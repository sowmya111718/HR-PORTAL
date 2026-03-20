<?php
require_once '../config/db.php';
require_once '../includes/leave_functions.php';
require_once '../includes/icon_functions.php';
require_once '../includes/notification_functions.php';
checkRole(['hr', 'admin', 'dm', 'coo', 'ed']);

$user_id = $_SESSION['user_id'];
$role    = $_SESSION['role'];

// Leave year info
$leave_year      = getCurrentLeaveYear();
$prev_leave_year = getPreviousLeaveYear();

// Month ranges (16th–15th)
function getHWMonthRanges() {
    $current_month = date('n');
    $current_day   = date('j');
    $current_leave_month = ($current_day >= 16) ? $current_month : (($current_month == 1) ? 12 : $current_month - 1);
    $months = [];
    for ($i = 0; $i < 12; $i++) {
        $mn = $current_leave_month - $i;
        if ($mn <= 0) $mn += 12;
        $start_year = ($mn == 12 && $current_month < 12) ? date('Y') - 1 : date('Y');
        if ($mn == 12 && date('n') < 12) $start_year = date('Y') - 1;
        $end_year   = $start_year;
        $end_month  = $mn + 1;
        if ($end_month > 12) { $end_month = 1; $end_year++; }
        $start_date = sprintf('%04d-%02d-16', $start_year, $mn);
        $end_date   = sprintf('%04d-%02d-15', $end_year, $end_month);
        $months[$mn] = [
            'label'      => date('F', mktime(0,0,0,$mn,1)) . ' ' . $start_year,
            'start_date' => $start_date,
            'end_date'   => $end_date,
        ];
    }
    return $months;
}
$leave_months = getHWMonthRanges();

// Filters
$leave_month_filter = isset($_GET['leave_month']) ? $_GET['leave_month'] : 'all';
$leave_year_filter  = isset($_GET['leave_year'])  ? $_GET['leave_year']  : 'all';
$status_filter      = isset($_GET['status'])      ? $_GET['status']      : 'all';

// Build year date range
$year_start = null; $year_end = null;
if ($leave_year_filter != 'all') {
    if ($leave_year_filter == $leave_year['year_label']) {
        $year_start = $leave_year['start_date']; $year_end = $leave_year['end_date'];
    } elseif ($leave_year_filter == $prev_leave_year['year_label']) {
        $year_start = $prev_leave_year['start_date']; $year_end = $prev_leave_year['end_date'];
    }
}

// Stats for current leave year
$hw_casual_start = $leave_year['start_date'];
$hw_casual_end   = $leave_year['end_date'];
$hw_year_label   = $leave_year['year_label'];

$hw_stats_res = $conn->query("
    SELECT
        SUM(CASE WHEN reason LIKE '%SUNDAY WORK BONUS%' OR reason LIKE '%FESTIVAL WORK BONUS%' THEN 1 ELSE 0 END) as manual_bonus_count,
        SUM(CASE WHEN reason LIKE 'Auto-granted: Festival/Holiday%' THEN 1 ELSE 0 END) as auto_holiday_count,
        SUM(CASE WHEN (reason LIKE '%SUNDAY WORK BONUS%' OR reason LIKE '%FESTIVAL WORK BONUS%') AND status='Approved' THEN days ELSE 0 END) as manual_bonus_days,
        SUM(CASE WHEN reason LIKE 'Auto-granted: Festival/Holiday%' AND status='Approved' THEN days ELSE 0 END) as auto_holiday_days,
        COUNT(*) as total_count
    FROM leaves
    WHERE status != 'Cancelled'
      AND (reason LIKE '%SUNDAY WORK BONUS%' OR reason LIKE '%FESTIVAL WORK BONUS%' OR reason LIKE 'Auto-granted: Festival/Holiday%')
      AND from_date BETWEEN '$hw_casual_start' AND '$hw_casual_end'
");
$hw_stats = $hw_stats_res ? $hw_stats_res->fetch_assoc() : ['manual_bonus_count'=>0,'auto_holiday_count'=>0,'manual_bonus_days'=>0,'auto_holiday_days'=>0,'total_count'=>0];

// Monthly breakdown
$hw_monthly_res = $conn->query("
    SELECT
        MONTH(from_date) as mon,
        MONTHNAME(from_date) as month_name,
        SUM(CASE WHEN reason LIKE '%SUNDAY WORK BONUS%' OR reason LIKE '%FESTIVAL WORK BONUS%' THEN 1 ELSE 0 END) as manual_count,
        SUM(CASE WHEN reason LIKE 'Auto-granted: Festival/Holiday%' THEN 1 ELSE 0 END) as auto_count,
        SUM(CASE WHEN status='Approved' THEN days ELSE 0 END) as total_days,
        SUM(CASE WHEN status='Approved' THEN 1 ELSE 0 END) as approved_count
    FROM leaves
    WHERE status != 'Cancelled'
      AND (reason LIKE '%SUNDAY WORK BONUS%' OR reason LIKE '%FESTIVAL WORK BONUS%' OR reason LIKE 'Auto-granted: Festival/Holiday%')
      AND from_date BETWEEN '$hw_casual_start' AND '$hw_casual_end'
    GROUP BY MONTH(from_date), MONTHNAME(from_date)
    ORDER BY MONTH(from_date)
");
$hw_monthly_rows = $hw_monthly_res ? $hw_monthly_res->fetch_all(MYSQLI_ASSOC) : [];

// Main query with filters
$hw_where = "l.status != 'Cancelled' AND (
    l.reason LIKE '%SUNDAY WORK BONUS%'
    OR l.reason LIKE '%FESTIVAL WORK BONUS%'
    OR l.reason LIKE 'Auto-granted: Festival/Holiday%'
)";
if ($year_start && $year_end) {
    $hw_where .= " AND l.from_date >= '$year_start' AND l.from_date <= '$year_end'";
}
if ($leave_month_filter != 'all') {
    $md = $leave_months[$leave_month_filter] ?? null;
    if ($md) $hw_where .= " AND l.from_date >= '{$md['start_date']}' AND l.from_date <= '{$md['end_date']}'";
}
if ($status_filter != 'all') {
    if ($status_filter == 'approved_bonus') {
        // Approved +1 CL = manually approved (Sunday/Festival work bonus only, not auto-granted)
        $hw_where .= " AND l.status = 'Approved' AND (l.reason LIKE '%SUNDAY WORK BONUS%' OR l.reason LIKE '%FESTIVAL WORK BONUS%')";
    } else {
        $hw_where .= " AND l.status = '" . $conn->real_escape_string($status_filter) . "'";
    }
}

$hw_leaves = $conn->query("
    SELECT l.*, u.full_name, u.username,
           a.full_name as approved_by_name,
           DATE(l.approved_date) as approved_date_formatted,
           CASE
               WHEN l.reason LIKE '%FESTIVAL WORK BONUS%' THEN 'Festival'
               WHEN l.reason LIKE 'Auto-granted: Festival/Holiday%' THEN 'Festival'
               ELSE 'Sunday'
           END as work_type
    FROM leaves l
    JOIN users u ON l.user_id = u.id
    LEFT JOIN users a ON l.approved_by = a.id
    WHERE $hw_where
    ORDER BY l.from_date DESC
");

$page_title = 'Holiday Work +1 CL';
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
        th, td { padding:12px; text-align:left; border-bottom:1px solid #e2e8f0; }
        th { background:#f7fafc; font-weight:600; color:#4a5568; }
        tr:hover { background:#f7fafc; }
        .status-badge { padding:3px 10px; border-radius:12px; font-size:12px; font-weight:600; display:inline-block; }
        .status-approved { background:#c6f6d5; color:#276749; }
        .status-pending  { background:#fef3c7; color:#92400e; }
        .status-rejected { background:#fed7d7; color:#c53030; }
        .filter-bar { display:flex; gap:15px; flex-wrap:wrap; align-items:flex-end; background:#f7fafc; padding:14px 18px; border-radius:10px; margin-bottom:20px; }
        .filter-bar label { font-size:12px; font-weight:600; color:#4a5568; display:block; margin-bottom:3px; }
        .filter-bar select { font-size:13px; padding:6px 10px; border:1px solid #e2e8f0; border-radius:6px; min-width:160px; }
        .apply-btn { background:#4299e1; color:#fff; border:none; padding:8px 18px; border-radius:6px; font-weight:600; cursor:pointer; }
        .apply-btn:hover { background:#3182ce; }
        .stat-cards { display:grid; grid-template-columns:1fr 1fr; gap:20px; margin-bottom:25px; }
        @media(max-width:600px){ .stat-cards { grid-template-columns:1fr; } }
    </style>
</head>
<body>
    <?php include '../includes/header.php'; ?>
    <div class="app-main">
        <?php include '../includes/sidebar.php'; ?>
        <div class="main-content">

            <div class="page-title" style="display:flex; align-items:center; justify-content:space-between; margin-bottom:20px;">
                <h2 style="margin:0;">🎉 Holiday Work +1 CL</h2>
                <span style="background:#9b59b6; color:#fff; padding:4px 14px; border-radius:20px; font-size:13px; font-weight:600;"><?php echo $hw_year_label; ?></span>
            </div>

            <!-- ── Two Stat Cards ── -->
            <div class="stat-cards">
                <!-- Card 1: Holiday Work +1 CL (manual) -->
                <div style="background:linear-gradient(135deg,#6b21a8 0%,#9b59b6 100%); border-radius:14px; padding:22px 24px; color:#fff; box-shadow:0 4px 15px rgba(107,33,168,0.3);">
                    <div style="display:flex; justify-content:space-between; align-items:flex-start; margin-bottom:14px;">
                        <div>
                            <div style="font-size:13px; opacity:0.85; margin-bottom:4px;">Holiday Work +1 CL</div>
                            <div style="font-size:44px; font-weight:700; line-height:1;"><?php echo $hw_stats['manual_bonus_count']; ?></div>
                            <div style="font-size:12px; opacity:0.8; margin-top:6px;"><?php echo $hw_stats['manual_bonus_days']; ?> days granted &nbsp;|&nbsp; <?php echo $hw_year_label; ?></div>
                        </div>
                        <div style="background:rgba(255,255,255,0.2); border-radius:50%; width:54px; height:54px; display:flex; align-items:center; justify-content:center; font-size:26px;">🎉</div>
                    </div>
                    <div style="border-top:1px solid rgba(255,255,255,0.25); padding-top:10px; font-size:12px; opacity:0.85;">
                        Sunday &amp; Festival timesheets approved by DM/ED
                    </div>
                </div>

                <!-- Card 2: Auto-Generated Holiday Leave -->
                <div style="background:linear-gradient(135deg,#1a5276 0%,#2980b9 100%); border-radius:14px; padding:22px 24px; color:#fff; box-shadow:0 4px 15px rgba(26,82,118,0.3);">
                    <div style="display:flex; justify-content:space-between; align-items:flex-start; margin-bottom:14px;">
                        <div>
                            <div style="font-size:13px; opacity:0.85; margin-bottom:4px;">Auto-Generated Holiday Leave</div>
                            <div style="font-size:44px; font-weight:700; line-height:1;"><?php echo $hw_stats['auto_holiday_count']; ?></div>
                            <div style="font-size:12px; opacity:0.8; margin-top:6px;"><?php echo $hw_stats['auto_holiday_days']; ?> days granted &nbsp;|&nbsp; <?php echo $hw_year_label; ?></div>
                        </div>
                        <div style="background:rgba(255,255,255,0.2); border-radius:50%; width:54px; height:54px; display:flex; align-items:center; justify-content:center; font-size:26px;">🏖️</div>
                    </div>
                    <div style="border-top:1px solid rgba(255,255,255,0.25); padding-top:10px; font-size:12px; opacity:0.85;">
                        Auto-granted to employees on festival/holiday days
                    </div>
                </div>
            </div>

            <!-- ── Monthly Breakdown ── -->
            <?php if (!empty($hw_monthly_rows)): ?>
            <div style="background:#fff; border-radius:12px; padding:20px 24px; box-shadow:0 2px 10px rgba(0,0,0,0.06); margin-bottom:25px;">
                <h3 style="color:#6b21a8; margin-bottom:16px; font-size:16px;">📊 Monthly Breakdown — <?php echo $hw_year_label; ?></h3>
                <div style="overflow-x:auto;">
                    <table>
                        <thead>
                            <tr style="background:#f3e8ff; color:#6b21a8;">
                                <th>Month</th>
                                <th style="text-align:center;">🎉 Holiday Work<br><span style="font-size:10px; font-weight:400;">(Manual Approved)</span></th>
                                <th style="text-align:center;">🏖️ Auto Holiday<br><span style="font-size:10px; font-weight:400;">(System Granted)</span></th>
                                <th style="text-align:center;">Total Approved</th>
                                <th style="text-align:center;">Days Granted</th>
                                <th style="text-align:center;">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($hw_monthly_rows as $hwr): ?>
                            <tr style="border-bottom:1px solid #f0e6ff;">
                                <td style="font-weight:600; color:#2d3748;"><?php echo $hwr['month_name']; ?></td>
                                <td style="text-align:center;">
                                    <?php if ($hwr['manual_count'] > 0): ?>
                                        <span style="background:#9b59b6; color:#fff; padding:2px 10px; border-radius:12px; font-size:12px; font-weight:600;"><?php echo $hwr['manual_count']; ?></span>
                                    <?php else: ?><span style="color:#cbd5e0;">—</span><?php endif; ?>
                                </td>
                                <td style="text-align:center;">
                                    <?php if ($hwr['auto_count'] > 0): ?>
                                        <span style="background:#2980b9; color:#fff; padding:2px 10px; border-radius:12px; font-size:12px; font-weight:600;"><?php echo $hwr['auto_count']; ?></span>
                                    <?php else: ?><span style="color:#cbd5e0;">—</span><?php endif; ?>
                                </td>
                                <td style="text-align:center; font-weight:600; color:#276749;"><?php echo $hwr['approved_count']; ?></td>
                                <td style="text-align:center;">
                                    <span style="background:#c6f6d5; color:#276749; padding:2px 10px; border-radius:12px; font-size:12px; font-weight:600;"><?php echo $hwr['total_days']; ?> days</span>
                                </td>
                                <td style="text-align:center;">
                                    <a href="?leave_month=<?php echo $hwr['mon']; ?>&leave_year=<?php echo urlencode($leave_year['year_label']); ?>"
                                       style="background:#f3e8ff; color:#6b21a8; padding:4px 12px; border-radius:8px; font-size:11px; text-decoration:none; font-weight:600;">
                                        View →
                                    </a>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                            <tr style="background:#f9f0ff; font-weight:700;">
                                <td style="color:#6b21a8;">Total</td>
                                <td style="text-align:center; color:#6b21a8;"><?php echo array_sum(array_column($hw_monthly_rows,'manual_count')); ?></td>
                                <td style="text-align:center; color:#2980b9;"><?php echo array_sum(array_column($hw_monthly_rows,'auto_count')); ?></td>
                                <td style="text-align:center; color:#276749;"><?php echo array_sum(array_column($hw_monthly_rows,'approved_count')); ?></td>
                                <td style="text-align:center; color:#276749;"><?php echo array_sum(array_column($hw_monthly_rows,'total_days')); ?> days</td>
                                <td></td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
            <?php endif; ?>

            <!-- ── Detail Table with Filters ── -->
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">🎉 All Holiday Work Bonus Leaves
                        <span style="background:#9b59b6; color:#fff; padding:2px 10px; border-radius:12px; font-size:13px; font-weight:600; margin-left:8px;">
                            <?php echo $hw_leaves ? $hw_leaves->num_rows : 0; ?>
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
                                    <option value="all" <?php echo $leave_month_filter=='all'?'selected':''; ?>>All Months</option>
                                    <?php foreach ($leave_months as $mn => $md): ?>
                                    <option value="<?php echo $mn; ?>" <?php echo $leave_month_filter==$mn?'selected':''; ?>><?php echo $md['label']; ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div>
                                <label>Year:</label>
                                <select name="leave_year">
                                    <option value="all" <?php echo $leave_year_filter=='all'?'selected':''; ?>>All Years</option>
                                    <option value="<?php echo $leave_year['year_label']; ?>" <?php echo $leave_year_filter==$leave_year['year_label']?'selected':''; ?>>Current (<?php echo $leave_year['year_label']; ?>)</option>
                                    <option value="<?php echo $prev_leave_year['year_label']; ?>" <?php echo $leave_year_filter==$prev_leave_year['year_label']?'selected':''; ?>>Previous (<?php echo $prev_leave_year['year_label']; ?>)</option>
                                </select>
                            </div>
                            <div>
                                <label>Status:</label>
                                <select name="status">
                                    <option value="all"           <?php echo $status_filter=='all'?'selected':''; ?>>All Status</option>
                                    <option value="approved_bonus"<?php echo $status_filter=='approved_bonus'?'selected':''; ?>>✅ Approved +1 CL</option>
                                    <option value="Approved"      <?php echo $status_filter=='Approved'?'selected':''; ?>>Approved</option>
                                    <option value="Pending"       <?php echo $status_filter=='Pending'?'selected':''; ?>>Pending</option>
                                    <option value="Rejected"      <?php echo $status_filter=='Rejected'?'selected':''; ?>>Rejected</option>
                                </select>
                            </div>
                            <button type="submit" class="apply-btn">Apply Filters</button>
                        </div>
                    </form>
                </div>

                <!-- Table -->
                <div class="table-container" style="padding:0 20px 20px;">
                    <table>
                        <thead>
                            <tr style="background:#6b21a8; color:#fff;">
                                <th>Employee</th>
                                <th>Work Type</th>
                                <th>Work Date</th>
                                <th>+1 CL Granted On</th>
                                <th>Leave Year</th>
                                <th>Approved By</th>
                                <th>Reason</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($hw_leaves && $hw_leaves->num_rows > 0): ?>
                                <?php while ($hw = $hw_leaves->fetch_assoc()):
                                    $hw_yr   = $hw['leave_year'] ?? getLeaveYearForDate($hw['from_date'])['year_label'];
                                    $is_fest = ($hw['work_type'] === 'Festival');
                                ?>
                                <tr style="background:<?php echo $is_fest ? '#fdf4ff' : '#fffbf0'; ?>;">
                                    <td><strong><?php echo htmlspecialchars($hw['full_name']); ?></strong><br>
                                        <span style="font-size:11px; color:#718096;"><?php echo htmlspecialchars($hw['username']); ?></span>
                                    </td>
                                    <td>
                                        <?php if ($is_fest): ?>
                                            <span style="background:#9b59b6; color:#fff; padding:3px 10px; border-radius:12px; font-size:12px; font-weight:600;">🎉 Festival</span>
                                        <?php else: ?>
                                            <span style="background:#ed8936; color:#fff; padding:3px 10px; border-radius:12px; font-size:12px; font-weight:600;">☀️ Sunday</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><strong><?php echo htmlspecialchars($hw['from_date']); ?></strong><br>
                                        <span style="font-size:11px; color:#718096;"><?php echo date('l', strtotime($hw['from_date'])); ?></span>
                                    </td>
                                    <td><?php echo $hw['approved_date_formatted'] ?? '-'; ?></td>
                                    <td><span style="background:#e2e8f0; padding:3px 8px; border-radius:12px; font-size:11px;"><?php echo $hw_yr; ?></span></td>
                                    <td><?php echo htmlspecialchars($hw['approved_by_name'] ?? '-'); ?></td>
                                    <td style="font-size:11px; color:#4a5568; max-width:200px;" title="<?php echo htmlspecialchars($hw['reason']); ?>">
                                        <?php echo strlen($hw['reason']) > 45 ? substr(htmlspecialchars($hw['reason']), 0, 45) . '...' : htmlspecialchars($hw['reason']); ?>
                                    </td>
                                    <td>
                                        <?php
                                        $is_manual_bonus = (strpos($hw['reason'], 'SUNDAY WORK BONUS') !== false || strpos($hw['reason'], 'FESTIVAL WORK BONUS') !== false);
                                        if ($hw['status'] === 'Approved' && $is_manual_bonus):
                                        ?>
                                            <span class="status-badge" style="background:#d6bcfa; color:#553c9a;">✅ Approved +1 CL</span>
                                        <?php else: ?>
                                            <span class="status-badge status-<?php echo strtolower($hw['status']); ?>">
                                                <?php echo htmlspecialchars($hw['status']); ?>
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr><td colspan="8" style="text-align:center; padding:40px; color:#718096;">
                                    <div style="font-size:40px; margin-bottom:10px;">🎉</div>
                                    No holiday work bonus leaves found for the selected period.
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