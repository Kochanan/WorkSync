<?php
/**
 * dashboard.php — Dashboard ผู้ขาย
 * สรุปยอดขาย, ออเดอร์, สินค้า, สต็อกใกล้หมด
 */
require_once 'init.php';
require_login();

$uid = (int)$_SESSION['user_id'];

/* ── ยอดขายรวม (จาก order_items ของสินค้าตัวเอง) ─────────── */
$st = $conn->prepare("
    SELECT
        COUNT(DISTINCT o.id)             AS total_orders,
        COALESCE(SUM(oi.qty * oi.price), 0) AS total_revenue,
        COUNT(DISTINCT oi.id)            AS total_items_sold
    FROM order_items oi
    JOIN orders o ON o.id = oi.order_id
    JOIN products p ON p.id = oi.product_id
    WHERE p.seller_id = ?
      AND o.status NOT IN ('cancelled','pending')
");
$st->bind_param('i', $uid);
$st->execute();
$summary = $st->get_result()->fetch_assoc();
$st->close();

/* ── ออเดอร์รอดำเนินการ ──────────────────────────────────── */
$st = $conn->prepare("
    SELECT COUNT(DISTINCT o.id) cnt
    FROM orders o
    JOIN order_items oi ON oi.order_id = o.id
    JOIN products p ON p.id = oi.product_id
    WHERE p.seller_id = ? AND o.status = 'paid'
");
$st->bind_param('i', $uid);
$st->execute();
$pendingCount = (int)$st->get_result()->fetch_assoc()['cnt'];
$st->close();

/* ── สินค้าทั้งหมดของตัวเอง ──────────────────────────────── */
$st = $conn->prepare("SELECT COUNT(*) cnt, SUM(stock) total_stock FROM products WHERE seller_id = ?");
$st->bind_param('i', $uid);
$st->execute();
$productStat = $st->get_result()->fetch_assoc();
$st->close();

/* ── ยอดขายรายวัน 14 วันย้อนหลัง ────────────────────────── */
$st = $conn->prepare("
    SELECT DATE(o.created_at) AS d, COALESCE(SUM(oi.qty * oi.price), 0) AS rev
    FROM order_items oi
    JOIN orders o ON o.id = oi.order_id
    JOIN products p ON p.id = oi.product_id
    WHERE p.seller_id = ?
      AND o.status NOT IN ('cancelled','pending')
      AND o.created_at >= DATE_SUB(NOW(), INTERVAL 14 DAY)
    GROUP BY DATE(o.created_at)
    ORDER BY d ASC
");
$st->bind_param('i', $uid);
$st->execute();
$chartRaw = $st->get_result()->fetch_all(MYSQLI_ASSOC);
$st->close();

// เติมวันที่ขาดหาย
$chartData = [];
for ($i = 13; $i >= 0; $i--) {
    $day = date('Y-m-d', strtotime("-$i days"));
    $chartData[$day] = 0;
}
foreach ($chartRaw as $r) {
    $chartData[$r['d']] = (float)$r['rev'];
}
$chartLabels = array_map(fn($d) => date('d/m', strtotime($d)), array_keys($chartData));
$chartValues = array_values($chartData);

/* ── สินค้าขายดี top 5 ───────────────────────────────────── */
$st = $conn->prepare("
    SELECT p.id, p.title, p.image, p.stock,
           SUM(oi.qty) AS sold_qty,
           SUM(oi.qty * oi.price) AS revenue
    FROM order_items oi
    JOIN orders o ON o.id = oi.order_id
    JOIN products p ON p.id = oi.product_id
    WHERE p.seller_id = ? AND o.status NOT IN ('cancelled','pending')
    GROUP BY p.id
    ORDER BY sold_qty DESC
    LIMIT 5
");
$st->bind_param('i', $uid);
$st->execute();
$topProducts = $st->get_result()->fetch_all(MYSQLI_ASSOC);
$st->close();

/* ── สินค้าสต็อกใกล้หมด (น้อยกว่า 5) ───────────────────── */
$st = $conn->prepare("
    SELECT id, title, stock, image
    FROM products WHERE seller_id = ? AND stock <= 5
    ORDER BY stock ASC LIMIT 5
");
$st->bind_param('i', $uid);
$st->execute();
$lowStock = $st->get_result()->fetch_all(MYSQLI_ASSOC);
$st->close();

/* ── ออเดอร์ล่าสุด 8 รายการ ─────────────────────────────── */
$st = $conn->prepare("
    SELECT o.id, o.status, o.created_at,
           SUM(oi.qty * oi.price) AS total,   -- ยอดเฉพาะสินค้าของร้านนี้ ไม่โชว์ยอดของร้านอื่นในออเดอร์เดียวกัน
           u.username buyer_name
    FROM orders o
    JOIN order_items oi ON oi.order_id = o.id
    JOIN products p ON p.id = oi.product_id
    JOIN users u ON u.id = o.user_id
    WHERE p.seller_id = ?
    GROUP BY o.id, o.status, o.created_at, u.username
    ORDER BY o.created_at DESC
    LIMIT 8
");
$st->bind_param('i', $uid);
$st->execute();
$recentOrders = $st->get_result()->fetch_all(MYSQLI_ASSOC);
$st->close();

/* ── สินค้าทั้งหมดของฉัน (สำหรับเข้าไปแก้ไข/ลบ) ─────────────── */
$st = $conn->prepare("SELECT id, title, price, stock, image FROM products WHERE seller_id = ? ORDER BY created_at DESC LIMIT 100");
$st->bind_param('i', $uid);
$st->execute();
$myProducts = $st->get_result()->fetch_all(MYSQLI_ASSOC);
$st->close();

$isAdmin = (($_SESSION['role'] ?? '') === 'admin');
?>
<!doctype html>
<html lang="th">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <link rel="stylesheet" href="assets/style.css?v=<?= @filemtime(__DIR__.'/assets/style.css') ?>">
  <title>WorkSync — Dashboard</title>
  <style>
    .db-wrap { max-width: 1100px; margin: 28px auto; padding: 0 20px 60px; }

    /* metric cards */
    .metrics { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 14px; margin-bottom: 24px; }
    .metric {
      background: var(--n0); border: 1px solid var(--n100);
      border-radius: var(--radius-lg); padding: 18px 20px;
      box-shadow: var(--shadow-xs); position: relative; overflow: hidden;
    }
    .metric-accent { position: absolute; top: 0; left: 0; width: 4px; height: 100%; border-radius: 4px 0 0 4px; }
    .metric-label { font-size: 12px; color: var(--n500); font-weight: 500; margin-bottom: 6px; }
    .metric-val   { font-size: 26px; font-weight: 700; color: var(--n900); line-height: 1; }
    .metric-sub   { font-size: 12px; color: var(--n400); margin-top: 4px; }

    /* grid layout */
    .db-grid { display: grid; grid-template-columns: 1fr 340px; gap: 20px; align-items: start; }
    @media(max-width:900px){ .db-grid { grid-template-columns: 1fr; } }

    .panel {
      background: var(--n0); border: 1px solid var(--n100);
      border-radius: var(--radius-lg); overflow: hidden;
      box-shadow: var(--shadow-xs); margin-bottom: 20px;
    }
    .panel-head {
      padding: 14px 20px; border-bottom: 1px solid var(--n100);
      font-weight: 700; font-size: 14px; color: var(--n900);
      display: flex; align-items: center; justify-content: space-between;
      background: var(--n50);
    }
    .panel-body { padding: 16px 20px; }

    /* chart */
    .chart-wrap { position: relative; width: 100%; height: 220px; }

    /* top products */
    .tp-row {
      display: flex; align-items: center; gap: 12px;
      padding: 10px 0; border-bottom: 1px solid var(--n50);
    }
    .tp-row:last-child { border-bottom: none; }
    .tp-img { width: 44px; height: 44px; border-radius: 8px; object-fit: cover; background: var(--n100); flex-shrink: 0; }
    .tp-info { flex: 1; min-width: 0; }
    .tp-name { font-size: 13px; font-weight: 600; color: var(--n800); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .tp-sold { font-size: 12px; color: var(--n500); margin-top: 2px; }
    .tp-rev  { font-size: 13px; font-weight: 700; color: var(--red); white-space: nowrap; }

    /* low stock */
    .ls-row {
      display: flex; align-items: center; gap: 10px;
      padding: 9px 0; border-bottom: 1px solid var(--n50);
    }
    .ls-row:last-child { border-bottom: none; }
    .ls-img  { width: 36px; height: 36px; border-radius: 6px; object-fit: cover; background: var(--n100); flex-shrink: 0; }
    .ls-name { flex: 1; font-size: 13px; color: var(--n700); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .ls-stock { font-size: 13px; font-weight: 700; padding: 2px 8px; border-radius: 6px; white-space: nowrap; }
    .ls-stock.danger { background: var(--red-bg); color: var(--red); }
    .ls-stock.warn   { background: var(--amber-bg); color: var(--amber); }

    /* recent orders */
    .order-row {
      display: flex; align-items: center; gap: 10px;
      padding: 10px 0; border-bottom: 1px solid var(--n50); font-size: 13px;
    }
    .order-row:last-child { border-bottom: none; }
    .order-id   { color: var(--primary); font-weight: 700; min-width: 52px; }
    .order-buyer { color: var(--n600); flex: 1; }
    .order-total { font-weight: 700; color: var(--n800); min-width: 80px; text-align: right; }
    .order-date  { color: var(--n400); font-size: 12px; min-width: 80px; text-align: right; }

    .empty-msg { text-align: center; color: var(--n400); padding: 24px; font-size: 13px; }

    .page-header { margin-bottom: 22px; display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 10px; }
    .page-title  { font-size: 22px; font-weight: 700; color: var(--n900); }
    .page-sub    { font-size: 13px; color: var(--n500); margin-top: 3px; }
  </style>
</head>
<body>
<?php include 'navbar.php'; ?>

<div class="db-wrap">
  <div class="page-header">
    <div>
      <div class="page-title">Dashboard</div>
      <div class="page-sub">ยินดีต้อนรับ, <?= htmlspecialchars($_SESSION['username'] ?? '', ENT_QUOTES, 'UTF-8') ?> · ข้อมูล ณ วันนี้</div>
    </div>
    <a href="add_product.php" class="btn" style="gap:6px;">
      <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
      เพิ่มสินค้าใหม่
    </a>
  </div>

  <?php if ((int)$productStat['cnt'] === 0): ?>
  <!-- Onboarding empty state -->
  <div class="panel" style="text-align:center; padding:56px 24px;">
    <div style="font-size:56px; margin-bottom:16px;">🛍️</div>
    <div style="font-size:19px; font-weight:800; color:var(--n900); margin-bottom:8px;">ยังไม่มีสินค้าในร้านของคุณ</div>
    <div style="font-size:13.5px; color:var(--n500); max-width:420px; margin:0 auto 24px; line-height:1.7;">
      เริ่มต้นขายสินค้าชิ้นแรกวันนี้ — ลงขายฟรี ไม่มีค่าธรรมเนียม แล้วรอคำสั่งซื้อจากผู้ซื้อได้เลย
    </div>
    <a href="add_product.php" class="hero-cta" style="box-shadow:none;">+ เพิ่มสินค้าชิ้นแรก</a>

    <div style="display:flex; justify-content:center; gap:32px; margin-top:36px; flex-wrap:wrap;">
      <div style="max-width:160px;">
        <div style="font-size:26px;">📦</div>
        <div style="font-size:13px; font-weight:700; color:var(--n800); margin-top:6px;">1. ลงขายสินค้า</div>
        <div style="font-size:12px; color:var(--n500); margin-top:2px;">อัปโหลดรูป ตั้งราคา และรายละเอียด</div>
      </div>
      <div style="max-width:160px;">
        <div style="font-size:26px;">💬</div>
        <div style="font-size:13px; font-weight:700; color:var(--n800); margin-top:6px;">2. รับคำสั่งซื้อ</div>
        <div style="font-size:12px; color:var(--n500); margin-top:2px;">ตอบแชทลูกค้าและยืนยันออเดอร์</div>
      </div>
      <div style="max-width:160px;">
        <div style="font-size:26px;">🚚</div>
        <div style="font-size:13px; font-weight:700; color:var(--n800); margin-top:6px;">3. จัดส่งและรับเงิน</div>
        <div style="font-size:12px; color:var(--n500); margin-top:2px;">แพ็คสินค้าส่งและรับยอดขายเต็มจำนวน</div>
      </div>
    </div>
  </div>
  <?php else: ?>
  <!-- Metrics -->
  <div class="metrics">
    <div class="metric">
      <div class="metric-accent" style="background:var(--primary);"></div>
      <div class="metric-label">ยอดขายรวม</div>
      <div class="metric-val">฿<?= number_format((float)$summary['total_revenue'], 0) ?></div>
      <div class="metric-sub">ออเดอร์ที่สำเร็จแล้ว</div>
    </div>
    <div class="metric">
      <div class="metric-accent" style="background:#1a7f4b;"></div>
      <div class="metric-label">ออเดอร์ทั้งหมด</div>
      <div class="metric-val"><?= (int)$summary['total_orders'] ?></div>
      <div class="metric-sub">ไม่รวม pending/cancelled</div>
    </div>
    <div class="metric">
      <div class="metric-accent" style="background:#b45309;"></div>
      <div class="metric-label">รอดำเนินการ</div>
      <div class="metric-val" style="color:<?= $pendingCount > 0 ? 'var(--red)' : 'var(--n900)' ?>">
        <?= $pendingCount ?>
      </div>
      <div class="metric-sub">ออเดอร์ที่ชำระแล้ว</div>
    </div>
    <div class="metric">
      <div class="metric-accent" style="background:#0369a1;"></div>
      <div class="metric-label">สินค้าทั้งหมด</div>
      <div class="metric-val"><?= (int)$productStat['cnt'] ?></div>
      <div class="metric-sub">สต็อกรวม <?= number_format((int)$productStat['total_stock']) ?> ชิ้น</div>
    </div>
  </div>

  <!-- Main grid -->
  <div class="db-grid">

    <!-- Left column -->
    <div>
      <!-- Chart -->
      <div class="panel">
        <div class="panel-head">
          ยอดขาย 14 วันย้อนหลัง
          <span style="font-size:12px;color:var(--n400);font-weight:400;">บาท</span>
        </div>
        <div class="panel-body">
          <div class="chart-wrap">
            <canvas id="revenueChart"
                    role="img"
                    aria-label="กราฟยอดขาย 14 วันย้อนหลัง">
              ยอดขาย 14 วันล่าสุด
            </canvas>
          </div>
        </div>
      </div>

      <!-- Recent orders -->
      <div class="panel">
        <div class="panel-head">
          ออเดอร์ล่าสุด
          <?php if ($isAdmin): /* admin_orders.php เปิดได้เฉพาะแอดมิน ผู้ขายทั่วไปกดแล้วจะเจอ 403 */ ?>
          <a href="admin_orders.php" style="font-size:12px;color:var(--primary);font-weight:500;">ดูทั้งหมด →</a>
          <?php endif; ?>
        </div>
        <div class="panel-body" style="padding:8px 20px;">
          <?php if (empty($recentOrders)): ?>
            <div class="empty-msg">ยังไม่มีออเดอร์</div>
          <?php else: ?>
            <?php foreach ($recentOrders as $o):
              $statusColor = match($o['status']) {
                'paid'      => '#1a7f4b',
                'packing'   => '#0f4c81',
                'shipping'  => '#0369a1',
                'delivered' => '#1a7f4b',
                'cancelled' => '#c8102e',
                default     => '#b45309',
              };
              $statusLabel = match($o['status']) {
                'paid'      => 'ชำระแล้ว',
                'packing'   => 'จัดเตรียม',
                'shipping'  => 'กำลังส่ง',
                'delivered' => 'สำเร็จ',
                'cancelled' => 'ยกเลิก',
                default     => 'รอชำระ',
              };
            ?>
            <div class="order-row">
              <span class="order-id">#<?= (int)$o['id'] ?></span>
              <span class="order-buyer"><?= htmlspecialchars($o['buyer_name'], ENT_QUOTES, 'UTF-8') ?></span>
              <span style="font-size:11px;font-weight:600;color:<?= $statusColor ?>;padding:2px 8px;background:<?= $statusColor ?>18;border-radius:20px;">
                <?= $statusLabel ?>
              </span>
              <span class="order-total">฿<?= number_format((float)$o['total'], 0) ?></span>
              <span class="order-date"><?= date('d/m H:i', strtotime($o['created_at'])) ?></span>
            </div>
            <?php endforeach; ?>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <!-- Right column -->
    <div>
      <!-- Top products -->
      <div class="panel">
        <div class="panel-head">สินค้าขายดี</div>
        <div class="panel-body" style="padding:8px 20px;">
          <?php if (empty($topProducts)): ?>
            <div class="empty-msg">ยังไม่มีข้อมูล</div>
          <?php else: ?>
            <?php foreach ($topProducts as $i => $tp): ?>
            <div class="tp-row">
              <span style="font-size:14px;font-weight:700;color:var(--n300);min-width:20px;"><?= $i+1 ?></span>
              <img class="tp-img" src="<?= img_src($tp['image']) ?>" alt="">
              <div class="tp-info">
                <div class="tp-name"><a href="edit_product.php?id=<?= (int)$tp['id'] ?>" style="color:inherit;"><?= htmlspecialchars($tp['title'], ENT_QUOTES, 'UTF-8') ?></a></div>
                <div class="tp-sold">ขายแล้ว <?= (int)$tp['sold_qty'] ?> ชิ้น · เหลือ <?= (int)$tp['stock'] ?></div>
              </div>
              <div class="tp-rev">฿<?= number_format((float)$tp['revenue'], 0) ?></div>
            </div>
            <?php endforeach; ?>
          <?php endif; ?>
        </div>
      </div>

      <!-- Low stock -->
      <?php if (!empty($lowStock)): ?>
      <div class="panel">
        <div class="panel-head" style="color:var(--red);">
          ⚠ สต็อกใกล้หมด
        </div>
        <div class="panel-body" style="padding:8px 20px;">
          <?php foreach ($lowStock as $ls): ?>
          <div class="ls-row">
            <img class="ls-img" src="<?= img_src($ls['image']) ?>" alt="">
            <a class="ls-name" href="edit_product.php?id=<?= (int)$ls['id'] ?>"><?= htmlspecialchars($ls['title'], ENT_QUOTES, 'UTF-8') ?></a>
            <span class="ls-stock <?= (int)$ls['stock'] <= 2 ? 'danger' : 'warn' ?>">
              เหลือ <?= (int)$ls['stock'] ?>
            </span>
          </div>
          <?php endforeach; ?>
        </div>
      </div>
      <?php endif; ?>

      <!-- My products: ทางเข้าหน้าแก้ไข/ลบสินค้า (เดิมไม่มีลิงก์ไป edit_product.php เลย) -->
      <div class="panel">
        <div class="panel-head">สินค้าของฉัน <span style="font-size:12px;color:var(--n400);font-weight:400;"><?= count($myProducts) ?> รายการ</span></div>
        <div class="panel-body" style="padding:8px 20px; max-height:360px; overflow-y:auto;">
          <?php foreach ($myProducts as $mp): ?>
          <div class="ls-row">
            <img class="ls-img" src="<?= htmlspecialchars(img_src($mp['image']), ENT_QUOTES, 'UTF-8') ?>" alt="">
            <a class="ls-name" href="product.php?id=<?= (int)$mp['id'] ?>"><?= htmlspecialchars($mp['title'], ENT_QUOTES, 'UTF-8') ?></a>
            <a href="edit_product.php?id=<?= (int)$mp['id'] ?>" style="font-size:12px;font-weight:600;color:var(--primary);white-space:nowrap;">แก้ไข</a>
          </div>
          <?php endforeach; ?>
        </div>
      </div>

      <!-- Quick links -->
      <div class="panel">
        <div class="panel-head">เมนูลัด</div>
        <div class="panel-body" style="display:flex;flex-direction:column;gap:8px;">
          <?php
          $links = [
            ['add_product.php',  'เพิ่มสินค้าใหม่',     'var(--primary)'],
            ['orders.php',       'จัดการออเดอร์',        '#1a7f4b'],
            ['seller_inbox.php', 'ข้อความลูกค้า',        '#0369a1'],
            ['user_profile.php', 'แก้ไขโปรไฟล์',        'var(--n600)'],
          ];
          foreach ($links as [$url, $label, $color]): ?>
          <a href="<?= $url ?>" style="
            display:flex; align-items:center; gap:10px;
            padding:10px 14px; border-radius:var(--radius-md);
            border:1px solid var(--n100); font-size:13px; font-weight:600;
            color:<?= $color ?>; text-decoration:none;
            transition:background .12s, box-shadow .12s;
          " onmouseover="this.style.background='var(--n50)'"
             onmouseout="this.style.background='transparent'">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><polyline points="9 18 15 12 9 6"/></svg>
            <?= $label ?>
          </a>
          <?php endforeach; ?>
        </div>
      </div>
    </div>

  </div>
  <?php endif; ?>
</div>

<?php if ((int)$productStat['cnt'] > 0): ?>
<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.1/chart.umd.js"></script>
<script>
const labels = <?= json_encode(array_values($chartLabels)) ?>;
const values = <?= json_encode($chartValues) ?>;
const ctx = document.getElementById('revenueChart');
new Chart(ctx, {
  type: 'bar',
  data: {
    labels,
    datasets: [{
      label: 'ยอดขาย (฿)',
      data: values,
      backgroundColor: 'rgba(15,76,129,.18)',
      borderColor: '#0f4c81',
      borderWidth: 1.5,
      borderRadius: 5,
      hoverBackgroundColor: 'rgba(15,76,129,.32)',
    }]
  },
  options: {
    responsive: true,
    maintainAspectRatio: false,
    plugins: {
      legend: { display: false },
      tooltip: {
        callbacks: {
          label: ctx => '฿' + ctx.parsed.y.toLocaleString()
        }
      }
    },
    scales: {
      x: { grid: { display: false }, ticks: { font: { size: 11 }, color: '#adb5bd', maxRotation: 45, autoSkip: false } },
      y: { grid: { color: '#f1f3f5' }, ticks: { font: { size: 11 }, color: '#adb5bd', callback: v => '฿' + v.toLocaleString() } }
    }
  }
});
</script>
<?php endif; ?>
</body>
</html>
