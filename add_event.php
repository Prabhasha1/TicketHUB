<?php
session_start();
include "config/database.php";

// Only admin can access
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'admin') {
    header("Location: login.php");
    exit();
}

// Handle form submission
if (isset($_POST['submit'])) {

    $title = mysqli_real_escape_string($conn, $_POST['title']);
    $description = mysqli_real_escape_string($conn, $_POST['description']);
    $category = mysqli_real_escape_string($conn, $_POST['category']);
    $location = mysqli_real_escape_string($conn, $_POST['location']);
    $event_date = mysqli_real_escape_string($conn, $_POST['event_date']);
    $ticket_price = mysqli_real_escape_string($conn, $_POST['ticket_price']);
    $total_tickets = mysqli_real_escape_string($conn, $_POST['total_tickets']);
    $created_by = $_SESSION['user_id'];

    // Handle photo upload
    $photo_name = '';
    if (isset($_FILES['photo']) && $_FILES['photo']['error'] === 0) {
        $upload_dir = "eventphotos/";
        $photo_tmp = $_FILES['photo']['tmp_name'];
        $photo_ext = pathinfo($_FILES['photo']['name'], PATHINFO_EXTENSION);
        $photo_name = time() . '_' . rand(1000,9999) . '.' . $photo_ext;
        $photo_path = $upload_dir . $photo_name;

        if (!is_dir($upload_dir)) {
            mkdir($upload_dir, 0777, true);
        }

        if (!move_uploaded_file($photo_tmp, $photo_path)) {
            $message = "Failed to upload photo!";
        }
    }

    // Insert into database
    $sql = "INSERT INTO events (title, description, category, location, event_date, ticket_price, total_tickets, created_by, photo) 
            VALUES ('$title','$description','$category','$location','$event_date','$ticket_price','$total_tickets','$created_by','$photo_name')";

    if (mysqli_query($conn, $sql)) {
        $message = "Event added successfully!";
    } else {
        $message = "Error: " . mysqli_error($conn);
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Add Event - TicketHub</title>
<style>
body { font-family: Arial; background: #111; color: #fff; }
.container { max-width: 700px; margin: 50px auto; background: rgba(17,17,17,0.85); padding: 30px; border-radius: 12px; }
input, textarea, select { width: 100%; padding: 10px; margin: 10px 0; border-radius: 8px; border: none; }
input[type="file"] { padding: 5px; }
.btn { padding: 12px 25px; background: linear-gradient(45deg,#8B5CF6,#EC4899); color: #fff; border: none; border-radius: 25px; cursor: pointer; }
.btn:hover { transform: scale(1.05); }
.message { margin: 15px 0; color: #8B5CF6; }
</style>
</head>
<body>
<div class="container">
    <h2>Add New Event</h2>
    <?php if(isset($message)) echo "<div class='message'>$message</div>"; ?>
    <form method="POST" enctype="multipart/form-data">
        <input type="text" name="title" placeholder="Event Title" required>
        <textarea name="description" placeholder="Event Description" rows="4"></textarea>
        <input type="text" name="category" placeholder="Category" required>
        <input type="text" name="location" placeholder="Location" required>
        <input type="datetime-local" name="event_date" required>
        <input type="number" step="0.01" name="ticket_price" placeholder="Ticket Price" required>
        <input type="number" name="total_tickets" placeholder="Total Tickets" required>
        <input type="file" name="photo" accept="image/*">
        <button type="submit" name="submit" class="btn">Add Event</button>
    </form>
</div>
</body>
</html>