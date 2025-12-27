<?php
// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
?>

<header class="header">
    <div class="logo">
        <span style="font-size: 1.8rem; margin-right: 8px;">⚙️</span>
        <div>
            <div style="font-size: 1.3rem; font-weight: bold;">GearGuard</div>
            <div style="font-size: 0.8rem; opacity: 0.9;">Maintenance Tracker</div>
        </div>
    </div>
    
    <div class="user-info">
        <div style="display: flex; align-items: center; gap: 8px;">
            <span style="background: rgba(255,255,255,0.2); padding: 5px 10px; border-radius: 4px; font-size: 0.9rem;">
                <span id="current-time"><?php echo date('h:i A'); ?></span>
            </span>
        </div>
        <div style="display: flex; align-items: center; gap: 15px;">
            <div style="text-align: right;">
                <div style="font-weight: 500;"><?php echo htmlspecialchars($_SESSION['full_name'] ?? 'User'); ?></div>
                <div style="font-size: 0.85rem; opacity: 0.9;"><?php echo ucfirst($_SESSION['role'] ?? 'user'); ?></div>
            </div>
            <a href="../logout.php" class="btn btn-danger btn-sm">
                <span style="margin-right: 5px;">🚪</span> Logout
            </a>
        </div>
    </div>
</header>

<script>
// Update time every minute
function updateTime() {
    const now = new Date();
    const timeElement = document.getElementById('current-time');
    if (timeElement) {
        const timeString = now.toLocaleTimeString('en-US', {
            hour: 'numeric',
            minute: '2-digit',
            hour12: true
        });
        timeElement.textContent = timeString;
    }
}

// Update time immediately
updateTime();

// Update every minute
setInterval(updateTime, 60000);
</script>