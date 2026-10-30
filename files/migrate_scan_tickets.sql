-- ------------------------------------------------------------
-- migrate_scan_tickets.sql
-- Scan Tickets feature: door check-in / check-out.
-- Safe to run more than once (only adds what is missing).
--
-- phpMyAdmin → if0_42594762_tickethub → SQL tab → paste → Go.
-- ------------------------------------------------------------

-- First check-in, who scanned it, and whether the guest is inside right now
ALTER TABLE `bookings`
  ADD COLUMN IF NOT EXISTS `checked_in_at` DATETIME DEFAULT NULL AFTER `notify_error`,
  ADD COLUMN IF NOT EXISTS `checked_in_by` INT(11) DEFAULT NULL AFTER `checked_in_at`,
  ADD COLUMN IF NOT EXISTS `is_inside` TINYINT(1) NOT NULL DEFAULT 0 AFTER `checked_in_by`;

-- Every door scan (entries, exits and refused attempts) for the check-in history
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
