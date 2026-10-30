-- ------------------------------------------------------------
-- migrate_live.sql
-- Brings the live (InfinityFree) database up to the current
-- TicketHUB schema. Safe to run more than once: it only adds
-- what is missing and never drops tables, columns or rows.
--
-- How to run: phpMyAdmin → select if0_42594762_tickethub →
-- SQL tab → paste this whole file → Go.
-- ------------------------------------------------------------

-- 1. Existing tables: switch to InnoDB + utf8mb4 (needed for transactions & emoji)
ALTER TABLE `users`  ENGINE = InnoDB, CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE `events` ENGINE = InnoDB, CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- 2. users
ALTER TABLE `users`
  MODIFY `role` ENUM('super_admin','admin','customer') NOT NULL DEFAULT 'customer',
  ADD COLUMN IF NOT EXISTS `phone` VARCHAR(20) DEFAULT NULL AFTER `email`,
  ADD COLUMN IF NOT EXISTS `loyalty_points` INT(11) NOT NULL DEFAULT 0 AFTER `role`;

-- 3. events: per-event commission
ALTER TABLE `events`
  MODIFY `description` MEDIUMTEXT DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `commission_type` ENUM('percentage','fixed') NOT NULL DEFAULT 'percentage' AFTER `tickets_sold`,
  ADD COLUMN IF NOT EXISTS `commission_value` DECIMAL(10,2) NOT NULL DEFAULT 5.00 AFTER `commission_type`;

-- 4. bookings
CREATE TABLE IF NOT EXISTS `bookings` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `event_id` int(11) NOT NULL,
  `quantity` int(11) NOT NULL DEFAULT 1,
  `total_price` decimal(10,2) NOT NULL,
  `booking_ref` varchar(20) NOT NULL,
  `status` enum('confirmed','cancelled') NOT NULL DEFAULT 'confirmed',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `booking_ref` (`booking_ref`),
  KEY `idx_user` (`user_id`),
  KEY `idx_event` (`event_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE `bookings` ENGINE = InnoDB, CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

ALTER TABLE `bookings`
  ADD COLUMN IF NOT EXISTS `payment_id` INT(11) DEFAULT NULL AFTER `event_id`,
  ADD COLUMN IF NOT EXISTS `commission_amount` DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER `total_price`,
  ADD COLUMN IF NOT EXISTS `points_earned` INT(11) NOT NULL DEFAULT 0 AFTER `status`,
  ADD COLUMN IF NOT EXISTS `phone` VARCHAR(20) DEFAULT NULL AFTER `points_earned`,
  ADD COLUMN IF NOT EXISTS `ticket_token` CHAR(32) DEFAULT NULL AFTER `phone`,
  ADD COLUMN IF NOT EXISTS `email_sent_at` DATETIME DEFAULT NULL AFTER `ticket_token`,
  ADD COLUMN IF NOT EXISTS `sms_sent_at` DATETIME DEFAULT NULL AFTER `email_sent_at`,
  ADD COLUMN IF NOT EXISTS `notify_error` VARCHAR(255) DEFAULT NULL AFTER `sms_sent_at`,
  ADD COLUMN IF NOT EXISTS `checked_in_at` DATETIME DEFAULT NULL AFTER `notify_error`,
  ADD COLUMN IF NOT EXISTS `checked_in_by` INT(11) DEFAULT NULL AFTER `checked_in_at`,
  ADD COLUMN IF NOT EXISTS `is_inside` TINYINT(1) NOT NULL DEFAULT 0 AFTER `checked_in_by`,
  ADD UNIQUE KEY IF NOT EXISTS `uniq_payment` (`payment_id`);

-- Every door scan (entries, exits and rejected attempts) for the check-in history
CREATE TABLE IF NOT EXISTS `ticket_scans` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `booking_id` int(11) NOT NULL,
  `event_id` int(11) NOT NULL,
  `direction` enum('in','out') NOT NULL,
  `accepted` tinyint(1) NOT NULL DEFAULT 1,
  `result` varchar(30) NOT NULL,
  `method` enum('qr','qr_legacy','manual') NOT NULL DEFAULT 'qr',
  `scanned_by` int(11) DEFAULT NULL,
  `scanned_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_booking` (`booking_id`,`scanned_at`),
  KEY `idx_event` (`event_id`,`scanned_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 5. New tables
CREATE TABLE IF NOT EXISTS `payments` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `event_id` int(11) NOT NULL,
  `quantity` int(11) NOT NULL,
  `unit_price` decimal(10,2) NOT NULL,
  `amount_total` decimal(10,2) NOT NULL,
  `phone` varchar(20) DEFAULT NULL,
  `stripe_session_id` varchar(255) DEFAULT NULL,
  `stripe_payment_intent` varchar(255) DEFAULT NULL,
  `status` enum('pending','paid','failed','expired','cancelled','refunded') NOT NULL DEFAULT 'pending',
  `booking_id` int(11) DEFAULT NULL,
  `failure_reason` varchar(255) DEFAULT NULL,
  `expires_at` datetime NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `stripe_session_id` (`stripe_session_id`),
  KEY `idx_hold` (`event_id`,`status`,`expires_at`),
  KEY `idx_user_status` (`user_id`,`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `password_resets` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `email` varchar(150) NOT NULL,
  `token` varchar(64) NOT NULL,
  `expires_at` datetime NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `token` (`token`),
  KEY `idx_email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `platform_settings` (
  `setting_key` varchar(64) NOT NULL,
  `setting_value` varchar(255) NOT NULL,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT IGNORE INTO `platform_settings` (`setting_key`, `setting_value`) VALUES
  ('default_commission_type', 'percentage'),
  ('default_commission_value', '10');

CREATE TABLE IF NOT EXISTS `loyalty_transactions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `booking_id` int(11) DEFAULT NULL,
  `points` int(11) NOT NULL,
  `reason` varchar(150) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `user_id` (`user_id`),
  KEY `booking_id` (`booking_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `user_category_affinity` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `category` varchar(50) NOT NULL,
  `score` decimal(6,2) NOT NULL DEFAULT 0.00,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_user_category` (`user_id`,`category`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `agent_conversations` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) DEFAULT NULL,
  `session_id` varchar(64) NOT NULL,
  `sender` enum('user','agent') NOT NULL,
  `message` mediumtext NOT NULL,
  `intent` varchar(50) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `user_id` (`user_id`),
  KEY `idx_session` (`session_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 6. Other existing tables → InnoDB + utf8mb4 (no-op if already converted)
ALTER TABLE `password_resets`        ENGINE = InnoDB, CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE `loyalty_transactions`   ENGINE = InnoDB, CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE `user_category_affinity` ENGINE = InnoDB, CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE `agent_conversations`    ENGINE = InnoDB, CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
