<?php
// technician.php - Technician Dashboard
session_start();

// Check if user is logged in and is a technician
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'technician') {
    header('Location: ../index.php');
    exit();
}

// Database connection
require_once '../includes/db_connect.php';

$user_id = $_SESSION['user_id'];
$user_name = $_SESSION['full_name'];

// Get technician's assigned requests and statistics
$stats = [
    'total_assigned' => 0,
    'in_progress' => 0,
    'completed' => 0,
    'pending' => 0,
    'today_tasks' => 0,
    'overdue' => 0
];

// Get statistics
$query = "SELECT 
    COUNT(*) as total_assigned,
    SUM(CASE WHEN status = 'In Progress' THEN 1 ELSE 0 END) as in_progress,
    SUM(CASE WHEN status = 'Completed' THEN 1 ELSE 0 END) as completed,
    SUM(CASE WHEN status = 'New' THEN 1 ELSE 0 END) as pending,
    SUM(CASE WHEN DATE(scheduled_date) = CURDATE() THEN 1 ELSE 0 END) as today_tasks,
    SUM(CASE WHEN scheduled_date < CURDATE() AND status NOT IN ('Completed', 'Cancelled') THEN 1 ELSE 0 END) as overdue
    FROM maintenance_requests 
    WHERE assigned_to = ?";
$stmt = mysqli_prepare($conn, $query);
mysqli_stmt_bind_param($stmt, 'i', $user_id);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);

if ($row = mysqli_fetch_assoc($result)) {
    $stats = $row;
}

// Get recent assigned requests
$recent_requests = [];
$recent_query = "SELECT mr.*, e.name as equipment_name, e.location,
                        d.name as department_name
                 FROM maintenance_requests mr
                 LEFT JOIN equipment e ON mr.equipment_id = e.id
                 LEFT JOIN departments d ON e.department_id = d.id
                 WHERE mr.assigned_to = ?
                 ORDER BY mr.priority DESC, mr.created_at DESC
                 LIMIT 10";
$recent_stmt = mysqli_prepare($conn, $recent_query);
mysqli_stmt_bind_param($recent_stmt, 'i', $user_id);
mysqli_stmt_execute($recent_stmt);
$recent_result = mysqli_stmt_get_result($recent_stmt);

while ($row = mysqli_fetch_assoc($recent_result)) {
    if (empty($row['request_number'])) {
        $row['request_number'] = 'REQ-' . str_pad($row['id'], 6, '0', STR_PAD_LEFT);
    }
    $recent_requests[] = $row;
}

// Get today's tasks
$today_tasks = [];
$today_query = "SELECT mr.*, e.name as equipment_name, e.location
                FROM maintenance_requests mr
                LEFT JOIN equipment e ON mr.equipment_id = e.id
                WHERE mr.assigned_to = ? 
                AND DATE(mr.scheduled_date) = CURDATE()
                AND mr.status NOT IN ('Completed', 'Cancelled')
                ORDER BY mr.priority DESC";
$today_stmt = mysqli_prepare($conn, $today_query);
mysqli_stmt_bind_param($today_stmt, 'i', $user_id);
mysqli_stmt_execute($today_stmt);
$today_result = mysqli_stmt_get_result($today_stmt);

while ($row = mysqli_fetch_assoc($today_result)) {
    $today_tasks[] = $row;
}

mysqli_close($conn);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Technician Dashboard - GearGuard</title>
    <link rel="stylesheet" href="../css/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        .dashboard-cards {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        
        .card {
            background: white;
            border-radius: 10px;
            padding: 25px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.08);
            transition: transform 0.3s, box-shadow 0.3s;
            border-left: 4px solid #2196F3;
        }
        
        .card:hover {
            transform: translateY(-5px);
            box-shadow: 0 5px 20px rgba(0,0,0,0.12);
        }
        
        .card-icon {
            font-size: 2.5rem;
            margin-bottom: 15px;
            display: inline-block;
            padding: 15px;
            border-radius: 10px;
            background: #e3f2fd;
            color: #1976d2;
        }
        
        .card h3 {
            font-size: 2.2rem;
            margin: 10px 0;
            color: #333;
        }
        
        .card p {
            color: #666;
            margin-bottom: 5px;
        }
        
        .sub-stat {
            margin-top: 10px;
            font-size: 0.9rem;
            padding: 5px 10px;
            border-radius: 4px;
            display: inline-block;
        }
        
        .sub-stat.warning {
            background: #fff3e0;
            color: #f57c00;
        }
        
        .sub-stat.success {
            background: #e8f5e9;
            color: #2e7d32;
        }
        
        .table-container {
            background: white;
            border-radius: 10px;
            padding: 25px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.08);
            margin-bottom: 30px;
        }
        
        .table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 15px;
        }
        
        .table th {
            background: #f5f5f5;
            padding: 15px;
            text-align: left;
            font-weight: 600;
            color: #555;
            border-bottom: 2px solid #e0e0e0;
        }
        
        .table td {
            padding: 15px;
            border-bottom: 1px solid #eee;
        }
        
        .table tr:hover {
            background: #f9f9f9;
        }
        
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
        .status-scheduled { background: #e3f2fd; color: #1976d2; }
        .status-cancelled { background: #f5f5f5; color: #616161; }
        
        .priority-badge {
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 0.85rem;
            font-weight: 500;
        }
        
        .priority-low { background: #e8f5e9; color: #2e7d32; }
        .priority-medium { background: #fff3e0; color: #f57c00; }
        .priority-high { background: #ffebee; color: #c62828; }
        .priority-critical { background: #f44336; color: white; }
        
        .action-btn {
            padding: 6px 12px;
            background: #2196F3;
            color: white;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 0.85rem;
            text-decoration: none;
            display: inline-block;
        }
        
        .action-btn:hover {
            background: #1976d2;
        }
        
        .action-btn.complete {
            background: #4CAF50;
        }
        
        .action-btn.complete:hover {
            background: #388e3c;
        }
        
        .today-highlight {
            background: #fff8e1 !important;
            border-left: 3px solid #ffc107;
        }
        
        .overdue-highlight {
            background: #ffebee !important;
            border-left: 3px solid #f44336;
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
        
        .welcome-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 30px;
        }
        
        .welcome-message h1 {
            color: #333;
            margin-bottom: 5px;
        }
        
        .welcome-message p {
            color: #666;
        }
        
        .quick-actions {
            display: flex;
            gap: 10px;
        }
        
        @media (max-width: 768px) {
            .dashboard-cards {
                grid-template-columns: 1fr;
            }
            
            .welcome-header {
                flex-direction: column;
                gap: 15px;
                text-align: center;
            }
            
            .quick-actions {
                justify-content: center;
            }
        }
    </style>
</head>
<body>
    <?php include '../includes/header.php'; ?>
    
    <div class="container">
        <?php include '../includes/sidebartec.php'; ?>
        
        <main class="main-content">
            <!-- Welcome Header -->
            <div class="welcome-header">
                <div class="welcome-message">
                    <h1>Welcome, <?php echo htmlspecialchars($user_name); ?>! 👋</h1>
                    <p>Technician Dashboard • <?php echo date('F j, Y'); ?></p>
                </div>
                <div class="quick-actions">
                    <a href="create_request.php" class="action-btn">
                        <i class="fas fa-plus"></i> New Request
                    </a>
                    <a href="my_tasks.php" class="action-btn">
                        <i class="fas fa-tasks"></i> My Tasks
                    </a>
                </div>
            </div>
            
            <!-- Statistics Cards -->
            <div class="dashboard-cards">
                <div class="card">
                    <div class="card-icon">
                        📋
                    </div>
                    <h3><?php echo $stats['total_assigned']; ?></h3>
                    <p>Total Assigned</p>
                    <div class="sub-stat success">
                        ✅ <?php echo $stats['completed']; ?> Completed
                    </div>
                </div>
                
                <div class="card">
                    <div class="card-icon">
                        🛠️
                    </div>
                    <h3><?php echo $stats['in_progress']; ?></h3>
                    <p>In Progress</p>
                    <div class="sub-stat">
                        🔄 Active Work
                    </div>
                </div>
                
                <div class="card">
                    <div class="card-icon">
                        ⏰
                    </div>
                    <h3><?php echo $stats['today_tasks']; ?></h3>
                    <p>Today's Tasks</p>
                    <div class="sub-stat warning">
                        ⚠️ <?php echo $stats['overdue']; ?> Overdue
                    </div>
                </div>
                
                <div class="card">
                    <div class="card-icon">
                        📊
                    </div>
                    <h3><?php echo $stats['pending']; ?></h3>
                    <p>Pending</p>
                    <div class="sub-stat">
                        ⏳ Awaiting Action
                    </div>
                </div>
            </div>
            
            <!-- Today's Tasks -->
            <div class="table-container">
                <h2>Today's Tasks</h2>
                <?php if (!empty($today_tasks)): ?>
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Request #</th>
                                <th>Subject</th>
                                <th>Equipment</th>
                                <th>Priority</th>
                                <th>Status</th>
                                <th>Scheduled</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($today_tasks as $task): 
                                $status_class = 'status-' . strtolower(str_replace(' ', '-', $task['status']));
                                $priority_class = 'priority-' . strtolower($task['priority']);
                                $row_class = '';
                                if (strtotime($task['scheduled_date']) < strtotime('today')) {
                                    $row_class = 'overdue-highlight';
                                }
                            ?>
                            <tr class="<?php echo $row_class; ?>">
                                <td>
                                    <strong><?php echo htmlspecialchars($task['request_number']); ?></strong>
                                </td>
                                <td><?php echo htmlspecialchars($task['subject']); ?></td>
                                <td><?php echo htmlspecialchars($task['equipment_name']); ?></td>
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
                                <td><?php echo date('h:i A', strtotime($task['scheduled_date'])); ?></td>
                                <td>
                                    <a href="view_request.php?id=<?php echo $task['id']; ?>" class="action-btn">
                                        <i class="fas fa-eye"></i> View
                                    </a>
                                    <?php if ($task['status'] !== 'Completed'): ?>
                                        <a href="update_status.php?id=<?php echo $task['id']; ?>&status=In Progress" class="action-btn">
                                            <i class="fas fa-play"></i> Start
                                        </a>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php else: ?>
                    <div class="empty-state">
                        <div class="empty-icon">🎉</div>
                        <h3>No tasks scheduled for today!</h3>
                        <p>You're all caught up. Check upcoming tasks below.</p>
                    </div>
                <?php endif; ?>
            </div>
            
            <!-- Recent Assigned Requests -->
            <div class="table-container">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px;">
                    <h2>My Assigned Requests</h2>
                    <a href="my_tasks.php" class="action-btn">View All</a>
                </div>
                
                <?php if (!empty($recent_requests)): ?>
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Request #</th>
                                <th>Subject</th>
                                <th>Equipment</th>
                                <th>Department</th>
                                <th>Priority</th>
                                <th>Status</th>
                                <th>Created</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($recent_requests as $request): 
                                $status_class = 'status-' . strtolower(str_replace(' ', '-', $request['status']));
                                $priority_class = 'priority-' . strtolower($request['priority']);
                                $row_class = '';
                                if (strtotime($request['scheduled_date']) < strtotime('today') && !in_array($request['status'], ['Completed', 'Cancelled'])) {
                                    $row_class = 'overdue-highlight';
                                } elseif (date('Y-m-d', strtotime($request['scheduled_date'])) == date('Y-m-d')) {
                                    $row_class = 'today-highlight';
                                }
                            ?>
                            <tr class="<?php echo $row_class; ?>">
                                <td>
                                    <strong><?php echo htmlspecialchars($request['request_number']); ?></strong>
                                </td>
                                <td><?php echo htmlspecialchars($request['subject']); ?></td>
                                <td><?php echo htmlspecialchars($request['equipment_name']); ?></td>
                                <td><?php echo htmlspecialchars($request['department_name'] ?? 'N/A'); ?></td>
                                <td>
                                    <span class="priority-badge <?php echo $priority_class; ?>">
                                        <?php echo $request['priority']; ?>
                                    </span>
                                </td>
                                <td>
                                    <span class="status-badge <?php echo $status_class; ?>">
                                        <?php echo $request['status']; ?>
                                    </span>
                                </td>
                                <td><?php echo date('M d', strtotime($request['created_at'])); ?></td>
                                <td>
                                    <a href="view_request.php?id=<?php echo $request['id']; ?>" class="action-btn" style="margin-right: 5px;">
                                        <i class="fas fa-eye"></i>
                                    </a>
                                    <?php if ($request['status'] !== 'Completed'): ?>
                                        <a href="update_status.php?id=<?php echo $request['id']; ?>&status=In Progress" class="action-btn" style="margin-right: 5px;">
                                            <i class="fas fa-play"></i>
                                        </a>
                                        <a href="update_status.php?id=<?php echo $request['id']; ?>&status=Completed" class="action-btn complete">
                                            <i class="fas fa-check"></i>
                                        </a>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php else: ?>
                    <div class="empty-state">
                        <div class="empty-icon">📋</div>
                        <h3>No requests assigned yet</h3>
                        <p>You will see requests here when they are assigned to you.</p>
                    </div>
                <?php endif; ?>
            </div>
            
            <!-- Quick Stats -->
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 20px;">
                <!-- Equipment Types -->
                <div class="table-container">
                    <h3>Equipment Types</h3>
                    <div id="equipmentChart" style="height: 200px; margin-top: 20px;">
                        <p style="color: #666; text-align: center; padding: 40px 0;">
                            Chart showing equipment distribution
                        </p>
                    </div>
                </div>
                
                <!-- Upcoming Schedule -->
                <div class="table-container">
                    <h3>Upcoming Schedule</h3>
                    <div style="margin-top: 20px;">
                        <?php
                        // Get upcoming tasks for next 7 days
                        require_once '../includes/db_connect.php';
                        $upcoming_query = "SELECT mr.subject, e.name as equipment_name, mr.scheduled_date
                                          FROM maintenance_requests mr
                                          LEFT JOIN equipment e ON mr.equipment_id = e.id
                                          WHERE mr.assigned_to = ?
                                          AND mr.scheduled_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY)
                                          AND mr.status NOT IN ('Completed', 'Cancelled')
                                          ORDER BY mr.scheduled_date
                                          LIMIT 5";
                        $upcoming_stmt = mysqli_prepare($conn, $upcoming_query);
                        mysqli_stmt_bind_param($upcoming_stmt, 'i', $user_id);
                        mysqli_stmt_execute($upcoming_stmt);
                        $upcoming_result = mysqli_stmt_get_result($upcoming_stmt);
                        
                        if (mysqli_num_rows($upcoming_result) > 0): ?>
                            <ul style="list-style: none; padding: 0;">
                                <?php while ($upcoming = mysqli_fetch_assoc($upcoming_result)): ?>
                                <li style="padding: 12px 0; border-bottom: 1px solid #eee; display: flex; justify-content: space-between;">
                                    <div>
                                        <strong><?php echo htmlspecialchars($upcoming['subject']); ?></strong>
                                        <div style="color: #666; font-size: 0.9rem;"><?php echo htmlspecialchars($upcoming['equipment_name']); ?></div>
                                    </div>
                                    <div style="color: #2196F3; font-weight: 500;">
                                        <?php echo date('D, M j', strtotime($upcoming['scheduled_date'])); ?>
                                    </div>
                                </li>
                                <?php endwhile; ?>
                            </ul>
                        <?php else: ?>
                            <p style="color: #666; text-align: center; padding: 20px 0;">
                                No upcoming tasks for the next week.
                            </p>
                        <?php endif; 
                        mysqli_close($conn);
                        ?>
                    </div>
                </div>
            </div>
        </main>
    </div>
    
    <script>
        // Update task status
        document.querySelectorAll('.action-btn.complete').forEach(btn => {
            btn.addEventListener('click', function(e) {
                if (!confirm('Mark this task as completed?')) {
                    e.preventDefault();
                }
            });
        });
        
        // Auto-refresh dashboard every 60 seconds
        setTimeout(() => {
            window.location.reload();
        }, 60000);
        
        // Highlight overdue tasks
        document.querySelectorAll('.overdue-highlight').forEach(row => {
            row.addEventListener('mouseenter', function() {
                this.style.transform = 'scale(1.01)';
                this.style.boxShadow = '0 2px 8px rgba(244, 67, 54, 0.2)';
            });
            row.addEventListener('mouseleave', function() {
                this.style.transform = '';
                this.style.boxShadow = '';
            });
        });
        
        // Add notification for new assignments (simulated)
        if (Math.random() > 0.7) {
            setTimeout(() => {
                const notification = document.createElement('div');
                notification.style.cssText = `
                    position: fixed;
                    bottom: 20px;
                    right: 20px;
                    background: #4CAF50;
                    color: white;
                    padding: 15px;
                    border-radius: 10px;
                    box-shadow: 0 4px 12px rgba(0,0,0,0.15);
                    z-index: 1000;
                    max-width: 300px;
                `;
                notification.innerHTML = `
                    <strong>📨 New Assignment</strong>
                    <p style="margin: 5px 0 0; font-size: 0.9rem;">
                        You have been assigned a new maintenance request.
                    </p>
                `;
                document.body.appendChild(notification);
                
                setTimeout(() => {
                    notification.remove();
                }, 5000);
            }, 2000);
        }
    </script>
</body>
</html>