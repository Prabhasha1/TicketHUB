<?php
/**
 * info.php?page=about|refunds|terms|privacy|contact
 * ------------------------------------------------------------
 * Customer care pages linked from the footer.
 * Refund rules are read from config/event_status.php so this page
 * always matches what the app enforces.
 * NOTE: Terms and Privacy are a plain-language starting draft —
 * have them reviewed before relying on them.
 * ------------------------------------------------------------
 */
session_start();
require __DIR__ . "/config/database.php";
require __DIR__ . "/config/app.php";
require __DIR__ . "/config/site.php";
require __DIR__ . "/config/event_status.php";
require_once __DIR__ . "/config/loyalty.php";

$pages = [
    'about'   => ['title' => 'About us',        'icon' => '🎟'],
    'refunds' => ['title' => 'Refund policy',   'icon' => '↩'],
    'terms'   => ['title' => 'Terms of use',    'icon' => '📜'],
    'privacy' => ['title' => 'Privacy policy',  'icon' => '🔒'],
    'contact' => ['title' => 'Help & contact',  'icon' => '💬'],
];
$page = $_GET['page'] ?? 'about';
if (!isset($pages[$page])) {
    $page = 'about';
}
$cutoff = REFUND_CUTOFF_HOURS;
$supportEmail = SITE_EMAIL !== '' ? '<a href="mailto:' . htmlspecialchars(SITE_EMAIL) . '">' . htmlspecialchars(SITE_EMAIL) . '</a>' : 'our support team';

// A few live numbers for the About page
$stats = ['events' => 0, 'customers' => 0, 'tickets' => 0];
if ($page === 'about') {
    try {
        $stats['events']    = (int)$conn->query("SELECT COUNT(*) n FROM events")->fetch_assoc()['n'];
        $stats['customers'] = (int)$conn->query("SELECT COUNT(*) n FROM users WHERE role = 'customer'")->fetch_assoc()['n'];
        $stats['tickets']   = (int)$conn->query("SELECT COALESCE(SUM(quantity), 0) n FROM bookings WHERE status = 'confirmed'")->fetch_assoc()['n'];
    } catch (mysqli_sql_exception $e) {
        error_log("info stats failed: " . $e->getMessage());
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<?php include __DIR__ . "/pwa_head.php"; ?>
<title><?php echo htmlspecialchars($pages[$page]['title']); ?> - TicketHub</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Sora:wght@600;700;800&family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
<style>
:root {
    --bg: #14121A; --surface: rgba(255,255,255,0.04); --border: rgba(255,255,255,0.08);
    --text: #F1EEF7; --text-dim: #A9A3B8; --violet: #9D7CF9; --rose: #F0729C; --green: #6FCF97; --amber: #F2C572; --blue: #6CA0F2;
    --font-display: 'Sora', 'Segoe UI', sans-serif; --font-body: 'Inter', 'Segoe UI', sans-serif;
}
* { box-sizing: border-box; }
body { margin: 0; font-family: var(--font-body); background: var(--bg); color: var(--text); }
.container { max-width: 1080px; margin: 0 auto; padding: 40px 48px 60px; display: grid; grid-template-columns: 220px 1fr; gap: 36px; align-items: start; }
.side { position: sticky; top: 100px; display: flex; flex-direction: column; gap: 4px; }
.side a { padding: 10px 14px; border-radius: 10px; color: var(--text-dim); text-decoration: none; font-size: 14px; font-weight: 500; }
.side a:hover { color: var(--text); background: var(--surface); }
.side a.active { color: var(--text); background: rgba(157,124,249,0.16); font-weight: 600; }
article { min-width: 0; animation: rise 0.45s ease-out both; }
h1 { font-family: var(--font-display); font-size: 30px; margin: 0 0 6px; }
.updated { color: var(--text-dim); font-size: 13px; margin: 0 0 28px; }
h2 { font-family: var(--font-display); font-size: 18px; margin: 30px 0 10px; }
p, li { color: var(--text-dim); font-size: 15px; line-height: 1.7; }
ul { padding-left: 20px; }
a { color: var(--violet); }
strong { color: var(--text); }
.card { background: var(--surface); border: 1px solid var(--border); border-radius: 16px; padding: 18px 20px; margin: 16px 0; }
table { width: 100%; border-collapse: collapse; font-size: 14px; }
th { text-align: left; font-size: 12px; text-transform: uppercase; letter-spacing: 0.5px; color: var(--text-dim); padding: 10px 12px; border-bottom: 1px solid var(--border); }
td { padding: 12px; border-bottom: 1px solid rgba(255,255,255,0.05); vertical-align: top; color: var(--text-dim); }
td:first-child { color: var(--text); font-weight: 600; white-space: nowrap; }
.stats { display: grid; grid-template-columns: repeat(3, 1fr); gap: 14px; margin: 20px 0; }
.stat { background: var(--surface); border: 1px solid var(--border); border-radius: 16px; padding: 18px; text-align: center; }
.stat b { display: block; font-family: var(--font-display); font-size: 26px; color: var(--violet); }
.stat span { font-size: 13px; color: var(--text-dim); }
.steps { counter-reset: step; list-style: none; padding: 0; }
.steps li { counter-increment: step; position: relative; padding-left: 44px; margin-bottom: 14px; }
.steps li::before {
    content: counter(step); position: absolute; left: 0; top: 2px; width: 30px; height: 30px; border-radius: 50%;
    display: flex; align-items: center; justify-content: center; font-weight: 700; font-size: 13px; color: #fff;
    background: linear-gradient(135deg, var(--violet), var(--rose));
}
details { background: var(--surface); border: 1px solid var(--border); border-radius: 12px; padding: 14px 18px; margin-bottom: 10px; }
summary { cursor: pointer; font-weight: 600; color: var(--text); }
details p { margin: 10px 0 0; }
.note { font-size: 13px; color: var(--amber); }
@keyframes rise { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }
@media (prefers-reduced-motion: reduce) { * { animation: none !important; } }
@media (max-width: 760px) {
    .container { grid-template-columns: 1fr; padding: 24px 16px 40px; gap: 16px; }
    .side { position: static; flex-direction: row; overflow-x: auto; }
    .side a { white-space: nowrap; }
    .stats { grid-template-columns: 1fr; }
    td:first-child { white-space: normal; }
}
</style>
</head>
<body>
<?php require __DIR__ . "/nav.php"; ?>

<div class="container">
    <nav class="side" aria-label="Customer care">
        <?php foreach ($pages as $key => $p): ?>
            <a href="info.php?page=<?php echo $key; ?>" class="<?php echo $key === $page ? 'active' : ''; ?>"><?php echo $p['icon'] . ' ' . $p['title']; ?></a>
        <?php endforeach; ?>
    </nav>

    <article>
    <?php if ($page === 'about'): ?>
        <h1>About TicketHub</h1>
        <p class="updated">Live events, without the queue.</p>
        <p><?php echo htmlspecialchars(SITE_TAGLINE); ?></p>
        <div class="stats">
            <div class="stat"><b><?php echo number_format($stats['events']); ?></b><span>events listed</span></div>
            <div class="stat"><b><?php echo number_format($stats['customers']); ?></b><span>happy customers</span></div>
            <div class="stat"><b><?php echo number_format($stats['tickets']); ?></b><span>tickets issued</span></div>
        </div>
        <h2>How it works</h2>
        <ol class="steps">
            <li><strong>Find an event</strong> — browse music, sports, theatre, tech and more on the <a href="events.php">Events</a> page.</li>
            <li><strong>Pay securely</strong> — checkout is handled by Stripe; we never see or store your card number.</li>
            <li><strong>Get your e-ticket</strong> — a QR code arrives by email and SMS, and is always in <a href="my_tickets.php">My Tickets</a>.</li>
            <li><strong>Walk in</strong> — show the QR at the door; one code admits everyone in the booking.</li>
        </ol>
        <h2>For organizers</h2>
        <p>Organizers get a dashboard to publish events, track sales and payouts, scan tickets at the door, and handle postponements, cancellations and refunds. Want to sell tickets on TicketHub? Contact us at <?php echo $supportEmail; ?>.</p>
        <h2>Why customers like us</h2>
        <ul>
            <li>Earn <strong>loyalty points</strong> on every booking (1 point per LKR <?php echo LOYALTY_LKR_PER_POINT; ?>) and spend them at checkout — <strong>1 point = <?php echo LOYALTY_PERCENT_PER_POINT; ?>% off</strong>, up to <?php echo LOYALTY_MAX_DISCOUNT_PERCENT; ?>% per order.</li>
            <li>Clear, fair <a href="info.php?page=refunds">refunds</a> when events change.</li>
            <li>Your tickets are never lost — re-send them by email or SMS any time.</li>
        </ul>

    <?php elseif ($page === 'refunds'): ?>
        <h1>Refund policy</h1>
        <p class="updated">These are the exact rules TicketHub applies when you request a refund.</p>
        <div class="card">
            <table>
                <thead><tr><th>If the event is…</th><th>Can I get a refund?</th></tr></thead>
                <tbody>
                    <tr><td>⛔ Cancelled</td><td><strong>Yes — full refund</strong>, any time, even after the original date.</td></tr>
                    <tr><td>⏳ Postponed</td><td><strong>Yes — full refund</strong> if the new arrangements don't work for you.</td></tr>
                    <tr><td>📅 Rescheduled</td><td><strong>Yes — full refund</strong> any time before the new date. Your ticket also stays valid for the new date.</td></tr>
                    <tr><td>🟢 Going ahead as planned</td><td>You can ask for a refund up to <strong><?php echo $cutoff; ?> hours before the event starts</strong>. The organizer reviews it and may approve a full or partial refund, or decline it.</td></tr>
                </tbody>
            </table>
        </div>
        <h2>When refunds aren't possible</h2>
        <ul>
            <li>The ticket has already been scanned at the door.</li>
            <li>The event is going ahead and it's less than <?php echo $cutoff; ?> hours to the start.</li>
            <li>The ticket was free.</li>
        </ul>
        <h2>How to request a refund</h2>
        <ol class="steps">
            <li>Log in and open <a href="my_tickets.php">My Tickets</a>.</li>
            <li>Under the booking, click <strong>↩ Request refund</strong>, choose a reason and send.</li>
            <li>We email you as soon as the organizer decides. You can also see the status on My Tickets, and withdraw the request while it's pending.</li>
        </ol>
        <h2>Getting your money</h2>
        <p>Approved refunds go back to the card you paid with. Banks usually take <strong>5–10 business days</strong> to show it. Once refunded, the booking is cancelled, its QR code stops working, and the loyalty points it earned are removed.</p>

    <?php elseif ($page === 'terms'): ?>
        <h1>Terms of use</h1>
        <p class="updated">Last updated <?php echo date('F j, Y', filemtime(__FILE__)); ?></p>
        <p>By creating an account or buying tickets on TicketHub you agree to these terms.</p>
        <h2>1. What TicketHub does</h2>
        <p>TicketHub sells tickets on behalf of event organizers. The organizer is responsible for running the event — its date, venue, line-up and entry rules. TicketHub is responsible for selling and delivering your tickets and handling payments and refunds through the platform.</p>
        <h2>2. Your account</h2>
        <ul>
            <li>Keep your login details private; you're responsible for activity on your account.</li>
            <li>Give us a real email and mobile number — that's where your tickets are sent.</li>
        </ul>
        <h2>3. Buying tickets</h2>
        <ul>
            <li>Prices are shown in Sri Lankan Rupees (LKR) and include everything you pay at checkout.</li>
            <li>Seats are held for you while you pay; if the checkout isn't completed in time they are released.</li>
            <li>A booking is confirmed only once payment succeeds and you receive a booking reference.</li>
            <li>You may buy up to 10 tickets per order.</li>
        </ul>
        <h2>4. Tickets and entry</h2>
        <ul>
            <li>Your QR code admits the number of people in the booking. Keep it private — anyone holding your QR code could use it to get in.</li>
            <li>Organizers may refuse entry under their own venue rules.</li>
            <li>Reselling tickets for profit is not allowed.</li>
        </ul>
        <h2>5. Changes, cancellations and refunds</h2>
        <p>If an event is postponed, rescheduled or cancelled we'll show it on My Tickets and you can request a refund as described in our <a href="info.php?page=refunds">Refund policy</a>.</p>
        <h2>6. Fair use</h2>
        <p>Don't misuse the site — no automated bulk buying, fraud, or attempts to break security. We may suspend accounts that do.</p>
        <h2>7. Contact</h2>
        <p>Questions about these terms? Contact <?php echo $supportEmail; ?>.</p>
        <p class="note">ℹ This is a plain-language draft. Please have it reviewed before relying on it.</p>

    <?php elseif ($page === 'privacy'): ?>
        <h1>Privacy policy</h1>
        <p class="updated">Last updated <?php echo date('F j, Y', filemtime(__FILE__)); ?></p>
        <h2>What we collect</h2>
        <ul>
            <li><strong>Account details:</strong> your name, email, mobile number and a securely hashed password (or your Google account email if you sign in with Google).</li>
            <li><strong>Bookings:</strong> the events you book, ticket quantities, amounts paid, check-in times at the door and refund requests.</li>
            <li><strong>Payments:</strong> handled by Stripe. We store the payment reference and amount — <strong>never your card number</strong>.</li>
            <li><strong>Preferences:</strong> the event categories you book most, used to recommend events, and your loyalty points.</li>
            <li><strong>Newsletter:</strong> your email, if you subscribe in the footer.</li>
        </ul>
        <h2>How we use it</h2>
        <ul>
            <li>To issue and deliver your tickets by email and SMS, and to let you in at the door.</li>
            <li>To process payments and refunds.</li>
            <li>To tell you about changes to events you've booked.</li>
            <li>To send event announcements, only if you subscribed.</li>
        </ul>
        <h2>Who we share it with</h2>
        <ul>
            <li><strong>The event organizer</strong> — your name, contact details and booking, so they can run the event and handle refunds.</li>
            <li><strong>Service providers</strong> that run parts of TicketHub for us: Stripe (payments), Brevo (email) and our SMS provider.</li>
        </ul>
        <p>We don't sell your personal data.</p>
        <h2>Your choices</h2>
        <ul>
            <li>View and update your details any time on your <a href="profile.php">Profile</a>.</li>
            <li>To unsubscribe from announcements or delete your account, contact <?php echo $supportEmail; ?>.</li>
        </ul>
        <p class="note">ℹ This is a plain-language draft. Please have it reviewed before relying on it.</p>

    <?php else: ?>
        <h1>Help &amp; contact</h1>
        <p class="updated">We're happy to help with bookings, tickets and refunds.</p>
        <div class="card">
            <?php if (SITE_EMAIL !== ''): ?><p style="margin: 0 0 6px;">✉ Email: <a href="mailto:<?php echo htmlspecialchars(SITE_EMAIL); ?>"><?php echo htmlspecialchars(SITE_EMAIL); ?></a></p><?php endif; ?>
            <?php if (SITE_PHONE !== ''): ?><p style="margin: 0 0 6px;">📞 Phone: <a href="tel:<?php echo htmlspecialchars(preg_replace('/[^0-9+]/', '', SITE_PHONE)); ?>"><?php echo htmlspecialchars(SITE_PHONE); ?></a></p><?php endif; ?>
            <?php if (SITE_ADDRESS !== ''): ?><p style="margin: 0;">📍 <?php echo htmlspecialchars(SITE_ADDRESS); ?></p><?php endif; ?>
            <p style="margin: 10px 0 0; font-size: 13px;">Please include your booking reference (it starts with <strong>TKT-</strong>) so we can help faster.</p>
        </div>
        <h2>Quick answers</h2>
        <details><summary>I didn't get my e-ticket email or SMS</summary><p>Open <a href="my_tickets.php">My Tickets</a> — you can view your QR there, or press <strong>Resend email</strong> / <strong>Resend SMS</strong>. Check your spam folder too.</p></details>
        <details><summary>I paid but don't see my booking</summary><p>Refresh My Tickets after a minute — we check with Stripe automatically. If it still isn't there, contact us with the time you paid.</p></details>
        <details><summary>The event was postponed or cancelled — what now?</summary><p>You'll see a notice on the booking in My Tickets and can request a full refund there. See the <a href="info.php?page=refunds">Refund policy</a>.</p></details>
        <details><summary>Can I change my email or phone number?</summary><p>Yes — go to your <a href="profile.php">Profile</a>. Changing your email asks for your current password.</p></details>
        <details><summary>How do loyalty points work?</summary><p>You earn 1 point for every LKR <?php echo LOYALTY_LKR_PER_POINT; ?> you actually pay on a booking. When you buy tickets, use the <strong>Use loyalty points</strong> slider: each point takes <?php echo LOYALTY_PERCENT_PER_POINT; ?>% off that order, up to <?php echo LOYALTY_MAX_DISCOUNT_PERCENT; ?>%. Points are only spent if the payment succeeds. If a booking is refunded, the points it earned are removed and any points you used on it are given back.</p></details>
    <?php endif; ?>
    </article>
</div>

<?php require __DIR__ . "/footer.php"; ?>
</body>
</html>
