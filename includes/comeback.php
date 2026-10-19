<?php
/**
 * ProcrastiNot - Comeback Mode.
 *
 * A supportive, non-judgemental recovery system for tasks that slipped. It
 * never changes a deadline on its own and never removes points or streaks -
 * it simply surfaces what needs restarting and lets the student choose.
 */

require_once __DIR__ . '/tasks.php';
require_once __DIR__ . '/priority.php';

/** Overdue, unfinished tasks ranked by the Smart Priority Engine. */
function comeback_overdue_tasks(int $userId): array
{
    $stmt = db()->prepare(
        'SELECT t.*, c.name AS category_name
         FROM tasks t
         LEFT JOIN categories c ON c.id = t.category_id
         WHERE t.user_id = ?
           AND t.status <> \'completed\'
           AND t.due_date IS NOT NULL
           AND t.due_date < CURDATE()'
    );
    $stmt->execute([$userId]);
    $tasks = $stmt->fetchAll();
    if ($tasks === []) {
        return [];
    }
    $tasks = priority_annotate($tasks, $userId);
    usort($tasks, static fn($a, $b) => ($b['priority_info']['score'] ?? -1) <=> ($a['priority_info']['score'] ?? -1));
    return $tasks;
}

/**
 * Tasks that are not overdue yet but are at risk of slipping: due within the
 * next few days with high estimated effort relative to the study time left.
 */
function comeback_at_risk_tasks(int $userId, int $days = 3): array
{
    $days = max(1, min(14, $days));
    $stmt = db()->prepare(
        'SELECT t.*, c.name AS category_name
         FROM tasks t
         LEFT JOIN categories c ON c.id = t.category_id
         WHERE t.user_id = ?
           AND t.status <> \'completed\'
           AND t.due_date IS NOT NULL
           AND t.due_date >= CURDATE()
           AND t.due_date <= DATE_ADD(CURDATE(), INTERVAL ? DAY)'
    );
    $stmt->execute([$userId, $days]);
    $tasks = $stmt->fetchAll();
    if ($tasks === []) {
        return [];
    }
    $tasks = priority_annotate($tasks, $userId);
    $risky = array_values(array_filter($tasks, static function (array $t): bool {
        $info = $t['priority_info'] ?? null;
        if ($info === null) {
            return false;
        }
        $daysLeft = $info['days_left'];
        return ($daysLeft !== null && $daysLeft <= 1) || ($info['factors']['risk'] ?? 0) >= 0.6;
    }));
    usort($risky, static fn($a, $b) => ($b['priority_info']['score'] ?? -1) <=> ($a['priority_info']['score'] ?? -1));
    return $risky;
}

/**
 * Move a task's deadline with explicit student approval. Records the change in
 * task_deadline_history and preserves the very first deadline, so historical
 * reporting stays honest. Never called without a form submission.
 */
function comeback_reschedule(int $userId, int $taskId, string $newDue, ?string $reason): bool
{
    if ($newDue === '' || !is_valid_date($newDue)) {
        return false;
    }
    $task = task_find($userId, $taskId);
    if ($task === null) {
        return false;
    }
    $old = $task['due_date'];
    if ($old === $newDue) {
        return true; // nothing to change
    }
    $reason = $reason !== null ? trim($reason) : '';
    if (mb_strlen($reason) > 200) {
        $reason = mb_substr($reason, 0, 200);
    }
    $original = $old ?? $newDue;

    db()->beginTransaction();
    try {
        $stmt = db()->prepare(
            'UPDATE tasks
             SET due_date = ?, original_due_date = COALESCE(original_due_date, ?),
                 updated_at = NOW()
             WHERE id = ? AND user_id = ?'
        );
        $stmt->execute([$newDue, $original, $taskId, $userId]);
        task_record_deadline_change($userId, $taskId, $old, $newDue,
            $reason !== '' ? $reason : 'Rescheduled in Comeback Mode');
        db()->commit();
        return true;
    } catch (Throwable $ex) {
        db()->rollBack();
        throw $ex;
    }
}

/**
 * Recovery progress: of the tasks that were once overdue (their original
 * deadline has passed), the share that are now completed.
 * Returns ['overdue_now', 'recovered', 'total', 'percent'].
 */
function comeback_progress(int $userId): array
{
    $stmt = db()->prepare(
        'SELECT COUNT(*) AS recovered
         FROM tasks
         WHERE user_id = ?
           AND status = \'completed\'
           AND original_due_date IS NOT NULL
           AND original_due_date < CURDATE()'
    );
    $stmt->execute([$userId]);
    $recovered = (int) $stmt->fetchColumn();

    $stmt = db()->prepare(
        'SELECT COUNT(*)
         FROM tasks
         WHERE user_id = ? AND status <> \'completed\'
           AND due_date IS NOT NULL AND due_date < CURDATE()'
    );
    $stmt->execute([$userId]);
    $overdueNow = (int) $stmt->fetchColumn();

    $total = $recovered + $overdueNow;
    return [
        'overdue_now' => $overdueNow,
        'recovered'   => $recovered,
        'total'       => $total,
        'percent'     => $total > 0 ? (int) round($recovered * 100 / $total) : 0,
    ];
}

/**
 * A realistic, encouraging next action for a task: the first unfinished
 * subtask when planning exists, otherwise the top suggested starting step.
 */
function comeback_next_action(int $userId, array $task, array $suggestions): string
{
    $step = subtask_next_open($userId, (int) $task['id']);
    if ($step !== null) {
        return 'Open the task and finish this step: ' . $step['title'];
    }
    if (isset($suggestions[0])) {
        return 'Set a five-minute timer and: ' . $suggestions[0];
    }
    return 'Set a five-minute timer and write down the smallest first step.';
}
