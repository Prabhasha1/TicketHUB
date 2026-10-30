<?php
/**
 * refund_requests.php
 * ------------------------------------------------------------
 * Admins review customer refund requests:
 *   Approve → money back (Stripe card refund, or recorded as a manual
 *             cash/bank refund), booking cancelled, seats released.
 *   Decline → customer sees the admin's note on My Tickets.
 *
 * Admins see requests for their own events; Super Admins see all.
 * Filters: ?status=pending|refunded|rejected|withdrawn|all  &event_id=N
 * ------------------------------------------------------------
 */
session_start();
require __DIR__ . "/config/database.php";
require __DIR__ . "/config/app.php";
require __DIR__ . "/config/refund_service.php";

if (!isset($_SESSION['user_role']) || !in_array($_SESSION['user_role'], ['admin', 'super_admin'], true)) {
    header("Location: login.php");
    exit;
}

$currentUserId = (int)$_SESSION['user_id'];
$isSuperAdmin  = ($_SESSION['user_role'] === 'super_admin');
$csrfToken     = csrf_token();

$statusFilter = $_GET['status'] ?? 'pending';
if ($statusFilter !== 'all' && !isset(REFUND_STATUSES[$statusFilter])) {
    $statusFilter = 'pending';
}
$eventFilter = (int)($_GET['event_id'] ?? 0);

function back_to_refunds(string $key, string $message): void {
    $qs = ['status' => $_POST['return_status'] ?? 'pending'];
    if (!empty($_POST['return_event'])) {
        $qs['event_id'] = (int)$_POST['return_event'];
    }
    $qs[$key] = $message;
    header("Location: refund_requests.php?" . http_build_query($qs));
    exit;
}

// ---- Approve / decline ----
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_valid($_POST['csrf_token'] ?? null)) {
        back_to_refunds('error', "Your session expired. Please try again.");
    }
    $requestId = (int)($_POST['request_id'] ?? 0);
    $action    = $_POST['action'] ?? '';

    if ($action === 'approve') {
        $result = refund_approve($conn, $requestId, $currentUserId, $isSuperAdmin,
            (float)($_POST['amount'] ?? 0), (string)($_POST['method'] ?? ''), (string)($_POST['admin_note'] ?? ''));
    } elseif ($action === 'refund_all') {
        $result = refund_cancelled_event_all($conn, (int)($_POST['event_id'] ?? 0), $currentUserId, $isSuperAdmin, (string)($_POST['admin_note'] ?? ''));
    } elseif ($action === 'resend_email') {
        $req = refund_request_for_admin($conn, $requestId, $currentUserId, $isSuperAdmin);
        $mail = $req ? refund_notify($conn, $requestId) : ['ok' => false, 'error' => 'Request not found.'];
        $result = ['ok' => $mail['ok'], 'message' => $mail['ok'] ? "Email sent to the customer again." : "Email failed: {$mail['error']}"];
    } elseif ($action === 'reject') {
        $result = refund_reject($conn, $requestId, $currentUserId, $isSuperAdmin, (string)($_POST['admin_note'] ?? ''));
    } else {
        $result = ['ok' => false, 'message' => "Unknown action."];
    }
    back_to_refunds($result['ok'] ? 'msg' : 'error', $result['message']);
}

$successMsg = trim($_GET['msg'] ?? '');
$errorMsg   = trim($_GET['error'] ?? '');

// ---- Data ----
$ownerSql = $isSuperAdmin ? "1 = 1" : "e.created_by = $currentUserId";

$counts = ['pending' => 0, 'refunded' => 0, 'rejected' => 0, 'withdrawn' => 0, 'all' => 0];
$pendingAmount = 0.0;
$refundedAmount = 0.0;
$requests = [];
$eventsWithRequests = [];
$filterEvent = null;

try {
    $eventSql = $eventFilter ? " AND r.event_id = $eventFilter" : "";

    $res = $conn->query(
        "SELECT r.status, COUNT(*) AS n, COALESCE(SUM(r.amount_requested), 0) AS requested, COALESCE(SUM(r.refund_amount), 0) AS refunded
         FROM refund_requests r JOIN events e ON e.id = r.event_id
         WHERE $ownerSql $eventSql GROUP BY r.status"
    );
    while ($row = $res->fetch_assoc()) {
        $counts[$row['status']] = (int)$row['n'];
        $counts['all'] += (int)$row['n'];
        if ($row['status'] === 'pending')  { $pendingAmount  = (float)$row['requested']; }
        if ($row['status'] === 'refunded') { $refundedAmount = (float)$row['refunded']; }
    }

    $eventsWithRequests = $conn->query(
        "SELECT e.id, e.title, SUM(r.status = 'pending') AS pending
         FROM refund_requests r JOIN events e ON e.id = r.event_id
         WHERE $ownerSql GROUP BY e.id, e.title ORDER BY pending DESC, e.title"
    )->fetch_all(MYSQLI_ASSOC);
    foreach ($eventsWithRequests as $ev) {
        if ((int)$ev['id'] === $eventFilter) { $filterEvent = $ev; }
    }

    $statusSql = $statusFilter === 'all' ? "" : " AND r.status = '" . $conn->real_escape_string($statusFilter) . "'";
    $order = $statusFilter === 'pending' ? "r.created_at ASC" : "COALESCE(r.reviewed_at, r.created_at) DESC";
    $requests = $conn->query(
        "SELECT r.*, b.booking_ref, b.quantity, b.total_price, b.commission_amount, b.status AS booking_status,
                b.checked_in_at, b.points_earned, b.points_redeemed, b.phone, b.payment_id,
                e.title AS event_title, e.event_date, e.status AS event_status, e.status_note, e.photo,
                u.name AS customer_name, u.email AS customer_email,
                rv.name AS reviewer_name, p.stripe_payment_intent,
                (SELECT COUNT(*) FROM refund_requests r2 WHERE r2.booking_id = r.booking_id AND r2.id < r.id) AS earlier_requests
         FROM refund_requests r
         JOIN bookings b ON b.id = r.booking_id
         JOIN events e   ON e.id = r.event_id
         LEFT JOIN users u  ON u.id = r.user_id
         LEFT JOIN users rv ON rv.id = r.reviewed_by
         LEFT JOIN payments p ON p.id = b.payment_id
         WHERE $ownerSql $eventSql $statusSql
         ORDER BY $order
         LIMIT 200"
    )->fetch_all(MYSQLI_ASSOC);
} catch (mysqli_sql_exception $e) {
    error_log("refund_requests load failed (run files/setup_platform.php?): " . $e->getMessage());
    $errorMsg = $errorMsg ?: "Could not load refund requests. If you just updated TicketHUB, run files/setup_platform.php once.";
}

$cancelledToRefund = refund_cancelled_events_pending($conn, $currentUserId, $isSuperAdmin);
if ($eventFilter) {
    $cancelledToRefund = array_values(array_filter($cancelledToRefund, fn($ev) => (int)$ev['id'] === $eventFilter));
}

function lkr(float $n): string { return 'LKR ' . number_format($n, 2); }
function filter_url(array $changes): string {
    global $statusFilter, $eventFilter;
    $qs = array_filter(['status' => $statusFilter, 'event_id' => $eventFilter ?: null] + [], fn($v) => $v !== null);
    $qs = array_merge($qs, $changes);
    return 'refund_requests.php?' . http_build_query(array_filter($qs, fn($v) => $v !== null && $v !== 0));
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<?php include __DIR__ . "/pwa_head.php"; ?>
<title>Refund Requests - TicketHub</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Sora:wght@600;700;800&family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
<style>
:root {
    --bg: #14121A; --surface: rgba(255,255,255,0.04); --surface-hover: rgba(255,255,255,0.07);
    --border: rgba(255,255,255,0.08); --text: #F1EEF7; --text-dim: #A9A3B8; --text-muted: #7D7790;
    --violet: #9D7CF9; --rose: #F0729C; --blue: #6CA0F2; --red: #EF6B6B; --amber: #F2C572; --green: #6FCF97;
    --font-display: 'Sora', 'Segoe UI', sans-serif; --font-body: 'Inter', 'Segoe UI', sans-serif;
}
* { box-sizing: border-box; }
body { margin: 0; font-family: var(--font-body); background: var(--bg); color: var(--text); }
.container { max-width: 1080px; margin: 0 auto; padding: 36px 48px 100px; }
.crumbs { font-size: 13px; color: var(--text-dim); margin-bottom: 18px; }
.crumbs a { color: var(--text-dim); text-decoration: none; }
.crumbs a:hover { color: var(--text); }
h1 { font-family: var(--font-display); font-size: 28px; margin: 0 0 6px; }
.lead { color: var(--text-dim); font-size: 14px; margin: 0 0 24px; }

.flash { padding: 12px 18px; border-radius: 12px; margin-bottom: 20px; font-size: 14px; }
.flash.ok  { background: rgba(111,207,151,0.12); border: 1px solid rgba(111,207,151,0.35); color: var(--green); }
.flash.err { background: rgba(239,107,107,0.14); border: 1px solid rgba(239,107,107,0.35); color: #FFA5A5; }

.kpis { display: grid; grid-template-columns: repeat(3, 1fr); gap: 16px; margin-bottom: 22px; }
.kpi { background: var(--surface); border: 1px solid var(--border); border-radius: 16px; padding: 16px 20px; }
.kpi .label { font-size: 12px; font-weight: 600; letter-spacing: 0.5px; text-transform: uppercase; color: var(--text-dim); margin-bottom: 6px; }
.kpi .value { font-family: var(--font-display); font-size: 22px; font-weight: 700; }
.kpi .value small { font-size: 12px; color: var(--text-dim); font-weight: 600; margin-right: 4px; }
.kpi.rose { border-color: rgba(240,114,156,0.35); background: linear-gradient(135deg, rgba(240,114,156,0.1), rgba(20,18,26,0.4)); }
.kpi.rose .label, .kpi.rose .value { color: #FFC2D4; }

.toolbar { display: flex; justify-content: space-between; gap: 12px; flex-wrap: wrap; align-items: center; margin-bottom: 18px; }
.tabs { display: inline-flex; flex-wrap: wrap; gap: 6px; }
.tabs a {
    padding: 8px 14px; border-radius: 100px; font-size: 13px; font-weight: 600; text-decoration: none;
    color: var(--text-dim); border: 1px solid var(--border); background: rgba(255,255,255,0.03);
}
.tabs a:hover { color: var(--text); }
.tabs a.active { color: var(--text); background: rgba(157,124,249,0.2); border-color: rgba(157,124,249,0.45); }
.tabs a .n { margin-left: 4px; color: var(--text-muted); font-weight: 700; }
.tabs a.active .n { color: #CBB8FC; }
select.event-filter {
    background: rgba(255,255,255,0.05); border: 1px solid var(--border); border-radius: 10px; color: var(--text);
    font-family: var(--font-body); font-size: 13px; padding: 9px 12px; max-width: 100%;
}
select.event-filter option { background: #1A1722; }

.req {
    background: var(--surface); border: 1px solid var(--border); border-radius: 18px; padding: 18px 20px; margin-bottom: 16px;
    animation: rise 0.4s ease-out both;
}
.req.pending { border-color: rgba(242,197,114,0.3); }
.req-top { display: flex; justify-content: space-between; gap: 16px; flex-wrap: wrap; }
.req-top h3 { font-family: var(--font-display); font-size: 16px; margin: 0 0 4px; }
.req-top h3 a { color: inherit; text-decoration: none; }
.req-top h3 a:hover { text-decoration: underline; }
.meta { font-size: 13px; color: var(--text-dim); display: flex; gap: 6px 14px; flex-wrap: wrap; }
.amount { text-align: right; }
.amount .big { font-family: var(--font-display); font-size: 20px; font-weight: 700; }
.amount .small { font-size: 12px; color: var(--text-dim); }
.mono { font-family: ui-monospace, Consolas, monospace; font-size: 12.5px; }

.pill { display: inline-flex; align-items: center; gap: 4px; font-size: 11.5px; font-weight: 600; padding: 3px 9px; border-radius: 100px; border: 1px solid transparent; white-space: nowrap; }
.pill.green { color: var(--green); background: rgba(111,207,151,0.12); border-color: rgba(111,207,151,0.3); }
.pill.amber { color: var(--amber); background: rgba(242,197,114,0.12); border-color: rgba(242,197,114,0.3); }
.pill.red   { color: #FFA5A5; background: rgba(239,107,107,0.12); border-color: rgba(239,107,107,0.3); }
.pill.blue  { color: var(--blue); background: rgba(108,160,242,0.12); border-color: rgba(108,160,242,0.3); }
.pill.dim   { color: var(--text-dim); background: rgba(255,255,255,0.05); border-color: var(--border); }

.reason { margin-top: 14px; padding: 12px 14px; border-radius: 12px; background: rgba(255,255,255,0.03); border: 1px solid var(--border); font-size: 13.5px; }
.reason .why { font-weight: 600; display: flex; gap: 8px; align-items: center; flex-wrap: wrap; }
.reason .msg { color: var(--text-dim); margin-top: 6px; white-space: pre-line; }
.warn-line { margin-top: 10px; font-size: 12.5px; color: var(--amber); }
.err-line  { margin-top: 10px; font-size: 12.5px; color: #FFA5A5; }

.decision { margin-top: 12px; font-size: 13px; color: var(--text-dim); }
.decision b { color: var(--text); }

.actions { display: flex; gap: 10px; margin-top: 14px; flex-wrap: wrap; }
.btn {
    padding: 9px 18px; border-radius: 100px; border: 1px solid var(--border); cursor: pointer;
    font-family: var(--font-body); font-weight: 700; font-size: 13px; background: rgba(255,255,255,0.05); color: var(--text);
    transition: background 0.2s ease, transform 0.2s ease;
}
.btn:hover { transform: translateY(-1px); background: rgba(255,255,255,0.1); }
.btn.approve { background: var(--green); color: #0B2416; border-color: var(--green); }
.btn.approve:hover { background: #8FE0B0; }
.btn.reject { color: #FFA5A5; border-color: rgba(239,107,107,0.4); background: rgba(239,107,107,0.08); }
.btn:disabled { opacity: 0.5; cursor: not-allowed; transform: none; }

.panel { display: none; margin-top: 14px; padding: 16px; border-radius: 14px; background: rgba(0,0,0,0.18); border: 1px solid var(--border); }
.panel.open { display: block; animation: rise 0.25s ease-out; }
.panel h4 { margin: 0 0 12px; font-size: 14px; }
.field { margin-bottom: 12px; }
.field label.cap { display: block; font-size: 12px; font-weight: 600; color: var(--text-dim); margin-bottom: 6px; }
.field input[type="number"], .field input[type="text"] {
    width: 100%; background: rgba(255,255,255,0.05); border: 1px solid var(--border); border-radius: 10px; color: var(--text);
    font-family: var(--font-body); font-size: 14px; padding: 10px 12px; outline: none;
}
.field input:focus { border-color: var(--violet); box-shadow: 0 0 0 3px rgba(157,124,249,0.15); }
.quick { display: flex; gap: 6px; flex-wrap: wrap; margin-top: 6px; }
.quick button {
    font-size: 12px; font-weight: 600; padding: 5px 10px; border-radius: 100px; cursor: pointer;
    background: transparent; color: var(--text-dim); border: 1px solid var(--border); font-family: var(--font-body);
}
.quick button:hover { color: var(--text); border-color: rgba(255,255,255,0.25); }
.radio { display: flex; gap: 10px; align-items: flex-start; padding: 10px 12px; border-radius: 10px; border: 1px solid var(--border); margin-bottom: 8px; cursor: pointer; font-size: 13px; }
.radio:has(input:checked) { border-color: rgba(157,124,249,0.5); background: rgba(157,124,249,0.08); }
.radio.disabled { opacity: 0.5; cursor: not-allowed; }
.radio small { display: block; color: var(--text-dim); margin-top: 2px; }
.summary { font-size: 13px; color: var(--text-dim); margin: 4px 0 12px; }
.summary b { color: var(--text); }

.link-btn { background: none; border: none; padding: 0; color: var(--violet); font: inherit; font-weight: 600; cursor: pointer; text-decoration: underline; }

.refund-all {
    display: flex; align-items: center; justify-content: space-between; gap: 18px; flex-wrap: wrap;
    padding: 18px 20px; border-radius: 16px; margin-bottom: 18px;
    background: linear-gradient(135deg, rgba(239,107,107,0.12), rgba(20,18,26,0.4)); border: 1px solid rgba(239,107,107,0.4);
}
.refund-all .ra-text { flex: 1 1 380px; }
.refund-all h4 { margin: 0 0 4px; font-family: var(--font-display); font-size: 15px; color: #FFA5A5; }
.refund-all h4 a { color: inherit; }
.refund-all p { margin: 0 0 10px; font-size: 13px; color: var(--text-dim); line-height: 1.5; }
.refund-all p b { color: var(--text); }
.refund-all input[type="text"] {
    width: 100%; background: rgba(255,255,255,0.05); border: 1px solid var(--border); border-radius: 10px; color: var(--text);
    font-family: var(--font-body); font-size: 13px; padding: 9px 12px; outline: none;
}
.refund-all input:focus { border-color: var(--violet); }

.empty { text-align: center; padding: 60px 20px; color: var(--text-dim); background: var(--surface); border: 1px dashed var(--border); border-radius: 18px; }
.empty .icon { font-size: 40px; display: block; margin-bottom: 10px; }
.footer { text-align: center; padding: 28px; border-top: 1px solid var(--border); color: var(--text-dim); font-size: 13px; }

@keyframes rise { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }
@media (prefers-reduced-motion: reduce) { * { animation: none !important; transition: none !important; } }
@media (max-width: 760px) { .kpis { grid-template-columns: 1fr; } .amount { text-align: left; } }
@media (max-width: 640px) { .container { padding: 24px 16px 70px; } }
</style>
</head>
<body>
<?php require __DIR__ . "/nav.php"; ?>

<div class="container">
    <div class="crumbs"><a href="manage_events.php">← Manage Events</a> / Refund requests</div>
    <h1>🔔 Refund requests</h1>
    <p class="lead"><?php echo $isSuperAdmin ? 'Requests from customers across every event on TicketHUB.' : 'Requests from customers who bought tickets to your events.'; ?>
        Approving refunds the money and cancels the booking, so its seats go back on sale.</p>

    <?php if ($successMsg): ?><div class="flash ok">✅ <?php echo htmlspecialchars($successMsg); ?></div><?php endif; ?>
    <?php if ($errorMsg): ?><div class="flash err">⚠ <?php echo htmlspecialchars($errorMsg); ?></div><?php endif; ?>

    <?php foreach ($cancelledToRefund as $ev): $o = $ev['outstanding']; ?>
        <form class="refund-all" method="POST" data-title="<?php echo htmlspecialchars($ev['title']); ?>" data-bookings="<?php echo $o['bookings']; ?>" data-amount="<?php echo number_format($o['amount'], 2); ?>" data-card="<?php echo $o['card']; ?>" data-manual="<?php echo $o['manual']; ?>">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
            <input type="hidden" name="action" value="refund_all">
            <input type="hidden" name="event_id" value="<?php echo (int)$ev['id']; ?>">
            <input type="hidden" name="return_status" value="<?php echo htmlspecialchars($statusFilter); ?>">
            <input type="hidden" name="return_event" value="<?php echo $eventFilter; ?>">
            <div class="ra-text">
                <h4>⛔ <a href="event_details.php?id=<?php echo (int)$ev['id']; ?>"><?php echo htmlspecialchars($ev['title']); ?></a> is cancelled</h4>
                <p><b><?php echo $o['bookings']; ?> booking(s)</b> · <?php echo $o['tickets']; ?> ticket(s) · <b><?php echo lkr($o['amount']); ?></b> still to give back.
                    <?php if ($o['manual']): ?><br><?php echo $o['manual']; ?> of them have no card payment on record — they'll be added to the Pending list for a manual refund.<?php endif; ?></p>
                <input type="text" name="admin_note" maxlength="255" placeholder="Message to customers (optional) — e.g. Sorry, the artist is unwell. Hope to see you next time!">
            </div>
            <button type="submit" class="btn approve">↩ Refund everyone</button>
        </form>
    <?php endforeach; ?>

    <div class="kpis">
        <div class="kpi <?php echo $counts['pending'] ? 'rose' : ''; ?>">
            <div class="label">⏳ Waiting for review</div>
            <div class="value"><?php echo $counts['pending']; ?></div>
        </div>
        <div class="kpi">
            <div class="label">💸 Requested (pending)</div>
            <div class="value"><small>LKR</small><?php echo number_format($pendingAmount, 2); ?></div>
        </div>
        <div class="kpi">
            <div class="label">✅ Refunded so far</div>
            <div class="value"><small>LKR</small><?php echo number_format($refundedAmount, 2); ?></div>
        </div>
    </div>

    <div class="toolbar">
        <div class="tabs">
            <?php foreach (['pending' => 'Pending', 'refunded' => 'Refunded', 'rejected' => 'Declined', 'withdrawn' => 'Withdrawn', 'all' => 'All'] as $key => $label): ?>
                <a href="<?php echo htmlspecialchars(filter_url(['status' => $key])); ?>" class="<?php echo $statusFilter === $key ? 'active' : ''; ?>"><?php echo $label; ?><span class="n"><?php echo $counts[$key]; ?></span></a>
            <?php endforeach; ?>
        </div>
        <?php if ($eventsWithRequests): ?>
            <select class="event-filter" aria-label="Filter by event" onchange="location.href = this.value;">
                <option value="<?php echo htmlspecialchars(filter_url(['event_id' => null])); ?>">All events</option>
                <?php foreach ($eventsWithRequests as $ev): ?>
                    <option value="<?php echo htmlspecialchars(filter_url(['event_id' => (int)$ev['id']])); ?>" <?php echo (int)$ev['id'] === $eventFilter ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($ev['title']); ?><?php echo (int)$ev['pending'] ? " ({$ev['pending']} pending)" : ''; ?>
                    </option>
                <?php endforeach; ?>
            </select>
        <?php endif; ?>
    </div>

    <?php if (!$requests): ?>
        <div class="empty">
            <span class="icon"><?php echo $statusFilter === 'pending' ? '🎉' : '📭'; ?></span>
            <?php echo $statusFilter === 'pending' ? "You're all caught up — no refund requests waiting." : "No refund requests here."; ?>
        </div>
    <?php endif; ?>

    <?php foreach ($requests as $i => $r):
        $rs        = REFUND_STATUSES[$r['status']];
        $evMeta    = EVENT_STATUSES[$r['event_status']] ?? EVENT_STATUSES['on_sale'];
        $eventSide = refund_reason_is_event_side($r['reason']) || in_array($r['event_status'], ['cancelled', 'postponed', 'rescheduled'], true);
        $paid      = (float)$r['total_price'];
        $commission = (float)$r['commission_amount'];
        $hasStripe = !empty($r['stripe_payment_intent']);
    ?>
    <article class="req <?php echo $r['status']; ?>" style="animation-delay: <?php echo min($i * 0.04, 0.4); ?>s;">
        <div class="req-top">
            <div>
                <h3><a href="event_details.php?id=<?php echo (int)$r['event_id']; ?>"><?php echo htmlspecialchars($r['event_title']); ?></a></h3>
                <div class="meta">
                    <span class="pill <?php echo $evMeta['tone']; ?>"><?php echo $evMeta['icon'] . ' ' . $evMeta['label']; ?></span>
                    <span>🗓 <?php echo date('M d, Y · h:i A', strtotime($r['event_date'])); ?></span>
                </div>
                <div class="meta" style="margin-top: 8px;">
                    <span>👤 <b style="color: var(--text);"><?php echo htmlspecialchars($r['customer_name'] ?? 'Deleted user'); ?></b></span>
                    <span><?php echo htmlspecialchars($r['customer_email'] ?? ''); ?></span>
                    <?php if ($r['phone']): ?><span>📱 <?php echo htmlspecialchars(phone_display($r['phone'])); ?></span><?php endif; ?>
                </div>
                <div class="meta" style="margin-top: 4px;">
                    <span class="mono"><?php echo htmlspecialchars($r['booking_ref']); ?></span>
                    <span><?php echo (int)$r['quantity']; ?> ticket(s)</span>
                    <span>Requested <?php echo date('M d, Y · h:i A', strtotime($r['created_at'])); ?></span>
                </div>
            </div>
            <div class="amount">
                <span class="pill <?php echo $rs['tone']; ?>"><?php echo $rs['icon'] . ' ' . $rs['label']; ?></span>
                <div class="big" style="margin-top: 8px;"><?php echo lkr($r['status'] === 'refunded' ? (float)$r['refund_amount'] : (float)$r['amount_requested']); ?></div>
                <div class="small"><?php echo $r['status'] === 'refunded' ? 'refunded of ' . lkr($paid) . ' paid' : 'paid for this booking'; ?></div>
            </div>
        </div>

        <div class="reason">
            <div class="why">
                <?php echo htmlspecialchars(REFUND_REASONS[$r['reason']] ?? $r['reason']); ?>
                <?php if ($eventSide): ?>
                    <span class="pill blue">Event changed — full refund recommended</span>
                <?php else: ?>
                    <span class="pill dim">Customer's choice</span>
                <?php endif; ?>
            </div>
            <?php if (trim((string)$r['message']) !== ''): ?>
                <div class="msg">“<?php echo htmlspecialchars($r['message']); ?>”</div>
            <?php endif; ?>
            <?php if (!empty($r['status_note']) && $eventSide): ?>
                <div class="msg" style="font-size: 12.5px;">Event notice: <?php echo htmlspecialchars($r['status_note']); ?></div>
            <?php endif; ?>
        </div>

        <?php if ($r['status'] === 'pending'): ?>
            <?php if ($r['checked_in_at']): ?>
                <div class="warn-line">⚠ This ticket was already scanned at the door on <?php echo date('M d, h:i A', strtotime($r['checked_in_at'])); ?>.</div>
            <?php endif; ?>
            <?php if ((int)$r['earlier_requests'] > 0): ?>
                <div class="warn-line">ℹ The customer asked for a refund on this booking <?php echo (int)$r['earlier_requests']; ?> time(s) before.</div>
            <?php endif; ?>
            <?php if (!$hasStripe): ?>
                <div class="warn-line">ℹ No card payment on record for this booking — refund the customer another way and record it as a manual refund.</div>
            <?php endif; ?>
            <?php if ($r['refund_error']): ?>
                <div class="err-line">⚠ Last refund attempt failed: <?php echo htmlspecialchars($r['refund_error']); ?></div>
            <?php endif; ?>

            <div class="actions">
                <button type="button" class="btn approve" data-open="approve-<?php echo (int)$r['id']; ?>">✓ Approve refund</button>
                <button type="button" class="btn reject" data-open="reject-<?php echo (int)$r['id']; ?>">✕ Decline</button>
            </div>

            <form class="panel approve-form" id="approve-<?php echo (int)$r['id']; ?>" method="POST" data-ref="<?php echo htmlspecialchars($r['booking_ref']); ?>" data-paid="<?php echo $paid; ?>" data-qty="<?php echo (int)$r['quantity']; ?>">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                <input type="hidden" name="action" value="approve">
                <input type="hidden" name="request_id" value="<?php echo (int)$r['id']; ?>">
                <input type="hidden" name="return_status" value="<?php echo htmlspecialchars($statusFilter); ?>">
                <input type="hidden" name="return_event" value="<?php echo $eventFilter; ?>">
                <h4>Refund <?php echo htmlspecialchars($r['customer_name'] ?? 'customer'); ?></h4>
                <div class="field">
                    <label class="cap" for="amt-<?php echo (int)$r['id']; ?>">Amount to refund (LKR)</label>
                    <input type="number" id="amt-<?php echo (int)$r['id']; ?>" name="amount" class="amount-input" min="0.01" max="<?php echo $paid; ?>" step="0.01" value="<?php echo number_format($paid, 2, '.', ''); ?>" required>
                    <div class="quick">
                        <button type="button" data-amount="<?php echo $paid; ?>">Full amount · <?php echo lkr($paid); ?></button>
                        <?php if ($commission > 0 && $commission < $paid): ?>
                            <button type="button" data-amount="<?php echo $paid - $commission; ?>">Keep service fee · <?php echo lkr($paid - $commission); ?></button>
                        <?php endif; ?>
                        <button type="button" data-amount="<?php echo round($paid / 2, 2); ?>">50% · <?php echo lkr(round($paid / 2, 2)); ?></button>
                    </div>
                </div>
                <div class="field">
                    <span class="cap" style="display:block; font-size:12px; font-weight:600; color: var(--text-dim); margin-bottom: 6px;">How is the money returned?</span>
                    <label class="radio <?php echo $hasStripe ? '' : 'disabled'; ?>">
                        <input type="radio" name="method" value="stripe" <?php echo $hasStripe ? 'checked' : 'disabled'; ?>>
                        <span>💳 Refund to the customer's card via Stripe<small>Automatic. Takes 5–10 business days to show on their statement.</small></span>
                    </label>
                    <label class="radio">
                        <input type="radio" name="method" value="manual" <?php echo $hasStripe ? '' : 'checked'; ?>>
                        <span>🏦 Already refunded manually<small>You paid it back by cash or bank transfer — just record it here.</small></span>
                    </label>
                </div>
                <div class="field">
                    <label class="cap" for="an-<?php echo (int)$r['id']; ?>">Note to customer (optional)</label>
                    <input type="text" id="an-<?php echo (int)$r['id']; ?>" name="admin_note" maxlength="255" placeholder="e.g. Sorry about the change — hope to see you next time!">
                </div>
                <p class="summary">Booking <b><?php echo htmlspecialchars($r['booking_ref']); ?></b> will be cancelled, <b><?php echo (int)$r['quantity']; ?> seat(s)</b> go back on sale<?php echo (int)$r['points_earned'] ? ', and <b>' . (int)$r['points_earned'] . ' loyalty points</b> are removed' : ''; ?>.<?php echo (int)$r['points_redeemed'] ? ' The <b>' . (int)$r['points_redeemed'] . ' points</b> they used for a discount are given back.' : ''; ?></p>
                <div class="actions" style="margin-top: 0;">
                    <button type="submit" class="btn approve">Confirm refund</button>
                    <button type="button" class="btn" data-close>Cancel</button>
                </div>
            </form>

            <form class="panel reject-form" id="reject-<?php echo (int)$r['id']; ?>" method="POST">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                <input type="hidden" name="action" value="reject">
                <input type="hidden" name="request_id" value="<?php echo (int)$r['id']; ?>">
                <input type="hidden" name="return_status" value="<?php echo htmlspecialchars($statusFilter); ?>">
                <input type="hidden" name="return_event" value="<?php echo $eventFilter; ?>">
                <h4>Decline this request</h4>
                <div class="field">
                    <label class="cap" for="rn-<?php echo (int)$r['id']; ?>">Reason (the customer will see this)</label>
                    <input type="text" id="rn-<?php echo (int)$r['id']; ?>" name="admin_note" maxlength="255" required placeholder="e.g. Tickets are non-refundable within 48 hours of the event.">
                </div>
                <div class="actions" style="margin-top: 0;">
                    <button type="submit" class="btn reject">Decline request</button>
                    <button type="button" class="btn" data-close>Cancel</button>
                </div>
            </form>
        <?php else: ?>
            <div class="decision">
                <?php if ($r['status'] === 'withdrawn'): ?>
                    The customer withdrew this request<?php echo $r['reviewed_at'] ? ' on ' . date('M d, Y · h:i A', strtotime($r['reviewed_at'])) : ''; ?>.
                <?php else: ?>
                    <?php echo $r['status'] === 'refunded' ? 'Refunded' : 'Declined'; ?>
                    by <b><?php echo htmlspecialchars($r['reviewer_name'] ?? 'an admin'); ?></b>
                    on <?php echo date('M d, Y · h:i A', strtotime($r['reviewed_at'])); ?>
                    <?php if ($r['status'] === 'refunded'): ?>
                        · <?php echo $r['refund_method'] === 'stripe' ? '💳 Stripe card refund' : '🏦 Manual refund'; ?>
                        <?php if ($r['stripe_refund_id']): ?><span class="mono">(<?php echo htmlspecialchars($r['stripe_refund_id']); ?>)</span><?php endif; ?>
                    <?php endif; ?>
                    <?php if ($r['admin_note']): ?><br>Note: “<?php echo htmlspecialchars($r['admin_note']); ?>”<?php endif; ?>
                    <br>
                    <?php if ($r['email_sent_at']): ?>
                        <span style="color: var(--green);">📧 Customer emailed <?php echo date('M d, h:i A', strtotime($r['email_sent_at'])); ?></span>
                    <?php elseif ($r['email_error']): ?>
                        <span style="color: #FFA5A5;" title="<?php echo htmlspecialchars($r['email_error']); ?>">📧 Email not delivered: <?php echo htmlspecialchars(mb_strimwidth($r['email_error'], 0, 70, '…')); ?></span>
                    <?php else: ?>
                        <span>📧 No email sent</span>
                    <?php endif; ?>
                    <?php if (!$r['email_sent_at']): ?>
                        <form method="POST" style="display: inline;">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                            <input type="hidden" name="action" value="resend_email">
                            <input type="hidden" name="request_id" value="<?php echo (int)$r['id']; ?>">
                            <input type="hidden" name="return_status" value="<?php echo htmlspecialchars($statusFilter); ?>">
                            <input type="hidden" name="return_event" value="<?php echo $eventFilter; ?>">
                            · <button type="submit" class="link-btn">Send email</button>
                        </form>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </article>
    <?php endforeach; ?>
</div>

<?php require __DIR__ . "/footer.php"; ?>

<script>
document.querySelectorAll('[data-open]').forEach(btn => btn.addEventListener('click', () => {
    const card = btn.closest('.req');
    card.querySelectorAll('.panel').forEach(p => p.classList.toggle('open', p.id === btn.dataset.open && !p.classList.contains('open')));
    const open = card.querySelector('.panel.open input:not([type=hidden]):not([type=radio])');
    if (open) open.focus();
}));
document.querySelectorAll('[data-close]').forEach(btn => btn.addEventListener('click', () => btn.closest('.panel').classList.remove('open')));
document.querySelectorAll('.quick button').forEach(btn => btn.addEventListener('click', () => {
    btn.closest('.field').querySelector('.amount-input').value = parseFloat(btn.dataset.amount).toFixed(2);
}));
document.querySelectorAll('.approve-form').forEach(form => form.addEventListener('submit', e => {
    const amount = parseFloat(form.amount.value) || 0;
    const method = form.querySelector('input[name=method]:checked');
    if (!method) { e.preventDefault(); alert('Choose how the money is returned.'); return; }
    const how = method.value === 'stripe' ? 'to their card via Stripe' : 'as a manual refund';
    if (!confirm(`Refund LKR ${amount.toLocaleString('en-US', { minimumFractionDigits: 2 })} ${how} and cancel booking ${form.dataset.ref}?\n\nThis cannot be undone.`)) {
        e.preventDefault();
        return;
    }
    form.querySelectorAll('button').forEach(b => b.disabled = true);
    form.querySelector('button[type=submit]').textContent = 'Refunding…';
}));
document.querySelectorAll('.refund-all').forEach(form => form.addEventListener('submit', e => {
    const d = form.dataset;
    let q = `Refund everyone for "${d.title}"?\n\n${d.bookings} booking(s) · LKR ${d.amount} in total.`;
    if (+d.card) q += `\n• ${d.card} will be refunded in full to their cards via Stripe now, and emailed.`;
    if (+d.manual) q += `\n• ${d.manual} have no card payment — they'll wait in Pending for you to refund manually.`;
    q += `\n\nThis cannot be undone.`;
    if (!confirm(q)) { e.preventDefault(); return; }
    const btn = form.querySelector('button');
    btn.disabled = true;
    btn.textContent = 'Refunding… please wait';
}));
document.querySelectorAll('.reject-form').forEach(form => form.addEventListener('submit', () => {
    form.querySelectorAll('button').forEach(b => b.disabled = true);
}));
</script>
</body>
</html>
