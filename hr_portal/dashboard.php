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

// ── Sick leave: Mar 16 – Mar 15 cycle (same as casual) ─────
$sick_year    = getCurrentCasualLeaveYear();
$current_window = getCurrentCasualWindow();

// ── Casual leave: Mar 16 – Mar 15 cycle ───────────────────
$casual_year    = getCurrentCasualLeaveYear();
$current_window = getCurrentCasualWindow();

// ── Full balance (includes accrual, carry-forward, countdowns) ──
$balance = getUserLeaveBalance($conn, $user_id);

// ── Casual balance shorthand ───────────────────────────────
$casual_balance          = $balance['casual_balance'];
$casual_available        = $casual_balance['remaining'];
$casual_total_entitled   = $casual_balance['total_entitled'];
$casual_accrued          = $casual_balance['accrued_to_date'];
$casual_used_cycle       = $casual_balance['used_cycle'];
$casual_used_this_window = $casual_balance['used_this_window'];

// ── Countdown values ──────────────────────────────────────
$days_until_monthly_reset = $balance['days_until_monthly_reset'];
$days_until_yearly_reset  = $balance['days_until_yearly_reset'];

// ── Sick year countdown (Mar 16 reset) ────────────────────
$today           = new DateTime();
$sick_reset_date = new DateTime($sick_year['end_date']);
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

// ── LOP (Loss of Pay from leaves) ─────────────────────────
$lop_total          = getLOPCount($conn, $user_id);
$lop_this_month     = getCurrentMonthLOPUsage($conn, $user_id);
$lop_previous_month = function_exists('getPreviousMonthLOPUsage') ? getPreviousMonthLOPUsage($conn, $user_id) : 0;

// ── Permission LOP in hours ───────────────────────────────
function calculatePermissionLOPHours($conn, $user_id, $period = 'total') {
    $hours = 0;
    $column_check = $conn->query("SHOW COLUMNS FROM permissions LIKE 'lop_hours'");
    $has_lop_column = $column_check && $column_check->num_rows > 0;
    
    $query = "SELECT * FROM permissions 
              WHERE user_id = ? AND status = 'Approved'";
    
    switch($period) {
        case 'month':
            $query .= " AND MONTH(permission_date) = MONTH(CURDATE()) 
                       AND YEAR(permission_date) = YEAR(CURDATE())";
            break;
        case 'prev_month':
            $query .= " AND MONTH(permission_date) = MONTH(DATE_SUB(CURDATE(), INTERVAL 1 MONTH)) 
                       AND YEAR(permission_date) = YEAR(DATE_SUB(CURDATE(), INTERVAL 1 MONTH))";
            break;
    }
    
    $stmt = $conn->prepare($query);
    if ($stmt) {
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $result = $stmt->get_result();
        
        while ($row = $result->fetch_assoc()) {
            $reason = $row['reason'] ?? '';
            $duration = floatval($row['duration'] ?? 0);
            $lop_hours_col = floatval($row['lop_hours'] ?? 0);
            
            $reason_lower = strtolower($reason);
            $has_lop_indicator = (strpos($reason_lower, 'lop') !== false) || 
                                 (strpos($reason_lower, 'loss of pay') !== false) ||
                                 (strpos($reason_lower, 'excess') !== false);
            
            if ($has_lop_indicator) {
                if ($has_lop_column && $lop_hours_col > 0) {
                    $hours += $lop_hours_col;
                } else {
                    $hours += $duration;
                }
            }
        }
        $stmt->close();
    }
    
    return $hours;
}

function getDetailedLOPPermissions($conn, $user_id, $limit = 10) {
    $permissions = [];
    $column_check = $conn->query("SHOW COLUMNS FROM permissions LIKE 'lop_hours'");
    $has_lop_column = $column_check && $column_check->num_rows > 0;
    
    $query = "SELECT * FROM permissions 
              WHERE user_id = ? AND status = 'Approved'
              ORDER BY permission_date DESC 
              LIMIT ?";
    
    $stmt = $conn->prepare($query);
    if ($stmt) {
        $stmt->bind_param("ii", $user_id, $limit);
        $stmt->execute();
        $result = $stmt->get_result();
        
        while ($row = $result->fetch_assoc()) {
            $permission = $row;
            $reason = $row['reason'] ?? '';
            $duration = floatval($row['duration'] ?? 0);
            $lop_hours_col = floatval($row['lop_hours'] ?? 0);
            $reason_lower = strtolower($reason);
            
            $has_lop_indicator = (strpos($reason_lower, 'lop') !== false) || 
                                 (strpos($reason_lower, 'loss of pay') !== false) ||
                                 (strpos($reason_lower, 'excess') !== false);
            
            if ($has_lop_indicator) {
                if ($has_lop_column && $lop_hours_col > 0) {
                    $permission['lop_hours'] = $lop_hours_col;
                } else {
                    $permission['lop_hours'] = $duration;
                }
                $permission['is_lop'] = true;
                $permissions[] = $permission;
            }
        }
        $stmt->close();
    }
    
    return $permissions;
}

$permission_lop_total_hours = calculatePermissionLOPHours($conn, $user_id, 'total');
$permission_lop_month_hours = calculatePermissionLOPHours($conn, $user_id, 'month');
$permission_lop_prev_month_hours = calculatePermissionLOPHours($conn, $user_id, 'prev_month');

// ── Regular permission counts ─────────────────────────────
$permission_total = 0;
$permission_month = 0;
$permission_prev_month = 0;

$perm_query = "SELECT COUNT(*) as total FROM permissions WHERE user_id = ? AND status = 'Approved'";
$stmt = $conn->prepare($perm_query);
if ($stmt) {
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $permission_total = $result->fetch_assoc()['total'] ?? 0;
    $stmt->close();
}

$perm_month_query = "SELECT COUNT(*) as total FROM permissions WHERE user_id = ? AND status = 'Approved' AND MONTH(permission_date) = MONTH(CURDATE()) AND YEAR(permission_date) = YEAR(CURDATE())";
$stmt = $conn->prepare($perm_month_query);
if ($stmt) {
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $permission_month = $result->fetch_assoc()['total'] ?? 0;
    $stmt->close();
}

$perm_prev_query = "SELECT COUNT(*) as total FROM permissions WHERE user_id = ? AND status = 'Approved' AND MONTH(permission_date) = MONTH(DATE_SUB(CURDATE(), INTERVAL 1 MONTH)) AND YEAR(permission_date) = YEAR(DATE_SUB(CURDATE(), INTERVAL 1 MONTH))";
$stmt = $conn->prepare($perm_prev_query);
if ($stmt) {
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $permission_prev_month = $result->fetch_assoc()['total'] ?? 0;
    $stmt->close();
}

// ── Pending count ─────────────────────────────────────────
$pending = 0;
$stmt = $conn->prepare("
    SELECT
        (SELECT COUNT(*) FROM leaves WHERE user_id = ? AND status = 'Pending') AS pending_leaves,
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

// ── Recent approved LOP permissions ───────────────────────
$lop_permissions = getDetailedLOPPermissions($conn, $user_id, 10);

// ── Progress bar helpers ───────────────────────────────────
$casual_progress = $casual_total_entitled > 0 ? round(($casual_used_cycle / $casual_total_entitled) * 100) : 0;
$sick_progress   = ($balance['sick_entitlement'] ?? 6) > 0
    ? round((($balance['used']['Sick'] ?? 0) / ($balance['sick_entitlement'] ?? 6)) * 100) : 0;

$page_title = "Dashboard - MAKSIM HR";
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php include 'includes/head.php'; ?>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; font-family: 'Segoe UI', sans-serif; }
        body { background: linear-gradient(135deg, #2c9218 0%, #006400 100%); min-height: 100vh; padding: 20px; }

        .app-header {
            background: linear-gradient(135deg, #2c9218 0%, #006400 100%);
            color: white; padding: 20px 30px;
            display: flex; justify-content: space-between; align-items: center;
            border-radius: 15px 15px 0 0;
        }
        .user-info   { display: flex; align-items: center; gap: 15px; }
        .user-label  { background: rgba(255,255,255,0.2); padding: 8px 15px; border-radius: 20px; display: flex; align-items: center; gap: 8px; }
        .logout-btn  { background: rgba(255,255,255,0.2); color: white; padding: 8px 15px; border-radius: 20px; text-decoration: none; }

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

        .cycle-banner {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white; padding: 18px 24px; border-radius: 12px; margin-bottom: 16px;
            display: flex; flex-wrap: wrap; gap: 20px; align-items: center; justify-content: space-between;
        }
        .cycle-banner .section { display: flex; flex-direction: column; gap: 4px; }
        .cycle-banner .label   { font-size: 11px; opacity: .75; text-transform: uppercase; letter-spacing: .5px; }
        .cycle-banner .value   { font-size: 16px; font-weight: 700; }
        .cycle-banner .divider { width: 1px; height: 40px; background: rgba(255,255,255,.3); }

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

        .stats-grid { display: grid; grid-template-columns: repeat(4,1fr); gap: 20px; margin-bottom: 25px; }
        @media(max-width:1100px) { .stats-grid { grid-template-columns: repeat(2,1fr); } }
        .stat-card {
            background: white; border-radius: 15px; padding: 20px;
            box-shadow: 0 2px 10px rgba(0,0,0,.05); transition: transform .2s;
        }
        .stat-card:hover { transform: translateY(-4px); box-shadow: 0 6px 20px rgba(0,0,0,.1); }
        .stat-card.lop-card { background: linear-gradient(135deg, #f56565 0%, #c53030 100%); color: white; }
        .stat-card.permission-lop-card { background: linear-gradient(135deg, #ed8936 0%, #c05621 100%); color: white; }
        .stat-icon {
            width: 50px; height: 50px; border-radius: 12px;
            display: flex; align-items: center; justify-content: center;
            margin-bottom: 15px; font-size: 22px;
        }
        .stat-card:not(.lop-card):not(.permission-lop-card) .stat-icon.sick    { background: #fed7d7; color: #c53030; }
        .stat-card:not(.lop-card):not(.permission-lop-card) .stat-icon.casual  { background: #c6f6d5; color: #276749; }
        .stat-card:not(.lop-card):not(.permission-lop-card) .stat-icon.pending { background: #e9d8fd; color: #553c9a; }
        .stat-card.lop-card .stat-icon,
        .stat-card.permission-lop-card .stat-icon { background: rgba(255,255,255,.2); color: white; }
        .stat-value      { font-size: 36px; font-weight: 700; margin-bottom: 5px; }
        .stat-card:not(.lop-card):not(.permission-lop-card) .stat-value { color: #2d3748; }
        .stat-card.lop-card .stat-value,
        .stat-card.permission-lop-card .stat-value { color: white; }
        .stat-label      { font-size: 14px; margin-bottom: 8px; }
        .stat-card:not(.lop-card):not(.permission-lop-card) .stat-label { color: #718096; }
        .stat-card.lop-card .stat-label,
        .stat-card.permission-lop-card .stat-label { color: rgba(255,255,255,.9); }
        .stat-sub { font-size: 12px; padding-top: 10px; border-top: 1px solid #e2e8f0; margin-top: 8px; color: #718096; }
        .stat-card.lop-card .stat-sub,
        .stat-card.permission-lop-card .stat-sub { border-top-color: rgba(255,255,255,.2); color: rgba(255,255,255,.8); }

        .progress-bar  { width: 100%; height: 6px; background: #e2e8f0; border-radius: 4px; overflow: hidden; margin-top: 8px; }
        .progress-fill { height: 100%; border-radius: 4px; transition: width .4s ease; }
        .fill-sick     { background: linear-gradient(90deg,#fc8181,#c53030); }
        .fill-casual   { background: linear-gradient(90deg,#68d391,#276749); }

        .card { background: white; border-radius: 15px; padding: 25px; box-shadow: 0 2px 10px rgba(0,0,0,.05); margin-bottom: 25px; }
        .card-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; }
        .card-title  { font-size: 16px; font-weight: 600; color: #2d3748; }

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

        .table-container { overflow-x: auto; border: 1px solid #e2e8f0; border-radius: 10px; margin-bottom: 20px; }
        table { width: 100%; border-collapse: collapse; }
        th { background: #f7fafc; padding: 12px 14px; text-align: left; font-weight: 600; color: #4a5568; border-bottom: 2px solid #e2e8f0; font-size: 13px; }
        td { padding: 12px 14px; border-bottom: 1px solid #e2e8f0; color: #4a5568; font-size: 13px; }
        tr:last-child td { border-bottom: none; }
        .lop-row { background: #fff5f5; }
        .permission-row { background: #f0f9ff; }
        .permission-lop-row { background: #fff5f0; }
        .status-badge   { padding: 3px 10px; border-radius: 20px; font-size: 11px; font-weight: 600; display: inline-block; }
        .status-approved { background: #c6f6d5; color: #276749; }
        .status-pending  { background: #fefcbf; color: #744210; }
        .status-rejected { background: #fed7d7; color: #c53030; }
        .status-lop { background: #fed7d7; color: #c53030; }
        .lop-badge { background: #c53030; color: white; padding: 2px 8px; border-radius: 12px; font-size: 11px; margin-left: 5px; }
        .lop-hours-badge { background: #ed8936; color: white; padding: 2px 8px; border-radius: 12px; font-size: 11px; margin-left: 5px; }
        .type-lop    { color: #c53030; font-weight: 600; }
        .type-casual { color: #276749; font-weight: 600; }
        .type-sick   { color: #3182ce; font-weight: 600; }
        .duration-badge { background: #4299e1; color: white; padding: 2px 6px; border-radius: 10px; font-size: 10px; margin-left: 5px; }
        .approved-badge { background: #48bb78; color: white; padding: 2px 8px; border-radius: 12px; font-size: 10px; margin-left: 5px; }
    </style>
</head>
<body>

<?php include 'includes/header.php'; ?>

<div class="app-main">
    <?php include 'includes/sidebar.php'; ?>

    <div class="main-content">
        <h2 class="page-title"><i class="icon-dashboard"></i> Dashboard</h2>

        <div class="cycle-banner">
            <div class="section">
                <span class="label">Sick Leave Year</span>
                <span class="value"><i class="icon-calendar"></i> <?php echo $sick_year['year_label']; ?> (Mar 16–Mar 15)</span>
            </div>
            <div class="divider"></div>
            <div class="section">
                <span class="label">Casual Leave Cycle</span>
                <span class="value"><i class="icon-sync"></i> <?php echo $casual_year['year_label']; ?> (Mar 16–Mar 15)</span>
            </div>
            <div class="divider"></div>
            <div class="section">
                <span class="label">Current Window</span>
                <span class="value">
                    <i class="icon-calendar"></i>
                    <?php echo date('d M', strtotime($current_window['window_start'])); ?>
                    &rarr;
                    <?php echo date('d M', strtotime($current_window['window_end'])); ?>
                </span>
            </div>
            <div class="divider"></div>
            <div class="section">
                <span class="label">Join Date</span>
                <span class="value">
                    <i class="icon-user"></i>
                    <?php echo $balance['join_date'] ? date('d M Y', strtotime($balance['join_date'])) : 'N/A'; ?>
                </span>
            </div>
        </div>

        <div class="countdown-row">
            <div class="countdown-pill monthly">
                <div class="pill-icon"><i class="icon-calendar"></i></div>
                <div>
                    <div class="pill-days"><?php echo $days_until_monthly_reset; ?></div>
                    <div class="pill-label">Days until next monthly window</div>
                    <div class="pill-sub">Next accrual on <?php echo date('d M Y', strtotime(date('Y-m') . '-16' . ($today->format('j') >= 16 ? ' +1 month' : ''))); ?></div>
                </div>
            </div>
            <div class="countdown-pill yearly">
                <div class="pill-icon"><i class="icon-hourglass"></i></div>
                <div>
                    <div class="pill-days"><?php echo $days_until_yearly_reset; ?></div>
                    <div class="pill-label">Days until casual cycle resets</div>
                    <div class="pill-sub">Resets <?php echo $next_mar16_label; ?> (Mar 16) — unused days forfeited</div>
                </div>
            </div>
            <div class="countdown-pill" style="border-left: 3px solid #f56565;">
                <div class="pill-icon" style="background:#fff5f5; color:#c53030;"><i class="icon-sick"></i></div>
                <div>
                    <div class="pill-days"><?php echo $days_until_yearly_reset; ?></div>
                    <div class="pill-label">Days until sick leave resets</div>
                    <div class="pill-sub">Resets <?php echo $next_mar16_label; ?> (Mar 16)</div>
                </div>
            </div>
        </div>

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
                <i class="icon-calendar"></i>
                <?php echo date('d M', strtotime($current_window['window_start'])); ?> &rarr; <?php echo date('d M', strtotime($current_window['window_end'])); ?>
            </div>
        </div>

        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-icon sick"><i class="icon-sick"></i></div>
                <div class="stat-value"><?php echo $balance['remaining']['Sick'] ?? 0; ?></div>
                <div class="stat-label">Sick Leave Remaining</div>
                <div class="stat-sub">
                    Entitled: <?php echo $balance['sick_entitlement'] ?? 6; ?> | Used: <?php echo $balance['used']['Sick'] ?? 0; ?>
                    <div class="progress-bar"><div class="progress-fill fill-sick" style="width:<?php echo $sick_progress; ?>%"></div></div>
                </div>
            </div>

            <div class="stat-card">
                <div class="stat-icon casual"><i class="icon-casual"></i></div>
                <div class="stat-value"><?php echo $casual_available; ?></div>
                <div class="stat-label">Casual Leave Available</div>
                <div class="stat-sub">
                    Accrued: <?php echo $casual_accrued; ?> / <?php echo $casual_total_entitled; ?> | Used: <?php echo $casual_used_cycle; ?>
                    <div class="progress-bar"><div class="progress-fill fill-casual" style="width:<?php echo $casual_progress; ?>%"></div></div>
                </div>
            </div>

            <div class="stat-card lop-card">
                <div class="stat-icon"><i class="icon-lop"></i></div>
                <div class="stat-value"><?php echo $lop_this_month; ?></div>
                <div class="stat-label">LOP (Leaves) — This Month</div>
                <div class="stat-sub">
                    <div>Yearly total: <?php echo $lop_total; ?> days</div>
                    <div>Previous month: <?php echo $lop_previous_month; ?> days</div>
                </div>
            </div>

            <div class="stat-card permission-lop-card">
                <div class="stat-icon"><i class="icon-clock"></i></div>
                <div class="stat-value"><?php echo $permission_lop_month_hours; ?> hr</div>
                <div class="stat-label">Permission LOP — This Month</div>
                <div class="stat-sub">
                    <div>Total LOP hours: <?php echo $permission_lop_total_hours; ?> hrs</div>
                    <div>Previous month: <?php echo $permission_lop_prev_month_hours; ?> hrs</div>
                    <div>From approved excess permission hours</div>
                </div>
            </div>
        </div>

        <div class="card">
            <div class="card-header">
                <h3 class="card-title"><i class="icon-balance"></i> Leave Balance Summary</h3>
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
                            <td class="type-sick"><i class="icon-sick"></i> Sick</td>
                            <td><?php echo $sick_year['year_label']; ?> (Mar 16–Mar 15)</td>
                            <td><?php echo $balance['sick_entitlement'] ?? 6; ?></td>
                            <td>—</td>
                            <td><?php echo $balance['used']['Sick'] ?? 0; ?></td>
                            <td><strong><?php echo $balance['remaining']['Sick'] ?? 0; ?></strong></td>
                            <td><?php echo getSickLeaveUsedThisMonth($conn, $user_id); ?> / month</td>
                        </tr>
                        <tr>
                            <td class="type-casual"><i class="icon-casual"></i> Casual</td>
                            <td>
                                <?php echo $casual_year['year_label']; ?> (Mar 16–Mar 15)<br>
                                <small style="color:#a0aec0;">Window: <?php echo date('d M', strtotime($current_window['window_start'])); ?> – <?php echo date('d M', strtotime($current_window['window_end'])); ?></small>
                            </td>
                            <td><?php echo $casual_total_entitled; ?></td>
                            <td><?php echo $casual_accrued; ?></td>
                            <td><?php echo $casual_used_cycle; ?></td>
                            <td><strong style="color:#276749;"><?php echo $casual_available; ?></strong></td>
                            <td><?php echo $casual_used_this_window; ?></td>
                        </tr>
                        <tr class="lop-row">
                            <td class="type-lop"><i class="icon-lop"></i> LOP (Leaves) <span class="lop-badge">Unpaid</span></td>
                            <td><?php echo $sick_year['year_label']; ?></td>
                            <td>N/A</td>
                            <td>—</td>
                            <td style="color:#c53030;"><?php echo $lop_total; ?> days</td>
                            <td>N/A</td>
                            <td><?php echo $lop_this_month; ?> days</td>
                        </tr>
                        <tr class="permission-lop-row">
                            <td style="color:#ed8936; font-weight:600;"><i class="icon-clock"></i> Permission LOP <span class="lop-hours-badge">Hours</span></td>
                            <td><?php echo $sick_year['year_label']; ?></td>
                            <td>N/A</td>
                            <td>—</td>
                            <td style="color:#ed8936;"><?php echo $permission_lop_total_hours; ?> hrs</td>
                            <td>N/A</td>
                            <td><?php echo $permission_lop_month_hours; ?> hrs</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="card">
            <div class="card-header">
                <h3 class="card-title"><i class="icon-history"></i> Recent Leaves</h3>
                <a href="leaves/leaves.php" style="background:#4299e1; color:white; padding:6px 14px; border-radius:6px; text-decoration:none; font-size:13px;"><i class="icon-view"></i> View All</a>
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

        <!-- Recent Approved LOP Permissions -->
        <div class="card">
            <div class="card-header">
                <h3 class="card-title"><i class="icon-clock"></i> Recent LOP Permissions (Approved)</h3>
                <a href="permissions/permissions.php" style="background:#4299e1; color:white; padding:6px 14px; border-radius:6px; text-decoration:none; font-size:13px;"><i class="icon-view"></i> View All</a>
            </div>
            <div class="table-container">
                <table>
                    <thead>
                        <tr><th>Date</th><th>Duration</th><th>Reason</th><th>Status</th><th>LOP Hours</th></tr>
                    </thead>
                    <tbody>
                        <?php if (!empty($lop_permissions)): ?>
                            <?php foreach ($lop_permissions as $permission): ?>
                            <tr class="permission-lop-row" style="background: #fff0e6;">
                                <td><?php echo date('Y-m-d', strtotime($permission['permission_date'])); ?></td>
                                <td>
                                    <?php 
                                    $dur = floatval($permission['duration']);
                                    if ($dur == 1) echo "1 hour";
                                    elseif ($dur < 1) echo ($dur * 60) . " min";
                                    elseif ($dur == 8) echo "Full Day";
                                    else echo $dur . " hours";
                                    ?>
                                </td>
                                <td style="max-width:200px;" title="<?php echo htmlspecialchars($permission['reason']); ?>">
                                    <?php echo htmlspecialchars(mb_strimwidth($permission['reason'] ?? '—', 0, 40, '…')); ?>
                                </td>
                                <td>
                                    <span class="status-badge status-approved">
                                        Approved
                                    </span>
                                    <span class="approved-badge">LOP</span>
                                </td>
                                <td>
                                    <span class="lop-hours-badge"><?php echo $permission['lop_hours']; ?> hr LOP</span>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="5" style="text-align:center; padding:24px; color:#a0aec0;">No approved LOP permissions found</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

    </div>
</div>

<script src="assets/js/app.js"></script>
</body>
</html>