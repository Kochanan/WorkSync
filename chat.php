<?php
/**
 * chat.php — หน้าแชทฝั่งผู้ใช้ทั่วไป
 * ผู้ใช้คนหนึ่ง = 1 conversation กับ admin
 */
require_once 'init.php';
require_login();

$uid  = (int)$_SESSION['user_id'];
$role = $_SESSION['role'] ?? 'user';

// admin ต้องใช้หน้า admin_chat.php
if ($role === 'admin') {
    header('Location: admin_chat.php');
    exit;
}

/* ── หา / สร้าง conversation ───────────────────────────────── */
$st = $conn->prepare("SELECT id FROM conversations WHERE user_id = ? LIMIT 1");
$st->bind_param('i', $uid);
$st->execute();
$conv = $st->get_result()->fetch_assoc();
$st->close();

if (!$conv) {
    $ins = $conn->prepare("INSERT INTO conversations (user_id, last_message_at) VALUES (?, NOW())");
    $ins->bind_param('i', $uid);
    $ins->execute();
    $convId = (int)$ins->insert_id;
    $ins->close();
} else {
    $convId = (int)$conv['id'];
}

/* ── ส่งข้อความ (POST) ─────────────────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $body = trim((string)($_POST['body'] ?? ''));
    $newId = 0;
    if ($body !== '' && mb_strlen($body) <= 2000) {
        $ins = $conn->prepare(
            "INSERT INTO messages (conversation_id, sender_id, sender_role, body)
             VALUES (?, ?, 'user', ?)"
        );
        $ins->bind_param('iis', $convId, $uid, $body);
        $ins->execute();
        $newId = (int)$ins->insert_id;
        $ins->close();

        // อัปเดต conversation
        $upd = $conn->prepare(
            "UPDATE conversations
             SET last_message_at = NOW(), unread_admin = unread_admin + 1
             WHERE id = ?"
        );
        $upd->bind_param('i', $convId);
        $upd->execute();
        $upd->close();
    }

    if (!empty($_SERVER['HTTP_X_REQUESTED_WITH'])) {
        header('Content-Type: application/json');
        // บอกตามจริงว่าบันทึกได้ไหม (เดิมตอบ ok ทุกกรณี แม้ข้อความว่าง/ยาวเกินจะถูกทิ้ง)
        echo json_encode(['ok' => $newId > 0, 'id' => $newId]);
        exit;
    }
    header("Location: chat.php");
    exit;
}

/* ── mark อ่านฝั่ง user ────────────────────────────────────── */
$upd = $conn->prepare("UPDATE conversations SET unread_user = 0 WHERE id = ?");
$upd->bind_param('i', $convId);
$upd->execute();
$upd->close();

$upd2 = $conn->prepare(
    "UPDATE messages SET is_read = 1
     WHERE conversation_id = ? AND sender_role = 'admin' AND is_read = 0"
);
$upd2->bind_param('i', $convId);
$upd2->execute();
$upd2->close();

/* ── ดึงข้อความ ────────────────────────────────────────────── */
// เอา 200 ข้อความล่าสุด (เดิม ORDER BY ASC LIMIT 200 ได้ 200 ข้อความ "แรกสุด" พอแชทยาวข้อความใหม่จะไม่ขึ้น)
$st = $conn->prepare(
    "SELECT * FROM (
        SELECT m.id, m.sender_role, m.body, m.created_at
        FROM messages m
        WHERE m.conversation_id = ?
        ORDER BY m.id DESC
        LIMIT 200
     ) t ORDER BY t.id ASC"
);
$st->bind_param('i', $convId);
$st->execute();
$messages = $st->get_result()->fetch_all(MYSQLI_ASSOC);
$st->close();
?>
<!doctype html>
<html lang="th">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <link rel="stylesheet" href="assets/style.css?v=<?= @filemtime(__DIR__.'/assets/style.css') ?>">
  <title>WorkSync — ข้อความ</title>
  <style>
    .chat-wrap {
      max-width: 760px;
      margin: 28px auto;
      padding: 0 16px 80px;
    }
    .chat-header {
      background: #fff;
      border: 1px solid var(--n200, #e9ecef);
      border-radius: 12px 12px 0 0;
      padding: 16px 20px;
      display: flex;
      align-items: center;
      gap: 12px;
      border-bottom: 1px solid var(--n200, #e9ecef);
    }
    .chat-avatar {
      width: 40px; height: 40px;
      border-radius: 50%;
      background: var(--primary, #0f4c81);
      color: #fff;
      display: flex; align-items: center; justify-content: center;
      font-weight: 700; font-size: 15px;
      flex-shrink: 0;
    }
    .chat-header-info { flex: 1; }
    .chat-header-name { font-weight: 700; font-size: 15px; color: var(--n900, #212529); }
    .chat-header-sub  { font-size: 12px; color: var(--n600, #6c757d); margin-top: 1px; }
    .online-dot {
      width: 8px; height: 8px; border-radius: 50%;
      background: #22c55e;
      display: inline-block;
      margin-right: 4px;
    }

    .chat-body {
      background: #fff;
      border-left: 1px solid var(--n200, #e9ecef);
      border-right: 1px solid var(--n200, #e9ecef);
      min-height: 460px;
      max-height: 520px;
      overflow-y: auto;
      padding: 20px 20px 12px;
      display: flex;
      flex-direction: column;
      gap: 10px;
      scroll-behavior: smooth;
    }

    .msg-row {
      display: flex;
      gap: 8px;
      align-items: flex-end;
    }
    .msg-row.me { flex-direction: row-reverse; }

    .msg-av {
      width: 30px; height: 30px;
      border-radius: 50%;
      display: flex; align-items: center; justify-content: center;
      font-size: 12px; font-weight: 700;
      flex-shrink: 0;
    }
    .msg-av.admin { background: var(--primary, #0f4c81); color: #fff; }
    .msg-av.me    { background: var(--n200, #e9ecef); color: var(--n700, #495057); }

    .msg-bubble {
      max-width: 68%;
      padding: 10px 14px;
      border-radius: 16px;
      font-size: 14px;
      line-height: 1.55;
      word-break: break-word;
    }
    .msg-row.me    .msg-bubble { background: var(--primary, #0f4c81); color: #fff; border-bottom-right-radius: 4px; }
    .msg-row.other .msg-bubble { background: var(--n100, #f1f3f5); color: var(--n800, #343a40); border-bottom-left-radius: 4px; }

    .msg-time {
      font-size: 11px;
      color: var(--n500, #adb5bd);
      margin-top: 4px;
      text-align: right;
    }
    .msg-row.other .msg-time { text-align: left; }
    .msg-meta { display: flex; flex-direction: column; }
    .msg-row.me .msg-meta { align-items: flex-end; }

    .chat-empty {
      flex: 1;
      display: flex;
      flex-direction: column;
      align-items: center;
      justify-content: center;
      color: var(--n500, #adb5bd);
      gap: 8px;
      padding: 40px 0;
    }
    .chat-empty-icon { font-size: 40px; opacity: .5; }

    .chat-footer {
      background: #fff;
      border: 1px solid var(--n200, #e9ecef);
      border-top: none;
      border-radius: 0 0 12px 12px;
      padding: 14px 16px;
    }
    .chat-form {
      display: flex;
      gap: 10px;
      align-items: flex-end;
    }
    .chat-form textarea {
      flex: 1;
      resize: none;
      border: 1px solid var(--n300, #dee2e6);
      border-radius: 10px;
      padding: 10px 14px;
      font-family: inherit;
      font-size: 14px;
      line-height: 1.5;
      max-height: 120px;
      overflow-y: auto;
      outline: none;
      transition: border-color .15s, box-shadow .15s;
      color: var(--n900, #212529);
    }
    .chat-form textarea:focus {
      border-color: var(--primary, #0f4c81);
      box-shadow: 0 0 0 3px rgba(15,76,129,.1);
    }
    .send-btn {
      width: 42px; height: 42px;
      border-radius: 50%;
      border: none;
      background: var(--primary, #0f4c81);
      color: #fff;
      cursor: pointer;
      display: flex; align-items: center; justify-content: center;
      flex-shrink: 0;
      transition: background .15s, transform .1s;
    }
    .send-btn:hover  { background: var(--primary-dark, #0a3560); }
    .send-btn:active { transform: scale(0.94); }
    .send-btn svg { width: 18px; height: 18px; fill: none; stroke: #fff; stroke-width: 2; stroke-linecap: round; stroke-linejoin: round; }

    .char-count { font-size: 11px; color: var(--n500, #adb5bd); text-align: right; margin-top: 4px; }
  </style>
</head>
<body>
<?php include 'navbar.php'; ?>

<div class="chat-wrap">
  <div style="margin-bottom:18px;">
    <h2 style="font-size:20px; color:var(--n900,#212529);">ข้อความ</h2>
    <p style="font-size:13px; color:var(--n600,#6c757d); margin-top:3px;">พูดคุยกับทีมงาน WorkSync ได้เลยครับ</p>
  </div>

  <div class="chat-header">
    <div class="chat-avatar">WS</div>
    <div class="chat-header-info">
      <div class="chat-header-name">WorkSync Support</div>
      <div class="chat-header-sub"><span class="online-dot"></span>ออนไลน์</div>
    </div>
  </div>

  <div class="chat-body" id="chatBody">
    <?php if (empty($messages)): ?>
      <div class="chat-empty">
        <div class="chat-empty-icon">💬</div>
        <div style="font-weight:600;">ยังไม่มีข้อความ</div>
        <div style="font-size:13px;">ส่งข้อความเพื่อเริ่มการสนทนากับทีมงาน</div>
      </div>
    <?php else: ?>
      <?php foreach ($messages as $m):
        $isMe = ($m['sender_role'] === 'user');
        $rowCls = $isMe ? 'me' : 'other';
        $avCls  = $isMe ? 'me' : 'admin';
        $avText = $isMe ? mb_strtoupper(mb_substr($_SESSION['username'] ?? '', 0, 1)) : 'WS';
        $time   = date('H:i', strtotime($m['created_at']));
        $dateStr = date('d/m/Y', strtotime($m['created_at']));
      ?>
        <div class="msg-row <?= $rowCls ?>" data-id="<?= (int)$m['id'] ?>">
          <div class="msg-av <?= $avCls ?>"><?= htmlspecialchars($avText, ENT_QUOTES, 'UTF-8') ?></div>
          <div class="msg-meta">
            <div class="msg-bubble"><?= nl2br(htmlspecialchars($m['body'], ENT_QUOTES, 'UTF-8')) ?></div>
            <div class="msg-time"><?= $dateStr ?> <?= $time ?></div>
          </div>
        </div>
      <?php endforeach; ?>
    <?php endif; ?>
  </div>

  <div class="chat-footer">
    <form class="chat-form" id="chatForm">
      <?= csrf_tag() ?>
      <div style="flex:1;">
        <textarea id="msgInput" name="body" rows="1"
                  placeholder="พิมพ์ข้อความ..." maxlength="2000"></textarea>
        <div class="char-count"><span id="charCount">0</span>/2000</div>
      </div>
      <button type="submit" class="send-btn" title="ส่งข้อความ">
        <svg viewBox="0 0 24 24"><line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/></svg>
      </button>
    </form>
  </div>
</div>

<script>
(function(){
  const body    = document.getElementById('chatBody');
  const form    = document.getElementById('chatForm');
  const input   = document.getElementById('msgInput');
  const counter = document.getElementById('charCount');
  const convId  = <?= $convId ?>;
  const myRole  = 'user';
  const myName  = <?= json_encode(mb_strtoupper(mb_substr($_SESSION['username'] ?? '',0,1)), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
  let lastId    = <?= empty($messages) ? 0 : (int)end($messages)['id'] ?>;
  let polling;

  /* ── scroll to bottom ── */
  function scrollBottom(){ body.scrollTop = body.scrollHeight; }
  scrollBottom();

  /* ── auto-resize textarea ── */
  input.addEventListener('input', function(){
    this.style.height = 'auto';
    this.style.height = Math.min(this.scrollHeight, 120) + 'px';
    counter.textContent = this.value.length;
  });

  /* ── Enter ส่ง, Shift+Enter ขึ้นบรรทัด ── */
  input.addEventListener('keydown', function(e){
    if (e.key === 'Enter' && !e.shiftKey){ e.preventDefault(); form.dispatchEvent(new Event('submit')); }
  });

  /* ── append bubble ── */
  // pending = bubble ที่โชว์ทันทีตอนกดส่ง ยังไม่รู้ id จริง จะได้ id จาก response หรือจาก poll
  // (เดิมใช้ ++lastId เป็น id ปลอม ทำให้ lastId เลื่อนข้ามข้อความของ admin ที่มี id ตรงกันได้)
  function appendBubble(m, pending){
    const isMe = (m.sender_role === 'user');
    const cls  = isMe ? 'me' : 'other';
    const avCls = isMe ? 'me' : 'admin';
    const avTxt = isMe ? myName : 'WS';
    // created_at เป็น Date ได้ (bubble pending) ถ้าแปลงผ่าน toISOString จะได้เวลา UTC แล้วโชว์ผิดไป 7 ชม.
    const d = (m.created_at instanceof Date) ? m.created_at : new Date(String(m.created_at).replace(' ','T'));
    const hhmm = d.toLocaleTimeString('th-TH',{hour:'2-digit',minute:'2-digit'});
    const ddmmyy = d.toLocaleDateString('th-TH',{day:'2-digit',month:'2-digit',year:'numeric'});

    const empty = body.querySelector('.chat-empty');
    if (empty) empty.remove();

    const row = document.createElement('div');
    row.className = 'msg-row ' + cls + (pending ? ' pending' : '');
    if (!pending) row.dataset.id = m.id;
    row.innerHTML = `
      <div class="msg-av ${avCls}">${esc(avTxt)}</div>
      <div class="msg-meta">
        <div class="msg-bubble">${nl2br(esc(m.body))}</div>
        <div class="msg-time">${ddmmyy} ${hhmm}</div>
      </div>`;
    body.appendChild(row);
    scrollBottom();
    return row;
  }

  function esc(s){ return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#39;'); }
  function nl2br(s){ return s.replace(/\n/g,'<br>'); }

  /* ── send ── */
  form.addEventListener('submit', function(e){
    e.preventDefault();
    const body_val = input.value.trim();
    if (!body_val) return;

    const fd = new FormData(form);
    fd.set('body', body_val);

    input.value = '';
    input.style.height = 'auto';
    counter.textContent = '0';

    // แสดง bubble ทันทีไม่รอ poll
    const row = appendBubble({ sender_role: 'user', body: body_val, created_at: new Date() }, true);

    fetch('chat.php', {
      method: 'POST',
      headers: {'X-Requested-With': 'XMLHttpRequest'},
      body: fd
    })
      .then(r => r.json())
      .then(d => {
        if (!d.ok) throw new Error('send failed');
        if (row.classList.contains('pending')) {
          row.classList.remove('pending');
          row.dataset.id = d.id;
        }
      })
      .catch(() => { row.style.opacity = '.5'; row.title = 'ส่งไม่สำเร็จ'; });
  });

  /* ── polling ทุก 3 วิ ── */
  function poll(){
    fetch('chat_poll.php?conv_id=' + convId + '&last_id=' + lastId + '&role=user')
      .then(r => r.json())
      .then(data => {
        if (data.messages && data.messages.length){
          // เลื่อน lastId ทุกข้อความ (รวมของตัวเอง) ไม่งั้นถ้าเราส่งเกิน 50 ข้อความติดกัน
          // poll จะได้แต่ข้อความเดิมของเราซ้ำ ๆ และไม่เห็นข้อความใหม่ของ admin
          data.messages.forEach(m => {
            const id = parseInt(m.id, 10);
            lastId = Math.max(lastId, id);
            if (body.querySelector('[data-id="'+id+'"]')) return;
            if (m.sender_role === 'user'){
              const p = body.querySelector('.msg-row.pending');
              if (p){ p.classList.remove('pending'); p.dataset.id = id; return; }
            }
            appendBubble(m);
          });
        }
      })
      .catch(()=>{});
  }

  polling = setInterval(poll, 3000);
  document.addEventListener('visibilitychange', function(){
    if (document.hidden){ clearInterval(polling); }
    else { polling = setInterval(poll, 3000); }
  });
})();
</script>
</body>
</html>
