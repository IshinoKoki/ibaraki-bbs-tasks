<?php
require_once __DIR__.'/../config.php';
header('Content-Type: application/json; charset=utf-8');

$user = current_user();
if(!$user){ echo json_encode(['ok'=>false,'error'=>'login_required']); exit; }

$pdo = get_pdo();
$in  = json_decode(file_get_contents('php://input'), true) ?? [];
$act = $in['action'] ?? '';

try{
  if ($act === 'set_default'){
    $id = (int)($in['id'] ?? 0);
    // 自分の既定を一旦解除 → 指定IDを既定に
    $pdo->prepare("UPDATE saved_views SET is_default=0 WHERE user_id=?")->execute([$user['id']]);
    $pdo->prepare("UPDATE saved_views SET is_default=1 WHERE id=? AND user_id=?")->execute([$id,$user['id']]);
    echo json_encode(['ok'=>true]); exit;
  }
  if ($act === 'delete'){
    $id = (int)($in['id'] ?? 0);
    $pdo->prepare("DELETE FROM saved_views WHERE id=? AND user_id=?")->execute([$id,$user['id']]);
    echo json_encode(['ok'=>true]); exit;
  }
  echo json_encode(['ok'=>false,'error'=>'unknown_action']);
}catch(Throwable $e){
  echo json_encode(['ok'=>false,'error'=>'server_error']);
}
