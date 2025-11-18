<?php
// /tasks/index.php
require_once __DIR__ . '/config.php';

$pdo = get_pdo();
if (!current_user()) { header('Location: login.php'); exit; }
$user = current_user();

$message = '';
$error   = '';
$tasks   = [];
$team_id = null;
$team_name = '';

$teamsList = $pdo->query('SELECT id, name FROM teams ORDER BY id')->fetchAll();
if (empty($teamsList)) {
  $error = 'teams テーブルにチームが登録されていません。管理者に連絡してください。';
} else {
  $team_id = isset($_GET['team_id']) ? (int)$_GET['team_id'] : (int)$teamsList[0]['id'];
  foreach ($teamsList as $row) if ((int)$row['id'] === $team_id) { $team_name = $row['name']; break; }
  if ($team_name === '') { $team_id = (int)$teamsList[0]['id']; $team_name = $teamsList[0]['name']; }

  $statuses   = $pdo->query('SELECT id, name, color FROM task_statuses   ORDER BY sort_order, id')->fetchAll();
  $priorities = $pdo->query('SELECT id, name, color FROM task_priorities ORDER BY sort_order, id')->fetchAll();
  $types      = $pdo->query('SELECT id, name, color FROM task_types      ORDER BY sort_order, id')->fetchAll();
  $usersList  = $pdo->query('SELECT id, display_name FROM users ORDER BY display_name, id')->fetchAll();

  if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'add') {
      $title       = trim($_POST['title'] ?? '');
      $assignee_id = ($_POST['assignee_id'] ?? '') !== '' ? (int)$_POST['assignee_id'] : null;
      $due         = $_POST['due_date'] ?? '';
      $priority_id = isset($_POST['priority_id']) ? (int)$_POST['priority_id'] : null;
      $type_id     = isset($_POST['type_id']) ? (int)$_POST['type_id'] : null;

      if ($title === '') {
        $error = 'タスク名を入力してください。';
      } else {
        $status_id = 1;
        $now = date('Y-m-d H:i:s'); $user_id = (int)$user['id'];
        $assignee_name = null;
        if ($assignee_id !== null) {
          $st = $pdo->prepare('SELECT display_name FROM users WHERE id=:id');
          $st->execute([':id'=>$assignee_id]);
          if ($r = $st->fetch()) $assignee_name = $r['display_name']; else $assignee_id = $assignee_name = null;
        }
        $stmt = $pdo->prepare(
          'INSERT INTO tasks
            (team_id, title, status_id, assignee_id, assignee_name,
             due_date, priority_id, type_id, description, url,
             updated_at, created_at, updated_by)
           VALUES
            (:team_id,:title,:status_id,:assignee_id,:assignee_name,
             :due_date,:priority_id,:type_id,NULL,NULL,
             :updated_at,:created_at,:updated_by)'
        );
        $stmt->execute([
          ':team_id'=>$team_id, ':title'=>$title, ':status_id'=>$status_id,
          ':assignee_id'=>$assignee_id, ':assignee_name'=>$assignee_name,
          ':due_date'=>$due!=='' ? $due : null,
          ':priority_id'=>$priority_id ?: null, ':type_id'=>$type_id ?: null,
          ':updated_at'=>$now, ':created_at'=>$now, ':updated_by'=>$user_id
        ]);
        $message = 'タスクを追加しました。';
      }

    } elseif ($action === 'update' && isset($_POST['task_id'], $_POST['field'])) {
      $task_id = (int)$_POST['task_id'];
      $field   = $_POST['field'];
      $value   = $_POST['value'] ?? '';
      $allowed = ['title','assignee_name','assignee_id','status_id','priority_id','type_id','due_date','description','url'];
      if (!in_array($field,$allowed,true)) {
        $error = 'この項目は編集できません。';
      } else {
        $now = date('Y-m-d H:i:s'); $user_id = (int)$user['id'];

        if ($field === 'assignee_id') {
          $assignee_id   = $value!=='' ? (int)$value : null;
          $assignee_name = null;
          if ($assignee_id !== null) {
            $st = $pdo->prepare('SELECT display_name FROM users WHERE id=:id');
            $st->execute([':id'=>$assignee_id]);
            if ($r=$st->fetch()) $assignee_name = $r['display_name']; else $assignee_id = $assignee_name = null;
          }
          $st2 = $pdo->prepare('UPDATE tasks SET assignee_id=:aid, assignee_name=:an, updated_at=:up, updated_by=:ub WHERE id=:id');
          $st2->execute([':aid'=>$assignee_id, ':an'=>$assignee_name, ':up'=>$now, ':ub'=>$user_id, ':id'=>$task_id]);
          $message = '担当者を更新しました。';
        } else {
          if (in_array($field,['status_id','priority_id','type_id'],true)) $val = $value!=='' ? (int)$value : null;
          elseif ($field==='due_date') $val = $value!=='' ? $value : null;
          else $val = trim($value) !== '' ? trim($value) : null;

          $st2 = $pdo->prepare("UPDATE tasks SET {$field}=:v, updated_at=:up, updated_by=:ub WHERE id=:id");
          $st2->execute([':v'=>$val, ':up'=>$now, ':ub'=>$user_id, ':id'=>$task_id]);
          $message = 'タスクを更新しました。';
        }
      }

    } elseif ($action === 'delete' && isset($_POST['task_id'])) {
      $task_id = (int)$_POST['task_id'];
      $now = date('Y-m-d H:i:s'); $user_id = (int)$user['id'];
      $st = $pdo->prepare('UPDATE tasks SET deleted_at=:del, updated_at=:up, updated_by=:ub WHERE id=:id');
      $st->execute([':del'=>$now, ':up'=>$now, ':ub'=>$user_id, ':id'=>$task_id]);
      $message = 'タスクを削除しました。';
    }
  }

  $sql = "
    SELECT
      t.id, t.title, t.status_id, t.assignee_id, t.assignee_name, t.due_date,
      t.priority_id, t.type_id, t.description, t.url, t.updated_at,
      ts.name AS status_name, ts.color AS status_color,
      tp.name AS priority_name, tp.color AS priority_color,
      tt.name AS type_name, tt.color AS type_color
    FROM tasks t
    LEFT JOIN task_statuses   ts ON t.status_id   = ts.id
    LEFT JOIN task_priorities tp ON t.priority_id = tp.id
    LEFT JOIN task_types      tt ON t.type_id     = tt.id
    WHERE t.team_id=:team_id AND t.deleted_at IS NULL
    ORDER BY t.due_date IS NULL, t.due_date ASC, t.id DESC
  ";
  $st = $pdo->prepare($sql);
  $st->execute([':team_id'=>$team_id]);
  $tasks = $st->fetchAll();

  $filesMap = [];
  if (!empty($tasks)) {
    $ids = array_map(fn($r)=>(int)$r['id'],$tasks);
    $in  = implode(',', array_fill(0,count($ids),'?'));
    $stf = $pdo->prepare("SELECT task_id, COUNT(*) AS cnt FROM task_files WHERE task_id IN ($in) GROUP BY task_id");
    $stf->execute($ids);
    while ($r=$stf->fetch()) $filesMap[(int)$r['task_id']] = (int)$r['cnt'];
  }
}
?>
<!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<title>茨木BBS会 タスク管理</title>
<style>
  body{font-family:system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;margin:0;background:#fef5e7;}
  .app{max-width:1200px;margin:24px auto;padding:16px 20px 24px;background:#fff;border-radius:16px;box-shadow:0 10px 25px rgba(0,0,0,.06);}
  h1{margin:0;font-size:22px;}
  .header{display:flex;justify-content:space-between;align-items:center;margin-bottom:12px;}
  .user-info{font-size:12px;color:#4b5563;text-align:right;}
  .user-info a{color:#2563eb;text-decoration:none;margin-left:4px;} .user-info a:hover{text-decoration:underline;}

  .team-tabs{display:flex;gap:8px;margin:8px 0;flex-wrap:wrap;}
  .team-tab{padding:4px 10px;border-radius:999px;border:1px solid #f97316;font-size:12px;text-decoration:none;color:#9a3412;background:#fff7ed;}
  .team-tab.active{background:#f97316;color:#fff;border-color:#f97316;font-weight:600;}

  .controls{margin:4px 0 8px;}
  .controls input,.controls select{font-size:13px;padding:6px 8px;border-radius:8px;border:1px solid #d1d5db;margin-right:4px;}
  .controls button{padding:6px 12px;border-radius:999px;border:none;background:#f97316;color:#fff;font-size:13px;cursor:pointer;}

  .table-wrap{width:100%;overflow:auto;max-height:70vh;border-radius:10px;}
  table{width:100%;min-width:1100px;border-collapse:separate;border-spacing:0;margin-top:12px;font-size:13px;}
  th,td{padding:6px 8px;border-bottom:1px solid #eee;vertical-align:middle;white-space:nowrap;background:#fff;}
  th{background:#fff7e6;text-align:center;font-weight:600;position:sticky;top:0;z-index:5;}
  tr:hover td{background:#fff9ef;}
  .msg{margin-top:8px;font-size:13px;} .msg.ok{color:#059669;} .msg.err{color:#b91c1c;}

  .inline-input{font-size:12px;padding:4px 6px;border-radius:6px;border:1px solid transparent;width:100%;box-sizing:border-box;}
  .inline-input:hover{border-color:#e5e7eb;background:#f9fafb;}
  .inline-input:focus{outline:none;border-color:#f97316;background:#fff7ed;}

  .inline-select{
    appearance:none;-webkit-appearance:none;-moz-appearance:none;
    padding:4px 20px;border-radius:999px;font-size:12px;text-align-last:center;
    border:1px solid rgba(0,0,0,.1);cursor:pointer;box-sizing:border-box;
    background:#fff;color:#111;
    transition:background-color .15s ease,color .15s ease,border-color .15s ease;
  }

  /* 列幅 */
  th.col-title   {min-width:240px;}
  th.col-due     {width:110px;}
  th.col-desc    {min-width:200px;}
  th.col-url     {min-width:220px;}
  th.col-files   {width:140px;}
  th.col-updated {width:130px;}
  th.col-actions {width:80px;}

  th.col-assignee, th.col-status, th.col-priority, th.col-type { width:1%; }
  td.fit .inline-select{ width:auto; }
  td.center{ text-align:center; }

  /* 先頭列（タスク）を確実に固定（横＆縦） */
  th.sticky-col{
    position:sticky; left:0; top:0; z-index:8; background:#fff7e6; box-shadow:2px 0 0 rgba(0,0,0,0.05);
  }
  td.sticky-col{
    position:sticky; left:0; z-index:7; background:#fff; box-shadow:2px 0 0 rgba(0,0,0,0.05);
  }

  /* URLセル */
  .url-cell{ position:relative; }
  .pill-btn{
    display:inline-block; padding:6px 12px; border-radius:999px; border:none; cursor:pointer;
    font-size:12px; text-decoration:none; color:#fff;
  }
  .pill-btn.orange{ background:#f97316; }
  .pill-btn.blue{ background:#2563eb; }
</style>
</head>
<body>
<div class="app">
  <div class="header">
    <h1>茨木BBS会 タスク管理 <?php if ($team_name){ echo '（'.htmlspecialchars($team_name,ENT_QUOTES,'UTF-8').'）'; } ?></h1>
    <div class="user-info">
      <?php echo htmlspecialchars($user['display_name'] ?? '',ENT_QUOTES,'UTF-8'); ?> さん
      ／ <a href="change_password.php">パスワード変更</a>
      <?php if (!empty($user['role']) && $user['role']==='admin'): ?>
        ／ <a href="admin_users.php">ユーザー管理</a>
        ／ <a href="admin_masters.php">マスタ管理</a>
      <?php endif; ?>
      ／ <a href="logout.php">ログアウト</a>
    </div>
  </div>

  <?php if (!empty($teamsList)): ?>
    <div class="team-tabs">
      <?php foreach ($teamsList as $t): $tid=(int)$t['id']; ?>
        <a class="team-tab<?php echo $tid===$team_id?' active':''; ?>" href="index.php?team_id=<?php echo $tid; ?>">
          <?php echo htmlspecialchars($t['name'],ENT_QUOTES,'UTF-8'); ?>
        </a>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <?php if ($message): ?><div class="msg ok"><?php echo htmlspecialchars($message,ENT_QUOTES,'UTF-8'); ?></div><?php endif; ?>
  <?php if ($error):   ?><div class="msg err"><?php echo htmlspecialchars($error,ENT_QUOTES,'UTF-8');   ?></div><?php endif; ?>

  <?php if (!empty($teamsList)): ?>
    <div class="controls">
      <form method="post" style="display:flex;flex-wrap:wrap;align-items:center;gap:4px;">
        <input type="hidden" name="action" value="add">
        <input name="title" placeholder="タスク名" style="min-width:240px;">
        <select name="assignee_id">
          <option value="">担当者（未設定）</option>
          <?php foreach ($usersList as $u): ?>
            <option value="<?php echo (int)$u['id']; ?>"><?php echo htmlspecialchars($u['display_name'],ENT_QUOTES,'UTF-8'); ?></option>
          <?php endforeach; ?>
        </select>
        <input name="due_date" type="date">
        <select name="priority_id">
          <option value="">優先度（未設定）</option>
          <?php foreach ($priorities as $p): ?>
            <option value="<?php echo (int)$p['id']; ?>"><?php echo htmlspecialchars($p['name'],ENT_QUOTES,'UTF-8'); ?></option>
          <?php endforeach; ?>
        </select>
        <select name="type_id">
          <option value="">種別（未設定）</option>
          <?php foreach ($types as $ty): ?>
            <option value="<?php echo (int)$ty['id']; ?>"><?php echo htmlspecialchars($ty['name'],ENT_QUOTES,'UTF-8'); ?></option>
          <?php endforeach; ?>
        </select>
        <button type="submit" class="pill-btn orange">追加</button>
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
          <tr><td class="sticky-col" colspan="11">このチームにはまだタスクがありません。</td></tr>
        <?php else: foreach ($tasks as $t): $tid=(int)$t['id']; ?>
          <tr>
            <!-- タスク（固定） -->
            <td class="sticky-col">
              <input type="text" class="inline-input js-inline-input"
                     data-id="<?php echo $tid; ?>" data-field="title"
                     value="<?php echo htmlspecialchars($t['title'],ENT_QUOTES,'UTF-8'); ?>">
            </td>

            <!-- 担当者 -->
            <td class="fit center">
              <select class="inline-select js-inline-input"
                      data-id="<?php echo $tid; ?>" data-field="assignee_id">
                <option value="">未設定</option>
                <?php
                  $hasSelected=false;
                  foreach ($usersList as $u):
                    $uid=(int)$u['id']; $sel=($t['assignee_id']!==null && (int)$t['assignee_id']===$uid);
                    if ($sel) $hasSelected=true;
                ?>
                  <option value="<?php echo $uid; ?>" <?php if($sel) echo 'selected'; ?>>
                    <?php echo htmlspecialchars($u['display_name'],ENT_QUOTES,'UTF-8'); ?>
                  </option>
                <?php endforeach; ?>
                <?php if (!$hasSelected && !empty($t['assignee_name'])): ?>
                  <option value="" selected><?php echo htmlspecialchars($t['assignee_name'].'（未登録）',ENT_QUOTES,'UTF-8'); ?></option>
                <?php endif; ?>
              </select>
            </td>

            <!-- ステータス -->
            <td class="fit center">
              <select class="inline-select js-inline-input js-colored"
                      data-id="<?php echo $tid; ?>" data-field="status_id">
                <?php foreach ($statuses as $s): ?>
                  <option value="<?php echo (int)$s['id']; ?>"
                          data-color="<?php echo htmlspecialchars($s['color'] ?: '#9ca3af',ENT_QUOTES,'UTF-8'); ?>"
                          <?php if((int)$s['id']===(int)$t['status_id']) echo 'selected'; ?>>
                    <?php echo htmlspecialchars($s['name'],ENT_QUOTES,'UTF-8'); ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </td>

            <!-- 優先度 -->
            <td class="fit center">
              <select class="inline-select js-inline-input js-colored"
                      data-id="<?php echo $tid; ?>" data-field="priority_id">
                <option value="" data-color="#e5e7eb" <?php if ($t['priority_id']===null) echo 'selected'; ?>>未設定</option>
                <?php foreach ($priorities as $p): ?>
                  <option value="<?php echo (int)$p['id']; ?>"
                          data-color="<?php echo htmlspecialchars($p['color'] ?: '#6b7280',ENT_QUOTES,'UTF-8'); ?>"
                          <?php if((int)$p['id']===(int)$t['priority_id']) echo 'selected'; ?>>
                    <?php echo htmlspecialchars($p['name'],ENT_QUOTES,'UTF-8'); ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </td>

            <!-- 種別 -->
            <td class="fit center">
              <select class="inline-select js-inline-input js-colored"
                      data-id="<?php echo $tid; ?>" data-field="type_id">
                <option value="" data-color="#e5e7eb" <?php if ($t['type_id']===null) echo 'selected'; ?>>未設定</option>
                <?php foreach ($types as $ty): ?>
                  <option value="<?php echo (int)$ty['id']; ?>"
                          data-color="<?php echo htmlspecialchars($ty['color'] ?: '#6b7280',ENT_QUOTES,'UTF-8'); ?>"
                          <?php if((int)$ty['id']===(int)$t['type_id']) echo 'selected'; ?>>
                    <?php echo htmlspecialchars($ty['name'],ENT_QUOTES,'UTF-8'); ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </td>

            <!-- 期日 -->
            <td>
              <input type="date" class="inline-input js-inline-input"
                     data-id="<?php echo $tid; ?>" data-field="due_date"
                     value="<?php echo htmlspecialchars($t['due_date'] ?? '',ENT_QUOTES,'UTF-8'); ?>">
            </td>

            <!-- 説明 -->
            <td>
              <input type="text" class="inline-input js-desc-input"
                     data-id="<?php echo $tid; ?>" data-field="description"
                     value="<?php echo htmlspecialchars($t['description'] ?? '',ENT_QUOTES,'UTF-8'); ?>">
            </td>

            <!-- URL（Ctrl+クリックで開く / 通常クリックは編集） -->
            <td class="url-cell">
              <input type="text" class="inline-input js-url-input"
                     data-id="<?php echo $tid; ?>" data-field="url"
                     value="<?php echo htmlspecialchars($t['url'] ?? '',ENT_QUOTES,'UTF-8'); ?>">
            </td>

            <!-- ファイル（添付有→確認(青)／無→添付(オレンジ)） -->
            <td>
              <?php if (!empty($filesMap[$tid])): ?>
                <a class="pill-btn blue" href="task_files.php?task_id=<?php echo $tid; ?>">確認</a>
              <?php else: ?>
                <a class="pill-btn orange" href="task_files.php?task_id=<?php echo $tid; ?>">添付</a>
              <?php endif; ?>
            </td>

            <!-- 更新日 -->
            <td><?php echo htmlspecialchars($t['updated_at'] ?? '',ENT_QUOTES,'UTF-8'); ?></td>

            <!-- 操作 -->
            <td>
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
  <?php endif; ?>
</div>

<form id="editForm" method="post" style="display:none;">
  <input type="hidden" name="action" value="update">
  <input type="hidden" name="task_id" value="">
  <input type="hidden" name="field" value="">
  <input type="hidden" name="value" value="">
</form>

<div id="descModal" style="position:fixed;inset:0;background:rgba(0,0,0,.35);display:none;align-items:center;justify-content:center;z-index:1000;">
  <div style="width:min(90vw,700px);background:#fff;border-radius:12px;padding:16px;box-shadow:0 20px 40px rgba(0,0,0,.2);">
    <h3 style="margin:0 0 8px;font-size:16px;">説明（全文編集）</h3>
    <textarea id="descTextarea" style="width:100%;min-height:200px;font-size:13px;padding:8px;border:1px solid #e5e7eb;border-radius:8px;box-sizing:border-box;"></textarea>
    <div style="margin-top:12px;display:flex;gap:8px;justify-content:flex-end;">
      <button type="button" id="descCancel" class="pill-btn" style="background:#e5e7eb;color:#111827;">キャンセル</button>
      <button type="button" id="descSave"   class="pill-btn orange">保存</button>
    </div>
  </div>
</div>

<script>
(function(){
  const editForm=document.getElementById('editForm');

  function submitInline(id,field,value){
    editForm.elements['task_id'].value=id;
    editForm.elements['field'].value=field;
    editForm.elements['value'].value=value;
    editForm.submit();
  }
  function handleChange(e){
    const el=e.target, id=el.dataset.id, field=el.dataset.field;
    if(!id||!field) return;
    submitInline(id,field,el.value);
  }

  // テキスト/日付/セレクトの共通ハンドラ
  document.querySelectorAll('.js-inline-input').forEach(el=>{
    if(el.classList.contains('js-url-input')) return; // URLは別処理
    if(el.tagName==='INPUT' && el.type==='text'){
      el.addEventListener('keydown',e=>{ if(e.key==='Enter'){ e.preventDefault(); handleChange(e); }});
      el.addEventListener('blur',handleChange);
    } else if(el.tagName==='INPUT' && el.type==='date'){
      el.addEventListener('change',handleChange);
    } else if(el.tagName==='SELECT'){
      el.addEventListener('change',handleChange);
    }
  });

  // セレクトの色反映
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
    // Enter/Blurで保存
    inp.addEventListener('keydown',e=>{ if(e.key==='Enter'){ e.preventDefault(); handleChange(e); }});
    inp.addEventListener('blur',handleChange);

    // Ctrl+クリックで別タブ
    inp.addEventListener('mousedown',e=>{
      if(e.ctrlKey){
        e.preventDefault();
        const url = normalizeUrl(inp.value);
        if(url) window.open(url,'_blank');
      }
    });
  });

  // 説明：モーダル編集
  const modal=document.getElementById('descModal');
  const ta=document.getElementById('descTextarea');
  const btnC=document.getElementById('descCancel');
  const btnS=document.getElementById('descSave');
  let currentId=null;
  document.querySelectorAll('.js-desc-input').forEach(el=>{
    el.addEventListener('focus',e=>{
      currentId=e.target.dataset.id; ta.value=e.target.value||''; modal.style.display='flex'; e.target.blur();
    });
  });
  btnC.addEventListener('click',()=>{ modal.style.display='none'; currentId=null; });
  modal.addEventListener('click',e=>{ if(e.target===modal){ modal.style.display='none'; currentId=null; }});
  btnS.addEventListener('click',()=>{ if(!currentId) return; submitInline(currentId,'description',ta.value); });

})();
</script>
</body>
</html>
