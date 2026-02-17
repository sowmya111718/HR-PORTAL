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

$user_id = $_SESSION['user_id'];
$role = $_SESSION['role'];
$full_name = $_SESSION['full_name'];

$leave_year = getCurrentLeaveYear();
$current_month = getCurrentLeaveMonth();
$balance = getUserLeaveBalance($conn, $user_id);

$lop_total = getLOPCount($conn, $user_id);
$lop_this_month = getCurrentMonthLOPUsage($conn, $user_id);
$lop_previous_month = function_exists('getPreviousMonthLOPUsage') ? getPreviousMonthLOPUsage($conn, $user_id) : 0;

$pending = 0;
$stmt = $conn->prepare("
    SELECT 
        (SELECT COUNT(*) FROM leaves WHERE user_id = ? AND status = 'Pending') as pending_leaves,
        (SELECT COUNT(*) FROM permissions WHERE user_id = ? AND status = 'Pending') as pending_permissions
");
if ($stmt) {
    $stmt->bind_param("ii", $user_id, $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    $pending = ($row['pending_leaves'] ?? 0) + ($row['pending_permissions'] ?? 0);
    $stmt->close();
}

$recent_leaves = [];
$recent_stmt = $conn->prepare("
    SELECT * FROM leaves 
    WHERE user_id = ? 
    ORDER BY applied_date DESC 
    LIMIT 10
");
if ($recent_stmt) {
    $recent_stmt->bind_param("i", $user_id);
    $recent_stmt->execute();
    $result = $recent_stmt->get_result();
    $recent_leaves = $result->fetch_all(MYSQLI_ASSOC);
    $recent_stmt->close();
}

$today = new DateTime();
$reset = new DateTime($leave_year['end_date']);
$reset->modify('+1 day');
$days_until_reset = $today->diff($reset)->days;

$casual_this_month = getCurrentMonthCasualUsage($conn, $user_id);
$casual_remaining = max(0, 1 - $casual_this_month);
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
        .app-header {
            background: linear-gradient(135deg, #2c9218 0%, #006400 100%);
            color: white;
            padding: 20px 30px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            border-radius: 15px 15px 0 0;
        }
        .user-info { display: flex; align-items: center; gap: 15px; }
        .user-label { background: rgba(255,255,255,0.2); padding: 8px 15px; border-radius: 20px; display: flex; align-items: center; gap: 8px; }
        .logout-btn { background: rgba(255,255,255,0.2); color: white; padding: 8px 15px; border-radius: 20px; text-decoration: none; }
        .app-main { display: flex; min-height: 800px; background: white; border-radius: 0 0 15px 15px; }
        .sidebar { width: 250px; background: #f7fafc; border-right: 1px solid #e2e8f0; padding: 20px 0; }
        .sidebar-nav { list-style: none; }
        .sidebar-nav a {
            display: flex; align-items: center; gap: 12px; padding: 15px 25px; color: #4a5568;
            text-decoration: none; border-left: 4px solid transparent;
        }
        .sidebar-nav a:hover, .sidebar-nav a.active {
            background: #edf2f7; color: #006400; border-left-color: #667eea;
        }
        .main-content { flex: 1; padding: 30px; background: #f8fafc; }
        .page-title { color: #006400; margin-bottom: 30px; padding-bottom: 15px; border-bottom: 2px solid #e2e8f0; }
        .stats-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 20px; margin-bottom: 30px; }
        .stat-card {
            background: white; border-radius: 15px; padding: 20px; box-shadow: 0 2px 10px rgba(0,0,0,0.05);
            transition: transform 0.2s;
        }
        .stat-card:hover { transform: translateY(-5px); box-shadow: 0 5px 20px rgba(0,0,0,0.1); }
        .stat-card.lop-card { background: linear-gradient(135deg, #f56565 0%, #c53030 100%); color: white; }
        .stat-icon {
            width: 50px; height: 50px; border-radius: 12px; display: flex; align-items: center;
            justify-content: center; margin-bottom: 15px; font-size: 24px;
        }
        .stat-card:not(.lop-card) .stat-icon.sick { background: #fed7d7; color: #c53030; }
        .stat-card:not(.lop-card) .stat-icon.casual { background: #c6f6d5; color: #276749; }
        .stat-card.lop-card .stat-icon { background: rgba(255,255,255,0.2); color: white; }
        .stat-value { font-size: 36px; font-weight: 700; margin-bottom: 5px; }
        .stat-card:not(.lop-card) .stat-value { color: #2d3748; }
        .stat-card.lop-card .stat-value { color: white; }
        .stat-label { font-size: 14px; margin-bottom: 10px; }
        .stat-card:not(.lop-card) .stat-label { color: #718096; }
        .stat-card.lop-card .stat-label { color: rgba(255,255,255,0.9); }
        .stat-sub { font-size: 13px; padding-top: 10px; border-top: 1px solid #e2e8f0; margin-top: 10px; }
        .stat-card.lop-card .stat-sub { border-top-color: rgba(255,255,255,0.2); color: rgba(255,255,255,0.8); }
        .card { background: white; border-radius: 15px; padding: 25px; box-shadow: 0 2px 10px rgba(0,0,0,0.05); margin-bottom: 25px; }
        .card-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; }
        .table-container { overflow-x: auto; border: 1px solid #e2e8f0; border-radius: 10px; }
        table { width: 100%; border-collapse: collapse; }
        th { background: #f7fafc; padding: 12px; text-align: left; font-weight: 600; color: #4a5568; border-bottom: 2px solid #e2e8f0; }
        td { padding: 12px; border-bottom: 1px solid #e2e8f0; color: #4a5568; }
        .status-badge { padding: 4px 10px; border-radius: 20px; font-size: 12px; font-weight: 600; display: inline-block; }
        .status-approved { background: #c6f6d5; color: #276749; }
        .leave-year-info {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white; padding: 20px; border-radius: 10px; margin-bottom: 25px;
            display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 15px;
        }
        .progress-bar { width: 100%; height: 8px; background: #e2e8f0; border-radius: 4px; overflow: hidden; margin-top: 8px; }
        .progress-fill { height: 100%; transition: width 0.3s ease; }
        .lop-row { background: #fff5f5; }
        .lop-badge { background: #c53030; color: white; padding: 2px 8px; border-radius: 12px; font-size: 11px; margin-left: 5px; display: inline-block; }
        .policy-notice {
            background: #f0fff4; border-left: 4px solid #48bb78; padding: 15px 20px; border-radius: 8px;
            margin-bottom: 20px; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 15px;
        }
        .usage-badge { background: #48bb78; color: white; padding: 8px 15px; border-radius: 20px; font-size: 14px; }
        .type-lop { color: #c53030; font-weight: bold; }
        .type-casual { color: #48bb78; }
        .type-sick { color: #4299e1; }
    </style>
</head>
<body>
    <!-- Header with logo and no icon -->
    <div class="app-header">
        <div style="display: flex; align-items: center;">
            <!-- Logo image - no icon beside it -->
            <img src="assets/images/maksim_infotech_logo.png" alt="MAKSIM Infotech" height="40" style="margin-right: 10px;">
            
            <!-- Title without icon -->
            <h1 style="margin: 0; font-size: 24px;">MAKSIM HR System</h1>
        </div>
        <div class="user-info">
            <div class="user-label">
                <i class="fas fa-user"></i> <?php echo htmlspecialchars($full_name); ?>
            </div>
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
            
            <div class="leave-year-info">
                <div><i class="fas fa-calendar-alt"></i> Current Year: <?php echo $leave_year['year_label']; ?></div>
                <div><i class="fas fa-hourglass-half"></i> Days until reset: <?php echo $days_until_reset; ?></div>
            </div>
            
            <div class="policy-notice">
                <div><i class="fas fa-info-circle" style="color: #48bb78;"></i> Casual Leave: 1 day/month only</div>
                <div class="usage-badge">Used: <?php echo $casual_this_month; ?>/1 this month</div>
            </div>
            
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-icon sick"><i class="fas fa-heartbeat"></i></div>
                    <div class="stat-value"><?php echo $balance['remaining']['Sick'] ?? 0; ?></div>
                    <div class="stat-label">Sick Leave</div>
                </div>
                
                <div class="stat-card">
                    <div class="stat-icon casual"><i class="fas fa-coffee"></i></div>
                    <div class="stat-value"><?php echo $casual_remaining; ?></div>
                    <div class="stat-label">Casual Leave</div>
                    <div class="stat-sub">This month: <?php echo $casual_this_month; ?>/1</div>
                </div>
                
                <div class="stat-card lop-card">
                    <div class="stat-icon"><i class="fas fa-exclamation-triangle"></i></div>
                    <div class="stat-value"><?php echo $lop_this_month; ?></div>
                    <div class="stat-label">LOP - This Month</div>
                    <div class="stat-sub">
                        <div>Yearly: <?php echo $lop_total; ?> days</div>
                        <div>Previous: <?php echo $lop_previous_month; ?> days</div>
                    </div>
                </div>
                
                <div class="stat-card">
                    <div class="stat-icon" style="background: #e9d8fd; color: #553c9a;"><i class="fas fa-hourglass-half"></i></div>
                    <div class="stat-value"><?php echo $pending; ?></div>
                    <div class="stat-label">Pending</div>
                </div>
            </div>

            <div class="card">
                <div class="card-header">
                    <h3 class="card-title"><i class="fas fa-balance-scale"></i> Leave Balance</h3>
                </div>
                <div class="table-container">
                    <table>
                        <thead><tr><th>Type</th><th>Total</th><th>Used</th><th>Remaining</th><th>Monthly</th></tr></thead>
                        <tbody>
                            <tr><td>Sick</td><td><?php echo $balance['sick_entitlement'] ?? 6; ?></td><td><?php echo $balance['used']['Sick'] ?? 0; ?></td><td><?php echo $balance['remaining']['Sick'] ?? 0; ?></td><td>0.5</td></tr>
                            <tr><td>Casual</td><td>12</td><td><?php echo $balance['used']['Casual'] ?? 0; ?></td><td><?php echo $balance['remaining']['Casual'] ?? 0; ?></td><td><?php echo $casual_this_month; ?>/1</td></tr>
                            <tr class="lop-row"><td>LOP <span class="lop-badge">Unpaid</span></td><td>N/A</td><td style="color:#c53030;"><?php echo $lop_this_month; ?> (mon) / <?php echo $lop_total; ?> (yr)</td><td>N/A</td><td><?php echo $lop_this_month; ?></td></tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="card">
                <div class="card-header">
                    <h3 class="card-title"><i class="fas fa-history"></i> Recent Leaves</h3>
                    <a href="leaves/leaves.php" style="background:#4299e1; color:white; padding:6px 12px; border-radius:6px; text-decoration:none;">View All</a>
                </div>
                <div class="table-container">
                    <table>
                        <thead><tr><th>Type</th><th>From</th><th>To</th><th>Days</th><th>Status</th></tr></thead>
                        <tbody>
                            <?php if (!empty($recent_leaves)): ?>
                                <?php foreach ($recent_leaves as $leave): 
                                    $is_lop = $leave['leave_type'] === 'LOP';
                                ?>
                                <tr class="<?php echo $is_lop ? 'lop-row' : ''; ?>">
                                    <td class="<?php echo $is_lop ? 'type-lop' : ($leave['leave_type'] === 'Casual' ? 'type-casual' : 'type-sick'); ?>">
                                        <?php echo $leave['leave_type']; ?>
                                        <?php if ($is_lop): ?><span class="lop-badge">Unpaid</span><?php endif; ?>
                                    </td>
                                    <td><?php echo $leave['from_date']; ?></td>
                                    <td><?php echo $leave['to_date']; ?></td>
                                    <td><?php echo $leave['days']; ?></td>
                                    <td><span class="status-badge status-<?php echo strtolower($leave['status']); ?>"><?php echo $leave['status']; ?></span></td>
                                </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr><td colspan="5" style="text-align:center; padding:20px;">No recent leaves</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</body>
</html>