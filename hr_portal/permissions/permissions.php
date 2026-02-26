<?php
require_once '../config/db.php';
require_once '../includes/leave_functions.php';
require_once '../includes/icon_functions.php';

if (!isLoggedIn()) {
    header('Location: ../auth/login.php');
    exit();
}

$user_id = $_SESSION['user_id'];
$message = '';

// Function to get total permission hours used in a specific window (16th-15th)
function getPermissionHoursInWindow($conn, $user_id, $window_start, $window_end) {
    $stmt = $conn->prepare("
        SELECT COALESCE(SUM(duration), 0) as total_hours 
        FROM permissions 
        WHERE user_id = ? 
        AND status IN ('Approved', 'Pending', 'LOP')
        AND permission_date BETWEEN ? AND ?
    ");
    $stmt->bind_param("iss", $user_id, $window_start, $window_end);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    $stmt->close();
    
    return floatval($row['total_hours']);
}

// Apply permission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['apply_permission'])) {
    
    $permission_date = sanitize($_POST['permission_date']);
    $duration = floatval($_POST['duration']);
    $reason = sanitize($_POST['reason']);
    
    // Check if the date is valid
    $date_timestamp = strtotime($permission_date);
    if ($date_timestamp === false) {
        $message = '<div class="alert alert-error"><i class="icon-error"></i> Invalid date format. Please use YYYY-MM-DD format</div>';
    } else {
        $formatted_date = date('Y-m-d', $date_timestamp);
        
        // Check for existing permission on same date
        $stmt = $conn->prepare("
            SELECT COUNT(*) as count 
            FROM permissions 
            WHERE user_id = ? 
            AND permission_date = ? 
            AND status IN ('Pending', 'Approved', 'LOP')
        ");
        $stmt->bind_param("is", $user_id, $formatted_date);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        $stmt->close();
        
        if ($row['count'] > 0) {
            $message = '<div class="alert alert-error"><i class="icon-error"></i> You already have a permission request for this date</div>';
        } else {
            // Get the window for the selected date using the function from leave_functions.php
            $window = getWindowForDate($formatted_date);
            $window_start = $window['window_start'];
            $window_end = $window['window_end'];
            
            // Check monthly permission limit (4 hours max per window 16th-15th)
            $used_hours = getPermissionHoursInWindow($conn, $user_id, $window_start, $window_end);
            $remaining_hours = 4 - $used_hours;
            
            if ($duration > $remaining_hours) {
                // Not enough permission hours left - excess becomes LOP
                $lop_hours = $duration - $remaining_hours;
                
                // Start transaction
                $conn->begin_transaction();
                
                try {
                    $permission_ids = [];
                    
                    // Insert permission for remaining hours - with PENDING status
                    if ($remaining_hours > 0) {
                        $stmt1 = $conn->prepare("
                            INSERT INTO permissions (user_id, permission_date, duration, reason, status)
                            VALUES (?, ?, ?, ?, 'Pending')
                        ");
                        $permission_reason = $reason . " (Partial - within 4hr limit)";
                        $stmt1->bind_param("isds", $user_id, $formatted_date, $remaining_hours, $permission_reason);
                        $stmt1->execute();
                        $permission_ids[] = $stmt1->insert_id;
                        $stmt1->close();
                    }
                    
                    // Insert LOP for excess hours - stored in permissions table
                    if ($lop_hours > 0) {
                        $lop_reason = $reason . " (Excess " . $lop_hours . "hr - LOP, 4hr monthly limit exceeded)";

                        // Ensure lop_hours column exists (add it if not)
                        $conn->query("ALTER TABLE permissions ADD COLUMN IF NOT EXISTS lop_hours DECIMAL(4,1) DEFAULT NULL");

                        // Ensure LOP is a valid status (handle ENUM or VARCHAR)
                        $col_info = $conn->query("SHOW COLUMNS FROM permissions LIKE 'status'");
                        if ($col_info && $col_row = $col_info->fetch_assoc()) {
                            if (strpos($col_row['Type'], 'enum') !== false && strpos($col_row['Type'], "'LOP'") === false) {
                                // Add LOP to the enum
                                $new_type = str_replace(")", ",'LOP')", $col_row['Type']);
                                $conn->query("ALTER TABLE permissions MODIFY COLUMN status $new_type NOT NULL DEFAULT 'Pending'");
                            }
                        }

                        $stmt2 = $conn->prepare("
                            INSERT INTO permissions (user_id, permission_date, duration, reason, status, lop_hours)
                            VALUES (?, ?, ?, ?, 'LOP', ?)
                        ");
                        $stmt2->bind_param("isdsd", $user_id, $formatted_date, $lop_hours, $lop_reason, $lop_hours);
                        $stmt2->execute();
                        $permission_ids[] = $stmt2->insert_id;
                        $stmt2->close();
                    }
                    
                    $conn->commit();
                    
                    if ($remaining_hours > 0 && $lop_hours > 0) {
                        $message = '<div class="alert alert-warning" style="background: #fff5f5; border-left-color: #c53030;">
                            <i class="icon-warning"></i> 
                            <strong>Partial Submission with LOP!</strong><br>
                            You have used ' . $used_hours . ' of 4 monthly permission hours for the window ' . date('M j', strtotime($window_start)) . ' - ' . date('M j, Y', strtotime($window_end)) . '.<br>
                            ' . $remaining_hours . ' hours submitted as permission (pending approval).<br>
                            ' . $lop_hours . ' hours converted to LOP (Loss of Pay) as unpaid leave.
                        </div>';
                    } else if ($remaining_hours > 0) {
                        $message = '<div class="alert alert-success"><i class="icon-success"></i> Permission request submitted successfully! ' . $remaining_hours . ' hours (pending approval).</div>';
                    } else {
                        $message = '<div class="alert alert-warning" style="background: #fff5f5; border-left-color: #c53030;">
                            <i class="icon-warning"></i> 
                            <strong>Fully Converted to LOP!</strong><br>
                            You have used all 4 monthly permission hours for the window ' . date('M j', strtotime($window_start)) . ' - ' . date('M j, Y', strtotime($window_end)) . '.<br>
                            All ' . $duration . ' hours converted to LOP (Loss of Pay) as unpaid leave.
                        </div>';
                    }
                    
                } catch (Exception $e) {
                    $conn->rollback();
                    $message = '<div class="alert alert-error"><i class="icon-error"></i> Error processing request: ' . $e->getMessage() . '</div>';
                }
                
            } else {
                // Enough permission hours left - insert normally with PENDING status
                $stmt = $conn->prepare("
                    INSERT INTO permissions (user_id, permission_date, duration, reason, status)
                    VALUES (?, ?, ?, ?, 'Pending')
                ");
                $stmt->bind_param("isds", $user_id, $formatted_date, $duration, $reason);
                
                if ($stmt->execute()) {
                    $message = '<div class="alert alert-success"><i class="icon-success"></i> Permission request submitted successfully! (' . $duration . ' hours) - Pending approval.</div>';
                } else {
                    $message = '<div class="alert alert-error"><i class="icon-error"></i> Error submitting permission request</div>';
                }
                $stmt->close();
            }
        }
    }
}

// Cancel permission
if (isset($_GET['cancel'])) {
    $permission_id = intval($_GET['cancel']);
    
    $stmt = $conn->prepare("
        UPDATE permissions 
        SET status = 'Cancelled' 
        WHERE id = ? AND user_id = ? AND status = 'Pending'
    ");
    $stmt->bind_param("ii", $permission_id, $user_id);
    
    if ($stmt->execute()) {
        $message = '<div class="alert alert-success"><i class="icon-success"></i> Permission request cancelled successfully</div>';
    } else {
        $message = '<div class="alert alert-error"><i class="icon-error"></i> Error cancelling permission request</div>';
    }
    $stmt->close();
}

// Get user's permissions
$stmt = $conn->prepare("
    SELECT * FROM permissions 
    WHERE user_id = ? 
    ORDER BY permission_date DESC, applied_date DESC
");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$permissions = $stmt->get_result();
$stmt->close();

// Get current window for display (based on today) using the function from leave_functions.php
$current_window = getWindowForDate(date('Y-m-d'));
$current_window_start = $current_window['window_start'];
$current_window_end = $current_window['window_end'];

// Get monthly usage statistics for current window - CACHE THIS VALUE
$used_hours = getPermissionHoursInWindow($conn, $user_id, $current_window_start, $current_window_end);
$remaining_hours = max(0, 4 - $used_hours);

// Format window dates for display
$window_start_display = date('M j', strtotime($current_window_start));
$window_end_display = date('M j, Y', strtotime($current_window_end));
$window_label = $window_start_display . ' - ' . $window_end_display;

$page_title = "Permission Management - MAKSIM HR";
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Permission Management - MAKSIM HR</title>
    <?php include '../includes/head.php'; ?>
    <!-- REMOVED Flatpickr CSS - Not needed for basic functionality -->
    <style>
        /* Simple date input styling - no external dependencies */
        .date-input-container {
            position: relative;
        }
        
        /* Permission limit card styles */
        .permission-limit-card {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 20px;
            border-radius: 10px;
            margin-bottom: 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 15px;
        }
        .limit-stats {
            display: flex;
            gap: 30px;
            flex-wrap: wrap;
        }
        .limit-stat {
            text-align: center;
        }
        .limit-stat-value {
            font-size: 32px;
            font-weight: bold;
        }
        .limit-stat-label {
            font-size: 12px;
            opacity: 0.8;
        }
        .limit-progress {
            width: 200px;
            height: 8px;
            background: rgba(255,255,255,0.2);
            border-radius: 4px;
            overflow: hidden;
        }
        .limit-progress-fill {
            height: 100%;
            background: #48bb78;
            transition: width 0.3s ease;
        }
        .warning-message {
            background: #fff5f5;
            border-left: 4px solid #c53030;
            padding: 15px;
            border-radius: 8px;
            margin: 15px 0;
            color: #742a2a;
        }
        .info-message {
            background: #ebf8ff;
            border-left: 4px solid #4299e1;
            padding: 15px;
            border-radius: 8px;
            margin: 15px 0;
        }
        .window-badge {
            background: rgba(255,255,255,0.2);
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 12px;
            display: inline-flex;
            align-items: center;
            gap: 5px;
        }
        .btn-primary {
            background: #0db329;
            color: white;
            padding: 10px 20px;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            font-size: 16px;
        }
        .btn-primary:hover {
            background: #24d14f;
        }
        i[class^="icon-"] {
            font-style: normal;
            display: inline-block;
        }
    </style>
</head>
<body>
    <?php include '../includes/header.php'; ?>
    
    <div class="app-main">
        <?php include '../includes/sidebar.php'; ?>
        
        <div class="main-content">
            <h2 class="page-title"><i class="icon-clock"></i> Permission Management</h2>
            
            <?php echo $message; ?>
            
            <!-- Permission Limit Card -->
            <div class="permission-limit-card">
                <div style="display: flex; align-items: center; gap: 15px; flex-wrap: wrap;">
                    <div>
                        <i class="icon-clock"></i> 
                        <strong>Monthly Permission Limit:</strong> 4 hours per month (16th - 15th cycle)
                    </div>
                    <span class="window-badge">
                        <i class="icon-calendar"></i> Current Window: <?php echo $window_label; ?>
                    </span>
                </div>
                <div class="limit-stats">
                    <div class="limit-stat">
                        <div class="limit-stat-value"><?php echo $used_hours; ?></div>
                        <div class="limit-stat-label">Hours Used</div>
                    </div>
                    <div class="limit-stat">
                        <div class="limit-stat-value"><?php echo $remaining_hours; ?></div>
                        <div class="limit-stat-label">Hours Remaining</div>
                    </div>
                    <div class="limit-stat">
                        <div class="limit-progress">
                            <div class="limit-progress-fill" style="width: <?php echo min(100, ($used_hours / 4) * 100); ?>%"></div>
                        </div>
                        <div class="limit-stat-label"><?php echo $window_label; ?></div>
                    </div>
                </div>
            </div>
            
            <!-- Warning if limit reached -->
            <?php if ($used_hours >= 4): ?>
            <div class="warning-message">
                <i class="icon-warning"></i> 
                <strong>⚠️ Monthly Permission Limit Reached!</strong><br>
                You have used all 4 hours of your permission quota for the current window (<?php echo $window_label; ?>).
                Any additional permission requests will automatically be converted to 
                <span style="color: #c53030; font-weight: 600;">Loss of Pay (LOP) - Unpaid Leave</span>.
            </div>
            <?php elseif ($remaining_hours > 0 && $remaining_hours < 2): ?>
            <div class="info-message">
                <i class="icon-info"></i> 
                <strong>Low Permission Balance:</strong> You have only <?php echo $remaining_hours; ?> hour(s) remaining for the current window (<?php echo $window_label; ?>).
                Any excess hours will be converted to LOP.
            </div>
            <?php endif; ?>
            
            <!-- Apply Permission Form -->
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title"><i class="icon-plus"></i> Apply for Permission</h3>
                </div>
                <form method="POST" action="">
                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label">Date *</label>
                            <div class="date-input-container">
                                <input type="date" 
                                       name="permission_date" 
                                       id="permission_date" 
                                       class="form-control" 
                                       required
                                       value="<?php echo date('Y-m-d'); ?>">
                            </div>
                            <small class="form-text">Select any date</small>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Duration (hours) *</label>
                            <select name="duration" id="duration_select" class="form-control" required onchange="updateDurationInfo()">
                                <option value="0.5">30 minutes</option>
                                <option value="1" selected>1 hour</option>
                                <option value="1.5">1.5 hours</option>
                                <option value="2">2 hours</option>
                                <option value="2.5">2.5 hours</option>
                                <option value="3">3 hours</option>
                                <option value="3.5">3.5 hours</option>
                                <option value="4">4 hours</option>
                            </select>
                        </div>
                    </div>
                    
                    <div id="duration_info" style="margin-bottom: 15px;"></div>
                    
                    <div class="form-group">
                        <label class="form-label">Reason *</label>
                        <textarea name="reason" class="form-control" rows="3" required placeholder="Enter detailed reason for permission"></textarea>
                    </div>
                    
                    <button type="submit" name="apply_permission" class="btn btn-primary">
                        <i class="icon-plus"></i> Apply Permission
                    </button>
                </form>
            </div>

            <!-- My Permission Requests -->
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title"><i class="icon-list"></i> My Permission Requests</h3>
                </div>
                <div class="table-container">
                    <table>
                        <thead>
                            <tr>
                                <th>Date</th>
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
                                <tr style="<?php echo $is_lop ? 'background:#fff5f5;' : ''; ?>">
                                    <td><?php echo date('M j, Y', strtotime($permission['permission_date'])); ?></td>
                                    <td>
                                        <?php 
                                        $dur = floatval($permission['duration']);
                                        if ($dur == 1) echo "1 hour";
                                        elseif ($dur < 1) echo ($dur * 60) . " min";
                                        elseif ($dur == 8) echo "Full Day";
                                        else echo $dur . " hours";
                                        ?>
                                        <?php if ($is_lop): ?>
                                            <span style="display:inline-block; background:#c53030; color:white; font-size:10px; font-weight:700; padding:1px 7px; border-radius:10px; margin-left:5px;">LOP</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($is_lop): ?>
                                            <span class="status-badge" style="background:#fed7d7; color:#c53030;">LOP</span>
                                        <?php else: ?>
                                            <span class="status-badge status-<?php echo strtolower($permission['status']); ?>">
                                                <?php echo $permission['status']; ?>
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                    <td title="<?php echo htmlspecialchars($permission['reason']); ?>">
                                        <?php echo strlen($permission['reason']) > 50 ? substr($permission['reason'], 0, 50) . '...' : $permission['reason']; ?>
                                    </td>
                                    <td><?php echo date('M j, Y', strtotime($permission['applied_date'])); ?></td>
                                    <td>
                                        <div class="action-buttons">
                                            <?php if ($permission['status'] === 'Pending'): ?>
                                                <a href="?cancel=<?php echo $permission['id']; ?>" 
                                                   class="btn-small btn-cancel"
                                                   onclick="return confirm('Are you sure you want to cancel this permission request?')">
                                                    <i class="icon-cancel"></i> Cancel
                                                </a>
                                            <?php endif; ?>
                                            <a href="permission_details.php?id=<?php echo $permission['id']; ?>" class="btn-small btn-view">
                                                <i class="icon-view"></i> View
                                            </a>
                                        </div>
                                    </td>
                                </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="6" style="text-align: center; padding: 40px; color: #718096;">
                                        <i class="icon-folder-open" style="font-size: 48px; margin-bottom: 15px; display: block;"></i>
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
    // Optimized duration update function - only runs when needed
    function updateDurationInfo() {
        const duration = parseFloat(document.getElementById('duration_select').value);
        const usedHours = <?php echo $used_hours; ?>;
        const remainingHours = <?php echo $remaining_hours; ?>;
        const infoDiv = document.getElementById('duration_info');
        
        if (duration > remainingHours) {
            const excessHours = duration - remainingHours;
            const lopDays = (excessHours / 8).toFixed(2);
            infoDiv.innerHTML = `
                <div class="alert alert-warning" style="background: #fff5f5; border-left-color: #c53030;">
                    <i class="icon-warning"></i> 
                    <strong>⚠️ Monthly Limit Exceeded!</strong><br>
                    You have only ${remainingHours} hour(s) remaining for the current window (<?php echo $window_label; ?>).<br>
                    <strong>${remainingHours} hour(s)</strong> will be submitted as permission (pending approval).<br>
                    <strong>${excessHours} hour(s) (${lopDays} days)</strong> will be converted to 
                    <span style="color: #c53030; font-weight: 600;">Loss of Pay (LOP) - Unpaid Leave</span>.
                </div>
            `;
        } else {
            infoDiv.innerHTML = `
                <div class="alert alert-info" style="background: #f0fff4; border-left-color: #48bb78;">
                    <i class="icon-check"></i> 
                    <strong>Within Monthly Limit</strong><br>
                    You have ${remainingHours} hour(s) remaining for the current window (<?php echo $window_label; ?>).<br>
                    This ${duration} hour(s) request will be submitted as permission (pending approval).
                </div>
            `;
        }
    }
    
    // Initialize on page load
    document.addEventListener('DOMContentLoaded', function() {
        updateDurationInfo();
    });
    </script>
    
    <script src="../assets/js/app.js"></script>
</body>
</html>