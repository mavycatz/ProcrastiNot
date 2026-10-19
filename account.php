<?php
/**
 * ProcrastiNot - My Account.
 *
 * One calm place to manage the student's profile, sign-in details and
 * preferences, plus a personal productivity summary built only from their own
 * real data. Every update is CSRF-protected and scoped to the session user.
 */
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/tasks.php';
require_once __DIR__ . '/includes/focus.php';
require_once __DIR__ . '/includes/gamification.php';

$user = require_login();
$userId = (int) $user['id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'profile') {
        $name      = trim((string) ($_POST['name'] ?? ''));
        $email     = trim((string) ($_POST['email'] ?? ''));
        $course    = trim((string) ($_POST['course'] ?? ''));
        $yearLevel = trim((string) ($_POST['year_level'] ?? ''));
        $greeting  = trim((string) ($_POST['greeting'] ?? ''));

        $errors = [];
        if ($name === '' || mb_strlen($name) > 100) {
            $errors[] = 'Please enter your name (1-100 characters).';
        }
        if ($email === '' || !validate_email($email)) {
            $errors[] = 'Please enter a valid email address.';
        }
        if (mb_strlen($course) > 120) {
            $errors[] = 'Course or programme must be 120 characters or fewer.';
        }
        if (mb_strlen($yearLevel) > 40) {
            $errors[] = 'Year level must be 40 characters or fewer.';
        }
        if (mb_strlen($greeting) > 150) {
            $errors[] = 'Your greeting must be 150 characters or fewer.';
        }
        if (!$errors) {
            $stmt = db()->prepare('SELECT id FROM users WHERE email = ? AND id <> ? LIMIT 1');
            $stmt->execute([$email, $userId]);
            if ($stmt->fetch()) {
                $errors[] = 'Another account already uses that email address.';
            }
        }

        if ($errors) {
            foreach ($errors as $error) {
                flash('error', $error);
            }
        } else {
            $stmt = db()->prepare(
                'UPDATE users
                 SET name = ?, email = ?, course = ?, year_level = ?, greeting = ?,
                     updated_at = NOW()
                 WHERE id = ?'
            );
            $stmt->execute([
                $name,
                $email,
                $course !== '' ? $course : null,
                $yearLevel !== '' ? $yearLevel : null,
                $greeting !== '' ? $greeting : null,
                $userId,
            ]);
            flash('success', 'Your profile has been updated.');
        }
        redirect('account.php');

    } elseif ($action === 'password') {
        $current = (string) ($_POST['current_password'] ?? '');
        $new     = (string) ($_POST['new_password'] ?? '');
        $confirm = (string) ($_POST['confirm_password'] ?? '');

        $errors = [];
        $stmt = db()->prepare('SELECT password_hash FROM users WHERE id = ? LIMIT 1');
        $stmt->execute([$userId]);
        $row = $stmt->fetch();

        if (!$row || !password_verify($current, $row['password_hash'])) {
            $errors[] = 'Your current password is incorrect.';
        }
        if (strlen($new) < 8) {
            $errors[] = 'Your new password must be at least 8 characters.';
        }
        if ($new !== $confirm) {
            $errors[] = 'The new passwords do not match.';
        }

        if ($errors) {
            foreach ($errors as $error) {
                flash('error', $error);
            }
        } else {
            $stmt = db()->prepare('UPDATE users SET password_hash = ?, updated_at = NOW() WHERE id = ?');
            $stmt->execute([password_hash($new, PASSWORD_DEFAULT), $userId]);
            flash('success', 'Your password has been changed.');
        }
        redirect('account.php');
    }
}

// Re-read the freshest profile values for the session user only.
$stmt = db()->prepare(
    'SELECT id, name, email, greeting, course, year_level, created_at,
            daily_study_minutes, weekly_goal, xp_enabled
     FROM users WHERE id = ? LIMIT 1'
);
$stmt->execute([$userId]);
$profile = $stmt->fetch() ?: $user;
$user = $profile;

// Personal productivity summary (authenticated student's own records).
$stats   = task_stats($userId);
$streaks = gamification_streaks($userId);
$focusTotals = focus_session_totals($userId);
$gam     = gamification_stats($userId);

$membersSince = !empty($profile['created_at'])
    ? date('F j, Y', strtotime((string) $profile['created_at']))
    : null;

$pageTitle = 'My Account';
$currentPage = 'account';
require __DIR__ . '/includes/header.php';
?>

<section class="account-hero card">
  <div class="account-hero-main">
    <?= user_avatar($user, 'lg') ?>
    <div class="account-hero-text">
      <h2><?= e($user['name']) ?></h2>
      <p class="account-hero-email"><?= e($user['email']) ?></p>
      <p class="account-hero-meta">
        <span class="badge badge-info"><?= e($user['course'] ?: 'No course set') ?></span>
        <span class="badge badge-info"><?= e($user['year_level'] ?: 'No year level set') ?></span>
        <?php if ($membersSince !== null): ?>
        <span class="muted">Member since <?= e($membersSince) ?></span>
        <?php endif; ?>
      </p>
    </div>
  </div>
  <a class="btn btn-primary btn-small" href="#edit-profile">
    <span data-lucide="pencil" aria-hidden="true"></span> Edit profile
  </a>
</section>

<div class="account-grid">
  <div class="account-main">
    <section class="card" aria-labelledby="edit-profile-heading" id="edit-profile">
      <div class="card-head">
        <h2 id="edit-profile-heading">Profile</h2>
      </div>
      <form method="post" action="account.php" class="form">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="profile">
        <div class="form-row">
          <div class="field">
            <label for="name">Full name</label>
            <input type="text" id="name" name="name" required maxlength="100"
                   autocomplete="name" value="<?= e($user['name']) ?>">
          </div>
          <div class="field">
            <label for="email">Email address</label>
            <input type="email" id="email" name="email" required
                   autocomplete="email" value="<?= e($user['email']) ?>">
          </div>
        </div>
        <div class="form-row">
          <div class="field">
            <label for="course">Course or programme <span class="muted">(optional)</span></label>
            <input type="text" id="course" name="course" maxlength="120"
                   placeholder="e.g. BS Information Technology"
                   value="<?= e($user['course'] ?? '') ?>">
          </div>
          <div class="field">
            <label for="year_level">Year level <span class="muted">(optional)</span></label>
            <input type="text" id="year_level" name="year_level" maxlength="40"
                   placeholder="e.g. Year 2"
                   value="<?= e($user['year_level'] ?? '') ?>">
          </div>
        </div>
        <div class="field">
          <label for="greeting">Preferred dashboard greeting</label>
          <input type="text" id="greeting" name="greeting" maxlength="150"
                 value="<?= e($user['greeting'] ?? '') ?>" aria-describedby="greeting-help">
          <p class="field-help" id="greeting-help">Leave empty to use the default greeting.</p>
        </div>
        <div class="form-actions">
          <button type="submit" class="btn btn-primary">Save changes</button>
        </div>
      </form>
    </section>

    <section class="card" aria-labelledby="password-heading">
      <div class="card-head">
        <h2 id="password-heading">Change password</h2>
        <span class="chip-count"><span data-lucide="lock" aria-hidden="true"></span> Secure</span>
      </div>
      <form method="post" action="account.php" class="form">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="password">
        <div class="field">
          <label for="current_password">Current password</label>
          <input type="password" id="current_password" name="current_password"
                 required autocomplete="current-password">
        </div>
        <div class="form-row">
          <div class="field">
            <label for="new_password">New password</label>
            <input type="password" id="new_password" name="new_password" required
                   minlength="8" autocomplete="new-password" aria-describedby="new-password-help">
            <p class="field-help" id="new-password-help">At least 8 characters.</p>
          </div>
          <div class="field">
            <label for="confirm_password">Confirm new password</label>
            <input type="password" id="confirm_password" name="confirm_password"
                   required autocomplete="new-password">
          </div>
        </div>
        <div class="form-actions">
          <button type="submit" class="btn btn-primary">Update password</button>
        </div>
      </form>
    </section>
  </div>

  <aside class="account-side">
    <section class="card" aria-labelledby="summary-heading">
      <div class="card-head">
        <h2 id="summary-heading">Your progress</h2>
        <a class="chip-count chip-link" href="reports.php">View progress</a>
      </div>
      <div class="summary-grid">
        <div class="summary-item">
          <span class="summary-value"><?= (int) $stats['completed'] ?></span>
          <span class="summary-label">Tasks completed</span>
        </div>
        <div class="summary-item">
          <span class="summary-value"><?= (int) $stats['percent'] ?>%</span>
          <span class="summary-label">Completion rate</span>
        </div>
        <div class="summary-item">
          <span class="summary-value"><?= (int) $streaks['current'] ?></span>
          <span class="summary-label">Day streak</span>
        </div>
        <div class="summary-item">
          <span class="summary-value"><?= (int) round($focusTotals['seconds'] / 60) ?>m</span>
          <span class="summary-label">Focus time</span>
        </div>
        <?php if ($gam['enabled']): ?>
        <div class="summary-item">
          <span class="summary-value"><?= (int) $gam['level'] ?></span>
          <span class="summary-label">Level</span>
        </div>
        <div class="summary-item">
          <span class="summary-value"><?= (int) $gam['total_xp'] ?></span>
          <span class="summary-label">Total XP</span>
        </div>
        <?php endif; ?>
      </div>
    </section>

    <section class="card" aria-labelledby="pref-heading">
      <div class="card-head">
        <h2 id="pref-heading">Preferences</h2>
      </div>
      <button class="pref-row" type="button" data-theme-toggle>
        <span class="pref-row-icon"><span data-lucide="sun-moon" aria-hidden="true"></span></span>
        <span class="pref-row-text">
          <span class="pref-row-title">Appearance</span>
          <span class="pref-row-sub" data-theme-label>Dark theme</span>
        </span>
        <span data-lucide="chevron-right" aria-hidden="true"></span>
      </button>
      <a class="pref-row" href="settings.php">
        <span class="pref-row-icon"><span data-lucide="sliders-horizontal" aria-hidden="true"></span></span>
        <span class="pref-row-text">
          <span class="pref-row-title">Study rhythm &amp; XP</span>
          <span class="pref-row-sub">Daily study time, weekly goal, motivation</span>
        </span>
        <span data-lucide="chevron-right" aria-hidden="true"></span>
      </a>
    </section>

    <section class="card account-logout-card">
      <p class="muted">Signed in as <?= e($user['email']) ?>.</p>
      <a class="btn btn-danger btn-block" href="logout.php">
        <span data-lucide="log-out" aria-hidden="true"></span> Log out
      </a>
    </section>
  </aside>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
