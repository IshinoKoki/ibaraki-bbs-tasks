<?php
require_once __DIR__.'/config.php';

$pdo  = get_pdo();
$user = current_user();
if (!$user) { header('Location: login.php'); exit; }
if (($user['role'] ?? '') !== 'admin') { header('Location: index.php'); exit; }

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function gen_password($len=10){
  $chars='ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnpqrstuvwxyz23456789!@#$%';
  $r=''; for($i=0;$i<$len;$i++){ $r.=$chars[random_int(0,strlen($chars)-1)]; } return $r;
}

$msg=''; $err='';
if ($_SERVER['REQUEST_METHOD']==='POST'){
  $act = $_POST['action'] ?? '';
  if ($act==='create'){
    $email = trim($_POST['email'] ?? '');
    $name  = trim($_POST['display_name'] ?? '');
    $role  = ($_POST['role'] ?? 'member')==='admin' ? 'admin' : 'member';
    $pw    = trim($_POST['password'] ?? '');
    if ($email==='' || $name===''){ $err='メールアドレスと表示名は必須です。'; }
    else{
      if ($pw==='') $pw = gen_password(10);
      $hash = password_hash($pw, PASSWORD_DEFAULT);
      $pdo->prepare("INSERT INTO users(email,display_name,password_hash,role,created_at,updated_at) VALUES(?,?,?,?,NOW(),NOW())")
          ->execute([$email,$name,$hash,$role]);
      $msg='ユーザーを作成しました。';
    }
  } elseif ($act==='update'){
    $id   = (int)($_POST['id'] ?? 0);
    $name = trim($_POST['display_name'] ?? '');
    $role = ($_POST['role'] ?? 'member')==='admin' ? 'admin' : 'member';
    if ($id<=0 || $name===''){ $err='入力が不正です。'; }
    else{
      $pdo->prepare("UPDATE users SET display_name=?, role=?, updated_at=NOW() WHERE id=?")
          ->execute([$name,$role,$id]);
      $msg='更新しました。';
    }
  } elseif ($act==='delete'){
    $id=(int)($_POST['id'] ?? 0);
    if ($id>0 && $id!==(int)$user['id']){
      $pdo->prepare("DELETE FROM users WHERE id=?")->execute([$id]);
      $msg='削除しました。';
    } else { $err='削除できません。'; }
  }
}

$rows=$pdo->query("SELECT id,email,display_name,role,last_login_at,created_at,updated_at FROM users ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
?><!doctype html><html lang="ja"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>ユーザー管理</title>
<style>
:root{
  --bg:#fef5e7; --panel:#fff; --border:#e5e7eb; --accent:#f97316; --blue:#2563eb; --shadow:0 10px 25px rgba(0,0,0,.06);
  /* タスク管理カード幅と統一 */
  --content-width: 1200px;
}
*{box-sizing:border-box} html,body{overflow-x:hidden}
body{margin:0;background:var(--bg);font-family:system-ui,-apple-system,"Segoe UI",sans-serif;color:#111827}
.back{position:fixed;left:12px;top:12px;z-index:100}
.back a{display:inline-block;padding:8px 12px;border-radius:999px;border:1px solid var(--border);background:#fff;text-decoration:none;color:#111}

.wrap{min-height:100vh;display:grid;place-items:start center;padding:56px 0 24px}
.container{max-width:var(--content-width);width:100%;margin:0 auto;padding:0 20px}
.card{width:100%;background:var(--panel);border:1px solid var(--border);border-radius:16px;box-shadow:var(--shadow);padding:16px}
h1{margin:0 0 6px;font-size:20px;font-weight:700}
.msg{font-size:13px;margin:6px 0}.ok{color:#059669}.err{color:#b91c1c}

.row{display:grid;gap:10px;grid-template-columns:1.2fr 1fr 0.8fr 1fr}
@media (max-width:900px){.row{grid-template-columns:1fr 1fr}}
@media (max-width:560px){.row{grid-template-columns:1fr}}

.field{display:flex;flex-direction:column;gap:6px}
.label{font-size:12px;color:#374151}
.input,.select{font-size:14px;padding:10px;border-radius:10px;border:1px solid var(--border);background:#fff;width:100%}
.btn{padding:10px 16px;border:none;border-radius:999px;background:var(--accent);color:#fff;font-weight:700;cursor:pointer}
.btn-ghost{padding:10px 16px;border-radius:999px;border:1px solid var(--border);background:#fff;cursor:pointer}

.card-actions{display:flex;justify-content:flex-end;margin-top:8px}

/* 横スクロールは wrap 内のテーブル領域のみ。最小幅はカード幅に追従 */
.table-wrap{width:100%;overflow:auto;border-radius:12px;border:1px solid var(--border);margin-top:10px}
table{width:100%;min-width:calc(var(--content-width) - 40px);border-collapse:separate;border-spacing:0;font-size:13px}
th,td{padding:10px;border-bottom:1px solid #eee;background:#fff;white-space:nowrap;vertical-align:middle}
th{background:#fff7e6;text-align:center;font-weight:600;position:sticky;top:0;z-index:1}
tr:hover td{background:#fff9ef}

.col-id{width:70px;text-align:right}
.col-email{min-width:260px}
.col-name{min-width:200px}
.col-role{min-width:160px;text-align:center}
.col-last{min-width:180px}
.col-dates{min-width:240px}
.col-actions{min-width:180px;text-align:center}
</style>
</head><body>
<div class="back"><a href="index.php">← タスク一覧に戻る</a></div>

<div class="wrap"><div class="container">
  <div class="card">
    <h1>ユーザー管理</h1>
    <?php if($msg):?><div class="msg ok"><?php echo h($msg);?></div><?php endif;?>
    <?php if($err):?><div class="msg err"><?php echo h($err);?></div><?php endif;?>

    <!-- 追加フォーム -->
    <form method="post" class="row" autocomplete="off">
      <input type="hidden" name="action" value="create">
      <div class="field">
        <label class="label">メールアドレス（必須）</label>
        <input class="input" name="email" required>
      </div>
      <div class="field">
        <label class="label">表示名（必須）</label>
        <input class="input" name="display_name" required>
      </div>
      <div class="field">
        <label class="label">ロール</label>
        <select class="select" name="role">
          <option value="member">member</option>
          <option value="admin">admin</option>
        </select>
      </div>
      <div class="field">
        <label class="label">初期パスワード（未入力で自動生成）</label>
        <input class="input" name="password">
      </div>
      <div class="card-actions" style="grid-column:1/-1">
        <button class="btn" type="submit">ユーザー作成</button>
      </div>
    </form>

    <!-- 一覧（テーブル最小幅はカード幅に追従、通常は横スクロールなし） -->
    <div class="table-wrap">
      <table>
        <thead>
          <tr>
            <th class="col-id">ID</th>
            <th class="col-email">メールアドレス</th>
            <th class="col-name">表示名</th>
            <th class="col-role">ロール</th>
            <th class="col-last">最終ログイン</th>
            <th class="col-dates">作成/更新</th>
            <th class="col-actions">操作</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach($rows as $r): ?>
          <tr>
            <td class="col-id"><?php echo (int)$r['id'];?></td>
            <td class="col-email"><?php echo h($r['email']);?></td>
            <td class="col-name">
              <form method="post" style="display:flex;gap:8px;align-items:center">
                <input type="hidden" name="action" value="update">
                <input type="hidden" name="id" value="<?php echo (int)$r['id'];?>">
                <input class="input" name="display_name" value="<?php echo h($r['display_name']);?>" style="min-width:200px">
            </td>
            <td class="col-role">
                <select class="select" name="role" style="min-width:160px">
                  <option value="member" <?php if($r['role']==='member') echo 'selected';?>>member</option>
                  <option value="admin"  <?php if($r['role']==='admin')  echo 'selected';?>>admin</option>
                </select>
            </td>
            <td class="col-last"><?php echo h($r['last_login_at'] ?? ''); ?></td>
            <td class="col-dates">
              作成: <?php echo h($r['created_at']);?><br>
              更新: <?php echo h($r['updated_at']);?>
            </td>
            <td class="col-actions">
                <button class="btn" style="background:var(--blue)" type="submit">保存</button>
              </form>
              <form method="post" onsubmit="return confirm('削除しますか？');" style="display:inline">
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="id" value="<?php echo (int)$r['id'];?>">
                <button class="btn-ghost" type="submit">削除</button>
              </form>
            </td>
          </tr>
          <?php endforeach; ?>
          <?php if(!$rows): ?><tr><td colspan="7">ユーザーがいません。</td></tr><?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div></div>
</body></html>
