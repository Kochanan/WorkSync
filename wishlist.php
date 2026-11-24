<?php
require_once 'init.php';
require_login();
$uid = (int)$_SESSION['user_id'];
$st = $conn->prepare(
    "SELECT p.id, p.title, p.price, p.image, p.stock, w.created_at wishlisted_at
     FROM wishlists w JOIN products p ON p.id = w.product_id
     WHERE w.user_id = ? ORDER BY w.created_at DESC"
);
$st->bind_param('i', $uid);
$st->execute();
$items = $st->get_result()->fetch_all(MYSQLI_ASSOC);
$st->close();
?>
<!doctype html><html lang="th"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<link rel="stylesheet" href="assets/style.css?v=<?= @filemtime(__DIR__.'/assets/style.css') ?>">
<title>รายการโปรด — WorkSync</title></head><body>
<?php include 'navbar.php'; ?>
<div class="container">
  <div style="margin-bottom:20px;"><h2 style="font-size:20px;font-weight:700;margin:0;">รายการโปรดของฉัน</h2>
  <p style="font-size:13px;color:var(--tx3);margin:4px 0 0;"><?= count($items) ?> รายการ</p></div>
  <?php if (empty($items)): ?>
    <div style="text-align:center;padding:60px 24px;color:var(--tx4);">
      <div style="font-size:48px;margin-bottom:12px;opacity:.4;">🤍</div>
      <div style="font-weight:600;color:var(--tx3);margin-bottom:6px;">ยังไม่มีสินค้าในรายการโปรด</div>
      <a href="index.php" class="btn" style="margin-top:18px;display:inline-flex;">เลือกดูสินค้า</a>
    </div>
  <?php else: ?>
    <div class="grid">
      <?php foreach ($items as $p): ?>
        <a class="card" href="product.php?id=<?= (int)$p['id'] ?>">
          <div class="card-img">
            <img src="<?= htmlspecialchars(img_src($p['image']), ENT_QUOTES, 'UTF-8') ?>" alt="" loading="lazy">
            <div class="card-overlay"><div class="card-quick-btn">ดูสินค้า</div></div>
          </div>
          <div class="pad">
            <div class="line-clamp-2"><?= htmlspecialchars($p['title'], ENT_QUOTES, 'UTF-8') ?></div>
            <div class="price">฿<?= number_format((float)$p['price'], 2) ?></div>
            <div class="stock-label">เหลือ <?= (int)$p['stock'] ?> ชิ้น</div>
          </div>
        </a>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</div>
</body></html>
