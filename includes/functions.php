<?php
/**
 * ProcrastiNot - shared helper functions (escaping, CSRF, flashes, validation).
 */

require_once dirname(__DIR__) . '/config/config.php';

/** The application name, used for titles, branding and microcopy. */
function app_name(): string
{
    return defined('APP_NAME') ? APP_NAME : 'ProcrastiNot';
}

/** The marketing tagline. */
function app_tagline(): string
{
    return defined('APP_TAGLINE') ? APP_TAGLINE : 'Your deadlines called. We answered.';
}

/**
 * Brand wordmark: a gradient tile with a checkmark plus the app name.
 * Inline SVG so the logo renders even when the icon CDN is unavailable.
 * $size: 'md' (sidebar/header) or 'lg' (auth screens).
 */
function brand_wordmark(string $size = 'md'): string
{
    $class = 'brand' . ($size === 'lg' ? ' brand-lg' : '');
    return '<span class="' . $class . '">'
        . '<span class="brand-mark" aria-hidden="true">'
        . '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3.4"'
        . ' stroke-linecap="round" stroke-linejoin="round"><path d="M4 12.5l5 5L20 6.5"/></svg>'
        . '</span>'
        . '<span class="brand-text">Procrasti<span class="brand-accent">Not</span></span>'
        . '</span>';
}

/** Escape a value for safe output inside HTML. */
function e(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

/**
 * Up to two initials from a display name, for the avatar. Falls back to a
 * neutral "?" when the name is empty so the avatar never looks broken.
 */
function user_initials(?string $name): string
{
    $name = trim((string) $name);
    if ($name === '') {
        return '?';
    }
    $parts = preg_split('/\s+/', $name) ?: [];
    $first = mb_substr($parts[0] ?? '', 0, 1);
    $last  = count($parts) > 1 ? mb_substr((string) end($parts), 0, 1) : '';
    $initials = mb_strtoupper($first . $last);
    return $initials !== '' ? $initials : '?';
}

/**
 * Initials avatar markup. Purely presentational (initials are escapes).
 * $size: 'sm' (topbar/menu), 'md' (sidebar), 'lg' (account header).
 */
function user_avatar(?array $user, string $size = 'sm'): string
{
    $name = is_array($user) ? ($user['name'] ?? '') : '';
    $class = 'avatar avatar-' . preg_replace('/[^a-z]/', '', $size);
    return '<span class="' . $class . '" aria-hidden="true">'
        . e(user_initials((string) $name)) . '</span>';
}

/** Redirect to a relative path and stop the script. */
function redirect(string $path): void
{
    header('Location: ' . $path);
    exit;
}

// ---------------------------------------------------------------------
// CSRF protection
// ---------------------------------------------------------------------

function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="_csrf" value="'
        . e(csrf_token()) . '">';
}

function verify_csrf(): bool
{
    $sent = $_POST['_csrf'] ?? '';
    return is_string($sent) && $sent !== ''
        && hash_equals(csrf_token(), $sent);
}

/**
 * Require a valid CSRF token on a POST request.
 * Rejects with an error flash and a redirect back to the referer.
 */
function require_csrf(): void
{
    if (!verify_csrf()) {
        flash('error', 'Your session token was missing or invalid. Please try again.');
        $back = $_SERVER['HTTP_REFERER'] ?? 'index.php';
        redirect($back);
    }
}

// ---------------------------------------------------------------------
// Flash messages (rendered as toasts by assets/js/app.js)
// ---------------------------------------------------------------------

function flash(string $type, string $message): void
{
    $_SESSION['flashes'][] = ['type' => $type, 'message' => $message];
}

/** Consume and return all pending flash messages. */
function get_flashes(): array
{
    $flashes = $_SESSION['flashes'] ?? [];
    unset($_SESSION['flashes']);
    return $flashes;
}

// ---------------------------------------------------------------------
// Validation helpers
// ---------------------------------------------------------------------

function validate_email(string $email): bool
{
    return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
}

function allowed_status(): array
{
    return ['pending', 'in_progress', 'completed'];
}

function allowed_priority(): array
{
    return ['low', 'medium', 'high'];
}

function is_valid_status(string $status): bool
{
    return in_array($status, allowed_status(), true);
}

function is_valid_priority(string $priority): bool
{
    return in_array($priority, allowed_priority(), true);
}

/** Validate a Y-m-d date string (real calendar date only). */
function is_valid_date(string $date): bool
{
    if ($date === '') {
        return true; // empty means "no due date"
    }
    $dt = DateTime::createFromFormat('Y-m-d', $date);
    return $dt instanceof DateTime
        && $dt->format('Y-m-d') === $date;
}

/** Escape LIKE wildcards so student input matches literally. */
function like_escape(string $value): string
{
    return addcslashes($value, '%_\\');
}

// ---------------------------------------------------------------------
// Display labels
// ---------------------------------------------------------------------

function status_label(string $status): string
{
    $labels = [
        'pending'      => 'Pending',
        'in_progress'  => 'In Progress',
        'completed'    => 'Completed',
    ];
    return $labels[$status] ?? $status;
}

function priority_label(string $priority): string
{
    $labels = [
        'low'    => 'Low',
        'medium' => 'Medium',
        'high'   => 'High',
    ];
    return $labels[$priority] ?? $priority;
}

/**
 * True when a task counts as overdue:
 * due date has passed and the task is not completed.
 */
function task_is_overdue(?string $dueDate, string $status): bool
{
    if ($dueDate === null || $dueDate === '' || $status === 'completed') {
        return false;
    }
    return $dueDate < date('Y-m-d');
}
