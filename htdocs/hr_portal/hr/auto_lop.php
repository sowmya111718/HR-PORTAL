<?php
require_once '../config/db.php';
require_once '../includes/leave_functions.php';
require_once '../includes/icon_functions.php';
require_once '../includes/notification_functions.php';
checkRole(['hr', 'admin', 'dm', 'coo', 'ed']);

$user_id = $_SESSION['user_id'];
$role    = strtolower($_SESSION['role']);
$message = '';

// ── Month ranges (16th–15th) ─────────────────────────────────────────────────
function getALOPMonthRanges() {
    $cm = date('n'); $cd = date('j');
    $clm = ($cd >= 16) ? $cm : ($cm == 1 ? 12 : $cm - 1);
    $months = [];
    for ($i = 0; $i < 12; $i++) {
        $mn = $clm - $i;
        if ($mn <= 0) $mn += 12;
        $sy = date('Y'); $ey = $sy;
        if ($mn == 12 && date('n') < 12) $sy--;
        $em = $mn + 1; if ($em > 12) { $em = 1; $ey++; }
        $start_label = date('M', mktime(0,0,0,$mn,1)) . ' 16';
        $end_label   = date('M', mktime(0,0,0,$em,1)) . ' 15';
        $months[$mn] = [
            'label'      => $start_label . ' – ' . $end_label,
            'start_date' => sprintf('%04d-%02d-16', $sy, $mn),
            'end_date'   => sprintf('%04d-%02d-15', $ey, $em),
        ];
    }
    return $months;
}
$leave_months    = getALOPMonthRanges();
$leave_year      = getCurrentCasualLeaveYear();
$prev_leave_year = getPreviousCasualLeaveYear();

// ── Approve LOP ───────────────────────────────────────────────────────────────
if (isset($_GET['approve_lop']) && in_array($role, ['dm', 'ed'])) {
    $lid = intval($_GET['approve_lop']);
    $gl = $conn->prepare("SELECT l.*, u.full_name FROM leaves l JOIN users u ON l.user_id = u.id WHERE l.id = ? AND l.leave_type='LOP' AND l.reason LIKE 'Auto-generated LOP%'");
    $gl->bind_param("i", $lid); $gl->execute();
    $ld = $gl->get_result()->fetch_assoc(); $gl->close();
    if ($ld) {
        $conn->begin_transaction();
        try {
            $st = $conn->prepare("UPDATE leaves SET status='Approved', approved_by=?, approved_date=NOW() WHERE id=? AND status='Pending'");
            $st->bind_param("ii", $user_id, $lid);
            if ($st->execute()) {
                createNotification($conn, $ld['user_id'], 'leave_approved', 'LOP Approved', "Your auto-generated LOP for {$ld['from_date']} has been approved.", $lid);
                $conn->commit();
                $message = '<div class="alert alert-success"><i class="icon-success"></i> LOP approved successfully. Notification sent.</div>';
            } else throw new Exception($st->error);
            $st->close();
        } catch (Exception $e) { $conn->rollback(); $message = '<div class="alert alert-error"><i class="icon-error"></i> Error: ' . $e->getMessage() . '</div>'; }
    }
}

// ── Reject LOP ────────────────────────────────────────────────────────────────
if (isset($_GET['reject_lop']) && in_array($role, ['dm', 'ed'])) {
    $lid = intval($_GET['reject_lop']);
    $gl = $conn->prepare("SELECT l.*, u.full_name FROM leaves l JOIN users u ON l.user_id = u.id WHERE l.id = ? AND l.leave_type='LOP' AND l.reason LIKE 'Auto-generated LOP%'");
    $gl->bind_param("i", $lid); $gl->execute();
    $ld = $gl->get_result()->fetch_assoc(); $gl->close();
    if ($ld) {
        $st = $conn->prepare("UPDATE leaves SET status='Rejected', rejected_by=?, rejected_date=NOW() WHERE id=? AND status='Pending'");
        $st->bind_param("ii", $user_id, $lid);
        if ($st->execute()) {
            createNotification($conn, $ld['user_id'], 'leave_rejected', 'LOP Rejected', "Your auto-generated LOP for {$ld['from_date']} has been rejected.", $lid);
            $message = '<div class="alert alert-success"><i class="icon-success"></i> LOP rejected. Notification sent.</div>';
        } else $message = '<div class="alert alert-error"><i class="icon-error"></i> Error rejecting LOP.</div>';
        $st->close();
    }
}

// ── Delete LOP ────────────────────────────────────────────────────────────────
if (isset($_GET['delete_lop']) && in_array($role, ['dm', 'ed'])) {
    $lid = intval($_GET['delete_lop']);
    $gl = $conn->prepare("SELECT l.*, u.full_name FROM leaves l JOIN users u ON l.user_id = u.id WHERE l.id = ? AND l.leave_type='LOP' AND l.reason LIKE 'Auto-generated LOP%'");
    $gl->bind_param("i", $lid); $gl->execute();
    $ld = $gl->get_result()->fetch_assoc(); $gl->close();
    if ($ld) {
        $conn->begin_transaction();
        try {
            createNotification($conn, $ld['user_id'], 'leave_deleted', 'LOP Deleted', "Your auto-generated LOP for {$ld['from_date']} has been deleted by management.", $lid);
            $del = $conn->prepare("DELETE FROM leaves WHERE id=?");
            $del->bind_param("i", $lid); $del->execute(); $del->close();
            $conn->commit();
            $message = '<div class="alert alert-warning"><i class="icon-warning"></i> LOP deleted. Notification sent.</div>';
        } catch (Exception $e) { $conn->rollback(); $message = '<div class="alert alert-error"><i class="icon-error"></i> Error: ' . $e->getMessage() . '</div>'; }
    }
}

// ── Filters ───────────────────────────────────────────────────────────────────
$month_filter  = isset($_GET['leave_month']) ? $_GET['leave_month']  : 'all';
$year_filter   = isset($_GET['leave_year'])  ? $_GET['leave_year']   : 'all';
$status_filter = isset($_GET['status'])      ? $_GET['status']       : 'all';
$emp_filter    = isset($_GET['employee'])    ? intval($_GET['employee']) : 0;
$date_from     = isset($_GET['date_from'])   && $_GET['date_from'] !== '' ? $_GET['date_from'] : '';
$date_to       = isset($_GET['date_to'])     && $_GET['date_to'] !== ''   ? $_GET['date_to']   : '';

// Build WHERE
$where = "l.leave_type = 'LOP' AND l.reason LIKE 'Auto-generated LOP%'";
if ($status_filter != 'all') $where .= " AND l.status = '" . $conn->real_escape_string($status_filter) . "'";
if ($emp_filter > 0) $where .= " AND l.user_id = $emp_filter";

// Year filter
$year_start = null; $year_end = null;
if ($year_filter != 'all') {
    if ($year_filter == $leave_year['year_label']) { $year_start = $leave_year['start_date']; $year_end = $leave_year['end_date']; }
    elseif ($year_filter == $prev_leave_year['year_label']) { $year_start = $prev_leave_year['start_date']; $year_end = $prev_leave_year['end_date']; }
}
if ($year_start) $where .= " AND l.from_date >= '$year_start' AND l.from_date <= '$year_end'";

// Month filter
if ($month_filter != 'all') {
    $md = $leave_months[$month_filter] ?? null;
    if ($md) $where .= " AND l.from_date >= '{$md['start_date']}' AND l.from_date <= '{$md['end_date']}'";
}

// Date range filter (overrides month/year if set)
if ($date_from !== '') $where .= " AND l.from_date >= '" . $conn->real_escape_string($date_from) . "'";
if ($date_to   !== '') $where .= " AND l.from_date <= '" . $conn->real_escape_string($date_to)   . "'";

// ── Excel Export ──────────────────────────────────────────────────────────────
if (isset($_GET['export_excel'])) {

    // Build date range clause for the LOP sub-query (same as $where but without employee filter)
    $lop_date_clause = "l.leave_type = 'LOP' AND l.reason LIKE 'Auto-generated LOP%' AND l.status != 'Cancelled'";
    if ($year_start && $year_end) $lop_date_clause .= " AND l.from_date >= '$year_start' AND l.from_date <= '$year_end'";
    if ($month_filter != 'all' && isset($leave_months[$month_filter])) {
        $lop_date_clause .= " AND l.from_date >= '{$leave_months[$month_filter]['start_date']}' AND l.from_date <= '{$leave_months[$month_filter]['end_date']}'";
    }
    if ($date_from !== '') $lop_date_clause .= " AND l.from_date >= '" . $conn->real_escape_string($date_from) . "'";
    if ($date_to   !== '') $lop_date_clause .= " AND l.from_date <= '" . $conn->real_escape_string($date_to)   . "'";
    if ($status_filter != 'all') $lop_date_clause .= " AND l.status = '" . $conn->real_escape_string($status_filter) . "'";

    // Employee filter for detail rows
    $emp_clause = $emp_filter > 0 ? " AND l.user_id = $emp_filter" : '';

    // All employees summary (LEFT JOIN so zero-LOP employees still appear)
    $all_users_where = $emp_filter > 0 ? "WHERE u.id = $emp_filter" : "WHERE u.status != 'inactive'";
    $summary_res = $conn->query("
        SELECT u.id, u.full_name, u.username, u.department, u.position,
               COALESCE(SUM(CASE WHEN ($lop_date_clause) THEN l.days ELSE 0 END), 0) as lop_days,
               COALESCE(COUNT(CASE WHEN ($lop_date_clause) THEN 1 END), 0) as lop_count
        FROM users u
        LEFT JOIN leaves l ON u.id = l.user_id
        $all_users_where
        GROUP BY u.id, u.full_name, u.username, u.department, u.position
        ORDER BY u.full_name ASC
    ");
    $all_users = [];
    if ($summary_res) { while ($r = $summary_res->fetch_assoc()) $all_users[] = $r; }

    // Detailed LOP entries (filtered)
    $detail_res = $conn->query("
        SELECT u.full_name, u.username, u.department, u.position,
               l.from_date, l.to_date, l.days, l.status, l.reason,
               l.applied_date, l.leave_year,
               a.full_name as approved_by_name, DATE(l.approved_date) as approved_date_fmt,
               r.full_name as rejected_by_name, DATE(l.rejected_date) as rejected_date_fmt
        FROM leaves l
        JOIN users u ON l.user_id = u.id
        LEFT JOIN users a ON l.approved_by = a.id
        LEFT JOIN users r ON l.rejected_by = r.id
        WHERE $lop_date_clause $emp_clause
        ORDER BY u.full_name ASC, l.from_date ASC
    ");
    $detail_rows = [];
    if ($detail_res) { while ($r = $detail_res->fetch_assoc()) $detail_rows[] = $r; }

    // Smart filename
    $name_part = $emp_filter > 0 && !empty($all_users)
        ? '_' . preg_replace('/[^A-Za-z0-9_]/', '', str_replace(' ', '_', $all_users[0]['full_name']))
        : '_All';
    $from_part = $date_from ? '_' . $date_from : ($year_start ? '_' . $year_start : '');
    $to_part   = $date_to   ? '_to_' . $date_to : ($year_end ? '_to_' . $year_end : '');
    $filename  = 'Auto_LOP' . $name_part . $from_part . $to_part . '_' . date('Ymd') . '.xls';

    header('Content-Type: application/vnd.ms-excel');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: max-age=0');

    $total_lop_days     = array_sum(array_column($all_users, 'lop_days'));
    $employees_with_lop = count(array_filter($all_users, fn($e) => $e['lop_days'] > 0));
    $pending_count      = count(array_filter($detail_rows, fn($r) => $r['status'] === 'Pending'));
    $approved_count     = count(array_filter($detail_rows, fn($r) => $r['status'] === 'Approved'));

    // Date range label
    $range_label = 'All Time';
    if ($date_from || $date_to) $range_label = ($date_from ?: 'Start') . ' to ' . ($date_to ?: 'Today');
    elseif ($year_start && $year_end) $range_label = $year_start . ' to ' . $year_end;
    elseif ($month_filter != 'all' && isset($leave_months[$month_filter])) $range_label = $leave_months[$month_filter]['label'];

    echo '<html xmlns:o="urn:schemas-microsoft-com:office:office" xmlns:x="urn:schemas-microsoft-com:office:excel" xmlns="http://www.w3.org/TR/REC-html40">';
    echo '<head><meta charset="UTF-8"><style>
        td { mso-number-format:\\@; vertical-align:top; padding:5px; }
        .date { mso-number-format:\'yyyy-mm-dd\'; }
        .stats-box { background:#fff5f5; border:2px solid #c53030; border-radius:10px; padding:15px; margin-bottom:20px; }
        .stats-title { font-size:16px; font-weight:bold; color:#c53030; margin-bottom:10px; }
        .stats-grid { display:flex; flex-wrap:wrap; gap:15px; }
        .stat-item { flex:1; min-width:150px; background:white; padding:10px; border-radius:8px; box-shadow:0 2px 4px rgba(0,0,0,0.1); }
        .stat-label { font-size:12px; color:#718096; }
        .stat-value { font-size:24px; font-weight:bold; }
        .employee-summary-box { background:#f9f9f9; border:2px solid #48bb78; border-radius:10px; padding:15px; margin-bottom:20px; }
        .employee-summary-title { font-size:16px; font-weight:bold; color:#276749; margin-bottom:10px; }
        .summary-table { width:100%; border-collapse:collapse; margin-top:10px; }
        .summary-table th { background:#276749; color:white; padding:8px; text-align:left; }
        .summary-table td { padding:8px; border-bottom:1px solid #cbd5e0; }
        .summary-table tr:hover { background:#f0fff4; }
        .lop-row { background-color:#fee2e2; }
        .lop-text { color:#dc2626; font-weight:bold; }
        .zero-leave { color:#94a3b8; font-style:italic; }
        .approved { color:#276749; font-weight:bold; }
        .rejected { color:#c53030; }
        .pending  { color:#92400e; }
    </style></head><body>';

    echo "<h2 style='color:#006400;'>🏢 MAKSIM HR - Leaves Export</h2>";
    echo "<p><strong>Export Type:</strong> Auto-Generated LOP Only</p>";
    echo "<p><strong>Date Range:</strong> {$range_label}</p>";
    echo "<p><strong>Cancelled Leaves:</strong> Excluded from report</p>";

    // Stats box
    echo "<div class='stats-box'><div class='stats-title'>📊 Leave Statistics for Selected Period</div><div class='stats-grid'>";
    echo "<div class='stat-item'><div class='stat-label'>Total LOP Days</div><div class='stat-value' style='color:#dc2626;'>{$total_lop_days}</div><div style='font-size:11px;color:#6b7280;'>Employees: {$employees_with_lop}</div></div>";
    echo "<div class='stat-item'><div class='stat-label'>Auto LOP Days</div><div class='stat-value' style='color:#b91c1c;'>{$total_lop_days}</div></div>";
    echo "<div class='stat-item'><div class='stat-label'>Manual LOP Days</div><div class='stat-value' style='color:#f97316;'>0</div></div>";
    echo "<div class='stat-item'><div class='stat-label'>Sick Leave Days</div><div class='stat-value' style='color:#16a34a;'>0</div><div style='font-size:11px;color:#6b7280;'>Employees: 0</div></div>";
    echo "<div class='stat-item'><div class='stat-label'>Casual Leave Days</div><div class='stat-value' style='color:#2563eb;'>0</div><div style='font-size:11px;color:#6b7280;'>Employees: 0</div></div>";
    echo "</div></div>";

    // Employee-wise summary — ALL employees, zero rows shown gray italic
    echo "<div class='employee-summary-box'><div class='employee-summary-title'>👥 Employee-wise Leave Summary (All Employees)</div>";
    echo "<table class='summary-table' border='1' cellpadding='5' style='border-collapse:collapse;width:100%;'>";
    echo "<tr><th>S.No</th><th>EMP ID</th><th>EMP NAME</th><th>Department</th><th>LEAVES LOP (Days)</th><th>Auto LOP</th><th>Manual LOP</th><th>CASUAL LEAVES TAKEN</th><th>SICK LEAVES TAKEN</th><th>Total Leaves</th></tr>";
    $sno = 1;
    foreach ($all_users as $e) {
        $has = $e['lop_days'] > 0;
        $total = $e['lop_days'];
        echo '<tr' . ($has ? " class='lop-row'" : '') . '>';
        echo "<td>{$sno}</td>";
        echo "<td>" . htmlspecialchars($e['username']) . "</td>";
        echo "<td><strong>" . htmlspecialchars($e['full_name']) . "</strong></td>";
        echo "<td>" . htmlspecialchars($e['department'] ?? 'N/A') . "</td>";
        if ($has) {
            echo "<td class='lop-text'><strong>{$e['lop_days']}</strong> ({$e['lop_count']} apps)</td>";
            echo "<td style='color:#b91c1c;'>{$e['lop_days']}</td>";
            echo "<td style='color:#f97316;'>0.0</td>";
        } else {
            echo "<td class='zero-leave'>0</td><td class='zero-leave'>0</td><td class='zero-leave'>0</td>";
        }
        echo "<td class='zero-leave'>0</td>"; // casual
        echo "<td class='zero-leave'>0</td>"; // sick
        echo "<td><strong>{$total}</strong></td>";
        echo "</tr>";
        $sno++;
    }
    echo "</table></div>";

    // Detailed entries
    echo "<h3 style='margin-top:20px;'>⚠️ Detailed Leave Entries</h3>";
    echo "<table border='1' cellpadding='5' style='border-collapse:collapse;width:100%;'>";
    echo "<tr style='background:#006400;color:white;'>";
    foreach (['Employee Name','Username','Department','Position','Leave Type','From Date','To Date','Days','Reason','Status','Applied Date','Approved By','Approved Date','Rejected By','Rejected Date','Leave Year','LOP Type'] as $h)
        echo "<th>{$h}</th>";
    echo "</tr>";

    if (empty($detail_rows)) {
        echo "<tr><td colspan='17' style='text-align:center;padding:20px;'>No leave entries found for the selected period</td></tr>";
    } else {
        foreach ($detail_rows as $row) {
            echo "<tr class='lop-row'>";
            echo "<td>" . htmlspecialchars($row['full_name']) . "</td>";
            echo "<td>" . htmlspecialchars($row['username']) . "</td>";
            echo "<td>" . htmlspecialchars($row['department'] ?? '-') . "</td>";
            echo "<td>" . htmlspecialchars($row['position'] ?? '-') . "</td>";
            echo "<td>LOP</td>";
            echo "<td class='date'>" . $row['from_date'] . "</td>";
            echo "<td class='date'>" . $row['to_date'] . "</td>";
            echo "<td class='lop-text'>" . $row['days'] . "</td>";
            echo "<td>" . htmlspecialchars($row['reason']) . "</td>";
            echo "<td class='" . strtolower($row['status']) . "'>" . $row['status'] . "</td>";
            echo "<td class='date'>" . date('Y-m-d', strtotime($row['applied_date'])) . "</td>";
            echo "<td>" . htmlspecialchars($row['approved_by_name'] ?? '-') . "</td>";
            echo "<td class='date'>" . ($row['approved_date_fmt'] ?? '-') . "</td>";
            echo "<td>" . htmlspecialchars($row['rejected_by_name'] ?? '-') . "</td>";
            echo "<td class='date'>" . ($row['rejected_date_fmt'] ?? '-') . "</td>";
            echo "<td>" . htmlspecialchars($row['leave_year'] ?? '-') . "</td>";
            echo "<td class='lop-text'>Auto-generated LOP</td>";
            echo "</tr>";
        }
    }
    echo "</table></body></html>";
    exit();
}

// ── Stats ─────────────────────────────────────────────────────────────────────
$stats_res = $conn->query("
    SELECT
        COUNT(*) as total,
        SUM(CASE WHEN status='Pending'  THEN 1 ELSE 0 END) as pending,
        SUM(CASE WHEN status='Approved' THEN 1 ELSE 0 END) as approved,
        SUM(CASE WHEN status='Rejected' THEN 1 ELSE 0 END) as rejected,
        SUM(days) as total_days,
        COUNT(DISTINCT user_id) as affected_employees
    FROM leaves
    WHERE leave_type='LOP' AND reason LIKE 'Auto-generated LOP%'
      AND from_date BETWEEN '{$leave_year['start_date']}' AND '{$leave_year['end_date']}'
");
$stats = $stats_res ? $stats_res->fetch_assoc() : ['total'=>0,'pending'=>0,'approved'=>0,'rejected'=>0,'total_days'=>0,'affected_employees'=>0];

// ── Monthly breakdown ─────────────────────────────────────────────────────────
$monthly_res = $conn->query("
    SELECT MONTH(from_date) as mon, MONTHNAME(from_date) as month_name,
           COUNT(*) as count, SUM(days) as days,
           SUM(CASE WHEN status='Pending' THEN 1 ELSE 0 END) as pending
    FROM leaves
    WHERE leave_type='LOP' AND reason LIKE 'Auto-generated LOP%'
      AND from_date BETWEEN '{$leave_year['start_date']}' AND '{$leave_year['end_date']}'
    GROUP BY MONTH(from_date), MONTHNAME(from_date)
    ORDER BY MONTH(from_date)
");
$monthly_rows = $monthly_res ? $monthly_res->fetch_all(MYSQLI_ASSOC) : [];

// ── Main query ────────────────────────────────────────────────────────────────
$lops = $conn->query("
    SELECT l.*, u.full_name, u.username, u.department,
           a.full_name as approved_by_name,
           r.full_name as rejected_by_name,
           DATE(l.approved_date) as approved_date_fmt,
           DATE(l.rejected_date) as rejected_date_fmt
    FROM leaves l
    JOIN users u ON l.user_id = u.id
    LEFT JOIN users a ON l.approved_by = a.id
    LEFT JOIN users r ON l.rejected_by = r.id
    WHERE $where
    ORDER BY l.from_date DESC
");

// ── Employee list for filter ──────────────────────────────────────────────────
$emp_list = $conn->query("
    SELECT DISTINCT u.id, u.full_name FROM users u
    JOIN leaves l ON u.id = l.user_id
    WHERE l.leave_type='LOP' AND l.reason LIKE 'Auto-generated LOP%'
    ORDER BY u.full_name
");

$page_title = 'Auto-Generated LOPs';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $page_title; ?> - MAKSIM HR</title>
    <?php include '../includes/head.php'; ?>
    <style>
        table { width:100%; border-collapse:collapse; }
        th, td { padding:11px 12px; text-align:left; border-bottom:1px solid #e2e8f0; font-size:13px; }
        th { background:#f7fafc; font-weight:600; color:#4a5568; }
        tr:hover { background:#f7fafc; }
        .status-badge { padding:3px 10px; border-radius:12px; font-size:11px; font-weight:600; display:inline-block; }
        .status-pending  { background:#fef3c7; color:#92400e; }
        .status-approved { background:#c6f6d5; color:#276749; }
        .status-rejected { background:#fed7d7; color:#c53030; }
        .filter-bar { display:flex; gap:12px; flex-wrap:wrap; align-items:flex-end; background:#f7fafc; padding:14px 18px; border-radius:10px; margin-bottom:20px; }
        .filter-bar label { font-size:12px; font-weight:600; color:#4a5568; display:block; margin-bottom:3px; }
        .filter-bar select { font-size:13px; padding:6px 10px; border:1px solid #e2e8f0; border-radius:6px; min-width:150px; }
        .apply-btn { background:#c53030; color:#fff; border:none; padding:8px 18px; border-radius:6px; font-weight:600; cursor:pointer; }
        .apply-btn:hover { background:#9b2c2c; }
        .stat-cards { display:grid; grid-template-columns:repeat(auto-fit,minmax(160px,1fr)); gap:16px; margin-bottom:24px; }
        .stat-card { background:#fff; border-radius:12px; padding:18px 20px; box-shadow:0 2px 8px rgba(0,0,0,0.06); }
        .stat-num  { font-size:36px; font-weight:700; line-height:1; margin-bottom:4px; }
        .stat-lbl  { font-size:12px; color:#718096; }
        .btn-sm { padding:4px 10px; border-radius:5px; font-size:11px; font-weight:600; text-decoration:none; display:inline-block; border:none; cursor:pointer; }
        .btn-approve { background:#48bb78; color:#fff; }
        .btn-reject  { background:#f56565; color:#fff; }
        .btn-delete  { background:#c53030; color:#fff; }
        .btn-approve:hover { background:#38a169; }
        .btn-reject:hover  { background:#e53e3e; }
        .btn-delete:hover  { background:#9b2c2c; }
        .lop-row { background:#fff5f5; border-left:3px solid #c53030; }
        .lop-pending { background:#fff8f0; border-left:3px solid #ed8936; }
    </style>
</head>
<body>
    <?php include '../includes/header.php'; ?>
    <div class="app-main">
        <?php include '../includes/sidebar.php'; ?>
        <div class="main-content">

            <div style="display:flex; align-items:center; justify-content:space-between; margin-bottom:20px;">
                <h2 style="margin:0;">⚠️ Auto-Generated LOPs</h2>
                <span style="background:#c53030; color:#fff; padding:4px 14px; border-radius:20px; font-size:13px; font-weight:600;"><?php echo $leave_year['year_label']; ?></span>
            </div>

            <?php echo $message; ?>

            <!-- ── Stat Cards ── -->
            <div class="stat-cards">
                <div class="stat-card" style="border-top:4px solid #c53030;">
                    <div class="stat-num" style="color:#c53030;"><?php echo $stats['total']; ?></div>
                    <div class="stat-lbl">Total Auto-LOPs</div>
                </div>
                <div class="stat-card" style="border-top:4px solid #ed8936;">
                    <div class="stat-num" style="color:#ed8936;"><?php echo $stats['pending']; ?></div>
                    <div class="stat-lbl">⏳ Pending</div>
                </div>
                <div class="stat-card" style="border-top:4px solid #48bb78;">
                    <div class="stat-num" style="color:#48bb78;"><?php echo $stats['approved']; ?></div>
                    <div class="stat-lbl">✅ Approved</div>
                </div>
                <div class="stat-card" style="border-top:4px solid #718096;">
                    <div class="stat-num" style="color:#718096;"><?php echo $stats['rejected']; ?></div>
                    <div class="stat-lbl">❌ Rejected</div>
                </div>
                <div class="stat-card" style="border-top:4px solid #9b59b6;">
                    <div class="stat-num" style="color:#9b59b6;"><?php echo $stats['total_days']; ?></div>
                    <div class="stat-lbl">Total Days</div>
                </div>
                <div class="stat-card" style="border-top:4px solid #4299e1;">
                    <div class="stat-num" style="color:#4299e1;"><?php echo $stats['affected_employees']; ?></div>
                    <div class="stat-lbl">Employees Affected</div>
                </div>
            </div>

            <!-- ── Monthly Breakdown ── -->
            <?php if (!empty($monthly_rows)): ?>
            <div style="background:#fff; border-radius:12px; padding:20px 24px; box-shadow:0 2px 10px rgba(0,0,0,0.06); margin-bottom:24px;">
                <h3 style="color:#c53030; margin-bottom:14px; font-size:16px;">📊 Monthly Breakdown — <?php echo $leave_year['year_label']; ?></h3>
                <div style="overflow-x:auto;">
                    <table>
                        <thead>
                            <tr style="background:#fff5f5; color:#c53030;">
                                <th>Month</th>
                                <th style="text-align:center;">LOP Entries</th>
                                <th style="text-align:center;">Days</th>
                                <th style="text-align:center;">Pending</th>
                                <th style="text-align:center;">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($monthly_rows as $mr): ?>
                            <tr>
                                <td style="font-weight:600;"><?php echo $mr['month_name']; ?></td>
                                <td style="text-align:center;"><span style="background:#fed7d7; color:#c53030; padding:2px 10px; border-radius:12px; font-size:12px; font-weight:600;"><?php echo $mr['count']; ?></span></td>
                                <td style="text-align:center; font-weight:600; color:#c53030;"><?php echo $mr['days']; ?></td>
                                <td style="text-align:center;">
                                    <?php if ($mr['pending'] > 0): ?>
                                    <span style="background:#fef3c7; color:#92400e; padding:2px 10px; border-radius:12px; font-size:12px; font-weight:600;"><?php echo $mr['pending']; ?> pending</span>
                                    <?php else: ?><span style="color:#48bb78; font-size:12px;">✅ All done</span><?php endif; ?>
                                </td>
                                <td style="text-align:center;">
                                    <a href="?leave_month=<?php echo $mr['mon']; ?>&leave_year=<?php echo urlencode($leave_year['year_label']); ?>"
                                       style="background:#fff5f5; color:#c53030; padding:4px 12px; border-radius:8px; font-size:11px; text-decoration:none; font-weight:600; border:1px solid #fed7d7;">
                                        View →
                                    </a>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                            <tr style="background:#fff5f5; font-weight:700;">
                                <td style="color:#c53030;">Total</td>
                                <td style="text-align:center; color:#c53030;"><?php echo array_sum(array_column($monthly_rows,'count')); ?></td>
                                <td style="text-align:center; color:#c53030;"><?php echo array_sum(array_column($monthly_rows,'days')); ?></td>
                                <td style="text-align:center; color:#92400e;"><?php echo array_sum(array_column($monthly_rows,'pending')); ?> pending</td>
                                <td></td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
            <?php endif; ?>

            <!-- ── Detail Table ── -->
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">⚠️ All Auto-Generated LOPs
                        <span style="background:#c53030; color:#fff; padding:2px 10px; border-radius:12px; font-size:13px; font-weight:600; margin-left:8px;">
                            <?php echo $lops ? $lops->num_rows : 0; ?>
                        </span>
                    </h3>
                </div>

                <!-- Filters -->
                <div style="padding:16px 20px 0;">
                    <form method="GET" action="">
                        <div class="filter-bar">
                            <div>
                                <label>Month:</label>
                                <select name="leave_month">
                                    <option value="all" <?php echo $month_filter=='all'?'selected':''; ?>>All Months</option>
                                    <?php foreach ($leave_months as $mn => $md): ?>
                                    <option value="<?php echo $mn; ?>" <?php echo $month_filter==$mn?'selected':''; ?>><?php echo $md['label']; ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div>
                                <label>Year:</label>
                                <select name="leave_year">
                                    <option value="all" <?php echo $year_filter=='all'?'selected':''; ?>>All Years</option>
                                    <option value="<?php echo $leave_year['year_label']; ?>" <?php echo $year_filter==$leave_year['year_label']?'selected':''; ?>>Current (<?php echo $leave_year['year_label']; ?>)</option>
                                    <option value="<?php echo $prev_leave_year['year_label']; ?>" <?php echo $year_filter==$prev_leave_year['year_label']?'selected':''; ?>>Previous (<?php echo $prev_leave_year['year_label']; ?>)</option>
                                </select>
                            </div>
                            <div>
                                <label>From Date:</label>
                                <input type="date" name="date_from" value="<?php echo htmlspecialchars($date_from); ?>" style="font-size:13px; padding:6px 10px; border:1px solid #e2e8f0; border-radius:6px;">
                            </div>
                            <div>
                                <label>To Date:</label>
                                <input type="date" name="date_to" value="<?php echo htmlspecialchars($date_to); ?>" style="font-size:13px; padding:6px 10px; border:1px solid #e2e8f0; border-radius:6px;">
                            </div>
                            <div>
                                <label>Status:</label>
                                <select name="status">
                                    <option value="all"      <?php echo $status_filter=='all'?'selected':''; ?>>All Status</option>
                                    <option value="Pending"  <?php echo $status_filter=='Pending'?'selected':''; ?>>⏳ Pending</option>
                                    <option value="Approved" <?php echo $status_filter=='Approved'?'selected':''; ?>>✅ Approved</option>
                                    <option value="Rejected" <?php echo $status_filter=='Rejected'?'selected':''; ?>>❌ Rejected</option>
                                </select>
                            </div>
                            <div>
                                <label>Employee:</label>
                                <select name="employee">
                                    <option value="0" <?php echo $emp_filter==0?'selected':''; ?>>All Employees</option>
                                    <?php if ($emp_list): while ($e = $emp_list->fetch_assoc()): ?>
                                    <option value="<?php echo $e['id']; ?>" <?php echo $emp_filter==$e['id']?'selected':''; ?>><?php echo htmlspecialchars($e['full_name']); ?></option>
                                    <?php endwhile; endif; ?>
                                </select>
                            </div>
                            <button type="submit" class="apply-btn">Apply Filters</button>
                        </div>
                    </form>
                    <!-- Export Button -->
                    <div style="margin-bottom:14px;">
                        <a href="?export_excel=1&leave_month=<?php echo urlencode($month_filter); ?>&leave_year=<?php echo urlencode($year_filter); ?>&date_from=<?php echo urlencode($date_from); ?>&date_to=<?php echo urlencode($date_to); ?>&status=<?php echo urlencode($status_filter); ?>&employee=<?php echo $emp_filter; ?>"
                           style="background:#1a7431; color:#fff; padding:8px 20px; border-radius:6px; font-weight:600; font-size:13px; text-decoration:none; display:inline-flex; align-items:center; gap:6px;">
                            📊 Export to Excel
                        </a>
                        <span style="color:#718096; font-size:12px; margin-left:10px;">Exports current filtered results (<?php echo $lops ? $lops->num_rows : 0; ?> records)</span>
                    </div>
                </div>

                <!-- Table -->
                <div style="padding:0 20px 20px; overflow-x:auto;">
                    <table>
                        <thead>
                            <tr style="background:#fff5f5; color:#c53030;">
                                <th>Employee</th>
                                <th>Department</th>
                                <th>LOP Date</th>
                                <th>Days</th>
                                <th>Reason</th>
                                <th>Status</th>
                                <th>Applied</th>
                                <th>Approved/Rejected By</th>
                                <?php if (in_array($role, ['dm', 'ed'])): ?>
                                <th>Actions</th>
                                <?php endif; ?>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($lops && $lops->num_rows > 0): ?>
                                <?php while ($lop = $lops->fetch_assoc()):
                                    $row_class = ($lop['status'] === 'Pending') ? 'lop-pending' : 'lop-row';
                                ?>
                                <tr class="<?php echo $row_class; ?>">
                                    <td>
                                        <strong><?php echo htmlspecialchars($lop['full_name']); ?></strong><br>
                                        <span style="font-size:11px; color:#718096;"><?php echo htmlspecialchars($lop['username']); ?></span>
                                    </td>
                                    <td style="font-size:12px; color:#4a5568;"><?php echo htmlspecialchars($lop['department'] ?? '-'); ?></td>
                                    <td>
                                        <strong><?php echo $lop['from_date']; ?></strong><br>
                                        <span style="font-size:11px; color:#718096;"><?php echo date('l', strtotime($lop['from_date'])); ?></span>
                                    </td>
                                    <td style="font-weight:700; color:#c53030;"><?php echo $lop['days']; ?></td>
                                    <td style="font-size:11px; color:#4a5568; max-width:180px;" title="<?php echo htmlspecialchars($lop['reason']); ?>">
                                        <?php echo strlen($lop['reason']) > 40 ? substr(htmlspecialchars($lop['reason']), 0, 40) . '...' : htmlspecialchars($lop['reason']); ?>
                                    </td>
                                    <td>
                                        <span class="status-badge status-<?php echo strtolower($lop['status']); ?>">
                                            <?php echo $lop['status']; ?>
                                        </span>
                                    </td>
                                    <td style="font-size:12px;"><?php echo date('d M Y', strtotime($lop['applied_date'])); ?></td>
                                    <td style="font-size:12px; color:#4a5568;">
                                        <?php if ($lop['approved_by_name']): ?>
                                            <span style="color:#276749;">✅ <?php echo htmlspecialchars($lop['approved_by_name']); ?></span><br>
                                            <span style="font-size:10px; color:#718096;"><?php echo $lop['approved_date_fmt'] ?? ''; ?></span>
                                        <?php elseif ($lop['rejected_by_name']): ?>
                                            <span style="color:#c53030;">❌ <?php echo htmlspecialchars($lop['rejected_by_name']); ?></span><br>
                                            <span style="font-size:10px; color:#718096;"><?php echo $lop['rejected_date_fmt'] ?? ''; ?></span>
                                        <?php else: ?>—<?php endif; ?>
                                    </td>
                                    <?php if (in_array($role, ['dm', 'ed'])): ?>
                                    <td>
                                        <div style="display:flex; gap:5px; flex-wrap:wrap;">
                                            <?php if ($lop['status'] === 'Pending'): ?>
                                            <a href="?approve_lop=<?php echo $lop['id']; ?>&leave_month=<?php echo $month_filter; ?>&leave_year=<?php echo urlencode($year_filter); ?>&status=<?php echo $status_filter; ?>&employee=<?php echo $emp_filter; ?>"
                                               class="btn-sm btn-approve"
                                               onclick="return confirm('Approve this auto-generated LOP?')">✅ Approve</a>
                                            <a href="?reject_lop=<?php echo $lop['id']; ?>&leave_month=<?php echo $month_filter; ?>&leave_year=<?php echo urlencode($year_filter); ?>&status=<?php echo $status_filter; ?>&employee=<?php echo $emp_filter; ?>"
                                               class="btn-sm btn-reject"
                                               onclick="return confirm('Reject this auto-generated LOP?')">❌ Reject</a>
                                            <?php endif; ?>
                                            <a href="?delete_lop=<?php echo $lop['id']; ?>&leave_month=<?php echo $month_filter; ?>&leave_year=<?php echo urlencode($year_filter); ?>&status=<?php echo $status_filter; ?>&employee=<?php echo $emp_filter; ?>"
                                               class="btn-sm btn-delete"
                                               onclick="return confirm('Delete this LOP permanently? This cannot be undone.')">🗑️ Delete</a>
                                        </div>
                                    </td>
                                    <?php endif; ?>
                                </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr><td colspan="9" style="text-align:center; padding:40px; color:#718096;">
                                    <div style="font-size:40px; margin-bottom:10px;">✅</div>
                                    No auto-generated LOPs found for the selected filters.
                                </td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

        </div>
    </div>
    <script src="../assets/js/app.js"></script>
</body>
</html>