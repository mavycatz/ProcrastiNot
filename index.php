<?php
/**
 * ProcrastiNot - entry point. Sends students to the dashboard or the login page.
 */
require_once __DIR__ . '/includes/auth.php';

if (current_user() !== null) {
    redirect('dashboard.php');
}
redirect('login.php');
