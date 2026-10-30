<?php
session_start();
require __DIR__ . "/config/database.php";
require __DIR__ . "/config/booking_service.php";
require __DIR__ . "/config/refund_service.php";

$loggedIn = isset($_SESSION['user_id']);
if (!$loggedIn) {
    header("Location: login.php");
    exit;
}

$userName = $_SESSION['user_name'];
$isAdmin  = isset($_SESSION['user_role']) && in_array($_SESSION['user_role'], ['admin', 'super_admin'], true);
$userId   = (int)$_SESSION['user_id'];

$justBooked = $_GET['justBooked'] ?? null;
$sentFlags  = $_GET['sent'] ?? null;
$csrfToken  = csrf_token();

// ---- Refund requests (request / withdraw) ----
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if (!csrf_valid($_POST['csrf_token'] ?? null)) {
        $error = "Your session expired. Please try again.";
    } elseif ($action === 'request_refund') {
        $error = refund_request_create($conn, $userId, (int)($_POST['booking_id'] ?? 0),
                                       (string)($_POST['reason'] ?? ''), (string)($_POST['message'] ?? ''));
    } elseif ($action === 'withdraw_refund') {
        $error = refund_request_withdraw($conn, $userId, (int)($_POST['request_id'] ?? 0));
    } else {
        $error = "Unknown action.";
    }
    $notice = $error === null
        ? ($action === 'request_refund' ? "Refund request sent. The organizer will review it — you'll see the result here." : "Refund request withdrawn. Your tickets are still valid.")
        : $error;
    header("Location: my_tickets.php?" . http_build_query([$error === null ? 'refund_ok' : 'refund_err' => $notice]));
    exit;
}
$refundOk  = trim($_GET['refund_ok'] ?? '');
$refundErr = trim($_GET['refund_err'] ?? '');

// Pick up any payment Stripe completed but we never heard back about (tab closed, no webhook)
$recovered = [];
try {
    $recovered = payments_reconcile_user($conn, $userId);
} catch (Throwable $e) {
    error_log("my_tickets reconcile failed: " . $e->getMessage());
}
if ($recovered && !$justBooked) {
    $justBooked = $recovered[0]['booking_ref'];
    $sentFlags = null;
}

// Checkouts still in progress or that didn't go through (last 7 days)
$openPayments = [];
try {
    $pStmt = $conn->prepare(
        "SELECT p.id, p.event_id, p.quantity, p.amount_total, p.status, p.failure_reason, p.expires_at, p.created_at, e.title
         FROM payments p JOIN events e ON e.id = p.event_id
         WHERE p.user_id = ? AND p.booking_id IS NULL
           AND ((p.status = 'pending' AND p.expires_at > NOW()) OR p.status IN ('refunded', 'failed'))
           AND p.created_at > NOW() - INTERVAL 7 DAY
         ORDER BY p.id DESC"
    );
    $pStmt->bind_param("i", $userId);
    $pStmt->execute();
    $openPayments = $pStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $pStmt->close();
} catch (mysqli_sql_exception $e) {
    error_log("my_tickets payments fetch failed: " . $e->getMessage());
}

$bookings = [];
try {
    $stmt = $conn->prepare(
        "SELECT b.*, e.title, e.location, e.event_date, e.photo, e.category,
                e.status AS event_status, e.status_note, e.original_event_date
         FROM bookings b
         JOIN events e ON e.id = b.event_id
         WHERE b.user_id = ?
         ORDER BY b.created_at DESC"
    );
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $row['ticket_url'] = booking_ticket_url($conn, $row);
        $row['refund'] = refund_eligibility($row, ['status' => $row['event_status'], 'event_date' => $row['event_date']]);
        $row['refund_request'] = null;
        $bookings[(int)$row['id']] = $row;
    }
    $stmt->close();

    // Latest refund request per booking
    $rStmt = $conn->prepare(
        "SELECT r.* FROM refund_requests r
         JOIN (SELECT booking_id, MAX(id) AS id FROM refund_requests WHERE user_id = ? GROUP BY booking_id) last ON last.id = r.id"
    );
    $rStmt->bind_param("i", $userId);
    $rStmt->execute();
    foreach ($rStmt->get_result()->fetch_all(MYSQLI_ASSOC) as $req) {
        if (isset($bookings[(int)$req['booking_id']])) {
            $bookings[(int)$req['booking_id']]['refund_request'] = $req;
        }
    }
    $rStmt->close();
} catch (mysqli_sql_exception $e) {
    error_log("my_tickets fetch failed: " . $e->getMessage());
}
$bookings = array_values($bookings);

$loyaltyPoints = 0;
try {
    $ptsStmt = $conn->prepare("SELECT loyalty_points FROM users WHERE id = ?");
    $ptsStmt->bind_param("i", $userId);
    $ptsStmt->execute();
    $loyaltyPoints = (int)($ptsStmt->get_result()->fetch_assoc()['loyalty_points'] ?? 0);
    $ptsStmt->close();
} catch (mysqli_sql_exception $e) {
    error_log("my_tickets points fetch failed: " . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<?php include __DIR__ . "/pwa_head.php"; ?>
<title>My Tickets - TicketHub</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Sora:wght@600;700;800&family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
<style>
:root {
    --bg: #14121A; --surface: rgba(255,255,255,0.04); --surface-hover: rgba(255,255,255,0.07);
    --border: rgba(255,255,255,0.08); --text: #F1EEF7; --text-dim: #A9A3B8;
    --violet: #9D7CF9; --violet-soft: rgba(157,124,249,0.18); --rose: #F0729C; --green: #6FCF97;
    --font-display: 'Sora', 'Segoe UI', sans-serif; --font-body: 'Inter', 'Segoe UI', sans-serif;
}
* { box-sizing: border-box; }
body { margin: 0; font-family: var(--font-body); background: var(--bg); color: var(--text); }

header {
    display: flex; justify-content: space-between; align-items: center;
    padding: 22px 48px; background: rgba(20,18,26,0.75);
    backdrop-filter: blur(14px); border-bottom: 1px solid var(--border);
    position: sticky; top: 0; z-index: 100;
}
.logo { font-family: var(--font-display); font-size: 22px; font-weight: 700; }
.logo span { color: var(--violet); }
nav { display: flex; align-items: center; gap: 4px; }
nav a, nav span {
    padding: 8px 14px; border-radius: 8px; text-decoration: none;
    color: var(--text-dim); font-size: 14px; font-weight: 500;
    transition: color 0.25s ease, background 0.25s ease;
}
nav a:hover { color: var(--text); background: var(--surface); }
nav a.cta { color: var(--bg); background: var(--text); margin-left: 6px; }
nav a.cta:hover { background: var(--violet); color: #fff; }
nav span.welcome { color: var(--violet); font-weight: 600; }

nav .nav-divider {
    display: inline-block;
    width: 1px;
    height: 18px;
    background: var(--border);
    margin: 0 4px;
    padding: 0;
}

nav a.admin-link {
    color: #CBB8FC;
    background: rgba(157, 124, 249, 0.12);
    border: 1px solid rgba(157, 124, 249, 0.25);
}

nav a.admin-link:hover {
    background: rgba(157, 124, 249, 0.22);
    color: #fff;
    border-color: rgba(157, 124, 249, 0.45);
}

nav span.admin-badge {
    background: rgba(157, 124, 249, 0.18);
    border: 1px solid rgba(157, 124, 249, 0.35);
    color: var(--violet);
    font-size: 11px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    padding: 2px 7px;
    border-radius: 6px;
    margin-left: 6px;
    display: inline-block;
}

.container { max-width: 880px; margin: 0 auto; padding: 50px 48px 100px; }

.success-banner {
    background: rgba(111,207,151,0.12); border: 1px solid rgba(111,207,151,0.35);
    color: var(--green); padding: 14px 20px; border-radius: 12px; font-size: 14px;
    margin-bottom: 28px; opacity: 0; animation: rise 0.5s ease-out forwards;
}

.page-head {
    display: flex; justify-content: space-between; align-items: flex-end; flex-wrap: wrap; gap: 16px;
    margin-bottom: 32px; opacity: 0; animation: rise 0.6s ease-out forwards;
}

h1 { font-family: var(--font-display); font-size: 28px; font-weight: 700; margin: 0; }

.points-badge {
    display: flex; align-items: center; gap: 10px;
    background: var(--violet-soft); border: 1px solid rgba(157,124,249,0.3);
    padding: 10px 18px; border-radius: 100px; font-size: 14px;
}
.points-badge strong { font-family: var(--font-display); color: var(--violet); }

.bookings-list { display: flex; flex-direction: column; gap: 16px; }

.booking-card {
    display: flex; gap: 18px; background: var(--surface); border: 1px solid var(--border);
    border-radius: 16px; padding: 18px; transition: border-color 0.25s ease, background 0.25s ease;
    opacity: 0; transform: translateY(14px); animation: rise 0.5s ease-out forwards;
}
.booking-card:hover { border-color: rgba(157,124,249,0.35); background: var(--surface-hover); }

.booking-thumb { width: 90px; height: 90px; border-radius: 12px; object-fit: cover; flex-shrink: 0; }
.booking-thumb-fallback {
    width: 90px; height: 90px; border-radius: 12px; flex-shrink: 0;
    display: flex; align-items: center; justify-content: center; font-size: 26px;
    background: linear-gradient(135deg, rgba(157,124,249,0.15), rgba(240,114,156,0.15));
}

.booking-details { flex: 1; min-width: 0; }
.booking-details h3 { font-family: var(--font-display); margin: 0 0 6px; font-size: 16px; font-weight: 600; }
.booking-meta { color: var(--text-dim); font-size: 13px; margin-bottom: 4px; }

.booking-footer {
    display: flex; justify-content: space-between; align-items: center;
    margin-top: 10px; padding-top: 10px; border-top: 1px solid var(--border);
}

.booking-ref { font-size: 12px; color: var(--text-dim); font-family: monospace; }
.booking-price { font-family: var(--font-display); font-weight: 700; font-size: 14px; }

.status-tag {
    font-size: 11px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.03em;
    padding: 4px 10px; border-radius: 100px;
}
.status-tag.confirmed { background: rgba(111,207,151,0.15); color: var(--green); }
.status-tag.cancelled { background: rgba(240,114,156,0.15); color: var(--rose); }

.resend-btn {
    margin-top: 12px;
    width: 100%;
    padding: 9px 0;
    border-radius: 100px;
    border: 1px solid var(--border);
    background: transparent;
    color: var(--text-dim);
    font-family: var(--font-body);
    font-size: 13px;
    font-weight: 500;
    cursor: pointer;
    transition: border-color 0.2s ease, color 0.2s ease, background 0.2s ease;
}

.resend-btn:hover:not(:disabled) {
    border-color: var(--violet);
    color: var(--violet);
    background: var(--violet-soft);
}

.resend-btn:disabled { opacity: 0.6; cursor: default; }

.ticket-actions { display: flex; gap: 8px; flex-wrap: wrap; margin-top: 12px; }
.ticket-actions > * { flex: 1 1 120px; margin-top: 0; width: auto; }
a.view-ticket {
    text-align: center; padding: 9px 0; border-radius: 100px; font-size: 13px; font-weight: 600;
    text-decoration: none; background: var(--text); color: var(--bg);
    transition: background 0.2s ease, color 0.2s ease;
}
a.view-ticket:hover { background: var(--violet); color: #fff; }

.delivery { display: flex; gap: 12px; flex-wrap: wrap; margin-top: 10px; font-size: 12px; color: var(--text-dim); }
.delivery .ok { color: var(--green); }
.delivery .bad { color: var(--rose); }

.success-banner small { display: block; margin-top: 4px; color: var(--text-dim); font-size: 13px; }

.payment-notice {
    display: flex; justify-content: space-between; align-items: center; gap: 12px; flex-wrap: wrap;
    padding: 14px 18px; border-radius: 12px; margin-bottom: 14px; font-size: 14px;
    background: rgba(242,197,114,0.08); border: 1px solid rgba(242,197,114,0.3); color: #F2C572;
}
.payment-notice.refunded, .payment-notice.failed { background: rgba(240,114,156,0.08); border-color: rgba(240,114,156,0.3); color: #F4A4BE; }
.payment-notice span { color: var(--text-dim); font-size: 13px; }
.payment-notice a { color: inherit; font-weight: 600; }

.empty-state { text-align: center; padding: 80px 20px; color: var(--text-dim); }
.empty-state .icon { font-size: 44px; margin-bottom: 14px; display: block; }
.empty-state a {
    display: inline-block; margin-top: 18px; padding: 12px 26px; border-radius: 100px;
    background: var(--text); color: var(--bg); text-decoration: none; font-weight: 600; font-size: 14px;
    transition: background 0.25s ease, color 0.25s ease, transform 0.25s ease;
}
.empty-state a:hover { background: var(--violet); color: #fff; transform: translateY(-2px); }

/* ---- Event status + refunds ---- */
.flash { padding: 13px 18px; border-radius: 12px; margin-bottom: 20px; font-size: 14px; }
.flash.ok  { background: rgba(111,207,151,0.12); border: 1px solid rgba(111,207,151,0.35); color: var(--green); }
.flash.err { background: rgba(240,114,156,0.12); border: 1px solid rgba(240,114,156,0.35); color: #F4A4BE; }

.event-notice { margin: 8px 0 4px; padding: 10px 12px; border-radius: 10px; font-size: 13px; line-height: 1.45; }
.event-notice b { display: block; margin-bottom: 2px; }
.event-notice span { color: var(--text-dim); }
.event-notice.red   { background: rgba(239,107,107,0.1);  border: 1px solid rgba(239,107,107,0.35); color: #FFA5A5; }
.event-notice.amber { background: rgba(242,197,114,0.08); border: 1px solid rgba(242,197,114,0.3);  color: #F2C572; }
.event-notice.blue  { background: rgba(108,160,242,0.08); border: 1px solid rgba(108,160,242,0.3);  color: #6CA0F2; }

.status-tag.refunded { background: rgba(108,160,242,0.15); color: #6CA0F2; }

.refund-box { margin-top: 12px; padding: 10px 12px; border-radius: 10px; font-size: 13px; display: flex; justify-content: space-between; align-items: center; gap: 10px; flex-wrap: wrap; }
.refund-box.pending  { background: rgba(242,197,114,0.08); border: 1px solid rgba(242,197,114,0.3); color: #F2C572; }
.refund-box.refunded { background: rgba(111,207,151,0.08); border: 1px solid rgba(111,207,151,0.3); color: var(--green); }
.refund-box.rejected { background: rgba(240,114,156,0.08); border: 1px solid rgba(240,114,156,0.3); color: #F4A4BE; }
.refund-box small { display: block; color: var(--text-dim); margin-top: 2px; font-size: 12px; }
.refund-box form { margin: 0; }
.link-btn { background: none; border: none; padding: 0; color: inherit; font: inherit; font-weight: 600; text-decoration: underline; cursor: pointer; }

.refund-btn {
    margin-top: 8px; width: 100%; padding: 9px 0; border-radius: 100px; cursor: pointer;
    font-family: var(--font-body); font-size: 13px; font-weight: 600;
    background: transparent; color: #F4A4BE; border: 1px solid rgba(240,114,156,0.4);
    transition: background 0.2s ease, color 0.2s ease;
}
.refund-btn:hover { background: rgba(240,114,156,0.15); color: #fff; }
.refund-btn.strong { background: rgba(240,114,156,0.18); color: #fff; }
.refund-closed { margin-top: 8px; font-size: 12px; color: var(--text-dim); text-align: center; }

.modal-overlay {
    position: fixed; inset: 0; background: rgba(10,8,14,0.75); backdrop-filter: blur(8px); -webkit-backdrop-filter: blur(8px);
    z-index: 2000; display: none; align-items: center; justify-content: center; padding: 16px;
}
.modal-overlay.active { display: flex; }
.modal-card {
    background: #181521; border: 1px solid rgba(255,255,255,0.12); border-radius: 20px; width: 100%; max-width: 460px;
    padding: 26px; box-shadow: 0 20px 50px rgba(0,0,0,0.6); animation: rise 0.25s ease-out; max-height: calc(100vh - 32px); overflow: auto;
}
.modal-card h3 { margin: 0 0 4px; font-family: var(--font-display); font-size: 19px; }
.modal-card .sub { margin: 0 0 16px; color: var(--text-dim); font-size: 13px; }
.modal-card .info { padding: 12px 14px; border-radius: 12px; background: rgba(255,255,255,0.03); border: 1px solid var(--border); font-size: 13px; margin-bottom: 16px; }
.modal-card .info .row { display: flex; justify-content: space-between; margin: 3px 0; color: var(--text-dim); }
.modal-card .info .row b { color: var(--text); }
.modal-card .policy { font-size: 12.5px; color: var(--text-dim); margin: 0 0 16px; line-height: 1.5; }
.modal-card label { display: block; font-size: 12px; font-weight: 600; color: var(--text-dim); margin-bottom: 6px; }
.modal-card select, .modal-card textarea {
    width: 100%; background: rgba(255,255,255,0.05); border: 1px solid var(--border); border-radius: 10px; color: var(--text);
    font-family: var(--font-body); font-size: 14px; padding: 10px 12px; outline: none; margin-bottom: 14px;
}
.modal-card select option { background: #1A1722; }
.modal-card textarea { resize: vertical; min-height: 80px; }
.modal-card select:focus, .modal-card textarea:focus { border-color: var(--violet); box-shadow: 0 0 0 3px rgba(157,124,249,0.15); }
.modal-actions { display: flex; gap: 10px; }
.modal-actions .primary {
    flex: 1; padding: 12px; border: none; border-radius: 100px; cursor: pointer; font-family: var(--font-body);
    font-weight: 700; font-size: 14px; background: var(--rose); color: #2A0A16;
}
.modal-actions .primary:disabled { opacity: 0.6; cursor: default; }
.modal-actions .secondary {
    padding: 12px 18px; background: transparent; border: 1px solid var(--border); color: var(--text-dim);
    border-radius: 100px; cursor: pointer; font-family: var(--font-body);
}

@keyframes rise { from { opacity: 0; transform: translateY(14px); } to { opacity: 1; transform: translateY(0); } }

@media (prefers-reduced-motion: reduce) {
    *, .page-head, .booking-card, .success-banner { animation: none !important; opacity: 1 !important; transform: none !important; }
}

@media (max-width: 600px) {
    header { padding: 18px 20px; }
    .container { padding: 36px 20px 70px; }
    .booking-card { flex-direction: column; }
    .booking-thumb, .booking-thumb-fallback { width: 100%; height: 140px; }
}
</style>
</head>
<body>

<?php require __DIR__ . "/nav.php"; ?>

<div class="container">
    <?php if ($justBooked): ?>
        <div class="success-banner">
            ✅ Payment received, booking confirmed — reference <strong><?php echo htmlspecialchars($justBooked); ?></strong>. Enjoy the event!
            <?php if ($sentFlags !== null): ?>
                <small>
                    <?php echo str_contains($sentFlags, 'e') ? '📧 E-ticket emailed' : '📧 Email could not be sent — use Resend below'; ?> ·
                    <?php echo str_contains($sentFlags, 's') ? '💬 SMS sent to your phone' : '💬 SMS could not be sent — use Resend below'; ?>
                </small>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <?php if ($refundOk): ?><div class="flash ok">✅ <?php echo htmlspecialchars($refundOk); ?></div><?php endif; ?>
    <?php if ($refundErr): ?><div class="flash err">⚠ <?php echo htmlspecialchars($refundErr); ?></div><?php endif; ?>

    <?php foreach ($openPayments as $p): ?>
        <div class="payment-notice <?php echo htmlspecialchars($p['status']); ?>">
            <div>
                <?php if ($p['status'] === 'pending'): ?>
                    ⏳ Checkout in progress: <?php echo (int)$p['quantity']; ?> × <?php echo htmlspecialchars($p['title']); ?>
                    <br><span>Seats held until <?php echo date("h:i A", strtotime($p['expires_at'])); ?>. Already paid? Refresh in a moment.</span>
                <?php elseif ($p['status'] === 'refunded'): ?>
                    ↩ Refunded LKR <?php echo number_format((float)$p['amount_total'], 2); ?> for <?php echo htmlspecialchars($p['title']); ?>
                    <br><span><?php echo htmlspecialchars($p['failure_reason'] ?? ''); ?></span>
                <?php else: ?>
                    ⚠ Payment for <?php echo htmlspecialchars($p['title']); ?> did not go through
                    <br><span>You have not been charged.</span>
                <?php endif; ?>
            </div>
            <?php if ($p['status'] !== 'refunded'): ?>
                <a href="tickets.php?event_id=<?php echo (int)$p['event_id']; ?>"><?php echo $p['status'] === 'pending' ? 'Back to checkout' : 'Try again'; ?> →</a>
            <?php endif; ?>
        </div>
    <?php endforeach; ?>

    <div class="page-head">
        <h1>My tickets</h1>
        <a class="points-badge" href="events.php" style="text-decoration: none; color: inherit;" title="1 point = <?php echo LOYALTY_PERCENT_PER_POINT; ?>% off your next order (up to <?php echo LOYALTY_MAX_DISCOUNT_PERCENT; ?>%)">
            🏆 <strong><?php echo $loyaltyPoints; ?></strong> loyalty points
            <?php if ($loyaltyPoints > 0): ?><span style="color: var(--text-dim); font-size: 12.5px;">· worth up to <?php echo loyalty_discount_percent($loyaltyPoints); ?>% off your next order</span><?php endif; ?>
        </a>
    </div>

    <div class="bookings-list">
    <?php if (count($bookings) === 0): ?>
        <div class="empty-state">
            <span class="icon">🎟️</span>
            <p>You haven't booked any tickets yet.</p>
            <a href="events.php">Browse events</a>
        </div>
    <?php else: ?>
        <?php foreach ($bookings as $index => $booking):
            $delay = min($index * 0.06, 0.5);
        ?>
        <div class="booking-card" style="animation-delay: <?php echo $delay; ?>s;">
            <?php if (!empty($booking['photo']) && file_exists(__DIR__ . "/eventphotos/" . $booking['photo'])): ?>
                <img class="booking-thumb" src="eventphotos/<?php echo htmlspecialchars($booking['photo']); ?>" alt="<?php echo htmlspecialchars($booking['title']); ?>">
            <?php else: ?>
                <div class="booking-thumb-fallback">🎫</div>
            <?php endif; ?>
            <div class="booking-details">
                <h3><?php echo htmlspecialchars($booking['title']); ?></h3>
                <div class="booking-meta">📍 <?php echo htmlspecialchars($booking['location']); ?></div>
                <div class="booking-meta">🗓 <?php echo date("M d, Y · h:i A", strtotime($booking['event_date'])); ?></div>
                <?php
                    $evStatus   = event_status_of(['status' => $booking['event_status']]);
                    $req        = $booking['refund_request'];
                    $isRefunded = $req && $req['status'] === 'refunded';
                ?>
                <?php if (in_array($evStatus, ['cancelled', 'postponed', 'rescheduled'], true) && $booking['status'] === 'confirmed'): ?>
                    <div class="event-notice <?php echo $evStatus === 'cancelled' ? 'red' : ($evStatus === 'rescheduled' ? 'blue' : 'amber'); ?>">
                        <?php if ($evStatus === 'cancelled'): ?>
                            <b>⛔ This event has been cancelled</b>
                        <?php elseif ($evStatus === 'postponed'): ?>
                            <b>⏳ This event has been postponed — new date to be announced</b>
                        <?php else: ?>
                            <b>📅 New date: <?php echo date("l, M d, Y · h:i A", strtotime($booking['event_date'])); ?></b>
                            <?php if ($booking['original_event_date']): ?><span>Originally <?php echo date("M d, Y · h:i A", strtotime($booking['original_event_date'])); ?>. Your ticket is still valid.</span><br><?php endif; ?>
                        <?php endif; ?>
                        <?php if ($booking['status_note']): ?><span>Organizer: “<?php echo htmlspecialchars($booking['status_note']); ?>”</span><?php endif; ?>
                    </div>
                <?php endif; ?>
                <div class="booking-meta"><?php echo (int)$booking['quantity']; ?> ticket<?php echo (int)$booking['quantity'] === 1 ? '' : 's'; ?> · +<?php echo (int)$booking['points_earned']; ?> points earned<?php if ((float)$booking['discount_amount'] > 0): ?> · <span style="color: var(--green);">🏆 saved LKR <?php echo number_format((float)$booking['discount_amount'], 2); ?> with <?php echo (int)$booking['points_redeemed']; ?> points</span><?php endif; ?></div>
                <div class="booking-footer">
                    <span class="booking-ref"><?php echo htmlspecialchars($booking['booking_ref']); ?></span>
                    <?php $tag = $isRefunded ? 'refunded' : $booking['status']; ?><span class="status-tag <?php echo htmlspecialchars($tag); ?>"><?php echo htmlspecialchars($tag); ?></span>
                    <span class="booking-price">LKR <?php echo number_format($booking['total_price'], 2); ?></span>
                </div>
                <?php if ($booking['status'] === 'confirmed'): ?>
                    <div class="delivery">
                        <?php if (!empty($booking['email_sent_at'])): ?>
                            <span class="ok">📧 Emailed <?php echo date("M d, h:i A", strtotime($booking['email_sent_at'])); ?></span>
                        <?php elseif ($booking['payment_id']): ?>
                            <span class="bad">📧 Not emailed yet</span>
                        <?php endif; ?>
                        <?php if (!empty($booking['sms_sent_at'])): ?>
                            <span class="ok">💬 SMS to <?php echo htmlspecialchars(phone_masked($booking['phone'])); ?></span>
                        <?php elseif ($booking['phone']): ?>
                            <span class="bad">💬 SMS not delivered yet</span>
                        <?php endif; ?>
                    </div>
                    <div class="ticket-actions">
                        <a class="view-ticket" href="<?php echo htmlspecialchars($booking['ticket_url']); ?>">🎟️ View e-ticket</a>
                        <button class="resend-btn" data-channel="email" data-ref="<?php echo htmlspecialchars($booking['booking_ref']); ?>">📧 Resend email</button>
                        <button class="resend-btn" data-channel="sms" data-ref="<?php echo htmlspecialchars($booking['booking_ref']); ?>"
                                data-phone="<?php echo htmlspecialchars(phone_display($booking['phone'])); ?>">💬 <?php echo $booking['phone'] ? 'Resend SMS' : 'Send SMS'; ?></button>
                    </div>
                <?php endif; ?>

                <?php if ($req && $req['status'] === 'pending'): ?>
                    <div class="refund-box pending">
                        <div>⏳ Refund requested · LKR <?php echo number_format((float)$req['amount_requested'], 2); ?>
                            <small>Sent <?php echo date("M d, h:i A", strtotime($req['created_at'])); ?> · waiting for the organizer to review. Your tickets stay valid until then.</small></div>
                        <form method="POST" onsubmit="return confirm('Withdraw your refund request? Your tickets stay valid.');">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                            <input type="hidden" name="action" value="withdraw_refund">
                            <input type="hidden" name="request_id" value="<?php echo (int)$req['id']; ?>">
                            <button type="submit" class="link-btn">Withdraw</button>
                        </form>
                    </div>
                <?php elseif ($isRefunded): ?>
                    <div class="refund-box refunded">
                        <div>✅ Refunded LKR <?php echo number_format((float)$req['refund_amount'], 2); ?> on <?php echo date("M d, Y", strtotime($req['reviewed_at'])); ?>
                            <small><?php echo $req['refund_method'] === 'stripe' ? 'Back to your card — it can take 5–10 business days to appear.' : 'Refunded directly by the organizer.'; ?><?php echo $req['admin_note'] ? ' “' . htmlspecialchars($req['admin_note']) . '”' : ''; ?></small></div>
                    </div>
                <?php elseif ($req && $req['status'] === 'rejected' && $booking['status'] === 'confirmed'): ?>
                    <div class="refund-box rejected">
                        <div>✕ Refund request declined
                            <small><?php echo $req['admin_note'] ? '“' . htmlspecialchars($req['admin_note']) . '”' : 'The organizer declined this request.'; ?></small></div>
                    </div>
                <?php endif; ?>

                <?php if ($booking['status'] === 'confirmed' && !($req && $req['status'] === 'pending')): ?>
                    <?php if ($booking['refund']['ok']): ?>
                        <button type="button" class="refund-btn <?php echo in_array($evStatus, ['cancelled', 'postponed'], true) ? 'strong' : ''; ?>"
                                data-booking="<?php echo htmlspecialchars(json_encode([
                                    'id'     => (int)$booking['id'],
                                    'title'  => $booking['title'],
                                    'ref'    => $booking['booking_ref'],
                                    'qty'    => (int)$booking['quantity'],
                                    'amount' => number_format((float)$booking['total_price'], 2),
                                    'points' => (int)$booking['points_earned'],
                                    'redeemed' => (int)$booking['points_redeemed'],
                                    'why'    => $booking['refund']['why'],
                                    'reason' => $booking['refund']['suggested_reason'],
                                    'status' => $evStatus,
                                ])); ?>">
                            ↩ <?php echo ($req && $req['status'] === 'rejected') ? 'Request refund again' : 'Request refund'; ?>
                        </button>
                    <?php elseif ((float)$booking['total_price'] > 0 && strtotime($booking['event_date']) > time() && empty($booking['checked_in_at'])): ?>
                        <div class="refund-closed">ℹ <?php echo htmlspecialchars($booking['refund']['why']); ?></div>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>
        <?php endforeach; ?>
    <?php endif; ?>
    </div>
</div>

<div class="modal-overlay" id="refundModal" onclick="if (event.target === this) closeRefund();">
    <div class="modal-card" role="dialog" aria-modal="true" aria-labelledby="rf_title">
        <h3 id="rf_title">↩ Request a refund</h3>
        <p class="sub" id="rf_event"></p>
        <div class="info">
            <div class="row"><span>Booking</span><b id="rf_ref"></b></div>
            <div class="row"><span>Tickets</span><b id="rf_qty"></b></div>
            <div class="row"><span>You paid</span><b id="rf_amount"></b></div>
        </div>
        <p class="policy" id="rf_why"></p>
        <form method="POST" id="refundForm">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
            <input type="hidden" name="action" value="request_refund">
            <input type="hidden" name="booking_id" id="rf_id">
            <label for="rf_reason">Why do you need a refund?</label>
            <select name="reason" id="rf_reason" required>
                <?php foreach (REFUND_REASONS as $key => $label): ?>
                    <option value="<?php echo $key; ?>"><?php echo htmlspecialchars($label); ?></option>
                <?php endforeach; ?>
            </select>
            <label for="rf_message">Anything the organizer should know? <span id="rf_optional">(optional)</span></label>
            <textarea name="message" id="rf_message" maxlength="1000" placeholder="e.g. I'm travelling on the new date."></textarea>
            <p class="policy" id="rf_effects"></p>
            <div class="modal-actions">
                <button type="submit" class="primary">Send refund request</button>
                <button type="button" class="secondary" onclick="closeRefund();">Cancel</button>
            </div>
        </form>
    </div>
</div>

<script>
// ---- Refund request modal ----
const EVENT_SIDE_REASONS = { event_cancelled: 'cancelled', event_postponed: 'postponed', event_rescheduled: 'rescheduled' };
const rfReason  = document.getElementById('rf_reason');
const rfMessage = document.getElementById('rf_message');

function syncRefundReason() {
    const other = rfReason.value === 'other';
    rfMessage.required = other;
    document.getElementById('rf_optional').textContent = other ? '(required)' : '(optional)';
}
rfReason.addEventListener('change', syncRefundReason);

function openRefund(b) {
    document.getElementById('rf_id').value = b.id;
    document.getElementById('rf_event').textContent = b.title;
    document.getElementById('rf_ref').textContent = b.ref;
    document.getElementById('rf_qty').textContent = b.qty;
    document.getElementById('rf_amount').textContent = 'LKR ' + b.amount;
    document.getElementById('rf_why').textContent = b.why;
    document.getElementById('rf_effects').textContent =
        'If the refund is approved, this booking is cancelled and its QR code stops working' +
        (b.points ? `, and the ${b.points} loyalty points it earned are removed.` : '.') +
        (b.redeemed ? ` The ${b.redeemed} points you used for a discount are given back.` : '');
    // Only offer "event was cancelled / postponed / rescheduled" when that actually happened
    [...rfReason.options].forEach(opt => {
        const needs = EVENT_SIDE_REASONS[opt.value];
        opt.hidden = opt.disabled = !!needs && needs !== b.status;
    });
    rfReason.value = b.reason || 'cant_attend';
    rfMessage.value = '';
    syncRefundReason();
    document.getElementById('refundModal').classList.add('active');
    rfReason.focus();
}
function closeRefund() { document.getElementById('refundModal').classList.remove('active'); }
document.querySelectorAll('.refund-btn').forEach(btn => btn.addEventListener('click', () => openRefund(JSON.parse(btn.dataset.booking))));
document.addEventListener('keydown', e => { if (e.key === 'Escape') closeRefund(); });
document.getElementById('refundForm').addEventListener('submit', e => {
    const submit = e.target.querySelector('.primary');
    submit.disabled = true;
    submit.textContent = 'Sending…';
});

document.querySelectorAll('.resend-btn').forEach(btn => {
    btn.addEventListener('click', async () => {
        const ref = btn.dataset.ref;
        const channel = btn.dataset.channel;
        const originalText = btn.textContent;
        const body = new URLSearchParams({ booking_ref: ref, channel, csrf_token: <?php echo json_encode($csrfToken); ?> });

        if (channel === 'sms') {
            const phone = prompt('Send the e-ticket link by SMS to:', btn.dataset.phone || '');
            if (phone === null) return;
            body.set('phone', phone);
        }

        btn.disabled = true;
        btn.textContent = 'Sending…';

        try {
            const response = await fetch('resend_ticket_email.php', { method: 'POST', body });
            const result = await response.json();

            if (result.ok) {
                btn.textContent = channel === 'sms' ? '✅ SMS sent to ' + result.sent_to : '✅ Sent! Check your inbox';
                if (channel === 'sms' && result.sent_to) btn.dataset.phone = body.get('phone');
            } else {
                btn.textContent = '⚠ ' + (result.error || 'Failed to send');
            }
        } catch (err) {
            btn.textContent = '⚠ Network error — try again';
        }

        setTimeout(() => {
            btn.disabled = false;
            btn.textContent = originalText;
        }, 3500);
    });
});
</script>

<?php require __DIR__ . "/footer.php"; ?>
</body>
</html>