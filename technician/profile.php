<?php
// profile.php - User Profile Page
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: ../index.php');
    exit();
}

// Database connection
require_once '../includes/db_connect.php';

$user_id = $_SESSION['user_id'];
$user_name = $_SESSION['full_name'];
$user_role = $_SESSION['role'];

// Initialize variables
$success_message = '';
$error_message = '';
$user_data = [];

// Fetch user data
$query = "SELECT u.*, mt.name as team_name 
          FROM users u 
          LEFT JOIN maintenance_teams mt ON u.team_id = mt.id 
          WHERE u.id = ?";
$stmt = mysqli_prepare($conn, $query);
mysqli_stmt_bind_param($stmt, 'i', $user_id);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);

if ($result && $row = mysqli_fetch_assoc($result)) {
    $user_data = $row;
} else {
    $error_message = "Unable to load user data.";
}

// Handle profile update
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['update_profile'])) {
        $full_name = trim($_POST['full_name']);
        $email = trim($_POST['email']);
        $phone = trim($_POST['phone'] ?? '');
        $address = trim($_POST['address'] ?? '');
        
        // Validate inputs
        if (empty($full_name)) {
            $error_message = "Full name is required.";
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error_message = "Please enter a valid email address.";
        } else {
            // Check if email already exists for another user
            $check_email_query = "SELECT id FROM users WHERE email = ? AND id != ?";
            $check_stmt = mysqli_prepare($conn, $check_email_query);
            mysqli_stmt_bind_param($check_stmt, 'si', $email, $user_id);
            mysqli_stmt_execute($check_stmt);
            mysqli_stmt_store_result($check_stmt);
            
            if (mysqli_stmt_num_rows($check_stmt) > 0) {
                $error_message = "This email is already registered by another user.";
            } else {
                // Update user profile
                $update_query = "UPDATE users 
                                SET full_name = ?, email = ?, phone = ?, address = ?, updated_at = NOW() 
                                WHERE id = ?";
                $update_stmt = mysqli_prepare($conn, $update_query);
                mysqli_stmt_bind_param($update_stmt, 'ssssi', $full_name, $email, $phone, $address, $user_id);
                
                if (mysqli_stmt_execute($update_stmt)) {
                    $success_message = "Profile updated successfully!";
                    
                    // Update session data
                    $_SESSION['full_name'] = $full_name;
                    $_SESSION['email'] = $email;
                    
                    // Refresh user data
                    $user_data['full_name'] = $full_name;
                    $user_data['email'] = $email;
                    $user_data['phone'] = $phone;
                    $user_data['address'] = $address;
                } else {
                    $error_message = "Failed to update profile. Please try again.";
                }
            }
        }
    }
    
    // Handle password change
    if (isset($_POST['change_password'])) {
        $current_password = $_POST['current_password'];
        $new_password = $_POST['new_password'];
        $confirm_password = $_POST['confirm_password'];
        
        // Validate password inputs
        if (empty($current_password) || empty($new_password) || empty($confirm_password)) {
            $error_message = "All password fields are required.";
        } elseif ($new_password !== $confirm_password) {
            $error_message = "New passwords do not match.";
        } elseif (strlen($new_password) < 6) {
            $error_message = "New password must be at least 6 characters long.";
        } else {
            // Verify current password (using MD5 as shown in your database)
            $current_password_md5 = md5($current_password);
            if ($current_password_md5 !== $user_data['password']) {
                $error_message = "Current password is incorrect.";
            } else {
                // Update password (using MD5)
                $new_password_md5 = md5($new_password);
                $password_query = "UPDATE users SET password = ? WHERE id = ?";
                $password_stmt = mysqli_prepare($conn, $password_query);
                mysqli_stmt_bind_param($password_stmt, 'si', $new_password_md5, $user_id);
                
                if (mysqli_stmt_execute($password_stmt)) {
                    $success_message = "Password changed successfully!";
                } else {
                    $error_message = "Failed to change password. Please try again.";
                }
            }
        }
    }
    
    // Handle profile picture upload
    if (isset($_POST['upload_photo']) && isset($_FILES['profile_photo'])) {
        $file = $_FILES['profile_photo'];
        
        // Check for upload errors
        if ($file['error'] !== UPLOAD_ERR_OK) {
            $error_message = "File upload failed. Error code: " . $file['error'];
        } else {
            // Validate file type
            $allowed_types = ['image/jpeg', 'image/png', 'image/gif'];
            $file_type = mime_content_type($file['tmp_name']);
            
            if (!in_array($file_type, $allowed_types)) {
                $error_message = "Only JPG, PNG, and GIF files are allowed.";
            } else {
                // Validate file size (max 2MB)
                if ($file['size'] > 2 * 1024 * 1024) {
                    $error_message = "File size must be less than 2MB.";
                } else {
                    // Generate unique filename
                    $extension = pathinfo($file['name'], PATHINFO_EXTENSION);
                    $filename = 'profile_' . $user_id . '_' . time() . '.' . $extension;
                    $upload_dir = '../uploads/profiles/';
                    
                    // Create directory if it doesn't exist
                    if (!is_dir($upload_dir)) {
                        mkdir($upload_dir, 0777, true);
                    }
                    
                    $filepath = $upload_dir . $filename;
                    
                    if (move_uploaded_file($file['tmp_name'], $filepath)) {
                        // Update user profile with photo path
                        $photo_query = "UPDATE users SET profile_photo = ?, updated_at = NOW() WHERE id = ?";
                        $photo_stmt = mysqli_prepare($conn, $photo_query);
                        mysqli_stmt_bind_param($photo_stmt, 'si', $filename, $user_id);
                        
                        if (mysqli_stmt_execute($photo_stmt)) {
                            $success_message = "Profile photo updated successfully!";
                            $user_data['profile_photo'] = $filename;
                        } else {
                            $error_message = "Failed to update profile photo in database.";
                        }
                    } else {
                        $error_message = "Failed to upload file. Please try again.";
                    }
                }
            }
        }
    }
}

// Get user statistics
$stats = [];
if ($user_role === 'technician') {
    // Technician-specific stats
    $stats_query = "SELECT 
        COUNT(*) as total_tasks,
        SUM(CASE WHEN status = 'Completed' THEN 1 ELSE 0 END) as completed_tasks,
        SUM(CASE WHEN status = 'In Progress' THEN 1 ELSE 0 END) as in_progress_tasks,
        SUM(CASE WHEN scheduled_date < CURDATE() AND status NOT IN ('Completed', 'Cancelled') THEN 1 ELSE 0 END) as overdue_tasks
        FROM maintenance_requests 
        WHERE assigned_to = ?";
    $stats_stmt = mysqli_prepare($conn, $stats_query);
    mysqli_stmt_bind_param($stats_stmt, 'i', $user_id);
    mysqli_stmt_execute($stats_stmt);
    $stats_result = mysqli_stmt_get_result($stats_stmt);
    $stats = mysqli_fetch_assoc($stats_result);
}

// Get recent activity
$activity_query = "SELECT mr.request_number, mr.subject, mr.status, mr.updated_at 
                  FROM maintenance_requests mr
                  WHERE mr.assigned_to = ? OR mr.created_by = ?
                  ORDER BY mr.updated_at DESC 
                  LIMIT 5";
$activity_stmt = mysqli_prepare($conn, $activity_query);
mysqli_stmt_bind_param($activity_stmt, 'ii', $user_id, $user_id);
mysqli_stmt_execute($activity_stmt);
$activity_result = mysqli_stmt_get_result($activity_stmt);

$recent_activity = [];
while ($activity = mysqli_fetch_assoc($activity_result)) {
    $recent_activity[] = $activity;
}

mysqli_close($conn);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Profile - GearGuard</title>
    <link rel="stylesheet" href="../css/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: #f5f7fa;
            color: #333;
        }
        
        .dashboard-wrapper {
            display: flex;
            min-height: 100vh;
        }
        
        
        
        /* Main Content */
        .main-content {
            flex: 1;
            padding: 30px;
            overflow-y: auto;
            background: #f5f7fa;
        }
        
        .page-title {
            color: #333;
            margin-bottom: 10px;
            font-size: 1.8rem;
            font-weight: 600;
        }
        
        .page-subtitle {
            color: #666;
            margin-bottom: 30px;
            font-size: 1rem;
        }
        
        /* Profile Container */
        .profile-container {
            display: grid;
            grid-template-columns: 300px 1fr;
            gap: 30px;
            margin-bottom: 30px;
        }
        
        @media (max-width: 1024px) {
            .profile-container {
                grid-template-columns: 1fr;
            }
        }
        
        /* Profile Sidebar */
        .profile-sidebar {
            background: white;
            border-radius: 15px;
            padding: 30px;
            box-shadow: 0 5px 20px rgba(0,0,0,0.05);
            text-align: center;
        }
        
        .profile-photo-container {
            position: relative;
            width: 150px;
            height: 150px;
            margin: 0 auto 20px;
        }
        
        .profile-photo {
            width: 150px;
            height: 150px;
            border-radius: 50%;
            object-fit: cover;
            border: 5px solid #f0f4ff;
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
        }
        
        .photo-upload-btn {
            position: absolute;
            bottom: 10px;
            right: 10px;
            background: #667eea;
            color: white;
            width: 40px;
            height: 40px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: all 0.3s;
            border: none;
        }
        
        .photo-upload-btn:hover {
            background: #764ba2;
            transform: scale(1.1);
        }
        
        .user-details {
            margin-top: 20px;
        }
        
        .user-name-large {
            font-size: 1.5rem;
            font-weight: 600;
            color: #333;
            margin-bottom: 5px;
        }
        
        .user-role-badge {
            display: inline-block;
            padding: 5px 15px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border-radius: 20px;
            font-size: 0.9rem;
            font-weight: 500;
            margin-bottom: 15px;
            text-transform: capitalize;
        }
        
        .user-info-item {
            display: flex;
            align-items: center;
            gap: 10px;
            margin: 10px 0;
            color: #666;
            font-size: 0.95rem;
        }
        
        .user-info-item i {
            width: 20px;
            color: #667eea;
        }
        
        /* Profile Content */
        .profile-content {
            display: flex;
            flex-direction: column;
            gap: 30px;
        }
        
        /* Profile Sections */
        .profile-section {
            background: white;
            border-radius: 15px;
            padding: 30px;
            box-shadow: 0 5px 20px rgba(0,0,0,0.05);
        }
        
        .section-title {
            font-size: 1.3rem;
            font-weight: 600;
            color: #333;
            margin-bottom: 25px;
            padding-bottom: 15px;
            border-bottom: 2px solid #f0f0f0;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .section-title i {
            color: #667eea;
        }
        
        /* Form Styles */
        .form-group {
            margin-bottom: 20px;
        }
        
        .form-row {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            margin-bottom: 20px;
        }
        
        .form-label {
            display: block;
            margin-bottom: 8px;
            font-weight: 500;
            color: #555;
            font-size: 0.95rem;
        }
        
        .form-input {
            width: 100%;
            padding: 12px 15px;
            border: 2px solid #e1e5e9;
            border-radius: 8px;
            font-size: 1rem;
            transition: all 0.3s;
            background: white;
        }
        
        .form-input:focus {
            outline: none;
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
        }
        
        .form-input:disabled {
            background: #f8f9fa;
            color: #666;
            cursor: not-allowed;
        }
        
        .btn {
            padding: 12px 25px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-size: 1rem;
            font-weight: 500;
            transition: all 0.3s;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }
        
        .btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(102, 126, 234, 0.3);
        }
        
        .btn-secondary {
            background: #6c757d;
        }
        
        .btn-secondary:hover {
            background: #5a6268;
            box-shadow: 0 5px 15px rgba(108, 117, 125, 0.3);
        }
        
        .btn-danger {
            background: #dc3545;
        }
        
        .btn-danger:hover {
            background: #c82333;
            box-shadow: 0 5px 15px rgba(220, 53, 69, 0.3);
        }
        
        /* Statistics Cards */
        .stats-cards {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        
        .stat-card {
            background: white;
            border-radius: 15px;
            padding: 20px;
            box-shadow: 0 5px 20px rgba(0,0,0,0.05);
            text-align: center;
            transition: transform 0.3s;
        }
        
        .stat-card:hover {
            transform: translateY(-5px);
        }
        
        .stat-icon {
            font-size: 2.5rem;
            margin-bottom: 10px;
            opacity: 0.8;
        }
        
        .stat-value {
            font-size: 2.2rem;
            font-weight: 700;
            color: #333;
            margin: 10px 0;
        }
        
        .stat-label {
            color: #666;
            font-size: 0.9rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        /* Recent Activity */
        .activity-list {
            display: flex;
            flex-direction: column;
            gap: 15px;
        }
        
        .activity-item {
            display: flex;
            align-items: center;
            gap: 15px;
            padding: 15px;
            background: #f8f9fa;
            border-radius: 10px;
            transition: all 0.3s;
        }
        
        .activity-item:hover {
            background: #f0f4ff;
            transform: translateX(5px);
        }
        
        .activity-icon {
            width: 40px;
            height: 40px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.2rem;
        }
        
        .activity-content {
            flex: 1;
        }
        
        .activity-title {
            font-weight: 500;
            color: #333;
            margin-bottom: 5px;
        }
        
        .activity-details {
            color: #666;
            font-size: 0.9rem;
            display: flex;
            gap: 15px;
        }
        
        .activity-time {
            color: #999;
            font-size: 0.85rem;
        }
        
        /* Status Badges */
        .status-badge {
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 0.85rem;
            font-weight: 500;
            display: inline-block;
        }
        
        .status-new { background: #ffebee; color: #c62828; }
        .status-in-progress { background: #fff3e0; color: #ef6c00; }
        .status-completed { background: #e8f5e9; color: #2e7d32; }
        
        /* Alerts */
        .alert {
            padding: 15px 20px;
            border-radius: 10px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
            animation: slideIn 0.3s ease-out;
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
        
        /* Modal for Photo Upload */
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.5);
            z-index: 10000;
            align-items: center;
            justify-content: center;
        }
        
        .modal-content {
            background: white;
            border-radius: 15px;
            padding: 30px;
            max-width: 500px;
            width: 90%;
            animation: modalSlideIn 0.3s ease-out;
        }
        
        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }
        
        .modal-title {
            font-size: 1.3rem;
            font-weight: 600;
            color: #333;
        }
        
        .close-modal {
            background: none;
            border: none;
            font-size: 1.5rem;
            cursor: pointer;
            color: #666;
        }
        
        .close-modal:hover {
            color: #333;
        }
        
        /* Photo Upload Form */
        .photo-upload-form {
            text-align: center;
        }
        
        .photo-preview {
            width: 200px;
            height: 200px;
            border-radius: 50%;
            object-fit: cover;
            margin: 20px auto;
            border: 5px solid #f0f0f0;
            display: none;
        }
        
        .file-input-label {
            display: inline-block;
            padding: 12px 25px;
            background: #f8f9fa;
            color: #667eea;
            border: 2px dashed #667eea;
            border-radius: 8px;
            cursor: pointer;
            transition: all 0.3s;
            margin-bottom: 20px;
        }
        
        .file-input-label:hover {
            background: #f0f4ff;
        }
        
        /* Animations */
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
        
        @keyframes modalSlideIn {
            from {
                transform: translateY(-50px);
                opacity: 0;
            }
            to {
                transform: translateY(0);
                opacity: 1;
            }
        }
        
        /* Responsive */
        @media (max-width: 768px) {
            .header {
                padding: 0 15px;
            }
            
            .main-content {
                padding: 20px;
            }
            
            .profile-section {
                padding: 20px;
            }
            
            .form-row {
                grid-template-columns: 1fr;
            }
            
            .sidebar {
                width: 100%;
                position: fixed;
                top: 70px;
                left: -100%;
                z-index: 999;
                transition: left 0.3s;
            }
            
            .sidebar.active {
                left: 0;
            }
            
            .menu-toggle {
                display: block;
                background: none;
                border: none;
                color: white;
                font-size: 1.5rem;
                cursor: pointer;
            }
        }
        
        /* Account Info */
        .account-info {
            background: #f8f9fa;
            padding: 15px;
            border-radius: 10px;
            margin-top: 20px;
        }
        
        .info-item {
            display: flex;
            justify-content: space-between;
            padding: 10px 0;
            border-bottom: 1px solid #e9ecef;
        }
        
        .info-item:last-child {
            border-bottom: none;
        }
        
        .info-label {
            font-weight: 500;
            color: #555;
        }
        
        .info-value {
            color: #333;
        }
    </style>
</head>
<body>
    <!-- Header -->
    <?php include '../includes/header.php'; ?>
    
    <div class="dashboard-wrapper">
        <!-- Sidebar -->
       <?php include '../includes/sidebartec.php'; ?>
        
        <!-- Main Content -->
        <main class="main-content">
            <div class="page-title">My Profile</div>
            <div class="page-subtitle">Manage your account information and settings</div>
            
            <!-- Alerts -->
            <?php if ($success_message): ?>
                <div class="alert alert-success">
                    <i class="fas fa-check-circle"></i>
                    <?php echo htmlspecialchars($success_message); ?>
                </div>
            <?php endif; ?>
            
            <?php if ($error_message): ?>
                <div class="alert alert-error">
                    <i class="fas fa-exclamation-circle"></i>
                    <?php echo htmlspecialchars($error_message); ?>
                </div>
            <?php endif; ?>
            
            <div class="profile-container">
                <!-- Profile Sidebar -->
                <div class="profile-sidebar">
                    <div class="profile-photo-container">
                        <?php if (!empty($user_data['profile_photo'])): ?>
                            <img src="../uploads/profiles/<?php echo htmlspecialchars($user_data['profile_photo']); ?>" 
                                 alt="Profile Photo" class="profile-photo">
                        <?php else: ?>
                            <div class="profile-photo" style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); 
                                 display: flex; align-items: center; justify-content: center; color: white; font-size: 3rem;">
                                <?php echo strtoupper(substr($user_data['full_name'] ?? 'U', 0, 1)); ?>
                            </div>
                        <?php endif; ?>
                        <button class="photo-upload-btn" onclick="openPhotoModal()">
                            <i class="fas fa-camera"></i>
                        </button>
                    </div>
                    
                    <div class="user-details">
                        <div class="user-name-large"><?php echo htmlspecialchars($user_data['full_name'] ?? 'User'); ?></div>
                        <div class="user-role-badge"><?php echo htmlspecialchars($user_data['role'] ?? 'user'); ?></div>
                        
                        <div class="user-info-item">
                            <i class="fas fa-envelope"></i>
                            <span><?php echo htmlspecialchars($user_data['email'] ?? 'Not set'); ?></span>
                        </div>
                        
                        <?php if (!empty($user_data['phone'])): ?>
                            <div class="user-info-item">
                                <i class="fas fa-phone"></i>
                                <span><?php echo htmlspecialchars($user_data['phone']); ?></span>
                            </div>
                        <?php endif; ?>
                        
                        <?php if (!empty($user_data['team_name'])): ?>
                            <div class="user-info-item">
                                <i class="fas fa-users"></i>
                                <span><?php echo htmlspecialchars($user_data['team_name']); ?> Team</span>
                            </div>
                        <?php endif; ?>
                        
                        <div class="user-info-item">
                            <i class="fas fa-calendar-alt"></i>
                            <span>Joined <?php echo date('F Y', strtotime($user_data['created_at'] ?? 'now')); ?></span>
                        </div>
                    </div>
                    
                    <div class="account-info">
                        <div class="info-item">
                            <span class="info-label">Account Status:</span>
                            <span class="info-value" style="color: <?php echo ($user_data['status'] ?? 'active') === 'active' ? '#4CAF50' : '#f44336'; ?>">
                                <?php echo ucfirst($user_data['status'] ?? 'active'); ?>
                            </span>
                        </div>
                        <div class="info-item">
                            <span class="info-label">Last Login:</span>
                            <span class="info-value"><?php echo date('M d, Y H:i'); ?></span>
                        </div>
                        <div class="info-item">
                            <span class="info-label">Member Since:</span>
                            <span class="info-value"><?php echo date('M d, Y', strtotime($user_data['created_at'] ?? 'now')); ?></span>
                        </div>
                    </div>
                </div>
                
                <!-- Profile Content -->
                <div class="profile-content">
                    <!-- Personal Information -->
                    <div class="profile-section">
                        <div class="section-title">
                            <i class="fas fa-user"></i>
                            Personal Information
                        </div>
                        
                        <form method="POST" action="">
                            <div class="form-row">
                                <div class="form-group">
                                    <label class="form-label">Full Name *</label>
                                    <input type="text" name="full_name" class="form-input" 
                                           value="<?php echo htmlspecialchars($user_data['full_name'] ?? ''); ?>" 
                                           required>
                                </div>
                                
                                <div class="form-group">
                                    <label class="form-label">Email Address *</label>
                                    <input type="email" name="email" class="form-input" 
                                           value="<?php echo htmlspecialchars($user_data['email'] ?? ''); ?>" 
                                           required>
                                </div>
                            </div>
                            
                            <div class="form-row">
                                <div class="form-group">
                                    <label class="form-label">Phone Number</label>
                                    <input type="tel" name="phone" class="form-input" 
                                           value="<?php echo htmlspecialchars($user_data['phone'] ?? ''); ?>"
                                           placeholder="+1 (234) 567-8900">
                                </div>
                                
                                <div class="form-group">
                                    <label class="form-label">Address</label>
                                    <input type="text" name="address" class="form-input" 
                                           value="<?php echo htmlspecialchars($user_data['address'] ?? ''); ?>"
                                           placeholder="123 Main St, City, Country">
                                </div>
                            </div>
                            
                            <div class="form-row">
                                <div class="form-group">
                                    <label class="form-label">Username</label>
                                    <input type="text" class="form-input" 
                                           value="<?php echo htmlspecialchars($user_data['username'] ?? ''); ?>" 
                                           disabled>
                                </div>
                                
                                <div class="form-group">
                                    <label class="form-label">User Role</label>
                                    <input type="text" class="form-input" 
                                           value="<?php echo htmlspecialchars(ucfirst($user_data['role'] ?? 'user')); ?>" 
                                           disabled>
                                </div>
                            </div>
                            
                            <button type="submit" name="update_profile" class="btn">
                                <i class="fas fa-save"></i> Update Profile
                            </button>
                        </form>
                    </div>
                    
                    <!-- Password Change -->
                    <div class="profile-section">
                        <div class="section-title">
                            <i class="fas fa-lock"></i>
                            Change Password
                        </div>
                        
                        <form method="POST" action="">
                            <div class="form-row">
                                <div class="form-group">
                                    <label class="form-label">Current Password *</label>
                                    <input type="password" name="current_password" class="form-input" required>
                                </div>
                            </div>
                            
                            <div class="form-row">
                                <div class="form-group">
                                    <label class="form-label">New Password *</label>
                                    <input type="password" name="new_password" class="form-input" required
                                           minlength="6" placeholder="At least 6 characters">
                                </div>
                                
                                <div class="form-group">
                                    <label class="form-label">Confirm New Password *</label>
                                    <input type="password" name="confirm_password" class="form-input" required>
                                </div>
                            </div>
                            
                            <button type="submit" name="change_password" class="btn">
                                <i class="fas fa-key"></i> Change Password
                            </button>
                        </form>
                    </div>
                    
                    <!-- Statistics (for technicians) -->
                    <?php if ($user_role === 'technician' && !empty($stats)): ?>
                    <div class="profile-section">
                        <div class="section-title">
                            <i class="fas fa-chart-bar"></i>
                            Performance Statistics
                        </div>
                        
                        <div class="stats-cards">
                            <div class="stat-card">
                                <div class="stat-icon">📋</div>
                                <div class="stat-value"><?php echo $stats['total_tasks'] ?? 0; ?></div>
                                <div class="stat-label">Total Tasks</div>
                            </div>
                            
                            <div class="stat-card">
                                <div class="stat-icon">✅</div>
                                <div class="stat-value"><?php echo $stats['completed_tasks'] ?? 0; ?></div>
                                <div class="stat-label">Completed</div>
                            </div>
                            
                            <div class="stat-card">
                                <div class="stat-icon">🛠️</div>
                                <div class="stat-value"><?php echo $stats['in_progress_tasks'] ?? 0; ?></div>
                                <div class="stat-label">In Progress</div>
                            </div>
                            
                            <div class="stat-card">
                                <div class="stat-icon">⏰</div>
                                <div class="stat-value"><?php echo $stats['overdue_tasks'] ?? 0; ?></div>
                                <div class="stat-label">Overdue</div>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>
                    
                    <!-- Recent Activity -->
                    <?php if (!empty($recent_activity)): ?>
                    <div class="profile-section">
                        <div class="section-title">
                            <i class="fas fa-history"></i>
                            Recent Activity
                        </div>
                        
                        <div class="activity-list">
                            <?php foreach ($recent_activity as $activity): 
                                $status_class = 'status-' . strtolower(str_replace(' ', '-', $activity['status']));
                            ?>
                            <div class="activity-item">
                                <div class="activity-icon">
                                    <?php 
                                    $icons = [
                                        'New' => '📝',
                                        'In Progress' => '🛠️',
                                        'Completed' => '✅',
                                        'On Hold' => '⏸️',
                                        'Cancelled' => '❌'
                                    ];
                                    echo $icons[$activity['status']] ?? '📋';
                                    ?>
                                </div>
                                <div class="activity-content">
                                    <div class="activity-title">
                                        <?php echo htmlspecialchars($activity['subject']); ?>
                                    </div>
                                    <div class="activity-details">
                                        <span>Request: <?php echo htmlspecialchars($activity['request_number'] ?? 'N/A'); ?></span>
                                        <span class="status-badge <?php echo $status_class; ?>">
                                            <?php echo $activity['status']; ?>
                                        </span>
                                    </div>
                                </div>
                                <div class="activity-time">
                                    <?php echo date('M d, H:i', strtotime($activity['updated_at'])); ?>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <?php endif; ?>
                    
                    <!-- Account Settings -->
                    <div class="profile-section">
                        <div class="section-title">
                            <i class="fas fa-cog"></i>
                            Account Settings
                        </div>
                        
                        <div style="display: flex; gap: 15px; flex-wrap: wrap;">
                            <button class="btn btn-secondary" onclick="exportData()">
                                <i class="fas fa-download"></i> Export My Data
                            </button>
                            
                            <button class="btn btn-secondary" onclick="showNotificationsSettings()">
                                <i class="fas fa-bell"></i> Notification Settings
                            </button>
                            
                            <button class="btn btn-danger" onclick="confirmDeactivate()">
                                <i class="fas fa-user-slash"></i> Deactivate Account
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </main>
    </div>
    
    <!-- Photo Upload Modal -->
    <div class="modal" id="photoModal">
        <div class="modal-content">
            <div class="modal-header">
                <div class="modal-title">Update Profile Photo</div>
                <button class="close-modal" onclick="closePhotoModal()">&times;</button>
            </div>
            
            <form method="POST" action="" enctype="multipart/form-data" class="photo-upload-form">
                <input type="file" id="profilePhotoInput" name="profile_photo" accept="image/*" 
                       style="display: none;" onchange="previewPhoto(event)">
                
                <label for="profilePhotoInput" class="file-input-label">
                    <i class="fas fa-cloud-upload-alt"></i> Choose Photo
                </label>
                
                <img id="photoPreview" class="photo-preview" alt="Photo Preview">
                
                <div style="display: flex; gap: 10px; margin-top: 20px;">
                    <button type="button" class="btn btn-secondary" onclick="closePhotoModal()">
                        Cancel
                    </button>
                    <button type="submit" name="upload_photo" class="btn">
                        <i class="fas fa-upload"></i> Upload Photo
                    </button>
                </div>
            </form>
        </div>
    </div>
    
    <script>
        // Mobile menu toggle
        const menuToggle = document.querySelector('.menu-toggle');
        const sidebar = document.querySelector('.sidebar');
        
        if (menuToggle) {
            menuToggle.addEventListener('click', function() {
                sidebar.classList.toggle('active');
            });
        }
        
        // Show menu toggle on mobile
        if (window.innerWidth <= 768) {
            menuToggle.style.display = 'block';
        }
        
        window.addEventListener('resize', function() {
            if (window.innerWidth <= 768) {
                menuToggle.style.display = 'block';
            } else {
                menuToggle.style.display = 'none';
                sidebar.classList.remove('active');
            }
        });
        
        // Photo modal functions
        function openPhotoModal() {
            document.getElementById('photoModal').style.display = 'flex';
        }
        
        function closePhotoModal() {
            document.getElementById('photoModal').style.display = 'none';
            document.getElementById('photoPreview').style.display = 'none';
            document.getElementById('profilePhotoInput').value = '';
        }
        
        function previewPhoto(event) {
            const input = event.target;
            const preview = document.getElementById('photoPreview');
            
            if (input.files && input.files[0]) {
                const reader = new FileReader();
                
                reader.onload = function(e) {
                    preview.src = e.target.result;
                    preview.style.display = 'block';
                }
                
                reader.readAsDataURL(input.files[0]);
            }
        }
        
        // Close modal when clicking outside
        window.onclick = function(event) {
            const modal = document.getElementById('photoModal');
            if (event.target === modal) {
                closePhotoModal();
            }
        }
        
        // Account actions
        function exportData() {
            if (confirm('Export your personal data? This may take a moment.')) {
                window.location.href = 'export_profile.php';
            }
        }
        
        function showNotificationsSettings() {
            alert('Notification settings would open here. Feature coming soon!');
        }
        
        function confirmDeactivate() {
            if (confirm('Are you sure you want to deactivate your account? This action cannot be undone.')) {
                alert('Account deactivation requested. Feature coming soon!');
                // In production: window.location.href = 'deactivate_account.php';
            }
        }
        
        // Form validation
        document.querySelectorAll('form').forEach(form => {
            form.addEventListener('submit', function(e) {
                // Basic validation for password change
                if (this.querySelector('input[name="new_password"]')) {
                    const newPass = this.querySelector('input[name="new_password"]').value;
                    const confirmPass = this.querySelector('input[name="confirm_password"]').value;
                    
                    if (newPass !== confirmPass) {
                        e.preventDefault();
                        alert('New passwords do not match!');
                        return false;
                    }
                    
                    if (newPass.length < 6) {
                        e.preventDefault();
                        alert('Password must be at least 6 characters long!');
                        return false;
                    }
                }
            });
        });
        
        // Auto-dismiss alerts after 5 seconds
        setTimeout(() => {
            document.querySelectorAll('.alert').forEach(alert => {
                alert.style.opacity = '0';
                alert.style.transition = 'opacity 0.5s';
                setTimeout(() => alert.remove(), 500);
            });
        }, 5000);
        
        // Update last seen time
        function updateLastSeen() {
            const now = new Date();
            const timeString = now.toLocaleTimeString([], {hour: '2-digit', minute:'2-digit'});
            const dateString = now.toLocaleDateString([], {month: 'short', day: 'numeric', year: 'numeric'});
            
            const lastLoginElement = document.querySelector('.account-info .info-item:nth-child(2) .info-value');
            if (lastLoginElement) {
                lastLoginElement.textContent = `${dateString} ${timeString}`;
            }
        }
        
        // Update every minute
        setInterval(updateLastSeen, 60000);
        
        // Initialize tooltips
        document.querySelectorAll('[title]').forEach(element => {
            element.addEventListener('mouseenter', function() {
                const tooltip = document.createElement('div');
                tooltip.className = 'tooltip';
                tooltip.textContent = this.title;
                tooltip.style.cssText = `
                    position: absolute;
                    background: #333;
                    color: white;
                    padding: 5px 10px;
                    border-radius: 4px;
                    font-size: 0.85rem;
                    z-index: 10000;
                    white-space: nowrap;
                `;
                
                const rect = this.getBoundingClientRect();
                tooltip.style.top = (rect.top - 35) + 'px';
                tooltip.style.left = (rect.left + (rect.width / 2)) + 'px';
                tooltip.style.transform = 'translateX(-50%)';
                
                document.body.appendChild(tooltip);
                this._tooltip = tooltip;
            });
            
            element.addEventListener('mouseleave', function() {
                if (this._tooltip) {
                    this._tooltip.remove();
                }
            });
        });
    </script>
</body>
</html> 