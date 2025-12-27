<?php
session_start();

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: ../index.php');
    exit();
}

// Database connection
$conn = mysqli_connect("localhost", "root", "", "gear_guard");

if (!$conn) {
    die("Database connection failed");
}

// ==================== HANDLE RESCHEDULE FORM SUBMISSION ====================
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['reschedule_maintenance'])) {
    $event_id = intval($_POST['event_id']);
    $new_scheduled_date = mysqli_real_escape_string($conn, $_POST['new_scheduled_date']);
    $reschedule_reason = mysqli_real_escape_string($conn, $_POST['reschedule_reason']);
    $reschedule_notes = mysqli_real_escape_string($conn, $_POST['reschedule_notes']);
    $user_id = $_SESSION['user_id'];
    
    // Get current date for logging
    $current_date_query = "SELECT scheduled_date FROM maintenance_requests WHERE id = $event_id";
    $current_date_result = mysqli_query($conn, $current_date_query);
    $current_date = '';
    if ($current_date_result && $row = mysqli_fetch_assoc($current_date_result)) {
        $current_date = $row['scheduled_date'];
    }
    
    // Update the scheduled date
    $update_query = "UPDATE maintenance_requests SET scheduled_date = '$new_scheduled_date', updated_at = NOW() WHERE id = $event_id";
    
    if (mysqli_query($conn, $update_query)) {
        // Log the reschedule action
        $log_query = "INSERT INTO maintenance_comments (request_id, user_id, comment) 
                     VALUES ($event_id, $user_id, 
                     'Rescheduled from $current_date to $new_scheduled_date. Reason: $reschedule_reason" . 
                     (!empty($reschedule_notes) ? " - $reschedule_notes" : "") . "')";
        mysqli_query($conn, $log_query);
        
        // Store success message in session
        $_SESSION['success_message'] = "Maintenance request #$event_id has been rescheduled to " . date('F j, Y', strtotime($new_scheduled_date));
        
        // Refresh the page to show updated calendar
        header("Location: calendar.php?month=" . date('n', strtotime($new_scheduled_date)) . "&year=" . date('Y', strtotime($new_scheduled_date)));
        exit();
    } else {
        $_SESSION['error_message'] = "Failed to reschedule: " . mysqli_error($conn);
    }
}

// ==================== HANDLE ASSIGN FORM SUBMISSION ====================
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['assign_technician'])) {
    $event_id = intval($_POST['event_id']);
    $assigned_to = intval($_POST['assigned_to']);
    $assignment_notes = mysqli_real_escape_string($conn, $_POST['assignment_notes']);
    $expected_completion_date = mysqli_real_escape_string($conn, $_POST['expected_completion_date']);
    $status = mysqli_real_escape_string($conn, $_POST['status']);
    $user_id = $_SESSION['user_id'];
    
    // Get technician name
    $tech_query = "SELECT full_name, username FROM users WHERE id = $assigned_to";
    $tech_result = mysqli_query($conn, $tech_query);
    $tech_name = 'Unknown Technician';
    if ($tech_result && $row = mysqli_fetch_assoc($tech_result)) {
        $tech_name = $row['full_name'] ?: $row['username'];
    }
    
    // Build update query
    $update_query = "UPDATE maintenance_requests SET assigned_to = $assigned_to, updated_at = NOW()";
    if (!empty($status)) {
        $update_query .= ", status = '$status'";
    }
    if (!empty($expected_completion_date)) {
        $update_query .= ", expected_completion_date = '$expected_completion_date'";
    }
    $update_query .= " WHERE id = $event_id";
    
    if (mysqli_query($conn, $update_query)) {
        // Log the assignment
        $log_query = "INSERT INTO maintenance_comments (request_id, user_id, comment) 
                     VALUES ($event_id, $user_id, 
                     'Assigned to: $tech_name" . 
                     (!empty($assignment_notes) ? " - $assignment_notes" : "") . 
                     (!empty($expected_completion_date) ? " - Expected completion: $expected_completion_date" : "") . "')";
        mysqli_query($conn, $log_query);
        
        $_SESSION['success_message'] = "Technician assigned to maintenance request #$event_id";
        
        // Refresh the page
        header("Location: calendar.php");
        exit();
    } else {
        $_SESSION['error_message'] = "Failed to assign technician: " . mysqli_error($conn);
    }
}

// ==================== HANDLE SCHEDULE FORM SUBMISSION ====================
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['schedule_maintenance'])) {
    $type = mysqli_real_escape_string($conn, $_POST['type']);
    $equipment_id = intval($_POST['equipment_id']);
    $subject = mysqli_real_escape_string($conn, $_POST['subject']);
    $description = mysqli_real_escape_string($conn, $_POST['description']);
    $scheduled_date = mysqli_real_escape_string($conn, $_POST['scheduled_date']);
    $priority = mysqli_real_escape_string($conn, $_POST['priority']);
    $status = mysqli_real_escape_string($conn, $_POST['status']);
    $assigned_to = !empty($_POST['assigned_to']) ? intval($_POST['assigned_to']) : NULL;
    $duration_hours = !empty($_POST['duration_hours']) ? floatval($_POST['duration_hours']) : 1.0;
    $user_id = $_SESSION['user_id'];
    
    // Generate request number
    $request_number = 'MR-' . date('Ymd') . '-' . str_pad(rand(1, 9999), 4, '0', STR_PAD_LEFT);
    
    $insert_query = "INSERT INTO maintenance_requests 
                    (request_number, type, equipment_id, subject, description, 
                     scheduled_date, priority, status, assigned_to, 
                     duration_hours, created_by, created_at, updated_at) 
                    VALUES 
                    ('$request_number', '$type', $equipment_id, '$subject', '$description', 
                     '$scheduled_date', '$priority', '$status', " . 
                     ($assigned_to ? "$assigned_to" : "NULL") . ", 
                     $duration_hours, $user_id, NOW(), NOW())";
    
    if (mysqli_query($conn, $insert_query)) {
        $new_request_id = mysqli_insert_id($conn);
        
        // Log the creation
        $log_query = "INSERT INTO maintenance_comments (request_id, user_id, comment) 
                     VALUES ($new_request_id, $user_id, 'Maintenance scheduled for $scheduled_date. Priority: $priority')";
        mysqli_query($conn, $log_query);
        
        $_SESSION['success_message'] = "Maintenance scheduled successfully! Request #$request_number";
        
        // Refresh the page
        header("Location: calendar.php?month=" . date('n', strtotime($scheduled_date)) . "&year=" . date('Y', strtotime($scheduled_date)));
        exit();
    } else {
        $_SESSION['error_message'] = "Failed to schedule maintenance: " . mysqli_error($conn);
    }
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

// Get current month and year
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

// Get all maintenance types from database for filter
$maintenance_types = [];
$type_result = mysqli_query($conn, "SELECT DISTINCT type FROM maintenance_requests WHERE type IS NOT NULL AND type != ''");
if ($type_result) {
    while ($row = mysqli_fetch_assoc($type_result)) {
        $maintenance_types[] = $row['type'];
    }
}

// Get all equipment for filter
$equipment_list = [];
$equip_result = mysqli_query($conn, "SELECT id, name FROM equipment WHERE status = 'active' OR status IS NULL ORDER BY name");
if ($equip_result) {
    while ($row = mysqli_fetch_assoc($equip_result)) {
        $equipment_list[] = $row;
    }
}

// Get all technicians for assignment
$technicians = [];
$tech_result = mysqli_query($conn, "
    SELECT id, full_name, username, role 
    FROM users 
    WHERE (role = 'technician' OR role = 'admin') 
    AND status = 'active'
    ORDER BY full_name
");

if ($tech_result) {
    while ($row = mysqli_fetch_assoc($tech_result)) {
        $technicians[] = $row;
    }
}

// Get all distinct statuses from maintenance_requests
$statuses = [];
$status_result = mysqli_query($conn, "SELECT DISTINCT status FROM maintenance_requests WHERE status IS NOT NULL AND status != '' ORDER BY status");
if ($status_result) {
    while ($row = mysqli_fetch_assoc($status_result)) {
        $statuses[] = $row['status'];
    }
}

// Get all priority levels
$priorities = ['Low', 'Medium', 'High', 'Critical'];

// Get filter parameters
$filter_type = isset($_GET['filter_type']) ? $_GET['filter_type'] : '';
$filter_equipment = isset($_GET['filter_equipment']) ? intval($_GET['filter_equipment']) : 0;
$filter_priority = isset($_GET['filter_priority']) ? $_GET['filter_priority'] : '';
$filter_status = isset($_GET['filter_status']) ? $_GET['filter_status'] : '';

// Build filter conditions
$filter_conditions = [];
if (!empty($filter_type) && $filter_type != 'all') {
    $filter_conditions[] = "mr.type = '" . mysqli_real_escape_string($conn, $filter_type) . "'";
}
if ($filter_equipment > 0) {
    $filter_conditions[] = "mr.equipment_id = $filter_equipment";
}
if (!empty($filter_priority) && $filter_priority != 'all') {
    $filter_conditions[] = "mr.priority = '" . mysqli_real_escape_string($conn, $filter_priority) . "'";
}
if (!empty($filter_status) && $filter_status != 'all') {
    $filter_conditions[] = "mr.status = '" . mysqli_real_escape_string($conn, $filter_status) . "'";
}

$filter_sql = '';
if (!empty($filter_conditions)) {
    $filter_sql = ' AND ' . implode(' AND ', $filter_conditions);
}

// Get maintenance for the month
$first_day = date('Y-m-01', mktime(0, 0, 0, $month, 1, $year));
$last_day = date('Y-m-t', mktime(0, 0, 0, $month, 1, $year));

// Get all maintenance events for the month
$maintenance_events = [];

$query = "
    SELECT mr.*, 
           e.name as equipment_name, 
           e.location,
           e.serial_number,
           u.full_name as assigned_technician_name,
           u.username as technician_username,
           u.id as technician_id,
           u.role as technician_role
    FROM maintenance_requests mr
    LEFT JOIN equipment e ON mr.equipment_id = e.id
    LEFT JOIN users u ON mr.assigned_to = u.id
    WHERE mr.scheduled_date BETWEEN '$first_day' AND '$last_day'
    $filter_sql
    ORDER BY 
        CASE 
            WHEN mr.priority = 'Critical' THEN 1
            WHEN mr.priority = 'High' THEN 2
            WHEN mr.priority = 'Medium' THEN 3
            WHEN mr.priority = 'Low' THEN 4
            ELSE 5
        END,
        mr.scheduled_date,
        mr.created_at DESC
";

$result = mysqli_query($conn, $query);

if ($result) {
    while ($row = mysqli_fetch_assoc($result)) {
        $maintenance_events[] = $row;
    }
}

// Get statistics for the month
$stats_query = "
    SELECT 
        COUNT(*) as total_count,
        SUM(CASE WHEN status IN ('Repaired', 'Completed', 'Finished') THEN 1 ELSE 0 END) as completed_count,
        SUM(CASE WHEN priority = 'Critical' THEN 1 ELSE 0 END) as critical_count,
        SUM(CASE WHEN priority = 'High' THEN 1 ELSE 0 END) as high_count,
        SUM(CASE WHEN priority = 'Medium' THEN 1 ELSE 0 END) as medium_count,
        SUM(CASE WHEN priority = 'Low' THEN 1 ELSE 0 END) as low_count,
        SUM(CASE WHEN status IN ('New', 'Pending', 'Assigned', 'In Progress', 'Scheduled') THEN 1 ELSE 0 END) as pending_count,
        SUM(CASE WHEN type = 'Preventive' THEN 1 ELSE 0 END) as preventive_count,
        SUM(CASE WHEN type = 'Corrective' THEN 1 ELSE 0 END) as corrective_count
    FROM maintenance_requests mr
    WHERE mr.scheduled_date BETWEEN '$first_day' AND '$last_day'
    $filter_sql
";

$stats_result = mysqli_query($conn, $stats_query);
$month_stats = [
    'total_count' => 0, 
    'completed_count' => 0, 
    'critical_count' => 0,
    'high_count' => 0, 
    'pending_count' => 0,
    'preventive_count' => 0,
    'corrective_count' => 0
];

if ($stats_result && $row = mysqli_fetch_assoc($stats_result)) {
    $month_stats = $row;
}

// Get type statistics
$type_stats = [];
$sql = "SELECT type, COUNT(*) as type_count 
        FROM maintenance_requests 
        WHERE scheduled_date BETWEEN '$first_day' AND '$last_day'
        AND priority = '$filter_priority'  -- This line causes error
        GROUP BY type";
$type_stats_query = mysqli_query($conn,$sql);
if ($type_stats_query) {
    while ($row = mysqli_fetch_assoc($type_stats_query)) {
        $type_stats[$row['type']] = $row['type_count'];
    }
}

// Get equipment with most maintenance
$equipment_stats = [];
$equipment_stats_query = mysqli_query($conn, "
    SELECT e.name, COUNT(mr.id) as maintenance_count
    FROM equipment e
    LEFT JOIN maintenance_requests mr ON e.id = mr.equipment_id
    WHERE mr.scheduled_date BETWEEN '$first_day' AND '$last_day'
    $filter_sql
    GROUP BY e.id
    ORDER BY maintenance_count DESC
    LIMIT 5
");
if ($equipment_stats_query) {
    while ($row = mysqli_fetch_assoc($equipment_stats_query)) {
        $equipment_stats[] = $row;
    }
}

// Group by date for calendar
$events_by_date = [];
foreach ($maintenance_events as $event) {
    $date = $event['scheduled_date'];
    if ($date) {
        if (!isset($events_by_date[$date])) {
            $events_by_date[$date] = [];
        }
        $events_by_date[$date][] = $event;
    }
}

// Get upcoming events (next 7 days) for sidebar
$today = date('Y-m-d');
$week_from_now = date('Y-m-d', strtotime('+7 days'));
$upcoming_query = "
    SELECT mr.*, 
           e.name as equipment_name, 
           e.location,
           e.serial_number,
           u.full_name as assigned_technician_name,
           u.username as technician_username
    FROM maintenance_requests mr
    LEFT JOIN equipment e ON mr.equipment_id = e.id
    LEFT JOIN users u ON mr.assigned_to = u.id
    WHERE mr.scheduled_date BETWEEN '$today' AND '$week_from_now'
    AND mr.status NOT IN ('Repaired', 'Completed', 'Finished', 'Cancelled')
    $filter_sql
    ORDER BY 
        CASE 
            WHEN mr.priority = 'Critical' THEN 1
            WHEN mr.priority = 'High' THEN 2
            WHEN mr.priority = 'Medium' THEN 3
            WHEN mr.priority = 'Low' THEN 4
            ELSE 5
        END,
        mr.scheduled_date
    LIMIT 10
";

$upcoming_result = mysqli_query($conn, $upcoming_query);
$upcoming_events = [];
if ($upcoming_result) {
    while ($row = mysqli_fetch_assoc($upcoming_result)) {
        $upcoming_events[] = $row;
    }
}

mysqli_close($conn);

// Calendar calculations
$month_name = date('F', mktime(0, 0, 0, $month, 1, $year));
$days_in_month = date('t', mktime(0, 0, 0, $month, 1, $year));
$first_day_of_month = date('w', mktime(0, 0, 0, $month, 1, $year));
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Maintenance Calendar - GearGuard</title>
    <link rel="stylesheet" href="../css/style.css">
    <style>
        /* Add message styles */
        .message-container {
            margin-bottom: 20px;
        }
        
        .success-message {
            background: #d4edda;
            color: #155724;
            padding: 12px 20px;
            border-radius: 4px;
            margin-bottom: 15px;
            border-left: 4px solid #28a745;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .error-message {
            background: #f8d7da;
            color: #721c24;
            padding: 12px 20px;
            border-radius: 4px;
            margin-bottom: 15px;
            border-left: 4px solid #dc3545;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .message-container button {
            background: none;
            border: none;
            color: inherit;
            cursor: pointer;
            font-size: 1.2rem;
        }
        
        /* Existing styles remain the same */
        .calendar-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 30px;
            background: white;
            padding: 20px;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.08);
        }
        
        .calendar-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 30px;
            background: white;
            padding: 20px;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.08);
        }
        
        .month-navigation {
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .calendar-grid {
            display: grid;
            grid-template-columns: repeat(7, 1fr);
            gap: 1px;
            background: #eaeaea;
            border: 1px solid #eaeaea;
            border-radius: 8px;
            overflow: hidden;
            margin-bottom: 30px;
        }
        
        .calendar-day-header {
            background: #667eea;
            color: white;
            padding: 15px;
            text-align: center;
            font-weight: 500;
            text-transform: uppercase;
            font-size: 0.9rem;
        }
        
        .calendar-day {
            background: white;
            padding: 10px;
            min-height: 120px;
            border: 1px solid #eaeaea;
            position: relative;
            transition: all 0.3s;
            cursor: pointer;
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
            margin-bottom: 8px;
            color: #333;
            font-size: 1.1rem;
        }
        
        .calendar-events {
            min-height: 80px;
        }
        
        .calendar-event {
            color: white;
            padding: 4px 8px;
            border-radius: 4px;
            margin-bottom: 3px;
            font-size: 0.8rem;
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
        
        .calendar-event.critical {
            background: #f44336;
        }
        
        .calendar-event.emergency {
            background: #d32f2f;
        }
        
        .calendar-event.other {
            background: #9c27b0;
        }
        
        .calendar-event.new {
            background: #9e9e9e;
        }
        
        .calendar-event.assigned {
            background: #FF9800;
        }
        
        .calendar-event.in_progress {
            background: #2196F3;
        }
        
        .calendar-event.repaired {
            background: #4CAF50;
        }
        
        .calendar-event.more {
            background: #607d8b;
            font-style: italic;
        }
        
        .events-sidebar {
            width: 350px;
            background: white;
            padding: 25px;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.08);
            height: fit-content;
            position: sticky;
            top: 100px;
        }
        
        .calendar-layout {
            display: flex;
            gap: 30px;
            margin-top: 20px;
        }
        
        .calendar-main {
            flex: 1;
        }
        
        .event-item {
            background: white;
            padding: 15px;
            border-radius: 6px;
            margin-bottom: 10px;
            border-left: 4px solid #2196F3;
            box-shadow: 0 2px 5px rgba(0,0,0,0.05);
            transition: all 0.3s;
        }
        
        .event-item:hover {
            transform: translateX(5px);
            box-shadow: 0 3px 10px rgba(0,0,0,0.1);
        }
        
        .event-date {
            color: #667eea;
            font-weight: 500;
            margin-bottom: 5px;
            font-size: 0.9rem;
        }
        
        .filters {
            display: flex;
            gap: 10px;
            margin-bottom: 20px;
            flex-wrap: wrap;
            background: white;
            padding: 15px;
            border-radius: 8px;
            box-shadow: 0 2px 5px rgba(0,0,0,0.05);
        }
        
        .filter-group {
            display: flex;
            flex-direction: column;
            gap: 5px;
        }
        
        .filter-group label {
            font-size: 0.8rem;
            color: #666;
            font-weight: 500;
        }
        
        .filter-group select {
            padding: 8px 12px;
            border: 1px solid #ddd;
            border-radius: 4px;
            background: white;
            min-width: 150px;
        }
        
        .filter-actions {
            display: flex;
            gap: 10px;
            align-items: flex-end;
        }
        
        .view-toggle {
            display: flex;
            gap: 10px;
            margin-bottom: 20px;
        }
        
        .view-toggle button {
            padding: 8px 16px;
            border: 1px solid #ddd;
            background: white;
            border-radius: 4px;
            cursor: pointer;
            transition: all 0.3s;
        }
        
        .view-toggle button.active {
            background: #667eea;
            color: white;
            border-color: #667eea;
        }
        
        .event-badge {
            display: inline-block;
            padding: 2px 8px;
            border-radius: 10px;
            font-size: 0.75rem;
            font-weight: 500;
            margin-left: 5px;
        }
        
        .badge-critical { background: #ffebee; color: #c62828; border: 1px solid #ffcdd2; }
        .badge-high { background: #fff3e0; color: #ef6c00; border: 1px solid #ffe0b2; }
        .badge-medium { background: #e3f2fd; color: #1565c0; border: 1px solid #bbdefb; }
        .badge-low { background: #e8f5e9; color: #2e7d32; border: 1px solid #c8e6c9; }
        
        .legend {
            display: flex;
            gap: 15px;
            margin: 20px 0;
            flex-wrap: wrap;
            background: white;
            padding: 15px;
            border-radius: 8px;
            box-shadow: 0 2px 5px rgba(0,0,0,0.05);
        }
        
        .legend-item {
            display: flex;
            align-items: center;
            gap: 5px;
            font-size: 0.85rem;
            color: #666;
        }
        
        .legend-color {
            width: 12px;
            height: 12px;
            border-radius: 2px;
        }
        
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 15px;
            margin-bottom: 20px;
        }
        
        .stat-card {
            background: white;
            padding: 15px;
            border-radius: 8px;
            text-align: center;
            box-shadow: 0 2px 5px rgba(0,0,0,0.05);
        }
        
        .stat-value {
            font-size: 1.5rem;
            font-weight: bold;
            margin-bottom: 5px;
        }
        
        .stat-label {
            font-size: 0.85rem;
            color: #666;
        }
        
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.5);
            z-index: 1000;
            align-items: center;
            justify-content: center;
        }
        
        .modal-content {
            background: white;
            padding: 30px;
            border-radius: 10px;
            max-width: 500px;
            width: 90%;
            max-height: 80vh;
            overflow-y: auto;
        }
        
        .form-group {
            margin-bottom: 15px;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 5px;
            font-weight: 500;
            color: #333;
        }
        
        .form-group input,
        .form-group select,
        .form-group textarea {
            width: 100%;
            padding: 8px 12px;
            border: 1px solid #ddd;
            border-radius: 4px;
        }
        
        .form-group textarea {
            min-height: 100px;
            resize: vertical;
        }
        
        .modal-actions {
            display: flex;
            gap: 10px;
            justify-content: flex-end;
            margin-top: 20px;
        }
        
        .btn {
            padding: 8px 16px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            text-decoration: none;
            display: inline-block;
            text-align: center;
            font-size: 14px;
        }
        
        .btn-sm {
            padding: 6px 12px;
            font-size: 0.85rem;
        }
        
        .btn-primary {
            background: #667eea;
            color: white;
        }
        
        .btn-primary:hover {
            background: #5a67d8;
        }
        
        .btn-success {
            background: #4CAF50;
            color: white;
        }
        
        .btn-success:hover {
            background: #45a049;
        }
        
        .mt-2 {
            margin-top: 10px;
        }
        
        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }
        
        .page-header h1 {
            margin: 0;
            color: #333;
        }
        
        .container {
            display: flex;
            min-height: calc(100vh - 60px);
        }
        
        .main-content {
            flex: 1;
            padding: 20px;
            background: #f5f7fa;
        }
        
        /* Status-specific colors */
        .stat-total { color: #667eea; }
        .stat-completed { color: #4CAF50; }
        .stat-critical { color: #f44336; }
        .stat-pending { color: #FF9800; }
        .stat-preventive { color: #2196F3; }
        .stat-corrective { color: #FF9800; }
        
        /* Add AJAX loading indicator */
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
                        <span>✅ <?php echo htmlspecialchars($success_message); ?></span>
                        <button onclick="this.parentElement.style.display='none'">&times;</button>
                    </div>
                <?php endif; ?>
                
                <?php if ($error_message): ?>
                    <div class="error-message">
                        <span>❌ <?php echo htmlspecialchars($error_message); ?></span>
                        <button onclick="this.parentElement.style.display='none'">&times;</button>
                    </div>
                <?php endif; ?>
            </div>
            
            <div class="page-header">
                <h1>Maintenance Calendar</h1>
                <button class="btn btn-primary" onclick="showScheduleModal()">
                    📅 Schedule Maintenance
                </button>
            </div>
            
            <!-- Filters -->
            <div class="filters">
                <div class="filter-group">
                    <label>Maintenance Type</label>
                    <select id="filter_type" onchange="applyFilters()">
                        <option value="all">All Types</option>
                        <?php foreach ($maintenance_types as $type): ?>
                            <option value="<?php echo htmlspecialchars($type); ?>" 
                                <?php echo ($filter_type == $type) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($type); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="filter-group">
                    <label>Equipment</label>
                    <select id="filter_equipment" onchange="applyFilters()">
                        <option value="0">All Equipment</option>
                        <?php foreach ($equipment_list as $equipment): ?>
                            <option value="<?php echo $equipment['id']; ?>" 
                                <?php echo ($filter_equipment == $equipment['id']) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($equipment['name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="filter-group">
                    <label>Priority</label>
                    <select id="filter_priority" onchange="applyFilters()">
                        <option value="all">All Priorities</option>
                        <?php foreach ($priorities as $priority): ?>
                            <option value="<?php echo $priority; ?>" 
                                <?php echo ($filter_priority == $priority) ? 'selected' : ''; ?>>
                                <?php echo $priority; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <?php if (!empty($statuses)): ?>
                <div class="filter-group">
                    <label>Status</label>
                    <select id="filter_status" onchange="applyFilters()">
                        <option value="all">All Statuses</option>
                        <?php foreach ($statuses as $status): ?>
                            <option value="<?php echo htmlspecialchars($status); ?>" 
                                <?php echo ($filter_status == $status) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($status); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php endif; ?>
                
                <div class="filter-actions">
                    <button class="btn btn-sm" onclick="clearFilters()">Clear Filters</button>
                </div>
            </div>
            
            <!-- Stats Grid -->
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-value stat-total"><?php echo $month_stats['total_count']; ?></div>
                    <div class="stat-label">Total Scheduled</div>
                </div>
                <div class="stat-card">
                    <div class="stat-value stat-completed"><?php echo $month_stats['completed_count']; ?></div>
                    <div class="stat-label">Completed</div>
                </div>
                <div class="stat-card">
                    <div class="stat-value stat-critical"><?php echo $month_stats['critical_count'] + $month_stats['high_count']; ?></div>
                    <div class="stat-label">High/Critical</div>
                </div>
                <div class="stat-card">
                    <div class="stat-value stat-pending"><?php echo $month_stats['pending_count']; ?></div>
                    <div class="stat-label">Pending</div>
                </div>
            </div>
            
            <!-- Calendar Legend -->
            <div class="legend">
                <div class="legend-item">
                    <div class="legend-color" style="background: #2196F3;"></div>
                    <span>Preventive Maintenance</span>
                </div>
                <div class="legend-item">
                    <div class="legend-color" style="background: #FF9800;"></div>
                    <span>Corrective Maintenance</span>
                </div>
                <div class="legend-item">
                    <div class="legend-color" style="background: #f44336;"></div>
                    <span>Critical Priority</span>
                </div>
                <div class="legend-item">
                    <div class="legend-color" style="background: #4CAF50;"></div>
                    <span>Completed/Repaired</span>
                </div>
                <div class="legend-item">
                    <div class="legend-color" style="background: #9c27b0;"></div>
                    <span>Other Types</span>
                </div>
            </div>
            
            <!-- Calendar Header -->
            <div class="calendar-header">
                <h2 style="margin: 0; color: #333;"><?php echo $month_name . ' ' . $year; ?></h2>
                <div class="month-navigation">
                    <a href="?month=<?php echo $month - 1; ?>&year=<?php echo ($month == 1 ? $year - 1 : $year); 
                        echo $filter_type ? '&filter_type=' . urlencode($filter_type) : '';
                        echo $filter_equipment ? '&filter_equipment=' . $filter_equipment : '';
                        echo $filter_priority ? '&filter_priority=' . urlencode($filter_priority) : '';
                        echo $filter_status ? '&filter_status=' . urlencode($filter_status) : '';
                    ?>" class="btn btn-sm">← Previous</a>
                    <a href="?month=<?php echo date('n'); ?>&year=<?php echo date('Y'); 
                        echo $filter_type ? '&filter_type=' . urlencode($filter_type) : '';
                        echo $filter_equipment ? '&filter_equipment=' . $filter_equipment : '';
                        echo $filter_priority ? '&filter_priority=' . urlencode($filter_priority) : '';
                        echo $filter_status ? '&filter_status=' . urlencode($filter_status) : '';
                    ?>" class="btn btn-sm">Today</a>
                    <a href="?month=<?php echo $month + 1; ?>&year=<?php echo ($month == 12 ? $year + 1 : $year);
                        echo $filter_type ? '&filter_type=' . urlencode($filter_type) : '';
                        echo $filter_equipment ? '&filter_equipment=' . $filter_equipment : '';
                        echo $filter_priority ? '&filter_priority=' . urlencode($filter_priority) : '';
                        echo $filter_status ? '&filter_status=' . urlencode($filter_status) : '';
                    ?>" class="btn btn-sm">Next →</a>
                </div>
            </div>
            
            <div class="calendar-layout">
                <!-- Main Calendar -->
                <div class="calendar-main">
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
                            $day_events = $events_by_date[$date_str] ?? [];
                        ?>
                            <div class="calendar-day <?php echo $is_today ? 'today' : ''; ?>" 
                                 onclick="viewDate('<?php echo $date_str; ?>')">
                                <div class="calendar-date"><?php echo $day; ?></div>
                                <div class="calendar-events">
                                    <?php 
                                    $display_events = array_slice($day_events, 0, 3);
                                    foreach ($display_events as $event): 
                                        $type = strtolower($event['type'] ?? 'corrective');
                                        $status = strtolower(str_replace(' ', '_', $event['status'] ?? 'new'));
                                        $priority = strtolower($event['priority'] ?? 'medium');
                                        
                                        // Determine CSS class based on type
                                        $css_class = '';
                                        if ($type == 'preventive') {
                                            $css_class = 'preventive';
                                        } elseif ($type == 'corrective') {
                                            $css_class = 'corrective';
                                        } elseif ($priority == 'critical') {
                                            $css_class = 'critical';
                                        } elseif ($status == 'repaired') {
                                            $css_class = 'repaired';
                                        } elseif ($status == 'new') {
                                            $css_class = 'new';
                                        } elseif ($status == 'in_progress' || $status == 'in progress') {
                                            $css_class = 'in_progress';
                                        } else {
                                            $css_class = 'other';
                                        }
                                    ?>
                                        <div class="calendar-event <?php echo $css_class; ?>" 
                                             onclick="event.stopPropagation(); viewEvent(<?php echo $event['id']; ?>)"
                                             title="<?php 
                                                echo htmlspecialchars(
                                                    ($event['type'] ?? 'Maintenance') . 
                                                    ': ' . ($event['subject'] ?? 'No Subject') . 
                                                    ' (' . ($event['equipment_name'] ?? 'No Equipment') . ') - ' . 
                                                    ($event['priority'] ?? 'Medium') . ' Priority - ' . 
                                                    ($event['status'] ?? 'New')
                                                ); 
                                             ?>">
                                            <?php 
                                            $display_text = ($event['subject'] ?? 'Maintenance');
                                            $truncated_text = substr($display_text, 0, 15);
                                            echo htmlspecialchars($truncated_text . (strlen($display_text) > 15 ? '...' : '')); 
                                            ?>
                                            <span class="event-badge badge-<?php echo $priority; ?>">
                                                <?php echo substr($event['priority'] ?? 'M', 0, 1); ?>
                                            </span>
                                        </div>
                                    <?php endforeach; ?>
                                    
                                    <?php if (count($day_events) > 3): ?>
                                        <div class="calendar-event more" 
                                             onclick="event.stopPropagation(); viewDateEvents('<?php echo $date_str; ?>')">
                                            +<?php echo count($day_events) - 3; ?> more
                                        </div>
                                    <?php endif; ?>
                                    
                                    <?php if (empty($day_events)): ?>
                                        <div style="color: #999; font-size: 0.8rem; text-align: center; margin-top: 10px;">
                                            No events
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
                
                <!-- Upcoming Events Sidebar -->
                <div class="events-sidebar">
                    <h3 style="margin: 0 0 20px 0; color: #333;">📅 Upcoming Maintenance</h3>
                    
                    <!-- View Toggle -->
                    <div class="view-toggle">
                        <button class="active" onclick="changeView('week')">This Week</button>
                        <button onclick="changeView('month')">This Month</button>
                        <button onclick="changeView('all')">All Upcoming</button>
                    </div>
                    
                    <?php if (!empty($upcoming_events)): ?>
                        <?php foreach ($upcoming_events as $event): 
                            $priority = strtolower($event['priority'] ?? 'medium');
                            $type = strtolower($event['type'] ?? 'corrective');
                            $status_color = '';
                            if ($event['status'] == 'Repaired' || $event['status'] == 'Completed') {
                                $status_color = 'color: #4CAF50;';
                            } elseif ($event['priority'] == 'Critical' || $event['priority'] == 'High') {
                                $status_color = 'color: #f44336;';
                            }
                        ?>
                        <div class="event-item" id="event-<?php echo $event['id']; ?>">
                            <div class="event-date">
                                📅 <?php echo date('D, M j', strtotime($event['scheduled_date'])); ?>
                                <span class="event-badge badge-<?php echo $priority; ?>">
                                    <?php echo $event['priority'] ?? 'Medium'; ?>
                                </span>
                                <span style="font-size: 0.8rem; color: #999; margin-left: 5px; <?php echo $status_color; ?>">
                                    <?php echo $event['status'] ?? 'New'; ?>
                                </span>
                            </div>
                            <h4 style="margin: 5px 0; color: #333; font-size: 1rem;">
                                <?php echo htmlspecialchars($event['subject'] ?? 'No Subject'); ?>
                            </h4>
                            <div style="color: #666; margin-bottom: 10px; font-size: 0.9rem;">
                                <?php if (!empty($event['equipment_name'])): ?>
                                    <div>🏭 <?php echo htmlspecialchars($event['equipment_name']); ?></div>
                                <?php endif; ?>
                                <?php if (!empty($event['assigned_technician_name'])): ?>
                                    <div>👤 Assigned to: <?php echo htmlspecialchars($event['assigned_technician_name']); ?></div>
                                <?php elseif (!empty($event['technician_username'])): ?>
                                    <div>👤 Assigned to: <?php echo htmlspecialchars($event['technician_username']); ?></div>
                                <?php endif; ?>
                                <?php if (!empty($event['location'])): ?>
                                    <div>📍 <?php echo htmlspecialchars($event['location']); ?></div>
                                <?php endif; ?>
                            </div>
                            <div style="display: flex; gap: 8px; flex-wrap: wrap;">
                                <button class="btn btn-sm btn-primary" 
                                        onclick="viewEvent(<?php echo $event['id']; ?>)">
                                    👁️ View
                                </button>
                                <?php if (empty($event['assigned_to'])): ?>
                                <button class="btn btn-sm btn-success" 
                                        onclick="assignEvent(<?php echo $event['id']; ?>)">
                                    👤 Assign
                                </button>
                                <?php endif; ?>
                                <button class="btn btn-sm" 
                                        onclick="rescheduleEvent(<?php echo $event['id']; ?>)" 
                                        style="background: #fff3e0; color: #f57c00;">
                                    📝 Reschedule
                                </button>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div style="text-align: center; padding: 20px; color: #666;">
                            <div style="font-size: 2rem; margin-bottom: 10px;">📅</div>
                            <p>No upcoming maintenance scheduled</p>
                            <button onclick="showScheduleModal()" class="btn btn-primary mt-2">
                                Schedule Now
                            </button>
                        </div>
                    <?php endif; ?>
                    
                    <!-- Quick Stats -->
                    <div style="margin-top: 30px; padding-top: 20px; border-top: 1px solid #eaeaea;">
                        <h4 style="margin: 0 0 10px 0; color: #333;">📊 This Month Stats</h4>
                        <div style="display: grid; grid-template-columns: repeat(2, 1fr); gap: 10px;">
                            <div style="background: #f8f9fa; padding: 10px; border-radius: 6px; text-align: center;">
                                <div style="font-size: 1.2rem; font-weight: bold; color: #667eea;">
                                    <?php echo $month_stats['total_count']; ?>
                                </div>
                                <div style="font-size: 0.8rem; color: #666;">Total</div>
                            </div>
                            <div style="background: #f8f9fa; padding: 10px; border-radius: 6px; text-align: center;">
                                <div style="font-size: 1.2rem; font-weight: bold; color: #4CAF50;">
                                    <?php echo $month_stats['completed_count']; ?>
                                </div>
                                <div style="font-size: 0.8rem; color: #666;">Completed</div>
                            </div>
                            <div style="background: #f8f9fa; padding: 10px; border-radius: 6px; text-align: center;">
                                <div style="font-size: 1.2rem; font-weight: bold; color: #FF9800;">
                                    <?php echo $month_stats['pending_count']; ?>
                                </div>
                                <div style="font-size: 0.8rem; color: #666;">Pending</div>
                            </div>
                            <div style="background: #f8f9fa; padding: 10px; border-radius: 6px; text-align: center;">
                                <div style="font-size: 1.2rem; font-weight: bold; color: #f44336;">
                                    <?php echo $month_stats['critical_count'] + $month_stats['high_count']; ?>
                                </div>
                                <div style="font-size: 0.8rem; color: #666;">High/Critical</div>
                            </div>
                        </div>
                        
                        <!-- Type Breakdown -->
                        <?php if (!empty($type_stats)): ?>
                        <div style="margin-top: 20px;">
                            <h5 style="margin: 0 0 10px 0; color: #333;">Type Breakdown</h5>
                            <?php foreach ($type_stats as $type => $count): 
                                $color = ($type == 'Preventive') ? '#2196F3' : (($type == 'Corrective') ? '#FF9800' : '#9c27b0');
                            ?>
                                <div style="display: flex; justify-content: space-between; margin-bottom: 5px; font-size: 0.85rem;">
                                    <span>
                                        <span style="display: inline-block; width: 8px; height: 8px; background: <?php echo $color; ?>; border-radius: 50%; margin-right: 8px;"></span>
                                        <?php echo htmlspecialchars($type); ?>
                                    </span>
                                    <span style="color: #667eea; font-weight: bold;"><?php echo $count; ?></span>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <?php endif; ?>
                        
                        <!-- Equipment Stats -->
                        <?php if (!empty($equipment_stats)): ?>
                        <div style="margin-top: 20px;">
                            <h5 style="margin: 0 0 10px 0; color: #333;">Top Equipment</h5>
                            <?php foreach ($equipment_stats as $stat): ?>
                                <div style="display: flex; justify-content: space-between; margin-bottom: 5px; font-size: 0.85rem;">
                                    <span><?php echo htmlspecialchars($stat['name']); ?></span>
                                    <span style="color: #667eea; font-weight: bold;"><?php echo $stat['maintenance_count']; ?> tasks</span>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </main>
    </div>
    
    <!-- Loading Overlay -->
    <div class="loading-overlay" id="loadingOverlay">
        <div class="spinner"></div>
    </div>
    
    <!-- Schedule Modal -->
    <div id="scheduleModal" class="modal">
        <div class="modal-content">
            <h3 style="margin: 0 0 20px 0;">Schedule New Maintenance</h3>
            <form method="POST" action="">
                <div class="form-group">
                    <label>Maintenance Type *</label>
                    <select name="type" required>
                        <option value="">Select Type</option>
                        <option value="Preventive" selected>Preventive</option>
                        <option value="Corrective">Corrective</option>
                        <?php foreach (array_diff($maintenance_types, ['Preventive', 'Corrective']) as $type): ?>
                            <option value="<?php echo htmlspecialchars($type); ?>"><?php echo htmlspecialchars($type); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="form-group">
                    <label>Equipment *</label>
                    <select name="equipment_id" required>
                        <option value="">Select Equipment</option>
                        <?php foreach ($equipment_list as $equipment): ?>
                            <option value="<?php echo $equipment['id']; ?>">
                                <?php echo htmlspecialchars($equipment['name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="form-group">
                    <label>Subject *</label>
                    <input type="text" name="subject" required placeholder="Enter maintenance subject">
                </div>
                
                <div class="form-group">
                    <label>Description</label>
                    <textarea name="description" placeholder="Enter maintenance description"></textarea>
                </div>
                
                <div class="form-group">
                    <label>Scheduled Date *</label>
                    <input type="date" name="scheduled_date" required min="<?php echo date('Y-m-d'); ?>">
                </div>
                
                <div class="form-group">
                    <label>Priority *</label>
                    <select name="priority" required>
                        <?php foreach ($priorities as $priority): ?>
                            <option value="<?php echo $priority; ?>" <?php echo ($priority == 'Medium') ? 'selected' : ''; ?>>
                                <?php echo $priority; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="form-group">
                    <label>Status</label>
                    <select name="status">
                        <option value="New">New</option>
                        <option value="Scheduled">Scheduled</option>
                        <?php foreach ($statuses as $status): ?>
                            <?php if (!in_array($status, ['New', 'Scheduled'])): ?>
                                <option value="<?php echo htmlspecialchars($status); ?>"><?php echo htmlspecialchars($status); ?></option>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="form-group">
                    <label>Assign To</label>
                    <select name="assigned_to">
                        <option value="">Not Assigned</option>
                        <?php foreach ($technicians as $tech): ?>
                            <option value="<?php echo $tech['id']; ?>">
                                <?php 
                                $display_name = !empty($tech['full_name']) ? $tech['full_name'] : $tech['username'];
                                echo htmlspecialchars($display_name . ' (' . $tech['role'] . ')'); 
                                ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="form-group">
                    <label>Estimated Duration (hours)</label>
                    <input type="number" name="duration_hours" min="0.5" max="24" step="0.5" value="1">
                </div>
                
                <div class="modal-actions">
                    <button type="button" class="btn" onclick="hideModal('scheduleModal')">Cancel</button>
                    <button type="submit" name="schedule_maintenance" class="btn btn-primary">Schedule Maintenance</button>
                </div>
            </form>
        </div>
    </div>
    
    <!-- Assign Modal -->
    <div id="assignModal" class="modal">
        <div class="modal-content">
            <h3 style="margin: 0 0 20px 0;">Assign Technician</h3>
            <form method="POST" action="">
                <input type="hidden" name="event_id" id="assignEventId">
                
                <div class="form-group">
                    <label>Select Technician *</label>
                    <select name="assigned_to" required>
                        <option value="">Select Technician</option>
                        <?php foreach ($technicians as $tech): ?>
                            <option value="<?php echo $tech['id']; ?>">
                                <?php 
                                $display_name = !empty($tech['full_name']) ? $tech['full_name'] : $tech['username'];
                                echo htmlspecialchars($display_name . ' (' . $tech['role'] . ')'); 
                                ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="form-group">
                    <label>Assignment Notes</label>
                    <textarea name="assignment_notes" placeholder="Add any special instructions"></textarea>
                </div>
                
                <div class="form-group">
                    <label>Expected Completion Date</label>
                    <input type="date" name="expected_completion_date" min="<?php echo date('Y-m-d'); ?>">
                </div>
                
                <div class="form-group">
                    <label>Update Status</label>
                    <select name="status">
                        <option value="">Keep Current Status</option>
                        <option value="Assigned">Assigned</option>
                        <option value="In Progress">In Progress</option>
                        <?php foreach ($statuses as $status): ?>
                            <?php if (!in_array($status, ['Assigned', 'In Progress'])): ?>
                                <option value="<?php echo htmlspecialchars($status); ?>"><?php echo htmlspecialchars($status); ?></option>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="modal-actions">
                    <button type="button" class="btn" onclick="hideModal('assignModal')">Cancel</button>
                    <button type="submit" name="assign_technician" class="btn btn-primary">Assign Technician</button>
                </div>
            </form>
        </div>
    </div>
    
    <!-- Reschedule Modal -->
    <div id="rescheduleModal" class="modal">
        <div class="modal-content">
            <h3 style="margin: 0 0 20px 0;">Reschedule Maintenance</h3>
            <form method="POST" action="" id="rescheduleForm">
                <input type="hidden" name="event_id" id="rescheduleEventId">
                
                <div class="form-group">
                    <label>New Scheduled Date *</label>
                    <input type="date" name="new_scheduled_date" required min="<?php echo date('Y-m-d'); ?>">
                </div>
                
                <div class="form-group">
                    <label>Reason for Rescheduling *</label>
                    <select name="reschedule_reason" required>
                        <option value="">Select Reason</option>
                        <option value="Equipment Unavailable">Equipment Unavailable</option>
                        <option value="Technician Unavailable">Technician Unavailable</option>
                        <option value="Parts Delay">Parts Delay</option>
                        <option value="Higher Priority Task">Higher Priority Task</option>
                        <option value="Weather Conditions">Weather Conditions</option>
                        <option value="Other">Other</option>
                    </select>
                </div>
                
                <div class="form-group">
                    <label>Additional Notes</label>
                    <textarea name="reschedule_notes" placeholder="Add any additional notes"></textarea>
                </div>
                
                <div class="modal-actions">
                    <button type="button" class="btn" onclick="hideModal('rescheduleModal')">Cancel</button>
                    <button type="submit" name="reschedule_maintenance" class="btn btn-primary">Reschedule</button>
                </div>
            </form>
        </div>
    </div>
    
    <script>
        function showScheduleModal() {
            document.getElementById('scheduleModal').style.display = 'flex';
            // Set today as default date
            const today = new Date().toISOString().split('T')[0];
            const dateInput = document.querySelector('#scheduleModal input[name="scheduled_date"]');
            if (dateInput) {
                dateInput.value = today;
                dateInput.min = today;
            }
        }
        
        function assignEvent(eventId) {
            document.getElementById('assignEventId').value = eventId;
            document.getElementById('assignModal').style.display = 'flex';
        }
        
        function rescheduleEvent(eventId) {
            document.getElementById('rescheduleEventId').value = eventId;
            document.getElementById('rescheduleModal').style.display = 'flex';
            
            // Set tomorrow as default date
            const tomorrow = new Date();
            tomorrow.setDate(tomorrow.getDate() + 1);
            const tomorrowStr = tomorrow.toISOString().split('T')[0];
            const dateInput = document.querySelector('#rescheduleModal input[name="new_scheduled_date"]');
            if (dateInput) {
                dateInput.value = tomorrowStr;
                dateInput.min = new Date().toISOString().split('T')[0];
            }
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
        
        function applyFilters() {
            const type = document.getElementById('filter_type').value;
            const equipment = document.getElementById('filter_equipment').value;
            const priority = document.getElementById('filter_priority').value;
            const status = document.getElementById('filter_status').value;
            
            let url = `calendar.php?month=<?php echo $month; ?>&year=<?php echo $year; ?>`;
            
            if (type !== 'all') url += `&filter_type=${encodeURIComponent(type)}`;
            if (equipment !== '0') url += `&filter_equipment=${equipment}`;
            if (priority !== 'all') url += `&filter_priority=${encodeURIComponent(priority)}`;
            if (status !== 'all') url += `&filter_status=${encodeURIComponent(status)}`;
            
            window.location.href = url;
        }
        
        function clearFilters() {
            window.location.href = `calendar.php?month=<?php echo $month; ?>&year=<?php echo $year; ?>`;
        }
        
        function viewDate(date) {
            window.location.href = `day_view.php?date=${date}`;
        }
        
        function viewDateEvents(date) {
            alert('View all events for ' + formatDate(date));
            // In real app: window.location.href = 'day_events.php?date=' + date;
        }
        
        function viewEvent(id) {
            window.location.href = `maintenance_request.php?id=${id}`;
        }
        
        function changeView(view) {
            // Update active button
            document.querySelectorAll('.view-toggle button').forEach(btn => {
                btn.classList.remove('active');
            });
            event.target.classList.add('active');
            
            // For demonstration, just show an alert
            alert('Switch to ' + view + ' view - This would filter the upcoming events list');
        }
        
        function formatDate(dateStr) {
            const date = new Date(dateStr);
            return date.toLocaleDateString('en-US', { 
                weekday: 'long', 
                year: 'numeric', 
                month: 'long', 
                day: 'numeric' 
            });
        }
        
        // Handle form submissions without page reload using AJAX
        document.addEventListener('DOMContentLoaded', function() {
            // Handle reschedule form submission with AJAX
            const rescheduleForm = document.getElementById('rescheduleForm');
            if (rescheduleForm) {
                rescheduleForm.addEventListener('submit', function(e) {
                    e.preventDefault();
                    
                    const formData = new FormData(this);
                    showLoading();
                    
                    fetch('', {
                        method: 'POST',
                        body: formData
                    })
                    .then(response => response.text())
                    .then(data => {
                        hideLoading();
                        // The page will reload with the updated data
                        window.location.reload();
                    })
                    .catch(error => {
                        hideLoading();
                        console.error('Error:', error);
                        alert('Error rescheduling maintenance. Please try again.');
                    });
                });
            }
            
            // Highlight today's date
            const today = new Date();
            const todayStr = today.getFullYear() + '-' + 
                           String(today.getMonth() + 1).padStart(2, '0') + '-' + 
                           String(today.getDate()).padStart(2, '0');
            
            // Scroll to today if in current month
            if (<?php echo $month; ?> === today.getMonth() + 1 && <?php echo $year; ?> === today.getFullYear()) {
                const todayElement = document.querySelector('.calendar-day.today');
                if (todayElement) {
                    setTimeout(() => {
                        todayElement.scrollIntoView({ behavior: 'smooth', block: 'center' });
                    }, 500);
                }
            }
            
            // Close modal when clicking outside
            window.onclick = function(event) {
                if (event.target.classList.contains('modal')) {
                    event.target.style.display = 'none';
                }
            };
            
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
    </script>
</body>
</html>