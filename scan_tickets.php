<?php
/**
 * scan_tickets.php
 * ------------------------------------------------------------
 * Door check-in / check-out for Admins & Super Admins.
 *
 * Opens the device camera and reads the QR on a TicketHub
 * e-ticket. The QR holds "TH1.<booking ref>.<ticket token>", so a
 * ticket can't be faked from the printed reference alone (older
 * tickets with a bare reference still scan, flagged as legacy).
 *
 * Two modes:
 *   Entry — first scan = first check-in; after a check-out the same
 *           QR re-enters; scanning someone already inside is refused
 *           (stops a ticket being passed back to a friend).
 *   Exit  — records a check-out so the guest can come back in.
 *
 * Every scan (accepted or refused) goes into ticket_scans, so each
 * ticket shows its full first check-in / check-out / re-entry history.
 *
 * Admins can only scan tickets for events they created;
 * Super Admins can scan any event.
 *
 * Note: browsers only allow camera access on https:// or localhost.
 * ------------------------------------------------------------
 */
session_start();

if (!isset($_SESSION['user_id'], $_SESSION['user_role']) || !in_array($_SESSION['user_role'], ['admin', 'super_admin'], true)) {
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
        http_response_code(403);
        header('Content-Type: application/json');
        echo json_encode(['status' => 'error', 'title' => 'Not allowed', 'message' => 'Please log in as an admin.']);
        exit();
    }
    header("Location: login.php");
    exit();
}

require __DIR__ . "/config/database.php";
require __DIR__ . "/config/app.php";

$currentUserId = (int)$_SESSION['user_id'];
$isSuperAdmin  = ($_SESSION['user_role'] === 'super_admin');
$csrfToken     = csrf_token();

const SCAN_RESULT_LABELS = [
    'first_entry'    => 'First check-in',
    're_entry'       => 'Re-entry',
    'checked_out'    => 'Checked out',
    'already_inside' => 'Refused — already inside',
    'already_out'    => 'Refused — already out',
    'not_checked_in' => 'Refused — never checked in',
    'cancelled'      => 'Refused — booking cancelled',
    'wrong_event'    => 'Refused — different event',
    'fake'           => 'Refused — invalid QR',
];

/**
 * Work out what the camera / keyboard gave us.
 * Returns ['ref' => 'TKT-…', 'token' => '…'|null, 'method' => 'qr'|'qr_legacy'|'manual'].
 */
function scan_parse_code(string $raw, string $source): array
{
    $raw = trim($raw);

    // Smart QR: TH1.TKT-XXXXXXXXXX.<32 hex>
    if (preg_match('/^TH1\.(TKT-[A-Z0-9]{6,16})\.([a-f0-9]{32})$/i', $raw, $m)) {
        return ['ref' => strtoupper($m[1]), 'token' => strtolower($m[2]), 'method' => 'qr'];
    }

    // An e-ticket link (view_ticket.php?ref=…&t=…) carries the token too
    $query = parse_url($raw, PHP_URL_QUERY);
    if ($query) {
        parse_str($query, $params);
        if (!empty($params['ref'])) {
            $token = (isset($params['t']) && preg_match('/^[a-f0-9]{32}$/i', $params['t'])) ? strtolower($params['t']) : null;
            return ['ref' => strtoupper(trim((string)$params['ref'])), 'token' => $token, 'method' => $token ? 'qr' : 'qr_legacy'];
        }
    }

    $ref = preg_match('/TKT-[A-Z0-9]+/i', $raw, $m)
        ? strtoupper($m[0])
        : strtoupper(substr(preg_replace('/[^A-Za-z0-9-]/', '', $raw), 0, 20));

    return ['ref' => $ref, 'token' => null, 'method' => $source === 'manual' ? 'manual' : 'qr_legacy'];
}

function scan_load_booking(mysqli $conn, string $ref): ?array
{
    $stmt = $conn->prepare(
        "SELECT b.id, b.booking_ref, b.quantity, b.status, b.ticket_token, b.event_id,
                b.checked_in_at, b.is_inside,
                e.title, e.event_date, e.location, e.created_by,
                u.name AS holder_name, u.email AS holder_email,
                s.name AS first_scanned_by
         FROM bookings b
         JOIN events e ON e.id = b.event_id
         LEFT JOIN users u ON u.id = b.user_id
         LEFT JOIN users s ON s.id = b.checked_in_by
         WHERE b.booking_ref = ?
         LIMIT 1"
    );
    $stmt->bind_param("s", $ref);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}

function scan_log(mysqli $conn, array $booking, string $direction, bool $accepted, string $result, string $method, int $userId): void
{
    $acceptedInt = $accepted ? 1 : 0;
    $stmt = $conn->prepare(
        "INSERT INTO ticket_scans (booking_id, event_id, direction, accepted, result, method, scanned_by, scanned_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, NOW())"
    );
    $stmt->bind_param("iisissi", $booking['id'], $booking['event_id'], $direction, $acceptedInt, $result, $method, $userId);
    $stmt->execute();
    $stmt->close();
}

function scan_history(mysqli $conn, int $bookingId): array
{
    $stmt = $conn->prepare(
        "SELECT t.direction, t.accepted, t.result, t.method, t.scanned_at, u.name AS scanned_by
         FROM ticket_scans t
         LEFT JOIN users u ON u.id = t.scanned_by
         WHERE t.booking_id = ?
         ORDER BY t.scanned_at DESC, t.id DESC
         LIMIT 50"
    );
    $stmt->bind_param("i", $bookingId);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    return array_map(static fn(array $r) => [
        'direction' => $r['direction'],
        'accepted'  => (bool)$r['accepted'],
        'label'     => SCAN_RESULT_LABELS[$r['result']] ?? $r['result'],
        'result'    => $r['result'],
        'method'    => $r['method'],
        'time'      => date("M d, h:i:s A", strtotime($r['scanned_at'])),
        'by'        => $r['scanned_by'] ?? '',
    ], $rows);
}

function scan_event_progress(mysqli $conn, int $eventId): array
{
    $stmt = $conn->prepare(
        "SELECT COALESCE(SUM(quantity), 0) AS sold,
                COALESCE(SUM(CASE WHEN checked_in_at IS NOT NULL THEN quantity ELSE 0 END), 0) AS arrived,
                COALESCE(SUM(CASE WHEN is_inside = 1 THEN quantity ELSE 0 END), 0) AS inside
         FROM bookings WHERE event_id = ? AND status = 'confirmed'"
    );
    $stmt->bind_param("i", $eventId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return ['arrived' => (int)$row['arrived'], 'inside' => (int)$row['inside'], 'sold' => (int)$row['sold']];
}

/** Everything the result card needs about one booking. */
function scan_booking_payload(mysqli $conn, string $ref): array
{
    $b = scan_load_booking($conn, $ref);
    $history = scan_history($conn, (int)$b['id']);
    $entries = count(array_filter($history, static fn($h) => $h['accepted'] && $h['direction'] === 'in'));
    $exits   = count(array_filter($history, static fn($h) => $h['accepted'] && $h['direction'] === 'out'));

    return [
        'ref'         => $b['booking_ref'],
        'event'       => $b['title'],
        'event_date'  => date("D, M d, Y · h:i A", strtotime($b['event_date'])),
        'location'    => $b['location'],
        'holder'      => $b['holder_name'] ?? '',
        'email'       => $b['holder_email'] ?? '',
        'quantity'    => (int)$b['quantity'],
        'first_in'    => $b['checked_in_at'] ? date("M d, h:i A", strtotime($b['checked_in_at'])) : null,
        'first_by'    => $b['first_scanned_by'] ?? '',
        'inside'      => (bool)$b['is_inside'],
        'entries'     => $entries,
        'exits'       => $exits,
        'history'     => $history,
        'event_id'    => (int)$b['event_id'],
    ];
}

/** Put a booking's is_inside / first check-in back in line with its remaining accepted scans. */
function scan_rebuild_state(mysqli $conn, int $bookingId): void
{
    $stmt = $conn->prepare(
        "SELECT direction FROM ticket_scans WHERE booking_id = ? AND accepted = 1 ORDER BY scanned_at DESC, id DESC LIMIT 1"
    );
    $stmt->bind_param("i", $bookingId);
    $stmt->execute();
    $last = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $inside = ($last && $last['direction'] === 'in') ? 1 : 0;

    $stmt = $conn->prepare(
        "SELECT scanned_at, scanned_by FROM ticket_scans
         WHERE booking_id = ? AND accepted = 1 AND direction = 'in' ORDER BY scanned_at ASC, id ASC LIMIT 1"
    );
    $stmt->bind_param("i", $bookingId);
    $stmt->execute();
    $first = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $firstAt = $first['scanned_at'] ?? null;
    $firstBy = isset($first['scanned_by']) ? (int)$first['scanned_by'] : null;
    $stmt = $conn->prepare("UPDATE bookings SET is_inside = ?, checked_in_at = ?, checked_in_by = ? WHERE id = ?");
    $stmt->bind_param("isii", $inside, $firstAt, $firstBy, $bookingId);
    $stmt->execute();
    $stmt->close();
}

// ------------------------------------------------------------
// JSON API
// ------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');

    $respond = static function (array $data): void {
        echo json_encode($data);
        exit();
    };

    if (!csrf_valid($_POST['csrf_token'] ?? null)) {
        $respond(['status' => 'error', 'title' => 'Session expired', 'message' => 'Reload the page and try again.']);
    }

    $action    = $_POST['action'] ?? 'scan';
    $eventId   = (int)($_POST['event_id'] ?? 0);
    $ownerSql  = $isSuperAdmin ? "1 = 1" : "e.created_by = $currentUserId";

    try {
        // Latest door activity (all staff, all devices) for the side list
        if ($action === 'recent') {
            $eventSql = $eventId > 0 ? "AND t.event_id = $eventId" : "";
            $rows = $conn->query(
                "SELECT t.direction, t.accepted, t.result, t.scanned_at, b.booking_ref, b.quantity, u.name AS holder
                 FROM ticket_scans t
                 JOIN bookings b ON b.id = t.booking_id
                 JOIN events e ON e.id = t.event_id
                 LEFT JOIN users u ON u.id = b.user_id
                 WHERE $ownerSql $eventSql
                 ORDER BY t.scanned_at DESC, t.id DESC
                 LIMIT 15"
            )->fetch_all(MYSQLI_ASSOC);
            $respond(['status' => 'ok', 'scans' => array_map(static fn($r) => [
                'ref'       => $r['booking_ref'],
                'holder'    => $r['holder'] ?? '',
                'quantity'  => (int)$r['quantity'],
                'direction' => $r['direction'],
                'accepted'  => (bool)$r['accepted'],
                'label'     => SCAN_RESULT_LABELS[$r['result']] ?? $r['result'],
                'time'      => date("h:i:s A", strtotime($r['scanned_at'])),
            ], $rows)]);
        }

        $parsed = scan_parse_code((string)($_POST['code'] ?? ''), (string)($_POST['source'] ?? 'camera'));
        $ref    = $parsed['ref'];

        if ($ref === '') {
            $respond(['status' => 'not_found', 'title' => 'Not a TicketHub ticket', 'message' => 'This QR code does not contain a TicketHub booking.']);
        }

        $booking = scan_load_booking($conn, $ref);
        if (!$booking) {
            $respond(['status' => 'not_found', 'title' => 'Ticket not found', 'message' => "No booking with reference $ref. Do not admit.", 'ref' => $ref]);
        }
        if (!$isSuperAdmin && (int)$booking['created_by'] !== $currentUserId) {
            $respond(['status' => 'forbidden', 'title' => 'Not your event', 'message' => 'This ticket belongs to an event managed by another organizer.', 'ref' => $ref]);
        }

        // ---- Look a ticket up without recording a scan (from the activity list) ----
        if ($action === 'lookup') {
            $payload = scan_booking_payload($conn, $ref);
            $respond([
                'status' => $payload['inside'] ? 're_entry' : ($payload['first_in'] ? 'checked_out' : 'undone'),
                'title' => $payload['inside'] ? 'Inside now' : ($payload['first_in'] ? 'Outside (checked out)' : 'Not arrived yet'),
                'message' => 'Ticket details — nothing was scanned.',
                'booking' => $payload, 'progress' => scan_event_progress($conn, (int)$booking['event_id']),
            ]);
        }

        // ---- Undo the most recent accepted scan ----
        if ($action === 'undo') {
            $conn->begin_transaction();
            $stmt = $conn->prepare(
                "SELECT id FROM ticket_scans WHERE booking_id = ? AND accepted = 1 ORDER BY scanned_at DESC, id DESC LIMIT 1 FOR UPDATE"
            );
            $stmt->bind_param("i", $booking['id']);
            $stmt->execute();
            $lastScan = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if ($lastScan) {
                $conn->query("DELETE FROM ticket_scans WHERE id = " . (int)$lastScan['id']);
            }
            scan_rebuild_state($conn, (int)$booking['id']);
            $conn->commit();

            $payload = scan_booking_payload($conn, $ref);
            $respond([
                'status' => 'undone', 'title' => $lastScan ? 'Last scan undone' : 'Nothing to undo',
                'message' => $payload['inside'] ? 'Guest is now marked as inside.' : ($payload['first_in'] ? 'Guest is now marked as outside.' : 'Ticket is back to not checked in.'),
                'booking' => $payload, 'progress' => scan_event_progress($conn, (int)$booking['event_id']),
            ]);
        }

        if ($action !== 'scan') {
            $respond(['status' => 'error', 'title' => 'Unknown request', 'message' => 'Reload the page and try again.']);
        }

        $direction = (($_POST['direction'] ?? 'in') === 'out') ? 'out' : 'in';
        $method    = $parsed['method'];

        $refuse = static function (string $status, string $title, string $message, string $result) use ($conn, $booking, $direction, $method, $currentUserId, $ref, $respond) {
            scan_log($conn, $booking, $direction, false, $result, $method, $currentUserId);
            $respond([
                'status' => $status, 'title' => $title, 'message' => $message,
                'booking' => scan_booking_payload($conn, $ref),
                'progress' => scan_event_progress($conn, (int)$booking['event_id']),
            ]);
        };

        // Smart QR: the secret token must match this booking
        if ($parsed['token'] !== null && !hash_equals((string)$booking['ticket_token'], $parsed['token'])) {
            $refuse('fake', 'Invalid QR code', 'The security code in this QR does not match the booking. Do not admit — this ticket may be copied or edited.', 'fake');
        }
        if ($booking['status'] !== 'confirmed') {
            $refuse('cancelled', 'Booking cancelled', 'Do not admit — this booking was cancelled.', 'cancelled');
        }
        if ($eventId > 0 && (int)$booking['event_id'] !== $eventId) {
            $refuse('wrong_event', 'Different event', 'This ticket is for "' . $booking['title'] . '", not the event selected on this scanner.', 'wrong_event');
        }

        $notes = [];
        if ($method === 'qr_legacy') {
            $notes[] = 'Older ticket QR (reference only, no security code). Check the guest\'s e-ticket on their phone.';
        } elseif ($method === 'manual') {
            $notes[] = 'Entered by hand — check the e-ticket on the guest\'s phone matches.';
        }

        if ($direction === 'in') {
            // Atomic: only one scanner can flip the guest to "inside"
            $stmt = $conn->prepare(
                "UPDATE bookings
                 SET is_inside = 1,
                     checked_in_by = IF(checked_in_at IS NULL, ?, checked_in_by),
                     checked_in_at = COALESCE(checked_in_at, NOW())
                 WHERE id = ? AND is_inside = 0 AND status = 'confirmed'"
            );
            $stmt->bind_param("ii", $currentUserId, $booking['id']);
            $stmt->execute();
            $entered = $stmt->affected_rows === 1;
            $stmt->close();

            if (!$entered) {
                $last = scan_booking_payload($conn, $ref);
                $lastIn = null;
                foreach ($last['history'] as $h) {
                    if ($h['accepted'] && $h['direction'] === 'in') { $lastIn = $h; break; }
                }
                $refuse('already_inside', 'Already inside',
                    'This ticket was scanned in' . ($lastIn ? ' at ' . $lastIn['time'] . ($lastIn['by'] !== '' ? ' by ' . $lastIn['by'] : '') : '') . ' and has not checked out. Do not admit again.',
                    'already_inside');
            }

            $isFirst = $booking['checked_in_at'] === null;
            scan_log($conn, $booking, 'in', true, $isFirst ? 'first_entry' : 're_entry', $method, $currentUserId);

            if ($isFirst && date('Y-m-d', strtotime($booking['event_date'])) !== date('Y-m-d')) {
                $notes[] = 'Heads up: this event is on ' . date('M d, Y', strtotime($booking['event_date'])) . ', not today.';
            }
            $qty = (int)$booking['quantity'];
            $respond([
                'status'  => $isFirst ? 'first_entry' : 're_entry',
                'title'   => ($isFirst ? 'Admit ' : 'Welcome back — admit ') . $qty . ($qty === 1 ? ' person' : ' people'),
                'message' => $isFirst ? 'Valid ticket. First check-in recorded.' : 'Re-entry after check-out. Recorded.',
                'note'    => implode(' ', $notes),
                'booking' => scan_booking_payload($conn, $ref),
                'progress' => scan_event_progress($conn, (int)$booking['event_id']),
            ]);
        }

        // ---- Exit ----
        $stmt = $conn->prepare("UPDATE bookings SET is_inside = 0 WHERE id = ? AND is_inside = 1");
        $stmt->bind_param("i", $booking['id']);
        $stmt->execute();
        $left = $stmt->affected_rows === 1;
        $stmt->close();

        if (!$left) {
            if ($booking['checked_in_at'] === null) {
                $refuse('not_checked_in', 'Never checked in', 'This ticket has not been scanned in yet, so it cannot check out.', 'not_checked_in');
            }
            $refuse('already_out', 'Already checked out', 'This guest is already marked as outside.', 'already_out');
        }

        scan_log($conn, $booking, 'out', true, 'checked_out', $method, $currentUserId);
        $respond([
            'status' => 'checked_out', 'title' => 'Checked out',
            'message' => 'Guest is marked as outside. They can come back in with the same QR.',
            'note' => implode(' ', $notes),
            'booking' => scan_booking_payload($conn, $ref),
            'progress' => scan_event_progress($conn, (int)$booking['event_id']),
        ]);
    } catch (mysqli_sql_exception $e) {
        error_log("Ticket scan failed: " . $e->getMessage());
        $respond(['status' => 'error', 'title' => 'Something went wrong', 'message' => 'Could not check this ticket. Try again.']);
    }
}

// ------------------------------------------------------------
// Page: events this admin can scan (recent & upcoming)
// ------------------------------------------------------------
$events = [];
try {
    $ownerFilter = $isSuperAdmin ? "1 = 1" : "created_by = $currentUserId";
    $events = $conn->query(
        "SELECT id, title, event_date FROM events
         WHERE $ownerFilter AND event_date >= (NOW() - INTERVAL 2 DAY)
         ORDER BY event_date ASC LIMIT 100"
    )->fetch_all(MYSQLI_ASSOC);
} catch (mysqli_sql_exception $e) {
    error_log("Scan page event list failed: " . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<?php include __DIR__ . "/pwa_head.php"; ?>
<title>Scan Tickets - TicketHub</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Sora:wght@600;700;800&family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
<style>
:root {
    --bg: #14121A;
    --surface: rgba(255,255,255,0.04);
    --surface-hover: rgba(255,255,255,0.07);
    --field: rgba(255,255,255,0.05);
    --border: rgba(255,255,255,0.08);
    --border-hover: rgba(255,255,255,0.16);
    --text: #F1EEF7;
    --text-dim: #A9A3B8;
    --violet: #9D7CF9;
    --violet-soft: rgba(157,124,249,0.18);
    --rose: #F0729C;
    --green: #6FCF97;
    --amber: #F2C572;
    --sky: #7CC4F9;
    --font-display: 'Sora', 'Segoe UI', sans-serif;
    --font-body: 'Inter', 'Segoe UI', sans-serif;
}

* { box-sizing: border-box; }

body {
    margin: 0;
    font-family: var(--font-body);
    background: var(--bg);
    color: var(--text);
    overflow-x: hidden;
}

.main {
    max-width: 1100px;
    margin: 0 auto;
    padding: 40px 24px 70px;
}

.intro { margin-bottom: 24px; }

.eyebrow {
    display: inline-block;
    font-size: 12px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.08em;
    color: var(--violet);
    margin-bottom: 8px;
}

h1 {
    font-family: var(--font-display);
    font-size: 32px;
    margin: 0 0 6px;
    letter-spacing: -0.01em;
}

.intro p { color: var(--text-dim); margin: 0; font-size: 14px; line-height: 1.5; max-width: 720px; }

.layout {
    display: grid;
    grid-template-columns: minmax(0, 1fr) minmax(0, 1fr);
    gap: 24px;
    align-items: start;
}

.panel {
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: 18px;
    padding: 20px;
}

.field-label {
    display: block;
    font-size: 13px;
    font-weight: 500;
    margin-bottom: 7px;
}

select, input[type="text"] {
    width: 100%;
    padding: 12px 14px;
    border: 1px solid var(--border);
    border-radius: 12px;
    background: var(--field);
    color: var(--text);
    font-family: var(--font-body);
    font-size: 14px;
    outline: none;
}

select option { background: #1B1822; }

select:focus, input[type="text"]:focus {
    border-color: var(--violet);
    box-shadow: 0 0 0 3px var(--violet-soft);
}

/* ENTRY / EXIT MODE */
.mode {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 6px;
    padding: 5px;
    border-radius: 14px;
    background: rgba(0,0,0,0.3);
    border: 1px solid var(--border);
    margin-bottom: 16px;
}

.mode button {
    padding: 12px 10px;
    border: none;
    border-radius: 10px;
    background: transparent;
    color: var(--text-dim);
    font-family: var(--font-body);
    font-size: 15px;
    font-weight: 600;
    cursor: pointer;
    transition: background 0.2s ease, color 0.2s ease;
}

.mode button:hover { color: var(--text); }
.mode button.active[data-dir="in"]  { background: var(--green); color: #0f2a1b; }
.mode button.active[data-dir="out"] { background: var(--sky); color: #0b2133; }

.camera-box {
    position: relative;
    margin-top: 16px;
    border-radius: 14px;
    overflow: hidden;
    background: #0c0b10;
    aspect-ratio: 1 / 1;
    border: 2px solid var(--border);
    transition: border-color 0.2s ease;
}

.camera-box.mode-in.live  { border-color: rgba(111,207,151,0.7); }
.camera-box.mode-out.live { border-color: rgba(124,196,249,0.7); }

#reader { width: 100%; height: 100%; }
#reader video { width: 100% !important; height: 100% !important; object-fit: cover; }

.camera-placeholder {
    position: absolute;
    inset: 0;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    gap: 12px;
    text-align: center;
    padding: 24px;
    color: var(--text-dim);
    font-size: 14px;
    line-height: 1.5;
}

.camera-placeholder .icon { font-size: 40px; }

.scan-frame {
    position: absolute;
    inset: 18%;
    border: 3px solid rgba(255,255,255,0.85);
    border-radius: 18px;
    box-shadow: 0 0 0 9999px rgba(0,0,0,0.35);
    pointer-events: none;
    display: none;
}

.mode-chip {
    position: absolute;
    top: 12px;
    left: 12px;
    padding: 5px 12px;
    border-radius: 100px;
    font-size: 12px;
    font-weight: 700;
    letter-spacing: 0.04em;
    text-transform: uppercase;
    display: none;
}

.camera-box.mode-in .mode-chip  { background: var(--green); color: #0f2a1b; }
.camera-box.mode-out .mode-chip { background: var(--sky); color: #0b2133; }

.camera-box.live .scan-frame,
.camera-box.live .mode-chip { display: block; }
.camera-box.live .camera-placeholder { display: none; }

.cam-actions {
    display: flex;
    gap: 10px;
    margin-top: 14px;
    flex-wrap: wrap;
}

.btn {
    flex: 1;
    min-width: 140px;
    padding: 12px 18px;
    border-radius: 100px;
    border: none;
    cursor: pointer;
    font-family: var(--font-body);
    font-size: 14px;
    font-weight: 600;
    color: #121017;
    background: var(--text);
    transition: background 0.2s ease, color 0.2s ease, transform 0.2s ease;
}

.btn:hover { background: var(--violet); color: #fff; transform: translateY(-1px); }

.btn.ghost {
    background: transparent;
    color: var(--text);
    border: 1px solid var(--border-hover);
}

.btn.ghost:hover { background: var(--surface-hover); }

.btn[hidden] { display: none; }

.manual {
    display: flex;
    gap: 8px;
    margin-top: 18px;
}

.manual input { flex: 1; min-width: 0; text-transform: uppercase; font-family: monospace; letter-spacing: 0.5px; }
.manual .btn { flex: 0 0 auto; min-width: 0; }

.hint { font-size: 12px; color: var(--text-dim); margin: 8px 0 0; line-height: 1.5; }

/* RESULT CARD */
.result {
    border-radius: 18px;
    padding: 24px;
    border: 1px solid var(--border);
    background: var(--surface);
    min-height: 220px;
}

.result.idle { display: flex; align-items: center; justify-content: center; text-align: center; color: var(--text-dim); font-size: 14px; }

.result .badge {
    width: 56px;
    height: 56px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 28px;
    font-weight: 700;
    margin-bottom: 14px;
}

.result h2 {
    font-family: var(--font-display);
    font-size: 26px;
    margin: 0 0 6px;
}

.result .message { margin: 0; color: var(--text); font-size: 14px; line-height: 1.5; }
.result .note { margin: 10px 0 0; color: var(--amber); font-size: 13px; line-height: 1.5; }

.result.ok     { background: rgba(111,207,151,0.12); border-color: rgba(111,207,151,0.45); }
.result.ok .badge { background: var(--green); color: #0f2a1b; }
.result.ok h2 { color: var(--green); }

.result.out    { background: rgba(124,196,249,0.1); border-color: rgba(124,196,249,0.45); }
.result.out .badge { background: var(--sky); color: #0b2133; }
.result.out h2 { color: var(--sky); }

.result.bad    { background: rgba(240,114,156,0.12); border-color: rgba(240,114,156,0.45); }
.result.bad .badge { background: var(--rose); color: #2c0d18; }
.result.bad h2 { color: #F8A7C0; }

.result.warn   { background: rgba(242,197,114,0.1); border-color: rgba(242,197,114,0.45); }
.result.warn .badge { background: var(--amber); color: #2b2005; }
.result.warn h2 { color: var(--amber); }

.details {
    margin-top: 18px;
    width: 100%;
    border-collapse: collapse;
    font-size: 14px;
}

.details td { padding: 7px 0; border-top: 1px solid var(--border); vertical-align: top; }
.details td:first-child { color: var(--text-dim); width: 38%; padding-right: 10px; }
.details td:last-child { word-break: break-word; }
.details .ref { font-family: monospace; color: var(--violet); letter-spacing: 0.5px; }

.presence {
    display: inline-block;
    padding: 2px 10px;
    border-radius: 100px;
    font-size: 12px;
    font-weight: 700;
}
.presence.in  { background: rgba(111,207,151,0.2); color: var(--green); }
.presence.out { background: rgba(124,196,249,0.18); color: var(--sky); }
.presence.never { background: rgba(255,255,255,0.08); color: var(--text-dim); }

/* SCAN HISTORY TIMELINE */
.timeline-title {
    margin: 20px 0 8px;
    font-size: 13px;
    font-weight: 600;
    color: var(--text-dim);
    text-transform: uppercase;
    letter-spacing: 0.06em;
}

.timeline { list-style: none; margin: 0; padding: 0; max-height: 260px; overflow-y: auto; }

.timeline li {
    position: relative;
    padding: 6px 0 10px 22px;
    font-size: 13px;
    line-height: 1.4;
}

.timeline li::before {
    content: '';
    position: absolute;
    left: 5px;
    top: 11px;
    width: 9px;
    height: 9px;
    border-radius: 50%;
    background: var(--text-dim);
}

.timeline li::after {
    content: '';
    position: absolute;
    left: 9px;
    top: 22px;
    bottom: -4px;
    width: 1px;
    background: var(--border);
}

.timeline li:last-child::after { display: none; }
.timeline li.in::before { background: var(--green); }
.timeline li.out::before { background: var(--sky); }
.timeline li.refused::before { background: var(--rose); }
.timeline li.first::before { box-shadow: 0 0 0 3px rgba(111,207,151,0.3); }
.timeline .t-label { font-weight: 600; }
.timeline li.refused .t-label { color: #F8A7C0; }
.timeline .t-meta { color: var(--text-dim); font-size: 12px; }

.result-actions { display: flex; gap: 10px; margin-top: 16px; flex-wrap: wrap; }

.progress {
    margin-top: 16px;
    font-size: 13px;
    color: var(--text-dim);
}

.bar {
    position: relative;
    height: 6px;
    border-radius: 6px;
    background: rgba(255,255,255,0.08);
    overflow: hidden;
    margin-top: 6px;
}

.bar span { position: absolute; left: 0; top: 0; bottom: 0; transition: width 0.4s ease; }
.bar .arrived { background: rgba(157,124,249,0.45); }
.bar .inside { background: var(--green); }

/* RECENT ACTIVITY */
.recent { margin-top: 24px; }
.recent-head { display: flex; justify-content: space-between; align-items: baseline; gap: 10px; }
.recent h3 { font-family: var(--font-display); font-size: 16px; margin: 0 0 10px; }
.recent .refresh { background: none; border: none; color: var(--violet); font-size: 13px; cursor: pointer; font-family: var(--font-body); }
.recent ul { list-style: none; margin: 0; padding: 0; }
.recent li {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 10px;
    padding: 9px 12px;
    border-radius: 10px;
    background: var(--surface);
    border: 1px solid var(--border);
    margin-bottom: 6px;
    font-size: 13px;
    cursor: pointer;
}
.recent li:hover { background: var(--surface-hover); }
.recent li .r-main { min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.recent li .r-ref { font-family: monospace; }
.recent li .r-status { font-weight: 600; white-space: nowrap; font-size: 12px; }
.recent li.in .r-status { color: var(--green); }
.recent li.out .r-status { color: var(--sky); }
.recent li.refused .r-status { color: #F8A7C0; }
.recent .empty { color: var(--text-dim); font-size: 13px; background: none; border: none; padding: 0; cursor: default; }

@media (max-width: 820px) {
    .layout { grid-template-columns: 1fr; }
    .main { padding: 24px 16px 60px; }
    h1 { font-size: 26px; }
}
</style>
</head>
<body>

<?php require __DIR__ . "/nav.php"; ?>

<div class="main">
    <div class="intro">
        <span class="eyebrow">Door check-in</span>
        <h1>Scan Tickets</h1>
        <p>Scan the QR on a TicketHub e-ticket. Use <strong>Entry</strong> at the door and <strong>Exit</strong> when a guest leaves. Each ticket keeps its first check-in and every check-out and re-entry, and a ticket that's already inside can't be used again.<?php if (!$isSuperAdmin): ?> You can scan tickets for the events you manage.<?php endif; ?></p>
    </div>

    <div class="layout">
        <section class="panel">
            <div class="mode" role="group" aria-label="Scan mode">
                <button type="button" data-dir="in" class="active">⬇ Entry</button>
                <button type="button" data-dir="out">⬆ Exit</button>
            </div>

            <label class="field-label" for="eventSelect">Scanning for</label>
            <select id="eventSelect">
                <option value="0">Any event<?php echo $isSuperAdmin ? '' : ' I manage'; ?></option>
                <?php foreach ($events as $ev): ?>
                    <option value="<?php echo (int)$ev['id']; ?>">
                        <?php echo htmlspecialchars($ev['title']) . ' — ' . date("M d, h:i A", strtotime($ev['event_date'])); ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <p class="hint">Pick an event so tickets for other events are refused instead of checked in.</p>

            <div class="camera-box mode-in" id="cameraBox">
                <div id="reader"></div>
                <div class="scan-frame" aria-hidden="true"></div>
                <div class="mode-chip" id="modeChip">Entry</div>
                <div class="camera-placeholder">
                    <div class="icon">📷</div>
                    <div id="cameraMsg">Tap <strong>Start camera</strong> to begin scanning.</div>
                </div>
            </div>

            <div class="cam-actions">
                <button type="button" class="btn" id="startBtn">Start camera</button>
                <button type="button" class="btn ghost" id="stopBtn" hidden>Stop camera</button>
                <button type="button" class="btn ghost" id="switchBtn" hidden>Switch camera</button>
            </div>

            <form class="manual" id="manualForm" autocomplete="off">
                <input type="text" id="manualRef" placeholder="TKT-XXXXXXXXXX" aria-label="Booking reference">
                <button type="submit" class="btn">Check</button>
            </form>
            <p class="hint">QR won't scan? Type the booking reference printed under the code.</p>
        </section>

        <section>
            <div class="result idle" id="result" aria-live="polite">
                Scan result will appear here.
            </div>

            <div class="recent">
                <div class="recent-head">
                    <h3>Live door activity</h3>
                    <button type="button" class="refresh" id="refreshBtn">Refresh</button>
                </div>
                <ul id="recentList"><li class="empty">No scans yet.</li></ul>
            </div>
        </section>
    </div>
</div>

<script src="https://unpkg.com/html5-qrcode@2.3.8/html5-qrcode.min.js"></script>
<script>
(function () {
    const CSRF = <?php echo json_encode($csrfToken); ?>;
    const cameraBox   = document.getElementById('cameraBox');
    const cameraMsg   = document.getElementById('cameraMsg');
    const modeChip    = document.getElementById('modeChip');
    const startBtn    = document.getElementById('startBtn');
    const stopBtn     = document.getElementById('stopBtn');
    const switchBtn   = document.getElementById('switchBtn');
    const eventSelect = document.getElementById('eventSelect');
    const resultEl    = document.getElementById('result');
    const recentList  = document.getElementById('recentList');
    const manualForm  = document.getElementById('manualForm');
    const manualRef   = document.getElementById('manualRef');
    const modeButtons = document.querySelectorAll('.mode button');

    let direction = 'in';
    let scanner = null;
    let cameras = [];
    let cameraIndex = -1;
    let busy = false;
    let lastCode = '';
    let lastCodeAt = 0;

    const TONE = {
        first_entry: 'ok', re_entry: 'ok', checked_out: 'out', undone: 'warn',
        already_inside: 'bad', cancelled: 'bad', not_found: 'bad', forbidden: 'bad', fake: 'bad',
        already_out: 'warn', not_checked_in: 'warn', wrong_event: 'warn', error: 'warn'
    };
    const ICON = { ok: '✓', out: '↗', bad: '✕', warn: '!' };

    function esc(s) {
        return String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
    }

    // Remember Entry/Exit and the chosen event on this device
    function store(key, value) { try { localStorage.setItem('scan_' + key, value); } catch (e) {} }
    function recall(key) { try { return localStorage.getItem('scan_' + key); } catch (e) { return null; } }

    function setDirection(dir) {
        direction = dir === 'out' ? 'out' : 'in';
        modeButtons.forEach(b => b.classList.toggle('active', b.dataset.dir === direction));
        cameraBox.classList.toggle('mode-in', direction === 'in');
        cameraBox.classList.toggle('mode-out', direction === 'out');
        modeChip.textContent = direction === 'in' ? 'Entry' : 'Exit';
        lastCode = '';
        store('direction', direction);
    }

    modeButtons.forEach(b => b.addEventListener('click', () => setDirection(b.dataset.dir)));
    setDirection(recall('direction') || 'in');

    const savedEvent = recall('event');
    if (savedEvent && eventSelect.querySelector(`option[value="${CSS.escape(savedEvent)}"]`)) {
        eventSelect.value = savedEvent;
    }
    eventSelect.addEventListener('change', () => { store('event', eventSelect.value); loadRecent(); });

    function beep(tone) {
        const good = tone === 'ok' || tone === 'out';
        try {
            const ctx = new (window.AudioContext || window.webkitAudioContext)();
            const osc = ctx.createOscillator();
            const gain = ctx.createGain();
            osc.frequency.value = tone === 'ok' ? 880 : (tone === 'out' ? 660 : 220);
            gain.gain.value = 0.15;
            osc.connect(gain); gain.connect(ctx.destination);
            osc.start(); osc.stop(ctx.currentTime + (good ? 0.15 : 0.45));
        } catch (e) { /* sound is optional */ }
        if (navigator.vibrate) navigator.vibrate(good ? 80 : [120, 60, 120]);
    }

    function presenceBadge(b) {
        if (b.inside) return '<span class="presence in">Inside now</span>';
        if (b.first_in) return '<span class="presence out">Outside (checked out)</span>';
        return '<span class="presence never">Not arrived yet</span>';
    }

    function render(data) {
        const tone = TONE[data.status] || 'warn';
        const b = data.booking;
        let html = `<div class="badge">${ICON[tone]}</div>
            <h2>${esc(data.title)}</h2>
            <p class="message">${esc(data.message)}</p>`;
        if (data.note) html += `<p class="note">${esc(data.note)}</p>`;

        if (b) {
            html += `<table class="details">
                <tr><td>Reference</td><td class="ref">${esc(b.ref)}</td></tr>
                <tr><td>Ticket holder</td><td>${esc(b.holder)}${b.email ? '<br><span style="color:var(--text-dim);font-size:12px;">' + esc(b.email) + '</span>' : ''}</td></tr>
                <tr><td>Tickets</td><td><strong>${esc(b.quantity)}</strong></td></tr>
                <tr><td>Event</td><td>${esc(b.event)}<br><span style="color:var(--text-dim);font-size:12px;">${esc(b.event_date)} · ${esc(b.location)}</span></td></tr>
                <tr><td>Status</td><td>${presenceBadge(b)}</td></tr>
                <tr><td>First check-in</td><td>${b.first_in ? esc(b.first_in) + (b.first_by ? ' · ' + esc(b.first_by) : '') : '—'}</td></tr>
                <tr><td>Entries / exits</td><td>${esc(b.entries)} in · ${esc(b.exits)} out</td></tr>
            </table>`;

            if (b.history && b.history.length) {
                const firstIdx = b.history.map(h => h.result).lastIndexOf('first_entry');
                html += `<div class="timeline-title">Scan history</div><ul class="timeline">`;
                b.history.forEach((h, i) => {
                    const cls = !h.accepted ? 'refused' : h.direction;
                    const how = h.method === 'manual' ? ' · typed' : (h.method === 'qr_legacy' ? ' · old QR' : '');
                    html += `<li class="${cls}${i === firstIdx ? ' first' : ''}">
                        <div class="t-label">${esc(h.label)}</div>
                        <div class="t-meta">${esc(h.time)}${h.by ? ' · ' + esc(h.by) : ''}${how}</div>
                    </li>`;
                });
                html += `</ul>`;
            }
        }

        if (data.progress && data.progress.sold > 0) {
            const p = data.progress;
            const arrivedPct = Math.min(100, Math.round(p.arrived / p.sold * 100));
            const insidePct = Math.min(100, Math.round(p.inside / p.sold * 100));
            html += `<div class="progress"><strong style="color:var(--green)">${p.inside}</strong> inside now · ${p.arrived} arrived · ${p.sold} tickets sold
                <div class="bar"><span class="arrived" style="width:${arrivedPct}%"></span><span class="inside" style="width:${insidePct}%"></span></div></div>`;
        }

        if (b && b.history && b.history.some(h => h.accepted)) {
            html += `<div class="result-actions">
                <button type="button" class="btn ghost" data-undo="${esc(b.ref)}">Undo last scan</button>
            </div>`;
        }

        resultEl.className = 'result ' + tone;
        resultEl.innerHTML = html;

        const undoBtn = resultEl.querySelector('[data-undo]');
        if (undoBtn) {
            undoBtn.addEventListener('click', () => {
                if (confirm('Undo the last accepted scan for ' + undoBtn.dataset.undo + '?')) {
                    post({ action: 'undo', code: undoBtn.dataset.undo, source: 'manual' }).then(data => { render(data); loadRecent(); });
                }
            });
        }
    }

    async function post(fields) {
        const body = new URLSearchParams(Object.assign({ csrf_token: CSRF, event_id: eventSelect.value }, fields));
        const res = await fetch('scan_tickets.php', { method: 'POST', body: body, credentials: 'same-origin' });
        return res.json();
    }

    async function scan(code, source) {
        if (busy) return;
        busy = true;
        resultEl.className = 'result idle';
        resultEl.textContent = 'Checking ticket…';
        try {
            const data = await post({ action: 'scan', code: code, source: source, direction: direction });
            render(data);
            beep(TONE[data.status] || 'warn');
            loadRecent();
        } catch (e) {
            render({ status: 'error', title: 'Connection problem', message: 'Could not reach the server. Check your internet and scan again.' });
            beep('bad');
        } finally {
            busy = false;
        }
    }

    async function loadRecent() {
        try {
            const data = await post({ action: 'recent' });
            if (!data.scans) return;
            recentList.innerHTML = '';
            if (!data.scans.length) {
                recentList.innerHTML = '<li class="empty">No scans yet.</li>';
                return;
            }
            data.scans.forEach(s => {
                const li = document.createElement('li');
                li.className = !s.accepted ? 'refused' : s.direction;
                li.title = 'Show this ticket';
                li.innerHTML = `<span class="r-main"><span class="r-ref">${esc(s.ref)}</span>
                        <span style="color:var(--text-dim)">· ${esc(s.holder)} · ${esc(s.time)}</span></span>
                    <span class="r-status">${esc(s.label)}</span>`;
                li.addEventListener('click', () => showTicket(s.ref));
                recentList.appendChild(li);
            });
        } catch (e) { /* the list is a convenience */ }
    }

    async function showTicket(ref) {
        try {
            const data = await post({ action: 'lookup', code: ref, source: 'manual' });
            render(data);
        } catch (e) { /* ignore */ }
    }

    function onScan(text) {
        const now = Date.now();
        // Ignore the same code held in front of the camera
        if (busy || (text === lastCode && now - lastCodeAt < 4000)) return;
        lastCode = text;
        lastCodeAt = now;
        scan(text, 'camera');
    }

    async function startCamera(index) {
        if (!window.isSecureContext) {
            cameraMsg.innerHTML = 'Camera needs a secure connection. Open this page over <strong>https://</strong>.';
            return;
        }
        if (typeof Html5Qrcode === 'undefined') {
            cameraMsg.textContent = 'Scanner could not load. Check your internet connection and reload.';
            return;
        }
        try {
            if (!scanner) scanner = new Html5Qrcode('reader', { verbose: false });
            if (scanner.isScanning) await scanner.stop();

            if (!cameras.length) {
                try { cameras = await Html5Qrcode.getCameras(); } catch (e) { cameras = []; }
            }
            let source = { facingMode: 'environment' };
            if (typeof index === 'number' && cameras[index]) {
                source = cameras[index].id;
                cameraIndex = index;
            }

            await scanner.start(source, { fps: 10, qrbox: (w, h) => {
                const size = Math.floor(Math.min(w, h) * 0.64);
                return { width: size, height: size };
            } }, onScan, () => {});

            cameraBox.classList.add('live');
            startBtn.hidden = true;
            stopBtn.hidden = false;
            switchBtn.hidden = cameras.length < 2;
        } catch (err) {
            const msg = String(err && (err.name || err.message || err));
            cameraMsg.innerHTML = /NotAllowed|Permission/i.test(msg)
                ? 'Camera permission was blocked. Allow camera access in your browser\'s address bar, then tap <strong>Start camera</strong> again.'
                : (/NotFound|no camera/i.test(msg) ? 'No camera found on this device. Type the booking reference below instead.' : 'Could not start the camera: ' + esc(msg));
            cameraBox.classList.remove('live');
        }
    }

    async function stopCamera() {
        if (scanner && scanner.isScanning) await scanner.stop();
        cameraBox.classList.remove('live');
        cameraMsg.innerHTML = 'Camera stopped. Tap <strong>Start camera</strong> to scan again.';
        startBtn.hidden = false;
        stopBtn.hidden = true;
        switchBtn.hidden = true;
    }

    startBtn.addEventListener('click', () => startCamera());
    stopBtn.addEventListener('click', stopCamera);
    switchBtn.addEventListener('click', () => {
        if (cameras.length) startCamera((cameraIndex + 1) % cameras.length);
    });
    document.getElementById('refreshBtn').addEventListener('click', loadRecent);

    manualForm.addEventListener('submit', (e) => {
        e.preventDefault();
        const code = manualRef.value.trim();
        if (!code) return;
        scan(code, 'manual');
        manualRef.value = '';
    });

    // Release the camera when the tab is hidden
    document.addEventListener('visibilitychange', () => {
        if (document.hidden && scanner && scanner.isScanning) stopCamera();
    });

    loadRecent();
    setInterval(() => { if (!document.hidden) loadRecent(); }, 15000);
})();
</script>

</body>
</html>
