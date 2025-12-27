<?php
// my_tasks.php - Technician My Tasks Page
// Don't start session if already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Check if user is logged in and is a technician
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'technician') {
    header('Location: ../index.php');
    exit();
}

// Database connection
require_once '../includes/db_connect.php';

$user_id = $_SESSION['user_id'];
$user_name = $_SESSION['full_name'];

// Get filter parameters
$status_filter = isset($_GET['status']) ? $_GET['status'] : '';
$priority_filter = isset($_GET['priority']) ? $_GET['priority'] : '';
$date_filter = isset($_GET['date']) ? $_GET['date'] : '';
$search_query = isset($_GET['search']) ? trim($_GET['search']) : '';

// Build WHERE clause for filtering
$where_conditions = ["mr.assigned_to = ?"];
$params = [$user_id];
$param_types = "i";

if (!empty($status_filter) && $status_filter !== 'all') {
    $where_conditions[] = "mr.status = ?";
    $params[] = $status_filter;
    $param_types .= "s";
}

if (!empty($priority_filter) && $priority_filter !== 'all') {
    $where_conditions[] = "mr.priority = ?";
    $params[] = $priority_filter;
    $param_types .= "s";
}

if (!empty($date_filter)) {
    if ($date_filter === 'today') {
        $where_conditions[] = "DATE(mr.scheduled_date) = CURDATE()";
    } elseif ($date_filter === 'tomorrow') {
        $where_conditions[] = "DATE(mr.scheduled_date) = DATE_ADD(CURDATE(), INTERVAL 1 DAY)";
    } elseif ($date_filter === 'week') {
        $where_conditions[] = "mr.scheduled_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY)";
    } elseif ($date_filter === 'overdue') {
        $where_conditions[] = "mr.scheduled_date < CURDATE() AND mr.status NOT IN ('Completed', 'Cancelled')";
    } elseif ($date_filter === 'upcoming') {
        $where_conditions[] = "mr.scheduled_date > CURDATE() AND mr.status NOT IN ('Completed', 'Cancelled')";
    }
}

if (!empty($search_query)) {
    $where_conditions[] = "(mr.subject LIKE ? OR mr.description LIKE ? OR e.name LIKE ? OR mr.request_number LIKE ?)";
    $search_term = "%$search_query%";
    $params = array_merge($params, [$search_term, $search_term, $search_term, $search_term]);
    $param_types .= "ssss";
}

$where_clause = implode(' AND ', $where_conditions);

// Get total tasks count for pagination
$count_query = "SELECT COUNT(*) as total 
                FROM maintenance_requests mr
                LEFT JOIN equipment e ON mr.equipment_id = e.id
                WHERE $where_clause";
$count_stmt = mysqli_prepare($conn, $count_query);

// Bind parameters
if ($params) {
    mysqli_stmt_bind_param($count_stmt, $param_types, ...$params);
}
mysqli_stmt_execute($count_stmt);
$count_result = mysqli_stmt_get_result($count_stmt);
$total_tasks = mysqli_fetch_assoc($count_result)['total'];

// Pagination
$per_page = 15;
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$total_pages = ceil($total_tasks / $per_page);
$offset = ($page - 1) * $per_page;

// Get tasks with filters and pagination
$query = "SELECT 
            mr.*, 
            e.name as equipment_name,
            e.location,
            e.serial_number,
            d.name as department_name,
            u.full_name as requester_name,
            u.email as requester_email,
            mt.name as team_name,
            ec.name as equipment_category
          FROM maintenance_requests mr
          LEFT JOIN equipment e ON mr.equipment_id = e.id
          LEFT JOIN departments d ON e.department_id = d.id
          LEFT JOIN users u ON mr.created_by = u.id
          LEFT JOIN maintenance_teams mt ON mr.assigned_to = mt.id
          LEFT JOIN equipment_categories ec ON e.category_id = ec.id
          WHERE $where_clause
          ORDER BY 
            CASE 
              WHEN mr.scheduled_date < CURDATE() AND mr.status NOT IN ('Completed', 'Cancelled') THEN 0
              WHEN DATE(mr.scheduled_date) = CURDATE() THEN 1
              ELSE 2
            END,
            CASE mr.priority
              WHEN 'Critical' THEN 1
              WHEN 'High' THEN 2
              WHEN 'Medium' THEN 3
              WHEN 'Low' THEN 4
              ELSE 5
            END,
            mr.scheduled_date ASC,
            mr.created_at DESC
          LIMIT ? OFFSET ?";

// Add pagination parameters
$params[] = $per_page;
$params[] = $offset;
$param_types .= "ii";

$stmt = mysqli_prepare($conn, $query);

// Bind all parameters including pagination
if ($params) {
    mysqli_stmt_bind_param($stmt, $param_types, ...$params);
}

mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);

$tasks = [];
while ($row = mysqli_fetch_assoc($result)) {
    if (empty($row['request_number'])) {
        $row['request_number'] = 'REQ-' . str_pad($row['id'], 6, '0', STR_PAD_LEFT);
    }
    
    // Calculate days difference for overdue/upcoming
    if ($row['scheduled_date']) {
        $scheduled_date = new DateTime($row['scheduled_date']);
        $today = new DateTime();
        $interval = $today->diff($scheduled_date);
        $row['days_difference'] = $interval->days;
        $row['is_overdue'] = ($scheduled_date < $today && !in_array($row['status'], ['Completed', 'Cancelled']));
        $row['is_today'] = ($scheduled_date->format('Y-m-d') == $today->format('Y-m-d'));
        $row['is_tomorrow'] = ($scheduled_date->format('Y-m-d') == $today->modify('+1 day')->format('Y-m-d'));
    }
    
    $tasks[] = $row;
}

// Get task statistics for filter sidebar
$stats_query = "SELECT 
    COUNT(*) as total,
    SUM(CASE WHEN status = 'New' THEN 1 ELSE 0 END) as new,
    SUM(CASE WHEN status = 'In Progress' THEN 1 ELSE 0 END) as in_progress,
    SUM(CASE WHEN status = 'Completed' THEN 1 ELSE 0 END) as completed,
    SUM(CASE WHEN status = 'On Hold' THEN 1 ELSE 0 END) as on_hold,
    SUM(CASE WHEN status = 'Cancelled' THEN 1 ELSE 0 END) as cancelled,
    SUM(CASE WHEN scheduled_date < CURDATE() AND status NOT IN ('Completed', 'Cancelled') THEN 1 ELSE 0 END) as overdue,
    SUM(CASE WHEN DATE(scheduled_date) = CURDATE() THEN 1 ELSE 0 END) as today,
    SUM(CASE WHEN DATE(scheduled_date) = DATE_ADD(CURDATE(), INTERVAL 1 DAY) THEN 1 ELSE 0 END) as tomorrow,
    SUM(CASE WHEN priority = 'Critical' THEN 1 ELSE 0 END) as critical,
    SUM(CASE WHEN priority = 'High' THEN 1 ELSE 0 END) as high,
    SUM(CASE WHEN priority = 'Medium' THEN 1 ELSE 0 END) as medium,
    SUM(CASE WHEN priority = 'Low' THEN 1 ELSE 0 END) as low
    FROM maintenance_requests 
    WHERE assigned_to = ?";
$stats_stmt = mysqli_prepare($conn, $stats_query);
mysqli_stmt_bind_param($stats_stmt, 'i', $user_id);
mysqli_stmt_execute($stats_stmt);
$stats_result = mysqli_stmt_get_result($stats_stmt);
$stats = mysqli_fetch_assoc($stats_result);

// Get equipment categories for filtering
$categories_query = "SELECT DISTINCT ec.name 
                    FROM maintenance_requests mr
                    LEFT JOIN equipment e ON mr.equipment_id = e.id
                    LEFT JOIN equipment_categories ec ON e.category_id = ec.id
                    WHERE mr.assigned_to = ? AND ec.name IS NOT NULL
                    ORDER BY ec.name";
$categories_stmt = mysqli_prepare($conn, $categories_query);
mysqli_stmt_bind_param($categories_stmt, 'i', $user_id);
mysqli_stmt_execute($categories_stmt);
$categories_result = mysqli_stmt_get_result($categories_stmt);

$equipment_categories = [];
while ($cat = mysqli_fetch_assoc($categories_result)) {
    $equipment_categories[] = $cat['name'];
}

// Get request types for filtering
$types_query = "SELECT DISTINCT type FROM maintenance_requests WHERE assigned_to = ?";
$types_stmt = mysqli_prepare($conn, $types_query);
mysqli_stmt_bind_param($types_stmt, 'i', $user_id);
mysqli_stmt_execute($types_stmt);
$types_result = mysqli_stmt_get_result($types_stmt);

$request_types = [];
while ($type = mysqli_fetch_assoc($types_result)) {
    $request_types[] = $type['type'];
}

mysqli_close($conn);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Tasks - GearGuard</title>
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
        
        /* Header Styles */
        
        
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
        
        /* Tasks Container */
        .tasks-container {
            display: flex;
            gap: 25px;
            margin-bottom: 30px;
        }
        
        @media (max-width: 1024px) {
            .tasks-container {
                flex-direction: column;
            }
        }
        
        /* Filters Sidebar */
        .filters-sidebar {
            flex: 0 0 280px;
            background: white;
            border-radius: 15px;
            padding: 25px;
            box-shadow: 0 5px 20px rgba(0,0,0,0.05);
            height: fit-content;
        }
        
        .filter-section {
            margin-bottom: 25px;
        }
        
        .filter-section h3 {
            font-size: 1.1rem;
            color: #333;
            margin-bottom: 15px;
            padding-bottom: 10px;
            border-bottom: 2px solid #f0f0f0;
            font-weight: 600;
        }
        
        .filter-group {
            display: flex;
            flex-direction: column;
            gap: 8px;
        }
        
        .filter-btn {
            display: flex;
            justify-content: space-between;
            align-items: center;
            width: 100%;
            text-align: left;
            padding: 10px 15px;
            border: none;
            background: #f8f9fa;
            border-radius: 8px;
            cursor: pointer;
            transition: all 0.3s;
            font-size: 0.95rem;
            color: #555;
            text-decoration: none;
        }
        
        .filter-btn:hover {
            background: #e9ecef;
            transform: translateX(5px);
        }
        
        .filter-btn.active {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            font-weight: 500;
            box-shadow: 0 3px 10px rgba(102, 126, 234, 0.3);
        }
        
        .badge {
            background: rgba(255,255,255,0.2);
            padding: 2px 8px;
            border-radius: 10px;
            font-size: 0.8rem;
            min-width: 30px;
            text-align: center;
        }
        
        .filter-btn:not(.active) .badge {
            background: #dee2e6;
            color: #666;
        }
        
        .search-box {
            position: relative;
            margin-bottom: 20px;
        }
        
        .search-input {
            width: 100%;
            padding: 12px 15px 12px 45px;
            border: 2px solid #e1e5e9;
            border-radius: 10px;
            font-size: 1rem;
            transition: all 0.3s;
        }
        
        .search-input:focus {
            outline: none;
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
        }
        
        .search-icon {
            position: absolute;
            left: 15px;
            top: 50%;
            transform: translateY(-50%);
            color: #666;
        }
        
        .clear-filters {
            display: block;
            width: 100%;
            text-align: center;
            padding: 12px;
            background: #6c757d;
            color: white;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            text-decoration: none;
            margin-top: 20px;
            transition: background 0.3s;
            font-weight: 500;
        }
        
        .clear-filters:hover {
            background: #5a6268;
        }
        
        /* Tasks Header */
        .tasks-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 25px;
            flex-wrap: wrap;
            gap: 15px;
        }
        
        .tasks-header h1 {
            color: #333;
            font-size: 1.8rem;
        }
        
        .task-count {
            color: #666;
            font-size: 1rem;
            background: white;
            padding: 8px 15px;
            border-radius: 20px;
            box-shadow: 0 2px 5px rgba(0,0,0,0.05);
        }
        
        /* Tasks Table */
        .tasks-table-container {
            background: white;
            border-radius: 15px;
            padding: 25px;
            box-shadow: 0 5px 20px rgba(0,0,0,0.05);
            overflow-x: auto;
        }
        
        .table {
            width: 100%;
            border-collapse: collapse;
            min-width: 1000px;
        }
        
        .table th {
            background: #f8f9fa;
            padding: 15px;
            text-align: left;
            font-weight: 600;
            color: #555;
            border-bottom: 2px solid #e9ecef;
            white-space: nowrap;
            font-size: 0.9rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .table td {
            padding: 15px;
            border-bottom: 1px solid #f0f0f0;
            vertical-align: top;
        }
        
        .table tr:hover {
            background: #f9f9f9;
        }
        
        .table tr.overdue {
            background: linear-gradient(90deg, rgba(255,235,238,0.3) 0%, rgba(255,255,255,1) 100%);
            border-left: 4px solid #f44336;
        }
        
        .table tr.today {
            background: linear-gradient(90deg, rgba(255,248,225,0.3) 0%, rgba(255,255,255,1) 100%);
            border-left: 4px solid #FF9800;
        }
        
        .table tr.tomorrow {
            background: linear-gradient(90deg, rgba(227,242,253,0.3) 0%, rgba(255,255,255,1) 100%);
            border-left: 4px solid #2196F3;
        }
        
        /* Status Badges */
        .status-badge {
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 0.85rem;
            font-weight: 500;
            display: inline-block;
            min-width: 100px;
            text-align: center;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .status-new { background: #ffebee; color: #c62828; }
        .status-in-progress { background: #fff3e0; color: #ef6c00; }
        .status-completed { background: #e8f5e9; color: #2e7d32; }
        .status-on-hold { background: #f5f5f5; color: #616161; }
        .status-cancelled { background: #f5f5f5; color: #616161; }
        .status-scheduled { background: #e3f2fd; color: #1976d2; }
        
        /* Priority Badges */
        .priority-badge {
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 0.85rem;
            font-weight: 500;
            display: inline-block;
            min-width: 80px;
            text-align: center;
        }
        
        .priority-low { background: #e8f5e9; color: #2e7d32; }
        .priority-medium { background: #fff3e0; color: #f57c00; }
        .priority-high { background: #ffebee; color: #c62828; }
        .priority-critical { background: #f44336; color: white; }
        
        /* Action Buttons */
        .action-buttons {
            display: flex;
            gap: 5px;
            flex-wrap: wrap;
        }
        
        .btn-action {
            padding: 6px 12px;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            font-size: 0.85rem;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 5px;
            transition: all 0.3s;
            font-weight: 500;
        }
        
        .btn-view { background: #2196F3; color: white; }
        .btn-view:hover { background: #1976d2; transform: translateY(-2px); }
        
        .btn-start { background: #4CAF50; color: white; }
        .btn-start:hover { background: #388e3c; transform: translateY(-2px); }
        
        .btn-complete { background: #FF9800; color: white; }
        .btn-complete:hover { background: #F57C00; transform: translateY(-2px); }
        
        .btn-hold { background: #9E9E9E; color: white; }
        .btn-hold:hover { background: #757575; transform: translateY(-2px); }
        
        /* Pagination */
        .pagination {
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 10px;
            margin-top: 30px;
            flex-wrap: wrap;
        }
        
        .page-link {
            padding: 8px 15px;
            background: white;
            border: 1px solid #dee2e6;
            border-radius: 6px;
            text-decoration: none;
            color: #667eea;
            transition: all 0.3s;
            font-weight: 500;
        }
        
        .page-link:hover {
            background: #f8f9fa;
            transform: translateY(-2px);
        }
        
        .page-link.active {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border-color: #667eea;
        }
        
        .page-link.disabled {
            color: #6c757d;
            pointer-events: none;
            background: #f8f9fa;
            opacity: 0.6;
        }
        
        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 60px 20px;
            color: #666;
        }
        
        .empty-icon {
            font-size: 4rem;
            margin-bottom: 20px;
            opacity: 0.3;
        }
        
        /* Task Details */
        .task-details {
            font-size: 0.9rem;
            color: #666;
            margin-top: 5px;
            line-height: 1.4;
        }
        
        .equipment-info {
            background: #f8f9fa;
            padding: 8px 12px;
            border-radius: 8px;
            margin-top: 5px;
            font-size: 0.85rem;
            border-left: 3px solid #667eea;
        }
        
        .equipment-info div {
            margin-bottom: 3px;
            display: flex;
            align-items: center;
            gap: 5px;
        }
        
        .equipment-info div:last-child {
            margin-bottom: 0;
        }
        
        .date-badge {
            padding: 4px 10px;
            border-radius: 12px;
            font-size: 0.8rem;
            font-weight: 500;
            display: inline-block;
            margin-top: 5px;
        }
        
        .date-overdue { background: #ffebee; color: #c62828; }
        .date-today { background: #fff3e0; color: #f57c00; }
        .date-tomorrow { background: #e3f2fd; color: #1976d2; }
        .date-upcoming { background: #f5f5f5; color: #616161; }
        
        /* Export Buttons */
        .export-buttons {
            display: flex;
            gap: 10px;
            margin-top: 20px;
            justify-content: flex-end;
        }
        
        .btn-export {
            padding: 8px 16px;
            background: white;
            color: #667eea;
            border: 2px solid #667eea;
            border-radius: 6px;
            cursor: pointer;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            font-size: 0.9rem;
            font-weight: 500;
            transition: all 0.3s;
        }
        
        .btn-export:hover {
            background: #667eea;
            color: white;
            transform: translateY(-2px);
        }
        
        .btn-export.pdf { border-color: #dc3545; color: #dc3545; }
        .btn-export.pdf:hover { background: #dc3545; }
        
        .btn-export.excel { border-color: #28a745; color: #28a745; }
        .btn-export.excel:hover { background: #28a745; }
        
        .btn-export.print { border-color: #6c757d; color: #6c757d; }
        .btn-export.print:hover { background: #6c757d; }
        
        /* Responsive */
        @media (max-width: 768px) {
            .tasks-header {
                flex-direction: column;
                align-items: flex-start;
            }
            
            .filters-sidebar {
                width: 100%;
            }
            
            .action-buttons {
                flex-direction: column;
            }
            
            .header {
                padding: 0 15px;
            }
            
            .main-content {
                padding: 20px;
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
        
        /* Type Badge */
        .type-badge {
            padding: 4px 10px;
            border-radius: 12px;
            font-size: 0.8rem;
            font-weight: 500;
            display: inline-block;
            margin-top: 5px;
        }
        
        .type-corrective { background: #fff3e0; color: #f57c00; }
        .type-preventive { background: #e8f5e9; color: #2e7d32; }
        
        /* Quick Actions */
        .quick-actions {
            display: flex;
            gap: 10px;
            margin-bottom: 20px;
            flex-wrap: wrap;
        }
        
        .quick-action-btn {
            padding: 10px 20px;
            background: white;
            border: 2px solid #667eea;
            border-radius: 8px;
            color: #667eea;
            text-decoration: none;
            font-weight: 500;
            transition: all 0.3s;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }
        
        .quick-action-btn:hover {
            background: #667eea;
            color: white;
            transform: translateY(-2px);
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
            <div class="page-title">My Tasks</div>
            <div class="page-subtitle">Manage and track all your assigned maintenance requests</div>
            
            <!-- Statistics Cards -->
            <div class="stats-cards">
                <div class="stat-card">
                    <div class="stat-icon">📋</div>
                    <div class="stat-value"><?php echo $stats['total']; ?></div>
                    <div class="stat-label">Total Tasks</div>
                </div>
                
                <div class="stat-card">
                    <div class="stat-icon">⏰</div>
                    <div class="stat-value"><?php echo $stats['overdue']; ?></div>
                    <div class="stat-label">Overdue</div>
                </div>
                
                <div class="stat-card">
                    <div class="stat-icon">🛠️</div>
                    <div class="stat-value"><?php echo $stats['in_progress']; ?></div>
                    <div class="stat-label">In Progress</div>
                </div>
                
                <div class="stat-card">
                    <div class="stat-icon">✅</div>
                    <div class="stat-value"><?php echo $stats['completed']; ?></div>
                    <div class="stat-label">Completed</div>
                </div>
            </div>
            
            <!-- Quick Actions -->
            <div class="quick-actions">
                <a href="?status=overdue" class="quick-action-btn">
                    <i class="fas fa-exclamation-triangle"></i> View Overdue
                </a>
                <a href="?date=today" class="quick-action-btn">
                    <i class="fas fa-calendar-day"></i> Today's Tasks
                </a>
                <a href="?status=In Progress" class="quick-action-btn">
                    <i class="fas fa-tools"></i> In Progress
                </a>
                <a href="?status=New" class="quick-action-btn">
                    <i class="fas fa-plus-circle"></i> New Tasks
                </a>
            </div>
            
            <div class="tasks-container">
                <!-- Filters Sidebar -->
                <div class="filters-sidebar">
                    <div class="search-box">
                        <i class="fas fa-search search-icon"></i>
                        <form method="GET" action="" id="searchForm">
                            <input type="text" name="search" class="search-input" 
                                   placeholder="Search tasks..." 
                                   value="<?php echo htmlspecialchars($search_query); ?>"
                                   onkeyup="if(event.keyCode===13) this.form.submit()">
                        </form>
                    </div>
                    
                    <div class="filter-section">
                        <h3>Status</h3>
                        <div class="filter-group">
                            <a href="?status=all&priority=<?php echo $priority_filter; ?>&date=<?php echo $date_filter; ?>&search=<?php echo urlencode($search_query); ?>" 
                               class="filter-btn <?php echo empty($status_filter) || $status_filter === 'all' ? 'active' : ''; ?>">
                                All Tasks
                                <span class="badge"><?php echo $stats['total']; ?></span>
                            </a>
                            <a href="?status=New&priority=<?php echo $priority_filter; ?>&date=<?php echo $date_filter; ?>&search=<?php echo urlencode($search_query); ?>" 
                               class="filter-btn <?php echo $status_filter === 'New' ? 'active' : ''; ?>">
                                New
                                <span class="badge"><?php echo $stats['new']; ?></span>
                            </a>
                            <a href="?status=In Progress&priority=<?php echo $priority_filter; ?>&date=<?php echo $date_filter; ?>&search=<?php echo urlencode($search_query); ?>" 
                               class="filter-btn <?php echo $status_filter === 'In Progress' ? 'active' : ''; ?>">
                                In Progress
                                <span class="badge"><?php echo $stats['in_progress']; ?></span>
                            </a>
                            <a href="?status=Completed&priority=<?php echo $priority_filter; ?>&date=<?php echo $date_filter; ?>&search=<?php echo urlencode($search_query); ?>" 
                               class="filter-btn <?php echo $status_filter === 'Completed' ? 'active' : ''; ?>">
                                Completed
                                <span class="badge"><?php echo $stats['completed']; ?></span>
                            </a>
                            <a href="?status=On Hold&priority=<?php echo $priority_filter; ?>&date=<?php echo $date_filter; ?>&search=<?php echo urlencode($search_query); ?>" 
                               class="filter-btn <?php echo $status_filter === 'On Hold' ? 'active' : ''; ?>">
                                On Hold
                                <span class="badge"><?php echo $stats['on_hold']; ?></span>
                            </a>
                        </div>
                    </div>
                    
                    <div class="filter-section">
                        <h3>Priority</h3>
                        <div class="filter-group">
                            <a href="?status=<?php echo $status_filter; ?>&priority=all&date=<?php echo $date_filter; ?>&search=<?php echo urlencode($search_query); ?>" 
                               class="filter-btn <?php echo empty($priority_filter) || $priority_filter === 'all' ? 'active' : ''; ?>">
                                All Priorities
                            </a>
                            <a href="?status=<?php echo $status_filter; ?>&priority=Critical&date=<?php echo $date_filter; ?>&search=<?php echo urlencode($search_query); ?>" 
                               class="filter-btn <?php echo $priority_filter === 'Critical' ? 'active' : ''; ?>">
                                Critical
                                <span class="badge"><?php echo $stats['critical']; ?></span>
                            </a>
                            <a href="?status=<?php echo $status_filter; ?>&priority=High&date=<?php echo $date_filter; ?>&search=<?php echo urlencode($search_query); ?>" 
                               class="filter-btn <?php echo $priority_filter === 'High' ? 'active' : ''; ?>">
                                High
                                <span class="badge"><?php echo $stats['high']; ?></span>
                            </a>
                            <a href="?status=<?php echo $status_filter; ?>&priority=Medium&date=<?php echo $date_filter; ?>&search=<?php echo urlencode($search_query); ?>" 
                               class="filter-btn <?php echo $priority_filter === 'Medium' ? 'active' : ''; ?>">
                                Medium
                                <span class="badge"><?php echo $stats['medium']; ?></span>
                            </a>
                            <a href="?status=<?php echo $status_filter; ?>&priority=Low&date=<?php echo $date_filter; ?>&search=<?php echo urlencode($search_query); ?>" 
                               class="filter-btn <?php echo $priority_filter === 'Low' ? 'active' : ''; ?>">
                                Low
                                <span class="badge"><?php echo $stats['low']; ?></span>
                            </a>
                        </div>
                    </div>
                    
                    <div class="filter-section">
                        <h3>Due Date</h3>
                        <div class="filter-group">
                            <a href="?status=<?php echo $status_filter; ?>&priority=<?php echo $priority_filter; ?>&date=&search=<?php echo urlencode($search_query); ?>" 
                               class="filter-btn <?php echo empty($date_filter) ? 'active' : ''; ?>">
                                All Dates
                            </a>
                            <a href="?status=<?php echo $status_filter; ?>&priority=<?php echo $priority_filter; ?>&date=overdue&search=<?php echo urlencode($search_query); ?>" 
                               class="filter-btn <?php echo $date_filter === 'overdue' ? 'active' : ''; ?>">
                                Overdue
                                <span class="badge"><?php echo $stats['overdue']; ?></span>
                            </a>
                            <a href="?status=<?php echo $status_filter; ?>&priority=<?php echo $priority_filter; ?>&date=today&search=<?php echo urlencode($search_query); ?>" 
                               class="filter-btn <?php echo $date_filter === 'today' ? 'active' : ''; ?>">
                                Today
                                <span class="badge"><?php echo $stats['today']; ?></span>
                            </a>
                            <a href="?status=<?php echo $status_filter; ?>&priority=<?php echo $priority_filter; ?>&date=tomorrow&search=<?php echo urlencode($search_query); ?>" 
                               class="filter-btn <?php echo $date_filter === 'tomorrow' ? 'active' : ''; ?>">
                                Tomorrow
                                <span class="badge"><?php echo $stats['tomorrow']; ?></span>
                            </a>
                            <a href="?status=<?php echo $status_filter; ?>&priority=<?php echo $priority_filter; ?>&date=week&search=<?php echo urlencode($search_query); ?>" 
                               class="filter-btn <?php echo $date_filter === 'week' ? 'active' : ''; ?>">
                                Next 7 Days
                            </a>
                        </div>
                    </div>
                    
                    <?php if (!empty($request_types)): ?>
                    <div class="filter-section">
                        <h3>Request Type</h3>
                        <div class="filter-group">
                            <a href="?status=<?php echo $status_filter; ?>&priority=<?php echo $priority_filter; ?>&date=<?php echo $date_filter; ?>&search=<?php echo urlencode($search_query); ?>" 
                               class="filter-btn <?php echo empty($_GET['type']) ? 'active' : ''; ?>">
                                All Types
                            </a>
                            <?php foreach ($request_types as $type): ?>
                            <a href="?status=<?php echo $status_filter; ?>&priority=<?php echo $priority_filter; ?>&date=<?php echo $date_filter; ?>&search=<?php echo urlencode($search_query); ?>&type=<?php echo urlencode($type); ?>" 
                               class="filter-btn <?php echo isset($_GET['type']) && $_GET['type'] === $type ? 'active' : ''; ?>">
                                <?php echo htmlspecialchars($type); ?>
                            </a>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <?php endif; ?>
                    
                    <a href="my_tasks.php" class="clear-filters">
                        <i class="fas fa-times"></i> Clear All Filters
                    </a>
                </div>
                
                <!-- Tasks Main Content -->
                <div class="tasks-main">
                    <div class="tasks-header">
                        <div>
                            <h1>Assigned Maintenance Requests</h1>
                            <p class="task-count">Showing <?php echo count($tasks); ?> of <?php echo $total_tasks; ?> tasks</p>
                        </div>
                        <div class="export-buttons">
                            <button onclick="window.print()" class="btn-export print">
                                <i class="fas fa-print"></i> Print
                            </button>
                        </div>
                    </div>
                    
                    <div class="tasks-table-container">
                        <?php if (!empty($tasks)): ?>
                            <table class="table">
                                <thead>
                                    <tr>
                                        <th>ID</th>
                                        <th>Subject & Details</th>
                                        <th>Equipment</th>
                                        <th>Priority</th>
                                        <th>Status</th>
                                        <th>Due Date</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($tasks as $task): 
                                        $status_class = 'status-' . strtolower(str_replace(' ', '-', $task['status']));
                                        $priority_class = 'priority-' . strtolower($task['priority']);
                                        $type_class = 'type-' . strtolower($task['type']);
                                        
                                        // Determine row class based on date
                                        $row_class = '';
                                        $date_badge = '';
                                        if (isset($task['is_overdue']) && $task['is_overdue']) {
                                            $row_class = 'overdue';
                                            $date_badge = '<span class="date-badge date-overdue">Overdue</span>';
                                        } elseif (isset($task['is_today']) && $task['is_today']) {
                                            $row_class = 'today';
                                            $date_badge = '<span class="date-badge date-today">Today</span>';
                                        } elseif (isset($task['is_tomorrow']) && $task['is_tomorrow']) {
                                            $row_class = 'tomorrow';
                                            $date_badge = '<span class="date-badge date-tomorrow">Tomorrow</span>';
                                        }
                                    ?>
                                    <tr class="<?php echo $row_class; ?>">
                                        <td>
                                            <strong><?php echo htmlspecialchars($task['request_number']); ?></strong>
                                            <div class="task-details">
                                                <small>Created: <?php echo date('M d, Y', strtotime($task['created_at'])); ?></small>
                                            </div>
                                            <?php if ($task['type']): ?>
                                                <div class="type-badge <?php echo $type_class; ?>">
                                                    <?php echo $task['type']; ?>
                                                </div>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <div style="font-weight: 500; color: #333; margin-bottom: 5px;">
                                                <?php echo htmlspecialchars($task['subject']); ?>
                                            </div>
                                            <?php if (!empty($task['description'])): ?>
                                                <div class="task-details">
                                                    <?php echo substr(htmlspecialchars($task['description']), 0, 100); ?>
                                                    <?php if (strlen($task['description']) > 100): ?>...<?php endif; ?>
                                                </div>
                                            <?php endif; ?>
                                            <?php if ($task['requester_name']): ?>
                                                <div class="task-details">
                                                    <i class="fas fa-user"></i> 
                                                    <?php echo htmlspecialchars($task['requester_name']); ?>
                                                    <?php if ($task['requester_email']): ?>
                                                        <br><small><?php echo htmlspecialchars($task['requester_email']); ?></small>
                                                    <?php endif; ?>
                                                </div>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <div style="font-weight: 500; margin-bottom: 5px;">
                                                <?php echo htmlspecialchars($task['equipment_name']); ?>
                                            </div>
                                            <div class="equipment-info">
                                                <?php if ($task['location']): ?>
                                                    <div><i class="fas fa-map-marker-alt"></i> <?php echo htmlspecialchars($task['location']); ?></div>
                                                <?php endif; ?>
                                                <?php if ($task['department_name']): ?>
                                                    <div><i class="fas fa-building"></i> <?php echo htmlspecialchars($task['department_name']); ?></div>
                                                <?php endif; ?>
                                                <?php if ($task['equipment_category']): ?>
                                                    <div><i class="fas fa-tag"></i> <?php echo htmlspecialchars($task['equipment_category']); ?></div>
                                                <?php endif; ?>
                                                <?php if ($task['serial_number']): ?>
                                                    <div><i class="fas fa-barcode"></i> <?php echo htmlspecialchars($task['serial_number']); ?></div>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                        <td>
                                            <span class="priority-badge <?php echo $priority_class; ?>">
                                                <?php echo $task['priority']; ?>
                                            </span>
                                        </td>
                                        <td>
                                            <span class="status-badge <?php echo $status_class; ?>">
                                                <?php echo $task['status']; ?>
                                            </span>
                                        </td>
                                        <td>
                                            <?php if ($task['scheduled_date']): ?>
                                                <div style="font-weight: 500;">
                                                    <?php echo date('M d, Y', strtotime($task['scheduled_date'])); ?>
                                                    <?php echo $date_badge; ?>
                                                </div>
                                                <?php if (isset($task['days_difference'])): ?>
                                                    <div class="task-details">
                                                        <?php if ($task['is_overdue']): ?>
                                                            <span style="color: #f44336;">
                                                                <i class="fas fa-exclamation-circle"></i> 
                                                                <?php echo $task['days_difference']; ?> days overdue
                                                            </span>
                                                        <?php elseif ($task['is_today']): ?>
                                                            <span style="color: #FF9800;">
                                                                <i class="fas fa-calendar-day"></i> Due today
                                                            </span>
                                                        <?php elseif ($task['is_tomorrow']): ?>
                                                            <span style="color: #2196F3;">
                                                                <i class="fas fa-calendar-check"></i> Due tomorrow
                                                            </span>
                                                        <?php elseif ($task['days_difference'] > 0): ?>
                                                            <span style="color: #4CAF50;">
                                                                <i class="fas fa-clock"></i> 
                                                                <?php echo $task['days_difference']; ?> days left
                                                            </span>
                                                        <?php endif; ?>
                                                    </div>
                                                <?php endif; ?>
                                            <?php else: ?>
                                                <span style="color: #999; font-style: italic;">Not scheduled</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <div class="action-buttons">
                                                <a href="../view_request.php?id=<?php echo $task['id']; ?>" class="btn-action btn-view">
                                                    <i class="fas fa-eye"></i> View
                                                </a>
                                                
                                                <?php if ($task['status'] === 'New' || $task['status'] === 'On Hold'): ?>
                                                    <a href="../update_status.php?id=<?php echo $task['id']; ?>&status=In Progress" 
                                                       class="btn-action btn-start">
                                                        <i class="fas fa-play"></i> Start
                                                    </a>
                                                <?php endif; ?>
                                                
                                                <?php if ($task['status'] === 'In Progress'): ?>
                                                    <a href="../update_status.php?id=<?php echo $task['id']; ?>&status=Completed" 
                                                       class="btn-action btn-complete"
                                                       onclick="return confirm('Mark this task as completed?')">
                                                        <i class="fas fa-check"></i> Complete
                                                    </a>
                                                    <a href="../update_status.php?id=<?php echo $task['id']; ?>&status=On Hold" 
                                                       class="btn-action btn-hold">
                                                        <i class="fas fa-pause"></i> Hold
                                                    </a>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                            
                            <!-- Pagination -->
                            <?php if ($total_pages > 1): ?>
                                <div class="pagination">
                                    <a href="?page=1&status=<?php echo $status_filter; ?>&priority=<?php echo $priority_filter; ?>&date=<?php echo $date_filter; ?>&search=<?php echo urlencode($search_query); ?>" 
                                       class="page-link <?php echo $page == 1 ? 'disabled' : ''; ?>">
                                        <i class="fas fa-angle-double-left"></i>
                                    </a>
                                    <a href="?page=<?php echo max(1, $page - 1); ?>&status=<?php echo $status_filter; ?>&priority=<?php echo $priority_filter; ?>&date=<?php echo $date_filter; ?>&search=<?php echo urlencode($search_query); ?>" 
                                       class="page-link <?php echo $page == 1 ? 'disabled' : ''; ?>">
                                        <i class="fas fa-angle-left"></i>
                                    </a>
                                    
                                    <?php 
                                    $start_page = max(1, $page - 2);
                                    $end_page = min($total_pages, $start_page + 4);
                                    
                                    for ($i = $start_page; $i <= $end_page; $i++): 
                                    ?>
                                        <a href="?page=<?php echo $i; ?>&status=<?php echo $status_filter; ?>&priority=<?php echo $priority_filter; ?>&date=<?php echo $date_filter; ?>&search=<?php echo urlencode($search_query); ?>" 
                                           class="page-link <?php echo $i == $page ? 'active' : ''; ?>">
                                            <?php echo $i; ?>
                                        </a>
                                    <?php endfor; ?>
                                    
                                    <a href="?page=<?php echo min($total_pages, $page + 1); ?>&status=<?php echo $status_filter; ?>&priority=<?php echo $priority_filter; ?>&date=<?php echo $date_filter; ?>&search=<?php echo urlencode($search_query); ?>" 
                                       class="page-link <?php echo $page == $total_pages ? 'disabled' : ''; ?>">
                                        <i class="fas fa-angle-right"></i>
                                    </a>
                                    <a href="?page=<?php echo $total_pages; ?>&status=<?php echo $status_filter; ?>&priority=<?php echo $priority_filter; ?>&date=<?php echo $date_filter; ?>&search=<?php echo urlencode($search_query); ?>" 
                                       class="page-link <?php echo $page == $total_pages ? 'disabled' : ''; ?>">
                                        <i class="fas fa-angle-double-right"></i>
                                    </a>
                                </div>
                            <?php endif; ?>
                            
                        <?php else: ?>
                            <div class="empty-state">
                                <div class="empty-icon">📋</div>
                                <h3>No tasks found</h3>
                                <p>No maintenance requests are currently assigned to you.</p>
                                <?php if (!empty($status_filter) || !empty($priority_filter) || !empty($date_filter) || !empty($search_query)): ?>
                                    <p>Try clearing your filters to see all tasks.</p>
                                    <a href="my_tasks.php" class="btn-action btn-view" style="margin-top: 15px;">
                                        <i class="fas fa-redo"></i> Clear Filters
                                    </a>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </main>
    </div>
    
    <script>
        // Auto-submit search on enter
        document.querySelector('.search-input')?.addEventListener('keypress', function(e) {
            if (e.key === 'Enter') {
                this.form.submit();
            }
        });
        
        // Add hover effects to table rows
        document.querySelectorAll('.table tbody tr').forEach(row => {
            row.addEventListener('mouseenter', function() {
                if (!this.classList.contains('overdue') && !this.classList.contains('today') && !this.classList.contains('tomorrow')) {
                    this.style.transform = 'translateY(-2px)';
                    this.style.boxShadow = '0 5px 15px rgba(0,0,0,0.1)';
                }
            });
            
            row.addEventListener('mouseleave', function() {
                if (!this.classList.contains('overdue') && !this.classList.contains('today') && !this.classList.contains('tomorrow')) {
                    this.style.transform = '';
                    this.style.boxShadow = '';
                }
            });
        });
        
        // Quick status update confirmation
        document.querySelectorAll('.btn-complete').forEach(btn => {
            btn.addEventListener('click', function(e) {
                if (!confirm('Are you sure you want to mark this task as completed?')) {
                    e.preventDefault();
                }
            });
        });
        
        // Real-time updates (simulate with setInterval)
        let lastUpdate = Date.now();
        
        function checkForUpdates() {
            fetch(`?check_update=1&last=${lastUpdate}`)
                .then(response => response.json())
                .then(data => {
                    if (data.has_update) {
                        // Show notification
                        showNotification('New tasks have been assigned to you!', 'info');
                        lastUpdate = Date.now();
                    }
                })
                .catch(error => console.error('Update check failed:', error));
        }
        
        // Check for updates every 30 seconds
        setInterval(checkForUpdates, 30000);
        
        // Notification function
        function showNotification(message, type = 'info') {
            const notification = document.createElement('div');
            notification.style.cssText = `
                position: fixed;
                top: 20px;
                right: 20px;
                background: ${type === 'info' ? '#2196F3' : '#4CAF50'};
                color: white;
                padding: 15px 20px;
                border-radius: 10px;
                box-shadow: 0 5px 15px rgba(0,0,0,0.2);
                z-index: 10000;
                display: flex;
                align-items: center;
                gap: 10px;
                animation: slideIn 0.3s ease-out;
            `;
            
            notification.innerHTML = `
                <i class="fas fa-bell"></i>
                <div>
                    <strong>Update Available</strong>
                    <div style="font-size: 0.9rem; opacity: 0.9;">${message}</div>
                </div>
                <button onclick="this.parentElement.remove()" style="background: none; border: none; color: white; cursor: pointer; margin-left: 10px;">
                    <i class="fas fa-times"></i>
                </button>
            `;
            
            document.body.appendChild(notification);
            
            // Auto-remove after 5 seconds
            setTimeout(() => {
                if (notification.parentElement) {
                    notification.remove();
                }
            }, 5000);
        }
        
        // Print-specific styles
        const printStyles = `
            @media print {
                .sidebar, .header, .filters-sidebar, .export-buttons, 
                .action-buttons, .pagination, .date-badge, .stats-cards,
                .quick-actions, .page-subtitle, .btn-logout, .user-info {
                    display: none !important;
                }
                
                .tasks-container {
                    display: block !important;
                }
                
                .tasks-main {
                    width: 100% !important;
                }
                
                .table {
                    width: 100%;
                    border: 1px solid #000 !important;
                }
                
                .table th, .table td {
                    border: 1px solid #000 !important;
                    padding: 8px !important;
                }
                
                .status-badge, .priority-badge {
                    border: 1px solid #000 !important;
                    background: white !important;
                    color: #000 !important;
                }
                
                h1 {
                    text-align: center;
                }
                
                body {
                    background: white !important;
                }
            }
            
            @keyframes slideIn {
                from {
                    transform: translateX(100%);
                    opacity: 0;
                }
                to {
                    transform: translateX(0);
                    opacity: 1;
                }
            }
        `;
        
        // Add print styles
        const styleSheet = document.createElement('style');
        styleSheet.type = 'text/css';
        styleSheet.innerText = printStyles;
        document.head.appendChild(styleSheet);
        
        // Mobile menu toggle
        const menuToggle = document.createElement('button');
        menuToggle.className = 'menu-toggle';
        menuToggle.innerHTML = '☰';
        menuToggle.style.cssText = 'display: none; background: none; border: none; color: white; font-size: 1.5rem; cursor: pointer; margin-right: 15px;';
        
        menuToggle.addEventListener('click', function() {
            document.querySelector('.sidebar').classList.toggle('active');
        });
        
        document.querySelector('.header-left').prepend(menuToggle);
        
        // Show menu toggle on mobile
        if (window.innerWidth <= 768) {
            menuToggle.style.display = 'block';
        }
        
        window.addEventListener('resize', function() {
            if (window.innerWidth <= 768) {
                menuToggle.style.display = 'block';
            } else {
                menuToggle.style.display = 'none';
                document.querySelector('.sidebar').classList.remove('active');
            }
        });
    </script>
</body>
</html>