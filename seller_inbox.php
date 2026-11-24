<?php
/**
 * seller_inbox.php
 * กล่องข้อความสำหรับทั้ง buyer และ seller
 * - buyer → เห็นรายการ conversation ที่ตัวเองเป็นผู้ซื้อ
 * - seller → เห็นทุก conversation ที่มีคนทักมาถามสินค้าของตัวเอง
 */
require_once 'init.php';
require_login();

$uid = (int)$_SESSION['user_id'];

/* ── ดึง conversations ────────────────────────────────────── */
$st = $conn->prepare(
    // ต้องดึง sc.seller_id กับ sc.buyer_id มาด้วย
    // โค้ดข้างล่างใช้สองคอลัมน์นี้ตัดสินว่าผู้ใช้ที่ล็อกอินอยู่เป็นผู้ขายหรือผู้ซื้อในแต่ละห้องแชท
    "SELECT sc.id, sc.seller_id, sc.buyer_id,
            sc.last_message_at, sc.unread_buyer, sc.unread_seller,
            p.id product_id, p.title product_title, p.image product_image,
            buyer.username buyer_name, buyer.display_name buyer_display, buyer.avatar buyer_avatar,
            seller.username seller_name, seller.display_name seller_display, seller.avatar seller_avatar,
            (SELECT body FROM seller_messages WHERE conversation_id=sc.id ORDER BY id DESC LIMIT 1) last_body
     FROM seller_conversations sc
     JOIN products p ON p.id = sc.product_id
     JOIN users buyer  ON buyer.id  = sc.buyer_id
     JOIN users seller ON seller.id = sc.seller_id
     WHERE sc.buyer_id = ? OR sc.seller_id = ?
     ORDER BY sc.last_message_at DESC
     LIMIT 100"
);
$st->bind_param('ii', $uid, $uid);
$st->execute();
$convList = $st->get_result()->fetch_all(MYSQLI_ASSOC);
$st->close();

$totalUnread = 0;
foreach ($convList as $c) {
    // วงเล็บเดิมผิดตำแหน่ง เขียนเป็น ((int)$c['seller_id'] ?? 0)
    // ทำให้ cast ทำงานก่อน แล้ว ?? ไม่มีโอกาสได้ทำงานเลย เพราะ (int) ไม่มีทางคืนค่า null
    $isSeller = ((int)($c['seller_id'] ?? 0)) === $uid;
    $totalUnread += $isSeller ? (int)$c['unread_seller'] : (int)$c['unread_buyer'];
}
?>
<!doctype html>
<html lang="th">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <link rel="stylesheet" href="assets/style.css?v=<?= @filemtime(__DIR__.'/assets/style.css') ?>">
  <title>WorkSync — กล่องข้อความ</title>
  <style>
    .inbox-wrap { max-width: 720px; margin: 28px auto; padding: 0 16px 60px; }

    .inbox-card {
      background: #fff;
      border: 1px solid var(--n200,#e9ecef);
      border-radius: 14px;
      overflow: hidden;
      box-shadow: 0 1px 4px rgba(0,0,0,.06);
    }
    .inbox-card-head {
      padding: 16px 20px;
      border-bottom: 1px solid var(--n200,#e9ecef);
      background: var(--n50,#f8f9fa);
      display: flex; align-items: center; gap: 10px;
    }
    .inbox-card-head h2 { font-size: 16px; font-weight: 700; margin: 0; color: var(--n900,#212529); }
    .unread-badge {
      background: var(--red,#c8102e); color: #fff;
      font-size: 11px; font-weight: 700;
      padding: 2px 7px; border-radius: 20px;
    }

    .conv-item {
      display: flex; align-items: center; gap: 14px;
      padding: 14px 20px;
      border-bottom: 1px solid var(--n100,#f1f3f5);
      text-decoration: none;
      transition: background .12s;
      cursor: pointer;
    }
    .conv-item:last-child { border-bottom: none; }
    .conv-item:hover { background: var(--n50,#f8f9fa); text-decoration: none; }

    /* product thumbnail */
    .conv-product-img {
      width: 48px; height: 48px; border-radius: 8px;
      object-fit: cover; flex-shrink: 0;
      border: 1px solid var(--n200,#e9ecef);
      background: var(--n100,#f1f3f5);
    }

    /* user avatar */
    .conv-av {
      width: 36px; height: 36px; border-radius: 50%; flex-shrink: 0;
      background: var(--primary,#0f4c81); color: #fff;
      font-size: 13px; font-weight: 700;
      display: flex; align-items: center; justify-content: center;
      overflow: hidden;
    }
    .conv-av img { width: 36px; height: 36px; object-fit: cover; border-radius: 50%; }
    .conv-av.unread { background: var(--primary,#0f4c81); }

    .conv-info { flex: 1; min-width: 0; }
    .conv-top { display: flex; align-items: baseline; gap: 6px; }
    .conv-name { font-size: 14px; font-weight: 600; color: var(--n800,#343a40); white-space: nowrap; }
    .conv-product { font-size: 12px; color: var(--n500,#adb5bd); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; flex: 1; }
    .conv-preview { font-size: 13px; color: var(--n500,#adb5bd); margin-top: 2px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .conv-preview.unread-text { color: var(--n700,#495057); font-weight: 600; }

    .conv-meta { text-align: right; flex-shrink: 0; }
    .conv-time { font-size: 11px; color: var(--n400,#ced4da); }
    .conv-dot {
      width: 8px; height: 8px; border-radius: 50%;
      background: var(--primary,#0f4c81);
      margin: 4px 0 0 auto;
    }

    .role-tag {
      font-size: 10px; font-weight: 600;
      padding: 2px 6px; border-radius: 4px;
      background: var(--n100,#f1f3f5);
      color: var(--n600,#6c757d);
      white-space: nowrap;
    }
    .role-tag.seller { background: var(--primary-light,#e8f0f9); color: var(--primary,#0f4c81); }

    .inbox-empty {
      padding: 48px 24px; text-align: center;
      color: var(--n400,#ced4da); font-size: 14px;
    }
    .inbox-empty-icon { font-size: 40px; opacity: .4; margin-bottom: 10px; }
  </style>
</head>
<body>
<?php include 'navbar.php'; ?>

<div class="inbox-wrap">
  <div style="margin-bottom:18px; display:flex; align-items:center; justify-content:space-between;">
    <div>
      <h2 style="font-size:20px; font-weight:700; margin:0;">กล่องข้อความ</h2>
      <p style="font-size:13px; color:var(--n500); margin:4px 0 0;">การสนทนาระหว่างลูกค้าและผู้ขาย</p>
    </div>
    <?php if ($totalUnread > 0): ?>
      <span class="unread-badge" style="font-size:13px; padding:4px 10px;"><?= $totalUnread ?> ข้อความใหม่</span>
    <?php endif; ?>
  </div>

  <div class="inbox-card">
    <div class="inbox-card-head">
      <h2>ทั้งหมด</h2>
      <?php if ($totalUnread > 0): ?>
        <span class="unread-badge"><?= $totalUnread ?></span>
      <?php endif; ?>
    </div>

    <?php if (empty($convList)): ?>
      <div class="inbox-empty">
        <div class="inbox-empty-icon">💬</div>
        <div style="font-weight:600; color:var(--n600); margin-bottom:6px;">ยังไม่มีการสนทนา</div>
        <div>ไปที่หน้าสินค้าแล้วกด "ถามผู้ขาย" เพื่อเริ่มแชท</div>
      </div>
    <?php else: ?>
      <?php foreach ($convList as $c):
        $isSeller  = ((int)($c['seller_id'] ?? 0)) === $uid;
        $hasUnread = $isSeller ? (int)$c['unread_seller'] > 0 : (int)$c['unread_buyer'] > 0;

        // ฝั่งตรงข้าม
        if ($isSeller) {
          $otherName   = $c['buyer_display'] ?: $c['buyer_name'];
          $otherAvatar = $c['buyer_avatar'] ?? null;
        } else {
          $otherName   = $c['seller_display'] ?: $c['seller_name'];
          $otherAvatar = $c['seller_avatar'] ?? null;
        }
        $otherInit = mb_strtoupper(mb_substr($otherName, 0, 2));
        $timeAgo   = date('d/m H:i', strtotime($c['last_message_at']));
        $preview   = mb_substr($c['last_body'] ?? '—', 0, 50);
      ?>
        <a class="conv-item" href="seller_chat.php?conv_id=<?= (int)$c['id'] ?>">
          <img class="conv-product-img" src="<?= img_src($c['product_image']) ?>" alt="">

          <div class="conv-av <?= $hasUnread ? 'unread' : '' ?>">
            <?php if ($otherAvatar): ?>
              <img src="<?= htmlspecialchars($otherAvatar, ENT_QUOTES, 'UTF-8') ?>" alt="">
            <?php else: ?>
              <?= htmlspecialchars($otherInit, ENT_QUOTES, 'UTF-8') ?>
            <?php endif; ?>
          </div>

          <div class="conv-info">
            <div class="conv-top">
              <span class="conv-name"><?= htmlspecialchars($otherName, ENT_QUOTES, 'UTF-8') ?></span>
              <?php // ป้ายนี้อยู่ข้างชื่ออีกฝ่าย จึงต้องบอกบทบาทของอีกฝ่าย (เดิมกลับด้าน: ผู้ขายเห็นลูกค้าถูกติดป้าย "ผู้ขาย") ?>
              <span class="role-tag <?= $isSeller ? '' : 'seller' ?>"><?= $isSeller ? 'ลูกค้า' : 'ผู้ขาย' ?></span>
            </div>
            <div class="conv-product">สินค้า: <?= htmlspecialchars($c['product_title'], ENT_QUOTES, 'UTF-8') ?></div>
            <div class="conv-preview <?= $hasUnread ? 'unread-text' : '' ?>"><?= htmlspecialchars($preview, ENT_QUOTES, 'UTF-8') ?></div>
          </div>

          <div class="conv-meta">
            <div class="conv-time"><?= $timeAgo ?></div>
            <?php if ($hasUnread): ?><div class="conv-dot"></div><?php endif; ?>
          </div>
        </a>
      <?php endforeach; ?>
    <?php endif; ?>
  </div>
</div>
</body>
</html>
