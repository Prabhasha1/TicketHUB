<?php
/**
 * booking_service.php
 * ------------------------------------------------------------
 * The whole ticket purchase lifecycle in one place:
 *
 *   checkout_start()          customer clicks "Pay" → payments row (pending)
 *                             + Stripe Checkout session; seats are held
 *                             for the life of the session.
 *   payment_finalize_session() Stripe says "paid" → verify amount, lock the
 *                             event, create the booking, award points.
 *                             Idempotent: the success page, the webhook and
 *                             the My Tickets check can all call it; only the
 *                             first one creates the booking.
 *   booking_send_notifications() email (with QR) + SMS (with e-ticket link).
 *   payments_reconcile_user() catches payments whose customer closed the tab
 *                             before returning from Stripe.
 *
 * Requires: database.php ($conn), stripe_client.php, commission.php,
 *           qr_generator.php, brevo_client.php, sms_client.php, app.php
 * ------------------------------------------------------------
 */

require_once __DIR__ . "/app.php";
require_once __DIR__ . "/stripe_client.php";
require_once __DIR__ . "/commission.php";
require_once __DIR__ . "/event_status.php";
require_once __DIR__ . "/loyalty.php";
require_once __DIR__ . "/qr_generator.php";
require_once __DIR__ . "/brevo_client.php";
require_once __DIR__ . "/sms_client.php";

const BOOKING_MAX_PER_ORDER = 10;
// Stripe requires Checkout sessions to live at least 30 minutes
const CHECKOUT_HOLD_MINUTES = 31;

/**
 * Seats held by other customers who are on the Stripe payment page right now.
 */
function tickets_held(mysqli $conn, int $eventId): int
{
    $stmt = $conn->prepare(
        "SELECT COALESCE(SUM(quantity), 0) AS held FROM payments
         WHERE event_id = ? AND status = 'pending' AND expires_at > NOW()"
    );
    $stmt->bind_param("i", $eventId);
    $stmt->execute();
    $held = (int)$stmt->get_result()->fetch_assoc()['held'];
    $stmt->close();
    return $held;
}

function tickets_available(mysqli $conn, array $event): int
{
    return max(0, (int)$event['total_tickets'] - (int)$event['tickets_sold'] - tickets_held($conn, (int)$event['id']));
}

function payment_mark(mysqli $conn, int $paymentId, string $status, ?string $reason = null): void
{
    $stmt = $conn->prepare("UPDATE payments SET status = ?, failure_reason = COALESCE(?, failure_reason) WHERE id = ?");
    $stmt->bind_param("ssi", $status, $reason, $paymentId);
    $stmt->execute();
    $stmt->close();
}

// Guest checkout limits (visitors buying without logging in)
const GUEST_MAX_OPEN_HOLDS_PER_EMAIL   = 3; // unpaid checkouts in progress for one email
const GUEST_MAX_CHECKOUTS_PER_BROWSER  = 6; // checkouts started from one browser per hold window

/**
 * Guest checkout: the quiet customer account behind a guest's email.
 * New emails get an account with is_guest = 1 and no usable password, so bookings,
 * refunds, loyalty and the admin pages all keep working unchanged. The guest is never
 * logged in to it; they claim it later via "Forgot password" or Google sign-in.
 * An email that already belongs to a real account must log in instead.
 *
 * @return array ['ok' => true, 'user_id' => int] or ['ok' => false, 'needs_login' => bool, 'error' => string]
 */
function guest_account_for(mysqli $conn, string $name, string $email, string $phone): array
{
    $email = strtolower(trim($email));

    $find = function () use ($conn, $email): ?array {
        $stmt = $conn->prepare("SELECT id, is_guest FROM users WHERE LOWER(email) = ? LIMIT 1");
        $stmt->bind_param("s", $email);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $row ?: null;
    };

    $user = $find();
    if (!$user) {
        $randomPassword = password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT);
        try {
            $stmt = $conn->prepare("INSERT INTO users (name, email, phone, password, role, is_guest) VALUES (?, ?, ?, ?, 'customer', 1)");
            $stmt->bind_param("ssss", $name, $email, $phone, $randomPassword);
            $stmt->execute();
            $stmt->close();
            return ['ok' => true, 'user_id' => (int)$conn->insert_id];
        } catch (mysqli_sql_exception $e) {
            // Same email created a split second ago in another tab: use that one
            $user = $find();
            if (!$user) {
                error_log("guest_account_for failed: " . $e->getMessage());
                return ['ok' => false, 'needs_login' => false, 'error' => "We couldn't start your checkout. Please try again."];
            }
        }
    }

    if (!(int)$user['is_guest']) {
        return ['ok' => false, 'needs_login' => true, 'error' => "You already have a TicketHub account with this email. Please log in to book — it only takes a moment."];
    }
    return ['ok' => true, 'user_id' => (int)$user['id']];
}

/** Remember which checkouts this browser started as a guest (proves ownership on return from Stripe). */
function guest_remember_payment(int $paymentId): void
{
    $_SESSION['guest_payments'][$paymentId] = time();
}

function guest_owns_payment(int $paymentId): bool
{
    return isset($_SESSION['guest_payments'][$paymentId]);
}

/**
 * Start a purchase. Returns one of:
 *   ['ok' => true, 'redirect' => '<stripe checkout url>']
 *   ['ok' => true, 'booking_ref' => '...']          (free event, booked instantly)
 *   ['ok' => false, 'error' => 'message for the customer']
 *
 * $isGuest: the visitor is not logged in and $userId is their quiet guest account
 * (see guest_account_for). Anyone who types that email can reach this account, so
 * guests can't spend points, overwrite the saved phone, or cancel checkouts that
 * another browser started.
 */
function checkout_start(mysqli $conn, int $userId, int $eventId, int $quantity, string $phone, int $pointsToUse = 0, bool $isGuest = false): array
{
    if ($pointsToUse < 0 || $isGuest) {
        $pointsToUse = 0;
    }
    if ($isGuest) {
        $recent = array_filter($_SESSION['guest_payments'] ?? [], fn($t) => $t > time() - CHECKOUT_HOLD_MINUTES * 60);
        if (count($recent) >= GUEST_MAX_CHECKOUTS_PER_BROWSER) {
            return ['ok' => false, 'error' => "You've started several checkouts in a short time. Please finish one, or wait 30 minutes and try again."];
        }
    }
    if ($quantity < 1 || $quantity > BOOKING_MAX_PER_ORDER) {
        return ['ok' => false, 'error' => "Please select between 1 and " . BOOKING_MAX_PER_ORDER . " tickets."];
    }

    $userStmt = $conn->prepare("SELECT id, name, email FROM users WHERE id = ?");
    $userStmt->bind_param("i", $userId);
    $userStmt->execute();
    $user = $userStmt->get_result()->fetch_assoc();
    $userStmt->close();
    if (!$user) {
        return ['ok' => false, 'error' => "Your account could not be found. Please log in again."];
    }

    // Remember the phone on the profile so next checkout is pre-filled
    // (guests only fill a blank one — they can't change an existing number)
    $phoneStmt = $conn->prepare($isGuest
        ? "UPDATE users SET phone = ? WHERE id = ? AND (phone IS NULL OR phone = '')"
        : "UPDATE users SET phone = ? WHERE id = ?");
    $phoneStmt->bind_param("si", $phone, $userId);
    $phoneStmt->execute();
    $phoneStmt->close();

    // Lock the event while we check availability and place the hold,
    // so two customers can't both grab the last seats.
    $conn->begin_transaction();
    try {
        $eventStmt = $conn->prepare("SELECT * FROM events WHERE id = ? FOR UPDATE");
        $eventStmt->bind_param("i", $eventId);
        $eventStmt->execute();
        $event = $eventStmt->get_result()->fetch_assoc();
        $eventStmt->close();

        if (!$event) {
            $conn->rollback();
            return ['ok' => false, 'error' => "This event no longer exists."];
        }
        if (!event_is_on_sale($event)) {
            $conn->rollback();
            return ['ok' => false, 'error' => event_sales_block_reason($event) ?? "Tickets for this event are not on sale."];
        }
        if ($isGuest && (float)$event['ticket_price'] <= 0) {
            $conn->rollback();
            return ['ok' => false, 'error' => "Please log in to get free tickets."];
        }
        if ($isGuest) {
            $openStmt = $conn->prepare("SELECT COUNT(*) AS n FROM payments WHERE user_id = ? AND status = 'pending' AND expires_at > NOW()");
            $openStmt->bind_param("i", $userId);
            $openStmt->execute();
            $open = (int)$openStmt->get_result()->fetch_assoc()['n'];
            $openStmt->close();
            if ($open >= GUEST_MAX_OPEN_HOLDS_PER_EMAIL) {
                $conn->rollback();
                return ['ok' => false, 'error' => "There are already checkouts in progress for this email. Please finish one, or wait 30 minutes and try again."];
            }
        }

        // A customer retrying checkout shouldn't be blocked by their own earlier, abandoned hold
        $releaseStmt = $conn->prepare(
            "SELECT id, stripe_session_id FROM payments
             WHERE user_id = ? AND event_id = ? AND status = 'pending' AND expires_at > NOW()"
        );
        $releaseStmt->bind_param("ii", $userId, $eventId);
        $releaseStmt->execute();
        $ownHolds = $releaseStmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $releaseStmt->close();
        foreach ($ownHolds as $hold) {
            // No session yet = another tab is creating it this very second; leave that one alone
            if (!$hold['stripe_session_id']) {
                continue;
            }
            // A guest may only replace checkouts started from this browser, never someone else's
            if ($isGuest && !guest_owns_payment((int)$hold['id'])) {
                continue;
            }
            $expired = stripe_expire_checkout_session($hold['stripe_session_id']);
            if (!$expired['ok']) {
                // Could not expire (e.g. it was just paid) — leave it for reconciliation
                continue;
            }
            payment_mark($conn, (int)$hold['id'], 'cancelled', 'Replaced by a new checkout');
        }

        $available = tickets_available($conn, $event);
        if ($available <= 0) {
            $conn->rollback();
            return ['ok' => false, 'error' => "Sorry, this event is sold out (or the last tickets are being paid for right now — try again in a few minutes)."];
        }
        if ($quantity > $available) {
            $conn->rollback();
            return ['ok' => false, 'error' => "Only $available ticket(s) are available right now."];
        }

        $unitPrice   = (float)$event['ticket_price'];
        $subtotal    = round($unitPrice * $quantity, 2);

        // Loyalty points: lock the user's balance so two tabs can't spend the same points
        $discount = 0.0;
        if ($pointsToUse > 0 && $subtotal > 0) {
            $pStmt = $conn->prepare("SELECT loyalty_points FROM users WHERE id = ? FOR UPDATE");
            $pStmt->bind_param("i", $userId);
            $pStmt->execute();
            $balance = (int)$pStmt->get_result()->fetch_assoc()['loyalty_points'];
            $pStmt->close();

            $available = loyalty_points_available($conn, $userId, $balance);
            if ($pointsToUse > $available) {
                $conn->rollback();
                return ['ok' => false, 'error' => "You only have $available loyalty point(s) available right now."];
            }
            $pointsToUse = min($pointsToUse, loyalty_max_points_per_order());
            $discount    = loyalty_discount_amount($subtotal, $pointsToUse);
        } else {
            $pointsToUse = 0;
        }
        $amountTotal = round($subtotal - $discount, 2);
        // Unix time for Stripe; the DB computes its own copy from NOW() so PHP/MySQL timezones can't disagree
        $expiresAt   = time() + CHECKOUT_HOLD_MINUTES * 60;
        $holdMinutes = CHECKOUT_HOLD_MINUTES;

        $ins = $conn->prepare(
            "INSERT INTO payments (user_id, event_id, quantity, unit_price, amount_total, points_redeemed, discount_amount, phone, status, expires_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'pending', NOW() + INTERVAL ? MINUTE)"
        );
        $ins->bind_param("iiiddidsi", $userId, $eventId, $quantity, $unitPrice, $amountTotal, $pointsToUse, $discount, $phone, $holdMinutes);
        $ins->execute();
        $paymentId = (int)$ins->insert_id;
        $ins->close();

        $conn->commit();
        if ($isGuest) {
            guest_remember_payment($paymentId);
        }
    } catch (Throwable $e) {
        $conn->rollback();
        error_log("checkout_start failed: " . $e->getMessage());
        return ['ok' => false, 'error' => "We couldn't start your checkout. Please try again."];
    }

    // ---- Free event: no card needed, book straight away ----
    if ($amountTotal <= 0) {
        $result = payment_finalize($conn, $paymentId, null);
        if ($result['status'] === 'confirmed') {
            booking_send_notifications($conn, (int)$result['booking']['id']);
        }
        return in_array($result['status'], ['confirmed', 'already'], true)
            ? ['ok' => true, 'booking_ref' => $result['booking']['booking_ref']]
            : ['ok' => false, 'error' => $result['message']];
    }

    // ---- Paid event: hand over to Stripe Checkout ----
    // With a loyalty discount Stripe gets one line for the whole discounted order
    $productName = $event['title'] . " — " . date("M d, Y", strtotime($event['event_date']));
    if ($discount > 0) {
        $productName = "$quantity × $productName (loyalty " . rtrim(rtrim(number_format(loyalty_discount_percent($pointsToUse), 2), '0'), '.') . "% off)";
    }
    $stripe = stripe_create_checkout_session(
        $productName,
        $discount > 0 ? $amountTotal : $unitPrice,
        $discount > 0 ? 1 : $quantity,
        app_url("payment_success.php"),
        app_url("payment_cancelled.php?event_id=$eventId&pid=$paymentId"),
        [
            'payment_id' => $paymentId,
            'event_id'   => $eventId,
            'user_id'    => $userId,
            'quantity'   => $quantity,
        ],
        [
            'client_reference_id' => (string)$paymentId,
            'customer_email'      => $user['email'],
            'expires_at'          => $expiresAt,
            // Card only: stops Stripe Link's "Confirm it's you" phone/email-code popup
            'payment_method_types' => ['card'],
        ]
    );

    if (!$stripe['ok'] || empty($stripe['data']['url'])) {
        payment_mark($conn, $paymentId, 'failed', substr('Stripe: ' . ($stripe['error'] ?? 'unknown'), 0, 255));
        error_log("Stripe checkout session creation failed: " . ($stripe['error'] ?? 'unknown'));
        return ['ok' => false, 'error' => "Could not start secure checkout: " . ($stripe['error'] ?? 'unknown error') . ". You have not been charged."];
    }

    $sessionId = $stripe['data']['id'];
    $upd = $conn->prepare("UPDATE payments SET stripe_session_id = ? WHERE id = ?");
    $upd->bind_param("si", $sessionId, $paymentId);
    $upd->execute();
    $upd->close();

    return ['ok' => true, 'redirect' => $stripe['data']['url']];
}

/**
 * Finalize from a Stripe Checkout Session ID (success page, webhook, reconcile).
 * Pass $session when you already have the verified session object (webhook).
 *
 * @return array ['status' => confirmed|already|refunded|pending|expired|error, 'message' => string, 'booking' => ?array]
 */
function payment_finalize_session(mysqli $conn, string $sessionId, ?array $session = null): array
{
    if ($session === null || ($session['id'] ?? '') !== $sessionId) {
        $res = stripe_get_checkout_session($sessionId);
        if (!$res['ok']) {
            error_log("payment_finalize_session: cannot retrieve $sessionId — " . $res['error']);
            return ['status' => 'error', 'message' => "We couldn't verify your payment with Stripe right now. If you were charged, your tickets will appear in My Tickets shortly.", 'booking' => null];
        }
        $session = $res['data'];
    }

    $stmt = $conn->prepare("SELECT id FROM payments WHERE stripe_session_id = ?");
    $stmt->bind_param("s", $sessionId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$row) {
        error_log("payment_finalize_session: no payments row for session $sessionId");
        return ['status' => 'error', 'message' => "We couldn't match this payment to a booking. Please contact support with reference " . substr($sessionId, -10) . ".", 'booking' => null];
    }

    return payment_finalize($conn, (int)$row['id'], $session);
}

/**
 * Turn a payment into a booking. Safe to call many times for the same payment.
 * $session must be the Stripe Checkout Session (null only for free events).
 */
function payment_finalize(mysqli $conn, int $paymentId, ?array $session): array
{
    $result = ['status' => 'error', 'message' => 'Something went wrong while finalizing your booking.', 'booking' => null];
    $refundIntent = null;

    $conn->begin_transaction();
    try {
        // Row lock: a second caller (webhook vs. success page) waits here, then sees 'paid'
        $pStmt = $conn->prepare("SELECT * FROM payments WHERE id = ? FOR UPDATE");
        $pStmt->bind_param("i", $paymentId);
        $pStmt->execute();
        $payment = $pStmt->get_result()->fetch_assoc();
        $pStmt->close();

        if (!$payment) {
            throw new RuntimeException("Payment #$paymentId not found.");
        }

        if ($payment['status'] === 'paid' && $payment['booking_id']) {
            $conn->commit();
            return ['status' => 'already', 'message' => 'Booking already confirmed.', 'booking' => booking_get($conn, (int)$payment['booking_id'])];
        }
        if ($payment['status'] === 'refunded') {
            $conn->commit();
            return ['status' => 'refunded', 'message' => $payment['failure_reason'] ?: 'This payment was refunded.', 'booking' => null];
        }

        $isFree = (float)$payment['amount_total'] <= 0;

        if (!$isFree) {
            if ($session === null || ($session['id'] ?? '') !== $payment['stripe_session_id']) {
                throw new RuntimeException("Stripe session does not match payment #$paymentId.");
            }
            $paymentStatus = $session['payment_status'] ?? '';
            if ($paymentStatus !== 'paid') {
                if (($session['status'] ?? '') === 'expired') {
                    payment_mark($conn, $paymentId, 'expired', 'Checkout session expired before payment.');
                    $conn->commit();
                    return ['status' => 'expired', 'message' => 'This checkout expired before payment was completed. You have not been charged.', 'booking' => null];
                }
                $conn->commit();
                return ['status' => 'pending', 'message' => 'Your payment is still processing. We\'ll confirm your tickets by email and SMS as soon as it clears.', 'booking' => null];
            }

            // Never trust amounts from the browser — compare with what we asked Stripe to charge
            $expectedCents = (int)round((float)$payment['amount_total'] * 100);
            if ((int)($session['amount_total'] ?? -1) !== $expectedCents || strtolower($session['currency'] ?? '') !== 'lkr') {
                payment_mark($conn, $paymentId, 'failed', 'Amount mismatch: Stripe charged ' . ($session['amount_total'] ?? '?') . ' ' . ($session['currency'] ?? ''));
                $conn->commit();
                error_log("payment_finalize: amount mismatch on payment #$paymentId");
                return ['status' => 'error', 'message' => 'The amount paid did not match your order. Our team has been alerted and will refund or confirm it.', 'booking' => null];
            }
        }

        $intentId = is_array($session['payment_intent'] ?? null) ? ($session['payment_intent']['id'] ?? null) : ($session['payment_intent'] ?? null);
        if ($intentId) {
            $iStmt = $conn->prepare("UPDATE payments SET stripe_payment_intent = ? WHERE id = ?");
            $iStmt->bind_param("si", $intentId, $paymentId);
            $iStmt->execute();
            $iStmt->close();
        }

        $eStmt = $conn->prepare("SELECT * FROM events WHERE id = ? FOR UPDATE");
        $eStmt->bind_param("i", $payment['event_id']);
        $eStmt->execute();
        $event = $eStmt->get_result()->fetch_assoc();
        $eStmt->close();

        $quantity  = (int)$payment['quantity'];
        $remaining = $event ? (int)$event['total_tickets'] - (int)$event['tickets_sold'] : 0;

        $stoppedSelling = $event && in_array(event_status_of($event), ['cancelled', 'postponed'], true);
        if (!$event || $quantity > $remaining || $stoppedSelling) {
            // Paid, but we can't honour it (event deleted / oversold / cancelled mid-checkout) → refund automatically
            $reason = !$event ? 'The event was removed before your payment completed.'
                : ($stoppedSelling ? 'The event was ' . strtolower(event_status_meta($event)['label']) . ' while you were paying.'
                                   : 'Tickets sold out before your payment completed.');
            if (!$isFree && $intentId) {
                $refundIntent = $intentId;
            }
            payment_mark($conn, $paymentId, 'refunded', $reason . ' Full refund issued.');
            $conn->commit();
            $result = ['status' => 'refunded', 'message' => "$reason We've issued a full refund to your card (it can take 5–10 days to appear).", 'booking' => null];
        } else {
            $totalPrice     = (float)$payment['amount_total'];
            $pointsEarned   = loyalty_points_earned($totalPrice); // on what was actually paid
            $pointsRedeemed = (int)($payment['points_redeemed'] ?? 0);
            $discountAmount = (float)($payment['discount_amount'] ?? 0);
            // Commission on the price actually paid, so a loyalty discount is shared with the organizer
            $commission   = commission_per_ticket(
                $quantity > 0 ? $totalPrice / $quantity : (float)$payment['unit_price'],
                (string)($event['commission_type'] ?? COMMISSION_FALLBACK_TYPE),
                (float)($event['commission_value'] ?? 0)
            ) * $quantity;
            $ticketToken = bin2hex(random_bytes(16));
            $userId      = (int)$payment['user_id'];
            $eventId     = (int)$payment['event_id'];

            $bStmt = $conn->prepare(
                "INSERT INTO bookings (user_id, event_id, payment_id, quantity, total_price, points_redeemed, discount_amount,
                                       commission_amount, booking_ref, status, points_earned, phone, ticket_token)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'confirmed', ?, ?, ?)"
            );
            // Retry on the (astronomically unlikely) booking_ref collision
            for ($attempt = 0; ; $attempt++) {
                $bookingRef = booking_generate_ref();
                $bStmt->bind_param("iiiididdsiss", $userId, $eventId, $paymentId, $quantity, $totalPrice, $pointsRedeemed, $discountAmount,
                                   $commission, $bookingRef, $pointsEarned, $payment['phone'], $ticketToken);
                try {
                    $bStmt->execute();
                    break;
                } catch (mysqli_sql_exception $dup) {
                    if ($dup->getCode() !== 1062 || $attempt >= 3) {
                        throw $dup;
                    }
                }
            }
            $bookingId = (int)$bStmt->insert_id;
            $bStmt->close();

            $uStmt = $conn->prepare("UPDATE events SET tickets_sold = tickets_sold + ? WHERE id = ?");
            $uStmt->bind_param("ii", $quantity, $eventId);
            $uStmt->execute();
            $uStmt->close();

            // Spend the points that were reserved for this checkout, then award the new ones
            if ($pointsRedeemed > 0) {
                loyalty_adjust($conn, $userId, $bookingId, -$pointsRedeemed,
                    "Redeemed: " . loyalty_discount_percent($pointsRedeemed) . "% off " . $event['title']);
            }
            $reason = "Booking: " . $event['title'];
            $lStmt = $conn->prepare("UPDATE users SET loyalty_points = loyalty_points + ? WHERE id = ?");
            $lStmt->bind_param("ii", $pointsEarned, $userId);
            $lStmt->execute();
            $lStmt->close();
            $lStmt = $conn->prepare("INSERT INTO loyalty_transactions (user_id, booking_id, points, reason) VALUES (?, ?, ?, ?)");
            $lStmt->bind_param("iiis", $userId, $bookingId, $pointsEarned, $reason);
            $lStmt->execute();
            $lStmt->close();

            // Category affinity feeds the recommendation engine
            $aStmt = $conn->prepare(
                "INSERT INTO user_category_affinity (user_id, category, score) VALUES (?, ?, 1)
                 ON DUPLICATE KEY UPDATE score = score + 1"
            );
            $aStmt->bind_param("is", $userId, $event['category']);
            $aStmt->execute();
            $aStmt->close();

            $payStmt = $conn->prepare("UPDATE payments SET status = 'paid', booking_id = ?, failure_reason = NULL WHERE id = ?");
            $payStmt->bind_param("ii", $bookingId, $paymentId);
            $payStmt->execute();
            $payStmt->close();

            $conn->commit();
            $result = ['status' => 'confirmed', 'message' => 'Booking confirmed.', 'booking' => booking_get($conn, $bookingId)];
        }
    } catch (Throwable $e) {
        $conn->rollback();
        error_log("payment_finalize #$paymentId failed: " . $e->getMessage());
        return $result;
    }

    if ($refundIntent) {
        $refund = stripe_refund_payment_intent($refundIntent);
        if (!$refund['ok']) {
            error_log("AUTO-REFUND FAILED for payment #$paymentId ($refundIntent): " . $refund['error']);
            payment_mark($conn, $paymentId, 'refunded', 'Refund needs manual action in Stripe: ' . substr($refund['error'], 0, 150));
        }
    }

    return $result;
}

function booking_generate_ref(): string
{
    // No 0/O/1/I so refs are easy to read out loud at the door
    $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    $ref = '';
    for ($i = 0; $i < 10; $i++) {
        $ref .= $alphabet[random_int(0, strlen($alphabet) - 1)];
    }
    return 'TKT-' . $ref;
}

/**
 * Booking joined with its event and customer.
 */
function booking_get(mysqli $conn, int $bookingId): ?array
{
    $stmt = $conn->prepare(
        "SELECT b.*, e.title, e.location, e.event_date, e.category, e.photo,
                u.name AS customer_name, u.email AS customer_email
         FROM bookings b
         JOIN events e ON e.id = b.event_id
         JOIN users u ON u.id = b.user_id
         WHERE b.id = ?"
    );
    $stmt->bind_param("i", $bookingId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}

/**
 * Secret link to the e-ticket (works without logging in — it's what the SMS links to).
 * Older bookings get a token on first use.
 */
function booking_ticket_url(mysqli $conn, array &$booking, bool $qrImage = false): string
{
    if (empty($booking['ticket_token'])) {
        $booking['ticket_token'] = bin2hex(random_bytes(16));
        $stmt = $conn->prepare("UPDATE bookings SET ticket_token = ? WHERE id = ? AND ticket_token IS NULL");
        $stmt->bind_param("si", $booking['ticket_token'], $booking['id']);
        $stmt->execute();
        $stmt->close();
    }
    $query = 'ref=' . urlencode($booking['booking_ref']) . '&t=' . $booking['ticket_token'] . ($qrImage ? '&qr=1' : '');
    return app_url("view_ticket.php?$query");
}

/**
 * Send the e-ticket by email and/or SMS and record the outcome on the booking.
 *
 * @param string[] $channels any of 'email', 'sms'
 * @return array ['email' => ['ok'=>..,'error'=>..]|null, 'sms' => ...|null]
 */
function booking_send_notifications(mysqli $conn, int $bookingId, array $channels = ['email', 'sms']): array
{
    $out = ['email' => null, 'sms' => null];
    $booking = booking_get($conn, $bookingId);
    if (!$booking) {
        return $out;
    }

    $ticketUrl = booking_ticket_url($conn, $booking);
    $errors = [];

    if (in_array('email', $channels, true)) {
        try {
            if (empty($booking['customer_email'])) {
                $out['email'] = ['ok' => false, 'error' => 'No email address on the account.'];
            } else {
                $host = parse_url($ticketUrl, PHP_URL_HOST);
                $isPublic = !in_array($host, ['localhost', '127.0.0.1', '::1'], true);
                $html = build_ticket_email_html($booking, $booking, [
                    'ticket_url'  => $ticketUrl,
                    // Mail clients can only load the inline QR from a public site
                    'qr_url'      => $isPublic ? booking_ticket_url($conn, $booking, true) : '',
                    'holder_name' => $booking['customer_name'],
                    'phone'       => in_array('sms', $channels, true) && $booking['phone'] ? phone_masked($booking['phone']) : '',
                ]);
                $png = booking_qr_png($booking['booking_ref'], $booking['ticket_token']);
                $out['email'] = brevo_send_email(
                    $booking['customer_email'],
                    $booking['customer_name'],
                    "🎟️ Your tickets for {$booking['title']} — {$booking['booking_ref']}",
                    $html,
                    null,
                    $png ? $booking['booking_ref'] . '.png' : null,
                    $png ?: null
                );
            }
        } catch (Throwable $e) {
            $out['email'] = ['ok' => false, 'error' => $e->getMessage()];
        }
        if ($out['email']['ok']) {
            $conn->query("UPDATE bookings SET email_sent_at = NOW() WHERE id = " . (int)$bookingId);
        } else {
            $errors[] = 'Email: ' . $out['email']['error'];
        }
    }

    if (in_array('sms', $channels, true)) {
        try {
            if (empty($booking['phone'])) {
                $out['sms'] = ['ok' => false, 'error' => 'No phone number on this booking.'];
            } else {
                $out['sms'] = sms_send($booking['phone'], booking_sms_text($booking, $ticketUrl));
            }
        } catch (Throwable $e) {
            $out['sms'] = ['ok' => false, 'error' => $e->getMessage()];
        }
        if ($out['sms']['ok']) {
            $conn->query("UPDATE bookings SET sms_sent_at = NOW() WHERE id = " . (int)$bookingId);
        } else {
            $errors[] = 'SMS: ' . $out['sms']['error'];
        }
    }

    $errorText = $errors ? substr(implode(' | ', $errors), 0, 255) : null;
    $stmt = $conn->prepare("UPDATE bookings SET notify_error = ? WHERE id = ?");
    $stmt->bind_param("si", $errorText, $bookingId);
    $stmt->execute();
    $stmt->close();
    if ($errorText) {
        error_log("Booking #$bookingId notification problem: $errorText");
    }

    return $out;
}

function booking_sms_text(array $booking, string $ticketUrl): string
{
    $title = mb_strimwidth($booking['title'], 0, 40, '…');
    $when  = date("d M, g:i A", strtotime($booking['event_date']));
    $qty   = (int)$booking['quantity'];
    return "TicketHub: Payment received! {$title} on {$when}, {$qty} ticket" . ($qty === 1 ? '' : 's')
         . ". Ref {$booking['booking_ref']}. Your e-ticket (show QR at entry): {$ticketUrl}";
}

/**
 * Finalize any of this customer's checkouts that Stripe marked paid but that we
 * never heard back about (tab closed, no webhook on localhost, network blip).
 * Returns bookings newly confirmed by this call.
 */
function payments_reconcile_user(mysqli $conn, int $userId, int $limit = 5): array
{
    $confirmed = [];
    $stmt = $conn->prepare(
        "SELECT id, stripe_session_id FROM payments
         WHERE user_id = ? AND status = 'pending' AND stripe_session_id IS NOT NULL
           AND created_at > NOW() - INTERVAL 2 DAY
         ORDER BY id DESC LIMIT ?"
    );
    $stmt->bind_param("ii", $userId, $limit);
    $stmt->execute();
    $pending = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    foreach ($pending as $p) {
        $result = payment_finalize_session($conn, $p['stripe_session_id']);
        if ($result['status'] === 'confirmed') {
            booking_send_notifications($conn, (int)$result['booking']['id']);
            $confirmed[] = $result['booking'];
        }
    }
    return $confirmed;
}
