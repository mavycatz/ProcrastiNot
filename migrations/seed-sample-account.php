<?php
/**
 * ProcrastiNot - sample account data seeder.
 *
 * Fills one account with realistic profile details, tasks, focus sessions,
 * XP and badges so the My Account / Progress pages look alive for a demo.
 * It reuses the app's own gamification helpers, so every XP award and badge
 * obeys the normal rules and stays consistent with the stored data.
 *
 * Usage:
 *   php migrations/seed-sample-account.php [email]
 *
 * Safe to delete. Re-running tops the account up without duplicating XP
 * (awards are idempotent) but will add another set of sample tasks.
 */

require_once dirname(__DIR__) . '/includes/tasks.php';
require_once dirname(__DIR__) . '/includes/focus.php';
require_once dirname(__DIR__) . '/includes/gamification.php';

$email = $argv[1] ?? 'abrenicamarvhil@gmail.com';

$stmt = db()->prepare('SELECT id, name FROM users WHERE email = ? LIMIT 1');
$stmt->execute([$email]);
$user = $stmt->fetch();
if (!$user) {
    fwrite(STDERR, "No account found for {$email}\n");
    exit(1);
}
$uid = (int) $user['id'];
echo "Seeding sample data for #{$uid} {$user['name']} <{$email}>\n";

// ---------------------------------------------------------------------
// 1. Profile details
// ---------------------------------------------------------------------
db()->prepare(
    'UPDATE users
        SET greeting = ?, course = ?, year_level = ?,
            daily_study_minutes = ?, weekly_goal = ?, updated_at = NOW()
      WHERE id = ?'
)->execute(['Ready when you are, Maria?', 'BS Information Technology', 'Year 2', 90, 5, $uid]);

// ---------------------------------------------------------------------
// 2. Categories
// ---------------------------------------------------------------------
$categoryId = static function (string $name) use ($uid): int {
    $find = db()->prepare('SELECT id FROM categories WHERE user_id = ? AND name = ? LIMIT 1');
    $find->execute([$uid, $name]);
    $id = $find->fetchColumn();
    if ($id !== false) {
        return (int) $id;
    }
    db()->prepare('INSERT INTO categories (user_id, name) VALUES (?, ?)')->execute([$uid, $name]);
    return (int) db()->lastInsertId();
};

$catGeneral = $categoryId('General');
$catMath    = $categoryId('Mathematics');
$catScience = $categoryId('Science');

// ---------------------------------------------------------------------
// 3. Tasks
//    completedDaysAgo => drives completed_at (and the completion streak)
//    dueOffset        => due_date relative to today
//    For completed tasks original_due_date = due_date, so a due date in the
//    future counts as "finished early" and a past one counts as "late".
// ---------------------------------------------------------------------
$tasks = [
    // Completed - spread across six consecutive days for a 6-day streak.
    ['Finish statistics assignment', 'Chapter 7 exercises 1-15 on probability.', 'Check answer key for Q9', 'high', 'completed', 2, 90, $catMath, 0, 0, 1],
    ['Read chapter 6: cell division', 'Mitosis and meiosis summary with key terms.', null, 'medium', 'completed', -1, 45, $catScience, 1, 1, 0],
    ['Submit English reflection', 'A short reflection on the assigned novel.', null, 'medium', 'completed', -2, 40, $catGeneral, 2, 2, 0],
    ['Physics problem set', 'Kinematics problems 1-20.', null, 'high', 'completed', -5, 60, $catScience, 3, 3, 0],
    ['Review geometry notes', 'Redo the theorems flagged in class.', null, 'low', 'completed', -4, 30, $catMath, 4, 4, 0],
    ['Update study planner', 'Plan the week around upcoming deadlines.', null, 'low', 'completed', -6, 15, $catGeneral, 5, 5, 0],
    // Open - overdue, due today, in progress and upcoming.
    ['Prepare for calculus quiz', 'Limits and derivatives review.', null, 'high', 'pending', 0, 60, $catMath, null, null, 1],
    ['Draft group project outline', 'Split the deliverable into sections.', 'Message the group tonight', 'medium', 'in_progress', 3, 120, $catGeneral, null, null, 1],
    ['Chemistry lab report', 'Acid-base titration write-up.', null, 'high', 'pending', -2, 75, $catScience, null, null, 0],
    ['Buy a new notebook', null, null, 'low', 'pending', 5, 20, $catGeneral, null, null, 0],
];

$insertTask = db()->prepare(
    'INSERT INTO tasks
        (user_id, category_id, title, description, notes, priority, status,
         due_date, my_day, estimated_minutes, original_due_date, completed_at)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
);

$completed = 0;
foreach ($tasks as $t) {
    [$title, $desc, $notes, $priority, $status, $dueOffset, $est, $cat, $doneDaysAgo, , $myDay] = $t;

    $due = $dueOffset === null
        ? null
        : (new DateTime('today'))->modify(($dueOffset >= 0 ? '+' : '') . $dueOffset . ' days')->format('Y-m-d');
    $doneAt = $doneDaysAgo === null
        ? null
        : (new DateTime('today'))->modify('-' . $doneDaysAgo . ' days')->format('Y-m-d H:i:s');

    $insertTask->execute([
        $uid, $cat, $title,
        $desc, $notes, $priority, $status,
        $due, $myDay, $est,
        $due, $doneAt,
    ]);
    $taskId = (int) db()->lastInsertId();

    if ($status === 'completed') {
        $completed++;
        gamification_award($uid, $taskId, 'task_complete', 10, 'task:' . $taskId . ':complete', 'Task completed');
        if ($due !== null && $doneAt !== null && date('Y-m-d', strtotime($doneAt)) < $due) {
            gamification_award($uid, $taskId, 'early_bonus', 5, 'task:' . $taskId . ':early', 'Finished early');
        }
    }
}

// ---------------------------------------------------------------------
// 4. Focus sessions ("Just 5 Minutes"), spread across recent days.
// ---------------------------------------------------------------------
$insertFocus = db()->prepare(
    'INSERT INTO focus_sessions
        (task_id, user_id, planned_minutes, actual_seconds, note, started_at, ended_at)
     VALUES (?, ?, ?, ?, ?, ?, ?)'
);
$focusSeconds = [900, 900, 600, 900, 1200, 300, 900, 600, 900, 900, 600, 900];
$focusTask = (int) db()->query('SELECT id FROM tasks WHERE user_id = ' . $uid . " AND status = 'completed' ORDER BY id LIMIT 1")->fetchColumn();

$sessions = 0;
foreach ($focusSeconds as $i => $seconds) {
    $daysAgo = intdiv($i, 2);
    $start = (new DateTime('today'))->modify('-' . $daysAgo . ' days')->setTime(18, 0);
    $startedAt = $start->format('Y-m-d H:i:s');
    $endedAt = (clone $start)->modify('+' . $seconds . ' seconds')->format('Y-m-d H:i:s');

    $insertFocus->execute([
        $focusTask, $uid, (int) round($seconds / 60), $seconds, 'Sample focus session', $startedAt, $endedAt,
    ]);
    $sessionId = (int) db()->lastInsertId();
    gamification_reward_focus($uid, $focusTask, $sessionId, $seconds);
    $sessions++;
}

// ---------------------------------------------------------------------
// 5. Grant any badges the seeded activity satisfies.
// ---------------------------------------------------------------------
$newBadges = gamification_evaluate_achievements($uid);
$gam = gamification_stats($uid);
$streaks = gamification_streaks($uid);

printf(
    "Done. tasks=%d (completed=%d), focus sessions=%d, XP=%d (level %d), streak=%d, badges=%d%s\n",
    count($tasks),
    $completed,
    $sessions,
    $gam['total_xp'],
    $gam['level'],
    $streaks['current'],
    $gam['badges_earned'],
    $newBadges ? ' [' . implode(', ', $newBadges) . ']' : ''
);
