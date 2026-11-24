<?php
require_once 'init.php';
check_csrf();

$err = '';

/* ── จำกัดการเดารหัสผ่าน (brute force) ──────────────────────────────
 * เดิมนับครั้งที่ผิดไว้ใน $_SESSION อย่างเดียว ผู้โจมตีแค่ทิ้ง cookie แล้วขอ session ใหม่
 * ก็เดาต่อได้ไม่จำกัด (และคนที่พิมพ์ผิดครบ 8 ครั้งจะโดนล็อกถาวรจน session หมดอายุ)
 * จึงเก็บสถิติไว้ฝั่งเซิร์ฟเวอร์ตาม IP และ IP+username แบบหน้าต่างเวลา 15 นาที
 * (ใช้ไฟล์ใน temp เพราะยังไม่มีตารางสำหรับเก็บ) */
const LOGIN_WINDOW        = 900; // 15 นาที
const LOGIN_MAX_PER_USER  = 8;   // ผิดได้กี่ครั้งต่อ IP+username
const LOGIN_MAX_PER_IP    = 30;  // ผิดได้กี่ครั้งต่อ IP (กันไล่เดาหลาย username)

function login_throttle_file(): string {
    $dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'worksync_login_throttle';
    if (!is_dir($dir)) @mkdir($dir, 0700, true);
    return $dir . DIRECTORY_SEPARATOR . 'attempts.json';
}

/** อ่าน/เขียนข้อมูลแบบล็อกไฟล์  $fn รับ array แล้วคืน array ใหม่ */
function login_throttle_update(callable $fn): array {
    $fh = @fopen(login_throttle_file(), 'c+');
    if (!$fh) return [];
    flock($fh, LOCK_EX);
    $data = json_decode(stream_get_contents($fh) ?: '[]', true) ?: [];
    // ทิ้งรายการที่เก่ากว่าหน้าต่างเวลา
    $now = time();
    foreach ($data as $k => $times) {
        $data[$k] = array_values(array_filter((array)$times, fn($t) => $t > $now - LOGIN_WINDOW));
        if (!$data[$k]) unset($data[$k]);
    }
    $data = $fn($data);
    ftruncate($fh, 0);
    rewind($fh);
    fwrite($fh, json_encode($data));
    fflush($fh);
    flock($fh, LOCK_UN);
    fclose($fh);
    return $data;
}

$ip      = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
$ipKey   = 'ip:' . $ip;
$userKey = function (string $u) use ($ip) { return 'u:' . sha1($ip . '|' . mb_strtolower($u)); };

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $u = trim($_POST['username'] ?? '');
    $p = $_POST['password'] ?? '';
    $uk = $userKey($u);

    $data = login_throttle_update(fn($d) => $d);
    $locked = count($data[$ipKey] ?? []) >= LOGIN_MAX_PER_IP
           || count($data[$uk] ?? []) >= LOGIN_MAX_PER_USER;

    // ป้องกันการยิงสแปมรหัสผ่าน (Rate Limiting Protection)
    if ($locked) {
        $err = 'คุณกรอกรหัสผ่านผิดเกินกำหนด โปรดลองใหม่อีกครั้งใน 15 นาทีเพื่อความปลอดภัย';
    } else {
        $st = $conn->prepare("SELECT id, username, email, password, role, is_verified FROM users WHERE username = ? LIMIT 1");
        $st->bind_param('s', $u);
        $st->execute();
        $r = $st->get_result()->fetch_assoc();
        $st->close();

        // ถ้าไม่พบ user ก็ยังเรียก password_verify กับ hash หลอก ให้เวลาตอบสนองใกล้เคียงกัน
        // (กันการเดาว่า username ไหนมีอยู่จริงจากความเร็วของการตอบกลับ)
        $hash  = $r['password'] ?? '$2y$10$ftRhUFnS/9XDo9A5WZlDquJdjPB5lkcar4y0iKjR.MKFJ/d6ujb4G';
        $pwOk  = password_verify($p, $hash) && $r;

        if ($pwOk) {
            if ((int)$r['is_verified'] !== 1) {
                // รหัสผ่านถูก แต่ยังไม่ได้ยืนยัน OTP ทางอีเมล → ส่งไปหน้ายืนยัน OTP ต่อ
                // (เดิมบัญชีค้างแบบนี้ไม่มีทางกลับไปยืนยันได้อีกเลย)
                session_regenerate_id(true);
                $_SESSION['reg_uid']      = (int)$r['id'];
                $_SESSION['reg_email']    = (string)($r['email'] ?? '');
                $_SESSION['reg_name']     = $r['username'];
                $_SESSION['reg_step']     = 2;
                $_SESSION['reg_otp_fail'] = 0;
                header('Location: register.php');
                exit;
            }
            // เคลียร์ค่า Session ไร้สาระและ Re-generate session ใหม่ลดการดักข้อมูลเส้นทาง
            session_regenerate_id(true);
            $_SESSION['user_id']  = $r['id'];
            $_SESSION['username'] = $r['username'];
            // ต้องเก็บ role ไว้ด้วย ไม่งั้นหน้า admin ทุกหน้าจะกันผู้ดูแลระบบออกหมด
            $_SESSION['role']     = $r['role'] ?? 'user';
            // รหัส CSRF เดิมอาจถูกฝังไว้ก่อนล็อกอิน ให้สร้างใหม่หลังยืนยันตัวตน
            unset($_SESSION['_csrf']);
            login_throttle_update(function ($d) use ($uk) { unset($d[$uk]); return $d; });

            header('Location: index.php');
            exit;
        }

        login_throttle_update(function ($d) use ($ipKey, $uk) {
            $d[$ipKey][] = time();
            $d[$uk][]    = time();
            return $d;
        });
        $err = 'ชื่อผู้ใช้งานหรือรหัสผ่านที่คุณป้อนไม่ถูกต้องในระบบ';
    }
}
?>
<!doctype html>
<html lang="th">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<link rel="stylesheet" href="assets/style.css?v=<?= filemtime(__DIR__.'/assets/style.css') ?>">
<title>WorkSync — เข้าสู่ระบบสมาชิก</title>
</head>
<body>
<?php include 'navbar.php'; ?>

<div class="container">
  <div class="box" style="max-width:480px; margin:50px auto; padding:30px; border-radius:12px; box-shadow:0 4px 12px rgba(0,0,0,0.05);">
    <h2 style="text-align:center; margin-bottom:20px;">🔐 เข้าสู่ระบบสมาชิก</h2>

    <?php if (isset($_GET['registered']) || isset($_GET['verified'])): ?>
        <div style="background:#eaf6f0; color:#0f9b5e; padding:10px 15px; border-radius:8px; margin-bottom:15px; font-weight:bold; font-size:14px;">
            ✓ ยืนยันอีเมลสำเร็จแล้ว กรุณาเข้าสู่ระบบ
        </div>
    <?php endif; ?>
    <?php if (isset($_GET['reset'])): ?>
        <div style="background:#eaf6f0; color:#0f9b5e; padding:10px 15px; border-radius:8px; margin-bottom:15px; font-weight:bold; font-size:14px;">
            ✓ เปลี่ยนรหัสผ่านสำเร็จ กรุณาเข้าสู่ระบบด้วยรหัสผ่านใหม่
        </div>
    <?php endif; ?>

    <?php if ($err): ?>
        <div style="background:#ffebee; color:#c62828; padding:10px 15px; border-radius:8px; margin-bottom:15px; font-weight:bold; font-size:14px;">
           ⚠️ <?= htmlspecialchars($err, ENT_QUOTES, 'UTF-8') ?>
        </div>
    <?php endif; ?>

    <form method="POST">
      <?= csrf_tag() ?>
      <label style="font-weight:bold; display:block; margin-bottom:5px; font-size:14px;">ชื่อผู้ใช้งาน (Username)</label>
      <input class="input" name="username" placeholder="พิมพ์ชื่อผู้ใช้ของคุณ..." required style="width:100%; margin-bottom:15px; padding:10px;">
      
      <label style="font-weight:bold; display:block; margin-bottom:5px; font-size:14px;">รหัสผ่านความปลอดภัย (Password)</label>
      <div style="position:relative; margin-bottom:20px;">
        <input class="input" type="password" name="password" id="pw_login"
               placeholder="พิมพ์รหัสผ่านลับ..." required
               style="width:100%; padding:10px 44px 10px 10px; box-sizing:border-box;">
        <button type="button" onclick="togglePw('pw_login','eye_login')"
                style="position:absolute;right:12px;top:50%;transform:translateY(-50%);
                       background:none;border:none;cursor:pointer;padding:4px;color:#888;font-size:18px;"
                title="แสดง/ซ่อนรหัสผ่าน">
          <span id="eye_login">👁️</span>
        </button>
      </div>
      
      <button type="submit" class="btn" style="width:100%; padding:12px; font-size:16px;">ล็อกอินเข้าใช้งานระบบ</button>
    </form>
    
    <hr style="border:0; border-top:1px solid #eee; margin:20px 0;">
    <p style="text-align:center; font-size:14px; color:#666;">ยังไม่มีบัญชีสมาชิกใช่ไหม? <a href="register.php" style="font-weight:bold; color:var(--red, #b00020);">สมัครสมาชิกที่นี่</a></p>
    <p style="text-align:center; font-size:13px; margin-top:8px;"><a href="forgot_password.php" style="color:#888;">ลืมรหัสผ่าน?</a></p>
  </div>
</div>
<script>
function togglePw(inputId, eyeId) {
  const input = document.getElementById(inputId);
  const eye   = document.getElementById(eyeId);
  if (input.type === 'password') {
    input.type = 'text';
    eye.textContent = '🙈';
  } else {
    input.type = 'password';
    eye.textContent = '👁️';
  }
}
</script>
</body>
</html>