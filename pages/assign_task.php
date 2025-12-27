<?php
session_start();
require_once 'db_connection.php';

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['assign_task'])) {
    $team_id = intval($_POST['team_id']);
    $subject = mysqli_real_escape_string($conn, $_POST['subject']);
    $equipment_id = intval($_POST['equipment_id']);
    $description = mysqli_real_escape_string($conn, $_POST['description']);
    $priority = mysqli_real_escape_string($conn, $_POST['priority']);
    $type = mysqli_real_escape_string($conn, $_POST['type']);
    $scheduled_date = $_POST['scheduled_date'] ?: NULL;
    $created_by = $_SESSION['user_id'];
    
    // Generate request number
    $request_number = 'REQ-' . date('Ymd') . '-' . rand(1000, 9999);
    
    $sql = "INSERT INTO maintenance_requests 
            (request_number, subject, description, equipment_id, type, priority, scheduled_date, created_by) 
            VALUES ('$request_number', '$subject', '$description', $equipment_id, '$type', '$priority', 
            " . ($scheduled_date ? "'$scheduled_date'" : "NULL") . ", $created_by)";
    
    if (mysqli_query($conn, $sql)) {
        $request_id = mysqli_insert_id($conn);
        
        // Add to history
        $history_sql = "INSERT INTO maintenance_history 
                       (request_id, action, performed_by, notes) 
                       VALUES ($request_id, 'Task created and assigned to team', $created_by, 'Task assignment')";
        mysqli_query($conn, $history_sql);
        
        $_SESSION['message'] = "Task assigned successfully!";
    } else {
        $_SESSION['message'] = "Error assigning task: " . mysqli_error($conn);
    }
    
    header('Location: teams.php');
    exit();
}
?>