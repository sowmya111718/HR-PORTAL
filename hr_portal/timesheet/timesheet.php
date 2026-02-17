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

// Submit timesheet
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
    
    // Check if entry already exists for this date and user
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
        
        if ($tasks_table_exists && $task_id > 0) {
            // Insert with task_id
            $stmt = $conn->prepare("
                INSERT INTO timesheets (user_id, entry_date, hours, software, project_id, task_id, project_name, task_name, remarks, status)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->bind_param("isdssissss", $viewing_user_id, $date, $total_hours, $software, $project_id, $task_id, $project_name, $task_name, $remarks, $status);
        } else {
            // Insert without task_id
            $stmt = $conn->prepare("
                INSERT INTO timesheets (user_id, entry_date, hours, software, project_id, project_name, task_name, remarks, status)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->bind_param("isdssisss", $viewing_user_id, $date, $total_hours, $software, $project_id, $project_name, $task_name, $remarks, $status);
        }
        
        if ($stmt->execute()) {
            $message = '<div class="alert alert-success">Timesheet entry added successfully!</div>';
            // Refresh existing timesheet check
            $stmt_check = $conn->prepare("SELECT * FROM timesheets WHERE user_id = ? AND entry_date = ?");
            $stmt_check->bind_param("is", $viewing_user_id, $date);
            $stmt_check->execute();
            $check_result = $stmt_check->get_result();
            $existing_timesheet = $check_result->fetch_assoc();
            $stmt_check->close();
        } else {
            $message = '<div class="alert alert-error">Error adding timesheet entry: ' . $stmt->error . '</div>';
        }
        $stmt->close();
    }
}

// Delete timesheet entry - FIXED: ONLY THE PERSON WHO ENTERED CAN DELETE
if (isset($_GET['delete'])) {
    $timesheet_id = intval($_GET['delete']);
    
    // First, get the user_id of this timesheet entry
    $get_user_stmt = $conn->prepare("SELECT user_id FROM timesheets WHERE id = ?");
    $get_user_stmt->bind_param("i", $timesheet_id);
    $get_user_stmt->execute();
    $get_user_result = $get_user_stmt->get_result();
    $timesheet_data = $get_user_result->fetch_assoc();
    $timesheet_user_id = $timesheet_data['user_id'] ?? 0;
    $get_user_stmt->close();
    
    // ONLY the person who entered can delete - NO ONE ELSE
    if ($timesheet_user_id == $user_id) {
        // User can delete only their own entry
        $stmt = $conn->prepare("DELETE FROM timesheets WHERE id = ? AND user_id = ?");
        $stmt->bind_param("ii", $timesheet_id, $user_id);
        
        if ($stmt->execute()) {
            $message = '<div class="alert alert-success">Timesheet entry deleted successfully</div>';
            // Reset existing timesheet check
            $existing_timesheet = null;
        } else {
            $message = '<div class="alert alert-error">Error deleting timesheet entry</div>';
        }
        $stmt->close();
    } else {
        // User is trying to delete someone else's entry
        $message = '<div class="alert alert-error">You can only delete your own timesheet entries</div>';
    }
}

// Get timesheet entries for selected date range
if (in_array($role, ['hr', 'admin', 'pm'])) {
    // HR/Admin/PM can view date range for ALL users (but cannot delete)
    if (isset($_GET['user_id']) && $_GET['user_id'] != '') {
        // View specific user
        $stmt = $conn->prepare("
            SELECT t.*, u.full_name as employee_name, u.username, 
                   p.project_name as project_name, p.project_code,
                   tk.task_name as task_name, tk.description as task_description
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
                   p.project_name as project_name, p.project_code,
                   tk.task_name as task_name, tk.description as task_description
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
        SELECT t.*, p.project_name as project_name, p.project_code,
               tk.task_name as task_name, tk.description as task_description
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

// MODIFIED: Get total submissions for today
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
            
            <!-- MODIFIED: Quick Stats - Removed Total Hours Today, Added Total Submissions Today -->
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
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($timesheets && $timesheets->num_rows > 0): ?>
                                <?php while ($entry = $timesheets->fetch_assoc()): ?>
                                <?php
                                $whole_hours = floor($entry['hours']);
                                $minutes = round(($entry['hours'] - $whole_hours) * 60);
                                // Only the person who entered can delete - NO ONE ELSE
                                $can_delete = ($entry['user_id'] == $user_id);
                                ?>
                                <tr>
                                    <td><?php echo $entry['entry_date']; ?></td>
                                    <td><?php echo $whole_hours; ?>h <?php echo $minutes; ?>m</td>
                                    <td><?php echo $entry['software'] ?? '-'; ?></td>
                                    <td><?php echo $entry['project_name'] ?? '-'; ?></td>
                                    <td>
                                        <strong><?php echo $entry['task_name'] ?? '-'; ?></strong>
                                    </td>
                                    <td title="<?php echo htmlspecialchars($entry['remarks'] ?? ''); ?>">
                                        <?php echo $entry['remarks'] ? (strlen($entry['remarks']) > 30 ? substr($entry['remarks'], 0, 30) . '...' : $entry['remarks']) : '-'; ?>
                                    </td>
                                    <td>
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
                                    </td>
                                    <?php if (in_array($role, ['hr', 'admin', 'pm'])): ?>
                                    <td>
                                        <strong><?php echo $entry['employee_name'] ?? ''; ?></strong>
                                    </td>
                                    <?php endif; ?>
                                    <td><?php echo date('Y-m-d H:i', strtotime($entry['submitted_date'] ?? date('Y-m-d H:i:s'))); ?></td>
                                    <td>
                                        <div class="action-buttons">
                                            <?php if ($can_delete): ?>
                                            <a href="?delete=<?php echo $entry['id']; ?>&from_date=<?php echo $from_date; ?>&to_date=<?php echo $to_date; ?><?php echo isset($_GET['user_id']) ? '&user_id=' . $_GET['user_id'] : ''; ?>" 
                                               class="btn-small btn-delete"
                                               onclick="return confirm('Are you sure you want to delete your timesheet entry?')">
                                                <i class="fas fa-trash"></i> Delete
                                            </a>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="<?php 
                                        $colspan = 8; // Base columns
                                        if (in_array($role, ['hr', 'admin', 'pm'])) $colspan += 1; // Employee column
                                        echo $colspan + 2; // +2 for Submitted and Actions
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