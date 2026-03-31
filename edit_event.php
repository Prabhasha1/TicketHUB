<?php
session_start();
include "config/database.php";

// Admin check
if (!isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'admin') {
    header("Location: login.php");
    exit;
}

if (!isset($_GET['id'])) die("Event ID missing");
$eventId = intval($_GET['id']);

// Fetch event
$sql = "SELECT * FROM events WHERE id=$eventId";
$result = mysqli_query($conn, $sql);
$event = mysqli_fetch_assoc($result);

$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $title = mysqli_real_escape_string($conn, $_POST['title']);
    $location = mysqli_real_escape_string($conn, $_POST['location']);
    $date = mysqli_real_escape_string($conn, $_POST['event_date']);
    $price = floatval($_POST['ticket_price']);

    // Handle photo upload
    if (isset($_FILES['photo']) && $_FILES['photo']['error'] === UPLOAD_ERR_OK) {
        $ext = pathinfo($_FILES['photo']['name'], PATHINFO_EXTENSION);
        $newPhoto = 'event_' . time() . '.' . $ext;
        $uploadDir = 'eventphotos/';
        if (!is_dir($uploadDir)) mkdir($uploadDir, 0777, true);
        move_uploaded_file($_FILES['photo']['tmp_name'], $uploadDir . $newPhoto);

        // Delete old photo if exists
        if (!empty($event['photo']) && file_exists($uploadDir . $event['photo'])) {
            unlink($uploadDir . $event['photo']);
        }

        $photoSQL = ", photo='$newPhoto'";
    } else {
        $photoSQL = '';
    }

    $update = "UPDATE events SET title='$title', location='$location', event_date='$date', ticket_price=$price $photoSQL WHERE id=$eventId";
    if (mysqli_query($conn, $update)) {
        header("Location: manage_events.php");
        exit;
    } else {
        $message = "Update failed: " . mysqli_error($conn);
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Edit Event - TicketHub</title>
<style>
body { background: radial-gradient(circle at top, #111 0%, #0B0B0B 60%); color:#fff; font-family:'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; }
.container { max-width:600px; margin:50px auto; padding:30px; background: rgba(255,255,255,0.05); border-radius:16px; backdrop-filter: blur(10px);}
h2 { color:#8B5CF6; text-align:center; }
label { display:block; margin-top:15px; color:#ccc; }
input, input[type="file"] { width:100%; padding:10px; margin-top:5px; border-radius:8px; border:none; background:rgba(255,255,255,0.1); color:#fff; }
button { margin-top:20px; padding:12px 25px; border:none; border-radius:30px; font-size:16px; font-weight:bold; cursor:pointer; background:linear-gradient(45deg,#8B5CF6,#EC4899); box-shadow:0 0 15px #8B5CF6; color:#fff; transition:0.3s;}
button:hover { transform: scale(1.05); box-shadow:0 0 25px #EC4899; }

a.back { color:#8B5CF6; text-decoration:none; display:inline-block; margin-top:10px;}
img.current-photo { width:100%; max-height:250px; object-fit:cover; border-radius:12px; margin-top:10px; box-shadow: 0 0 15px #8B5CF6; }
.message { color:#EC4899; margin-top:10px; text-align:center; font-weight:bold; }

</style>
</head>
<body>
<div class="container">
<h2>Edit Event</h2>

<?php if($message) echo "<div class='message'>$message</div>"; ?>

<form method="post" enctype="multipart/form-data">
<label>Title</label>
<input type="text" name="title" value="<?php echo htmlspecialchars($event['title']); ?>" required>

<label>Location</label>
<input type="text" name="location" value="<?php echo htmlspecialchars($event['location']); ?>" required>

<label>Date & Time</label>
<input type="datetime-local" name="event_date" value="<?php echo date('Y-m-d\TH:i', strtotime($event['event_date'])); ?>" required>

<label>Price</label>
<input type="number" step="0.01" name="ticket_price" value="<?php echo $event['ticket_price']; ?>" required>

<label>Current Photo</label>
<?php if(!empty($event['photo']) && file_exists('eventphotos/'.$event['photo'])): ?>
    <img src="eventphotos/<?php echo $event['photo']; ?>" alt="Event Photo" class="current-photo">
<?php else: ?>
    <img src="https://via.placeholder.com/600x250?text=No+Photo" alt="No Photo" class="current-photo">
<?php endif; ?>

<label>Change Photo</label>
<input type="file" name="photo" accept="image/*">

<button type="submit">Update Event</button>
</form>

<a class="back" href="manage_events.php">← Back to Manage Events</a>
</div>
</body>
</html>