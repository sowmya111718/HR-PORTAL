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

// Get all notifications with pagination
$page = isset($_GET['page']) ? intval($_GET['page']) : 1;
$limit = 20;
$offset = ($page - 1) * $limit;

// Get total count
$count_stmt = $conn->prepare("SELECT COUNT(*) as total FROM notifications WHERE user_id = ?");
$count_stmt->bind_param("i", $user_id);
$count_stmt->execute();
$count_result = $count_stmt->get_result();
$total_rows = $count_result->fetch_assoc()['total'];
$total_pages = ceil($total_rows / $limit);
$count_stmt->close();

// Get paginated notifications
$stmt = $conn->prepare("
    SELECT * FROM notifications 
    WHERE user_id = ? 
    ORDER BY created_at DESC
    LIMIT ? OFFSET ?
");
$stmt->bind_param("iii", $user_id, $limit, $offset);
$stmt->execute();
$notifications = $stmt->get_result();
$stmt->close();

$page_title = "Notifications - MAKSIM HR";

// Determine base path
$base_path = '';
$current_file = $_SERVER['SCRIPT_NAME'];
if (strpos($current_file, '/admin/') !== false || 
    strpos($current_file, '/auth/') !== false || 
    strpos($current_file, '/hr/') !== false || 
    strpos($current_file, '/leaves/') !== false || 
    strpos($current_file, '/permissions/') !== false || 
    strpos($current_file, '/timesheet/') !== false || 
    strpos($current_file, '/users/') !== false) {
    $base_path = '../';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $page_title; ?></title>
    <?php include 'includes/head.php'; ?>
    <style>
        .notifications-container {
            max-width: 900px;
            margin: 0 auto;
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
            font-weight: 600;
        }
        
        .back-link a:hover {
            text-decoration: underline;
        }
        
        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 30px;
            flex-wrap: wrap;
            gap: 15px;
        }
        
        .page-header h2 {
            color: #006400;
            margin: 0;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .mark-all-btn {
            background: #4299e1;
            color: white;
            border: none;
            padding: 10px 20px;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 8px;
            transition: background 0.2s;
        }
        
        .mark-all-btn:hover {
            background: #3182ce;
        }
        
        .notification-item {
            padding: 20px;
            border-radius: 10px;
            margin-bottom: 15px;
            background: white;
            box-shadow: 0 2px 8px rgba(0,0,0,0.05);
            display: flex;
            gap: 15px;
            transition: transform 0.2s, box-shadow 0.2s;
            border-left: 4px solid transparent;
        }
        
        .notification-item:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0,0,0,0.1);
        }
        
        .notification-item.unread {
            background: #ebf8ff;
            border-left-color: #006400;
        }
        
        .notification-icon {
            width: 50px;
            height: 50px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 24px;
            flex-shrink: 0;
        }
        
        .notification-icon.leave-approved,
        .notification-icon.leave-new { background: #c6f6d5; color: #276749; }
        .notification-icon.leave-rejected { background: #fed7d7; color: #c53030; }
        .notification-icon.leave-deleted { background: #fed7e2; color: #b83280; }
        .notification-icon.permission-approved,
        .notification-icon.permission-new { background: #c6f6d5; color: #276749; }
        .notification-icon.permission-rejected { background: #fed7d7; color: #c53030; }
        .notification-icon.lop-approved { background: #fed7d7; color: #c53030; }
        .notification-icon.lop-rejected { background: #fed7d7; color: #c53030; }
        .notification-icon.timesheet-new { background: #bee3f8; color: #2c5282; }
        
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
            margin-bottom: 8px;
            line-height: 1.5;
        }
        
        .notification-time {
            color: #a0aec0;
            font-size: 12px;
            display: flex;
            align-items: center;
            gap: 5px;
        }
        
        .notification-actions {
            display: flex;
            gap: 10px;
            margin-top: 10px;
        }
        
        .btn-mark-read {
            background: #4299e1;
            color: white;
            border: none;
            padding: 5px 12px;
            border-radius: 6px;
            font-size: 12px;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 5px;
        }
        
        .btn-mark-read:hover {
            background: #3182ce;
        }
        
        .no-notifications {
            text-align: center;
            padding: 80px 20px;
            color: #718096;
            background: white;
            border-radius: 10px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.05);
        }
        
        .no-notifications i {
            font-size: 64px;
            color: #cbd5e0;
            margin-bottom: 20px;
            display: block;
        }
        
        .no-notifications h3 {
            color: #2d3748;
            margin-bottom: 10px;
            font-size: 24px;
        }
        
        .no-notifications p {
            color: #718096;
            font-size: 16px;
        }
        
        /* Pagination */
        .pagination {
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 8px;
            margin-top: 30px;
            flex-wrap: wrap;
        }
        
        .pagination a, .pagination span {
            padding: 8px 14px;
            border: 1px solid #e2e8f0;
            border-radius: 6px;
            text-decoration: none;
            color: #4a5568;
            transition: all 0.2s;
            background: white;
        }
        
        .pagination a:hover {
            background: #f7fafc;
            border-color: #cbd5e0;
        }
        
        .pagination .active {
            background: #006400;
            color: white;
            border-color: #006400;
        }
        
        .pagination .disabled {
            opacity: 0.5;
            cursor: not-allowed;
            pointer-events: none;
        }
        
        .pagination-info {
            margin-left: 15px;
            color: #718096;
            font-size: 14px;
        }
        
        @media (max-width: 768px) {
            .notification-item {
                flex-direction: column;
            }
            
            .notification-icon {
                align-self: flex-start;
            }
        }
    </style>
</head>
<body>
    <?php include 'includes/header.php'; ?>
    
    <div class="app-main">
        <?php include 'includes/sidebar.php'; ?>
        
        <div class="main-content">
            <div class="notifications-container">
                <div class="back-link">
                    <a href="dashboard.php">
                        <i class="icon-arrow-left"></i> Back to Dashboard
                    </a>
                </div>
                
                <div class="page-header">
                    <h2>
                        <i class="icon-bell"></i> All Notifications
                        <?php if ($total_rows > 0): ?>
                            <span style="font-size: 14px; color: #718096; margin-left: 10px;">
                                (<?php echo $total_rows; ?> total)
                            </span>
                        <?php endif; ?>
                    </h2>
                    
                    <?php if ($total_rows > 0): ?>
                        <button class="mark-all-btn" onclick="markAllRead()">
                            <i class="icon-check"></i> Mark All as Read
                        </button>
                    <?php endif; ?>
                </div>
                
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
                        elseif (strpos($notif['type'], 'new_leave') !== false) $icon_class = 'leave-new';
                        elseif (strpos($notif['type'], 'new_permission') !== false) $icon_class = 'permission-new';
                        elseif (strpos($notif['type'], 'new_timesheet') !== false) $icon_class = 'timesheet-new';
                        
                        $icon = 'ℹ️';
                        if (strpos($notif['type'], 'approved') !== false) $icon = '✓';
                        elseif (strpos($notif['type'], 'rejected') !== false) $icon = '✗';
                        elseif (strpos($notif['type'], 'deleted') !== false) $icon = '🗑️';
                        elseif (strpos($notif['type'], 'new_leave') !== false) $icon = '📝';
                        elseif (strpos($notif['type'], 'new_permission') !== false) $icon = '⏰';
                        elseif (strpos($notif['type'], 'new_timesheet') !== false) $icon = '📋';
                    ?>
                    <div class="notification-item <?php echo $notif['is_read'] ? '' : 'unread'; ?>" data-id="<?php echo $notif['id']; ?>">
                        <div class="notification-icon <?php echo $icon_class; ?>">
                            <?php echo $icon; ?>
                        </div>
                        <div class="notification-content">
                            <div class="notification-title"><?php echo htmlspecialchars($notif['title']); ?></div>
                            <div class="notification-message"><?php echo htmlspecialchars($notif['message']); ?></div>
                            <div class="notification-time">
                                <i class="icon-clock"></i>
                                <?php echo date('F j, Y g:i A', strtotime($notif['created_at'])); ?>
                            </div>
                            <?php if (!$notif['is_read']): ?>
                                <div class="notification-actions">
                                    <button class="btn-mark-read" onclick="markSingleRead(<?php echo $notif['id']; ?>, this)">
                                        <i class="icon-check"></i> Mark as Read
                                    </button>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endwhile; ?>
                    
                    <!-- Pagination -->
                    <?php if ($total_pages > 1): ?>
                    <div class="pagination">
                        <?php if ($page > 1): ?>
                            <a href="?page=<?php echo $page - 1; ?>">
                                <i class="icon-arrow-left"></i> Previous
                            </a>
                        <?php else: ?>
                            <span class="disabled">
                                <i class="icon-arrow-left"></i> Previous
                            </span>
                        <?php endif; ?>
                        
                        <?php
                        $start_page = max(1, $page - 2);
                        $end_page = min($total_pages, $page + 2);
                        
                        for ($i = $start_page; $i <= $end_page; $i++):
                        ?>
                            <?php if ($i == $page): ?>
                                <span class="active"><?php echo $i; ?></span>
                            <?php else: ?>
                                <a href="?page=<?php echo $i; ?>"><?php echo $i; ?></a>
                            <?php endif; ?>
                        <?php endfor; ?>
                        
                        <?php if ($page < $total_pages): ?>
                            <a href="?page=<?php echo $page + 1; ?>">
                                Next <i class="icon-arrow-right"></i>
                            </a>
                        <?php else: ?>
                            <span class="disabled">
                                Next <i class="icon-arrow-right"></i>
                            </span>
                        <?php endif; ?>
                        
                        <span class="pagination-info">
                            Page <?php echo $page; ?> of <?php echo $total_pages; ?>
                        </span>
                    </div>
                    <?php endif; ?>
                    
                <?php else: ?>
                    <div class="no-notifications">
                        <i class="icon-bell"></i>
                        <h3>No notifications</h3>
                        <p>You're all caught up! Check back later for updates.</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <script>
    function markAllRead() {
        if (!confirm('Mark all notifications as read?')) return;
        
        fetch('<?php echo $base_path; ?>ajax/mark_all_notifications_read.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            }
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                document.querySelectorAll('.notification-item').forEach(item => {
                    item.classList.remove('unread');
                });
                document.querySelectorAll('.notification-actions').forEach(action => {
                    action.style.display = 'none';
                });
                updateHeaderBadge();
                showToast('All notifications marked as read', 'success');
            }
        })
        .catch(error => console.error('Error:', error));
    }
    
    function markSingleRead(id, button) {
        fetch('<?php echo $base_path; ?>ajax/mark_notification_read.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify({ notification_id: id })
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                const item = document.querySelector(`.notification-item[data-id="${id}"]`);
                if (item) {
                    item.classList.remove('unread');
                    const actions = item.querySelector('.notification-actions');
                    if (actions) actions.style.display = 'none';
                }
                updateHeaderBadge();
                showToast('Notification marked as read', 'success');
            }
        })
        .catch(error => console.error('Error:', error));
    }
    
    function updateHeaderBadge() {
        fetch('<?php echo $base_path; ?>ajax/get_unread_count.php')
        .then(response => response.json())
        .then(data => {
            const badge = document.getElementById('notificationBadge');
            if (badge) {
                if (data.count > 0) {
                    badge.textContent = data.count;
                    badge.style.display = 'block';
                } else {
                    badge.style.display = 'none';
                }
            }
        })
        .catch(error => console.error('Error:', error));
    }
    
    function showToast(message, type = 'success') {
        const toast = document.createElement('div');
        toast.className = `notification-toast`;
        toast.style.cssText = `
            position: fixed;
            top: 20px;
            right: 20px;
            background: white;
            padding: 15px 20px;
            border-radius: 8px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
            z-index: 9999;
            animation: slideIn 0.3s ease;
            border-left: 4px solid ${type === 'success' ? '#48bb78' : '#4299e1'};
        `;
        toast.innerHTML = `
            <div style="display: flex; align-items: center; gap: 10px;">
                <i class="icon-${type === 'success' ? 'check' : 'info'}" style="color: ${type === 'success' ? '#48bb78' : '#4299e1'};"></i>
                <span>${message}</span>
            </div>
        `;
        document.body.appendChild(toast);
        
        setTimeout(() => {
            toast.style.animation = 'slideOut 0.3s ease';
            setTimeout(() => toast.remove(), 300);
        }, 3000);
    }
    
    // Add animation styles
    const style = document.createElement('style');
    style.textContent = `
        @keyframes slideIn {
            from { transform: translateX(100%); opacity: 0; }
            to { transform: translateX(0); opacity: 1; }
        }
        @keyframes slideOut {
            from { transform: translateX(0); opacity: 1; }
            to { transform: translateX(100%); opacity: 0; }
        }
    `;
    document.head.appendChild(style);
    </script>
    
    <script src="<?php echo $base_path; ?>assets/js/app.js"></script>
</body>
</html>