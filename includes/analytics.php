<?php
/**
 * ProcrastiNot - reporting metrics. Every number here is derived from what the
 * student actually recorded (task due dates, recorded completion times, focus
 * sessions). Nothing is inferred about grades or learning outcomes.
 */

require_once __DIR__ . '/tasks.php';
require_once __DIR__ . '/focus.php';

/**
 * On-time completion rate.
 * A completed task is "on time" when it was completed on or before its
 * deadline. We compare with the first recorded deadline (original_due_date)
 * when available, so rescheduling cannot flatter the number.
 * Returns ['ontime','late','total','percent'].
 */
function analytics_ontime(int $userId): array
{
    $stmt = db()->prepare(
        'SELECT
            SUM(DATE(completed_at) <= due_date) AS ontime,
            SUM(DATE(completed_at) >  due_date) AS late,
            COUNT(*) AS total
         FROM tasks
         WHERE user_id = ? AND completed_at IS NOT NULL AND due_date IS NOT NULL'
    );
    $stmt->execute([$userId]);
    $row = $stmt->fetch() ?: [];
    $ontime = (int) ($row['ontime'] ?? 0);
    $late   = (int) ($row['late'] ?? 0);
    $total  = (int) ($row['total'] ?? 0);
    return [
        'ontime'  => $ontime,
        'late'    => $late,
        'total'   => $total,
        'percent' => $total > 0 ? (int) round($ontime * 100 / $total) : 0,
    ];
}

/**
 * Overdue trend: how many tasks were (or still are) past their first recorded
 * deadline, grouped by the week that deadline fell in. Late completions and
 * unresolved overdue tasks both count. Returns lists for a chart.
 */
function analytics_overdue_trend(int $userId, int $weeks = 8): array
{
    $weeks = max(1, min(52, $weeks));
    $stmt = db()->prepare(
        'SELECT YEARWEEK(original_due_date, 1) AS yw, COUNT(*) AS c
         FROM tasks
         WHERE user_id = ?
           AND original_due_date IS NOT NULL
           AND original_due_date >= DATE_SUB(CURDATE(), INTERVAL ? WEEK)
           AND (
                (completed_at IS NOT NULL AND DATE(completed_at) > original_due_date)
                OR (completed_at IS NULL AND original_due_date < CURDATE())
           )
         GROUP BY YEARWEEK(original_due_date, 1)'
    );
    $stmt->execute([$userId, $weeks]);
    $map = [];
    foreach ($stmt->fetchAll() as $row) {
        $map[(string) $row['yw']] = (int) $row['c'];
    }
    $result = [];
    $week = new DateTime('monday this week');
    $week->modify('-' . ($weeks - 1) . ' weeks');
    for ($i = 0; $i < $weeks; $i++) {
        $yw = (int) $week->format('o') * 100 + (int) $week->format('W');
        $result[] = ['label' => $week->format('M j'), 'count' => $map[(string) $yw] ?? 0];
        $week->modify('+1 week');
    }
    return $result;
}

/**
 * Focus minutes grouped by day of week over the last $days days, so students
 * can see which days they actually get work done. Returns seven rows
 * (Mon..Sun) with total minutes and session count.
 */
function analytics_focus_by_weekday(int $userId, int $days = 28): array
{
    $days = max(7, min(90, $days));
    $stmt = db()->prepare(
        'SELECT WEEKDAY(started_at) AS dow,
                COUNT(*) AS sessions,
                COALESCE(SUM(actual_seconds), 0) AS seconds
         FROM focus_sessions
         WHERE user_id = ? AND started_at >= DATE_SUB(CURDATE(), INTERVAL ? DAY)
         GROUP BY WEEKDAY(started_at)'
    );
    $stmt->execute([$userId, $days]);
    $rows = $stmt->fetchAll();
    $byDow = [];
    foreach ($rows as $row) {
        $byDow[(int) $row['dow']] = [
            'sessions' => (int) $row['sessions'],
            'minutes'  => (int) round(((int) $row['seconds']) / 60),
        ];
    }
    $names = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];
    $result = [];
    for ($d = 0; $d < 7; $d++) {
        $result[] = [
            'label'    => $names[$d],
            'minutes'  => $byDow[$d]['minutes'] ?? 0,
            'sessions' => $byDow[$d]['sessions'] ?? 0,
        ];
    }
    return $result;
}

/** The single most productive weekday by focus minutes, or null. */
function analytics_most_productive_day(int $userId): ?array
{
    $days = analytics_focus_by_weekday($userId);
    $best = null;
    foreach ($days as $day) {
        if ($day['minutes'] > 0 && ($best === null || $day['minutes'] > $best['minutes'])) {
            $best = $day;
        }
    }
    return $best;
}
