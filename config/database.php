<?php
/**
 * ProcrastiNot - database connection.
 * Provides db(), a single shared PDO connection using prepared statements.
 */

require_once __DIR__ . '/config.php';

function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4';
    try {
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
    } catch (PDOException $ex) {
        error_log('Database connection failed: ' . $ex->getMessage());
        http_response_code(500);
        echo '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8">'
            . '<title>ProcrastiNot - Database error</title></head><body>'
            . '<h1>Database connection failed</h1>'
            . '<p>ProcrastiNot could not connect to MySQL. Make sure MySQL is '
            . 'running in the XAMPP Control Panel and that you imported '
            . '<code>database.sql</code> through phpMyAdmin. '
            . 'See the <em>Troubleshooting</em> section of the README.</p>'
            . '</body></html>';
        exit;
    }

    return $pdo;
}
