<?php
/**
 * ProcrastiNot - main configuration.
 * Edit the four database constants below if your XAMPP setup differs.
 */

// ---- Branding ----
define('APP_NAME', 'ProcrastiNot');
define('APP_TAGLINE', 'Your deadlines called. We answered.');

// ---- Database settings (default XAMPP values) ----
// Note: the database name is kept as tasktrack_db for compatibility with any
// existing installs. Renaming it is not required for the visible rebranding.
define('DB_HOST', 'localhost');
define('DB_NAME', 'tasktrack_db');
define('DB_USER', 'root');
define('DB_PASS', ''); // default XAMPP MySQL password is empty

// Base URL path of the app when served by Apache (used only if absolute
// links are ever needed). Keep the trailing structure simple.
define('APP_URL', '/tasktrack');

// ---- Error handling: never expose credentials or stack traces ----
ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
error_reporting(E_ALL);

$logDir = dirname(__DIR__) . '/logs';
if (!is_dir($logDir)) {
    @mkdir($logDir, 0775, true);
}
ini_set('error_log', $logDir . '/php-error.log');

set_exception_handler(static function (Throwable $ex): void {
    error_log('Uncaught exception: ' . $ex->getMessage()
        . ' in ' . $ex->getFile() . ':' . $ex->getLine());
    if (!headers_sent()) {
        http_response_code(500);
    }
    echo 'Something went wrong. Please try again later. Details were written to the error log.';
    exit;
});
