<?php
// ============================================================
// pages/setup_2fa.php — Enable/disable TOTP 2FA
// ============================================================
require_once '../core/auth.php';
require_once '../core/totp.php';

if (!is_logged_in()) { header('Location: ' . BASE_URL . '/pages/login.php'); exit; }

$user = current_user();
$uid  = (int)$user['id'];
$db   = get_db();

$error   = '';
$success = '';
$show_setup = false;
$secret  = '';
$uri     = '';

$is_enabled = totp_is_enabled($db, $uid);

// Handle actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = $_POST['action'] ?? '';

    if ($action === 'begin_setup') {
        $secret = totp_generate_secret();
        $_SESSION['_2fa_setup_secret'] = $secret;
        $show_setup = true;
        $uri = totp_provisioning_uri($secret, $user['username']);

    } elseif ($action === 'confirm_setup') {
        $code   = trim($_POST['code'] ?? '');
        $secret = $_SESSION['_2fa_setup_secret'] ?? '';
        if (!$secret) { $error = 'Setup session expired. Please start again.'; }
        elseif (!totp_verify($secret, $code)) {
            $error = 'Invalid code. Please check your authenticator app and try again.';
            $show_setup = true;
            $uri = totp_provisioning_uri($secret, $user['username']);
        } else {
            totp_save_secret($db, $uid, $secret);
            unset($_SESSION['_2fa_setup_secret']);
            $is_enabled = true;
            $success = 'Two-factor authentication is now enabled.';
            audit_log($uid, 'USER_MGMT', '2FA enabled via TOTP');
        }

    } elseif ($action === 'disable') {
        $code = trim($_POST['code'] ?? '');
        $stored_secret = totp_get_secret($db, $uid);
        if ($stored_secret && totp_verify($stored_secret, $code)) {
            totp_disable($db, $uid);
            $is_enabled = false;
            $success = 'Two-factor authentication has been disabled.';
            audit_log($uid, 'USER_MGMT', '2FA disabled');
        } else {
            $error = 'Invalid code. Enter the current code from your authenticator to disable 2FA.';
        }
    }
}
$required = totp_is_required_for_role($user['role']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8"><title>Two-Factor Authentication — Zimnat IcecashRec</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link href="https://fonts.googleapis.com/css?family=Open+Sans:400,600,700" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="../assets/css/login.css">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
</head>
<body>
<div class="wrapper">
    <div class="login" style="max-width:440px;padding:30px">
        <div class="brand">
            <img src="../assets/img/zimnat logo.png" alt="Zimnat Logo" style="height:60px;width:auto;margin-bottom:8px">
            <div class="brand-logo">Icecash<span>Rec</span></div>
            <div class="brand-sub">Two-Factor Authentication</div>
        </div>

        <?php if ($error): ?><div class="error-msg"><?= htmlspecialchars($error) ?></div><?php endif; ?>
        <?php if ($success): ?><div class="error-msg" style="background:#eaf7ef;color:#00a950;border-color:#00a950"><?= htmlspecialchars($success) ?></div><?php endif; ?>

        <?php if ($show_setup): ?>
        <div style="text-align:center;margin:20px 0">
            <p style="font-size:13px;color:#555;margin-bottom:4px">
                1. Install <strong>Google Authenticator</strong> or <strong>Authy</strong> on your phone.<br>
                2. Open the app &rarr; tap <strong>+</strong> &rarr; <strong>Scan QR code</strong>.<br>
                3. Scan the code below <em>from inside the app</em> (not the camera app).
            </p>
            <div id="qrcode" style="display:inline-block;margin:12px auto;padding:8px;background:#fff;border:4px solid #eee;border-radius:8px"></div>
            <p style="font-size:11px;color:#888;margin-top:4px">
                Or <a href="<?= htmlspecialchars($uri) ?>" style="color:#007a3d">tap here to open in authenticator app</a><br>
                Manual key: <code style="background:#f5f5f5;padding:2px 6px;border-radius:3px;letter-spacing:2px"><?= htmlspecialchars($secret) ?></code>
            </p>
        </div>
        <script>
        new QRCode(document.getElementById('qrcode'), {
            text: <?= json_encode($uri) ?>,
            width: 200, height: 200,
            correctLevel: QRCode.CorrectLevel.M
        });
        </script>
        <form method="POST">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="confirm_setup">
            <input type="text" name="code" placeholder="Enter 6-digit code from app" maxlength="6" pattern="[0-9]{6}" required autofocus
                   style="text-align:center;font-size:24px;letter-spacing:8px;font-family:monospace">
            <button type="submit"><span class="state">Verify &amp; Enable</span></button>
        </form>

        <?php elseif ($is_enabled): ?>
        <div style="text-align:center;padding:20px 0">
            <i class="fa-solid fa-shield-halved" style="font-size:48px;color:#00a950"></i>
            <p style="font-size:14px;font-weight:600;color:#00a950;margin-top:12px">2FA is ENABLED</p>
            <p style="font-size:12px;color:#888">You'll be asked for a code from your authenticator app on every login.</p>
        </div>
        <form method="POST" style="margin-top:16px">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="disable">
            <input type="text" name="code" placeholder="Enter current code to disable" maxlength="6" pattern="[0-9]{6}" required
                   style="text-align:center;font-size:20px;letter-spacing:6px;font-family:monospace">
            <button type="submit" style="background:#c0392b"><span class="state">Disable 2FA</span></button>
        </form>

        <?php else: ?>
        <div style="text-align:center;padding:20px 0">
            <i class="fa-solid fa-lock-open" style="font-size:48px;color:#888"></i>
            <p style="font-size:14px;font-weight:600;color:#555;margin-top:12px">2FA is not enabled</p>
            <?php if ($required): ?>
            <p style="font-size:12px;color:#c0392b;font-weight:600">Your role (<?= $user['role'] ?>) requires 2FA. Please enable it now.</p>
            <?php else: ?>
            <p style="font-size:12px;color:#888">Optional for your role, but recommended for extra security.</p>
            <?php endif; ?>
        </div>
        <form method="POST">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="begin_setup">
            <button type="submit"><span class="state"><i class="fa-solid fa-shield-halved"></i> Set Up 2FA</span></button>
        </form>
        <?php endif; ?>

        <a href="<?= BASE_URL ?>/modules/dashboard.php" style="display:block;text-align:center;margin-top:16px;color:#888;font-size:12px">
            <?= ($required && !$is_enabled) ? 'Skip for now (you will be asked again)' : '← Back to dashboard' ?>
        </a>
    </div>
</div>
</body>
</html>
