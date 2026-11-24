<?php
if (session_status() === PHP_SESSION_NONE) {
    // คุกกี้ session: JavaScript อ่านไม่ได้ (กันขโมยผ่าน XSS),
    // ไม่ถูกส่งไปกับคำขอจากเว็บอื่น (ช่วยกัน CSRF), และไม่รับ session id ที่ server ไม่ได้ออกให้
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'httponly' => true,
        'samesite' => 'Lax',
        'secure'   => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
    ]);
    session_start();
}
require_once __DIR__ . '/config.php';

// ป้องกันช่องโหว่ Session Fixation / Hijacking (Best Practice)
if (!isset($_SESSION['_created'])) {
    $_SESSION['_created'] = time();
} elseif (time() - $_SESSION['_created'] > 1800) { // เปลี่ยน ID ทุกๆ 30 นาที
    session_regenerate_id(true);
    $_SESSION['_created'] = time();
}

/**
 * ยกเลิกออเดอร์ที่ค้าง "รอชำระเงิน" เกินกำหนด แล้วคืนสต็อก
 * ไม่งั้นคนสั่งซื้อสินค้าหมดสต็อกแล้วไม่จ่าย คนอื่นก็ซื้อไม่ได้ตลอดไป
 * ทำงานไม่เกิน 1 ครั้งต่อ 10 นาที (ไม่ต้องตั้ง cron)
 */
const PENDING_ORDER_HOURS = 24;

function expire_stale_orders(mysqli $conn): void {
    $stamp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'worksync_expire_orders.stamp';
    if (is_file($stamp) && time() - (int)@filemtime($stamp) < 600) {
        return;
    }
    @touch($stamp);

    $hours = PENDING_ORDER_HOURS;
    try {
        $st = $conn->prepare("SELECT id FROM orders WHERE status='pending'
                              AND created_at < NOW() - INTERVAL ? HOUR LIMIT 200");
        $st->bind_param('i', $hours);
        $st->execute();
        $ids = array_column($st->get_result()->fetch_all(MYSQLI_ASSOC), 'id');
        $st->close();

        foreach ($ids as $oid) {
            $oid = (int)$oid;
            $conn->begin_transaction();
            try {
                // ล็อกแถวและเช็คสถานะซ้ำ กันชนกับคนที่กำลังกดจ่ายเงินพอดี
                $ld = $conn->prepare("SELECT user_id FROM orders WHERE id=? AND status='pending' FOR UPDATE");
                $ld->bind_param('i', $oid);
                $ld->execute();
                $ord = $ld->get_result()->fetch_assoc();
                $ld->close();
                if (!$ord) { $conn->commit(); continue; }

                $it = $conn->prepare("SELECT product_id, qty FROM order_items WHERE order_id=?");
                $it->bind_param('i', $oid);
                $it->execute();
                $rows = $it->get_result()->fetch_all(MYSQLI_ASSOC);
                $it->close();

                $give = $conn->prepare("UPDATE products SET stock = stock + ? WHERE id = ?");
                foreach ($rows as $r) {
                    $q = (int)$r['qty']; $p = (int)$r['product_id'];
                    $give->bind_param('ii', $q, $p);
                    $give->execute();
                }
                $give->close();

                $u = $conn->prepare("UPDATE orders SET status='cancelled' WHERE id=? AND status='pending'");
                $u->bind_param('i', $oid);
                $u->execute();
                $u->close();

                $uid = (int)$ord['user_id'];
                $msg = "คำสั่งซื้อ #{$oid} ถูกยกเลิกอัตโนมัติ เนื่องจากไม่ได้ชำระเงินภายใน {$hours} ชั่วโมง";
                $n = $conn->prepare("INSERT INTO notifications (user_id, title, message, is_read, created_at)
                                     VALUES (?, 'ออเดอร์หมดเวลาชำระเงิน', ?, 0, NOW())");
                $n->bind_param('is', $uid, $msg);
                $n->execute();
                $n->close();

                $conn->commit();
            } catch (Throwable $e) {
                $conn->rollback();
                error_log("expire_stale_orders #$oid: " . $e->getMessage());
            }
        }
    } catch (Throwable $e) {
        error_log('expire_stale_orders: ' . $e->getMessage());
    }
}
expire_stale_orders($conn);

/**
 * สร้าง CSRF Token ประจำ Session
 */
function csrf_token() {
    if (empty($_SESSION['_csrf'])) {
        $_SESSION['_csrf'] = bin2hex(random_bytes(32)); // ขยายความยาว Token เพื่อความปลอดภัย
    }
    return $_SESSION['_csrf'];
}

/**
 * แสดงฟิลด์ Input Hidden สำหรับฟอร์ม HTML
 */
function csrf_tag() {
    return '<input type="hidden" name="_csrf" value="' . htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') . '">';
}

/**
 * ตรวจสอบความถูกต้องของ Token ป้องกัน CSRF Attack
 */
function check_csrf() {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $token = $_POST['_csrf'] ?? '';
        if (empty($token) || !hash_equals($_SESSION['_csrf'] ?? '', $token)) {
            http_response_code(400);
            die("Error 400: Invalid or Missing CSRF Token.");
        }
    }
}

/**
 * ตรวจสอบสิทธิ์การเข้าถึงหน้าบ้าน
 */
function require_login() {
    if (!isset($_SESSION['user_id'])) {
        header('Location: login.php');
        exit;
    }
}

/**
 * จัดการ Fallback ตรวจสอบเส้นทางรูปภาพสินค้า
 */
/** รูปสำรองเก็บไว้ในเครื่อง
 *  ของเดิมชี้ไปที่ via.placeholder.com ซึ่งปิดบริการไปแล้ว
 *  ทำให้สินค้าที่ไม่มีรูปขึ้นเป็นรูปแตกทุกตัว */
const NO_IMAGE = 'assets/no-image.svg';

function img_src($path) {
    if (!$path) {
        return NO_IMAGE;
    }
    if (preg_match('#^https?://#i', $path)) {
        return $path;
    }
    $local = __DIR__ . '/' . ltrim($path, '/');
    return file_exists($local) ? $path : NO_IMAGE;
}