<?php
/**
 * lending.php — ระบบยืม-คืนสินค้าในชุมชน
 * 
 * โมเดลใหม่: ผู้ขายสามารถตั้งสินค้าเป็น "ให้ยืม" แทนการขาย
 * ระบบติดตามการยืม + แจ้งเตือนวันครบกำหนดคืน
 * Trust Score จากไอเดียที่ 1 ใช้ตัดสินว่าผู้ยืมน่าเชื่อถือแค่ไหน
 */

require_once 'init.php';
require_once 'trust_score.php';
require_login();

$uid = (int)$_SESSION['user_id'];
$action = $_GET['action'] ?? 'list';

// ── POST: ยืมสินค้า ──────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();

    if (isset($_POST['borrow_product_id'])) {
        $pid     = (int)$_POST['borrow_product_id'];
        $days    = max(1, min(30, (int)($_POST['borrow_days'] ?? 7)));

        // เช็ค Trust Score ผู้ยืม — ต้องมากกว่า 40
        $trust = get_trust_score($conn, $uid);
        if ($trust['score'] <= 40) { // บัญชีใหม่ได้ 40 พอดี ต้องมีประวัติก่อนถึงยืมได้
            $err = "Trust Score ของคุณยังต่ำเกินไป ({$trust['score']}/100) กรุณาสร้างประวัติการซื้อขายก่อน";
        } else {
            $conn->begin_transaction();
            // สินค้าต้องเปิดให้ยืมจริง และไม่ใช่ของตัวเอง (เดิมส่ง product_id อะไรก็ยืมได้)
            // ล็อกแถว product_lending_info กันสองคนกดยืมชิ้นเดียวกันพร้อมกัน
            $pst = $conn->prepare(
                "SELECT p.title, p.seller_id, pi.lending_days_limit
                 FROM product_lending_info pi JOIN products p ON p.id = pi.product_id
                 WHERE pi.product_id=? AND pi.is_lendable=1 LIMIT 1 FOR UPDATE"
            );
            $pst->bind_param('i', $pid);
            $pst->execute();
            $product = $pst->get_result()->fetch_assoc();
            $pst->close();

            // เช็คว่าสินค้านี้ว่างอยู่ไหม — นับทุกรายการที่ยังไม่คืน รวมที่เลยกำหนดแล้ว
            // (เดิมมีเงื่อนไข return_due >= CURDATE() ทำให้ของที่ค้างคืนถูกยืมซ้อนได้)
            $chk = $conn->prepare(
                "SELECT id FROM lending_records
                 WHERE product_id=? AND status IN ('borrowed','overdue') LIMIT 1"
            );
            $chk->bind_param('i', $pid);
            $chk->execute();
            $busy = $chk->get_result()->fetch_assoc();
            $chk->close();

            if (!$product) {
                $conn->rollback();
                $err = "สินค้านี้ไม่ได้เปิดให้ยืม";
            } elseif ((int)$product['seller_id'] === $uid) {
                $conn->rollback();
                $err = "ยืมสินค้าของตัวเองไม่ได้";
            } elseif ($busy) {
                $conn->rollback();
                $err = "สินค้านี้ถูกยืมไปแล้ว กรุณารอให้คืนก่อน";
            } else {
                // จำนวนวันต้องไม่เกินที่ผู้ขายกำหนด
                $days = min($days, max(1, (int)$product['lending_days_limit']));
                $ins = $conn->prepare(
                    "INSERT INTO lending_records
                     (product_id, borrower_id, borrow_days, borrow_date, return_due, status)
                     VALUES (?, ?, ?, CURDATE(), DATE_ADD(CURDATE(), INTERVAL ? DAY), 'borrowed')"
                );
                $ins->bind_param('iiii', $pid, $uid, $days, $days);
                $ins->execute();
                $ins->close();

                // แจ้งเตือนเจ้าของสินค้า
                $notif = $conn->prepare(
                    "INSERT INTO notifications (user_id, title, message, is_read, created_at)
                     VALUES (?, 'มีการยืมสินค้า', ?, 0, NOW())"
                );
                $msg = "สินค้า \"{$product['title']}\" ถูกยืมโดยผู้ใช้ #$uid เป็นเวลา $days วัน";
                $notif->bind_param('is', $product['seller_id'], $msg);
                $notif->execute();
                $notif->close();
                $conn->commit();

                $ok = "ยืมสินค้าสำเร็จ! กรุณาคืนภายใน $days วัน";
            }
        }
    }

    // คืนสินค้า
    if (isset($_POST['return_record_id'])) {
        $rid = (int)$_POST['return_record_id'];
        $upd = $conn->prepare(
            "UPDATE lending_records SET status='returned', actual_return=CURDATE()
             WHERE id=? AND borrower_id=? AND status IN ('borrowed','overdue')"
        );
        $upd->bind_param('ii', $rid, $uid);
        $upd->execute();
        $changed = $upd->affected_rows;
        $upd->close();
        // เดิมขึ้นว่าสำเร็จเสมอ แม้ส่ง id ของคนอื่นหรือรายการที่คืนไปแล้ว
        if ($changed === 1) { $ok = "คืนสินค้าสำเร็จ ขอบคุณที่รักษาเวลา! 🙏"; }
        else { $err = "ไม่พบรายการยืมนี้ หรือคืนไปแล้ว"; }
    }
}

// ── ดึงสินค้าที่เปิดให้ยืม ───────────────────────────────────
$st = $conn->prepare("
    SELECT p.id, p.title, p.image, p.description,
           u.username seller_name,
           lr.status borrow_status, lr.return_due,
           pi.lending_days_limit, pi.deposit_required
    FROM products p
    JOIN users u ON u.id = p.seller_id
    JOIN product_lending_info pi ON pi.product_id = p.id AND pi.is_lendable = 1
    LEFT JOIN lending_records lr ON lr.id = (
        SELECT lr2.id FROM lending_records lr2
        WHERE lr2.product_id = p.id AND lr2.status IN ('borrowed','overdue')
        ORDER BY lr2.id DESC LIMIT 1)
    WHERE p.seller_id != ?
    ORDER BY lr.status IS NULL DESC, p.created_at DESC
    LIMIT 30
");
$st->bind_param('i', $uid);
$st->execute();
$lendingProducts = $st->get_result()->fetch_all(MYSQLI_ASSOC);
$st->close();

// ── สินค้าที่ฉันกำลังยืมอยู่ ─────────────────────────────────
$st = $conn->prepare("
    SELECT lr.*, p.title, p.image, u.username seller_name,
           DATEDIFF(lr.return_due, CURDATE()) days_left
    FROM lending_records lr
    JOIN products p ON p.id = lr.product_id
    JOIN users u ON u.id = p.seller_id
    WHERE lr.borrower_id = ? AND lr.status IN ('borrowed','overdue')
    ORDER BY lr.return_due ASC
");
$st->bind_param('i', $uid);
$st->execute();
$myBorrows = $st->get_result()->fetch_all(MYSQLI_ASSOC);
$st->close();

// Trust score ของตัวเอง
$myTrust = get_trust_score($conn, $uid);
?>
<!doctype html>
<html lang="th">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <link rel="stylesheet" href="assets/style.css?v=<?= @filemtime(__DIR__.'/assets/style.css') ?>">
  <title>WorkSync — ยืม-คืนสินค้าชุมชน</title>
</head>
<body>
<?php include 'navbar.php'; ?>

<div class="container" style="max-width:1000px;">
  <div style="margin:24px 0 20px;">
    <h2 style="margin:0;">🤝 ระบบยืม-คืนสินค้าชุมชน</h2>
    <p style="font-size:13px;color:var(--tx3);margin:6px 0 0;">
      ยืมสินค้าจากเพื่อนในชุมชนโดยไม่ต้องซื้อ · ข้อมูลยืนยันด้วย Trust Score
    </p>
  </div>

  <?php if (!empty($err ?? '')): ?>
    <div style="background:#fdf0f2;color:#c8102e;padding:12px 16px;border-radius:10px;margin-bottom:16px;font-weight:600;">✕ <?= htmlspecialchars($err, ENT_QUOTES, 'UTF-8') ?></div>
  <?php endif; ?>
  <?php if (!empty($ok ?? '')): ?>
    <div style="background:#eaf6f0;color:#0f9b5e;padding:12px 16px;border-radius:10px;margin-bottom:16px;font-weight:600;">✓ <?= htmlspecialchars($ok, ENT_QUOTES, 'UTF-8') ?></div>
  <?php endif; ?>

  <!-- Trust Score ของฉัน -->
  <div style="background:<?= $myTrust['badge']['color'] ?>18;border:1.5px solid <?= $myTrust['badge']['color'] ?>;border-radius:14px;padding:16px 20px;margin-bottom:24px;display:flex;align-items:center;gap:16px;">
    <div style="font-size:36px;"><?= $myTrust['badge']['icon'] ?></div>
    <div>
      <div style="font-size:13px;font-weight:700;color:var(--tx2);">Trust Score ของคุณ</div>
      <div style="font-size:24px;font-weight:800;color:<?= $myTrust['badge']['color'] ?>;"><?= $myTrust['score'] ?>/100</div>
      <div style="font-size:12px;color:var(--tx3);"><?= htmlspecialchars($myTrust['badge']['label'], ENT_QUOTES, 'UTF-8') ?> · <?= $myTrust['score'] > 40 ? '✅ มีสิทธิ์ยืมสินค้า' : '❌ ยังไม่มีสิทธิ์ยืม (ต้องมากกว่า 40)' ?></div>
    </div>
    <div style="margin-left:auto;text-align:right;">
      <div style="height:8px;width:160px;background:#eee;border-radius:4px;overflow:hidden;">
        <div style="height:100%;width:<?= $myTrust['score'] ?>%;background:<?= $myTrust['badge']['color'] ?>;border-radius:4px;transition:width .6s;"></div>
      </div>
      <div style="font-size:11px;color:var(--tx4);margin-top:4px;">ยิ่งสูงยิ่งได้ยืมได้ง่ายขึ้น</div>
    </div>
  </div>

  <!-- สินค้าที่ฉันกำลังยืม -->
  <?php if (!empty($myBorrows)): ?>
  <div style="margin-bottom:28px;">
    <h3 style="font-size:15px;font-weight:700;margin-bottom:12px;">📦 สินค้าที่ฉันกำลังยืม</h3>
    <?php foreach ($myBorrows as $b):
      $urgent = (int)$b['days_left'] <= 2;
    ?>
    <div style="display:flex;align-items:center;gap:14px;padding:14px;background:#fff;border-radius:12px;border:1.5px solid <?= $urgent ? '#ff5c35' : 'var(--border)' ?>;margin-bottom:10px;">
      <img src="<?= img_src($b['image']) ?>" style="width:60px;height:60px;object-fit:cover;border-radius:8px;">
      <div style="flex:1;">
        <div style="font-weight:700;"><?= htmlspecialchars($b['title']) ?></div>
        <div style="font-size:12px;color:<?= $urgent ? '#ff5c35' : 'var(--tx3)' ?>;margin-top:3px;">
          <?= $urgent ? '⚠️ ' : '📅 ' ?>ต้องคืนใน <?= (int)$b['days_left'] ?> วัน (<?= htmlspecialchars($b['return_due'], ENT_QUOTES, 'UTF-8') ?>)
        </div>
      </div>
      <form method="POST" style="margin:0;">
        <?= csrf_tag() ?>
        <input type="hidden" name="return_record_id" value="<?= (int)$b['id'] ?>">
        <button class="btn" type="submit" style="padding:7px 16px;font-size:13px;">คืนสินค้า</button>
      </form>
    </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

  <!-- รายการสินค้าที่เปิดให้ยืม -->
  <h3 style="font-size:15px;font-weight:700;margin-bottom:14px;">🏪 สินค้าที่เปิดให้ยืม</h3>
  <div class="grid">
    <?php foreach ($lendingProducts as $p):
      $available = empty($p['borrow_status']);
    ?>
      <div class="card" style="text-decoration:none;display:block;position:relative;">
        <div class="card-img">
          <img src="<?= img_src($p['image']) ?>" alt="" loading="lazy">
          <div style="position:absolute;top:10px;left:10px;
               background:<?= $available ? '#0f9b5e' : '#ff5c35' ?>;
               color:#fff;font-size:10px;font-weight:700;
               padding:3px 9px;border-radius:999px;">
            <?= $available ? '✅ ว่าง' : '⏳ ถูกยืมแล้ว' ?>
          </div>
        </div>
        <div class="pad">
          <div class="line-clamp-2"><?= htmlspecialchars($p['title']) ?></div>
          <div style="font-size:12px;color:var(--tx3);margin-top:4px;">ยืมได้สูงสุด <?= (int)($p['lending_days_limit'] ?? 7) ?> วัน</div>
          <?php if ($available && $myTrust['score'] > 40): ?>
          <form method="POST" style="margin-top:10px;">
            <?= csrf_tag() ?>
            <input type="hidden" name="borrow_product_id" value="<?= (int)$p['id'] ?>">
            <div style="display:flex;gap:8px;align-items:center;">
              <input type="number" name="borrow_days" value="7" min="1" max="<?= (int)($p['lending_days_limit'] ?? 7) ?>"
                     style="width:60px;padding:5px;border:1px solid #ddd;border-radius:6px;font-size:12px;">
              <span style="font-size:12px;color:var(--tx3);">วัน</span>
              <button class="btn" type="submit" style="flex:1;padding:6px;font-size:12px;">ยืม</button>
            </div>
          </form>
          <?php elseif (!$available): ?>
            <div style="font-size:12px;color:#ff5c35;margin-top:8px;">คืนวันที่ <?= htmlspecialchars((string)$p['return_due'], ENT_QUOTES, 'UTF-8') ?></div>
          <?php else: ?>
            <div style="font-size:12px;color:var(--tx4);margin-top:8px;">Trust Score ไม่เพียงพอ</div>
          <?php endif; ?>
        </div>
      </div>
    <?php endforeach; ?>
    <?php if (empty($lendingProducts)): ?>
      <div class="no-products">
        <div class="no-products-icon">🤝</div>
        <div style="font-weight:600;color:var(--tx2);margin-bottom:6px;">ยังไม่มีสินค้าให้ยืม</div>
        <div>เป็นคนแรกที่เปิดให้ยืมสินค้าในชุมชน!</div>
      </div>
    <?php endif; ?>
  </div>
</div>
</body>
</html>
