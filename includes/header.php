<?php
/**
 * ProcrastiNot - page header for authenticated pages.
 * Expects: $user (array), $pageTitle (string), $currentPage (string)
 */
require_once __DIR__ . '/layout.php';
$flashes = get_flashes();
$moreItems = more_menu_items($currentPage);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="theme-color" content="#7655E8">
<title><?= e($pageTitle) ?> | <?= e(app_name()) ?></title>
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
<body class="app-body" data-page="<?= e($currentPage) ?>">
<a class="skip-link" href="#main-content">Skip to main content</a>
<div class="app-shell">
  <div class="sidebar-overlay" hidden></div>

  <aside class="sidebar" id="sidebar" aria-label="Main navigation">
    <div class="sidebar-brand"><?= brand_wordmark() ?></div>

    <nav class="sidebar-nav" aria-label="Workspace">
      <?php foreach (nav_groups($currentPage) as $group): ?>
      <p class="sidebar-group-label"><?= e($group['label']) ?></p>
      <ul>
        <?php foreach ($group['items'] as $item): ?>
        <li>
          <a href="<?= e($item['href']) ?>" class="sidebar-link<?= $item['active'] ? ' is-active' : '' ?>"
             title="<?= e($item['label']) ?>"
             <?= $item['active'] ? 'aria-current="page"' : '' ?>>
            <span data-lucide="<?= e($item['icon']) ?>" aria-hidden="true"></span>
            <span class="sidebar-link-label"><?= e($item['label']) ?></span>
          </a>
        </li>
        <?php endforeach; ?>
      </ul>
      <?php endforeach; ?>
    </nav>

    <div class="sidebar-account">
      <a class="sidebar-user" href="account.php">
        <?= user_avatar($user, 'md') ?>
        <span class="sidebar-user-text">
          <span class="sidebar-user-name"><?= e($user['name']) ?></span>
          <span class="sidebar-user-email"><?= e($user['email']) ?></span>
        </span>
      </a>
      <div class="sidebar-account-actions">
        <button class="sidebar-icon-btn" type="button" data-theme-toggle aria-label="Switch to dark theme" title="Switch theme">
          <span data-lucide="moon" aria-hidden="true"></span>
          <span class="sidebar-link-label" data-theme-label>Theme</span>
        </button>
        <a class="sidebar-icon-btn" href="logout.php" title="Log out">
          <span data-lucide="log-out" aria-hidden="true"></span>
          <span class="sidebar-link-label">Log out</span>
        </a>
      </div>
    </div>
  </aside>

  <div class="main-wrap">
    <header class="topbar">
      <span class="topbar-brand"><?= brand_wordmark() ?></span>
      <h1 class="topbar-title"><?= e($pageTitle) ?></h1>
      <button class="topbar-avatar" type="button" data-account-toggle
              aria-haspopup="true" aria-expanded="false" aria-controls="account-menu"
              aria-label="Open account menu">
        <?= user_avatar($user, 'sm') ?>
      </button>
    </header>
    <main class="main" id="main-content">
<?php if ($flashes): ?>
<script type="application/json" id="flash-data"><?= json_encode($flashes, JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
<?php endif; ?>
