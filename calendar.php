<?php
// /tasks/calendar.php
require_once __DIR__ . '/config.php';

$pdo = get_pdo();
if (!current_user()) { header('Location: login.php'); exit; }
$user = current_user();
$uid  = (int)$user['id'];

// チーム一覧（index.php と同じ前提）
$teamsStmt = $pdo->query('SELECT id, name FROM teams ORDER BY id');
$teamsList = $teamsStmt->fetchAll();
if ($teamsList) {
    $team_id = isset($_GET['team_id']) ? (int)$_GET['team_id'] : (int)$teamsList[0]['id'];
} else {
    $team_id = null;
}

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
?>
<!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<title>茨木BBS会タスク管理｜タスクカレンダー</title>
<meta name="viewport" content="width=device-width,initial-scale=1">
<style>
  :root{
    --bg:#fef5e7; --panel:#fff; --muted:#6b7280; --accent:#f97316; --accent-weak:#fff7ed; --blue:#2563eb;
    --border:#e5e7eb; --shadow:0 10px 25px rgba(0,0,0,.06);
  }
  *{ box-sizing:border-box; }
  body{
    font-family:system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;
    margin:0;
    background:var(--bg);
    color:#0f172a;
  }

  /* ===== 固定ヘッダー（index.php と同じデザイン） ===== */
  .topbar{
    position:sticky;
    top:0;
    z-index:50;
    background:#fff;
    border-bottom:1px solid var(--border);
    box-shadow:0 2px 8px rgba(0,0,0,.03);
  }
  .topbar-inner{
    max-width:1200px;
    margin:0 auto;
    padding:10px 20px;
  }
  .tb-row{
    display:flex;
    justify-content:space-between;
    align-items:center;
    gap:10px;
    flex-wrap:wrap;
  }
  .tb-title{
    font-weight:700;
    color:#111827;
  }
  .tb-links{
    font-size:12px;
    color:#4b5563;
  }
  .tb-links a{
    color:var(--blue);
    text-decoration:none;
    margin-left:6px;
  }

  .tb-teams{
    display:flex;
    gap:8px;
    flex-wrap:wrap;
    margin-top:8px;
  }
  .tb-team{
    padding:6px 12px;
    border-radius:999px;
    border:1px solid var(--accent);
    font-size:12px;
    text-decoration:none;
    color:#9a3412;
    background:var(--accent-weak);
  }
  .tb-team.active{
    background:var(--accent);
    color:#fff;
    border-color:var(--accent);
    font-weight:600;
  }

  .tb-pages{
    display:flex;
    gap:8px;
    flex-wrap:wrap;
    margin-top:6px;
  }
  .tb-page{
    padding:4px 12px;
    border-radius:999px;
    border:1px solid transparent;
    font-size:12px;
    text-decoration:none;
    color:#374151;
    background:#f3f4f6;
  }
  .tb-page.active{
    background:var(--accent);
    color:#fff;
    border-color:var(--accent);
    font-weight:600;
  }

  /* ===== カレンダー画面メイン ===== */
  .page-wrap{
    max-width:900px; /* 0.75倍くらいにして小さめに表示 */
    margin:16px auto 32px;
    padding:0 16px;
  }
  .calendar-card{
    background:#fff;
    border-radius:16px;
    box-shadow:0 4px 12px rgba(0,0,0,0.06);
    padding:16px 20px 20px;
  }
  .calendar-header{
    display:flex;
    justify-content:space-between;
    align-items:center;
    gap:8px;
    margin-bottom:12px;
    flex-wrap:wrap;
  }
  .calendar-title{
    font-size:18px;
    font-weight:600;
  }
  .calendar-nav{
    display:flex;
    align-items:center;
    gap:8px;
  }
  .btn-nav{
    border-radius:999px;
    padding:4px 10px;
    border:1px solid var(--border);
    background:#f9fafb;
    font-size:12px;
    cursor:pointer;
  }
  .btn-nav:hover{
    background:#e5e7eb;
  }

  .calendar-grid{
    display:grid;
    grid-template-columns:repeat(7,1fr);
    border-radius:12px;
    overflow:hidden;
    border:1px solid #e5e7eb;
    background:#fff;
  }
  .calendar-day-header{
    background:#f9fafb;
    font-size:12px;
    text-align:center;
    font-weight:600;
    padding:6px 0;
    border-bottom:1px solid #e5e7eb;
  }
  .calendar-day-cell{
    border-right:1px solid #eee;
    border-bottom:1px solid #eee;
    min-height:70px;
    padding:4px 4px 6px;
    box-sizing:border-box;
    background:#fff;
    font-size:11px;
    vertical-align:top;
  }
  .calendar-day-cell:nth-child(7n){
    border-right:none;
  }
  .calendar-day-cell.other-month{
    background:#f9fafb;
    color:#9ca3af;
  }
  .day-number{
    font-weight:600;
    margin-bottom:2px;
  }
  .day-tasks{
    margin-top:2px;
    display:flex;
    flex-direction:column;
    gap:2px;
  }
  .day-task{
    font-size:10px;
    padding:2px 4px;
    border-radius:4px;
    background:#fee2e2;
    color:#991b1b;
    cursor:pointer;
    white-space:nowrap;
    overflow:hidden;
    text-overflow:ellipsis;
  }
  .error-msg{
    color:#b91c1c;
    font-size:12px;
    margin-top:8px;
    display:none;
  }
</style>
</head>
<body>

<header class="topbar">
  <div class="topbar-inner">
    <div class="tb-row">
      <div class="tb-title">茨木BBS会タスク管理</div>
      <div class="tb-links">
        <?php echo h($user['display_name'] ?? ''); ?> さん

        ／ <a href="notifications.php" style="position:relative;text-decoration:none;color:#2563eb;">
             🔔 通知
             <span id="notif-badge"
                   style="background:red;color:white;border-radius:50%;padding:2px 6px;
                          font-size:10px;position:absolute;top:-6px;right:-10px;display:none;"></span>
           </a>

        ／ <a href="change_password.php">パスワード変更</a>
        <?php if (!empty($user['role']) && $user['role'] === 'admin'): ?>
          ／ <a href="admin_users.php">ユーザー管理</a>
          ／ <a href="admin_masters.php">マスタ管理</a>
        <?php endif; ?>
        ／ <a href="logout.php">ログアウト</a>
      </div>
    </div>

    <!-- チーム切替（index.php と同じ見た目） -->
    <div class="tb-teams">
      <?php foreach ($teamsList as $t): $tid = (int)$t['id']; $isActive = ($tid === $team_id); ?>
<?php $currentPage = basename($_SERVER['PHP_SELF']); ?>

<a class="tb-team<?php echo $isActive ? ' active':''; ?>"
   href="<?php echo $currentPage; ?>?team_id=<?php echo $tid; ?>">
  <?php echo h($t['name']); ?>
</a>

      <?php endforeach; ?>
    </div>

    <!-- ページ切替タブ（URLも index.php と揃える） -->
    <div class="tb-pages">
      <?php $self = basename($_SERVER['PHP_SELF']); ?>
      <a href="index.php?team_id=<?php echo (int)$team_id; ?>"
         class="tb-page <?php echo $self === 'index.php' ? 'active' : ''; ?>">
        タスク一覧
      </a>
      <a href="my_tasks.php?team_id=<?php echo (int)$team_id; ?>"
         class="tb-page <?php echo $self === 'my_tasks.php' ? 'active' : ''; ?>">
        マイタスク
      </a>
      <a href="calendar.php?team_id=<?php echo (int)$team_id; ?>"
         class="tb-page <?php echo $self === 'calendar.php' ? 'active' : ''; ?>">
        タスクカレンダー
      </a>
    </div>
  </div>
</header>

<div class="page-wrap">
  <div class="calendar-card">
    <div class="calendar-header">
      <div class="calendar-title" id="calendarTitle"></div>
      <div class="calendar-nav">
        <button class="btn-nav" id="prevMonth">&lt; 前の月</button>
        <button class="btn-nav" id="todayBtn">今月</button>
        <button class="btn-nav" id="nextMonth">次の月 &gt;</button>
      </div>
    </div>

    <div class="calendar-grid" id="calendarGrid"></div>
    <div class="error-msg" id="calendarError">カレンダーの読み込みに失敗しました。</div>
  </div>
</div>

<script>
const teamId = <?php echo (int)$team_id; ?>;

let current = new Date(); // 表示中の年月

const titleEl = document.getElementById('calendarTitle');
const gridEl  = document.getElementById('calendarGrid');
const errEl   = document.getElementById('calendarError');

function formatYmd(date){
  const y = date.getFullYear();
  const m = String(date.getMonth() + 1).padStart(2,'0');
  const d = String(date.getDate()).padStart(2,'0');
  return `${y}-${m}-${d}`;
}

function renderCalendar(eventsByDate){
  const year  = current.getFullYear();
  const month = current.getMonth() + 1;

  titleEl.textContent = `${year}年 ${month}月`;
  gridEl.innerHTML = '';

  const weekdays = ['日','月','火','水','木','金','土'];
  for (let i=0;i<7;i++){
    const h = document.createElement('div');
    h.className = 'calendar-day-header';
    h.textContent = weekdays[i];
    gridEl.appendChild(h);
  }

  const first = new Date(year, month-1, 1);
  const last  = new Date(year, month, 0);
  const startWeekday = first.getDay();
  const daysInMonth  = last.getDate();
  const prevLast = new Date(year, month-1, 0).getDate();

  const totalCells = Math.ceil((startWeekday + daysInMonth) / 7) * 7;

  for (let idx=0; idx<totalCells; idx++){
    const cell = document.createElement('div');
    cell.className = 'calendar-day-cell';

    let displayYear  = year;
    let displayMonth = month;
    let displayDate;

    if (idx < startWeekday){
      // 前月
      displayMonth = month - 1;
      if (displayMonth <= 0){
        displayMonth = 12;
        displayYear--;
      }
      displayDate = prevLast - (startWeekday - 1 - idx);
      cell.classList.add('other-month');
    } else if (idx >= startWeekday + daysInMonth){
      // 翌月
      displayMonth = month + 1;
      if (displayMonth > 12){
        displayMonth = 1;
        displayYear++;
      }
      displayDate = idx - (startWeekday + daysInMonth) + 1;
      cell.classList.add('other-month');
    } else {
      // 当月
      displayDate = idx - startWeekday + 1;
    }

    const dateObj = new Date(displayYear, displayMonth-1, displayDate);
    const ymd = formatYmd(dateObj);

    const num = document.createElement('div');
    num.className = 'day-number';
    num.textContent = displayDate;
    cell.appendChild(num);

    const tasksWrap = document.createElement('div');
    tasksWrap.className = 'day-tasks';

    const tasks = eventsByDate[ymd] || [];
    tasks.forEach(ev => {
      const t = document.createElement('div');
      t.className = 'day-task';
      if (ev.color){
        t.style.background = ev.color;
        t.style.color = '#fff';
      }
      t.textContent = ev.title || '';
      t.dataset.taskId = ev.id || '';
      t.addEventListener('click', () => {
        const taskId = t.dataset.taskId;
        if (taskId && window.openTaskDetailModal){
          window.openTaskDetailModal(taskId);
        } else if (taskId){
          location.href = 'index.php?team_id=' + teamId + '&task_id=' + taskId;
        }
      });
      tasksWrap.appendChild(t);
    });

    cell.appendChild(tasksWrap);
    gridEl.appendChild(cell);
  }
}

async function loadCalendar(){
  errEl.style.display = 'none';
  const year  = current.getFullYear();
  const month = current.getMonth() + 1;

  try{
    const res = await fetch('api/calendar.php?team_id=' + teamId + '&year=' + year + '&month=' + month);
    if (!res.ok) throw new Error('HTTP ' + res.status);

    const json = await res.json();
    if (!json.ok) throw new Error('api_error');

    const events = json.events || [];
    const map = {};
    events.forEach(ev => {
      const d = ev.date;
      if (!map[d]) map[d] = [];
      map[d].push(ev);
    });
    renderCalendar(map);
  }catch(e){
    console.error(e);
    errEl.style.display = 'block';
  }
}

document.getElementById('prevMonth').addEventListener('click', () => {
  current = new Date(current.getFullYear(), current.getMonth() - 1, 1);
  loadCalendar();
});
document.getElementById('nextMonth').addEventListener('click', () => {
  current = new Date(current.getFullYear(), current.getMonth() + 1, 1);
  loadCalendar();
});
document.getElementById('todayBtn').addEventListener('click', () => {
  current = new Date();
  loadCalendar();
});

// 通知バッジ（index.php と同じ挙動）
async function updateNotificationBadge(){
  try{
    const res = await fetch('api/notifications.php', {
      method: 'POST',
      headers: {'Content-Type':'application/json'},
      body: JSON.stringify({action:'list'})
    });
    const json = await res.json();
    const notifications = json.notifications || [];
    const unread = notifications.filter(n => n.is_read == 0).length;
    const badge = document.getElementById('notif-badge');
    if (!badge) return;
    if (unread > 0){
      badge.style.display = 'inline-block';
      badge.textContent = unread;
    } else {
      badge.style.display = 'none';
    }
  }catch(e){
    console.error(e);
  }
}

updateNotificationBadge();
loadCalendar();
</script>
</body>
</html>
