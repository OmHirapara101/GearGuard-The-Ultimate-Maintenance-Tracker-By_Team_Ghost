<?php
// includes/sidebar.php - Role-based sidebar
$current_role = $_SESSION['role'] ?? 'user';
?>

<aside class="sidebar">
    <nav class="sidebar-menu">
        <ul>
            <li class="nav-item <?php echo basename($_SERVER['PHP_SELF']) == 'technician.php' ? 'active' : ''; ?>">
                <a href="technician.php">
                    <span class="nav-icon">📊</span>
                    <span class="nav-text">Dashboard</span>
                </a>
            </li>
            
            <?php if ($current_role === 'admin'): ?>
            <!-- Admin menu items -->
            <?php elseif ($current_role === 'technician'): ?>
                <li class="nav-item">
                    <a href="my_tasks.php">
                        <span class="nav-icon">✅</span>
                        <span class="nav-text">My Tasks</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a href="equipment.php">
                        <span class="nav-icon">⚙️</span>
                        <span class="nav-text">Equipment</span>
                    </a>
                </li>
            <?php endif; ?>
            
            <li class="nav-item">
                <a href="profile.php">
                    <span class="nav-icon">👤</span>
                    <span class="nav-text">Profile</span>
                </a>
            </li>
        </ul>
        <li style="margin-top: 30px; padding-top: 20px; border-top: 1px solid #eaeaea;">
        </li>
        <li>
            <a href="../logout.php" style="color: #f44336;">
                <span style="font-size: 1.1rem;">🚪</span>
                <span>Logout</span>
            </a>
        </li>
    </nav>
</aside>

