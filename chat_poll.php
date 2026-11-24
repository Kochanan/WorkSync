<?php
/**
 * chat_poll.php — Long-polling endpoint
 * ส่งคืน JSON ข้อความใหม่หลัง last_id ที่ระบุ
 */
require_once 'init.php';
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['ok' => false, 'messages' => []]);
    exit;
}

$uid    = (int)$_SESSION['user_id'];
$role   = $_SESSION['role'] ?? 'user';
$convId = (int)($_GET['conv_id'] ?? 0);
$lastId = (int)($_GET['last_id'] ?? 0);

if ($convId <= 0) {
    echo json_encode(['ok' => false, 'messages' => []]);
    exit;
}

// ตรวจสิทธิ์: user ต้องเป็นเจ้าของ conversation / admin เข้าได้ทุก conv
if ($role !== 'admin') {
    $chk = $conn->prepare("SELECT id FROM conversations WHERE id = ? AND user_id = ? LIMIT 1");
    $chk->bind_param('ii', $convId, $uid);
    $chk->execute();
    $ok = $chk->get_result()->fetch_assoc();
    $chk->close();
    if (!$ok) {
        echo json_encode(['ok' => false, 'messages' => []]);
        exit;
    }
}

// ดึงข้อความใหม่
$st = $conn->prepare(
    "SELECT id, sender_role, body, created_at
     FROM messages
     WHERE conversation_id = ? AND id > ?
     ORDER BY id ASC
     LIMIT 50"
);
$st->bind_param('ii', $convId, $lastId);
$st->execute();
$rows = $st->get_result()->fetch_all(MYSQLI_ASSOC);
$st->close();

// mark อ่าน (ทั้งตัวนับในห้องและสถานะรายข้อความ ให้ตรงกับตอนเปิดหน้า chat.php / admin_chat.php)
if (!empty($rows)) {
    $isAdmin = ($role === 'admin');
    $upd = $conn->prepare($isAdmin
        ? "UPDATE conversations SET unread_admin = 0 WHERE id = ?"
        : "UPDATE conversations SET unread_user = 0 WHERE id = ?");
    $upd->bind_param('i', $convId);
    $upd->execute();
    $upd->close();

    $otherRole = $isAdmin ? 'user' : 'admin';
    $upd2 = $conn->prepare("UPDATE messages SET is_read = 1 WHERE conversation_id = ? AND sender_role = ? AND is_read = 0");
    $upd2->bind_param('is', $convId, $otherRole);
    $upd2->execute();
    $upd2->close();
}

foreach ($rows as &$r) {
    $r['id'] = (int)$r['id'];
}
unset($r);

echo json_encode(['ok' => true, 'messages' => $rows]);
