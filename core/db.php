<?php
// ============================================================
// core/db.php — Mysqli connection singleton
// Credentials live in core/config.php (add to .gitignore on prod).
// ============================================================
// Pull in the database credentials (host, user, password, db name)
require_once __DIR__ . '/config.php';

// Returns a single shared database connection.
// We use a static variable so we only connect once per request,
// no matter how many times get_db() is called.
function get_db() {
    static $db = null;

    // Only connect if we haven't already
    if ($db === null) {
        // Connect using the credentials from config.php
        $db = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME_CONST);

        // If something went wrong (wrong password, DB down, etc), stop immediately
        if ($db->connect_error) {
            die('Database connection failed: ' . $db->connect_error);
        }

        // Use UTF-8 so special characters (accents, emojis) don't break
        $db->set_charset('utf8mb4');
    }

    return $db;
}
