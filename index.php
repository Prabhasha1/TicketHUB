<?php
session_start();

// Check if user is logged in
$loggedIn = isset($_SESSION['user_id']);
$userName = $loggedIn ? $_SESSION['user_name'] : '';
$isAdmin = isset($_SESSION['user_role']) && $_SESSION['user_role'] === 'admin';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>TicketHub - Smart Event Platform</title>
<style>
body {
    margin: 0;
    font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
    background: radial-gradient(circle at top, #111 0%, #0B0B0B 60%);
    color: #fff;
    overflow-x: hidden;
}

/* NAVBAR */
header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 20px 40px;
    background: rgba(17,17,17,0.8);
    backdrop-filter: blur(10px);
    position: sticky;
    top: 0;
    z-index: 100;
}

.logo {
    font-size: 26px;
    font-weight: bold;
    color: #8B5CF6;
    text-shadow: 0 0 10px #8B5CF6;
}

nav a, nav span {
    margin: 0 15px;
    text-decoration: none;
    color: #ccc;
    transition: 0.3s;
}

nav a:hover {
    color: #8B5CF6;
}

/* HERO */
.hero {
    text-align: center;
    padding: 120px 20px;
    animation: fadeIn 1.5s ease-in-out;
}

.hero h1 {
    font-size: 52px;
}

.hero span {
    color: #8B5CF6;
    text-shadow: 0 0 20px #8B5CF6;
}

.hero p {
    margin-top: 10px;
    color: #aaa;
}

.btn {
    margin-top: 25px;
    padding: 14px 30px;
    background: linear-gradient(45deg, #8B5CF6, #EC4899);
    border: none;
    color: white;
    font-size: 16px;
    border-radius: 30px;
    cursor: pointer;
    transition: 0.4s;
    box-shadow: 0 0 15px #8B5CF6;
}

.btn:hover {
    transform: scale(1.1);
    box-shadow: 0 0 25px #EC4899;
}

/* FLOATING GLOW */
.glow {
    position: absolute;
    width: 200px;
    height: 200px;
    background: #8B5CF6;
    filter: blur(120px);
    opacity: 0.4;
    animation: float 6s infinite ease-in-out;
}

.glow2 {
    right: 0;
    background: #EC4899;
    animation-delay: 2s;
}

/* EVENTS */
.events {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
    gap: 25px;
    padding: 50px;
}

.card {
    background: rgba(255,255,255,0.05);
    padding: 20px;
    border-radius: 16px;
    backdrop-filter: blur(10px);
    transition: 0.4s;
    position: relative;
    overflow: hidden;
}

.card::before {
    content: '';
    position: absolute;
    top: 0;
    left: -100%;
    width: 100%;
    height: 100%;
    background: linear-gradient(120deg, transparent, rgba(139,92,246,0.4), transparent);
    transition: 0.5s;
}

.card:hover::before {
    left: 100%;
}

.card:hover {
    transform: translateY(-10px) scale(1.02);
    box-shadow: 0 0 25px #8B5CF6;
}

.card h3 {
    margin: 0;
}

.card p {
    color: #bbb;
}

/* FOOTER */
.footer {
    text-align: center;
    padding: 20px;
    background: #111;
    margin-top: 40px;
    color: #777;
}

/* ANIMATIONS */
@keyframes fadeIn {
    from {opacity: 0; transform: translateY(30px);} 
    to {opacity: 1; transform: translateY(0);} 
}

@keyframes float {
    0% { transform: translateY(0px);} 
    50% { transform: translateY(-40px);} 
    100% { transform: translateY(0px);} 
}
</style>
</head>
<body>

<div class="glow"></div>
<div class="glow glow2"></div>

<header>
    <div class="logo">TicketHub</div>
    <nav>
        <?php if($isAdmin): ?>
            <a href="admin_dashboard.php">Admin Dashboard</a>
            <a href="manage_events.php">Manage Events</a>
            <a href="logout.php">Logout</a>
        <?php elseif($loggedIn): ?>
            <a href="index.php">Home</a>
            <a href="events.php">Events</a>
            <a href="tickets.php">My Tickets</a>
            <a href="rewards.php">Rewards</a>
            <span>Welcome, <?php echo $userName; ?></span>
            <a href="logout.php">Logout</a>
        <?php else: ?>
            <a href="index.php">Home</a>
            <a href="events.php">Events</a>
            <a href="tickets.php">My Tickets</a>
            <a href="login.php">Login</a>
            <a href="register.php">Register</a>
        <?php endif; ?>
    </nav>
</header>

<section class="hero">
    <h1>Experience Events Like <span>Never Before</span></h1>
    <p>AI-powered ticketing • Smart pricing • Instant booking • Personalized Recommendations • Loyalty Rewards</p>
    <button class="btn" onclick="location.href='events.php'">Explore Events</button>
</section>

<section class="events">
    <!-- Example Event Cards -->
    <div class="card">
        <h3>🎵 Music Festival</h3>
        <p>Colombo Night Vibes</p>
        <button class="btn" onclick="location.href='events.php'">Buy Ticket</button>
    </div>

    <div class="card">
        <h3>🏏 Cricket Match</h3>
        <p>Live Stadium Action</p>
        <button class="btn" onclick="location.href='events.php'">Buy Ticket</button>
    </div>

    <div class="card">
        <h3>💻 Tech Summit</h3>
        <p>Future of AI & Innovation</p>
        <button class="btn" onclick="location.href='events.php'">Buy Ticket</button>
    </div>
</section>

<div class="footer">
    © 2026 TicketHub. All rights reserved.
</div>

</body>
</html>