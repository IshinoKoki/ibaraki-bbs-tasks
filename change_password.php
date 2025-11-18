<?php
// /tasks/change_password.php
require_once __DIR__ . '/config.php';
$pdo = get_pdo();
$user = current_user();
if (!$user){ header('Location: login.php'); exit; }

$msg=''; $err='';
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

if ($_SERVER['REQUEST_METHOD']==='POST'){
  $cur = $_POST['current_password'] ?? '';
  $pw1 = $_POST['new_password'] ?? '';
  $pw2 = $_POST['new_password2'] ?? '';
  if ($pw1==='' || strlen($pw1)<8) {
    $err='新しいパスワードは8文字以上にしてください。';
  } elseif ($pw1 !== $pw2) {
    $err='確認用パスワードが一致しません。';
  } else {
    // 現在パスワード確認
    $st=$pdo->prepare('SELECT password_hash FROM users WHERE id=:id');
    $st->execute([':id'=>$user['id']]);
    $row=$st->fetch();
    if (!$row || !password_verify($cur, $row['password_hash'])) {
      $err='現在のパスワードが正しくありません。';
    } else {
      $hash=password_hash($pw1, PASSWORD_DEFAULT);
      $pdo->prepare('UPDATE users SET password_hash=:h, updated_at=NOW() WHERE id=:id')->execute([':h'=>$hash, ':id'=>$user['id']]);
      $msg='パスワードを更新しました。';
    }
  }
}
?>
<!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<title>パスワード変更</title>
<meta name="viewport" content="width=device-width,initial-scale=1">
<style>
  :root{ --bg:#fef5e7; --panel:#ffffff; --accent:#f97316; --border:#e5e7eb; --blue:#2563eb; --shadow:0 10px 25px rgba(0,0,0,.06); }
  *{ box-sizing:border-box; }
  html, body{ overflow-x:hidden; }
  body{margin:0;background:var(--bg);font-family:system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;color:#111827;}
  .backlink{position:fixed;left:12px;top:12px;z-index:100;}
  .backlink a{display:inline-block;padding:8px 12px;border-radius:999px;border:1px solid var(--border);background:#fff;text-decoration:none;color:#111;}
  .backlink a:hover{background:#f9fafb;}
  .wrap{min-height:100vh;display:grid;place-items:center;padding:0;}
  .container{width:min(520px, 100% - 40px);margin:0 auto;}  .card{background:var(--panel);border:1px solid var(--border);border-radius:16px;box-shadow:var(--shadow);padding:18px;width:100%;}
  h1{margin:0 0 8px;font-size:20px;font-weight:700;}
  .msg{font-size:13px;margin-bottom:8px;}
  .ok{color:#059669;} .err{color:#b91c1c;}
  .field{display:flex;flex-direction:column;gap:6px;margin-top:8px;}
  .label{font-size:12px;color:#374151;}
  .input{font-size:14px;padding:10px;border-radius:10px;border:1px solid var(--border);background:#fff;width:100%;}
  .btn{margin-top:12px;padding:10px 14px;border:none;border-radius:999px;background:var(--accent);color:#fff;font-weight:700;cursor:pointer;width:100%;}
</style>
</head>
<body>
<div class="backlink"><a href="index.php">← タスク一覧に戻る</a></div>

<div class="wrap">
  <div class="container">
    <div class="card">
      <h1>茨木BBS会タスク管理｜パスワード変更</h1>
      <?php if ($msg): ?><div class="msg ok"><?php echo h($msg); ?></div><?php endif; ?>
      <?php if ($err): ?><div class="msg err"><?php echo h($err); ?></div><?php endif; ?>

      <form method="post" autocomplete="off">
        <div class="field">
          <label class="label">現在のパスワード</label>
          <input class="input" type="password" name="current_password" required>
        </div>
        <div class="field">
          <label class="label">新しいパスワード（8文字以上）</label>
          <input class="input" type="password" name="new_password" required minlength="8">
        </div>
        <div class="field">
          <label class="label">新しいパスワード（確認）</label>
          <input class="input" type="password" name="new_password2" required minlength="8">
        </div>
        <button class="btn" type="submit">更新する</button>
      </form>
    </div>
  </div>
</div>
</body>
</html>
