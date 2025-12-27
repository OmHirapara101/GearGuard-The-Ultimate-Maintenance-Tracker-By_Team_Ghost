<?php
// config.php - UPDATED

// Database configuration - USE gear_guard (not gear_guard_db)
define('DB_HOST', 'localhost');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_NAME', 'gear_guard');

// Create connection
$conn = mysqli_connect(DB_HOST, DB_USER, DB_PASS, DB_NAME);

// Check connection
if (!$conn) {
    die("❌ Database Connection Failed: " . mysqli_connect_error() . 
        "<br>Please run <a href='database_setup.php'>database_setup.php</a> first.");
}

// Check if user is logged in
function check_login() {
    if (!isset($_SESSION['user_id'])) {
        header('Location: ../index.php');
        exit();
    }
}
?>