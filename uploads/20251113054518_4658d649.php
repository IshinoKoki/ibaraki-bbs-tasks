<?php
// /tasks/index.php

require_once __DIR__ . '/config.php';

$pdo = get_pdo();

// ★ 未ログインなら login.php にリダイレクト（ここで1回だけ）
if (!current_user()) {
    header('Location: login.php');
    exit;
}

// ここに来ている時点で必ずログイン済み
$user = current_user();
$logged_in = true;

$message   = '';
$error     = '';
$tasks     = array();

// チームID取得（運営=unei想定）
$stmt = $pdo->prepare('SELECT id FROM teams WHERE team_key = ?');
$stmt->execute(array('unei'));
$team = $stmt->fetch();
if ($team) {
    $team_id = (int)$team['id'];
} else {
    $team_id = null;
    $error = 'teams テーブルに team_key = "unei" のレコードが見つかりません。';
}

// タスク追加処理（POST）
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $team_id !== null) {
    $title = isset($_POST['title']) ? trim($_POST['title']) : '';
    $due   = isset($_POST['due_date']) ? $_POST['due_date'] : '';

    if ($title === '') {
        $error = 'タスク名を入力してください。';
    } else {
        // status_idはとりあえず1（未着手）として登録
        $status_id = 1;
        $now       = date('Y-m-d H:i:s');
        $user_id   = (int)$user['id'];

        $stmt = $pdo->prepare(
            'INSERT INTO tasks
              (team_id, title, status_id, due_date, created_at, updated_at, updated_by)
             VALUES
              (:team_id, :title, :status_id, :due_date, :created_at, :updated_at, :updated_by)'
        );
        $stmt->execute(array(
            ':team_id'    => $team_id,
            ':title'      => $title,
            ':status_id'  => $status_id,
            ':due_date'   => $due !== '' ? $due : null,
            ':created_at' => $now,
            ':updated_at' => $now,
            ':updated_by' => $user_id,
        ));
        $message = 'タスクを追加しました。';
    }
}

// タスク一覧取得
if ($team_id !== null) {
    $sql = "
      SELECT
        t.id,
        t.title,
        t.due_date,
        t.updated_at,
        ts.name  AS status_name,
        ts.color AS status_color
      FROM tasks t
      LEFT JOIN task_statuses ts ON t.status_id = ts.id
      WHERE t.team_id = :team_id
        AND t.deleted_at IS NULL
      ORDER BY t.due_date IS NULL, t.due_date ASC, t.id DESC
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute(array(':team_id' => $team_id));
    $tasks = $stmt->fetchAll();
}
?>
<!DOCTYPE html>
<html lang="ja">
<head>
  <meta charset="UTF-8">
  <title>茨木BBS会 タスク管理</title>
  <style>
    body {
      font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
      margin: 0;
      background: #fef5e7;
    }
    .app {
      max-width: 1200px;
      margin: 24px auto;
      padding: 16px 20px 24px;
      background: #ffffff;
      border-radius: 16px;
      box-shadow: 0 10px 25px rgba(0,0,0,.06);
    }
    h1 {
      margin: 0;
      font-size: 22px;
    }
    .header {
      display: flex;
      justify-content: space-between;
      align-items: center;
      margin-bottom: 12px;
    }
    .user-info {
      font-size: 12px;
      color: #4b5563;
      text-align: right;
    }
    .user-info a {
      color: #2563eb;
      text-decoration: none;
      margin-left: 4px;
    }
    .user-info a:hover {
      text-decoration: underline;
    }
    .controls {
      margin-top: 8px;
      margin-bottom: 8px;
    }
    .controls input {
      font-size: 13px;
      padding: 6px 8px;
      border-radius: 8px;
      border: 1px solid #d1d5db;
      margin-right: 4px;
    }
    .controls button {
      padding: 6px 12px;
      border-radius: 999px;
      border: none;
      background: #f97316;
      color: #fff;
      font-size: 13px;
      cursor: pointer;
    }
    table {
      width: 100%;
      border-collapse: collapse;
      margin-top: 12px;
      font-size: 13px;
    }
    th, td {
      padding: 8px 6px;
      border-bottom: 1px solid #eee;
    }
    th {
      background: #fff7e6;
      text-align: left;
      font-weight: 600;
    }
    tr:hover {
      background: #fff9ef;
    }
    .badge {
      display: inline-block;
      padding: 2px 8px;
      border-radius: 999px;
      font-size: 11px;
      color: #fff;
    }
    .msg {
      margin-top: 8px;
      font-size: 13px;
    }
    .msg.ok { color:#059669; }
    .msg.err { color:#b91c1c; }
  </style>
</head>
<body>
  <div class="app">
    <div class="header">
      <h1>茨木BBS会 タスク管理（運営_タスク管理）</h1>
      <div class="user-info">
        <?php echo htmlspecialchars($user['display_name'] ?? '', ENT_QUOTES, 'UTF-8'); ?> さん
        ／ <a href="change_password.php">パスワード変更</a>
        <?php if (!empty($user['role']) && $user['role'] === 'admin'): ?>
          ／ <a href="admin_users.php">ユーザー管理</a>
        <?php endif; ?>
        ／ <a href="logout.php">ログアウト</a>
      </div>
    </div>

    <?php if ($message): ?>
      <div class="msg ok"><?php echo htmlspecialchars($message, ENT_QUOTES, 'UTF-8'); ?></div>
    <?php endif; ?>
    <?php if ($error): ?>
      <div class="msg err"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></div>
    <?php endif; ?>

    <div class="controls">
      <form method="post" style="display:inline;">
        <input name="title" placeholder="タスク名">
        <input name="due_date" type="date">
        <button type="submit">追加</button>
      </form>
    </div>

    <table>
      <thead>
        <tr>
          <th style="width:40px;">ID</th>
          <th>タスク名</th>
          <th style="width:120px;">ステータス</th>
          <th style="width:110px;">期日</th>
          <th style="width:150px;">更新日</th>
        </tr>
      </thead>
      <tbody>
      <?php if (empty($tasks)): ?>
        <tr><td colspan="5">まだタスクはありません。</td></tr>
      <?php else: ?>
        <?php foreach ($tasks as $t): ?>
          <tr>
            <td><?php echo (int)$t['id']; ?></td>
            <td><?php echo htmlspecialchars($t['title'], ENT_QUOTES, 'UTF-8'); ?></td>
            <td>
              <?php
                $color = $t['status_color'] ? $t['status_color'] : '#9ca3af';
                $name  = $t['status_name'] ? $t['status_name'] : '';
              ?>
              <span class="badge" style="background:<?php echo htmlspecialchars($color, ENT_QUOTES, 'UTF-8'); ?>">
                <?php echo htmlspecialchars($name, ENT_QUOTES, 'UTF-8'); ?>
              </span>
            </td>
            <td><?php echo htmlspecialchars($t['due_date'] ?? '', ENT_QUOTES, 'UTF-8'); ?></td>
            <td><?php echo htmlspecialchars($t['updated_at'] ?? '', ENT_QUOTES, 'UTF-8'); ?></td>
          </tr>
        <?php endforeach; ?>
      <?php endif; ?>
      </tbody>
    </table>
  </div>
</body>
</html>
