<?php
/**
 * shop.php — หน้าร้านค้าของผู้ขาย
 * ?seller_id=X
 */
require_once 'init.php';
require_once 'trust_score.php';

$sellerId = (int)($_GET['seller_id'] ?? 0);
if ($sellerId <= 0) { header('Location: index.php'); exit; }

/* ── ข้อมูลผู้ขาย ─────────────────────────────────────────── */
$st = $conn->prepare("SELECT id, username, display_name, avatar, bio, created_at FROM users WHERE id=? LIMIT 1");
$st->bind_param('i', $sellerId);
$st->execute();
$seller = $st->get_result()->fetch_assoc();
$st->close();
if (!$seller) { header('Location: index.php'); exit; }

/* ── สถิติร้าน ────────────────────────────────────────────── */
// เดิม JOIN order_items กับ reviews พร้อมกัน ทำให้ยอดขายคูณด้วยจำนวนรีวิว (และค่าเฉลี่ยดาวเพี้ยน)
// และนับออเดอร์ที่ยกเลิก/ยังไม่จ่ายด้วย จึงแยกเป็น subquery
$st = $conn->prepare("
    SELECT
      (SELECT COUNT(*) FROM products p WHERE p.seller_id = ?) product_count,
      (SELECT COALESCE(SUM(oi.qty),0) FROM order_items oi
         JOIN products p ON p.id = oi.product_id
         JOIN orders o ON o.id = oi.order_id
        WHERE p.seller_id = ? AND o.status IN ('paid','packing','shipping','delivered')) sold_total,
      (SELECT COALESCE(AVG(r.rating),0) FROM reviews r JOIN products p ON p.id = r.product_id WHERE p.seller_id = ?) avg_rating,
      (SELECT COUNT(*) FROM reviews r JOIN products p ON p.id = r.product_id WHERE p.seller_id = ?) review_count
");
$st->bind_param('iiii', $sellerId, $sellerId, $sellerId, $sellerId);
$st->execute();
$shopStat = $st->get_result()->fetch_assoc();
$st->close();

/* ── เรียงลำดับ / แบ่งหน้า ───────────────────────────────────── */
$sortOptions = [
    'newest'     => ['label' => 'ใหม่ล่าสุด',    'order' => 'p.created_at DESC'],
    'price_asc'  => ['label' => 'ราคา: ต่ำ-สูง', 'order' => 'p.price ASC'],
    'price_desc' => ['label' => 'ราคา: สูง-ต่ำ', 'order' => 'p.price DESC'],
];
$sort = is_string($_GET['sort'] ?? null) ? $_GET['sort'] : 'newest'; // ส่งมาเป็น array จะทำให้ isset() พัง
if (!isset($sortOptions[$sort])) { $sort = 'newest'; }

$perPage = 24;
$page    = max(1, (int)($_GET['page'] ?? 1));

function build_url(array $overrides, int $sellerId, string $q, int $catId, string $sort, int $page): string {
    $base = [
        'seller_id' => $sellerId,
        'q'         => $q !== '' ? $q : null,
        'cat'       => $catId > 0 ? $catId : null,
        'sort'      => $sort !== 'newest' ? $sort : null,
        'page'      => $page > 1 ? $page : null,
    ];
    $merged = array_filter(array_merge($base, $overrides), fn($v) => $v !== null && $v !== '');
    return 'shop.php?' . http_build_query($merged);
}

/* ── สินค้าของร้าน ────────────────────────────────────────── */
$q = trim(is_string($_GET['q'] ?? null) ? $_GET['q'] : '');
$catId = (int)($_GET['cat'] ?? 0);

$where  = "WHERE p.seller_id = ?";
$params = [$sellerId];
$types  = 'i';

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
$countSt->bind_param($types, ...$params);
$countSt->execute();
$totalProducts = (int)$countSt->get_result()->fetch_row()[0];
$countSt->close();

$totalPages = max(1, (int)ceil($totalProducts / $perPage));
$page       = min($page, $totalPages);
$offset     = ($page - 1) * $perPage;

$sql = "SELECT p.id, p.title, p.price, p.image, p.stock FROM products p $where
        ORDER BY {$sortOptions[$sort]['order']} LIMIT ? OFFSET ?";
$st = $conn->prepare($sql);
$bindParams = array_merge($params, [$perPage, $offset]);
$st->bind_param($types . 'ii', ...$bindParams);
$st->execute();
$products = $st->get_result()->fetch_all(MYSQLI_ASSOC);
$st->close();

$sellerName   = $seller['display_name'] ?: $seller['username'];
$sellerInitial = mb_strtoupper(mb_substr($sellerName, 0, 2));
$memberSince  = date('M Y', strtotime($seller['created_at']));
$sellerTrust  = get_trust_score($conn, $sellerId);
?>
<!doctype html>
<html lang="th">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <link rel="stylesheet" href="assets/style.css?v=<?= @filemtime(__DIR__.'/assets/style.css') ?>">
  <title><?= htmlspecialchars($sellerName, ENT_QUOTES, 'UTF-8') ?> — WorkSync Shop</title>
  <style>
    .shop-wrap { max-width: 1100px; margin: 0 auto; padding: 0 20px 60px; }

    /* shop hero */
    .shop-hero {
      background: var(--n0); border: 1px solid var(--n100);
      border-radius: var(--radius-lg); overflow: hidden;
      margin-bottom: 24px; box-shadow: var(--shadow-xs);
    }
    .shop-banner {
      height: 120px;
      background: linear-gradient(135deg, var(--primary) 0%, #1968b3 100%);
    }
    .shop-hero-body {
      padding: 0 28px 24px;
      display: flex; align-items: flex-end; gap: 20px;
      margin-top: -44px; flex-wrap: wrap;
    }
    .shop-av {
      width: 88px; height: 88px; border-radius: 50%;
      border: 4px solid #fff; flex-shrink: 0;
      background: var(--primary); color: #fff;
      font-size: 28px; font-weight: 700;
      display: flex; align-items: center; justify-content: center;
      box-shadow: 0 2px 10px rgba(0,0,0,.12);
      overflow: hidden;
    }
    .shop-av img { width: 88px; height: 88px; object-fit: cover; border-radius: 50%; }
    .shop-info { flex: 1; padding-top: 50px; }
    .shop-name { font-size: 20px; font-weight: 700; color: var(--n900); }
    .shop-meta { font-size: 13px; color: var(--n500); margin-top: 3px; }
    .shop-bio  { font-size: 14px; color: var(--n600); margin-top: 6px; }

    .shop-stats {
      display: flex; gap: 24px; margin-top: 16px; flex-wrap: wrap;
    }
    .shop-stat { text-align: center; }
    .shop-stat-val { font-size: 18px; font-weight: 700; color: var(--primary); }
    .shop-stat-lbl { font-size: 11px; color: var(--n500); }

    /* chat btn */
    .btn-chat {
      margin-left: auto;
      padding: 8px 16px;
      background: var(--n0);
      border: 1.5px solid var(--primary);
      color: var(--primary);
      border-radius: var(--radius-md);
      font-size: 13px; font-weight: 700;
      cursor: pointer; text-decoration: none;
      display: inline-flex; align-items: center; gap: 6px;
      transition: background .13s;
      align-self: flex-end;
    }
    .btn-chat:hover { background: var(--primary-light); text-decoration: none; }

    /* filter bar */
    .filter-bar {
      display: flex; align-items: center; gap: 10px;
      margin-bottom: 20px; flex-wrap: wrap;
    }
    .filter-bar input {
      height: 38px; padding: 0 14px;
      border: 1.5px solid var(--n200); border-radius: 999px;
      font-size: 13.5px; font-family: inherit; color: var(--n800);
      outline: none; min-width: 200px;
      transition: border-color .15s;
    }
    .filter-bar input:focus { border-color: var(--primary); }
    .product-count { font-size: 13px; color: var(--n500); margin-left: auto; }
  </style>
</head>
<body>
<?php include 'navbar.php'; ?>

<div style="background:var(--n50); padding-top:24px;">
<div class="shop-wrap">

  <!-- Shop hero -->
  <div class="shop-hero">
    <div class="shop-banner"></div>
    <div class="shop-hero-body">
      <div class="shop-av">
        <?php if ($seller['avatar']): ?>
          <img src="<?= htmlspecialchars($seller['avatar'], ENT_QUOTES, 'UTF-8') ?>" alt="">
        <?php else: ?>
          <?= htmlspecialchars($sellerInitial, ENT_QUOTES, 'UTF-8') ?>
        <?php endif; ?>
      </div>
      <div class="shop-info">
        <div class="shop-name"><?= htmlspecialchars($sellerName, ENT_QUOTES, 'UTF-8') ?></div>
        <div class="shop-meta">@<?= htmlspecialchars($seller['username'], ENT_QUOTES, 'UTF-8') ?> · เปิดร้านตั้งแต่ <?= $memberSince ?></div>
        <div style="display:inline-flex;align-items:center;gap:6px;background:<?= $sellerTrust['badge']['color'] ?>18;
                    border:1px solid <?= $sellerTrust['badge']['color'] ?>;border-radius:999px;padding:4px 12px;
                    margin-top:8px;font-size:12px;font-weight:700;color:<?= $sellerTrust['badge']['color'] ?>;">
          <?= $sellerTrust['badge']['icon'] ?> <?= htmlspecialchars($sellerTrust['badge']['label'], ENT_QUOTES, 'UTF-8') ?> · <?= $sellerTrust['score'] ?>/100
        </div>
        <?php if ($seller['bio']): ?>
          <div class="shop-bio"><?= htmlspecialchars($seller['bio'], ENT_QUOTES, 'UTF-8') ?></div>
        <?php endif; ?>
        <div class="shop-stats">
          <div class="shop-stat">
            <div class="shop-stat-val"><?= (int)$shopStat['product_count'] ?></div>
            <div class="shop-stat-lbl">สินค้า</div>
          </div>
          <div class="shop-stat">
            <div class="shop-stat-val"><?= (int)$shopStat['sold_total'] ?></div>
            <div class="shop-stat-lbl">ขายแล้ว</div>
          </div>
          <?php if ((float)$shopStat['avg_rating'] > 0): ?>
          <div class="shop-stat">
            <div class="shop-stat-val">⭐ <?= number_format((float)$shopStat['avg_rating'],1) ?></div>
            <div class="shop-stat-lbl"><?= (int)$shopStat['review_count'] ?> รีวิว</div>
          </div>
          <?php endif; ?>
        </div>
      </div>

      <?php if (isset($_SESSION['user_id']) && (int)$_SESSION['user_id'] !== $sellerId && !empty($products)): /* แชทผูกกับสินค้า ร้านที่ไม่มีสินค้าจะได้ลิงก์ product_id=0 ที่ใช้ไม่ได้ */ ?>
        <a href="seller_chat.php?product_id=<?= (int)($products[0]['id'] ?? 0) ?>" class="btn-chat">
          <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/>
          </svg>
          ส่งข้อความ
        </a>
      <?php endif; ?>
    </div>
  </div>

  <!-- Filter -->
  <form class="filter-bar" method="GET">
    <input type="hidden" name="seller_id" value="<?= $sellerId ?>">
    <input name="q" value="<?= htmlspecialchars($q, ENT_QUOTES, 'UTF-8') ?>" placeholder="ค้นหาในร้านนี้...">
    <button type="submit" class="btn" style="height:38px; padding:0 16px; font-size:13px;">ค้นหา</button>
    <?php if ($q): ?>
      <a href="shop.php?seller_id=<?= $sellerId ?>" style="font-size:13px; color:var(--n500);">ล้าง ×</a>
    <?php endif; ?>
    <select name="sort" class="input" style="height:38px; font-size:13px; width:auto;" onchange="this.form.submit()">
      <?php foreach ($sortOptions as $key => $opt): ?>
        <option value="<?= $key ?>" <?= $sort === $key ? 'selected' : '' ?>><?= htmlspecialchars($opt['label'], ENT_QUOTES, 'UTF-8') ?></option>
      <?php endforeach; ?>
    </select>
    <span class="product-count">สินค้า <?= number_format($totalProducts) ?> รายการ</span>
  </form>

  <!-- Grid -->
  <div class="grid">
    <?php if (empty($products)): ?>
      <div class="no-products">
        <div class="no-products-icon">📦</div>
        <div style="font-weight:600; color:var(--n600); margin-bottom:6px;">ไม่พบสินค้า</div>
      </div>
    <?php else: ?>
      <?php foreach ($products as $p): ?>
        <a class="card" href="product.php?id=<?= (int)$p['id'] ?>">
          <div class="card-img">
            <img src="<?= img_src($p['image']) ?>" alt="<?= htmlspecialchars($p['title'], ENT_QUOTES, 'UTF-8') ?>" loading="lazy">
            <div class="card-overlay"><div class="card-quick-btn">ดูสินค้า</div></div>
          </div>
          <div class="pad">
            <div class="line-clamp-2"><?= htmlspecialchars($p['title'], ENT_QUOTES, 'UTF-8') ?></div>
            <div class="price">฿<?= number_format((float)$p['price'], 2) ?></div>
            <div class="stock-label">คงเหลือ <?= (int)$p['stock'] ?> ชิ้น</div>
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
             . 'background:' . ($active ? 'var(--primary)' : '#f7f8fb') . ';'
             . 'color:' . ($active ? '#fff' : 'var(--n700,#495057)') . ';'
             . 'border:1px solid ' . ($active ? 'var(--primary)' : 'var(--n200,#e9ecef)') . ';';
    };
    $rangeStart = max(1, $page - 2);
    $rangeEnd   = min($totalPages, $page + 2);
    ?>
    <?php if ($page > 1): ?>
      <a href="<?= build_url(['page' => $page - 1], $sellerId, $q, $catId, $sort, $page) ?>" style="<?= $pillStyle(false) ?>">‹ ก่อนหน้า</a>
    <?php endif; ?>

    <?php if ($rangeStart > 1): ?>
      <a href="<?= build_url(['page' => 1], $sellerId, $q, $catId, $sort, $page) ?>" style="<?= $pillStyle(false) ?>">1</a>
      <?php if ($rangeStart > 2): ?><span style="color:var(--n400,#ced4da);">…</span><?php endif; ?>
    <?php endif; ?>

    <?php for ($i = $rangeStart; $i <= $rangeEnd; $i++): ?>
      <a href="<?= build_url(['page' => $i], $sellerId, $q, $catId, $sort, $page) ?>" style="<?= $pillStyle($i === $page) ?>"><?= $i ?></a>
    <?php endfor; ?>

    <?php if ($rangeEnd < $totalPages): ?>
      <?php if ($rangeEnd < $totalPages - 1): ?><span style="color:var(--n400,#ced4da);">…</span><?php endif; ?>
      <a href="<?= build_url(['page' => $totalPages], $sellerId, $q, $catId, $sort, $page) ?>" style="<?= $pillStyle(false) ?>"><?= $totalPages ?></a>
    <?php endif; ?>

    <?php if ($page < $totalPages): ?>
      <a href="<?= build_url(['page' => $page + 1], $sellerId, $q, $catId, $sort, $page) ?>" style="<?= $pillStyle(false) ?>">ถัดไป ›</a>
    <?php endif; ?>
  </div>
  <?php endif; ?>

</div>
</div>
</body>
</html>
