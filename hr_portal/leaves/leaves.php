<?php
require_once '../config/db.php';
require_once '../includes/leave_functions.php';

if (!isLoggedIn()) {
    header('Location: ../auth/login.php');
    exit();
}

$user_id = $_SESSION['user_id'];
$message = '';

// Initialize carryforward for current user
initializeMonthlyCarryForward($conn, $user_id);

// Get current leave info
$leave_year = getCurrentLeaveYear();
$current_month = getCurrentLeaveMonth();
$balance = getUserLeaveBalance($conn, $user_id);

// Check for March leave expiry warning
$current_date = new DateTime();
$current_month_num = $current_date->format('n');
$current_day = $current_date->format('j');
$show_expiry_warning = false;

if ($current_month_num == 3 && $current_day <= 15) {
    // We're in March 1-15, check if user has remaining casual leaves
    if (isset($balance['casual_remaining_this_month']) && $balance['casual_remaining_this_month'] > 0) {
        $show_expiry_warning = true;
    }
}

// Handle AJAX for LOP application
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['apply_as_lop_ajax'])) {
    $user_id = $_SESSION['user_id'];
    $from_date = sanitize($_POST['from_date']);
    $to_date = sanitize($_POST['to_date']);
    $reason = sanitize($_POST['reason']);
    $day_type = sanitize($_POST['day_type']);
    $days = floatval($_POST['days']);
    
    $leave_year_for_date = getLeaveYearForDate($from_date);
    $year_label = isset($leave_year_for_date['year_label']) ? $leave_year_for_date['year_label'] : $leave_year['year_label'];
    
    $stmt = $conn->prepare("
        INSERT INTO leaves (user_id, leave_type, from_date, to_date, days, day_type, reason, leave_year)
        VALUES (?, 'LOP', ?, ?, ?, ?, ?, ?)
    ");
    $stmt->bind_param("issdsss", $user_id, $from_date, $to_date, $days, $day_type, $reason, $year_label);
    
    if ($stmt->execute()) {
        echo json_encode(['success' => true, 'message' => 'LOP application submitted successfully']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Error submitting LOP application']);
    }
    $stmt->close();
    exit();
}

// Handle AJAX for mixed application
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['apply_mixed'])) {
    $user_id = $_SESSION['user_id'];
    $from_date = sanitize($_POST['from_date']);
    $to_date = sanitize($_POST['to_date']);
    $reason = sanitize($_POST['reason']);
    $day_type = sanitize($_POST['day_type']);
    $days = floatval($_POST['days']);
    
    $leave_year_for_date = getLeaveYearForDate($from_date);
    $year_label = isset($leave_year_for_date['year_label']) ? $leave_year_for_date['year_label'] : $leave_year['year_label'];
    $used_this_month = getCurrentMonthCasualUsage($conn, $user_id);
    $remaining_this_month = max(0, 1 - $used_this_month);
    
    $conn->begin_transaction();
    
    try {
        $result = [
            'success' => true,
            'casual_days' => 0,
            'lop_days' => 0,
            'message' => ''
        ];
        
        if ($remaining_this_month > 0) {
            $casual_days = min($remaining_this_month, $days);
            $casual_reason = $reason . " (First day - Casual Leave)";
            $casual_stmt = $conn->prepare("
                INSERT INTO leaves (user_id, leave_type, from_date, to_date, days, day_type, reason, leave_year)
                VALUES (?, 'Casual', ?, ?, ?, ?, ?, ?)
            ");
            $casual_stmt->bind_param("issdsss", $user_id, $from_date, $to_date, $casual_days, $day_type, $casual_reason, $year_label);
            $casual_stmt->execute();
            $casual_stmt->close();
            $result['casual_days'] = $casual_days;
        }
        
        $lop_days = $days - ($remaining_this_month > 0 ? min($remaining_this_month, $days) : 0);
        if ($lop_days > 0) {
            $lop_reason = $reason . " (Remaining days - Loss of Pay)";
            $lop_stmt = $conn->prepare("
                INSERT INTO leaves (user_id, leave_type, from_date, to_date, days, day_type, reason, leave_year)
                VALUES (?, 'LOP', ?, ?, ?, ?, ?, ?)
            ");
            $lop_stmt->bind_param("issdsss", $user_id, $from_date, $to_date, $lop_days, $day_type, $lop_reason, $year_label);
            $lop_stmt->execute();
            $lop_stmt->close();
            $result['lop_days'] = $lop_days;
        }
        
        $conn->commit();
        $result['success'] = true;
        $result['message'] = "Applied: {$result['casual_days']} casual + {$result['lop_days']} LOP";
        
    } catch (Exception $e) {
        $conn->rollback();
        $result['success'] = false;
        $result['message'] = 'Error: ' . $e->getMessage();
    }
    
    header('Content-Type: application/json');
    echo json_encode($result);
    exit();
}

// Function to check if leave already exists for a date
function checkExistingLeave($conn, $user_id, $from_date, $to_date) {
    $stmt = $conn->prepare("
        SELECT id, status, from_date, to_date 
        FROM leaves 
        WHERE user_id = ? 
        AND status IN ('Pending', 'Approved')
        AND NOT (to_date < ? OR from_date > ?)
    ");
    $stmt->bind_param("iss", $user_id, $from_date, $to_date);
    $stmt->execute();
    $result = $stmt->get_result();
    $existing_leaves = $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    
    return $existing_leaves;
}

// Function to calculate days between two dates (inclusive)
function calculateDaysBetweenDates($from_date, $to_date, $day_type = 'full') {
    $from = new DateTime($from_date);
    $to = new DateTime($to_date);
    
    if ($from == $to) {
        // Same day - always 1 day for full day, 0.5 for half day
        return ($day_type === 'half') ? 0.5 : 1;
    }
    
    // Different days - calculate inclusive days
    $interval = $from->diff($to);
    $calendar_days = $interval->days + 1; // +1 for inclusive counting
    
    if ($day_type === 'half') {
        return $calendar_days * 0.5;
    }
    
    return $calendar_days;
}

// Function to split leave dates based on existing approved leaves
function splitLeaveDates($user_id, $from_date, $to_date, $conn) {
    // Get all approved leaves that overlap with the requested range
    $stmt = $conn->prepare("
        SELECT from_date, to_date 
        FROM leaves 
        WHERE user_id = ? 
        AND status = 'Approved'
        AND NOT (to_date < ? OR from_date > ?)
        ORDER BY from_date ASC
    ");
    $stmt->bind_param("iss", $user_id, $from_date, $to_date);
    $stmt->execute();
    $result = $stmt->get_result();
    $approved_leaves = $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    
    if (empty($approved_leaves)) {
        // No conflicts, return the original range
        return [['from' => $from_date, 'to' => $to_date]];
    }
    
    $segments = [];
    $current_start = $from_date;
    $current_end = $to_date;
    
    foreach ($approved_leaves as $leave) {
        $approve_start = $leave['from_date'];
        $approve_end = $leave['to_date'];
        
        // If current segment starts before approved leave
        if ($current_start < $approve_start) {
            $segment_end = date('Y-m-d', strtotime($approve_start . ' -1 day'));
            if ($segment_end >= $current_start) {
                $segments[] = ['from' => $current_start, 'to' => $segment_end];
            }
        }
        
        // Move current start to after approved leave
        $next_start = date('Y-m-d', strtotime($approve_end . ' +1 day'));
        $current_start = max($current_start, $next_start);
        
        if ($current_start > $current_end) {
            break;
        }
    }
    
    // Add remaining segment
    if ($current_start <= $current_end) {
        $segments[] = ['from' => $current_start, 'to' => $current_end];
    }
    
    return $segments;
}

// Apply leave with auto LOP - FIXED: Using PRG pattern to prevent resubmission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['apply_leave'])) {
    // Start session if not already started
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    
    $leave_type = sanitize($_POST['leave_type']);
    $from_date = sanitize($_POST['from_date']);
    $to_date = sanitize($_POST['to_date']);
    $reason = sanitize($_POST['reason']);
    $day_type = sanitize($_POST['day_type']);
    
    $from = new DateTime($from_date);
    $to = new DateTime($to_date);
    
    if ($from > $to) {
        $_SESSION['leave_message'] = '<div class="alert alert-error">From date cannot be after To date</div>';
        header('Location: leaves.php');
        exit();
    }
    
    // Check if already applied for these dates
    $existing_leaves = checkExistingLeave($conn, $user_id, $from_date, $to_date);
    
    if (!empty($existing_leaves)) {
        $conflict_dates = [];
        foreach ($existing_leaves as $leave) {
            $conflict_dates[] = $leave['from_date'] . ' to ' . $leave['to_date'] . ' (' . $leave['status'] . ')';
        }
        $conflict_list = implode('<br>', $conflict_dates);
        $_SESSION['leave_message'] = '<div class="alert alert-warning" style="background: #fff5f5; border-left-color: #c53030;">
            <i class="icon-warning" style="color: #c53030;"></i> 
            <strong>Cannot Apply Leave!</strong><br>
            You already have pending or approved leave applications for these dates:<br>
            ' . $conflict_list . '<br>
            Please select different dates or cancel the existing application.
        </div>';
        header('Location: leaves.php');
        exit();
    }
    
    // Split the date range based on existing approved leaves
    $date_segments = splitLeaveDates($user_id, $from_date, $to_date, $conn);
    
    if (empty($date_segments)) {
        $_SESSION['leave_message'] = '<div class="alert alert-warning">
            <i class="icon-info"></i> 
            All dates in your selected range are already covered by approved leaves.
        </div>';
    } else {
        $total_segments = count($date_segments);
        $applied_segments = 0;
        $total_casual = 0;
        $total_lop = 0;
        
        foreach ($date_segments as $index => $segment) {
            $segment_from = $segment['from'];
            $segment_to = $segment['to'];
            
            // Calculate days for this segment using the new function
            $segment_days = calculateDaysBetweenDates($segment_from, $segment_to, $day_type);
            
            // Apply for this segment
            $segment_reason = $reason;
            if ($total_segments > 1) {
                $segment_reason .= " (Part " . ($index + 1) . " of " . $total_segments . ")";
            }
            
            $result = applyLeaveWithAutoLOP($conn, $user_id, $leave_type, $segment_from, $segment_to, $segment_days, $day_type, $segment_reason);
            
            if ($result['success']) {
                $applied_segments++;
                if (isset($result['casual_days'])) {
                    $total_casual += $result['casual_days'];
                }
                if (isset($result['lop_days'])) {
                    $total_lop += $result['lop_days'];
                }
            }
        }
        
        if ($applied_segments > 0) {
            if ($total_lop > 0) {
                $_SESSION['leave_message'] = '<div class="alert alert-warning" style="background: #fff5f5; border-left-color: #c53030;">
                    <i class="icon-warning" style="color: #c53030;"></i> 
                    <strong>Application Submitted with Adjustments!</strong><br>
                    Applied for ' . $applied_segments . ' available segment(s).<br>
                    Total: ' . $total_casual . ' casual + ' . $total_lop . ' LOP days.<br>
                    Dates already approved were automatically skipped.
                </div>';
            } else {
                $_SESSION['leave_message'] = '<div class="alert alert-success">
                    <i class="icon-success"></i> 
                    Leave applied successfully for ' . $applied_segments . ' available segment(s).<br>
                    Total: ' . $total_casual . ' casual days.<br>
                    Dates already approved were automatically skipped.
                </div>';
            }
        } else {
            $_SESSION['leave_message'] = '<div class="alert alert-error">Error applying leave for available segments</div>';
        }
    }
    
    // Redirect to prevent form resubmission
    header('Location: leaves.php');
    exit();
}

// Cancel leave - FIXED: Using PRG pattern to prevent resubmission
if (isset($_GET['cancel'])) {
    $leave_id = intval($_GET['cancel']);
    
    $stmt = $conn->prepare("
        UPDATE leaves 
        SET status = 'Cancelled' 
        WHERE id = ? AND user_id = ? AND status = 'Pending'
    ");
    $stmt->bind_param("ii", $leave_id, $user_id);
    
    if ($stmt->execute()) {
        $_SESSION['leave_message'] = '<div class="alert alert-success"><i class="icon-success"></i> Leave application cancelled successfully</div>';
    } else {
        $_SESSION['leave_message'] = '<div class="alert alert-error"><i class="icon-error"></i> Error cancelling leave application</div>';
    }
    $stmt->close();
    
    // Redirect to prevent resubmission
    header('Location: leaves.php');
    exit();
}

// Get message from session if exists
if (isset($_SESSION['leave_message'])) {
    $message = $_SESSION['leave_message'];
    unset($_SESSION['leave_message']); // Clear after displaying
}

// Get user's leaves
$stmt = $conn->prepare("
    SELECT * FROM leaves 
    WHERE user_id = ? 
    ORDER BY applied_date DESC
");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$leaves = $stmt->get_result();
$stmt->close();

// Get LOP total for display
$lop_total = getLOPCount($conn, $user_id);
$lop_this_month = getCurrentMonthLOPUsage($conn, $user_id);

$page_title = "Leave Management - MAKSIM HR";
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <?php include '../includes/head.php'; ?>
    <style>
        .leave-year-info {
            background: linear-gradient(135deg, #075bc9 0%, #764ba2 100%);
            color: white;
            padding: 15px 20px;
            border-radius: 10px;
            margin-bottom: 20px;
        }
        .policy-badge {
            background: rgba(255,255,255,0.2);
            padding: 8px 15px;
            border-radius: 20px;
            font-size: 14px;
            display: inline-block;
        }
        .month-info {
            background: #f0fff4;
            border-left: 4px solid #48bb78;
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
        }
        .lop-card {
            background: linear-gradient(135deg, #f56565 0%, #c53030 100%);
            color: white;
            padding: 20px;
            border-radius: 10px;
        }
        .casual-monthly {
            background: #f0fff4;
            border: 1px solid #9ae6b4;
            border-radius: 8px;
            padding: 12px;
            margin-top: 10px;
        }
        .warning-box {
            background: #fff3cd;
            border-left: 4px solid #ffc107;
            padding: 15px;
            border-radius: 5px;
            margin: 15px 0;
        }
        .expiry-warning {
            background: #fff5f5;
            border-left: 4px solid #c53030;
            padding: 15px;
            border-radius: 5px;
            margin: 15px 0;
            color: #742a2a;
        }
        .btn-view {
            background: #4299e1;
            color: white;
            padding: 4px 8px;
            border-radius: 4px;
            text-decoration: none;
            font-size: 12px;
        }
        .btn-cancel {
            background: #f56565;
            color: white;
            padding: 4px 8px;
            border-radius: 4px;
            text-decoration: none;
            font-size: 12px;
            margin-right: 5px;
        }
        .lop-badge {
            background: #c53030;
            color: white;
            padding: 2px 8px;
            border-radius: 12px;
            font-size: 11px;
            margin-left: 5px;
            display: inline-block;
        }
        .lop-row {
            background: #fff5f5;
        }
        .type-lop {
            color: #c53030;
            font-weight: bold;
        }
        .type-casual {
            color: #48bb78;
        }
        .type-sick {
            color: #4299e1;
        }
        .info-box {
            background: #ebf8ff;
            border-left: 4px solid #4299e1;
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
        }
        .monthly-info {
            background: #f0fff4;
            border-left: 4px solid #48bb78;
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
        }
        .carry-forward {
            color: #276749;
            font-weight: 600;
        }
        .used-this-month {
            color: #ed8936;
            font-weight: 600;
        }
        .btn:disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }
    </style>
</head>
<body>
    <?php include '../includes/header.php'; ?>
    
    <div class="app-main">
        <?php include '../includes/sidebar.php'; ?>
        
        <div class="main-content">
            <h2 class="page-title"><i class="icon-leave"></i> Leave Management</h2>
            
            <div class="leave-year-info">
                <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 15px;">
                    <div>
                        <i class="icon-calendar"></i> 
                        <strong>Current Leave Year:</strong> <?php echo $leave_year['year_label']; ?> 
                        (Mar 16 - Mar 15)
                    </div>
                    <div class="policy-badge">
                        <i class="icon-clock"></i> 
                        Casual Leave: 1 day per month (16th to 15th cycle)
                    </div>
                </div>
            </div>
            
            <div class="month-info">
                <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 15px;">
                    <div>
                        <i class="icon-calendar-week"></i> 
                        <strong>Current Leave Month:</strong> <?php echo $current_month['month_label']; ?>
                    </div>
                    <div>
                        <span style="background: #48bb78; color: white; padding: 5px 15px; border-radius: 20px; font-size: 14px;">
                            <i class="icon-check"></i> 1 casual leave per month
                        </span>
                    </div>
                </div>
            </div>
            
            <!-- March Expiry Warning -->
            <?php if ($show_expiry_warning): ?>
            <div class="expiry-warning">
                <i class="icon-warning" style="color: #c53030;"></i> 
                <strong>⚠️ Important: Leaves Expiring Soon!</strong><br>
                You have <strong><?php echo $balance['casual_remaining_this_month']; ?> casual leave(s)</strong> remaining this month (March 1-15).<br>
                <span style="font-weight: 600;">These leaves will EXPIRE on March 15th and cannot be carried forward to the next leave year.</span><br>
                Please use them before March 15th or they will be lost. You cannot apply for leaves in the new leave year (starting March 16) until the current leave year ends.
            </div>
            <?php endif; ?>
            
            <!-- Monthly Casual Leave Info -->
            <div class="monthly-info">
                <i class="icon-info"></i> 
                Unused casual leaves are carried forward to the next month.
                <?php if (isset($balance['casual_monthly']['carry_forward']) && $balance['casual_monthly']['carry_forward'] > 0): ?>
                <br><span class="carry-forward"><i class="icon-arrow-right"></i> You have <?php echo $balance['casual_monthly']['carry_forward']; ?> day(s) carried forward from last month.</span>
                <?php endif; ?>
                <?php if (isset($balance['casual_monthly']['used_this_month']) && $balance['casual_monthly']['used_this_month'] > 0): ?>
                <br><span class="used-this-month"><i class="icon-check"></i> You have used <?php echo $balance['casual_monthly']['used_this_month']; ?>/1 casual leave this month.</span>
                <?php endif; ?>
            </div>
            
            <div class="info-box">
                <i class="icon-info"></i> 
                <strong>Smart Leave Application:</strong> If you apply for dates that overlap with already approved leaves, 
                the system will automatically skip those dates and only apply for the available dates.<br>
                <strong>Day Calculation:</strong> 2026-02-27 to 2026-02-28 = 2 days (inclusive counting).
            </div>
            
            <div style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 20px; margin-bottom: 30px;">
                <!-- Sick Leave Card -->
                <div style="background: white; padding: 20px; border-radius: 10px; box-shadow: 0 2px 10px rgba(0,0,0,0.05);">
                    <div style="color: #4299e1; font-size: 14px; text-transform: uppercase; margin-bottom: 10px;">
                        <i class="icon-sick"></i> Sick Leave
                    </div>
                    <div style="font-size: 36px; font-weight: bold; color: #2d3748;"><?php echo $balance['remaining']['Sick']; ?></div>
                    <div style="color: #718096;">Yearly remaining</div>
                    <div style="margin-top: 10px; font-size: 13px; color: #f56565;"></div>
                </div>
                
                <!-- Casual Leave Card -->
                <div style="background: white; padding: 20px; border-radius: 10px; box-shadow: 0 2px 10px rgba(0,0,0,0.05);">
                    <div style="color: #48bb78; font-size: 14px; text-transform: uppercase; margin-bottom: 10px;">
                        <i class="icon-casual"></i> Casual Leave
                    </div>
                    <div style="font-size: 36px; font-weight: bold; color: #2d3748;"><?php echo $balance['casual_remaining_this_month']; ?></div>
                    <div style="color: #718096;">Available this month</div>
                    <div class="casual-monthly">
                        <div style="display: flex; justify-content: space-between;">
                            <span>Used this month:</span>
                            <span style="font-weight: 600;"><?php echo $balance['casual_this_month']; ?>/1 day</span>
                        </div>
                        <div style="display: flex; justify-content: space-between; margin-top: 5px;">
                            <span>Yearly remaining:</span>
                            <span style="font-weight: 600;"><?php echo $balance['remaining']['Casual']; ?>/12 days</span>
                        </div>
                    </div>
                </div>
                
                <!-- LOP Card -->
                <div class="lop-card">
                    <div style="font-size: 14px; text-transform: uppercase; margin-bottom: 10px; opacity: 0.9;">
                        <i class="icon-lop"></i> Loss of Pay (LOP)
                    </div>
                    <div style="font-size: 36px; font-weight: bold;"><?php echo $lop_total; ?></div>
                    <div style="opacity: 0.9;">Total days this year</div>
                    <div style="margin-top: 5px; font-size: 14px;">
                        This month: <strong><?php echo $lop_this_month; ?></strong> days
                    </div>
                    <div style="margin-top: 10px; font-size: 13px; background: rgba(255,255,255,0.2); padding: 8px; border-radius: 5px;">
                        <i class="icon-info"></i> Unpaid leave - affects salary
                    </div>
                </div>
            </div>
            
            <?php echo $message; ?>
            
            <!-- Apply Leave Form - Only Sick and Casual options -->
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title"><i class="icon-plus"></i> Apply for Leave</h3>
                </div>
                <form method="POST" action="" id="leaveForm">
                    <div class="warning-box">
                        <i class="icon-info"></i> 
                        <strong>Casual Leave Policy:</strong> You can take only 1 casual leave per month. 
                        Any additional days in the same month will automatically become 
                        <span style="color: #c53030; font-weight: 600;">Loss of Pay (LOP) - Unpaid Leave</span>.<br>
                        <strong>Day Calculation:</strong> 2026-02-27 to 2026-02-28 = 2 days (inclusive counting)
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label">From Date *</label>
                            <input type="date" name="from_date" id="from_date" class="form-control" required>
                        </div>
                        <div class="form-group">
                            <label class="form-label">To Date *</label>
                            <input type="date" name="to_date" id="to_date" class="form-control" required>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Leave Type *</label>
                            <select name="leave_type" id="leave_type" class="form-control" required>
                                <option value="">Select Type</option>
                                <option value="Sick">Sick Leave (6 days/year, 2 month restriction)</option>
                                <option value="Casual">Casual Leave (1 day/month, excess becomes LOP)</option>
                            </select>
                        </div>
                    </div>
                    
                    <div id="leave_type_info" style="margin-bottom: 15px;"></div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label">Day Type *</label>
                            <div style="display: flex; gap: 20px; margin-top: 8px;">
                                <label style="display: flex; align-items: center; gap: 5px;">
                                    <input type="radio" name="day_type" value="full" checked required> Full Day
                                </label>
                                <label style="display: flex; align-items: center; gap: 5px;">
                                    <input type="radio" name="day_type" value="half" required> Half Day
                                </label>
                            </div>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Reason *</label>
                        <textarea name="reason" id="reason" class="form-control" rows="3" required placeholder="Enter reason for leave"></textarea>
                    </div>
                    
                    <button type="submit" name="apply_leave" id="submitBtn" class="btn"><i class="icon-plus"></i> Apply Leave</button>
                </form>
            </div>

            <!-- My Leave Applications - Shows LOP in type column -->
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title"><i class="icon-list"></i> My Leave Applications</h3>
                </div>
                <div class="table-container">
                    <table>
                        <thead>
                            <tr>
                                <th>Type</th>
                                <th>From</th>
                                <th>To</th>
                                <th>Days</th>
                                <th>Status</th>
                                <th>Reason</th>
                                <th>Day Type</th>
                                <th>Applied Date</th>
                                <th>Leave Year</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($leaves->num_rows > 0): ?>
                                <?php while ($leave = $leaves->fetch_assoc()): 
                                    $is_lop = $leave['leave_type'] === 'LOP';
                                    $type_class = $is_lop ? 'type-lop' : ($leave['leave_type'] === 'Casual' ? 'type-casual' : 'type-sick');
                                ?>
                                <tr <?php echo $is_lop ? 'class="lop-row"' : ''; ?>>
                                    <td class="<?php echo $type_class; ?>">
                                        <?php echo $leave['leave_type']; ?>
                                        <?php if ($is_lop): ?>
                                            <span class="lop-badge">Unpaid</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo $leave['from_date']; ?></td>
                                    <td><?php echo $leave['to_date']; ?></td>
                                    <td><?php echo $leave['days']; ?></td>
                                    <td>
                                        <span class="status-badge status-<?php echo strtolower($leave['status']); ?>">
                                            <?php echo $leave['status']; ?>
                                        </span>
                                    </td>
                                    <td title="<?php echo htmlspecialchars($leave['reason']); ?>">
                                        <?php 
                                        $reason_text = $leave['reason'];
                                        if ($is_lop && strpos($reason_text, '(Loss of Pay)') === false) {
                                            $reason_text .= ' (Loss of Pay)';
                                        }
                                        echo strlen($reason_text) > 30 ? substr($reason_text, 0, 30) . '...' : $reason_text; 
                                        ?>
                                    </td>
                                    <td><?php echo $leave['day_type'] === 'half' ? 'Half Day' : 'Full Day'; ?></td>
                                    <td><?php echo date('Y-m-d', strtotime($leave['applied_date'])); ?></td>
                                    <td>
                                        <span style="background: #e2e8f0; padding: 3px 8px; border-radius: 12px; font-size: 11px;">
                                            <?php echo $leave['leave_year'] ?? $leave_year['year_label']; ?>
                                        </span>
                                    </td>
                                    <td>
                                        <div class="action-buttons">
                                            <?php if ($leave['status'] === 'Pending'): ?>
                                                <a href="?cancel=<?php echo $leave['id']; ?>" 
                                                   class="btn-small btn-cancel"
                                                   onclick="return confirm('Cancel this application?')">
                                                    <i class="icon-cancel"></i> Cancel
                                                </a>
                                            <?php endif; ?>
                                            <a href="leave_details.php?id=<?php echo $leave['id']; ?>" class="btn-small btn-view">
                                                <i class="icon-view"></i> View
                                            </a>
                                        </div>
                                    </td>
                                </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="10" style="text-align: center; padding: 40px;">
                                        <i class="icon-folder-open" style="font-size: 48px; margin-bottom: 15px; display: block; color: #cbd5e0;"></i>
                                        No leave applications found
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
    document.addEventListener('DOMContentLoaded', function() {
        var today = new Date().toISOString().split('T')[0];
        document.getElementById('from_date').value = today;
        document.getElementById('to_date').value = today;
    });
    
    document.getElementById('leave_type').addEventListener('change', function() {
        const type = this.value;
        const infoDiv = document.getElementById('leave_type_info');
        const balance = <?php echo json_encode($balance); ?>;
        
        if (type === 'Casual') {
            infoDiv.innerHTML = `
                <div class="alert alert-info" style="background: #f0fff4; border-left-color: #48bb78;">
                    <i class="icon-info"></i> 
                    <strong>Current Month:</strong> ${balance.casual_this_month}/1 day used.<br>
                    <strong>Remaining this month:</strong> ${balance.casual_remaining_this_month} day(s)<br>
                    <strong>Yearly remaining:</strong> ${balance.remaining.Casual}/12 days<br>
                    <span style="color: #c53030;">Note: Any days beyond 1 this month become LOP (unpaid)</span>
                </div>
            `;
        } else if (type === 'Sick') {
            infoDiv.innerHTML = `
                <div class="alert alert-info" style="background: #ebf8ff; border-left-color: #4299e1;">
                    <i class="icon-info"></i> 
                    <strong>Sick Leave:</strong> ${balance.remaining.Sick} days remaining this year.<br>
                    <span style="color: #f56565;">2 month restriction applies</span>
                </div>
            `;
        } else {
            infoDiv.innerHTML = '';
        }
    });
    </script>
    
    <script src="../assets/js/app.js"></script>
</body>
</html>