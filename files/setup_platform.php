<?php
/**
 * setup_platform.php
 * ------------------------------------------------------------
 * One-time (safe to re-run) setup for the Super Admin / Admin /
 * Customer model and the TicketHUB commission feature.
 *
 *   Browser: http://localhost/TicketHUB/files/setup_platform.php
 *   CLI:     php files/setup_platform.php
 *
 * It will:
 *   1. Make sure users.role supports 'super_admin'.
 *   2. Add commission columns to events + bookings.
 *   3. Create the platform_settings table (default commission).
 *   4. Payments: convert tables to InnoDB/utf8mb4, add users.phone,
 *      the payments table and booking delivery-tracking columns.
 *   5. Mark prabhashafernando2@gmail.com as the first Super Admin
 *      (creating the account with a one-time password if it does
 *      not exist yet — localhost/CLI only).
 *
 * Every step checks first, so running it twice changes nothing.
 * ------------------------------------------------------------
 */

require __DIR__ . "/../config/database.php";
require __DIR__ . "/../config/commission.php";

// The first Super Admin's personal login (TicketHub's public/support address is tickethubdesk@gmail.com — see config/site.php)
$superAdminEmail = "prabhashafernando2@gmail.com";
$superAdminName  = "Prabhasha Fernando";

$isCli   = php_sapi_name() === 'cli';
$remote  = $_SERVER['REMOTE_ADDR'] ?? '';
$isLocal = $isCli || in_array($remote, ['127.0.0.1', '::1'], true);

$log = [];
function step(array &$log, string $status, string $msg): void { $log[] = [$status, $msg]; }

function column_exists(mysqli $conn, string $table, string $column): bool
{
    $stmt = $conn->prepare(
        "SELECT 1 FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1"
    );
    $stmt->bind_param("ss", $table, $column);
    $stmt->execute();
    $stmt->store_result();
    $exists = $stmt->num_rows > 0;
    $stmt->close();
    return $exists;
}

try {
    // ---- 1. Role enum ----
    $roleType = $conn->query(
        "SELECT COLUMN_TYPE FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'role'"
    )->fetch_assoc()['COLUMN_TYPE'] ?? '';

    if (strpos($roleType, 'super_admin') === false) {
        $conn->query("ALTER TABLE users MODIFY role ENUM('super_admin','admin','customer') NOT NULL DEFAULT 'customer'");
        step($log, 'done', "users.role now supports 'super_admin'.");
    } else {
        step($log, 'skip', "users.role already supports 'super_admin'.");
    }

    // ---- 2. Commission columns ----
    if (!column_exists($conn, 'events', 'commission_type')) {
        $conn->query("ALTER TABLE events ADD COLUMN commission_type ENUM('percentage','fixed') NOT NULL DEFAULT 'percentage' AFTER tickets_sold");
        step($log, 'done', "Added events.commission_type.");
    } else {
        step($log, 'skip', "events.commission_type already exists.");
    }

    if (!column_exists($conn, 'events', 'commission_value')) {
        $conn->query("ALTER TABLE events ADD COLUMN commission_value DECIMAL(10,2) NOT NULL DEFAULT " . COMMISSION_FALLBACK_VALUE . " AFTER commission_type");
        step($log, 'done', "Added events.commission_value (existing events start at " . commission_label(COMMISSION_FALLBACK_TYPE, COMMISSION_FALLBACK_VALUE) . ").");
    } else {
        step($log, 'skip', "events.commission_value already exists.");
    }

    if (!column_exists($conn, 'bookings', 'commission_amount')) {
        $conn->query("ALTER TABLE bookings ADD COLUMN commission_amount DECIMAL(10,2) NOT NULL DEFAULT 0 AFTER total_price");
        step($log, 'done', "Added bookings.commission_amount (existing bookings recorded as LKR 0 commission).");
    } else {
        step($log, 'skip', "bookings.commission_amount already exists.");
    }

    // ---- 3. Platform settings ----
    $conn->query(
        "CREATE TABLE IF NOT EXISTS platform_settings (
            setting_key   VARCHAR(64)  NOT NULL PRIMARY KEY,
            setting_value VARCHAR(255) NOT NULL,
            updated_at    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        )"
    );
    $conn->query(
        "INSERT IGNORE INTO platform_settings (setting_key, setting_value) VALUES
            ('default_commission_type', '" . COMMISSION_FALLBACK_TYPE . "'),
            ('default_commission_value', '" . COMMISSION_FALLBACK_VALUE . "')"
    );
    $default = commission_get_default($conn);
    step($log, 'done', "platform_settings ready. Default commission: " . commission_label($default['type'], $default['value']) . ".");

    // ---- 4. Payments: InnoDB (real transactions + row locks), utf8mb4 (Sinhala/Tamil names, emoji) ----
    $tables = $conn->query(
        "SELECT TABLE_NAME, ENGINE, TABLE_COLLATION FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE'"
    )->fetch_all(MYSQLI_ASSOC);
    foreach ($tables as $t) {
        $changes = [];
        if (strcasecmp($t['ENGINE'], 'InnoDB') !== 0) {
            $changes[] = "ENGINE = InnoDB";
        }
        if (stripos((string)$t['TABLE_COLLATION'], 'utf8mb4') !== 0) {
            $changes[] = "CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci";
        }
        if ($changes) {
            $conn->query("ALTER TABLE `{$t['TABLE_NAME']}` " . implode(', ', $changes));
            step($log, 'done', "Converted {$t['TABLE_NAME']} (" . implode('; ', $changes) . ").");
        }
    }
    step($log, 'done', "All tables are InnoDB + utf8mb4.");

    if (!column_exists($conn, 'users', 'phone')) {
        $conn->query("ALTER TABLE users ADD COLUMN phone VARCHAR(20) NULL AFTER email");
        step($log, 'done', "Added users.phone.");
    } else {
        step($log, 'skip', "users.phone already exists.");
    }

    $conn->query(
        "CREATE TABLE IF NOT EXISTS payments (
            id                    INT AUTO_INCREMENT PRIMARY KEY,
            user_id               INT            NOT NULL,
            event_id              INT            NOT NULL,
            quantity              INT            NOT NULL,
            unit_price            DECIMAL(10,2)  NOT NULL,
            amount_total          DECIMAL(10,2)  NOT NULL,
            phone                 VARCHAR(20)    NULL,
            stripe_session_id     VARCHAR(255)   NULL UNIQUE,
            stripe_payment_intent VARCHAR(255)   NULL,
            status                ENUM('pending','paid','failed','expired','cancelled','refunded') NOT NULL DEFAULT 'pending',
            booking_id            INT            NULL,
            failure_reason        VARCHAR(255)   NULL,
            expires_at            DATETIME       NOT NULL,
            created_at            TIMESTAMP      NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at            TIMESTAMP      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_hold (event_id, status, expires_at),
            INDEX idx_user_status (user_id, status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    step($log, 'done', "payments table ready.");

    $bookingColumns = [
        'payment_id'    => "INT NULL AFTER event_id",
        'phone'         => "VARCHAR(20) NULL AFTER points_earned",
        'ticket_token'  => "CHAR(32) NULL AFTER phone",
        'email_sent_at' => "DATETIME NULL AFTER ticket_token",
        'sms_sent_at'   => "DATETIME NULL AFTER email_sent_at",
        'notify_error'  => "VARCHAR(255) NULL AFTER sms_sent_at",
        'checked_in_at' => "DATETIME NULL AFTER notify_error",
        'checked_in_by' => "INT NULL AFTER checked_in_at",
        'is_inside'     => "TINYINT(1) NOT NULL DEFAULT 0 AFTER checked_in_by",
    ];
    foreach ($bookingColumns as $col => $definition) {
        if (!column_exists($conn, 'bookings', $col)) {
            $conn->query("ALTER TABLE bookings ADD COLUMN $col $definition");
            step($log, 'done', "Added bookings.$col.");
        }
    }
    $hasPaymentIndex = $conn->query("SHOW INDEX FROM bookings WHERE Key_name = 'uniq_payment'")->num_rows > 0;
    if (!$hasPaymentIndex) {
        // One booking per payment — the database itself refuses a double booking
        $conn->query("ALTER TABLE bookings ADD UNIQUE KEY uniq_payment (payment_id)");
        step($log, 'done', "Added unique index bookings.payment_id.");
    }
    step($log, 'done', "bookings has payment, phone and delivery-tracking columns.");

    $conn->query(
        "CREATE TABLE IF NOT EXISTS ticket_scans (
            id          INT AUTO_INCREMENT PRIMARY KEY,
            booking_id  INT          NOT NULL,
            event_id    INT          NOT NULL,
            direction   ENUM('in','out') NOT NULL,
            accepted    TINYINT(1)   NOT NULL DEFAULT 1,
            result      VARCHAR(30)  NOT NULL,
            method      ENUM('qr','qr_legacy','manual') NOT NULL DEFAULT 'qr',
            scanned_by  INT          NULL,
            scanned_at  DATETIME     NOT NULL,
            INDEX idx_booking (booking_id, scanned_at),
            INDEX idx_event (event_id, scanned_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    step($log, 'done', "ticket_scans table ready (door check-in / check-out history).");

    // ---- Event status (postpone / cancel / reschedule) + refund requests ----
    $eventColumns = [
        'status'              => "ENUM('on_sale','sales_paused','postponed','rescheduled','cancelled') NOT NULL DEFAULT 'on_sale' AFTER commission_value",
        'status_note'         => "VARCHAR(255) NULL AFTER status",
        'status_changed_at'   => "DATETIME NULL AFTER status_note",
        'status_changed_by'   => "INT NULL AFTER status_changed_at",
        'original_event_date' => "DATETIME NULL AFTER status_changed_by",
    ];
    foreach ($eventColumns as $col => $definition) {
        if (!column_exists($conn, 'events', $col)) {
            $conn->query("ALTER TABLE events ADD COLUMN $col $definition");
            step($log, 'done', "Added events.$col.");
        }
    }
    step($log, 'done', "events has status columns (on sale / paused / postponed / rescheduled / cancelled).");

    $conn->query(
        "CREATE TABLE IF NOT EXISTS event_status_history (
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
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    step($log, 'done', "event_status_history table ready.");

    $conn->query(
        "CREATE TABLE IF NOT EXISTS refund_requests (
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
            created_at       TIMESTAMP      NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_status (status, created_at),
            INDEX idx_event (event_id, status),
            INDEX idx_booking (booking_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    foreach (['email_sent_at' => "DATETIME NULL AFTER reviewed_at", 'email_error' => "VARCHAR(255) NULL AFTER email_sent_at"] as $col => $definition) {
        if (!column_exists($conn, 'refund_requests', $col)) {
            $conn->query("ALTER TABLE refund_requests ADD COLUMN $col $definition");
            step($log, 'done', "Added refund_requests.$col.");
        }
    }
    step($log, 'done', "refund_requests table ready (with customer email tracking).");

    // ---- Loyalty point redemption (1 point = 1% off) ----
    foreach (['payments' => 'amount_total', 'bookings' => 'total_price'] as $table => $after) {
        if (!column_exists($conn, $table, 'points_redeemed')) {
            $conn->query("ALTER TABLE $table ADD COLUMN points_redeemed INT NOT NULL DEFAULT 0 AFTER $after");
            step($log, 'done', "Added $table.points_redeemed.");
        }
        if (!column_exists($conn, $table, 'discount_amount')) {
            $conn->query("ALTER TABLE $table ADD COLUMN discount_amount DECIMAL(10,2) NOT NULL DEFAULT 0 AFTER points_redeemed");
            step($log, 'done', "Added $table.discount_amount.");
        }
    }
    step($log, 'done', "Loyalty redemption columns ready on payments + bookings.");

    $conn->query(
        "CREATE TABLE IF NOT EXISTS newsletter_subscribers (
            id              INT AUTO_INCREMENT PRIMARY KEY,
            email           VARCHAR(150) NOT NULL UNIQUE,
            user_id         INT          NULL,
            subscribed_at   DATETIME     NOT NULL,
            unsubscribed_at DATETIME     NULL,
            INDEX idx_active (unsubscribed_at, subscribed_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    step($log, 'done', "newsletter_subscribers table ready (footer Subscribe box).");

    // ---- 5. First Super Admin ----
    $stmt = $conn->prepare("SELECT id, name, role FROM users WHERE email = ? LIMIT 1");
    $stmt->bind_param("s", $superAdminEmail);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($user && $user['role'] === 'super_admin') {
        step($log, 'skip', "$superAdminEmail is already a Super Admin.");
    } elseif ($user) {
        $upd = $conn->prepare("UPDATE users SET role = 'super_admin' WHERE id = ?");
        $upd->bind_param("i", $user['id']);
        $upd->execute();
        $upd->close();
        step($log, 'done', "Promoted existing account $superAdminEmail ({$user['name']}) from {$user['role']} to Super Admin.");
    } elseif ($isLocal) {
        $tempPassword = 'TH-' . bin2hex(random_bytes(5));
        $hash = password_hash($tempPassword, PASSWORD_DEFAULT);
        $ins = $conn->prepare("INSERT INTO users (name, email, password, role) VALUES (?, ?, ?, 'super_admin')");
        $ins->bind_param("sss", $superAdminName, $superAdminEmail, $hash);
        $ins->execute();
        $ins->close();
        step($log, 'done', "Created Super Admin $superAdminEmail with one-time password: $tempPassword — log in and change it (Admins Management → Edit Data).");
    } else {
        step($log, 'warn', "$superAdminEmail has no account yet. Register it at register.php, then run this script again to promote it.");
    }
} catch (mysqli_sql_exception $e) {
    error_log("setup_platform failed: " . $e->getMessage());
    step($log, 'fail', "Setup stopped: " . $e->getMessage());
}

$conn->close();

// ---- Output ----
if ($isCli) {
    foreach ($log as [$status, $msg]) {
        echo str_pad(strtoupper($status), 6) . " $msg\n";
    }
    exit;
}

$colors = ['done' => '#6FCF97', 'skip' => '#A9A3B8', 'warn' => '#F2C572', 'fail' => '#EF6B6B'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Platform Setup - TicketHub</title>
</head>
<body style="margin:0;background:#14121A;color:#F1EEF7;font-family:'Segoe UI',sans-serif;padding:40px 16px;">
<div style="max-width:720px;margin:0 auto;">
    <h2 style="margin-top:0;">👑 TicketHUB Platform Setup</h2>
    <?php foreach ($log as [$status, $msg]): ?>
        <div style="padding:12px 16px;margin-bottom:10px;border-radius:10px;background:rgba(255,255,255,0.04);border-left:4px solid <?php echo $colors[$status]; ?>;">
            <strong style="color:<?php echo $colors[$status]; ?>;text-transform:uppercase;font-size:12px;"><?php echo $status; ?></strong>
            &nbsp;<?php echo htmlspecialchars($msg); ?>
        </div>
    <?php endforeach; ?>
    <p style="margin-top:24px;"><a style="color:#9D7CF9;" href="../login.php">Go to login</a> · <a style="color:#9D7CF9;" href="../manage_admins.php">Admins Management</a> · <a style="color:#9D7CF9;" href="../manage_events.php">Manage Events</a></p>
</div>
</body>
</html>
