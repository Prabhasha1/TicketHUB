<?php
session_start();
include "config/database.php";

// Admin check
if (!isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'admin') {
    header("Location: login.php");
    exit;
}

// Fetch events
$sql = "SELECT * FROM events ORDER BY event_date ASC";
$result = mysqli_query($conn, $sql);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Manage Events - TicketHub</title>
<style>
body {
    margin: 0;
    font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
    background: radial-gradient(circle at top, #111 0%, #0B0B0B 60%);
    color: #fff;
}

header {
    display: flex; justify-content: space-between; align-items: center;
    padding: 20px 40px; background: rgba(17,17,17,0.8);
    backdrop-filter: blur(10px); position: sticky; top: 0; z-index: 100;
}

.logo { font-size: 26px; font-weight: bold; color: #8B5CF6; text-shadow: 0 0 10px #8B5CF6; }
nav a { margin: 0 15px; text-decoration: none; color: #ccc; transition: 0.3s; }
nav a:hover { color: #8B5CF6; }

.container { padding: 40px; text-align:center; }

h2 { color: #8B5CF6; text-align: center; margin-bottom:30px; }

a.btn {
    padding: 8px 16px; text-decoration: none; border-radius: 30px;
    font-weight: bold; margin: 2px;
    color: #fff; transition: 0.3s;
    background: linear-gradient(45deg,#8B5CF6,#EC4899); 
    box-shadow: 0 0 10px #8B5CF6;
}
a.btn:hover { transform: scale(1.05); box-shadow: 0 0 20px #EC4899; }

/* CARD LAYOUT */
.cards {
    display: flex;
    flex-wrap: wrap;
    justify-content: center;
    gap: 20px;
}

.card {
    background: rgba(17,17,17,0.85);
    border-radius: 12px;
    width: 300px;
    overflow: hidden;
    box-shadow: 0 0 15px #8B5CF6;
    transition: transform 0.3s, box-shadow 0.3s;
}

.card:hover {
    transform: translateY(-5px);
    box-shadow: 0 0 25px #EC4899;
}

.card img {
    width: 100%;
    height: 180px;
    object-fit: cover;
}

.card-body {
    padding: 15px;
    text-align: left;
}

.card-body h3 {
    margin: 0 0 10px 0;
    color: #8B5CF6;
}

.card-body p {
    margin: 5px 0;
    color: #ccc;
    font-size: 0.9em;
}

.card-body .actions {
    margin-top: 10px;
    display: flex;
    gap: 10px;
}

.card-body .actions a {
    flex: 1;
    text-align: center;
    padding: 8px 0;
    border-radius: 25px;
    color: #fff;
    font-weight: bold;
    text-decoration: none;
    transition: 0.3s;
}

.card-body .actions a.edit { background: #3B82F6; }
.card-body .actions a.edit:hover { box-shadow: 0 0 15px #3B82F6; transform: scale(1.05); }

.card-body .actions a.delete { background: #EF4444; }
.card-body .actions a.delete:hover { box-shadow: 0 0 15px #EF4444; transform: scale(1.05); }

.footer { text-align:center; padding:20px; background:#111; margin-top:40px; color:#777; }

@media (max-width: 1000px) {
    .cards { justify-content: center; }
}

@media (max-width: 650px) {
    .card { width: 90%; }
}
</style>
</head>
<body>
<header>
    <div class="logo">TicketHub Admin</div>
    <nav>
        <a href="admin_dashboard.php">Dashboard</a>
        <a href="logout.php">Logout</a>
    </nav>
</header>

<div class="container">
<h2>Manage Events</h2>
<a href="add_event.php" class="btn">+ Add New Event</a><br><br><br><br>

<div class="cards">
<?php while($row = mysqli_fetch_assoc($result)): ?>
<div class="card">
    <?php if(!empty($row['photo']) && file_exists("eventphotos/".$row['photo'])): ?>
        <img src="eventphotos/<?php echo $row['photo']; ?>" alt="Event Photo">
    <?php else: ?>
        <img src="https://via.placeholder.com/300x180?text=No+Photo" alt="No Photo">
    <?php endif; ?>
    <div class="card-body">
        <h3><?php echo htmlspecialchars($row['title']); ?></h3>
        <p><strong>Location:</strong> <?php echo htmlspecialchars($row['location']); ?></p>
        <p><strong>Date:</strong> <?php echo date("M d, Y H:i", strtotime($row['event_date'])); ?></p>
        <p><strong>Price:</strong> RS.<?php echo number_format($row['ticket_price'],2); ?></p>
        <div class="actions">
            <a href="edit_event.php?id=<?php echo $row['id']; ?>" class="edit">Edit</a>
            <a href="delete_event.php?id=<?php echo $row['id']; ?>" class="delete" onclick="return confirm('Are you sure?');">Delete</a>
        </div>
    </div>
</div>
<?php endwhile; ?>
</div>

</div>

<div class="footer">© 2026 TicketHub. All rights reserved.</div>
</body>
</html>