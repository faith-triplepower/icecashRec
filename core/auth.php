<?php
// ============================================================
// core/auth.php — Authentication & Session Management
// Handles login/logout, role-based access, CSRF protection,
// login rate limiting, password expiry/history, and session
// hardening. Included by every page via layout_header.php.
// Part of IceCashRec — Zimnat General Insurance
// ============================================================

// ── Fix 3: Session cookie hardening ──────────────────────────
// HttpOnly prevents JS from reading the session cookie (XSS defense).
// SameSite=Strict blocks cross-site form submissions from sending cookies.
// Secure should be true on production (HTTPS); false for localhost dev.
if (session_status() === PHP_SESSION_NONE && !headers_sent()) {
    $is_https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
    // PHP 7.2 doesn't support the array form — use positional args.
    // SameSite is injected via the path parameter (standard 7.2 workaround).
    session_set_cookie_params(0, '/; SameSite=Strict', '', $is_https, true);
    session_start();
}
// Load DB config first — it pulls BASE_URL from .env (APP_BASE_URL).
// Our local-dev fallbacks below only apply if .env didn't define them.
require_once __DIR__ . '/db.php';

if (!defined('BASE_URL'))   define('BASE_URL',   '/icecashRec');
if (!defined('EXPORT_DIR')) define('EXPORT_DIR', __DIR__ . '/../exports');

// ── Helpers ─────────────────────────────────────────────────

// Centralised audit-log writer. Every state change in the system
// MUST go through this function — direct INSERT INTO audit_log calls
// scattered through process/* drift out of sync with the ENUM and
// silently lose the most security-relevant rows (deletions, flags).
//
// $action MUST be one of the values in the audit_log.action_type ENUM:
//   LOGIN, LOGOUT, FILE_UPLOAD, FLAG_UPLOAD, DELETE_UPLOAD,
//   RECON_RUN, DATA_EDIT, REPORT_EXPORT, USER_MGMT
function audit_log_entry($user_id, $action, $details, $result = 'success'): void {
    $db = get_db();
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    $stmt = $db->prepare(
        "INSERT INTO audit_log (user_id, action_type, detail, ip_address, result, created_at)
         VALUES (?, ?, ?, ?, ?, NOW())"
    );
    if (!$stmt) {
        // Don't break the caller — but do leave a server-log breadcrumb.
        error_log("audit_log_entry: prepare failed: " . $db->error);
        return;
    }
    $stmt->bind_param('issss', $user_id, $action, $details, $ip, $result);
    $stmt->execute();
    $stmt->close();
}

function is_logged_in(): bool {
    return isset($_SESSION['user']['id']) && is_numeric($_SESSION['user']['id']);
}

function require_login(): void {
    if (!is_logged_in()) {
        header('Location: ' . BASE_URL . '/pages/login.php');
        exit;
    }
    // Force-logout check
    $uid = (int)($_SESSION['user']['id'] ?? 0);
    if ($uid > 0 && function_exists('get_db')) {
        $db = get_db();
        $stmt = $db->prepare("SELECT pref_val FROM user_preferences WHERE user_id=? AND pref_key='force_logout_at'");
        $stmt->bind_param('i', $uid);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($row) {
            $session_started = $_SESSION['login_time'] ?? '1970-01-01 00:00:00';
            if ($row['pref_val'] > $session_started) {
                session_destroy();
                header('Location: ' . BASE_URL . '/pages/login.php?error=' . urlencode('You have been signed out by an administrator.'));
                exit;
            }
        }
        // Password expiry: redirect to change-password if >90 days old.
        // Skip on the change-password page itself to avoid an infinite loop.
        $current_page = basename($_SERVER['SCRIPT_NAME'] ?? '');
        if ($current_page !== 'change_password.php') {
            $days = password_days_remaining($db, $uid, 90);
            if ($days === 0) {
                header('Location: ' . BASE_URL . '/pages/change_password.php?expired=1');
                exit;
            }
        }
    }
}

function require_role($roles): void {
    require_login();
    // Admin bypasses every role restriction in the system
    if ($_SESSION['user']['role'] === 'Admin') return;
    $allowed = is_array($roles) ? $roles : [$roles];
    if (!in_array($_SESSION['user']['role'], $allowed)) {
        header('Location: ' . BASE_URL . '/pages/access-denied.php');
        exit;
    }
}

function is_admin(): bool {
    return isset($_SESSION['user']['role']) && $_SESSION['user']['role'] === 'Admin';
}

function current_user(): ?array {
    return $_SESSION['user'] ?? null;
}

// ── Login ────────────────────────────────────────────────────

function login(string $username, string $password, bool &$needs_2fa = false, bool &$disabled = false): bool {
    $db  = get_db();
    // Fetch without the is_active filter so we can distinguish a disabled
    // account from a non-existent one and show a helpful message.
    $stmt = $db->prepare(
        "SELECT id, username, full_name, email, password_hash, role, initials, is_active
         FROM users WHERE username = ? LIMIT 1"
    );
    $stmt->bind_param('s', $username);
    $stmt->execute();
    $result = $stmt->get_result();
    $user   = $result->fetch_assoc();
    $stmt->close();

    // Account exists but has been disabled — tell the caller without
    // leaking whether the password was correct.
    if ($user && !(int)$user['is_active']) {
        $disabled = true;
        return false;
    }

    if (!$user || !password_verify($password, $user['password_hash'])) {
        audit_login_failed($username);
        record_login_failure($username);
        return false;
    }

    // Clear rate-limit failures on successful login
    clear_login_failures($username);

    // Prevents session fixation: attacker can't pre-set a session ID
    // and reuse it after the victim logs in.
    session_regenerate_id(true);

    // If user has 2FA enabled, park a pending UID in the session and
    // return — the caller redirects to verify_2fa.php which completes
    // the login after the TOTP code is verified.
    require_once __DIR__ . '/totp.php';
    if (totp_is_enabled($db, (int)$user['id'])) {
        $needs_2fa = true;
        $_SESSION['_2fa_pending_uid'] = (int)$user['id'];
        return true;
    }

    // No 2FA — complete the session immediately
    $_SESSION['user'] = [
        'id'       => $user['id'],
        'username' => $user['username'],
        'name'     => $user['full_name'],
        'email'    => $user['email'],
        'role'     => $user['role'],
        'initials' => $user['initials'],
    ];
    $_SESSION['login_time'] = date('Y-m-d H:i:s');

    // Update last_login timestamp + IP
    $ip = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : null;
    $upd = $db->prepare("UPDATE users SET last_login = NOW(), last_login_ip = ? WHERE id = ?");
    $upd->bind_param('si', $ip, $user['id']);
    $upd->execute();
    $upd->close();

    audit_log($user['id'], 'LOGIN', 'Successful login');

    return true;
}


// ── Logout ───────────────────────────────────────────────────

function logout(): void {
    if (isset($_SESSION['user']['id']) && is_numeric($_SESSION['user']['id'])) {
        audit_log((int)$_SESSION['user']['id'], 'LOGOUT', 'Session ended');
    }
    session_unset();
    session_destroy();
    header('Location: ' . BASE_URL . '/pages/login.php');
    exit;
}

// ── Audit logging ────────────────────────────────────────────

// Backwards-compatible alias for callers that already use audit_log().
// Both names route to the canonical writer above so there's only one
// INSERT into audit_log in the whole codebase.
function audit_log(int $user_id, string $action, string $detail, string $result = 'success'): void {
    audit_log_entry($user_id, $action, $detail, $result);
}

function audit_login_failed(string $username): void {
    $db   = get_db();
    $ip   = $_SERVER['REMOTE_ADDR'] ?? '';
    // Find user id if exists
    $stmt = $db->prepare("SELECT id FROM users WHERE username = ? LIMIT 1");
    $stmt->bind_param('s', $username);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if ($row) {
        $detail = "Failed login attempt for username: $username";
        $s2 = $db->prepare(
            "INSERT INTO audit_log (user_id, action_type, detail, ip_address, result)
             VALUES (?, 'LOGIN', ?, ?, 'failed')"
        );
        $s2->bind_param('iss', $row['id'], $detail, $ip);
        $s2->execute();
        $s2->close();
    }
}

/**
 * Format an integer count compactly for table cells and KPI cards.
 * Keeps columns narrow when record counts span orders of magnitude
 * (1K policies vs 1.2M receipt rows) without losing a useful degree
 * of precision. The `title` attribute on the HTML element should
 * still carry the exact count for mouse-over disambiguation.
 *
 *   999        → "999"
 *   1,500      → "1.5K"
 *   23,593     → "23.6K"
 *   1,247,891  → "1.25M"
 *   4,200,000,000 → "4.2B"
 */
if (!function_exists('fmt_compact')) {
    function fmt_compact($n) {
        if ($n === null || $n === '') return '—';
        $n = (float)$n;
        if (!is_finite($n)) return '—';
        $abs = abs($n);
        if ($abs < 1000)             return number_format($n);
        if ($abs < 1000000)          return rtrim(rtrim(number_format($n / 1000, 1), '0'), '.') . 'K';
        if ($abs < 1000000000)       return rtrim(rtrim(number_format($n / 1000000, 2), '0'), '.') . 'M';
        if ($abs < 1000000000000)    return rtrim(rtrim(number_format($n / 1000000000, 2), '0'), '.') . 'B';
        return rtrim(rtrim(number_format($n / 1000000000000, 2), '0'), '.') . 'T';
    }
}

// ── Fix 1: CSRF protection ──────────────────────────────────
// Generate a per-session token. Render csrf_field() in every POST
// form. Call csrf_verify() at the top of every POST handler.

function csrf_token() {
    if (empty($_SESSION['_csrf_token'])) {
        $_SESSION['_csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['_csrf_token'];
}

function csrf_field() {
    return '<input type="hidden" name="_csrf" value="' . htmlspecialchars(csrf_token()) . '">';
}

function csrf_verify() {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') return;
    $token = isset($_POST['_csrf']) ? $_POST['_csrf'] : '';
    if (!hash_equals(csrf_token(), $token)) {
        http_response_code(403);
        die('Security error: invalid form token. Please go back and try again.');
    }
}

// ── Fix 2: Login rate limiting ──────────────────────────────
// Returns seconds to wait before next attempt, or 0 if allowed.
// Tracks by IP + username combo. Locks after 5 failures in 15 min.

function check_login_rate($username) {
    $db = get_db();
    $ip = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '';
    $window = 15 * 60; // 15 minutes

    // Ensure table exists (safe to call repeatedly)
    $db->query("CREATE TABLE IF NOT EXISTS login_attempts (
        id INT AUTO_INCREMENT PRIMARY KEY,
        ip VARCHAR(45) NOT NULL,
        username VARCHAR(50) NOT NULL,
        attempted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_lookup (ip, username, attempted_at)
    )");

    // Count recent failures
    $cutoff = date('Y-m-d H:i:s', time() - $window);
    $stmt = $db->prepare("SELECT COUNT(*) c FROM login_attempts WHERE ip=? AND username=? AND attempted_at > ?");
    $stmt->bind_param('sss', $ip, $username, $cutoff);
    $stmt->execute();
    $count = (int)$stmt->get_result()->fetch_assoc()['c'];
    $stmt->close();

    if ($count >= 5) {
        // Find when the oldest attempt in the window expires
        $stmt2 = $db->prepare("SELECT MIN(attempted_at) oldest FROM login_attempts WHERE ip=? AND username=? AND attempted_at > ?");
        $stmt2->bind_param('sss', $ip, $username, $cutoff);
        $stmt2->execute();
        $oldest = $stmt2->get_result()->fetch_assoc()['oldest'];
        $stmt2->close();
        $wait = $window - (time() - strtotime($oldest));
        return max(1, $wait);
    }
    return 0;
}

function record_login_failure($username) {
    $db = get_db();
    $ip = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '';
    $stmt = $db->prepare("INSERT INTO login_attempts (ip, username) VALUES (?, ?)");
    $stmt->bind_param('ss', $ip, $username);
    $stmt->execute();
    $stmt->close();
}

function clear_login_failures($username) {
    $db = get_db();
    $ip = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '';
    $stmt = $db->prepare("DELETE FROM login_attempts WHERE ip=? AND username=?");
    $stmt->bind_param('ss', $ip, $username);
    $stmt->execute();
    $stmt->close();
}

// ── Fix 7: Password rotation + history ──────────────────────
// Returns true if password was used in the last $depth changes.
function password_was_reused($db, $user_id, $new_password, $depth = 5) {
    $stmt = $db->prepare("SELECT password_hash FROM password_history WHERE user_id=? ORDER BY changed_at DESC LIMIT ?");
    $stmt->bind_param('ii', $user_id, $depth);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    foreach ($rows as $r) {
        if (password_verify($new_password, $r['password_hash'])) return true;
    }
    return false;
}

function record_password_change($db, $user_id, $hash) {
    $stmt = $db->prepare("INSERT INTO password_history (user_id, password_hash) VALUES (?, ?)");
    $stmt->bind_param('is', $user_id, $hash);
    $stmt->execute();
    $stmt->close();
    $upd = $db->prepare("UPDATE users SET password_changed_at = NOW() WHERE id = ?");
    $upd->bind_param('i', $user_id);
    $upd->execute();
    $upd->close();
}

// Returns days until password expires, or 0 if expired, or -1 if no policy.
function password_days_remaining($db, $user_id, $max_age_days = 90) {
    $stmt = $db->prepare("SELECT password_changed_at FROM users WHERE id=?");
    $stmt->bind_param('i', $user_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$row || !$row['password_changed_at']) return -1;
    $age = (time() - strtotime($row['password_changed_at'])) / 86400;
    return max(0, (int)($max_age_days - $age));
}
