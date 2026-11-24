<?php
/**
 * admin_chat.php — inbox ฝั่ง admin
 * แสดงรายชื่อ user ทุกคน + เปิดหน้าต่าง chat ด้านขวา
 */
require_once 'init.php';
require_login();

if (($_SESSION['role'] ?? '') !== 'admin') {
    http_response_code(403);
    exit('403 Forbidden');
}

$adminId = (int)$_SESSION['user_id'];

/* ── ส่งข้อความ (POST) ─────────────────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $convId  = (int)($_POST['conv_id'] ?? 0);
    $body    = trim((string)($_POST['body'] ?? ''));
    $newId   = 0;

    if ($convId > 0 && $body !== '' && mb_strlen($body) <= 2000) {
        // ตรวจว่า conversation มีอยู่
        $chk = $conn->prepare("SELECT id, user_id FROM conversations WHERE id = ? LIMIT 1");
        $chk->bind_param('i', $convId);
        $chk->execute();
        $conv = $chk->get_result()->fetch_assoc();
        $chk->close();

        if ($conv) {
            $ins = $conn->prepare(
                "INSERT INTO messages (conversation_id, sender_id, sender_role, body)
                 VALUES (?, ?, 'admin', ?)"
            );
            $ins->bind_param('iis', $convId, $adminId, $body);
            $ins->execute();
            $newId = (int)$ins->insert_id;
            $ins->close();

            $upd = $conn->prepare(
                "UPDATE conversations
                 SET last_message_at = NOW(), unread_user = unread_user + 1
                 WHERE id = ?"
            );
            $upd->bind_param('i', $convId);
            $upd->execute();
            $upd->close();
        }
    }

    if (!empty($_SERVER['HTTP_X_REQUESTED_WITH'])) {
        header('Content-Type: application/json');
        echo json_encode(['ok' => $newId > 0, 'id' => $newId]);
        exit;
    }
    header("Location: admin_chat.php?conv=" . $convId);
    exit;
}

/* ── เลือก conversation ─────────────────────────────────────── */
$activeConvId = (int)($_GET['conv'] ?? 0);

/* ── รายการ inbox ───────────────────────────────────────────── */
$st = $conn->prepare(
    "SELECT c.id, c.user_id, c.last_message_at, c.unread_admin,
            u.username,
            (SELECT body FROM messages WHERE conversation_id = c.id ORDER BY id DESC LIMIT 1) AS last_body
     FROM conversations c
     JOIN users u ON u.id = c.user_id
     ORDER BY c.last_message_at DESC
     LIMIT 100"
);
$st->execute();
$convList = $st->get_result()->fetch_all(MYSQLI_ASSOC);
$st->close();

/* ── mark อ่านถ้าเปิด conversation ─────────────────────────── */
$activeMessages = [];
$activeUser     = null;

if ($activeConvId > 0) {
    $st = $conn->prepare("SELECT c.id, c.user_id, u.username FROM conversations c JOIN users u ON u.id = c.user_id WHERE c.id = ? LIMIT 1");
    $st->bind_param('i', $activeConvId);
    $st->execute();
    $activeUser = $st->get_result()->fetch_assoc();
    $st->close();

    if ($activeUser) {
        // mark อ่าน
        $upd = $conn->prepare("UPDATE conversations SET unread_admin = 0 WHERE id = ?");
        $upd->bind_param('i', $activeConvId);
        $upd->execute();
        $upd->close();

        $upd2 = $conn->prepare(
            "UPDATE messages SET is_read = 1
             WHERE conversation_id = ? AND sender_role = 'user' AND is_read = 0"
        );
        $upd2->bind_param('i', $activeConvId);
        $upd2->execute();
        $upd2->close();

        // ดึงข้อความ
        $st = $conn->prepare(
            // 200 ข้อความล่าสุด (เดิมได้ 200 ข้อความแรกสุด)
            "SELECT * FROM (
                SELECT id, sender_role, body, created_at
                FROM messages WHERE conversation_id = ?
                ORDER BY id DESC LIMIT 200
             ) t ORDER BY t.id ASC"
        );
        $st->bind_param('i', $activeConvId);
        $st->execute();
        $activeMessages = $st->get_result()->fetch_all(MYSQLI_ASSOC);
        $st->close();
    }
}

$totalUnread = array_sum(array_column($convList, 'unread_admin'));
?>
<!doctype html>
<html lang="th">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <link rel="stylesheet" href="assets/style.css?v=<?= @filemtime(__DIR__.'/assets/style.css') ?>">
  <title>WorkSync Admin — ข้อความลูกค้า</title>
  <style>
    body { background: var(--n50, #f8f9fa); }

    .chat-layout {
      max-width: 1100px;
      margin: 28px auto;
      padding: 0 16px 60px;
      display: flex;
      gap: 18px;
      align-items: flex-start;
    }

    /* ── Sidebar ── */
    .inbox-panel {
      width: 280px;
      flex-shrink: 0;
      background: #fff;
      border: 1px solid var(--n200, #e9ecef);
      border-radius: 12px;
      overflow: hidden;
    }
    .inbox-head {
      padding: 16px 18px;
      border-bottom: 1px solid var(--n200, #e9ecef);
      display: flex;
      align-items: center;
      justify-content: space-between;
    }
    .inbox-head-title {
      font-weight: 700;
      font-size: 15px;
      color: var(--n900, #212529);
    }
    .unread-badge {
      background: var(--red, #c8102e);
      color: #fff;
      font-size: 11px;
      font-weight: 700;
      padding: 2px 7px;
      border-radius: 20px;
    }

    .inbox-list { overflow-y: auto; max-height: 620px; }

    .inbox-item {
      display: flex;
      align-items: center;
      gap: 12px;
      padding: 13px 16px;
      border-bottom: 1px solid var(--n100, #f1f3f5);
      text-decoration: none;
      transition: background .12s;
      cursor: pointer;
    }
    .inbox-item:hover { background: var(--n50, #f8f9fa); text-decoration: none; }
    .inbox-item.active { background: var(--primary-light, #e8f0f9); }

    .inbox-av {
      width: 38px; height: 38px; border-radius: 50%;
      background: var(--n200, #e9ecef);
      color: var(--n700, #495057);
      display: flex; align-items: center; justify-content: center;
      font-size: 14px; font-weight: 700;
      flex-shrink: 0;
    }
    .inbox-av.has-unread { background: var(--primary, #0f4c81); color: #fff; }

    .inbox-info { flex: 1; min-width: 0; }
    .inbox-name {
      font-size: 14px; font-weight: 600;
      color: var(--n800, #343a40);
      white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
    }
    .inbox-preview {
      font-size: 12px; color: var(--n600, #6c757d);
      white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
      margin-top: 2px;
    }
    .inbox-meta { text-align: right; flex-shrink: 0; }
    .inbox-time { font-size: 11px; color: var(--n500, #adb5bd); }
    .inbox-dot {
      width: 8px; height: 8px; border-radius: 50%;
      background: var(--red, #c8102e);
      margin: 4px 0 0 auto;
    }

    .inbox-empty {
      padding: 32px 16px;
      text-align: center;
      color: var(--n500, #adb5bd);
      font-size: 14px;
    }

    /* ── Chat panel ── */
    .chat-panel {
      flex: 1;
      min-width: 0;
      background: #fff;
      border: 1px solid var(--n200, #e9ecef);
      border-radius: 12px;
      overflow: hidden;
      display: flex;
      flex-direction: column;
    }
    .chat-ph {
      flex: 1;
      display: flex;
      flex-direction: column;
      align-items: center;
      justify-content: center;
      color: var(--n400, #ced4da);
      gap: 10px;
      min-height: 500px;
    }
    .chat-ph-icon { font-size: 48px; opacity: .4; }

    .chat-head {
      padding: 14px 20px;
      border-bottom: 1px solid var(--n200, #e9ecef);
      display: flex;
      align-items: center;
      gap: 12px;
    }
    .chat-head-av {
      width: 36px; height: 36px; border-radius: 50%;
      background: var(--primary, #0f4c81); color: #fff;
      display: flex; align-items: center; justify-content: center;
      font-weight: 700; font-size: 14px;
    }
    .chat-head-name { font-weight: 700; font-size: 15px; color: var(--n900, #212529); }
    .chat-head-sub  { font-size: 12px; color: var(--n500, #adb5bd); }

    .chat-msgs {
      flex: 1;
      overflow-y: auto;
      padding: 18px 20px;
      display: flex;
      flex-direction: column;
      gap: 10px;
      min-height: 380px;
      max-height: 460px;
    }

    .msg-row { display: flex; gap: 8px; align-items: flex-end; }
    .msg-row.me { flex-direction: row-reverse; }

    .msg-av {
      width: 28px; height: 28px; border-radius: 50%;
      display: flex; align-items: center; justify-content: center;
      font-size: 11px; font-weight: 700; flex-shrink: 0;
    }
    .msg-av.admin { background: var(--primary, #0f4c81); color: #fff; }
    .msg-av.user  { background: var(--n200, #e9ecef); color: var(--n700, #495057); }

    .msg-bubble {
      max-width: 65%;
      padding: 9px 13px;
      border-radius: 16px;
      font-size: 14px;
      line-height: 1.5;
      word-break: break-word;
    }
    .msg-row.me    .msg-bubble { background: var(--primary, #0f4c81); color: #fff; border-bottom-right-radius: 4px; }
    .msg-row.other .msg-bubble { background: var(--n100, #f1f3f5); color: var(--n800, #343a40); border-bottom-left-radius: 4px; }

    .msg-meta { display: flex; flex-direction: column; }
    .msg-row.me .msg-meta { align-items: flex-end; }
    .msg-time { font-size: 11px; color: var(--n400, #ced4da); margin-top: 3px; }

    .chat-foot {
      border-top: 1px solid var(--n200, #e9ecef);
      padding: 14px 16px;
    }
    .chat-form { display: flex; gap: 10px; align-items: flex-end; }
    .chat-form textarea {
      flex: 1; resize: none;
      border: 1px solid var(--n300, #dee2e6);
      border-radius: 10px;
      padding: 9px 13px;
      font-family: inherit; font-size: 14px;
      line-height: 1.5; max-height: 100px;
      outline: none; transition: border-color .15s, box-shadow .15s;
      color: var(--n900, #212529);
    }
    .chat-form textarea:focus {
      border-color: var(--primary, #0f4c81);
      box-shadow: 0 0 0 3px rgba(15,76,129,.1);
    }
    .send-btn {
      width: 40px; height: 40px; border-radius: 50%;
      border: none; background: var(--primary, #0f4c81); color: #fff;
      cursor: pointer; display: flex; align-items: center; justify-content: center;
      flex-shrink: 0; transition: background .15s, transform .1s;
    }
    .send-btn:hover  { background: var(--primary-dark, #0a3560); }
    .send-btn:active { transform: scale(0.94); }
    .send-btn svg { width: 17px; height: 17px; fill: none; stroke: #fff; stroke-width: 2; stroke-linecap: round; stroke-linejoin: round; }
  </style>
</head>
<body>
<?php include 'navbar.php'; ?>

<div class="chat-layout">

  <!-- Sidebar -->
  <div class="inbox-panel">
    <div class="inbox-head">
      <div class="inbox-head-title">ข้อความลูกค้า</div>
      <?php if ($totalUnread > 0): ?>
        <span class="unread-badge"><?= $totalUnread ?></span>
      <?php endif; ?>
    </div>

    <div class="inbox-list">
      <?php if (empty($convList)): ?>
        <div class="inbox-empty">ยังไม่มีข้อความ</div>
      <?php endif; ?>
      <?php foreach ($convList as $c):
        $isActive  = ($activeConvId === (int)$c['id']);
        $hasUnread = (int)$c['unread_admin'] > 0;
        $initial   = mb_strtoupper(mb_substr($c['username'], 0, 1));
        $preview   = mb_substr($c['last_body'] ?? '', 0, 40);
        $timeAgo   = date('d/m H:i', strtotime($c['last_message_at']));
      ?>
        <a class="inbox-item <?= $isActive ? 'active' : '' ?>"
           href="admin_chat.php?conv=<?= (int)$c['id'] ?>">
          <div class="inbox-av <?= $hasUnread ? 'has-unread' : '' ?>"><?= htmlspecialchars($initial) ?></div>
          <div class="inbox-info">
            <div class="inbox-name"><?= htmlspecialchars($c['username'], ENT_QUOTES, 'UTF-8') ?></div>
            <div class="inbox-preview"><?= htmlspecialchars($preview ?: '—', ENT_QUOTES, 'UTF-8') ?></div>
          </div>
          <div class="inbox-meta">
            <div class="inbox-time"><?= $timeAgo ?></div>
            <?php if ($hasUnread): ?><div class="inbox-dot"></div><?php endif; ?>
          </div>
        </a>
      <?php endforeach; ?>
    </div>
  </div>

  <!-- Chat panel -->
  <div class="chat-panel">
    <?php if (!$activeUser): ?>
      <div class="chat-ph">
        <div class="chat-ph-icon">💬</div>
        <div style="font-weight:600; color:var(--n600,#6c757d);">เลือกการสนทนาเพื่อตอบกลับ</div>
      </div>
    <?php else: ?>
      <div class="chat-head">
        <div class="chat-head-av"><?= htmlspecialchars(mb_strtoupper(mb_substr($activeUser['username'],0,1)), ENT_QUOTES, 'UTF-8') ?></div>
        <div>
          <div class="chat-head-name"><?= htmlspecialchars($activeUser['username'], ENT_QUOTES, 'UTF-8') ?></div>
          <div class="chat-head-sub">ลูกค้า</div>
        </div>
      </div>

      <div class="chat-msgs" id="chatMsgs">
        <?php foreach ($activeMessages as $m):
          $isMe  = ($m['sender_role'] === 'admin');
          $cls   = $isMe ? 'me' : 'other';
          $avCls = $isMe ? 'admin' : 'user';
          $avTxt = $isMe ? 'AD' : htmlspecialchars(mb_strtoupper(mb_substr($activeUser['username'],0,1)), ENT_QUOTES, 'UTF-8');
          $time  = date('d/m H:i', strtotime($m['created_at']));
        ?>
          <div class="msg-row <?= $cls ?>" data-id="<?= (int)$m['id'] ?>">
            <div class="msg-av <?= $avCls ?>"><?= $avTxt ?></div>
            <div class="msg-meta">
              <div class="msg-bubble"><?= nl2br(htmlspecialchars($m['body'], ENT_QUOTES, 'UTF-8')) ?></div>
              <div class="msg-time"><?= $time ?></div>
            </div>
          </div>
        <?php endforeach; ?>
      </div>

      <div class="chat-foot">
        <form class="chat-form" id="adminForm">
          <?= csrf_tag() ?>
          <input type="hidden" name="conv_id" value="<?= (int)$activeConvId ?>">
          <textarea id="adminInput" name="body" rows="1"
                    placeholder="พิมพ์ข้อความตอบกลับ..." maxlength="2000"></textarea>
          <button type="submit" class="send-btn">
            <svg viewBox="0 0 24 24"><line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/></svg>
          </button>
        </form>
      </div>
    <?php endif; ?>
  </div>
</div>

<?php if ($activeUser): ?>
<script>
(function(){
  const msgs   = document.getElementById('chatMsgs');
  const form   = document.getElementById('adminForm');
  const input  = document.getElementById('adminInput');
  const convId = <?= $activeConvId ?>;
  let lastId   = <?= empty($activeMessages) ? 0 : (int)end($activeMessages)['id'] ?>;
  let polling;

  function scrollBottom(){ msgs.scrollTop = msgs.scrollHeight; }
  scrollBottom();

  input.addEventListener('input', function(){
    this.style.height = 'auto';
    this.style.height = Math.min(this.scrollHeight, 100) + 'px';
  });
  input.addEventListener('keydown', function(e){
    if (e.key === 'Enter' && !e.shiftKey){ e.preventDefault(); form.dispatchEvent(new Event('submit')); }
  });

  function esc(s){ return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#39;'); }
  function nl2br(s){ return s.replace(/\n/g,'<br>'); }

  // pending = bubble ที่โชว์ทันทีตอนกดส่ง ยังไม่มี id จริง
  // (เดิมใช้ ++lastId เป็น id ปลอม: ข้อความตัวเองขึ้นซ้ำ และข้ามข้อความลูกค้าที่ id ชนกันได้)
  function appendBubble(m, pending){
    const isMe = (m.sender_role === 'admin');
    const cls  = isMe ? 'me' : 'other';
    const avCls = isMe ? 'admin' : 'user';
    const avTxt = isMe ? 'AD' : <?= json_encode(mb_strtoupper(mb_substr($activeUser['username'],0,1)), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    const d = (m.created_at instanceof Date) ? m.created_at : new Date(String(m.created_at).replace(' ','T'));
    const t = d.toLocaleDateString('th-TH',{day:'2-digit',month:'2-digit'}) + ' ' +
              d.toLocaleTimeString('th-TH',{hour:'2-digit',minute:'2-digit'});
    const row = document.createElement('div');
    row.className = 'msg-row ' + cls + (pending ? ' pending' : '');
    if (!pending) row.dataset.id = m.id;
    row.innerHTML = `<div class="msg-av ${avCls}">${esc(avTxt)}</div>
      <div class="msg-meta"><div class="msg-bubble">${nl2br(esc(m.body))}</div>
      <div class="msg-time">${t}</div></div>`;
    msgs.appendChild(row);
    scrollBottom();
    return row;
  }

  form.addEventListener('submit', function(e){
    e.preventDefault();
    const val = input.value.trim();
    if (!val) return;
    const fd = new FormData(form);
    fd.set('body', val);
    input.value = ''; input.style.height = 'auto';
    const row = appendBubble({ sender_role:'admin', body: val, created_at: new Date() }, true);
    fetch('admin_chat.php', {
      method:'POST',
      headers:{'X-Requested-With':'XMLHttpRequest'},
      body: fd
    })
      .then(r => r.json())
      .then(d => {
        if (!d.ok) throw new Error('send failed');
        if (row.classList.contains('pending')) { row.classList.remove('pending'); row.dataset.id = d.id; }
      })
      .catch(() => { row.style.opacity = '.5'; row.title = 'ส่งไม่สำเร็จ'; });
  });

  function poll(){
    fetch('chat_poll.php?conv_id=' + convId + '&last_id=' + lastId + '&role=admin')
      .then(r => r.json())
      .then(data => {
        if (data.messages && data.messages.length){
          data.messages.forEach(m => {
            const id = parseInt(m.id, 10);
            lastId = Math.max(lastId, id);
            if (msgs.querySelector('[data-id="'+id+'"]')) return;
            if (m.sender_role === 'admin'){
              const p = msgs.querySelector('.msg-row.pending');
              if (p){ p.classList.remove('pending'); p.dataset.id = id; return; }
            }
            appendBubble(m);
          });
        }
      }).catch(()=>{});
  }

  polling = setInterval(poll, 3000);
  document.addEventListener('visibilitychange', function(){
    if (document.hidden){ clearInterval(polling); }
    else { polling = setInterval(poll, 3000); }
  });
})();
</script>
<?php endif; ?>
</body>
</html>
