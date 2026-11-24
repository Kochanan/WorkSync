<?php
require_once 'init.php';
require_once 'trust_score.php';

$pid = (int)($_GET['id'] ?? 0);
if ($pid <= 0) { header('Location: index.php'); exit; }

$uid      = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0;
$err      = '';
$ok       = '';

/* ── ดึงสินค้า ────────────────────────────────────────────── */
$st = $conn->prepare("SELECT * FROM products WHERE id = ? LIMIT 1");
$st->bind_param('i', $pid);
$st->execute();
$p = $st->get_result()->fetch_assoc();
$st->close();
if (!$p) { header('Location: index.php'); exit; }

/* ── ผู้ขาย + Trust Score ────────────────────────────────── */
$sst = $conn->prepare("SELECT id, username, display_name, avatar FROM users WHERE id = ? LIMIT 1");
$sst->bind_param('i', $p['seller_id']);
$sst->execute();
$seller = $sst->get_result()->fetch_assoc();
$sst->close();
// บัญชีผู้ขายถูกลบไปแล้ว กันหน้าเว็บพังเป็น warning
if (!$seller) { $seller = ['id' => (int)$p['seller_id'], 'username' => 'ผู้ขาย', 'display_name' => null, 'avatar' => null]; }
$sellerName  = $seller['display_name'] ?: $seller['username'];
$sellerTrust = get_trust_score($conn, (int)$p['seller_id']);

/* ── สถานะให้ยืม ──────────────────────────────────────────── */
$lst = $conn->prepare("SELECT lending_days_limit FROM product_lending_info WHERE product_id = ? AND is_lendable = 1 LIMIT 1");
$lst->bind_param('i', $pid);
$lst->execute();
$lendInfo = $lst->get_result()->fetch_assoc();
$lst->close();

/* ── สิทธิ์รีวิว: ต้องเคยซื้อและได้รับสินค้าแล้วเท่านั้น ─────────────
   เดิมใครล็อกอินก็รีวิวได้ รวมถึงผู้ขายรีวิวสินค้าตัวเอง → ปั่นคะแนนได้ */
$reviewOrderId = 0;
if ($uid > 0 && $uid !== (int)$p['seller_id']) {
    $bst = $conn->prepare(
        "SELECT o.id FROM orders o JOIN order_items oi ON oi.order_id = o.id
         WHERE o.user_id = ? AND oi.product_id = ? AND o.status = 'delivered'
         ORDER BY o.id DESC LIMIT 1"
    );
    $bst->bind_param('ii', $uid, $pid);
    $bst->execute();
    $reviewOrderId = (int)($bst->get_result()->fetch_row()[0] ?? 0);
    $bst->close();
}
$canReview = $reviewOrderId > 0;

/* ── POST: ส่งรีวิว ──────────────────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $uid > 0) {
    check_csrf();

    $ratingIn = (int)($_POST['rating'] ?? 0);
    $body     = trim(is_string($_POST['body'] ?? null) ? $_POST['body'] : '');

    if (!$canReview) {
        $err = 'รีวิวได้เฉพาะผู้ที่ซื้อและได้รับสินค้านี้แล้วเท่านั้น';
    } elseif ($ratingIn < 1 || $ratingIn > 5) {
        $err = 'คะแนนต้องอยู่ระหว่าง 1–5 ดาว';
    } elseif ($body === '') {
        $err = 'กรุณาเขียนรีวิวก่อนส่ง';
    } elseif (mb_strlen($body) > 1000) {
        $err = 'รีวิวยาวเกินไป (สูงสุด 1,000 ตัวอักษร)';
    } else {
        // ตรวจ duplicate
        $chk = $conn->prepare("SELECT id FROM reviews WHERE user_id=? AND product_id=? LIMIT 1");
        $chk->bind_param('ii', $uid, $pid);
        $chk->execute();
        $dup = $chk->get_result()->fetch_assoc();
        $chk->close();

        if ($dup) {
            $err = 'คุณได้รีวิวสินค้านี้ไปแล้ว';
        } else {
            $rating = $ratingIn;
            $ins = $conn->prepare(
                "INSERT INTO reviews (product_id, user_id, order_id, rating, body) VALUES (?,?,?,?,?)"
            );
            $ins->bind_param('iiiis', $pid, $uid, $reviewOrderId, $rating, $body);
            try {
                $ins->execute();
                $reviewId = (int)$ins->insert_id;
            } catch (mysqli_sql_exception $e) {
                // กดส่งซ้อนกันสองครั้ง ชน UNIQUE (user_id, product_id)
                $reviewId = 0;
                $err = 'คุณได้รีวิวสินค้านี้ไปแล้ว';
            }
            $ins->close();

            /* อัปโหลดรูปภาพ (สูงสุด 5 รูป) */
            if ($reviewId > 0 && !empty($_FILES['images']['tmp_name']) && is_array($_FILES['images']['tmp_name'])) {
                $uploadDir = __DIR__ . '/assets/reviews';
                if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);

                $mimeExt     = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
                $allowedExt  = ['jpg','jpeg','png','webp'];
                $sort = 0;

                foreach ($_FILES['images']['tmp_name'] as $i => $tmp) {
                    if ($sort >= 5) break;
                    if ($_FILES['images']['error'][$i] !== UPLOAD_ERR_OK) continue;
                    if ($_FILES['images']['size'][$i] > 3 * 1024 * 1024) continue;

                    $ext = strtolower(pathinfo($_FILES['images']['name'][$i], PATHINFO_EXTENSION));
                    if (!in_array($ext, $allowedExt, true)) continue;

                    $fi   = finfo_open(FILEINFO_MIME_TYPE);
                    $mime = finfo_file($fi, $tmp);
                    finfo_close($fi);
                    if (!isset($mimeExt[$mime])) continue;
                    $info = @getimagesize($tmp);
                    if (!$info || !in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP], true)) continue;
                    if (stripos((string)@file_get_contents($tmp), '<?php') !== false) continue;

                    $fname  = 'rv_' . $reviewId . '_' . bin2hex(random_bytes(6)) . '.' . $mimeExt[$mime];
                    $target = $uploadDir . '/' . $fname;
                    if (move_uploaded_file($tmp, $target)) {
                        $imgIns = $conn->prepare(
                            "INSERT INTO review_images (review_id, path, sort) VALUES (?,?,?)"
                        );
                        $imgPath = 'assets/reviews/' . $fname;
                        $imgIns->bind_param('isi', $reviewId, $imgPath, $sort);
                        $imgIns->execute();
                        $imgIns->close();
                        $sort++;
                    }
                }
            }

            if ($reviewId > 0) { $ok = 'ขอบคุณสำหรับรีวิวของคุณ!'; }
        }
    }
}

/* ── ดึงรีวิวทั้งหมด ─────────────────────────────────────── */
$rst = $conn->prepare(
    "SELECT r.id, r.rating, r.body, r.created_at,
            u.username, u.display_name, u.avatar,
            (SELECT COUNT(*) FROM review_images WHERE review_id = r.id) img_count
     FROM reviews r
     JOIN users u ON u.id = r.user_id
     WHERE r.product_id = ?
     ORDER BY r.created_at DESC
     LIMIT 50"
);
$rst->bind_param('i', $pid);
$rst->execute();
$reviews = $rst->get_result()->fetch_all(MYSQLI_ASSOC);
$rst->close();

/* ── ดึงรูปของแต่ละรีวิว ─────────────────────────────────── */
$reviewImgs = [];
if (!empty($reviews)) {
    $rids = implode(',', array_map('intval', array_column($reviews, 'id')));
    $imgs = $conn->query(
        "SELECT review_id, path FROM review_images WHERE review_id IN ($rids) ORDER BY sort ASC"
    );
    while ($img = $imgs->fetch_assoc()) {
        $reviewImgs[$img['review_id']][] = $img['path'];
    }
}

/* ── สถิติคะแนน ──────────────────────────────────────────── */
$statSt = $conn->prepare(
    "SELECT AVG(rating) avg_r, COUNT(*) total,
            SUM(rating=5) r5, SUM(rating=4) r4, SUM(rating=3) r3,
            SUM(rating=2) r2, SUM(rating=1) r1
     FROM reviews WHERE product_id=?"
);
$statSt->bind_param('i', $pid);
$statSt->execute();
$rStats = $statSt->get_result()->fetch_assoc();
$statSt->close();

/* ── รีวิวของ user ปัจจุบัน ─────────────────────────────── */
$myReview = null;
if ($uid > 0) {
    $mySt = $conn->prepare("SELECT id FROM reviews WHERE user_id=? AND product_id=? LIMIT 1");
    $mySt->bind_param('ii', $uid, $pid);
    $mySt->execute();
    $myReview = $mySt->get_result()->fetch_assoc();
    $mySt->close();
}

$avgRating  = round((float)($rStats['avg_r'] ?? 0), 1);
$totalReview = (int)($rStats['total'] ?? 0);
?>
<!doctype html>
<html lang="th">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <link rel="stylesheet" href="assets/style.css?v=<?= @filemtime(__DIR__.'/assets/style.css') ?>">
  <title><?= htmlspecialchars($p['title'], ENT_QUOTES, 'UTF-8') ?> — WorkSync</title>
  <style>
    /* ── Product layout ── */
    .product-wrap { max-width: 960px; margin: 24px auto; padding: 0 16px 60px; }

    .product-card {
      background: #fff;
      border: 1px solid var(--n200,#e9ecef);
      border-radius: 14px;
      overflow: hidden;
      display: flex;
      gap: 0;
      margin-bottom: 24px;
      box-shadow: 0 1px 4px rgba(0,0,0,.06);
    }
    .product-img-col {
      width: 420px; flex-shrink: 0;
      background: var(--n100,#f1f3f5);
    }
    .product-img-col img {
      width: 100%; height: 420px;
      object-fit: cover; display: block;
    }
    .product-info-col { flex: 1; padding: 28px; display: flex; flex-direction: column; }
    .product-title { font-size: 22px; font-weight: 700; color: var(--n900,#212529); line-height: 1.35; }
    .product-price { font-size: 30px; font-weight: 800; color: var(--red,#c8102e); margin: 14px 0; font-variant-numeric: tabular-nums; }

    /* mini rating bar */
    .mini-rating { display: flex; align-items: center; gap: 8px; margin-bottom: 16px; }
    .stars-sm { display: flex; gap: 2px; }
    .star-sm { font-size: 16px; color: var(--n300,#dee2e6); }
    .star-sm.on { color: #f59e0b; }
    .mini-rating .avg { font-size: 15px; font-weight: 700; color: var(--n800,#343a40); }
    .mini-rating .cnt { font-size: 13px; color: var(--n500,#adb5bd); }

    .product-desc { font-size: 14px; color: var(--n600,#6c757d); line-height: 1.7; flex: 1; margin-bottom: 20px; white-space: pre-line; }

    .stock-box {
      background: var(--n50,#f8f9fa);
      border: 1px solid var(--n200,#e9ecef);
      border-radius: 10px;
      padding: 16px;
    }
    .stock-label { font-size: 13px; color: var(--n500,#adb5bd); margin-bottom: 10px; }
    .add-cart-form { display: flex; gap: 10px; align-items: center; flex-wrap: wrap; }
    .qty-input { width: 64px; text-align: center; padding: 8px; border: 1px solid var(--n300,#dee2e6); border-radius: 8px; font-size: 15px; font-family: inherit; }
    .btn-cart {
      padding: 0 22px; height: 42px;
      background: var(--primary,#0f4c81); color: #fff;
      border: none; border-radius: 9px;
      font-size: 15px; font-weight: 700;
      cursor: pointer; transition: background .15s;
    }
    .btn-cart:hover { background: var(--primary-dark,#0a3560); }
    .out-of-stock { font-weight: 700; color: var(--red,#c8102e); font-size: 15px; }

    /* ── Review section ── */
    .review-section { background: #fff; border: 1px solid var(--n200,#e9ecef); border-radius: 14px; overflow: hidden; box-shadow: 0 1px 4px rgba(0,0,0,.06); }
    .rv-head { padding: 20px 24px; border-bottom: 1px solid var(--n100,#f1f3f5); background: var(--n50,#f8f9fa); }
    .rv-head-title { font-size: 17px; font-weight: 700; color: var(--n900,#212529); margin-bottom: 14px; }

    /* summary bar */
    .rv-summary { display: flex; gap: 28px; align-items: center; flex-wrap: wrap; }
    .rv-big-score { text-align: center; }
    .rv-big-num { font-size: 52px; font-weight: 800; color: var(--n900,#212529); line-height: 1; }
    .rv-big-stars { display: flex; gap: 3px; justify-content: center; margin: 6px 0; }
    .star-lg { font-size: 20px; color: var(--n300,#dee2e6); }
    .star-lg.on { color: #f59e0b; }
    .rv-big-cnt { font-size: 13px; color: var(--n500,#adb5bd); }

    .rv-bars { flex: 1; min-width: 200px; }
    .rv-bar-row { display: flex; align-items: center; gap: 8px; margin-bottom: 6px; }
    .rv-bar-lbl { font-size: 12px; color: var(--n600,#6c757d); width: 20px; text-align: right; flex-shrink: 0; }
    .rv-bar-track { flex: 1; height: 8px; background: var(--n200,#e9ecef); border-radius: 4px; overflow: hidden; }
    .rv-bar-fill { height: 100%; background: #f59e0b; border-radius: 4px; transition: width .4s; }
    .rv-bar-cnt { font-size: 12px; color: var(--n500,#adb5bd); width: 24px; flex-shrink: 0; }

    /* write review form */
    .rv-form-wrap { padding: 24px; border-bottom: 1px solid var(--n100,#f1f3f5); }
    .rv-form-title { font-size: 15px; font-weight: 700; color: var(--n800,#343a40); margin-bottom: 14px; }

    /* star selector */
    .star-select { display: flex; gap: 6px; margin-bottom: 14px; }
    .star-select input { display: none; }
    .star-select label {
      font-size: 30px; color: var(--n300,#dee2e6);
      cursor: pointer; transition: color .1s, transform .1s;
      user-select: none;
    }
    .star-select label:hover,
    .star-select label.active { color: #f59e0b; }
    .star-select label:hover { transform: scale(1.15); }

    .rv-textarea {
      width: 100%; padding: 10px 14px;
      border: 1px solid var(--n300,#dee2e6);
      border-radius: 8px; font-family: inherit; font-size: 14px;
      line-height: 1.6; resize: vertical; min-height: 90px;
      transition: border-color .15s, box-shadow .15s;
      color: var(--n900,#212529); box-sizing: border-box;
    }
    .rv-textarea:focus { outline: none; border-color: var(--primary,#0f4c81); box-shadow: 0 0 0 3px rgba(15,76,129,.1); }

    /* image upload */
    .img-upload-row { display: flex; gap: 8px; flex-wrap: wrap; margin: 12px 0; }
    .img-thumb-add {
      width: 72px; height: 72px; border-radius: 8px;
      border: 1.5px dashed var(--n400,#ced4da);
      display: flex; flex-direction: column; align-items: center; justify-content: center;
      cursor: pointer; font-size: 22px; color: var(--n400,#ced4da);
      transition: border-color .15s, color .15s; background: var(--n50,#f8f9fa);
    }
    .img-thumb-add:hover { border-color: var(--primary,#0f4c81); color: var(--primary,#0f4c81); }
    .img-thumb-add span { font-size: 10px; margin-top: 2px; color: var(--n500,#adb5bd); }

    .img-preview-box {
      position: relative; width: 72px; height: 72px; border-radius: 8px; overflow: hidden;
      border: 1px solid var(--n200,#e9ecef);
    }
    .img-preview-box img { width: 100%; height: 100%; object-fit: cover; display: block; }
    .img-preview-box .rm {
      position: absolute; top: 2px; right: 2px;
      width: 18px; height: 18px; border-radius: 50%;
      background: rgba(0,0,0,.55); color: #fff;
      font-size: 11px; display: flex; align-items: center; justify-content: center;
      cursor: pointer; font-weight: 700;
    }

    .btn-submit-rv {
      margin-top: 14px; padding: 0 20px; height: 38px;
      background: var(--primary,#0f4c81); color: #fff;
      border: none; border-radius: 8px;
      font-size: 14px; font-weight: 600;
      cursor: pointer; transition: background .15s;
    }
    .btn-submit-rv:hover { background: var(--primary-dark,#0a3560); }

    /* review list */
    .rv-list { padding: 0 24px 8px; }
    .rv-item {
      padding: 20px 0;
      border-bottom: 1px solid var(--n100,#f1f3f5);
    }
    .rv-item:last-child { border-bottom: none; }

    .rv-user-row { display: flex; align-items: center; gap: 12px; margin-bottom: 10px; }
    .rv-av {
      width: 38px; height: 38px; border-radius: 50%; flex-shrink: 0;
      object-fit: cover; background: var(--primary,#0f4c81);
      color: #fff; font-size: 14px; font-weight: 700;
      display: flex; align-items: center; justify-content: center;
    }
    .rv-av img { width: 38px; height: 38px; border-radius: 50%; object-fit: cover; }
    .rv-uname { font-size: 14px; font-weight: 600; color: var(--n800,#343a40); }
    .rv-date  { font-size: 12px; color: var(--n500,#adb5bd); margin-top: 1px; }

    .rv-stars { display: flex; gap: 2px; margin-bottom: 8px; }
    .rv-star { font-size: 14px; color: var(--n300,#dee2e6); }
    .rv-star.on { color: #f59e0b; }

    .rv-body { font-size: 14px; color: var(--n700,#495057); line-height: 1.65; margin-bottom: 10px; }

    /* review photos */
    .rv-photos { display: flex; gap: 8px; flex-wrap: wrap; margin-top: 10px; }
    .rv-photo {
      width: 80px; height: 80px; border-radius: 8px;
      object-fit: cover; cursor: pointer;
      border: 1px solid var(--n200,#e9ecef);
      transition: transform .15s;
    }
    .rv-photo:hover { transform: scale(1.04); }

    /* lightbox */
    .lb-overlay {
      display: none; position: fixed; inset: 0;
      background: rgba(0,0,0,.88); z-index: 9999;
      align-items: center; justify-content: center;
    }
    .lb-overlay.show { display: flex; }
    .lb-overlay img { max-width: 90vw; max-height: 88vh; border-radius: 8px; box-shadow: 0 4px 32px rgba(0,0,0,.4); }
    .lb-close {
      position: absolute; top: 18px; right: 22px;
      color: #fff; font-size: 28px; cursor: pointer; font-weight: 300; line-height: 1;
    }

    /* empty */
    .rv-empty { padding: 32px 24px; text-align: center; color: var(--n400,#ced4da); font-size: 14px; }

    .alert { padding: 11px 16px; border-radius: 8px; font-size: 13px; font-weight: 600; margin-bottom: 14px; display: flex; align-items: center; gap: 8px; }
    .alert-ok  { background: #eaf6f0; color: #1a7f4b; }
    .alert-err { background: #fdf0f2; color: #c8102e; }

    @media(max-width:720px){
      .product-card { flex-direction: column; }
      .product-img-col { width: 100%; }
      .product-img-col img { height: 260px; }
    }
  </style>
</head>
<body>
<?php include 'navbar.php'; ?>

<div class="product-wrap">

  <!-- Product card -->
  <div class="product-card">
    <div class="product-img-col">
      <img src="<?= img_src($p['image']) ?>" alt="<?= htmlspecialchars($p['title'], ENT_QUOTES, 'UTF-8') ?>">
    </div>
    <div class="product-info-col">
      <div>
        <div class="product-title"><?= htmlspecialchars($p['title'], ENT_QUOTES, 'UTF-8') ?></div>

        <!-- mini rating -->
        <?php if ($totalReview > 0): ?>
        <div class="mini-rating" style="margin-top:10px;">
          <div class="stars-sm">
            <?php for($s=1;$s<=5;$s++): ?>
              <span class="star-sm <?= $s <= round($avgRating) ? 'on' : '' ?>">★</span>
            <?php endfor; ?>
          </div>
          <span class="avg"><?= number_format($avgRating,1) ?></span>
          <span class="cnt">(<?= $totalReview ?> รีวิว)</span>
        </div>
        <?php endif; ?>

        <div class="product-price">฿<?= number_format((float)$p['price'], 2) ?></div>
        <div class="product-desc"><?= htmlspecialchars($p['description'] ?? 'ไม่มีคำอธิบาย', ENT_QUOTES, 'UTF-8') ?></div>
      </div>

      <a href="shop.php?seller_id=<?= (int)$p['seller_id'] ?>"
         style="display:flex;align-items:center;gap:10px;padding:10px 14px;background:var(--n50,#f8f9fa);
                border-radius:10px;text-decoration:none;margin-bottom:14px;">
        <div style="width:36px;height:36px;border-radius:50%;background:var(--primary,#0f4c81);color:#fff;
                    display:flex;align-items:center;justify-content:center;font-size:13px;font-weight:700;
                    overflow:hidden;flex-shrink:0;">
          <?php if ($seller['avatar']): ?>
            <img src="<?= htmlspecialchars($seller['avatar'], ENT_QUOTES, 'UTF-8') ?>" style="width:100%;height:100%;object-fit:cover;">
          <?php else: ?>
            <?= htmlspecialchars(mb_strtoupper(mb_substr($sellerName, 0, 2)), ENT_QUOTES, 'UTF-8') ?>
          <?php endif; ?>
        </div>
        <div style="flex:1;min-width:0;">
          <div style="font-size:13.5px;font-weight:700;color:var(--n900,#212529);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">
            <?= htmlspecialchars($sellerName, ENT_QUOTES, 'UTF-8') ?>
          </div>
          <div style="font-size:11.5px;font-weight:700;color:<?= $sellerTrust['badge']['color'] ?>;">
            <?= $sellerTrust['badge']['icon'] ?> <?= htmlspecialchars($sellerTrust['badge']['label'], ENT_QUOTES, 'UTF-8') ?> · <?= $sellerTrust['score'] ?>/100
          </div>
        </div>
        <div style="font-size:12px;color:var(--n500,#adb5bd);">ดูร้าน ›</div>
      </a>

      <?php if ($lendInfo): ?>
        <a href="lending.php" style="display:flex;align-items:center;gap:8px;padding:9px 14px;background:#eaf6f0;
                  color:#0f9b5e;border-radius:10px;text-decoration:none;margin-bottom:14px;font-size:12.5px;font-weight:700;">
          🤝 สินค้านี้เปิดให้ยืมได้สูงสุด <?= (int)$lendInfo['lending_days_limit'] ?> วัน — ไปยืมที่นี่ ›
        </a>
      <?php endif; ?>

      <div class="stock-box">
        <div class="stock-label">คงเหลือในคลัง: <b style="color:var(--n800)"><?= (int)$p['stock'] ?></b> ชิ้น</div>
        <?php if ($uid > 0 && (int)$p['seller_id'] === $uid): /* ผู้ขายซื้อสินค้าตัวเองไม่ได้ (cart.php ปฏิเสธอยู่แล้ว) */ ?>
          <div style="font-size:13px;color:var(--n500,#adb5bd);">นี่คือสินค้าของคุณ</div>
        <?php elseif ((int)$p['stock'] > 0): ?>
          <form class="add-cart-form" method="POST" action="add_to_cart.php" id="addCartForm">
            <?= csrf_tag() ?>
            <input type="hidden" name="product_id" value="<?= $pid ?>">
            <label style="font-size:13px;font-weight:600;color:var(--n600,#6c757d);">จำนวน</label>
            <div style="display:flex; align-items:center; gap:0; margin:8px 0 4px;">
              <button type="button" id="btnMinus"
                      style="width:36px;height:36px;border:1px solid rgba(13,33,55,.15);
                             border-radius:8px 0 0 8px;background:#f7f8fb;font-size:18px;
                             cursor:pointer;font-weight:700;color:#3d5066;line-height:1;">−</button>
              <input class="qty-input" type="number" name="qty" id="qtyInput"
                     value="1" min="1" max="<?= (int)$p['stock'] ?>"
                     style="width:64px;border-radius:0;border-left:none;border-right:none;
                            text-align:center;font-size:15px;">
              <button type="button" id="btnPlus"
                      style="width:36px;height:36px;border:1px solid rgba(13,33,55,.15);
                             border-radius:0 8px 8px 0;background:#f7f8fb;font-size:18px;
                             cursor:pointer;font-weight:700;color:#3d5066;line-height:1;">+</button>
            </div>
            <div id="qtyAlert" style="font-size:12px;color:#c8102e;margin-bottom:8px;display:none;"></div>
            <div style="font-size:11px;color:#aaa;margin-bottom:12px;">คงเหลือ <?= (int)$p['stock'] ?> ชิ้น</div>
            <button type="submit" class="btn-cart" id="btnAddCart">เพิ่มไปยังตะกร้า</button>
          </form>
          <script>
          (function(){
            const max    = <?= (int)$p['stock'] ?>;
            const inp    = document.getElementById('qtyInput');
            const alert_ = document.getElementById('qtyAlert');
            const btn    = document.getElementById('btnAddCart');
            function validate(v){
              v = parseInt(v) || 1;
              if (v < 1) v = 1;
              if (v > max) {
                alert_.textContent = 'จำนวนเกินสต็อกที่มี (' + max + ' ชิ้น)';
                alert_.style.display = 'block';
                v = max;
                btn.disabled = false;
              } else {
                alert_.style.display = 'none';
                btn.disabled = false;
              }
              inp.value = v;
            }
            document.getElementById('btnMinus').onclick = () => validate(parseInt(inp.value||1) - 1);
            document.getElementById('btnPlus').onclick  = () => validate(parseInt(inp.value||1) + 1);
            inp.addEventListener('input', () => validate(inp.value));
            validate(1);
          })();
          </script>
        <?php else: ?>
          <div class="out-of-stock">สินค้าหมดชั่วคราว</div>
        <?php endif; ?>

        <?php if ($uid > 0 && (int)$p['seller_id'] === $uid): ?>
          <div style="margin-top:10px; padding-top:10px; border-top:1px solid var(--n200,#e9ecef);">
            <a href="edit_product.php?id=<?= $pid ?>" style="font-size:13px; font-weight:600; color:var(--primary,#0f4c81);">✎ แก้ไขสินค้านี้</a>
          </div>
        <?php endif; ?>
        <?php if (isset($_SESSION['user_id']) && (int)$p['seller_id'] !== $uid): ?>
          <div style="margin-top:10px; padding-top:10px; border-top:1px solid var(--n200,#e9ecef);">
            <a href="seller_chat.php?product_id=<?= $pid ?>"
               style="display:inline-flex; align-items:center; gap:6px; font-size:13px; font-weight:600;
                      color:var(--primary,#0f4c81); text-decoration:none; padding:6px 0;">
              <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/>
              </svg>
              ถามผู้ขาย
            </a>
          </div>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <!-- Review section -->
  <div class="review-section">
    <div class="rv-head">
      <div class="rv-head-title">รีวิวจากผู้ซื้อ</div>

      <?php if ($totalReview > 0): ?>
      <div class="rv-summary">
        <!-- big score -->
        <div class="rv-big-score">
          <div class="rv-big-num"><?= number_format($avgRating,1) ?></div>
          <div class="rv-big-stars">
            <?php for($s=1;$s<=5;$s++): ?>
              <span class="star-lg <?= $s <= round($avgRating) ? 'on' : '' ?>">★</span>
            <?php endfor; ?>
          </div>
          <div class="rv-big-cnt"><?= $totalReview ?> รีวิว</div>
        </div>
        <!-- bar chart -->
        <div class="rv-bars">
          <?php foreach([5,4,3,2,1] as $star):
            $cnt = (int)($rStats['r'.$star] ?? 0);
            $pct = $totalReview > 0 ? round($cnt / $totalReview * 100) : 0;
          ?>
          <div class="rv-bar-row">
            <span class="rv-bar-lbl"><?= $star ?></span>
            <span style="font-size:13px;color:#f59e0b;">★</span>
            <div class="rv-bar-track"><div class="rv-bar-fill" style="width:<?= $pct ?>%"></div></div>
            <span class="rv-bar-cnt"><?= $cnt ?></span>
          </div>
          <?php endforeach; ?>
        </div>
      </div>
      <?php else: ?>
        <div style="color:var(--n500);font-size:14px;">ยังไม่มีรีวิว เป็นคนแรกที่รีวิวสินค้านี้!</div>
      <?php endif; ?>
    </div>

    <!-- Write review form -->
    <?php if ($uid > 0 && !$myReview && $canReview): ?>
    <div class="rv-form-wrap">
      <div class="rv-form-title">เขียนรีวิวสินค้านี้</div>

      <?php if ($err): ?><div class="alert alert-err">✕ <?= htmlspecialchars($err, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>
      <?php if ($ok):  ?><div class="alert alert-ok">✓ <?= htmlspecialchars($ok, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>

      <form method="POST" enctype="multipart/form-data">
        <?= csrf_tag() ?>

        <!-- Star picker -->
        <div style="font-size:13px;font-weight:600;color:var(--n700);margin-bottom:6px;">คะแนน</div>
        <div class="star-select" id="starPicker">
          <?php for($s=1;$s<=5;$s++): ?>
            <input type="radio" name="rating" id="r<?=$s?>" value="<?=$s?>" <?= $s===5?'checked':'' ?>>
            <label for="r<?=$s?>" data-val="<?=$s?>">★</label>
          <?php endfor; ?>
        </div>

        <textarea class="rv-textarea" name="body"
                  placeholder="แชร์ประสบการณ์ของคุณกับสินค้านี้..."
                  maxlength="1000" id="rvBody"></textarea>
        <div style="font-size:12px;color:var(--n500);text-align:right;margin-top:3px;"><span id="rvCount">0</span>/1000</div>

        <!-- Image upload -->
        <div style="font-size:13px;font-weight:600;color:var(--n700);margin:12px 0 6px;">รูปภาพ <span style="font-weight:400;color:var(--n500);">(ไม่เกิน 5 รูป)</span></div>
        <div class="img-upload-row" id="imgPreviewRow">
          <div class="img-thumb-add" id="imgAddBtn">
            <span>+</span>
            <span>เพิ่มรูป</span>
          </div>
        </div>
        <input type="file" id="imgFileInput" name="images[]" accept="image/*" multiple style="display:none;">

        <button type="submit" class="btn-submit-rv">ส่งรีวิว</button>
      </form>
    </div>
    <?php elseif ($uid === 0): ?>
    <div class="rv-form-wrap" style="text-align:center; padding:20px 24px;">
      <span style="font-size:14px;color:var(--n600);">
        <a href="login.php" style="color:var(--primary);font-weight:600;">เข้าสู่ระบบ</a> เพื่อเขียนรีวิวสินค้านี้
      </span>
    </div>
    <?php elseif (!$myReview): ?>
    <div class="rv-form-wrap" style="padding:16px 24px;">
      <?php if ($err): ?><div class="alert alert-err">✕ <?= htmlspecialchars($err, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>
      <div style="font-size:14px;color:var(--n500);">
        <?= $uid === (int)$p['seller_id'] ? 'คุณเป็นผู้ขายสินค้านี้ จึงรีวิวสินค้าของตัวเองไม่ได้' : 'รีวิวได้หลังจากสั่งซื้อและได้รับสินค้านี้แล้ว' ?>
      </div>
    </div>
    <?php elseif ($myReview): ?>
    <div class="rv-form-wrap" style="padding:16px 24px;">
      <?php if ($ok): ?><div class="alert alert-ok">✓ <?= htmlspecialchars($ok, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>
      <div style="font-size:14px;color:var(--n500);">คุณได้รีวิวสินค้านี้ไปแล้ว</div>
    </div>
    <?php endif; ?>

    <!-- Review list -->
    <div class="rv-list">
      <?php if (empty($reviews)): ?>
        <div class="rv-empty">ยังไม่มีรีวิว</div>
      <?php else: ?>
        <?php foreach ($reviews as $rv):
          $rvName   = $rv['display_name'] ?: $rv['username'];
          $rvInit   = mb_strtoupper(mb_substr($rvName, 0, 2));
          $rvDate   = date('d M Y', strtotime($rv['created_at']));
          $rvImgs   = $reviewImgs[$rv['id']] ?? [];
        ?>
        <div class="rv-item">
          <div class="rv-user-row">
            <?php if ($rv['avatar']): ?>
              <div class="rv-av"><img src="<?= htmlspecialchars($rv['avatar'], ENT_QUOTES, 'UTF-8') ?>" alt=""></div>
            <?php else: ?>
              <div class="rv-av"><?= htmlspecialchars($rvInit, ENT_QUOTES, 'UTF-8') ?></div>
            <?php endif; ?>
            <div>
              <div class="rv-uname"><?= htmlspecialchars($rvName, ENT_QUOTES, 'UTF-8') ?></div>
              <div class="rv-date"><?= $rvDate ?></div>
            </div>
          </div>

          <div class="rv-stars">
            <?php for($s=1;$s<=5;$s++): ?>
              <span class="rv-star <?= $s <= (int)$rv['rating'] ? 'on' : '' ?>">★</span>
            <?php endfor; ?>
          </div>

          <div class="rv-body"><?= nl2br(htmlspecialchars($rv['body'], ENT_QUOTES, 'UTF-8')) ?></div>

          <?php if (!empty($rvImgs)): ?>
          <div class="rv-photos">
            <?php foreach($rvImgs as $imgPath): ?>
              <img class="rv-photo" src="<?= htmlspecialchars($imgPath, ENT_QUOTES, 'UTF-8') ?>"
                   alt="" onclick="openLb(this.src)">
            <?php endforeach; ?>
          </div>
          <?php endif; ?>
        </div>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>
  </div>
</div>

<!-- Lightbox -->
<div class="lb-overlay" id="lb" onclick="closeLb()">
  <span class="lb-close" onclick="closeLb()">×</span>
  <img id="lbImg" src="" alt="">
</div>

<script>
/* ── Star picker ── */
(function(){
  const labels = document.querySelectorAll('#starPicker label');
  function setStars(val){
    labels.forEach(l => l.classList.toggle('active', parseInt(l.dataset.val) <= val));
  }
  setStars(5);
  labels.forEach(l => {
    l.addEventListener('click', () => setStars(parseInt(l.dataset.val)));
  });
})();

/* ── Textarea counter ── */
const rvBody  = document.getElementById('rvBody');
const rvCount = document.getElementById('rvCount');
if (rvBody) {
  rvBody.addEventListener('input', () => { rvCount.textContent = [...rvBody.value].length; });
}

/* ── Image upload preview ── */
(function(){
  const input    = document.getElementById('imgFileInput');
  const addBtn   = document.getElementById('imgAddBtn');
  const row      = document.getElementById('imgPreviewRow');
  if (!input) return;

  addBtn.addEventListener('click', () => input.click());

  const dt = new DataTransfer();

  input.addEventListener('change', function(){
    Array.from(this.files).forEach(file => {
      if (dt.files.length >= 5) return;
      dt.items.add(file);
      const reader = new FileReader();
      reader.onload = e => {
        const box = document.createElement('div');
        box.className = 'img-preview-box';
        // สร้าง element ด้วย DOM แทน innerHTML กันชื่อไฟล์แปลก ๆ กลายเป็น HTML
        const im = document.createElement('img'); im.src = e.target.result; im.alt = '';
        const rm = document.createElement('span'); rm.className = 'rm'; rm.textContent = '×';
        box.append(im, rm);
        rm.addEventListener('click', function(){
          const idx = Array.from(dt.files).indexOf(file);
          if (idx > -1) dt.items.remove(idx);
          input.files = dt.files;
          box.remove();
        });
        row.insertBefore(box, addBtn);
      };
      reader.readAsDataURL(file);
    });
    // ต้องล้าง value ก่อนแล้วค่อยใส่ไฟล์กลับ (ของเดิมล้างทีหลัง ทำให้รูปรีวิวไม่เคยถูกส่งขึ้นไป)
    this.value = '';
    input.files = dt.files;
  });
})();

/* ── Lightbox ── */
function openLb(src){ document.getElementById('lbImg').src = src; document.getElementById('lb').classList.add('show'); }
function closeLb()  { document.getElementById('lb').classList.remove('show'); }
document.addEventListener('keydown', e => { if(e.key==='Escape') closeLb(); });
</script>
</body>
</html>
