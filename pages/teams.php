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
$team_id = $_GET['team_id'] ?? 0;
$member_id = $_GET['member_id'] ?? 0;
$message = '';

// Add new team
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['add_team'])) {
    $name = mysqli_real_escape_string($conn, $_POST['name']);
    $specialization = mysqli_real_escape_string($conn, $_POST['specialization']);
    $description = mysqli_real_escape_string($conn, $_POST['description']);
    
    $sql = "INSERT INTO maintenance_teams (name, specialization, description) 
            VALUES ('$name', '$specialization', '$description')";
    if (mysqli_query($conn, $sql)) {
        $message = "Team added successfully!";
    }
}

// Edit team
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['edit_team'])) {
    $team_id = intval($_POST['team_id']);
    $name = mysqli_real_escape_string($conn, $_POST['name']);
    $specialization = mysqli_real_escape_string($conn, $_POST['specialization']);
    $description = mysqli_real_escape_string($conn, $_POST['description']);
    $status = mysqli_real_escape_string($conn, $_POST['status']);
    
    $sql = "UPDATE maintenance_teams SET 
            name = '$name', 
            specialization = '$specialization', 
            description = '$description',
            status = '$status'
            WHERE id = $team_id";
    if (mysqli_query($conn, $sql)) {
        $message = "Team updated successfully!";
    }
}

// Delete team
if ($action == 'delete_team' && $team_id > 0) {
    // Check if team has members
    $check_sql = "SELECT COUNT(*) as count FROM users WHERE team_id = $team_id";
    $check_result = mysqli_query($conn, $check_sql);
    $check_data = mysqli_fetch_assoc($check_result);
    
    if ($check_data['count'] == 0) {
        $sql = "DELETE FROM maintenance_teams WHERE id = $team_id";
        if (mysqli_query($conn, $sql)) {
            $message = "Team deleted successfully!";
        }
    } else {
        $message = "Cannot delete team with members assigned!";
    }
}

// Assign member to team
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['assign_member'])) {
    $member_id = intval($_POST['member_id']);
    $team_id = intval($_POST['team_id']);
    
    $sql = "UPDATE users SET team_id = $team_id WHERE id = $member_id";
    if (mysqli_query($conn, $sql)) {
        $message = "Member assigned to team successfully!";
    }
}

// Get all teams
$teams = [];
$result = mysqli_query($conn, "SELECT * FROM maintenance_teams ORDER BY name");
if ($result) {
    while ($row = mysqli_fetch_assoc($result)) {
        $teams[] = $row;
    }
}

// Get all team members from database
$team_members = [];
$member_result = mysqli_query($conn, "
    SELECT u.id, u.full_name as name, u.email, u.role, u.team_id,
           mt.name as team_name,
           COUNT(mr.id) as task_count
    FROM users u
    LEFT JOIN maintenance_teams mt ON u.team_id = mt.id
    LEFT JOIN maintenance_requests mr ON u.id = mr.assigned_to AND mr.status IN ('New', 'In Progress')
    WHERE u.role IN ('technician', 'admin')
    GROUP BY u.id
    ORDER BY mt.name, u.full_name
");

if ($member_result) {
    while ($row = mysqli_fetch_assoc($member_result)) {
        $team_members[] = $row;
    }
}

// Get total tasks count
$total_tasks = 0;
$tasks_result = mysqli_query($conn, "
    SELECT COUNT(*) as total FROM maintenance_requests 
    WHERE status IN ('New', 'In Progress')
");
if ($tasks_result) {
    $task_data = mysqli_fetch_assoc($tasks_result);
    $total_tasks = $task_data['total'];
}

// Get team statistics
$team_stats = [];
$stats_result = mysqli_query($conn, "
    SELECT 
        mt.id as team_id,
        mt.name as team_name,
        COUNT(DISTINCT u.id) as member_count,
        COUNT(DISTINCT mr.id) as active_tasks,
        COUNT(DISTINCT mr_completed.id) as completed_tasks
    FROM maintenance_teams mt
    LEFT JOIN users u ON mt.id = u.team_id AND u.status = 'active'
    LEFT JOIN maintenance_requests mr ON u.id = mr.assigned_to AND mr.status IN ('New', 'In Progress')
    LEFT JOIN maintenance_requests mr_completed ON u.id = mr_completed.assigned_to AND mr_completed.status = 'Repaired'
    GROUP BY mt.id
    ORDER BY mt.name
");

if ($stats_result) {
    while ($row = mysqli_fetch_assoc($stats_result)) {
        $team_stats[$row['team_id']] = $row;
    }
}

// Get team members for each team
$members_by_team = [];
$all_members_result = mysqli_query($conn, "
    SELECT u.*, mt.name as team_name,
           (SELECT COUNT(*) FROM maintenance_requests 
            WHERE assigned_to = u.id AND status IN ('New', 'In Progress')) as task_count
    FROM users u
    LEFT JOIN maintenance_teams mt ON u.team_id = mt.id
    WHERE u.role IN ('technician', 'admin') AND u.status = 'active'
    ORDER BY mt.name, u.full_name
");

if ($all_members_result) {
    while ($row = mysqli_fetch_assoc($all_members_result)) {
        if ($row['team_name']) {
            $members_by_team[$row['team_name']][] = $row;
        } else {
            $members_by_team['Unassigned'][] = $row;
        }
    }
}

// Get team for editing/viewing
$edit_team = null;
if ($action == 'edit' && $team_id > 0) {
    $result = mysqli_query($conn, "SELECT * FROM maintenance_teams WHERE id = $team_id");
    $edit_team = mysqli_fetch_assoc($result);
}

// Get member for viewing
$view_member = null;
if ($action == 'view_member' && $member_id > 0) {
    $result = mysqli_query($conn, "
        SELECT u.*, mt.name as team_name,
               (SELECT COUNT(*) FROM maintenance_requests 
                WHERE assigned_to = u.id) as total_tasks,
               (SELECT COUNT(*) FROM maintenance_requests 
                WHERE assigned_to = u.id AND status = 'Repaired') as completed_tasks
        FROM users u
        LEFT JOIN maintenance_teams mt ON u.team_id = mt.id
        WHERE u.id = $member_id
    ");
    $view_member = mysqli_fetch_assoc($result);
}

// Get all available technicians for assignment
$available_technicians = [];
$tech_result = mysqli_query($conn, "
    SELECT id, full_name, email, role 
    FROM users 
    WHERE role IN ('technician', 'admin') AND status = 'active'
    ORDER BY full_name
");
if ($tech_result) {
    while ($row = mysqli_fetch_assoc($tech_result)) {
        $available_technicians[] = $row;
    }
}

// Get all equipment for task assignment
$equipment_list = [];
$equip_result = mysqli_query($conn, "
    SELECT id, name, serial_number 
    FROM equipment 
    WHERE status = 'active'
    ORDER BY name
");
if ($equip_result) {
    while ($row = mysqli_fetch_assoc($equip_result)) {
        $equipment_list[] = $row;
    }
}

mysqli_close($conn);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Maintenance Teams - GearGuard</title>
    <link rel="stylesheet" href="../css/style.css">
    <style>
        .teams-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 20px;
            margin-top: 30px;
        }
        
        .team-card {
            background: white;
            border-radius: 10px;
            padding: 25px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.08);
            border: 1px solid #eaeaea;
            transition: all 0.3s;
        }
        
        .team-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 5px 20px rgba(0,0,0,0.12);
        }
        
        .team-header {
            display: flex;
            align-items: center;
            gap: 15px;
            margin-bottom: 20px;
        }
        
        .team-icon {
            width: 60px;
            height: 60px;
            background: linear-gradient(135deg, #667eea, #764ba2);
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 1.5rem;
        }
        
        .team-info h3 {
            margin: 0;
            color: #333;
        }
        
        .team-info p {
            margin: 5px 0 0 0;
            color: #666;
        }
        
        .team-stats {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 10px;
            margin: 20px 0;
        }
        
        .stat-item {
            background: #f8f9fa;
            padding: 10px;
            border-radius: 6px;
            text-align: center;
        }
        
        .stat-value {
            font-size: 1.2rem;
            font-weight: bold;
            color: #667eea;
            margin-bottom: 5px;
        }
        
        .stat-label {
            font-size: 0.85rem;
            color: #666;
        }
        
        .member-avatar {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: #667eea;
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
        }
        
        .members-list {
            margin-top: 20px;
            border-top: 1px solid #eaeaea;
            padding-top: 15px;
        }
        
        .member-item {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 10px;
            border-radius: 6px;
            margin-bottom: 5px;
            transition: background 0.3s;
        }
        
        .member-item:hover {
            background: #f8f9fa;
        }
        
        .member-info {
            flex: 1;
        }
        
        .member-name {
            font-weight: 500;
            color: #333;
        }
        
        .member-role {
            font-size: 0.85rem;
            color: #666;
        }
        
        .member-tasks {
            background: #e3f2fd;
            color: #1976d2;
            padding: 2px 8px;
            border-radius: 10px;
            font-size: 0.85rem;
            font-weight: 500;
        }
        
        .modal-overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0,0,0,0.5);
            z-index: 1000;
            justify-content: center;
            align-items: center;
        }
        
        .modal {
            background: white;
            border-radius: 10px;
            padding: 30px;
            width: 90%;
            max-width: 500px;
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
        
        .form-group {
            margin-bottom: 20px;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 5px;
            font-weight: 500;
            color: #333;
        }
        
        .form-control {
            width: 100%;
            padding: 8px 12px;
            border: 1px solid #ddd;
            border-radius: 6px;
            font-size: 1rem;
        }
        
        textarea.form-control {
            min-height: 100px;
            resize: vertical;
        }
        
        .modal-footer {
            display: flex;
            justify-content: flex-end;
            gap: 10px;
            margin-top: 30px;
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
        
        .member-details {
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
    </style>
</head>
<body>
    <?php include '../includes/header.php'; ?>
    
    <div class="container">
        <?php include '../includes/sidebar.php'; ?>
        
        <main class="main-content">
            <div class="page-header">
                <h1>Maintenance Teams</h1>
                <button class="btn btn-primary" onclick="showAddTeamModal()">
                    ➕ Add New Team
                </button>
            </div>
            
            <?php if ($message): ?>
                <div class="alert <?php echo strpos($message, 'Cannot') !== false ? 'alert-error' : 'alert-success'; ?>">
                    <?php echo $message; ?>
                </div>
            <?php endif; ?>
            
            <!-- Teams Statistics -->
            <div class="stats-overview">
                <div class="stat-card">
                    <div class="label">Total Teams</div>
                    <div class="value"><?php echo count($teams); ?></div>
                </div>
                <div class="stat-card">
                    <div class="label">Total Members</div>
                    <div class="value"><?php echo count($team_members); ?></div>
                </div>
                <div class="stat-card">
                    <div class="label">Active Tasks</div>
                    <div class="value"><?php echo $total_tasks; ?></div>
                </div>
                <div class="stat-card">
                    <div class="label">Avg. Tasks/Member</div>
                    <div class="value">
                        <?php echo count($team_members) > 0 ? round($total_tasks / count($team_members), 1) : 0; ?>
                    </div>
                </div>
            </div>
            
            <!-- Teams Grid -->
            <div class="teams-grid">
                <?php foreach ($teams as $team): 
                    $team_id = $team['id'];
                    $stats = $team_stats[$team_id] ?? ['member_count' => 0, 'active_tasks' => 0, 'completed_tasks' => 0];
                    $team_members_list = $members_by_team[$team['name']] ?? [];
                ?>
                <div class="team-card">
                    <div class="team-header">
                        <div class="team-icon">
                            <?php 
                            $icons = ['👥', '🔧', '💻', '⚡', '🔌', '🛠️'];
                            echo $icons[$team['id'] % count($icons)] ?? '👥';
                            ?>
                        </div>
                        <div class="team-info">
                            <h3><?php echo htmlspecialchars($team['name']); ?></h3>
                            <p><?php echo htmlspecialchars($team['specialization']); ?></p>
                        </div>
                    </div>
                    
                    <?php if ($team['description']): ?>
                        <div style="color: #666; margin-bottom: 15px; font-size: 0.95rem;">
                            <?php echo htmlspecialchars($team['description']); ?>
                        </div>
                    <?php endif; ?>
                    
                    <div class="team-stats">
                        <div class="stat-item">
                            <div class="stat-value"><?php echo $stats['member_count']; ?></div>
                            <div class="stat-label">Members</div>
                        </div>
                        <div class="stat-item">
                            <div class="stat-value"><?php echo $stats['active_tasks']; ?></div>
                            <div class="stat-label">Active Tasks</div>
                        </div>
                        <div class="stat-item">
                            <div class="stat-value"><?php echo $stats['completed_tasks']; ?></div>
                            <div class="stat-label">Completed</div>
                        </div>
                        <div class="stat-item">
                            <?php 
                            $total_assignments = $stats['active_tasks'] + $stats['completed_tasks'];
                            $success_rate = $total_assignments > 0 ? round(($stats['completed_tasks'] / $total_assignments) * 100) : 0;
                            ?>
                            <div class="stat-value"><?php echo $success_rate; ?>%</div>
                            <div class="stat-label">Success Rate</div>
                        </div>
                    </div>
                    
                    <?php if (!empty($team_members_list)): ?>
                        <div class="members-list">
                            <h4 style="margin: 0 0 10px 0; color: #333; font-size: 1rem;">Team Members</h4>
                            <?php foreach (array_slice($team_members_list, 0, 3) as $member): ?>
                                <div class="member-item" onclick="viewMember(<?php echo $member['id']; ?>)">
                                    <div class="member-avatar">
                                        <?php 
                                        $initials = '';
                                        $name_parts = explode(' ', $member['full_name']);
                                        foreach ($name_parts as $part) {
                                            $initials .= strtoupper(substr($part, 0, 1));
                                        }
                                        echo substr($initials, 0, 2);
                                        ?>
                                    </div>
                                    <div class="member-info">
                                        <div class="member-name"><?php echo htmlspecialchars($member['full_name']); ?></div>
                                        <div class="member-role"><?php echo htmlspecialchars(ucfirst($member['role'])); ?></div>
                                    </div>
                                    <div class="member-tasks" title="Active tasks">
                                        <?php echo $member['task_count'] ?? 0; ?> tasks
                                    </div>
                                </div>
                            <?php endforeach; ?>
                            
                            <?php if (count($team_members_list) > 3): ?>
                                <div style="text-align: center; margin-top: 10px;">
                                    <button class="btn btn-sm" onclick="viewTeamMembers(<?php echo $team['id']; ?>)">
                                        +<?php echo count($team_members_list) - 3; ?> more members
                                    </button>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                    
                    <div style="margin-top: 20px; display: flex; gap: 10px; justify-content: space-between;">
                        <button class="btn btn-sm btn-primary" onclick="viewTeam(<?php echo $team['id']; ?>)">
                            👁️ View Details
                        </button>
                        <button class="btn btn-sm btn-warning" onclick="editTeam(<?php echo $team['id']; ?>)">
                            ✏️ Edit Team
                        </button>
                        <button class="btn btn-sm" onclick="assignTask(<?php echo $team['id']; ?>)" 
                                style="background: #e8f5e9; color: #388e3c;">
                            📝 Assign Task
                        </button>
                        <button class="btn btn-sm btn-danger" onclick="deleteTeam(<?php echo $team['id']; ?>)">
                            🗑️ Delete
                        </button>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            
            <!-- Team Members Table -->
            <div class="table-container" style="margin-top: 40px;">
                <h2>All Team Members</h2>
                <table class="table">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Name</th>
                            <th>Team</th>
                            <th>Role</th>
                            <th>Email</th>
                            <th>Active Tasks</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($team_members as $member): ?>
                        <tr>
                            <td>#<?php echo $member['id']; ?></td>
                            <td>
                                <div style="display: flex; align-items: center; gap: 10px;">
                                    <div class="member-avatar" style="width: 35px; height: 35px; font-size: 0.9rem;">
                                        <?php 
                                        $initials = '';
                                        $name_parts = explode(' ', $member['name']);
                                        foreach ($name_parts as $part) {
                                            $initials .= strtoupper(substr($part, 0, 1));
                                        }
                                        echo substr($initials, 0, 2);
                                        ?>
                                    </div>
                                    <span><?php echo htmlspecialchars($member['name']); ?></span>
                                </div>
                            </td>
                            <td><?php echo htmlspecialchars($member['team_name'] ?? 'Unassigned'); ?></td>
                            <td>
                                <span class="status-badge status-progress">
                                    <?php echo htmlspecialchars(ucfirst($member['role'])); ?>
                                </span>
                            </td>
                            <td><?php echo htmlspecialchars($member['email']); ?></td>
                            <td>
                                <?php $task_count = $member['task_count'] ?? 0; ?>
                                <span style="font-weight: 500; color: <?php echo $task_count > 3 ? '#f44336' : ($task_count > 0 ? '#FF9800' : '#4CAF50'); ?>;">
                                    <?php echo $task_count; ?>
                                </span>
                            </td>
                            <td>
                                <span class="status-badge <?php echo $task_count > 0 ? 'status-progress' : 'status-repaired'; ?>">
                                    <?php echo $task_count > 0 ? 'Busy' : 'Available'; ?>
                                </span>
                            </td>
                            <td>
                                <div class="btn-group">
                                    <button class="btn btn-sm btn-primary" onclick="viewMember(<?php echo $member['id']; ?>)">
                                        Profile
                                    </button>
                                    <button class="btn btn-sm btn-warning" onclick="assignToMember(<?php echo $member['id']; ?>)">
                                        Assign
                                    </button>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </main>
    </div>
    
    <!-- Add Team Modal -->
    <div id="addTeamModal" class="modal-overlay">
        <div class="modal">
            <div class="modal-header">
                <h2>Add New Team</h2>
                <button class="close-btn" onclick="closeModal('addTeamModal')">×</button>
            </div>
            <form method="POST" action="">
                <div class="form-group">
                    <label for="team_name">Team Name *</label>
                    <input type="text" id="team_name" name="name" class="form-control" required>
                </div>
                <div class="form-group">
                    <label for="specialization">Specialization</label>
                    <input type="text" id="specialization" name="specialization" class="form-control">
                </div>
                <div class="form-group">
                    <label for="description">Description</label>
                    <textarea id="description" name="description" class="form-control"></textarea>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn" onclick="closeModal('addTeamModal')">Cancel</button>
                    <button type="submit" name="add_team" class="btn btn-primary">Add Team</button>
                </div>
            </form>
        </div>
    </div>
    
    <!-- Edit Team Modal -->
    <div id="editTeamModal" class="modal-overlay">
        <div class="modal">
            <div class="modal-header">
                <h2>Edit Team</h2>
                <button class="close-btn" onclick="closeModal('editTeamModal')">×</button>
            </div>
            <?php if ($edit_team): ?>
            <form method="POST" action="">
                <input type="hidden" name="team_id" value="<?php echo $edit_team['id']; ?>">
                <div class="form-group">
                    <label for="edit_name">Team Name *</label>
                    <input type="text" id="edit_name" name="name" class="form-control" 
                           value="<?php echo htmlspecialchars($edit_team['name']); ?>" required>
                </div>
                <div class="form-group">
                    <label for="edit_specialization">Specialization</label>
                    <input type="text" id="edit_specialization" name="specialization" class="form-control"
                           value="<?php echo htmlspecialchars($edit_team['specialization']); ?>">
                </div>
                <div class="form-group">
                    <label for="edit_description">Description</label>
                    <textarea id="edit_description" name="description" class="form-control"><?php echo htmlspecialchars($edit_team['description']); ?></textarea>
                </div>
                <div class="form-group">
                    <label for="edit_status">Status</label>
                    <select id="edit_status" name="status" class="form-control">
                        <option value="active" <?php echo $edit_team['status'] == 'active' ? 'selected' : ''; ?>>Active</option>
                        <option value="inactive" <?php echo $edit_team['status'] == 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                    </select>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn" onclick="closeModal('editTeamModal')">Cancel</button>
                    <button type="submit" name="edit_team" class="btn btn-primary">Update Team</button>
                </div>
            </form>
            <?php endif; ?>
        </div>
    </div>
    
    <!-- View Team Details Modal -->
    <div id="viewTeamModal" class="modal-overlay">
        <div class="modal">
            <div class="modal-header">
                <h2>Team Details</h2>
                <button class="close-btn" onclick="closeModal('viewTeamModal')">×</button>
            </div>
            <?php 
            if ($action == 'view' && $team_id > 0):
                $conn = mysqli_connect("localhost", "root", "", "gear_guard");
                $team_result = mysqli_query($conn, "
                    SELECT mt.*, 
                           COUNT(DISTINCT u.id) as member_count,
                           COUNT(DISTINCT mr.id) as active_requests
                    FROM maintenance_teams mt
                    LEFT JOIN users u ON mt.id = u.team_id AND u.status = 'active'
                    LEFT JOIN maintenance_requests mr ON u.id = mr.assigned_to AND mr.status IN ('New', 'In Progress')
                    WHERE mt.id = $team_id
                ");
                $team_details = mysqli_fetch_assoc($team_result);
                
                // Get team members
                $members_result = mysqli_query($conn, "
                    SELECT u.*, 
                           (SELECT COUNT(*) FROM maintenance_requests 
                            WHERE assigned_to = u.id AND status IN ('New', 'In Progress')) as task_count
                    FROM users u
                    WHERE u.team_id = $team_id AND u.status = 'active'
                ");
                $team_members_list = [];
                while ($row = mysqli_fetch_assoc($members_result)) {
                    $team_members_list[] = $row;
                }
                mysqli_close($conn);
            ?>
            <div class="member-details">
                <div class="detail-row">
                    <div class="detail-label">Team Name:</div>
                    <div class="detail-value"><?php echo htmlspecialchars($team_details['name']); ?></div>
                </div>
                <div class="detail-row">
                    <div class="detail-label">Specialization:</div>
                    <div class="detail-value"><?php echo htmlspecialchars($team_details['specialization']); ?></div>
                </div>
                <div class="detail-row">
                    <div class="detail-label">Status:</div>
                    <div class="detail-value">
                        <span class="status-badge <?php echo $team_details['status'] == 'active' ? 'status-repaired' : 'status-new'; ?>">
                            <?php echo ucfirst($team_details['status']); ?>
                        </span>
                    </div>
                </div>
                <div class="detail-row">
                    <div class="detail-label">Members:</div>
                    <div class="detail-value"><?php echo $team_details['member_count']; ?></div>
                </div>
                <div class="detail-row">
                    <div class="detail-label">Active Requests:</div>
                    <div class="detail-value"><?php echo $team_details['active_requests']; ?></div>
                </div>
                <?php if ($team_details['description']): ?>
                <div class="detail-row">
                    <div class="detail-label">Description:</div>
                    <div class="detail-value"><?php echo htmlspecialchars($team_details['description']); ?></div>
                </div>
                <?php endif; ?>
            </div>
            
            <?php if (!empty($team_members_list)): ?>
            <h3>Team Members</h3>
            <div class="members-list">
                <?php foreach ($team_members_list as $member): ?>
                    <div class="member-item" onclick="viewMember(<?php echo $member['id']; ?>)">
                        <div class="member-avatar">
                            <?php 
                            $initials = '';
                            $name_parts = explode(' ', $member['full_name']);
                            foreach ($name_parts as $part) {
                                $initials .= strtoupper(substr($part, 0, 1));
                            }
                            echo substr($initials, 0, 2);
                            ?>
                        </div>
                        <div class="member-info">
                            <div class="member-name"><?php echo htmlspecialchars($member['full_name']); ?></div>
                            <div class="member-role"><?php echo htmlspecialchars(ucfirst($member['role'])); ?></div>
                        </div>
                        <div class="member-tasks" title="Active tasks">
                            <?php echo $member['task_count'] ?? 0; ?> tasks
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
            
            <div class="modal-footer">
                <button type="button" class="btn" onclick="closeModal('viewTeamModal')">Close</button>
                <button type="button" class="btn btn-warning" onclick="editTeam(<?php echo $team_id; ?>)">Edit Team</button>
            </div>
            <?php endif; ?>
        </div>
    </div>
    
    <!-- View Member Modal -->
    <div id="viewMemberModal" class="modal-overlay">
        <div class="modal">
            <div class="modal-header">
                <h2>Member Details</h2>
                <button class="close-btn" onclick="closeModal('viewMemberModal')">×</button>
            </div>
            <?php if ($view_member): ?>
            <div class="member-details">
                <div class="detail-row">
                    <div class="detail-label">Full Name:</div>
                    <div class="detail-value"><?php echo htmlspecialchars($view_member['full_name']); ?></div>
                </div>
                <div class="detail-row">
                    <div class="detail-label">Email:</div>
                    <div class="detail-value"><?php echo htmlspecialchars($view_member['email']); ?></div>
                </div>
                <div class="detail-row">
                    <div class="detail-label">Username:</div>
                    <div class="detail-value"><?php echo htmlspecialchars($view_member['username']); ?></div>
                </div>
                <div class="detail-row">
                    <div class="detail-label">Role:</div>
                    <div class="detail-value"><?php echo htmlspecialchars(ucfirst($view_member['role'])); ?></div>
                </div>
                <div class="detail-row">
                    <div class="detail-label">Team:</div>
                    <div class="detail-value"><?php echo htmlspecialchars($view_member['team_name'] ?? 'Unassigned'); ?></div>
                </div>
                <div class="detail-row">
                    <div class="detail-label">Status:</div>
                    <div class="detail-value">
                        <span class="status-badge <?php echo $view_member['status'] == 'active' ? 'status-repaired' : 'status-new'; ?>">
                            <?php echo ucfirst($view_member['status']); ?>
                        </span>
                    </div>
                </div>
                <div class="detail-row">
                    <div class="detail-label">Total Tasks:</div>
                    <div class="detail-value"><?php echo $view_member['total_tasks'] ?? 0; ?></div>
                </div>
                <div class="detail-row">
                    <div class="detail-label">Completed Tasks:</div>
                    <div class="detail-value"><?php echo $view_member['completed_tasks'] ?? 0; ?></div>
                </div>
                <?php if ($view_member['completed_tasks'] > 0): ?>
                <div class="detail-row">
                    <div class="detail-label">Completion Rate:</div>
                    <div class="detail-value">
                        <?php 
                        $completion_rate = $view_member['total_tasks'] > 0 
                            ? round(($view_member['completed_tasks'] / $view_member['total_tasks']) * 100) 
                            : 0;
                        echo $completion_rate . '%';
                        ?>
                    </div>
                </div>
                <?php endif; ?>
            </div>
            
            <div class="modal-footer">
                <button type="button" class="btn" onclick="closeModal('viewMemberModal')">Close</button>
                <button type="button" class="btn btn-warning" onclick="assignToMember(<?php echo $view_member['id']; ?>)">Assign Task</button>
            </div>
            <?php endif; ?>
        </div>
    </div>
    
    <!-- Assign Member to Team Modal -->
    <div id="assignMemberModal" class="modal-overlay">
        <div class="modal">
            <div class="modal-header">
                <h2>Assign Member to Team</h2>
                <button class="close-btn" onclick="closeModal('assignMemberModal')">×</button>
            </div>
            <form method="POST" action="">
                <input type="hidden" id="assign_member_id" name="member_id">
                <div class="form-group">
                    <label for="assign_team">Select Team:</label>
                    <select id="assign_team" name="team_id" class="form-control" required>
                        <option value="">-- Select Team --</option>
                        <?php foreach ($teams as $team): ?>
                            <option value="<?php echo $team['id']; ?>"><?php echo htmlspecialchars($team['name']); ?></option>
                        <?php endforeach; ?>
                        <option value="0">Unassign from Team</option>
                    </select>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn" onclick="closeModal('assignMemberModal')">Cancel</button>
                    <button type="submit" name="assign_member" class="btn btn-primary">Assign</button>
                </div>
            </form>
        </div>
    </div>
    
    <!-- Assign Task Modal -->
    <div id="assignTaskModal" class="modal-overlay">
        <div class="modal">
            <div class="modal-header">
                <h2>Assign Task to Team</h2>
                <button class="close-btn" onclick="closeModal('assignTaskModal')">×</button>
            </div>
            <form method="POST" action="assign_task.php">
                <input type="hidden" id="assign_team_id" name="team_id">
                <div class="form-group">
                    <label for="task_subject">Task Subject *</label>
                    <input type="text" id="task_subject" name="subject" class="form-control" required>
                </div>
                <div class="form-group">
                    <label for="task_equipment">Equipment *</label>
                    <select id="task_equipment" name="equipment_id" class="form-control" required>
                        <option value="">-- Select Equipment --</option>
                        <?php foreach ($equipment_list as $equipment): ?>
                            <option value="<?php echo $equipment['id']; ?>">
                                <?php echo htmlspecialchars($equipment['name'] . ' (' . $equipment['serial_number'] . ')'); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label for="task_description">Description</label>
                    <textarea id="task_description" name="description" class="form-control"></textarea>
                </div>
                <div class="form-group">
                    <label for="task_priority">Priority</label>
                    <select id="task_priority" name="priority" class="form-control">
                        <option value="Low">Low</option>
                        <option value="Medium" selected>Medium</option>
                        <option value="High">High</option>
                        <option value="Critical">Critical</option>
                    </select>
                </div>
                <div class="form-group">
                    <label for="task_type">Type</label>
                    <select id="task_type" name="type" class="form-control">
                        <option value="Corrective">Corrective</option>
                        <option value="Preventive">Preventive</option>
                    </select>
                </div>
                <div class="form-group">
                    <label for="task_scheduled">Scheduled Date</label>
                    <input type="date" id="task_scheduled" name="scheduled_date" class="form-control">
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn" onclick="closeModal('assignTaskModal')">Cancel</button>
                    <button type="submit" name="assign_task" class="btn btn-primary">Assign Task</button>
                </div>
            </form>
        </div>
    </div>
    
    <!-- Team Members Modal -->
    <div id="teamMembersModal" class="modal-overlay">
        <div class="modal" style="max-width: 600px;">
            <div class="modal-header">
                <h2>Team Members</h2>
                <button class="close-btn" onclick="closeModal('teamMembersModal')">×</button>
            </div>
            <div id="teamMembersContent">
                <!-- Content will be loaded via AJAX -->
            </div>
        </div>
    </div>
    
    <script>
        // Show modals based on URL parameters
        window.onload = function() {
            const urlParams = new URLSearchParams(window.location.search);
            const action = urlParams.get('action');
            const teamId = urlParams.get('team_id');
            const memberId = urlParams.get('member_id');
            
            if (action === 'edit' && teamId) {
                showEditTeamModal();
            } else if (action === 'view' && teamId) {
                showViewTeamModal();
            } else if (action === 'view_member' && memberId) {
                showViewMemberModal();
            }
        };
        
        function showAddTeamModal() {
            document.getElementById('addTeamModal').style.display = 'flex';
        }
        
        function showEditTeamModal() {
            document.getElementById('editTeamModal').style.display = 'flex';
        }
        
        function showViewTeamModal() {
            document.getElementById('viewTeamModal').style.display = 'flex';
        }
        
        function showViewMemberModal() {
            document.getElementById('viewMemberModal').style.display = 'flex';
        }
        
        function closeModal(modalId) {
            document.getElementById(modalId).style.display = 'none';
            // Remove action parameters from URL
            window.history.replaceState({}, document.title, window.location.pathname);
        }
        
        function addNewTeam() {
            showAddTeamModal();
        }
        
        function viewTeam(id) {
            window.location.href = '?action=view&team_id=' + id;
        }
        
        function editTeam(id) {
            window.location.href = '?action=edit&team_id=' + id;
        }
        
        function viewMember(id) {
            window.location.href = '?action=view_member&member_id=' + id;
        }
        
        function assignToMember(id) {
            document.getElementById('assign_member_id').value = id;
            document.getElementById('assignMemberModal').style.display = 'flex';
        }
        
        function assignTask(teamId) {
            document.getElementById('assign_team_id').value = teamId;
            document.getElementById('assignTaskModal').style.display = 'flex';
        }
        
        function deleteTeam(id) {
            if (confirm('Are you sure you want to delete this team?')) {
                window.location.href = '?action=delete_team&team_id=' + id;
            }
        }
        
        function viewTeamMembers(teamId) {
            // Load team members via AJAX
            fetch('get_team_members.php?team_id=' + teamId)
                .then(response => response.text())
                .then(data => {
                    document.getElementById('teamMembersContent').innerHTML = data;
                    document.getElementById('teamMembersModal').style.display = 'flex';
                })
                .catch(error => {
                    document.getElementById('teamMembersContent').innerHTML = '<p>Error loading team members.</p>';
                    document.getElementById('teamMembersModal').style.display = 'flex';
                });
        }
        
        // Close modal when clicking outside
        window.onclick = function(event) {
            if (event.target.classList.contains('modal-overlay')) {
                event.target.style.display = 'none';
                // Remove action parameters from URL
                window.history.replaceState({}, document.title, window.location.pathname);
            }
        }
    </script>
</body>
</html>