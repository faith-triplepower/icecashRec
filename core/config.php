<?php
$envPath = __DIR__ . '/../.env';

if (!file_exists($envPath)) {
    die('Configuration error: .env file not found. Please create it from .env.example.');
}

$env = parse_ini_file($envPath);

if (!defined('DB_HOST'))          define('DB_HOST',         $env['DB_HOST']          ?? 'localhost');
if (!defined('DB_USER'))          define('DB_USER',         $env['DB_USER']          ?? 'root');
if (!defined('DB_PASS'))          define('DB_PASS',         $env['DB_PASS']          ?? '');
if (!defined('DB_NAME_CONST'))    define('DB_NAME_CONST',   $env['DB_NAME']          ?? 'icecash_recon');
if (!defined('SCRIPTS_USERNAME')) define('SCRIPTS_USERNAME', $env['SCRIPTS_USERNAME'] ?? '');
if (!defined('SCRIPTS_PASSWORD')) define('SCRIPTS_PASSWORD', $env['SCRIPTS_PASSWORD'] ?? '');

if (empty($env['APP_BASE_URL'])) {
    die('Configuration error: APP_BASE_URL is not set in .env.');
}
if (!defined('BASE_URL')) define('BASE_URL', rtrim($env['APP_BASE_URL'], '/'));
