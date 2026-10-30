<?php
session_start();
include "config/database.php";

// Admin & Super Admin access
if (!isset($_SESSION['user_id']) || !isset($_SESSION['user_role']) || !in_array($_SESSION['user_role'], ['admin', 'super_admin'], true)) {
    header("Location: login.php");
    exit();
}

// Fetch events created by this admin
$admin_id = $_SESSION['user_id'];
$result = mysqli_query($conn, "SELECT * FROM events WHERE created_by='$admin_id' ORDER BY event_date ASC");
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>My Events - TicketHub Admin</title>
<style>
body { font-family: Arial; background: #111; color: #fff; }
.container { max-width: 900px; margin: 50px auto; }
h2 { text-align: center; margin-bottom: 20px; color: #8B5CF6; }
table { width: 100%; border-collapse: collapse; background: rgba(17,17,17,0.85); border-radius: 10px; overflow: hidden; }
th, td { padding: 12px; text-align: left; border-bottom: 1px solid #333; }
th { background: #8B5CF6; color: #fff; }
tr:hover { background: rgba(139,92,246,0.2); }
.btn { padding: 6px 12px; border: none; border-radius: 6px; cursor: pointer; margin-right:5px; color:#fff; }
.edit { background: #8B5CF6; }
.delete { background: #EC4899; }
.add { display: inline-block; margin-bottom: 15px; background: linear-gradient(45deg,#8B5CF6,#EC4899); padding: 10px 20px; border-radius: 20px; text-decoration:none; color:#fff; }
</style>
</head>
<body>
<div class="container">
    <h2>My Events</h2>
    <a href="add_event.php" class="add">+ Add New Event</a>
    <table>
        <tr>
            <th>ID</th>
            <th>Title</th>
            <th>Category</th>
            <th>Date</th>
            <th>Location</th>
            <th>Tickets</th>
            <th>Price</th>
            <th>Actions</th>
        </tr>
        <?php while($row = mysqli_fetch_assoc($result)): ?>
        <tr>
            <td><?php echo $row['id']; ?></td>
            <td><?php echo $row['title']; ?></td>
            <td><?php echo $row['category']; ?></td>
            <td><?php echo date("Y-m-d H:i", strtotime($row['event_date'])); ?></td>
            <td><?php echo $row['location']; ?></td>
            <td><?php echo $row['total_tickets']; ?></td>
            <td><?php echo number_format($row['ticket_price'],2); ?></td>
            <td>
                <a href="edit_event.php?id=<?php echo $row['id']; ?>" class="btn edit">Edit</a>
                <a href="delete_event.php?id=<?php echo $row['id']; ?>" class="btn delete" onclick="return confirm('Are you sure?');">Delete</a>
            </td>
        </tr>
        <?php endwhile; ?>
    </table>
</div>
</body>
</html>