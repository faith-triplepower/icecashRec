<?php
// ============================================================
// pages/change_password.php — forced password change (expiry)
// ============================================================
require_once '../core/auth.php';

if (!is_logged_in()) {
    header('Location: ' . BASE_URL . '/pages/login.php');
    exit;
}
$user = current_user();
$uid  = (int)$user['id'];
$db   = get_db();

$expired = isset($_GET['expired']);
$error   = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $current  = trim($_POST['current_password'] ?? '');
    $new_pass = trim($_POST['new_password'] ?? '');
    $confirm  = trim($_POST['confirm_password'] ?? '');

    // Verify current password
    $stmt = $db->prepare("SELECT password_hash FROM users WHERE id=?");
    $stmt->bind_param('i', $uid);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$row || !password_verify($current, $row['password_hash'])) {
        $error = 'Current password is incorrect.';
    } elseif (strlen($new_pass) < 8) {
        $error = 'New password must be at least 8 characters.';
    } elseif ($new_pass !== $confirm) {
        $error = 'New passwords do not match.';
    } elseif (password_was_reused($db, $uid, $new_pass, 5)) {
        $error = 'This password was used recently. Choose a different one.';
    } else {
        $hash = password_hash($new_pass, PASSWORD_DEFAULT);
        $upd = $db->prepare("UPDATE users SET password_hash=? WHERE id=?");
        $upd->bind_param('si', $hash, $uid);
        $upd->execute();
        $upd->close();
        record_password_change($db, $uid, $hash);
        audit_log($uid, 'USER_MGMT', 'Self-service password change');
        header('Location: ' . BASE_URL . '/modules/dashboard.php?success=' . urlencode('Password changed successfully.'));
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Change Password — Zimnat IcecashRec</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link href="https://fonts.googleapis.com/css?family=Open+Sans:400,600,700" rel="stylesheet">
    <link rel="stylesheet" href="../assets/css/login.css">
</head>
<body>
<div class="wrapper">
    <form class="login" method="POST" style="max-width:400px">
        <?= csrf_field() ?>
        <div class="brand">
            <img src="../assets/img/zimnat logo.png" alt="Zimnat Logo" style="height:60px;width:auto;margin-bottom:8px">
            <div class="brand-logo">Icecash<span>Rec</span></div>
            <div class="brand-sub">Change Your Password</div>
        </div>
        <?php if ($expired): ?>
        <div class="error-msg" style="background:#fff8e1;color:#5a4500;border-color:#d49a00">
            Your password has expired (90-day policy). Please set a new one to continue.
        </div>
        <?php endif; ?>
        <?php if ($error): ?>
        <div class="error-msg"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>
        <input type="password" name="current_password" placeholder="Current Password" required autofocus>
        <input type="password" name="new_password" placeholder="New Password (min 8 chars)" required minlength="8">
        <input type="password" name="confirm_password" placeholder="Confirm New Password" required minlength="8">
        <button type="submit"><span class="state">Change Password</span></button>
        <?php if (!$expired): ?>
        <a href="<?= BASE_URL ?>/modules/dashboard.php" style="display:block;text-align:center;margin-top:12px;color:#888;font-size:12px">Cancel — go back to dashboard</a>
        <?php endif; ?>
    </form>
</div>
</body>
</html>
