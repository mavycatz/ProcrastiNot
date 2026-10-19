<?php
/**
 * ProcrastiNot - optional, lightweight gamification (Anti-Cramming XP).
 *
 * Motivational, never punitive or competitive. Students can switch it off in
 * Settings and simply focus on tasks.
 *
 * ---------------------------------------------------------------------
 * RULES (all documented, all server-side)
 * ---------------------------------------------------------------------
 *   Complete a task ................ +10 XP   (once per task)
 *   Finished before the deadline ... +5 XP   (once per task)
 *   A focus session of 5+ minutes .. +3 XP   (once per session)
 *   A shorter focus session (1-4m).. +1 XP   (once per session)
 *
 * Every award is written to xp_transactions with a unique event_key. The
 * database's UNIQUE (user_id, event_key) constraint means a completion can
 * never be rewarded twice - so toggling completed/incomplete and back again
 * earns nothing extra. Missing deadlines never removes XP.
 *
 *   Level = floor(total XP / 100) + 1, so each level is 100 XP.
 *
 * Streaks count *distinct consecutive days* on which at least one task was
 * completed (using the recorded completed_at timestamp).
 */

require_once __DIR__ . '/tasks.php';

const GAMIFICATION_LEVEL_XP = 100;

/** Is gamification switched on for this student? */
function gamification_enabled(int $userId): bool
{
    $stmt = db()->prepare('SELECT xp_enabled FROM users WHERE id = ? LIMIT 1');
    $stmt->execute([$userId]);
    $row = $stmt->fetch();
    return !$row || (int) $row['xp_enabled'] === 1;
}

/** The badge catalog (key => label, icon, description). */
function gamification_badges(): array
{
    return [
        'first_step'    => ['label' => 'First Step',   'icon' => 'footprints',    'description' => 'Completed your first task.'],
        'early_bird'    => ['label' => 'Early Bird',   'icon' => 'sunrise',       'description' => 'Finished a task before its deadline.'],
        'focused_five'  => ['label' => 'Focused Five', 'icon' => 'timer',         'description' => 'Logged five focus sessions.'],
        'focused_hour'  => ['label' => 'Deep Work',    'icon' => 'clock',         'description' => 'Focused for a total of 60 minutes.'],
        'comeback_kid'  => ['label' => 'Comeback',     'icon' => 'life-buoy',     'description' => 'Recovered a task that had gone overdue.'],
        'week_goal'     => ['label' => 'On Target',    'icon' => 'target',        'description' => 'Reached your weekly completion goal.'],
        'streak_3'      => ['label' => 'Three in a Row', 'icon' => 'flame',       'description' => 'Completed tasks three days in a row.'],
    ];
}

/**
 * Add an XP transaction once (idempotent). Returns true when this call
 * actually inserted a new row. Disabled for students who opted out.
 */
function gamification_award(int $userId, ?int $taskId, string $source, int $points, string $eventKey, ?string $note = null): bool
{
    if (!gamification_enabled($userId)) {
        return false;
    }
    $stmt = db()->prepare(
        'INSERT IGNORE INTO xp_transactions
            (user_id, task_id, source, points, event_key, note)
         VALUES (?, ?, ?, ?, ?, ?)'
    );
    $stmt->execute([$userId, $taskId, $source, $points, substr($eventKey, 0, 80), $note]);
    return $stmt->rowCount() > 0;
}

/**
 * Award the completion (+10) and early (+5) rewards for a task that just moved
 * to completed. $task is the row as it looked BEFORE the change.
 */
function gamification_on_status_change(int $userId, array $task, string $newStatus): void
{
    if ($newStatus !== 'completed' || ($task['status'] ?? '') === 'completed') {
        return;
    }
    $taskId = (int) $task['id'];
    gamification_award($userId, $taskId, 'task_complete', 10, 'task:' . $taskId . ':complete', 'Task completed');

    $due = $task['original_due_date'] ?? $task['due_date'] ?? null;
    if ($due !== null && $due !== '' && date('Y-m-d') < $due) {
        gamification_award($userId, $taskId, 'early_bonus', 5, 'task:' . $taskId . ':early', 'Finished early');
    }
    gamification_evaluate_achievements($userId);
}

/** Award XP for a recorded focus session. */
function gamification_reward_focus(int $userId, int $taskId, int $sessionId, int $seconds): void
{
    if ($seconds < 60) {
        return;
    }
    $points = $seconds >= 300 ? 3 : 1;
    gamification_award($userId, $taskId, 'focus_session', $points, 'focus:' . $sessionId, 'Focus session');
    gamification_evaluate_achievements($userId);
}

/** Set a task's status and unlock any rewards when it becomes completed. */
function gamification_apply_status(int $userId, int $taskId, string $status): bool
{
    if (!is_valid_status($status)) {
        return false;
    }
    $task = task_find($userId, $taskId);
    if ($task === null) {
        return false;
    }
    $wasCompleted = ($task['status'] ?? '') === 'completed';
    $ok = task_set_status($userId, $taskId, $status);
    if ($ok && $status === 'completed' && !$wasCompleted) {
        gamification_on_status_change($userId, $task, 'completed');
    }
    return $ok;
}

/** Total XP earned (never negative - this ledger has no penalties). */
function gamification_total_xp(int $userId): int
{
    $stmt = db()->prepare('SELECT COALESCE(SUM(points), 0) AS xp FROM xp_transactions WHERE user_id = ?');
    $stmt->execute([$userId]);
    return (int) $stmt->fetchColumn();
}

/** Distinct completion dates (Y-m-d), newest first. */
function gamification_completion_dates(int $userId): array
{
    $stmt = db()->prepare(
        'SELECT DISTINCT DATE(completed_at) AS d
         FROM tasks
         WHERE user_id = ? AND completed_at IS NOT NULL
         ORDER BY d DESC'
    );
    $stmt->execute([$userId]);
    return array_map(static fn($r) => (string) $r['d'], $stmt->fetchAll());
}

/**
 * Current and longest completion streaks in days. The current streak is still
 * "alive" if the most recent completion was today or yesterday.
 */
function gamification_streaks(int $userId): array
{
    $dates = gamification_completion_dates($userId); // newest first
    if ($dates === []) {
        return ['current' => 0, 'longest' => 0];
    }
    $set = array_fill_keys($dates, true);

    // Longest run of consecutive days.
    $longest = 1;
    $run = 1;
    for ($i = 1; $i < count($dates); $i++) {
        $prev = strtotime($dates[$i - 1]);
        $cur  = strtotime($dates[$i]);
        if (($prev - $cur) === 86400) {
            $run++;
            $longest = max($longest, $run);
        } else {
            $run = 1;
        }
    }

    // Current run counting backwards from today (or yesterday).
    $current = 0;
    $cursor = new DateTime('today');
    if (!isset($set[$cursor->format('Y-m-d')])) {
        $cursor->modify('-1 day');
        if (!isset($set[$cursor->format('Y-m-d')])) {
            return ['current' => 0, 'longest' => $longest];
        }
    }
    while (isset($set[$cursor->format('Y-m-d')])) {
        $current++;
        $cursor->modify('-1 day');
    }
    return ['current' => $current, 'longest' => max($longest, $current)];
}

/** Tasks completed in the current ISO week (by recorded completed_at). */
function gamification_week_completed(int $userId): int
{
    $stmt = db()->prepare(
        'SELECT COUNT(*) FROM tasks
         WHERE user_id = ? AND completed_at IS NOT NULL
           AND YEARWEEK(completed_at, 1) = YEARWEEK(CURDATE(), 1)'
    );
    $stmt->execute([$userId]);
    return (int) $stmt->fetchColumn();
}

/** Weekly goal the student set for themselves. */
function gamification_weekly_goal(int $userId): int
{
    $stmt = db()->prepare('SELECT weekly_goal FROM users WHERE id = ? LIMIT 1');
    $stmt->execute([$userId]);
    $goal = (int) $stmt->fetchColumn();
    return $goal > 0 ? $goal : 5;
}

/** XP earned per week for the last $weeks ISO weeks (zero-filled). */
function gamification_xp_by_week(int $userId, int $weeks = 8): array
{
    $weeks = max(1, min(52, $weeks));
    $stmt = db()->prepare(
        'SELECT YEARWEEK(created_at, 1) AS yw, COALESCE(SUM(points), 0) AS xp
         FROM xp_transactions
         WHERE user_id = ? AND created_at >= DATE_SUB(CURDATE(), INTERVAL ? WEEK)
         GROUP BY YEARWEEK(created_at, 1)'
    );
    $stmt->execute([$userId, $weeks]);
    $map = [];
    foreach ($stmt->fetchAll() as $row) {
        $map[(string) $row['yw']] = (int) $row['xp'];
    }
    $result = [];
    $week = new DateTime('monday this week');
    $week->modify('-' . ($weeks - 1) . ' weeks');
    for ($i = 0; $i < $weeks; $i++) {
        $yw = (int) $week->format('o') * 100 + (int) $week->format('W');
        $result[] = ['label' => $week->format('M j'), 'xp' => $map[(string) $yw] ?? 0];
        $week->modify('+1 week');
    }
    return $result;
}

/** Badge keys this student has earned. */
function gamification_earned_badges(int $userId): array
{
    $stmt = db()->prepare('SELECT badge_key FROM user_achievements WHERE user_id = ?');
    $stmt->execute([$userId]);
    $keys = [];
    foreach ($stmt->fetchAll() as $row) {
        $keys[(string) $row['badge_key']] = true;
    }
    return $keys;
}

/**
 * Check every achievement rule and grant any that are newly satisfied.
 * Returns the list of newly earned badge keys.
 */
function gamification_evaluate_achievements(int $userId): array
{
    if (!gamification_enabled($userId)) {
        return [];
    }
    $earned = gamification_earned_badges($userId);
    $toGrant = [];

    $scalar = static function (string $sql) use ($userId): int {
        $stmt = db()->prepare($sql);
        $stmt->execute([$userId]);
        return (int) $stmt->fetchColumn();
    };

    if ($scalar('SELECT COUNT(*) FROM tasks WHERE user_id = ? AND completed_at IS NOT NULL') >= 1) {
        $toGrant[] = 'first_step';
    }

    if ($scalar('SELECT COUNT(*) FROM focus_sessions WHERE user_id = ?') >= 5) {
        $toGrant[] = 'focused_five';
    }

    if ($scalar('SELECT COALESCE(SUM(actual_seconds),0) FROM focus_sessions WHERE user_id = ?') >= 3600) {
        $toGrant[] = 'focused_hour';
    }

    if ($scalar(
        'SELECT COUNT(*) FROM tasks
          WHERE user_id = ? AND completed_at IS NOT NULL
            AND original_due_date IS NOT NULL
            AND DATE(completed_at) < original_due_date'
    ) >= 1) {
        $toGrant[] = 'early_bird';
    }

    if ($scalar(
        'SELECT COUNT(*) FROM tasks
          WHERE user_id = ? AND completed_at IS NOT NULL
            AND original_due_date IS NOT NULL
            AND DATE(completed_at) > original_due_date'
    ) >= 1) {
        $toGrant[] = 'comeback_kid';
    }

    if (gamification_week_completed($userId) >= gamification_weekly_goal($userId)) {
        $toGrant[] = 'week_goal';
    }

    $streaks = gamification_streaks($userId);
    if ($streaks['current'] >= 3 || $streaks['longest'] >= 3) {
        $toGrant[] = 'streak_3';
    }

    $new = [];
    foreach (array_unique($toGrant) as $key) {
        if (isset($earned[$key])) {
            continue;
        }
        $stmt = db()->prepare(
            'INSERT IGNORE INTO user_achievements (user_id, badge_key) VALUES (?, ?)'
        );
        $stmt->execute([$userId, $key]);
        if ($stmt->rowCount() > 0) {
            $new[] = $key;
        }
    }
    return $new;
}

/** Everything the dashboard/reports need about a student's progress. */
function gamification_stats(int $userId): array
{
    $totalXp = gamification_total_xp($userId);
    $level = intdiv($totalXp, GAMIFICATION_LEVEL_XP) + 1;
    $into = $totalXp % GAMIFICATION_LEVEL_XP;
    $streaks = gamification_streaks($userId);
    $earned = gamification_earned_badges($userId);
    $catalog = gamification_badges();
    $badges = [];
    foreach ($catalog as $key => $info) {
        $badges[$key] = $info + ['earned' => isset($earned[$key])];
    }
    return [
        'enabled'         => gamification_enabled($userId),
        'total_xp'        => $totalXp,
        'level'           => $level,
        'level_xp'        => $into,
        'level_target'    => GAMIFICATION_LEVEL_XP,
        'level_percent'   => (int) round($into * 100 / GAMIFICATION_LEVEL_XP),
        'current_streak'  => $streaks['current'],
        'longest_streak'  => $streaks['longest'],
        'badges'          => $badges,
        'badges_earned'   => count($earned),
        'badges_total'    => count($catalog),
        'week_completed'  => gamification_week_completed($userId),
        'weekly_goal'     => gamification_weekly_goal($userId),
    ];
}
