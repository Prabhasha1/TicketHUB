<?php
/**
 * loyalty.php
 * ------------------------------------------------------------
 * Loyalty points: earn on every paid booking, spend for a discount.
 *
 *   Earn:   1 point per LOYALTY_LKR_PER_POINT spent (on the amount actually paid)
 *   Redeem: 1 point = LOYALTY_PERCENT_PER_POINT % off one order,
 *           up to LOYALTY_MAX_DISCOUNT_PERCENT % per order
 *
 * Points chosen at checkout are reserved while the customer is on the
 * Stripe page (so they can't be spent twice) and only deducted when the
 * payment succeeds. A refunded booking gets its redeemed points back.
 * ------------------------------------------------------------
 */

const LOYALTY_LKR_PER_POINT        = 100; // earn 1 point per LKR 100
const LOYALTY_PERCENT_PER_POINT    = 1;   // 1 point = 1% off
const LOYALTY_MAX_DISCOUNT_PERCENT = 50;  // never more than 50% off one order

/** Points a payment of $amountPaid earns. */
function loyalty_points_earned(float $amountPaid): int
{
    return (int)floor($amountPaid / LOYALTY_LKR_PER_POINT);
}

/** Most points that can be used on a single order. */
function loyalty_max_points_per_order(): int
{
    return (int)floor(LOYALTY_MAX_DISCOUNT_PERCENT / LOYALTY_PERCENT_PER_POINT);
}

/** Discount % for a number of points (capped). */
function loyalty_discount_percent(int $points): float
{
    return min(max(0, $points) * LOYALTY_PERCENT_PER_POINT, LOYALTY_MAX_DISCOUNT_PERCENT);
}

/** LKR discount on $subtotal for $points. */
function loyalty_discount_amount(float $subtotal, int $points): float
{
    return round($subtotal * loyalty_discount_percent($points) / 100, 2);
}

/** Points held by this user's checkouts that are still open on Stripe. */
function loyalty_points_reserved(mysqli $conn, int $userId): int
{
    try {
        $stmt = $conn->prepare(
            "SELECT COALESCE(SUM(points_redeemed), 0) AS held FROM payments
             WHERE user_id = ? AND status = 'pending' AND booking_id IS NULL AND expires_at > NOW()"
        );
        $stmt->bind_param("i", $userId);
        $stmt->execute();
        $held = (int)$stmt->get_result()->fetch_assoc()['held'];
        $stmt->close();
        return $held;
    } catch (mysqli_sql_exception $e) {
        error_log("loyalty_points_reserved failed (run files/setup_platform.php?): " . $e->getMessage());
        return 0;
    }
}

/** Points the user can spend right now = balance − reserved by open checkouts. */
function loyalty_points_available(mysqli $conn, int $userId, ?int $balance = null): int
{
    if ($balance === null) {
        $stmt = $conn->prepare("SELECT loyalty_points FROM users WHERE id = ?");
        $stmt->bind_param("i", $userId);
        $stmt->execute();
        $balance = (int)($stmt->get_result()->fetch_assoc()['loyalty_points'] ?? 0);
        $stmt->close();
    }
    return max(0, $balance - loyalty_points_reserved($conn, $userId));
}

/** Add (+) or remove (−) points with a ledger entry. Balance never goes below 0. */
function loyalty_adjust(mysqli $conn, int $userId, ?int $bookingId, int $points, string $reason): void
{
    if ($points === 0) {
        return;
    }
    $u = $conn->prepare("UPDATE users SET loyalty_points = GREATEST(0, loyalty_points + ?) WHERE id = ?");
    $u->bind_param("ii", $points, $userId);
    $u->execute();
    $u->close();

    $reason = mb_substr($reason, 0, 150);
    $l = $conn->prepare("INSERT INTO loyalty_transactions (user_id, booking_id, points, reason) VALUES (?, ?, ?, ?)");
    $l->bind_param("iiis", $userId, $bookingId, $points, $reason);
    $l->execute();
    $l->close();
}
