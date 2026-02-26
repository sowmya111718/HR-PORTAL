<?php
require_once '../config/db.php';
require_once '../includes/leave_functions.php';
require_once '../includes/icon_functions.php'; // ADDED
checkRole(['hr', 'admin', 'pm']);

$message = '';

// Add new user
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_user'])) {
    $username = sanitize($_POST['username']);
    $password = password_hash($_POST['password'], PASSWORD_DEFAULT);
    $full_name = sanitize($_POST['full_name']);
    $role = sanitize($_POST['role']);
    $email = sanitize($_POST['email']);
    $department = sanitize($_POST['department']);
    $position = sanitize($_POST['position']);
    $reporting_to = sanitize($_POST['reporting_to']);
    $join_date = sanitize($_POST['join_date']);
    
    // Check if username exists
    $stmt = $conn->prepare("SELECT id FROM users WHERE username = ?");
    $stmt->bind_param("s", $username);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows > 0) {
        $message = '<div class="alert alert-error"><i class="icon-error"></i> Username already exists</div>';
    } else {
        $stmt = $conn->prepare("
            INSERT INTO users (username, password, role, full_name, email, department, position, reporting_to, join_date, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
        ");
        $stmt->bind_param("sssssssss", $username, $password, $role, $full_name, $email, $department, $position, $reporting_to, $join_date);
        
        if ($stmt->execute()) {
            // Get the new user ID
            $new_user_id = $stmt->insert_id;
            
            // Initialize leave balance for new employee based on join date
            $initial_balance = initializeEmployeeLeaveBalance($conn, $new_user_id, $join_date);
            
            $message = '<div class="alert alert-success">
                <i class="icon-success"></i> User created successfully!<br>
                <strong>Username:</strong> ' . $username . '<br>
                <strong>Full Name:</strong> ' . $full_name . '<br>
                <strong>Initial Leave Balance for ' . $initial_balance['leave_year'] . ':</strong><br>
                &nbsp;&nbsp;• Sick Leave: ' . $initial_balance['sick'] . ' days<br>
                &nbsp;&nbsp;• Casual Leave: ' . $initial_balance['casual'] . ' days (12 per year, 1 per month)<br>
                &nbsp;&nbsp;• Loss of Pay (LOP): 0 days (unpaid leave)
            </div>';
            
            // Store in session for display if needed
            $_SESSION['initial_leave_balance'] = $initial_balance;
            $_SESSION['new_user_name'] = $full_name;
            
        } else {
            $message = '<div class="alert alert-error"><i class="icon-error"></i> Error creating user: ' . $stmt->error . '</div>';
        }
    }
    $stmt->close();
}

// Delete user
if (isset($_GET['delete'])) {
    $user_id = intval($_GET['delete']);
    
    // Prevent deleting own account
    if ($user_id == $_SESSION['user_id']) {
        $message = '<div class="alert alert-error"><i class="icon-error"></i> You cannot delete your own account</div>';
    } else {
        // Begin transaction
        $conn->begin_transaction();
        
        try {
            // 1. First, update any users reporting to this user to NULL
            $update_reports = $conn->prepare("UPDATE users SET reporting_to = NULL WHERE reporting_to = (SELECT username FROM users WHERE id = ?)");
            $update_reports->bind_param("i", $user_id);
            $update_reports->execute();
            $update_reports->close();
            
            // 2. Get the username of the user being deleted (needed for reporting_to updates)
            $username_query = $conn->prepare("SELECT username FROM users WHERE id = ?");
            $username_query->bind_param("i", $user_id);
            $username_query->execute();
            $username_result = $username_query->get_result();
            $user_data = $username_result->fetch_assoc();
            $username = $user_data['username'] ?? '';
            $username_query->close();
            
            // 3. Update reporting_to for any users that have this user as manager (using username)
            if (!empty($username)) {
                $update_reports_by_username = $conn->prepare("UPDATE users SET reporting_to = NULL WHERE reporting_to = ?");
                $update_reports_by_username->bind_param("s", $username);
                $update_reports_by_username->execute();
                $update_reports_by_username->close();
            }
            
            // 4. Update approved_by in leaves table to NULL (set to NULL instead of deleting)
            $update_leaves_approved = $conn->prepare("UPDATE leaves SET approved_by = NULL WHERE approved_by = ?");
            $update_leaves_approved->bind_param("i", $user_id);
            $update_leaves_approved->execute();
            $update_leaves_approved->close();
            
            // 5. Update rejected_by in leaves table to NULL
            $update_leaves_rejected = $conn->prepare("UPDATE leaves SET rejected_by = NULL WHERE rejected_by = ?");
            $update_leaves_rejected->bind_param("i", $user_id);
            $update_leaves_rejected->execute();
            $update_leaves_rejected->close();
            
            // 6. Update cancelled_by in leaves table to NULL (if exists)
            if ($conn->query("SHOW COLUMNS FROM leaves LIKE 'cancelled_by'")->num_rows > 0) {
                $update_leaves_cancelled = $conn->prepare("UPDATE leaves SET cancelled_by = NULL WHERE cancelled_by = ?");
                $update_leaves_cancelled->bind_param("i", $user_id);
                $update_leaves_cancelled->execute();
                $update_leaves_cancelled->close();
            }
            
            // 7. Update approved_by in permissions table to NULL
            if ($conn->query("SHOW COLUMNS FROM permissions LIKE 'approved_by'")->num_rows > 0) {
                $update_permissions_approved = $conn->prepare("UPDATE permissions SET approved_by = NULL WHERE approved_by = ?");
                $update_permissions_approved->bind_param("i", $user_id);
                $update_permissions_approved->execute();
                $update_permissions_approved->close();
            }
            
            // 8. Update rejected_by in permissions table to NULL
            if ($conn->query("SHOW COLUMNS FROM permissions LIKE 'rejected_by'")->num_rows > 0) {
                $update_permissions_rejected = $conn->prepare("UPDATE permissions SET rejected_by = NULL WHERE rejected_by = ?");
                $update_permissions_rejected->bind_param("i", $user_id);
                $update_permissions_rejected->execute();
                $update_permissions_rejected->close();
            }
            
            // 9. Update approved_by in timesheets table to NULL (if exists)
            if ($conn->query("SHOW COLUMNS FROM timesheets LIKE 'approved_by'")->num_rows > 0) {
                $update_timesheets_approved = $conn->prepare("UPDATE timesheets SET approved_by = NULL WHERE approved_by = ?");
                $update_timesheets_approved->bind_param("i", $user_id);
                $update_timesheets_approved->execute();
                $update_timesheets_approved->close();
            }
            
            // 10. Delete leave balances
            if ($conn->query("SHOW TABLES LIKE 'leave_balances'")->num_rows > 0) {
                $delete_balances = $conn->prepare("DELETE FROM leave_balances WHERE user_id = ?");
                $delete_balances->bind_param("i", $user_id);
                $delete_balances->execute();
                $delete_balances->close();
            }
            
            // 11. Delete leave balances archive
            if ($conn->query("SHOW TABLES LIKE 'leave_balances_archive'")->num_rows > 0) {
                $delete_archive = $conn->prepare("DELETE FROM leave_balances_archive WHERE user_id = ?");
                $delete_archive->bind_param("i", $user_id);
                $delete_archive->execute();
                $delete_archive->close();
            }
            
            // 12. Delete attendance records
            $stmt1 = $conn->prepare("DELETE FROM attendance WHERE user_id = ?");
            $stmt1->bind_param("i", $user_id);
            $stmt1->execute();
            $stmt1->close();
            
            // 13. Delete timesheet records
            $stmt2 = $conn->prepare("DELETE FROM timesheets WHERE user_id = ?");
            $stmt2->bind_param("i", $user_id);
            $stmt2->execute();
            $stmt2->close();
            
            // 14. Delete permission requests
            $stmt3 = $conn->prepare("DELETE FROM permissions WHERE user_id = ?");
            $stmt3->bind_param("i", $user_id);
            $stmt3->execute();
            $stmt3->close();
            
            // 15. Delete leave applications
            $stmt4 = $conn->prepare("DELETE FROM leaves WHERE user_id = ?");
            $stmt4->bind_param("i", $user_id);
            $stmt4->execute();
            $stmt4->close();
            
            // 16. Finally delete the user
            $stmt5 = $conn->prepare("DELETE FROM users WHERE id = ?");
            $stmt5->bind_param("i", $user_id);
            $stmt5->execute();
            
            if ($stmt5->affected_rows > 0) {
                $conn->commit();
                $message = '<div class="alert alert-success"><i class="icon-success"></i> User and all associated records deleted successfully</div>';
            } else {
                $conn->rollback();
                $message = '<div class="alert alert-error"><i class="icon-error"></i> User not found</div>';
            }
            $stmt5->close();
            
        } catch (Exception $e) {
            $conn->rollback();
            $message = '<div class="alert alert-error"><i class="icon-error"></i> Error deleting user: ' . $e->getMessage() . '</div>';
        }
    }
}

// Get all users
$users = $conn->query("
    SELECT u.*, r.full_name as reporting_manager 
    FROM users u 
    LEFT JOIN users r ON u.reporting_to = r.username 
    ORDER BY u.role, u.full_name
");

// Get managers for reporting to dropdown - UPDATED to include all management roles
$managers = $conn->query("
    SELECT username, full_name 
    FROM users 
    WHERE role IN ('hr', 'pm', 'admin', 'MG') 
    ORDER BY full_name
");

// Get current leave year info for display
$current_leave_year = getCurrentLeaveYear();

$page_title = "User Management - MAKSIM HR";
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>User Management - MAKSIM HR</title>
    <?php include '../includes/head.php'; ?>
    <style>
        .alert {
            padding: 15px;
            border-radius: 10px;
            margin-bottom: 20px;
        }
        .alert-success {
            background: #d1fae5;
            color: #065f46;
            border: 1px solid #a7f3d0;
        }
        .alert-error {
            background: #fde2e2;
            color: #991b1b;
            border: 1px solid #fecaca;
        }
        .user-role-badge {
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 600;
            display: inline-block;
        }
        .role-admin, .role-hr, .role-HR {
            background: #dbeafe;
            color: #1e40af;
        }
        .role-pm, .role-PM {
            background: #fef3c7;
            color: #92400e;
        }
        .role-MG {
            background: #e9d8fd;
            color: #553c9a;
        }
        .role-employee {
            background: #d1fae5;
            color: #065f46;
        }
        .form-row {
            display: flex;
            gap: 20px;
            margin-bottom: 15px;
            flex-wrap: wrap;
        }
        .form-group {
            flex: 1;
            min-width: 200px;
        }
        .btn-small {
            padding: 6px 12px;
            font-size: 12px;
            border-radius: 6px;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 4px;
        }
        .btn-edit {
            background: #3498db;
            color: white;
        }
        .btn-delete {
            background: #e74c3c;
            color: white;
        }
        .btn-view {
            background: #2ecc71;
            color: white;
        }
        .table-container {
            overflow-x: auto;
        }
        table {
            width: 100%;
            border-collapse: collapse;
        }
        th {
            background: #f8f9fa;
            padding: 12px;
            text-align: left;
            border-bottom: 2px solid #dee2e6;
        }
        td {
            padding: 12px;
            border-bottom: 1px solid #dee2e6;
        }
        .action-buttons {
            display: flex;
            gap: 8px;
        }
        .leave-year-info {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 15px 20px;
            border-radius: 10px;
            margin-bottom: 25px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 15px;
        }
        .leave-badge {
            background: rgba(255,255,255,0.2);
            padding: 5px 12px;
            border-radius: 20px;
            font-size: 13px;
            display: inline-flex;
            align-items: center;
            gap: 5px;
        }
        .initial-balance-info {
            background: #ebf8ff;
            border-left: 4px solid #4299e1;
            padding: 15px;
            border-radius: 8px;
            margin-top: 10px;
        }
        .ceo-option {
            background: #fef3c7;
            border-left: 4px solid #92400e;
            font-weight: bold;
        }
    </style>
</head>
<body>
    <?php include '../includes/header.php'; ?>
    
    <div class="app-main">
        <?php include '../includes/sidebar.php'; ?>
        
        <div class="main-content">
            <h2 class="page-title">
                <i class="icon-users"></i> User Management
            </h2>
            
            <!-- Leave Year Info -->
            <div class="leave-year-info">
                <div>
                    <i class="icon-calendar"></i> 
                    <strong>Current Leave Year:</strong> <?php echo $current_leave_year['year_label']; ?> (Mar 16 - Mar 15)
                </div>
                <div class="leave-badge">
                    <i class="icon-clock"></i> 
                    New employees get prorated leave based on join date
                </div>
            </div>
            
            <?php if ($message): ?>
                <?php echo $message; ?>
            <?php endif; ?>
            
            <!-- Add User Form -->
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title"><i class="icon-user-plus"></i> Add New User</h3>
                </div>
                <form method="POST" action="" id="addUserForm">
                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label">Username *</label>
                            <input type="text" name="username" id="username" class="form-control" required>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Password *</label>
                            <input type="password" name="password" id="password" class="form-control" required minlength="6">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Full Name *</label>
                            <input type="text" name="full_name" id="full_name" class="form-control" required>
                        </div>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label">Role *</label>
                            <select name="role" id="role" class="form-control" required>
                                <option value="employee">Employee</option>
                                <option value="HR">HR Manager</option>
                                <option value="PM">Project Manager</option>
                                <option value="MG">Management</option>
                                <option value="admin">Admin</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Email</label>
                            <input type="email" name="email" id="email" class="form-control">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Department</label>
                            <input type="text" name="department" id="department" class="form-control">
                        </div>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label">Position</label>
                            <input type="text" name="position" id="position" class="form-control">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Reporting To *</label>
                            <select name="reporting_to" id="reporting_to" class="form-control" required>
                                <option value="">Select Reporting Manager</option>
                                <option value="CEO" class="ceo-option">👑 CEO (Chief Executive Officer)</option>
                                <?php 
                                if ($managers && $managers->num_rows > 0) {
                                    $managers->data_seek(0);
                                    while ($manager = $managers->fetch_assoc()): 
                                ?>
                                <option value="<?php echo htmlspecialchars($manager['username']); ?>">
                                    <?php echo htmlspecialchars($manager['full_name']); ?> (<?php echo htmlspecialchars($manager['username']); ?>)
                                </option>
                                <?php 
                                    endwhile; 
                                }
                                ?>
                                <option value="Management">Management Team</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Join Date *</label>
                            <input type="date" name="join_date" id="join_date" class="form-control" value="<?php echo date('Y-m-d'); ?>" required>
                            <small style="color: #718096;">Leave balance will be prorated based on join date</small>
                        </div>
                    </div>
                    
                    <div id="leavePreview" style="display: none; margin-bottom: 20px; padding: 15px; background: #f0fff4; border: 1px solid #9ae6b4; border-radius: 8px;">
                        <h4 style="color: #276749; margin-bottom: 10px;"><i class="icon-calculator"></i> Prorated Leave Balance Preview</h4>
                        <div style="display: grid; grid-template-columns: repeat(2, 1fr); gap: 15px;">
                            <div>
                                <span style="color: #4a5568; font-size: 12px;">Sick Leave</span>
                                <span style="display: block; font-size: 20px; font-weight: bold; color: #2d3748;" id="previewSick">0</span>
                                <span style="color: #718096; font-size: 11px;">of 6 days</span>
                            </div>
                            <div>
                                <span style="color: #4a5568; font-size: 12px;">Casual Leave</span>
                                <span style="display: block; font-size: 20px; font-weight: bold; color: #2d3748;" id="previewCasual">0</span>
                                <span style="color: #718096; font-size: 11px;">of 12 days</span>
                            </div>
                        </div>
                        <div style="margin-top: 10px; font-size: 12px; color: #718096;" id="previewYear"></div>
                    </div>
                    
                    <button type="submit" name="add_user" class="btn" style="background: #006400; color: white;">
                        <i class="icon-user-plus"></i> Add User
                    </button>
                </form>
            </div>

            <!-- Users List -->
            <div class="card">
                <div class="card-header" style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 15px;">
                    <h3 class="card-title"><i class="icon-users"></i> All Users</h3>
                    <div style="display: flex; gap: 15px; flex-wrap: wrap;">
                        <div style="width: 300px;">
                            <input type="text" id="searchUsers" class="form-control" placeholder="Search users by name, username, email, department...">
                        </div>
                        <select id="roleFilter" class="form-control" style="width: 150px;" onchange="filterByRole()">
                            <option value="all">All Roles</option>
                            <option value="employee">Employee</option>
                            <option value="HR">HR</option>
                            <option value="PM">PM</option>
                            <option value="MG">Management</option>
                            <option value="admin">Admin</option>
                        </select>
                    </div>
                </div>
                <div class="table-container">
                    <table>
                        <thead>
                            <tr>
                                <th>Username</th>
                                <th>Full Name</th>
                                <th>Role</th>
                                <th>Reporting To</th>
                                <th>Department</th>
                                <th>Email</th>
                                <th>Join Date</th>
                                <th>Leave Year</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody id="usersTable">
                            <?php if ($users && $users->num_rows > 0): ?>
                                <?php while ($user = $users->fetch_assoc()): 
                                    // Get leave year for join date
                                    $user_leave_year = !empty($user['join_date']) ? getLeaveYearForDate($user['join_date']) : null;
                                ?>
                                <tr data-role="<?php echo $user['role']; ?>">
                                    <td><strong><?php echo htmlspecialchars($user['username']); ?></strong></td>
                                    <td><?php echo htmlspecialchars($user['full_name']); ?></td>
                                    <td>
                                        <span class="user-role-badge role-<?php echo $user['role']; ?>">
                                            <?php 
                                                $role_display = $user['role'];
                                                if ($role_display == 'MG') $role_display = 'MANAGEMENT';
                                                echo strtoupper($role_display); 
                                            ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php 
                                            if ($user['reporting_to'] == 'CEO') {
                                                echo '<span style="background: #fef3c7; padding: 2px 8px; border-radius: 12px; font-weight: bold;">👑 CEO</span>';
                                            } elseif ($user['reporting_manager']) {
                                                echo htmlspecialchars($user['reporting_manager']);
                                            } elseif ($user['reporting_to'] == 'Management') {
                                                echo 'Management Team';
                                            } else {
                                                echo '-';
                                            }
                                        ?>
                                    </td>
                                    <td><?php echo htmlspecialchars($user['department'] ?? '-'); ?></td>
                                    <td><?php echo htmlspecialchars($user['email'] ?? '-'); ?></td>
                                    <td><?php echo $user['join_date'] ?? '-'; ?></td>
                                    <td>
                                        <?php if ($user_leave_year): ?>
                                        <span style="background: #e2e8f0; padding: 3px 8px; border-radius: 12px; font-size: 11px;">
                                            <?php echo $user_leave_year['year_label']; ?>
                                        </span>
                                        <?php else: ?>
                                        -
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div class="action-buttons">
                                            <a href="edit_user.php?id=<?php echo $user['id']; ?>" class="btn-small btn-edit">
                                                <i class="icon-edit"></i> Edit
                                            </a>
                                            <?php if ($user['id'] != $_SESSION['user_id']): ?>
                                                <a href="?delete=<?php echo $user['id']; ?>" 
                                                   class="btn-small btn-delete"
                                                   onclick="return confirm('⚠️ WARNING: Deleting this user will also delete ALL their:\n\n- Leave applications\n- Permission requests\n- Timesheet entries\n- Attendance records\n- Leave balances\n\nAdditionally, this user will be removed as approver from any pending requests.\n\nThis action CANNOT be undone!\n\nAre you absolutely sure you want to delete this user?')">
                                                    <i class="icon-delete"></i> Delete
                                                </a>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="9" style="text-align: center; padding: 40px; color: #718096;">
                                        <i class="icon-users" style="font-size: 48px; margin-bottom: 10px; display: block;"></i>
                                        No users found
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <script>
    // Search functionality
    document.getElementById('searchUsers').addEventListener('input', function() {
        const searchTerm = this.value.toLowerCase();
        const rows = document.querySelectorAll('#usersTable tr');
        
        rows.forEach(row => {
            const text = row.textContent.toLowerCase();
            row.style.display = text.includes(searchTerm) ? '' : 'none';
        });
    });

    // Filter by role
    function filterByRole() {
        const roleFilter = document.getElementById('roleFilter').value;
        const rows = document.querySelectorAll('#usersTable tr');
        
        rows.forEach(row => {
            if (roleFilter === 'all') {
                row.style.display = '';
            } else {
                const userRole = row.getAttribute('data-role');
                row.style.display = userRole === roleFilter ? '' : 'none';
            }
        });
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

    // Preview prorated leave balance based on join date
    document.getElementById('join_date').addEventListener('change', function() {
        const joinDate = this.value;
        if (joinDate) {
            // Calculate prorated leave via AJAX
            fetch('../ajax/preview_leave_balance.php?join_date=' + encodeURIComponent(joinDate))
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        document.getElementById('previewSick').textContent = data.balance.sick;
                        document.getElementById('previewCasual').textContent = data.balance.casual;
                        // Remove Other Leave preview line
                        document.getElementById('previewYear').innerHTML = 
                            '<i class="icon-calendar"></i> Leave Year: ' + data.leave_year;
                        document.getElementById('leavePreview').style.display = 'block';
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                });
        }
    });

    // Form validation
    document.getElementById('addUserForm').addEventListener('submit', function(e) {
        const password = document.getElementById('password').value;
        if (password.length < 6) {
            e.preventDefault();
            alert('Password must be at least 6 characters long');
        }
    });

    // Trigger join date change on page load if value exists
    window.onload = function() {
        const joinDate = document.getElementById('join_date').value;
        if (joinDate) {
            const event = new Event('change');
            document.getElementById('join_date').dispatchEvent(event);
        }
    };
    </script>
    
    <script src="../assets/js/app.js"></script>
</body>
</html>