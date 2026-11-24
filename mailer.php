<?php
/**
 * mailer.php — Email helper ใช้ PHPMailer + Gmail SMTP
 * วาง PHPMailer ไว้ที่ worksync/libs/PHPMailer/src/
 */

require_once __DIR__ . '/libs/PHPMailer/src/Exception.php';
require_once __DIR__ . '/libs/PHPMailer/src/PHPMailer.php';
require_once __DIR__ . '/libs/PHPMailer/src/SMTP.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;

require_once __DIR__ . '/mail_config.php';

function send_otp_email(string $toEmail, string $toName, string $code, string $type = 'verify'): bool {
    $subject = $type === 'verify'
        ? '[WorkSync] รหัสยืนยันอีเมลของคุณ'
        : '[WorkSync] รหัสรีเซ็ตรหัสผ่าน';
    $action = $type === 'verify' ? 'ยืนยันอีเมล' : 'รีเซ็ตรหัสผ่าน';
    // ชื่อผู้ใช้มาจากผู้ใช้กรอกเอง ต้อง escape ก่อนใส่ลง HTML ของอีเมล (กัน HTML injection / phishing ในอีเมล)
    $safeName = htmlspecialchars($toName, ENT_QUOTES, 'UTF-8');
    $safeCode = htmlspecialchars($code, ENT_QUOTES, 'UTF-8');

    $html = '<!DOCTYPE html><html lang="th"><head><meta charset="utf-8"></head>
<body style="margin:0;padding:0;background:#f0f2f6;font-family:sans-serif;">
<table width="100%" cellpadding="0" cellspacing="0">
<tr><td align="center" style="padding:40px 16px;">
<table width="480" cellpadding="0" cellspacing="0" style="background:#fff;border-radius:16px;overflow:hidden;">
<tr><td style="background:linear-gradient(135deg,#0d2137,#2756a0);padding:28px 32px;">
  <h1 style="margin:0;color:#fff;font-size:22px;">WorkSync</h1>
  <p style="margin:6px 0 0;color:rgba(255,255,255,.7);font-size:13px;">'.$action.'</p>
</td></tr>
<tr><td style="padding:32px;">
  <p style="color:#3d4754;font-size:15px;">สวัสดี <strong>'.$safeName.'</strong>,</p>
  <p style="color:#5f6b7a;font-size:14px;line-height:1.7;">รหัส OTP สำหรับ'.$action.'บัญชีของคุณ<br>มีอายุ <strong>10 นาที</strong> ใช้ได้ครั้งเดียว</p>
  <div style="text-align:center;margin:24px 0;">
    <div style="display:inline-block;background:#f0f2f6;border-radius:12px;padding:20px 40px;">
      <span style="font-size:36px;font-weight:700;letter-spacing:10px;color:#0d2137;font-family:monospace;">'.$safeCode.'</span>
    </div>
  </div>
  <p style="color:#b0bec9;font-size:12px;">หากไม่ได้ทำรายการนี้ กรุณาเพิกเฉยต่ออีเมลนี้</p>
</td></tr>
<tr><td style="background:#f7f8fb;padding:14px 32px;border-top:1px solid #eaecf0;">
  <p style="margin:0;color:#b0bec9;font-size:11px;text-align:center;">© 2568 WorkSync · โรงเรียนอัสสัมชัญระยอง</p>
</td></tr>
</table></td></tr></table>
</body></html>';

    try {
        $mail = new PHPMailer(true);
        $mail->isSMTP();
        $mail->Host       = MAIL_HOST;
        $mail->SMTPAuth   = true;
        $mail->Username   = MAIL_USER;
        $mail->Password   = MAIL_PASS;
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = MAIL_PORT;
        $mail->CharSet    = 'UTF-8';
        // ตรวจใบรับรองของ Gmail จริง (ของเดิมปิดไว้ = ใครดักกลางทางก็อ่านรหัสผ่านอีเมลได้)
        // ใช้ CA bundle ที่ตั้งไว้ใน php.ini (openssl.cafile)
        $mail->SMTPOptions = ['ssl' => [
            'verify_peer'       => true,
            'verify_peer_name'  => true,
            'allow_self_signed' => false,
        ]];
        $mail->setFrom(MAIL_FROM, MAIL_NAME);
        $mail->addAddress($toEmail, $toName);
        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body    = $html;
        $mail->AltBody = "รหัส OTP: $code (มีอายุ 10 นาที)";
        $mail->send();
        return true;
    } catch (Exception $e) {
        error_log('PHPMailer: ' . $e->getMessage());
        return false;
    }
}

function create_otp(mysqli $conn, int $userId, string $email, string $type = 'verify'): string {
    $del = $conn->prepare("DELETE FROM otp_codes WHERE user_id=? AND type=?");
    $del->bind_param('is', $userId, $type);
    $del->execute();
    $del->close();

    $code = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);

    // ใช้ NOW() + INTERVAL จาก MySQL โดยตรง ไม่ขึ้นกับ timezone ของ PHP
    $ins = $conn->prepare(
        "INSERT INTO otp_codes (user_id, email, code, type, expires_at)
         VALUES (?, ?, ?, ?, NOW() + INTERVAL 10 MINUTE)"
    );
    $ins->bind_param('isss', $userId, $email, $code, $type);
    $ins->execute();
    $ins->close();
    return $code;
}

function verify_otp(mysqli $conn, int $userId, string $code, string $type = 'verify'): bool {
    $st = $conn->prepare(
        "SELECT id FROM otp_codes
         WHERE user_id=? AND code=? AND type=? AND is_used=0 AND expires_at > NOW() LIMIT 1"
    );
    $st->bind_param('iss', $userId, $code, $type);
    $st->execute();
    $row = $st->get_result()->fetch_assoc();
    $st->close();
    if (!$row) return false;
    $upd = $conn->prepare("UPDATE otp_codes SET is_used=1 WHERE id=?");
    $upd->bind_param('i', $row['id']);
    $upd->execute();
    $upd->close();
    return true;
}

/**
 * นับครั้งที่กรอก OTP ผิด ถ้าผิดครบ $max ครั้ง ให้ยกเลิก OTP ตัวนั้นทิ้งทันที
 * (กัน brute force รหัส 6 หลัก — ต้องขอรหัสใหม่ซึ่งจะสุ่มใหม่ทุกครั้ง)
 * คืนค่า true ถ้าถูกล็อก (OTP ถูกยกเลิกแล้ว)
 */
const OTP_MAX_ATTEMPTS = 5;

function otp_register_failure(mysqli $conn, int $userId, string $type, string $sessKey): bool {
    $_SESSION[$sessKey] = (int)($_SESSION[$sessKey] ?? 0) + 1;
    if ($_SESSION[$sessKey] < OTP_MAX_ATTEMPTS) {
        return false;
    }
    if ($userId > 0) {
        $del = $conn->prepare("DELETE FROM otp_codes WHERE user_id=? AND type=?");
        $del->bind_param('is', $userId, $type);
        $del->execute();
        $del->close();
    }
    return true;
}

/** จำกัดการขอส่ง OTP ซ้ำ (กันยิงอีเมลรัว ๆ) — คืนจำนวนวินาทีที่ต้องรอ, 0 = ส่งได้ */
const OTP_RESEND_COOLDOWN = 60;

function otp_cooldown_left(string $sessKey): int {
    $last = (int)($_SESSION[$sessKey] ?? 0);
    $left = $last + OTP_RESEND_COOLDOWN - time();
    return $left > 0 ? $left : 0;
}
