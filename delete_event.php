<?php
session_start();
include "config/database.php";

if (!isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'admin') {
    header("Location: login.php");
    exit;
}

if (!isset($_GET['id'])) die("Event ID missing");
$eventId = intval($_GET['id']);

mysqli_query($conn, "DELETE FROM events WHERE id=$eventId");

header("Location: manage_events.php");
exit;