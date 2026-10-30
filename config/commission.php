<?php
/**
 * commission.php
 * ------------------------------------------------------------
 * TicketHUB platform commission helpers.
 *
 * Every event carries its own commission:
 *   - commission_type  = 'percentage' (e.g. 5 = 5% of each ticket)
 *                      | 'fixed'      (e.g. 500 = LKR 500 per ticket)
 *   - commission_value = the number above
 *
 * New events inherit the platform default (platform_settings table).
 * Only Super Admins can change commissions; Admins can view them.
 * Customers never see commission — they just pay the ticket price.
 *
 * Each booking stores a snapshot of the commission it was charged
 * (bookings.commission_amount) so changing a rate later never
 * rewrites past earnings.
 * ------------------------------------------------------------
 */

const COMMISSION_TYPES = ['percentage', 'fixed'];
const COMMISSION_FALLBACK_TYPE  = 'percentage';
const COMMISSION_FALLBACK_VALUE = 5.00;

/**
 * Commission TicketHUB takes from ONE ticket, never more than the ticket price.
 */
function commission_per_ticket(float $ticketPrice, string $type, float $value): float
{
    if ($ticketPrice <= 0 || $value <= 0) {
        return 0.0;
    }
    $amount = ($type === 'fixed') ? $value : $ticketPrice * $value / 100;
    return round(min($amount, $ticketPrice), 2);
}

/**
 * Human label: "5%" or "LKR 500.00".
 */
function commission_label(string $type, float $value): string
{
    if ($type === 'fixed') {
        return 'LKR ' . number_format($value, 2);
    }
    // Drop trailing zeros: 5.00 -> 5, 2.50 -> 2.5
    return rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.') . '%';
}

/**
 * Validate commission input. Returns an error message, or null if valid.
 */
function commission_validate(string $type, $value): ?string
{
    if (!in_array($type, COMMISSION_TYPES, true)) {
        return "Commission type must be a percentage or a fixed amount.";
    }
    if (!is_numeric($value) || (float)$value < 0) {
        return "Commission must be a number of 0 or more.";
    }
    if ($type === 'percentage' && (float)$value > 100) {
        return "Percentage commission cannot exceed 100%.";
    }
    if ($type === 'fixed' && (float)$value > 99999999) {
        return "Fixed commission is too large.";
    }
    return null;
}

/**
 * Platform default commission applied to newly created events.
 * Returns ['type' => ..., 'value' => ...].
 */
function commission_get_default(mysqli $conn): array
{
    $default = ['type' => COMMISSION_FALLBACK_TYPE, 'value' => COMMISSION_FALLBACK_VALUE];
    try {
        $res = $conn->query(
            "SELECT setting_key, setting_value FROM platform_settings
             WHERE setting_key IN ('default_commission_type', 'default_commission_value')"
        );
        while ($row = $res->fetch_assoc()) {
            if ($row['setting_key'] === 'default_commission_type' && in_array($row['setting_value'], COMMISSION_TYPES, true)) {
                $default['type'] = $row['setting_value'];
            } elseif ($row['setting_key'] === 'default_commission_value' && is_numeric($row['setting_value'])) {
                $default['value'] = (float)$row['setting_value'];
            }
        }
    } catch (mysqli_sql_exception $e) {
        error_log("commission_get_default failed (run files/setup_platform.php?): " . $e->getMessage());
    }
    return $default;
}

function commission_set_default(mysqli $conn, string $type, float $value): bool
{
    $stmt = $conn->prepare(
        "INSERT INTO platform_settings (setting_key, setting_value) VALUES (?, ?)
         ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)"
    );
    $ok = true;
    foreach (['default_commission_type' => $type, 'default_commission_value' => (string)$value] as $key => $val) {
        $stmt->bind_param("ss", $key, $val);
        $ok = $stmt->execute() && $ok;
    }
    $stmt->close();
    return $ok;
}
