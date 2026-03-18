<?php
require_once '../config/db.php';
require_once '../includes/leave_functions.php';
require_once '../includes/icon_functions.php'; // ADDED
require_once '../includes/birthday_functions.php'; // ADD THIS NEW FILE
checkRole(['hr', 'admin', 'pm', 'coo', 'ed']);

$message = '';

// Get today's birthday people for celebration
$today_birthdays = getTodaysBirthdays($conn);

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
    $birthday = !empty($_POST['birthday']) ? sanitize($_POST['birthday']) : null;
    $status = 'active'; // New users are active by default
    
    // Check if username exists
    $stmt = $conn->prepare("SELECT id FROM users WHERE username = ?");
    $stmt->bind_param("s", $username);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows > 0) {
        $message = '<div class="alert alert-error"><i class="icon-error"></i> Username already exists</div>';
    } else {
        $stmt = $conn->prepare("
            INSERT INTO users (username, password, role, full_name, email, department, position, reporting_to, join_date, birthday, status, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
        ");
        $stmt->bind_param("sssssssssss", $username, $password, $role, $full_name, $email, $department, $position, $reporting_to, $join_date, $birthday, $status);
        
        if ($stmt->execute()) {
            // Get the new user ID
            $new_user_id = $stmt->insert_id;
            
            // Initialize leave balance for new employee based on join date
            $initial_balance = initializeEmployeeLeaveBalance($conn, $new_user_id, $join_date);
            
            $message = '<div class="alert alert-success">
                <i class="icon-success"></i> User created successfully!<br>
                <strong>Username:</strong> ' . $username . '<br>
                <strong>Full Name:</strong> ' . $full_name . '<br>
                <strong>Birthday:</strong> ' . (!empty($birthday) ? date('d M Y', strtotime($birthday)) : 'Not set') . '<br>
                <strong>Initial Leave Balance for ' . $initial_balance['leave_year'] . ':</strong><br>
                &nbsp;&nbsp;• Sick Leave: ' . $initial_balance['sick'] . ' days (Mar 16 - Mar 15 cycle)<br>
                &nbsp;&nbsp;• Casual Leave: ' . $initial_balance['casual'] . ' days (12 per year, 1 per month, Mar 16 - Mar 15 cycle)<br>
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
    SELECT username, full_name, role 
    FROM users 
    WHERE role IN ('hr', 'pm', 'admin', 'MG', 'coo', 'ed') 
    ORDER BY CASE role WHEN 'pm' THEN 1 WHEN 'ed' THEN 2 WHEN 'hr' THEN 3 WHEN 'coo' THEN 4 ELSE 5 END, full_name
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
        .role-coo, .role-COO {
            background: #e0f2fe;
            color: #075985;
        }
        .role-pm, .role-PM {
            background: #fef3c7;
            color: #92400e;
        }
        .role-ed, .role-ED {
            background: #fde68a;
            color: #78350f;
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
        /* Birthday Celebration Styles */
        .birthday-celebration {
            background: linear-gradient(135deg, #ff6b6b, #ff8787, #ffb8b8);
            color: white;
            padding: 15px 20px;
            border-radius: 10px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 15px;
            flex-wrap: wrap;
            box-shadow: 0 4px 15px rgba(255, 107, 107, 0.3);
            animation: confetti 2s infinite;
            position: relative;
            overflow: hidden;
        }
        
        .birthday-celebration::before {
            content: '🎈 🎉 🎊 🎂 🎁 🎈';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            font-size: 40px;
            opacity: 0.1;
            display: flex;
            align-items: center;
            justify-content: center;
            white-space: nowrap;
            animation: floatBalloons 10s linear infinite;
            pointer-events: none;
        }
        
        .birthday-icon {
            font-size: 48px;
            animation: bounce 1s infinite;
        }
        
        .birthday-message {
            flex: 1;
        }
        
        .birthday-message h3 {
            font-size: 24px;
            margin-bottom: 5px;
            text-shadow: 2px 2px 4px rgba(0,0,0,0.2);
        }
        
        .birthday-names {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
        }
        
        .birthday-name-tag {
            background: rgba(255,255,255,0.2);
            padding: 5px 15px;
            border-radius: 30px;
            font-size: 16px;
            font-weight: 600;
            backdrop-filter: blur(5px);
            border: 1px solid rgba(255,255,255,0.3);
        }
        
        .birthday-badge {
            background: linear-gradient(135deg, #ff6b6b, #ff8787);
            color: white;
            padding: 2px 8px;
            border-radius: 12px;
            font-size: 10px;
            margin-left: 5px;
            display: inline-flex;
            align-items: center;
            gap: 3px;
        }
        
        .birthday-badge i {
            font-size: 10px;
        }
        
        .birthday-column {
            background: #fff9f0;
            position: relative;
        }
        
        .birthday-highlight {
            background: #fff0e6;
        }
        
        .status-badge {
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 600;
            display: inline-block;
        }
        .status-active {
            background: #c6f6d5;
            color: #22543d;
        }
        .status-on_duty {
            background: #bee3f8;
            color: #2c5282;
        }
        .status-inactive {
            background: #fed7d7;
            color: #742a2a;
        }
        
        @keyframes confetti {
            0% { background-position: 0 0; }
            100% { background-position: 100% 100%; }
        }
        
        @keyframes floatBalloons {
            0% { transform: translateX(-100%); }
            100% { transform: translateX(100%); }
        }
        
        @keyframes bounce {
            0%, 100% { transform: translateY(0); }
            50% { transform: translateY(-10px); }
        }
        
        .balloon {
            position: fixed;
            font-size: 30px;
            user-select: none;
            pointer-events: none;
            z-index: 9999;
            animation: floatUp 5s linear forwards;
        }
        
        @keyframes floatUp {
            0% {
                transform: translateY(100vh) rotate(0deg);
                opacity: 0.8;
            }
            100% {
                transform: translateY(-100px) rotate(20deg);
                opacity: 0;
            }
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
            
            <!-- Birthday Celebration Banner -->
            <?php if (!empty($today_birthdays)): ?>
            <div class="birthday-celebration" id="birthdayBanner">
                <div class="birthday-icon">🎂</div>
                <div class="birthday-message">
                    <h3>
                        <?php if (count($today_birthdays) == 1): ?>
                            🎉 Happy Birthday to <?php echo $today_birthdays[0]['full_name']; ?>! 🎉
                        <?php else: ?>
                            🎉 Happy Birthday to our celebration squad! 🎉
                        <?php endif; ?>
                    </h3>
                    <div class="birthday-names">
                        <?php foreach ($today_birthdays as $bday_user): ?>
                            <span class="birthday-name-tag">
                                🎈 <?php echo htmlspecialchars($bday_user['full_name']); ?> 
                                (<?php echo date('Y', strtotime($bday_user['birthday'])); ?>)
                            </span>
                        <?php endforeach; ?>
                    </div>
                    <p style="margin-top: 10px; font-size: 14px; opacity: 0.9;">
                        Join us in wishing them a fantastic day! 🎊
                    </p>
                </div>
                <div class="birthday-icon">🎉</div>
            </div>
            
            <script>
                // Create floating balloons for birthday celebration
                function createBalloon() {
                    const balloon = document.createElement('div');
                    balloon.className = 'balloon';
                    const balloons = ['🎈', '🎉', '🎊', '🎂', '🎁', '✨', '🌟', '💫'];
                    balloon.textContent = balloons[Math.floor(Math.random() * balloons.length)];
                    balloon.style.left = Math.random() * 100 + 'vw';
                    balloon.style.fontSize = (Math.random() * 20 + 20) + 'px';
                    balloon.style.animationDuration = (Math.random() * 3 + 3) + 's';
                    document.body.appendChild(balloon);
                    
                    setTimeout(() => {
                        balloon.remove();
                    }, 6000);
                }
                
                // Create balloons every 300ms for 3 seconds
                if (document.getElementById('birthdayBanner')) {
                    for (let i = 0; i < 20; i++) {
                        setTimeout(() => {
                            createBalloon();
                        }, i * 150);
                    }
                    
                    // Continue creating occasional balloons while banner is visible
                    const balloonInterval = setInterval(() => {
                        if (document.getElementById('birthdayBanner')) {
                            createBalloon();
                        } else {
                            clearInterval(balloonInterval);
                        }
                    }, 800);
                }
            </script>
            <?php endif; ?>
            
            <!-- Leave Year Info -->
            <div class="leave-year-info">
                <div>
                    <i class="icon-calendar"></i> 
                    <strong>Current Leave Year (Mar 16 - Mar 15):</strong> <?php echo $current_leave_year['year_label']; ?>
                </div>
                <div class="leave-badge">
                    <i class="icon-clock"></i> 
                    New employees get prorated leave based on join date (Mar 16 - Mar 15 cycle)
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
                                <option value="coo">COO (Chief Operating Officer)</option>
                                <option value="PM">Project Manager</option>
                                <option value="ed">ED (Executive Director)</option>
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
                                $pm_selected = false;
                                if ($managers && $managers->num_rows > 0) {
                                    $managers->data_seek(0);
                                    while ($manager = $managers->fetch_assoc()): 
                                ?>
                                <option value="<?php echo htmlspecialchars($manager['username']); ?>" 
                                    data-role="<?php echo strtolower($manager['role']); ?>"
                                    <?php echo (strtolower($manager['role']) === 'pm' && !$pm_selected) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($manager['full_name']); ?> (<?php echo htmlspecialchars($manager['username']); ?>)
                                    <?php if (strtolower($manager['role']) === 'pm') { echo ' — Project Manager'; $pm_selected = true; } ?>
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
                            <small style="color: #718096;">Leave balance will be prorated based on join date (Mar 16 - Mar 15 cycle)</small>
                        </div>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label">Birthday</label>
                            <input type="date" name="birthday" id="birthday" class="form-control" 
                                   value="" autocomplete="off"
                                   min="1960-01-01" max="<?php echo date('Y-m-d'); ?>">
                            <small style="color: #718096;">
                                <i class="icon-cake"></i> 
                                Will show celebration on their special day
                            </small>
                        </div>
                    </div>
                    
                    <div id="leavePreview" style="display: none; margin-bottom: 20px; padding: 15px; background: #f0fff4; border: 1px solid #9ae6b4; border-radius: 8px;">
                        <h4 style="color: #276749; margin-bottom: 10px;"><i class="icon-calculator"></i> Prorated Leave Balance Preview (Mar 16 - Mar 15 Cycle)</h4>
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
                            <option value="coo">COO</option>
                            <option value="PM">PM</option>
                            <option value="ed">ED</option>
                            <option value="MG">Management</option>
                            <option value="admin">Admin</option>
                        </select>
                        <select id="statusFilter" class="form-control" style="width: 150px;" onchange="filterByStatus()">
                            <option value="all">All Status</option>
                            <option value="active">Active</option>
                            <option value="inactive">Inactive</option>
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
                                <th>Status</th>
                                <th>Reporting To</th>
                                <th>Department</th>
                                <th>Email</th>
                                <th>Join Date</th>
                                <th>Birthday</th>
                                <th>Leave Year (Mar 16 - Mar 15)</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody id="usersTable">
                            <?php if ($users && $users->num_rows > 0): ?>
                                <?php 
                                $today = date('m-d');
                                while ($user = $users->fetch_assoc()): 
                                    $user_leave_year = !empty($user['join_date']) ? getLeaveYearForDate($user['join_date']) : null;
                                    $is_birthday_today = !empty($user['birthday']) && date('m-d', strtotime($user['birthday'])) == $today;
                                    $status = $user['status'] ?? 'active';
                                ?>
                                <tr data-role="<?php echo $user['role']; ?>" data-status="<?php echo $status; ?>"
                                    class="<?php echo $is_birthday_today ? 'birthday-highlight' : ''; ?>">
                                    <td><strong><?php echo htmlspecialchars($user['username']); ?></strong></td>
                                    <td>
                                        <?php echo htmlspecialchars($user['full_name']); ?>
                                        <?php if ($is_birthday_today): ?>
                                            <span class="birthday-badge" title="Happy Birthday!">
                                                <i class="icon-cake"></i> BIRTHDAY
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php 
                                            $role_display = $user['role'];
                                            $role_css = strtolower($role_display);
                                            if ($role_display == 'MG') { $role_display = 'MANAGEMENT'; $role_css = 'MG'; }
                                            elseif ($role_css == 'coo') $role_display = 'COO';
                                            elseif ($role_css == 'ed') $role_display = 'ED';
                                            else $role_display = strtoupper($role_display);
                                        ?>
                                        <span class="user-role-badge role-<?php echo $role_css; ?>">
                                            <?php echo $role_display; ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php if ($status == 'active'): ?>
                                            <span class="status-badge status-active">Active</span>
                                        <?php elseif ($status == 'on_duty'): ?>
                                            <span class="status-badge status-on_duty">On Duty</span>
                                        <?php else: ?>
                                            <span class="status-badge status-inactive">Inactive</span>
                                        <?php endif; ?>
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
                                    <td class="birthday-column">
                                        <?php if (!empty($user['birthday'])): ?>
                                            <?php echo date('d M', strtotime($user['birthday'])); ?>
                                            <?php 
                                            $age = date('Y') - date('Y', strtotime($user['birthday']));
                                            if (date('md') < date('md', strtotime($user['birthday']))) $age--;
                                            ?>
                                            <span style="color: #718096; font-size: 10px; display: block;">
                                                (<?php echo $age; ?> years)
                                            </span>
                                            <?php if ($is_birthday_today): ?>
                                                <span style="color: #ff6b6b; font-size: 16px;">🎂</span>
                                            <?php endif; ?>
                                        <?php else: ?>
                                            -
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if (!empty($user['join_date'])): ?>
                                            <?php 
                                            $leave_year = getLeaveYearForDate($user['join_date']);
                                            $casual_year = getCasualLeaveYearForDate($user['join_date']);
                                            ?>
                                            <div style="display: flex; flex-direction: column; gap: 3px;">
                                                <span style="background: #e2e8f0; padding: 3px 8px; border-radius: 12px; font-size: 11px;">
                                                    Sick: <?php echo $leave_year['year_label']; ?>
                                                </span>
                                                <span style="background: #c6f6d5; color: #276749; padding: 3px 8px; border-radius: 12px; font-size: 11px;">
                                                    Casual: <?php echo $casual_year['year_label']; ?>
                                                </span>
                                            </div>
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
                                    <td colspan="11" style="text-align: center; padding: 40px; color: #718096;">
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
        const statusFilter = document.getElementById('statusFilter').value;
        const rows = document.querySelectorAll('#usersTable tr');
        
        rows.forEach(row => {
            const userRole = row.getAttribute('data-role');
            const userStatus = row.getAttribute('data-status');
            let roleMatch = roleFilter === 'all' || userRole === roleFilter;
            let statusMatch = statusFilter === 'all' || userStatus === statusFilter;
            
            row.style.display = (roleMatch && statusMatch) ? '' : 'none';
        });
    }

    // Filter by status
    function filterByStatus() {
        filterByRole(); // Reuse the same function
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
                        document.getElementById('previewYear').innerHTML = 
                            '<i class="icon-calendar"></i> Leave Year (Mar 16 - Mar 15): ' + data.leave_year;
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
        // Force birthday field to be empty (prevents browser autofill)
        document.getElementById('birthday').value = '';
        
        // Auto-select first PM in Reporting To dropdown for new users
        var reportingSelect = document.getElementById('reporting_to');
        if (reportingSelect && reportingSelect.value === '') {
            for (var i = 0; i < reportingSelect.options.length; i++) {
                var optText = reportingSelect.options[i].text.toLowerCase();
                var optVal  = reportingSelect.options[i].value.toLowerCase();
                // Match options that belong to a PM user (text contains 'pm' label or data-role)
                if (reportingSelect.options[i].getAttribute('data-role') === 'pm') {
                    reportingSelect.selectedIndex = i;
                    break;
                }
            }
        }
        
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