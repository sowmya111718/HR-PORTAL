<?php
require_once '../config/db.php';
require_once '../includes/icon_functions.php'; // ADDED
checkRole(['admin', 'hr', 'pm', 'coo', 'ed']);

$user_id = $_SESSION['user_id'];
$role = $_SESSION['role'];
$message = '';

// Get all users for dropdown
$users_result = $conn->query("SELECT id, username, full_name FROM users ORDER BY full_name");
$users = [];
if ($users_result) {
    while ($row = $users_result->fetch_assoc()) {
        $users[] = $row;
    }
}

// Reset Leaves Only (Admin only) - For specific user
if (isset($_POST['reset_leaves']) && $role === 'admin') {
    // Check if the confirm text field exists and is not empty
    if (isset($_POST['confirm_text_leaves']) && !empty($_POST['confirm_text_leaves'])) {
        $confirm_text = sanitize($_POST['confirm_text_leaves']);
        $selected_user_id = isset($_POST['user_id_leaves']) ? intval($_POST['user_id_leaves']) : 0;
        
        if ($confirm_text === 'RESET LEAVES') {
            if ($selected_user_id > 0) {
                // Reset leaves for specific user
                $stmt = $conn->prepare("DELETE FROM leaves WHERE user_id = ?");
                $stmt->bind_param("i", $selected_user_id);
                $stmt->execute();
                $affected = $stmt->affected_rows;
                $stmt->close();
                
                // Get user name for message
                $user_name = '';
                foreach ($users as $u) {
                    if ($u['id'] == $selected_user_id) {
                        $user_name = $u['full_name'];
                        break;
                    }
                }
                
                $message = '<div class="alert alert-success"><i class="icon-success"></i> All leaves data for ' . htmlspecialchars($user_name) . ' has been cleared. (' . $affected . ' records deleted)</div>';
            } else {
                // Reset leaves for all users
                $conn->query("DELETE FROM leaves");
                $message = '<div class="alert alert-success"><i class="icon-success"></i> All leaves data for ALL users has been cleared.</div>';
            }
        } else {
            $message = '<div class="alert alert-error"><i class="icon-error"></i> Please type "RESET LEAVES" exactly to confirm</div>';
        }
    } else {
        $message = '<div class="alert alert-error"><i class="icon-error"></i> Please enter the confirmation text</div>';
    }
}

// Reset Permissions Only (Admin only) - For specific user
if (isset($_POST['reset_permissions']) && $role === 'admin') {
    // Check if the confirm text field exists and is not empty
    if (isset($_POST['confirm_text_permissions']) && !empty($_POST['confirm_text_permissions'])) {
        $confirm_text = sanitize($_POST['confirm_text_permissions']);
        $selected_user_id = isset($_POST['user_id_permissions']) ? intval($_POST['user_id_permissions']) : 0;
        
        if ($confirm_text === 'RESET PERMISSIONS') {
            if ($selected_user_id > 0) {
                // Reset permissions for specific user
                $stmt = $conn->prepare("DELETE FROM permissions WHERE user_id = ?");
                $stmt->bind_param("i", $selected_user_id);
                $stmt->execute();
                $affected = $stmt->affected_rows;
                $stmt->close();
                
                // Get user name for message
                $user_name = '';
                foreach ($users as $u) {
                    if ($u['id'] == $selected_user_id) {
                        $user_name = $u['full_name'];
                        break;
                    }
                }
                
                $message = '<div class="alert alert-success"><i class="icon-success"></i> All permissions data for ' . htmlspecialchars($user_name) . ' has been cleared. (' . $affected . ' records deleted)</div>';
            } else {
                // Reset permissions for all users
                $conn->query("DELETE FROM permissions");
                $message = '<div class="alert alert-success"><i class="icon-success"></i> All permissions data for ALL users has been cleared.</div>';
            }
        } else {
            $message = '<div class="alert alert-error"><i class="icon-error"></i> Please type "RESET PERMISSIONS" exactly to confirm</div>';
        }
    } else {
        $message = '<div class="alert alert-error"><i class="icon-error"></i> Please enter the confirmation text</div>';
    }
}

// Reset Leaves & Permissions Only (Admin only) - For specific user
if (isset($_POST['reset_leave_permissions']) && $role === 'admin') {
    // Check if the confirm text field exists and is not empty
    if (isset($_POST['confirm_text_leave_permissions']) && !empty($_POST['confirm_text_leave_permissions'])) {
        $confirm_text = sanitize($_POST['confirm_text_leave_permissions']);
        $selected_user_id = isset($_POST['user_id_both']) ? intval($_POST['user_id_both']) : 0;
        
        if ($confirm_text === 'RESET BOTH') {
            if ($selected_user_id > 0) {
                // Reset both for specific user
                $stmt1 = $conn->prepare("DELETE FROM leaves WHERE user_id = ?");
                $stmt1->bind_param("i", $selected_user_id);
                $stmt1->execute();
                $affected1 = $stmt1->affected_rows;
                $stmt1->close();
                
                $stmt2 = $conn->prepare("DELETE FROM permissions WHERE user_id = ?");
                $stmt2->bind_param("i", $selected_user_id);
                $stmt2->execute();
                $affected2 = $stmt2->affected_rows;
                $stmt2->close();
                
                // Get user name for message
                $user_name = '';
                foreach ($users as $u) {
                    if ($u['id'] == $selected_user_id) {
                        $user_name = $u['full_name'];
                        break;
                    }
                }
                
                $message = '<div class="alert alert-success"><i class="icon-success"></i> All leaves and permissions data for ' . htmlspecialchars($user_name) . ' has been cleared. (Leaves: ' . $affected1 . ', Permissions: ' . $affected2 . ')</div>';
            } else {
                // Reset both for all users
                $conn->query("DELETE FROM leaves");
                $conn->query("DELETE FROM permissions");
                $message = '<div class="alert alert-success"><i class="icon-success"></i> All leaves and permissions data for ALL users has been cleared.</div>';
            }
        } else {
            $message = '<div class="alert alert-error"><i class="icon-error"></i> Please type "RESET BOTH" exactly to confirm</div>';
        }
    } else {
        $message = '<div class="alert alert-error"><i class="icon-error"></i> Please enter the confirmation text</div>';
    }
}

// Reset Timesheet for All Users (Admin only) - For specific user
if (isset($_POST['reset_timesheet_all']) && $role === 'admin') {
    // Check if the confirm text field exists and is not empty
    if (isset($_POST['confirm_text_timesheet']) && !empty($_POST['confirm_text_timesheet'])) {
        $confirm_text = sanitize($_POST['confirm_text_timesheet']);
        $selected_user_id = isset($_POST['user_id_timesheet']) ? intval($_POST['user_id_timesheet']) : 0;
        
        if ($confirm_text === 'RESET TIMESHEET') {
            if ($selected_user_id > 0) {
                // Reset timesheet for specific user
                $stmt = $conn->prepare("DELETE FROM timesheets WHERE user_id = ?");
                $stmt->bind_param("i", $selected_user_id);
                $stmt->execute();
                $affected = $stmt->affected_rows;
                $stmt->close();
                
                // Get user name for message
                $user_name = '';
                foreach ($users as $u) {
                    if ($u['id'] == $selected_user_id) {
                        $user_name = $u['full_name'];
                        break;
                    }
                }
                
                $message = '<div class="alert alert-success"><i class="icon-success"></i> All timesheet data for ' . htmlspecialchars($user_name) . ' has been cleared. (' . $affected . ' records deleted)</div>';
            } else {
                // Reset timesheet for all users
                $conn->query("DELETE FROM timesheets");
                $message = '<div class="alert alert-success"><i class="icon-success"></i> All timesheet data for ALL users has been cleared.</div>';
            }
        } else {
            $message = '<div class="alert alert-error"><i class="icon-error"></i> Please type "RESET TIMESHEET" exactly to confirm</div>';
        }
    } else {
        $message = '<div class="alert alert-error"><i class="icon-error"></i> Please enter the confirmation text</div>';
    }
}

// Clear all data (Admin only) - Can be for specific user or all
if (isset($_POST['clear_data']) && $role === 'admin') {
    // Check if the confirm text field exists and is not empty
    if (isset($_POST['confirm_text']) && !empty($_POST['confirm_text'])) {
        $confirm_text = sanitize($_POST['confirm_text']);
        $selected_user_id = isset($_POST['user_id_system']) ? intval($_POST['user_id_system']) : 0;
        
        if ($confirm_text === 'RESET SYSTEM') {
            if ($selected_user_id > 0) {
                // Reset for specific user (keep user account)
                $stmt1 = $conn->prepare("DELETE FROM leaves WHERE user_id = ?");
                $stmt1->bind_param("i", $selected_user_id);
                $stmt1->execute();
                $leaves_affected = $stmt1->affected_rows;
                $stmt1->close();
                
                $stmt2 = $conn->prepare("DELETE FROM permissions WHERE user_id = ?");
                $stmt2->bind_param("i", $selected_user_id);
                $stmt2->execute();
                $perms_affected = $stmt2->affected_rows;
                $stmt2->close();
                
                $stmt3 = $conn->prepare("DELETE FROM timesheets WHERE user_id = ?");
                $stmt3->bind_param("i", $selected_user_id);
                $stmt3->execute();
                $timesheet_affected = $stmt3->affected_rows;
                $stmt3->close();
                
                $stmt4 = $conn->prepare("DELETE FROM attendance WHERE user_id = ?");
                $stmt4->bind_param("i", $selected_user_id);
                $stmt4->execute();
                $attendance_affected = $stmt4->affected_rows;
                $stmt4->close();
                
                // Get user name for message
                $user_name = '';
                foreach ($users as $u) {
                    if ($u['id'] == $selected_user_id) {
                        $user_name = $u['full_name'];
                        break;
                    }
                }
                
                $message = '<div class="alert alert-success"><i class="icon-success"></i> All data for ' . htmlspecialchars($user_name) . ' has been cleared. (Leaves: ' . $leaves_affected . ', Permissions: ' . $perms_affected . ', Timesheets: ' . $timesheet_affected . ', Attendance: ' . $attendance_affected . ')</div>';
            } else {
                // Store current user info before clearing
                $current_user_id = $user_id;
                $current_username = $_SESSION['username'];
                
                // Clear all tables (except default admin)
                $conn->query("DELETE FROM leaves");
                $conn->query("DELETE FROM permissions");
                $conn->query("DELETE FROM timesheets");
                $conn->query("DELETE FROM attendance");
                $conn->query("DELETE FROM notifications");
                $conn->query("DELETE FROM casual_leave_carryforward");
                $conn->query("DELETE FROM holidays");
                $conn->query("DELETE FROM projects");
                $conn->query("DELETE FROM tasks");
                $conn->query("DELETE FROM system_logs");
                $conn->query("DELETE FROM users WHERE username NOT IN ('admin', 'hr', 'projectmanager')");
                
                // Reset default users passwords
                $default_password = password_hash('password', PASSWORD_DEFAULT);
                $conn->query("UPDATE users SET password = '$default_password' WHERE username IN ('admin', 'hr', 'projectmanager')");
                
                $message = '<div class="alert alert-success"><i class="icon-success"></i> All data has been cleared. System reset to factory defaults.</div>';
            }
        } else {
            $message = '<div class="alert alert-error"><i class="icon-error"></i> Please type "RESET SYSTEM" exactly to confirm</div>';
        }
    } else {
        $message = '<div class="alert alert-error"><i class="icon-error"></i> Please enter the confirmation text</div>';
    }
}

// Backup database (Admin only)
if (isset($_GET['backup']) && $role === 'admin') {
    // Generate database backup
    $tables = array();
    $result = $conn->query("SHOW TABLES");
    while ($row = $result->fetch_row()) {
        $tables[] = $row[0];
    }

    $backup_content = "-- MAKSIM HR Database Backup\n";
    $backup_content .= "-- Generated on: " . date('Y-m-d H:i:s') . "\n";
    $backup_content .= "-- Generated by: " . $_SESSION['username'] . "\n\n";
    
    foreach ($tables as $table) {
        $result = $conn->query("SELECT * FROM $table");
        $num_fields = $result->field_count;
        
        $backup_content .= "-- Table structure for table `$table`\n";
        $backup_content .= "DROP TABLE IF EXISTS `$table`;\n";
        $row2 = $conn->query("SHOW CREATE TABLE $table")->fetch_row();
        $backup_content .= $row2[1] . ";\n\n";
        
        $backup_content .= "-- Dumping data for table `$table`\n";
        for ($i = 0; $i < $num_fields; $i++) {
            while ($row = $result->fetch_row()) {
                $backup_content .= "INSERT INTO `$table` VALUES(";
                for ($j = 0; $j < $num_fields; $j++) {
                    $row[$j] = addslashes($row[$j]);
                    $row[$j] = str_replace("\n", "\\n", $row[$j]);
                    if (isset($row[$j])) {
                        $backup_content .= '"' . $row[$j] . '"';
                    } else {
                        $backup_content .= '""';
                    }
                    if ($j < ($num_fields - 1)) {
                        $backup_content .= ',';
                    }
                }
                $backup_content .= ");\n";
            }
        }
        $backup_content .= "\n\n";
    }

    $backup_file_name = 'maksim_hr_backup_' . date('Y-m-d_H-i-s') . '.sql';
    
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="' . $backup_file_name . '"');
    header('Content-Length: ' . strlen($backup_content));
    header('Cache-Control: must-revalidate, post-check=0, pre-check=0');
    header('Cache-Control: private', false);
    
    echo $backup_content;
    exit();
}

// Export data (Admin only)
if (isset($_GET['export']) && $role === 'admin') {
    // Export all data to CSV with proper formatting
    $tables = [
        'users' => 'SELECT id, username, full_name, email, role, department, position, reporting_to, join_date, birthday, status, created_at FROM users ORDER BY id',
        'leaves' => 'SELECT l.*, u.full_name as user_name FROM leaves l LEFT JOIN users u ON l.user_id = u.id ORDER BY l.id',
        'permissions' => 'SELECT p.*, u.full_name as user_name FROM permissions p LEFT JOIN users u ON p.user_id = u.id ORDER BY p.id',
        'timesheets' => 'SELECT t.*, u.full_name as user_name FROM timesheets t LEFT JOIN users u ON t.user_id = u.id ORDER BY t.id',
        'attendance' => 'SELECT a.*, u.full_name as user_name FROM attendance a LEFT JOIN users u ON a.user_id = u.id ORDER BY a.id',
        'notifications' => 'SELECT n.*, u.full_name as user_name FROM notifications n LEFT JOIN users u ON n.user_id = u.id ORDER BY n.id',
        'casual_leave_carryforward' => 'SELECT c.*, u.full_name as user_name FROM casual_leave_carryforward c LEFT JOIN users u ON c.user_id = u.id ORDER BY c.id',
        'holidays' => 'SELECT * FROM holidays ORDER BY holiday_date',
        'projects' => 'SELECT * FROM projects ORDER BY id',
        'tasks' => 'SELECT * FROM tasks ORDER BY id',
        'system_logs' => 'SELECT l.*, u.full_name as user_name FROM system_logs l LEFT JOIN users u ON l.user_id = u.id ORDER BY l.id'
    ];
    
    $zip = new ZipArchive();
    $zip_filename = 'maksim_hr_export_' . date('Y-m-d_H-i-s') . '.zip';
    $zip_path = sys_get_temp_dir() . '/' . $zip_filename;
    
    if ($zip->open($zip_path, ZipArchive::CREATE) !== TRUE) {
        $message = '<div class="alert alert-error"><i class="icon-error"></i> Cannot create zip file</div>';
    } else {
        foreach ($tables as $table_name => $query) {
            $result = $conn->query($query);
            if ($result && $result->num_rows > 0) {
                $fp = fopen('php://temp', 'w');
                
                // Add UTF-8 BOM for Excel compatibility
                fprintf($fp, chr(0xEF).chr(0xBB).chr(0xBF));
                
                // Get field names for headers
                $fields = $result->fetch_fields();
                $headers = array();
                foreach ($fields as $field) {
                    $headers[] = $field->name;
                }
                fputcsv($fp, $headers);
                
                // Add data rows
                while ($row = $result->fetch_assoc()) {
                    // Format dates properly to prevent Excel from showing #####
                    foreach ($row as $key => $value) {
                        // Check if this is a date field
                        if ($value !== null && $value != '0000-00-00' && $value != '0000-00-00 00:00:00') {
                            if (strpos($key, 'date') !== false || strpos($key, 'Date') !== false || 
                                strpos($key, 'created_at') !== false || strpos($key, 'updated_at') !== false ||
                                strpos($key, 'applied_date') !== false || strpos($key, 'approved_date') !== false ||
                                strpos($key, 'rejected_date') !== false || strpos($key, 'from_date') !== false ||
                                strpos($key, 'to_date') !== false || strpos($key, 'entry_date') !== false ||
                                strpos($key, 'submitted_date') !== false || strpos($key, 'permission_date') !== false ||
                                strpos($key, 'holiday_date') !== false || strpos($key, 'join_date') !== false) {
                                
                                $timestamp = strtotime($value);
                                if ($timestamp !== false && $timestamp > 0) {
                                    // Format as text with quotes to prevent Excel conversion
                                    $row[$key] = '="' . date('Y-m-d', $timestamp) . '"';
                                }
                            }
                        } else {
                            $row[$key] = '';
                        }
                    }
                    fputcsv($fp, $row);
                }
                
                rewind($fp);
                $csv_content = stream_get_contents($fp);
                fclose($fp);
                
                $zip->addFromString($table_name . '.csv', $csv_content);
            }
        }
        
        // Add README file with export information
        $readme = "MAKSIM HR DATA EXPORT\n";
        $readme .= "=======================\n\n";
        $readme .= "Export Date: " . date('Y-m-d H:i:s') . "\n";
        $readme .= "Generated by: " . $_SESSION['username'] . "\n\n";
        $readme .= "File Format: CSV (Comma Separated Values)\n";
        $readme .= "Encoding: UTF-8 with BOM (for Excel compatibility)\n\n";
        $readme .= "Date Format: YYYY-MM-DD (formatted as text to prevent Excel conversion)\n";
        $readme .= "In Excel, dates appear as =\"YYYY-MM-DD\" to avoid ##### display\n\n";
        $readme .= "Included Tables:\n";
        foreach (array_keys($tables) as $table) {
            $readme .= "- " . ucfirst(str_replace('_', ' ', $table)) . "\n";
        }
        
        $zip->addFromString('README.txt', $readme);
        
        // Add summary statistics
        $stats = $conn->query("
            SELECT 
                (SELECT COUNT(*) FROM users) as users,
                (SELECT COUNT(*) FROM leaves) as leaves,
                (SELECT COUNT(*) FROM permissions) as permissions,
                (SELECT COUNT(*) FROM timesheets) as timesheets,
                (SELECT COUNT(*) FROM attendance) as attendance,
                (SELECT COUNT(*) FROM notifications) as notifications,
                (SELECT COUNT(*) FROM casual_leave_carryforward) as carryforward,
                (SELECT COUNT(*) FROM holidays) as holidays,
                (SELECT COUNT(*) FROM projects) as projects,
                (SELECT COUNT(*) FROM tasks) as tasks,
                (SELECT COUNT(*) FROM system_logs) as system_logs
        ")->fetch_assoc();
        
        $summary = "EXPORT SUMMARY\n";
        $summary .= "===============\n\n";
        $summary .= "Total Records:\n";
        foreach ($stats as $key => $value) {
            $summary .= "- " . ucfirst(str_replace('_', ' ', $key)) . ": " . number_format($value) . "\n";
        }
        
        $zip->addFromString('summary.txt', $summary);
        $zip->close();
        
        // Download the zip file
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="' . $zip_filename . '"');
        header('Content-Length: ' . filesize($zip_path));
        header('Cache-Control: must-revalidate, post-check=0, pre-check=0');
        header('Cache-Control: private', false);
        header('Pragma: public');
        
        readfile($zip_path);
        unlink($zip_path);
        exit();
    }
}

// View System Logs
if (isset($_GET['view_logs'])) {
    // Create logs modal content
    $log_type = isset($_GET['log_type']) ? $_GET['log_type'] : 'all';
    $log_limit = isset($_GET['log_limit']) ? intval($_GET['log_limit']) : 50;
    
    // Check if system_logs table exists
    $table_check = $conn->query("SHOW TABLES LIKE 'system_logs'");
    $logs_table_exists = $table_check && $table_check->num_rows > 0;
    
    $logs = [];
    $total_logs = 0;
    
    if ($logs_table_exists) {
        // Get total count
        $count_query = "SELECT COUNT(*) as total FROM system_logs";
        if ($log_type !== 'all') {
            $count_query .= " WHERE event_type = '" . $conn->real_escape_string($log_type) . "'";
        }
        $count_result = $conn->query($count_query);
        if ($count_result) {
            $total_logs = $count_result->fetch_assoc()['total'];
        }
        
        // Get logs with user details
        $query = "
            SELECT l.*, u.full_name, u.username 
            FROM system_logs l
            LEFT JOIN users u ON l.user_id = u.id
        ";
        
        if ($log_type !== 'all') {
            $query .= " WHERE l.event_type = '" . $conn->real_escape_string($log_type) . "'";
        }
        
        $query .= " ORDER BY l.created_at DESC LIMIT ?";
        
        $stmt = $conn->prepare($query);
        $stmt->bind_param("i", $log_limit);
        $stmt->execute();
        $result = $stmt->get_result();
        $logs = $result->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
    }
    
    // Get unique event types for filter
    $event_types = [];
    if ($logs_table_exists) {
        $types_result = $conn->query("SELECT DISTINCT event_type FROM system_logs ORDER BY event_type");
        if ($types_result) {
            while ($row = $types_result->fetch_assoc()) {
                $event_types[] = $row['event_type'];
            }
        }
    }
    
    // Return JSON for AJAX request
    if (isset($_GET['ajax'])) {
        header('Content-Type: application/json');
        echo json_encode([
            'success' => true,
            'logs' => $logs,
            'total_logs' => $total_logs,
            'event_types' => $event_types,
            'logs_table_exists' => $logs_table_exists
        ]);
        exit();
    }
}

// Get system statistics with all tables
$stats = $conn->query("
    SELECT 
        (SELECT COUNT(*) FROM users) as total_users,
        (SELECT COUNT(*) FROM leaves) as total_leaves,
        (SELECT COUNT(*) FROM permissions) as total_permissions,
        (SELECT COUNT(*) FROM timesheets) as total_timesheets,
        (SELECT COUNT(*) FROM attendance) as total_attendance,
        (SELECT COUNT(*) FROM notifications) as total_notifications,
        (SELECT COUNT(*) FROM casual_leave_carryforward) as total_carryforward,
        (SELECT COUNT(*) FROM holidays) as total_holidays,
        (SELECT COUNT(*) FROM projects) as total_projects,
        (SELECT COUNT(*) FROM tasks) as total_tasks,
        (SELECT COUNT(*) FROM system_logs) as total_system_logs,
        (SELECT COUNT(*) FROM users WHERE DATE(created_at) = CURDATE()) as new_users_today,
        (SELECT COUNT(*) FROM leaves WHERE DATE(applied_date) = CURDATE()) as new_leaves_today,
        (SELECT COUNT(*) FROM permissions WHERE DATE(applied_date) = CURDATE()) as new_permissions_today,
        (SELECT COUNT(*) FROM timesheets WHERE DATE(submitted_date) = CURDATE()) as new_timesheets_today
")->fetch_assoc();

// Calculate total records for system data including all tables
$total_records = 
    $stats['total_users'] + 
    $stats['total_leaves'] + 
    $stats['total_permissions'] + 
    $stats['total_timesheets'] + 
    $stats['total_attendance'] +
    $stats['total_notifications'] +
    $stats['total_carryforward'] +
    $stats['total_holidays'] +
    $stats['total_projects'] +
    $stats['total_tasks'] +
    $stats['total_system_logs'];

// Calculate storage usage (approximate) for all tables
$storage_estimate = 
    ($stats['total_users'] * 1024) + 
    ($stats['total_leaves'] * 512) + 
    ($stats['total_permissions'] * 256) + 
    ($stats['total_timesheets'] * 1024) + 
    ($stats['total_attendance'] * 128) +
    ($stats['total_notifications'] * 256) +
    ($stats['total_carryforward'] * 128) +
    ($stats['total_holidays'] * 128) +
    ($stats['total_projects'] * 512) +
    ($stats['total_tasks'] * 256) +
    ($stats['total_system_logs'] * 512);

if ($storage_estimate < 1024) {
    $storage_text = $storage_estimate . ' bytes';
} elseif ($storage_estimate < 1024 * 1024) {
    $storage_text = round($storage_estimate / 1024, 2) . ' KB';
} else {
    $storage_text = round($storage_estimate / (1024 * 1024), 2) . ' MB';
}

$page_title = "System Administration - MAKSIM HR";
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>System Administration - MAKSIM HR</title>
    <?php include '../includes/head.php'; ?>
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
            grid-template-columns: repeat(auto-fit, minmax(350px, 1fr));
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
        .reset-card.timesheet { border-top: 4px solid #9f7aea; }
        .reset-card.system { border-top: 4px solid #c53030; }
        
        .btn-warning { background: #ed8936; color: white; }
        .btn-warning:hover { background: #dd7733; }
        
        .btn-danger { background: #c53030; color: white; }
        .btn-danger:hover { background: #b52020; }
        
        .btn-purple { background: #9f7aea; color: white; }
        .btn-purple:hover { background: #8b5cf6; }
        
        /* System Data Cards */
        .system-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 20px;
            margin-bottom: 30px;
        }
        
        .system-card {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 20px;
            border-radius: 12px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.1);
        }
        
        .system-card.storage {
            background: linear-gradient(135deg, #48bb78 0%, #38a169 100%);
        }
        
        .system-header {
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 14px;
            opacity: 0.9;
            margin-bottom: 15px;
        }
        
        .system-header i {
            font-size: 18px;
        }
        
        .system-value {
            font-size: 32px;
            font-weight: bold;
            margin-bottom: 5px;
        }
        
        .system-label {
            font-size: 13px;
            opacity: 0.8;
            margin-bottom: 15px;
        }
        
        .system-breakdown {
            display: flex;
            flex-wrap: wrap;
            gap: 15px;
            margin-top: 15px;
            padding-top: 15px;
            border-top: 1px solid rgba(255,255,255,0.2);
        }
        
        .breakdown-item {
            display: flex;
            flex-direction: column;
            min-width: 70px;
        }
        
        .breakdown-label {
            font-size: 11px;
            opacity: 0.7;
        }
        
        .breakdown-number {
            font-size: 16px;
            font-weight: 600;
        }
        
        /* Module Stats - Keep original layout */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 25px;
            margin-bottom: 40px;
        }
        
        .stat-card {
            background: white;
            border-radius: 15px;
            padding: 25px;
            box-shadow: 0 5px 15px rgba(0,0,0,0.05);
            transition: transform 0.3s;
        }
        
        .stat-card:hover {
            transform: translateY(-5px);
        }
        
        .stat-icon {
            width: 60px;
            height: 60px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 20px;
            font-size: 24px;
        }
        
        .stat-card:nth-child(1) .stat-icon { background: #fed7d7; color: #c53030; }
        .stat-card:nth-child(2) .stat-icon { background: #c6f6d5; color: #276749; }
        .stat-card:nth-child(3) .stat-icon { background: #bee3f8; color: #2c5282; }
        .stat-card:nth-child(4) .stat-icon { background: #e9d8fd; color: #553c9a; }
        
        .stat-value {
            font-size: 32px;
            font-weight: 700;
            color: #2d3748;
            margin-bottom: 5px;
        }
        
        .stat-label {
            color: #718096;
            font-size: 14px;
        }
        
        /* Modal styles for system logs */
        .modal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0,0,0,0.5);
            overflow: auto;
        }
        
        .modal-content {
            background-color: white;
            margin: 5% auto;
            padding: 30px;
            border-radius: 15px;
            width: 90%;
            max-width: 1200px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
            animation: slideDown 0.3s ease;
        }
        
        @keyframes slideDown {
            from {
                transform: translateY(-50px);
                opacity: 0;
            }
            to {
                transform: translateY(0);
                opacity: 1;
            }
        }
        
        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            padding-bottom: 15px;
            border-bottom: 2px solid #e2e8f0;
        }
        
        .modal-header h3 {
            display: flex;
            align-items: center;
            gap: 10px;
            color: #2d3748;
        }
        
        .modal-header h3 i {
            color: #006400;
        }
        
        .close-btn {
            background: none;
            border: none;
            font-size: 24px;
            cursor: pointer;
            color: #718096;
            transition: color 0.2s;
        }
        
        .close-btn:hover {
            color: #c53030;
        }
        
        .log-filters {
            display: flex;
            gap: 15px;
            margin-bottom: 20px;
            flex-wrap: wrap;
            align-items: center;
        }
        
        .log-filter-select {
            padding: 8px 12px;
            border: 2px solid #e2e8f0;
            border-radius: 8px;
            font-size: 14px;
            min-width: 150px;
        }
        
        .log-filter-select:focus {
            outline: none;
            border-color: #006400;
        }
        
        .log-limit-input {
            padding: 8px 12px;
            border: 2px solid #e2e8f0;
            border-radius: 8px;
            font-size: 14px;
            width: 100px;
        }
        
        .log-limit-input:focus {
            outline: none;
            border-color: #006400;
        }
        
        .refresh-btn {
            background: #4299e1;
            color: white;
            border: none;
            padding: 8px 15px;
            border-radius: 8px;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 5px;
            font-size: 14px;
            transition: background 0.2s;
        }
        
        .refresh-btn:hover {
            background: #3182ce;
        }
        
        .logs-table-container {
            overflow-x: auto;
            max-height: 500px;
            overflow-y: auto;
            border: 1px solid #e2e8f0;
            border-radius: 10px;
        }
        
        .logs-table {
            width: 100%;
            border-collapse: collapse;
        }
        
        .logs-table th {
            background: #f7fafc;
            padding: 12px;
            text-align: left;
            font-weight: 600;
            color: #4a5568;
            border-bottom: 2px solid #e2e8f0;
            position: sticky;
            top: 0;
            background: #f7fafc;
            z-index: 10;
        }
        
        .logs-table td {
            padding: 12px;
            border-bottom: 1px solid #e2e8f0;
            color: #4a5568;
            font-size: 13px;
        }
        
        .logs-table tr:hover {
            background: #f7fafc;
        }
        
        .event-badge {
            display: inline-block;
            padding: 3px 8px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
        }
        
        .event-leave { background: #4299e1; color: white; }
        .event-permission { background: #48bb78; color: white; }
        .event-user { background: #ed8936; color: white; }
        .event-system { background: #9f7aea; color: white; }
        .event-error { background: #c53030; color: white; }
        
        .log-count-badge {
            background: #006400;
            color: white;
            padding: 3px 10px;
            border-radius: 20px;
            font-size: 12px;
            margin-left: 10px;
        }
        
        .no-logs-message {
            text-align: center;
            padding: 40px;
            color: #718096;
        }
        
        .loading-spinner {
            display: inline-block;
            width: 20px;
            height: 20px;
            border: 3px solid #f3f3f3;
            border-top: 3px solid #006400;
            border-radius: 50%;
            animation: spin 1s linear infinite;
        }
        
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
        
        .log-timestamp {
            font-family: monospace;
            font-size: 12px;
            color: #718096;
        }
        
        .log-user {
            font-weight: 600;
            color: #2d3748;
        }
        
        /* User select styles */
        .user-select {
            margin-bottom: 10px;
        }
        
        .reset-note {
            font-size: 12px;
            color: #718096;
            margin-top: 5px;
            font-style: italic;
        }
    </style>
</head>
<body>
    <?php include '../includes/header.php'; ?>
    
    <div class="app-main">
        <?php include '../includes/sidebar.php'; ?>
        
        <div class="main-content">
            <h2 class="page-title"><i class="icon-settings"></i> System Administration</h2>
            
            <?php echo $message; ?>
            
            <!-- System Data Cards - Including all database tables -->
            <div class="system-grid">
                <!-- Total System Data Card -->
                <div class="system-card">
                    <div class="system-header">
                        <i class="icon-database"></i> Total System Data
                    </div>
                    <div class="system-value"><?php echo number_format($total_records); ?></div>
                    <div class="system-label">Total Records Across All Tables</div>
                    
                    <div class="system-breakdown">
                        <div class="breakdown-item">
                            <span class="breakdown-label">Users</span>
                            <span class="breakdown-number"><?php echo $stats['total_users']; ?></span>
                        </div>
                        <div class="breakdown-item">
                            <span class="breakdown-label">Leaves</span>
                            <span class="breakdown-number"><?php echo $stats['total_leaves']; ?></span>
                        </div>
                        <div class="breakdown-item">
                            <span class="breakdown-label">Permissions</span>
                            <span class="breakdown-number"><?php echo $stats['total_permissions']; ?></span>
                        </div>
                        <div class="breakdown-item">
                            <span class="breakdown-label">Timesheets</span>
                            <span class="breakdown-number"><?php echo $stats['total_timesheets']; ?></span>
                        </div>
                        <div class="breakdown-item">
                            <span class="breakdown-label">Attendance</span>
                            <span class="breakdown-number"><?php echo $stats['total_attendance']; ?></span>
                        </div>
                        <div class="breakdown-item">
                            <span class="breakdown-label">Notifications</span>
                            <span class="breakdown-number"><?php echo $stats['total_notifications']; ?></span>
                        </div>
                        <div class="breakdown-item">
                            <span class="breakdown-label">Carryforward</span>
                            <span class="breakdown-number"><?php echo $stats['total_carryforward']; ?></span>
                        </div>
                        <div class="breakdown-item">
                            <span class="breakdown-label">Holidays</span>
                            <span class="breakdown-number"><?php echo $stats['total_holidays']; ?></span>
                        </div>
                        <div class="breakdown-item">
                            <span class="breakdown-label">Projects</span>
                            <span class="breakdown-number"><?php echo $stats['total_projects']; ?></span>
                        </div>
                        <div class="breakdown-item">
                            <span class="breakdown-label">Tasks</span>
                            <span class="breakdown-number"><?php echo $stats['total_tasks']; ?></span>
                        </div>
                        <div class="breakdown-item">
                            <span class="breakdown-label">System Logs</span>
                            <span class="breakdown-number"><?php echo $stats['total_system_logs']; ?></span>
                        </div>
                    </div>
                </div>
                
                <!-- Storage Usage Card -->
                <div class="system-card storage">
                    <div class="system-header">
                        <i class="icon-hdd"></i> Storage Usage
                    </div>
                    <div class="system-value"><?php echo $storage_text; ?></div>
                    <div class="system-label">Approximate Database Size</div>
                    
                    <div class="system-breakdown">
                        <div class="breakdown-item">
                            <span class="breakdown-label">Per User</span>
                            <span class="breakdown-number">~1KB</span>
                        </div>
                        <div class="breakdown-item">
                            <span class="breakdown-label">Per Leave</span>
                            <span class="breakdown-number">~0.5KB</span>
                        </div>
                        <div class="breakdown-item">
                            <span class="breakdown-label">Per Timesheet</span>
                            <span class="breakdown-number">~1KB</span>
                        </div>
                        <div class="breakdown-item">
                            <span class="breakdown-label">Per Notification</span>
                            <span class="breakdown-number">~0.25KB</span>
                        </div>
                        <div class="breakdown-item">
                            <span class="breakdown-label">Per Project</span>
                            <span class="breakdown-number">~0.5KB</span>
                        </div>
                        <div class="breakdown-item">
                            <span class="breakdown-label">Per Task</span>
                            <span class="breakdown-number">~0.25KB</span>
                        </div>
                        <div class="breakdown-item">
                            <span class="breakdown-label">Per System Log</span>
                            <span class="breakdown-number">~0.5KB</span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Module Statistics - Keep original layout -->
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-icon"><i class="icon-users"></i></div>
                    <div class="stat-value"><?php echo $stats['total_users']; ?></div>
                    <div class="stat-label">Total Users</div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon"><i class="icon-leave"></i></div>
                    <div class="stat-value"><?php echo $stats['total_leaves']; ?></div>
                    <div class="stat-label">Total Leaves</div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon"><i class="icon-clock"></i></div>
                    <div class="stat-value"><?php echo $stats['total_permissions']; ?></div>
                    <div class="stat-label">Total Permissions</div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon"><i class="icon-attendance"></i></div>
                    <div class="stat-value"><?php echo $stats['total_attendance']; ?></div>
                    <div class="stat-label">Total Attendance</div>
                </div>
            </div>

            <!-- Today's Activity - Keep original layout -->
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title"><i class="icon-chart-line"></i> Today's Activity</h3>
                </div>
                <div class="stats-grid">
                    <div class="stat-card" style="background: #f0fff4;">
                        <div class="stat-icon" style="background: #c6f6d5; color: #276749;"><i class="icon-user-plus"></i></div>
                        <div class="stat-value"><?php echo $stats['new_users_today']; ?></div>
                        <div class="stat-label">New Users</div>
                    </div>
                    <div class="stat-card" style="background: #fffaf0;">
                        <div class="stat-icon" style="background: #feebc8; color: #c05621;"><i class="icon-leave"></i></div>
                        <div class="stat-value"><?php echo $stats['new_leaves_today']; ?></div>
                        <div class="stat-label">New Leaves</div>
                    </div>
                    <div class="stat-card" style="background: #f0f9ff;">
                        <div class="stat-icon" style="background: #bee3f8; color: #2c5282;"><i class="icon-clock"></i></div>
                        <div class="stat-value"><?php echo $stats['new_permissions_today']; ?></div>
                        <div class="stat-label">New Permissions</div>
                    </div>
                    <div class="stat-card" style="background: #faf5ff;">
                        <div class="stat-icon" style="background: #e9d8fd; color: #553c9a;"><i class="icon-calendar"></i></div>
                        <div class="stat-value"><?php echo $stats['new_timesheets_today'] ?? 0; ?></div>
                        <div class="stat-label">New Timesheets</div>
                    </div>
                </div>
            </div>

            <?php if ($role === 'admin'): ?>
            <!-- Data Reset Options -->
            <div class="danger-zone">
                <h4><i class="icon-warning"></i> Data Reset Options (Admin Only)</h4>
                <p style="margin-bottom: 20px; color: #718096;">
                    Warning: These actions will delete data permanently and cannot be undone!
                </p>
                
                <div class="reset-options">
                    <!-- Reset Leaves Only -->
                    <div class="reset-card leaves">
                        <h5><i class="icon-leave"></i> Reset Leaves Only</h5>
                        <p style="color: #718096; margin-bottom: 15px; font-size: 14px;">
                            This will delete leaves data for selected user or all users.
                        </p>
                        <form method="POST" action="">
                            <div class="form-group user-select">
                                <label class="form-label">Select User:</label>
                                <select name="user_id_leaves" class="form-control">
                                    <option value="0">All Users</option>
                                    <?php foreach ($users as $user): ?>
                                        <option value="<?php echo $user['id']; ?>"><?php echo htmlspecialchars($user['full_name']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="form-group">
                                <label class="form-label">Type "RESET LEAVES" to confirm:</label>
                                <input type="text" name="confirm_text_leaves" class="form-control" placeholder="RESET LEAVES" required>
                            </div>
                            <button type="submit" name="reset_leaves" class="btn btn-warning" onclick="return confirmReset('leaves')">
                                <i class="icon-delete"></i> Reset Leaves Data
                            </button>
                            <div class="reset-note">Records will be permanently deleted</div>
                        </form>
                    </div>
                    
                    <!-- Reset Permissions Only -->
                    <div class="reset-card permissions">
                        <h5><i class="icon-clock"></i> Reset Permissions Only</h5>
                        <p style="color: #718096; margin-bottom: 15px; font-size: 14px;">
                            This will delete permissions data for selected user or all users.
                        </p>
                        <form method="POST" action="">
                            <div class="form-group user-select">
                                <label class="form-label">Select User:</label>
                                <select name="user_id_permissions" class="form-control">
                                    <option value="0">All Users</option>
                                    <?php foreach ($users as $user): ?>
                                        <option value="<?php echo $user['id']; ?>"><?php echo htmlspecialchars($user['full_name']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="form-group">
                                <label class="form-label">Type "RESET PERMISSIONS" to confirm:</label>
                                <input type="text" name="confirm_text_permissions" class="form-control" placeholder="RESET PERMISSIONS" required>
                            </div>
                            <button type="submit" name="reset_permissions" class="btn btn-warning" onclick="return confirmReset('permissions')">
                                <i class="icon-delete"></i> Reset Permissions Data
                            </button>
                            <div class="reset-note">Records will be permanently deleted</div>
                        </form>
                    </div>
                    
                    <!-- Reset Leaves & Permissions -->
                    <div class="reset-card both">
                        <h5><i class="icon-ban"></i> Reset Leaves & Permissions</h5>
                        <p style="color: #718096; margin-bottom: 15px; font-size: 14px;">
                            This will delete leaves AND permissions data for selected user or all users.
                        </p>
                        <form method="POST" action="">
                            <div class="form-group user-select">
                                <label class="form-label">Select User:</label>
                                <select name="user_id_both" class="form-control">
                                    <option value="0">All Users</option>
                                    <?php foreach ($users as $user): ?>
                                        <option value="<?php echo $user['id']; ?>"><?php echo htmlspecialchars($user['full_name']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="form-group">
                                <label class="form-label">Type "RESET BOTH" to confirm:</label>
                                <input type="text" name="confirm_text_leave_permissions" class="form-control" placeholder="RESET BOTH" required>
                            </div>
                            <button type="submit" name="reset_leave_permissions" class="btn btn-danger" onclick="return confirmReset('both')">
                                <i class="icon-delete"></i> Reset Leaves & Permissions
                            </button>
                            <div class="reset-note">Both types of records will be permanently deleted</div>
                        </form>
                    </div>
                    
                    <!-- Reset Timesheet for All Users -->
                    <div class="reset-card timesheet">
                        <h5><i class="icon-calendar"></i> Reset Timesheet</h5>
                        <p style="color: #718096; margin-bottom: 15px; font-size: 14px;">
                            This will delete timesheet entries for selected user or all users.
                        </p>
                        <form method="POST" action="">
                            <div class="form-group user-select">
                                <label class="form-label">Select User:</label>
                                <select name="user_id_timesheet" class="form-control">
                                    <option value="0">All Users</option>
                                    <?php foreach ($users as $user): ?>
                                        <option value="<?php echo $user['id']; ?>"><?php echo htmlspecialchars($user['full_name']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="form-group">
                                <label class="form-label">Type "RESET TIMESHEET" to confirm:</label>
                                <input type="text" name="confirm_text_timesheet" class="form-control" placeholder="RESET TIMESHEET" required>
                            </div>
                            <button type="submit" name="reset_timesheet_all" class="btn btn-purple" onclick="return confirmReset('timesheet')">
                                <i class="icon-delete"></i> Reset Timesheet
                            </button>
                            <div class="reset-note">All timesheet entries will be permanently deleted</div>
                        </form>
                    </div>
                    
                    <!-- Full System Reset -->
                    <div class="reset-card system">
                        <h5><i class="icon-bomb"></i> Full System Reset</h5>
                        <p style="color: #718096; margin-bottom: 15px; font-size: 14px;">
                            Reset all data for selected user or full system reset (all users).
                        </p>
                        <form method="POST" action="">
                            <div class="form-group user-select">
                                <label class="form-label">Select User:</label>
                                <select name="user_id_system" class="form-control">
                                    <option value="0">All Users (Full System Reset)</option>
                                    <?php foreach ($users as $user): ?>
                                        <option value="<?php echo $user['id']; ?>"><?php echo htmlspecialchars($user['full_name']); ?> (User Only)</option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="form-group">
                                <label class="form-label">Type "RESET SYSTEM" to confirm:</label>
                                <input type="text" name="confirm_text" class="form-control" placeholder="RESET SYSTEM" required>
                            </div>
                            <button type="submit" name="clear_data" class="btn btn-danger" onclick="return confirmReset('system')">
                                <i class="icon-delete"></i> Reset Data
                            </button>
                            <div class="reset-note">Warning: This will permanently delete all selected data</div>
                        </form>
                    </div>
                </div>
                
                <div style="margin-top: 20px; padding: 15px; background: #fed7d7; border-radius: 8px;">
                    <p style="color: #c53030; margin: 0; display: flex; align-items: center; gap: 10px;">
                        <i class="icon-warning"></i>
                        <strong>Warning:</strong> All reset operations are permanent and irreversible. Backup your data first!
                    </p>
                </div>
            </div>
            <?php endif; ?>

            <!-- System Tools -->
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title"><i class="icon-tools"></i> System Tools</h3>
                </div>
                <div class="form-row">
                    <?php if ($role === 'admin'): ?>
                    <div class="form-group">
                        <a href="?backup=1" class="btn btn-success" style="width: 100%; display: inline-block; text-align: center; text-decoration: none;">
                            <i class="icon-database"></i> Backup Database
                        </a>
                        <small style="display: block; margin-top: 5px; color: #718096;">Create a full database backup</small>
                    </div>
                    <div class="form-group">
                        <a href="?export=1" class="btn btn-success" style="width: 100%; display: inline-block; text-align: center; text-decoration: none;">
                            <i class="icon-excel"></i> Export All Data
                        </a>
                        <small style="display: block; margin-top: 5px; color: #718096;">Export all data to Excel/CSV</small>
                    </div>
                    
                    <!-- ADDED: Manual Cleanup Link -->
                    <div class="form-group">
                        <a href="manual_cleanup.php" class="btn" style="background: #ed8936; width: 100%; display: inline-block; text-align: center; color: white; text-decoration: none; padding: 14px 20px; border-radius: 10px; font-size: 16px; font-weight: 600;">
                            <i class="icon-database"></i> Manual Data Cleanup (Admin Only)
                        </a>
                        <small style="display: block; margin-top: 5px; color: #718096;">
                            Download old data and delete from database
                        </small>
                    </div>
                    <?php endif; ?>
                    
                    <div class="form-group">
                        <button class="btn btn-info" style="width: 100%;" onclick="refreshSystem()">
                            <i class="icon-sync"></i> Refresh System Cache
                        </button>
                        <small style="display: block; margin-top: 5px; color: #718096;">Clear cache and refresh data</small>
                    </div>
                    
                    <div class="form-group">
                        <button class="btn btn-warning" style="width: 100%;" onclick="showSystemLogs()">
                            <i class="icon-clipboard-list"></i> View System Logs
                        </button>
                        <small style="display: block; margin-top: 5px; color: #718096;">View system activity logs</small>
                    </div>
                </div>
            </div>

            <!-- System Information -->
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title"><i class="icon-info"></i> System Information</h3>
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
                        <label class="form-label">Storage Used</label>
                        <input type="text" class="form-control" value="<?php echo $storage_text; ?>" readonly>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- System Logs Modal -->
    <div id="systemLogsModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>
                    <i class="icon-clipboard-list"></i>
                    System Activity Logs
                    <span id="logCountBadge" class="log-count-badge">0</span>
                </h3>
                <button class="close-btn" onclick="closeLogsModal()">&times;</button>
            </div>
            
            <div class="log-filters">
                <select id="logTypeFilter" class="log-filter-select" onchange="loadLogs()">
                    <option value="all">All Events</option>
                </select>
                
                <select id="logLimit" class="log-limit-input" onchange="loadLogs()">
                    <option value="25">25 records</option>
                    <option value="50" selected>50 records</option>
                    <option value="100">100 records</option>
                    <option value="200">200 records</option>
                    <option value="500">500 records</option>
                </select>
                
                <button class="refresh-btn" onclick="loadLogs()">
                    <i class="icon-sync"></i> Refresh
                </button>
            </div>
            
            <div id="logsContainer" class="logs-table-container">
                <div style="text-align: center; padding: 40px;">
                    <div class="loading-spinner" style="margin: 0 auto 15px;"></div>
                    <div>Loading logs...</div>
                </div>
            </div>
            
            <div style="margin-top: 20px; text-align: right; color: #718096; font-size: 12px;">
                <i class="icon-info"></i> Logs are automatically cleared after 30 days
            </div>
        </div>
    </div>

    <script>
    function refreshSystem() {
        if (confirm('Refresh system cache?')) {
            // Clear browser cache and reload
            if ('caches' in window) {
                caches.keys().then(function(names) {
                    for (let name of names) {
                        caches.delete(name);
                    }
                });
            }
            localStorage.clear();
            sessionStorage.clear();
            alert('System cache refreshed successfully. Page will reload.');
            window.location.reload(true);
        }
    }
    
    function showSystemLogs() {
        const modal = document.getElementById('systemLogsModal');
        modal.style.display = 'block';
        loadLogs();
    }
    
    function closeLogsModal() {
        document.getElementById('systemLogsModal').style.display = 'none';
    }
    
    function loadLogs() {
        const logType = document.getElementById('logTypeFilter').value;
        const logLimit = document.getElementById('logLimit').value;
        const logsContainer = document.getElementById('logsContainer');
        
        logsContainer.innerHTML = '<div style="text-align: center; padding: 40px;"><div class="loading-spinner" style="margin: 0 auto 15px;"></div><div>Loading logs...</div></div>';
        
        fetch('settings.php?view_logs=1&log_type=' + logType + '&log_limit=' + logLimit + '&ajax=1')
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    displayLogs(data);
                } else {
                    logsContainer.innerHTML = '<div class="no-logs-message">Error loading logs</div>';
                }
            })
            .catch(error => {
                console.error('Error:', error);
                logsContainer.innerHTML = '<div class="no-logs-message">Error loading logs</div>';
            });
    }
    
    function displayLogs(data) {
        const logsContainer = document.getElementById('logsContainer');
        const logCountBadge = document.getElementById('logCountBadge');
        const logTypeFilter = document.getElementById('logTypeFilter');
        
        // Update count badge
        logCountBadge.textContent = data.total_logs;
        
        // Update filter dropdown
        logTypeFilter.innerHTML = '<option value="all">All Events</option>';
        if (data.event_types && data.event_types.length > 0) {
            data.event_types.forEach(type => {
                logTypeFilter.innerHTML += `<option value="${type}">${type}</option>`;
            });
        }
        
        if (!data.logs_table_exists) {
            logsContainer.innerHTML = `
                <div class="no-logs-message">
                    <i class="icon-database" style="font-size: 48px; margin-bottom: 15px; display: block; color: #cbd5e0;"></i>
                    <h4 style="color: #2d3748;">System Logs Table Not Found</h4>
                    <p style="color: #718096; margin-top: 10px;">The system_logs table does not exist in the database.</p>
                    <p style="color: #718096; font-size: 12px;">This is normal for a new installation. Logs will be created as system events occur.</p>
                </div>
            `;
            return;
        }
        
        if (!data.logs || data.logs.length === 0) {
            logsContainer.innerHTML = '<div class="no-logs-message">No logs found</div>';
            return;
        }
        
        let html = '<table class="logs-table">';
        html += '<thead><tr><th>Timestamp</th><th>Event Type</th><th>Description</th><th>User</th><th>IP Address</th></tr></thead><tbody>';
        
        data.logs.forEach(log => {
            // Determine event badge class
            let badgeClass = 'event-system';
            if (log.event_type && log.event_type.includes('leave')) badgeClass = 'event-leave';
            else if (log.event_type && log.event_type.includes('permission')) badgeClass = 'event-permission';
            else if (log.event_type && log.event_type.includes('user')) badgeClass = 'event-user';
            else if (log.event_type && log.event_type.includes('error')) badgeClass = 'event-error';
            
            const timestamp = log.created_at ? new Date(log.created_at).toLocaleString() : '-';
            const user = log.full_name || log.username || 'System';
            const ip = log.ip_address || '-';
            
            html += `<tr>`;
            html += `<td class="log-timestamp">${timestamp}</td>`;
            html += `<td><span class="event-badge ${badgeClass}">${log.event_type || 'unknown'}</span></td>`;
            html += `<td>${log.description || '-'}</td>`;
            html += `<td class="log-user">${user}</td>`;
            html += `<td>${ip}</td>`;
            html += `</tr>`;
        });
        
        html += '</tbody></table>';
        logsContainer.innerHTML = html;
    }
    
    function confirmReset(action) {
        const messages = {
            'leaves': 'Delete ALL leaves for the selected user? This cannot be undone!',
            'permissions': 'Delete ALL permissions for the selected user? This cannot be undone!',
            'both': 'Delete ALL leaves and permissions for the selected user? This cannot be undone!',
            'timesheet': 'Delete ALL timesheet entries for the selected user? This cannot be undone!',
            'system': 'Are you ABSOLUTELY sure you want to reset this data? This cannot be undone!'
        };
        
        return confirm(messages[action] || 'Are you sure? This cannot be undone!');
    }
    
    // Close modal when clicking outside
    window.onclick = function(event) {
        const modal = document.getElementById('systemLogsModal');
        if (event.target === modal) {
            modal.style.display = 'none';
        }
    }
    </script>
    
    <script src="../assets/js/app.js"></script>
</body>
</html>