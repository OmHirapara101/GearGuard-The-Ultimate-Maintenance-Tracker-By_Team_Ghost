<?php
// sidebar.php - FIXED
// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Use the same database connection as config.php
$servername = "localhost";
$username = "root";
$password = "";
$dbname = "gear_guard";

$conn = mysqli_connect($servername, $username, $password, $dbname);

if (!$conn) {
    // Don't die, just show zero counts
    $equipment_count = 0;
    $pending_requests = 0;
} else {
    // Get counts for badges
    $equipment_count = 0;
    $pending_requests = 0;
    
    // Total equipment
    $result = mysqli_query($conn, "SELECT COUNT(*) as count FROM equipment");
    if ($result) {
        $row = mysqli_fetch_assoc($result);
        $equipment_count = $row['count'];
    }
    
    // Pending requests
    $result = mysqli_query($conn, "SELECT COUNT(*) as count FROM maintenance_requests WHERE status = 'New'");
    if ($result) {
        $row = mysqli_fetch_assoc($result);
        $pending_requests = $row['count'];
    }
    
    mysqli_close($conn);
}

// Get current page
$current_page = basename($_SERVER['PHP_SELF']);
?>

<nav class="sidebar">
    <ul class="sidebar-menu">
        <li>
            <a href="dashboard.php" class="<?php echo $current_page == 'dashboard.php' ? 'active' : ''; ?>">
                <span style="font-size: 1.2rem;">📊</span>
                <span>Dashboard</span>
            </a>
        </li>
        <li>
            <a href="equipment.php" class="<?php echo $current_page == 'equipment.php' ? 'active' : ''; ?>">
                <span style="font-size: 1.2rem;">⚙️</span>
                <span>Equipment</span>
                <?php if ($equipment_count > 0): ?>
                    <span class="badge"><?php echo $equipment_count; ?></span>
                <?php endif; ?>
            </a>
        </li>
        <li>
            <a href="teams.php" class="<?php echo $current_page == 'teams.php' ? 'active' : ''; ?>">
                <span style="font-size: 1.2rem;">👥</span>
                <span>Teams</span>
            </a>
        </li>
        <li>
            <a href="requests.php" class="<?php echo $current_page == 'requests.php' ? 'active' : ''; ?>">
                <span style="font-size: 1.2rem;">📋</span>
                <span>Requests</span>
                <?php if ($pending_requests > 0): ?>
                    <span class="badge"><?php echo $pending_requests; ?> pending</span>
                <?php endif; ?>
            </a>
        </li>
        <li>
            <a href="calendar.php" class="<?php echo $current_page == 'calendar.php' ? 'active' : ''; ?>">
                <span style="font-size: 1.2rem;">📅</span>
                <span>Calendar</span>
            </a>
        </li>
        <li style="margin-top: 30px; padding-top: 20px; border-top: 1px solid #eaeaea;">
        </li>
        <li>
            <a href="../logout.php" style="color: #f44336;">
                <span style="font-size: 1.1rem;">🚪</span>
                <span>Logout</span>
            </a>
        </li>
    </ul>
</nav>