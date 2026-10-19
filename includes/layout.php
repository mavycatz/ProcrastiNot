<?php
/**
 * ProcrastiNot - shared navigation data.
 *
 * The sidebar is split into two clearly separated groups: the primary
 * productivity destinations, and the account controls shown near the bottom.
 * Each page passes its own id so the active item can be marked.
 */

/** Primary destinations, in order. */
function primary_nav_items(string $current): array
{
    $items = [
        ['id' => 'dashboard', 'label' => 'Home',      'icon' => 'home',            'href' => 'dashboard.php'],
        ['id' => 'tasks',     'label' => 'Tasks',     'icon' => 'list-todo',       'href' => 'tasks.php'],
        ['id' => 'calendar',  'label' => 'Calendar',  'icon' => 'calendar-days',   'href' => 'calendar.php'],
        ['id' => 'reports',   'label' => 'Progress',  'icon' => 'trending-up',     'href' => 'reports.php'],
        ['id' => 'comeback',  'label' => 'Comeback',  'icon' => 'life-buoy',       'href' => 'comeback.php'],
    ];
    foreach ($items as &$item) {
        $item['active'] = $item['id'] === $current;
    }
    return $items;
}

/** Account-level destinations shown in the lower sidebar group. */
function account_nav_items(string $current): array
{
    $items = [
        ['id' => 'account',  'label' => 'My Account', 'icon' => 'user-round', 'href' => 'account.php'],
        ['id' => 'settings', 'label' => 'Settings',   'icon' => 'settings',   'href' => 'settings.php'],
    ];
    foreach ($items as &$item) {
        $item['active'] = $item['id'] === $current;
    }
    return $items;
}

/** Grouped navigation for the desktop sidebar. */
function nav_groups(string $current): array
{
    return [
        ['label' => 'Workspace', 'items' => primary_nav_items($current)],
        ['label' => 'Account',   'items' => account_nav_items($current)],
    ];
}

/** Flat list of every destination (kept for any simple consumers). */
function nav_items(string $current): array
{
    return array_merge(primary_nav_items($current), account_nav_items($current));
}

/** The four fixed mobile bottom-navigation destinations. */
function bottom_nav_items(string $current): array
{
    $ids = ['dashboard', 'tasks', 'calendar', 'reports'];
    return array_values(array_filter(
        primary_nav_items($current),
        static fn(array $item): bool => in_array($item['id'], $ids, true)
    ));
}

/** Secondary destinations surfaced in the mobile "More" / account menu. */
function more_menu_items(string $current): array
{
    $items = array_merge(primary_nav_items($current), account_nav_items($current));
    $ids = ['comeback', 'account', 'settings'];
    return array_values(array_filter(
        $items,
        static fn(array $item): bool => in_array($item['id'], $ids, true)
    ));
}
