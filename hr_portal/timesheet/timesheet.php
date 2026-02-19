<?php
require_once '../config/db.php';

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

// Function to add LOP for missing timesheet
function addLOPForMissingTimesheet($conn, $user_id, $date) {
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

// Check for missing timesheets and add LOP (for admin/HR view) - EXEMPT DEMO ACCOUNTS
if (in_array($role, ['hr', 'admin', 'pm']) && isset($_GET['check_missing'])) {
    $check_date = isset($_GET['check_date']) ? sanitize($_GET['check_date']) : date('Y-m-d', strtotime('-1 day'));
    
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
    
    $message = '<div class="alert alert-success">Checked for missing timesheets on ' . $check_date . '. Added ' . $lop_added_count . ' LOP entries.</div>';
}

// Submit timesheet - FIXED: Prevent double entries and redirect
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_timesheet'])) {
    $date = sanitize($_POST['date']);
    $hours = floatval($_POST['hours']);
    $minutes = intval($_POST['minutes']);
    $software = sanitize($_POST['software']);
    $project_id = intval($_POST['project_id']);
    
    // Handle task - either from dropdown or manual entry
    $task_id = isset($_POST['task_id']) ? intval($_POST['task_id']) : 0;
    $task_name = '';
    
    if ($task_id > 0 && $tasks_table_exists) {
        // Get task name from tasks table
        $stmt_task = $conn->prepare("SELECT task_name FROM tasks WHERE id = ?");
        $stmt_task->bind_param("i", $task_id);
        $stmt_task->execute();
        $task_result = $stmt_task->get_result();
        $task_data = $task_result->fetch_assoc();
        $task_name = $task_data['task_name'];
        $stmt_task->close();
    } else {
        // Manual task entry
        $task_name = sanitize($_POST['task_name_manual']);
        $task_id = 0;
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
        $message = '<div class="alert alert-error">You have already submitted a timesheet for this date. You can only submit one entry per day.</div>';
    } else {
        // Get project name from project_id
        $stmt_project = $conn->prepare("SELECT project_name FROM projects WHERE id = ?");
        $stmt_project->bind_param("i", $project_id);
        $stmt_project->execute();
        $project_result = $stmt_project->get_result();
        $project_data = $project_result->fetch_assoc();
        $project_name = $project_data['project_name'];
        $stmt_project->close();
        
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
            // Insert without task_id
            $stmt = $conn->prepare("
                INSERT INTO timesheets (user_id, entry_date, hours, software, project_id, project_name, task_name, remarks, status, submitted_date)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->bind_param("isdssissss", $viewing_user_id, $date, $total_hours, $software, $project_id, $project_name, $task_name, $remarks, $status, $submitted_date);
        }
        
        if ($stmt->execute()) {
            // Check if this is a late submission (submitted after the date)
            if ($is_late) {
                $message = '<div class="alert alert-warning">Timesheet entry added successfully but marked as LATE SUBMISSION. LOP will remain until approved by PM.</div>';
            } else {
                $message = '<div class="alert alert-success">Timesheet entry added successfully!</div>';
            }
            
            // Redirect to refresh the page and show the newly added entry
            $redirect_url = "timesheet.php?from_date=" . urlencode($date) . "&to_date=" . urlencode($date);
            if (isset($_GET['user_id']) && $_GET['user_id'] != '') {
                $redirect_url .= "&user_id=" . urlencode($_GET['user_id']);
            }
            header("Location: $redirect_url");
            exit();
        } else {
            $message = '<div class="alert alert-error">Error adding timesheet entry: ' . $stmt->error . '</div>';
        }
        $stmt->close();
    }
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
            $message = '<div class="alert alert-success">Timesheet approved successfully. LOP removed.</div>';
            
            // Redirect to refresh the page and show the updated status
            $redirect_url = "timesheet.php?from_date=" . urlencode($from_date) . "&to_date=" . urlencode($to_date);
            if (isset($_GET['user_id']) && $_GET['user_id'] != '') {
                $redirect_url .= "&user_id=" . urlencode($_GET['user_id']);
            }
            header("Location: $redirect_url");
            exit();
        } else {
            $message = '<div class="alert alert-error">Error approving timesheet</div>';
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
            // ADD LOP ENTRY WHEN TIMESHEET IS DELETED (MISSING)
            addLOPForMissingTimesheet($conn, $user_id, $entry_date);
            
            $message = '<div class="alert alert-success">Timesheet entry deleted successfully. LOP entry added for this date.</div>';
            
            // Redirect to refresh the page
            $redirect_url = "timesheet.php?from_date=" . urlencode($from_date) . "&to_date=" . urlencode($to_date);
            if (isset($_GET['user_id']) && $_GET['user_id'] != '') {
                $redirect_url .= "&user_id=" . urlencode($_GET['user_id']);
            }
            header("Location: $redirect_url");
            exit();
        } else {
            $message = '<div class="alert alert-error">Error deleting timesheet entry</div>';
        }
        $stmt->close();
    } else {
        // User is trying to delete someone else's entry
        $message = '<div class="alert alert-error">You can only delete your own timesheet entries</div>';
    }
}

// Auto-check for previous day on page load for HR/Admin/PM - EXEMPT DEMO ACCOUNTS
if (in_array($role, ['hr', 'admin', 'pm'])) {
    $yesterday = date('Y-m-d', strtotime('-1 day'));
    
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
        $message = '<div class="alert alert-info">Auto-check completed: ' . $lop_added_count . ' LOP entries added for ' . $yesterday . '.</div>';
    }
}

// Get timesheet entries for selected date range - FIXED: Simplified query to ensure timesheets are displayed
if (in_array($role, ['hr', 'admin', 'pm'])) {
    // HR/Admin/PM can view date range for ALL users
    if (isset($_GET['user_id']) && $_GET['user_id'] != '') {
        // View specific user
        $stmt = $conn->prepare("
            SELECT t.*, u.full_name as employee_name, u.username, 
                   p.project_name, p.project_code,
                   tk.task_name, tk.description as task_description,
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
        // View ALL users
        $stmt = $conn->prepare("
            SELECT t.*, u.full_name as employee_name, u.username, 
                   p.project_name, p.project_code,
                   tk.task_name, tk.description as task_description,
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
    // Regular employees can only view their own single day
    $stmt = $conn->prepare("
        SELECT t.*, p.project_name, p.project_code,
               tk.task_name, tk.description as task_description,
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

// Calculate total hours for the selected period
if (in_array($role, ['hr', 'admin', 'pm'])) {
    if (isset($_GET['user_id']) && $_GET['user_id'] != '') {
        // Total for specific user
        $stmt_total = $conn->prepare("
            SELECT COALESCE(SUM(hours), 0) as total_hours 
            FROM timesheets 
            WHERE user_id = ? AND entry_date BETWEEN ? AND ?
        ");
        $stmt_total->bind_param("iss", $viewing_user_id, $from_date, $to_date);
    } else {
        // Total for ALL users
        $stmt_total = $conn->prepare("
            SELECT COALESCE(SUM(hours), 0) as total_hours 
            FROM timesheets 
            WHERE entry_date BETWEEN ? AND ?
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

// Get LOP count for the period
$lop_count = 0;
if (in_array($role, ['hr', 'admin', 'pm'])) {
    if (isset($_GET['user_id']) && $_GET['user_id'] != '') {
        $stmt_lop = $conn->prepare("
            SELECT COUNT(*) as lop_count 
            FROM leaves 
            WHERE user_id = ? AND from_date BETWEEN ? AND ? 
            AND leave_type = 'LOP' AND reason LIKE 'Auto-generated LOP%'
        ");
        $stmt_lop->bind_param("iss", $viewing_user_id, $from_date, $to_date);
    } else {
        $stmt_lop = $conn->prepare("
            SELECT COUNT(*) as lop_count 
            FROM leaves 
            WHERE from_date BETWEEN ? AND ? 
            AND leave_type = 'LOP' AND reason LIKE 'Auto-generated LOP%'
        ");
        $stmt_lop->bind_param("ss", $from_date, $to_date);
    }
    $stmt_lop->execute();
    $lop_result = $stmt_lop->get_result();
    $lop_row = $lop_result->fetch_assoc();
    $lop_count = $lop_row['lop_count'] ?? 0;
    $stmt_lop->close();
}

// Get total submissions for today
$submissions_today = 0;
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

// Software options
$software_options = ['Cyclone', 'Revit', 'SP3D', 'AutoCAD', 'Other', 'Training'];

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
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $page_title; ?> - MAKSIM HR</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="../assets/css/style.css">
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
            width: 60px;
        }
        
        .time-separator {
            font-weight: bold;
            color: #718096;
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
        
        .task-toggle {
            display: flex;
            gap: 20px;
            margin-bottom: 10px;
        }
        
        .task-toggle label {
            display: flex;
            align-items: center;
            gap: 5px;
            cursor: pointer;
            color: #4a5568;
        }
        
        .manual-task-input {
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
    </style>
</head>
<body>
    <?php include '../includes/header.php'; ?>
    
    <div class="app-main">
        <?php include '../includes/sidebar.php'; ?>
        
        <div class="main-content">
            <h2 class="page-title">
                <i class="fas fa-calendar-alt"></i> 
                <?php echo $page_title; ?>
                <?php if (in_array($role, ['hr', 'admin', 'pm']) && !isset($_GET['user_id'])): ?>
                <span class="all-employees-badge">
                    <i class="fas fa-users"></i> Viewing: All Employees
                </span>
                <?php endif; ?>
            </h2>
            
            <?php echo $message; ?>
            
            <?php if (in_array($role, ['hr', 'admin', 'pm'])): ?>
            <!-- LOP Check Section -->
            <div class="project-management" style="background: #fed7d7; border-color: #fc8181;">
                <i class="fas fa-exclamation-triangle" style="color: #c53030;"></i>
                <div style="flex: 1;">
                    <strong style="color: #c53030;">Auto LOP Tracking:</strong> 
                    <?php echo $lop_count; ?> auto-generated LOP entries found in this date range.
                </div>
                <div>
                    <a href="?check_missing=1&check_date=<?php echo date('Y-m-d', strtotime('-1 day')); ?><?php echo isset($_GET['user_id']) ? '&user_id=' . $_GET['user_id'] : ''; ?>&from_date=<?php echo $from_date; ?>&to_date=<?php echo $to_date; ?>" class="btn-check" style="background: #805ad5; color: white; padding: 8px 16px; border-radius: 6px; text-decoration: none;">
                        <i class="fas fa-check-double"></i> Check Missing
                    </a>
                </div>
            </div>
            
            <!-- Project Management Section -->
            <div class="project-management">
                <div style="display: flex; align-items: center; gap: 15px;">
                    <i class="fas fa-tasks"></i>
                    <div>
                        <strong style="font-size: 16px; color: #2d3748;">Project Management</strong>
                        <div style="display: flex; gap: 10px; margin-top: 5px;">
                            <span class="role-badge">
                                <i class="fas fa-user-tie"></i> 
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
                        <i class="fas fa-plus-circle"></i> Add New Project
                    </a>
                    <a href="../admin/manage_projects.php" class="btn-project btn-project-remove" target="_blank">
                        <i class="fas fa-trash-alt"></i> Remove Projects
                    </a>
                    <a href="../admin/manage_projects.php" class="btn-project btn-project-view" target="_blank">
                        <i class="fas fa-edit"></i> Edit Projects
                    </a>
                </div>
            </div>
            
            <!-- Task Management Section - ALL TASKS -->
            <div class="task-management">
                <div style="display: flex; align-items: center; gap: 15px;">
                    <i class="fas fa-check-square"></i>
                    <div>
                        <strong style="font-size: 16px; color: #2d3748;">Engineering & Design Tasks</strong>
                        <span class="tasks-badge">
                            <i class="fas fa-bolt"></i> Electrical
                        </span>
                        <span class="tasks-badge">
                            <i class="fas fa-wrench"></i> Cable Trays
                        </span>
                        <span class="tasks-badge">
                            <i class="fas fa-building"></i> Architecture
                        </span>
                        <span class="tasks-badge">
                            <i class="fas fa-wrench"></i> Piping
                        </span>
                        <span class="tasks-badge">
                            <i class="fas fa-cubes"></i> Structure
                        </span>
                        <div style="display: flex; gap: 10px; margin-top: 5px;">
                            <span class="role-badge">
                                <i class="fas fa-clipboard-list"></i> 
                                Electrical | Cable Trays | Support | Railings | Architecture | Stairs | Equipment | Piping | Structure
                            </span>
                        </div>
                    </div>
                </div>
                
                <div class="task-management-buttons">
                    <a href="../admin/manage_tasks.php" class="btn-task btn-task-view" target="_blank">
                        <i class="fas fa-cog"></i> Manage Tasks
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
                        <i class="fas fa-clock"></i> 
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
                        </div>
                    </div>
                </div>

                <!-- Add Timesheet Form - Only show for regular employees or when viewing specific user -->
                <?php if (!$existing_timesheet && (!in_array($role, ['hr', 'admin', 'pm']) || isset($_GET['user_id']))): ?>
                <div class="timesheet-form">
                    <h4 style="margin-bottom: 20px; color: #4a5568;">
                        <i class="fas fa-plus-circle" style="color: #667eea;"></i> 
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
                                    <input type="number" name="hours" class="form-control" placeholder="HH" min="0" max="23" value="8" required>
                                    <span class="time-separator">:</span>
                                    <input type="number" name="minutes" class="form-control" placeholder="MM" min="0" max="59" value="0" required>
                                </div>
                            </div>
                            <div>
                                <label class="form-label">Software *</label>
                                <select name="software" class="form-control" required>
                                    <option value="">Select Software</option>
                                    <?php foreach ($software_options as $software): ?>
                                    <option value="<?php echo $software; ?>"><?php echo $software; ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div>
                                <label class="form-label">Project *</label>
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
                                    <i class="fas fa-exclamation-triangle"></i> 
                                    No active projects found. Please contact HR or Project Manager.
                                </small>
                                <?php endif; ?>
                            </div>
                            
                            <!-- TASK DROPDOWN - Electrical, Cable Trays, Support, Railings, Architecture, Stairs, Equipment, Piping, Structure -->
                            <div class="dependent-dropdown">
                                <label class="form-label">
                                    Task * 
                                    <span class="tasks-badge">
                                        <i class="fas fa-tasks"></i> 9 Tasks
                                    </span>
                                </label>
                                
                                <?php if ($tasks_table_exists): ?>
                                <div class="task-toggle">
                                    <label>
                                        <input type="radio" name="task_type" value="dropdown" checked onchange="toggleTaskInput()"> 
                                        <i class="fas fa-list"></i> Select from list
                                    </label>
                                    <label>
                                        <input type="radio" name="task_type" value="manual" onchange="toggleTaskInput()"> 
                                        <i class="fas fa-edit"></i> Enter manually
                                    </label>
                                </div>
                                
                                <div id="task_dropdown_container">
                                    <select name="task_id" id="task_id" class="form-control task-select" required>
                                        <option value="">-- Select Task --</option>
                                        <?php if (!empty($all_tasks)): ?>
                                            <?php foreach ($all_tasks as $task): ?>
                                                <?php
                                                $icon = '';
                                                if ($task['task_name'] == 'Electrical') $icon = '⚡';
                                                elseif ($task['task_name'] == 'Cable Trays') $icon = '🔌';
                                                elseif ($task['task_name'] == 'Support') $icon = '🛠️';
                                                elseif ($task['task_name'] == 'Railings') $icon = '🚧';
                                                elseif ($task['task_name'] == 'Architecture') $icon = '🏛️';
                                                elseif ($task['task_name'] == 'Stairs') $icon = '🪜';
                                                elseif ($task['task_name'] == 'Equipment') $icon = '⚙️';
                                                elseif ($task['task_name'] == 'Piping') $icon = '🔧';
                                                elseif ($task['task_name'] == 'Structure') $icon = '🏗️';
                                                ?>
                                                <option value="<?php echo $task['id']; ?>">
                                                    <?php echo $icon; ?> <?php echo htmlspecialchars($task['task_name']); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        <?php else: ?>
                                            <option value="">No tasks available</option>
                                        <?php endif; ?>
                                    </select>
                                    <small style="color: #718096; display: block; margin-top: 5px;">
                                        <i class="fas fa-hard-hat"></i> 
                                        <strong>Available tasks:</strong> Electrical, Cable Trays, Support, Railings, Architecture, Stairs, Equipment, Piping, Structure
                                    </small>
                                </div>
                                
                                <div id="task_manual_container" style="display: none;">
                                    <input type="text" name="task_name_manual" id="task_name_manual" class="form-control" placeholder="Enter custom task name">
                                    <small style="color: #718096; display: block; margin-top: 5px;">
                                        <i class="fas fa-edit"></i> 
                                        Type a custom task name
                                    </small>
                                </div>
                                
                                <?php else: ?>
                                <input type="text" name="task_name_manual" class="form-control" placeholder="Enter task name" required>
                                <small style="color: #e53e3e; display: block; margin-top: 5px;">
                                    <i class="fas fa-exclamation-triangle"></i> 
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
                            <i class="fas fa-plus-circle"></i> Add Timesheet Entry
                        </button>
                    </form>
                </div>
                <?php elseif ($existing_timesheet && (!in_array($role, ['hr', 'admin', 'pm']) || isset($_GET['user_id']))): ?>
                <div class="existing-entry">
                    <i class="fas fa-check-circle"></i>
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
                                <th>Submitted</th>
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
                                $can_delete = ($entry['user_id'] == $user_id) && ($entry['id'] > 0);
                                $has_lop = ($entry['has_lop'] ?? 0) > 0;
                                $is_approved = ($entry['status'] == 'approved');
                                $is_late = ($entry['is_late'] ?? 0) > 0;
                                ?>
                                <tr class="<?php echo $has_lop ? 'lop-entry' : ''; ?>">
                                    <td><?php echo $entry['entry_date']; ?>
                                        <?php if ($is_late && !$is_approved): ?>
                                        <span class="late-badge">Late</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php echo $whole_hours; ?>h <?php echo $minutes; ?>m
                                    </td>
                                    <td><?php echo $entry['software'] ?? '-'; ?></td>
                                    <td><?php echo $entry['project_name'] ?? '-'; ?></td>
                                    <td>
                                        <strong><?php echo $entry['task_name'] ?? '-'; ?></strong>
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
                                    <td><?php echo date('Y-m-d H:i', strtotime($entry['submitted_date'] ?? date('Y-m-d H:i:s'))); ?></td>
                                    <td>
                                        <div class="action-buttons" style="display: flex; gap: 5px;">
                                            <?php if ($can_delete && !$is_approved): ?>
                                            <a href="?delete=<?php echo $entry['id']; ?>&from_date=<?php echo $from_date; ?>&to_date=<?php echo $to_date; ?><?php echo isset($_GET['user_id']) ? '&user_id=' . $_GET['user_id'] : ''; ?>" 
                                               class="btn-small btn-delete"
                                               onclick="return confirm('Are you sure you want to delete your timesheet entry? This will add an auto-generated LOP entry for this date.')">
                                                <i class="fas fa-trash"></i>
                                            </a>
                                            <?php endif; ?>
                                            
                                            <?php if (in_array($role, ['pm', 'hr', 'admin']) && !$is_approved): ?>
                                            <a href="?approve=<?php echo $entry['id']; ?>&from_date=<?php echo $from_date; ?>&to_date=<?php echo $to_date; ?><?php echo isset($_GET['user_id']) ? '&user_id=' . $_GET['user_id'] : ''; ?>" 
                                               class="btn-small btn-approve"
                                               onclick="return confirm('Approve this timesheet? This will remove any auto-generated LOP for this date.')">
                                                <i class="fas fa-check-circle"></i>
                                            </a>
                                            <?php endif; ?>
                                            
                                            <?php if ($is_approved): ?>
                                            <span class="btn-small btn-approve-disabled">
                                                <i class="fas fa-check-circle"></i> Approved
                                            </span>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                    <td>
                                        <?php if ($is_approved): ?>
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
                                            <i class="fas fa-clock"></i>
                                            <div>No timesheet entries found for <?php echo $from_date; ?><?php echo ($from_date != $to_date) ? ' to ' . $to_date : ''; ?></div>
                                            <p style="margin-top: 10px; color: #718096;">
                                                <i class="fas fa-info-circle"></i> 
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
    // Toggle between dropdown and manual task input
    function toggleTaskInput() {
        <?php if ($tasks_table_exists): ?>
        const taskTypeDropdown = document.querySelector('input[name="task_type"][value="dropdown"]');
        const taskTypeManual = document.querySelector('input[name="task_type"][value="manual"]');
        const dropdownContainer = document.getElementById('task_dropdown_container');
        const manualContainer = document.getElementById('task_manual_container');
        const taskSelect = document.getElementById('task_id');
        const taskManual = document.getElementById('task_name_manual');
        
        if (taskTypeDropdown && taskTypeDropdown.checked) {
            dropdownContainer.style.display = 'block';
            manualContainer.style.display = 'none';
            taskSelect.disabled = false;
            taskSelect.required = true;
            taskManual.removeAttribute('required');
            taskManual.value = '';
        } else if (taskTypeManual && taskTypeManual.checked) {
            dropdownContainer.style.display = 'none';
            manualContainer.style.display = 'block';
            taskSelect.disabled = true;
            taskSelect.removeAttribute('required');
            taskSelect.value = '';
            taskManual.setAttribute('required', 'required');
        }
        <?php endif; ?>
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
        <?php endif; ?>
    });
    </script>
    
    <script src="../assets/js/app.js"></script>
</body>
</html>