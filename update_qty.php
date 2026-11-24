<?php
/**
 * update_qty.php — แก้จำนวน / ติ๊กเลือกสินค้าในตะกร้า (เรียกจาก cart.php ด้วย fetch)
 */

require_once 'init.php';
require_login();
check_csrf();

header('Content-Type: application/json; charset=utf-8');

$action = $_POST['action'] ?? '';
$cartId = (int)($_POST['cart_id'] ?? 0);
$uid    = (int)$_SESSION['user_id'];

if ($cartId <= 0) {
    echo json_encode(['ok' => false, 'message' => 'cart_id ไม่ถูกต้อง']);
    exit;
}

if ($action === 'qty') {
    $qty = max(1, (int)($_POST['qty'] ?? 1));

    // ต้องเป็นแถวของผู้ใช้คนนี้ และดูสต็อกจริงของสินค้าประกอบ
    $chk = $conn->prepare(
        "SELECT p.stock
         FROM cart c JOIN products p ON c.product_id = p.id
         WHERE c.id = ? AND c.user_id = ? LIMIT 1"
    );
    $chk->bind_param('ii', $cartId, $uid);
    $chk->execute();
    $row = $chk->get_result()->fetch_assoc();
    $chk->close();

    if (!$row) {
        echo json_encode(['ok' => false, 'message' => 'ไม่พบสินค้าในตะกร้า']);
        exit;
    }

    $stock = (int)$row['stock'];
    if ($stock < 1) {
        echo json_encode(['ok' => false, 'message' => 'สินค้าหมดสต็อก', 'stock' => 0]);
        exit;
    }

    // ขอเกินของที่มีไม่ได้ ตัดให้เท่าสต็อกแล้วบอกกลับไปว่าเหลือเท่าไร
    $capped = min($qty, $stock);

    $st = $conn->prepare("UPDATE cart SET qty = ? WHERE id = ? AND user_id = ?");
    $st->bind_param('iii', $capped, $cartId, $uid);
    $st->execute();
    $st->close();

    echo json_encode(['ok' => true, 'qty' => $capped, 'stock' => $stock]);
    exit;
}

if ($action === 'select') {
    $sel = (int)($_POST['selected'] ?? 0) === 1 ? 1 : 0;

    $st = $conn->prepare("UPDATE cart SET selected = ? WHERE id = ? AND user_id = ?");
    $st->bind_param('iii', $sel, $cartId, $uid);
    $st->execute();
    $st->close();

    echo json_encode(['ok' => true, 'selected' => $sel]);
    exit;
}

echo json_encode(['ok' => false, 'message' => 'ไม่รู้จักคำสั่งนี้']);
