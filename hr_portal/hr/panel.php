<?php
require_once '../config/db.php';
require_once '../includes/leave_functions.php';
require_once '../includes/icon_functions.php';
checkRole(['hr', 'admin', 'pm']);

$user_id = $_SESSION['user_id'];
$role = $_SESSION['role'];
$message = '';

// Function to get leave month ranges (16th to 15th)
function getLeaveMonthRanges() {
    $current_year = date('Y');
    $current_month = date('n');
    $current_day = date('j');
    
    // Determine current leave month based on date
    if ($current_day >= 16) {
        $current_leave_month = $current_month;
    } else {
        $current_leave_month = ($current_month == 1) ? 12 : $current_month - 1;
    }
    
    $months = [];
    for ($i = 0; $i < 12; $i++) {
        $month_num = $current_leave_month - $i;
        if ($month_num <= 0) {
            $month_num += 12;
        }
        
        // Calculate start and end dates for this leave month
        if ($month_num == 1) {
            $start_month = 1;
            $start_day = 16;
            $end_month = 2;
            $end_day = 15;
            $year_offset = 0;
        } elseif ($month_num == 2) {
            $start_month = 2;
            $start_day = 16;
            $end_month = 3;
            $end_day = 15;
            $year_offset = 0;
        } elseif ($month_num == 3) {
            $start_month = 3;
            $start_day = 16;
            $end_month = 4;
            $end_day = 15;
            $year_offset = 0;
        } elseif ($month_num == 4) {
            $start_month = 4;
            $start_day = 16;
            $end_month = 5;
            $end_day = 15;
            $year_offset = 0;
        } elseif ($month_num == 5) {
            $start_month = 5;
            $start_day = 16;
            $end_month = 6;
            $end_day = 15;
            $year_offset = 0;
        } elseif ($month_num == 6) {
            $start_month = 6;
            $start_day = 16;
            $end_month = 7;
            $end_day = 15;
            $year_offset = 0;
        } elseif ($month_num == 7) {
            $start_month = 7;
            $start_day = 16;
            $end_month = 8;
            $end_day = 15;
            $year_offset = 0;
        } elseif ($month_num == 8) {
            $start_month = 8;
            $start_day = 16;
            $end_month = 9;
            $end_day = 15;
            $year_offset = 0;
        } elseif ($month_num == 9) {
            $start_month = 9;
            $start_day = 16;
            $end_month = 10;
            $end_day = 15;
            $year_offset = 0;
        } elseif ($month_num == 10) {
            $start_month = 10;
            $start_day = 16;
            $end_month = 11;
            $end_day = 15;
            $year_offset = 0;
        } elseif ($month_num == 11) {
            $start_month = 11;
            $start_day = 16;
            $end_month = 12;
            $end_day = 15;
            $year_offset = 0;
        } elseif ($month_num == 12) {
            $start_month = 12;
            $start_day = 16;
            $end_month = 1;
            $end_day = 15;
            $year_offset = 1; // Next year for end date
        }
        
        $start_year = date('Y');
        $end_year = date('Y') + $year_offset;
        
        // Adjust years based on month position
        if ($month_num > $current_month) {
            $start_year = date('Y') - 1;
            $end_year = date('Y') - 1 + $year_offset;
        }
        
        $start_date = "$start_year-$start_month-$start_day";
        $end_date = "$end_year-$end_month-$end_day";
        
        $month_name = date('F', mktime(0, 0, 0, $month_num, 1));
        $next_month_name = date('F', mktime(0, 0, 0, $end_month, 1));
        
        $months[$month_num] = [
            'value' => $month_num,
            'label' => $month_name . ' 16 - ' . $next_month_name . ' 15',
            'start_date' => $start_date,
            'end_date' => $end_date
        ];
    }
    
    // Sort by month number
    ksort($months);
    return $months;
}

// Get leave year info
$leave_year = getCurrentCasualLeaveYear();
$leave_stats = getLeaveYearStatistics($conn);

// Get leave month ranges
$leave_months = getLeaveMonthRanges();

// Get LOP statistics for HR panel
$lop_stats_query = $conn->query("
    SELECT 
        COUNT(*) as total_lop_applications,
        COALESCE(SUM(days), 0) as total_lop_days,
        COUNT(DISTINCT user_id) as employees_with_lop,
        SUM(CASE WHEN reason LIKE 'Auto-generated LOP%' THEN 1 ELSE 0 END) as auto_lop_count,
        SUM(CASE WHEN reason LIKE 'Auto-generated LOP%' THEN days ELSE 0 END) as auto_lop_days,
        SUM(CASE WHEN reason NOT LIKE 'Auto-generated LOP%' AND leave_type = 'LOP' THEN 1 ELSE 0 END) as manual_lop_count,
        SUM(CASE WHEN reason NOT LIKE 'Auto-generated LOP%' AND leave_type = 'LOP' THEN days ELSE 0 END) as manual_lop_days
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
        'employees_with_lop' => 0,
        'auto_lop_count' => 0,
        'auto_lop_days' => 0,
        'manual_lop_count' => 0,
        'manual_lop_days' => 0
    ];
}

// Export Leaves functionality
if (isset($_POST['export_leaves'])) {
    $export_type = sanitize($_POST['export_type']);
    $export_month = isset($_POST['export_month']) ? intval($_POST['export_month']) : date('m');
    $export_year = isset($_POST['export_year']) ? intval($_POST['export_year']) : date('Y');
    $export_lop_type = isset($_POST['export_lop_type']) ? sanitize($_POST['export_lop_type']) : 'all';
    $export_leave_month = isset($_POST['export_leave_month']) ? intval($_POST['export_leave_month']) : 0;
    
    if ($export_type === 'monthly' && $export_leave_month > 0) {
        $month_data = $leave_months[$export_leave_month] ?? null;
        if ($month_data) {
            $leave_where = "l.from_date >= '{$month_data['start_date']}' AND l.from_date <= '{$month_data['end_date']}'";
        } else {
            $leave_where = "1=1";
        }
    } else {
        $leave_where = "1=1";
    }
    
    if ($export_lop_type === 'auto') {
        $leave_where .= " AND l.leave_type = 'LOP' AND l.reason LIKE 'Auto-generated LOP%'";
    } elseif ($export_lop_type === 'manual') {
        $leave_where .= " AND l.leave_type = 'LOP' AND l.reason NOT LIKE 'Auto-generated LOP%'";
    } elseif ($export_lop_type === 'regular') {
        $leave_where .= " AND l.leave_type != 'LOP'";
    } elseif ($export_lop_type === 'sick') {
        $leave_where .= " AND l.leave_type = 'Sick'";
    } elseif ($export_lop_type === 'casual') {
        $leave_where .= " AND l.leave_type = 'Casual'";
    }
    
    $export_leaves = $conn->query("
        SELECT l.*, u.full_name, u.username, u.department, u.position,
               a.full_name as approved_by_name, r.full_name as rejected_by_name,
               DATE(l.applied_date) as applied_date_only,
               DATE(l.approved_date) as approved_date_only,
               DATE(l.rejected_date) as rejected_date_only,
               CASE WHEN l.reason LIKE 'Auto-generated LOP%' THEN 1 ELSE 0 END as is_auto_lop
        FROM leaves l
        JOIN users u ON l.user_id = u.id
        LEFT JOIN users a ON l.approved_by = a.id
        LEFT JOIN users r ON l.rejected_by = r.id
        WHERE $leave_where
        ORDER BY l.applied_date DESC
    ");
    
    $filename = "leaves_export_" . date('Y-m-d') . ".xls";
    header("Content-Type: application/vnd.ms-excel");
    header("Content-Disposition: attachment; filename=\"$filename\"");
    header("Pragma: no-cache");
    header("Expires: 0");
    
    echo "<html><head><meta charset=\"UTF-8\"><style>td { mso-number-format:\\@; }.date { mso-number-format:'yyyy-mm-dd'; }.number { mso-number-format:'0'; }</style></head><body>";
    echo "<h2>MAKSIM HR - Leaves Export</h2>";
    echo "<p><strong>Export Type:</strong> " . ($export_lop_type == 'auto' ? 'Auto-generated LOP Only' : ($export_lop_type == 'manual' ? 'Manual LOP Only' : ($export_lop_type == 'regular' ? 'Regular Leaves (Non-LOP)' : ($export_lop_type == 'sick' ? 'Sick Leave Only' : ($export_lop_type == 'casual' ? 'Casual Leave Only' : 'All Leaves'))))) . "</p>";
    if ($export_type == 'monthly' && $export_leave_month > 0) {
        $month_data = $leave_months[$export_leave_month] ?? null;
        if ($month_data) {
            echo "<p><strong>Leave Month:</strong> " . $month_data['label'] . "</p>";
        }
    }
    echo "<br><table border='1'><tr><th>Employee Name</th><th>Username</th><th>Department</th><th>Position</th><th>Leave Type</th><th>From Date</th><th>To Date</th><th>Days</th><th>Reason</th><th>Status</th><th>Applied Date</th><th>Approved By</th><th>Approved Date</th><th>Rejected By</th><th>Rejected Date</th><th>Leave Year</th><th>LOP Type</th></tr>";
    
    while ($row = $export_leaves->fetch_assoc()) {
        $row_leave_year = $row['leave_year'] ?? getLeaveYearForDate($row['from_date'])['year_label'];
        $is_auto_lop = ($row['is_auto_lop'] == 1);
        $is_lop = ($row['leave_type'] == 'LOP');
        $lop_type_display = $is_lop ? ($is_auto_lop ? 'Auto-generated LOP' : 'Manual LOP') : 'Regular Leave';
        
        echo "<tr>";
        echo "<td>" . htmlspecialchars($row['full_name']) . "</td>";
        echo "<td>" . htmlspecialchars($row['username']) . "</td>";
        echo "<td>" . htmlspecialchars($row['department'] ?? 'N/A') . "</td>";
        echo "<td>" . htmlspecialchars($row['position'] ?? 'N/A') . "</td>";
        echo "<td>" . htmlspecialchars($row['leave_type']) . "</td>";
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
        echo "<td>" . $lop_type_display . "</td>";
        echo "</tr>";
    }
    
    echo "</table></body></html>";
    exit();
}

// Export Permissions functionality
if (isset($_POST['export_permissions'])) {
    $export_type = sanitize($_POST['export_type']);
    $export_month = isset($_POST['export_month']) ? intval($_POST['export_month']) : date('m');
    $export_year = isset($_POST['export_year']) ? intval($_POST['export_year']) : date('Y');
    $export_permission_month = isset($_POST['export_permission_month']) ? intval($_POST['export_permission_month']) : 0;
    
    if ($export_type === 'monthly' && $export_permission_month > 0) {
        $month_data = $leave_months[$export_permission_month] ?? null;
        if ($month_data) {
            $permission_where = "p.permission_date >= '{$month_data['start_date']}' AND p.permission_date <= '{$month_data['end_date']}'";
        } else {
            $permission_where = "1=1";
        }
    } else {
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
        ORDER BY p.permission_date DESC
    ");
    
    $filename = "permissions_export_" . date('Y-m-d') . ".xls";
    header("Content-Type: application/vnd.ms-excel");
    header("Content-Disposition: attachment; filename=\"$filename\"");
    header("Pragma: no-cache");
    header("Expires: 0");
    
    echo "<html><head><meta charset=\"UTF-8\"><style>td { mso-number-format:\\@; }.date { mso-number-format:'yyyy-mm-dd'; }.number { mso-number-format:'0.00'; }</style></head><body>";
    echo "<h2>MAKSIM HR - Permissions Export</h2>";
    if ($export_type == 'monthly' && $export_permission_month > 0) {
        $month_data = $leave_months[$export_permission_month] ?? null;
        if ($month_data) {
            echo "<p><strong>Permission Month:</strong> " . $month_data['label'] . "</p>";
        }
    }
    echo "<br><table border='1'><tr><th>Employee Name</th><th>Username</th><th>Department</th><th>Position</th><th>Permission Date</th><th>Duration (hours)</th><th>Reason</th><th>Status</th><th>Applied Date</th><th>Approved By</th><th>Approved Date</th><th>Rejected By</th><th>Rejected Date</th></tr>";
    
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
    
    echo "</table></body></html>";
    exit();
}

// Approve/Reject Leave
if (isset($_GET['approve_leave'])) {
    $leave_id = intval($_GET['approve_leave']);
    
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

// DELETE LEAVE HANDLING - Only for Admin and PM
if (isset($_GET['delete_leave']) && in_array($role, ['admin', 'pm'])) {
    $leave_id = intval($_GET['delete_leave']);
    
    $stmt = $conn->prepare("SELECT user_id, from_date, to_date, leave_type, days FROM leaves WHERE id = ?");
    $stmt->bind_param("i", $leave_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $leave = $result->fetch_assoc();
    $stmt->close();
    
    if ($leave) {
        $conn->begin_transaction();
        
        try {
            $delete = $conn->prepare("DELETE FROM leaves WHERE id = ?");
            $delete->bind_param("i", $leave_id);
            $delete->execute();
            $delete->close();
            
            $conn->commit();
            
            $_SESSION['panel_message'] = '<div class="alert alert-warning"><i class="icon-warning"></i> Leave entry deleted successfully.</div>';
            
        } catch (Exception $e) {
            $conn->rollback();
            $_SESSION['panel_message'] = '<div class="alert alert-error"><i class="icon-error"></i> Error deleting leave: ' . $e->getMessage() . '</div>';
        }
    } else {
        $_SESSION['panel_message'] = '<div class="alert alert-error"><i class="icon-error"></i> Leave not found.</div>';
    }
    
    $redirect_url = "panel.php?leave_filter=" . urlencode($_GET['leave_filter'] ?? 'all') . 
                    "&permission_filter=" . urlencode($_GET['permission_filter'] ?? 'all') . 
                    "&leave_year=" . urlencode($_GET['leave_year'] ?? 'all') . 
                    "&leave_month=" . urlencode($_GET['leave_month'] ?? 'all') .
                    "&permission_month=" . urlencode($_GET['permission_month'] ?? 'all') .
                    "&leave_type_filter=" . urlencode($_GET['leave_type_filter'] ?? 'all') .
                    "&view_type=" . urlencode($_GET['view_type'] ?? 'leaves');
    header("Location: $redirect_url");
    exit();
}

// Approve/Reject Permission
if (isset($_GET['approve_permission'])) {
    $permission_id = intval($_GET['approve_permission']);
    
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

// Approve/Reject LOP Permission
if (isset($_GET['approve_lop_permission'])) {
    $permission_id = intval($_GET['approve_lop_permission']);
    
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
$leave_filter = isset($_GET['leave_filter']) ? $_GET['leave_filter'] : 'all';
$permission_filter = isset($_GET['permission_filter']) ? $_GET['permission_filter'] : 'all';
$leave_year_filter = isset($_GET['leave_year']) ? $_GET['leave_year'] : 'all';
$leave_month_filter = isset($_GET['leave_month']) ? $_GET['leave_month'] : 'all';
$permission_month_filter = isset($_GET['permission_month']) ? $_GET['permission_month'] : 'all';
$leave_type_filter = isset($_GET['leave_type_filter']) ? $_GET['leave_type_filter'] : 'all';
$view_type = isset($_GET['view_type']) ? $_GET['view_type'] : 'leaves';

// Get leaves with filter
$leave_where = "l.status != 'Cancelled'";

if ($leave_filter != 'all') {
    if ($leave_filter == 'lop') {
        $leave_where .= " AND l.leave_type = 'LOP'";
    } else {
        $leave_where .= " AND l.status = '" . $conn->real_escape_string($leave_filter) . "'";
    }
}

if ($leave_type_filter != 'all') {
    if ($leave_type_filter == 'sick') {
        $leave_where .= " AND l.leave_type = 'Sick'";
    } elseif ($leave_type_filter == 'casual') {
        $leave_where .= " AND l.leave_type = 'Casual'";
    } elseif ($leave_type_filter == 'lop') {
        $leave_where .= " AND l.leave_type = 'LOP'";
    } elseif ($leave_type_filter == 'auto_lop') {
        $leave_where .= " AND l.leave_type = 'LOP' AND l.reason LIKE 'Auto-generated LOP%'";
    } elseif ($leave_type_filter == 'manual_lop') {
        $leave_where .= " AND l.leave_type = 'LOP' AND l.reason NOT LIKE 'Auto-generated LOP%'";
    }
}

if ($leave_year_filter != 'all' && !empty($leave_year_filter)) {
    $leave_where .= " AND l.leave_year = '" . $conn->real_escape_string($leave_year_filter) . "'";
}

if ($leave_month_filter != 'all' && !empty($leave_month_filter)) {
    $month_data = $leave_months[$leave_month_filter] ?? null;
    if ($month_data) {
        $leave_where .= " AND l.from_date >= '{$month_data['start_date']}' AND l.from_date <= '{$month_data['end_date']}'";
    }
}

// Get permissions with filter
$permission_where = "p.status != 'Cancelled'";
if ($permission_filter != 'all') {
    if ($permission_filter == 'lop') {
        $permission_where .= " AND p.status = 'LOP'";
    } else {
        $permission_where .= " AND p.status = '" . $conn->real_escape_string($permission_filter) . "'";
    }
}

if ($permission_month_filter != 'all' && !empty($permission_month_filter)) {
    $month_data = $leave_months[$permission_month_filter] ?? null;
    if ($month_data) {
        $permission_where .= " AND p.permission_date >= '{$month_data['start_date']}' AND p.permission_date <= '{$month_data['end_date']}'";
    }
}

// Get data based on view type
if ($view_type == 'leaves') {
    $leaves = $conn->query("
        SELECT l.*, u.full_name, u.username,
               a.full_name as approved_by_name, a.username as approved_by_username,
               r.full_name as rejected_by_name, r.username as rejected_by_username,
               DATE(l.approved_date) as approved_date_formatted,
               DATE(l.rejected_date) as rejected_date_formatted,
               CASE WHEN l.reason LIKE 'Auto-generated LOP%' THEN 1 ELSE 0 END as is_auto_lop
        FROM leaves l
        JOIN users u ON l.user_id = u.id
        LEFT JOIN users a ON l.approved_by = a.id
        LEFT JOIN users r ON l.rejected_by = r.id
        WHERE $leave_where
        ORDER BY l.applied_date DESC
    ");
    $permissions = null;
} else {
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
        ORDER BY p.permission_date DESC
    ");
    $leaves = null;
}

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

$current_month = date('m');
$current_year = date('Y');

$page_title = '';
if ($role === 'pm') {
    $page_title = 'Project Manager Panel';
} elseif ($role === 'hr') {
    $page_title = 'HR Panel';
} elseif ($role === 'admin') {
    $page_title = 'Admin Panel';
}

$prev_leave_year = getPreviousCasualLeaveYear();
$days_until_casual_reset = daysUntilCasualYearReset();
$casual_reset_date = getNextCasualResetDate();

if (isset($_SESSION['panel_message'])) {
    $message = $_SESSION['panel_message'];
    unset($_SESSION['panel_message']);
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $page_title; ?> - MAKSIM HR</title>
    <?php include '../includes/head.php'; ?>
    <style>
        .export-modal { display: none; position: fixed; z-index: 1000; left: 0; top: 0; width: 100%; height: 100%; background-color: rgba(0,0,0,0.5); }
        .export-modal-content { background-color: white; margin: 15% auto; padding: 20px; border-radius: 10px; width: 550px; box-shadow: 0 4px 20px rgba(0,0,0,0.2); }
        .export-options { display: flex; flex-direction: column; gap: 15px; margin: 20px 0; }
        .export-option-group { display: flex; gap: 10px; align-items: center; flex-wrap: wrap; }
        .export-month-year { display: flex; gap: 10px; margin-left: 20px; }
        .export-month-year select { flex: 1; }
        .export-lop-type { display: flex; gap: 15px; margin-left: 20px; flex-wrap: wrap; }
        .export-lop-type label { display: flex; align-items: center; gap: 5px; }
        .modal-buttons { display: flex; gap: 10px; justify-content: flex-end; margin-top: 20px; }
        .export-btn-group { display: flex; gap: 10px; align-items: center; flex-wrap: wrap; }
        .role-badge { display: inline-block; padding: 4px 8px; border-radius: 4px; font-size: 12px; font-weight: 600; margin-left: 10px; }
        .role-hr { background: #4299e1; color: white; }
        .role-pm { background: #48bb78; color: white; }
        .role-admin { background: #ed8936; color: white; }
        .table-container { overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; }
        th, td { padding: 12px; text-align: left; border-bottom: 1px solid #e2e8f0; }
        th { background-color: #f7fafc; font-weight: 600; color: #4a5568; }
        tr:hover { background-color: #f7fafc; }
        .status-info { font-size: 12px; color: #7180962d; margin-top: 4px; }
        .action-timestamp { background: #f7fafc; padding: 8px 12px; border-radius: 6px; margin-top: 10px; border-left: 3px solid #48bb78; }
        .today-stats { display: flex; gap: 15px; margin-top: 20px; flex-wrap: wrap; }
        .today-stat { background: #f7fafc; padding: 12px 16px; border-radius: 8px; border-left: 4px solid #48bb78; flex: 1; min-width: 200px; }
        .today-stat.approved { border-left-color: #48bb78; }
        .today-stat.rejected { border-left-color: #f56565; }
        .today-stat.lop { border-left-color: #ed8936; }
        .today-stat-value { font-size: 24px; font-weight: 600; color: #4a5568; }
        .today-stat-label { font-size: 14px; color: #7a9671; }
        .action-buttons { display: flex; gap: 8px; flex-wrap: wrap; }
        .btn-approve:disabled, .btn-reject:disabled, .btn-delete-leave:disabled { opacity: 0.5; cursor: not-allowed; pointer-events: none; background: #cbd5e0 !important; color: #718096 !important; transform: none !important; box-shadow: none !important; }
        .page-title { display: flex; align-items: center; justify-content: space-between; }
        .title-left { display: flex; align-items: center; gap: 10px; }
        .role-icon { font-size: 24px; }
        .role-pm-icon { color: #48bb78; }
        .role-hr-icon { color: #4299e1; }
        .role-admin-icon { color: #ed8936; }
        .leave-year-stats { display: flex; gap: 20px; margin-bottom: 20px; flex-wrap: wrap; }
        .leave-year-card { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); padding: 20px; border-radius: 10px; color: white; flex: 1; min-width: 200px; }
        .stats-card { background: white; padding: 20px; border-radius: 10px; box-shadow: 0 2px 5px rgba(0,0,0,0.05); flex: 1; min-width: 200px; }
        .stats-label { font-size: 14px; color: #718096; margin-bottom: 5px; }
        .stats-value { font-size: 24px; font-weight: bold; color: #2d3748; }
        .stats-sub { font-size: 12px; color: #48bb78; margin-top: 5px; }
        .lop-badge { background: #fed7d7; color: #c53030; padding: 3px 8px; border-radius: 12px; font-size: 11px; font-weight: 600; display: inline-block; }
        .auto-lop-badge { background: #fed7d7; color: #c53030; padding: 3px 8px; border-radius: 12px; font-size: 11px; font-weight: 600; display: inline-block; border: 1px dashed #c53030; }
        .lop-row { background: #fff5f5; }
        .auto-lop-row { background: #fff0f0; border-left: 3px solid #c53030; }
        .lop-text { color: #c53030; font-weight: 600; }
        .btn-lop-approve { background: #ed8936; color: white; }
        .btn-lop-reject { background: #9b59b6; color: white; }
        .btn-delete-leave { background: #c53030; color: white; padding: 4px 8px; border-radius: 4px; text-decoration: none; font-size: 12px; }
        .btn-delete-leave:hover { background: #9b2c2c; }
        .stat-card { background: white; border-radius: 12px; padding: 15px; box-shadow: 0 2px 8px rgba(0,0,0,0.05); transition: transform 0.2s; }
        .stat-card:hover { transform: translateY(-2px); box-shadow: 0 4px 12px rgba(0,0,0,0.1); }
        .stat-card .stat-icon { width: 40px; height: 40px; border-radius: 10px; display: flex; align-items: center; justify-content: center; margin-bottom: 10px; font-size: 18px; }
        .stat-card .stat-value { font-size: 24px; font-weight: 700; margin-bottom: 3px; }
        .stat-card .stat-label { font-size: 12px; color: #080808; margin-bottom: 5px; }
        .stat-card .stat-sub { font-size: 11px; color: #000000; padding-top: 5px; border-top: 1px solid #e2e8f0; margin-top: 5px; }
        .stat-card.lop-card { background: linear-gradient(135deg, #f56565 0%, #c53030 100%); color: white; }
        .stat-card.auto-lop-card { background: linear-gradient(135deg, #fc8181 0%, #c53030 100%); color: white; border: 2px solid #9b2c2c; }
        .stat-card.manual-lop-card { background: linear-gradient(135deg, #f56565 0%, #c05621 100%); color: white; }
        .stats-grid { display: grid; grid-template-columns: repeat(5, 1fr); gap: 15px; margin-bottom: 20px; }
        @media (max-width: 1200px) { .stats-grid { grid-template-columns: repeat(3, 1fr); } }
        @media (max-width: 768px) { .stats-grid { grid-template-columns: repeat(2, 1fr); } }
        @media (max-width: 480px) { .stats-grid { grid-template-columns: 1fr; } }
        .filter-container { display: flex; gap: 15px; align-items: center; flex-wrap: wrap; margin-bottom: 15px; }
        .filter-item { display: flex; align-items: center; gap: 8px; }
        .filter-item label { font-weight: 600; color: #4a5568; font-size: 14px; }
        .filter-item select { width: 180px; }
        .view-type-selector { display: flex; gap: 10px; margin-right: 15px; }
        .view-type-btn { padding: 8px 16px; border-radius: 20px; font-size: 14px; font-weight: 600; cursor: pointer; border: 2px solid transparent; transition: all 0.3s ease; }
        .view-type-btn.active { background: #4299e1; color: white; border-color: #3182ce; }
        .view-type-btn.inactive { background: #e2e8f0; color: #4a5568; }
        .view-type-btn.inactive:hover { background: #cbd5e0; }
        .apply-filters-btn { background: #4299e1; color: white; border: none; padding: 8px 16px; border-radius: 6px; font-weight: 600; cursor: pointer; }
        .apply-filters-btn:hover { background: #3182ce; }
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
            
            <div class="leave-year-stats">
                <div class="leave-year-card">
                    <div style="font-size: 14px; opacity: 0.9;">Current Leave Year</div>
                    <div style="font-size: 28px; font-weight: bold;"><?php echo $leave_year['year_label']; ?></div>
                    <div style="font-size: 12px; margin-top: 5px; opacity: 0.8;">Mar 16 - Mar 15</div>
                </div>
                <div class="stats-card">
                    <div class="stats-label">Total Leaves This Year</div>
                    <div class="stats-value"><?php echo $leave_stats['current_days']; ?> days</div>
                    <div class="stats-sub"><i class="icon-file"></i> <?php echo $leave_stats['current_applications']; ?> applications</div>
                </div>
                <div class="stats-card">
                    <div class="stats-label">Days Until Reset</div>
                    <div class="stats-value"><?php echo $days_until_casual_reset; ?> days</div>
                    <div class="stats-sub" style="color: #ed8936;"><i class="icon-calendar"></i> Reset: <?php echo date('M d, Y', strtotime($casual_reset_date)); ?></div>
                </div>
            </div>
            
            <div class="stats-grid">
                <div class="stat-card lop-card">
                    <div class="stat-icon" style="background: rgba(255, 255, 255, 0.2); color: white;"><i class="icon-lop"></i></div>
                    <div class="stat-value"><?php echo $lop_stats['total_lop_days']; ?></div>
                    <div class="stat-label">Total LOP Days</div>
                    <div class="stat-sub"><i class="icon-users"></i> <?php echo $lop_stats['employees_with_lop']; ?> employees</div>
                </div>
                
                <div class="stat-card auto-lop-card">
                    <div class="stat-icon" style="background: rgba(255, 255, 255, 0.2); color: white;"><i class="icon-lop"></i></div>
                    <div class="stat-value"><?php echo $lop_stats['auto_lop_days']; ?></div>
                    <div class="stat-label">Auto LOP Days</div>
                    <div class="stat-sub"><i class="icon-file"></i> <?php echo $lop_stats['auto_lop_count']; ?> applications</div>
                </div>
                
                <div class="stat-card manual-lop-card">
                    <div class="stat-icon" style="background: rgba(0, 0, 0, 0.2); color: white;"><i class="icon-lop"></i></div>
                    <div class="stat-value"><?php echo $lop_stats['manual_lop_days']; ?></div>
                    <div class="stat-label">Manual LOP Days</div>
                    <div class="stat-sub"><i class="icon-file"></i> <?php echo $lop_stats['manual_lop_count']; ?> applications</div>
                </div>
                
                <div class="stat-card">
                    <div class="stat-icon" style="background: #e6f7ff; color: #1890ff;"><i class="icon-users"></i></div>
                    <div class="stat-value"><?php echo $stats['total_users']; ?></div>
                    <div class="stat-label">Total Users</div>
                </div>
                
                <div class="stat-card">
                    <div class="stat-icon" style="background: #f6ffed; color: #52c41a;"><i class="icon-leave"></i></div>
                    <div class="stat-value"><?php echo $lop_stats['total_lop_applications']; ?></div>
                    <div class="stat-label">LOP Applications</div>
                    <div class="stat-sub">Current Year</div>
                </div>
            </div>
            
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-icon" style="background: #fff7e6; color: #fa8c16;"><i class="icon-hourglass"></i></div>
                    <div class="stat-value"><?php echo $stats['pending_leaves']; ?></div>
                    <div class="stat-label">Pending Leaves</div>
                </div>
                
                <div class="stat-card">
                    <div class="stat-icon" style="background: #f9f0ff; color: #722ed1;"><i class="icon-clock"></i></div>
                    <div class="stat-value"><?php echo $stats['pending_permissions']; ?></div>
                    <div class="stat-label">Pending Permissions</div>
                </div>
                
                <div class="stat-card">
                    <div class="stat-icon" style="background: #fed7d7; color: #c53030;"><i class="icon-clock"></i></div>
                    <div class="stat-value"><?php echo $stats['lop_permissions']; ?></div>
                    <div class="stat-label">LOP Permissions</div>
                    <div class="stat-sub"><i class="icon-warning"></i> Pending approvals</div>
                </div>
                
                <div class="stat-card">
                    <div class="stat-icon" style="background: #f6ffed; color: #52c41a;"><i class="icon-check"></i></div>
                    <div class="stat-value"><?php echo $stats['today_approved_leaves']; ?></div>
                    <div class="stat-label">Approved Leaves</div>
                    <div class="stat-sub">Today</div>
                </div>
                
                <div class="stat-card">
                    <div class="stat-icon" style="background: #f6ffed; color: #52c41a;"><i class="icon-check"></i></div>
                    <div class="stat-value"><?php echo $stats['today_approved_permissions']; ?></div>
                    <div class="stat-label">Approved Permissions</div>
                    <div class="stat-sub">Today</div>
                </div>
            </div>

            <div class="today-stats">
                <div class="today-stat rejected"><div class="today-stat-value"><?php echo $stats['today_rejected_leaves']; ?></div><div class="today-stat-label">Leaves Rejected Today</div></div>
                <div class="today-stat rejected"><div class="today-stat-value"><?php echo $stats['today_rejected_permissions']; ?></div><div class="today-stat-label">Permissions Rejected Today</div></div>
                <div class="today-stat lop"><div class="today-stat-value"><?php echo $stats['today_lop_permissions']; ?></div><div class="today-stat-label">LOP Permissions Today</div></div>
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
                                <label for="leaves_monthly">Export by Leave Month (16th to 15th)</label>
                                <div class="export-month-year">
                                    <select name="export_leave_month" id="export_leave_month">
                                        <option value="">Select Leave Month</option>
                                        <?php foreach ($leave_months as $month_num => $month_data): ?>
                                            <option value="<?php echo $month_num; ?>"><?php echo $month_data['label']; ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                            
                            <div class="export-option-group" style="margin-top: 10px;">
                                <span style="font-weight: 600;">Leave Type:</span>
                                <div class="export-lop-type">
                                    <label><input type="radio" name="export_lop_type" value="all" checked> All Types</label>
                                    <label><input type="radio" name="export_lop_type" value="sick"> Sick Leave</label>
                                    <label><input type="radio" name="export_lop_type" value="casual"> Casual Leave</label>
                                    <label><input type="radio" name="export_lop_type" value="lop"> LOP (All)</label>
                                    <label><input type="radio" name="export_lop_type" value="auto"> Auto-generated LOP</label>
                                    <label><input type="radio" name="export_lop_type" value="manual"> Manual LOP</label>
                                </div>
                            </div>
                        </div>
                        <div class="modal-buttons">
                            <button type="button" class="btn btn-secondary" onclick="closeExportModal('leaves')">Cancel</button>
                            <button type="submit" name="export_leaves" class="btn btn-success"><i class="icon-excel"></i> Export to Excel</button>
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
                                <label for="permissions_monthly">Export by Permission Month (16th to 15th)</label>
                                <div class="export-month-year">
                                    <select name="export_permission_month" id="export_permission_month">
                                        <option value="">Select Permission Month</option>
                                        <?php foreach ($leave_months as $month_num => $month_data): ?>
                                            <option value="<?php echo $month_num; ?>"><?php echo $month_data['label']; ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                        </div>
                        <div class="modal-buttons">
                            <button type="button" class="btn btn-secondary" onclick="closeExportModal('permissions')">Cancel</button>
                            <button type="submit" name="export_permissions" class="btn btn-success"><i class="icon-excel"></i> Export to Excel</button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Unified Requests Card -->
            <div class="card">
                <div class="card-header">
                    <div style="display: flex; align-items: center; gap: 20px; flex-wrap: wrap;">
                        <h3 class="card-title">
                            <i class="<?php echo $view_type == 'leaves' ? 'icon-leave' : 'icon-clock'; ?>"></i> 
                            <?php echo $view_type == 'leaves' ? 'Leave Requests' : 'Permission & LOP Requests'; ?>
                        </h3>
                        
                        <div class="view-type-selector">
                            <a href="?view_type=leaves&leave_filter=<?php echo $leave_filter; ?>&permission_filter=<?php echo $permission_filter; ?>&leave_year=<?php echo $leave_year_filter; ?>&leave_month=<?php echo $leave_month_filter; ?>&permission_month=<?php echo $permission_month_filter; ?>&leave_type_filter=<?php echo $leave_type_filter; ?>" 
                               class="view-type-btn <?php echo $view_type == 'leaves' ? 'active' : 'inactive'; ?>">
                                <i class="icon-leave"></i> Leaves
                            </a>
                            <a href="?view_type=permissions&leave_filter=<?php echo $leave_filter; ?>&permission_filter=<?php echo $permission_filter; ?>&leave_year=<?php echo $leave_year_filter; ?>&leave_month=<?php echo $leave_month_filter; ?>&permission_month=<?php echo $permission_month_filter; ?>&leave_type_filter=<?php echo $leave_type_filter; ?>" 
                               class="view-type-btn <?php echo $view_type == 'permissions' ? 'active' : 'inactive'; ?>">
                                <i class="icon-clock"></i> Permissions
                            </a>
                        </div>
                    </div>
                    
                    <div class="export-btn-group" style="margin-top: 15px;">
                        <form method="GET" action="" id="filterForm">
                            <input type="hidden" name="view_type" value="<?php echo $view_type; ?>">
                            
                            <?php if ($view_type == 'leaves'): ?>
                            <div class="filter-container">
                                <div class="filter-item">
                                    <label><i class="icon-calendar"></i> Leave Month:</label>
                                    <select name="leave_month" class="form-control">
                                        <option value="all" <?php echo ($leave_month_filter == 'all') ? 'selected' : ''; ?>>All Months</option>
                                        <?php foreach ($leave_months as $month_num => $month_data): ?>
                                            <option value="<?php echo $month_num; ?>" <?php echo ($leave_month_filter == $month_num) ? 'selected' : ''; ?>>
                                                <?php echo $month_data['label']; ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                
                                <div class="filter-item">
                                    <label><i class="icon-calendar"></i> Year:</label>
                                    <select name="leave_year" class="form-control">
                                        <option value="all" <?php echo ($leave_year_filter == 'all') ? 'selected' : ''; ?>>All Years</option>
                                        <option value="<?php echo $leave_year['year_label']; ?>" <?php echo ($leave_year_filter == $leave_year['year_label']) ? 'selected' : ''; ?>>
                                            Current (<?php echo $leave_year['year_label']; ?>)
                                        </option>
                                        <option value="<?php echo $prev_leave_year['year_label']; ?>" <?php echo ($leave_year_filter == $prev_leave_year['year_label']) ? 'selected' : ''; ?>>
                                            Previous (<?php echo $prev_leave_year['year_label']; ?>)
                                        </option>
                                    </select>
                                </div>
                                
                                <div class="filter-item">
                                    <label><i class="icon-filter"></i> Status:</label>
                                    <select name="leave_filter" class="form-control">
                                        <option value="all" <?php echo ($leave_filter == 'all') ? 'selected' : ''; ?>>All Status</option>
                                        <option value="pending" <?php echo ($leave_filter == 'pending') ? 'selected' : ''; ?>>Pending</option>
                                        <option value="approved" <?php echo ($leave_filter == 'approved') ? 'selected' : ''; ?>>Approved</option>
                                        <option value="rejected" <?php echo ($leave_filter == 'rejected') ? 'selected' : ''; ?>>Rejected</option>
                                        <option value="lop" <?php echo ($leave_filter == 'lop') ? 'selected' : ''; ?>>LOP (All)</option>
                                    </select>
                                </div>
                                
                                <div class="filter-item">
                                    <label><i class="icon-filter"></i> Leave Type:</label>
                                    <select name="leave_type_filter" class="form-control">
                                        <option value="all" <?php echo ($leave_type_filter == 'all') ? 'selected' : ''; ?>>All Leave Types</option>
                                        <option value="sick" <?php echo ($leave_type_filter == 'sick') ? 'selected' : ''; ?>>Sick Leave</option>
                                        <option value="casual" <?php echo ($leave_type_filter == 'casual') ? 'selected' : ''; ?>>Casual Leave</option>
                                        <option value="lop" <?php echo ($leave_type_filter == 'lop') ? 'selected' : ''; ?>>LOP (All)</option>
                                        <option value="auto_lop" <?php echo ($leave_type_filter == 'auto_lop') ? 'selected' : ''; ?>>Auto-generated LOP</option>
                                        <option value="manual_lop" <?php echo ($leave_type_filter == 'manual_lop') ? 'selected' : ''; ?>>Manual LOP</option>
                                    </select>
                                </div>
                                
                                <input type="hidden" name="permission_filter" value="<?php echo $permission_filter; ?>">
                                <input type="hidden" name="permission_month" value="<?php echo $permission_month_filter; ?>">
                                
                                <button type="submit" class="apply-filters-btn">Apply Filters</button>
                            </div>
                            
                            <?php else: ?>
                            <div class="filter-container">
                                <div class="filter-item">
                                    <label><i class="icon-calendar"></i> Permission Month:</label>
                                    <select name="permission_month" class="form-control">
                                        <option value="all" <?php echo ($permission_month_filter == 'all') ? 'selected' : ''; ?>>All Months</option>
                                        <?php foreach ($leave_months as $month_num => $month_data): ?>
                                            <option value="<?php echo $month_num; ?>" <?php echo ($permission_month_filter == $month_num) ? 'selected' : ''; ?>>
                                                <?php echo $month_data['label']; ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                
                                <div class="filter-item">
                                    <label><i class="icon-filter"></i> Status:</label>
                                    <select name="permission_filter" class="form-control">
                                        <option value="all" <?php echo ($permission_filter == 'all') ? 'selected' : ''; ?>>All Status</option>
                                        <option value="pending" <?php echo ($permission_filter == 'pending') ? 'selected' : ''; ?>>Pending</option>
                                        <option value="approved" <?php echo ($permission_filter == 'approved') ? 'selected' : ''; ?>>Approved</option>
                                        <option value="rejected" <?php echo ($permission_filter == 'rejected') ? 'selected' : ''; ?>>Rejected</option>
                                        <option value="LOP" <?php echo ($permission_filter == 'LOP') ? 'selected' : ''; ?>>LOP (Pending)</option>
                                    </select>
                                </div>
                                
                                <input type="hidden" name="leave_filter" value="<?php echo $leave_filter; ?>">
                                <input type="hidden" name="leave_year" value="<?php echo $leave_year_filter; ?>">
                                <input type="hidden" name="leave_month" value="<?php echo $leave_month_filter; ?>">
                                <input type="hidden" name="leave_type_filter" value="<?php echo $leave_type_filter; ?>">
                                
                                <button type="submit" class="apply-filters-btn">Apply Filters</button>
                            </div>
                            <?php endif; ?>
                        </form>
                        
                        <button class="btn btn-success" onclick="openExportModal('leaves')">
                            <i class="icon-excel"></i> Export Excel
                        </button>
                    </div>
                </div>
                
                <div class="table-container">
                    <?php if ($view_type == 'leaves'): ?>
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
                                    $is_auto_lop = ($leave['is_auto_lop'] == 1);
                                ?>
                                <tr class="<?php echo $is_lop ? ($is_auto_lop ? 'auto-lop-row' : 'lop-row') : ''; ?>">
                                    <td><?php echo htmlspecialchars($leave['full_name']); ?></td>
                                    <td>
                                        <?php if ($is_lop): ?>
                                            <?php if ($is_auto_lop): ?>
                                                <span class="auto-lop-badge"><i class="icon-lop"></i> Auto LOP</span>
                                            <?php else: ?>
                                                <span class="lop-badge"><i class="icon-lop"></i> LOP</span>
                                            <?php endif; ?>
                                        <?php else: ?>
                                            <?php echo htmlspecialchars($leave['leave_type']); ?>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo htmlspecialchars($leave['from_date']); ?></td>
                                    <td><?php echo htmlspecialchars($leave['to_date']); ?></td>
                                    <td <?php echo $is_lop ? 'class="lop-text"' : ''; ?>><?php echo htmlspecialchars($leave['days']); ?></td>
                                    <td>
                                        <span class="status-badge status-<?php echo strtolower($leave['status']); ?>">
                                            <?php echo htmlspecialchars($leave['status']); ?>
                                        </span>
                                        <?php if ($leave['status'] == 'Approved' && $leave['approved_by_name']): ?>
                                            <div class="status-info">Approved by: <?php echo htmlspecialchars($leave['approved_by_name']); ?> on <?php echo $leave['approved_date_formatted']; ?></div>
                                        <?php elseif ($leave['status'] == 'Rejected' && $leave['rejected_by_name']): ?>
                                            <div class="status-info">Rejected by: <?php echo htmlspecialchars($leave['rejected_by_name']); ?> on <?php echo $leave['rejected_date_formatted']; ?></div>
                                        <?php endif; ?>
                                    </td>
                                    <td title="<?php echo htmlspecialchars($leave['reason']); ?>">
                                        <?php echo strlen($leave['reason']) > 30 ? substr(htmlspecialchars($leave['reason']), 0, 30) . '...' : htmlspecialchars($leave['reason']); ?>
                                        <?php if ($is_auto_lop): ?>
                                            <span style="display: block; font-size: 10px; color: #c53030;">Auto-generated</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo date('Y-m-d', strtotime($leave['applied_date'])); ?></td>
                                    <td><span style="background: #e2e8f0; padding: 3px 8px; border-radius: 12px; font-size: 11px;"><?php echo $row_leave_year; ?></span></td>
                                    <td>
                                        <div class="action-buttons">
                                            <?php if ($leave['status'] == 'Pending'): ?>
                                                <?php if ($role == 'hr'): ?>
                                                    <button class="btn-small btn-approve" disabled title="HR cannot approve leaves"><i class="icon-check"></i> Approve</button>
                                                    <button class="btn-small btn-reject" disabled title="HR cannot reject leaves"><i class="icon-cancel"></i> Reject</button>
                                                <?php else: ?>
                                                    <a href="?approve_leave=<?php echo $leave['id']; ?>&leave_filter=<?php echo $leave_filter; ?>&permission_filter=<?php echo $permission_filter; ?>&leave_year=<?php echo $leave_year_filter; ?>&leave_month=<?php echo $leave_month_filter; ?>&permission_month=<?php echo $permission_month_filter; ?>&leave_type_filter=<?php echo $leave_type_filter; ?>&view_type=<?php echo $view_type; ?>" 
                                                       class="btn-small btn-approve" onclick="return confirm('Approve this leave?')"><i class="icon-check"></i> Approve</a>
                                                    <a href="?reject_leave=<?php echo $leave['id']; ?>&leave_filter=<?php echo $leave_filter; ?>&permission_filter=<?php echo $permission_filter; ?>&leave_year=<?php echo $leave_year_filter; ?>&leave_month=<?php echo $leave_month_filter; ?>&permission_month=<?php echo $permission_month_filter; ?>&leave_type_filter=<?php echo $leave_type_filter; ?>&view_type=<?php echo $view_type; ?>" 
                                                       class="btn-small btn-reject" onclick="return confirm('Reject this leave?')"><i class="icon-cancel"></i> Reject</a>
                                                <?php endif; ?>
                                            <?php endif; ?>
                                            
                                            <?php if ($leave['status'] == 'Approved' && in_array($role, ['admin', 'pm'])): ?>
                                                <a href="?delete_leave=<?php echo $leave['id']; ?>&leave_filter=<?php echo $leave_filter; ?>&permission_filter=<?php echo $permission_filter; ?>&leave_year=<?php echo $leave_year_filter; ?>&leave_month=<?php echo $leave_month_filter; ?>&permission_month=<?php echo $permission_month_filter; ?>&leave_type_filter=<?php echo $leave_type_filter; ?>&view_type=<?php echo $view_type; ?>" 
                                                   class="btn-small btn-delete-leave" onclick="return confirm('Delete this approved leave?')"><i class="icon-delete"></i> Delete</a>
                                            <?php endif; ?>
                                            
                                            <a href="../leaves/leave_details.php?id=<?php echo $leave['id']; ?>" class="btn-small btn-view"><i class="icon-view"></i> View</a>
                                        </div>
                                    </td>
                                </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr><td colspan="10" style="text-align: center; padding: 40px; color: #718096;"><i class="icon-leave" style="font-size: 48px; margin-bottom: 15px; display: block; color: #cbd5e0;"></i>No leave requests found</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                    
                    <?php else: ?>
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
                                    $is_lop = ($permission['status'] == 'LOP');
                                ?>
                                <tr <?php echo $is_lop ? 'class="lop-row"' : ''; ?>>
                                    <td><?php echo htmlspecialchars($permission['full_name']); ?></td>
                                    <td><?php echo htmlspecialchars($permission['permission_date']); ?></td>
                                    <td><?php echo htmlspecialchars($permission['duration']); ?> hours</td>
                                    <td>
                                        <?php if ($is_lop): ?>
                                            <span class="status-badge" style="background:#fed7d7; color:#c53030;">LOP (Pending)</span>
                                        <?php else: ?>
                                            <span class="status-badge status-<?php echo strtolower($permission['status']); ?>"><?php echo htmlspecialchars($permission['status']); ?></span>
                                        <?php endif; ?>
                                        
                                        <?php if ($permission['status'] == 'Approved' && $permission['approved_by_name']): ?>
                                            <div class="status-info">Approved by: <?php echo htmlspecialchars($permission['approved_by_name']); ?> on <?php echo $permission['approved_date_formatted']; ?></div>
                                        <?php elseif ($permission['status'] == 'Rejected' && $permission['rejected_by_name']): ?>
                                            <div class="status-info">Rejected by: <?php echo htmlspecialchars($permission['rejected_by_name']); ?> on <?php echo $permission['rejected_date_formatted']; ?></div>
                                        <?php elseif ($is_lop && $permission['status'] == 'LOP'): ?>
                                            <div class="status-info" style="color:#ed8936;"><i class="icon-warning"></i> Auto-generated from excess hours</div>
                                        <?php endif; ?>
                                    </td>
                                    <td title="<?php echo htmlspecialchars($permission['reason']); ?>"><?php echo strlen($permission['reason']) > 30 ? substr(htmlspecialchars($permission['reason']), 0, 30) . '...' : htmlspecialchars($permission['reason']); ?></td>
                                    <td><?php echo date('Y-m-d', strtotime($permission['applied_date'])); ?></td>
                                    <td>
                                        <div class="action-buttons">
                                            <?php if ($permission['status'] == 'Pending'): ?>
                                                <?php if ($role == 'hr'): ?>
                                                    <button class="btn-small btn-approve" disabled><i class="icon-check"></i> Approve</button>
                                                    <button class="btn-small btn-reject" disabled><i class="icon-cancel"></i> Reject</button>
                                                <?php else: ?>
                                                    <a href="?approve_permission=<?php echo $permission['id']; ?>&leave_filter=<?php echo $leave_filter; ?>&permission_filter=<?php echo $permission_filter; ?>&leave_year=<?php echo $leave_year_filter; ?>&leave_month=<?php echo $leave_month_filter; ?>&permission_month=<?php echo $permission_month_filter; ?>&leave_type_filter=<?php echo $leave_type_filter; ?>&view_type=<?php echo $view_type; ?>" 
                                                       class="btn-small btn-approve" onclick="return confirm('Approve this permission?')"><i class="icon-check"></i> Approve</a>
                                                    <a href="?reject_permission=<?php echo $permission['id']; ?>&leave_filter=<?php echo $leave_filter; ?>&permission_filter=<?php echo $permission_filter; ?>&leave_year=<?php echo $leave_year_filter; ?>&leave_month=<?php echo $leave_month_filter; ?>&permission_month=<?php echo $permission_month_filter; ?>&leave_type_filter=<?php echo $leave_type_filter; ?>&view_type=<?php echo $view_type; ?>" 
                                                       class="btn-small btn-reject" onclick="return confirm('Reject this permission?')"><i class="icon-cancel"></i> Reject</a>
                                                <?php endif; ?>
                                            <?php elseif ($is_lop && $permission['status'] == 'LOP'): ?>
                                                <?php if ($role == 'hr'): ?>
                                                    <button class="btn-small" disabled><i class="icon-check"></i> Approve LOP</button>
                                                    <button class="btn-small" disabled><i class="icon-cancel"></i> Reject LOP</button>
                                                <?php else: ?>
                                                    <a href="?approve_lop_permission=<?php echo $permission['id']; ?>&leave_filter=<?php echo $leave_filter; ?>&permission_filter=<?php echo $permission_filter; ?>&leave_year=<?php echo $leave_year_filter; ?>&leave_month=<?php echo $leave_month_filter; ?>&permission_month=<?php echo $permission_month_filter; ?>&leave_type_filter=<?php echo $leave_type_filter; ?>&view_type=<?php echo $view_type; ?>" 
                                                       class="btn-small btn-lop-approve" onclick="return confirm('Approve this LOP permission?')"><i class="icon-check"></i> Approve LOP</a>
                                                    <a href="?reject_lop_permission=<?php echo $permission['id']; ?>&leave_filter=<?php echo $leave_filter; ?>&permission_filter=<?php echo $permission_filter; ?>&leave_year=<?php echo $leave_year_filter; ?>&leave_month=<?php echo $leave_month_filter; ?>&permission_month=<?php echo $permission_month_filter; ?>&leave_type_filter=<?php echo $leave_type_filter; ?>&view_type=<?php echo $view_type; ?>" 
                                                       class="btn-small btn-lop-reject" onclick="return confirm('Reject this LOP permission?')"><i class="icon-cancel"></i> Reject LOP</a>
                                                <?php endif; ?>
                                            <?php endif; ?>
                                            <a href="../permissions/permission_details.php?id=<?php echo $permission['id']; ?>" class="btn-small btn-view"><i class="icon-view"></i> View</a>
                                        </div>
                                    </td>
                                </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr><td colspan="7" style="text-align: center; padding: 40px; color: #718096;"><i class="icon-clock" style="font-size: 48px; margin-bottom: 15px; display: block; color: #cbd5e0;"></i>No permission requests found</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <script>
    function openExportModal(type) {
        document.getElementById('export' + type.charAt(0).toUpperCase() + type.slice(1) + 'Modal').style.display = 'block';
    }
    
    function closeExportModal(type) {
        document.getElementById('export' + type.charAt(0).toUpperCase() + type.slice(1) + 'Modal').style.display = 'none';
    }
    
    window.onclick = function(event) {
        if (event.target == document.getElementById('exportLeavesModal')) {
            document.getElementById('exportLeavesModal').style.display = 'none';
        }
        if (event.target == document.getElementById('exportPermissionsModal')) {
            document.getElementById('exportPermissionsModal').style.display = 'none';
        }
    }
    
    document.addEventListener('DOMContentLoaded', function() {
        var leavesMonthlyRadio = document.getElementById('leaves_monthly');
        var leavesMonthSelect = document.getElementById('export_leave_month');
        
        if (leavesMonthlyRadio) {
            leavesMonthlyRadio.addEventListener('change', function() {
                leavesMonthSelect.disabled = !this.checked;
            });
        }
        
        var permissionsMonthlyRadio = document.getElementById('permissions_monthly');
        var permissionsMonthSelect = document.getElementById('export_permission_month');
        
        if (permissionsMonthlyRadio) {
            permissionsMonthlyRadio.addEventListener('change', function() {
                permissionsMonthSelect.disabled = !this.checked;
            });
        }
        
        if (leavesMonthSelect) leavesMonthSelect.disabled = !(leavesMonthlyRadio && leavesMonthlyRadio.checked);
        if (permissionsMonthSelect) permissionsMonthSelect.disabled = !(permissionsMonthlyRadio && permissionsMonthlyRadio.checked);
    });
    </script>
    
    <script src="../assets/js/app.js"></script>
</body>
</html>