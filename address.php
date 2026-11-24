<?php
require_once 'init.php';
require_login();

$uid = (int)$_SESSION['user_id'];
$err = '';
$ok = '';

// ถ้าส่งมาเป็น array (เช่น full_name[]=x) trim() จะโยน TypeError หน้าขาว จึงรับเฉพาะ string
function post($k) { $v = $_POST[$k] ?? ''; return is_string($v) ? trim($v) : ''; }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();

    // 1. ลบข้อมูลที่อยู่
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

    // 2. ตั้งค่าให้เป็นที่อยู่หลัก (Default Address)
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

            // เคลียร์ค่าที่อยู่เดิมทั้งหมดของ User รายนี้ไม่ให้เป็นหลัก
            $clear = $conn->prepare("UPDATE user_addresses SET is_default = 0 WHERE user_id = ?");
            $clear->bind_param("i", $uid);
            $clear->execute();
            $clear->close();

            // อัปเดตที่อยู่รายการที่เลือกให้เป็นที่อยู่หลัก
            $setOpt = $conn->prepare("UPDATE user_addresses SET is_default = 1 WHERE id = ? AND user_id = ?");
            $setOpt->bind_param("ii", $id, $uid);
            $setOpt->execute();
            $setOpt->close();

            $ok = "ตั้งค่าที่อยู่หลักเรียบร้อยแล้ว";
        }
    }

    // 3. เพิ่มข้อมูลที่อยู่ใหม่
    if (isset($_POST['action']) && $_POST['action'] === 'add') {
        $full_name    = post('full_name');
        $phone        = post('phone');
        $address_line = post('address_line');
        $subdistrict  = post('subdistrict');
        $district     = post('district');
        $province     = post('province');
        $postcode     = post('postcode');

        if ($full_name === '' || $phone === '' || $address_line === '' || $province === '' || $postcode === '') {
            $err = 'กรุณากรอกข้อมูลที่จำเป็นให้ครบถ้วน (ชื่อ, เบอร์โทรศัพท์, ที่อยู่, จังหวัด และรหัสไปรษณีย์)';
        } elseif (mb_strlen($full_name) > 200 || mb_strlen($address_line) > 1000
                  || mb_strlen($subdistrict) > 100 || mb_strlen($district) > 100 || mb_strlen($province) > 100) {
            // เกินขนาดคอลัมน์ → mysqli โยน exception (หน้าขาว) จึงตรวจก่อน
            $err = 'ข้อมูลที่กรอกยาวเกินกำหนด';
        } elseif (!preg_match('/^[0-9+\-\s()]{6,20}$/', $phone)) {
            $err = 'รูปแบบเบอร์โทรศัพท์ไม่ถูกต้อง';
        } elseif (!preg_match('/^[0-9]{5}$/', $postcode)) {
            $err = 'รหัสไปรษณีย์ต้องเป็นตัวเลข 5 หลัก';
        } else {
            // ค้นหาว่ามีที่อยู่อื่นอยู่ก่อนไหม ถ้าไม่มีให้ที่อยู่นี้เป็นหลักทันที
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

// ดึงข้อมูลที่อยู่ทั้งหมดของผู้ใช้มาแสดงผล
$st = $conn->prepare("SELECT * FROM user_addresses WHERE user_id = ? ORDER BY is_default DESC, id DESC");
$st->bind_param("i", $uid);
$st->execute();
$addresses = $st->get_result();
$st->close();
?>
<!doctype html>
<html lang="th">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <link rel="stylesheet" href="assets/style.css?v=<?= filemtime(__DIR__.'/assets/style.css') ?>">
  <title>WorkSync — จัดการที่อยู่จัดส่ง</title>
</head>
<body>
<?php include 'navbar.php'; ?>

<div class="container" style="max-width: 800px; margin-top: 20px;">
  <div class="box" style="padding: 25px;">
    <h2>📍 สมุดที่อยู่จัดส่งสินค้า</h2>
    
    <?php if($err): ?>
        <div style="background:#ffebee; color:#c62828; padding:10px 15px; border-radius:8px; margin-bottom:15px; font-weight:bold; font-size:14px;">⚠️ <?= htmlspecialchars($err, ENT_QUOTES, 'UTF-8') ?></div>
    <?php endif; ?>
    
    <?php if($ok): ?>
        <div style="background:#e8f5e9; color:#2e7d32; padding:10px 15px; border-radius:8px; margin-bottom:15px; font-weight:bold; font-size:14px;">✅ <?= htmlspecialchars($ok, ENT_QUOTES, 'UTF-8') ?></div>
    <?php endif; ?>

    <div style="margin-bottom:30px;">
      <h3 style="margin-bottom:15px; color:#555;">รายการที่อยู่ของคุณ</h3>
      <?php if ($addresses->num_rows === 0): ?>
         <p style="color:#888; text-align:center; padding:20px; background:#fafafa; border-radius:8px;">ยังไม่มีข้อมูลที่อยู่จัดส่งในระบบ กรุณาเพิ่มที่อยู่ด้านล่าง</p>
      <?php else: ?>
         <?php while($a = $addresses->fetch_assoc()): ?>
            <div class="box" style="margin-bottom:15px; border:1px solid #eee; position:relative; <?= $a['is_default'] ? 'border-left: 5px solid #4caf50;' : '' ?>">
              <div style="display:flex; justify-content:space-between; gap:10px; flex-wrap:wrap;">
                <div>
                  <div style="font-weight:bold; font-size:16px;">
                    <?= htmlspecialchars($a['full_name'], ENT_QUOTES, 'UTF-8') ?>
                    <?php if((int)$a['is_default'] === 1): ?> 
                        <span class="badge" style="background:#4caf50; color:#fff; font-size:11px; padding:2px 6px; border-radius:4px; margin-left:5px;">ที่อยู่หลัก</span>
                    <?php endif; ?>
                  </div>
                  <div style="color:#666; margin-top:4px; font-size:14px;">📱 เบอร์โทรศัพท์: <?= htmlspecialchars($a['phone'], ENT_QUOTES, 'UTF-8') ?></div>
                  <div style="color:#444; margin-top:8px; font-size:14px; line-height:1.5;">
                    <?= nl2br(htmlspecialchars($a['address_line'], ENT_QUOTES, 'UTF-8')) ?><br>
                    <?= htmlspecialchars($a['subdistrict'] ?? '', ENT_QUOTES, 'UTF-8') ?> <?= htmlspecialchars($a['district'] ?? '', ENT_QUOTES, 'UTF-8') ?><br>
                    <?= htmlspecialchars($a['province'], ENT_QUOTES, 'UTF-8') ?> <?= htmlspecialchars($a['postcode'], ENT_QUOTES, 'UTF-8') ?>
                  </div>
                </div>

                <div style="display:flex; gap:8px; align-items:flex-start;">
                  <?php if(!(int)$a['is_default']): ?>
                    <form method="POST" style="margin:0;">
                      <?= csrf_tag(); ?>
                      <input type="hidden" name="default_id" value="<?= (int)$a['id'] ?>">
                      <button class="btn" type="submit" style="background:#777; padding:5px 10px; font-size:12px;">ตั้งเป็นที่อยู่หลัก</button>
                    </form>
                  <?php endif; ?>

                  <form method="POST" onsubmit="return confirm('คุณต้องการลบที่อยู่จัดส่งนี้ใช่หรือไม่?')" style="margin:0;">
                    <?= csrf_tag(); ?>
                    <input type="hidden" name="delete_id" value="<?= (int)$a['id'] ?>">
                    <button class="btn" type="submit" style="background:var(--red,#b00020); padding:5px 10px; font-size:12px;">ลบ</button>
                  </form>
                </div>
              </div>
            </div>
         <?php endwhile; ?>
      <?php endif; ?>
    </div>

    <div style="background:#fafafa; padding:20px; border-radius:12px; border:1px solid #eee;">
      <h3 style="margin-bottom:15px; color:#333;">➕ เพิ่มที่อยู่จัดส่งใหม่</h3>
      <form method="POST">
        <?= csrf_tag(); ?>
        <input type="hidden" name="action" value="add">
        
        <div class="row" style="display:flex; gap:15px; margin-bottom:12px;">
          <div style="flex:1;">
            <label style="font-weight:bold; display:block; margin-bottom:4px; font-size:14px;">ชื่อ-นามสกุล ผู้รับสินค้า</label>
            <input class="input" name="full_name" required style="width:100%;">
          </div>
          <div style="flex:1;">
            <label style="font-weight:bold; display:block; margin-bottom:4px; font-size:14px;">เบอร์โทรศัพท์ติดต่อ</label>
            <input class="input" name="phone" required style="width:100%;">
          </div>
        </div>

        <div style="margin-bottom:12px;">
          <label style="font-weight:bold; display:block; margin-bottom:4px; font-size:14px;">ที่อยู่ (บ้านเลขที่, ถนน, ซอย, หมู่บ้าน/อาคาร)</label>
          <textarea class="input" name="address_line" rows="3" required style="width:100%; font-family:inherit;"></textarea>
        </div>

        <div class="row" style="display:flex; gap:15px; margin-bottom:15px; flex-wrap:wrap;">
          <div style="flex:1; min-width:150px;">
            <label style="font-weight:bold; display:block; margin-bottom:4px; font-size:14px;">แขวง / ตำบล</label>
            <input class="input" name="subdistrict" style="width:100%;">
          </div>
          <div style="flex:1; min-width:150px;">
            <label style="font-weight:bold; display:block; margin-bottom:4px; font-size:14px;">เขต / อำเภอ</label>
            <input class="input" name="district" style="width:100%;">
          </div>
          <div style="flex:1; min-width:150px;">
            <label style="font-weight:bold; display:block; margin-bottom:4px; font-size:14px;">จังหวัด</label>
            <input class="input" name="province" required style="width:100%;">
          </div>
          <div style="flex:1; min-width:150px;">
            <label style="font-weight:bold; display:block; margin-bottom:4px; font-size:14px;">รหัสไปรษณีย์</label>
            <input class="input" name="postcode" required style="width:100%;">
          </div>
        </div>

        <button type="submit" class="btn" style="width:100%; padding:10px; font-size:15px;">บันทึกข้อมูลที่อยู่จัดส่ง</button>
      </form>
    </div>

  </div>
</div>
</body>
</html>