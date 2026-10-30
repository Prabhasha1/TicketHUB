<?php
session_start();

// Allow admin and super_admin access
if (!isset($_SESSION['user_id']) || !isset($_SESSION['user_role']) || !in_array($_SESSION['user_role'], ['admin', 'super_admin'], true)) {
    header("Location: login.php");
    exit();
}

require __DIR__ . "/config/database.php";

$adminName = $_SESSION['user_name'];
$isSuperAdmin = ($_SESSION['user_role'] === 'super_admin');

// Pull live numbers so the dashboard means something
$totalEvents = 0;
$upcomingEvents = 0;
$totalBookings = 0;
$totalAdmins = 0;
$grossSales = 0.0;
$commissionEarned = 0.0;

// Super Admins see the whole platform; Admins see only the events they created
$currentUserId = (int)$_SESSION['user_id'];
$ownerFilter   = $isSuperAdmin ? "1 = 1" : "e.created_by = $currentUserId";

try {
    $eventStats = $conn->query(
        "SELECT COUNT(*) AS total, SUM(e.event_date >= NOW()) AS upcoming FROM events e WHERE $ownerFilter"
    )->fetch_assoc();
    $totalEvents    = (int)$eventStats['total'];
    $upcomingEvents = (int)$eventStats['upcoming'];

    $salesStats = $conn->query(
        "SELECT COUNT(*) AS bookings, COALESCE(SUM(b.total_price), 0) AS gross, COALESCE(SUM(b.commission_amount), 0) AS commission
         FROM bookings b JOIN events e ON e.id = b.event_id
         WHERE b.status = 'confirmed' AND $ownerFilter"
    )->fetch_assoc();
    $totalBookings    = (int)$salesStats['bookings'];
    $grossSales       = (float)$salesStats['gross'];
    $commissionEarned = (float)$salesStats['commission'];

    if ($isSuperAdmin) {
        $totalAdmins = (int)$conn->query("SELECT COUNT(*) AS c FROM users WHERE role IN ('admin', 'super_admin')")->fetch_assoc()['c'];
    }
} catch (mysqli_sql_exception $e) {
    error_log("Admin dashboard stats failed: " . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<?php include __DIR__ . "/pwa_head.php"; ?>
<title>Admin Dashboard - TicketHub</title>
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
    --violet: #9D7CF9;
    --violet-soft: rgba(157,124,249,0.18);
    --rose: #F0729C;
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

header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 22px 48px;
    background: rgba(20,18,26,0.75);
    backdrop-filter: blur(14px);
    border-bottom: 1px solid var(--border);
    position: sticky;
    top: 0;
    z-index: 100;
}

.logo { font-family: var(--font-display); font-size: 22px; font-weight: 700; letter-spacing: -0.01em; }
.logo span { color: var(--violet); }

nav { display: flex; align-items: center; gap: 4px; }

nav a {
    padding: 8px 14px;
    border-radius: 8px;
    text-decoration: none;
    color: var(--text-dim);
    font-size: 14px;
    font-weight: 500;
    transition: color 0.25s ease, background 0.25s ease;
}

nav a:hover { color: var(--text); background: var(--surface); }
nav a.cta { color: var(--bg); background: var(--text); margin-left: 6px; }
nav a.cta:hover { background: var(--violet); color: #fff; }

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

nav span.welcome {
    color: var(--violet);
    font-weight: 600;
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

.main {
    max-width: 1080px;
    margin: 0 auto;
    padding: 60px 48px 100px;
    position: relative;
}

.glow {
    position: absolute;
    width: 380px;
    height: 380px;
    border-radius: 50%;
    filter: blur(100px);
    opacity: 0.15;
    pointer-events: none;
    top: -80px;
    right: -100px;
    background: var(--violet);
    z-index: 0;
}

.intro {
    position: relative;
    z-index: 1;
    opacity: 0;
    animation: rise 0.7s ease-out forwards;
}

.eyebrow {
    display: inline-block;
    font-size: 13px;
    font-weight: 500;
    color: var(--violet);
    background: var(--violet-soft);
    border: 1px solid rgba(157,124,249,0.3);
    padding: 5px 14px;
    border-radius: 100px;
    margin-bottom: 16px;
}

h1 {
    font-family: var(--font-display);
    font-size: 36px;
    font-weight: 700;
    margin: 0 0 8px;
    letter-spacing: -0.01em;
}

.intro p {
    color: var(--text-dim);
    font-size: 15px;
    max-width: 520px;
    margin: 0 0 36px;
    line-height: 1.6;
}

.stats {
    position: relative;
    z-index: 1;
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 18px;
    margin-bottom: 40px;
}

.stat-card {
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: 16px;
    padding: 22px 24px;
    opacity: 0;
    transform: translateY(14px);
    animation: rise 0.6s ease-out forwards;
}

.stat-card:nth-child(1) { animation-delay: 0.1s; }
.stat-card:nth-child(2) { animation-delay: 0.2s; }
.stat-card:nth-child(3) { animation-delay: 0.3s; }

.stat-card .label {
    color: var(--text-dim);
    font-size: 13px;
    font-weight: 500;
    margin-bottom: 8px;
}

.stat-card .value {
    font-family: var(--font-display);
    font-size: 30px;
    font-weight: 700;
}

.actions {
    position: relative;
    z-index: 1;
    display: flex;
    gap: 14px;
    flex-wrap: wrap;
    opacity: 0;
    animation: rise 0.6s 0.4s ease-out forwards;
}

.btn {
    padding: 13px 28px;
    border: none;
    color: var(--bg);
    background: var(--text);
    font-family: var(--font-body);
    font-size: 15px;
    font-weight: 600;
    border-radius: 100px;
    cursor: pointer;
    transition: transform 0.25s ease, background 0.25s ease, color 0.25s ease, box-shadow 0.25s ease;
}

.btn:hover {
    background: var(--violet);
    color: #fff;
    transform: translateY(-2px);
    box-shadow: 0 12px 30px -8px rgba(157,124,249,0.55);
}

.btn.ghost {
    background: transparent;
    color: var(--text);
    border: 1px solid var(--border);
}

.btn.ghost:hover { border-color: var(--violet); box-shadow: none; }

@keyframes rise { from { opacity: 0; transform: translateY(16px); } to { opacity: 1; transform: translateY(0); } }

@media (prefers-reduced-motion: reduce) {
    *, .intro, .stat-card, .actions { animation: none !important; opacity: 1 !important; transform: none !important; }
}

@media (max-width: 720px) {
    .stats { grid-template-columns: 1fr; }
    .main { padding: 40px 24px 70px; }
    h1 { font-size: 28px; }
}
</style>
</head>
<body>

<?php require __DIR__ . "/nav.php"; ?>

<div class="main">
    <div class="glow"></div>

    <div class="intro">
        <span class="eyebrow">Admin dashboard</span>
        <h1>Welcome, <?php echo htmlspecialchars($adminName); ?></h1>
        <p><?php echo $isSuperAdmin
            ? 'Platform-wide view: every organizer\'s events, bookings, and TicketHUB commission.'
            : 'Manage the events you run on TicketHUB, track bookings, and see your earnings after commission.'; ?></p>
    </div>

    <div class="stats">
        <div class="stat-card">
            <div class="label">Total events</div>
            <div class="value"><?php echo $totalEvents; ?></div>
        </div>
        <div class="stat-card">
            <div class="label">Upcoming events</div>
            <div class="value"><?php echo $upcomingEvents; ?></div>
        </div>
        <div class="stat-card">
            <div class="label">Confirmed bookings</div>
            <div class="value"><?php echo $totalBookings; ?></div>
        </div>
        <div class="stat-card" style="border-color: rgba(242, 197, 114, 0.35); background: rgba(242, 197, 114, 0.07);">
            <div class="label" style="color: #F2C572;">TicketHUB commission</div>
            <div class="value" style="color: #F2C572; font-size: 24px;">LKR <?php echo number_format($commissionEarned, 2); ?></div>
        </div>
        <div class="stat-card" style="border-color: rgba(111, 207, 151, 0.35); background: rgba(111, 207, 151, 0.07);">
            <div class="label" style="color: #6FCF97;"><?php echo $isSuperAdmin ? 'Organizer payouts' : 'Your earnings'; ?></div>
            <div class="value" style="color: #6FCF97; font-size: 24px;">LKR <?php echo number_format($grossSales - $commissionEarned, 2); ?></div>
        </div>
        <?php if ($isSuperAdmin): ?>
        <div class="stat-card" style="border-color: rgba(157, 124, 249, 0.35); background: rgba(157, 124, 249, 0.08);">
            <div class="label" style="color: #CBB8FC;">Total Admins</div>
            <div class="value" style="color: #9D7CF9;"><?php echo $totalAdmins; ?></div>
        </div>
        <?php endif; ?>
    </div>

    <div class="actions">
        <button class="btn" onclick="location.href='manage_events.php'">Go to manage events</button>
        <button class="btn ghost" onclick="location.href='add_event.php'">Add new event</button>
        <button class="btn ghost" onclick="location.href='scan_tickets.php'">📷 Scan tickets</button>
        <?php if ($isSuperAdmin): ?>
        <button class="btn" style="background: #9D7CF9; color: #fff;" onclick="location.href='manage_admins.php'">👑 Admins Management</button>
        <?php endif; ?>
    </div>
</div>

<?php require __DIR__ . "/footer.php"; ?>
</body>
</html>