<?php
session_start();
include "config/database.php";

// User info
$loggedIn = isset($_SESSION['user_id']);
$userName = $loggedIn ? $_SESSION['user_name'] : '';
$isAdmin  = isset($_SESSION['user_role']) && $_SESSION['user_role'] === 'admin';

// Fetch all events
$sql = "SELECT * FROM events ORDER BY event_date ASC";
$result = mysqli_query($conn, $sql);
$events = [];
if($result){
    while($row = mysqli_fetch_assoc($result)){
        $events[] = $row;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>All Events - TicketHub</title>
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
.logo { font-size: 26px; font-weight: bold; color: #8B5CF6; text-shadow: 0 0 10px #8B5CF6; }
nav a, nav span { margin: 0 15px; text-decoration: none; color: #ccc; transition: 0.3s; }
nav a:hover { color: #8B5CF6; }

/* HERO */
.hero {
    text-align: center;
    padding: 80px 20px;
}
.hero h1 { font-size: 48px; color: #8B5CF6; text-shadow: 0 0 20px #8B5CF6; }
.hero p { margin-top: 10px; color: #aaa; font-size: 18px; }

/* EVENTS GRID */
.events {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
    gap: 30px;
    padding: 50px 40px;
}

.card {
    background: rgba(255,255,255,0.05);
    border-radius: 16px;
    backdrop-filter: blur(10px);
    overflow: hidden;
    transition: 0.4s;
    position: relative;
    cursor: pointer;
    display: flex;
    flex-direction: column;
}
.card img {
    width: 100%;
    height: 180px;
    object-fit: cover;
    border-bottom: 1px solid #333;
}
.card h3 {
    margin: 15px 20px 5px;
    font-size: 20px;
    color: #fff;
}
.card p {
    margin: 4px 20px;
    color: #ccc;
    font-size: 14px;
}
.card button {
    margin: 15px 20px 20px;
    padding: 10px;
    border: none;
    border-radius: 25px;
    background: linear-gradient(45deg,#8B5CF6,#EC4899);
    color: #fff;
    font-weight: bold;
    cursor: pointer;
    box-shadow: 0 0 10px #8B5CF6;
    transition: 0.3s;
}
.card button:hover { transform: scale(1.05); box-shadow: 0 0 20px #EC4899; }
.card:hover { transform: translateY(-8px) scale(1.02); box-shadow: 0 0 25px #8B5CF6; }

/* FOOTER */
.footer { text-align:center; padding:20px; background:#111; margin-top:40px; color:#777; }

/* RESPONSIVE */
@media(max-width:900px){
    .events { grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); }
}
@media(max-width:600px){
    .events { grid-template-columns: 1fr; padding: 20px; }
}
</style>
</head>
<body>

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
            <span>Welcome, <?php echo $userName; ?></span>
            <a href="logout.php">Logout</a>
        <?php else: ?>
            <a href="index.php">Home</a>
            <a href="events.php">Events</a>
            <a href="login.php">Login</a>
            <a href="register.php">Register</a>
        <?php endif; ?>
    </nav>
</header>

<section class="hero">
    <h1>🎉 Explore Events</h1>
    <p>Concerts, sports, tech summits, and more – grab your tickets now!</p>
</section>

<section class="events">
<?php if(count($events) === 0): ?>
    <p style="grid-column:1/-1; text-align:center; color:#aaa;">No events available at the moment.</p>
<?php else: ?>
    <?php foreach($events as $event): ?>
        <div class="card">
            <?php if(!empty($event['photo']) && file_exists("eventphotos/".$event['photo'])): ?>
                <img src="eventphotos/<?php echo $event['photo']; ?>" alt="Event Photo">
            <?php else: ?>
                <img src="https://via.placeholder.com/400x180?text=No+Photo" alt="No Photo">
            <?php endif; ?>
            <h3><?php echo htmlspecialchars($event['title']); ?></h3>
            <p>📍 <?php echo htmlspecialchars($event['location']); ?></p>
            <p>🗓 <?php echo date("M d, Y H:i", strtotime($event['event_date'])); ?></p>
            <p>💰 LKR <?php echo number_format($event['ticket_price'],2); ?></p>
            <button onclick="location.href='tickets.php?event_id=<?php echo $event['id']; ?>'">Buy Ticket</button>
        </div>
    <?php endforeach; ?>
<?php endif; ?>
</section>

<div class="footer">© 2026 TicketHub. All rights reserved.</div>

</body>
</html>