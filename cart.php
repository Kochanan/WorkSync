<?php
require_once 'init.php';
require_login();

$uid = (int)$_SESSION['user_id'];
$sql = "SELECT c.id cart_id, c.qty, c.selected, p.id product_id, p.title, p.price, p.image, p.stock
        FROM cart c
        JOIN products p ON c.product_id = p.id
        WHERE c.user_id = ?
        ORDER BY c.created_at DESC";

$st = $conn->prepare($sql);
$st->bind_param('i', $uid);
$st->execute();
$items = $st->get_result()->fetch_all(MYSQLI_ASSOC);
$st->close();

// ข้อความเมื่อสั่งซื้อไม่สำเร็จ ส่งกลับมาจาก place_order.php
$errCodes = [
    'input'      => 'ข้อมูลคำสั่งซื้อไม่ครบ กรุณาเลือกสินค้าและที่อยู่จัดส่งอีกครั้ง',
    'address'    => 'ไม่พบที่อยู่จัดส่งนี้ในบัญชีของคุณ กรุณาเลือกที่อยู่ใหม่',
    'empty'      => 'ไม่พบสินค้าที่เลือกในตะกร้า',
    'outofstock' => 'สินค้าบางรายการหมดสต็อกหรือมีไม่พอ กรุณาปรับจำนวนแล้วลองใหม่',
    'own'        => 'ไม่สามารถสั่งซื้อสินค้าของร้านตัวเองได้',
    'failed'     => 'ระบบขัดข้องระหว่างสร้างคำสั่งซื้อ ยังไม่มีการตัดเงินหรือตัดสต็อก กรุณาลองใหม่',
];
// ต้องเป็นสตริงเท่านั้น ถ้าส่ง err[]=x มาจะกลายเป็น array แล้วใช้เป็นคีย์ไม่ได้ (Fatal error)
$errKey = is_string($_GET['err'] ?? null) ? $_GET['err'] : '';
$errMsg = $errCodes[$errKey] ?? '';
?>
<!doctype html>
<html lang="th">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<link rel="stylesheet" href="assets/style.css?v=<?= filemtime(__DIR__.'/assets/style.css') ?>">
<title>WorkSync — ตะกร้าสินค้าของฉัน</title>
</head>
<body>
<?php include 'navbar.php'; ?>

<div class="container">
  <div class="box" style="margin-top:20px; padding:25px;">
    <h2>🛒 ตะกร้าสินค้าของคุณ</h2>

    <?php if ($errMsg !== ''): ?>
      <div style="background:#fdecea; color:#b00020; border:1px solid #f5c6cb; padding:12px 15px; border-radius:8px; margin:15px 0; font-size:14px; font-weight:700;">
        ⚠️ <?= htmlspecialchars($errMsg, ENT_QUOTES, 'UTF-8') ?>
      </div>
    <?php endif; ?>

    <label style="font-weight:800; user-select:none; cursor:pointer;">
      <input type="checkbox" id="selectAll"> เลือกรายการทั้งหมด
    </label>
    <hr style="border:0; border-top:1px solid #eee; margin:15px 0;">

    <?php if (empty($items)): ?>
       <p style="text-align:center; color:#888; margin:30px 0;">ไม่มีสินค้าอยู่ในตะกร้าในขณะนี้ <a href="index.php">ไปช้อปปิ้งกันเลย</a></p>
    <?php endif; ?>

    <form method="POST" action="checkout.php" id="cartForm">
      <?= csrf_tag() ?>
      <?php foreach ($items as $it): ?>
        <div class="cart-item" data-price="<?= (float)$it['price'] ?>" data-cart-id="<?= (int)$it['cart_id'] ?>" data-stock="<?= (int)$it['stock'] ?>" style="display:flex; align-items:center; gap:15px; margin-bottom:15px; padding-bottom:15px; border-bottom:1px solid #f9f9f9;">
          <input type="checkbox" class="sel" name="selected[]" value="<?= (int)$it['cart_id'] ?>" <?= $it['selected'] ? 'checked' : '' ?> style="transform: scale(1.2); cursor:pointer;">
          <img src="<?= htmlspecialchars(img_src($it['image']), ENT_QUOTES, 'UTF-8') ?>" alt="" style="width:75px; height:75px; object-fit:cover; border-radius:8px;">
          <div style="flex:1;">
            <div style="font-weight:800; color:#333;"><?= htmlspecialchars($it['title'], ENT_QUOTES, 'UTF-8') ?></div>
            <div class="price" style="color:var(--red, #b00020); font-weight:bold; margin-top:4px;">฿<?= number_format($it['price'], 2) ?></div>
            <?php if ((int)$it['stock'] < 1): ?>
              <div style="font-size:12px; color:#b00020; font-weight:700; margin-top:3px;">สินค้าหมดชั่วคราว</div>
            <?php elseif ((int)$it['stock'] <= 5): ?>
              <div style="font-size:12px; color:#e65100; font-weight:700; margin-top:3px;">เหลือเพียง <?= (int)$it['stock'] ?> ชิ้น</div>
            <?php endif; ?>
          </div>
          <div class="qty" style="display:flex; align-items:center; gap:5px;">
            <button type="button" class="minus" style="padding:4px 10px; cursor:pointer;">-</button>
            <input type="number" class="qtyInput" name="qty[<?= (int)$it['cart_id'] ?>]" value="<?= (int)$it['qty'] ?>" min="1" max="<?= max(1, (int)$it['stock']) ?>" readonly style="width:50px; text-align:center; font-weight:bold;">
            <button type="button" class="plus" style="padding:4px 10px; cursor:pointer;">+</button>
          </div>
        </div>
      <?php endforeach; ?>

      <?php if (!empty($items)): ?>
      <div class="row" style="display:flex; align-items:center; margin-top:20px; padding-top:15px; border-top:1px solid #eee;">
        <div style="font-weight:900; font-size:18px;">ราคารวมที่เลือกทั้งหมด: <span id="total" style="color:var(--red,#b00020)">฿0.00</span></div>
        <div style="flex:1;"></div>
        <button class="btn" id="checkoutBtn" disabled style="padding:8px 20px; font-size:15px;">ดำเนินการชำระเงิน (Checkout)</button>
      </div>
      <?php endif; ?>
    </form>
  </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const selectAll = document.getElementById('selectAll');
    const sels = document.querySelectorAll('.sel');
    const totalEl = document.getElementById('total');
    const checkoutBtn = document.getElementById('checkoutBtn');

    function calc() {
        let sum = 0;
        let checkedCount = 0;
        sels.forEach(cb => {
            if (cb.checked) {
                const row = cb.closest('.cart-item');
                const price = parseFloat(row.dataset.price || 0);
                const qty = parseInt(row.querySelector('.qtyInput').value || 1);
                sum += price * qty;
                checkedCount++;
            }
        });
        if (totalEl) totalEl.textContent = '฿' + sum.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        if (checkoutBtn) checkoutBtn.disabled = (checkedCount === 0);
        if (selectAll) selectAll.checked = (sels.length > 0 && checkedCount === sels.length);
    }

    if (selectAll) {
        selectAll.addEventListener('change', () => {
            sels.forEach(cb => {
                if (cb.checked !== selectAll.checked) {
                    cb.checked = selectAll.checked;
                    // Trigger การส่งอัปเดตไปหลังบ้านด้วย Ajax ทันที
                    updateStatus('select', cb.value, cb.checked ? 1 : 0);
                }
            });
            calc();
        });
    }

    sels.forEach(cb => cb.addEventListener('change', () => {
        updateStatus('select', cb.value, cb.checked ? 1 : 0);
        calc();
    }));

    document.querySelectorAll('.plus').forEach(btn => btn.addEventListener('click', () => {
        const row = btn.closest('.cart-item');
        const inp = row.querySelector('.qtyInput');
        const stock = parseInt(row.dataset.stock || 0);
        const next = parseInt(inp.value || 1) + 1;
        // กดเพิ่มเกินของที่มีจริงไม่ได้ ฝั่งเซิร์ฟเวอร์ก็ตรวจซ้ำอีกชั้น
        if (next > stock) return;
        inp.value = next;
        updateStatus('qty', row.dataset.cartId, inp.value);
        calc();
    }));

    document.querySelectorAll('.minus').forEach(btn => btn.addEventListener('click', () => {
        const row = btn.closest('.cart-item');
        const inp = row.querySelector('.qtyInput');
        inp.value = Math.max(1, parseInt(inp.value || 1) - 1);
        updateStatus('qty', row.dataset.cartId, inp.value);
        calc();
    }));

    // update_qty.php ตรวจ CSRF แล้ว จึงต้องแนบ token ไปทุกครั้ง
    const CSRF = <?= json_encode(csrf_token(), JSON_UNESCAPED_SLASHES) ?>;

    function updateStatus(action, cartId, val) {
        let bodyStr = `action=${action}&cart_id=${cartId}&_csrf=${encodeURIComponent(CSRF)}`;
        if (action === 'qty') bodyStr += `&qty=${val}`;
        if (action === 'select') bodyStr += `&selected=${val}`;

        fetch('update_qty.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: bodyStr
        });
    }

    calc();
});
</script>
</body>
</html>