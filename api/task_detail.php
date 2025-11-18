<?php
// /tasks/api/task_detail.php
require_once __DIR__ . '/../config.php';

header('Content-Type: application/json; charset=utf-8');

// 共通レスポンス関数
function json_error($code, $httpStatus = 200) {
    if ($httpStatus !== 200) {
        http_response_code($httpStatus);
    }
    echo json_encode(['ok' => false, 'error' => $code], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function json_ok($extra = []) {
    echo json_encode(array_merge(['ok' => true], $extra), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

// DB 接続
try {
    $pdo = get_pdo();
} catch (Throwable $e) {
    json_error('db_connect', 500);
}

// ログインチェック
$user = current_user();
if (!$user) {
    json_error('login_required', 401);
}
$user_id = (int)$user['id'];

// リクエスト JSON 読み取り
$raw  = file_get_contents('php://input');
$data = json_decode($raw, true);
if (!is_array($data)) {
    json_error('invalid_request');
}

$action  = $data['action']  ?? '';
$task_id = isset($data['task_id']) ? (int)$data['task_id'] : 0;
if ($task_id <= 0) {
    json_error('invalid_task');
}

// 共通：タスクが存在するか確認（削除済みはNG）
$st = $pdo->prepare('
    SELECT
      t.*,
      ts.name AS status_name,
      tp.name AS priority_name,
      tt.name AS type_name
    FROM tasks t
    LEFT JOIN task_statuses   ts ON t.status_id   = ts.id
    LEFT JOIN task_priorities tp ON t.priority_id = tp.id
    LEFT JOIN task_types      tt ON t.type_id     = tt.id
    WHERE t.id = :id AND t.deleted_at IS NULL
');
$st->execute([':id' => $task_id]);
$task = $st->fetch(PDO::FETCH_ASSOC);
if (!$task) {
    json_error('not_found', 404);
}

// ログ追加用
function add_task_log_api(PDO $pdo, int $task_id, int $user_id, string $action, ?string $field, ?string $old, ?string $new) {
    $st = $pdo->prepare('
        INSERT INTO task_logs (task_id, user_id, action, field, old_value, new_value, created_at)
        VALUES (:task_id, :user_id, :action, :field, :old_value, :new_value, NOW())
    ');
    $st->execute([
        ':task_id'   => $task_id,
        ':user_id'   => $user_id,
        ':action'    => $action,
        ':field'     => $field,
        ':old_value' => $old,
        ':new_value' => $new,
    ]);
}

// メンション通知作成用
function create_mention_notifications(PDO $pdo, int $task_id, int $comment_id, int $sender_id, string $body) {
    // 全ユーザーの display_name を取得して、コメント本文に含まれる @名前 を検出
    $st = $pdo->query('SELECT id, display_name FROM users WHERE 1');
    $users = $st->fetchAll(PDO::FETCH_ASSOC);

    if (!$users) {
        return;
    }

    $notified = []; // 同じユーザーに重複通知しないようにする

    foreach ($users as $u) {
        $uid   = (int)$u['id'];
        $name  = (string)$u['display_name'];
        if ($uid === $sender_id || $name === '') {
            continue; // 自分自身や名前空はスキップ
        }

        // コメント本文中の「@表示名」を判定
        $pattern = '/@' . preg_quote($name, '/') . '\b/u';
        if (preg_match($pattern, $body)) {
            if (in_array($uid, $notified, true)) {
                continue;
            }
            $notified[] = $uid;

            // 通知メッセージはコメント本文の先頭 80 文字程度を格納
            $msg = '「' . mb_strimwidth($body, 0, 80, '…', 'UTF-8') . '」';

            $stN = $pdo->prepare('
                INSERT INTO notifications
                  (user_id, sender_id, type, task_id, comment_id, message, is_read, created_at)
                VALUES
                  (:user_id, :sender_id, :type, :task_id, :comment_id, :message, 0, NOW())
            ');
            $stN->execute([
                ':user_id'    => $uid,
                ':sender_id'  => $sender_id,
                ':type'       => 'mention',
                ':task_id'    => $task_id,
                ':comment_id' => $comment_id,
                ':message'    => $msg,
            ]);
        }
    }
}

// ===== アクションごとに処理 =====
if ($action === 'get') {
    // --- コメント取得 ---
    $stC = $pdo->prepare('
        SELECT
          c.id,
          c.body,
          c.created_at,
          c.user_id,
          u.display_name
        FROM task_comments c
        LEFT JOIN users u ON c.user_id = u.id
        WHERE c.task_id = :id
          AND (c.deleted_at IS NULL OR c.deleted_at = "0000-00-00 00:00:00")
        ORDER BY c.id DESC
    ');
    $stC->execute([':id' => $task_id]);
    $rows = $stC->fetchAll(PDO::FETCH_ASSOC);

    // 自分のコメントかどうかをフラグで付ける
    $comments = [];
    foreach ($rows as $r) {
        $r['is_mine'] = ((int)$r['user_id'] === (int)$user_id) ? 1 : 0;
        $comments[] = $r;
    }

    // --- 履歴取得 ---
    $stL = $pdo->prepare('
        SELECT l.id, l.action, l.field, l.old_value, l.new_value, l.created_at, u.display_name
        FROM task_logs l
        LEFT JOIN users u ON l.user_id = u.id
        WHERE l.task_id = :id
        ORDER BY l.created_at DESC, l.id DESC
        LIMIT 200
    ');
    $stL->execute([':id' => $task_id]);
    $logs = $stL->fetchAll(PDO::FETCH_ASSOC);

    // タスク情報を整形して返す
    $task_payload = [
        'id'            => (int)$task['id'],
        'title'         => $task['title'],
        'assignee_name' => $task['assignee_name'] ?? null,
        'status_name'   => $task['status_name']   ?? null,
        'priority_name' => $task['priority_name'] ?? null,
        'type_name'     => $task['type_name']     ?? null,
        'due_date'      => $task['due_date'],
        'url'           => $task['url'],
        'description'   => $task['description'],
    ];

    json_ok([
        'task'      => $task_payload,
        'comments'  => $comments,
        'logs'      => $logs,
    ]);


} elseif ($action === 'add_comment') {
    // コメント追加
    $body = trim($data['body'] ?? '');
    if ($body === '') {
        json_error('empty_body');
    }

    $comment_id = 0;

    try {
        $pdo->beginTransaction();

        // コメント保存
        $stC = $pdo->prepare('
            INSERT INTO task_comments (task_id, user_id, body, created_at)
            VALUES (:task_id, :user_id, :body, NOW())
        ');
        $stC->execute([
            ':task_id' => $task_id,
            ':user_id' => $user_id,
            ':body'    => $body,
        ]);

        // 追加したコメントのID取得
        $comment_id = (int)$pdo->lastInsertId();

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        json_error('server_error', 500);
    }

    // ★ メンション通知を作成（失敗してもコメント自体は残したいので try-catch で保護）
    if ($comment_id > 0) {
        try {
            create_mention_notifications($pdo, $task_id, $comment_id, $user_id, $body);
        } catch (Throwable $e) {
            // ログを取りたいならここで error_log() など
        }
    }

    // ここでは ok:true だけ返す（一覧は JS 側で再取得する）
    json_ok();


/* ▼▼ ここから新規追加 ▼▼ */

} elseif ($action === 'update_comment') {
    // コメント編集
    $comment_id = isset($data['comment_id']) ? (int)$data['comment_id'] : 0;
    $body       = trim($data['body'] ?? '');

    if ($comment_id <= 0 || $body === '') {
        json_error('invalid_input');
    }

    // 自分のコメントだけ編集可能
    $st = $pdo->prepare('
        UPDATE task_comments
        SET body = :body,
            updated_at = NOW()
        WHERE id = :id
          AND user_id = :user_id
          AND (deleted_at IS NULL OR deleted_at = "0000-00-00 00:00:00")
    ');
    $st->execute([
        ':body'    => $body,
        ':id'      => $comment_id,
        ':user_id' => $user_id,
    ]);

    json_ok();

/* ▲▲ ここまで新規 ▲▲ */

/* ▼▼ さらに削除アクション ▼▼ */

} elseif ($action === 'delete_comment') {
    // コメント削除（ソフト削除）
    $comment_id = isset($data['comment_id']) ? (int)$data['comment_id'] : 0;
    if ($comment_id <= 0) {
        json_error('invalid_comment_id');
    }

    // 自分のコメントだけ削除可能
    $st = $pdo->prepare('
        UPDATE task_comments
        SET deleted_at = NOW()
        WHERE id = :id
          AND user_id = :user_id
          AND (deleted_at IS NULL OR deleted_at = "0000-00-00 00:00:00")
    ');
    $st->execute([
        ':id'      => $comment_id,
        ':user_id' => $user_id,
    ]);

    json_ok();

/* ▲▲ ここまで新規 ▲▲ */

} else {
    json_error('unknown_action');
}

