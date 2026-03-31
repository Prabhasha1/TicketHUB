<?php
include __DIR__ . "/config/database.php"; // Correct path to your database config

$name = $_POST['name'];
$email = $_POST['email'];
$password = password_hash($_POST['password'], PASSWORD_DEFAULT);

$sql = "INSERT INTO users (name,email,password)
        VALUES ('$name','$email','$password')";

mysqli_query($conn,$sql);

header("Location: login.php");
?>