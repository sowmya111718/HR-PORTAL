<?php
require_once '../config/db.php';
require_once '../includes/icon_functions.php';

if (!isLoggedIn()) {
    header('Location: ../auth/login.php');
    exit();
}

$permission_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

// Get permission details
$stmt = $conn->prepare("
    SELECT p.*, u.full_name, u.username, u.department,
           a.full_name as approved_by_name, r.full_name as rejected_by_name
    FROM permissions p
    JOIN users u ON p.user_id = u.id
    LEFT JOIN users a ON p.approved_by = a.id
    LEFT JOIN users r ON p.rejected_by = r.id
    WHERE p.id = ?
");

// Check permissions
$user_id = $_SESSION['user_id'];
$role = $_SESSION['role'];

if ($role === 'employee') {
    $stmt = $conn->prepare("
        SELECT p.*, u.full_name, u.username, u.department,
               a.full_name as approved_by_name, r.full_name as rejected_by_name
        FROM permissions p
        JOIN users u ON p.user_id = u.id
        LEFT JOIN users a ON p.approved_by = a.id
        LEFT JOIN users r ON p.rejected_by = r.id
        WHERE p.id = ? AND p.user_id = ?
    ");
    $stmt->bind_param("ii", $permission_id, $user_id);
} else {
    $stmt->bind_param("i", $permission_id);
}

$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    echo '<div class="alert alert-error"><i class="icon-error"></i> Permission not found or access denied</div>';
    exit();
}

$permission = $result->fetch_assoc();
$stmt->close();

$page_title = "Permission Details - MAKSIM HR";
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Permission Details - MAKSIM HR</title>
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
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
                <h2 class="page-title"><i class="icon-clock"></i> Permission Details</h2>
                <a href="javascript:history.back()" class="btn-small btn-view">
                    <i class="icon-arrow-left"></i> Back
                </a>
            </div>
            
            <div class="details-container">
                <!-- Employee Details -->
                <div class="detail-card">
                    <h3 style="margin-bottom: 20px; color: #4a5568;"><i class="icon-user"></i> Employee Details</h3>
                    <div class="detail-row">
                        <span class="detail-label">Employee Name:</span>
                        <span class="detail-value"><?php echo $permission['full_name']; ?></span>
                    </div>
                    <div class="detail-row">
                        <span class="detail-label">Username:</span>
                        <span class="detail-value"><?php echo $permission['username']; ?></span>
                    </div>
                    <div class="detail-row">
                        <span class="detail-label">Department:</span>
                        <span class="detail-value"><?php echo $permission['department'] ?: 'N/A'; ?></span>
                    </div>
                </div>

                <!-- Permission Details -->
                <div class="detail-card">
                    <h3 style="margin-bottom: 20px; color: #4a5568;"><i class="icon-clock"></i> Permission Details</h3>
                    <div class="detail-row">
                        <span class="detail-label">Permission Date:</span>
                        <span class="detail-value"><?php echo date('F j, Y', strtotime($permission['permission_date'])); ?></span>
                    </div>
                    <div class="detail-row">
                        <span class="detail-label">Duration:</span>
                        <span class="detail-value">
                            <?php 
                            if ($permission['duration'] == 1) {
                                echo "1 hour";
                            } else if ($permission['duration'] < 1) {
                                echo ($permission['duration'] * 60) . " minutes";
                            } else if ($permission['duration'] == 8) {
                                echo "Full Day (8 hours)";
                            } else {
                                echo $permission['duration'] . " hours";
                            }
                            ?>
                        </span>
                    </div>
                    <div class="detail-row">
                        <span class="detail-label">Applied Date:</span>
                        <span class="detail-value"><?php echo date('F j, Y g:i A', strtotime($permission['applied_date'])); ?></span>
                    </div>
                </div>
                
                <!-- Status -->
                <div class="detail-card">
                    <h3 style="margin-bottom: 20px; color: #4a5568;"><i class="icon-info"></i> Status</h3>
                    <div class="detail-row">
                        <span class="detail-label">Status:</span>
                        <span class="detail-value">
                            <span class="status-badge status-<?php echo strtolower($permission['status']); ?>">
                                <?php echo $permission['status']; ?>
                            </span>
                        </span>
                    </div>
                    
                    <?php if ($permission['approved_by_name']): ?>
                    <div class="detail-row">
                        <span class="detail-label">Approved By:</span>
                        <span class="detail-value"><?php echo $permission['approved_by_name']; ?></span>
                    </div>
                    <div class="detail-row">
                        <span class="detail-label">Approved Date:</span>
                        <span class="detail-value"><?php echo $permission['approved_date']; ?></span>
                    </div>
                    <?php endif; ?>
                    
                    <?php if ($permission['rejected_by_name']): ?>
                    <div class="detail-row">
                        <span class="detail-label">Rejected By:</span>
                        <span class="detail-value"><?php echo $permission['rejected_by_name']; ?></span>
                    </div>
                    <div class="detail-row">
                        <span class="detail-label">Rejected Date:</span>
                        <span class="detail-value"><?php echo $permission['rejected_date']; ?></span>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Reason -->
            <div class="card" style="margin-top: 20px;">
                <div class="card-header">
                    <h3 class="card-title"><i class="icon-comment"></i> Reason</h3>
                </div>
                <div style="padding: 20px;">
                    <p style="color: #4a5568; line-height: 1.6;"><?php echo nl2br(htmlspecialchars($permission['reason'])); ?></p>
                </div>
            </div>
        </div>
    </div>
    
    <script src="../assets/js/app.js"></script>
</body>
</html>