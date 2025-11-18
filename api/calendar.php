<?php
// /tasks/api/calendar.php
require_once __DIR__ . '/../config.php';

header('Content-Type: application/json; charset=utf-8');

try {
    $pdo = get_pdo();
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'db_connect']);
    exit;
}

$user = current_user();
if (!$user) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'login_required']);
    exit;
}
$uid = (int)$user['id'];

// --- パラメータ（YYYY-MM / team_id） ---
$year  = isset($_GET['year'])  ? (int)$_GET['year']  : (int)date('Y');
$month = isset($_GET['month']) ? (int)$_GET['month'] : (int)date('n');
$team_id = isset($_GET['team_id']) ? (int)$_GET['team_id'] : null;

// 月の開始・終了日
$start = sprintf('%04d-%02d-01', $year, $month);
$end   = date('Y-m-d', strtotime("$start +1 month")); // 翌月1日

$where = [
    't.deleted_at IS NULL',
    't.due_date >= :start',
    't.due_date < :end',
];
$params = [
    ':start' => $start,
    ':end'   => $end,
];

if ($team_id !== null && $team_id > 0) {
    $where[] = 't.team_id = :team_id';
    $params[':team_id'] = $team_id;
}

// 必要なら「自分の所属チームだけ」などに制限も可能

$sql = "
  SELECT
    t.id,
    t.title,
    t.due_date,
    ts.color AS status_color,
    tp.color AS priority_color
  FROM tasks t
  LEFT JOIN task_statuses   ts ON t.status_id   = ts.id
  LEFT JOIN task_priorities tp ON t.priority_id = tp.id
  WHERE " . implode(' AND ', $where) . "
  ORDER BY t.due_date ASC, t.id ASC
";

$st = $pdo->prepare($sql);
$st->execute($params);
$rows = $st->fetchAll(PDO::FETCH_ASSOC);

// カレンダー用イベントに整形
$events = [];
foreach ($rows as $r) {
    $events[] = [
        'id'    => (int)$r['id'],
        'title' => $r['title'],
        'date'  => $r['due_date'],               // YYYY-MM-DD
        'color' => $r['priority_color'] ??
                   $r['status_color'] ??
                   '#2563eb', // なければ青系
    ];
}

echo json_encode(['ok' => true, 'events' => $events], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
