<?php
require_once 'init.php';
require_login();

// ตรวจสอบความปลอดภัยคัดกรองเฉพาะสิทธิ์ผู้ดูแลระบบ (Role Enforcement)
if (($_SESSION['role'] ?? '') !== 'admin') { 
    http_response_code(403); 
    exit('Error 403: Forbidden access.'); 
}

$q = trim(is_string($_GET['q'] ?? null) ? $_GET['q'] : '');
$sql = "SELECT o.id, o.user_id, o.total, o.status, o.created_at, u.username
        FROM orders o
        JOIN users u ON u.id = o.user_id
        WHERE 1";
$params = [];
$types = '';

if ($q !== '') {
    $sql .= " AND (u.username LIKE ? OR o.id = ?)";
    $params[] = '%' . addcslashes($q, '%_\\') . '%'; 
    $types .= 's';
    $params[] = (int)$q; 
    $types .= 'i';
}
$sql .= " ORDER BY o.id DESC LIMIT 200";

$st = $conn->prepare($sql);
if ($types !== '') {
    // ใช้ Argument Unpacking (...$params) ปลอดภัยและรองรับ PHP เวอร์ชันปัจจุบันอย่างสมบูรณ์
    $st->bind_param($types, ...$params);
}
$st->execute();
$orders = $st->get_result()->fetch_all(MYSQLI_ASSOC);
$st->close();
?>
<!doctype html>
<html lang="th">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>WorkSync Admin — การจัดการคำสั่งซื้อทั้งหมด</title>
  <link rel="stylesheet" href="assets/style.css?v=<?= filemtime(__DIR__.'/assets/style.css') ?>">
</head>
<body>

<?php include 'navbar.php'; ?>

<div class="container" style="margin-top:20px;">
  <div class="box" style="padding:25px;">
    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:20px; flex-wrap:wrap; gap:15px;">
      <h2>🛠️ ระบบจัดการข้อมูลคำสั่งซื้อ (ผู้ดูแลระบบ)</h2>
      
      <form method="GET" style="display:flex; gap:8px; margin:0;">
        <input class="input" name="q" value="<?= htmlspecialchars($q, ENT_QUOTES, 'UTF-8') ?>" placeholder="ค้นหาด้วยชื่อผู้ใช้ หรือ เลขออเดอร์..." style="width:260px; padding:6px 12px;">
        <button class="btn" type="submit" style="padding:6px 15px;">ค้นหา</button>
        <?php if($q !== ''): ?>
            <a href="admin_orders.php" class="btn" style="background:#777; text-decoration:none; padding:6px 12px; font-size:14px;">ล้างค่า</a>
        <?php endif; ?>
      </form>
    </div>

    <?php
    // แสดงผลลัพธ์จาก admin_order_update.php (เดิมส่ง ?ok / ?err กลับมาแต่ไม่เคยแสดง)
    $flashErr = ['nostock' => 'ปลดยกเลิกไม่ได้ สต็อกสินค้าบางรายการไม่พอ', 'notfound' => 'ไม่พบคำสั่งซื้อนี้', 'failed' => 'บันทึกไม่สำเร็จ กรุณาลองใหม่'];
    $errKey = is_string($_GET['err'] ?? null) ? $_GET['err'] : '';
    ?>
    <?php if (isset($_GET['ok'])): ?>
      <div style="background:#eaf6f0;color:#0f9b5e;padding:10px 14px;border-radius:8px;margin-bottom:12px;font-weight:600;">✓ อัปเดตสถานะเรียบร้อย</div>
    <?php elseif ($errKey !== ''): ?>
      <div style="background:#fdf0f2;color:#c8102e;padding:10px 14px;border-radius:8px;margin-bottom:12px;font-weight:600;">✕ <?= htmlspecialchars($flashErr[$errKey] ?? $flashErr['failed'], ENT_QUOTES, 'UTF-8') ?></div>
    <?php endif; ?>

    <div style="overflow-x:auto;">
      <table class="table" style="width:100%; border-collapse:collapse; margin-top:10px;">
        <thead>
          <tr style="background:#f5f5f5; text-align:left; border-bottom:2px solid #eee;">
            <th style="padding:12px 8px;">เลขคำสั่งซื้อ</th>
            <th style="padding:12px 8px;">ชื่อผู้ใช้งาน</th>
            <th style="padding:12px 8px;">ยอดรวมสุทธิ</th>
            <th style="padding:12px 8px;">สถานะออเดอร์</th>
            <th style="padding:12px 8px; width:420px;">อัปเดตสถานะและแจ้งเตือนไปยังผู้ใช้</th>
            <th style="padding:12px 8px;">วันที่สั่งซื้อ</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($orders)): ?>
             <tr>
               <td colspan="6" style="text-align:center; color:#888; padding:30px;">ไม่พบข้อมูลรายการคำสั่งซื้อตามเงื่อนไขค้นหา</td>
             </tr>
          <?php else: ?>
            <?php foreach($orders as $o): ?>
              <tr style="border-bottom:1px solid #eee;">
                <td style="padding:12px 8px; font-weight:bold;">#<?= (int)$o['id'] ?></td>
                <td style="padding:12px 8px;"><?= htmlspecialchars($o['username'], ENT_QUOTES, 'UTF-8') ?></td>
                <td style="padding:12px 8px; font-weight:bold; color:var(--red,#b00020);">฿<?= number_format((float)$o['total'], 2) ?></td>
                <td style="padding:12px 8px;">
                  <span class="pill <?= htmlspecialchars($o['status'], ENT_QUOTES, 'UTF-8') ?>" style="font-size:12px; font-weight:bold; padding:4px 8px; border-radius:12px;">
                    <?= htmlspecialchars(strtoupper($o['status']), ENT_QUOTES, 'UTF-8') ?>
                  </span>
                </td>
                <td style="padding:12px 8px;">
                  <form method="POST" action="admin_order_update.php" style="display:flex; gap:8px; align-items:center; margin:0;">
                    <?= csrf_tag(); ?>
                    <input type="hidden" name="order_id" value="<?= (int)$o['id'] ?>">
                    
                    <select name="status" class="input" style="padding:4px 8px; border-radius:6px;">
                      <?php foreach(['pending', 'paid', 'packing', 'shipping', 'delivered', 'cancelled'] as $s): ?>
                        <option value="<?= $s ?>" <?= $o['status'] === $s ? 'selected' : '' ?>><?= strtoupper($s) ?></option>
                      <?php endforeach; ?>
                    </select>

                    <label style="display:flex; gap:4px; align-items:center; font-size:13px; user-select:none; cursor:pointer; white-space:nowrap;">
                      <input type="checkbox" name="notify" value="1" checked style="cursor:pointer;">
                      ส่ง Notification หาผู้ใช้
                    </label>

                    <button class="btn" type="submit" style="padding:5px 12px; font-size:13px; background:#222;">บันทึก</button>
                  </form>
                </td>
                <td style="padding:12px 8px; font-size:13px; color:#666;"><?= htmlspecialchars($o['created_at'], ENT_QUOTES, 'UTF-8') ?></td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>

  </div>
</div>
</body>
</html>