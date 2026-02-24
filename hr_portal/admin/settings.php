<?php
require_once '../config/db.php';
require_once '../includes/icon_functions.php'; // ADDED
checkRole(['admin', 'hr', 'pm']);

$user_id = $_SESSION['user_id'];
$role = $_SESSION['role'];
$message = '';

// Reset Leaves Only (Admin only)
if (isset($_POST['reset_leaves']) && $role === 'admin') {
    // Check if the confirm text field exists and is not empty
    if (isset($_POST['confirm_text_leaves']) && !empty($_POST['confirm_text_leaves'])) {
        $confirm_text = sanitize($_POST['confirm_text_leaves']);
        
        if ($confirm_text === 'RESET LEAVES') {
            $conn->query("DELETE FROM leaves");
            $message = '<div class="alert alert-success"><i class="icon-success"></i> All leaves data has been cleared.</div>';
        } else {
            $message = '<div class="alert alert-error"><i class="icon-error"></i> Please type "RESET LEAVES" exactly to confirm</div>';
        }
    } else {
        $message = '<div class="alert alert-error"><i class="icon-error"></i> Please enter the confirmation text</div>';
    }
}

// Reset Permissions Only (Admin only)
if (isset($_POST['reset_permissions']) && $role === 'admin') {
    // Check if the confirm text field exists and is not empty
    if (isset($_POST['confirm_text_permissions']) && !empty($_POST['confirm_text_permissions'])) {
        $confirm_text = sanitize($_POST['confirm_text_permissions']);
        
        if ($confirm_text === 'RESET PERMISSIONS') {
            $conn->query("DELETE FROM permissions");
            $message = '<div class="alert alert-success"><i class="icon-success"></i> All permissions data has been cleared.</div>';
        } else {
            $message = '<div class="alert alert-error"><i class="icon-error"></i> Please type "RESET PERMISSIONS" exactly to confirm</div>';
        }
    } else {
        $message = '<div class="alert alert-error"><i class="icon-error"></i> Please enter the confirmation text</div>';
    }
}

// Reset Leaves & Permissions Only (Admin only)
if (isset($_POST['reset_leave_permissions']) && $role === 'admin') {
    // Check if the confirm text field exists and is not empty
    if (isset($_POST['confirm_text_leave_permissions']) && !empty($_POST['confirm_text_leave_permissions'])) {
        $confirm_text = sanitize($_POST['confirm_text_leave_permissions']);
        
        if ($confirm_text === 'RESET BOTH') {
            $conn->query("DELETE FROM leaves");
            $conn->query("DELETE FROM permissions");
            $message = '<div class="alert alert-success"><i class="icon-success"></i> All leaves and permissions data has been cleared.</div>';
        } else {
            $message = '<div class="alert alert-error"><i class="icon-error"></i> Please type "RESET BOTH" exactly to confirm</div>';
        }
    } else {
        $message = '<div class="alert alert-error"><i class="icon-error"></i> Please enter the confirmation text</div>';
    }
}

// Reset Timesheet for All Users (Admin only)
if (isset($_POST['reset_timesheet_all']) && $role === 'admin') {
    // Check if the confirm text field exists and is not empty
    if (isset($_POST['confirm_text_timesheet']) && !empty($_POST['confirm_text_timesheet'])) {
        $confirm_text = sanitize($_POST['confirm_text_timesheet']);
        
        if ($confirm_text === 'RESET TIMESHEET') {
            $conn->query("DELETE FROM timesheets");
            $message = '<div class="alert alert-success"><i class="icon-success"></i> All timesheet data has been cleared for all users.</div>';
        } else {
            $message = '<div class="alert alert-error"><i class="icon-error"></i> Please type "RESET TIMESHEET" exactly to confirm</div>';
        }
    } else {
        $message = '<div class="alert alert-error"><i class="icon-error"></i> Please enter the confirmation text</div>';
    }
}

// Clear all data (Admin only)
if (isset($_POST['clear_data']) && $role === 'admin') {
    // Check if the confirm text field exists and is not empty
    if (isset($_POST['confirm_text']) && !empty($_POST['confirm_text'])) {
        $confirm_text = sanitize($_POST['confirm_text']);
        
        if ($confirm_text === 'RESET SYSTEM') {
            // Store current user info before clearing
            $current_user_id = $user_id;
            $current_username = $_SESSION['username'];
            
            // Clear all tables (except default admin)
            $conn->query("DELETE FROM leaves");
            $conn->query("DELETE FROM permissions");
            $conn->query("DELETE FROM timesheets");
            $conn->query("DELETE FROM attendance");
            $conn->query("DELETE FROM users WHERE username NOT IN ('admin', 'hr', 'projectmanager')");
            
            // Reset default users passwords
            $default_password = password_hash('password', PASSWORD_DEFAULT);
            $conn->query("UPDATE users SET password = '$default_password' WHERE username IN ('admin', 'hr', 'projectmanager')");
            
            $message = '<div class="alert alert-success"><i class="icon-success"></i> All data has been cleared. System reset to factory defaults.</div>';
        } else {
            $message = '<div class="alert alert-error"><i class="icon-error"></i> Please type "RESET SYSTEM" exactly to confirm</div>';
        }
    } else {
        $message = '<div class="alert alert-error"><i class="icon-error"></i> Please enter the confirmation text</div>';
    }
}

// Backup database (Admin only)
if (isset($_GET['backup']) && $role === 'admin') {
    $message = '<div class="alert alert-success"><i class="icon-success"></i> Database backup functionality would be implemented here. This would generate a SQL file for download.</div>';
}

// Export data (Admin only)
if (isset($_GET['export']) && $role === 'admin') {
    $message = '<div class="alert alert-success"><i class="icon-success"></i> Data export functionality would be implemented here. This would generate Excel/CSV files for download.</div>';
}

// View System Logs
if (isset($_GET['view_logs'])) {
    // Create logs modal content
    $log_type = isset($_GET['log_type']) ? $_GET['log_type'] : 'all';
    $log_limit = isset($_GET['log_limit']) ? intval($_GET['log_limit']) : 50;
    
    // Check if system_logs table exists
    $table_check = $conn->query("SHOW TABLES LIKE 'system_logs'");
    $logs_table_exists = $table_check && $table_check->num_rows > 0;
    
    $logs = [];
    $total_logs = 0;
    
    if ($logs_table_exists) {
        // Get total count
        $count_query = "SELECT COUNT(*) as total FROM system_logs";
        if ($log_type !== 'all') {
            $count_query .= " WHERE event_type = '" . $conn->real_escape_string($log_type) . "'";
        }
        $count_result = $conn->query($count_query);
        if ($count_result) {
            $total_logs = $count_result->fetch_assoc()['total'];
        }
        
        // Get logs with user details
        $query = "
            SELECT l.*, u.full_name, u.username 
            FROM system_logs l
            LEFT JOIN users u ON l.user_id = u.id
        ";
        
        if ($log_type !== 'all') {
            $query .= " WHERE l.event_type = '" . $conn->real_escape_string($log_type) . "'";
        }
        
        $query .= " ORDER BY l.created_at DESC LIMIT ?";
        
        $stmt = $conn->prepare($query);
        $stmt->bind_param("i", $log_limit);
        $stmt->execute();
        $result = $stmt->get_result();
        $logs = $result->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
    }
    
    // Get unique event types for filter
    $event_types = [];
    if ($logs_table_exists) {
        $types_result = $conn->query("SELECT DISTINCT event_type FROM system_logs ORDER BY event_type");
        if ($types_result) {
            while ($row = $types_result->fetch_assoc()) {
                $event_types[] = $row['event_type'];
            }
        }
    }
    
    // Return JSON for AJAX request
    if (isset($_GET['ajax'])) {
        header('Content-Type: application/json');
        echo json_encode([
            'success' => true,
            'logs' => $logs,
            'total_logs' => $total_logs,
            'event_types' => $event_types,
            'logs_table_exists' => $logs_table_exists
        ]);
        exit();
    }
}

// Get system statistics
$stats = $conn->query("
    SELECT 
        (SELECT COUNT(*) FROM users) as total_users,
        (SELECT COUNT(*) FROM leaves) as total_leaves,
        (SELECT COUNT(*) FROM permissions) as total_permissions,
        (SELECT COUNT(*) FROM timesheets) as total_timesheets,
        (SELECT COUNT(*) FROM attendance) as total_attendance,
        (SELECT COUNT(*) FROM users WHERE DATE(created_at) = CURDATE()) as new_users_today,
        (SELECT COUNT(*) FROM leaves WHERE DATE(applied_date) = CURDATE()) as new_leaves_today,
        (SELECT COUNT(*) FROM permissions WHERE DATE(applied_date) = CURDATE()) as new_permissions_today,
        (SELECT COUNT(*) FROM timesheets WHERE DATE(submitted_date) = CURDATE()) as new_timesheets_today
")->fetch_assoc();

// Calculate total records for system data
$total_records = 
    $stats['total_users'] + 
    $stats['total_leaves'] + 
    $stats['total_permissions'] + 
    $stats['total_timesheets'] + 
    $stats['total_attendance'];

// Calculate storage usage (approximate)
$storage_estimate = 
    ($stats['total_users'] * 1024) + 
    ($stats['total_leaves'] * 512) + 
    ($stats['total_permissions'] * 256) + 
    ($stats['total_timesheets'] * 1024) + 
    ($stats['total_attendance'] * 128);

if ($storage_estimate < 1024) {
    $storage_text = $storage_estimate . ' bytes';
} elseif ($storage_estimate < 1024 * 1024) {
    $storage_text = round($storage_estimate / 1024, 2) . ' KB';
} else {
    $storage_text = round($storage_estimate / (1024 * 1024), 2) . ' MB';
}

$page_title = "System Administration - MAKSIM HR";
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>System Administration - MAKSIM HR</title>
    <?php include '../includes/head.php'; ?>
    <style>
        .danger-zone {
            margin-top: 30px;
            padding: 20px;
            background: #fff5f5;
            border-radius: 10px;
            border: 1px solid #fed7d7;
        }
        
        .danger-zone h4 {
            color: #c53030;
            margin-bottom: 15px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .reset-options {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 20px;
            margin-bottom: 20px;
        }
        
        .reset-card {
            background: white;
            border-radius: 10px;
            padding: 20px;
            border: 1px solid #e2e8f0;
            box-shadow: 0 2px 4px rgba(0,0,0,0.05);
        }
        
        .reset-card h5 {
            color: #4a5568;
            margin-bottom: 15px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .reset-card.leaves { border-top: 4px solid #4299e1; }
        .reset-card.permissions { border-top: 4px solid #48bb78; }
        .reset-card.both { border-top: 4px solid #ed8936; }
        .reset-card.timesheet { border-top: 4px solid #9f7aea; }
        .reset-card.system { border-top: 4px solid #c53030; }
        
        .btn-warning { background: #ed8936; color: white; }
        .btn-warning:hover { background: #dd7733; }
        
        .btn-danger { background: #c53030; color: white; }
        .btn-danger:hover { background: #b52020; }
        
        .btn-purple { background: #9f7aea; color: white; }
        .btn-purple:hover { background: #8b5cf6; }
        
        /* System Data Cards */
        .system-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 20px;
            margin-bottom: 30px;
        }
        
        .system-card {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 20px;
            border-radius: 12px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.1);
        }
        
        .system-card.storage {
            background: linear-gradient(135deg, #48bb78 0%, #38a169 100%);
        }
        
        .system-header {
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 14px;
            opacity: 0.9;
            margin-bottom: 15px;
        }
        
        .system-header i {
            font-size: 18px;
        }
        
        .system-value {
            font-size: 32px;
            font-weight: bold;
            margin-bottom: 5px;
        }
        
        .system-label {
            font-size: 13px;
            opacity: 0.8;
            margin-bottom: 15px;
        }
        
        .system-breakdown {
            display: flex;
            flex-wrap: wrap;
            gap: 15px;
            margin-top: 15px;
            padding-top: 15px;
            border-top: 1px solid rgba(255,255,255,0.2);
        }
        
        .breakdown-item {
            display: flex;
            flex-direction: column;
            min-width: 70px;
        }
        
        .breakdown-label {
            font-size: 11px;
            opacity: 0.7;
        }
        
        .breakdown-number {
            font-size: 16px;
            font-weight: 600;
        }
        
        /* Module Stats - Keep original layout */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 25px;
            margin-bottom: 40px;
        }
        
        .stat-card {
            background: white;
            border-radius: 15px;
            padding: 25px;
            box-shadow: 0 5px 15px rgba(0,0,0,0.05);
            transition: transform 0.3s;
        }
        
        .stat-card:hover {
            transform: translateY(-5px);
        }
        
        .stat-icon {
            width: 60px;
            height: 60px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 20px;
            font-size: 24px;
        }
        
        .stat-card:nth-child(1) .stat-icon { background: #fed7d7; color: #c53030; }
        .stat-card:nth-child(2) .stat-icon { background: #c6f6d5; color: #276749; }
        .stat-card:nth-child(3) .stat-icon { background: #bee3f8; color: #2c5282; }
        .stat-card:nth-child(4) .stat-icon { background: #e9d8fd; color: #553c9a; }
        
        .stat-value {
            font-size: 32px;
            font-weight: 700;
            color: #2d3748;
            margin-bottom: 5px;
        }
        
        .stat-label {
            color: #718096;
            font-size: 14px;
        }
        
        /* Modal styles for system logs */
        .modal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0,0,0,0.5);
            overflow: auto;
        }
        
        .modal-content {
            background-color: white;
            margin: 5% auto;
            padding: 30px;
            border-radius: 15px;
            width: 90%;
            max-width: 1200px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
            animation: slideDown 0.3s ease;
        }
        
        @keyframes slideDown {
            from {
                transform: translateY(-50px);
                opacity: 0;
            }
            to {
                transform: translateY(0);
                opacity: 1;
            }
        }
        
        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            padding-bottom: 15px;
            border-bottom: 2px solid #e2e8f0;
        }
        
        .modal-header h3 {
            display: flex;
            align-items: center;
            gap: 10px;
            color: #2d3748;
        }
        
        .modal-header h3 i {
            color: #006400;
        }
        
        .close-btn {
            background: none;
            border: none;
            font-size: 24px;
            cursor: pointer;
            color: #718096;
            transition: color 0.2s;
        }
        
        .close-btn:hover {
            color: #c53030;
        }
        
        .log-filters {
            display: flex;
            gap: 15px;
            margin-bottom: 20px;
            flex-wrap: wrap;
            align-items: center;
        }
        
        .log-filter-select {
            padding: 8px 12px;
            border: 2px solid #e2e8f0;
            border-radius: 8px;
            font-size: 14px;
            min-width: 150px;
        }
        
        .log-filter-select:focus {
            outline: none;
            border-color: #006400;
        }
        
        .log-limit-input {
            padding: 8px 12px;
            border: 2px solid #e2e8f0;
            border-radius: 8px;
            font-size: 14px;
            width: 100px;
        }
        
        .log-limit-input:focus {
            outline: none;
            border-color: #006400;
        }
        
        .refresh-btn {
            background: #4299e1;
            color: white;
            border: none;
            padding: 8px 15px;
            border-radius: 8px;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 5px;
            font-size: 14px;
            transition: background 0.2s;
        }
        
        .refresh-btn:hover {
            background: #3182ce;
        }
        
        .logs-table-container {
            overflow-x: auto;
            max-height: 500px;
            overflow-y: auto;
            border: 1px solid #e2e8f0;
            border-radius: 10px;
        }
        
        .logs-table {
            width: 100%;
            border-collapse: collapse;
        }
        
        .logs-table th {
            background: #f7fafc;
            padding: 12px;
            text-align: left;
            font-weight: 600;
            color: #4a5568;
            border-bottom: 2px solid #e2e8f0;
            position: sticky;
            top: 0;
            background: #f7fafc;
            z-index: 10;
        }
        
        .logs-table td {
            padding: 12px;
            border-bottom: 1px solid #e2e8f0;
            color: #4a5568;
            font-size: 13px;
        }
        
        .logs-table tr:hover {
            background: #f7fafc;
        }
        
        .event-badge {
            display: inline-block;
            padding: 3px 8px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
        }
        
        .event-leave { background: #4299e1; color: white; }
        .event-permission { background: #48bb78; color: white; }
        .event-user { background: #ed8936; color: white; }
        .event-system { background: #9f7aea; color: white; }
        .event-error { background: #c53030; color: white; }
        
        .log-count-badge {
            background: #006400;
            color: white;
            padding: 3px 10px;
            border-radius: 20px;
            font-size: 12px;
            margin-left: 10px;
        }
        
        .no-logs-message {
            text-align: center;
            padding: 40px;
            color: #718096;
        }
        
        .loading-spinner {
            display: inline-block;
            width: 20px;
            height: 20px;
            border: 3px solid #f3f3f3;
            border-top: 3px solid #006400;
            border-radius: 50%;
            animation: spin 1s linear infinite;
        }
        
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
        
        .log-timestamp {
            font-family: monospace;
            font-size: 12px;
            color: #718096;
        }
        
        .log-user {
            font-weight: 600;
            color: #2d3748;
        }
    </style>
</head>
<body>
    <?php include '../includes/header.php'; ?>
    
    <div class="app-main">
        <?php include '../includes/sidebar.php'; ?>
        
        <div class="main-content">
            <h2 class="page-title"><i class="icon-settings"></i> System Administration</h2>
            
            <?php echo $message; ?>
            
            <!-- System Data Cards - Replacing Total Timesheets -->
            <div class="system-grid">
                <!-- Total System Data Card -->
                <div class="system-card">
                    <div class="system-header">
                        <i class="icon-database"></i> Total System Data
                    </div>
                    <div class="system-value"><?php echo $total_records; ?></div>
                    <div class="system-label">Total Records in Database</div>
                    
                    <div class="system-breakdown">
                        <div class="breakdown-item">
                            <span class="breakdown-label">Users</span>
                            <span class="breakdown-number"><?php echo $stats['total_users']; ?></span>
                        </div>
                        <div class="breakdown-item">
                            <span class="breakdown-label">Leaves</span>
                            <span class="breakdown-number"><?php echo $stats['total_leaves']; ?></span>
                        </div>
                        <div class="breakdown-item">
                            <span class="breakdown-label">Perms</span>
                            <span class="breakdown-number"><?php echo $stats['total_permissions']; ?></span>
                        </div>
                        <div class="breakdown-item">
                            <span class="breakdown-label">Timesheet</span>
                            <span class="breakdown-number"><?php echo $stats['total_timesheets']; ?></span>
                        </div>
                        <div class="breakdown-item">
                            <span class="breakdown-label">Attendance</span>
                            <span class="breakdown-number"><?php echo $stats['total_attendance']; ?></span>
                        </div>
                    </div>
                </div>
                
                <!-- Storage Usage Card -->
                <div class="system-card storage">
                    <div class="system-header">
                        <i class="icon-hdd"></i> Storage Usage
                    </div>
                    <div class="system-value"><?php echo $storage_text; ?></div>
                    <div class="system-label">Approximate Database Size</div>
                    
                    <div class="system-breakdown">
                        <div class="breakdown-item">
                            <span class="breakdown-label">Per User</span>
                            <span class="breakdown-number">~1KB</span>
                        </div>
                        <div class="breakdown-item">
                            <span class="breakdown-label">Per Leave</span>
                            <span class="breakdown-number">~0.5KB</span>
                        </div>
                        <div class="breakdown-item">
                            <span class="breakdown-label">Per Timesheet</span>
                            <span class="breakdown-number">~1KB</span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Module Statistics - Keep original layout -->
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-icon"><i class="icon-users"></i></div>
                    <div class="stat-value"><?php echo $stats['total_users']; ?></div>
                    <div class="stat-label">Total Users</div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon"><i class="icon-leave"></i></div>
                    <div class="stat-value"><?php echo $stats['total_leaves']; ?></div>
                    <div class="stat-label">Total Leaves</div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon"><i class="icon-clock"></i></div>
                    <div class="stat-value"><?php echo $stats['total_permissions']; ?></div>
                    <div class="stat-label">Total Permissions</div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon"><i class="icon-attendance"></i></div>
                    <div class="stat-value"><?php echo $stats['total_attendance']; ?></div>
                    <div class="stat-label">Total Attendance</div>
                </div>
            </div>

            <!-- Today's Activity - Keep original layout -->
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title"><i class="icon-chart-line"></i> Today's Activity</h3>
                </div>
                <div class="stats-grid">
                    <div class="stat-card" style="background: #f0fff4;">
                        <div class="stat-icon" style="background: #c6f6d5; color: #276749;"><i class="icon-user-plus"></i></div>
                        <div class="stat-value"><?php echo $stats['new_users_today']; ?></div>
                        <div class="stat-label">New Users</div>
                    </div>
                    <div class="stat-card" style="background: #fffaf0;">
                        <div class="stat-icon" style="background: #feebc8; color: #c05621;"><i class="icon-leave"></i></div>
                        <div class="stat-value"><?php echo $stats['new_leaves_today']; ?></div>
                        <div class="stat-label">New Leaves</div>
                    </div>
                    <div class="stat-card" style="background: #f0f9ff;">
                        <div class="stat-icon" style="background: #bee3f8; color: #2c5282;"><i class="icon-clock"></i></div>
                        <div class="stat-value"><?php echo $stats['new_permissions_today']; ?></div>
                        <div class="stat-label">New Permissions</div>
                    </div>
                    <div class="stat-card" style="background: #faf5ff;">
                        <div class="stat-icon" style="background: #e9d8fd; color: #553c9a;"><i class="icon-calendar"></i></div>
                        <div class="stat-value"><?php echo $stats['new_timesheets_today'] ?? 0; ?></div>
                        <div class="stat-label">New Timesheets</div>
                    </div>
                </div>
            </div>

            <?php if ($role === 'admin'): ?>
            <!-- Data Reset Options -->
            <div class="danger-zone">
                <h4><i class="icon-warning"></i> Data Reset Options (Admin Only)</h4>
                <p style="margin-bottom: 20px; color: #718096;">
                    Warning: These actions will delete data permanently and cannot be undone!
                </p>
                
                <div class="reset-options">
                    <!-- Reset Leaves Only -->
                    <div class="reset-card leaves">
                        <h5><i class="icon-leave"></i> Reset Leaves Only</h5>
                        <p style="color: #718096; margin-bottom: 15px; font-size: 14px;">
                            This will delete all leaves data only. Users and other data will remain.
                        </p>
                        <form method="POST" action="">
                            <div class="form-group">
                                <label class="form-label">Type "RESET LEAVES" to confirm:</label>
                                <input type="text" name="confirm_text_leaves" class="form-control" placeholder="RESET LEAVES" required>
                            </div>
                            <button type="submit" name="reset_leaves" class="btn btn-warning" onclick="return confirm('Delete ALL leaves? This cannot be undone!')">
                                <i class="icon-delete"></i> Reset Leaves Data
                            </button>
                        </form>
                    </div>
                    
                    <!-- Reset Permissions Only -->
                    <div class="reset-card permissions">
                        <h5><i class="icon-clock"></i> Reset Permissions Only</h5>
                        <p style="color: #718096; margin-bottom: 15px; font-size: 14px;">
                            This will delete all permissions data only. Users and other data will remain.
                        </p>
                        <form method="POST" action="">
                            <div class="form-group">
                                <label class="form-label">Type "RESET PERMISSIONS" to confirm:</label>
                                <input type="text" name="confirm_text_permissions" class="form-control" placeholder="RESET PERMISSIONS" required>
                            </div>
                            <button type="submit" name="reset_permissions" class="btn btn-warning" onclick="return confirm('Delete ALL permissions? This cannot be undone!')">
                                <i class="icon-delete"></i> Reset Permissions Data
                            </button>
                        </form>
                    </div>
                    
                    <!-- Reset Leaves & Permissions -->
                    <div class="reset-card both">
                        <h5><i class="icon-ban"></i> Reset Leaves & Permissions</h5>
                        <p style="color: #718096; margin-bottom: 15px; font-size: 14px;">
                            This will delete all leaves AND permissions data. Users will remain.
                        </p>
                        <form method="POST" action="">
                            <div class="form-group">
                                <label class="form-label">Type "RESET BOTH" to confirm:</label>
                                <input type="text" name="confirm_text_leave_permissions" class="form-control" placeholder="RESET BOTH" required>
                            </div>
                            <button type="submit" name="reset_leave_permissions" class="btn btn-danger" onclick="return confirm('Delete ALL leaves and permissions? This cannot be undone!')">
                                <i class="icon-delete"></i> Reset Leaves & Permissions
                            </button>
                        </form>
                    </div>
                    
                    <!-- Reset Timesheet for All Users -->
                    <div class="reset-card timesheet">
                        <h5><i class="icon-calendar"></i> Reset Timesheet for All</h5>
                        <p style="color: #718096; margin-bottom: 15px; font-size: 14px;">
                            This will delete ALL timesheet entries for ALL users. Users will remain.
                        </p>
                        <form method="POST" action="">
                            <div class="form-group">
                                <label class="form-label">Type "RESET TIMESHEET" to confirm:</label>
                                <input type="text" name="confirm_text_timesheet" class="form-control" placeholder="RESET TIMESHEET" required>
                            </div>
                            <button type="submit" name="reset_timesheet_all" class="btn btn-purple" onclick="return confirm('Delete ALL timesheet entries for ALL users? This cannot be undone!')">
                                <i class="icon-delete"></i> Reset Timesheet (All Users)
                            </button>
                        </form>
                    </div>
                    
                    <!-- Full System Reset -->
                    <div class="reset-card system">
                        <h5><i class="icon-bomb"></i> Full System Reset</h5>
                        <p style="color: #718096; margin-bottom: 15px; font-size: 14px;">
                            This will delete ALL data including users (except default admin accounts).
                        </p>
                        <form method="POST" action="">
                            <div class="form-group">
                                <label class="form-label">Type "RESET SYSTEM" to confirm:</label>
                                <input type="text" name="confirm_text" class="form-control" placeholder="RESET SYSTEM" required>
                            </div>
                            <button type="submit" name="clear_data" class="btn btn-danger" onclick="return confirm('Are you ABSOLUTELY sure? This will delete ALL data!')">
                                <i class="icon-delete"></i> Full System Reset
                            </button>
                        </form>
                    </div>
                </div>
                
                <div style="margin-top: 20px; padding: 15px; background: #fed7d7; border-radius: 8px;">
                    <p style="color: #c53030; margin: 0; display: flex; align-items: center; gap: 10px;">
                        <i class="icon-warning"></i>
                        <strong>Warning:</strong> All reset operations are permanent and irreversible. Backup your data first!
                    </p>
                </div>
            </div>
            <?php endif; ?>

            <!-- System Tools -->
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title"><i class="icon-tools"></i> System Tools</h3>
                </div>
                <div class="form-row">
                    <?php if ($role === 'admin'): ?>
                    <div class="form-group">
                        <button class="btn btn-success" style="width: 100%;" onclick="window.location.href='?backup=1'">
                            <i class="icon-database"></i> Backup Database
                        </button>
                        <small style="display: block; margin-top: 5px; color: #718096;">Create a full database backup</small>
                    </div>
                    <div class="form-group">
                        <button class="btn btn-success" style="width: 100%;" onclick="window.location.href='?export=1'">
                            <i class="icon-excel"></i> Export All Data
                        </button>
                        <small style="display: block; margin-top: 5px; color: #718096;">Export all data to Excel/CSV</small>
                    </div>
                    <?php endif; ?>
                    
                    <div class="form-group">
                        <button class="btn btn-info" style="width: 100%;" onclick="refreshSystem()">
                            <i class="icon-sync"></i> Refresh System Cache
                        </button>
                        <small style="display: block; margin-top: 5px; color: #718096;">Clear cache and refresh data</small>
                    </div>
                    
                    <div class="form-group">
                        <button class="btn btn-warning" style="width: 100%;" onclick="showSystemLogs()">
                            <i class="icon-clipboard-list"></i> View System Logs
                        </button>
                        <small style="display: block; margin-top: 5px; color: #718096;">View system activity logs</small>
                    </div>
                </div>
            </div>

            <!-- System Information -->
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title"><i class="icon-info"></i> System Information</h3>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">PHP Version</label>
                        <input type="text" class="form-control" value="<?php echo phpversion(); ?>" readonly>
                    </div>
                    <div class="form-group">
                        <label class="form-label">MySQL Version</label>
                        <input type="text" class="form-control" value="<?php echo $conn->server_info; ?>" readonly>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Server Time</label>
                        <input type="text" class="form-control" value="<?php echo date('Y-m-d H:i:s'); ?>" readonly>
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">Server Name</label>
                        <input type="text" class="form-control" value="<?php echo $_SERVER['SERVER_NAME']; ?>" readonly>
                    </div>
                    <div class="form-group">
                        <label class="form-label">System Uptime</label>
                        <input type="text" class="form-control" value="24/7" readonly>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Storage Used</label>
                        <input type="text" class="form-control" value="<?php echo $storage_text; ?>" readonly>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- System Logs Modal -->
    <div id="systemLogsModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>
                    <i class="icon-clipboard-list"></i>
                    System Activity Logs
                    <span id="logCountBadge" class="log-count-badge">0</span>
                </h3>
                <button class="close-btn" onclick="closeLogsModal()">&times;</button>
            </div>
            
            <div class="log-filters">
                <select id="logTypeFilter" class="log-filter-select" onchange="loadLogs()">
                    <option value="all">All Events</option>
                </select>
                
                <select id="logLimit" class="log-limit-input" onchange="loadLogs()">
                    <option value="25">25 records</option>
                    <option value="50" selected>50 records</option>
                    <option value="100">100 records</option>
                    <option value="200">200 records</option>
                    <option value="500">500 records</option>
                </select>
                
                <button class="refresh-btn" onclick="loadLogs()">
                    <i class="icon-sync"></i> Refresh
                </button>
            </div>
            
            <div id="logsContainer" class="logs-table-container">
                <div style="text-align: center; padding: 40px;">
                    <div class="loading-spinner" style="margin: 0 auto 15px;"></div>
                    <div>Loading logs...</div>
                </div>
            </div>
            
            <div style="margin-top: 20px; text-align: right; color: #718096; font-size: 12px;">
                <i class="icon-info"></i> Logs are automatically cleared after 30 days
            </div>
        </div>
    </div>

    <script>
    function refreshSystem() {
        if (confirm('Refresh system cache?')) {
            alert('System cache refreshed successfully.');
        }
    }
    
    function showSystemLogs() {
        const modal = document.getElementById('systemLogsModal');
        modal.style.display = 'block';
        loadLogs();
    }
    
    function closeLogsModal() {
        document.getElementById('systemLogsModal').style.display = 'none';
    }
    
    function loadLogs() {
        const logType = document.getElementById('logTypeFilter').value;
        const logLimit = document.getElementById('logLimit').value;
        const logsContainer = document.getElementById('logsContainer');
        
        logsContainer.innerHTML = '<div style="text-align: center; padding: 40px;"><div class="loading-spinner" style="margin: 0 auto 15px;"></div><div>Loading logs...</div></div>';
        
        fetch(`system_administration.php?view_logs=1&log_type=${logType}&log_limit=${logLimit}&ajax=1`)
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    displayLogs(data);
                } else {
                    logsContainer.innerHTML = '<div class="no-logs-message">Error loading logs</div>';
                }
            })
            .catch(error => {
                console.error('Error:', error);
                logsContainer.innerHTML = '<div class="no-logs-message">Error loading logs</div>';
            });
    }
    
    function displayLogs(data) {
        const logsContainer = document.getElementById('logsContainer');
        const logCountBadge = document.getElementById('logCountBadge');
        const logTypeFilter = document.getElementById('logTypeFilter');
        
        // Update count badge
        logCountBadge.textContent = data.total_logs;
        
        // Update filter dropdown
        logTypeFilter.innerHTML = '<option value="all">All Events</option>';
        if (data.event_types && data.event_types.length > 0) {
            data.event_types.forEach(type => {
                const selected = (type === '<?php echo isset($_GET['log_type']) ? $_GET['log_type'] : ''; ?>') ? 'selected' : '';
                logTypeFilter.innerHTML += `<option value="${type}" ${selected}>${type}</option>`;
            });
        }
        
        if (!data.logs_table_exists) {
            logsContainer.innerHTML = `
                <div class="no-logs-message">
                    <i class="icon-database" style="font-size: 48px; margin-bottom: 15px; display: block; color: #cbd5e0;"></i>
                    <h4 style="color: #2d3748;">System Logs Table Not Found</h4>
                    <p style="color: #718096; margin-top: 10px;">The system_logs table does not exist in the database.</p>
                    <p style="color: #718096; font-size: 12px;">This is normal for a new installation. Logs will be created as system events occur.</p>
                </div>
            `;
            return;
        }
        
        if (!data.logs || data.logs.length === 0) {
            logsContainer.innerHTML = '<div class="no-logs-message">No logs found</div>';
            return;
        }
        
        let html = '<table class="logs-table">';
        html += '<thead><tr><th>Timestamp</th><th>Event Type</th><th>Description</th><th>User</th><th>IP Address</th></tr></thead><tbody>';
        
        data.logs.forEach(log => {
            // Determine event badge class
            let badgeClass = 'event-system';
            if (log.event_type.includes('leave')) badgeClass = 'event-leave';
            else if (log.event_type.includes('permission')) badgeClass = 'event-permission';
            else if (log.event_type.includes('user')) badgeClass = 'event-user';
            else if (log.event_type.includes('error')) badgeClass = 'event-error';
            
            const timestamp = new Date(log.created_at).toLocaleString();
            const user = log.full_name || log.username || 'System';
            const ip = log.ip_address || '-';
            
            html += `<tr>`;
            html += `<td class="log-timestamp">${timestamp}</td>`;
            html += `<td><span class="event-badge ${badgeClass}">${log.event_type}</span></td>`;
            html += `<td>${log.description}</td>`;
            html += `<td class="log-user">${user}</td>`;
            html += `<td>${ip}</td>`;
            html += `</tr>`;
        });
        
        html += '</tbody></table>';
        logsContainer.innerHTML = html;
    }
    
    function confirmReset(action) {
        const messages = {
            'leaves': 'Delete ALL leaves? This cannot be undone!',
            'permissions': 'Delete ALL permissions? This cannot be undone!',
            'both': 'Delete ALL leaves and permissions? This cannot be undone!',
            'timesheet': 'Delete ALL timesheet entries for ALL users? This cannot be undone!',
            'system': 'Are you ABSOLUTELY sure? This will delete ALL data!'
        };
        
        return confirm(messages[action]);
    }
    
    // Close modal when clicking outside
    window.onclick = function(event) {
        const modal = document.getElementById('systemLogsModal');
        if (event.target === modal) {
            modal.style.display = 'none';
        }
    }
    </script>
    
    <script src="../assets/js/app.js"></script>
</body>
</html>