<?php

/**
 * Normalize raw user id inputs (strings, ints) into a unique positive-int array while keeping order.
 *
 * @param array $raw
 * @return int[]
 */
function normalize_user_ids(array $raw): array
{
    $normalized = [];
    foreach ($raw as $value) {
        if ($value === '' || $value === null) {
            continue;
        }
        $id = (int)$value;
        if ($id <= 0) {
            continue;
        }
        if (!in_array($id, $normalized, true)) {
            $normalized[] = $id;
        }
    }
    return $normalized;
}

/**
 * Fetch display names for user ids.
 *
 * @param PDO   $pdo
 * @param int[] $ids
 * @return array<int,string>
 */
function fetch_user_map(PDO $pdo, array $ids): array
{
    if (empty($ids)) {
        return [];
    }
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $pdo->prepare("SELECT id, display_name FROM users WHERE id IN ($placeholders)");
    $stmt->execute($ids);
    $map = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $map[(int)$row['id']] = $row['display_name'] ?? '';
    }
    return $map;
}

/**
 * Sync the task_assignees table with provided user ids.
 *
 * @param PDO   $pdo
 * @param int   $task_id
 * @param int[] $user_ids
 * @return array{list: array<int, array{id:int,name:string}>, primary: ?array{id:int,name:string}}
 */
function sync_task_assignees(PDO $pdo, int $task_id, array $user_ids): array
{
    $user_ids = normalize_user_ids($user_ids);
    $pdo->prepare('DELETE FROM task_assignees WHERE task_id = ?')->execute([$task_id]);

    if (empty($user_ids)) {
        return ['list' => [], 'primary' => null];
    }

    $userMap = fetch_user_map($pdo, $user_ids);
    $ordered = [];
    foreach ($user_ids as $uid) {
        if (!isset($userMap[$uid])) {
            continue;
        }
        $ordered[] = ['id' => $uid, 'name' => $userMap[$uid] ?? '（不明）'];
    }

    if (empty($ordered)) {
        return ['list' => [], 'primary' => null];
    }

    $stmt = $pdo->prepare('
        INSERT INTO task_assignees (task_id, user_id, is_primary, created_at)
        VALUES (:task_id, :user_id, :is_primary, NOW())
    ');
    foreach ($ordered as $index => $info) {
        $stmt->execute([
            ':task_id'    => $task_id,
            ':user_id'    => $info['id'],
            ':is_primary' => ($index === 0) ? 1 : 0,
        ]);
    }

    return [
        'list'    => array_map(
            fn($row) => ['id' => $row['id'], 'name' => $row['name']],
            $ordered
        ),
        'primary' => $ordered[0] ?? null,
    ];
}

/**
 * Fetch assignees for multiple tasks at once.
 *
 * @param PDO   $pdo
 * @param int[] $task_ids
 * @return array<int, array<int, array{user_id:int, display_name:string}>>
 */
function fetch_task_assignees(PDO $pdo, array $task_ids): array
{
    if (empty($task_ids)) {
        return [];
    }
    $placeholders = implode(',', array_fill(0, count($task_ids), '?'));
    $stmt = $pdo->prepare("
        SELECT ta.task_id, ta.user_id, COALESCE(u.display_name, '(未登録)') AS display_name, ta.is_primary
        FROM task_assignees ta
        LEFT JOIN users u ON u.id = ta.user_id
        WHERE ta.task_id IN ($placeholders)
        ORDER BY ta.task_id, ta.is_primary DESC, ta.id
    ");
    $stmt->execute($task_ids);

    $result = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $tid = (int)$row['task_id'];
        if (!isset($result[$tid])) {
            $result[$tid] = [];
        }
        $result[$tid][] = [
            'user_id'      => (int)$row['user_id'],
            'display_name' => $row['display_name'] ?? '(未登録)',
        ];
    }
    return $result;
}

