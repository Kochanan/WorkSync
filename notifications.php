<?php
require_once 'init.php';
require_login();
$uid = (int)$_SESSION['user_id'];

// ปุ่ม "อ่านทั้งหมดแล้ว" — ตรวจ CSRF แล้ว redirect กลับ (PRG) กันกด F5 แล้วส่งฟอร์มซ้ำ
// เดิม check_csrf() อยู่ใน if แต่คำสั่ง UPDATE อยู่นอก if ทำให้ POST อะไรก็ได้ไม่ผ่านการตรวจเลย
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    if (isset($_POST['mark_all'])) {
        $upd = $conn->prepare("UPDATE notifications SET is_read=1 WHERE user_id=?");
        $upd->bind_param("i", $uid);
        $upd->execute();
        $upd->close();
    }
    header('Location: notifications.php');
    exit;
}

// ป้องกัน SQL Injection ด้วยการย้ายมาใช้ Prepared Statements
$st = $conn->prepare("SELECT * FROM notifications WHERE user_id=? ORDER BY id DESC LIMIT 100");
$st->bind_param("i", $uid);
$st->execute();
$list = $st->get_result()->fetch_all(MYSQLI_ASSOC);
$st->close();

// เปิดดูหน้าแจ้งเตือน = ถือว่าอ่านแล้ว (คงพฤติกรรมเดิม; รายการที่ดึงมาแล้วด้านบนยังโชว์ป้าย "ใหม่" ได้ในรอบนี้)
$upd = $conn->prepare("UPDATE notifications SET is_read=1 WHERE user_id=? AND is_read=0");
$upd->bind_param("i", $uid);
$upd->execute();
$upd->close();
?>
<!doctype html>
<html lang="th">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Notifications — ศูนย์แจ้งเตือน</title>
<link rel="stylesheet" href="assets/style.css">
<style>
.n{background:#fff; border:1px solid #eee; border-radius:16px; padding:16px; margin-bottom:12px; box-shadow:0 2px 4px rgba(0,0,0,0.02)}
.n .t{font-weight:900; font-size:16px; color:#333;}
.n .m{color:#555; margin-top:6px; line-height:1.4;}
.n .d{color:#aaa; font-size:12px; margin-top:8px;}
</style>
</head>
<body>

<?php include 'navbar.php'; ?>

<div class="container" style="max-width:820px; margin-top:30px;">
  <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:16px;flex-wrap:wrap;gap:10px;">
    <h2 style="margin:0;">ศูนย์แจ้งเตือนของฉัน</h2>
    <?php if (!empty($list)): ?>
      <form method="POST" action="notifications.php" style="margin:0;">
        <?= csrf_tag() ?>
        <input type="hidden" name="mark_all" value="1">
        <button class="btn" type="submit"
                style="padding:6px 14px;font-size:12px;background:#555;">
          ✓ อ่านทั้งหมดแล้ว
        </button>
      </form>
    <?php endif; ?>
  </div>

  <!-- Tabs -->
  <div style="display:flex;gap:4px;margin-bottom:20px;border-bottom:1px solid rgba(13,33,55,.08);padding-bottom:0;">
    <?php
      $tabs = ['all'=>'ทั้งหมด','order'=>'คำสั่งซื้อ','promo'=>'โปรโมชัน'];
      // รับเฉพาะค่าที่รู้จัก (เดิมค่าแปลก ๆ จะตกไปกรองแบบ "โปรโมชัน")
      $tab = is_string($_GET['tab'] ?? null) ? $_GET['tab'] : 'all';
      if (!isset($tabs[$tab])) $tab = 'all';
      foreach ($tabs as $k => $label):
        $active = $tab === $k;
    ?>
      <a href="?tab=<?= $k ?>"
         style="padding:9px 16px;font-size:13px;font-weight:<?= $active?'700':'500' ?>;
                color:<?= $active?'#0d2137':'#7a8fa6' ?>;text-decoration:none;
                border-bottom:2px solid <?= $active?'#0d2137':'transparent' ?>;
                border-radius:var(--r-sm,8px) var(--r-sm,8px) 0 0;
                transition:color .15s;">
        <?= $label ?>
        <?php
          $cnt = array_filter($list, function($n) use ($k) {
            if ($k === 'all') return true;
            if ($k === 'order') return stripos($n['title'],'ออเดอร์') !== false || stripos($n['title'],'order') !== false;
            return stripos($n['title'],'โปร') !== false || stripos($n['title'],'สิทธิ') !== false;
          });
          if (count($cnt) > 0): ?>
          <span style="background:#ff5c35;color:#fff;font-size:10px;font-weight:700;
                       padding:1px 6px;border-radius:999px;margin-left:5px;">
            <?= count($cnt) ?>
          </span>
        <?php endif; ?>
      </a>
    <?php endforeach; ?>
  </div>

  <?php
    // กรอง tab
    $filtered = array_filter($list, function($n) use ($tab) {
      if ($tab === 'all') return true;
      if ($tab === 'order') return stripos($n['title'],'ออเดอร์') !== false || stripos($n['title'],'order') !== false;
      return stripos($n['title'],'โปร') !== false || stripos($n['title'],'สิทธิ') !== false;
    });
  ?>

  <?php if (empty($filtered)): ?>
    <div style="text-align:center; color:#999; margin-top:40px; padding:40px;">
      <div style="font-size:40px;opacity:.4;margin-bottom:10px;">🔔</div>
      <div>ไม่มีการแจ้งเตือนในหมวดนี้</div>
    </div>
  <?php endif; ?>

  <?php foreach ($filtered as $n): ?>
    <div class="n" style="<?= (!$n['is_read']) ? 'border-left: 4px solid #0d2137;' : '' ?>">
      <div class="t"><?= htmlspecialchars($n['title'], ENT_QUOTES, 'UTF-8') ?></div>
      <div class="m"><?= nl2br(htmlspecialchars($n['message'], ENT_QUOTES, 'UTF-8')) ?></div>
      <div class="d">📅 <?= htmlspecialchars($n['created_at'], ENT_QUOTES, 'UTF-8') ?>
        <?php if (!$n['is_read']): ?>
          <span style="background:#ff5c35;color:#fff;font-size:10px;padding:1px 7px;
                       border-radius:999px;margin-left:6px;">ใหม่</span>
        <?php endif; ?>
      </div>
    </div>
  <?php endforeach; ?>
</div>
</body>
</html>