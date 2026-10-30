<?php
/**
 * event_details.php
 * ------------------------------------------------------------
 * Admin-only 360° view of ONE event:
 *   - event info, status & countdown
 *   - sales, capacity, live seat holds
 *   - revenue split (gross / TicketHUB commission / organizer payout)
 *   - daily sales chart
 *   - payment funnel (pending / paid / failed / expired / cancelled / refunded)
 *   - attendance (checked in, inside now, no-shows) + scan log
 *   - e-ticket delivery health (email / SMS)
 *   - every booking, searchable, exportable as CSV (?export=csv)
 *
 * Admins see only events they created; Super Admins see every event.
 * ------------------------------------------------------------
 */
session_start();
require __DIR__ . "/config/database.php";
require __DIR__ . "/config/commission.php";
require __DIR__ . "/config/app.php";
require __DIR__ . "/config/event_status.php";

if (!isset($_SESSION['user_role']) || !in_array($_SESSION['user_role'], ['admin', 'super_admin'], true)) {
    header("Location: login.php");
    exit;
}

$currentUserId = (int)$_SESSION['user_id'];
$isSuperAdmin  = ($_SESSION['user_role'] === 'super_admin');
$eventId       = (int)($_GET['id'] ?? 0);

$stmt = $conn->prepare(
    "SELECT e.*, u.name AS creator_name, u.email AS creator_email, u.role AS creator_role
     FROM events e LEFT JOIN users u ON e.created_by = u.id
     WHERE e.id = ? LIMIT 1"
);
$stmt->bind_param("i", $eventId);
$stmt->execute();
$event = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$event) {
    header("Location: manage_events.php?error=" . urlencode("Event not found."));
    exit;
}
// Regular admins can only see their own events
if (!$isSuperAdmin && (int)$event['created_by'] !== $currentUserId) {
    header("Location: manage_events.php?error=" . urlencode("Access denied: You can only view details of events that you created."));
    exit;
}

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

// ---- Bookings (with customer + who checked them in) ----
$stmt = $conn->prepare(
    "SELECT b.*, u.name AS customer_name, u.email AS customer_email, c.name AS checked_in_by_name
     FROM bookings b
     LEFT JOIN users u ON b.user_id = u.id
     LEFT JOIN users c ON b.checked_in_by = c.id
     WHERE b.event_id = ?
     ORDER BY b.created_at DESC"
);
$stmt->bind_param("i", $eventId);
$stmt->execute();
$bookings = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// ---- CSV export of the bookings list ----
if (($_GET['export'] ?? '') === 'csv') {
    $slug = trim(preg_replace('/[^a-z0-9]+/', '-', strtolower($event['title'])), '-') ?: 'event';
    header('Content-Type: text/csv; charset=utf-8');
    header("Content-Disposition: attachment; filename=\"{$slug}-bookings-" . date('Y-m-d') . ".csv\"");
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF"); // UTF-8 BOM so Excel shows names correctly
    fputcsv($out, ['Booking ref', 'Customer', 'Email', 'Phone', 'Quantity', 'Total (LKR)', 'Commission (LKR)',
                   'Organizer payout (LKR)', 'Status', 'Booked at', 'Checked in at', 'Inside now', 'Email sent', 'SMS sent', 'Delivery error']);
    foreach ($bookings as $b) {
        fputcsv($out, [
            $b['booking_ref'], $b['customer_name'], $b['customer_email'], phone_display($b['phone']),
            $b['quantity'], $b['total_price'], $b['commission_amount'],
            number_format((float)$b['total_price'] - (float)$b['commission_amount'], 2, '.', ''),
            $b['status'], $b['created_at'], $b['checked_in_at'], $b['is_inside'] ? 'yes' : 'no',
            $b['email_sent_at'], $b['sms_sent_at'], $b['notify_error'],
        ]);
    }
    fclose($out);
    exit;
}

// ---- Roll up bookings ----
$stats = [
    'confirmed_bookings' => 0, 'cancelled_bookings' => 0,
    'tickets' => 0, 'cancelled_tickets' => 0,
    'gross' => 0.0, 'commission' => 0.0, 'points' => 0,
    'checked_in_bookings' => 0, 'checked_in_tickets' => 0,
    'inside_bookings' => 0, 'inside_tickets' => 0,
    'email_sent' => 0, 'sms_sent' => 0, 'sms_expected' => 0, 'notify_errors' => 0,
];
$customers = [];
$salesByDay = [];
foreach ($bookings as $b) {
    $qty = (int)$b['quantity'];
    if ($b['status'] !== 'confirmed') {
        $stats['cancelled_bookings']++;
        $stats['cancelled_tickets'] += $qty;
        continue;
    }
    $stats['confirmed_bookings']++;
    $stats['tickets']    += $qty;
    $stats['gross']      += (float)$b['total_price'];
    $stats['commission'] += (float)$b['commission_amount'];
    $stats['points']     += (int)$b['points_earned'];
    if ($b['checked_in_at']) { $stats['checked_in_bookings']++; $stats['checked_in_tickets'] += $qty; }
    if ($b['is_inside'])     { $stats['inside_bookings']++;     $stats['inside_tickets']     += $qty; }
    if ($b['email_sent_at']) { $stats['email_sent']++; }
    if ($b['phone'])         { $stats['sms_expected']++; }
    if ($b['sms_sent_at'])   { $stats['sms_sent']++; }
    if ($b['notify_error'])  { $stats['notify_errors']++; }
    $customers[(int)$b['user_id']] = true;

    $day = date('Y-m-d', strtotime($b['created_at']));
    $salesByDay[$day] = ($salesByDay[$day] ?? ['tickets' => 0, 'gross' => 0.0]);
    $salesByDay[$day]['tickets'] += $qty;
    $salesByDay[$day]['gross']   += (float)$b['total_price'];
}
$stats['payout']         = $stats['gross'] - $stats['commission'];
$stats['unique_buyers']  = count($customers);
$stats['avg_order']      = $stats['confirmed_bookings'] ? $stats['gross'] / $stats['confirmed_bookings'] : 0;
$stats['avg_tickets']    = $stats['confirmed_bookings'] ? $stats['tickets'] / $stats['confirmed_bookings'] : 0;

// ---- Daily sales: continuous range from first sale to last sale (max 45 days shown) ----
$chartDays = [];
if ($salesByDay) {
    ksort($salesByDay);
    $first = new DateTime(array_key_first($salesByDay));
    $last  = new DateTime(array_key_last($salesByDay));
    if ($first->diff($last)->days > 44) {
        $first = (clone $last)->modify('-44 days');
    }
    for ($d = clone $first; $d <= $last; $d->modify('+1 day')) {
        $key = $d->format('Y-m-d');
        $chartDays[$key] = $salesByDay[$key] ?? ['tickets' => 0, 'gross' => 0.0];
    }
}
$chartMax = $chartDays ? max(array_column($chartDays, 'tickets')) : 0;
$bestDay  = null;
foreach ($salesByDay as $day => $v) {
    if (!$bestDay || $v['tickets'] > $salesByDay[$bestDay]['tickets']) { $bestDay = $day; }
}

// ---- Payments funnel ----
$paymentStatuses = ['paid' => 0, 'pending' => 0, 'failed' => 0, 'expired' => 0, 'cancelled' => 0, 'refunded' => 0];
$paymentAmounts  = $paymentStatuses;
$heldNow = 0;
$stmt = $conn->prepare(
    "SELECT status, COUNT(*) AS n, COALESCE(SUM(amount_total), 0) AS amount,
            COALESCE(SUM(CASE WHEN status = 'pending' AND expires_at > NOW() THEN quantity ELSE 0 END), 0) AS held
     FROM payments WHERE event_id = ? GROUP BY status"
);
$stmt->bind_param("i", $eventId);
$stmt->execute();
foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $p) {
    $paymentStatuses[$p['status']] = (int)$p['n'];
    $paymentAmounts[$p['status']]  = (float)$p['amount'];
    $heldNow += (int)$p['held'];
}
$stmt->close();
$paymentsTotal  = array_sum($paymentStatuses);
$conversionRate = $paymentsTotal ? $paymentStatuses['paid'] / $paymentsTotal * 100 : 0;

// Recent failed / cancelled payments, so admins can see why checkouts didn't go through
$stmt = $conn->prepare(
    "SELECT p.status, p.quantity, p.amount_total, p.failure_reason, p.created_at, u.name AS customer_name
     FROM payments p LEFT JOIN users u ON p.user_id = u.id
     WHERE p.event_id = ? AND p.status IN ('failed','expired','cancelled','refunded')
     ORDER BY p.created_at DESC LIMIT 8"
);
$stmt->bind_param("i", $eventId);
$stmt->execute();
$lostPayments = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// ---- Scans (table may be missing on older installs) ----
$scanCounts = [];
$scanLog    = [];
$scansTotal = 0;
$scansRefused = 0;
try {
    $stmt = $conn->prepare("SELECT result, accepted, COUNT(*) AS n FROM ticket_scans WHERE event_id = ? GROUP BY result, accepted");
    $stmt->bind_param("i", $eventId);
    $stmt->execute();
    foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $s) {
        $scanCounts[$s['result']] = ($scanCounts[$s['result']] ?? 0) + (int)$s['n'];
        $scansTotal += (int)$s['n'];
        if (!(int)$s['accepted']) { $scansRefused += (int)$s['n']; }
    }
    $stmt->close();

    $stmt = $conn->prepare(
        "SELECT t.direction, t.accepted, t.result, t.method, t.scanned_at,
                b.booking_ref, b.quantity, u.name AS holder, s.name AS scanned_by
         FROM ticket_scans t
         JOIN bookings b ON t.booking_id = b.id
         LEFT JOIN users u ON b.user_id = u.id
         LEFT JOIN users s ON t.scanned_by = s.id
         WHERE t.event_id = ?
         ORDER BY t.scanned_at DESC, t.id DESC LIMIT 25"
    );
    $stmt->bind_param("i", $eventId);
    $stmt->execute();
    $scanLog = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
} catch (mysqli_sql_exception $e) {
    error_log("event_details scans failed (run files/migrate_scan_tickets.sql?): " . $e->getMessage());
}

// ---- Status history + refund requests (tables may be missing on older installs) ----
$statusHistory = [];
$refundSummary = ['pending' => 0, 'refunded' => 0, 'rejected' => 0, 'withdrawn' => 0, 'refunded_amount' => 0.0, 'pending_amount' => 0.0];
try {
    $stmt = $conn->prepare(
        "SELECT h.*, u.name AS changed_by_name FROM event_status_history h
         LEFT JOIN users u ON u.id = h.changed_by
         WHERE h.event_id = ? ORDER BY h.changed_at DESC, h.id DESC LIMIT 20"
    );
    $stmt->bind_param("i", $eventId);
    $stmt->execute();
    $statusHistory = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    $stmt = $conn->prepare(
        "SELECT status, COUNT(*) AS n, COALESCE(SUM(refund_amount), 0) AS refunded, COALESCE(SUM(amount_requested), 0) AS requested
         FROM refund_requests WHERE event_id = ? GROUP BY status"
    );
    $stmt->bind_param("i", $eventId);
    $stmt->execute();
    foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $r) {
        $refundSummary[$r['status']] = (int)$r['n'];
        if ($r['status'] === 'refunded') { $refundSummary['refunded_amount'] = (float)$r['refunded']; }
        if ($r['status'] === 'pending')  { $refundSummary['pending_amount']  = (float)$r['requested']; }
    }
    $stmt->close();
} catch (mysqli_sql_exception $e) {
    error_log("event_details status/refunds failed (run files/setup_platform.php?): " . $e->getMessage());
}
$lifecycle = event_status_meta($event);

// ---- Derived numbers ----
$capacity       = (int)$event['total_tickets'];
$soldPct        = $capacity > 0 ? min(100, $stats['tickets'] / $capacity * 100) : 0;
$heldPct        = $capacity > 0 ? min(100 - $soldPct, $heldNow / $capacity * 100) : 0;
$available      = max(0, $capacity - (int)$event['tickets_sold'] - $heldNow);
$commPerTicket  = commission_per_ticket((float)$event['ticket_price'], $event['commission_type'], (float)$event['commission_value']);
$maxRevenue     = (float)$event['ticket_price'] * $capacity;
$checkInPct     = $stats['tickets'] ? $stats['checked_in_tickets'] / $stats['tickets'] * 100 : 0;
$commissionShare = $stats['gross'] > 0 ? $stats['commission'] / $stats['gross'] * 100 : 0;

$eventTs   = strtotime($event['event_date']);
$daysAway  = (int)floor((strtotime(date('Y-m-d', $eventTs)) - strtotime(date('Y-m-d'))) / 86400);
if ($daysAway > 1)       { $statusKey = 'upcoming'; $statusText = "In $daysAway days"; }
elseif ($daysAway === 1) { $statusKey = 'upcoming'; $statusText = 'Tomorrow'; }
elseif ($daysAway === 0) { $statusKey = 'today';    $statusText = $eventTs > time() ? 'Today' : 'Happening today'; }
else                     { $statusKey = 'past';     $statusText = 'Ended ' . abs($daysAway) . ' day' . (abs($daysAway) === 1 ? '' : 's') . ' ago'; }
if ($capacity > 0 && $available === 0 && $statusKey !== 'past') { $soldOut = true; } else { $soldOut = false; }

// Discrepancy between events.tickets_sold (counter) and confirmed bookings — worth flagging
$counterMismatch = (int)$event['tickets_sold'] !== $stats['tickets'];

function lkr(float $n): string { return 'LKR ' . number_format($n, 2); }
function when(?string $ts): string { return $ts ? date('M d, Y · h:i A', strtotime($ts)) : '—'; }
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<?php include __DIR__ . "/pwa_head.php"; ?>
<title><?php echo htmlspecialchars($event['title']); ?> - Event Details - TicketHub</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Sora:wght@600;700;800&family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
<style>
:root {
    --bg: #14121A;
    --surface: rgba(255,255,255,0.04);
    --surface-hover: rgba(255,255,255,0.07);
    --border: rgba(255,255,255,0.08);
    --text: #F1EEF7;
    --text-dim: #A9A3B8;
    --text-muted: #7D7790;
    --violet: #9D7CF9;
    --rose: #F0729C;
    --blue: #6CA0F2;
    --red: #EF6B6B;
    --amber: #F2C572;
    --green: #6FCF97;
    --font-display: 'Sora', 'Segoe UI', sans-serif;
    --font-body: 'Inter', 'Segoe UI', sans-serif;
}
* { box-sizing: border-box; }
body { margin: 0; font-family: var(--font-body); background: var(--bg); color: var(--text); }
a { color: inherit; }

.container { max-width: 1180px; margin: 0 auto; padding: 36px 48px 100px; }

.crumbs { font-size: 13px; color: var(--text-dim); margin-bottom: 18px; }
.crumbs a { color: var(--text-dim); text-decoration: none; }
.crumbs a:hover { color: var(--text); }

/* ---- Hero ---- */
.hero {
    display: grid; grid-template-columns: 340px 1fr; gap: 28px;
    background: var(--surface); border: 1px solid var(--border); border-radius: 20px;
    padding: 22px; margin-bottom: 22px;
    animation: rise 0.5s ease-out both;
}
.hero .photo { width: 100%; height: 100%; min-height: 220px; max-height: 280px; object-fit: cover; border-radius: 14px; display: block; }
.hero .photo-fallback {
    min-height: 220px; border-radius: 14px; display: flex; align-items: center; justify-content: center; font-size: 54px;
    background: linear-gradient(135deg, rgba(157,124,249,0.18), rgba(240,114,156,0.18));
}
.hero h1 { font-family: var(--font-display); font-size: 28px; margin: 10px 0 12px; line-height: 1.2; }
.tags { display: flex; gap: 8px; flex-wrap: wrap; }
.tag {
    font-size: 11px; font-weight: 700; letter-spacing: 0.4px; text-transform: uppercase;
    padding: 4px 10px; border-radius: 100px; border: 1px solid var(--border); color: var(--text-dim);
}
.tag.cat      { color: #CBB8FC; background: rgba(157,124,249,0.12); border-color: rgba(157,124,249,0.3); }
.tag.upcoming { color: var(--blue);  background: rgba(108,160,242,0.12); border-color: rgba(108,160,242,0.3); }
.tag.today    { color: var(--green); background: rgba(111,207,151,0.12); border-color: rgba(111,207,151,0.35); }
.tag.past     { color: var(--text-dim); background: rgba(255,255,255,0.05); }
.tag.soldout  { color: var(--rose);  background: rgba(240,114,156,0.12); border-color: rgba(240,114,156,0.35); }
.tag.life-green { color: var(--green); background: rgba(111,207,151,0.12); border-color: rgba(111,207,151,0.35); }
.tag.life-amber { color: var(--amber); background: rgba(242,197,114,0.12); border-color: rgba(242,197,114,0.35); }
.tag.life-blue  { color: var(--blue);  background: rgba(108,160,242,0.12); border-color: rgba(108,160,242,0.35); }
.tag.life-red   { color: #FFA5A5;      background: rgba(239,107,107,0.12); border-color: rgba(239,107,107,0.35); }
.tag.id       { font-family: ui-monospace, Consolas, monospace; text-transform: none; letter-spacing: 0; }

.facts { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 10px 24px; margin-bottom: 14px; }
.fact { font-size: 13.5px; }
.fact .k { display: block; color: var(--text-muted); font-size: 11.5px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.4px; margin-bottom: 2px; }
.fact a { color: var(--text); text-decoration-color: rgba(255,255,255,0.25); }
.desc { color: var(--text-dim); font-size: 14px; line-height: 1.6; margin: 0 0 16px; white-space: pre-line; max-height: 7.5em; overflow: auto; }

.hero-actions { display: flex; gap: 10px; flex-wrap: wrap; }
.btn {
    display: inline-flex; align-items: center; gap: 6px;
    padding: 9px 16px; border-radius: 100px; font-weight: 600; font-size: 13px; text-decoration: none;
    border: 1px solid var(--border); color: var(--text); background: rgba(255,255,255,0.05);
    transition: transform 0.2s ease, background 0.2s ease, border-color 0.2s ease;
}
.btn:hover { transform: translateY(-1px); background: rgba(255,255,255,0.1); border-color: rgba(255,255,255,0.2); }
.btn.primary { background: var(--text); color: var(--bg); border-color: var(--text); }
.btn.primary:hover { background: var(--violet); color: #fff; border-color: var(--violet); }
.btn.blue { color: var(--blue); border-color: rgba(108,160,242,0.35); background: rgba(108,160,242,0.1); }

/* ---- KPI tiles ---- */
.kpis { display: grid; grid-template-columns: repeat(4, 1fr); gap: 16px; margin-bottom: 22px; animation: rise 0.5s 0.05s ease-out both; }
.kpi { background: var(--surface); border: 1px solid var(--border); border-radius: 16px; padding: 18px 20px; }
.kpi .label { font-size: 12px; font-weight: 600; letter-spacing: 0.5px; text-transform: uppercase; color: var(--text-dim); margin-bottom: 8px; }
.kpi .value { font-family: var(--font-display); font-size: 24px; font-weight: 700; }
.kpi .value small { font-size: 12px; font-weight: 600; color: var(--text-dim); margin-left: 3px; }
.kpi .value small.pre { margin: 0 4px 0 0; }
.kpi .hint { font-size: 12px; color: var(--text-dim); margin-top: 6px; }
.kpi.gold  { background: linear-gradient(135deg, rgba(242,197,114,0.13), rgba(20,18,26,0.4)); border-color: rgba(242,197,114,0.32); }
.kpi.gold .label, .kpi.gold .value { color: var(--amber); }
.kpi.green { background: linear-gradient(135deg, rgba(111,207,151,0.12), rgba(20,18,26,0.4)); border-color: rgba(111,207,151,0.3); }
.kpi.green .label, .kpi.green .value { color: var(--green); }

.meter { display: flex; height: 8px; border-radius: 100px; overflow: hidden; background: rgba(255,255,255,0.07); margin-top: 10px; gap: 2px; }
.meter span { display: block; height: 100%; }
.meter .sold { background: var(--violet); border-radius: 100px; }
.meter .held { background: rgba(157,124,249,0.4); border-radius: 100px; }
.meter .org  { background: var(--green); border-radius: 100px; }
.meter .th   { background: var(--amber); border-radius: 100px; }
.meter .in   { background: var(--green); border-radius: 100px; }

/* ---- Panels ---- */
.grid-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 22px; margin-bottom: 22px; }
.grid-3 { display: grid; grid-template-columns: 1.4fr 1fr; gap: 22px; margin-bottom: 22px; }
.panel {
    background: var(--surface); border: 1px solid var(--border); border-radius: 18px; padding: 20px 22px;
    min-width: 0; animation: rise 0.5s 0.1s ease-out both;
}
.panel h3 { font-family: var(--font-display); font-size: 16px; margin: 0 0 4px; }
.panel .sub { font-size: 12.5px; color: var(--text-dim); margin: 0 0 16px; }
.panel-head { display: flex; justify-content: space-between; align-items: flex-start; gap: 12px; flex-wrap: wrap; margin-bottom: 4px; }

.rows .row { display: flex; justify-content: space-between; align-items: center; gap: 12px; padding: 8px 0; border-bottom: 1px solid rgba(255,255,255,0.05); font-size: 13.5px; color: var(--text-dim); }
.rows .row:last-child { border-bottom: none; }
.rows .row b { color: var(--text); font-weight: 600; text-align: right; }
.rows .row b.gold { color: var(--amber); }
.rows .row b.green { color: var(--green); }
.rows .row b.red { color: #FFA5A5; }

/* Chart */
.chart { position: relative; }
.chart svg { width: 100%; height: 200px; display: block; overflow: visible; }
.chart .grid line { stroke: rgba(255,255,255,0.07); stroke-width: 1; }
.chart .axis text { fill: var(--text-muted); font-size: 10.5px; font-family: var(--font-body); }
.chart .bar { fill: var(--violet); }
.chart .hit { fill: transparent; cursor: pointer; }
.chart .hit:hover + .bar, .chart g.col:hover .bar { fill: #B8A0FB; }
.tooltip {
    position: absolute; pointer-events: none; transform: translate(-50%, -110%);
    background: #221E2C; border: 1px solid rgba(255,255,255,0.14); border-radius: 10px;
    padding: 8px 11px; font-size: 12px; white-space: nowrap; box-shadow: 0 8px 24px rgba(0,0,0,0.45);
    opacity: 0; transition: opacity 0.12s ease;
}
.tooltip.show { opacity: 1; }
.tooltip b { display: block; font-size: 13px; margin-bottom: 2px; }
.tooltip span { color: var(--text-dim); }
.chart-empty { height: 200px; display: flex; align-items: center; justify-content: center; color: var(--text-dim); font-size: 13px; border: 1px dashed var(--border); border-radius: 12px; }

/* Funnel */
.funnel .f-row { display: grid; grid-template-columns: 110px 1fr 70px; align-items: center; gap: 12px; padding: 6px 0; font-size: 13px; }
.funnel .f-row .name { color: var(--text-dim); display: flex; align-items: center; gap: 6px; }
.funnel .f-row .track { height: 8px; border-radius: 100px; background: rgba(255,255,255,0.06); overflow: hidden; }
.funnel .f-row .fill { height: 100%; border-radius: 100px; background: rgba(255,255,255,0.35); }
.funnel .f-row .fill.paid { background: var(--green); }
.funnel .f-row .fill.pending { background: var(--blue); }
.funnel .f-row .fill.failed, .funnel .f-row .fill.refunded { background: var(--red); }
.funnel .f-row .n { text-align: right; font-weight: 600; }

/* Tables */
.table-wrap { overflow-x: auto; margin: 0 -22px; padding: 0 22px; }
table { width: 100%; border-collapse: collapse; font-size: 13px; }
th { text-align: left; font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; color: var(--text-muted); padding: 10px 10px; border-bottom: 1px solid var(--border); white-space: nowrap; }
td { padding: 11px 10px; border-bottom: 1px solid rgba(255,255,255,0.05); vertical-align: top; }
tr:last-child td { border-bottom: none; }
tbody tr:hover td { background: rgba(255,255,255,0.025); }
td.num, th.num { text-align: right; white-space: nowrap; }
td .muted { color: var(--text-dim); font-size: 12px; }
.mono { font-family: ui-monospace, Consolas, monospace; font-size: 12.5px; }
.pill {
    display: inline-flex; align-items: center; gap: 4px; font-size: 11.5px; font-weight: 600;
    padding: 3px 9px; border-radius: 100px; white-space: nowrap; border: 1px solid transparent;
}
.pill.ok   { color: var(--green); background: rgba(111,207,151,0.12); border-color: rgba(111,207,151,0.3); }
.pill.bad  { color: #FFA5A5; background: rgba(239,107,107,0.12); border-color: rgba(239,107,107,0.3); }
.pill.info { color: var(--blue); background: rgba(108,160,242,0.12); border-color: rgba(108,160,242,0.3); }
.pill.dim  { color: var(--text-dim); background: rgba(255,255,255,0.05); border-color: var(--border); }
.pill.warn { color: var(--amber); background: rgba(242,197,114,0.12); border-color: rgba(242,197,114,0.3); }
.deliv { display: flex; gap: 6px; flex-wrap: wrap; }
td a.link { color: var(--violet); text-decoration: none; font-weight: 600; font-size: 12.5px; white-space: nowrap; }
td a.link:hover { text-decoration: underline; }

.toolbar { display: flex; gap: 10px; flex-wrap: wrap; align-items: center; }
.search {
    background: rgba(255,255,255,0.05); border: 1px solid var(--border); border-radius: 10px;
    color: var(--text); font-family: var(--font-body); font-size: 13px; padding: 9px 12px; width: 240px; outline: none;
}
.search:focus { border-color: var(--violet); box-shadow: 0 0 0 3px rgba(157,124,249,0.15); }
.seg { display: inline-flex; border: 1px solid var(--border); border-radius: 10px; overflow: hidden; }
.seg button {
    background: transparent; border: none; color: var(--text-dim); font-family: var(--font-body);
    font-size: 12.5px; font-weight: 600; padding: 8px 12px; cursor: pointer;
}
.seg button.active { background: rgba(157,124,249,0.2); color: var(--text); }
.empty { text-align: center; padding: 34px 10px; color: var(--text-dim); font-size: 13.5px; }

.alert { padding: 12px 16px; border-radius: 12px; margin-bottom: 22px; font-size: 13.5px; }
.alert.warn { background: rgba(242,197,114,0.1); border: 1px solid rgba(242,197,114,0.35); color: var(--amber); }

.footer { text-align: center; padding: 28px; border-top: 1px solid var(--border); color: var(--text-dim); font-size: 13px; }

@keyframes rise { from { opacity: 0; transform: translateY(14px); } to { opacity: 1; transform: translateY(0); } }
@media (prefers-reduced-motion: reduce) { * { animation: none !important; transition: none !important; } }
@media (max-width: 980px) {
    .hero { grid-template-columns: 1fr; }
    .kpis { grid-template-columns: repeat(2, 1fr); }
    .grid-2, .grid-3 { grid-template-columns: 1fr; }
}
@media (max-width: 640px) {
    .container { padding: 24px 16px 70px; }
    .kpis { grid-template-columns: 1fr; }
    .facts { grid-template-columns: 1fr; }
    .search { width: 100%; }
    .table-wrap { margin: 0 -16px; padding: 0 16px; }
}
</style>
</head>
<body>
<?php require __DIR__ . "/nav.php"; ?>

<div class="container">
    <div class="crumbs"><a href="manage_events.php">← Manage Events</a> / Event details</div>

    <!-- ===== Hero ===== -->
    <section class="hero">
        <?php if (!empty($event['photo']) && file_exists(__DIR__ . "/eventphotos/" . $event['photo'])): ?>
            <img class="photo" src="eventphotos/<?php echo htmlspecialchars($event['photo']); ?>" alt="<?php echo htmlspecialchars($event['title']); ?>">
        <?php else: ?>
            <div class="photo-fallback">🎫</div>
        <?php endif; ?>

        <div>
            <div class="tags">
                <span class="tag cat"><?php echo htmlspecialchars($event['category']); ?></span>
                <span class="tag life-<?php echo $lifecycle['tone']; ?>" title="<?php echo htmlspecialchars($lifecycle['hint']); ?>"><?php echo $lifecycle['icon'] . ' ' . $lifecycle['label']; ?></span>
                <span class="tag <?php echo $statusKey; ?>"><?php echo $statusKey === 'past' ? '⏹' : '●'; ?> <?php echo htmlspecialchars($statusText); ?></span>
                <?php if ($soldOut): ?><span class="tag soldout">🔥 Sold out</span><?php endif; ?>
                <span class="tag id">#<?php echo (int)$event['id']; ?></span>
            </div>
            <h1><?php echo htmlspecialchars($event['title']); ?></h1>

            <div class="facts">
                <div class="fact"><span class="k">🗓 Date & time</span><?php echo date("l, M d, Y · h:i A", $eventTs); ?></div>
                <div class="fact"><span class="k">📍 Location</span>
                    <a href="https://www.google.com/maps/search/?api=1&query=<?php echo urlencode($event['location']); ?>" target="_blank" rel="noopener noreferrer"><?php echo htmlspecialchars($event['location']); ?></a>
                </div>
                <div class="fact"><span class="k">🏢 Organizer</span>
                    <?php echo htmlspecialchars($event['creator_name'] ?? 'Unknown'); ?>
                    <?php if ($event['creator_role']): ?><span class="muted" style="color: var(--text-dim);">(<?php echo $event['creator_role'] === 'super_admin' ? 'Super Admin' : 'Admin'; ?>)</span><?php endif; ?>
                    <?php if ($isSuperAdmin && !empty($event['creator_email'])): ?><br><span style="color: var(--text-dim); font-size: 12.5px;"><?php echo htmlspecialchars($event['creator_email']); ?></span><?php endif; ?>
                </div>
                <div class="fact"><span class="k">🕓 Created</span><?php echo when($event['created_at']); ?></div>
                <div class="fact"><span class="k">🎟 Ticket price</span><?php echo lkr((float)$event['ticket_price']); ?></div>
                <div class="fact"><span class="k">👑 TicketHUB commission</span><?php echo htmlspecialchars(commission_label($event['commission_type'], (float)$event['commission_value'])); ?> · <?php echo lkr($commPerTicket); ?> / ticket</div>
            </div>

            <?php if (!empty($event['status_note']) || !empty($event['original_event_date'])): ?>
                <p class="desc" style="color: var(--amber);">
                    <?php if (!empty($event['original_event_date'])): ?>📅 Originally <?php echo date("M d, Y · h:i A", strtotime($event['original_event_date'])); ?><?php echo !empty($event['status_note']) ? ' · ' : ''; ?><?php endif; ?>
                    <?php if (!empty($event['status_note'])): ?>“<?php echo htmlspecialchars($event['status_note']); ?>”<?php endif; ?>
                </p>
            <?php endif; ?>

            <?php if (trim((string)$event['description']) !== ''): ?>
                <p class="desc"><?php echo htmlspecialchars($event['description']); ?></p>
            <?php endif; ?>

            <div class="hero-actions">
                <a class="btn primary" href="edit_event.php?id=<?php echo (int)$event['id']; ?>">✎ Edit event</a>
                <a class="btn" href="scan_tickets.php">📷 Scan tickets</a>
                <a class="btn" href="tickets.php?event_id=<?php echo (int)$event['id']; ?>" target="_blank" rel="noopener">👁 Customer view</a>
                <a class="btn blue" href="event_details.php?id=<?php echo (int)$event['id']; ?>&export=csv">⬇ Export bookings (CSV)</a>
            </div>
        </div>
    </section>

    <?php if ($counterMismatch): ?>
        <div class="alert warn">⚠ The event's sold counter says <b><?php echo (int)$event['tickets_sold']; ?></b> tickets, but confirmed bookings add up to <b><?php echo $stats['tickets']; ?></b>. Figures on this page use confirmed bookings.</div>
    <?php endif; ?>

    <!-- ===== KPI tiles ===== -->
    <section class="kpis">
        <div class="kpi">
            <div class="label">🎟️ Tickets sold</div>
            <div class="value"><?php echo number_format($stats['tickets']); ?><small>/ <?php echo number_format($capacity); ?></small></div>
            <div class="meter" title="Sold <?php echo round($soldPct, 1); ?>% · on hold <?php echo round($heldPct, 1); ?>%">
                <span class="sold" style="width: <?php echo $soldPct; ?>%;"></span>
                <?php if ($heldPct > 0): ?><span class="held" style="width: <?php echo $heldPct; ?>%;"></span><?php endif; ?>
            </div>
            <div class="hint"><?php echo number_format($soldPct, 1); ?>% sold · <?php echo number_format($available); ?> available<?php echo $heldNow ? " · $heldNow on hold" : ''; ?></div>
        </div>
        <div class="kpi">
            <div class="label">💳 Gross sales</div>
            <div class="value"><small class="pre">LKR</small><?php echo number_format($stats['gross'], 2); ?></div>
            <div class="meter" title="Organizer vs TicketHUB share">
                <span class="org" style="width: <?php echo $stats['gross'] > 0 ? 100 - $commissionShare : 0; ?>%;"></span>
                <span class="th" style="width: <?php echo $commissionShare; ?>%;"></span>
            </div>
            <div class="hint"><?php echo $maxRevenue > 0 ? number_format($stats['gross'] / $maxRevenue * 100, 1) . '% of ' . lkr($maxRevenue) . ' potential' : 'Free event'; ?></div>
        </div>
        <div class="kpi gold">
            <div class="label">👑 TicketHUB commission</div>
            <div class="value"><small class="pre">LKR</small><?php echo number_format($stats['commission'], 2); ?></div>
            <div class="hint"><?php echo number_format($commissionShare, 1); ?>% of gross sales</div>
        </div>
        <div class="kpi green">
            <div class="label">🏢 Organizer payout</div>
            <div class="value"><small class="pre">LKR</small><?php echo number_format($stats['payout'], 2); ?></div>
            <div class="hint">Gross sales minus commission</div>
        </div>
        <div class="kpi">
            <div class="label">🧾 Bookings</div>
            <div class="value"><?php echo number_format($stats['confirmed_bookings']); ?></div>
            <div class="hint"><?php echo $stats['unique_buyers']; ?> unique buyer(s)<?php echo $stats['cancelled_bookings'] ? " · {$stats['cancelled_bookings']} cancelled" : ''; ?></div>
        </div>
        <div class="kpi">
            <div class="label">🛒 Average order</div>
            <div class="value"><small class="pre">LKR</small><?php echo number_format($stats['avg_order'], 2); ?></div>
            <div class="hint"><?php echo number_format($stats['avg_tickets'], 1); ?> ticket(s) per booking</div>
        </div>
        <div class="kpi">
            <div class="label">✅ Checked in</div>
            <div class="value"><?php echo number_format($stats['checked_in_tickets']); ?><small>/ <?php echo number_format($stats['tickets']); ?></small></div>
            <div class="meter"><span class="in" style="width: <?php echo $checkInPct; ?>%;"></span></div>
            <div class="hint"><?php echo number_format($checkInPct, 1); ?>% attendance · <?php echo $stats['inside_tickets']; ?> inside now</div>
        </div>
        <div class="kpi">
            <div class="label">💸 Checkout conversion</div>
            <div class="value"><?php echo number_format($conversionRate, 0); ?><small>%</small></div>
            <div class="hint"><?php echo $paymentStatuses['paid']; ?> paid of <?php echo $paymentsTotal; ?> checkout attempt(s)</div>
        </div>
    </section>

    <!-- ===== Sales chart + pricing ===== -->
    <div class="grid-3">
        <section class="panel">
            <h3>📈 Tickets sold per day</h3>
            <p class="sub">
                <?php if ($bestDay): ?>
                    Best day: <?php echo date('M d, Y', strtotime($bestDay)); ?> with <?php echo $salesByDay[$bestDay]['tickets']; ?> ticket(s)<?php echo count($salesByDay) > 45 ? ' · showing the last 45 days of sales' : ''; ?>
                <?php else: ?>
                    Confirmed bookings by the day they were made.
                <?php endif; ?>
            </p>
            <?php if (!$chartDays): ?>
                <div class="chart-empty">No sales yet.</div>
            <?php else:
                $n = count($chartDays);
                $W = 640; $H = 200; $padL = 30; $padB = 22; $padT = 8;
                $plotW = $W - $padL; $plotH = $H - $padB - $padT;
                $step = $plotW / $n;
                $barW = max(3, min(28, $step - 2));
                $yMax = max(1, $chartMax);
                // nice integer ticks
                $tickStep = max(1, (int)ceil($yMax / 4));
                $yTop = $tickStep * (int)ceil($yMax / $tickStep);
                $labelEvery = max(1, (int)ceil($n / 8));
            ?>
            <div class="chart" id="salesChart">
                <svg viewBox="0 0 <?php echo $W; ?> <?php echo $H; ?>" preserveAspectRatio="none" role="img" aria-label="Tickets sold per day">
                    <g class="grid axis">
                        <?php for ($t = 0; $t <= $yTop; $t += $tickStep):
                            $y = $padT + $plotH - ($t / $yTop) * $plotH; ?>
                            <line x1="<?php echo $padL; ?>" x2="<?php echo $W; ?>" y1="<?php echo $y; ?>" y2="<?php echo $y; ?>"></line>
                            <text x="<?php echo $padL - 8; ?>" y="<?php echo $y + 3.5; ?>" text-anchor="end"><?php echo $t; ?></text>
                        <?php endfor; ?>
                    </g>
                    <?php $i = 0; foreach ($chartDays as $day => $v):
                        $cx = $padL + $step * $i + $step / 2;
                        $h  = $v['tickets'] / $yTop * $plotH;
                        $y  = $padT + $plotH - $h;
                        $r  = min(4, $barW / 2, $h);
                    ?>
                    <g class="col" data-day="<?php echo date('D, M d', strtotime($day)); ?>" data-tickets="<?php echo $v['tickets']; ?>" data-gross="<?php echo number_format($v['gross'], 2); ?>" data-x="<?php echo $cx; ?>" data-y="<?php echo $y; ?>">
                        <rect class="hit" x="<?php echo $padL + $step * $i; ?>" y="<?php echo $padT; ?>" width="<?php echo $step; ?>" height="<?php echo $plotH; ?>"></rect>
                        <?php if ($h > 0): ?>
                            <path class="bar" d="M<?php echo $cx - $barW / 2; ?>,<?php echo $padT + $plotH; ?> V<?php echo $y + $r; ?> Q<?php echo $cx - $barW / 2; ?>,<?php echo $y; ?> <?php echo $cx - $barW / 2 + $r; ?>,<?php echo $y; ?> H<?php echo $cx + $barW / 2 - $r; ?> Q<?php echo $cx + $barW / 2; ?>,<?php echo $y; ?> <?php echo $cx + $barW / 2; ?>,<?php echo $y + $r; ?> V<?php echo $padT + $plotH; ?> Z"></path>
                        <?php endif; ?>
                    </g>
                    <?php if ($i % $labelEvery === 0): ?>
                        <text class="axis" x="<?php echo $cx; ?>" y="<?php echo $H - 5; ?>" text-anchor="middle" style="fill: var(--text-muted); font-size: 10.5px;"><?php echo date('M j', strtotime($day)); ?></text>
                    <?php endif; ?>
                    <?php $i++; endforeach; ?>
                    <line x1="<?php echo $padL; ?>" x2="<?php echo $W; ?>" y1="<?php echo $padT + $plotH; ?>" y2="<?php echo $padT + $plotH; ?>" stroke="rgba(255,255,255,0.18)"></line>
                </svg>
                <div class="tooltip" id="chartTip"></div>
            </div>
            <?php endif; ?>
        </section>

        <section class="panel">
            <h3>💰 Pricing & split</h3>
            <p class="sub">What each ticket is worth, and where the money goes.</p>
            <div class="rows">
                <div class="row"><span>Ticket price</span><b><?php echo lkr((float)$event['ticket_price']); ?></b></div>
                <div class="row"><span>Commission rate</span><b class="gold"><?php echo htmlspecialchars(commission_label($event['commission_type'], (float)$event['commission_value'])); ?></b></div>
                <div class="row"><span>TicketHUB / ticket</span><b class="gold"><?php echo lkr($commPerTicket); ?></b></div>
                <div class="row"><span>Organizer / ticket</span><b class="green"><?php echo lkr((float)$event['ticket_price'] - $commPerTicket); ?></b></div>
                <div class="row"><span>Potential gross (sell-out)</span><b><?php echo lkr($maxRevenue); ?></b></div>
                <div class="row"><span>Potential commission</span><b class="gold"><?php echo lkr($commPerTicket * $capacity); ?></b></div>
                <div class="row"><span>Loyalty points awarded</span><b><?php echo number_format($stats['points']); ?></b></div>
            </div>
        </section>
    </div>

    <!-- ===== Payments + Attendance ===== -->
    <div class="grid-2">
        <section class="panel">
            <h3>💳 Payments & checkouts</h3>
            <p class="sub">Every Stripe checkout started for this event.<?php echo $heldNow ? " <b style=\"color: var(--blue);\">$heldNow seat(s) held right now</b> by customers on the payment page." : ''; ?></p>
            <?php if ($paymentsTotal === 0): ?>
                <div class="empty">No checkouts yet.</div>
            <?php else: ?>
            <div class="funnel">
                <?php
                $paymentIcons = ['paid' => '✅', 'pending' => '⏳', 'failed' => '❌', 'expired' => '⌛', 'cancelled' => '↩', 'refunded' => '💸'];
                $maxStatus = max($paymentStatuses) ?: 1;
                foreach ($paymentStatuses as $st => $cnt): ?>
                <div class="f-row" title="<?php echo lkr($paymentAmounts[$st]); ?>">
                    <span class="name"><?php echo $paymentIcons[$st]; ?> <?php echo ucfirst($st); ?></span>
                    <span class="track"><span class="fill <?php echo $st; ?>" style="display:block; width: <?php echo $cnt / $maxStatus * 100; ?>%;"></span></span>
                    <span class="n"><?php echo $cnt; ?></span>
                </div>
                <?php endforeach; ?>
            </div>
            <?php if ($lostPayments): ?>
                <div style="margin-top: 16px; font-size: 12px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; color: var(--text-muted);">Recent unsuccessful checkouts</div>
                <div class="rows">
                <?php foreach ($lostPayments as $p): ?>
                    <div class="row">
                        <span><?php echo htmlspecialchars($p['customer_name'] ?? 'Unknown'); ?> · <?php echo (int)$p['quantity']; ?> ticket(s)
                            <br><span style="font-size: 12px; color: var(--text-muted);"><?php echo when($p['created_at']); ?><?php echo $p['failure_reason'] ? ' · ' . htmlspecialchars($p['failure_reason']) : ''; ?></span></span>
                        <span class="pill <?php echo $p['status'] === 'failed' || $p['status'] === 'refunded' ? 'bad' : 'dim'; ?>"><?php echo ucfirst($p['status']); ?></span>
                    </div>
                <?php endforeach; ?>
                </div>
            <?php endif; ?>
            <?php endif; ?>
        </section>

        <section class="panel">
            <h3>🚪 Attendance & delivery</h3>
            <p class="sub">Door activity and whether customers received their e-tickets.</p>
            <div class="rows">
                <div class="row"><span>✅ Checked in (tickets)</span><b class="green"><?php echo $stats['checked_in_tickets']; ?> / <?php echo $stats['tickets']; ?></b></div>
                <div class="row"><span>🏟 Inside right now</span><b><?php echo $stats['inside_tickets']; ?> ticket(s) · <?php echo $stats['inside_bookings']; ?> booking(s)</b></div>
                <div class="row"><span>🕳 Not arrived yet<?php echo $statusKey === 'past' ? ' (no-shows)' : ''; ?></span><b><?php echo $stats['tickets'] - $stats['checked_in_tickets']; ?> ticket(s)</b></div>
                <div class="row"><span>📷 Total scans</span><b><?php echo $scansTotal; ?></b></div>
                <div class="row"><span>⛔ Refused scans</span><b class="<?php echo $scansRefused ? 'red' : ''; ?>"><?php echo $scansRefused; ?></b></div>
                <div class="row"><span>📧 E-ticket emails sent</span><b><?php echo $stats['email_sent']; ?> / <?php echo $stats['confirmed_bookings']; ?></b></div>
                <div class="row"><span>💬 SMS tickets sent</span><b><?php echo $stats['sms_sent']; ?> / <?php echo $stats['sms_expected']; ?></b></div>
                <div class="row"><span>⚠ Delivery errors</span><b class="<?php echo $stats['notify_errors'] ? 'red' : ''; ?>"><?php echo $stats['notify_errors']; ?></b></div>
            </div>
            <?php if ($scanCounts): ?>
                <div class="deliv" style="margin-top: 12px;">
                    <?php foreach ($scanCounts as $res => $cnt): ?>
                        <span class="pill <?php echo in_array($res, ['first_entry', 're_entry'], true) ? 'ok' : ($res === 'checked_out' ? 'info' : 'bad'); ?>">
                            <?php echo htmlspecialchars(SCAN_RESULT_LABELS[$res] ?? $res); ?> · <?php echo $cnt; ?>
                        </span>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>
    </div>

    <!-- ===== Refunds + status history ===== -->
    <div class="grid-2">
        <section class="panel">
            <div class="panel-head">
                <div>
                    <h3>↩ Refunds</h3>
                    <p class="sub">Customer refund requests for this event.</p>
                </div>
                <a class="btn" href="refund_requests.php?status=all&event_id=<?php echo (int)$event['id']; ?>">Open refund requests →</a>
            </div>
            <div class="rows">
                <div class="row"><span>⏳ Waiting for review</span><b class="<?php echo $refundSummary['pending'] ? 'gold' : ''; ?>"><?php echo $refundSummary['pending']; ?><?php echo $refundSummary['pending'] ? ' · ' . lkr($refundSummary['pending_amount']) : ''; ?></b></div>
                <div class="row"><span>✅ Refunded</span><b><?php echo $refundSummary['refunded']; ?> · <?php echo lkr($refundSummary['refunded_amount']); ?></b></div>
                <div class="row"><span>✕ Declined</span><b><?php echo $refundSummary['rejected']; ?></b></div>
                <div class="row"><span>↩ Withdrawn by customer</span><b><?php echo $refundSummary['withdrawn']; ?></b></div>
            </div>
            <?php if (event_status_of($event) === 'cancelled' && $stats['gross'] > 0): ?>
                <a class="btn" style="margin-top: 12px; color: #FFA5A5; border-color: rgba(239,107,107,0.4);" href="refund_requests.php?status=all&event_id=<?php echo (int)$event['id']; ?>">⛔ Event cancelled — refund everyone →</a>
            <?php endif; ?>
        </section>

        <section class="panel">
            <h3>🕓 Status history</h3>
            <p class="sub">Every postpone, cancel, reschedule or sales change.</p>
            <?php if (!$statusHistory): ?>
                <div class="empty">No status changes yet — the event has been on sale since it was created.</div>
            <?php else: ?>
            <div class="rows">
                <?php foreach ($statusHistory as $h):
                    $from = EVENT_STATUSES[$h['old_status']] ?? null;
                    $to   = EVENT_STATUSES[$h['new_status']] ?? null; ?>
                    <div class="row" style="align-items: flex-start;">
                        <span>
                            <b style="color: var(--text);"><?php echo $from ? $from['label'] : $h['old_status']; ?> → <?php echo $to ? $to['icon'] . ' ' . $to['label'] : $h['new_status']; ?></b>
                            <?php if ($h['new_date']): ?><br><span style="font-size: 12px;">Date moved <?php echo date('M d', strtotime($h['old_date'])); ?> → <?php echo date('M d, Y · h:i A', strtotime($h['new_date'])); ?></span><?php endif; ?>
                            <?php if ($h['note']): ?><br><span style="font-size: 12px;">“<?php echo htmlspecialchars($h['note']); ?>”</span><?php endif; ?>
                        </span>
                        <span style="text-align: right; font-size: 12px; white-space: nowrap;"><?php echo date('M d, h:i A', strtotime($h['changed_at'])); ?><br><?php echo htmlspecialchars($h['changed_by_name'] ?? '—'); ?></span>
                    </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </section>
    </div>

    <!-- ===== Bookings table ===== -->
    <section class="panel" style="margin-bottom: 22px;">
        <div class="panel-head">
            <div>
                <h3>🧾 Bookings</h3>
                <p class="sub"><?php echo count($bookings); ?> booking(s) · newest first</p>
            </div>
            <?php if ($bookings): ?>
            <div class="toolbar">
                <input type="search" class="search" id="bookingSearch" placeholder="Search name, email, ref, phone…" aria-label="Search bookings">
                <div class="seg" id="bookingFilter" role="group" aria-label="Filter bookings">
                    <button type="button" class="active" data-f="all">All</button>
                    <button type="button" data-f="in">Checked in</button>
                    <button type="button" data-f="notin">Not arrived</button>
                    <button type="button" data-f="cancelled">Cancelled</button>
                </div>
            </div>
            <?php endif; ?>
        </div>

        <?php if (!$bookings): ?>
            <div class="empty">🎫 No bookings yet. They'll show up here as soon as someone buys a ticket.</div>
        <?php else: ?>
        <div class="table-wrap">
            <table id="bookingsTable">
                <thead>
                    <tr>
                        <th>Booking</th>
                        <th>Customer</th>
                        <th class="num">Qty</th>
                        <th class="num">Total</th>
                        <th class="num">Commission</th>
                        <th>Status</th>
                        <th>Check-in</th>
                        <th>E-ticket</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($bookings as $b):
                    $isConfirmed = $b['status'] === 'confirmed';
                    $filterKey = !$isConfirmed ? 'cancelled' : ($b['checked_in_at'] ? 'in' : 'notin');
                    $searchText = mb_strtolower(implode(' ', [$b['booking_ref'], $b['customer_name'], $b['customer_email'], $b['phone']]));
                ?>
                    <tr data-f="<?php echo $filterKey; ?>" data-s="<?php echo htmlspecialchars($searchText); ?>">
                        <td>
                            <span class="mono"><?php echo htmlspecialchars($b['booking_ref']); ?></span>
                            <div class="muted"><?php echo when($b['created_at']); ?></div>
                        </td>
                        <td>
                            <?php echo htmlspecialchars($b['customer_name'] ?? 'Deleted user'); ?>
                            <div class="muted"><?php echo htmlspecialchars($b['customer_email'] ?? ''); ?></div>
                            <?php if ($b['phone']): ?><div class="muted"><?php echo htmlspecialchars(phone_display($b['phone'])); ?></div><?php endif; ?>
                        </td>
                        <td class="num"><?php echo (int)$b['quantity']; ?></td>
                        <td class="num"><?php echo lkr((float)$b['total_price']); ?></td>
                        <td class="num" style="color: var(--amber);"><?php echo lkr((float)$b['commission_amount']); ?></td>
                        <td>
                            <?php if ($isConfirmed): ?><span class="pill ok">✓ Confirmed</span>
                            <?php else: ?><span class="pill bad">✕ Cancelled</span><?php endif; ?>
                        </td>
                        <td>
                            <?php if ($b['is_inside']): ?>
                                <span class="pill ok">● Inside</span>
                            <?php elseif ($b['checked_in_at']): ?>
                                <span class="pill info">↗ Checked out</span>
                            <?php elseif ($isConfirmed): ?>
                                <span class="pill dim">○ Not arrived</span>
                            <?php else: ?>
                                <span class="pill dim">—</span>
                            <?php endif; ?>
                            <?php if ($b['checked_in_at']): ?>
                                <div class="muted" style="margin-top: 4px;">First in <?php echo date('M d, h:i A', strtotime($b['checked_in_at'])); ?><?php echo $b['checked_in_by_name'] ? ' · by ' . htmlspecialchars($b['checked_in_by_name']) : ''; ?></div>
                            <?php endif; ?>
                        </td>
                        <td>
                            <div class="deliv">
                                <span class="pill <?php echo $b['email_sent_at'] ? 'ok' : 'dim'; ?>" title="<?php echo $b['email_sent_at'] ? 'Emailed ' . when($b['email_sent_at']) : 'Email not sent'; ?>">📧 <?php echo $b['email_sent_at'] ? 'Sent' : 'No'; ?></span>
                                <?php if ($b['phone']): ?>
                                    <span class="pill <?php echo $b['sms_sent_at'] ? 'ok' : 'dim'; ?>" title="<?php echo $b['sms_sent_at'] ? 'SMS ' . when($b['sms_sent_at']) : 'SMS not sent'; ?>">💬 <?php echo $b['sms_sent_at'] ? 'Sent' : 'No'; ?></span>
                                <?php endif; ?>
                            </div>
                            <?php if ($b['notify_error']): ?>
                                <div class="muted" style="color: #FFA5A5; margin-top: 4px;" title="<?php echo htmlspecialchars($b['notify_error']); ?>">⚠ <?php echo htmlspecialchars(mb_strimwidth($b['notify_error'], 0, 50, '…')); ?></div>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($b['ticket_token']): ?>
                                <a class="link" href="view_ticket.php?ref=<?php echo urlencode($b['booking_ref']); ?>&t=<?php echo urlencode($b['ticket_token']); ?>" target="_blank" rel="noopener">View ticket ↗</a>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <div class="empty" id="noMatches" style="display: none;">No bookings match your search.</div>
        </div>
        <?php endif; ?>
    </section>

    <!-- ===== Scan log ===== -->
    <section class="panel">
        <h3>📷 Recent door scans</h3>
        <p class="sub">The latest <?php echo count($scanLog); ?> scan(s) at the gate, newest first.</p>
        <?php if (!$scanLog): ?>
            <div class="empty">No tickets have been scanned for this event yet.</div>
        <?php else: ?>
        <div class="table-wrap">
            <table>
                <thead><tr><th>Time</th><th>Booking</th><th>Holder</th><th>Direction</th><th>Result</th><th>Method</th><th>Scanned by</th></tr></thead>
                <tbody>
                <?php foreach ($scanLog as $s): ?>
                    <tr>
                        <td><?php echo when($s['scanned_at']); ?></td>
                        <td class="mono"><?php echo htmlspecialchars($s['booking_ref']); ?></td>
                        <td><?php echo htmlspecialchars($s['holder'] ?? '—'); ?> <span class="muted">×<?php echo (int)$s['quantity']; ?></span></td>
                        <td><?php echo $s['direction'] === 'in' ? '⬇ In' : '⬆ Out'; ?></td>
                        <td><span class="pill <?php echo $s['accepted'] ? 'ok' : 'bad'; ?>"><?php echo $s['accepted'] ? '✓' : '✕'; ?> <?php echo htmlspecialchars(SCAN_RESULT_LABELS[$s['result']] ?? $s['result']); ?></span></td>
                        <td><?php echo $s['method'] === 'manual' ? '⌨ Manual' : '▣ QR'; ?></td>
                        <td><?php echo htmlspecialchars($s['scanned_by'] ?? '—'); ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </section>
</div>

<?php require __DIR__ . "/footer.php"; ?>

<script>
// ---- Chart hover tooltip ----
(function () {
    const chart = document.getElementById('salesChart');
    if (!chart) return;
    const svg = chart.querySelector('svg');
    const tip = document.getElementById('chartTip');
    chart.querySelectorAll('g.col').forEach(col => {
        col.addEventListener('mouseenter', () => {
            const vb = svg.viewBox.baseVal, box = svg.getBoundingClientRect();
            const x = col.dataset.x * box.width / vb.width;
            const y = Math.min(col.dataset.y, vb.height - 30) * box.height / vb.height;
            tip.innerHTML = `<b>${col.dataset.tickets} ticket(s)</b><span>${col.dataset.day} · LKR ${col.dataset.gross}</span>`;
            tip.style.left = x + 'px';
            tip.style.top = y + 'px';
            tip.classList.add('show');
        });
        col.addEventListener('mouseleave', () => tip.classList.remove('show'));
    });
})();

// ---- Bookings search + filter ----
(function () {
    const table = document.getElementById('bookingsTable');
    if (!table) return;
    const search = document.getElementById('bookingSearch');
    const buttons = document.querySelectorAll('#bookingFilter button');
    const rows = table.querySelectorAll('tbody tr');
    const none = document.getElementById('noMatches');
    let filter = 'all';

    function apply() {
        const q = search.value.trim().toLowerCase();
        let shown = 0;
        rows.forEach(r => {
            const ok = (filter === 'all' || r.dataset.f === filter) && (q === '' || r.dataset.s.includes(q));
            r.style.display = ok ? '' : 'none';
            if (ok) shown++;
        });
        none.style.display = shown ? 'none' : '';
    }
    search.addEventListener('input', apply);
    buttons.forEach(b => b.addEventListener('click', () => {
        buttons.forEach(x => x.classList.remove('active'));
        b.classList.add('active');
        filter = b.dataset.f;
        apply();
    }));
})();
</script>
</body>
</html>
