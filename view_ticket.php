<?php
/**
 * view_ticket.php
 * ------------------------------------------------------------
 * The customer's e-ticket. Opened from the SMS / email link
 * (ref + secret token, no login needed) or from My Tickets.
 *   ?ref=TKT-...&t=<token>        ticket page
 *   ?ref=TKT-...&t=<token>&qr=1   just the QR PNG (for emails)
 * ------------------------------------------------------------
 */
session_start();
require __DIR__ . "/config/database.php";
require __DIR__ . "/config/booking_service.php";

$ref   = trim($_GET['ref'] ?? '');
$token = trim($_GET['t'] ?? '');

$booking = null;
if (preg_match('/^TKT-[A-Z0-9]{6,16}$/', $ref)) {
    $stmt = $conn->prepare("SELECT id, user_id, ticket_token FROM bookings WHERE booking_ref = ?");
    $stmt->bind_param("s", $ref);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $tokenOk = $row && $row['ticket_token'] && $token !== '' && hash_equals($row['ticket_token'], $token);
    $ownerOk = $row && isset($_SESSION['user_id']) && (int)$_SESSION['user_id'] === (int)$row['user_id'];
    if ($tokenOk || $ownerOk) {
        $booking = booking_get($conn, (int)$row['id']);
    }
}

if (!$booking) {
    http_response_code(404);
}

if (isset($_GET['qr'])) {
    $png = $booking ? booking_qr_png($booking['booking_ref'], $booking['ticket_token'] ?: null) : false;
    if (!$png) {
        http_response_code(404);
        exit;
    }
    header('Content-Type: image/png');
    header('Cache-Control: private, max-age=86400');
    echo $png;
    exit;
}

if ($booking) {
    $shareUrl = booking_ticket_url($conn, $booking);
    $start = strtotime($booking['event_date']);
    $calendarUrl = 'https://calendar.google.com/calendar/render?action=TEMPLATE'
        . '&text=' . urlencode($booking['title'])
        . '&dates=' . gmdate('Ymd\THis\Z', $start) . '/' . gmdate('Ymd\THis\Z', $start + 3 * 3600)
        . '&location=' . urlencode($booking['location'])
        . '&details=' . urlencode("TicketHub booking {$booking['booking_ref']}\n$shareUrl");
    $isPast = $start < time();
    $isCancelled = $booking['status'] !== 'confirmed';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<?php include __DIR__ . "/pwa_head.php"; ?>
<meta name="robots" content="noindex">
<title><?php echo $booking ? htmlspecialchars($booking['title']) . ' — E-ticket' : 'Ticket not found'; ?> - TicketHub</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Sora:wght@600;700&family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
<style>
:root {
    --bg: #14121A; --surface: #1B1822; --border: rgba(255,255,255,0.09);
    --text: #F1EEF7; --text-dim: #A9A3B8; --violet: #9D7CF9; --rose: #F0729C; --green: #6FCF97;
    --font-display: 'Sora', 'Segoe UI', sans-serif; --font-body: 'Inter', 'Segoe UI', sans-serif;
}
* { box-sizing: border-box; }
body {
    margin: 0; font-family: var(--font-body); background: var(--bg); color: var(--text);
    min-height: 100vh; padding: 32px 16px 60px;
    background-image: radial-gradient(circle at 20% 0%, rgba(157,124,249,0.16), transparent 45%),
                      radial-gradient(circle at 90% 100%, rgba(240,114,156,0.12), transparent 40%);
}
.logo { text-align: center; font-family: var(--font-display); font-size: 20px; font-weight: 700; margin-bottom: 22px; }
.logo a { color: var(--text); text-decoration: none; }
.logo span { color: var(--violet); }

.ticket {
    max-width: 420px; margin: 0 auto; background: var(--surface);
    border: 1px solid var(--border); border-radius: 24px; overflow: hidden;
    box-shadow: 0 30px 60px -20px rgba(0,0,0,0.6);
    animation: rise 0.5s ease-out;
}
.ticket-head {
    padding: 26px 26px 22px;
    background: linear-gradient(135deg, rgba(157,124,249,0.28), rgba(240,114,156,0.18));
}
.tag {
    display: inline-block; font-size: 11px; font-weight: 700; letter-spacing: 0.06em; text-transform: uppercase;
    padding: 4px 10px; border-radius: 100px; background: rgba(20,18,26,0.45); color: #fff; margin-bottom: 12px;
}
.ticket-head h1 { font-family: var(--font-display); font-size: 22px; line-height: 1.25; margin: 0 0 10px; }
.ticket-head .meta { font-size: 14px; color: #E2DCF0; margin: 4px 0; }
.ticket-head .meta a { color: inherit; }

.perforation { position: relative; height: 24px; }
.perforation::before, .perforation::after {
    content: ''; position: absolute; top: 0; width: 24px; height: 24px; border-radius: 50%; background: var(--bg);
}
.perforation::before { left: -12px; }
.perforation::after { right: -12px; }
.perforation span { position: absolute; left: 18px; right: 18px; top: 11px; border-top: 2px dashed var(--border); }

.qr-wrap { text-align: center; padding: 4px 26px 8px; }
.qr-wrap img {
    width: 230px; height: 230px; max-width: 100%; background: #fff; border-radius: 16px; padding: 8px;
    image-rendering: pixelated;
}
.qr-wrap.void img { opacity: 0.25; filter: grayscale(1); }
.ref { font-family: 'Courier New', monospace; font-size: 18px; letter-spacing: 2px; color: var(--violet); margin-top: 12px; font-weight: 700; }
.admit { font-size: 13px; color: var(--text-dim); margin-top: 4px; }

.status {
    margin: 14px 26px 0; padding: 10px 14px; border-radius: 12px; font-size: 13px; text-align: center; font-weight: 600;
    background: rgba(111,207,151,0.12); color: var(--green); border: 1px solid rgba(111,207,151,0.3);
}
.status.warn { background: rgba(240,114,156,0.12); color: #F4A4BE; border-color: rgba(240,114,156,0.35); }

.details { padding: 18px 26px 6px; display: grid; grid-template-columns: 1fr 1fr; gap: 14px; }
.details div small { display: block; font-size: 11px; color: var(--text-dim); text-transform: uppercase; letter-spacing: 0.05em; margin-bottom: 3px; }
.details div b { font-size: 14px; font-weight: 600; word-break: break-word; }

.actions { padding: 18px 26px 26px; display: flex; gap: 10px; flex-wrap: wrap; }
.actions a, .actions button {
    flex: 1 1 120px; text-align: center; padding: 12px; border-radius: 100px; font-family: var(--font-body);
    font-size: 13px; font-weight: 600; text-decoration: none; cursor: pointer;
    border: 1px solid var(--border); background: transparent; color: var(--text);
    transition: background 0.2s ease, border-color 0.2s ease;
}
.actions a:hover, .actions button:hover { border-color: var(--violet); background: rgba(157,124,249,0.12); }
.actions .primary { background: var(--text); color: var(--bg); border-color: var(--text); }
.actions .primary:hover { background: var(--violet); color: #fff; border-color: var(--violet); }

.footnote { max-width: 420px; margin: 18px auto 0; text-align: center; font-size: 12px; color: var(--text-dim); line-height: 1.6; }

.notfound { max-width: 420px; margin: 60px auto; text-align: center; }
.notfound h1 { font-family: var(--font-display); font-size: 22px; }
.notfound p { color: var(--text-dim); font-size: 14px; }
.notfound a { color: var(--violet); }

@keyframes rise { from { opacity: 0; transform: translateY(16px); } to { opacity: 1; transform: translateY(0); } }
@media (prefers-reduced-motion: reduce) { .ticket { animation: none; } }

@media print {
    body { background: #fff; color: #000; padding: 0; }
    .ticket { box-shadow: none; border-color: #ccc; background: #fff; }
    .ticket-head { background: #f3f0fa; }
    .ticket-head .meta, .details div small, .admit, .footnote { color: #444; }
    .actions, .logo { display: none; }
    .perforation::before, .perforation::after { background: #fff; }
}
</style>
</head>
<body>
<div class="logo"><a href="index.php">Ticket<span>Hub</span></a></div>

<?php if (!$booking): ?>
    <div class="notfound">
        <h1>Ticket not found</h1>
        <p>This ticket link is invalid or incomplete. Open the link from your SMS or email again, or <a href="my_tickets.php">log in to see My Tickets</a>.</p>
    </div>
<?php else: ?>
    <div class="ticket">
        <div class="ticket-head">
            <span class="tag"><?php echo htmlspecialchars($booking['category']); ?></span>
            <h1><?php echo htmlspecialchars($booking['title']); ?></h1>
            <div class="meta">🗓 <?php echo date("l, M d, Y · h:i A", $start); ?></div>
            <div class="meta">📍 <a href="https://www.google.com/maps/search/?api=1&query=<?php echo urlencode($booking['location']); ?>" target="_blank" rel="noopener noreferrer"><?php echo htmlspecialchars($booking['location']); ?></a></div>
        </div>

        <div class="perforation"><span></span></div>

        <div class="qr-wrap <?php echo $isCancelled ? 'void' : ''; ?>">
            <img src="<?php echo booking_qr_data_uri($booking['booking_ref'], $booking['ticket_token']); ?>" alt="QR code for booking <?php echo htmlspecialchars($booking['booking_ref']); ?>">
            <div class="ref"><?php echo htmlspecialchars($booking['booking_ref']); ?></div>
            <div class="admit">Admits <?php echo (int)$booking['quantity']; ?> · show this at the entrance</div>
        </div>

        <?php if ($isCancelled): ?>
            <div class="status warn">This booking is <?php echo htmlspecialchars($booking['status']); ?> and is no longer valid.</div>
        <?php elseif ($isPast): ?>
            <div class="status warn">This event has already taken place.</div>
        <?php else: ?>
            <div class="status">✓ Paid &amp; confirmed</div>
        <?php endif; ?>

        <?php if (!$isCancelled && !empty($booking['checked_in_at'])): ?>
            <div class="status">
                <?php echo !empty($booking['is_inside']) ? '🟢 Checked in' : '↗ Checked out'; ?>
                · first entry <?php echo date("M d, h:i A", strtotime($booking['checked_in_at'])); ?>
            </div>
        <?php endif; ?>

        <div class="details">
            <div><small>Ticket holder</small><b><?php echo htmlspecialchars($booking['customer_name']); ?></b></div>
            <div><small>Tickets</small><b><?php echo (int)$booking['quantity']; ?></b></div>
            <div><small>Total paid</small><b>LKR <?php echo number_format((float)$booking['total_price'], 2); ?></b></div>
            <div><small>Booked on</small><b><?php echo date("M d, Y", strtotime($booking['created_at'])); ?></b></div>
        </div>

        <div class="actions">
            <button type="button" class="primary" onclick="window.print()">🖨 Print / Save PDF</button>
            <a href="<?php echo htmlspecialchars($calendarUrl); ?>" target="_blank" rel="noopener noreferrer">📅 Add to calendar</a>
            <button type="button" id="shareBtn" data-url="<?php echo htmlspecialchars($shareUrl); ?>">🔗 Share</button>
        </div>
    </div>
    <p class="footnote">Keep this link private — anyone with it can show this ticket.<br>Screenshot the QR code in case you're offline at the venue.</p>

    <script>
    document.getElementById('shareBtn').addEventListener('click', async (e) => {
        const btn = e.currentTarget;
        const url = btn.dataset.url;
        if (navigator.share) {
            try { await navigator.share({ title: document.title, url }); } catch (_) {}
            return;
        }
        try {
            await navigator.clipboard.writeText(url);
            btn.textContent = '✓ Link copied';
        } catch (_) {
            prompt('Copy your ticket link:', url);
        }
    });
    </script>
<?php endif; ?>
</body>
</html>
