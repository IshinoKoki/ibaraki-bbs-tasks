<?php
// /tasks/login.php
require_once __DIR__ . '/config.php';

$pdo   = get_pdo();
$error = '';

// すでにログイン済みなら index.php へ
if (current_user()) {
    header('Location: index.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email    = isset($_POST['email']) ? trim($_POST['email']) : '';
    $password = isset($_POST['password']) ? $_POST['password'] : '';

    if ($email === '' || $password === '') {
        $error = 'メールアドレスとパスワードを入力してください。';
    } else {
        $stmt = $pdo->prepare('SELECT * FROM users WHERE email = ?');
        $stmt->execute(array($email));
        $userRow = $stmt->fetch();

        if (!$userRow) {
            // ユーザーが存在しない場合も同じメッセージ
            $error = 'メールアドレスまたはパスワードが正しくありません。';
        } else {
            // ロック中か確認
            if (!empty($userRow['locked_until']) && strtotime($userRow['locked_until']) > time()) {
                $error = 'このアカウントは一時的にロックされています。'
                       . 'しばらく待ってから再度お試しください。';
            } else {
                // パスワードチェック
                if (empty($userRow['password_hash']) ||
                    !password_verify($password, $userRow['password_hash'])) {

                    // 失敗回数 +1
                    $failed    = (int)$userRow['failed_attempts'] + 1;
                    $lockUntil = null;

                    // 5回以上失敗したら15分ロック
                    if ($failed >= 5) {
                        $lockUntil = date('Y-m-d H:i:s', time() + 15 * 60);
                    }

                    $stmt = $pdo->prepare(
                        'UPDATE users
                         SET failed_attempts = :failed,
                             locked_until   = :locked_until
                         WHERE id = :id'
                    );
                    $stmt->execute(array(
                        ':failed'       => $failed,
                        ':locked_until' => $lockUntil,
                        ':id'           => $userRow['id'],
                    ));

                    if ($lockUntil) {
                        $error = '一定回数以上ログインに失敗したため、'
                               . 'アカウントを一時ロックしました。15分後に再度お試しください。';
                    } else {
                        $error = 'メールアドレスまたはパスワードが正しくありません。';
                    }
                } else {
                    // ★ ログイン成功 ★
                    session_regenerate_id(true);
                    $_SESSION['user_id'] = $userRow['id'];

                    // 失敗カウンタをリセットし、ロック解除＆最終ログイン時刻を更新
                    $stmt = $pdo->prepare(
                        'UPDATE users
                         SET failed_attempts = 0,
                             locked_until   = NULL,
                             last_login_at  = NOW()
                         WHERE id = ?'
                    );
                    $stmt->execute(array($userRow['id']));

                    header('Location: index.php');
                    exit;
                }
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="ja">
<head>
  <meta charset="UTF-8">
  <title>茨木BBS会 タスク管理 ログイン</title>
  <style>
    body {
      font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
      background:#f3f4f6;
      margin:0;
      min-height:100vh;
      display:flex;
      justify-content:center;
      align-items:center;
    }
    .card {
      background:#fff;
      padding:24px 28px;
      border-radius:12px;
      box-shadow:0 10px 25px rgba(0,0,0,.08);
      width:320px;
    }
    h1 {
      margin-top:0;
      font-size:20px;
      text-align:center;
    }
    label {
      display:block;
      font-size:13px;
      margin-top:12px;
    }
    input[type="email"],
    input[type="password"] {
      width:100%;
      padding:8px;
      margin-top:4px;
      border-radius:6px;
      border:1px solid #d1d5db;
      font-size:13px;
      box-sizing:border-box;
    }
    button {
      width:100%;
      margin-top:16px;
      padding:8px;
      border:none;
      border-radius:999px;
      background:#f97316;
      color:#fff;
      font-size:14px;
      cursor:pointer;
    }
    button:hover {
      opacity:.9;
    }
    .error {
      margin-top:8px;
      color:#b91c1c;
      font-size:12px;
      text-align:center;
    }
    .small {
      margin-top:8px;
      font-size:11px;
      color:#6b7280;
      text-align:center;
    }
  </style>
</head>
<body>
  <div class="card">
    <h1>タスク管理 ログイン</h1>

    <?php if ($error): ?>
      <div class="error"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></div>
    <?php endif; ?>

    <form method="post">
      <label>
        メールアドレス
        <input type="email" name="email" required>
      </label>
      <label>
        パスワード
        <input type="password" name="password" required>
      </label>
      <button type="submit">ログイン</button>
    </form>

    <div class="small">
      管理者から配布されたメールアドレスとパスワードを入力してください。
    </div>
  </div>
</body>
</html>
