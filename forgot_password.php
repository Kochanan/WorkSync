<?php
/**
 * forgot_password.php
 * Step 1: กรอก email
 * Step 2: กรอก OTP 6 หลัก
 * Step 3: ตั้งรหัสผ่านใหม่
 */
require_once 'init.php';
require_once 'mailer.php';

if (isset($_SESSION['user_id'])) { header('Location: index.php'); exit; }

$step = (int)($_SESSION['fp_step'] ?? 1);
$err  = '';
$ok   = '';

/* ════════════════════════════════════════
   STEP 1 — กรอก Email
════════════════════════════════════════ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['step'] ?? '') === '1') {
    check_csrf();

    unset($_SESSION['fp_uid'], $_SESSION['fp_email'], $_SESSION['fp_step'], $_SESSION['fp_verified'], $_SESSION['fp_otp_fail']);

    $email = trim($_POST['email'] ?? '');

    if (strlen($email) > 150 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $err = 'รูปแบบอีเมลไม่ถูกต้อง';
        $step = 1;
    } elseif (($wait = otp_cooldown_left('fp_last_send')) > 0) {
        $err = "กรุณารอ $wait วินาทีก่อนขอรหัสใหม่";
        $step = 1;
    } else {
        $st = $conn->prepare("SELECT id, username FROM users WHERE email=? AND is_verified=1 ORDER BY id LIMIT 1");
        $st->bind_param('s', $email);
        $st->execute();
        $user = $st->get_result()->fetch_assoc();
        $st->close();

        // ตอบแบบเดียวกันเสมอ ไม่ว่าจะพบอีเมลหรือไม่ (กัน user enumeration)
        // เดิมถ้าไม่พบจะค้างที่ step 1 แต่ถ้าพบจะไป step 2 ทำให้ไล่เช็คได้ว่าอีเมลไหนมีบัญชี
        if ($user) {
            $code = create_otp($conn, (int)$user['id'], $email, 'reset');
            send_otp_email($email, $user['username'], $code, 'reset');
        }

        $_SESSION['fp_uid']       = $user ? (int)$user['id'] : 0;
        $_SESSION['fp_email']     = $email;
        $_SESSION['fp_step']      = 2;
        $_SESSION['fp_otp_fail']  = 0;
        $_SESSION['fp_last_send'] = time();
        $step = 2;
        $ok   = "หากอีเมล $email ลงทะเบียนไว้ เราได้ส่งรหัส OTP ไปให้แล้ว";
    }
}

/* ════════════════════════════════════════
   STEP 2 — ตรวจสอบ OTP
════════════════════════════════════════ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['step'] ?? '') === '2') {
    check_csrf();

    $uid  = (int)($_SESSION['fp_uid'] ?? 0);
    $code = trim(str_replace(' ', '', $_POST['otp'] ?? ''));

    // fp_uid = 0 แต่มี fp_email = กรอกอีเมลที่ไม่มีในระบบ → ทำทีเหมือนตรวจ OTP แล้วไม่ผ่าน
    if ($uid <= 0 && empty($_SESSION['fp_email'])) { header('Location: forgot_password.php'); exit; }

    if (strlen($code) !== 6 || !ctype_digit($code)) {
        $err = 'กรุณากรอกรหัส OTP 6 หลักให้ถูกต้อง';
        $step = 2;
    } elseif ((int)($_SESSION['fp_otp_fail'] ?? 0) >= OTP_MAX_ATTEMPTS) {
        $err = 'กรอกรหัส OTP ผิดเกินกำหนด กรุณากด "ส่งรหัสใหม่อีกครั้ง"';
        $step = 2;
    } elseif ($uid <= 0 || !verify_otp($conn, $uid, $code, 'reset')) {
        $err = otp_register_failure($conn, $uid, 'reset', 'fp_otp_fail')
            ? 'กรอกรหัส OTP ผิดเกินกำหนด รหัสนี้ถูกยกเลิกแล้ว กรุณากด "ส่งรหัสใหม่อีกครั้ง"'
            : 'รหัส OTP ไม่ถูกต้องหรือหมดอายุแล้ว';
        $step = 2;
    } else {
        // ยืนยันตัวตนสำเร็จ เปลี่ยน session id กัน session fixation ก่อนให้ตั้งรหัสใหม่
        session_regenerate_id(true);
        $_SESSION['fp_step']     = 3;
        $_SESSION['fp_verified'] = true;
        $step = 3;
        $ok   = 'ยืนยัน OTP สำเร็จ กรุณาตั้งรหัสผ่านใหม่';
    }
}

/* ════════════════════════════════════════
   STEP 3 — ตั้งรหัสผ่านใหม่
════════════════════════════════════════ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['step'] ?? '') === '3') {
    check_csrf();

    $uid      = (int)($_SESSION['fp_uid'] ?? 0);
    $verified = $_SESSION['fp_verified'] ?? false;
    $newPw    = $_POST['new_password']  ?? '';
    $confPw   = $_POST['conf_password'] ?? '';

    if ($uid <= 0 || $verified !== true) { header('Location: forgot_password.php'); exit; }

    if (strlen($newPw) < 6) {
        $err = 'รหัสผ่านต้องมีอย่างน้อย 6 ตัวอักษร';
        $step = 3;
    } elseif (strlen($newPw) > 72) {
        $err = 'รหัสผ่านยาวเกินไป (สูงสุด 72 ตัวอักษร)';
        $step = 3;
    } elseif ($newPw !== $confPw) {
        $err = 'รหัสผ่านไม่ตรงกัน';
        $step = 3;
    } else {
        $hash = password_hash($newPw, PASSWORD_DEFAULT);
        $upd  = $conn->prepare("UPDATE users SET password=?, updated_at=NOW() WHERE id=?");
        $upd->bind_param('si', $hash, $uid);
        $upd->execute();
        $upd->close();

        // เผื่อมี OTP รีเซ็ตค้างอยู่ ยกเลิกทิ้งทั้งหมด
        $del = $conn->prepare("DELETE FROM otp_codes WHERE user_id=? AND type='reset'");
        $del->bind_param('i', $uid);
        $del->execute();
        $del->close();

        // ล้าง session
        unset($_SESSION['fp_uid'], $_SESSION['fp_email'], $_SESSION['fp_step'], $_SESSION['fp_verified'], $_SESSION['fp_otp_fail']);

        header('Location: login.php?reset=1');
        exit;
    }
}

/* ════════════════════════════════════════
   RESEND OTP
════════════════════════════════════════ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['step'] ?? '') === 'resend') {
    check_csrf();
    $uid   = (int)($_SESSION['fp_uid'] ?? 0);
    $email = $_SESSION['fp_email'] ?? '';
    if ($email && ($wait = otp_cooldown_left('fp_last_send')) > 0) {
        $err  = "กรุณารอ $wait วินาทีก่อนขอรหัสใหม่";
        $step = 2;
    } elseif ($email) {
        if ($uid > 0) {
            $st = $conn->prepare("SELECT username FROM users WHERE id=? LIMIT 1");
            $st->bind_param('i', $uid);
            $st->execute();
            $u = $st->get_result()->fetch_assoc();
            $st->close();
            $code = create_otp($conn, $uid, $email, 'reset');
            send_otp_email($email, $u['username'] ?? '', $code, 'reset');
        }
        $_SESSION['fp_otp_fail']  = 0;
        $_SESSION['fp_last_send'] = time();
        $ok = 'ส่งรหัส OTP ใหม่แล้ว';
        $step = 2;
    }
}

$step = (int)($_SESSION['fp_step'] ?? $step);
if ($err && $step === 1) unset($_SESSION['fp_step']);
?>
<!doctype html>
<html lang="th">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <link rel="stylesheet" href="assets/style.css?v=<?= @filemtime(__DIR__.'/assets/style.css') ?>">
  <title>WorkSync — ลืมรหัสผ่าน</title>
  <style>
    .auth-page {
      min-height: calc(100vh - var(--nav-h));
      display: flex; align-items: center; justify-content: center;
      padding: 24px 16px;
    }
    .auth-card {
      width: 100%; max-width: 440px;
      background: var(--surface, #fff);
      border-radius: var(--r-xl, 28px);
      box-shadow: var(--sh-3, 0 8px 28px rgba(13,33,55,.12));
      overflow: hidden;
    }
    .auth-header {
      background: linear-gradient(135deg, var(--brand, #0d2137) 0%, var(--brand-light, #2756a0) 100%);
      padding: 28px 32px 24px; position: relative; overflow: hidden;
    }
    .auth-header::before {
      content: ''; position: absolute; right: -30px; top: -30px;
      width: 140px; height: 140px; border-radius: 50%;
      background: rgba(255,255,255,.07); pointer-events: none;
    }
    .auth-header h1 { font-size: 20px; font-weight: 700; color: #fff; margin: 0; }
    .auth-header p  { font-size: 13px; color: rgba(255,255,255,.7); margin: 5px 0 0; }
    .auth-body { padding: 28px 28px 32px; }

    /* Step bar */
    .step-bar { display: flex; align-items: center; margin-bottom: 24px; }
    .step-dot {
      width: 28px; height: 28px; border-radius: 50%;
      display: flex; align-items: center; justify-content: center;
      font-size: 12px; font-weight: 700; flex-shrink: 0;
      background: var(--border, rgba(0,0,0,.07)); color: var(--tx4, #b0bec9);
      transition: all .2s;
    }
    .step-dot.done   { background: var(--green, #0f9b5e); color: #fff; }
    .step-dot.active { background: linear-gradient(135deg, var(--brand-light, #2756a0), var(--brand, #0d2137)); color: #fff; }
    .step-line { flex: 1; height: 2px; background: var(--border, rgba(0,0,0,.07)); margin: 0 6px; }
    .step-line.done { background: var(--green, #0f9b5e); }

    /* Form */
    .fgroup { margin-bottom: 16px; }
    .fgroup label { display: block; font-size: 12.5px; font-weight: 600; color: var(--tx2, #3d5066); margin-bottom: 6px; }
    .fgroup input {
      width: 100%; height: 44px; padding: 0 14px;
      border: 1.5px solid rgba(13,33,55,.1);
      border-radius: var(--r-sm, 8px); font-family: var(--font);
      font-size: 14px; color: var(--tx1, #0d1f35); background: #fff;
      transition: border-color .15s, box-shadow .15s; box-sizing: border-box;
    }
    .fgroup input:focus {
      outline: none; border-color: var(--brand-light, #2756a0);
      box-shadow: 0 0 0 3px rgba(39,86,160,.1);
    }

    /* OTP boxes */
    .otp-wrap { display: flex; gap: 10px; justify-content: center; margin: 20px 0; }
    .otp-wrap input {
      width: 48px; height: 56px;
      border: 2px solid rgba(13,33,55,.12);
      border-radius: var(--r-sm, 8px);
      font-family: var(--font-mono, monospace);
      font-size: 22px; font-weight: 700; text-align: center;
      color: var(--tx1, #0d1f35); background: #fff;
      transition: border-color .15s, box-shadow .15s;
      -moz-appearance: textfield;
    }
    .otp-wrap input::-webkit-inner-spin-button,
    .otp-wrap input::-webkit-outer-spin-button { -webkit-appearance: none; }
    .otp-wrap input:focus {
      outline: none; border-color: var(--brand-light, #2756a0);
      box-shadow: 0 0 0 3px rgba(39,86,160,.1);
    }
    .otp-wrap input.filled { border-color: var(--brand-light, #2756a0); background: #e8f0f9; }

    /* Button */
    .btn-auth {
      width: 100%; height: 46px;
      background: linear-gradient(135deg, var(--brand-light, #2756a0) 0%, var(--brand, #0d2137) 100%);
      color: #fff; border: none; border-radius: var(--r-sm, 8px);
      font-family: var(--font); font-size: 15px; font-weight: 700;
      cursor: pointer; box-shadow: 0 3px 12px rgba(13,33,55,.22);
      transition: transform .15s, box-shadow .15s; margin-top: 8px;
    }
    .btn-auth:hover { transform: translateY(-1px); box-shadow: 0 6px 20px rgba(13,33,55,.28); }
    .btn-auth:active { transform: scale(.98); }

    /* Alert */
    .alert { padding: 11px 14px; border-radius: var(--r-sm, 8px); font-size: 13px; font-weight: 600; margin-bottom: 16px; display: flex; align-items: flex-start; gap: 8px; }
    .alert-err { background: #fdf0f2; color: #c8102e; }
    .alert-ok  { background: #eaf6f0; color: #0f9b5e; }

    .email-hint { text-align: center; font-size: 13px; color: var(--tx3, #7a8fa6); margin-bottom: 8px; line-height: 1.6; }
    .email-highlight { display: inline-block; background: #f0f2f6; border: 1px solid rgba(0,0,0,.1); padding: 2px 10px; border-radius: 6px; font-weight: 600; color: var(--tx1, #0d1f35); }

    .resend-row { text-align: center; margin-top: 14px; }
    .resend-btn { background: none; border: none; color: var(--brand-light, #2756a0); font-size: 13px; font-weight: 600; cursor: pointer; font-family: var(--font); padding: 4px 0; }
    .resend-btn:hover { text-decoration: underline; }

    .auth-footer { text-align: center; margin-top: 20px; font-size: 13px; color: var(--tx3, #7a8fa6); }
    .auth-footer a { color: var(--brand-light, #2756a0); font-weight: 600; }

    .pw-strength { height: 4px; border-radius: 2px; background: rgba(0,0,0,.08); margin-top: 6px; overflow: hidden; }
    .pw-strength-bar { height: 100%; border-radius: 2px; width: 0; transition: width .3s, background .3s; }
  </style>
</head>
<body>
<?php include 'navbar.php'; ?>

<div class="auth-page">
  <div class="auth-card">
    <div class="auth-header">
      <?php if ($step === 1): ?>
        <h1>ลืมรหัสผ่าน?</h1>
        <p>กรอกอีเมลที่ลงทะเบียนไว้ เราจะส่ง OTP ให้</p>
      <?php elseif ($step === 2): ?>
        <h1>ยืนยัน OTP</h1>
        <p>กรอกรหัส 6 หลักที่ส่งไปยังอีเมลของคุณ</p>
      <?php else: ?>
        <h1>ตั้งรหัสผ่านใหม่</h1>
        <p>เลือกรหัสผ่านที่แข็งแกร่งและจำได้ง่าย</p>
      <?php endif; ?>
    </div>

    <div class="auth-body">

      <!-- Step bar -->
      <div class="step-bar">
        <div class="step-dot <?= $step >= 1 ? 'done' : 'active' ?>">
          <?= $step > 1 ? '✓' : '1' ?>
        </div>
        <div class="step-line <?= $step > 1 ? 'done' : '' ?>"></div>
        <div class="step-dot <?= $step === 2 ? 'active' : ($step > 2 ? 'done' : '') ?>">
          <?= $step > 2 ? '✓' : '2' ?>
        </div>
        <div class="step-line <?= $step > 2 ? 'done' : '' ?>"></div>
        <div class="step-dot <?= $step === 3 ? 'active' : '' ?>">3</div>
      </div>

      <?php if ($err): ?>
        <div class="alert alert-err">✕ <?= htmlspecialchars($err, ENT_QUOTES, 'UTF-8') ?></div>
      <?php endif; ?>
      <?php if ($ok): ?>
        <div class="alert alert-ok">✓ <?= htmlspecialchars($ok, ENT_QUOTES, 'UTF-8') ?></div>
      <?php endif; ?>

      <!-- ══ STEP 1 — Email ══ -->
      <?php if ($step === 1): ?>
      <form method="POST">
        <?= csrf_tag() ?>
        <input type="hidden" name="step" value="1">
        <div class="fgroup">
          <label>อีเมลที่ลงทะเบียนไว้</label>
          <input type="email" name="email" placeholder="example@email.com"
                 value="<?= htmlspecialchars($_POST['email'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                 autocomplete="email" required autofocus>
        </div>
        <button type="submit" class="btn-auth">ส่งรหัส OTP</button>
      </form>

      <!-- ══ STEP 2 — OTP ══ -->
      <?php elseif ($step === 2): ?>
      <div class="email-hint">
        ส่งรหัส OTP ไปที่<br>
        <span class="email-highlight"><?= htmlspecialchars($_SESSION['fp_email'] ?? '', ENT_QUOTES, 'UTF-8') ?></span>
      </div>

      <form method="POST" id="otpForm">
        <?= csrf_tag() ?>
        <input type="hidden" name="step" value="2">
        <input type="hidden" name="otp" id="otpHidden">
        <div class="otp-wrap" id="otpBoxes">
          <?php for ($i = 0; $i < 6; $i++): ?>
            <input type="tel" maxlength="1" class="otp-digit"
                   inputmode="numeric" pattern="[0-9]" autocomplete="one-time-code">
          <?php endfor; ?>
        </div>
        <button type="submit" class="btn-auth">ยืนยัน OTP</button>
      </form>

      <div class="resend-row">
        <form method="POST" style="display:inline;">
          <?= csrf_tag() ?>
          <input type="hidden" name="step" value="resend">
          <button class="resend-btn" type="submit">ส่งรหัสใหม่อีกครั้ง</button>
        </form>
        <span style="color:var(--tx4,#b0bec9); font-size:12px;"> · ใช้ได้ 10 นาที</span>
      </div>

      <!-- ══ STEP 3 — New Password ══ -->
      <?php else: ?>
      <form method="POST">
        <?= csrf_tag() ?>
        <input type="hidden" name="step" value="3">
        <div class="fgroup">
          <label>รหัสผ่านใหม่</label>
          <input type="password" name="new_password" id="pwNew"
                 placeholder="อย่างน้อย 6 ตัวอักษร"
                 autocomplete="new-password" required autofocus>
          <div class="pw-strength"><div class="pw-strength-bar" id="pwBar"></div></div>
        </div>
        <div class="fgroup">
          <label>ยืนยันรหัสผ่านใหม่</label>
          <input type="password" name="conf_password"
                 placeholder="พิมพ์อีกครั้ง"
                 autocomplete="new-password" required>
        </div>
        <button type="submit" class="btn-auth">บันทึกรหัสผ่านใหม่</button>
      </form>
      <?php endif; ?>

      <div class="auth-footer">
        <a href="login.php">← กลับไปหน้าเข้าสู่ระบบ</a>
      </div>
    </div>
  </div>
</div>

<script>
/* OTP boxes */
(function(){
  const digits = document.querySelectorAll('.otp-digit');
  const hidden = document.getElementById('otpHidden');
  const form   = document.getElementById('otpForm');
  if (!digits.length) return;

  function sync(){
    let val = '';
    digits.forEach(d => { val += d.value; d.classList.toggle('filled', d.value !== ''); });
    if (hidden) hidden.value = val;
  }

  digits.forEach((d, i) => {
    d.addEventListener('input', function(){
      this.value = this.value.replace(/\D/g,'').slice(-1);
      sync();
      if (this.value && i < digits.length - 1) digits[i+1].focus();
      let full = ''; digits.forEach(x => full += x.value);
      if (full.length === 6 && form) form.requestSubmit();
    });
    d.addEventListener('keydown', function(e){
      if (e.key === 'Backspace' && !this.value && i > 0) {
        digits[i-1].value = ''; digits[i-1].focus(); sync();
      }
    });
    d.addEventListener('paste', function(e){
      e.preventDefault();
      const txt = (e.clipboardData || window.clipboardData).getData('text').replace(/\D/g,'');
      txt.split('').slice(0,6).forEach((c,j) => { if(digits[j]) digits[j].value = c; });
      sync();
      digits[Math.min(txt.length,5)].focus();
    });
  });
  if (digits[0]) digits[0].focus();
})();

/* Password strength */
const pw = document.getElementById('pwNew');
const bar = document.getElementById('pwBar');
if (pw && bar) {
  pw.addEventListener('input', function(){
    const v = this.value;
    let s = 0;
    if (v.length >= 6)  s++;
    if (v.length >= 10) s++;
    if (/[A-Z]/.test(v)) s++;
    if (/[0-9]/.test(v)) s++;
    if (/[^A-Za-z0-9]/.test(v)) s++;
    const c = ['','#e53935','#ef9f27','#1a73e8','#0f9b5e'];
    const w = ['0%','25%','50%','75%','100%'];
    bar.style.width = w[Math.min(s,4)];
    bar.style.background = c[Math.min(s,4)];
  });
}
</script>
</body>
</html>
