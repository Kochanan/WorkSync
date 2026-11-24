<?php
require_once __DIR__ . '/_bootstrap.php';
$user = authenticate($conn);
$uid  = (int)$user['user_id'];
$action = $_GET['action'] ?? 'list';
$input  = get_json_input();

// ข้อความที่ตั้งใจให้ผู้ใช้เห็น แยกจาก RuntimeException ทั่วไป
// (mysqli_sql_exception ก็เป็น RuntimeException ถ้าใช้ตัวเดียวกัน error ของ SQL จะหลุดถึง client)
class OrderUserError extends Exception {}

// ══ LIST ══
if ($action === 'list') {
    require_method('GET');
    $st = $conn->prepare("SELECT * FROM orders WHERE user_id=? ORDER BY created_at DESC");
    $st->bind_param('i', $uid); $st->execute();
    $orders = $st->get_result()->fetch_all(MYSQLI_ASSOC);
    $st->close();
    foreach ($orders as &$o) {
        $o['id']=(int)$o['id']; $o['total']=(float)$o['total'];
    }
    unset($o);
    json_response(['success' => true, 'orders' => $orders]);
}

// ══ DETAIL ══
if ($action === 'detail') {
    require_method('GET');
    $oid = (int)($_GET['id'] ?? 0);
    $st = $conn->prepare("SELECT * FROM orders WHERE id=? AND user_id=? LIMIT 1");
    $st->bind_param('ii', $oid, $uid); $st->execute();
    $order = $st->get_result()->fetch_assoc(); $st->close();
    if (!$order) json_error('ไม่พบออเดอร์', 404);

    $it = $conn->prepare("SELECT * FROM order_items WHERE order_id=?");
    $it->bind_param('i', $oid); $it->execute();
    $items = $it->get_result()->fetch_all(MYSQLI_ASSOC); $it->close();
    foreach ($items as &$x) $x['image_url'] = api_img_url($x['image_snapshot']);
    unset($x);

    json_response(['success' => true, 'order' => $order, 'items' => $items]);
}

// ══ CREATE (checkout) ══
// ยอดเงิน ค่าจัดส่ง และจำนวน คิดจากฝั่งเซิร์ฟเวอร์ทั้งหมด ไม่เชื่อค่าที่แอปส่งมา
if ($action === 'create') {
    require_method('POST');
    $addressId = (int)($input['address_id'] ?? 0);
    $selected  = $input['cart_ids'] ?? [];
    // cart_ids ต้องเป็น array ของตัวเลข (เดิมส่ง string มาแล้วเกิด TypeError พร้อม path เครื่องหลุดออกไป)
    if (!is_array($selected) || empty($selected) || $addressId <= 0) json_error('ข้อมูลไม่ครบ');
    if (count($selected) > 100) json_error('เลือกสินค้าได้ไม่เกิน 100 รายการต่อออเดอร์');

    $selected = array_map(fn($v) => is_scalar($v) ? (int)$v : 0, $selected);
    $selected = array_values(array_unique(array_filter($selected, fn($v) => $v > 0)));
    if (!$selected) json_error('ข้อมูลไม่ครบ');

    $place  = implode(',', array_fill(0, count($selected), '?'));
    $types  = str_repeat('i', count($selected) + 1);
    $params = array_merge([$uid], $selected);

    $conn->begin_transaction();

    try {
        // ที่อยู่ต้องเป็นของผู้ใช้คนนี้จริง
        $addrChk = $conn->prepare("SELECT id FROM user_addresses WHERE id=? AND user_id=? LIMIT 1");
        $addrChk->bind_param('ii', $addressId, $uid);
        $addrChk->execute();
        $addrOk = $addrChk->get_result()->fetch_assoc();
        $addrChk->close();
        if (!$addrOk) throw new OrderUserError('ไม่พบที่อยู่จัดส่งนี้ในบัญชีของคุณ');

        // ล็อกแถวสินค้ากันแย่งสต็อก
        $sql = "SELECT c.id cart_id, c.product_id, c.qty, p.title, p.price, p.image, p.stock
                FROM cart c JOIN products p ON c.product_id=p.id
                WHERE c.user_id=? AND c.id IN ($place)
                FOR UPDATE";
        $st = $conn->prepare($sql);
        $st->bind_param($types, ...$params); $st->execute();
        $rows = $st->get_result()->fetch_all(MYSQLI_ASSOC); $st->close();
        if (!$rows) throw new OrderUserError('ไม่พบสินค้าในตะกร้า');

        $subtotal = 0;
        $lines = [];
        foreach ($rows as $r) {
            $stock = (int)$r['stock'];
            if ($stock < 1) throw new OrderUserError('สินค้า "' . $r['title'] . '" หมดสต็อก');

            $qty = max(1, min((int)$r['qty'], $stock));
            $price = (float)$r['price'];
            $subtotal += $price * $qty;

            $lines[] = [
                'product_id' => (int)$r['product_id'],
                'qty'        => $qty,
                'price'      => $price,
                'title'      => $r['title'],
                'image'      => $r['image'],
            ];
        }

        // กฎค่าส่งเดียวกับฝั่งเว็บ ซื้อครบ 999 ส่งฟรี
        $shipFee = ($subtotal >= 999) ? 0.0 : 40.0;
        $total   = $subtotal + $shipFee;

        $ins = $conn->prepare("INSERT INTO orders (user_id,total,status,payment_method,address_id,shipping_fee) VALUES (?,?,'pending','simulator',?,?)");
        $ins->bind_param('idid', $uid, $total, $addressId, $shipFee);
        $ins->execute();
        $orderId = (int)$ins->insert_id; $ins->close();

        $item = $conn->prepare("INSERT INTO order_items (order_id,product_id,qty,price,title_snapshot,image_snapshot) VALUES (?,?,?,?,?,?)");
        $cut  = $conn->prepare("UPDATE products SET stock = stock - ? WHERE id = ? AND stock >= ?");
        foreach ($lines as $l) {
            $item->bind_param('iiidss', $orderId, $l['product_id'], $l['qty'], $l['price'], $l['title'], $l['image']);
            $item->execute();

            $cut->bind_param('iii', $l['qty'], $l['product_id'], $l['qty']);
            $cut->execute();
            if ($cut->affected_rows !== 1) throw new OrderUserError('สินค้าบางรายการเพิ่งหมดสต็อก');
        }
        $item->close();
        $cut->close();

        // ลบออกจากตะกร้า
        $del = $conn->prepare("DELETE FROM cart WHERE user_id=? AND id IN ($place)");
        $del->bind_param($types, ...$params); $del->execute(); $del->close();

        $conn->commit();
        json_response([
            'success'  => true,
            'order_id' => $orderId,
            'subtotal' => $subtotal,
            'ship_fee' => $shipFee,
            'total'    => $total,
        ]);

    } catch (Throwable $e) {
        $conn->rollback();
        if ($e instanceof OrderUserError) json_error($e->getMessage(), 409);
        error_log('api order create failed: ' . $e->getMessage());
        json_error('ระบบขัดข้องระหว่างสร้างคำสั่งซื้อ', 500);
    }
}

json_error('Unknown action', 404);
