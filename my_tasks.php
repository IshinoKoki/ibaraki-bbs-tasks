<?php
// /tasks/my_tasks.php
require_once __DIR__ . '/config.php';

$pdo = get_pdo();

// ログインしていなければログインページへ
if (!current_user()) {
    header('Location: login.php');
    exit;
}

$user = current_user();
$uid  = (int)$user['id'];

// 元のクエリパラメータをベースにする（team_id もそのまま引き継ぐ）
$params = $_GET;

// assignee_ids（担当者ID）の正規化
$assigneeIds = [];
if (isset($params['assignee_ids'])) {
    if (is_array($params['assignee_ids'])) {
        $assigneeIds = array_map('intval', $params['assignee_ids']);
    } else {
        $tmp = $params['assignee_ids'];
        if (is_string($tmp)) {
            foreach (explode(',', $tmp) as $v) {
                $v = trim($v);
                if ($v !== '') {
                    $assigneeIds[] = (int)$v;
                }
            }
        } else {
            $assigneeIds[] = (int)$tmp;
        }
    }
}

// 自分のIDを必ず含める
if (!in_array($uid, $assigneeIds, true)) {
    $assigneeIds[] = $uid;
}
$params['assignee_ids'] = $assigneeIds;

// 「マイタスクモード」フラグ
$params['my'] = 1;

// index.php にリダイレクト（ここから先は index.php 側が画面を描画）
$query = http_build_query($params);
header('Location: index.php' . ($query ? ('?' . $query) : ''));
exit;
