<?php
/**
 * ProcrastiNot - task data helpers.
 * Every query is bound to the owning user so students can never
 * touch each other's records.
 */

require_once __DIR__ . '/auth.php';

// ---------------------------------------------------------------------
// Validation
// ---------------------------------------------------------------------

/**
 * Validate and normalize a task form payload.
 * Returns ['ok' => bool, 'errors' => string[], 'data' => array].
 */
function task_validate(array $input): array
{
    $errors = [];
    $fieldErrors = [];
    $data = [
        'title'       => trim((string) ($input['title'] ?? '')),
        'description' => trim((string) ($input['description'] ?? '')),
        'notes'       => trim((string) ($input['notes'] ?? '')),
        'priority'    => (string) ($input['priority'] ?? 'medium'),
        'status'      => (string) ($input['status'] ?? 'pending'),
        'due_date'    => trim((string) ($input['due_date'] ?? '')),
        'my_day'      => !empty($input['my_day']) ? 1 : 0,
        'category_id' => ($input['category_id'] ?? '') !== ''
            ? (int) $input['category_id'] : null,
        'estimated_minutes' => null,
    ];

    if ($data['title'] === '' || mb_strlen($data['title']) > 200) {
        $errors[] = 'Please enter a task title (1-200 characters).';
        $fieldErrors['title'] = 'Please enter a task title (1-200 characters).';
    }
    if (!is_valid_priority($data['priority'])) {
        $errors[] = 'Please choose a valid importance level.';
        $fieldErrors['priority'] = 'Please choose a valid importance level.';
    }
    if (!is_valid_status($data['status'])) {
        $errors[] = 'Please choose a valid status.';
        $fieldErrors['status'] = 'Please choose a valid status.';
    }
    if (!is_valid_date($data['due_date'])) {
        $errors[] = 'The due date must be a real date (YYYY-MM-DD).';
        $fieldErrors['due_date'] = 'The due date must be a real date (YYYY-MM-DD).';
    }
    if ($data['due_date'] === '') {
        $data['due_date'] = null;
    }

    // Optional estimated effort, in whole minutes.
    $est = trim((string) ($input['estimated_minutes'] ?? ''));
    if ($est !== '') {
        if (!ctype_digit($est) || (int) $est < 1 || (int) $est > 6000) {
            $errors[] = 'Estimated time must be whole minutes between 1 and 6000.';
            $fieldErrors['estimated_minutes'] = 'Enter whole minutes between 1 and 6000.';
        } else {
            $data['estimated_minutes'] = (int) $est;
        }
    }

    return ['ok' => $errors === [], 'errors' => $errors, 'field_errors' => $fieldErrors, 'data' => $data];
}

// ---------------------------------------------------------------------
// CRUD (all scoped to the owning user)
// ---------------------------------------------------------------------

function task_categories(int $userId): array
{
    $stmt = db()->prepare(
        'SELECT id, name FROM categories WHERE user_id = ? ORDER BY name'
    );
    $stmt->execute([$userId]);
    return $stmt->fetchAll();
}

/** The student's default category (first by id). */
function task_default_category_id(int $userId): ?int
{
    $stmt = db()->prepare(
        'SELECT id FROM categories WHERE user_id = ? ORDER BY id LIMIT 1'
    );
    $stmt->execute([$userId]);
    $row = $stmt->fetch();
    return $row ? (int) $row['id'] : null;
}

function task_category_owned(int $userId, ?int $categoryId): bool
{
    if ($categoryId === null) {
        return true;
    }
    $stmt = db()->prepare(
        'SELECT id FROM categories WHERE id = ? AND user_id = ? LIMIT 1'
    );
    $stmt->execute([$categoryId, $userId]);
    return (bool) $stmt->fetch();
}

function task_create(int $userId, array $data): int
{
    $stmt = db()->prepare(
        'INSERT INTO tasks
            (user_id, category_id, title, description, notes,
             priority, status, due_date, my_day, estimated_minutes,
             original_due_date, completed_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $stmt->execute([
        $userId,
        $data['category_id'],
        $data['title'],
        $data['description'] !== '' ? $data['description'] : null,
        $data['notes'] !== '' ? $data['notes'] : null,
        $data['priority'],
        $data['status'],
        $data['due_date'],
        $data['my_day'],
        $data['estimated_minutes'] ?? null,
        $data['due_date'],
        $data['status'] === 'completed' ? date('Y-m-d H:i:s') : null,
    ]);
    return (int) db()->lastInsertId();
}

/** Fetch one task owned by the user (with category name), or null. */
function task_find(int $userId, int $taskId): ?array
{
    $stmt = db()->prepare(
        'SELECT t.*, c.name AS category_name
         FROM tasks t
         LEFT JOIN categories c ON c.id = t.category_id
         WHERE t.id = ? AND t.user_id = ?
         LIMIT 1'
    );
    $stmt->execute([$taskId, $userId]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function task_update(int $userId, int $taskId, array $data): bool
{
    $existing = task_find($userId, $taskId);
    if ($existing === null) {
        return false;
    }
    $oldDue = $existing['due_date'];
    $newDue = $data['due_date'];

    db()->beginTransaction();
    try {
        $stmt = db()->prepare(
            'UPDATE tasks
             SET category_id = ?, title = ?, description = ?, notes = ?,
                 priority = ?, status = ?, due_date = ?, my_day = ?,
                 estimated_minutes = ?,
                 original_due_date = COALESCE(original_due_date, ?),
                 completed_at = CASE WHEN ? = \'completed\'
                                     THEN COALESCE(completed_at, NOW())
                                     ELSE NULL END,
                 updated_at = NOW()
             WHERE id = ? AND user_id = ?'
        );
        $stmt->execute([
            $data['category_id'],
            $data['title'],
            $data['description'] !== '' ? $data['description'] : null,
            $data['notes'] !== '' ? $data['notes'] : null,
            $data['priority'],
            $data['status'],
            $newDue,
            $data['my_day'],
            $data['estimated_minutes'] ?? null,
            $oldDue,
            $data['status'],
            $taskId,
            $userId,
        ]);

        // Explicit user edit that moves the deadline: keep an audit trail so
        // the original date is never lost (Comeback Mode rule).
        if ($oldDue !== $newDue) {
            task_record_deadline_change($userId, $taskId, $oldDue, $newDue, 'Edited by student');
        }
        db()->commit();
    } catch (Throwable $ex) {
        db()->rollBack();
        throw $ex;
    }
    return true;
}

/**
 * Append a deadline change to the audit trail and, when used standalone,
 * move the deadline while preserving original_due_date.
 */
function task_record_deadline_change(int $userId, int $taskId, ?string $oldDue, ?string $newDue, ?string $reason = null): void
{
    $stmt = db()->prepare(
        'INSERT INTO task_deadline_history
            (task_id, user_id, old_due_date, new_due_date, reason)
         VALUES (?, ?, ?, ?, ?)'
    );
    $stmt->execute([$taskId, $userId, $oldDue, $newDue, $reason]);
}

function task_delete(int $userId, int $taskId): bool
{
    $stmt = db()->prepare(
        'DELETE FROM tasks WHERE id = ? AND user_id = ?'
    );
    $stmt->execute([$taskId, $userId]);
    return $stmt->rowCount() > 0;
}

function task_set_status(int $userId, int $taskId, string $status): bool
{
    if (!is_valid_status($status)) {
        return false;
    }
    $stmt = db()->prepare(
        'UPDATE tasks
         SET status = ?,
             completed_at = CASE WHEN ? = \'completed\'
                                 THEN COALESCE(completed_at, NOW())
                                 ELSE NULL END,
             updated_at = NOW()
         WHERE id = ? AND user_id = ?'
    );
    $stmt->execute([$status, $status, $taskId, $userId]);
    return $stmt->rowCount() > 0;
}

/** Deadline-change history for one task, newest first (owner-scoped). */
function task_deadline_history(int $userId, int $taskId): array
{
    $stmt = db()->prepare(
        'SELECT * FROM task_deadline_history
         WHERE task_id = ? AND user_id = ?
         ORDER BY changed_at DESC, id DESC'
    );
    $stmt->execute([$taskId, $userId]);
    return $stmt->fetchAll();
}

// ---------------------------------------------------------------------
// List query builder (view / search / filter / sort)
// ---------------------------------------------------------------------

/**
 * Build and run the student's task list.
 * $filters: view (all|my_day|upcoming|completed|overdue), q, status,
 *           priority, category_id, due (today|week|overdue), sort.
 */
function task_list(int $userId, array $filters): array
{
    $where  = ['t.user_id = :uid'];
    $params = [':uid' => $userId];

    $view = $filters['view'] ?? 'all';
    if ($view === 'my_day') {
        $where[] = 't.my_day = 1 AND t.status <> \'completed\'';
    } elseif ($view === 'upcoming') {
        $where[] = 't.status <> \'completed\' AND t.due_date IS NOT NULL AND t.due_date >= CURDATE()';
    } elseif ($view === 'completed') {
        $where[] = 't.status = \'completed\'';
    } elseif ($view === 'overdue') {
        $where[] = 't.due_date < CURDATE() AND t.status <> \'completed\'';
    }

    $q = trim((string) ($filters['q'] ?? ''));
    if ($q !== '') {
        $where[] = 't.title LIKE :q';
        $params[':q'] = '%' . like_escape($q) . '%';
    }

    $status = $filters['status'] ?? '';
    if (is_valid_status($status)) {
        $where[] = 't.status = :status';
        $params[':status'] = $status;
    }

    $priority = $filters['priority'] ?? '';
    if (is_valid_priority($priority)) {
        $where[] = 't.priority = :priority';
        $params[':priority'] = $priority;
    }

    if (!empty($filters['category_id'])) {
        $where[] = 't.category_id = :cat';
        $params[':cat'] = (int) $filters['category_id'];
    }

    $due = $filters['due'] ?? '';
    if ($due === 'today') {
        $where[] = 't.due_date = CURDATE()';
    } elseif ($due === 'week') {
        $where[] = 't.due_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY)';
    } elseif ($due === 'overdue') {
        $where[] = 't.due_date < CURDATE() AND t.status <> \'completed\'';
    }

    $orderBy = 't.due_date IS NULL, t.due_date ASC, t.created_at DESC';
    $sort = $filters['sort'] ?? '';
    if ($sort === 'priority') {
        $orderBy = "FIELD(t.priority, 'high', 'medium', 'low'), t.due_date IS NULL, t.due_date ASC";
    } elseif ($sort === 'created') {
        $orderBy = 't.created_at DESC';
    }

    $sql = 'SELECT t.*, c.name AS category_name
            FROM tasks t
            LEFT JOIN categories c ON c.id = t.category_id
            WHERE ' . implode(' AND ', $where) . '
            ORDER BY ' . $orderBy;

    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

// ---------------------------------------------------------------------
// Dashboard helpers
// ---------------------------------------------------------------------

function task_stats(int $userId): array
{
    $stmt = db()->prepare(
        'SELECT
            COUNT(*) AS total,
            SUM(status = \'pending\') AS pending,
            SUM(status = \'in_progress\') AS in_progress,
            SUM(status = \'completed\') AS completed,
            SUM(due_date IS NOT NULL AND due_date < CURDATE() AND status <> \'completed\') AS overdue,
            SUM(due_date = CURDATE() AND status <> \'completed\') AS due_today
         FROM tasks WHERE user_id = ?'
    );
    $stmt->execute([$userId]);
    $row = $stmt->fetch();

    $total     = (int) $row['total'];
    $completed = (int) $row['completed'];
    return [
        'total'       => $total,
        'pending'     => (int) $row['pending'],
        'in_progress' => (int) $row['in_progress'],
        'completed'   => $completed,
        'overdue'     => (int) $row['overdue'],
        'due_today'   => (int) $row['due_today'],
        'percent'     => $total > 0 ? (int) round($completed * 100 / $total) : 0,
    ];
}

/**
 * Daily focus: open tasks that are flagged My Day, overdue, or due today,
 * ordered so the most urgent float to the top (design D15).
 */
function task_focus(int $userId, int $limit = 4): array
{
    $stmt = db()->prepare(
        'SELECT t.*, c.name AS category_name
         FROM tasks t
         LEFT JOIN categories c ON c.id = t.category_id
         WHERE t.user_id = :uid
           AND t.status <> \'completed\'
           AND (t.my_day = 1 OR (t.due_date IS NOT NULL AND t.due_date <= CURDATE()))
         ORDER BY
           (t.due_date IS NOT NULL AND t.due_date < CURDATE()) DESC,
           t.my_day DESC,
           t.due_date IS NULL, t.due_date ASC,
           FIELD(t.priority, \'high\', \'medium\', \'low\')
         LIMIT ' . $limit
    );
    $stmt->execute([':uid' => $userId]);
    return $stmt->fetchAll();
}

/**
 * Tasks completed on each day of the current ISO week (Monday first),
 * zero-filled so every day appears on the chart.
 */
function task_week_daily_completions(int $userId): array
{
    $stmt = db()->prepare(
        'SELECT DATE(updated_at) AS d, COUNT(*) AS n
         FROM tasks
         WHERE user_id = :uid
           AND status = \'completed\'
           AND YEARWEEK(updated_at, 1) = YEARWEEK(CURDATE(), 1)
         GROUP BY DATE(updated_at)'
    );
    $stmt->execute([':uid' => $userId]);
    $counts = [];
    foreach ($stmt->fetchAll() as $row) {
        $counts[(string) $row['d']] = (int) $row['n'];
    }

    $result = [];
    $day = new DateTime('monday this week');
    for ($i = 0; $i < 7; $i++) {
        $key = $day->format('Y-m-d');
        $result[] = [
            'label' => $day->format('D'),
            'date'  => $day->format('M j'),
            'count' => $counts[$key] ?? 0,
        ];
        $day->modify('+1 day');
    }
    return $result;
}

/** The soonest upcoming deadline still open, or null. */
function task_next_deadline(int $userId): ?array
{
    $stmt = db()->prepare(
        'SELECT t.*, c.name AS category_name
         FROM tasks t
         LEFT JOIN categories c ON c.id = t.category_id
         WHERE t.user_id = :uid
           AND t.status <> \'completed\'
           AND t.due_date IS NOT NULL
           AND t.due_date >= CURDATE()
         ORDER BY t.due_date ASC,
           FIELD(t.priority, \'high\', \'medium\', \'low\')
         LIMIT 1'
    );
    $stmt->execute([':uid' => $userId]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function task_upcoming(int $userId, int $limit = 5): array
{
    $stmt = db()->prepare(
        'SELECT t.*, c.name AS category_name
         FROM tasks t
         LEFT JOIN categories c ON c.id = t.category_id
         WHERE t.user_id = :uid
           AND t.status <> \'completed\'
           AND t.due_date IS NOT NULL
           AND t.due_date >= CURDATE()
         ORDER BY t.due_date ASC
         LIMIT ' . $limit
    );
    $stmt->execute([':uid' => $userId]);
    return $stmt->fetchAll();
}

function task_recent(int $userId, int $limit = 5): array
{
    $stmt = db()->prepare(
        'SELECT t.*, c.name AS category_name
         FROM tasks t
         LEFT JOIN categories c ON c.id = t.category_id
         WHERE t.user_id = :uid
         ORDER BY t.created_at DESC
         LIMIT ' . $limit
    );
    $stmt->execute([':uid' => $userId]);
    return $stmt->fetchAll();
}

/**
 * Tasks completed per week for the last $weeks ISO weeks (Monday start),
 * zero-filled so quiet weeks still appear on the chart.
 * A completed task counts in the week its updated_at fell (design D8).
 */
function task_weekly_completions(int $userId, int $weeks = 8): array
{
    $stmt = db()->prepare(
        'SELECT YEARWEEK(updated_at, 1) AS yw, COUNT(*) AS n
         FROM tasks
         WHERE user_id = ? AND status = \'completed\'
           AND updated_at >= DATE_SUB(CURDATE(), INTERVAL ? WEEK)
         GROUP BY YEARWEEK(updated_at, 1)'
    );
    $stmt->execute([$userId, $weeks]);
    $counts = [];
    foreach ($stmt->fetchAll() as $row) {
        $counts[(string) $row['yw']] = (int) $row['n'];
    }

    // Build the zero-filled week keys ending with the current week.
    // 'o' (ISO year) * 100 + 'W' (ISO week) matches YEARWEEK(..., 1).
    $result = [];
    $week = new DateTime('monday this week');
    $week->modify('-' . ($weeks - 1) . ' weeks');
    for ($i = 0; $i < $weeks; $i++) {
        $yw = (int) $week->format('o') * 100 + (int) $week->format('W');
        $result[] = [
            'label' => $week->format('M j'),
            'count' => $counts[(string) $yw] ?? 0,
        ];
        $week->modify('+1 week');
    }
    return $result;
}

function task_category_breakdown(int $userId): array
{
    $stmt = db()->prepare(
        'SELECT COALESCE(c.name, \'Uncategorized\') AS category,
                COUNT(*) AS total
         FROM tasks t
         LEFT JOIN categories c ON c.id = t.category_id
         WHERE t.user_id = ?
         GROUP BY COALESCE(c.name, \'Uncategorized\')
         ORDER BY total DESC, category ASC'
    );
    $stmt->execute([$userId]);
    return $stmt->fetchAll();
}

// ---------------------------------------------------------------------
// Calendar helpers
// ---------------------------------------------------------------------

/** Per-day aggregates for one month: count, has_overdue, has_completed. */
function task_counts_by_day(int $userId, int $year, int $month): array
{
    $stmt = db()->prepare(
        'SELECT DAY(due_date) AS d,
                COUNT(*) AS n,
                SUM(due_date < CURDATE() AND status <> \'completed\') AS overdue_n,
                SUM(status = \'completed\') AS done_n
         FROM tasks
         WHERE user_id = :uid
           AND due_date IS NOT NULL
           AND YEAR(due_date) = :y
           AND MONTH(due_date) = :m
         GROUP BY DAY(due_date)'
    );
    $stmt->execute([':uid' => $userId, ':y' => $year, ':m' => $month]);
    $days = [];
    foreach ($stmt->fetchAll() as $row) {
        $days[(int) $row['d']] = [
            'count'        => (int) $row['n'],
            'has_overdue'  => (int) $row['overdue_n'] > 0,
            'has_completed'=> (int) $row['done_n'] > 0,
        ];
    }
    return $days;
}

/** All of the student's tasks due on one exact date. */
function task_by_date(int $userId, string $date): array
{
    $stmt = db()->prepare(
        'SELECT t.*, c.name AS category_name
         FROM tasks t
         LEFT JOIN categories c ON c.id = t.category_id
         WHERE t.user_id = :uid AND t.due_date = :d
         ORDER BY
           FIELD(t.priority, \'high\', \'medium\', \'low\'),
           t.title ASC'
    );
    $stmt->execute([':uid' => $userId, ':d' => $date]);
    return $stmt->fetchAll();
}

/** The month's deadlines in chronological order (mobile list alternative). */
function task_month_list(int $userId, int $year, int $month): array
{
    $stmt = db()->prepare(
        'SELECT t.*, c.name AS category_name
         FROM tasks t
         LEFT JOIN categories c ON c.id = t.category_id
         WHERE t.user_id = :uid
           AND t.due_date IS NOT NULL
           AND YEAR(t.due_date) = :y
           AND MONTH(t.due_date) = :m
         ORDER BY t.due_date ASC,
           FIELD(t.priority, \'high\', \'medium\', \'low\')'
    );
    $stmt->execute([':uid' => $userId, ':y' => $year, ':m' => $month]);
    return $stmt->fetchAll();
}
