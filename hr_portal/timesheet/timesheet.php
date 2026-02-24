<?php
require_once '../config/db.php';
require_once '../includes/icon_functions.php'; // ADDED

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

// Check if current user is a demo account (exempt from timesheet requirements)
$current_username = $_SESSION['username'];
$is_demo_account = in_array($current_username, ['admin', 'hr', 'projectmanager', 'pm']);

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

// Check if user is admin/HR/PM/MG/CEO
$is_admin_user = in_array($role, ['hr', 'admin', 'pm', 'MG']) || $user_info['reporting_to'] == 'CEO' || $user_info['position'] == 'CEO';

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

// Function to add LOP for missing timesheet (except Sundays)
function addLOPForMissingTimesheet($conn, $user_id, $date) {
    // Skip Sundays - no LOP for Sunday
    if (date('N', strtotime($date)) == 7) { // 7 = Sunday
        return false;
    }
    
    $leave_year = date('Y', strtotime($date)) . '-' . (date('Y', strtotime($date)) + 1);
    
    // Check if LOP already exists
    $check = $conn->prepare("SELECT id FROM leaves WHERE user_id = ? AND from_date = ? AND leave_type = 'LOP'");
    $check->bind_param("is", $user_id, $date);
    $check->execute();
    $result = $check->get_result();
    
    if ($result->num_rows == 0) {
        $insert = $conn->prepare("
            INSERT INTO leaves (user_id, leave_type, from_date, to_date, days, day_type, reason, status, applied_date, leave_year) 
            VALUES (?, 'LOP', ?, ?, 1, 'Full Day', 'Auto-generated LOP - Timesheet not submitted', 'Approved', NOW(), ?)
        ");
        $insert->bind_param("isss", $user_id, $date, $date, $leave_year);
        $insert->execute();
        $insert->close();
        return true;
    }
    $check->close();
    return false;
}

// Function to remove LOP when timesheet is approved
function removeLOPForTimesheet($conn, $user_id, $date) {
    $delete = $conn->prepare("DELETE FROM leaves WHERE user_id = ? AND from_date = ? AND leave_type = 'LOP' AND reason LIKE 'Auto-generated LOP%'");
    $delete->bind_param("is", $user_id, $date);
    $delete->execute();
    $affected = $delete->affected_rows;
    $delete->close();
    return $affected > 0;
}

// Function to check if timesheet is late (submitted after the day)
function isLateTimesheet($entry_date, $submitted_date) {
    $entry = strtotime($entry_date);
    $submitted = strtotime(date('Y-m-d', strtotime($submitted_date)));
    return $submitted > $entry;
}

// Function to count working days (excluding Sundays) in a date range
function countWorkingDays($from_date, $to_date) {
    $start = new DateTime($from_date);
    $end = new DateTime($to_date);
    $end->modify('+1 day');
    $interval = new DateInterval('P1D');
    $date_range = new DatePeriod($start, $interval, $end);
    
    $working_days = 0;
    foreach ($date_range as $date) {
        if ($date->format('N') != 7) { // 7 = Sunday
            $working_days++;
        }
    }
    return $working_days;
}

// Check for missing timesheets and add LOP (for admin/HR view) - EXEMPT DEMO ACCOUNTS AND SUNDAYS
if (in_array($role, ['hr', 'admin', 'pm']) && isset($_GET['check_missing'])) {
    $check_date = isset($_GET['check_date']) ? sanitize($_GET['check_date']) : date('Y-m-d', strtotime('-1 day'));
    
    // Skip if checking date is Sunday
    if (date('N', strtotime($check_date)) == 7) {
        $message = '<div class="alert alert-info"><i class="icon-info"></i> Skipped check for ' . $check_date . ' (Sunday - No LOP required).</div>';
    } else {
        // Get all users except demo accounts
        $users_result = $conn->query("SELECT id, username FROM users");
        $lop_added_count = 0;
        
        while ($user = $users_result->fetch_assoc()) {
            // Skip demo accounts
            if (in_array($user['username'], ['admin', 'hr', 'projectmanager', 'pm'])) {
                continue;
            }
            
            // Check if timesheet exists for this date
            $check_ts = $conn->prepare("SELECT id FROM timesheets WHERE user_id = ? AND entry_date = ?");
            $check_ts->bind_param("is", $user['id'], $check_date);
            $check_ts->execute();
            $ts_result = $check_ts->get_result();
            
            if ($ts_result->num_rows == 0) {
                // No timesheet - add LOP
                if (addLOPForMissingTimesheet($conn, $user['id'], $check_date)) {
                    $lop_added_count++;
                }
            }
            $check_ts->close();
        }
        
        $message = '<div class="alert alert-success"><i class="icon-success"></i> Checked for missing timesheets on ' . $check_date . '. Added ' . $lop_added_count . ' LOP entries.</div>';
    }
}

// Submit timesheet - FIXED: Prevent double entries and redirect, fixed manual entry
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_timesheet'])) {
    $date = sanitize($_POST['date']);
    $hours = floatval($_POST['hours']);
    $minutes = intval($_POST['minutes']);
    
    // Handle software - either from dropdown or manual entry
    $software_type = isset($_POST['software_type']) ? sanitize($_POST['software_type']) : 'dropdown';
    $software = '';
    
    if ($software_type === 'manual' && $is_admin_user) {
        $software = sanitize($_POST['software_manual']);
    } else {
        $software = sanitize($_POST['software']);
    }
    
    // Handle project - either from dropdown or manual entry
    $project_type = isset($_POST['project_type']) ? sanitize($_POST['project_type']) : 'dropdown';
    $project_id = 0;
    $project_name = '';
    
    if ($project_type === 'manual' && $is_admin_user) {
        $project_name = sanitize($_POST['project_name_manual']);
        $project_id = 0;
    } else {
        $project_id = intval($_POST['project_id']);
        // Get project name from project_id
        $stmt_project = $conn->prepare("SELECT project_name FROM projects WHERE id = ?");
        $stmt_project->bind_param("i", $project_id);
        $stmt_project->execute();
        $project_result = $stmt_project->get_result();
        $project_data = $project_result->fetch_assoc();
        $project_name = $project_data['project_name'];
        $stmt_project->close();
    }
    
    // Handle task - either from dropdown or manual entry
    $task_type = isset($_POST['task_type']) ? sanitize($_POST['task_type']) : 'dropdown';
    $task_id = 0;
    $task_name = '';
    
    // MODIFIED: Handle multiple tasks selection
    if ($task_type === 'manual') {
        $task_name = sanitize($_POST['task_name_manual']);
        $task_id = 0;
    } else {
        // Check if multiple tasks are selected
        if (isset($_POST['task_ids']) && is_array($_POST['task_ids'])) {
            $task_ids = $_POST['task_ids'];
            // Get task names for all selected tasks
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
            $task_id = 0; // No single task_id for multiple tasks
        } else {
            $task_id = isset($_POST['task_id']) ? intval($_POST['task_id']) : 0;
            if ($task_id > 0 && $tasks_table_exists) {
                // Get task name from tasks table
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
    
    // Calculate total hours
    $total_hours = $hours + ($minutes / 60);
    
    // Check if entry already exists for this date and user - PREVENT DOUBLE ENTRIES
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
        // Use IST for submission time
        $submitted_date = date('Y-m-d H:i:s');
        
        // Check if this is a late submission
        $is_late = ($date < date('Y-m-d'));
        
        // Insert the timesheet - ONLY ONE INSERT
        if ($tasks_table_exists && $task_id > 0) {
            // Insert with task_id
            $stmt = $conn->prepare("
                INSERT INTO timesheets (user_id, entry_date, hours, software, project_id, task_id, project_name, task_name, remarks, status, submitted_date)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->bind_param("isdssisssss", $viewing_user_id, $date, $total_hours, $software, $project_id, $task_id, $project_name, $task_name, $remarks, $status, $submitted_date);
        } else {
            // Insert without task_id (for manual tasks or multiple tasks)
            $stmt = $conn->prepare("
                INSERT INTO timesheets (user_id, entry_date, hours, software, project_id, project_name, task_name, remarks, status, submitted_date)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->bind_param("isdssissss", $viewing_user_id, $date, $total_hours, $software, $project_id, $project_name, $task_name, $remarks, $status, $submitted_date);
        }
        
        if ($stmt->execute()) {
            // Check if this is a late submission (submitted after the date)
            if ($is_late) {
                $message = '<div class="alert alert-warning"><i class="icon-warning"></i> Timesheet entry added successfully but marked as LATE SUBMISSION. LOP will remain until approved by PM.</div>';
            } else {
                $message = '<div class="alert alert-success"><i class="icon-success"></i> Timesheet entry added successfully!</div>';
            }
            
            // Redirect to refresh the page and show the newly added entry
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

// NEW: Edit timesheet entry - MODIFIED: Only allow editing for today's date
if (isset($_GET['edit'])) {
    $edit_id = intval($_GET['edit']);
    $today_date = date('Y-m-d');
    
    // Get the timesheet entry to edit
    $stmt_edit = $conn->prepare("SELECT * FROM timesheets WHERE id = ?");
    $stmt_edit->bind_param("i", $edit_id);
    $stmt_edit->execute();
    $edit_result = $stmt_edit->get_result();
    $edit_entry = $edit_result->fetch_assoc();
    $stmt_edit->close();
    
    // Check if user has permission to edit (only their own entry, not approved, and only for today's date)
    if ($edit_entry && $edit_entry['user_id'] == $user_id && $edit_entry['status'] != 'approved') {
        if ($edit_entry['entry_date'] == $today_date) {
            // Store in session for the edit form - only for today's date
            $_SESSION['edit_timesheet'] = $edit_entry;
            $_SESSION['edit_id'] = $edit_id;
        } else {
            $message = '<div class="alert alert-error"><i class="icon-error"></i> You can only edit timesheet entries for today\'s date. Past entries cannot be modified.</div>';
        }
    } else {
        $message = '<div class="alert alert-error"><i class="icon-error"></i> You can only edit your own pending timesheet entries.</div>';
    }
}

// NEW: Update timesheet entry after edit - FIXED: Time now updates to system time
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_timesheet'])) {
    $edit_id = intval($_POST['edit_id']);
    $date = sanitize($_POST['date']);
    $hours = floatval($_POST['hours']);
    $minutes = intval($_POST['minutes']);
    $today_date = date('Y-m-d');
    
    // Handle software - either from dropdown or manual entry
    $software_type = isset($_POST['software_type']) ? sanitize($_POST['software_type']) : 'dropdown';
    $software = '';
    
    if ($software_type === 'manual' && $is_admin_user) {
        $software = sanitize($_POST['software_manual']);
    } else {
        $software = sanitize($_POST['software']);
    }
    
    // Handle project - either from dropdown or manual entry
    $project_type = isset($_POST['project_type']) ? sanitize($_POST['project_type']) : 'dropdown';
    $project_id = 0;
    $project_name = '';
    
    if ($project_type === 'manual' && $is_admin_user) {
        $project_name = sanitize($_POST['project_name_manual']);
        $project_id = 0;
    } else {
        $project_id = intval($_POST['project_id']);
        // Get project name from project_id
        $stmt_project = $conn->prepare("SELECT project_name FROM projects WHERE id = ?");
        $stmt_project->bind_param("i", $project_id);
        $stmt_project->execute();
        $project_result = $stmt_project->get_result();
        $project_data = $project_result->fetch_assoc();
        $project_name = $project_data['project_name'];
        $stmt_project->close();
    }
    
    // Handle task - either from dropdown or manual entry
    $task_type = isset($_POST['task_type']) ? sanitize($_POST['task_type']) : 'dropdown';
    $task_id = 0;
    $task_name = '';
    
    if ($task_type === 'manual') {
        $task_name = sanitize($_POST['task_name_manual']);
        $task_id = 0;
    } else {
        // Check if multiple tasks are selected
        if (isset($_POST['task_ids']) && is_array($_POST['task_ids'])) {
            $task_ids = $_POST['task_ids'];
            // Get task names for all selected tasks
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
            $task_id = 0;
        } else {
            $task_id = isset($_POST['task_id']) ? intval($_POST['task_id']) : 0;
            if ($task_id > 0 && $tasks_table_exists) {
                // Get task name from tasks table
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
    
    // Calculate total hours
    $total_hours = $hours + ($minutes / 60);
    
    // Get current system time for update
    $current_time = date('Y-m-d H:i:s');
    
    // Verify the entry belongs to the current user, is not approved, and is for today's date
    $check_owner = $conn->prepare("SELECT user_id, status, entry_date FROM timesheets WHERE id = ?");
    $check_owner->bind_param("i", $edit_id);
    $check_owner->execute();
    $owner_result = $check_owner->get_result();
    $owner_data = $owner_result->fetch_assoc();
    $check_owner->close();
    
    if ($owner_data && $owner_data['user_id'] == $user_id && $owner_data['status'] != 'approved') {
        if ($owner_data['entry_date'] == $today_date) {
            // Update the timesheet - FIXED: Added submitted_date to update to current time
            if ($tasks_table_exists && $task_id > 0) {
                $stmt = $conn->prepare("
                    UPDATE timesheets 
                    SET entry_date = ?, hours = ?, software = ?, project_id = ?, task_id = ?, project_name = ?, task_name = ?, remarks = ?, status = ?, submitted_date = ?
                    WHERE id = ?
                ");
                $stmt->bind_param("sdssisssssi", $date, $total_hours, $software, $project_id, $task_id, $project_name, $task_name, $remarks, $status, $current_time, $edit_id);
            } else {
                $stmt = $conn->prepare("
                    UPDATE timesheets 
                    SET entry_date = ?, hours = ?, software = ?, project_id = ?, project_name = ?, task_name = ?, remarks = ?, status = ?, submitted_date = ?
                    WHERE id = ?
                ");
                $stmt->bind_param("sdsisssssi", $date, $total_hours, $software, $project_id, $project_name, $task_name, $remarks, $status, $current_time, $edit_id);
            }
            
            if ($stmt->execute()) {
                $message = '<div class="alert alert-success"><i class="icon-success"></i> Timesheet entry updated successfully!</div>';
                
                // Clear edit session
                unset($_SESSION['edit_timesheet']);
                unset($_SESSION['edit_id']);
                
                // Redirect to refresh the page
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
            $message = '<div class="alert alert-error"><i class="icon-error"></i> You can only edit timesheet entries for today\'s date. Past entries cannot be modified.</div>';
        }
    } else {
        $message = '<div class="alert alert-error"><i class="icon-error"></i> You can only edit your own pending timesheet entries.</div>';
    }
}

// NEW: Cancel edit
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

// PM Approval action - FIXED: Can approve late timesheets and redirect
if (isset($_GET['approve']) && in_array($role, ['pm', 'hr', 'admin'])) {
    $timesheet_id = intval($_GET['approve']);
    
    // Get timesheet details
    $get_ts = $conn->prepare("SELECT user_id, entry_date FROM timesheets WHERE id = ?");
    $get_ts->bind_param("i", $timesheet_id);
    $get_ts->execute();
    $ts_result = $get_ts->get_result();
    $ts_data = $ts_result->fetch_assoc();
    $get_ts->close();
    
    if ($ts_data) {
        // Update timesheet status to approved - CAN APPROVE LATE TIMESHEETS
        $update = $conn->prepare("UPDATE timesheets SET status = 'approved' WHERE id = ?");
        $update->bind_param("i", $timesheet_id);
        
        if ($update->execute()) {
            // REMOVE LOP WHEN APPROVED BY PM
            removeLOPForTimesheet($conn, $ts_data['user_id'], $ts_data['entry_date']);
            $message = '<div class="alert alert-success"><i class="icon-success"></i> Timesheet approved successfully. LOP removed.</div>';
            
            // Redirect to refresh the page and show the updated status
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

// Delete timesheet entry - FIXED: ONLY THE PERSON WHO ENTERED CAN DELETE
if (isset($_GET['delete'])) {
    $timesheet_id = intval($_GET['delete']);
    
    // First, get the user_id of this timesheet entry
    $get_user_stmt = $conn->prepare("SELECT user_id, entry_date FROM timesheets WHERE id = ?");
    $get_user_stmt->bind_param("i", $timesheet_id);
    $get_user_stmt->execute();
    $get_user_result = $get_user_stmt->get_result();
    $timesheet_data = $get_user_result->fetch_assoc();
    $timesheet_user_id = $timesheet_data['user_id'] ?? 0;
    $entry_date = $timesheet_data['entry_date'] ?? '';
    $get_user_stmt->close();
    
    // ONLY the person who entered can delete - NO ONE ELSE
    if ($timesheet_user_id == $user_id) {
        // User can delete only their own entry
        $stmt = $conn->prepare("DELETE FROM timesheets WHERE id = ? AND user_id = ?");
        $stmt->bind_param("ii", $timesheet_id, $user_id);
        
        if ($stmt->execute()) {
            // ADD LOP ENTRY WHEN TIMESHEET IS DELETED (MISSING) - Skip Sundays
            addLOPForMissingTimesheet($conn, $user_id, $entry_date);
            
            $message = '<div class="alert alert-success"><i class="icon-success"></i> Timesheet entry deleted successfully. LOP entry added for this date (except Sundays).</div>';
            
            // Redirect to refresh the page
            $redirect_url = "timesheet.php?from_date=" . urlencode($from_date) . "&to_date=" . urlencode($to_date);
            if (isset($_GET['user_id']) && $_GET['user_id'] != '') {
                $redirect_url .= "&user_id=" . urlencode($_GET['user_id']);
            }
            header("Location: $redirect_url");
            exit();
        } else {
            $message = '<div class="alert alert-error"><i class="icon-error"></i> Error deleting timesheet entry</div>';
        }
        $stmt->close();
    } else {
        // User is trying to delete someone else's entry
        $message = '<div class="alert alert-error"><i class="icon-error"></i> You can only delete your own timesheet entries</div>';
    }
}

// Auto-check for previous day on page load for HR/Admin/PM - EXEMPT DEMO ACCOUNTS AND SUNDAYS
if (in_array($role, ['hr', 'admin', 'pm'])) {
    $yesterday = date('Y-m-d', strtotime('-1 day'));
    
    // Skip if yesterday was Sunday
    if (date('N', strtotime($yesterday)) != 7) {
        // Get all users except demo accounts
        $users_result = $conn->query("SELECT id, username FROM users");
        $lop_added_count = 0;
        
        while ($user = $users_result->fetch_assoc()) {
            // Skip demo accounts
            if (in_array($user['username'], ['admin', 'hr', 'projectmanager', 'pm'])) {
                continue;
            }
            
            // Check if timesheet exists for this date
            $check_ts = $conn->prepare("SELECT id FROM timesheets WHERE user_id = ? AND entry_date = ?");
            $check_ts->bind_param("is", $user['id'], $yesterday);
            $check_ts->execute();
            $ts_result = $check_ts->get_result();
            
            if ($ts_result->num_rows == 0) {
                // No timesheet - add LOP
                if (addLOPForMissingTimesheet($conn, $user['id'], $yesterday)) {
                    $lop_added_count++;
                }
            }
            $check_ts->close();
        }
        
        if ($lop_added_count > 0) {
            $message = '<div class="alert alert-info"><i class="icon-info"></i> Auto-check completed: ' . $lop_added_count . ' LOP entries added for ' . $yesterday . '.</div>';
        }
    }
}

// Get timesheet entries for selected date range - FIXED: Ensure task_name is always selected from timesheets table
if (in_array($role, ['hr', 'admin', 'pm'])) {
    // HR/Admin/PM can view date range for ALL users
    if (isset($_GET['user_id']) && $_GET['user_id'] != '') {
        // View specific user - FIXED: Get task_name directly from timesheets table
        $stmt = $conn->prepare("
            SELECT t.*, u.full_name as employee_name, u.username, 
                   p.project_name as project_name_from_projects, p.project_code,
                   tk.task_name as task_name_from_tasks, tk.description as task_description,
                   (SELECT COUNT(*) FROM leaves WHERE user_id = t.user_id AND from_date = t.entry_date AND leave_type = 'LOP' AND reason LIKE 'Auto-generated LOP%') as has_lop,
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
        // View ALL users - FIXED: Get task_name directly from timesheets table
        $stmt = $conn->prepare("
            SELECT t.*, u.full_name as employee_name, u.username, 
                   p.project_name as project_name_from_projects, p.project_code,
                   tk.task_name as task_name_from_tasks, tk.description as task_description,
                   (SELECT COUNT(*) FROM leaves WHERE user_id = t.user_id AND from_date = t.entry_date AND leave_type = 'LOP' AND reason LIKE 'Auto-generated LOP%') as has_lop,
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
    // Regular employees can only view their own single day - FIXED: Get task_name directly from timesheets table
    $stmt = $conn->prepare("
        SELECT t.*, p.project_name as project_name_from_projects, p.project_code,
               tk.task_name as task_name_from_tasks, tk.description as task_description,
               (SELECT COUNT(*) FROM leaves WHERE user_id = t.user_id AND from_date = t.entry_date AND leave_type = 'LOP' AND reason LIKE 'Auto-generated LOP%') as has_lop,
               CASE WHEN t.submitted_date > CONCAT(t.entry_date, ' 23:59:59') THEN 1 ELSE 0 END as is_late
        FROM timesheets t 
        LEFT JOIN projects p ON t.project_id = p.id
        LEFT JOIN tasks tk ON t.task_id = tk.id
        WHERE t.user_id = ? AND t.entry_date = ? 
        ORDER BY t.submitted_date DESC
    ");
    $stmt->bind_param("is", $viewing_user_id, $from_date);
}
$stmt->execute();
$timesheets = $stmt->get_result();

// Calculate total hours for the selected period (excluding Sundays)
if (in_array($role, ['hr', 'admin', 'pm'])) {
    if (isset($_GET['user_id']) && $_GET['user_id'] != '') {
        // Total for specific user - exclude Sundays
        $stmt_total = $conn->prepare("
            SELECT COALESCE(SUM(hours), 0) as total_hours 
            FROM timesheets 
            WHERE user_id = ? AND entry_date BETWEEN ? AND ?
            AND DAYOFWEEK(entry_date) != 1
        ");
        $stmt_total->bind_param("iss", $viewing_user_id, $from_date, $to_date);
    } else {
        // Total for ALL users - exclude Sundays
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
        WHERE user_id = ? AND entry_date = ?
    ");
    $stmt_total->bind_param("is", $viewing_user_id, $from_date);
}
$stmt_total->execute();
$total_result = $stmt_total->get_result();
$total_row = $total_result->fetch_assoc();
$total_hours = $total_row['total_hours'];
$stmt_total->close();

// Get LOP count for the period (excluding Sundays)
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

// Get total submissions for today (excluding Sundays for the total)
$submissions_today = 0;
$working_days_in_range = countWorkingDays($from_date, $to_date);
if (in_array($role, ['hr', 'admin', 'pm'])) {
    $submissions_stmt = $conn->prepare("
        SELECT COUNT(DISTINCT user_id) as submission_count 
        FROM timesheets 
        WHERE entry_date = CURDATE()
    ");
    $submissions_stmt->execute();
    $submissions_result = $submissions_stmt->get_result();
    $submissions_row = $submissions_result->fetch_assoc();
    $submissions_today = $submissions_row['submission_count'] ?? 0;
    $submissions_stmt->close();
}

// Get all users for HR/Admin/PM dropdown
$users = [];
if (in_array($role, ['hr', 'admin', 'pm'])) {
    $users_result = $conn->query("SELECT id, username, full_name FROM users ORDER BY full_name");
    if ($users_result) {
        $users = $users_result->fetch_all(MYSQLI_ASSOC);
    }
}

// Get all projects for dropdown
$projects = [];
$projects_result = $conn->query("SELECT id, project_name, project_code FROM projects WHERE status = 'active' ORDER BY project_name");
if ($projects_result) {
    $projects = $projects_result->fetch_all(MYSQLI_ASSOC);
}

// Software options - ADDED HR and Admin
$software_options = ['Cyclone', 'Revit(cad to bim)','Revit(scan to bim)' ,'SP3D', 'AutoCAD', 'HR', 'Admin', 'Other', 'Training'];

// Set page title based on role
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

// TASKS: Electrical, Cable Trays, Support, Railings, Architecture, Stairs, Equipment, Piping, Structure
$all_tasks = [];
if ($tasks_table_exists) {
    // First, ensure all the tasks exist
    $task_list = [
        'Electrical', 
        'Cable Trays', 
        'Support', 
        'Railings', 
        'Architecture', 
        'Stairs', 
        'Equipment',
        'Piping',
        'Structure'
    ];
    
    foreach ($task_list as $task_name) {
        $check = $conn->query("SELECT id FROM tasks WHERE task_name = '$task_name' LIMIT 1");
        if ($check->num_rows == 0) {
            $category = 'Engineering';
            if ($task_name == 'Architecture') $category = 'Design';
            if ($task_name == 'Railings' || $task_name == 'Stairs') $category = 'Structural';
            if ($task_name == 'Piping') $category = 'Mechanical';
            if ($task_name == 'Structure') $category = 'Structural';
            $conn->query("INSERT INTO tasks (task_name, category, status) VALUES ('$task_name', '$category', 'active')");
        }
    }
    
    // Remove 'Other' task if it exists
    $conn->query("DELETE FROM tasks WHERE task_name = 'Other'");
    
    // Get ONLY the specified tasks
    $tasks_result = $conn->query("
        SELECT id, task_name, category 
        FROM tasks 
        WHERE status = 'active' 
        AND task_name IN (
            'Electrical', 
            'Cable Trays', 
            'Support', 
            'Railings', 
            'Architecture', 
            'Stairs', 
            'Equipment',
            'Piping',
            'Structure'
        )
        ORDER BY 
            CASE task_name
                WHEN 'Electrical' THEN 1
                WHEN 'Cable Trays' THEN 2
                WHEN 'Support' THEN 3
                WHEN 'Railings' THEN 4
                WHEN 'Architecture' THEN 5
                WHEN 'Stairs' THEN 6
                WHEN 'Equipment' THEN 7
                WHEN 'Piping' THEN 8
                WHEN 'Structure' THEN 9
            END
    ");
    
    if ($tasks_result) {
        $all_tasks = $tasks_result->fetch_all(MYSQLI_ASSOC);
    }
}

// Get task count
$unique_tasks_count = count($all_tasks);

// ============================================
// EXCEL EXPORT FUNCTIONALITY - NEW (Excluding Sundays)
// ============================================
if (isset($_GET['export_excel']) && in_array($role, ['hr', 'admin', 'pm'])) {
    $export_from = isset($_GET['export_from']) ? sanitize($_GET['export_from']) : $from_date;
    $export_to = isset($_GET['export_to']) ? sanitize($_GET['export_to']) : $to_date;
    $export_user_id = isset($_GET['export_user_id']) ? intval($_GET['export_user_id']) : 0;
    
    // Get all users for the period
    $users_query = "SELECT id, username, full_name, department, position FROM users";
    if ($export_user_id > 0) {
        $users_query .= " WHERE id = $export_user_id";
    }
    $users_query .= " ORDER BY full_name";
    $all_users_result = $conn->query($users_query);
    $all_users = $all_users_result->fetch_all(MYSQLI_ASSOC);
    
    // Generate Excel file
    $filename = "timesheet_report_" . date('Y-m-d') . ".xls";
    header("Content-Type: application/vnd.ms-excel");
    header("Content-Disposition: attachment; filename=\"$filename\"");
    header("Pragma: no-cache");
    header("Expires: 0");
    
    echo '<html>';
    echo '<head>';
    echo '<meta charset="UTF-8">';
    echo '<style>';
    echo 'td { mso-number-format:\@; vertical-align: top; }';
    echo '.date { mso-number-format:"yyyy-mm-dd"; }';
    echo '.header { background: #006400; color: white; font-weight: bold; text-align: center; }';
    echo '.subheader { background: #e8f5e9; font-weight: bold; }';
    echo '.applied { background: #d4edda; }';
    echo '.not-applied { background: #fff3cd; }';
    echo '.lop { color: #c53030; font-weight: bold; }';
    echo '.total { background: #f0f0f0; font-weight: bold; }';
    echo '.sunday { background: #e2e8f0; color: #718096; font-style: italic; }';
    echo 'h3 { color: #006400; margin-top: 20px; }';
    echo '</style>';
    echo '</head>';
    echo '<body>';
    
    // Title
    echo '<h2>MAKSIM HR - Timesheet Report</h2>';
    echo '<p><strong>Period:</strong> ' . date('d-m-Y', strtotime($export_from)) . ' to ' . date('d-m-Y', strtotime($export_to)) . ' (Excluding Sundays)</p>';
    echo '<p><strong>Generated on:</strong> ' . date('d-m-Y H:i:s') . ' IST</p>';
    echo '<br>';
    
    // Get all dates in the range (excluding Sundays for the header)
    $start_date = new DateTime($export_from);
    $end_date = new DateTime($export_to);
    $date_interval = new DateInterval('P1D');
    $date_range = new DatePeriod($start_date, $date_interval, $end_date->modify('+1 day'));
    
    $dates = [];
    $working_dates = [];
    foreach ($date_range as $date) {
        $date_str = $date->format('Y-m-d');
        $dates[] = $date_str;
        if ($date->format('N') != 7) { // Not Sunday
            $working_dates[] = $date_str;
        }
    }
    
    // Get all timesheets for the period
    $timesheets_query = "SELECT t.*, u.full_name, u.username 
                        FROM timesheets t 
                        JOIN users u ON t.user_id = u.id 
                        WHERE t.entry_date BETWEEN '$export_from' AND '$export_to'";
    if ($export_user_id > 0) {
        $timesheets_query .= " AND t.user_id = $export_user_id";
    }
    $timesheets_query .= " ORDER BY u.full_name, t.entry_date";
    
    $timesheets_result = $conn->query($timesheets_query);
    $timesheets_data = [];
    while ($row = $timesheets_result->fetch_assoc()) {
        $user_key = $row['full_name'] . ' (ID:' . $row['user_id'] . ')';
        $date_key = $row['entry_date'];
        $timesheets_data[$user_key][$date_key] = $row;
    }
    
    // Get all LOP entries for the period (auto-generated from missing timesheets, excluding Sundays)
    $lop_query = "SELECT l.*, u.full_name 
                  FROM leaves l 
                  JOIN users u ON l.user_id = u.id 
                  WHERE l.leave_type = 'LOP' 
                  AND l.reason LIKE 'Auto-generated LOP%'
                  AND l.from_date BETWEEN '$export_from' AND '$export_to'
                  AND DAYOFWEEK(l.from_date) != 1";
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
    
    // ===== SUMMARY STATISTICS =====
    echo '<h3>Summary Statistics (Excluding Sundays)</h3>';
    echo '<table border="1" cellpadding="5">';
    echo '<tr class="header"><th>Metric</th><th>Count</th></tr>';
    
    $total_users = count($all_users);
    $total_dates = count($working_dates);
    $total_possible_entries = $total_users * $total_dates;
    $total_actual_entries = $timesheets_result->num_rows;
    $total_lop_entries = 0;
    foreach ($lop_data as $user_lops) {
        $total_lop_entries += count($user_lops);
    }
    
    echo '<tr><td>Total Employees</td><td>' . $total_users . '</td></tr>';
    echo '<tr><td>Working Days (Mon-Sat)</td><td>' . $total_dates . '</td></tr>';
    echo '<tr><td>Total Possible Entries</td><td>' . $total_possible_entries . '</td></tr>';
    echo '<tr><td>Total Timesheet Submissions</td><td>' . $total_actual_entries . '</td></tr>';
    echo '<tr><td>Total Auto LOP Entries (Missing)</td><td>' . $total_lop_entries . '</td></tr>';
    echo '<tr><td>Submission Rate</td><td>' . round(($total_actual_entries / max($total_possible_entries, 1)) * 100, 2) . '%</td></tr>';
    echo '</table>';
    echo '<br>';
    
    // ===== USER SUMMARY =====
    echo '<h3>Employee Summary (Working Days Only)</h3>';
    echo '<table border="1" cellpadding="5">';
    echo '<tr class="header">';
    echo '<th>Employee</th>';
    echo '<th>Department</th>';
    echo '<th>Position</th>';
    echo '<th>Days Submitted</th>';
    echo '<th>Total Hours</th>';
    echo '<th>Auto LOP Days</th>';
    echo '<th>Submission %</th>';
    echo '</tr>';
    
    foreach ($all_users as $user) {
        $user_key = $user['full_name'] . ' (ID:' . $user['id'] . ')';
        
        // Count submissions for this user (only on working days)
        $user_submissions = 0;
        $user_hours = 0;
        if (isset($timesheets_data[$user_key])) {
            $user_submissions = count($timesheets_data[$user_key]);
            foreach ($timesheets_data[$user_key] as $entry) {
                $user_hours += floatval($entry['hours']);
            }
        }
        
        // Count LOP for this user
        $user_lop_days = isset($lop_data[$user_key]) ? count($lop_data[$user_key]) : 0;
        
        $submission_percent = $total_dates > 0 ? round(($user_submissions / $total_dates) * 100, 1) : 0;
        $status_class = $user_submissions == $total_dates ? 'applied' : ($user_submissions > 0 ? '' : 'not-applied');
        
        echo '<tr class="' . $status_class . '">';
        echo '<td><strong>' . htmlspecialchars($user['full_name']) . '</strong></td>';
        echo '<td>' . htmlspecialchars($user['department'] ?? '-') . '</td>';
        echo '<td>' . htmlspecialchars($user['position'] ?? '-') . '</td>';
        echo '<td>' . $user_submissions . '/' . $total_dates . '</td>';
        echo '<td>' . number_format($user_hours, 2) . '</td>';
        echo '<td class="lop">' . $user_lop_days . '</td>';
        echo '<td>' . $submission_percent . '%</td>';
        echo '</tr>';
    }
    echo '</table>';
    echo '<br>';
    
    // ===== DETAILED DAILY BREAKDOWN =====
    echo '<h3>Daily Timesheet Status (Sundays Excluded)</h3>';
    echo '<p><span style="background: #d4edda; padding: 2px 8px;">■ Submitted</span> ';
    echo '<span style="background: #fff3cd; padding: 2px 8px;">■ Not Submitted (Auto LOP)</span> ';
    echo '<span style="background: #e2e8f0; padding: 2px 8px;">■ Sunday (Not Counted)</span></p>';
    
    echo '<table border="1" cellpadding="5">';
    
    // Header row with dates (show Sundays but mark them)
    echo '<tr class="header">';
    echo '<th>Employee</th>';
    foreach ($dates as $date) {
        $day_of_week = date('N', strtotime($date));
        $display = date('d-m', strtotime($date));
        if ($day_of_week == 7) {
            echo '<th style="background: #718096;">' . $display . ' (Sun)</th>';
        } else {
            echo '<th>' . $display . '</th>';
        }
    }
    echo '<th>Total</th>';
    echo '</tr>';
    
    // Data rows for each user
    foreach ($all_users as $user) {
        $user_key = $user['full_name'] . ' (ID:' . $user['id'] . ')';
        $submission_count = 0;
        
        echo '<tr>';
        echo '<td><strong>' . htmlspecialchars($user['full_name']) . '</strong></td>';
        
        foreach ($dates as $date) {
            $day_of_week = date('N', strtotime($date));
            
            if ($day_of_week == 7) {
                // Sunday - show as excluded
                echo '<td style="background: #e2e8f0; text-align: center; color: #718096;">';
                echo 'Sun';
                echo '</td>';
            } else {
                $has_submission = isset($timesheets_data[$user_key][$date]);
                $has_lop = isset($lop_data[$user_key][$date]);
                
                if ($has_submission) {
                    $entry = $timesheets_data[$user_key][$date];
                    $hours = $entry['hours'];
                    $status = $entry['status'];
                    $hours_display = number_format($hours, 2);
                    $status_display = $status == 'approved' ? '✓' : '';
                    echo '<td style="background: #d4edda; text-align: center;">';
                    echo $hours_display . 'h ' . $status_display;
                    echo '</td>';
                    $submission_count++;
                } elseif ($has_lop) {
                    echo '<td style="background: #fff3cd; text-align: center; color: #c53030;">';
                    echo 'LOP';
                    echo '</td>';
                } else {
                    echo '<td style="background: #f0f0f0; text-align: center;">-</td>';
                }
            }
        }
        
        echo '<td style="font-weight: bold; text-align: center;">' . $submission_count . '/' . count($working_dates) . '</td>';
        echo '</tr>';
    }
    
    // Totals row
    echo '<tr class="total">';
    echo '<td><strong>Daily Totals</strong></td>';
    $daily_submissions = [];
    foreach ($dates as $date) {
        $daily_submissions[$date] = 0;
        $day_of_week = date('N', strtotime($date));
        if ($day_of_week != 7) {
            foreach ($all_users as $user) {
                $user_key = $user['full_name'] . ' (ID:' . $user['id'] . ')';
                if (isset($timesheets_data[$user_key][$date])) {
                    $daily_submissions[$date]++;
                }
            }
        }
    }
    
    foreach ($dates as $date) {
        $day_of_week = date('N', strtotime($date));
        if ($day_of_week == 7) {
            echo '<td style="text-align: center; background: #e2e8f0;">-</td>';
        } else {
            echo '<td style="text-align: center;"><strong>' . $daily_submissions[$date] . '</strong></td>';
        }
    }
    echo '<td style="text-align: center;"><strong>' . $total_actual_entries . '</strong></td>';
    echo '</tr>';
    
    echo '</table>';
    echo '<br>';
    
    // ===== DETAILED TIMESHEET ENTRIES =====
    if ($timesheets_result->num_rows > 0) {
        echo '<h3>Detailed Timesheet Entries</h3>';
        echo '<table border="1" cellpadding="5">';
        echo '<tr class="header">';
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
        echo '</tr>';
        
        $timesheets_result->data_seek(0);
        while ($entry = $timesheets_result->fetch_assoc()) {
            $is_late = $entry['is_late'] ?? 0;
            $status_class = $entry['status'] == 'approved' ? 'status-success' : '';
            
            echo '<tr>';
            echo '<td class="date">' . $entry['entry_date'] . '</td>';
            echo '<td><strong>' . htmlspecialchars($entry['full_name']) . '</strong></td>';
            echo '<td>' . htmlspecialchars($entry['department'] ?? '-') . '</td>';
            echo '<td>' . htmlspecialchars($entry['software'] ?? '-') . '</td>';
            echo '<td>' . htmlspecialchars($entry['project_name'] ?? '-') . '</td>';
            echo '<td>' . htmlspecialchars($entry['task_name'] ?? '-') . '</td>';
            echo '<td>' . number_format($entry['hours'], 2) . '</td>';
            echo '<td>' . ucfirst($entry['status']) . ($is_late ? ' (Late)' : '') . '</td>';
            echo '<td>' . htmlspecialchars($entry['remarks'] ?? '-') . '</td>';
            // Display submitted date in IST
            $submitted_display = $entry['submitted_date'] ?? $entry['entry_date'];
            echo '<td>' . date('Y-m-d H:i', strtotime($submitted_display)) . ' IST</td>';
            echo '<td>' . ($is_late ? 'Yes' : 'No') . '</td>';
            echo '</tr>';
        }
        echo '</table>';
        echo '<br>';
    }
    
    // ===== AUTO LOP ENTRIES =====
    if ($total_lop_entries > 0) {
        echo '<h3>Auto-generated LOP Entries (Missing Timesheets on Working Days)</h3>';
        echo '<table border="1" cellpadding="5">';
        echo '<tr class="header">';
        echo '<th>Date</th>';
        echo '<th>Employee</th>';
        echo '<th>Department</th>';
        echo '<th>Days</th>';
        echo '<th>Reason</th>';
        echo '</tr>';
        
        $lop_result->data_seek(0);
        while ($lop = $lop_result->fetch_assoc()) {
            // Get user details
            $user_info = null;
            foreach ($all_users as $u) {
                if ($u['id'] == $lop['user_id']) {
                    $user_info = $u;
                    break;
                }
            }
            
            echo '<tr class="lop">';
            echo '<td class="date">' . $lop['from_date'] . '</td>';
            echo '<td>' . htmlspecialchars($lop['full_name']) . '</td>';
            echo '<td>' . htmlspecialchars($user_info['department'] ?? '-') . '</td>';
            echo '<td>' . $lop['days'] . '</td>';
            echo '<td>' . htmlspecialchars($lop['reason']) . '</td>';
            echo '</tr>';
        }
        echo '</table>';
        echo '<br>';
    }
    
    echo '<hr>';
    echo '<p><em>Generated by MAKSIM PORTAL</em></p>';
    echo '</body>';
    echo '</html>';
    exit();
}
// ===== END EXCEL EXPORT FUNCTIONALITY =====

// Get task count
$unique_tasks_count = count($all_tasks);

// NEW: Check if we're in edit mode
$edit_mode = isset($_SESSION['edit_timesheet']) && isset($_SESSION['edit_id']);
$edit_data = $edit_mode ? $_SESSION['edit_timesheet'] : null;
$edit_id = $edit_mode ? $_SESSION['edit_id'] : 0;

// Calculate hours and minutes for edit mode
$edit_hours = 0;
$edit_minutes = 0;
if ($edit_mode && $edit_data) {
    $edit_hours = floor($edit_data['hours']);
    $edit_minutes = round(($edit_data['hours'] - $edit_hours) * 60);
}

// Today's date for display
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
    <style>
        .timesheet-form {
            background: white;
            border-radius: 15px;
            padding: 25px;
            margin-bottom: 30px;
            box-shadow: 0 5px 15px rgba(0,0,0,0.05);
        }
        
        .timesheet-row {
            display: grid;
            grid-template-columns: 1fr 0.8fr 1.2fr 1.5fr 1.5fr 1.2fr 1fr;
            gap: 15px;
            margin-bottom: 20px;
            padding-bottom: 20px;
            border-bottom: 1px solid #e2e8f0;
        }
        
        .time-input {
            display: flex;
            align-items: center;
            gap: 5px;
        }
        
        .time-input input {
            width: 70px;
            padding: 10px 8px;
            text-align: center;
            font-size: 14px;
            border: 1px solid #e2e8f0;
            border-radius: 6px;
        }
        
        .time-separator {
            font-weight: bold;
            color: #718096;
            font-size: 18px;
        }
        
        .no-timesheet {
            text-align: center;
            padding: 40px;
            color: #718096;
        }
        
        .no-timesheet i {
            font-size: 48px;
            margin-bottom: 15px;
            color: #cbd5e0;
        }
        
        .date-range-picker {
            display: flex;
            gap: 10px;
            align-items: center;
            margin-right: 15px;
        }
        
        .date-range-picker .form-control {
            width: 150px;
        }
        
        .btn-submit {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border: none;
            padding: 12px 30px;
            border-radius: 8px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            font-size: 14px;
        }
        
        .btn-submit:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 20px rgba(102, 126, 234, 0.3);
        }
        
        .btn-edit {
            background: #4299e1;
            color: white;
            border: none;
            padding: 4px 10px;
            border-radius: 4px;
            font-size: 12px;
            cursor: pointer;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 5px;
        }
        
        .btn-edit:hover {
            background: #3182ce;
        }
        
        .btn-edit-disabled {
            background: #cbd5e0;
            color: #718096;
            border: none;
            padding: 4px 10px;
            border-radius: 4px;
            font-size: 12px;
            cursor: not-allowed;
            pointer-events: none;
            opacity: 0.6;
            display: inline-flex;
            align-items: center;
            gap: 5px;
            text-decoration: none;
        }
        
        .btn-cancel {
            background: #a0aec0;
            color: white;
            border: none;
            padding: 12px 30px;
            border-radius: 8px;
            font-weight: 600;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            font-size: 14px;
            text-decoration: none;
            margin-left: 10px;
        }
        
        .btn-cancel:hover {
            background: #718096;
        }
        
        .existing-entry {
            background: #f0fff4;
            border: 1px solid #9ae6b4;
            color: #276749;
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .project-management, .task-management {
            background: #ebf8ff;
            border: 1px solid #90cdf4;
            padding: 20px;
            border-radius: 8px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            flex-wrap: wrap;
            gap: 15px;
        }
        
        .task-management {
            background: #f0e7ff;
            border-color: #9f7aea;
        }
        
        .task-management i {
            color: #805ad5;
        }
        
        .project-management-buttons, .task-management-buttons {
            display: flex;
            gap: 10px;
            margin-left: auto;
        }
        
        .btn-project, .btn-task {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 8px 16px;
            border-radius: 6px;
            font-weight: 600;
            text-decoration: none;
            transition: all 0.3s ease;
            font-size: 14px;
        }
        
        .btn-project-add, .btn-task-add {
            background: #4299e1;
            color: white;
        }
        
        .btn-project-remove, .btn-task-remove {
            background: #f56565;
            color: white;
        }
        
        .btn-project-view, .btn-task-view {
            background: #48bb78;
            color: white;
        }
        
        .role-badge {
            background: #edf2f7;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 12px;
            color: #4a5568;
            display: inline-flex;
            align-items: center;
            gap: 5px;
        }
        
        .all-employees-badge {
            background: #667eea;
            color: white;
            padding: 6px 15px;
            border-radius: 20px;
            font-size: 14px;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }
        
        .task-select {
            width: 100%;
        }
        
        .dependent-dropdown {
            position: relative;
        }
        
        .task-toggle, .software-toggle, .project-toggle {
            display: flex;
            gap: 20px;
            margin-bottom: 10px;
            flex-wrap: wrap;
        }
        
        .task-toggle label, .software-toggle label, .project-toggle label {
            display: flex;
            align-items: center;
            gap: 5px;
            cursor: pointer;
            color: #4a5568;
        }
        
        .manual-input {
            margin-top: 10px;
        }
        
        .tasks-badge {
            background: #805ad5;
            color: white;
            padding: 2px 10px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 600;
            margin-left: 10px;
        }
        
        .admin-badge {
            background: #ed8936;
            color: white;
            padding: 2px 8px;
            border-radius: 12px;
            font-size: 10px;
            font-weight: 600;
            margin-left: 5px;
        }
        
        .task-icon {
            margin-right: 5px;
        }
        
        .submissions-badge {
            background: #ed8936;
            color: white;
            padding: 6px 15px;
            border-radius: 20px;
            font-size: 14px;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }
        
        .lop-badge {
            background: #c53030;
            color: white;
            padding: 2px 8px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 600;
            margin-left: 5px;
        }
        
        .btn-approve {
            background: #48bb78;
            color: white;
            border: none;
            padding: 4px 10px;
            border-radius: 4px;
            font-size: 12px;
            cursor: pointer;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 5px;
        }
        
        .btn-approve:hover {
            background: #38a169;
        }
        
        .btn-approve-disabled {
            background: #a0aec0;
            color: white;
            border: none;
            padding: 4px 10px;
            border-radius: 4px;
            font-size: 12px;
            opacity: 0.6;
            cursor: not-allowed;
            pointer-events: none;
            display: inline-flex;
            align-items: center;
            gap: 5px;
        }
        
        .lop-entry {
            background-color: #f0f0f0;
        }
        
        .lop-entry td {
            color: #666666;
        }
        
        .btn-delete {
            background: #f56565;
            color: white;
            border: none;
            padding: 4px 10px;
            border-radius: 4px;
            font-size: 12px;
            cursor: pointer;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 5px;
        }
        
        .btn-delete:hover {
            background: #e53e3e;
        }
        
        .btn-small {
            padding: 4px 10px;
            border-radius: 4px;
            font-size: 12px;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 5px;
        }
        
        .late-badge {
            background: #ed8936;
            color: white;
            padding: 2px 6px;
            border-radius: 4px;
            font-size: 10px;
            margin-left: 5px;
        }
        
        /* NEW: Export button styles */
        .btn-export {
            background: #28a745;
            color: white;
            border: none;
            padding: 8px 15px;
            border-radius: 6px;
            font-weight: 600;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            text-decoration: none;
            font-size: 14px;
        }
        
        .btn-export:hover {
            background: #218838;
            transform: translateY(-2px);
            box-shadow: 0 5px 10px rgba(40, 167, 69, 0.3);
        }
        
        .export-container {
            display: inline-block;
        }
        
        /* NEW: Multi-task select styles */
        .task-checkbox-group {
            max-height: 200px;
            overflow-y: auto;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            padding: 10px;
            background: #f9f9f9;
        }
        
        .task-checkbox-item {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 8px;
            border-bottom: 1px solid #e2e8f0;
        }
        
        .task-checkbox-item:last-child {
            border-bottom: none;
        }
        
        .task-checkbox-item:hover {
            background: #edf2f7;
        }
        
        .task-checkbox-item input[type="checkbox"] {
            width: 18px;
            height: 18px;
            cursor: pointer;
        }
        
        .task-checkbox-item label {
            cursor: pointer;
            flex: 1;
            font-size: 14px;
        }
        
        .selected-tasks-badge {
            background: #4299e1;
            color: white;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 12px;
            display: inline-block;
            margin-top: 5px;
        }
        
        .edit-mode-badge {
            background: #4299e1;
            color: white;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 12px;
            margin-left: 10px;
        }
        
        /* NEW: Sunday indicator styles */
        .sunday-row {
            background-color: #e2e8f0;
            color: #718096;
        }
        
        .sunday-row td {
            color: #718096;
        }
        
        .sunday-badge {
            background: #718096;
            color: white;
            padding: 2px 6px;
            border-radius: 4px;
            font-size: 10px;
            margin-left: 5px;
        }
        
        .working-days-note {
            background: #ebf8ff;
            border: 1px solid #4299e1;
            color: #2c5282;
            padding: 10px 15px;
            border-radius: 8px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 14px;
        }
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
            <!-- Working Days Note -->
            <div class="working-days-note">
                <i class="icon-info"></i>
                <div>
                    <strong>Note:</strong> Sundays are excluded from all calculations. 
                    No LOP is generated for Sundays, and they are not counted in working days.
                    <?php if ($working_days_in_range > 0): ?>
                    <span style="font-weight: bold; margin-left: 10px;">Working days in range: <?php echo $working_days_in_range; ?></span>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- LOP Check Section -->
            <div class="project-management" style="background: #fed7d7; border-color: #fc8181;">
                <i class="icon-warning" style="color: #c53030;"></i>
                <div style="flex: 1;">
                    <strong style="color: #c53030;">Auto LOP Tracking:</strong> 
                    <?php echo $lop_count; ?> auto-generated LOP entries found in this date range (Sundays excluded).
                </div>
                <div>
                    <a href="?check_missing=1&check_date=<?php echo date('Y-m-d', strtotime('-1 day')); ?><?php echo isset($_GET['user_id']) ? '&user_id=' . $_GET['user_id'] : ''; ?>&from_date=<?php echo $from_date; ?>&to_date=<?php echo $to_date; ?>" class="btn-check" style="background: #805ad5; color: white; padding: 8px 16px; border-radius: 6px; text-decoration: none;">
                        <i class="icon-check"></i> Check Missing
                    </a>
                </div>
            </div>
            
            <!-- Project Management Section -->
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
            
            <!-- Task Management Section - ALL TASKS -->
            <div class="task-management">
                <div style="display: flex; align-items: center; gap: 15px;">
                    <i class="icon-task"></i>
                    <div>
                        <strong style="font-size: 16px; color: #2d3748;">Engineering & Design Tasks</strong>
                        <span class="tasks-badge">
                            <i class="icon-task"></i> Electrical
                        </span>
                        <span class="tasks-badge">
                            <i class="icon-task"></i> Cable Trays
                        </span>
                        <span class="tasks-badge">
                            <i class="icon-task"></i> Architecture
                        </span>
                        <span class="tasks-badge">
                            <i class="icon-task"></i> Piping
                        </span>
                        <span class="tasks-badge">
                            <i class="icon-task"></i> Structure
                        </span>
                        <div style="display: flex; gap: 10px; margin-top: 5px;">
                            <span class="role-badge">
                                <i class="icon-list"></i> 
                                Electrical | Cable Trays | Support | Railings | Architecture | Stairs | Equipment | Piping | Structure
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
            
            <!-- Quick Stats with LOP Count -->
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
                    <div style="color: #ed8936; font-size: 12px; text-transform: uppercase; letter-spacing: 1px;">Submissions Today</div>
                    <div style="font-size: 28px; font-weight: bold; color: #2d3748; display: flex; align-items: center; gap: 10px;">
                        <?php echo $submissions_today; ?>
                        <span style="font-size: 14px; font-weight: normal; color: #718096;">
                            <?php echo $submissions_today == 1 ? 'employee' : 'employees'; ?>
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
                        
                        <!-- Date Range Picker -->
                        <div class="date-range-picker">
                            <input type="date" id="fromDate" class="form-control" value="<?php echo $from_date; ?>" onchange="updateDateRange()">
                            <span style="color: #718096;">to</span>
                            <input type="date" id="toDate" class="form-control" value="<?php echo $to_date; ?>" onchange="updateDateRange()">
                        </div>
                        
                        <!-- NEW: Export Excel Button -->
                        <div class="export-container">
                            <a href="?export_excel=1&export_from=<?php echo $from_date; ?>&export_to=<?php echo $to_date; ?><?php echo isset($_GET['user_id']) && $_GET['user_id'] != '' ? '&export_user_id=' . $_GET['user_id'] : ''; ?>" class="btn-export" target="_blank">
                                <i class="icon-excel"></i> Export Excel
                            </a>
                        </div>
                        
                        <?php else: ?>
                        <!-- Single Date Picker for Regular Employees -->
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

                <!-- Edit Timesheet Form - Show when in edit mode -->
                <?php if ($edit_mode && $edit_data): ?>
                <div class="timesheet-form" style="border: 2px solid #4299e1;">
                    <h4 style="margin-bottom: 20px; color: #4299e1;">
                        <i class="icon-edit" style="color: #4299e1;"></i> 
                        Editing Timesheet Entry for <?php echo $from_date; ?>
                    </h4>
                    <form method="POST" action="" id="timesheetForm">
                        <div class="timesheet-row">
                            <div>
                                <label class="form-label">Date *</label>
                                <input type="date" name="date" class="form-control" value="<?php echo $edit_data['entry_date']; ?>" required>
                                <input type="hidden" name="edit_id" value="<?php echo $edit_id; ?>">
                            </div>
                            <div>
                                <label class="form-label">Hours *</label>
                                <div class="time-input">
                                    <input type="number" name="hours" class="form-control" placeholder="HH" min="0" max="12" value="<?php echo $edit_hours; ?>" required>
                                    <span class="time-separator">:</span>
                                    <input type="number" name="minutes" class="form-control" placeholder="MM" min="0" max="59" value="<?php echo $edit_minutes; ?>" required>
                                </div>
                                <small style="color: #718096; display: block; margin-top: 5px;">Hours: 0-12 | Minutes: 0-59</small>
                            </div>
                            
                            <!-- Software with manual option for admin users -->
                            <div class="dependent-dropdown">
                                <label class="form-label">
                                    Software * 
                                    <?php if ($is_admin_user): ?>
                                    <span class="admin-badge">Admin</span>
                                    <?php endif; ?>
                                </label>
                                
                                <?php if ($is_admin_user): ?>
                                <div class="software-toggle">
                                    <label>
                                        <input type="radio" name="software_type" value="dropdown" <?php echo (!isset($edit_data['software']) || !in_array($edit_data['software'], $software_options)) ? 'checked' : ''; ?> onchange="toggleSoftwareInput()"> 
                                        <i class="icon-list"></i> Select from list
                                    </label>
                                    <label>
                                        <input type="radio" name="software_type" value="manual" <?php echo (isset($edit_data['software']) && !in_array($edit_data['software'], $software_options)) ? 'checked' : ''; ?> onchange="toggleSoftwareInput()"> 
                                        <i class="icon-edit"></i> Enter manually
                                    </label>
                                </div>
                                
                                <div id="software_dropdown_container" style="<?php echo (isset($edit_data['software']) && !in_array($edit_data['software'], $software_options)) ? 'display: none;' : 'display: block;'; ?>">
                                    <select name="software" class="form-control" <?php echo (isset($edit_data['software']) && !in_array($edit_data['software'], $software_options)) ? '' : 'required'; ?>>
                                        <option value="">Select Software</option>
                                        <?php foreach ($software_options as $software): ?>
                                        <option value="<?php echo $software; ?>" <?php echo ($edit_data['software'] == $software) ? 'selected' : ''; ?>><?php echo $software; ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                
                                <div id="software_manual_container" style="<?php echo (isset($edit_data['software']) && !in_array($edit_data['software'], $software_options)) ? 'display: block;' : 'display: none;'; ?>">
                                    <input type="text" name="software_manual" class="form-control" placeholder="Enter software name" value="<?php echo (isset($edit_data['software']) && !in_array($edit_data['software'], $software_options)) ? htmlspecialchars($edit_data['software']) : ''; ?>">
                                </div>
                                
                                <?php else: ?>
                                <select name="software" class="form-control" required>
                                    <option value="">Select Software</option>
                                    <?php foreach ($software_options as $software): ?>
                                    <option value="<?php echo $software; ?>" <?php echo ($edit_data['software'] == $software) ? 'selected' : ''; ?>><?php echo $software; ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <?php endif; ?>
                            </div>
                            
                            <!-- Project with manual option for admin users -->
                            <div class="dependent-dropdown">
                                <label class="form-label">
                                    Project * 
                                    <?php if ($is_admin_user): ?>
                                    <span class="admin-badge">Admin</span>
                                    <?php endif; ?>
                                </label>
                                
                                <?php if ($is_admin_user): ?>
                                <div class="project-toggle">
                                    <label>
                                        <input type="radio" name="project_type" value="dropdown" <?php echo ($edit_data['project_id'] > 0) ? 'checked' : ''; ?> onchange="toggleProjectInput()"> 
                                        <i class="icon-list"></i> Select from list
                                    </label>
                                    <label>
                                        <input type="radio" name="project_type" value="manual" <?php echo ($edit_data['project_id'] == 0 && !empty($edit_data['project_name'])) ? 'checked' : ''; ?> onchange="toggleProjectInput()"> 
                                        <i class="icon-edit"></i> Enter manually
                                    </label>
                                </div>
                                
                                <div id="project_dropdown_container" style="<?php echo ($edit_data['project_id'] == 0 && !empty($edit_data['project_name'])) ? 'display: none;' : 'display: block;'; ?>">
                                    <select name="project_id" id="project_id" class="form-control" <?php echo ($edit_data['project_id'] == 0 && !empty($edit_data['project_name'])) ? '' : 'required'; ?>>
                                        <option value="">Select Project</option>
                                        <?php foreach ($projects as $project): ?>
                                        <option value="<?php echo $project['id']; ?>" <?php echo ($edit_data['project_id'] == $project['id']) ? 'selected' : ''; ?>>
                                            <?php echo $project['project_code'] ? '[' . $project['project_code'] . '] ' : ''; ?>
                                            <?php echo $project['project_name']; ?>
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <?php if (empty($projects)): ?>
                                    <small style="color: #e53e3e; display: block; margin-top: 5px;">
                                        <i class="icon-warning"></i> 
                                        No active projects found. Please contact HR or Project Manager.
                                    </small>
                                    <?php endif; ?>
                                </div>
                                
                                <div id="project_manual_container" style="<?php echo ($edit_data['project_id'] == 0 && !empty($edit_data['project_name'])) ? 'display: block;' : 'display: none;'; ?>">
                                    <input type="text" name="project_name_manual" class="form-control" placeholder="Enter project name" value="<?php echo ($edit_data['project_id'] == 0 && !empty($edit_data['project_name'])) ? htmlspecialchars($edit_data['project_name']) : ''; ?>">
                                </div>
                                
                                <?php else: ?>
                                <select name="project_id" id="project_id" class="form-control" required>
                                    <option value="">Select Project</option>
                                    <?php foreach ($projects as $project): ?>
                                    <option value="<?php echo $project['id']; ?>" <?php echo ($edit_data['project_id'] == $project['id']) ? 'selected' : ''; ?>>
                                        <?php echo $project['project_code'] ? '[' . $project['project_code'] . '] ' : ''; ?>
                                        <?php echo $project['project_name']; ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                                <?php if (empty($projects)): ?>
                                <small style="color: #e53e3e; display: block; margin-top: 5px;">
                                    <i class="icon-warning"></i> 
                                    No active projects found. Please contact HR or Project Manager.
                                </small>
                                <?php endif; ?>
                                <?php endif; ?>
                            </div>
                            
                            <!-- TASK DROPDOWN - Electrical, Cable Trays, Support, Railings, Architecture, Stairs, Equipment, Piping, Structure -->
                            <div class="dependent-dropdown">
                                <label class="form-label">
                                    Task * 
                                    <span class="tasks-badge">
                                        <i class="icon-task"></i> 9 Tasks
                                    </span>
                                </label>
                                
                                <?php if ($tasks_table_exists): ?>
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
                                    <!-- MODIFIED: Changed from select to checkbox group for multi-select -->
                                    <div class="task-checkbox-group" id="multi_task_container">
                                        <?php if (!empty($all_tasks)): ?>
                                            <?php 
                                            $selected_tasks = explode(', ', $edit_data['task_name']);
                                            foreach ($all_tasks as $task): 
                                            $checked = in_array($task['task_name'], $selected_tasks) ? 'checked' : '';
                                            ?>
                                                <?php
                                                $icon = '';
                                                if ($task['task_name'] == 'Electrical') $icon = '⚡';
                                                elseif ($task['task_name'] == 'Cable Trays') $icon = '🔌';
                                                elseif ($task['task_name'] == 'Support') $icon = '🛠️';
                                                elseif ($task['task_name'] == 'Railings') $icon = '🚧';
                                                elseif ($task['task_name'] == 'Architecture') $icon = '🏛️';
                                                elseif ($task['task_name'] == 'Stairs') $icon = '▁▃▅▇';
                                                elseif ($task['task_name'] == 'Equipment') $icon = '⚙️';
                                                elseif ($task['task_name'] == 'Piping') $icon = '─┼─';
                                                elseif ($task['task_name'] == 'Structure') $icon = '🏗️';
                                                ?>
                                                <div class="task-checkbox-item">
                                                    <input type="checkbox" name="task_ids[]" value="<?php echo $task['id']; ?>" id="task_<?php echo $task['id']; ?>" <?php echo $checked; ?>>
                                                    <label for="task_<?php echo $task['id']; ?>">
                                                        <?php echo $icon; ?> <?php echo htmlspecialchars($task['task_name']); ?>
                                                    </label>
                                                </div>
                                            <?php endforeach; ?>
                                            <div class="selected-tasks-badge" id="selected_tasks_count">
                                                <?php 
                                                $count = count(explode(', ', $edit_data['task_name']));
                                                echo $count . ' task' . ($count != 1 ? 's' : '') . ' selected';
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
                                    <!-- Hidden select to maintain backward compatibility -->
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
                            <!-- END TASK DROPDOWN -->
                            
                            <div>
                                <label class="form-label">Remarks</label>
                                <input type="text" name="remarks" class="form-control" placeholder="Enter remarks" value="<?php echo htmlspecialchars($edit_data['remarks'] ?? ''); ?>">
                            </div>
                            <div>
                                <label class="form-label">Status *</label>
                                <select name="status" class="form-control" required>
                                    <option value="inprogress" <?php echo ($edit_data['status'] == 'inprogress') ? 'selected' : ''; ?>>In Progress</option>
                                    <option value="completed" <?php echo ($edit_data['status'] == 'completed') ? 'selected' : ''; ?>>Completed</option>
                                    <option value="notstarted" <?php echo ($edit_data['status'] == 'notstarted') ? 'selected' : ''; ?>>Not Started</option>
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
                
                <!-- Add Timesheet Form - Hide when in edit mode -->
                <?php elseif (!$existing_timesheet && (!in_array($role, ['hr', 'admin', 'pm']) || isset($_GET['user_id']))): ?>
                <div class="timesheet-form">
                    <h4 style="margin-bottom: 20px; color: #4a5568;">
                        <i class="icon-plus" style="color: #667eea;"></i> 
                        Add Timesheet Entry for <?php echo $user_info['full_name'] ?? $selected_user_name; ?>
                    </h4>
                    <form method="POST" action="" id="timesheetForm">
                        <div class="timesheet-row">
                            <div>
                                <label class="form-label">Date *</label>
                                <input type="date" name="date" class="form-control" value="<?php echo $from_date; ?>" required>
                            </div>
                            <div>
                                <label class="form-label">Hours *</label>
                                <div class="time-input">
                                    <input type="number" name="hours" class="form-control" placeholder="HH" min="0" max="12" value="8" required>
                                    <span class="time-separator">:</span>
                                    <input type="number" name="minutes" class="form-control" placeholder="MM" min="0" max="59" value="0" required>
                                </div>
                                <small style="color: #718096; display: block; margin-top: 5px;">Hours: 0-12 | Minutes: 0-59</small>
                            </div>
                            
                            <!-- Software with manual option for admin users -->
                            <div class="dependent-dropdown">
                                <label class="form-label">
                                    Software * 
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
                                    <select name="software" class="form-control" required>
                                        <option value="">Select Software</option>
                                        <?php foreach ($software_options as $software): ?>
                                        <option value="<?php echo $software; ?>"><?php echo $software; ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                
                                <div id="software_manual_container" style="display: none;">
                                    <input type="text" name="software_manual" class="form-control" placeholder="Enter software name">
                                </div>
                                
                                <?php else: ?>
                                <select name="software" class="form-control" required>
                                    <option value="">Select Software</option>
                                    <?php foreach ($software_options as $software): ?>
                                    <option value="<?php echo $software; ?>"><?php echo $software; ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <?php endif; ?>
                            </div>
                            
                            <!-- Project with manual option for admin users -->
                            <div class="dependent-dropdown">
                                <label class="form-label">
                                    Project * 
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
                                    <select name="project_id" id="project_id" class="form-control" required>
                                        <option value="">Select Project</option>
                                        <?php foreach ($projects as $project): ?>
                                        <option value="<?php echo $project['id']; ?>">
                                            <?php echo $project['project_code'] ? '[' . $project['project_code'] . '] ' : ''; ?>
                                            <?php echo $project['project_name']; ?>
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <?php if (empty($projects)): ?>
                                    <small style="color: #e53e3e; display: block; margin-top: 5px;">
                                        <i class="icon-warning"></i> 
                                        No active projects found. Please contact HR or Project Manager.
                                    </small>
                                    <?php endif; ?>
                                </div>
                                
                                <div id="project_manual_container" style="display: none;">
                                    <input type="text" name="project_name_manual" class="form-control" placeholder="Enter project name">
                                </div>
                                
                                <?php else: ?>
                                <select name="project_id" id="project_id" class="form-control" required>
                                    <option value="">Select Project</option>
                                    <?php foreach ($projects as $project): ?>
                                    <option value="<?php echo $project['id']; ?>">
                                        <?php echo $project['project_code'] ? '[' . $project['project_code'] . '] ' : ''; ?>
                                        <?php echo $project['project_name']; ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                                <?php if (empty($projects)): ?>
                                <small style="color: #e53e3e; display: block; margin-top: 5px;">
                                    <i class="icon-warning"></i> 
                                    No active projects found. Please contact HR or Project Manager.
                                </small>
                                <?php endif; ?>
                                <?php endif; ?>
                            </div>
                            
                            <!-- TASK DROPDOWN - Electrical, Cable Trays, Support, Railings, Architecture, Stairs, Equipment, Piping, Structure -->
                            <div class="dependent-dropdown">
                                <label class="form-label">
                                    Task * 
                                    <span class="tasks-badge">
                                        <i class="icon-task"></i> 9 Tasks
                                    </span>
                                </label>
                                
                                <?php if ($tasks_table_exists): ?>
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
                                    <!-- MODIFIED: Changed from select to checkbox group for multi-select -->
                                    <div class="task-checkbox-group" id="multi_task_container">
                                        <?php if (!empty($all_tasks)): ?>
                                            <?php foreach ($all_tasks as $task): ?>
                                                <?php
                                                $icon = '';
                                                if ($task['task_name'] == 'Electrical') $icon = '⚡';
                                                elseif ($task['task_name'] == 'Cable Trays') $icon = '🔌';
                                                elseif ($task['task_name'] == 'Support') $icon = '🛠️';
                                                elseif ($task['task_name'] == 'Railings') $icon = '🚧';
                                                elseif ($task['task_name'] == 'Architecture') $icon = '🏛️';
                                                elseif ($task['task_name'] == 'Stairs') $icon = '▁▃▅▇';
                                                elseif ($task['task_name'] == 'Equipment') $icon = '⚙️';
                                                elseif ($task['task_name'] == 'Piping') $icon = '─┼─';
                                                elseif ($task['task_name'] == 'Structure') $icon = '🏗️';
                                                ?>
                                                <div class="task-checkbox-item">
                                                    <input type="checkbox" name="task_ids[]" value="<?php echo $task['id']; ?>" id="task_<?php echo $task['id']; ?>">
                                                    <label for="task_<?php echo $task['id']; ?>">
                                                        <?php echo $icon; ?> <?php echo htmlspecialchars($task['task_name']); ?>
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
                                    <!-- Hidden select to maintain backward compatibility -->
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
                            <!-- END TASK DROPDOWN -->
                            
                            <div>
                                <label class="form-label">Remarks</label>
                                <input type="text" name="remarks" class="form-control" placeholder="Enter remarks">
                            </div>
                            <div>
                                <label class="form-label">Status *</label>
                                <select name="status" class="form-control" required>
                                    <option value="inprogress">In Progress</option>
                                    <option value="completed" selected>Completed</option>
                                    <option value="notstarted">Not Started</option>
                                </select>
                            </div>
                        </div>
                        <button type="submit" name="submit_timesheet" class="btn-submit">
                            <i class="icon-plus"></i> Add Timesheet Entry
                        </button>
                    </form>
                </div>
                <?php elseif ($existing_timesheet && (!in_array($role, ['hr', 'admin', 'pm']) || isset($_GET['user_id'])) && !$edit_mode): ?>
                <div class="existing-entry">
                    <i class="icon-check"></i>
                    <div>
                        <strong>Timesheet already submitted for <?php echo $from_date; ?></strong><br>
                        <?php echo $user_info['full_name'] ?? $selected_user_name; ?> has already submitted a timesheet for this date. Only one entry per day is allowed.
                        <?php if ($role === 'hr' || $role === 'admin' || $role === 'pm' || $existing_timesheet['user_id'] == $user_id): ?>
                        You can delete the existing entry if you need to resubmit.
                        <?php endif; ?>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Timesheet Entries -->
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
                                // Only the person who entered can delete - NO ONE ELSE
                                $can_delete = ($entry['user_id'] == $user_id) && ($entry['id'] > 0) && ($entry['status'] != 'approved');
                                $can_edit = ($entry['user_id'] == $user_id) && ($entry['id'] > 0) && ($entry['status'] != 'approved') && ($entry['entry_date'] == $today_date);
                                $has_lop = ($entry['has_lop'] ?? 0) > 0;
                                $is_approved = ($entry['status'] == 'approved');
                                $is_late = ($entry['is_late'] ?? 0) > 0;
                                $is_sunday = (date('N', strtotime($entry['entry_date'])) == 7);
                                
                                // FIXED: Display task name - if task_name from timesheets exists, use it, otherwise use task_name_from_tasks
                                $display_task_name = $entry['task_name'] ?? '';
                                if (empty($display_task_name) && !empty($entry['task_name_from_tasks'])) {
                                    $display_task_name = $entry['task_name_from_tasks'];
                                }
                                if (empty($display_task_name)) {
                                    $display_task_name = '-';
                                }
                                ?>
                                <tr class="<?php echo $has_lop ? 'lop-entry' : ($is_sunday ? 'sunday-row' : ''); ?>">
                                    <td><?php echo $entry['entry_date']; ?>
                                        <?php if ($is_late && !$is_approved): ?>
                                        <span class="late-badge">Late</span>
                                        <?php endif; ?>
                                        <?php if ($is_sunday): ?>
                                        <span class="sunday-badge">Sunday</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php echo $whole_hours; ?>h <?php echo $minutes; ?>m
                                    </td>
                                    <td><?php echo $entry['software'] ?? '-'; ?></td>
                                    <td><?php echo $entry['project_name'] ?? '-'; ?></td>
                                    <td>
                                        <strong><?php echo htmlspecialchars($display_task_name); ?></strong>
                                    </td>
                                    <td title="<?php echo htmlspecialchars($entry['remarks'] ?? ''); ?>">
                                        <?php echo $entry['remarks'] ? (strlen($entry['remarks']) > 30 ? substr($entry['remarks'], 0, 30) . '...' : $entry['remarks']) : '-'; ?>
                                    </td>
                                    <td>
                                        <?php if ($is_approved): ?>
                                            <span class="status-badge status-success">Approved</span>
                                        <?php else: ?>
                                            <?php
                                            $status_labels = [
                                                'inprogress' => 'In Progress',
                                                'completed' => 'Completed',
                                                'notstarted' => 'Not Started'
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
                                    <td>
                                        <strong><?php echo $entry['employee_name'] ?? ''; ?></strong>
                                    </td>
                                    <?php endif; ?>
                                    <td>
                                        <?php 
                                        // Display submitted date in IST
                                        $submitted_display = $entry['submitted_date'] ?? date('Y-m-d H:i:s');
                                        echo date('Y-m-d H:i', strtotime($submitted_display)) . ' IST';
                                        ?>
                                    </td>
                                    <td>
                                        <div class="action-buttons" style="display: flex; gap: 5px;">
                                            <?php if ($can_edit && !$is_sunday): ?>
                                            <a href="?edit=<?php echo $entry['id']; ?>&from_date=<?php echo $from_date; ?>&to_date=<?php echo $to_date; ?><?php echo isset($_GET['user_id']) ? '&user_id=' . $_GET['user_id'] : ''; ?>" 
                                               class="btn-small btn-edit">
                                                <i class="icon-edit"></i>
                                            </a>
                                            <?php elseif ($entry['user_id'] == $user_id && $entry['status'] != 'approved' && $entry['entry_date'] != $today_date && !$is_sunday): ?>
                                            <span class="btn-small btn-edit-disabled" title="Only today's entries can be edited">
                                                <i class="icon-edit"></i> Edit
                                            </span>
                                            <?php endif; ?>
                                            
                                            <?php if ($can_delete && !$is_approved && !$is_sunday): ?>
                                            <a href="?delete=<?php echo $entry['id']; ?>&from_date=<?php echo $from_date; ?>&to_date=<?php echo $to_date; ?><?php echo isset($_GET['user_id']) ? '&user_id=' . $_GET['user_id'] : ''; ?>" 
                                               class="btn-small btn-delete"
                                               onclick="return confirm('Are you sure you want to delete your timesheet entry? This will add an auto-generated LOP entry for this date (except Sundays).')">
                                                <i class="icon-delete"></i>
                                            </a>
                                            <?php endif; ?>
                                            
                                            <?php if (in_array($role, ['pm', 'hr', 'admin']) && !$is_approved && !$is_sunday): ?>
                                            <a href="?approve=<?php echo $entry['id']; ?>&from_date=<?php echo $from_date; ?>&to_date=<?php echo $to_date; ?><?php echo isset($_GET['user_id']) ? '&user_id=' . $_GET['user_id'] : ''; ?>" 
                                               class="btn-small btn-approve"
                                               onclick="return confirm('Approve this timesheet? This will remove any auto-generated LOP for this date.')">
                                                <i class="icon-check"></i>
                                            </a>
                                            <?php endif; ?>
                                            
                                            <?php if ($is_approved): ?>
                                            <span class="btn-small btn-approve-disabled">
                                                <i class="icon-check"></i> Approved
                                            </span>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                    <td>
                                        <?php if ($is_sunday): ?>
                                        <span class="status-badge" style="background: #718096;">Sunday - No LOP</span>
                                        <?php elseif ($is_approved): ?>
                                        <span class="status-badge status-success">Approved - No LOP</span>
                                        <?php elseif ($has_lop && !$is_approved): ?>
                                        <span class="status-badge status-error">LOP (Pending Approval)</span>
                                        <?php else: ?>
                                        <span class="status-badge status-success">No LOP</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="<?php 
                                        $colspan = 9; // Base columns including LOP
                                        if (in_array($role, ['hr', 'admin', 'pm'])) $colspan += 1; // Employee column
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
            </div>
        </div>
    </div>

    <script>
    // MODIFIED: Update selected tasks count
    function updateSelectedTasksCount() {
        const checkboxes = document.querySelectorAll('input[name="task_ids[]"]:checked');
        const countSpan = document.getElementById('selected_tasks_count');
        if (countSpan) {
            countSpan.textContent = checkboxes.length + ' task' + (checkboxes.length !== 1 ? 's' : '') + ' selected';
        }
    }
    
    // Toggle between dropdown and manual task input - FIXED
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
                // Enable all checkboxes
                const checkboxes = multiTaskContainer.querySelectorAll('input[type="checkbox"]');
                checkboxes.forEach(cb => {
                    cb.disabled = false;
                });
            }
            if (taskSelect) {
                taskSelect.disabled = true; // Hidden select, no need to enable
            }
            if (taskManual) {
                taskManual.removeAttribute('required');
                taskManual.value = '';
            }
        } else if (taskTypeManual && taskTypeManual.checked) {
            dropdownContainer.style.display = 'none';
            manualContainer.style.display = 'block';
            if (multiTaskContainer) {
                // Disable all checkboxes
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
    
    // Toggle between dropdown and manual software input - FIXED
    function toggleSoftwareInput() {
        const softwareTypeDropdown = document.querySelector('input[name="software_type"][value="dropdown"]');
        const softwareTypeManual = document.querySelector('input[name="software_type"][value="manual"]');
        const dropdownContainer = document.getElementById('software_dropdown_container');
        const manualContainer = document.getElementById('software_manual_container');
        const softwareSelect = document.querySelector('select[name="software"]');
        const softwareManual = document.querySelector('input[name="software_manual"]');
        
        if (softwareTypeDropdown && softwareTypeDropdown.checked) {
            dropdownContainer.style.display = 'block';
            manualContainer.style.display = 'none';
            softwareSelect.disabled = false;
            softwareSelect.required = true;
            if (softwareManual) {
                softwareManual.removeAttribute('required');
                softwareManual.value = '';
            }
        } else if (softwareTypeManual && softwareTypeManual.checked) {
            dropdownContainer.style.display = 'none';
            manualContainer.style.display = 'block';
            if (softwareSelect) {
                softwareSelect.disabled = true;
                softwareSelect.removeAttribute('required');
                softwareSelect.value = '';
            }
            if (softwareManual) {
                softwareManual.setAttribute('required', 'required');
            }
        }
    }
    
    // Toggle between dropdown and manual project input - FIXED
    function toggleProjectInput() {
        const projectTypeDropdown = document.querySelector('input[name="project_type"][value="dropdown"]');
        const projectTypeManual = document.querySelector('input[name="project_type"][value="manual"]');
        const dropdownContainer = document.getElementById('project_dropdown_container');
        const manualContainer = document.getElementById('project_manual_container');
        const projectSelect = document.querySelector('select[name="project_id"]');
        const projectManual = document.querySelector('input[name="project_name_manual"]');
        
        if (projectTypeDropdown && projectTypeDropdown.checked) {
            dropdownContainer.style.display = 'block';
            manualContainer.style.display = 'none';
            projectSelect.disabled = false;
            projectSelect.required = true;
            if (projectManual) {
                projectManual.removeAttribute('required');
                projectManual.value = '';
            }
        } else if (projectTypeManual && projectTypeManual.checked) {
            dropdownContainer.style.display = 'none';
            manualContainer.style.display = 'block';
            if (projectSelect) {
                projectSelect.disabled = true;
                projectSelect.removeAttribute('required');
                projectSelect.value = '';
            }
            if (projectManual) {
                projectManual.setAttribute('required', 'required');
            }
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
    
    // Initialize on page load
    document.addEventListener('DOMContentLoaded', function() {
        <?php if ($tasks_table_exists): ?>
        // Initialize task toggle
        toggleTaskInput();
        
        // Add event listeners to checkboxes for count update
        const checkboxes = document.querySelectorAll('input[name="task_ids[]"]');
        checkboxes.forEach(cb => {
            cb.addEventListener('change', updateSelectedTasksCount);
        });
        <?php endif; ?>
        
        <?php if ($is_admin_user): ?>
        // Initialize software toggle
        toggleSoftwareInput();
        // Initialize project toggle
        toggleProjectInput();
        <?php endif; ?>
        
        // Initialize selected tasks count for edit mode
        updateSelectedTasksCount();
    });
    </script>
    
    <script src="../assets/js/app.js"></script>
</body>
</html>