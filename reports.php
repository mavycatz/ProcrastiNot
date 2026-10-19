<?php
/**
 * ProcrastiNot - productivity reports from live task data.
 */
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/tasks.php';
require_once __DIR__ . '/includes/focus.php';
require_once __DIR__ . '/includes/gamification.php';
require_once __DIR__ . '/includes/analytics.php';

$user = require_login();
$userId = (int) $user['id'];

$stats     = task_stats($userId);
$weekly    = task_weekly_completions($userId, 8);
$breakdown = task_category_breakdown($userId);
$ontime       = analytics_ontime($userId);
$focusTotals  = focus_session_totals($userId);
$focusByDay   = analytics_focus_by_weekday($userId);
$productiveDay = analytics_most_productive_day($userId);
$overdueTrend = analytics_overdue_trend($userId, 8);
$overdueTrendHasData = array_sum(array_column($overdueTrend, 'count')) > 0;
$gam = gamification_stats($userId);
$xpByWeek = $gam['enabled'] ? gamification_xp_by_week($userId, 8) : [];
$xpHasData = $xpByWeek && array_sum(array_column($xpByWeek, 'xp')) > 0;

$pageTitle = 'Progress';
$currentPage = 'reports';
$pageScripts = ['reports.js'];
require __DIR__ . '/includes/header.php';
?>

<?php if ($gam['enabled']): ?>
<section class="card momentum-card" aria-labelledby="progress-momentum-heading">
  <div class="card-head">
    <h2 id="progress-momentum-heading"><span data-lucide="flame" aria-hidden="true"></span> Momentum &amp; achievements</h2>
    <span class="chip-count"><span data-lucide="award" aria-hidden="true"></span> Level <?= (int) $gam['level'] ?> &middot; <?= (int) $gam['total_xp'] ?> XP</span>
  </div>
  <div class="momentum-row">
    <div class="momentum-stat">
      <span class="momentum-value"><?= (int) $gam['week_completed'] ?>/<?= (int) $gam['weekly_goal'] ?></span>
      <span class="momentum-label">This week</span>
    </div>
    <div class="momentum-stat">
      <span class="momentum-value"><?= (int) $gam['current_streak'] ?></span>
      <span class="momentum-label">Current streak</span>
    </div>
    <div class="momentum-stat">
      <span class="momentum-value"><?= (int) $gam['longest_streak'] ?></span>
      <span class="momentum-label">Longest streak</span>
    </div>
    <div class="momentum-stat">
      <span class="momentum-value"><?= (int) round($focusTotals['seconds'] / 60) ?>m</span>
      <span class="momentum-label">Focus time</span>
    </div>
  </div>
  <?php $goalPct = (int) min(100, round((int) $gam['week_completed'] * 100 / max(1, (int) $gam['weekly_goal']))); ?>
  <div class="goal-progress" role="img"
       aria-label="<?= (int) $gam['week_completed'] ?> of <?= (int) $gam['weekly_goal'] ?> weekly tasks done">
    <div class="goal-progress-bar" style="width: <?= $goalPct ?>%"></div>
  </div>
  <ul class="badge-grid" aria-label="Achievement badges">
    <?php foreach ($gam['badges'] as $badge): ?>
    <li class="badge-tile<?= $badge['earned'] ? ' is-earned' : '' ?>">
      <span data-lucide="<?= e($badge['earned'] ? $badge['icon'] : 'lock') ?>" aria-hidden="true"></span>
      <span class="badge-tile-label"><?= e($badge['label']) ?></span>
    </li>
    <?php endforeach; ?>
  </ul>
  <p class="field-help"><?= (int) $gam['badges_earned'] ?> of <?= (int) $gam['badges_total'] ?> badges earned.</p>
</section>
<?php endif; ?>

<section class="stat-grid" aria-label="Report summary">
  <div class="stat-card stat-done">
    <span class="stat-icon"><span data-lucide="check-circle-2" aria-hidden="true"></span></span>
    <span class="stat-value"><?= (int) $stats['completed'] ?></span>
    <span class="stat-label">Tasks completed</span>
  </div>
  <div class="stat-card stat-completion">
    <span class="stat-icon"><span data-lucide="trending-up" aria-hidden="true"></span></span>
    <span class="stat-value"><?= (int) $stats['percent'] ?>%</span>
    <span class="stat-label">Completion rate</span>
  </div>
  <div class="stat-card stat-overdue">
    <span class="stat-icon"><span data-lucide="alert-triangle" aria-hidden="true"></span></span>
    <span class="stat-value"><?= (int) $stats['overdue'] ?></span>
    <span class="stat-label">Overdue tasks</span>
  </div>
  <div class="stat-card stat-total">
    <span class="stat-icon"><span data-lucide="layers" aria-hidden="true"></span></span>
    <span class="stat-value"><?= (int) $stats['total'] ?></span>
    <span class="stat-label">Total tasks</span>
  </div>
  <div class="stat-card stat-completion">
    <span class="stat-icon"><span data-lucide="calendar-check" aria-hidden="true"></span></span>
    <span class="stat-value"><?= (int) $ontime['percent'] ?>%</span>
    <span class="stat-label">On-time rate</span>
  </div>
  <div class="stat-card stat-progress">
    <span class="stat-icon"><span data-lucide="timer" aria-hidden="true"></span></span>
    <span class="stat-value"><?= (int) round($focusTotals['seconds'] / 60) ?>m</span>
    <span class="stat-label">Focused time</span>
  </div>
</section>

<?php if ($stats['total'] === 0): ?>
<section class="card">
  <div class="empty-state">
    <span data-lucide="bar-chart-3" aria-hidden="true"></span>
    <h3>No data to report yet</h3>
    <p>Create some tasks and your productivity charts will appear here.</p>
    <a class="btn btn-primary" href="tasks.php">Create a task</a>
  </div>
</section>
<?php else: ?>

<div class="two-col">
  <section class="card" aria-labelledby="pend-heading">
    <h2 id="pend-heading">Pending vs completed</h2>
    <div class="chart-wrap">
      <canvas id="pending-chart" height="200"
              data-chart='<?= e(json_encode([
                  'labels' => ['Pending', 'In progress', 'Completed'],
                  'values' => [$stats['pending'], $stats['in_progress'], $stats['completed']],
              ], JSON_HEX_TAG | JSON_HEX_AMP)) ?>'
              aria-label="Doughnut chart of pending versus completed tasks" role="img"></canvas>
      <noscript>
        <table class="chart-table">
          <thead><tr><th scope="col">Status</th><th scope="col">Tasks</th></tr></thead>
          <tbody>
            <tr><td>Pending</td><td><?= (int) $stats['pending'] ?></td></tr>
            <tr><td>In progress</td><td><?= (int) $stats['in_progress'] ?></td></tr>
            <tr><td>Completed</td><td><?= (int) $stats['completed'] ?></td></tr>
          </tbody>
        </table>
      </noscript>
    </div>
  </section>

  <section class="card" aria-labelledby="cat-heading">
    <h2 id="cat-heading">Tasks by subject</h2>
    <div class="chart-wrap">
      <canvas id="category-chart" height="200"
              data-chart='<?= e(json_encode([
                  'labels' => array_column($breakdown, 'category'),
                  'values' => array_map('intval', array_column($breakdown, 'total')),
              ], JSON_HEX_TAG | JSON_HEX_AMP)) ?>'
              aria-label="Doughnut chart of tasks by subject" role="img"></canvas>
      <noscript>
        <table class="chart-table">
          <thead><tr><th scope="col">Subject</th><th scope="col">Tasks</th></tr></thead>
          <tbody>
            <?php foreach ($breakdown as $row): ?>
            <tr><td><?= e($row['category']) ?></td><td><?= (int) $row['total'] ?></td></tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </noscript>
    </div>
  </section>
</div>

<section class="card" aria-labelledby="weekly-heading">
  <h2 id="weekly-heading">Tasks completed per week</h2>
  <p class="muted">Last 8 weeks. A completed task counts in the week it was marked completed.</p>
  <div class="chart-wrap">
    <canvas id="weekly-report-chart" height="120"
            data-chart='<?= e(json_encode(['labels' => array_column($weekly, 'label'), 'values' => array_column($weekly, 'count')], JSON_HEX_TAG | JSON_HEX_AMP)) ?>'
            aria-label="Bar chart of tasks completed per week" role="img"></canvas>
    <noscript>
      <table class="chart-table">
        <thead><tr><th scope="col">Week</th><th scope="col">Completed</th></tr></thead>
        <tbody>
          <?php foreach ($weekly as $week): ?>
          <tr><td><?= e($week['label']) ?></td><td><?= (int) $week['count'] ?></td></tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </noscript>
  </div>
</section>

<div class="two-col">
  <section class="card" aria-labelledby="focus-rhythm-heading">
    <div class="card-head">
      <h2 id="focus-rhythm-heading">Focus rhythm</h2>
      <span class="chip-count">Last 28 days</span>
    </div>
    <p class="muted">
      Minutes of focused work by weekday.
      <?php if ($productiveDay !== null): ?>
      You focus most on <strong><?= e($productiveDay['label']) ?></strong>.
      <?php else: ?>
      Start a "Just 5 Minutes" timer to build this picture.
      <?php endif; ?>
    </p>
    <div class="chart-wrap">
      <canvas id="focus-chart" height="200"
              data-chart='<?= e(json_encode([
                  'labels' => array_column($focusByDay, 'label'),
                  'values' => array_column($focusByDay, 'minutes'),
              ], JSON_HEX_TAG | JSON_HEX_AMP)) ?>'
              aria-label="Bar chart of focus minutes by weekday" role="img"></canvas>
      <noscript>
        <table class="chart-table">
          <thead><tr><th scope="col">Day</th><th scope="col">Focus minutes</th></tr></thead>
          <tbody>
            <?php foreach ($focusByDay as $day): ?>
            <tr><td><?= e($day['label']) ?></td><td><?= (int) $day['minutes'] ?></td></tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </noscript>
    </div>
  </section>

  <section class="card" aria-labelledby="ontime-heading">
    <h2 id="ontime-heading">On-time completions</h2>
    <p class="muted">Out of <?= (int) $ontime['total'] ?> dated tasks you finished:</p>
    <ul class="mini-list">
      <li><strong><?= (int) $ontime['ontime'] ?></strong> finished on or before the deadline</li>
      <li><strong><?= (int) $ontime['late'] ?></strong> finished after the deadline</li>
    </ul>
    <?php if ((int) $ontime['total'] === 0): ?>
    <p class="field-help">Completion dates are recorded from now on, so this fills in as you work.</p>
    <?php endif; ?>
  </section>
</div>

<?php if ($overdueTrendHasData): ?>
<section class="card chart-card" aria-labelledby="overdue-trend-heading">
  <h2 id="overdue-trend-heading">Overdue trend</h2>
  <p class="muted">Tasks that went past their first recorded deadline, by the week that deadline was due.
    A falling line means fewer things are slipping.</p>
  <div class="chart-wrap">
    <canvas id="overdue-chart" height="120"
            data-chart='<?= e(json_encode(['labels' => array_column($overdueTrend, 'label'), 'values' => array_column($overdueTrend, 'count')], JSON_HEX_TAG | JSON_HEX_AMP)) ?>'
            aria-label="Line chart of tasks that slipped past their deadline each week" role="img"></canvas>
    <noscript>
      <table class="chart-table">
        <thead><tr><th scope="col">Week</th><th scope="col">Slipped</th></tr></thead>
        <tbody>
          <?php foreach ($overdueTrend as $row): ?>
          <tr><td><?= e($row['label']) ?></td><td><?= (int) $row['count'] ?></td></tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </noscript>
  </div>
</section>
<?php endif; ?>

<?php if ($xpHasData): ?>
<section class="card chart-card" aria-labelledby="xp-heading">
  <h2 id="xp-heading">XP over time</h2>
  <p class="muted">XP you earned each week from completions, early finishes and focus sessions.</p>
  <div class="chart-wrap">
    <canvas id="xp-chart" height="120"
            data-chart='<?= e(json_encode(['labels' => array_column($xpByWeek, 'label'), 'values' => array_column($xpByWeek, 'xp')], JSON_HEX_TAG | JSON_HEX_AMP)) ?>'
            aria-label="Line chart of XP earned per week" role="img"></canvas>
    <noscript>
      <table class="chart-table">
        <thead><tr><th scope="col">Week</th><th scope="col">XP</th></tr></thead>
        <tbody>
          <?php foreach ($xpByWeek as $row): ?>
          <tr><td><?= e($row['label']) ?></td><td><?= (int) $row['xp'] ?></td></tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </noscript>
  </div>
</section>
<?php endif; ?>

<?php endif; ?>

<?php require __DIR__ . '/includes/footer.php'; ?>
