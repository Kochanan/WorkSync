<?php
require_once 'init.php';

// ปรับ Header ให้เซิร์ฟเวอร์ตอบกลับเป็น JSON Data เท่านั้น
header('Content-Type: application/json; charset=utf-8');

// บังคับว่าผู้ใช้ต้องผ่านการ Login ก่อนจัดการตะกร้าสินค้า
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized access. Please login first.']);
    exit;
}

$uid = (int)$_SESSION['user_id'];

// ทุก action แก้ข้อมูลตะกร้า จึงรับเฉพาะ POST และต้องมี CSRF Token เสมอ
// (ของเดิมตรวจ token เฉพาะตอนเป็น POST แต่รับ action จาก GET ด้วย)
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
    exit;
}
// อ่าน header จาก $_SERVER เพราะ getallheaders() คืนชื่อ header ตามตัวพิมพ์ที่ client ส่งมา
$token = $_POST['_csrf'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
if (!is_string($token) || !isset($_SESSION['_csrf']) || !hash_equals($_SESSION['_csrf'], $token)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid or missing CSRF token.']);
    exit;
}

$action = $_POST['action'] ?? '';

// 🛒 ACTION 1: การกดเพิ่มสินค้าลงในตะกร้า
if ($action === 'add') {
    $pid = (int)($_POST['product_id'] ?? 0);
    $qty = max(1, (int)($_POST['qty'] ?? 1));

    if ($pid <= 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid Product ID.']);
        exit;
    }

    // ตรวจสอบข้อมูลสินค้าในระบบว่ามีจริงและมีสต็อกเพียงพอหรือไม่
    $chk = $conn->prepare("SELECT id, stock, seller_id FROM products WHERE id = ? LIMIT 1");
    $chk->bind_param('i', $pid);
    $chk->execute();
    $p = $chk->get_result()->fetch_assoc();
    $chk->close();

    if (!$p) {
        echo json_encode(['success' => false, 'message' => 'Product not found.']);
        exit;
    }

    // ห้ามซื้อสินค้าของร้านตัวเอง
    if ((int)$p['seller_id'] === $uid) {
        echo json_encode(['success' => false, 'message' => 'ไม่สามารถสั่งซื้อสินค้าของร้านตัวเองได้']);
        exit;
    }

    $stock = (int)$p['stock'];
    if ($stock < 1) {
        echo json_encode(['success' => false, 'message' => 'สินค้าหมดสต็อก']);
        exit;
    }

    // ตรวจสอบว่าเคยมีสินค้านี้อยู่ในตะกร้าแล้วหรือยัง (Upsert)
    $st = $conn->prepare("SELECT id, qty FROM cart WHERE user_id = ? AND product_id = ? LIMIT 1");
    $st->bind_param('ii', $uid, $pid);
    $st->execute();
    $cartItem = $st->get_result()->fetch_assoc();
    $st->close();

    if ($cartItem) {
        $newQty = $cartItem['qty'] + $qty;
        // ป้องกันไม่ให้สั่งเกินสต็อกที่มีอยู่จริง
        if ($newQty > $stock) $newQty = $stock;

        $u = $conn->prepare("UPDATE cart SET qty = ?, selected = 1 WHERE id = ? AND user_id = ?");
        $u->bind_param('iii', $newQty, $cartItem['id'], $uid);
        $u->execute();
        $u->close();
    } else {
        if ($qty > $stock) $qty = $stock;

        $i = $conn->prepare("INSERT INTO cart (user_id, product_id, qty, selected) VALUES (?, ?, ?, 1)");
        $i->bind_param('iii', $uid, $pid, $qty);
        $i->execute();
        $i->close();
    }

    echo json_encode(['success' => true, 'message' => 'เพิ่มสินค้าเข้าตะกร้าเรียบร้อยแล้ว']);
    exit;
}

// 🗑️ ACTION 2: ลบรายการสินค้าออกจากตะกร้า
if ($action === 'delete') {
    $cartId = (int)($_POST['cart_id'] ?? 0);
    
    $del = $conn->prepare("DELETE FROM cart WHERE id = ? AND user_id = ?");
    $del->bind_param('ii', $cartId, $uid);
    $del->execute();
    $affected = $del->affected_rows;
    $del->close();

    echo json_encode(['success' => $affected > 0, 'message' => 'ลบรายการสำเร็จ']);
    exit;
}

// ส่งข้อความกลับกรณีเรียก Action ผิดพลาดหรือไม่ตรงเงื่อนไขใดๆ
echo json_encode(['success' => false, 'message' => 'Unknown action or endpoint.']);