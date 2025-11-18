<?php
// /tasks/index.php
require_once __DIR__ . '/config.php';

$pdo = get_pdo();
if (!current_user()) { header('Location: login.php'); exit; }
$user = current_user();
$uid  = (int)$user['id'];

$message = '';
$error   = '';
$tasks   = [];
$team_id = null;

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

function normalize_ids($input): array {
  if (!is_array($input)) return [];
  $ids = [];
  foreach ($input as $v) {
    $v = (int)$v;
    if ($v > 0 && !in_array($v, $ids, true)) $ids[] = $v;
  }
  return $ids;
}

function fetch_user_names(PDO $pdo, array $ids): array {
  if (!$ids) return [];
  $in  = implode(',', array_fill(0, count($ids), '?'));
  $st  = $pdo->prepare("SELECT id, display_name FROM users WHERE id IN ($in)");
  $st->execute($ids);
  $map = [];
  while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
    $map[(int)$r['id']] = (string)($r['display_name'] ?? '');
  }
  return $map;
}

function sync_task_assignees(PDO $pdo, int $task_id, array $user_ids): void {
  $pdo->prepare('DELETE FROM task_assignees WHERE task_id = :tid')->execute([':tid' => $task_id]);
  if (!$user_ids) return;
  $st = $pdo->prepare('INSERT INTO task_assignees (task_id, user_id, is_primary) VALUES (:tid, :uid, :primary)');
  foreach ($user_ids as $idx => $uid) {
    $st->execute([
      ':tid'     => $task_id,
      ':uid'     => $uid,
      ':primary' => $idx === 0 ? 1 : 0,
    ]);
  }
}

function get_task_assignee_labels(PDO $pdo, int $task_id): array {
  $st = $pdo->prepare('SELECT ta.user_id, u.display_name FROM task_assignees ta LEFT JOIN users u ON ta.user_id = u.id WHERE ta.task_id = :tid ORDER BY ta.is_primary DESC, u.display_name ASC, ta.user_id ASC');
  $st->execute([':tid' => $task_id]);
  $labels = [];
  while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
    $labels[] = $r['display_name'] ?? '（不明）';
  }
  return $labels;
}

// 変更履歴用の共通関数
function add_task_log(PDO $pdo, int $task_id, int $user_id, string $action, ?string $field, ?string $old, ?string $new){
  $st = $pdo->prepare("
    INSERT INTO task_logs (task_id, user_id, action, field, old_value, new_value, created_at)
    VALUES (:task_id, :user_id, :action, :field, :old, :new, NOW())
  ");
  $st->execute([
    ':task_id' => $task_id,
    ':user_id' => $user_id,
    ':action'  => $action,
    ':field'   => $field,
    ':old'     => $old,
    ':new'     => $new,
  ]);
}

/* =========================
   チーム取得
   ========================= */
$teamsList = $pdo->query('SELECT id, name FROM teams ORDER BY id')->fetchAll();
if (empty($teamsList)) {
  $error = 'teams テーブルにチームが登録されていません。管理者に連絡してください。';
} else {
  $team_id = isset($_GET['team_id']) ? (int)$_GET['team_id'] : (int)$teamsList[0]['id'];

  /* =========================
     マスタ & 保存ビュー取得
     ========================= */
  $statuses   = $pdo->query('SELECT id, name, color FROM task_statuses   ORDER BY sort_order, id')->fetchAll();
  $priorities = $pdo->query('SELECT id, name, color FROM task_priorities ORDER BY sort_order, id')->fetchAll();
  $types      = $pdo->query('SELECT id, name, color FROM task_types      ORDER BY sort_order, id')->fetchAll();
  $usersList  = $pdo->query('SELECT id, display_name FROM users ORDER BY display_name, id')->fetchAll();
  $userNameMap = [];
  foreach ($usersList as $u) {
    $userNameMap[(int)$u['id']] = $u['display_name'];
  }

  $viewsSt = $pdo->prepare('SELECT id, name, is_default FROM user_saved_views WHERE user_id=:uid AND (team_id=:tid OR team_id IS NULL) ORDER BY is_default DESC, name ASC');
  $viewsSt->execute([':uid'=>$uid, ':tid'=>$team_id]);
  $savedViews = $viewsSt->fetchAll();

  /* =========================
     ビュー関連 POST（保存/適用/削除/既定）
     ========================= */
  if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'save_view') {
      $view_name = trim($_POST['view_name'] ?? '');
      if ($view_name === '') {
        $error = 'ビュー名を入力してください。';
      } else {
        $params = [];
        if (isset($_POST['param_q']))                 $params['q'] = trim($_POST['param_q']);
        if (isset($_POST['param_sort']))              $params['sort'] = $_POST['param_sort'];
        if (isset($_POST['param_due_from']))          $params['due_from'] = $_POST['param_due_from'];
        if (isset($_POST['param_due_to']))            $params['due_to']   = $_POST['param_due_to'];
        if (!empty($_POST['param_assignee_ids']) && is_array($_POST['param_assignee_ids'])) {
          $params['assignee_ids'] = array_values(array_unique(array_map('intval', $_POST['param_assignee_ids'])));
        }
        if (!empty($_POST['param_status_ids']) && is_array($_POST['param_status_ids'])) {
@@ -106,129 +155,158 @@ if (empty($teamsList)) {
      }

    } elseif ($action === 'delete_view' && !empty($_POST['view_id'])) {
      $vid = (int)$_POST['view_id'];
      $pdo->prepare('DELETE FROM user_saved_views WHERE id=:id AND user_id=:uid')
          ->execute([':id'=>$vid, ':uid'=>$uid]);
      $message = 'ビューを削除しました。';

    } elseif ($action === 'set_default_view' && !empty($_POST['view_id'])) {
      $vid = (int)$_POST['view_id'];
      $pdo->prepare('UPDATE user_saved_views SET is_default=0 WHERE user_id=:uid AND (team_id=:tid OR team_id IS NULL)')
          ->execute([':uid'=>$uid, ':tid'=>$team_id]);
      $pdo->prepare('UPDATE user_saved_views SET is_default=1 WHERE id=:id AND user_id=:uid')
          ->execute([':id'=>$vid, ':uid'=>$uid]);
      $message = '既定ビューを更新しました。';
    }
  }

  /* =========================
     タスク add/update/delete
     ========================= */
  if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'add') {
      $title       = trim($_POST['title'] ?? '');
      $assignee_id = ($_POST['assignee_id'] ?? '') !== '' ? (int)$_POST['assignee_id'] : null;
      $title          = trim($_POST['title'] ?? '');
      $rawAssigneeIds = normalize_ids($_POST['assignee_ids'] ?? []);
      $assigneeNames  = fetch_user_names($pdo, $rawAssigneeIds);
      $assignee_ids   = [];
      foreach ($rawAssigneeIds as $aid) {
        if (isset($assigneeNames[$aid])) $assignee_ids[] = $aid;
      }
      $primary_assignee_id = $assignee_ids[0] ?? null;
      $status_id   = ($_POST['status_id']   ?? '') !== '' ? (int)$_POST['status_id']   : null; // ← 未設定許可
      $due         = $_POST['due_date'] ?? '';
      $priority_id = isset($_POST['priority_id']) && $_POST['priority_id']!=='' ? (int)$_POST['priority_id'] : null;
      $type_id     = isset($_POST['type_id'])     && $_POST['type_id']!==''     ? (int)$_POST['type_id']     : null;

      if ($title === '') {
        $error = 'タスク名を入力してください。';
      } else {
        $now = date('Y-m-d H:i:s');
        $assignee_name = null;
        if ($assignee_id !== null) {
          $st = $pdo->prepare('SELECT display_name FROM users WHERE id=:id');
          $st->execute([':id'=>$assignee_id]);
          if ($r = $st->fetch()) $assignee_name = $r['display_name']; else $assignee_id = $assignee_name = null;
        }
        $assignee_name = ($primary_assignee_id !== null && isset($assigneeNames[$primary_assignee_id]))
          ? $assigneeNames[$primary_assignee_id]
          : null;
        $pdo->prepare(
          'INSERT INTO tasks
            (team_id, title, status_id, assignee_id, assignee_name,
             due_date, priority_id, type_id, description, url,
             updated_at, created_at, updated_by)
           VALUES
            (:team_id,:title,:status_id,:assignee_id,:assignee_name,
             :due_date,:priority_id,:type_id,NULL,NULL,
             :updated_at,:created_at,:updated_by)'
        )->execute([
          ':team_id'=>$team_id, ':title'=>$title, ':status_id'=>$status_id,
          ':assignee_id'=>$assignee_id, ':assignee_name'=>$assignee_name,
          ':assignee_id'=>$primary_assignee_id, ':assignee_name'=>$assignee_name,
          ':due_date'=>$due!=='' ? $due : null,
          ':priority_id'=>$priority_id, ':type_id'=>$type_id,
          ':updated_at'=>$now, ':created_at'=>$now, ':updated_by'=>$uid
        ]);

        // 作成ログ
        $newId = (int)$pdo->lastInsertId();
        sync_task_assignees($pdo, $newId, $assignee_ids);
        add_task_log($pdo, $newId, $uid, 'create', null, null, 'タスクを作成');

        $message = 'タスクを追加しました。';
      }

    } elseif ($action === 'update' && isset($_POST['task_id'], $_POST['field'])) {
      $isAjax = isset($_POST['ajax']) && $_POST['ajax'] === '1';
      $task_id = (int)$_POST['task_id'];
      $field   = $_POST['field'];
      $value   = $_POST['value'] ?? '';
      $allowed = ['title','assignee_name','assignee_id','status_id','priority_id','type_id','due_date','description','url'];
      $allowed = ['title','assignee_name','assignee_id','assignees','status_id','priority_id','type_id','due_date','description','url'];

      $resp = ['ok'=>false,'msg'=>'','field'=>$field];

      if (in_array($field,$allowed,true)) {
        $now = date('Y-m-d H:i:s');

        // 更新前の状態を取得
        $stBefore = $pdo->prepare('SELECT * FROM tasks WHERE id=:id');
        $stBefore->execute([':id'=>$task_id]);
        $before = $stBefore->fetch(PDO::FETCH_ASSOC);

        if (!$before) {
          $resp = ['ok'=>false,'msg'=>'タスクが見つかりません。'];
        } else {

          if ($field === 'assignee_id') {
            $assignee_id   = $value!=='' ? (int)$value : null;
            $assignee_name = null;
            if ($assignee_id !== null) {
              $st = $pdo->prepare('SELECT display_name FROM users WHERE id=:id');
              $st->execute([':id'=>$assignee_id]);
              if ($r=$st->fetch()) $assignee_name = $r['display_name']; else $assignee_id = $assignee_name = null;
          if ($field === 'assignee_id' || $field === 'assignees') {
            if ($field === 'assignee_id') {
              $selectedIds = $value!=='' ? [(int)$value] : [];
            } else {
              $selectedIds = [];
              if ($value !== '') {
                $decoded = json_decode($value, true);
                if (is_array($decoded)) {
                  foreach ($decoded as $vv) {
                    $vv = (int)$vv;
                    if ($vv > 0) $selectedIds[] = $vv;
                  }
                }
              }
            }
            $selectedIds = normalize_ids($selectedIds);
            $assigneeNames = fetch_user_names($pdo, $selectedIds);
            $finalIds = [];
            foreach ($selectedIds as $sid) {
              if (isset($assigneeNames[$sid])) $finalIds[] = $sid;
            }
            $primaryId   = $finalIds[0] ?? null;
            $primaryName = ($primaryId !== null && isset($assigneeNames[$primaryId])) ? $assigneeNames[$primaryId] : null;
            $beforeList  = get_task_assignee_labels($pdo, $task_id);
            if (!$beforeList && !empty($before['assignee_name'])) {
              $beforeList = [$before['assignee_name']];
            }

            $pdo->prepare('UPDATE tasks SET assignee_id=:aid, assignee_name=:an, updated_at=:u, updated_by=:ub WHERE id=:id')
                ->execute([':aid'=>$assignee_id, ':an'=>$assignee_name, ':u'=>$now, ':ub'=>$uid, ':id'=>$task_id]);
            $resp = ['ok'=>true,'msg'=>'担当者を更新しました。','assignee_name'=>$assignee_name];

            // ログ：担当者
            $old = $before['assignee_name'] ?: '未設定';
            $new = $assignee_name ?: '未設定';
            if ($old !== $new) {
              add_task_log($pdo, $task_id, $uid, 'update', 'assignee', $old, $new);
                ->execute([':aid'=>$primaryId, ':an'=>$primaryName, ':u'=>$now, ':ub'=>$uid, ':id'=>$task_id]);
            sync_task_assignees($pdo, $task_id, $finalIds);

            $resp = ['ok'=>true,'msg'=>'担当者を更新しました。'];

            $newList = [];
            foreach ($finalIds as $fid) {
              $newList[] = $assigneeNames[$fid] ?? '（不明）';
            }
            $oldText = $beforeList ? implode(', ', $beforeList) : '未設定';
            $newText = $newList ? implode(', ', $newList) : '未設定';
            if ($oldText !== $newText) {
              add_task_log($pdo, $task_id, $uid, 'update', 'assignee', $oldText, $newText);
            }

          } else {
            if (in_array($field,['status_id','priority_id','type_id'],true)) {
              $val = $value!=='' ? (int)$value : null;
            } elseif ($field==='due_date') {
              $val = $value!=='' ? $value : null;
            } else {
              $val = trim($value) !== '' ? trim($value) : null;
            }

            $pdo->prepare("UPDATE tasks SET {$field}=:v, updated_at=:u, updated_by=:ub WHERE id=:id")
                ->execute([':v'=>$val, ':u'=>$now, ':ub'=>$uid, ':id'=>$task_id]);
            $resp = ['ok'=>true,'msg'=>'タスクを更新しました。'];

            // ログ：項目ごとに表示用テキストを作る
            $oldText = ''; $newText = '';

            switch ($field) {
              case 'title':
                $oldText = $before['title'] ?? '';
                $newText = $val ?? '';
                break;

              case 'status_id':
@@ -329,90 +407,136 @@ if (empty($teamsList)) {
  /* =========================
     フィルタ
     ========================= */
  $q            = trim($_GET['q'] ?? '');
  $f_assignees  = isset($_GET['assignee_ids']) ? array_values(array_filter(array_map('intval', (array)$_GET['assignee_ids']), fn($v)=>$v>0)) : [];
  $f_statuses   = isset($_GET['status_ids'])   ? array_values(array_filter(array_map('intval', (array)$_GET['status_ids']),   fn($v)=>$v>0)) : [];
  $f_priorities = isset($_GET['priority_ids']) ? array_values(array_filter(array_map('intval', (array)$_GET['priority_ids']), fn($v)=>$v>0)) : [];
  $f_types      = isset($_GET['type_ids'])     ? array_values(array_filter(array_map('intval', (array)$_GET['type_ids']),     fn($v)=>$v>0)) : [];
  $f_due_from   = $_GET['due_from']    ?? '';
  $f_due_to     = $_GET['due_to']      ?? '';
  $sort         = $_GET['sort']        ?? '';

  $where = ['t.team_id = :team_id', 't.deleted_at IS NULL'];
  $binds = [':team_id'=>$team_id];

  if ($q !== '') {
    $where[] = '(t.title LIKE :q OR t.description LIKE :q OR t.url LIKE :q)';
    $binds[':q'] = '%'.$q.'%';
  }
  $mkIn = function(array $vals, string $prefix, string $col) use (&$binds,&$where) {
    if (!$vals) return;
    $names=[];
    foreach ($vals as $i=>$v) { $n=":$prefix$i"; $names[]=$n; $binds[$n]=(int)$v; }
    $where[] = "$col IN (".implode(',', $names).")";
  };
  $mkIn($f_assignees,  'assignee_', 't.assignee_id');
  if ($f_assignees) {
    $names = [];
    foreach ($f_assignees as $i => $v) {
      $n = ":assignee_$i";
      $names[] = $n;
      $binds[$n] = (int)$v;
    }
    $where[] = "EXISTS (
      SELECT 1
        FROM task_assignees ta
       WHERE ta.task_id = t.id
         AND ta.user_id IN (".implode(',', $names).")
    )";
  }
  $mkIn($f_statuses,   'status_',   't.status_id');
  $mkIn($f_priorities, 'priority_', 't.priority_id');
  $mkIn($f_types,      'type_',     't.type_id');

  if ($f_due_from !== '') { $where[] = 't.due_date >= :due_from'; $binds[':due_from'] = $f_due_from; }
  if ($f_due_to   !== '') { $where[] = 't.due_date <= :due_to';   $binds[':due_to']   = $f_due_to; }

  $orderMap = [
    'due_asc'       => 't.due_date IS NULL, t.due_date ASC',
    'due_desc'      => 't.due_date IS NULL, t.due_date DESC',
    'updated_desc'  => 't.updated_at DESC',
    'updated_asc'   => 't.updated_at ASC',
    'priority_desc' => 't.priority_id DESC, t.updated_at DESC',
    'priority_asc'  => 't.priority_id ASC, t.updated_at DESC',
    'title_asc'     => 't.title ASC',
    'title_desc'    => 't.title DESC',
  ];
  $orderBy = $orderMap[$sort] ?? 't.due_date IS NULL, t.due_date ASC, t.id DESC';

  /* =========================
     タスク一覧
     ========================= */
  $sql = "
    SELECT
      t.id, t.title, t.status_id, t.assignee_id, t.assignee_name, t.due_date,
      t.priority_id, t.type_id, t.description, t.url, t.updated_at,
      ts.name AS status_name, ts.color AS status_color,
      tp.name AS priority_name, tp.color AS priority_color,
      tt.name AS type_name,   tt.color AS type_color
    FROM tasks t
    LEFT JOIN task_statuses   ts ON t.status_id   = ts.id
    LEFT JOIN task_priorities tp ON t.priority_id = tp.id
    LEFT JOIN task_types      tt ON t.type_id     = tt.id
    WHERE ".implode(' AND ', $where)."
    ORDER BY $orderBy
  ";
  $st = $pdo->prepare($sql);
  $st->execute($binds);
  $tasks = $st->fetchAll();
  $taskAssigneesMap = [];
  if ($tasks) {
    $ids = array_map(fn($r)=>(int)$r['id'], $tasks);
    $in  = implode(',', array_fill(0, count($ids), '?'));
    if ($in !== '') {
      $stA = $pdo->prepare("SELECT ta.task_id, ta.user_id, ta.is_primary, u.display_name FROM task_assignees ta LEFT JOIN users u ON ta.user_id = u.id WHERE ta.task_id IN ($in) ORDER BY ta.is_primary DESC, u.display_name ASC, ta.user_id ASC");
      $stA->execute($ids);
      while ($row = $stA->fetch(PDO::FETCH_ASSOC)) {
        $tid = (int)$row['task_id'];
        if (!isset($taskAssigneesMap[$tid])) $taskAssigneesMap[$tid] = [];
        $taskAssigneesMap[$tid][] = [
          'id'   => (int)$row['user_id'],
          'name' => $row['display_name'] ?? '（不明）',
        ];
      }
    }
    foreach ($tasks as $tRow) {
      $tid = (int)$tRow['id'];
      if (!isset($taskAssigneesMap[$tid]) || !$taskAssigneesMap[$tid]) {
        if (!empty($tRow['assignee_id'])) {
          $taskAssigneesMap[$tid] = [[
            'id'   => (int)$tRow['assignee_id'],
            'name' => $tRow['assignee_name'] ?? '（不明）',
          ]];
        } elseif (!empty($tRow['assignee_name'])) {
          $taskAssigneesMap[$tid] = [[
            'id'   => 0,
            'name' => $tRow['assignee_name'],
          ]];
        }
      }
    }
  }

  // 添付有無
  $filesMap = [];
  if (!empty($tasks)) {
    $ids = array_map(fn($r)=>(int)$r['id'],$tasks);
    $in  = implode(',', array_fill(0,count($ids),'?'));
    $stf = $pdo->prepare("SELECT task_id, COUNT(*) AS cnt FROM task_files WHERE task_id IN ($in) GROUP BY task_id");
    $stf->execute($ids);
    while ($r=$stf->fetch()) $filesMap[(int)$r['task_id']] = (int)$r['cnt'];
  }
  $taskCount = count($tasks);
}
?>
<!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<title>茨木BBS会タスク管理</title>
<meta name="viewport" content="width=device-width,initial-scale=1">
<style>
  :root{
    --bg:#fef5e7; --panel:#fff; --muted:#6b7280; --accent:#f97316; --accent-weak:#fff7ed; --blue:#2563eb;
    --border:#e5e7eb; --shadow:0 10px 25px rgba(0,0,0,.06);
  }
  *{ box-sizing: border-box; }
@@ -462,50 +586,69 @@ if (empty($teamsList)) {
  .card h2{font-size:14px;margin:0 0 10px;color:#111827;display:flex;align-items:center;gap:8px;}
  .card .sub{font-size:12px;color:var(--muted);}
  .label{font-size:12px;color:#374151;}
  .input, .select{font-size:13px;padding:8px;border-radius:8px;border:1px solid var(--border);background:#fff;min-width:0;}
  .select[multiple]{min-height:96px;}
  .filters-grid{display:grid;gap:10px;grid-template-columns: repeat(6, minmax(0,1fr));}
  .field{display:flex;flex-direction:column;gap:4px;min-width:0;}
  .filters-actions{display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin-top:2px;}
  .link-reset{color:#374151;text-decoration:none;border:1px solid var(--border);padding:8px 12px;border-radius:999px;background:#fff;}
  .count{margin-left:auto;font-size:12px;color:#6b7280;}

  .add-grid{display:grid;gap:10px;grid-template-columns: 2.2fr 1.2fr 1fr 1fr 1fr 1fr;}
  .add-field{display:flex;flex-direction:column;gap:4px;}
  .add-actions{display:flex;align-items:flex-end;justify-content:flex-end;grid-column: 1 / -1;}

  .table-wrap{width:100%;overflow:auto;max-height:70vh;border-radius:10px;margin-top:12px;}
  table{width:100%;min-width:1100px;border-collapse:separate;border-spacing:0;font-size:13px;}
  th,td{padding:8px;border-bottom:1px solid #eee;vertical-align:middle;white-space:nowrap;background:#fff;}
  th{background:#fff7e6;text-align:center;font-weight:600;position:sticky;top:0;z-index:5;}
  tr:hover td{background:#fff9ef;}
  .inline-input{font-size:12px;padding:6px;border-radius:6px;border:1px solid transparent;width:100%;box-sizing:border-box;}
  .inline-input:hover{border-color:#e5e7eb;background:#f9fafb;}
  .inline-input:focus{outline:none;border-color:var(--accent);background:#fff7ed;}
  .inline-select{appearance:none;padding:6px 20px;border-radius:999px;font-size:12px;text-align-last:center;border:1px solid rgba(0,0,0,.1);cursor:pointer;background:#fff;color:#111;}

  .assignee-picker{position:relative;border:1px solid var(--border);border-radius:10px;padding:6px 8px;min-height:38px;background:#fff;display:flex;flex-direction:column;gap:4px;cursor:pointer;}
  .assignee-picker-inline{border:none;padding:0;background:transparent;cursor:pointer;}
  .assignee-picker-display{display:flex;flex-wrap:wrap;gap:6px;align-items:center;min-height:24px;}
  .assignee-picker .placeholder{color:#9ca3af;font-size:12px;}
  .assignee-picker-inline .assignee-picker-display{min-height:auto;}
  .assignee-picker-fallback{font-size:12px;color:#374151;}
  .assignee-picker-hidden{display:none;}
  .assignee-pill{background:#f3f4f6;border-radius:999px;padding:2px 8px;font-size:12px;display:inline-flex;align-items:center;gap:4px;}
  .assignee-pill button{border:none;background:none;color:#9ca3af;cursor:pointer;padding:0;font-size:12px;}
  .assignee-picker-inline .assignee-pill{background:#fee2e2;}
  .assignee-picker-dropdown{position:absolute;left:0;top:100%;margin-top:4px;background:#fff;border:1px solid var(--border);border-radius:12px;box-shadow:0 14px 30px rgba(15,23,42,.18);width:240px;z-index:80;display:none;flex-direction:column;}
  .assignee-picker-inline .assignee-picker-dropdown{min-width:260px;}
  .assignee-picker-dropdown.show{display:flex;}
  .assignee-picker-search{border:none;border-bottom:1px solid var(--border);padding:8px 10px;font-size:13px;outline:none;}
  .assignee-picker-options{max-height:220px;overflow:auto;}
  .assignee-option{display:flex;justify-content:space-between;align-items:center;width:100%;padding:8px 12px;border:none;background:none;font-size:13px;cursor:pointer;}
  .assignee-option:hover{background:#fff7ed;}
  .assignee-option.active{color:var(--accent);font-weight:600;}

  th.col-title   {min-width:260px;}
  th.col-due     {width:110px;}
  th.col-desc    {min-width:240px;}
  th.col-url     {min-width:240px;}
  th.col-files   {width:140px;}
  th.col-updated {width:130px;}
  th.col-actions {width:90px;}
  th.col-assignee, th.col-status, th.col-priority, th.col-type { width:1%; }
  td.fit .inline-select{ width:auto; }
  td.center{ text-align:center; }

  /* 先頭列（横＆縦）固定 */
  th.sticky-col{ position:sticky; left:0; top:0; z-index:8; background:#fff7e6; box-shadow:2px 0 0 rgba(0,0,0,0.05); }
  td.sticky-col{ position:sticky; left:0; z-index:7; background:#fff; box-shadow:2px 0 0 rgba(0,0,0,0.05); }

  /* ===== コメント Notion風メニュー ===== */
  .td-comment-item{
    position:relative;
    padding:6px 8px;
    margin-bottom:4px;
    border-radius:8px;
  }
  .td-comment-item.mine:hover{
    background:#f9fafb;
  }
@@ -673,57 +816,76 @@ if (empty($teamsList)) {
            <?php if (empty($savedViews)): ?>
              <option value="">保存ビューはありません</option>
            <?php else: foreach ($savedViews as $v): ?>
              <option value="<?php echo (int)$v['id']; ?>">
                <?php echo $v['is_default']?'★ ':''; ?><?php echo h($v['name']); ?>
              </option>
            <?php endforeach; endif; ?>
          </select>
          <button type="submit" class="pill-btn blue">適用</button>
          <button type="button" class="btn-ghost" onclick="setDefaultFromSelect()">既定に</button>
          <button type="button" class="btn-ghost" onclick="deleteFromSelect()">削除</button>
        </form>
      </div>
    </div>

    <form class="filters" method="get" id="filtersForm">
      <input type="hidden" name="team_id" value="<?php echo (int)$team_id; ?>">
      <div class="filters-grid">
        <div class="field" style="grid-column: span 2;">
          <label class="label">キーワード</label>
          <input class="input" type="text" name="q" placeholder="タイトル / 説明 / URL を検索" value="<?php echo h($q); ?>">
        </div>

        <div class="field">
          <label class="label">担当者（複数可）</label>
          <select class="select js-multi-click" name="assignee_ids[]" multiple>
            <?php foreach ($usersList as $u): $val=(int)$u['id']; ?>
              <option value="<?php echo $val; ?>" <?php if(in_array($val,$f_assignees,true)) echo 'selected'; ?>>
                <?php echo h($u['display_name']); ?>
              </option>
            <?php endforeach; ?>
          </select>
          <?php
            $assigneeFilterLabels = [];
            foreach ($f_assignees as $aid) {
              if (isset($userNameMap[$aid])) $assigneeFilterLabels[$aid] = $userNameMap[$aid];
            }
          ?>
          <div class="assignee-picker"
               data-assignee-picker
               data-picker-id="filter-assignees"
               data-name="assignee_ids[]"
               data-placeholder="担当者を選択"
               data-value='<?php echo h(json_encode($f_assignees, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)); ?>'
               data-extra-labels='<?php echo h(json_encode($assigneeFilterLabels, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)); ?>'>
            <div class="assignee-picker-hidden">
              <?php foreach ($f_assignees as $aid): ?>
                <input type="hidden" name="assignee_ids[]" value="<?php echo (int)$aid; ?>">
              <?php endforeach; ?>
            </div>
            <div class="assignee-picker-fallback">
              <?php if ($f_assignees): ?>
                <?php echo h(implode(', ', array_map(fn($id)=>$userNameMap[$id] ?? '（不明）', $f_assignees))); ?>
              <?php else: ?>
                <span class="placeholder">担当者を選択</span>
              <?php endif; ?>
            </div>
          </div>
        </div>

        <div class="field">
          <label class="label">ステータス（複数可）</label>
          <select class="select js-multi-click" name="status_ids[]" multiple>
            <?php foreach ($statuses as $s): $val=(int)$s['id']; ?>
              <option value="<?php echo $val; ?>" <?php if(in_array($val,$f_statuses,true)) echo 'selected'; ?>>
                <?php echo h($s['name']); ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="field">
          <label class="label">優先度（複数可）</label>
          <select class="select js-multi-click" name="priority_ids[]" multiple>
            <?php foreach ($priorities as $p): $val=(int)$p['id']; ?>
              <option value="<?php echo $val; ?>" <?php if(in_array($val,$f_priorities,true)) echo 'selected'; ?>>
                <?php echo h($p['name']); ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="field">
@@ -762,56 +924,59 @@ if (empty($teamsList)) {
        </div>

        <div class="filters-actions" style="grid-column: 1 / -1;">
          <button type="submit" class="pill-btn orange">絞り込みを適用</button>
          <a class="link-reset" href="index.php?team_id=<?php echo (int)$team_id; ?>">リセット</a>
          <span class="count">表示中：<?php echo (int)$taskCount; ?> 件</span>
          <div style="margin-left:auto;display:flex;gap:6px;align-items:center;">
            <input type="text" id="viewNameInput" class="input" placeholder="ビュー名を入力" style="min-width:200px;">
            <button type="button" class="pill-btn blue" onclick="saveCurrentView()">保存</button>
          </div>
        </div>
      </div>
    </form>
  </div>

  <div class="card">
    <h2>タスクの新規追加</h2>
    <form method="post" class="add-grid">
      <input type="hidden" name="action" value="add">
      <div class="add-field">
        <label class="label">タスク名（必須）</label>
        <input class="input" name="title" placeholder="例：子ども食堂の備品手配">
      </div>
      <div class="add-field">
        <label class="label">担当者</label>
        <select class="select" name="assignee_id">
          <option value="">未設定</option>
          <?php foreach ($usersList as $u): ?>
            <option value="<?php echo (int)$u['id']; ?>"><?php echo h($u['display_name']); ?></option>
          <?php endforeach; ?>
        </select>
        <div class="assignee-picker"
             data-assignee-picker
             data-picker-id="add-assignees"
             data-name="assignee_ids[]"
             data-placeholder="担当者を追加"
             data-value="[]">
          <div class="assignee-picker-hidden"></div>
          <div class="assignee-picker-fallback"><span class="placeholder">担当者を追加</span></div>
        </div>
      </div>
      <div class="add-field">
        <label class="label">ステータス</label>
        <select class="select" name="status_id">
          <option value="">未設定</option>
          <?php foreach ($statuses as $s): ?>
            <option value="<?php echo (int)$s['id']; ?>"><?php echo h($s['name']); ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="add-field">
        <label class="label">優先度</label>
        <select class="select" name="priority_id">
          <option value="">未設定</option>
          <?php foreach ($priorities as $p): ?>
            <option value="<?php echo (int)$p['id']; ?>"><?php echo h($p['name']); ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="add-field">
        <label class="label">種別</label>
        <select class="select" name="type_id">
          <option value="">未設定</option>
          <?php foreach ($types as $ty): ?>
            <option value="<?php echo (int)$ty['id']; ?>"><?php echo h($ty['name']); ?></option>
@@ -832,67 +997,79 @@ if (empty($teamsList)) {
    <table id="taskTable">
      <thead>
      <tr>
        <th class="col-title sticky-col">タスク</th>
        <th class="col-assignee">担当者</th>
        <th class="col-status">ステータス</th>
        <th class="col-priority">優先度</th>
        <th class="col-type">種別</th>
        <th class="col-due">期日</th>
        <th class="col-desc">説明</th>
        <th class="col-url">URL</th>
        <th class="col-files">ファイル</th>
        <th class="col-updated">更新日</th>
        <th class="col-actions">操作</th>
      </tr>
      </thead>
      <tbody>
      <?php if (empty($tasks)): ?>
        <tr><td class="sticky-col" colspan="11">該当するタスクはありません。</td></tr>
      <?php else: foreach ($tasks as $t): $tid=(int)$t['id']; ?>
        <tr>
          <td class="sticky-col">
            <input type="text" class="inline-input js-inline-input" data-id="<?php echo $tid; ?>" data-field="title" value="<?php echo h($t['title']); ?>">
          </td>

          <td class="fit center">
            <select class="inline-select js-inline-input js-colored" data-id="<?php echo $tid; ?>" data-field="assignee_id">
              <option value="" data-color="#d9d9d9" <?php echo $t['assignee_id']===null?'selected':''; ?>>未設定</option>
              <?php
                $hasSelected=false;
                foreach ($usersList as $u):
                  $uid2=(int)$u['id']; $sel=($t['assignee_id']!==null && (int)$t['assignee_id']===$uid2);
                  if ($sel) $hasSelected=true;
              ?>
                <option value="<?php echo $uid2; ?>" data-color="#ffffff" <?php if($sel) echo 'selected'; ?>>
                  <?php echo h($u['display_name']); ?>
                </option>
              <?php endforeach; ?>
              <?php if(!$hasSelected && !empty($t['assignee_name'])): ?>
                <option value="" selected data-color="#d9d9d9"><?php echo h($t['assignee_name'].'（未登録）'); ?></option>
              <?php endif; ?>
            </select>
          <?php
            $taskAssignees = $taskAssigneesMap[$tid] ?? [];
            $taskAssigneeIds = [];
            $taskAssigneeExtras = [];
            $taskLegacyLabels = [];
            foreach ($taskAssignees as $assRow) {
              $aid = (int)($assRow['id'] ?? 0);
              if ($aid > 0) {
                $taskAssigneeIds[] = $aid;
                $taskAssigneeExtras[$aid] = $assRow['name'] ?? '';
              } else {
                if (!empty($assRow['name'])) $taskLegacyLabels[] = $assRow['name'];
              }
            }
            $assigneeFallback = $taskAssignees
              ? implode(', ', array_map(fn($row)=>$row['name'] ?? '（不明）', $taskAssignees))
              : '未設定';
          ?>
          <td class="fit">
            <div class="assignee-picker assignee-picker-inline"
                 data-assignee-picker
                 data-mode="inline"
                 data-task-id="<?php echo $tid; ?>"
                 data-placeholder="担当者を追加"
                 data-value='<?php echo h(json_encode($taskAssigneeIds, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)); ?>'
                 data-extra-labels='<?php echo h(json_encode($taskAssigneeExtras, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)); ?>'
                 data-legacy-labels='<?php echo h(json_encode($taskLegacyLabels, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)); ?>'>
              <div class="assignee-picker-fallback"><?php echo h($assigneeFallback); ?></div>
            </div>
          </td>

          <td class="fit center">
            <select class="inline-select js-inline-input js-colored" data-id="<?php echo $tid; ?>" data-field="status_id">
              <option value="" data-color="#d9d9d9" <?php echo $t['status_id']===null?'selected':''; ?>>未設定</option>
              <?php foreach ($statuses as $s): ?>
                <option value="<?php echo (int)$s['id']; ?>" data-color="<?php echo h($s['color'] ?: '#9ca3af'); ?>" <?php if((int)$s['id']===(int)$t['status_id']) echo 'selected'; ?>>
                  <?php echo h($s['name']); ?>
                </option>
              <?php endforeach; ?>
            </select>
          </td>

          <td class="fit center">
            <select class="inline-select js-inline-input js-colored" data-id="<?php echo $tid; ?>" data-field="priority_id">
              <option value="" data-color="#d9d9d9" <?php echo $t['priority_id']===null?'selected':''; ?>>未設定</option>
              <?php foreach ($priorities as $p): ?>
                <option value="<?php echo (int)$p['id']; ?>" data-color="<?php echo h($p['color'] ?: '#6b7280'); ?>" <?php if((int)$p['id']===(int)$t['priority_id']) echo 'selected'; ?>>
                  <?php echo h($p['name']); ?>
                </option>
              <?php endforeach; ?>
            </select>
          </td>

          <td class="fit center">
@@ -1035,50 +1212,74 @@ if (empty($teamsList)) {
                      max-height:160px;overflow:auto;display:none;">
          </div>
        </div>

        <div style="margin-top:4px;text-align:right;">
          <button type="button" id="td-comment-send" class="pill-btn orange">コメント送信</button>
        </div>
      </div>

      <!-- 履歴 -->
      <div>
        <h4 style="margin:0 0 4px;font-size:14px;">履歴</h4>
        <div id="td-logs" style="max-height:300px;overflow:auto;border:1px solid #e5e7eb;border-radius:8px;padding:6px 8px;font-size:12px;background:#f9fafb;"></div>
      </div>
    </div>
  </div>
</div>

<script>
(function(){
  const toolbar = document.getElementById('toolbarCard');
  window.toggleFilters = function(){
    toolbar.style.display = (toolbar.style.display==='block') ? 'none' : 'block';
  };

  const assigneePickerRegistry = {};
  const ASSIGNEE_OPTIONS = <?php
    echo json_encode(
      array_map(
        fn($u) => ['id' => (int)$u['id'], 'name' => $u['display_name']],
        $usersList
      ),
      JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );
  ?>;
  const ASSIGNEE_LOOKUP = {};
  ASSIGNEE_OPTIONS.forEach(opt => { ASSIGNEE_LOOKUP[opt.id] = opt.name || ''; });

  function escapeHtml(str){
    if (!str) return '';
    return String(str).replace(/[&<>"']/g, s => ({
      '&':'&amp;',
      '<':'&lt;',
      '>':'&gt;',
      '"':'&quot;',
      "'":'&#39;'
    }[s] || s));
  }

  // ===== インライン更新はAJAX送信 =====
  async function submitInlineAjax(id, field, value){
    const fd = new FormData();
    fd.append('action','update');
    fd.append('ajax','1');
    fd.append('task_id', id);
    fd.append('field', field);
    fd.append('value', value ?? '');
    try{
      const res = await fetch('index.php', { method:'POST', body: fd, credentials:'same-origin' });
      if(!res.ok) return;
      await res.json().catch(()=>null);
    }catch(e){ console.error(e); }
  }

  function handleChange(e){
    const el=e.target, id=el.dataset.id, field=el.dataset.field;
    if(!id||!field) return;
    submitInlineAjax(id, field, el.value);
  }

  // 入力/日付/セレクト
  document.querySelectorAll('.js-inline-input').forEach(el=>{
    if(el.classList.contains('js-url-input')) return;
    if(el.tagName==='INPUT' && el.type==='text'){ el.addEventListener('keydown',e=>{ if(e.key==='Enter'){ e.preventDefault(); handleChange(e); }}); el.addEventListener('blur',handleChange); }
@@ -1148,52 +1349,276 @@ btnS.addEventListener('click',()=>{
  if (!currentId) return;

  // 一覧の説明入力欄も即座に更新する
  const input = document.querySelector(`.js-desc-input[data-id="${currentId}"]`);
  if (input) {
    input.value = ta.value;
  }

  // DB 更新（従来どおり）
  submitInlineAjax(currentId, 'description', ta.value);

  modal.style.display = 'none';
});


  // 「クリックだけで複数選択」
  document.querySelectorAll('select[multiple].js-multi-click').forEach(sel=>{
    Array.from(sel.options).forEach(opt=>{
      opt.addEventListener('mousedown', function(e){
        e.preventDefault(); opt.selected = !opt.selected; sel.dispatchEvent(new Event('change'));
      });
    });
    sel.addEventListener('keydown', (e)=>{ if(e.key==='Enter'){ e.preventDefault(); } });
  });

  class AssigneePicker {
    constructor(el, options = {}){
      this.el = el;
      this.options = options;
      this.mode = el.dataset.mode || 'form';
      this.name = el.dataset.name || '';
      this.placeholder = el.dataset.placeholder || '担当者を選択';
      this.selected = this.parseValue(el.dataset.value);
      this.prevSerialized = JSON.stringify(this.selected);
      this.extraLabels = this.parseLabels(el.dataset.extraLabels);
      this.legacyLabels = this.parseLegacy(el.dataset.legacyLabels);
      this.hiddenWrap = el.querySelector('.assignee-picker-hidden') || null;
      const fallback = el.querySelector('.assignee-picker-fallback');
      if (fallback) fallback.remove();
      this.build();
      this.render();
    }
    parseValue(str){
      if (!str) return [];
      try{
        const arr = JSON.parse(str);
        if (Array.isArray(arr)){
          const uniq = [];
          arr.forEach(v=>{
            const id = parseInt(v,10);
            if (id > 0 && !uniq.includes(id)) uniq.push(id);
          });
          return uniq;
        }
      }catch(e){}
      return [];
    }
    parseLabels(str){
      if (!str) return {};
      try{
        const obj = JSON.parse(str);
        return (obj && typeof obj === 'object') ? obj : {};
      }catch(e){ return {}; }
    }
    parseLegacy(str){
      if (!str) return [];
      try{
        const arr = JSON.parse(str);
        return Array.isArray(arr) ? arr.map(v => String(v)) : [];
      }catch(e){ return []; }
    }
    getSelectedIds(){ return this.selected.slice(); }
    getLabel(id){ return this.extraLabels[id] || ASSIGNEE_LOOKUP[id] || `ID:${id}`; }
    build(){
      this.display = document.createElement('div');
      this.display.className = 'assignee-picker-display';
      if (this.hiddenWrap){
        this.el.insertBefore(this.display, this.hiddenWrap);
      } else if (this.el.firstChild){
        this.el.insertBefore(this.display, this.el.firstChild);
      } else {
        this.el.appendChild(this.display);
      }
      this.dropdown = document.createElement('div');
      this.dropdown.className = 'assignee-picker-dropdown';
      this.searchInput = document.createElement('input');
      this.searchInput.type = 'text';
      this.searchInput.className = 'assignee-picker-search';
      this.searchInput.placeholder = '名前で検索';
      this.dropdown.appendChild(this.searchInput);
      this.optionsList = document.createElement('div');
      this.optionsList.className = 'assignee-picker-options';
      this.dropdown.appendChild(this.optionsList);
      this.el.appendChild(this.dropdown);
      this.display.addEventListener('click', (e)=>{ e.stopPropagation(); this.toggleDropdown(); });
      this.dropdown.addEventListener('click', e=>{ e.stopPropagation(); });
      this.searchInput.addEventListener('input', ()=>this.renderOptions());
      this.outsideHandler = (e)=>{ if (!this.el.contains(e.target)) this.toggleDropdown(false); };
    }
    toggleDropdown(force){
      const shouldOpen = typeof force === 'boolean' ? force : !this.dropdown.classList.contains('show');
      if (shouldOpen){
        this.dropdown.classList.add('show');
        this.searchInput.value = '';
        this.renderOptions();
        setTimeout(()=>this.searchInput.focus(), 0);
        document.addEventListener('click', this.outsideHandler);
      }else{
        this.dropdown.classList.remove('show');
        document.removeEventListener('click', this.outsideHandler);
      }
    }
    render(){
      this.renderDisplay();
      this.updateHiddenInputs();
      if (this.dropdown.classList.contains('show')) this.renderOptions();
    }
    renderDisplay(){
      this.display.innerHTML = '';
      if (!this.selected.length && (!this.legacyLabels || !this.legacyLabels.length)){
        const span = document.createElement('span');
        span.className = 'placeholder';
        span.textContent = this.placeholder;
        this.display.appendChild(span);
        return;
      }
      this.selected.forEach(id=>{
        const pill = document.createElement('span');
        pill.className = 'assignee-pill';
        const label = document.createElement('span');
        label.textContent = this.getLabel(id);
        pill.appendChild(label);
        const btn = document.createElement('button');
        btn.type = 'button';
        btn.textContent = '×';
        btn.addEventListener('click', (e)=>{ e.stopPropagation(); this.remove(id); });
        pill.appendChild(btn);
        this.display.appendChild(pill);
      });
      if (this.legacyLabels && this.legacyLabels.length){
        this.legacyLabels.forEach(text=>{
          const legacy = document.createElement('span');
          legacy.className = 'assignee-pill';
          legacy.textContent = text;
          this.display.appendChild(legacy);
        });
      }
      if (this.mode !== 'inline') {
        const hint = document.createElement('span');
        hint.className = 'placeholder';
        hint.textContent = '＋ 追加';
        this.display.appendChild(hint);
      }
    }
    updateHiddenInputs(){
      if (!this.name) return;
      if (!this.hiddenWrap){
        this.hiddenWrap = document.createElement('div');
        this.hiddenWrap.className = 'assignee-picker-hidden';
        this.el.appendChild(this.hiddenWrap);
      }
      this.hiddenWrap.innerHTML = '';
      this.selected.forEach(id=>{
        const input = document.createElement('input');
        input.type = 'hidden';
        input.name = this.name;
        input.value = id;
        this.hiddenWrap.appendChild(input);
      });
    }
    renderOptions(){
      this.optionsList.innerHTML = '';
      const q = (this.searchInput.value || '').trim().toLowerCase();
      const list = ASSIGNEE_OPTIONS.filter(u => {
        if (!q) return true;
        return (u.name || '').toLowerCase().includes(q);
      });
      if (!list.length){
        const empty = document.createElement('div');
        empty.style.padding = '12px';
        empty.style.fontSize = '12px';
        empty.style.color = '#9ca3af';
        empty.textContent = '該当する担当者がいません';
        this.optionsList.appendChild(empty);
        return;
      }
      list.forEach(u=>{
        const btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'assignee-option' + (this.selected.includes(u.id) ? ' active' : '');
        const label = document.createElement('span');
        label.textContent = u.name || '（名称未設定）';
        const mark = document.createElement('span');
        mark.textContent = this.selected.includes(u.id) ? '✓' : '';
        btn.append(label, mark);
        btn.addEventListener('click', (e)=>{ e.preventDefault(); this.toggleSelection(u.id); });
        this.optionsList.appendChild(btn);
      });
    }
    toggleSelection(id){
      if (this.selected.includes(id)){
        this.selected = this.selected.filter(v => v !== id);
      } else {
        this.selected = [...this.selected, id];
      }
      if (this.legacyLabels && this.legacyLabels.length) {
        this.legacyLabels = [];
      }
      this.render();
      this.notifyChange();
    }
    remove(id){
      if (!this.selected.includes(id)) return;
      this.selected = this.selected.filter(v => v !== id);
      if (this.legacyLabels && this.legacyLabels.length) {
        this.legacyLabels = [];
      }
      this.render();
      this.notifyChange();
    }
    notifyChange(){
      const serialized = JSON.stringify(this.selected);
      if (serialized === this.prevSerialized) return;
      this.prevSerialized = serialized;
      if (typeof this.options.onChange === 'function') {
        this.options.onChange(this.getSelectedIds());
      }
    }
  }

  function initAssigneePickers(){
    document.querySelectorAll('[data-assignee-picker]').forEach(el=>{
      if (el.__picker) return;
      const picker = new AssigneePicker(el, {
        onChange: (ids)=>{
          if (el.dataset.mode === 'inline') {
            const taskId = el.dataset.taskId;
            if (taskId) submitInlineAjax(taskId, 'assignees', JSON.stringify(ids));
          }
        }
      });
      el.__picker = picker;
      const pid = el.dataset.pickerId || '';
      if (pid) assigneePickerRegistry[pid] = picker;
    });
  }

  initAssigneePickers();

  // ====== ここからビュー＆詳細共通関数 ======
    const CURRENT_TEAM_ID = <?php echo (int)$team_id; ?>;
  const CURRENT_TEAM_ID = <?php echo (int)$team_id; ?>;
  const MENTION_USERS = <?php
    echo json_encode(
      array_map(
        fn($u) => ['id' => (int)$u['id'], 'name' => $u['display_name']],
        $usersList
      ),
      JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );
  ?>;

  async function fetchJson(url, body){
    const res = await fetch(url, {
      method: 'POST',
      headers: {'Content-Type':'application/json'},
      body: JSON.stringify(body),
      credentials: 'same-origin'
    });
    if (!res.ok) throw new Error('network_error');
    return await res.json();
  }

  // ===== ビュー選択時：自動適用 =====
  const viewSelectEl = document.getElementById('viewSelect');
  if (viewSelectEl){
    viewSelectEl.addEventListener('change', ()=>{
@@ -1229,54 +1654,56 @@ btnS.addEventListener('click',()=>{
    const nameInput = document.getElementById('viewNameInput');
    const viewName  = nameInput && nameInput.value ? nameInput.value.trim() : '';
    if (!viewName){
      alert('ビュー名を入力してください');
      return;
    }

    const fm = document.getElementById('filtersForm');
    const params = {
      q        : fm.querySelector('[name="q"]').value || '',
      sort     : fm.querySelector('[name="sort"]').value || '',
      due_from : fm.querySelector('[name="due_from"]').value || '',
      due_to   : fm.querySelector('[name="due_to"]').value || '',
      assignee_ids: [],
      status_ids  : [],
      priority_ids: [],
      type_ids    : []
    };
    const collectMulti = (name, key)=>{
      const sel = fm.querySelector(`[name="${name}"]`);
      if (!sel) return;
      [...sel.selectedOptions].forEach(opt=>{
        params[key].push(opt.value);
      });
    };
    collectMulti('assignee_ids[]','assignee_ids');
    collectMulti('status_ids[]',  'status_ids');
    collectMulti('priority_ids[]','priority_ids');
    collectMulti('type_ids[]',    'type_ids');
    if (assigneePickerRegistry['filter-assignees']) {
      params.assignee_ids = assigneePickerRegistry['filter-assignees'].getSelectedIds();
    }

    try{
      const j = await fetchJson('api/views.php', {
        action : 'save',
        team_id: CURRENT_TEAM_ID,
        name   : viewName,
        params : params
      });
      if (!j.ok){
        alert(j.error || 'ビューの保存に失敗しました');
        return;
      }
      rebuildViewSelect(j.views || []);
      nameInput.value = '';
      alert('ビューを保存しました');
    }catch(e){
      console.error(e);
      alert('通信エラーによりビューの保存に失敗しました');
    }
  };

  // ===== ビュー「既定に」 =====
  window.setDefaultFromSelect = async function(){
    const sel = document.getElementById('viewSelect');
    if (!sel || !sel.value){
@@ -1482,51 +1909,56 @@ btnS.addEventListener('click',()=>{
        text = action || '';
      }
      return `
        <div style="border-bottom:1px solid #e5e7eb;padding:4px 0;">
          <div style="font-size:11px;color:#6b7280;">${name} ／ ${date}</div>
          <div>${text}</div>
        </div>
      `;
    }).join('');
  }

  async function loadTaskDetail(taskId){
    try{
      const j = await fetchJson('api/task_detail.php', {
        action  : 'get',
        task_id : taskId
      });
      if (!j.ok){
        alert(j.error || '詳細の取得に失敗しました');
        return;
      }
      const t = j.task;
      currentDetailTaskId = t.id;

      detailTitle.textContent = t.title || '';
      detailAss.textContent   = t.assignee_name || '未設定';
      const assigneesForDetail = Array.isArray(t.assignees) ? t.assignees : [];
      if (detailAss) {
        detailAss.innerHTML = assigneesForDetail.length
          ? assigneesForDetail.map(a=>`<span class="assignee-pill">${escapeHtml(a.name || '（不明）')}</span>`).join('')
          : '<span class="assignee-pill" style="background:#e5e7eb;color:#4b5563;">未設定</span>';
      }
      detailStatus.textContent= t.status_name   || '未設定';
      detailPrio.textContent  = t.priority_name || '未設定';
      detailType.textContent  = t.type_name     || '未設定';
      detailDue.textContent   = t.due_date      || '未設定';

      if (t.url){
        const urlEsc = t.url.replace(/[&<>]/g, m=>({ '&':'&amp;','<':'&lt;','>':'&gt;'}[m]||m));
        detailUrl.innerHTML = `<a href="${urlEsc}" target="_blank" rel="noopener" style="color:#2563eb;">${urlEsc}</a>`;
      }else{
        detailUrl.textContent = '未設定';
      }

      detailDesc.textContent = t.description || '';

      renderComments(j.comments || []);
      renderLogs(j.logs || []);

      openDetailModal();
    }catch(e){
      console.error(e);
      alert('通信エラーにより詳細の取得に失敗しました');
    }
  }

  // 詳細ボタンクリック
  document.querySelectorAll('.js-detail-btn').forEach(btn=>{
    btn.addEventListener('click', ()=>{
      const id = btn.dataset.id;
      if (!id) return;
      loadTaskDetail(id);
    });
  });

  // コメント送信
  if (commentSendBtn){
    commentSendBtn.addEventListener('click', async ()=>{
      if (!currentDetailTaskId) return;
      const text = commentInput.value.trim();
      if (!text){
        alert('コメントを入力してください');
        return;
      }
      try{
        const j = await fetchJson('api/task_detail.php', {
          action  : 'add_comment',
          task_id : currentDetailTaskId,
          body    : text
        });
        if (!j.ok){
          alert(j.error || 'コメントの送信に失敗しました');
          return;
        }
        await loadTaskDetail(currentDetailTaskId);
        commentInput.value = '';
        hideMentionSuggest();
      }catch(e){
        console.error(e);
        alert('通信エラーによりコメントの送信に失敗しました');
      }
    });
  }
  // =============================
  // URL に task_id があれば自動で詳細モーダルを開く
  // （カレンダーからの遷移用）
  // =============================
(function autoOpenDetailFromQuery(){
  const url = new URL(window.location.href);
  // ✅ task_id が優先、なければ open_task を見る
  const taskId = url.searchParams.get('task_id') || url.searchParams.get('open_task');
  if (!taskId) return;

  // ここでは URL を書き換えない（削除は閉じるときに行う）
  loadTaskDetail(taskId);
})();


  // ===== コメントメニュー（編集・削除） =====
  const commentsBox = detailComments;
  if (commentsBox){
    commentsBox.addEventListener('click', async (e)=>{
      const menuBtn = e.target.closest('.td-comment-menu-btn');
      const editBtn = e.target.closest('.td-comment-edit');
      const delBtn  = e.target.closest('.td-comment-delete');

      // ⋯ メニュー開閉
      if (menuBtn){
        const wrap = menuBtn.closest('.td-comment-menu-wrap');
        const isOpen = wrap.classList.contains('open');
        document.querySelectorAll('.td-comment-menu-wrap.open').forEach(w=>{
          w.classList.remove('open');
        });
        if (!isOpen){
          wrap.classList.add('open');
        }
        e.stopPropagation();
        return;
      }

      // 編集
if (editBtn){
  const item = editBtn.closest('.td-comment-item');
  if (!item) return;
  const commentId = item.dataset.commentId;
  const bodyEl = item.querySelector('.td-comment-body');
  const currentText = bodyEl.innerText || bodyEl.textContent || '';

  const newText = window.prompt('コメントを編集', currentText);
  if (newText === null) return;

  try{
    const j = await fetchJson('api/task_detail.php', {
      action     : 'update_comment',
      task_id    : currentDetailTaskId,   // ★追加
      comment_id : commentId,
      body       : newText
    });
    if (!j.ok){
      alert(j.error || 'コメントの更新に失敗しました');
      return;
    }
    await loadTaskDetail(currentDetailTaskId);
  }catch(err){
    console.error(err);
    alert('通信エラーによりコメントの更新に失敗しました');
  }
  return;
}


// 削除
if (delBtn){
  const item = delBtn.closest('.td-comment-item');
  if (!item) return;
  const commentId = item.dataset.commentId;

  if (!window.confirm('このコメントを削除しますか？')){
    return;
  }

  try{
    const j = await fetchJson('api/task_detail.php', {
      action     : 'delete_comment',
      task_id    : currentDetailTaskId,   // ★追加
      comment_id : commentId
    });
    if (!j.ok){
      alert(j.error || 'コメントの削除に失敗しました');
      return;
    }
    await loadTaskDetail(currentDetailTaskId);
  }catch(err){
    console.error(err);
    alert('通信エラーによりコメントの削除に失敗しました');
  }
  return;
}

    });

    // 画面の余白クリックでメニューを閉じる
    document.addEventListener('click', ()=>{
      document.querySelectorAll('.td-comment-menu-wrap.open').forEach(w=>{
        w.classList.remove('open');
      });
    });
  }


  // =============================
  // @メンション・サジェスト
  // =============================
  const mentionBox = document.getElementById('mention-suggest');

  function hideMentionSuggest(){
    if (!mentionBox) return;
    mentionBox.style.display = 'none';
    mentionBox.innerHTML = '';
  }

  function showMentionSuggest(items){
    if (!mentionBox) return;
    if (!items.length){
      hideMentionSuggest();
      return;
    }
    mentionBox.innerHTML = '';
    items.forEach(user=>{
      const div = document.createElement('div');
      div.textContent = user.name;
      div.style.padding = '6px 10px';
      div.style.cursor = 'pointer';
      div.addEventListener('mouseover', ()=>{ div.style.background = '#f3f4f6'; });
      div.addEventListener('mouseout', ()=>{ div.style.background = '#ffffff'; });
      div.addEventListener('mousedown', (e)=>{
        e.preventDefault();
        insertMention(user.name);
      });
      mentionBox.appendChild(div);
    });
    mentionBox.style.display = 'block';
  }

  function findMentionQuery(){
    const el = commentInput;
    if (!el) return null;
    const text = el.value;
    const pos = el.selectionStart ?? text.length;
    const upToCursor = text.slice(0, pos);
    const atIndex = upToCursor.lastIndexOf('@');
    if (atIndex === -1) return null;
    // 直前が空白以外なら「単語中の@」とみなして無視
    if (atIndex > 0 && !/\s/.test(upToCursor[atIndex-1])) return null;

    const after = upToCursor.slice(atIndex + 1);
    // スペースや改行が入っていたら終了
    if (/\s/.test(after)) return null;

    return {
      start: atIndex,
      end  : pos,
      query: after
    };
  }

  function updateMentionSuggest(){
    const q = findMentionQuery();
    if (!q){
      hideMentionSuggest();
      return;
    }
    const query = q.query.trim();
    let list = MENTION_USERS || [];
    if (query){
      const lower = query.toLowerCase();
      list = list.filter(u => u.name.toLowerCase().includes(lower));
    }
    if (!list.length){
      hideMentionSuggest();
      return;
    }
    showMentionSuggest(list.slice(0, 10));
  }

  function insertMention(name){
    const el = commentInput;
    const text = el.value;
    const pos = el.selectionStart ?? text.length;
    const q = findMentionQuery();
    if (!q) return;
    const before = text.slice(0, q.start);
    const after  = text.slice(q.end);
    const inserted = '@' + name + ' ';
    el.value = before + inserted + after;
    const newPos = (before + inserted).length;
    el.focus();
    el.setSelectionRange(newPos, newPos);
    hideMentionSuggest();
  }

  if (commentInput){
    commentInput.addEventListener('keyup', ()=>{
      updateMentionSuggest();
    });
    commentInput.addEventListener('click', updateMentionSuggest);
    commentInput.addEventListener('blur', ()=>{
      setTimeout(hideMentionSuggest, 150);
    });
  }

  // =============================
  // URL に task_id がある場合、自動で詳細モーダルを開く
  // =============================
  (function autoOpenDetailFromQuery(){
    const m = location.search.match(/[?&]task_id=(\d+)/);
    if (!m) return;
    const taskId = m[1];
    if (!taskId) return;
    loadTaskDetail(taskId);
  })();

// =============================
// 🔔 未読通知数の更新処理
// =============================
async function updateNotificationBadge(){
    try{
        const res = await fetch("api/notifications.php", {
            method: "POST",
            headers: {"Content-Type":"application/json"},
            body: JSON.stringify({action: "list"})
        });

        const json = await res.json();

        let unread = json.notifications.filter(n => n.is_read == 0).length;
        const badge = document.getElementById("notif-badge");

        if (unread > 0){
            badge.style.display = "inline-block";
            badge.textContent = unread;
        } else {
            badge.style.display = "none";
        }
    }catch(e){
        console.error("通知取得エラー:", e);
    }
}

// 最初の読み込みと、5秒おきの更新
updateNotificationBadge();
setInterval(updateNotificationBadge, 5000);

})();
</script>
</body>
</html>

