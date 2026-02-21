<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once 'config/db.php';
require_once 'includes/leave_functions.php';

if (!isLoggedIn()) {
    header('Location: auth/login.php');
    exit();
}

$user_id   = $_SESSION['user_id'];
$role      = $_SESSION['role'];
$full_name = $_SESSION['full_name'];

// ── Sick leave: calendar year ──────────────────────────────
$leave_year    = getCurrentLeaveYear();
$current_month = getCurrentLeaveMonth();

// ── Casual leave: Mar 16 – Mar 15 cycle ───────────────────
$casual_year    = getCurrentCasualLeaveYear();
$current_window = getCurrentCasualWindow();   // 16th–15th window

// ── Full balance (includes accrual, carry-forward, countdowns) ──
$balance = getUserLeaveBalance($conn, $user_id);

// ── Casual balance shorthand ───────────────────────────────
$casual_balance          = $balance['casual_balance'];
$casual_available        = $casual_balance['remaining'];           // accrued − used (carry-forward included)
$casual_total_entitled   = $casual_balance['total_entitled'];      // total days for full cycle (join-date prorated)
$casual_accrued          = $casual_balance['accrued_to_date'];     // days unlocked so far
$casual_used_cycle       = $casual_balance['used_cycle'];          // total used in current cycle
$casual_used_this_window = $casual_balance['used_this_window'];    // used in current 16th-15th window

// ── Countdown values ──────────────────────────────────────
$days_until_monthly_reset = $balance['days_until_monthly_reset'];  // days until next 16th
$days_until_yearly_reset  = $balance['days_until_yearly_reset'];   // days until next Mar 16

// ── Sick year countdown (Dec 31 reset) ────────────────────
$today           = new DateTime();
$sick_reset_date = new DateTime($leave_year['end_date']);
$sick_reset_date->modify('+1 day');
$days_until_sick_reset = $today->diff($sick_reset_date)->days;

// ── Next Mar 16 reset date label ──────────────────────────
$year_now  = (int)$today->format('Y');
$month_now = (int)$today->format('n');
$day_now   = (int)$today->format('j');
if ($month_now < 3 || ($month_now == 3 && $day_now <= 16)) {
    $next_mar16 = new DateTime("{$year_now}-03-16");
} else {
    $next_mar16 = new DateTime(($year_now + 1) . "-03-16");
}
$next_mar16_label = date('d M Y', $next_mar16->getTimestamp());

// ── LOP ───────────────────────────────────────────────────
$lop_total          = getLOPCount($conn, $user_id);
$lop_this_month     = getCurrentMonthLOPUsage($conn, $user_id);
$lop_previous_month = function_exists('getPreviousMonthLOPUsage') ? getPreviousMonthLOPUsage($conn, $user_id) : 0;

// ── Pending count ─────────────────────────────────────────
$pending = 0;
$stmt = $conn->prepare("
    SELECT
        (SELECT COUNT(*) FROM leaves      WHERE user_id = ? AND status = 'Pending') AS pending_leaves,
        (SELECT COUNT(*) FROM permissions WHERE user_id = ? AND status = 'Pending') AS pending_permissions
");
if ($stmt) {
    $stmt->bind_param("ii", $user_id, $user_id);
    $stmt->execute();
    $row     = $stmt->get_result()->fetch_assoc();
    $pending = ($row['pending_leaves'] ?? 0) + ($row['pending_permissions'] ?? 0);
    $stmt->close();
}

// ── Recent leaves ─────────────────────────────────────────
$recent_leaves = [];
$recent_stmt   = $conn->prepare("SELECT * FROM leaves WHERE user_id = ? ORDER BY applied_date DESC LIMIT 10");
if ($recent_stmt) {
    $recent_stmt->bind_param("i", $user_id);
    $recent_stmt->execute();
    $recent_leaves = $recent_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $recent_stmt->close();
}

// ── Progress bar helpers ───────────────────────────────────
$casual_progress = $casual_total_entitled > 0 ? round(($casual_used_cycle / $casual_total_entitled) * 100) : 0;
$sick_progress   = ($balance['sick_entitlement'] ?? 6) > 0
    ? round((($balance['used']['Sick'] ?? 0) / ($balance['sick_entitlement'] ?? 6)) * 100) : 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>MAKSIM HR - Dashboard</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; font-family: 'Segoe UI', sans-serif; }
        body { background: linear-gradient(135deg, #2c9218 0%, #006400 100%); min-height: 100vh; padding: 20px; }

        /* ── Header ── */
        .app-header {
            background: linear-gradient(135deg, #2c9218 0%, #006400 100%);
            color: white; padding: 20px 30px;
            display: flex; justify-content: space-between; align-items: center;
            border-radius: 15px 15px 0 0;
        }
        .user-info   { display: flex; align-items: center; gap: 15px; }
        .user-label  { background: rgba(255,255,255,0.2); padding: 8px 15px; border-radius: 20px; display: flex; align-items: center; gap: 8px; }
        .logout-btn  { background: rgba(255,255,255,0.2); color: white; padding: 8px 15px; border-radius: 20px; text-decoration: none; }

        /* ── Layout ── */
        .app-main    { display: flex; min-height: 800px; background: white; border-radius: 0 0 15px 15px; }
        .sidebar     { width: 250px; background: #f7fafc; border-right: 1px solid #e2e8f0; padding: 20px 0; flex-shrink: 0; }
        .sidebar-nav { list-style: none; }
        .sidebar-nav a {
            display: flex; align-items: center; gap: 12px; padding: 15px 25px;
            color: #4a5568; text-decoration: none; border-left: 4px solid transparent;
        }
        .sidebar-nav a:hover, .sidebar-nav a.active { background: #edf2f7; color: #006400; border-left-color: #667eea; }
        .main-content { flex: 1; padding: 30px; background: #f8fafc; overflow-x: hidden; }
        .page-title   { color: #006400; margin-bottom: 20px; padding-bottom: 15px; border-bottom: 2px solid #e2e8f0; }

        /* ── Cycle info banner ── */
        .cycle-banner {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white; padding: 18px 24px; border-radius: 12px; margin-bottom: 16px;
            display: flex; flex-wrap: wrap; gap: 20px; align-items: center; justify-content: space-between;
        }
        .cycle-banner .section { display: flex; flex-direction: column; gap: 4px; }
        .cycle-banner .label   { font-size: 11px; opacity: .75; text-transform: uppercase; letter-spacing: .5px; }
        .cycle-banner .value   { font-size: 16px; font-weight: 700; }
        .cycle-banner .divider { width: 1px; height: 40px; background: rgba(255,255,255,.3); }

        /* countdown pills */
        .countdown-row { display: flex; gap: 12px; flex-wrap: wrap; margin-bottom: 20px; }
        .countdown-pill {
            display: flex; align-items: center; gap: 10px;
            background: white; border-radius: 10px; padding: 12px 18px;
            box-shadow: 0 2px 8px rgba(0,0,0,.08); flex: 1; min-width: 200px;
        }
        .countdown-pill .pill-icon { width: 40px; height: 40px; border-radius: 10px; display: flex; align-items: center; justify-content: center; font-size: 18px; }
        .countdown-pill.monthly .pill-icon  { background: #ebf8ff; color: #3182ce; }
        .countdown-pill.yearly  .pill-icon  { background: #faf5ff; color: #805ad5; }
        .countdown-pill .pill-days  { font-size: 26px; font-weight: 700; color: #2d3748; }
        .countdown-pill .pill-label { font-size: 12px; color: #718096; }
        .countdown-pill .pill-sub   { font-size: 11px; color: #a0aec0; }

        /* ── Stat cards ── */
        .stats-grid { display: grid; grid-template-columns: repeat(4,1fr); gap: 20px; margin-bottom: 25px; }
        @media(max-width:1100px) { .stats-grid { grid-template-columns: repeat(2,1fr); } }
        .stat-card {
            background: white; border-radius: 15px; padding: 20px;
            box-shadow: 0 2px 10px rgba(0,0,0,.05); transition: transform .2s;
        }
        .stat-card:hover { transform: translateY(-4px); box-shadow: 0 6px 20px rgba(0,0,0,.1); }
        .stat-card.lop-card { background: linear-gradient(135deg, #f56565 0%, #c53030 100%); color: white; }
        .stat-icon {
            width: 50px; height: 50px; border-radius: 12px;
            display: flex; align-items: center; justify-content: center;
            margin-bottom: 15px; font-size: 22px;
        }
        .stat-card:not(.lop-card) .stat-icon.sick    { background: #fed7d7; color: #c53030; }
        .stat-card:not(.lop-card) .stat-icon.casual  { background: #c6f6d5; color: #276749; }
        .stat-card:not(.lop-card) .stat-icon.pending { background: #e9d8fd; color: #553c9a; }
        .stat-card.lop-card        .stat-icon         { background: rgba(255,255,255,.2); color: white; }
        .stat-value      { font-size: 36px; font-weight: 700; margin-bottom: 5px; }
        .stat-card:not(.lop-card) .stat-value { color: #2d3748; }
        .stat-card.lop-card       .stat-value { color: white; }
        .stat-label      { font-size: 14px; margin-bottom: 8px; }
        .stat-card:not(.lop-card) .stat-label { color: #718096; }
        .stat-card.lop-card       .stat-label { color: rgba(255,255,255,.9); }
        .stat-sub { font-size: 12px; padding-top: 10px; border-top: 1px solid #e2e8f0; margin-top: 8px; color: #718096; }
        .stat-card.lop-card .stat-sub { border-top-color: rgba(255,255,255,.2); color: rgba(255,255,255,.8); }

        /* progress bar */
        .progress-bar  { width: 100%; height: 6px; background: #e2e8f0; border-radius: 4px; overflow: hidden; margin-top: 8px; }
        .progress-fill { height: 100%; border-radius: 4px; transition: width .4s ease; }
        .fill-sick     { background: linear-gradient(90deg,#fc8181,#c53030); }
        .fill-casual   { background: linear-gradient(90deg,#68d391,#276749); }

        /* ── Cards ── */
        .card { background: white; border-radius: 15px; padding: 25px; box-shadow: 0 2px 10px rgba(0,0,0,.05); margin-bottom: 25px; }
        .card-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; }
        .card-title  { font-size: 16px; font-weight: 600; color: #2d3748; }

        /* casual detail strip */
        .casual-detail {
            background: #f0fff4; border: 1px solid #9ae6b4; border-radius: 10px;
            padding: 14px 20px; margin-bottom: 20px;
            display: flex; flex-wrap: wrap; gap: 20px; align-items: center;
        }
        .casual-detail .item { display: flex; flex-direction: column; }
        .casual-detail .item .lbl { font-size: 11px; color: #718096; text-transform: uppercase; letter-spacing: .5px; }
        .casual-detail .item .val { font-size: 18px; font-weight: 700; color: #276749; }
        .casual-detail .window-tag {
            background: #276749; color: white; border-radius: 8px;
            padding: 6px 14px; font-size: 13px; font-weight: 600; margin-left: auto;
        }

        /* ── Table ── */
        .table-container { overflow-x: auto; border: 1px solid #e2e8f0; border-radius: 10px; }
        table { width: 100%; border-collapse: collapse; }
        th { background: #f7fafc; padding: 12px 14px; text-align: left; font-weight: 600; color: #4a5568; border-bottom: 2px solid #e2e8f0; font-size: 13px; }
        td { padding: 12px 14px; border-bottom: 1px solid #e2e8f0; color: #4a5568; font-size: 13px; }
        tr:last-child td { border-bottom: none; }
        .lop-row { background: #fff5f5; }
        .status-badge   { padding: 3px 10px; border-radius: 20px; font-size: 11px; font-weight: 600; display: inline-block; }
        .status-approved { background: #c6f6d5; color: #276749; }
        .status-pending  { background: #fefcbf; color: #744210; }
        .status-rejected { background: #fed7d7; color: #c53030; }
        .lop-badge { background: #c53030; color: white; padding: 2px 8px; border-radius: 12px; font-size: 11px; margin-left: 5px; }
        .type-lop    { color: #c53030; font-weight: 600; }
        .type-casual { color: #276749; font-weight: 600; }
        .type-sick   { color: #3182ce; font-weight: 600; }
    </style>
</head>
<body>

<div class="app-header">
    <div style="display:flex; align-items:center;">
        <img src="assets/images/maksim_infotech_logo.png" alt="MAKSIM Infotech" height="40" style="margin-right:10px;">
        <h1 style="margin:0; font-size:24px;">MAKSIM HR SYSTEM</h1>
    </div>
    <div class="user-info">
        <div class="user-label"><i class="fas fa-user"></i> <?php echo htmlspecialchars($full_name); ?></div>
        <a href="auth/logout.php" class="logout-btn"><i class="fas fa-sign-out-alt"></i> Logout</a>
    </div>
</div>

<div class="app-main">
    <div class="sidebar">
        <ul class="sidebar-nav">
            <li><a href="dashboard.php" class="active"><i class="fas fa-home"></i> Dashboard</a></li>
            <li><a href="leaves/leaves.php"><i class="fas fa-umbrella-beach"></i> Leave Management</a></li>
            <li><a href="permissions/permissions.php"><i class="fas fa-clock"></i> Permission Management</a></li>
            <li><a href="timesheet/timesheet.php"><i class="fas fa-calendar-alt"></i> Timesheet</a></li>
            <li><a href="attendance/attendance.php"><i class="fas fa-calendar-check"></i> Attendance</a></li>
            <?php if (in_array($role, ['hr', 'admin', 'pm'])): ?>
            <li><a href="hr/panel.php"><i class="fas fa-user-tie"></i> HR Panel</a></li>
            <li><a href="users/users.php"><i class="fas fa-user-cog"></i> User Management</a></li>
            <?php endif; ?>
            <li><a href="auth/change_password.php"><i class="fas fa-key"></i> Change Password</a></li>
        </ul>
    </div>

    <div class="main-content">
        <h2 class="page-title">Dashboard</h2>

        <!-- ── Cycle banner ─────────────────────────────── -->
        <div class="cycle-banner">
            <div class="section">
                <span class="label">Sick Leave Year</span>
                <span class="value"><i class="fas fa-calendar"></i> <?php echo $leave_year['year_label']; ?></span>
            </div>
            <div class="divider"></div>
            <div class="section">
                <span class="label">Casual Leave Cycle</span>
                <span class="value"><i class="fas fa-sync-alt"></i> <?php echo $casual_year['year_label']; ?></span>
            </div>
            <div class="divider"></div>
            <div class="section">
                <span class="label">Current Window</span>
                <span class="value">
                    <?php echo date('d M', strtotime($current_window['window_start'])); ?>
                    &rarr;
                    <?php echo date('d M', strtotime($current_window['window_end'])); ?>
                </span>
            </div>
            <div class="divider"></div>
            <div class="section">
                <span class="label">Join Date</span>
                <span class="value">
                    <?php echo $balance['join_date'] ? date('d M Y', strtotime($balance['join_date'])) : 'N/A'; ?>
                </span>
            </div>
        </div>

        <!-- ── Countdown pills ──────────────────────────── -->
        <div class="countdown-row">
            <div class="countdown-pill monthly">
                <div class="pill-icon"><i class="fas fa-calendar-day"></i></div>
                <div>
                    <div class="pill-days"><?php echo $days_until_monthly_reset; ?></div>
                    <div class="pill-label">Days until next monthly window</div>
                    <div class="pill-sub">
                        Next accrual on <?php echo date('d M Y', strtotime(date('Y-m') . '-16' . ($today->format('j') >= 16 ? ' +1 month' : ''))); ?>
                    </div>
                </div>
            </div>
            <div class="countdown-pill yearly">
                <div class="pill-icon"><i class="fas fa-hourglass-half"></i></div>
                <div>
                    <div class="pill-days"><?php echo $days_until_yearly_reset; ?></div>
                    <div class="pill-label">Days until casual cycle resets</div>
                    <div class="pill-sub">Resets <?php echo $next_mar16_label; ?> (Mar 16) — unused days forfeited</div>
                </div>
            </div>
            <div class="countdown-pill" style="border-left: 3px solid #f56565;">
                <div class="pill-icon" style="background:#fff5f5; color:#c53030;"><i class="fas fa-heartbeat"></i></div>
                <div>
                    <div class="pill-days"><?php echo $days_until_yearly_reset; ?></div>
                    <div class="pill-label">Days until sick leave year resets</div>
                    <div class="pill-sub">Resets <?php echo $next_mar16_label; ?> (Mar 16)</div>
                </div>
            </div>
        </div>

        <!-- ── Casual leave detail strip ────────────────── -->
        <div class="casual-detail">
            <div class="item">
                <span class="lbl">Entitled (cycle)</span>
                <span class="val"><?php echo $casual_total_entitled; ?> days</span>
            </div>
            <div class="item">
                <span class="lbl">Accrued to date</span>
                <span class="val"><?php echo $casual_accrued; ?> days</span>
            </div>
            <div class="item">
                <span class="lbl">Used (cycle)</span>
                <span class="val"><?php echo $casual_used_cycle; ?> days</span>
            </div>
            <div class="item">
                <span class="lbl">Used (this window)</span>
                <span class="val"><?php echo $casual_used_this_window; ?> days</span>
            </div>
            <div class="item">
                <span class="lbl">Available (carry-fwd)</span>
                <span class="val" style="color:#276749;"><?php echo $casual_available; ?> days</span>
            </div>
            <div class="window-tag">
                <i class="fas fa-calendar-week"></i>
                <?php echo date('d M', strtotime($current_window['window_start'])); ?>
                &rarr;
                <?php echo date('d M', strtotime($current_window['window_end'])); ?>
            </div>
        </div>

        <!-- ── Stat cards ────────────────────────────────── -->
        <div class="stats-grid">

            <!-- Sick leave -->
            <div class="stat-card">
                <div class="stat-icon sick"><i class="fas fa-heartbeat"></i></div>
                <div class="stat-value"><?php echo $balance['remaining']['Sick'] ?? 0; ?></div>
                <div class="stat-label">Sick Leave Remaining</div>
                <div class="stat-sub">
                    Entitled: <?php echo $balance['sick_entitlement'] ?? 6; ?> &nbsp;|&nbsp;
                    Used: <?php echo $balance['used']['Sick'] ?? 0; ?>
                    <div class="progress-bar"><div class="progress-fill fill-sick" style="width:<?php echo $sick_progress; ?>%"></div></div>
                </div>
            </div>

            <!-- Casual leave -->
            <div class="stat-card">
                <div class="stat-icon casual"><i class="fas fa-coffee"></i></div>
                <div class="stat-value"><?php echo $casual_available; ?></div>
                <div class="stat-label">Casual Leave Available</div>
                <div class="stat-sub">
                    Accrued: <?php echo $casual_accrued; ?> / <?php echo $casual_total_entitled; ?> &nbsp;|&nbsp;
                    Used: <?php echo $casual_used_cycle; ?>
                    <div class="progress-bar"><div class="progress-fill fill-casual" style="width:<?php echo $casual_progress; ?>%"></div></div>
                </div>
            </div>

            <!-- LOP -->
            <div class="stat-card lop-card">
                <div class="stat-icon"><i class="fas fa-exclamation-triangle"></i></div>
                <div class="stat-value"><?php echo $lop_this_month; ?></div>
                <div class="stat-label">LOP — This Month</div>
                <div class="stat-sub">
                    <div>Yearly total: <?php echo $lop_total; ?> days</div>
                    <div>Previous month: <?php echo $lop_previous_month; ?> days</div>
                </div>
            </div>

            <!-- Pending -->
            <div class="stat-card">
                <div class="stat-icon pending"><i class="fas fa-hourglass-half"></i></div>
                <div class="stat-value"><?php echo $pending; ?></div>
                <div class="stat-label">Pending Requests</div>
                <div class="stat-sub">Leaves + Permissions awaiting approval</div>
            </div>

        </div>

        <!-- ── Leave balance table ───────────────────────── -->
        <div class="card">
            <div class="card-header">
                <h3 class="card-title"><i class="fas fa-balance-scale"></i> Leave Balance Summary</h3>
            </div>
            <div class="table-container">
                <table>
                    <thead>
                        <tr>
                            <th>Type</th>
                            <th>Cycle / Year</th>
                            <th>Entitled</th>
                            <th>Accrued</th>
                            <th>Used</th>
                            <th>Available</th>
                            <th>Window Used</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td class="type-sick"><i class="fas fa-heartbeat"></i> Sick</td>
                            <td><?php echo $leave_year['year_label']; ?> (Jan–Dec)</td>
                            <td><?php echo $balance['sick_entitlement'] ?? 6; ?></td>
                            <td>—</td>
                            <td><?php echo $balance['used']['Sick'] ?? 0; ?></td>
                            <td><strong><?php echo $balance['remaining']['Sick'] ?? 0; ?></strong></td>
                            <td><?php echo getSickLeaveUsedThisMonth($conn, $user_id); ?> / month</td>
                        </tr>
                        <tr>
                            <td class="type-casual"><i class="fas fa-coffee"></i> Casual</td>
                            <td>
                                <?php echo $casual_year['year_label']; ?> (Mar 16–Mar 15)<br>
                                <small style="color:#a0aec0;">
                                    Window: <?php echo date('d M', strtotime($current_window['window_start'])); ?>
                                    – <?php echo date('d M', strtotime($current_window['window_end'])); ?>
                                </small>
                            </td>
                            <td><?php echo $casual_total_entitled; ?></td>
                            <td><?php echo $casual_accrued; ?></td>
                            <td><?php echo $casual_used_cycle; ?></td>
                            <td><strong style="color:#276749;"><?php echo $casual_available; ?></strong></td>
                            <td><?php echo $casual_used_this_window; ?></td>
                        </tr>
                        <tr class="lop-row">
                            <td class="type-lop"><i class="fas fa-exclamation-triangle"></i> LOP <span class="lop-badge">Unpaid</span></td>
                            <td><?php echo $leave_year['year_label']; ?></td>
                            <td>N/A</td>
                            <td>—</td>
                            <td style="color:#c53030;"><?php echo $lop_total; ?></td>
                            <td>N/A</td>
                            <td><?php echo $lop_this_month; ?></td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- ── Recent leaves ─────────────────────────────── -->
        <div class="card">
            <div class="card-header">
                <h3 class="card-title"><i class="fas fa-history"></i> Recent Leaves</h3>
                <a href="leaves/leaves.php" style="background:#4299e1; color:white; padding:6px 14px; border-radius:6px; text-decoration:none; font-size:13px;">View All</a>
            </div>
            <div class="table-container">
                <table>
                    <thead>
                        <tr><th>Type</th><th>From</th><th>To</th><th>Days</th><th>Reason</th><th>Status</th></tr>
                    </thead>
                    <tbody>
                        <?php if (!empty($recent_leaves)): ?>
                            <?php foreach ($recent_leaves as $leave):
                                $is_lop = ($leave['leave_type'] === 'LOP');
                                $type_class = $is_lop ? 'type-lop' : ($leave['leave_type'] === 'Casual' ? 'type-casual' : 'type-sick');
                            ?>
                            <tr class="<?php echo $is_lop ? 'lop-row' : ''; ?>">
                                <td class="<?php echo $type_class; ?>">
                                    <?php echo htmlspecialchars($leave['leave_type']); ?>
                                    <?php if ($is_lop): ?><span class="lop-badge">Unpaid</span><?php endif; ?>
                                </td>
                                <td><?php echo htmlspecialchars($leave['from_date']); ?></td>
                                <td><?php echo htmlspecialchars($leave['to_date']); ?></td>
                                <td><?php echo htmlspecialchars($leave['days']); ?></td>
                                <td style="max-width:160px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;" title="<?php echo htmlspecialchars($leave['reason'] ?? ''); ?>">
                                    <?php echo htmlspecialchars(mb_strimwidth($leave['reason'] ?? '—', 0, 30, '…')); ?>
                                </td>
                                <td>
                                    <span class="status-badge status-<?php echo strtolower(htmlspecialchars($leave['status'])); ?>">
                                        <?php echo htmlspecialchars($leave['status']); ?>
                                    </span>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr><td colspan="6" style="text-align:center; padding:24px; color:#a0aec0;">No recent leaves found</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

    </div><!-- /main-content -->
</div><!-- /app-main -->

</body>
</html>