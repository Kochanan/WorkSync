<?php
/**
 * place_order.php — สร้างคำสั่งซื้อจากตะกร้า
 *
 * กฎสำคัญ: ราคา ค่าจัดส่ง และจำนวนสินค้า ต้องคำนวณจากฝั่งเซิร์ฟเวอร์เสมอ
 * ห้ามเชื่อค่าที่ส่งมาจากฟอร์ม เพราะผู้ใช้แก้ค่าใน request ได้
 */

require_once 'init.php';
require_login();
check_csrf();

$uid = (int)$_SESSION['user_id'];

$selected  = $_POST['selected'] ?? [];
$qtyMap    = $_POST['qty'] ?? [];
$addressId = (int)($_POST['address_id'] ?? 0);

// ต้องเป็น array เท่านั้น ถ้าส่งเป็นสตริงมา array_map จะโยน TypeError ก่อนเข้า try
if (!is_array($selected)) $selected = [];
if (!is_array($qtyMap))   $qtyMap = [];

if (empty($selected) || $addressId <= 0) {
    header('Location: cart.php?err=input');
    exit;
}

$selected = array_map('intval', $selected);
$selected = array_values(array_unique(array_filter($selected, fn($v) => $v > 0)));
if (!$selected) {
    header('Location: cart.php?err=input');
    exit;
}

$place  = implode(',', array_fill(0, count($selected), '?'));
$types  = str_repeat('i', count($selected) + 1);
$params = array_merge([$uid], $selected);

$conn->begin_transaction();

try {
    /* ── 1. ที่อยู่ต้องเป็นของผู้ใช้คนนี้จริง ──────────────────── */
    $addrChk = $conn->prepare(
        "SELECT id FROM user_addresses WHERE id = ? AND user_id = ? LIMIT 1"
    );
    $addrChk->bind_param('ii', $addressId, $uid);
    $addrChk->execute();
    $addrOk = $addrChk->get_result()->fetch_assoc();
    $addrChk->close();

    if (!$addrOk) {
        throw new RuntimeException('address');
    }

    /* ── 2. ดึงรายการในตะกร้า พร้อมล็อกแถวสินค้ากันแย่งสต็อก ──── */
    $st = $conn->prepare(
        "SELECT c.id cart_id, c.product_id, c.qty, p.title, p.price, p.image, p.stock, p.seller_id
         FROM cart c
         JOIN products p ON c.product_id = p.id
         WHERE c.user_id = ? AND c.id IN ($place)
         FOR UPDATE"
    );
    $st->bind_param($types, ...$params);
    $st->execute();
    $rows = $st->get_result()->fetch_all(MYSQLI_ASSOC);
    $st->close();

    if (!$rows) {
        throw new RuntimeException('empty');
    }

    /* ── 3. คิดจำนวนและยอดรวมจากฝั่งเซิร์ฟเวอร์ ────────────────── */
    $subtotal = 0;
    $lines    = [];

    foreach ($rows as $r) {
        $cid   = (int)$r['cart_id'];
        $stock = (int)$r['stock'];

        // ห้ามซื้อสินค้าของร้านตัวเอง (กันปั๊มยอดขาย / Trust Score)
        if ((int)$r['seller_id'] === $uid) {
            throw new RuntimeException('own');
        }

        if ($stock < 1) {
            throw new RuntimeException('outofstock');
        }

        // จำนวนที่ส่งมาจากฟอร์มเป็นแค่คำขอ ต้องไม่เกินสต็อกจริง
        $want = isset($qtyMap[$cid]) ? (int)$qtyMap[$cid] : (int)$r['qty'];
        $qty  = max(1, min($want, $stock));

        $price     = (float)$r['price'];
        $subtotal += $price * $qty;

        $lines[] = [
            'cart_id'    => $cid,
            'product_id' => (int)$r['product_id'],
            'qty'        => $qty,
            'price'      => $price,
            'title'      => $r['title'],
            'image'      => $r['image'],
        ];
    }

    // ค่าจัดส่งคิดจากกฎของระบบ ไม่ใช่ค่าที่ส่งมากับฟอร์ม
    $shipFee = ($subtotal >= 999) ? 0.0 : 40.0;
    $total   = $subtotal + $shipFee;

    /* ── 4. สร้างออเดอร์ ───────────────────────────────────────── */
    $ins = $conn->prepare(
        "INSERT INTO orders (user_id, total, status, payment_method, address_id, shipping_fee)
         VALUES (?, ?, 'pending', 'simulator', ?, ?)"
    );
    $ins->bind_param('idid', $uid, $total, $addressId, $shipFee);
    $ins->execute();
    $orderId = (int)$ins->insert_id;
    $ins->close();

    /* ── 5. บันทึกรายการสินค้า + ตัดสต็อก ──────────────────────── */
    $item = $conn->prepare(
        "INSERT INTO order_items (order_id, product_id, qty, price, title_snapshot, image_snapshot)
         VALUES (?, ?, ?, ?, ?, ?)"
    );
    $cut = $conn->prepare(
        "UPDATE products SET stock = stock - ? WHERE id = ? AND stock >= ?"
    );

    foreach ($lines as $l) {
        $item->bind_param(
            'iiidss',
            $orderId, $l['product_id'], $l['qty'], $l['price'], $l['title'], $l['image']
        );
        $item->execute();

        $cut->bind_param('iii', $l['qty'], $l['product_id'], $l['qty']);
        $cut->execute();

        // ถ้าไม่มีแถวไหนถูกแก้ แปลว่ามีคนตัดสต็อกไปก่อนหน้าเรา
        if ($cut->affected_rows !== 1) {
            throw new RuntimeException('outofstock');
        }
    }
    $item->close();
    $cut->close();

    /* ── 6. ล้างสินค้าที่สั่งแล้วออกจากตะกร้า ──────────────────── */
    $del = $conn->prepare("DELETE FROM cart WHERE user_id = ? AND id IN ($place)");
    $del->bind_param($types, ...$params);
    $del->execute();
    $del->close();

    $conn->commit();

    header('Location: order_detail.php?id=' . $orderId);
    exit;

} catch (Throwable $e) {
    $conn->rollback();

    $code = ($e instanceof RuntimeException) ? $e->getMessage() : 'failed';
    // mysqli_sql_exception เป็นลูกของ RuntimeException ข้อความ error ของ DB จะหลุดไปใน URL ได้ จึงรับเฉพาะรหัสที่รู้จัก
    if (!in_array($code, ['address', 'empty', 'outofstock', 'own'], true)) {
        $code = 'failed';
    }
    if ($code === 'failed') {
        error_log('place_order failed: ' . $e->getMessage());
    }

    header('Location: cart.php?err=' . urlencode($code));
    exit;
}
