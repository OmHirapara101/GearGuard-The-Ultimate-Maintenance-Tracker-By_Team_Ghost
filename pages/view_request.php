<?php
// view_request.php - View Maintenance Request Details
session_start();

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: ../index.php');
    exit();
}

// Database connection
require_once '../includes/db_connect.php';

// Get request ID from query parameter
$request_id = isset($_GET['id']) ? intval($_GET['id']) : 0;
$request_number = isset($_GET['request_number']) ? $_GET['request_number'] : '';

$request = null;
$assigned_team = null;
$equipment = null;
$activity_log = [];
$attachments = [];

try {
    // Get request details based on ID or request number
    if ($request_id > 0) {
        $query = "SELECT mr.*, e.name as equipment_name, e.serial_number, e.location, 
                         t.name as team_name, t.contact_email, t.contact_phone,
                         u.full_name as requester_name, u.email as requester_email
                  FROM maintenance_requests mr
                  LEFT JOIN equipment e ON mr.equipment_id = e.id
                  LEFT JOIN maintenance_teams t ON mr.assigned_team_id = t.id
                  LEFT JOIN users u ON mr.requester_id = u.id
                  WHERE mr.id = ?";
        $stmt = mysqli_prepare($conn, $query);
        mysqli_stmt_bind_param($stmt, 'i', $request_id);
    } elseif (!empty($request_number)) {
        $query = "SELECT mr.*, e.name as equipment_name, e.serial_number, e.location, 
                         t.name as team_name, t.contact_email, t.contact_phone,
                         u.full_name as requester_name, u.email as requester_email
                  FROM maintenance_requests mr
                  LEFT JOIN equipment e ON mr.equipment_id = e.id
                  LEFT JOIN maintenance_teams t ON mr.assigned_team_id = t.id
                  LEFT JOIN users u ON mr.requester_id = u.id
                  WHERE mr.request_number = ?";
        $stmt = mysqli_prepare($conn, $query);
        mysqli_stmt_bind_param($stmt, 's', $request_number);
    } else {
        header('Location: requests.php');
        exit();
    }
    
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    
    if ($result && $row = mysqli_fetch_assoc($result)) {
        $request = $row;
        
        // Generate request number if not exists
        if (empty($request['request_number'])) {
            $request['request_number'] = 'REQ-' . str_pad($request['id'], 6, '0', STR_PAD_LEFT);
        }
        
        // Get activity log for this request
        $activity_query = "SELECT al.*, u.full_name as user_name
                          FROM activity_log al
                          LEFT JOIN users u ON al.user_id = u.id
                          WHERE al.request_id = ?
                          ORDER BY al.created_at DESC";
        $activity_stmt = mysqli_prepare($conn, $activity_query);
        mysqli_stmt_bind_param($activity_stmt, 'i', $request['id']);
        mysqli_stmt_execute($activity_stmt);
        $activity_result = mysqli_stmt_get_result($activity_stmt);
        
        while ($activity = mysqli_fetch_assoc($activity_result)) {
            $activity_log[] = $activity;
        }
        
        // Get attachments for this request
        $attachment_query = "SELECT * FROM request_attachments 
                            WHERE request_id = ? 
                            ORDER BY uploaded_at DESC";
        $attachment_stmt = mysqli_prepare($conn, $attachment_query);
        mysqli_stmt_bind_param($attachment_stmt, 'i', $request['id']);
        mysqli_stmt_execute($attachment_stmt);
        $attachment_result = mysqli_stmt_get_result($attachment_stmt);
        
        while ($attachment = mysqli_fetch_assoc($attachment_result)) {
            $attachments[] = $attachment;
        }
        
    } else {
        // Request not found
        header('Location: requests.php?error=request_not_found');
        exit();
    }
    
} catch (Exception $e) {
    error_log("View request error: " . $e->getMessage());
    $error = "Unable to load request details. Please try again.";
}

// Handle status update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_status'])) {
    $new_status = $_POST['status'];
    $notes = $_POST['status_notes'];
    $user_id = $_SESSION['user_id'];
    
    // Validate status
    $valid_statuses = ['New', 'In Progress', 'On Hold', 'Repaired', 'Cancelled'];
    if (in_array($new_status, $valid_statuses)) {
        try {
            // Start transaction
            mysqli_begin_transaction($conn);
            
            // Update request status
            $update_query = "UPDATE maintenance_requests 
                            SET status = ?, updated_at = NOW() 
                            WHERE id = ?";
            $update_stmt = mysqli_prepare($conn, $update_query);
            mysqli_stmt_bind_param($update_stmt, 'si', $new_status, $request['id']);
            mysqli_stmt_execute($update_stmt);
            
            // Log activity
            $activity_query = "INSERT INTO activity_log 
                              (request_id, user_id, activity_type, description, created_at) 
                              VALUES (?, ?, 'status_change', ?, NOW())";
            $activity_stmt = mysqli_prepare($conn, $activity_query);
            $description = "Status changed from {$request['status']} to {$new_status}. " . ($notes ? "Notes: {$notes}" : "");
            mysqli_stmt_bind_param($activity_stmt, 'iis', $request['id'], $user_id, $description);
            mysqli_stmt_execute($activity_stmt);
            
            // Commit transaction
            mysqli_commit($conn);
            
            // Refresh page to show updated status
            header("Location: view_request.php?id=" . $request['id']);
            exit();
            
        } catch (Exception $e) {
            mysqli_rollback($conn);
            $error = "Failed to update status: " . $e->getMessage();
        }
    }
}

// Handle assign team
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['assign_team'])) {
    $team_id = $_POST['team_id'];
    $user_id = $_SESSION['user_id'];
    
    try {
        // Start transaction
        mysqli_begin_transaction($conn);
        
        // Get team name for logging
        $team_query = "SELECT name FROM maintenance_teams WHERE id = ?";
        $team_stmt = mysqli_prepare($conn, $team_query);
        mysqli_stmt_bind_param($team_stmt, 'i', $team_id);
        mysqli_stmt_execute($team_stmt);
        $team_result = mysqli_stmt_get_result($team_stmt);
        $team_data = mysqli_fetch_assoc($team_result);
        $team_name = $team_data['name'] ?? 'Unknown Team';
        
        // Update assigned team
        $update_query = "UPDATE maintenance_requests 
                        SET assigned_team_id = ?, updated_at = NOW() 
                        WHERE id = ?";
        $update_stmt = mysqli_prepare($conn, $update_query);
        mysqli_stmt_bind_param($update_stmt, 'ii', $team_id, $request['id']);
        mysqli_stmt_execute($update_stmt);
        
        // Log activity
        $activity_query = "INSERT INTO activity_log 
                          (request_id, user_id, activity_type, description, created_at) 
                          VALUES (?, ?, 'team_assigned', ?, NOW())";
        $activity_stmt = mysqli_prepare($conn, $activity_query);
        $description = "Assigned to team: {$team_name}";
        mysqli_stmt_bind_param($activity_stmt, 'iis', $request['id'], $user_id, $description);
        mysqli_stmt_execute($activity_stmt);
        
        // Commit transaction
        mysqli_commit($conn);
        
        // Refresh page
        header("Location: view_request.php?id=" . $request['id']);
        exit();
        
    } catch (Exception $e) {
        mysqli_rollback($conn);
        $error = "Failed to assign team: " . $e->getMessage();
    }
}

// Get available teams for assignment
$teams = [];
$teams_query = "SELECT id, name, specialization FROM maintenance_teams ORDER BY name";
$teams_result = mysqli_query($conn, $teams_query);
while ($team = mysqli_fetch_assoc($teams_result)) {
    $teams[] = $team;
}

mysqli_close($conn);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Request #<?php echo htmlspecialchars($request['request_number']); ?> - GearGuard</title>
    <link rel="stylesheet" href="../css/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        .request-container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 20px;
        }
        
        .request-header {
            background: white;
            border-radius: 10px;
            padding: 25px;
            margin-bottom: 25px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.08);
            border-left: 5px solid #1976d2;
        }
        
        .request-title {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 20px;
        }
        
        .request-id {
            font-size: 1.8rem;
            font-weight: 600;
            color: #333;
        }
        
        .request-subject {
            font-size: 1.4rem;
            color: #555;
            margin: 10px 0;
        }
        
        .request-meta {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin-top: 20px;
            padding-top: 20px;
            border-top: 1px solid #eee;
        }
        
        .meta-item {
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .meta-label {
            font-weight: 600;
            color: #666;
            min-width: 120px;
        }
        
        .meta-value {
            color: #333;
        }
        
        .status-badge {
            padding: 6px 15px;
            border-radius: 20px;
            font-size: 0.9rem;
            font-weight: 500;
            display: inline-block;
        }
        
        .status-new { background: #ffebee; color: #c62828; }
        .status-in-progress { background: #fff3e0; color: #ef6c00; }
        .status-on-hold { background: #f5f5f5; color: #616161; }
        .status-repaired { background: #e8f5e9; color: #2e7d32; }
        .status-cancelled { background: #f5f5f5; color: #616161; }
        
        .priority-badge {
            padding: 4px 12px;
            border-radius: 4px;
            font-weight: 500;
            font-size: 0.85rem;
        }
        
        .priority-low { background: #e8f5e9; color: #2e7d32; }
        .priority-medium { background: #fff3e0; color: #f57c00; }
        .priority-high { background: #ffebee; color: #c62828; }
        .priority-critical { background: #f44336; color: white; }
        
        .details-grid {
            display: grid;
            grid-template-columns: 2fr 1fr;
            gap: 25px;
            margin-bottom: 30px;
        }
        
        @media (max-width: 768px) {
            .details-grid {
                grid-template-columns: 1fr;
            }
        }
        
        .details-section {
            background: white;
            border-radius: 10px;
            padding: 25px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.08);
        }
        
        .section-title {
            font-size: 1.2rem;
            font-weight: 600;
            color: #333;
            margin-bottom: 20px;
            padding-bottom: 10px;
            border-bottom: 2px solid #f0f0f0;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .description-box {
            background: #f9f9f9;
            padding: 20px;
            border-radius: 8px;
            line-height: 1.6;
            color: #444;
            white-space: pre-wrap;
        }
        
        .activity-timeline {
            margin-top: 20px;
        }
        
        .activity-item {
            display: flex;
            gap: 15px;
            padding: 15px 0;
            border-bottom: 1px solid #f0f0f0;
        }
        
        .activity-item:last-child {
            border-bottom: none;
        }
        
        .activity-icon {
            width: 40px;
            height: 40px;
            background: #f0f0f0;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }
        
        .activity-content {
            flex: 1;
        }
        
        .activity-description {
            color: #555;
            margin: 5px 0;
        }
        
        .activity-time {
            color: #888;
            font-size: 0.85rem;
        }
        
        .attachment-list {
            display: grid;
            gap: 10px;
            margin-top: 15px;
        }
        
        .attachment-item {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 12px;
            background: #f9f9f9;
            border-radius: 6px;
            transition: background 0.3s;
        }
        
        .attachment-item:hover {
            background: #f0f0f0;
        }
        
        .attachment-icon {
            color: #666;
            font-size: 1.2rem;
        }
        
        .attachment-info {
            flex: 1;
        }
        
        .attachment-name {
            font-weight: 500;
            color: #333;
        }
        
        .attachment-size {
            color: #888;
            font-size: 0.85rem;
        }
        
        .action-buttons {
            display: flex;
            gap: 10px;
            margin-top: 20px;
            flex-wrap: wrap;
        }
        
        .btn-print {
            background: #6c757d;
            color: white;
            padding: 8px 16px;
            border-radius: 4px;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            font-size: 0.9rem;
        }
        
        .btn-print:hover {
            background: #5a6268;
        }
        
        .btn-back {
            color: #666;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 8px 16px;
            border: 1px solid #ddd;
            border-radius: 4px;
        }
        
        .btn-back:hover {
            background: #f5f5f5;
        }
        
        .form-group {
            margin-bottom: 20px;
        }
        
        .form-label {
            display: block;
            margin-bottom: 8px;
            font-weight: 500;
            color: #555;
        }
        
        .form-select, .form-textarea {
            width: 100%;
            padding: 10px;
            border: 1px solid #ddd;
            border-radius: 4px;
            font-size: 1rem;
        }
        
        .form-textarea {
            min-height: 100px;
            resize: vertical;
        }
        
        .empty-state {
            text-align: center;
            padding: 40px;
            color: #666;
        }
        
        .empty-icon {
            font-size: 3rem;
            margin-bottom: 20px;
            opacity: 0.5;
        }
        
        .alert {
            padding: 15px;
            border-radius: 4px;
            margin-bottom: 20px;
        }
        
        .alert-success {
            background: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }
        
        .alert-error {
            background: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }
        
        .btn-assign {
            background: #2196f3;
            color: white;
            border: none;
            padding: 8px 16px;
            border-radius: 4px;
            cursor: pointer;
        }
        
        .btn-assign:hover {
            background: #1976d2;
        }
    </style>
</head>
<body>
    <?php include '../includes/header.php'; ?>
    
    <div class="container">
        <?php include '../includes/sidebar.php'; ?>
        
        <main class="main-content">
            <div class="request-container">
                <!-- Error/Success Messages -->
                <?php if (isset($_GET['success'])): ?>
                    <div class="alert alert-success">
                        <?php 
                        $messages = [
                            'status_updated' => 'Status updated successfully!',
                            'team_assigned' => 'Team assigned successfully!',
                            'note_added' => 'Note added successfully!'
                        ];
                        echo htmlspecialchars($messages[$_GET['success']] ?? 'Action completed successfully!');
                        ?>
                    </div>
                <?php endif; ?>
                
                <?php if (isset($error)): ?>
                    <div class="alert alert-error">
                        <?php echo htmlspecialchars($error); ?>
                    </div>
                <?php endif; ?>
                
                <!-- Request Header -->
                <div class="request-header">
                    <div class="request-title">
                        <div>
                            <h1 class="request-id">Request #<?php echo htmlspecialchars($request['request_number']); ?></h1>
                            <h2 class="request-subject"><?php echo htmlspecialchars($request['subject']); ?></h2>
                        </div>
                        <div>
                            <span class="status-badge status-<?php echo strtolower(str_replace(' ', '-', $request['status'])); ?>">
                                <?php echo $request['status']; ?>
                            </span>
                        </div>
                    </div>
                    
                    <div class="request-meta">
                        <div class="meta-item">
                            <span class="meta-label">Created:</span>
                            <span class="meta-value"><?php echo date('F j, Y g:i A', strtotime($request['created_at'])); ?></span>
                        </div>
                        <div class="meta-item">
                            <span class="meta-label">Priority:</span>
                            <span class="priority-badge priority-<?php echo strtolower($request['priority']); ?>">
                                <?php echo $request['priority']; ?>
                            </span>
                        </div>
                        <div class="meta-item">
                            <span class="meta-label">Type:</span>
                            <span class="meta-value"><?php echo $request['type']; ?></span>
                        </div>
                        <div class="meta-item">
                            <span class="meta-label">Requester:</span>
                            <span class="meta-value"><?php echo htmlspecialchars($request['requester_name'] ?? 'Unknown'); ?></span>
                        </div>
                    </div>
                </div>
                
                <!-- Details Grid -->
                <div class="details-grid">
                    <!-- Left Column: Details and Activity -->
                    <div>
                        <!-- Request Details -->
                        <div class="details-section">
                            <div class="section-title">
                                <span>Request Details</span>
                            </div>
                            
                            <div class="description-box">
                                <?php echo nl2br(htmlspecialchars($request['description'])); ?>
                            </div>
                            
                            <!-- Equipment Information -->
                            <?php if ($request['equipment_name']): ?>
                                <div style="margin-top: 25px;">
                                    <h4 style="margin-bottom: 10px; color: #555;">Equipment Information</h4>
                                    <div style="background: #f9f9f9; padding: 15px; border-radius: 6px;">
                                        <div><strong>Name:</strong> <?php echo htmlspecialchars($request['equipment_name']); ?></div>
                                        <?php if ($request['serial_number']): ?>
                                            <div><strong>Serial Number:</strong> <?php echo htmlspecialchars($request['serial_number']); ?></div>
                                        <?php endif; ?>
                                        <?php if ($request['location']): ?>
                                            <div><strong>Location:</strong> <?php echo htmlspecialchars($request['location']); ?></div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endif; ?>
                            
                            <!-- Assigned Team -->
                            <?php if ($request['team_name']): ?>
                                <div style="margin-top: 25px;">
                                    <h4 style="margin-bottom: 10px; color: #555;">Assigned Team</h4>
                                    <div style="background: #e8f5e9; padding: 15px; border-radius: 6px;">
                                        <div><strong>Team:</strong> <?php echo htmlspecialchars($request['team_name']); ?></div>
                                        <?php if ($request['contact_phone']): ?>
                                            <div><strong>Contact:</strong> <?php echo htmlspecialchars($request['contact_phone']); ?></div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endif; ?>
                        </div>
                        
                        <!-- Activity Timeline -->
                        <div class="details-section" style="margin-top: 25px;">
                            <div class="section-title">
                                <span>Activity Log</span>
                            </div>
                            
                            <div class="activity-timeline">
                                <?php if (!empty($activity_log)): ?>
                                    <?php foreach ($activity_log as $activity): ?>
                                        <div class="activity-item">
                                            <div class="activity-icon">
                                                <?php 
                                                $icons = [
                                                    'status_change' => '🔄',
                                                    'team_assigned' => '👥',
                                                    'note_added' => '📝',
                                                    'attachment_added' => '📎',
                                                    'created' => '➕'
                                                ];
                                                echo $icons[$activity['activity_type']] ?? '📋';
                                                ?>
                                            </div>
                                            <div class="activity-content">
                                                <div style="font-weight: 500;">
                                                    <?php echo htmlspecialchars($activity['user_name'] ?? 'System'); ?>
                                                    <span style="color: #888; font-weight: normal;">
                                                        <?php 
                                                        $actions = [
                                                            'status_change' => 'updated the status',
                                                            'team_assigned' => 'assigned a team',
                                                            'note_added' => 'added a note',
                                                            'attachment_added' => 'added an attachment',
                                                            'created' => 'created this request'
                                                        ];
                                                        echo $actions[$activity['activity_type']] ?? 'performed an action';
                                                        ?>
                                                    </span>
                                                </div>
                                                <div class="activity-description">
                                                    <?php echo htmlspecialchars($activity['description']); ?>
                                                </div>
                                                <div class="activity-time">
                                                    <?php echo date('F j, Y g:i A', strtotime($activity['created_at'])); ?>
                                                </div>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <div class="empty-state">
                                        <div class="empty-icon">📋</div>
                                        <p>No activity recorded yet.</p>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Right Column: Actions and Info -->
                    <div>
                        <!-- Status Update Form -->
                        <div class="details-section">
                            <div class="section-title">
                                <span>Update Status</span>
                            </div>
                            
                            <form method="POST" action="">
                                <div class="form-group">
                                    <label class="form-label">New Status</label>
                                    <select name="status" class="form-select" required>
                                        <option value="">Select Status</option>
                                        <option value="New" <?php echo $request['status'] == 'New' ? 'selected' : ''; ?>>New</option>
                                        <option value="In Progress" <?php echo $request['status'] == 'In Progress' ? 'selected' : ''; ?>>In Progress</option>
                                        <option value="On Hold" <?php echo $request['status'] == 'On Hold' ? 'selected' : ''; ?>>On Hold</option>
                                        <option value="Repaired" <?php echo $request['status'] == 'Repaired' ? 'selected' : ''; ?>>Repaired</option>
                                        <option value="Cancelled" <?php echo $request['status'] == 'Cancelled' ? 'selected' : ''; ?>>Cancelled</option>
                                    </select>
                                </div>
                                
                                <div class="form-group">
                                    <label class="form-label">Notes (Optional)</label>
                                    <textarea name="status_notes" class="form-textarea" placeholder="Add any additional notes..."></textarea>
                                </div>
                                
                                <button type="submit" name="update_status" class="btn btn-primary">
                                    <i class="fas fa-save"></i> Update Status
                                </button>
                            </form>
                        </div>
                        
                        <!-- Assign Team -->
                        <div class="details-section" style="margin-top: 25px;">
                            <div class="section-title">
                                <span>Assign Team</span>
                            </div>
                            
                            <form method="POST" action="">
                                <div class="form-group">
                                    <label class="form-label">Select Team</label>
                                    <select name="team_id" class="form-select" required>
                                        <option value="">Select a team</option>
                                        <?php foreach ($teams as $team): ?>
                                            <option value="<?php echo $team['id']; ?>" 
                                                <?php echo $request['assigned_team_id'] == $team['id'] ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($team['name']); ?>
                                                <?php if ($team['specialization']): ?>
                                                    (<?php echo htmlspecialchars($team['specialization']); ?>)
                                                <?php endif; ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                
                                <button type="submit" name="assign_team" class="btn-assign">
                                    <i class="fas fa-user-plus"></i> Assign Team
                                </button>
                            </form>
                        </div>
                        
                        <!-- Attachments -->
                        <?php if (!empty($attachments)): ?>
                            <div class="details-section" style="margin-top: 25px;">
                                <div class="section-title">
                                    <span>Attachments</span>
                                    <a href="upload_attachment.php?request_id=<?php echo $request['id']; ?>" class="btn btn-sm">
                                        <i class="fas fa-plus"></i> Add
                                    </a>
                                </div>
                                
                                <div class="attachment-list">
                                    <?php foreach ($attachments as $attachment): ?>
                                        <div class="attachment-item">
                                            <div class="attachment-icon">
                                                <?php 
                                                $ext = pathinfo($attachment['file_name'], PATHINFO_EXTENSION);
                                                $icons = [
                                                    'pdf' => '📕',
                                                    'doc' => '📘', 'docx' => '📘',
                                                    'xls' => '📗', 'xlsx' => '📗',
                                                    'jpg' => '🖼️', 'jpeg' => '🖼️', 'png' => '🖼️', 'gif' => '🖼️',
                                                    'zip' => '📦', 'rar' => '📦'
                                                ];
                                                echo $icons[strtolower($ext)] ?? '📄';
                                                ?>
                                            </div>
                                            <div class="attachment-info">
                                                <div class="attachment-name"><?php echo htmlspecialchars($attachment['file_name']); ?></div>
                                                <div class="attachment-size">
                                                    <?php echo round($attachment['file_size'] / 1024, 2); ?> KB
                                                    • <?php echo date('M d, Y', strtotime($attachment['uploaded_at'])); ?>
                                                </div>
                                            </div>
                                            <a href="../uploads/<?php echo $attachment['file_path']; ?>" 
                                               target="_blank" 
                                               class="btn btn-sm">
                                                <i class="fas fa-download"></i>
                                            </a>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        <?php endif; ?>
                        
                        <!-- Quick Actions -->
                        <div class="details-section" style="margin-top: 25px;">
                            <div class="section-title">
                                <span>Quick Actions</span>
                            </div>
                            
                            <div class="action-buttons">
                                <a href="requests.php" class="btn-back">
                                    <i class="fas fa-arrow-left"></i> Back to Requests
                                </a>
                                
                                <a href="edit_request.php?id=<?php echo $request['id']; ?>" class="btn btn-secondary">
                                    <i class="fas fa-edit"></i> Edit
                                </a>
                                
                                <a href="#" onclick="window.print()" class="btn-print">
                                    <i class="fas fa-print"></i> Print
                                </a>
                                
                                <a href="mailto:<?php echo $request['requester_email']; ?>?subject=Request%20<?php echo $request['request_number']; ?>" 
                                   class="btn btn-primary">
                                    <i class="fas fa-envelope"></i> Email
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </main>
    </div>
    
    <script>
        // Print-specific styling
        const printStyles = `
            @media print {
                .sidebar, .header, .action-buttons, form, .btn {
                    display: none !important;
                }
                
                .request-container {
                    padding: 0;
                }
                
                .details-section {
                    box-shadow: none !important;
                    border: 1px solid #ddd !important;
                }
                
                .details-grid {
                    display: block !important;
                }
                
                .status-badge, .priority-badge {
                    border: 1px solid #666 !important;
                    color: #333 !important;
                    background: white !important;
                }
            }
        `;
        
        // Add print styles
        const styleSheet = document.createElement("style");
        styleSheet.type = "text/css";
        styleSheet.innerText = printStyles;
        document.head.appendChild(styleSheet);
        
        // Auto-expand textareas
        document.querySelectorAll('.form-textarea').forEach(textarea => {
            textarea.addEventListener('input', function() {
                this.style.height = 'auto';
                this.style.height = (this.scrollHeight) + 'px';
            });
        });
        
        // Status change confirmation
        const statusForm = document.querySelector('form[action=""]');
        if (statusForm) {
            statusForm.addEventListener('submit', function(e) {
                const status = this.querySelector('[name="status"]').value;
                if (status === 'Cancelled') {
                    if (!confirm('Are you sure you want to cancel this request? This action cannot be undone.')) {
                        e.preventDefault();
                    }
                }
            });
        }
        
        // Auto-refresh activity log every 30 seconds
        setInterval(() => {
            fetch(`view_request_ajax.php?action=activity&id=<?php echo $request['id']; ?>`)
                .then(response => response.json())
                .then(data => {
                    if (data.new_activity) {
                        // Could add logic to update activity log without refresh
                        console.log('New activity detected');
                    }
                })
                .catch(error => console.error('Error checking for updates:', error));
        }, 30000);
    </script>
</body>
</html>