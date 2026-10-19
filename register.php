<?php
/**
 * ProcrastiNot - student registration.
 */
require_once __DIR__ . '/includes/auth.php';

if (current_user() !== null) {
    redirect('dashboard.php');
}

$errors = [];
$old = ['name' => '', 'email' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();

    $old['name']  = trim((string) ($_POST['name'] ?? ''));
    $old['email'] = trim((string) ($_POST['email'] ?? ''));
    $password     = (string) ($_POST['password'] ?? '');
    $confirm      = (string) ($_POST['password_confirm'] ?? '');

    if ($old['name'] === '' || mb_strlen($old['name']) > 100) {
        $errors[] = 'Please enter your name (1-100 characters).';
    }
    if ($old['email'] === '') {
        $errors[] = 'Please enter your email address.';
    } elseif (!validate_email($old['email'])) {
        $errors[] = 'That email address is not valid.';
    }
    if (strlen($password) < 8) {
        $errors[] = 'Your password must be at least 8 characters.';
    }
    if ($password !== $confirm) {
        $errors[] = 'The passwords do not match.';
    }

    if (!$errors) {
        $stmt = db()->prepare('SELECT id FROM users WHERE email = ? LIMIT 1');
        $stmt->execute([$old['email']]);
        if ($stmt->fetch()) {
            $errors[] = 'An account with that email address already exists.';
        }
    }

    if (!$errors) {
        try {
            db()->beginTransaction();
            $stmt = db()->prepare(
                'INSERT INTO users (name, email, password_hash) VALUES (?, ?, ?)'
            );
            $stmt->execute([
                $old['name'],
                $old['email'],
                password_hash($password, PASSWORD_DEFAULT),
            ]);
            $userId = (int) db()->lastInsertId();

            $stmt = db()->prepare(
                'INSERT INTO categories (user_id, name) VALUES (?, ?)'
            );
            $stmt->execute([$userId, 'General']);
            db()->commit();
        } catch (Throwable $ex) {
            db()->rollBack();
            throw $ex;
        }

        login_user($userId);
        flash('success', 'Welcome to ' . app_name() . ', ' . $old['name'] . '!');
        redirect('dashboard.php');
    }

    foreach ($errors as $error) {
        flash('error', $error);
    }
}

$flashes = get_flashes();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Register | <?= e(app_name()) ?></title>
<script>
(function () {
  try {
    var t = localStorage.getItem('tt-theme');
    if (t === 'dark' || t === 'light') document.documentElement.setAttribute('data-theme', t);
  } catch (e) {}
})();
</script>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/css/style.css">
</head>
<body class="auth-body">
<main class="auth-split" id="main-content">
  <aside class="auth-visual" aria-hidden="true">
    <div class="auth-visual-brand"><?= brand_wordmark('lg') ?></div>
    <h2>Look at you, getting things done!</h2>
    <p class="auth-tagline">&ldquo;<?= e(app_tagline()) ?>&rdquo;</p>
    <ul class="auth-visual-points">
      <li><span data-lucide="sparkles"></span> A cheerful dashboard with instant progress</li>
      <li><span data-lucide="folder-open"></span> Subjects to group tasks by course</li>
      <li><span data-lucide="sun-moon"></span> Light and dark themes for day or night</li>
    </ul>
  </aside>
  <div class="auth-panel">
    <button class="theme-toggle auth-theme-toggle" type="button" data-theme-toggle aria-label="Switch to dark theme">
      <span data-lucide="moon" aria-hidden="true"></span>
      <span data-theme-label>Dark theme</span>
    </button>
    <div class="auth-card">
      <div class="auth-brand">
        <h1 class="auth-title">Create your account</h1>
        <p class="auth-sub">Join <?= e(app_name()) ?> to organize your studies.</p>
      </div>
      <?php if ($flashes): ?>
      <script type="application/json" id="flash-data"><?= json_encode($flashes, JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
      <div class="alert alert-error" role="alert">
        <ul>
          <?php foreach ($errors as $error): ?>
          <li><?= e($error) ?></li>
          <?php endforeach; ?>
        </ul>
      </div>
      <?php endif; ?>
      <form method="post" action="register.php" class="form" novalidate>
        <?= csrf_field() ?>
        <div class="field">
          <label for="name">Full name</label>
          <input type="text" id="name" name="name" required maxlength="100"
                 autocomplete="name" value="<?= e($old['name']) ?>">
        </div>
        <div class="field">
          <label for="email">Email address</label>
          <input type="email" id="email" name="email" required
                 autocomplete="email" value="<?= e($old['email']) ?>">
        </div>
        <div class="field">
          <label for="password">Password</label>
          <input type="password" id="password" name="password" required
                 minlength="8" autocomplete="new-password"
                 aria-describedby="password-help">
          <p class="field-help" id="password-help">At least 8 characters.</p>
        </div>
        <div class="field">
          <label for="password_confirm">Confirm password</label>
          <input type="password" id="password_confirm" name="password_confirm"
                 required autocomplete="new-password">
        </div>
        <button type="submit" class="btn btn-primary btn-block">Create account</button>
      </form>
      <p class="auth-switch">Already have an account? <a href="login.php">Log in</a></p>
    </div>
  </div>
</main>
<div id="toast-region" class="toast-region" role="status" aria-live="polite"></div>
<script src="https://unpkg.com/lucide@latest/dist/umd/lucide.min.js"></script>
<script src="assets/js/app.js"></script>
</body>
</html>
