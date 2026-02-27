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

// Add new project
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_project'])) {
    $project_code = sanitize($_POST['project_code']);
    $project_name = sanitize($_POST['project_name']);
    $description = sanitize($_POST['description']);
    $status = sanitize($_POST['status']);
    $created_by = $_SESSION['user_id'];
    
    $stmt = $conn->prepare("
        INSERT INTO projects (project_code, project_name, description, status, created_by)
        VALUES (?, ?, ?, ?, ?)
    ");
    $stmt->bind_param("ssssi", $project_code, $project_name, $description, $status, $created_by);
    
    if ($stmt->execute()) {
        $message = '<div class="alert alert-success"><i class="icon-success"></i> Project added successfully!</div>';
    } else {
        $message = '<div class="alert alert-error"><i class="icon-error"></i> Error adding project: ' . $stmt->error . '</div>';
    }
    $stmt->close();
}

// Edit project
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_project'])) {
    $project_id = intval($_POST['project_id']);
    $project_code = sanitize($_POST['project_code']);
    $project_name = sanitize($_POST['project_name']);
    $description = sanitize($_POST['description']);
    $status = sanitize($_POST['status']);
    
    $stmt = $conn->prepare("
        UPDATE projects 
        SET project_code = ?, project_name = ?, description = ?, status = ?
        WHERE id = ?
    ");
    $stmt->bind_param("ssssi", $project_code, $project_name, $description, $status, $project_id);
    
    if ($stmt->execute()) {
        $message = '<div class="alert alert-success"><i class="icon-success"></i> Project updated successfully!</div>';
    } else {
        $message = '<div class="alert alert-error"><i class="icon-error"></i> Error updating project: ' . $stmt->error . '</div>';
    }
    $stmt->close();
}

// Delete project
if (isset($_GET['delete'])) {
    $project_id = intval($_GET['delete']);
    
    // Check if project is being used in timesheets
    $check_stmt = $conn->prepare("SELECT COUNT(*) as count FROM timesheets WHERE project_id = ?");
    $check_stmt->bind_param("i", $project_id);
    $check_stmt->execute();
    $check_result = $check_stmt->get_result();
    $check_row = $check_result->fetch_assoc();
    $check_stmt->close();
    
    if ($check_row['count'] > 0) {
        // Project is in use, just mark as inactive instead of deleting
        $stmt = $conn->prepare("UPDATE projects SET status = 'inactive' WHERE id = ?");
        $stmt->bind_param("i", $project_id);
        
        if ($stmt->execute()) {
            $message = '<div class="alert alert-warning"><i class="icon-warning"></i> Project is being used in timesheets. It has been marked as inactive instead of deleted.</div>';
        }
    } else {
        // Project not in use, can be deleted
        $stmt = $conn->prepare("DELETE FROM projects WHERE id = ?");
        $stmt->bind_param("i", $project_id);
        
        if ($stmt->execute()) {
            $message = '<div class="alert alert-success"><i class="icon-success"></i> Project deleted successfully!</div>';
        } else {
            $message = '<div class="alert alert-error"><i class="icon-error"></i> Error deleting project: ' . $stmt->error . '</div>';
        }
    }
    $stmt->close();
}

// Get all projects
$projects_result = $conn->query("
    SELECT p.*, u.full_name as created_by_name 
    FROM projects p 
    LEFT JOIN users u ON p.created_by = u.id 
    ORDER BY 
        CASE p.status 
            WHEN 'active' THEN 1 
            WHEN 'inactive' THEN 2 
            WHEN 'completed' THEN 3 
        END, 
        p.project_name ASC
");
$projects = $projects_result->fetch_all(MYSQLI_ASSOC);

// Get project for editing
$edit_project = null;
if (isset($_GET['edit'])) {
    $edit_id = intval($_GET['edit']);
    $edit_stmt = $conn->prepare("SELECT * FROM projects WHERE id = ?");
    $edit_stmt->bind_param("i", $edit_id);
    $edit_stmt->execute();
    $edit_result = $edit_stmt->get_result();
    $edit_project = $edit_result->fetch_assoc();
    $edit_stmt->close();
}

$page_title = "Manage Projects - MAKSIM HR";
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Projects - MAKSIM HR</title>
    <?php include '../includes/head.php'; ?>
    <style>
        .project-form {
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
        
        .status-active {
            background: #c6f6d5;
            color: #22543d;
        }
        
        .status-inactive {
            background: #fed7d7;
            color: #742a2a;
        }
        
        .status-completed {
            background: #bee3f8;
            color: #2c5282;
        }
        
        .warning-message {
            background: #fffaf0;
            border: 1px solid #fbd38d;
            color: #c05621;
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
        }
        
        .project-stats {
            display: flex;
            gap: 20px;
            margin-bottom: 20px;
        }
        
        .stat-card {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
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
        
        .btn-remove {
            background: #f56565;
            color: white;
        }
        
        .btn-remove:hover {
            background: #c53030;
        }
    </style>
</head>
<body>
    <?php include '../includes/header.php'; ?>
    
    <div class="app-main">
        <?php include '../includes/sidebar.php'; ?>
        
        <div class="main-content">
            <h2 class="page-title">
                <i class="icon-project"></i> Manage Projects
                <span style="font-size: 14px; color: #718096; margin-left: 10px;">
                    (HR & Project Manager Access)
                </span>
            </h2>
            
            <?php echo $message; ?>
            
            <!-- Project Statistics -->
            <div class="project-stats">
                <?php
                $active_count = 0;
                $inactive_count = 0;
                $completed_count = 0;
                
                foreach ($projects as $project) {
                    if ($project['status'] == 'active') $active_count++;
                    elseif ($project['status'] == 'inactive') $inactive_count++;
                    elseif ($project['status'] == 'completed') $completed_count++;
                }
                ?>
                <div class="stat-card">
                    <i class="icon-play"></i>
                    <div class="stat-number"><?php echo $active_count; ?></div>
                    <div>Active Projects</div>
                </div>
                <div class="stat-card" style="background: linear-gradient(135deg, #48bb78 0%, #38a169 100%);">
                    <i class="icon-check"></i>
                    <div class="stat-number"><?php echo $completed_count; ?></div>
                    <div>Completed Projects</div>
                </div>
                <div class="stat-card" style="background: linear-gradient(135deg, #f56565 0%, #c53030 100%);">
                    <i class="icon-stop"></i>
                    <div class="stat-number"><?php echo $inactive_count; ?></div>
                    <div>Inactive Projects</div>
                </div>
            </div>
            
            <!-- Add/Edit Project Form -->
            <div class="project-form">
                <h3 style="margin-bottom: 20px; color: #4a5568;">
                    <i class="icon <?php echo $edit_project ? 'icon-edit' : 'icon-plus'; ?>"></i>
                    <?php echo $edit_project ? 'Edit Project' : 'Add New Project'; ?>
                </h3>
                
                <form method="POST" action="">
                    <?php if ($edit_project): ?>
                    <input type="hidden" name="project_id" value="<?php echo $edit_project['id']; ?>">
                    <?php endif; ?>
                    
                    <div class="form-grid">
                        <div>
                            <label class="form-label">Project Code</label>
                            <input type="text" name="project_code" class="form-control" 
                                   value="<?php echo $edit_project ? htmlspecialchars($edit_project['project_code']) : ''; ?>" 
                                   placeholder="e.g., PRJ001">
                        </div>
                        <div>
                            <label class="form-label">Project Name *</label>
                            <input type="text" name="project_name" class="form-control" required
                                   value="<?php echo $edit_project ? htmlspecialchars($edit_project['project_name']) : ''; ?>" 
                                   placeholder="Enter project name">
                        </div>
                        <div>
                            <label class="form-label">Status *</label>
                            <select name="status" class="form-control" required>
                                <option value="active" <?php echo ($edit_project && $edit_project['status'] == 'active') ? 'selected' : ''; ?>>Active</option>
                                <option value="inactive" <?php echo ($edit_project && $edit_project['status'] == 'inactive') ? 'selected' : ''; ?>>Inactive</option>
                                <option value="completed" <?php echo ($edit_project && $edit_project['status'] == 'completed') ? 'selected' : ''; ?>>Completed</option>
                            </select>
                        </div>
                    </div>
                    
                    <div style="margin-bottom: 20px;">
                        <label class="form-label">Description</label>
                        <textarea name="description" class="form-control" rows="3" placeholder="Enter project description"><?php echo $edit_project ? htmlspecialchars($edit_project['description']) : ''; ?></textarea>
                    </div>
                    
                    <div style="display: flex; gap: 10px;">
                        <button type="submit" name="<?php echo $edit_project ? 'edit_project' : 'add_project'; ?>" class="btn">
                            <i class="icon <?php echo $edit_project ? 'icon-save' : 'icon-plus'; ?>"></i>
                            <?php echo $edit_project ? 'Update Project' : 'Add Project'; ?>
                        </button>
                        
                        <?php if ($edit_project): ?>
                        <a href="manage_projects.php" class="btn" style="background: #718096;">
                            <i class="icon-cancel"></i> Cancel Edit
                        </a>
                        <?php endif; ?>
                    </div>
                </form>
            </div>
            
            <!-- Projects List -->
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title"><i class="icon-list"></i> All Projects</h3>
                    <div>
                        <span style="color: #718096; margin-right: 10px;">
                            <i class="icon-info"></i> Click Delete to remove or mark inactive
                        </span>
                    </div>
                </div>
                <div class="table-container">
                    <table>
                        <thead>
                            <tr>
                                <th>Project Code</th>
                                <th>Project Name</th>
                                <th>Description</th>
                                <th>Status</th>
                                <th>Created By</th>
                                <th>Created Date</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!empty($projects)): ?>
                                <?php foreach ($projects as $project): ?>
                                <tr>
                                    <td><strong><?php echo $project['project_code'] ?: '-'; ?></strong></td>
                                    <td><?php echo htmlspecialchars($project['project_name']); ?></td>
                                    <td title="<?php echo htmlspecialchars($project['description']); ?>">
                                        <?php 
                                        if ($project['description']) {
                                            echo strlen($project['description']) > 50 ? 
                                                substr(htmlspecialchars($project['description']), 0, 50) . '...' : 
                                                htmlspecialchars($project['description']);
                                        } else {
                                            echo '-';
                                        }
                                        ?>
                                    </td>
                                    <td>
                                        <span class="status-badge status-<?php 
                                            echo $project['status'] == 'active' ? 'success' : 
                                                ($project['status'] == 'inactive' ? 'error' : 'warning'); 
                                        ?>">
                                            <?php echo ucfirst($project['status']); ?>
                                        </span>
                                    </td>
                                    <td><?php echo $project['created_by_name'] ?: 'System'; ?></td>
                                    <td><?php echo date('Y-m-d', strtotime($project['created_date'])); ?></td>
                                    <td>
                                        <div class="action-buttons">
                                            <a href="?edit=<?php echo $project['id']; ?>" class="btn-small" style="background: #4299e1;">
                                                <i class="icon-edit"></i> Edit
                                            </a>
                                            <a href="?delete=<?php echo $project['id']; ?>" 
                                               class="btn-small btn-remove"
                                               onclick="return confirm('Are you sure you want to remove this project?\n\nIf the project is being used in timesheets, it will be marked as inactive instead of deleted.')">
                                                <i class="icon-delete"></i> Remove
                                            </a>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="7" style="text-align: center; padding: 40px; color: #718096;">
                                        <i class="icon-folder-open" style="font-size: 48px; margin-bottom: 15px; display: block;"></i>
                                        No projects found. Click "Add New Project" to create your first project.
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            
            <!-- Information Box -->
            <div class="warning-message">
                <i class="icon-info"></i>
                <strong>Project Management Guidelines:</strong>
                <ul style="margin-top: 10px; margin-left: 20px;">
                    <li><strong>Remove Projects:</strong> Click the "Remove" button to delete or mark projects as inactive</li>
                    <li>Projects that are being used in timesheets cannot be permanently deleted - they will be marked as inactive instead</li>
                    <li>Inactive and Completed projects will not appear in the timesheet dropdown menu</li>
                    <li>Only HR, Admin, and Project Managers can manage projects</li>
                    <li>Use the Edit button to update project details or change status</li>
                </ul>
            </div>
        </div>
    </div>
    
    <script src="../assets/js/app.js"></script>
</body>
</html>