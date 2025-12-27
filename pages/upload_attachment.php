<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: ../index.php');
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['attachment'])) {
    $request_id = $_POST['request_id'];
    $upload_dir = '../uploads/requests/';
    
    // Create directory if it doesn't exist
    if (!is_dir($upload_dir)) {
        mkdir($upload_dir, 0777, true);
    }
    
    $file = $_FILES['attachment'];
    $file_name = time() . '_' . basename($file['name']);
    $target_path = $upload_dir . $file_name;
    
    if (move_uploaded_file($file['tmp_name'], $target_path)) {
        // Save to database
        require_once '../includes/db_connect.php';
        $query = "INSERT INTO request_attachments (request_id, file_name, file_path, file_size, uploaded_at) 
                  VALUES (?, ?, ?, ?, NOW())";
        $stmt = mysqli_prepare($conn, $query);
        mysqli_stmt_bind_param($stmt, 'issi', $request_id, $file['name'], $file_name, $file['size']);
        mysqli_stmt_execute($stmt);
        
        header("Location: view_request.php?id=$request_id&success=attachment_added");
    }
}
?>