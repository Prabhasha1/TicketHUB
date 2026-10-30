<?php
session_start();

// Check if user is logged in
$loggedIn = isset($_SESSION['user_id']);
$userName = $loggedIn ? $_SESSION['user_name'] : '';
$isAdmin = isset($_SESSION['user_role']) && in_array($_SESSION['user_role'], ['admin', 'super_admin'], true);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<?php include __DIR__ . "/pwa_head.php"; ?>
<title>TicketHub - Smart Event Platform</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Sora:wght@500;600;700;800&family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
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

/* NAVBAR */
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

.logo {
    font-family: var(--font-display);
    font-size: 22px;
    font-weight: 700;
    letter-spacing: -0.01em;
    color: var(--text);
}

.logo span { color: var(--violet); }

nav {
    display: flex;
    align-items: center;
    gap: 4px;
    flex-wrap: wrap;
}

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

nav a:hover {
    color: var(--text);
    background: var(--surface);
}

nav a.cta {
    color: var(--bg);
    background: var(--text);
    margin-left: 6px;
}

nav a.cta:hover {
    background: var(--violet);
    color: #fff;
}

nav span.welcome {
    color: var(--violet);
    font-weight: 600;
}

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

/* HERO */
.hero {
    position: relative;
    text-align: center;
    padding: 130px 24px 100px;
    opacity: 0;
    animation: rise 0.9s 0.1s ease-out forwards;
}

.eyebrow {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    font-size: 13px;
    font-weight: 500;
    color: var(--violet);
    background: var(--violet-soft);
    border: 1px solid rgba(157,124,249,0.3);
    padding: 6px 14px;
    border-radius: 100px;
    margin-bottom: 28px;
}

.hero h1 {
    font-family: var(--font-display);
    font-size: 56px;
    font-weight: 700;
    line-height: 1.12;
    letter-spacing: -0.015em;
    margin: 0;
}

.hero h1 span {
    background: linear-gradient(100deg, var(--violet), var(--rose));
    -webkit-background-clip: text;
    background-clip: text;
    color: transparent;
}

.hero p {
    margin: 20px auto 0;
    max-width: 520px;
    color: var(--text-dim);
    font-size: 16px;
    line-height: 1.6;
}

.btn {
    margin-top: 32px;
    padding: 14px 32px;
    background: var(--text);
    border: none;
    color: var(--bg);
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

/* AMBIENT GLOW */
.glow {
    position: absolute;
    width: 420px;
    height: 420px;
    border-radius: 50%;
    filter: blur(90px);
    opacity: 0.22;
    pointer-events: none;
    z-index: 0;
}

.glow-violet { background: var(--violet); top: -120px; left: -100px; animation: drift 14s ease-in-out infinite; }
.glow-rose { background: var(--rose); top: -60px; right: -140px; animation: drift 14s ease-in-out infinite reverse; animation-delay: -4s; }

/* EVENTS */
.section-label {
    text-align: center;
    font-size: 13px;
    font-weight: 600;
    letter-spacing: 0.08em;
    text-transform: uppercase;
    color: var(--text-dim);
    margin: 0 0 12px;
}

.section-title {
    text-align: center;
    font-family: var(--font-display);
    font-size: 28px;
    font-weight: 600;
    margin: 0 0 50px;
}

.events {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(260px, 1fr));
    gap: 24px;
    max-width: 1080px;
    margin: 0 auto;
    padding: 0 48px 100px;
    position: relative;
    z-index: 1;
}

.card {
    background: var(--surface);
    border: 1px solid var(--border);
    padding: 28px 24px;
    border-radius: 16px;
    transition: transform 0.35s ease, border-color 0.35s ease, background 0.35s ease;
    opacity: 0;
    transform: translateY(16px);
    animation: rise 0.7s ease-out forwards;
}

.card:nth-child(1) { animation-delay: 0.15s; }
.card:nth-child(2) { animation-delay: 0.28s; }
.card:nth-child(3) { animation-delay: 0.41s; }

.card:hover {
    transform: translateY(-6px);
    border-color: rgba(157,124,249,0.4);
    background: var(--surface-hover);
}

.card .icon {
    font-size: 28px;
    margin-bottom: 14px;
    display: inline-block;
}

.card h3 {
    font-family: var(--font-display);
    margin: 0 0 6px;
    font-size: 18px;
    font-weight: 600;
}

.card p {
    color: var(--text-dim);
    font-size: 14px;
    margin: 0 0 20px;
}

.card .btn {
    margin-top: 0;
    width: 100%;
    padding: 11px 0;
    background: transparent;
    color: var(--text);
    border: 1px solid var(--border);
    font-size: 14px;
}

.card .btn:hover {
    background: var(--violet);
    border-color: var(--violet);
    color: #fff;
    box-shadow: none;
    transform: none;
}

/* FOOTER */
.footer {
    text-align: center;
    padding: 28px;
    border-top: 1px solid var(--border);
    color: var(--text-dim);
    font-size: 13px;
}

/* ANIMATIONS */
@keyframes rise {
    from { opacity: 0; transform: translateY(18px); }
    to { opacity: 1; transform: translateY(0); }
}

@keyframes drift {
    0%, 100% { transform: translate(0, 0); }
    50% { transform: translate(30px, 40px); }
}

@media (prefers-reduced-motion: reduce) {
    *, .hero, .card, .glow { animation: none !important; opacity: 1 !important; transform: none !important; }
}

@media (max-width: 640px) {
    header { padding: 18px 20px; flex-wrap: wrap; gap: 10px; }
    .hero { padding: 90px 20px 70px; }
    .hero h1 { font-size: 38px; }
    .events { padding: 0 20px 70px; }
}
</style>
</head>
<body>

<?php require __DIR__ . "/nav.php"; ?>

<section class="hero">
    <div class="glow glow-violet"></div>
    <div class="glow glow-rose"></div>
    <span class="eyebrow">AI-powered ticketing</span>
    <h1>Experience events <span>like never before</span></h1>
    <p>AI-powered ticketing • Smart pricing • Instant booking • Personalized recommendations • Loyalty rewards</p>
    <button class="btn" onclick="location.href='events.php'">Explore events</button>
</section>

<p class="section-label">What's on</p>
<h2 class="section-title">Happening soon</h2>

<section class="events">
    <div class="card">
        <span class="icon">🎵</span>
        <h3>Music Festival</h3>
        <p>Colombo Night Vibes</p>
        <button class="btn" onclick="location.href='events.php'">Buy ticket</button>
    </div>

    <div class="card">
        <span class="icon">🏏</span>
        <h3>Cricket Match</h3>
        <p>Live Stadium Action</p>
        <button class="btn" onclick="location.href='events.php'">Buy ticket</button>
    </div>

    <div class="card">
        <span class="icon">💻</span>
        <h3>Tech Summit</h3>
        <p>Future of AI & Innovation</p>
        <button class="btn" onclick="location.href='events.php'">Buy ticket</button>
    </div>
</section>

<?php require __DIR__ . "/footer.php"; ?>

</body>
</html>