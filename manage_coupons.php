<?php
require_once 'init.php';
require_login();
$uid = (int)$_SESSION['user_id'];

// คูปองผูกกับร้าน (seller_id) จึงให้เข้าได้เฉพาะแอดมิน หรือผู้ที่มีสินค้าวางขายอย่างน้อย 1 ชิ้น
$isAdmin = (($_SESSION['role'] ?? '') === 'admin');
if (!$isAdmin) {
    $sc = $conn->prepare("SELECT 1 FROM products WHERE seller_id=? LIMIT 1");
    $sc->bind_param('i', $uid); $sc->execute();
    $isSeller = (bool)$sc->get_result()->fetch_row(); $sc->close();
    if (!$isSeller) {
        http_response_code(403);
        exit('Error 403: เฉพาะผู้ขายหรือแอดมินเท่านั้นที่จัดการ Coupon ได้');
    }
}
$err = ''; $ok = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    if (isset($_POST['delete_id'])) {
        $did = (int)$_POST['delete_id'];
        $del = $conn->prepare("DELETE FROM coupons WHERE id=? AND seller_id=?");
        $del->bind_param('ii', $did, $uid); $del->execute(); $del->close();
        $ok = 'ลบ Coupon แล้ว';
    }
    if (isset($_POST['toggle_id'])) {
        $tid = (int)$_POST['toggle_id'];
        $upd = $conn->prepare("UPDATE coupons SET is_active = 1 - is_active WHERE id=? AND seller_id=?");
        $upd->bind_param('ii', $tid, $uid); $upd->execute(); $upd->close();
        $ok = 'อัปเดตสถานะแล้ว';
    }
    if (isset($_POST['action']) && $_POST['action'] === 'create') {
        $code = strtoupper(trim((string)($_POST['code'] ?? '')));
        $type = ($_POST['type'] ?? '') === 'fixed' ? 'fixed' : 'percent';
        $value = (float)($_POST['value'] ?? 0);
        $minOrder = max(0, (float)($_POST['min_order'] ?? 0));
        $maxUses = min(1000000, max(1, (int)($_POST['max_uses'] ?? 100)));
        $expiresAt = trim((string)($_POST['expires_at'] ?? ''));
        $expDate = $expiresAt !== '' ? DateTime::createFromFormat('!Y-m-d', $expiresAt) : null;
        // จำกัดรูปแบบรหัส (คอลัมน์ code ยาวได้ 20 ตัว) กันอักขระแปลก ๆ / HTML
        if (!preg_match('/^[A-Z0-9_-]{3,20}$/', $code)) { $err = 'รหัส Coupon ต้องเป็น A-Z, 0-9, _ หรือ - ยาว 3-20 ตัวอักษร'; }
        elseif (!is_finite($value) || $value <= 0) { $err = 'กรุณากรอกมูลค่าส่วนลด'; }
        elseif ($type === 'percent' && $value > 100) { $err = 'ส่วนลดแบบเปอร์เซ็นต์ต้องไม่เกิน 100%'; }
        elseif ($value > 99999999) { $err = 'มูลค่าส่วนลดสูงเกินไป'; }
        elseif (!is_finite($minOrder) || $minOrder > 99999999) { $err = 'ยอดขั้นต่ำไม่ถูกต้อง'; }
        elseif ($expiresAt !== '' && (!$expDate || $expDate->format('Y-m-d') !== $expiresAt)) { $err = 'วันหมดอายุไม่ถูกต้อง'; }
        elseif ($expDate && $expDate->format('Y-m-d') < date('Y-m-d')) { $err = 'วันหมดอายุต้องไม่เป็นวันที่ผ่านมาแล้ว'; }
        else {
            $chk = $conn->prepare("SELECT id FROM coupons WHERE code=? LIMIT 1");
            $chk->bind_param('s', $code); $chk->execute();
            if ($chk->get_result()->fetch_assoc()) { $err = 'รหัส Coupon นี้ถูกใช้งานแล้ว'; }
            $chk->close();
            if ($err === '') {
                $expVal = $expiresAt !== '' ? $expiresAt . ' 23:59:59' : null;
                $ins = $conn->prepare("INSERT INTO coupons (seller_id,code,type,value,min_order,max_uses,expires_at) VALUES (?,?,?,?,?,?,?)");
                $ins->bind_param('issdids', $uid, $code, $type, $value, $minOrder, $maxUses, $expVal);
                try {
                    $ins->execute();
                    $ok = 'สร้าง Coupon <b>' . htmlspecialchars($code, ENT_QUOTES, 'UTF-8') . '</b> สำเร็จแล้ว';
                } catch (mysqli_sql_exception $e) {
                    // กดสร้างรหัสเดียวกันพร้อมกัน จะชน UNIQUE key (uq_code) ตรงนี้
                    $err = 'รหัส Coupon นี้ถูกใช้งานแล้ว';
                }
                $ins->close();
            }
        }
    }
}
$st = $conn->prepare("SELECT * FROM coupons WHERE seller_id=? ORDER BY created_at DESC");
$st->bind_param('i', $uid); $st->execute();
$coupons = $st->get_result()->fetch_all(MYSQLI_ASSOC); $st->close();
?>
<!doctype html><html lang="th"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<link rel="stylesheet" href="assets/style.css?v=<?= @filemtime(__DIR__.'/assets/style.css') ?>">
<title>จัดการ Coupon — WorkSync</title></head><body>
<?php include 'navbar.php'; ?>
<div class="container" style="max-width:900px">
  <div class="box" style="margin-top:24px;padding:24px">
    <h2 style="margin-bottom:20px">จัดการ Coupon ส่วนลด</h2>
    <?php if ($err): ?><div style="background:#fdf0f2;color:#c8102e;padding:10px 14px;border-radius:8px;margin-bottom:16px;font-weight:600">✕ <?= htmlspecialchars($err) ?></div><?php endif; ?>
    <?php if ($ok):  ?><div style="background:#eaf6f0;color:#0f9b5e;padding:10px 14px;border-radius:8px;margin-bottom:16px;font-weight:600">✓ <?= $ok ?></div><?php endif; ?>
    <form method="POST" style="background:#f7f8fb;padding:20px;border-radius:12px;margin-bottom:24px">
      <?= csrf_tag() ?><input type="hidden" name="action" value="create">
      <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:14px;margin-bottom:14px">
        <div><label style="font-size:12px;font-weight:700;display:block;margin-bottom:5px">รหัส Coupon *</label>
        <input class="input" name="code" placeholder="SAVE20" style="width:100%;text-transform:uppercase"></div>
        <div><label style="font-size:12px;font-weight:700;display:block;margin-bottom:5px">ประเภท</label>
        <select class="input" name="type" style="width:100%"><option value="percent">% เปอร์เซ็นต์</option><option value="fixed">฿ จำนวนเงิน</option></select></div>
        <div><label style="font-size:12px;font-weight:700;display:block;margin-bottom:5px">มูลค่า *</label>
        <input class="input" type="number" name="value" placeholder="20" style="width:100%"></div>
        <div><label style="font-size:12px;font-weight:700;display:block;margin-bottom:5px">ยอดขั้นต่ำ (฿)</label>
        <input class="input" type="number" name="min_order" value="0" style="width:100%"></div>
        <div><label style="font-size:12px;font-weight:700;display:block;margin-bottom:5px">จำนวนครั้งสูงสุด</label>
        <input class="input" type="number" name="max_uses" value="100" style="width:100%"></div>
        <div><label style="font-size:12px;font-weight:700;display:block;margin-bottom:5px">วันหมดอายุ</label>
        <input class="input" type="date" name="expires_at" style="width:100%"></div>
      </div>
      <button class="btn" type="submit">สร้าง Coupon</button>
    </form>
    <?php if (empty($coupons)): ?>
      <p style="text-align:center;color:#888">ยังไม่มี Coupon</p>
    <?php else: ?>
      <?php foreach ($coupons as $c):
        $expired = $c['expires_at'] && strtotime($c['expires_at']) < time();
        $pct = $c['max_uses'] > 0 ? min(100, round($c['used_count']/$c['max_uses']*100)) : 0;
      ?>
      <div style="display:flex;align-items:center;gap:14px;padding:14px 0;border-bottom:1px solid #f0f0f0;flex-wrap:wrap">
        <div style="width:8px;height:8px;border-radius:50%;background:<?= $c['is_active']&&!$expired?'#0f9b5e':'#ccc' ?>;flex-shrink:0"></div>
        <div style="font-family:monospace;font-size:14px;font-weight:700;color:#0d2137;background:#f0f2f6;padding:3px 10px;border-radius:6px;border:1px dashed #ccc;min-width:100px;text-align:center"><?= htmlspecialchars($c['code']) ?></div>
        <div style="flex:1">
          <div style="font-weight:700">ลด <?= $c['type']==='percent'?number_format((float)$c['value'],0).'%':'฿'.number_format((float)$c['value'],0) ?><?= (float)$c['min_order']>0?' · ขั้นต่ำ ฿'.number_format((float)$c['min_order'],0):'' ?></div>
          <div style="font-size:12px;color:#888">ใช้แล้ว <?= (int)$c['used_count'] ?>/<?= (int)$c['max_uses'] ?><?= $c['expires_at']?' · หมด '.date('d/m/Y',strtotime($c['expires_at'])):'' ?><?= $expired?' <span style="color:#c8102e">(หมดอายุ)</span>':'' ?></div>
          <div style="height:3px;background:#eee;border-radius:2px;margin-top:4px;overflow:hidden"><div style="height:100%;width:<?= $pct ?>%;background:#2756a0;border-radius:2px"></div></div>
        </div>
        <div style="display:flex;gap:7px">
          <form method="POST" style="margin:0"><?= csrf_tag() ?><input type="hidden" name="toggle_id" value="<?= (int)$c['id'] ?>">
          <button class="btn" type="submit" style="padding:5px 12px;font-size:12px;background:#555"><?= $c['is_active']?'ปิด':'เปิด' ?></button></form>
          <form method="POST" style="margin:0" onsubmit="return confirm('ลบ?')"><?= csrf_tag() ?><input type="hidden" name="delete_id" value="<?= (int)$c['id'] ?>">
          <button class="btn" type="submit" style="padding:5px 12px;font-size:12px;background:#c8102e">ลบ</button></form>
        </div>
      </div>
      <?php endforeach; ?>
    <?php endif; ?>
  </div>
</div>
</body></html>
