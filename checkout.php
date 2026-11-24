<?php
require_once 'init.php';
require_login();

$uid = (int)$_SESSION['user_id'];

// ดึงข้อมูลที่อยู่ของผู้ใช้งาน
$addrStmt = $conn->prepare("SELECT * FROM user_addresses WHERE user_id = ? ORDER BY is_default DESC, id DESC");
$addrStmt->bind_param("i", $uid);
$addrStmt->execute();
$addresses = $addrStmt->get_result()->fetch_all(MYSQLI_ASSOC);
$addrStmt->close();

// รับข้อมูลอาร์เรย์สินค้าและจำนวนจากตะกร้าสินค้า
$selected = $_POST['selected'] ?? [];
$qtyMap   = $_POST['qty'] ?? [];
// ต้องเป็น array เท่านั้น ถ้าส่ง selected=1 มาเป็นสตริง array_map จะโยน TypeError (หน้า 500)
if (!is_array($selected)) $selected = [];
if (!is_array($qtyMap))   $qtyMap = [];

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || empty($selected)) {
    header('Location: cart.php');
    exit;
}

check_csrf();

$selected = array_values(array_unique(array_filter(array_map('intval', $selected), fn($v) => $v > 0)));
if (!$selected) {
    header('Location: cart.php');
    exit;
}
$place = implode(',', array_fill(0, count($selected), '?'));

// ค้นหาสินค้าตาม ID ที่ถูกเลือกในตะกร้าสินค้า
$sql = "SELECT c.id cart_id, c.product_id, c.qty, p.title, p.price, p.image, p.stock, p.seller_id
        FROM cart c
        JOIN products p ON c.product_id = p.id
        WHERE c.user_id = ? AND c.id IN ($place)";

$st = $conn->prepare($sql);

// ใช้ Argument Unpacking วิธีการสมัยใหม่ทดแทน call_user_func_array เพื่อความเสถียรบน PHP ทุกเวอร์ชัน
$types = 'i' . str_repeat('i', count($selected));
$params = array_merge([$uid], $selected);
$st->bind_param($types, ...$params);
$st->execute();
$rows = $st->get_result()->fetch_all(MYSQLI_ASSOC);
$st->close();

if (!$rows) {
    header('Location: cart.php');
    exit;
}

// คำนวณราคารวมทั้งหมด
// จำนวนต้องไม่เกินสต็อกจริง เพื่อให้ยอดตรงกับที่ place_order.php จะคิดตอนกดยืนยัน
$subtotal = 0;
$items = [];
$stockWarn = false;
$outOfStock = false;

foreach ($rows as $r) {
    $cid   = (int)$r['cart_id'];
    $stock = (int)$r['stock'];

    // สินค้าของร้านตัวเองสั่งซื้อไม่ได้ (place_order.php ก็ตรวจซ้ำ)
    if ((int)$r['seller_id'] === $uid) {
        header('Location: cart.php?err=own');
        exit;
    }

    if ($stock < 1) {
        $outOfStock = true;
        continue; // สินค้าหมด ไม่นำมาคิดเงิน
    }

    $want = isset($qtyMap[$cid]) ? max(1, (int)$qtyMap[$cid]) : (int)$r['qty'];
    $q = min($want, $stock);
    if ($q < $want) {
        $stockWarn = true;
    }

    $line = (float)$r['price'] * $q;
    $subtotal += $line;
    $r['final_qty'] = $q;
    $r['line_total'] = $line;
    $items[] = $r;
}

if (!$items) {
    header('Location: cart.php?err=outofstock');
    exit;
}

// ตรวจสอบค่าจัดส่งจำลอง
$shipFee = ($subtotal >= 999) ? 0 : 40;
$grand = $subtotal + $shipFee;

// ที่อยู่เรียงมาโดยให้ที่อยู่หลักอยู่บนสุดแล้ว หน้าเว็บจึงติ๊กรายการแรกไว้ให้
// ผู้ใช้เปลี่ยนเป็นรายการอื่นได้ และ place_order.php จะตรวจซ้ำว่าเป็นที่อยู่ของคนสั่งจริง
?>
<!doctype html>
<html lang="th">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>WorkSync — สรุปรายการคำสั่งซื้อ</title>
  <link rel="stylesheet" href="assets/style.css?v=<?= @filemtime(__DIR__.'/assets/style.css') ?>">
</head>
<body>

<?php include 'navbar.php'; ?>

<div class="container" style="max-width:980px; margin-top:20px;">
<form method="POST" action="place_order.php">
  <?= csrf_tag() ?>

  <div class="checkout-card" style="background:#fff; padding:20px; border-radius:12px; border:1px solid #eee; margin-bottom:15px;">
    <div class="checkout-title" style="margin-bottom:15px;">
      <span class="badge" style="background:#222; color:#fff; padding:4px 8px; border-radius:4px; font-size:12px;">ที่อยู่จัดส่ง</span>
    </div>

    <?php if (empty($addresses)): ?>
      <div class="addr-empty">
        <div style="font-weight:bold; margin-bottom:5px;">ยังไม่มีข้อมูลที่อยู่จัดส่งของคุณ</div>
        <div style="font-size:14px; color:var(--tx2); margin-bottom:12px;">โปรดเพิ่มที่อยู่ก่อนทำการสั่งซื้อสินค้า</div>
        <a class="btn" href="profile.php" style="text-decoration:none; padding:6px 12px; font-size:14px;">+ เพิ่มที่อยู่จัดส่ง</a>
      </div>
    <?php else: ?>
      <div class="addr-choices">
        <?php foreach ($addresses as $i => $a): ?>
          <label class="addr-choice">
            <input type="radio" name="address_id" value="<?= (int)$a['id'] ?>" <?= $i === 0 ? 'checked' : '' ?>>
            <span class="addr-choice-body">
              <span class="addr-choice-name"><?= htmlspecialchars(($a['full_name'] ?? 'ผู้รับ').' · '.($a['phone'] ?? ''), ENT_QUOTES, 'UTF-8') ?></span>
              <span class="addr-choice-line"><?= htmlspecialchars(trim(($a['address_line'] ?? '').' '.($a['subdistrict'] ?? '').' '.($a['district'] ?? '').' '.($a['province'] ?? '').' '.($a['postcode'] ?? '')), ENT_QUOTES, 'UTF-8') ?></span>
              <?php if (!empty($a['is_default'])): ?>
                <span class="addr-choice-tag">ที่อยู่หลัก</span>
              <?php endif; ?>
            </span>
          </label>
        <?php endforeach; ?>
      </div>
      <div style="margin-top:12px;">
        <a href="profile.php" style="font-size:13px; color:var(--brand-light);">+ เพิ่มหรือแก้ไขที่อยู่</a>
      </div>
    <?php endif; ?>
  </div>

  <div class="checkout-card" style="background:#fff; padding:20px; border-radius:12px; border:1px solid #eee;">
    <div class="checkout-title" style="font-weight:bold; padding-bottom:10px; border-bottom:1px solid #eee; margin-bottom:15px;">รายการสินค้าชำระเงิน</div>

    <?php if ($outOfStock || $stockWarn): ?>
      <div style="background:#fff8e1; color:#8a6100; border:1px solid #ffe0a3; padding:12px 15px; border-radius:8px; margin-bottom:15px; font-size:14px; font-weight:700;">
        ⚠️
        <?php if ($outOfStock): ?>
          สินค้าบางรายการหมดสต็อกและถูกตัดออกจากรายการนี้แล้ว
        <?php endif; ?>
        <?php if ($stockWarn): ?>
          จำนวนบางรายการถูกปรับลงให้เท่ากับของที่มีอยู่จริง
        <?php endif; ?>
      </div>
    <?php endif; ?>

      <?php /* ไม่ต้องส่ง ship_fee มาแล้ว place_order.php คิดค่าจัดส่งเองจากยอดจริง */ ?>
      <?php foreach ($items as $it): ?>
        <input type="hidden" name="selected[]" value="<?= (int)$it['cart_id'] ?>">
        <input type="hidden" name="qty[<?= (int)$it['cart_id'] ?>]" value="<?= (int)$it['final_qty'] ?>">

        <div class="cart-item" style="display:flex; gap:15px; padding:12px 0; border-bottom:1px solid #f5f5f5; align-items:center;">
          <img src="<?= htmlspecialchars(img_src($it['image']), ENT_QUOTES, 'UTF-8') ?>" alt="" style="width:70px; height:70px; object-fit:cover; border-radius:8px;">
          <div style="flex:1;">
            <div style="font-weight:bold; color:#333;"><?= htmlspecialchars($it['title'], ENT_QUOTES, 'UTF-8') ?></div>
            <div style="font-size:14px; color:#666; margin-top:4px;">จำนวน: <?= (int)$it['final_qty'] ?> ชิ้น</div>
          </div>
          <div style="font-weight:bold; text-align:right;">
            ฿<?= number_format($it['line_total'], 2) ?>
          </div>
        </div>
      <?php endforeach; ?>

      <div style="margin-top:20px; border-top:2px dashed #eee; padding-top:15px; font-size:15px; line-height:1.8;">
        <div style="display:flex; justify-content:space-between;">
          <span style="color:#666;">ยอดรวมสินค้า:</span>
          <span style="margin-left:auto;">฿<?= number_format($subtotal, 2) ?></span>
        </div>
        <div style="display:flex; justify-content:space-between;">
          <span style="color:#666;">ค่าจัดส่งสินค้า:</span>
          <span style="margin-left:auto;"><?= $shipFee > 0 ? '฿'.number_format($shipFee, 2) : '<b style="color:#4caf50;">จัดส่งฟรี</b>' ?></span>
        </div>
        <div style="display:flex; justify-content:space-between; font-size:18px; font-weight:bold; margin-top:10px; padding-top:10px; border-top:1px solid #eee;">
          <span>ยอดชำระเงินสุทธิ:</span>
          <span style="margin-left:auto; color:var(--red, #b00020);">฿<?= number_format($grand, 2) ?></span>
        </div>
      </div>

      <div style="margin-top:20px; display:flex; justify-content:flex-end;">
         <button type="submit" class="btn" style="padding:10px 24px; font-size:16px; <?= empty($addresses) ? 'background:#ccc; cursor:not-allowed;' : '' ?>" <?= empty($addresses) ? 'disabled' : '' ?>>
           ยืนยันสั่งซื้อสินค้าออเดอร์นี้
         </button>
      </div>
  </div>
</form>
</div>
</body>
</html>