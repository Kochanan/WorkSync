<?php
require_once __DIR__ . '/_bootstrap.php';
$user = authenticate($conn);
$uid  = (int)$user['user_id'];
$action = $_GET['action'] ?? 'get';
$input  = get_json_input();

// จำนวนสูงสุดต่อรายการ กันค่าล้น int / ค่าประหลาดจาก client
const API_CART_MAX_QTY = 999;

// ══ GET CART ══
if ($action === 'get') {
    require_method('GET');
    $st = $conn->prepare(
        "SELECT c.id cart_id, c.qty, c.selected, p.id product_id, p.title, p.price, p.image, p.stock
         FROM cart c JOIN products p ON c.product_id=p.id
         WHERE c.user_id=? ORDER BY c.created_at DESC"
    );
    $st->bind_param('i', $uid);
    $st->execute();
    $items = $st->get_result()->fetch_all(MYSQLI_ASSOC);
    $st->close();

    $total = 0;
    foreach ($items as &$it) {
        $it['cart_id']    = (int)$it['cart_id'];
        $it['product_id'] = (int)$it['product_id'];
        $it['qty']        = (int)$it['qty'];
        $it['price']      = (float)$it['price'];
        $it['stock']      = (int)$it['stock'];
        $it['selected']   = (bool)$it['selected'];
        $it['image_url']  = api_img_url($it['image']);
        if ($it['selected']) $total += $it['price'] * $it['qty'];
    }
    json_response(['success' => true, 'items' => $items, 'total' => $total]);
}

// ══ ADD ══
if ($action === 'add') {
    require_method('POST');
    $pid = (int)($input['product_id'] ?? 0);
    $qty = min(API_CART_MAX_QTY, max(1, (int)($input['qty'] ?? 1)));
    if ($pid <= 0) json_error('Invalid product');

    $chk = $conn->prepare("SELECT stock FROM products WHERE id=? LIMIT 1");
    $chk->bind_param('i', $pid); $chk->execute();
    $prod = $chk->get_result()->fetch_assoc(); $chk->close();
    if (!$prod) json_error('ไม่พบสินค้า', 404);
    // สต็อกเป็น 0 เดิมยังใส่ตะกร้าได้ด้วย qty=0
    $stock = (int)$prod['stock'];
    if ($stock < 1) json_error('สินค้าหมดสต็อก', 409);

    $st = $conn->prepare("SELECT id, qty FROM cart WHERE user_id=? AND product_id=? LIMIT 1");
    $st->bind_param('ii', $uid, $pid); $st->execute();
    $existing = $st->get_result()->fetch_assoc(); $st->close();

    if ($existing) {
        $newQty = min((int)$existing['qty'] + $qty, $stock, API_CART_MAX_QTY);
        $cid = (int)$existing['id'];
        $u = $conn->prepare("UPDATE cart SET qty=?, selected=1 WHERE id=? AND user_id=?");
        $u->bind_param('iii', $newQty, $cid, $uid); $u->execute(); $u->close();
    } else {
        $qty = min($qty, $stock);
        $i = $conn->prepare("INSERT INTO cart (user_id, product_id, qty, selected) VALUES (?,?,?,1)");
        $i->bind_param('iii', $uid, $pid, $qty); $i->execute(); $i->close();
    }
    json_response(['success' => true, 'message' => 'เพิ่มลงตะกร้าแล้ว']);
}

// ══ UPDATE QTY ══
if ($action === 'update') {
    require_method('POST');
    $cartId = (int)($input['cart_id'] ?? 0);
    $qty    = min(API_CART_MAX_QTY, max(1, (int)($input['qty'] ?? 1)));
    if ($cartId <= 0) json_error('cart_id ไม่ถูกต้อง');

    // ต้องเป็นแถวของผู้ใช้คนนี้ และขอเกินสต็อกจริงไม่ได้
    $chk = $conn->prepare(
        "SELECT p.stock FROM cart c JOIN products p ON c.product_id=p.id
         WHERE c.id=? AND c.user_id=? LIMIT 1"
    );
    $chk->bind_param('ii', $cartId, $uid); $chk->execute();
    $row = $chk->get_result()->fetch_assoc(); $chk->close();
    if (!$row) json_error('ไม่พบสินค้าในตะกร้า', 404);

    $stock = (int)$row['stock'];
    if ($stock < 1) json_error('สินค้าหมดสต็อก', 409);

    $capped = min($qty, $stock);
    $u = $conn->prepare("UPDATE cart SET qty=? WHERE id=? AND user_id=?");
    $u->bind_param('iii', $capped, $cartId, $uid); $u->execute(); $u->close();
    json_response(['success' => true, 'qty' => $capped, 'stock' => $stock]);
}

// ══ REMOVE ══
if ($action === 'remove') {
    require_method('POST');
    $cartId = (int)($input['cart_id'] ?? 0);
    $d = $conn->prepare("DELETE FROM cart WHERE id=? AND user_id=?");
    $d->bind_param('ii', $cartId, $uid); $d->execute();
    $gone = $d->affected_rows; $d->close();
    if ($gone < 1) json_error('ไม่พบสินค้าในตะกร้า', 404);
    json_response(['success' => true, 'message' => 'ลบแล้ว']);
}

json_error('Unknown action', 404);
