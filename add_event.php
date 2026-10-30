<?php
session_start();
require __DIR__ . "/config/database.php";
require __DIR__ . "/config/commission.php";

// Only admin can access
if (!isset($_SESSION['user_id']) || !isset($_SESSION['user_role']) || !in_array($_SESSION['user_role'], ['admin', 'super_admin'], true)) {
    header("Location: login.php");
    exit();
}

$message = '';
$messageType = 'success';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit'])) {

    $title         = trim($_POST['title'] ?? '');
    $description   = trim($_POST['description'] ?? '');
    $category      = trim($_POST['category'] ?? '');
    $location      = trim($_POST['location'] ?? '');
    $event_date    = trim($_POST['event_date'] ?? '');
    $ticket_price  = $_POST['ticket_price'] ?? '';
    $total_tickets = $_POST['total_tickets'] ?? '';
    $created_by    = (int)$_SESSION['user_id'];

    $errors = [];

    if ($title === '' || $location === '' || $category === '') {
        $errors[] = "Title, category, and location are required.";
    }
    if ($event_date === '' || !strtotime($event_date)) {
        $errors[] = "Please provide a valid event date and time.";
    }
    if (!is_numeric($ticket_price) || (float)$ticket_price < 0) {
        $errors[] = "Ticket price must be a positive number.";
    }
    if (!ctype_digit((string)$total_tickets) || (int)$total_tickets < 1) {
        $errors[] = "Total tickets must be a whole number of at least 1.";
    }

    // ---- Photo upload (validated properly) ----
    $photo_name = '';
    $allowedExt = ['jpg', 'jpeg', 'png', 'webp'];
    $allowedMime = ['image/jpeg', 'image/png', 'image/webp'];
    $maxBytes = 5 * 1024 * 1024;

    if (isset($_FILES['photo']) && $_FILES['photo']['error'] !== UPLOAD_ERR_NO_FILE) {
        $file = $_FILES['photo'];

        if ($file['error'] !== UPLOAD_ERR_OK) {
            $errors[] = "Photo upload failed. Please try again.";
        } elseif ($file['size'] > $maxBytes) {
            $errors[] = "Photo must be smaller than 5MB.";
        } else {
            $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            $mime = mime_content_type($file['tmp_name']);

            if (!in_array($ext, $allowedExt, true) || !in_array($mime, $allowedMime, true)) {
                $errors[] = "Photo must be a JPG, PNG, or WEBP image.";
            } else {
                $upload_dir = __DIR__ . "/eventphotos/";
                if (!is_dir($upload_dir)) {
                    mkdir($upload_dir, 0755, true);
                }
                $photo_name = bin2hex(random_bytes(8)) . '.' . $ext;
                if (!move_uploaded_file($file['tmp_name'], $upload_dir . $photo_name)) {
                    $errors[] = "Could not save the uploaded photo.";
                    $photo_name = '';
                }
            }
        }
    }

    if (empty($errors)) {
        // New events start on the platform default commission; Super Admins adjust it per event in Manage Events
        $defaultCommission = commission_get_default($conn);

        $stmt = $conn->prepare(
            "INSERT INTO events (title, description, category, location, event_date, ticket_price, total_tickets, created_by, photo, commission_type, commission_value)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
        );
        $stmt->bind_param(
            "ssssssiissd",
            $title, $description, $category, $location, $event_date,
            $ticket_price, $total_tickets, $created_by, $photo_name,
            $defaultCommission['type'], $defaultCommission['value']
        );

        if ($stmt->execute()) {
            $message = "Event added successfully.";
            $messageType = 'success';
        } else {
            $message = "Could not add the event. Please try again.";
            $messageType = 'error';
            error_log("add_event insert failed: " . $stmt->error);
        }
        $stmt->close();
    } else {
        $message = implode(' ', $errors);
        $messageType = 'error';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<?php include __DIR__ . "/pwa_head.php"; ?>
<title>Add Event - TicketHub</title>
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
    opacity: 0;
    transform: translateY(16px);
    animation: rise 0.6s ease-out forwards;
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
}

.message.success { background: rgba(111,207,151,0.12); border: 1px solid rgba(111,207,151,0.35); color: var(--green); }
.message.error { background: rgba(240,114,156,0.12); border: 1px solid rgba(240,114,156,0.35); color: #F4A4BE; }

.form-group { margin-bottom: 18px; text-align: left; }
.form-row { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; }

label {
    display: block;
    margin-bottom: 6px;
    color: var(--text-dim);
    font-size: 13px;
    font-weight: 500;
}

input, textarea {
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

textarea { resize: vertical; min-height: 90px; }

input::placeholder, textarea::placeholder { color: #6e6a7d; }

input:focus, textarea:focus {
    border-color: var(--violet);
    box-shadow: 0 0 0 3px var(--violet-soft);
}

input[type="file"] { padding: 9px 14px; }

.btn {
    width: 100%;
    margin-top: 8px;
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

@keyframes rise { from { opacity: 0; transform: translateY(16px); } to { opacity: 1; transform: translateY(0); } }

@media (prefers-reduced-motion: reduce) {
    *, .container { animation: none !important; opacity: 1 !important; transform: none !important; }
}

@media (max-width: 540px) {
    .form-row { grid-template-columns: 1fr; }
    .container { padding: 28px 22px; }
}
</style>
</head>
<body>

<?php require __DIR__ . "/nav.php"; ?>

<div class="page-wrap">
    <a class="back-link" href="manage_events.php">← Back to manage events</a>

    <div class="container">
        <h2>Add new event</h2>
        <p class="subtitle">Fill in the details below to publish a new event.</p>

        <?php if ($message): ?>
            <div class="message <?php echo $messageType; ?>"><?php echo htmlspecialchars($message); ?></div>
        <?php endif; ?>

        <form method="POST" enctype="multipart/form-data">
            <div class="form-group">
                <label for="title">Event title</label>
                <input type="text" id="title" name="title" placeholder="Colombo Night Music Festival" required value="<?php echo isset($_POST['title']) ? htmlspecialchars($_POST['title']) : ''; ?>">
            </div>

            <div class="form-group">
                <label for="description">Description</label>
                <textarea id="description" name="description" placeholder="What's this event about?"><?php echo isset($_POST['description']) ? htmlspecialchars($_POST['description']) : ''; ?></textarea>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label for="category">Category</label>
                    <input type="text" id="category" name="category" placeholder="Music, Sports, Technology…" required value="<?php echo isset($_POST['category']) ? htmlspecialchars($_POST['category']) : ''; ?>">
                </div>
                <div class="form-group">
                    <label for="location">Location</label>
                    <input type="text" id="location" name="location" placeholder="Viharamahadevi Park, Colombo" required value="<?php echo isset($_POST['location']) ? htmlspecialchars($_POST['location']) : ''; ?>">
                </div>
            </div>

            <div class="form-group">
                <label for="event_date">Date and time</label>
                <input type="datetime-local" id="event_date" name="event_date" required value="<?php echo isset($_POST['event_date']) ? htmlspecialchars($_POST['event_date']) : ''; ?>">
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label for="ticket_price">Ticket price (LKR)</label>
                    <input type="number" id="ticket_price" name="ticket_price" step="0.01" min="0" placeholder="2500.00" required value="<?php echo isset($_POST['ticket_price']) ? htmlspecialchars($_POST['ticket_price']) : ''; ?>">
                </div>
                <div class="form-group">
                    <label for="total_tickets">Total tickets</label>
                    <input type="number" id="total_tickets" name="total_tickets" min="1" step="1" placeholder="200" required value="<?php echo isset($_POST['total_tickets']) ? htmlspecialchars($_POST['total_tickets']) : ''; ?>">
                </div>
            </div>

            <div class="form-group">
                <label for="photo">Event photo (JPG, PNG, or WEBP — max 5MB)</label>
                <input type="file" id="photo" name="photo" accept="image/jpeg,image/png,image/webp">
            </div>

            <button type="submit" name="submit" class="btn">Add event</button>
        </form>
    </div>
</div>

<?php require __DIR__ . "/footer.php"; ?>
</body>
</html>