<?php
require_once __DIR__.'/../config.php';
header('Content-Type: application/json; charset=utf-8');

$user = current_user();
if (!$user) { echo json_encode(['ok'=>false,'error'=>'login_required']); exit; }

$pdo = get_pdo();
$in  = json_decode(file_get_contents('php://input'), true) ?? [];
$act = $in['action'] ?? '';

function nullIfEmpty($v){
  if ($v === '' || $v === null) return null;
  if ($v === '__unset__') return null;
  if ($v === 0 || $v === '0') return null;
  return $v;
}
function where_in_or_null(&$where,&$params,$col,$ids,$withNull){
  if (!empty($ids) || $withNull){
    $w = [];
    if (!empty($ids)){
      $place = implode(',', array_fill(0,count($ids),'?'));
      $w[] = "$col IN ($place)";
      foreach($ids as $id){ $params[] = (int)$id; }
    }
    if ($withNull){ $w[] = "$col IS NULL"; }
    $where[] = '('.implode(' OR ',$w).')';
  }
}

try{
  if ($act === 'list'){
    $q = trim($in['q'] ?? '');

    $status_ids    = $in['status_ids']    ?? [];
    $status_null   = !empty($in['status_null']);
    $priority_ids  = $in['priority_ids']  ?? [];
    $priority_null = !empty($in['priority_null']);
    $type_ids      = $in['type_ids']      ?? [];
    $type_null     = !empty($in['type_null']);
    $assignee_ids  = $in['assignee_ids']  ?? [];
    $assignee_null = !empty($in['assignee_null']);

    $where  = [];
    $params = [];

    if ($q !== ''){
      $where[] = "(t.title LIKE ?)";
      $params[] = "%{$q}%";
    }
    where_in_or_null($where,$params,'t.status_id',   $status_ids,$status_null);
    where_in_or_null($where,$params,'t.priority_id', $priority_ids,$priority_null);
    where_in_or_null($where,$params,'t.type_id',     $type_ids,$type_null);
    where_in_or_null($where,$params,'t.assignee_id', $assignee_ids,$assignee_null);

    $sql = "
      SELECT
        t.id,t.title,t.assignee_id,t.assignee_name,t.due_date,t.updated_at,
        s.name AS status_name, s.color AS status_color,
        p.name AS priority_name, p.color AS priority_color,
        ty.name AS type_name, ty.color AS type_color
      FROM tasks t
      LEFT JOIN task_statuses s   ON s.id=t.status_id
      LEFT JOIN task_priorities p ON p.id=t.priority_id
      LEFT JOIN task_types ty     ON ty.id=t.type_id
    ";
    if ($where){ $sql .= ' WHERE '.implode(' AND ', $where); }
    $sql .= ' ORDER BY t.updated_at DESC, t.id DESC';

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    echo json_encode(['ok'=>true,'rows'=>$stmt->fetchAll(PDO::FETCH_ASSOC)]);
    exit;
  }

  if ($act === 'create'){
    $title       = trim($in['title'] ?? '');
    if ($title === ''){ echo json_encode(['ok'=>false,'error'=>'title_required']); exit; }
    $assignee_id = nullIfEmpty($in['assignee_id'] ?? null);
    $status_id   = nullIfEmpty($in['status_id']   ?? null);
    $priority_id = nullIfEmpty($in['priority_id'] ?? null);
    $type_id     = nullIfEmpty($in['type_id']     ?? null);
    $due_date    = trim($in['due_date'] ?? '');
    $due_date    = ($due_date==='') ? null : $due_date;

    // assignee_name は users から補完（NULL 可）
    $assignee_name = null;
    if ($assignee_id){
      $u = $pdo->prepare("SELECT display_name FROM users WHERE id=?");
      $u->execute([(int)$assignee_id]);
      $assignee_name = $u->fetchColumn() ?: null;
    }

    $stmt = $pdo->prepare("
      INSERT INTO tasks
        (team_id,title,status_id,priority_id,type_id,assignee_id,assignee_name,due_date,created_at,updated_at,updated_by)
      VALUES
        (1,?,?,?,?,?,?,?,NOW(),NOW(),?)
    ");
    $stmt->execute([
      $status_id,$priority_id,$type_id,$assignee_id,$assignee_name,$due_date,(int)$user['id']
    ]);
    echo json_encode(['ok'=>true]); exit;
  }

  if ($act === 'update'){
    $id          = (int)($in['id'] ?? 0);
    if ($id<=0){ echo json_encode(['ok'=>false,'error'=>'bad_id']); exit; }
    $title       = trim($in['title'] ?? '');
    $assignee_id = nullIfEmpty($in['assignee_id'] ?? null);
    $status_id   = nullIfEmpty($in['status_id']   ?? null);
    $priority_id = nullIfEmpty($in['priority_id'] ?? null);
    $type_id     = nullIfEmpty($in['type_id']     ?? null);
    $due_date    = trim($in['due_date'] ?? '');
    $due_date    = ($due_date==='') ? null : $due_date;

    $assignee_name = null;
    if ($assignee_id){
      $u = $pdo->prepare("SELECT display_name FROM users WHERE id=?");
      $u->execute([(int)$assignee_id]);
      $assignee_name = $u->fetchColumn() ?: null;
    }

    $stmt = $pdo->prepare("
      UPDATE tasks SET
        title=?, status_id=?, priority_id=?, type_id=?,
        assignee_id=?, assignee_name=?, due_date=?,
        updated_at=NOW(), updated_by=?
      WHERE id=?
    ");
    $stmt->execute([
      $title,$status_id,$priority_id,$type_id,$assignee_id,$assignee_name,$due_date,(int)$user['id'],$id
    ]);
    echo json_encode(['ok'=>true]); exit;
  }

  if ($act === 'delete'){
    $id=(int)($in['id'] ?? 0);
    if ($id<=0){ echo json_encode(['ok'=>false,'error'=>'bad_id']); exit; }
    $pdo->prepare("DELETE FROM tasks WHERE id=?")->execute([$id]);
    echo json_encode(['ok'=>true]); exit;
  }

  echo json_encode(['ok'=>false,'error'=>'unknown_action']);
}catch(Throwable $e){
  echo json_encode(['ok'=>false,'error'=>'server_error']);
}
