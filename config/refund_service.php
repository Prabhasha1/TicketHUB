<?php
/**
 * refund_service.php
 * ------------------------------------------------------------
 * Customer refund requests and the admin decisions on them.
 *
 *   refund_request_create()  customer asks (My Tickets)
 *   refund_request_withdraw() customer changes their mind
 *   refund_approve()         admin refunds money (Stripe or manual) →
 *                            booking cancelled, seats released,
 *                            loyalty points taken back
 *   refund_reject()          admin declines with a note
 *   refund_notify()          emails the customer the decision
 *   refund_cancelled_event_all() one click: refund every ticket of a
 *                            cancelled event
 *
 * Requires: database.php ($conn)
 * ------------------------------------------------------------
 */

require_once __DIR__ . "/app.php";
require_once __DIR__ . "/stripe_client.php";
require_once __DIR__ . "/brevo_client.php";
require_once __DIR__ . "/event_status.php";
require_once __DIR__ . "/loyalty.php";

/** Booking + event + payment + latest refund request, for one booking. */
function refund_booking_context(mysqli $conn, int $bookingId): ?array
{
    $stmt = $conn->prepare(
        "SELECT b.*, e.title, e.event_date, e.status AS event_status, e.created_by AS event_owner,
                p.stripe_payment_intent, p.amount_total AS paid_amount
         FROM bookings b
         JOIN events e ON e.id = b.event_id
         LEFT JOIN payments p ON p.id = b.payment_id
         WHERE b.id = ?"
    );
    $stmt->bind_param("i", $bookingId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}

/** Customer submits a refund request. Returns null on success or an error message. */
function refund_request_create(mysqli $conn, int $userId, int $bookingId, string $reason, string $message): ?string
{
    if (!isset(REFUND_REASONS[$reason])) {
        return "Please choose a reason for the refund.";
    }
    $message = mb_substr(trim($message), 0, 1000);
    if ($reason === 'other' && $message === '') {
        return "Please tell us a little about why you need a refund.";
    }

    $ctx = refund_booking_context($conn, $bookingId);
    if (!$ctx || (int)$ctx['user_id'] !== $userId) {
        return "Booking not found.";
    }
    $eligibility = refund_eligibility($ctx, ['status' => $ctx['event_status'], 'event_date' => $ctx['event_date']]);
    if (!$eligibility['ok']) {
        return $eligibility['why'];
    }

    $check = $conn->prepare("SELECT id FROM refund_requests WHERE booking_id = ? AND status = 'pending' LIMIT 1");
    $check->bind_param("i", $bookingId);
    $check->execute();
    $hasPending = $check->get_result()->num_rows > 0;
    $check->close();
    if ($hasPending) {
        return "You already have a refund request waiting for review for this booking.";
    }

    $amount = (float)$ctx['total_price'];
    $ins = $conn->prepare(
        "INSERT INTO refund_requests (booking_id, user_id, event_id, reason, message, amount_requested)
         VALUES (?, ?, ?, ?, ?, ?)"
    );
    $ins->bind_param("iiissd", $bookingId, $userId, $ctx['event_id'], $reason, $message, $amount);
    $ins->execute();
    $ins->close();
    return null;
}

function refund_request_withdraw(mysqli $conn, int $userId, int $requestId): ?string
{
    $upd = $conn->prepare("UPDATE refund_requests SET status = 'withdrawn', reviewed_at = NOW() WHERE id = ? AND user_id = ? AND status = 'pending'");
    $upd->bind_param("ii", $requestId, $userId);
    $upd->execute();
    $ok = $upd->affected_rows > 0;
    $upd->close();
    return $ok ? null : "That request can no longer be withdrawn.";
}

/**
 * Load a request the given admin is allowed to act on (own events; super admin: all).
 */
function refund_request_for_admin(mysqli $conn, int $requestId, int $adminId, bool $isSuperAdmin, bool $lock = false): ?array
{
    $stmt = $conn->prepare(
        "SELECT r.*, e.created_by AS event_owner FROM refund_requests r JOIN events e ON e.id = r.event_id
         WHERE r.id = ?" . ($lock ? " FOR UPDATE" : "")
    );
    $stmt->bind_param("i", $requestId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$row || (!$isSuperAdmin && (int)$row['event_owner'] !== $adminId)) {
        return null;
    }
    return $row;
}

/**
 * Approve: refund money and cancel the booking.
 *   $method = 'stripe' → refund to the customer's card via Stripe
 *             'manual' → admin already paid it back another way (cash / bank); just record it
 * Returns ['ok' => bool, 'message' => string].
 */
function refund_approve(mysqli $conn, int $requestId, int $adminId, bool $isSuperAdmin, float $amount, string $method, string $note): array
{
    $note = mb_substr(trim($note), 0, 255);
    $amount = round($amount, 2);

    $conn->begin_transaction();
    try {
        $req = refund_request_for_admin($conn, $requestId, $adminId, $isSuperAdmin, true);
        if (!$req) {
            $conn->rollback();
            return ['ok' => false, 'message' => "Refund request not found, or it belongs to another organizer's event."];
        }
        if ($req['status'] !== 'pending') {
            $conn->rollback();
            return ['ok' => false, 'message' => "This request was already " . REFUND_STATUSES[$req['status']]['label'] . "."];
        }

        $bStmt = $conn->prepare(
            "SELECT b.*, p.stripe_payment_intent, e.title FROM bookings b
             JOIN events e ON e.id = b.event_id
             LEFT JOIN payments p ON p.id = b.payment_id
             WHERE b.id = ? FOR UPDATE"
        );
        $bStmt->bind_param("i", $req['booking_id']);
        $bStmt->execute();
        $booking = $bStmt->get_result()->fetch_assoc();
        $bStmt->close();

        if (!$booking || $booking['status'] !== 'confirmed') {
            $conn->rollback();
            return ['ok' => false, 'message' => "The booking is no longer active, so there is nothing to refund."];
        }
        if ($amount <= 0 || $amount > (float)$booking['total_price']) {
            $conn->rollback();
            return ['ok' => false, 'message' => "Refund amount must be more than 0 and at most LKR " . number_format((float)$booking['total_price'], 2) . "."];
        }

        $stripeRefundId = null;
        if ($method === 'stripe') {
            if (empty($booking['stripe_payment_intent'])) {
                $conn->rollback();
                return ['ok' => false, 'message' => "This booking has no Stripe payment on record. Refund the customer another way, then choose \"Already refunded manually\"."];
            }
            $refund = stripe_refund_amount($booking['stripe_payment_intent'], $amount, "refund-request-$requestId", [
                'booking_ref' => $booking['booking_ref'],
                'refund_request_id' => $requestId,
            ]);
            if (!$refund['ok']) {
                $conn->rollback();
                $err = mb_substr($refund['error'], 0, 255);
                $e = $conn->prepare("UPDATE refund_requests SET refund_error = ? WHERE id = ?");
                $e->bind_param("si", $err, $requestId);
                $e->execute();
                $e->close();
                error_log("Refund request #$requestId Stripe refund failed: " . $refund['error']);
                return ['ok' => false, 'message' => "Stripe could not refund this payment: {$refund['error']}"];
            }
            $stripeRefundId = $refund['data']['id'] ?? null;
        } elseif ($method !== 'manual') {
            $conn->rollback();
            return ['ok' => false, 'message' => "Choose how the money is being returned."];
        }

        // Cancel the booking and release its seats
        $u = $conn->prepare("UPDATE bookings SET status = 'cancelled', is_inside = 0 WHERE id = ?");
        $u->bind_param("i", $booking['id']);
        $u->execute();
        $u->close();

        $qty = (int)$booking['quantity'];
        $u = $conn->prepare("UPDATE events SET tickets_sold = GREATEST(0, tickets_sold - ?) WHERE id = ?");
        $u->bind_param("ii", $qty, $booking['event_id']);
        $u->execute();
        $u->close();

        if ($booking['payment_id']) {
            $why = "Refunded LKR " . number_format($amount, 2) . " (refund request #$requestId)";
            $u = $conn->prepare("UPDATE payments SET status = 'refunded', failure_reason = ? WHERE id = ?");
            $u->bind_param("si", $why, $booking['payment_id']);
            $u->execute();
            $u->close();
        }

        // Take back the loyalty points this booking earned (never below zero)
        $points = (int)$booking['points_earned'];
        if ($points > 0) {
            $u = $conn->prepare("UPDATE users SET loyalty_points = GREATEST(0, loyalty_points - ?) WHERE id = ?");
            $u->bind_param("ii", $points, $booking['user_id']);
            $u->execute();
            $u->close();

            $neg = -$points;
            $why = "Refund: " . $booking['title'];
            $u = $conn->prepare("INSERT INTO loyalty_transactions (user_id, booking_id, points, reason) VALUES (?, ?, ?, ?)");
            $u->bind_param("iiis", $booking['user_id'], $booking['id'], $neg, $why);
            $u->execute();
            $u->close();
        }
        // Give back any points the customer spent on this booking's discount
        $redeemed = (int)($booking['points_redeemed'] ?? 0);
        if ($redeemed > 0) {
            loyalty_adjust($conn, (int)$booking['user_id'], (int)$booking['id'], $redeemed, "Points returned: refund for " . $booking['title']);
        }

        $u = $conn->prepare(
            "UPDATE refund_requests
             SET status = 'refunded', refund_amount = ?, refund_method = ?, stripe_refund_id = ?, admin_note = ?,
                 refund_error = NULL, reviewed_by = ?, reviewed_at = NOW()
             WHERE id = ?"
        );
        $u->bind_param("dsssii", $amount, $method, $stripeRefundId, $note, $adminId, $requestId);
        $u->execute();
        $u->close();

        $conn->commit();
    } catch (Throwable $e) {
        $conn->rollback();
        error_log("refund_approve #$requestId failed: " . $e->getMessage());
        return ['ok' => false, 'message' => "Something went wrong while saving the refund. If Stripe already refunded it, approving again is safe — it will not refund twice."];
    }

    $mail = refund_notify($conn, $requestId);
    return ['ok' => true, 'emailed' => $mail['ok'], 'message' => "Refunded LKR " . number_format($amount, 2) . " for {$booking['booking_ref']}" .
        ($method === 'stripe' ? " to the customer's card (5–10 days to appear)." : " (recorded as a manual refund).") .
        " The booking is cancelled and $qty seat(s) are back on sale. " . refund_mail_sentence($mail)];
}

function refund_mail_sentence(array $mail): string
{
    return $mail['ok'] ? "📧 Customer emailed." : "📧 Email to customer failed ({$mail['error']}) — they can still see it on My Tickets.";
}

function refund_reject(mysqli $conn, int $requestId, int $adminId, bool $isSuperAdmin, string $note): array
{
    $note = mb_substr(trim($note), 0, 255);
    if ($note === '') {
        return ['ok' => false, 'message' => "Please tell the customer why the refund was declined."];
    }
    $req = refund_request_for_admin($conn, $requestId, $adminId, $isSuperAdmin);
    if (!$req) {
        return ['ok' => false, 'message' => "Refund request not found, or it belongs to another organizer's event."];
    }
    $u = $conn->prepare(
        "UPDATE refund_requests SET status = 'rejected', admin_note = ?, reviewed_by = ?, reviewed_at = NOW()
         WHERE id = ? AND status = 'pending'"
    );
    $u->bind_param("sii", $note, $adminId, $requestId);
    $u->execute();
    $ok = $u->affected_rows > 0;
    $u->close();
    if (!$ok) {
        return ['ok' => false, 'message' => "This request was already handled."];
    }
    $mail = refund_notify($conn, $requestId);
    return ['ok' => true, 'message' => "Refund request declined. The customer will see your note on My Tickets. " . refund_mail_sentence($mail)];
}

// ------------------------------------------------------------
// Customer emails
// ------------------------------------------------------------

/**
 * Email the customer the outcome of a refunded / rejected request.
 * Records email_sent_at or email_error on the request. Never throws.
 */
function refund_notify(mysqli $conn, int $requestId): array
{
    try {
        $stmt = $conn->prepare(
            "SELECT r.*, b.booking_ref, b.quantity, e.title, e.event_date, e.location, e.status AS event_status,
                    u.name AS customer_name, u.email AS customer_email
             FROM refund_requests r
             JOIN bookings b ON b.id = r.booking_id
             JOIN events e   ON e.id = r.event_id
             JOIN users u    ON u.id = r.user_id
             WHERE r.id = ?"
        );
        $stmt->bind_param("i", $requestId);
        $stmt->execute();
        $req = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$req || !in_array($req['status'], ['refunded', 'rejected'], true)) {
            $result = ['ok' => false, 'error' => 'Nothing to notify.'];
        } elseif (empty($req['customer_email'])) {
            $result = ['ok' => false, 'error' => 'No email address on the account.'];
        } else {
            $subject = $req['status'] === 'refunded'
                ? "↩ Refund of LKR " . number_format((float)$req['refund_amount'], 2) . " for {$req['title']}"
                : "Update on your refund request for {$req['title']}";
            $result = brevo_send_email($req['customer_email'], $req['customer_name'] ?? '', $subject, refund_email_html($req));
        }
    } catch (Throwable $e) {
        $result = ['ok' => false, 'error' => $e->getMessage()];
    }

    try {
        $error = $result['ok'] ? null : mb_substr((string)$result['error'], 0, 255);
        $u = $conn->prepare("UPDATE refund_requests SET email_sent_at = IF(? IS NULL, NOW(), email_sent_at), email_error = ? WHERE id = ?");
        $u->bind_param("ssi", $error, $error, $requestId);
        $u->execute();
        $u->close();
    } catch (Throwable $e) {
        error_log("refund_notify bookkeeping failed for #$requestId: " . $e->getMessage());
    }
    if (!$result['ok']) {
        error_log("Refund request #$requestId email problem: " . $result['error']);
    }
    return $result;
}

function refund_email_html(array $req): string
{
    $h         = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES);
    $refunded  = $req['status'] === 'refunded';
    $name      = $h(explode(' ', trim((string)$req['customer_name']))[0] ?: 'there');
    $title     = $h($req['title']);
    $ref       = $h($req['booking_ref']);
    $date      = date("l, M d, Y · h:i A", strtotime($req['event_date']));
    $note      = trim((string)$req['admin_note']);
    $myTickets = $h(app_url('my_tickets.php'));
    $accent    = $refunded ? '#6FCF97' : '#F0729C';

    if ($refunded) {
        $amount  = number_format((float)$req['refund_amount'], 2);
        $partial = (float)$req['refund_amount'] < (float)$req['amount_requested']
            ? "<tr><td style=\"padding:6px 0;color:#A9A3B8;\">You paid</td><td style=\"padding:6px 0;text-align:right;\">LKR " . number_format((float)$req['amount_requested'], 2) . "</td></tr>"
            : '';
        $how = $req['refund_method'] === 'stripe'
            ? "The money is on its way back to the card you paid with. Depending on your bank it can take <strong style=\"color:#F1EEF7;\">5–10 business days</strong> to show on your statement."
            : "The organizer has refunded you directly (cash or bank transfer).";
        $heading = "Your refund is approved ✅";
        $intro   = "Hi $name, we've refunded your booking for <strong style=\"color:#F1EEF7;\">$title</strong>.";
        $rows    = "$partial<tr><td style=\"padding:10px 0 6px;color:#A9A3B8;border-top:1px solid #2a2733;\">Refunded</td><td style=\"padding:10px 0 6px;text-align:right;font-weight:bold;color:#6FCF97;border-top:1px solid #2a2733;\">LKR $amount</td></tr>";
        $body    = "<p style=\"color:#A9A3B8;font-size:14px;line-height:1.6;margin:0 0 12px;\">$how</p>
                    <p style=\"color:#A9A3B8;font-size:14px;line-height:1.6;margin:0;\">This booking is now cancelled, so its QR code will no longer work at the door.</p>";
    } else {
        $heading = "About your refund request";
        $intro   = "Hi $name, the organizer of <strong style=\"color:#F1EEF7;\">$title</strong> has reviewed your refund request and wasn't able to approve it.";
        $rows    = '';
        $body    = "<p style=\"color:#A9A3B8;font-size:14px;line-height:1.6;margin:0;\">Good news: <strong style=\"color:#F1EEF7;\">your tickets are still valid</strong> — see you at the event!</p>";
    }
    $noteBlock = $note !== ''
        ? "<div style=\"margin:0 0 16px;padding:12px 14px;border-left:3px solid $accent;background:#0f0e14;border-radius:8px;color:#F1EEF7;font-size:14px;\"><span style=\"color:#A9A3B8;font-size:12px;\">Message from the organizer</span><br>" . $h($note) . "</div>"
        : '';

    return <<<HTML
    <div style="font-family: Arial, sans-serif; background:#14121A; padding: 30px 12px; color:#F1EEF7;">
        <div style="max-width: 480px; margin: 0 auto; background:#1B1822; border-radius: 16px; overflow:hidden; border:1px solid #2a2733;">
            <div style="padding: 28px 30px 10px;">
                <div style="font-size: 20px; font-weight: bold; color:#F1EEF7;">Ticket<span style="color:#9D7CF9;">Hub</span></div>
            </div>
            <div style="padding: 10px 30px 0;">
                <h2 style="color:$accent; margin: 10px 0 6px; font-size: 20px;">$heading</h2>
                <p style="color:#A9A3B8; font-size: 14px; line-height:1.6; margin: 0 0 20px;">$intro</p>
            </div>
            <div style="padding: 0 30px;">
                <table style="width:100%; font-size: 14px; color:#F1EEF7; border-collapse: collapse; margin-bottom: 16px;">
                    <tr><td style="padding:6px 0;color:#A9A3B8;">Event</td><td style="padding:6px 0;text-align:right;">$title</td></tr>
                    <tr><td style="padding:6px 0;color:#A9A3B8;">Date</td><td style="padding:6px 0;text-align:right;">$date</td></tr>
                    <tr><td style="padding:6px 0;color:#A9A3B8;">Booking</td><td style="padding:6px 0;text-align:right;font-family:monospace;color:#9D7CF9;">$ref</td></tr>
                    $rows
                </table>
                $noteBlock
                $body
            </div>
            <div style="padding: 22px 30px 0; text-align:center;">
                <a href="$myTickets" style="display:inline-block; background:#9D7CF9; color:#ffffff; text-decoration:none; font-weight:bold; font-size:14px; padding: 12px 26px; border-radius: 100px;">Open My Tickets</a>
            </div>
            <div style="padding: 22px 30px 28px; text-align:center; color:#6e6a7d; font-size: 12px;">
                Questions? Just reply to this email.<br>© 2026 TicketHub. All rights reserved.
            </div>
        </div>
    </div>
    HTML;
}

// ------------------------------------------------------------
// Cancelled events: refund everyone in one go
// ------------------------------------------------------------

/**
 * Paid, still-active bookings of a cancelled event, i.e. money that still has to go back.
 * Returns ['bookings' => n, 'tickets' => n, 'amount' => float, 'card' => n (refundable via Stripe), 'manual' => n].
 */
function refund_cancelled_event_outstanding(mysqli $conn, int $eventId): array
{
    $stmt = $conn->prepare(
        "SELECT COUNT(*) AS bookings, COALESCE(SUM(b.quantity), 0) AS tickets, COALESCE(SUM(b.total_price), 0) AS amount,
                COALESCE(SUM(p.stripe_payment_intent IS NOT NULL AND p.stripe_payment_intent <> ''), 0) AS card
         FROM bookings b LEFT JOIN payments p ON p.id = b.payment_id
         WHERE b.event_id = ? AND b.status = 'confirmed' AND b.total_price > 0 AND b.checked_in_at IS NULL"
    );
    $stmt->bind_param("i", $eventId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return [
        'bookings' => (int)$row['bookings'],
        'tickets'  => (int)$row['tickets'],
        'amount'   => (float)$row['amount'],
        'card'     => (int)$row['card'],
        'manual'   => (int)$row['bookings'] - (int)$row['card'],
    ];
}

/** Cancelled events (that this admin manages) which still have money to give back. */
function refund_cancelled_events_pending(mysqli $conn, int $adminId, bool $isSuperAdmin): array
{
    try {
        $owner = $isSuperAdmin ? "" : " AND e.created_by = " . (int)$adminId;
        $rows = $conn->query(
            "SELECT DISTINCT e.id, e.title, e.event_date, e.status_note FROM events e
             JOIN bookings b ON b.event_id = e.id AND b.status = 'confirmed' AND b.total_price > 0 AND b.checked_in_at IS NULL
             WHERE e.status = 'cancelled' $owner ORDER BY e.event_date"
        )->fetch_all(MYSQLI_ASSOC);
        foreach ($rows as &$r) {
            $r['outstanding'] = refund_cancelled_event_outstanding($conn, (int)$r['id']);
        }
        return $rows;
    } catch (mysqli_sql_exception $e) {
        error_log("refund_cancelled_events_pending failed: " . $e->getMessage());
        return [];
    }
}

/**
 * Refund every paid booking of a cancelled event in full.
 *   - card payments → refunded via Stripe now (customer emailed)
 *   - no card payment on record → a pending request is created so it shows up
 *     in the list for a manual refund
 *   - tickets already scanned at the door are left alone
 * Returns ['ok' => bool, 'message' => string].
 */
function refund_cancelled_event_all(mysqli $conn, int $eventId, int $adminId, bool $isSuperAdmin, string $note): array
{
    $stmt = $conn->prepare("SELECT id, title, status, created_by FROM events WHERE id = ?");
    $stmt->bind_param("i", $eventId);
    $stmt->execute();
    $event = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$event || (!$isSuperAdmin && (int)$event['created_by'] !== $adminId)) {
        return ['ok' => false, 'message' => "Event not found, or it belongs to another organizer."];
    }
    if (event_status_of($event) !== 'cancelled') {
        return ['ok' => false, 'message' => "\"Refund everyone\" is only available for cancelled events."];
    }

    $note = trim($note) !== '' ? $note : "Event cancelled — automatic full refund.";
    @set_time_limit(0); // one Stripe call per booking

    $stmt = $conn->prepare(
        "SELECT b.id, b.user_id, b.total_price, p.stripe_payment_intent
         FROM bookings b LEFT JOIN payments p ON p.id = b.payment_id
         WHERE b.event_id = ? AND b.status = 'confirmed' AND b.total_price > 0 AND b.checked_in_at IS NULL
         ORDER BY b.id"
    );
    $stmt->bind_param("i", $eventId);
    $stmt->execute();
    $bookings = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    $done = 0; $doneAmount = 0.0; $manual = 0; $failed = []; $emailFailed = 0;
    foreach ($bookings as $b) {
        // Reuse the customer's own pending request if they already asked
        $find = $conn->prepare("SELECT id FROM refund_requests WHERE booking_id = ? AND status = 'pending' ORDER BY id DESC LIMIT 1");
        $find->bind_param("i", $b['id']);
        $find->execute();
        $requestId = (int)($find->get_result()->fetch_assoc()['id'] ?? 0);
        $find->close();

        if (!$requestId) {
            $amount = (float)$b['total_price'];
            $msg = "Created automatically when the event was cancelled.";
            $ins = $conn->prepare(
                "INSERT INTO refund_requests (booking_id, user_id, event_id, reason, message, amount_requested)
                 VALUES (?, ?, ?, 'event_cancelled', ?, ?)"
            );
            $ins->bind_param("iiisd", $b['id'], $b['user_id'], $eventId, $msg, $amount);
            $ins->execute();
            $requestId = (int)$ins->insert_id;
            $ins->close();
        }

        if (empty($b['stripe_payment_intent'])) {
            $manual++;
            continue;
        }
        $result = refund_approve($conn, $requestId, $adminId, $isSuperAdmin, (float)$b['total_price'], 'stripe', $note);
        if ($result['ok']) {
            $done++;
            $doneAmount += (float)$b['total_price'];
            if (empty($result['emailed'])) { $emailFailed++; }
        } else {
            $failed[] = $result['message'];
        }
    }

    $parts = [];
    if ($done)   { $parts[] = "Refunded $done booking(s) — LKR " . number_format($doneAmount, 2) . " back to customers' cards."; }
    if ($emailFailed) { $parts[] = "$emailFailed refund email(s) could not be sent; customers still see it on My Tickets."; }
    if ($manual) { $parts[] = "$manual booking(s) have no card payment on record — they're in the Pending list to refund manually."; }
    if ($failed) { $parts[] = count($failed) . " refund(s) failed and stay pending: " . $failed[0]; }
    if (!$parts) { $parts[] = "Nothing left to refund for '{$event['title']}'."; }
    return ['ok' => !$failed, 'message' => implode(' ', $parts)];
}

/** Pending refund requests the admin can act on. */
function refund_pending_count(mysqli $conn, int $adminId, bool $isSuperAdmin): int
{
    try {
        if ($isSuperAdmin) {
            $res = $conn->query("SELECT COUNT(*) AS n FROM refund_requests WHERE status = 'pending'");
            return (int)$res->fetch_assoc()['n'];
        }
        $stmt = $conn->prepare(
            "SELECT COUNT(*) AS n FROM refund_requests r JOIN events e ON e.id = r.event_id
             WHERE r.status = 'pending' AND e.created_by = ?"
        );
        $stmt->bind_param("i", $adminId);
        $stmt->execute();
        $n = (int)$stmt->get_result()->fetch_assoc()['n'];
        $stmt->close();
        return $n;
    } catch (mysqli_sql_exception $e) {
        error_log("refund_pending_count failed (run files/setup_platform.php?): " . $e->getMessage());
        return 0;
    }
}
