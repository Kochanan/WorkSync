<?php
/**
 * seller_chat.php
 * ลูกค้ากดจากหน้า product แล้วมาคุยกับเจ้าของสินค้าโดยตรง
 * ?product_id=X  → เปิด/หา conversation สำหรับสินค้านั้น
 * ?conv_id=X     → เปิด conversation เดิม (เข้าจาก inbox)
 */
require_once 'init.php';
require_login();

$uid = (int)$_SESSION['user_id'];

/* ── resolve conversation ─────────────────────────────────── */
$convId    = 0;
$productId = 0;
$conv      = null;

if (isset($_GET['product_id'])) {
    $productId = (int)$_GET['product_id'];

    // ดึงสินค้า + ข้อมูล seller
    $st = $conn->prepare(
        "SELECT p.id, p.title, p.image, p.seller_id,
                u.username seller_name, u.display_name seller_display, u.avatar seller_avatar
         FROM products p JOIN users u ON u.id = p.seller_id
         WHERE p.id = ? LIMIT 1"
    );
    $st->bind_param('i', $productId);
    $st->execute();
    $product = $st->get_result()->fetch_assoc();
    $st->close();

    if (!$product) { header('Location: index.php'); exit; }
    if ((int)$product['seller_id'] === $uid) {
        // เจ้าของสินค้าคุยกับตัวเองไม่ได้
        header('Location: seller_inbox.php');
        exit;
    }

    $sellerId = (int)$product['seller_id'];

    // หา conversation เดิม — ถ้ายังไม่มีจะสร้างตอนส่งข้อความแรก (POST) เท่านั้น
    // ของเดิมสร้างทันทีตอนเปิดหน้า (GET) ทำให้เว็บอื่นหลอกให้ผู้ใช้สร้างห้องแชทว่าง ๆ ได้
    $st = $conn->prepare(
        "SELECT id FROM seller_conversations WHERE product_id=? AND buyer_id=? LIMIT 1"
    );
    $st->bind_param('ii', $productId, $uid);
    $st->execute();
    $row = $st->get_result()->fetch_assoc();
    $st->close();

    if ($row) {
        $convId = (int)$row['id'];
    }

} elseif (isset($_GET['conv_id'])) {
    $convId = (int)$_GET['conv_id'];

    // ตรวจสิทธิ์ — ต้องเป็น buyer หรือ seller ของ conv นี้
    $st = $conn->prepare(
        "SELECT sc.*, p.title, p.image, p.id product_id,
                u.username seller_name, u.display_name seller_display, u.avatar seller_avatar
         FROM seller_conversations sc
         JOIN products p ON p.id = sc.product_id
         JOIN users u ON u.id = sc.seller_id
         WHERE sc.id = ? AND (sc.buyer_id = ? OR sc.seller_id = ?) LIMIT 1"
    );
    $st->bind_param('iii', $convId, $uid, $uid);
    $st->execute();
    $conv = $st->get_result()->fetch_assoc();
    $st->close();

    if (!$conv) { header('Location: seller_inbox.php'); exit; }

    $product   = [
        'id'             => $conv['product_id'],
        'title'          => $conv['title'],
        'image'          => $conv['image'],
        'seller_id'      => $conv['seller_id'],
        'seller_name'    => $conv['seller_name'],
        'seller_display' => $conv['seller_display'],
        'seller_avatar'  => $conv['seller_avatar'],
    ];
    $productId = (int)$conv['product_id'];
    $sellerId  = (int)$conv['seller_id'];
} else {
    header('Location: seller_inbox.php');
    exit;
}

$isSeller = ($uid === (int)($product['seller_id'] ?? $sellerId));

/* ── POST: ส่งข้อความ ────────────────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $body = trim((string)($_POST['body'] ?? ''));
    $newId = 0;
    if ($body !== '' && mb_strlen($body) <= 2000) {
        if ($convId === 0) {
            // ข้อความแรกของสินค้านี้ → สร้าง conversation ตอนนี้
            $ins = $conn->prepare(
                "INSERT INTO seller_conversations (product_id, buyer_id, seller_id, last_message_at)
                 VALUES (?,?,?,NOW())"
            );
            $ins->bind_param('iii', $productId, $uid, $sellerId);
            $ins->execute();
            $convId = (int)$ins->insert_id;
            $ins->close();
        }
        $ins = $conn->prepare(
            "INSERT INTO seller_messages (conversation_id, sender_id, body) VALUES (?,?,?)"
        );
        $ins->bind_param('iis', $convId, $uid, $body);
        $ins->execute();
        $newId = (int)$ins->insert_id;
        $ins->close();

        // อัปเดต unread
        if ($isSeller) {
            $upd = $conn->prepare("UPDATE seller_conversations SET last_message_at=NOW(), unread_buyer=unread_buyer+1 WHERE id=?");
        } else {
            $upd = $conn->prepare("UPDATE seller_conversations SET last_message_at=NOW(), unread_seller=unread_seller+1 WHERE id=?");
        }
        $upd->bind_param('i', $convId);
        $upd->execute();
        $upd->close();
    }

    if (!empty($_SERVER['HTTP_X_REQUESTED_WITH'])) {
        header('Content-Type: application/json');
        echo json_encode(['ok' => $newId > 0, 'id' => $newId, 'conv_id' => $convId]);
        exit;
    }
    header($convId > 0 ? "Location: seller_chat.php?conv_id=$convId" : "Location: seller_chat.php?product_id=$productId");
    exit;
}

/* ── mark อ่าน ───────────────────────────────────────────── */
if ($isSeller) {
    $upd = $conn->prepare("UPDATE seller_conversations SET unread_seller=0 WHERE id=?");
} else {
    $upd = $conn->prepare("UPDATE seller_conversations SET unread_buyer=0 WHERE id=?");
}
$upd->bind_param('i', $convId);
$upd->execute();
$upd->close();

$upd2 = $conn->prepare(
    "UPDATE seller_messages SET is_read=1 WHERE conversation_id=? AND sender_id!=? AND is_read=0"
);
$upd2->bind_param('ii', $convId, $uid);
$upd2->execute();
$upd2->close();

/* ── ดึงข้อความ ──────────────────────────────────────────── */
$st = $conn->prepare(
    // 200 ข้อความล่าสุด (เดิมได้ 200 ข้อความแรกสุด)
    "SELECT * FROM (
        SELECT id, sender_id, body, created_at FROM seller_messages WHERE conversation_id=? ORDER BY id DESC LIMIT 200
     ) t ORDER BY t.id ASC"
);
$st->bind_param('i', $convId);
$st->execute();
$messages = $st->get_result()->fetch_all(MYSQLI_ASSOC);
$st->close();

/* ── ข้อมูลอีกฝ่าย ────────────────────────────────────────── */
$otherName   = $isSeller
    ? '' // จะดึงจาก buyer
    : ($product['seller_display'] ?: $product['seller_name']);

if ($isSeller) {
    // ดึงชื่อ buyer
    $st = $conn->prepare("SELECT username, display_name, avatar FROM users WHERE id=(SELECT buyer_id FROM seller_conversations WHERE id=?) LIMIT 1");
    $st->bind_param('i', $convId);
    $st->execute();
    $buyer = $st->get_result()->fetch_assoc();
    $st->close();
    $otherName   = ($buyer['display_name'] ?? '') ?: ($buyer['username'] ?? 'ลูกค้า');
    $otherAvatar = $buyer['avatar'] ?? null;
    $otherInit   = mb_strtoupper(mb_substr($otherName, 0, 2));
} else {
    $otherAvatar = $product['seller_avatar'] ?? null;
    $otherInit   = mb_strtoupper(mb_substr($otherName, 0, 2));
}
?>
<!doctype html>
<html lang="th">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <link rel="stylesheet" href="assets/style.css?v=<?= @filemtime(__DIR__.'/assets/style.css') ?>">
  <title>WorkSync — แชทกับผู้ขาย</title>
  <style>
    .sc-wrap { max-width: 800px; margin: 28px auto; padding: 0 16px 60px; }

    .sc-card {
      background: #fff;
      border: 1px solid var(--n200,#e9ecef);
      border-radius: 14px;
      overflow: hidden;
      box-shadow: 0 1px 4px rgba(0,0,0,.06);
    }

    /* header */
    .sc-head {
      padding: 14px 20px;
      border-bottom: 1px solid var(--n200,#e9ecef);
      display: flex; align-items: center; gap: 14px;
      background: #fff;
    }
    .sc-head-av {
      width: 42px; height: 42px; border-radius: 50%;
      background: var(--primary,#0f4c81); color: #fff;
      display: flex; align-items: center; justify-content: center;
      font-weight: 700; font-size: 15px; flex-shrink: 0;
      overflow: hidden;
    }
    .sc-head-av img { width: 42px; height: 42px; object-fit: cover; border-radius: 50%; }
    .sc-head-info { flex: 1; }
    .sc-head-name { font-weight: 700; font-size: 15px; color: var(--n900,#212529); }
    .sc-head-sub  { font-size: 12px; color: var(--n500,#adb5bd); margin-top: 2px; }

    /* product strip */
    .product-strip {
      display: flex; align-items: center; gap: 12px;
      padding: 10px 20px;
      background: var(--n50,#f8f9fa);
      border-bottom: 1px solid var(--n200,#e9ecef);
      text-decoration: none;
    }
    .product-strip img {
      width: 44px; height: 44px; border-radius: 8px;
      object-fit: cover; border: 1px solid var(--n200,#e9ecef);
      flex-shrink: 0;
    }
    .product-strip-info { flex: 1; min-width: 0; }
    .product-strip-name {
      font-size: 13px; font-weight: 600; color: var(--n800,#343a40);
      white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
    }
    .product-strip-lbl { font-size: 11px; color: var(--n500,#adb5bd); margin-top: 1px; }

    /* messages */
    .sc-msgs {
      min-height: 400px; max-height: 480px;
      overflow-y: auto;
      padding: 18px 20px;
      display: flex; flex-direction: column; gap: 10px;
    }
    .msg-row { display: flex; gap: 8px; align-items: flex-end; }
    .msg-row.me { flex-direction: row-reverse; }
    .msg-av {
      width: 30px; height: 30px; border-radius: 50; flex-shrink: 0;
      background: var(--n200,#e9ecef); color: var(--n700,#495057);
      font-size: 11px; font-weight: 700;
      display: flex; align-items: center; justify-content: center;
      border-radius: 50%;
    }
    .msg-av.seller-av { background: var(--primary,#0f4c81); color: #fff; }
    .msg-av img { width: 30px; height: 30px; border-radius: 50%; object-fit: cover; }
    .msg-meta { display: flex; flex-direction: column; }
    .msg-row.me .msg-meta { align-items: flex-end; }
    .msg-bubble {
      max-width: 66%; padding: 9px 13px; border-radius: 16px;
      font-size: 14px; line-height: 1.55; word-break: break-word;
    }
    .msg-row.me    .msg-bubble { background: var(--primary,#0f4c81); color: #fff; border-bottom-right-radius: 4px; }
    .msg-row.other .msg-bubble { background: var(--n100,#f1f3f5); color: var(--n800,#343a40); border-bottom-left-radius: 4px; }
    .msg-time { font-size: 11px; color: var(--n400,#ced4da); margin-top: 3px; }

    .chat-empty {
      flex: 1; display: flex; flex-direction: column;
      align-items: center; justify-content: center;
      color: var(--n400,#ced4da); gap: 8px; padding: 40px;
      font-size: 14px;
    }

    /* footer */
    .sc-foot {
      border-top: 1px solid var(--n200,#e9ecef);
      padding: 13px 16px; background: #fff;
    }
    .sc-form { display: flex; gap: 10px; align-items: flex-end; }
    .sc-form textarea {
      flex: 1; resize: none;
      border: 1px solid var(--n300,#dee2e6); border-radius: 10px;
      padding: 9px 13px; font-family: inherit; font-size: 14px;
      line-height: 1.5; max-height: 110px; outline: none;
      transition: border-color .15s, box-shadow .15s;
      color: var(--n900,#212529);
    }
    .sc-form textarea:focus {
      border-color: var(--primary,#0f4c81);
      box-shadow: 0 0 0 3px rgba(15,76,129,.1);
    }
    .send-btn {
      width: 42px; height: 42px; border-radius: 50%;
      border: none; background: var(--primary,#0f4c81); color: #fff;
      cursor: pointer; display: flex; align-items: center; justify-content: center;
      flex-shrink: 0; transition: background .15s, transform .1s;
    }
    .send-btn:hover  { background: var(--primary-dark,#0a3560); }
    .send-btn:active { transform: scale(.94); }
    .send-btn svg { width: 17px; height: 17px; fill: none; stroke: #fff; stroke-width: 2; stroke-linecap: round; stroke-linejoin: round; }
  </style>
</head>
<body>
<?php include 'navbar.php'; ?>

<div class="sc-wrap">
  <div style="margin-bottom:16px; display:flex; align-items:center; gap:10px;">
    <a href="seller_inbox.php" style="font-size:13px; color:var(--n500); text-decoration:none;">← กล่องข้อความ</a>
    <span style="color:var(--n300);">/</span>
    <span style="font-size:13px; color:var(--n700); font-weight:600;">
      <?= $isSeller ? 'ตอบกลับลูกค้า' : 'แชทกับผู้ขาย' ?>
    </span>
  </div>

  <div class="sc-card">
    <!-- Header -->
    <div class="sc-head">
      <div class="sc-head-av">
        <?php if ($otherAvatar): ?>
          <img src="<?= htmlspecialchars($otherAvatar, ENT_QUOTES, 'UTF-8') ?>" alt="">
        <?php else: ?>
          <?= htmlspecialchars($otherInit, ENT_QUOTES, 'UTF-8') ?>
        <?php endif; ?>
      </div>
      <div class="sc-head-info">
        <div class="sc-head-name"><?= htmlspecialchars($otherName, ENT_QUOTES, 'UTF-8') ?></div>
        <div class="sc-head-sub"><?= $isSeller ? 'ลูกค้า' : 'ผู้ขาย' ?></div>
      </div>
    </div>

    <!-- Product strip -->
    <a class="product-strip" href="product.php?id=<?= $productId ?>">
      <img src="<?= img_src($product['image']) ?>" alt="">
      <div class="product-strip-info">
        <div class="product-strip-name"><?= htmlspecialchars($product['title'], ENT_QUOTES, 'UTF-8') ?></div>
        <div class="product-strip-lbl">สนใจสินค้านี้ • กดเพื่อดูสินค้า</div>
      </div>
      <span style="font-size:13px; color:var(--n400); flex-shrink:0;">›</span>
    </a>

    <!-- Messages -->
    <div class="sc-msgs" id="scMsgs">
      <?php if (empty($messages)): ?>
        <div class="chat-empty">
          <div style="font-size:36px; opacity:.3;">💬</div>
          <div>เริ่มการสนทนากับ<?= $isSeller ? 'ลูกค้า' : 'ผู้ขาย' ?>ได้เลย</div>
        </div>
      <?php else: ?>
        <?php foreach ($messages as $m):
          $isMe   = ((int)$m['sender_id'] === $uid);
          $cls    = $isMe ? 'me' : 'other';
          $avCls  = (!$isMe && !$isSeller) ? 'seller-av' : '';
          $avTxt  = $isMe
            ? mb_strtoupper(mb_substr($_SESSION['username'] ?? '',0,2))
            : mb_strtoupper(mb_substr($otherName,0,2));
          $time   = date('d/m H:i', strtotime($m['created_at']));
        ?>
          <div class="msg-row <?= $cls ?>" data-id="<?= (int)$m['id'] ?>">
            <div class="msg-av <?= $avCls ?>"><?= htmlspecialchars($avTxt, ENT_QUOTES, 'UTF-8') ?></div>
            <div class="msg-meta">
              <div class="msg-bubble"><?= nl2br(htmlspecialchars($m['body'], ENT_QUOTES, 'UTF-8')) ?></div>
              <div class="msg-time"><?= $time ?></div>
            </div>
          </div>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>

    <!-- Footer -->
    <div class="sc-foot">
      <form class="sc-form" id="scForm">
        <?= csrf_tag() ?>
        <textarea id="scInput" name="body" rows="1"
                  placeholder="พิมพ์ข้อความ..." maxlength="2000"></textarea>
        <button type="submit" class="send-btn">
          <svg viewBox="0 0 24 24"><line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/></svg>
        </button>
      </form>
    </div>
  </div>
</div>

<script>
(function(){
  const msgs  = document.getElementById('scMsgs');
  const form  = document.getElementById('scForm');
  const input = document.getElementById('scInput');
  let convId = <?= (int)$convId ?>;            // 0 = ยังไม่มีห้อง จะได้ id หลังส่งข้อความแรก
  const productId = <?= (int)$productId ?>;
  const myUid  = <?= $uid ?>;
  let lastId   = <?= empty($messages) ? 0 : (int)end($messages)['id'] ?>;
  const myInit = <?= json_encode(mb_strtoupper(mb_substr($_SESSION['username'] ?? '',0,2)), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
  const otherInit = <?= json_encode(mb_strtoupper(mb_substr($otherName,0,2)), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
  const isSeller  = <?= $isSeller ? 'true' : 'false' ?>;

  function scrollBottom(){ msgs.scrollTop = msgs.scrollHeight; }
  scrollBottom();

  input.addEventListener('input', function(){
    this.style.height = 'auto';
    this.style.height = Math.min(this.scrollHeight, 110) + 'px';
  });
  input.addEventListener('keydown', function(e){
    if (e.key === 'Enter' && !e.shiftKey){ e.preventDefault(); form.dispatchEvent(new Event('submit')); }
  });

  function esc(s){ return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#39;'); }
  function nl2br(s){ return s.replace(/\n/g,'<br>'); }

  // pending = bubble ที่โชว์ทันทีตอนกดส่ง ยังไม่มี id จริง
  // (เดิมใช้ ++lastId เป็น id ปลอม: ข้อความตัวเองขึ้นซ้ำสองอัน และข้ามข้อความอีกฝ่ายที่ id ชนกันได้)
  function appendBubble(m, pending){
    const isMe  = (parseInt(m.sender_id, 10) === myUid);
    const cls   = isMe ? 'me' : 'other';
    const avCls = (!isMe && !isSeller) ? 'seller-av' : '';
    const avTxt = isMe ? myInit : otherInit;
    const d = (m.created_at instanceof Date) ? m.created_at : new Date(String(m.created_at).replace(' ','T'));
    const t = d.toLocaleDateString('th-TH',{day:'2-digit',month:'2-digit'})
            + ' ' + d.toLocaleTimeString('th-TH',{hour:'2-digit',minute:'2-digit'});

    const empty = msgs.querySelector('.chat-empty');
    if (empty) empty.remove();

    const row = document.createElement('div');
    row.className = `msg-row ${cls}` + (pending ? ' pending' : '');
    if (!pending) row.dataset.id = m.id;
    row.innerHTML = `
      <div class="msg-av ${avCls}">${esc(avTxt)}</div>
      <div class="msg-meta">
        <div class="msg-bubble">${nl2br(esc(m.body))}</div>
        <div class="msg-time">${t}</div>
      </div>`;
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
    const row = appendBubble({ sender_id: myUid, body: val, created_at: new Date() }, true);
    fetch(convId ? 'seller_chat.php?conv_id=' + convId : 'seller_chat.php?product_id=' + productId, {
      method:'POST', headers:{'X-Requested-With':'XMLHttpRequest'}, body: fd
    })
      .then(r => r.json())
      .then(d => {
        if (!d.ok) throw new Error('send failed');
        if (!convId && d.conv_id) convId = parseInt(d.conv_id, 10);
        if (row.classList.contains('pending')) { row.classList.remove('pending'); row.dataset.id = d.id; }
      })
      .catch(() => { row.style.opacity = '.5'; row.title = 'ส่งไม่สำเร็จ'; });
  });

  function poll(){
    if (!convId) return; // ยังไม่มีห้องแชท ไม่มีอะไรให้ดึง
    fetch(`seller_chat_poll.php?conv_id=${convId}&last_id=${lastId}`)
      .then(r=>r.json()).then(data=>{
        (data.messages||[]).forEach(m=>{
          const id = parseInt(m.id, 10);
          lastId = Math.max(lastId, id);
          if (msgs.querySelector(`[data-id="${id}"]`)) return;
          if (parseInt(m.sender_id, 10) === myUid){
            const p = msgs.querySelector('.msg-row.pending');
            if (p){ p.classList.remove('pending'); p.dataset.id = id; return; }
          }
          appendBubble(m);
        });
      }).catch(()=>{});
  }

  let timer = setInterval(poll, 3000);
  document.addEventListener('visibilitychange', ()=>{
    clearInterval(timer);
    if (!document.hidden) timer = setInterval(poll, 3000);
  });
})();
</script>
</body>
</html>
