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

// Handle status update
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['update_status'])) {
    $request_id = intval($_POST['request_id']);
    $new_status = mysqli_real_escape_string($conn, $_POST['status']);
    
    $sql = "UPDATE maintenance_requests SET status = '$new_status' WHERE id = $request_id";
    if (mysqli_query($conn, $sql)) {
        $success = "Request status updated!";
        
        // Add to maintenance history
        $user_id = $_SESSION['user_id'];
        $history_sql = "INSERT INTO maintenance_history 
                       (request_id, action, performed_by, notes) 
                       VALUES ($request_id, 'Status changed to $new_status', $user_id, 'Status update')";
        mysqli_query($conn, $history_sql);
    }
}

// Handle filters
$where_conditions = [];
$filter_params = [];

if (isset($_GET['status']) && $_GET['status'] != '') {
    $where_conditions[] = "mr.status = '" . mysqli_real_escape_string($conn, $_GET['status']) . "'";
}

if (isset($_GET['type']) && $_GET['type'] != '') {
    $where_conditions[] = "mr.type = '" . mysqli_real_escape_string($conn, $_GET['type']) . "'";
}

if (isset($_GET['priority']) && $_GET['priority'] != '') {
    $where_conditions[] = "mr.priority = '" . mysqli_real_escape_string($conn, $_GET['priority']) . "'";
}

if (isset($_GET['equipment']) && $_GET['equipment'] != '') {
    $where_conditions[] = "mr.equipment_id = " . intval($_GET['equipment']);
}

if (isset($_GET['date_range']) && $_GET['date_range'] != '') {
    $date_condition = '';
    switch ($_GET['date_range']) {
        case 'today':
            $date_condition = "DATE(mr.created_at) = CURDATE()";
            break;
        case 'week':
            $date_condition = "mr.created_at >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)";
            break;
        case 'month':
            $date_condition = "mr.created_at >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)";
            break;
    }
    if ($date_condition) {
        $where_conditions[] = $date_condition;
    }
}

// Build WHERE clause
$where_clause = '';
if (!empty($where_conditions)) {
    $where_clause = "WHERE " . implode(' AND ', $where_conditions);
}

// Get all maintenance requests
$requests = [];
$stats = [
    'total' => 0,
    'new' => 0,
    'in_progress' => 0,
    'repaired' => 0,
    'corrective' => 0,
    'preventive' => 0
];

$sql = "
    SELECT mr.*, 
           e.name as equipment_name,
           e.serial_number as equipment_serial,
           e.location as equipment_location,
           ec.name as equipment_category,
           d.name as department_name,
           u.full_name as assigned_name,
           uc.full_name as created_by_name
    FROM maintenance_requests mr
    LEFT JOIN equipment e ON mr.equipment_id = e.id
    LEFT JOIN equipment_categories ec ON e.category_id = ec.id
    LEFT JOIN departments d ON e.department_id = d.id
    LEFT JOIN users u ON mr.assigned_to = u.id
    LEFT JOIN users uc ON mr.created_by = uc.id
    $where_clause
    ORDER BY 
        CASE mr.priority 
            WHEN 'Critical' THEN 1
            WHEN 'High' THEN 2
            WHEN 'Medium' THEN 3
            WHEN 'Low' THEN 4
            ELSE 5
        END,
        mr.created_at DESC
";

$result = mysqli_query($conn, $sql);

if ($result) {
    while ($row = mysqli_fetch_assoc($result)) {
        $requests[] = $row;
        
        // Update stats
        $stats['total']++;
        if ($row['status'] == 'New') $stats['new']++;
        elseif ($row['status'] == 'In Progress') $stats['in_progress']++;
        elseif ($row['status'] == 'Repaired') $stats['repaired']++;
        
        if ($row['type'] == 'Corrective') $stats['corrective']++;
        elseif ($row['type'] == 'Preventive') $stats['preventive']++;
    }
}

// Get equipment for dropdown
$equipment = [];
$result = mysqli_query($conn, "SELECT id, name, serial_number FROM equipment WHERE status = 'active' ORDER BY name");
if ($result) {
    while ($row = mysqli_fetch_assoc($result)) {
        $equipment[] = $row;
    }
}

// Get technicians for assignment
$technicians = [];
$tech_result = mysqli_query($conn, "
    SELECT id, full_name, email, role 
    FROM users 
    WHERE role IN ('technician', 'admin') AND status = 'active'
    ORDER BY full_name
");
if ($tech_result) {
    while ($row = mysqli_fetch_assoc($tech_result)) {
        $technicians[] = $row;
    }
}

// Get request history
$request_history = [];
foreach ($requests as &$request) {
    $history_result = mysqli_query($conn, "
        SELECT * FROM maintenance_history 
        WHERE request_id = {$request['id']}
        ORDER BY created_at DESC
        LIMIT 5
    ");
    $request['history'] = [];
    if ($history_result) {
        while ($history_row = mysqli_fetch_assoc($history_result)) {
            $request['history'][] = $history_row;
        }
    }
}

mysqli_close($conn);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Maintenance Requests - GearGuard</title>
    <link rel="stylesheet" href="../css/style.css">
    <style>
        .requests-container {
            display: flex;
            gap: 20px;
            margin-top: 20px;
        }
        
        .filters-sidebar {
            width: 250px;
            background: white;
            padding: 20px;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.08);
            height: fit-content;
            position: sticky;
            top: 100px;
        }
        
        .filters-sidebar h3 {
            margin: 0 0 20px 0;
            color: #333;
            font-size: 1.2rem;
        }
        
        .filter-group {
            margin-bottom: 20px;
        }
        
        .filter-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 500;
            color: #555;
            font-size: 0.95rem;
        }
        
        .filter-select {
            width: 100%;
            padding: 8px 12px;
            border: 1px solid #ddd;
            border-radius: 6px;
            background: white;
            font-size: 0.95rem;
        }
        
        .requests-list {
            flex: 1;
        }
        
        .request-card {
            background: white;
            border-radius: 8px;
            padding: 20px;
            margin-bottom: 15px;
            border-left: 4px solid #ddd;
            box-shadow: 0 2px 5px rgba(0,0,0,0.05);
            transition: all 0.3s;
        }
        
        .request-card:hover {
            box-shadow: 0 3px 10px rgba(0,0,0,0.1);
            transform: translateY(-2px);
        }
        
        .request-card.critical { border-left-color: #f44336; }
        .request-card.high { border-left-color: #FF9800; }
        .request-card.medium { border-left-color: #2196F3; }
        .request-card.low { border-left-color: #4CAF50; }
        
        .request-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 15px;
        }
        
        .request-title {
            font-size: 1.1rem;
            font-weight: 500;
            color: #333;
            margin: 0 0 5px 0;
        }
        
        .request-meta {
            display: flex;
            gap: 15px;
            color: #666;
            font-size: 0.9rem;
            flex-wrap: wrap;
        }
        
        .request-priority {
            padding: 3px 10px;
            border-radius: 12px;
            font-size: 0.85rem;
            font-weight: 500;
        }
        
        .priority-critical { background: #ffebee; color: #d32f2f; }
        .priority-high { background: #fff3e0; color: #f57c00; }
        .priority-medium { background: #e3f2fd; color: #1976d2; }
        .priority-low { background: #e8f5e9; color: #388e3c; }
        
        .request-actions {
            display: flex;
            gap: 10px;
            margin-top: 15px;
            flex-wrap: wrap;
        }
        
        .no-requests {
            text-align: center;
            padding: 40px;
            background: white;
            border-radius: 10px;
            color: #666;
        }
        
        .request-details {
            background: #f8f9fa;
            padding: 15px;
            border-radius: 6px;
            margin: 15px 0;
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 10px;
        }
        
        .detail-item {
            display: flex;
            flex-direction: column;
        }
        
        .detail-label {
            font-size: 0.85rem;
            color: #666;
            margin-bottom: 3px;
        }
        
        .detail-value {
            font-weight: 500;
            color: #333;
        }
        
        .history-panel {
            display: none;
            background: #f8f9fa;
            border-radius: 6px;
            padding: 15px;
            margin-top: 10px;
        }
        
        .history-item {
            padding: 8px;
            border-bottom: 1px solid #eaeaea;
            font-size: 0.9rem;
        }
        
        .history-item:last-child {
            border-bottom: none;
        }
        
        .history-time {
            color: #666;
            font-size: 0.85rem;
        }
        
        .assign-form {
            display: none;
            background: white;
            padding: 20px;
            border-radius: 8px;
            margin-top: 10px;
            border: 1px solid #eaeaea;
        }
    </style>
</head>
<body>
    <?php include '../includes/header.php'; ?>
    
    <div class="container">
        <?php include '../includes/sidebar.php'; ?>
        
        <main class="main-content">
            <div class="page-header">
                <h1>Maintenance Requests</h1>
                <button class="btn btn-primary" onclick="window.location.href='request_create.php'">
                    📝 Create New Request
                </button>
            </div>
            
            <?php if (isset($success)): ?>
                <div class="alert alert-success">
                    ✅ <?php echo $success; ?>
                </div>
            <?php endif; ?>
            
            <!-- Statistics -->
            <div class="stats-overview">
                <div class="stat-card">
                    <div class="label">Total Requests</div>
                    <div class="value"><?php echo $stats['total']; ?></div>
                </div>
                <div class="stat-card">
                    <div class="label">New</div>
                    <div class="value"><?php echo $stats['new']; ?></div>
                </div>
                <div class="stat-card">
                    <div class="label">In Progress</div>
                    <div class="value"><?php echo $stats['in_progress']; ?></div>
                </div>
                <div class="stat-card">
                    <div class="label">Completed</div>
                    <div class="value"><?php echo $stats['repaired']; ?></div>
                </div>
            </div>
            
            <div class="requests-container">
                <!-- Filters Sidebar -->
                <div class="filters-sidebar">
                    <h3>🔍 Filters</h3>
                    
                    <form method="GET" action="">
                        <div class="filter-group">
                            <label>Status</label>
                            <select class="filter-select" name="status" onchange="this.form.submit()">
                                <option value="">All Status</option>
                                <option value="New" <?php echo (isset($_GET['status']) && $_GET['status'] == 'New') ? 'selected' : ''; ?>>New</option>
                                <option value="In Progress" <?php echo (isset($_GET['status']) && $_GET['status'] == 'In Progress') ? 'selected' : ''; ?>>In Progress</option>
                                <option value="Repaired" <?php echo (isset($_GET['status']) && $_GET['status'] == 'Repaired') ? 'selected' : ''; ?>>Repaired</option>
                                <option value="Scrap" <?php echo (isset($_GET['status']) && $_GET['status'] == 'Scrap') ? 'selected' : ''; ?>>Scrapped</option>
                            </select>
                        </div>
                        
                        <div class="filter-group">
                            <label>Type</label>
                            <select class="filter-select" name="type" onchange="this.form.submit()">
                                <option value="">All Types</option>
                                <option value="Corrective" <?php echo (isset($_GET['type']) && $_GET['type'] == 'Corrective') ? 'selected' : ''; ?>>Corrective</option>
                                <option value="Preventive" <?php echo (isset($_GET['type']) && $_GET['type'] == 'Preventive') ? 'selected' : ''; ?>>Preventive</option>
                            </select>
                        </div>
                        
                        <div class="filter-group">
                            <label>Priority</label>
                            <select class="filter-select" name="priority" onchange="this.form.submit()">
                                <option value="">All Priorities</option>
                                <option value="Critical" <?php echo (isset($_GET['priority']) && $_GET['priority'] == 'Critical') ? 'selected' : ''; ?>>Critical</option>
                                <option value="High" <?php echo (isset($_GET['priority']) && $_GET['priority'] == 'High') ? 'selected' : ''; ?>>High</option>
                                <option value="Medium" <?php echo (isset($_GET['priority']) && $_GET['priority'] == 'Medium') ? 'selected' : ''; ?>>Medium</option>
                                <option value="Low" <?php echo (isset($_GET['priority']) && $_GET['priority'] == 'Low') ? 'selected' : ''; ?>>Low</option>
                            </select>
                        </div>
                        
                        <div class="filter-group">
                            <label>Equipment</label>
                            <select class="filter-select" name="equipment" onchange="this.form.submit()">
                                <option value="">All Equipment</option>
                                <?php foreach ($equipment as $item): ?>
                                    <option value="<?php echo $item['id']; ?>" 
                                        <?php echo (isset($_GET['equipment']) && $_GET['equipment'] == $item['id']) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($item['name'] . ' (' . $item['serial_number'] . ')'); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="filter-group">
                            <label>Date Range</label>
                            <select class="filter-select" name="date_range" onchange="this.form.submit()">
                                <option value="">All Time</option>
                                <option value="today" <?php echo (isset($_GET['date_range']) && $_GET['date_range'] == 'today') ? 'selected' : ''; ?>>Today</option>
                                <option value="week" <?php echo (isset($_GET['date_range']) && $_GET['date_range'] == 'week') ? 'selected' : ''; ?>>This Week</option>
                                <option value="month" <?php echo (isset($_GET['date_range']) && $_GET['date_range'] == 'month') ? 'selected' : ''; ?>>This Month</option>
                            </select>
                        </div>
                        
                        <button class="btn btn-sm" type="button" onclick="window.location.href='requests.php'" style="width: 100%; margin-top: 10px;">
                            🔄 Reset Filters
                        </button>
                    </form>
                </div>
                
                <!-- Requests List -->
                <div class="requests-list">
                    <?php if (!empty($requests)): ?>
                        <?php foreach ($requests as $request): 
                            $priority_class = strtolower($request['priority']);
                            $status_class = 'status-' . strtolower(str_replace(' ', '-', $request['status']));
                        ?>
                        <div class="request-card <?php echo $priority_class; ?>" 
                             data-id="<?php echo $request['id']; ?>">
                            
                            <div class="request-header">
                                <div style="flex: 1;">
                                    <div style="display: flex; align-items: center; gap: 10px; margin-bottom: 8px;">
                                        <h3 class="request-title">
                                            <?php echo htmlspecialchars($request['subject']); ?>
                                        </h3>
                                        <span class="request-priority priority-<?php echo $priority_class; ?>">
                                            <?php echo $request['priority']; ?>
                                        </span>
                                    </div>
                                    <div class="request-meta">
                                        <span>#<?php echo htmlspecialchars($request['request_number'] ?: 'REQ-' . $request['id']); ?></span>
                                        <span>Equipment: <?php echo htmlspecialchars($request['equipment_name']); ?></span>
                                        <span>Created: <?php echo date('M d, Y', strtotime($request['created_at'])); ?></span>
                                        <span>By: <?php echo htmlspecialchars($request['created_by_name'] ?? 'System'); ?></span>
                                        <?php if ($request['assigned_name']): ?>
                                            <span>Assigned to: <?php echo htmlspecialchars($request['assigned_name']); ?></span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <div style="text-align: right;">
                                    <span class="status-badge <?php echo $status_class; ?>">
                                        <?php echo $request['status']; ?>
                                    </span>
                                    <div style="margin-top: 5px; font-size: 0.9rem;">
                                        <span style="color: #666;">Type:</span>
                                        <span class="status-badge <?php echo $request['type'] == 'Corrective' ? 'status-new' : 'status-repaired'; ?>">
                                            <?php echo $request['type']; ?>
                                        </span>
                                    </div>
                                </div>
                            </div>
                            
                            <?php if ($request['description']): ?>
                                <div style="color: #666; margin: 10px 0; padding: 10px; background: #f8f9fa; border-radius: 4px;">
                                    <?php echo nl2br(htmlspecialchars($request['description'])); ?>
                                </div>
                            <?php endif; ?>
                            
                            <div class="request-details">
                                <div class="detail-item">
                                    <span class="detail-label">Equipment:</span>
                                    <span class="detail-value">
                                        <?php echo htmlspecialchars($request['equipment_name']); ?>
                                        (SN: <?php echo htmlspecialchars($request['equipment_serial']); ?>)
                                    </span>
                                </div>
                                
                                <div class="detail-item">
                                    <span class="detail-label">Category:</span>
                                    <span class="detail-value"><?php echo htmlspecialchars($request['equipment_category']); ?></span>
                                </div>
                                
                                <div class="detail-item">
                                    <span class="detail-label">Department:</span>
                                    <span class="detail-value"><?php echo htmlspecialchars($request['department_name']); ?></span>
                                </div>
                                
                                <div class="detail-item">
                                    <span class="detail-label">Location:</span>
                                    <span class="detail-value"><?php echo htmlspecialchars($request['equipment_location'] ?: 'N/A'); ?></span>
                                </div>
                                
                                <?php if ($request['scheduled_date']): ?>
                                    <div class="detail-item">
                                        <span class="detail-label">Scheduled Date:</span>
                                        <span class="detail-value"><?php echo date('M d, Y', strtotime($request['scheduled_date'])); ?></span>
                                    </div>
                                <?php endif; ?>
                                
                                <div class="detail-item">
                                    <span class="detail-label">Last Updated:</span>
                                    <span class="detail-value"><?php echo date('M d, Y H:i', strtotime($request['updated_at'])); ?></span>
                                </div>
                            </div>
                            
                            <!-- History Panel -->
                            <div class="history-panel" id="history-<?php echo $request['id']; ?>">
                                <h4 style="margin: 0 0 10px 0;">History</h4>
                                <?php if (!empty($request['history'])): ?>
                                    <?php foreach ($request['history'] as $history): ?>
                                        <div class="history-item">
                                            <div><?php echo htmlspecialchars($history['action']); ?></div>
                                            <div class="history-time">
                                                <?php echo date('M d, Y H:i', strtotime($history['created_at'])); ?>
                                                <?php if ($history['notes']): ?>
                                                    - <?php echo htmlspecialchars($history['notes']); ?>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <div class="history-item">No history recorded yet.</div>
                                <?php endif; ?>
                            </div>
                            
                            <!-- Assign Form -->
                            <div class="assign-form" id="assign-form-<?php echo $request['id']; ?>">
                                <h4 style="margin: 0 0 15px 0;">Assign Technician</h4>
                                <form method="POST" action="assign_technician.php">
                                    <input type="hidden" name="request_id" value="<?php echo $request['id']; ?>">
                                    <div class="form-group">
                                        <label>Select Technician:</label>
                                        <select name="technician_id" class="filter-select" required>
                                            <option value="">-- Select Technician --</option>
                                            <?php foreach ($technicians as $tech): ?>
                                                <option value="<?php echo $tech['id']; ?>">
                                                    <?php echo htmlspecialchars($tech['full_name'] . ' (' . $tech['role'] . ')'); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="form-group">
                                        <label>Notes (optional):</label>
                                        <textarea name="notes" rows="2" class="filter-select"></textarea>
                                    </div>
                                    <div style="display: flex; gap: 10px; margin-top: 15px;">
                                        <button type="submit" class="btn btn-sm btn-success">Assign</button>
                                        <button type="button" class="btn btn-sm" onclick="toggleAssignForm(<?php echo $request['id']; ?>)">Cancel</button>
                                    </div>
                                </form>
                            </div>
                            
                            <div class="request-actions">
                                <button class="btn btn-sm btn-primary" onclick="viewRequest(<?php echo $request['id']; ?>)">
                                    👁️ View Details
                                </button>
                                
                                <?php if ($request['status'] == 'New'): ?>
                                    <button class="btn btn-sm btn-success" onclick="toggleAssignForm(<?php echo $request['id']; ?>)">
                                        👤 Assign Technician
                                    </button>
                                <?php endif; ?>
                                
                                <?php if ($request['status'] == 'In Progress'): ?>
                                    <button class="btn btn-sm btn-warning" onclick="updateProgress(<?php echo $request['id']; ?>)">
                                        📝 Update Progress
                                    </button>
                                <?php endif; ?>
                                
                                <div class="dropdown" style="display: inline-block;">
                                    <button class="btn btn-sm dropdown-toggle" type="button" 
                                            onclick="toggleRequestDropdown(<?php echo $request['id']; ?>)">
                                        ⚙️ More Actions
                                    </button>
                                    <div class="dropdown-menu" id="request-dropdown-<?php echo $request['id']; ?>">
                                        <?php if ($request['status'] == 'New'): ?>
                                            <a href="#" onclick="changeRequestStatus(<?php echo $request['id']; ?>, 'In Progress')">
                                                ▶️ Start Work
                                            </a>
                                        <?php endif; ?>
                                        
                                        <?php if ($request['status'] == 'In Progress'): ?>
                                            <a href="#" onclick="changeRequestStatus(<?php echo $request['id']; ?>, 'Repaired')">
                                                ✅ Mark as Repaired
                                            </a>
                                        <?php endif; ?>
                                        
                                        <a href="#" onclick="changeRequestStatus(<?php echo $request['id']; ?>, 'Scrap')">
                                            🗑️ Mark as Scrapped
                                        </a>
                                        <hr>
                                        <a href="#" onclick="toggleHistory(<?php echo $request['id']; ?>)" style="color: #2196F3;">
                                            📋 View History
                                        </a>
                                        <a href="#" onclick="addNote(<?php echo $request['id']; ?>)" style="color: #FF9800;">
                                            💬 Add Note
                                        </a>
                                        <hr>
                                        <a href="request_edit.php?id=<?php echo $request['id']; ?>" style="color: #4CAF50;">
                                            ✏️ Edit Request
                                        </a>
                                    </div>
                                </div>
                                
                                <button class="btn btn-sm" onclick="printRequest(<?php echo $request['id']; ?>)" 
                                        style="background: #e3f2fd; color: #1976d2;">
                                    🖨️ Print Report
                                </button>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="no-requests">
                            <div style="font-size: 3rem; margin-bottom: 20px;">📋</div>
                            <h3>No maintenance requests found</h3>
                            <p><?php echo !empty($where_conditions) ? 'Try changing your filters' : 'Create your first maintenance request to get started'; ?></p>
                            <button onclick="window.location.href='request_create.php'" class="btn btn-primary mt-2">
                                📝 Create New Request
                            </button>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </main>
    </div>
    
    <!-- Status Change Form -->
    <form method="POST" action="" id="statusForm" style="display: none;">
        <input type="hidden" name="request_id" id="requestId">
        <input type="hidden" name="status" id="statusValue">
        <input type="hidden" name="update_status" value="1">
    </form>
    
    <script>
        function openNewRequest() {
            window.location.href = 'request_create.php';
        }
        
        function viewRequest(id) {
            window.location.href = 'request_view.php?id=' + id;
        }
        
        function toggleAssignForm(id) {
            const form = document.getElementById('assign-form-' + id);
            form.style.display = form.style.display === 'block' ? 'none' : 'block';
        }
        
        function updateProgress(id) {
            window.location.href = 'request_update.php?id=' + id;
        }
        
        function addNote(id) {
            const note = prompt('Enter note for request #' + id + ':');
            if (note) {
                // Submit note via AJAX or form
                const form = document.createElement('form');
                form.method = 'POST';
                form.action = 'add_note.php';
                
                const requestId = document.createElement('input');
                requestId.type = 'hidden';
                requestId.name = 'request_id';
                requestId.value = id;
                
                const noteInput = document.createElement('input');
                noteInput.type = 'hidden';
                noteInput.name = 'note';
                noteInput.value = note;
                
                form.appendChild(requestId);
                form.appendChild(noteInput);
                document.body.appendChild(form);
                form.submit();
            }
        }
        
        function toggleHistory(id) {
            const history = document.getElementById('history-' + id);
            history.style.display = history.style.display === 'block' ? 'none' : 'block';
        }
        
        function printRequest(id) {
            window.open('request_print.php?id=' + id, '_blank');
        }
        
        function toggleRequestDropdown(id) {
            const dropdown = document.getElementById('request-dropdown-' + id);
            dropdown.style.display = dropdown.style.display === 'block' ? 'none' : 'block';
        }
        
        function changeRequestStatus(requestId, status) {
            if (confirm('Change request status to "' + status + '"?')) {
                document.getElementById('requestId').value = requestId;
                document.getElementById('statusValue').value = status;
                document.getElementById('statusForm').submit();
            }
        }
        
        // Close dropdowns when clicking outside
        document.addEventListener('click', function(e) {
            if (!e.target.matches('.dropdown-toggle')) {
                document.querySelectorAll('.dropdown-menu').forEach(menu => {
                    menu.style.display = 'none';
                });
            }
        });
    </script>
</body>
</html>