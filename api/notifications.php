<?php
require_once __DIR__ . '/../config.php';

header('Content-Type: application/json; charset=utf-8');

$user = current_user();
if (!$user){
    echo json_encode(['ok'=>false, 'error'=>'not_logged_in']);
    exit;
}
$uid = (int)$user['id'];

$pdo = get_pdo();

$raw = file_get_contents("php://input");
$data = json_decode($raw, true) ?? [];

$action = $data['action'] ?? '';

/* ============================
   1. 通知一覧の取得
   ============================ */
if ($action === 'list'){
    $st = $pdo->prepare("
    SELECT
      n.*,
      u.display_name AS sender_name,
      t.team_id
    FROM notifications n
    JOIN users u ON u.id = n.sender_id
    LEFT JOIN tasks t ON t.id = n.task_id
    WHERE n.user_id = ?
    ORDER BY n.created_at DESC
");
$st->execute([$uid]);
echo json_encode(['ok'=>true, 'notifications'=>$st->fetchAll()]);
exit;

}

/* ============================
   2. 既読処理
   ============================ */
if ($action === 'read'){
    $id = (int)($data['id'] ?? 0);
    $st = $pdo->prepare("UPDATE notifications SET is_read=1 WHERE id=? AND user_id=?");
    $st->execute([$id, $uid]);

    echo json_encode(['ok'=>true]);
    exit;
}

echo json_encode(['ok'=>false, 'error'=>'unknown_action']);
