<?php
// ============================================================
// process/process_settings.php
// Actions for the Settings page:
//   save_defaults     — reconciliation defaults (Manager/Admin)
//   save_org          — org info + session timeout + escalation threshold
//   save_prefs        — per-user notification + theme preferences
//   update_profile    — edit own name/email
//   change_password   — change own password (with modern policy)
//   signout_all       — force session regeneration (kicks other devices)
// ============================================================

require_once '../core/auth.php';
require_login();
csrf_verify();

$db     = get_db();
$user   = current_user();
$uid    = (int)$user['id'];
$action = $_POST['action'] ?? '';

function redirect_back($type, $msg) {
    header("Location: " . BASE_URL . "/admin/settings.php?" . $type . "=" . urlencode($msg));
    exit;
}

// Upsert a system setting
function set_system_setting($db, $key, $val, $uid) {
    $val = (string)$val;
    $stmt = $db->prepare("
        INSERT INTO system_settings (setting_key, setting_value, updated_by)
        VALUES (?, ?, ?)
        ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value), updated_by=VALUES(updated_by)
    ");
    $stmt->bind_param('ssi', $key, $val, $uid);
    $stmt->execute();
    $stmt->close();
}

// Upsert a per-user preference
function set_user_pref($db, $uid, $key, $val) {
    $val = (string)$val;
    $stmt = $db->prepare("
        INSERT INTO user_preferences (user_id, pref_key, pref_val)
        VALUES (?, ?, ?)
        ON DUPLICATE KEY UPDATE pref_val=VALUES(pref_val)
    ");
    $stmt->bind_param('iss', $uid, $key, $val);
    $stmt->execute();
    $stmt->close();
}

switch ($action) {

    // ── Reconciliation defaults (Manager/Admin) ──────────────
    case 'save_defaults':
        require_role(['Manager','Admin']);

        $period = $_POST['default_period_type'] ?? 'Monthly';
        if (!in_array($period, array('Monthly','Daily'))) $period = 'Monthly';
        $date_tol   = max(0, min(5,  (int)($_POST['date_tolerance']   ?? 1)));
        $amt_tol    = max(0, (int)($_POST['amount_tolerance'] ?? 0));
        $auto_fx    = ($_POST['auto_flag_fx'] ?? 'yes') === 'yes' ? 'yes' : 'no';

        set_system_setting($db, 'default_period_type',   $period,   $uid);
        set_system_setting($db, 'date_tolerance_days',   $date_tol, $uid);
        set_system_setting($db, 'amount_tolerance_zwg',  $amt_tol,  $uid);
        set_system_setting($db, 'auto_flag_fx_mismatch', $auto_fx,  $uid);

        audit_log($uid, 'DATA_EDIT', 'Updated reconciliation default settings');
        redirect_back('success', 'Reconciliation defaults saved.');

    // ── Organization settings (Admin only) ───────────────────
    case 'save_org':
        require_role(['Admin']);

        $org_name     = trim($_POST['org_name'] ?? '');
        $version      = trim($_POST['system_version'] ?? 'v1.0');
        $timeout      = max(1, min(24, (int)($_POST['session_timeout'] ?? 8)));
        $esc_zwg      = max(0, (int)($_POST['auto_escalate_zwg'] ?? 10000));
        $esc_usd      = max(0, (int)($_POST['auto_escalate_usd'] ?? 500));
        $pw_min       = max(6, min(32, (int)($_POST['password_min_length'] ?? 8)));
        $retention    = max(30, (int)($_POST['audit_retention_days'] ?? 3650));

        if (!$org_name) redirect_back('error', 'Organization name is required.');

        set_system_setting($db, 'org_name',                    $org_name,  $uid);
        set_system_setting($db, 'system_version',              $version,   $uid);
        set_system_setting($db, 'session_timeout_hours',       $timeout,   $uid);
        set_system_setting($db, 'auto_escalate_threshold_zwg', $esc_zwg,   $uid);
        set_system_setting($db, 'auto_escalate_threshold_usd', $esc_usd,   $uid);
        set_system_setting($db, 'password_min_length',         $pw_min,    $uid);
        set_system_setting($db, 'audit_retention_days',        $retention, $uid);

        $pdf_path = trim($_POST['pdftotext_path'] ?? '');
        set_system_setting($db, 'pdftotext_path', $pdf_path, $uid);

        audit_log($uid, 'DATA_EDIT', 'Updated organization settings');
        redirect_back('success', 'Organization settings saved.');

    // ── Per-user notification + theme preferences ────────────
    case 'save_prefs':
        // Everyone can save their own preferences.
        $allowed_notif = array(
            'notif_variance',
            'notif_unmatched',
            'notif_daily_summary',
            'notif_failed_upload',
            'notif_weekly_audit',
            'notif_escalation_assigned',
        );
        foreach ($allowed_notif as $key) {
            $val = isset($_POST[$key]) ? '1' : '0';
            set_user_pref($db, $uid, $key, $val);
        }

        $notif_email = trim($_POST['notif_email'] ?? $user['email']);
        if ($notif_email) set_user_pref($db, $uid, 'notif_email', $notif_email);

        $theme = $_POST['theme'] ?? 'default';
        if (!in_array($theme, array('default','light','dark'))) $theme = 'default';
        set_user_pref($db, $uid, 'theme', $theme);

        $timezone = $_POST['timezone'] ?? 'Africa/Harare';
        set_user_pref($db, $uid, 'timezone', $timezone);

        audit_log($uid, 'DATA_EDIT', 'Updated own notification/theme preferences');
        redirect_back('success', 'Preferences saved.');

    // ── Profile ──────────────────────────────────────────────
    case 'update_profile':
        $full_name = trim($_POST['full_name'] ?? '');
        $email     = trim($_POST['email']     ?? '');

        if (!$full_name || !$email) redirect_back('error', 'Name and email are required.');
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) redirect_back('error', 'Invalid email address.');

        // Check uniqueness of email
        $chk = $db->prepare("SELECT id FROM users WHERE email=? AND id<>?");
        $chk->bind_param('si', $email, $uid);
        $chk->execute();
        if ($chk->get_result()->fetch_assoc()) {
            $chk->close();
            redirect_back('error', 'That email is already in use by another user.');
        }
        $chk->close();

        $parts    = explode(' ', $full_name);
        $initials = strtoupper(substr($parts[0],0,1) . (isset($parts[1]) ? substr($parts[1],0,1) : ''));

        $stmt = $db->prepare("UPDATE users SET full_name=?, email=?, initials=? WHERE id=?");
        $stmt->bind_param('sssi', $full_name, $email, $initials, $uid);
        $stmt->execute();
        $stmt->close();

        $_SESSION['user']['name']     = $full_name;
        $_SESSION['user']['email']    = $email;
        $_SESSION['user']['initials'] = $initials;

        audit_log($uid, 'DATA_EDIT', 'Updated own profile');
        redirect_back('success', 'Profile updated.');

    // ── Change password ──────────────────────────────────────
    case 'change_password':
        $current = $_POST['current_password'] ?? '';
        $new_pw  = $_POST['new_password']     ?? '';
        $confirm = $_POST['confirm_password'] ?? '';

        if (!$current || !$new_pw || !$confirm) redirect_back('error', 'All password fields are required.');
        if ($new_pw !== $confirm) redirect_back('error', 'New passwords do not match.');

        // Read org-configured min length; fall back to 8
        $min_row = $db->query("SELECT setting_value FROM system_settings WHERE setting_key='password_min_length'")->fetch_assoc();
        $min_len = $min_row ? max(6, (int)$min_row['setting_value']) : 8;

        if (strlen($new_pw) < $min_len) {
            redirect_back('error', "Password must be at least $min_len characters.");
        }
        if (!preg_match('/[A-Za-z]/', $new_pw) || !preg_match('/[0-9]/', $new_pw)) {
            redirect_back('error', 'Password must contain at least one letter and one number.');
        }
        if ($new_pw === $current) {
            redirect_back('error', 'New password must be different from the current one.');
        }

        $row = $db->query("SELECT password_hash FROM users WHERE id=$uid")->fetch_assoc();
        if (!password_verify($current, $row['password_hash'])) {
            redirect_back('error', 'Current password is incorrect.');
        }

        $hash = password_hash($new_pw, PASSWORD_DEFAULT);
        $stmt = $db->prepare("UPDATE users SET password_hash=? WHERE id=?");
        $stmt->bind_param('si', $hash, $uid);
        $stmt->execute();
        $stmt->close();

        audit_log($uid, 'DATA_EDIT', 'Changed own password');
        redirect_back('success', 'Password changed.');

    // ── Sign out everywhere (kick other sessions) ────────────
    case 'signout_all':
        // Regenerate the session ID. Any stale session cookies on other
        // devices will not match the new ID and will be forced to re-login.
        session_regenerate_id(true);
        audit_log($uid, 'LOGOUT', 'Signed out of all other sessions');
        redirect_back('success', 'Signed out of all other sessions.');

    default:
        redirect_back('error', 'Unknown action.');
}
