<?php
// update_status.php - Update Request Status
session_start();

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'technician') {
    header('Location: ../index.php');
    exit();
}

require_once '../includes/db_connect.php';

$request_id = isset($_GET['id']) ? intval($_GET['id']) : 0;
$new_status = isset($_GET['status']) ? $_GET['status'] : '';

if ($request_id > 0 && !empty($new_status)) {
    $user_id = $_SESSION['user_id'];
    
    // Verify the request is assigned to this technician
    $check_query = "SELECT id FROM maintenance_requests 
                    WHERE id = ? AND assigned_to = ?";
    $check_stmt = mysqli_prepare($conn, $check_query);
    mysqli_stmt_bind_param($check_stmt, 'ii', $request_id, $user_id);
    mysqli_stmt_execute($check_stmt);
    $check_result = mysqli_stmt_get_result($check_stmt);
    
    if (mysqli_num_rows($check_result) > 0) {
        // Update status
        $update_query = "UPDATE maintenance_requests 
                        SET status = ?, updated_at = NOW() 
                        WHERE id = ?";
        $update_stmt = mysqli_prepare($conn, $update_query);
        mysqli_stmt_bind_param($update_stmt, 'si', $new_status, $request_id);
        mysqli_stmt_execute($update_stmt);
        
        // Redirect back to technician dashboard
        header('Location: technician.php?status=updated');
        exit();
    }
}

header('Location: technician.php');
exit();
?>