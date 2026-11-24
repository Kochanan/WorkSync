<?php
/**
 * admin_order_update.php — แอดมินปรับสถานะคำสั่งซื้อ
 *
 * สต็อกถูกตัดตั้งแต่ตอนสร้างออเดอร์ (place_order.php)
 * ไฟล์นี้จึงต้องคืนสต็อกเมื่อยกเลิก และตัดกลับเมื่อปลดยกเลิก
 */

require_once 'init.php';
require_login();
check_csrf();

// บังคับจำกัดสิทธิ์ความปลอดภัยสูงสุดเฉพาะระดับ Admin เท่านั้น
if (($_SESSION['role'] ?? '') !== 'admin') {
    http_response_code(403);
    exit('Error 403: Forbidden access.');
}

$orderId = (int)($_POST['order_id'] ?? 0);
$status  = is_string($_POST['status'] ?? null) ? trim($_POST['status']) : '';
$notify  = (int)($_POST['notify'] ?? 0);

$allowed = ['pending', 'paid', 'packing', 'shipping', 'delivered', 'cancelled'];

if ($orderId <= 0 || !in_array($status, $allowed, true)) {
    header('Location: admin_orders.php');
    exit;
}

$conn->begin_transaction();

try {
    // ล็อกแถวออเดอร์ กันแอดมินสองคนกดพร้อมกันแล้วสต็อกเพี้ยน
    $st = $conn->prepare("SELECT id, user_id, status FROM orders WHERE id = ? LIMIT 1 FOR UPDATE");
    $st->bind_param('i', $orderId);
    $st->execute();
    $ord = $st->get_result()->fetch_assoc();
    $st->close();

    if (!$ord) {
        throw new RuntimeException('notfound');
    }

    $oldStatus = $ord['status'];

    if ($oldStatus !== $status) {
        $wasCancelled = ($oldStatus === 'cancelled');
        $nowCancelled = ($status === 'cancelled');

        if ($wasCancelled !== $nowCancelled) {
            // ดึงรายการสินค้าในออเดอร์นี้มาปรับสต็อก
            $it = $conn->prepare("SELECT product_id, qty FROM order_items WHERE order_id = ?");
            $it->bind_param('i', $orderId);
            $it->execute();
            $items = $it->get_result()->fetch_all(MYSQLI_ASSOC);
            $it->close();

            if ($nowCancelled) {
                // ยกเลิกออเดอร์ → คืนของกลับเข้าคลัง
                $give = $conn->prepare("UPDATE products SET stock = stock + ? WHERE id = ?");
                foreach ($items as $row) {
                    $q = (int)$row['qty'];
                    $p = (int)$row['product_id'];
                    $give->bind_param('ii', $q, $p);
                    $give->execute();
                }
                $give->close();
            } else {
                // ปลดยกเลิก → ต้องตัดสต็อกกลับ ถ้าของไม่พอถือว่าทำไม่ได้
                $take = $conn->prepare("UPDATE products SET stock = stock - ? WHERE id = ? AND stock >= ?");
                foreach ($items as $row) {
                    $q = (int)$row['qty'];
                    $p = (int)$row['product_id'];
                    $take->bind_param('iii', $q, $p, $q);
                    $take->execute();
                    if ($take->affected_rows !== 1) {
                        $take->close();
                        throw new RuntimeException('nostock');
                    }
                }
                $take->close();
            }
        }

        // อัปเดตสถานะใหม่ลงในตารางหลักคำสั่งซื้อ
        // บันทึกเวลาชำระเงินถ้ายังไม่มี (Trust Score ใช้ paid_at คำนวณ) — ไม่ทับค่าเดิม
        $upd = $conn->prepare("UPDATE orders SET status = ?,
                                   paid_at = IF(? IN ('paid','packing','shipping','delivered'), COALESCE(paid_at, NOW()), paid_at),
                                   delivered_at = IF(? = 'delivered', COALESCE(delivered_at, NOW()), NULL)
                                WHERE id = ?");
        $upd->bind_param('sssi', $status, $status, $status, $orderId);
        $upd->execute();
        $upd->close();
    }

    // หากติ๊กเลือกให้ส่งระบบแจ้งเตือนภายในแอปพลิเคชันไปยังลูกค้า (In-app Notification Simulation)
    if ($notify === 1) {
        $targetUserId = (int)$ord['user_id'];
        $msg = "คำสั่งซื้อออเดอร์หมายเลข #{$orderId} ของคุณได้รับการปรับปรุงสถานะเป็น: " . strtoupper($status);

        $ins = $conn->prepare("INSERT INTO notifications (user_id, title, message, is_read, created_at) VALUES (?, 'อัปเดตสถานะออเดอร์', ?, 0, NOW())");
        $ins->bind_param('is', $targetUserId, $msg);
        $ins->execute();
        $ins->close();
    }

    $conn->commit();
    header('Location: admin_orders.php?ok=1');
    exit;

} catch (Throwable $e) {
    $conn->rollback();

    $code = ($e instanceof RuntimeException) ? $e->getMessage() : 'failed';
    if ($code === 'failed') {
        error_log('admin_order_update failed: ' . $e->getMessage());
    }

    header('Location: admin_orders.php?err=' . urlencode($code));
    exit;
}
