<?php
require_once '../config/db.php';
require_once '../includes/leave_functions.php';

if (!isLoggedIn()) {
    header('Location: ../auth/login.php');
    exit();
}

$user_id = $_SESSION['user_id'];
$message = '';

// Get current leave info
$leave_year = getCurrentLeaveYear();
$current_month = getCurrentLeaveMonth();
$balance = getUserLeaveBalance($conn, $user_id);

// Handle AJAX for LOP application
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['apply_as_lop_ajax'])) {
    $user_id = $_SESSION['user_id'];
    $from_date = sanitize($_POST['from_date']);
    $to_date = sanitize($_POST['to_date']);
    $reason = sanitize($_POST['reason']);
    $day_type = sanitize($_POST['day_type']);
    $days = floatval($_POST['days']);
    
    $leave_year_for_date = getLeaveYearForDate($from_date);
    $year_label = isset($leave_year_for_date['year_label']) ? $leave_year_for_date['year_label'] : $leave_year['year_label'];
    
    $stmt = $conn->prepare("
        INSERT INTO leaves (user_id, leave_type, from_date, to_date, days, day_type, reason, leave_year)
        VALUES (?, 'LOP', ?, ?, ?, ?, ?, ?)
    ");
    $stmt->bind_param("issdsss", $user_id, $from_date, $to_date, $days, $day_type, $reason, $year_label);
    
    if ($stmt->execute()) {
        echo json_encode(['success' => true, 'message' => 'LOP application submitted successfully']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Error submitting LOP application']);
    }
    $stmt->close();
    exit();
}

// Handle AJAX for mixed application
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['apply_mixed'])) {
    $user_id = $_SESSION['user_id'];
    $from_date = sanitize($_POST['from_date']);
    $to_date = sanitize($_POST['to_date']);
    $reason = sanitize($_POST['reason']);
    $day_type = sanitize($_POST['day_type']);
    $days = floatval($_POST['days']);
    
    $leave_year_for_date = getLeaveYearForDate($from_date);
    $year_label = isset($leave_year_for_date['year_label']) ? $leave_year_for_date['year_label'] : $leave_year['year_label'];
    $used_this_month = getCurrentMonthCasualUsage($conn, $user_id);
    $remaining_this_month = max(0, 1 - $used_this_month);
    
    $conn->begin_transaction();
    
    try {
        $result = [
            'success' => true,
            'casual_days' => 0,
            'lop_days' => 0,
            'message' => ''
        ];
        
        if ($remaining_this_month > 0) {
            $casual_days = min($remaining_this_month, $days);
            $casual_reason = $reason . " (First day - Casual Leave)";
            $casual_stmt = $conn->prepare("
                INSERT INTO leaves (user_id, leave_type, from_date, to_date, days, day_type, reason, leave_year)
                VALUES (?, 'Casual', ?, ?, ?, ?, ?, ?)
            ");
            $casual_stmt->bind_param("issdsss", $user_id, $from_date, $to_date, $casual_days, $day_type, $casual_reason, $year_label);
            $casual_stmt->execute();
            $casual_stmt->close();
            $result['casual_days'] = $casual_days;
        }
        
        $lop_days = $days - ($remaining_this_month > 0 ? min($remaining_this_month, $days) : 0);
        if ($lop_days > 0) {
            $lop_reason = $reason . " (Remaining days - Loss of Pay)";
            $lop_stmt = $conn->prepare("
                INSERT INTO leaves (user_id, leave_type, from_date, to_date, days, day_type, reason, leave_year)
                VALUES (?, 'LOP', ?, ?, ?, ?, ?, ?)
            ");
            $lop_stmt->bind_param("issdsss", $user_id, $from_date, $to_date, $lop_days, $day_type, $lop_reason, $year_label);
            $lop_stmt->execute();
            $lop_stmt->close();
            $result['lop_days'] = $lop_days;
        }
        
        $conn->commit();
        $result['success'] = true;
        $result['message'] = "Applied: {$result['casual_days']} casual + {$result['lop_days']} LOP";
        
    } catch (Exception $e) {
        $conn->rollback();
        $result['success'] = false;
        $result['message'] = 'Error: ' . $e->getMessage();
    }
    
    header('Content-Type: application/json');
    echo json_encode($result);
    exit();
}

// Apply leave with auto LOP
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['apply_leave'])) {
    $leave_type = sanitize($_POST['leave_type']);
    $from_date = sanitize($_POST['from_date']);
    $to_date = sanitize($_POST['to_date']);
    $reason = sanitize($_POST['reason']);
    $day_type = sanitize($_POST['day_type']);
    
    $from = new DateTime($from_date);
    $to = new DateTime($to_date);
    
    if ($from > $to) {
        $message = '<div class="alert alert-error">From date cannot be after To date</div>';
    } else {
        $interval = $from->diff($to);
        $calendar_days = $interval->days + 1;
        
        $working_days = 0;
        $current_date = clone $from;
        
        for ($i = 0; $i < $calendar_days; $i++) {
            $day_of_week = $current_date->format('N');
            if ($day_of_week >= 1 && $day_of_week <= 5) {
                $working_days++;
            }
            $current_date->modify('+1 day');
        }
        
        if ($calendar_days == 1) {
            $days = ($day_type === 'half') ? 0.5 : 1;
        } else {
            $days = ($day_type === 'half') ? $working_days * 0.5 : $working_days;
        }
        
        // Check for overlapping leaves
        $stmt = $conn->prepare("
            SELECT COUNT(*) as count 
            FROM leaves 
            WHERE user_id = ? 
            AND status IN ('Pending', 'Approved')
            AND NOT (to_date < ? OR from_date > ?)
        ");
        $stmt->bind_param("iss", $user_id, $from_date, $to_date);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        $stmt->close();
        
        if ($row['count'] > 0) {
            $message = '<div class="alert alert-error">You already have a leave application for this date range</div>';
        } else {
            // Use the auto-LOP function
            $result = applyLeaveWithAutoLOP($conn, $user_id, $leave_type, $from_date, $to_date, $days, $day_type, $reason);
            
            if ($result['success']) {
                if (isset($result['lop_days']) && $result['lop_days'] > 0) {
                    $message = '<div class="alert alert-warning" style="background: #fff5f5; border-left-color: #c53030;">
                        <i class="fas fa-exclamation-triangle" style="color: #c53030;"></i> 
                        <strong>Application Submitted with LOP!</strong><br>
                        ' . $result['message'] . '<br>
                        Total LOP this year: ' . (getLOPCount($conn, $user_id) + ($result['lop_days'] ?? 0)) . ' days
                    </div>';
                } else {
                    $message = '<div class="alert alert-success">' . $result['message'] . '</div>';
                }
            } else {
                $message = '<div class="alert alert-error">' . $result['message'] . '</div>';
            }
        }
    }
}

// Cancel leave
if (isset($_GET['cancel'])) {
    $leave_id = intval($_GET['cancel']);
    
    $stmt = $conn->prepare("
        UPDATE leaves 
        SET status = 'Cancelled' 
        WHERE id = ? AND user_id = ? AND status = 'Pending'
    ");
    $stmt->bind_param("ii", $leave_id, $user_id);
    
    if ($stmt->execute()) {
        $message = '<div class="alert alert-success">Leave application cancelled successfully</div>';
    } else {
        $message = '<div class="alert alert-error">Error cancelling leave application</div>';
    }
    $stmt->close();
}

// Get user's leaves
$stmt = $conn->prepare("
    SELECT * FROM leaves 
    WHERE user_id = ? 
    ORDER BY applied_date DESC
");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$leaves = $stmt->get_result();
$stmt->close();

// Get LOP total for display
$lop_total = getLOPCount($conn, $user_id);
$lop_this_month = getCurrentMonthLOPUsage($conn, $user_id);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Leave Management - MAKSIM HR</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="../assets/css/style.css">
    <style>
        .leave-year-info {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 15px 20px;
            border-radius: 10px;
            margin-bottom: 20px;
        }
        .policy-badge {
            background: rgba(255,255,255,0.2);
            padding: 8px 15px;
            border-radius: 20px;
            font-size: 14px;
            display: inline-block;
        }
        .month-info {
            background: #f0fff4;
            border-left: 4px solid #48bb78;
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
        }
        .lop-card {
            background: linear-gradient(135deg, #f56565 0%, #c53030 100%);
            color: white;
            padding: 20px;
            border-radius: 10px;
        }
        .casual-monthly {
            background: #f0fff4;
            border: 1px solid #9ae6b4;
            border-radius: 8px;
            padding: 12px;
            margin-top: 10px;
        }
        .warning-box {
            background: #fff3cd;
            border-left: 4px solid #ffc107;
            padding: 15px;
            border-radius: 5px;
            margin: 15px 0;
        }
        .btn-view {
            background: #4299e1;
            color: white;
            padding: 4px 8px;
            border-radius: 4px;
            text-decoration: none;
            font-size: 12px;
        }
        .btn-cancel {
            background: #f56565;
            color: white;
            padding: 4px 8px;
            border-radius: 4px;
            text-decoration: none;
            font-size: 12px;
            margin-right: 5px;
        }
        .lop-badge {
            background: #c53030;
            color: white;
            padding: 2px 8px;
            border-radius: 12px;
            font-size: 11px;
            margin-left: 5px;
            display: inline-block;
        }
        .lop-row {
            background: #fff5f5;
        }
        .type-lop {
            color: #c53030;
            font-weight: bold;
        }
        .type-casual {
            color: #48bb78;
        }
        .type-sick {
            color: #4299e1;
        }
    </style>
</head>
<body>
    <?php include '../includes/header.php'; ?>
    
    <div class="app-main">
        <?php include '../includes/sidebar.php'; ?>
        
        <div class="main-content">
            <h2 class="page-title">Leave Management</h2>
            
            <div class="leave-year-info">
                <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 15px;">
                    <div>
                        <i class="fas fa-calendar-alt"></i> 
                        <strong>Current Leave Year:</strong> <?php echo $leave_year['year_label']; ?> 
                        (Mar 16 - Mar 15)
                    </div>
                    <div class="policy-badge">
                        <i class="fas fa-clock"></i> 
                        Casual Leave: 1 day per month (16th to 15th cycle)
                    </div>
                </div>
            </div>
            
            <div class="month-info">
                <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 15px;">
                    <div>
                        <i class="fas fa-calendar-week"></i> 
                        <strong>Current Leave Month:</strong> <?php echo $current_month['month_label']; ?>
                    </div>
                    <div>
                        <span style="background: #48bb78; color: white; padding: 5px 15px; border-radius: 20px; font-size: 14px;">
                            <i class="fas fa-check-circle"></i> 1 casual leave per month
                        </span>
                    </div>
                </div>
            </div>
            
            <div style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 20px; margin-bottom: 30px;">
                <!-- Sick Leave Card -->
                <div style="background: white; padding: 20px; border-radius: 10px; box-shadow: 0 2px 10px rgba(0,0,0,0.05);">
                    <div style="color: #4299e1; font-size: 14px; text-transform: uppercase; margin-bottom: 10px;">
                        <i class="fas fa-heartbeat"></i> Sick Leave
                    </div>
                    <div style="font-size: 36px; font-weight: bold; color: #2d3748;"><?php echo $balance['remaining']['Sick']; ?></div>
                    <div style="color: #718096;">Yearly remaining</div>
                    <div style="margin-top: 10px; font-size: 13px; color: #f56565;">
                        <i class="fas fa-clock"></i> 2 month restriction
                    </div>
                </div>
                
                <!-- Casual Leave Card -->
                <div style="background: white; padding: 20px; border-radius: 10px; box-shadow: 0 2px 10px rgba(0,0,0,0.05);">
                    <div style="color: #48bb78; font-size: 14px; text-transform: uppercase; margin-bottom: 10px;">
                        <i class="fas fa-coffee"></i> Casual Leave
                    </div>
                    <div style="font-size: 36px; font-weight: bold; color: #2d3748;"><?php echo $balance['casual_remaining_this_month']; ?></div>
                    <div style="color: #718096;">Available this month</div>
                    <div class="casual-monthly">
                        <div style="display: flex; justify-content: space-between;">
                            <span>Used this month:</span>
                            <span style="font-weight: 600;"><?php echo $balance['casual_this_month']; ?>/1 day</span>
                        </div>
                        <div style="display: flex; justify-content: space-between; margin-top: 5px;">
                            <span>Yearly remaining:</span>
                            <span style="font-weight: 600;"><?php echo $balance['remaining']['Casual']; ?>/12 days</span>
                        </div>
                    </div>
                </div>
                
                <!-- LOP Card -->
                <div class="lop-card">
                    <div style="font-size: 14px; text-transform: uppercase; margin-bottom: 10px; opacity: 0.9;">
                        <i class="fas fa-exclamation-triangle"></i> Loss of Pay (LOP)
                    </div>
                    <div style="font-size: 36px; font-weight: bold;"><?php echo $lop_total; ?></div>
                    <div style="opacity: 0.9;">Total days this year</div>
                    <div style="margin-top: 5px; font-size: 14px;">
                        This month: <strong><?php echo $lop_this_month; ?></strong> days
                    </div>
                    <div style="margin-top: 10px; font-size: 13px; background: rgba(255,255,255,0.2); padding: 8px; border-radius: 5px;">
                        <i class="fas fa-info-circle"></i> Unpaid leave - affects salary
                    </div>
                </div>
            </div>
            
            <?php echo $message; ?>
            
            <!-- Apply Leave Form - Only Sick and Casual options -->
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title"><i class="fas fa-plus-circle"></i> Apply for Leave</h3>
                </div>
                <form method="POST" action="" id="leaveForm">
                    <div class="warning-box">
                        <i class="fas fa-info-circle"></i> 
                        <strong>Casual Leave Policy:</strong> You can take only 1 casual leave per month. 
                        Any additional days in the same month will automatically become 
                        <span style="color: #c53030; font-weight: 600;">Loss of Pay (LOP) - Unpaid Leave</span>.
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label">From Date *</label>
                            <input type="date" name="from_date" id="from_date" class="form-control" required>
                        </div>
                        <div class="form-group">
                            <label class="form-label">To Date *</label>
                            <input type="date" name="to_date" id="to_date" class="form-control" required>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Leave Type *</label>
                            <select name="leave_type" id="leave_type" class="form-control" required>
                                <option value="">Select Type</option>
                                <option value="Sick">Sick Leave (6 days/year, 2 month restriction)</option>
                                <option value="Casual">Casual Leave (1 day/month, excess becomes LOP)</option>
                            </select>
                        </div>
                    </div>
                    
                    <div id="leave_type_info" style="margin-bottom: 15px;"></div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label">Day Type *</label>
                            <div style="display: flex; gap: 20px; margin-top: 8px;">
                                <label style="display: flex; align-items: center; gap: 5px;">
                                    <input type="radio" name="day_type" value="full" checked required> Full Day
                                </label>
                                <label style="display: flex; align-items: center; gap: 5px;">
                                    <input type="radio" name="day_type" value="half" required> Half Day
                                </label>
                            </div>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Reason *</label>
                        <textarea name="reason" id="reason" class="form-control" rows="3" required placeholder="Enter reason for leave"></textarea>
                    </div>
                    
                    <button type="submit" name="apply_leave" class="btn">Apply Leave</button>
                </form>
            </div>

            <!-- My Leave Applications - Shows LOP in type column -->
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title"><i class="fas fa-list"></i> My Leave Applications</h3>
                </div>
                <div class="table-container">
                    <table>
                        <thead>
                            <tr>
                                <th>Type</th>
                                <th>From</th>
                                <th>To</th>
                                <th>Days</th>
                                <th>Status</th>
                                <th>Reason</th>
                                <th>Day Type</th>
                                <th>Applied Date</th>
                                <th>Leave Year</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($leaves->num_rows > 0): ?>
                                <?php while ($leave = $leaves->fetch_assoc()): 
                                    $is_lop = $leave['leave_type'] === 'LOP';
                                    $type_class = $is_lop ? 'type-lop' : ($leave['leave_type'] === 'Casual' ? 'type-casual' : 'type-sick');
                                ?>
                                <tr <?php echo $is_lop ? 'class="lop-row"' : ''; ?>>
                                    <td class="<?php echo $type_class; ?>">
                                        <?php echo $leave['leave_type']; ?>
                                        <?php if ($is_lop): ?>
                                            <span class="lop-badge">Unpaid</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo $leave['from_date']; ?></td>
                                    <td><?php echo $leave['to_date']; ?></td>
                                    <td><?php echo $leave['days']; ?></td>
                                    <td>
                                        <span class="status-badge status-<?php echo strtolower($leave['status']); ?>">
                                            <?php echo $leave['status']; ?>
                                        </span>
                                    </td>
                                    <td title="<?php echo htmlspecialchars($leave['reason']); ?>">
                                        <?php 
                                        $reason_text = $leave['reason'];
                                        if ($is_lop && strpos($reason_text, '(Loss of Pay)') === false) {
                                            $reason_text .= ' (Loss of Pay)';
                                        }
                                        echo strlen($reason_text) > 30 ? substr($reason_text, 0, 30) . '...' : $reason_text; 
                                        ?>
                                    </td>
                                    <td><?php echo $leave['day_type'] === 'half' ? 'Half Day' : 'Full Day'; ?></td>
                                    <td><?php echo date('Y-m-d', strtotime($leave['applied_date'])); ?></td>
                                    <td>
                                        <span style="background: #e2e8f0; padding: 3px 8px; border-radius: 12px; font-size: 11px;">
                                            <?php echo $leave['leave_year'] ?? $leave_year['year_label']; ?>
                                        </span>
                                    </td>
                                    <td>
                                        <div class="action-buttons">
                                            <?php if ($leave['status'] === 'Pending'): ?>
                                                <a href="?cancel=<?php echo $leave['id']; ?>" 
                                                   class="btn-cancel"
                                                   onclick="return confirm('Cancel this application?')">
                                                    <i class="fas fa-times"></i> Cancel
                                                </a>
                                            <?php endif; ?>
                                            <button class="btn-view" onclick="viewLeaveDetails(<?php echo $leave['id']; ?>)">
                                                <i class="fas fa-eye"></i> View
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="10" style="text-align: center; padding: 40px;">
                                        No leave applications found
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
    document.addEventListener('DOMContentLoaded', function() {
        var today = new Date().toISOString().split('T')[0];
        document.getElementById('from_date').value = today;
        document.getElementById('to_date').value = today;
    });
    
    document.getElementById('leave_type').addEventListener('change', function() {
        const type = this.value;
        const infoDiv = document.getElementById('leave_type_info');
        const balance = <?php echo json_encode($balance); ?>;
        
        if (type === 'Casual') {
            infoDiv.innerHTML = `
                <div class="alert alert-info" style="background: #f0fff4; border-left-color: #48bb78;">
                    <i class="fas fa-info-circle"></i> 
                    <strong>Current Month:</strong> ${balance.casual_this_month}/1 day used.<br>
                    <strong>Remaining this month:</strong> ${balance.casual_remaining_this_month} day(s)<br>
                    <strong>Yearly remaining:</strong> ${balance.remaining.Casual}/12 days<br>
                    <span style="color: #c53030;">Note: Any days beyond 1 this month become LOP (unpaid)</span>
                </div>
            `;
        } else if (type === 'Sick') {
            infoDiv.innerHTML = `
                <div class="alert alert-info" style="background: #ebf8ff; border-left-color: #4299e1;">
                    <i class="fas fa-info-circle"></i> 
                    <strong>Sick Leave:</strong> ${balance.remaining.Sick} days remaining this year.<br>
                    <span style="color: #f56565;">2 month restriction applies</span>
                </div>
            `;
        } else {
            infoDiv.innerHTML = '';
        }
    });
    
    function viewLeaveDetails(id) {
        window.location.href = 'leave_details.php?id=' + id;
    }
    
    function applyAsLOP() {
        const fromDate = document.getElementById('lop_from_date').value;
        const toDate = document.getElementById('lop_to_date').value;
        const reason = document.getElementById('lop_reason').value;
        const dayType = document.getElementById('lop_day_type').value;
        const days = document.getElementById('lop_days').value;
        
        fetch('leaves.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: 'apply_as_lop_ajax=1&from_date=' + encodeURIComponent(fromDate) + 
                  '&to_date=' + encodeURIComponent(toDate) + 
                  '&reason=' + encodeURIComponent(reason) + 
                  '&day_type=' + encodeURIComponent(dayType) + 
                  '&days=' + encodeURIComponent(days)
        })
        .then(response => response.json())
        .then(data => {
            alert(data.message);
            if (data.success) {
                location.reload();
            }
        })
        .catch(error => {
            console.error('Error:', error);
            alert('An error occurred. Please try again.');
        });
    }
    
    function applyAsMixed() {
        const fromDate = document.getElementById('mixed_from_date').value;
        const toDate = document.getElementById('mixed_to_date').value;
        const reason = document.getElementById('mixed_reason').value;
        const dayType = document.getElementById('mixed_day_type').value;
        const days = document.getElementById('mixed_days').value;
        
        fetch('leaves.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: 'apply_mixed=1&from_date=' + encodeURIComponent(fromDate) + 
                  '&to_date=' + encodeURIComponent(toDate) + 
                  '&reason=' + encodeURIComponent(reason) + 
                  '&day_type=' + encodeURIComponent(dayType) + 
                  '&days=' + encodeURIComponent(days)
        })
        .then(response => response.json())
        .then(data => {
            alert(data.message);
            if (data.success) {
                location.reload();
            }
        })
        .catch(error => {
            console.error('Error:', error);
            alert('An error occurred. Please try again.');
        });
    }
    </script>
</body>
</html>