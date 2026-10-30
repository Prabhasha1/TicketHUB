<?php
session_start();
require __DIR__ . "/config/database.php";

// Admin check
if (!isset($_SESSION['user_role']) || !in_array($_SESSION['user_role'], ['admin', 'super_admin'], true)) {
    header("Location: login.php");
    exit;
}

if (!isset($_GET['id'])) {
    die("Event ID missing");
}
$eventId = (int)$_GET['id'];

// Fetch event
$stmt = $conn->prepare("SELECT * FROM events WHERE id = ? LIMIT 1");
$stmt->bind_param("i", $eventId);
$stmt->execute();
$event = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$event) {
    header("Location: manage_events.php?error=" . urlencode("Event not found."));
    exit;
}

$currentUserId = (int)$_SESSION['user_id'];
$isSuperAdmin  = ($_SESSION['user_role'] === 'super_admin');

// Regular admins can only manage their own added events
if (!$isSuperAdmin && (int)$event['created_by'] !== $currentUserId) {
    header("Location: manage_events.php?error=" . urlencode("Access denied: You can only edit events that you created."));
    exit;
}

$message = '';
$messageType = 'error';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $title    = trim($_POST['title'] ?? '');
    $location = trim($_POST['location'] ?? '');
    $date     = trim($_POST['event_date'] ?? '');
    $price    = (float)($_POST['ticket_price'] ?? 0);

    $newPhoto = null;

    // Handle photo upload
    if (isset($_FILES['photo']) && $_FILES['photo']['error'] === UPLOAD_ERR_OK) {
        $allowedExt = ['jpg', 'jpeg', 'png', 'webp'];
        $ext = strtolower(pathinfo($_FILES['photo']['name'], PATHINFO_EXTENSION));

        if (in_array($ext, $allowedExt, true)) {
            $newPhotoName = 'event_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
            $uploadDir = __DIR__ . '/eventphotos/';
            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0777, true);
            }
            if (move_uploaded_file($_FILES['photo']['tmp_name'], $uploadDir . $newPhotoName)) {
                // Delete old photo
                if (!empty($event['photo']) && file_exists($uploadDir . $event['photo'])) {
                    unlink($uploadDir . $event['photo']);
                }
                $newPhoto = $newPhotoName;
            }
        }
    }

    if ($title === '' || $location === '' || $date === '') {
        $message = "Please fill in all required fields.";
    } else {
        if ($newPhoto !== null) {
            $upd = $conn->prepare("UPDATE events SET title = ?, location = ?, event_date = ?, ticket_price = ?, photo = ? WHERE id = ?");
            $upd->bind_param("sssdsi", $title, $location, $date, $price, $newPhoto, $eventId);
        } else {
            $upd = $conn->prepare("UPDATE events SET title = ?, location = ?, event_date = ?, ticket_price = ? WHERE id = ?");
            $upd->bind_param("sssdi", $title, $location, $date, $price, $eventId);
        }

        if ($upd->execute()) {
            $upd->close();
            header("Location: manage_events.php?msg=" . urlencode("Event updated successfully."));
            exit;
        } else {
            $message = "Update failed: " . $conn->error;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<?php include __DIR__ . "/pwa_head.php"; ?>
<title>Edit Event - TicketHub</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Sora:wght@600;700&family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
<style>
:root {
    --bg: #14121A;
    --surface: rgba(255,255,255,0.04);
    --field: rgba(255,255,255,0.06);
    --border: rgba(255,255,255,0.08);
    --text: #F1EEF7;
    --text-dim: #A9A3B8;
    --violet: #9D7CF9;
    --violet-soft: rgba(157,124,249,0.18);
    --rose: #F0729C;
    --green: #6FCF97;
    --font-display: 'Sora', 'Segoe UI', sans-serif;
    --font-body: 'Inter', 'Segoe UI', sans-serif;
}

* { box-sizing: border-box; }

body {
    margin: 0;
    font-family: var(--font-body);
    background: var(--bg);
    color: var(--text);
    min-height: 100vh;
}

header {
    display: flex; justify-content: space-between; align-items: center;
    padding: 22px 48px; background: rgba(20,18,26,0.75);
    backdrop-filter: blur(14px); border-bottom: 1px solid var(--border);
    position: sticky; top: 0; z-index: 100;
}
.logo { font-family: var(--font-display); font-size: 22px; font-weight: 700; letter-spacing: -0.01em; color: var(--text); }
.logo span { color: var(--violet); }

nav { display: flex; align-items: center; gap: 4px; flex-wrap: wrap; }
nav a, nav span {
    padding: 8px 14px; border-radius: 8px; text-decoration: none;
    color: var(--text-dim); font-size: 14px; font-weight: 500;
    transition: color 0.25s ease, background 0.25s ease;
    white-space: nowrap;
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

nav span.welcome { color: var(--violet); font-weight: 600; }

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

@media (max-width: 640px) {
    header { padding: 18px 20px; }
}

.page-wrap {
    max-width: 640px;
    margin: 0 auto;
    padding: 40px 24px 100px;
}

.back-link {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    color: var(--text-dim);
    text-decoration: none;
    font-size: 14px;
    margin-bottom: 24px;
    transition: color 0.2s ease;
}

.back-link:hover { color: var(--violet); }

.container {
    background: var(--surface);
    border: 1px solid var(--border);
    padding: 36px;
    border-radius: 20px;
    backdrop-filter: blur(14px);
}

h2 {
    font-family: var(--font-display);
    font-size: 24px;
    font-weight: 700;
    margin: 0 0 6px;
}

.subtitle { color: var(--text-dim); font-size: 14px; margin: 0 0 28px; }

.message {
    padding: 12px 16px;
    border-radius: 10px;
    font-size: 14px;
    margin-bottom: 20px;
    background: rgba(240,114,156,0.12);
    border: 1px solid rgba(240,114,156,0.35);
    color: #F4A4BE;
}

.form-group { margin-bottom: 18px; text-align: left; }

label {
    display: block;
    margin-bottom: 6px;
    color: var(--text-dim);
    font-size: 13px;
    font-weight: 500;
}

input {
    width: 100%;
    padding: 12px 14px;
    border: 1px solid var(--border);
    border-radius: 10px;
    outline: none;
    background: var(--field);
    color: var(--text);
    font-family: var(--font-body);
    font-size: 14px;
    transition: border-color 0.25s ease, box-shadow 0.25s ease;
}

input:focus {
    border-color: var(--violet);
    box-shadow: 0 0 0 3px var(--violet-soft);
}

input[type="file"] { padding: 9px 14px; }

.current-photo {
    width: 100%;
    max-height: 220px;
    object-fit: cover;
    border-radius: 12px;
    margin-top: 6px;
    border: 1px solid var(--border);
}

.btn {
    width: 100%;
    margin-top: 12px;
    padding: 14px;
    font-family: var(--font-body);
    font-size: 15px;
    font-weight: 600;
    border-radius: 100px;
    border: none;
    cursor: pointer;
    color: var(--bg);
    background: var(--text);
    transition: transform 0.25s ease, background 0.25s ease, color 0.25s ease, box-shadow 0.25s ease;
}

.btn:hover {
    background: var(--violet);
    color: #fff;
    transform: translateY(-2px);
    box-shadow: 0 12px 30px -8px rgba(157,124,249,0.55);
}
</style>
</head>
<body>

<?php require __DIR__ . "/nav.php"; ?>

<div class="page-wrap">
    <a class="back-link" href="manage_events.php">← Back to manage events</a>

    <div class="container">
        <h2>Edit event</h2>
        <p class="subtitle">Modify event details below.</p>

        <?php if ($message !== ''): ?>
            <div class="message"><?php echo htmlspecialchars($message); ?></div>
        <?php endif; ?>

        <form method="POST" enctype="multipart/form-data">
            <div class="form-group">
                <label>Event Title</label>
                <input type="text" name="title" value="<?php echo htmlspecialchars($event['title']); ?>" required>
            </div>

            <div class="form-group">
                <label>Location</label>
                <input type="text" name="location" value="<?php echo htmlspecialchars($event['location']); ?>" required>
            </div>

            <div class="form-group">
                <label>Date & Time</label>
                <input type="datetime-local" name="event_date" value="<?php echo date('Y-m-d\TH:i', strtotime($event['event_date'])); ?>" required>
            </div>

            <div class="form-group">
                <label>Price (LKR)</label>
                <input type="number" step="0.01" name="ticket_price" value="<?php echo htmlspecialchars($event['ticket_price']); ?>" required>
            </div>

            <div class="form-group">
                <label>Current Photo</label>
                <?php if (!empty($event['photo']) && file_exists(__DIR__ . '/eventphotos/' . $event['photo'])): ?>
                    <img src="eventphotos/<?php echo htmlspecialchars($event['photo']); ?>" alt="Event Photo" class="current-photo">
                <?php else: ?>
                    <p style="color:var(--text-dim); font-size:13px; margin:4px 0 0;">No photo uploaded yet.</p>
                <?php endif; ?>
            </div>

            <div class="form-group">
                <label>Change Photo</label>
                <input type="file" name="photo" accept="image/*">
            </div>

            <button type="submit" class="btn">Update Event</button>
        </form>
    </div>
</div>

<?php require __DIR__ . "/footer.php"; ?>
</body>
</html>