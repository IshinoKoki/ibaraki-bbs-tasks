<?php
require_once __DIR__ . '/config.php';

$user = current_user();
if (!$user){
    header('Location: login.php');
    exit;
}

$pdo = get_pdo();

$task_id = isset($_GET['task_id']) ? (int)$_GET['task_id'] : 0;
if (!$task_id){
    echo 'タスクIDが不正です。';
    exit;
}

// タスク情報（タイトルくらいは表示したい）
$stmt = $pdo->prepare("SELECT t.title, t.team_id FROM tasks t WHERE t.id = :id");
$stmt->execute([':id' => $task_id]);
$task = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$task){
    echo '指定されたタスクが見つかりません。';
    exit;
}

// ファイル削除 or アップロード処理
if ($_SERVER['REQUEST_METHOD'] === 'POST'){
    $action = $_POST['action'] ?? '';

    // ファイルアップロード
    if ($action === 'upload' && isset($_FILES['file'])){
        $file = $_FILES['file'];
        if ($file['error'] === UPLOAD_ERR_OK){
            $uploadDir = __DIR__ . '/uploads/tasks';
            if (!is_dir($uploadDir)){
                mkdir($uploadDir, 0777, true);
            }

            $ext = pathinfo($file['name'], PATHINFO_EXTENSION);
            $base = 'task_' . $task_id . '_' . time() . '_' . bin2hex(random_bytes(4));
            $filename = $base . ($ext ? '.' . $ext : '');
            $destPath = $uploadDir . '/' . $filename;

            if (move_uploaded_file($file['tmp_name'], $destPath)){
                // Web からアクセスする相対パスとして保存（既存の仕様と整合する前提）
                $webPath = 'uploads/tasks/' . $filename;

                $stmt = $pdo->prepare("
                    INSERT INTO task_files
                        (task_id, path, original_name, mime_type, size, uploaded_by, created_at)
                    VALUES
                        (:task_id, :path, :original_name, :mime_type, :size, :uploaded_by, NOW())
                ");
                $stmt->execute([
                    ':task_id'      => $task_id,
                    ':path'         => $webPath,
                    ':original_name'=> $file['name'],
                    ':mime_type'    => $file['type'] ?: null,
                    ':size'         => $file['size'],
                    ':uploaded_by'  => (int)$user['id'],
                ]);
            }
        }
    }

    // ファイル削除
    if ($action === 'delete' && !empty($_POST['file_id'])){
        $file_id = (int)$_POST['file_id'];

        $stmt = $pdo->prepare("SELECT * FROM task_files WHERE id = :id AND task_id = :task_id");
        $stmt->execute([':id' => $file_id, ':task_id' => $task_id]);
        if ($f = $stmt->fetch(PDO::FETCH_ASSOC)){
            $path = $f['path'];
            if ($path){
                $fullPath = __DIR__ . '/' . ltrim($path, '/');
                if (is_file($fullPath)){
                    @unlink($fullPath);
                }
            }
            $del = $pdo->prepare("DELETE FROM task_files WHERE id = :id");
            $del->execute([':id' => $file_id]);
        }
    }

    header('Location: task_files.php?task_id=' . $task_id);
    exit;
}

// 現在のファイル一覧
$stmt = $pdo->prepare("
    SELECT tf.*, u.display_name AS uploader_name
    FROM task_files tf
    LEFT JOIN users u ON u.id = tf.uploaded_by
    WHERE tf.task_id = :task_id
    ORDER BY tf.created_at DESC
");
$stmt->execute([':task_id' => $task_id]);
$files = $stmt->fetchAll(PDO::FETCH_ASSOC);

?>
<!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<title>茨木BBS会タスク管理｜ファイル添付</title>
<meta name="viewport" content="width=device-width,initial-scale=1">
<style>
  :root{
    --bg:#fef5e7;
    --panel:#ffffff;
    --accent:#f97316;
    --border:#e5e7eb;
    --blue:#2563eb;
    --shadow:0 10px 25px rgba(0,0,0,.06);
  }
  *{ box-sizing:border-box; }
  html, body{ overflow-x:hidden; }
  body{
    margin:0;
    background:var(--bg);
    font-family:system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;
    color:#111827;
  }
  .backlink{
    position:fixed;
    left:12px;
    top:12px;
    z-index:100;
  }
  .backlink a{
    display:inline-block;
    padding:8px 12px;
    border-radius:999px;
    border:1px solid var(--border);
    background:#fff;
    text-decoration:none;
    color:#111;
  }
  .backlink a:hover{ background:#f9fafb; }

  .wrap{
    min-height:100vh;
    display:grid;
    place-items:center;
    padding:0;
  }
  .container{
    width:min(700px, 100% - 40px);
    margin:0 auto;
  }
  .card{
    background:var(--panel);
    border-radius:16px;
    box-shadow:var(--shadow);
    padding:18px;
    width:100%;
  }
  h1{
    margin:0 0 4px;
    font-size:20px;
    font-weight:700;
  }
  .task-title{
    font-size:13px;
    color:#6b7280;
    margin-bottom:12px;
  }
  .section-title{
    font-size:14px;
    font-weight:600;
    margin:12px 0 6px;
  }
  .upload-row{
    display:flex;
    gap:8px;
    align-items:center;
    flex-wrap:wrap;
  }
  input[type="file"]{
    font-size:12px;
  }
  .btn-pill{
    padding:8px 14px;
    border-radius:999px;
    border:none;
    cursor:pointer;
    font-size:12px;
  }
  .btn-pill.orange{
    background:var(--accent);
    color:#fff;
  }
  .btn-pill.gray{
    background:#e5e7eb;
    color:#111;
  }
  .file-list{
    margin-top:10px;
    border-top:1px solid var(--border);
    padding-top:6px;
  }
  .file-item{
    padding:8px 0;
    border-bottom:1px solid var(--border);
    font-size:13px;
    display:flex;
    justify-content:space-between;
    gap:10px;
    align-items:flex-start;
  }
  .file-main{
    flex:1;
  }
  .file-name a{
    color:var(--blue);
    text-decoration:none;
  }
  .file-name a:hover{
    text-decoration:underline;
  }
  .file-meta{
    font-size:11px;
    color:#6b7280;
    margin-top:2px;
  }
  .file-actions{
    flex-shrink:0;
    display:flex;
    flex-direction:column;
    gap:6px;
  }
</style>
</head>
<body>

<div class="backlink">
  <a href="index.php?team_id=<?php echo (int)($task['team_id'] ?? 0); ?>">← タスク一覧に戻る</a>
</div>

<div class="wrap">
  <div class="container">
    <div class="card">
      <h1>ファイル添付</h1>
      <div class="task-title">
        対象タスク：<?php echo htmlspecialchars($task['title'] ?? '', ENT_QUOTES, 'UTF-8'); ?>
      </div>

      <!-- アップロード -->
      <div>
        <div class="section-title">ファイルを追加</div>
        <form method="post" enctype="multipart/form-data" class="upload-row">
          <input type="hidden" name="action" value="upload">
          <input type="file" name="file" required>
          <button type="submit" class="btn-pill orange">アップロード</button>
        </form>
      </div>

      <!-- ファイル一覧 -->
      <div class="file-list">
        <div class="section-title">添付ファイル一覧</div>

        <?php if (empty($files)): ?>
          <div style="font-size:13px;color:#6b7280;">現在添付されているファイルはありません。</div>
        <?php else: ?>
          <?php foreach ($files as $f): ?>
            <div class="file-item">
              <div class="file-main">
                <div class="file-name">
                  <a href="<?php echo htmlspecialchars($f['path'], ENT_QUOTES, 'UTF-8'); ?>" target="_blank" rel="noopener">
                    <?php echo htmlspecialchars($f['original_name'], ENT_QUOTES, 'UTF-8'); ?>
                  </a>
                </div>
                <div class="file-meta">
                  <?php
                    $size = (int)($f['size'] ?? 0);
                    $sizeText = $size >= 1048576
                      ? round($size / 1048576, 2) . ' MB'
                      : round($size / 1024, 1) . ' KB';
                  ?>
                  サイズ：<?php echo $sizeText; ?>
                  ／ アップロード者：<?php echo htmlspecialchars($f['uploader_name'] ?? '不明', ENT_QUOTES, 'UTF-8'); ?>
                  ／ 日時：<?php echo htmlspecialchars($f['created_at'] ?? '', ENT_QUOTES, 'UTF-8'); ?>
                </div>
              </div>
<div class="file-actions">
  <a class="btn-pill gray"
     href="download_file.php?id=<?php echo (int)$f['id']; ?>">
    ダウンロード
  </a>
  <form method="post" onsubmit="return confirm('このファイルを削除しますか？');">
    <input type="hidden" name="action" value="delete">
    <input type="hidden" name="file_id" value="<?php echo (int)$f['id']; ?>">
    <button type="submit" class="btn-pill orange">削除</button>
  </form>
</div>
            </div>
          <?php endforeach; ?>
        <?php endif; ?>
      </div>

    </div>
  </div>
</div>

</body>
</html>
