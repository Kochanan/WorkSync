<?php
require_once 'init.php';
require_once 'popular_products.php';

/* ── ค่าค้นหา / หมวดหมู่ ─────────────────────────────────────── */
$q     = trim(is_string($_GET['q'] ?? null) ? $_GET['q'] : ''); // ส่ง q[]= มาจะทำให้ trim() พัง
$catId = (int)($_GET['cat'] ?? 0);

/* ── หมวดหมู่ทั้งหมด ─────────────────────────────────────────── */
// ดึงจำนวนสินค้าและรูปตัวแทนของแต่ละหมวดมาด้วย
// รูปตัวแทนเลือกจากสินค้าล่าสุดที่มีรูปถ่ายจริง ไม่เอาภาพประจำหมวดมาเป็นตัวแทนตัวเอง
$cats = $conn->query(
    "SELECT c.id, c.icon, c.name,
            (SELECT COUNT(*) FROM products p WHERE p.category_id = c.id) AS product_count,
            (SELECT p.image FROM products p
              WHERE p.category_id = c.id AND p.image IS NOT NULL AND p.image NOT LIKE '%cat-%'
              ORDER BY p.created_at DESC LIMIT 1) AS cover
     FROM categories c
     ORDER BY c.sort ASC"
)->fetch_all(MYSQLI_ASSOC);

$activeCatName = '';
foreach ($cats as $c) {
    if ((int)$c['id'] === $catId) { $activeCatName = $c['name']; break; }
}

/* ── เรียงลำดับ / แบ่งหน้า ───────────────────────────────────── */
// ค่า order ถูกนำไปต่อในคำสั่ง SQL ตรง ๆ จึงต้องมาจากรายการนี้เท่านั้น ห้ามรับจากผู้ใช้
// popular ใช้สูตรน้ำหนักชุดเดียวกับ popular_products.php ยอดขายหนักสุด รองลงมาคือถูกใจและรีวิว
$popularOrder =
    "(COALESCE((SELECT SUM(oi.qty) FROM order_items oi
                JOIN orders o ON o.id = oi.order_id
                WHERE oi.product_id = p.id AND o.status <> 'cancelled'), 0) * " . POPULAR_W_SOLD . "
    + COALESCE((SELECT COUNT(*) FROM wishlists w WHERE w.product_id = p.id), 0) * " . POPULAR_W_WISH . "
    + COALESCE((SELECT COUNT(*) FROM reviews r WHERE r.product_id = p.id), 0) * " . POPULAR_W_REVIEW . ") DESC,
    p.created_at DESC";

$sortOptions = [
    'newest'     => ['label' => 'ใหม่ล่าสุด',    'order' => 'p.created_at DESC'],
    'popular'    => ['label' => 'ยอดนิยม',       'order' => $popularOrder],
    'price_asc'  => ['label' => 'ราคา: ต่ำ-สูง', 'order' => 'p.price ASC'],
    'price_desc' => ['label' => 'ราคา: สูง-ต่ำ', 'order' => 'p.price DESC'],
];
$sort = is_string($_GET['sort'] ?? null) ? $_GET['sort'] : 'newest'; // ส่งมาเป็น array จะทำให้ isset() พัง
if (!isset($sortOptions[$sort])) { $sort = 'newest'; }

$perPage = 24;
$page    = max(1, (int)($_GET['page'] ?? 1));

function build_url(array $overrides, string $q, int $catId, string $sort, int $page): string {
    $base = [
        'q'    => $q !== '' ? $q : null,
        'cat'  => $catId > 0 ? $catId : null,
        'sort' => $sort !== 'newest' ? $sort : null,
        'page' => $page > 1 ? $page : null,
    ];
    $merged = array_filter(array_merge($base, $overrides), fn($v) => $v !== null && $v !== '');
    return 'index.php' . ($merged ? '?' . http_build_query($merged) : '');
}

/* ── สินค้า ──────────────────────────────────────────────────── */
$where  = "WHERE 1=1";
$params = [];
$types  = '';

if ($q !== '') {
    $where .= " AND (p.title LIKE ? OR p.description LIKE ?)";
    $like = '%' . addcslashes($q, '%_\\') . '%'; // ให้ % และ _ ที่ผู้ใช้พิมพ์เป็นตัวอักษรธรรมดา
    $params[] = $like; $params[] = $like;
    $types .= 'ss';
}
if ($catId > 0) {
    $where .= " AND p.category_id = ?";
    $params[] = $catId;
    $types .= 'i';
}

$countSt = $conn->prepare("SELECT COUNT(*) FROM products p $where");
if ($types !== '') { $countSt->bind_param($types, ...$params); }
$countSt->execute();
$totalProducts = (int)$countSt->get_result()->fetch_row()[0];
$countSt->close();

$totalPages = max(1, (int)ceil($totalProducts / $perPage));
$page       = min($page, $totalPages);
$offset     = ($page - 1) * $perPage;

$sql = "SELECT p.id, p.title, p.price, p.image, p.stock, p.seller_id FROM products p $where
        ORDER BY {$sortOptions[$sort]['order']} LIMIT ? OFFSET ?";
$st = $conn->prepare($sql);
$bindParams = array_merge($params, [$perPage, $offset]);
$st->bind_param($types . 'ii', ...$bindParams);
$st->execute();
$products = $st->get_result()->fetch_all(MYSQLI_ASSOC);
$st->close();

/* ── คูปองที่ยังใช้ได้ของแต่ละผู้ขาย ─────────────────────────
   เว็บมีระบบคูปองอยู่แล้วแต่ไม่เคยแสดงบนการ์ดสินค้าเลย คนจึงไม่รู้ว่ามีส่วนลด
   ตรงนี้หยิบคูปองที่ลดได้มากที่สุดของผู้ขายแต่ละรายมาแสดงเป็นป้าย
   ไม่ได้แต่งราคาเดิมขีดฆ่าขึ้นมาเอง เพราะระบบไม่มีข้อมูลราคาก่อนลดจริง */
$sellerCoupon = [];
$cpRes = $conn->query(
    "SELECT seller_id, type, value
     FROM coupons
     WHERE is_active = 1
       AND (expires_at IS NULL OR expires_at > NOW())
       AND (max_uses = 0 OR used_count < max_uses)
     ORDER BY seller_id, (type = 'percent') DESC, value DESC"
);
while ($cp = $cpRes->fetch_assoc()) {
    // แถวแรกของผู้ขายแต่ละรายคือใบที่ดีที่สุดอยู่แล้วตามลำดับ ORDER BY
    if (!isset($sellerCoupon[(int)$cp['seller_id']])) {
        $sellerCoupon[(int)$cp['seller_id']] = $cp['type'] === 'percent'
            ? 'ลด ' . rtrim(rtrim(number_format((float)$cp['value'], 2), '0'), '.') . '%'
            : 'ลด ' . number_format((float)$cp['value']) . '฿';
    }
}

$showHero = ($q === '' && $catId === 0);
$stat = ['total_products' => 0, 'total_sellers' => 0];

// สินค้ายอดนิยม แสดงเฉพาะหน้าแรกล้วน ๆ ตอนค้นหาหรือกรองหมวดผู้ใช้ตั้งใจดูอย่างอื่นอยู่
$popular = ['items' => [], 'fallback' => false, 'days' => POPULAR_DAYS];
if ($showHero) {
    $stat = $conn->query(
        "SELECT COUNT(*) total_products, COUNT(DISTINCT seller_id) total_sellers FROM products"
    )->fetch_assoc();

    $popular = get_popular_products($conn, 8);
}
?>
<!doctype html>
<html lang="th">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <link rel="stylesheet" href="assets/style.css?v=<?= @filemtime(__DIR__.'/assets/style.css') ?>">
  <title>WorkSync — ซื้อขายออนไลน์ ปลอดภัย ไม่มีค่าธรรมเนียม</title>
</head>
<body>
<?php include 'navbar.php'; ?>

<?php if ($showHero): ?>
<!-- ══ HERO ══ -->
<div class="hero-banner">
  <div class="hero-inner">
    <div class="hero-text">
      <div class="hero-badge"><span class="hero-badge-dot"></span> เปิดร้านฟรี ไม่มีค่าธรรมเนียม</div>
      <h1>ของดีจากคนปลูก<br>ถึงมือคุณ<em>โดยตรง</em></h1>
      <p>ซื้อจากผู้ขายตัวจริงโดยไม่ผ่านคนกลาง ราคาที่เห็นคือราคาที่ผู้ขายได้รับเต็มจำนวน</p>
      <div class="hero-actions">
        <a href="#products" class="hero-cta">เลือกซื้อสินค้า</a>
        <a href="add_product.php" class="hero-cta-outline">เปิดร้านของคุณ</a>
      </div>
      <?php /* ตราประทับแทนตัวเลขสถิติ ตัวเลข "1+ ร้านค้า" จริงแต่ไม่ช่วยให้อยากซื้อ */ ?>
      <div class="hero-marks">
        <span class="hero-mark">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" aria-hidden="true"><path d="M12 3 4 6.5v5c0 4.6 3.2 8.5 8 9.5 4.8-1 8-4.9 8-9.5v-5L12 3Z"/><path d="m9 12 2 2 4-4"/></svg>
          ส่งตรงจากผู้ขาย
        </span>
        <span class="hero-mark">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" aria-hidden="true"><path d="M3 12a9 9 0 1 0 3-6.7"/><path d="M3 4v4h4"/></svg>
          การันตีคืนเงิน
        </span>
        <span class="hero-mark">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" aria-hidden="true"><path d="M12 2v20"/><path d="M17.5 6.5H9.8a3.3 3.3 0 0 0 0 6.6h4.4a3.3 3.3 0 0 1 0 6.6H6"/></svg>
          ไม่เก็บค่าธรรมเนียม
        </span>
      </div>
    </div>
    <div class="hero-showcase">
      <div class="showcase-grid">
        <?php foreach (array_slice($products, 0, 3) as $i => $p): ?>
          <div class="sc-card <?= $i === 0 ? 'big' : '' ?>">
            <div class="sc-img <?= $i === 0 ? 'wide' : '' ?>">
              <img src="<?= img_src($p['image']) ?>" alt="" style="width:100%;height:100%;object-fit:cover;">
            </div>
            <div class="sc-body">
              <div class="sc-name"><?= htmlspecialchars($p['title'], ENT_QUOTES, 'UTF-8') ?></div>
              <div class="sc-price">฿<?= number_format((float)$p['price'], 2) ?></div>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
  </div>
</div>

<!-- ══ TRUST BAR ══ -->
<div class="trust-bar">
  <div class="trust-inner">
    <div class="trust-item">
      <div class="trust-icon">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><rect x="4" y="10.5" width="16" height="10" rx="2.2"/><path d="M8 10.5V7.6a4 4 0 0 1 8 0v2.9"/></svg>
      </div>
      <div>
        <div class="trust-title">ปลอดภัย 100%</div>
        <div class="trust-sub">ข้อมูลของคุณได้รับการปกป้อง</div>
      </div>
    </div>
    <div class="trust-item">
      <div class="trust-icon">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M12 2v20"/><path d="M17.5 6.5H9.8a3.3 3.3 0 0 0 0 6.6h4.4a3.3 3.3 0 0 1 0 6.6H6"/></svg>
      </div>
      <div>
        <div class="trust-title">ไม่มีค่าธรรมเนียม</div>
        <div class="trust-sub">ซื้อขายฟรี ไม่มีค่าใช้จ่ายแอบแฝง</div>
      </div>
    </div>
    <div class="trust-item">
      <div class="trust-icon">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M3 11.5 7 8l4.2 3.4a2 2 0 0 0 2.5 0L18 8l3 3.5"/><path d="M7 8v9.5a1.5 1.5 0 0 0 1.5 1.5h7a1.5 1.5 0 0 0 1.5-1.5V8"/></svg>
      </div>
      <div>
        <div class="trust-title">ซื้อตรงจากผู้ขาย</div>
        <div class="trust-sub">ไม่มีคนกลาง คุยกับผู้ขายได้โดยตรง</div>
      </div>
    </div>
    <div class="trust-item">
      <div class="trust-icon">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M20.5 12a8.5 8.5 0 1 1-4.2-7.3"/><path d="M4 20.5 5.6 16"/><path d="M9 11h6M9 14.5h4"/></svg>
      </div>
      <div>
        <div class="trust-title">แชทได้ทันที</div>
        <div class="trust-sub">สอบถามสินค้าก่อนตัดสินใจซื้อ</div>
      </div>
    </div>
  </div>
</div>
<?php endif; ?>

<div class="container" id="products">

  <?php if ($showHero): ?>
  <!-- ══ หมวดหมู่แบบมีรูป ══
       เดิมเป็นแถบข้อความบางเลื่อนแนวนอน ซึ่งกวาดตาผ่านได้ง่าย
       ให้พื้นที่กับรูปช่วยคนที่ยังไม่รู้ว่าจะซื้ออะไร -->
  <section class="cat-section">
    <div class="section-head">
      <div class="section-title"><span></span> เลือกตามหมวดหมู่</div>
    </div>
    <div class="cat-grid">
      <?php foreach ($cats as $c): ?>
        <a class="cat-tile" href="<?= build_url(['cat' => (int)$c['id'], 'q' => null, 'page' => null], $q, $catId, $sort, $page) ?>">
          <span class="cat-tile-img">
            <?php if (!empty($c['cover'])): ?>
              <img src="<?= img_src($c['cover']) ?>" alt="" loading="lazy">
            <?php else: ?>
              <span class="cat-tile-emoji"><?= htmlspecialchars($c['icon'], ENT_QUOTES, 'UTF-8') ?></span>
            <?php endif; ?>
          </span>
          <span class="cat-tile-name"><?= htmlspecialchars($c['name'], ENT_QUOTES, 'UTF-8') ?></span>
          <span class="cat-tile-count"><?= number_format((int)$c['product_count']) ?> รายการ</span>
        </a>
      <?php endforeach; ?>
    </div>
  </section>
  <?php endif; ?>

  <!-- แถบหมวดหมู่แบบข้อความ ใช้ตอนที่กำลังกรองหรือค้นหาอยู่ เพื่อสลับหมวดได้เร็ว -->
  <?php if (!$showHero): ?>
  <div class="cat-strip">
    <a href="<?= build_url(['cat' => null, 'q' => null, 'page' => null], $q, $catId, $sort, $page) ?>"
       class="cat-btn <?= $catId === 0 ? 'active' : '' ?>">ทั้งหมด</a>
    <?php foreach ($cats as $c): ?>
      <a href="<?= build_url(['cat' => (int)$c['id'], 'q' => null, 'page' => null], $q, $catId, $sort, $page) ?>"
         class="cat-btn <?= $catId === (int)$c['id'] ? 'active' : '' ?>">
        <span><?= htmlspecialchars($c['icon'], ENT_QUOTES, 'UTF-8') ?></span>
        <?= htmlspecialchars($c['name'], ENT_QUOTES, 'UTF-8') ?>
      </a>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

  <?php if ($showHero && !empty($popular['items'])): ?>
  <!-- ══ สินค้ายอดนิยม ══ -->
  <section class="popular-section">
    <div class="popular-head">
      <h2><?= $popular['fallback'] ? 'สินค้ามาใหม่' : 'สินค้ายอดนิยม' ?></h2>
      <span class="popular-note">
        <?php if ($popular['fallback']): ?>
          ยังมีข้อมูลการซื้อขายไม่พอจัดอันดับความนิยม จึงแสดงสินค้าที่เพิ่งลงขายแทน
        <?php else: ?>
          จัดอันดับจากยอดขาย รายการที่ถูกใจ และรีวิว ในรอบ <?= (int)$popular['days'] ?> วัน
        <?php endif; ?>
      </span>
      <?php if (!$popular['fallback']): ?>
        <a class="see-all" href="<?= build_url(['sort' => 'popular', 'cat' => null, 'q' => null, 'page' => null], $q, $catId, $sort, $page) ?>">
          ดูทั้งหมด
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" aria-hidden="true"><path d="m9 6 6 6-6 6"/></svg>
        </a>
      <?php endif; ?>
    </div>

    <div class="popular-grid">
      <?php foreach ($popular['items'] as $i => $p): ?>
        <a class="card popular-card" href="product.php?id=<?= (int)$p['id'] ?>">
          <?php if (!$popular['fallback']): ?>
            <span class="popular-rank">#<?= $i + 1 ?></span>
          <?php endif; ?>
          <div class="card-img">
            <img src="<?= img_src($p['image']) ?>" alt="<?= htmlspecialchars($p['title'], ENT_QUOTES, 'UTF-8') ?>" loading="lazy">
            <div class="card-overlay"><div class="card-quick-btn">ดูสินค้า</div></div>
          </div>
          <div class="pad">
            <div class="line-clamp-2"><?= htmlspecialchars($p['title'], ENT_QUOTES, 'UTF-8') ?></div>
            <div class="price">฿<?= number_format((float)$p['price'], 2) ?></div>
            <?php if ($p['stock'] < 1): ?>
              <span class="popular-soldout">สินค้าหมดชั่วคราว</span>
            <?php elseif ($p['reason'] !== ''): ?>
              <span class="popular-reason"><?= htmlspecialchars($p['reason'], ENT_QUOTES, 'UTF-8') ?></span>
            <?php else: ?>
              <span class="stock-label">คงเหลือ <?= (int)$p['stock'] ?> ชิ้น</span>
            <?php endif; ?>
          </div>
        </a>
      <?php endforeach; ?>
    </div>
  </section>
  <?php endif; ?>

  <div class="section-head" style="flex-wrap:wrap; gap:12px;">
    <div class="section-title">
      <span></span>
      <?php if ($q !== ''): ?>
        ผลการค้นหา "<?= htmlspecialchars($q, ENT_QUOTES, 'UTF-8') ?>"
      <?php elseif ($catId > 0): ?>
        <?= htmlspecialchars($activeCatName ?: 'หมวดหมู่', ENT_QUOTES, 'UTF-8') ?>
      <?php else: ?>
        สินค้าแนะนำ
      <?php endif; ?>
      <div class="small" style="font-weight:400; margin-left:6px;">(<?= number_format($totalProducts) ?> รายการ)</div>
    </div>
    <select class="input" style="width:auto; height:36px; font-size:13px;"
            onchange="if(this.value) location.href=this.value;">
      <?php foreach ($sortOptions as $key => $opt): ?>
        <option value="<?= htmlspecialchars(build_url(['sort' => $key], $q, $catId, $sort, $page), ENT_QUOTES, 'UTF-8') ?>" <?= $sort === $key ? 'selected' : '' ?>>
          <?= htmlspecialchars($opt['label'], ENT_QUOTES, 'UTF-8') ?>
        </option>
      <?php endforeach; ?>
    </select>
  </div>

  <!-- Grid -->
  <div class="grid">
    <?php if (empty($products)): ?>
      <div class="no-products">
        <div class="no-products-icon">📦</div>
        <div style="font-weight:600; color:var(--tx2); margin-bottom:6px;">ไม่พบสินค้า</div>
      </div>
    <?php else: ?>
      <?php foreach ($products as $p): ?>
        <?php $coupon = $sellerCoupon[(int)$p['seller_id']] ?? null; ?>
        <a class="card" href="product.php?id=<?= (int)$p['id'] ?>">
          <div class="card-img">
            <img src="<?= img_src($p['image']) ?>" alt="<?= htmlspecialchars($p['title'], ENT_QUOTES, 'UTF-8') ?>" loading="lazy">
            <?php if ($coupon): ?>
              <span class="coupon-badge"><?= htmlspecialchars($coupon, ENT_QUOTES, 'UTF-8') ?></span>
            <?php endif; ?>
            <div class="card-overlay"><div class="card-quick-btn">ดูสินค้า</div></div>
          </div>
          <div class="pad">
            <div class="line-clamp-2"><?= htmlspecialchars($p['title'], ENT_QUOTES, 'UTF-8') ?></div>
            <div class="price">฿<?= number_format((float)$p['price'], 2) ?></div>
            <?php if ($coupon): ?>
              <span class="coupon-note">มีโค้ดส่วนลดจากร้านนี้</span>
            <?php else: ?>
              <span class="stock-label">คงเหลือ <?= (int)$p['stock'] ?> ชิ้น</span>
            <?php endif; ?>
          </div>
        </a>
      <?php endforeach; ?>
    <?php endif; ?>
  </div>

  <!-- Pagination -->
  <?php if ($totalPages > 1): ?>
  <div style="display:flex; justify-content:center; align-items:center; gap:6px; margin-top:32px; flex-wrap:wrap;">
    <?php
    $pillStyle = function (bool $active) {
        return 'min-width:36px;height:36px;display:flex;align-items:center;justify-content:center;padding:0 12px;'
             . 'border-radius:999px;font-size:13px;font-weight:600;text-decoration:none;'
             . 'background:' . ($active ? 'var(--brand)' : 'var(--surface2,#f7f8fb)') . ';'
             . 'color:' . ($active ? '#fff' : 'var(--tx2)') . ';'
             . 'border:1px solid ' . ($active ? 'var(--brand)' : 'var(--border,#e9ecef)') . ';';
    };
    $rangeStart = max(1, $page - 2);
    $rangeEnd   = min($totalPages, $page + 2);
    ?>
    <?php if ($page > 1): ?>
      <a href="<?= build_url(['page' => $page - 1], $q, $catId, $sort, $page) ?>" style="<?= $pillStyle(false) ?>">‹ ก่อนหน้า</a>
    <?php endif; ?>

    <?php if ($rangeStart > 1): ?>
      <a href="<?= build_url(['page' => 1], $q, $catId, $sort, $page) ?>" style="<?= $pillStyle(false) ?>">1</a>
      <?php if ($rangeStart > 2): ?><span style="color:var(--tx4);">…</span><?php endif; ?>
    <?php endif; ?>

    <?php for ($i = $rangeStart; $i <= $rangeEnd; $i++): ?>
      <a href="<?= build_url(['page' => $i], $q, $catId, $sort, $page) ?>" style="<?= $pillStyle($i === $page) ?>"><?= $i ?></a>
    <?php endfor; ?>

    <?php if ($rangeEnd < $totalPages): ?>
      <?php if ($rangeEnd < $totalPages - 1): ?><span style="color:var(--tx4);">…</span><?php endif; ?>
      <a href="<?= build_url(['page' => $totalPages], $q, $catId, $sort, $page) ?>" style="<?= $pillStyle(false) ?>"><?= $totalPages ?></a>
    <?php endif; ?>

    <?php if ($page < $totalPages): ?>
      <a href="<?= build_url(['page' => $page + 1], $q, $catId, $sort, $page) ?>" style="<?= $pillStyle(false) ?>">ถัดไป ›</a>
    <?php endif; ?>
  </div>
  <?php endif; ?>

</div>

<!-- ══ FOOTER ══ -->
<?php if ($q === '' && $catId === 0): ?>
<footer class="site-footer">
  <div class="site-footer-inner">
    <div class="site-footer-cols">
      <div>
        <div class="site-footer-brand">WorkSync</div>
        <p class="site-footer-about">
          แพลตฟอร์มซื้อขายออนไลน์ที่ข้อมูลปลอดภัย ไม่มีค่าธรรมเนียม ซื้อตรงจากผู้ขาย
        </p>
        <div class="site-footer-social">
          <a href="#" title="Facebook" aria-label="Facebook">
            <svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M22 12.06C22 6.5 17.52 2 12 2S2 6.5 2 12.06c0 5.02 3.66 9.18 8.44 9.94v-7.03H7.9v-2.91h2.54V9.85c0-2.52 1.49-3.91 3.77-3.91 1.09 0 2.24.2 2.24.2v2.46h-1.26c-1.24 0-1.63.78-1.63 1.57v1.89h2.78l-.44 2.91h-2.34V22c4.78-.76 8.44-4.92 8.44-9.94Z"/></svg>
          </a>
          <a href="#" title="LINE" aria-label="LINE">
            <svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M12 2C6.48 2 2 5.67 2 10.19c0 4.05 3.55 7.44 8.35 8.08.33.07.77.22.88.5.1.26.07.66.03.92l-.14.85c-.04.25-.2.98.86.53s5.7-3.36 7.77-5.75c1.43-1.57 2.25-3.17 2.25-5.13C22 5.67 17.52 2 12 2ZM8.02 12.85H6.03a.53.53 0 0 1-.53-.53V8.35a.53.53 0 0 1 1.05 0v3.45h1.47a.53.53 0 0 1 0 1.05Zm2.07-.53a.53.53 0 0 1-1.05 0V8.35a.53.53 0 0 1 1.05 0v3.97Zm4.78 0a.53.53 0 0 1-.95.32l-2.04-2.77v2.45a.53.53 0 0 1-1.05 0V8.35a.53.53 0 0 1 .95-.32l2.04 2.78V8.35a.53.53 0 0 1 1.05 0v3.97Zm3.2-2.51a.53.53 0 0 1 0 1.05h-1.47v.94h1.47a.53.53 0 0 1 0 1.05h-2a.53.53 0 0 1-.52-.53V8.35a.53.53 0 0 1 .53-.53h2a.53.53 0 0 1 0 1.05h-1.48v.94h1.47Z"/></svg>
          </a>
          <a href="#" title="Instagram" aria-label="Instagram">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" aria-hidden="true"><rect x="3" y="3" width="18" height="18" rx="5"/><circle cx="12" cy="12" r="3.8"/><circle cx="17.4" cy="6.6" r="1.1" fill="currentColor" stroke="none"/></svg>
          </a>
        </div>
      </div>
      <div>
        <h4>ผู้ซื้อ</h4>
        <?php foreach(['วิธีสั่งซื้อ','ติดตามพัสดุ','นโยบายคืนเงิน','ศูนย์ช่วยเหลือ'] as $l): ?>
          <a href="#" class="site-footer-link"><?= htmlspecialchars($l, ENT_QUOTES, 'UTF-8') ?></a>
        <?php endforeach; ?>
      </div>
      <div>
        <h4>ผู้ขาย</h4>
        <?php foreach(['เริ่มขายสินค้า','จัดการร้านค้า','ระบบ Coupon','Dashboard'] as $l): ?>
          <a href="#" class="site-footer-link"><?= htmlspecialchars($l, ENT_QUOTES, 'UTF-8') ?></a>
        <?php endforeach; ?>
      </div>
      <div>
        <h4>ติดต่อเรา</h4>
        <div class="site-footer-contact">
          <div>
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><rect x="2.5" y="4.5" width="19" height="15" rx="2.5"/><path d="m3 6.5 9 6 9-6"/></svg>
            <span>article2311@gmail.com</span>
          </div>
          <div>
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M12 21s7-5.5 7-11a7 7 0 1 0-14 0c0 5.5 7 11 7 11Z"/><circle cx="12" cy="10" r="2.6"/></svg>
            <span>โรงเรียนอัสสัมชัญระยอง</span>
          </div>
          <div>
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 7v5.2l3.4 2"/></svg>
            <span>จ–ศ 09:00–17:00</span>
          </div>
        </div>
      </div>
    </div>
    <div class="site-footer-bottom">
      <div>© 2568 WorkSync — โรงเรียนอัสสัมชัญระยอง · IS ม.5/3</div>
      <div class="site-footer-legal">
        <a href="#">นโยบายความเป็นส่วนตัว</a>
        <a href="#">เงื่อนไขการใช้งาน</a>
        <a href="image_credits.php">เครดิตรูปภาพ</a>
      </div>
    </div>
  </div>
</footer>
<?php endif; ?>
</body>
</html>
