<?php
/**
 * user_profile.php — หน้าโปรไฟล์ผู้ใช้
 * แก้ไขข้อมูลส่วนตัว, อัปโหลดรูปโปรไฟล์, เปลี่ยนรหัสผ่าน
 */
require_once 'init.php';
require_login();

$uid = (int)$_SESSION['user_id'];
$err = '';
$ok  = '';

/* ── ดึงข้อมูล user ──────────────────────────────────────────── */
$st = $conn->prepare("SELECT * FROM users WHERE id = ? LIMIT 1");
$st->bind_param('i', $uid);
$st->execute();
$user = $st->get_result()->fetch_assoc();
$st->close();

// session ยังอยู่แต่บัญชีถูกลบไปแล้ว → ออกจากระบบ (เดิมจะเจอ warning/fatal เพราะ $user เป็น null)
if (!$user) {
    header('Location: logout.php');
    exit;
}

/** อ่านค่า POST แบบ string เท่านั้น (ส่งมาเป็น array จะไม่ทำให้ trim() พัง) */
function post_str(string $k): string {
    $v = $_POST[$k] ?? '';
    return is_string($v) ? trim($v) : '';
}

/* ── POST handler ────────────────────────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $action = $_POST['action'] ?? '';

    /* ── 1. บันทึกข้อมูลทั่วไป + รูปโปรไฟล์ ────────────────── */
    if ($action === 'profile') {
        $display_name = post_str('display_name');
        $email        = post_str('email');
        $phone        = post_str('phone');
        $bio          = post_str('bio');
        $curPw        = $_POST['current_password'] ?? '';
        $emailChanged = mb_strtolower($email) !== mb_strtolower((string)($user['email'] ?? ''));

        // validate
        if ($email !== '' && (strlen($email) > 150 || !filter_var($email, FILTER_VALIDATE_EMAIL))) {
            $err = 'รูปแบบอีเมลไม่ถูกต้อง';
        } elseif ($email === '' && !empty($user['email'])) {
            // อีเมลใช้กู้รหัสผ่าน ห้ามลบทิ้ง
            $err = 'กรุณากรอกอีเมล';
        } elseif (mb_strlen($display_name) > 100) {
            $err = 'ชื่อที่แสดงยาวเกินไป (สูงสุด 100 ตัวอักษร)';
        } elseif ($phone !== '' && !preg_match('/^[0-9+\-\s()]{6,20}$/', $phone)) {
            $err = 'รูปแบบเบอร์โทรศัพท์ไม่ถูกต้อง';
        } elseif (mb_strlen($bio) > 300) {
            $err = 'คำอธิบายตัวเองยาวเกินไป (สูงสุด 300 ตัวอักษร)';
        } elseif ($emailChanged && (!is_string($curPw) || !password_verify($curPw, $user['password']))) {
            // เปลี่ยนอีเมลต้องยืนยันรหัสผ่านปัจจุบัน — ไม่งั้นคนที่ขโมย session ได้
            // จะเปลี่ยนอีเมลเป็นของตัวเองแล้วใช้ "ลืมรหัสผ่าน" ยึดบัญชีถาวร
            $err = 'การเปลี่ยนอีเมลต้องกรอกรหัสผ่านปัจจุบันให้ถูกต้อง';
        }

        // อีเมลห้ามซ้ำกับบัญชีอื่น (เดิมไม่เช็ค → อีเมลซ้ำกันหลายบัญชี, ลืมรหัสผ่านส่ง OTP ผิดบัญชี
        // และเจ้าของอีเมลตัวจริงสมัครสมาชิกไม่ได้)
        if ($err === '' && $emailChanged && $email !== '') {
            $dup = $conn->prepare("SELECT id FROM users WHERE email = ? AND id <> ? LIMIT 1");
            $dup->bind_param('si', $email, $uid);
            $dup->execute();
            if ($dup->get_result()->fetch_assoc()) {
                $err = 'อีเมลนี้ถูกใช้งานโดยบัญชีอื่นแล้ว';
            }
            $dup->close();
        }

        if ($err === '') {
            $avatarPath = $user['avatar']; // คงเดิมถ้าไม่ได้อัปโหลดใหม่
            $oldAvatar  = null;

            /* อัปโหลดรูปโปรไฟล์ */
            if (isset($_FILES['avatar']) && is_array($_FILES['avatar']) && is_string($_FILES['avatar']['name'] ?? null)
                && $_FILES['avatar']['name'] !== '') {
                $f = $_FILES['avatar'];
                $allowedExt  = ['jpg','jpeg','png','webp'];
                // นามสกุลไฟล์ที่บันทึกจริง กำหนดจากชนิดไฟล์ที่ตรวจได้ ไม่เชื่อชื่อไฟล์ที่ผู้ใช้ส่งมา
                $mimeToExt   = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
                $maxSize     = 3 * 1024 * 1024; // 3MB
                $ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));

                if ($f['error'] === UPLOAD_ERR_INI_SIZE || $f['error'] === UPLOAD_ERR_FORM_SIZE) {
                    $err = 'ขนาดไฟล์ต้องไม่เกิน 3MB';
                } elseif ($f['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($f['tmp_name'])) {
                    $err = 'เกิดข้อผิดพลาดในการอัปโหลดไฟล์';
                } elseif (!in_array($ext, $allowedExt, true)) {
                    $err = 'อนุญาตเฉพาะไฟล์ jpg, jpeg, png, webp เท่านั้น';
                } elseif ($f['size'] > $maxSize || filesize($f['tmp_name']) > $maxSize) {
                    $err = 'ขนาดไฟล์ต้องไม่เกิน 3MB';
                } else {
                    $fi   = finfo_open(FILEINFO_MIME_TYPE);
                    $mime = finfo_file($fi, $f['tmp_name']);
                    finfo_close($fi);
                    $info = @getimagesize($f['tmp_name']);
                    if (!isset($mimeToExt[$mime]) || $info === false || ($info['mime'] ?? '') !== $mime
                        || $info[0] < 1 || $info[1] < 1 || $info[0] > 6000 || $info[1] > 6000) {
                        $err = 'ไฟล์ไม่ใช่รูปภาพจริง';
                    } else {
                        $uploadDir = __DIR__ . '/assets/avatars';
                        if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);

                        $newName = 'avatar_' . $uid . '_' . bin2hex(random_bytes(8)) . '.' . $mimeToExt[$mime];
                        $target  = $uploadDir . '/' . $newName;
                        if (!move_uploaded_file($f['tmp_name'], $target)) {
                            $err = 'ไม่สามารถบันทึกไฟล์ได้';
                        } else {
                            $avatarPath = 'assets/avatars/' . $newName;
                            $oldAvatar  = $user['avatar'];
                        }
                    }
                }
            }
        }

        if ($err === '') {
            $emailDb = $email === '' ? null : $email;
            $upd = $conn->prepare(
                "UPDATE users SET display_name=?, email=?, phone=?, bio=?, avatar=?, updated_at=NOW()
                 WHERE id=?"
            );
            $upd->bind_param('sssssi', $display_name, $emailDb, $phone, $bio, $avatarPath, $uid);
            $upd->execute();
            $upd->close();

            // ลบรูปเก่า "หลัง" บันทึกรูปใหม่สำเร็จแล้วเท่านั้น (เดิมลบก่อน ถ้าอัปโหลดพังรูปจะหายไปเฉย ๆ)
            // และลบได้เฉพาะไฟล์ในโฟลเดอร์ avatars เท่านั้น กันค่าใน DB ชี้ไปลบไฟล์อื่นของระบบ
            if ($oldAvatar && preg_match('#^assets/avatars/avatar_[A-Za-z0-9_]+\.(jpg|jpeg|png|webp)$#', $oldAvatar)) {
                $old = __DIR__ . '/' . $oldAvatar;
                if (is_file($old)) @unlink($old);
            }

            // อัปเดต session display name
            $_SESSION['display_name'] = $display_name ?: $user['username'];

            // reload user
            $st = $conn->prepare("SELECT * FROM users WHERE id = ? LIMIT 1");
            $st->bind_param('i', $uid);
            $st->execute();
            $user = $st->get_result()->fetch_assoc();
            $st->close();

            $ok = 'บันทึกข้อมูลโปรไฟล์เรียบร้อยแล้ว';
        }
    }

    /* ── 2. เปลี่ยนรหัสผ่าน ─────────────────────────────────── */
    if ($action === 'password') {
        $oldPw  = $_POST['old_password']  ?? '';
        $newPw  = $_POST['new_password']  ?? '';
        $confPw = $_POST['conf_password'] ?? '';
        if (!is_string($oldPw) || !is_string($newPw) || !is_string($confPw)) {
            $oldPw = $newPw = $confPw = '';
        }

        // จำกัดการเดารหัสผ่านเดิม (กรณี session ถูกขโมย) ผิด 5 ครั้ง พัก 15 นาที
        $pwFail = $_SESSION['_pwchg_fail'] ?? ['n' => 0, 't' => 0];
        if ($pwFail['t'] < time() - 900) $pwFail = ['n' => 0, 't' => time()];

        if ($pwFail['n'] >= 5) {
            $err = 'กรอกรหัสผ่านเดิมผิดหลายครั้งเกินไป กรุณาลองใหม่ภายหลัง';
        } elseif (!password_verify($oldPw, $user['password'])) {
            $pwFail['n']++;
            $_SESSION['_pwchg_fail'] = $pwFail;
            $err = 'รหัสผ่านเดิมไม่ถูกต้อง';
        } elseif (strlen($newPw) < 6) {
            $err = 'รหัสผ่านใหม่ต้องมีอย่างน้อย 6 ตัวอักษร';
        } elseif (strlen($newPw) > 72) {
            $err = 'รหัสผ่านใหม่ยาวเกินไป (สูงสุด 72 ตัวอักษร)';
        } elseif ($newPw !== $confPw) {
            $err = 'รหัสผ่านใหม่ไม่ตรงกัน';
        } else {
            $hash = password_hash($newPw, PASSWORD_DEFAULT);
            $upd  = $conn->prepare("UPDATE users SET password=?, updated_at=NOW() WHERE id=?");
            $upd->bind_param('si', $hash, $uid);
            $upd->execute();
            $upd->close();
            unset($_SESSION['_pwchg_fail']);
            // เปลี่ยนรหัสแล้วออก session id ใหม่ (session เดิมที่อาจหลุดไปใช้ต่อไม่ได้)
            session_regenerate_id(true);
            $user['password'] = $hash;
            $ok = 'เปลี่ยนรหัสผ่านเรียบร้อยแล้ว';
        }
    }
}

/* ── ข้อมูลสรุป ──────────────────────────────────────────────── */
$orderSt = $conn->prepare("SELECT COUNT(*) cnt, COALESCE(SUM(total),0) total FROM orders WHERE user_id=?");
$orderSt->bind_param('i', $uid);
$orderSt->execute();
$stats = $orderSt->get_result()->fetch_assoc();
$orderSt->close();

$addrSt = $conn->prepare("SELECT COUNT(*) cnt FROM user_addresses WHERE user_id=?");
$addrSt->bind_param('i', $uid);
$addrSt->execute();
$addrCount = (int)$addrSt->get_result()->fetch_assoc()['cnt'];
$addrSt->close();

/* ── helpers ─────────────────────────────────────────────────── */
// ใช้ img_src() ตรวจว่าไฟล์มีอยู่จริง ถ้าไฟล์หายจะได้แสดงตัวอักษรย่อแทนรูปแตก
$avatarUrl = ($user['avatar'] && img_src($user['avatar']) !== NO_IMAGE) ? img_src($user['avatar']) : null;
$initials = mb_strtoupper(mb_substr($user['display_name'] ?: $user['username'], 0, 2));
$memberSince = date('M Y', strtotime($user['created_at']));
?>
<!doctype html>
<html lang="th">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <link rel="stylesheet" href="assets/style.css?v=<?= @filemtime(__DIR__.'/assets/style.css') ?>">
  <title>WorkSync — โปรไฟล์ของฉัน</title>
  <style>
    .profile-wrap { max-width: 900px; margin: 28px auto; padding: 0 16px 60px; }

    /* ── Hero card ── */
    .hero-card {
      background: #fff;
      border: 1px solid var(--n200,#e9ecef);
      border-radius: 14px;
      overflow: hidden;
      margin-bottom: 20px;
      box-shadow: 0 1px 4px rgba(0,0,0,.06);
    }
    .hero-banner {
      height: 100px;
      background: linear-gradient(135deg, var(--primary,#0f4c81) 0%, #1a6fb5 100%);
    }
    .hero-body {
      padding: 0 28px 24px;
      display: flex;
      align-items: flex-end;
      gap: 20px;
      margin-top: -44px;
      flex-wrap: wrap;
    }
    .avatar-wrap { position: relative; flex-shrink: 0; }
    .avatar-img {
      width: 88px; height: 88px;
      border-radius: 50%;
      border: 4px solid #fff;
      object-fit: cover;
      background: var(--n200,#e9ecef);
      display: block;
      box-shadow: 0 2px 8px rgba(0,0,0,.12);
    }
    .avatar-init {
      width: 88px; height: 88px;
      border-radius: 50%;
      border: 4px solid #fff;
      background: var(--primary,#0f4c81);
      color: #fff;
      font-size: 28px;
      font-weight: 700;
      display: flex; align-items: center; justify-content: center;
      box-shadow: 0 2px 8px rgba(0,0,0,.12);
      letter-spacing: -1px;
    }
    .avatar-edit-btn {
      position: absolute; bottom: 4px; right: 4px;
      width: 26px; height: 26px;
      border-radius: 50%;
      background: var(--n700,#495057);
      border: 2px solid #fff;
      display: flex; align-items: center; justify-content: center;
      cursor: pointer;
      transition: background .15s;
    }
    .avatar-edit-btn:hover { background: var(--primary,#0f4c81); }
    .avatar-edit-btn svg { width: 13px; height: 13px; stroke: #fff; fill: none; stroke-width: 2; }

    .hero-info { flex: 1; min-width: 0; padding-top: 52px; }
    .hero-name { font-size: 20px; font-weight: 700; color: var(--n900,#212529); }
    .hero-username { font-size: 13px; color: var(--n500,#adb5bd); margin-top: 2px; }
    .hero-bio { font-size: 14px; color: var(--n600,#6c757d); margin-top: 6px; line-height: 1.5; }

    /* stats */
    .stats-row {
      display: flex; gap: 12px; margin-top: 16px; flex-wrap: wrap;
    }
    .stat-box {
      background: var(--n50,#f8f9fa);
      border: 1px solid var(--n200,#e9ecef);
      border-radius: 10px;
      padding: 12px 20px;
      text-align: center;
      min-width: 100px;
    }
    .stat-val { font-size: 20px; font-weight: 700; color: var(--primary,#0f4c81); }
    .stat-lbl { font-size: 12px; color: var(--n500,#adb5bd); margin-top: 2px; }

    /* ── Tabs ── */
    .tab-bar {
      display: flex; gap: 2px;
      border-bottom: 1px solid var(--n200,#e9ecef);
      margin-bottom: 22px;
    }
    .tab-btn {
      padding: 10px 20px;
      font-size: 14px; font-weight: 600;
      color: var(--n600,#6c757d);
      border: none; background: none;
      border-bottom: 2px solid transparent;
      margin-bottom: -1px;
      cursor: pointer;
      transition: color .15s, border-color .15s;
    }
    .tab-btn.active { color: var(--primary,#0f4c81); border-bottom-color: var(--primary,#0f4c81); }
    .tab-btn:hover  { color: var(--n900,#212529); }

    .tab-panel { display: none; }
    .tab-panel.active { display: block; }

    /* ── Form section ── */
    .form-card {
      background: #fff;
      border: 1px solid var(--n200,#e9ecef);
      border-radius: 14px;
      overflow: hidden;
      box-shadow: 0 1px 4px rgba(0,0,0,.06);
    }
    .form-card-head {
      padding: 16px 24px;
      border-bottom: 1px solid var(--n100,#f1f3f5);
      font-weight: 700; font-size: 15px;
      color: var(--n900,#212529);
      background: var(--n50,#f8f9fa);
    }
    .form-body { padding: 24px; }

    .form-row { display: flex; gap: 16px; margin-bottom: 16px; flex-wrap: wrap; }
    .form-group { flex: 1; min-width: 200px; }
    .form-group label {
      display: block; font-size: 13px; font-weight: 600;
      color: var(--n700,#495057); margin-bottom: 6px;
    }
    .form-group .req { color: var(--red,#c8102e); }
    .form-group input,
    .form-group textarea {
      width: 100%; padding: 9px 12px;
      border: 1px solid var(--n300,#dee2e6);
      border-radius: 8px;
      font-size: 14px; font-family: inherit;
      color: var(--n900,#212529);
      transition: border-color .15s, box-shadow .15s;
      box-sizing: border-box;
      background: #fff;
    }
    .form-group input:focus,
    .form-group textarea:focus {
      outline: none;
      border-color: var(--primary,#0f4c81);
      box-shadow: 0 0 0 3px rgba(15,76,129,.1);
    }
    .form-group textarea { resize: vertical; }
    .form-hint { font-size: 12px; color: var(--n500,#adb5bd); margin-top: 4px; }

    /* avatar upload preview */
    .avatar-upload-area {
      display: flex; align-items: center; gap: 20px;
      padding: 16px;
      background: var(--n50,#f8f9fa);
      border: 1px dashed var(--n300,#dee2e6);
      border-radius: 10px;
      margin-bottom: 20px;
      cursor: pointer;
      transition: border-color .15s, background .15s;
    }
    .avatar-upload-area:hover { border-color: var(--primary,#0f4c81); background: var(--primary-light,#e8f0f9); }

    .preview-avatar {
      width: 64px; height: 64px; border-radius: 50%;
      object-fit: cover; background: var(--n200,#e9ecef);
      flex-shrink: 0;
    }
    .preview-init {
      width: 64px; height: 64px; border-radius: 50%;
      background: var(--primary,#0f4c81); color: #fff;
      font-size: 22px; font-weight: 700;
      display: flex; align-items: center; justify-content: center;
      flex-shrink: 0;
    }
    .upload-text { flex: 1; }
    .upload-text strong { font-size: 14px; color: var(--n800,#343a40); display: block; }
    .upload-text span   { font-size: 12px; color: var(--n500,#adb5bd); }

    /* alert */
    .alert { padding: 12px 16px; border-radius: 8px; font-size: 14px; font-weight: 600; margin-bottom: 18px; display: flex; align-items: center; gap: 8px; }
    .alert-ok  { background: #eaf6f0; color: #1a7f4b; }
    .alert-err { background: #fdf0f2; color: #c8102e; }

    .btn-save {
      padding: 0 24px; height: 40px;
      background: var(--primary,#0f4c81); color: #fff;
      border: none; border-radius: 8px;
      font-size: 14px; font-weight: 600;
      cursor: pointer; transition: background .15s;
    }
    .btn-save:hover { background: var(--primary-dark,#0a3560); }

    .danger-zone {
      border: 1px solid var(--red,#c8102e);
      border-radius: 14px; overflow: hidden;
    }
    .danger-head { background: #fdf0f2; padding: 14px 24px; font-weight: 700; font-size: 14px; color: var(--red,#c8102e); }
  </style>
</head>
<body>
<?php include 'navbar.php'; ?>

<div class="profile-wrap">

  <?php if ($ok): ?>
    <div class="alert alert-ok">✓ <?= htmlspecialchars($ok, ENT_QUOTES, 'UTF-8') ?></div>
  <?php endif; ?>
  <?php if ($err): ?>
    <div class="alert alert-err">✕ <?= htmlspecialchars($err, ENT_QUOTES, 'UTF-8') ?></div>
  <?php endif; ?>

  <!-- Hero -->
  <div class="hero-card">
    <div class="hero-banner"></div>
    <div class="hero-body">
      <div class="avatar-wrap">
        <?php if ($avatarUrl): ?>
          <img class="avatar-img" src="<?= htmlspecialchars($avatarUrl, ENT_QUOTES, 'UTF-8') ?>" alt="avatar" id="heroAvatar">
        <?php else: ?>
          <div class="avatar-init" id="heroAvatar"><?= htmlspecialchars($initials, ENT_QUOTES, 'UTF-8') ?></div>
        <?php endif; ?>
        <div class="avatar-edit-btn" title="เปลี่ยนรูปโปรไฟล์" onclick="switchTab('tab-info'); document.getElementById('avatarFile').click();">
          <svg viewBox="0 0 24 24"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
        </div>
      </div>

      <div class="hero-info">
        <div class="hero-name"><?= htmlspecialchars($user['display_name'] ?: $user['username'], ENT_QUOTES, 'UTF-8') ?></div>
        <div class="hero-username">@<?= htmlspecialchars($user['username'], ENT_QUOTES, 'UTF-8') ?> · สมาชิกตั้งแต่ <?= $memberSince ?></div>
        <?php if ($user['bio']): ?>
          <div class="hero-bio"><?= htmlspecialchars($user['bio'], ENT_QUOTES, 'UTF-8') ?></div>
        <?php endif; ?>
      </div>
    </div>

    <div style="padding: 0 28px 20px;">
      <div class="stats-row">
        <div class="stat-box">
          <div class="stat-val"><?= (int)$stats['cnt'] ?></div>
          <div class="stat-lbl">ออเดอร์ทั้งหมด</div>
        </div>
        <div class="stat-box">
          <div class="stat-val">฿<?= number_format((float)$stats['total'], 0) ?></div>
          <div class="stat-lbl">ยอดซื้อสะสม</div>
        </div>
        <div class="stat-box">
          <div class="stat-val"><?= $addrCount ?></div>
          <div class="stat-lbl">ที่อยู่จัดส่ง</div>
        </div>
      </div>
    </div>
  </div>

  <!-- Tabs -->
  <div class="tab-bar">
    <button class="tab-btn active" onclick="switchTab('tab-info')" id="btn-tab-info">ข้อมูลส่วนตัว</button>
    <button class="tab-btn" onclick="switchTab('tab-password')" id="btn-tab-password">เปลี่ยนรหัสผ่าน</button>
  </div>

  <!-- Tab: ข้อมูลส่วนตัว -->
  <div class="tab-panel active" id="tab-info">
    <div class="form-card">
      <div class="form-card-head">แก้ไขข้อมูลส่วนตัว</div>
      <div class="form-body">
        <form method="POST" enctype="multipart/form-data">
          <?= csrf_tag() ?>
          <input type="hidden" name="action" value="profile">

          <!-- Avatar upload -->
          <label for="avatarFile" style="display:block; margin-bottom:6px; font-size:13px; font-weight:600; color:var(--n700);">รูปโปรไฟล์</label>
          <div class="avatar-upload-area" onclick="document.getElementById('avatarFile').click();">
            <?php if ($avatarUrl): ?>
              <img class="preview-avatar" src="<?= htmlspecialchars($avatarUrl, ENT_QUOTES, 'UTF-8') ?>" id="previewImg" alt="">
            <?php else: ?>
              <div class="preview-init" id="previewImg"><?= htmlspecialchars($initials, ENT_QUOTES, 'UTF-8') ?></div>
            <?php endif; ?>
            <div class="upload-text">
              <strong>คลิกเพื่อเปลี่ยนรูปโปรไฟล์</strong>
              <span>JPG, PNG, WEBP · ไม่เกิน 3MB</span>
            </div>
          </div>
          <input type="file" id="avatarFile" name="avatar" accept="image/*" style="display:none;">

          <div class="form-row">
            <div class="form-group">
              <label>ชื่อที่แสดง</label>
              <input type="text" name="display_name" value="<?= htmlspecialchars($user['display_name'] ?? '', ENT_QUOTES, 'UTF-8') ?>" placeholder="ชื่อ-นามสกุล หรือชื่อเล่น" maxlength="100">
            </div>
            <div class="form-group">
              <label>ชื่อผู้ใช้</label>
              <input type="text" value="<?= htmlspecialchars($user['username'], ENT_QUOTES, 'UTF-8') ?>" disabled style="background:var(--n100); color:var(--n500); cursor:not-allowed;">
              <div class="form-hint">ไม่สามารถเปลี่ยนชื่อผู้ใช้ได้</div>
            </div>
          </div>

          <div class="form-row">
            <div class="form-group">
              <label>อีเมล</label>
              <input type="email" name="email" value="<?= htmlspecialchars($user['email'] ?? '', ENT_QUOTES, 'UTF-8') ?>" placeholder="email@example.com" maxlength="150">
              <input type="password" name="current_password" placeholder="รหัสผ่านปัจจุบัน (กรอกเมื่อเปลี่ยนอีเมล)" autocomplete="current-password" style="margin-top:8px;">
              <div class="form-hint">ถ้าเปลี่ยนอีเมล ต้องกรอกรหัสผ่านปัจจุบันเพื่อยืนยัน</div>
            </div>
            <div class="form-group">
              <label>เบอร์โทรศัพท์</label>
              <input type="tel" name="phone" value="<?= htmlspecialchars($user['phone'] ?? '', ENT_QUOTES, 'UTF-8') ?>" placeholder="0xx-xxx-xxxx" maxlength="20">
            </div>
          </div>

          <div class="form-group" style="margin-bottom:20px;">
            <label>แนะนำตัวเอง <span style="font-weight:400; color:var(--n500);">(ไม่บังคับ)</span></label>
            <textarea name="bio" rows="3" placeholder="เขียนแนะนำตัวเองสั้นๆ..." maxlength="300"><?= htmlspecialchars($user['bio'] ?? '', ENT_QUOTES, 'UTF-8') ?></textarea>
            <div class="form-hint"><span id="bioCount"><?= mb_strlen($user['bio'] ?? '') ?></span>/300 ตัวอักษร</div>
          </div>

          <div style="display:flex; gap:10px; align-items:center; flex-wrap:wrap;">
            <button type="submit" class="btn-save">บันทึกข้อมูล</button>
            <a href="address.php" style="font-size:13px; color:var(--primary); text-decoration:none; padding: 0 4px;">จัดการที่อยู่จัดส่ง →</a>
          </div>
        </form>
      </div>
    </div>
  </div>

  <!-- Tab: เปลี่ยนรหัสผ่าน -->
  <div class="tab-panel" id="tab-password">
    <div class="form-card danger-zone">
      <div class="danger-head">เปลี่ยนรหัสผ่าน</div>
      <div class="form-body">
        <form method="POST">
          <?= csrf_tag() ?>
          <input type="hidden" name="action" value="password">

          <div class="form-group" style="margin-bottom:16px;">
            <label>รหัสผ่านเดิม <span class="req">*</span></label>
            <input type="password" name="old_password" required placeholder="กรอกรหัสผ่านเดิม" autocomplete="current-password">
          </div>

          <div class="form-row">
            <div class="form-group">
              <label>รหัสผ่านใหม่ <span class="req">*</span></label>
              <input type="password" name="new_password" id="newPw" required placeholder="อย่างน้อย 6 ตัวอักษร" minlength="6" autocomplete="new-password">
            </div>
            <div class="form-group">
              <label>ยืนยันรหัสผ่านใหม่ <span class="req">*</span></label>
              <input type="password" name="conf_password" id="confPw" required placeholder="พิมพ์อีกครั้ง" autocomplete="new-password">
            </div>
          </div>

          <!-- strength bar -->
          <div style="margin-bottom:20px;">
            <div style="height:4px; background:var(--n200); border-radius:4px; overflow:hidden;">
              <div id="strengthBar" style="height:100%; width:0%; border-radius:4px; transition:width .3s, background .3s;"></div>
            </div>
            <div id="strengthText" style="font-size:12px; color:var(--n500); margin-top:4px;"></div>
          </div>

          <button type="submit" class="btn-save" style="background:var(--red,#c8102e);" onmouseover="this.style.background='#a00020'" onmouseout="this.style.background='var(--red,#c8102e)'">
            เปลี่ยนรหัสผ่าน
          </button>
        </form>
      </div>
    </div>
  </div>

</div>

<script>
/* ── Tabs ── */
function switchTab(id) {
  document.querySelectorAll('.tab-panel').forEach(p => p.classList.remove('active'));
  document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
  document.getElementById(id).classList.add('active');
  document.getElementById('btn-' + id).classList.add('active');
}

/* ── Avatar preview ── */
document.getElementById('avatarFile').addEventListener('change', function(){
  const file = this.files[0];
  if (!file) return;
  const reader = new FileReader();
  reader.onload = function(e){
    const prev = document.getElementById('previewImg');
    const hero = document.getElementById('heroAvatar');
    // แทนที่ด้วย img
    [prev, hero].forEach(el => {
      if (el.tagName === 'DIV') {
        const img = document.createElement('img');
        img.id = el.id;
        img.className = el.className.replace('avatar-init','avatar-img').replace('preview-init','preview-avatar');
        img.src = e.target.result;
        img.alt = 'preview';
        el.replaceWith(img);
      } else {
        el.src = e.target.result;
      }
    });
  };
  reader.readAsDataURL(file);
});

/* ── Bio counter ── */
const bioTa = document.querySelector('textarea[name="bio"]');
const bioCount = document.getElementById('bioCount');
if (bioTa) {
  bioTa.addEventListener('input', () => {
    bioCount.textContent = [...bioTa.value].length;
  });
}

/* ── Password strength ── */
const newPw = document.getElementById('newPw');
const bar   = document.getElementById('strengthBar');
const txt   = document.getElementById('strengthText');
if (newPw) {
  newPw.addEventListener('input', function(){
    const v = this.value;
    let score = 0;
    if (v.length >= 6)  score++;
    if (v.length >= 10) score++;
    if (/[A-Z]/.test(v)) score++;
    if (/[0-9]/.test(v)) score++;
    if (/[^A-Za-z0-9]/.test(v)) score++;
    const levels = [
      {w:'0%',   c:'#e9ecef', t:''},
      {w:'25%',  c:'#e24b4a', t:'อ่อนมาก'},
      {w:'50%',  c:'#ef9f27', t:'พอใช้'},
      {w:'75%',  c:'#378add', t:'ดี'},
      {w:'100%', c:'#1a7f4b', t:'แข็งแกร่งมาก'},
    ];
    const lv = levels[Math.min(score, 4)];
    bar.style.width = lv.w;
    bar.style.background = lv.c;
    txt.textContent = lv.t;
    txt.style.color = lv.c;
  });
}

/* ── Tab จาก query string ── */
const qs = new URLSearchParams(location.search);
if (qs.get('tab') === 'password') switchTab('tab-password');
</script>
</body>
</html>
