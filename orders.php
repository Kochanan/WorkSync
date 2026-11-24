<?php
require_once 'init.php';
require_login();

$uid = (int)$_SESSION['user_id'];

// ผู้ซื้อทำได้แค่ 2 อย่าง: ยกเลิกออเดอร์ที่ยังไม่จ่าย และยืนยันรับของเมื่อสถานะเป็น shipping
// ของเดิมให้ผู้ซื้อเลือกสถานะอะไรก็ได้ (ตั้งเป็น paid โดยไม่จ่ายเงิน, ตั้ง delivered เพื่อปั๊ม Trust Score,
// ปลุกออเดอร์ที่ยกเลิกแล้ว) และยกเลิกแล้วไม่คืนสต็อก
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $oid    = (int)($_POST['order_id'] ?? 0);
    $action = $_POST['action'] ?? '';
    $msg    = 'invalid';

    if ($oid > 0 && in_array($action, ['cancel', 'received'], true)) {
        $conn->begin_transaction();
        try {
            // ล็อกแถวออเดอร์ กันกดยกเลิกพร้อมกับกดจ่ายเงิน / กดยกเลิกซ้ำจนคืนสต็อกสองรอบ
            $ld = $conn->prepare("SELECT id, status FROM orders WHERE id=? AND user_id=? LIMIT 1 FOR UPDATE");
            $ld->bind_param('ii', $oid, $uid);
            $ld->execute();
            $ord = $ld->get_result()->fetch_assoc();
            $ld->close();

            if ($ord && $action === 'cancel' && $ord['status'] === 'pending') {
                // คืนสต็อกที่ถูกตัดไปตอน place_order
                $it = $conn->prepare("SELECT product_id, qty FROM order_items WHERE order_id=?");
                $it->bind_param('i', $oid);
                $it->execute();
                $rows = $it->get_result()->fetch_all(MYSQLI_ASSOC);
                $it->close();

                $give = $conn->prepare("UPDATE products SET stock = stock + ? WHERE id = ?");
                foreach ($rows as $r) {
                    $q = (int)$r['qty'];
                    $p = (int)$r['product_id'];
                    $give->bind_param('ii', $q, $p);
                    $give->execute();
                }
                $give->close();

                $u = $conn->prepare("UPDATE orders SET status='cancelled' WHERE id=? AND user_id=? AND status='pending'");
                $u->bind_param('ii', $oid, $uid);
                $u->execute();
                $u->close();
                $msg = 'cancelled';
            } elseif ($ord && $action === 'received' && $ord['status'] === 'shipping') {
                $u = $conn->prepare("UPDATE orders SET status='delivered', delivered_at=NOW() WHERE id=? AND user_id=? AND status='shipping'");
                $u->bind_param('ii', $oid, $uid);
                $u->execute();
                $u->close();
                $msg = 'received';
            }
            $conn->commit();
        } catch (Throwable $e) {
            $conn->rollback();
            error_log('orders.php update failed: ' . $e->getMessage());
            $msg = 'failed';
        }
    }
    header('Location: orders.php?msg=' . $msg);
    exit;
}

$flashMap = [
    'cancelled' => 'ยกเลิกคำสั่งซื้อและคืนสต็อกเรียบร้อยแล้ว',
    'received'  => 'ยืนยันการรับสินค้าเรียบร้อยแล้ว',
    'invalid'   => 'ไม่สามารถทำรายการนี้กับคำสั่งซื้อในสถานะปัจจุบันได้',
    'failed'    => 'ระบบขัดข้อง กรุณาลองใหม่',
];
$flash = $flashMap[is_string($_GET['msg'] ?? null) ? $_GET['msg'] : ''] ?? '';

$st = $conn->prepare("SELECT * FROM orders WHERE user_id=? ORDER BY created_at DESC");
$st->bind_param('i', $uid);
$st->execute();
$orders = $st->get_result()->fetch_all(MYSQLI_ASSOC);
$st->close();
?>
<!doctype html>
<html lang="th">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <link rel="stylesheet" href="assets/style.css?v=<?= filemtime(__DIR__.'/assets/style.css') ?>">
    <title>Orders — ออเดอร์ของฉัน</title>
</head>
<body>
<?php include 'navbar.php'; ?>
<div class="container">
    <div class="box">
        <h2>ออเดอร์ของฉัน</h2>
        <?php if ($flash !== ''): ?>
            <div class="small" style="background:#f7f8fb; border:1px solid #eee; padding:10px 14px; border-radius:8px; margin:10px 0; font-weight:700;"><?= htmlspecialchars($flash, ENT_QUOTES, 'UTF-8') ?></div>
        <?php endif; ?>
        <?php if (empty($orders)): ?>
            <p class="small">ยังไม่มีออเดอร์</p>
        <?php endif; ?>

        <?php foreach ($orders as $o): ?>
            <div class="box" style="margin-top:12px; border: 1px solid #eee;">
                <div style="display:flex;justify-content:space-between;gap:10px;flex-wrap:wrap">
                    <div>
                        <b>Order #<?= (int)$o['id'] ?></b> 
                        <span class="small">(<?= htmlspecialchars($o['created_at']) ?>)</span>
                    </div>
                    <div><b>฿<?= number_format((float)$o['total'], 2) ?></b></div>
                </div>
                <div class="small" style="margin-top:6px">
                    สถานะปัจจุบัน: <b style="text-transform: capitalize;"><?= htmlspecialchars($o['status']) ?></b>
                </div>

                <div style="margin-top:10px;display:flex;gap:10px;align-items:center;flex-wrap:wrap">
                    <a href="order_detail.php?id=<?= (int)$o['id'] ?>" class="btn" style="text-decoration:none; background:#555;">ดูรายละเอียด</a>
                    <?php if ($o['status'] === 'pending'): ?>
                        <a href="payment_simulator.php?order_id=<?= (int)$o['id'] ?>" class="btn" style="text-decoration:none; background:#4caf50;">ชำระเงิน</a>
                        <form method="post" style="margin:0" onsubmit="return confirm('ยืนยันยกเลิกคำสั่งซื้อนี้?')">
                            <?= csrf_tag() ?>
                            <input type="hidden" name="order_id" value="<?= (int)$o['id'] ?>">
                            <input type="hidden" name="action" value="cancel">
                            <button type="submit" class="btn" style="background:#b00020;">ยกเลิกคำสั่งซื้อ</button>
                        </form>
                    <?php elseif ($o['status'] === 'shipping'): ?>
                        <form method="post" style="margin:0">
                            <?= csrf_tag() ?>
                            <input type="hidden" name="order_id" value="<?= (int)$o['id'] ?>">
                            <input type="hidden" name="action" value="received">
                            <button type="submit" class="btn">ยืนยันได้รับสินค้าแล้ว</button>
                        </form>
                    <?php endif; ?>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
</div>
</body>
</html>