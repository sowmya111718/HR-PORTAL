<?php
require_once '../config/db.php';
require_once '../includes/leave_functions.php';
require_once '../includes/icon_functions.php';
checkRole(['hr', 'admin', 'pm']);

$user_id = $_SESSION['user_id'];
$role = $_SESSION['role'];
$message = '';

// Get leave year info
$leave_year = getCurrentCasualLeaveYear(); // Changed to casual leave year (Mar 16 - Mar 15)
$leave_stats = getLeaveYearStatistics($conn);

// Get LOP statistics for HR panel
$lop_stats_query = $conn->query("
    SELECT 
        COUNT(*) as total_lop_applications,
        COALESCE(SUM(days), 0) as total_lop_days,
        COUNT(DISTINCT user_id) as employees_with_lop
    FROM leaves 
    WHERE leave_type = 'LOP'
    AND from_date BETWEEN '{$leave_year['start_date']}' AND '{$leave_year['end_date']}'
");

if ($lop_stats_query) {
    $lop_stats = $lop_stats_query->fetch_assoc();
} else {
    $lop_stats = [
        'total_lop_applications' => 0,
        'total_lop_days' => 0,
        'employees_with_lop' => 0
    ];
}

// Export Leaves functionality
if (isset($_POST['export_leaves'])) {
    $export_type = sanitize($_POST['export_type']);
    $export_month = isset($_POST['export_month']) ? intval($_POST['export_month']) : date('m');
    $export_year = isset($_POST['export_year']) ? intval($_POST['export_year']) : date('Y');
    
    if ($export_type === 'monthly' && $export_month && $export_year) {
        // Export for specific month/year
        $leave_where = "MONTH(l.applied_date) = $export_month AND YEAR(l.applied_date) = $export_year";
    } else {
        // Export all
        $leave_where = "1=1";
    }
    
    $export_leaves = $conn->query("
        SELECT l.*, u.full_name, u.username, u.department, u.position,
               a.full_name as approved_by_name, r.full_name as rejected_by_name,
               DATE(l.applied_date) as applied_date_only,
               DATE(l.approved_date) as approved_date_only,
               DATE(l.rejected_date) as rejected_date_only
        FROM leaves l
        JOIN users u ON l.user_id = u.id
        LEFT JOIN users a ON l.approved_by = a.id
        LEFT JOIN users r ON l.rejected_by = r.id
        WHERE $leave_where
        ORDER BY l.applied_date DESC
    ");
    
    // Generate Excel file
    $filename = "leaves_export_" . date('Y-m-d') . ".xls";
    
    // Set headers for Excel download
    header("Content-Type: application/vnd.ms-excel");
    header("Content-Disposition: attachment; filename=\"$filename\"");
    header("Pragma: no-cache");
    header("Expires: 0");
    
    // Start Excel content with proper formatting
    echo "<html>";
    echo "<head>";
    echo "<meta charset=\"UTF-8\">";
    echo "<style>";
    echo "td { mso-number-format:\\@; }"; // Force text format for all cells
    echo ".date { mso-number-format:'yyyy-mm-dd'; }";
    echo ".number { mso-number-format:'0'; }";
    echo ".lop { color: #c53030; font-weight: bold; }";
    echo "</style>";
    echo "</head>";
    echo "<body>";
    
    // Start table
    echo "<table border='1'>";
    
    // Headers
    echo "<tr>";
    echo "<th>Employee Name</th>";
    echo "<th>Username</th>";
    echo "<th>Department</th>";
    echo "<th>Position</th>";
    echo "<th>Leave Type</th>";
    echo "<th>From Date</th>";
    echo "<th>To Date</th>";
    echo "<th>Days</th>";
    echo "<th>Reason</th>";
    echo "<th>Status</th>";
    echo "<th>Applied Date</th>";
    echo "<th>Approved By</th>";
    echo "<th>Approved Date</th>";
    echo "<th>Rejected By</th>";
    echo "<th>Rejected Date</th>";
    echo "<th>Leave Year</th>";
    echo "</tr>";
    
    // Data rows
    while ($row = $export_leaves->fetch_assoc()) {
        $row_leave_year = $row['leave_year'] ?? getLeaveYearForDate($row['from_date'])['year_label'];
        $lop_class = ($row['leave_type'] == 'LOP') ? ' class="lop"' : '';
        echo "<tr>";
        echo "<td>" . htmlspecialchars($row['full_name']) . "</td>";
        echo "<td>" . htmlspecialchars($row['username']) . "</td>";
        echo "<td>" . htmlspecialchars($row['department'] ?? 'N/A') . "</td>";
        echo "<td>" . htmlspecialchars($row['position'] ?? 'N/A') . "</td>";
        echo "<td$lop_class>" . htmlspecialchars($row['leave_type']) . "</td>";
        echo "<td class='date'>" . $row['from_date'] . "</td>";
        echo "<td class='date'>" . $row['to_date'] . "</td>";
        echo "<td class='number'>" . $row['days'] . "</td>";
        echo "<td>" . htmlspecialchars($row['reason']) . "</td>";
        echo "<td>" . htmlspecialchars($row['status']) . "</td>";
        echo "<td class='date'>" . ($row['applied_date_only'] ?? '') . "</td>";
        echo "<td>" . htmlspecialchars($row['approved_by_name'] ?? 'N/A') . "</td>";
        echo "<td class='date'>" . ($row['approved_date_only'] ?? '') . "</td>";
        echo "<td>" . htmlspecialchars($row['rejected_by_name'] ?? 'N/A') . "</td>";
        echo "<td class='date'>" . ($row['rejected_date_only'] ?? '') . "</td>";
        echo "<td>" . $row_leave_year . "</td>";
        echo "</tr>";
    }
    
    echo "</table>";
    echo "</body>";
    echo "</html>";
    exit();
}

// Export Permissions functionality
if (isset($_POST['export_permissions'])) {
    $export_type = sanitize($_POST['export_type']);
    $export_month = isset($_POST['export_month']) ? intval($_POST['export_month']) : date('m');
    $export_year = isset($_POST['export_year']) ? intval($_POST['export_year']) : date('Y');
    
    if ($export_type === 'monthly' && $export_month && $export_year) {
        // Export for specific month/year
        $permission_where = "MONTH(p.applied_date) = $export_month AND YEAR(p.applied_date) = $export_year";
    } else {
        // Export all
        $permission_where = "1=1";
    }
    
    $export_permissions = $conn->query("
        SELECT p.*, u.full_name, u.username, u.department, u.position,
               a.full_name as approved_by_name, r.full_name as rejected_by_name,
               DATE(p.applied_date) as applied_date_only,
               DATE(p.approved_date) as approved_date_only,
               DATE(p.rejected_date) as rejected_date_only
        FROM permissions p
        JOIN users u ON p.user_id = u.id
        LEFT JOIN users a ON p.approved_by = a.id
        LEFT JOIN users r ON p.rejected_by = r.id
        WHERE $permission_where
        ORDER BY p.applied_date DESC
    ");
    
    // Generate Excel file
    $filename = "permissions_export_" . date('Y-m-d') . ".xls";
    
    // Set headers for Excel download
    header("Content-Type: application/vnd.ms-excel");
    header("Content-Disposition: attachment; filename=\"$filename\"");
    header("Pragma: no-cache");
    header("Expires: 0");
    
    // Start Excel content with proper formatting
    echo "<html>";
    echo "<head>";
    echo "<meta charset=\"UTF-8\">";
    echo "<style>";
    echo "td { mso-number-format:\\@; }"; // Force text format for all cells
    echo ".date { mso-number-format:'yyyy-mm-dd'; }";
    echo ".number { mso-number-format:'0.00'; }";
    echo "</style>";
    echo "</head>";
    echo "<body>";
    
    // Start table
    echo "<table border='1'>";
    
    // Headers
    echo "<tr>";
    echo "<th>Employee Name</th>";
    echo "<th>Username</th>";
    echo "<th>Department</th>";
    echo "<th>Position</th>";
    echo "<th>Permission Date</th>";
    echo "<th>Duration (hours)</th>";
    echo "<th>Reason</th>";
    echo "<th>Status</th>";
    echo "<th>Applied Date</th>";
    echo "<th>Approved By</th>";
    echo "<th>Approved Date</th>";
    echo "<th>Rejected By</th>";
    echo "<th>Rejected Date</th>";
    echo "</tr>";
    
    // Data rows
    while ($row = $export_permissions->fetch_assoc()) {
        echo "<tr>";
        echo "<td>" . htmlspecialchars($row['full_name']) . "</td>";
        echo "<td>" . htmlspecialchars($row['username']) . "</td>";
        echo "<td>" . htmlspecialchars($row['department'] ?? 'N/A') . "</td>";
        echo "<td>" . htmlspecialchars($row['position'] ?? 'N/A') . "</td>";
        echo "<td class='date'>" . $row['permission_date'] . "</td>";
        echo "<td class='number'>" . $row['duration'] . "</td>";
        echo "<td>" . htmlspecialchars($row['reason']) . "</td>";
        echo "<td>" . htmlspecialchars($row['status']) . "</td>";
        echo "<td class='date'>" . ($row['applied_date_only'] ?? '') . "</td>";
        echo "<td>" . htmlspecialchars($row['approved_by_name'] ?? 'N/A') . "</td>";
        echo "<td class='date'>" . ($row['approved_date_only'] ?? '') . "</td>";
        echo "<td>" . htmlspecialchars($row['rejected_by_name'] ?? 'N/A') . "</td>";
        echo "<td class='date'>" . ($row['rejected_date_only'] ?? '') . "</td>";
        echo "</tr>";
    }
    
    echo "</table>";
    echo "</body>";
    echo "</html>";
    exit();
}

// Approve/Reject Leave
if (isset($_GET['approve_leave'])) {
    $leave_id = intval($_GET['approve_leave']);
    
    // HR cannot approve leaves (only Admin and PM can)
    if ($role === 'hr') {
        $message = '<div class="alert alert-error"><i class="icon-error"></i> HR managers cannot approve leaves. Only Admins and Project Managers can.</div>';
    } else {
        $stmt = $conn->prepare("
            UPDATE leaves 
            SET status = 'Approved', approved_by = ?, approved_date = CURDATE() 
            WHERE id = ? AND status = 'Pending'
        ");
        $stmt->bind_param("ii", $user_id, $leave_id);
        
        if ($stmt->execute()) {
            $message = '<div class="alert alert-success"><i class="icon-success"></i> Leave approved successfully</div>';
        } else {
            $message = '<div class="alert alert-error"><i class="icon-error"></i> Error approving leave</div>';
        }
        $stmt->close();
    }
}

if (isset($_GET['reject_leave'])) {
    $leave_id = intval($_GET['reject_leave']);
    
    // HR cannot reject leaves (only Admin and PM can)
    if ($role === 'hr') {
        $message = '<div class="alert alert-error"><i class="icon-error"></i> HR managers cannot reject leaves. Only Admins and Project Managers can.</div>';
    } else {
        $stmt = $conn->prepare("
            UPDATE leaves 
            SET status = 'Rejected', rejected_by = ?, rejected_date = CURDATE() 
            WHERE id = ? AND status = 'Pending'
        ");
        $stmt->bind_param("ii", $user_id, $leave_id);
        
        if ($stmt->execute()) {
            $message = '<div class="alert alert-success"><i class="icon-success"></i> Leave rejected successfully</div>';
        } else {
            $message = '<div class="alert alert-error"><i class="icon-error"></i> Error rejecting leave</div>';
        }
        $stmt->close();
    }
}

// Approve/Reject Permission
if (isset($_GET['approve_permission'])) {
    $permission_id = intval($_GET['approve_permission']);
    
    // HR cannot approve permissions (only Admin and PM can)
    if ($role === 'hr') {
        $message = '<div class="alert alert-error"><i class="icon-error"></i> HR managers cannot approve permissions. Only Admins and Project Managers can.</div>';
    } else {
        $stmt = $conn->prepare("
            UPDATE permissions 
            SET status = 'Approved', approved_by = ?, approved_date = CURDATE() 
            WHERE id = ? AND status = 'Pending'
        ");
        $stmt->bind_param("ii", $user_id, $permission_id);
        
        if ($stmt->execute()) {
            $message = '<div class="alert alert-success"><i class="icon-success"></i> Permission approved successfully</div>';
        } else {
            $message = '<div class="alert alert-error"><i class="icon-error"></i> Error approving permission</div>';
        }
        $stmt->close();
    }
}

if (isset($_GET['reject_permission'])) {
    $permission_id = intval($_GET['reject_permission']);
    
    // HR cannot reject permissions (only Admin and PM can)
    if ($role === 'hr') {
        $message = '<div class="alert alert-error"><i class="icon-error"></i> HR managers cannot reject permissions. Only Admins and Project Managers can.</div>';
    } else {
        $stmt = $conn->prepare("
            UPDATE permissions 
            SET status = 'Rejected', rejected_by = ?, rejected_date = CURDATE() 
            WHERE id = ? AND status = 'Pending'
        ");
        $stmt->bind_param("ii", $user_id, $permission_id);
        
        if ($stmt->execute()) {
            $message = '<div class="alert alert-success"><i class="icon-success"></i> Permission rejected successfully</div>';
        } else {
            $message = '<div class="alert alert-error"><i class="icon-error"></i> Error rejecting permission</div>';
        }
        $stmt->close();
    }
}

// Approve/Reject LOP Permission (status 'LOP' is also a type that can be approved/rejected)
if (isset($_GET['approve_lop_permission'])) {
    $permission_id = intval($_GET['approve_lop_permission']);
    
    // HR cannot approve permissions (only Admin and PM can)
    if ($role === 'hr') {
        $message = '<div class="alert alert-error"><i class="icon-error"></i> HR managers cannot approve LOP permissions. Only Admins and Project Managers can.</div>';
    } else {
        $stmt = $conn->prepare("
            UPDATE permissions 
            SET status = 'Approved', approved_by = ?, approved_date = CURDATE() 
            WHERE id = ? AND status = 'LOP'
        ");
        $stmt->bind_param("ii", $user_id, $permission_id);
        
        if ($stmt->execute()) {
            $message = '<div class="alert alert-success"><i class="icon-success"></i> LOP permission approved successfully</div>';
        } else {
            $message = '<div class="alert alert-error"><i class="icon-error"></i> Error approving LOP permission</div>';
        }
        $stmt->close();
    }
}

if (isset($_GET['reject_lop_permission'])) {
    $permission_id = intval($_GET['reject_lop_permission']);
    
    // HR cannot reject permissions (only Admin and PM can)
    if ($role === 'hr') {
        $message = '<div class="alert alert-error"><i class="icon-error"></i> HR managers cannot reject LOP permissions. Only Admins and Project Managers can.</div>';
    } else {
        $stmt = $conn->prepare("
            UPDATE permissions 
            SET status = 'Rejected', rejected_by = ?, rejected_date = CURDATE() 
            WHERE id = ? AND status = 'LOP'
        ");
        $stmt->bind_param("ii", $user_id, $permission_id);
        
        if ($stmt->execute()) {
            $message = '<div class="alert alert-success"><i class="icon-success"></i> LOP permission rejected successfully</div>';
        } else {
            $message = '<div class="alert alert-error"><i class="icon-error"></i> Error rejecting LOP permission</div>';
        }
        $stmt->close();
    }
}

// Get filter values
$leave_filter = isset($_GET['leave_filter']) ? sanitize($_GET['leave_filter']) : 'all';
$permission_filter = isset($_GET['permission_filter']) ? sanitize($_GET['permission_filter']) : 'all';
$leave_year_filter = isset($_GET['leave_year']) ? sanitize($_GET['leave_year']) : 'all';

// Get leaves with filter - FIXED: Remove leave_year filter if not needed
$leave_where = "1=1";
if ($leave_filter !== 'all') {
    $leave_where .= " AND l.status = '" . $conn->real_escape_string($leave_filter) . "'";
}
// Only apply leave_year filter if it's not 'all' and not empty
if ($leave_year_filter !== 'all' && !empty($leave_year_filter)) {
    $leave_where .= " AND l.leave_year = '" . $conn->real_escape_string($leave_year_filter) . "'";
}

$leaves = $conn->query("
    SELECT l.*, u.full_name, u.username,
           a.full_name as approved_by_name, a.username as approved_by_username,
           r.full_name as rejected_by_name, r.username as rejected_by_username,
           DATE(l.approved_date) as approved_date_formatted,
           DATE(l.rejected_date) as rejected_date_formatted
    FROM leaves l
    JOIN users u ON l.user_id = u.id
    LEFT JOIN users a ON l.approved_by = a.id
    LEFT JOIN users r ON l.rejected_by = r.id
    WHERE $leave_where
    ORDER BY l.applied_date DESC
");

// Get permissions with filter - FIXED: Proper WHERE clause for permissions
$permission_where = "1=1";
if ($permission_filter !== 'all') {
    $permission_where = "p.status = '" . $conn->real_escape_string($permission_filter) . "'";
}

$permissions = $conn->query("
    SELECT p.*, u.full_name, u.username,
           a.full_name as approved_by_name, a.username as approved_by_username,
           r.full_name as rejected_by_name, r.username as rejected_by_username,
           DATE(p.approved_date) as approved_date_formatted,
           DATE(p.rejected_date) as rejected_date_formatted
    FROM permissions p
    JOIN users u ON p.user_id = u.id
    LEFT JOIN users a ON p.approved_by = a.id
    LEFT JOIN users r ON p.rejected_by = r.id
    WHERE $permission_where
    ORDER BY p.applied_date DESC
");

// Get statistics
$stats_result = $conn->query("
    SELECT 
        (SELECT COUNT(*) FROM users) as total_users,
        (SELECT COUNT(*) FROM leaves WHERE status = 'Pending') as pending_leaves,
        (SELECT COUNT(*) FROM permissions WHERE status = 'Pending') as pending_permissions,
        (SELECT COUNT(*) FROM permissions WHERE status = 'LOP') as lop_permissions,
        (SELECT COUNT(*) FROM attendance WHERE attendance_date = CURDATE()) as today_attendance,
        (SELECT COUNT(*) FROM leaves WHERE status = 'Approved' AND DATE(approved_date) = CURDATE()) as today_approved_leaves,
        (SELECT COUNT(*) FROM leaves WHERE status = 'Rejected' AND DATE(rejected_date) = CURDATE()) as today_rejected_leaves,
        (SELECT COUNT(*) FROM permissions WHERE status = 'Approved' AND DATE(approved_date) = CURDATE()) as today_approved_permissions,
        (SELECT COUNT(*) FROM permissions WHERE status = 'Rejected' AND DATE(rejected_date) = CURDATE()) as today_rejected_permissions,
        (SELECT COUNT(*) FROM permissions WHERE status = 'LOP' AND DATE(applied_date) = CURDATE()) as today_lop_permissions
");

if ($stats_result) {
    $stats = $stats_result->fetch_assoc();
} else {
    $stats = [
        'total_users' => 0,
        'pending_leaves' => 0,
        'pending_permissions' => 0,
        'lop_permissions' => 0,
        'today_attendance' => 0,
        'today_approved_leaves' => 0,
        'today_rejected_leaves' => 0,
        'today_approved_permissions' => 0,
        'today_rejected_permissions' => 0,
        'today_lop_permissions' => 0
    ];
}

// Get current month and year for export forms
$current_month = date('m');
$current_year = date('Y');

// Set page title based on role
$page_title = '';
if ($role === 'pm') {
    $page_title = 'Project Manager Panel';
} elseif ($role === 'hr') {
    $page_title = 'HR Panel';
} elseif ($role === 'admin') {
    $page_title = 'Admin Panel';
}

// Get previous leave year for filter
$prev_leave_year = getPreviousCasualLeaveYear(); // Changed to casual

// Calculate days until next casual reset directly
$days_until_casual_reset = daysUntilCasualYearReset();
$casual_reset_date = getNextCasualResetDate();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $page_title; ?> - MAKSIM HR</title>
    <?php include '../includes/head.php'; ?>
    <style>
        .export-modal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0,0,0,0.5);
        }
        
        .export-modal-content {
            background-color: white;
            margin: 15% auto;
            padding: 20px;
            border-radius: 10px;
            width: 400px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.2);
        }
        
        .export-options {
            display: flex;
            flex-direction: column;
            gap: 15px;
            margin: 20px 0;
        }
        
        .export-option-group {
            display: flex;
            gap: 10px;
            align-items: center;
        }
        
        .export-month-year {
            display: flex;
            gap: 10px;
            margin-left: 20px;
        }
        
        .export-month-year select {
            flex: 1;
        }
        
        .modal-buttons {
            display: flex;
            gap: 10px;
            justify-content: flex-end;
            margin-top: 20px;
        }
        
        .export-btn-group {
            display: flex;
            gap: 10px;
            align-items: center;
        }
        
        .role-badge {
            display: inline-block;
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 12px;
            font-weight: 600;
            margin-left: 10px;
        }
        
        .role-hr { background: #4299e1; color: white; }
        .role-pm { background: #48bb78; color: white; }
        .role-admin { background: #ed8936; color: white; }
        
        .table-container {
            overflow-x: auto;
        }
        
        table {
            width: 100%;
            border-collapse: collapse;
        }
        
        th, td {
            padding: 12px;
            text-align: left;
            border-bottom: 1px solid #e2e8f0;
        }
        
        th {
            background-color: #f7fafc;
            font-weight: 600;
            color: #4a5568;
        }
        
        tr:hover {
            background-color: #f7fafc;
        }
        
        .status-info {
            font-size: 12px;
            color: #718096;
            margin-top: 4px;
        }
        
        .action-timestamp {
            background: #f7fafc;
            padding: 8px 12px;
            border-radius: 6px;
            margin-top: 10px;
            border-left: 3px solid #48bb78;
        }
        
        .today-stats {
            display: flex;
            gap: 15px;
            margin-top: 20px;
            flex-wrap: wrap;
        }
        
        .today-stat {
            background: #f7fafc;
            padding: 12px 16px;
            border-radius: 8px;
            border-left: 4px solid #48bb78;
            flex: 1;
            min-width: 200px;
        }
        
        .today-stat.approved { border-left-color: #48bb78; }
        .today-stat.rejected { border-left-color: #f56565; }
        .today-stat.lop { border-left-color: #ed8936; }
        
        .today-stat-value {
            font-size: 24px;
            font-weight: 600;
            color: #4a5568;
        }
        
        .today-stat-label {
            font-size: 14px;
            color: #718096;
        }
        
        .action-buttons {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
        }
        
        /* Disabled button styles for HR */
        .btn-approve:disabled,
        .btn-reject:disabled {
            opacity: 0.5;
            cursor: not-allowed;
            pointer-events: none;
            background: #cbd5e0 !important;
            color: #718096 !important;
            transform: none !important;
            box-shadow: none !important;
        }
        
        .btn-approve:disabled:hover,
        .btn-reject:disabled:hover {
            transform: none !important;
            box-shadow: none !important;
        }
        
        /* Role-specific page title */
        .page-title {
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        
        .title-left {
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .role-icon {
            font-size: 24px;
        }
        
        .role-pm-icon { color: #48bb78; }
        .role-hr-icon { color: #4299e1; }
        .role-admin-icon { color: #ed8936; }
        
        /* Leave year info styles */
        .leave-year-stats {
            display: flex;
            gap: 20px;
            margin-bottom: 20px;
            flex-wrap: wrap;
        }
        .leave-year-card {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            padding: 20px;
            border-radius: 10px;
            color: white;
            flex: 1;
            min-width: 200px;
        }
        .stats-card {
            background: white;
            padding: 20px;
            border-radius: 10px;
            box-shadow: 0 2px 5px rgba(0,0,0,0.05);
            flex: 1;
            min-width: 200px;
        }
        .stats-label {
            font-size: 14px;
            color: #718096;
            margin-bottom: 5px;
        }
        .stats-value {
            font-size: 24px;
            font-weight: bold;
            color: #2d3748;
        }
        .stats-sub {
            font-size: 12px;
            color: #48bb78;
            margin-top: 5px;
        }
        .leave-year-filter {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 15px;
            padding: 15px;
            background: #f7fafc;
            border-radius: 8px;
        }
        .leave-year-label {
            font-weight: 600;
            color: #4a5568;
        }
        
        /* LOP specific styles */
        .lop-stats-card {
            background: #fff5f5;
            border: 1px solid #feb2b2;
        }
        .lop-value {
            color: #c53030 !important;
        }
        .lop-badge {
            background: #fed7d7;
            color: #c53030;
            padding: 3px 8px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 600;
            display: inline-block;
        }
        .lop-row {
            background: #fff5f5;
        }
        .lop-row:hover {
            background: #fff0f0;
        }
        .lop-text {
            color: #c53030;
            font-weight: 600;
        }
        .btn-lop-approve {
            background: #ed8936;
            color: white;
        }
        .btn-lop-approve:hover {
            background: #dd6b20;
        }
        .btn-lop-reject {
            background: #9b59b6;
            color: white;
        }
        .btn-lop-reject:hover {
            background: #8e44ad;
        }
        
        /* All stat cards now have consistent sizing matching Total LOP Days card */
        .stat-card {
            background: white;
            border-radius: 12px;
            padding: 15px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.05);
            transition: transform 0.2s;
        }
        .stat-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0,0,0,0.1);
        }
        .stat-card .stat-icon {
            width: 40px;
            height: 40px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 10px;
            font-size: 18px;
        }
        .stat-card .stat-value {
            font-size: 24px;
            font-weight: 700;
            margin-bottom: 3px;
        }
        .stat-card .stat-label {
            font-size: 12px;
            color: #718096;
            margin-bottom: 5px;
        }
        .stat-card .stat-sub {
            font-size: 11px;
            color: #718096;
            padding-top: 5px;
            border-top: 1px solid #e2e8f0;
            margin-top: 5px;
        }
        
        /* LOP card specific styling */
        .stat-card.lop-card {
            background: linear-gradient(135deg, #f56565 0%, #c53030 100%);
            color: white;
        }
        .stat-card.lop-card .stat-label,
        .stat-card.lop-card .stat-sub {
            color: rgba(255,255,255,0.9);
        }
        .stat-card.lop-card .stat-sub {
            border-top-color: rgba(255,255,255,0.2);
        }
        
        /* Permission LOP card specific styling */
        .stat-card.permission-lop-card {
            background: linear-gradient(135deg, #ed8936 0%, #c05621 100%);
            color: white;
        }
        .stat-card.permission-lop-card .stat-label,
        .stat-card.permission-lop-card .stat-sub {
            color: rgba(255,255,255,0.9);
        }
        .stat-card.permission-lop-card .stat-sub {
            border-top-color: rgba(255,255,255,0.2);
        }
        
        /* Grid layout for stats */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 15px;
            margin-bottom: 20px;
        }
        
        @media (max-width: 1200px) {
            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
            }
        }
        
        @media (max-width: 768px) {
            .stats-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
    <?php include '../includes/header.php'; ?>
    
    <div class="app-main">
        <?php include '../includes/sidebar.php'; ?>
        
        <div class="main-content">
            <div class="page-title">
                <div class="title-left">
                    <?php if ($role === 'pm'): ?>
                        <i class="icon-pm role-icon"></i>
                    <?php elseif ($role === 'hr'): ?>
                        <i class="icon-hr role-icon"></i>
                    <?php elseif ($role === 'admin'): ?>
                        <i class="icon-admin role-icon"></i>
                    <?php endif; ?>
                    <h2><?php echo $page_title; ?></h2>
                </div>
                <span class="role-badge role-<?php echo $role; ?>">
                    <?php 
                    if ($role === 'pm') echo 'PROJECT MANAGER';
                    elseif ($role === 'hr') echo 'HR MANAGER';
                    elseif ($role === 'admin') echo 'ADMIN';
                    ?>
                </span>
            </div>
            
            <?php echo $message; ?>
            
            <!-- Leave Year Information - Now using Mar 16 - Mar 15 cycle -->
            <div class="leave-year-stats">
                <div class="leave-year-card">
                    <div style="font-size: 14px; opacity: 0.9;">Current Leave Year</div>
                    <div style="font-size: 28px; font-weight: bold;"><?php echo $leave_year['year_label']; ?></div>
                    <div style="font-size: 12px; margin-top: 5px; opacity: 0.8;">Mar 16 - Mar 15</div>
                </div>
                <div class="stats-card">
                    <div class="stats-label">Total Leaves This Year</div>
                    <div class="stats-value"><?php echo $leave_stats['current_days']; ?> days</div>
                    <div class="stats-sub">
                        <i class="icon-file"></i> <?php echo $leave_stats['current_applications']; ?> applications
                    </div>
                </div>
                <div class="stats-card">
                    <div class="stats-label">Days Until Reset</div>
                    <div class="stats-value"><?php echo $days_until_casual_reset; ?> days</div>
                    <div class="stats-sub" style="color: #ed8936;">
                        <i class="icon-calendar"></i> Reset: <?php echo date('M d, Y', strtotime($casual_reset_date)); ?>
                    </div>
                </div>
            </div>
            
            <!-- Statistics Cards - All same size -->
            <div class="stats-grid">
                <!-- Total LOP Days -->
                <div class="stat-card">
                    <div class="stat-icon" style="background: #fed7d7; color: #c53030;">
                        <i class="icon-lop"></i>
                    </div>
                    <div class="stat-value" style="color: #c53030;"><?php echo $lop_stats['total_lop_days']; ?></div>
                    <div class="stat-label">Total LOP Days</div>
                    <div class="stat-sub">
                        <i class="icon-users"></i> <?php echo $lop_stats['employees_with_lop']; ?> employees
                    </div>
                </div>
                
                <!-- Total Users -->
                <div class="stat-card">
                    <div class="stat-icon" style="background: #e6f7ff; color: #1890ff;">
                        <i class="icon-users"></i>
                    </div>
                    <div class="stat-value"><?php echo $stats['total_users']; ?></div>
                    <div class="stat-label">Total Users</div>
                </div>
                
                <!-- LOP Applications -->
                <div class="stat-card">
                    <div class="stat-icon" style="background: #f6ffed; color: #52c41a;">
                        <i class="icon-leave"></i>
                    </div>
                    <div class="stat-value"><?php echo $lop_stats['total_lop_applications']; ?></div>
                    <div class="stat-label">LOP Applications</div>
                    <div class="stat-sub">Current Year</div>
                </div>
                
                <!-- Pending Leaves -->
                <div class="stat-card">
                    <div class="stat-icon" style="background: #fff7e6; color: #fa8c16;">
                        <i class="icon-hourglass"></i>
                    </div>
                    <div class="stat-value"><?php echo $stats['pending_leaves']; ?></div>
                    <div class="stat-label">Pending Leaves</div>
                </div>
                
                <!-- Pending Permissions -->
                <div class="stat-card">
                    <div class="stat-icon" style="background: #f9f0ff; color: #722ed1;">
                        <i class="icon-clock"></i>
                    </div>
                    <div class="stat-value"><?php echo $stats['pending_permissions']; ?></div>
                    <div class="stat-label">Pending Permissions</div>
                </div>
                
                <!-- LOP Permissions -->
                <div class="stat-card">
                    <div class="stat-icon" style="background: #fed7d7; color: #c53030;">
                        <i class="icon-clock"></i>
                    </div>
                    <div class="stat-value"><?php echo $stats['lop_permissions']; ?></div>
                    <div class="stat-label">LOP Permissions</div>
                    <div class="stat-sub">
                        <i class="icon-warning"></i> Pending approvals
                    </div>
                </div>
                
                <!-- Today's Approved Leaves -->
                <div class="stat-card">
                    <div class="stat-icon" style="background: #f6ffed; color: #52c41a;">
                        <i class="icon-check"></i>
                    </div>
                    <div class="stat-value"><?php echo $stats['today_approved_leaves']; ?></div>
                    <div class="stat-label">Approved Leaves</div>
                    <div class="stat-sub">Today</div>
                </div>
                
                <!-- Today's Approved Permissions -->
                <div class="stat-card">
                    <div class="stat-icon" style="background: #f6ffed; color: #52c41a;">
                        <i class="icon-check"></i>
                    </div>
                    <div class="stat-value"><?php echo $stats['today_approved_permissions']; ?></div>
                    <div class="stat-label">Approved Permissions</div>
                    <div class="stat-sub">Today</div>
                </div>
            </div>

            <!-- Today's Approval Stats - Keep these separate as they have different styling -->
            <div class="today-stats">
                <div class="today-stat approved">
                    <div class="today-stat-value"><?php echo $stats['today_attendance']; ?></div>
                    <div class="today-stat-label">Today's Attendance</div>
                </div>
                <div class="today-stat rejected">
                    <div class="today-stat-value"><?php echo $stats['today_rejected_leaves']; ?></div>
                    <div class="today-stat-label">Leaves Rejected Today</div>
                </div>
                <div class="today-stat rejected">
                    <div class="today-stat-value"><?php echo $stats['today_rejected_permissions']; ?></div>
                    <div class="today-stat-label">Permissions Rejected Today</div>
                </div>
                <div class="today-stat lop">
                    <div class="today-stat-value"><?php echo $stats['today_lop_permissions']; ?></div>
                    <div class="today-stat-label">LOP Permissions Today</div>
                </div>
            </div>

            <!-- Export Modals -->
            <div id="exportLeavesModal" class="export-modal">
                <div class="export-modal-content">
                    <h3><i class="icon-excel"></i> Export Leaves to Excel</h3>
                    <form method="POST" action="">
                        <div class="export-options">
                            <div class="export-option-group">
                                <input type="radio" id="leaves_all" name="export_type" value="all" checked>
                                <label for="leaves_all">Export All Leaves</label>
                            </div>
                            <div class="export-option-group">
                                <input type="radio" id="leaves_monthly" name="export_type" value="monthly">
                                <label for="leaves_monthly">Export by Month/Year</label>
                                <div class="export-month-year">
                                    <select name="export_month" id="leaves_month">
                                        <option value="01" <?php echo $current_month == '01' ? 'selected' : ''; ?>>January</option>
                                        <option value="02" <?php echo $current_month == '02' ? 'selected' : ''; ?>>February</option>
                                        <option value="03" <?php echo $current_month == '03' ? 'selected' : ''; ?>>March</option>
                                        <option value="04" <?php echo $current_month == '04' ? 'selected' : ''; ?>>April</option>
                                        <option value="05" <?php echo $current_month == '05' ? 'selected' : ''; ?>>May</option>
                                        <option value="06" <?php echo $current_month == '06' ? 'selected' : ''; ?>>June</option>
                                        <option value="07" <?php echo $current_month == '07' ? 'selected' : ''; ?>>July</option>
                                        <option value="08" <?php echo $current_month == '08' ? 'selected' : ''; ?>>August</option>
                                        <option value="09" <?php echo $current_month == '09' ? 'selected' : ''; ?>>September</option>
                                        <option value="10" <?php echo $current_month == '10' ? 'selected' : ''; ?>>October</option>
                                        <option value="11" <?php echo $current_month == '11' ? 'selected' : ''; ?>>November</option>
                                        <option value="12" <?php echo $current_month == '12' ? 'selected' : ''; ?>>December</option>
                                    </select>
                                    <select name="export_year" id="leaves_year">
                                        <?php for ($y = date('Y'); $y >= 2020; $y--): ?>
                                            <option value="<?php echo $y; ?>" <?php echo $current_year == $y ? 'selected' : ''; ?>><?php echo $y; ?></option>
                                        <?php endfor; ?>
                                    </select>
                                </div>
                            </div>
                        </div>
                        <div class="modal-buttons">
                            <button type="button" class="btn btn-secondary" onclick="closeExportModal('leaves')">Cancel</button>
                            <button type="submit" name="export_leaves" class="btn btn-success">
                                <i class="icon-excel"></i> Export to Excel
                            </button>
                        </div>
                    </form>
                </div>
            </div>

            <div id="exportPermissionsModal" class="export-modal">
                <div class="export-modal-content">
                    <h3><i class="icon-excel"></i> Export Permissions to Excel</h3>
                    <form method="POST" action="">
                        <div class="export-options">
                            <div class="export-option-group">
                                <input type="radio" id="permissions_all" name="export_type" value="all" checked>
                                <label for="permissions_all">Export All Permissions</label>
                            </div>
                            <div class="export-option-group">
                                <input type="radio" id="permissions_monthly" name="export_type" value="monthly">
                                <label for="permissions_monthly">Export by Month/Year</label>
                                <div class="export-month-year">
                                    <select name="export_month" id="permissions_month">
                                        <option value="01" <?php echo $current_month == '01' ? 'selected' : ''; ?>>January</option>
                                        <option value="02" <?php echo $current_month == '02' ? 'selected' : ''; ?>>February</option>
                                        <option value="03" <?php echo $current_month == '03' ? 'selected' : ''; ?>>March</option>
                                        <option value="04" <?php echo $current_month == '04' ? 'selected' : ''; ?>>April</option>
                                        <option value="05" <?php echo $current_month == '05' ? 'selected' : ''; ?>>May</option>
                                        <option value="06" <?php echo $current_month == '06' ? 'selected' : ''; ?>>June</option>
                                        <option value="07" <?php echo $current_month == '07' ? 'selected' : ''; ?>>July</option>
                                        <option value="08" <?php echo $current_month == '08' ? 'selected' : ''; ?>>August</option>
                                        <option value="09" <?php echo $current_month == '09' ? 'selected' : ''; ?>>September</option>
                                        <option value="10" <?php echo $current_month == '10' ? 'selected' : ''; ?>>October</option>
                                        <option value="11" <?php echo $current_month == '11' ? 'selected' : ''; ?>>November</option>
                                        <option value="12" <?php echo $current_month == '12' ? 'selected' : ''; ?>>December</option>
                                    </select>
                                    <select name="export_year" id="permissions_year">
                                        <?php for ($y = date('Y'); $y >= 2020; $y--): ?>
                                            <option value="<?php echo $y; ?>" <?php echo $current_year == $y ? 'selected' : ''; ?>><?php echo $y; ?></option>
                                        <?php endfor; ?>
                                    </select>
                                </div>
                            </div>
                        </div>
                        <div class="modal-buttons">
                            <button type="button" class="btn btn-secondary" onclick="closeExportModal('permissions')">Cancel</button>
                            <button type="submit" name="export_permissions" class="btn btn-success">
                                <i class="icon-excel"></i> Export to Excel
                            </button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Leave Requests -->
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title"><i class="icon-leave"></i> Leave Requests</h3>
                    <div class="export-btn-group">
                        <!-- ADD THIS: Leave Year Filter -->
                        <div class="leave-year-filter">
                            <span class="leave-year-label"><i class="icon-calendar"></i> Leave Year:</span>
                            <select id="leaveYearFilter" class="form-control" style="width: 150px;" onchange="filterLeaves()">
                                <option value="all" <?php echo $leave_year_filter === 'all' ? 'selected' : ''; ?>>All Years</option>
                                <option value="<?php echo $leave_year['year_label']; ?>" <?php echo $leave_year_filter === $leave_year['year_label'] ? 'selected' : ''; ?>>
                                    Current (<?php echo $leave_year['year_label']; ?>)
                                </option>
                                <option value="<?php echo $prev_leave_year['year_label']; ?>" <?php echo $leave_year_filter === $prev_leave_year['year_label'] ? 'selected' : ''; ?>>
                                    Previous (<?php echo $prev_leave_year['year_label']; ?>)
                                </option>
                            </select>
                        </div>
                        <select id="leaveFilter" class="form-control" onchange="filterLeaves()">
                            <option value="all" <?php echo $leave_filter === 'all' ? 'selected' : ''; ?>>All Status</option>
                            <option value="pending" <?php echo $leave_filter === 'pending' ? 'selected' : ''; ?>>Pending</option>
                            <option value="approved" <?php echo $leave_filter === 'approved' ? 'selected' : ''; ?>>Approved</option>
                            <option value="rejected" <?php echo $leave_filter === 'rejected' ? 'selected' : ''; ?>>Rejected</option>
                        </select>
                        <button class="btn btn-success" onclick="openExportModal('leaves')">
                            <i class="icon-excel"></i> Export Excel
                        </button>
                    </div>
                </div>
                <div class="table-container">
                    <table>
                        <thead>
                            <tr>
                                <th>Employee</th>
                                <th>Type</th>
                                <th>From Date</th>
                                <th>To Date</th>
                                <th>Days</th>
                                <th>Status</th>
                                <th>Reason</th>
                                <th>Applied Date</th>
                                <th>Leave Year</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($leaves && $leaves->num_rows > 0): ?>
                                <?php while ($leave = $leaves->fetch_assoc()): 
                                    $row_leave_year = $leave['leave_year'] ?? getLeaveYearForDate($leave['from_date'])['year_label'];
                                    $is_lop = ($leave['leave_type'] == 'LOP');
                                ?>
                                <tr <?php echo $is_lop ? 'class="lop-row"' : ''; ?>>
                                    <td><?php echo htmlspecialchars($leave['full_name']); ?></td>
                                    <td>
                                        <?php if ($is_lop): ?>
                                            <span class="lop-badge">
                                                <i class="icon-lop"></i> LOP
                                            </span>
                                        <?php else: ?>
                                            <?php echo htmlspecialchars($leave['leave_type']); ?>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo htmlspecialchars($leave['from_date']); ?></td>
                                    <td><?php echo htmlspecialchars($leave['to_date']); ?></td>
                                    <td <?php echo $is_lop ? 'class="lop-text"' : ''; ?>>
                                        <?php echo htmlspecialchars($leave['days']); ?>
                                    </td>
                                    <td>
                                        <span class="status-badge status-<?php echo strtolower($leave['status']); ?>">
                                            <?php echo htmlspecialchars($leave['status']); ?>
                                        </span>
                                        <?php if ($leave['status'] === 'Approved' && $leave['approved_by_name']): ?>
                                            <div class="status-info">
                                                Approved by: <?php echo htmlspecialchars($leave['approved_by_name']); ?>
                                                <?php if ($leave['approved_date_formatted']): ?>
                                                    on <?php echo $leave['approved_date_formatted']; ?>
                                                <?php endif; ?>
                                            </div>
                                        <?php elseif ($leave['status'] === 'Rejected' && $leave['rejected_by_name']): ?>
                                            <div class="status-info">
                                                Rejected by: <?php echo htmlspecialchars($leave['rejected_by_name']); ?>
                                                <?php if ($leave['rejected_date_formatted']): ?>
                                                    on <?php echo $leave['rejected_date_formatted']; ?>
                                                <?php endif; ?>
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                    <td title="<?php echo htmlspecialchars($leave['reason']); ?>">
                                        <?php echo strlen($leave['reason']) > 30 ? substr(htmlspecialchars($leave['reason']), 0, 30) . '...' : htmlspecialchars($leave['reason']); ?>
                                    </td>
                                    <td><?php echo date('Y-m-d', strtotime($leave['applied_date'])); ?></td>
                                    <td>
                                        <span style="background: #e2e8f0; padding: 3px 8px; border-radius: 12px; font-size: 11px;">
                                            <?php echo $row_leave_year; ?>
                                        </span>
                                    </td>
                                    <td>
                                        <div class="action-buttons">
                                            <?php if ($leave['status'] === 'Pending'): ?>
                                                <?php if ($role === 'hr'): ?>
                                                    <!-- HR can only view, not approve/reject - disabled buttons with no hover -->
                                                    <button class="btn-small btn-approve" disabled title="HR cannot approve leaves">
                                                        <i class="icon-check"></i> Approve
                                                    </button>
                                                    <button class="btn-small btn-reject" disabled title="HR cannot reject leaves">
                                                        <i class="icon-cancel"></i> Reject
                                                    </button>
                                                <?php else: ?>
                                                    <!-- Admin and PM can approve/reject -->
                                                    <a href="?approve_leave=<?php echo $leave['id']; ?>&leave_filter=<?php echo $leave_filter; ?>&permission_filter=<?php echo $permission_filter; ?>&leave_year=<?php echo $leave_year_filter; ?>" 
                                                       class="btn-small btn-approve"
                                                       onclick="return confirm('Are you sure you want to approve this leave?')">
                                                        <i class="icon-check"></i> Approve
                                                    </a>
                                                    <a href="?reject_leave=<?php echo $leave['id']; ?>&leave_filter=<?php echo $leave_filter; ?>&permission_filter=<?php echo $permission_filter; ?>&leave_year=<?php echo $leave_year_filter; ?>" 
                                                       class="btn-small btn-reject"
                                                       onclick="return confirm('Are you sure you want to reject this leave?')">
                                                        <i class="icon-cancel"></i> Reject
                                                    </a>
                                                <?php endif; ?>
                                            <?php endif; ?>
                                            <a href="../leaves/leave_details.php?id=<?php echo $leave['id']; ?>" class="btn-small btn-view">
                                                <i class="icon-view"></i> View
                                            </a>
                                        </div>
                                        <?php if ($leave['status'] !== 'Pending'): ?>
                                            <div class="action-timestamp">
                                                <?php if ($leave['status'] === 'Approved'): ?>
                                                    <i class="icon-check" style="color: #48bb78;"></i>
                                                    Approved on <?php echo $leave['approved_date_formatted']; ?>
                                                <?php elseif ($leave['status'] === 'Rejected'): ?>
                                                    <i class="icon-cancel" style="color: #f56565;"></i>
                                                    Rejected on <?php echo $leave['rejected_date_formatted']; ?>
                                                <?php endif; ?>
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="10" style="text-align: center; padding: 40px; color: #718096;">
                                        <i class="icon-leave" style="font-size: 48px; margin-bottom: 15px; display: block; color: #cbd5e0;"></i>
                                        No leave requests found
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Permission Requests -->
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title"><i class="icon-clock"></i> Permission & LOP Requests</h3>
                    <div class="export-btn-group">
                        <select id="permissionFilter" class="form-control" onchange="filterPermissions()">
                            <option value="all" <?php echo $permission_filter === 'all' ? 'selected' : ''; ?>>All Status</option>
                            <option value="pending" <?php echo $permission_filter === 'pending' ? 'selected' : ''; ?>>Pending</option>
                            <option value="approved" <?php echo $permission_filter === 'approved' ? 'selected' : ''; ?>>Approved</option>
                            <option value="rejected" <?php echo $permission_filter === 'rejected' ? 'selected' : ''; ?>>Rejected</option>
                            <option value="LOP" <?php echo $permission_filter === 'LOP' ? 'selected' : ''; ?>>LOP (Pending)</option>
                        </select>
                        <button class="btn btn-success" onclick="openExportModal('permissions')">
                            <i class="icon-excel"></i> Export Excel
                        </button>
                    </div>
                </div>
                <div class="table-container">
                    <table>
                        <thead>
                            <tr>
                                <th>Employee</th>
                                <th>Permission Date</th>
                                <th>Duration</th>
                                <th>Status</th>
                                <th>Reason</th>
                                <th>Applied Date</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($permissions && $permissions->num_rows > 0): ?>
                                <?php while ($permission = $permissions->fetch_assoc()): 
                                    $is_lop = ($permission['status'] === 'LOP');
                                ?>
                                <tr <?php echo $is_lop ? 'class="lop-row"' : ''; ?>>
                                    <td><?php echo htmlspecialchars($permission['full_name']); ?></td>
                                    <td><?php echo htmlspecialchars($permission['permission_date']); ?></td>
                                    <td><?php echo htmlspecialchars($permission['duration']); ?> hours</td>
                                    <td>
                                        <?php if ($is_lop): ?>
                                            <span class="status-badge" style="background:#fed7d7; color:#c53030;">
                                                LOP (Pending)
                                            </span>
                                        <?php else: ?>
                                            <span class="status-badge status-<?php echo strtolower($permission['status']); ?>">
                                                <?php echo htmlspecialchars($permission['status']); ?>
                                            </span>
                                        <?php endif; ?>
                                        
                                        <?php if ($permission['status'] === 'Approved' && $permission['approved_by_name']): ?>
                                            <div class="status-info">
                                                Approved by: <?php echo htmlspecialchars($permission['approved_by_name']); ?>
                                                <?php if ($permission['approved_date_formatted']): ?>
                                                    on <?php echo $permission['approved_date_formatted']; ?>
                                                <?php endif; ?>
                                            </div>
                                        <?php elseif ($permission['status'] === 'Rejected' && $permission['rejected_by_name']): ?>
                                            <div class="status-info">
                                                Rejected by: <?php echo htmlspecialchars($permission['rejected_by_name']); ?>
                                                <?php if ($permission['rejected_date_formatted']): ?>
                                                    on <?php echo $permission['rejected_date_formatted']; ?>
                                                <?php endif; ?>
                                            </div>
                                        <?php elseif ($is_lop && $permission['status'] === 'LOP'): ?>
                                            <div class="status-info" style="color:#ed8936;">
                                                <i class="icon-warning"></i> Auto-generated from excess hours
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                    <td title="<?php echo htmlspecialchars($permission['reason']); ?>">
                                        <?php echo strlen($permission['reason']) > 30 ? substr(htmlspecialchars($permission['reason']), 0, 30) . '...' : htmlspecialchars($permission['reason']); ?>
                                    </td>
                                    <td><?php echo date('Y-m-d', strtotime($permission['applied_date'])); ?></td>
                                    <td>
                                        <div class="action-buttons">
                                            <?php if ($permission['status'] === 'Pending'): ?>
                                                <?php if ($role === 'hr'): ?>
                                                    <!-- HR can only view, not approve/reject - disabled buttons with no hover -->
                                                    <button class="btn-small btn-approve" disabled title="HR cannot approve permissions">
                                                        <i class="icon-check"></i> Approve
                                                    </button>
                                                    <button class="btn-small btn-reject" disabled title="HR cannot reject permissions">
                                                        <i class="icon-cancel"></i> Reject
                                                    </button>
                                                <?php else: ?>
                                                    <!-- Admin and PM can approve/reject -->
                                                    <a href="?approve_permission=<?php echo $permission['id']; ?>&leave_filter=<?php echo $leave_filter; ?>&permission_filter=<?php echo $permission_filter; ?>&leave_year=<?php echo $leave_year_filter; ?>" 
                                                       class="btn-small btn-approve"
                                                       onclick="return confirm('Are you sure you want to approve this permission?')">
                                                        <i class="icon-check"></i> Approve
                                                    </a>
                                                    <a href="?reject_permission=<?php echo $permission['id']; ?>&leave_filter=<?php echo $leave_filter; ?>&permission_filter=<?php echo $permission_filter; ?>&leave_year=<?php echo $leave_year_filter; ?>" 
                                                       class="btn-small btn-reject"
                                                       onclick="return confirm('Are you sure you want to reject this permission?')">
                                                        <i class="icon-cancel"></i> Reject
                                                    </a>
                                                <?php endif; ?>
                                            <?php elseif ($is_lop && $permission['status'] === 'LOP'): ?>
                                                <?php if ($role === 'hr'): ?>
                                                    <!-- HR can only view, not approve/reject LOP -->
                                                    <button class="btn-small" disabled style="background:#cbd5e0; color:#718096;" title="HR cannot approve LOP">
                                                        <i class="icon-check"></i> Approve LOP
                                                    </button>
                                                    <button class="btn-small" disabled style="background:#cbd5e0; color:#718096;" title="HR cannot reject LOP">
                                                        <i class="icon-cancel"></i> Reject LOP
                                                    </button>
                                                <?php else: ?>
                                                    <!-- Admin and PM can approve/reject LOP -->
                                                    <a href="?approve_lop_permission=<?php echo $permission['id']; ?>&leave_filter=<?php echo $leave_filter; ?>&permission_filter=<?php echo $permission_filter; ?>&leave_year=<?php echo $leave_year_filter; ?>" 
                                                       class="btn-small btn-lop-approve"
                                                       onclick="return confirm('Are you sure you want to approve this LOP permission?')">
                                                        <i class="icon-check"></i> Approve LOP
                                                    </a>
                                                    <a href="?reject_lop_permission=<?php echo $permission['id']; ?>&leave_filter=<?php echo $leave_filter; ?>&permission_filter=<?php echo $permission_filter; ?>&leave_year=<?php echo $leave_year_filter; ?>" 
                                                       class="btn-small btn-lop-reject"
                                                       onclick="return confirm('Are you sure you want to reject this LOP permission?')">
                                                        <i class="icon-cancel"></i> Reject LOP
                                                    </a>
                                                <?php endif; ?>
                                            <?php endif; ?>
                                            <a href="../permissions/permission_details.php?id=<?php echo $permission['id']; ?>" class="btn-small btn-view">
                                                <i class="icon-view"></i> View
                                            </a>
                                        </div>
                                        <?php if ($permission['status'] !== 'Pending' && $permission['status'] !== 'LOP'): ?>
                                            <div class="action-timestamp">
                                                <?php if ($permission['status'] === 'Approved'): ?>
                                                    <i class="icon-check" style="color: #48bb78;"></i>
                                                    Approved on <?php echo $permission['approved_date_formatted']; ?>
                                                <?php elseif ($permission['status'] === 'Rejected'): ?>
                                                    <i class="icon-cancel" style="color: #f56565;"></i>
                                                    Rejected on <?php echo $permission['rejected_date_formatted']; ?>
                                                <?php endif; ?>
                                            </div>
                                        <?php elseif ($is_lop && $permission['status'] === 'LOP'): ?>
                                            <div class="action-timestamp" style="border-left-color: #ed8936;">
                                                <i class="icon-warning" style="color: #ed8936;"></i>
                                                Pending LOP approval
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="7" style="text-align: center; padding: 40px; color: #718096;">
                                        <i class="icon-clock" style="font-size: 48px; margin-bottom: 15px; display: block; color: #cbd5e0;"></i>
                                        No permission requests found
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
    function filterLeaves() {
        const filter = document.getElementById('leaveFilter').value;
        const permissionFilter = document.getElementById('permissionFilter').value;
        const leaveYear = document.getElementById('leaveYearFilter').value;
        window.location.href = `panel.php?leave_filter=${filter}&permission_filter=${permissionFilter}&leave_year=${leaveYear}`;
    }
    
    function filterPermissions() {
        const filter = document.getElementById('permissionFilter').value;
        const leaveFilter = document.getElementById('leaveFilter').value;
        const leaveYear = document.getElementById('leaveYearFilter').value;
        window.location.href = `panel.php?leave_filter=${leaveFilter}&permission_filter=${filter}&leave_year=${leaveYear}`;
    }
    
    function openExportModal(type) {
        const modal = document.getElementById(`export${type.charAt(0).toUpperCase() + type.slice(1)}Modal`);
        modal.style.display = 'block';
    }
    
    function closeExportModal(type) {
        const modal = document.getElementById(`export${type.charAt(0).toUpperCase() + type.slice(1)}Modal`);
        modal.style.display = 'none';
    }
    
    // Close modal when clicking outside
    window.onclick = function(event) {
        const leavesModal = document.getElementById('exportLeavesModal');
        const permissionsModal = document.getElementById('exportPermissionsModal');
        
        if (event.target === leavesModal) {
            leavesModal.style.display = 'none';
        }
        if (event.target === permissionsModal) {
            permissionsModal.style.display = 'none';
        }
    }
    
    // Enable/disable month/year selects based on radio selection
    document.addEventListener('DOMContentLoaded', function() {
        // For leaves modal
        const leavesMonthlyRadio = document.getElementById('leaves_monthly');
        const leavesMonthSelect = document.getElementById('leaves_month');
        const leavesYearSelect = document.getElementById('leaves_year');
        
        if (leavesMonthlyRadio) {
            leavesMonthlyRadio.addEventListener('change', function() {
                leavesMonthSelect.disabled = !this.checked;
                leavesYearSelect.disabled = !this.checked;
            });
        }
        
        // For permissions modal
        const permissionsMonthlyRadio = document.getElementById('permissions_monthly');
        const permissionsMonthSelect = document.getElementById('permissions_month');
        const permissionsYearSelect = document.getElementById('permissions_year');
        
        if (permissionsMonthlyRadio) {
            permissionsMonthlyRadio.addEventListener('change', function() {
                permissionsMonthSelect.disabled = !this.checked;
                permissionsYearSelect.disabled = !this.checked;
            });
        }
        
        // Initialize disabled state
        if (leavesMonthSelect) leavesMonthSelect.disabled = !(leavesMonthlyRadio && leavesMonthlyRadio.checked);
        if (leavesYearSelect) leavesYearSelect.disabled = !(leavesMonthlyRadio && leavesMonthlyRadio.checked);
        if (permissionsMonthSelect) permissionsMonthSelect.disabled = !(permissionsMonthlyRadio && permissionsMonthlyRadio.checked);
        if (permissionsYearSelect) permissionsYearSelect.disabled = !(permissionsMonthlyRadio && permissionsMonthlyRadio.checked);
    });
    </script>
    
    <script src="../assets/js/app.js"></script>
</body>
</html>