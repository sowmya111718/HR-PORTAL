<?php
require_once '../config/db.php';
require_once '../includes/icon_functions.php';
require_once '../includes/notification_functions.php';

if (!isLoggedIn()) {
    header('Location: ../auth/login.php');
    exit();
}

// Only HR, Admin, and dm can manage tasks
if (!in_array($_SESSION['role'], ['hr', 'admin', 'dm'])) {
    header('HTTP/1.0 403 Forbidden');
    exit();
}

$user_id = $_SESSION['user_id'];
$role = $_SESSION['role'];
$message = '';
$error = '';

// Handle Add Task
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_task'])) {
    $task_name = sanitize($_POST['task_name']);
    $description = sanitize($_POST['description']);
    $status = sanitize($_POST['status']);
    $category = 'General'; // Default category
    
    if (empty($task_name)) {
        $error = '<div class="alert alert-error"><i class="icon-error"></i> Task name is required.</div>';
    } else {
        // Check if task already exists
        $check = $conn->prepare("SELECT id FROM tasks WHERE task_name = ?");
        $check->bind_param("s", $task_name);
        $check->execute();
        $check_result = $check->get_result();
        
        if ($check_result->num_rows > 0) {
            $error = '<div class="alert alert-error"><i class="icon-error"></i> A task with this name already exists.</div>';
        } else {
            $stmt = $conn->prepare("
                INSERT INTO tasks (task_name, description, category, status) 
                VALUES (?, ?, ?, ?)
            ");
            $stmt->bind_param("ssss", $task_name, $description, $category, $status);
            
            if ($stmt->execute()) {
                $message = '<div class="alert alert-success"><i class="icon-success"></i> Task added successfully!</div>';
            } else {
                $error = '<div class="alert alert-error"><i class="icon-error"></i> Error adding task: ' . $stmt->error . '</div>';
            }
            $stmt->close();
        }
        $check->close();
    }
}

// Handle Edit Task
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_task'])) {
    $task_id = intval($_POST['task_id']);
    $task_name = sanitize($_POST['task_name']);
    $description = sanitize($_POST['description']);
    $status = sanitize($_POST['status']);
    
    if (empty($task_name)) {
        $error = '<div class="alert alert-error"><i class="icon-error"></i> Task name is required.</div>';
    } else {
        // Check if task name already exists for another task
        $check = $conn->prepare("SELECT id FROM tasks WHERE task_name = ? AND id != ?");
        $check->bind_param("si", $task_name, $task_id);
        $check->execute();
        $check_result = $check->get_result();
        
        if ($check_result->num_rows > 0) {
            $error = '<div class="alert alert-error"><i class="icon-error"></i> A task with this name already exists.</div>';
        } else {
            $stmt = $conn->prepare("
                UPDATE tasks 
                SET task_name = ?, description = ?, status = ? 
                WHERE id = ?
            ");
            $stmt->bind_param("sssi", $task_name, $description, $status, $task_id);
            
            if ($stmt->execute()) {
                $message = '<div class="alert alert-success"><i class="icon-success"></i> Task updated successfully!</div>';
            } else {
                $error = '<div class="alert alert-error"><i class="icon-error"></i> Error updating task: ' . $stmt->error . '</div>';
            }
            $stmt->close();
        }
        $check->close();
    }
}

// Handle Delete Task
if (isset($_GET['delete'])) {
    $task_id = intval($_GET['delete']);
    
    // Check if task is used in timesheets
    $check_usage = $conn->prepare("SELECT COUNT(*) as count FROM timesheets WHERE task_id = ?");
    $check_usage->bind_param("i", $task_id);
    $check_usage->execute();
    $usage_result = $check_usage->get_result();
    $usage_data = $usage_result->fetch_assoc();
    $check_usage->close();
    
    if ($usage_data['count'] > 0) {
        $error = '<div class="alert alert-error"><i class="icon-error"></i> Cannot delete task. It is being used in ' . $usage_data['count'] . ' timesheet entries.</div>';
    } else {
        $stmt = $conn->prepare("DELETE FROM tasks WHERE id = ?");
        $stmt->bind_param("i", $task_id);
        
        if ($stmt->execute()) {
            $message = '<div class="alert alert-success"><i class="icon-success"></i> Task deleted successfully!</div>';
        } else {
            $error = '<div class="alert alert-error"><i class="icon-error"></i> Error deleting task: ' . $stmt->error . '</div>';
        }
        $stmt->close();
    }
}

// Get task for editing
$edit_task = null;
if (isset($_GET['edit'])) {
    $edit_id = intval($_GET['edit']);
    $stmt = $conn->prepare("SELECT * FROM tasks WHERE id = ?");
    $stmt->bind_param("i", $edit_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $edit_task = $result->fetch_assoc();
    $stmt->close();
}

// Get filter values
$status_filter = isset($_GET['status']) ? $_GET['status'] : 'all';
$search = isset($_GET['search']) ? sanitize($_GET['search']) : '';

// Build query
$where = [];
if ($status_filter != 'all') {
    $where[] = "status = '" . $conn->real_escape_string($status_filter) . "'";
}
if (!empty($search)) {
    $where[] = "(task_name LIKE '%$search%' OR description LIKE '%$search%')";
}

$where_clause = !empty($where) ? "WHERE " . implode(" AND ", $where) : "";

// Get tasks - Using only columns that exist
$tasks_query = "
    SELECT t.*, 
           (SELECT COUNT(*) FROM timesheets WHERE task_id = t.id) as timesheet_count
    FROM tasks t
    $where_clause
    ORDER BY 
        CASE t.status
            WHEN 'active' THEN 1
            WHEN 'completed' THEN 2
            WHEN 'inactive' THEN 3
        END,
        t.task_name ASC
";

$tasks_result = $conn->query($tasks_query);

// Get statistics - Using only columns that exist
$stats_query = "
    SELECT 
        COUNT(*) as total_tasks,
        SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) as active_tasks,
        SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed_tasks,
        SUM(CASE WHEN status = 'inactive' THEN 1 ELSE 0 END) as inactive_tasks
    FROM tasks
";
$stats_result = $conn->query($stats_query);
$stats = $stats_result->fetch_assoc();

$page_title = 'Manage Tasks';
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $page_title; ?> - MAKSIM HR</title>
    <?php include '../includes/head.php'; ?>
    <style>
        /* Task Management Styles */
        .task-form { background: white; border-radius: 15px; padding: 25px; margin-bottom: 30px; box-shadow: 0 5px 15px rgba(0,0,0,0.05); }
        .task-stats { display: grid; grid-template-columns: repeat(4, 1fr); gap: 20px; margin-bottom: 30px; }
        .stat-card { background: white; border-radius: 12px; padding: 20px; box-shadow: 0 2px 8px rgba(0,0,0,0.05); transition: transform 0.2s; }
        .stat-card:hover { transform: translateY(-2px); box-shadow: 0 4px 12px rgba(0,0,0,0.1); }
        .stat-card .stat-icon { width: 48px; height: 48px; border-radius: 12px; display: flex; align-items: center; justify-content: center; margin-bottom: 15px; font-size: 24px; }
        .stat-card .stat-value { font-size: 32px; font-weight: 700; margin-bottom: 5px; }
        .stat-card .stat-label { font-size: 14px; color: #718096; }
        .stat-card .stat-sub { font-size: 12px; color: #48bb78; margin-top: 10px; padding-top: 10px; border-top: 1px solid #e2e8f0; }
        .stat-card.total { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; }
        .stat-card.total .stat-sub { border-top-color: rgba(255,255,255,0.2); color: rgba(255,255,255,0.9); }
        .stat-card.active { background: linear-gradient(135deg, #48bb78 0%, #38a169 100%); color: white; }
        .stat-card.completed { background: linear-gradient(135deg, #4299e1 0%, #3182ce 100%); color: white; }
        .stat-card.inactive { background: linear-gradient(135deg, #a0aec0 0%, #718096 100%); color: white; }
        .filter-bar { display: flex; gap: 15px; align-items: center; margin-bottom: 20px; flex-wrap: wrap; }
        .filter-item { display: flex; align-items: center; gap: 8px; }
        .filter-item label { font-weight: 600; color: #4a5568; font-size: 14px; }
        .filter-item select, .filter-item input { width: 200px; }
        .search-box { display: flex; gap: 5px; }
        .search-box input { width: 250px; }
        .btn-search { background: #4299e1; color: white; border: none; padding: 8px 16px; border-radius: 6px; cursor: pointer; }
        .btn-search:hover { background: #3182ce; }
        .btn-reset { background: #a0aec0; color: white; border: none; padding: 8px 16px; border-radius: 6px; cursor: pointer; text-decoration: none; font-size: 14px; }
        .btn-reset:hover { background: #718096; }
        .task-table { width: 100%; border-collapse: collapse; }
        .task-table th { background: #f7fafc; padding: 12px; text-align: left; font-weight: 600; color: #4a5568; border-bottom: 2px solid #e2e8f0; }
        .task-table td { padding: 12px; border-bottom: 1px solid #e2e8f0; vertical-align: middle; }
        .task-table tr:hover { background: #f7fafc; }
        .status-badge { padding: 4px 12px; border-radius: 20px; font-size: 12px; font-weight: 600; display: inline-block; }
        .status-active { background: #c6f6d5; color: #22543d; }
        .status-completed { background: #bee3f8; color: #2c5282; }
        .status-inactive { background: #fed7d7; color: #742a2a; }
        .action-buttons { display: flex; gap: 8px; }
        .btn-edit { background: #4299e1; color: white; padding: 4px 10px; border-radius: 4px; text-decoration: none; font-size: 12px; display: inline-flex; align-items: center; gap: 5px; }
        .btn-edit:hover { background: #3182ce; }
        .btn-delete { background: #f56565; color: white; padding: 4px 10px; border-radius: 4px; text-decoration: none; font-size: 12px; display: inline-flex; align-items: center; gap: 5px; }
        .btn-delete:hover { background: #e53e3e; }
        .btn-view { background: #48bb78; color: white; padding: 4px 10px; border-radius: 4px; text-decoration: none; font-size: 12px; display: inline-flex; align-items: center; gap: 5px; }
        .btn-view:hover { background: #38a169; }
        .usage-badge { background: #e2e8f0; padding: 2px 8px; border-radius: 12px; font-size: 11px; color: #4a5568; }
        .task-icon { font-size: 20px; margin-right: 8px; }
        .task-name { display: flex; align-items: center; font-weight: 600; }
        .description-cell { max-width: 300px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; color: #718096; }
        .timestamp { font-size: 11px; color: #a0aec0; margin-top: 2px; }
        .form-group { margin-bottom: 20px; }
        .form-group label { display: block; margin-bottom: 8px; font-weight: 600; color: #4a5568; }
        .form-group input, .form-group select, .form-group textarea { width: 100%; padding: 10px; border: 1px solid #e2e8f0; border-radius: 6px; font-size: 14px; }
        .form-group textarea { min-height: 100px; resize: vertical; }
        .form-row { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; }
        .btn-submit { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; border: none; padding: 12px 30px; border-radius: 8px; font-weight: 600; cursor: pointer; font-size: 14px; }
        .btn-submit:hover { transform: translateY(-2px); box-shadow: 0 10px 20px rgba(102, 126, 234, 0.3); }
        .btn-cancel { background: #a0aec0; color: white; border: none; padding: 12px 30px; border-radius: 8px; font-weight: 600; cursor: pointer; text-decoration: none; display: inline-block; margin-left: 10px; }
        .btn-cancel:hover { background: #718096; }
        .page-title { display: flex; align-items: center; justify-content: space-between; margin-bottom: 20px; }
        .title-left { display: flex; align-items: center; gap: 10px; }
        .add-button { background: #48bb78; color: white; padding: 10px 20px; border-radius: 8px; text-decoration: none; display: inline-flex; align-items: center; gap: 8px; font-weight: 600; }
        .add-button:hover { background: #38a169; transform: translateY(-2px); box-shadow: 0 5px 10px rgba(72, 187, 120, 0.3); }
        
        /* Unicode Icons */
        .icon-tasks:before { content: "📋 "; }
        .icon-plus:before { content: "➕ "; }
        .icon-edit:before { content: "✏️ "; }
        .icon-delete:before { content: "🗑️ "; }
        .icon-view:before { content: "👁️ "; }
        .icon-search:before { content: "🔍 "; }
        .icon-reset:before { content: "🔄 "; }
        .icon-save:before { content: "💾 "; }
        .icon-cancel:before { content: "❌ "; }
        .icon-success:before { content: "✅ "; }
        .icon-error:before { content: "❌ "; }
        .icon-info:before { content: "ℹ️ "; }
        .icon-warning:before { content: "⚠️ "; }
        .icon-active:before { content: "✅ "; }
        .icon-completed:before { content: "✔️ "; }
        .icon-inactive:before { content: "⏸️ "; }
        .icon-total:before { content: "📊 "; }
    </style>
</head>
<body>
    <?php include '../includes/header.php'; ?>
    
    <div class="app-main">
        <?php include '../includes/sidebar.php'; ?>
        
        <div class="main-content">
            <div class="page-title">
                <div class="title-left">
                    <i class="icon-tasks" style="font-size: 24px;"></i>
                    <h2><?php echo $page_title; ?></h2>
                </div>
                <a href="?add=1" class="add-button">
                    <i class="icon-plus"></i> Add New Task
                </a>
            </div>
            
            <?php echo $message; ?>
            <?php echo $error; ?>
            
            <!-- Statistics Cards -->
            <div class="task-stats">
                <div class="stat-card total">
                    <div class="stat-icon" style="background: rgba(255,255,255,0.2);"><i class="icon-total"></i></div>
                    <div class="stat-value"><?php echo $stats['total_tasks']; ?></div>
                    <div class="stat-label">Total Tasks</div>
                    <div class="stat-sub">All tasks in system</div>
                </div>
                
                <div class="stat-card active">
                    <div class="stat-icon" style="background: rgba(255,255,255,0.2);"><i class="icon-active"></i></div>
                    <div class="stat-value"><?php echo $stats['active_tasks']; ?></div>
                    <div class="stat-label">Active Tasks</div>
                    <div class="stat-sub">Currently in use</div>
                </div>
                
                <div class="stat-card completed">
                    <div class="stat-icon" style="background: rgba(255,255,255,0.2);"><i class="icon-completed"></i></div>
                    <div class="stat-value"><?php echo $stats['completed_tasks']; ?></div>
                    <div class="stat-label">Completed Tasks</div>
                    <div class="stat-sub">Marked as done</div>
                </div>
                
                <div class="stat-card inactive">
                    <div class="stat-icon" style="background: rgba(255,255,255,0.2);"><i class="icon-inactive"></i></div>
                    <div class="stat-value"><?php echo $stats['inactive_tasks']; ?></div>
                    <div class="stat-label">Inactive Tasks</div>
                    <div class="stat-sub">Not in use</div>
                </div>
            </div>
            
            <!-- Add/Edit Task Form -->
            <?php if (isset($_GET['add']) || $edit_task): ?>
            <div class="task-form">
                <h3 style="margin-bottom: 20px; color: #4a5568;">
                    <?php echo $edit_task ? '<i class="icon-edit"></i> Edit Task' : '<i class="icon-plus"></i> Add New Task'; ?>
                </h3>
                
                <form method="POST" action="">
                    <?php if ($edit_task): ?>
                    <input type="hidden" name="task_id" value="<?php echo $edit_task['id']; ?>">
                    <?php endif; ?>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label><span class="icon-task"></span> Task Name *</label>
                            <input type="text" name="task_name" placeholder="Enter task name" value="<?php echo $edit_task ? htmlspecialchars($edit_task['task_name']) : ''; ?>" required>
                        </div>
                        
                        <div class="form-group">
                            <label><span class="icon-flag"></span> Status *</label>
                            <select name="status" required>
                                <option value="active" <?php echo ($edit_task && $edit_task['status'] == 'active') ? 'selected' : ''; ?>>✅ Active</option>
                                <option value="completed" <?php echo ($edit_task && $edit_task['status'] == 'completed') ? 'selected' : ''; ?>>✔️ Completed</option>
                                <option value="inactive" <?php echo ($edit_task && $edit_task['status'] == 'inactive') ? 'selected' : ''; ?>>⏸️ Inactive</option>
                            </select>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label><span class="icon-info"></span> Description</label>
                        <textarea name="description" placeholder="Enter task description"><?php echo $edit_task ? htmlspecialchars($edit_task['description']) : ''; ?></textarea>
                    </div>
                    
                    <div style="display: flex; align-items: center;">
                        <button type="submit" name="<?php echo $edit_task ? 'edit_task' : 'add_task'; ?>" class="btn-submit">
                            <i class="icon-save"></i> <?php echo $edit_task ? 'Update Task' : 'Add Task'; ?>
                        </button>
                        <a href="manage_tasks.php" class="btn-cancel">
                            <i class="icon-cancel"></i> Cancel
                        </a>
                    </div>
                </form>
            </div>
            <?php endif; ?>
            
            <!-- Filter Bar -->
            <div class="filter-bar">
                <form method="GET" action="" style="display: flex; gap: 15px; align-items: center; flex-wrap: wrap; width: 100%;">
                    <div class="filter-item">
                        <label><i class="icon-filter"></i> Status:</label>
                        <select name="status" class="form-control" onchange="this.form.submit()">
                            <option value="all" <?php echo ($status_filter == 'all') ? 'selected' : ''; ?>>All Status</option>
                            <option value="active" <?php echo ($status_filter == 'active') ? 'selected' : ''; ?>>Active</option>
                            <option value="completed" <?php echo ($status_filter == 'completed') ? 'selected' : ''; ?>>Completed</option>
                            <option value="inactive" <?php echo ($status_filter == 'inactive') ? 'selected' : ''; ?>>Inactive</option>
                        </select>
                    </div>
                    
                    <div class="search-box">
                        <input type="text" name="search" class="form-control" placeholder="Search tasks..." value="<?php echo htmlspecialchars($search); ?>">
                        <button type="submit" class="btn-search"><i class="icon-search"></i> Search</button>
                        <?php if (!empty($search) || $status_filter != 'all'): ?>
                        <a href="manage_tasks.php" class="btn-reset"><i class="icon-reset"></i> Reset</a>
                        <?php endif; ?>
                    </div>
                </form>
            </div>
            
            <!-- Tasks Table -->
            <div class="table-container">
                <table class="task-table">
                    <thead>
                        <tr>
                            <th>Task Name</th>
                            <th>Description</th>
                            <th>Category</th>
                            <th>Status</th>
                            <th>Usage</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($tasks_result && $tasks_result->num_rows > 0): ?>
                            <?php while ($task = $tasks_result->fetch_assoc()): 
                                // Determine icon based on task name
                                $task_icon = '📋';
                                if (strpos($task['task_name'], 'Electrical') !== false) $task_icon = '⚡';
                                elseif (strpos($task['task_name'], 'Cable Trays') !== false) $task_icon = '🔌';
                                elseif (strpos($task['task_name'], 'Support') !== false) $task_icon = '🛠️';
                                elseif (strpos($task['task_name'], 'Railings') !== false) $task_icon = '🚧';
                                elseif (strpos($task['task_name'], 'Architecture') !== false) $task_icon = '🏛️';
                                elseif (strpos($task['task_name'], 'Stairs') !== false) $task_icon = '▁▃▅▇';
                                elseif (strpos($task['task_name'], 'Equipment') !== false) $task_icon = '⚙️';
                                elseif (strpos($task['task_name'], 'Piping') !== false) $task_icon = '🔧';
                                elseif (strpos($task['task_name'], 'Structure') !== false) $task_icon = '🏗️';
                                elseif (strpos($task['task_name'], 'HVAC') !== false) $task_icon = '❄️';
                            ?>
                            <tr>
                                <td>
                                    <div class="task-name">
                                        <span class="task-icon"><?php echo $task_icon; ?></span>
                                        <?php echo htmlspecialchars($task['task_name']); ?>
                                    </div>
                                </td>
                                <td class="description-cell" title="<?php echo htmlspecialchars($task['description'] ?? ''); ?>">
                                    <?php echo $task['description'] ? htmlspecialchars(substr($task['description'], 0, 50)) . (strlen($task['description']) > 50 ? '...' : '') : '-'; ?>
                                </td>
                                <td>
                                    <?php echo htmlspecialchars($task['category'] ?? 'General'); ?>
                                </td>
                                <td>
                                    <?php
                                    $status_class = '';
                                    $status_text = '';
                                    if ($task['status'] == 'active') {
                                        $status_class = 'status-active';
                                        $status_text = '✅ Active';
                                    } elseif ($task['status'] == 'completed') {
                                        $status_class = 'status-completed';
                                        $status_text = '✔️ Completed';
                                    } else {
                                        $status_class = 'status-inactive';
                                        $status_text = '⏸️ Inactive';
                                    }
                                    ?>
                                    <span class="status-badge <?php echo $status_class; ?>"><?php echo $status_text; ?></span>
                                </td>
                                <td>
                                    <span class="usage-badge">
                                        <i class="icon-file"></i> <?php echo $task['timesheet_count']; ?> entries
                                    </span>
                                </td>
                                <td>
                                    <div class="action-buttons">
                                        <a href="?edit=<?php echo $task['id']; ?>&status=<?php echo urlencode($status_filter); ?>&search=<?php echo urlencode($search); ?>" class="btn-edit">
                                            <i class="icon-edit"></i> Edit
                                        </a>
                                        <?php if ($task['timesheet_count'] == 0): ?>
                                        <a href="?delete=<?php echo $task['id']; ?>&status=<?php echo urlencode($status_filter); ?>&search=<?php echo urlencode($search); ?>" 
                                           class="btn-delete" 
                                           onclick="return confirm('Are you sure you want to delete this task? This action cannot be undone.')">
                                            <i class="icon-delete"></i> Delete
                                        </a>
                                        <?php else: ?>
                                        <span class="btn-delete" style="opacity: 0.5; cursor: not-allowed;" title="Cannot delete - used in timesheets">
                                            <i class="icon-delete"></i> Delete
                                        </span>
                                        <?php endif; ?>
                                        <a href="#" class="btn-view" onclick="alert('Task: <?php echo htmlspecialchars(addslashes($task['task_name'])); ?>\nDescription: <?php echo htmlspecialchars(addslashes($task['description'] ?? 'No description')); ?>\nCategory: <?php echo $task['category'] ?? 'General'; ?>\nStatus: <?php echo $task['status']; ?>\nUsed in: <?php echo $task['timesheet_count']; ?> timesheet entries'); return false;">
                                            <i class="icon-view"></i> View
                                        </a>
                                    </div>
                                </td>
                            </tr>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="6" style="text-align: center; padding: 40px; color: #718096;">
                                    <i class="icon-tasks" style="font-size: 48px; margin-bottom: 15px; display: block; color: #cbd5e0;"></i>
                                    No tasks found
                                    <?php if (!empty($search) || $status_filter != 'all'): ?>
                                    <p style="margin-top: 10px;">Try adjusting your filters or <a href="manage_tasks.php">reset them</a>.</p>
                                    <?php else: ?>
                                    <p style="margin-top: 10px;">Click "Add New Task" to create your first task.</p>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    
    <script src="../assets/js/app.js"></script>
</body>
</html>