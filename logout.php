<?php
require_once 'init.php';

function do_logout(): void {
    // เคลียร์ตัวแปรและทำลายอาร์เรย์เซสชันของระบบอย่างหมดจดเกลี้ยงเกลา (Secure Destruction)
    $_SESSION = [];
    if (ini_get("session.use_cookies")) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $params["path"], $params["domain"],
            $params["secure"], $params["httponly"]
        );
    }
    session_unset();
    session_destroy();
    header('Location: index.php');
    exit;
}

// ออกจากระบบต้องเป็น POST + CSRF token
// ของเดิมรับ GET ทำให้เว็บอื่นฝัง <img src=".../logout.php"> แล้วเตะผู้ใช้ออกได้
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    do_logout();
}

// GET: ยังไม่ล็อกอิน หรือบัญชีถูกลบไปแล้ว → ล้าง session ได้เลย ไม่มีอะไรให้เสีย
$uid = (int)($_SESSION['user_id'] ?? 0);
if ($uid <= 0) {
    do_logout();
}
$st = $conn->prepare("SELECT 1 FROM users WHERE id=? LIMIT 1");
$st->bind_param('i', $uid);
$st->execute();
$exists = (bool)$st->get_result()->fetch_row();
$st->close();
if (!$exists) {
    do_logout();
}

// GET ปกติ (เช่นเบราว์เซอร์ปิด JavaScript) → ถามยืนยันก่อน
?>
<!doctype html>
<html lang="th">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>ออกจากระบบ - WorkSync</title>
<link rel="stylesheet" href="assets/style.css">
</head>
<body>
<div class="container" style="max-width:420px;margin:80px auto;text-align:center;">
  <h2>ออกจากระบบ?</h2>
  <form method="POST" action="logout.php" style="margin-top:20px;">
    <?= csrf_tag() ?>
    <button type="submit" class="btn">ออกจากระบบ</button>
    <a href="index.php" style="margin-left:12px;">ยกเลิก</a>
  </form>
</div>
</body>
</html>
