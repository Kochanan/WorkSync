<?php
/**
 * trust_score.php — คำนวณ Trust Score ของผู้ขาย
 * 
 * สมการ: TrustScore = (R×0.35) + (D×0.25) + (C×0.20) + (S×0.20)
 * 
 * R = Review Score        (รีวิวจากผู้ซื้อจริงเท่านั้น + time-decay)
 * D = Delivery Score      (ความสม่ำเสมอส่งสินค้าหลังชำระเงิน)
 * C = Chat Response Score (ความเร็วในการตอบแชท)
 * S = Sales Volume Score  (ปริมาณยอดขายสะสม)
 */

require_once 'init.php';

/**
 * คำนวณ Trust Score ของผู้ขาย 1 คน
 * คืนค่า 0.0 - 100.0
 */
function calculate_trust_score(mysqli $conn, int $sellerId): array {

    // ── R: Review Score ──────────────────────────────────────
    // ดึงรีวิวเฉพาะคนที่ซื้อจริง (join order_items)
    // ใช้ time-decay: น้ำหนักรีวิวเก่า = e^(-λt) โดย λ=0.005/วัน
    $st = $conn->prepare("
        SELECT r.rating, r.created_at
        FROM reviews r
        INNER JOIN order_items oi ON oi.product_id = r.product_id
        INNER JOIN orders o ON o.id = oi.order_id AND o.user_id = r.user_id
        WHERE r.product_id IN (SELECT id FROM products WHERE seller_id = ?)
          AND o.status = 'delivered'
        GROUP BY r.id
    ");
    $st->bind_param('i', $sellerId);
    $st->execute();
    $reviews = $st->get_result()->fetch_all(MYSQLI_ASSOC);
    $st->close();

    $weightedSum = 0;
    $weightTotal = 0;
    $lambda = 0.005; // decay rate ต่อวัน

    foreach ($reviews as $r) {
        $daysAgo = (time() - strtotime($r['created_at'])) / 86400;
        $weight  = exp(-$lambda * $daysAgo); // time-decay
        $weightedSum += ($r['rating'] / 5) * $weight * 100;
        $weightTotal += $weight;
    }

    $R = $weightTotal > 0 ? ($weightedSum / $weightTotal) : 50; // default 50 ถ้าไม่มีรีวิว
    $reviewCount = count($reviews);

    // ── D: Delivery Score ────────────────────────────────────
    // วัดจาก: paid → delivered ภายในกี่วัน
    // ดี (<= 3 วัน) = 100, ปานกลาง (4-7 วัน) = 70, ช้า (>7 วัน) = 30
    // ใช้ orders.delivered_at (บันทึกตอนสถานะเป็น delivered) เทียบกับ paid_at
    // ของเดิมเขียน updated_at เฉย ๆ ซึ่ง MySQL ไปหยิบ products.updated_at มาแทน → คะแนน D ผิด
    // นับเฉพาะออเดอร์ที่ระบุเวลาได้ และใช้ค่ากลาง 50 ถ้าไม่มีข้อมูล
    $st = $conn->prepare("
        SELECT
            COUNT(d.days) total,
            SUM(CASE WHEN d.days <= 3 THEN 1 ELSE 0 END) fast,
            SUM(CASE WHEN d.days BETWEEN 4 AND 7 THEN 1 ELSE 0 END) medium,
            SUM(CASE WHEN d.days > 7 THEN 1 ELSE 0 END) slow
        FROM (
            SELECT DISTINCT o.id, DATEDIFF(o.delivered_at, o.paid_at) days
            FROM orders o
            INNER JOIN order_items oi ON oi.order_id = o.id
            INNER JOIN products p ON p.id = oi.product_id
            WHERE p.seller_id = ? AND o.status = 'delivered' AND o.paid_at IS NOT NULL
        ) d
    ");
    $st->bind_param('i', $sellerId);
    $st->execute();
    $delivery = $st->get_result()->fetch_assoc();
    $st->close();

    $D = 50; // default
    if ((int)$delivery['total'] > 0) {
        $D = (($delivery['fast'] * 100) + ($delivery['medium'] * 70) + ($delivery['slow'] * 30))
             / $delivery['total'];
    }

    // ── C: Chat Response Score ───────────────────────────────
    // วัดจาก: ลูกค้าส่งข้อความ → ผู้ขายตอบภายในกี่ชั่วโมง
    // < 1 ชม = 100, 1-6 ชม = 75, 6-24 ชม = 50, > 24 ชม = 20
    $st = $conn->prepare("
        SELECT
            COUNT(*) total,
            SUM(CASE WHEN TIMESTAMPDIFF(HOUR, buyer_msg.created_at, seller_reply.created_at) < 1   THEN 1 ELSE 0 END) very_fast,
            SUM(CASE WHEN TIMESTAMPDIFF(HOUR, buyer_msg.created_at, seller_reply.created_at) BETWEEN 1  AND 6  THEN 1 ELSE 0 END) fast,
            SUM(CASE WHEN TIMESTAMPDIFF(HOUR, buyer_msg.created_at, seller_reply.created_at) BETWEEN 7  AND 24 THEN 1 ELSE 0 END) medium,
            SUM(CASE WHEN TIMESTAMPDIFF(HOUR, buyer_msg.created_at, seller_reply.created_at) > 24  THEN 1 ELSE 0 END) slow
        FROM seller_conversations sc
        JOIN (
            SELECT conversation_id, MIN(created_at) created_at
            FROM seller_messages sm
            WHERE sm.sender_id != ?
            GROUP BY conversation_id
        ) buyer_msg ON buyer_msg.conversation_id = sc.id
        JOIN (
            SELECT conversation_id, MIN(sm2.created_at) created_at
            FROM seller_messages sm2
            WHERE sm2.sender_id = ?
            GROUP BY conversation_id
        ) seller_reply ON seller_reply.conversation_id = sc.id
        WHERE sc.seller_id = ?
          AND seller_reply.created_at > buyer_msg.created_at
    ");
    $st->bind_param('iii', $sellerId, $sellerId, $sellerId);
    $st->execute();
    $chat = $st->get_result()->fetch_assoc();
    $st->close();

    $C = 50; // default
    if ((int)$chat['total'] > 0) {
        $C = (($chat['very_fast'] * 100) + ($chat['fast'] * 75) + ($chat['medium'] * 50) + ($chat['slow'] * 20))
             / $chat['total'];
    }

    // ── S: Sales Volume Score ────────────────────────────────
    // normalize ยอดขายสะสมเทียบกับ top seller ในระบบ
    $st = $conn->prepare("
        SELECT COALESCE(SUM(oi.qty), 0) total_sold
        FROM order_items oi
        JOIN products p ON p.id = oi.product_id
        JOIN orders o ON o.id = oi.order_id
        WHERE p.seller_id = ? AND o.status = 'delivered'
    ");
    $st->bind_param('i', $sellerId);
    $st->execute();
    $sold = (int)$st->get_result()->fetch_row()[0];
    $st->close();

    // ดึง max sold ในระบบ (normalize)
    $maxSold = (int)$conn->query("
        SELECT COALESCE(MAX(cnt),1) FROM (
            SELECT SUM(oi.qty) cnt
            FROM order_items oi
            JOIN products p ON p.id = oi.product_id
            JOIN orders o ON o.id = oi.order_id
            WHERE o.status = 'delivered'
            GROUP BY p.seller_id
        ) t
    ")->fetch_row()[0];

    $S = min(100, ($sold / $maxSold) * 100);

    // ── Final Score ──────────────────────────────────────────
    // TrustScore = R×0.35 + D×0.25 + C×0.20 + S×0.20
    $score = ($R * 0.35) + ($D * 0.25) + ($C * 0.20) + ($S * 0.20);
    $score = round(min(100, max(0, $score)), 1);

    // Badge label
    $badge = match(true) {
        $score >= 90 => ['label' => 'ผู้ขายระดับเพชร',  'color' => '#00bcd4', 'icon' => '💎'],
        $score >= 75 => ['label' => 'ผู้ขายระดับทอง',   'color' => '#ffc107', 'icon' => '🥇'],
        $score >= 60 => ['label' => 'ผู้ขายระดับเงิน',   'color' => '#9e9e9e', 'icon' => '🥈'],
        $score >= 40 => ['label' => 'ผู้ขายทั่วไป',      'color' => '#ff9800', 'icon' => '🏪'],
        default      => ['label' => 'ผู้ขายใหม่',        'color' => '#607d8b', 'icon' => '🆕'],
    };

    return [
        'score'        => $score,
        'badge'        => $badge,
        'components'   => [
            'review_score'   => round($R, 1),
            'delivery_score' => round($D, 1),
            'chat_score'     => round($C, 1),
            'sales_score'    => round($S, 1),
        ],
        'review_count' => $reviewCount,
        'sold_count'   => $sold,
    ];
}

/**
 * ดึง Trust Score แบบ cache (เก็บใน DB ไม่คำนวณทุกครั้ง)
 * ใช้ตอนแสดงในหน้า product / shop
 */
function get_trust_score(mysqli $conn, int $sellerId): array {
    // เช็ค cache ใน seller_trust_scores
    $st = $conn->prepare(
        "SELECT score, badge_label, badge_color, badge_icon, updated_at
         FROM seller_trust_scores WHERE seller_id = ? LIMIT 1"
    );
    $st->bind_param('i', $sellerId);
    $st->execute();
    $cached = $st->get_result()->fetch_assoc();
    $st->close();

    // คำนวณใหม่ถ้าไม่มี cache หรือ cache เก่าเกิน 24 ชั่วโมง
    $needRecalc = !$cached ||
                  (time() - strtotime($cached['updated_at'])) > 86400;

    if ($needRecalc) {
        $result = calculate_trust_score($conn, $sellerId);

        $score = $result['score'];
        $label = $result['badge']['label'];
        $color = $result['badge']['color'];
        $icon  = $result['badge']['icon'];

        $upsert = $conn->prepare("
            INSERT INTO seller_trust_scores (seller_id, score, badge_label, badge_color, badge_icon, updated_at)
            VALUES (?, ?, ?, ?, ?, NOW())
            ON DUPLICATE KEY UPDATE
                score = VALUES(score),
                badge_label = VALUES(badge_label),
                badge_color = VALUES(badge_color),
                badge_icon  = VALUES(badge_icon),
                updated_at  = NOW()
        ");
        $upsert->bind_param('idsss', $sellerId, $score, $label, $color, $icon);
        $upsert->execute();
        $upsert->close();

        return $result;
    }

    return [
        'score' => (float)$cached['score'],
        'badge' => [
            'label' => $cached['badge_label'],
            'color' => $cached['badge_color'],
            'icon'  => $cached['badge_icon'],
        ],
    ];
}
