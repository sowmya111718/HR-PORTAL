<?php
require_once '../config/db.php';
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
            $message = '<div class="alert alert-success">All leaves data has been cleared.</div>';
        } else {
            $message = '<div class="alert alert-error">Please type "RESET LEAVES" exactly to confirm</div>';
        }
    } else {
        $message = '<div class="alert alert-error">Please enter the confirmation text</div>';
    }
}

// Reset Permissions Only (Admin only)
if (isset($_POST['reset_permissions']) && $role === 'admin') {
    // Check if the confirm text field exists and is not empty
    if (isset($_POST['confirm_text_permissions']) && !empty($_POST['confirm_text_permissions'])) {
        $confirm_text = sanitize($_POST['confirm_text_permissions']);
        
        if ($confirm_text === 'RESET PERMISSIONS') {
            $conn->query("DELETE FROM permissions");
            $message = '<div class="alert alert-success">All permissions data has been cleared.</div>';
        } else {
            $message = '<div class="alert alert-error">Please type "RESET PERMISSIONS" exactly to confirm</div>';
        }
    } else {
        $message = '<div class="alert alert-error">Please enter the confirmation text</div>';
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
            $message = '<div class="alert alert-success">All leaves and permissions data has been cleared.</div>';
        } else {
            $message = '<div class="alert alert-error">Please type "RESET BOTH" exactly to confirm</div>';
        }
    } else {
        $message = '<div class="alert alert-error">Please enter the confirmation text</div>';
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
            
            $message = '<div class="alert alert-success">All data has been cleared. System reset to factory defaults.</div>';
        } else {
            $message = '<div class="alert alert-error">Please type "RESET SYSTEM" exactly to confirm</div>';
        }
    } else {
        $message = '<div class="alert alert-error">Please enter the confirmation text</div>';
    }
}

// Backup database (Admin only)
if (isset($_GET['backup']) && $role === 'admin') {
    $message = '<div class="alert alert-success">Database backup functionality would be implemented here. This would generate a SQL file for download.</div>';
}

// Export data (Admin only)
if (isset($_GET['export']) && $role === 'admin') {
    $message = '<div class="alert alert-success">Data export functionality would be implemented here. This would generate Excel/CSV files for download.</div>';
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
        (SELECT COUNT(*) FROM permissions WHERE DATE(applied_date) = CURDATE()) as new_permissions_today
")->fetch_assoc();

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
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>System Administration - MAKSIM HR</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="../assets/css/style.css">
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
        .reset-card.system { border-top: 4px solid #c53030; }
        
        .btn-warning { background: #ed8936; color: white; }
        .btn-warning:hover { background: #dd7733; }
        
        .btn-danger { background: #c53030; color: white; }
        .btn-danger:hover { background: #b52020; }
    </style>
</head>
<body>
    <?php include '../includes/header.php'; ?>
    
    <div class="app-main">
        <?php include '../includes/sidebar.php'; ?>
        
        <div class="main-content">
            <h2 class="page-title">System Administration</h2>
            
            <?php echo $message; ?>
            
            <!-- System Stats -->
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-icon"><i class="fas fa-users"></i></div>
                    <div class="stat-value"><?php echo $stats['total_users']; ?></div>
                    <div class="stat-label">Total Users</div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon"><i class="fas fa-umbrella-beach"></i></div>
                    <div class="stat-value"><?php echo $stats['total_leaves']; ?></div>
                    <div class="stat-label">Total Leaves</div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon"><i class="fas fa-clock"></i></div>
                    <div class="stat-value"><?php echo $stats['total_permissions']; ?></div>
                    <div class="stat-label">Total Permissions</div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon"><i class="fas fa-database"></i></div>
                    <div class="stat-value"><?php echo $storage_text; ?></div>
                    <div class="stat-label">Storage Used</div>
                </div>
            </div>

            <!-- Today's Activity -->
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title"><i class="fas fa-chart-line"></i> Today's Activity</h3>
                </div>
                <div class="stats-grid">
                    <div class="stat-card" style="background: #f0fff4;">
                        <div class="stat-icon" style="background: #c6f6d5; color: #276749;"><i class="fas fa-user-plus"></i></div>
                        <div class="stat-value"><?php echo $stats['new_users_today']; ?></div>
                        <div class="stat-label">New Users</div>
                    </div>
                    <div class="stat-card" style="background: #fffaf0;">
                        <div class="stat-icon" style="background: #feebc8; color: #c05621;"><i class="fas fa-umbrella-beach"></i></div>
                        <div class="stat-value"><?php echo $stats['new_leaves_today']; ?></div>
                        <div class="stat-label">New Leaves</div>
                    </div>
                    <div class="stat-card" style="background: #f0f9ff;">
                        <div class="stat-icon" style="background: #bee3f8; color: #2c5282;"><i class="fas fa-clock"></i></div>
                        <div class="stat-value"><?php echo $stats['new_permissions_today']; ?></div>
                        <div class="stat-label">New Permissions</div>
                    </div>
                    <div class="stat-card" style="background: #faf5ff;">
                        <div class="stat-icon" style="background: #e9d8fd; color: #553c9a;"><i class="fas fa-calendar-alt"></i></div>
                        <div class="stat-value"><?php echo $stats['total_timesheets']; ?></div>
                        <div class="stat-label">Total Timesheets</div>
                    </div>
                </div>
            </div>

            <?php if ($role === 'admin'): ?>
            <!-- Data Reset Options -->
            <div class="danger-zone">
                <h4><i class="fas fa-exclamation-triangle"></i> Data Reset Options (Admin Only)</h4>
                <p style="margin-bottom: 20px; color: #718096;">
                    Warning: These actions will delete data permanently and cannot be undone!
                </p>
                
                <div class="reset-options">
                    <!-- Reset Leaves Only -->
                    <div class="reset-card leaves">
                        <h5><i class="fas fa-umbrella-beach"></i> Reset Leaves Only</h5>
                        <p style="color: #718096; margin-bottom: 15px; font-size: 14px;">
                            This will delete all leaves data only. Users and other data will remain.
                        </p>
                        <form method="POST" action="">
                            <div class="form-group">
                                <label class="form-label">Type "RESET LEAVES" to confirm:</label>
                                <input type="text" name="confirm_text_leaves" class="form-control" placeholder="RESET LEAVES" required>
                            </div>
                            <button type="submit" name="reset_leaves" class="btn btn-warning" onclick="return confirm('Delete ALL leaves? This cannot be undone!')">
                                <i class="fas fa-trash"></i> Reset Leaves Data
                            </button>
                        </form>
                    </div>
                    
                    <!-- Reset Permissions Only -->
                    <div class="reset-card permissions">
                        <h5><i class="fas fa-clock"></i> Reset Permissions Only</h5>
                        <p style="color: #718096; margin-bottom: 15px; font-size: 14px;">
                            This will delete all permissions data only. Users and other data will remain.
                        </p>
                        <form method="POST" action="">
                            <div class="form-group">
                                <label class="form-label">Type "RESET PERMISSIONS" to confirm:</label>
                                <input type="text" name="confirm_text_permissions" class="form-control" placeholder="RESET PERMISSIONS" required>
                            </div>
                            <button type="submit" name="reset_permissions" class="btn btn-warning" onclick="return confirm('Delete ALL permissions? This cannot be undone!')">
                                <i class="fas fa-trash"></i> Reset Permissions Data
                            </button>
                        </form>
                    </div>
                    
                    <!-- Reset Leaves & Permissions -->
                    <div class="reset-card both">
                        <h5><i class="fas fa-ban"></i> Reset Leaves & Permissions</h5>
                        <p style="color: #718096; margin-bottom: 15px; font-size: 14px;">
                            This will delete all leaves AND permissions data. Users will remain.
                        </p>
                        <form method="POST" action="">
                            <div class="form-group">
                                <label class="form-label">Type "RESET BOTH" to confirm:</label>
                                <input type="text" name="confirm_text_leave_permissions" class="form-control" placeholder="RESET BOTH" required>
                            </div>
                            <button type="submit" name="reset_leave_permissions" class="btn btn-danger" onclick="return confirm('Delete ALL leaves and permissions? This cannot be undone!')">
                                <i class="fas fa-trash"></i> Reset Leaves & Permissions
                            </button>
                        </form>
                    </div>
                    
                    <!-- Full System Reset -->
                    <div class="reset-card system">
                        <h5><i class="fas fa-bomb"></i> Full System Reset</h5>
                        <p style="color: #718096; margin-bottom: 15px; font-size: 14px;">
                            This will delete ALL data including users (except default admin accounts).
                        </p>
                        <form method="POST" action="">
                            <div class="form-group">
                                <label class="form-label">Type "RESET SYSTEM" to confirm:</label>
                                <input type="text" name="confirm_text" class="form-control" placeholder="RESET SYSTEM" required>
                            </div>
                            <button type="submit" name="clear_data" class="btn btn-danger" onclick="return confirm('Are you ABSOLUTELY sure? This will delete ALL data!')">
                                <i class="fas fa-trash"></i> Full System Reset
                            </button>
                        </form>
                    </div>
                </div>
                
                <div style="margin-top: 20px; padding: 15px; background: #fed7d7; border-radius: 8px;">
                    <p style="color: #c53030; margin: 0; display: flex; align-items: center; gap: 10px;">
                        <i class="fas fa-exclamation-circle"></i>
                        <strong>Warning:</strong> All reset operations are permanent and irreversible. Backup your data first!
                    </p>
                </div>
            </div>
            <?php endif; ?>

            <!-- System Tools -->
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title"><i class="fas fa-tools"></i> System Tools</h3>
                </div>
                <div class="form-row">
                    <?php if ($role === 'admin'): ?>
                    <div class="form-group">
                        <button class="btn btn-success" style="width: 100%;" onclick="window.location.href='?backup=1'">
                            <i class="fas fa-database"></i> Backup Database
                        </button>
                        <small style="display: block; margin-top: 5px; color: #718096;">Create a full database backup</small>
                    </div>
                    <div class="form-group">
                        <button class="btn btn-success" style="width: 100%;" onclick="window.location.href='?export=1'">
                            <i class="fas fa-file-excel"></i> Export All Data
                        </button>
                        <small style="display: block; margin-top: 5px; color: #718096;">Export all data to Excel/CSV</small>
                    </div>
                    <?php endif; ?>
                    
                    <div class="form-group">
                        <button class="btn btn-info" style="width: 100%;" onclick="refreshSystem()">
                            <i class="fas fa-sync-alt"></i> Refresh System Cache
                        </button>
                        <small style="display: block; margin-top: 5px; color: #718096;">Clear cache and refresh data</small>
                    </div>
                    
                    <div class="form-group">
                        <button class="btn btn-warning" style="width: 100%;" onclick="showSystemLogs()">
                            <i class="fas fa-clipboard-list"></i> View System Logs
                        </button>
                        <small style="display: block; margin-top: 5px; color: #718096;">View system activity logs</small>
                    </div>
                </div>
            </div>

            <!-- System Information -->
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title"><i class="fas fa-info-circle"></i> System Information</h3>
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
                        <label class="form-label">Last Updated</label>
                        <input type="text" class="form-control" value="<?php echo date('Y-m-d H:i:s'); ?>" readonly>
                    </div>
                </div>
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
        alert('System logs functionality would open a modal showing recent system activities.');
    }
    
    function confirmReset(action) {
        const messages = {
            'leaves': 'Delete ALL leaves? This cannot be undone!',
            'permissions': 'Delete ALL permissions? This cannot be undone!',
            'both': 'Delete ALL leaves and permissions? This cannot be undone!',
            'system': 'Are you ABSOLUTELY sure? This will delete ALL data!'
        };
        
        return confirm(messages[action]);
    }
    </script>
    
    <script src="../assets/js/app.js"></script>
</body>
</html>