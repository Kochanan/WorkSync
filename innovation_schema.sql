-- ══════════════════════════════════════════════════════
--  WorkSync Innovation Schema — รันต่อจาก worksync_full.sql
-- ══════════════════════════════════════════════════════

USE `worksync`;

-- ── Trust Score Cache ────────────────────────────────
CREATE TABLE IF NOT EXISTS `seller_trust_scores` (
  `seller_id`   INT UNSIGNED  NOT NULL,
  `score`       DECIMAL(5,2)  NOT NULL DEFAULT 0,
  `badge_label` VARCHAR(50)   NOT NULL DEFAULT 'ผู้ขายใหม่',
  `badge_color` VARCHAR(10)   NOT NULL DEFAULT '#607d8b',
  `badge_icon`  VARCHAR(5)    NOT NULL DEFAULT '🆕',
  `updated_at`  DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`seller_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Community Lending ────────────────────────────────
CREATE TABLE IF NOT EXISTS `product_lending_info` (
  `product_id`        INT UNSIGNED  NOT NULL,
  `is_lendable`       TINYINT(1)    NOT NULL DEFAULT 0,
  `lending_days_limit` INT          NOT NULL DEFAULT 7,
  `deposit_required`  DECIMAL(10,2) NOT NULL DEFAULT 0,
  `lending_note`      VARCHAR(300)  NULL,
  PRIMARY KEY (`product_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `lending_records` (
  `id`             INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `product_id`     INT UNSIGNED  NOT NULL,
  `borrower_id`    INT UNSIGNED  NOT NULL,
  `borrow_days`    INT           NOT NULL DEFAULT 7,
  `borrow_date`    DATE          NOT NULL,
  `return_due`     DATE          NOT NULL,
  `actual_return`  DATE          NULL,
  `status`         ENUM('borrowed','returned','overdue') NOT NULL DEFAULT 'borrowed',
  `created_at`     DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_product` (`product_id`),
  KEY `idx_borrower` (`borrower_id`),
  KEY `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Price Recommendation Log (เก็บ history การแนะนำ) ─
CREATE TABLE IF NOT EXISTS `price_recommend_logs` (
  `id`          INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `seller_id`   INT UNSIGNED  NOT NULL,
  `category_id` INT UNSIGNED  NOT NULL,
  `median_price` DECIMAL(10,2) NOT NULL,
  `suggested_price` DECIMAL(10,2) NOT NULL,
  `strategy`    ENUM('competitive','optimal','premium') NOT NULL,
  `created_at`  DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_seller` (`seller_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Trigger: อัปเดต lending_records เป็น overdue อัตโนมัติ ─
DROP EVENT IF EXISTS `check_overdue_lending`;
CREATE EVENT `check_overdue_lending`
  ON SCHEDULE EVERY 1 DAY
  STARTS CURRENT_TIMESTAMP
  DO
    UPDATE lending_records
    SET status = 'overdue'
    WHERE status = 'borrowed' AND return_due < CURDATE();
