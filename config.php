<?php
// =====================
// デバッグフラグ（動作確認中は true でOK）
// =====================
define('APP_DEBUG', true);

// =====================
// DB 接続情報（必ず自分の値に変更）
// =====================
define('DB_HOST', 'mysql327.phy.lolipop.lan');      // 例: mysql1234.lolipop.jp
define('DB_NAME', 'LAA1623726-tasks');           // 例: LAA0123456-taskdb
define('DB_USER', 'LAA1623726');                  // DBユーザー名
define('DB_PASS', 'bbs0011');        // DBパスワード

// =====================
// セッション開始
// =====================
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// =====================
// PDO を取得
// =====================
function get_pdo() {
    static $pdo = null;
    if ($pdo !== null) {
        return $pdo;
    }

    $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4';

    try {
        $pdo = new PDO($dsn, DB_USER, DB_PASS, array(
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ));
    } catch (PDOException $e) {
        // DB接続に失敗したときは、わかりやすいエラーを出して止める
        if (APP_DEBUG) {
            echo '<h1>DB接続エラー</h1>';
            echo '<p>' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8') . '</p>';
        } else {
            error_log('DB connection error: ' . $e->getMessage());
            echo '内部エラーが発生しました。時間をおいて再度お試しください。';
        }
        exit;
    }

    return $pdo;
}

// =====================
// ログイン中ユーザー取得
// =====================
function current_user() {
    if (empty($_SESSION['user_id'])) {
        return null;
    }
    $pdo = get_pdo();
    $stmt = $pdo->prepare('SELECT * FROM users WHERE id = ?');
    $stmt->execute(array($_SESSION['user_id']));
    $user = $stmt->fetch();
    return $user ? $user : null;
}

// =====================
// 通常ページ用：未ログインなら login.php へ
// =====================
function require_login_page() {
    if (!current_user()) {
        header('Location: login.php');
        exit;
    }
}

// =====================
// API用：未ログインなら 401+JSON
// =====================
function require_login_api() {
    if (!current_user()) {
        http_response_code(401);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(array('success' => false, 'error' => 'login_required'));
        exit;
    }
}

// =====================
// 管理者専用ページ用
// =====================
function require_admin_page() {
    $user = current_user();
    if (!$user || $user['role'] !== 'admin') {
        http_response_code(403);
        echo '権限がありません（管理者のみアクセス可能です）。';
        exit;
    }
}

// =====================
// パスワードハッシュ作成
// =====================
function hash_password($plain) {
    return password_hash($plain, PASSWORD_DEFAULT);
}
