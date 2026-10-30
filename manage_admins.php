<?php
session_start();
require __DIR__ . "/config/database.php";

// Strict Access Control: Only Super Admin can access this page
if (!isset($_SESSION['user_id']) || !isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'super_admin') {
    if (isset($_SESSION['user_role']) && $_SESSION['user_role'] === 'admin') {
        header("Location: admin_dashboard.php?error=" . urlencode("Access Denied: Only Super Admins can manage administrators."));
    } else {
        header("Location: login.php?error=" . urlencode("Please sign in as a Super Admin."));
    }
    exit();
}

$currentUserId = (int)$_SESSION['user_id'];

// CSRF Token setup
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['csrf_token'];

$successMsg = trim($_GET['msg'] ?? '');
$errorMsg   = trim($_GET['error'] ?? '');

// Handle Form Actions (POST)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $submittedToken = $_POST['csrf_token'] ?? '';
    if (!hash_equals($csrfToken, $submittedToken)) {
        header("Location: manage_admins.php?error=" . urlencode("Security validation failed (invalid CSRF token). Please try again."));
        exit();
    }

    $action = trim($_POST['action'] ?? '');

    // ---------------------------------------------------------
    // 1. CREATE ADMIN
    // ---------------------------------------------------------
    if ($action === 'create_admin') {
        $name            = trim($_POST['name'] ?? '');
        $email           = trim($_POST['email'] ?? '');
        $password        = $_POST['password'] ?? '';
        $confirmPassword = $_POST['confirm_password'] ?? '';
        $role            = trim($_POST['role'] ?? 'admin');

        // Allow creating either admin or super_admin
        if (!in_array($role, ['admin', 'super_admin'], true)) {
            $role = 'admin';
        }

        if ($name === '' || $email === '' || $password === '') {
            header("Location: manage_admins.php?error=" . urlencode("Please fill in all required fields."));
            exit();
        }

        if (mb_strlen($name) > 100) {
            header("Location: manage_admins.php?error=" . urlencode("Name cannot exceed 100 characters."));
            exit();
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 150) {
            header("Location: manage_admins.php?error=" . urlencode("Please provide a valid email address."));
            exit();
        }

        if (strlen($password) < 8) {
            header("Location: manage_admins.php?error=" . urlencode("Password must be at least 8 characters long."));
            exit();
        }

        if ($password !== $confirmPassword) {
            header("Location: manage_admins.php?error=" . urlencode("Passwords do not match."));
            exit();
        }

        // Check if email is already taken
        $checkStmt = $conn->prepare("SELECT id FROM users WHERE email = ? LIMIT 1");
        $checkStmt->bind_param("s", $email);
        $checkStmt->execute();
        $checkStmt->store_result();
        if ($checkStmt->num_rows > 0) {
            $checkStmt->close();
            header("Location: manage_admins.php?error=" . urlencode("An account with that email already exists."));
            exit();
        }
        $checkStmt->close();

        // Hash and insert
        $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
        $insertStmt = $conn->prepare("INSERT INTO users (name, email, password, role) VALUES (?, ?, ?, ?)");
        $insertStmt->bind_param("ssss", $name, $email, $hashedPassword, $role);

        if ($insertStmt->execute()) {
            $roleLabel = ($role === 'super_admin') ? 'Super Admin' : 'Admin';
            $insertStmt->close();
            header("Location: manage_admins.php?msg=" . urlencode("New $roleLabel '{$name}' created and saved successfully."));
            exit();
        } else {
            $insertStmt->close();
            header("Location: manage_admins.php?error=" . urlencode("Failed to create administrator. Please try again."));
            exit();
        }
    }

    // ---------------------------------------------------------
    // 2. MARK AS SUPER ADMIN (Promote Admin -> Super Admin)
    // ---------------------------------------------------------
    elseif ($action === 'mark_super_admin') {
        $targetId = (int)($_POST['target_user_id'] ?? 0);

        if ($targetId <= 0) {
            header("Location: manage_admins.php?error=" . urlencode("Invalid administrator ID."));
            exit();
        }

        // Verify target is currently an admin
        $chk = $conn->prepare("SELECT id, name, role FROM users WHERE id = ? LIMIT 1");
        $chk->bind_param("i", $targetId);
        $chk->execute();
        $targetUser = $chk->get_result()->fetch_assoc();
        $chk->close();

        if (!$targetUser) {
            header("Location: manage_admins.php?error=" . urlencode("Administrator not found."));
            exit();
        }

        if ($targetUser['role'] === 'super_admin') {
            header("Location: manage_admins.php?msg=" . urlencode("{$targetUser['name']} is already a Super Admin."));
            exit();
        }

        $upd = $conn->prepare("UPDATE users SET role = 'super_admin' WHERE id = ?");
        $upd->bind_param("i", $targetId);
        if ($upd->execute()) {
            $upd->close();
            header("Location: manage_admins.php?msg=" . urlencode("👑 Successfully marked {$targetUser['name']} as a Super Admin!"));
            exit();
        } else {
            $upd->close();
            header("Location: manage_admins.php?error=" . urlencode("Failed to promote user to Super Admin."));
            exit();
        }
    }

    // ---------------------------------------------------------
    // 3. DEMOTE TO REGULAR ADMIN
    // ---------------------------------------------------------
    elseif ($action === 'demote_to_admin') {
        $targetId = (int)($_POST['target_user_id'] ?? 0);

        if ($targetId <= 0) {
            header("Location: manage_admins.php?error=" . urlencode("Invalid administrator ID."));
            exit();
        }

        // Prevent self-demotion to avoid accidental lock-out
        if ($targetId === $currentUserId) {
            header("Location: manage_admins.php?error=" . urlencode("You cannot demote your own Super Admin account."));
            exit();
        }

        // Ensure there is at least one other super admin remaining
        $superCount = (int)$conn->query("SELECT COUNT(*) AS c FROM users WHERE role = 'super_admin'")->fetch_assoc()['c'];
        if ($superCount <= 1) {
            header("Location: manage_admins.php?error=" . urlencode("Cannot demote this user. The system must have at least one active Super Admin."));
            exit();
        }

        $chk = $conn->prepare("SELECT name FROM users WHERE id = ? AND role = 'super_admin' LIMIT 1");
        $chk->bind_param("i", $targetId);
        $chk->execute();
        $targetUser = $chk->get_result()->fetch_assoc();
        $chk->close();

        if (!$targetUser) {
            header("Location: manage_admins.php?error=" . urlencode("Super Admin not found or already demoted."));
            exit();
        }

        $upd = $conn->prepare("UPDATE users SET role = 'admin' WHERE id = ?");
        $upd->bind_param("i", $targetId);
        if ($upd->execute()) {
            $upd->close();
            header("Location: manage_admins.php?msg=" . urlencode("Changed {$targetUser['name']} from Super Admin to regular Admin."));
            exit();
        } else {
            $upd->close();
            header("Location: manage_admins.php?error=" . urlencode("Failed to demote user."));
            exit();
        }
    }

    // ---------------------------------------------------------
    // 4. UPDATE / SAVE ADMIN DATA
    // ---------------------------------------------------------
    elseif ($action === 'save_admin_data') {
        $targetId    = (int)($_POST['target_user_id'] ?? 0);
        $name        = trim($_POST['name'] ?? '');
        $email       = trim($_POST['email'] ?? '');
        $role        = trim($_POST['role'] ?? 'admin');
        $newPassword = $_POST['new_password'] ?? '';

        if ($targetId <= 0 || $name === '' || $email === '') {
            header("Location: manage_admins.php?error=" . urlencode("Name and email are required to save admin data."));
            exit();
        }

        if (!in_array($role, ['admin', 'super_admin'], true)) {
            $role = 'admin';
        }

        // Do not allow current user to remove their own super_admin status here
        if ($targetId === $currentUserId && $role !== 'super_admin') {
            $role = 'super_admin';
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            header("Location: manage_admins.php?error=" . urlencode("Please enter a valid email address."));
            exit();
        }

        // Check if email belongs to another user
        $emailCheck = $conn->prepare("SELECT id FROM users WHERE email = ? AND id != ? LIMIT 1");
        $emailCheck->bind_param("si", $email, $targetId);
        $emailCheck->execute();
        $emailCheck->store_result();
        if ($emailCheck->num_rows > 0) {
            $emailCheck->close();
            header("Location: manage_admins.php?error=" . urlencode("That email address is already in use by another account."));
            exit();
        }
        $emailCheck->close();

        // Check if password should be updated
        if (!empty($newPassword)) {
            if (strlen($newPassword) < 8) {
                header("Location: manage_admins.php?error=" . urlencode("New password must be at least 8 characters long."));
                exit();
            }
            $newHash = password_hash($newPassword, PASSWORD_DEFAULT);
            $updStmt = $conn->prepare("UPDATE users SET name = ?, email = ?, role = ?, password = ? WHERE id = ?");
            $updStmt->bind_param("ssssi", $name, $email, $role, $newHash, $targetId);
        } else {
            $updStmt = $conn->prepare("UPDATE users SET name = ?, email = ?, role = ? WHERE id = ?");
            $updStmt->bind_param("sssi", $name, $email, $role, $targetId);
        }

        if ($updStmt->execute()) {
            $updStmt->close();
            if ($targetId === $currentUserId) {
                $_SESSION['user_name'] = $name;
                $_SESSION['user_role'] = $role;
            }
            header("Location: manage_admins.php?msg=" . urlencode("Admin data for '{$name}' saved successfully."));
            exit();
        } else {
            $updStmt->close();
            header("Location: manage_admins.php?error=" . urlencode("Failed to save admin data."));
            exit();
        }
    }

    // ---------------------------------------------------------
    // 5. DELETE ADMIN
    // ---------------------------------------------------------
    elseif ($action === 'delete_admin') {
        $targetId = (int)($_POST['target_user_id'] ?? 0);

        if ($targetId <= 0) {
            header("Location: manage_admins.php?error=" . urlencode("Invalid administrator ID."));
            exit();
        }

        if ($targetId === $currentUserId) {
            header("Location: manage_admins.php?error=" . urlencode("You cannot delete your own active account."));
            exit();
        }

        // Get admin name
        $nameStmt = $conn->prepare("SELECT name, role FROM users WHERE id = ? LIMIT 1");
        $nameStmt->bind_param("i", $targetId);
        $nameStmt->execute();
        $targetInfo = $nameStmt->get_result()->fetch_assoc();
        $nameStmt->close();

        if (!$targetInfo) {
            header("Location: manage_admins.php?error=" . urlencode("User not found."));
            exit();
        }

        // If deleting a super admin, make sure another one remains
        if ($targetInfo['role'] === 'super_admin') {
            $superCount = (int)$conn->query("SELECT COUNT(*) AS c FROM users WHERE role = 'super_admin'")->fetch_assoc()['c'];
            if ($superCount <= 1) {
                header("Location: manage_admins.php?error=" . urlencode("Cannot delete the only Super Admin in the system."));
                exit();
            }
        }

        $delStmt = $conn->prepare("DELETE FROM users WHERE id = ? AND role IN ('admin', 'super_admin')");
        $delStmt->bind_param("i", $targetId);
        if ($delStmt->execute()) {
            $delStmt->close();
            header("Location: manage_admins.php?msg=" . urlencode("Administrator '{$targetInfo['name']}' has been removed."));
            exit();
        } else {
            $delStmt->close();
            header("Location: manage_admins.php?error=" . urlencode("Failed to delete administrator."));
            exit();
        }
    }
}

// Fetch all administrators (super_admin and admin)
$adminsList = [];
try {
    $res = $conn->query("SELECT id, name, email, role, loyalty_points, created_at FROM users WHERE role IN ('super_admin', 'admin') ORDER BY (role = 'super_admin') DESC, created_at ASC");
    while ($row = $res->fetch_assoc()) {
        $adminsList[] = $row;
    }
} catch (mysqli_sql_exception $e) {
    error_log("Failed to fetch admin list: " . $e->getMessage());
}

// User count statistics
$superAdminCount = 0;
$adminCount      = 0;
$customerCount   = 0;

try {
    $countsRes = $conn->query("SELECT role, COUNT(*) AS count FROM users GROUP BY role");
    while ($cRow = $countsRes->fetch_assoc()) {
        if ($cRow['role'] === 'super_admin') $superAdminCount = (int)$cRow['count'];
        elseif ($cRow['role'] === 'admin')   $adminCount = (int)$cRow['count'];
        elseif ($cRow['role'] === 'customer')$customerCount = (int)$cRow['count'];
    }
} catch (mysqli_sql_exception $e) {
    error_log("Failed to count user roles: " . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<?php include __DIR__ . "/pwa_head.php"; ?>
<title>Admins Management - TicketHub</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Sora:wght@600;700;800&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
:root {
    --bg: #14121A;
    --surface: rgba(255,255,255,0.04);
    --surface-hover: rgba(255,255,255,0.07);
    --surface-active: rgba(255,255,255,0.09);
    --field: rgba(255,255,255,0.05);
    --border: rgba(255,255,255,0.08);
    --border-hover: rgba(255,255,255,0.16);
    --text: #F1EEF7;
    --text-dim: #A9A3B8;
    --violet: #9D7CF9;
    --violet-soft: rgba(157,124,249,0.18);
    --rose: #F0729C;
    --green: #6FCF97;
    --amber: #F2C572;
    --amber-soft: rgba(242,197,114,0.18);
    --blue: #6CA0F2;
    --red: #EF6B6B;
    --font-display: 'Sora', 'Segoe UI', sans-serif;
    --font-body: 'Inter', 'Segoe UI', sans-serif;
}

* { box-sizing: border-box; }

body {
    margin: 0;
    font-family: var(--font-body);
    background: var(--bg);
    color: var(--text);
    overflow-x: hidden;
    min-height: 100vh;
}

.container {
    max-width: 1200px;
    margin: 0 auto;
    padding: 40px 32px 100px;
    position: relative;
}

/* AMBIENT GLOW */
.glow {
    position: absolute;
    width: 440px;
    height: 440px;
    border-radius: 50%;
    filter: blur(120px);
    opacity: 0.12;
    pointer-events: none;
    top: -60px;
    right: 5%;
    background: var(--violet);
    z-index: 0;
}

/* PAGE HEADER */
.page-header {
    position: relative;
    z-index: 1;
    display: flex;
    justify-content: space-between;
    align-items: flex-end;
    flex-wrap: wrap;
    gap: 20px;
    margin-bottom: 36px;
    opacity: 0;
    animation: rise 0.5s ease-out forwards;
}

.header-title h1 {
    font-family: var(--font-display);
    font-size: 32px;
    font-weight: 700;
    margin: 0 0 8px;
    letter-spacing: -0.01em;
    display: flex;
    align-items: center;
    gap: 12px;
}

.header-title h1 span.crown-icon {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 42px;
    height: 42px;
    background: var(--amber-soft);
    border: 1px solid rgba(242,197,114,0.4);
    border-radius: 12px;
    font-size: 22px;
}

.header-title p {
    color: var(--text-dim);
    font-size: 15px;
    margin: 0;
    max-width: 620px;
    line-height: 1.5;
}

/* STATS ROW */
.stats-grid {
    position: relative;
    z-index: 1;
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 18px;
    margin-bottom: 36px;
}

.stat-card {
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: 16px;
    padding: 22px 24px;
    transition: transform 0.25s ease, border-color 0.25s ease;
    opacity: 0;
    animation: rise 0.5s ease-out forwards;
}

.stat-card:nth-child(1) { animation-delay: 0.1s; }
.stat-card:nth-child(2) { animation-delay: 0.18s; }
.stat-card:nth-child(3) { animation-delay: 0.26s; }

.stat-card:hover {
    transform: translateY(-3px);
    border-color: rgba(255,255,255,0.18);
}

.stat-card.gold {
    background: linear-gradient(135deg, rgba(242,197,114,0.12), rgba(20,18,26,0.6));
    border-color: rgba(242,197,114,0.3);
}

.stat-card.violet {
    background: linear-gradient(135deg, rgba(157,124,249,0.12), rgba(20,18,26,0.6));
    border-color: rgba(157,124,249,0.3);
}

.stat-card .stat-label {
    font-size: 13px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    margin-bottom: 10px;
    display: flex;
    align-items: center;
    gap: 8px;
}

.stat-card.gold .stat-label { color: #F2C572; }
.stat-card.violet .stat-label { color: #CBB8FC; }
.stat-card .stat-label { color: var(--text-dim); }

.stat-card .stat-value {
    font-family: var(--font-display);
    font-size: 32px;
    font-weight: 700;
    line-height: 1;
}

.stat-card.gold .stat-value { color: #F8D696; }
.stat-card.violet .stat-value { color: #ffffff; }

/* ALERTS */
.alert {
    padding: 14px 18px;
    border-radius: 12px;
    font-size: 14px;
    margin-bottom: 28px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    animation: rise 0.4s ease-out forwards;
}

.alert.success {
    background: rgba(111,207,151,0.12);
    border: 1px solid rgba(111,207,151,0.35);
    color: #6FCF97;
}

.alert.error {
    background: rgba(239,107,107,0.14);
    border: 1px solid rgba(239,107,107,0.35);
    color: #FFA5A5;
}

.alert .alert-close {
    background: transparent;
    border: none;
    color: inherit;
    font-size: 18px;
    cursor: pointer;
    line-height: 1;
    opacity: 0.7;
    transition: opacity 0.2s;
}

.alert .alert-close:hover { opacity: 1; }

/* SECTION LAYOUT */
.layout-grid {
    display: grid;
    grid-template-columns: 380px 1fr;
    gap: 28px;
    align-items: start;
}

@media (max-width: 960px) {
    .layout-grid {
        grid-template-columns: 1fr;
    }
}

/* CARDS & PANELS */
.panel {
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: 20px;
    padding: 28px;
    position: relative;
    box-shadow: 0 10px 30px -10px rgba(0,0,0,0.4);
}

.panel-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 22px;
    padding-bottom: 14px;
    border-bottom: 1px solid var(--border);
}

.panel-title {
    font-family: var(--font-display);
    font-size: 19px;
    font-weight: 700;
    margin: 0;
    display: flex;
    align-items: center;
    gap: 8px;
}

.panel-subtitle {
    color: var(--text-dim);
    font-size: 13px;
    margin: 4px 0 0;
}

/* FORM STYLES */
.form-group {
    margin-bottom: 16px;
    text-align: left;
}

.form-group label {
    display: block;
    font-size: 13px;
    font-weight: 600;
    color: var(--text-dim);
    margin-bottom: 6px;
}

.form-group input,
.form-group select {
    width: 100%;
    padding: 12px 14px;
    background: var(--field);
    border: 1px solid var(--border);
    border-radius: 10px;
    color: var(--text);
    font-family: var(--font-body);
    font-size: 14px;
    outline: none;
    transition: border-color 0.2s ease, box-shadow 0.2s ease;
}

.form-group input:focus,
.form-group select:focus {
    border-color: var(--violet);
    box-shadow: 0 0 0 3px var(--violet-soft);
}

.form-group select {
    appearance: none;
    background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='16' height='16' fill='%23A9A3B8' viewBox='0 0 16 16'%3E%3Cpath d='M7.247 11.14 2.451 5.658C1.885 5.013 2.345 4 3.204 4h9.592a1 1 0 0 1 .753 1.659l-4.796 5.48a1 1 0 0 1-1.506 0z'/%3E%3C/svg%3E");
    background-repeat: no-repeat;
    background-position: right 14px center;
    background-size: 12px;
    cursor: pointer;
}

.form-group select option {
    background: #1A1722;
    color: #F1EEF7;
}

.field-hint {
    font-size: 11px;
    color: var(--text-dim);
    margin-top: 4px;
}

/* BUTTONS */
.btn-primary {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    width: 100%;
    padding: 12px 20px;
    background: #9D7CF9;
    color: #ffffff;
    border: none;
    border-radius: 10px;
    font-family: var(--font-body);
    font-size: 14px;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.25s ease;
    text-decoration: none;
    box-shadow: 0 4px 14px rgba(157, 124, 249, 0.3);
}

.btn-primary:hover {
    background: #8B65F7;
    transform: translateY(-2px);
    box-shadow: 0 6px 20px rgba(157, 124, 249, 0.45);
}

/* THE MARK AS SUPER ADMIN BUTTON */
.btn-mark-super {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 7px 14px;
    background: rgba(242, 197, 114, 0.15);
    border: 1px solid rgba(242, 197, 114, 0.45);
    color: #F8D696;
    border-radius: 8px;
    font-family: var(--font-body);
    font-size: 12px;
    font-weight: 700;
    cursor: pointer;
    transition: all 0.2s ease;
    white-space: nowrap;
}

.btn-mark-super:hover {
    background: #F2C572;
    color: #1A1305;
    transform: translateY(-1px);
    box-shadow: 0 4px 12px rgba(242, 197, 114, 0.35);
}

.btn-demote {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    padding: 6px 12px;
    background: rgba(255, 255, 255, 0.05);
    border: 1px solid rgba(255, 255, 255, 0.12);
    color: #CBB8FC;
    border-radius: 8px;
    font-family: var(--font-body);
    font-size: 12px;
    font-weight: 500;
    cursor: pointer;
    transition: all 0.2s ease;
    white-space: nowrap;
}

.btn-demote:hover {
    background: rgba(157, 124, 249, 0.15);
    border-color: rgba(157, 124, 249, 0.35);
    color: #ffffff;
}

.btn-action-edit {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    padding: 6px 12px;
    background: rgba(108, 160, 242, 0.12);
    border: 1px solid rgba(108, 160, 242, 0.3);
    color: var(--blue);
    border-radius: 8px;
    font-family: var(--font-body);
    font-size: 12px;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.2s ease;
}

.btn-action-edit:hover {
    background: var(--blue);
    color: #0c2240;
    transform: translateY(-1px);
}

.btn-action-delete {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    padding: 6px 12px;
    background: rgba(239, 107, 107, 0.12);
    border: 1px solid rgba(239, 107, 107, 0.3);
    color: var(--red);
    border-radius: 8px;
    font-family: var(--font-body);
    font-size: 12px;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.2s ease;
}

.btn-action-delete:hover {
    background: var(--red);
    color: #2e0a0a;
    transform: translateY(-1px);
}

/* BADGES */
.badge {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 4px 10px;
    border-radius: 6px;
    font-size: 11px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.badge.super-admin {
    background: rgba(242, 197, 114, 0.18);
    border: 1px solid rgba(242, 197, 114, 0.45);
    color: #F2C572;
}

.badge.admin {
    background: rgba(157, 124, 249, 0.15);
    border: 1px solid rgba(157, 124, 249, 0.35);
    color: #CBB8FC;
}

.badge.current-user {
    background: rgba(111, 207, 151, 0.12);
    border: 1px solid rgba(111, 207, 151, 0.3);
    color: #6FCF97;
    font-size: 11px;
    padding: 3px 8px;
    border-radius: 6px;
}

/* TABLE STYLING */
.table-wrap {
    overflow-x: auto;
}

table.admin-table {
    width: 100%;
    border-collapse: collapse;
    text-align: left;
}

table.admin-table th {
    padding: 12px 16px;
    font-size: 12px;
    font-weight: 600;
    color: var(--text-dim);
    text-transform: uppercase;
    letter-spacing: 0.5px;
    border-bottom: 1px solid var(--border);
}

table.admin-table td {
    padding: 16px;
    border-bottom: 1px solid var(--border);
    vertical-align: middle;
    font-size: 14px;
}

table.admin-table tr:last-child td {
    border-bottom: none;
}

table.admin-table tr:hover td {
    background: rgba(255, 255, 255, 0.02);
}

.user-cell {
    display: flex;
    align-items: center;
    gap: 12px;
}

.user-avatar {
    width: 38px;
    height: 38px;
    border-radius: 50%;
    background: rgba(157, 124, 249, 0.2);
    border: 1px solid rgba(157, 124, 249, 0.35);
    color: #F1EEF7;
    display: flex;
    align-items: center;
    justify-content: center;
    font-weight: 700;
    font-size: 14px;
    flex-shrink: 0;
}

.user-avatar.gold {
    background: rgba(242, 197, 114, 0.2);
    border-color: rgba(242, 197, 114, 0.45);
    color: #F2C572;
}

.user-name-box .name {
    font-weight: 600;
    color: var(--text);
    display: flex;
    align-items: center;
    gap: 8px;
}

.user-name-box .email {
    font-size: 12px;
    color: var(--text-dim);
    margin-top: 2px;
}

.action-buttons {
    display: flex;
    align-items: center;
    gap: 8px;
    flex-wrap: wrap;
}

/* MODAL */
.modal-overlay {
    position: fixed;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background: rgba(10, 8, 14, 0.75);
    backdrop-filter: blur(8px);
    -webkit-backdrop-filter: blur(8px);
    z-index: 1000;
    display: none;
    align-items: center;
    justify-content: center;
    padding: 20px;
}

.modal-overlay.active {
    display: flex;
    animation: fadeIn 0.2s ease-out;
}

.modal-card {
    background: #181521;
    border: 1px solid rgba(255,255,255,0.12);
    border-radius: 20px;
    width: 100%;
    max-width: 480px;
    padding: 32px;
    box-shadow: 0 20px 50px rgba(0,0,0,0.6);
    position: relative;
    transform: translateY(0);
    animation: rise 0.3s ease-out;
}

.modal-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 20px;
    padding-bottom: 14px;
    border-bottom: 1px solid var(--border);
}

.modal-header h3 {
    margin: 0;
    font-family: var(--font-display);
    font-size: 20px;
}

.modal-close-btn {
    background: transparent;
    border: none;
    color: var(--text-dim);
    font-size: 20px;
    cursor: pointer;
    line-height: 1;
    transition: color 0.2s;
}

.modal-close-btn:hover {
    color: var(--text);
}

.modal-actions {
    display: flex;
    gap: 10px;
    margin-top: 24px;
}

.btn-secondary {
    padding: 12px 18px;
    background: transparent;
    border: 1px solid var(--border);
    color: var(--text-dim);
    border-radius: 10px;
    font-family: var(--font-body);
    font-size: 14px;
    font-weight: 500;
    cursor: pointer;
    transition: all 0.2s;
}

.btn-secondary:hover {
    color: var(--text);
    border-color: rgba(255,255,255,0.25);
}

@keyframes rise {
    from { opacity: 0; transform: translateY(16px); }
    to { opacity: 1; transform: translateY(0); }
}

@keyframes fadeIn {
    from { opacity: 0; }
    to { opacity: 1; }
}

@media (max-width: 768px) {
    .stats-grid {
        grid-template-columns: 1fr;
    }
    .container {
        padding: 24px 16px 70px;
    }
}
</style>
</head>
<body>

<?php require __DIR__ . "/nav.php"; ?>

<div class="container">
    <div class="glow"></div>

    <!-- Page Header -->
    <div class="page-header">
        <div class="header-title">
            <h1>
                <span class="crown-icon">👑</span>
                Admins Management
            </h1>
            <p>Super Admin control center. Create new platform admins, update admin records, and promote administrators to Super Admin.</p>
        </div>
    </div>

    <!-- Notification Messages -->
    <?php if ($successMsg): ?>
        <div class="alert success" role="alert">
            <span>✅ <?php echo htmlspecialchars($successMsg); ?></span>
            <button class="alert-close" onclick="this.parentElement.remove();">&times;</button>
        </div>
    <?php endif; ?>

    <?php if ($errorMsg): ?>
        <div class="alert error" role="alert">
            <span>⚠ <?php echo htmlspecialchars($errorMsg); ?></span>
            <button class="alert-close" onclick="this.parentElement.remove();">&times;</button>
        </div>
    <?php endif; ?>

    <!-- Overview Stats -->
    <div class="stats-grid">
        <div class="stat-card gold">
            <div class="stat-label">👑 Super Admins</div>
            <div class="stat-value"><?php echo $superAdminCount; ?></div>
        </div>
        <div class="stat-card violet">
            <div class="stat-label">🛡️ Regular Admins</div>
            <div class="stat-value"><?php echo $adminCount; ?></div>
        </div>
        <div class="stat-card">
            <div class="stat-label">👥 Customers</div>
            <div class="stat-value"><?php echo $customerCount; ?></div>
        </div>
    </div>

    <!-- Main Two-Column Layout -->
    <div class="layout-grid">
        
        <!-- Column 1: Create New Admin Form -->
        <div class="panel">
            <div class="panel-header">
                <div>
                    <h2 class="panel-title">➕ Create Admin</h2>
                    <p class="panel-subtitle">Only Super Admins can add new administrators</p>
                </div>
            </div>

            <form method="POST" action="manage_admins.php" autocomplete="off">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                <input type="hidden" name="action" value="create_admin">

                <div class="form-group">
                    <label for="create_name">Full Name *</label>
                    <input type="text" id="create_name" name="name" placeholder="e.g. John Doe" required>
                </div>

                <div class="form-group">
                    <label for="create_email">Email Address *</label>
                    <input type="email" id="create_email" name="email" placeholder="admin@tickethub.com" required>
                </div>

                <div class="form-group">
                    <label for="create_role">Role Assignment *</label>
                    <select id="create_role" name="role" required>
                        <option value="admin" selected>Admin (Platform Manager)</option>
                        <option value="super_admin">👑 Super Admin (Full Privileges)</option>
                    </select>
                    <div class="field-hint">Admins manage events; Super Admins also manage other admins.</div>
                </div>

                <div class="form-group">
                    <label for="create_password">Initial Password *</label>
                    <input type="password" id="create_password" name="password" placeholder="At least 8 characters" minlength="8" required>
                </div>

                <div class="form-group">
                    <label for="create_confirm_password">Confirm Password *</label>
                    <input type="password" id="create_confirm_password" name="confirm_password" placeholder="Re-enter password" minlength="8" required>
                </div>

                <button type="submit" class="btn-primary" style="margin-top: 10px;">
                    <span>Save &amp; Create Admin</span>
                </button>
            </form>
        </div>

        <!-- Column 2: Administrators Directory & Privileges -->
        <div class="panel">
            <div class="panel-header">
                <div>
                    <h2 class="panel-title">👥 Administrators Directory</h2>
                    <p class="panel-subtitle">Manage roles and promote admins to Super Admin</p>
                </div>
            </div>

            <div class="table-wrap">
                <table class="admin-table">
                    <thead>
                        <tr>
                            <th>Administrator</th>
                            <th>Role</th>
                            <th>Created</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($adminsList)): ?>
                            <tr>
                                <td colspan="4" style="text-align: center; color: var(--text-dim); padding: 40px 20px;">
                                    No administrators found in system.
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($adminsList as $adm): 
                                $isSelf = ($adm['id'] == $currentUserId);
                                $isSuper = ($adm['role'] === 'super_admin');
                                $initials = strtoupper(substr($adm['name'], 0, 1));
                            ?>
                            <tr>
                                <td>
                                    <div class="user-cell">
                                        <div class="user-avatar <?php echo $isSuper ? 'gold' : ''; ?>">
                                            <?php echo htmlspecialchars($initials); ?>
                                        </div>
                                        <div class="user-name-box">
                                            <div class="name">
                                                <?php echo htmlspecialchars($adm['name']); ?>
                                                <?php if ($isSelf): ?>
                                                    <span class="badge current-user">You</span>
                                                <?php endif; ?>
                                            </div>
                                            <div class="email"><?php echo htmlspecialchars($adm['email']); ?></div>
                                        </div>
                                    </div>
                                </td>
                                <td>
                                    <?php if ($isSuper): ?>
                                        <span class="badge super-admin">👑 Super Admin</span>
                                    <?php else: ?>
                                        <span class="badge admin">🛡️ Admin</span>
                                    <?php endif; ?>
                                </td>
                                <td style="color: var(--text-dim); font-size: 13px;">
                                    <?php echo date("M d, Y", strtotime($adm['created_at'])); ?>
                                </td>
                                <td>
                                    <div class="action-buttons">
                                        <?php if (!$isSuper): ?>
                                            <!-- The button requested by USER: "Mark as Super Admin" -->
                                            <form method="POST" action="manage_admins.php" style="display:inline;" onsubmit="return confirm('Promote \'<?php echo htmlspecialchars(addslashes($adm['name'])); ?>\' to Super Admin? They will have full privileges to create and manage admins.');">
                                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                                                <input type="hidden" name="action" value="mark_super_admin">
                                                <input type="hidden" name="target_user_id" value="<?php echo (int)$adm['id']; ?>">
                                                <button type="submit" class="btn-mark-super" title="Promote to Super Admin">
                                                    👑 Mark as Super Admin
                                                </button>
                                            </form>
                                        <?php elseif (!$isSelf): ?>
                                            <!-- Option to demote another super admin to regular admin if needed -->
                                            <form method="POST" action="manage_admins.php" style="display:inline;" onsubmit="return confirm('Change \'<?php echo htmlspecialchars(addslashes($adm['name'])); ?>\' from Super Admin to regular Admin?');">
                                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                                                <input type="hidden" name="action" value="demote_to_admin">
                                                <input type="hidden" name="target_user_id" value="<?php echo (int)$adm['id']; ?>">
                                                <button type="submit" class="btn-demote" title="Change to regular Admin">
                                                    Demote to Admin
                                                </button>
                                            </form>
                                        <?php endif; ?>

                                        <!-- Edit / Save Admin Data button -->
                                        <button type="button" class="btn-action-edit" onclick="openEditModal(<?php echo htmlspecialchars(json_encode([
                                            'id' => (int)$adm['id'],
                                            'name' => $adm['name'],
                                            'email' => $adm['email'],
                                            'role' => $adm['role'],
                                            'is_self' => $isSelf
                                        ])); ?>)">
                                            Edit Data
                                        </button>

                                        <?php if (!$isSelf): ?>
                                            <!-- Delete admin button -->
                                            <form method="POST" action="manage_admins.php" style="display:inline;" onsubmit="return confirm('Delete administrator \'<?php echo htmlspecialchars(addslashes($adm['name'])); ?>\'? This action cannot be undone.');">
                                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                                                <input type="hidden" name="action" value="delete_admin">
                                                <input type="hidden" name="target_user_id" value="<?php echo (int)$adm['id']; ?>">
                                                <button type="submit" class="btn-action-delete" title="Delete account">
                                                    Delete
                                                </button>
                                            </form>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

    </div>
</div>

<!-- Modal: Save / Edit Admin Data -->
<div class="modal-overlay" id="editAdminModal" onclick="if(event.target === this) closeEditModal();">
    <div class="modal-card">
        <div class="modal-header">
            <h3>✏️ Edit Administrator Data</h3>
            <button class="modal-close-btn" type="button" onclick="closeEditModal();">&times;</button>
        </div>

        <form method="POST" action="manage_admins.php" autocomplete="off">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
            <input type="hidden" name="action" value="save_admin_data">
            <input type="hidden" name="target_user_id" id="edit_user_id" value="">

            <div class="form-group">
                <label for="edit_name">Full Name *</label>
                <input type="text" id="edit_name" name="name" required>
            </div>

            <div class="form-group">
                <label for="edit_email">Email Address *</label>
                <input type="email" id="edit_email" name="email" required>
            </div>

            <div class="form-group">
                <label for="edit_role">Administrator Role *</label>
                <select id="edit_role" name="role" required>
                    <option value="admin">Admin (Platform Manager)</option>
                    <option value="super_admin">👑 Super Admin</option>
                </select>
                <div class="field-hint" id="edit_role_hint">Super Admins can create and manage other admins.</div>
            </div>

            <div class="form-group">
                <label for="edit_password">New Password (leave blank to keep current)</label>
                <input type="password" id="edit_password" name="new_password" placeholder="Min 8 characters (optional)" minlength="8">
            </div>

            <div class="modal-actions">
                <button type="submit" class="btn-primary" style="flex: 1;">
                    💾 Save Admin Data
                </button>
                <button type="button" class="btn-secondary" onclick="closeEditModal();">
                    Cancel
                </button>
            </div>
        </form>
    </div>
</div>

<script>
function openEditModal(data) {
    document.getElementById('edit_user_id').value = data.id;
    document.getElementById('edit_name').value = data.name;
    document.getElementById('edit_email').value = data.email;
    document.getElementById('edit_role').value = data.role;
    document.getElementById('edit_password').value = '';

    const roleSelect = document.getElementById('edit_role');
    const roleHint = document.getElementById('edit_role_hint');

    // If editing self, prevent demoting self via this form
    if (data.is_self) {
        roleSelect.disabled = true;
        roleHint.textContent = "You cannot change your own Super Admin role.";
    } else {
        roleSelect.disabled = false;
        roleHint.textContent = "Super Admins can create and manage other admins.";
    }

    document.getElementById('editAdminModal').classList.add('active');
}

function closeEditModal() {
    document.getElementById('editAdminModal').classList.remove('active');
}

// Close on escape key
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        closeEditModal();
    }
});
</script>

<?php require __DIR__ . "/footer.php"; ?>
</body>
</html>
