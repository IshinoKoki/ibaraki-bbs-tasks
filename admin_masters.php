<?php
require_once __DIR__.'/config.php';

$pdo  = get_pdo();
$user = current_user();
if (!$user) { header('Location: login.php'); exit; }
if (($user['role'] ?? '') !== 'admin') { header('Location: index.php'); exit; }

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function norm_hex($hex){
  $hex = trim((string)$hex);
  if ($hex === '') return '';
  if ($hex[0] !== '#') $hex = '#'.$hex;
  return preg_match('/^#([0-9a-fA-F]{6})$/',$hex) ? strtoupper($hex) : '';
}
function rgb_to_hex($r,$g,$b){
  $r = max(0,min(255,(int)$r));
  $g = max(0,min(255,(int)$g));
  $b = max(0,min(255,(int)$b));
  return sprintf('#%02X%02X%02X',$r,$g,$b);
}

$tab = $_GET['tab'] ?? 'status';
$tab = in_array($tab,['status','priority','type'],true) ? $tab : 'status';
$map = [
  'status'   => ['table'=>'task_statuses',   'label'=>'ステータス'],
  'priority' => ['table'=>'task_priorities', 'label'=>'優先度'],
  'type'     => ['table'=>'task_types',      'label'=>'種別'],
];
$T   = $map[$tab]['table'];
$LBL = $map[$tab]['label'];

$msg=''; $err='';
if ($_SERVER['REQUEST_METHOD']==='POST'){
  $act = $_POST['action'] ?? '';
  if ($act==='create'){
    $name = trim($_POST['name'] ?? '');
    $sort = (int)($_POST['sort_order'] ?? 0);
    $hex  = norm_hex($_POST['color_hex'] ?? '');
    if ($hex===''){
      $hex = rgb_to_hex($_POST['r'] ?? 217, $_POST['g'] ?? 217, $_POST['b'] ?? 217);
    }
    if ($name===''){ $err='名称を入力してください。'; }
    else{
      $pdo->prepare("INSERT INTO {$T}(name,color,sort_order) VALUES(?,?,?)")->execute([$name,$hex,$sort]);
      $msg="{$LBL}を追加しました。";
    }
  } elseif ($act==='update'){
    $id   =(int)($_POST['id'] ?? 0);
    $name = trim($_POST['name'] ?? '');
    $sort = (int)($_POST['sort_order'] ?? 0);
    $hex  = norm_hex($_POST['color_hex'] ?? '');
    if ($hex===''){ $hex = rgb_to_hex($_POST['r'] ?? 217, $_POST['g'] ?? 217, $_POST['b'] ?? 217); }
    if ($id<=0){ $err='不正なIDです。'; }
    elseif ($name===''){ $err='名称を入力してください。'; }
    else{
      $pdo->prepare("UPDATE {$T} SET name=?, color=?, sort_order=? WHERE id=?")->execute([$name,$hex,$sort,$id]);
      $msg="{$LBL}を更新しました。";
    }
  } elseif ($act==='delete'){
    $id=(int)($_POST['id'] ?? 0);
    if ($id>0){
      $pdo->prepare("DELETE FROM {$T} WHERE id=?")->execute([$id]);
      $msg="{$LBL}を削除しました。";
    } else { $err='不正なIDです。'; }
  }
}
$rows=$pdo->query("SELECT id,name,color,sort_order FROM {$T} ORDER BY sort_order,id")->fetchAll(PDO::FETCH_ASSOC);
?><!doctype html><html lang="ja"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>マスタ管理</title>
<style>
:root{
  --bg:#fef5e7; --panel:#fff; --accent:#f97316; --border:#e5e7eb; --shadow:0 10px 25px rgba(0,0,0,.06); --blue:#2563eb;
  /* タスク管理カード幅と統一 */
  --content-width: 1200px;
}
*{box-sizing:border-box} html,body{overflow-x:hidden}
body{margin:0;background:var(--bg);font-family:system-ui,-apple-system,"Segoe UI",sans-serif;color:#111827}
.back{position:fixed;left:12px;top:12px;z-index:100}
.back a{display:inline-block;padding:8px 12px;border-radius:999px;border:1px solid var(--border);background:#fff;color:#111;text-decoration:none}
.wrap{min-height:100vh;display:grid;place-items:start center;padding:56px 0 24px}
.container{max-width:var(--content-width);width:100%;margin:0 auto;padding:0 20px}
.tabs{display:flex;gap:8px;flex-wrap:wrap;margin-bottom:10px}
.tab{padding:8px 14px;border-radius:999px;border:1px solid var(--accent);background:#fff7ed;color:#9a3412;text-decoration:none;font-size:13px}
.tab.active{background:var(--accent);color:#fff}
.card{background:var(--panel);border:1px solid var(--border);border-radius:16px;box-shadow:var(--shadow);padding:16px}
h1{margin:0 0 8px;font-size:20px;font-weight:700}
.msg{font-size:13px;margin:6px 0}.ok{color:#059669}.err{color:#b91c1c}

/* 追加フォーム：上段3カラム、下段はボタンのみ（重なり不可） */
.add-grid{display:grid;gap:12px;grid-template-columns: 2fr 120px minmax(520px,1fr)}
@media (max-width: 1100px){ .add-grid{grid-template-columns:1fr} }
.field{display:flex;flex-direction:column;gap:6px}
.label{font-size:12px;color:#374151}
.input{font-size:14px;padding:10px;border-radius:10px;border:1px solid var(--border);background:#fff;width:100%}
.actions{display:flex;justify-content:flex-end}
.btn{padding:10px 16px;border:none;border-radius:999px;background:var(--accent);color:#fff;font-weight:700;cursor:pointer}
.btn-blue{background:var(--blue);color:#fff}
.btn-ghost{padding:10px 14px;border-radius:999px;border:1px solid var(--border);background:#fff;color:#111;cursor:pointer}

.table-wrap{width:100%;overflow:auto;border-radius:12px;border:1px solid var(--border);margin-top:10px}
table{width:100%;min-width:980px;border-collapse:separate;border-spacing:0;font-size:13px}
th,td{padding:10px;border-bottom:1px solid #eee;background:#fff;vertical-align:middle;white-space:nowrap}
th{background:#fff7e6;position:sticky;top:0;z-index:1;text-align:center}
td.name{min-width:220px}
td.color{min-width:520px}
td.sort{width:120px;text-align:center}
td.actions{width:180px;text-align:center}
.row-actions{display:flex;gap:8px;justify-content:center}

/* 色入力群：カード幅に合わせ余裕を持たせる */
.cc{display:grid;grid-template-columns: 56px 140px 100px 100px 100px;gap:8px;align-items:center}
.cc input[type=color]{width:56px;height:40px;padding:0;border:1px solid var(--border);border-radius:8px}
.cc .hex,.cc .rgb{padding:10px;border:1px solid var(--border);border-radius:10px}
</style>
</head><body>
<div class="back"><a href="index.php">← タスク一覧に戻る</a></div>
<div class="wrap"><div class="container">
  <div class="tabs">
    <a class="tab <?php if($tab==='status') echo 'active';?>" href="?tab=status">ステータス</a>
    <a class="tab <?php if($tab==='priority') echo 'active';?>" href="?tab=priority">優先度</a>
    <a class="tab <?php if($tab==='type') echo 'active';?>" href="?tab=type">種別</a>
  </div>

  <div class="card">
    <h1><?php echo h($LBL); ?> マスタ管理</h1>
    <?php if($msg):?><div class="msg ok"><?php echo h($msg);?></div><?php endif;?>
    <?php if($err):?><div class="msg err"><?php echo h($err);?></div><?php endif;?>

    <!-- 追加 -->
    <form method="post" autocomplete="off">
      <input type="hidden" name="action" value="create">
      <div class="add-grid">
        <div class="field">
          <label class="label">名称（必須）</label>
          <input class="input" name="name" required>
        </div>
        <div class="field">
          <label class="label">並び順</label>
          <input class="input" type="number" name="sort_order" value="0">
        </div>
        <div class="field">
          <label class="label">色（ピッカー／HEX／RGB）</label>
          <div class="cc">
            <input type="color" value="#D9D9D9">
            <input class="hex" type="text" name="color_hex" value="#D9D9D9" placeholder="#RRGGBB">
            <input class="rgb" type="number" name="r" min="0" max="255" value="217" placeholder="R">
            <input class="rgb" type="number" name="g" min="0" max="255" value="217" placeholder="G">
            <input class="rgb" type="number" name="b" min="0" max="255" value="217" placeholder="B">
          </div>
        </div>
        <div class="actions" style="grid-column:1/-1">
          <button class="btn" type="submit">追加</button>
        </div>
      </div>
    </form>

    <div class="table-wrap">
      <table>
        <thead>
          <tr>
            <th style="width:70px">ID</th>
            <th>名称</th>
            <th>色（ピッカー／HEX／RGB）</th>
            <th style="width:120px">並び順</th>
            <th style="width:180px">操作</th>
          </tr>
        </thead>
        <tbody>
        <?php foreach($rows as $r):
          $hex = norm_hex($r['color'] ?: '#D9D9D9') ?: '#D9D9D9';
          $ri = hexdec(substr($hex,1,2));
          $gi = hexdec(substr($hex,3,2));
          $bi = hexdec(substr($hex,5,2));
        ?>
          <tr>
            <td style="text-align:right"><?php echo (int)$r['id'];?></td>
            <td class="name">
              <form method="post" id="row-<?php echo (int)$r['id'];?>">
                <input type="hidden" name="action" value="update">
                <input type="hidden" name="id" value="<?php echo (int)$r['id'];?>">
                <input class="input" name="name" value="<?php echo h($r['name']);?>">
            </td>
            <td class="color">
                <div class="cc">
                  <input type="color" value="<?php echo $hex;?>">
                  <input class="hex" type="text" name="color_hex" value="<?php echo $hex;?>">
                  <input class="rgb" type="number" name="r" min="0" max="255" value="<?php echo (int)$ri;?>">
                  <input class="rgb" type="number" name="g" min="0" max="255" value="<?php echo (int)$gi;?>">
                  <input class="rgb" type="number" name="b" min="0" max="255" value="<?php echo (int)$bi;?>">
                </div>
            </td>
            <td class="sort">
                <input class="input" type="number" name="sort_order" value="<?php echo (int)$r['sort_order'];?>">
            </td>
            <td class="actions">
                <div class="row-actions">
                  <button class="btn-blue" type="submit">保存</button>
                </div>
              </form>
              <form method="post" onsubmit="return confirm('削除しますか？');" style="display:inline">
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="id" value="<?php echo (int)$r['id'];?>">
                <button class="btn-ghost" type="submit">削除</button>
              </form>
            </td>
          </tr>
        <?php endforeach;?>
        <?php if(!$rows):?><tr><td colspan="5">データがありません。</td></tr><?php endif;?>
        </tbody>
      </table>
    </div>
  </div>
</div></div>

<script>
function clamp255(v){v=parseInt(v||0,10);if(isNaN(v))v=0;return Math.max(0,Math.min(255,v))}
function hex2rgb(hex){hex=(hex||'').trim();if(hex[0]!=='#')hex='#'+hex;const m=hex.match(/^#([0-9a-fA-F]{6})$/);if(!m)return{r:217,g:217,b:217};return{r:parseInt(hex.substr(1,2),16),g:parseInt(hex.substr(3,2),16),b:parseInt(hex.substr(5,2),16)}}
function rgb2hex(r,g,b){const f=v=>('0'+clamp255(v).toString(16)).slice(-2).toUpperCase();return '#'+f(r)+f(g)+f(b)}
function normHex(v){if(!v)return'';v=v.trim();if(v[0]!=='#')v='#'+v;const m=v.match(/^#([0-9a-fA-F]{6})$/);return m?('#'+m[1].toUpperCase()):''}
function bindCC(cc){
  const pk=cc.querySelector('input[type=color]'), hx=cc.querySelector('.hex'), rs=[...cc.querySelectorAll('.rgb')];
  if(!pk||!hx||rs.length!==3) return;
  const syncFromPicker=()=>{hx.value=pk.value.toUpperCase(); const c=hex2rgb(pk.value); rs[0].value=c.r; rs[1].value=c.g; rs[2].value=c.b;}
  const syncFromHex=()=>{const h=normHex(hx.value); if(h){pk.value=h; const c=hex2rgb(h); rs[0].value=c.r; rs[1].value=c.g; rs[2].value=c.b;}}
  const syncFromRgb=()=>{const h=rgb2hex(rs[0].value,rs[1].value,rs[2].value); pk.value=h; hx.value=h;}
  pk.addEventListener('input',syncFromPicker);
  hx.addEventListener('input',syncFromHex);
  rs.forEach(el=>el.addEventListener('input',syncFromRgb));
}
document.querySelectorAll('.cc').forEach(bindCC);
</script>
</body></html>
