<?php
session_start();
include "config/database.php";

$email = $_POST['email'];
$password = $_POST['password'];

// Fetch user from database
$sql = "SELECT * FROM users WHERE email = '$email' LIMIT 1";
$result = mysqli_query($conn, $sql);
$user = mysqli_fetch_assoc($result);

if ($user) {
    if (password_verify($password, $user['password'])) {
        // Set session variables
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['user_name'] = $user['name'];
        $_SESSION['user_role'] = $user['role'];

        // Redirect based on role
        if ($user['role'] == 'admin') {
            header("Location: admin_dashboard.php");
            exit();
        } else {
            header("Location: index.php");
            exit();
        }
    } else {
        echo "Invalid password!";
    }
} else {
    echo "User not found!";
}
?>