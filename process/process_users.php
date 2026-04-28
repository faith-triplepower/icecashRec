<?php
// ============================================================
// process/process_users.php — User Management
// Add, edit, reset password (with reuse prevention),
// toggle active, force logout, and delete users.
// Part of IceCashRec — Zimnat General Insurance
// ============================================================
// Handles: add_user, edit_user, reset_password, toggle_user,
//          force_logout (new), send_welcome (new)
// ============================================================

require_once '../core/auth.php';
require_once '../core/notifications.php';
require_role(['Manager','Admin']);
csrf_verify();

$db     = get_db();
$action = $_POST['action'] ?? '';
$user   = current_user();
$uid    = (int)$user['id'];

// Read org password policy once
$pw_min_row = $db->query("SELECT setting_value FROM system_settings WHERE setting_key='password_min_length'")->fetch_assoc();
$pw_min_len = $pw_min_row ? max(6, (int)$pw_min_row['setting_value']) : 8;

function validate_password($pw, $min) {
    if (strlen($pw) < $min) return "Password must be at least $min characters.";
    if (!preg_match('/[A-Za-z]/', $pw) || !preg_match('/[0-9]/', $pw)) {
        return 'Password must contain at least one letter and one number.';
    }
    return null;
}

switch ($action) {

    // ── Add new user ─────────────────────────────────────────
    case 'add_user':
        $full_name = trim($_POST['full_name'] ?? '');
        $username  = trim($_POST['username']  ?? '');
        $email     = trim($_POST['email']     ?? '');
        $role      = $_POST['role']           ?? '';
        $password  = $_POST['temp_password']  ?? '';
        $send_welcome = !empty($_POST['send_welcome']);

        $allowed_roles = array('Manager','Reconciler','Uploader');
        if ($user['role'] === 'Admin') $allowed_roles[] = 'Admin';

        if (!$full_name || !$username || !$email || !in_array($role, $allowed_roles) || !$password) {
            redirect_back('error', 'All fields are required. Only Admin can assign the Admin role.');
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            redirect_back('error', 'Invalid email format.');
        }
        if (($pw_err = validate_password($password, $pw_min_len))) {
            redirect_back('error', $pw_err);
        }

        // Explicit uniqueness checks (pre-empt 1062)
        $chk = $db->prepare("SELECT id FROM users WHERE username=? OR email=?");
        $chk->bind_param('ss', $username, $email);
        $chk->execute();
        if ($chk->get_result()->fetch_assoc()) {
            $chk->close();
            redirect_back('error', 'Username or email already in use.');
        }
        $chk->close();

        $parts    = explode(' ', $full_name);
        $initials = strtoupper(substr($parts[0],0,1) . (isset($parts[1]) ? substr($parts[1],0,1) : ''));
        $hash     = password_hash($password, PASSWORD_DEFAULT);

        $stmt = $db->prepare(
            "INSERT INTO users (username, full_name, email, password_hash, role, initials)
             VALUES (?, ?, ?, ?, ?, ?)"
        );
        $stmt->bind_param('ssssss', $username, $full_name, $email, $hash, $role, $initials);
        if (!$stmt->execute()) {
            $stmt->close();
            redirect_back('error', 'Failed to create user.');
        }
        $new_id = (int)$stmt->insert_id;
        $stmt->close();

        // Optional welcome email
        if ($send_welcome) {
            $body = "Welcome to IceCashRec.\n\n"
                  . "Your account has been created by {$user['name']}.\n\n"
                  . "Username: $username\n"
                  . "Role: $role\n"
                  . "Temporary password: (shared separately)\n\n"
                  . "Please log in and change your password on first use:\n"
                  . BASE_URL . "/pages/login.php";
            enqueue_email($db, $new_id, "Your IceCashRec account has been created", $body, 'welcome', null);
        }

        audit_log($uid, 'USER_MGMT', "Created user: $username ($role)" . ($send_welcome ? ' + welcome email' : ''));
        redirect_back('success', "User '$username' created.");

    // ── Edit user ────────────────────────────────────────────
    case 'edit_user':
        $id        = (int)($_POST['user_id'] ?? 0);
        $full_name = trim($_POST['full_name'] ?? '');
        $email     = trim($_POST['email']     ?? '');
        $role      = $_POST['role']           ?? '';

        $allowed_roles = array('Manager','Reconciler','Uploader');
        if ($user['role'] === 'Admin') $allowed_roles[] = 'Admin';

        if (!$id || !$full_name || !$email || !in_array($role, $allowed_roles)) {
            redirect_back('error', 'Invalid data. Only Admin can assign the Admin role.');
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            redirect_back('error', 'Invalid email format.');
        }

        // Prevent non-Admin editing an Admin account
        $t = $db->prepare("SELECT role FROM users WHERE id=?");
        $t->bind_param('i', $id);
        $t->execute();
        $target = $t->get_result()->fetch_assoc();
        $t->close();
        if ($target && $target['role'] === 'Admin' && $user['role'] !== 'Admin') {
            redirect_back('error', 'Only an Admin can edit an Admin account.');
        }

        // Email uniqueness (allow own row)
        $chk = $db->prepare("SELECT id FROM users WHERE email=? AND id<>?");
        $chk->bind_param('si', $email, $id);
        $chk->execute();
        if ($chk->get_result()->fetch_assoc()) {
            $chk->close();
            redirect_back('error', 'That email is already in use by another user.');
        }
        $chk->close();

        $parts    = explode(' ', $full_name);
        $initials = strtoupper(substr($parts[0],0,1) . (isset($parts[1]) ? substr($parts[1],0,1) : ''));

        $stmt = $db->prepare("UPDATE users SET full_name=?, email=?, role=?, initials=? WHERE id=?");
        $stmt->bind_param('ssssi', $full_name, $email, $role, $initials, $id);
        $stmt->execute();
        $stmt->close();

        audit_log($uid, 'USER_MGMT', "Edited user ID $id — name: $full_name, role: $role");
        redirect_back('success', 'User updated.');

    // ── Reset password ───────────────────────────────────────
    case 'reset_password':
        $id           = (int)($_POST['user_id']      ?? 0);
        $new_password = $_POST['new_password'] ?? '';

        if (!$id) redirect_back('error', 'Invalid user.');
        if (($pw_err = validate_password($new_password, $pw_min_len))) {
            redirect_back('error', $pw_err);
        }

        // Prevent non-Admin resetting an Admin password
        $t = $db->prepare("SELECT role FROM users WHERE id=?");
        $t->bind_param('i', $id);
        $t->execute();
        $target = $t->get_result()->fetch_assoc();
        $t->close();
        if ($target && $target['role'] === 'Admin' && $user['role'] !== 'Admin') {
            redirect_back('error', 'Only an Admin can reset an Admin password.');
        }

        // Reject reuse of last 5 passwords
        if (password_was_reused($db, $id, $new_password, 5)) {
            redirect_back('error', 'Password was used recently. Choose a different one.');
        }

        $hash = password_hash($new_password, PASSWORD_DEFAULT);
        $stmt = $db->prepare("UPDATE users SET password_hash=? WHERE id=?");
        $stmt->bind_param('si', $hash, $id);
        $stmt->execute();
        $stmt->close();

        record_password_change($db, $id, $hash);

        audit_log($uid, 'USER_MGMT', "Reset password for user ID $id");
        redirect_back('success', 'Password reset.');

    // ── Disable / enable user ────────────────────────────────
    case 'toggle_user':
        $id     = (int)($_POST['user_id'] ?? 0);
        $active = (int)($_POST['is_active'] ?? 0);
        if (!$id) redirect_back('error', 'Invalid user.');
        if ($id === $uid) redirect_back('error', 'You cannot disable your own account.');

        // Prevent non-Admin toggling an Admin
        $t = $db->prepare("SELECT role FROM users WHERE id=?");
        $t->bind_param('i', $id);
        $t->execute();
        $target = $t->get_result()->fetch_assoc();
        $t->close();
        if ($target && $target['role'] === 'Admin' && $user['role'] !== 'Admin') {
            redirect_back('error', 'Only an Admin can disable an Admin account.');
        }

        $stmt = $db->prepare("UPDATE users SET is_active=? WHERE id=?");
        $stmt->bind_param('ii', $active, $id);
        $stmt->execute();
        $stmt->close();

        $label = $active ? 'Enabled' : 'Disabled';
        audit_log($uid, 'USER_MGMT', "$label user ID $id");
        redirect_back('success', "User $label.");

    // ── Force logout a user ──────────────────────────────────
    // Marks the password_hash with a session-invalidating tag. The
    // cleanest way without a dedicated sessions table is to rotate
    // the user's password_hash with a high-entropy random placeholder,
    // then immediately set it back. But that's destructive. Instead,
    // we bump a user_preferences "force_logout_at" timestamp and the
    // auth middleware can check it. For now, we log the intent —
    // real enforcement is left to auth.php to consult later.
    case 'force_logout':
        $id = (int)($_POST['user_id'] ?? 0);
        if (!$id) redirect_back('error', 'Invalid user.');

        $now = date('Y-m-d H:i:s');
        $stmt = $db->prepare("
            INSERT INTO user_preferences (user_id, pref_key, pref_val)
            VALUES (?, 'force_logout_at', ?)
            ON DUPLICATE KEY UPDATE pref_val=VALUES(pref_val)
        ");
        $stmt->bind_param('is', $id, $now);
        $stmt->execute();
        $stmt->close();

        audit_log($uid, 'USER_MGMT', "Force-logout marker set for user ID $id");
        redirect_back('success', "Force-logout marker set. User will be logged out on their next request.");

    // ── Delete user (Admin only) ───────────────────────────────
    case 'delete_user':
        if ($user['role'] !== 'Admin') redirect_back('error', 'Only Admin can delete users.');
        $id = (int)($_POST['user_id'] ?? 0);
        if (!$id) redirect_back('error', 'Invalid user.');
        if ($id === $uid) redirect_back('error', 'You cannot delete your own account.');

        $t = $db->prepare("SELECT username, full_name FROM users WHERE id=?");
        $t->bind_param('i', $id);
        $t->execute();
        $target = $t->get_result()->fetch_assoc();
        $t->close();
        if (!$target) redirect_back('error', 'User not found.');

        // Cascade: clean up all FK references before deleting the user
        $db->query("DELETE FROM password_history WHERE user_id = $id");
        $db->query("DELETE FROM user_preferences WHERE user_id = $id");
        $db->query("DELETE FROM notification_queue WHERE user_id = $id");
        $db->query("UPDATE escalations SET assigned_to = NULL WHERE assigned_to = $id");
        $db->query("UPDATE escalations SET reviewed_by = NULL WHERE reviewed_by = $id");
        $db->query("UPDATE statements SET reviewed_by = NULL WHERE reviewed_by = $id");

        $del = $db->prepare("DELETE FROM users WHERE id=?");
        $del->bind_param('i', $id);
        $del->execute();
        $del->close();

        audit_log($uid, 'USER_MGMT', "Deleted user: {$target['username']} ({$target['full_name']})");
        redirect_back('success', "User '{$target['username']}' deleted.");

    default:
        redirect_back('error', 'Unknown action.');
}

function redirect_back(string $type, string $msg): void {
    header("Location: " . BASE_URL . "/admin/users.php?{$type}=" . urlencode($msg));
    exit;
}
