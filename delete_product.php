<?php
require_once 'init.php';
require_login();
// ลบได้เฉพาะผ่านฟอร์ม POST ที่มี CSRF token เท่านั้น (กันลิงก์ GET หลอกให้กด)
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: dashboard.php'); exit; }
check_csrf();
$uid = (int)$_SESSION['user_id'];
$pid = (int)($_POST['product_id'] ?? 0);
if ($pid > 0) {
    // ลบได้เฉพาะสินค้าของตัวเอง (seller_id = ผู้ใช้ที่ล็อกอิน)
    $st = $conn->prepare("SELECT image FROM products WHERE id=? AND seller_id=? LIMIT 1");
    $st->bind_param('ii', $pid, $uid); $st->execute();
    $p = $st->get_result()->fetch_assoc(); $st->close();
    if ($p) {
        $del = $conn->prepare("DELETE FROM products WHERE id=? AND seller_id=?");
        $del->bind_param('ii', $pid, $uid); $del->execute(); $del->close();

        // เก็บกวาดข้อมูลที่อ้างถึงสินค้านี้ (ตารางไม่มี foreign key จึงไม่ลบตามให้อัตโนมัติ)
        // order_items ไม่ลบ เพราะเป็นประวัติการสั่งซื้อและมี snapshot ชื่อ/รูปเก็บไว้แล้ว
        foreach (['cart', 'wishlists', 'product_lending_info'] as $tbl) {
            $c = $conn->prepare("DELETE FROM `$tbl` WHERE product_id=?");
            $c->bind_param('i', $pid); $c->execute(); $c->close();
        }

        // ลบไฟล์รูปเฉพาะไฟล์ที่ผู้ขายอัปโหลดเอง (ชื่อสุ่ม) และไม่มีสินค้าอื่นใช้อยู่
        // รูปประจำหมวด cat-*.svg / รูปตัวอย่าง ใช้ร่วมกันหลายที่ ห้ามลบ
        $img = (string)$p['image'];
        if (preg_match('#^assets/uploads/[a-f0-9]{32}\.(jpg|jpeg|png|webp)$#', $img)) {
            $c = $conn->prepare("SELECT COUNT(*) FROM products WHERE image=?");
            $c->bind_param('s', $img); $c->execute();
            $inUse = (int)$c->get_result()->fetch_row()[0]; $c->close();
            $path = __DIR__ . '/' . $img;
            if ($inUse === 0 && is_file($path)) @unlink($path);
        }
    }
}
header('Location: dashboard.php'); exit;
