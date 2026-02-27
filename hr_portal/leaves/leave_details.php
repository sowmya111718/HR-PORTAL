<?php
require_once '../config/db.php';
require_once '../includes/leave_functions.php';

if (!isLoggedIn()) {
    header('Location: ../auth/login.php');
    exit();
}

$leave_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

// Get leave details
$stmt = $conn->prepare("
    SELECT l.*, u.full_name, u.username, u.department,
           a.full_name as approved_by_name, r.full_name as rejected_by_name
    FROM leaves l
    JOIN users u ON l.user_id = u.id
    LEFT JOIN users a ON l.approved_by = a.id
    LEFT JOIN users r ON l.rejected_by = r.id
    WHERE l.id = ?
");

// Check permissions
$user_id = $_SESSION['user_id'];
$role = $_SESSION['role'];

if ($role === 'employee') {
    // Employees can only view their own leaves
    $stmt = $conn->prepare("
        SELECT l.*, u.full_name, u.username, u.department,
               a.full_name as approved_by_name, r.full_name as rejected_by_name
        FROM leaves l
        JOIN users u ON l.user_id = u.id
        LEFT JOIN users a ON l.approved_by = a.id
        LEFT JOIN users r ON l.rejected_by = r.id
        WHERE l.id = ? AND l.user_id = ?
    ");
    $stmt->bind_param("ii", $leave_id, $user_id);
} else {
    // HR/Admin/PM can view any leave
    $stmt->bind_param("i", $leave_id);
}

$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    echo '<div class="alert alert-error">Leave not found or access denied</div>';
    exit();
}

$leave = $result->fetch_assoc();
$stmt->close();

// Get leave year for this leave
$leave_year = getLeaveYearForDate($leave['from_date']);
$current_leave_year = getCurrentLeaveYear();

$page_title = "Leave Details - MAKSIM HR";
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <?php include '../includes/head.php'; ?>
    <style>
        .details-container {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 20px;
            margin-top: 20px;
        }
        
        .detail-card {
            background: white;
            border-radius: 10px;
            padding: 20px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
        }
        
        .detail-row {
            display: flex;
            justify-content: space-between;
            margin-bottom: 15px;
            padding-bottom: 15px;
            border-bottom: 1px solid #e2e8f0;
        }
        
        .detail-label {
            font-weight: 600;
            color: #4a5568;
        }
        
        .detail-value {
            color: #2d3748;
        }
        
        .leave-year-badge {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 8px 15px;
            border-radius: 20px;
            display: inline-block;
            margin-bottom: 15px;
        }
    </style>
</head>
<body>
    <?php include '../includes/header.php'; ?>
    
    <div class="app-main">
        <?php include '../includes/sidebar.php'; ?>
        
        <div class="main-content">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
                <h2 class="page-title"><i class="icon-leave"></i> Leave Details</h2>
                <a href="javascript:history.back()" class="btn-small btn-view">
                    <i class="icon-arrow-left"></i> Back
                </a>
            </div>
            
            <!-- ADD THIS: Leave Year Information -->
            <div class="leave-year-badge">
                <i class="icon-calendar"></i> 
                Leave Year: <?php echo $leave_year['year_label']; ?> (<?php echo $leave_year['start_date']; ?> to <?php echo $leave_year['end_date']; ?>)
                <?php if ($leave_year['year_label'] === $current_leave_year['year_label']): ?>
                    <span style="background: rgba(255,255,255,0.2); padding: 3px 8px; border-radius: 12px; margin-left: 10px; font-size: 11px;">
                        Current Year
                    </span>
                <?php else: ?>
                    <span style="background: rgba(255,255,255,0.2); padding: 3px 8px; border-radius: 12px; margin-left: 10px; font-size: 11px;">
                        Previous Year
                    </span>
                <?php endif; ?>
            </div>
            
            <div class="details-container">
                <!-- Employee Details -->
                <div class="detail-card">
                    <h3 style="margin-bottom: 20px; color: #4a5568;"><i class="icon-user"></i> Employee Details</h3>
                    <div class="detail-row">
                        <span class="detail-label">Employee Name:</span>
                        <span class="detail-value"><?php echo $leave['full_name']; ?></span>
                    </div>
                    <div class="detail-row">
                        <span class="detail-label">Username:</span>
                        <span class="detail-value"><?php echo $leave['username']; ?></span>
                    </div>
                    <div class="detail-row">
                        <span class="detail-label">Department:</span>
                        <span class="detail-value"><?php echo $leave['department'] ?: 'N/A'; ?></span>
                    </div>
                </div>

                <!-- Leave Details -->
                <div class="detail-card">
                    <h3 style="margin-bottom: 20px; color: #4a5568;"><i class="icon-leave"></i> Leave Details</h3>
                    <div class="detail-row">
                        <span class="detail-label">Leave Type:</span>
                        <span class="detail-value"><?php echo $leave['leave_type']; ?></span>
                    </div>
                    <div class="detail-row">
                        <span class="detail-label">From Date:</span>
                        <span class="detail-value"><?php echo $leave['from_date']; ?></span>
                    </div>
                    <div class="detail-row">
                        <span class="detail-label">To Date:</span>
                        <span class="detail-value"><?php echo $leave['to_date']; ?></span>
                    </div>
                    <div class="detail-row">
                        <span class="detail-label">Days:</span>
                        <span class="detail-value"><?php echo $leave['days']; ?></span>
                    </div>
                    <div class="detail-row">
                        <span class="detail-label">Day Type:</span>
                        <span class="detail-value"><?php echo $leave['day_type'] === 'half' ? 'Half Day' : 'Full Day'; ?></span>
                    </div>
                    <div class="detail-row">
                        <span class="detail-label">Working Days:</span>
                        <span class="detail-value"><?php echo $leave['days']; ?> days</span>
                    </div>
                </div>

                <!-- Status & Dates -->
                <div class="detail-card">
                    <h3 style="margin-bottom: 20px; color: #4a5568;"><i class="icon-info"></i> Status & Dates</h3>
                    <div class="detail-row">
                        <span class="detail-label">Status:</span>
                        <span class="detail-value">
                            <span class="status-badge status-<?php echo strtolower($leave['status']); ?>">
                                <?php echo $leave['status']; ?>
                            </span>
                        </span>
                    </div>
                    <div class="detail-row">
                        <span class="detail-label">Applied Date:</span>
                        <span class="detail-value"><?php echo date('Y-m-d H:i', strtotime($leave['applied_date'])); ?></span>
                    </div>
                    
                    <?php if ($leave['approved_by_name']): ?>
                    <div class="detail-row">
                        <span class="detail-label">Approved By:</span>
                        <span class="detail-value"><?php echo $leave['approved_by_name']; ?></span>
                    </div>
                    <div class="detail-row">
                        <span class="detail-label">Approved Date:</span>
                        <span class="detail-value"><?php echo $leave['approved_date']; ?></span>
                    </div>
                    <?php endif; ?>
                    
                    <?php if ($leave['rejected_by_name']): ?>
                    <div class="detail-row">
                        <span class="detail-label">Rejected By:</span>
                        <span class="detail-value"><?php echo $leave['rejected_by_name']; ?></span>
                    </div>
                    <div class="detail-row">
                        <span class="detail-label">Rejected Date:</span>
                        <span class="detail-value"><?php echo $leave['rejected_date']; ?></span>
                    </div>
                    <?php endif; ?>
                    
                    <!-- ADD THIS: Leave Year -->
                    <div class="detail-row">
                        <span class="detail-label">Leave Year:</span>
                        <span class="detail-value">
                            <strong><?php echo $leave_year['year_label']; ?></strong>
                            <span style="display: block; font-size: 11px; color: #718096;">
                                <?php echo $leave_year['start_date']; ?> to <?php echo $leave_year['end_date']; ?>
                            </span>
                        </span>
                    </div>
                </div>
            </div>

            <!-- Reason -->
            <div class="card" style="margin-top: 20px;">
                <div class="card-header">
                    <h3 class="card-title"><i class="icon-comment"></i> Reason</h3>
                </div>
                <div style="padding: 20px;">
                    <p style="color: #4a5568; line-height: 1.6;"><?php echo nl2br(htmlspecialchars($leave['reason'])); ?></p>
                </div>
            </div>
        </div>
    </div>
    
    <script src="../assets/js/app.js"></script>
</body>
</html>