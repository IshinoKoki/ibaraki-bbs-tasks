<?php
require_once __DIR__ . '/config.php';

$user = current_user();
if (!$user){
    header("Location: login.php");
    exit;
}
?>
<!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<title>茨木BBS会タスク管理｜通知一覧</title>
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
    width:min(520px, 100% - 40px);
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
    margin:0 0 8px;
    font-size:20px;
    font-weight:700;
  }

  .notif-list{
    margin-top:8px;
  }
  .notif-item{
    border-bottom:1px solid var(--border);
    padding:10px 0;
    font-size:13px;
    display:flex;
    justify-content:space-between;
    gap:8px;
    align-items:flex-start;
  }
  .notif-item:last-child{
    border-bottom:none;
  }
  .notif-main{
    flex:1;
  }
  .notif-meta{
    font-size:11px;
    color:#6b7280;
    margin-top:2px;
  }
  .notif-actions{
    display:flex;
    gap:6px;
    flex-shrink:0;
  }
  .btn-pill{
    padding:6px 12px;
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
  .unread{
    background:#fff7eb;
    border-radius:10px;
    padding:8px;
  }
</style>
</head>
<body>

<div class="backlink"><a href="index.php">← タスク一覧に戻る</a></div>

<div class="wrap">
  <div class="container">
    <div class="card">
      <h1>茨木BBS会タスク管理｜通知一覧</h1>
      <div id="notif-container" class="notif-list"></div>
    </div>
  </div>
</div>

<script>
async function loadNotifications(){
    const res = await fetch("api/notifications.php", {
        method:"POST",
        headers:{"Content-Type":"application/json"},
        body:JSON.stringify({action:"list"})
    });

    const json = await res.json();
    const cont = document.getElementById("notif-container");

    cont.innerHTML = "";

    json.notifications.forEach(n=>{
        const div = document.createElement("div");
        div.className = "notif-item" + (n.is_read==0 ? " unread" : "");

        div.innerHTML = `
          <div class="notif-main">
            <div><strong>${n.sender_name}</strong>：${n.message}</div>
            <div class="notif-meta">${n.created_at}</div>
          </div>
          <div class="notif-actions">
            <button class="btn-pill gray" onclick="markRead(${n.id})">既読</button>
            <button class="btn-pill orange" onclick="openTask(${n.task_id}, ${n.team_id || 0})">タスクへ</button>
          </div>
        `;

        cont.appendChild(div);
    });
}

async function markRead(id){
    await fetch("api/notifications.php",{
        method:"POST",
        headers:{"Content-Type":"application/json"},
        body:JSON.stringify({action:"read", id})
    });
    loadNotifications();
}

function openTask(id, teamId){
    if (!id) return;

    const params = new URLSearchParams();
    if (teamId) params.set('team_id', teamId);
    params.set('task_id', id);

    window.location.href = 'index.php?' + params.toString();
}

loadNotifications();
</script>

</body>
</html>
