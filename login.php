<?php
/**
 * ProcrastiNot - student login.
 */
require_once __DIR__ . '/includes/auth.php';

if (current_user() !== null) {
    redirect('dashboard.php');
}

$errors = [];
$old = ['email' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();

    $old['email'] = trim((string) ($_POST['email'] ?? ''));
    $password     = (string) ($_POST['password'] ?? '');

    if ($old['email'] === '' || $password === '') {
        $errors[] = 'Please enter your email address and password.';
    } elseif (!validate_email($old['email'])) {
        $errors[] = 'That email address is not valid.';
    }

    if (!$errors) {
        $stmt = db()->prepare(
            'SELECT id, password_hash FROM users WHERE email = ? LIMIT 1'
        );
        $stmt->execute([$old['email']]);
        $row = $stmt->fetch();

        if ($row && password_verify($password, $row['password_hash'])) {
            login_user((int) $row['id']);
            flash('success', 'Welcome back!');
            redirect('dashboard.php');
        }
        // Generic message: never reveal whether the email exists.
        $errors[] = 'Invalid email or password.';
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
<title>Log in | <?= e(app_name()) ?></title>
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
    <h2>Turn plans into progress, one task at a time.</h2>
    <p class="auth-tagline">&ldquo;<?= e(app_tagline()) ?>&rdquo;</p>
    <ul class="auth-visual-points">
      <li><span data-lucide="target"></span> A daily focus list that shows what matters now</li>
      <li><span data-lucide="calendar-days"></span> A calendar of every deadline this month</li>
      <li><span data-lucide="trending-up"></span> Reports that reveal your best study weeks</li>
    </ul>
  </aside>
  <div class="auth-panel">
    <button class="theme-toggle auth-theme-toggle" type="button" data-theme-toggle aria-label="Switch to dark theme">
      <span data-lucide="moon" aria-hidden="true"></span>
      <span data-theme-label>Dark theme</span>
    </button>
    <div class="auth-card">
      <div class="auth-brand">
        <h1 class="auth-title">Welcome back</h1>
        <p class="auth-sub">Log in to keep your focus &mdash; one task at a time.</p>
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
      <form method="post" action="login.php" class="form" novalidate>
        <?= csrf_field() ?>
        <div class="field">
          <label for="email">Email address</label>
          <input type="email" id="email" name="email" required
                 autocomplete="email" value="<?= e($old['email']) ?>">
        </div>
        <div class="field">
          <label for="password">Password</label>
          <input type="password" id="password" name="password" required
                 autocomplete="current-password">
        </div>
        <button type="submit" class="btn btn-primary btn-block">Log in</button>
      </form>
      <p class="auth-switch">New to <?= e(app_name()) ?>? <a href="register.php">Create an account</a></p>
    </div>
  </div>
</main>
<div id="toast-region" class="toast-region" role="status" aria-live="polite"></div>
<script src="https://unpkg.com/lucide@latest/dist/umd/lucide.min.js"></script>
<script src="assets/js/app.js"></script>
</body>
</html>
