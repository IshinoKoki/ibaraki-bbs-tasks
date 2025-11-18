<?php
// /tasks/api/views.php
require_once __DIR__ . '/../config.php';

header('Content-Type: application/json; charset=utf-8');

$user = current_user();
if (!$user){
  echo json_encode(['ok'=>false,'error'=>'login_required']);
  exit;
}
$uid = (int)$user['id'];
$pdo = get_pdo();

$in  = json_decode(file_get_contents('php://input'), true) ?? [];
$act = $in['action'] ?? '';

$team_id = isset($in['team_id']) ? (int)$in['team_id'] : null;

function list_views(PDO $pdo, int $uid, ?int $team_id){
  $sql = 'SELECT id,name,is_default,params
          FROM user_saved_views
          WHERE user_id = :uid AND (team_id = :tid OR team_id IS NULL)
          ORDER BY is_default DESC, name ASC';
  $st = $pdo->prepare($sql);
  $st->execute([':uid'=>$uid, ':tid'=>$team_id]);
  $rows = $st->fetchAll(PDO::FETCH_ASSOC);
  foreach ($rows as &$r){
    $r['id'] = (int)$r['id'];
    $r['is_default'] = (bool)$r['is_default'];
  }
  return $rows;
}

try{
  if ($act === 'list'){
    $views = list_views($pdo, $uid, $team_id);
    echo json_encode(['ok'=>true,'views'=>$views]);
    exit;
  }

  if ($act === 'save'){
    $name = trim($in['name'] ?? '');
    if ($name === ''){
      echo json_encode(['ok'=>false,'error'=>'name_required']);
      exit;
    }
    $params = $in['params'] ?? [];
    if (!is_array($params)) $params = [];
    $paramsJson = json_encode($params, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);

    $sql = 'INSERT INTO user_saved_views(user_id,team_id,name,params,is_default,created_at)
            VALUES(:uid,:tid,:name,:params,0,NOW())';
    $st  = $pdo->prepare($sql);
    $st->execute([
      ':uid'=>$uid,
      ':tid'=>$team_id,
      ':name'=>$name,
      ':params'=>$paramsJson
    ]);

    $views = list_views($pdo, $uid, $team_id);
    echo json_encode(['ok'=>true,'views'=>$views]);
    exit;
  }

  if ($act === 'set_default'){
    $id = (int)($in['id'] ?? 0);
    if ($id <= 0){
      echo json_encode(['ok'=>false,'error'=>'bad_id']);
      exit;
    }
    $pdo->prepare('UPDATE user_saved_views SET is_default=0 WHERE user_id=:uid AND (team_id=:tid OR team_id IS NULL)')
        ->execute([':uid'=>$uid, ':tid'=>$team_id]);
    $pdo->prepare('UPDATE user_saved_views SET is_default=1 WHERE id=:id AND user_id=:uid')
        ->execute([':id'=>$id, ':uid'=>$uid]);

    $views = list_views($pdo, $uid, $team_id);
    echo json_encode(['ok'=>true,'views'=>$views]);
    exit;
  }

  if ($act === 'delete'){
    $id = (int)($in['id'] ?? 0);
    if ($id <= 0){
      echo json_encode(['ok'=>false,'error'=>'bad_id']);
      exit;
    }
    $pdo->prepare('DELETE FROM user_saved_views WHERE id=:id AND user_id=:uid')
        ->execute([':id'=>$id, ':uid'=>$uid]);

    $views = list_views($pdo, $uid, $team_id);
    echo json_encode(['ok'=>true,'views'=>$views]);
    exit;
  }

  echo json_encode(['ok'=>false,'error'=>'unknown_action']);
}catch(Throwable $e){
  echo json_encode(['ok'=>false,'error'=>'server_error']);
}
