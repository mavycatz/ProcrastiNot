<?php
/**
 * ProcrastiNot - task list, create, edit, detail, delete, status.
 */
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/tasks.php';
require_once __DIR__ . '/includes/priority.php';
require_once __DIR__ . '/includes/focus.php';
require_once __DIR__ . '/includes/gamification.php';

$user = require_login();
$userId = (int) $user['id'];

$views = ['all', 'my_day', 'upcoming', 'completed', 'overdue'];
$viewLabels = [
    'all'       => 'All Tasks',
    'my_day'    => 'My Day',
    'upcoming'  => 'Upcoming',
    'completed' => 'Completed',
    'overdue'   => 'Overdue',
];

// ---------------------------------------------------------------------
// POST actions (all CSRF-checked and owner-scoped)
// ---------------------------------------------------------------------
$formErrors = [];      // field name => message (shown inline in the form)
$formValues = null;    // submitted values to refill after a validation error
$formRequested = null; // which form failed validation: 'create' or 'edit'
$editing = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $action = (string) ($_POST['action'] ?? '');
    $taskId = (int) ($_POST['id'] ?? 0);

    if ($action === 'create') {
        $result = task_validate($_POST);
        if (!$result['ok']) {
            $formErrors = $result['field_errors'];
            $formValues = $_POST;
            $formRequested = 'create';
        } else {
            $data = $result['data'];
            if ($data['category_id'] === null
                || !task_category_owned($userId, $data['category_id'])) {
                $data['category_id'] = task_default_category_id($userId);
            }
            task_create($userId, $data);
            flash('success', 'Task created.');
            redirect('tasks.php');
        }

    } elseif ($action === 'edit') {
        $existing = task_find($userId, $taskId);
        if ($existing === null) {
            flash('error', 'That task was not found.');
            redirect('tasks.php');
        }
        $result = task_validate($_POST);
        if (!$result['ok']) {
            $formErrors = $result['field_errors'];
            $formValues = $_POST;
            $formRequested = 'edit';
            $editing = $existing;
        } else {
            $data = $result['data'];
            if ($data['category_id'] !== null
                && !task_category_owned($userId, $data['category_id'])) {
                $data['category_id'] = (int) $existing['category_id'];
            }
            task_update($userId, $taskId, $data);
            flash('success', 'Task updated.');
            redirect('tasks.php');
        }

    } elseif ($action === 'delete') {
        if (task_delete($userId, $taskId)) {
            flash('success', 'Task deleted.');
        } else {
            flash('error', 'That task was not found.');
        }
        redirect('tasks.php');

    } elseif ($action === 'status') {
        $status = (string) ($_POST['status'] ?? '');
        if (!is_valid_status($status)) {
            flash('error', 'Please choose a valid status.');
        } elseif (gamification_apply_status($userId, $taskId, $status)) {
            flash('success', 'Status updated to ' . status_label($status) . '.');
        } else {
            flash('error', 'That task was not found.');
        }
        $back = $_SERVER['HTTP_REFERER'] ?? 'tasks.php';
        redirect($back);

    } elseif ($action === 'complete') {
        if (gamification_apply_status($userId, $taskId, 'completed')) {
            flash('success', 'Task marked as completed.');
        } else {
            flash('error', 'That task was not found.');
        }
        $back = $_SERVER['HTTP_REFERER'] ?? 'tasks.php';
        redirect($back);
    }
}

// ---------------------------------------------------------------------
// Filters
// ---------------------------------------------------------------------
$view = (string) ($_GET['view'] ?? 'all');
if (!in_array($view, $views, true)) {
    $view = 'all';
}
$filters = [
    'view'        => $view,
    'q'           => trim((string) ($_GET['q'] ?? '')),
    'status'      => (string) ($_GET['status'] ?? ''),
    'priority'    => (string) ($_GET['priority'] ?? ''),
    'category_id' => (string) ($_GET['category'] ?? ''),
    'due'         => (string) ($_GET['due'] ?? ''),
    'sort'        => (string) ($_GET['sort'] ?? ''),
];
$tasks = task_list($userId, $filters);

// Attach Smart Priority scores/explanations (completed tasks are skipped).
$tasks = priority_annotate($tasks, $userId);
if ($filters['sort'] === 'smart') {
    usort($tasks, static function (array $a, array $b): int {
        return ($b['priority']['score'] ?? -1) <=> ($a['priority']['score'] ?? -1);
    });
}
$categories = task_categories($userId);

// Task detail (?task=N) - owner-checked
$detail = null;
$detailSteps = [];
$detailStats = ['total' => 0, 'done' => 0];
if (isset($_GET['task'])) {
    $detail = task_find($userId, (int) $_GET['task']);
    if ($detail === null) {
        flash('error', 'That task was not found.');
    } else {
        if ($detail['status'] !== 'completed') {
            $detail = priority_annotate([$detail], $userId)[0];
        }
        $detailSteps = subtask_list($userId, (int) $detail['id']);
        $detailStats = subtask_stats($userId, (int) $detail['id']);
    }
}

// Edit form (?edit=N) - owner-checked
if ($editing === null && isset($_GET['edit'])) {
    $editing = task_find($userId, (int) $_GET['edit']);
    if ($editing === null) {
        flash('error', 'That task was not found.');
        redirect('tasks.php');
    }
}

$defaultCategory = task_default_category_id($userId);

/**
 * Render a task form shared by create and edit.
 * $actionValue: 'create' or 'edit'; $task: existing row or null.
 * $errors: field name => message for inline validation; $values: submitted values to refill.
 */
function render_task_form(
    string $actionValue,
    ?array $task,
    array $categories,
    ?int $defaultCategory,
    array $errors = [],
    ?array $values = null
): void {
    $get = static function (string $key, $fallback) use ($values) {
        if ($values !== null && array_key_exists($key, $values)) {
            $value = $values[$key];
            return is_string($value) ? trim($value) : $value;
        }
        return $fallback;
    };

    $title       = (string) $get('title', $task['title'] ?? '');
    $description = (string) $get('description', $task['description'] ?? '');
    $notes       = (string) $get('notes', $task['notes'] ?? '');
    $priority    = (string) $get('priority', $task['priority'] ?? 'medium');
    $status      = (string) $get('status', $task['status'] ?? 'pending');
    $due         = (string) $get('due_date', $task['due_date'] ?? '');
    $catId       = $get('category_id', $task['category_id'] ?? $defaultCategory);
    $estimate    = $get(
        'estimated_minutes',
        isset($task['estimated_minutes']) && $task['estimated_minutes'] !== null
            ? (int) $task['estimated_minutes'] : ''
    );
    $myDay = $values !== null
        ? !empty($values['my_day'])
        : !empty($task['my_day']);

    $err    = static fn(string $key): string => $errors[$key] ?? '';
    $invalid = static fn(string $key): string => $err($key) !== '' ? 'true' : 'false';
    ?>
    <form method="post" action="tasks.php" class="form task-form">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="<?= e($actionValue) ?>">
      <?php if ($actionValue === 'edit'): ?>
      <input type="hidden" name="id" value="<?= (int) $task['id'] ?>">
      <?php endif; ?>

      <?php if ($errors): ?>
      <div class="alert alert-error form-alert" role="alert">
        <span data-lucide="alert-circle" aria-hidden="true"></span>
        Please fix the highlighted fields below.
      </div>
      <?php endif; ?>

      <p class="form-legend">Fields marked <span class="req-mark" aria-hidden="true">*</span> are required.</p>

      <div class="form-row">
        <div class="field field-grow">
          <label for="task-title">Title <span class="req-mark" aria-hidden="true">*</span></label>
          <input type="text" id="task-title" name="title" required maxlength="200"
                 placeholder="e.g. Finish algebra worksheet"
                 value="<?= e($title) ?>" aria-invalid="<?= $invalid('title') ?>">
          <?php if ($err('title')): ?><p class="field-error"><?= e($err('title')) ?></p><?php endif; ?>
        </div>
        <div class="field">
          <label for="task-category">Subject / category</label>
          <select id="task-category" name="category_id" aria-invalid="<?= $invalid('category_id') ?>">
            <?php foreach ($categories as $category): ?>
            <option value="<?= (int) $category['id'] ?>"
              <?= (int) ($catId ?? 0) === (int) $category['id'] ? 'selected' : '' ?>>
              <?= e($category['name']) ?>
            </option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>

      <div class="field field-full">
        <label for="task-description">Description <span class="opt-mark">optional</span></label>
        <textarea id="task-description" name="description" rows="3"
                  placeholder="What needs to be done?"><?= e($description) ?></textarea>
      </div>

      <div class="field field-full">
        <label for="task-notes">Notes <span class="opt-mark">optional</span></label>
        <textarea id="task-notes" name="notes" rows="2"
                  placeholder="Anything to remember?"><?= e($notes) ?></textarea>
      </div>

      <div class="form-row">
        <div class="field">
          <label for="task-priority">Importance</label>
          <select id="task-priority" name="priority" aria-invalid="<?= $invalid('priority') ?>">
            <?php foreach (['high', 'medium', 'low'] as $p): ?>
            <option value="<?= $p ?>" <?= $priority === $p ? 'selected' : '' ?>>
              <?= e(priority_label($p)) ?>
            </option>
            <?php endforeach; ?>
          </select>
          <?php if ($err('priority')): ?><p class="field-error"><?= e($err('priority')) ?></p>
          <?php else: ?><p class="field-help">Feeds the Smart Priority Engine (30% of the score).</p><?php endif; ?>
        </div>
        <div class="field">
          <label for="task-status">Status</label>
          <select id="task-status" name="status" aria-invalid="<?= $invalid('status') ?>">
            <?php foreach (['pending', 'in_progress', 'completed'] as $s): ?>
            <option value="<?= $s ?>" <?= $status === $s ? 'selected' : '' ?>>
              <?= e(status_label($s)) ?>
            </option>
            <?php endforeach; ?>
          </select>
          <?php if ($err('status')): ?><p class="field-error"><?= e($err('status')) ?></p><?php endif; ?>
        </div>
        <div class="field">
          <label for="task-due">Due date <span class="opt-mark">optional</span></label>
          <input type="date" id="task-due" name="due_date" value="<?= e($due) ?>"
                 aria-invalid="<?= $invalid('due_date') ?>">
          <?php if ($err('due_date')): ?><p class="field-error"><?= e($err('due_date')) ?></p><?php endif; ?>
        </div>
        <div class="field">
          <label for="task-estimate">Estimated time (minutes) <span class="opt-mark">optional</span></label>
          <input type="number" id="task-estimate" name="estimated_minutes"
                 min="1" max="6000" step="5" inputmode="numeric" placeholder="e.g. 45"
                 value="<?= e((string) $estimate) ?>"
                 aria-describedby="task-estimate-help" aria-invalid="<?= $invalid('estimated_minutes') ?>">
          <?php if ($err('estimated_minutes')): ?>
          <p class="field-error"><?= e($err('estimated_minutes')) ?></p>
          <?php else: ?>
          <p class="field-help" id="task-estimate-help">Helps risk scoring.</p>
          <?php endif; ?>
        </div>
      </div>

      <div class="field field-checkbox field-full">
        <label for="task-my-day">
          <input type="checkbox" id="task-my-day" name="my_day" value="1"
            <?= $myDay ? 'checked' : '' ?>>
          Add to My Day
        </label>
      </div>

      <div class="form-actions">
        <button type="submit" class="btn btn-primary">
          <?= $actionValue === 'edit' ? 'Save changes' : 'Create task' ?>
        </button>
        <?php if ($actionValue === 'edit'): ?>
        <a class="btn btn-ghost" href="tasks.php">Cancel</a>
        <?php endif; ?>
      </div>
    </form>
    <?php
}

$pageTitle = $viewLabels[$view];
$currentPage = 'tasks';
$pageScripts = ['tasks.js'];
require __DIR__ . '/includes/header.php';
?>

<?php if ($detail !== null): ?>
  <!-- Task detail -->
  <section class="card" aria-labelledby="detail-title">
    <div class="card-head">
      <h2 id="detail-title"><?= e($detail['title']) ?></h2>
      <div class="badges">
        <span class="badge badge-<?= e($detail['priority']) ?>"><?= e(priority_label($detail['priority'])) ?></span>
        <span class="badge badge-<?= e($detail['status']) ?>"><?= e(status_label($detail['status'])) ?></span>
        <?php if (task_is_overdue($detail['due_date'], $detail['status'])): ?>
        <span class="badge badge-overdue">Overdue</span>
        <?php endif; ?>
        <?php if (!empty($detail['priority_info'])): ?>
        <span class="badge <?= e(priority_label_class($detail['priority_info']['label'])) ?>">
          <?= e($detail['priority_info']['label']) ?> priority
        </span>
        <?php endif; ?>
      </div>
    </div>
    <?php if (!empty($detail['priority_info'])): ?>
    <div class="recommendation" role="note">
      <span data-lucide="sparkles" aria-hidden="true"></span>
      <p><?= e($detail['priority_info']['explanation']) ?></p>
      <span class="recommendation-score">Score <?= e((string) $detail['priority_info']['score']) ?>/100</span>
    </div>
    <?php endif; ?>

    <div class="detail-actions">
      <a class="btn btn-primary btn-small" href="focus.php?task=<?= (int) $detail['id'] ?>">
        <span data-lucide="timer" aria-hidden="true"></span> Just 5 Minutes
      </a>
      <?php if ($detailStats['total'] > 0): ?>
      <span class="chip-count"><?= (int) $detailStats['done'] ?>/<?= (int) $detailStats['total'] ?> steps done</span>
      <?php else: ?>
      <span class="muted">Break this into small steps to make it easy to start.</span>
      <?php endif; ?>
    </div>

    <?php if (count($detailSteps) > 0): ?>
    <ul class="subtask-list subtask-list-compact">
      <?php foreach ($detailSteps as $step): ?>
      <li class="subtask-item<?= $step['is_done'] ? ' is-done' : '' ?>">
        <span class="subtask-dot" aria-hidden="true"></span>
        <span class="subtask-title"><?= e($step['title']) ?></span>
      </li>
      <?php endforeach; ?>
    </ul>
    <?php endif; ?>

    <dl class="detail-list">
      <dt>Subject / category</dt>
      <dd><?= e($detail['category_name'] ?? 'Uncategorized') ?></dd>
      <dt>Due date</dt>
      <dd><?= $detail['due_date'] !== null ? e(date('M j, Y', strtotime($detail['due_date']))) : '<span class="muted">No due date</span>' ?></dd>
      <?php if ($detail['original_due_date'] !== null && $detail['original_due_date'] !== $detail['due_date']): ?>
      <dt>Original due date</dt>
      <dd><?= e(date('M j, Y', strtotime($detail['original_due_date']))) ?> <span class="muted">(rescheduled)</span></dd>
      <?php endif; ?>
      <dt>Estimated time</dt>
      <dd><?= $detail['estimated_minutes'] !== null ? e(priority_estimate_label((int) $detail['estimated_minutes'])) : '<span class="muted">Not set</span>' ?></dd>
      <dt>My Day</dt>
      <dd><?= $detail['my_day'] ? 'Yes' : 'No' ?></dd>
      <dt>Description</dt>
      <dd><?= $detail['description'] !== null && $detail['description'] !== '' ? nl2br(e($detail['description'])) : '<span class="muted">None</span>' ?></dd>
      <dt>Notes</dt>
      <dd><?= $detail['notes'] !== null && $detail['notes'] !== '' ? nl2br(e($detail['notes'])) : '<span class="muted">None</span>' ?></dd>
      <dt>Created</dt>
      <dd><?= e(date('M j, Y g:i A', strtotime($detail['created_at']))) ?></dd>
      <dt>Last updated</dt>
      <dd><?= e(date('M j, Y g:i A', strtotime($detail['updated_at']))) ?></dd>
    </dl>
    <div class="form-actions">
      <form method="post" action="tasks.php" class="inline-form">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="status">
        <input type="hidden" name="id" value="<?= (int) $detail['id'] ?>">
        <label class="visually-hidden" for="detail-status">Change status</label>
        <select id="detail-status" name="status" class="status-select">
          <?php foreach (['pending', 'in_progress', 'completed'] as $s): ?>
          <option value="<?= $s ?>" <?= $detail['status'] === $s ? 'selected' : '' ?>>
            <?= e(status_label($s)) ?>
          </option>
          <?php endforeach; ?>
        </select>
        <button type="submit" class="btn btn-secondary">Update status</button>
      </form>
      <a class="btn btn-secondary" href="tasks.php?edit=<?= (int) $detail['id'] ?>">Edit</a>
      <form method="post" action="tasks.php" class="inline-form"
            data-confirm-delete="Delete this task? This cannot be undone.">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="delete">
        <input type="hidden" name="id" value="<?= (int) $detail['id'] ?>">
        <button type="submit" class="btn btn-danger">Delete</button>
      </form>
      <a class="btn btn-ghost" href="tasks.php">Back to tasks</a>
    </div>
  </section>

<?php else: ?>

  <?php if ($editing !== null): ?>
  <!-- Edit task -->
  <section class="card" aria-labelledby="edit-heading">
    <h2 id="edit-heading">Edit task</h2>
    <?php
    $editErrors = $formRequested === 'edit' ? $formErrors : [];
    $editValues = $formRequested === 'edit' ? $formValues : null;
    render_task_form('edit', $editing, $categories, $defaultCategory, $editErrors, $editValues);
    ?>
  </section>
  <?php else: ?>
  <?php $openCreate = $formRequested === 'create' || (($_GET['action'] ?? '') === 'new'); ?>
  <!-- Create task -->
  <section class="card collapsible" aria-labelledby="new-heading">
    <h2 id="new-heading"><button type="button" class="collapse-toggle"
        aria-expanded="<?= $openCreate ? 'true' : 'false' ?>" aria-controls="new-task-body">New task</button></h2>
    <div id="new-task-body" class="collapse-body"<?= $openCreate ? '' : ' hidden' ?>>
      <?php
      $createErrors = $formRequested === 'create' ? $formErrors : [];
      $createValues = $formRequested === 'create' ? $formValues : null;
      render_task_form('create', null, $categories, $defaultCategory, $createErrors, $createValues);
      ?>
    </div>
  </section>
  <?php endif; ?>

  <!-- View tabs -->
  <nav class="tabs" aria-label="Task views">
    <ul>
      <?php foreach ($views as $v): ?>
      <li>
        <a class="tab<?= $v === $view ? ' is-active' : '' ?>"
           href="tasks.php?view=<?= e($v) ?>"
           <?= $v === $view ? 'aria-current="page"' : '' ?>>
          <?= e($viewLabels[$v]) ?>
        </a>
      </li>
      <?php endforeach; ?>
    </ul>
  </nav>

  <!-- Search and filters -->
  <section class="card filter-card" aria-label="Search and filter tasks">
    <form method="get" action="tasks.php" class="filter-form">
      <input type="hidden" name="view" value="<?= e($view) ?>">
      <div class="field field-grow">
        <label for="filter-q">Search by title</label>
        <input type="search" id="filter-q" name="q" value="<?= e($filters['q']) ?>">
      </div>
      <div class="field">
        <label for="filter-status">Status</label>
        <select id="filter-status" name="status">
          <option value="">Any</option>
          <?php foreach (['pending', 'in_progress', 'completed'] as $s): ?>
          <option value="<?= $s ?>" <?= $filters['status'] === $s ? 'selected' : '' ?>>
            <?= e(status_label($s)) ?>
          </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="field">
        <label for="filter-priority">Priority</label>
        <select id="filter-priority" name="priority">
          <option value="">Any</option>
          <?php foreach (['high', 'medium', 'low'] as $p): ?>
          <option value="<?= $p ?>" <?= $filters['priority'] === $p ? 'selected' : '' ?>>
            <?= e(priority_label($p)) ?>
          </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="field">
        <label for="filter-category">Subject</label>
        <select id="filter-category" name="category">
          <option value="">Any</option>
          <?php foreach ($categories as $category): ?>
          <option value="<?= (int) $category['id'] ?>"
            <?= $filters['category_id'] === (string) $category['id'] ? 'selected' : '' ?>>
            <?= e($category['name']) ?>
          </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="field">
        <label for="filter-due">Deadline</label>
        <select id="filter-due" name="due">
          <option value="">Any</option>
          <option value="today" <?= $filters['due'] === 'today' ? 'selected' : '' ?>>Due today</option>
          <option value="week" <?= $filters['due'] === 'week' ? 'selected' : '' ?>>Next 7 days</option>
          <option value="overdue" <?= $filters['due'] === 'overdue' ? 'selected' : '' ?>>Overdue</option>
        </select>
      </div>
      <div class="field">
        <label for="filter-sort">Sort by</label>
        <select id="filter-sort" name="sort">
          <option value="">Due date</option>
          <option value="smart" <?= $filters['sort'] === 'smart' ? 'selected' : '' ?>>Smart (recommended)</option>
          <option value="priority" <?= $filters['sort'] === 'priority' ? 'selected' : '' ?>>Importance</option>
          <option value="created" <?= $filters['sort'] === 'created' ? 'selected' : '' ?>>Created date</option>
        </select>
      </div>
      <div class="field field-actions">
        <button type="submit" class="btn btn-secondary">Apply</button>
      </div>
    </form>
  </section>

  <!-- Task list -->
  <section class="card" aria-label="Tasks">
    <?php if (count($tasks) === 0): ?>
    <div class="empty-state">
      <span data-lucide="inbox" aria-hidden="true"></span>
      <h3>No tasks here yet</h3>
      <p><?php
        if ($filters['q'] !== '') {
            echo 'No tasks match your search. Try different words or clear the filters.';
        } elseif ($view !== 'all') {
            echo 'Nothing in this view right now. Create a task to get started.';
        } else {
            echo 'Create your first task to start tracking your studies.';
        }
      ?></p>
      <a class="btn btn-primary" href="tasks.php">Show all tasks</a>
    </div>
    <?php else: ?>
    <ul class="task-list">
      <?php foreach ($tasks as $task): ?>
      <li class="task-item<?= task_is_overdue($task['due_date'], $task['status']) ? ' is-overdue' : '' ?>">
        <form method="post" action="tasks.php" class="inline-form task-complete-form">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="complete">
          <input type="hidden" name="id" value="<?= (int) $task['id'] ?>">
          <button type="submit" class="check-btn<?= $task['status'] === 'completed' ? ' is-done' : '' ?>"
                  aria-label="<?= $task['status'] === 'completed' ? 'Task completed' : 'Mark task completed' ?>"
                  <?= $task['status'] === 'completed' ? 'disabled' : '' ?>>
            <span data-lucide="check" aria-hidden="true"></span>
          </button>
        </form>
        <div class="task-body">
          <a class="task-title" href="tasks.php?task=<?= (int) $task['id'] ?>"><?= e($task['title']) ?></a>
          <div class="task-meta">
            <span class="badge badge-<?= e($task['priority']) ?>"><?= e(priority_label($task['priority'])) ?></span>
            <span class="badge badge-<?= e($task['status']) ?>"><?= e(status_label($task['status'])) ?></span>
            <?php if (!empty($task['priority_info'])): ?>
            <span class="badge <?= e(priority_label_class($task['priority_info']['label'])) ?>"
                  title="<?= e($task['priority_info']['explanation']) ?>">
              <?= e($task['priority_info']['label']) ?>
            </span>
            <?php endif; ?>
            <span class="muted"><?= e($task['category_name'] ?? 'Uncategorized') ?></span>
            <?php if ($task['due_date'] !== null): ?>
            <span class="muted">Due <?= e(date('M j, Y', strtotime($task['due_date']))) ?></span>
            <?php endif; ?>
            <?php if (task_is_overdue($task['due_date'], $task['status'])): ?>
            <span class="badge badge-overdue">Overdue</span>
            <?php endif; ?>
            <?php if ($task['my_day']): ?>
            <span class="badge badge-myday">My Day</span>
            <?php endif; ?>
          </div>
        </div>
        <div class="task-actions">
          <form method="post" action="tasks.php" class="inline-form">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="status">
            <input type="hidden" name="id" value="<?= (int) $task['id'] ?>">
            <label class="visually-hidden" for="status-<?= (int) $task['id'] ?>">
              Change status of <?= e($task['title']) ?>
            </label>
            <select id="status-<?= (int) $task['id'] ?>" name="status" class="status-select">
              <?php foreach (['pending', 'in_progress', 'completed'] as $s): ?>
              <option value="<?= $s ?>" <?= $task['status'] === $s ? 'selected' : '' ?>>
                <?= e(status_label($s)) ?>
              </option>
              <?php endforeach; ?>
            </select>
            <button type="submit" class="btn btn-ghost btn-small">Save</button>
          </form>
          <a class="btn btn-ghost btn-small" href="tasks.php?edit=<?= (int) $task['id'] ?>">Edit</a>
          <form method="post" action="tasks.php" class="inline-form"
                data-confirm-delete="Delete &lsquo;<?= e($task['title']) ?>&rsquo;? This cannot be undone.">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="id" value="<?= (int) $task['id'] ?>">
            <button type="submit" class="btn btn-danger btn-small">Delete</button>
          </form>
        </div>
      </li>
      <?php endforeach; ?>
    </ul>
    <?php endif; ?>
  </section>
<?php endif; ?>

<?php if ($detail === null && $editing === null): ?>
<!-- Floating action button: opens the New task panel -->
<button class="fab" type="button" data-open-new-task>
  <span data-lucide="plus" aria-hidden="true"></span>
  Add task
</button>
<?php endif; ?>

<!-- Delete confirmation dialog -->
<dialog id="delete-dialog" class="dialog" aria-labelledby="delete-dialog-title">
  <form method="post" action="tasks.php" id="delete-dialog-form">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="delete">
    <input type="hidden" name="id" id="delete-dialog-id" value="">
    <h2 id="delete-dialog-title">Delete task?</h2>
    <p id="delete-dialog-message">This cannot be undone.</p>
    <div class="form-actions">
      <button type="button" class="btn btn-ghost" data-delete-cancel>Cancel</button>
      <button type="submit" class="btn btn-danger">Delete</button>
    </div>
  </form>
</dialog>

<?php require __DIR__ . '/includes/footer.php'; ?>
