<?php
session_start();
require __DIR__ . "/config/database.php";

$loggedIn = isset($_SESSION['user_id']);
$userName = $loggedIn ? $_SESSION['user_name'] : '';

$eventId = isset($_GET['event_id']) && ctype_digit((string)$_GET['event_id']) ? (int)$_GET['event_id'] : null;
$event = null;

// Release the seats this customer was holding during checkout
$paymentId = isset($_GET['pid']) && ctype_digit((string)$_GET['pid']) ? (int)$_GET['pid'] : 0;
// Guests: only the browser that started the checkout (see guest_owns_payment) may cancel it
$guestOwns = !$loggedIn && isset($_SESSION['guest_payments'][$paymentId]);
if (($loggedIn || $guestOwns) && $paymentId > 0) {
    require_once __DIR__ . "/config/stripe_client.php";
    if ($loggedIn) {
        $stmt = $conn->prepare("SELECT stripe_session_id FROM payments WHERE id = ? AND user_id = ? AND status = 'pending'");
        $stmt->bind_param("ii", $paymentId, $_SESSION['user_id']);
    } else {
        $stmt = $conn->prepare("SELECT stripe_session_id FROM payments WHERE id = ? AND status = 'pending'");
        $stmt->bind_param("i", $paymentId);
    }
    $stmt->execute();
    $pending = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    // Only mark cancelled once Stripe confirms the session can no longer be paid
    if ($pending && (!$pending['stripe_session_id'] || stripe_expire_checkout_session($pending['stripe_session_id'])['ok'])) {
        $upd = $conn->prepare("UPDATE payments SET status = 'cancelled', failure_reason = 'Cancelled by customer.' WHERE id = ? AND status = 'pending'");
        $upd->bind_param("i", $paymentId);
        $upd->execute();
        $upd->close();
    }
}

if ($eventId) {
    $stmt = $conn->prepare("SELECT title FROM events WHERE id = ?");
    $stmt->bind_param("i", $eventId);
    $stmt->execute();
    $event = $stmt->get_result()->fetch_assoc();
    $stmt->close();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<?php include __DIR__ . "/pwa_head.php"; ?>
<title>Payment Cancelled - TicketHub</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Sora:wght@600;700&family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
<style>
:root {
    --bg: #14121A; --surface: rgba(255,255,255,0.04); --border: rgba(255,255,255,0.08);
    --text: #F1EEF7; --text-dim: #A9A3B8; --violet: #9D7CF9; --rose: #F0729C;
    --font-display: 'Sora', 'Segoe UI', sans-serif; --font-body: 'Inter', 'Segoe UI', sans-serif;
}
* { box-sizing: border-box; }
body {
    margin: 0; font-family: var(--font-body); background: var(--bg); color: var(--text);
    min-height: 100vh; display: flex; align-items: center; justify-content: center;
}
.card {
    max-width: 420px; text-align: center; padding: 48px 36px;
    background: var(--surface); border: 1px solid var(--border); border-radius: 20px;
    opacity: 0; transform: translateY(16px); animation: rise 0.6s ease-out forwards;
}
.icon {
    width: 64px; height: 64px; border-radius: 50%; margin: 0 auto 20px;
    background: rgba(240,114,156,0.15); display: flex; align-items: center; justify-content: center;
    font-size: 28px;
}
h1 { font-family: var(--font-display); font-size: 22px; font-weight: 700; margin: 0 0 10px; }
p { color: var(--text-dim); font-size: 14px; line-height: 1.6; margin: 0 0 28px; }
.btn {
    display: inline-block; padding: 13px 26px; border-radius: 100px;
    text-decoration: none; font-weight: 600; font-size: 14px;
    color: var(--bg); background: var(--text);
    transition: transform 0.25s ease, background 0.25s ease, color 0.25s ease;
}
.btn:hover { background: var(--violet); color: #fff; transform: translateY(-2px); }
.btn.ghost { background: transparent; color: var(--text-dim); border: 1px solid var(--border); margin-left: 10px; }
@keyframes rise { from { opacity: 0; transform: translateY(16px); } to { opacity: 1; transform: translateY(0); } }
@media (prefers-reduced-motion: reduce) { *, .card { animation: none !important; opacity: 1 !important; transform: none !important; } }
</style>
</head>
<body>

<div class="card">
    <div class="icon">✕</div>
    <h1>Payment cancelled</h1>
    <p>
        <?php if ($event): ?>
            No charge was made. Your booking for <strong><?php echo htmlspecialchars($event['title']); ?></strong> was not completed — you can try again anytime.
        <?php else: ?>
            No charge was made. You can return to events and try again anytime.
        <?php endif; ?>
    </p>
    <?php if ($eventId): ?>
        <a href="tickets.php?event_id=<?php echo $eventId; ?>" class="btn">Try again</a>
    <?php endif; ?>
    <a href="events.php" class="btn ghost">Browse events</a>
</div>

</body>
</html>
