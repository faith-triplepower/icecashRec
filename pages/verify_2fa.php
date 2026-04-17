<?php
// ============================================================
// pages/verify_2fa.php — Post-login TOTP verification
// User has already passed username/password but needs 2FA code.
// ============================================================
require_once '../core/auth.php';
require_once '../core/totp.php';

if (!isset($_SESSION['_2fa_pending_uid'])) {
    header('Location: ' . BASE_URL . '/pages/login.php');
    exit;
}

$uid = (int)$_SESSION['_2fa_pending_uid'];
$db  = get_db();
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $code = trim(isset($_POST['code']) ? $_POST['code'] : '');
    $secret = totp_get_secret($db, $uid);

    if ($secret && totp_verify($secret, $code)) {
        // Load the full user into session (was deferred pending 2FA)
        $stmt = $db->prepare("SELECT id, username, full_name, email, role, initials FROM users WHERE id=?");
        $stmt->bind_param('i', $uid);
        $stmt->execute();
        $u = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        $_SESSION['user'] = array(
            'id'       => $u['id'],
            'username' => $u['username'],
            'name'     => $u['full_name'],
            'email'    => $u['email'],
            'role'     => $u['role'],
            'initials' => $u['initials'],
        );
        $_SESSION['login_time'] = date('Y-m-d H:i:s');
        unset($_SESSION['_2fa_pending_uid']);

        $ip = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : null;
        $upd = $db->prepare("UPDATE users SET last_login = NOW(), last_login_ip = ? WHERE id = ?");
        $upd->bind_param('si', $ip, $uid);
        $upd->execute();
        $upd->close();

        audit_log($uid, 'LOGIN', 'Successful login (2FA verified)');
        header('Location: ' . BASE_URL . '/modules/dashboard.php');
        exit;
    } else {
        $error = 'Invalid code. Please try again.';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8"><title>Verify 2FA — Zimnat IcecashRec</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link href="https://fonts.googleapis.com/css?family=Open+Sans:400,600,700" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="../assets/css/login.css">
</head>
<body>
<div class="wrapper">
    <form class="login" method="POST" style="max-width:380px">
        <?= csrf_field() ?>
        <div class="brand">
            <img src="../assets/img/zimnat logo.png" alt="Zimnat Logo" style="height:60px;width:auto;margin-bottom:8px">
            <div class="brand-logo">Icecash<span>Rec</span></div>
            <div class="brand-sub">Two-Factor Verification</div>
        </div>
        <p style="font-size:13px;color:#555;text-align:center;margin-bottom:16px">
            <i class="fa-solid fa-shield-halved" style="color:#00a950"></i>
            Enter the 6-digit code from your authenticator app.
        </p>
        <?php if ($error): ?><div class="error-msg"><?= htmlspecialchars($error) ?></div><?php endif; ?>
        <input type="text" name="code" placeholder="000000" maxlength="6" pattern="[0-9]{6}" required autofocus
               style="text-align:center;font-size:28px;letter-spacing:10px;font-family:monospace">
        <button type="submit"><span class="state">Verify</span></button>
        <a href="<?= BASE_URL ?>/pages/login.php" style="display:block;text-align:center;margin-top:12px;color:#888;font-size:12px">Cancel — sign in with a different account</a>
    </form>
</div>
</body>
</html>
