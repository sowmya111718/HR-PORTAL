<?php
require_once '../config/db.php';
require_once '../includes/leave_functions.php';
require_once '../includes/icon_functions.php';
require_once '../includes/notification_functions.php';
checkRole(['hr', 'admin', 'pm']);

$user_id = $_SESSION['user_id'];
$role = $_SESSION['role'];
$message = '';

// Get all available leave years for filter
$all_leave_years = getAllLeaveYears($conn);
$current_leave_year = getCurrentLeaveYear();

// Year is now driven by the leave_year dropdown in the Leave Requests filter (no separate top filter)
$_prev_ly = getPreviousLeaveYear();
$_leave_year_param = isset($_GET['leave_year']) ? sanitize($_GET['leave_year']) : '';

if ($_leave_year_param === $_prev_ly['year_label']) {
    $year_filter = $_prev_ly['year_label'];
} else {
    // Default: current leave year
    $year_filter = $current_leave_year['year_label'];
}

// Determine if we're viewing previous year data
$is_previous_year = ($year_filter !== $current_leave_year['year_label']);

// Extract start year from year filter for date calculations
$year_parts = explode('-', $year_filter);
$start_year = $year_parts[0]; // e.g. "2025" from "2025-2026"

// Calculate the strict Mar 16 – Mar 15 date range for the selected year
$year_start_date = $start_year . '-03-16';
$year_end_date   = ($start_year + 1) . '-03-15';

// Function to get leave month ranges (16th to 15th) - FIXED
function getLeaveMonthRanges() {
    $current_year = date('Y');
    $current_month = date('n');
    $current_day = date('j');
    
    $months = [];
    
    // Determine the starting point (most recent March 16)
    if ($current_month > 3 || ($current_month == 3 && $current_day >= 16)) {
        // We're after March 16 this year, so cycle started this year on March 16
        $cycle_start_year = $current_year;
        $cycle_start_month = 3; // March
    } else {
        // We're before March 16 this year, so cycle started last year on March 16
        $cycle_start_year = $current_year - 1;
        $cycle_start_month = 3; // March
    }
    
    // Generate 12 months in the cycle (Mar 16 - Mar 15)
    for ($i = 0; $i < 12; $i++) {
        $current_cycle_month = $cycle_start_month + $i;
        $current_cycle_year = $cycle_start_year;
        
        // Handle year rollover
        if ($current_cycle_month > 12) {
            $current_cycle_month -= 12;
            $current_cycle_year++;
        }
        
        // Start date: current month 16th
        $start_date = sprintf("%04d-%02d-16", $current_cycle_year, $current_cycle_month);
        
        // End date: next month 15th
        $next_month = $current_cycle_month + 1;
        $next_year = $current_cycle_year;
        if ($next_month > 12) {
            $next_month = 1;
            $next_year++;
        }
        $end_date = sprintf("%04d-%02d-15", $next_year, $next_month);
        
        // Month name for display
        $month_name = date('F', mktime(0, 0, 0, $current_cycle_month, 1));
        $next_month_name = date('F', mktime(0, 0, 0, $next_month, 1));
        
        // Create a unique key for the month
        $key = $current_cycle_year . '_' . $current_cycle_month;
        
        $months[$key] = [
            'value' => $key,
            'label' => $month_name . ' 16 - ' . $next_month_name . ' 15',
            'start_date' => $start_date,
            'end_date' => $end_date,
            'display_month' => $month_name,
            'year' => $current_cycle_year,
            'month_num' => $current_cycle_month
        ];
    }
    
    // Sort months by date (ascending)
    uasort($months, function($a, $b) {
        return strcmp($a['start_date'], $b['start_date']);
    });
    
    return $months;
}

// Get leave year info
$leave_year = getCurrentCasualLeaveYear();
$leave_stats = getLeaveYearStatistics($conn);

// Get leave month ranges
$leave_months = getLeaveMonthRanges();

// Get LOP statistics for HR panel - FIXED: Extract year properly from year_filter
$lop_start = $start_year . '-03-16';
$lop_end = ($start_year + 1) . '-03-15';

$lop_stats_query = $conn->prepare("
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
    AND from_date BETWEEN ? AND ?
");
$lop_stats_query->bind_param("ss", $lop_start, $lop_end);
$lop_stats_query->execute();
$lop_stats = $lop_stats_query->get_result()->fetch_assoc();
$lop_stats_query->close();

if (!$lop_stats) {
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

// FIXED: Check if notifications already created today to prevent duplicates
$today_date = date('Y-m-d');

// Check if we've already created notifications today
$notification_check = $conn->prepare("
    SELECT COUNT(*) as count FROM notifications 
    WHERE user_id = ? 
    AND DATE(created_at) = ? 
    AND type IN ('pending_leaves', 'pending_permissions', 'late_timesheets')
");
$notification_check->bind_param("is", $user_id, $today_date);
$notification_check->execute();
$notification_result = $notification_check->get_result();
$notification_row = $notification_result->fetch_assoc();
$notifications_today = $notification_row['count'];
$notification_check->close();

// Only create notifications if none exist for today
if ($notifications_today == 0 && !$is_previous_year) {
    // Get counts for notifications about new submissions
    $new_pending_leaves_today = 0;
    $new_pending_permissions_today = 0;
    $new_late_timesheets_today = 0;

    // Count new pending leaves submitted today
    $leaves_today_query = $conn->prepare("
        SELECT COUNT(*) as count 
        FROM leaves 
        WHERE status = 'Pending' 
        AND DATE(applied_date) = ?
    ");
    $leaves_today_query->bind_param("s", $today_date);
    $leaves_today_query->execute();
    $leaves_today_result = $leaves_today_query->get_result();
    if ($leaves_today_row = $leaves_today_result->fetch_assoc()) {
        $new_pending_leaves_today = $leaves_today_row['count'];
    }
    $leaves_today_query->close();

    // Count new pending permissions submitted today
    $permissions_today_query = $conn->prepare("
        SELECT COUNT(*) as count 
        FROM permissions 
        WHERE status = 'Pending' 
        AND DATE(applied_date) = ?
    ");
    $permissions_today_query->bind_param("s", $today_date);
    $permissions_today_query->execute();
    $permissions_today_result = $permissions_today_query->get_result();
    if ($permissions_today_row = $permissions_today_result->fetch_assoc()) {
        $new_pending_permissions_today = $permissions_today_row['count'];
    }
    $permissions_today_query->close();

    // Count late timesheets submitted today
    $late_timesheets_query = $conn->prepare("
        SELECT COUNT(*) as count 
        FROM timesheets 
        WHERE submitted_date > CONCAT(entry_date, ' 23:59:59')
        AND DATE(submitted_date) = ?
    ");
    $late_timesheets_query->bind_param("s", $today_date);
    $late_timesheets_query->execute();
    $late_timesheets_result = $late_timesheets_query->get_result();
    if ($late_timesheets_row = $late_timesheets_result->fetch_assoc()) {
        $new_late_timesheets_today = $late_timesheets_row['count'];
    }
    $late_timesheets_query->close();

    // Create notifications for new pending leaves - only if there are new ones
    if ($new_pending_leaves_today > 0) {
        // Check if we already created this exact notification
        $check_existing = $conn->prepare("
            SELECT id FROM notifications 
            WHERE user_id = ? AND type = 'pending_leaves' 
            AND DATE(created_at) = ? AND message LIKE ?
        ");
        $like = "%{$new_pending_leaves_today}%";
        $check_existing->bind_param("iss", $user_id, $today_date, $like);
        $check_existing->execute();
        $existing_result = $check_existing->get_result();
        
        if ($existing_result->num_rows == 0) {
            $title = "New Pending Leave Applications";
            $message = "You have {$new_pending_leaves_today} new pending leave application(s) submitted today.";
            createNotification($conn, $user_id, 'pending_leaves', $title, $message);
        }
        $check_existing->close();
    }

    // Create notifications for new pending permissions
    if ($new_pending_permissions_today > 0) {
        $check_existing = $conn->prepare("
            SELECT id FROM notifications 
            WHERE user_id = ? AND type = 'pending_permissions' 
            AND DATE(created_at) = ? AND message LIKE ?
        ");
        $like = "%{$new_pending_permissions_today}%";
        $check_existing->bind_param("iss", $user_id, $today_date, $like);
        $check_existing->execute();
        $existing_result = $check_existing->get_result();
        
        if ($existing_result->num_rows == 0) {
            $title = "New Pending Permission Requests";
            $message = "You have {$new_pending_permissions_today} new pending permission request(s) submitted today.";
            createNotification($conn, $user_id, 'pending_permissions', $title, $message);
        }
        $check_existing->close();
    }

    // Create notifications for late timesheets
    if ($new_late_timesheets_today > 0) {
        $check_existing = $conn->prepare("
            SELECT id FROM notifications 
            WHERE user_id = ? AND type = 'late_timesheets' 
            AND DATE(created_at) = ? AND message LIKE ?
        ");
        $like = "%{$new_late_timesheets_today}%";
        $check_existing->bind_param("iss", $user_id, $today_date, $like);
        $check_existing->execute();
        $existing_result = $check_existing->get_result();
        
        if ($existing_result->num_rows == 0) {
            $title = "Late Timesheet Submissions";
            $message = "You have {$new_late_timesheets_today} late timesheet submission(s) today.";
            createNotification($conn, $user_id, 'late_timesheets', $title, $message);
        }
        $check_existing->close();
    }
}

// Export Leaves functionality - UPDATED with employee-wise summary and removed cancelled leaves
if (isset($_POST['export_leaves'])) {
    $export_type = sanitize($_POST['export_type']);
    $export_from_date = isset($_POST['export_from_date']) ? sanitize($_POST['export_from_date']) : '';
    $export_to_date = isset($_POST['export_to_date']) ? sanitize($_POST['export_to_date']) : '';
    $export_lop_type = isset($_POST['export_lop_type']) ? sanitize($_POST['export_lop_type']) : 'all';
    $export_year = isset($_POST['export_year']) ? sanitize($_POST['export_year']) : $year_filter;
    
    // Build WHERE clause - use strict Mar 16-Mar 15 date range, not leave_year label
    $export_year_parts = explode('-', $export_year);
    $export_start_year = intval($export_year_parts[0]);
    $export_year_start = $export_start_year . '-03-16';
    $export_year_end   = ($export_start_year + 1) . '-03-15';

    if ($export_type === 'date_range' && !empty($export_from_date) && !empty($export_to_date)) {
        $leave_where = "l.status != 'Cancelled' AND l.from_date BETWEEN '$export_from_date' AND '$export_to_date'";
        $range_label = date('d M Y', strtotime($export_from_date)) . ' to ' . date('d M Y', strtotime($export_to_date));
    } else {
        $leave_where = "l.status != 'Cancelled' AND l.from_date BETWEEN '$export_year_start' AND '$export_year_end'";
        $range_label = 'Year: ' . $export_year . ' (' . $export_year_start . ' to ' . $export_year_end . ')';
    }
    
    // Apply LOP type filters
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
    } elseif ($export_lop_type === 'lop') {
        $leave_where .= " AND l.leave_type = 'LOP'";
    }
    
    // Get ALL users with their leave summaries - INCLUDING those with zero leaves
    $users_query = $conn->query("
        SELECT u.id, u.full_name, u.username, u.department, u.position,
               COALESCE(SUM(CASE WHEN l.leave_type = 'LOP' THEN l.days ELSE 0 END), 0) as total_lop_days,
               COALESCE(SUM(CASE WHEN l.leave_type = 'LOP' AND l.reason LIKE 'Auto-generated LOP%' THEN l.days ELSE 0 END), 0) as auto_lop_days,
               COALESCE(SUM(CASE WHEN l.leave_type = 'LOP' AND l.reason NOT LIKE 'Auto-generated LOP%' THEN l.days ELSE 0 END), 0) as manual_lop_days,
               COALESCE(SUM(CASE WHEN l.leave_type = 'Casual' THEN l.days ELSE 0 END), 0) as casual_days,
               COALESCE(SUM(CASE WHEN l.leave_type = 'Sick' THEN l.days ELSE 0 END), 0) as sick_days,
               COUNT(CASE WHEN l.leave_type = 'LOP' THEN 1 END) as lop_count,
               COUNT(CASE WHEN l.leave_type = 'Casual' THEN 1 END) as casual_count,
               COUNT(CASE WHEN l.leave_type = 'Sick' THEN 1 END) as sick_count
        FROM users u
        LEFT JOIN leaves l ON u.id = l.user_id AND ($leave_where)
        GROUP BY u.id
        ORDER BY u.full_name
    ");
    
    $user_summaries = $users_query->fetch_all(MYSQLI_ASSOC);
    
    // Get detailed leaves for the range - EXCLUDE CANCELLED
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
        ORDER BY l.from_date DESC
    ");
    
    // Build filename
    $filename = "leaves_{$export_year}";
    if ($export_type === 'date_range' && !empty($export_from_date) && !empty($export_to_date)) {
        $filename .= "_" . date('d-m-Y', strtotime($export_from_date)) . '_to_' . date('d-m-Y', strtotime($export_to_date));
    }
    if ($export_lop_type !== 'all') {
        $lop_type_map = ['auto' => 'auto_lop', 'manual' => 'manual_lop', 'regular' => 'regular', 'sick' => 'sick', 'casual' => 'casual', 'lop' => 'lop'];
        $filename .= '_' . ($lop_type_map[$export_lop_type] ?? $export_lop_type);
    }
    $filename .= ".xls";
    
    header("Content-Type: application/vnd.ms-excel");
    header("Content-Disposition: attachment; filename=\"$filename\"");
    header("Pragma: no-cache");
    header("Expires: 0");
    header("X-Download-By: Microsoft Office Professional Plus Build 17928.20148");
    
    echo "<html><head><meta charset=\"UTF-8\"><style>
        td { mso-number-format:\\@; vertical-align: top; padding: 5px; }
        .date { mso-number-format:'yyyy-mm-dd'; }
        .number { mso-number-format:'0'; }
        .stats-box { background: #f0f7ff; border: 2px solid #4299e1; border-radius: 10px; padding: 15px; margin-bottom: 20px; }
        .stats-title { font-size: 16px; font-weight: bold; color: #2c5282; margin-bottom: 10px; }
        .stats-grid { display: flex; flex-wrap: wrap; gap: 15px; }
        .stat-item { flex: 1; min-width: 150px; background: white; padding: 10px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
        .stat-label { font-size: 12px; color: #718096; }
        .stat-value { font-size: 24px; font-weight: bold; }
        .employee-summary-box { background: #f9f9f9; border: 2px solid #48bb78; border-radius: 10px; padding: 15px; margin-bottom: 20px; }
        .employee-summary-title { font-size: 16px; font-weight: bold; color: #276749; margin-bottom: 10px; }
        .summary-table { width: 100%; border-collapse: collapse; margin-top: 10px; }
        .summary-table th { background: #276749; color: white; padding: 8px; text-align: left; }
        .summary-table td { padding: 8px; border-bottom: 1px solid #cbd5e0; }
        .summary-table tr:hover { background: #f0fff4; }
        .lop-row { background-color: #fee2e2; }
        .auto-lop-row { background-color: #fecaca; border-left: 4px solid #dc2626; }
        .sick-row { background-color: #dcfce7; }
        .casual-row { background-color: #dbeafe; }
        .lop-text { color: #dc2626; font-weight: bold; }
        .sick-text { color: #16a34a; font-weight: bold; }
        .casual-text { color: #2563eb; font-weight: bold; }
        .header-cell { background: #006400; color: white; font-weight: bold; }
        .zero-leave { color: #94a3b8; font-style: italic; }
    </style></head><body>";
    
    echo "<h2 style='color: #006400;'>🏢 MAKSIM HR - Leaves Export</h2>";
    echo "<p><strong>Leave Year:</strong> " . $export_year . "</p>";
    echo "<p><strong>Export Type:</strong> " . ($export_lop_type == 'auto' ? 'Auto-generated LOP Only' : ($export_lop_type == 'manual' ? 'Manual LOP Only' : ($export_lop_type == 'regular' ? 'Regular Leaves (Non-LOP)' : ($export_lop_type == 'sick' ? 'Sick Leave Only' : ($export_lop_type == 'casual' ? 'Casual Leave Only' : ($export_lop_type == 'lop' ? 'LOP Only' : 'All Leaves')))))) . "</p>";
    echo "<p><strong>Date Range:</strong> " . $range_label . "</p>";
    echo "<p><strong>Cancelled Leaves:</strong> Excluded from report</p>";
    
    // Statistics Box
    $total_lop = array_sum(array_column($user_summaries, 'total_lop_days'));
    $total_auto_lop = array_sum(array_column($user_summaries, 'auto_lop_days'));
    $total_manual_lop = array_sum(array_column($user_summaries, 'manual_lop_days'));
    $total_casual = array_sum(array_column($user_summaries, 'casual_days'));
    $total_sick = array_sum(array_column($user_summaries, 'sick_days'));
    
    echo "<div class='stats-box'>";
    echo "<div class='stats-title'>📊 Leave Statistics for " . $export_year . "</div>";
    echo "<div class='stats-grid'>";
    
    echo "<div class='stat-item'>";
    echo "<div class='stat-label'>Total LOP Days</div>";
    echo "<div class='stat-value' style='color: #dc2626;'>" . $total_lop . "</div>";
    echo "<div style='font-size: 11px; color: #6b7280;'>Employees: " . count(array_filter($user_summaries, function($u) { return $u['total_lop_days'] > 0; })) . "</div>";
    echo "</div>";
    
    echo "<div class='stat-item'>";
    echo "<div class='stat-label'>Auto LOP Days</div>";
    echo "<div class='stat-value' style='color: #b91c1c;'>" . $total_auto_lop . "</div>";
    echo "</div>";
    
    echo "<div class='stat-item'>";
    echo "<div class='stat-label'>Manual LOP Days</div>";
    echo "<div class='stat-value' style='color: #f97316;'>" . $total_manual_lop . "</div>";
    echo "</div>";
    
    echo "<div class='stat-item'>";
    echo "<div class='stat-label'>Sick Leave Days</div>";
    echo "<div class='stat-value' style='color: #16a34a;'>" . $total_sick . "</div>";
    echo "<div style='font-size: 11px; color: #6b7280;'>Employees: " . count(array_filter($user_summaries, function($u) { return $u['sick_days'] > 0; })) . "</div>";
    echo "</div>";
    
    echo "<div class='stat-item'>";
    echo "<div class='stat-label'>Casual Leave Days</div>";
    echo "<div class='stat-value' style='color: #2563eb;'>" . $total_casual . "</div>";
    echo "<div style='font-size: 11px; color: #6b7280;'>Employees: " . count(array_filter($user_summaries, function($u) { return $u['casual_days'] > 0; })) . "</div>";
    echo "</div>";
    
    echo "</div></div>";
    
    // Employee-wise Summary Box - ALL EMPLOYEES INCLUDED
    echo "<div class='employee-summary-box'>";
    echo "<div class='employee-summary-title'>👥 Employee-wise Leave Summary (All Employees) - " . $export_year . "</div>";
    echo "<table class='summary-table' border='1' cellpadding='5' style='border-collapse: collapse; width: 100%;'>";
    echo "<tr>";
    echo "<th style='background: #276749; color: white;'>S.No</th>";
    echo "<th style='background: #276749; color: white;'>EMP ID</th>";
    echo "<th style='background: #276749; color: white;'>EMP NAME</th>";
    echo "<th style='background: #276749; color: white;'>Department</th>";
    echo "<th style='background: #276749; color: white;'>LEAVES LOP (Days)</th>";
    echo "<th style='background: #276749; color: white;'>Auto LOP</th>";
    echo "<th style='background: #276749; color: white;'>Manual LOP</th>";
    echo "<th style='background: #276749; color: white;'>CASUAL LEAVES TAKEN</th>";
    echo "<th style='background: #276749; color: white;'>SICK LEAVES TAKEN</th>";
    echo "<th style='background: #276749; color: white;'>Total Leaves</th>";
    echo "</tr>";
    
    $sno = 1;
    foreach ($user_summaries as $user) {
        $total_leaves = $user['total_lop_days'] + $user['casual_days'] + $user['sick_days'];
        
        echo "<tr>";
        echo "<td>" . $sno++ . "</td>";
        echo "<td>" . htmlspecialchars($user['username']) . "</td>";
        echo "<td><strong>" . htmlspecialchars($user['full_name']) . "</strong></td>";
        echo "<td>" . htmlspecialchars($user['department'] ?? 'N/A') . "</td>";
        
        if ($user['total_lop_days'] > 0) {
            echo "<td class='lop-text'><strong>" . $user['total_lop_days'] . "</strong> (" . $user['lop_count'] . " apps)</td>";
            echo "<td style='color: #b91c1c;'>" . $user['auto_lop_days'] . "</td>";
            echo "<td style='color: #f97316;'>" . $user['manual_lop_days'] . "</td>";
        } else {
            echo "<td class='zero-leave'>0</td>";
            echo "<td class='zero-leave'>0</td>";
            echo "<td class='zero-leave'>0</td>";
        }
        
        if ($user['casual_days'] > 0) {
            echo "<td class='casual-text'><strong>" . $user['casual_days'] . "</strong> (" . $user['casual_count'] . " apps)</td>";
        } else {
            echo "<td class='zero-leave'>0</td>";
        }
        
        if ($user['sick_days'] > 0) {
            echo "<td class='sick-text'><strong>" . $user['sick_days'] . "</strong> (" . $user['sick_count'] . " apps)</td>";
        } else {
            echo "<td class='zero-leave'>0</td>";
        }
        
        echo "<td><strong>" . $total_leaves . "</strong></td>";
        echo "</tr>";
    }
    
    echo "</table></div>";
    
    // Detailed Leaves Table
    echo "<h3 style='margin-top: 20px;'>📋 Detailed Leave Entries</h3>";
    echo "<table border='1' cellpadding='5' style='border-collapse: collapse; width: 100%;'>";
    echo "<tr style='background: #006400; color: white;'>";
    echo "<th>Employee Name</th><th>Username</th><th>Department</th><th>Position</th><th>Leave Type</th><th>From Date</th><th>To Date</th><th>Days</th><th>Reason</th><th>Status</th><th>Applied Date</th><th>Approved By</th><th>Approved Date</th><th>Rejected By</th><th>Rejected Date</th><th>Leave Year</th><th>LOP Type</th>";
    echo "</tr>";
    
    if ($export_leaves->num_rows > 0) {
        while ($row = $export_leaves->fetch_assoc()) {
            $row_leave_year = $row['leave_year'] ?? getLeaveYearForDate($row['from_date'])['year_label'];
            $is_auto_lop = ($row['is_auto_lop'] == 1);
            $is_lop = ($row['leave_type'] == 'LOP');
            $is_sick = ($row['leave_type'] == 'Sick');
            $is_casual = ($row['leave_type'] == 'Casual');
            $lop_type_display = $is_lop ? ($is_auto_lop ? 'Auto-generated LOP' : 'Manual LOP') : 'Regular Leave';
            
            $row_class = '';
            if ($is_lop) {
                $row_class = $is_auto_lop ? 'auto-lop-row' : 'lop-row';
            } elseif ($is_sick) {
                $row_class = 'sick-row';
            } elseif ($is_casual) {
                $row_class = 'casual-row';
            }
            
            $type_color = '';
            if ($is_lop) $type_color = 'lop-text';
            elseif ($is_sick) $type_color = 'sick-text';
            elseif ($is_casual) $type_color = 'casual-text';
            
            echo "<tr class='$row_class'>";
            echo "<td>" . htmlspecialchars($row['full_name']) . "</td>";
            echo "<td>" . htmlspecialchars($row['username']) . "</td>";
            echo "<td>" . htmlspecialchars($row['department'] ?? 'N/A') . "</td>";
            echo "<td>" . htmlspecialchars($row['position'] ?? 'N/A') . "</td>";
            echo "<td class='$type_color'>" . htmlspecialchars($row['leave_type']) . "</td>";
            echo "<td class='date'>" . $row['from_date'] . "</td>";
            echo "<td class='date'>" . $row['to_date'] . "</td>";
            echo "<td class='number $type_color'>" . $row['days'] . "</td>";
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
    } else {
        echo "<tr><td colspan='17' style='text-align: center; padding: 20px;'>No leave entries found for the selected period</td></tr>";
    }
    
    echo "</table></body></html>";
    exit();
}

// Export Permissions functionality - UPDATED with employee-wise summary and removed cancelled permissions
if (isset($_POST['export_permissions'])) {
    $export_type = sanitize($_POST['export_type']);
    $export_from_date = isset($_POST['export_from_date']) ? sanitize($_POST['export_from_date']) : '';
    $export_to_date = isset($_POST['export_to_date']) ? sanitize($_POST['export_to_date']) : '';
    $export_permission_type = isset($_POST['export_permission_type']) ? sanitize($_POST['export_permission_type']) : 'all';
    $export_year = isset($_POST['export_year']) ? sanitize($_POST['export_year']) : $year_filter;
    
    // Build WHERE clause based on date range - EXCLUDE CANCELLED
    $permission_where = "p.status != 'Cancelled'";
    
    // Get start and end dates for the year
    $export_year_parts = explode('-', $export_year);
    $export_start_year = $export_year_parts[0];
    $year_start = $export_start_year . '-03-16';
    $year_end = ($export_start_year + 1) . '-03-15';
    
    if ($export_type === 'date_range' && !empty($export_from_date) && !empty($export_to_date)) {
        $permission_where .= " AND p.permission_date >= '$export_from_date' AND p.permission_date <= '$export_to_date'";
        $range_label = date('d M Y', strtotime($export_from_date)) . ' to ' . date('d M Y', strtotime($export_to_date));
    } else {
        $permission_where .= " AND p.permission_date BETWEEN '$year_start' AND '$year_end'";
        $range_label = 'Year: ' . $export_year . ' (' . $year_start . ' to ' . $year_end . ')';
    }
    
    // Apply permission type filters
    if ($export_permission_type === 'lop') {
        $permission_where .= " AND p.status = 'LOP'";
    } elseif ($export_permission_type === 'pending') {
        $permission_where .= " AND p.status = 'Pending'";
    } elseif ($export_permission_type === 'approved') {
        $permission_where .= " AND p.status = 'Approved'";
    } elseif ($export_permission_type === 'rejected') {
        $permission_where .= " AND p.status = 'Rejected'";
    }
    
    // Get ALL users with their permission summaries - INCLUDING those with zero permissions
    $users_query = $conn->query("
        SELECT u.id, u.full_name, u.username, u.department, u.position,
               COALESCE(SUM(CASE WHEN p.status = 'LOP' THEN p.duration ELSE 0 END), 0) as lop_hours,
               COALESCE(SUM(CASE WHEN p.status = 'Approved' THEN p.duration ELSE 0 END), 0) as approved_hours,
               COALESCE(SUM(CASE WHEN p.status = 'Pending' THEN p.duration ELSE 0 END), 0) as pending_hours,
               COALESCE(SUM(CASE WHEN p.status = 'Rejected' THEN p.duration ELSE 0 END), 0) as rejected_hours,
               COALESCE(SUM(p.duration), 0) as total_hours,
               COUNT(CASE WHEN p.status = 'LOP' THEN 1 END) as lop_count,
               COUNT(CASE WHEN p.status = 'Approved' THEN 1 END) as approved_count,
               COUNT(CASE WHEN p.status = 'Pending' THEN 1 END) as pending_count,
               COUNT(CASE WHEN p.status = 'Rejected' THEN 1 END) as rejected_count,
               COUNT(p.id) as total_permissions
        FROM users u
        LEFT JOIN permissions p ON u.id = p.user_id AND ($permission_where)
        GROUP BY u.id
        ORDER BY u.full_name
    ");
    
    $user_summaries = $users_query->fetch_all(MYSQLI_ASSOC);
    
    // Get detailed permissions for the range - EXCLUDE CANCELLED
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
    
    // Build filename
    $filename = "permissions_{$export_year}";
    if ($export_type === 'date_range' && !empty($export_from_date) && !empty($export_to_date)) {
        $filename .= "_" . date('d-m-Y', strtotime($export_from_date)) . '_to_' . date('d-m-Y', strtotime($export_to_date));
    }
    if ($export_permission_type !== 'all') {
        $filename .= '_' . $export_permission_type;
    }
    $filename .= ".xls";
    
    header("Content-Type: application/vnd.ms-excel");
    header("Content-Disposition: attachment; filename=\"$filename\"");
    header("Pragma: no-cache");
    header("Expires: 0");
    header("X-Download-By: Microsoft Office Professional Plus Build 17928.20148");
    
    echo "<html><head><meta charset=\"UTF-8\"><style>
        td { mso-number-format:\\@; vertical-align: top; padding: 5px; }
        .date { mso-number-format:'yyyy-mm-dd'; }
        .number { mso-number-format:'0.00'; }
        .stats-box { background: #f0f7ff; border: 2px solid #4299e1; border-radius: 10px; padding: 15px; margin-bottom: 20px; }
        .stats-title { font-size: 16px; font-weight: bold; color: #2c5282; margin-bottom: 10px; }
        .stats-grid { display: flex; flex-wrap: wrap; gap: 15px; }
        .stat-item { flex: 1; min-width: 150px; background: white; padding: 10px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
        .stat-label { font-size: 12px; color: #718096; }
        .stat-value { font-size: 24px; font-weight: bold; }
        .employee-summary-box { background: #f9f9f9; border: 2px solid #9f7aea; border-radius: 10px; padding: 15px; margin-bottom: 20px; }
        .employee-summary-title { font-size: 16px; font-weight: bold; color: #6b46c1; margin-bottom: 10px; }
        .summary-table { width: 100%; border-collapse: collapse; margin-top: 10px; }
        .summary-table th { background: #6b46c1; color: white; padding: 8px; text-align: left; }
        .summary-table td { padding: 8px; border-bottom: 1px solid #cbd5e0; }
        .summary-table tr:hover { background: #f3e8ff; }
        .lop-row { background-color: #fee2e2; }
        .approved-row { background-color: #dcfce7; }
        .pending-row { background-color: #fef9c3; }
        .rejected-row { background-color: #fee2e2; }
        .lop-text { color: #dc2626; font-weight: bold; }
        .approved-text { color: #16a34a; font-weight: bold; }
        .pending-text { color: #ca8a04; font-weight: bold; }
        .rejected-text { color: #dc2626; font-weight: bold; }
        .zero-permission { color: #94a3b8; font-style: italic; }
    </style></head><body>";
    
    echo "<h2 style='color: #006400;'>🏢 MAKSIM HR - Permissions Export</h2>";
    echo "<p><strong>Leave Year:</strong> " . $export_year . "</p>";
    echo "<p><strong>Date Range:</strong> " . $range_label . "</p>";
    echo "<p><strong>Permission Type:</strong> " . ($export_permission_type == 'lop' ? 'LOP Only' : ($export_permission_type == 'pending' ? 'Pending Only' : ($export_permission_type == 'approved' ? 'Approved Only' : ($export_permission_type == 'rejected' ? 'Rejected Only' : 'All Permissions')))) . "</p>";
    echo "<p><strong>Cancelled Permissions:</strong> Excluded from report</p>";
    
    // Statistics Box
    $total_lop_hours = array_sum(array_column($user_summaries, 'lop_hours'));
    $total_approved_hours = array_sum(array_column($user_summaries, 'approved_hours'));
    $total_pending_hours = array_sum(array_column($user_summaries, 'pending_hours'));
    $total_rejected_hours = array_sum(array_column($user_summaries, 'rejected_hours'));
    $total_hours = array_sum(array_column($user_summaries, 'total_hours'));
    
    echo "<div class='stats-box'>";
    echo "<div class='stats-title'>📊 Permission Statistics for " . $export_year . "</div>";
    echo "<div class='stats-grid'>";
    
    echo "<div class='stat-item'>";
    echo "<div class='stat-label'>Total Permission Hours</div>";
    echo "<div class='stat-value' style='color: #6b46c1;'>" . number_format($total_hours, 1) . "</div>";
    echo "<div style='font-size: 11px; color: #6b7280;'>Employees: " . count(array_filter($user_summaries, function($u) { return $u['total_hours'] > 0; })) . "</div>";
    echo "</div>";
    
    echo "<div class='stat-item'>";
    echo "<div class='stat-label'>LOP Hours</div>";
    echo "<div class='stat-value' style='color: #dc2626;'>" . number_format($total_lop_hours, 1) . "</div>";
    echo "</div>";
    
    echo "<div class='stat-item'>";
    echo "<div class='stat-label'>Approved Hours</div>";
    echo "<div class='stat-value' style='color: #16a34a;'>" . number_format($total_approved_hours, 1) . "</div>";
    echo "</div>";
    
    echo "<div class='stat-item'>";
    echo "<div class='stat-label'>Pending Hours</div>";
    echo "<div class='stat-value' style='color: #ca8a04;'>" . number_format($total_pending_hours, 1) . "</div>";
    echo "</div>";
    
    echo "<div class='stat-item'>";
    echo "<div class='stat-label'>Rejected Hours</div>";
    echo "<div class='stat-value' style='color: #dc2626;'>" . number_format($total_rejected_hours, 1) . "</div>";
    echo "</div>";
    
    echo "</div></div>";
    
    // Employee-wise Summary Box - ALL EMPLOYEES INCLUDED
    echo "<div class='employee-summary-box'>";
    echo "<div class='employee-summary-title'>👥 Employee-wise Permission Summary (All Employees) - " . $export_year . "</div>";
    echo "<table class='summary-table' border='1' cellpadding='5' style='border-collapse: collapse; width: 100%;'>";
    echo "<tr>";
    echo "<th style='background: #6b46c1; color: white;'>S.No</th>";
    echo "<th style='background: #6b46c1; color: white;'>EMP ID</th>";
    echo "<th style='background: #6b46c1; color: white;'>EMP NAME</th>";
    echo "<th style='background: #6b46c1; color: white;'>Department</th>";
    echo "<th style='background: #6b46c1; color: white;'>LOP Hours</th>";
    echo "<th style='background: #6b46c1; color: white;'>Approved Hours</th>";
    echo "<th style='background: #6b46c1; color: white;'>Pending Hours</th>";
    echo "<th style='background: #6b46c1; color: white;'>Rejected Hours</th>";
    echo "<th style='background: #6b46c1; color: white;'>Total Hours</th>";
    echo "<th style='background: #6b46c1; color: white;'>Total Permissions</th>";
    echo "</tr>";
    
    $sno = 1;
    foreach ($user_summaries as $user) {
        echo "<tr>";
        echo "<td>" . $sno++ . "</td>";
        echo "<td>" . htmlspecialchars($user['username']) . "</td>";
        echo "<td><strong>" . htmlspecialchars($user['full_name']) . "</strong></td>";
        echo "<td>" . htmlspecialchars($user['department'] ?? 'N/A') . "</td>";
        
        // LOP Hours
        if ($user['lop_hours'] > 0) {
            echo "<td class='lop-text'><strong>" . number_format($user['lop_hours'], 1) . "</strong> (" . $user['lop_count'] . " apps)</td>";
        } else {
            echo "<td class='zero-permission'>0</td>";
        }
        
        // Approved Hours
        if ($user['approved_hours'] > 0) {
            echo "<td class='approved-text'><strong>" . number_format($user['approved_hours'], 1) . "</strong> (" . $user['approved_count'] . " apps)</td>";
        } else {
            echo "<td class='zero-permission'>0</td>";
        }
        
        // Pending Hours
        if ($user['pending_hours'] > 0) {
            echo "<td class='pending-text'><strong>" . number_format($user['pending_hours'], 1) . "</strong> (" . $user['pending_count'] . " apps)</td>";
        } else {
            echo "<td class='zero-permission'>0</td>";
        }
        
        // Rejected Hours
        if ($user['rejected_hours'] > 0) {
            echo "<td class='rejected-text'><strong>" . number_format($user['rejected_hours'], 1) . "</strong> (" . $user['rejected_count'] . " apps)</td>";
        } else {
            echo "<td class='zero-permission'>0</td>";
        }
        
        echo "<td><strong>" . number_format($user['total_hours'], 1) . "</strong></td>";
        echo "<td><strong>" . $user['total_permissions'] . "</strong></td>";
        echo "</tr>";
    }
    
    echo "</table></div>";
    
    // Detailed Permissions Table
    echo "<h3 style='margin-top: 20px;'>📋 Detailed Permission Entries</h3>";
    echo "<table border='1' cellpadding='5' style='border-collapse: collapse; width: 100%;'>";
    echo "<tr style='background: #6b46c1; color: white;'>";
    echo "<th>Employee Name</th><th>Username</th><th>Department</th><th>Position</th><th>Permission Date</th><th>Duration</th><th>Time Range</th><th>Reason</th><th>Status</th><th>Applied Date</th><th>Approved By</th><th>Approved Date</th><th>Rejected By</th><th>Rejected Date</th><th>Actions</th>";
    echo "</tr>";
    
    if ($export_permissions && $export_permissions->num_rows > 0) {
        while ($row = $export_permissions->fetch_assoc()) {
            $status_class = '';
            $status_color = '';
            
            if ($row['status'] == 'LOP') {
                $status_class = 'lop-row';
                $status_color = 'lop-text';
            } elseif ($row['status'] == 'Approved') {
                $status_class = 'approved-row';
                $status_color = 'approved-text';
            } elseif ($row['status'] == 'Pending') {
                $status_class = 'pending-row';
                $status_color = 'pending-text';
            } elseif ($row['status'] == 'Rejected') {
                $status_class = 'rejected-row';
                $status_color = 'rejected-text';
            }
            
            // Format time range if available
            $time_range_display = '-';
            if (!empty($row['time_from']) && !empty($row['time_to'])) {
                $from = date('g:i A', strtotime($row['time_from']));
                $to = date('g:i A', strtotime($row['time_to']));
                $time_range_display = $from . ' - ' . $to;
            }
            
            echo "<tr class='$status_class'>";
            echo "<td>" . htmlspecialchars($row['full_name']) . "</td>";
            echo "<td>" . htmlspecialchars($row['username']) . "</td>";
            echo "<td>" . htmlspecialchars($row['department'] ?? 'N/A') . "</td>";
            echo "<td>" . htmlspecialchars($row['position'] ?? 'N/A') . "</td>";
            echo "<td class='date'>" . $row['permission_date'] . "</td>";
            echo "<td class='number $status_color'><strong>" . number_format($row['duration'], 1) . " hrs</strong></td>";
            echo "<td>" . $time_range_display . "</td>";
            echo "<td>" . htmlspecialchars($row['reason']) . "</td>";
            echo "<td class='$status_color'><strong>" . htmlspecialchars($row['status']) . "</strong></td>";
            echo "<td class='date'>" . ($row['applied_date_only'] ?? '') . "</td>";
            echo "<td>" . htmlspecialchars($row['approved_by_name'] ?? 'N/A') . "</td>";
            echo "<td class='date'>" . ($row['approved_date_only'] ?? '') . "</td>";
            echo "<td>" . htmlspecialchars($row['rejected_by_name'] ?? 'N/A') . "</td>";
            echo "<td class='date'>" . ($row['rejected_date_only'] ?? '') . "</td>";
            echo "<td>";
            echo "<div class='action-buttons'>";
            
            // Delete button for permissions (Admin/PM only) - only for current year
            if (!$is_previous_year && in_array($role, ['admin', 'pm'])) {
                echo "<a href='?delete_permission={$row['id']}&leave_filter=" . urlencode($_GET['leave_filter'] ?? 'all') . "&permission_filter=" . urlencode($_GET['permission_filter'] ?? 'all') . "&leave_year=" . urlencode($_GET['leave_year'] ?? 'all') . "&leave_month=" . urlencode($_GET['leave_month'] ?? 'all') . "&permission_month=" . urlencode($_GET['permission_month'] ?? 'all') . "&permission_year=" . urlencode($_GET['permission_year'] ?? 'all') . "&permission_type_filter=" . urlencode($_GET['permission_type_filter'] ?? 'all') . "&leave_type_filter=" . urlencode($_GET['leave_type_filter'] ?? 'all') . "&view_type=" . urlencode($_GET['view_type'] ?? 'permissions') . "&year=" . urlencode($year_filter) . "' 
                       class='btn-small btn-delete-leave' 
                       onclick=\"return confirm('Delete this permission entry? This action cannot be undone.')\">
                        <i class='icon-delete'></i> Delete
                    </a>";
            }
            
            echo "</div>";
            echo "</td>";
            echo "</tr>";
        }
    } else {
        echo "<tr><td colspan='15' style='text-align: center; padding: 20px;'>No permission entries found for the selected period</td></tr>";
    }
    
    echo "</table></body></html>";
    exit();
}

// DELETE PERMISSION - Only for Admin and PM (only for current year)
if (!$is_previous_year && isset($_GET['delete_permission']) && in_array($role, ['admin', 'pm'])) {
    $permission_id = intval($_GET['delete_permission']);
    
    // Get permission details before deletion
    $get_perm = $conn->prepare("SELECT p.*, u.full_name FROM permissions p JOIN users u ON p.user_id = u.id WHERE p.id = ?");
    $get_perm->bind_param("i", $permission_id);
    $get_perm->execute();
    $permission = $get_perm->get_result()->fetch_assoc();
    $get_perm->close();
    
    if ($permission) {
        $conn->begin_transaction();
        
        try {
            // Create notification before deletion
            $title = "Permission Request Deleted";
            $duration_text = $permission['duration'] == 1 ? "1 hour" : ($permission['duration'] . " hours");
            $notification_msg = "Your permission request for {$permission['permission_date']} ({$duration_text}) has been deleted by an administrator.";
            createNotification($conn, $permission['user_id'], 'permission_deleted', $title, $notification_msg, $permission_id);
            
            $delete = $conn->prepare("DELETE FROM permissions WHERE id = ?");
            $delete->bind_param("i", $permission_id);
            $delete->execute();
            $delete->close();
            
            $conn->commit();
            
            $_SESSION['panel_message'] = '<div class="alert alert-warning"><i class="icon-warning"></i> Permission entry deleted successfully. Notification sent to employee.</div>';
            
        } catch (Exception $e) {
            $conn->rollback();
            $_SESSION['panel_message'] = '<div class="alert alert-error"><i class="icon-error"></i> Error deleting permission: ' . $e->getMessage() . '</div>';
        }
    } else {
        $_SESSION['panel_message'] = '<div class="alert alert-error"><i class="icon-error"></i> Permission not found.</div>';
    }
    
    $redirect_url = "panel.php?leave_filter=" . urlencode($_GET['leave_filter'] ?? 'all') . 
                    "&permission_filter=" . urlencode($_GET['permission_filter'] ?? 'all') . 
                    "&leave_year=" . urlencode($_GET['leave_year'] ?? 'all') . 
                    "&leave_month=" . urlencode($_GET['leave_month'] ?? 'all') .
                    "&permission_month=" . urlencode($_GET['permission_month'] ?? 'all') .
                    "&permission_year=" . urlencode($_GET['permission_year'] ?? 'all') .
                    "&permission_type_filter=" . urlencode($_GET['permission_type_filter'] ?? 'all') .
                    "&leave_type_filter=" . urlencode($_GET['leave_type_filter'] ?? 'all') .
                    "&view_type=" . urlencode($_GET['view_type'] ?? 'permissions') .
                    "&year=" . urlencode($year_filter);
    header("Location: $redirect_url");
    exit();
}

// Approve Leave - WITH NOTIFICATION AND LOP REMOVAL (only for current year)
if (!$is_previous_year && isset($_GET['approve_leave'])) {
    $leave_id = intval($_GET['approve_leave']);
    
    if ($role === 'hr') {
        $message = '<div class="alert alert-error"><i class="icon-error"></i> HR managers cannot approve leaves. Only Admins and Project Managers can.</div>';
    } else {
        // Get leave details for notification and LOP removal
        $get_leave = $conn->prepare("SELECT l.*, u.full_name, l.leave_year FROM leaves l JOIN users u ON l.user_id = u.id WHERE l.id = ?");
        $get_leave->bind_param("i", $leave_id);
        $get_leave->execute();
        $leave_data = $get_leave->get_result()->fetch_assoc();
        $get_leave->close();
        
        if ($leave_data) {
            // Check if this leave belongs to previous year
            if ($leave_data['leave_year'] != $current_leave_year['year_label']) {
                $message = '<div class="alert alert-error"><i class="icon-error"></i> Cannot approve leaves from previous years.</div>';
            } else {
                // Start transaction to ensure both operations succeed
                $conn->begin_transaction();
                
                try {
                    $stmt = $conn->prepare("
                        UPDATE leaves 
                        SET status = 'Approved', approved_by = ?, approved_date = NOW() 
                        WHERE id = ? AND status = 'Pending'
                    ");
                    $stmt->bind_param("ii", $user_id, $leave_id);
                    
                    if ($stmt->execute()) {
                        // Remove any auto-generated LOP for the dates in this leave range
                        $lop_removed = removeLOPForLeaveRange($conn, $leave_data['user_id'], $leave_data['from_date'], $leave_data['to_date']);
                        
                        // Create notification for the user
                        $title = "Leave Request Approved";
                        $notification_msg = "Your leave request from {$leave_data['from_date']} to {$leave_data['to_date']} ({$leave_data['days']} days) has been approved.";
                        if ($lop_removed > 0) {
                            $notification_msg .= " Any auto-generated LOP entries for these dates have been automatically removed.";
                        }
                        createNotification($conn, $leave_data['user_id'], 'leave_approved', $title, $notification_msg, $leave_id);
                        
                        $conn->commit();
                        
                        $message = '<div class="alert alert-success"><i class="icon-success"></i> Leave approved successfully. Notification sent to employee. Auto-generated LOP removed for affected dates.</div>';
                    } else {
                        throw new Exception("Error approving leave");
                    }
                    $stmt->close();
                    
                } catch (Exception $e) {
                    $conn->rollback();
                    $message = '<div class="alert alert-error"><i class="icon-error"></i> Error: ' . $e->getMessage() . '</div>';
                }
            }
        }
    }
}

// Reject Leave - WITH NOTIFICATION (only for current year)
if (!$is_previous_year && isset($_GET['reject_leave'])) {
    $leave_id = intval($_GET['reject_leave']);
    
    if ($role === 'hr') {
        $message = '<div class="alert alert-error"><i class="icon-error"></i> HR managers cannot reject leaves. Only Admins and Project Managers can.</div>';
    } else {
        // Get leave details for notification
        $get_leave = $conn->prepare("SELECT l.*, u.full_name, l.leave_year FROM leaves l JOIN users u ON l.user_id = u.id WHERE l.id = ?");
        $get_leave->bind_param("i", $leave_id);
        $get_leave->execute();
        $leave_data = $get_leave->get_result()->fetch_assoc();
        $get_leave->close();
        
        if ($leave_data) {
            // Check if this leave belongs to previous year
            if ($leave_data['leave_year'] != $current_leave_year['year_label']) {
                $message = '<div class="alert alert-error"><i class="icon-error"></i> Cannot reject leaves from previous years.</div>';
            } else {
                $stmt = $conn->prepare("
                    UPDATE leaves 
                    SET status = 'Rejected', rejected_by = ?, rejected_date = NOW() 
                    WHERE id = ? AND status = 'Pending'
                ");
                $stmt->bind_param("ii", $user_id, $leave_id);
                
                if ($stmt->execute()) {
                    // Create notification for the user
                    $title = "Leave Request Rejected";
                    $notification_msg = "Your leave request from {$leave_data['from_date']} to {$leave_data['to_date']} ({$leave_data['days']} days) has been rejected.";
                    createNotification($conn, $leave_data['user_id'], 'leave_rejected', $title, $notification_msg, $leave_id);
                    
                    $message = '<div class="alert alert-success"><i class="icon-success"></i> Leave rejected successfully. Notification sent to employee.</div>';
                } else {
                    $message = '<div class="alert alert-error"><i class="icon-error"></i> Error rejecting leave</div>';
                }
                $stmt->close();
            }
        }
    }
}

// DELETE LEAVE HANDLING - Only for Admin and PM - WITH NOTIFICATION (only for current year)
if (!$is_previous_year && isset($_GET['delete_leave']) && in_array($role, ['admin', 'pm'])) {
    $leave_id = intval($_GET['delete_leave']);
    
    // Get leave details before deletion
    $get_leave = $conn->prepare("SELECT l.*, u.full_name, l.leave_year FROM leaves l JOIN users u ON l.user_id = u.id WHERE l.id = ?");
    $get_leave->bind_param("i", $leave_id);
    $get_leave->execute();
    $leave = $get_leave->get_result()->fetch_assoc();
    $get_leave->close();
    
    if ($leave) {
        // Check if this leave belongs to previous year
        if ($leave['leave_year'] != $current_leave_year['year_label']) {
            $_SESSION['panel_message'] = '<div class="alert alert-error"><i class="icon-error"></i> Cannot delete leaves from previous years.</div>';
        } else {
            $conn->begin_transaction();
            
            try {
                // Create notification before deletion
                $title = "Leave Request Deleted";
                $notification_msg = "Your {$leave['leave_type']} leave request from {$leave['from_date']} to {$leave['to_date']} has been deleted by an administrator.";
                createNotification($conn, $leave['user_id'], 'leave_deleted', $title, $notification_msg, $leave_id);
                
                $delete = $conn->prepare("DELETE FROM leaves WHERE id = ?");
                $delete->bind_param("i", $leave_id);
                $delete->execute();
                $delete->close();
                
                $conn->commit();
                
                $_SESSION['panel_message'] = '<div class="alert alert-warning"><i class="icon-warning"></i> Leave entry deleted successfully. Notification sent to employee.</div>';
                
            } catch (Exception $e) {
                $conn->rollback();
                $_SESSION['panel_message'] = '<div class="alert alert-error"><i class="icon-error"></i> Error deleting leave: ' . $e->getMessage() . '</div>';
            }
        }
    } else {
        $_SESSION['panel_message'] = '<div class="alert alert-error"><i class="icon-error"></i> Leave not found.</div>';
    }
    
    $redirect_url = "panel.php?leave_filter=" . urlencode($_GET['leave_filter'] ?? 'all') . 
                    "&permission_filter=" . urlencode($_GET['permission_filter'] ?? 'all') . 
                    "&leave_year=" . urlencode($_GET['leave_year'] ?? 'all') . 
                    "&leave_month=" . urlencode($_GET['leave_month'] ?? 'all') .
                    "&permission_month=" . urlencode($_GET['permission_month'] ?? 'all') .
                    "&permission_year=" . urlencode($_GET['permission_year'] ?? 'all') .
                    "&permission_type_filter=" . urlencode($_GET['permission_type_filter'] ?? 'all') .
                    "&leave_type_filter=" . urlencode($_GET['leave_type_filter'] ?? 'all') .
                    "&view_type=" . urlencode($_GET['view_type'] ?? 'leaves') .
                    "&year=" . urlencode($year_filter);
    header("Location: $redirect_url");
    exit();
}

// Approve Permission - WITH NOTIFICATION (only for current year)
if (!$is_previous_year && isset($_GET['approve_permission'])) {
    $permission_id = intval($_GET['approve_permission']);
    
    if ($role === 'hr') {
        $message = '<div class="alert alert-error"><i class="icon-error"></i> HR managers cannot approve permissions. Only Admins and Project Managers can.</div>';
    } else {
        // Get permission details for notification
        $get_perm = $conn->prepare("SELECT p.*, u.full_name FROM permissions p JOIN users u ON p.user_id = u.id WHERE p.id = ?");
        $get_perm->bind_param("i", $permission_id);
        $get_perm->execute();
        $perm_data = $get_perm->get_result()->fetch_assoc();
        $get_perm->close();
        
        if ($perm_data) {
            // Check if this permission is from previous year using date range
            $perm_year = getLeaveYearForDate($perm_data['permission_date'])['year_label'];
            if ($perm_year != $current_leave_year['year_label']) {
                $message = '<div class="alert alert-error"><i class="icon-error"></i> Cannot approve permissions from previous years.</div>';
            } else {
                $stmt = $conn->prepare("
                    UPDATE permissions 
                    SET status = 'Approved', approved_by = ?, approved_date = NOW() 
                    WHERE id = ? AND status = 'Pending'
                ");
                $stmt->bind_param("ii", $user_id, $permission_id);
                
                if ($stmt->execute()) {
                    // Create notification for the user
                    $title = "Permission Request Approved";
                    $duration_text = $perm_data['duration'] == 1 ? "1 hour" : ($perm_data['duration'] . " hours");
                    $notification_msg = "Your permission request for {$perm_data['permission_date']} ({$duration_text}) has been approved.";
                    createNotification($conn, $perm_data['user_id'], 'permission_approved', $title, $notification_msg, $permission_id);
                    
                    $message = '<div class="alert alert-success"><i class="icon-success"></i> Permission approved successfully. Notification sent to employee.</div>';
                } else {
                    $message = '<div class="alert alert-error"><i class="icon-error"></i> Error approving permission</div>';
                }
                $stmt->close();
            }
        }
    }
}

// Reject Permission - WITH NOTIFICATION (only for current year)
if (!$is_previous_year && isset($_GET['reject_permission'])) {
    $permission_id = intval($_GET['reject_permission']);
    
    if ($role === 'hr') {
        $message = '<div class="alert alert-error"><i class="icon-error"></i> HR managers cannot reject permissions. Only Admins and Project Managers can.</div>';
    } else {
        // Get permission details for notification
        $get_perm = $conn->prepare("SELECT p.*, u.full_name FROM permissions p JOIN users u ON p.user_id = u.id WHERE p.id = ?");
        $get_perm->bind_param("i", $permission_id);
        $get_perm->execute();
        $perm_data = $get_perm->get_result()->fetch_assoc();
        $get_perm->close();
        
        if ($perm_data) {
            // Check if this permission is from previous year using date range
            $perm_year = getLeaveYearForDate($perm_data['permission_date'])['year_label'];
            if ($perm_year != $current_leave_year['year_label']) {
                $message = '<div class="alert alert-error"><i class="icon-error"></i> Cannot reject permissions from previous years.</div>';
            } else {
                $stmt = $conn->prepare("
                    UPDATE permissions 
                    SET status = 'Rejected', rejected_by = ?, rejected_date = NOW() 
                    WHERE id = ? AND status = 'Pending'
                ");
                $stmt->bind_param("ii", $user_id, $permission_id);
                
                if ($stmt->execute()) {
                    // Create notification for the user
                    $title = "Permission Request Rejected";
                    $duration_text = $perm_data['duration'] == 1 ? "1 hour" : ($perm_data['duration'] . " hours");
                    $notification_msg = "Your permission request for {$perm_data['permission_date']} ({$duration_text}) has been rejected.";
                    createNotification($conn, $perm_data['user_id'], 'permission_rejected', $title, $notification_msg, $permission_id);
                    
                    $message = '<div class="alert alert-success"><i class="icon-success"></i> Permission rejected successfully. Notification sent to employee.</div>';
                } else {
                    $message = '<div class="alert alert-error"><i class="icon-error"></i> Error rejecting permission</div>';
                }
                $stmt->close();
            }
        }
    }
}

// Approve LOP Permission - WITH NOTIFICATION AND LOP REMOVAL (only for current year)
if (!$is_previous_year && isset($_GET['approve_lop_permission'])) {
    $permission_id = intval($_GET['approve_lop_permission']);
    
    if ($role === 'hr') {
        $message = '<div class="alert alert-error"><i class="icon-error"></i> HR managers cannot approve LOP permissions. Only Admins and Project Managers can.</div>';
    } else {
        // Get permission details for notification
        $get_perm = $conn->prepare("SELECT p.*, u.full_name FROM permissions p JOIN users u ON p.user_id = u.id WHERE p.id = ?");
        $get_perm->bind_param("i", $permission_id);
        $get_perm->execute();
        $perm_data = $get_perm->get_result()->fetch_assoc();
        $get_perm->close();
        
        if ($perm_data) {
            // Check if this permission is from previous year using date range
            $perm_year = getLeaveYearForDate($perm_data['permission_date'])['year_label'];
            if ($perm_year != $current_leave_year['year_label']) {
                $message = '<div class="alert alert-error"><i class="icon-error"></i> Cannot approve LOP permissions from previous years.</div>';
            } else {
                $stmt = $conn->prepare("
                    UPDATE permissions 
                    SET status = 'Approved', approved_by = ?, approved_date = NOW() 
                    WHERE id = ? AND status = 'LOP'
                ");
                $stmt->bind_param("ii", $user_id, $permission_id);
                
                if ($stmt->execute()) {
                    // Create notification for the user
                    $title = "LOP Permission Approved";
                    $duration_text = $perm_data['duration'] == 1 ? "1 hour" : ($perm_data['duration'] . " hours");
                    $notification_msg = "Your LOP permission request for {$perm_data['permission_date']} ({$duration_text}) has been approved.";
                    createNotification($conn, $perm_data['user_id'], 'lop_approved', $title, $notification_msg, $permission_id);
                    
                    $message = '<div class="alert alert-success"><i class="icon-success"></i> LOP permission approved successfully. Notification sent to employee.</div>';
                } else {
                    $message = '<div class="alert alert-error"><i class="icon-error"></i> Error approving LOP permission</div>';
                }
                $stmt->close();
            }
        }
    }
}

// Reject LOP Permission - WITH NOTIFICATION (only for current year)
if (!$is_previous_year && isset($_GET['reject_lop_permission'])) {
    $permission_id = intval($_GET['reject_lop_permission']);
    
    if ($role === 'hr') {
        $message = '<div class="alert alert-error"><i class="icon-error"></i> HR managers cannot reject LOP permissions. Only Admins and Project Managers can.</div>';
    } else {
        // Get permission details for notification
        $get_perm = $conn->prepare("SELECT p.*, u.full_name FROM permissions p JOIN users u ON p.user_id = u.id WHERE p.id = ?");
        $get_perm->bind_param("i", $permission_id);
        $get_perm->execute();
        $perm_data = $get_perm->get_result()->fetch_assoc();
        $get_perm->close();
        
        if ($perm_data) {
            // Check if this permission is from previous year using date range
            $perm_year = getLeaveYearForDate($perm_data['permission_date'])['year_label'];
            if ($perm_year != $current_leave_year['year_label']) {
                $message = '<div class="alert alert-error"><i class="icon-error"></i> Cannot reject LOP permissions from previous years.</div>';
            } else {
                $stmt = $conn->prepare("
                    UPDATE permissions 
                    SET status = 'Rejected', rejected_by = ?, rejected_date = NOW() 
                    WHERE id = ? AND status = 'LOP'
                ");
                $stmt->bind_param("ii", $user_id, $permission_id);
                
                if ($stmt->execute()) {
                    // Create notification for the user
                    $title = "LOP Permission Rejected";
                    $duration_text = $perm_data['duration'] == 1 ? "1 hour" : ($perm_data['duration'] . " hours");
                    $notification_msg = "Your LOP permission request for {$perm_data['permission_date']} ({$duration_text}) has been rejected.";
                    createNotification($conn, $perm_data['user_id'], 'lop_rejected', $title, $notification_msg, $permission_id);
                    
                    $message = '<div class="alert alert-success"><i class="icon-success"></i> LOP permission rejected successfully. Notification sent to employee.</div>';
                } else {
                    $message = '<div class="alert alert-error"><i class="icon-error"></i> Error rejecting LOP permission</div>';
                }
                $stmt->close();
            }
        }
    }
}

// Get filter values
$leave_filter = isset($_GET['leave_filter']) ? $_GET['leave_filter'] : 'all';
$permission_filter = isset($_GET['permission_filter']) ? $_GET['permission_filter'] : 'all';
$leave_year_filter = isset($_GET['leave_year']) ? $_GET['leave_year'] : 'all';
$permission_year_filter = isset($_GET['permission_year']) ? $_GET['permission_year'] : 'all';
$leave_month_filter = isset($_GET['leave_month']) ? $_GET['leave_month'] : 'all';
$permission_month_filter = isset($_GET['permission_month']) ? $_GET['permission_month'] : 'all';
$leave_type_filter = isset($_GET['leave_type_filter']) ? $_GET['leave_type_filter'] : 'all';
$permission_type_filter = isset($_GET['permission_type_filter']) ? $_GET['permission_type_filter'] : 'all';
$view_type = isset($_GET['view_type']) ? $_GET['view_type'] : 'leaves';

// Get leaves with filter - for selected year - FIXED: Use STRICT date range filtering
// Calculate date range for the selected year
$year_start_date = $start_year . '-03-16';
$year_end_date = ($start_year + 1) . '-03-15';

// For leaves, use STRICT date range filtering - NO OR conditions that could pull in wrong data
$leave_where = "l.status != 'Cancelled' AND l.from_date BETWEEN '$year_start_date' AND '$year_end_date'";

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

// Date range (Mar 16 – Mar 15) already strictly filters by year — no extra label filter needed

if ($leave_month_filter != 'all' && !empty($leave_month_filter)) {
    $month_data = $leave_months[$leave_month_filter] ?? null;
    if ($month_data) {
        $leave_where .= " AND l.from_date >= '{$month_data['start_date']}' AND l.from_date <= '{$month_data['end_date']}'";
    }
}

// Get permissions with filter - use permission_year_filter for strict Mar 16-Mar 15 range
$_perm_prev_ly = getPreviousLeaveYear();
if ($permission_year_filter === $_perm_prev_ly['year_label']) {
    $perm_start_date = $_perm_prev_ly['start_date'];
    $perm_end_date   = $_perm_prev_ly['end_date'];
    $is_previous_perm_year = true;
} else {
    $perm_start_date = $current_leave_year['start_date'];
    $perm_end_date   = $current_leave_year['end_date'];
    $is_previous_perm_year = false;
}

$permission_where = "p.status != 'Cancelled' AND p.permission_date BETWEEN '$perm_start_date' AND '$perm_end_date'";

if ($permission_filter != 'all') {
    if ($permission_filter == 'lop') {
        $permission_where .= " AND p.status = 'LOP'";
    } else {
        $permission_where .= " AND p.status = '" . $conn->real_escape_string($permission_filter) . "'";
    }
}

// Permission type filter: regular vs LOP permission
if ($permission_type_filter == 'regular') {
    $permission_where .= " AND p.status != 'LOP'";
} elseif ($permission_type_filter == 'lop') {
    $permission_where .= " AND p.status = 'LOP'";
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

// Get statistics for selected year
// Calculate date range for the selected year
$year_start_date = $start_year . '-03-16';
$year_end_date = ($start_year + 1) . '-03-15';

$stats_result = $conn->query("
    SELECT 
        (SELECT COUNT(*) FROM users) as total_users,
        (SELECT COUNT(*) FROM leaves WHERE status = 'Pending' AND from_date BETWEEN '$year_start_date' AND '$year_end_date') as pending_leaves,
        (SELECT COUNT(*) FROM permissions WHERE status = 'Pending' AND permission_date BETWEEN '$year_start_date' AND '$year_end_date') as pending_permissions,
        (SELECT COUNT(*) FROM permissions WHERE status = 'LOP' AND permission_date BETWEEN '$year_start_date' AND '$year_end_date') as lop_permissions,
        (SELECT COUNT(*) FROM leaves WHERE status = 'Approved' AND from_date BETWEEN '$year_start_date' AND '$year_end_date' AND DATE(approved_date) = CURDATE()) as today_approved_leaves,
        (SELECT COUNT(*) FROM leaves WHERE status = 'Rejected' AND from_date BETWEEN '$year_start_date' AND '$year_end_date' AND DATE(rejected_date) = CURDATE()) as today_rejected_leaves,
        (SELECT COUNT(*) FROM permissions WHERE status = 'Approved' AND permission_date BETWEEN '$year_start_date' AND '$year_end_date' AND DATE(approved_date) = CURDATE()) as today_approved_permissions,
        (SELECT COUNT(*) FROM permissions WHERE status = 'Rejected' AND permission_date BETWEEN '$year_start_date' AND '$year_end_date' AND DATE(rejected_date) = CURDATE()) as today_rejected_permissions,
        (SELECT COUNT(*) FROM permissions WHERE status = 'LOP' AND permission_date BETWEEN '$year_start_date' AND '$year_end_date' AND DATE(applied_date) = CURDATE()) as today_lop_permissions
");

if ($stats_result) {
    $stats = $stats_result->fetch_assoc();
} else {
    $stats = [
        'total_users' => 0,
        'pending_leaves' => 0,
        'pending_permissions' => 0,
        'lop_permissions' => 0,
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
        .export-date-range { display: flex; gap: 10px; margin-left: 20px; flex-wrap: wrap; }
        .export-date-range input[type="date"] { flex: 1; min-width: 150px; }
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
        .status-info { font-size: 12px; color: #000000f5; margin-top: 4px; }
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
        
        /* Year filter styles */
        .year-filter {
            background: white;
            border-radius: 8px;
            padding: 15px 20px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 15px;
            flex-wrap: wrap;
            box-shadow: 0 2px 5px rgba(0,0,0,0.05);
        }
        .year-filter-label {
            font-weight: 600;
            color: #4a5568;
            display: flex;
            align-items: center;
            gap: 5px;
        }
        .year-filter-select {
            padding: 8px 12px;
            border: 2px solid #e2e8f0;
            border-radius: 6px;
            font-size: 14px;
            min-width: 200px;
        }
        .year-filter-select:focus {
            outline: none;
            border-color: #006400;
        }
        .year-badge {
            background: #006400;
            color: white;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 5px;
        }
        .previous-year-badge {
            background: #805ad5;
            color: white;
        }
        .archive-note {
            background: #f0f7ff;
            border-left: 4px solid #805ad5;
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
            color: #553c9a;
        }
        .action-disabled {
            opacity: 0.5;
            pointer-events: none;
            cursor: not-allowed;
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
                    <div class="stat-sub"><?php echo $year_filter; ?></div>
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

            <!-- Export Modals - UPDATED with date range and year -->
            <div id="exportLeavesModal" class="export-modal">
                <div class="export-modal-content">
                    <h3><i class="icon-excel"></i> Export Leaves to Excel</h3>
                    <form method="POST" action="">
                        <input type="hidden" name="export_year" value="<?php echo $year_filter; ?>">
                        <div class="export-options">
                            <div class="export-option-group">
                                <input type="radio" id="leaves_all" name="export_type" value="all" checked>
                                <label for="leaves_all">Export All Leaves for <?php echo $year_filter; ?></label>
                            </div>
                            <div class="export-option-group">
                                <input type="radio" id="leaves_date_range" name="export_type" value="date_range">
                                <label for="leaves_date_range">Export by Date Range</label>
                                <div class="export-date-range">
                                    <input type="date" name="export_from_date" id="export_from_date" class="form-control" placeholder="From Date">
                                    <span>to</span>
                                    <input type="date" name="export_to_date" id="export_to_date" class="form-control" placeholder="To Date">
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
                        <input type="hidden" name="export_year" value="<?php echo $year_filter; ?>">
                        <div class="export-options">
                            <div class="export-option-group">
                                <input type="radio" id="permissions_all" name="export_type" value="all" checked>
                                <label for="permissions_all">Export All Permissions for <?php echo $year_filter; ?></label>
                            </div>
                            <div class="export-option-group">
                                <input type="radio" id="permissions_date_range" name="export_type" value="date_range">
                                <label for="permissions_date_range">Export by Date Range</label>
                                <div class="export-date-range">
                                    <input type="date" name="export_from_date" id="export_permission_from_date" class="form-control" placeholder="From Date">
                                    <span>to</span>
                                    <input type="date" name="export_to_date" id="export_permission_to_date" class="form-control" placeholder="To Date">
                                </div>
                            </div>
                            
                            <div class="export-option-group" style="margin-top: 10px;">
                                <span style="font-weight: 600;">Permission Type:</span>
                                <div class="export-lop-type">
                                    <label><input type="radio" name="export_permission_type" value="all" checked> All Permissions</label>
                                    <label><input type="radio" name="export_permission_type" value="lop"> LOP Only</label>
                                    <label><input type="radio" name="export_permission_type" value="pending"> Pending Only</label>
                                    <label><input type="radio" name="export_permission_type" value="approved"> Approved Only</label>
                                    <label><input type="radio" name="export_permission_type" value="rejected"> Rejected Only</label>
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
                            <?php if ($is_previous_year): ?>
                            <span class="year-badge previous-year-badge" style="margin-left: 10px;">Archived: <?php echo $year_filter; ?></span>
                            <?php endif; ?>
                        </h3>
                        
                        <div class="view-type-selector">
                            <a href="?view_type=leaves&leave_filter=<?php echo $leave_filter; ?>&permission_filter=<?php echo $permission_filter; ?>&leave_year=<?php echo $leave_year_filter; ?>&leave_month=<?php echo $leave_month_filter; ?>&permission_month=<?php echo $permission_month_filter; ?>&permission_year=<?php echo urlencode($permission_year_filter); ?>&permission_type_filter=<?php echo $permission_type_filter; ?>&leave_type_filter=<?php echo $leave_type_filter; ?>&year=<?php echo urlencode($year_filter); ?>" 
                               class="view-type-btn <?php echo $view_type == 'leaves' ? 'active' : 'inactive'; ?>">
                                <i class="icon-leave"></i> Leaves
                            </a>
                            <a href="?view_type=permissions&leave_filter=<?php echo $leave_filter; ?>&permission_filter=<?php echo $permission_filter; ?>&leave_year=<?php echo $leave_year_filter; ?>&leave_month=<?php echo $leave_month_filter; ?>&permission_month=<?php echo $permission_month_filter; ?>&permission_year=<?php echo urlencode($permission_year_filter); ?>&permission_type_filter=<?php echo $permission_type_filter; ?>&leave_type_filter=<?php echo $leave_type_filter; ?>&year=<?php echo urlencode($year_filter); ?>" 
                               class="view-type-btn <?php echo $view_type == 'permissions' ? 'active' : 'inactive'; ?>">
                                <i class="icon-clock"></i> Permissions
                            </a>
                        </div>
                    </div>
                    
                    <div class="export-btn-group" style="margin-top: 15px;">
                        <form method="GET" action="" id="filterForm">
                            <input type="hidden" name="view_type" value="<?php echo $view_type; ?>">
                            <input type="hidden" name="year" value="<?php echo $year_filter; ?>">
                            
                            <?php if ($view_type == 'leaves'): ?>
                            <div class="filter-container">
                                <div class="filter-item">
                                    <label><i class="icon-calendar"></i> Leave Month:</label>
                                    <select name="leave_month" class="form-control">
                                        <option value="all" <?php echo ($leave_month_filter == 'all') ? 'selected' : ''; ?>>All Months</option>
                                        <?php foreach ($leave_months as $key => $month_data): ?>
                                            <option value="<?php echo $key; ?>" <?php echo ($leave_month_filter == $key) ? 'selected' : ''; ?>>
                                                <?php echo $month_data['label']; ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                
                                <div class="filter-item">
                                    <label><i class="icon-calendar"></i> Year:</label>
                                    <select name="leave_year" class="form-control">
                                        <option value="<?php echo $current_leave_year['year_label']; ?>" <?php echo ($year_filter === $current_leave_year['year_label']) ? 'selected' : ''; ?>>
                                            Current (<?php echo $current_leave_year['year_label']; ?>)
                                        </option>
                                        <option value="<?php echo $_prev_ly['year_label']; ?>" <?php echo ($year_filter === $_prev_ly['year_label']) ? 'selected' : ''; ?>>
                                            Previous (<?php echo $_prev_ly['year_label']; ?>)
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
                                        <?php foreach ($leave_months as $key => $month_data): ?>
                                            <option value="<?php echo $key; ?>" <?php echo ($permission_month_filter == $key) ? 'selected' : ''; ?>>
                                                <?php echo $month_data['label']; ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                
                                <div class="filter-item">
                                    <label><i class="icon-calendar"></i> Year:</label>
                                    <select name="permission_year" class="form-control">
                                        <option value="<?php echo $current_leave_year['year_label']; ?>" <?php echo (!$is_previous_perm_year) ? 'selected' : ''; ?>>
                                            Current (<?php echo $current_leave_year['year_label']; ?>)
                                        </option>
                                        <option value="<?php echo $_perm_prev_ly['year_label']; ?>" <?php echo ($is_previous_perm_year) ? 'selected' : ''; ?>>
                                            Previous (<?php echo $_perm_prev_ly['year_label']; ?>)
                                        </option>
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
                                
                                <div class="filter-item">
                                    <label><i class="icon-filter"></i> Type:</label>
                                    <select name="permission_type_filter" class="form-control">
                                        <option value="all" <?php echo ($permission_type_filter == 'all') ? 'selected' : ''; ?>>All Types</option>
                                        <option value="regular" <?php echo ($permission_type_filter == 'regular') ? 'selected' : ''; ?>>Permission</option>
                                        <option value="lop" <?php echo ($permission_type_filter == 'lop') ? 'selected' : ''; ?>>LOP Permission</option>
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
                            <i class="icon-excel"></i> Export Leaves
                        </button>
                        
                        <button class="btn btn-success" onclick="openExportModal('permissions')">
                            <i class="icon-excel"></i> Export Permissions
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
                                                <?php if ($role == 'hr' || $is_previous_year): ?>
                                                    <button class="btn-small btn-approve action-disabled" disabled title="<?php echo $is_previous_year ? 'Cannot approve archived leaves' : 'HR cannot approve leaves'; ?>"><i class="icon-check"></i> Approve</button>
                                                    <button class="btn-small btn-reject action-disabled" disabled title="<?php echo $is_previous_year ? 'Cannot reject archived leaves' : 'HR cannot reject leaves'; ?>"><i class="icon-cancel"></i> Reject</button>
                                                <?php else: ?>
                                                    <a href="?approve_leave=<?php echo $leave['id']; ?>&leave_filter=<?php echo $leave_filter; ?>&permission_filter=<?php echo $permission_filter; ?>&leave_year=<?php echo $leave_year_filter; ?>&leave_month=<?php echo $leave_month_filter; ?>&permission_month=<?php echo $permission_month_filter; ?>&permission_year=<?php echo urlencode($permission_year_filter); ?>&permission_type_filter=<?php echo $permission_type_filter; ?>&leave_type_filter=<?php echo $leave_type_filter; ?>&view_type=<?php echo $view_type; ?>&year=<?php echo urlencode($year_filter); ?>" 
                                                       class="btn-small btn-approve" onclick="return confirm('Approve this leave?')"><i class="icon-check"></i> Approve</a>
                                                    <a href="?reject_leave=<?php echo $leave['id']; ?>&leave_filter=<?php echo $leave_filter; ?>&permission_filter=<?php echo $permission_filter; ?>&leave_year=<?php echo $leave_year_filter; ?>&leave_month=<?php echo $leave_month_filter; ?>&permission_month=<?php echo $permission_month_filter; ?>&permission_year=<?php echo urlencode($permission_year_filter); ?>&permission_type_filter=<?php echo $permission_type_filter; ?>&leave_type_filter=<?php echo $leave_type_filter; ?>&view_type=<?php echo $view_type; ?>&year=<?php echo urlencode($year_filter); ?>" 
                                                       class="btn-small btn-reject" onclick="return confirm('Reject this leave?')"><i class="icon-cancel"></i> Reject</a>
                                                <?php endif; ?>
                                            <?php endif; ?>
                                            
                                            <?php if (!$is_previous_year && $leave['status'] == 'Approved' && in_array($role, ['admin', 'pm'])): ?>
                                                <a href="?delete_leave=<?php echo $leave['id']; ?>&leave_filter=<?php echo $leave_filter; ?>&permission_filter=<?php echo $permission_filter; ?>&leave_year=<?php echo $leave_year_filter; ?>&leave_month=<?php echo $leave_month_filter; ?>&permission_month=<?php echo $permission_month_filter; ?>&permission_year=<?php echo urlencode($permission_year_filter); ?>&permission_type_filter=<?php echo $permission_type_filter; ?>&leave_type_filter=<?php echo $leave_type_filter; ?>&view_type=<?php echo $view_type; ?>&year=<?php echo urlencode($year_filter); ?>" 
                                                   class="btn-small btn-delete-leave" onclick="return confirm('Delete this approved leave? This will send notification to the employee.')"><i class="icon-delete"></i> Delete</a>
                                            <?php elseif ($is_previous_year && $leave['status'] == 'Approved'): ?>
                                                <span class="btn-small btn-delete-leave action-disabled" style="opacity:0.5;" title="Cannot delete archived leaves"><i class="icon-delete"></i> Delete</span>
                                            <?php endif; ?>
                                            
                                            <a href="../leaves/leave_details.php?id=<?php echo $leave['id']; ?>" class="btn-small btn-view"><i class="icon-view"></i> View</a>
                                        </div>
                                    </td>
                                </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr><td colspan="10" style="text-align: center; padding: 40px; color: #718096;"><i class="icon-leave" style="font-size: 48px; margin-bottom: 15px; display: block; color: #cbd5e0;"></i>No leave requests found for <?php echo $year_filter; ?></td></tr>
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
                                <th>Time Range</th>
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
                                    $has_time_range = !empty($permission['time_from']) && !empty($permission['time_to']);
                                ?>
                                <tr <?php echo $is_lop ? 'class="lop-row"' : ''; ?>>
                                    <td><?php echo htmlspecialchars($permission['full_name']); ?></td>
                                    <td><?php echo htmlspecialchars($permission['permission_date']); ?></td>
                                    <td><?php echo htmlspecialchars($permission['duration']); ?> hours</td>
                                    <td>
                                        <?php if ($has_time_range): ?>
                                            <?php 
                                            $from = date('g:i A', strtotime($permission['time_from']));
                                            $to = date('g:i A', strtotime($permission['time_to']));
                                            echo $from . ' - ' . $to;
                                            ?>
                                        <?php else: ?>
                                            -
                                        <?php endif; ?>
                                    </td>
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
                                                <?php if ($role == 'hr' || $is_previous_year): ?>
                                                    <button class="btn-small btn-approve action-disabled" disabled><i class="icon-check"></i> Approve</button>
                                                    <button class="btn-small btn-reject action-disabled" disabled><i class="icon-cancel"></i> Reject</button>
                                                <?php else: ?>
                                                    <a href="?approve_permission=<?php echo $permission['id']; ?>&leave_filter=<?php echo $leave_filter; ?>&permission_filter=<?php echo $permission_filter; ?>&leave_year=<?php echo $leave_year_filter; ?>&leave_month=<?php echo $leave_month_filter; ?>&permission_month=<?php echo $permission_month_filter; ?>&permission_year=<?php echo urlencode($permission_year_filter); ?>&permission_type_filter=<?php echo $permission_type_filter; ?>&leave_type_filter=<?php echo $leave_type_filter; ?>&view_type=<?php echo $view_type; ?>&year=<?php echo urlencode($year_filter); ?>" 
                                                       class="btn-small btn-approve" onclick="return confirm('Approve this permission? This will send notification to the employee.')"><i class="icon-check"></i> Approve</a>
                                                    <a href="?reject_permission=<?php echo $permission['id']; ?>&leave_filter=<?php echo $leave_filter; ?>&permission_filter=<?php echo $permission_filter; ?>&leave_year=<?php echo $leave_year_filter; ?>&leave_month=<?php echo $leave_month_filter; ?>&permission_month=<?php echo $permission_month_filter; ?>&permission_year=<?php echo urlencode($permission_year_filter); ?>&permission_type_filter=<?php echo $permission_type_filter; ?>&leave_type_filter=<?php echo $leave_type_filter; ?>&view_type=<?php echo $view_type; ?>&year=<?php echo urlencode($year_filter); ?>" 
                                                       class="btn-small btn-reject" onclick="return confirm('Reject this permission? This will send notification to the employee.')"><i class="icon-cancel"></i> Reject</a>
                                                <?php endif; ?>
                                            <?php elseif ($is_lop && $permission['status'] == 'LOP'): ?>
                                                <?php if ($role == 'hr' || $is_previous_year): ?>
                                                    <button class="btn-small action-disabled" disabled><i class="icon-check"></i> Approve LOP</button>
                                                    <button class="btn-small action-disabled" disabled><i class="icon-cancel"></i> Reject LOP</button>
                                                <?php else: ?>
                                                    <a href="?approve_lop_permission=<?php echo $permission['id']; ?>&leave_filter=<?php echo $leave_filter; ?>&permission_filter=<?php echo $permission_filter; ?>&leave_year=<?php echo $leave_year_filter; ?>&leave_month=<?php echo $leave_month_filter; ?>&permission_month=<?php echo $permission_month_filter; ?>&permission_year=<?php echo urlencode($permission_year_filter); ?>&permission_type_filter=<?php echo $permission_type_filter; ?>&leave_type_filter=<?php echo $leave_type_filter; ?>&view_type=<?php echo $view_type; ?>&year=<?php echo urlencode($year_filter); ?>" 
                                                       class="btn-small btn-lop-approve" onclick="return confirm('Approve this LOP permission? This will send notification to the employee.')"><i class="icon-check"></i> Approve LOP</a>
                                                    <a href="?reject_lop_permission=<?php echo $permission['id']; ?>&leave_filter=<?php echo $leave_filter; ?>&permission_filter=<?php echo $permission_filter; ?>&leave_year=<?php echo $leave_year_filter; ?>&leave_month=<?php echo $leave_month_filter; ?>&permission_month=<?php echo $permission_month_filter; ?>&permission_year=<?php echo urlencode($permission_year_filter); ?>&permission_type_filter=<?php echo $permission_type_filter; ?>&leave_type_filter=<?php echo $leave_type_filter; ?>&view_type=<?php echo $view_type; ?>&year=<?php echo urlencode($year_filter); ?>" 
                                                       class="btn-small btn-lop-reject" onclick="return confirm('Reject this LOP permission? This will send notification to the employee.')"><i class="icon-cancel"></i> Reject LOP</a>
                                                <?php endif; ?>
                                            <?php endif; ?>
                                            <a href="../permissions/permission_details.php?id=<?php echo $permission['id']; ?>" class="btn-small btn-view"><i class="icon-view"></i> View</a>
                                            
                                            <?php if (!$is_previous_year && in_array($role, ['admin', 'pm'])): ?>
                                                <a href="?delete_permission=<?php echo $permission['id']; ?>&leave_filter=<?php echo $leave_filter; ?>&permission_filter=<?php echo $permission_filter; ?>&leave_year=<?php echo $leave_year_filter; ?>&leave_month=<?php echo $leave_month_filter; ?>&permission_month=<?php echo $permission_month_filter; ?>&permission_year=<?php echo urlencode($permission_year_filter); ?>&permission_type_filter=<?php echo $permission_type_filter; ?>&leave_type_filter=<?php echo $leave_type_filter; ?>&view_type=<?php echo $view_type; ?>&year=<?php echo urlencode($year_filter); ?>" 
                                                   class="btn-small btn-delete-leave" onclick="return confirm('Delete this permission entry? This will send notification to the employee.')">
                                                    <i class="icon-delete"></i> Delete
                                                </a>
                                            <?php elseif ($is_previous_year): ?>
                                                <span class="btn-small btn-delete-leave action-disabled" style="opacity:0.5;" title="Cannot delete archived permissions"><i class="icon-delete"></i> Delete</span>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr><td colspan="8" style="text-align: center; padding: 40px; color: #718096;"><i class="icon-clock" style="font-size: 48px; margin-bottom: 15px; display: block; color: #cbd5e0;"></i>No permission requests found for <?php echo $year_filter; ?></td></tr>
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
        var leavesAllRadio = document.getElementById('leaves_all');
        var leavesDateRangeRadio = document.getElementById('leaves_date_range');
        var leavesFromDate = document.getElementById('export_from_date');
        var leavesToDate = document.getElementById('export_to_date');
        
        if (leavesAllRadio && leavesDateRangeRadio && leavesFromDate && leavesToDate) {
            leavesAllRadio.addEventListener('change', function() {
                leavesFromDate.disabled = true;
                leavesToDate.disabled = true;
            });
            
            leavesDateRangeRadio.addEventListener('change', function() {
                leavesFromDate.disabled = false;
                leavesToDate.disabled = false;
            });
            
            // Initial state
            leavesFromDate.disabled = !leavesDateRangeRadio.checked;
            leavesToDate.disabled = !leavesDateRangeRadio.checked;
        }
        
        var permissionsAllRadio = document.getElementById('permissions_all');
        var permissionsDateRangeRadio = document.getElementById('permissions_date_range');
        var permissionsFromDate = document.getElementById('export_permission_from_date');
        var permissionsToDate = document.getElementById('export_permission_to_date');
        
        if (permissionsAllRadio && permissionsDateRangeRadio && permissionsFromDate && permissionsToDate) {
            permissionsAllRadio.addEventListener('change', function() {
                permissionsFromDate.disabled = true;
                permissionsToDate.disabled = true;
            });
            
            permissionsDateRangeRadio.addEventListener('change', function() {
                permissionsFromDate.disabled = false;
                permissionsToDate.disabled = false;
            });
            
            // Initial state
            permissionsFromDate.disabled = !permissionsDateRangeRadio.checked;
            permissionsToDate.disabled = !permissionsDateRangeRadio.checked;
        }
    });
    </script>
    
    <script src="../assets/js/app.js"></script>
</body>
</html>