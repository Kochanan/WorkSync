<?php
/**
 * popular_products.php — โมดูลจัดอันดับสินค้ายอดนิยม
 *
 * จัดอันดับจากพฤติกรรมจริงของผู้ใช้ ไม่ใช่ให้คนมากดปักหมุดเอง
 * ใช้สัญญาณสามอย่างที่มีอยู่แล้วในระบบ จึงไม่ต้องแก้โครงสร้างฐานข้อมูล
 *
 *   ขายได้จริง  น้ำหนักสูงสุด เพราะเป็นสัญญาณเดียวที่ผู้ซื้อยอมจ่ายเงินจริง
 *   ถูกใจ       รองลงมา เป็นความตั้งใจซื้อแต่ยังไม่เกิดขึ้น
 *   รีวิว       บอกว่ามีคนใช้จริงแล้วกลับมาพูดถึง
 *
 * ยอดขายนับเฉพาะออเดอร์ที่จ่ายเงินแล้ว ออเดอร์ที่ยังค้างจ่ายหรือถูกยกเลิก
 * ไม่นับ ไม่งั้นจะปั่นอันดับได้ด้วยการกดสั่งแล้วไม่จ่าย
 *
 * ถ้ายังไม่มีสัญญาณพอ ฟังก์ชันจะคืน fallback = true กลับไป
 * เพื่อให้หน้าเว็บบอกผู้ใช้ตามตรงว่านี่คือสินค้ามาใหม่ ไม่ใช่อันดับความนิยม
 */

// น้ำหนักของแต่ละสัญญาณ ปรับตรงนี้ที่เดียวแล้วมีผลทั้งเว็บและแอป
const POPULAR_W_SOLD   = 4;
const POPULAR_W_WISH   = 2;
const POPULAR_W_REVIEW = 2;

// ช่วงเวลาที่นับย้อนหลัง กันไม่ให้สินค้าที่เคยดังเมื่อนานมาแล้วค้างอันดับตลอดไป
const POPULAR_DAYS = 90;

/**
 * ดึงสินค้ายอดนิยม
 *
 * @return array{items: array, fallback: bool, days: int}
 *         fallback = true แปลว่ายังไม่มีสัญญาณพอ รายการที่คืนมาคือสินค้าใหม่แทน
 */
function get_popular_products(mysqli $conn, int $limit = 8, int $days = POPULAR_DAYS): array
{
    $limit = max(1, min(50, $limit));
    $days  = max(1, min(3650, $days));

    $sql = "
        SELECT p.id, p.title, p.price, p.image, p.stock,
               COALESCE(s.sold, 0)          AS sold,
               COALESCE(w.wishes, 0)        AS wishes,
               COALESCE(r.reviews, 0)       AS reviews,
               COALESCE(r.avg_rating, 0)    AS avg_rating,
               (COALESCE(s.sold,0) * ? + COALESCE(w.wishes,0) * ? + COALESCE(r.reviews,0) * ?) AS score
        FROM products p
        LEFT JOIN (
            SELECT oi.product_id, SUM(oi.qty) AS sold
            FROM order_items oi
            JOIN orders o ON o.id = oi.order_id
            WHERE o.status IN ('paid','packing','shipping','delivered')
              AND o.created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
            GROUP BY oi.product_id
        ) s ON s.product_id = p.id
        LEFT JOIN (
            SELECT product_id, COUNT(*) AS wishes
            FROM wishlists
            WHERE created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
            GROUP BY product_id
        ) w ON w.product_id = p.id
        LEFT JOIN (
            SELECT product_id, COUNT(*) AS reviews, AVG(rating) AS avg_rating
            FROM reviews
            WHERE created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
            GROUP BY product_id
        ) r ON r.product_id = p.id
        HAVING score > 0
        ORDER BY (p.stock > 0) DESC, score DESC, avg_rating DESC, p.created_at DESC
        LIMIT ?
    ";

    $wSold = POPULAR_W_SOLD; $wWish = POPULAR_W_WISH; $wReview = POPULAR_W_REVIEW;

    $st = $conn->prepare($sql);
    $st->bind_param('iiiiiii', $wSold, $wWish, $wReview, $days, $days, $days, $limit);
    $st->execute();
    $rows = $st->get_result()->fetch_all(MYSQLI_ASSOC);
    $st->close();

    $fallback = false;

    // ยังไม่มีใครซื้อ กดถูกใจ หรือรีวิวเลย จึงยังจัดอันดับไม่ได้
    if (!$rows) {
        $fallback = true;
        $fb = $conn->prepare(
            "SELECT id, title, price, image, stock,
                    0 AS sold, 0 AS wishes, 0 AS reviews, 0 AS avg_rating, 0 AS score
             FROM products
             ORDER BY (stock > 0) DESC, created_at DESC
             LIMIT ?"
        );
        $fb->bind_param('i', $limit);
        $fb->execute();
        $rows = $fb->get_result()->fetch_all(MYSQLI_ASSOC);
        $fb->close();
    }

    foreach ($rows as &$r) {
        $r['id']         = (int)$r['id'];
        $r['price']      = (float)$r['price'];
        $r['stock']      = (int)$r['stock'];
        $r['sold']       = (int)$r['sold'];
        $r['wishes']     = (int)$r['wishes'];
        $r['reviews']    = (int)$r['reviews'];
        $r['avg_rating'] = round((float)$r['avg_rating'], 1);
        $r['score']      = (int)$r['score'];
        $r['reason']     = popular_reason($r);
    }
    unset($r);

    return ['items' => $rows, 'fallback' => $fallback, 'days' => $days];
}

/**
 * ข้อความสั้น ๆ บอกว่าทำไมสินค้านี้ติดอันดับ
 * เลือกสัญญาณที่แรงที่สุดมาแสดงอันเดียว ไม่ยัดตัวเลขทุกอย่างใส่การ์ด
 */
function popular_reason(array $r): string
{
    if ($r['sold'] > 0) {
        return 'ขายแล้ว ' . number_format($r['sold']) . ' ชิ้น';
    }
    if ($r['wishes'] > 0) {
        return number_format($r['wishes']) . ' คนถูกใจ';
    }
    if ($r['reviews'] > 0) {
        return $r['avg_rating'] . ' ดาว จาก ' . number_format($r['reviews']) . ' รีวิว';
    }
    return '';
}
