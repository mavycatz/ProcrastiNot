<?php
/**
 * ProcrastiNot - dashboard.
 *
 * Ordered on purpose so the answer to "What should I do next?" is always at
 * the top: greeting + quick add, a compact Daily Focus panel, two primary
 * statistics, the single recommended task, upcoming deadlines, then compact
 * charts and (collapsed) momentum.
 */
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/tasks.php';
require_once __DIR__ . '/includes/priority.php';
require_once __DIR__ . '/includes/focus.php';
require_once __DIR__ . '/includes/gamification.php';
require_once __DIR__ . '/includes/comeback.php';

$user = require_login();
$userId = (int) $user['id'];

$stats        = task_stats($userId);
$focus        = task_focus($userId, 3);
$upcoming     = task_upcoming($userId, 4);
$weekly       = task_weekly_completions($userId, 8);
$weekDay      = task_week_daily_completions($userId);
$recommended  = priority_rank_tasks($userId, 5);
$nextTask     = $recommended[0] ?? null;
$focusTotals  = focus_session_totals($userId);
$gam          = gamification_stats($userId);
$comeback     = comeback_progress($userId);

$totalTasks = (int) $stats['total'];
$hasTasks   = $totalTasks > 0;

// Compact, data-driven focus line.
if (!$hasTasks) {
    $focusMessage = 'Add your first task and we will help you start.';
} elseif ((int) $stats['completed'] === $totalTasks) {
    $focusMessage = 'Look at you, getting things done!';
} elseif ((int) $stats['overdue'] > 0) {
    $focusMessage = 'You have ' . (int) $stats['overdue'] . ' overdue task'
        . ((int) $stats['overdue'] === 1 ? '' : 's') . '. Start with the oldest.';
} elseif ((int) $stats['due_today'] > 0) {
    $focusMessage = (int) $stats['due_today'] . ' due today. One task at a time.';
} elseif (count($focus) === 0) {
    $focusMessage = 'All clear. Want to work ahead?';
} else {
    $focusMessage = count($focus) . ' task' . (count($focus) === 1 ? '' : 's') . ' on your plate today.';
}

$hour = (int) date('G');
$part = $hour < 12 ? 'Good morning' : ($hour < 18 ? 'Good afternoon' : 'Good evening');
$firstName = explode(' ', trim((string) $user['name']))[0];
$greeting = $user['greeting'] !== null && $user['greeting'] !== ''
    ? $user['greeting']
    : $part . ', ' . $firstName;
$today = date('l, F j, Y');

$weekLabels = array_column($weekDay, 'label');
$weekValues = array_column($weekDay, 'count');
$weekHasData = array_sum($weekValues) > 0;
$statusLabels = ['Pending', 'In Progress', 'Completed'];
$statusValues = [(int) $stats['pending'], (int) $stats['in_progress'], (int) $stats['completed']];
$activeWeeks = count(array_filter($weekly, static fn($w) => (int) $w['count'] > 0));
$showTrend = $activeWeeks >= 2;

$pageTitle = 'Home';
$currentPage = 'dashboard';
$pageScripts = ['dashboard.js'];
require __DIR__ . '/includes/header.php';
?>

<!-- 1. Greeting + quick add -->
<section class="greeting-block">
  <div>
    <h2 class="greeting"><?= e($greeting) ?></h2>
    <p class="muted"><?= e($today) ?></p>
  </div>
  <a class="btn btn-primary btn-small" href="tasks.php?action=new">
    <span data-lucide="plus" aria-hidden="true"></span> Add task
  </a>
</section>

<!-- 2. Compact Daily Focus -->
<section class="focus-panel" aria-labelledby="focus-panel-heading">
  <div class="focus-panel-head">
    <span class="focus-kicker"><span data-lucide="target" aria-hidden="true"></span> Daily focus</span>
    <span class="focus-percent"><?= (int) $stats['percent'] ?>% done</span>
  </div>
  <h2 id="focus-panel-heading"><?= e($focusMessage) ?></h2>
  <div class="focus-bar" role="img" aria-label="<?= (int) $stats['percent'] ?>% of tasks completed">
    <div class="focus-bar-fill" style="width: <?= (int) $stats['percent'] ?>%"></div>
  </div>
  <?php if ($nextTask !== null): ?>
  <div class="focus-panel-actions">
    <a class="btn btn-primary btn-small" href="focus.php?task=<?= (int) $nextTask['id'] ?>">
      <span data-lucide="timer" aria-hidden="true"></span> Start 5 minutes
    </a>
    <a class="btn btn-ghost btn-small" href="tasks.php?sort=smart">See recommendations</a>
  </div>
  <?php else: ?>
  <div class="focus-panel-actions">
    <a class="btn btn-primary btn-small" href="tasks.php?action=new">
      <span data-lucide="plus" aria-hidden="true"></span> Add your first task
    </a>
  </div>
  <?php endif; ?>
</section>

<!-- 3. Two primary statistics (+ collapsible extras) -->
<div class="stat-toolbar">
  <h2 class="section-heading">Your tasks</h2>
  <button type="button" class="stat-more-toggle" data-stat-toggle aria-expanded="false" aria-controls="stat-grid">
    <span data-stat-toggle-label>Show all</span>
    <span data-lucide="chevron-down" aria-hidden="true"></span>
  </button>
</div>
<section class="stat-grid" id="stat-grid" aria-label="Task statistics">
  <a class="stat-card stat-todo" href="tasks.php?status=pending">
    <span class="stat-icon"><span data-lucide="list-todo" aria-hidden="true"></span></span>
    <span class="stat-value"><?= (int) $stats['pending'] ?></span>
    <span class="stat-label">To Do</span>
  </a>
  <a class="stat-card stat-done" href="tasks.php?status=completed">
    <span class="stat-icon"><span data-lucide="check-circle-2" aria-hidden="true"></span></span>
    <span class="stat-value"><?= (int) $stats['completed'] ?></span>
    <span class="stat-label">Completed</span>
  </a>
  <a class="stat-card stat-total stat-extra" href="tasks.php">
    <span class="stat-icon"><span data-lucide="layers" aria-hidden="true"></span></span>
    <span class="stat-value"><?= $totalTasks ?></span>
    <span class="stat-label">Total Tasks</span>
  </a>
  <a class="stat-card stat-progress stat-extra" href="tasks.php?status=in_progress">
    <span class="stat-icon"><span data-lucide="loader" aria-hidden="true"></span></span>
    <span class="stat-value"><?= (int) $stats['in_progress'] ?></span>
    <span class="stat-label">In Progress</span>
  </a>
</section>

<!-- Contextual badges, only when they matter -->
<?php if ((int) $stats['overdue'] > 0 || (int) $stats['due_today'] > 0): ?>
<div class="context-chips" aria-label="Deadline alerts">
  <?php if ((int) $stats['overdue'] > 0): ?>
  <a class="context-chip is-alert" href="tasks.php?view=overdue">
    <span data-lucide="alert-triangle" aria-hidden="true"></span>
    <?= (int) $stats['overdue'] ?> overdue
  </a>
  <?php endif; ?>
  <?php if ((int) $stats['due_today'] > 0): ?>
  <a class="context-chip is-warn" href="tasks.php?due=today">
    <span data-lucide="calendar-clock" aria-hidden="true"></span>
    <?= (int) $stats['due_today'] ?> due today
  </a>
  <?php endif; ?>
  <?php if ((int) $stats['overdue'] > 0): ?>
  <a class="context-chip is-soft" href="comeback.php">
    <span data-lucide="life-buoy" aria-hidden="true"></span>
    Go to Comeback Mode
  </a>
  <?php endif; ?>
</div>
<?php endif; ?>

<!-- 4. Recommended next task -->
<section class="card recommend-card" aria-labelledby="recommend-heading">
  <div class="card-head">
    <h2 id="recommend-heading"><span data-lucide="sparkles" aria-hidden="true"></span> Recommended next</h2>
    <?php if (count($recommended) > 1): ?>
    <a class="chip-count chip-link" href="tasks.php?sort=smart">View all</a>
    <?php endif; ?>
  </div>
  <?php if ($nextTask === null): ?>
  <div class="empty-state empty-state-compact">
    <p>Nothing to rank yet. Add a task and the Smart Priority Engine will pick your starting point.</p>
    <a class="btn btn-secondary btn-small" href="tasks.php?action=new">Add a task</a>
  </div>
  <?php else: ?>
  <div class="recommend-feature">
    <div class="recommend-feature-main">
      <a class="recommend-feature-title" href="tasks.php?task=<?= (int) $nextTask['id'] ?>"><?= e($nextTask['title']) ?></a>
      <div class="recommend-feature-meta">
        <span class="badge <?= e(priority_label_class($nextTask['priority_info']['label'])) ?>">
          <?= e($nextTask['priority_info']['label']) ?>
        </span>
        <span class="muted">
          <?php $d = $nextTask['priority_info']['days_left']; ?>
          <?php if ($d === null): ?>
          No deadline
          <?php elseif ($d < 0): ?>
          <?= e(abs($d) . ' day' . (abs($d) === 1 ? '' : 's') . ' overdue') ?>
          <?php elseif ($d === 0): ?>
          Due today
          <?php else: ?>
          Due in <?= (int) $d ?> day<?= $d === 1 ? '' : 's' ?>
          <?php endif; ?>
          &middot; Score <?= e((string) $nextTask['priority_info']['score']) ?>
        </span>
      </div>
      <p class="recommend-feature-why"><?= e($nextTask['priority_info']['explanation']) ?></p>
    </div>
    <a class="btn btn-primary btn-small" href="focus.php?task=<?= (int) $nextTask['id'] ?>">
      <span data-lucide="play" aria-hidden="true"></span> Start
    </a>
  </div>
  <?php endif; ?>
</section>

<!-- 5. Upcoming deadlines -->
<section class="card" aria-labelledby="upcoming-heading">
  <div class="card-head">
    <h2 id="upcoming-heading">Upcoming deadlines</h2>
    <a class="chip-count chip-link" href="tasks.php?view=upcoming">See all</a>
  </div>
  <?php if (count($upcoming) === 0): ?>
  <div class="empty-state empty-state-compact">
    <p>No upcoming deadlines. Enjoy the calm, or add a dated task.</p>
  </div>
  <?php else: ?>
  <ul class="mini-list">
    <?php foreach ($upcoming as $task): ?>
    <li>
      <a href="tasks.php?task=<?= (int) $task['id'] ?>"><?= e($task['title']) ?></a>
      <span class="badge badge-<?= e($task['priority']) ?>"><?= e(priority_label($task['priority'])) ?></span>
      <span class="muted">Due <?= e(date('M j', strtotime($task['due_date']))) ?></span>
    </li>
    <?php endforeach; ?>
  </ul>
  <?php endif; ?>
</section>

<!-- 6. Compact charts -->
<section class="chart-grid" aria-label="Productivity charts">
  <div class="card chart-card">
    <div class="card-head">
      <h2 id="week-chart-heading">This week</h2>
      <span class="chip-count"><?= (int) array_sum($weekValues) ?> completed</span>
    </div>
    <?php if ($weekHasData): ?>
    <div class="chart-wrap chart-wrap-sm">
      <canvas id="week-chart"
              data-chart='<?= e(json_encode(['labels' => $weekLabels, 'values' => $weekValues], JSON_HEX_TAG | JSON_HEX_AMP)) ?>'
              aria-label="Bar chart of tasks completed each day this week" role="img"></canvas>
      <noscript>
        <table class="chart-table">
          <caption class="visually-hidden">Tasks completed each day this week</caption>
          <thead><tr><th scope="col">Day</th><th scope="col">Completed</th></tr></thead>
          <tbody>
            <?php foreach ($weekDay as $day): ?>
            <tr><td><?= e($day['label']) ?></td><td><?= (int) $day['count'] ?></td></tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </noscript>
    </div>
    <?php else: ?>
    <div class="chart-empty">
      <span data-lucide="calendar-check" aria-hidden="true"></span>
      <p>No tasks completed yet this week. Finish one to see the bars grow.</p>
    </div>
    <?php endif; ?>
  </div>

  <div class="card chart-card">
    <div class="card-head">
      <h2 id="status-chart-heading">Status</h2>
    </div>
    <?php if ($hasTasks): ?>
    <div class="chart-wrap chart-wrap-sm">
      <canvas id="status-chart"
              data-chart='<?= e(json_encode(['labels' => $statusLabels, 'values' => $statusValues], JSON_HEX_TAG | JSON_HEX_AMP)) ?>'
              aria-label="Doughnut chart of tasks by status" role="img"></canvas>
      <noscript>
        <table class="chart-table">
          <caption class="visually-hidden">Tasks by status</caption>
          <thead><tr><th scope="col">Status</th><th scope="col">Tasks</th></tr></thead>
          <tbody>
            <tr><td>Pending</td><td><?= (int) $stats['pending'] ?></td></tr>
            <tr><td>In Progress</td><td><?= (int) $stats['in_progress'] ?></td></tr>
            <tr><td>Completed</td><td><?= (int) $stats['completed'] ?></td></tr>
          </tbody>
        </table>
      </noscript>
    </div>
    <?php else: ?>
    <div class="chart-empty">
      <span data-lucide="pie-chart" aria-hidden="true"></span>
      <p>Add a task and your status mix will appear here.</p>
    </div>
    <?php endif; ?>
  </div>
</section>

<?php if ($showTrend): ?>
<details class="card expand-card">
  <summary>
    <span class="expand-title"><span data-lucide="activity" aria-hidden="true"></span> Productivity trend</span>
    <span class="expand-hint">Last 8 weeks</span>
  </summary>
  <div class="chart-wrap chart-wrap-sm">
    <canvas id="trend-chart"
            data-chart='<?= e(json_encode(['labels' => array_column($weekly, 'label'), 'values' => array_column($weekly, 'count')], JSON_HEX_TAG | JSON_HEX_AMP)) ?>'
            aria-label="Line chart of tasks completed per week" role="img"></canvas>
    <noscript>
      <table class="chart-table">
        <caption class="visually-hidden">Tasks completed per week</caption>
        <thead><tr><th scope="col">Week</th><th scope="col">Completed</th></tr></thead>
        <tbody>
          <?php foreach ($weekly as $week): ?>
          <tr><td><?= e($week['label']) ?></td><td><?= (int) $week['count'] ?></td></tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </noscript>
  </div>
</details>
<?php endif; ?>

<!-- 7. Momentum + achievements, collapsed by default -->
<details class="card expand-card">
  <summary>
    <span class="expand-title"><span data-lucide="flame" aria-hidden="true"></span> Momentum &amp; achievements</span>
    <span class="expand-hint">Weekly goal, streak, badges</span>
  </summary>
  <div class="momentum-body">
    <div class="momentum-row">
      <div class="momentum-stat">
        <span class="momentum-value"><?= (int) $gam['week_completed'] ?>/<?= (int) $gam['weekly_goal'] ?></span>
        <span class="momentum-label">Weekly tasks</span>
      </div>
      <div class="momentum-stat">
        <span class="momentum-value"><?= (int) $gam['current_streak'] ?></span>
        <span class="momentum-label">Day streak</span>
      </div>
      <div class="momentum-stat">
        <span class="momentum-value"><?= (int) round($focusTotals['week_seconds'] / 60) ?>m</span>
        <span class="momentum-label">Focused</span>
      </div>
      <?php if ($gam['enabled']): ?>
      <div class="momentum-stat">
        <span class="momentum-value"><?= (int) $gam['level'] ?></span>
        <span class="momentum-label">Level</span>
      </div>
      <?php endif; ?>
    </div>
    <?php $goalPct = (int) min(100, round((int) $gam['week_completed'] * 100 / max(1, (int) $gam['weekly_goal']))); ?>
    <div class="goal-progress" role="img"
         aria-label="<?= (int) $gam['week_completed'] ?> of <?= (int) $gam['weekly_goal'] ?> weekly tasks done">
      <div class="goal-progress-bar" style="width: <?= $goalPct ?>%"></div>
    </div>
    <?php if ($gam['enabled']): ?>
    <ul class="badge-grid" aria-label="Badges">
      <?php foreach ($gam['badges'] as $key => $badge): ?>
      <li class="badge-tile<?= $badge['earned'] ? ' is-earned' : '' ?>">
        <span data-lucide="<?= e($badge['earned'] ? $badge['icon'] : 'lock') ?>" aria-hidden="true"></span>
        <span class="badge-tile-label"><?= e($badge['label']) ?></span>
      </li>
      <?php endforeach; ?>
    </ul>
    <p class="field-help"><?= (int) $gam['badges_earned'] ?> of <?= (int) $gam['badges_total'] ?> badges earned.
      <a href="reports.php">See all on Progress</a></p>
    <?php else: ?>
    <p class="field-help">XP and badges are switched off. Turn them on in
      <a href="settings.php">Settings</a> if you would like some extra motivation.</p>
    <?php endif; ?>
  </div>
</details>

<?php require __DIR__ . '/includes/footer.php'; ?>
