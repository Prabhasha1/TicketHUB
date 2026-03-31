<?php
session_start();

// Check if user is logged in
$loggedIn = isset($_SESSION['user_id']);
$userName = $loggedIn ? $_SESSION['user_name'] : '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>TicketHub - Login</title>
<style>
body {
    margin: 0;
    font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
    background: radial-gradient(circle at top, #111 0%, #0B0B0B 60%);
    color: #fff;
    overflow-x: hidden;
}

/* NAVBAR */
header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 20px 40px;
    background: rgba(17,17,17,0.8);
    backdrop-filter: blur(10px);
    position: sticky;
    top: 0;
    z-index: 100;
}

.logo {
    font-size: 26px;
    font-weight: bold;
    color: #8B5CF6;
    text-shadow: 0 0 10px #8B5CF6;
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

/* HERO / LOGIN FORM */
.login-container {
    max-width: 400px;
    margin: 100px auto;
    background: rgba(255,255,255,0.05);
    padding: 40px;
    border-radius: 16px;
    backdrop-filter: blur(10px);
    box-shadow: 0 0 25px #8B5CF6;
    text-align: center;
    animation: fadeIn 1s ease-in-out;
}

.login-container h2 {
    color: #8B5CF6;
    margin-bottom: 25px;
}

.login-container .form-group {
    margin-bottom: 20px;
    text-align: left;
}

.login-container label {
    display: block;
    margin-bottom: 5px;
    color: #ccc;
}

.login-container input {
    width: 100%;
    padding: 12px;
    border: none;
    border-radius: 8px;
    outline: none;
    background: rgba(255,255,255,0.1);
    color: #fff;
    font-size: 14px;
}

.login-container input::placeholder {
    color: #aaa;
}

.login-container .btn {
    width: 100%;
    padding: 14px;
    font-size: 16px;
    border-radius: 30px;
    border: none;
    cursor: pointer;
    color: #fff;
    background: linear-gradient(45deg, #8B5CF6, #EC4899);
    box-shadow: 0 0 15px #8B5CF6;
    transition: 0.4s;
}

.login-container .btn:hover {
    transform: scale(1.05);
    box-shadow: 0 0 25px #EC4899;
}

/* FOOTER */
.footer {
    text-align: center;
    padding: 20px;
    background: #111;
    margin-top: 40px;
    color: #777;
}

/* ANIMATIONS */
@keyframes fadeIn {
    from {opacity: 0; transform: translateY(30px);} 
    to {opacity: 1; transform: translateY(0);} 
}
</style>
</head>
<body>

<div class="glow"></div>
<div class="glow glow2"></div>

<header>
    <div class="logo">TicketHub</div>
    <nav>
        <a href="index.php">Home</a>
        <a href="events.php">Events</a>
        <a href="tickets.php">My Tickets</a>

        <?php if($loggedIn): ?>
            <span style="margin-left:15px;">Welcome <?php echo $userName; ?></span>
            <a href="logout.php">Logout</a>
        <?php else: ?>
            <a href="login.php">Login</a>
            <a href="register.php">Register</a>
        <?php endif; ?>
    </nav>
</header>

<div class="login-container">
    <h2>Login to TicketHub</h2>
    <form method="POST" action="login_process.php">
        <div class="form-group">
            <label>Email</label>
            <input type="email" name="email" placeholder="Enter your email" required>
        </div>
        <div class="form-group">
            <label>Password</label>
            <input type="password" name="password" placeholder="Enter your password" required>
        </div>
        <button type="submit" class="btn">Login</button>
    </form>
</div>

<div class="footer">
    © 2026 TicketHub. All rights reserved.
</div>

</body>
</html>