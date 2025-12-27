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

// Handle actions
$action = $_GET['action'] ?? '';
$equipment_id = $_GET['equipment_id'] ?? 0;
$view_id = $_GET['view_id'] ?? 0;
$message = '';
$message_type = '';

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (isset($_POST['add_equipment'])) {
        $name = mysqli_real_escape_string($conn, $_POST['name']);
        $serial_number = mysqli_real_escape_string($conn, $_POST['serial_number']);
        $category_id = intval($_POST['category_id']);
        $department_id = intval($_POST['department_id']);
        $location = mysqli_real_escape_string($conn, $_POST['location']);
        $purchase_date = !empty($_POST['purchase_date']) ? $_POST['purchase_date'] : NULL;
        $warranty_date = !empty($_POST['warranty_date']) ? $_POST['warranty_date'] : NULL;
        $notes = mysqli_real_escape_string($conn, $_POST['notes']);
        $status = 'active';
        $created_by = $_SESSION['user_id'];
        
        // Get maintenance team based on department or category
        $maintenance_team_id = isset($_POST['maintenance_team_id']) ? intval($_POST['maintenance_team_id']) : NULL;
        
        $sql = "INSERT INTO equipment (name, serial_number, category_id, department_id, location, status, notes, purchase_date, warranty_date, maintenance_team_id, created_by) 
                VALUES ('$name', '$serial_number', $category_id, $department_id, '$location', '$status', '$notes', " . 
                ($purchase_date ? "'$purchase_date'" : "NULL") . ", " . 
                ($warranty_date ? "'$warranty_date'" : "NULL") . ", " . 
                ($maintenance_team_id ? "$maintenance_team_id" : "NULL") . ", $created_by)";
        
        if (mysqli_query($conn, $sql)) {
            $message = "Equipment added successfully!";
            $message_type = 'success';
        } else {
            $message = "Error: " . mysqli_error($conn);
            $message_type = 'error';
        }
    }
    
    if (isset($_POST['update_status'])) {
        $equipment_id = intval($_POST['equipment_id']);
        $new_status = mysqli_real_escape_string($conn, $_POST['status']);
        
        $sql = "UPDATE equipment SET status = '$new_status' WHERE id = $equipment_id";
        if (mysqli_query($conn, $sql)) {
            $message = "Equipment status updated to " . ucfirst(str_replace('_', ' ', $new_status)) . "!";
            $message_type = 'success';
        }
    }
    
    if (isset($_POST['edit_equipment'])) {
        $equipment_id = intval($_POST['edit_id']);
        $name = mysqli_real_escape_string($conn, $_POST['edit_name']);
        $serial_number = mysqli_real_escape_string($conn, $_POST['edit_serial_number']);
        $category_id = intval($_POST['edit_category_id']);
        $department_id = intval($_POST['edit_department_id']);
        $location = mysqli_real_escape_string($conn, $_POST['edit_location']);
        $maintenance_team_id = isset($_POST['edit_maintenance_team_id']) ? intval($_POST['edit_maintenance_team_id']) : NULL;
        $notes = mysqli_real_escape_string($conn, $_POST['edit_notes']);
        
        $sql = "UPDATE equipment SET 
                name = '$name',
                serial_number = '$serial_number',
                category_id = $category_id,
                department_id = $department_id,
                location = '$location',
                maintenance_team_id = " . ($maintenance_team_id ? "$maintenance_team_id" : "NULL") . ",
                notes = '$notes',
                updated_at = NOW()
                WHERE id = $equipment_id";
        
        if (mysqli_query($conn, $sql)) {
            $message = "Equipment updated successfully!";
            $message_type = 'success';
        } else {
            $message = "Error updating equipment: " . mysqli_error($conn);
            $message_type = 'error';
        }
    }
    
    if (isset($_POST['schedule_maintenance'])) {
        $equipment_id = intval($_POST['schedule_equipment_id']);
        $subject = mysqli_real_escape_string($conn, $_POST['subject']);
        $description = mysqli_real_escape_string($conn, $_POST['description']);
        $scheduled_date = $_POST['scheduled_date'];
        $type = mysqli_real_escape_string($conn, $_POST['type']);
        $priority = mysqli_real_escape_string($conn, $_POST['priority']);
        $assigned_to = intval($_POST['assigned_to']);
        $created_by = $_SESSION['user_id'];
        
        // Generate request number
        $request_number = 'REQ-' . date('Ymd') . '-' . rand(1000, 9999);
        
        $sql = "INSERT INTO maintenance_requests 
                (request_number, subject, description, equipment_id, type, priority, scheduled_date, assigned_to, created_by) 
                VALUES ('$request_number', '$subject', '$description', $equipment_id, '$type', '$priority', 
                '$scheduled_date', $assigned_to, $created_by)";
        
        if (mysqli_query($conn, $sql)) {
            $message = "Maintenance scheduled successfully!";
            $message_type = 'success';
        }
    }
    
    if (isset($_POST['delete_equipment'])) {
        $equipment_id = intval($_POST['delete_id']);
        
        // Check if equipment has maintenance requests
        $check_sql = "SELECT COUNT(*) as count FROM maintenance_requests WHERE equipment_id = $equipment_id";
        $check_result = mysqli_query($conn, $check_sql);
        $check_data = mysqli_fetch_assoc($check_result);
        
        if ($check_data['count'] == 0) {
            $sql = "DELETE FROM equipment WHERE id = $equipment_id";
            if (mysqli_query($conn, $sql)) {
                $message = "Equipment deleted successfully!";
                $message_type = 'success';
            }
        } else {
            $message = "Cannot delete equipment with maintenance history! Set status to 'scrapped' instead.";
            $message_type = 'error';
        }
    }
}

// Get equipment for editing/viewing
$edit_equipment = null;
if ($action == 'edit' && $equipment_id > 0) {
    $result = mysqli_query($conn, "SELECT * FROM equipment WHERE id = $equipment_id");
    $edit_equipment = mysqli_fetch_assoc($result);
}

// Get equipment for viewing details
$view_equipment = null;
if ($action == 'view' && $view_id > 0) {
    $result = mysqli_query($conn, "
        SELECT e.*, 
               ec.name as category_name,
               d.name as department_name,
               mt.name as maintenance_team_name,
               u.full_name as created_by_name,
               (SELECT COUNT(*) FROM maintenance_requests WHERE equipment_id = e.id) as maintenance_count,
               (SELECT COUNT(*) FROM maintenance_requests WHERE equipment_id = e.id AND status = 'Repaired') as completed_count
        FROM equipment e
        LEFT JOIN equipment_categories ec ON e.category_id = ec.id
        LEFT JOIN departments d ON e.department_id = d.id
        LEFT JOIN maintenance_teams mt ON e.maintenance_team_id = mt.id
        LEFT JOIN users u ON e.created_by = u.id
        WHERE e.id = $view_id
    ");
    $view_equipment = mysqli_fetch_assoc($result);
}

// Get maintenance history for equipment
$maintenance_history = [];
if ($action == 'view_history' && $equipment_id > 0) {
    $result = mysqli_query($conn, "
        SELECT mr.*, u.full_name as assigned_name
        FROM maintenance_requests mr
        LEFT JOIN users u ON mr.assigned_to = u.id
        WHERE mr.equipment_id = $equipment_id
        ORDER BY mr.created_at DESC
        LIMIT 10
    ");
    if ($result) {
        while ($row = mysqli_fetch_assoc($result)) {
            $maintenance_history[] = $row;
        }
    }
}

// Get all equipment with details
$equipment_list = [];
$categories = [];
$departments = [];
$maintenance_teams = [];
$users = [];

// Get equipment with all related data
$result = mysqli_query($conn, "
    SELECT e.*, 
           ec.name as category_name,
           d.name as department_name,
           mt.name as maintenance_team_name,
           u.username as created_by_username
    FROM equipment e
    LEFT JOIN equipment_categories ec ON e.category_id = ec.id
    LEFT JOIN departments d ON e.department_id = d.id
    LEFT JOIN maintenance_teams mt ON e.maintenance_team_id = mt.id
    LEFT JOIN users u ON e.created_by = u.id
    ORDER BY e.name
");

if ($result) {
    while ($row = mysqli_fetch_assoc($result)) {
        $equipment_list[] = $row;
    }
}

// Get categories
$result = mysqli_query($conn, "SELECT * FROM equipment_categories WHERE status = 'active' ORDER BY name");
if ($result) {
    while ($row = mysqli_fetch_assoc($result)) {
        $categories[] = $row;
    }
}

// Get departments
$result = mysqli_query($conn, "SELECT * FROM departments WHERE status = 'active' ORDER BY name");
if ($result) {
    while ($row = mysqli_fetch_assoc($result)) {
        $departments[] = $row;
    }
}

// Get maintenance teams
$result = mysqli_query($conn, "SELECT * FROM maintenance_teams WHERE status = 'active' ORDER BY name");
if ($result) {
    while ($row = mysqli_fetch_assoc($result)) {
        $maintenance_teams[] = $row;
    }
}

// Get users (for assignment)
$result = mysqli_query($conn, "SELECT id, full_name, email, role FROM users WHERE status = 'active' ORDER BY full_name");
if ($result) {
    while ($row = mysqli_fetch_assoc($result)) {
        $users[] = $row;
    }
}

// Get status options dynamically
$status_options = ['active', 'inactive', 'scrapped', 'under_maintenance'];

// Get stats
$total_equipment = count($equipment_list);
$active_equipment = 0;
$inactive_equipment = 0;
$scrapped_equipment = 0;

foreach ($equipment_list as $item) {
    switch ($item['status']) {
        case 'active':
            $active_equipment++;
            break;
        case 'inactive':
            $inactive_equipment++;
            break;
        case 'scrapped':
            $scrapped_equipment++;
            break;
    }
}

mysqli_close($conn);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Equipment Management - GearGuard</title>
    <link rel="stylesheet" href="../css/style.css">
    <style>
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.5);
            z-index: 1000;
            justify-content: center;
            align-items: center;
        }
        
        .modal-content {
            background: white;
            padding: 30px;
            border-radius: 10px;
            width: 90%;
            max-width: 700px;
            max-height: 90vh;
            overflow-y: auto;
        }
        
        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }
        
        .modal-header h2 {
            margin: 0;
        }
        
        .close-btn {
            background: none;
            border: none;
            font-size: 1.5rem;
            cursor: pointer;
            color: #666;
        }
        
        .status-badge {
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 0.85rem;
            font-weight: 500;
            display: inline-block;
        }
        
        .status-active { background: #e8f5e9; color: #388e3c; }
        .status-inactive { background: #fff3e0; color: #f57c00; }
        .status-scrapped { background: #ffebee; color: #d32f2f; }
        .status-under_maintenance { background: #e3f2fd; color: #1976d2; }
        
        .filter-bar {
            display: flex;
            gap: 10px;
            margin-bottom: 20px;
            flex-wrap: wrap;
        }
        
        .filter-select {
            padding: 8px 12px;
            border: 1px solid #ddd;
            border-radius: 4px;
            background: white;
        }
        
        .btn-group {
            display: flex;
            gap: 5px;
        }
        
        .dropdown {
            position: relative;
        }
        
        .dropdown-menu {
            display: none;
            position: absolute;
            background: white;
            border: 1px solid #ddd;
            border-radius: 4px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            z-index: 1000;
            min-width: 150px;
        }
        
        .dropdown-menu a {
            display: block;
            padding: 8px 12px;
            color: #333;
            text-decoration: none;
        }
        
        .dropdown-menu a:hover {
            background: #f5f5f5;
        }
        
        .form-row {
            display: flex;
            gap: 15px;
        }
        
        .form-row .form-group {
            flex: 1;
        }
        
        .alert {
            padding: 15px;
            border-radius: 6px;
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
        
        .equipment-details {
            background: #f8f9fa;
            padding: 20px;
            border-radius: 8px;
            margin: 20px 0;
        }
        
        .detail-row {
            display: flex;
            margin-bottom: 10px;
        }
        
        .detail-label {
            width: 150px;
            font-weight: 500;
            color: #666;
        }
        
        .detail-value {
            flex: 1;
            color: #333;
        }
        
        .history-item {
            padding: 10px;
            border-bottom: 1px solid #eaeaea;
        }
        
        .history-item:last-child {
            border-bottom: none;
        }
        
        .history-date {
            color: #666;
            font-size: 0.9rem;
        }
        
        .action-buttons {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }
    </style>
</head>
<body>
    <?php include '../includes/header.php'; ?>
    
    <div class="container">
        <?php include '../includes/sidebartec.php'; ?>
        
        <main class="main-content">
            <div class="page-header">
                <h1>Equipment Management</h1>
                <button onclick="showAddModal()" class="btn btn-primary">
                    ➕ Add New Equipment
                </button>
            </div>
            
            <?php if ($message): ?>
                <div class="alert alert-<?php echo $message_type; ?>">
                    <?php echo $message_type == 'success' ? '✅' : '❌'; ?> <?php echo $message; ?>
                </div>
            <?php endif; ?>
            
            <!-- Stats Overview -->
            <div class="stats-overview">
                <div class="stat-card">
                    <div class="label">Total Equipment</div>
                    <div class="value"><?php echo $total_equipment; ?></div>
                </div>
                <div class="stat-card">
                    <div class="label">Active</div>
                    <div class="value"><?php echo $active_equipment; ?></div>
                </div>
                <div class="stat-card">
                    <div class="label">Inactive</div>
                    <div class="value"><?php echo $inactive_equipment; ?></div>
                </div>
                <div class="stat-card">
                    <div class="label">Scrapped</div>
                    <div class="value"><?php echo $scrapped_equipment; ?></div>
                </div>
            </div>
            
            <!-- Filter Bar -->
            <div class="filter-bar">
                <select class="filter-select" id="statusFilter" onchange="filterEquipment()">
                    <option value="">All Status</option>
                    <?php foreach ($status_options as $status): ?>
                        <option value="<?php echo $status; ?>"><?php echo ucfirst(str_replace('_', ' ', $status)); ?></option>
                    <?php endforeach; ?>
                </select>
                
                <select class="filter-select" id="departmentFilter" onchange="filterEquipment()">
                    <option value="">All Departments</option>
                    <?php foreach ($departments as $dept): ?>
                        <option value="<?php echo $dept['id']; ?>"><?php echo htmlspecialchars($dept['name']); ?></option>
                    <?php endforeach; ?>
                </select>
                
                <select class="filter-select" id="categoryFilter" onchange="filterEquipment()">
                    <option value="">All Categories</option>
                    <?php foreach ($categories as $cat): ?>
                        <option value="<?php echo $cat['id']; ?>"><?php echo htmlspecialchars($cat['name']); ?></option>
                    <?php endforeach; ?>
                </select>
                
                <input type="text" class="filter-select" id="searchInput" placeholder="Search equipment..." 
                       onkeyup="filterEquipment()" style="flex: 1;">
            </div>
            
            <!-- Equipment Table -->
            <div class="table-container">
                <h2>Equipment List</h2>
                <?php if (!empty($equipment_list)): ?>
                    <table class="table" id="equipmentTable">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Name</th>
                                <th>Serial No.</th>
                                <th>Category</th>
                                <th>Department</th>
                                <th>Location</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody id="equipmentTableBody">
                            <?php foreach ($equipment_list as $item): 
                                $status_class = 'status-' . $item['status'];
                            ?>
                            <tr data-status="<?php echo $item['status']; ?>" 
                                data-department="<?php echo $item['department_id']; ?>"
                                data-category="<?php echo $item['category_id']; ?>"
                                data-name="<?php echo htmlspecialchars(strtolower($item['name'])); ?>"
                                data-serial="<?php echo htmlspecialchars(strtolower($item['serial_number'])); ?>">
                                <td>#<?php echo $item['id']; ?></td>
                                <td>
                                    <strong><?php echo htmlspecialchars($item['name']); ?></strong>
                                    <?php if ($item['notes']): ?>
                                        <br><small style="color: #666;"><?php echo substr(htmlspecialchars($item['notes']), 0, 50) . '...'; ?></small>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo htmlspecialchars($item['serial_number'] ?: 'N/A'); ?></td>
                                <td><?php echo htmlspecialchars($item['category_name'] ?: 'N/A'); ?></td>
                                <td><?php echo htmlspecialchars($item['department_name'] ?: 'N/A'); ?></td>
                                <td><?php echo htmlspecialchars($item['location'] ?: 'N/A'); ?></td>
                                <td>
                                    <span class="status-badge <?php echo $status_class; ?>">
                                        <?php echo ucfirst(str_replace('_', ' ', $item['status'])); ?>
                                    </span>
                                </td>
                                <td>
                                    <div class="action-buttons">
                                        <button class="btn btn-sm btn-info" onclick="viewEquipment(<?php echo $item['id']; ?>)">
                                            👁️ View
                                        </button>
                                        <button class="btn btn-sm btn-warning" onclick="editEquipment(<?php echo $item['id']; ?>)">
                                            ✏️ Edit
                                        </button>
                                        <button class="btn btn-sm btn-success" onclick="scheduleMaintenance(<?php echo $item['id']; ?>, '<?php echo htmlspecialchars($item['name']); ?>')">
                                            📅 Schedule
                                        </button>
                                        <div class="dropdown">
                                            <button class="btn btn-sm btn-secondary dropdown-toggle" type="button">
                                                ⚙️ More
                                            </button>
                                            <div class="dropdown-menu">
                                                <?php foreach ($status_options as $status_option): 
                                                    if ($status_option != $item['status']): ?>
                                                        <a href="#" onclick="changeStatus(<?php echo $item['id']; ?>, '<?php echo $status_option; ?>', '<?php echo htmlspecialchars($item['name']); ?>')">
                                                            Set to <?php echo ucfirst(str_replace('_', ' ', $status_option)); ?>
                                                        </a>
                                                    <?php endif; 
                                                endforeach; ?>
                                                <hr>
                                                <a href="#" onclick="viewMaintenanceHistory(<?php echo $item['id']; ?>)">
                                                    🔧 Maintenance History
                                                </a>
                                                <a href="#" onclick="deleteEquipment(<?php echo $item['id']; ?>, '<?php echo htmlspecialchars($item['name']); ?>')" style="color: #f44336;">
                                                    🗑️ Delete
                                                </a>
                                            </div>
                                        </div>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php else: ?>
                    <div style="text-align: center; padding: 40px; color: #666;">
                        <div style="font-size: 3rem; margin-bottom: 20px;">⚙️</div>
                        <h3>No equipment found</h3>
                        <p>Add your first equipment to get started</p>
                        <button onclick="showAddModal()" class="btn btn-primary mt-2">
                            ➕ Add Equipment
                        </button>
                    </div>
                <?php endif; ?>
            </div>
        </main>
    </div>
    
    <!-- Add Equipment Modal -->
    <div id="addModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2>Add New Equipment</h2>
                <button class="close-btn" onclick="closeModal('addModal')">&times;</button>
            </div>
            
            <form method="POST" action="">
                <div class="form-group">
                    <label>Equipment Name *</label>
                    <input type="text" name="name" class="form-control" required 
                           placeholder="e.g., CNC Machine 01">
                </div>
                
                <div class="form-group">
                    <label>Serial Number</label>
                    <input type="text" name="serial_number" class="form-control" 
                           placeholder="e.g., CNC-2023-001">
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label>Category *</label>
                        <select name="category_id" class="form-control" required>
                            <option value="">Select Category</option>
                            <?php foreach ($categories as $category): ?>
                                <option value="<?php echo $category['id']; ?>">
                                    <?php echo htmlspecialchars($category['name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label>Department *</label>
                        <select name="department_id" class="form-control" required>
                            <option value="">Select Department</option>
                            <?php foreach ($departments as $dept): ?>
                                <option value="<?php echo $dept['id']; ?>">
                                    <?php echo htmlspecialchars($dept['name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                
                <div class="form-group">
                    <label>Maintenance Team</label>
                    <select name="maintenance_team_id" class="form-control">
                        <option value="">Select Team (Optional)</option>
                        <?php foreach ($maintenance_teams as $team): ?>
                            <option value="<?php echo $team['id']; ?>">
                                <?php echo htmlspecialchars($team['name']); ?>
                                <?php if ($team['specialization']): ?>
                                    (<?php echo htmlspecialchars($team['specialization']); ?>)
                                <?php endif; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="form-group">
                    <label>Location</label>
                    <input type="text" name="location" class="form-control" 
                           placeholder="e.g., Production Floor A">
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label>Purchase Date</label>
                        <input type="date" name="purchase_date" class="form-control">
                    </div>
                    
                    <div class="form-group">
                        <label>Warranty Until</label>
                        <input type="date" name="warranty_date" class="form-control">
                    </div>
                </div>
                
                <div class="form-group">
                    <label>Notes</label>
                    <textarea name="notes" class="form-control" rows="3" 
                              placeholder="Additional information about the equipment..."></textarea>
                </div>
                
                <div style="display: flex; gap: 10px; margin-top: 30px;">
                    <button type="button" class="btn" onclick="closeModal('addModal')">
                        Cancel
                    </button>
                    <button type="submit" name="add_equipment" class="btn btn-primary">
                        💾 Save Equipment
                    </button>
                </div>
            </form>
        </div>
    </div>
    
    <!-- Edit Equipment Modal -->
    <div id="editModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2>Edit Equipment</h2>
                <button class="close-btn" onclick="closeModal('editModal')">&times;</button>
            </div>
            
            <?php if ($edit_equipment): ?>
            <form method="POST" action="" id="editForm">
                <input type="hidden" name="edit_id" value="<?php echo $edit_equipment['id']; ?>">
                
                <div class="form-group">
                    <label>Equipment Name *</label>
                    <input type="text" name="edit_name" class="form-control" 
                           value="<?php echo htmlspecialchars($edit_equipment['name']); ?>" required>
                </div>
                
                <div class="form-group">
                    <label>Serial Number</label>
                    <input type="text" name="edit_serial_number" class="form-control"
                           value="<?php echo htmlspecialchars($edit_equipment['serial_number']); ?>">
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label>Category *</label>
                        <select name="edit_category_id" class="form-control" required>
                            <option value="">Select Category</option>
                            <?php foreach ($categories as $category): ?>
                                <option value="<?php echo $category['id']; ?>" 
                                    <?php echo $category['id'] == $edit_equipment['category_id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($category['name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label>Department *</label>
                        <select name="edit_department_id" class="form-control" required>
                            <option value="">Select Department</option>
                            <?php foreach ($departments as $dept): ?>
                                <option value="<?php echo $dept['id']; ?>" 
                                    <?php echo $dept['id'] == $edit_equipment['department_id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($dept['name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                
                <div class="form-group">
                    <label>Maintenance Team</label>
                    <select name="edit_maintenance_team_id" class="form-control">
                        <option value="">Select Team (Optional)</option>
                        <?php foreach ($maintenance_teams as $team): ?>
                            <option value="<?php echo $team['id']; ?>" 
                                <?php echo $team['id'] == $edit_equipment['maintenance_team_id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($team['name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="form-group">
                    <label>Location</label>
                    <input type="text" name="edit_location" class="form-control"
                           value="<?php echo htmlspecialchars($edit_equipment['location']); ?>">
                </div>
                
                <div class="form-group">
                    <label>Notes</label>
                    <textarea name="edit_notes" class="form-control" rows="3"><?php echo htmlspecialchars($edit_equipment['notes']); ?></textarea>
                </div>
                
                <div style="display: flex; gap: 10px; margin-top: 30px;">
                    <button type="button" class="btn" onclick="closeModal('editModal')">
                        Cancel
                    </button>
                    <button type="submit" name="edit_equipment" class="btn btn-primary">
                        💾 Update Equipment
                    </button>
                </div>
            </form>
            <?php endif; ?>
        </div>
    </div>
    
    <!-- View Equipment Modal -->
    <div id="viewModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2>Equipment Details</h2>
                <button class="close-btn" onclick="closeModal('viewModal')">&times;</button>
            </div>
            
            <?php if ($view_equipment): 
                $status_class = 'status-' . $view_equipment['status'];
            ?>
            <div class="equipment-details">
                <div class="detail-row">
                    <div class="detail-label">Equipment Name:</div>
                    <div class="detail-value"><strong><?php echo htmlspecialchars($view_equipment['name']); ?></strong></div>
                </div>
                <div class="detail-row">
                    <div class="detail-label">Serial Number:</div>
                    <div class="detail-value"><?php echo htmlspecialchars($view_equipment['serial_number'] ?: 'N/A'); ?></div>
                </div>
                <div class="detail-row">
                    <div class="detail-label">Category:</div>
                    <div class="detail-value"><?php echo htmlspecialchars($view_equipment['category_name'] ?: 'N/A'); ?></div>
                </div>
                <div class="detail-row">
                    <div class="detail-label">Department:</div>
                    <div class="detail-value"><?php echo htmlspecialchars($view_equipment['department_name'] ?: 'N/A'); ?></div>
                </div>
                <div class="detail-row">
                    <div class="detail-label">Location:</div>
                    <div class="detail-value"><?php echo htmlspecialchars($view_equipment['location'] ?: 'N/A'); ?></div>
                </div>
                <div class="detail-row">
                    <div class="detail-label">Maintenance Team:</div>
                    <div class="detail-value"><?php echo htmlspecialchars($view_equipment['maintenance_team_name'] ?: 'Not Assigned'); ?></div>
                </div>
                <div class="detail-row">
                    <div class="detail-label">Status:</div>
                    <div class="detail-value">
                        <span class="status-badge <?php echo $status_class; ?>">
                            <?php echo ucfirst(str_replace('_', ' ', $view_equipment['status'])); ?>
                        </span>
                    </div>
                </div>
                <?php if ($view_equipment['purchase_date']): ?>
                <div class="detail-row">
                    <div class="detail-label">Purchase Date:</div>
                    <div class="detail-value"><?php echo date('M d, Y', strtotime($view_equipment['purchase_date'])); ?></div>
                </div>
                <?php endif; ?>
                <?php if ($view_equipment['warranty_date']): ?>
                <div class="detail-row">
                    <div class="detail-label">Warranty Until:</div>
                    <div class="detail-value"><?php echo date('M d, Y', strtotime($view_equipment['warranty_date'])); ?></div>
                </div>
                <?php endif; ?>
                <div class="detail-row">
                    <div class="detail-label">Created By:</div>
                    <div class="detail-value"><?php echo htmlspecialchars($view_equipment['created_by_name'] ?: 'System'); ?></div>
                </div>
                <div class="detail-row">
                    <div class="detail-label">Created At:</div>
                    <div class="detail-value"><?php echo date('M d, Y H:i', strtotime($view_equipment['created_at'])); ?></div>
                </div>
                <div class="detail-row">
                    <div class="detail-label">Maintenance Stats:</div>
                    <div class="detail-value">
                        Total: <?php echo $view_equipment['maintenance_count']; ?> | 
                        Completed: <?php echo $view_equipment['completed_count']; ?> |
                        Pending: <?php echo $view_equipment['maintenance_count'] - $view_equipment['completed_count']; ?>
                    </div>
                </div>
                <?php if ($view_equipment['notes']): ?>
                <div class="detail-row">
                    <div class="detail-label">Notes:</div>
                    <div class="detail-value"><?php echo nl2br(htmlspecialchars($view_equipment['notes'])); ?></div>
                </div>
                <?php endif; ?>
            </div>
            
            <div class="action-buttons" style="margin-top: 20px;">
                <button class="btn btn-warning" onclick="editEquipment(<?php echo $view_equipment['id']; ?>)">
                    ✏️ Edit Equipment
                </button>
                <button class="btn btn-success" onclick="scheduleMaintenance(<?php echo $view_equipment['id']; ?>, '<?php echo htmlspecialchars($view_equipment['name']); ?>')">
                    📅 Schedule Maintenance
                </button>
                <button class="btn btn-info" onclick="viewMaintenanceHistory(<?php echo $view_equipment['id']; ?>)">
                    🔧 View History
                </button>
            </div>
            <?php endif; ?>
        </div>
    </div>
    
    <!-- Schedule Maintenance Modal -->
    <div id="scheduleModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2>Schedule Maintenance</h2>
                <button class="close-btn" onclick="closeModal('scheduleModal')">&times;</button>
            </div>
            
            <form method="POST" action="">
                <input type="hidden" id="schedule_equipment_id" name="schedule_equipment_id">
                
                <div class="form-group">
                    <label>Equipment</label>
                    <input type="text" id="schedule_equipment_name" class="form-control" readonly>
                </div>
                
                <div class="form-group">
                    <label>Subject *</label>
                    <input type="text" name="subject" class="form-control" required 
                           placeholder="e.g., Monthly Preventive Maintenance">
                </div>
                
                <div class="form-group">
                    <label>Description</label>
                    <textarea name="description" class="form-control" rows="3" 
                              placeholder="Describe the maintenance task..."></textarea>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label>Scheduled Date *</label>
                        <input type="date" name="scheduled_date" class="form-control" required 
                               min="<?php echo date('Y-m-d'); ?>">
                    </div>
                    
                    <div class="form-group">
                        <label>Type</label>
                        <select name="type" class="form-control">
                            <option value="Preventive">Preventive</option>
                            <option value="Corrective">Corrective</option>
                        </select>
                    </div>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label>Priority</label>
                        <select name="priority" class="form-control">
                            <option value="Low">Low</option>
                            <option value="Medium" selected>Medium</option>
                            <option value="High">High</option>
                            <option value="Critical">Critical</option>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label>Assign To</label>
                        <select name="assigned_to" class="form-control">
                            <option value="">Select Technician</option>
                            <?php foreach ($users as $user): 
                                if (in_array($user['role'], ['technician', 'admin'])): ?>
                                    <option value="<?php echo $user['id']; ?>">
                                        <?php echo htmlspecialchars($user['full_name'] . ' (' . $user['role'] . ')'); ?>
                                    </option>
                                <?php endif; 
                            endforeach; ?>
                        </select>
                    </div>
                </div>
                
                <div style="display: flex; gap: 10px; margin-top: 30px;">
                    <button type="button" class="btn" onclick="closeModal('scheduleModal')">
                        Cancel
                    </button>
                    <button type="submit" name="schedule_maintenance" class="btn btn-primary">
                        📅 Schedule Maintenance
                    </button>
                </div>
            </form>
        </div>
    </div>
    
    <!-- Maintenance History Modal -->
    <div id="historyModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2>Maintenance History</h2>
                <button class="close-btn" onclick="closeModal('historyModal')">&times;</button>
            </div>
            
            <?php if ($action == 'view_history' && !empty($maintenance_history)): ?>
                <h3>Recent Maintenance Requests</h3>
                <div style="max-height: 400px; overflow-y: auto;">
                    <?php foreach ($maintenance_history as $history): 
                        $priority_class = strtolower($history['priority']);
                        $status_class = 'status-' . strtolower(str_replace(' ', '-', $history['status']));
                    ?>
                    <div class="history-item">
                        <div style="display: flex; justify-content: space-between; align-items: start;">
                            <div>
                                <strong><?php echo htmlspecialchars($history['subject']); ?></strong>
                                <div style="color: #666; font-size: 0.9rem; margin-top: 5px;">
                                    <?php echo htmlspecialchars($history['description']); ?>
                                </div>
                                <div class="history-date">
                                    Scheduled: <?php echo date('M d, Y', strtotime($history['scheduled_date'])); ?> |
                                    Created: <?php echo date('M d, Y', strtotime($history['created_at'])); ?>
                                    <?php if ($history['assigned_name']): ?>
                                        | Assigned to: <?php echo htmlspecialchars($history['assigned_name']); ?>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div style="text-align: right;">
                                <span class="status-badge <?php echo $status_class; ?>" style="display: block; margin-bottom: 5px;">
                                    <?php echo $history['status']; ?>
                                </span>
                                <span class="request-priority priority-<?php echo $priority_class; ?>" style="display: block;">
                                    <?php echo $history['priority']; ?>
                                </span>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                
                <div style="margin-top: 20px;">
                    <a href="requests.php?equipment=<?php echo $equipment_id; ?>" class="btn btn-info">
                        🔧 View All Maintenance Requests
                    </a>
                </div>
            <?php elseif ($action == 'view_history'): ?>
                <div style="text-align: center; padding: 40px; color: #666;">
                    <div style="font-size: 3rem; margin-bottom: 20px;">📋</div>
                    <h3>No maintenance history found</h3>
                    <p>This equipment has no maintenance records yet.</p>
                    <button class="btn btn-primary mt-2" onclick="closeModal('historyModal'); showScheduleModal()">
                        📅 Schedule First Maintenance
                    </button>
                </div>
            <?php endif; ?>
        </div>
    </div>
    
    <!-- Status Change Form -->
    <form method="POST" action="" id="statusForm" style="display: none;">
        <input type="hidden" name="equipment_id" id="equipmentId">
        <input type="hidden" name="status" id="statusValue">
        <input type="hidden" name="update_status" value="1">
    </form>
    
    <!-- Delete Equipment Form -->
    <form method="POST" action="" id="deleteForm" style="display: none;">
        <input type="hidden" name="delete_id" id="deleteId">
        <input type="hidden" name="delete_equipment" value="1">
    </form>
    
    <script>
        // Show modals based on URL parameters
        window.onload = function() {
            const urlParams = new URLSearchParams(window.location.search);
            const action = urlParams.get('action');
            const equipmentId = urlParams.get('equipment_id');
            const viewId = urlParams.get('view_id');
            
            if (action === 'edit' && equipmentId) {
                showEditModal();
            } else if (action === 'view' && viewId) {
                showViewModal();
            } else if (action === 'view_history' && equipmentId) {
                showHistoryModal();
            }
            
            // Clear URL parameters
            window.history.replaceState({}, document.title, window.location.pathname);
        };
        
        // Modal functions
        function showAddModal() {
            document.getElementById('addModal').style.display = 'flex';
        }
        
        function showEditModal() {
            document.getElementById('editModal').style.display = 'flex';
        }
        
        function showViewModal() {
            document.getElementById('viewModal').style.display = 'flex';
        }
        
        function showScheduleModal() {
            document.getElementById('scheduleModal').style.display = 'flex';
        }
        
        function showHistoryModal() {
            document.getElementById('historyModal').style.display = 'flex';
        }
        
        function closeModal(modalId) {
            document.getElementById(modalId).style.display = 'none';
            // Remove action parameters from URL
            window.history.replaceState({}, document.title, window.location.pathname);
        }
        
        // Equipment functions
        function viewEquipment(id) {
            window.location.href = '?action=view&view_id=' + id;
        }
        
        function editEquipment(id) {
            window.location.href = '?action=edit&equipment_id=' + id;
        }
        
        function scheduleMaintenance(id, name) {
            document.getElementById('schedule_equipment_id').value = id;
            document.getElementById('schedule_equipment_name').value = name;
            showScheduleModal();
        }
        
        function viewMaintenanceHistory(id) {
            window.location.href = '?action=view_history&equipment_id=' + id;
        }
        
        function changeStatus(equipmentId, status, equipmentName) {
            if (confirm('Are you sure you want to change "' + equipmentName + '" status to "' + status.replace('_', ' ') + '"?')) {
                document.getElementById('equipmentId').value = equipmentId;
                document.getElementById('statusValue').value = status;
                document.getElementById('statusForm').submit();
            }
        }
        
        function deleteEquipment(id, name) {
            if (confirm('Are you sure you want to delete "' + name + '"?\n\nNote: Equipment with maintenance history cannot be deleted. Consider setting status to "scrapped" instead.')) {
                document.getElementById('deleteId').value = id;
                document.getElementById('deleteForm').submit();
            }
        }
        
        // Filter and search functions
        function filterEquipment() {
            const status = document.getElementById('statusFilter').value;
            const department = document.getElementById('departmentFilter').value;
            const category = document.getElementById('categoryFilter').value;
            const search = document.getElementById('searchInput').value.toLowerCase();
            
            const rows = document.querySelectorAll('#equipmentTableBody tr');
            
            rows.forEach(row => {
                const rowStatus = row.getAttribute('data-status');
                const rowDepartment = row.getAttribute('data-department');
                const rowCategory = row.getAttribute('data-category');
                const rowName = row.getAttribute('data-name');
                const rowSerial = row.getAttribute('data-serial');
                
                let showRow = true;
                
                // Filter by status
                if (status && rowStatus !== status) {
                    showRow = false;
                }
                
                // Filter by department
                if (department && rowDepartment !== department) {
                    showRow = false;
                }
                
                // Filter by category
                if (category && rowCategory !== category) {
                    showRow = false;
                }
                
                // Search filter
                if (search && !rowName.includes(search) && !rowSerial.includes(search)) {
                    showRow = false;
                }
                
                row.style.display = showRow ? '' : 'none';
            });
        }
        
        // Initialize dropdowns
        document.querySelectorAll('.dropdown-toggle').forEach(toggle => {
            toggle.addEventListener('click', function(e) {
                e.stopPropagation();
                const menu = this.nextElementSibling;
                menu.style.display = menu.style.display === 'block' ? 'none' : 'block';
            });
        });
        
        // Close dropdowns when clicking outside
        document.addEventListener('click', function() {
            document.querySelectorAll('.dropdown-menu').forEach(menu => {
                menu.style.display = 'none';
            });
        });
        
        // Close modals when clicking outside
        window.onclick = function(event) {
            if (event.target.classList.contains('modal')) {
                event.target.style.display = 'none';
                window.history.replaceState({}, document.title, window.location.pathname);
            }
        }
        
        // Prevent form submission on Enter in search
        document.getElementById('searchInput').addEventListener('keypress', function(e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                filterEquipment();
            }
        });
        
        // Auto-focus first input in modals
        document.querySelectorAll('.modal').forEach(modal => {
            modal.addEventListener('shown', function() {
                const input = this.querySelector('input, select, textarea');
                if (input) input.focus();
            });
        });
    </script>
</body>
</html>