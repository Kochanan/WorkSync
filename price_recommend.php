<?php
/**
 * price_recommend.php — ระบบแนะนำราคาขายอัตโนมัติ
 *
 * อัลกอริทึม:
 * 1. หาสินค้าหมวดเดียวกันที่ขายดี (sold > 0)
 * 2. คำนวณ median price และ std deviation
 * 3. หา "sweet spot" = ราคาที่ขายดีสุดในช่วงนั้น
 * 4. แนะนำ 3 กลยุทธ์: Competitive / Optimal / Premium
 */

require_once 'init.php';
header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['ok' => false, 'msg' => 'กรุณาเข้าสู่ระบบ']);
    exit;
}

$catId = (int)($_GET['cat_id'] ?? 0);

if ($catId <= 0) {
    echo json_encode(['ok' => false, 'msg' => 'กรุณาระบุหมวดหมู่สินค้า']);
    exit;
}

// ── ดึงข้อมูลราคาและยอดขายสินค้าหมวดเดียวกัน ────────────────
$st = $conn->prepare("
    SELECT
        p.price,
        -- นับเฉพาะรายการที่อยู่ในออเดอร์ delivered (เดิม SUM ทุกออเดอร์ รวมที่ยกเลิก เพราะเงื่อนไขอยู่ใน LEFT JOIN)
        COALESCE(SUM(CASE WHEN o.id IS NOT NULL THEN oi.qty ELSE 0 END), 0) AS sold
    FROM products p
    LEFT JOIN order_items oi ON oi.product_id = p.id
    LEFT JOIN orders o ON o.id = oi.order_id AND o.status = 'delivered'
    WHERE p.category_id = ? AND p.stock > 0
    GROUP BY p.id
    ORDER BY p.price ASC
");
$st->bind_param('i', $catId);
$st->execute();
$rows = $st->get_result()->fetch_all(MYSQLI_ASSOC);
$st->close();

if (count($rows) < 3) {
    echo json_encode(['ok' => false, 'msg' => 'ข้อมูลสินค้าในหมวดนี้ยังน้อยเกินไป']);
    exit;
}

// ── คำนวณสถิติ ───────────────────────────────────────────────
$prices = array_map('floatval', array_column($rows, 'price'));
$n = count($prices);

// Mean
$mean = array_sum($prices) / $n;

// Median
sort($prices);
$median = $n % 2 === 0
    ? ($prices[$n/2 - 1] + $prices[$n/2]) / 2
    : $prices[intval($n/2)];

// Std deviation
$variance = array_sum(array_map(fn($p) => pow($p - $mean, 2), $prices)) / $n;
$std = sqrt($variance);

// ── หา "Sweet Spot" — ราคาที่ขายดีสุด ───────────────────────
// แบ่งราคาเป็น 3 zone: ต่ำ / กลาง / สูง
// นับ sold ในแต่ละ zone แล้วหา zone ที่ขายดีสุด
$zones = ['low' => 0, 'mid' => 0, 'high' => 0];
foreach ($rows as $r) {
    if ($r['price'] < $median - $std * 0.5)     $zones['low']  += $r['sold'];
    elseif ($r['price'] <= $median + $std * 0.5) $zones['mid']  += $r['sold'];
    else                                          $zones['high'] += $r['sold'];
}
arsort($zones);
$bestZone = array_key_first($zones);

// ── 3 กลยุทธ์ราคา ────────────────────────────────────────────
// ปัดเป็นหลักสิบ แต่ห้ามต่ำกว่า 1 บาท (เดิมสินค้าราคาถูกปัดแล้วกลายเป็น 0 บาท กดใช้แล้วฟอร์มไม่ผ่าน)
$competitive = max(1, round(max(1, $median - $std * 0.3), -1)); // ต่ำกว่าตลาด
$optimal     = max(1, round($median, -1));                        // กลางตลาด (median)
$premium     = max(1, round($median + $std * 0.5, -1));          // สูงกว่าตลาด

// คำอธิบายแต่ละกลยุทธ์
$strategies = [
    [
        'name'    => '🏷️ ราคาแข่งขัน (Competitive)',
        'price'   => $competitive,
        'desc'    => 'ต่ำกว่าราคาตลาดเฉลี่ย เหมาะสำหรับผู้ขายใหม่ที่ต้องการสร้างยอดขายเร็ว',
        'pros'    => 'ขายง่าย ลูกค้าเลือกก่อน',
        'cons'    => 'กำไรน้อย ต้องมีสต็อกเยอะ',
    ],
    [
        'name'    => '⭐ ราคาเหมาะสม (Optimal)',
        'price'   => $optimal,
        'desc'    => 'ใกล้เคียงราคากลางของตลาด เหมาะกับสินค้าคุณภาพมาตรฐาน',
        'pros'    => 'สมดุลระหว่างยอดขายและกำไร',
        'cons'    => 'ต้องสร้างความแตกต่างด้านอื่น',
        'recommended' => true,
    ],
    [
        'name'    => '💎 ราคาพรีเมียม (Premium)',
        'price'   => $premium,
        'desc'    => 'สูงกว่าตลาด เหมาะสำหรับสินค้าที่มีจุดเด่นพิเศษหรือแบรนด์ที่น่าเชื่อถือ',
        'pros'    => 'กำไรสูง ภาพลักษณ์ดี',
        'cons'    => 'ขายยากกว่า ต้องมี Trust Score สูง',
    ],
];

echo json_encode([
    'ok'         => true,
    'category_id'=> $catId,
    'market_data' => [
        'sample_size' => $n,
        'median'      => round($median, 2),
        'mean'        => round($mean, 2),
        'std_dev'     => round($std, 2),
        'price_range' => ['min' => min($prices), 'max' => max($prices)],
        'best_zone'   => $bestZone,
    ],
    'strategies' => $strategies,
    'tip' => $bestZone === 'low'
        ? '💡 สินค้าหมวดนี้ลูกค้านิยมราคาถูก กลยุทธ์ Competitive จะได้เปรียบ'
        : ($bestZone === 'high'
            ? '💡 สินค้าหมวดนี้ลูกค้ายอมจ่ายราคาสูงถ้าคุณภาพดี กลยุทธ์ Premium คุ้มค่า'
            : '💡 ตลาดหมวดนี้แข่งขันที่ราคากลาง ควรเน้นคุณภาพและ Trust Score'),
]);
