<?php
// includes/db_connect.php
$conn = mysqli_connect("localhost", "root", "", "gear_guard");
if (!$conn) {
    die("Database connection failed: " . mysqli_connect_error());
}
mysqli_set_charset($conn, "utf8");
?>