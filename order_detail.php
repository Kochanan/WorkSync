<?php
require_once 'init.php';
require_login();

$uid = (int)$_SESSION['user_id'];
$orderId = (int)($_GET['id'] ?? 0);
if ($orderId <= 0) { 
    header('Location: dashboard.php'); 
    exit; 
}

$od = $conn->prepare("SELECT * FROM orders WHERE id=? AND user_id=? LIMIT 1");
$od->bind_param("ii", $orderId, $uid);
$od->execute();
$order = $od->get_result()->fetch_assoc();
$od->close();

if (!$order) { 
    echo "Order not found"; 
    exit; 
}

$it = $conn->prepare("SELECT * FROM order_items WHERE order_id=?");
$it->bind_param("i", $orderId);
$it->execute();
$items = $it->get_result()->fetch_all(MYSQLI_ASSOC);
$it->close();

$status = $order['status']; // pending, paid, packing, shipping, delivered, cancelled

function stepClass($current, $step) {
    // เพิ่มสถานะ packing ให้สอดคล้องกับระบบของหน้ารวม Order อื่นๆ
    $map = ['pending' => 0, 'paid' => 1, 'packing' => 2, 'shipping' => 3, 'delivered' => 4, 'cancelled' => 99];
    $c = $map[$current] ?? 0;
    $s = $map[$step] ?? 0;
    
    if ($current === 'cancelled') {
        return ($step === 'pending') ? 'active' : '';
    }
    if ($s < $c) return 'done';
    if ($s === $c) return 'active';
    return '';
}
?>
<!doctype html>
<html lang="th">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>WorkSync — รายละเอียดออเดอร์ #<?= (int)$orderId ?></title>
  <link rel="stylesheet" href="assets/style.css?v=<?= @filemtime(__DIR__.'/assets/style.css') ?>">
</head>
<body>

<?php include 'navbar.php'; ?>

<div class="container" style="max-width:980px; margin-top:20px;">

  <div class="checkout-card">
    <div class="checkout-title" style="display:flex; justify-content:space-between; align-items:center;">
      <span class="badge">Order #<?= (int)$orderId ?></span>
      <span class="price-red" style="font-size:24px; font-weight:bold;">฿<?= number_format((float)$order['total'], 2) ?></span>
    </div>

    <?php if ($status === 'cancelled'): ?>
      <div class="badge" style="background:#ffebee; color:#b00020; display:inline-block; margin-top:10px; padding:6px 12px;">❌ ยกเลิกคำสั่งซื้อแล้ว</div>
    <?php else: ?>
      <div class="timeline" style="margin-top:20px;">
        <div class="tstep <?= stepClass($status, 'pending') ?>">รอยืนยัน (Pending)</div>
        <div class="tstep <?= stepClass($status, 'paid') ?>">ชำระเงินแล้ว (Paid)</div>
        <div class="tstep <?= stepClass($status, 'packing') ?>">กำลังจัดเตรียม (Packing)</div>
        <div class="tstep <?= stepClass($status, 'shipping') ?>">อยู่ระหว่างส่ง (Shipping)</div>
        <div class="tstep <?= stepClass($status, 'delivered') ?>">สำเร็จ (Delivered)</div>
      </div>
    <?php endif; ?>

    <div style="margin-top:15px;" class="small">สถานะปัจจุบัน: <b style="text-transform:uppercase; color:#4caf50;"><?= htmlspecialchars($status) ?></b></div>
    
    <?php if ($status === 'pending'): ?>
        <div style="margin-top:15px;">
            <a href="payment_simulator.php?order_id=<?= (int)$orderId ?>" class="btn" style="text-decoration:none; background:#4caf50;">👉 ไปที่หน้าจำลองการชำระเงิน</a>
        </div>
    <?php endif; ?>
  </div>

  <div class="checkout-card" style="margin-top:20px;">
    <div class="checkout-title"><span class="badge">รายการสินค้าในออเดอร์</span></div>
    <?php foreach ($items as $x): ?>
      <div class="cart-item" style="padding:15px 0; border-bottom:1px solid #f2f2f2; display:flex; gap:15px; align-items:center;">
        <img src="<?= htmlspecialchars(img_src($x['image_snapshot'])) ?>" alt="" style="width:70px; height:70px; object-fit:cover; border-radius:8px;">
        <div style="flex:1; min-width:0">
          <div style="font-weight:900; color:#222; white-space:nowrap; overflow:hidden; text-overflow:ellipsis">
            <?= htmlspecialchars($x['title_snapshot']) ?>
          </div>
          <div class="small" style="color:#666; margin-top:4px;">จำนวน: <?= (int)$x['qty'] ?> ชิ้น</div>
        </div>
        <div style="text-align:right; min-width:110px">
          <div class="price-red" style="font-weight:bold;">฿<?= number_format((float)$x['price'], 2) ?></div>
        </div>
      </div>
    <?php endforeach; ?>
  </div>

</div>
</body>
</html>