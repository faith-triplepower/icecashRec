<?php
// ============================================================
// pages/index.php
// Root redirect to login or dashboard based on session.
// ============================================================
require_once '../core/auth.php';

// Route to appropriate page
if (is_logged_in()) {
    header('Location: ' . BASE_URL . '/modules/dashboard.php');
} else {
    header('Location: ' . BASE_URL . '/pages/login.php');
}
exit;
?>

