<?php
/**
 * event_status.php
 * ------------------------------------------------------------
 * Event lifecycle status + refund eligibility rules.
 *
 *   on_sale      normal; customers can buy
 *   sales_paused event still happening, no new sales for now
 *   postponed    event moved, new date not known yet; no sales
 *   rescheduled  event moved to a new date; sales reopen
 *   cancelled    event will not happen; no sales
 *
 * Refunds (customers request, admins approve in refund_requests.php):
 *   - cancelled / postponed / rescheduled → ticket holders can request
 *     a refund any time (unless they already attended).
 *   - on sale / sales paused → "change of mind" refunds are allowed
 *     until REFUND_CUTOFF_HOURS before the event starts.
 * ------------------------------------------------------------
 */

const EVENT_STATUSES = [
    'on_sale'      => ['label' => 'On sale',       'icon' => '🟢', 'tone' => 'green', 'hint' => 'Customers can buy tickets.'],
    'sales_paused' => ['label' => 'Sales paused',  'icon' => '⏸',  'tone' => 'amber', 'hint' => 'Event is still on, but no new tickets can be bought.'],
    'postponed'    => ['label' => 'Postponed',     'icon' => '⏳', 'tone' => 'amber', 'hint' => 'New date not known yet. Sales stop; ticket holders may request refunds.'],
    'rescheduled'  => ['label' => 'Rescheduled',   'icon' => '📅', 'tone' => 'blue',  'hint' => 'Moved to a new date. Sales reopen; existing tickets stay valid or can be refunded.'],
    'cancelled'    => ['label' => 'Cancelled',     'icon' => '⛔', 'tone' => 'red',   'hint' => 'Event will not happen. Sales stop; ticket holders may request refunds.'],
];

const REFUND_CUTOFF_HOURS = 48;

const REFUND_REASONS = [
    'event_cancelled'   => 'Event was cancelled',
    'event_postponed'   => 'Event was postponed',
    'event_rescheduled' => 'New date doesn\'t work for me',
    'cant_attend'       => 'I can no longer attend',
    'bought_by_mistake' => 'Bought by mistake / wrong quantity',
    'other'             => 'Other reason',
];

const REFUND_STATUSES = [
    'pending'   => ['label' => 'Pending review', 'icon' => '⏳', 'tone' => 'amber'],
    'refunded'  => ['label' => 'Refunded',       'icon' => '✅', 'tone' => 'green'],
    'rejected'  => ['label' => 'Rejected',       'icon' => '✕',  'tone' => 'red'],
    'withdrawn' => ['label' => 'Withdrawn',      'icon' => '↩',  'tone' => 'dim'],
];

function event_status_of(array $event): string
{
    $status = $event['status'] ?? 'on_sale';
    return isset(EVENT_STATUSES[$status]) ? $status : 'on_sale';
}

function event_status_meta(array $event): array
{
    return EVENT_STATUSES[event_status_of($event)];
}

/** Can customers buy tickets for this event right now (ignoring capacity)? */
function event_is_on_sale(array $event): bool
{
    return in_array(event_status_of($event), ['on_sale', 'rescheduled'], true)
        && strtotime($event['event_date']) >= time();
}

/** Customer-facing sentence explaining why tickets can't be bought, or null. */
function event_sales_block_reason(array $event): ?string
{
    switch (event_status_of($event)) {
        case 'cancelled':    return "This event has been cancelled. Ticket holders can request a refund from My Tickets.";
        case 'postponed':    return "This event has been postponed. Sales will reopen once a new date is announced.";
        case 'sales_paused': return "Ticket sales for this event are paused at the moment. Please check back soon.";
    }
    return strtotime($event['event_date']) < time() ? "This event has already taken place." : null;
}

/** Refund reasons that are "the event changed" rather than the customer's choice. */
function refund_reason_is_event_side(string $reason): bool
{
    return in_array($reason, ['event_cancelled', 'event_postponed', 'event_rescheduled'], true);
}

/**
 * Can this booking be refunded on request?
 * $booking needs: status, checked_in_at, total_price; $event needs: status, event_date.
 * Returns ['ok' => bool, 'why' => customer-facing explanation, 'suggested_reason' => key|null]
 */
function refund_eligibility(array $booking, array $event): array
{
    if (($booking['status'] ?? '') !== 'confirmed') {
        return ['ok' => false, 'why' => 'This booking is no longer active.', 'suggested_reason' => null];
    }
    if ((float)$booking['total_price'] <= 0) {
        return ['ok' => false, 'why' => 'Free tickets have nothing to refund.', 'suggested_reason' => null];
    }
    if (!empty($booking['checked_in_at'])) {
        return ['ok' => false, 'why' => 'This ticket was already used at the door.', 'suggested_reason' => null];
    }

    $status = event_status_of($event);
    if ($status === 'cancelled') {
        return ['ok' => true, 'why' => 'The event was cancelled — you are entitled to a full refund.', 'suggested_reason' => 'event_cancelled'];
    }
    if ($status === 'postponed') {
        return ['ok' => true, 'why' => 'The event was postponed — you can ask for a full refund.', 'suggested_reason' => 'event_postponed'];
    }

    $start = strtotime($event['event_date']);
    if ($status === 'rescheduled') {
        return $start > time()
            ? ['ok' => true, 'why' => 'The event moved to a new date — if it no longer suits you, ask for a full refund.', 'suggested_reason' => 'event_rescheduled']
            : ['ok' => false, 'why' => 'This event has already taken place.', 'suggested_reason' => null];
    }

    // Event is going ahead as planned: change-of-mind refunds until the cutoff
    if ($start - time() >= REFUND_CUTOFF_HOURS * 3600) {
        return ['ok' => true, 'why' => 'Refund requests close ' . REFUND_CUTOFF_HOURS . ' hours before the event and are reviewed by the organizer.', 'suggested_reason' => 'cant_attend'];
    }
    return ['ok' => false, 'why' => $start > time()
        ? 'Refund requests closed ' . REFUND_CUTOFF_HOURS . ' hours before the event.'
        : 'This event has already taken place.', 'suggested_reason' => null];
}

/**
 * Change an event's status (manage_events.php). Caller has already checked the admin may edit this event.
 * $newDate ('Y-m-d\TH:i' from a datetime-local input) is required for 'rescheduled'.
 * Returns ['ok' => bool, 'message' => string].
 */
function event_status_change(mysqli $conn, int $eventId, string $newStatus, string $note, string $newDate, int $adminId): array
{
    if (!isset(EVENT_STATUSES[$newStatus])) {
        return ['ok' => false, 'message' => "Choose a valid event status."];
    }
    $note = mb_substr(trim($note), 0, 255);
    if (in_array($newStatus, ['postponed', 'cancelled'], true) && $note === '') {
        return ['ok' => false, 'message' => "Please add a short reason for customers when an event is " . strtolower(EVENT_STATUSES[$newStatus]['label']) . "."];
    }

    $conn->begin_transaction();
    try {
        $stmt = $conn->prepare("SELECT id, title, status, event_date, original_event_date FROM events WHERE id = ? FOR UPDATE");
        $stmt->bind_param("i", $eventId);
        $stmt->execute();
        $event = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$event) {
            $conn->rollback();
            return ['ok' => false, 'message' => "Event not found."];
        }

        $oldStatus = event_status_of($event);
        $oldDate   = $event['event_date'];
        $date      = $oldDate;

        if ($newStatus === 'rescheduled') {
            $ts = strtotime($newDate);
            if ($newDate === '' || $ts === false) {
                $conn->rollback();
                return ['ok' => false, 'message' => "Pick the new date and time for the rescheduled event."];
            }
            if ($ts <= time()) {
                $conn->rollback();
                return ['ok' => false, 'message' => "The new date must be in the future."];
            }
            $date = date('Y-m-d H:i:s', $ts);
            if ($date === $oldDate && $oldStatus === 'rescheduled') {
                $conn->rollback();
                return ['ok' => false, 'message' => "'{$event['title']}' is already rescheduled to that date."];
            }
        } elseif ($newStatus === $oldStatus && $note === '') {
            $conn->rollback();
            return ['ok' => false, 'message' => "'{$event['title']}' is already " . EVENT_STATUSES[$newStatus]['label'] . "."];
        }

        $original = $event['original_event_date'] ?: ($date !== $oldDate ? $oldDate : null);
        $noteOrNull = $note === '' ? null : $note;
        $upd = $conn->prepare(
            "UPDATE events SET status = ?, status_note = ?, event_date = ?, original_event_date = ?,
                               status_changed_at = NOW(), status_changed_by = ?
             WHERE id = ?"
        );
        $upd->bind_param("ssssii", $newStatus, $noteOrNull, $date, $original, $adminId, $eventId);
        $upd->execute();
        $upd->close();

        $newDateOrNull = $date !== $oldDate ? $date : null;
        $oldDateOrNull = $date !== $oldDate ? $oldDate : null;
        $log = $conn->prepare(
            "INSERT INTO event_status_history (event_id, old_status, new_status, old_date, new_date, note, changed_by, changed_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, NOW())"
        );
        $log->bind_param("isssssi", $eventId, $oldStatus, $newStatus, $oldDateOrNull, $newDateOrNull, $noteOrNull, $adminId);
        $log->execute();
        $log->close();

        $conn->commit();
    } catch (Throwable $e) {
        $conn->rollback();
        error_log("event_status_change #$eventId failed: " . $e->getMessage());
        return ['ok' => false, 'message' => "Could not update the event status. If you just updated TicketHUB, run files/setup_platform.php once."];
    }

    $label = EVENT_STATUSES[$newStatus]['label'];
    $msg = "'{$event['title']}' is now $label";
    if ($newStatus === 'rescheduled') {
        $msg .= " to " . date('M d, Y · h:i A', strtotime($date));
    }
    if ($newStatus === 'cancelled') {
        $msg .= ". To give everyone their money back in one go, use \"Refund everyone\" on the Refund requests page";
    } elseif (in_array($newStatus, ['postponed', 'rescheduled'], true)) {
        $msg .= ". Ticket holders can now request refunds from My Tickets";
    }
    return ['ok' => true, 'message' => $msg . "."];
}
