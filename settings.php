<?php
/**
 * ProcrastiNot - Settings: study rhythm and motivation preferences.
 *
 * Profile details and password now live on the dedicated My Account page.
 */
require_once __DIR__ . '/includes/auth.php';

$user = require_login();
$userId = (int) $user['id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'preferences') {
        $xpEnabled    = isset($_POST['xp_enabled']) && $_POST['xp_enabled'] === '1' ? 1 : 0;
        $dailyMinutes = (int) ($_POST['daily_study_minutes'] ?? 120);
        $weeklyGoal   = (int) ($_POST['weekly_goal'] ?? 5);

        $errors = [];
        if ($dailyMinutes < 0 || $dailyMinutes > 720) {
            $errors[] = 'Daily study minutes must be between 0 and 720.';
        }
        if ($weeklyGoal < 1 || $weeklyGoal > 50) {
            $errors[] = 'Your weekly goal must be between 1 and 50 tasks.';
        }

        if ($errors) {
            foreach ($errors as $error) {
                flash('error', $error);
            }
        } else {
            $stmt = db()->prepare(
                'UPDATE users SET xp_enabled = ?, daily_study_minutes = ?,
                 weekly_goal = ?, updated_at = NOW() WHERE id = ?'
            );
            $stmt->execute([$xpEnabled, $dailyMinutes, $weeklyGoal, $userId]);
            flash('success', 'Your preferences have been saved.');
        }
        redirect('settings.php');
    }
}

// Re-read so the page always shows current values.
$stmt = db()->prepare(
    'SELECT id, name, email, daily_study_minutes, weekly_goal, xp_enabled
     FROM users WHERE id = ? LIMIT 1'
);
$stmt->execute([$userId]);
$user = $stmt->fetch();

$pageTitle = 'Settings';
$currentPage = 'settings';
require __DIR__ . '/includes/header.php';
?>

<section class="card" aria-labelledby="preferences-heading">
  <div class="card-head">
    <h2 id="preferences-heading">Study rhythm &amp; motivation</h2>
  </div>
  <p class="muted">These guide the Smart Priority Engine and the optional XP system. You are always in control.</p>
  <form method="post" action="settings.php" class="form">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="preferences">
    <div class="form-row">
      <div class="field">
        <label for="daily_study_minutes">Study time available per day (minutes)</label>
        <input type="number" id="daily_study_minutes" name="daily_study_minutes"
               min="0" max="720" step="15"
               value="<?= (int) ($user['daily_study_minutes'] ?? 120) ?>"
               aria-describedby="study-help">
        <p class="field-help" id="study-help">Used to judge how much effort fits in a day (0-720).</p>
      </div>
      <div class="field">
        <label for="weekly_goal">Weekly task goal</label>
        <input type="number" id="weekly_goal" name="weekly_goal"
               min="1" max="50" step="1"
               value="<?= (int) ($user['weekly_goal'] ?? 5) ?>"
               aria-describedby="goal-help">
        <p class="field-help" id="goal-help">A gentle target for tasks completed each week (1-50).</p>
      </div>
    </div>
    <div class="field field-checkbox">
      <label for="xp_enabled">
        <input type="checkbox" id="xp_enabled" name="xp_enabled" value="1"
               <?= (int) ($user['xp_enabled'] ?? 1) === 1 ? 'checked' : '' ?>>
        Turn on XP, levels and badges
      </label>
      <p class="field-help">Optional motivation. Turn it off any time &mdash; your tasks, focus sessions and history are never affected.</p>
    </div>
    <div class="form-actions">
      <button type="submit" class="btn btn-primary">Save preferences</button>
    </div>
  </form>
</section>

<section class="card" aria-labelledby="appearance-heading">
  <h2 id="appearance-heading">Appearance</h2>
  <p class="muted">Switch between light and dark themes. Your choice is remembered on this device.</p>
  <button class="pref-row" type="button" data-theme-toggle>
    <span class="pref-row-icon"><span data-lucide="sun-moon" aria-hidden="true"></span></span>
    <span class="pref-row-text">
      <span class="pref-row-title">Theme</span>
      <span class="pref-row-sub" data-theme-label>Dark theme</span>
    </span>
    <span data-lucide="chevron-right" aria-hidden="true"></span>
  </button>
</section>

<section class="card" aria-labelledby="account-link-heading">
  <h2 id="account-link-heading">Profile &amp; security</h2>
  <p class="muted">Your name, email, course, year level and password are managed on your account page.</p>
  <div class="form-actions">
    <a class="btn btn-secondary" href="account.php">
      <span data-lucide="user-round" aria-hidden="true"></span> Go to My Account
    </a>
  </div>
</section>

<?php require __DIR__ . '/includes/footer.php'; ?>
