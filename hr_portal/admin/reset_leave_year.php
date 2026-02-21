<?php
require_once '../config/db.php';
require_once '../includes/leave_functions.php';

// Check if user is logged in and has admin/hr role
if (!isLoggedIn()) {
    header('Location: ../auth/login.php');
    exit();
}

$role = $_SESSION['role'];
if (!in_array($role, ['hr', 'admin'])) {
    header('Location: ../dashboard.php');
    exit();
}

$message = '';
$result = null;

// Handle reset request
if (isset($_POST['reset_leave_year'])) {
    $confirm = $_POST['confirm_reset'] ?? '';
    
    if ($confirm === 'RESET LEAVE YEAR') {
        $result = resetLeaveBalancesForNewYear($conn);
        $message = '<div class="alert alert-' . ($result['success'] ? 'success' : 'error') . '">' . $result['message'] . '</div>';
    } else {
        $message = '<div class="alert alert-error">Please type "RESET LEAVE YEAR" to confirm</div>';
    }
}

// Get leave year info
$leave_year = getCurrentLeaveYear();
$prev_year = getPreviousLeaveYear();
$stats = getLeaveYearStatistics($conn);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reset Leave Year - MAKSIM HR</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="../assets/css/style.css">
    <style>
        .reset-container {
            max-width: 800px;
            margin: 0 auto;
        }
        .info-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 20px;
            margin-bottom: 30px;
        }
        .info-card {
            background: white;
            padding: 20px;
            border-radius: 10px;
            box-shadow: 0 2px 5px rgba(0,0,0,0.05);
            text-align: center;
        }
        .info-card i {
            font-size: 32px;
            margin-bottom: 10px;
            color: #667eea;
        }
        .info-label {
            color: #718096;
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: 1px;
        }
        .info-value {
            font-size: 28px;
            font-weight: bold;
            color: #2d3748;
        }
        .warning-box {
            background: #fff3cd;
            border: 1px solid #ffeeba;
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 30px;
            color: #856404;
        }
        .warning-box h4 {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 15px;
            color: #856404;
        }
        .warning-box ul {
            margin-left: 30px;
            line-height: 1.8;
        }
        .confirm-input {
            width: 100%;
            padding: 12px;
            border: 2px solid #e2e8f0;
            border-radius: 8px;
            font-size: 16px;
            margin-bottom: 20px;
        }
        .confirm-input:focus {
            border-color: #667eea;
            outline: none;
        }
        .btn-reset {
            background: #e53e3e;
            color: white;
            padding: 12px 30px;
            border: none;
            border-radius: 8px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 10px;
            transition: all 0.3s ease;
        }
        .btn-reset:hover {
            background: #c53030;
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(229, 62, 62, 0.3);
        }
        .btn-cancel {
            background: #718096;
            color: white;
            padding: 12px 30px;
            border: none;
            border-radius: 8px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 10px;
            text-decoration: none;
            margin-left: 10px;
        }
        .btn-cancel:hover {
            background: #4a5568;
        }
    </style>
</head>
<body>
    <?php include '../includes/header.php'; ?>
    
    <div class="app-main">
        <?php include '../includes/sidebar.php'; ?>
        
        <div class="main-content reset-container">
            <h2 class="page-title">
                <i class="fas fa-calendar-alt"></i> Reset Leave Year
            </h2>
            
            <?php echo $message; ?>
            
            <!-- Leave Year Info -->
            <div class="info-grid">
                <div class="info-card">
                    <i class="fas fa-calendar"></i>
                    <div class="info-label">Current Leave Year</div>
                    <div class="info-value"><?php echo $leave_year['year_label']; ?></div>
                    <div style="font-size: 12px; color: #718096; margin-top: 5px;">
                        <?php echo $leave_year['start_date']; ?> to <?php echo $leave_year['end_date']; ?>
                    </div>
                </div>
                <div class="info-card">
                    <i class="fas fa-history"></i>
                    <div class="info-label">Previous Leave Year</div>
                    <div class="info-value"><?php echo $prev_year['year_label']; ?></div>
                    <div style="font-size: 12px; color: #718096; margin-top: 5px;">
                        <?php echo $prev_year['start_date']; ?> to <?php echo $prev_year['end_date']; ?>
                    </div>
                </div>
                <div class="info-card">
                    <i class="fas fa-hourglass-half"></i>
                    <div class="info-label">Next Reset Date</div>
                    <div class="info-value"><?php echo $stats['reset_date']; ?></div>
                    <div style="font-size: 12px; color: #718096; margin-top: 5px;">
                        in <?php echo $stats['days_until_reset']; ?> days
                    </div>
                </div>
            </div>
            
            <!-- Warning Box -->
            <div class="warning-box">
                <h4>
                    <i class="fas fa-exclamation-triangle"></i>
                    ⚠️ Important Information - Read Carefully
                </h4>
                <ul>
                    <li><strong>Leave Year Cycle:</strong> March 16 to March 15</li>
                    <li><strong>Automatic Reset:</strong> Occurs automatically on March 16 each year</li>
                    <li><strong>Carry Forward:</strong> Unused leave days (max 6 days per leave type) will be carried to next year</li>
                    <li><strong>Archive:</strong> Previous year's leave balances will be archived</li>
                    <li><strong>This Action:</strong> Only use this if the automatic reset failed or for testing purposes</li>
                    <li><strong>Cannot Undo:</strong> This action cannot be undone</li>
                </ul>
            </div>
            
            <!-- Reset Form -->
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">
                        <i class="fas fa-exclamation-circle"></i> Manual Leave Year Reset
                    </h3>
                </div>
                <div style="padding: 20px;">
                    <form method="POST" action="" onsubmit="return confirm('⚠️ ARE YOU ABSOLUTELY SURE?\n\nThis will:\n- Archive current leave balances\n- Calculate carry forward (max 6 days)\n- Reset all users to new leave year\n\nThis action CANNOT be undone!\n\nDo you want to continue?')">
                        
                        <div style="margin-bottom: 20px;">
                            <label style="display: block; margin-bottom: 10px; font-weight: 600; color: #4a5568;">
                                Type <span style="background: #e53e3e; color: white; padding: 3px 8px; border-radius: 4px;">RESET LEAVE YEAR</span> to confirm:
                            </label>
                            <input type="text" 
                                   name="confirm_reset" 
                                   class="confirm-input" 
                                   placeholder="RESET LEAVE YEAR" 
                                   required
                                   pattern="RESET LEAVE YEAR"
                                   title="Please type exactly: RESET LEAVE YEAR">
                            <small style="color: #718096; display: block; margin-top: 5px;">
                                <i class="fas fa-info-circle"></i> 
                                You must type exactly "RESET LEAVE YEAR" in uppercase
                            </small>
                        </div>
                        
                        <div style="display: flex; align-items: center;">
                            <button type="submit" name="reset_leave_year" class="btn-reset">
                                <i class="fas fa-exclamation-triangle"></i> Reset Leave Year Now
                            </button>
                            <a href="dashboard.php" class="btn-cancel">
                                <i class="fas fa-times"></i> Cancel
                            </a>
                        </div>
                    </form>
                </div>
            </div>
            
            <!-- Reset History -->
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">
                        <i class="fas fa-history"></i> Reset History
                    </h3>
                </div>
                <div class="table-container">
                    <table>
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Leave Year</th>
                                <th>Description</th>
                                <th>Performed By</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            $log_stmt = $conn->prepare("
                                SELECT * FROM system_logs 
                                WHERE event_type = 'leave_year_reset' 
                                ORDER BY created_at DESC 
                                LIMIT 10
                            ");
                            $log_stmt->execute();
                            $logs = $log_stmt->get_result();
                            
                            if ($logs->num_rows > 0):
                                while ($log = $logs->fetch_assoc()):
                                    // Get user name
                                    $user_stmt = $conn->prepare("SELECT full_name FROM users WHERE id = ?");
                                    $user_stmt->bind_param("i", $log['user_id']);
                                    $user_stmt->execute();
                                    $user_result = $user_stmt->get_result();
                                    $user_name = $user_result->fetch_assoc()['full_name'] ?? 'System';
                                    $user_stmt->close();
                            ?>
                            <tr>
                                <td><?php echo date('Y-m-d H:i', strtotime($log['created_at'])); ?></td>
                                <td>
                                    <?php 
                                    preg_match('/\d{4}-\d{4}/', $log['description'], $matches);
                                    echo $matches[0] ?? '-';
                                    ?>
                                </td>
                                <td><?php echo $log['description']; ?></td>
                                <td><?php echo $user_name; ?></td>
                            </tr>
                            <?php 
                                endwhile;
                            else:
                            ?>
                            <tr>
                                <td colspan="4" style="text-align: center; padding: 20px; color: #718096;">
                                    No reset history found
                                </td>
                            </tr>
                            <?php endif; ?>
                            <?php $log_stmt->close(); ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
    
    <script src="../assets/js/app.js"></script>
</body>
</html>