<?php
/**
 * _bootstrap.php — แกนกลางของ REST API สำหรับ Mobile App
 * ใช้ Bearer Token แทน session (เหมาะกับ Flutter/มือถือ)
 *
 * API นี้ไม่ใช้ cookie / session เลย การเปิด CORS เป็น * จึงไม่เปิดช่อง CSRF
 * (เบราว์เซอร์ไม่แนบ token ให้อัตโนมัติ) ห้าม require init.php หรือ session_start() ในโฟลเดอร์นี้
 */

// ข้อผิดพลาดดิบห้ามหลุดไปกับ response (เคยหลุด path เครื่องและ stack trace) ให้ลง error_log แทน
ini_set('display_errors', '0');

// ── CORS: อนุญาตให้แอปมือถือเรียกได้ ──
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

// ตอบ preflight request ทันที
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// ── Helper: ส่ง JSON response ──
function json_response($data, int $code = 200): void {
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function json_error(string $msg, int $code = 400): void {
    json_response(['success' => false, 'message' => $msg], $code);
}

// ข้อผิดพลาดที่ไม่ได้ดักไว้ (เช่น SQL error, TypeError) ตอบเป็น JSON กลาง ๆ ไม่บอกรายละเอียดภายใน
set_exception_handler(function (Throwable $e) {
    error_log('api error: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    if (!headers_sent()) {
        header('Content-Type: application/json; charset=utf-8');
    }
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'ระบบขัดข้อง กรุณาลองใหม่ภายหลัง'], JSON_UNESCAPED_UNICODE);
    exit;
});

require_once __DIR__ . '/../config.php';

// ── บังคับ HTTP method ──
function require_method(string ...$methods): void {
    if (!in_array($_SERVER['REQUEST_METHOD'], $methods, true)) {
        header('Allow: ' . implode(', ', $methods));
        json_error('Method not allowed', 405);
    }
}

// ── รับ JSON body จาก request ──
function get_json_input(): array {
    $raw = file_get_contents('php://input');
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

// ── สร้าง API token ──
function generate_token(): string {
    return bin2hex(random_bytes(32));
}

// ฐานข้อมูลเก็บเฉพาะ hash ของ token ถ้าตาราง api_tokens รั่ว ก็เอาค่าไปใช้เข้าระบบแทนใครไม่ได้
// sha256 เป็น hex 64 ตัว ใส่ลงคอลัมน์ VARCHAR(64) เดิมได้พอดี ไม่ต้องแก้ schema
function token_hash(string $token): string {
    return hash('sha256', $token);
}

// ── ดึง token จาก Authorization header (ชื่อ header ไม่สนตัวพิมพ์เล็ก/ใหญ่) ──
function bearer_token(): ?string {
    $auth = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
    if ($auth === '' && function_exists('getallheaders')) {
        foreach (getallheaders() as $k => $v) {
            if (strcasecmp($k, 'Authorization') === 0) { $auth = $v; break; }
        }
    }
    if (!preg_match('/^\s*Bearer\s+([0-9a-f]{64})\s*$/i', $auth, $m)) {
        return null;
    }
    return strtolower($m[1]);
}

// ── ตรวจสอบ token จาก Authorization header ──
function authenticate(mysqli $conn): array {
    $token = bearer_token();
    if ($token === null) {
        json_error('ไม่พบ token กรุณาเข้าสู่ระบบ', 401);
    }
    $hash = token_hash($token);

    $st = $conn->prepare(
        "SELECT t.user_id, u.username, u.display_name, u.email, u.role
         FROM api_tokens t
         JOIN users u ON u.id = t.user_id
         WHERE t.token = ? AND t.expires_at > NOW() LIMIT 1"
    );
    $st->bind_param('s', $hash);
    $st->execute();
    $user = $st->get_result()->fetch_assoc();
    $st->close();

    if (!$user) {
        json_error('Token หมดอายุหรือไม่ถูกต้อง', 401);
    }
    $user['user_id'] = (int)$user['user_id'];
    return $user;
}

// ── จำกัดจำนวนครั้ง (กัน brute force) เก็บเป็นไฟล์ใน temp ไม่ต้องแก้ schema ──
// $key ควรรวม IP / username ไว้แล้ว คืนค่าจำนวนครั้งที่ล้มเหลวในช่วง $window วินาที
function throttle_path(string $key): string {
    $dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'worksync_api_throttle';
    if (!is_dir($dir)) @mkdir($dir, 0700, true);
    return $dir . DIRECTORY_SEPARATOR . hash('sha256', $key) . '.json';
}

function throttle_count(string $key, int $window): int {
    $f = throttle_path($key);
    $d = is_file($f) ? json_decode((string)@file_get_contents($f), true) : null;
    if (!is_array($d) || ($d['start'] ?? 0) < time() - $window) return 0;
    return (int)($d['n'] ?? 0);
}

function throttle_hit(string $key, int $window): void {
    $f = throttle_path($key);
    $fp = @fopen($f, 'c+');
    if (!$fp) return;
    flock($fp, LOCK_EX);
    $d = json_decode((string)stream_get_contents($fp), true);
    if (!is_array($d) || ($d['start'] ?? 0) < time() - $window) {
        $d = ['start' => time(), 'n' => 0];
    }
    $d['n']++;
    ftruncate($fp, 0);
    rewind($fp);
    fwrite($fp, json_encode($d));
    flock($fp, LOCK_UN);
    fclose($fp);
}

function throttle_clear(string $key): void {
    @unlink(throttle_path($key));
}

function client_ip(): string {
    // ใช้ REMOTE_ADDR เท่านั้น X-Forwarded-For ปลอมได้
    return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
}

// ── helper รูปภาพ ──
// derive จาก host ที่ client เรียกมา — ไม่ต้องตามแก้ทุกครั้งที่ IP/domain เปลี่ยน
// แต่ Host header ผู้เรียกกำหนดเองได้ จึงรับเฉพาะรูปแบบ hostname[:port] ที่ถูกต้อง ไม่งั้นใช้ SERVER_NAME
function api_base_url(): string {
    static $base = null;
    if ($base !== null) return $base;

    // ยอมรับ: IP ตรง ๆ (แอปเรียกผ่าน IP วง LAN), localhost และชื่อโดเมนที่ประกาศไว้ใน API_ALLOWED_HOSTS
    // (define('API_ALLOWED_HOSTS', ['shop.example.com']) ใน config.php เมื่อขึ้นโดเมนจริง)
    // ชื่ออื่นที่ไม่รู้จักใช้ IP ของเครื่องแทน กันคนปลอม Host ให้ URL รูปชี้ไปโดเมนอื่น (เช่นพิษ cache)
    $host = $_SERVER['HTTP_HOST'] ?? '';
    $ok = false;
    if (preg_match('/^(\[[0-9A-Fa-f:.]+\]|[A-Za-z0-9.\-]{1,253})(?::(\d{1,5}))?$/', $host, $hm)) {
        $name = strtolower(trim($hm[1], '[]'));
        $allowed = array_map('strtolower', defined('API_ALLOWED_HOSTS') ? (array)API_ALLOWED_HOSTS : []);
        $ok = filter_var($name, FILTER_VALIDATE_IP) !== false
           || $name === 'localhost'
           || in_array($name, $allowed, true);
    }
    if (!$ok) {
        $addr = $_SERVER['SERVER_ADDR'] ?? '127.0.0.1';
        $host = (strpos($addr, ':') !== false) ? "[$addr]" : $addr;
    }
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    // โฟลเดอร์แอป = โฟลเดอร์แม่ของ /api
    $dir = rtrim(strtr(dirname(dirname($_SERVER['SCRIPT_NAME'] ?? '/worksync/api/x.php')), chr(92), '/'), '/');
    $base = "$scheme://$host$dir/";
    return $base;
}

function api_img_url($path): string {
    $base = api_base_url();
    // รูปสำรองใช้ไฟล์ในเครื่อง บริการภายนอกเดิมปิดไปแล้ว
    if (!$path) return $base . 'assets/no-image.svg';
    if (preg_match('#^https?://#i', $path)) return $path;
    return $base . ltrim($path, '/');
}
