<?php
/**
 * addresses.php — จัดการที่อยู่จัดส่งสำหรับแอปมือถือ
 *
 * ทุก action ทำงานกับที่อยู่ของเจ้าของ token เท่านั้น
 * เงื่อนไข user_id ในทุกคำสั่งคือด่านกัน IDOR ห้ามตัดออก
 */

require_once __DIR__ . '/_bootstrap.php';
$user = authenticate($conn);
$uid  = (int)$user['user_id'];
$action = $_GET['action'] ?? 'list';
$input  = get_json_input();

/** อ่านและตรวจข้อมูลที่อยู่จาก request */
function read_address_input(array $in): array
{
    // ค่าที่ไม่ใช่ข้อความ (array/object) ให้ถือเป็นค่าว่าง ไม่งั้น trim() โยน TypeError
    foreach (['full_name', 'phone', 'address_line', 'subdistrict', 'district', 'province', 'postcode'] as $k) {
        $in[$k] = isset($in[$k]) && is_scalar($in[$k]) ? (string)$in[$k] : '';
    }
    $fullName    = trim($in['full_name']    ?? '');
    $phone       = trim($in['phone']        ?? '');
    $addressLine = trim($in['address_line'] ?? '');
    $subdistrict = trim($in['subdistrict']  ?? '');
    $district    = trim($in['district']     ?? '');
    $province    = trim($in['province']     ?? '');
    $postcode    = trim($in['postcode']     ?? '');

    if ($fullName === '' || $phone === '' || $addressLine === '' || $province === '' || $postcode === '') {
        json_error('กรุณากรอกชื่อผู้รับ เบอร์โทร ที่อยู่ จังหวัด และรหัสไปรษณีย์ให้ครบ');
    }
    if (!preg_match('/^[0-9\-\s+]{9,20}$/', $phone)) {
        json_error('รูปแบบเบอร์โทรไม่ถูกต้อง');
    }
    if (!preg_match('/^[0-9]{5}$/', $postcode)) {
        json_error('รหัสไปรษณีย์ต้องเป็นตัวเลข 5 หลัก');
    }
    if (mb_strlen($fullName) > 200 || mb_strlen($province) > 100 || mb_strlen($addressLine) > 1000
        || mb_strlen($subdistrict) > 100 || mb_strlen($district) > 100) {
        json_error('ข้อมูลยาวเกินกำหนด');
    }

    return [$fullName, $phone, $addressLine, $subdistrict, $district, $province, $postcode];
}

/** ทำให้เหลือที่อยู่หลักเพียงรายการเดียว */
function set_only_default(mysqli $conn, int $uid, int $addrId): void
{
    $clear = $conn->prepare("UPDATE user_addresses SET is_default = 0 WHERE user_id = ?");
    $clear->bind_param('i', $uid);
    $clear->execute();
    $clear->close();

    $set = $conn->prepare("UPDATE user_addresses SET is_default = 1 WHERE id = ? AND user_id = ?");
    $set->bind_param('ii', $addrId, $uid);
    $set->execute();
    $set->close();
}

// ══ LIST ══
if ($action === 'list') {
    require_method('GET');
    $st = $conn->prepare(
        "SELECT id, full_name, phone, address_line, subdistrict, district, province, postcode, is_default
         FROM user_addresses WHERE user_id = ?
         ORDER BY is_default DESC, id DESC"
    );
    $st->bind_param('i', $uid);
    $st->execute();
    $rows = $st->get_result()->fetch_all(MYSQLI_ASSOC);
    $st->close();

    foreach ($rows as &$r) {
        $r['id'] = (int)$r['id'];
        $r['is_default'] = (bool)$r['is_default'];
    }
    unset($r);
    json_response(['success' => true, 'addresses' => $rows]);
}

// ══ ADD ══
if ($action === 'add') {
    require_method('POST');
    [$fullName, $phone, $addressLine, $subdistrict, $district, $province, $postcode] = read_address_input($input);
    $makeDefault = !empty($input['is_default']);

    // ที่อยู่แรกของผู้ใช้ให้เป็นค่าเริ่มต้นเสมอ ไม่งั้นจะไม่มีที่อยู่หลักเลย
    $cnt = $conn->prepare("SELECT COUNT(*) c FROM user_addresses WHERE user_id = ?");
    $cnt->bind_param('i', $uid);
    $cnt->execute();
    $isFirst = ((int)$cnt->get_result()->fetch_assoc()['c'] === 0);
    $cnt->close();

    $flag = ($makeDefault || $isFirst) ? 1 : 0;

    $ins = $conn->prepare(
        "INSERT INTO user_addresses
         (user_id, full_name, phone, address_line, subdistrict, district, province, postcode, is_default)
         VALUES (?,?,?,?,?,?,?,?,?)"
    );
    $ins->bind_param(
        'isssssssi',
        $uid, $fullName, $phone, $addressLine, $subdistrict, $district, $province, $postcode, $flag
    );
    $ins->execute();
    $newId = (int)$ins->insert_id;
    $ins->close();

    if ($flag === 1) {
        set_only_default($conn, $uid, $newId);
    }

    json_response(['success' => true, 'id' => $newId, 'message' => 'บันทึกที่อยู่แล้ว']);
}

// ══ UPDATE ══
if ($action === 'update') {
    require_method('POST');
    $id = (int)($input['id'] ?? 0);
    if ($id <= 0) json_error('id ไม่ถูกต้อง');

    [$fullName, $phone, $addressLine, $subdistrict, $district, $province, $postcode] = read_address_input($input);

    $upd = $conn->prepare(
        "UPDATE user_addresses
         SET full_name=?, phone=?, address_line=?, subdistrict=?, district=?, province=?, postcode=?
         WHERE id=? AND user_id=?"
    );
    $upd->bind_param(
        'sssssssii',
        $fullName, $phone, $addressLine, $subdistrict, $district, $province, $postcode, $id, $uid
    );
    $upd->execute();
    $touched = $upd->affected_rows;
    $upd->close();

    // affected_rows เป็น 0 ได้ทั้งกรณีไม่ใช่เจ้าของ และกรณีข้อมูลเหมือนเดิม จึงต้องเช็คความเป็นเจ้าของแยก
    $own = $conn->prepare("SELECT id FROM user_addresses WHERE id=? AND user_id=? LIMIT 1");
    $own->bind_param('ii', $id, $uid);
    $own->execute();
    $exists = $own->get_result()->fetch_assoc();
    $own->close();
    if (!$exists) json_error('ไม่พบที่อยู่นี้ในบัญชีของคุณ', 404);

    if (!empty($input['is_default'])) {
        set_only_default($conn, $uid, $id);
    }

    json_response(['success' => true, 'message' => 'อัปเดตที่อยู่แล้ว', 'changed' => $touched > 0]);
}

// ══ SET DEFAULT ══
if ($action === 'default') {
    require_method('POST');
    $id = (int)($input['id'] ?? 0);
    if ($id <= 0) json_error('id ไม่ถูกต้อง');

    $own = $conn->prepare("SELECT id FROM user_addresses WHERE id=? AND user_id=? LIMIT 1");
    $own->bind_param('ii', $id, $uid);
    $own->execute();
    $exists = $own->get_result()->fetch_assoc();
    $own->close();
    if (!$exists) json_error('ไม่พบที่อยู่นี้ในบัญชีของคุณ', 404);

    set_only_default($conn, $uid, $id);
    json_response(['success' => true, 'message' => 'ตั้งเป็นที่อยู่หลักแล้ว']);
}

// ══ DELETE ══
if ($action === 'delete') {
    require_method('POST');
    $id = (int)($input['id'] ?? 0);
    if ($id <= 0) json_error('id ไม่ถูกต้อง');

    $wasDefault = false;
    $chk = $conn->prepare("SELECT is_default FROM user_addresses WHERE id=? AND user_id=? LIMIT 1");
    $chk->bind_param('ii', $id, $uid);
    $chk->execute();
    $row = $chk->get_result()->fetch_assoc();
    $chk->close();
    if (!$row) json_error('ไม่พบที่อยู่นี้ในบัญชีของคุณ', 404);
    $wasDefault = (bool)$row['is_default'];

    $del = $conn->prepare("DELETE FROM user_addresses WHERE id=? AND user_id=?");
    $del->bind_param('ii', $id, $uid);
    $del->execute();
    $del->close();

    // ถ้าลบที่อยู่หลักไป ให้เลื่อนรายการที่เหลือขึ้นมาเป็นหลักแทน
    if ($wasDefault) {
        $nx = $conn->prepare("SELECT id FROM user_addresses WHERE user_id=? ORDER BY id DESC LIMIT 1");
        $nx->bind_param('i', $uid);
        $nx->execute();
        $next = $nx->get_result()->fetch_assoc();
        $nx->close();
        if ($next) set_only_default($conn, $uid, (int)$next['id']);
    }

    json_response(['success' => true, 'message' => 'ลบที่อยู่แล้ว']);
}

json_error('Unknown action', 404);
