<?php
/**
 * ProcrastiNot - "Just 5 Minutes": subtask breakdowns and focus sessions.
 *
 * Students who feel stuck on a big assignment can break it into small steps
 * and start a short, low-pressure focus timer. The timer never changes the
 * task status by itself - only the student decides.
 */

require_once __DIR__ . '/tasks.php';

// ---------------------------------------------------------------------
// Subtasks (small steps that belong to one task)
// ---------------------------------------------------------------------

/** All steps of a task, ordered by position then id (owner-scoped). */
function subtask_list(int $userId, int $taskId): array
{
    $stmt = db()->prepare(
        'SELECT * FROM task_subtasks
         WHERE user_id = ? AND task_id = ?
         ORDER BY position ASC, id ASC'
    );
    $stmt->execute([$userId, $taskId]);
    return $stmt->fetchAll();
}

/** Step tallies for one task. */
function subtask_stats(int $userId, int $taskId): array
{
    $stmt = db()->prepare(
        'SELECT COUNT(*) AS total, SUM(is_done = 1) AS done
         FROM task_subtasks WHERE user_id = ? AND task_id = ?'
    );
    $stmt->execute([$userId, $taskId]);
    $row = $stmt->fetch();
    return ['total' => (int) ($row['total'] ?? 0), 'done' => (int) ($row['done'] ?? 0)];
}

/** Add a step. Returns the new id, or 0 when the task is not owned/valid. */
function subtask_add(int $userId, int $taskId, string $title): int
{
    $title = trim($title);
    if ($title === '' || mb_strlen($title) > 200) {
        return 0;
    }
    if (task_find($userId, $taskId) === null) {
        return 0; // ownership gate
    }
    $stmt = db()->prepare(
        'SELECT COALESCE(MAX(position), 0) + 1 AS next_pos
         FROM task_subtasks WHERE task_id = ? AND user_id = ?'
    );
    $stmt->execute([$taskId, $userId]);
    $position = (int) $stmt->fetchColumn();

    $stmt = db()->prepare(
        'INSERT INTO task_subtasks (task_id, user_id, title, position)
         VALUES (?, ?, ?, ?)'
    );
    $stmt->execute([$taskId, $userId, $title, $position]);
    return (int) db()->lastInsertId();
}

/** Rename a step (owner-scoped). */
function subtask_rename(int $userId, int $subtaskId, string $title): bool
{
    $title = trim($title);
    if ($title === '' || mb_strlen($title) > 200) {
        return false;
    }
    $stmt = db()->prepare(
        'UPDATE task_subtasks SET title = ? WHERE id = ? AND user_id = ?'
    );
    $stmt->execute([$title, $subtaskId, $userId]);
    return $stmt->rowCount() > 0;
}

/** Tick or untick a step (owner-scoped). */
function subtask_set_done(int $userId, int $subtaskId, bool $done): bool
{
    $stmt = db()->prepare(
        'UPDATE task_subtasks
         SET is_done = ?,
             completed_at = CASE WHEN ? = 1 THEN NOW() ELSE NULL END
         WHERE id = ? AND user_id = ?'
    );
    $stmt->execute([$done ? 1 : 0, $done ? 1 : 0, $subtaskId, $userId]);
    return $stmt->rowCount() > 0;
}

function subtask_delete(int $userId, int $subtaskId): bool
{
    $stmt = db()->prepare(
        'DELETE FROM task_subtasks WHERE id = ? AND user_id = ?'
    );
    $stmt->execute([$subtaskId, $userId]);
    return $stmt->rowCount() > 0;
}

/** The first unfinished step of a task, or null (used as the start action). */
function subtask_next_open(int $userId, int $taskId): ?array
{
    $stmt = db()->prepare(
        'SELECT * FROM task_subtasks
         WHERE user_id = ? AND task_id = ? AND is_done = 0
         ORDER BY position ASC, id ASC LIMIT 1'
    );
    $stmt->execute([$userId, $taskId]);
    $row = $stmt->fetch();
    return $row ?: null;
}

// ---------------------------------------------------------------------
// Focus sessions ("Just 5 Minutes")
// ---------------------------------------------------------------------

/**
 * Record a finished focus session. Ownership of the task is verified first.
 * Returns the new session id, or 0 when the task is not owned.
 */
function focus_session_record(int $userId, int $taskId, int $plannedMinutes, int $actualSeconds, ?string $note): int
{
    if (task_find($userId, $taskId) === null) {
        return 0;
    }
    $plannedMinutes = max(1, min(600, $plannedMinutes));
    $actualSeconds  = max(0, min(86400, $actualSeconds));
    $note = $note !== null ? trim($note) : '';
    if (mb_strlen($note) > 500) {
        $note = mb_substr($note, 0, 500);
    }

    $stmt = db()->prepare(
        'INSERT INTO focus_sessions
            (task_id, user_id, planned_minutes, actual_seconds, note, started_at, ended_at)
         VALUES (?, ?, ?, ?, ?, ?, NOW())'
    );
    $startedAt = date('Y-m-d H:i:s', time() - $actualSeconds);
    $stmt->execute([
        $taskId,
        $userId,
        $plannedMinutes,
        $actualSeconds,
        $note !== '' ? $note : null,
        $startedAt,
    ]);
    return (int) db()->lastInsertId();
}

/** Recent focus sessions with the task title, newest first. */
function focus_session_recent(int $userId, int $limit = 5): array
{
    $limit = max(1, min(50, $limit));
    $stmt = db()->prepare(
        'SELECT f.*, t.title AS task_title
         FROM focus_sessions f
         JOIN tasks t ON t.id = f.task_id
         WHERE f.user_id = ?
         ORDER BY f.started_at DESC, f.id DESC
         LIMIT ' . $limit
    );
    $stmt->execute([$userId]);
    return $stmt->fetchAll();
}

/** Aggregate focus-session figures used on the dashboard and reports. */
function focus_session_totals(int $userId): array
{
    $stmt = db()->prepare(
        'SELECT
            COUNT(*) AS sessions,
            COALESCE(SUM(actual_seconds), 0) AS seconds,
            COALESCE(SUM(CASE WHEN DATE(started_at) = CURDATE()
                              THEN actual_seconds ELSE 0 END), 0) AS today_seconds,
            SUM(DATE(started_at) = CURDATE()) AS today_sessions,
            SUM(YEARWEEK(started_at, 1) = YEARWEEK(CURDATE(), 1)) AS week_sessions,
            COALESCE(SUM(CASE WHEN YEARWEEK(started_at, 1) = YEARWEEK(CURDATE(), 1)
                              THEN actual_seconds ELSE 0 END), 0) AS week_seconds
         FROM focus_sessions WHERE user_id = ?'
    );
    $stmt->execute([$userId]);
    $row = $stmt->fetch() ?: [];
    return [
        'sessions'       => (int) ($row['sessions'] ?? 0),
        'seconds'        => (int) ($row['seconds'] ?? 0),
        'today_seconds'  => (int) ($row['today_seconds'] ?? 0),
        'today_sessions' => (int) ($row['today_sessions'] ?? 0),
        'week_sessions'  => (int) ($row['week_sessions'] ?? 0),
        'week_seconds'   => (int) ($row['week_seconds'] ?? 0),
    ];
}

/** Minutes for the focus chart: minutes focused on each of the last N days. */
function focus_minutes_by_day(int $userId, int $days = 7): array
{
    $days = max(1, min(60, $days));
    $stmt = db()->prepare(
        'SELECT DATE(started_at) AS d, COALESCE(SUM(actual_seconds), 0) AS s
         FROM focus_sessions
         WHERE user_id = ? AND started_at >= DATE_SUB(CURDATE(), INTERVAL ? DAY)
         GROUP BY DATE(started_at)'
    );
    $stmt->execute([$userId, $days - 1]);
    $map = [];
    foreach ($stmt->fetchAll() as $row) {
        $map[(string) $row['d']] = (int) $row['s'];
    }
    $result = [];
    $day = new DateTime('today');
    $day->modify('-' . ($days - 1) . ' days');
    for ($i = 0; $i < $days; $i++) {
        $key = $day->format('Y-m-d');
        $result[] = [
            'label'   => $day->format('D'),
            'date'    => $day->format('M j'),
            'minutes' => isset($map[$key]) ? (int) round($map[$key] / 60) : 0,
        ];
        $day->modify('+1 day');
    }
    return $result;
}

/** "12m 30s" style label from a number of seconds. */
function focus_duration_label(int $seconds): string
{
    $seconds = max(0, $seconds);
    $m = intdiv($seconds, 60);
    $s = $seconds % 60;
    if ($m === 0) {
        return $s . 's';
    }
    if ($s === 0) {
        return $m . 'm';
    }
    return $m . 'm ' . $s . 's';
}

// ---------------------------------------------------------------------
// Rule-based starting steps (template engine - NOT an AI model)
// ---------------------------------------------------------------------

/**
 * Suggest a few concrete first steps for a task, chosen by matching keywords
 * in its title and description against a fixed set of study templates. This
 * is transparent pattern matching, so the same words always give the same
 * suggestions. Students can edit, add, or delete any suggested step.
 */
function focus_suggest_steps(array $task): array
{
    $haystack = mb_strtolower(($task['title'] ?? '') . ' ' . ($task['description'] ?? ''));

    $matches = static function (array $words) use ($haystack): bool {
        foreach ($words as $word) {
            if ($word !== '' && mb_strpos($haystack, $word) !== false) {
                return true;
            }
        }
        return false;
    };

    if ($matches(['paper', 'essay', 'report', 'thesis', 'research', 'write'])) {
        return [
            'Open the document and re-read the instructions.',
            'Write down three questions I need to answer.',
            'Find one credible source and note the key idea.',
            'Draft just the first paragraph or section.',
        ];
    }
    if ($matches(['read', 'chapter', 'textbook', 'article'])) {
        return [
            'Skim the headings to see the shape of the reading.',
            'Read one page and write a single key takeaway.',
            'Note two new terms worth remembering.',
            'Summarise the section in three sentences.',
        ];
    }
    if ($matches(['presentation', 'slide', 'project', 'group'])) {
        return [
            'Write the one-sentence message of the presentation.',
            'List the three main points I want to make.',
            'Make a rough outline of the slides.',
            'Fill in the first slide only.',
        ];
    }
    if ($matches(['lab', 'experiment', 'results', 'analysis', 'data'])) {
        return [
            'Gather the data and results in one place.',
            'Write the method in two sentences.',
            'Sketch or list the key result.',
            'Draft the conclusion in one paragraph.',
        ];
    }
    if ($matches(['exam', 'study', 'revision', 'quiz', 'test', 'homework', 'worksheet', 'practice'])) {
        return [
            'Pick the first five questions or problems.',
            'Do just one problem, checking each step.',
            'Mark which ones I still find hard.',
            'Write down the rule or formula I need to remember.',
        ];
    }

    return [
        'Open everything I need for this task.',
        'Write the very first small thing I can finish.',
        'Set a five-minute timer and start there.',
        'Note what I got done so I can pick it up later.',
    ];
}
