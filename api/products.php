<?php
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../popular_products.php';

$action = $_GET['action'] ?? 'list';
require_method('GET');

// ══ POPULAR ══
// ใช้โมดูลเดียวกับหน้าเว็บ อันดับบนแอปกับบนเว็บจึงตรงกันเสมอ
if ($action === 'popular') {
    $limit = (int)($_GET['limit'] ?? 8);
    $days  = (int)($_GET['days'] ?? POPULAR_DAYS);

    $result = get_popular_products($conn, $limit, $days);

    foreach ($result['items'] as &$p) {
        $p['image_url'] = api_img_url($p['image']);
    }
    unset($p);

    json_response([
        'success'  => true,
        'products' => $result['items'],
        // แอปต้องรู้ว่านี่คืออันดับจริงหรือรายการสำรอง จะได้ขึ้นหัวข้อให้ถูก
        'fallback' => $result['fallback'],
        'days'     => $result['days'],
    ]);
}

// ══ LIST / SEARCH / FILTER ══
if ($action === 'list') {
    $q     = trim($_GET['q'] ?? '');
    $catId = (int)($_GET['cat'] ?? 0);

    $sql = "SELECT p.id, p.title, p.price, p.image, p.stock, p.seller_id,
                   u.username seller_name, u.display_name seller_display,
                   c.icon cat_icon, c.name cat_name
            FROM products p
            JOIN users u ON u.id = p.seller_id
            LEFT JOIN categories c ON c.id = p.category_id
            WHERE 1";
    $params = []; $types = '';

    if ($q !== '') {
        $sql .= " AND (p.title LIKE ? OR p.description LIKE ?)";
        // escape ตัว wildcard ของ LIKE ไม่ให้ค้นหา % หรือ _ แล้วได้สินค้าทั้งร้าน
        $like = '%' . addcslashes(mb_substr($q, 0, 100), chr(92) . '%_') . '%';
        $params[] = $like; $params[] = $like; $types .= 'ss';
    }
    if ($catId > 0) {
        $sql .= " AND p.category_id = ?";
        $params[] = $catId; $types .= 'i';
    }
    $sql .= " ORDER BY p.created_at DESC LIMIT 60";

    $st = $conn->prepare($sql);
    if ($types) $st->bind_param($types, ...$params);
    $st->execute();
    $rows = $st->get_result()->fetch_all(MYSQLI_ASSOC);
    $st->close();

    foreach ($rows as &$r) {
        $r['id']    = (int)$r['id'];
        $r['price'] = (float)$r['price'];
        $r['stock'] = (int)$r['stock'];
        $r['image_url'] = api_img_url($r['image']);
    }
    unset($r);
    json_response(['success' => true, 'products' => $rows]);
}

// ══ DETAIL ══
if ($action === 'detail') {
    $id = (int)($_GET['id'] ?? 0);
    $st = $conn->prepare(
        "SELECT p.*, u.username seller_name, u.display_name seller_display,
                c.icon cat_icon, c.name cat_name
         FROM products p
         JOIN users u ON u.id = p.seller_id
         LEFT JOIN categories c ON c.id = p.category_id
         WHERE p.id=? LIMIT 1"
    );
    $st->bind_param('i', $id);
    $st->execute();
    $p = $st->get_result()->fetch_assoc();
    $st->close();

    if (!$p) json_error('ไม่พบสินค้า', 404);

    $p['id']    = (int)$p['id'];
    $p['price'] = (float)$p['price'];
    $p['stock'] = (int)$p['stock'];
    $p['image_url'] = api_img_url($p['image']);

    // reviews
    $rv = $conn->prepare(
        "SELECT r.rating, r.body, r.created_at, u.username, u.display_name
         FROM reviews r JOIN users u ON u.id=r.user_id
         WHERE r.product_id=? ORDER BY r.created_at DESC LIMIT 20"
    );
    $rv->bind_param('i', $id);
    $rv->execute();
    $reviews = $rv->get_result()->fetch_all(MYSQLI_ASSOC);
    $rv->close();

    json_response(['success' => true, 'product' => $p, 'reviews' => $reviews]);
}

// ══ CATEGORIES ══
if ($action === 'categories') {
    $cats = $conn->query("SELECT id, name, icon, sort FROM categories ORDER BY sort ASC")->fetch_all(MYSQLI_ASSOC);
    foreach ($cats as &$c) $c['id'] = (int)$c['id'];
    unset($c);
    json_response(['success' => true, 'categories' => $cats]);
}

json_error('Unknown action', 404);
