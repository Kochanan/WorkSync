<?php
/**
 * register.php — สมัครสมาชิก + ยืนยัน OTP ทาง Email
 * Step 1: กรอก username, email, password
 * Step 2: กรอก OTP 6 หลักที่ส่งไปทาง email
 */
require_once 'init.php';
require_once 'mailer.php';

if (isset($_SESSION['user_id'])) {
    header('Location: index.php');
    exit;
}

$step = (int)($_SESSION['reg_step'] ?? 1);
$err  = '';
$ok   = '';

/* ════════════════════════════════════════
   STEP 1 — กรอกข้อมูล + ส่ง OTP
════════════════════════════════════════ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['step'] ?? '') === '1') {
    check_csrf();

    $username = trim($_POST['username'] ?? '');
    $email    = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirm  = $_POST['confirm']  ?? '';

    // ล้าง session เก่าทุกครั้งที่ submit step 1 ใหม่
    unset($_SESSION['reg_uid'], $_SESSION['reg_email'], $_SESSION['reg_name'], $_SESSION['reg_step']);

    // Validate
    if ($username === '' || $email === '' || $password === '') {
        $err = 'กรุณากรอกข้อมูลให้ครบทุกช่อง';
    } elseif (!preg_match('/^[A-Za-z0-9_.\-]{3,50}$/', $username)) {
        // กันชื่อแปลก ๆ / ยาวเกินคอลัมน์ (เดิมยาวเกินจะทำให้ mysqli โยน exception หน้าขาว)
        $err = 'ชื่อผู้ใช้ต้องเป็นภาษาอังกฤษ ตัวเลข หรือ _ . - ความยาว 3–50 ตัวอักษร';
    } elseif (strlen($email) > 150 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $err = 'รูปแบบอีเมลไม่ถูกต้อง';
    } elseif (strlen($password) > 72) {
        // bcrypt ใช้แค่ 72 ไบต์แรก ยาวกว่านี้จะถูกตัดทิ้งเงียบ ๆ
        $err = 'รหัสผ่านยาวเกินไป (สูงสุด 72 ตัวอักษร)';
    } elseif (otp_cooldown_left('reg_last_send') > 0) {
        $err = 'กรุณารอ ' . otp_cooldown_left('reg_last_send') . ' วินาทีก่อนสมัครใหม่อีกครั้ง';
    } elseif (strlen($password) < 6) {
        $err = 'รหัสผ่านต้องมีอย่างน้อย 6 ตัวอักษร';
    } elseif ($password !== $confirm) {
        $err = 'รหัสผ่านไม่ตรงกัน';
    } else {
        // ล้างบัญชีที่สมัครค้างไว้แต่ไม่เคยยืนยัน OTP (เกินอายุ OTP แล้ว)
        // ไม่งั้นใครก็จองชื่อผู้ใช้/อีเมลของคนอื่นทิ้งไว้ได้ถาวร
        $stale = $conn->prepare(
            "SELECT id FROM users
             WHERE is_verified=0 AND (username=? OR email=?)
               AND created_at < NOW() - INTERVAL 15 MINUTE"
        );
        $stale->bind_param('ss', $username, $email);
        $stale->execute();
        $staleIds = array_column($stale->get_result()->fetch_all(MYSQLI_ASSOC), 'id');
        $stale->close();
        foreach ($staleIds as $sid) {
            $sid = (int)$sid;
            $d1 = $conn->prepare("DELETE FROM otp_codes WHERE user_id=?");
            $d1->bind_param('i', $sid);
            $d1->execute();
            $d1->close();
            $d2 = $conn->prepare("DELETE FROM users WHERE id=? AND is_verified=0");
            $d2->bind_param('i', $sid);
            $d2->execute();
            $d2->close();
        }

        // เช็ค username ซ้ำ
        $chk = $conn->prepare("SELECT id FROM users WHERE username=? LIMIT 1");
        $chk->bind_param('s', $username);
        $chk->execute();
        if ($chk->get_result()->fetch_assoc()) {
            $err = 'ชื่อผู้ใช้นี้ถูกใช้งานแล้ว';
        }
        $chk->close();

        // เช็ค email ซ้ำ
        if ($err === '') {
            $chk2 = $conn->prepare("SELECT id FROM users WHERE email=? LIMIT 1");
            $chk2->bind_param('s', $email);
            $chk2->execute();
            if ($chk2->get_result()->fetch_assoc()) {
                $err = 'อีเมลนี้ถูกใช้งานแล้ว';
            }
            $chk2->close();
        }

        if ($err === '') {
            // สร้าง user แบบ unverified
            $hash = password_hash($password, PASSWORD_DEFAULT);
            $ins  = $conn->prepare(
                "INSERT INTO users (username, email, password, is_verified) VALUES (?,?,?,0)"
            );
            $ins->bind_param('sss', $username, $email, $hash);
            $ins->execute();
            $newId = (int)$ins->insert_id;
            $ins->close();

            // สร้างและส่ง OTP
            $code = create_otp($conn, $newId, $email, 'verify');
            $sent = send_otp_email($email, $username, $code, 'verify');

            // เก็บ session ชั่วคราว
            $_SESSION['reg_uid']   = $newId;
            $_SESSION['reg_email'] = $email;
            $_SESSION['reg_name']  = $username;
            $_SESSION['reg_step']  = 2;
            $_SESSION['reg_otp_fail']  = 0;
            $_SESSION['reg_last_send'] = time();

            $step = 2;
            // ห้ามโชว์รหัส OTP บนหน้าเว็บ — เดิมถ้าส่งเมลไม่ได้จะโชว์รหัสออกมา
            // ทำให้สมัครด้วยอีเมลของคนอื่นแล้วยืนยันเองได้ (ข้ามการยืนยันอีเมล)
            $ok   = $sent
                ? "ส่งรหัส OTP ไปที่ $email แล้ว กรุณาตรวจสอบกล่องจดหมาย"
                : "สร้างบัญชีแล้ว แต่ระบบส่งอีเมลขัดข้อง กรุณากด \"ส่งรหัสใหม่อีกครั้ง\" ภายหลัง";
        }
    }
}

/* ════════════════════════════════════════
   STEP 2 — ตรวจสอบ OTP
════════════════════════════════════════ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['step'] ?? '') === '2') {
    check_csrf();

    $code  = trim(str_replace(' ', '', $_POST['otp'] ?? ''));
    $uid   = (int)($_SESSION['reg_uid'] ?? 0);
    $email = $_SESSION['reg_email'] ?? '';

    if ($uid <= 0 || $email === '') {
        header('Location: register.php');
        exit;
    }

    if (strlen($code) !== 6 || !ctype_digit($code)) {
        $err  = 'กรุณากรอกรหัส OTP 6 หลักให้ถูกต้อง';
        $step = 2;
    } elseif ((int)($_SESSION['reg_otp_fail'] ?? 0) >= OTP_MAX_ATTEMPTS) {
        $err  = 'กรอกรหัส OTP ผิดเกินกำหนด กรุณากด "ส่งรหัสใหม่อีกครั้ง"';
        $step = 2;
    } elseif (!verify_otp($conn, $uid, $code, 'verify')) {
        $err  = otp_register_failure($conn, $uid, 'verify', 'reg_otp_fail')
            ? 'กรอกรหัส OTP ผิดเกินกำหนด รหัสนี้ถูกยกเลิกแล้ว กรุณากด "ส่งรหัสใหม่อีกครั้ง"'
            : 'รหัส OTP ไม่ถูกต้องหรือหมดอายุแล้ว';
        $step = 2;
    } else {
        // ยืนยันสำเร็จ
        $upd = $conn->prepare("UPDATE users SET is_verified=1 WHERE id=?");
        $upd->bind_param('i', $uid);
        $upd->execute();
        $upd->close();

        // ล้าง session ชั่วคราว
        unset($_SESSION['reg_uid'], $_SESSION['reg_email'], $_SESSION['reg_name'], $_SESSION['reg_step'], $_SESSION['reg_otp_fail']);

        header('Location: login.php?verified=1');
        exit;
    }
}

/* ════════════════════════════════════════
   RESEND OTP
════════════════════════════════════════ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['step'] ?? '') === 'resend') {
    check_csrf();
    $uid   = (int)($_SESSION['reg_uid'] ?? 0);
    $email = $_SESSION['reg_email'] ?? '';
    $name  = $_SESSION['reg_name'] ?? '';

    if ($uid > 0 && $email) {
        $wait = otp_cooldown_left('reg_last_send');
        if ($wait > 0) {
            $err = "กรุณารอ $wait วินาทีก่อนขอรหัสใหม่";
        } else {
            $code = create_otp($conn, $uid, $email, 'verify');
            $sent = send_otp_email($email, $name, $code, 'verify');
            $_SESSION['reg_otp_fail']  = 0;
            $_SESSION['reg_last_send'] = time();
            if ($sent) {
                $ok = 'ส่งรหัส OTP ใหม่แล้ว';
            } else {
                $err = 'ระบบส่งอีเมลขัดข้อง กรุณาลองใหม่ภายหลัง';
            }
        }
        $step = 2;
    }
}

// ถ้ามี error ใน step 1 ให้ยืนยัน step=1 เสมอ
// ถ้า error เกิดขณะ session บอก step=2 ให้ยึด session
if ($err !== '' && empty($_SESSION['reg_uid'])) {
    $step = 1;
} else {
    $step = (int)($_SESSION['reg_step'] ?? $step);
}
?>
<!doctype html>
<html lang="th">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <link rel="stylesheet" href="assets/style.css?v=<?= @filemtime(__DIR__.'/assets/style.css') ?>">
  <title>WorkSync — สมัครสมาชิก</title>
  <style>
    .auth-page {
      min-height: calc(100vh - var(--nav-h));
      display: flex; align-items: center; justify-content: center;
      padding: 24px 16px;
    }
    .auth-card {
      width: 100%; max-width: 440px;
      background: var(--surface);
      border-radius: var(--r-xl);
      box-shadow: var(--sh-3);
      overflow: hidden;
    }
    .auth-header {
      background: linear-gradient(135deg, var(--brand) 0%, var(--brand-light) 100%);
      padding: 28px 32px 24px;
      position: relative; overflow: hidden;
    }
    .auth-header::before {
      content: '';
      position: absolute; right: -30px; top: -30px;
      width: 140px; height: 140px; border-radius: 50%;
      background: rgba(255, 255, 255, 0.07); pointer-events: none;
    }
    .auth-header h1 { font-size: 20px; font-weight: 700; color: #fff; margin: 0; }
    .auth-header p  { font-size: 13px; color: rgba(255,255,255,.7); margin: 5px 0 0; }

    .auth-body { padding: 28px 28px 32px; }

    .step-bar {
      display: flex; align-items: center; gap: 0;
      margin-bottom: 24px;
    }
    .step-dot {
      width: 28px; height: 28px; border-radius: 50%;
      display: flex; align-items: center; justify-content: center;
      font-size: 12px; font-weight: 700; flex-shrink: 0;
      background: var(--border); color: var(--tx4);
      transition: all .2s;
    }
    .step-dot.done   { background: var(--green); color: #fff; }
    .step-dot.active { background: linear-gradient(135deg,var(--brand-light),var(--brand)); color: #fff; }
    .step-line { flex: 1; height: 2px; background: var(--border); margin: 0 6px; }
    .step-line.done { background: var(--green); }
    .step-label { font-size: 11px; color: var(--tx4); text-align: center; margin-top: 4px; }

    .fgroup { margin-bottom: 16px; }
    .fgroup label {
      display: block; font-size: 12.5px; font-weight: 600;
      color: var(--tx2); margin-bottom: 6px;
    }
    .fgroup .req { color: var(--accent); }
    .fgroup input {
      width: 100%; height: 44px; padding: 0 14px;
      border: 1.5px solid rgba(13,33,55,.1);
      border-radius: var(--r-sm); font-family: var(--font);
      font-size: 14px; color: var(--tx1); background: #fff;
      transition: border-color .15s, box-shadow .15s;
      box-sizing: border-box;
    }
    .fgroup input:focus {
      outline: none; border-color: var(--brand-light);
      box-shadow: 0 0 0 3px rgba(39,86,160,.1);
    }

    /* OTP input */
    .otp-wrap { display: flex; gap: 10px; justify-content: center; margin: 20px 0; }
    .otp-wrap input {
      width: 48px; height: 56px;
      border: 2px solid rgba(13,33,55,.12);
      border-radius: var(--r-sm); font-family: var(--font-mono);
      font-size: 22px; font-weight: 700; text-align: center;
      color: var(--tx1); background: #fff;
      transition: border-color .15s, box-shadow .15s;
      -moz-appearance: textfield;
    }
    .otp-wrap input::-webkit-inner-spin-button,
    .otp-wrap input::-webkit-outer-spin-button { -webkit-appearance: none; }
    .otp-wrap input:focus {
      outline: none; border-color: var(--brand-light);
      box-shadow: 0 0 0 3px rgba(39,86,160,.1);
    }
    .otp-wrap input.filled { border-color: var(--brand-light); background: var(--primary-light,#e8f0f9); }

    .btn-auth {
      width: 100%; height: 46px;
      background: linear-gradient(135deg, var(--brand-light) 0%, var(--brand) 100%);
      color: #fff; border: none; border-radius: var(--r-sm);
      font-family: var(--font); font-size: 15px; font-weight: 700;
      cursor: pointer; box-shadow: 0 3px 12px rgba(13,33,55,.22);
      transition: transform .15s, box-shadow .15s; margin-top: 8px;
    }
    .btn-auth:hover { transform: translateY(-1px); box-shadow: 0 6px 20px rgba(13,33,55,.28); }
    .btn-auth:active { transform: scale(.98); }

    .alert { padding: 11px 14px; border-radius: var(--r-sm); font-size: 13px; font-weight: 600; margin-bottom: 16px; display: flex; align-items: flex-start; gap: 8px; }
    .alert-err { background: var(--red-bg); color: var(--red); }
    .alert-ok  { background: var(--green-bg); color: var(--green); }

    .auth-footer { text-align: center; margin-top: 20px; font-size: 13px; color: var(--tx3); }
    .auth-footer a { color: var(--brand-light); font-weight: 600; }

    .email-hint {
      text-align: center; font-size: 13px; color: var(--tx3);
      margin-bottom: 8px; line-height: 1.6;
    }
    .email-highlight {
      display: inline-block; background: var(--surface2);
      border: 1px solid var(--border-md);
      padding: 2px 10px; border-radius: 6px;
      font-weight: 600; color: var(--tx1); margin: 2px 0;
    }

    .resend-row { text-align: center; margin-top: 14px; }
    .resend-btn {
      background: none; border: none; color: var(--brand-light);
      font-size: 13px; font-weight: 600; cursor: pointer;
      font-family: var(--font); padding: 4px 0;
    }
    .resend-btn:hover { text-decoration: underline; }

    .pw-strength { height: 4px; border-radius: 2px; background: var(--border); margin-top: 6px; overflow: hidden; }
    .pw-strength-bar { height: 100%; border-radius: 2px; width: 0; transition: width .3s, background .3s; }
  </style>
</head>
<body>
<?php include 'navbar.php'; ?>

<div class="auth-page">
  <div class="auth-card">

    <div class="auth-header">
      <h1><?= $step === 1 ? 'สร้างบัญชีใหม่' : 'ยืนยันอีเมล' ?></h1>
      <p><?= $step === 1 ? 'กรอกข้อมูลเพื่อเริ่มใช้งาน WorkSync' : 'กรอกรหัส OTP ที่ส่งไปยังอีเมลของคุณ' ?></p>
    </div>

    <div class="auth-body">

      <!-- Step indicator -->
      <div>
        <div class="step-bar">
          <div class="step-dot <?= $step >= 1 ? 'done' : '' ?>">
            <?= $step > 1 ? '✓' : '1' ?>
          </div>
          <div class="step-line <?= $step > 1 ? 'done' : '' ?>"></div>
          <div class="step-dot <?= $step === 2 ? 'active' : '' ?>">2</div>
        </div>
        <div style="display:flex; justify-content:space-between;">
          <span class="step-label">ข้อมูล</span>
          <span class="step-label">ยืนยัน OTP</span>
        </div>
      </div>

      <?php if ($err): ?>
        <div class="alert alert-err">⚠ <?= htmlspecialchars($err, ENT_QUOTES, 'UTF-8') ?></div>
      <?php endif; ?>
      <?php if ($ok): ?>
        <div class="alert alert-ok">✓ <?= htmlspecialchars($ok, ENT_QUOTES, 'UTF-8') ?></div>
      <?php endif; ?>

      <!-- ══ STEP 1 ══ -->
      <?php if ($step === 1): ?>
      <form method="POST" id="regForm">
        <?= csrf_tag() ?>
        <input type="hidden" name="step" value="1">

        <div class="fgroup">
          <label>ชื่อผู้ใช้ <span class="req">*</span></label>
          <input type="text" name="username" placeholder="ตั้งชื่อผู้ใช้ (ภาษาอังกฤษ/ตัวเลข)"
                 value="<?= htmlspecialchars($_POST['username'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                 autocomplete="username" maxlength="50" required>
        </div>

        <div class="fgroup">
          <label>อีเมล <span class="req">*</span></label>
          <input type="email" name="email" placeholder="example@email.com"
                 value="<?= htmlspecialchars($_POST['email'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                 autocomplete="email" required>
        </div>

        <div class="fgroup">
          <label>รหัสผ่าน <span class="req">*</span></label>
          <div style="position:relative;">
            <input type="password" name="password" id="pwInput"
                   placeholder="อย่างน้อย 6 ตัวอักษร"
                   autocomplete="new-password" required
                   style="padding-right:44px; width:100%; box-sizing:border-box;">
            <button type="button" onclick="togglePw('pwInput','eye1')"
                    style="position:absolute;right:12px;top:50%;transform:translateY(-50%);
                           background:none;border:none;cursor:pointer;font-size:16px;color:#888;">
              <span id="eye1">👁️</span>
            </button>
          </div>
          <div class="pw-strength"><div class="pw-strength-bar" id="pwBar"></div></div>
        </div>

        <div class="fgroup">
          <label>ยืนยันรหัสผ่าน <span class="req">*</span></label>
          <div style="position:relative;">
            <input type="password" name="confirm" id="pwConfirm"
                   placeholder="พิมพ์รหัสผ่านอีกครั้ง"
                   autocomplete="new-password" required
                   style="padding-right:44px; width:100%; box-sizing:border-box;">
            <button type="button" onclick="togglePw('pwConfirm','eye2')"
                    style="position:absolute;right:12px;top:50%;transform:translateY(-50%);
                           background:none;border:none;cursor:pointer;font-size:16px;color:#888;">
              <span id="eye2">👁️</span>
            </button>
          </div>
        </div>

        <button type="submit" class="btn-auth">ถัดไป — รับ OTP ทางอีเมล</button>
      </form>

      <!-- ══ STEP 2 ══ -->
      <?php else: ?>
      <div class="email-hint">
        ส่งรหัส OTP ไปที่<br>
        <span class="email-highlight"><?= htmlspecialchars($_SESSION['reg_email'] ?? '', ENT_QUOTES, 'UTF-8') ?></span>
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
        <span style="color:var(--tx4); font-size:12px;"> · ใช้ได้ 10 นาที</span>
      </div>
      <?php endif; ?>

      <div class="auth-footer">
        มีบัญชีอยู่แล้ว? <a href="login.php">เข้าสู่ระบบ</a>
      </div>
    </div>
  </div>
</div>

<script>
/* Password strength */
const pw = document.getElementById('pwInput');
const bar = document.getElementById('pwBar'); // เดิมไม่ได้ประกาศ ทำให้ JS error ทุกครั้งที่พิมพ์รหัสผ่าน
function togglePw(inputId, eyeId) {
  const input = document.getElementById(inputId);
  const eye   = document.getElementById(eyeId);
  if (input.type === 'password') { input.type = 'text'; eye.textContent = '🙈'; }
  else { input.type = 'password'; eye.textContent = '👁️'; }
}

if (pw && bar) {
  pw.addEventListener('input', function(){
    const v = this.value;
    let s = 0;
    if (v.length >= 6)  s++;
    if (v.length >= 10) s++;
    if (/[A-Z]/.test(v)) s++;
    if (/[0-9]/.test(v)) s++;
    if (/[^A-Za-z0-9]/.test(v)) s++;
    const colors = ['','#e53935','#ef9f27','#1a73e8','#0f9b5e'];
    const widths = ['0%','25%','50%','75%','100%'];
    bar.style.width = widths[Math.min(s,4)];
    bar.style.background = colors[Math.min(s,4)];
  });
}

/* OTP boxes */
(function(){
  const digits = document.querySelectorAll('.otp-digit');
  const hidden = document.getElementById('otpHidden');
  const form   = document.getElementById('otpForm');
  if (!digits.length) return;

  function updateHidden(){
    let val = '';
    digits.forEach(d => val += d.value);
    if (hidden) hidden.value = val;
    digits.forEach(d => d.classList.toggle('filled', d.value !== ''));
  }

  digits.forEach((d, i) => {
    d.addEventListener('input', function(){
      this.value = this.value.replace(/\D/g,'').slice(-1);
      updateHidden();
      if (this.value && i < digits.length - 1) digits[i+1].focus();
      // auto submit
      let full = ''; digits.forEach(x => full += x.value);
      if (full.length === 6 && form) form.requestSubmit();
    });

    d.addEventListener('keydown', function(e){
      if (e.key === 'Backspace' && !this.value && i > 0) {
        digits[i-1].value = '';
        digits[i-1].focus();
        updateHidden();
      }
    });

    d.addEventListener('paste', function(e){
      e.preventDefault();
      const text = (e.clipboardData || window.clipboardData).getData('text').replace(/\D/g,'');
      text.split('').slice(0,6).forEach((c,j) => {
        if (digits[j]) { digits[j].value = c; digits[j].classList.add('filled'); }
      });
      updateHidden();
      digits[Math.min(text.length, 5)].focus();
    });
  });

  if (digits[0]) digits[0].focus();
})();
</script>
</body>
</html>
