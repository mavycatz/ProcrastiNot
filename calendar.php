<?php
/**
 * ProcrastiNot - monthly deadline calendar with per-date task list.
 */
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/tasks.php';

$user = require_login();
$userId = (int) $user['id'];

$now = new DateTimeImmutable('today');
$year  = isset($_GET['y']) ? (int) $_GET['y'] : (int) $now->format('Y');
$month = isset($_GET['m']) ? (int) $_GET['m'] : (int) $now->format('n');
if ($year < 2000 || $year > 2100) {
    $year = (int) $now->format('Y');
}
if ($month < 1 || $month > 12) {
    $month = (int) $now->format('n');
}
$monthName = date('F Y', mktime(0, 0, 0, $month, 1, $year));

$prev = (new DateTimeImmutable(sprintf('%04d-%02d-01', $year, $month)))->modify('-1 month');
$next = (new DateTimeImmutable(sprintf('%04d-%02d-01', $year, $month)))->modify('+1 month');

// Selected date (?y=&m=&d=) - only valid inside the displayed month.
$selected = null;
if (isset($_GET['d'])) {
    $day = (int) $_GET['d'];
    $candidate = DateTimeImmutable::createFromFormat('!Y-n-j', "$year-$month-$day");
    if ($candidate instanceof DateTimeImmutable
        && $candidate->format('Y-n-j') === "$year-$month-$day") {
        $selected = $candidate;
    }
}

$dayData = task_counts_by_day($userId, $year, $month);
$monthTasks = task_month_list($userId, $year, $month);
$selectedTasks = $selected !== null
    ? task_by_date($userId, $selected->format('Y-m-d'))
    : [];

// Build the Mon-Sun grid.
$firstOfMonth = new DateTimeImmutable(sprintf('%04d-%02d-01', $year, $month));
$daysInMonth = (int) $firstOfMonth->format('t');
$leadBlanks = ((int) $firstOfMonth->format('N') - 1); // N=1 Monday
$todayStr = $now->format('Y-m-d');

$pageTitle = 'Calendar';
$currentPage = 'calendar';
$pageScripts = ['calendar.js'];
require __DIR__ . '/includes/header.php';
?>

<section class="card" aria-labelledby="cal-heading">
  <div class="cal-head">
    <h2 id="cal-heading"><?= e($monthName) ?></h2>
    <div class="cal-nav">
      <a class="btn btn-secondary btn-small"
         href="calendar.php?y=<?= (int) $prev->format('Y') ?>&m=<?= (int) $prev->format('n') ?>"
         aria-label="Previous month">&larr; <?= e($prev->format('M')) ?></a>
      <a class="btn btn-secondary btn-small" href="calendar.php">Today</a>
      <a class="btn btn-secondary btn-small"
         href="calendar.php?y=<?= (int) $next->format('Y') ?>&m=<?= (int) $next->format('n') ?>"
         aria-label="Next month"><?= e($next->format('M')) ?> &rarr;</a>
    </div>
  </div>

  <!-- Month grid (desktop / tablet) -->
  <table class="cal-grid" aria-label="Task deadlines for <?= e($monthName) ?>">
    <thead>
      <tr>
        <th scope="col" abbr="Monday">Mon</th>
        <th scope="col" abbr="Tuesday">Tue</th>
        <th scope="col" abbr="Wednesday">Wed</th>
        <th scope="col" abbr="Thursday">Thu</th>
        <th scope="col" abbr="Friday">Fri</th>
        <th scope="col" abbr="Saturday">Sat</th>
        <th scope="col" abbr="Sunday">Sun</th>
      </tr>
    </thead>
    <tbody>
      <?php
      $cell = 0;
      $rowOpen = false;
      for ($i = 0; $i < $leadBlanks; $i++):
          if (!$rowOpen) { echo '<tr>'; $rowOpen = true; }
          echo '<td class="cal-cell is-blank"></td>';
          $cell++;
      endfor;
      for ($day = 1; $day <= $daysInMonth; $day++):
          if ($cell % 7 === 0) {
              if ($rowOpen) { echo '</tr>'; }
              echo '<tr>';
              $rowOpen = true;
          }
          $dateStr = sprintf('%04d-%02d-%02d', $year, $month, $day);
          $info = $dayData[$day] ?? null;
          $isToday = $dateStr === $todayStr;
          $isSelected = $selected !== null && $selected->format('Y-m-d') === $dateStr;
          $classes = ['cal-cell'];
          if ($isToday) { $classes[] = 'is-today'; }
          if ($isSelected) { $classes[] = 'is-selected'; }
          if ($info) { $classes[] = 'has-tasks'; }
          if ($info && $info['has_overdue']) { $classes[] = 'has-overdue'; }
          if ($info && $info['has_completed']) { $classes[] = 'has-completed'; }
          $count = $info['count'] ?? 0;
          $label = $day . ' ' . date('F', mktime(0, 0, 0, $month, 1, $year));
          if ($count > 0) {
              $label .= ", $count task" . ($count === 1 ? '' : 's') . ' due';
          }
          if ($isToday) { $label .= ' (today)'; }
          ?>
      <td class="<?= e(implode(' ', $classes)) ?>"
          data-day="<?= $day ?>" data-count="<?= $count ?>"
          <?= ($info && $info['has_overdue']) ? 'data-overdue="1"' : '' ?>
          <?= ($info && $info['has_completed']) ? 'data-completed="1"' : '' ?>>
        <a class="cal-day<?= $isSelected ? ' is-selected' : '' ?>"
           href="calendar.php?y=<?= $year ?>&m=<?= $month ?>&d=<?= $day ?>"
           aria-label="<?= e($label) ?>"
           <?= $isSelected ? 'aria-current="date"' : '' ?>>
          <span class="cal-day-num"><?= $day ?></span>
          <?php if ($count > 0): ?>
          <span class="cal-dots" aria-hidden="true">
            <span class="cal-dot"></span><?= $count > 1 ? '<span class="cal-dot"></span>' : '' ?>
          </span>
          <?php endif; ?>
        </a>
      </td>
      <?php
          $cell++;
      endfor;
      while ($cell % 7 !== 0):
          echo '<td class="cal-cell is-blank"></td>';
          $cell++;
      endwhile;
      if ($rowOpen) { echo '</tr>'; }
      ?>
    </tbody>
  </table>

  <!-- Selected day tasks -->
  <?php if ($selected !== null): ?>
  <section class="cal-selected" aria-live="polite" aria-labelledby="selected-heading">
    <h3 id="selected-heading">Tasks due <?= e($selected->format('F j, Y')) ?></h3>
    <?php if (count($selectedTasks) === 0): ?>
    <div class="empty-state empty-state-compact">
      <p>Nothing is due on this day.</p>
    </div>
    <?php else: ?>
    <ul class="mini-list">
      <?php foreach ($selectedTasks as $task): ?>
      <li>
        <a href="tasks.php?task=<?= (int) $task['id'] ?>"><?= e($task['title']) ?></a>
        <span class="badge badge-<?= e($task['priority']) ?>"><?= e(priority_label($task['priority'])) ?></span>
        <span class="badge badge-<?= e($task['status']) ?>"><?= e(status_label($task['status'])) ?></span>
        <?php if (task_is_overdue($task['due_date'], $task['status'])): ?>
        <span class="badge badge-overdue">Overdue</span>
        <?php endif; ?>
      </li>
      <?php endforeach; ?>
    </ul>
    <?php endif; ?>
  </section>
  <?php endif; ?>
</section>

<!-- Month deadline list (mobile alternative) -->
<section class="card cal-list-card" aria-labelledby="cal-list-heading">
  <h3 id="cal-list-heading">Deadlines this month</h3>
  <?php if (count($monthTasks) === 0): ?>
  <div class="empty-state empty-state-compact">
    <p>No deadlines in <?= e($monthName) ?>.</p>
  </div>
  <?php else: ?>
  <ul class="mini-list">
    <?php foreach ($monthTasks as $task): ?>
    <li>
      <span class="cal-list-date"><?= e(date('M j', strtotime($task['due_date']))) ?></span>
      <a href="tasks.php?task=<?= (int) $task['id'] ?>"><?= e($task['title']) ?></a>
      <span class="badge badge-<?= e($task['status']) ?>"><?= e(status_label($task['status'])) ?></span>
      <?php if (task_is_overdue($task['due_date'], $task['status'])): ?>
      <span class="badge badge-overdue">Overdue</span>
      <?php endif; ?>
    </li>
    <?php endforeach; ?>
  </ul>
  <?php endif; ?>
</section>

<?php require __DIR__ . '/includes/footer.php'; ?>
