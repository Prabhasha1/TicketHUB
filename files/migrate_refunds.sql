-- ---------------------------------------------------------
-- Event status (postpone / cancel / reschedule) + refund requests
-- Run once in phpMyAdmin on the live database
-- (or just open files/setup_platform.php, which does the same).
-- ---------------------------------------------------------

ALTER TABLE `events`
  ADD COLUMN IF NOT EXISTS `status` ENUM('on_sale','sales_paused','postponed','rescheduled','cancelled') NOT NULL DEFAULT 'on_sale' AFTER `commission_value`,
  ADD COLUMN IF NOT EXISTS `status_note` VARCHAR(255) DEFAULT NULL AFTER `status`,
  ADD COLUMN IF NOT EXISTS `status_changed_at` DATETIME DEFAULT NULL AFTER `status_note`,
  ADD COLUMN IF NOT EXISTS `status_changed_by` INT(11) DEFAULT NULL AFTER `status_changed_at`,
  ADD COLUMN IF NOT EXISTS `original_event_date` DATETIME DEFAULT NULL AFTER `status_changed_by`;

CREATE TABLE IF NOT EXISTS `event_status_history` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `event_id` int(11) NOT NULL,
  `old_status` varchar(20) NOT NULL,
  `new_status` varchar(20) NOT NULL,
  `old_date` datetime DEFAULT NULL,
  `new_date` datetime DEFAULT NULL,
  `note` varchar(255) DEFAULT NULL,
  `changed_by` int(11) DEFAULT NULL,
  `changed_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_event` (`event_id`,`changed_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `refund_requests` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `booking_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `event_id` int(11) NOT NULL,
  `reason` varchar(30) NOT NULL,
  `message` text DEFAULT NULL,
  `amount_requested` decimal(10,2) NOT NULL,
  `status` enum('pending','refunded','rejected','withdrawn') NOT NULL DEFAULT 'pending',
  `refund_amount` decimal(10,2) DEFAULT NULL,
  `refund_method` enum('stripe','manual') DEFAULT NULL,
  `stripe_refund_id` varchar(255) DEFAULT NULL,
  `refund_error` varchar(255) DEFAULT NULL,
  `admin_note` varchar(255) DEFAULT NULL,
  `reviewed_by` int(11) DEFAULT NULL,
  `reviewed_at` datetime DEFAULT NULL,
  `email_sent_at` datetime DEFAULT NULL,
  `email_error` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_status` (`status`,`created_at`),
  KEY `idx_event` (`event_id`,`status`),
  KEY `idx_booking` (`booking_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- In case an earlier version of this file was already run
ALTER TABLE `refund_requests`
  ADD COLUMN IF NOT EXISTS `email_sent_at` DATETIME DEFAULT NULL AFTER `reviewed_at`,
  ADD COLUMN IF NOT EXISTS `email_error` VARCHAR(255) DEFAULT NULL AFTER `email_sent_at`;

-- ---------- 4. NEWSLETTER SUBSCRIBERS (footer "Subscribe" box) ----------
CREATE TABLE IF NOT EXISTS `newsletter_subscribers` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `email` varchar(150) NOT NULL,
  `user_id` int(11) DEFAULT NULL,
  `subscribed_at` datetime NOT NULL,
  `unsubscribed_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `email` (`email`),
  KEY `idx_active` (`unsubscribed_at`,`subscribed_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------- 5. LOYALTY REDEMPTION (1 point = 1% off at checkout) ----------
ALTER TABLE `payments`
  ADD COLUMN IF NOT EXISTS `points_redeemed` INT(11) NOT NULL DEFAULT 0 AFTER `amount_total`,
  ADD COLUMN IF NOT EXISTS `discount_amount` DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER `points_redeemed`;

ALTER TABLE `bookings`
  ADD COLUMN IF NOT EXISTS `points_redeemed` INT(11) NOT NULL DEFAULT 0 AFTER `total_price`,
  ADD COLUMN IF NOT EXISTS `discount_amount` DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER `points_redeemed`;
