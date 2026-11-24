<?php
require_once __DIR__ . '/_bootstrap.php';

$action = $_GET['action'] ?? '';
$input  = get_json_input();

// จำกัดการเดารหัสผ่าน: ใช้ไฟล์และรูปแบบ key เดียวกับ login.php ฝั่งเว็บ
// ตัวนับจึงรวมกัน ผู้โจมตีสลับไปยิงทาง API เพื่อเลี่ยงลิมิตของหน้าเว็บไม่ได้ (และกลับกัน)
// ถ้าแก้ค่า/รูปแบบที่นี่ ต้องแก้ใน login.php ให้ตรงกันด้วย
const API_LOGIN_WINDOW   = 900; // 15 นาที
const API_LOGIN_MAX_USER = 8;   // ต่อ IP+username
const API_LOGIN_MAX_IP   = 30;  // ต่อ IP

function shared_login_throttle_update(callable $fn): array {
    $dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'worksync_login_throttle';
    if (!is_dir($dir)) @mkdir($dir, 0700, true);
    $fh = @fopen($dir . DIRECTORY_SEPARATOR . 'attempts.json', 'c+');
    if (!$fh) return [];
    flock($fh, LOCK_EX);
    $data = json_decode(stream_get_contents($fh) ?: '[]', true) ?: [];
    $now = time();
    foreach ($data as $k => $times) {
        $data[$k] = array_values(array_filter((array)$times, fn($t) => $t > $now - API_LOGIN_WINDOW));
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
// จำกัดการเดา OTP 6 หลัก
const API_OTP_MAX        = 5;
// จำกัดการสมัคร/ขอ OTP ต่อ IP ต่อชั่วโมง กันยิงอีเมลสแปม
const API_REG_MAX_IP     = 10;

// ══ REGISTER ══
// สมัครแล้วต้องยืนยันอีเมลด้วย OTP ก่อน (action=verify) เหมือนฝั่งเว็บ
// เดิมสร้างบัญชีเป็น is_verified=1 ทันที ทำให้ข้ามการยืนยันอีเมล และจองอีเมลของคนอื่นได้
if ($action === 'register') {
    require_method('POST');
    $username = trim((string)($input['username'] ?? ''));
    $password = (string)($input['password'] ?? '');
    $email    = trim((string)($input['email'] ?? ''));

    if ($username === '' || strlen($password) < 6) {
        json_error('กรุณากรอก username และรหัสผ่านอย่างน้อย 6 ตัว');
    }
    if (mb_strlen($username) > 100 || !preg_match('/^[\p{L}\p{N}_.\-]{3,100}$/u', $username)) {
        json_error('username ต้องยาว 3-100 ตัว ใช้ได้เฉพาะตัวอักษร ตัวเลข _ . -');
    }
    if (strlen($password) > 72) {
        json_error('รหัสผ่านยาวเกินไป');
    }
    if ($email === '' || mb_strlen($email) > 150 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        json_error('รูปแบบอีเมลไม่ถูกต้อง');
    }

    $regKey = 'reg|' . client_ip();
    if (throttle_count($regKey, 3600) >= API_REG_MAX_IP) {
        json_error('สมัครสมาชิกถี่เกินไป กรุณาลองใหม่ภายหลัง', 429);
    }
    throttle_hit($regKey, 3600);

    $chk = $conn->prepare("SELECT id FROM users WHERE username=? LIMIT 1");
    $chk->bind_param('s', $username);
    $chk->execute();
    $dup = $chk->get_result()->fetch_assoc();
    $chk->close();
    if ($dup) {
        json_error('ชื่อผู้ใช้นี้ถูกใช้งานแล้ว');
    }

    $chk2 = $conn->prepare("SELECT id FROM users WHERE email=? LIMIT 1");
    $chk2->bind_param('s', $email);
    $chk2->execute();
    $dupEmail = $chk2->get_result()->fetch_assoc();
    $chk2->close();
    if ($dupEmail) {
        json_error('อีเมลนี้ถูกใช้งานแล้ว');
    }

    // role ไม่รับจาก client เด็ดขาด (ค่า default ของคอลัมน์คือ user)
    $hash = password_hash($password, PASSWORD_DEFAULT);
    $ins = $conn->prepare("INSERT INTO users (username, password, email, is_verified) VALUES (?, ?, ?, 0)");
    $ins->bind_param('sss', $username, $hash, $email);
    $ins->execute();
    $userId = (int)$ins->insert_id;
    $ins->close();

    require_once __DIR__ . '/../mailer.php';
    $code = create_otp($conn, $userId, $email, 'verify');
    $sent = send_otp_email($email, $username, $code, 'verify');

    json_response([
        'success'      => true,
        'message'      => $sent
            ? 'สมัครสมาชิกสำเร็จ กรุณากรอกรหัส OTP ที่ส่งไปทางอีเมลเพื่อยืนยันบัญชี'
            : 'สมัครสมาชิกสำเร็จ แต่ส่งอีเมลไม่สำเร็จ กรุณาขอรหัสใหม่ (action=resend)',
        'user_id'      => $userId,
        'need_verify'  => true,
    ]);
}

// ══ VERIFY (ยืนยันอีเมลด้วย OTP) ══
if ($action === 'verify') {
    require_method('POST');
    $username = trim((string)($input['username'] ?? ''));
    $code     = preg_replace('/\s+/', '', (string)($input['code'] ?? ''));
    if ($username === '' || !preg_match('/^[0-9]{6}$/', $code)) {
        json_error('กรุณากรอก username และรหัส OTP 6 หลัก');
    }

    $st = $conn->prepare("SELECT id, is_verified FROM users WHERE username=? LIMIT 1");
    $st->bind_param('s', $username);
    $st->execute();
    $u = $st->get_result()->fetch_assoc();
    $st->close();

    $otpKey = 'otp|' . strtolower($username);
    if (throttle_count($otpKey, 600) >= API_OTP_MAX) {
        json_error('กรอกรหัสผิดหลายครั้งเกินไป กรุณาขอรหัสใหม่', 429);
    }

    require_once __DIR__ . '/../mailer.php';
    if (!$u || (int)$u['is_verified'] === 1 || !verify_otp($conn, (int)$u['id'], $code, 'verify')) {
        throttle_hit($otpKey, 600);
        if ($u && throttle_count($otpKey, 600) >= API_OTP_MAX) {
            // ผิดครบกำหนด ทิ้ง OTP ตัวนี้ไปเลย ต้องขอใหม่ (ซึ่งจะสุ่มใหม่)
            $del = $conn->prepare("DELETE FROM otp_codes WHERE user_id=? AND type='verify'");
            $uidDel = (int)$u['id'];
            $del->bind_param('i', $uidDel);
            $del->execute();
            $del->close();
        }
        json_error('รหัส OTP ไม่ถูกต้องหรือหมดอายุแล้ว');
    }

    $uidV = (int)$u['id'];
    $upd = $conn->prepare("UPDATE users SET is_verified=1 WHERE id=?");
    $upd->bind_param('i', $uidV);
    $upd->execute();
    $upd->close();
    throttle_clear($otpKey);

    json_response(['success' => true, 'message' => 'ยืนยันอีเมลแล้ว เข้าสู่ระบบได้เลย']);
}

// ══ RESEND OTP ══
if ($action === 'resend') {
    require_method('POST');
    $username = trim((string)($input['username'] ?? ''));

    $regKey = 'reg|' . client_ip();
    if (throttle_count($regKey, 3600) >= API_REG_MAX_IP) {
        json_error('ขอรหัสถี่เกินไป กรุณาลองใหม่ภายหลัง', 429);
    }
    throttle_hit($regKey, 3600);

    $st = $conn->prepare("SELECT id, username, email, is_verified FROM users WHERE username=? LIMIT 1");
    $st->bind_param('s', $username);
    $st->execute();
    $u = $st->get_result()->fetch_assoc();
    $st->close();

    // ตอบข้อความเดียวกันทุกกรณี ไม่บอกว่ามีบัญชีนี้หรือไม่
    if ($u && (int)$u['is_verified'] === 0 && !empty($u['email'])) {
        require_once __DIR__ . '/../mailer.php';
        $code = create_otp($conn, (int)$u['id'], $u['email'], 'verify');
        send_otp_email($u['email'], $u['username'], $code, 'verify');
        throttle_clear('otp|' . strtolower($username));
    }
    json_response(['success' => true, 'message' => 'หากบัญชียังไม่ได้ยืนยัน ระบบได้ส่งรหัสใหม่ไปทางอีเมลแล้ว']);
}

// ══ LOGIN ══
if ($action === 'login') {
    require_method('POST');
    $username = trim((string)($input['username'] ?? ''));
    $password = (string)($input['password'] ?? '');

    // key รูปแบบเดียวกับ login.php
    $ip      = client_ip();
    $ipKey   = 'ip:' . $ip;
    $userKey = 'u:' . sha1($ip . '|' . mb_strtolower($username));
    $tdata = shared_login_throttle_update(fn($d) => $d);
    if (count($tdata[$ipKey] ?? []) >= API_LOGIN_MAX_IP
        || count($tdata[$userKey] ?? []) >= API_LOGIN_MAX_USER) {
        json_error('พยายามเข้าสู่ระบบผิดหลายครั้งเกินไป กรุณาลองใหม่ใน 15 นาที', 429);
    }

    $st = $conn->prepare("SELECT id, username, display_name, password, role, is_verified FROM users WHERE username=? LIMIT 1");
    $st->bind_param('s', $username);
    $st->execute();
    $user = $st->get_result()->fetch_assoc();
    $st->close();

    // ตรวจ hash เสมอแม้ไม่พบผู้ใช้ เวลาตอบกลับจะได้ไม่บอกว่ามี username นี้อยู่หรือไม่
    $hashToCheck = $user['password'] ?? '$2y$10$usesomesillystringfore7hnbRJHxXVLeakoG8K30oukPsA.ztMG';
    $passOk = password_verify($password, $hashToCheck);

    if (!$user || !$passOk) {
        shared_login_throttle_update(function ($d) use ($ipKey, $userKey) {
            $d[$ipKey][]   = time();
            $d[$userKey][] = time();
            return $d;
        });
        json_error('ชื่อผู้ใช้หรือรหัสผ่านไม่ถูกต้อง', 401);
    }

    // เหมือนฝั่งเว็บ บัญชีที่ยังไม่ยืนยันอีเมลเข้าระบบไม่ได้
    if ((int)$user['is_verified'] !== 1) {
        json_error('บัญชีนี้ยังไม่ได้ยืนยันอีเมล กรุณายืนยันด้วยรหัส OTP (action=verify) หรือเข้าสู่ระบบที่หน้าเว็บเพื่อยืนยันอีเมลก่อน', 403);
    }
    shared_login_throttle_update(function ($d) use ($userKey) { unset($d[$userKey]); return $d; });

    $uid = (int)$user['id'];

    // เก็บกวาด token ที่หมดอายุแล้วของผู้ใช้คนนี้
    $gc = $conn->prepare("DELETE FROM api_tokens WHERE user_id=? AND expires_at <= NOW()");
    $gc->bind_param('i', $uid);
    $gc->execute();
    $gc->close();

    // สร้าง token อายุ 30 วัน (DB เก็บแค่ hash ตัวจริงส่งให้ client ครั้งเดียว)
    $token = generate_token();
    $hash  = token_hash($token);
    $ins = $conn->prepare("INSERT INTO api_tokens (user_id, token, expires_at) VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 30 DAY))");
    $ins->bind_param('is', $uid, $hash);
    $ins->execute();
    $ins->close();

    json_response([
        'success' => true,
        'token'   => $token,
        'user'    => [
            'id'           => $uid,
            'username'     => $user['username'],
            'display_name' => $user['display_name'],
            'role'         => $user['role'],
        ],
    ]);
}

// ══ LOGOUT ══
// all=true ใน body = ออกจากระบบทุกอุปกรณ์
if ($action === 'logout') {
    require_method('POST');
    $token = bearer_token();
    if ($token !== null) {
        $hash = token_hash($token);
        if (!empty($input['all'])) {
            $who = $conn->prepare("SELECT user_id FROM api_tokens WHERE token=? LIMIT 1");
            $who->bind_param('s', $hash);
            $who->execute();
            $row = $who->get_result()->fetch_assoc();
            $who->close();
            if ($row) {
                $tuid = (int)$row['user_id'];
                $del = $conn->prepare("DELETE FROM api_tokens WHERE user_id=?");
                $del->bind_param('i', $tuid);
                $del->execute();
                $del->close();
            }
        } else {
            $del = $conn->prepare("DELETE FROM api_tokens WHERE token=?");
            $del->bind_param('s', $hash);
            $del->execute();
            $del->close();
        }
    }
    json_response(['success' => true, 'message' => 'ออกจากระบบแล้ว']);
}

// ══ ME (ดูข้อมูลตัวเอง) ══
if ($action === 'me') {
    require_method('GET');
    $user = authenticate($conn);
    json_response(['success' => true, 'user' => $user]);
}

json_error('Unknown action', 404);
