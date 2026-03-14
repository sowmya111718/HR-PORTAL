<?php
require_once '../config/db.php';
require_once '../includes/icon_functions.php'; // ADDED
require_once '../includes/birthday_functions.php'; // ADD THIS NEW FILE
checkRole(['hr', 'admin', 'pm']);

$user_id = isset($_GET['id']) ? intval($_GET['id']) : 0;
$message = '';

// Get user details
$stmt = $conn->prepare("
    SELECT * FROM users 
    WHERE id = ?
");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    $message = '<div class="alert alert-error"><i class="icon-error"></i> User not found</div>';
    $user = null;
} else {
    $user = $result->fetch_assoc();
}
$stmt->close();

// Get managers for reporting to dropdown - UPDATED to include all management roles
$managers_result = null;
if ($user) {
    $stmt = $conn->prepare("
        SELECT username, full_name, role 
        FROM users 
        WHERE role IN ('hr', 'pm', 'admin', 'MG') 
        AND id != ?
        ORDER BY 
            CASE role 
                WHEN 'admin' THEN 1
                WHEN 'hr' THEN 2
                WHEN 'pm' THEN 3
                WHEN 'MG' THEN 4
            END,
            full_name
    ");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $managers_result = $stmt->get_result();
    $stmt->close();
}

// Update user
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_user'])) {
    $username = sanitize($_POST['username']);
    $full_name = sanitize($_POST['full_name']);
    $role = sanitize($_POST['role']);
    $reporting_to = sanitize($_POST['reporting_to']);
    $email = sanitize($_POST['email']);
    $department = sanitize($_POST['department']);
    $position = sanitize($_POST['position']);
    $join_date = sanitize($_POST['join_date']);
    $birthday = !empty($_POST['birthday']) ? sanitize($_POST['birthday']) : null;
    $status = sanitize($_POST['status']);
    $password = $_POST['password'];
    
    // Prevent user from changing their own role
    if ($user_id == $_SESSION['user_id'] && $role !== $_SESSION['role']) {
        $message = '<div class="alert alert-error"><i class="icon-error"></i> You cannot change your own role</div>';
    } else {
        // Check if username already exists for another user
        $check_username = $conn->prepare("SELECT id FROM users WHERE username = ? AND id != ?");
        $check_username->bind_param("si", $username, $user_id);
        $check_username->execute();
        $username_result = $check_username->get_result();
        
        if ($username_result->num_rows > 0) {
            $message = '<div class="alert alert-error"><i class="icon-error"></i> Username already exists. Please choose a different username.</div>';
        } else {
            $update_stmt = null;
            
            if (!empty($password)) {
                // Update with password
                $password_hash = password_hash($password, PASSWORD_DEFAULT);
                $update_stmt = $conn->prepare("
                    UPDATE users SET 
                    username = ?, full_name = ?, role = ?, reporting_to = ?, email = ?, 
                    department = ?, position = ?, join_date = ?, birthday = ?, status = ?, password = ?
                    WHERE id = ?
                ");
                if ($update_stmt) {
                    $update_stmt->bind_param("sssssssssssi", $username, $full_name, $role, $reporting_to, $email, 
                                     $department, $position, $join_date, $birthday, $status, $password_hash, $user_id);
                }
            } else {
                // Update without changing password
                $update_stmt = $conn->prepare("
                    UPDATE users SET 
                    username = ?, full_name = ?, role = ?, reporting_to = ?, email = ?, 
                    department = ?, position = ?, join_date = ?, birthday = ?, status = ?
                    WHERE id = ?
                ");
                if ($update_stmt) {
                    $update_stmt->bind_param("ssssssssssi", $username, $full_name, $role, $reporting_to, $email, 
                                     $department, $position, $join_date, $birthday, $status, $user_id);
                }
            }
            
            if ($update_stmt) {
                if ($update_stmt->execute()) {
                    $message = '<div class="alert alert-success"><i class="icon-success"></i> User updated successfully</div>';
                    
                    // Refresh user data
                    $stmt = $conn->prepare("SELECT * FROM users WHERE id = ?");
                    $stmt->bind_param("i", $user_id);
                    $stmt->execute();
                    $result = $stmt->get_result();
                    $user = $result->fetch_assoc();
                    $stmt->close();
                } else {
                    $message = '<div class="alert alert-error"><i class="icon-error"></i> Error updating user: ' . $update_stmt->error . '</div>';
                }
                $update_stmt->close();
            } else {
                $message = '<div class="alert alert-error"><i class="icon-error"></i> Error preparing statement</div>';
            }
        }
        $check_username->close();
    }
}

$page_title = "Edit User - MAKSIM HR";
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit User - MAKSIM HR</title>
    <?php include '../includes/head.php'; ?>
    <style>
        .ceo-option {
            background: #fef3c7;
            border-left: 4px solid #92400e;
            font-weight: bold;
        }
        .birthday-badge {
            background: linear-gradient(135deg, #ff6b6b, #ff8787);
            color: white;
            padding: 2px 8px;
            border-radius: 12px;
            font-size: 11px;
            margin-left: 8px;
        }
        .birthday-field {
            border-left: 3px solid #ff6b6b !important;
        }
        .username-warning {
            color: #e67e22;
            font-size: 11px;
            margin-top: 3px;
        }
        .status-badge {
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
        }
        .status-on-duty {
            background: #c6f6d5;
            color: #276749;
            border: 1px solid #9ae6b4;
        }
        .status-active {
            background: #c6f6d5;
            color: #22543d;
        }
        .status-inactive {
            background: #fed7d7;
            color: #742a2a;
        }
        .status-note {
            background: #fef3c7;
            border-left: 4px solid #d97706;
            padding: 10px 15px;
            border-radius: 8px;
            margin-top: 10px;
            font-size: 13px;
        }
        .status-note i {
            color: #d97706;
        }
    </style>
</head>
<body>
    <?php include '../includes/header.php'; ?>
    
    <div class="app-main">
        <?php include '../includes/sidebar.php'; ?>
        
        <div class="main-content">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
                <h2 class="page-title"><i class="icon-edit"></i> Edit User</h2>
                <a href="users.php" class="btn-small btn-view">
                    <i class="icon-arrow-left"></i> Back to Users
                </a>
            </div>
            
            <?php if ($message): ?>
                <?php echo $message; ?>
            <?php endif; ?>
            
            <?php if ($user): ?>
            <!-- Edit User Form -->
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title"><i class="icon-edit"></i> Edit User: <?php echo $user['full_name']; ?></h3>
                </div>
                <form method="POST" action="" onsubmit="return validateForm()">
                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label">Username *</label>
                            <input type="text" name="username" class="form-control" value="<?php echo htmlspecialchars($user['username']); ?>" required 
                                   pattern="[a-zA-Z0-9_]+" title="Username can only contain letters, numbers, and underscores">
                            <small style="color: #718096;">You can change username. Only letters, numbers, and underscores allowed.</small>
                            <div id="username-error" class="username-warning" style="display: none;"></div>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Full Name *</label>
                            <input type="text" name="full_name" class="form-control" value="<?php echo htmlspecialchars($user['full_name']); ?>" required>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Email</label>
                            <input type="email" name="email" class="form-control" value="<?php echo htmlspecialchars($user['email'] ?? ''); ?>">
                        </div>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label">Role *</label>
                            <select name="role" class="form-control" required <?php echo $user['id'] == $_SESSION['user_id'] ? 'disabled' : ''; ?>>
                                <option value="employee" <?php echo $user['role'] === 'employee' ? 'selected' : ''; ?>>Employee</option>
                                <option value="HR" <?php echo $user['role'] === 'HR' ? 'selected' : ''; ?>>HR Manager</option>
                                <option value="PM" <?php echo $user['role'] === 'PM' ? 'selected' : ''; ?>>Project Manager</option>
                                <option value="MG" <?php echo $user['role'] === 'MG' ? 'selected' : ''; ?>>Management</option>
                                <option value="admin" <?php echo $user['role'] === 'admin' ? 'selected' : ''; ?>>Admin</option>
                            </select>
                            <?php if ($user['id'] == $_SESSION['user_id']): ?>
                                <input type="hidden" name="role" value="<?php echo $user['role']; ?>">
                                <small style="color: #718096;">You cannot change your own role</small>
                            <?php endif; ?>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Reporting To *</label>
                            <select name="reporting_to" class="form-control" required>
                                <option value="">Select Reporting Manager</option>
                                <option value="CEO" <?php echo $user['reporting_to'] === 'CEO' ? 'selected' : ''; ?> class="ceo-option">👑 CEO (Chief Executive Officer)</option>
                                <?php if ($managers_result && $managers_result->num_rows > 0): ?>
                                    <?php $managers_result->data_seek(0); ?>
                                    <?php while ($manager = $managers_result->fetch_assoc()): ?>
                                    <option value="<?php echo $manager['username']; ?>" <?php echo $user['reporting_to'] === $manager['username'] ? 'selected' : ''; ?>>
                                        <?php 
                                        $role_icon = '';
                                        if ($manager['role'] == 'admin') $role_icon = '🔧 ';
                                        elseif ($manager['role'] == 'hr') $role_icon = '👥 ';
                                        elseif ($manager['role'] == 'pm') $role_icon = '📋 ';
                                        elseif ($manager['role'] == 'MG') $role_icon = '👔 ';
                                        echo $role_icon . htmlspecialchars($manager['full_name']); 
                                        ?>
                                    </option>
                                    <?php endwhile; ?>
                                <?php endif; ?>
                                <option value="Management" <?php echo $user['reporting_to'] === 'Management' ? 'selected' : ''; ?>>Management Team</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Join Date</label>
                            <input type="date" name="join_date" class="form-control" value="<?php echo $user['join_date'] ?? date('Y-m-d'); ?>">
                        </div>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label">Department</label>
                            <input type="text" name="department" class="form-control" value="<?php echo htmlspecialchars($user['department'] ?? ''); ?>">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Position</label>
                            <input type="text" name="position" class="form-control" value="<?php echo htmlspecialchars($user['position'] ?? ''); ?>">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Status
                                <?php if (!in_array($_SESSION['role'], ['pm', 'admin'])): ?>
                                <span style="color: #e53e3e; font-size: 11px; margin-left: 5px;">(Only PM/Admin can change)</span>
                                <?php endif; ?>
                            </label>
                            <?php if (in_array($_SESSION['role'], ['pm', 'admin'])): ?>
                            <select name="status" class="form-control" required>
                                <option value="active" <?php echo ($user['status'] ?? 'active') == 'active' ? 'selected' : ''; ?>>
                                    Active
                                </option>
                                <option value="on_duty" <?php echo ($user['status'] ?? '') == 'on_duty' ? 'selected' : ''; ?>>
                                    On Duty (Auto Timesheet)
                                </option>
                                <option value="inactive" <?php echo ($user['status'] ?? '') == 'inactive' ? 'selected' : ''; ?>>
                                    Inactive
                                </option>
                            </select>
                            <small style="color: #718096; display: block; margin-top: 5px;">
                                <i class="icon-info"></i>
                                <strong>Active:</strong> Normal user — must submit timesheet manually.<br>
                                <strong>On Duty:</strong> Timesheet auto-submitted daily (8h, project/task set below).<br>
                                <strong>Inactive:</strong> No LOP generated for missing timesheets.
                            </small>
                            <?php else: ?>
                            <input type="hidden" name="status" value="<?php echo htmlspecialchars($user['status'] ?? 'active'); ?>">
                            <div style="padding: 10px; background: #f7fafc; border: 1px solid #e2e8f0; border-radius: 6px;">
                                <?php 
                                $cur_status = $user['status'] ?? 'active';
                                if ($cur_status == 'on_duty') echo '🟢 On Duty (Auto Timesheet)';
                                elseif ($cur_status == 'inactive') echo '🔴 Inactive';
                                else echo '🟡 Active';
                                ?>
                            </div>
                            <small style="color: #a0aec0; display: block; margin-top: 5px;">Only PM or Admin can change user status.</small>
                            <?php endif; ?>
                        </div>
                        
                        <!-- On Duty config - only show when on_duty selected -->
                        <div id="on_duty_config" style="display: <?php echo ($user['status'] ?? '') == 'on_duty' ? 'block' : 'none'; ?>; background: #f0fff4; border: 1px solid #9ae6b4; border-radius: 8px; padding: 15px; margin-top: 10px;">
                            <strong style="color: #276749;">⚙️ On Duty Auto-Timesheet Settings</strong>
                            <small style="display: block; color: #718096; margin-bottom: 10px;">These details will be used for the daily auto-submitted timesheet.</small>
                            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px;">
                                <div>
                                    <label class="form-label">Default Project ID</label>
                                    <input type="number" name="duty_project_id" class="form-control" placeholder="Project ID" value="<?php echo htmlspecialchars($user['duty_project_id'] ?? ''); ?>">
                                </div>
                                <div>
                                    <label class="form-label">Default Task Name</label>
                                    <input type="text" name="duty_task_name" class="form-control" placeholder="e.g. On Site Work" value="<?php echo htmlspecialchars($user['duty_task_name'] ?? 'On Duty'); ?>">
                                </div>
                                <div>
                                    <label class="form-label">Default Hours</label>
                                    <input type="number" name="duty_hours" class="form-control" min="1" max="12" value="<?php echo htmlspecialchars($user['duty_hours'] ?? '8'); ?>">
                                </div>
                                <div>
                                    <label class="form-label">Default Software</label>
                                    <input type="text" name="duty_software" class="form-control" placeholder="e.g. Other" value="<?php echo htmlspecialchars($user['duty_software'] ?? 'Other'); ?>">
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label">Birthday</label>
                            <?php 
                            // Format birthday for date input (Y-m-d)
                            $birthday_value = '';
                            if (!empty($user['birthday'])) {
                                // Ensure it's in Y-m-d format
                                $birthday_timestamp = strtotime($user['birthday']);
                                if ($birthday_timestamp) {
                                    $birthday_value = date('Y-m-d', $birthday_timestamp);
                                }
                            }
                            ?>
                            <input type="date" name="birthday" class="form-control birthday-field" 
                                   value="<?php echo $birthday_value; ?>" 
                                   min="1960-01-01" max="<?php echo date('Y-m-d'); ?>">
                            <small style="color: #718096;">
                                <i class="icon-cake"></i> 
                                <?php 
                                if (!empty($user['birthday'])) {
                                    $bday = new DateTime($user['birthday']);
                                    $today = new DateTime();
                                    // Set to current year for calculation
                                    $bday_this_year = clone $bday;
                                    $bday_this_year->setDate($today->format('Y'), $bday->format('m'), $bday->format('d'));
                                    
                                    if ($bday_this_year < $today) {
                                        $bday_this_year->modify('+1 year');
                                    }
                                    $days = $today->diff($bday_this_year)->days;
                                    echo "Birthday in $days days";
                                } else {
                                    echo "Set birthday for celebrations";
                                }
                                ?>
                            </small>
                        </div>
                        <div class="form-group">
                            <label class="form-label">New Password</label>
                            <input type="password" name="password" class="form-control" placeholder="Leave blank to keep current password">
                            <small style="color: #718096;">Minimum 6 characters</small>
                        </div>
                    </div>
                    
                    <!-- Status Note -->
                    <?php if (($user['status'] ?? 'active') == 'inactive'): ?>
                    <div class="status-note">
                        <i class="icon-info"></i> 
                        <strong>Inactive User Note:</strong> This user will NOT receive auto-generated LOP for missing timesheets. 
                        They can still log in but are marked as inactive.
                    </div>
                    <?php endif; ?>
                    
                    <!-- User Stats -->
                    <div style="margin: 20px 0; padding: 15px; background: #f7fafc; border-radius: 10px;">
                        <h4 style="margin-bottom: 10px; color: #4a5568;">User Statistics</h4>
                        <div class="form-row">
                            <?php
                            // Get user statistics
                            $stats_stmt = $conn->prepare("
                                SELECT 
                                    (SELECT COUNT(*) FROM leaves WHERE user_id = ?) as total_leaves,
                                    (SELECT COUNT(*) FROM leaves WHERE user_id = ? AND leave_type = 'LOP') as lop_leaves,
                                    (SELECT COUNT(*) FROM permissions WHERE user_id = ?) as total_permissions,
                                    (SELECT COUNT(*) FROM timesheets WHERE user_id = ?) as total_timesheets,
                                    (SELECT COUNT(*) FROM attendance WHERE user_id = ?) as total_attendance
                            ");
                            $stats_stmt->bind_param("iiiii", $user_id, $user_id, $user_id, $user_id, $user_id);
                            $stats_stmt->execute();
                            $stats_result = $stats_stmt->get_result();
                            $user_stats = $stats_result->fetch_assoc();
                            $stats_stmt->close();
                            ?>
                            <div class="form-group">
                                <label class="form-label">Total Leaves</label>
                                <input type="text" class="form-control" value="<?php echo $user_stats['total_leaves']; ?>" readonly>
                            </div>
                            <div class="form-group">
                                <label class="form-label">LOP Taken</label>
                                <input type="text" class="form-control" value="<?php echo $user_stats['lop_leaves']; ?>" readonly style="color: #c53030; font-weight: bold;">
                            </div>
                            <div class="form-group">
                                <label class="form-label">Total Permissions</label>
                                <input type="text" class="form-control" value="<?php echo $user_stats['total_permissions']; ?>" readonly>
                            </div>
                            <div class="form-group">
                                <label class="form-label">Total Timesheets</label>
                                <input type="text" class="form-control" value="<?php echo $user_stats['total_timesheets']; ?>" readonly>
                            </div>
                            <div class="form-group">
                                <label class="form-label">Total Attendance</label>
                                <input type="text" class="form-control" value="<?php echo $user_stats['total_attendance']; ?>" readonly>
                            </div>
                        </div>
                    </div>
                    
                    <div style="display: flex; gap: 10px;">
                        <button type="submit" name="update_user" class="btn">
                            <i class="icon-save"></i> Update User
                        </button>
                        <a href="users.php" class="btn" style="background: #718096;">
                            <i class="icon-cancel"></i> Cancel
                        </a>
                    </div>
                </form>
            </div>

            <!-- User Activity -->
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title"><i class="icon-history"></i> Recent Activity</h3>
                </div>
                <div class="table-container">
                    <?php
                    // Get recent leaves
                    $recent_stmt = $conn->prepare("
                        SELECT 'Leave' as type, 
                               CASE WHEN leave_type = 'LOP' THEN CONCAT('LOP: ', leave_type) ELSE leave_type END as description, 
                               from_date, to_date, status, applied_date as date,
                               leave_type as leave_type_col
                        FROM leaves 
                        WHERE user_id = ? 
                        UNION ALL
                        SELECT 'Permission' as type, CONCAT(duration, ' hours') as description, permission_date as from_date, NULL as to_date, status, applied_date as date, NULL as leave_type_col
                        FROM permissions 
                        WHERE user_id = ?
                        ORDER BY date DESC 
                        LIMIT 10
                    ");
                    $recent_stmt->bind_param("ii", $user_id, $user_id);
                    $recent_stmt->execute();
                    $recent_result = $recent_stmt->get_result();
                    ?>
                    <table>
                        <thead>
                            <tr>
                                <th>Type</th>
                                <th>Description</th>
                                <th>Date</th>
                                <th>Status</th>
                                <th>Action Date</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($recent_result && $recent_result->num_rows > 0): ?>
                                <?php while ($activity = $recent_result->fetch_assoc()): ?>
                                <tr <?php echo ($activity['leave_type_col'] ?? '') == 'LOP' ? 'style="background: #fff5f5;"' : ''; ?>>
                                    <td>
                                        <?php if (($activity['leave_type_col'] ?? '') == 'LOP'): ?>
                                            <span style="color: #c53030; font-weight: 600;">
                                                <i class="icon-lop"></i> LOP
                                            </span>
                                        <?php else: ?>
                                            <?php echo $activity['type']; ?>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo $activity['description']; ?></td>
                                    <td>
                                        <?php if ($activity['type'] === 'Leave'): ?>
                                            <?php echo $activity['from_date']; ?> to <?php echo $activity['to_date']; ?>
                                        <?php else: ?>
                                            <?php echo $activity['from_date']; ?>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span class="status-badge status-<?php echo strtolower($activity['status']); ?>">
                                            <?php echo $activity['status']; ?>
                                        </span>
                                    </td>
                                    <td><?php echo date('Y-m-d H:i', strtotime($activity['date'])); ?></td>
                                </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="5" style="text-align: center; padding: 20px; color: #718096;">
                                        <i class="icon-folder-open" style="font-size: 24px; margin-right: 10px;"></i> No recent activity found
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                    <?php if ($recent_stmt) $recent_stmt->close(); ?>
                </div>
            </div>
            <?php else: ?>
                <div class="card">
                    <div style="text-align: center; padding: 40px;">
                        <i class="icon-user" style="font-size: 48px; color: #cbd5e0; margin-bottom: 15px;"></i>
                        <h3 style="color: #718096; margin-bottom: 10px;">User Not Found</h3>
                        <p style="color: #a0aec0;">The user you're trying to edit does not exist or has been deleted.</p>
                        <a href="users.php" class="btn" style="margin-top: 20px;">
                            <i class="icon-arrow-left"></i> Back to Users
                        </a>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <script>
    function validateForm() {
        const username = document.querySelector('input[name="username"]').value;
        const usernameRegex = /^[a-zA-Z0-9_]+$/;
        const errorDiv = document.getElementById('username-error');
        
        if (!usernameRegex.test(username)) {
            errorDiv.style.display = 'block';
            errorDiv.innerHTML = '<i class="icon-error"></i> Username can only contain letters, numbers, and underscores';
            return false;
        }
        
        const password = document.querySelector('input[name="password"]').value;
        if (password && password.length < 6) {
            alert('Password must be at least 6 characters long if you want to change it');
            return false;
        }
        
        return true;
    }

    // Auto-hide alerts after 5 seconds
    setTimeout(function() {
        const alerts = document.querySelectorAll('.alert');
        alerts.forEach(alert => {
            alert.style.transition = 'opacity 0.5s';
            alert.style.opacity = '0';
            setTimeout(() => alert.remove(), 500);
        });
    }, 5000);
    </script>
    
    <script src="../assets/js/app.js"></script>
    <script>
    const statusSelect = document.querySelector('select[name="status"]');
    const dutyConfig = document.getElementById('on_duty_config');
    if (statusSelect && dutyConfig) {
        statusSelect.addEventListener('change', function() {
            dutyConfig.style.display = this.value === 'on_duty' ? 'block' : 'none';
        });
    }
    </script>
</body>
</html>