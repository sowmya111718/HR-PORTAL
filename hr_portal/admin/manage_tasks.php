<?php
require_once '../config/db.php';
require_once '../includes/icon_functions.php'; // ADDED

if (!isLoggedIn()) {
    header('Location: ../auth/login.php');
    exit();
}

$role = $_SESSION['role'];
// Only allow HR, Admin, and Project Manager
if (!in_array($role, ['hr', 'admin', 'pm'])) {
    header('Location: ../dashboard.php');
    exit();
}

$message = '';

// Check if tasks table exists
$table_check = $conn->query("SHOW TABLES LIKE 'tasks'");
$tasks_table_exists = $table_check->num_rows > 0;

// Get all projects for dropdown
$projects = [];
$projects_result = $conn->query("SELECT id, project_name, project_code FROM projects WHERE status = 'active' ORDER BY project_name");
if ($projects_result) {
    $projects = $projects_result->fetch_all(MYSQLI_ASSOC);
}

// Add new task
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_task'])) {
    if (!$tasks_table_exists) {
        $message = '<div class="alert alert-error"><i class="icon-error"></i> Tasks table does not exist. Please run the database setup first.</div>';
    } else {
        $project_id = intval($_POST['project_id']);
        $task_name = sanitize($_POST['task_name']);
        $description = sanitize($_POST['description']);
        $status = sanitize($_POST['status']);
        $created_by = $_SESSION['user_id'];
        
        $stmt = $conn->prepare("
            INSERT INTO tasks (project_id, task_name, description, status, created_by)
            VALUES (?, ?, ?, ?, ?)
        ");
        $stmt->bind_param("isssi", $project_id, $task_name, $description, $status, $created_by);
        
        if ($stmt->execute()) {
            $message = '<div class="alert alert-success"><i class="icon-success"></i> Task added successfully!</div>';
        } else {
            $message = '<div class="alert alert-error"><i class="icon-error"></i> Error adding task: ' . $stmt->error . '</div>';
        }
        $stmt->close();
    }
}

// Edit task
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_task'])) {
    $task_id = intval($_POST['task_id']);
    $project_id = intval($_POST['project_id']);
    $task_name = sanitize($_POST['task_name']);
    $description = sanitize($_POST['description']);
    $status = sanitize($_POST['status']);
    
    $stmt = $conn->prepare("
        UPDATE tasks 
        SET project_id = ?, task_name = ?, description = ?, status = ?
        WHERE id = ?
    ");
    $stmt->bind_param("isssi", $project_id, $task_name, $description, $status, $task_id);
    
    if ($stmt->execute()) {
        $message = '<div class="alert alert-success"><i class="icon-success"></i> Task updated successfully!</div>';
    } else {
        $message = '<div class="alert alert-error"><i class="icon-error"></i> Error updating task: ' . $stmt->error . '</div>';
    }
    $stmt->close();
}

// Delete task
if (isset($_GET['delete'])) {
    $task_id = intval($_GET['delete']);
    
    // Check if task is being used in timesheets
    $check_stmt = $conn->prepare("SELECT COUNT(*) as count FROM timesheets WHERE task_id = ?");
    $check_stmt->bind_param("i", $task_id);
    $check_stmt->execute();
    $check_result = $check_stmt->get_result();
    $check_row = $check_result->fetch_assoc();
    $check_stmt->close();
    
    if ($check_row['count'] > 0) {
        // Task is in use, just mark as inactive instead of deleting
        $stmt = $conn->prepare("UPDATE tasks SET status = 'inactive' WHERE id = ?");
        $stmt->bind_param("i", $task_id);
        
        if ($stmt->execute()) {
            $message = '<div class="alert alert-warning"><i class="icon-warning"></i> Task is being used in timesheets. It has been marked as inactive instead of deleted.</div>';
        }
    } else {
        // Task not in use, can be deleted
        $stmt = $conn->prepare("DELETE FROM tasks WHERE id = ?");
        $stmt->bind_param("i", $task_id);
        
        if ($stmt->execute()) {
            $message = '<div class="alert alert-success"><i class="icon-success"></i> Task deleted successfully!</div>';
        } else {
            $message = '<div class="alert alert-error"><i class="icon-error"></i> Error deleting task: ' . $stmt->error . '</div>';
        }
    }
    $stmt->close();
}

// Get task for editing
$edit_task = null;
if (isset($_GET['edit'])) {
    $edit_id = intval($_GET['edit']);
    $edit_stmt = $conn->prepare("SELECT * FROM tasks WHERE id = ?");
    $edit_stmt->bind_param("i", $edit_id);
    $edit_stmt->execute();
    $edit_result = $edit_stmt->get_result();
    $edit_task = $edit_result->fetch_assoc();
    $edit_stmt->close();
}

// Get all tasks with project info
$tasks = [];
if ($tasks_table_exists) {
    $tasks_result = $conn->query("
        SELECT t.*, p.project_name, p.project_code, u.full_name as created_by_name 
        FROM tasks t 
        JOIN projects p ON t.project_id = p.id 
        LEFT JOIN users u ON t.created_by = u.id 
        ORDER BY 
            CASE t.status 
                WHEN 'active' THEN 1 
                WHEN 'inactive' THEN 2 
                WHEN 'completed' THEN 3 
            END, 
            p.project_name, t.task_name ASC
    ");
    if ($tasks_result) {
        $tasks = $tasks_result->fetch_all(MYSQLI_ASSOC);
    }
}

// Get statistics
$active_tasks_count = 0;
$inactive_tasks_count = 0;
$completed_tasks_count = 0;

foreach ($tasks as $task) {
    if ($task['status'] == 'active') $active_tasks_count++;
    elseif ($task['status'] == 'inactive') $inactive_tasks_count++;
    elseif ($task['status'] == 'completed') $completed_tasks_count++;
}

$page_title = "Manage Tasks - MAKSIM HR";
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Tasks - MAKSIM HR</title>
    <?php include '../includes/head.php'; ?>
    <style>
        .task-form {
            background: white;
            border-radius: 15px;
            padding: 25px;
            margin-bottom: 30px;
            box-shadow: 0 5px 15px rgba(0,0,0,0.05);
        }
        
        .form-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            margin-bottom: 20px;
        }
        
        .task-stats {
            display: flex;
            gap: 20px;
            margin-bottom: 20px;
        }
        
        .stat-card {
            background: linear-gradient(135deg, #805ad5 0%, #6b46c1 100%);
            color: white;
            padding: 20px;
            border-radius: 10px;
            flex: 1;
        }
        
        .stat-card i {
            font-size: 24px;
            margin-bottom: 10px;
        }
        
        .stat-number {
            font-size: 28px;
            font-weight: bold;
        }
        
        .db-warning {
            background: #fffaf0;
            border: 1px solid #fbd38d;
            color: #c05621;
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
        }
        
        .btn-task {
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
        
        .btn-task-add {
            background: #4299e1;
            color: white;
        }
        
        .btn-task-remove {
            background: #f56565;
            color: white;
        }
        
        .btn-task-view {
            background: #48bb78;
            color: white;
        }
    </style>
</head>
<body>
    <?php include '../includes/header.php'; ?>
    
    <div class="app-main">
        <?php include '../includes/sidebar.php'; ?>
        
        <div class="main-content">
            <h2 class="page-title">
                <i class="icon-task"></i> Manage Tasks
                <span style="font-size: 14px; color: #718096; margin-left: 10px;">
                    (HR & Project Manager Access)
                </span>
            </h2>
            
            <?php echo $message; ?>
            
            <?php if (!$tasks_table_exists): ?>
            <div class="db-warning">
                <h3 style="margin-bottom: 10px;"><i class="icon-database"></i> Database Setup Required</h3>
                <p>Please run the following SQL query to create the tasks table:</p>
                <pre style="background: #fed7d7; padding: 15px; border-radius: 5px; margin-top: 10px; overflow-x: auto;">
CREATE TABLE tasks (
    id INT AUTO_INCREMENT PRIMARY KEY,
    project_id INT NOT NULL,
    task_name VARCHAR(255) NOT NULL,
    description TEXT,
    status ENUM('active', 'inactive', 'completed') DEFAULT 'active',
    created_by INT,
    created_date DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX (project_id),
    INDEX (status)
);

ALTER TABLE timesheets 
ADD COLUMN task_id INT NULL AFTER project_id,
ADD COLUMN task_name VARCHAR(255) NULL AFTER task_id;
                </pre>
                <p style="margin-top: 10px;">
                    <a href="../admin/manage_projects.php" class="btn" style="background: #4299e1;"><i class="icon-plus"></i> First, Add Projects</a>
                </p>
            </div>
            <?php else: ?>
            
            <!-- Task Statistics -->
            <div class="task-stats">
                <div class="stat-card">
                    <i class="icon-play"></i>
                    <div class="stat-number"><?php echo $active_tasks_count; ?></div>
                    <div>Active Tasks</div>
                </div>
                <div class="stat-card" style="background: linear-gradient(135deg, #48bb78 0%, #38a169 100%);">
                    <i class="icon-check"></i>
                    <div class="stat-number"><?php echo $completed_tasks_count; ?></div>
                    <div>Completed Tasks</div>
                </div>
                <div class="stat-card" style="background: linear-gradient(135deg, #f56565 0%, #c53030 100%);">
                    <i class="icon-stop"></i>
                    <div class="stat-number"><?php echo $inactive_tasks_count; ?></div>
                    <div>Inactive Tasks</div>
                </div>
            </div>
            
            <!-- Add/Edit Task Form -->
            <div class="task-form">
                <h3 style="margin-bottom: 20px; color: #4a5568;">
                    <i class="icon <?php echo $edit_task ? 'icon-edit' : 'icon-plus'; ?>"></i>
                    <?php echo $edit_task ? 'Edit Task' : 'Add New Task'; ?>
                </h3>
                
                <form method="POST" action="">
                    <?php if ($edit_task): ?>
                    <input type="hidden" name="task_id" value="<?php echo $edit_task['id']; ?>">
                    <?php endif; ?>
                    
                    <div class="form-grid">
                        <div>
                            <label class="form-label">Project *</label>
                            <select name="project_id" class="form-control" required>
                                <option value="">Select Project</option>
                                <?php foreach ($projects as $project): ?>
                                <option value="<?php echo $project['id']; ?>" 
                                    <?php echo ($edit_task && $edit_task['project_id'] == $project['id']) ? 'selected' : ''; ?>>
                                    <?php echo $project['project_code'] ? '[' . $project['project_code'] . '] ' : ''; ?>
                                    <?php echo $project['project_name']; ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <label class="form-label">Task Name *</label>
                            <input type="text" name="task_name" class="form-control" required
                                   value="<?php echo $edit_task ? htmlspecialchars($edit_task['task_name']) : ''; ?>" 
                                   placeholder="Enter task name">
                        </div>
                        <div>
                            <label class="form-label">Status *</label>
                            <select name="status" class="form-control" required>
                                <option value="active" <?php echo ($edit_task && $edit_task['status'] == 'active') ? 'selected' : ''; ?>>Active</option>
                                <option value="inactive" <?php echo ($edit_task && $edit_task['status'] == 'inactive') ? 'selected' : ''; ?>>Inactive</option>
                                <option value="completed" <?php echo ($edit_task && $edit_task['status'] == 'completed') ? 'selected' : ''; ?>>Completed</option>
                            </select>
                        </div>
                    </div>
                    
                    <div style="margin-bottom: 20px;">
                        <label class="form-label">Description</label>
                        <textarea name="description" class="form-control" rows="3" placeholder="Enter task description"><?php echo $edit_task ? htmlspecialchars($edit_task['description']) : ''; ?></textarea>
                    </div>
                    
                    <div style="display: flex; gap: 10px;">
                        <button type="submit" name="<?php echo $edit_task ? 'edit_task' : 'add_task'; ?>" class="btn">
                            <i class="icon <?php echo $edit_task ? 'icon-save' : 'icon-plus'; ?>"></i>
                            <?php echo $edit_task ? 'Update Task' : 'Add Task'; ?>
                        </button>
                        
                        <?php if ($edit_task): ?>
                        <a href="manage_tasks.php" class="btn" style="background: #718096;">
                            <i class="icon-cancel"></i> Cancel Edit
                        </a>
                        <?php endif; ?>
                    </div>
                </form>
            </div>
            
            <!-- Tasks List -->
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title"><i class="icon-list"></i> All Tasks</h3>
                    <div>
                        <a href="manage_projects.php" class="btn-task btn-task-view" style="margin-right: 10px;">
                            <i class="icon-project"></i> Manage Projects
                        </a>
                    </div>
                </div>
                <div class="table-container">
                    <table>
                        <thead>
                            <tr>
                                <th>Project</th>
                                <th>Task Name</th>
                                <th>Description</th>
                                <th>Status</th>
                                <th>Created By</th>
                                <th>Created Date</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!empty($tasks)): ?>
                                <?php foreach ($tasks as $task): ?>
                                <tr>
                                    <td>
                                        <strong><?php echo $task['project_code'] ? '[' . $task['project_code'] . '] ' : ''; ?></strong>
                                        <?php echo htmlspecialchars($task['project_name']); ?>
                                    </td>
                                    <td><strong><?php echo htmlspecialchars($task['task_name']); ?></strong></td>
                                    <td title="<?php echo htmlspecialchars($task['description']); ?>">
                                        <?php 
                                        if ($task['description']) {
                                            echo strlen($task['description']) > 50 ? 
                                                substr(htmlspecialchars($task['description']), 0, 50) . '...' : 
                                                htmlspecialchars($task['description']);
                                        } else {
                                            echo '-';
                                        }
                                        ?>
                                    </td>
                                    <td>
                                        <span class="status-badge status-<?php 
                                            echo $task['status'] == 'active' ? 'success' : 
                                                ($task['status'] == 'inactive' ? 'error' : 'warning'); 
                                        ?>">
                                            <?php echo ucfirst($task['status']); ?>
                                        </span>
                                    </td>
                                    <td><?php echo $task['created_by_name'] ?: 'System'; ?></td>
                                    <td><?php echo date('Y-m-d', strtotime($task['created_date'])); ?></td>
                                    <td>
                                        <div class="action-buttons">
                                            <a href="?edit=<?php echo $task['id']; ?>" class="btn-small" style="background: #4299e1;">
                                                <i class="icon-edit"></i> Edit
                                            </a>
                                            <a href="?delete=<?php echo $task['id']; ?>" 
                                               class="btn-small btn-delete"
                                               onclick="return confirm('Are you sure you want to delete this task?\n\nIf the task is being used in timesheets, it will be marked as inactive instead of deleted.')">
                                                <i class="icon-delete"></i> Delete
                                            </a>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="7" style="text-align: center; padding: 40px; color: #718096;">
                                        <i class="icon-task" style="font-size: 48px; margin-bottom: 15px; display: block;"></i>
                                        No tasks found. Click "Add New Task" to create your first task.
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>
    
    <script src="../assets/js/app.js"></script>
</body>
</html>