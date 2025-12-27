<?php
session_start();
require_once 'db_connection.php';

$team_id = intval($_GET['team_id']);

$sql = "
    SELECT u.*, 
           (SELECT COUNT(*) FROM maintenance_requests 
            WHERE assigned_to = u.id AND status IN ('New', 'In Progress')) as task_count
    FROM users u
    WHERE u.team_id = $team_id AND u.status = 'active'
    ORDER BY u.full_name
";

$result = mysqli_query($conn, $sql);
?>

<h3>Team Members</h3>
<div class="members-list">
    <?php if (mysqli_num_rows($result) > 0): ?>
        <?php while ($member = mysqli_fetch_assoc($result)): ?>
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
        <?php endwhile; ?>
    <?php else: ?>
        <p>No members in this team.</p>
    <?php endif; ?>
</div>