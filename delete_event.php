<?php
session_start();
require __DIR__ . "/config/database.php";

if (!isset($_SESSION['user_role']) || !in_array($_SESSION['user_role'], ['admin', 'super_admin'], true)) {
    header("Location: login.php");
    exit;
}

if (!isset($_GET['id']) || !ctype_digit((string)$_GET['id'])) {
    header("Location: manage_events.php?error=" . urlencode("Event ID is invalid or missing."));
    exit;
}

$eventId       = (int)$_GET['id'];
$currentUserId = (int)$_SESSION['user_id'];
$isSuperAdmin  = ($_SESSION['user_role'] === 'super_admin');

// Fetch event to verify existence and check ownership
$stmt = $conn->prepare("SELECT id, title, photo, created_by FROM events WHERE id = ? LIMIT 1");
$stmt->bind_param("i", $eventId);
$stmt->execute();
$event = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$event) {
    header("Location: manage_events.php?error=" . urlencode("Event not found."));
    exit;
}

// Ownership check: regular admins can ONLY delete their own added events
if (!$isSuperAdmin && (int)$event['created_by'] !== $currentUserId) {
    header("Location: manage_events.php?error=" . urlencode("Access denied: You can only delete events that you created."));
    exit;
}

// Delete event photo from disk if present
if (!empty($event['photo'])) {
    $photoPath = __DIR__ . "/eventphotos/" . $event['photo'];
    if (file_exists($photoPath)) {
        @unlink($photoPath);
    }
}

// Delete event record
$delStmt = $conn->prepare("DELETE FROM events WHERE id = ?");
$delStmt->bind_param("i", $eventId);

if ($delStmt->execute()) {
    $delStmt->close();
    header("Location: manage_events.php?msg=" . urlencode("Event '{$event['title']}' deleted successfully."));
    exit;
} else {
    $delStmt->close();
    header("Location: manage_events.php?error=" . urlencode("Failed to delete event."));
    exit;
}