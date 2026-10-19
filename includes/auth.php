<?php
/**
 * ProcrastiNot - authentication and session handling.
 * Include this at the top of every page.
 */

require_once __DIR__ . '/functions.php';
require_once dirname(__DIR__) . '/config/database.php';

// Secure session cookie, then start the session.
session_set_cookie_params([
    'lifetime' => 0,
    'path'     => '/',
    'httponly' => true,
    'samesite' => 'Lax',
]);
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

/** Currently signed-in user row, or null. */
function current_user(): ?array
{
    static $user = false;
    if ($user !== false) {
        return $user;
    }
    if (empty($_SESSION['user_id'])) {
        $user = null;
        return null;
    }
    $stmt = db()->prepare(
        'SELECT id, name, email, greeting, course, year_level, created_at,
                daily_study_minutes, weekly_goal, xp_enabled
         FROM users WHERE id = ? LIMIT 1'
    );
    $stmt->execute([$_SESSION['user_id']]);
    $user = $stmt->fetch() ?: null;
    if ($user === null) {
        // Session points at a deleted account: force logout.
        unset($_SESSION['user_id']);
    }
    return $user;
}

/** Sign a student in and rotate the session ID. */
function login_user(int $userId): void
{
    session_regenerate_id(true);
    $_SESSION['user_id'] = $userId;
}

/**
 * Protect a page: unauthenticated visitors are sent to the login page.
 * Returns the signed-in user row.
 */
function require_login(): array
{
    $user = current_user();
    if ($user === null) {
        redirect('login.php');
    }
    return $user;
}
