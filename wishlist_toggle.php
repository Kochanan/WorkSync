<?php
require_once 'init.php';
header('Content-Type: application/json');
if (!isset($_SESSION['user_id'])) { echo json_encode(['ok'=>false,'msg'=>'กรุณาเข้าสู่ระบบ']); exit; }
// เปลี่ยนข้อมูลได้เฉพาะ POST + CSRF เท่านั้น (ของเดิมข้ามการตรวจ token ถ้าไม่ใช่ POST)
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['ok'=>false]); exit; }
// อ่าน header จาก $_SERVER เพราะ getallheaders() ขึ้นกับตัวพิมพ์ที่ client ส่งมา
$token = $_POST['_csrf'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
if (!is_string($token) || !hash_equals($_SESSION['_csrf'] ?? '', $token)) { http_response_code(400); echo json_encode(['ok'=>false]); exit; }
$uid = (int)$_SESSION['user_id'];
$pid = (int)($_POST['product_id'] ?? 0);
if ($pid <= 0) { echo json_encode(['ok'=>false]); exit; }
// ต้องเป็นสินค้าที่มีอยู่จริง กันแถวขยะใน wishlists
$pc = $conn->prepare("SELECT id FROM products WHERE id=? LIMIT 1");
$pc->bind_param('i', $pid); $pc->execute();
$prodOk = $pc->get_result()->fetch_assoc(); $pc->close();
if (!$prodOk) { echo json_encode(['ok'=>false]); exit; }
$chk = $conn->prepare("SELECT id FROM wishlists WHERE user_id=? AND product_id=? LIMIT 1");
$chk->bind_param('ii', $uid, $pid); $chk->execute();
$exists = $chk->get_result()->fetch_assoc(); $chk->close();
if ($exists) {
    $del = $conn->prepare("DELETE FROM wishlists WHERE user_id=? AND product_id=?");
    $del->bind_param('ii', $uid, $pid); $del->execute(); $del->close();
    echo json_encode(['ok'=>true,'wishlisted'=>false]);
} else {
    // INSERT IGNORE: กดซ้ำพร้อมกันสองครั้งจะไม่ชน UNIQUE key จนเกิด exception
    $ins = $conn->prepare("INSERT IGNORE INTO wishlists (user_id, product_id) VALUES (?,?)");
    $ins->bind_param('ii', $uid, $pid); $ins->execute(); $ins->close();
    echo json_encode(['ok'=>true,'wishlisted'=>true]);
}
