<?php
session_start();
require __DIR__ . "/config/database.php";

// User info
$loggedIn = isset($_SESSION['user_id']);
$userName = $loggedIn ? $_SESSION['user_name'] : '';
$isAdmin  = isset($_SESSION['user_role']) && in_array($_SESSION['user_role'], ['admin', 'super_admin'], true);
$isSuperAdmin  = ($_SESSION['user_role'] ?? '') === 'super_admin';
$currentUserId = (int)($_SESSION['user_id'] ?? 0);

// Fetch all upcoming events
$events = [];
try {
    // Cancelled events are hidden from browsing; ticket holders still see them on My Tickets
    $result = $conn->query("SELECT * FROM events WHERE event_date >= NOW() AND status <> 'cancelled' ORDER BY event_date ASC");
    while ($row = $result->fetch_assoc()) {
        $events[] = $row;
    }
} catch (mysqli_sql_exception $e) {
    error_log("Failed to load events: " . $e->getMessage());
    $events = [];
}

// Distinct categories for the filter chips
$categories = [];
foreach ($events as $event) {
    if (!in_array($event['category'], $categories)) {
        $categories[] = $event['category'];
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<?php include __DIR__ . "/pwa_head.php"; ?>
<title>All Events - TicketHub</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Sora:wght@600;700;800&family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
<style>
:root {
    --bg: #14121A;
    --surface: rgba(255,255,255,0.04);
    --surface-hover: rgba(255,255,255,0.07);
    --field: rgba(255,255,255,0.06);
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

.logo { font-family: var(--font-display); font-size: 22px; font-weight: 700; letter-spacing: -0.01em; color: var(--text); }
.logo span { color: var(--violet); }

nav { display: flex; align-items: center; gap: 4px; flex-wrap: wrap; }

nav a, nav span {
    padding: 8px 14px;
    border-radius: 8px;
    text-decoration: none;
    color: var(--text-dim);
    font-size: 14px;
    font-weight: 500;
    transition: color 0.25s ease, background 0.25s ease;
    white-space: nowrap;
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

.hero {
    position: relative;
    text-align: center;
    padding: 90px 24px 50px;
    opacity: 0;
    animation: rise 0.8s ease-out forwards;
}

.glow {
    position: absolute;
    width: 380px;
    height: 380px;
    border-radius: 50%;
    filter: blur(90px);
    opacity: 0.18;
    pointer-events: none;
    z-index: 0;
}
.glow-violet { background: var(--violet); top: -60px; left: 10%; animation: drift 16s ease-in-out infinite; }
.glow-rose { background: var(--rose); top: -40px; right: 10%; animation: drift 16s ease-in-out infinite reverse; animation-delay: -5s; }

.hero h1 {
    position: relative;
    z-index: 1;
    font-family: var(--font-display);
    font-size: 44px;
    font-weight: 700;
    margin: 0;
    letter-spacing: -0.01em;
}

.hero p {
    position: relative;
    z-index: 1;
    margin: 14px auto 0;
    max-width: 480px;
    color: var(--text-dim);
    font-size: 16px;
}

.controls {
    position: relative;
    z-index: 1;
    max-width: 760px;
    margin: 36px auto 0;
    padding: 0 24px;
    opacity: 0;
    animation: rise 0.8s 0.15s ease-out forwards;
}

.search-bar {
    display: flex;
    align-items: center;
    gap: 10px;
    background: var(--field);
    border: 1px solid var(--border);
    border-radius: 14px;
    padding: 12px 18px;
    transition: border-color 0.25s ease, box-shadow 0.25s ease;
}

.search-bar:focus-within { border-color: var(--violet); box-shadow: 0 0 0 3px var(--violet-soft); }
.search-bar svg { flex-shrink: 0; opacity: 0.6; }

.search-bar input {
    flex: 1;
    background: none;
    border: none;
    outline: none;
    color: var(--text);
    font-family: var(--font-body);
    font-size: 15px;
}

.search-bar input::placeholder { color: #6e6a7d; }

.chips {
    display: flex;
    gap: 10px;
    flex-wrap: wrap;
    margin-top: 16px;
    justify-content: center;
}

.chip {
    padding: 7px 16px;
    border-radius: 100px;
    border: 1px solid var(--border);
    background: var(--surface);
    color: var(--text-dim);
    font-size: 13px;
    font-weight: 500;
    cursor: pointer;
    transition: all 0.2s ease;
}

.chip:hover { border-color: var(--violet); color: var(--text); }

.chip.active {
    background: var(--violet);
    border-color: var(--violet);
    color: #fff;
}

.events {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
    gap: 26px;
    max-width: 1180px;
    margin: 0 auto;
    padding: 56px 48px 100px;
}

.card {
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: 18px;
    overflow: hidden;
    transition: transform 0.35s ease, border-color 0.35s ease, background 0.35s ease;
    display: flex;
    flex-direction: column;
    opacity: 0;
    transform: translateY(18px);
    animation: rise 0.6s ease-out forwards;
}

.card:hover {
    transform: translateY(-6px);
    border-color: rgba(157,124,249,0.4);
    background: var(--surface-hover);
}

.card .thumb {
    width: 100%;
    height: 170px;
    object-fit: cover;
    border-bottom: 1px solid var(--border);
    display: block;
}

.card .thumb-fallback {
    width: 100%;
    height: 170px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 40px;
    background: linear-gradient(135deg, rgba(157,124,249,0.15), rgba(240,114,156,0.15));
    border-bottom: 1px solid var(--border);
}

.card-body { padding: 20px; display: flex; flex-direction: column; flex: 1; }

.category-tag {
    align-self: flex-start;
    font-size: 11px;
    font-weight: 600;
    letter-spacing: 0.04em;
    text-transform: uppercase;
    color: var(--violet);
    background: var(--violet-soft);
    padding: 4px 10px;
    border-radius: 100px;
    margin-bottom: 10px;
}

.card h3 {
    font-family: var(--font-display);
    margin: 0 0 10px;
    font-size: 18px;
    font-weight: 600;
    line-height: 1.3;
}

.meta-row {
    display: flex;
    align-items: center;
    gap: 6px;
    color: var(--text-dim);
    font-size: 13px;
    margin-bottom: 6px;
}

.location-link {
    color: var(--text-dim);
    text-decoration: none;
    border-bottom: 1px dashed transparent;
    transition: color 0.2s ease, border-color 0.2s ease;
}

.location-link:hover {
    color: var(--violet);
    border-bottom-color: var(--violet);
}

.location-link:active { transform: translateY(1px); }

.countdown {
    font-size: 12px;
    color: var(--rose);
    font-weight: 600;
    margin-bottom: 14px;
}

.price-row {
    display: flex;
    align-items: baseline;
    justify-content: space-between;
    margin-top: auto;
    padding-top: 14px;
    border-top: 1px solid var(--border);
}

.price { font-family: var(--font-display); font-size: 18px; font-weight: 700; }
.price span { font-size: 12px; color: var(--text-dim); font-weight: 400; }

.card button {
    margin-top: 14px;
    width: 100%;
    padding: 12px 0;
    border: none;
    border-radius: 100px;
    background: var(--text);
    color: var(--bg);
    font-family: var(--font-body);
    font-weight: 600;
    font-size: 14px;
    cursor: pointer;
    transition: background 0.25s ease, color 0.25s ease, transform 0.25s ease;
}

.card button:hover { background: var(--violet); color: #fff; transform: translateY(-2px); }

.status-note { margin-top: 12px; font-size: 12.5px; font-weight: 600; padding: 7px 10px; border-radius: 8px; }
.status-note.amber { color: #F2C572; background: rgba(242,197,114,0.1); border: 1px solid rgba(242,197,114,0.3); }
.status-note.blue  { color: #6CA0F2; background: rgba(108,160,242,0.1); border: 1px solid rgba(108,160,242,0.3); }

.card a.admin-details {
    display: block; margin-top: 8px; padding: 9px 0; text-align: center; border-radius: 100px;
    font-size: 13px; font-weight: 600; text-decoration: none;
    color: #CBB8FC; background: rgba(157,124,249,0.12); border: 1px solid rgba(157,124,249,0.3);
    transition: background 0.2s ease, color 0.2s ease;
}
.card a.admin-details:hover { background: rgba(157,124,249,0.28); color: #fff; }

.empty-state { grid-column: 1 / -1; text-align: center; padding: 60px 20px; color: var(--text-dim); }
.empty-state .icon { font-size: 40px; margin-bottom: 12px; display: block; }

.footer { text-align: center; padding: 28px; border-top: 1px solid var(--border); color: var(--text-dim); font-size: 13px; }

@keyframes rise { from { opacity: 0; transform: translateY(18px); } to { opacity: 1; transform: translateY(0); } }
@keyframes drift { 0%, 100% { transform: translate(0,0); } 50% { transform: translate(25px, 30px); } }

@media (prefers-reduced-motion: reduce) {
    *, .hero, .controls, .card, .glow { animation: none !important; opacity: 1 !important; transform: none !important; }
}

@media (max-width: 640px) {
    header { padding: 18px 20px; }
    .hero { padding: 60px 20px 40px; }
    .hero h1 { font-size: 32px; }
    .events { padding: 36px 20px 70px; }
}
</style>
</head>
<body>

<?php require __DIR__ . "/nav.php"; ?>

<section class="hero">
    <div class="glow glow-violet"></div>
    <div class="glow glow-rose"></div>
    <h1>Find your next night out</h1>
    <p>Concerts, sports, tech summits, and more — grab your tickets before they're gone.</p>
</section>

<div class="controls">
    <div class="search-bar">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line></svg>
        <input type="text" id="searchInput" placeholder="Search events, venues, cities…">
    </div>
    <div class="chips" id="chipRow">
        <button class="chip active" data-category="all">All</button>
        <?php foreach ($categories as $category): ?>
            <button class="chip" data-category="<?php echo htmlspecialchars($category); ?>"><?php echo htmlspecialchars($category); ?></button>
        <?php endforeach; ?>
    </div>
</div>

<section class="events" id="eventsGrid">
<?php if (count($events) === 0): ?>
    <div class="empty-state">
        <span class="icon">🎟️</span>
        <p>No upcoming events right now — check back soon.</p>
    </div>
<?php else: ?>
    <?php foreach ($events as $index => $event):
        $eventTimestamp = strtotime($event['event_date']);
        $daysAway = ceil(($eventTimestamp - time()) / 86400);
        if ($daysAway <= 0) {
            $countdownText = "Happening today";
        } elseif ($daysAway === 1) {
            $countdownText = "Tomorrow";
        } else {
            $countdownText = "In $daysAway days";
        }
        $delay = min($index * 0.08, 0.8);
    ?>
        <div class="card" data-category="<?php echo htmlspecialchars($event['category']); ?>" data-title="<?php echo htmlspecialchars(mb_strtolower($event['title'] . ' ' . $event['location'])); ?>" style="animation-delay: <?php echo $delay; ?>s;">
            <?php if (!empty($event['photo']) && file_exists(__DIR__ . "/eventphotos/" . $event['photo'])): ?>
                <img class="thumb" src="eventphotos/<?php echo htmlspecialchars($event['photo']); ?>" alt="<?php echo htmlspecialchars($event['title']); ?>">
            <?php else: ?>
                <div class="thumb-fallback">🎫</div>
            <?php endif; ?>
            <div class="card-body">
                <span class="category-tag"><?php echo htmlspecialchars($event['category']); ?></span>
                <h3><?php echo htmlspecialchars($event['title']); ?></h3>
                <div class="meta-row">📍 <a class="location-link" href="https://www.google.com/maps/search/?api=1&query=<?php echo urlencode($event['location']); ?>" target="_blank" rel="noopener noreferrer"><?php echo htmlspecialchars($event['location']); ?></a></div>
                <div class="meta-row">🗓 <?php echo date("M d, Y · h:i A", $eventTimestamp); ?></div>
                <div class="countdown"><?php echo $countdownText; ?></div>
                <div class="price-row">
                    <div class="price">LKR <?php echo number_format($event['ticket_price'], 2); ?> <span>/ ticket</span></div>
                </div>
                <?php $evStatus = $event['status'] ?? 'on_sale'; ?>
                <?php if ($evStatus === 'postponed'): ?>
                    <div class="status-note amber">⏳ Postponed — new date coming soon</div>
                <?php elseif ($evStatus === 'sales_paused'): ?>
                    <div class="status-note amber">⏸ Ticket sales paused</div>
                <?php elseif ($evStatus === 'rescheduled'): ?>
                    <div class="status-note blue">📅 Rescheduled to this new date</div>
                <?php endif; ?>
                <button onclick="location.href='tickets.php?event_id=<?php echo (int)$event['id']; ?>'"><?php echo in_array($evStatus, ['postponed', 'sales_paused'], true) ? 'View details' : 'Buy ticket'; ?></button>
                <?php if ($isSuperAdmin || ($isAdmin && (int)$event['created_by'] === $currentUserId)): ?>
                    <a class="admin-details" href="event_details.php?id=<?php echo (int)$event['id']; ?>">📊 Event details (admin)</a>
                <?php endif; ?>
            </div>
        </div>
    <?php endforeach; ?>
<?php endif; ?>
</section>

<?php require __DIR__ . "/footer.php"; ?>

<script>
const searchInput = document.getElementById('searchInput');
const chipRow = document.getElementById('chipRow');
const cards = document.querySelectorAll('#eventsGrid .card');
let activeCategory = 'all';

function applyFilters() {
    const query = searchInput.value.trim().toLowerCase();
    cards.forEach(card => {
        const matchesCategory = activeCategory === 'all' || card.dataset.category === activeCategory;
        const matchesSearch = query === '' || card.dataset.title.includes(query);
        card.style.display = (matchesCategory && matchesSearch) ? '' : 'none';
    });
}

searchInput.addEventListener('input', applyFilters);

chipRow.addEventListener('click', (e) => {
    const chip = e.target.closest('.chip');
    if (!chip) return;
    chipRow.querySelectorAll('.chip').forEach(c => c.classList.remove('active'));
    chip.classList.add('active');
    activeCategory = chip.dataset.category;
    applyFilters();
});
</script>

</body>
</html>