<?php
session_start();
require __DIR__ . "/config/database.php";
require __DIR__ . "/config/booking_service.php";

$sessionId = trim($_GET['session_id'] ?? '');
$outcome = null;
$guestTicket = null; // guest checkout: ['url' =>, 'ref' =>, 'email' =>, 'sent' =>] to show on this page

if ($sessionId === '') {
    $outcome = ['status' => 'error', 'message' => 'Missing payment reference.'];
} elseif (!isset($_SESSION['user_id'])) {
    // Guest checkout. Finalizing is safe for anyone (Stripe is asked whether it was paid),
    // but the ticket link is only shown in the browser that started this checkout.
    $find = $conn->prepare("SELECT id FROM payments WHERE stripe_session_id = ?");
    $find->bind_param("s", $sessionId);
    $find->execute();
    $paymentRow = $find->get_result()->fetch_assoc();
    $find->close();

    $outcome = $paymentRow
        ? payment_finalize_session($conn, $sessionId)
        : ['status' => 'error', 'message' => "We couldn't find this payment. If you were charged, your ticket will arrive by email shortly."];

    $sent = null;
    if ($outcome['status'] === 'confirmed') {
        $sent = booking_send_notifications($conn, (int)$outcome['booking']['id']);
    }
    if (in_array($outcome['status'], ['confirmed', 'already'], true)) {
        $booking = $outcome['booking'];
        $guestTicket = [
            'url'   => guest_owns_payment((int)$paymentRow['id']) ? booking_ticket_url($conn, $booking) : null,
            'ref'   => $booking['booking_ref'],
            'email' => $booking['customer_email'],
            'sent'  => $sent,
        ];
    }
} else {
    // Only the customer who owns this checkout may finalize it from the browser
    $own = $conn->prepare("SELECT id FROM payments WHERE stripe_session_id = ? AND user_id = ?");
    $own->bind_param("si", $sessionId, $_SESSION['user_id']);
    $own->execute();
    $isOwner = $own->get_result()->num_rows > 0;
    $own->close();

    $outcome = $isOwner
        ? payment_finalize_session($conn, $sessionId)
        : ['status' => 'error', 'message' => 'This payment session does not belong to your account.'];
}

if ($guestTicket === null && $outcome['status'] === 'confirmed') {
    // First time we've seen this payment — deliver the ticket by email + SMS
    $sent = booking_send_notifications($conn, (int)$outcome['booking']['id']);
    $flags = (($sent['email']['ok'] ?? false) ? 'e' : '') . (($sent['sms']['ok'] ?? false) ? 's' : '');
    header("Location: my_tickets.php?justBooked=" . urlencode($outcome['booking']['booking_ref']) . "&sent=" . $flags);
    exit;
}
if ($guestTicket === null && $outcome['status'] === 'already') {
    header("Location: my_tickets.php?justBooked=" . urlencode($outcome['booking']['booking_ref']));
    exit;
}

$views = [
    'pending'  => ['⏳', 'Payment processing', 'Check My Tickets'],
    'refunded' => ['↩', 'Payment refunded', 'Browse other events'],
    'expired'  => ['⌛', 'Checkout expired', 'Browse events'],
    'error'    => ['⚠', 'We hit a problem', 'Go to My Tickets'],
];
[$icon, $heading, $cta] = $views[$outcome['status']] ?? $views['error'];
$ctaHref = in_array($outcome['status'], ['pending', 'error'], true) ? 'my_tickets.php' : 'events.php';

if (!isset($_SESSION['user_id']) && $ctaHref === 'my_tickets.php') {
    // Guests have no My Tickets page; the ticket arrives by email + SMS
    [$cta, $ctaHref] = ['Browse events', 'events.php'];
}

if ($guestTicket) {
    $icon = '🎉';
    $heading = "You're booked!";
    $emailNote = ($guestTicket['sent'] === null || ($guestTicket['sent']['email']['ok'] ?? false))
        ? "Your e-ticket is on its way to " . $guestTicket['email'] . " (and by SMS)."
        : "We couldn't email your ticket just now — keep the link below safe.";
    $outcome['message'] = "Booking " . $guestTicket['ref'] . ". " . $emailNote;
    if (!$guestTicket['url']) {
        // Not the browser that bought it: confirm, but don't reveal the buyer's details
        $outcome['message'] = "Your payment is confirmed. The e-ticket has been sent to the email and phone used at checkout.";
    }
    [$cta, $ctaHref] = $guestTicket['url'] ? ['🎟️ View my e-ticket', $guestTicket['url']] : ['Browse events', 'events.php'];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<?php include __DIR__ . "/pwa_head.php"; ?>
<title><?php echo htmlspecialchars($heading); ?> - TicketHub</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Sora:wght@600;700&family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
<style>
:root {
    --bg: #14121A; --surface: rgba(255,255,255,0.04); --border: rgba(255,255,255,0.08);
    --text: #F1EEF7; --text-dim: #A9A3B8; --violet: #9D7CF9; --amber: #F2C572;
    --font-display: 'Sora', 'Segoe UI', sans-serif; --font-body: 'Inter', 'Segoe UI', sans-serif;
}
* { box-sizing: border-box; }
body {
    margin: 0; font-family: var(--font-body); background: var(--bg); color: var(--text);
    min-height: 100vh; display: flex; align-items: center; justify-content: center; padding: 16px;
}
.card {
    max-width: 440px; text-align: center; padding: 48px 36px;
    background: var(--surface); border: 1px solid var(--border); border-radius: 20px;
    animation: rise 0.5s ease-out;
}
.icon {
    width: 64px; height: 64px; border-radius: 50%; margin: 0 auto 20px;
    background: rgba(242,197,114,0.15); color: var(--amber);
    display: flex; align-items: center; justify-content: center; font-size: 28px;
}
h1 { font-family: var(--font-display); font-size: 22px; margin: 0 0 10px; }
p { color: var(--text-dim); font-size: 14px; line-height: 1.6; margin: 0 0 28px; }
.btn {
    display: inline-block; padding: 13px 26px; border-radius: 100px; text-decoration: none;
    font-weight: 600; font-size: 14px; color: var(--bg); background: var(--text);
    transition: background 0.25s ease, color 0.25s ease, transform 0.25s ease;
}
.btn:hover { background: var(--violet); color: #fff; transform: translateY(-2px); }
.claim { margin: 26px 0 0; font-size: 13px; }
.claim a { color: var(--violet); font-weight: 600; text-decoration: none; }
@keyframes rise { from { opacity: 0; transform: translateY(16px); } to { opacity: 1; transform: translateY(0); } }
@media (prefers-reduced-motion: reduce) { .card { animation: none; } }
</style>
</head>
<body>
<div class="card">
    <div class="icon"><?php echo $icon; ?></div>
    <h1><?php echo htmlspecialchars($heading); ?></h1>
    <p><?php echo htmlspecialchars($outcome['message']); ?></p>
    <a class="btn" href="<?php echo htmlspecialchars($ctaHref); ?>"><?php echo htmlspecialchars($cta); ?></a>
    <?php if ($guestTicket && $guestTicket['url']): ?>
        <p class="claim">Want all your tickets in one place?
            <a href="forgot_password.php?email=<?php echo urlencode($guestTicket['email']); ?>">Set a password</a>
            for <?php echo htmlspecialchars($guestTicket['email']); ?> and they'll be waiting in My Tickets.</p>
    <?php endif; ?>
</div>
</body>
</html>
