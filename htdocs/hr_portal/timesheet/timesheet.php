<?php
require_once '../config/db.php';
require_once '../includes/leave_functions.php';
require_once '../includes/icon_functions.php';
require_once '../includes/notification_functions.php'; // ADD THIS LINE

// Prevent browser caching - always fetch fresh data
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

// Set default timezone to IST for the entire script
date_default_timezone_set('Asia/Kolkata');

if (!isLoggedIn()) {
    header('Location: ../auth/login.php');
    exit();
}

$user_id = $_SESSION['user_id'];
$role = $_SESSION['role'];
$message = '';

// For HR/Admin/PM viewing other users' timesheets
$viewing_user_id = $user_id;
if (isset($_GET['user_id']) && in_array($role, ['hr', 'admin', 'pm'])) {
    $viewing_user_id = intval($_GET['user_id']);
}

// Get date range parameters
$from_date = isset($_GET['from_date']) ? sanitize($_GET['from_date']) : date('Y-m-d');
$to_date = isset($_GET['to_date']) ? sanitize($_GET['to_date']) : date('Y-m-d');

// Ensure from_date is not after to_date
if ($from_date > $to_date) {
    $to_date = $from_date;
}

// Get user info
$stmt = $conn->prepare("
    SELECT u.*, r.full_name as reporting_manager 
    FROM users u 
    LEFT JOIN users r ON u.reporting_to = r.username 
    WHERE u.id = ?
");
$stmt->bind_param("i", $viewing_user_id);
$stmt->execute();
$user_result = $stmt->get_result();
$user_info = $user_result->fetch_assoc();
$stmt->close();

// Check if current user is a PM/HR/Admin account (exempt from timesheet requirements)
$current_username = $_SESSION['username'];
$is_admin_user = in_array($role, ['hr', 'admin', 'pm', 'MG']) || $user_info['reporting_to'] == 'CEO' || $user_info['position'] == 'CEO';

// Check if timesheet already exists for this date and user
$existing_timesheet = null;
$date_to_check = isset($_POST['date']) ? sanitize($_POST['date']) : $from_date;

$stmt_check = $conn->prepare("
    SELECT * FROM timesheets 
    WHERE user_id = ? AND entry_date = ?
");
$stmt_check->bind_param("is", $viewing_user_id, $date_to_check);
$stmt_check->execute();
$check_result = $stmt_check->get_result();
$existing_timesheet = $check_result->fetch_assoc();
$stmt_check->close();

// Check if tasks table exists
$tasks_table_check = $conn->query("SHOW TABLES LIKE 'tasks'");
$tasks_table_exists = $tasks_table_check->num_rows > 0;

// Function to check if LOP exists for a date
function hasLOPForDate($conn, $user_id, $date) {
    $check = $conn->prepare("SELECT id FROM leaves WHERE user_id = ? AND from_date = ? AND leave_type = 'LOP' AND reason LIKE 'Auto-generated LOP%'");
    $check->bind_param("is", $user_id, $date);
    $check->execute();
    $result = $check->get_result();
    $exists = $result->num_rows > 0;
    $check->close();
    return $exists;
}

// Function to check if timesheet is late (submitted after the day)
function isLateTimesheet($entry_date, $submitted_date) {
    $entry = strtotime($entry_date);
    $submitted = strtotime(date('Y-m-d', strtotime($submitted_date)));
    return $submitted > $entry;
}

// Function to count working days (excluding Sundays and holidays) in a date range
function countWorkingDays($from_date, $to_date) {
    $start = new DateTime($from_date);
    $end = new DateTime($to_date);
    $end->modify('+1 day');
    $interval = new DateInterval('P1D');
    $date_range = new DatePeriod($start, $interval, $end);
    
    $working_days = 0;
    foreach ($date_range as $date) {
        $date_str = $date->format('Y-m-d');
        // Skip Sundays and holidays
        if ($date->format('N') != 7 && isHoliday($date_str) === false) {
            $working_days++;
        }
    }
    return $working_days;
}

// Check for missing timesheets and add LOP (exempt Sundays, holidays, and leaves)
if (in_array($role, ['hr', 'admin', 'pm']) && isset($_GET['check_missing'])) {
    $check_date = isset($_GET['check_date']) ? sanitize($_GET['check_date']) : date('Y-m-d', strtotime('-1 day'));
    
    // Skip if checking date is Sunday
    if (date('N', strtotime($check_date)) == 7) {
        $message = '<div class="alert alert-info"><i class="icon-info"></i> Skipped check for ' . $check_date . ' (Sunday - No LOP required).</div>';
    }
    // Skip if checking date is a holiday
    else if (isHoliday($check_date) !== false) {
        $holiday_name = isHoliday($check_date);
        $message = '<div class="alert alert-info"><i class="icon-info"></i> Skipped check for ' . $check_date . ' (' . $holiday_name . ' - No LOP required).</div>';
    }
    else {
        $users_result = $conn->query("SELECT id, username, role, status FROM users");
        $lop_added_count = 0;
        
        while ($user = $users_result->fetch_assoc()) {
            // SKIP PM/HR/ADMIN users - they don't get LOP
            if (in_array($user['role'], ['hr', 'admin', 'pm'])) {
                continue;
            }
            
            // Skip users with status = 'inactive'
            if ($user['status'] === 'inactive') {
                continue;
            }
            
            $check_ts = $conn->prepare("SELECT id FROM timesheets WHERE user_id = ? AND entry_date = ?");
            $check_ts->bind_param("is", $user['id'], $check_date);
            $check_ts->execute();
            $ts_result = $check_ts->get_result();
            
            if ($ts_result->num_rows == 0) {
                if (!hasApprovedLeaveOnDate($conn, $user['id'], $check_date)) {
                    if (addLOPForMissingTimesheet($conn, $user['id'], $check_date)) {
                        $lop_added_count++;
                    }
                }
            }
            $check_ts->close();
        }
        
        $message = '<div class="alert alert-success"><i class="icon-success"></i> Checked for missing timesheets on ' . $check_date . '. Added ' . $lop_added_count . ' LOP entries (PM/HR/Admin users excluded).</div>';
    }
}

// Submit timesheet - WITH NOTIFICATION FOR LATE SUBMISSION
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_timesheet'])) {
    $date = sanitize($_POST['date']);
    $hours = floatval($_POST['hours']);
    $minutes = intval($_POST['minutes']);
    
    $total_hours = $hours + ($minutes / 60);
    
    // Handle software selection
    $software = '';
    $software_type = isset($_POST['software_type']) ? sanitize($_POST['software_type']) : 'dropdown';
    
    if ($software_type === 'manual' && isset($_POST['software_manual']) && !empty($_POST['software_manual'])) {
        $software = sanitize($_POST['software_manual']);
    } else {
        if (isset($_POST['software_ids']) && is_array($_POST['software_ids']) && !empty($_POST['software_ids'])) {
            $software = implode(', ', $_POST['software_ids']);
        } elseif (isset($_POST['software']) && !empty($_POST['software'])) {
            $software = sanitize($_POST['software']);
        }
    }
    
    // Handle project selection - KEEPING MANUAL ENTRY OPTION
    $project_id = null;
    $project_name = '';
    $project_type = isset($_POST['project_type']) ? sanitize($_POST['project_type']) : 'dropdown';
    
    if ($project_type === 'manual' && isset($_POST['project_name_manual']) && !empty($_POST['project_name_manual'])) {
        $project_name = sanitize($_POST['project_name_manual']);
        $project_id = null;
    } else {
        // Handle single project selection from dropdown
        if (isset($_POST['project_id']) && !empty($_POST['project_id']) && $_POST['project_id'] > 0) {
            $project_id = intval($_POST['project_id']);
            $stmt_project = $conn->prepare("SELECT project_name, project_code FROM projects WHERE id = ?");
            $stmt_project->bind_param("i", $project_id);
            $stmt_project->execute();
            $project_result = $stmt_project->get_result();
            $project_data = $project_result->fetch_assoc();
            if ($project_data) {
                $project_name = $project_data['project_name'];
                if (!empty($project_data['project_code'])) {
                    $project_name .= ' [' . $project_data['project_code'] . ']';
                }
            }
            $stmt_project->close();
        }
    }
    
    // Handle task selection
    $task_id = null;
    $task_name = '';
    $task_type = isset($_POST['task_type']) ? sanitize($_POST['task_type']) : 'dropdown';
    
    if ($task_type === 'manual' && isset($_POST['task_name_manual']) && !empty($_POST['task_name_manual'])) {
        $task_name = sanitize($_POST['task_name_manual']);
        $task_id = null;
    } else {
        if (isset($_POST['task_ids']) && is_array($_POST['task_ids']) && !empty($_POST['task_ids'])) {
            $task_ids = $_POST['task_ids'];
            $task_names = [];
            foreach ($task_ids as $tid) {
                $tid = intval($tid);
                if ($tid > 0 && $tasks_table_exists) {
                    $stmt_task = $conn->prepare("SELECT task_name FROM tasks WHERE id = ?");
                    $stmt_task->bind_param("i", $tid);
                    $stmt_task->execute();
                    $task_result = $stmt_task->get_result();
                    $task_data = $task_result->fetch_assoc();
                    if ($task_data) {
                        $task_names[] = $task_data['task_name'];
                    }
                    $stmt_task->close();
                }
            }
            $task_name = implode(', ', $task_names);
            $task_id = null;
        } elseif (isset($_POST['task_id']) && !empty($_POST['task_id']) && $_POST['task_id'] > 0) {
            $task_id = intval($_POST['task_id']);
            if ($tasks_table_exists) {
                $stmt_task = $conn->prepare("SELECT task_name FROM tasks WHERE id = ?");
                $stmt_task->bind_param("i", $task_id);
                $stmt_task->execute();
                $task_result = $stmt_task->get_result();
                $task_data = $task_result->fetch_assoc();
                $task_name = $task_data['task_name'];
                $stmt_task->close();
            }
        }
    }
    
    $remarks = sanitize($_POST['remarks']);
    $status = sanitize($_POST['status']);
    
    $approved_leave = hasApprovedLeaveOnDate($conn, $viewing_user_id, $date);
    if ($approved_leave) {
        $message = '<div class="alert alert-warning"><i class="icon-info"></i> You have approved leave on this date. Timesheet entry is not required for leave days.</div>';
    } else {
        $stmt_check = $conn->prepare("
            SELECT id FROM timesheets 
            WHERE user_id = ? AND entry_date = ?
        ");
        $stmt_check->bind_param("is", $viewing_user_id, $date);
        $stmt_check->execute();
        $check_result = $stmt_check->get_result();
        
        if ($check_result->num_rows > 0) {
            $message = '<div class="alert alert-error"><i class="icon-error"></i> You have already submitted a timesheet for this date. You can only submit one entry per day.</div>';
        } else {
            $submitted_date = date('Y-m-d H:i:s');
            $is_late = ($date < date('Y-m-d'));
            
            $stmt = $conn->prepare("
                INSERT INTO timesheets (user_id, entry_date, hours, software, project_id, project_name, task_id, task_name, remarks, status, submitted_date)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->bind_param("isdssisssss", $viewing_user_id, $date, $total_hours, $software, $project_id, $project_name, $task_id, $task_name, $remarks, $status, $submitted_date);
            
            if ($stmt->execute()) {
                $timesheet_id = $stmt->insert_id;
                
                // Create notification for managers/HR about timesheet submission
                createTimesheetNotification($conn, $timesheet_id, $viewing_user_id, 'submitted');
                
                if ($is_late) {
                    $message = '<div class="alert alert-warning"><i class="icon-warning"></i> Timesheet entry added successfully but marked as LATE SUBMISSION. Notification sent to managers.</div>';
                } else {
                    $message = '<div class="alert alert-success"><i class="icon-success"></i> Timesheet entry added successfully!</div>';
                }
                
                $redirect_url = "timesheet.php?from_date=" . urlencode($date) . "&to_date=" . urlencode($date);
                if (isset($_GET['user_id']) && $_GET['user_id'] != '') {
                    $redirect_url .= "&user_id=" . urlencode($_GET['user_id']);
                }
                header("Location: $redirect_url");
                exit();
            } else {
                $message = '<div class="alert alert-error"><i class="icon-error"></i> Error adding timesheet entry: ' . $stmt->error . '</div>';
            }
            $stmt->close();
        }
    }
}

// Edit timesheet entry
if (isset($_GET['edit'])) {
    $edit_id = intval($_GET['edit']);
    $today_date = date('Y-m-d');
    
    $stmt_edit = $conn->prepare("SELECT * FROM timesheets WHERE id = ?");
    $stmt_edit->bind_param("i", $edit_id);
    $stmt_edit->execute();
    $edit_result = $stmt_edit->get_result();
    $edit_entry = $edit_result->fetch_assoc();
    $stmt_edit->close();
    
    if ($edit_entry && $edit_entry['user_id'] == $user_id && $edit_entry['status'] != 'approved') {
        if ($edit_entry['entry_date'] == $today_date) {
            if (isHoliday($edit_entry['entry_date']) !== false) {
                $message = '<div class="alert alert-error"><i class="icon-error"></i> Cannot edit holiday entries.</div>';
            } else {
                $_SESSION['edit_timesheet'] = $edit_entry;
                $_SESSION['edit_id'] = $edit_id;
            }
        } else {
            $message = '<div class="alert alert-error"><i class="icon-error"></i> You can only edit timesheet entries for today\'s date.</div>';
        }
    } else {
        $message = '<div class="alert alert-error"><i class="icon-error"></i> You can only edit your own pending timesheet entries.</div>';
    }
}

// Update timesheet entry after edit
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_timesheet'])) {
    $edit_id = intval($_POST['edit_id']);
    $date = sanitize($_POST['date']);
    $hours = floatval($_POST['hours']);
    $minutes = intval($_POST['minutes']);
    $today_date = date('Y-m-d');
    
    $total_hours = $hours + ($minutes / 60);
    
    // Handle software selection
    $software = '';
    $software_type = isset($_POST['software_type']) ? sanitize($_POST['software_type']) : 'dropdown';
    
    if ($software_type === 'manual' && isset($_POST['software_manual']) && !empty($_POST['software_manual'])) {
        $software = sanitize($_POST['software_manual']);
    } else {
        if (isset($_POST['software_ids']) && is_array($_POST['software_ids']) && !empty($_POST['software_ids'])) {
            $software = implode(', ', $_POST['software_ids']);
        } elseif (isset($_POST['software']) && !empty($_POST['software'])) {
            $software = sanitize($_POST['software']);
        }
    }
    
    // Handle project selection - KEEPING MANUAL ENTRY OPTION
    $project_id = null;
    $project_name = '';
    $project_type = isset($_POST['project_type']) ? sanitize($_POST['project_type']) : 'dropdown';
    
    if ($project_type === 'manual' && isset($_POST['project_name_manual']) && !empty($_POST['project_name_manual'])) {
        $project_name = sanitize($_POST['project_name_manual']);
        $project_id = null;
    } else {
        // Handle single project selection from dropdown
        if (isset($_POST['project_id']) && !empty($_POST['project_id']) && $_POST['project_id'] > 0) {
            $project_id = intval($_POST['project_id']);
            $stmt_project = $conn->prepare("SELECT project_name, project_code FROM projects WHERE id = ?");
            $stmt_project->bind_param("i", $project_id);
            $stmt_project->execute();
            $project_result = $stmt_project->get_result();
            $project_data = $project_result->fetch_assoc();
            if ($project_data) {
                $project_name = $project_data['project_name'];
                if (!empty($project_data['project_code'])) {
                    $project_name .= ' [' . $project_data['project_code'] . ']';
                }
            }
            $stmt_project->close();
        }
    }
    
    // Handle task selection
    $task_id = null;
    $task_name = '';
    $task_type = isset($_POST['task_type']) ? sanitize($_POST['task_type']) : 'dropdown';
    
    if ($task_type === 'manual' && isset($_POST['task_name_manual']) && !empty($_POST['task_name_manual'])) {
        $task_name = sanitize($_POST['task_name_manual']);
        $task_id = null;
    } else {
        if (isset($_POST['task_ids']) && is_array($_POST['task_ids']) && !empty($_POST['task_ids'])) {
            $task_ids = $_POST['task_ids'];
            $task_names = [];
            foreach ($task_ids as $tid) {
                $tid = intval($tid);
                if ($tid > 0 && $tasks_table_exists) {
                    $stmt_task = $conn->prepare("SELECT task_name FROM tasks WHERE id = ?");
                    $stmt_task->bind_param("i", $tid);
                    $stmt_task->execute();
                    $task_result = $stmt_task->get_result();
                    $task_data = $task_result->fetch_assoc();
                    if ($task_data) {
                        $task_names[] = $task_data['task_name'];
                    }
                    $stmt_task->close();
                }
            }
            $task_name = implode(', ', $task_names);
            $task_id = null;
        } elseif (isset($_POST['task_id']) && !empty($_POST['task_id']) && $_POST['task_id'] > 0) {
            $task_id = intval($_POST['task_id']);
            if ($tasks_table_exists) {
                $stmt_task = $conn->prepare("SELECT task_name FROM tasks WHERE id = ?");
                $stmt_task->bind_param("i", $task_id);
                $stmt_task->execute();
                $task_result = $stmt_task->get_result();
                $task_data = $task_result->fetch_assoc();
                $task_name = $task_data['task_name'];
                $stmt_task->close();
            }
        }
    }
    
    $remarks = sanitize($_POST['remarks']);
    $status = sanitize($_POST['status']);
    $current_time = date('Y-m-d H:i:s');
    
    $check_owner = $conn->prepare("SELECT user_id, status, entry_date FROM timesheets WHERE id = ?");
    $check_owner->bind_param("i", $edit_id);
    $check_owner->execute();
    $owner_result = $check_owner->get_result();
    $owner_data = $owner_result->fetch_assoc();
    $check_owner->close();
    
    if ($owner_data && $owner_data['user_id'] == $user_id && $owner_data['status'] != 'approved') {
        if ($owner_data['entry_date'] == $today_date) {
            $stmt = $conn->prepare("
                UPDATE timesheets 
                SET entry_date = ?, hours = ?, software = ?, project_id = ?, project_name = ?, task_id = ?, task_name = ?, remarks = ?, status = ?, submitted_date = ?
                WHERE id = ?
            ");
            $stmt->bind_param("sdsissssssi", $date, $total_hours, $software, $project_id, $project_name, $task_id, $task_name, $remarks, $status, $current_time, $edit_id);
            
            if ($stmt->execute()) {
                $message = '<div class="alert alert-success"><i class="icon-success"></i> Timesheet entry updated successfully!</div>';
                
                unset($_SESSION['edit_timesheet']);
                unset($_SESSION['edit_id']);
                
                $redirect_url = "timesheet.php?from_date=" . urlencode($from_date) . "&to_date=" . urlencode($to_date);
                if (isset($_GET['user_id']) && $_GET['user_id'] != '') {
                    $redirect_url .= "&user_id=" . urlencode($_GET['user_id']);
                }
                header("Location: $redirect_url");
                exit();
            } else {
                $message = '<div class="alert alert-error"><i class="icon-error"></i> Error updating timesheet entry: ' . $stmt->error . '</div>';
            }
            $stmt->close();
        } else {
            $message = '<div class="alert alert-error"><i class="icon-error"></i> You can only edit timesheet entries for today\'s date.</div>';
        }
    } else {
        $message = '<div class="alert alert-error"><i class="icon-error"></i> You can only edit your own pending timesheet entries.</div>';
    }
}

// Cancel edit
if (isset($_GET['cancel_edit'])) {
    unset($_SESSION['edit_timesheet']);
    unset($_SESSION['edit_id']);
    $redirect_url = "timesheet.php?from_date=" . urlencode($from_date) . "&to_date=" . urlencode($to_date);
    if (isset($_GET['user_id']) && $_GET['user_id'] != '') {
        $redirect_url .= "&user_id=" . urlencode($_GET['user_id']);
    }
    header("Location: $redirect_url");
    exit();
}

// PM Approval action - WITH NOTIFICATION FOR EMPLOYEE (only for late entries)
if (isset($_GET['approve']) && in_array($role, ['pm', 'hr', 'admin'])) {
    $timesheet_id = intval($_GET['approve']);
    
    $get_ts = $conn->prepare("SELECT user_id, entry_date, hours, submitted_date FROM timesheets WHERE id = ?");
    $get_ts->bind_param("i", $timesheet_id);
    $get_ts->execute();
    $ts_result = $get_ts->get_result();
    $ts_data = $ts_result->fetch_assoc();
    $get_ts->close();
    
    if ($ts_data) {
        // Check if this is a late entry or a Sunday (Sundays always need approval)
        $is_late = isLateTimesheet($ts_data['entry_date'], $ts_data['submitted_date']);
        $is_sunday_entry = (date('N', strtotime($ts_data['entry_date'])) == 7);
        
        if (!$is_late && !$is_sunday_entry) {
            $message = '<div class="alert alert-error"><i class="icon-error"></i> Approval is only allowed for late timesheet submissions or Sunday work.</div>';
        } else if (isHoliday($ts_data['entry_date']) !== false) {
            $message = '<div class="alert alert-error"><i class="icon-error"></i> Cannot approve holiday entries.</div>';
        } else {
            $update = $conn->prepare("UPDATE timesheets SET status = 'approved' WHERE id = ?");
            $update->bind_param("i", $timesheet_id);
            
            if ($update->execute()) {
                $lop_removed = removeLOPForTimesheet($conn, $ts_data['user_id'], $ts_data['entry_date']);
                
                // Create notification for the employee that their timesheet is approved
                createTimesheetNotification($conn, $timesheet_id, $ts_data['user_id'], 'approved', $user_id);
                
                // Notify the approver
                $approver_title = "Timesheet Approved";
                $approver_msg = "You approved timesheet for {$ts_data['entry_date']}.";
                createNotification($conn, $user_id, 'timesheet_approved_by_you', $approver_title, $approver_msg, $timesheet_id);
                
                $is_sunday = (date('N', strtotime($ts_data['entry_date'])) == 7);
                if ($is_sunday) {
                    $casual_added = addCasualLeaveForSundayWork($conn, $ts_data['user_id'], $ts_data['entry_date'], $user_id);
                    if ($casual_added) {
                        $message = '<div class="alert alert-success"><i class="icon-success"></i> Timesheet approved successfully. LOP removed and 1 casual leave added. Notification sent to employee.</div>';
                    } else {
                        $message = '<div class="alert alert-success"><i class="icon-success"></i> Timesheet approved successfully. LOP removed. Notification sent to employee.</div>';
                    }
                } else {
                    if ($lop_removed) {
                        $message = '<div class="alert alert-success"><i class="icon-success"></i> Timesheet approved successfully. Auto-generated LOP has been removed. Notification sent to employee.</div>';
                    } else {
                        $message = '<div class="alert alert-success"><i class="icon-success"></i> Timesheet approved successfully. Notification sent to employee.</div>';
                    }
                }
                
                $redirect_url = "timesheet.php?from_date=" . urlencode($from_date) . "&to_date=" . urlencode($to_date);
                if (isset($_GET['user_id']) && $_GET['user_id'] != '') {
                    $redirect_url .= "&user_id=" . urlencode($_GET['user_id']);
                }
                header("Location: $redirect_url");
                exit();
            } else {
                $message = '<div class="alert alert-error"><i class="icon-error"></i> Error approving timesheet</div>';
            }
            $update->close();
        }
    }
}

// PM Rejection action - WITH NOTIFICATION FOR EMPLOYEE (only for late entries)
if (isset($_GET['reject']) && in_array($role, ['pm', 'hr', 'admin'])) {
    $timesheet_id = intval($_GET['reject']);
    $rejection_reason = isset($_GET['reason']) ? sanitize($_GET['reason']) : 'No reason provided';
    
    $get_ts = $conn->prepare("SELECT user_id, entry_date, hours, submitted_date FROM timesheets WHERE id = ?");
    $get_ts->bind_param("i", $timesheet_id);
    $get_ts->execute();
    $ts_result = $get_ts->get_result();
    $ts_data = $ts_result->fetch_assoc();
    $get_ts->close();
    
    if ($ts_data) {
        // Check if this is a late entry
        $is_late = isLateTimesheet($ts_data['entry_date'], $ts_data['submitted_date']);
        
        if (!$is_late) {
            $message = '<div class="alert alert-error"><i class="icon-error"></i> Rejection is only allowed for late timesheet submissions.</div>';
        } else if (isHoliday($ts_data['entry_date']) !== false) {
            $message = '<div class="alert alert-error"><i class="icon-error"></i> Cannot reject holiday entries.</div>';
        } else {
            $update = $conn->prepare("UPDATE timesheets SET status = 'rejected' WHERE id = ?");
            $update->bind_param("i", $timesheet_id);
            
            if ($update->execute()) {
                // Create notification for the employee that their timesheet is rejected
                createTimesheetNotification($conn, $timesheet_id, $ts_data['user_id'], 'rejected', $user_id, ['reason' => $rejection_reason]);
                
                // Notify the rejector
                $rejector_title = "Timesheet Rejected";
                $rejector_msg = "You rejected timesheet for {$ts_data['entry_date']}.";
                createNotification($conn, $user_id, 'timesheet_rejected_by_you', $rejector_title, $rejector_msg, $timesheet_id);
                
                $message = '<div class="alert alert-success"><i class="icon-success"></i> Timesheet rejected successfully. Notification sent to employee.</div>';
                
                $redirect_url = "timesheet.php?from_date=" . urlencode($from_date) . "&to_date=" . urlencode($to_date);
                if (isset($_GET['user_id']) && $_GET['user_id'] != '') {
                    $redirect_url .= "&user_id=" . urlencode($_GET['user_id']);
                }
                header("Location: $redirect_url");
                exit();
            } else {
                $message = '<div class="alert alert-error"><i class="icon-error"></i> Error rejecting timesheet</div>';
            }
            $update->close();
        }
    }
}

// Delete timesheet entry
if (isset($_GET['delete']) && in_array($role, ['pm', 'hr', 'admin'])) {
    $timesheet_id = intval($_GET['delete']);
    $delete_sunday_leave = isset($_GET['delete_sunday_leave']) ? true : false;
    
    $get_user_stmt = $conn->prepare("SELECT user_id, entry_date FROM timesheets WHERE id = ?");
    $get_user_stmt->bind_param("i", $timesheet_id);
    $get_user_stmt->execute();
    $get_user_result = $get_user_stmt->get_result();
    $timesheet_data = $get_user_result->fetch_assoc();
    $timesheet_user_id = $timesheet_data['user_id'] ?? 0;
    $entry_date = $timesheet_data['entry_date'] ?? '';
    $get_user_stmt->close();
    
    if (isHoliday($entry_date) !== false) {
        $message = '<div class="alert alert-error"><i class="icon-error"></i> Cannot delete holiday entries.</div>';
    } else {
        $conn->begin_transaction();
        try {
            $is_sunday = (date('N', strtotime($entry_date)) == 7);
            
            // Get user info for notification
            $user_stmt = $conn->prepare("SELECT full_name FROM users WHERE id = ?");
            $user_stmt->bind_param("i", $timesheet_user_id);
            $user_stmt->execute();
            $user_result = $user_stmt->get_result();
            $user_data = $user_result->fetch_assoc();
            $user_stmt->close();
            
            // Get deleter info for notification
            $deleter_stmt = $conn->prepare("SELECT full_name FROM users WHERE id = ?");
            $deleter_stmt->bind_param("i", $user_id);
            $deleter_stmt->execute();
            $deleter_result = $deleter_stmt->get_result();
            $deleter_data = $deleter_result->fetch_assoc();
            $deleter_stmt->close();
            
            // Create notification for the employee
            $title = "Timesheet Deleted";
            $msg = "Your timesheet for {$entry_date} has been deleted by {$deleter_data['full_name']}.";
            createNotification($conn, $timesheet_user_id, 'timesheet_deleted', $title, $msg, $timesheet_id);
            
            // Notify all HR/Admin/PM about the deletion
            $mgmt_users = $conn->query("SELECT id FROM users WHERE role IN ('hr', 'admin', 'pm') AND id NOT IN ($timesheet_user_id, $user_id)");
            while ($mgmt_user = $mgmt_users->fetch_assoc()) {
                $mgmt_title = "Timesheet Deleted";
                $mgmt_msg = "{$user_data['full_name']}'s timesheet for {$entry_date} was deleted by {$deleter_data['full_name']}.";
                createNotification($conn, $mgmt_user['id'], 'timesheet_deleted', $mgmt_title, $mgmt_msg, $timesheet_id);
            }
            
            if ($is_sunday && $delete_sunday_leave) {
                $delete_leave = $conn->prepare("
                    DELETE FROM leaves 
                    WHERE user_id = ? AND from_date = ? 
                    AND leave_type = 'Casual' 
                    AND reason LIKE '%SUNDAY WORK BONUS%'
                ");
                $delete_leave->bind_param("is", $timesheet_user_id, $entry_date);
                $delete_leave->execute();
                $leave_deleted = $delete_leave->affected_rows;
                $delete_leave->close();
                
                if ($leave_deleted > 0) {
                    $message_part = " and 1 Sunday work leave entry removed";
                } else {
                    $message_part = "";
                }
            } else {
                $message_part = "";
            }
            
            $stmt = $conn->prepare("DELETE FROM timesheets WHERE id = ?");
            $stmt->bind_param("i", $timesheet_id);
            
            if ($stmt->execute()) {
                $current_month_key = getMonthKeyFromDate($entry_date);
                $delete_carry = $conn->prepare("DELETE FROM casual_leave_carryforward WHERE user_id = ? AND month_key = ?");
                $delete_carry->bind_param("is", $timesheet_user_id, $current_month_key);
                $delete_carry->execute();
                $delete_carry->close();
                
                $delete_all_carry = $conn->prepare("DELETE FROM casual_leave_carryforward WHERE user_id = ?");
                $delete_all_carry->bind_param("i", $timesheet_user_id);
                $delete_all_carry->execute();
                $delete_all_carry->close();
                
                $conn->commit();
                
                $message = '<div class="alert alert-success"><i class="icon-success"></i> Timesheet entry deleted successfully' . $message_part . '. Notification sent to employee.</div>';
                
                $redirect_url = "timesheet.php?from_date=" . urlencode($from_date) . "&to_date=" . urlencode($to_date);
                if (isset($_GET['user_id']) && $_GET['user_id'] != '') {
                    $redirect_url .= "&user_id=" . urlencode($_GET['user_id']);
                }
                header("Location: $redirect_url");
                exit();
            } else {
                throw new Exception("Error deleting timesheet");
            }
        } catch (Exception $e) {
            $conn->rollback();
            $message = '<div class="alert alert-error"><i class="icon-error"></i> Error: ' . $e->getMessage() . '</div>';
        }
        $stmt->close();
    }
} elseif (isset($_GET['delete']) && !in_array($role, ['pm', 'hr', 'admin'])) {
    $message = '<div class="alert alert-error"><i class="icon-error"></i> You do not have permission to delete timesheet entries.</div>';
}

// Auto-check for previous day every time page loads - BUT SKIP FOR PM/HR/ADMIN USERS
if (in_array($role, ['hr', 'admin', 'pm'])) {
    $yesterday = date('Y-m-d', strtotime('-1 day'));
    
    if (date('N', strtotime($yesterday)) == 7) {
        // Sunday - no LOP
    }
    else if (isHoliday($yesterday) !== false) {
        // Holiday - no LOP
    }
    else {
        $users_result = $conn->query("SELECT id, username, role, status FROM users");
        $lop_added_count = 0;
        
        while ($user = $users_result->fetch_assoc()) {
            // SKIP PM/HR/ADMIN users - they don't get LOP
            if (in_array($user['role'], ['hr', 'admin', 'pm'])) {
                continue;
            }
            
            // Skip users with status = 'inactive'
            if ($user['status'] === 'inactive') {
                continue;
            }
            
            $check_ts = $conn->prepare("SELECT id FROM timesheets WHERE user_id = ? AND entry_date = ?");
            $check_ts->bind_param("is", $user['id'], $yesterday);
            $check_ts->execute();
            $ts_result = $check_ts->get_result();
            
            if ($ts_result->num_rows == 0) {
                if (!hasApprovedLeaveOnDate($conn, $user['id'], $yesterday)) {
                    if (addLOPForMissingTimesheet($conn, $user['id'], $yesterday)) {
                        $lop_added_count++;
                    }
                }
            }
            $check_ts->close();
        }
        
        if ($lop_added_count > 0) {
            $message = '<div class="alert alert-info"><i class="icon-info"></i> Auto-check completed: ' . $lop_added_count . ' LOP entries added for ' . $yesterday . ' (PM/HR/Admin users excluded).</div>';
        }
    }
}

// Get timesheet entries
if (in_array($role, ['hr', 'admin', 'pm'])) {
    if (isset($_GET['user_id']) && $_GET['user_id'] != '') {
        $stmt = $conn->prepare("
            SELECT t.*, u.full_name as employee_name, u.username, u.department, u.position,
                   p.project_name as project_name_from_projects, p.project_code,
                   tk.task_name as task_name_from_tasks, tk.description as task_description,
                   (SELECT COUNT(*) FROM leaves WHERE user_id = t.user_id AND from_date = t.entry_date AND leave_type = 'LOP' AND reason LIKE 'Auto-generated LOP%') as has_lop,
                   (SELECT COUNT(*) FROM leaves WHERE user_id = t.user_id AND from_date = t.entry_date AND leave_type = 'Casual' AND reason LIKE '%SUNDAY WORK BONUS%') as has_sunday_leave,
                   CASE WHEN t.submitted_date > CONCAT(t.entry_date, ' 23:59:59') THEN 1 ELSE 0 END as is_late
            FROM timesheets t 
            JOIN users u ON t.user_id = u.id 
            LEFT JOIN projects p ON t.project_id = p.id
            LEFT JOIN tasks tk ON t.task_id = tk.id
            WHERE t.user_id = ? AND t.entry_date BETWEEN ? AND ? 
            ORDER BY t.entry_date DESC, t.submitted_date DESC
        ");
        $stmt->bind_param("iss", $viewing_user_id, $from_date, $to_date);
    } else {
        $stmt = $conn->prepare("
            SELECT t.*, u.full_name as employee_name, u.username, u.department, u.position,
                   p.project_name as project_name_from_projects, p.project_code,
                   tk.task_name as task_name_from_tasks, tk.description as task_description,
                   (SELECT COUNT(*) FROM leaves WHERE user_id = t.user_id AND from_date = t.entry_date AND leave_type = 'LOP' AND reason LIKE 'Auto-generated LOP%') as has_lop,
                   (SELECT COUNT(*) FROM leaves WHERE user_id = t.user_id AND from_date = t.entry_date AND leave_type = 'Casual' AND reason LIKE '%SUNDAY WORK BONUS%') as has_sunday_leave,
                   CASE WHEN t.submitted_date > CONCAT(t.entry_date, ' 23:59:59') THEN 1 ELSE 0 END as is_late
            FROM timesheets t 
            JOIN users u ON t.user_id = u.id 
            LEFT JOIN projects p ON t.project_id = p.id
            LEFT JOIN tasks tk ON t.task_id = tk.id
            WHERE t.entry_date BETWEEN ? AND ? 
            ORDER BY u.full_name, t.entry_date DESC, t.submitted_date DESC
        ");
        $stmt->bind_param("ss", $from_date, $to_date);
    }
} else {
    $stmt = $conn->prepare("
        SELECT t.*, p.project_name as project_name_from_projects, p.project_code,
               tk.task_name as task_name_from_tasks, tk.description as task_description,
               (SELECT COUNT(*) FROM leaves WHERE user_id = t.user_id AND from_date = t.entry_date AND leave_type = 'LOP' AND reason LIKE 'Auto-generated LOP%') as has_lop,
               (SELECT COUNT(*) FROM leaves WHERE user_id = t.user_id AND from_date = t.entry_date AND leave_type = 'Casual' AND reason LIKE '%SUNDAY WORK BONUS%') as has_sunday_leave,
               CASE WHEN t.submitted_date > CONCAT(t.entry_date, ' 23:59:59') THEN 1 ELSE 0 END as is_late
        FROM timesheets t 
        LEFT JOIN projects p ON t.project_id = p.id
        LEFT JOIN tasks tk ON t.task_id = tk.id
        WHERE t.user_id = ? AND t.entry_date BETWEEN ? AND ? 
        ORDER BY t.entry_date DESC, t.submitted_date DESC
    ");
    $stmt->bind_param("iss", $viewing_user_id, $from_date, $to_date);
}
$stmt->execute();
$timesheets = $stmt->get_result();

// Calculate total hours
if (in_array($role, ['hr', 'admin', 'pm'])) {
    if (isset($_GET['user_id']) && $_GET['user_id'] != '') {
        $stmt_total = $conn->prepare("
            SELECT COALESCE(SUM(hours), 0) as total_hours 
            FROM timesheets 
            WHERE user_id = ? AND entry_date BETWEEN ? AND ?
            AND DAYOFWEEK(entry_date) != 1
        ");
        $stmt_total->bind_param("iss", $viewing_user_id, $from_date, $to_date);
    } else {
        $stmt_total = $conn->prepare("
            SELECT COALESCE(SUM(hours), 0) as total_hours 
            FROM timesheets 
            WHERE entry_date BETWEEN ? AND ?
            AND DAYOFWEEK(entry_date) != 1
        ");
        $stmt_total->bind_param("ss", $from_date, $to_date);
    }
} else {
    $stmt_total = $conn->prepare("
        SELECT COALESCE(SUM(hours), 0) as total_hours 
        FROM timesheets 
        WHERE user_id = ? AND entry_date BETWEEN ? AND ?
    ");
    $stmt_total->bind_param("iss", $viewing_user_id, $from_date, $to_date);
}
$stmt_total->execute();
$total_result = $stmt_total->get_result();
$total_row = $total_result->fetch_assoc();
$total_hours = $total_row['total_hours'];
$stmt_total->close();

// Get LOP count
$lop_count = 0;
if (in_array($role, ['hr', 'admin', 'pm'])) {
    if (isset($_GET['user_id']) && $_GET['user_id'] != '') {
        $stmt_lop = $conn->prepare("
            SELECT COUNT(*) as lop_count 
            FROM leaves 
            WHERE user_id = ? AND from_date BETWEEN ? AND ? 
            AND leave_type = 'LOP' AND reason LIKE 'Auto-generated LOP%'
            AND DAYOFWEEK(from_date) != 1
        ");
        $stmt_lop->bind_param("iss", $viewing_user_id, $from_date, $to_date);
    } else {
        $stmt_lop = $conn->prepare("
            SELECT COUNT(*) as lop_count 
            FROM leaves 
            WHERE from_date BETWEEN ? AND ? 
            AND leave_type = 'LOP' AND reason LIKE 'Auto-generated LOP%'
            AND DAYOFWEEK(from_date) != 1
        ");
        $stmt_lop->bind_param("ss", $from_date, $to_date);
    }
    $stmt_lop->execute();
    $lop_result = $stmt_lop->get_result();
    $lop_row = $lop_result->fetch_assoc();
    $lop_count = $lop_row['lop_count'] ?? 0;
    $stmt_lop->close();
}

// Get total submissions
$submissions_count = 0;
$working_days_in_range = countWorkingDays($from_date, $to_date);
if (in_array($role, ['hr', 'admin', 'pm'])) {
    if (isset($_GET['user_id']) && $_GET['user_id'] != '') {
        $submissions_stmt = $conn->prepare("
            SELECT COUNT(DISTINCT entry_date) as submission_count 
            FROM timesheets 
            WHERE user_id = ? AND entry_date BETWEEN ? AND ?
        ");
        $submissions_stmt->bind_param("iss", $viewing_user_id, $from_date, $to_date);
    } else {
        $submissions_stmt = $conn->prepare("
            SELECT COUNT(*) as submission_count 
            FROM timesheets 
            WHERE entry_date BETWEEN ? AND ?
        ");
        $submissions_stmt->bind_param("ss", $from_date, $to_date);
    }
    $submissions_stmt->execute();
    $submissions_result = $submissions_stmt->get_result();
    $submissions_row = $submissions_result->fetch_assoc();
    $submissions_count = $submissions_row['submission_count'] ?? 0;
    $submissions_stmt->close();
    
    if (!isset($_GET['user_id']) || $_GET['user_id'] == '') {
        $employees_stmt = $conn->prepare("
            SELECT COUNT(DISTINCT user_id) as employee_count 
            FROM timesheets 
            WHERE entry_date BETWEEN ? AND ?
        ");
        $employees_stmt->bind_param("ss", $from_date, $to_date);
        $employees_stmt->execute();
        $employees_result = $employees_stmt->get_result();
        $employees_row = $employees_result->fetch_assoc();
        $unique_employees = $employees_row['employee_count'] ?? 0;
        $employees_stmt->close();
    } else {
        $unique_employees = 1;
    }
}

// Get all users for dropdown
$users = [];
if (in_array($role, ['hr', 'admin', 'pm'])) {
    $users_result = $conn->query("SELECT id, username, full_name FROM users ORDER BY full_name");
    if ($users_result) {
        $users = $users_result->fetch_all(MYSQLI_ASSOC);
    }
}

// Get all projects for dropdown
$projects = [];
$projects_query = "SELECT id, project_name, project_code FROM projects WHERE status = 'active' ORDER BY project_name";
$projects_result = $conn->query($projects_query);
if ($projects_result && $projects_result->num_rows > 0) {
    while ($row = $projects_result->fetch_assoc()) {
        $projects[] = $row;
    }
} else {
    error_log("No active projects found in database");
}

// Software options
$software_options = ['Cyclone', 'Revit(cad to bim)','Revit(scan to bim)' ,'SP3D', 'AutoCAD', 'HR', 'Admin', 'Other', 'Training'];

// Set page title
$page_title = 'Timesheet';
if (in_array($role, ['hr', 'admin', 'pm'])) {
    if ($role === 'pm') {
        $page_title = 'Project Manager - Timesheet Overview';
    } elseif ($role === 'hr') {
        $page_title = 'HR - Timesheet Overview';
    } elseif ($role === 'admin') {
        $page_title = 'Admin - Timesheet Overview';
    }
}

// Get selected user display name
$selected_user_name = 'All Employees';
if (isset($_GET['user_id']) && $_GET['user_id'] != '' && in_array($role, ['hr', 'admin', 'pm'])) {
    foreach ($users as $u) {
        if ($u['id'] == $viewing_user_id) {
            $selected_user_name = $u['full_name'];
            break;
        }
    }
}

// TASKS - Get all tasks from database
$all_tasks = [];
if ($tasks_table_exists) {
    $tasks_query = "SELECT id, task_name, category, description, status FROM tasks WHERE status = 'active' ORDER BY 
        CASE 
            WHEN task_name LIKE 'Electrical%' THEN 1
            WHEN task_name LIKE 'Cable Trays%' THEN 2
            WHEN task_name LIKE 'Support%' THEN 3
            WHEN task_name LIKE 'Railings%' THEN 4
            WHEN task_name LIKE 'Architecture%' THEN 5
            WHEN task_name LIKE 'Stairs%' THEN 6
            WHEN task_name LIKE 'Equipment%' THEN 7
            WHEN task_name LIKE 'Piping%' THEN 8
            WHEN task_name LIKE 'Structure%' THEN 9
            WHEN task_name LIKE 'HVAC%' THEN 10
            ELSE 11
        END, task_name ASC";
    
    $tasks_result = $conn->query($tasks_query);
    if ($tasks_result) {
        while ($row = $tasks_result->fetch_assoc()) {
            $all_tasks[] = $row;
        }
    }
    
    // If no tasks found, insert default ones
    if (count($all_tasks) == 0) {
        $default_tasks = [
            ['Electrical', 'Engineering'],
            ['Cable Trays', 'Engineering'],
            ['Support', 'Engineering'],
            ['Railings', 'Structural'],
            ['Architecture', 'Design'],
            ['Stairs', 'Structural'],
            ['Equipment', 'Engineering'],
            ['Piping', 'Mechanical'],
            ['Structure', 'Structural'],
            ['HVAC', 'Mechanical']
        ];
        
        foreach ($default_tasks as $task) {
            $check = $conn->query("SELECT id FROM tasks WHERE task_name = '{$task[0]}' LIMIT 1");
            if ($check->num_rows == 0) {
                $insert = $conn->prepare("INSERT INTO tasks (task_name, category, status) VALUES (?, ?, 'active')");
                $insert->bind_param("ss", $task[0], $task[1]);
                $insert->execute();
                $insert->close();
            }
        }
        
        // Fetch again after inserting
        $tasks_result = $conn->query($tasks_query);
        if ($tasks_result) {
            while ($row = $tasks_result->fetch_assoc()) {
                $all_tasks[] = $row;
            }
        }
    }
}

$unique_tasks_count = count($all_tasks);

// ============================================
// EXCEL EXPORT FUNCTIONALITY
// ============================================
if (isset($_GET['export_excel']) && in_array($role, ['hr', 'admin', 'pm'])) {
    $export_from = isset($_GET['export_from']) ? sanitize($_GET['export_from']) : $from_date;
    $export_to = isset($_GET['export_to']) ? sanitize($_GET['export_to']) : $to_date;
    $export_user_id = isset($_GET['export_user_id']) ? intval($_GET['export_user_id']) : 0;
    
    // Build filename from date range and user
    $from_fmt = date('d-m-Y', strtotime($export_from));
    $to_fmt   = date('d-m-Y', strtotime($export_to));
    if ($export_user_id > 0) {
        $uname_stmt = $conn->prepare("SELECT full_name FROM users WHERE id = ?");
        $uname_stmt->bind_param("i", $export_user_id);
        $uname_stmt->execute();
        $uname_row = $uname_stmt->get_result()->fetch_assoc();
        $uname_stmt->close();
        $user_label = $uname_row ? preg_replace('/[^A-Za-z0-9_\-]/', '_', $uname_row['full_name']) : 'user';
        $ts_filename = $from_fmt . '_to_' . $to_fmt . '_' . $user_label . '.xls';
    } else {
        $ts_filename = $from_fmt . '_to_' . $to_fmt . '_all.xls';
    }

    // Set headers for Excel download
    header('Content-Type: application/vnd.ms-excel');
    header('Content-Disposition: attachment; filename="' . $ts_filename . '"');
    header('Cache-Control: max-age=0');
    header('Pragma: public');
    header('X-Download-By: Microsoft Office Professional Plus Build 17928.20148');
    
    // Get all users for the period with department info
    $users_query = "SELECT id, username, full_name, department, position FROM users";
    if ($export_user_id > 0) {
        $users_query .= " WHERE id = " . intval($export_user_id);
    }
    $users_query .= " ORDER BY full_name";
    $all_users_result = $conn->query($users_query);
    $all_users = $all_users_result->fetch_all(MYSQLI_ASSOC);
    
    // Start output
    echo '<html>';
    echo '<head>';
    echo '<meta charset="UTF-8">';
    echo '<style>';
    echo 'body { font-family: Arial, sans-serif; margin: 20px; }';
    echo 'h2 { color: #006400; margin-bottom: 5px; }';
    echo 'h3 { color: #006400; margin-top: 20px; margin-bottom: 10px; border-bottom: 2px solid #006400; padding-bottom: 5px; }';
    echo 'table { border-collapse: collapse; width: 100%; margin-bottom: 20px; font-size: 12px; }';
    echo 'th { background: #006400; color: white; font-weight: bold; text-align: center; padding: 8px; border: 1px solid #004d00; }';
    echo 'td { padding: 6px; border: 1px solid #cccccc; vertical-align: top; }';
    echo '.date { mso-number-format:"yyyy-mm-dd"; }';
    echo '.subheader { background: #e8f5e9; font-weight: bold; }';
    echo '.applied { background: #d4edda; }';
    echo '.not-applied { background: #fff3cd; }';
    echo '.lop { background: #f8d7da; color: #721c24; font-weight: bold; }';
    echo '.leave { background: #cce5ff; color: #004085; font-weight: bold; }';
    echo '.holiday { background: #f3e8ff; color: #6b21a8; font-weight: bold; }';
    echo '.sunday-work { background: #fff3cd; color: #ed8936; font-weight: bold; }';
    echo '.total { background: #f0f0f0; font-weight: bold; }';
    echo '.sunday { background: #e2e8f0; color: #718096; font-style: italic; }';
    echo '.project-code { color: #006400; font-size: 10px; }';
    echo '.department-badge { background: #e2e8f0; padding: 2px 5px; border-radius: 3px; font-size: 10px; }';
    echo '.summary-box { background: #f8f9fa; border: 2px solid #006400; padding: 15px; margin-bottom: 20px; border-radius: 5px; }';
    echo '.summary-row { display: flex; margin-bottom: 5px; }';
    echo '.summary-label { font-weight: bold; width: 150px; }';
    echo '.summary-value { flex: 1; }';
    echo '.employee-name { font-weight: bold; color: #2d3748; }';
    echo '.project-name { color: #006400; }';
    echo '.task-list { color: #2d3748; }';
    echo '.status-approved { background: #48bb78; color: white; padding: 2px 5px; border-radius: 3px; }';
    echo '.status-pending { background: #ed8936; color: white; padding: 2px 5px; border-radius: 3px; }';
    echo '.leave-badge { background: #004085; color: white; padding: 2px 5px; border-radius: 3px; }';
    echo '.holiday-badge { background: #9b59b6; color: white; padding: 2px 5px; border-radius: 3px; }';
    echo '.sunday-badge { background: #ed8936; color: white; padding: 2px 5px; border-radius: 3px; }';
    echo '</style>';
    echo '</head>';
    echo '<body>';
    
    // Title and Report Info
    echo '<h2>🏢 MAKSIM HR - Timesheet Report</h2>';
    echo '<div class="summary-box">';
    echo '<div class="summary-row"><span class="summary-label">Period:</span><span class="summary-value">' . date('d-m-Y', strtotime($export_from)) . ' to ' . date('d-m-Y', strtotime($export_to)) . ' (Excluding Holidays)</span></div>';
    echo '<div class="summary-row"><span class="summary-label">Generated on:</span><span class="summary-value">' . date('d-m-Y H:i:s') . ' IST</span></div>';
    echo '<div class="summary-row"><span class="summary-label">Report Type:</span><span class="summary-value">' . ($export_user_id > 0 ? 'Individual Employee' : 'All Employees') . '</span></div>';
    echo '</div>';
    
    // Get all dates in the range
    $start_date = new DateTime($export_from);
    $end_date = new DateTime($export_to);
    $end_date->modify('+1 day');
    $date_interval = new DateInterval('P1D');
    $date_range = new DatePeriod($start_date, $date_interval, $end_date);
    
    $dates = [];
    $working_dates = [];
    $sunday_dates = [];
    $holiday_dates = [];
    foreach ($date_range as $date) {
        $date_str = $date->format('Y-m-d');
        $dates[] = $date_str;
        if (isHoliday($date_str) !== false) {
            $holiday_dates[] = $date_str;
        } elseif ($date->format('N') == 7) {
            $sunday_dates[] = $date_str;
        } else {
            $working_dates[] = $date_str;
        }
    }
    
    // Get all timesheets for the period with project details
    $timesheets_query = "SELECT t.*, u.full_name, u.username, u.department, u.position,
                                p.project_name, p.project_code
                         FROM timesheets t 
                         JOIN users u ON t.user_id = u.id 
                         LEFT JOIN projects p ON t.project_id = p.id
                         WHERE t.entry_date BETWEEN '$export_from' AND '$export_to'";
    if ($export_user_id > 0) {
        $timesheets_query .= " AND t.user_id = $export_user_id";
    }
    $timesheets_query .= " ORDER BY u.full_name, t.entry_date";
    
    $timesheets_result = $conn->query($timesheets_query);
    $timesheets_data = [];
    $user_departments = [];
    while ($row = $timesheets_result->fetch_assoc()) {
        $user_key = $row['full_name'] . ' (ID:' . $row['user_id'] . ')';
        $date_key = $row['entry_date'];
        $timesheets_data[$user_key][$date_key] = $row;
        $user_departments[$user_key] = $row['department'] ?? '';
    }
    
    // Get all LOP entries for the period
    $lop_query = "SELECT l.*, u.full_name, u.department 
                  FROM leaves l 
                  JOIN users u ON l.user_id = u.id 
                  WHERE l.leave_type = 'LOP' 
                  AND l.reason LIKE 'Auto-generated LOP%'
                  AND l.from_date BETWEEN '$export_from' AND '$export_to'";
    if ($export_user_id > 0) {
        $lop_query .= " AND l.user_id = $export_user_id";
    }
    $lop_result = $conn->query($lop_query);
    $lop_data = [];
    while ($row = $lop_result->fetch_assoc()) {
        $user_key = $row['full_name'] . ' (ID:' . $row['user_id'] . ')';
        $date_key = $row['from_date'];
        $lop_data[$user_key][$date_key] = $row;
    }
    
    // Get all approved leaves for the period (non-LOP leaves, excluding Sunday Work Bonus)
    $leaves_query = "SELECT l.*, u.full_name, u.department 
                     FROM leaves l 
                     JOIN users u ON l.user_id = u.id 
                     WHERE l.status = 'Approved' 
                     AND l.leave_type IN ('Sick', 'Casual', 'Other')
                     AND l.reason NOT LIKE '%SUNDAY WORK BONUS%'
                     AND l.from_date BETWEEN '$export_from' AND '$export_to'";
    if ($export_user_id > 0) {
        $leaves_query .= " AND l.user_id = $export_user_id";
    }
    $leaves_result = $conn->query($leaves_query);
    $leaves_data = [];
    while ($row = $leaves_result->fetch_assoc()) {
        $user_key = $row['full_name'] . ' (ID:' . $row['user_id'] . ')';
        $from_date = $row['from_date'];
        $to_date = $row['to_date'];
        
        // For each day in the leave range
        $current = new DateTime($from_date);
        $end = new DateTime($to_date);
        $end->modify('+1 day');
        while ($current < $end) {
            $date_str = $current->format('Y-m-d');
            if (isHoliday($date_str) === false) {
                $leaves_data[$user_key][$date_str] = $row;
            }
            $current->modify('+1 day');
        }
    }
    
    // Get all holidays for the period
    $holidays_list = getHolidaysForYear(date('Y', strtotime($export_from)));
    $holidays_data = [];
    foreach ($holidays_list as $date => $name) {
        if ($date >= $export_from && $date <= $export_to) {
            foreach ($all_users as $user) {
                $user_key = $user['full_name'] . ' (ID:' . $user['id'] . ')';
                $holidays_data[$user_key][$date] = $name;
            }
        }
    }
    
    // ===== SUMMARY STATISTICS =====
    echo '<h3>📊 Summary Statistics (Excluding Holidays)</h3>';
    echo '<table>';
    echo '<tr><th style="width: 300px;">Metric</th><th>Count</th></tr>';
    
    $total_users = count($all_users);
    $total_working_dates = count($working_dates);
    $total_sunday_dates = count($sunday_dates);
    $total_holiday_dates = count($holiday_dates);
    $total_possible_entries = $total_users * $total_working_dates;
    $total_actual_entries = $timesheets_result->num_rows;
    $total_lop_entries = 0;
    foreach ($lop_data as $user_lops) {
        $total_lop_entries += count($user_lops);
    }
    $total_leave_entries = 0;
    foreach ($leaves_data as $user_leaves) {
        $total_leave_entries += count($user_leaves);
    }
    $total_sunday_submissions = 0;
    $total_workday_submissions = 0; // Mon-Sat only (excludes Sundays)
    foreach ($timesheets_data as $user_key => $user_timesheets) {
        foreach ($user_timesheets as $date => $entry) {
            if (date('N', strtotime($date)) == 7) {
                if ($entry['status'] === 'approved') {
                    $total_sunday_submissions++;
                }
            } else {
                $total_workday_submissions++; // Only Mon-Sat count toward submission rate
            }
        }
    }
    
    echo '<tr><td>Total Employees</td><td><strong>' . $total_users . '</strong></td></tr>';
    echo '<tr><td>Working Days (Mon-Sat, Excluding Holidays)</td><td><strong>' . $total_working_dates . '</strong></td></tr>';
    echo '<tr><td>Sundays (Optional Work - Extra Casual Leave)</td><td><strong>' . $total_sunday_dates . '</strong></td></tr>';
    echo '<tr><td>Holidays</td><td><strong>' . $total_holiday_dates . '</strong></td></tr>';
    echo '<tr><td>Total Possible Entries (Working Days)</td><td><strong>' . $total_possible_entries . '</strong></td></tr>';
    echo '<tr><td>Total Timesheet Submissions (Working Days)</td><td><strong>' . $total_workday_submissions . '</strong></td></tr>';
    echo '<tr><td>Sunday Work Submissions</td><td><strong class="sunday-work">' . $total_sunday_submissions . '</strong></td></tr>';
    echo '<tr><td>Total Approved Leave Days</td><td><strong class="leave">' . $total_leave_entries . '</strong></td></tr>';
    echo '<tr><td>Total Holiday Days (No Timesheet)</td><td><strong class="holiday">' . $total_holiday_dates . '</strong></td></tr>';
    echo '<tr><td>Total Auto LOP Entries (Missing)</td><td><strong class="lop">' . $total_lop_entries . '</strong></td></tr>';
    // Approved Sundays count in both numerator AND denominator so rate stays accurate
    $effective_submitted = $total_workday_submissions + $total_sunday_submissions;
    $effective_required  = $total_possible_entries - $total_leave_entries + $total_sunday_submissions;
    $submission_rate = $effective_required > 0 ? round(($effective_submitted / $effective_required) * 100, 2) : 0;
    echo '<tr><td>Submission Rate (Working Days)</td><td><strong>' . $submission_rate . '%</strong></td></tr>';
    echo '</table>';
    
    // ===== USER SUMMARY =====
    echo '<h3>Employee Summary</h3>';
    echo '<table border="1" cellpadding="5">';
    echo '<tr class="header">';
    echo '<th>Employee</th>';
    echo '<th>Department</th>';
    echo '<th>Position</th>';
    echo '<th>Working Days Submitted</th>';
    echo '<th>Sunday Work</th>';
    echo '<th>Total Hours</th>';
    echo '<th>Leave Days</th>';
    echo '<th>Auto LOP Days</th>';
    echo '<th>Submission %</th>';
    echo '</tr>';
    
    foreach ($all_users as $user) {
        $user_key = $user['full_name'] . ' (ID:' . $user['id'] . ')';
        
        // Count submissions for this user
        $user_submissions = 0;
        $user_sunday_submissions = 0;
        $user_hours = 0;
        if (isset($timesheets_data[$user_key])) {
            foreach ($timesheets_data[$user_key] as $date => $entry) {
                $user_hours += floatval($entry['hours']);
                if (date('N', strtotime($date)) == 7) {
                    // Sunday: only count if approved by PM
                    if ($entry['status'] === 'approved') {
                        $user_sunday_submissions++;
                        $user_submissions++;
                    }
                    // Non-approved Sunday: skip entirely
                } else {
                    // Mon-Sat: always count
                    $user_submissions++;
                }
            }
        }
        
        // Count LOP for this user
        $user_lop_days = isset($lop_data[$user_key]) ? count($lop_data[$user_key]) : 0;
        
        // Count leave days for this user
        $user_leave_days = isset($leaves_data[$user_key]) ? count($leaves_data[$user_key]) : 0;
        
        // Add approved Sundays to denominator so 6/6=100% not 6/5=120%
        $working_days_required = count($working_dates) - $user_leave_days + $user_sunday_submissions;
        $submission_percent = $working_days_required > 0 ? round($user_submissions / $working_days_required * 100, 1) : 100;
        $status_class = $user_submissions == $working_days_required ? 'applied' : ($user_submissions > 0 ? '' : 'not-applied');
        
        echo '<tr class="' . $status_class . '">';
        echo '<td><strong>' . htmlspecialchars($user['full_name']) . '</strong></td>';
        echo '<td>' . htmlspecialchars($user['department'] ?? '-') . '</td>';
        echo '<td>' . htmlspecialchars($user['position'] ?? '-') . '</td>';
        echo '<td style="mso-number-format:\@;">' . $user_submissions . '/' . $working_days_required . '</td>';
        echo '<td class="sunday-work">' . $user_sunday_submissions . '</td>';
        echo '<td>' . number_format($user_hours, 2) . ' hrs</td>';
        echo '<td class="leave">' . $user_leave_days . '</td>';
        echo '<td class="lop">' . $user_lop_days . '</td>';
        echo '<td>' . $submission_percent . '%</td>';
        echo '</tr>';
    }
    echo '</table>';
    
    // ===== DETAILED DAILY BREAKDOWN =====
echo '<h3>Daily Timesheet Status (Holidays Excluded)</h3>';
echo '<p><span style="background: #d4edda; padding: 2px 8px;">■ Submitted (Working Day)</span> ';
echo '<span style="background: #fff3cd; padding: 2px 8px;">■ Sunday Work (+1 Casual Leave)</span> ';
echo '<span style="background: #cce5ff; padding: 2px 8px;">■ Approved Leave</span> ';
echo '<span style="background: #f3e8ff; padding: 2px 8px;">■ Holiday (No Timesheet)</span> ';
echo '<span style="background: #fff3cd; padding: 2px 8px;">■ Not Submitted (Auto LOP)</span> ';
echo '<span style="background: #e2e8f0; padding: 2px 8px;">■ Sunday (No Work)</span></p>';

echo '<table border="1" cellpadding="5">';

// Header row with dates
echo '<tr class="header">';
echo '<th>Employee</th>';
foreach ($dates as $date) {
    $day_of_week = date('N', strtotime($date));
    // CRITICAL FIX: Use d/m format and force as text to prevent Excel from converting to date
    $display = date('d/m', strtotime($date));
    $day_name = date('D', strtotime($date));
    $holiday_name = isHoliday($date);
    
    if ($holiday_name !== false) {
        echo '<th style="background: #9b59b6; color: white; mso-number-format:\@;">' . $display . ' ' . $day_name . '<br><small>' . $holiday_name . '</small></th>';
    } elseif ($day_of_week == 7) {
        echo '<th style="background: #ed8936; color: white; mso-number-format:\@;">' . $display . ' ' . $day_name . '<br><small>Sunday</small></th>';
    } else {
        echo '<th style="mso-number-format:\@;">' . $display . ' ' . $day_name . '</th>';
    }
}
echo '<th style="mso-number-format:\@;">Total</th>';
echo '</tr>';

// Data rows for each user
foreach ($all_users as $user) {
    $user_key = $user['full_name'] . ' (ID:' . $user['id'] . ')';
    $submission_count = 0;
    
    echo '<tr>';
    echo '<td><strong>' . htmlspecialchars($user['full_name']) . '</strong></td>';
    
    foreach ($dates as $date) {
        $day_of_week = date('N', strtotime($date));
        $holiday_name = isHoliday($date);
        
        if ($holiday_name !== false) {
            echo '<td style="background: #f3e8ff; text-align: center; color: #6b21a8; font-weight: bold; mso-number-format:\@;">H</td>';
        } elseif ($day_of_week == 7) {
            $has_submission = isset($timesheets_data[$user_key][$date]);
            if ($has_submission) {
                $entry = $timesheets_data[$user_key][$date];
                $hours = $entry['hours'];
                $hours_display = number_format($hours, 1);
                $is_approved = ($entry['status'] === 'approved');
                echo '<td style="background: #fff3cd; text-align: center; color: #ed8936; font-weight: bold; mso-number-format:\@;">' . $hours_display . 'h ★</td>';
                if ($is_approved) {
                    $submission_count++; // Only count approved Sunday
                }
            } else {
                echo '<td style="background: #e2e8f0; text-align: center; color: #718096; mso-number-format:\@;">-</td>';
            }
        } else {
            $has_submission = isset($timesheets_data[$user_key][$date]);
            $has_leave = isset($leaves_data[$user_key][$date]);
            $has_lop = isset($lop_data[$user_key][$date]);
            
            if ($has_submission) {
                $entry = $timesheets_data[$user_key][$date];
                $hours = $entry['hours'];
                $hours_display = number_format($hours, 1);
                echo '<td style="background: #d4edda; text-align: center; mso-number-format:\@;">' . $hours_display . 'h</td>';
                $submission_count++;
            } elseif ($has_leave) {
                $leave = $leaves_data[$user_key][$date];
                $leave_type = $leave['leave_type'];
                echo '<td style="background: #cce5ff; text-align: center; color: #004085; font-weight: bold; mso-number-format:\@;">' . $leave_type . '</td>';
            } elseif ($has_lop) {
                echo '<td style="background: #fff3cd; text-align: center; color: #c53030; font-weight: bold; mso-number-format:\@;">LOP</td>';
            } else {
                echo '<td style="background: #f0f0f0; text-align: center; mso-number-format:\@;">-</td>';
            }
        }
    }
    
    $user_leave_days = isset($leaves_data[$user_key]) ? count($leaves_data[$user_key]) : 0;
    // Count approved Sundays for this user from submission_count logic above
    $user_approved_sundays = 0;
    if (isset($timesheets_data[$user_key])) {
        foreach ($timesheets_data[$user_key] as $date => $entry) {
            if (date('N', strtotime($date)) == 7 && $entry['status'] === 'approved') {
                $user_approved_sundays++;
            }
        }
    }
    // Denominator = working days - leave days + approved Sundays
    $working_days_required = count($working_dates) - $user_leave_days + $user_approved_sundays;
    
    $total_display = $submission_count . '/' . $working_days_required;
    echo '<td style="font-weight: bold; text-align: center; mso-number-format:\@;">' . $total_display . '</td>';
    echo '</tr>';
}

// Totals row
echo '<tr class="total">';
echo '<td><strong>Daily Totals</strong></td>';
$daily_submissions = [];
$daily_leaves = [];
$daily_holidays = [];
$daily_sunday_work = [];
foreach ($dates as $date) {
    $daily_submissions[$date] = 0;
    $daily_leaves[$date] = 0;
    $daily_holidays[$date] = 0;
    $daily_sunday_work[$date] = 0;
    $day_of_week = date('N', strtotime($date));
    $holiday_name = isHoliday($date);
    
    if ($holiday_name === false) {
        foreach ($all_users as $user) {
            $user_key = $user['full_name'] . ' (ID:' . $user['id'] . ')';
            if (isset($timesheets_data[$user_key][$date])) {
                $daily_submissions[$date]++;
                if ($day_of_week == 7) {
                    $daily_sunday_work[$date]++;
                }
            }
            if (isset($leaves_data[$user_key][$date])) {
                $daily_leaves[$date]++;
            }
            if (isset($holidays_data[$user_key][$date])) {
                $daily_holidays[$date]++;
            }
        }
    }
}

foreach ($dates as $date) {
    $day_of_week = date('N', strtotime($date));
    $holiday_name = isHoliday($date);
    
    if ($holiday_name !== false) {
        echo '<td style="text-align: center; background: #f3e8ff; color: #6b21a8; mso-number-format:\@;"><strong>Holiday</strong></td>';
    } elseif ($day_of_week == 7) {
        $total_present = $daily_sunday_work[$date];
        echo '<td style="text-align: center; background: #fff3cd; color: #ed8936; mso-number-format:\@;"><strong>' . $total_present . ' worked</strong></td>';
    } else {
        $total_present = $daily_submissions[$date];
        $total_leaves = $daily_leaves[$date];
        $total_expected = $total_users - $total_leaves;
        // CRITICAL FIX: Force the total to be displayed as text with slash
        $total_display = $total_present . '/' . $total_expected;
        echo '<td style="text-align: center; mso-number-format:\@;"><strong>' . $total_display . '</strong></td>';
    }
}
echo '<td style="text-align: center; mso-number-format:\@;"><strong>' . $total_actual_entries . '</strong></td>';
echo '</tr>';

echo '</table>';
echo '<br>';
    // ===== DETAILED TIMESHEET ENTRIES =====
    if ($timesheets_result->num_rows > 0) {
        echo '<h3>📋 Detailed Timesheet Entries</h3>';
        echo '<table>';
        echo '<tr>';
        echo '<th>Date</th>';
        echo '<th>Employee</th>';
        echo '<th>Department</th>';
        echo '<th>Software</th>';
        echo '<th>Project</th>';
        echo '<th>Task</th>';
        echo '<th>Hours</th>';
        echo '<th>Status</th>';
        echo '<th>Remarks</th>';
        echo '<th>Submitted (IST)</th>';
        echo '<th>Late</th>';
        echo '<th>Sunday Work</th>';
        echo '</tr>';
        
        $timesheets_result->data_seek(0);
        while ($entry = $timesheets_result->fetch_assoc()) {
            $is_late = ($entry['submitted_date'] > $entry['entry_date'] . ' 23:59:59') ? 'Yes' : 'No';
            $is_sunday = (date('N', strtotime($entry['entry_date'])) == 7);
            
            // Use project_name from timesheets table
            $project_display = !empty($entry['project_name']) ? $entry['project_name'] : 'No Project';
            if (!empty($entry['project_code']) && !empty($entry['project_name'])) {
                $project_display .= ' [' . $entry['project_code'] . ']';
            }
            
            echo '<tr' . ($is_sunday ? ' style="background: #fff3cd;"' : '') . '>';
            echo '<td class="date">' . $entry['entry_date'] . '</td>';
            echo '<td class="employee-name">' . htmlspecialchars($entry['full_name']) . '</td>';
            echo '<td>' . htmlspecialchars($entry['department'] ?? '-') . '</td>';
            echo '<td>' . htmlspecialchars($entry['software'] ?? '-') . '</td>';
            echo '<td class="project-name">' . htmlspecialchars($project_display) . '</td>';
            echo '<td class="task-list">' . htmlspecialchars($entry['task_name'] ?? '-') . '</td>';
            echo '<td><strong>' . number_format($entry['hours'], 1) . '</strong> hrs</td>';
            echo '<td>' . ($entry['status'] == 'approved' ? '<span class="status-approved">Approved</span>' : '<span class="status-pending">' . ucfirst($entry['status']) . '</span>') . '</td>';
            echo '<td>' . htmlspecialchars($entry['remarks'] ?? '-') . '</td>';
            echo '<td>' . date('Y-m-d H:i', strtotime($entry['submitted_date'])) . '</td>';
            echo '<td>' . $is_late . '</td>';
            echo '<td>' . ($is_sunday ? '<span class="sunday-badge">+1 Casual Leave</span>' : 'No') . '</td>';
            echo '</tr>';
        }
        echo '</table>';
    }
    
    // ===== APPROVED LEAVE ENTRIES =====
    if ($total_leave_entries > 0) {
        echo '<h3>✅ Approved Leave Entries</h3>';
        echo '<p style="color: #004085;"><strong>Employees on Approved Leave</strong></p>';
        echo '<table>';
        echo '<tr>';
        echo '<th>Date</th>';
        echo '<th>Employee</th>';
        echo '<th>Department</th>';
        echo '<th>Leave Type</th>';
        echo '<th>Days</th>';
        echo '<th>Reason</th>';
        echo '</tr>';
        
        $leaves_result->data_seek(0);
        while ($leave = $leaves_result->fetch_assoc()) {
            $user_info = null;
            foreach ($all_users as $u) {
                if ($u['id'] == $leave['user_id']) {
                    $user_info = $u;
                    break;
                }
            }
            
            echo '<tr class="leave">';
            echo '<td class="date">' . $leave['from_date'] . ' to ' . $leave['to_date'] . '</td>';
            echo '<td class="employee-name">' . htmlspecialchars($leave['full_name']) . '</td>';
            echo '<td>' . htmlspecialchars($user_info['department'] ?? '-') . '</td>';
            echo '<td><span class="leave-badge">' . $leave['leave_type'] . '</span></td>';
            echo '<td><strong>' . $leave['days'] . '</strong> day(s)</td>';
            echo '<td>' . htmlspecialchars($leave['reason']) . '</td>';
            echo '</tr>';
        }
        echo '</table>';
    }
    
    // ===== HOLIDAY ENTRIES =====
    if ($total_holiday_dates > 0) {
        echo '<h3>🎉 Holiday Entries (No Timesheet Required)</h3>';
        echo '<p style="color: #6b21a8;"><strong>Company Holidays</strong></p>';
        echo '<table>';
        echo '<tr>';
        echo '<th>Date</th>';
        echo '<th>Holiday Name</th>';
        echo '</tr>';
        
        $displayed_holidays = [];
        foreach ($holidays_data as $user_key => $holidays) {
            foreach ($holidays as $date => $name) {
                if (!isset($displayed_holidays[$date])) {
                    $displayed_holidays[$date] = $name;
                }
            }
        }
        
        foreach ($displayed_holidays as $date => $name) {
            echo '<tr class="holiday">';
            echo '<td class="date">' . $date . '</td>';
            echo '<td><span class="holiday-badge">' . $name . '</span></td>';
            echo '</tr>';
        }
        echo '</table>';
    }
    
    // ===== AUTO LOP ENTRIES =====
    if ($total_lop_entries > 0) {
        echo '<h3>⚠️ Auto-generated LOP Entries</h3>';
        echo '<p style="color: #c53030;"><strong>Missing Timesheets on Working Days (No Approved Leave)</strong></p>';
        echo '<table>';
        echo '<tr>';
        echo '<th>Date</th>';
        echo '<th>Employee</th>';
        echo '<th>Department</th>';
        echo '<th>Days</th>';
        echo '<th>Reason</th>';
        echo '</tr>';
        
        $lop_result->data_seek(0);
        while ($lop = $lop_result->fetch_assoc()) {
            $user_info = null;
            foreach ($all_users as $u) {
                if ($u['id'] == $lop['user_id']) {
                    $user_info = $u;
                    break;
                }
            }
            
            echo '<tr class="lop">';
            echo '<td class="date">' . $lop['from_date'] . '</td>';
            echo '<td class="employee-name">' . htmlspecialchars($lop['full_name']) . '</td>';
            echo '<td>' . htmlspecialchars($user_info['department'] ?? '-') . '</td>';
            echo '<td><strong>' . $lop['days'] . '</strong> day</td>';
            echo '<td>' . htmlspecialchars($lop['reason']) . '</td>';
            echo '</tr>';
        }
        echo '</table>';
    }
    
    echo '<hr>';
    echo '<p style="text-align: right; font-style: italic; color: #718096;">Generated by MAKSIM PORTAL on ' . date('Y-m-d H:i:s') . ' IST</p>';
    echo '</body>';
    echo '</html>';
    exit();
}
// ===== END EXCEL EXPORT FUNCTIONALITY =====

$edit_mode = isset($_SESSION['edit_timesheet']) && isset($_SESSION['edit_id']);
$edit_data = $edit_mode ? $_SESSION['edit_timesheet'] : null;
$edit_id = $edit_mode ? $_SESSION['edit_id'] : 0;

// Clear stale session if the record no longer exists in DB
if ($edit_mode && $edit_id > 0) {
    $verify = $conn->prepare("SELECT id FROM timesheets WHERE id = ?");
    $verify->bind_param("i", $edit_id);
    $verify->execute();
    if ($verify->get_result()->num_rows === 0) {
        unset($_SESSION['edit_timesheet']);
        unset($_SESSION['edit_id']);
        $edit_mode = false;
        $edit_data = null;
        $edit_id = 0;
    }
    $verify->close();
}

$edit_hours = 0;
$edit_minutes = 0;
if ($edit_mode && $edit_data) {
    $edit_hours = floor($edit_data['hours']);
    $edit_minutes = round(($edit_data['hours'] - $edit_hours) * 60);
}

$today_date = date('Y-m-d');
$page_title = "Timesheet - MAKSIM HR";
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $page_title; ?> - MAKSIM HR</title>
    <?php include '../includes/head.php'; ?>
    <!-- Simple inline SVG icons for faster offline loading -->
    <style>
        /* Base Styles */
        .timesheet-form { background: white; border-radius: 15px; padding: 25px; margin-bottom: 30px; box-shadow: 0 5px 15px rgba(0,0,0,0.05); }
        .timesheet-row { display: grid; grid-template-columns: 1fr 0.8fr 1.2fr 1.5fr 1.5fr 1.2fr 1fr; gap: 15px; margin-bottom: 20px; padding-bottom: 20px; border-bottom: 1px solid #e2e8f0; }
        .time-input { display: flex; align-items: center; gap: 5px; }
        .time-input input { width: 70px; padding: 10px 8px; text-align: center; font-size: 14px; border: 1px solid #e2e8f0; border-radius: 6px; }
        .time-separator { font-weight: bold; color: #718096; font-size: 18px; }
        .no-timesheet { text-align: center; padding: 40px; color: #718096; }
        .no-timesheet i { font-size: 48px; margin-bottom: 15px; color: #cbd5e0; }
        .date-range-picker { display: flex; gap: 10px; align-items: center; margin-right: 15px; }
        .date-range-picker .form-control { width: 150px; }
        .btn-submit { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; border: none; padding: 12px 30px; border-radius: 8px; font-weight: 600; cursor: pointer; transition: all 0.3s ease; display: inline-flex; align-items: center; gap: 8px; font-size: 14px; }
        .btn-submit:hover { transform: translateY(-2px); box-shadow: 0 10px 20px rgba(102, 126, 234, 0.3); }
        .btn-edit { background: #4299e1; color: white; border: none; padding: 4px 10px; border-radius: 4px; font-size: 12px; cursor: pointer; text-decoration: none; display: inline-flex; align-items: center; gap: 5px; }
        .btn-edit:hover { background: #3182ce; }
        .btn-edit-disabled { background: #cbd5e0; color: #718096; border: none; padding: 4px 10px; border-radius: 4px; font-size: 12px; cursor: not-allowed; pointer-events: none; opacity: 0.6; display: inline-flex; align-items: center; gap: 5px; text-decoration: none; }
        .btn-cancel { background: #a0aec0; color: white; border: none; padding: 12px 30px; border-radius: 8px; font-weight: 600; cursor: pointer; display: inline-flex; align-items: center; gap: 8px; font-size: 14px; text-decoration: none; margin-left: 10px; }
        .btn-cancel:hover { background: #718096; }
        .existing-entry { background: #f0fff4; border: 1px solid #9ae6b4; color: #276749; padding: 15px; border-radius: 8px; margin-bottom: 20px; display: flex; align-items: center; gap: 10px; }
        .project-management, .task-management { background: #ebf8ff; border: 1px solid #90cdf4; padding: 20px; border-radius: 8px; margin-bottom: 20px; display: flex; align-items: center; flex-wrap: wrap; gap: 15px; }
        .task-management { background: #f0e7ff; border-color: #9f7aea; }
        .project-management-buttons, .task-management-buttons { display: flex; gap: 10px; margin-left: auto; }
        .btn-project, .btn-task { display: inline-flex; align-items: center; gap: 8px; padding: 8px 16px; border-radius: 6px; font-weight: 600; text-decoration: none; transition: all 0.3s ease; font-size: 14px; }
        .btn-project-add, .btn-task-add { background: #4299e1; color: white; }
        .btn-project-remove, .btn-task-remove { background: #f56565; color: white; }
        .btn-project-view, .btn-task-view { background: #48bb78; color: white; }
        .role-badge { background: #edf2f7; padding: 4px 12px; border-radius: 20px; font-size: 12px; color: #4a5568; display: inline-flex; align-items: center; gap: 5px; }
        .all-employees-badge { background: #667eea; color: white; padding: 6px 15px; border-radius: 20px; font-size: 14px; font-weight: 600; display: inline-flex; align-items: center; gap: 8px; }
        .dependent-dropdown { position: relative; }
        .task-toggle, .software-toggle, .project-toggle { display: flex; gap: 20px; margin-bottom: 10px; flex-wrap: wrap; }
        .task-toggle label, .software-toggle label, .project-toggle label { display: flex; align-items: center; gap: 5px; cursor: pointer; color: #4a5568; }
        .tasks-badge { background: #805ad5; color: white; padding: 2px 10px; border-radius: 12px; font-size: 11px; font-weight: 600; margin-left: 10px; }
        .admin-badge { background: #ed8936; color: white; padding: 2px 8px; border-radius: 12px; font-size: 10px; font-weight: 600; margin-left: 5px; }
        .submissions-badge { background: #ed8936; color: white; padding: 6px 15px; border-radius: 20px; font-size: 14px; font-weight: 600; display: inline-flex; align-items: center; gap: 8px; }
        .lop-badge { background: #c53030; color: white; padding: 2px 8px; border-radius: 12px; font-size: 11px; font-weight: 600; margin-left: 5px; }
        .btn-approve { background: #48bb78; color: white; border: none; padding: 4px 10px; border-radius: 4px; font-size: 12px; cursor: pointer; text-decoration: none; display: inline-flex; align-items: center; gap: 5px; }
        .btn-approve:hover { background: #38a169; }
        .btn-approve-disabled { background: #a0aec0; color: white; border: none; padding: 4px 10px; border-radius: 4px; font-size: 12px; opacity: 0.6; cursor: not-allowed; pointer-events: none; display: inline-flex; align-items: center; gap: 5px; }
        .lop-entry { background-color: #f0f0f0; }
        .lop-entry td { color: #666666; }
        .btn-delete { background: #f56565; color: white; border: none; padding: 4px 10px; border-radius: 4px; font-size: 12px; cursor: pointer; text-decoration: none; display: inline-flex; align-items: center; gap: 5px; }
        .btn-delete-sunday { background: #ed8936; color: white; border: none; padding: 4px 10px; border-radius: 4px; font-size: 12px; cursor: pointer; text-decoration: none; display: inline-flex; align-items: center; gap: 5px; }
        .btn-delete:hover { background: #e53e3e; }
        .btn-delete-sunday:hover { background: #dd6b20; }
        .btn-small { padding: 4px 10px; border-radius: 4px; font-size: 12px; text-decoration: none; display: inline-flex; align-items: center; gap: 5px; }
        .late-badge { background: #ed8936; color: white; padding: 2px 6px; border-radius: 4px; font-size: 10px; margin-left: 5px; }
        .btn-export { background: #28a745; color: white; border: none; padding: 8px 15px; border-radius: 6px; font-weight: 600; cursor: pointer; display: inline-flex; align-items: center; gap: 8px; text-decoration: none; font-size: 14px; }
        .btn-export:hover { background: #218838; transform: translateY(-2px); box-shadow: 0 5px 10px rgba(40, 167, 69, 0.3); }
        .export-container { display: inline-block; }
        .task-checkbox-group { max-height: 200px; overflow-y: auto; border: 1px solid #e2e8f0; border-radius: 8px; padding: 10px; background: #f9f9f9; }
        .task-checkbox-item { display: flex; align-items: center; gap: 8px; padding: 8px; border-bottom: 1px solid #e2e8f0; }
        .task-checkbox-item:last-child { border-bottom: none; }
        .task-checkbox-item:hover { background: #edf2f7; }
        .task-checkbox-item input[type="checkbox"] { width: 18px; height: 18px; cursor: pointer; }
        .task-checkbox-item label { cursor: pointer; flex: 1; font-size: 14px; }
        .selected-tasks-badge { background: #4299e1; color: white; padding: 4px 12px; border-radius: 20px; font-size: 12px; display: inline-block; margin-top: 5px; }
        .software-checkbox-group { max-height: 200px; overflow-y: auto; border: 1px solid #e2e8f0; border-radius: 8px; padding: 10px; background: #f9f9f9; }
        .software-checkbox-item { display: flex; align-items: center; gap: 8px; padding: 8px; border-bottom: 1px solid #e2e8f0; }
        .software-checkbox-item:last-child { border-bottom: none; }
        .software-checkbox-item:hover { background: #edf2f7; }
        .software-checkbox-item input[type="checkbox"] { width: 18px; height: 18px; cursor: pointer; }
        .software-checkbox-item label { cursor: pointer; flex: 1; font-size: 14px; }
        .selected-software-badge { background: #4299e1; color: white; padding: 4px 12px; border-radius: 20px; font-size: 12px; display: inline-block; margin-top: 5px; }
        .project-checkbox-group { max-height: 200px; overflow-y: auto; border: 1px solid #e2e8f0; border-radius: 8px; padding: 10px; background: #f9f9f9; }
        .project-checkbox-item { display: flex; align-items: center; gap: 8px; padding: 8px; border-bottom: 1px solid #e2e8f0; }
        .project-checkbox-item:last-child { border-bottom: none; }
        .project-checkbox-item:hover { background: #edf2f7; }
        .project-checkbox-item input[type="checkbox"] { width: 18px; height: 18px; cursor: pointer; }
        .project-checkbox-item label { cursor: pointer; flex: 1; font-size: 14px; }
        .selected-projects-badge { background: #4299e1; color: white; padding: 4px 12px; border-radius: 20px; font-size: 12px; display: inline-block; margin-top: 5px; }
        .edit-mode-badge { background: #4299e1; color: white; padding: 4px 12px; border-radius: 20px; font-size: 12px; margin-left: 10px; }
        .sunday-row { background-color: #fff3cd; color: #ed8936; }
        .sunday-row td { color: #ed8936; }
        .sunday-badge { background: #ed8936; color: white; padding: 2px 6px; border-radius: 4px; font-size: 10px; margin-left: 5px; }
        .working-days-note { background: #ebf8ff; border: 1px solid #4299e1; color: #2c5282; padding: 10px 15px; border-radius: 8px; margin-bottom: 20px; display: flex; align-items: center; gap: 10px; font-size: 14px; }
        .project-name { font-weight: 500; }
        .project-code { color: #718096; font-size: 11px; margin-left: 4px; }
        .holiday-entry { background-color: #f3e8ff !important; }
        .holiday-entry td { color: #6b21a8; }
        .holiday-badge { background: #9b59b6; color: white; padding: 2px 8px; border-radius: 4px; font-size: 10px; margin-left: 5px; font-weight: bold; }
        .leave-entry { background-color: #cce5ff !important; }
        .leave-entry td { color: #004085; }
        .sunday-work-badge { background: #ed8936; color: white; padding: 2px 6px; border-radius: 4px; font-size: 10px; margin-left: 5px; font-weight: bold; }
        .delete-options { display: flex; flex-direction: column; gap: 2px; }
        
        /* New style for late badge below date */
        .date-container {
            display: flex;
            flex-direction: column;
            align-items: flex-start;
            line-height: 1.3;
        }
        .late-badge-below {
            background: #ed8936;
            color: white;
            padding: 2px 6px;
            border-radius: 4px;
            font-size: 9px;
            font-weight: bold;
            margin-top: 2px;
            display: inline-block;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        /* Offline Icon Styles - Using Unicode/Emoji for instant loading */
        .icon { margin-right: 8px; }
        .task-icon { margin-right: 8px; }
        .project-icon { margin-right: 8px; color: #48bb78; }
        .software-icon { margin-right: 8px; color: #ed8936; }
        .status-icon { margin-right: 5px; }
        
        /* Unicode Icons - Load instantly, no external dependencies */
        .icon-timesheet:before { content: "📝 "; }
        .icon-users:before { content: "👥 "; }
        .icon-edit:before { content: "✏️ "; }
        .icon-plus:before { content: "➕ "; }
        .icon-save:before { content: "💾 "; }
        .icon-cancel:before { content: "❌ "; }
        .icon-check:before { content: "✅ "; }
        .icon-delete:before { content: "🗑️ "; }
        .icon-info:before { content: "ℹ️ "; }
        .icon-warning:before { content: "⚠️ "; }
        .icon-success:before { content: "✅ "; }
        .icon-error:before { content: "❌ "; }
        .icon-project:before { content: "📁 "; }
        .icon-task:before { content: "📋 "; }
        .icon-list:before { content: "📝 "; }
        .icon-software:before { content: "💻 "; }
        .icon-clock:before { content: "⏰ "; }
        .icon-calendar:before { content: "📅 "; }
        .icon-excel:before { content: "📤 "; }
        .icon-settings:before { content: "⚙️ "; }
        .icon-user-tie:before { content: "👔 "; }
        
        /* Task specific icons */
        .task-bolt:before { content: "⚡ "; }
        .task-cable:before { content: "🔌 "; }
        .task-wrench:before { content: "🛠️ "; }
        .task-railing:before { content: "🚧 "; }
        .task-building:before { content: "🏛️ "; }
        .task-stairs:before { content: "▁▃▅▇ "; }
        .task-cogs:before { content: "⚙️ "; }
        .task-pipe:before { content: "🔧 "; }
        .task-cubes:before { content: "🏗️ "; }
        .task-hvac:before { content: "❄️ "; }
        
        /* Status icons */
        .status-approved:before { content: "✅ "; }
        .status-pending:before { content: "⏳ "; }
        .status-error:before { content: "⚠️ "; }
        .status-holiday:before { content: "🎉 "; }
        .status-leave:before { content: "🏖️ "; }
    </style>
</head>
<body>
    <?php include '../includes/header.php'; ?>
    
    <div class="app-main">
        <?php include '../includes/sidebar.php'; ?>
        
        <div class="main-content">
            <h2 class="page-title">
                <i class="icon-timesheet"></i> 
                <?php echo $page_title; ?>
                <?php if (in_array($role, ['hr', 'admin', 'pm']) && !isset($_GET['user_id'])): ?>
                <span class="all-employees-badge">
                    <i class="icon-users"></i> Viewing: All Employees
                </span>
                <?php endif; ?>
                <?php if ($edit_mode): ?>
                <span class="edit-mode-badge">
                    <i class="icon-edit"></i> Editing Timesheet Entry
                </span>
                <?php endif; ?>
            </h2>
            
            <?php echo $message; ?>
            
            <?php if (in_array($role, ['hr', 'admin', 'pm'])): ?>
            <div class="working-days-note">
                <i class="icon-info"></i>
                <div>
                    <strong>Note:</strong> 
                    <span style="color: #2c5282;">Working Days (Mon-Sat): Timesheet required - Auto LOP generated if missing</span> | 
                    <span style="color: #ed8936; font-weight: bold;">Sundays: Optional work - If approved, employee gets +1 casual leave</span> | 
                    <span style="color: #6b21a8;">Holidays: No timesheet required</span>
                    <?php if ($working_days_in_range > 0): ?>
                    <span style="font-weight: bold; margin-left: 10px;">Working days in range: <?php echo $working_days_in_range; ?></span>
                    <?php endif; ?>
                </div>
            </div>
            
            <div class="project-management" style="background: #fed7d7; border-color: #fc8181;">
                <i class="icon-warning" style="color: #c53030;"></i>
                <div style="flex: 1;">
                    <strong style="color: #c53030;">Auto LOP Tracking:</strong> 
                    <?php echo $lop_count; ?> auto-generated LOP entries found in this date range.
                </div>
                <div style="display: flex; align-items: center; gap: 10px; flex-wrap: wrap;">
                    <?php $yesterday_btn = date('Y-m-d', strtotime('-1 day')); ?>
                    <a href="?check_missing=1&check_date=<?php echo $yesterday_btn; ?><?php echo isset($_GET['user_id']) ? '&user_id=' . $_GET['user_id'] : ''; ?>&from_date=<?php echo $from_date; ?>&to_date=<?php echo $to_date; ?>" class="btn-check" style="background: #805ad5; color: white; padding: 8px 16px; border-radius: 6px; text-decoration: none;">
                        <i class="icon-check"></i> Check Yesterday (<?php echo $yesterday_btn; ?>)
                    </a>
                    <span style="color: #718096; font-size: 12px;">or pick a date:</span>
                    <form method="GET" action="" style="display: flex; align-items: center; gap: 6px;">
                        <input type="hidden" name="check_missing" value="1">
                        <input type="hidden" name="from_date" value="<?php echo $from_date; ?>">
                        <input type="hidden" name="to_date" value="<?php echo $to_date; ?>">
                        <?php if (isset($_GET['user_id'])): ?>
                        <input type="hidden" name="user_id" value="<?php echo $_GET['user_id']; ?>">
                        <?php endif; ?>
                        <input type="date" name="check_date" value="<?php echo $yesterday_btn; ?>" max="<?php echo $yesterday_btn; ?>" style="padding: 7px 10px; border: 1px solid #e2e8f0; border-radius: 6px; font-size: 13px;">
                        <button type="submit" style="background: #4299e1; color: white; border: none; padding: 8px 14px; border-radius: 6px; cursor: pointer; font-size: 13px;">
                            ✓ Check
                        </button>
                    </form>
                </div>
            </div>
            
            <div class="project-management">
                <div style="display: flex; align-items: center; gap: 15px;">
                    <i class="icon-project"></i>
                    <div>
                        <strong style="font-size: 16px; color: #2d3748;">Project Management</strong>
                        <div style="display: flex; gap: 10px; margin-top: 5px;">
                            <span class="role-badge">
                                <i class="icon-user-tie"></i> 
                                <?php 
                                if ($role == 'hr') echo 'HR Administrator';
                                elseif ($role == 'admin') echo 'System Admin';
                                elseif ($role == 'pm') echo 'Project Manager';
                                ?>
                            </span>
                        </div>
                    </div>
                </div>
                
                <div class="project-management-buttons">
                    <a href="../admin/manage_projects.php" class="btn-project btn-project-add" target="_blank">
                        <i class="icon-plus"></i> Add New Project
                    </a>
                    <a href="../admin/manage_projects.php" class="btn-project btn-project-remove" target="_blank">
                        <i class="icon-delete"></i> Remove Projects
                    </a>
                    <a href="../admin/manage_projects.php" class="btn-project btn-project-view" target="_blank">
                        <i class="icon-edit"></i> Edit Projects
                    </a>
                </div>
            </div>
            
            <div class="task-management">
                <div style="display: flex; align-items: center; gap: 15px;">
                    <i class="icon-task"></i>
                    <div>
                        <strong style="font-size: 16px; color: #2d3748;">Task Management</strong>
                        <div style="display: flex; gap: 10px; margin-top: 5px;">
                            <span class="role-badge">
                                <i class="icon-user-tie"></i> 
                                <?php 
                                if ($role == 'hr') echo 'HR Administrator';
                                elseif ($role == 'admin') echo 'System Admin';
                                elseif ($role == 'pm') echo 'Project Manager';
                                ?>
                            </span>
                        </div>
                    </div>
                </div>
                
                <div class="task-management-buttons">
                    <a href="../admin/manage_tasks.php" class="btn-task btn-task-view" target="_blank">
                        <i class="icon-settings"></i> Manage Tasks
                    </a>
                </div>
            </div>
            
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px; margin-bottom: 20px;">
                <div style="background: white; padding: 15px; border-radius: 10px; box-shadow: 0 2px 5px rgba(0,0,0,0.05);">
                    <div style="color: #4299e1; font-size: 12px; text-transform: uppercase; letter-spacing: 1px;">Active Projects</div>
                    <div style="font-size: 28px; font-weight: bold; color: #2d3748;">
                        <?php
                        $active_projects = $conn->query("SELECT COUNT(*) as count FROM projects WHERE status = 'active'");
                        $active_count = $active_projects ? $active_projects->fetch_assoc() : ['count' => 0];
                        echo $active_count['count'];
                        ?>
                    </div>
                </div>
                <div style="background: white; padding: 15px; border-radius: 10px; box-shadow: 0 2px 5px rgba(0,0,0,0.05);">
                    <div style="color: #48bb78; font-size: 12px; text-transform: uppercase; letter-spacing: 1px;">Available Tasks</div>
                    <div style="font-size: 28px; font-weight: bold; color: #2d3748;">
                        <?php echo $unique_tasks_count; ?>
                    </div>
                </div>
                <div style="background: white; padding: 15px; border-radius: 10px; box-shadow: 0 2px 5px rgba(0,0,0,0.05);">
                    <div style="color: #ed8936; font-size: 12px; text-transform: uppercase; letter-spacing: 1px;">
                        <?php echo ($from_date == $to_date) ? 'Submissions on ' . date('d-m-Y', strtotime($from_date)) : 'Total Submissions'; ?>
                    </div>
                    <div style="font-size: 28px; font-weight: bold; color: #2d3748; display: flex; align-items: center; gap: 10px;">
                        <?php echo $submissions_count; ?>
                        <span style="font-size: 14px; font-weight: normal; color: #718096;">
                            <?php 
                            if (isset($unique_employees) && !isset($_GET['user_id'])) {
                                echo $unique_employees . ' ' . ($unique_employees == 1 ? 'employee' : 'employees');
                            } elseif (isset($_GET['user_id'])) {
                                echo 'entries';
                            }
                            ?>
                        </span>
                    </div>
                </div>
                <div style="background: white; padding: 15px; border-radius: 10px; box-shadow: 0 2px 5px rgba(0,0,0,0.05);">
                    <div style="color: #c53030; font-size: 12px; text-transform: uppercase; letter-spacing: 1px;">Auto LOP Entries</div>
                    <div style="font-size: 28px; font-weight: bold; color: #c53030;">
                        <?php echo $lop_count; ?>
                    </div>
                </div>
            </div>
            <?php endif; ?>
            
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">
                        <i class="icon-clock"></i> 
                        <?php if (in_array($role, ['hr', 'admin', 'pm']) && !isset($_GET['user_id'])): ?>
                            All Employees Timesheet
                        <?php else: ?>
                            Timesheet - <?php echo $user_info['full_name'] ?? $selected_user_name; ?>
                        <?php endif; ?>
                    </h3>
                    <div style="display: flex; gap: 10px; align-items: center;">
                        <?php if (in_array($role, ['hr', 'admin', 'pm'])): ?>
                        <select id="userSelect" class="form-control" style="width: 200px;" onchange="changeUser(this.value)">
                            <option value="">All Employees</option>
                            <?php foreach ($users as $u): ?>
                            <option value="<?php echo $u['id']; ?>" <?php echo (isset($_GET['user_id']) && $viewing_user_id == $u['id']) ? 'selected' : ''; ?>>
                                <?php echo $u['full_name']; ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                        
                        <div class="date-range-picker">
                            <input type="date" id="fromDate" class="form-control" value="<?php echo $from_date; ?>" onchange="updateDateRange()">
                            <span style="color: #718096;">to</span>
                            <input type="date" id="toDate" class="form-control" value="<?php echo $to_date; ?>" onchange="updateDateRange()">
                        </div>
                        
                        <div class="export-container">
                            <a href="?export_excel=1&export_from=<?php echo $from_date; ?>&export_to=<?php echo $to_date; ?><?php echo isset($_GET['user_id']) && $_GET['user_id'] != '' ? '&export_user_id=' . $_GET['user_id'] : ''; ?>" class="btn-export" target="_blank">
                                <i class="icon-excel"></i> Export Excel
                            </a>
                        </div>
                        
                        <?php else: ?>
                        <input type="date" id="dateSelect" class="form-control" value="<?php echo $from_date; ?>" onchange="changeDate(this.value)">
                        <?php endif; ?>
                    </div>
                </div>
                
                <div style="margin-bottom: 20px; padding: 15px; background: #f7fafc; border-radius: 10px;">
                    <div style="display: flex; gap: 20px; margin-bottom: 10px; flex-wrap: wrap;">
                        <?php if (in_array($role, ['hr', 'admin', 'pm']) && !isset($_GET['user_id'])): ?>
                        <div>
                            <strong>Viewing:</strong> All Employees
                        </div>
                        <div>
                            <strong>Date Range:</strong> <?php echo $from_date; ?> to <?php echo $to_date; ?>
                            <?php if ($working_days_in_range > 0): ?>
                            <span class="sunday-badge">Working Days: <?php echo $working_days_in_range; ?></span>
                            <?php endif; ?>
                        </div>
                        <?php else: ?>
                        <div>
                            <strong>Employee:</strong> <?php echo $user_info['full_name'] ?? $selected_user_name; ?>
                        </div>
                        <div>
                            <strong>Reporting To:</strong> <?php echo $user_info['reporting_manager'] ?? 'Not assigned'; ?>
                        </div>
                        <?php endif; ?>
                        <div>
                            <strong>Total Hours <?php echo in_array($role, ['hr', 'admin', 'pm']) ? '(' . $from_date . ' to ' . $to_date . ')' : 'Today'; ?>:</strong> 
                            <span style="font-weight: bold; color: #276749;"><?php echo number_format($total_hours, 2); ?> hrs</span>
                            <?php if ($working_days_in_range > 0 && in_array($role, ['hr', 'admin', 'pm'])): ?>
                            <span style="color: #718096; font-size: 12px; margin-left: 10px;">
                                Avg: <?php echo number_format($total_hours / $working_days_in_range, 2); ?> hrs/day
                            </span>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- Edit Timesheet Form -->
                <?php if ($edit_mode && $edit_data): ?>
                <div class="timesheet-form" style="border: 2px solid #4299e1;">
                    <h4 style="margin-bottom: 20px; color: #4299e1;">
                        <i class="icon-edit" style="color: #4299e1;"></i> 
                        Editing Timesheet Entry for <?php echo $from_date; ?>
                    </h4>
                    <form method="POST" action="" id="timesheetForm">
                        <div class="timesheet-row">
                            <div>
                                <label class="form-label"><span class="icon-calendar"></span> Date *</label>
                                <input type="date" name="date" class="form-control" value="<?php echo $edit_data['entry_date']; ?>" required>
                                <input type="hidden" name="edit_id" value="<?php echo $edit_id; ?>">
                            </div>
                            <div>
                                <label class="form-label"><span class="icon-clock"></span> Hours *</label>
                                <div class="time-input">
                                    <input type="number" name="hours" class="form-control" placeholder="HH" min="0" max="12" value="<?php echo $edit_hours; ?>" required>
                                    <span class="time-separator">:</span>
                                    <input type="number" name="minutes" class="form-control" placeholder="MM" min="0" max="59" value="<?php echo $edit_minutes; ?>" required>
                                </div>
                                <small style="color: #718096; display: block; margin-top: 5px;">Hours: 0-12 | Minutes: 0-59</small>
                            </div>
                            
                            <!-- Software with checkbox/tick options -->
                            <div class="dependent-dropdown">
                                <label class="form-label">
                                    <span class="icon-software"></span> Software * 
                                    <?php if ($is_admin_user): ?>
                                    <span class="admin-badge">Admin</span>
                                    <?php endif; ?>
                                </label>
                                
                                <?php if ($is_admin_user): ?>
                                <div class="software-toggle">
                                    <label>
                                        <input type="radio" name="software_type" value="dropdown" <?php echo (!isset($edit_data['software']) || strpos($edit_data['software'], ',') === false) ? 'checked' : ''; ?> onchange="toggleSoftwareInput()"> 
                                        <i class="icon-list"></i> Select from list
                                    </label>
                                    <label>
                                        <input type="radio" name="software_type" value="manual" <?php echo (isset($edit_data['software']) && strpos($edit_data['software'], ',') !== false) ? 'checked' : ''; ?> onchange="toggleSoftwareInput()"> 
                                        <i class="icon-edit"></i> Enter manually
                                    </label>
                                </div>
                                
                                <div id="software_dropdown_container" style="<?php echo (isset($edit_data['software']) && strpos($edit_data['software'], ',') !== false) ? 'display: none;' : 'display: block;'; ?>">
                                    <div class="software-checkbox-group" id="multi_software_container">
                                        <?php 
                                        $selected_software = [];
                                        if (isset($edit_data['software'])) {
                                            if (strpos($edit_data['software'], ',') !== false) {
                                                $selected_software = explode(', ', $edit_data['software']);
                                            } else {
                                                $selected_software = [$edit_data['software']];
                                            }
                                        }
                                        foreach ($software_options as $software): 
                                        $checked = in_array($software, $selected_software) ? 'checked' : '';
                                        $icon = '';
                                        if ($software == 'Cyclone') $icon = '🌀';
                                        elseif ($software == 'AutoCAD') $icon = '📐';
                                        elseif ($software == 'HR') $icon = '👥';
                                        elseif ($software == 'Admin') $icon = '⚙️';
                                        elseif ($software == 'Other') $icon = '📁';
                                        elseif ($software == 'Training') $icon = '📚';
                                        ?>
                                            <div class="software-checkbox-item">
                                                <input type="checkbox" name="software_ids[]" value="<?php echo $software; ?>" id="software_<?php echo md5($software); ?>" <?php echo $checked; ?>>
                                                <label for="software_<?php echo md5($software); ?>">
                                                    <span class="software-icon"><?php echo $icon; ?></span> <?php echo $software; ?>
                                                </label>
                                            </div>
                                        <?php endforeach; ?>
                                        <div class="selected-software-badge" id="selected_software_count">
                                            <?php 
                                            if (isset($edit_data['software'])) {
                                                $count = count($selected_software);
                                                echo $count . ' software' . ($count != 1 ? 's' : '') . ' selected';
                                            } else {
                                                echo '0 software selected';
                                            }
                                            ?>
                                        </div>
                                    </div>
                                    <small style="color: #718096; display: block; margin-top: 5px;">
                                        <i class="icon-software"></i> 
                                        <strong>Select multiple software:</strong> Check all software you worked with
                                    </small>
                                    <select name="software" id="software_select" style="display: none;">
                                        <option value="">Select Software</option>
                                        <?php foreach ($software_options as $software): ?>
                                        <option value="<?php echo $software; ?>"><?php echo $software; ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                
                                <div id="software_manual_container" style="<?php echo (isset($edit_data['software']) && strpos($edit_data['software'], ',') !== false) ? 'display: block;' : 'display: none;'; ?>">
                                    <input type="text" name="software_manual" class="form-control" placeholder="Enter software names (comma separated)" value="<?php echo (isset($edit_data['software']) && strpos($edit_data['software'], ',') !== false) ? htmlspecialchars($edit_data['software']) : ''; ?>">
                                </div>
                                
                                <?php else: ?>
                                <div class="software-checkbox-group" id="multi_software_container">
                                    <?php 
                                    $selected_software = [];
                                    if (isset($edit_data['software'])) {
                                        if (strpos($edit_data['software'], ',') !== false) {
                                            $selected_software = explode(', ', $edit_data['software']);
                                        } else {
                                            $selected_software = [$edit_data['software']];
                                        }
                                    }
                                    foreach ($software_options as $software): 
                                    $checked = in_array($software, $selected_software) ? 'checked' : '';
                                    $icon = '💻';
                                    if ($software == 'Cyclone') $icon = '🌀';
                                    elseif ($software == 'AutoCAD') $icon = '📐';
                                    elseif ($software == 'HR') $icon = '👥';
                                    elseif ($software == 'Admin') $icon = '⚙️';
                                    elseif ($software == 'Other') $icon = '📁';
                                    elseif ($software == 'Training') $icon = '📚';
                                    ?>
                                        <div class="software-checkbox-item">
                                            <input type="checkbox" name="software_ids[]" value="<?php echo $software; ?>" id="software_<?php echo md5($software); ?>" <?php echo $checked; ?>>
                                            <label for="software_<?php echo md5($software); ?>">
                                                <span class="software-icon"><?php echo $icon; ?></span> <?php echo $software; ?>
                                            </label>
                                        </div>
                                    <?php endforeach; ?>
                                    <div class="selected-software-badge" id="selected_software_count">
                                        <?php 
                                        if (isset($edit_data['software'])) {
                                            $count = count($selected_software);
                                            echo $count . ' software' . ($count != 1 ? 's' : '') . ' selected';
                                        } else {
                                            echo '0 software selected';
                                        }
                                        ?>
                                    </div>
                                </div>
                                <small style="color: #718096; display: block; margin-top: 5px;">
                                    <i class="icon-software"></i> 
                                    <strong>Select multiple software:</strong> Check all software you worked with
                                </small>
                                <?php endif; ?>
                            </div>
                            
                            <!-- Project - KEEPING MANUAL ENTRY OPTION -->
                            <div class="dependent-dropdown">
                                <label class="form-label">
                                    <span class="icon-project"></span> Project * 
                                    <?php if ($is_admin_user): ?>
                                    <span class="admin-badge">Admin</span>
                                    <?php endif; ?>
                                </label>
                                
                                <?php if ($is_admin_user): ?>
                                <div class="project-toggle">
                                    <label>
                                        <input type="radio" name="project_type" value="dropdown" <?php echo (!isset($edit_data['project_name']) || strpos($edit_data['project_name'], ',') === false) ? 'checked' : ''; ?> onchange="toggleProjectInput()"> 
                                        <i class="icon-list"></i> Select from list
                                    </label>
                                    <label>
                                        <input type="radio" name="project_type" value="manual" <?php echo (isset($edit_data['project_name']) && strpos($edit_data['project_name'], ',') !== false) ? 'checked' : ''; ?> onchange="toggleProjectInput()"> 
                                        <i class="icon-edit"></i> Enter manually
                                    </label>
                                </div>
                                
                                <div id="project_dropdown_container" style="<?php echo (isset($edit_data['project_name']) && strpos($edit_data['project_name'], ',') !== false) ? 'display: none;' : 'display: block;'; ?>">
                                    <!-- Single Project Dropdown -->
                                    <select name="project_id" id="project_id" class="form-control">
                                        <option value="">-- Select Project --</option>
                                        <?php if (!empty($projects)): ?>
                                            <?php 
                                            $selected_project_id = '';
                                            if (isset($edit_data['project_id']) && $edit_data['project_id'] > 0) {
                                                $selected_project_id = $edit_data['project_id'];
                                            }
                                            foreach ($projects as $project): 
                                            $project_display = $project['project_name'];
                                            if (!empty($project['project_code'])) {
                                                $project_display .= ' [' . $project['project_code'] . ']';
                                            }
                                            $selected = ($selected_project_id == $project['id']) ? 'selected' : '';
                                            ?>
                                            <option value="<?php echo $project['id']; ?>" <?php echo $selected; ?>>
                                                📁 <?php echo htmlspecialchars($project_display); ?>
                                            </option>
                                            <?php endforeach; ?>
                                        <?php else: ?>
                                            <option value="" disabled>No projects available</option>
                                        <?php endif; ?>
                                    </select>
                                    <small style="color: #718096; display: block; margin-top: 5px;">
                                        <i class="icon-project"></i> 
                                        <strong>Select a single project:</strong> Choose the project you worked on
                                    </small>
                                </div>
                                
                                <div id="project_manual_container" style="<?php echo (isset($edit_data['project_name']) && strpos($edit_data['project_name'], ',') !== false) ? 'display: block;' : 'display: none;'; ?>">
                                    <input type="text" name="project_name_manual" class="form-control" placeholder="Enter project name" value="<?php echo (isset($edit_data['project_name']) && strpos($edit_data['project_name'], ',') !== false) ? htmlspecialchars($edit_data['project_name']) : ''; ?>">
                                </div>
                                
                                <?php else: ?>
                                <!-- Single Project Dropdown for non-admin users -->
                                <select name="project_id" id="project_id" class="form-control" required>
                                    <option value="">-- Select Project --</option>
                                    <?php if (!empty($projects)): ?>
                                        <?php 
                                        $selected_project_id = '';
                                        if (isset($edit_data['project_id']) && $edit_data['project_id'] > 0) {
                                            $selected_project_id = $edit_data['project_id'];
                                        }
                                        foreach ($projects as $project): 
                                        $project_display = $project['project_name'];
                                        if (!empty($project['project_code'])) {
                                            $project_display .= ' [' . $project['project_code'] . ']';
                                        }
                                        $selected = ($selected_project_id == $project['id']) ? 'selected' : '';
                                        ?>
                                        <option value="<?php echo $project['id']; ?>" <?php echo $selected; ?>>
                                            📁 <?php echo htmlspecialchars($project_display); ?>
                                        </option>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <option value="" disabled>No projects available</option>
                                    <?php endif; ?>
                                </select>
                                <small style="color: #718096; display: block; margin-top: 5px;">
                                    <i class="icon-project"></i> 
                                    <strong>Select a single project:</strong> Choose the project you worked on
                                </small>
                                <?php endif; ?>
                            </div>
                            
                            <!-- Task -->
                            <div class="dependent-dropdown">
                                <label class="form-label">
                                    <span class="icon-task"></span> Task * 
                                    <span class="tasks-badge">
                                        <i class="icon-task"></i> <?php echo $unique_tasks_count; ?> Tasks
                                    </span>
                                </label>
                                
                                <?php if ($tasks_table_exists && !empty($all_tasks)): ?>
                                <div class="task-toggle">
                                    <label>
                                        <input type="radio" name="task_type" value="dropdown" <?php echo (empty($edit_data['task_name']) || strpos($edit_data['task_name'], ',') === false) ? 'checked' : ''; ?> onchange="toggleTaskInput()"> 
                                        <i class="icon-list"></i> Select from list
                                    </label>
                                    <label>
                                        <input type="radio" name="task_type" value="manual" <?php echo (!empty($edit_data['task_name']) && strpos($edit_data['task_name'], ',') !== false) ? 'checked' : ''; ?> onchange="toggleTaskInput()"> 
                                        <i class="icon-edit"></i> Enter manually
                                    </label>
                                </div>
                                
                                <div id="task_dropdown_container" style="<?php echo (!empty($edit_data['task_name']) && strpos($edit_data['task_name'], ',') !== false) ? 'display: none;' : 'display: block;'; ?>">
                                    <div class="task-checkbox-group" id="multi_task_container">
                                        <?php if (!empty($all_tasks)): ?>
                                            <?php 
                                            $selected_tasks = [];
                                            if (isset($edit_data['task_name'])) {
                                                if (strpos($edit_data['task_name'], ',') !== false) {
                                                    $selected_tasks = explode(', ', $edit_data['task_name']);
                                                } else {
                                                    $selected_tasks = [$edit_data['task_name']];
                                                }
                                            }
                                            foreach ($all_tasks as $task): 
                                            $checked = in_array($task['task_name'], $selected_tasks) ? 'checked' : '';
                                            $icon = '⚡';
                                            if (strpos($task['task_name'], 'Electrical') !== false) $icon = '⚡';
                                            elseif (strpos($task['task_name'], 'Cable Trays') !== false) $icon = '🔌';
                                            elseif (strpos($task['task_name'], 'Support') !== false) $icon = '🛠️';
                                            elseif (strpos($task['task_name'], 'Railings') !== false) $icon = '🚧';
                                            elseif (strpos($task['task_name'], 'Architecture') !== false) $icon = '🏛️';
                                            elseif (strpos($task['task_name'], 'Stairs') !== false) $icon = '▁▃▅▇';
                                            elseif (strpos($task['task_name'], 'Equipment') !== false) $icon = '⚙️';
                                            elseif (strpos($task['task_name'], 'Piping') !== false) $icon = '🔧';
                                            elseif (strpos($task['task_name'], 'Structure') !== false) $icon = '🏗️';
                                            elseif (strpos($task['task_name'], 'HVAC') !== false) $icon = '❄️';
                                            ?>
                                                <div class="task-checkbox-item">
                                                    <input type="checkbox" name="task_ids[]" value="<?php echo $task['id']; ?>" id="task_<?php echo $task['id']; ?>" <?php echo $checked; ?>>
                                                    <label for="task_<?php echo $task['id']; ?>">
                                                        <span class="task-icon"><?php echo $icon; ?></span> <?php echo htmlspecialchars($task['task_name']); ?>
                                                    </label>
                                                </div>
                                            <?php endforeach; ?>
                                            <div class="selected-tasks-badge" id="selected_tasks_count">
                                                <?php 
                                                if (isset($edit_data['task_name'])) {
                                                    $count = count($selected_tasks);
                                                    echo $count . ' task' . ($count != 1 ? 's' : '') . ' selected';
                                                } else {
                                                    echo '0 tasks selected';
                                                }
                                                ?>
                                            </div>
                                        <?php else: ?>
                                            <div style="padding: 10px; color: #718096;">No tasks available</div>
                                        <?php endif; ?>
                                    </div>
                                    <small style="color: #718096; display: block; margin-top: 5px;">
                                        <i class="icon-task"></i> 
                                        <strong>Select multiple tasks:</strong> Check all tasks you worked on
                                    </small>
                                    <select name="task_id" id="task_id" style="display: none;">
                                        <option value="">-- Select Task --</option>
                                        <?php if (!empty($all_tasks)): ?>
                                            <?php foreach ($all_tasks as $task): ?>
                                                <option value="<?php echo $task['id']; ?>"><?php echo htmlspecialchars($task['task_name']); ?></option>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </select>
                                </div>
                                
                                <div id="task_manual_container" style="<?php echo (!empty($edit_data['task_name']) && strpos($edit_data['task_name'], ',') !== false) ? 'display: block;' : 'display: none;'; ?>">
                                    <input type="text" name="task_name_manual" id="task_name_manual" class="form-control" placeholder="Enter custom task name" value="<?php echo (!empty($edit_data['task_name']) && strpos($edit_data['task_name'], ',') !== false) ? htmlspecialchars($edit_data['task_name']) : ''; ?>">
                                    <small style="color: #718096; display: block; margin-top: 5px;">
                                        <i class="icon-edit"></i> 
                                        Type a custom task name
                                    </small>
                                </div>
                                
                                <?php else: ?>
                                <input type="text" name="task_name_manual" class="form-control" placeholder="Enter task name" required value="<?php echo htmlspecialchars($edit_data['task_name']); ?>">
                                <small style="color: #e53e3e; display: block; margin-top: 5px;">
                                    <i class="icon-warning"></i> 
                                    Task database not set up.
                                </small>
                                <?php endif; ?>
                            </div>
                            
                            <div>
                                <label class="form-label"><span class="icon-comment">💬</span> Remarks</label>
                                <input type="text" name="remarks" class="form-control" placeholder="Enter remarks" value="<?php echo htmlspecialchars($edit_data['remarks'] ?? ''); ?>">
                            </div>
                            <div>
                                <label class="form-label"><span class="icon-flag">🏷️</span> Status *</label>
                                <select name="status" class="form-control" required>
                                    <option value="inprogress" <?php echo ($edit_data['status'] == 'inprogress') ? 'selected' : ''; ?>>⏳ In Progress</option>
                                    <option value="completed" <?php echo ($edit_data['status'] == 'completed') ? 'selected' : ''; ?>>✅ Completed</option>
                                    <option value="notstarted" <?php echo ($edit_data['status'] == 'notstarted') ? 'selected' : ''; ?>>⏸️ Not Started</option>
                                </select>
                            </div>
                        </div>
                        <div style="display: flex; align-items: center;">
                            <button type="submit" name="update_timesheet" class="btn-submit">
                                <i class="icon-save"></i> Update Timesheet Entry
                            </button>
                            <a href="?cancel_edit=1&from_date=<?php echo $from_date; ?>&to_date=<?php echo $to_date; ?><?php echo isset($_GET['user_id']) ? '&user_id=' . $_GET['user_id'] : ''; ?>" class="btn-cancel">
                                <i class="icon-cancel"></i> Cancel Edit
                            </a>
                        </div>
                    </form>
                </div>
                
                <!-- Add Timesheet Form -->
                <?php elseif (!$existing_timesheet && (!in_array($role, ['hr', 'admin', 'pm']) || isset($_GET['user_id']))): 
                    $approved_leave = hasApprovedLeaveOnDate($conn, $viewing_user_id, $from_date);
                    $holiday_name = isHoliday($from_date);
                    
                    if (!$approved_leave && $holiday_name === false):
                ?>
                <div class="timesheet-form">
                    <h4 style="margin-bottom: 20px; color: #4a5568;">
                        <i class="icon-plus" style="color: #667eea;"></i> 
                        Add Timesheet Entry for <?php echo $user_info['full_name'] ?? $selected_user_name; ?>
                        <?php if (date('N', strtotime($from_date)) == 7): ?>
                        <span class="sunday-work-badge">Sunday Work - Will get +1 Casual Leave if approved</span>
                        <?php endif; ?>
                    </h4>
                    <form method="POST" action="" id="timesheetForm">
                        <div class="timesheet-row">
                            <div>
                                <label class="form-label"><span class="icon-calendar"></span> Date *</label>
                                <input type="date" name="date" class="form-control" value="<?php echo $from_date; ?>" required>
                            </div>
                            <div>
                                <label class="form-label"><span class="icon-clock"></span> Hours *</label>
                                <div class="time-input">
                                    <input type="number" name="hours" class="form-control" placeholder="HH" min="0" max="12" value="8" required>
                                    <span class="time-separator">:</span>
                                    <input type="number" name="minutes" class="form-control" placeholder="MM" min="0" max="59" value="0" required>
                                </div>
                                <small style="color: #718096; display: block; margin-top: 5px;">Hours: 0-12 | Minutes: 0-59</small>
                            </div>
                            
                            <!-- Software with checkbox/tick options -->
                            <div class="dependent-dropdown">
                                <label class="form-label">
                                    <span class="icon-software"></span> Software * 
                                    <?php if ($is_admin_user): ?>
                                    <span class="admin-badge">Admin</span>
                                    <?php endif; ?>
                                </label>
                                
                                <?php if ($is_admin_user): ?>
                                <div class="software-toggle">
                                    <label>
                                        <input type="radio" name="software_type" value="dropdown" checked onchange="toggleSoftwareInput()"> 
                                        <i class="icon-list"></i> Select from list
                                    </label>
                                    <label>
                                        <input type="radio" name="software_type" value="manual" onchange="toggleSoftwareInput()"> 
                                        <i class="icon-edit"></i> Enter manually
                                    </label>
                                </div>
                                
                                <div id="software_dropdown_container">
                                    <div class="software-checkbox-group" id="multi_software_container">
                                        <?php foreach ($software_options as $software): 
                                        $icon = '💻';
                                        if ($software == 'Cyclone') $icon = '🌀';
                                        elseif ($software == 'AutoCAD') $icon = '📐';
                                        elseif ($software == 'HR') $icon = '👥';
                                        elseif ($software == 'Admin') $icon = '⚙️';
                                        elseif ($software == 'Other') $icon = '📁';
                                        elseif ($software == 'Training') $icon = '📚';
                                        ?>
                                            <div class="software-checkbox-item">
                                                <input type="checkbox" name="software_ids[]" value="<?php echo $software; ?>" id="software_<?php echo md5($software); ?>">
                                                <label for="software_<?php echo md5($software); ?>">
                                                    <span class="software-icon"><?php echo $icon; ?></span> <?php echo $software; ?>
                                                </label>
                                            </div>
                                        <?php endforeach; ?>
                                        <div class="selected-software-badge" id="selected_software_count">
                                            0 software selected
                                        </div>
                                    </div>
                                    <small style="color: #718096; display: block; margin-top: 5px;">
                                        <i class="icon-software"></i> 
                                        <strong>Select multiple software:</strong> Check all software you worked with
                                    </small>
                                    <select name="software" id="software_select" style="display: none;">
                                        <option value="">Select Software</option>
                                        <?php foreach ($software_options as $software): ?>
                                        <option value="<?php echo $software; ?>"><?php echo $software; ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                
                                <div id="software_manual_container" style="display: none;">
                                    <input type="text" name="software_manual" class="form-control" placeholder="Enter software names (comma separated)">
                                </div>
                                
                                <?php else: ?>
                                <div class="software-checkbox-group" id="multi_software_container">
                                    <?php foreach ($software_options as $software): 
                                    $icon = '💻';
                                    if ($software == 'Cyclone') $icon = '🌀';
                                    elseif ($software == 'AutoCAD') $icon = '📐';
                                    elseif ($software == 'HR') $icon = '👥';
                                    elseif ($software == 'Admin') $icon = '⚙️';
                                    elseif ($software == 'Other') $icon = '📁';
                                    elseif ($software == 'Training') $icon = '📚';
                                    ?>
                                        <div class="software-checkbox-item">
                                            <input type="checkbox" name="software_ids[]" value="<?php echo $software; ?>" id="software_<?php echo md5($software); ?>">
                                            <label for="software_<?php echo md5($software); ?>">
                                                <span class="software-icon"><?php echo $icon; ?></span> <?php echo $software; ?>
                                            </label>
                                        </div>
                                    <?php endforeach; ?>
                                    <div class="selected-software-badge" id="selected_software_count">
                                        0 software selected
                                    </div>
                                </div>
                                <small style="color: #718096; display: block; margin-top: 5px;">
                                    <i class="icon-software"></i> 
                                    <strong>Select multiple software:</strong> Check all software you worked with
                                </small>
                                <?php endif; ?>
                            </div>
                            
                            <!-- Project - KEEPING MANUAL ENTRY OPTION -->
                            <div class="dependent-dropdown">
                                <label class="form-label">
                                    <span class="icon-project"></span> Project * 
                                    <?php if ($is_admin_user): ?>
                                    <span class="admin-badge">Admin</span>
                                    <?php endif; ?>
                                </label>
                                
                                <?php if ($is_admin_user): ?>
                                <div class="project-toggle">
                                    <label>
                                        <input type="radio" name="project_type" value="dropdown" checked onchange="toggleProjectInput()"> 
                                        <i class="icon-list"></i> Select from list
                                    </label>
                                    <label>
                                        <input type="radio" name="project_type" value="manual" onchange="toggleProjectInput()"> 
                                        <i class="icon-edit"></i> Enter manually
                                    </label>
                                </div>
                                
                                <div id="project_dropdown_container">
                                    <!-- Single Project Dropdown -->
                                    <select name="project_id" id="project_id" class="form-control">
                                        <option value="">-- Select Project --</option>
                                        <?php if (!empty($projects)): ?>
                                            <?php foreach ($projects as $project): 
                                            $project_display = $project['project_name'];
                                            if (!empty($project['project_code'])) {
                                                $project_display .= ' [' . $project['project_code'] . ']';
                                            }
                                            ?>
                                            <option value="<?php echo $project['id']; ?>">
                                                📁 <?php echo htmlspecialchars($project_display); ?>
                                            </option>
                                            <?php endforeach; ?>
                                        <?php else: ?>
                                            <option value="" disabled>No projects available</option>
                                        <?php endif; ?>
                                    </select>
                                    <small style="color: #718096; display: block; margin-top: 5px;">
                                        <i class="icon-project"></i> 
                                        <strong>Select a single project:</strong> Choose the project you worked on
                                    </small>
                                </div>
                                
                                <div id="project_manual_container" style="display: none;">
                                    <input type="text" name="project_name_manual" class="form-control" placeholder="Enter project name">
                                </div>
                                
                                <?php else: ?>
                                <!-- Single Project Dropdown for non-admin users -->
                                <select name="project_id" id="project_id" class="form-control" required>
                                    <option value="">-- Select Project --</option>
                                    <?php if (!empty($projects)): ?>
                                        <?php foreach ($projects as $project): 
                                        $project_display = $project['project_name'];
                                        if (!empty($project['project_code'])) {
                                            $project_display .= ' [' . $project['project_code'] . ']';
                                        }
                                        ?>
                                        <option value="<?php echo $project['id']; ?>">
                                            📁 <?php echo htmlspecialchars($project_display); ?>
                                        </option>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <option value="" disabled>No projects available</option>
                                    <?php endif; ?>
                                </select>
                                <small style="color: #718096; display: block; margin-top: 5px;">
                                    <i class="icon-project"></i> 
                                    <strong>Select a single project:</strong> Choose the project you worked on
                                </small>
                                <?php endif; ?>
                            </div>
                            
                            <!-- Task -->
                            <div class="dependent-dropdown">
                                <label class="form-label">
                                    <span class="icon-task"></span> Task * 
                                    <span class="tasks-badge">
                                        <i class="icon-task"></i> <?php echo $unique_tasks_count; ?> Tasks
                                    </span>
                                </label>
                                
                                <?php if ($tasks_table_exists && !empty($all_tasks)): ?>
                                <div class="task-toggle">
                                    <label>
                                        <input type="radio" name="task_type" value="dropdown" checked onchange="toggleTaskInput()"> 
                                        <i class="icon-list"></i> Select from list
                                    </label>
                                    <label>
                                        <input type="radio" name="task_type" value="manual" onchange="toggleTaskInput()"> 
                                        <i class="icon-edit"></i> Enter manually
                                    </label>
                                </div>
                                
                                <div id="task_dropdown_container">
                                    <div class="task-checkbox-group" id="multi_task_container">
                                        <?php if (!empty($all_tasks)): ?>
                                            <?php foreach ($all_tasks as $task): 
                                            $icon = '⚡';
                                            if (strpos($task['task_name'], 'Electrical') !== false) $icon = '⚡';
                                            elseif (strpos($task['task_name'], 'Cable Trays') !== false) $icon = '🔌';
                                            elseif (strpos($task['task_name'], 'Support') !== false) $icon = '🛠️';
                                            elseif (strpos($task['task_name'], 'Railings') !== false) $icon = '🚧';
                                            elseif (strpos($task['task_name'], 'Architecture') !== false) $icon = '🏛️';
                                            elseif (strpos($task['task_name'], 'Stairs') !== false) $icon = '▁▃▅▇';
                                            elseif (strpos($task['task_name'], 'Equipment') !== false) $icon = '⚙️';
                                            elseif (strpos($task['task_name'], 'Piping') !== false) $icon = '🔧';
                                            elseif (strpos($task['task_name'], 'Structure') !== false) $icon = '🏗️';
                                            elseif (strpos($task['task_name'], 'HVAC') !== false) $icon = '❄️';
                                            ?>
                                                <div class="task-checkbox-item">
                                                    <input type="checkbox" name="task_ids[]" value="<?php echo $task['id']; ?>" id="task_<?php echo $task['id']; ?>">
                                                    <label for="task_<?php echo $task['id']; ?>">
                                                        <span class="task-icon"><?php echo $icon; ?></span> <?php echo htmlspecialchars($task['task_name']); ?>
                                                    </label>
                                                </div>
                                            <?php endforeach; ?>
                                            <div class="selected-tasks-badge" id="selected_tasks_count">
                                                0 tasks selected
                                            </div>
                                        <?php else: ?>
                                            <div style="padding: 10px; color: #718096;">No tasks available</div>
                                        <?php endif; ?>
                                    </div>
                                    <small style="color: #718096; display: block; margin-top: 5px;">
                                        <i class="icon-task"></i> 
                                        <strong>Select multiple tasks:</strong> Check all tasks you worked on
                                    </small>
                                    <select name="task_id" id="task_id" style="display: none;">
                                        <option value="">-- Select Task --</option>
                                        <?php if (!empty($all_tasks)): ?>
                                            <?php foreach ($all_tasks as $task): ?>
                                                <option value="<?php echo $task['id']; ?>"><?php echo htmlspecialchars($task['task_name']); ?></option>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </select>
                                </div>
                                
                                <div id="task_manual_container" style="display: none;">
                                    <input type="text" name="task_name_manual" id="task_name_manual" class="form-control" placeholder="Enter custom task name">
                                    <small style="color: #718096; display: block; margin-top: 5px;">
                                        <i class="icon-edit"></i> 
                                        Type a custom task name
                                    </small>
                                </div>
                                
                                <?php else: ?>
                                <input type="text" name="task_name_manual" class="form-control" placeholder="Enter task name" required>
                                <small style="color: #e53e3e; display: block; margin-top: 5px;">
                                    <i class="icon-warning"></i> 
                                    Task database not set up.
                                </small>
                                <?php endif; ?>
                            </div>
                            
                            <div>
                                <label class="form-label"><span class="icon-comment">💬</span> Remarks</label>
                                <input type="text" name="remarks" class="form-control" placeholder="Enter remarks">
                            </div>
                            <div>
                                <label class="form-label"><span class="icon-flag">🏷️</span> Status *</label>
                                <select name="status" class="form-control" required>
                                    <option value="inprogress">⏳ In Progress</option>
                                    <option value="completed" selected>✅ Completed</option>
                                    <option value="notstarted">⏸️ Not Started</option>
                                </select>
                            </div>
                        </div>
                        <button type="submit" name="submit_timesheet" class="btn-submit">
                            <i class="icon-plus"></i> Add Timesheet Entry
                        </button>
                    </form>
                </div>
                <?php elseif ($approved_leave): ?>
                <div class="existing-entry" style="background: #cce5ff; border-color: #004085; color: #004085;">
                    <i class="icon-check"></i>
                    <div>
                        <strong>Approved Leave on <?php echo $from_date; ?></strong><br>
                        You have approved leave on this date. Timesheet entry is not required for leave days.
                    </div>
                </div>
                <?php elseif ($holiday_name !== false): ?>
                <div class="existing-entry" style="background: #f3e8ff; border-color: #9b59b6; color: #6b21a8;">
                    <i class="icon-calendar"></i>
                    <div>
                        <strong>Holiday: <?php echo $holiday_name; ?></strong><br>
                        <?php echo date('d M Y', strtotime($from_date)); ?> is a company holiday. Timesheet entry is not required.
                    </div>
                </div>
                <?php endif; ?>
                <?php elseif ($existing_timesheet && (!in_array($role, ['hr', 'admin', 'pm']) || isset($_GET['user_id'])) && !$edit_mode): ?>
                <div class="existing-entry">
                    <i class="icon-check"></i>
                    <div>
                        <strong>Timesheet already submitted for <?php echo $from_date; ?></strong><br>
                        <?php echo $user_info['full_name'] ?? $selected_user_name; ?> has already submitted a timesheet for this date. Only one entry per day is allowed.
                    </div>
                </div>
                <?php endif; ?>

                <!-- Timesheet Entries - FIXED: ALWAYS use project_name from timesheets table -->
<div class="table-container">
    <table>
        <thead>
            <tr>
                <th>Date</th>
                <th>Hours</th>
                <th>Software</th>
                <th>Project</th>
                <th>Task</th>
                <th>Remarks</th>
                <th>Status</th>
                <?php if (in_array($role, ['hr', 'admin', 'pm'])): ?>
                <th>Employee</th>
                <?php endif; ?>
                <th>Submitted (IST)</th>
                <th>Actions</th>
                <th>LOP Status</th>
            </tr>
        </thead>
        <tbody>
            <?php if ($timesheets && $timesheets->num_rows > 0): ?>
                <?php 
                while ($entry = $timesheets->fetch_assoc()): 
                $whole_hours = floor($entry['hours'] ?? 0);
                $minutes = round(($entry['hours'] ?? 0) - $whole_hours) * 60;
                $can_delete = in_array($role, ['pm', 'hr', 'admin']) && ($entry['id'] > 0) && ($entry['status'] != 'approved');
                $can_edit = ($entry['user_id'] == $user_id) && ($entry['id'] > 0) && ($entry['status'] != 'approved') && ($entry['entry_date'] == $today_date);
                $has_lop = ($entry['has_lop'] ?? 0) > 0;
                $has_sunday_leave = ($entry['has_sunday_leave'] ?? 0) > 0;
                $is_approved = ($entry['status'] == 'approved');
                $is_late = ($entry['is_late'] ?? 0) > 0; // This will show late even after approval
                $is_sunday = (date('N', strtotime($entry['entry_date'])) == 7);
                
                $holiday_name = isHoliday($entry['entry_date']);
                $is_holiday = ($holiday_name !== false);
                $approved_leave = hasApprovedLeaveOnDate($conn, $entry['user_id'], $entry['entry_date']);
                
                // CRITICAL FIX: ALWAYS use project_name from timesheets table first
                $project_display = '';
                $project_code = '';
                
                // First priority: project_name from timesheets table (this contains the project name)
                if (!empty($entry['project_name'])) {
                    $project_display = $entry['project_name'];
                } 
                // Fallback: project_name from projects table via join
                elseif (!empty($entry['project_name_from_projects'])) {
                    $project_display = $entry['project_name_from_projects'];
                    if (!empty($entry['project_code'])) {
                        $project_code = $entry['project_code'];
                    }
                }
                
                $display_task_name = $entry['task_name'] ?? '';
                if (empty($display_task_name) && !empty($entry['task_name_from_tasks'])) {
                    $display_task_name = $entry['task_name_from_tasks'];
                }
                if (empty($display_task_name)) {
                    $display_task_name = '-';
                }
                
                // Add icons to task name
                $task_icon = '';
                if (strpos($display_task_name, 'Electrical') !== false) $task_icon = '⚡';
                elseif (strpos($display_task_name, 'Cable Trays') !== false) $task_icon = '🔌';
                elseif (strpos($display_task_name, 'Support') !== false) $task_icon = '🛠️';
                elseif (strpos($display_task_name, 'Railings') !== false) $task_icon = '🚧';
                elseif (strpos($display_task_name, 'Architecture') !== false) $task_icon = '🏛️';
                elseif (strpos($display_task_name, 'Stairs') !== false) $task_icon = '▁▃▅▇';
                elseif (strpos($display_task_name, 'Equipment') !== false) $task_icon = '⚙️';
                elseif (strpos($display_task_name, 'Piping') !== false) $task_icon = '🔧';
                elseif (strpos($display_task_name, 'Structure') !== false) $task_icon = '🏗️';
                elseif (strpos($display_task_name, 'HVAC') !== false) $task_icon = '❄️';
                ?>
                <tr class="<?php 
                    echo $has_lop ? 'lop-entry' : ''; 
                    echo $is_sunday ? 'sunday-row' : ''; 
                    echo $approved_leave ? 'leave-entry' : ''; 
                    echo $is_holiday ? 'holiday-entry' : ''; 
                ?>">
                    <td>
                        <div class="date-container">
                            <span><?php echo $entry['entry_date']; ?></span>
                            <?php if ($is_late): // Show late badge for ALL late submissions, regardless of approval status ?>
                            <span class="late-badge-below">LATE</span>
                            <?php endif; ?>
                            <?php if ($is_sunday): ?>
                            <span class="sunday-badge">Sunday</span>
                            <?php endif; ?>
                            <?php if ($is_holiday): ?>
                            <span class="holiday-badge"><?php echo $holiday_name; ?></span>
                            <?php endif; ?>
                        </div>
                    </td>
                    <td><?php echo $whole_hours; ?>h <?php echo $minutes; ?>m</td>
                    <td><?php echo $entry['software'] ?? '-'; ?></td>
                    <td class="project-name">
                        <?php 
                        if (!empty($project_display)) {
                            echo '📁 ' . htmlspecialchars($project_display);
                            if (!empty($project_code)) {
                                echo ' <span class="project-code">[' . htmlspecialchars($project_code) . ']</span>';
                            }
                        } else {
                            echo '-';
                        }
                        ?>
                    </td>
                    <td><strong><?php echo $task_icon . ' ' . htmlspecialchars($display_task_name); ?></strong></td>
                    <td title="<?php echo htmlspecialchars($entry['remarks'] ?? ''); ?>">
                        <?php echo $entry['remarks'] ? (strlen($entry['remarks']) > 30 ? substr($entry['remarks'], 0, 30) . '...' : $entry['remarks']) : '-'; ?>
                    </td>
                    <td>
                        <?php if ($is_approved): ?>
                            <span class="status-badge status-success">✅ Approved</span>
                        <?php elseif ($is_holiday): ?>
                            <span class="status-badge" style="background: #9b59b6; color: white;">🎉 Holiday</span>
                        <?php else: ?>
                            <?php
                            $status_labels = [
                                'inprogress' => '⏳ In Progress',
                                'completed' => '✅ Completed',
                                'notstarted' => '⏸️ Not Started'
                            ];
                            $status_colors = [
                                'inprogress' => 'warning',
                                'completed' => 'success',
                                'notstarted' => 'error'
                            ];
                            $status = $entry['status'] ?? 'inprogress';
                            ?>
                            <span class="status-badge status-<?php echo $status_colors[$status] ?? 'warning'; ?>">
                                <?php echo $status_labels[$status] ?? ucfirst($status); ?>
                            </span>
                        <?php endif; ?>
                    </td>
                    <?php if (in_array($role, ['hr', 'admin', 'pm'])): ?>
                    <td><strong><?php echo $entry['employee_name'] ?? ''; ?></strong></td>
                    <?php endif; ?>
                    <td><?php echo date('Y-m-d H:i', strtotime($entry['submitted_date'] ?? date('Y-m-d H:i:s'))) . ' IST'; ?></td>
                    <td>
    <div class="action-buttons" style="display: flex; gap: 5px; flex-wrap: wrap;">
        <?php if ($can_edit && !$is_sunday && !$approved_leave && !$is_holiday): ?>
        <a href="?edit=<?php echo $entry['id']; ?>&from_date=<?php echo $from_date; ?>&to_date=<?php echo $to_date; ?><?php echo isset($_GET['user_id']) ? '&user_id=' . $_GET['user_id'] : ''; ?>" 
           class="btn-small btn-edit">
            <i class="icon-edit"></i>
        </a>
        <?php elseif ($entry['user_id'] == $user_id && $entry['status'] != 'approved' && $entry['entry_date'] != $today_date && !$is_sunday && !$is_holiday): ?>
        <span class="btn-small btn-edit-disabled" title="Only today's entries can be edited">
            <i class="icon-edit"></i>
        </span>
        <?php endif; ?>
        
        <?php 
        // Check if delete is allowed - for PM/HR/Admin
        $can_delete_late = in_array($role, ['pm', 'hr', 'admin']) && $is_late && !$is_holiday;
        $can_delete_regular = in_array($role, ['pm', 'hr', 'admin']) && !$is_approved && !$is_holiday;
        $can_delete_sunday = in_array($role, ['pm', 'hr', 'admin']) && $is_sunday && !$is_holiday; // Always allow delete for Sunday entries
        
        if ($can_delete_late || $can_delete_regular || $can_delete_sunday): 
        ?>
            <?php if ($is_sunday && $has_sunday_leave): ?>
            <div class="delete-options">
                <a href="?delete=<?php echo $entry['id']; ?>&from_date=<?php echo $from_date; ?>&to_date=<?php echo $to_date; ?><?php echo isset($_GET['user_id']) ? '&user_id=' . $_GET['user_id'] : ''; ?>" 
                   class="btn-small btn-delete"
                   onclick="return confirm('Delete timesheet only? The associated Sunday leave will remain.')">
                    <i class="icon-delete"></i> TS Only
                </a>
                <a href="?delete=<?php echo $entry['id']; ?>&delete_sunday_leave=1&from_date=<?php echo $from_date; ?>&to_date=<?php echo $to_date; ?><?php echo isset($_GET['user_id']) ? '&user_id=' . $_GET['user_id'] : ''; ?>" 
                   class="btn-small btn-delete-sunday"
                   onclick="return confirm('Delete both timesheet AND the associated Sunday leave (+1 casual)? This action cannot be undone.')">
                    <i class="icon-delete"></i> TS + Leave
                </a>
            </div>
            <?php else: ?>
            <a href="?delete=<?php echo $entry['id']; ?>&from_date=<?php echo $from_date; ?>&to_date=<?php echo $to_date; ?><?php echo isset($_GET['user_id']) ? '&user_id=' . $_GET['user_id'] : ''; ?>" 
               class="btn-small btn-delete"
               onclick="return confirm('Are you sure you want to delete this timesheet entry? This will NOT create an auto-generated LOP entry.')">
                <i class="icon-delete"></i>
            </a>
            <?php endif; ?>
        <?php endif; ?>
        
        <?php if (in_array($role, ['pm', 'hr', 'admin']) && !$is_approved && $is_late && !$is_holiday): ?>
        <a href="?approve=<?php echo $entry['id']; ?>&from_date=<?php echo $from_date; ?>&to_date=<?php echo $to_date; ?><?php echo isset($_GET['user_id']) ? '&user_id=' . $_GET['user_id'] : ''; ?>" 
           class="btn-small btn-approve"
           onclick="return confirm('Approve this late timesheet? This will remove any auto-generated LOP for this date.')">
            <i class="icon-check"></i> Approve
        </a>
        <a href="?reject=<?php echo $entry['id']; ?>&from_date=<?php echo $from_date; ?>&to_date=<?php echo $to_date; ?><?php echo isset($_GET['user_id']) ? '&user_id=' . $_GET['user_id'] : ''; ?>" 
           class="btn-small btn-reject"
           style="background: #f56565;"
           onclick="return confirm('Reject this late timesheet? This will send notification to the employee.')">
            <i class="icon-cancel"></i> Reject
        </a>
        <?php endif; ?>
        
        <?php if (in_array($role, ['pm', 'hr', 'admin']) && !$is_approved && $is_sunday && !$is_holiday): ?>
        <a href="?approve=<?php echo $entry['id']; ?>&from_date=<?php echo $from_date; ?>&to_date=<?php echo $to_date; ?><?php echo isset($_GET['user_id']) ? '&user_id=' . $_GET['user_id'] : ''; ?>" 
           class="btn-small btn-approve" style="background: #ed8936;"
           onclick="return confirm('Approve Sunday work timesheet? This will add +1 casual leave for the employee.')">
            <i class="icon-check"></i> +1 Leave
        </a>
        <?php endif; ?>
        
        <?php if ($is_approved && $is_sunday): ?>
        <span class="btn-small" style="background: #ed8936; color: white;">
            <i class="icon-check"></i> +1 Leave
        </span>
        <?php elseif ($is_approved && !$is_sunday): ?>
        <span class="btn-small btn-approve-disabled">
            <i class="icon-check"></i> Approved
        </span>
        <?php endif; ?>
    </div>
</td>
                    <td>
                        <?php if ($is_sunday): ?>
                            <?php if ($is_approved): ?>
                            <span class="status-badge" style="background: #48bb78; color: white;">✅ +1 Casual Leave Added</span>
                            <?php else: ?>
                            <span class="status-badge" style="background: #ed8936; color: white;">⏳ Sunday Work (Pending)</span>
                            <?php endif; ?>
                        <?php elseif ($is_holiday): ?>
                        <span class="status-badge" style="background: #9b59b6; color: white;">🎉 Holiday - No LOP</span>
                        <?php elseif ($is_approved): ?>
                        <span class="status-badge status-success">✅ Approved - No LOP</span>
                        <?php elseif ($approved_leave): ?>
                        <span class="status-badge" style="background: #004085; color: white;">🏖️ On Leave</span>
                        <?php elseif ($has_lop && !$is_approved): ?>
                        <span class="status-badge status-error">⚠️ LOP (Pending Approval)</span>
                        <?php else: ?>
                        <span class="status-badge status-success">✅ No LOP</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endwhile; ?>
            <?php else: ?>
                <tr>
                    <td colspan="<?php 
                        $colspan = 10;
                        if (in_array($role, ['hr', 'admin', 'pm'])) $colspan += 1;
                        echo $colspan;
                    ?>" style="text-align: center; padding: 40px;">
                        <div class="no-timesheet">
                            <i class="icon-clock"></i>
                            <div>No timesheet entries found for <?php echo $from_date; ?><?php echo ($from_date != $to_date) ? ' to ' . $to_date : ''; ?></div>
                            <p style="margin-top: 10px; color: #718096;">
                                <i class="icon-info"></i> 
                                <?php if (in_array($role, ['hr', 'admin', 'pm']) && !isset($_GET['user_id'])): ?>
                                    No employees have submitted timesheets for this date range.
                                <?php else: ?>
                                    Click "Add Timesheet Entry" to create your first entry.
                                <?php endif; ?>
                            </p>
                        </div>
                    </td>
                </tr>
            <?php endif; ?>
        </tbody>
    </table>
</div>

    <script>
    function updateSelectedTasksCount() {
        const checkboxes = document.querySelectorAll('input[name="task_ids[]"]:checked');
        const countSpan = document.getElementById('selected_tasks_count');
        if (countSpan) {
            countSpan.textContent = checkboxes.length + ' task' + (checkboxes.length !== 1 ? 's' : '') + ' selected';
        }
    }
    
    function updateSelectedSoftwareCount() {
        const checkboxes = document.querySelectorAll('input[name="software_ids[]"]:checked');
        const countSpan = document.getElementById('selected_software_count');
        if (countSpan) {
            countSpan.textContent = checkboxes.length + ' software' + (checkboxes.length !== 1 ? 's' : '') + ' selected';
        }
    }
    
    function toggleTaskInput() {
        <?php if ($tasks_table_exists): ?>
        const taskTypeDropdown = document.querySelector('input[name="task_type"][value="dropdown"]');
        const taskTypeManual = document.querySelector('input[name="task_type"][value="manual"]');
        const dropdownContainer = document.getElementById('task_dropdown_container');
        const manualContainer = document.getElementById('task_manual_container');
        const taskSelect = document.getElementById('task_id');
        const taskManual = document.getElementById('task_name_manual');
        const multiTaskContainer = document.getElementById('multi_task_container');
        
        if (taskTypeDropdown && taskTypeDropdown.checked) {
            dropdownContainer.style.display = 'block';
            manualContainer.style.display = 'none';
            if (multiTaskContainer) {
                const checkboxes = multiTaskContainer.querySelectorAll('input[type="checkbox"]');
                checkboxes.forEach(cb => {
                    cb.disabled = false;
                });
            }
            if (taskSelect) {
                taskSelect.disabled = true;
            }
            if (taskManual) {
                taskManual.removeAttribute('required');
                taskManual.value = '';
            }
        } else if (taskTypeManual && taskTypeManual.checked) {
            dropdownContainer.style.display = 'none';
            manualContainer.style.display = 'block';
            if (multiTaskContainer) {
                const checkboxes = multiTaskContainer.querySelectorAll('input[type="checkbox"]');
                checkboxes.forEach(cb => {
                    cb.disabled = true;
                    cb.checked = false;
                });
            }
            if (taskSelect) {
                taskSelect.disabled = true;
                taskSelect.value = '';
            }
            if (taskManual) {
                taskManual.setAttribute('required', 'required');
            }
            updateSelectedTasksCount();
        }
        <?php endif; ?>
    }
    
    function toggleSoftwareInput() {
        const softwareTypeDropdown = document.querySelector('input[name="software_type"][value="dropdown"]');
        const softwareTypeManual = document.querySelector('input[name="software_type"][value="manual"]');
        const dropdownContainer = document.getElementById('software_dropdown_container');
        const manualContainer = document.getElementById('software_manual_container');
        const multiSoftwareContainer = document.getElementById('multi_software_container');
        const softwareManual = document.querySelector('input[name="software_manual"]');
        
        if (softwareTypeDropdown && softwareTypeDropdown.checked) {
            dropdownContainer.style.display = 'block';
            manualContainer.style.display = 'none';
            if (multiSoftwareContainer) {
                const checkboxes = multiSoftwareContainer.querySelectorAll('input[type="checkbox"]');
                checkboxes.forEach(cb => {
                    cb.disabled = false;
                });
            }
            if (softwareManual) {
                softwareManual.removeAttribute('required');
                softwareManual.value = '';
            }
        } else if (softwareTypeManual && softwareTypeManual.checked) {
            dropdownContainer.style.display = 'none';
            manualContainer.style.display = 'block';
            if (multiSoftwareContainer) {
                const checkboxes = multiSoftwareContainer.querySelectorAll('input[type="checkbox"]');
                checkboxes.forEach(cb => {
                    cb.disabled = true;
                    cb.checked = false;
                });
            }
            if (softwareManual) {
                softwareManual.setAttribute('required', 'required');
            }
            updateSelectedSoftwareCount();
        }
    }
    
    function toggleProjectInput() {
        const projectTypeDropdown = document.querySelector('input[name="project_type"][value="dropdown"]');
        const projectTypeManual = document.querySelector('input[name="project_type"][value="manual"]');
        const dropdownContainer = document.getElementById('project_dropdown_container');
        const manualContainer = document.getElementById('project_manual_container');
        
        if (projectTypeDropdown && projectTypeDropdown.checked) {
            dropdownContainer.style.display = 'block';
            manualContainer.style.display = 'none';
        } else if (projectTypeManual && projectTypeManual.checked) {
            dropdownContainer.style.display = 'none';
            manualContainer.style.display = 'block';
        }
    }
    
    function changeUser(userId) {
        const fromDate = document.getElementById('fromDate')?.value || '<?php echo $from_date; ?>';
        const toDate = document.getElementById('toDate')?.value || '<?php echo $to_date; ?>';
        if (fromDate && toDate) {
            if (userId) {
                window.location.href = `timesheet.php?user_id=${userId}&from_date=${fromDate}&to_date=${toDate}`;
            } else {
                window.location.href = `timesheet.php?from_date=${fromDate}&to_date=${toDate}`;
            }
        }
    }
    
    function changeDate(date) {
        window.location.href = `timesheet.php?from_date=${date}&to_date=${date}`;
    }
    
    function updateDateRange() {
        const fromDate = document.getElementById('fromDate').value;
        const toDate = document.getElementById('toDate').value;
        const userId = document.getElementById('userSelect')?.value || '';
        
        if (fromDate && toDate) {
            if (fromDate > toDate) {
                alert('From date cannot be after To date');
                return;
            }
            if (userId) {
                window.location.href = `timesheet.php?user_id=${userId}&from_date=${fromDate}&to_date=${toDate}`;
            } else {
                window.location.href = `timesheet.php?from_date=${fromDate}&to_date=${toDate}`;
            }
        }
    }
    
    document.addEventListener('DOMContentLoaded', function() {
        <?php if ($tasks_table_exists): ?>
        toggleTaskInput();
        
        const taskCheckboxes = document.querySelectorAll('input[name="task_ids[]"]');
        taskCheckboxes.forEach(cb => {
            cb.addEventListener('change', updateSelectedTasksCount);
        });
        <?php endif; ?>
        
        updateSelectedSoftwareCount();
        const softwareCheckboxes = document.querySelectorAll('input[name="software_ids[]"]');
        softwareCheckboxes.forEach(cb => {
            cb.addEventListener('change', updateSelectedSoftwareCount);
        });
        
        <?php if ($is_admin_user): ?>
        toggleSoftwareInput();
        toggleProjectInput();
        <?php endif; ?>
        
        updateSelectedTasksCount();
    });
    </script>
    
    <script src="../assets/js/app.js"></script>
</body>
</html>