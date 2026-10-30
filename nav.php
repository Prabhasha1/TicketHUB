<?php
/**
 * nav.php
 * ------------------------------------------------------------
 * Shared navigation bar for TicketHub.
 * Automatically checks session state and renders:
 * - Customer navigation (Home, Events, My Tickets)
 * - Admin navigation (Dashboard, Manage Events, Scan Tickets) for Admins & Super Admins
 * - Admin Management (Manage Admins) strictly for Super Admins
 * - Active state indicator on the current page link
 * - User greeting & Logout / Login / Register buttons
 * ------------------------------------------------------------
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$isNavLoggedIn   = isset($_SESSION['user_id']);
$navUserName     = $isNavLoggedIn ? ($_SESSION['user_name'] ?? 'User') : '';
$navUserRole     = $_SESSION['user_role'] ?? '';
$isNavAdmin      = in_array($navUserRole, ['admin', 'super_admin'], true);
$isNavSuperAdmin = ($navUserRole === 'super_admin');
$currentPage     = basename($_SERVER['PHP_SELF'] ?? '');
?>
<style>
/* Shared Navigation Styles */
header.site-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 20px 48px;
    background: rgba(20, 18, 26, 0.82);
    backdrop-filter: blur(14px);
    -webkit-backdrop-filter: blur(14px);
    border-bottom: 1px solid rgba(255, 255, 255, 0.08);
    position: sticky;
    top: 0;
    z-index: 1000;
}

header.site-header .logo {
    font-family: 'Sora', 'Segoe UI', sans-serif;
    font-size: 22px;
    font-weight: 700;
    letter-spacing: -0.01em;
    color: #F1EEF7;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    gap: 8px;
}

header.site-header .logo span.highlight {
    color: #9D7CF9;
}

header.site-header nav {
    display: flex;
    align-items: center;
    gap: 4px;
    flex-wrap: nowrap;
}

header.site-header nav a,
header.site-header nav span {
    padding: 8px 14px;
    border-radius: 8px;
    text-decoration: none;
    color: #A9A3B8;
    font-size: 14px;
    font-weight: 500;
    transition: color 0.2s ease, background 0.2s ease;
    white-space: nowrap;
    font-family: 'Inter', 'Segoe UI', sans-serif;
}

header.site-header nav a:hover {
    color: #F1EEF7;
    background: rgba(255, 255, 255, 0.06);
}

header.site-header nav a.active {
    color: #F1EEF7;
    background: rgba(255, 255, 255, 0.1);
    font-weight: 600;
}

header.site-header nav .nav-divider {
    display: inline-block;
    width: 1px;
    height: 18px;
    background: rgba(255, 255, 255, 0.12);
    margin: 0 4px;
    padding: 0;
}

header.site-header nav a.admin-link {
    color: #CBB8FC;
    background: rgba(157, 124, 249, 0.12);
    border: 1px solid rgba(157, 124, 249, 0.25);
}

header.site-header nav a.admin-link:hover {
    background: rgba(157, 124, 249, 0.22);
    color: #ffffff;
    border-color: rgba(157, 124, 249, 0.45);
}

header.site-header nav a.admin-link.active {
    background: rgba(157, 124, 249, 0.28);
    color: #ffffff;
    border-color: rgba(157, 124, 249, 0.55);
}

header.site-header nav a.super-admin-link {
    color: #F8D696;
    background: rgba(242, 197, 114, 0.12);
    border: 1px solid rgba(242, 197, 114, 0.3);
}

header.site-header nav a.super-admin-link:hover {
    background: rgba(242, 197, 114, 0.22);
    color: #ffffff;
    border-color: rgba(242, 197, 114, 0.55);
}

header.site-header nav a.super-admin-link.active {
    background: rgba(242, 197, 114, 0.28);
    color: #ffffff;
    border-color: rgba(242, 197, 114, 0.65);
}

header.site-header nav span.welcome {
    color: #F1EEF7;
    font-weight: 500;
}

header.site-header nav span.admin-badge {
    background: rgba(157, 124, 249, 0.2);
    border: 1px solid rgba(157, 124, 249, 0.38);
    color: #9D7CF9;
    font-size: 11px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    padding: 2px 7px;
    border-radius: 6px;
    margin-left: 6px;
    display: inline-block;
}

header.site-header nav span.super-admin-badge {
    background: rgba(242, 197, 114, 0.18);
    border: 1px solid rgba(242, 197, 114, 0.45);
    color: #F2C572;
    font-size: 11px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    padding: 2px 8px;
    border-radius: 6px;
    margin-left: 6px;
    display: inline-block;
}

header.site-header nav a.profile-link {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    padding: 5px 12px 5px 5px;
    color: #F1EEF7;
    border: 1px solid rgba(255, 255, 255, 0.1);
    border-radius: 100px;
}

header.site-header nav a.profile-link:hover,
header.site-header nav a.profile-link.active {
    background: rgba(157, 124, 249, 0.16);
    border-color: rgba(157, 124, 249, 0.45);
}

header.site-header nav .nav-avatar {
    width: 26px;
    height: 26px;
    padding: 0;
    border-radius: 50%;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    font-size: 11px;
    font-weight: 700;
    color: #ffffff;
    background: linear-gradient(135deg, #9D7CF9, #F0729C);
}

header.site-header nav a.profile-link span.admin-badge,
header.site-header nav a.profile-link span.super-admin-badge {
    margin-left: 0;
    padding: 2px 7px;
}

header.site-header nav a.cta {
    color: #14121A;
    background: #F1EEF7;
    margin-left: 6px;
    font-weight: 600;
}

header.site-header nav a.cta:hover {
    background: #9D7CF9;
    color: #ffffff;
}

@media (max-width: 768px) {
    header.site-header {
        padding: 16px 20px;
    }
}

/* Hamburger toggle (only shown in compact mode) */
header.site-header .nav-toggle {
    display: none;
    width: 42px;
    height: 42px;
    padding: 0;
    border: 1px solid rgba(255, 255, 255, 0.12);
    border-radius: 10px;
    background: rgba(255, 255, 255, 0.04);
    cursor: pointer;
    align-items: center;
    justify-content: center;
    flex-direction: column;
    gap: 5px;
    -webkit-tap-highlight-color: transparent;
}

header.site-header .nav-toggle span {
    display: block;
    width: 18px;
    height: 2px;
    border-radius: 2px;
    background: #F1EEF7;
    transition: transform 0.25s ease, opacity 0.2s ease;
}

header.site-header.nav-open .nav-toggle span:nth-child(1) { transform: translateY(7px) rotate(45deg); }
header.site-header.nav-open .nav-toggle span:nth-child(2) { opacity: 0; }
header.site-header.nav-open .nav-toggle span:nth-child(3) { transform: translateY(-7px) rotate(-45deg); }

/* Compact mode: set by the script below when links don't fit on one row */
header.site-header.is-compact .nav-toggle {
    display: inline-flex;
}

header.site-header.is-compact nav {
    display: none;
    position: absolute;
    top: 100%;
    left: 0;
    right: 0;
    flex-direction: column;
    align-items: stretch;
    gap: 6px;
    padding: 14px 20px 22px;
    max-height: calc(100vh - 74px);
    max-height: calc(100dvh - 74px);
    overflow-y: auto;
    background: rgba(20, 18, 26, 0.98);
    border-bottom: 1px solid rgba(255, 255, 255, 0.08);
    box-shadow: 0 18px 40px rgba(0, 0, 0, 0.45);
}

header.site-header.is-compact.nav-open nav {
    display: flex;
}

header.site-header.is-compact nav a {
    padding: 13px 16px;
    font-size: 15px;
    white-space: normal;
}

header.site-header.is-compact nav .nav-divider {
    width: auto;
    height: 1px;
    margin: 6px 0;
}

header.site-header.is-compact nav a.profile-link {
    order: -1;
    padding: 8px 14px 8px 8px;
    border-radius: 12px;
    margin-bottom: 4px;
    flex-wrap: wrap;
}

header.site-header.is-compact nav a.cta {
    margin: 6px 0 0;
    text-align: center;
}
</style>

<header class="site-header">
    <a href="index.php" class="logo">
        Ticket<span class="highlight">Hub</span>
    </a>
    <button type="button" class="nav-toggle" aria-label="Open menu" aria-expanded="false" aria-controls="site-nav">
        <span></span><span></span><span></span>
    </button>
    <nav id="site-nav">
        <!-- Customer Navigation (available to all) -->
        <a href="index.php" class="<?php echo ($currentPage === 'index.php') ? 'active' : ''; ?>">Home</a>
        <a href="events.php" class="<?php echo ($currentPage === 'events.php') ? 'active' : ''; ?>">Events</a>
        <a href="my_tickets.php" class="<?php echo ($currentPage === 'my_tickets.php') ? 'active' : ''; ?>">My Tickets</a>

        <!-- Admin Navigation (visible to admins and super admins) -->
        <?php if ($isNavAdmin): ?>
            <span class="nav-divider" aria-hidden="true"></span>
            <a href="admin_dashboard.php" class="admin-link <?php echo ($currentPage === 'admin_dashboard.php') ? 'active' : ''; ?>">Dashboard</a>
            <a href="manage_events.php" class="admin-link <?php echo in_array($currentPage, ['manage_events.php', 'add_event.php', 'edit_event.php', 'event_details.php', 'refund_requests.php']) ? 'active' : ''; ?>">Manage Events</a>
            <a href="scan_tickets.php" class="admin-link <?php echo ($currentPage === 'scan_tickets.php') ? 'active' : ''; ?>">📷 Scan Tickets</a>
            
            <!-- Super Admin only link -->
            <?php if ($isNavSuperAdmin): ?>
                <a href="customers.php" class="super-admin-link <?php echo in_array($currentPage, ['customers.php', 'customer_details.php']) ? 'active' : ''; ?>">👥 Customers</a>
                <a href="manage_admins.php" class="super-admin-link <?php echo ($currentPage === 'manage_admins.php') ? 'active' : ''; ?>">👑 Admins Management</a>
            <?php endif; ?>
        <?php endif; ?>

        <!-- User identity / Session actions -->
        <?php if ($isNavLoggedIn): ?>
            <?php
                $navInitials = '';
                foreach (array_slice(preg_split('/\s+/', trim($navUserName)), 0, 2) as $navPart) {
                    $navInitials .= mb_strtoupper(mb_substr($navPart, 0, 1));
                }
            ?>
            <a href="profile.php" class="profile-link <?php echo ($currentPage === 'profile.php') ? 'active' : ''; ?>" title="My profile — edit your details and password">
                <span class="nav-avatar" aria-hidden="true"><?php echo htmlspecialchars($navInitials ?: '🙂'); ?></span>
                <?php echo htmlspecialchars($navUserName); ?>
                <?php if ($isNavSuperAdmin): ?>
                    <span class="super-admin-badge">👑 Super Admin</span>
                <?php elseif ($isNavAdmin): ?>
                    <span class="admin-badge">Admin</span>
                <?php endif; ?>
            </a>
            <a href="logout.php" class="cta">Logout</a>
        <?php else: ?>
            <a href="login.php" class="<?php echo ($currentPage === 'login.php') ? 'active' : ''; ?>">Login</a>
            <a href="register.php" class="cta <?php echo ($currentPage === 'register.php') ? 'active' : ''; ?>">Register</a>
        <?php endif; ?>
    </nav>
</header>
<script>
/* Collapse the nav into a hamburger menu whenever its links don't fit on one row. */
(function () {
    var header = document.querySelector('header.site-header');
    if (!header) return;
    var toggle = header.querySelector('.nav-toggle');

    function setOpen(open) {
        header.classList.toggle('nav-open', open);
        toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
        toggle.setAttribute('aria-label', open ? 'Close menu' : 'Open menu');
    }

    function fit() {
        var wasCompact = header.classList.contains('is-compact');
        header.classList.remove('is-compact');
        var compact = window.innerWidth <= 768 || header.scrollWidth > header.clientWidth + 1;
        header.classList.toggle('is-compact', compact);
        if (wasCompact && !compact) setOpen(false);
    }

    toggle.addEventListener('click', function (e) {
        e.stopPropagation();
        setOpen(!header.classList.contains('nav-open'));
    });
    document.addEventListener('click', function (e) {
        if (header.classList.contains('nav-open') && !header.contains(e.target)) setOpen(false);
    });
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') setOpen(false);
    });

    fit();
    window.addEventListener('resize', fit);
    if (document.fonts && document.fonts.ready) document.fonts.ready.then(fit);
})();
</script>
