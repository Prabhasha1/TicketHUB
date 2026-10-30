<?php
/**
 * customer_details.php?id=N
 * ------------------------------------------------------------
 * Super Admin only: everything about one customer.
 *   - profile + lifetime summary (spend, tickets, attendance, refunds)
 *   - favourite categories
 *   - every booking with its event, check-in, delivery and refund state
 *   - every checkout attempt (paid / failed / expired / refunded)
 *   - refund requests, loyalty point history, door scans
 * ------------------------------------------------------------
 */
session_start();
require __DIR__ . "/config/database.php";
require __DIR__ . "/config/app.php";
require __DIR__ . "/config/event_status.php";

if (($_SESSION['user_role'] ?? '') !== 'super_admin') {
    header("Location: " . (isset($_SESSION['user_id']) ? "index.php" : "login.php"));
    exit;
}

$customerId = (int)($_GET['id'] ?? 0);
$stmt = $conn->prepare("SELECT id, name, email, phone, role, loyalty_points, created_at FROM users WHERE id = ?");
$stmt->bind_param("i", $customerId);
$stmt->execute();
$customer = $stmt->get_result()->fetch_assoc();
$stmt->close();
if (!$customer) {
    header("Location: customers.php");
    exit;
}

function fetch_all_for(mysqli $conn, string $sql, int $id): array
{
    try {
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        return $rows;
    } catch (mysqli_sql_exception $e) {
        error_log("customer_details query failed: " . $e->getMessage());
        return [];
    }
}

$bookings = fetch_all_for($conn,
    "SELECT b.*, e.title, e.event_date, e.location, e.category, e.status AS event_status, e.photo,
            org.name AS organizer,
            (SELECT r.status FROM refund_requests r WHERE r.booking_id = b.id ORDER BY r.id DESC LIMIT 1) AS refund_status,
            (SELECT r.refund_amount FROM refund_requests r WHERE r.booking_id = b.id AND r.status = 'refunded' ORDER BY r.id DESC LIMIT 1) AS refund_amount
     FROM bookings b
     JOIN events e ON e.id = b.event_id
     LEFT JOIN users org ON org.id = e.created_by
     WHERE b.user_id = ?
     ORDER BY b.created_at DESC", $customerId);

$payments = fetch_all_for($conn,
    "SELECT p.*, e.title FROM payments p JOIN events e ON e.id = p.event_id
     WHERE p.user_id = ? ORDER BY p.created_at DESC LIMIT 50", $customerId);

$refunds = fetch_all_for($conn,
    "SELECT r.*, b.booking_ref, e.title, rv.name AS reviewer FROM refund_requests r
     JOIN bookings b ON b.id = r.booking_id JOIN events e ON e.id = r.event_id
     LEFT JOIN users rv ON rv.id = r.reviewed_by
     WHERE r.user_id = ? ORDER BY r.created_at DESC", $customerId);

$points = fetch_all_for($conn,
    "SELECT points, reason, created_at FROM loyalty_transactions WHERE user_id = ? ORDER BY created_at DESC, id DESC LIMIT 30", $customerId);

$favourites = fetch_all_for($conn,
    "SELECT category, score FROM user_category_affinity WHERE user_id = ? AND score > 0 ORDER BY score DESC LIMIT 6", $customerId);

$scans = fetch_all_for($conn,
    "SELECT t.direction, t.accepted, t.result, t.scanned_at, b.booking_ref, e.title
     FROM ticket_scans t JOIN bookings b ON b.id = t.booking_id JOIN events e ON e.id = t.event_id
     WHERE b.user_id = ? ORDER BY t.scanned_at DESC LIMIT 20", $customerId);

// ---- Summary ----
$s = ['bookings' => 0, 'tickets' => 0, 'spent' => 0.0, 'commission' => 0.0, 'attended' => 0, 'upcoming' => 0, 'events' => [],
      'cancelled' => 0, 'refunded' => 0.0, 'refunds' => 0, 'pending_refunds' => 0];
foreach ($bookings as $b) {
    if ($b['status'] !== 'confirmed') { $s['cancelled']++; continue; }
    $s['bookings']++;
    $s['tickets']    += (int)$b['quantity'];
    $s['spent']      += (float)$b['total_price'];
    $s['commission'] += (float)$b['commission_amount'];
    $s['events'][$b['event_id']] = true;
    if ($b['checked_in_at']) { $s['attended']++; }
    if (strtotime($b['event_date']) >= time()) { $s['upcoming']++; }
}
foreach ($refunds as $r) {
    if ($r['status'] === 'refunded') { $s['refunds']++; $s['refunded'] += (float)$r['refund_amount']; }
    if ($r['status'] === 'pending')  { $s['pending_refunds']++; }
}
$pastBookings = count(array_filter($bookings, fn($b) => $b['status'] === 'confirmed' && strtotime($b['event_date']) < time()));
$attendRate   = $pastBookings ? $s['attended'] / $pastBookings * 100 : null;
$paidCount    = count(array_filter($payments, fn($p) => in_array($p['status'], ['paid', 'refunded'], true)));
$finishedPay  = count(array_filter($payments, fn($p) => $p['status'] !== 'pending'));
$checkoutRate = $finishedPay ? $paidCount / $finishedPay * 100 : null;
$lastPurchase = null;
foreach ($bookings as $b) { if ($b['status'] === 'confirmed') { $lastPurchase = $b['created_at']; break; } }
$favMax = $favourites ? max(array_map(fn($f) => (float)$f['score'], $favourites)) : 1;

$initials = '';
foreach (array_slice(preg_split('/\s+/', trim($customer['name'])), 0, 2) as $part) { $initials .= mb_strtoupper(mb_substr($part, 0, 1)); }
$roleLabel = ['super_admin' => '👑 Super Admin', 'admin' => 'Admin', 'customer' => 'Customer'][$customer['role']] ?? $customer['role'];
$paymentTone = ['paid' => 'ok', 'pending' => 'info', 'failed' => 'bad', 'expired' => 'dim', 'cancelled' => 'dim', 'refunded' => 'warn'];
$refundTone  = ['pending' => 'warn', 'refunded' => 'ok', 'rejected' => 'bad', 'withdrawn' => 'dim'];

function lkr(float $n): string { return 'LKR ' . number_format($n, 2); }
function when(?string $ts): string { return $ts ? date('M d, Y · h:i A', strtotime($ts)) : '—'; }
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<?php include __DIR__ . "/pwa_head.php"; ?>
<title><?php echo htmlspecialchars($customer['name']); ?> - Customer - TicketHub</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Sora:wght@600;700;800&family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
<style>
:root {
    --bg: #14121A; --surface: rgba(255,255,255,0.04); --border: rgba(255,255,255,0.08);
    --text: #F1EEF7; --text-dim: #A9A3B8; --text-muted: #7D7790;
    --violet: #9D7CF9; --rose: #F0729C; --blue: #6CA0F2; --red: #EF6B6B; --amber: #F2C572; --green: #6FCF97;
    --font-display: 'Sora', 'Segoe UI', sans-serif; --font-body: 'Inter', 'Segoe UI', sans-serif;
}
* { box-sizing: border-box; }
body { margin: 0; font-family: var(--font-body); background: var(--bg); color: var(--text); }
.container { max-width: 1180px; margin: 0 auto; padding: 36px 48px 100px; }
.crumbs { font-size: 13px; color: var(--text-dim); margin-bottom: 18px; }
.crumbs a { color: var(--text-dim); text-decoration: none; }
.crumbs a:hover { color: var(--text); }

.hero {
    display: flex; align-items: center; gap: 22px; flex-wrap: wrap; padding: 24px; border-radius: 20px; margin-bottom: 22px;
    background: linear-gradient(135deg, rgba(157,124,249,0.16), rgba(240,114,156,0.08)); border: 1px solid rgba(157,124,249,0.3);
    animation: rise 0.5s ease-out both;
}
.avatar {
    width: 76px; height: 76px; border-radius: 50%; flex-shrink: 0; display: flex; align-items: center; justify-content: center;
    font-family: var(--font-display); font-size: 27px; font-weight: 700; color: #fff; background: linear-gradient(135deg, var(--violet), var(--rose));
}
.hero h1 { font-family: var(--font-display); font-size: 26px; margin: 0 0 6px; }
.hero .sub { color: var(--text-dim); font-size: 13.5px; display: flex; gap: 6px 16px; flex-wrap: wrap; }
.hero .sub a { color: var(--text); }
.hero .actions { margin-left: auto; display: flex; gap: 8px; flex-wrap: wrap; }
.btn {
    display: inline-flex; align-items: center; gap: 6px; padding: 9px 16px; border-radius: 100px; font-weight: 600; font-size: 13px;
    text-decoration: none; color: var(--text); border: 1px solid var(--border); background: rgba(255,255,255,0.06);
}
.btn:hover { background: rgba(255,255,255,0.12); }
.badge { font-size: 11px; font-weight: 700; padding: 3px 9px; border-radius: 100px; background: rgba(157,124,249,0.2); color: #CBB8FC; }

.kpis { display: grid; grid-template-columns: repeat(4, 1fr); gap: 16px; margin-bottom: 22px; animation: rise 0.5s 0.05s ease-out both; }
.kpi { background: var(--surface); border: 1px solid var(--border); border-radius: 16px; padding: 16px 18px; }
.kpi .label { font-size: 12px; font-weight: 600; letter-spacing: 0.5px; text-transform: uppercase; color: var(--text-dim); margin-bottom: 6px; }
.kpi .value { font-family: var(--font-display); font-size: 22px; font-weight: 700; }
.kpi .value small { font-size: 12px; color: var(--text-dim); margin-right: 3px; }
.kpi .hint { font-size: 12px; color: var(--text-dim); margin-top: 5px; }

.grid-3 { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 22px; margin-bottom: 22px; }
.panel { background: var(--surface); border: 1px solid var(--border); border-radius: 18px; padding: 20px 22px; min-width: 0; margin-bottom: 22px; animation: rise 0.5s 0.1s ease-out both; }
.grid-3 .panel { margin-bottom: 0; }
.panel h3 { font-family: var(--font-display); font-size: 16px; margin: 0 0 4px; }
.panel .sub { font-size: 12.5px; color: var(--text-dim); margin: 0 0 14px; }

.fav { margin-bottom: 10px; font-size: 13px; }
.fav .top { display: flex; justify-content: space-between; margin-bottom: 4px; }
.fav .track { height: 6px; border-radius: 100px; background: rgba(255,255,255,0.07); overflow: hidden; }
.fav .fill { height: 100%; border-radius: 100px; background: var(--violet); }

.rows .row { display: flex; justify-content: space-between; gap: 10px; padding: 8px 0; border-bottom: 1px solid rgba(255,255,255,0.05); font-size: 13px; }
.rows .row:last-child { border-bottom: none; }
.rows .row > span { color: var(--text-dim); }
.rows .row small { display: block; color: var(--text-muted); font-size: 11.5px; }
.plus { color: var(--green); font-weight: 700; white-space: nowrap; }
.minus { color: var(--rose); font-weight: 700; white-space: nowrap; }
.scroll { max-height: 300px; overflow: auto; }

.table-wrap { overflow-x: auto; margin: 0 -22px; padding: 0 22px; }
table { width: 100%; border-collapse: collapse; font-size: 13px; }
th { text-align: left; font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; color: var(--text-muted); padding: 10px; border-bottom: 1px solid var(--border); white-space: nowrap; }
td { padding: 11px 10px; border-bottom: 1px solid rgba(255,255,255,0.05); vertical-align: top; }
tbody tr:hover td { background: rgba(255,255,255,0.025); }
td.num, th.num { text-align: right; white-space: nowrap; }
td a { color: var(--text); text-decoration: none; font-weight: 600; }
td a:hover { color: var(--violet); }
td .muted { color: var(--text-dim); font-size: 12px; font-weight: 400; }
.mono { font-family: ui-monospace, Consolas, monospace; font-size: 12.5px; }
.pill { display: inline-flex; align-items: center; gap: 4px; font-size: 11.5px; font-weight: 600; padding: 3px 9px; border-radius: 100px; white-space: nowrap; border: 1px solid transparent; margin: 0 4px 4px 0; }
.pill.ok   { color: var(--green); background: rgba(111,207,151,0.12); border-color: rgba(111,207,151,0.3); }
.pill.bad  { color: #FFA5A5; background: rgba(239,107,107,0.12); border-color: rgba(239,107,107,0.3); }
.pill.info { color: var(--blue); background: rgba(108,160,242,0.12); border-color: rgba(108,160,242,0.3); }
.pill.warn { color: var(--amber); background: rgba(242,197,114,0.12); border-color: rgba(242,197,114,0.3); }
.pill.dim  { color: var(--text-dim); background: rgba(255,255,255,0.05); border-color: var(--border); }
.pill.green { color: var(--green); } .pill.amber { color: var(--amber); } .pill.blue { color: var(--blue); } .pill.red { color: #FFA5A5; }
.empty { text-align: center; padding: 28px 10px; color: var(--text-dim); font-size: 13.5px; }

.footer { text-align: center; padding: 28px; border-top: 1px solid var(--border); color: var(--text-dim); font-size: 13px; }
@keyframes rise { from { opacity: 0; transform: translateY(12px); } to { opacity: 1; transform: translateY(0); } }
@media (prefers-reduced-motion: reduce) { * { animation: none !important; transition: none !important; } }
@media (max-width: 980px) { .kpis { grid-template-columns: repeat(2, 1fr); } .grid-3 { grid-template-columns: 1fr; } .hero .actions { margin-left: 0; } }
@media (max-width: 640px) { .container { padding: 24px 16px 70px; } .kpis { grid-template-columns: 1fr; } .table-wrap { margin: 0 -16px; padding: 0 16px; } }
</style>
</head>
<body>
<?php require __DIR__ . "/nav.php"; ?>

<div class="container">
    <div class="crumbs"><a href="customers.php">← Customers</a> / <?php echo htmlspecialchars($customer['name']); ?></div>

    <section class="hero">
        <div class="avatar" aria-hidden="true"><?php echo htmlspecialchars($initials ?: '?'); ?></div>
        <div>
            <h1><?php echo htmlspecialchars($customer['name']); ?> <?php if ($customer['role'] !== 'customer'): ?><span class="badge"><?php echo $roleLabel; ?></span><?php endif; ?></h1>
            <div class="sub">
                <span>✉ <a href="mailto:<?php echo htmlspecialchars($customer['email']); ?>"><?php echo htmlspecialchars($customer['email']); ?></a></span>
                <span>📱 <?php echo $customer['phone'] ? '<a href="tel:+' . htmlspecialchars($customer['phone']) . '">' . htmlspecialchars(phone_display($customer['phone'])) . '</a>' : 'No phone'; ?></span>
                <span>🗓 Joined <?php echo date('M d, Y', strtotime($customer['created_at'])); ?></span>
                <span>🛒 Last purchase: <?php echo $lastPurchase ? date('M d, Y', strtotime($lastPurchase)) : 'never'; ?></span>
                <span>#<?php echo (int)$customer['id']; ?></span>
            </div>
        </div>
        <div class="actions">
            <a class="btn" href="mailto:<?php echo htmlspecialchars($customer['email']); ?>">✉ Email</a>
            <?php if ($s['pending_refunds']): ?><a class="btn" href="refund_requests.php" style="color: #FFC2D4; border-color: rgba(240,114,156,0.4);">🔔 <?php echo $s['pending_refunds']; ?> refund request(s)</a><?php endif; ?>
        </div>
    </section>

    <section class="kpis">
        <div class="kpi"><div class="label">💳 Lifetime spend</div><div class="value"><small>LKR</small><?php echo number_format($s['spent'], 2); ?></div>
            <div class="hint">TicketHUB commission: <?php echo lkr($s['commission']); ?></div></div>
        <div class="kpi"><div class="label">🎟 Tickets</div><div class="value"><?php echo number_format($s['tickets']); ?></div>
            <div class="hint"><?php echo $s['bookings']; ?> booking(s) · <?php echo count($s['events']); ?> event(s)</div></div>
        <div class="kpi"><div class="label">✅ Attendance</div><div class="value"><?php echo $attendRate === null ? '—' : number_format($attendRate, 0) . '<small style="margin-left:2px;">%</small>'; ?></div>
            <div class="hint"><?php echo $s['attended']; ?> of <?php echo $pastBookings; ?> past booking(s) checked in · <?php echo $s['upcoming']; ?> upcoming</div></div>
        <div class="kpi"><div class="label">🏆 Loyalty points</div><div class="value"><?php echo number_format((int)$customer['loyalty_points']); ?></div>
            <div class="hint">Checkout success: <?php echo $checkoutRate === null ? '—' : number_format($checkoutRate, 0) . '%'; ?></div></div>
        <div class="kpi"><div class="label">↩ Refunded</div><div class="value"><small>LKR</small><?php echo number_format($s['refunded'], 2); ?></div>
            <div class="hint"><?php echo $s['refunds']; ?> refund(s) · <?php echo $s['pending_refunds']; ?> pending</div></div>
        <div class="kpi"><div class="label">🧾 Avg order</div><div class="value"><small>LKR</small><?php echo number_format($s['bookings'] ? $s['spent'] / $s['bookings'] : 0, 2); ?></div>
            <div class="hint"><?php echo $s['bookings'] ? number_format($s['tickets'] / $s['bookings'], 1) : 0; ?> ticket(s) per booking</div></div>
        <div class="kpi"><div class="label">💸 Checkouts</div><div class="value"><?php echo count($payments); ?></div>
            <div class="hint"><?php echo $paidCount; ?> paid · <?php echo count(array_filter($payments, fn($p) => in_array($p['status'], ['failed', 'expired'], true))); ?> failed/expired</div></div>
        <div class="kpi"><div class="label">✕ Cancelled bookings</div><div class="value"><?php echo $s['cancelled']; ?></div>
            <div class="hint">Refunded or cancelled</div></div>
    </section>

    <div class="grid-3">
        <section class="panel">
            <h3>❤️ Favourite categories</h3>
            <p class="sub">From what they book.</p>
            <?php if (!$favourites): ?><div class="empty">No bookings yet.</div><?php endif; ?>
            <?php foreach ($favourites as $f): ?>
                <div class="fav">
                    <div class="top"><span><?php echo htmlspecialchars($f['category']); ?></span><span style="color: var(--text-dim);"><?php echo rtrim(rtrim($f['score'], '0'), '.'); ?></span></div>
                    <div class="track"><div class="fill" style="width: <?php echo (float)$f['score'] / $favMax * 100; ?>%;"></div></div>
                </div>
            <?php endforeach; ?>
        </section>

        <section class="panel">
            <h3>🏆 Loyalty history</h3>
            <p class="sub">Points earned and taken back.</p>
            <?php if (!$points): ?><div class="empty">No points yet.</div><?php else: ?>
            <div class="rows scroll">
                <?php foreach ($points as $p): ?>
                    <div class="row"><span><?php echo htmlspecialchars($p['reason']); ?><small><?php echo when($p['created_at']); ?></small></span>
                        <b class="<?php echo (int)$p['points'] >= 0 ? 'plus' : 'minus'; ?>"><?php echo ((int)$p['points'] >= 0 ? '+' : '') . (int)$p['points']; ?></b></div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </section>

        <section class="panel">
            <h3>📷 Door activity</h3>
            <p class="sub">Their latest ticket scans.</p>
            <?php if (!$scans): ?><div class="empty">Never scanned at an event.</div><?php else: ?>
            <div class="rows scroll">
                <?php foreach ($scans as $sc): ?>
                    <div class="row"><span><?php echo htmlspecialchars($sc['title']); ?><small><?php echo when($sc['scanned_at']); ?> · <?php echo htmlspecialchars($sc['booking_ref']); ?></small></span>
                        <span class="pill <?php echo $sc['accepted'] ? 'ok' : 'bad'; ?>"><?php echo $sc['direction'] === 'in' ? '⬇ In' : '⬆ Out'; ?><?php echo $sc['accepted'] ? '' : ' · refused'; ?></span></div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </section>
    </div>

    <section class="panel">
        <h3>🎟 Bookings & events</h3>
        <p class="sub"><?php echo count($bookings); ?> booking(s), newest first.</p>
        <?php if (!$bookings): ?><div class="empty">This customer hasn't booked anything yet.</div><?php else: ?>
        <div class="table-wrap">
            <table>
                <thead><tr><th>Event</th><th>Booking</th><th class="num">Qty</th><th class="num">Paid</th><th>Status</th><th>Attendance</th><th>E-ticket</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($bookings as $b):
                    $ev = EVENT_STATUSES[$b['event_status']] ?? EVENT_STATUSES['on_sale'];
                    $past = strtotime($b['event_date']) < time(); ?>
                    <tr>
                        <td>
                            <a href="event_details.php?id=<?php echo (int)$b['event_id']; ?>"><?php echo htmlspecialchars($b['title']); ?></a>
                            <div class="muted">🗓 <?php echo when($b['event_date']); ?> · <?php echo htmlspecialchars($b['category']); ?></div>
                            <div class="muted">📍 <?php echo htmlspecialchars($b['location']); ?> · by <?php echo htmlspecialchars($b['organizer'] ?? '—'); ?></div>
                            <?php if ($b['event_status'] !== 'on_sale'): ?><span class="pill dim <?php echo $ev['tone']; ?>" style="margin-top: 4px;"><?php echo $ev['icon'] . ' ' . $ev['label']; ?></span><?php endif; ?>
                        </td>
                        <td><span class="mono"><?php echo htmlspecialchars($b['booking_ref']); ?></span><div class="muted"><?php echo when($b['created_at']); ?></div></td>
                        <td class="num"><?php echo (int)$b['quantity']; ?></td>
                        <td class="num"><?php echo lkr((float)$b['total_price']); ?><div class="muted">+<?php echo (int)$b['points_earned']; ?> pts</div></td>
                        <td>
                            <?php if ($b['status'] === 'confirmed'): ?><span class="pill ok">✓ Confirmed</span>
                            <?php elseif ($b['refund_status'] === 'refunded'): ?><span class="pill warn">↩ Refunded <?php echo lkr((float)$b['refund_amount']); ?></span>
                            <?php else: ?><span class="pill bad">✕ Cancelled</span><?php endif; ?>
                            <?php if ($b['refund_status'] === 'pending'): ?><span class="pill warn">⏳ Refund requested</span><?php endif; ?>
                            <?php if ($b['refund_status'] === 'rejected' && $b['status'] === 'confirmed'): ?><span class="pill dim">Refund declined</span><?php endif; ?>
                        </td>
                        <td>
                            <?php if ($b['is_inside']): ?><span class="pill ok">● Inside now</span>
                            <?php elseif ($b['checked_in_at']): ?><span class="pill ok">✓ Attended</span><div class="muted"><?php echo when($b['checked_in_at']); ?></div>
                            <?php elseif ($b['status'] !== 'confirmed'): ?><span class="muted">—</span>
                            <?php elseif ($past): ?><span class="pill bad">No-show</span>
                            <?php else: ?><span class="pill info">Upcoming</span><?php endif; ?>
                        </td>
                        <td>
                            <span class="pill <?php echo $b['email_sent_at'] ? 'ok' : 'dim'; ?>">📧 <?php echo $b['email_sent_at'] ? 'Sent' : 'No'; ?></span>
                            <?php if ($b['phone']): ?><span class="pill <?php echo $b['sms_sent_at'] ? 'ok' : 'dim'; ?>">💬 <?php echo $b['sms_sent_at'] ? 'Sent' : 'No'; ?></span><?php endif; ?>
                        </td>
                        <td><?php if ($b['ticket_token']): ?><a class="mono" style="color: var(--violet);" target="_blank" rel="noopener" href="view_ticket.php?ref=<?php echo urlencode($b['booking_ref']); ?>&t=<?php echo urlencode($b['ticket_token']); ?>">Ticket ↗</a><?php endif; ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </section>

    <section class="panel">
        <h3>💳 Payments & checkouts</h3>
        <p class="sub">Every Stripe checkout they started (latest 50) — including ones that didn't go through.</p>
        <?php if (!$payments): ?><div class="empty">No checkouts yet.</div><?php else: ?>
        <div class="table-wrap">
            <table>
                <thead><tr><th>Started</th><th>Event</th><th class="num">Qty</th><th class="num">Amount</th><th>Status</th><th>Details</th></tr></thead>
                <tbody>
                <?php foreach ($payments as $p): ?>
                    <tr>
                        <td><?php echo when($p['created_at']); ?></td>
                        <td><a href="event_details.php?id=<?php echo (int)$p['event_id']; ?>"><?php echo htmlspecialchars($p['title']); ?></a></td>
                        <td class="num"><?php echo (int)$p['quantity']; ?></td>
                        <td class="num"><?php echo lkr((float)$p['amount_total']); ?></td>
                        <td><span class="pill <?php echo $paymentTone[$p['status']] ?? 'dim'; ?>"><?php echo ucfirst($p['status']); ?></span></td>
                        <td class="muted"><?php echo htmlspecialchars($p['failure_reason'] ?? ''); ?><?php if ($p['stripe_payment_intent']): ?><div class="mono" style="font-size: 11px;"><?php echo htmlspecialchars($p['stripe_payment_intent']); ?></div><?php endif; ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </section>

    <section class="panel">
        <h3>↩ Refund requests</h3>
        <p class="sub">What they asked for and what was decided.</p>
        <?php if (!$refunds): ?><div class="empty">No refund requests.</div><?php else: ?>
        <div class="table-wrap">
            <table>
                <thead><tr><th>Requested</th><th>Event / booking</th><th>Reason</th><th class="num">Amount</th><th>Outcome</th></tr></thead>
                <tbody>
                <?php foreach ($refunds as $r): $rs = REFUND_STATUSES[$r['status']] ?? REFUND_STATUSES['pending']; ?>
                    <tr>
                        <td><?php echo when($r['created_at']); ?></td>
                        <td><a href="event_details.php?id=<?php echo (int)$r['event_id']; ?>"><?php echo htmlspecialchars($r['title']); ?></a><div class="mono muted"><?php echo htmlspecialchars($r['booking_ref']); ?></div></td>
                        <td><?php echo htmlspecialchars(REFUND_REASONS[$r['reason']] ?? $r['reason']); ?><?php if ($r['message']): ?><div class="muted">“<?php echo htmlspecialchars($r['message']); ?>”</div><?php endif; ?></td>
                        <td class="num"><?php echo lkr((float)($r['refund_amount'] ?? $r['amount_requested'])); ?></td>
                        <td>
                            <span class="pill <?php echo $refundTone[$r['status']] ?? 'dim'; ?>"><?php echo $rs['icon'] . ' ' . $rs['label']; ?></span>
                            <?php if ($r['reviewed_at'] && $r['status'] !== 'withdrawn'): ?><div class="muted">by <?php echo htmlspecialchars($r['reviewer'] ?? '—'); ?>, <?php echo when($r['reviewed_at']); ?></div><?php endif; ?>
                            <?php if ($r['admin_note']): ?><div class="muted">“<?php echo htmlspecialchars($r['admin_note']); ?>”</div><?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </section>
</div>

<?php require __DIR__ . "/footer.php"; ?>
</body>
</html>
