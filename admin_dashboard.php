<?php
session_start();

// Only allow admin access
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'admin') {
    header("Location: login.php");
    exit();
}

$adminName = $_SESSION['user_name'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Admin Dashboard - TicketHub</title>
<style>
body {
    margin: 0;
    font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
    background: #0B0B0B;
    color: #fff;
}

/* NAVBAR */
header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 20px 40px;
    background: rgba(17,17,17,0.9);
    position: sticky;
    top: 0;
}

nav a {
    margin: 0 15px;
    text-decoration: none;
    color: #ccc;
    transition: 0.3s;
}

nav a:hover {
    color: #8B5CF6;
}

/* MAIN CONTENT */
.main {
    padding: 50px;
}

h1 {
    color: #8B5CF6;
}

.btn {
    margin-top: 20px;
    padding: 12px 25px;
    background: linear-gradient(45deg, #8B5CF6, #EC4899);
    border: none;
    color: white;
    font-size: 16px;
    border-radius: 20px;
    cursor: pointer;
    transition: 0.4s;
}

.btn:hover {
    transform: scale(1.05);
    box-shadow: 0 0 20px #EC4899;
}
</style>
</head>
<body>

<header>
    <div class="logo">TicketHub Admin</div>
    <nav>
        <a href="admin_dashboard.php">Dashboard</a>
        <a href="manage_events.php">Manage Events</a>
        <a href="logout.php">Logout</a>
    </nav>
</header>

<div class="main">
    <h1>Welcome, <?php echo $adminName; ?>!</h1>
    <p>From here, you can manage all events, view user activity, and oversee the platform.</p>

    <button class="btn" onclick="location.href='manage_events.php'">Go to Manage Events</button>
</div>

</body>
</html>