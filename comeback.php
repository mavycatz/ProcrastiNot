<?php
/**
 * ProcrastiNot - Comeback Mode: a kind, non-judgemental recovery plan for
 * tasks that slipped. Deadlines only move when the student says so.
 */
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/tasks.php';
require_once __DIR__ . '/includes/priority.php';
require_once __DIR__ . '/includes/focus.php';
require_once __DIR__ . '/includes/gamification.php';
require_once __DIR__ . '/includes/comeback.php';

$user = require_login();
$userId = (int) $user['id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $action = (string) ($_POST['action'] ?? '');
    $taskId = (int) ($_POST['task_id'] ?? 0);

    if ($action === 'reschedule') {
        $newDue = trim((string) ($_POST['new_due_date'] ?? ''));
        $reason = (string) ($_POST['reason'] ?? '');
        if ($newDue === '' || !is_valid_date($newDue)) {
            flash('error', 'Please pick a real date for the new deadline.');
        } elseif (comeback_reschedule($userId, $taskId, $newDue, $reason)) {
            flash('success', 'Deadline moved to ' . date('M j, Y', strtotime($newDue)) . '. The original date is kept for your records.');
        } else {
            flash('error', 'That task was not found.');
        }
        redirect('comeback.php');
    }
}

$overdue = comeback_overdue_tasks($userId);
$atRisk  = comeback_at_risk_tasks($userId);
$progress = comeback_progress($userId);

// Suggested first actions (template-based) for each overdue task.
$actions = [];
foreach ($overdue as $task) {
    $actions[(int) $task['id']] = comeback_next_action($userId, $task, focus_suggest_steps($task));
}

$pageTitle = 'Comeback Mode';
$currentPage = 'comeback';
$pageScripts = ['tasks.js'];
require __DIR__ . '/includes/header.php';
?>

<section class="comeback-hero">
  <div class="comeback-hero-main">
    <span class="hero-kicker"><span data-lucide="life-buoy" aria-hidden="true"></span> Comeback Mode</span>
    <h2>Yesterday didn't go as planned. Let's make today easier.</h2>
    <p class="muted">Pick one task to restart. No streaks were lost, no points were taken away &mdash;
      you are just choosing where to begin.</p>
  </div>
  <div class="comeback-progress">
    <div class="comeback-progress-ring" role="img" style="--ring: <?= (int) $progress['percent'] ?>%"
         aria-label="<?= (int) $progress['percent'] ?>% of once-overdue tasks recovered">
      <span class="comeback-progress-value"><?= (int) $progress['percent'] ?>%</span>
    </div>
    <p class="muted">
      <?= (int) $progress['recovered'] ?> recovered &middot;
      <?= (int) $progress['overdue_now'] ?> still waiting
    </p>
  </div>
</section>

<?php if (count($overdue) === 0): ?>
<section class="card">
  <div class="empty-state">
    <span data-lucide="sun" aria-hidden="true"></span>
    <h3>Nothing is overdue</h3>
    <p>You're all caught up. If that changes, Comeback Mode will be right here.</p>
    <a class="btn btn-primary" href="tasks.php">Go to tasks</a>
  </div>
</section>
<?php else: ?>
<section class="card" aria-labelledby="restart-heading">
  <div class="card-head">
    <h2 id="restart-heading">Restart these first</h2>
    <span class="chip-count"><?= count($overdue) ?> to recover</span>
  </div>
  <ul class="comeback-list">
    <?php foreach ($overdue as $task): ?>
    <li class="comeback-item">
      <div class="comeback-item-head">
        <a class="recommend-title" href="tasks.php?task=<?= (int) $task['id'] ?>"><?= e($task['title']) ?></a>
        <span class="badge <?= e(priority_label_class($task['priority_info']['label'])) ?>"><?= e($task['priority_info']['label']) ?></span>
        <span class="badge badge-overdue">
          <?php $d = (int) $task['priority_info']['days_left']; ?>
          <?= $d === 0 ? 'Due today' : e(abs($d) . ' day' . (abs($d) === 1 ? '' : 's') . ' overdue') ?>
        </span>
      </div>
      <p class="comeback-why"><?= e($task['priority_info']['explanation']) ?></p>
      <p class="comeback-action">
        <span data-lucide="arrow-right-circle" aria-hidden="true"></span>
        Try this: <?= e($actions[(int) $task['id']]) ?>
      </p>
      <div class="comeback-item-actions">
        <a class="btn btn-primary btn-small" href="focus.php?task=<?= (int) $task['id'] ?>">
          <span data-lucide="timer" aria-hidden="true"></span> Just 5 Minutes
        </a>
        <button type="button" class="btn btn-secondary btn-small" data-comeback-toggle
                aria-expanded="false" aria-controls="reschedule-<?= (int) $task['id'] ?>">
          <span data-lucide="calendar-clock" aria-hidden="true"></span> Adjust deadline
        </button>
      </div>
      <form method="post" action="comeback.php" id="reschedule-<?= (int) $task['id'] ?>"
            class="comeback-reschedule" hidden>
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="reschedule">
        <input type="hidden" name="task_id" value="<?= (int) $task['id'] ?>">
        <div class="form-row">
          <div class="field">
            <label for="new-date-<?= (int) $task['id'] ?>">New deadline</label>
            <input type="date" id="new-date-<?= (int) $task['id'] ?>" name="new_due_date"
                   value="<?= e(date('Y-m-d', strtotime('+1 day'))) ?>" required>
          </div>
          <div class="field">
            <label for="reason-<?= (int) $task['id'] ?>">What got in the way? (optional)</label>
            <select id="reason-<?= (int) $task['id'] ?>" name="reason">
              <option value="Ran out of time">Ran out of time</option>
              <option value="The task was bigger than expected">The task was bigger than expected</option>
              <option value="I was unwell or had personal things">I was unwell or had personal things</option>
              <option value="I forgot about it">I forgot about it</option>
              <option value="Other">Other</option>
            </select>
          </div>
        </div>
        <p class="field-help">Your original deadline (<?= $task['original_due_date'] !== null ? e(date('M j, Y', strtotime($task['original_due_date']))) : 'none' ?>)
          stays in your history, so reports stay honest. Nothing changes until you confirm.</p>
        <div class="form-actions">
          <button type="submit" class="btn btn-primary btn-small">Move deadline</button>
        </div>
      </form>
    </li>
    <?php endforeach; ?>
  </ul>
</section>
<?php endif; ?>

<?php if (count($atRisk) > 0): ?>
<section class="card" aria-labelledby="risk-heading">
  <div class="card-head">
    <h2 id="risk-heading">At risk of slipping</h2>
    <span class="chip-count"><?= count($atRisk) ?></span>
  </div>
  <p class="muted">These are not late yet, but they are close and could use a small start today.</p>
  <ul class="mini-list">
    <?php foreach ($atRisk as $task): ?>
    <li>
      <a href="tasks.php?task=<?= (int) $task['id'] ?>"><?= e($task['title']) ?></a>
      <span class="badge <?= e(priority_label_class($task['priority_info']['label'])) ?>"><?= e($task['priority_info']['label']) ?></span>
      <span class="muted">
        <?php $d = (int) $task['priority_info']['days_left']; ?>
        <?= $d <= 0 ? 'Due today' : e('Due in ' . $d . ' day' . ($d === 1 ? '' : 's')) ?>
      </span>
      <a class="btn btn-ghost btn-small" href="focus.php?task=<?= (int) $task['id'] ?>">Start</a>
    </li>
    <?php endforeach; ?>
  </ul>
</section>
<?php endif; ?>

<?php
// Recent deadline changes across the student's tasks.
$stmt = db()->prepare(
    'SELECT h.*, t.title AS task_title
     FROM task_deadline_history h
     JOIN tasks t ON t.id = h.task_id
     WHERE h.user_id = ?
     ORDER BY h.changed_at DESC, h.id DESC
     LIMIT 8'
);
$stmt->execute([$userId]);
$history = $stmt->fetchAll();
?>
<?php if (count($history) > 0): ?>
<section class="card" aria-labelledby="history-heading">
  <h2 id="history-heading">Deadline changes</h2>
  <p class="muted">A transparent record of every deadline you have moved.</p>
  <ul class="mini-list">
    <?php foreach ($history as $h): ?>
    <li>
      <a href="tasks.php?task=<?= (int) $h['task_id'] ?>"><?= e($h['task_title']) ?></a>
      <span class="muted">
        <?= $h['old_due_date'] !== null ? e(date('M j', strtotime($h['old_due_date']))) : 'no date' ?>
        &rarr;
        <?= $h['new_due_date'] !== null ? e(date('M j', strtotime($h['new_due_date']))) : 'no date' ?>
      </span>
      <?php if ($h['reason'] !== null && $h['reason'] !== ''): ?>
      <span class="muted">&middot; <?= e($h['reason']) ?></span>
      <?php endif; ?>
      <span class="muted"><?= e(date('M j, g:i A', strtotime($h['changed_at']))) ?></span>
    </li>
    <?php endforeach; ?>
  </ul>
</section>
<?php endif; ?>

<?php require __DIR__ . '/includes/footer.php'; ?>
