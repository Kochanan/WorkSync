<?php
require_once 'init.php';
require_login();
check_csrf();

$pid = (int)($_POST['product_id'] ?? 0);
$qty = max(1, (int)($_POST['qty'] ?? 1));
$uid = (int)$_SESSION['user_id'];

if ($pid <= 0) {
    header('Location: index.php');
    exit;
}

// ตรวจสอบว่าสินค้ามีอยู่จริงและ Stock พอหรือไม่ (Data Logic Protection)
$pCheck = $conn->prepare("SELECT stock, seller_id FROM products WHERE id = ? LIMIT 1");
$pCheck->bind_param('i', $pid);
$pCheck->execute();
$product = $pCheck->get_result()->fetch_assoc();
$pCheck->close();

if (!$product) {
    header('Location: index.php');
    exit;
}

// ห้ามซื้อสินค้าของร้านตัวเอง (กันปั๊มยอดขาย / คะแนนร้าน)
if ((int)$product['seller_id'] === $uid) {
    header('Location: cart.php?err=own');
    exit;
}

$stock = (int)$product['stock'];

// ค้นหาว่ามีไอเท็มนี้ในตะกร้าอยู่ก่อนแล้วหรือยัง (Upsert operation)
$st = $conn->prepare("SELECT id, qty FROM cart WHERE user_id = ? AND product_id = ? LIMIT 1");
$st->bind_param('ii', $uid, $pid);
$st->execute();
$r = $st->get_result()->fetch_assoc();
$st->close();

if ($r) {
    $newQty = $r['qty'] + $qty;
    // ป้องกันกรณีที่ผู้ใช้งานป้อนจำนวนเกินสินค้าที่ผู้ขายมีจริงในระบบคลัง
    // สินค้าหมดสต็อกให้คงจำนวนเดิมไว้ (อย่างน้อย 1) ไม่ปล่อยให้แถวในตะกร้ากลายเป็น qty = 0
    $newQty = max(1, min($newQty, $stock));

    $u = $conn->prepare("UPDATE cart SET qty = ?, selected = 1 WHERE id = ? AND user_id = ?");
    $u->bind_param('iii', $newQty, $r['id'], $uid);
    $u->execute();
    $u->close();
} else {
    if ($qty > $stock) {
        $qty = $stock;
    }
    if ($qty > 0) {
        $i = $conn->prepare("INSERT INTO cart (user_id, product_id, qty, selected) VALUES (?, ?, ?, 1)");
        $i->bind_param('iii', $uid, $pid, $qty);
        $i->execute();
        $i->close();
    }
}

header('Location: cart.php');
exit;