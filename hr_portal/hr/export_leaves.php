<?php
require_once '../config/db.php';
require_once '../includes/leave_functions.php';
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
$export_data = [];

// Get all users for dropdown
$users_result = $conn->query("SELECT id, username, full_name, department FROM users ORDER BY full_name");
$users = $users_result->fetch_all(MYSQLI_ASSOC);

// Set default dates or get from POST
$default_from_date = isset($_POST['from_date']) ? $_POST['from_date'] : date('Y-m-01');
$default_to_date = isset($_POST['to_date']) ? $_POST['to_date'] : date('Y-m-d');
$selected_user_id = isset($_POST['user_id']) ? intval($_POST['user_id']) : 0;
$export_type = isset($_POST['export_type']) ? $_POST['export_type'] : 'all';

// Handle export request
if (isset($_POST['export_excel']) || isset($_POST['generate_excel']) || isset($_POST['generate_lop_excel'])) {
    $from_date = sanitize($_POST['from_date']);
    $to_date = sanitize($_POST['to_date']);
    $user_id = isset($_POST['user_id']) ? intval($_POST['user_id']) : 0;
    $export_type = isset($_POST['export_type']) ? $_POST['export_type'] : 'all';
    
    if (empty($from_date) || empty($to_date)) {
        $message = '<div class="alert alert-error"><i class="icon-error"></i> Please select from and to dates</div>';
    } else {
        // Build query - GET ALL LEAVES (including pending/rejected for preview, but export only approved)
        $where_conditions = ["l.from_date BETWEEN ? AND ?"];
        $params = [$from_date, $to_date];
        $types = "ss";
        
        if ($user_id > 0) {
            $where_conditions[] = "l.user_id = ?";
            $params[] = $user_id;
            $types .= "i";
        }
        
        $where_sql = "WHERE " . implode(" AND ", $where_conditions);
        
        // Get leave data with user details
        $sql = "SELECT 
                    u.id as user_id,
                    u.username,
                    u.full_name,
                    u.department,
                    u.position,
                    l.id as leave_id,
                    l.leave_type,
                    l.from_date,
                    l.to_date,
                    l.days,
                    l.day_type,
                    l.reason,
                    l.status,
                    l.applied_date,
                    l.leave_year,
                    DATE_FORMAT(l.from_date, '%M %Y') as month_year,
                    MONTH(l.from_date) as month_num,
                    YEAR(l.from_date) as year_num
                FROM leaves l
                JOIN users u ON l.user_id = u.id
                $where_sql
                ORDER BY u.full_name, l.from_date DESC";
        
        $stmt = $conn->prepare($sql);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $result = $stmt->get_result();
        $all_leaves = $result->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        
        // Filter for preview (show all)
        $export_data = $all_leaves;
        
        // If generate Excel button clicked - EXPORT APPROVED LEAVES
        if (isset($_POST['generate_excel'])) {
            $approved_leaves = array_filter($all_leaves, function($leave) {
                return $leave['status'] === 'Approved';
            });
            generateExcel($approved_leaves, $from_date, $to_date, $user_id, $conn, 'all');
        }
        
        // If generate LOP Excel button clicked - EXPORT ONLY APPROVED LOP LEAVES with per-user totals
        if (isset($_POST['generate_lop_excel'])) {
            $lop_leaves = array_filter($all_leaves, function($leave) {
                return $leave['leave_type'] === 'LOP' && $leave['status'] === 'Approved';
            });
            generateLOPExcel($lop_leaves, $from_date, $to_date, $user_id, $conn, $users);
        }
    }
}

/**
 * Generate LOP Excel file in the specific format from the image with per-user LOP totals
 * Including ALL employees with 0 for those who haven't taken LOP
 */
function generateLOPExcel($data, $from_date, $to_date, $user_id, $conn, $all_users) {
    // Get user name if specific user selected
    $user_name = 'All Employees';
    if ($user_id > 0) {
        $user_stmt = $conn->prepare("SELECT full_name FROM users WHERE id = ?");
        $user_stmt->bind_param("i", $user_id);
        $user_stmt->execute();
        $user_result = $user_stmt->get_result();
        $user_row = $user_result->fetch_assoc();
        $user_name = $user_row['full_name'];
        $user_stmt->close();
    }
    
    // Calculate per-user LOP totals from the data
    $user_lop_totals = [];
    foreach ($data as $row) {
        $uid = $row['user_id'];
        if (!isset($user_lop_totals[$uid])) {
            $user_lop_totals[$uid] = [
                'name' => $row['full_name'],
                'department' => $row['department'] ?? 'Revit',
                'total_lop_days' => 0,
                'lop_applications' => []
            ];
        }
        $user_lop_totals[$uid]['total_lop_days'] += $row['days'];
        $user_lop_totals[$uid]['lop_applications'][] = $row;
    }
    
    // Create a complete list of all employees with their LOP totals (0 if no LOP)
    $complete_user_totals = [];
    foreach ($all_users as $user) {
        $uid = $user['id'];
        if (isset($user_lop_totals[$uid])) {
            $complete_user_totals[$uid] = $user_lop_totals[$uid];
        } else {
            $complete_user_totals[$uid] = [
                'name' => $user['full_name'],
                'department' => $user['department'] ?? 'Revit',
                'total_lop_days' => 0,
                'lop_applications' => []
            ];
        }
    }
    
    // Set headers for Excel download
    $filename = "lop_report_" . date('Y-m-d') . ".xls";
    header("Content-Type: application/vnd.ms-excel");
    header("Content-Disposition: attachment; filename=\"$filename\"");
    header("Pragma: no-cache");
    header("Expires: 0");
    
    // Start Excel content with the exact format from the image
    echo '<html>';
    echo '<head>';
    echo '<meta charset="UTF-8">';
    echo '<style>';
    echo 'td, th { border: 1px solid #000000; padding: 4px; vertical-align: top; }';
    echo 'table { border-collapse: collapse; width: 100%; }';
    echo '.header-note { background: #ffff00; font-weight: bold; text-align: center; }';
    echo '.revit-header { background: #d3d3d3; font-weight: bold; text-align: center; }';
    echo '.employee-header { background: #d3d3d3; font-weight: bold; text-align: center; }';
    echo '.signature-row td { height: 30px; }';
    echo '.lop-text { color: #c53030; font-weight: bold; }';
    echo '.zero-lop { color: #718096; }';
    echo '.total-row { background: #f0f0f0; font-weight: bold; }';
    echo '.summary-section { margin-bottom: 20px; }';
    echo '</style>';
    echo '</head>';
    echo '<body>';
    
    // Add report info at the top
    echo '<div class="summary-section">';
    echo '<h2>MAKSIM HR - LOP Report (All Employees)</h2>';
    echo '<p><strong>Period:</strong> ' . date('d-m-Y', strtotime($from_date)) . ' to ' . date('d-m-Y', strtotime($to_date)) . '</p>';
    echo '<p><strong>Employee:</strong> ' . $user_name . '</p>';
    echo '<p><strong>Generated on:</strong> ' . date('d-m-Y H:i:s') . '</p>';
    echo '<br>';
    
    // Per User LOP Summary Table - Show ALL employees
    echo '<h3>LOP Summary by Employee</h3>';
    echo '<table border="1" cellpadding="5">';
    echo '<tr style="background: #006400; color: white; font-weight: bold; text-align: center;">';
    echo '<th>S.NO</th>';
    echo '<th>Employee Name</th>';
    echo '<th>Department</th>';
    echo '<th>Total LOP Days</th>';
    echo '<th>Number of LOP Applications</th>';
    echo '</tr>';
    
    $sno = 1;
    $grand_total_lop = 0;
    $total_apps = 0;
    $employees_with_lop = 0;
    
    foreach ($complete_user_totals as $uid => $user_data) {
        $lop_days = $user_data['total_lop_days'];
        $app_count = count($user_data['lop_applications']);
        $lop_class = ($lop_days > 0) ? 'lop-text' : 'zero-lop';
        
        echo '<tr>';
        echo '<td style="text-align: center;">' . $sno++ . '</td>';
        echo '<td><strong>' . htmlspecialchars($user_data['name']) . '</strong></td>';
        echo '<td>' . htmlspecialchars($user_data['department']) . '</td>';
        echo '<td style="text-align: center; ' . ($lop_days > 0 ? 'color: #c53030; font-weight: bold;' : 'color: #718096;') . '">' . number_format($lop_days, 2) . ' days</td>';
        echo '<td style="text-align: center;">' . $app_count . '</td>';
        echo '</tr>';
        
        if ($lop_days > 0) {
            $employees_with_lop++;
        }
        $grand_total_lop += $lop_days;
        $total_apps += $app_count;
    }
    
    // Summary row
    echo '<tr class="total-row">';
    echo '<td colspan="3"><strong>SUMMARY</strong></td>';
    echo '<td style="text-align: center;"><strong>' . number_format($grand_total_lop, 2) . ' days total</strong></td>';
    echo '<td style="text-align: center;"><strong>' . $total_apps . ' applications</strong></td>';
    echo '</tr>';
    
    // Statistics row
    echo '<tr class="total-row">';
    echo '<td colspan="5" style="text-align: center;">';
    echo '<strong>Total Employees: ' . count($complete_user_totals) . ' | Employees with LOP: ' . $employees_with_lop . ' | Employees with 0 LOP: ' . (count($complete_user_totals) - $employees_with_lop);
    echo '</strong></td>';
    echo '</tr>';
    
    echo '</table>';
    echo '<br><br>';
    
    echo '</div>';
    
    // Title/NOTE row
    echo '<table>';
    echo '<tr>';
    echo '<td colspan="2" class="header-note">NOTE: WE HAVE GONE THROUGH WITH YOUR CL & SL THIS IS THE FINAL TOP SHEET NO CHANGES WILL BE DONE SO PLEASE DO SIGNATURES.</td>';
    echo '<td class="revit-header">REVIT</td>';
    echo '<td class="employee-header">EMPLOYEE NAME</td>';
    echo '<td>DOQ</td>';
    echo '<td>TOTAL WORKING DAYS</td>';
    echo '<td>LATENT ENTRY</td>';
    echo '<td>ABSENT (LOP Details)</td>';
    echo '<td>FINAL TOP</td>';
    echo '<td>SIGN</td>';
    echo '<td>REMARKS</td>';
    echo '</tr>';
    
    // S.NO and DEPT row
    echo '<tr>';
    echo '<td>S.NO</td>';
    echo '<td>DEPT</td>';
    echo '<td colspan="9"></td>';
    echo '</tr>';
    
    // Employee data rows - Show ALL employees with their LOP details or 0
    $sno = 1;
    
    foreach ($complete_user_totals as $uid => $user_data) {
        $dept = $user_data['department'] ?? 'Revit';
        $employee_name = strtoupper($user_data['name']);
        $lop_days = $user_data['total_lop_days'];
        $applications = $user_data['lop_applications'];
        
        if (!empty($applications)) {
            // Show each LOP application for this employee
            foreach ($applications as $app) {
                echo '<tr>';
                echo '<td>' . $sno++ . '</td>';
                echo '<td>' . htmlspecialchars($dept) . '</td>';
                echo '<td>REVIT</td>';
                echo '<td>' . htmlspecialchars($employee_name) . '</td>';
                echo '<td></td>'; // DOQ
                echo '<td></td>'; // TOTAL WORKING DAYS
                echo '<td></td>'; // LATENT ENTRY
                
                // ABSENT column - show LOP details with date range
                $date_range = '';
                if ($app['from_date'] == $app['to_date']) {
                    $date_range = ' on ' . date('d/m', strtotime($app['from_date']));
                } else {
                    $date_range = ' from ' . date('d/m', strtotime($app['from_date'])) . ' to ' . date('d/m', strtotime($app['to_date']));
                }
                $absent_text = 'LOP ' . $app['days'] . ' days' . $date_range;
                
                echo '<td class="lop-text">' . $absent_text . '</td>';
                echo '<td></td>'; // FINAL TOP
                echo '<td class="signature-row"></td>'; // SIGN
                echo '<td>' . htmlspecialchars($app['reason'] ?? '') . '</td>';
                echo '</tr>';
            }
        } else {
            // Employee with 0 LOP - show one row with zero
            echo '<tr>';
            echo '<td>' . $sno++ . '</td>';
            echo '<td>' . htmlspecialchars($dept) . '</td>';
            echo '<td>REVIT</td>';
            echo '<td>' . htmlspecialchars($employee_name) . '</td>';
            echo '<td></td>'; // DOQ
            echo '<td></td>'; // TOTAL WORKING DAYS
            echo '<td></td>'; // LATENT ENTRY
            echo '<td class="zero-lop">No LOP taken in this period</td>';
            echo '<td></td>'; // FINAL TOP
            echo '<td class="signature-row"></td>'; // SIGN
            echo '<td></td>'; // REMARKS
            echo '</tr>';
        }
    }
    
    // Add a separator row
    echo '<tr><td colspan="11" style="background: #e2e8f0; height: 5px;"></td></tr>';
    
    // Add per-user total rows at the bottom (summary)
    echo '<tr class="total-row">';
    echo '<td colspan="7" style="text-align: right;"><strong>PER USER LOP TOTALS:</strong></td>';
    echo '<td colspan="4"></td>';
    echo '</tr>';
    
    foreach ($complete_user_totals as $uid => $user_data) {
        $lop_days = $user_data['total_lop_days'];
        $lop_class = ($lop_days > 0) ? 'lop-text' : 'zero-lop';
        
        echo '<tr>';
        echo '<td colspan="3"></td>';
        echo '<td><strong>' . htmlspecialchars($user_data['name']) . '</strong></td>';
        echo '<td colspan="3"></td>';
        echo '<td class="' . ($lop_days > 0 ? 'lop-text' : 'zero-lop') . '"><strong>Total LOP: ' . number_format($lop_days, 2) . ' days</strong></td>';
        echo '<td colspan="3"></td>';
        echo '</tr>';
    }
    
    // Grand total row
    echo '<tr class="total-row">';
    echo '<td colspan="3"></td>';
    echo '<td><strong>GRAND TOTAL LOP</strong></td>';
    echo '<td colspan="3"></td>';
    echo '<td class="lop-text"><strong>' . number_format($grand_total_lop, 2) . ' days</strong></td>';
    echo '<td colspan="3"></td>';
    echo '</tr>';
    
    echo '</table>';
    echo '<br>';
    echo '<p><em>Generated by MAKSIM PORTAL - LOP Report with ALL Employees (0 LOP shown for employees with no LOP)</em></p>';
    echo '</body>';
    echo '</html>';
    exit();
}

/**
 * Generate regular Excel file with leave data (existing function - unchanged)
 */
function generateExcel($data, $from_date, $to_date, $user_id, $conn, $export_type = 'all') {
    // Get user name if specific user selected
    $user_name = 'All Employees';
    if ($user_id > 0) {
        $user_stmt = $conn->prepare("SELECT full_name FROM users WHERE id = ?");
        $user_stmt->bind_param("i", $user_id);
        $user_stmt->execute();
        $user_result = $user_stmt->get_result();
        $user_row = $user_result->fetch_assoc();
        $user_name = $user_row['full_name'];
        $user_stmt->close();
    }
    
    // Calculate summary statistics
    $total_applications = count($data);
    $total_days = 0;
    $total_lop_apps = 0;
    $total_lop_days = 0;
    $sick_days = 0;
    $casual_days = 0;
    $approved_count = 0;
    $pending_count = 0;
    $rejected_count = 0;
    
    foreach ($data as $row) {
        $total_days += $row['days'];
        
        if ($row['leave_type'] == 'LOP') {
            $total_lop_apps++;
            $total_lop_days += $row['days'];
        } elseif ($row['leave_type'] == 'Sick') {
            $sick_days += $row['days'];
        } elseif ($row['leave_type'] == 'Casual') {
            $casual_days += $row['days'];
        }
        
        if ($row['status'] == 'Approved') $approved_count++;
        elseif ($row['status'] == 'Pending') $pending_count++;
        elseif ($row['status'] == 'Rejected') $rejected_count++;
    }
    
    // Group by month for monthly summary
    $monthly_summary = [];
    foreach ($data as $row) {
        $month_key = date('Y-m', strtotime($row['from_date']));
        $month_display = date('F Y', strtotime($row['from_date']));
        
        if (!isset($monthly_summary[$month_key])) {
            $monthly_summary[$month_key] = [
                'month' => $month_display,
                'total_apps' => 0,
                'total_days' => 0,
                'sick_days' => 0,
                'casual_days' => 0,
                'lop_days' => 0
            ];
        }
        $monthly_summary[$month_key]['total_apps']++;
        $monthly_summary[$month_key]['total_days'] += $row['days'];
        
        if ($row['leave_type'] == 'LOP') {
            $monthly_summary[$month_key]['lop_days'] += $row['days'];
        } elseif ($row['leave_type'] == 'Sick') {
            $monthly_summary[$month_key]['sick_days'] += $row['days'];
        } elseif ($row['leave_type'] == 'Casual') {
            $monthly_summary[$month_key]['casual_days'] += $row['days'];
        }
    }
    ksort($monthly_summary);
    
    // Group by user for employee summary
    $user_summary = [];
    foreach ($data as $row) {
        $uid = $row['user_id'];
        if (!isset($user_summary[$uid])) {
            $user_summary[$uid] = [
                'name' => $row['full_name'],
                'department' => $row['department'] ?? 'N/A',
                'total_apps' => 0,
                'total_days' => 0,
                'lop_apps' => 0,
                'lop_days' => 0,
                'sick_days' => 0,
                'casual_days' => 0
            ];
        }
        $user_summary[$uid]['total_apps']++;
        $user_summary[$uid]['total_days'] += $row['days'];
        
        if ($row['leave_type'] == 'LOP') {
            $user_summary[$uid]['lop_apps']++;
            $user_summary[$uid]['lop_days'] += $row['days'];
        } elseif ($row['leave_type'] == 'Sick') {
            $user_summary[$uid]['sick_days'] += $row['days'];
        } elseif ($row['leave_type'] == 'Casual') {
            $user_summary[$uid]['casual_days'] += $row['days'];
        }
    }
    
    // Set headers for Excel download
    $filename = $export_type == 'lop' ? "lop_leaves_report_" . date('Y-m-d') . ".xls" : "leaves_report_" . date('Y-m-d') . ".xls";
    header("Content-Type: application/vnd.ms-excel");
    header("Content-Disposition: attachment; filename=\"$filename\"");
    header("Pragma: no-cache");
    header("Expires: 0");
    
    // Start Excel content
    echo '<html>';
    echo '<head>';
    echo '<meta charset="UTF-8">';
    echo '<style>';
    echo 'td { mso-number-format:\@; vertical-align: top; }';
    echo '.date { mso-number-format:"yyyy-mm-dd"; }';
    echo '.number { mso-number-format:"0.00"; }';
    echo '.header { background: #006400; color: white; font-weight: bold; text-align: center; }';
    echo '.subheader { background: #e8f5e9; font-weight: bold; }';
    echo '.lop { color: #c53030; font-weight: bold; }';
    echo '.casual { color: #48bb78; font-weight: bold; }';
    echo '.total { background: #f0f0f0; font-weight: bold; }';
    echo 'h3 { color: #006400; margin-top: 20px; }';
    echo '.summary-stats td { padding: 5px 10px; }';
    echo '</style>';
    echo '</head>';
    echo '<body>';
    
    // Title
    $title = $export_type == 'lop' ? 'LOP Leaves Report' : 'Leave Report';
    echo '<h2>MAKSIM HR - ' . $title . '</h2>';
    echo '<p><strong>Period:</strong> ' . date('d-m-Y', strtotime($from_date)) . ' to ' . date('d-m-Y', strtotime($to_date)) . '</p>';
    echo '<p><strong>Employee:</strong> ' . $user_name . '</p>';
    echo '<p><strong>Generated on:</strong> ' . date('d-m-Y H:i:s') . '</p>';
    echo '<br>';
    
    // ===== SUMMARY STATISTICS =====
    echo '<h3>Summary Statistics</h3>';
    echo '<table border="1" cellpadding="5" class="summary-stats">';
    echo '<tr><td><strong>Total Leaves Applied:</strong></td><td>' . $total_applications . '</td></tr>';
    echo '<tr><td><strong>Total Leave Days:</strong></td><td>' . number_format($total_days, 1) . '</td></tr>';
    echo '<tr><td><strong>Total LOP Applications:</strong></td><td>' . $total_lop_apps . '</td></tr>';
    echo '<tr><td><strong>Total LOP Days:</strong></td><td>' . number_format($total_lop_days, 1) . '</td></tr>';
    echo '<tr><td><strong>Sick Leave Days:</strong></td><td>' . number_format($sick_days, 1) . '</td></tr>';
    echo '<tr><td><strong>Casual Leave Days:</strong></td><td>' . number_format($casual_days, 1) . '</td></tr>';
    echo '<tr><td><strong>Approved:</strong></td><td>' . $approved_count . '</td></tr>';
    echo '<tr><td><strong>Pending:</strong></td><td>' . $pending_count . '</td></tr>';
    echo '<tr><td><strong>Rejected:</strong></td><td>' . $rejected_count . '</td></tr>';
    echo '</table>';
    echo '<br>';
    
    // ===== MONTHLY SUMMARY =====
    if (!empty($monthly_summary) && $export_type != 'lop') {
        echo '<h3>Monthly Summary</h3>';
        echo '<table border="1" cellpadding="5">';
        echo '<tr class="header">';
        echo '<th>Month</th>';
        echo '<th>Total Apps</th>';
        echo '<th>Total Days</th>';
        echo '<th>Sick Days</th>';
        echo '<th>Casual Days</th>';
        echo '<th>LOP Days</th>';
        echo '</tr>';
        
        foreach ($monthly_summary as $summary) {
            echo '<tr>';
            echo '<td>' . $summary['month'] . '</td>';
            echo '<td>' . $summary['total_apps'] . '</td>';
            echo '<td>' . number_format($summary['total_days'], 1) . '</td>';
            echo '<td>' . number_format($summary['sick_days'], 1) . '</td>';
            echo '<td>' . number_format($summary['casual_days'], 1) . '</td>';
            echo '<td class="lop">' . number_format($summary['lop_days'], 1) . '</td>';
            echo '</tr>';
        }
        echo '</table>';
        echo '<br>';
    }
    
    // ===== EMPLOYEE SUMMARY =====
    if (!empty($user_summary) && $export_type != 'lop') {
        echo '<h3>Employee Summary</h3>';
        echo '<table border="1" cellpadding="5">';
        echo '<tr class="header">';
        echo '<th>Employee</th>';
        echo '<th>Department</th>';
        echo '<th>Total Apps</th>';
        echo '<th>Total Days</th>';
        echo '<th>LOP Apps</th>';
        echo '<th>LOP Days</th>';
        echo '<th>Sick Days</th>';
        echo '<th>Casual Days</th>';
        echo '</tr>';
        
        foreach ($user_summary as $summary) {
            echo '<tr>';
            echo '<td><strong>' . $summary['name'] . '</strong></td>';
            echo '<td>' . $summary['department'] . '</td>';
            echo '<td>' . $summary['total_apps'] . '</td>';
            echo '<td>' . number_format($summary['total_days'], 1) . '</td>';
            echo '<td>' . $summary['lop_apps'] . '</td>';
            echo '<td>' . number_format($summary['lop_days'], 1) . '</td>';
            echo '<td>' . number_format($summary['sick_days'], 1) . '</td>';
            echo '<td>' . number_format($summary['casual_days'], 1) . '</td>';
            echo '</tr>';
        }
        echo '</table>';
        echo '<br>';
    }
    
    // ===== DETAILED LEAVE APPLICATIONS =====
    $detail_title = $export_type == 'lop' ? 'LOP Leave Applications' : 'Detailed Leave Applications';
    echo '<h3>' . $detail_title . '</h3>';
    echo '<table border="1" cellpadding="5">';
    echo '<tr class="header">';
    echo '<th>Employee</th>';
    echo '<th>Department</th>';
    echo '<th>Leave Type</th>';
    echo '<th>From Date</th>';
    echo '<th>To Date</th>';
    echo '<th>Days</th>';
    echo '<th>Day Type</th>';
    echo '<th>Status</th>';
    echo '<th>Reason</th>';
    echo '<th>Leave Year</th>';
    echo '<th>Month</th>';
    echo '</tr>';
    
    foreach ($data as $row) {
        $lop_class = ($row['leave_type'] == 'LOP') ? ' class="lop"' : ($row['leave_type'] == 'Casual' ? ' class="casual"' : '');
        $status_class = 'status-' . strtolower($row['status']);
        
        echo '<tr>';
        echo '<td>' . htmlspecialchars($row['full_name']) . '</td>';
        echo '<td>' . htmlspecialchars($row['department'] ?? 'N/A') . '</td>';
        echo '<td' . $lop_class . '>' . htmlspecialchars($row['leave_type']) . '</td>';
        echo '<td class="date">' . $row['from_date'] . '</td>';
        echo '<td class="date">' . $row['to_date'] . '</td>';
        echo '<td class="number">' . number_format($row['days'], 2) . '</td>';
        echo '<td>' . htmlspecialchars($row['day_type']) . '</td>';
        echo '<td><span class="status-badge ' . $status_class . '">' . $row['status'] . '</span></td>';
        echo '<td>' . htmlspecialchars($row['reason']) . '</td>';
        echo '<td>' . $row['leave_year'] . '</td>';
        echo '<td>' . ($row['month_year'] ?? date('F Y', strtotime($row['from_date']))) . '</td>';
        echo '</tr>';
    }
    
    // Totals row
    echo '<tr class="total">';
    echo '<td colspan="5"><strong>TOTAL</strong></td>';
    echo '<td><strong>' . number_format($total_days, 2) . ' days</strong></td>';
    echo '<td colspan="5"></td>';
    echo '</tr>';
    
    echo '</table>';
    echo '<br>';
    echo '<hr>';
    echo '<p><em>Generated by MAKSIM PORTAL</em></p>';
    echo '</body>';
    echo '</html>';
    exit();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Export Leaves - MAKSIM HR</title>
    <?php include '../includes/head.php'; ?>
    <style>
        .export-container {
            max-width: 800px;
            margin: 0 auto;
        }
        .filter-card {
            background: white;
            border-radius: 15px;
            padding: 30px;
            box-shadow: 0 5px 15px rgba(0,0,0,0.05);
            margin-bottom: 30px;
        }
        .filter-title {
            color: #2d3748;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .filter-title i {
            color: #006400;
            font-size: 24px;
        }
        .form-group {
            margin-bottom: 20px;
        }
        .form-label {
            display: block;
            margin-bottom: 8px;
            color: #4a5568;
            font-weight: 500;
        }
        .form-control {
            width: 100%;
            padding: 12px 16px;
            border: 2px solid #e2e8f0;
            border-radius: 10px;
            font-size: 16px;
        }
        .form-control:focus {
            outline: none;
            border-color: #006400;
        }
        .btn-export {
            background: linear-gradient(135deg, #006400 0%, #2c9218 100%);
            color: white;
            border: none;
            padding: 14px 30px;
            border-radius: 10px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            width: 100%;
            transition: transform 0.2s;
            margin-bottom: 10px;
        }
        .btn-export:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 20px rgba(0,100,0,0.3);
        }
        .btn-lop-export {
            background: linear-gradient(135deg, #c53030 0%, #ed8936 100%);
            color: white;
            border: none;
            padding: 14px 30px;
            border-radius: 10px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            width: 100%;
            transition: transform 0.2s;
            margin-bottom: 10px;
        }
        .btn-lop-export:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 20px rgba(197, 48, 48, 0.3);
        }
        .btn-preview {
            background: #4299e1;
            color: white;
            border: none;
            padding: 14px 30px;
            border-radius: 10px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            width: 100%;
            margin-top: 10px;
        }
        .date-hint {
            font-size: 12px;
            color: #718096;
            margin-top: 5px;
        }
        .preview-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
        }
        .preview-table th {
            background: #f7fafc;
            padding: 12px;
            text-align: left;
            border-bottom: 2px solid #e2e8f0;
        }
        .preview-table td {
            padding: 10px;
            border-bottom: 1px solid #e2e8f0;
        }
        .preview-table .lop-row {
            background: #fff5f5;
        }
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 15px;
            margin: 20px 0;
        }
        .stat-box {
            background: #f7fafc;
            padding: 15px;
            border-radius: 10px;
            text-align: center;
        }
        .stat-box.lop {
            background: #fff5f5;
            border-left: 4px solid #c53030;
        }
        .stat-value {
            font-size: 24px;
            font-weight: bold;
            color: #2d3748;
        }
        .stat-label {
            font-size: 12px;
            color: #718096;
        }
        .approved-badge {
            background: #006400;
            color: white;
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 12px;
            display: inline-flex;
            align-items: center;
            gap: 5px;
        }
        .lop-badge {
            background: #c53030;
            color: white;
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 12px;
            display: inline-flex;
            align-items: center;
            gap: 5px;
        }
        .export-buttons {
            display: flex;
            gap: 10px;
            margin-top: 20px;
        }
        .export-buttons button {
            flex: 1;
        }
    </style>
</head>
<body>
    <?php include '../includes/header.php'; ?>
    
    <div class="app-main">
        <?php include '../includes/sidebar.php'; ?>
        
        <div class="main-content">
            <div class="export-container">
                <h2 class="page-title">
                    <i class="icon-excel"></i> Export Leaves Report
                </h2>
                
                <?php echo $message; ?>
                
                <div class="filter-card">
                    <div class="filter-title">
                        <i class="icon-filter"></i>
                        <h3>Select Date Range</h3>
                        <div style="display: flex; gap: 10px; margin-left: auto;">
                            <span class="approved-badge">
                                <i class="icon-check"></i> All Approved
                            </span>
                            <span class="lop-badge">
                                <i class="icon-warning"></i> LOP Only
                            </span>
                        </div>
                    </div>
                    
                    <form method="POST" action="">
                        <div class="form-row">
                            <div class="form-group">
                                <label class="form-label">From Date *</label>
                                <input type="date" name="from_date" id="from_date" class="form-control" 
                                       value="<?php echo $default_from_date; ?>" required>
                                <div class="date-hint">You can select any date - past, present, or future</div>
                            </div>
                            
                            <div class="form-group">
                                <label class="form-label">To Date *</label>
                                <input type="date" name="to_date" id="to_date" class="form-control" 
                                       value="<?php echo $default_to_date; ?>" required>
                                <div class="date-hint">You can select any date - past, present, or future</div>
                            </div>
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label">Select Employee</label>
                            <select name="user_id" class="form-control">
                                <option value="0" <?php echo $selected_user_id == 0 ? 'selected' : ''; ?>>All Employees</option>
                                <?php foreach ($users as $user): ?>
                                <option value="<?php echo $user['id']; ?>" <?php echo $selected_user_id == $user['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($user['full_name']); ?> (<?php echo $user['username']; ?>)
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <button type="submit" name="export_excel" class="btn-export">
                            <i class="icon-search"></i> Preview Data
                        </button>
                        
                        <?php if (!empty($export_data)): ?>
                        <div class="export-buttons">
                            <button type="submit" name="generate_excel" class="btn-export" style="margin-bottom: 0;">
                                <i class="icon-excel"></i> Export All Approved
                            </button>
                            <button type="submit" name="generate_lop_excel" class="btn-lop-export" style="margin-bottom: 0;">
                                <i class="icon-warning"></i> Export LOP Only (All Employees)
                            </button>
                        </div>
                        <?php endif; ?>
                    </form>
                </div>
                
                <?php if (!empty($export_data)): ?>
                <!-- Preview Table -->
                <div class="card">
                    <div class="card-header">
                        <h3 class="card-title">
                            <i class="icon-view"></i> Preview (<?php echo count($export_data); ?> records - including all statuses)
                        </h3>
                        <span style="color: #718096;">
                            <?php echo date('d-m-Y', strtotime($_POST['from_date'])); ?> to 
                            <?php echo date('d-m-Y', strtotime($_POST['to_date'])); ?>
                        </span>
                    </div>
                    
                    <div class="table-container">
                        <table class="preview-table">
                            <thead>
                                <tr>
                                    <th>Employee</th>
                                    <th>Type</th>
                                    <th>From</th>
                                    <th>To</th>
                                    <th>Days</th>
                                    <th>Status</th>
                                    <th>Month</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php 
                                $display_count = 0;
                                $lop_count = 0;
                                foreach ($export_data as $row): 
                                    if ($display_count++ >= 20) break;
                                    $is_lop = $row['leave_type'] == 'LOP';
                                    if ($is_lop) $lop_count++;
                                ?>
                                <tr class="<?php echo $is_lop ? 'lop-row' : ''; ?>">
                                    <td><strong><?php echo htmlspecialchars($row['full_name']); ?></strong></td>
                                    <td>
                                        <span style="<?php echo $is_lop ? 'color: #c53030; font-weight: bold;' : ''; ?>">
                                            <?php echo $row['leave_type']; ?>
                                        </span>
                                    </td>
                                    <td><?php echo $row['from_date']; ?></td>
                                    <td><?php echo $row['to_date']; ?></td>
                                    <td><?php echo $row['days']; ?></td>
                                    <td>
                                        <span class="status-badge status-<?php echo strtolower($row['status']); ?>">
                                            <?php echo $row['status']; ?>
                                        </span>
                                    </td>
                                    <td><?php echo $row['month_year']; ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                        <?php if (count($export_data) > 20): ?>
                        <div style="text-align: center; padding: 15px; color: #718096;">
                            <i class="icon-info"></i> 
                            Showing first 20 of <?php echo count($export_data); ?> records. 
                            Export to Excel to see all approved records.
                        </div>
                        <?php endif; ?>
                        <?php if ($lop_count > 0): ?>
                        <div style="text-align: center; padding: 15px; color: #c53030;">
                            <i class="icon-warning"></i> 
                            Found <?php echo $lop_count; ?> LOP records in this preview.
                            Use "Export LOP Only (All Employees)" to generate a formatted LOP report with ALL employees (0 LOP shown).
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    
    <script>
    document.addEventListener('DOMContentLoaded', function() {
        // Get the current values from PHP (they will be preserved on form submission)
        var fromDateInput = document.getElementById('from_date');
        var toDateInput = document.getElementById('to_date');
        
        // Remove date restrictions but KEEP the current values
        fromDateInput.removeAttribute('max');
        fromDateInput.removeAttribute('min');
        toDateInput.removeAttribute('max');
        toDateInput.removeAttribute('min');
        
        console.log('Date restrictions removed - dates preserved');
    });
    
    document.getElementById('to_date').addEventListener('change', function() {
        var fromDate = document.getElementById('from_date').value;
        var toDate = this.value;
        
        if (fromDate && toDate && toDate < fromDate) {
            alert('To date cannot be before from date');
            this.value = fromDate;
        }
    });
    
    document.getElementById('from_date').addEventListener('change', function() {
        var fromDate = this.value;
        var toDate = document.getElementById('to_date').value;
        
        if (fromDate && toDate && toDate < fromDate) {
            document.getElementById('to_date').value = fromDate;
        }
    });
    </script>
    
    <script src="../assets/js/app.js"></script>
</body>
</html>