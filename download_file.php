<?php
// /tasks/download_file.php
require_once __DIR__ . '/config.php';

$user = current_user();
if (!$user) {
    header('Location: login.php');
    exit;
}

$pdo = get_pdo();

// ファイルID
$file_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if (!$file_id) {
    http_response_code(400);
    echo '不正なリクエストです。';
    exit;
}

// ファイル情報取得
$stmt = $pdo->prepare("
    SELECT tf.*, t.team_id
    FROM task_files tf
    LEFT JOIN tasks t ON t.id = tf.task_id
    WHERE tf.id = :id
");
$stmt->execute([':id' => $file_id]);
$file = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$file) {
    http_response_code(404);
    echo 'ファイルが見つかりません。';
    exit;
}

// パス解決
$relativePath = $file['path'];                   // 例: uploads/tasks/xxxxx.png
$fullPath     = __DIR__ . '/' . ltrim($relativePath, '/');

if (!is_file($fullPath)) {
    http_response_code(404);
    echo 'ファイルが見つかりません。';
    exit;
}

// 元ファイル名
$downloadName = $file['original_name'] ?: basename($fullPath);

// ヘッダ送信（強制ダウンロード）
$filesize = filesize($fullPath);
$mime     = $file['mime_type'] ?: 'application/octet-stream';

header('Content-Type: ' . $mime);
header('Content-Disposition: attachment; filename="' . rawurlencode(basename($downloadName)) . '"; filename*=UTF-8\'\'' . rawurlencode($downloadName));
header('Content-Length: ' . $filesize);
header('X-Content-Type-Options: nosniff');

// バッファをクリアしてから送信
while (ob_get_level()) {
    ob_end_clean();
}
readfile($fullPath);
exit;
