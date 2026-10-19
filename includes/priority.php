<?php
/**
 * ProcrastiNot - Smart Priority Engine.
 *
 * A transparent, rule-based recommendation engine (NOT an AI model). It ranks
 * a student's unfinished tasks so the dashboard and task list can suggest what
 * to work on next, and explains every recommendation in plain language.
 *
 * ---------------------------------------------------------------------
 * THE FORMULA
 * ---------------------------------------------------------------------
 * Every factor is normalized to 0.00 - 1.00, then combined with fixed,
 * configurable weights that always sum to 1.00:
 *
 *      score = 100 * ( 0.35*urgency + 0.30*importance
 *                    + 0.20*risk   + 0.15*effort )
 *
 *   1. Urgency (35%)      How close the deadline is.
 *                         overdue            -> 1.00
 *                         due today          -> 1.00
 *                         due in N days      -> max(0.05, 1 - N/14)
 *                         no due date        -> 0.10
 *
 *   2. Importance (30%)   The student's own importance field.
 *                         high 1.00 | medium 0.60 | low 0.30
 *
 *   3. Risk (20%)         Chance of not finishing in time, based on the
 *                         estimated effort versus the study time still
 *                         available before the deadline:
 *                         availableHours = (daysLeft + 0.5) * dailyStudyHours
 *                         risk = clamp(estHours / max(availableHours, 0.5))
 *                         Note: overdue tasks score 0 here on purpose - their
 *                         urgency is already maximal, so this avoids punishing
 *                         the same lateness twice.
 *
 *   4. Effort (15%)       Bigger tasks are surfaced earlier so students start
 *                         them in time; tasks already broken into subtasks
 *                         score a little lower (planning already done).
 *                         estNorm = clamp(estMinutes / 240, 0.20, 1.00)
 *                         effort  = estNorm * (hasSubtasks ? 0.70 : 1.00)
 *                         no estimate -> 0.40 (neutral)
 *
 * Scores are recomputed on every request, so they always reflect the latest
 * deadline, importance, estimate, status, and subtask data - nothing stale is
 * stored. Completed tasks are never ranked.
 *
 * Urgency labels:  score >= 75 Critical | >= 55 High | >= 35 Medium | else Low
 */

require_once __DIR__ . '/tasks.php';

/** Documented, configurable factor weights (must sum to 1.0). */
function priority_weights(): array
{
    if (defined('PRIORITY_WEIGHTS') && is_array(PRIORITY_WEIGHTS)) {
        return PRIORITY_WEIGHTS;
    }
    return [
        'urgency'    => 0.35,
        'importance' => 0.30,
        'risk'       => 0.20,
        'effort'     => 0.15,
    ];
}

/** Importance factor from the task's stored importance (priority) value. */
function priority_importance_factor(string $importance): float
{
    $map = ['high' => 1.0, 'medium' => 0.6, 'low' => 0.3];
    return $map[$importance] ?? 0.5;
}

/** Whole days from $today (Y-m-d) to $dueDate (negative = past). */
function priority_days_until(?string $dueDate, string $today): ?int
{
    if ($dueDate === null || $dueDate === '') {
        return null;
    }
    $due = strtotime($dueDate . ' 00:00:00');
    $now = strtotime($today . ' 00:00:00');
    if ($due === false || $now === false) {
        return null;
    }
    return (int) round(($due - $now) / 86400);
}

/** Urgency factor 0..1 from the deadline. */
function priority_urgency_factor(?int $days): float
{
    if ($days === null) {
        return 0.10; // no deadline: low but non-zero
    }
    if ($days <= 0) {
        return 1.00; // overdue or due today
    }
    return max(0.05, 1.00 - ($days / 14.0));
}

/**
 * Risk factor 0..1: estimated effort versus available study time.
 * Returns 0.00 for overdue tasks (urgency already maximal) and a neutral
 * 0.20 when there is no deadline to reason about.
 */
function priority_risk_factor(?int $days, ?int $estMinutes, ?int $studyMinutesPerDay): float
{
    if ($days === null) {
        return 0.20; // no deadline -> cannot assess risk
    }
    if ($days < 0) {
        return 0.00; // overdue: avoid double-penalising lateness
    }
    $estHours = $estMinutes !== null && $estMinutes > 0
        ? $estMinutes / 60.0
        : 1.0; // unknown effort: assume one hour
    $dailyHours = $studyMinutesPerDay !== null && $studyMinutesPerDay > 0
        ? $studyMinutesPerDay / 60.0
        : 2.0; // no study-time preference: assume two hours a day
    $availableHours = ($days + 0.5) * $dailyHours;
    $risk = $estHours / max($availableHours, 0.5);
    return max(0.0, min(1.0, $risk));
}

/** Effort / planning factor 0..1 from the estimate and subtask planning. */
function priority_effort_factor(?int $estMinutes, bool $hasSubtasks): float
{
    if ($estMinutes === null || $estMinutes <= 0) {
        return 0.40; // neutral when no estimate is given
    }
    $estNorm = max(0.20, min(1.00, $estMinutes / 240.0));
    return $hasSubtasks ? $estNorm * 0.70 : $estNorm;
}

/** Human label for an estimated duration, e.g. 90 -> "1.5 hours". */
function priority_estimate_label(?int $minutes): string
{
    if ($minutes === null || $minutes <= 0) {
        return '';
    }
    if ($minutes < 60) {
        return $minutes . ' minute' . ($minutes === 1 ? '' : 's');
    }
    $hours = $minutes / 60.0;
    $rounded = round($hours, $hours < 3 ? 1 : 0);
    if ($rounded == (int) $rounded) {
        $rounded = (int) $rounded;
    }
    return $rounded . ' hour' . ($rounded == 1 ? '' : 's');
}

/** Urgency label for a 0-100 score. */
function priority_label_for_score(float $score): string
{
    if ($score >= 75) return 'Critical';
    if ($score >= 55) return 'High';
    if ($score >= 35) return 'Medium';
    return 'Low';
}

/** CSS modifier (critical|high|medium|low) for an urgency label. */
function priority_label_class(string $label): string
{
    return 'urgency-' . strtolower($label);
}

/** Join reason fragments into a readable clause: a, b, and c. */
function priority_join_reasons(array $reasons): string
{
    $reasons = array_values(array_filter($reasons, static fn($r) => $r !== ''));
    $count = count($reasons);
    if ($count === 0) {
        return 'It is one of your open tasks.';
    }
    if ($count === 1) {
        return $reasons[0];
    }
    $last = array_pop($reasons);
    return implode(', ', $reasons) . ', and ' . $last;
}

/**
 * Score one task and explain it.
 * $subtaskStats: ['total' => int, 'done' => int] for this task (optional).
 * Returns: score, label, class, explanation, factors, days_left.
 */
function priority_score_task(array $task, ?int $studyMinutesPerDay, array $subtaskStats = [], ?string $today = null): array
{
    $today = $today ?? date('Y-m-d');
    $days = priority_days_until($task['due_date'] ?? null, $today);
    $importance = (string) ($task['priority'] ?? 'medium');
    $estMinutes = isset($task['estimated_minutes']) && $task['estimated_minutes'] !== null
        ? (int) $task['estimated_minutes'] : null;
    $hasSubtasks = (int) ($subtaskStats['total'] ?? 0) > 0;

    $factors = [
        'urgency'    => priority_urgency_factor($days),
        'importance' => priority_importance_factor($importance),
        'risk'       => priority_risk_factor($days, $estMinutes, $studyMinutesPerDay),
        'effort'     => priority_effort_factor($estMinutes, $hasSubtasks),
    ];

    $weights = priority_weights();
    $score = 0.0;
    foreach ($weights as $key => $weight) {
        $score += $weight * ($factors[$key] ?? 0.0);
    }
    $score = round($score * 100, 1);
    $label = priority_label_for_score($score);

    // ---- Plain-language explanation (matches the scoring rules exactly) ----
    $reasons = [];
    if ($days === null) {
        $reasons[] = 'it has no deadline yet';
    } elseif ($days < 0) {
        $abs = abs($days);
        $reasons[] = $abs === 1 ? "it's 1 day overdue" : "it's {$abs} days overdue";
    } elseif ($days === 0) {
        $reasons[] = "it's due today";
    } elseif ($days === 1) {
        $reasons[] = "it's due tomorrow";
    } else {
        $reasons[] = "it's due in {$days} days";
    }

    $importanceWord = ['high' => 'high', 'medium' => 'medium', 'low' => 'low'][$importance] ?? $importance;
    $reasons[] = 'marked ' . $importanceWord . ' importance';

    $estimateLabel = priority_estimate_label($estMinutes);
    if ($estimateLabel !== '') {
        $reasons[] = 'estimated to take about ' . $estimateLabel;
    }

    if ($factors['risk'] >= 0.60 && $days !== null && $days >= 0) {
        $reasons[] = "it's at risk of slipping";
    }
    if ($hasSubtasks && (int) $subtaskStats['done'] < (int) $subtaskStats['total']) {
        $done = (int) $subtaskStats['done'];
        $total = (int) $subtaskStats['total'];
        $reasons[] = "you've already planned {$done} of {$total} steps";
    }

    $explanation = 'It\'s recommended because ' . priority_join_reasons($reasons) . '.';

    return [
        'score'       => $score,
        'label'       => $label,
        'class'       => priority_label_class($label),
        'explanation' => $explanation,
        'factors'     => $factors,
        'days_left'   => $days,
    ];
}

/** Subtask tallies for many tasks at once: [taskId => ['total'=>,'done'=>]]. */
function priority_subtask_stats(int $userId, array $taskIds): array
{
    $taskIds = array_values(array_filter(array_map('intval', $taskIds)));
    if ($taskIds === []) {
        return [];
    }
    $placeholders = implode(',', array_fill(0, count($taskIds), '?'));
    $params = array_merge([$userId], $taskIds);
    $stmt = db()->prepare(
        'SELECT task_id,
                COUNT(*) AS total,
                SUM(is_done = 1) AS done
         FROM task_subtasks
         WHERE user_id = ? AND task_id IN (' . $placeholders . ')
         GROUP BY task_id'
    );
    $stmt->execute($params);
    $stats = [];
    foreach ($stmt->fetchAll() as $row) {
        $stats[(int) $row['task_id']] = [
            'total' => (int) $row['total'],
            'done'  => (int) $row['done'],
        ];
    }
    return $stats;
}

/** The student's configured study time per day (minutes) or null. */
function priority_study_minutes(int $userId): ?int
{
    $stmt = db()->prepare('SELECT daily_study_minutes FROM users WHERE id = ? LIMIT 1');
    $stmt->execute([$userId]);
    $row = $stmt->fetch();
    if (!$row || $row['daily_study_minutes'] === null) {
        return null;
    }
    return (int) $row['daily_study_minutes'];
}

/**
 * Annotate a list of tasks with priority scoring (adds a 'priority' key).
 * Completed tasks are left unannotated. Returns the same array shape.
 */
function priority_annotate(array $tasks, int $userId, ?int $studyMinutes = null): array
{
    if ($tasks === []) {
        return $tasks;
    }
    if ($studyMinutes === null) {
        $studyMinutes = priority_study_minutes($userId);
    }
    $ids = array_map(static fn($t) => (int) $t['id'], $tasks);
    $stats = priority_subtask_stats($userId, $ids);
    $today = date('Y-m-d');

    foreach ($tasks as &$task) {
        if (($task['status'] ?? '') === 'completed') {
            $task['priority_info'] = null;
            continue;
        }
        $task['priority_info'] = priority_score_task(
            $task,
            $studyMinutes,
            $stats[(int) $task['id']] ?? [],
            $today
        );
    }
    unset($task);
    return $tasks;
}

/**
 * The student's ranked recommendations (unfinished tasks, best first).
 * $limit = 0 means "all open tasks".
 */
function priority_rank_tasks(int $userId, int $limit = 5): array
{
    $stmt = db()->prepare(
        'SELECT t.*, c.name AS category_name
         FROM tasks t
         LEFT JOIN categories c ON c.id = t.category_id
         WHERE t.user_id = ? AND t.status <> \'completed\''
    );
    $stmt->execute([$userId]);
    $tasks = $stmt->fetchAll();
    if ($tasks === []) {
        return [];
    }

    $tasks = priority_annotate($tasks, $userId);

    usort($tasks, static function (array $a, array $b): int {
        $sa = $a['priority_info']['score'] ?? -1;
        $sb = $b['priority_info']['score'] ?? -1;
        if ($sa === $sb) {
            return strcmp((string) $a['title'], (string) $b['title']);
        }
        return $sb <=> $sa;
    });

    if ($limit > 0) {
        $tasks = array_slice($tasks, 0, $limit);
    }
    return $tasks;
}

/** The single best next task, or null. */
function priority_next_task(int $userId): ?array
{
    $ranked = priority_rank_tasks($userId, 1);
    return $ranked[0] ?? null;
}
