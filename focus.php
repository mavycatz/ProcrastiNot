<?php
/**
 * ProcrastiNot - "Just 5 Minutes": start an overwhelming task with a short
 * focus session, optional step breakdown, and a reflective note.
 *
 * Every state change is an explicit student action. The timer NEVER marks a
 * task complete on its own.
 */
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/tasks.php';
require_once __DIR__ . '/includes/priority.php';
require_once __DIR__ . '/includes/focus.php';

$user = require_login();
$userId = (int) $user['id'];

// ---------------------------------------------------------------------
// POST actions (CSRF-checked, owner-scoped)
// ---------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $action = (string) ($_POST['action'] ?? '');
    $taskId = (int) ($_POST['task_id'] ?? 0);
    $task = task_find($userId, $taskId);
    if ($task === null) {
        flash('error', 'That task was not found.');
        redirect('tasks.php');
    }

    if ($action === 'focus_record') {
        $planned = (int) ($_POST['planned_minutes'] ?? 5);
        $seconds = (int) ($_POST['actual_seconds'] ?? 0);
        $note    = (string) ($_POST['note'] ?? '');
        $sessionId = focus_session_record($userId, $taskId, $planned, $seconds, $note);
        if ($sessionId > 0) {
            require_once __DIR__ . '/includes/gamification.php';
            gamification_reward_focus($userId, $taskId, $sessionId, $seconds);
            flash('success', 'Nice work - ' . focus_duration_label($seconds) . ' logged on this task.');
        } else {
            flash('error', 'Could not save that session.');
        }
        redirect('focus.php?task=' . $taskId);

    } elseif ($action === 'subtask_add') {
        $title = (string) ($_POST['subtask_title'] ?? '');
        if (subtask_add($userId, $taskId, $title) > 0) {
            flash('success', 'Step added.');
        } else {
            flash('error', 'Please enter a step title (1-200 characters).');
        }
        redirect('focus.php?task=' . $taskId);

    } elseif ($action === 'subtask_suggest') {
        $added = 0;
        foreach (focus_suggest_steps($task) as $step) {
            if (subtask_add($userId, $taskId, $step) > 0) {
                $added++;
            }
        }
        flash($added > 0 ? 'success' : 'error', $added > 0
            ? 'Added ' . $added . ' suggested steps.'
            : 'No steps could be added.');
        redirect('focus.php?task=' . $taskId);

    } elseif ($action === 'subtask_toggle') {
        $subtaskId = (int) ($_POST['subtask_id'] ?? 0);
        $done = (int) ($_POST['done'] ?? 0) === 1;
        subtask_set_done($userId, $subtaskId, $done);
        redirect('focus.php?task=' . $taskId);

    } elseif ($action === 'subtask_delete') {
        $subtaskId = (int) ($_POST['subtask_id'] ?? 0);
        subtask_delete($userId, $subtaskId);
        flash('success', 'Step removed.');
        redirect('focus.php?task=' . $taskId);

    } elseif ($action === 'status') {
        $status = (string) ($_POST['status'] ?? '');
        if (!is_valid_status($status)) {
            flash('error', 'Please choose a valid status.');
        } elseif (task_set_status($userId, $taskId, $status)) {
            // Award XP when the student completes the task from here.
            require_once __DIR__ . '/includes/gamification.php';
            gamification_on_status_change($userId, $task, $status);
            flash('success', 'Status updated to ' . status_label($status) . '.');
        } else {
            flash('error', 'That task was not found.');
        }
        redirect('focus.php?task=' . $taskId);
    }
}

// ---------------------------------------------------------------------
// View
// ---------------------------------------------------------------------
$taskId = (int) ($_GET['task'] ?? 0);
$task = task_find($userId, $taskId);
if ($task === null) {
    flash('error', 'That task was not found.');
    redirect('tasks.php');
}
if ($task['status'] !== 'completed') {
    $task = priority_annotate([$task], $userId)[0];
}

$subtasks = subtask_list($userId, $taskId);
$stats = subtask_stats($userId, $taskId);
$nextStep = subtask_next_open($userId, $taskId);
$suggestions = count($subtasks) === 0 ? focus_suggest_steps($task) : [];
$focusTotals = focus_session_totals($userId);
$recentSessions = focus_session_recent($userId, 3);

$pageTitle = 'Just 5 Minutes';
$currentPage = 'tasks';
$pageScripts = ['focus.js', 'tasks.js'];
require __DIR__ . '/includes/header.php';
?>

<section class="card focus-head" aria-labelledby="focus-title">
  <div class="card-head">
    <div>
      <span class="hero-kicker"><span data-lucide="timer" aria-hidden="true"></span> Just 5 Minutes</span>
      <h2 id="focus-title"><?= e($task['title']) ?></h2>
    </div>
    <div class="badges">
      <span class="badge badge-<?= e($task['priority']) ?>"><?= e(priority_label($task['priority'])) ?></span>
      <span class="badge badge-<?= e($task['status']) ?>"><?= e(status_label($task['status'])) ?></span>
      <?php if (!empty($task['priority_info'])): ?>
      <span class="badge <?= e(priority_label_class($task['priority_info']['label'])) ?>"><?= e($task['priority_info']['label']) ?></span>
      <?php endif; ?>
    </div>
  </div>
  <p class="muted">You do not have to finish this now. Just start with one small step.</p>
</section>

<div class="focus-grid">
  <section class="card focus-timer-card" aria-labelledby="timer-heading">
    <h2 id="timer-heading">Focus timer</h2>
    <div class="focus-timer" data-focus-timer data-planned="5" data-running="false">
      <p class="focus-start-action">
        <span data-lucide="flag" aria-hidden="true"></span>
        <span>
          <?php if ($nextStep !== null): ?>
          Start with: <strong><?= e($nextStep['title']) ?></strong>
          <?php elseif ($suggestions !== []): ?>
          Start with: <strong><?= e($suggestions[0]) ?></strong>
          <?php else: ?>
          Start with the smallest thing you can finish.
          <?php endif; ?>
        </span>
      </p>

      <div class="timer-display" data-timer-display aria-live="off">05:00</div>
      <p class="timer-status" data-timer-status role="status">Ready when you are.</p>

      <div class="timer-controls">
        <button type="button" class="btn btn-primary" data-timer-start>
          <span data-lucide="play" aria-hidden="true"></span> <span data-timer-start-label>Start</span>
        </button>
        <button type="button" class="btn btn-secondary" data-timer-add5>
          <span data-lucide="plus" aria-hidden="true"></span> 5 more minutes
        </button>
      </div>

      <form method="post" action="focus.php" class="focus-session-form" data-focus-session>
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="focus_record">
        <input type="hidden" name="task_id" value="<?= (int) $task['id'] ?>">
        <input type="hidden" name="planned_minutes" value="5" data-timer-planned>
        <input type="hidden" name="actual_seconds" value="0" data-timer-seconds>
        <div class="field">
          <label for="focus-note">What did you accomplish? (optional)</label>
          <textarea id="focus-note" name="note" rows="2" maxlength="500"
                    placeholder="e.g. Wrote the introduction and found two sources."></textarea>
        </div>
        <div class="timer-controls">
          <button type="submit" class="btn btn-primary" data-timer-finish>
            <span data-lucide="check" aria-hidden="true"></span> Finish &amp; save session
          </button>
          <a class="btn btn-ghost" href="tasks.php?task=<?= (int) $task['id'] ?>">Back to task</a>
        </div>
      </form>
    </div>
  </section>

  <section class="card focus-steps-card" aria-labelledby="steps-heading">
    <div class="card-head">
      <h2 id="steps-heading">Small steps</h2>
      <?php if ($stats['total'] > 0): ?>
      <span class="chip-count"><?= (int) $stats['done'] ?>/<?= (int) $stats['total'] ?> done</span>
      <?php endif; ?>
    </div>

    <?php if (count($subtasks) > 0): ?>
    <ul class="subtask-list">
      <?php foreach ($subtasks as $step): ?>
      <li class="subtask-item<?= $step['is_done'] ? ' is-done' : '' ?>">
        <form method="post" action="focus.php" class="inline-form">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="subtask_toggle">
          <input type="hidden" name="task_id" value="<?= (int) $task['id'] ?>">
          <input type="hidden" name="subtask_id" value="<?= (int) $step['id'] ?>">
          <input type="hidden" name="done" value="<?= $step['is_done'] ? '0' : '1' ?>">
          <button type="submit" class="check-btn<?= $step['is_done'] ? ' is-done' : '' ?>"
                  aria-label="<?= $step['is_done'] ? 'Mark step not done' : 'Mark step done' ?>">
            <span data-lucide="check" aria-hidden="true"></span>
          </button>
        </form>
        <span class="subtask-title"><?= e($step['title']) ?></span>
        <form method="post" action="focus.php" class="inline-form"
              data-confirm-delete="Remove this step?">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="subtask_delete">
          <input type="hidden" name="task_id" value="<?= (int) $task['id'] ?>">
          <input type="hidden" name="subtask_id" value="<?= (int) $step['id'] ?>">
          <button type="submit" class="icon-btn" aria-label="Remove step">
            <span data-lucide="trash-2" aria-hidden="true"></span>
          </button>
        </form>
      </li>
      <?php endforeach; ?>
    </ul>
    <?php else: ?>
    <p class="muted">Break this task into small steps so it feels less heavy.
      These suggestions are based on the task's words - edit or remove any you like.</p>
    <ul class="suggestion-list">
      <?php foreach ($suggestions as $suggestion): ?>
      <li>
        <span data-lucide="corner-down-right" aria-hidden="true"></span>
        <span><?= e($suggestion) ?></span>
        <form method="post" action="focus.php" class="inline-form">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="subtask_add">
          <input type="hidden" name="task_id" value="<?= (int) $task['id'] ?>">
          <input type="hidden" name="subtask_title" value="<?= e($suggestion) ?>">
          <button type="submit" class="btn btn-ghost btn-small">Add</button>
        </form>
      </li>
      <?php endforeach; ?>
    </ul>
    <form method="post" action="focus.php" class="form-actions">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="subtask_suggest">
      <input type="hidden" name="task_id" value="<?= (int) $task['id'] ?>">
      <button type="submit" class="btn btn-secondary btn-small">Add all suggested steps</button>
    </form>
    <?php endif; ?>

    <form method="post" action="focus.php" class="subtask-add-form">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="subtask_add">
      <input type="hidden" name="task_id" value="<?= (int) $task['id'] ?>">
      <div class="field field-row">
        <label class="visually-hidden" for="subtask-title">New step</label>
        <input type="text" id="subtask-title" name="subtask_title" maxlength="200"
               placeholder="Add your own step...">
        <button type="submit" class="btn btn-secondary btn-small">Add step</button>
      </div>
    </form>

    <div class="subtask-footer">
      <form method="post" action="focus.php" class="inline-form">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="status">
        <input type="hidden" name="task_id" value="<?= (int) $task['id'] ?>">
        <input type="hidden" name="status" value="in_progress">
        <button type="submit" class="btn btn-secondary btn-small" <?= $task['status'] === 'in_progress' ? 'disabled' : '' ?>>
          Mark In Progress
        </button>
      </form>
      <form method="post" action="focus.php" class="inline-form"
            data-confirm-delete="Mark this whole task as completed?">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="status">
        <input type="hidden" name="task_id" value="<?= (int) $task['id'] ?>">
        <input type="hidden" name="status" value="completed">
        <button type="submit" class="btn btn-primary btn-small" <?= $task['status'] === 'completed' ? 'disabled' : '' ?>>
          Mark Completed
        </button>
      </form>
    </div>
    <p class="muted focus-note-help">Completing the task is always your choice - a finished
      timer never completes it for you.</p>
  </section>
</div>

<?php if ($focusTotals['sessions'] > 0): ?>
<section class="card" aria-labelledby="focus-history-heading">
  <h2 id="focus-history-heading">Recent focus</h2>
  <p class="muted">
    This week: <?= (int) $focusTotals['week_sessions'] ?> session<?= $focusTotals['week_sessions'] === 1 ? '' : 's' ?>
    (<?= e(focus_duration_label($focusTotals['week_seconds'])) ?>).
    Today: <?= e(focus_duration_label($focusTotals['today_seconds'])) ?>.
  </p>
  <ul class="mini-list">
    <?php foreach ($recentSessions as $session): ?>
    <li>
      <a href="tasks.php?task=<?= (int) $session['task_id'] ?>"><?= e($session['task_title']) ?></a>
      <span class="badge badge-completed"><?= e(focus_duration_label((int) $session['actual_seconds'])) ?></span>
      <span class="muted"><?= e(date('M j, g:i A', strtotime($session['started_at']))) ?></span>
      <?php if ($session['note'] !== null && $session['note'] !== ''): ?>
      <span class="muted">&middot; <?= e($session['note']) ?></span>
      <?php endif; ?>
    </li>
    <?php endforeach; ?>
  </ul>
</section>
<?php endif; ?>

<?php require __DIR__ . '/includes/footer.php'; ?>
