<?php
require_once './core/auth.php';

// Route to appropriate page
if (is_logged_in()) {
    header('Location: ' . BASE_URL . '/modules/dashboard.php');
} else {
    header('Location: ' . BASE_URL . '/pages/login.php');
}
exit;
