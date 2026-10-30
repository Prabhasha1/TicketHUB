<?php
session_start();
require __DIR__ . "/config/database.php";
require __DIR__ . "/config/booking_service.php";

$loggedIn = isset($_SESSION['user_id']);
$userName = $loggedIn ? $_SESSION['user_name'] : '';
$isAdmin  = isset($_SESSION['user_role']) && in_array($_SESSION['user_role'], ['admin', 'super_admin'], true);

// Visitors can buy paid tickets as a guest (no login); free tickets still need an account
if (!isset($_GET['event_id']) || !ctype_digit((string)$_GET['event_id'])) {
    die("Invalid event.");
}
$eventId = (int)$_GET['event_id'];

// Fetch the event
$stmt = $conn->prepare("SELECT * FROM events WHERE id = ?");
$stmt->bind_param("i", $eventId);
$stmt->execute();
$event = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$event) {
    die("Event not found.");
}

$ticketsRemaining = tickets_available($conn, $event);
$ticketsHeld = tickets_held($conn, $eventId);
$soldOut = $ticketsRemaining <= 0;
$eventPassed = strtotime($event['event_date']) < time();
$salesBlocked = event_sales_block_reason($event); // cancelled / postponed / paused / past
$statusMeta   = event_status_meta($event);
$isFree = (float)$event['ticket_price'] <= 0;
$isGuest = !$loggedIn;
$loginUrl = "login.php?return=" . urlencode("tickets.php?event_id=$eventId");

if ($isGuest && $isFree) {
    header("Location: $loginUrl&error=" . urlencode("Please log in to get free tickets."));
    exit;
}

$errorMessage = '';
$errorNeedsLogin = false;
$csrfToken = csrf_token();

// Pre-fill the phone from the profile
$savedPhone = '';
if ($loggedIn) {
    $phoneStmt = $conn->prepare("SELECT phone FROM users WHERE id = ?");
    $phoneStmt->bind_param("i", $_SESSION['user_id']);
    $phoneStmt->execute();
    $savedPhone = $phoneStmt->get_result()->fetch_assoc()['phone'] ?? '';
    $phoneStmt->close();
}
$phoneInput = $savedPhone ? phone_display($savedPhone) : '';
$guestNameInput = '';
$guestEmailInput = '';
$quantityInput = 1;

// Loyalty points the customer can spend on this order (1 point = 1% off, capped per order)
$pointsAvailable = ($isFree || $isGuest) ? 0 : loyalty_points_available($conn, (int)$_SESSION['user_id']);
$pointsMaxUse    = min($pointsAvailable, loyalty_max_points_per_order());
$pointsInput     = 0;

// Handle "Proceed to payment"
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['quantity'])) {
    $quantityInput = max(1, min(BOOKING_MAX_PER_ORDER, (int)$_POST['quantity']));
    $phoneInput = trim($_POST['phone'] ?? '');
    $phone = phone_normalize($phoneInput);
    $pointsInput = max(0, min($pointsMaxUse, (int)($_POST['points_to_use'] ?? 0)));
    $guestNameInput = trim($_POST['guest_name'] ?? '');
    $guestEmailInput = trim($_POST['guest_email'] ?? '');

    if (!csrf_valid($_POST['csrf_token'] ?? null)) {
        $errorMessage = "Your session expired. Please try again.";
    } elseif ($salesBlocked) {
        $errorMessage = $salesBlocked;
    } elseif ($isGuest && ($guestNameInput === '' || mb_strlen($guestNameInput) > 100)) {
        $errorMessage = "Please enter your name.";
    } elseif ($isGuest && (!filter_var($guestEmailInput, FILTER_VALIDATE_EMAIL) || strlen($guestEmailInput) > 150)) {
        $errorMessage = "Please enter a valid email address — we'll send your e-ticket to it.";
    } elseif ($phone === null) {
        $errorMessage = "Please enter a valid mobile number (e.g. 077 123 4567) — we'll text your e-ticket to it.";
    } else {
        $buyerId = $loggedIn ? (int)$_SESSION['user_id'] : 0;
        if ($isGuest) {
            try {
                $guest = guest_account_for($conn, $guestNameInput, $guestEmailInput, $phone);
            } catch (Throwable $e) {
                error_log("Guest checkout unavailable: " . $e->getMessage());
                $guest = ['ok' => false, 'needs_login' => false, 'error' => "Guest checkout isn't available right now. Please log in to book."];
            }
            $buyerId = $guest['ok'] ? $guest['user_id'] : 0;
        }

        $result = $buyerId > 0
            ? checkout_start($conn, $buyerId, $eventId, (int)$_POST['quantity'], $phone, $pointsInput, $isGuest)
            : ['ok' => false, 'error' => $guest['error']];
        $errorNeedsLogin = !empty($guest['needs_login']);

        if ($result['ok'] && isset($result['redirect'])) {
            header("Location: " . $result['redirect']);
            exit;
        } elseif ($result['ok']) {
            header("Location: my_tickets.php?justBooked=" . urlencode($result['booking_ref']));
            exit;
        }
        $errorMessage = $result['error'];
        $ticketsRemaining = tickets_available($conn, $event);
        $soldOut = $ticketsRemaining <= 0;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<?php include __DIR__ . "/pwa_head.php"; ?>
<title><?php echo htmlspecialchars($event['title']); ?> - TicketHub</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Sora:wght@600;700;800&family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
<style>
:root {
    --bg: #14121A;
    --surface: rgba(255,255,255,0.04);
    --field: rgba(255,255,255,0.06);
    --border: rgba(255,255,255,0.08);
    --text: #F1EEF7;
    --text-dim: #A9A3B8;
    --violet: #9D7CF9;
    --violet-soft: rgba(157,124,249,0.18);
    --rose: #F0729C;
    --green: #6FCF97;
    --font-display: 'Sora', 'Segoe UI', sans-serif;
    --font-body: 'Inter', 'Segoe UI', sans-serif;
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

.page-wrap { max-width: 760px; margin: 0 auto; padding: 50px 24px 100px; }

.back-link {
    display: inline-flex; align-items: center; gap: 6px;
    color: var(--text-dim); text-decoration: none; font-size: 14px;
    margin-bottom: 24px; transition: color 0.2s ease;
}
.back-link:hover { color: var(--violet); }

.booking-card {
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: 20px;
    overflow: hidden;
    opacity: 0;
    transform: translateY(16px);
    animation: rise 0.6s ease-out forwards;
}

.event-banner {
    width: 100%;
    height: 220px;
    object-fit: cover;
    display: block;
    border-bottom: 1px solid var(--border);
}

.event-banner-fallback {
    width: 100%; height: 220px;
    display: flex; align-items: center; justify-content: center;
    font-size: 48px;
    background: linear-gradient(135deg, rgba(157,124,249,0.18), rgba(240,114,156,0.18));
    border-bottom: 1px solid var(--border);
}

.event-info { padding: 32px; }

.category-tag {
    display: inline-block;
    font-size: 11px; font-weight: 600; letter-spacing: 0.04em; text-transform: uppercase;
    color: var(--violet); background: var(--violet-soft);
    padding: 4px 10px; border-radius: 100px; margin-bottom: 14px;
}

h1 { font-family: var(--font-display); font-size: 26px; font-weight: 700; margin: 0 0 16px; }

.meta-list { margin-bottom: 24px; }
.meta-row {
    display: flex; align-items: center; gap: 8px;
    color: var(--text-dim); font-size: 14px; margin-bottom: 8px;
}
.meta-row a { color: var(--text-dim); text-decoration: none; border-bottom: 1px dashed transparent; transition: color 0.2s ease, border-color 0.2s ease; }
.meta-row a:hover { color: var(--violet); border-bottom-color: var(--violet); }

.description { color: var(--text-dim); font-size: 14px; line-height: 1.7; margin-bottom: 28px; }

.divider { border-top: 1px solid var(--border); margin: 28px 0; }

.order-summary { display: flex; flex-direction: column; gap: 16px; }

.qty-row {
    display: flex; align-items: center; justify-content: space-between;
    background: var(--field); border: 1px solid var(--border);
    border-radius: 14px; padding: 16px 20px;
}

.qty-label { font-size: 14px; color: var(--text-dim); font-weight: 500; }

.qty-control { display: flex; align-items: center; gap: 14px; }

.qty-btn {
    width: 34px; height: 34px; border-radius: 10px; border: 1px solid var(--border);
    background: var(--surface); color: var(--text); font-size: 18px; font-weight: 600;
    cursor: pointer; display: flex; align-items: center; justify-content: center;
    transition: border-color 0.2s ease, background 0.2s ease;
}
.qty-btn:hover { border-color: var(--violet); background: var(--violet-soft); }
.qty-btn:disabled { opacity: 0.4; cursor: not-allowed; }

.qty-value { font-family: var(--font-display); font-size: 18px; font-weight: 700; min-width: 24px; text-align: center; }

.price-breakdown {
    display: flex; justify-content: space-between; align-items: baseline;
    padding: 0 4px;
}

.price-breakdown .label { color: var(--text-dim); font-size: 14px; }
.price-breakdown .value { font-family: var(--font-display); font-size: 22px; font-weight: 700; }

.remaining-note { font-size: 13px; color: var(--text-dim); text-align: right; margin-top: -8px; }
.remaining-note.low { color: var(--rose); font-weight: 600; }

.error-msg {
    background: rgba(240,114,156,0.12); border: 1px solid rgba(240,114,156,0.35);
    color: #F4A4BE; font-size: 13px; padding: 12px 16px; border-radius: 10px; margin-bottom: 20px;
}

.loyalty-box {
    margin: 16px 0 4px; padding: 14px 16px; border-radius: 14px;
    background: linear-gradient(135deg, rgba(157,124,249,0.14), rgba(240,114,156,0.06)); border: 1px solid rgba(157,124,249,0.35);
}
.loyalty-box .lb-head { display: flex; justify-content: space-between; align-items: center; gap: 10px; font-weight: 600; font-size: 14px; }
.loyalty-box .lb-have { font-size: 12.5px; font-weight: 500; color: var(--text-dim); }
.loyalty-box .lb-have b { color: #CBB8FC; }
.loyalty-box .lb-rule { margin: 4px 0 10px; font-size: 12px; color: var(--text-dim); }
.loyalty-box .lb-row { display: flex; align-items: center; gap: 12px; }
.loyalty-box input[type="range"] { flex: 1; accent-color: #9D7CF9; cursor: pointer; }
.loyalty-box input[type="number"] {
    width: 72px; background: rgba(255,255,255,0.06); border: 1px solid rgba(255,255,255,0.12); border-radius: 8px;
    color: var(--text); font-family: inherit; font-size: 14px; padding: 7px 8px; text-align: center; outline: none;
}
.loyalty-box input[type="number"]:focus { border-color: #9D7CF9; }
.loyalty-box .lb-quick { display: flex; gap: 6px; flex-wrap: wrap; margin-top: 10px; }
.loyalty-box .lb-quick button {
    font-family: inherit; font-size: 12px; font-weight: 600; padding: 5px 11px; border-radius: 100px; cursor: pointer;
    background: transparent; color: var(--text-dim); border: 1px solid rgba(255,255,255,0.14);
}
.loyalty-box .lb-quick button:hover { color: var(--text); border-color: #9D7CF9; }
.breakdown .discount-line { color: #6FCF97; }
.breakdown .discount-line[hidden] { display: none; }
.earn-note { text-align: center; font-size: 12.5px; color: #CBB8FC; margin-top: 8px; min-height: 16px; }

.sold-out-msg {
    background: rgba(255,255,255,0.04); border: 1px solid var(--border);
    color: var(--text-dim); font-size: 14px; padding: 16px; border-radius: 12px;
    text-align: center; font-weight: 600;
}

.btn {
    width: 100%; margin-top: 8px; padding: 16px;
    font-family: var(--font-body); font-size: 16px; font-weight: 600;
    border-radius: 100px; border: none; cursor: pointer;
    color: var(--bg); background: var(--text);
    transition: transform 0.25s ease, background 0.25s ease, color 0.25s ease, box-shadow 0.25s ease, opacity 0.25s ease;
    display: flex; align-items: center; justify-content: center; gap: 10px;
}

.btn:hover:not(:disabled) {
    background: var(--violet); color: #fff; transform: translateY(-2px);
    box-shadow: 0 14px 32px -8px rgba(157,124,249,0.55);
}

.btn:disabled { opacity: 0.5; cursor: not-allowed; }

.spinner {
    width: 16px; height: 16px; border: 2px solid rgba(20,18,26,0.3);
    border-top-color: var(--bg); border-radius: 50%; display: none;
    animation: spin 0.7s linear infinite;
}
.btn:disabled .spinner { display: inline-block; }

.secure-note {
    text-align: center; font-size: 12px; color: var(--text-dim);
    margin-top: 14px; display: flex; align-items: center; justify-content: center; gap: 6px;
}

.phone-field label { display: block; font-size: 14px; font-weight: 500; margin-bottom: 8px; }
.phone-field label span { color: var(--text-dim); font-weight: 400; font-size: 13px; }
.phone-field input {
    width: 100%; padding: 14px 16px; font-family: var(--font-body); font-size: 15px;
    background: var(--field); color: var(--text); border: 1px solid var(--border); border-radius: 14px;
    outline: none; transition: border-color 0.2s ease, box-shadow 0.2s ease;
}
.phone-field input:focus { border-color: var(--violet); box-shadow: 0 0 0 3px var(--violet-soft); }
.phone-field input.invalid { border-color: var(--rose); }
.field-error { display: none; color: #F4A4BE; font-size: 12px; margin-top: 6px; }
.field-error.show { display: block; }
.guest-note {
    padding: 12px 14px; border-radius: 14px; font-size: 13px; line-height: 1.5; color: var(--text-dim);
    background: var(--violet-soft); border: 1px solid rgba(157,124,249,0.25);
}
.guest-note a { display: inline-block; color: var(--text); font-weight: 600; text-decoration: none; }
.guest-note a:hover { color: var(--violet); }

.breakdown { padding: 0 4px; display: flex; flex-direction: column; gap: 6px; }
.breakdown .line { display: flex; justify-content: space-between; color: var(--text-dim); font-size: 13px; }

.next-steps {
    margin-top: 6px; padding: 14px 16px; border-radius: 14px;
    background: rgba(111,207,151,0.06); border: 1px solid rgba(111,207,151,0.2);
    display: flex; flex-wrap: wrap; gap: 6px 16px; font-size: 13px; color: var(--text-dim);
}
.next-steps strong { width: 100%; color: var(--green); font-weight: 600; }

@keyframes rise { from { opacity: 0; transform: translateY(16px); } to { opacity: 1; transform: translateY(0); } }
@keyframes spin { to { transform: rotate(360deg); } }

@media (prefers-reduced-motion: reduce) {
    *, .booking-card { animation: none !important; opacity: 1 !important; transform: none !important; }
}

@media (max-width: 560px) {
    header { padding: 18px 20px; }
    .event-info { padding: 24px; }
    h1 { font-size: 22px; }
}
</style>
</head>
<body>

<?php require __DIR__ . "/nav.php"; ?>

<div class="page-wrap">
    <a class="back-link" href="events.php">← Back to events</a>

    <div class="booking-card">
        <?php if (!empty($event['photo']) && file_exists(__DIR__ . "/eventphotos/" . $event['photo'])): ?>
            <img class="event-banner" src="eventphotos/<?php echo htmlspecialchars($event['photo']); ?>" alt="<?php echo htmlspecialchars($event['title']); ?>">
        <?php else: ?>
            <div class="event-banner-fallback">🎫</div>
        <?php endif; ?>

        <div class="event-info">
            <span class="category-tag"><?php echo htmlspecialchars($event['category']); ?></span>
            <h1><?php echo htmlspecialchars($event['title']); ?></h1>

            <div class="meta-list">
                <div class="meta-row">📍 <a href="https://www.google.com/maps/search/?api=1&query=<?php echo urlencode($event['location']); ?>" target="_blank" rel="noopener noreferrer"><?php echo htmlspecialchars($event['location']); ?></a></div>
                <div class="meta-row">🗓 <?php echo date("l, M d, Y · h:i A", strtotime($event['event_date'])); ?></div>
                <?php if (event_status_of($event) === 'rescheduled'): ?>
                    <div class="meta-row" style="color: #6CA0F2;">📅 Rescheduled<?php echo !empty($event['original_event_date']) ? ' from ' . date("M d, Y", strtotime($event['original_event_date'])) : ''; ?><?php echo !empty($event['status_note']) ? ' — ' . htmlspecialchars($event['status_note']) : ''; ?></div>
                <?php endif; ?>
            </div>

            <?php if (!empty($event['description'])): ?>
                <p class="description"><?php echo htmlspecialchars($event['description']); ?></p>
            <?php endif; ?>

            <div class="divider"></div>

            <?php if ($errorMessage): ?>
                <div class="error-msg">
                    <?php echo htmlspecialchars($errorMessage); ?>
                    <?php if ($errorNeedsLogin): ?>
                        <br><a href="<?php echo htmlspecialchars($loginUrl . '&email=' . urlencode($guestEmailInput)); ?>" style="color: inherit; font-weight: 600;">Log in and continue →</a>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <?php if ($salesBlocked || $soldOut): ?>
                <div class="sold-out-msg">
                    <?php if ($salesBlocked && event_status_of($event) !== 'on_sale'): ?><b><?php echo $statusMeta['icon'] . ' ' . $statusMeta['label']; ?></b><br><?php endif; ?>
                    <?php echo htmlspecialchars($salesBlocked ?? "Sorry, this event is sold out."); ?>
                    <?php if (!empty($event['status_note']) && $salesBlocked): ?><br><small>“<?php echo htmlspecialchars($event['status_note']); ?>”</small><?php endif; ?>
                </div>
            <?php else: ?>
                <form method="POST" id="bookingForm" novalidate>
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                    <div class="order-summary">
                        <div class="qty-row">
                            <span class="qty-label">Number of tickets</span>
                            <div class="qty-control">
                                <button type="button" class="qty-btn" id="qtyMinus" aria-label="Fewer tickets">−</button>
                                <span class="qty-value" id="qtyValue" aria-live="polite"><?php echo $quantityInput; ?></span>
                                <button type="button" class="qty-btn" id="qtyPlus" aria-label="More tickets">+</button>
                            </div>
                        </div>
                        <input type="hidden" name="quantity" id="qtyInput" value="<?php echo $quantityInput; ?>">

                        <div class="remaining-note <?php echo $ticketsRemaining <= 10 ? 'low' : ''; ?>">
                            <?php echo $ticketsRemaining; ?> ticket<?php echo $ticketsRemaining === 1 ? '' : 's'; ?> available
                            <?php if ($ticketsHeld > 0): ?>
                                · <?php echo $ticketsHeld; ?> being checked out by others
                            <?php endif; ?>
                        </div>

                        <?php if ($isGuest): ?>
                            <div class="guest-note">
                                No account needed — just tell us where to send your tickets.
                                <a href="<?php echo htmlspecialchars($loginUrl); ?>">Have an account? Log in</a>
                            </div>
                            <div class="phone-field">
                                <label for="guestName">🙂 Your name</label>
                                <input type="text" id="guestName" name="guest_name" autocomplete="name" maxlength="100"
                                       placeholder="Full name" required value="<?php echo htmlspecialchars($guestNameInput); ?>">
                                <div class="field-error" id="guestNameError">Please enter your name.</div>
                            </div>
                            <div class="phone-field">
                                <label for="guestEmail">📧 Email <span>— we'll email your e-ticket here</span></label>
                                <input type="email" id="guestEmail" name="guest_email" inputmode="email" autocomplete="email" maxlength="150"
                                       placeholder="you@example.com" required value="<?php echo htmlspecialchars($guestEmailInput); ?>">
                                <div class="field-error" id="guestEmailError">Enter a valid email address.</div>
                            </div>
                        <?php endif; ?>

                        <div class="phone-field">
                            <label for="phone">📱 Mobile number <span>— we'll SMS your e-ticket here</span></label>
                            <input type="tel" id="phone" name="phone" inputmode="tel" autocomplete="tel"
                                   placeholder="077 123 4567" required value="<?php echo htmlspecialchars($phoneInput); ?>">
                            <div class="field-error" id="phoneError">Enter a valid mobile number, e.g. 077 123 4567 or +94 77 123 4567.</div>
                        </div>

                        <?php if (!$isFree && $pointsMaxUse > 0): ?>
                            <div class="loyalty-box">
                                <div class="lb-head">
                                    <span>🏆 Use loyalty points</span>
                                    <span class="lb-have">You have <b><?php echo $pointsAvailable; ?></b> point<?php echo $pointsAvailable === 1 ? '' : 's'; ?></span>
                                </div>
                                <p class="lb-rule">1 point = <?php echo LOYALTY_PERCENT_PER_POINT; ?>% off this order · up to <?php echo LOYALTY_MAX_DISCOUNT_PERCENT; ?>% off</p>
                                <div class="lb-row">
                                    <input type="range" id="pointsRange" min="0" max="<?php echo $pointsMaxUse; ?>" step="1" value="<?php echo $pointsInput; ?>" aria-label="Loyalty points to use">
                                    <input type="number" id="pointsNumber" name="points_to_use" min="0" max="<?php echo $pointsMaxUse; ?>" step="1" value="<?php echo $pointsInput; ?>" aria-label="Points to use">
                                </div>
                                <div class="lb-quick">
                                    <button type="button" data-points="0">Don't use</button>
                                    <?php if ($pointsMaxUse >= 10): ?><button type="button" data-points="<?php echo (int)floor($pointsMaxUse / 2); ?>">Use <?php echo (int)floor($pointsMaxUse / 2); ?></button><?php endif; ?>
                                    <button type="button" data-points="<?php echo $pointsMaxUse; ?>">Use max (<?php echo $pointsMaxUse; ?>)</button>
                                </div>
                            </div>
                        <?php elseif (!$isFree): ?>
                            <input type="hidden" name="points_to_use" value="0">
                        <?php endif; ?>

                        <div class="breakdown">
                            <div class="line"><span id="lineLabel"><?php echo $quantityInput; ?> × LKR <?php echo number_format($event['ticket_price'], 2); ?></span><span id="lineValue"></span></div>
                            <div class="line discount-line" id="discountLine" hidden><span id="discountLabel">Loyalty discount</span><span id="discountValue"></span></div>
                            <div class="line"><span>Booking fee</span><span>LKR 0.00</span></div>
                        </div>

                        <div class="price-breakdown">
                            <span class="label">Total</span>
                            <span class="value" id="totalPrice">LKR <?php echo number_format($event['ticket_price'], 2); ?></span>
                        </div>

                        <button type="submit" class="btn" id="payBtn">
                            <span class="spinner"></span>
                            <span id="payBtnLabel"><?php echo $isFree ? 'Get free tickets' : 'Proceed to secure payment'; ?></span>
                        </button>
                        <div class="secure-note">
                            <?php echo $isFree ? '🎟️ No payment needed' : '🔒 Card payment by Stripe · your seats are held for 30 minutes'; ?>
                        </div>
                        <?php if (!$isFree): ?>
                            <div class="earn-note" id="earnNote"></div>
                        <?php endif; ?>
                        <div class="next-steps">
                            <strong>After payment you'll get:</strong>
                            <span>📧 E-ticket with QR code by email</span>
                            <span>💬 SMS with your ticket link</span>
                            <?php if ($isGuest): ?>
                                <span>🎟️ A ticket link you can open anytime</span>
                            <?php else: ?>
                                <span>🎟️ Ticket saved in My Tickets</span>
                            <?php endif; ?>
                        </div>
                    </div>
                </form>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
const unitPrice = <?php echo (float)$event['ticket_price']; ?>;
const maxTickets = Math.min(<?php echo BOOKING_MAX_PER_ORDER; ?>, <?php echo $ticketsRemaining; ?>);
let qty = Math.min(<?php echo $quantityInput; ?>, Math.max(1, maxTickets));
const lkr = n => 'LKR ' + n.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

const qtyValue = document.getElementById('qtyValue');
const qtyInput = document.getElementById('qtyInput');
const totalPrice = document.getElementById('totalPrice');
const qtyMinus = document.getElementById('qtyMinus');
const qtyPlus = document.getElementById('qtyPlus');

// Loyalty: mirrors config/loyalty.php (the server recalculates)
const PCT_PER_POINT = <?php echo LOYALTY_PERCENT_PER_POINT; ?>, MAX_PCT = <?php echo LOYALTY_MAX_DISCOUNT_PERCENT; ?>, LKR_PER_POINT = <?php echo LOYALTY_LKR_PER_POINT; ?>;
const maxPoints = <?php echo (int)$pointsMaxUse; ?>;
const pointsRange = document.getElementById('pointsRange');
const pointsNumber = document.getElementById('pointsNumber');
function pointsUsed() {
    if (!pointsNumber) return 0;
    const v = Math.max(0, Math.min(maxPoints, parseInt(pointsNumber.value, 10) || 0));
    return v;
}
function setPoints(v) {
    v = Math.max(0, Math.min(maxPoints, parseInt(v, 10) || 0));
    if (pointsRange) pointsRange.value = v;
    if (pointsNumber) pointsNumber.value = v;
    render();
}
pointsRange?.addEventListener('input', () => setPoints(pointsRange.value));
pointsNumber?.addEventListener('input', () => { if (pointsNumber.value !== '') setPoints(pointsNumber.value); });
pointsNumber?.addEventListener('blur', () => setPoints(pointsNumber.value));
document.querySelectorAll('.lb-quick button').forEach(b => b.addEventListener('click', () => setPoints(b.dataset.points)));

function render() {
    qtyValue.textContent = qty;
    qtyInput.value = qty;
    const subtotal = unitPrice * qty;
    const pts = pointsUsed();
    const pct = Math.min(pts * PCT_PER_POINT, MAX_PCT);
    const discount = Math.round(subtotal * pct) / 100;
    const total = subtotal - discount;
    totalPrice.textContent = lkr(total);
    document.getElementById('lineLabel').textContent = qty + ' × ' + lkr(unitPrice);
    document.getElementById('lineValue').textContent = lkr(subtotal);
    const discountLine = document.getElementById('discountLine');
    discountLine.hidden = discount <= 0;
    document.getElementById('discountLabel').textContent = `🏆 Loyalty discount (${pts} pts · ${pct}%)`;
    document.getElementById('discountValue').textContent = '− ' + lkr(discount);
    const earnNote = document.getElementById('earnNote');
    if (earnNote) {
        const earn = Math.floor(total / LKR_PER_POINT);
        earnNote.textContent = earn > 0 ? `✨ You'll earn ${earn} loyalty point${earn === 1 ? '' : 's'} with this order` + (<?php echo $isGuest ? 'true' : 'false'; ?> ? ' — set a password later to use them' : '') : '';
    }
    qtyMinus.disabled = qty <= 1;
    qtyPlus.disabled = qty >= maxTickets;
}

// Mirrors phone_normalize() in config/app.php (the server re-checks)
function validPhone(raw) {
    const plus = /^\s*(\+|00)/.test(raw);
    let d = raw.replace(/\D+/g, '');
    if (raw.trim().startsWith('00')) d = d.slice(2);
    if (/^0?7\d{8}$/.test(d) || /^947\d{8}$/.test(d)) return true;
    return plus && !d.startsWith('94') && /^[1-9]\d{7,14}$/.test(d);
}

const phoneInput = document.getElementById('phone');
const phoneError = document.getElementById('phoneError');
phoneInput?.addEventListener('input', () => {
    if (phoneError.classList.contains('show') && validPhone(phoneInput.value)) {
        phoneError.classList.remove('show');
        phoneInput.classList.remove('invalid');
    }
});

qtyMinus?.addEventListener('click', () => { if (qty > 1) { qty--; render(); } });
qtyPlus?.addEventListener('click', () => { if (qty < maxTickets) { qty++; render(); } });

const form = document.getElementById('bookingForm');
const payBtn = document.getElementById('payBtn');
const payBtnLabel = document.getElementById('payBtnLabel');

// Guest fields (the server re-checks)
const guestFields = [
    [document.getElementById('guestName'), document.getElementById('guestNameError'), v => v.trim() !== ''],
    [document.getElementById('guestEmail'), document.getElementById('guestEmailError'), v => /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(v.trim())],
].filter(([input]) => input);
guestFields.forEach(([input, error, ok]) => input.addEventListener('input', () => {
    if (ok(input.value)) { error.classList.remove('show'); input.classList.remove('invalid'); }
}));

form?.addEventListener('submit', (e) => {
    const badGuest = guestFields.find(([input, , ok]) => !ok(input.value));
    if (badGuest) {
        e.preventDefault();
        badGuest[1].classList.add('show');
        badGuest[0].classList.add('invalid');
        badGuest[0].focus();
        return;
    }
    if (!validPhone(phoneInput.value)) {
        e.preventDefault();
        phoneError.classList.add('show');
        phoneInput.classList.add('invalid');
        phoneInput.focus();
        return;
    }
    payBtn.disabled = true;
    payBtnLabel.textContent = <?php echo json_encode($isFree ? 'Booking your tickets…' : 'Redirecting to secure checkout…'); ?>;
});

render();
</script>

<?php require __DIR__ . "/footer.php"; ?>
</body>
</html>
