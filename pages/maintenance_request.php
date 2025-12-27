<?php
session_start();

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: ../index.php');
    exit();
}

// Check if request ID is provided and valid
if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    header('Location: calendar.php');
    exit();
}

$request_id = intval($_GET['id']);

if ($request_id <= 0) {
    header('Location: calendar.php');
    exit();
}

// Database connection
require_once '../config.php'; 
$conn = mysqli_connect("localhost", "root", "", "gear_guard");

if (!$conn) {
    die("Database connection failed: " . mysqli_connect_error());
}

// Set charset to UTF-8
mysqli_set_charset($conn, "utf8mb4");

// ==================== GET MAINTENANCE REQUEST DETAILS ====================
$query = "
    SELECT 
        mr.*,
        e.name as equipment_name,
        e.serial_number,
        e.location as equipment_location,
        e.notes as equipment_notes,
        e.status as equipment_status,
        e.category_id,
        e.department_id,
        ec.name as equipment_category,
        d.name as department_name,
        u_creator.full_name as created_by_name,
        u_creator.username as created_by_username,
        u_creator.email as created_by_email,
        u_assigned.full_name as assigned_to_name,
        u_assigned.username as assigned_to_username,
        u_assigned.email as assigned_to_email,
        u_assigned.role as assigned_to_role
    FROM maintenance_requests mr
    LEFT JOIN equipment e ON mr.equipment_id = e.id
    LEFT JOIN equipment_categories ec ON e.category_id = ec.id
    LEFT JOIN departments d ON e.department_id = d.id
    LEFT JOIN users u_creator ON mr.created_by = u_creator.id
    LEFT JOIN users u_assigned ON mr.assigned_to = u_assigned.id
    WHERE mr.id = ?
";

$stmt = mysqli_prepare($conn, $query);
if (!$stmt) {
    die("Query preparation failed: " . mysqli_error($conn));
}

mysqli_stmt_bind_param($stmt, "i", $request_id);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);

if (!$result || mysqli_num_rows($result) == 0) {
    echo "<script>alert('Maintenance request not found!'); window.location.href='calendar.php';</script>";
    mysqli_stmt_close($stmt);
    mysqli_close($conn);
    exit();
}

$request = mysqli_fetch_assoc($result);
mysqli_stmt_close($stmt);

// ==================== GET STATUS HISTORY ====================
$status_history = [];

// Use prepared statement for table existence check
$check_history_table = mysqli_prepare($conn, "SHOW TABLES LIKE 'maintenance_history'");
mysqli_stmt_execute($check_history_table);
$check_result = mysqli_stmt_get_result($check_history_table);
$history_table_exists = mysqli_num_rows($check_result) > 0;
mysqli_stmt_close($check_history_table);

if ($history_table_exists) {
    $history_query = "SELECT * FROM maintenance_history WHERE request_id = ? ORDER BY created_at DESC";
    $stmt = mysqli_prepare($conn, $history_query);
    mysqli_stmt_bind_param($stmt, "i", $request_id);
    mysqli_stmt_execute($stmt);
    $history_result = mysqli_stmt_get_result($stmt);
    
    if ($history_result) {
        while ($row = mysqli_fetch_assoc($history_result)) {
            $status_history[] = $row;
        }
    }
    mysqli_stmt_close($stmt);
}

// If no history exists or table doesn't exist, create initial history entry
if (empty($status_history)) {
    $initial_history = [
        'status' => $request['status'] ?? 'Unknown',
        'notes' => 'Request created',
        'created_by' => $request['created_by'] ?? 0,
        'created_at' => $request['created_at'] ?? date('Y-m-d H:i:s')
    ];
    array_unshift($status_history, $initial_history);
}

// ==================== GET COMMENTS/ACTIVITY LOG ====================
$comments = [];

// Check if maintenance_comments table exists
$check_comments_table = mysqli_prepare($conn, "SHOW TABLES LIKE 'maintenance_comments'");
mysqli_stmt_execute($check_comments_table);
$check_result = mysqli_stmt_get_result($check_comments_table);
$comments_table_exists = mysqli_num_rows($check_result) > 0;
mysqli_stmt_close($check_comments_table);

if ($comments_table_exists) {
    $comments_query = "
        SELECT 
            c.*,
            u.full_name as user_name,
            u.username,
            u.role
        FROM maintenance_comments c
        LEFT JOIN users u ON c.user_id = u.id
        WHERE c.request_id = ? 
        ORDER BY c.created_at DESC
    ";
    
    $stmt = mysqli_prepare($conn, $comments_query);
    mysqli_stmt_bind_param($stmt, "i", $request_id);
    mysqli_stmt_execute($stmt);
    $comments_result = mysqli_stmt_get_result($stmt);
    
    if ($comments_result) {
        while ($row = mysqli_fetch_assoc($comments_result)) {
            $comments[] = $row;
        }
    }
    mysqli_stmt_close($stmt);
}

// ==================== GET ATTACHMENTS ====================
$attachments = [];

// Check if maintenance_attachments table exists
$check_attachments_table = mysqli_prepare($conn, "SHOW TABLES LIKE 'maintenance_attachments'");
mysqli_stmt_execute($check_attachments_table);
$check_result = mysqli_stmt_get_result($check_attachments_table);
$attachments_table_exists = mysqli_num_rows($check_result) > 0;
mysqli_stmt_close($check_attachments_table);

if ($attachments_table_exists) {
    $attachments_query = "SELECT * FROM maintenance_attachments WHERE request_id = ? ORDER BY created_at DESC";
    $stmt = mysqli_prepare($conn, $attachments_query);
    mysqli_stmt_bind_param($stmt, "i", $request_id);
    mysqli_stmt_execute($stmt);
    $attachments_result = mysqli_stmt_get_result($stmt);
    
    if ($attachments_result) {
        while ($row = mysqli_fetch_assoc($attachments_result)) {
            $attachments[] = $row;
        }
    }
    mysqli_stmt_close($stmt);
}

// ==================== GET ALL USERS FOR ASSIGNMENT ====================
$users = [];
$users_query = "SELECT id, full_name, username, role, email FROM users WHERE status = 'active' ORDER BY role, full_name";
$users_result = mysqli_query($conn, $users_query);

if ($users_result) {
    while ($row = mysqli_fetch_assoc($users_result)) {
        $users[] = $row;
    }
}

// ==================== GET ALL STATUS OPTIONS ====================
$status_options = [];
$status_query = "SELECT DISTINCT status FROM maintenance_requests WHERE status IS NOT NULL ORDER BY status";
$status_result = mysqli_query($conn, $status_query);

if ($status_result) {
    while ($row = mysqli_fetch_assoc($status_result)) {
        $status_options[] = $row['status'];
    }
}

// ==================== GET ALL PRIORITY OPTIONS ====================
$priority_options = [];
$priority_query = "SELECT DISTINCT priority FROM maintenance_requests WHERE priority IS NOT NULL ORDER BY 
    CASE 
        WHEN priority = 'Critical' THEN 1
        WHEN priority = 'High' THEN 2
        WHEN priority = 'Medium' THEN 3
        WHEN priority = 'Low' THEN 4
        ELSE 5
    END";
$priority_result = mysqli_query($conn, $priority_query);

if ($priority_result) {
    while ($row = mysqli_fetch_assoc($priority_result)) {
        $priority_options[] = $row['priority'];
    }
}

// ==================== GET ALL MAINTENANCE TYPES ====================
$type_options = [];
$type_query = "SELECT DISTINCT type FROM maintenance_requests WHERE type IS NOT NULL ORDER BY type";
$type_result = mysqli_query($conn, $type_query);

if ($type_result) {
    while ($row = mysqli_fetch_assoc($type_result)) {
        $type_options[] = $row['type'];
    }
}

// ==================== GET ALL EQUIPMENT ====================
$equipment_list = [];
$equipment_query = "SELECT id, name, serial_number FROM equipment WHERE status = 'active' ORDER BY name";
$equipment_result = mysqli_query($conn, $equipment_query);

if ($equipment_result) {
    while ($row = mysqli_fetch_assoc($equipment_result)) {
        $equipment_list[] = $row;
    }
}

// ==================== PROCESS FORM SUBMISSIONS ====================
$error_message = '';
$success_message = '';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $user_id = $_SESSION['user_id'];
    
    // Update Request Status
    if (isset($_POST['update_status'])) {
        $new_status = isset($_POST['status']) ? trim($_POST['status']) : '';
        $status_notes = isset($_POST['status_notes']) ? trim($_POST['status_notes']) : '';
        
        if (!empty($new_status)) {
            // Update request status using prepared statement
            $update_query = "UPDATE maintenance_requests SET status = ?, updated_at = NOW() WHERE id = ?";
            $stmt = mysqli_prepare($conn, $update_query);
            mysqli_stmt_bind_param($stmt, "si", $new_status, $request_id);
            
            if (mysqli_stmt_execute($stmt)) {
                // Add to history if table exists
                if ($history_table_exists) {
                    $history_insert = "INSERT INTO maintenance_history (request_id, status, notes, created_by) VALUES (?, ?, ?, ?)";
                    $stmt2 = mysqli_prepare($conn, $history_insert);
                    mysqli_stmt_bind_param($stmt2, "issi", $request_id, $new_status, $status_notes, $user_id);
                    mysqli_stmt_execute($stmt2);
                    mysqli_stmt_close($stmt2);
                }
                
                // Add comment if table exists
                if ($comments_table_exists) {
                    $comment = "Status changed to: $new_status" . (!empty($status_notes) ? " - $status_notes" : "");
                    $comment_insert = "INSERT INTO maintenance_comments (request_id, user_id, comment) VALUES (?, ?, ?)";
                    $stmt3 = mysqli_prepare($conn, $comment_insert);
                    mysqli_stmt_bind_param($stmt3, "iis", $request_id, $user_id, $comment);
                    mysqli_stmt_execute($stmt3);
                    mysqli_stmt_close($stmt3);
                }
                
                $success_message = "Status updated successfully";
                // Refresh request data
                header("Location: maintenance_request.php?id=$request_id&success=" . urlencode($success_message));
                exit();
            } else {
                $error_message = "Failed to update status: " . mysqli_error($conn);
            }
            mysqli_stmt_close($stmt);
        }
    }
    
    // Assign Technician
    elseif (isset($_POST['assign_technician'])) {
        $assigned_to = isset($_POST['assigned_to']) ? intval($_POST['assigned_to']) : 0;
        $assignment_notes = isset($_POST['assignment_notes']) ? trim($_POST['assignment_notes']) : '';
        
        if ($assigned_to > 0) {
            // Update assignment using prepared statement
            $assign_query = "UPDATE maintenance_requests SET assigned_to = ?, updated_at = NOW() WHERE id = ?";
            $stmt = mysqli_prepare($conn, $assign_query);
            mysqli_stmt_bind_param($stmt, "ii", $assigned_to, $request_id);
            
            if (mysqli_stmt_execute($stmt)) {
                // Get technician name
                $tech_query = "SELECT full_name FROM users WHERE id = ?";
                $stmt2 = mysqli_prepare($conn, $tech_query);
                mysqli_stmt_bind_param($stmt2, "i", $assigned_to);
                mysqli_stmt_execute($stmt2);
                $tech_result = mysqli_stmt_get_result($stmt2);
                $tech_name = 'Unknown';
                
                if ($tech_result && $row = mysqli_fetch_assoc($tech_result)) {
                    $tech_name = $row['full_name'] ?: 'Technician';
                }
                mysqli_stmt_close($stmt2);
                
                // Add comment if table exists
                if ($comments_table_exists) {
                    $comment = "Assigned to: $tech_name" . (!empty($assignment_notes) ? " - $assignment_notes" : "");
                    $comment_insert = "INSERT INTO maintenance_comments (request_id, user_id, comment) VALUES (?, ?, ?)";
                    $stmt3 = mysqli_prepare($conn, $comment_insert);
                    mysqli_stmt_bind_param($stmt3, "iis", $request_id, $user_id, $comment);
                    mysqli_stmt_execute($stmt3);
                    mysqli_stmt_close($stmt3);
                }
                
                $success_message = "Technician assigned successfully";
                header("Location: maintenance_request.php?id=$request_id&success=" . urlencode($success_message));
                exit();
            } else {
                $error_message = "Failed to assign technician: " . mysqli_error($conn);
            }
            mysqli_stmt_close($stmt);
        }
    }
    
    // Add Comment
    elseif (isset($_POST['add_comment'])) {
        $comment = isset($_POST['comment']) ? trim($_POST['comment']) : '';
        
        if (!empty($comment)) {
            // Create comments table if it doesn't exist
            if (!$comments_table_exists) {
                $create_comments_table = "
                    CREATE TABLE IF NOT EXISTS maintenance_comments (
                        id INT(11) NOT NULL AUTO_INCREMENT,
                        request_id INT(11) NOT NULL,
                        user_id INT(11) NOT NULL,
                        comment TEXT NOT NULL,
                        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                        PRIMARY KEY (id),
                        FOREIGN KEY (request_id) REFERENCES maintenance_requests(id) ON DELETE CASCADE,
                        FOREIGN KEY (user_id) REFERENCES users(id)
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
                ";
                if (mysqli_query($conn, $create_comments_table)) {
                    $comments_table_exists = true;
                }
            }
            
            $comment_insert = "INSERT INTO maintenance_comments (request_id, user_id, comment) VALUES (?, ?, ?)";
            $stmt = mysqli_prepare($conn, $comment_insert);
            mysqli_stmt_bind_param($stmt, "iis", $request_id, $user_id, $comment);
            
            if (mysqli_stmt_execute($stmt)) {
                $success_message = "Comment added successfully";
                header("Location: maintenance_request.php?id=$request_id&success=" . urlencode($success_message));
                exit();
            } else {
                $error_message = "Failed to add comment: " . mysqli_error($conn);
            }
            mysqli_stmt_close($stmt);
        }
    }
    
    // Update Priority
    elseif (isset($_POST['update_priority'])) {
        $new_priority = isset($_POST['priority']) ? trim($_POST['priority']) : '';
        $priority_notes = isset($_POST['priority_notes']) ? trim($_POST['priority_notes']) : '';
        
        if (!empty($new_priority)) {
            $update_query = "UPDATE maintenance_requests SET priority = ?, updated_at = NOW() WHERE id = ?";
            $stmt = mysqli_prepare($conn, $update_query);
            mysqli_stmt_bind_param($stmt, "si", $new_priority, $request_id);
            
            if (mysqli_stmt_execute($stmt)) {
                // Add comment if table exists
                if ($comments_table_exists) {
                    $comment = "Priority changed to: $new_priority" . (!empty($priority_notes) ? " - $priority_notes" : "");
                    $comment_insert = "INSERT INTO maintenance_comments (request_id, user_id, comment) VALUES (?, ?, ?)";
                    $stmt2 = mysqli_prepare($conn, $comment_insert);
                    mysqli_stmt_bind_param($stmt2, "iis", $request_id, $user_id, $comment);
                    mysqli_stmt_execute($stmt2);
                    mysqli_stmt_close($stmt2);
                }
                
                $success_message = "Priority updated successfully";
                header("Location: maintenance_request.php?id=$request_id&success=" . urlencode($success_message));
                exit();
            } else {
                $error_message = "Failed to update priority: " . mysqli_error($conn);
            }
            mysqli_stmt_close($stmt);
        }
    }
    
    // Reschedule Request
    elseif (isset($_POST['reschedule'])) {
        $new_date = isset($_POST['new_scheduled_date']) ? trim($_POST['new_scheduled_date']) : '';
        $reschedule_reason = isset($_POST['reschedule_reason']) ? trim($_POST['reschedule_reason']) : '';
        $reschedule_notes = isset($_POST['reschedule_notes']) ? trim($_POST['reschedule_notes']) : '';
        
        if (!empty($new_date)) {
            $old_date = $request['scheduled_date'] ?? '';
            
            $update_query = "UPDATE maintenance_requests SET scheduled_date = ?, updated_at = NOW() WHERE id = ?";
            $stmt = mysqli_prepare($conn, $update_query);
            mysqli_stmt_bind_param($stmt, "si", $new_date, $request_id);
            
            if (mysqli_stmt_execute($stmt)) {
                // Add comment if table exists
                if ($comments_table_exists) {
                    $comment = "Rescheduled from $old_date to $new_date. Reason: $reschedule_reason" . 
                               (!empty($reschedule_notes) ? " - $reschedule_notes" : "");
                    $comment_insert = "INSERT INTO maintenance_comments (request_id, user_id, comment) VALUES (?, ?, ?)";
                    $stmt2 = mysqli_prepare($conn, $comment_insert);
                    mysqli_stmt_bind_param($stmt2, "iis", $request_id, $user_id, $comment);
                    mysqli_stmt_execute($stmt2);
                    mysqli_stmt_close($stmt2);
                }
                
                $success_message = "Request rescheduled successfully";
                header("Location: maintenance_request.php?id=$request_id&success=" . urlencode($success_message));
                exit();
            } else {
                $error_message = "Failed to reschedule: " . mysqli_error($conn);
            }
            mysqli_stmt_close($stmt);
        }
    }
    
    // Update Request Details
    elseif (isset($_POST['update_request'])) {
        $subject = isset($_POST['subject']) ? trim($_POST['subject']) : '';
        $description = isset($_POST['description']) ? trim($_POST['description']) : '';
        $equipment_id = isset($_POST['equipment_id']) ? intval($_POST['equipment_id']) : 0;
        $type = isset($_POST['type']) ? trim($_POST['type']) : '';
        
        if (!empty($subject) && !empty($description)) {
            $update_query = "UPDATE maintenance_requests SET 
                            subject = ?,
                            description = ?,
                            equipment_id = ?,
                            type = ?,
                            updated_at = NOW()
                            WHERE id = ?";
            
            $stmt = mysqli_prepare($conn, $update_query);
            mysqli_stmt_bind_param($stmt, "ssisi", $subject, $description, $equipment_id, $type, $request_id);
            
            if (mysqli_stmt_execute($stmt)) {
                // Add comment if table exists
                if ($comments_table_exists) {
                    $comment = "Request details updated";
                    $comment_insert = "INSERT INTO maintenance_comments (request_id, user_id, comment) VALUES (?, ?, ?)";
                    $stmt2 = mysqli_prepare($conn, $comment_insert);
                    mysqli_stmt_bind_param($stmt2, "iis", $request_id, $user_id, $comment);
                    mysqli_stmt_execute($stmt2);
                    mysqli_stmt_close($stmt2);
                }
                
                $success_message = "Request details updated successfully";
                header("Location: maintenance_request.php?id=$request_id&success=" . urlencode($success_message));
                exit();
            } else {
                $error_message = "Failed to update request: " . mysqli_error($conn);
            }
            mysqli_stmt_close($stmt);
        }
    }
}

// Store messages in session for display after redirect
if (!empty($error_message)) {
    $_SESSION['error_message'] = $error_message;
}
if (!empty($success_message)) {
    $_SESSION['success_message'] = $success_message;
}

mysqli_close($conn);

// Helper function
function getFileIcon($extension) {
    $extension = strtolower($extension);
    
    switch ($extension) {
        case 'pdf':
            return 'fas fa-file-pdf';
        case 'doc':
        case 'docx':
            return 'fas fa-file-word';
        case 'xls':
        case 'xlsx':
            return 'fas fa-file-excel';
        case 'jpg':
        case 'jpeg':
        case 'png':
        case 'gif':
        case 'bmp':
        case 'svg':
        case 'webp':
            return 'fas fa-file-image';
        case 'txt':
        case 'log':
            return 'fas fa-file-alt';
        case 'zip':
        case 'rar':
        case '7z':
        case 'tar':
        case 'gz':
            return 'fas fa-file-archive';
        case 'mp4':
        case 'avi':
        case 'mov':
        case 'wmv':
        case 'mkv':
            return 'fas fa-file-video';
        case 'mp3':
        case 'wav':
        case 'ogg':
            return 'fas fa-file-audio';
        default:
            return 'fas fa-file';
    }
}

// Function to safely display data
function safe_display($data, $default = '') {
    return !empty($data) ? htmlspecialchars($data, ENT_QUOTES, 'UTF-8') : $default;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Maintenance Request #<?php echo safe_display($request['request_number']); ?> - GearGuard</title>
    <link rel="stylesheet" href="../css/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --primary-color: #4361ee;
            --secondary-color: #3a0ca3;
            --success-color: #4cc9f0;
            --warning-color: #f72585;
            --danger-color: #7209b7;
            --light-color: #f8f9fa;
            --dark-color: #212529;
            --gray-color: #6c757d;
            --border-color: #dee2e6;
            --card-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
            --hover-shadow: 0 6px 12px rgba(0, 0, 0, 0.15);
        }
        
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #f5f7fa 0%, #c3cfe2 100%);
            min-height: 100vh;
            color: var(--dark-color);
        }
        
        .container {
            max-width: 1400px;
            margin: 0 auto;
            padding: 20px;
        }
        
        .back-btn {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            color: var(--primary-color);
            text-decoration: none;
            font-weight: 500;
            padding: 10px 0;
            margin-bottom: 20px;
            transition: all 0.3s;
        }
        
        .back-btn:hover {
            color: var(--secondary-color);
            transform: translateX(-5px);
        }
        
        .message-container {
            margin-bottom: 25px;
        }
        
        .success-message {
            background: linear-gradient(135deg, #d4edda 0%, #c3e6cb 100%);
            color: #155724;
            padding: 15px 20px;
            border-radius: 10px;
            margin-bottom: 10px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            border-left: 5px solid #28a745;
            animation: slideIn 0.5s ease;
        }
        
        .error-message {
            background: linear-gradient(135deg, #f8d7da 0%, #f5c6cb 100%);
            color: #721c24;
            padding: 15px 20px;
            border-radius: 10px;
            margin-bottom: 10px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            border-left: 5px solid #dc3545;
            animation: slideIn 0.5s ease;
        }
        
        @keyframes slideIn {
            from {
                transform: translateY(-20px);
                opacity: 0;
            }
            to {
                transform: translateY(0);
                opacity: 1;
            }
        }
        
        .message-container button {
            background: none;
            border: none;
            color: inherit;
            cursor: pointer;
            font-size: 1.5rem;
            line-height: 1;
            padding: 0 5px;
            transition: all 0.3s;
        }
        
        .message-container button:hover {
            opacity: 0.8;
            transform: scale(1.2);
        }
        
        .header-actions {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 30px;
            background: white;
            padding: 25px 30px;
            border-radius: 15px;
            box-shadow: var(--card-shadow);
            border: 1px solid var(--border-color);
            flex-wrap: wrap;
            gap: 20px;
        }
        
        .header-actions h1 {
            margin: 0;
            font-size: 1.8rem;
            color: var(--dark-color);
            font-weight: 600;
        }
        
        .header-actions h1 span {
            color: var(--primary-color);
            font-weight: 700;
        }
        
        .header-actions h1 small {
            display: block;
            font-size: 1rem;
            color: var(--gray-color);
            font-weight: 400;
            margin-top: 8px;
        }
        
        .action-buttons {
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
        }
        
        .btn {
            padding: 12px 20px;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-size: 0.95rem;
            font-weight: 500;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: all 0.3s;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .btn-sm {
            padding: 8px 16px;
            font-size: 0.85rem;
        }
        
        .btn-primary {
            background: linear-gradient(135deg, var(--primary-color) 0%, var(--secondary-color) 100%);
            color: white;
            box-shadow: 0 4px 6px rgba(67, 97, 238, 0.3);
        }
        
        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 12px rgba(67, 97, 238, 0.4);
        }
        
        .btn-success {
            background: linear-gradient(135deg, #28a745 0%, #20c997 100%);
            color: white;
            box-shadow: 0 4px 6px rgba(40, 167, 69, 0.3);
        }
        
        .btn-success:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 12px rgba(40, 167, 69, 0.4);
        }
        
        .btn-warning {
            background: linear-gradient(135deg, #ffc107 0%, #fd7e14 100%);
            color: white;
            box-shadow: 0 4px 6px rgba(255, 193, 7, 0.3);
        }
        
        .btn-warning:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 12px rgba(255, 193, 7, 0.4);
        }
        
        .btn-danger {
            background: linear-gradient(135deg, #dc3545 0%, #c82333 100%);
            color: white;
            box-shadow: 0 4px 6px rgba(220, 53, 69, 0.3);
        }
        
        .btn-danger:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 12px rgba(220, 53, 69, 0.4);
        }
        
        .btn-secondary {
            background: linear-gradient(135deg, var(--gray-color) 0%, #495057 100%);
            color: white;
            box-shadow: 0 4px 6px rgba(108, 117, 125, 0.3);
        }
        
        .btn-secondary:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 12px rgba(108, 117, 125, 0.4);
        }
        
        .main-content {
            display: grid;
            grid-template-columns: 1fr 400px;
            gap: 25px;
        }
        
        .request-details {
            display: flex;
            flex-direction: column;
            gap: 25px;
        }
        
        .card {
            background: white;
            border-radius: 15px;
            padding: 30px;
            box-shadow: var(--card-shadow);
            transition: all 0.3s;
            border: 1px solid var(--border-color);
            position: relative;
            overflow: hidden;
        }
        
        .card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 5px;
            height: 100%;
            background: linear-gradient(180deg, var(--primary-color) 0%, var(--secondary-color) 100%);
        }
        
        .card:hover {
            transform: translateY(-5px);
            box-shadow: var(--hover-shadow);
        }
        
        .card h2 {
            margin: 0 0 25px 0;
            color: var(--dark-color);
            font-size: 1.4rem;
            font-weight: 600;
            padding-bottom: 15px;
            border-bottom: 2px solid var(--light-color);
            position: relative;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .card h2::after {
            content: '';
            position: absolute;
            bottom: -2px;
            left: 0;
            width: 60px;
            height: 2px;
            background: linear-gradient(90deg, var(--primary-color) 0%, var(--secondary-color) 100%);
        }
        
        .info-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
        }
        
        .info-item {
            display: flex;
            flex-direction: column;
            gap: 8px;
        }
        
        .info-label {
            font-size: 0.85rem;
            color: var(--gray-color);
            font-weight: 500;
            text-transform: uppercase;
            letter-spacing: 1px;
        }
        
        .info-value {
            font-size: 1.05rem;
            color: var(--dark-color);
            font-weight: 500;
            display: flex;
            align-items: center;
            gap: 8px;
            flex-wrap: wrap;
        }
        
        .status-badge {
            display: inline-block;
            padding: 8px 20px;
            border-radius: 25px;
            font-size: 0.85rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 1px;
            border: 2px solid transparent;
            transition: all 0.3s;
        }
        
        .status-new { 
            background: linear-gradient(135deg, #e3f2fd 0%, #bbdefb 100%);
            color: #1565c0;
            border-color: #bbdefb;
        }
        .status-scheduled { 
            background: linear-gradient(135deg, #fff3e0 0%, #ffe0b2 100%);
            color: #ef6c00;
            border-color: #ffe0b2;
        }
        .status-assigned { 
            background: linear-gradient(135deg, #f3e5f5 0%, #e1bee7 100%);
            color: #7b1fa2;
            border-color: #e1bee7;
        }
        .status-in_progress,
        .status-in-progress { 
            background: linear-gradient(135deg, #e8f5e8 0%, #c8e6c9 100%);
            color: #2e7d32;
            border-color: #c8e6c9;
        }
        .status-completed { 
            background: linear-gradient(135deg, #e8f5e9 0%, #a5d6a7 100%);
            color: #1b5e20;
            border-color: #a5d6a7;
        }
        .status-repaired { 
            background: linear-gradient(135deg, #c8e6c9 0%, #81c784 100%);
            color: #1b5e20;
            border-color: #81c784;
        }
        .status-cancelled { 
            background: linear-gradient(135deg, #ffebee 0%, #ffcdd2 100%);
            color: #c62828;
            border-color: #ffcdd2;
        }
        .status-pending { 
            background: linear-gradient(135deg, #fff3e0 0%, #ffcc80 100%);
            color: #ef6c00;
            border-color: #ffcc80;
        }
        
        .priority-badge {
            display: inline-block;
            padding: 8px 20px;
            border-radius: 25px;
            font-size: 0.85rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 1px;
            border: 2px solid transparent;
            transition: all 0.3s;
        }
        
        .priority-critical { 
            background: linear-gradient(135deg, #ffebee 0%, #ffcdd2 100%);
            color: #c62828;
            border-color: #ffcdd2;
        }
        .priority-high { 
            background: linear-gradient(135deg, #fff3e0 0%, #ffe0b2 100%);
            color: #ef6c00;
            border-color: #ffe0b2;
        }
        .priority-medium { 
            background: linear-gradient(135deg, #e3f2fd 0%, #bbdefb 100%);
            color: #1565c0;
            border-color: #bbdefb;
        }
        .priority-low { 
            background: linear-gradient(135deg, #e8f5e9 0%, #c8e6c9 100%);
            color: #2e7d32;
            border-color: #c8e6c9;
        }
        
        .description-box {
            background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
            padding: 25px;
            border-radius: 10px;
            border-left: 5px solid var(--primary-color);
            margin-top: 15px;
            font-size: 1rem;
            line-height: 1.6;
            color: var(--dark-color);
            white-space: pre-wrap;
            word-wrap: break-word;
        }
        
        .sidebar {
            display: flex;
            flex-direction: column;
            gap: 25px;
        }
        
        .history-timeline {
            display: flex;
            flex-direction: column;
            gap: 20px;
            max-height: 400px;
            overflow-y: auto;
            padding-right: 10px;
        }
        
        .history-timeline::-webkit-scrollbar {
            width: 6px;
        }
        
        .history-timeline::-webkit-scrollbar-track {
            background: #f1f1f1;
            border-radius: 10px;
        }
        
        .history-timeline::-webkit-scrollbar-thumb {
            background: var(--primary-color);
            border-radius: 10px;
        }
        
        .timeline-item {
            display: flex;
            gap: 20px;
            padding: 20px;
            background: white;
            border-radius: 10px;
            border-left: 5px solid var(--primary-color);
            box-shadow: var(--card-shadow);
            transition: all 0.3s;
            position: relative;
        }
        
        .timeline-item::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 100%;
            background: linear-gradient(135deg, transparent 0%, rgba(67, 97, 238, 0.05) 100%);
            border-radius: 10px;
            z-index: 1;
        }
        
        .timeline-item > * {
            position: relative;
            z-index: 2;
        }
        
        .timeline-item:hover {
            transform: translateX(5px);
            box-shadow: var(--hover-shadow);
        }
        
        .timeline-icon {
            width: 50px;
            height: 50px;
            background: linear-gradient(135deg, var(--primary-color) 0%, var(--secondary-color) 100%);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 1.3rem;
            flex-shrink: 0;
            box-shadow: 0 4px 6px rgba(67, 97, 238, 0.3);
        }
        
        .timeline-content {
            flex: 1;
        }
        
        .timeline-date {
            font-size: 0.85rem;
            color: var(--gray-color);
            margin-bottom: 8px;
            font-weight: 500;
            display: flex;
            align-items: center;
            gap: 5px;
        }
        
        .timeline-status {
            font-weight: 600;
            color: var(--dark-color);
            margin-bottom: 8px;
            font-size: 1.1rem;
        }
        
        .timeline-notes {
            font-size: 0.95rem;
            color: var(--gray-color);
            line-height: 1.5;
            display: flex;
            align-items: flex-start;
            gap: 5px;
        }
        
        .comments-section {
            max-height: 400px;
            overflow-y: auto;
            padding-right: 15px;
        }
        
        .comments-section::-webkit-scrollbar {
            width: 6px;
        }
        
        .comments-section::-webkit-scrollbar-track {
            background: #f1f1f1;
            border-radius: 10px;
        }
        
        .comments-section::-webkit-scrollbar-thumb {
            background: var(--primary-color);
            border-radius: 10px;
        }
        
        .comment {
            background: white;
            padding: 20px;
            border-radius: 10px;
            margin-bottom: 15px;
            border-left: 4px solid var(--primary-color);
            box-shadow: var(--card-shadow);
            transition: all 0.3s;
        }
        
        .comment:hover {
            transform: translateX(5px);
            box-shadow: var(--hover-shadow);
        }
        
        .comment-header {
            display: flex;
            justify-content: space-between;
            margin-bottom: 12px;
            font-size: 0.9rem;
            flex-wrap: wrap;
            gap: 10px;
        }
        
        .comment-user {
            font-weight: 600;
            color: var(--dark-color);
            display: flex;
            align-items: center;
            gap: 5px;
        }
        
        .comment-user small {
            color: var(--gray-color);
            font-weight: 400;
        }
        
        .comment-date {
            color: var(--gray-color);
            font-weight: 500;
            display: flex;
            align-items: center;
            gap: 5px;
        }
        
        .comment-content {
            color: var(--dark-color);
            line-height: 1.6;
            font-size: 0.95rem;
            white-space: pre-wrap;
            word-wrap: break-word;
        }
        
        .form-group {
            margin-bottom: 20px;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: var(--dark-color);
            font-size: 0.95rem;
        }
        
        .form-group select,
        .form-group input,
        .form-group textarea {
            width: 100%;
            padding: 12px 15px;
            border: 2px solid var(--border-color);
            border-radius: 8px;
            font-size: 0.95rem;
            transition: all 0.3s;
            background: white;
            color: var(--dark-color);
            font-family: inherit;
        }
        
        .form-group select:focus,
        .form-group input:focus,
        .form-group textarea:focus {
            border-color: var(--primary-color);
            outline: none;
            box-shadow: 0 0 0 3px rgba(67, 97, 238, 0.1);
        }
        
        .form-group textarea {
            min-height: 120px;
            resize: vertical;
        }
        
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.5);
            backdrop-filter: blur(5px);
            z-index: 1000;
            align-items: center;
            justify-content: center;
            animation: fadeIn 0.3s ease;
            padding: 20px;
        }
        
        @keyframes fadeIn {
            from {
                opacity: 0;
            }
            to {
                opacity: 1;
            }
        }
        
        .modal-content {
            background: white;
            padding: 40px;
            border-radius: 20px;
            max-width: 500px;
            width: 100%;
            max-height: 80vh;
            overflow-y: auto;
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.2);
            animation: slideUp 0.3s ease;
        }
        
        @keyframes slideUp {
            from {
                transform: translateY(50px);
                opacity: 0;
            }
            to {
                transform: translateY(0);
                opacity: 1;
            }
        }
        
        .modal-content h3 {
            margin: 0 0 25px 0;
            color: var(--dark-color);
            font-size: 1.5rem;
            font-weight: 600;
            padding-bottom: 15px;
            border-bottom: 2px solid var(--light-color);
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .modal-actions {
            display: flex;
            gap: 12px;
            justify-content: flex-end;
            margin-top: 25px;
        }
        
        .attachments-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(180px, 1fr));
            gap: 20px;
            margin-top: 20px;
        }
        
        .attachment-item {
            background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
            padding: 25px 15px;
            border-radius: 15px;
            text-align: center;
            transition: all 0.3s;
            cursor: pointer;
            text-decoration: none;
            color: inherit;
            border: 2px solid transparent;
            position: relative;
            overflow: hidden;
        }
        
        .attachment-item::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: linear-gradient(90deg, var(--primary-color) 0%, var(--secondary-color) 100%);
        }
        
        .attachment-item:hover {
            transform: translateY(-5px);
            box-shadow: var(--hover-shadow);
            border-color: var(--primary-color);
        }
        
        .attachment-icon {
            font-size: 2.5rem;
            color: var(--primary-color);
            margin-bottom: 15px;
        }
        
        .attachment-name {
            font-size: 0.9rem;
            color: var(--dark-color);
            word-break: break-all;
            font-weight: 500;
        }
        
        .empty-state {
            text-align: center;
            padding: 50px 20px;
            color: var(--gray-color);
        }
        
        .empty-state i {
            font-size: 3.5rem;
            color: #dee2e6;
            margin-bottom: 20px;
        }
        
        .empty-state p {
            font-size: 1rem;
            margin-bottom: 15px;
        }
        
        .table-warning {
            background: linear-gradient(135deg, #fff3cd 0%, #ffeaa7 100%);
            color: #856404;
            padding: 15px 20px;
            border-radius: 10px;
            margin-bottom: 20px;
            border-left: 5px solid #ffc107;
            font-size: 0.9rem;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        @media (max-width: 1200px) {
            .main-content {
                grid-template-columns: 1fr;
            }
        }
        
        @media (max-width: 768px) {
            .container {
                padding: 15px;
            }
            
            .header-actions {
                flex-direction: column;
                gap: 20px;
                padding: 20px;
            }
            
            .action-buttons {
                width: 100%;
                justify-content: center;
            }
            
            .card {
                padding: 20px;
            }
            
            .info-grid {
                grid-template-columns: 1fr;
            }
            
            .modal-content {
                padding: 25px;
                width: 95%;
            }
            
            .modal-actions {
                flex-direction: column;
            }
            
            .modal-actions .btn {
                width: 100%;
                justify-content: center;
            }
        }
        
        @media (max-width: 576px) {
            .btn {
                width: 100%;
                justify-content: center;
            }
            
            .attachments-grid {
                grid-template-columns: 1fr;
            }
            
            .comment-header {
                flex-direction: column;
                align-items: flex-start;
            }
        }
        
        /* Loading animation */
        .loading {
            display: inline-block;
            width: 20px;
            height: 20px;
            border: 3px solid rgba(67, 97, 238, 0.3);
            border-radius: 50%;
            border-top-color: var(--primary-color);
            animation: spin 1s ease-in-out infinite;
        }
        
        @keyframes spin {
            to {
                transform: rotate(360deg);
            }
        }
        
        /* Tooltip */
        [data-tooltip] {
            position: relative;
            cursor: help;
        }
        
        [data-tooltip]:hover::after {
            content: attr(data-tooltip);
            position: absolute;
            bottom: 100%;
            left: 50%;
            transform: translateX(-50%);
            background: var(--dark-color);
            color: white;
            padding: 8px 12px;
            border-radius: 6px;
            font-size: 0.85rem;
            white-space: nowrap;
            z-index: 1000;
            margin-bottom: 8px;
        }
        
        [data-tooltip]:hover::before {
            content: '';
            position: absolute;
            bottom: 100%;
            left: 50%;
            transform: translateX(-50%);
            border: 6px solid transparent;
            border-top-color: var(--dark-color);
            margin-bottom: -6px;
        }
        
        .required {
            color: #dc3545;
        }
        
        .readonly-field {
            background-color: #f8f9fa;
            cursor: not-allowed;
        }
    </style>
</head>
<body>
    <?php include '../includes/header.php'; ?>
    
    <div class="container">
        <a href="calendar.php" class="back-btn">
            <i class="fas fa-arrow-left"></i> Back to Calendar
        </a>
        
        <div class="message-container">
            <?php 
            // Display success message from URL parameter
            if (isset($_GET['success'])): 
            ?>
                <div class="success-message">
                    <span><i class="fas fa-check-circle"></i> <?php echo safe_display($_GET['success']); ?></span>
                    <button onclick="this.parentElement.style.display='none'">&times;</button>
                </div>
            <?php endif; ?>
            
            <?php 
            // Display error message from URL parameter
            if (isset($_GET['error'])): 
            ?>
                <div class="error-message">
                    <span><i class="fas fa-exclamation-circle"></i> <?php echo safe_display($_GET['error']); ?></span>
                    <button onclick="this.parentElement.style.display='none'">&times;</button>
                </div>
            <?php endif; ?>
            
            <?php 
            // Display session messages
            if (isset($_SESSION['success_message'])): 
            ?>
                <div class="success-message">
                    <span><i class="fas fa-check-circle"></i> <?php echo safe_display($_SESSION['success_message']); ?></span>
                    <button onclick="this.parentElement.style.display='none'">&times;</button>
                </div>
                <?php unset($_SESSION['success_message']); ?>
            <?php endif; ?>
            
            <?php 
            if (isset($_SESSION['error_message'])): 
            ?>
                <div class="error-message">
                    <span><i class="fas fa-exclamation-circle"></i> <?php echo safe_display($_SESSION['error_message']); ?></span>
                    <button onclick="this.parentElement.style.display='none'">&times;</button>
                </div>
                <?php unset($_SESSION['error_message']); ?>
            <?php endif; ?>
        </div>
        
        <div class="header-actions">
            <div>
                <h1>
                    Maintenance Request 
                    <span>#<?php echo safe_display($request['request_number']); ?></span>
                    <small><?php echo safe_display($request['subject']); ?></small>
                </h1>
            </div>
            
            <div class="action-buttons">
                <button class="btn btn-primary" onclick="showModal('statusModal')">
                    <i class="fas fa-sync-alt"></i> Update Status
                </button>
                <button class="btn btn-warning" onclick="showModal('assignModal')">
                    <i class="fas fa-user-tie"></i> Assign
                </button>
                <button class="btn btn-secondary" onclick="showModal('priorityModal')">
                    <i class="fas fa-flag"></i> Priority
                </button>
                <button class="btn" onclick="showModal('rescheduleModal')" style="background: linear-gradient(135deg, #6c757d 0%, #495057 100%); color: white;">
                    <i class="fas fa-calendar-alt"></i> Reschedule
                </button>
                <button class="btn btn-secondary" onclick="showModal('editRequestModal')">
                    <i class="fas fa-edit"></i> Edit
                </button>
            </div>
        </div>
        
        <div class="main-content">
            <!-- Left Column: Request Details -->
            <div class="request-details">
                <!-- Request Information Card -->
                <div class="card">
                    <h2><i class="fas fa-clipboard-list"></i> Request Information</h2>
                    <div class="info-grid">
                        <div class="info-item">
                            <span class="info-label">Status</span>
                            <span class="status-badge status-<?php echo strtolower(str_replace([' ', '-'], '_', $request['status'])); ?>">
                                <?php echo safe_display($request['status']); ?>
                            </span>
                        </div>
                        
                        <div class="info-item">
                            <span class="info-label">Priority</span>
                            <span class="priority-badge priority-<?php echo strtolower($request['priority'] ?? 'medium'); ?>">
                                <?php echo safe_display($request['priority'] ?? 'Not Set'); ?>
                            </span>
                        </div>
                        
                        <div class="info-item">
                            <span class="info-label">Type</span>
                            <span class="info-value"><?php echo safe_display($request['type']); ?></span>
                        </div>
                        
                        <div class="info-item">
                            <span class="info-label">Scheduled Date</span>
                            <span class="info-value">
                                <i class="far fa-calendar-alt"></i> 
                                <?php 
                                if (!empty($request['scheduled_date']) && $request['scheduled_date'] != '0000-00-00') {
                                    echo date('F j, Y', strtotime($request['scheduled_date']));
                                } else {
                                    echo 'Not Scheduled';
                                }
                                ?>
                            </span>
                        </div>
                        
                        <div class="info-item">
                            <span class="info-label">Created By</span>
                            <span class="info-value">
                                <i class="fas fa-user"></i> 
                                <?php 
                                $creator = $request['created_by_name'] ?? $request['created_by_username'] ?? 'Unknown';
                                echo safe_display($creator);
                                ?>
                            </span>
                        </div>
                        
                        <div class="info-item">
                            <span class="info-label">Created On</span>
                            <span class="info-value">
                                <i class="far fa-clock"></i> 
                                <?php 
                                echo !empty($request['created_at']) ? date('F j, Y g:i A', strtotime($request['created_at'])) : 'Unknown';
                                ?>
                            </span>
                        </div>
                        
                        <div class="info-item">
                            <span class="info-label">Last Updated</span>
                            <span class="info-value">
                                <i class="fas fa-history"></i> 
                                <?php 
                                echo !empty($request['updated_at']) ? date('F j, Y g:i A', strtotime($request['updated_at'])) : 'Never';
                                ?>
                            </span>
                        </div>
                    </div>
                </div>
                
                <!-- Equipment Information Card -->
                <div class="card">
                    <h2><i class="fas fa-cogs"></i> Equipment Information</h2>
                    <div class="info-grid">
                        <div class="info-item">
                            <span class="info-label">Equipment Name</span>
                            <span class="info-value">
                                <i class="fas fa-toolbox"></i> <?php echo safe_display($request['equipment_name'] ?? 'Not Specified'); ?>
                            </span>
                        </div>
                        
                        <?php if (!empty($request['serial_number'])): ?>
                        <div class="info-item">
                            <span class="info-label">Serial Number</span>
                            <span class="info-value">
                                <i class="fas fa-barcode"></i> <?php echo safe_display($request['serial_number']); ?>
                            </span>
                        </div>
                        <?php endif; ?>
                        
                        <?php if (!empty($request['equipment_category'])): ?>
                        <div class="info-item">
                            <span class="info-label">Category</span>
                            <span class="info-value">
                                <i class="fas fa-tags"></i> <?php echo safe_display($request['equipment_category']); ?>
                            </span>
                        </div>
                        <?php endif; ?>
                        
                        <?php if (!empty($request['department_name'])): ?>
                        <div class="info-item">
                            <span class="info-label">Department</span>
                            <span class="info-value">
                                <i class="fas fa-building"></i> <?php echo safe_display($request['department_name']); ?>
                            </span>
                        </div>
                        <?php endif; ?>
                        
                        <div class="info-item">
                            <span class="info-label">Location</span>
                            <span class="info-value">
                                <i class="fas fa-map-marker-alt"></i> <?php echo safe_display($request['equipment_location'] ?? 'Not Specified'); ?>
                            </span>
                        </div>
                        
                        <div class="info-item">
                            <span class="info-label">Equipment Status</span>
                            <span class="info-value">
                                <i class="fas fa-power-off"></i> <?php echo safe_display($request['equipment_status'] ?? 'Unknown'); ?>
                            </span>
                        </div>
                    </div>
                    
                    <?php if (!empty($request['equipment_notes'])): ?>
                        <div style="margin-top: 20px;">
                            <span class="info-label">Equipment Notes</span>
                            <div class="description-box">
                                <i class="fas fa-sticky-note"></i> <?php echo nl2br(safe_display($request['equipment_notes'])); ?>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
                
                <!-- Description Card -->
                <div class="card">
                    <h2><i class="fas fa-align-left"></i> Description</h2>
                    <div class="description-box">
                        <?php echo nl2br(safe_display($request['description'])); ?>
                    </div>
                </div>
                
                <!-- Assigned Technician Card -->
                <?php if (!empty($request['assigned_to'])): ?>
                <div class="card">
                    <h2><i class="fas fa-user-tie"></i> Assigned Technician</h2>
                    <div class="info-grid">
                        <div class="info-item">
                            <span class="info-label">Name</span>
                            <span class="info-value">
                                <i class="fas fa-user"></i> 
                                <?php 
                                $technician = $request['assigned_to_name'] ?? $request['assigned_to_username'] ?? 'Unknown';
                                echo safe_display($technician);
                                ?>
                            </span>
                        </div>
                        
                        <?php if (!empty($request['assigned_to_role'])): ?>
                        <div class="info-item">
                            <span class="info-label">Role</span>
                            <span class="info-value">
                                <i class="fas fa-user-tag"></i> <?php echo safe_display($request['assigned_to_role']); ?>
                            </span>
                        </div>
                        <?php endif; ?>
                        
                        <?php if (!empty($request['assigned_to_email'])): ?>
                        <div class="info-item">
                            <span class="info-label">Email</span>
                            <span class="info-value">
                                <i class="fas fa-envelope"></i> <?php echo safe_display($request['assigned_to_email']); ?>
                            </span>
                        </div>
                        <?php endif; ?>
                    </div>
                    
                    <?php if (!empty($request['assigned_to_email'])): ?>
                        <div class="action-buttons" style="margin-top: 20px;">
                            <a href="mailto:<?php echo safe_display($request['assigned_to_email']); ?>" 
                               class="btn btn-sm btn-primary">
                                <i class="fas fa-envelope"></i> Send Email
                            </a>
                        </div>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
                
                <!-- Comments Section -->
                <div class="card">
                    <h2><i class="fas fa-comments"></i> Comments & Activity</h2>
                    <?php if (!$comments_table_exists): ?>
                        <div class="table-warning">
                            <i class="fas fa-exclamation-triangle"></i>
                            Comments table not found. Comments will be stored once you add your first comment.
                        </div>
                    <?php endif; ?>
                    
                    <div class="comments-section">
                        <?php if (!empty($comments)): ?>
                            <?php foreach ($comments as $comment): ?>
                                <div class="comment">
                                    <div class="comment-header">
                                        <span class="comment-user">
                                            <i class="fas fa-user-circle"></i> 
                                            <?php echo safe_display($comment['user_name'] ?? $comment['username'] ?? 'Unknown'); ?>
                                            <?php if (!empty($comment['role'])): ?>
                                                <small>(<?php echo safe_display($comment['role']); ?>)</small>
                                            <?php endif; ?>
                                        </span>
                                        <span class="comment-date">
                                            <i class="far fa-clock"></i> 
                                            <?php 
                                            echo !empty($comment['created_at']) ? date('M j, Y g:i A', strtotime($comment['created_at'])) : 'Unknown';
                                            ?>
                                        </span>
                                    </div>
                                    <div class="comment-content">
                                        <?php echo nl2br(safe_display($comment['comment'])); ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div class="empty-state">
                                <i class="fas fa-comments"></i>
                                <p>No comments yet</p>
                            </div>
                        <?php endif; ?>
                    </div>
                    
                    <form method="POST" action="">
                        <div class="form-group" style="margin-top: 20px;">
                            <label><i class="fas fa-edit"></i> Add Comment <span class="required">*</span></label>
                            <textarea name="comment" placeholder="Add your comment here..." required minlength="1" maxlength="1000"></textarea>
                        </div>
                        <div class="action-buttons">
                            <button type="submit" name="add_comment" class="btn btn-primary">
                                <i class="fas fa-paper-plane"></i> Post Comment
                            </button>
                        </div>
                    </form>
                </div>
                
                <!-- Attachments Section -->
                <?php if ($attachments_table_exists): ?>
                <div class="card">
                    <h2><i class="fas fa-paperclip"></i> Attachments</h2>
                    <?php if (!empty($attachments)): ?>
                        <div class="attachments-grid">
                            <?php foreach ($attachments as $attachment): 
                                $extension = pathinfo($attachment['file_name'], PATHINFO_EXTENSION);
                                $icon = getFileIcon($extension);
                            ?>
                                <a href="../uploads/<?php echo safe_display($attachment['file_path']); ?>" 
                                   target="_blank" 
                                   class="attachment-item"
                                   title="<?php echo safe_display($attachment['file_name']); ?>">
                                    <div class="attachment-icon">
                                        <i class="<?php echo $icon; ?>"></i>
                                    </div>
                                    <div class="attachment-name">
                                        <?php echo safe_display($attachment['file_name']); ?>
                                    </div>
                                </a>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <div class="empty-state">
                            <i class="fas fa-paperclip"></i>
                            <p>No attachments</p>
                        </div>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
            </div>
            
            <!-- Right Column: Sidebar -->
            <div class="sidebar">
                <!-- Status History -->
                <div class="card">
                    <h2><i class="fas fa-history"></i> Status History</h2>
                    <?php if (!$history_table_exists): ?>
                        <div class="table-warning">
                            <i class="fas fa-exclamation-triangle"></i>
                            History table not found. Status changes will not be tracked until table is created.
                        </div>
                    <?php endif; ?>
                    
                    <div class="history-timeline">
                        <?php foreach ($status_history as $history): ?>
                            <div class="timeline-item">
                                <div class="timeline-icon">
                                    <i class="fas fa-history"></i>
                                </div>
                                <div class="timeline-content">
                                    <div class="timeline-date">
                                        <i class="far fa-clock"></i> 
                                        <?php 
                                        echo !empty($history['created_at']) ? date('M j, Y g:i A', strtotime($history['created_at'])) : 'Unknown';
                                        ?>
                                    </div>
                                    <div class="timeline-status">
                                        <?php echo safe_display($history['status'] ?? 'Unknown'); ?>
                                    </div>
                                    <?php if (isset($history['notes']) && $history['notes']): ?>
                                        <div class="timeline-notes">
                                            <i class="fas fa-sticky-note"></i> <?php echo safe_display($history['notes']); ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                
                <!-- Quick Actions -->
                <div class="card">
                    <h2><i class="fas fa-bolt"></i> Quick Actions</h2>
                    <div class="action-buttons">
                        <a href="print_request.php?id=<?php echo $request_id; ?>" 
                           target="_blank" 
                           class="btn btn-secondary">
                            <i class="fas fa-print"></i> Print
                        </a>
                        <a href="clone_request.php?id=<?php echo $request_id; ?>" 
                           class="btn">
                            <i class="fas fa-copy"></i> Clone
                        </a>
                        <button class="btn btn-danger" onclick="showModal('deleteModal')">
                            <i class="fas fa-trash"></i> Delete
                        </button>
                    </div>
                </div>
                
                <!-- Related Requests -->
                <div class="card">
                    <h2><i class="fas fa-link"></i> Related Requests</h2>
                    <?php if (!empty($request['equipment_id'])): ?>
                        <div style="margin-top: 10px;">
                            <p style="color: var(--gray-color); font-size: 0.9rem; margin-bottom: 15px;">
                                <i class="fas fa-info-circle"></i> Other maintenance requests for this equipment:
                            </p>
                            <a href="calendar.php?filter_equipment=<?php echo $request['equipment_id']; ?>" 
                               class="btn btn-primary" style="width: 100%; text-align: center;">
                                <i class="fas fa-list"></i> View All Requests
                            </a>
                        </div>
                    <?php else: ?>
                        <div class="empty-state">
                            <i class="fas fa-link"></i>
                            <p>Equipment not specified</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Modals -->
    <!-- Status Update Modal -->
    <div id="statusModal" class="modal">
        <div class="modal-content">
            <h3><i class="fas fa-sync-alt"></i> Update Status</h3>
            <form method="POST" action="">
                <div class="form-group">
                    <label>Status <span class="required">*</span></label>
                    <select name="status" required>
                        <option value="">Select Status</option>
                        <?php foreach ($status_options as $option): ?>
                            <option value="<?php echo safe_display($option); ?>" <?php echo ($request['status'] ?? '') == $option ? 'selected' : ''; ?>>
                                <?php echo safe_display($option); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Notes (Optional)</label>
                    <textarea name="status_notes" placeholder="Add notes about this status change..." maxlength="500"></textarea>
                </div>
                <div class="modal-actions">
                    <button type="button" class="btn btn-secondary" onclick="hideModal('statusModal')">Cancel</button>
                    <button type="submit" name="update_status" class="btn btn-primary">Update Status</button>
                </div>
            </form>
        </div>
    </div>
    
    <!-- Assign Technician Modal -->
    <div id="assignModal" class="modal">
        <div class="modal-content">
            <h3><i class="fas fa-user-tie"></i> Assign Technician</h3>
            <form method="POST" action="">
                <div class="form-group">
                    <label>Assign To <span class="required">*</span></label>
                    <select name="assigned_to" required>
                        <option value="">Select Technician</option>
                        <?php foreach ($users as $user): 
                            if (strtolower($user['role']) == 'technician' || strtolower($user['role']) == 'admin'): ?>
                            <option value="<?php echo $user['id']; ?>" <?php echo ($request['assigned_to'] ?? 0) == $user['id'] ? 'selected' : ''; ?>>
                                <?php echo safe_display($user['full_name'] ?? $user['username']) . " (" . safe_display($user['role']) . ")"; ?>
                            </option>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Assignment Notes (Optional)</label>
                    <textarea name="assignment_notes" placeholder="Add notes about this assignment..." maxlength="500"></textarea>
                </div>
                <div class="modal-actions">
                    <button type="button" class="btn btn-secondary" onclick="hideModal('assignModal')">Cancel</button>
                    <button type="submit" name="assign_technician" class="btn btn-primary">Assign Technician</button>
                </div>
            </form>
        </div>
    </div>
    
    <!-- Update Priority Modal -->
    <div id="priorityModal" class="modal">
        <div class="modal-content">
            <h3><i class="fas fa-flag"></i> Update Priority</h3>
            <form method="POST" action="">
                <div class="form-group">
                    <label>Priority <span class="required">*</span></label>
                    <select name="priority" required>
                        <option value="">Select Priority</option>
                        <?php foreach ($priority_options as $option): ?>
                            <option value="<?php echo safe_display($option); ?>" <?php echo ($request['priority'] ?? '') == $option ? 'selected' : ''; ?>>
                                <?php echo safe_display($option); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Priority Notes (Optional)</label>
                    <textarea name="priority_notes" placeholder="Add notes about this priority change..." maxlength="500"></textarea>
                </div>
                <div class="modal-actions">
                    <button type="button" class="btn btn-secondary" onclick="hideModal('priorityModal')">Cancel</button>
                    <button type="submit" name="update_priority" class="btn btn-primary">Update Priority</button>
                </div>
            </form>
        </div>
    </div>
    
    <!-- Reschedule Modal -->
    <div id="rescheduleModal" class="modal">
        <div class="modal-content">
            <h3><i class="fas fa-calendar-alt"></i> Reschedule Request</h3>
            <form method="POST" action="">
                <div class="form-group">
                    <label>New Scheduled Date <span class="required">*</span></label>
                    <input type="date" name="new_scheduled_date" required 
                           value="<?php echo !empty($request['scheduled_date']) && $request['scheduled_date'] != '0000-00-00' ? $request['scheduled_date'] : ''; ?>"
                           min="<?php echo date('Y-m-d'); ?>">
                </div>
                <div class="form-group">
                    <label>Reschedule Reason <span class="required">*</span></label>
                    <select name="reschedule_reason" required>
                        <option value="">Select Reason</option>
                        <option value="Equipment Unavailable">Equipment Unavailable</option>
                        <option value="Technician Unavailable">Technician Unavailable</option>
                        <option value="Parts on Order">Parts on Order</option>
                        <option value="Higher Priority Work">Higher Priority Work</option>
                        <option value="Weather Conditions">Weather Conditions</option>
                        <option value="Other">Other</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>Additional Notes (Optional)</label>
                    <textarea name="reschedule_notes" placeholder="Add additional notes..." maxlength="500"></textarea>
                </div>
                <div class="modal-actions">
                    <button type="button" class="btn btn-secondary" onclick="hideModal('rescheduleModal')">Cancel</button>
                    <button type="submit" name="reschedule" class="btn btn-primary">Reschedule</button>
                </div>
            </form>
        </div>
    </div>
    
    <!-- Edit Request Modal -->
    <div id="editRequestModal" class="modal">
        <div class="modal-content">
            <h3><i class="fas fa-edit"></i> Edit Request Details</h3>
            <form method="POST" action="">
                <div class="form-group">
                    <label>Subject <span class="required">*</span></label>
                    <input type="text" name="subject" value="<?php echo safe_display($request['subject']); ?>" required maxlength="255">
                </div>
                <div class="form-group">
                    <label>Description <span class="required">*</span></label>
                    <textarea name="description" required maxlength="2000"><?php echo safe_display($request['description']); ?></textarea>
                </div>
                <div class="form-group">
                    <label>Equipment</label>
                    <select name="equipment_id">
                        <option value="0">Select Equipment</option>
                        <?php foreach ($equipment_list as $equipment): ?>
                            <option value="<?php echo $equipment['id']; ?>" <?php echo ($request['equipment_id'] ?? 0) == $equipment['id'] ? 'selected' : ''; ?>>
                                <?php echo safe_display($equipment['name']) . " (" . safe_display($equipment['serial_number']) . ")"; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Maintenance Type</label>
                    <select name="type">
                        <option value="">Select Type</option>
                        <?php foreach ($type_options as $option): ?>
                            <option value="<?php echo safe_display($option); ?>" <?php echo ($request['type'] ?? '') == $option ? 'selected' : ''; ?>>
                                <?php echo safe_display($option); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="modal-actions">
                    <button type="button" class="btn btn-secondary" onclick="hideModal('editRequestModal')">Cancel</button>
                    <button type="submit" name="update_request" class="btn btn-primary">Save Changes</button>
                </div>
            </form>
        </div>
    </div>
    
    <!-- Delete Confirmation Modal -->
    <div id="deleteModal" class="modal">
        <div class="modal-content">
            <h3><i class="fas fa-exclamation-triangle"></i> Confirm Deletion</h3>
            <p>Are you sure you want to delete this maintenance request? This action cannot be undone.</p>
            <div class="modal-actions">
                <button type="button" class="btn btn-secondary" onclick="hideModal('deleteModal')">Cancel</button>
                <a href="delete_request.php?id=<?php echo $request_id; ?>" class="btn btn-danger">
                    <i class="fas fa-trash"></i> Delete Request
                </a>
            </div>
        </div>
    </div>
    
    <script>
        function showModal(modalId) {
            const modal = document.getElementById(modalId);
            if (modal) {
                modal.style.display = 'flex';
                document.body.style.overflow = 'hidden';
            }
        }
        
        function hideModal(modalId) {
            const modal = document.getElementById(modalId);
            if (modal) {
                modal.style.display = 'none';
                document.body.style.overflow = 'auto';
            }
        }
        
        // Close modal when clicking outside
        window.onclick = function(event) {
            if (event.target.classList.contains('modal')) {
                event.target.style.display = 'none';
                document.body.style.overflow = 'auto';
            }
        };
        
        // Close modal with Escape key
        document.addEventListener('keydown', function(event) {
            if (event.key === 'Escape') {
                const modals = document.querySelectorAll('.modal');
                modals.forEach(modal => {
                    if (modal.style.display === 'flex') {
                        modal.style.display = 'none';
                        document.body.style.overflow = 'auto';
                    }
                });
            }
        });
        
        // Auto-hide success/error messages after 5 seconds
        setTimeout(function() {
            const messages = document.querySelectorAll('.success-message, .error-message');
            messages.forEach(message => {
                if (message.style.display !== 'none') {
                    message.style.display = 'none';
                }
            });
        }, 5000);
        
        // Form validation
        document.addEventListener('DOMContentLoaded', function() {
            const forms = document.querySelectorAll('form');
            forms.forEach(form => {
                form.addEventListener('submit', function(e) {
                    const requiredFields = form.querySelectorAll('[required]');
                    let isValid = true;
                    
                    requiredFields.forEach(field => {
                        if (!field.value.trim()) {
                            isValid = false;
                            field.style.borderColor = '#dc3545';
                        } else {
                            field.style.borderColor = '';
                        }
                    });
                    
                    if (!isValid) {
                        e.preventDefault();
                        alert('Please fill in all required fields.');
                    }
                });
            });
        });
        
        // Set minimum date for rescheduling to today
        const dateInput = document.querySelector('input[type="date"][name="new_scheduled_date"]');
        if (dateInput) {
            dateInput.min = new Date().toISOString().split('T')[0];
        }
    </script>
</body>
</html>