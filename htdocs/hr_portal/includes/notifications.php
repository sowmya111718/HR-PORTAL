<?php
require_once 'config/db.php';
require_once 'includes/notification_functions.php';
require_once 'includes/icon_functions.php';

if (!isLoggedIn()) {
    header('Location: auth/login.php');
    exit();
}

$user_id = $_SESSION['user_id'];

// Mark all as read when viewing the page
markAllNotificationsRead($conn, $user_id);

// Get all notifications
$stmt = $conn->prepare("
    SELECT * FROM notifications 
    WHERE user_id = ? 
    ORDER BY created_at DESC
");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$notifications = $stmt->get_result();
$stmt->close();

$page_title = "Notifications - MAKSIM HR";
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $page_title; ?></title>
    <?php include 'includes/head.php'; ?>
    <style>
        .notification-item {
            padding: 20px;
            border-radius: 10px;
            margin-bottom: 15px;
            background: white;
            box-shadow: 0 2px 8px rgba(0,0,0,0.05);
            display: flex;
            gap: 15px;
            transition: transform 0.2s;
        }
        .notification-item:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0,0,0,0.1);
        }
        .notification-icon {
            width: 50px;
            height: 50px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 24px;
        }
        .notification-icon.leave-approved { background: #c6f6d5; color: #276749; }
        .notification-icon.leave-rejected { background: #fed7d7; color: #c53030; }
        .notification-icon.leave-deleted { background: #fed7e2; color: #b83280; }
        .notification-icon.permission-approved { background: #c6f6d5; color: #276749; }
        .notification-icon.permission-rejected { background: #fed7d7; color: #c53030; }
        .notification-icon.lop-approved { background: #fed7d7; color: #c53030; }
        .notification-icon.lop-rejected { background: #fed7d7; color: #c53030; }
        .notification-content {
            flex: 1;
        }
        .notification-title {
            font-size: 16px;
            font-weight: 600;
            color: #2d3748;
            margin-bottom: 5px;
        }
        .notification-message {
            color: #718096;
            margin-bottom: 10px;
        }
        .notification-time {
            color: #a0aec0;
            font-size: 12px;
        }
        .no-notifications {
            text-align: center;
            padding: 60px;
            color: #718096;
        }
        .back-link {
            margin-bottom: 20px;
            display: inline-block;
        }
        .back-link a {
            color: #006400;
            text-decoration: none;
            display: flex;
            align-items: center;
            gap: 5px;
        }
    </style>
</head>
<body>
    <?php include 'includes/header.php'; ?>
    
    <div class="app-main">
        <?php include 'includes/sidebar.php'; ?>
        
        <div class="main-content">
            <div class="back-link">
                <a href="dashboard.php">
                    <i class="icon-arrow-left"></i> Back to Dashboard
                </a>
            </div>
            
            <h2 class="page-title"><i class="icon-bell"></i> All Notifications</h2>
            
            <?php if ($notifications && $notifications->num_rows > 0): ?>
                <?php while ($notif = $notifications->fetch_assoc()): 
                    $icon_class = '';
                    if (strpos($notif['type'], 'leave_approved') !== false) $icon_class = 'leave-approved';
                    elseif (strpos($notif['type'], 'leave_rejected') !== false) $icon_class = 'leave-rejected';
                    elseif (strpos($notif['type'], 'leave_deleted') !== false) $icon_class = 'leave-deleted';
                    elseif (strpos($notif['type'], 'permission_approved') !== false) $icon_class = 'permission-approved';
                    elseif (strpos($notif['type'], 'permission_rejected') !== false) $icon_class = 'permission-rejected';
                    elseif (strpos($notif['type'], 'lop_approved') !== false) $icon_class = 'lop-approved';
                    elseif (strpos($notif['type'], 'lop_rejected') !== false) $icon_class = 'lop-rejected';
                ?>
                <div class="notification-item">
                    <div class="notification-icon <?php echo $icon_class; ?>">
                        <?php
                        if (strpos($notif['type'], 'approved') !== false) echo '✓';
                        elseif (strpos($notif['type'], 'rejected') !== false) echo '✗';
                        elseif (strpos($notif['type'], 'deleted') !== false) echo '🗑️';
                        else echo 'ℹ️';
                        ?>
                    </div>
                    <div class="notification-content">
                        <div class="notification-title"><?php echo htmlspecialchars($notif['title']); ?></div>
                        <div class="notification-message"><?php echo htmlspecialchars($notif['message']); ?></div>
                        <div class="notification-time"><?php echo date('F j, Y g:i A', strtotime($notif['created_at'])); ?></div>
                    </div>
                </div>
                <?php endwhile; ?>
            <?php else: ?>
                <div class="no-notifications">
                    <i class="icon-bell" style="font-size: 64px; color: #cbd5e0; margin-bottom: 20px; display: block;"></i>
                    <h3 style="color: #2d3748; margin-bottom: 10px;">No notifications</h3>
                    <p>You're all caught up!</p>
                </div>
            <?php endif; ?>
        </div>
    </div>
    
    <script src="assets/js/app.js"></script>
</body>
</html>