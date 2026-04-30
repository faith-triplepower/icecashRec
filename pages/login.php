<?php
// ============================================================
// pages/login.php
// Login form with demo-account autofill cards.
// ============================================================
require_once '../core/auth.php';
require_once '../core/totp.php';

if (is_logged_in()) {
    header('Location: ' . BASE_URL . '/modules/dashboard.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $db = get_db();
    $username = trim($_POST['username'] ?? '');
    $password = trim($_POST['password'] ?? '');

    if (empty($username) || empty($password)) {
        header('Location: ' . BASE_URL . '/pages/login.php?error=empty');
        exit;
    }

    $wait = check_login_rate($username);
    if ($wait > 0) {
        $mins = (int)ceil($wait / 60);
        header('Location: ' . BASE_URL . '/pages/login.php?error=' . urlencode("Account locked. Try again in $mins minute(s)."));
        exit;
    }

    $needs_2fa = false;
    $disabled  = false;
    if (login($username, $password, $needs_2fa, $disabled)) {
        if ($needs_2fa) {
            header('Location: ' . BASE_URL . '/pages/verify_2fa.php');
        } else {
            header('Location: ' . BASE_URL . '/modules/dashboard.php');
        }
        exit;
    } elseif ($disabled) {
        header('Location: ' . BASE_URL . '/pages/login.php?error=disabled');
        exit;
    } else {
        header('Location: ' . BASE_URL . '/pages/login.php?error=invalid');
        exit;
    }
}

$error_param = $_GET['error'] ?? '';
$error = '';
if ($error_param === 'empty')    $error = 'Please enter your username and password.';
if ($error_param === 'invalid')  $error = 'Invalid username or password. Please try again.';
if ($error_param === 'disabled') $error = 'Your account has been deactivated. Please contact your administrator for assistance.';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Zimnat — Finance Reconciliation Portal</title>
    <link href="https://fonts.googleapis.com/css?family=Open+Sans:400,600,700" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="../assets/css/login.css">
</head>
<body>
    <div class="wrapper">

        <form class="login" method="post" action="login.php">
      <?= csrf_field() ?>

            <!-- Zimnat brand -->
            <div class="brand">
                <div class="logo-wrapper">
                    <img src="../assets/img/zimnat logo.png" alt="Zimnat Logo" class="brand-image"
                         style="height:70px;width:auto">
                </div>
                <div class="brand-logo">Icecash<span>Rec</span></div>
                <div class="brand-sub">Finance Reconciliation System</div>
            </div>

            <p class="title">STAFF FINANCE PORTAL</p>

            <?php if ($error): ?>
            <div class="error-msg"><i class="fa-solid fa-triangle-exclamation"></i> <?= htmlspecialchars($error) ?></div>
            <?php endif; ?>

            <input type="text"
                   placeholder="Username"
                   name="username"
                   value="<?= htmlspecialchars($_POST['username'] ?? '') ?>"
                   autocomplete="username"
                   autofocus />
            <i class="fa-solid fa-user"></i>

            <input type="password"
                   placeholder="Password"
                   name="password"
                   autocomplete="current-password" />
            <i class="fa-solid fa-lock"></i>

            <button type="submit">
                <i class="spinner"></i>
                <span class="state">Sign In &rarr;</span>
            </button>

        </form>

        <!-- Demo credentials -->
        <div class="demo-box">
            <div class="demo-title">Demo Accounts — click to autofill</div>
            <div class="demo-account" onclick="autofill('farai.choto','manager2025')">
                <div>
                    <div class="demo-user">Farai Choto</div>
                    <div class="demo-creds">farai.choto / manager2025</div>
                </div>
                <span class="demo-role">Manager</span>
            </div>
            <div class="demo-account" onclick="autofill('tendai.moyo','recon2025')">
                <div>
                    <div class="demo-user">Tendai Moyo</div>
                    <div class="demo-creds">tendai.moyo / recon2025</div>
                </div>
                <span class="demo-role">Reconciler</span>
            </div>
            <div class="demo-account" onclick="autofill('upload.user','upload2025')">
                <div>
                    <div class="demo-user">Upload User</div>
                    <div class="demo-creds">upload.user / upload2025</div>
                </div>
                <span class="demo-role">Uploader</span>
            </div>
            <div class="demo-account" onclick="autofill('sys.admin','admin2025')">
                <div>
                    <div class="demo-user">System Administrator</div>
                    <div class="demo-creds">sys.admin / admin2025</div>
                </div>
                <span class="demo-role" style="background:rgba(192,57,43,0.12);color:#b91c3c">Admin</span>
            </div>
        </div>

        <footer>Zimnat General Insurance &nbsp;·&nbsp; Reconciliation System v1.0 &nbsp;·&nbsp; &copy; <?= date('Y') ?></footer>
    </div>

    <script>
    function autofill(user, pass) {
        document.querySelector('input[name="username"]').value = user;
        document.querySelector('input[name="password"]').value = pass;
        document.querySelector('input[name="username"]').focus();
    }
    </script>
</body>
</html>
