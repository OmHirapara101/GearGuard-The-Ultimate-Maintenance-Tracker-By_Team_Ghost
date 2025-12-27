<?php
session_start();

// Check if user is logged in and is a technician
if (!isset($_SESSION['user_id'])) {
    header('Location: ../index.php');
    exit();
}

// Check if user is a technician or admin
$user_id = $_SESSION['user_id'];
$user_role = $_SESSION['role'] ?? '';
$is_technician = ($user_role == 'technician' || $user_role == 'admin');

if (!$is_technician) {
    header('Location: ../index.php?error=Access denied. Technician access required.');
    exit();
}

// Database connection
$conn = mysqli_connect("localhost", "root", "", "gear_guard");

if (!$conn) {
    die("Database connection failed: " . mysqli_connect_error());
}

// ==================== HANDLE FORM SUBMISSIONS ====================
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    
    // Update request status
    if (isset($_POST['update_request_status'])) {
        $request_id = intval($_POST['request_id']);
        $new_status = mysqli_real_escape_string($conn, $_POST['status']);
        $hours_spent = floatval($_POST['hours_spent']);
        $work_notes = mysqli_real_escape_string($conn, $_POST['work_notes']);
        
        // Get current task details
        $task_query = "SELECT * FROM maintenance_requests WHERE id = $request_id AND assigned_to = $user_id";
        $task_result = mysqli_query($conn, $task_query);
        
        if (mysqli_num_rows($task_result) > 0) {
            $task = mysqli_fetch_assoc($task_result);
            $old_status = $task['status'];
            
            // Update the request
            $update_query = "UPDATE maintenance_requests SET 
                status = '$new_status',
                actual_hours = COALESCE(actual_hours, 0) + $hours_spent,
                updated_at = NOW()";
            
            // Set completion date if status is Repaired/Completed
            if ($new_status == 'Repaired' || $new_status == 'Completed') {
                $update_query .= ", completed_date = NOW()";
            }
            
            // Set start date if status is In Progress and not already set
            if ($new_status == 'In Progress' && empty($task['work_started_at'])) {
                $update_query .= ", work_started_at = NOW()";
            }
            
            $update_query .= " WHERE id = $request_id";
            
            if (mysqli_query($conn, $update_query)) {
                // Log the status change
                $log_comment = "Status changed from $old_status to $new_status";
                if ($hours_spent > 0) {
                    $log_comment .= " | Hours spent: $hours_spent";
                }
                if (!empty($work_notes)) {
                    $log_comment .= " | Notes: $work_notes";
                }
                
                $log_query = "INSERT INTO maintenance_comments (request_id, user_id, comment) 
                             VALUES ($request_id, $user_id, '$log_comment')";
                mysqli_query($conn, $log_query);
                
                $_SESSION['success_message'] = "Request status updated successfully!";
            } else {
                $_SESSION['error_message'] = "Failed to update status: " . mysqli_error($conn);
            }
        } else {
            $_SESSION['error_message'] = "Request not found or not assigned to you!";
        }
    }
    
    // Add work log
    elseif (isset($_POST['add_work_log'])) {
        $request_id = intval($_POST['request_id']);
        $work_description = mysqli_real_escape_string($conn, $_POST['work_description']);
        $hours_worked = floatval($_POST['hours_worked']);
        $parts_used = mysqli_real_escape_string($conn, $_POST['parts_used']);
        
        // Create work_logs table if it doesn't exist
        $check_table = mysqli_query($conn, "SHOW TABLES LIKE 'work_logs'");
        if (mysqli_num_rows($check_table) == 0) {
            $create_table = "
                CREATE TABLE IF NOT EXISTS work_logs (
                    id INT(11) NOT NULL AUTO_INCREMENT,
                    request_id INT(11) NOT NULL,
                    technician_id INT(11) NOT NULL,
                    work_description TEXT NOT NULL,
                    hours_worked DECIMAL(5,2) NOT NULL,
                    parts_used TEXT,
                    logged_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY (id),
                    FOREIGN KEY (request_id) REFERENCES maintenance_requests(id) ON DELETE CASCADE,
                    FOREIGN KEY (technician_id) REFERENCES users(id)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
            ";
            mysqli_query($conn, $create_table);
        }
        
        $insert_query = "INSERT INTO work_logs (request_id, technician_id, work_description, hours_worked, parts_used) 
                        VALUES ($request_id, $user_id, '$work_description', $hours_worked, '$parts_used')";
        
        if (mysqli_query($conn, $insert_query)) {
            // Update total hours worked on the request
            $update_hours = "UPDATE maintenance_requests SET 
                            actual_hours = COALESCE(actual_hours, 0) + $hours_worked,
                            updated_at = NOW()
                            WHERE id = $request_id";
            mysqli_query($conn, $update_hours);
            
            // Log the work entry
            $log_query = "INSERT INTO maintenance_comments (request_id, user_id, comment) 
                         VALUES ($request_id, $user_id, 
                         'Work log added: $hours_worked hours worked" . 
                         (!empty($parts_used) ? " - Parts used: $parts_used" : "") . "')";
            mysqli_query($conn, $log_query);
            
            $_SESSION['success_message'] = "Work log added successfully!";
        } else {
            $_SESSION['error_message'] = "Failed to add work log: " . mysqli_error($conn);
        }
    }
    
    // Refresh the page
    header("Location: technician_dashboard.php");
    exit();
}

// Display success/error messages from session
$success_message = '';
$error_message = '';
if (isset($_SESSION['success_message'])) {
    $success_message = $_SESSION['success_message'];
    unset($_SESSION['success_message']);
}
if (isset($_SESSION['error_message'])) {
    $error_message = $_SESSION['error_message'];
    unset($_SESSION['error_message']);
}

// Get filter parameters
$filter_view = isset($_GET['view']) ? $_GET['view'] : 'kanban'; // kanban, calendar, list
$filter_status = isset($_GET['status']) ? $_GET['status'] : 'all';
$filter_priority = isset($_GET['priority']) ? $_GET['priority'] : 'all';
$filter_date = isset($_GET['date']) ? $_GET['date'] : '';
$filter_type = isset($_GET['type']) ? $_GET['type'] : 'all';

// Build filter conditions
$filter_conditions = ["mr.assigned_to = $user_id"];

if ($filter_status != 'all') {
    $filter_conditions[] = "mr.status = '" . mysqli_real_escape_string($conn, $filter_status) . "'";
}

if ($filter_priority != 'all') {
    $filter_conditions[] = "mr.priority = '" . mysqli_real_escape_string($conn, $filter_priority) . "'";
}

if (!empty($filter_date)) {
    $filter_conditions[] = "mr.scheduled_date = '" . mysqli_real_escape_string($conn, $filter_date) . "'";
}

if ($filter_type != 'all') {
    $filter_conditions[] = "mr.type = '" . mysqli_real_escape_string($conn, $filter_type) . "'";
}

$filter_sql = implode(' AND ', $filter_conditions);

// Get technician's assigned requests
$requests_query = "
    SELECT mr.*, 
           e.name as equipment_name, 
           e.location,
           e.serial_number,
           e.model,
           ec.name as equipment_category,
           d.name as department_name,
           u_creator.full_name as created_by_name,
           u_creator.email as creator_email,
           (SELECT COUNT(*) FROM work_logs wl WHERE wl.request_id = mr.id) as work_logs_count
    FROM maintenance_requests mr
    LEFT JOIN equipment e ON mr.equipment_id = e.id
    LEFT JOIN equipment_categories ec ON e.category_id = ec.id
    LEFT JOIN departments d ON e.department_id = d.id
    LEFT JOIN users u_creator ON mr.created_by = u_creator.id
    WHERE $filter_sql
    ORDER BY 
        CASE 
            WHEN mr.priority = 'Critical' THEN 1
            WHEN mr.priority = 'High' THEN 2
            WHEN mr.priority = 'Medium' THEN 3
            WHEN mr.priority = 'Low' THEN 4
            ELSE 5
        END,
        mr.scheduled_date ASC,
        mr.created_at DESC
";

$requests_result = mysqli_query($conn, $requests_query);
$assigned_requests = [];
if ($requests_result) {
    while ($row = mysqli_fetch_assoc($requests_result)) {
        $assigned_requests[] = $row;
    }
}

// Get request statistics
$stats_query = "
    SELECT 
        COUNT(*) as total_requests,
        SUM(CASE WHEN status = 'New' THEN 1 ELSE 0 END) as new_requests,
        SUM(CASE WHEN status = 'In Progress' THEN 1 ELSE 0 END) as in_progress_requests,
        SUM(CASE WHEN status = 'Repaired' THEN 1 ELSE 0 END) as repaired_requests,
        SUM(CASE WHEN status = 'Completed' THEN 1 ELSE 0 END) as completed_requests,
        SUM(CASE WHEN priority = 'Critical' THEN 1 ELSE 0 END) as critical_requests,
        SUM(CASE WHEN priority = 'High' THEN 1 ELSE 0 END) as high_requests,
        SUM(COALESCE(actual_hours, 0)) as total_hours_worked,
        SUM(CASE WHEN type = 'Preventive' THEN 1 ELSE 0 END) as preventive_requests,
        SUM(CASE WHEN type = 'Corrective' THEN 1 ELSE 0 END) as corrective_requests
    FROM maintenance_requests 
    WHERE assigned_to = $user_id
    AND scheduled_date >= DATE_SUB(NOW(), INTERVAL 30 DAY)
";

$stats_result = mysqli_query($conn, $stats_query);
$request_stats = [
    'total_requests' => 0,
    'new_requests' => 0,
    'in_progress_requests' => 0,
    'repaired_requests' => 0,
    'completed_requests' => 0,
    'critical_requests' => 0,
    'high_requests' => 0,
    'total_hours_worked' => 0,
    'preventive_requests' => 0,
    'corrective_requests' => 0
];

if ($stats_result && $row = mysqli_fetch_assoc($stats_result)) {
    $request_stats = $row;
}

// Get upcoming preventive tasks for calendar
$today = date('Y-m-d');
$month_start = date('Y-m-01');
$month_end = date('Y-m-t');
$calendar_tasks_query = "
    SELECT mr.*, e.name as equipment_name, e.location
    FROM maintenance_requests mr
    LEFT JOIN equipment e ON mr.equipment_id = e.id
    WHERE mr.assigned_to = $user_id
    AND mr.type = 'Preventive'
    AND mr.scheduled_date BETWEEN '$month_start' AND '$month_end'
    AND mr.status NOT IN ('Repaired', 'Completed', 'Cancelled')
    ORDER BY mr.scheduled_date
";

$calendar_tasks_result = mysqli_query($conn, $calendar_tasks_query);
$calendar_tasks = [];
if ($calendar_tasks_result) {
    while ($row = mysqli_fetch_assoc($calendar_tasks_result)) {
        $calendar_tasks[] = $row;
    }
}

// Group tasks by date for calendar
$tasks_by_date = [];
foreach ($calendar_tasks as $task) {
    $date = $task['scheduled_date'];
    if (!isset($tasks_by_date[$date])) {
        $tasks_by_date[$date] = [];
    }
    $tasks_by_date[$date][] = $task;
}

// Get recent work logs
$recent_work_logs = [];
$check_work_logs = mysqli_query($conn, "SHOW TABLES LIKE 'work_logs'");
if (mysqli_num_rows($check_work_logs) > 0) {
    $logs_query = "
        SELECT wl.*, mr.request_number, mr.subject
        FROM work_logs wl
        JOIN maintenance_requests mr ON wl.request_id = mr.id
        WHERE wl.technician_id = $user_id
        ORDER BY wl.logged_at DESC
        LIMIT 10
    ";
    
    $logs_result = mysqli_query($conn, $logs_query);
    if ($logs_result) {
        while ($row = mysqli_fetch_assoc($logs_result)) {
            $recent_work_logs[] = $row;
        }
    }
}

// Get all distinct statuses for filter
$all_statuses = ['New', 'In Progress', 'Repaired', 'Completed', 'Cancelled'];
$status_result = mysqli_query($conn, "SELECT DISTINCT status FROM maintenance_requests WHERE status IS NOT NULL ORDER BY status");
if ($status_result) {
    while ($row = mysqli_fetch_assoc($status_result)) {
        if (!in_array($row['status'], $all_statuses)) {
            $all_statuses[] = $row['status'];
        }
    }
}

// Get priority levels
$priorities = ['Low', 'Medium', 'High', 'Critical'];

// Get maintenance types
$maintenance_types = [];
$type_result = mysqli_query($conn, "SELECT DISTINCT type FROM maintenance_requests WHERE type IS NOT NULL ORDER BY type");
if ($type_result) {
    while ($row = mysqli_fetch_assoc($type_result)) {
        $maintenance_types[] = $row['type'];
    }
}

// Get current month and year for calendar
$month = isset($_GET['month']) ? intval($_GET['month']) : date('n');
$year = isset($_GET['year']) ? intval($_GET['year']) : date('Y');

// Validate month
if ($month < 1) {
    $month = 12;
    $year--;
} elseif ($month > 12) {
    $month = 1;
    $year++;
}

// Calendar calculations
$month_name = date('F', mktime(0, 0, 0, $month, 1, $year));
$days_in_month = date('t', mktime(0, 0, 0, $month, 1, $year));
$first_day_of_month = date('w', mktime(0, 0, 0, $month, 1, $year));

mysqli_close($conn);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Technician Dashboard - GearGuard</title>
    <link rel="stylesheet" href="../css/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/fullcalendar@5.11.3/main.min.css">
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
            background: linear-gradient(135deg, #f5f7fa 0%, #e4e8f0 100%);
            min-height: 100vh;
            color: var(--dark-color);
        }
        
        .container {
            display: flex;
            min-height: calc(100vh - 60px);
        }
        
        .main-content {
            flex: 1;
            padding: 25px;
            background: #f5f7fa;
            overflow-y: auto;
        }
        
        .technician-header {
            background: white;
            padding: 25px 30px;
            border-radius: 15px;
            box-shadow: var(--card-shadow);
            margin-bottom: 25px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            border-left: 5px solid var(--primary-color);
        }
        
        .technician-info {
            display: flex;
            align-items: center;
            gap: 20px;
        }
        
        .technician-avatar {
            width: 70px;
            height: 70px;
            background: linear-gradient(135deg, var(--primary-color) 0%, var(--secondary-color) 100%);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 1.8rem;
            font-weight: 600;
        }
        
        .technician-details h2 {
            margin: 0;
            color: var(--dark-color);
            font-size: 1.6rem;
            font-weight: 600;
        }
        
        .technician-details p {
            margin: 5px 0 0 0;
            color: var(--gray-color);
            font-size: 0.95rem;
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
        
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        
        .stat-card {
            background: white;
            padding: 25px;
            border-radius: 15px;
            text-align: center;
            box-shadow: var(--card-shadow);
            transition: all 0.3s;
            border: 1px solid var(--border-color);
            position: relative;
            overflow: hidden;
        }
        
        .stat-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 4px;
            background: linear-gradient(90deg, var(--primary-color) 0%, var(--secondary-color) 100%);
        }
        
        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: var(--hover-shadow);
        }
        
        .stat-icon {
            width: 50px;
            height: 50px;
            margin: 0 auto 15px;
            background: linear-gradient(135deg, var(--primary-color) 0%, var(--secondary-color) 100%);
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 1.5rem;
        }
        
        .stat-value {
            font-size: 2rem;
            font-weight: 700;
            margin-bottom: 5px;
            color: var(--dark-color);
        }
        
        .stat-label {
            font-size: 0.9rem;
            color: var(--gray-color);
            text-transform: uppercase;
            letter-spacing: 1px;
            font-weight: 500;
        }
        
        .view-toggle-container {
            display: flex;
            gap: 10px;
            margin-bottom: 20px;
            background: white;
            padding: 15px;
            border-radius: 8px;
            box-shadow: var(--card-shadow);
        }
        
        .view-toggle-btn {
            padding: 12px 24px;
            border: 2px solid #ddd;
            background: white;
            border-radius: 6px;
            cursor: pointer;
            font-weight: 500;
            display: flex;
            align-items: center;
            gap: 8px;
            transition: all 0.3s;
        }
        
        .view-toggle-btn:hover {
            border-color: var(--primary-color);
            color: var(--primary-color);
        }
        
        .view-toggle-btn.active {
            background: var(--primary-color);
            color: white;
            border-color: var(--primary-color);
        }
        
        .filters-section {
            background: white;
            padding: 25px;
            border-radius: 15px;
            box-shadow: var(--card-shadow);
            margin-bottom: 30px;
        }
        
        .filters-section h3 {
            margin: 0 0 20px 0;
            color: var(--dark-color);
            font-size: 1.3rem;
            font-weight: 600;
        }
        
        .filters {
            display: flex;
            gap: 20px;
            flex-wrap: wrap;
        }
        
        .filter-group {
            display: flex;
            flex-direction: column;
            gap: 8px;
            min-width: 200px;
        }
        
        .filter-group label {
            font-size: 0.9rem;
            color: var(--gray-color);
            font-weight: 500;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .filter-group select,
        .filter-group input {
            padding: 12px 15px;
            border: 2px solid var(--border-color);
            border-radius: 8px;
            font-size: 0.95rem;
            transition: all 0.3s;
            background: white;
            color: var(--dark-color);
        }
        
        .filter-group select:focus,
        .filter-group input:focus {
            border-color: var(--primary-color);
            outline: none;
            box-shadow: 0 0 0 3px rgba(67, 97, 238, 0.1);
        }
        
        .filter-actions {
            display: flex;
            gap: 12px;
            align-items: flex-end;
        }
        
        /* KANBAN BOARD STYLES */
        .kanban-container {
            display: none;
        }
        
        .kanban-board {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 25px;
            margin-top: 20px;
        }
        
        .kanban-column {
            background: white;
            border-radius: 12px;
            padding: 20px;
            box-shadow: var(--card-shadow);
            border: 1px solid var(--border-color);
            transition: all 0.3s;
        }
        
        .kanban-column:hover {
            transform: translateY(-5px);
            box-shadow: var(--hover-shadow);
        }
        
        .kanban-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            padding-bottom: 15px;
            border-bottom: 2px solid var(--light-color);
        }
        
        .kanban-title {
            margin: 0;
            color: var(--dark-color);
            font-size: 1.2rem;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .kanban-count {
            background: var(--primary-color);
            color: white;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 0.9rem;
            font-weight: 600;
        }
        
        .kanban-tasks {
            display: flex;
            flex-direction: column;
            gap: 15px;
            min-height: 400px;
        }
        
        .kanban-task {
            background: white;
            border-radius: 10px;
            padding: 20px;
            box-shadow: var(--card-shadow);
            border: 1px solid var(--border-color);
            cursor: move;
            transition: all 0.3s;
            position: relative;
        }
        
        .kanban-task:hover {
            transform: translateY(-3px);
            box-shadow: var(--hover-shadow);
        }
        
        .kanban-task.dragging {
            opacity: 0.5;
            transform: rotate(5deg);
        }
        
        .task-priority {
            position: absolute;
            top: 10px;
            right: 10px;
            width: 10px;
            height: 10px;
            border-radius: 50%;
        }
        
        .priority-critical { background: #f44336; }
        .priority-high { background: #FF9800; }
        .priority-medium { background: #2196F3; }
        .priority-low { background: #4CAF50; }
        
        .task-header {
            margin-bottom: 15px;
        }
        
        .task-request-number {
            color: var(--primary-color);
            font-weight: 600;
            font-size: 0.9rem;
            margin-bottom: 5px;
        }
        
        .task-title {
            margin: 0;
            color: var(--dark-color);
            font-size: 1.1rem;
            font-weight: 600;
            line-height: 1.3;
        }
        
        .task-info {
            display: flex;
            flex-direction: column;
            gap: 8px;
            margin-bottom: 15px;
        }
        
        .task-info-item {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 0.9rem;
            color: var(--gray-color);
        }
        
        .task-actions {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
            margin-top: 15px;
            padding-top: 15px;
            border-top: 1px solid var(--border-color);
        }
        
        /* CALENDAR VIEW STYLES */
        .calendar-container {
            display: none;
            background: white;
            border-radius: 15px;
            padding: 25px;
            box-shadow: var(--card-shadow);
            margin-top: 20px;
        }
        
        .calendar-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 25px;
            padding-bottom: 20px;
            border-bottom: 2px solid var(--light-color);
        }
        
        .calendar-grid {
            display: grid;
            grid-template-columns: repeat(7, 1fr);
            gap: 1px;
            background: #eaeaea;
            border: 1px solid #eaeaea;
            border-radius: 8px;
            overflow: hidden;
        }
        
        .calendar-day-header {
            background: var(--primary-color);
            color: white;
            padding: 15px;
            text-align: center;
            font-weight: 500;
            text-transform: uppercase;
            font-size: 0.9rem;
        }
        
        .calendar-day {
            background: white;
            padding: 15px;
            min-height: 120px;
            border: 1px solid #eaeaea;
            position: relative;
            transition: all 0.3s;
        }
        
        .calendar-day:hover {
            background: #f8f9fa;
        }
        
        .calendar-day.other-month {
            background: #f8f9fa;
            color: #999;
        }
        
        .calendar-day.today {
            background: #e3f2fd;
            border-color: #2196F3;
        }
        
        .calendar-date {
            font-weight: bold;
            margin-bottom: 10px;
            color: var(--dark-color);
            font-size: 1.1rem;
        }
        
        .calendar-events {
            min-height: 80px;
        }
        
        .calendar-event {
            background: var(--primary-color);
            color: white;
            padding: 6px 10px;
            border-radius: 6px;
            margin-bottom: 5px;
            font-size: 0.85rem;
            cursor: pointer;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
            transition: all 0.3s;
        }
        
        .calendar-event.preventive {
            background: #2196F3;
        }
        
        .calendar-event.corrective {
            background: #FF9800;
        }
        
        .calendar-event:hover {
            transform: translateX(3px);
            box-shadow: 0 3px 6px rgba(0,0,0,0.1);
        }
        
        /* LIST VIEW STYLES */
        .list-container {
            display: none;
            background: white;
            border-radius: 15px;
            padding: 25px;
            box-shadow: var(--card-shadow);
            margin-top: 20px;
        }
        
        .requests-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
        }
        
        .requests-table th {
            background: var(--primary-color);
            color: white;
            padding: 15px;
            text-align: left;
            font-weight: 600;
            text-transform: uppercase;
            font-size: 0.9rem;
            letter-spacing: 0.5px;
        }
        
        .requests-table td {
            padding: 15px;
            border-bottom: 1px solid var(--border-color);
            vertical-align: top;
        }
        
        .requests-table tr:hover {
            background: #f8f9fa;
        }
        
        .status-badge {
            display: inline-block;
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 0.85rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .status-new { background: #e3f2fd; color: #1565c0; }
        .status-in_progress { background: #fff3e0; color: #ef6c00; }
        .status-repaired { background: #e8f5e9; color: #2e7d32; }
        .status-completed { background: #c8e6c9; color: #1b5e20; }
        
        .priority-badge {
            display: inline-block;
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 0.85rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .priority-critical { background: #ffebee; color: #c62828; }
        .priority-high { background: #fff3e0; color: #ef6c00; }
        .priority-medium { background: #e3f2fd; color: #1565c0; }
        .priority-low { background: #e8f5e9; color: #2e7d32; }
        
        /* MODAL STYLES */
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
            min-height: 100px;
            resize: vertical;
        }
        
        .modal-actions {
            display: flex;
            gap: 12px;
            justify-content: flex-end;
            margin-top: 25px;
        }
        
        .btn {
            padding: 12px 24px;
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
        
        .btn-sm {
            padding: 8px 16px;
            font-size: 0.85rem;
        }
        
        .required {
            color: #dc3545;
        }
        
        .loading-overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.5);
            z-index: 2000;
            justify-content: center;
            align-items: center;
        }
        
        .spinner {
            width: 50px;
            height: 50px;
            border: 5px solid #f3f3f3;
            border-top: 5px solid #3498db;
            border-radius: 50%;
            animation: spin 1s linear infinite;
        }
        
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
        
        /* RESPONSIVE STYLES */
        @media (max-width: 1200px) {
            .kanban-board {
                grid-template-columns: 1fr;
            }
        }
        
        @media (max-width: 768px) {
            .main-content {
                padding: 15px;
            }
            
            .technician-header {
                flex-direction: column;
                gap: 20px;
                padding: 20px;
                text-align: center;
            }
            
            .technician-info {
                flex-direction: column;
                text-align: center;
            }
            
            .filters {
                flex-direction: column;
            }
            
            .filter-group {
                min-width: 100%;
            }
            
            .filter-actions {
                width: 100%;
                justify-content: center;
            }
            
            .view-toggle-btn {
                padding: 10px 15px;
                font-size: 0.9rem;
            }
            
            .calendar-grid {
                overflow-x: auto;
            }
            
            .requests-table {
                display: block;
                overflow-x: auto;
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
            .stats-grid {
                grid-template-columns: 1fr;
            }
            
            .btn {
                width: 100%;
                justify-content: center;
            }
            
            .view-toggle-container {
                flex-direction: column;
            }
            
            .view-toggle-btn {
                width: 100%;
                justify-content: center;
            }
        }
    </style>
</head>
<body>
    <?php include '../includes/header.php'; ?>
    
    <div class="container">
        <?php include '../includes/sidebar.php'; ?>
        
        <main class="main-content">
            <!-- Success/Error Messages -->
            <div class="message-container">
                <?php if ($success_message): ?>
                    <div class="success-message">
                        <span><i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($success_message); ?></span>
                        <button onclick="this.parentElement.style.display='none'">&times;</button>
                    </div>
                <?php endif; ?>
                
                <?php if ($error_message): ?>
                    <div class="error-message">
                        <span><i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($error_message); ?></span>
                        <button onclick="this.parentElement.style.display='none'">&times;</button>
                    </div>
                <?php endif; ?>
            </div>
            
            <!-- Technician Header -->
            <div class="technician-header">
                <div class="technician-info">
                    <div class="technician-avatar">
                        <?php echo strtoupper(substr($_SESSION['full_name'] ?? 'T', 0, 1)); ?>
                    </div>
                    <div class="technician-details">
                        <h2><?php echo htmlspecialchars($_SESSION['full_name'] ?? 'Technician'); ?></h2>
                        <p><i class="fas fa-briefcase"></i> Technician Dashboard</p>
                        <p><i class="fas fa-calendar-alt"></i> <?php echo date('F j, Y'); ?></p>
                    </div>
                </div>
                
                <div class="technician-actions">
                    <a href="calendar.php" class="btn btn-secondary">
                        <i class="fas fa-calendar-alt"></i> Full Calendar
                    </a>
                </div>
            </div>
            
            <!-- Statistics -->
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-icon">
                        <i class="fas fa-tasks"></i>
                    </div>
                    <div class="stat-value"><?php echo $request_stats['total_requests']; ?></div>
                    <div class="stat-label">Total Requests</div>
                </div>
                
                <div class="stat-card">
                    <div class="stat-icon">
                        <i class="fas fa-clock"></i>
                    </div>
                    <div class="stat-value"><?php echo $request_stats['new_requests']; ?></div>
                    <div class="stat-label">New Requests</div>
                </div>
                
                <div class="stat-card">
                    <div class="stat-icon">
                        <i class="fas fa-cogs"></i>
                    </div>
                    <div class="stat-value"><?php echo $request_stats['in_progress_requests']; ?></div>
                    <div class="stat-label">In Progress</div>
                </div>
                
                <div class="stat-card">
                    <div class="stat-icon">
                        <i class="fas fa-check-circle"></i>
                    </div>
                    <div class="stat-value"><?php echo $request_stats['repaired_requests'] + $request_stats['completed_requests']; ?></div>
                    <div class="stat-label">Completed</div>
                </div>
                
                <div class="stat-card">
                    <div class="stat-icon">
                        <i class="fas fa-exclamation-triangle"></i>
                    </div>
                    <div class="stat-value"><?php echo $request_stats['critical_requests'] + $request_stats['high_requests']; ?></div>
                    <div class="stat-label">High Priority</div>
                </div>
                
                <div class="stat-card">
                    <div class="stat-icon">
                        <i class="fas fa-hourglass-half"></i>
                    </div>
                    <div class="stat-value"><?php echo number_format($request_stats['total_hours_worked'], 1); ?></div>
                    <div class="stat-label">Hours Worked</div>
                </div>
            </div>
            
            <!-- View Toggle -->
            <div class="view-toggle-container">
                <button class="view-toggle-btn <?php echo $filter_view == 'kanban' ? 'active' : ''; ?>" 
                        onclick="switchView('kanban')">
                    <i class="fas fa-columns"></i> Kanban Board
                </button>
                <button class="view-toggle-btn <?php echo $filter_view == 'calendar' ? 'active' : ''; ?>" 
                        onclick="switchView('calendar')">
                    <i class="fas fa-calendar-alt"></i> Calendar
                </button>
                <button class="view-toggle-btn <?php echo $filter_view == 'list' ? 'active' : ''; ?>" 
                        onclick="switchView('list')">
                    <i class="fas fa-list"></i> List View
                </button>
            </div>
            
            <!-- Filters -->
            <div class="filters-section">
                <h3><i class="fas fa-filter"></i> Filter Requests</h3>
                <form method="GET" action="">
                    <input type="hidden" name="view" value="<?php echo $filter_view; ?>">
                    
                    <div class="filters">
                        <div class="filter-group">
                            <label>Status</label>
                            <select name="status" id="filter_status">
                                <option value="all" <?php echo ($filter_status == 'all') ? 'selected' : ''; ?>>All Statuses</option>
                                <option value="New" <?php echo ($filter_status == 'New') ? 'selected' : ''; ?>>New</option>
                                <option value="In Progress" <?php echo ($filter_status == 'In Progress') ? 'selected' : ''; ?>>In Progress</option>
                                <option value="Repaired" <?php echo ($filter_status == 'Repaired') ? 'selected' : ''; ?>>Repaired</option>
                                <option value="Completed" <?php echo ($filter_status == 'Completed') ? 'selected' : ''; ?>>Completed</option>
                                <?php foreach (array_diff($all_statuses, ['New', 'In Progress', 'Repaired', 'Completed']) as $status): ?>
                                    <option value="<?php echo htmlspecialchars($status); ?>" <?php echo ($filter_status == $status) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($status); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="filter-group">
                            <label>Priority</label>
                            <select name="priority" id="filter_priority">
                                <option value="all" <?php echo ($filter_priority == 'all') ? 'selected' : ''; ?>>All Priorities</option>
                                <?php foreach ($priorities as $priority): ?>
                                    <option value="<?php echo $priority; ?>" <?php echo ($filter_priority == $priority) ? 'selected' : ''; ?>>
                                        <?php echo $priority; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="filter-group">
                            <label>Type</label>
                            <select name="type" id="filter_type">
                                <option value="all" <?php echo ($filter_type == 'all') ? 'selected' : ''; ?>>All Types</option>
                                <?php foreach ($maintenance_types as $type): ?>
                                    <option value="<?php echo htmlspecialchars($type); ?>" <?php echo ($filter_type == $type) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($type); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="filter-group">
                            <label>Date (Optional)</label>
                            <input type="date" name="date" value="<?php echo htmlspecialchars($filter_date); ?>">
                        </div>
                        
                        <div class="filter-actions">
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-filter"></i> Apply Filters
                            </button>
                            <a href="technician_dashboard.php?view=<?php echo $filter_view; ?>" class="btn btn-secondary">
                                <i class="fas fa-redo"></i> Reset
                            </a>
                        </div>
                    </div>
                </form>
            </div>
            
            <!-- KANBAN BOARD VIEW -->
            <div id="kanbanView" class="<?php echo $filter_view == 'kanban' ? '' : 'kanban-container'; ?>">
                <div class="kanban-board" id="kanbanBoard">
                    <!-- New Requests Column -->
                    <div class="kanban-column" id="new-column" ondrop="drop(event)" ondragover="allowDrop(event)">
                        <div class="kanban-header">
                            <h3 class="kanban-title">
                                <i class="fas fa-plus-circle" style="color: #1565c0;"></i> New
                            </h3>
                            <span class="kanban-count" id="new-count">
                                <?php 
                                $new_count = array_filter($assigned_requests, function($r) {
                                    return $r['status'] == 'New';
                                });
                                echo count($new_count);
                                ?>
                            </span>
                        </div>
                        <div class="kanban-tasks" id="new-tasks">
                            <?php foreach ($assigned_requests as $request): 
                                if ($request['status'] == 'New'): 
                                    $priority = strtolower($request['priority'] ?? 'medium');
                            ?>
                                <div class="kanban-task" id="task-<?php echo $request['id']; ?>" 
                                     draggable="true" ondragstart="drag(event)" 
                                     data-id="<?php echo $request['id']; ?>"
                                     data-status="New">
                                    <div class="task-priority priority-<?php echo $priority; ?>"></div>
                                    <div class="task-header">
                                        <div class="task-request-number">
                                            #<?php echo htmlspecialchars($request['request_number']); ?>
                                        </div>
                                        <h4 class="task-title">
                                            <?php echo htmlspecialchars($request['subject']); ?>
                                        </h4>
                                    </div>
                                    <div class="task-info">
                                        <div class="task-info-item">
                                            <i class="fas fa-cogs"></i>
                                            <span><?php echo htmlspecialchars($request['equipment_name'] ?? 'No equipment'); ?></span>
                                        </div>
                                        <div class="task-info-item">
                                            <i class="fas fa-map-marker-alt"></i>
                                            <span><?php echo htmlspecialchars($request['location'] ?? 'No location'); ?></span>
                                        </div>
                                        <div class="task-info-item">
                                            <i class="far fa-calendar-alt"></i>
                                            <span><?php echo date('M j, Y', strtotime($request['scheduled_date'])); ?></span>
                                        </div>
                                    </div>
                                    <div class="task-actions">
                                        <button class="btn btn-sm btn-primary" 
                                                onclick="showUpdateStatusModal(<?php echo $request['id']; ?>, 'In Progress')">
                                            <i class="fas fa-play"></i> Start
                                        </button>
                                        <button class="btn btn-sm btn-secondary" 
                                                onclick="showRequestDetails(<?php echo $request['id']; ?>)">
                                            <i class="fas fa-eye"></i> View
                                        </button>
                                    </div>
                                </div>
                            <?php endif; endforeach; ?>
                        </div>
                    </div>
                    
                    <!-- In Progress Column -->
                    <div class="kanban-column" id="in-progress-column" ondrop="drop(event)" ondragover="allowDrop(event)">
                        <div class="kanban-header">
                            <h3 class="kanban-title">
                                <i class="fas fa-cogs" style="color: #ef6c00;"></i> In Progress
                            </h3>
                            <span class="kanban-count" id="in-progress-count">
                                <?php 
                                $in_progress_count = array_filter($assigned_requests, function($r) {
                                    return $r['status'] == 'In Progress';
                                });
                                echo count($in_progress_count);
                                ?>
                            </span>
                        </div>
                        <div class="kanban-tasks" id="in-progress-tasks">
                            <?php foreach ($assigned_requests as $request): 
                                if ($request['status'] == 'In Progress'): 
                                    $priority = strtolower($request['priority'] ?? 'medium');
                            ?>
                                <div class="kanban-task" id="task-<?php echo $request['id']; ?>" 
                                     draggable="true" ondragstart="drag(event)" 
                                     data-id="<?php echo $request['id']; ?>"
                                     data-status="In Progress">
                                    <div class="task-priority priority-<?php echo $priority; ?>"></div>
                                    <div class="task-header">
                                        <div class="task-request-number">
                                            #<?php echo htmlspecialchars($request['request_number']); ?>
                                        </div>
                                        <h4 class="task-title">
                                            <?php echo htmlspecialchars($request['subject']); ?>
                                        </h4>
                                    </div>
                                    <div class="task-info">
                                        <div class="task-info-item">
                                            <i class="fas fa-cogs"></i>
                                            <span><?php echo htmlspecialchars($request['equipment_name'] ?? 'No equipment'); ?></span>
                                        </div>
                                        <div class="task-info-item">
                                            <i class="fas fa-clock"></i>
                                            <span><?php echo number_format($request['actual_hours'] ?? 0, 1); ?> hrs spent</span>
                                        </div>
                                        <div class="task-info-item">
                                            <i class="far fa-calendar-alt"></i>
                                            <span><?php echo date('M j, Y', strtotime($request['scheduled_date'])); ?></span>
                                        </div>
                                    </div>
                                    <div class="task-actions">
                                        <button class="btn btn-sm btn-success" 
                                                onclick="showUpdateStatusModal(<?php echo $request['id']; ?>, 'Repaired')">
                                            <i class="fas fa-check"></i> Mark Repaired
                                        </button>
                                        <button class="btn btn-sm btn-warning" 
                                                onclick="showAddWorkLogModal(<?php echo $request['id']; ?>)">
                                            <i class="fas fa-plus"></i> Add Time
                                        </button>
                                        <button class="btn btn-sm btn-secondary" 
                                                onclick="showRequestDetails(<?php echo $request['id']; ?>)">
                                            <i class="fas fa-eye"></i> View
                                        </button>
                                    </div>
                                </div>
                            <?php endif; endforeach; ?>
                        </div>
                    </div>
                    
                    <!-- Repaired Column -->
                    <div class="kanban-column" id="repaired-column" ondrop="drop(event)" ondragover="allowDrop(event)">
                        <div class="kanban-header">
                            <h3 class="kanban-title">
                                <i class="fas fa-check-circle" style="color: #2e7d32;"></i> Repaired
                            </h3>
                            <span class="kanban-count" id="repaired-count">
                                <?php 
                                $repaired_count = array_filter($assigned_requests, function($r) {
                                    return $r['status'] == 'Repaired';
                                });
                                echo count($repaired_count);
                                ?>
                            </span>
                        </div>
                        <div class="kanban-tasks" id="repaired-tasks">
                            <?php foreach ($assigned_requests as $request): 
                                if ($request['status'] == 'Repaired'): 
                                    $priority = strtolower($request['priority'] ?? 'medium');
                            ?>
                                <div class="kanban-task" id="task-<?php echo $request['id']; ?>" 
                                     draggable="true" ondragstart="drag(event)" 
                                     data-id="<?php echo $request['id']; ?>"
                                     data-status="Repaired">
                                    <div class="task-priority priority-<?php echo $priority; ?>"></div>
                                    <div class="task-header">
                                        <div class="task-request-number">
                                            #<?php echo htmlspecialchars($request['request_number']); ?>
                                        </div>
                                        <h4 class="task-title">
                                            <?php echo htmlspecialchars($request['subject']); ?>
                                        </h4>
                                    </div>
                                    <div class="task-info">
                                        <div class="task-info-item">
                                            <i class="fas fa-cogs"></i>
                                            <span><?php echo htmlspecialchars($request['equipment_name'] ?? 'No equipment'); ?></span>
                                        </div>
                                        <div class="task-info-item">
                                            <i class="fas fa-clock"></i>
                                            <span>Total: <?php echo number_format($request['actual_hours'] ?? 0, 1); ?> hrs</span>
                                        </div>
                                        <div class="task-info-item">
                                            <i class="far fa-calendar-alt"></i>
                                            <span>Completed: <?php echo !empty($request['completed_date']) ? date('M j, Y', strtotime($request['completed_date'])) : 'Not set'; ?></span>
                                        </div>
                                    </div>
                                    <div class="task-actions">
                                        <button class="btn btn-sm btn-secondary" 
                                                onclick="showRequestDetails(<?php echo $request['id']; ?>)">
                                            <i class="fas fa-eye"></i> View Details
                                        </button>
                                    </div>
                                </div>
                            <?php endif; endforeach; ?>
                        </div>
                    </div>
                    
                    <!-- Other Statuses Column -->
                    <div class="kanban-column" id="other-column">
                        <div class="kanban-header">
                            <h3 class="kanban-title">
                                <i class="fas fa-ellipsis-h" style="color: #666;"></i> Other
                            </h3>
                            <span class="kanban-count" id="other-count">
                                <?php 
                                $other_statuses = ['New', 'In Progress', 'Repaired', 'Completed'];
                                $other_count = array_filter($assigned_requests, function($r) use ($other_statuses) {
                                    return !in_array($r['status'], $other_statuses);
                                });
                                echo count($other_count);
                                ?>
                            </span>
                        </div>
                        <div class="kanban-tasks" id="other-tasks">
                            <?php foreach ($assigned_requests as $request): 
                                if (!in_array($request['status'], ['New', 'In Progress', 'Repaired', 'Completed'])): 
                                    $priority = strtolower($request['priority'] ?? 'medium');
                            ?>
                                <div class="kanban-task">
                                    <div class="task-priority priority-<?php echo $priority; ?>"></div>
                                    <div class="task-header">
                                        <div class="task-request-number">
                                            #<?php echo htmlspecialchars($request['request_number']); ?>
                                        </div>
                                        <h4 class="task-title">
                                            <?php echo htmlspecialchars($request['subject']); ?>
                                        </h4>
                                    </div>
                                    <div class="task-info">
                                        <div class="task-info-item">
                                            <i class="fas fa-cogs"></i>
                                            <span><?php echo htmlspecialchars($request['equipment_name'] ?? 'No equipment'); ?></span>
                                        </div>
                                        <div class="task-info-item">
                                            <i class="fas fa-tag"></i>
                                            <span class="status-badge status-<?php echo strtolower(str_replace(' ', '_', $request['status'])); ?>">
                                                <?php echo htmlspecialchars($request['status']); ?>
                                            </span>
                                        </div>
                                        <div class="task-info-item">
                                            <i class="far fa-calendar-alt"></i>
                                            <span><?php echo date('M j, Y', strtotime($request['scheduled_date'])); ?></span>
                                        </div>
                                    </div>
                                    <div class="task-actions">
                                        <button class="btn btn-sm btn-secondary" 
                                                onclick="showRequestDetails(<?php echo $request['id']; ?>)">
                                            <i class="fas fa-eye"></i> View
                                        </button>
                                    </div>
                                </div>
                            <?php endif; endforeach; ?>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- CALENDAR VIEW -->
            <div id="calendarView" class="<?php echo $filter_view == 'calendar' ? '' : 'calendar-container'; ?>">
                <div class="calendar-header">
                    <h2 style="margin: 0; color: var(--dark-color);">
                        <i class="fas fa-calendar-alt"></i> Preventive Maintenance Calendar
                    </h2>
                    <div style="display: flex; gap: 10px; align-items: center;">
                        <a href="?view=calendar&month=<?php echo $month - 1; ?>&year=<?php echo ($month == 1 ? $year - 1 : $year); 
                            echo $filter_status != 'all' ? '&status=' . urlencode($filter_status) : '';
                            echo $filter_priority != 'all' ? '&priority=' . urlencode($filter_priority) : '';
                            echo $filter_type != 'all' ? '&type=' . urlencode($filter_type) : '';
                            echo $filter_date ? '&date=' . urlencode($filter_date) : '';
                        ?>" class="btn btn-sm">← Previous</a>
                        <span style="font-weight: 600; color: var(--dark-color);">
                            <?php echo $month_name . ' ' . $year; ?>
                        </span>
                        <a href="?view=calendar&month=<?php echo $month + 1; ?>&year=<?php echo ($month == 12 ? $year + 1 : $year);
                            echo $filter_status != 'all' ? '&status=' . urlencode($filter_status) : '';
                            echo $filter_priority != 'all' ? '&priority=' . urlencode($filter_priority) : '';
                            echo $filter_type != 'all' ? '&type=' . urlencode($filter_type) : '';
                            echo $filter_date ? '&date=' . urlencode($filter_date) : '';
                        ?>" class="btn btn-sm">Next →</a>
                    </div>
                </div>
                
                <div class="calendar-grid">
                    <!-- Day headers -->
                    <div class="calendar-day-header">Sun</div>
                    <div class="calendar-day-header">Mon</div>
                    <div class="calendar-day-header">Tue</div>
                    <div class="calendar-day-header">Wed</div>
                    <div class="calendar-day-header">Thu</div>
                    <div class="calendar-day-header">Fri</div>
                    <div class="calendar-day-header">Sat</div>
                    
                    <!-- Empty days for first week -->
                    <?php for ($i = 0; $i < $first_day_of_month; $i++): ?>
                        <div class="calendar-day other-month"></div>
                    <?php endfor; ?>
                    
                    <!-- Days of the month -->
                    <?php for ($day = 1; $day <= $days_in_month; $day++): 
                        $is_today = ($day == date('j') && $month == date('n') && $year == date('Y'));
                        $date_str = $year . '-' . str_pad($month, 2, '0', STR_PAD_LEFT) . '-' . str_pad($day, 2, '0', STR_PAD_LEFT);
                        $day_tasks = $tasks_by_date[$date_str] ?? [];
                        $preventive_tasks = array_filter($day_tasks, function($task) {
                            return $task['type'] == 'Preventive';
                        });
                    ?>
                        <div class="calendar-day <?php echo $is_today ? 'today' : ''; ?>">
                            <div class="calendar-date"><?php echo $day; ?></div>
                            <div class="calendar-events">
                                <?php foreach ($preventive_tasks as $task): 
                                    $type = strtolower($task['type'] ?? 'preventive');
                                ?>
                                    <div class="calendar-event <?php echo $type; ?>" 
                                         onclick="showRequestDetails(<?php echo $task['id']; ?>)"
                                         title="<?php 
                                            echo htmlspecialchars(
                                                ($task['type'] ?? 'Maintenance') . 
                                                ': ' . ($task['subject'] ?? 'No Subject') . 
                                                ' (' . ($task['equipment_name'] ?? 'No Equipment') . ') - ' . 
                                                ($task['priority'] ?? 'Medium') . ' Priority - ' . 
                                                ($task['status'] ?? 'New')
                                            ); 
                                         ?>">
                                        <?php 
                                        $display_text = ($task['subject'] ?? 'PM');
                                        $truncated_text = substr($display_text, 0, 12);
                                        echo htmlspecialchars($truncated_text . (strlen($display_text) > 12 ? '...' : '')); 
                                        ?>
                                    </div>
                                <?php endforeach; ?>
                                
                                <?php if (empty($preventive_tasks)): ?>
                                    <div style="color: #999; font-size: 0.8rem; text-align: center; margin-top: 10px;">
                                        No preventive tasks
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endfor; ?>
                    
                    <!-- Empty days for last week -->
                    <?php 
                    $total_cells = $first_day_of_month + $days_in_month;
                    $empty_cells = (7 - ($total_cells % 7)) % 7;
                    for ($i = 0; $i < $empty_cells; $i++):
                    ?>
                        <div class="calendar-day other-month"></div>
                    <?php endfor; ?>
                </div>
            </div>
            
            <!-- LIST VIEW -->
            <div id="listView" class="<?php echo $filter_view == 'list' ? '' : 'list-container'; ?>">
                <h3 style="margin: 0 0 20px 0; color: var(--dark-color);">
                    <i class="fas fa-list"></i> All Assigned Requests (<?php echo count($assigned_requests); ?>)
                </h3>
                
                <?php if (!empty($assigned_requests)): ?>
                    <table class="requests-table">
                        <thead>
                            <tr>
                                <th>Request #</th>
                                <th>Subject</th>
                                <th>Equipment</th>
                                <th>Type</th>
                                <th>Priority</th>
                                <th>Status</th>
                                <th>Scheduled Date</th>
                                <th>Hours Spent</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($assigned_requests as $request): 
                                $priority = strtolower($request['priority'] ?? 'medium');
                                $status = strtolower(str_replace(' ', '_', $request['status'] ?? 'new'));
                            ?>
                                <tr>
                                    <td>
                                        <strong>#<?php echo htmlspecialchars($request['request_number']); ?></strong>
                                    </td>
                                    <td>
                                        <?php echo htmlspecialchars($request['subject']); ?>
                                    </td>
                                    <td>
                                        <?php echo htmlspecialchars($request['equipment_name'] ?? 'N/A'); ?>
                                    </td>
                                    <td>
                                        <?php echo htmlspecialchars($request['type'] ?? 'N/A'); ?>
                                    </td>
                                    <td>
                                        <span class="priority-badge priority-<?php echo $priority; ?>">
                                            <?php echo htmlspecialchars($request['priority']); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="status-badge status-<?php echo $status; ?>">
                                            <?php echo htmlspecialchars($request['status']); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php echo date('M j, Y', strtotime($request['scheduled_date'])); ?>
                                    </td>
                                    <td>
                                        <?php echo number_format($request['actual_hours'] ?? 0, 1); ?> hrs
                                    </td>
                                    <td>
                                        <div style="display: flex; gap: 5px;">
                                            <?php if ($request['status'] == 'New'): ?>
                                                <button class="btn btn-sm btn-primary" 
                                                        onclick="showUpdateStatusModal(<?php echo $request['id']; ?>, 'In Progress')">
                                                    <i class="fas fa-play"></i>
                                                </button>
                                            <?php elseif ($request['status'] == 'In Progress'): ?>
                                                <button class="btn btn-sm btn-success" 
                                                        onclick="showUpdateStatusModal(<?php echo $request['id']; ?>, 'Repaired')">
                                                    <i class="fas fa-check"></i>
                                                </button>
                                                <button class="btn btn-sm btn-warning" 
                                                        onclick="showAddWorkLogModal(<?php echo $request['id']; ?>)">
                                                    <i class="fas fa-clock"></i>
                                                </button>
                                            <?php endif; ?>
                                            <button class="btn btn-sm btn-secondary" 
                                                    onclick="showRequestDetails(<?php echo $request['id']; ?>)">
                                                <i class="fas fa-eye"></i>
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php else: ?>
                    <div class="empty-state" style="margin-top: 30px;">
                        <i class="fas fa-tasks"></i>
                        <h4>No Requests Found</h4>
                        <p>No requests match your current filters.</p>
                        <a href="technician_dashboard.php?view=list" class="btn btn-primary mt-2">
                            <i class="fas fa-redo"></i> Clear Filters
                        </a>
                    </div>
                <?php endif; ?>
            </div>
        </main>
    </div>
    
    <!-- Loading Overlay -->
    <div class="loading-overlay" id="loadingOverlay">
        <div class="spinner"></div>
    </div>
    
    <!-- Update Status Modal -->
    <div id="updateStatusModal" class="modal">
        <div class="modal-content">
            <h3><i class="fas fa-sync-alt"></i> Update Request Status</h3>
            <form method="POST" action="" id="updateStatusForm">
                <input type="hidden" name="request_id" id="statusRequestId">
                <input type="hidden" name="status" id="statusValue">
                
                <div class="form-group">
                    <label>New Status</label>
                    <div style="padding: 15px; background: #f8f9fa; border-radius: 8px; margin-bottom: 20px;">
                        <span id="statusDisplay" style="font-weight: 600; font-size: 1.1rem;"></span>
                    </div>
                </div>
                
                <div class="form-group">
                    <label>Hours Spent <span class="required">*</span></label>
                    <input type="number" name="hours_spent" required step="0.25" min="0.25" max="24" 
                           placeholder="Enter hours spent on this task" id="hoursSpent">
                </div>
                
                <div class="form-group">
                    <label>Work Notes</label>
                    <textarea name="work_notes" placeholder="Add notes about the work performed..." 
                              rows="4" id="workNotes"></textarea>
                </div>
                
                <div class="modal-actions">
                    <button type="button" class="btn btn-secondary" onclick="hideModal('updateStatusModal')">Cancel</button>
                    <button type="submit" name="update_request_status" class="btn btn-primary">Update Status</button>
                </div>
            </form>
        </div>
    </div>
    
    <!-- Add Work Log Modal -->
    <div id="addWorkLogModal" class="modal">
        <div class="modal-content">
            <h3><i class="fas fa-clock"></i> Add Work Log</h3>
            <form method="POST" action="" id="addWorkLogForm">
                <input type="hidden" name="request_id" id="workLogRequestId">
                
                <div class="form-group">
                    <label>Work Description <span class="required">*</span></label>
                    <textarea name="work_description" required 
                              placeholder="Describe the work performed..." rows="5"></textarea>
                </div>
                
                <div class="form-group">
                    <label>Hours Worked <span class="required">*</span></label>
                    <input type="number" name="hours_worked" required step="0.25" min="0.25" max="24" 
                           placeholder="e.g., 2.5">
                </div>
                
                <div class="form-group">
                    <label>Parts Used (Optional)</label>
                    <textarea name="parts_used" placeholder="List any parts used..." rows="3"></textarea>
                </div>
                
                <div class="modal-actions">
                    <button type="button" class="btn btn-secondary" onclick="hideModal('addWorkLogModal')">Cancel</button>
                    <button type="submit" name="add_work_log" class="btn btn-primary">Add Work Log</button>
                </div>
            </form>
        </div>
    </div>
    
    <script>
        // View switching
        function switchView(viewMode) {
            // Hide all views
            document.getElementById('kanbanView').classList.add('kanban-container');
            document.getElementById('calendarView').classList.add('calendar-container');
            document.getElementById('listView').classList.add('list-container');
            
            // Show selected view
            document.getElementById(viewMode + 'View').classList.remove(
                viewMode === 'kanban' ? 'kanban-container' : 
                viewMode === 'calendar' ? 'calendar-container' : 'list-container'
            );
            
            // Update active button
            document.querySelectorAll('.view-toggle-btn').forEach(btn => {
                btn.classList.remove('active');
            });
            event.target.classList.add('active');
            
            // Update URL
            let url = `technician_dashboard.php?view=${viewMode}`;
            const params = new URLSearchParams(window.location.search);
            
            if (params.get('status')) url += `&status=${params.get('status')}`;
            if (params.get('priority')) url += `&priority=${params.get('priority')}`;
            if (params.get('type')) url += `&type=${params.get('type')}`;
            if (params.get('date')) url += `&date=${params.get('date')}`;
            if (params.get('month')) url += `&month=${params.get('month')}`;
            if (params.get('year')) url += `&year=${params.get('year')}`;
            
            window.history.pushState({}, '', url);
        }
        
        // Initialize view based on URL parameter
        document.addEventListener('DOMContentLoaded', function() {
            const view = '<?php echo $filter_view; ?>';
            if (view) {
                switchView(view);
                
                // Set active button
                document.querySelectorAll('.view-toggle-btn').forEach(btn => {
                    btn.classList.remove('active');
                });
                document.querySelector(`.view-toggle-btn[onclick*="${view}"]`).classList.add('active');
            }
            
            // Auto-hide messages after 5 seconds
            setTimeout(function() {
                const messages = document.querySelectorAll('.success-message, .error-message');
                messages.forEach(message => {
                    message.style.opacity = '0';
                    setTimeout(() => {
                        message.style.display = 'none';
                    }, 300);
                });
            }, 5000);
        });
        
        // Drag and drop for Kanban board
        let draggedTask = null;
        
        function allowDrop(ev) {
            ev.preventDefault();
        }
        
        function drag(ev) {
            draggedTask = ev.target;
            draggedTask.classList.add('dragging');
            ev.dataTransfer.setData("text", ev.target.id);
        }
        
        function drop(ev) {
            ev.preventDefault();
            const targetColumn = ev.target.closest('.kanban-column');
            
            if (targetColumn && draggedTask) {
                const taskId = draggedTask.dataset.id;
                const newStatus = targetColumn.id.replace('-column', '').replace('-', ' ');
                const oldStatus = draggedTask.dataset.status;
                
                if (newStatus !== oldStatus) {
                    // Update status via AJAX
                    updateTaskStatus(taskId, newStatus, draggedTask);
                    
                    // Move task visually
                    const tasksContainer = targetColumn.querySelector('.kanban-tasks');
                    tasksContainer.appendChild(draggedTask);
                    draggedTask.dataset.status = newStatus;
                    
                    // Update counts
                    updateKanbanCounts();
                }
            }
            
            if (draggedTask) {
                draggedTask.classList.remove('dragging');
                draggedTask = null;
            }
        }
        
        function updateTaskStatus(taskId, newStatus, taskElement) {
            showLoading();
            
            const formData = new FormData();
            formData.append('request_id', taskId);
            formData.append('status', newStatus);
            formData.append('hours_spent', 0.5); // Default 0.5 hours for status change
            formData.append('work_notes', `Status changed via Kanban board`);
            formData.append('update_request_status', '1');
            
            fetch('', {
                method: 'POST',
                body: formData
            })
            .then(response => response.text())
            .then(data => {
                hideLoading();
                // Reload page to show updated data
                window.location.reload();
            })
            .catch(error => {
                hideLoading();
                console.error('Error:', error);
                alert('Error updating task status. Please try again.');
            });
        }
        
        function updateKanbanCounts() {
            // This would be called after successful AJAX update
            // For now, we'll reload the page
        }
        
        // Modal functions
        function showUpdateStatusModal(requestId, newStatus) {
            document.getElementById('statusRequestId').value = requestId;
            document.getElementById('statusValue').value = newStatus;
            
            let statusText = '';
            switch(newStatus) {
                case 'In Progress':
                    statusText = 'Start Task → In Progress';
                    document.getElementById('hoursSpent').value = '0.5';
                    document.getElementById('workNotes').value = 'Started working on the task';
                    break;
                case 'Repaired':
                    statusText = 'Mark as Repaired';
                    document.getElementById('hoursSpent').value = '1.0';
                    document.getElementById('workNotes').value = 'Task completed and equipment repaired';
                    break;
                default:
                    statusText = newStatus;
            }
            
            document.getElementById('statusDisplay').textContent = statusText;
            document.getElementById('updateStatusModal').style.display = 'flex';
        }
        
        function showAddWorkLogModal(requestId) {
            document.getElementById('workLogRequestId').value = requestId;
            document.getElementById('addWorkLogModal').style.display = 'flex';
        }
        
        function showRequestDetails(requestId) {
            window.location.href = `maintenance_request.php?id=${requestId}`;
        }
        
        function hideModal(modalId) {
            document.getElementById(modalId).style.display = 'none';
        }
        
        function showLoading() {
            document.getElementById('loadingOverlay').style.display = 'flex';
        }
        
        function hideLoading() {
            document.getElementById('loadingOverlay').style.display = 'none';
        }
        
        // Close modal when clicking outside
        window.onclick = function(event) {
            if (event.target.classList.contains('modal')) {
                event.target.style.display = 'none';
            }
        };
        
        // Close modal with Escape key
        document.addEventListener('keydown', function(event) {
            if (event.key === 'Escape') {
                const modals = document.querySelectorAll('.modal');
                modals.forEach(modal => {
                    if (modal.style.display === 'flex') {
                        modal.style.display = 'none';
                    }
                });
            }
        });
        
        // Form validation
        document.addEventListener('DOMContentLoaded', function() {
            const updateStatusForm = document.getElementById('updateStatusForm');
            if (updateStatusForm) {
                updateStatusForm.addEventListener('submit', function(e) {
                    const hours = document.getElementById('hoursSpent');
                    if (hours && (hours.value <= 0 || hours.value > 24)) {
                        e.preventDefault();
                        alert('Please enter valid hours (0.25 to 24).');
                        return;
                    }
                    showLoading();
                });
            }
            
            const addWorkLogForm = document.getElementById('addWorkLogForm');
            if (addWorkLogForm) {
                addWorkLogForm.addEventListener('submit', function(e) {
                    const hours = this.querySelector('input[name="hours_worked"]');
                    if (hours && (hours.value <= 0 || hours.value > 24)) {
                        e.preventDefault();
                        alert('Please enter valid hours worked (0.25 to 24).');
                        return;
                    }
                    showLoading();
                });
            }
        });
    </script>
</body>
</html>