<?php
// dashboard.php - OPTIMIZED VERSION
session_start();

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: ../index.php');
    exit();
}

// Database connection with error handling
require_once '../config.php'; // Consider moving DB connection to separate file

// Get statistics in a single query where possible
$stats = [
    'equipment' => 0,
    'teams' => 0,
    'requests' => 0,
    'pending' => 0,
    'active_equipment' => 0,
    'corrective' => 0,
    'preventive' => 0,
    'in_progress' => 0,
    'completed' => 0
];

try {
    // Main statistics query
    $query = "SELECT 
        (SELECT COUNT(*) FROM equipment) as total_equipment,
        (SELECT COUNT(*) FROM maintenance_teams) as total_teams,
        (SELECT COUNT(*) FROM maintenance_requests) as total_requests,
        (SELECT COUNT(*) FROM maintenance_requests WHERE status = 'New') as pending_requests,
        (SELECT COUNT(*) FROM equipment WHERE status = 'active') as active_equipment,
        (SELECT COUNT(*) FROM maintenance_requests WHERE type = 'Corrective') as corrective_requests,
        (SELECT COUNT(*) FROM maintenance_requests WHERE type = 'Preventive') as preventive_requests,
        (SELECT COUNT(*) FROM maintenance_requests WHERE status = 'In Progress') as in_progress,
        (SELECT COUNT(*) FROM maintenance_requests WHERE status = 'Repaired') as completed";
    
    $result = mysqli_query($conn, $query);
    
    if ($result && $row = mysqli_fetch_assoc($result)) {
        $stats['equipment'] = $row['total_equipment'];
        $stats['teams'] = $row['total_teams'];
        $stats['requests'] = $row['total_requests'];
        $stats['pending'] = $row['pending_requests'];
        $stats['active_equipment'] = $row['active_equipment'];
        $stats['corrective'] = $row['corrective_requests'];
        $stats['preventive'] = $row['preventive_requests'];
        $stats['in_progress'] = $row['in_progress'];
        $stats['completed'] = $row['completed'];
    }
    
    // Recent requests with parameterized query (if needed)
    $recent_requests = [];
    $recent_query = "
        SELECT mr.*, e.name as equipment_name 
        FROM maintenance_requests mr
        LEFT JOIN equipment e ON mr.equipment_id = e.id
        ORDER BY mr.created_at DESC 
        LIMIT 8
    ";
    
    $recent_result = mysqli_query($conn, $recent_query);
    
    if ($recent_result) {
        while ($row = mysqli_fetch_assoc($recent_result)) {
            // Generate request number if not exists
            if (empty($row['request_number'])) {
                $row['request_number'] = 'REQ-' . str_pad($row['id'], 6, '0', STR_PAD_LEFT);
            }
            $recent_requests[] = $row;
        }
    }
    
} catch (Exception $e) {
    error_log("Dashboard error: " . $e->getMessage());
    // You might want to show a user-friendly error message
}

// Close connection at the end
if (isset($conn)) {
    mysqli_close($conn);
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard - GearGuard</title>
    <link rel="stylesheet" href="../css/style.css">
    <style>
        /* Additional dashboard-specific styles */
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
            background: #f5f5f5;
        }
        
        .icon-equipment { background: #e3f2fd; color: #1976d2; }
        .icon-team { background: #f3e5f5; color: #7b1fa2; }
        .icon-request { background: #e8f5e9; color: #388e3c; }
        .icon-pending { background: #fff3e0; color: #f57c00; }
        
        .stats-overview {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin-bottom: 30px;
        }
        
        .stat-card {
            background: white;
            padding: 20px;
            border-radius: 8px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
            border-left: 4px solid #1976d2;
        }
        
        .stat-card:nth-child(2) { border-left-color: #388e3c; }
        .stat-card:nth-child(3) { border-left-color: #f57c00; }
        .stat-card:nth-child(4) { border-left-color: #d32f2f; }
        
        .stat-card .value {
            font-size: 2.2rem;
            font-weight: bold;
            margin: 10px 0;
            color: #333;
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
        .status-repaired { background: #e8f5e9; color: #2e7d32; }
        .status-cancelled { background: #f5f5f5; color: #616161; }
        
        .table-container {
            background: white;
            border-radius: 10px;
            padding: 25px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.08);
            overflow-x: auto;
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
        
        .priority-high { color: #f44336; font-weight: 600; }
        .priority-medium { color: #ff9800; font-weight: 600; }
        .priority-low { color: #4caf50; font-weight: 600; }
        .priority-critical { color: #b71c1c; font-weight: 600; }
        
        @media (max-width: 768px) {
            .dashboard-cards,
            .stats-overview {
                grid-template-columns: 1fr;
            }
            
            .table-container {
                padding: 15px;
            }
            
            .table th,
            .table td {
                padding: 10px;
            }
        }
    </style>
</head>
<body>
    <?php include '../includes/header.php'; ?>
    
    <div class="container">
        <?php include '../includes/sidebar.php'; ?>
        
        <main class="main-content">
            <div class="page-header">
                <h1>Dashboard Overview</h1>
                <span class="date-display"><?php echo date('F j, Y'); ?></span>
            </div>
            
            <!-- Statistics Cards -->
            <div class="dashboard-cards">
                <div class="card">
                    <div class="card-icon icon-equipment">
                        ⚙️
                    </div>
                    <h3><?php echo $stats['equipment']; ?></h3>
                    <p>Total Equipment</p>
                    <div class="sub-stat">
                        ✅ <?php echo $stats['active_equipment']; ?> Active
                    </div>
                </div>
                
                <div class="card">
                    <div class="card-icon icon-team">
                        👥
                    </div>
                    <h3><?php echo $stats['teams']; ?></h3>
                    <p>Maintenance Teams</p>
                    <div class="sub-stat">
                        🛠️ Specialized Teams
                    </div>
                </div>
                
                <div class="card">
                    <div class="card-icon icon-request">
                        📋
                    </div>
                    <h3><?php echo $stats['requests']; ?></h3>
                    <p>Total Requests</p>
                    <div class="sub-stat">
                        📊 Maintenance History
                    </div>
                </div>
                
                <div class="card">
                    <div class="card-icon icon-pending">
                        ⏰
                    </div>
                    <h3><?php echo $stats['pending']; ?></h3>
                    <p>Pending Requests</p>
                    <div class="sub-stat warning">
                        ⚠️ Needs Attention
                    </div>
                </div>
            </div>
            
            <!-- Quick Stats -->
            <div class="stats-overview">
                <div class="stat-card">
                    <div class="stat-label">Corrective Requests</div>
                    <div class="value"><?php echo $stats['corrective']; ?></div>
                    <div class="stat-sub">Breakdown/Repair</div>
                </div>
                
                <div class="stat-card">
                    <div class="stat-label">Preventive Requests</div>
                    <div class="value"><?php echo $stats['preventive']; ?></div>
                    <div class="stat-sub">Scheduled</div>
                </div>
                
                <div class="stat-card">
                    <div class="stat-label">In Progress</div>
                    <div class="value"><?php echo $stats['in_progress']; ?></div>
                    <div class="stat-sub">Being Worked On</div>
                </div>
                
                <div class="stat-card">
                    <div class="stat-label">Completed</div>
                    <div class="value"><?php echo $stats['completed']; ?></div>
                    <div class="stat-sub">Resolved</div>
                </div>
            </div>
            
            <!-- Recent Requests -->
            <div class="table-container">
                <h2>Recent Maintenance Requests</h2>
                <?php if (!empty($recent_requests)): ?>
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Request #</th>
                                <th>Subject</th>
                                <th>Equipment</th>
                                <th>Type</th>
                                <th>Status</th>
                                <th>Priority</th>
                                <th>Created</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($recent_requests as $request): 
                                $status_class = 'status-' . strtolower(str_replace(' ', '-', $request['status']));
                                $priority_class = 'priority-' . strtolower($request['priority']);
                            ?>
                            <tr>
                                <td>
                                    <strong><?php echo htmlspecialchars($request['request_number']); ?></strong>
                                </td>
                                <td><?php echo htmlspecialchars($request['subject']); ?></td>
                                <td><?php echo htmlspecialchars($request['equipment_name'] ?? 'Unknown Equipment'); ?></td>
                                <td>
                                    <span class="status-badge <?php echo $request['type'] == 'Corrective' ? 'status-new' : 'status-repaired'; ?>">
                                        <?php echo $request['type']; ?>
                                    </span>
                                </td>
                                <td>
                                    <span class="status-badge <?php echo $status_class; ?>">
                                        <?php echo $request['status']; ?>
                                    </span>
                                </td>
                                <td>
                                    <span class="<?php echo $priority_class; ?>">
                                        <?php echo $request['priority']; ?>
                                    </span>
                                </td>
                                <td><?php echo date('M d, Y', strtotime($request['created_at'])); ?></td>
                                <td>
                                    <a href="view_request.php?id=<?php echo $request['id']; ?>" class="btn-view">View</a>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    <div style="text-align: center; margin-top: 20px;">
                        <a href="requests.php" class="btn btn-primary">View All Requests →</a>
                    </div>
                <?php else: ?>
                    <div class="empty-state">
                        <div class="empty-icon">📋</div>
                        <h3>No maintenance requests yet</h3>
                        <p>Create your first maintenance request to get started</p>
                        <a href="requests.php?action=new" class="btn btn-primary">
                            ➕ Create New Request
                        </a>
                    </div>
                <?php endif; ?>
            </div>
            
            <!-- Additional Dashboard Widgets (Optional) -->
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 20px; margin-top: 30px;">
                <!-- Equipment Status Summary -->
                <div style="background: white; padding: 20px; border-radius: 10px; box-shadow: 0 2px 10px rgba(0,0,0,0.08);">
                    <h3>Equipment Status</h3>
                    <div id="equipmentChart" style="height: 200px; margin-top: 20px;">
                        <!-- You can add a chart here using Chart.js or similar -->
                        <p style="color: #666; text-align: center; padding: 40px 0;">
                            Chart showing equipment status distribution
                        </p>
                    </div>
                </div>
                
                <!-- Team Performance -->
                <div style="background: white; padding: 20px; border-radius: 10px; box-shadow: 0 2px 10px rgba(0,0,0,0.08);">
                    <h3>Team Performance</h3>
                    <div id="teamChart" style="height: 200px; margin-top: 20px;">
                        <p style="color: #666; text-align: center; padding: 40px 0;">
                            Chart showing team performance metrics
                        </p>
                    </div>
                </div>
            </div>
        </main>
    </div>
    
    <script>
        // Add interactivity
        document.addEventListener('DOMContentLoaded', function() {
            // Card click events
            const cards = document.querySelectorAll('.card');
            cards.forEach(card => {
                card.addEventListener('click', function() {
                    // Add visual feedback
                    this.style.transform = 'scale(0.98)';
                    setTimeout(() => {
                        this.style.transform = '';
                    }, 150);
                });
            });
            
            // Status badge hover effects
            const statusBadges = document.querySelectorAll('.status-badge');
            statusBadges.forEach(badge => {
                badge.addEventListener('mouseenter', function() {
                    this.style.transform = 'scale(1.05)';
                });
                badge.addEventListener('mouseleave', function() {
                    this.style.transform = '';
                });
            });
            
            // Table row hover with view action
            const tableRows = document.querySelectorAll('.table tbody tr');
            tableRows.forEach(row => {
                row.addEventListener('click', function(e) {
                    if (!e.target.classList.contains('btn-view')) {
                        const requestId = this.querySelector('td:first-child strong').textContent.replace('REQ-', '');
                        window.location.href = `view_request.php?request_number=${requestId}`;
                    }
                });
            });
        });
        
        // Function to refresh dashboard data (optional)
        function refreshDashboard() {
            fetch('dashboard_ajax.php?action=refresh')
                .then(response => response.json())
                .then(data => {
                    // Update statistics
                    document.querySelectorAll('.card h3')[0].textContent = data.equipment;
                    document.querySelectorAll('.card h3')[1].textContent = data.teams;
                    document.querySelectorAll('.card h3')[2].textContent = data.requests;
                    document.querySelectorAll('.card h3')[3].textContent = data.pending;
                    
                    // Update quick stats
                    document.querySelectorAll('.stat-card .value')[0].textContent = data.corrective;
                    document.querySelectorAll('.stat-card .value')[1].textContent = data.preventive;
                    document.querySelectorAll('.stat-card .value')[2].textContent = data.in_progress;
                    document.querySelectorAll('.stat-card .value')[3].textContent = data.completed;
                    
                    // Show notification
                    const notification = document.createElement('div');
                    notification.style.cssText = 'position: fixed; top: 20px; right: 20px; background: #4CAF50; color: white; padding: 15px; border-radius: 5px; z-index: 1000;';
                    notification.textContent = 'Dashboard data refreshed!';
                    document.body.appendChild(notification);
                    
                    setTimeout(() => {
                        notification.remove();
                    }, 3000);
                })
                .catch(error => {
                    console.error('Error refreshing dashboard:', error);
                });
        }
    </script>
</body>
</html>