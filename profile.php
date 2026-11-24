<?php
require_once 'init.php';
require_login();

$uid = (int)$_SESSION['user_id'];
$err = '';
$ok  = '';

// ถ้าส่งมาเป็น array (เช่น full_name[]=x) trim() จะโยน TypeError หน้าขาว จึงรับเฉพาะ string
function post($k) { $v = $_POST[$k] ?? ''; return is_string($v) ? trim($v) : ''; }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();

    // ลบที่อยู่
    if (isset($_POST['delete_id'])) {
        $id = (int)$_POST['delete_id'];
        $del = $conn->prepare("DELETE FROM user_addresses WHERE id = ? AND user_id = ?");
        $del->bind_param("ii", $id, $uid);
        $del->execute();
        $del->close();

        // ถ้าลบที่อยู่หลักไปแล้ว ให้ที่อยู่ล่าสุดที่เหลือเป็นที่อยู่หลักแทน (เดิมจะไม่มีที่อยู่หลักเลย)
        $hasDef = $conn->prepare("SELECT id FROM user_addresses WHERE user_id = ? AND is_default = 1 LIMIT 1");
        $hasDef->bind_param("i", $uid);
        $hasDef->execute();
        $stillHasDefault = (bool)$hasDef->get_result()->fetch_assoc();
        $hasDef->close();
        if (!$stillHasDefault) {
            $promote = $conn->prepare("UPDATE user_addresses SET is_default = 1 WHERE user_id = ? ORDER BY id DESC LIMIT 1");
            $promote->bind_param("i", $uid);
            $promote->execute();
            $promote->close();
        }
        $ok = "ลบที่อยู่จัดส่งเรียบร้อยแล้ว";
    }

    // ตั้งเป็นที่อยู่หลัก
    if (isset($_POST['default_id'])) {
        $id = (int)$_POST['default_id'];

        // ต้องเช็คก่อนว่าที่อยู่นี้เป็นของเราจริง ไม่งั้นส่ง id มั่ว ๆ มาจะล้างที่อยู่หลักทิ้งหมดโดยไม่ตั้งอันใหม่
        $own = $conn->prepare("SELECT id FROM user_addresses WHERE id = ? AND user_id = ?");
        $own->bind_param("ii", $id, $uid);
        $own->execute();
        $isMine = (bool)$own->get_result()->fetch_assoc();
        $own->close();
        if (!$isMine) {
            $err = 'ไม่พบที่อยู่ที่เลือก';
        } else {
            $clear = $conn->prepare("UPDATE user_addresses SET is_default = 0 WHERE user_id = ?");
            $clear->bind_param("i", $uid);
            $clear->execute();
            $clear->close();

            $setDef = $conn->prepare("UPDATE user_addresses SET is_default = 1 WHERE id = ? AND user_id = ?");
            $setDef->bind_param("ii", $id, $uid);
            $setDef->execute();
            $setDef->close();
            $ok = "ตั้งค่าที่อยู่หลักเรียบร้อยแล้ว";
        }
    }

    // เพิ่มที่อยู่ใหม่
    if (isset($_POST['action']) && $_POST['action'] === 'add') {
        $full_name    = post('full_name');
        $phone        = post('phone');
        $address_line = post('address_line');
        $subdistrict  = post('subdistrict');
        $district     = post('district');
        $province     = post('province');
        $postcode     = post('postcode');

        if ($full_name === '' || $phone === '' || $address_line === '' || $province === '' || $postcode === '') {
            $err = 'กรุณากรอกข้อมูลที่จำเป็นให้ครบถ้วน';
        } elseif (mb_strlen($full_name) > 200 || mb_strlen($address_line) > 1000
                  || mb_strlen($subdistrict) > 100 || mb_strlen($district) > 100 || mb_strlen($province) > 100) {
            // เกินขนาดคอลัมน์ → mysqli โยน exception (หน้าขาว) จึงตรวจก่อน
            $err = 'ข้อมูลที่กรอกยาวเกินกำหนด';
        } elseif (!preg_match('/^[0-9+\-\s()]{6,20}$/', $phone)) {
            $err = 'รูปแบบเบอร์โทรศัพท์ไม่ถูกต้อง';
        } elseif (!preg_match('/^[0-9]{5}$/', $postcode)) {
            $err = 'รหัสไปรษณีย์ต้องเป็นตัวเลข 5 หลัก';
        } else {
            $check = $conn->prepare("SELECT id FROM user_addresses WHERE user_id = ? LIMIT 1");
            $check->bind_param("i", $uid);
            $check->execute();
            $hasAddress = $check->get_result()->fetch_assoc();
            $check->close();

            $is_default = $hasAddress ? 0 : 1;

            $ins = $conn->prepare("INSERT INTO user_addresses (user_id, full_name, phone, address_line, subdistrict, district, province, postcode, is_default) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $ins->bind_param("isssssssi", $uid, $full_name, $phone, $address_line, $subdistrict, $district, $province, $postcode, $is_default);
            if ($ins->execute()) {
                $ok = "เพิ่มที่อยู่จัดส่งใหม่สำเร็จ";
            } else {
                $err = "เกิดข้อผิดพลาดในการบันทึกข้อมูล";
            }
            $ins->close();
        }
    }
}

// ดึงที่อยู่ทั้งหมด
$st = $conn->prepare("SELECT * FROM user_addresses WHERE user_id = ? ORDER BY is_default DESC, id DESC");
$st->bind_param("i", $uid);
$st->execute();
$addresses = $st->get_result()->fetch_all(MYSQLI_ASSOC);
$st->close();
?>
<!doctype html>
<html lang="th">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <link rel="stylesheet" href="assets/style.css?v=<?= filemtime(__DIR__.'/assets/style.css') ?>">
  <title>WorkSync — จัดการที่อยู่จัดส่ง</title>
  <style>
    :root {
      --green: #4caf50;
      --green-light: #e8f5e9;
      --red: #b00020;
      --red-light: #ffebee;
      --border: #e8e8e8;
      --bg: #f7f8fa;
      --card-bg: #ffffff;
      --text: #1a1a2e;
      --muted: #888;
      --radius: 14px;
      --shadow: 0 2px 12px rgba(0,0,0,0.07);
    }

    body { background: var(--bg); font-family: 'Segoe UI', sans-serif; }

    .profile-wrap {
      max-width: 860px;
      margin: 30px auto;
      padding: 0 16px 60px;
    }

    .page-header {
      display: flex;
      align-items: center;
      gap: 12px;
      margin-bottom: 28px;
    }
    .page-header h1 {
      font-size: 22px;
      font-weight: 900;
      color: var(--text);
      margin: 0;
    }
    .page-header .icon {
      width: 44px; height: 44px;
      background: #222;
      border-radius: 12px;
      display: grid;
      place-items: center;
      font-size: 22px;
    }

    /* Alert */
    .alert {
      padding: 12px 16px;
      border-radius: 10px;
      font-weight: 700;
      font-size: 14px;
      margin-bottom: 18px;
      display: flex;
      align-items: center;
      gap: 8px;
    }
    .alert-ok  { background: var(--green-light); color: #2e7d32; }
    .alert-err { background: var(--red-light);   color: #b00020; }

    /* Section card */
    .section-card {
      background: var(--card-bg);
      border-radius: var(--radius);
      border: 1px solid var(--border);
      box-shadow: var(--shadow);
      margin-bottom: 22px;
      overflow: hidden;
    }
    .section-head {
      padding: 16px 22px;
      border-bottom: 1px solid var(--border);
      font-weight: 800;
      font-size: 15px;
      display: flex;
      align-items: center;
      gap: 8px;
      background: #fafafa;
    }

    /* Address card */
    .addr-list { padding: 8px 22px 16px; }
    .addr-card {
      border: 1.5px solid var(--border);
      border-radius: 12px;
      padding: 16px;
      margin-top: 12px;
      display: flex;
      gap: 14px;
      align-items: flex-start;
      transition: border-color .2s, box-shadow .2s;
      position: relative;
    }
    .addr-card.is-default {
      border-color: var(--green);
      background: #f6fff6;
    }
    .addr-card:hover { box-shadow: 0 4px 16px rgba(0,0,0,0.09); }

    .addr-icon { font-size: 26px; margin-top: 2px; }
    .addr-body { flex: 1; min-width: 0; }
    .addr-name {
      font-weight: 800;
      font-size: 15px;
      color: var(--text);
      display: flex;
      align-items: center;
      gap: 8px;
      flex-wrap: wrap;
    }
    .badge-default {
      background: var(--green);
      color: #fff;
      font-size: 11px;
      padding: 2px 8px;
      border-radius: 20px;
      font-weight: 700;
    }
    .addr-phone { color: #555; font-size: 13px; margin-top: 3px; }
    .addr-line  { color: #444; font-size: 14px; margin-top: 6px; line-height: 1.6; }

    .addr-actions {
      display: flex;
      gap: 7px;
      flex-shrink: 0;
      flex-wrap: wrap;
      align-items: flex-start;
    }

    .btn-sm {
      padding: 5px 13px;
      font-size: 12px;
      border: none;
      border-radius: 8px;
      cursor: pointer;
      font-weight: 700;
      transition: opacity .15s;
      white-space: nowrap;
    }
    .btn-sm:hover { opacity: .85; }
    .btn-default-addr { background: #e0e0e0; color: #333; }
    .btn-delete { background: var(--red); color: #fff; }

    .empty-state {
      text-align: center;
      padding: 32px 20px;
      color: var(--muted);
    }
    .empty-state .emoji { font-size: 40px; margin-bottom: 8px; }

    /* Add form */
    .add-form { padding: 20px 22px 24px; }
    .form-row {
      display: flex;
      gap: 14px;
      margin-bottom: 14px;
      flex-wrap: wrap;
    }
    .form-group { flex: 1; min-width: 180px; }
    .form-group label {
      display: block;
      font-size: 13px;
      font-weight: 700;
      margin-bottom: 5px;
      color: #333;
    }
    .form-group .required { color: var(--red); }
    .form-group input,
    .form-group textarea,
    .form-group select {
      width: 100%;
      padding: 9px 12px;
      border: 1.5px solid var(--border);
      border-radius: 9px;
      font-size: 14px;
      font-family: inherit;
      transition: border-color .2s, box-shadow .2s;
      background: #fff;
      box-sizing: border-box;
    }
    .form-group input:focus,
    .form-group textarea:focus {
      outline: none;
      border-color: #4caf50;
      box-shadow: 0 0 0 3px rgba(76,175,80,0.12);
    }
    .form-group textarea { resize: vertical; }

    .btn-submit {
      width: 100%;
      padding: 12px;
      background: #222;
      color: #fff;
      border: none;
      border-radius: 10px;
      font-size: 15px;
      font-weight: 800;
      cursor: pointer;
      margin-top: 6px;
      transition: background .2s;
    }
    .btn-submit:hover { background: #111; }

    @media (max-width: 600px) {
      .addr-card { flex-direction: column; }
      .addr-actions { flex-direction: row; }
    }
  </style>
</head>
<body>

<?php include 'navbar.php'; ?>

<div class="profile-wrap">

  <div class="page-header">
    <div class="icon">📍</div>
    <h1>สมุดที่อยู่จัดส่ง</h1>
  </div>

  <?php if ($ok): ?>
    <div class="alert alert-ok">✅ <?= htmlspecialchars($ok, ENT_QUOTES, 'UTF-8') ?></div>
  <?php endif; ?>
  <?php if ($err): ?>
    <div class="alert alert-err">⚠️ <?= htmlspecialchars($err, ENT_QUOTES, 'UTF-8') ?></div>
  <?php endif; ?>

  <!-- รายการที่อยู่ -->
  <div class="section-card">
    <div class="section-head">📋 ที่อยู่จัดส่งของคุณ
      <span style="background:#222;color:#fff;font-size:11px;padding:2px 8px;border-radius:20px;margin-left:6px;">
        <?= count($addresses) ?> รายการ
      </span>
    </div>

    <div class="addr-list">
      <?php if (empty($addresses)): ?>
        <div class="empty-state">
          <div class="emoji">📭</div>
          <div style="font-weight:700; margin-bottom:4px;">ยังไม่มีที่อยู่จัดส่ง</div>
          <div style="font-size:14px;">กรุณาเพิ่มที่อยู่ด้านล่างก่อนทำการสั่งซื้อ</div>
        </div>
      <?php else: ?>
        <?php foreach ($addresses as $a): ?>
          <div class="addr-card <?= (int)$a['is_default'] ? 'is-default' : '' ?>">
            <div class="addr-icon"><?= (int)$a['is_default'] ? '🏠' : '📦' ?></div>
            <div class="addr-body">
              <div class="addr-name">
                <?= htmlspecialchars($a['full_name'], ENT_QUOTES, 'UTF-8') ?>
                <?php if ((int)$a['is_default']): ?>
                  <span class="badge-default">ที่อยู่หลัก</span>
                <?php endif; ?>
              </div>
              <div class="addr-phone">📱 <?= htmlspecialchars($a['phone'], ENT_QUOTES, 'UTF-8') ?></div>
              <div class="addr-line">
                <?= nl2br(htmlspecialchars($a['address_line'], ENT_QUOTES, 'UTF-8')) ?><br>
                <?php if ($a['subdistrict'] || $a['district']): ?>
                  <?= htmlspecialchars(trim(($a['subdistrict'] ?? '').' '.($a['district'] ?? '')), ENT_QUOTES, 'UTF-8') ?><br>
                <?php endif; ?>
                <?= htmlspecialchars($a['province'], ENT_QUOTES, 'UTF-8') ?> <?= htmlspecialchars($a['postcode'], ENT_QUOTES, 'UTF-8') ?>
              </div>
            </div>

            <div class="addr-actions">
              <?php if (!(int)$a['is_default']): ?>
                <form method="POST" style="margin:0;">
                  <?= csrf_tag() ?>
                  <input type="hidden" name="default_id" value="<?= (int)$a['id'] ?>">
                  <button class="btn-sm btn-default-addr" type="submit">⭐ ตั้งเป็นหลัก</button>
                </form>
              <?php endif; ?>

              <form method="POST" onsubmit="return confirm('ยืนยันการลบที่อยู่นี้?')" style="margin:0;">
                <?= csrf_tag() ?>
                <input type="hidden" name="delete_id" value="<?= (int)$a['id'] ?>">
                <button class="btn-sm btn-delete" type="submit">🗑 ลบ</button>
              </form>
            </div>
          </div>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>
  </div>

  <!-- ฟอร์มเพิ่มที่อยู่ใหม่ -->
  <div class="section-card">
    <div class="section-head">➕ เพิ่มที่อยู่จัดส่งใหม่</div>

    <div class="add-form">
      <form method="POST">
        <?= csrf_tag() ?>
        <input type="hidden" name="action" value="add">

        <div class="form-row">
          <div class="form-group">
            <label>ชื่อ-นามสกุล ผู้รับ <span class="required">*</span></label>
            <input name="full_name" placeholder="เช่น สมชาย ใจดี" required>
          </div>
          <div class="form-group">
            <label>เบอร์โทรศัพท์ <span class="required">*</span></label>
            <input name="phone" placeholder="เช่น 086-xxx-xxxx" required>
          </div>
        </div>

        <div class="form-row">
          <div class="form-group" style="flex:2; min-width:100%;">
            <label>ที่อยู่ (บ้านเลขที่, ถนน, ซอย, หมู่บ้าน/อาคาร) <span class="required">*</span></label>
            <textarea name="address_line" rows="3" placeholder="เช่น 123/4 หมู่บ้านกรีนพาร์ค ถ.สุขุมวิท" required></textarea>
          </div>
        </div>

        <div class="form-row">
          <div class="form-group">
            <label>แขวง / ตำบล</label>
            <input name="subdistrict" placeholder="เช่น บางนา">
          </div>
          <div class="form-group">
            <label>เขต / อำเภอ</label>
            <input name="district" placeholder="เช่น บางนา">
          </div>
        </div>

        <div class="form-row">
          <div class="form-group">
            <label>จังหวัด <span class="required">*</span></label>
            <input name="province" placeholder="เช่น กรุงเทพมหานคร" required>
          </div>
          <div class="form-group">
            <label>รหัสไปรษณีย์ <span class="required">*</span></label>
            <input name="postcode" placeholder="เช่น 10260" required maxlength="5">
          </div>
        </div>

        <button type="submit" class="btn-submit">💾 บันทึกที่อยู่จัดส่ง</button>
      </form>
    </div>
  </div>

  <div style="text-align:center; margin-top:10px;">
    <a href="index.php" style="color:#888; font-size:14px; text-decoration:none;">← กลับหน้าหลัก</a>
  </div>

</div>
</body>
</html>
