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
          $params['status_ids'] = array_values(array_unique(array_map('intval', $_POST['param_status_ids'])));
        }
        if (!empty($_POST['param_priority_ids']) && is_array($_POST['param_priority_ids'])) {
          $params['priority_ids'] = array_values(array_unique(array_map('intval', $_POST['param_priority_ids'])));
        }
        if (!empty($_POST['param_type_ids']) && is_array($_POST['param_type_ids'])) {
          $params['type_ids'] = array_values(array_unique(array_map('intval', $_POST['param_type_ids'])));
        }

        $now = date('Y-m-d H:i:s');
        $st  = $pdo->prepare('INSERT INTO user_saved_views(user_id, team_id, name, params, is_default, created_at)
                              VALUES(:uid,:tid,:name,:params,0,:at)
                              ON DUPLICATE KEY UPDATE params=VALUES(params), created_at=VALUES(created_at)');
        $st->execute([
          ':uid'=>$uid, ':tid'=>$team_id, ':name'=>$view_name,
          ':params'=>json_encode($params, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),
          ':at'=>$now
        ]);
        $message = 'ビューを保存しました。';
      }

    } elseif ($action === 'apply_view' && !empty($_POST['view_id'])) {
      $vid = (int)$_POST['view_id'];
      $st  = $pdo->prepare('SELECT params FROM user_saved_views WHERE id=:id AND user_id=:uid');
      $st->execute([':id'=>$vid, ':uid'=>$uid]);
      if ($r = $st->fetch()) {
        $params = json_decode($r['params'] ?? '[]', true) ?: [];
        $params['team_id'] = $team_id;
        $qs = http_build_query($params);
        header('Location: index.php?'.$qs); exit;
      } else {
        $error = '指定のビューが見つかりません。';
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
          ':due_date'=>$due!=='' ? $due : null,
          ':priority_id'=>$priority_id, ':type_id'=>$type_id,
          ':updated_at'=>$now, ':created_at'=>$now, ':updated_by'=>$uid
        ]);

        // 作成ログ
        $newId = (int)$pdo->lastInsertId();
        add_task_log($pdo, $newId, $uid, 'create', null, null, 'タスクを作成');

        $message = 'タスクを追加しました。';
      }

    } elseif ($action === 'update' && isset($_POST['task_id'], $_POST['field'])) {
      $isAjax = isset($_POST['ajax']) && $_POST['ajax'] === '1';
      $task_id = (int)$_POST['task_id'];
      $field   = $_POST['field'];
      $value   = $_POST['value'] ?? '';
      $allowed = ['title','assignee_name','assignee_id','status_id','priority_id','type_id','due_date','description','url'];

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
            }
            $pdo->prepare('UPDATE tasks SET assignee_id=:aid, assignee_name=:an, updated_at=:u, updated_by=:ub WHERE id=:id')
                ->execute([':aid'=>$assignee_id, ':an'=>$assignee_name, ':u'=>$now, ':ub'=>$uid, ':id'=>$task_id]);
            $resp = ['ok'=>true,'msg'=>'担当者を更新しました。','assignee_name'=>$assignee_name];

            // ログ：担当者
            $old = $before['assignee_name'] ?: '未設定';
            $new = $assignee_name ?: '未設定';
            if ($old !== $new) {
              add_task_log($pdo, $task_id, $uid, 'update', 'assignee', $old, $new);
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
                $oldText = '(未設定)'; $newText = '(未設定)';
                if (!empty($before['status_id'])) {
                  $st = $pdo->prepare('SELECT name FROM task_statuses WHERE id=:id');
                  $st->execute([':id'=>$before['status_id']]);
                  $oldText = $st->fetchColumn() ?: '(未設定)';
                }
                if (!empty($val)) {
                  $st = $pdo->prepare('SELECT name FROM task_statuses WHERE id=:id');
                  $st->execute([':id'=>$val]);
                  $newText = $st->fetchColumn() ?: '(未設定)';
                }
                break;

              case 'priority_id':
                $oldText = '(未設定)'; $newText = '(未設定)';
                if (!empty($before['priority_id'])) {
                  $st = $pdo->prepare('SELECT name FROM task_priorities WHERE id=:id');
                  $st->execute([':id'=>$before['priority_id']]);
                  $oldText = $st->fetchColumn() ?: '(未設定)';
                }
                if (!empty($val)) {
                  $st = $pdo->prepare('SELECT name FROM task_priorities WHERE id=:id');
                  $st->execute([':id'=>$val]);
                  $newText = $st->fetchColumn() ?: '(未設定)';
                }
                break;

              case 'type_id':
                $oldText = '(未設定)'; $newText = '(未設定)';
                if (!empty($before['type_id'])) {
                  $st = $pdo->prepare('SELECT name FROM task_types WHERE id=:id');
                  $st->execute([':id'=>$before['type_id']]);
                  $oldText = $st->fetchColumn() ?: '(未設定)';
                }
                if (!empty($val)) {
                  $st = $pdo->prepare('SELECT name FROM task_types WHERE id=:id');
                  $st->execute([':id'=>$val]);
                  $newText = $st->fetchColumn() ?: '(未設定)';
                }
                break;

              case 'due_date':
                $oldText = $before['due_date'] ?: '(未設定)';
                $newText = $val ?: '(未設定)';
                break;

              case 'description':
                $oldText = ($before['description'] ?? '') === '' ? '(空)' : $before['description'];
                $newText = ($val ?? '') === '' ? '(空)' : $val;
                break;

              case 'url':
                $oldText = ($before['url'] ?? '') === '' ? '(未設定)' : $before['url'];
                $newText = ($val ?? '') === '' ? '(未設定)' : $val;
                break;

              default:
                $oldText = (string)($before[$field] ?? '');
                $newText = (string)($val ?? '');
            }

            if ($oldText !== $newText) {
              add_task_log($pdo, $task_id, $uid, 'update', $field, $oldText, $newText);
            }
          }
        }
      } else {
        $resp = ['ok'=>false,'msg'=>'この項目は編集できません。'];
      }

      if ($isAjax) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($resp, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        exit;
      }

    } elseif ($action === 'delete' && isset($_POST['task_id'])) {
      $task_id = (int)$_POST['task_id'];
      $now = date('Y-m-d H:i:s');

      // 削除前タイトル取得
      $stOld = $pdo->prepare('SELECT title FROM tasks WHERE id=:id');
      $stOld->execute([':id'=>$task_id]);
      $oldTitle = $stOld->fetchColumn() ?: null;

      $pdo->prepare('UPDATE tasks SET deleted_at=:d, updated_at=:u, updated_by=:ub WHERE id=:id')
          ->execute([':d'=>$now, ':u'=>$now, ':ub'=>$uid, ':id'=>$task_id]);

      add_task_log($pdo, $task_id, $uid, 'delete', null, $oldTitle, 'タスクを削除');

      $message='タスクを削除しました。';
    }
  }

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
  body{font-family:system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;margin:0;background:var(--bg);color:#0f172a;}

  /* ===== 固定ヘッダー ===== */
  .topbar{position:sticky;top:0;z-index:50;background:#fff;border-bottom:1px solid var(--border);box-shadow:0 2px 8px rgba(0,0,0,.03);}
  .topbar-inner{max-width:1200px;margin:0 auto;padding:10px 20px;}
  .tb-row{display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap;}
  .tb-title{font-weight:700;color:#111827;}
  .tb-links{font-size:12px;color:#4b5563;}
  .tb-links a{color:var(--blue);text-decoration:none;margin-left:6px;}
  .tb-teams{display:flex;gap:8px;flex-wrap:wrap;margin-top:8px;}
  .tb-team{padding:6px 12px;border-radius:999px;border:1px solid var(--accent);font-size:12px;text-decoration:none;color:#9a3412;background:var(--accent-weak);}
  .tb-team.active{background:var(--accent);color:#fff;border-color:var(--accent);font-weight:600;}

  /* 追加：ページ切替タブ（タスク一覧 / マイタスク） */
  .tb-pages{display:flex;gap:8px;flex-wrap:wrap;margin-top:6px;}
  .tb-page{
    padding:4px 12px;
    border-radius:999px;
    border:1px solid transparent;
    font-size:12px;
    text-decoration:none;
    color:#374151;
    background:#f3f4f6;
  }
  .tb-page.active{
    background:var(--accent);
    color:#fff;
    border-color:var(--accent);
    font-weight:600;
  }

  /* ===== メイン ===== */
  .app{max-width:1200px;margin:16px auto 24px;padding:16px 20px 24px;background:var(--panel);border-radius:16px;box-shadow:var(--shadow);}

  .pill-btn{display:inline-block;padding:8px 12px;border-radius:999px;border:none;cursor:pointer;font-size:12px;text-decoration:none;color:#fff;}
  .pill-btn.orange{background:var(--accent);} .pill-btn.blue{background:var(--blue);}
  .btn-ghost{padding:8px 12px;border-radius:999px;border:1px solid var(--border);background:#fff;color:#111;cursor:pointer;}
  .btn-ghost:hover{background:#f9fafb;}

  .toolbar-toggle{display:flex;justify-content:space-between;align-items:center;margin-bottom:8px;}
  .toolbar{display:none;}

  .card{background:#fff;border:1px solid var(--border);border-radius:12px;padding:12px;margin-top:12px;box-shadow:0 2px 10px rgba(0,0,0,.03);}
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
  .td-comment-main{
    font-size:13px;
  }
  .td-comment-meta{
    font-size:11px;
    color:#6b7280;
    margin-bottom:2px;
    display:flex;
    gap:6px;
  }
  .td-comment-body{
    font-size:13px;
    line-height:1.4;
  }

  .td-comment-menu-wrap{
    position:absolute;
    top:4px;
    right:4px;
    opacity:0;
    transition:opacity .15s ease;
  }
  .td-comment-item.mine:hover .td-comment-menu-wrap{
    opacity:1;
  }
  .td-comment-menu-btn{
    border:none;
    background:transparent;
    cursor:pointer;
    font-size:16px;
    line-height:1;
    padding:2px 6px;
    border-radius:999px;
  }
  .td-comment-menu-btn:hover{
    background:#e5e7eb;
  }

  .td-comment-menu-popover{
    position:absolute;
    top:100%;
    right:0;
    margin-top:4px;
    background:#ffffff;
    border:1px solid #e5e7eb;
    border-radius:8px;
    box-shadow:0 8px 16px rgba(0,0,0,.15);
    padding:4px 0;
    display:none;
    z-index:2000;
  }
  .td-comment-menu-wrap.open .td-comment-menu-popover{
    display:block;
  }
  .td-comment-menu-popover button{
    display:block;
    width:100%;
    padding:6px 14px;
    border:none;
    background:transparent;
    font-size:12px;
    text-align:left;
    cursor:pointer;
  }
  .td-comment-menu-popover button:hover{
    background:#f3f4f6;
  }
  .td-comment-delete{
    color:#b91c1c;
  }

</style>
</head>
<body>

<header class="topbar">
  <div class="topbar-inner">
    <div class="tb-row">
      <div class="tb-title">茨木BBS会タスク管理</div>
      <div class="tb-links">
    <?php echo h($user['display_name'] ?? ''); ?> さん

    <!-- 🔔 通知アイコンを追加 -->
    ／ <a href="notifications.php" style="position:relative;text-decoration:none;color:#2563eb;">
        🔔 通知
        <span id="notif-badge"
              style="background:red;color:white;border-radius:50%;padding:2px 6px;
                     font-size:10px;position:absolute;top:-6px;right:-10px;display:none;">
        </span>
    </a>

    ／ <a href="change_password.php">パスワード変更</a>
    <?php if (!empty($user['role']) && $user['role']==='admin'): ?>
      ／ <a href="admin_users.php">ユーザー管理</a>
      ／ <a href="admin_masters.php">マスタ管理</a>
    <?php endif; ?>
    ／ <a href="logout.php">ログアウト</a>
</div>
    </div>

    <!-- チーム切替 -->
    <?php
      // 現在のクエリパラメータをベースにして、team_id だけを後から差し替える
      $baseParams = $_GET;
      unset($baseParams['team_id']); // team_id は各タブごとに上書きする
    ?>
    <div class="tb-teams">
      <?php foreach ($teamsList as $t): ?>
        <?php
          $tid      = (int)$t['id'];
          $isActive = ($tid === (int)$team_id);

          // ベースのパラメータに team_id だけ入れ替え
          $params = $baseParams;
          $params['team_id'] = $tid;
          $qs = http_build_query($params);
        ?>
        <a class="tb-team<?php echo $isActive ? ' active' : ''; ?>"
           href="index.php<?php echo $qs ? ('?' . $qs) : ''; ?>">
          <?php echo h($t['name']); ?>
        </a>
      <?php endforeach; ?>
    </div>


    <!-- ページ切替（タスク一覧 / マイタスク） -->
    <div class="tb-pages">
      <?php $isMyTasks = !empty($_GET['my']); ?>
      <a href="index.php?team_id=<?php echo (int)$team_id; ?>"
         class="tb-page <?php echo $isMyTasks ? '' : 'active'; ?>">
        タスク一覧
      </a>
      <a href="my_tasks.php?team_id=<?php echo (int)$team_id; ?>"
         class="tb-page <?php echo $isMyTasks ? 'active' : ''; ?>">
        マイタスク
      </a>
      <a href="calendar.php?team_id=<?php echo (int)$team_id; ?>"
         class="tb-page">
        タスクカレンダー
      </a>
    </div>

  </div>
</header>

<div class="app">
  <?php if ($message): ?><div class="msg ok" style="color:#059669;margin-bottom:6px;"><?php echo h($message); ?></div><?php endif; ?>
  <?php if ($error):   ?><div class="msg err" style="color:#b91c1c;margin-bottom:6px;"><?php echo h($error);   ?></div><?php endif; ?>

  <div class="toolbar-toggle">
    <button class="pill-btn orange" type="button" onclick="toggleFilters()">絞り込み</button>
    <div style="font-size:12px;color:#6b7280;">表示中：<?php echo (int)($taskCount ?? 0); ?> 件</div>
  </div>

  <div class="card toolbar" id="toolbarCard">
    <div style="display:flex;justify-content:space-between;align-items:center;gap:8px;margin-bottom:6px;">
      <h2>表示コントロール <span class="sub">（検索・絞り込み・保存ビュー）</span></h2>
      <div style="display:flex;gap:6px;align-items:center;">
        <form id="applyViewForm" method="post" style="display:inline-flex;gap:6px;align-items:center;">
          <input type="hidden" name="action" value="apply_view">
          <select name="view_id" id="viewSelect" class="select" style="min-width:220px;">
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
          <label class="label">種別（複数可）</label>
          <select class="select js-multi-click" name="type_ids[]" multiple>
            <?php foreach ($types as $ty): $val=(int)$ty['id']; ?>
              <option value="<?php echo $val; ?>" <?php if(in_array($val,$f_types,true)) echo 'selected'; ?>>
                <?php echo h($ty['name']); ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="field">
          <label class="label">並び替え</label>
          <select class="select" name="sort">
            <option value="">デフォルト</option>
            <option value="due_asc"       <?php if($sort==='due_asc') echo 'selected'; ?>>期日 早い順</option>
            <option value="due_desc"      <?php if($sort==='due_desc') echo 'selected'; ?>>期日 遅い順</option>
            <option value="updated_desc"  <?php if($sort==='updated_desc') echo 'selected'; ?>>更新 新しい順</option>
            <option value="updated_asc"   <?php if($sort==='updated_asc') echo 'selected'; ?>>更新 古い順</option>
            <option value="priority_desc" <?php if($sort==='priority_desc') echo 'selected'; ?>>優先度 高い順</option>
            <option value="priority_asc"  <?php if($sort==='priority_asc') echo 'selected'; ?>>優先度 低い順</option>
            <option value="title_asc"     <?php if($sort==='title_asc') echo 'selected'; ?>>タイトル A→Z</option>
            <option value="title_desc"    <?php if($sort==='title_desc') echo 'selected'; ?>>タイトル Z→A</option>
          </select>
        </div>

        <div class="field">
          <label class="label">期日（開始）</label>
          <input class="input" type="date" name="due_from" value="<?php echo h($f_due_from); ?>">
        </div>
        <div class="field">
          <label class="label">期日（終了）</label>
          <input class="input" type="date" name="due_to" value="<?php echo h($f_due_to); ?>">
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
          <?php endforeach; ?>
        </select>
      </div>
      <div class="add-field">
        <label class="label">期日</label>
        <input class="input" name="due_date" type="date">
      </div>
      <div class="add-actions">
        <button type="submit" class="pill-btn orange">追加</button>
      </div>
    </form>
  </div>

  <div class="table-wrap" id="tableWrap">
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
            <select class="inline-select js-inline-input js-colored" data-id="<?php echo $tid; ?>" data-field="type_id">
              <option value="" data-color="#d9d9d9" <?php echo $t['type_id']===null?'selected':''; ?>>未設定</option>
              <?php foreach ($types as $ty): ?>
                <option value="<?php echo (int)$ty['id']; ?>" data-color="<?php echo h($ty['color'] ?: '#6b7280'); ?>" <?php if((int)$ty['id']===(int)$t['type_id']) echo 'selected'; ?>>
                  <?php echo h($ty['name']); ?>
                </option>
              <?php endforeach; ?>
            </select>
          </td>

          <td><input type="date" class="inline-input js-inline-input" data-id="<?php echo $tid; ?>" data-field="due_date" value="<?php echo h($t['due_date'] ?? ''); ?>"></td>

          <td><input type="text" class="inline-input js-desc-input" data-id="<?php echo $tid; ?>" data-field="description" value="<?php echo h($t['description'] ?? ''); ?>"></td>

          <td class="url-cell">
            <input type="text" class="inline-input js-url-input" data-id="<?php echo $tid; ?>" data-field="url" value="<?php echo h($t['url'] ?? ''); ?>">
          </td>

          <td>
            <?php if (!empty($filesMap[$tid])): ?>
              <a class="pill-btn blue" href="task_files.php?task_id=<?php echo $tid; ?>">確認</a>
            <?php else: ?>
              <a class="pill-btn orange" href="task_files.php?task_id=<?php echo $tid; ?>">添付</a>
            <?php endif; ?>
          </td>

          <td><?php echo h($t['updated_at'] ?? ''); ?></td>

          <td>
            <button type="button"
                    class="pill-btn blue js-detail-btn"
                    data-id="<?php echo $tid; ?>"
                    style="margin-right:4px;">
              詳細
            </button>

            <form method="post" onsubmit="return confirm('このタスクを削除しますか？');" style="display:inline;">
              <input type="hidden" name="action" value="delete">
              <input type="hidden" name="task_id" value="<?php echo $tid; ?>">
              <button type="submit" class="pill-btn orange">削除</button>
            </form>
          </td>
        </tr>
      <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- 隠しフォーム（ビュー操作用） -->
<form id="defaultViewForm" method="post" style="display:none;">
  <input type="hidden" name="action" value="set_default_view">
  <input type="hidden" name="view_id" value="">
</form>
<form id="deleteViewForm" method="post" style="display:none;">
  <input type="hidden" name="action" value="delete_view">
  <input type="hidden" name="view_id" value="">
</form>

<!-- 説明モーダル -->
<div id="descModal" style="position:fixed;inset:0;background:rgba(0,0,0,.35);display:none;align-items:center;justify-content:center;z-index:1000;">
  <div style="width:min(90vw,700px);background:#fff;border-radius:12px;padding:16px;box-shadow:0 20px 40px rgba(0,0,0,.2);">
    <h3 style="margin:0 0 8px;font-size:16px;">説明（全文編集）</h3>
    <textarea id="descTextarea" style="width:100%;min-height:200px;font-size:13px;padding:8px;border:1px solid #e5e7eb;border-radius:8px;box-sizing:border-box;"></textarea>
    <div style="margin-top:12px;display:flex;gap:8px;justify-content:flex-end;">
      <button type="button" id="descCancel" class="btn-ghost">キャンセル</button>
      <button type="button" id="descSave"   class="pill-btn orange">保存</button>
    </div>
  </div>
</div>

<!-- タスク詳細モーダル -->
<div id="taskDetailModal" style="position:fixed;inset:0;background:rgba(0,0,0,.35);display:none;align-items:center;justify-content:center;z-index:1100;">
  <div style="width:min(95vw,900px);max-height:90vh;overflow:auto;background:#fff;border-radius:12px;padding:16px;box-shadow:0 20px 40px rgba(0,0,0,.2);">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px;">
      <h3 style="margin:0;font-size:16px;">タスク詳細</h3>
      <button type="button" id="taskDetailClose" class="btn-ghost">× 閉じる</button>
    </div>

    <!-- 基本情報 -->
    <div style="display:grid;grid-template-columns:1.5fr 1fr;gap:10px;margin-bottom:12px;">
      <div>
        <div class="label">タスク名</div>
        <div id="td-title" style="font-weight:600;"></div>
      </div>
      <div>
        <div class="label">担当者</div>
        <div id="td-assignee"></div>
      </div>
      <div>
        <div class="label">ステータス</div>
        <div id="td-status"></div>
      </div>
      <div>
        <div class="label">優先度</div>
        <div id="td-priority"></div>
      </div>
      <div>
        <div class="label">種別</div>
        <div id="td-type"></div>
      </div>
      <div>
        <div class="label">期日</div>
        <div id="td-due"></div>
      </div>
      <div style="grid-column:1/-1;">
        <div class="label">URL</div>
        <div id="td-url"></div>
      </div>
    </div>

    <!-- 説明 -->
    <div style="margin-bottom:16px;">
      <div class="label">説明</div>
      <div id="td-desc" style="white-space:pre-wrap;border:1px solid #e5e7eb;border-radius:8px;padding:8px;font-size:13px;background:#f9fafb;"></div>
    </div>

    <div style="display:grid;grid-template-columns:1.2fr 1fr;gap:12px;">
      <!-- コメント -->
      <div>
        <h4 style="margin:0 0 4px;font-size:14px;">コメント</h4>
        <div id="td-comments"
             style="max-height:220px;overflow:auto;border:1px solid #e5e7eb;border-radius:8px;
                    padding:6px 8px;margin-bottom:6px;font-size:13px;"></div>

        <div style="position:relative;">
          <textarea id="td-comment-input"
                    placeholder="コメントを入力..."
                    style="width:100%;min-height:60px;font-size:13px;padding:6px;
                           border-radius:8px;border:1px solid #e5e7eb;box-sizing:border-box;"></textarea>

          <!-- @メンション候補 -->
          <div id="mention-suggest"
               style="position:absolute;left:8px;bottom:40px;z-index:1200;
                      background:#ffffff;border:1px solid #e5e7eb;border-radius:8px;
                      box-shadow:0 8px 16px rgba(0,0,0,.15);font-size:13px;
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
    else if(el.tagName==='INPUT' && el.type==='date'){ el.addEventListener('change',handleChange); }
    else if(el.tagName==='SELECT'){ el.addEventListener('change',handleChange); }
  });

  // 色付きセレクト（未設定=#d9d9d9）
  function setSelectColor(sel){
    const opt = sel.options[sel.selectedIndex];
    const color = opt && opt.dataset.color ? opt.dataset.color : '';
    if(color){
      sel.style.backgroundColor = color;
      try{
        const c=color.replace('#',''); const r=parseInt(c.substr(0,2),16), g=parseInt(c.substr(2,2),16), b=parseInt(c.substr(4,2),16);
        const L=(0.299*r+0.587*g+0.114*b); sel.style.color = L < 140 ? '#fff':'#111';
      }catch(e){ sel.style.color='#111'; }
    }else{
      sel.style.backgroundColor = '#fff'; sel.style.color = '#111';
    }
  }
  document.querySelectorAll('select.js-colored').forEach(sel=>{ setSelectColor(sel); sel.addEventListener('change',e=>{ setSelectColor(e.target); handleChange(e); }); });

  // URL：Ctrl+クリックで開く、通常は編集
  function normalizeUrl(s){
    if(!s) return '';
    const t=s.trim();
    if(/^([a-zA-Z][a-zA-Z0-9+\-.]*):\/\//.test(t)) return t;
    if(/^mailto:|^tel:/i.test(t)) return t;
    return 'https://' + t;
  }
  document.querySelectorAll('.js-url-input').forEach(inp=>{
    inp.addEventListener('keydown',e=>{ if(e.key==='Enter'){ e.preventDefault(); handleChange(e); }});
    inp.addEventListener('blur',handleChange);
    inp.addEventListener('mousedown',e=>{
      if(e.ctrlKey){ e.preventDefault(); const url = normalizeUrl(inp.value); if(url) window.open(url,'_blank'); }
    });
  });

// 説明：モーダル編集
const modal=document.getElementById('descModal'); const ta=document.getElementById('descTextarea');
const btnC=document.getElementById('descCancel'); const btnS=document.getElementById('descSave'); let currentId=null;

document.querySelectorAll('.js-desc-input').forEach(el=>{
  el.addEventListener('focus',e=>{
    currentId = e.target.dataset.id;
    ta.value  = e.target.value || '';
    modal.style.display = 'flex';
    e.target.blur();
  });
});

btnC.addEventListener('click',()=>{
  modal.style.display = 'none';
  currentId = null;
});

modal.addEventListener('click',e=>{
  if(e.target === modal){
    modal.style.display = 'none';
    currentId = null;
  }
});

// ★ここを修正
btnS.addEventListener('click',()=>{
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

  // ====== ここからビュー＆詳細共通関数 ======
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
      if (!viewSelectEl.value) return;
      const form = document.getElementById('applyViewForm');
      if (!form) return;
      form.submit();
    });
  }

  function rebuildViewSelect(views){
    const sel = document.getElementById('viewSelect');
    if (!sel) return;

    sel.innerHTML = '';
    if (!views || !views.length){
      const opt = document.createElement('option');
      opt.value = '';
      opt.textContent = '保存ビューはありません';
      sel.appendChild(opt);
      return;
    }
    views.forEach(v=>{
      const opt = document.createElement('option');
      opt.value = v.id;
      opt.textContent = (v.is_default ? '★ ' : '') + v.name;
      sel.appendChild(opt);
    });
  }

  // ビュー保存（ページリロード無し）
  window.saveCurrentView = async function(){
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
      alert('既定にするビューを選択してください。');
      return;
    }
    try{
      const j = await fetchJson('api/views.php', {
        action : 'set_default',
        team_id: CURRENT_TEAM_ID,
        id     : sel.value
      });
      if (!j.ok){
        alert(j.error || '既定ビューの設定に失敗しました');
        return;
      }
      rebuildViewSelect(j.views || []);
      alert('既定ビューを更新しました');
    }catch(e){
      console.error(e);
      alert('通信エラーにより既定ビューの設定に失敗しました');
    }
  };

  // ===== ビュー「削除」 =====
  window.deleteFromSelect = async function(){
    const sel = document.getElementById('viewSelect');
    if (!sel || !sel.value){
      alert('削除するビューを選択してください。');
      return;
    }
    if (!confirm('選択中のビューを削除しますか？')) return;
    try{
      const j = await fetchJson('api/views.php', {
        action : 'delete',
        team_id: CURRENT_TEAM_ID,
        id     : sel.value
      });
      if (!j.ok){
        alert(j.error || 'ビューの削除に失敗しました');
        return;
      }
      rebuildViewSelect(j.views || []);
      alert('ビューを削除しました');
    }catch(e){
      console.error(e);
      alert('通信エラーによりビューの削除に失敗しました');
    }
  };

  // ===== タスク詳細モーダル =====
  const detailModal  = document.getElementById('taskDetailModal');
  const detailClose  = document.getElementById('taskDetailClose');
  const detailTitle  = document.getElementById('td-title');
  const detailAss    = document.getElementById('td-assignee');
  const detailStatus = document.getElementById('td-status');
  const detailPrio   = document.getElementById('td-priority');
  const detailType   = document.getElementById('td-type');
  const detailDue    = document.getElementById('td-due');
  const detailUrl    = document.getElementById('td-url');
  const detailDesc   = document.getElementById('td-desc');
  const detailComments = document.getElementById('td-comments');
  const detailLogs     = document.getElementById('td-logs');
  const commentInput   = document.getElementById('td-comment-input');
  const commentSendBtn = document.getElementById('td-comment-send');

  let currentDetailTaskId = null;

  function openDetailModal(){
    if (!detailModal) return;
    detailModal.style.display = 'flex';
  }
  function closeDetailModal(){
    if (!detailModal) return;

    // ① まず確実にモーダルを閉じる
    detailModal.style.display = 'none';

    // ② 内部状態をリセット
    currentDetailTaskId = null;
    if (commentInput)   commentInput.value = '';
    if (detailComments) detailComments.innerHTML = '';
    if (detailLogs)     detailLogs.innerHTML = '';

    // ③ URL パラメータ（task_id / open_task）を削除
    //    ※ ここでエラーが出てもモーダル閉じ処理には影響しないように try/catch で保護
    try {
      const url = new URL(window.location.href);
      let changed = false;

      if (url.searchParams.has('task_id')) {
        url.searchParams.delete('task_id');
        changed = true;
      }
      if (url.searchParams.has('open_task')) {
        url.searchParams.delete('open_task');
        changed = true;
      }

      if (changed) {
        const qs     = url.searchParams.toString();
        const newUrl = url.pathname + (qs ? '?' + qs : '');
        window.history.replaceState({}, '', newUrl);
      }
    } catch (e) {
      console.error('URL パラメータ削除時にエラー', e);
      // ここは握りつぶす：モーダルが閉じることを最優先
    }
  }

  // ★ここを新しく追加する
  if (detailClose){
    detailClose.addEventListener('click', closeDetailModal);
  }
  if (detailModal){
    detailModal.addEventListener('click', (e)=>{
      // 背景（オーバーレイ）そのものをクリックしたときだけ閉じる
      if (e.target === detailModal){
        closeDetailModal();
      }
    });
  }

  function renderComments(list){
    if (!detailComments) return;
    if (!list || !list.length){
      detailComments.innerHTML = '<div style="color:#9ca3af;">コメントはまだありません。</div>';
      return;
    }
    detailComments.innerHTML = list.map(c=>{
      const name = c.display_name || '（不明）';
      const date = c.created_at || '';
      const safeBody = (c.body || '')
        .replace(/&/g,'&amp;')
        .replace(/</g,'&lt;')
        .replace(/>/g,'&gt;')
        .replace(/\n/g,'<br>');

      const isMine = c.is_mine == 1;
      const menuHtml = isMine ? `
        <div class="td-comment-menu-wrap">
          <button type="button" class="td-comment-menu-btn">⋯</button>
          <div class="td-comment-menu-popover">
            <button type="button" class="td-comment-edit">編集</button>
            <button type="button" class="td-comment-delete">削除</button>
          </div>
        </div>
      ` : '';

      return `
        <div class="td-comment-item${isMine ? ' mine' : ''}" data-comment-id="${c.id}">
          <div class="td-comment-main">
            <div class="td-comment-meta">
              <span class="td-comment-author">${name}</span>
              <span class="td-comment-date">${date}</span>
            </div>
            <div class="td-comment-body">${safeBody}</div>
          </div>
          ${menuHtml}
        </div>
      `;
    }).join('');
  }


  function renderLogs(list){
    if (!detailLogs) return;
    if (!list || !list.length){
      detailLogs.innerHTML = '<div style="color:#9ca3af;">履歴はまだありません。</div>';
      return;
    }
    const fieldLabel = (f)=>{
      if (!f) return '';
      switch(f){
        case 'title': return 'タイトル';
        case 'assignee': return '担当者';
        case 'status_id': return 'ステータス';
        case 'priority_id': return '優先度';
        case 'type_id': return '種別';
        case 'due_date': return '期日';
        case 'description': return '説明';
        case 'url': return 'URL';
        default: return f;
      }
    };
    detailLogs.innerHTML = list.map(l=>{
      const name = l.display_name || '（不明）';
      const date = l.created_at || '';
      const action = l.action;
      let text = '';
      if (action === 'create'){
        text = 'タスクを作成';
      } else if (action === 'delete'){
        text = 'タスクを削除';
      } else if (action === 'comment'){
        text = 'コメントを追加';
      } else if (action === 'update'){
        const fl = fieldLabel(l.field);
        const ov = (l.old_value || '').replace(/\r?\n/g,' ');
        const nv = (l.new_value || '').replace(/\r?\n/g,' ');
        text = `${fl} を「${ov}」→「${nv}」に変更`;
      } else {
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

