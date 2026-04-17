<?php
// ============================================================
// core/config.php — Database credentials & environment config
// On production: use a dedicated least-privilege MySQL user
// (no DROP, no FILE, no SUPER). Add this file to .gitignore.
// ============================================================

// Where's the database? (usually localhost for XAMPP)
define('DB_HOST', 'localhost');

// Database login — on production, create a dedicated user with limited permissions
define('DB_USER', 'root');
define('DB_PASS', '');

// The actual database name in MySQL
define('DB_NAME_CONST', 'icecash_recon');
