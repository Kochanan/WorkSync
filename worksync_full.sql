-- ======================================================
--  WorkSync — Full Database Schema (ล่าสุด 2568)
--  รัน SQL นี้ใน phpMyAdmin ครั้งเดียวได้เลย
--  ใช้ CREATE TABLE IF NOT EXISTS และ ADD COLUMN IF NOT EXISTS
--  ปลอดภัยสำหรับฐานข้อมูลที่มีข้อมูลอยู่แล้ว
-- ======================================================

CREATE DATABASE IF NOT EXISTS `worksync`
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE `worksync`;

-- ══════════════════════════════════════
-- 1. users
-- ══════════════════════════════════════
CREATE TABLE IF NOT EXISTS `users` (
  `id`             INT UNSIGNED      NOT NULL AUTO_INCREMENT,
  `username`       VARCHAR(100)      NOT NULL UNIQUE,
  `display_name`   VARCHAR(100)      NULL,
  `email`          VARCHAR(150)      NULL,
  `phone`          VARCHAR(20)       NULL,
  `bio`            VARCHAR(300)      NULL,
  `avatar`         VARCHAR(500)      NULL,
  `password`       VARCHAR(255)      NOT NULL,
  `role`           ENUM('user','admin') NOT NULL DEFAULT 'user',
  `is_verified`    TINYINT(1)        NOT NULL DEFAULT 0,
  `verify_token`   VARCHAR(64)       NULL,
  `token_expires`  DATETIME          NULL,
  `created_at`     DATETIME          NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`     DATETIME          NULL,
  PRIMARY KEY (`id`),
  KEY `idx_email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ══════════════════════════════════════
-- 2. categories
-- ══════════════════════════════════════
CREATE TABLE IF NOT EXISTS `categories` (
  `id`   INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(100)  NOT NULL,
  `icon` VARCHAR(10)   NOT NULL DEFAULT '📦',
  `sort` TINYINT       NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ══════════════════════════════════════
-- 3. products
-- ══════════════════════════════════════
CREATE TABLE IF NOT EXISTS `products` (
  `id`          INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `seller_id`   INT UNSIGNED  NOT NULL,
  `category_id` INT UNSIGNED  NULL,
  `title`       VARCHAR(255)  NOT NULL,
  `description` TEXT,
  `price`       DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `stock`       INT           NOT NULL DEFAULT 0,
  `image`       VARCHAR(500),
  `created_at`  DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`  DATETIME      NULL,
  PRIMARY KEY (`id`),
  KEY `idx_seller`   (`seller_id`),
  KEY `idx_category` (`category_id`),
  KEY `idx_created`  (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ══════════════════════════════════════
-- 4. user_addresses
-- ══════════════════════════════════════
CREATE TABLE IF NOT EXISTS `user_addresses` (
  `id`           INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `user_id`      INT UNSIGNED  NOT NULL,
  `full_name`    VARCHAR(200)  NOT NULL,
  `phone`        VARCHAR(20)   NOT NULL,
  `address_line` TEXT          NOT NULL,
  `subdistrict`  VARCHAR(100),
  `district`     VARCHAR(100),
  `province`     VARCHAR(100)  NOT NULL,
  `postcode`     VARCHAR(10)   NOT NULL,
  `is_default`   TINYINT(1)    NOT NULL DEFAULT 0,
  `created_at`   DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ══════════════════════════════════════
-- 5. cart
-- ══════════════════════════════════════
CREATE TABLE IF NOT EXISTS `cart` (
  `id`         INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `user_id`    INT UNSIGNED  NOT NULL,
  `product_id` INT UNSIGNED  NOT NULL,
  `qty`        INT           NOT NULL DEFAULT 1,
  `selected`   TINYINT(1)    NOT NULL DEFAULT 1,
  `created_at` DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_user_product` (`user_id`, `product_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ══════════════════════════════════════
-- 6. orders
-- ══════════════════════════════════════
CREATE TABLE IF NOT EXISTS `orders` (
  `id`              INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `user_id`         INT UNSIGNED  NOT NULL,
  `address_id`      INT UNSIGNED  NULL,
  `total`           DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `shipping_fee`    DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `coupon_code`     VARCHAR(20)   NULL,
  `discount_amount` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `status`          ENUM('pending','paid','packing','shipping','delivered','cancelled')
                                  NOT NULL DEFAULT 'pending',
  `payment_method`  VARCHAR(50)   NOT NULL DEFAULT 'simulator',
  `tracking_number` VARCHAR(100)  NULL,
  `tracking_url`    VARCHAR(300)  NULL,
  `paid_at`         DATETIME      NULL,
  `delivered_at`    DATETIME      NULL,
  `created_at`      DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_user`   (`user_id`),
  KEY `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ══════════════════════════════════════
-- 7. order_items
-- ══════════════════════════════════════
CREATE TABLE IF NOT EXISTS `order_items` (
  `id`             INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `order_id`       INT UNSIGNED  NOT NULL,
  `product_id`     INT UNSIGNED  NOT NULL,
  `qty`            INT           NOT NULL DEFAULT 1,
  `price`          DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `title_snapshot` VARCHAR(255)  NOT NULL,
  `image_snapshot` VARCHAR(500),
  PRIMARY KEY (`id`),
  KEY `idx_order` (`order_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ══════════════════════════════════════
-- 8. notifications
-- ══════════════════════════════════════
CREATE TABLE IF NOT EXISTS `notifications` (
  `id`         INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `user_id`    INT UNSIGNED  NOT NULL,
  `title`      VARCHAR(255)  NOT NULL,
  `message`    TEXT          NOT NULL,
  `is_read`    TINYINT(1)    NOT NULL DEFAULT 0,
  `created_at` DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_user_read` (`user_id`, `is_read`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ══════════════════════════════════════
-- 9. otp_codes
-- ══════════════════════════════════════
CREATE TABLE IF NOT EXISTS `otp_codes` (
  `id`         INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `user_id`    INT UNSIGNED  NOT NULL,
  `email`      VARCHAR(150)  NOT NULL,
  `code`       VARCHAR(6)    NOT NULL,
  `type`       ENUM('verify','reset') NOT NULL DEFAULT 'verify',
  `is_used`    TINYINT(1)    NOT NULL DEFAULT 0,
  `expires_at` DATETIME      NOT NULL,
  `created_at` DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_email_type` (`email`, `type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ══════════════════════════════════════
-- 10. conversations (Support Chat)
-- ══════════════════════════════════════
CREATE TABLE IF NOT EXISTS `conversations` (
  `id`              INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `user_id`         INT UNSIGNED  NOT NULL,
  `last_message_at` DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `unread_user`     INT           NOT NULL DEFAULT 0,
  `unread_admin`    INT           NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ══════════════════════════════════════
-- 11. messages (Support Chat)
-- ══════════════════════════════════════
CREATE TABLE IF NOT EXISTS `messages` (
  `id`              INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `conversation_id` INT UNSIGNED  NOT NULL,
  `sender_id`       INT UNSIGNED  NOT NULL,
  `sender_role`     ENUM('user','admin') NOT NULL,
  `body`            TEXT          NOT NULL,
  `is_read`         TINYINT(1)    NOT NULL DEFAULT 0,
  `created_at`      DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_conv` (`conversation_id`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ══════════════════════════════════════
-- 12. seller_conversations (Seller Chat)
-- ══════════════════════════════════════
CREATE TABLE IF NOT EXISTS `seller_conversations` (
  `id`              INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `product_id`      INT UNSIGNED  NOT NULL,
  `buyer_id`        INT UNSIGNED  NOT NULL,
  `seller_id`       INT UNSIGNED  NOT NULL,
  `last_message_at` DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `unread_buyer`    INT           NOT NULL DEFAULT 0,
  `unread_seller`   INT           NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_product_buyer` (`product_id`, `buyer_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ══════════════════════════════════════
-- 13. seller_messages (Seller Chat)
-- ══════════════════════════════════════
CREATE TABLE IF NOT EXISTS `seller_messages` (
  `id`              INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `conversation_id` INT UNSIGNED  NOT NULL,
  `sender_id`       INT UNSIGNED  NOT NULL,
  `body`            TEXT          NOT NULL,
  `is_read`         TINYINT(1)    NOT NULL DEFAULT 0,
  `created_at`      DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_conv` (`conversation_id`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ══════════════════════════════════════
-- 14. reviews
-- ══════════════════════════════════════
CREATE TABLE IF NOT EXISTS `reviews` (
  `id`         INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `product_id` INT UNSIGNED  NOT NULL,
  `user_id`    INT UNSIGNED  NOT NULL,
  `order_id`   INT UNSIGNED  NULL,
  `rating`     TINYINT       NOT NULL DEFAULT 5,
  `body`       TEXT          NOT NULL,
  `created_at` DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_user_product` (`user_id`, `product_id`),
  KEY `idx_product` (`product_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ══════════════════════════════════════
-- 15. review_images
-- ══════════════════════════════════════
CREATE TABLE IF NOT EXISTS `review_images` (
  `id`        INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `review_id` INT UNSIGNED  NOT NULL,
  `path`      VARCHAR(500)  NOT NULL,
  `sort`      TINYINT       NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_review` (`review_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ══════════════════════════════════════
-- 16. wishlists
-- ══════════════════════════════════════
CREATE TABLE IF NOT EXISTS `wishlists` (
  `id`         INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `user_id`    INT UNSIGNED  NOT NULL,
  `product_id` INT UNSIGNED  NOT NULL,
  `created_at` DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_user_product` (`user_id`, `product_id`),
  KEY `idx_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ══════════════════════════════════════
-- 17. coupons
-- ══════════════════════════════════════
CREATE TABLE IF NOT EXISTS `coupons` (
  `id`          INT UNSIGNED   NOT NULL AUTO_INCREMENT,
  `seller_id`   INT UNSIGNED   NOT NULL,
  `code`        VARCHAR(20)    NOT NULL,
  `type`        ENUM('percent','fixed') NOT NULL DEFAULT 'percent',
  `value`       DECIMAL(10,2)  NOT NULL DEFAULT 0,
  `min_order`   DECIMAL(10,2)  NOT NULL DEFAULT 0,
  `max_uses`    INT            NOT NULL DEFAULT 100,
  `used_count`  INT            NOT NULL DEFAULT 0,
  `is_active`   TINYINT(1)     NOT NULL DEFAULT 1,
  `expires_at`  DATETIME       NULL,
  `created_at`  DATETIME       NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_code` (`code`),
  KEY `idx_seller` (`seller_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ══════════════════════════════════════
-- DEFAULT DATA
-- ══════════════════════════════════════

-- Admin account
-- รหัสผ่านของ hash ด้านล่างคือ "password" (คอมเมนต์เดิมเขียนว่า admin1234 ซึ่งไม่ตรงกับ hash)
-- ⚠️ รหัสนี้อ่อนมากและเปิดเผยอยู่ในไฟล์นี้ ห้ามใช้บนเครื่องที่เปิดออกอินเทอร์เน็ต
--    หลังติดตั้งเสร็จให้เข้าไปเปลี่ยนรหัสผ่านที่หน้าโปรไฟล์ทันที
INSERT IGNORE INTO `users` (`username`, `password`, `role`, `is_verified`) VALUES
('admin', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'admin', 1);

-- หมวดหมู่สินค้า
INSERT IGNORE INTO `categories` (`id`, `name`, `icon`, `sort`) VALUES
(1, 'อาหารและเครื่องดื่ม', '🍜', 1),
(2, 'เสื้อผ้าและแฟชั่น',   '👕', 2),
(3, 'อิเล็กทรอนิกส์',      '📱', 3),
(4, 'บ้านและสวน',          '🏠', 4),
(5, 'สุขภาพและความงาม',    '💄', 5),
(6, 'กีฬาและกิจกรรม',      '⚽', 6),
(7, 'หนังสือและการศึกษา',  '📚', 7),
(8, 'อื่นๆ',               '📦', 8);
