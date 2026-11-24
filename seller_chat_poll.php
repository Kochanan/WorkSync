<?php
/**
 * seller_chat_poll.php — polling endpoint สำหรับ seller chat
 */
require_once 'init.php';
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['ok' => false, 'messages' => []]);
    exit;
}

$uid    = (int)$_SESSION['user_id'];
$convId = (int)($_GET['conv_id'] ?? 0);
$lastId = (int)($_GET['last_id'] ?? 0);

if ($convId <= 0) {
    echo json_encode(['ok' => false, 'messages' => []]);
    exit;
}

// ตรวจสิทธิ์
$chk = $conn->prepare(
    "SELECT id, buyer_id, seller_id FROM seller_conversations WHERE id=? AND (buyer_id=? OR seller_id=?) LIMIT 1"
);
$chk->bind_param('iii', $convId, $uid, $uid);
$chk->execute();
$conv = $chk->get_result()->fetch_assoc();
$chk->close();

if (!$conv) {
    echo json_encode(['ok' => false, 'messages' => []]);
    exit;
}

// ดึงข้อความใหม่
$st = $conn->prepare(
    "SELECT id, sender_id, body, created_at FROM seller_messages WHERE conversation_id=? AND id>? ORDER BY id ASC LIMIT 50"
);
$st->bind_param('ii', $convId, $lastId);
$st->execute();
$rows = $st->get_result()->fetch_all(MYSQLI_ASSOC);
$st->close();

// mark อ่าน
if (!empty($rows)) {
    $isSeller = ((int)$conv['seller_id'] === $uid);
    if ($isSeller) {
        $upd = $conn->prepare("UPDATE seller_conversations SET unread_seller=0 WHERE id=?");
    } else {
        $upd = $conn->prepare("UPDATE seller_conversations SET unread_buyer=0 WHERE id=?");
    }
    $upd->bind_param('i', $convId);
    $upd->execute();
    $upd->close();

    $upd2 = $conn->prepare("UPDATE seller_messages SET is_read=1 WHERE conversation_id=? AND sender_id!=? AND is_read=0");
    $upd2->bind_param('ii', $convId, $uid);
    $upd2->execute();
    $upd2->close();
}

foreach ($rows as &$r) {
    $r['id']        = (int)$r['id'];
    $r['sender_id'] = (int)$r['sender_id'];
}
unset($r);

echo json_encode(['ok' => true, 'messages' => $rows]);
