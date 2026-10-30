-- =========================================================
-- TicketHub Database Schema (Rebuilt)
-- =========================================================
-- Import this BEFORE any PHP files are used.
-- In phpMyAdmin: create database "tickethub", then Import this file.
-- Or via CLI:  mysql -u root -p tickethub < tickethub.sql
-- =========================================================

CREATE DATABASE IF NOT EXISTS tickethub
    CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

USE tickethub;

-- InnoDB is required: bookings use transactions and row locks to prevent overselling
SET default_storage_engine = InnoDB;

-- ---------------------------------------------------------
-- 1. USERS
-- ---------------------------------------------------------
CREATE TABLE users (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    name            VARCHAR(100)        NOT NULL,
    email           VARCHAR(150)        NOT NULL UNIQUE,
    phone           VARCHAR(20)         NULL,                -- international digits, e.g. 94771234567 (SMS tickets)
    password        VARCHAR(255)        NOT NULL,
    role            ENUM('super_admin','admin','customer') NOT NULL DEFAULT 'customer',
    loyalty_points  INT                 NOT NULL DEFAULT 0,
    created_at      TIMESTAMP           NOT NULL DEFAULT CURRENT_TIMESTAMP
);

-- ---------------------------------------------------------
-- 2. EVENTS
-- ---------------------------------------------------------
CREATE TABLE events (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    title           VARCHAR(150)        NOT NULL,
    description     TEXT,
    category        VARCHAR(50)         NOT NULL,
    location        VARCHAR(150)        NOT NULL,
    event_date      DATETIME            NOT NULL,
    ticket_price    DECIMAL(10,2)       NOT NULL DEFAULT 0,
    total_tickets   INT                 NOT NULL DEFAULT 0,
    tickets_sold    INT                 NOT NULL DEFAULT 0,
    commission_type ENUM('percentage','fixed') NOT NULL DEFAULT 'percentage',  -- TicketHUB's cut, set by Super Admins
    commission_value DECIMAL(10,2)      NOT NULL DEFAULT 5.00,                 -- 5 = 5%, or LKR amount when 'fixed'
    status          ENUM('on_sale','sales_paused','postponed','rescheduled','cancelled') NOT NULL DEFAULT 'on_sale',
    status_note     VARCHAR(255)        NULL,                -- shown to customers, e.g. "Moved due to weather"
    status_changed_at DATETIME          NULL,
    status_changed_by INT               NULL,
    original_event_date DATETIME        NULL,                -- first date, kept when an event is rescheduled
    photo           VARCHAR(255),
    created_by      INT                 NOT NULL,
    created_at      TIMESTAMP           NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_category (category),
    INDEX idx_event_date (event_date)
);

-- ---------------------------------------------------------
-- 3. BOOKINGS  (the missing core feature)
-- ---------------------------------------------------------
CREATE TABLE bookings (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    user_id         INT                 NOT NULL,
    event_id        INT                 NOT NULL,
    payment_id      INT                 NULL UNIQUE,         -- one booking per payment (blocks double booking)
    quantity        INT                 NOT NULL DEFAULT 1,
    total_price     DECIMAL(10,2)       NOT NULL,
    points_redeemed INT                 NOT NULL DEFAULT 0,  -- loyalty points spent on this booking
    discount_amount DECIMAL(10,2)       NOT NULL DEFAULT 0,  -- LKR taken off by those points (total_price is after discount)
    commission_amount DECIMAL(10,2)     NOT NULL DEFAULT 0,  -- TicketHUB commission snapshot for this booking
    booking_ref     VARCHAR(20)         NOT NULL UNIQUE,
    status          ENUM('confirmed','cancelled') NOT NULL DEFAULT 'confirmed',
    points_earned   INT                 NOT NULL DEFAULT 0,
    phone           VARCHAR(20)         NULL,                -- where the SMS ticket went
    ticket_token    CHAR(32)            NULL,                -- secret for the public e-ticket link
    email_sent_at   DATETIME            NULL,
    sms_sent_at     DATETIME            NULL,
    notify_error    VARCHAR(255)        NULL,
    created_at      TIMESTAMP           NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id)  REFERENCES users(id)  ON DELETE CASCADE,
    FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE,
    INDEX idx_user (user_id),
    INDEX idx_event (event_id)
);

-- ---------------------------------------------------------
-- 4. LOYALTY TRANSACTIONS  (audit trail for points)
-- ---------------------------------------------------------
CREATE TABLE loyalty_transactions (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    user_id         INT                 NOT NULL,
    booking_id      INT                 NULL,
    points          INT                 NOT NULL,           -- positive = earned, negative = redeemed
    reason          VARCHAR(150)        NOT NULL,
    created_at      TIMESTAMP           NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (booking_id) REFERENCES bookings(id) ON DELETE SET NULL
);

-- ---------------------------------------------------------
-- 5. USER PREFERENCE PROFILE  (feeds the recommendation engine)
-- ---------------------------------------------------------
-- Updated automatically every time a user books or views an event.
CREATE TABLE user_category_affinity (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    user_id         INT                 NOT NULL,
    category        VARCHAR(50)         NOT NULL,
    score           DECIMAL(6,2)        NOT NULL DEFAULT 0,  -- weighted affinity score
    updated_at      TIMESTAMP           NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    UNIQUE KEY unique_user_category (user_id, category)
);

-- ---------------------------------------------------------
-- 6. AI AGENT CONVERSATION LOG  (for the chat assistant)
-- ---------------------------------------------------------
CREATE TABLE agent_conversations (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    user_id         INT                 NULL,                -- NULL allowed = guest chat
    session_id      VARCHAR(64)         NOT NULL,
    sender          ENUM('user','agent') NOT NULL,
    message         TEXT                NOT NULL,
    intent          VARCHAR(50)         NULL,                 -- detected intent, e.g. 'find_event', 'price_query'
    created_at      TIMESTAMP           NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_session (session_id)
);

-- ---------------------------------------------------------
-- 7. PLATFORM SETTINGS  (default commission for new events)
-- ---------------------------------------------------------
CREATE TABLE platform_settings (
    setting_key     VARCHAR(64)         NOT NULL PRIMARY KEY,
    setting_value   VARCHAR(255)        NOT NULL,
    updated_at      TIMESTAMP           NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

INSERT INTO platform_settings (setting_key, setting_value) VALUES
('default_commission_type', 'percentage'),
('default_commission_value', '5');

-- ---------------------------------------------------------
-- 8. PAYMENTS  (every checkout attempt; pending rows hold seats)
-- ---------------------------------------------------------
CREATE TABLE payments (
    id                    INT AUTO_INCREMENT PRIMARY KEY,
    user_id               INT            NOT NULL,
    event_id              INT            NOT NULL,
    quantity              INT            NOT NULL,
    unit_price            DECIMAL(10,2)  NOT NULL,
    amount_total          DECIMAL(10,2)  NOT NULL,
    points_redeemed       INT            NOT NULL DEFAULT 0, -- reserved while pending, deducted when paid
    discount_amount       DECIMAL(10,2)  NOT NULL DEFAULT 0,
    phone                 VARCHAR(20)    NULL,
    stripe_session_id     VARCHAR(255)   NULL UNIQUE,
    stripe_payment_intent VARCHAR(255)   NULL,
    status                ENUM('pending','paid','failed','expired','cancelled','refunded') NOT NULL DEFAULT 'pending',
    booking_id            INT            NULL,
    failure_reason        VARCHAR(255)   NULL,
    expires_at            DATETIME       NOT NULL,             -- seats are held until this time
    created_at            TIMESTAMP      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at            TIMESTAMP      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_hold (event_id, status, expires_at),
    INDEX idx_user_status (user_id, status)
);

-- ---------------------------------------------------------
-- 9. EVENT STATUS HISTORY  (who postponed / cancelled / rescheduled, and when)
-- ---------------------------------------------------------
CREATE TABLE event_status_history (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    event_id    INT          NOT NULL,
    old_status  VARCHAR(20)  NOT NULL,
    new_status  VARCHAR(20)  NOT NULL,
    old_date    DATETIME     NULL,
    new_date    DATETIME     NULL,
    note        VARCHAR(255) NULL,
    changed_by  INT          NULL,
    changed_at  DATETIME     NOT NULL,
    INDEX idx_event (event_id, changed_at)
);

-- ---------------------------------------------------------
-- 10. REFUND REQUESTS  (customer asks on My Tickets, admin decides)
-- ---------------------------------------------------------
CREATE TABLE refund_requests (
    id               INT AUTO_INCREMENT PRIMARY KEY,
    booking_id       INT            NOT NULL,
    user_id          INT            NOT NULL,
    event_id         INT            NOT NULL,
    reason           VARCHAR(30)    NOT NULL,
    message          TEXT           NULL,
    amount_requested DECIMAL(10,2)  NOT NULL,
    status           ENUM('pending','refunded','rejected','withdrawn') NOT NULL DEFAULT 'pending',
    refund_amount    DECIMAL(10,2)  NULL,
    refund_method    ENUM('stripe','manual') NULL,
    stripe_refund_id VARCHAR(255)   NULL,
    refund_error     VARCHAR(255)   NULL,
    admin_note       VARCHAR(255)   NULL,
    reviewed_by      INT            NULL,
    reviewed_at      DATETIME       NULL,
    email_sent_at    DATETIME       NULL,               -- decision emailed to the customer
    email_error      VARCHAR(255)   NULL,
    created_at      TIMESTAMP      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_status (status, created_at),
    INDEX idx_event (event_id, status),
    INDEX idx_booking (booking_id)
);

-- First Super Admin: run files/setup_platform.php once after importing.
-- It promotes (or creates, on localhost) prabhashafernando2@gmail.com.

-- ---------------------------------------------------------
-- SEED DATA — sample events for demo
-- ---------------------------------------------------------
-- NOTE: The admin account is NOT inserted here on purpose.
-- bcrypt hashes are salted differently every time they're generated,
-- so a hash pasted into this SQL file can't be reliably guaranteed to work.
-- Instead, run create_admin.php (provided separately) ONCE in your browser
-- right after importing this file — it creates the admin account safely
-- using PHP's own password_hash() function, then delete that file.
--
-- IMPORTANT ORDER: run create_admin.php FIRST (right after this import),
-- THEN run the INSERT INTO events block below in phpMyAdmin's SQL tab.
-- This guarantees created_by correctly points at the real admin id.

-- Run this AFTER create_admin.php has created the admin account:
INSERT INTO events (title, description, category, location, event_date, ticket_price, total_tickets, created_by) VALUES
('Colombo Night Music Festival', 'An electrifying night of live music featuring top local artists.', 'Music', 'Viharamahadevi Park, Colombo', '2026-08-15 19:00:00', 2500.00, 200, (SELECT id FROM users WHERE role='admin' LIMIT 1)),
('Sri Lanka vs Australia - ODI', 'International cricket match at the historic R. Premadasa Stadium.', 'Sports', 'R. Premadasa Stadium, Colombo', '2026-08-22 14:00:00', 3500.00, 500, (SELECT id FROM users WHERE role='admin' LIMIT 1)),
('Tech Summit 2026', 'A summit exploring AI, cloud computing, and the future of software.', 'Technology', 'BMICH, Colombo', '2026-09-05 09:00:00', 1500.00, 150, (SELECT id FROM users WHERE role='admin' LIMIT 1));

-- ---------------------------------------------------------
-- 11. NEWSLETTER SUBSCRIBERS  (footer "Subscribe" box)
-- ---------------------------------------------------------
CREATE TABLE newsletter_subscribers (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    email           VARCHAR(150) NOT NULL UNIQUE,
    user_id         INT          NULL,                 -- set when a logged-in user subscribes
    subscribed_at   DATETIME     NOT NULL,
    unsubscribed_at DATETIME     NULL,
    INDEX idx_active (unsubscribed_at, subscribed_at)
);
