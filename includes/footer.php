<?php
/**
 * ProcrastiNot - page footer for authenticated pages.
 * Optional: a page may set $pageScripts = ['tasks.js'] before including.
 */
$pageScripts = $pageScripts ?? [];
$currentPage = $currentPage ?? '';
$moreItems = more_menu_items($currentPage);
?>
    </main>
    <footer class="app-footer">
      <p><?= e(app_name()) ?> &mdash; <?= e(app_tagline()) ?></p>
    </footer>
  </div>
</div>

<!-- Account / more menu (opened from the avatar or the "More" tab) -->
<div class="account-menu" id="account-menu" role="menu" aria-label="Account menu" hidden>
  <div class="account-menu-head">
    <?= user_avatar($user, 'md') ?>
    <span class="account-menu-identity">
      <span class="account-menu-name"><?= e($user['name']) ?></span>
      <span class="account-menu-email"><?= e($user['email']) ?></span>
    </span>
  </div>
  <ul class="account-menu-list">
    <?php foreach ($moreItems as $item): ?>
    <li>
      <a href="<?= e($item['href']) ?>" class="account-menu-link<?= $item['active'] ? ' is-active' : '' ?>"
         role="menuitem"<?= $item['active'] ? ' aria-current="page"' : '' ?>>
        <span data-lucide="<?= e($item['icon']) ?>" aria-hidden="true"></span>
        <?= e($item['label']) ?>
      </a>
    </li>
    <?php endforeach; ?>
    <li>
      <button type="button" class="account-menu-link" data-theme-toggle role="menuitem">
        <span data-lucide="moon" aria-hidden="true"></span>
        <span data-theme-label>Dark theme</span>
      </button>
    </li>
  </ul>
  <div class="account-menu-foot">
    <a href="logout.php" class="account-menu-link account-menu-logout" role="menuitem">
      <span data-lucide="log-out" aria-hidden="true"></span>
      Log out
    </a>
  </div>
</div>

<!-- Fixed bottom navigation (mobile) -->
<nav class="bottom-nav" aria-label="Primary">
  <ul>
    <?php foreach (bottom_nav_items($currentPage) as $item): ?>
    <li>
      <a href="<?= e($item['href']) ?>" class="bottom-nav-link<?= $item['active'] ? ' is-active' : '' ?>"
         <?= $item['active'] ? 'aria-current="page"' : '' ?>>
        <span data-lucide="<?= e($item['icon']) ?>" aria-hidden="true"></span>
        <span class="bottom-nav-label"><?= e($item['label']) ?></span>
      </a>
    </li>
    <?php endforeach; ?>
    <li>
      <button type="button" class="bottom-nav-link" data-account-toggle
              aria-haspopup="true" aria-expanded="false" aria-controls="account-menu">
        <span data-lucide="menu" aria-hidden="true"></span>
        <span class="bottom-nav-label">More</span>
      </button>
    </li>
  </ul>
</nav>

<div id="toast-region" class="toast-region" role="status" aria-live="polite" aria-atomic="false"></div>
<script src="https://unpkg.com/lucide@latest/dist/umd/lucide.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
<script src="assets/js/app.js"></script>
<?php foreach ($pageScripts as $script): ?>
<script src="assets/js/<?= e($script) ?>"></script>
<?php endforeach; ?>
</body>
</html>
