<?php
require_once '../config/db.php';
require_once '../includes/leave_functions.php';
require_once '../includes/icon_functions.php';
require_once '../includes/notification_functions.php'; // ADD THIS LINE

if (!isLoggedIn()) {
    header('Location: ../auth/login.php');
    exit();
}

$user_id = $_SESSION['user_id'];
$role = $_SESSION['role'];
$message = '';

// Pick up session message from redirect (cancel actions)
if (isset($_SESSION['perm_message'])) {
    $message = $_SESSION['perm_message'];
    unset($_SESSION['perm_message']);
}

// Check if time columns exist and add them if they don't
$check_columns = $conn->query("SHOW COLUMNS FROM permissions LIKE 'time_from'");
if ($check_columns->num_rows == 0) {
    $conn->query("ALTER TABLE permissions ADD COLUMN time_from TIME DEFAULT NULL");
}
$check_columns = $conn->query("SHOW COLUMNS FROM permissions LIKE 'time_to'");
if ($check_columns->num_rows == 0) {
    $conn->query("ALTER TABLE permissions ADD COLUMN time_to TIME DEFAULT NULL");
}

// Function to get total permission hours used in a specific window (16th-15th)
function getPermissionHoursInWindow($conn, $user_id, $window_start, $window_end) {
    $stmt = $conn->prepare("
        SELECT COALESCE(SUM(duration), 0) as total_hours 
        FROM permissions 
        WHERE user_id = ? 
        AND status IN ('Approved', 'Pending', 'LOP')
        AND permission_date BETWEEN ? AND ?
    ");
    $stmt->bind_param("iss", $user_id, $window_start, $window_end);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    $stmt->close();
    
    return floatval($row['total_hours']);
}

// Function to calculate hours between two times
function calculateHoursBetween($time_from, $time_to) {
    if (empty($time_from) || empty($time_to)) {
        return 0;
    }
    
    $from = new DateTime($time_from);
    $to = new DateTime($time_to);
    
    // If end time is less than start time, assume it's next day
    if ($to < $from) {
        $to->modify('+1 day');
    }
    
    $interval = $from->diff($to);
    $hours = $interval->h + ($interval->i / 60);
    
    return round($hours, 2);
}

// Apply permission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['apply_permission'])) {
    
    $permission_date = sanitize($_POST['permission_date']);
    $reason = sanitize($_POST['reason']);
    $time_from = isset($_POST['time_from']) && !empty($_POST['time_from']) ? sanitize($_POST['time_from']) : null;
    $time_to = isset($_POST['time_to']) && !empty($_POST['time_to']) ? sanitize($_POST['time_to']) : null;
    
    // Calculate duration from time range
    if ($time_from && $time_to) {
        $total_duration = calculateHoursBetween($time_from, $time_to);
    } else {
        $total_duration = 0;
    }
    
    // Check if the date is valid
    $date_timestamp = strtotime($permission_date);
    if ($date_timestamp === false) {
        $message = '<div class="alert alert-error"><i class="icon-error"></i> Invalid date format. Please use YYYY-MM-DD format</div>';
    } else {
        $formatted_date = date('Y-m-d', $date_timestamp);
        
        // Validate that we have a positive duration
        if ($total_duration <= 0) {
            $message = '<div class="alert alert-error"><i class="icon-error"></i> Please specify a valid time range</div>';
        } else {
            // Check for existing permission on same date
            $stmt = $conn->prepare("
                SELECT COUNT(*) as count 
                FROM permissions 
                WHERE user_id = ? 
                AND permission_date = ? 
                AND status IN ('Pending', 'Approved', 'LOP')
            ");
            $stmt->bind_param("is", $user_id, $formatted_date);
            $stmt->execute();
            $result = $stmt->get_result();
            $row = $result->fetch_assoc();
            $stmt->close();
            
            if ($row['count'] > 0) {
                $message = '<div class="alert alert-error"><i class="icon-error"></i> You already have a permission request for this date</div>';
            } else {
                // Get the window for the selected date using the function from leave_functions.php
                $window = getWindowForDate($formatted_date);
                $window_start = $window['window_start'];
                $window_end = $window['window_end'];
                
                // Check monthly permission limit (4 hours max per window 16th-15th)
                $used_hours = getPermissionHoursInWindow($conn, $user_id, $window_start, $window_end);
                $remaining_hours = 4 - $used_hours;
                
                // Determine how many hours can be permission vs LOP
                $permission_hours = min($total_duration, $remaining_hours);
                $lop_hours = $total_duration - $permission_hours;
                
                // Start transaction
                $conn->begin_transaction();
                
                try {
                    $permission_ids = [];
                    
                    // Insert permission for allowed hours - with PENDING status
                    if ($permission_hours > 0) {
                        // Ensure time values are properly handled
                        $time_from_val = $time_from ? $time_from : null;
                        $time_to_val = $time_to ? $time_to : null;
                        
                        $stmt1 = $conn->prepare("
                            INSERT INTO permissions (user_id, permission_date, duration, reason, status, time_from, time_to)
                            VALUES (?, ?, ?, ?, 'Pending', ?, ?)
                        ");
                        
                        // If this is partial, modify reason
                        if ($lop_hours > 0) {
                            $permission_reason = $reason . " (Partial - " . $permission_hours . " hrs within 4hr limit)";
                        } else {
                            $permission_reason = $reason;
                        }
                        
                        $stmt1->bind_param("isssss", 
                            $user_id, 
                            $formatted_date, 
                            $permission_hours, 
                            $permission_reason, 
                            $time_from_val, 
                            $time_to_val
                        );
                        $stmt1->execute();
                        $permission_ids[] = $stmt1->insert_id;
                        $stmt1->close();
                    }
                    
                    // Insert LOP for excess hours - stored in permissions table
                    if ($lop_hours > 0) {
                        $lop_reason = $reason . " (Excess " . $lop_hours . "hr - LOP, 4hr monthly limit exceeded)";

                        // Ensure LOP is a valid status (handle ENUM or VARCHAR)
                        $col_info = $conn->query("SHOW COLUMNS FROM permissions LIKE 'status'");
                        if ($col_info && $col_row = $col_info->fetch_assoc()) {
                            if (strpos($col_row['Type'], 'enum') !== false && strpos($col_row['Type'], "'LOP'") === false) {
                                // Add LOP to the enum
                                $new_type = str_replace(")", ",'LOP')", $col_row['Type']);
                                $conn->query("ALTER TABLE permissions MODIFY COLUMN status $new_type NOT NULL DEFAULT 'Pending'");
                            }
                        }

                        // Ensure time values are properly handled
                        $time_from_val = $time_from ? $time_from : null;
                        $time_to_val = $time_to ? $time_to : null;
                        
                        $stmt2 = $conn->prepare("
                            INSERT INTO permissions (user_id, permission_date, duration, reason, status, time_from, time_to)
                            VALUES (?, ?, ?, ?, 'LOP', ?, ?)
                        ");
                        $stmt2->bind_param("isssss", 
                            $user_id, 
                            $formatted_date, 
                            $lop_hours, 
                            $lop_reason, 
                            $time_from_val, 
                            $time_to_val
                        );
                        $stmt2->execute();
                        $permission_ids[] = $stmt2->insert_id;
                        $stmt2->close();
                    }
                    
                    $conn->commit();
                    
                    // Get user info for notification
                    $user_stmt = $conn->prepare("SELECT full_name FROM users WHERE id = ?");
                    $user_stmt->bind_param("i", $user_id);
                    $user_stmt->execute();
                    $user_result = $user_stmt->get_result();
                    $user_data = $user_result->fetch_assoc();
                    $user_stmt->close();
                    
                    // Get reporting manager ID
                    $manager_id = null;
                    $manager_query = $conn->prepare("SELECT reporting_to FROM users WHERE id = ?");
                    $manager_query->bind_param("i", $user_id);
                    $manager_query->execute();
                    $manager_result = $manager_query->get_result();
                    $manager_row = $manager_result->fetch_assoc();
                    if ($manager_row && !empty($manager_row['reporting_to'])) {
                        $manager_user_query = $conn->prepare("SELECT id FROM users WHERE username = ?");
                        $manager_user_query->bind_param("s", $manager_row['reporting_to']);
                        $manager_user_query->execute();
                        $manager_user_result = $manager_user_query->get_result();
                        $manager_user = $manager_user_result->fetch_assoc();
                        if ($manager_user) {
                            $manager_id = $manager_user['id'];
                        }
                        $manager_user_query->close();
                    }
                    $manager_query->close();
                    
                    // Format duration for notification
                    $duration_text = "";
                    if ($total_duration == 1) $duration_text = "1 hour";
                    elseif ($total_duration < 1) $duration_text = ($total_duration * 60) . " minutes";
                    elseif ($total_duration == 8) $duration_text = "full day";
                    else $duration_text = $total_duration . " hours";
                    
                    // NOTIFY THE EMPLOYEE (confirmation)
                    $user_title = "Permission Request Submitted";
                    $user_msg = "Your permission request for {$duration_text} on {$formatted_date} has been submitted and is pending approval.";
                    createNotification($conn, $user_id, 'permission_submitted', $user_title, $user_msg, $permission_ids[0] ?? 0);
                    
                    // NOTIFY REPORTING MANAGER
                    if ($manager_id) {
                        $manager_title = "New Permission Request";
                        $manager_msg = "{$user_data['full_name']} has submitted a permission request for {$duration_text} on {$formatted_date}.";
                        createNotification($conn, $manager_id, 'new_permission', $manager_title, $manager_msg, $permission_ids[0] ?? 0);
                    }
                    
                    // NOTIFY ALL HR/ADMIN/dm
                    $mgmt_users = $conn->query("SELECT id FROM users WHERE role IN ('hr', 'admin', 'dm', 'coo', 'ed') AND id != $user_id");
                    while ($mgmt_user = $mgmt_users->fetch_assoc()) {
                        $mgmt_title = "New Permission Request";
                        $mgmt_msg = "{$user_data['full_name']} has submitted a permission request for {$duration_text} on {$formatted_date}.";
                        createNotification($conn, $mgmt_user['id'], 'new_permission', $mgmt_title, $mgmt_msg, $permission_ids[0] ?? 0);
                    }
                    
                    // Format success message
                    if ($permission_hours > 0 && $lop_hours > 0) {
                        $message = '<div class="alert alert-warning" style="background: #fff5f5; border-left-color: #c53030;">
                            <i class="icon-warning"></i> 
                            <strong>Partial Submission with LOP!</strong><br>
                            You have used ' . $used_hours . ' of 4 monthly permission hours.<br>
                            <strong>' . $permission_hours . ' hour(s)</strong> submitted as permission (pending approval).<br>
                            <strong>' . $lop_hours . ' hour(s)</strong> converted to LOP (Loss of Pay) as unpaid leave.<br>
                            <span style="color: #006400;">✓ Notification sent to manager and administrators.</span>
                        </div>';
                    } else if ($permission_hours > 0) {
                        $hours_display = $permission_hours == 1 ? '1 hour' : ($permission_hours . ' hours');
                        $from = date('g:i A', strtotime($time_from));
                        $to = date('g:i A', strtotime($time_to));
                        $message = '<div class="alert alert-success"><i class="icon-success"></i> Permission request submitted successfully! (' . $hours_display . ' from ' . $from . ' to ' . $to . ') - Pending approval.<br><span style="color: #006400;">✓ Notification sent to manager and administrators.</span></div>';
                    } else {
                        $message = '<div class="alert alert-warning" style="background: #fff5f5; border-left-color: #c53030;">
                            <i class="icon-warning"></i> 
                            <strong>Fully Converted to LOP!</strong><br>
                            You have used all 4 monthly permission hours.<br>
                            All ' . $total_duration . ' hours converted to LOP (Loss of Pay) as unpaid leave.<br>
                            <span style="color: #006400;">✓ Notification sent to manager and administrators.</span>
                        </div>';
                    }
                    
                } catch (Exception $e) {
                    $conn->rollback();
                    $message = '<div class="alert alert-error"><i class="icon-error"></i> Error processing request: ' . $e->getMessage() . '</div>';
                }
            }
        }
    }
}

// Cancel permission (for regular pending permissions)
if (isset($_GET['cancel'])) {
    $permission_id = intval($_GET['cancel']);
    
    // Get permission details first
    $get_perm = $conn->prepare("SELECT permission_date, duration, status FROM permissions WHERE id = ? AND user_id = ?");
    $get_perm->bind_param("ii", $permission_id, $user_id);
    $get_perm->execute();
    $perm_result = $get_perm->get_result();
    $perm_data = $perm_result->fetch_assoc();
    $get_perm->close();
    
    if ($perm_data) {
        $duration = $perm_data['duration'];
        $duration_text = "";
        if ($duration == 1) $duration_text = "1 hour";
        elseif ($duration < 1) $duration_text = ($duration * 60) . " minutes";
        elseif ($duration == 8) $duration_text = "full day";
        else $duration_text = $duration . " hours";
        
        $stmt = $conn->prepare("DELETE FROM permissions WHERE id = ? AND user_id = ? AND status = 'Pending'");
        $stmt->bind_param("ii", $permission_id, $user_id);
        
        if ($stmt->execute()) {
            // Get user info for notification
            $user_stmt = $conn->prepare("SELECT full_name FROM users WHERE id = ?");
            $user_stmt->bind_param("i", $user_id);
            $user_stmt->execute();
            $user_result = $user_stmt->get_result();
            $user_data = $user_result->fetch_assoc();
            $user_stmt->close();
            
            // NOTIFY HR, ADMIN, AND dm that permission was cancelled
            $notify_users = $conn->query("SELECT id FROM users WHERE role IN ('hr', 'admin', 'dm', 'coo', 'ed')");
            while ($notify_user = $notify_users->fetch_assoc()) {
                $title = "Permission Request Cancelled";
                $notification_msg = "{$user_data['full_name']} has cancelled their permission request for {$duration_text} on {$perm_data['permission_date']}.";
                createNotification($conn, $notify_user['id'], 'permission_cancelled', $title, $notification_msg, $permission_id);
            }
            
            // NOTIFY THE EMPLOYEE (confirmation)
            $user_title = "Permission Cancelled";
            $user_msg = "You have cancelled your permission request for {$duration_text} on {$perm_data['permission_date']}.";
            createNotification($conn, $user_id, 'permission_cancelled', $user_title, $user_msg, $permission_id);
            
            $_SESSION['perm_message'] = '<div class="alert alert-success"><i class="icon-success"></i> Permission request cancelled successfully</div>';
        } else {
            $_SESSION['perm_message'] = '<div class="alert alert-error"><i class="icon-error"></i> Error cancelling permission request</div>';
        }
        $stmt->close();
    } else {
        $_SESSION['perm_message'] = '<div class="alert alert-error"><i class="icon-error"></i> Permission not found or cannot be cancelled</div>';
    }
    
    header('Location: permissions.php');
    exit();
}

// Cancel LOP permission
if (isset($_GET['cancel_lop'])) {
    $permission_id = intval($_GET['cancel_lop']);
    
    // Get permission details first
    $get_perm = $conn->prepare("SELECT permission_date, duration, status FROM permissions WHERE id = ? AND user_id = ?");
    $get_perm->bind_param("ii", $permission_id, $user_id);
    $get_perm->execute();
    $perm_result = $get_perm->get_result();
    $perm_data = $perm_result->fetch_assoc();
    $get_perm->close();
    
    if ($perm_data) {
        $duration = $perm_data['duration'];
        $duration_text = "";
        if ($duration == 1) $duration_text = "1 hour";
        elseif ($duration < 1) $duration_text = ($duration * 60) . " minutes";
        elseif ($duration == 8) $duration_text = "full day";
        else $duration_text = $duration . " hours";
        
        $stmt = $conn->prepare("DELETE FROM permissions WHERE id = ? AND user_id = ? AND status = 'LOP'");
        $stmt->bind_param("ii", $permission_id, $user_id);
        
        if ($stmt->execute()) {
            // Get user info for notification
            $user_stmt = $conn->prepare("SELECT full_name FROM users WHERE id = ?");
            $user_stmt->bind_param("i", $user_id);
            $user_stmt->execute();
            $user_result = $user_stmt->get_result();
            $user_data = $user_result->fetch_assoc();
            $user_stmt->close();
            
            // NOTIFY HR, ADMIN, AND dm that LOP permission was cancelled
            $notify_users = $conn->query("SELECT id FROM users WHERE role IN ('hr', 'admin', 'dm', 'coo', 'ed')");
            while ($notify_user = $notify_users->fetch_assoc()) {
                $title = "LOP Permission Cancelled";
                $notification_msg = "{$user_data['full_name']} has cancelled their LOP permission request for {$duration_text} on {$perm_data['permission_date']}.";
                createNotification($conn, $notify_user['id'], 'lop_cancelled', $title, $notification_msg, $permission_id);
            }
            
            // NOTIFY THE EMPLOYEE (confirmation)
            $user_title = "LOP Permission Cancelled";
            $user_msg = "You have cancelled your LOP permission request for {$duration_text} on {$perm_data['permission_date']}.";
            createNotification($conn, $user_id, 'lop_cancelled', $user_title, $user_msg, $permission_id);
            
            $_SESSION['perm_message'] = '<div class="alert alert-success"><i class="icon-success"></i> LOP permission cancelled successfully</div>';
        } else {
            $_SESSION['perm_message'] = '<div class="alert alert-error"><i class="icon-error"></i> Error cancelling LOP permission</div>';
        }
        $stmt->close();
    } else {
        $_SESSION['perm_message'] = '<div class="alert alert-error"><i class="icon-error"></i> Permission not found or cannot be cancelled</div>';
    }
    
    header('Location: permissions.php');
    exit();
}

// Auto-delete any existing Cancelled records from database (all users)
$conn->query("DELETE FROM permissions WHERE status = 'Cancelled'");

// Get user's permissions
$stmt = $conn->prepare("
    SELECT * FROM permissions 
    WHERE user_id = ?
    ORDER BY permission_date DESC, applied_date DESC
");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$permissions = $stmt->get_result();
$stmt->close();

// Get current window for display (based on today) using the function from leave_functions.php
$current_window = getWindowForDate(date('Y-m-d'));
$current_window_start = $current_window['window_start'];
$current_window_end = $current_window['window_end'];

// Get monthly usage statistics for current window - CACHE THIS VALUE
$used_hours = getPermissionHoursInWindow($conn, $user_id, $current_window_start, $current_window_end);
$remaining_hours = max(0, 4 - $used_hours);

// Format window dates for display
$window_start_display = date('M j', strtotime($current_window_start));
$window_end_display = date('M j, Y', strtotime($current_window_end));
$window_label = $window_start_display . ' - ' . $window_end_display;

$page_title = "Permission Management - MAKSIM HR";
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Permission Management - MAKSIM HR</title>
    <?php include '../includes/head.php'; ?>
    <style>
        /* Simple date input styling - no external dependencies */
        .date-input-container {
            position: relative;
        }
        
        /* Permission limit card styles */
        .permission-limit-card {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 20px;
            border-radius: 10px;
            margin-bottom: 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 15px;
        }
        .limit-stats {
            display: flex;
            gap: 30px;
            flex-wrap: wrap;
        }
        .limit-stat {
            text-align: center;
        }
        .limit-stat-value {
            font-size: 32px;
            font-weight: bold;
        }
        .limit-stat-label {
            font-size: 12px;
            opacity: 0.8;
        }
        .limit-progress {
            width: 200px;
            height: 8px;
            background: rgba(255,255,255,0.2);
            border-radius: 4px;
            overflow: hidden;
        }
        .limit-progress-fill {
            height: 100%;
            background: #48bb78;
            transition: width 0.3s ease;
        }
        .warning-message {
            background: #fff5f5;
            border-left: 4px solid #c53030;
            padding: 15px;
            border-radius: 8px;
            margin: 15px 0;
            color: #742a2a;
        }
        .info-message {
            background: #ebf8ff;
            border-left: 4px solid #4299e1;
            padding: 15px;
            border-radius: 8px;
            margin: 15px 0;
        }
        .window-badge {
            background: rgba(255,255,255,0.2);
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 12px;
            display: inline-flex;
            align-items: center;
            gap: 5px;
        }
        .btn-primary {
            background: #0db329;
            color: white;
            padding: 10px 20px;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            font-size: 16px;
        }
        .btn-primary:hover {
            background: #24d14f;
        }
        i[class^="icon-"] {
            font-style: normal;
            display: inline-block;
        }
        .time-range-container {
            display: flex;
            gap: 15px;
            margin-top: 10px;
        }
        .time-input {
            flex: 1;
        }
        .time-input input {
            width: 100%;
            padding: 10px;
            border: 2px solid #e2e8f0;
            border-radius: 8px;
        }
        .duration-display {
            background: #f0f7ff;
            border-left: 4px solid #4299e1;
            padding: 10px 15px;
            border-radius: 8px;
            margin: 15px 0;
            font-weight: 600;
        }
        .duration-value {
            color: #2c5282;
            font-size: 18px;
        }
        .required-field::after {
            content: " *";
            color: #e53e3e;
            font-weight: bold;
        }
        .lop-badge {
            background: #c53030;
            color: white;
            padding: 2px 8px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 600;
            display: inline-block;
            margin-left: 5px;
        }
    </style>
</head>
<body>
    <?php include '../includes/header.php'; ?>
    
    <div class="app-main">
        <?php include '../includes/sidebar.php'; ?>
        
        <div class="main-content">
            <h2 class="page-title"><i class="icon-clock"></i> Permission Management</h2>
            
            <?php echo $message; ?>
            
            <!-- Permission Limit Card -->
            <div class="permission-limit-card">
                <div style="display: flex; align-items: center; gap: 15px; flex-wrap: wrap;">
                    <div>
                        <i class="icon-clock"></i> 
                        <strong>Monthly Permission Limit:</strong> 4 hours per month (16th - 15th cycle) | <strong>Excess becomes LOP</strong>
                    </div>
                    <span class="window-badge">
                        <i class="icon-calendar"></i> Current Window: <?php echo $window_label; ?>
                    </span>
                </div>
                <div class="limit-stats">
                    <div class="limit-stat">
                        <div class="limit-stat-value"><?php echo $used_hours; ?></div>
                        <div class="limit-stat-label">Hours Used</div>
                    </div>
                    <div class="limit-stat">
                        <div class="limit-stat-value"><?php echo $remaining_hours; ?></div>
                        <div class="limit-stat-label">Hours Remaining</div>
                    </div>
                    <div class="limit-stat">
                        <div class="limit-progress">
                            <div class="limit-progress-fill" style="width: <?php echo min(100, ($used_hours / 4) * 100); ?>%"></div>
                        </div>
                        <div class="limit-stat-label"><?php echo $window_label; ?></div>
                    </div>
                </div>
            </div>
            
            <!-- Warning if limit reached -->
            <?php if ($used_hours >= 4): ?>
            <div class="warning-message">
                <i class="icon-warning"></i> 
                <strong>⚠️ Monthly Permission Limit Reached!</strong><br>
                You have used all 4 hours of your permission quota for the current window (<?php echo $window_label; ?>).
                Any additional permission requests will automatically be converted to 
                <span style="color: #c53030; font-weight: 600;">Loss of Pay (LOP) - Unpaid Leave</span>.
            </div>
            <?php elseif ($remaining_hours > 0 && $remaining_hours < 2): ?>
            <div class="info-message">
                <i class="icon-info"></i> 
                <strong>Low Permission Balance:</strong> You have only <?php echo $remaining_hours; ?> hour(s) remaining for the current window (<?php echo $window_label; ?>).
                Any excess hours will be converted to LOP.
            </div>
            <?php endif; ?>
            
            <!-- Apply Permission Form -->
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title"><i class="icon-plus"></i> Apply for Permission</h3>
                </div>
                <form method="POST" action="" id="permissionForm">
                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label required-field">Date</label>
                            <div class="date-input-container">
                                <input type="date" 
                                       name="permission_date" 
                                       id="permission_date" 
                                       class="form-control" 
                                       required
                                       value="<?php echo date('Y-m-d'); ?>">
                            </div>
                        </div>
                    </div>
                    
                    <!-- Time Range Selection - REQUIRED -->
                    <div style="margin-top: 20px;">
                        <label class="form-label required-field">Time Range</label>
                        <div class="time-range-container">
                            <div class="time-input">
                                <label style="font-size: 12px; color: #718096;">From</label>
                                <input type="time" name="time_from" id="time_from" class="form-control" required value="09:00" onchange="calculateDurationFromTime()">
                            </div>
                            <div class="time-input">
                                <label style="font-size: 12px; color: #718096;">To</label>
                                <input type="time" name="time_to" id="time_to" class="form-control" required value="10:00" onchange="calculateDurationFromTime()">
                            </div>
                        </div>
                        <small class="form-text">Specify the time range for your permission - duration will be calculated automatically</small>
                    </div>
                    
                    <!-- Calculated Duration Display -->
                    <div id="calculated_duration_display" class="duration-display">
                        <i class="icon-clock"></i> 
                        <strong>Total Duration:</strong> 
                        <span id="duration_value" class="duration-value">1.00</span> hours
                    </div>
                    
                    <div id="duration_info" style="margin: 20px 0;"></div>
                    
                    <div class="form-group">
                        <label class="form-label required-field">Reason</label>
                        <textarea name="reason" class="form-control" rows="3" required placeholder="Enter detailed reason for permission"></textarea>
                    </div>
                    
                    <button type="submit" name="apply_permission" class="btn btn-primary">
                        <i class="icon-plus"></i> Apply Permission
                    </button>
                </form>
            </div>

            <!-- My Permission Requests -->
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title"><i class="icon-list"></i> My Permission Requests</h3>
                </div>
                <div class="table-container">
                    <table>
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Time Range</th>
                                <th>Duration</th>
                                <th>Status</th>
                                <th>Reason</th>
                                <th>Applied Date</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($permissions && $permissions->num_rows > 0): ?>
                                <?php while ($permission = $permissions->fetch_assoc()): 
                                    $is_lop = ($permission['status'] === 'LOP');
                                    $has_time_range = !empty($permission['time_from']) && !empty($permission['time_to']);
                                ?>
                                <tr style="<?php echo $is_lop ? 'background:#fff5f5;' : ''; ?>">
                                    <td><?php echo date('M j, Y', strtotime($permission['permission_date'])); ?></td>
                                    <td>
                                        <?php if ($has_time_range): ?>
                                            <?php 
                                            // Convert 24h to 12h format for display
                                            $from = date('g:i A', strtotime($permission['time_from']));
                                            $to = date('g:i A', strtotime($permission['time_to']));
                                            echo $from . ' - ' . $to;
                                            ?>
                                        <?php else: ?>
                                            <span style="color: #a0aec0; font-style: italic;">Not specified</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php 
                                        $dur = floatval($permission['duration']);
                                        if ($dur == 1) echo "1 hour";
                                        elseif ($dur < 1) echo ($dur * 60) . " min";
                                        else echo $dur . " hours";
                                        ?>
                                        <?php if ($is_lop): ?>
                                            <span class="lop-badge">LOP</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($is_lop): ?>
                                            <span class="status-badge" style="background:#fed7d7; color:#c53030;">LOP</span>
                                        <?php else: ?>
                                            <span class="status-badge status-<?php echo strtolower($permission['status']); ?>">
                                                <?php echo $permission['status']; ?>
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                    <td title="<?php echo htmlspecialchars($permission['reason']); ?>">
                                        <?php echo strlen($permission['reason']) > 50 ? substr($permission['reason'], 0, 50) . '...' : $permission['reason']; ?>
                                    </td>
                                    <td><?php echo date('M j, Y', strtotime($permission['applied_date'])); ?></td>
                                    <td>
                                        <div class="action-buttons">
                                            <?php if ($permission['status'] === 'Pending'): ?>
                                                <a href="?cancel=<?php echo $permission['id']; ?>" 
                                                   class="btn-small btn-cancel"
                                                   onclick="return confirm('Are you sure you want to cancel this permission request?')">
                                                    <i class="icon-cancel"></i> Cancel
                                                </a>
                                            <?php elseif ($permission['status'] === 'LOP'): ?>
                                                <a href="?cancel_lop=<?php echo $permission['id']; ?>" 
                                                   class="btn-small btn-cancel"
                                                   onclick="return confirm('Are you sure you want to cancel this LOP permission?')">
                                                    <i class="icon-cancel"></i> Cancel LOP
                                                </a>
                                            <?php endif; ?>
                                            <a href="permission_details.php?id=<?php echo $permission['id']; ?>" class="btn-small btn-view">
                                                <i class="icon-view"></i> View
                                            </a>
                                        </div>
                                    </td>
                                </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="7" style="text-align: center; padding: 40px; color: #718096;">
                                        <i class="icon-folder-open" style="font-size: 48px; margin-bottom: 15px; display: block;"></i>
                                        No permission requests found
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
    // Function to calculate hours between two times
    function calculateDurationFromTime() {
        const timeFrom = document.getElementById('time_from').value;
        const timeTo = document.getElementById('time_to').value;
        const durationValue = document.getElementById('duration_value');
        const durationInfo = document.getElementById('duration_info');
        const usedHours = <?php echo $used_hours; ?>;
        const remainingHours = <?php echo $remaining_hours; ?>;
        
        if (timeFrom && timeTo) {
            // Parse times
            const [fromHour, fromMin] = timeFrom.split(':').map(Number);
            const [toHour, toMin] = timeTo.split(':').map(Number);
            
            // Convert to minutes since midnight
            let fromMinutes = fromHour * 60 + fromMin;
            let toMinutes = toHour * 60 + toMin;
            
            // Validate that from time is not after to time
            if (fromMinutes > toMinutes) {
                durationValue.textContent = '0.00';
                durationInfo.innerHTML = `
                    <div class="alert alert-error" style="background: #fff5f5; border-left-color: #c53030;">
                        <i class="icon-error"></i> 
                        <strong>❌ Invalid Time Range!</strong><br>
                        From time cannot be after To time.<br>
                        Please correct the time range.
                    </div>
                `;
                return;
            }
            
            // Calculate difference in hours
            const diffMinutes = toMinutes - fromMinutes;
            const diffHours = (diffMinutes / 60).toFixed(2);
            const totalDuration = parseFloat(diffHours);
            
            // Update display
            durationValue.textContent = diffHours;
            
            // Calculate permission vs LOP
            const permissionHours = Math.min(totalDuration, remainingHours);
            const lopHours = totalDuration - permissionHours;
            
            if (totalDuration > remainingHours) {
                const excessHours = totalDuration - remainingHours;
                const lopDays = (excessHours / 8).toFixed(2);
                durationInfo.innerHTML = `
                    <div class="alert alert-warning" style="background: #fff5f5; border-left-color: #c53030;">
                        <i class="icon-warning"></i> 
                        <strong>⚠️ Monthly Limit Exceeded!</strong><br>
                        You have ${remainingHours} hour(s) remaining for the current window.<br>
                        <strong>${permissionHours.toFixed(2)} hour(s)</strong> will be submitted as permission (pending approval).<br>
                        <strong>${lopHours.toFixed(2)} hour(s) (${lopDays} days)</strong> will be converted to 
                        <span style="color: #c53030; font-weight: 600;">Loss of Pay (LOP) - Unpaid Leave</span>.
                    </div>
                `;
            } else {
                durationInfo.innerHTML = `
                    <div class="alert alert-info" style="background: #f0fff4; border-left-color: #48bb78;">
                        <i class="icon-check"></i> 
                        <strong>Within Monthly Limit</strong><br>
                        You have ${remainingHours} hour(s) remaining for the current window.<br>
                        This ${totalDuration} hour(s) request will be submitted as permission (pending approval).
                    </div>
                `;
            }
        }
    }
    
    // Add form submission validation
    document.getElementById('permissionForm').addEventListener('submit', function(e) {
        const timeFrom = document.getElementById('time_from').value;
        const timeTo = document.getElementById('time_to').value;
        
        if (timeFrom && timeTo) {
            const [fromHour, fromMin] = timeFrom.split(':').map(Number);
            const [toHour, toMin] = timeTo.split(':').map(Number);
            
            const fromMinutes = fromHour * 60 + fromMin;
            const toMinutes = toHour * 60 + toMin;
            
            if (fromMinutes > toMinutes) {
                e.preventDefault();
                alert('Error: From time cannot be after To time. Please correct the time range.');
                return false;
            }
        }
    });
    
    // Initialize on page load
    document.addEventListener('DOMContentLoaded', function() {
        // Set default time values
        document.getElementById('time_from').value = '09:00';
        document.getElementById('time_to').value = '10:00';
        calculateDurationFromTime();
    });
    
    // Handle time from change
    document.getElementById('time_from').addEventListener('change', calculateDurationFromTime);
    
    // Handle time to change
    document.getElementById('time_to').addEventListener('change', calculateDurationFromTime);
    </script>
    
    <script src="../assets/js/app.js"></script>
</body>
</html>