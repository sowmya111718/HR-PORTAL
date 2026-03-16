<?php
// File: includes/header.php
require_once 'quotes.php';

if (!isset($no_header)):

// Determine the base path
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

// Get a random quote
$random_quote = getRandomQuote();

// Get unread notifications count only
$unread_count = 0;
if (isset($_SESSION['user_id'])) {
    // Check if we have a cached count in session
    if (isset($_SESSION['notification_count']) && isset($_SESSION['notification_count_time']) && 
        (time() - $_SESSION['notification_count_time'] < 60)) {
        $unread_count = $_SESSION['notification_count'];
    } else {
        require_once dirname(__FILE__) . '/notification_functions.php';
        global $conn;
        $user_id = $_SESSION['user_id'];
        $unread_count = getUnreadNotificationCount($conn, $user_id);
        $_SESSION['notification_count'] = $unread_count;
        $_SESSION['notification_count_time'] = time();
    }
}
?>
<!-- Header -->
<div class="app-header">
    <div style="display: flex; align-items: center; flex-wrap: wrap; gap: 10px;">
        <img src="<?php echo $base_path; ?>assets/images/maksim_infotech_logo.png" alt="MAKSIM Infotech" height="40" style="margin-right: 10px;">
        <h1 style="margin: 0; font-size: 24px;">MAKSIM</h1>
        <span style="font-size: 16px; color: #FF9933; font-weight: bold; font-style: italic; margin-left: 15px; padding-left: 15px; border-left: 1px solid rgba(255,255,255,0.3); max-width: 400px;">
            “<?php echo $random_quote; ?>”
        </span>
    </div>
    <div class="user-info">
        <!-- Notification Bell with Badge -->
        <div class="notification-bell" id="notificationBell">
            <i class="icon-bell"></i>
            <span class="notification-badge" id="notificationBadge" style="<?php echo $unread_count > 0 ? '' : 'display: none;'; ?>"><?php echo $unread_count; ?></span>
        </div>
        
        <div class="user-label <?php echo $_SESSION['role'] === 'hr' ? 'hr' : ($_SESSION['role'] === 'pm' ? 'pm' : ''); ?>">
            <i class="icon-user"></i> <?php echo $_SESSION['full_name']; ?>
            <span class="user-role-badge role-<?php echo $_SESSION['role']; ?>">
                <?php echo strtoupper($_SESSION['role']); ?>
            </span>
        </div>
        <a href="<?php echo $base_path; ?>auth/logout.php" class="logout-btn">
            <i class="icon-logout"></i> Logout
        </a>
    </div>
</div>

<!-- Notification Panel -->
<div class="notification-panel" id="notificationPanel">
    <div class="notification-header">
        <h4><i class="icon-bell"></i> Notifications</h4>
        <button class="mark-all-read" id="markAllReadBtn" onclick="markAllRead(event)">Mark all as read</button>
    </div>
    <div class="notification-list" id="notificationList">
        <div class="loading-notifications">
            <div class="spinner"></div>
            <div>Loading notifications...</div>
        </div>
    </div>
    <div class="notification-footer">
        <a href="<?php echo $base_path; ?>notifications.php">View all notifications</a>
    </div>
</div>

<!-- Notification Toast Container -->
<div id="notificationToastContainer" class="notification-toast-container"></div>

<!-- Notification Sound (optional) -->
<audio id="notificationSound" preload="auto" style="display:none;">
    <source src="<?php echo $base_path; ?>assets/sounds/notification.mp3" type="audio/mpeg">
</audio>

<script>
// Global variables
let lastNotificationId = 0;
let notificationCheckInterval = null;
let isNotificationPanelOpen = false;
let notificationSound = null;

// Initialize notification system
document.addEventListener('DOMContentLoaded', function() {
    console.log('Notification system initialized');
    
    // Initialize sound
    notificationSound = document.getElementById('notificationSound');
    
    // Set initial badge
    updateNotificationBadge();
    
    // Set up notification bell click
    const bell = document.getElementById('notificationBell');
    if (bell) {
        bell.addEventListener('click', function(e) {
            e.stopPropagation();
            toggleNotificationPanel();
        });
    }
    
    // Close panel when clicking outside
    document.addEventListener('click', function(event) {
        const panel = document.getElementById('notificationPanel');
        const bell = document.getElementById('notificationBell');
        
        if (panel && bell && !bell.contains(event.target) && !panel.contains(event.target)) {
            panel.classList.remove('show');
            isNotificationPanelOpen = false;
        }
    });
    
    // Start polling for new notifications
    startNotificationPolling();
    
    // Get initial last notification ID
    getLastNotificationId();
});

// Get the last notification ID
function getLastNotificationId() {
    fetch('<?php echo $base_path; ?>ajax/get_last_notification_id.php')
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            lastNotificationId = data.last_id;
            console.log('Last notification ID:', lastNotificationId);
        }
    })
    .catch(error => console.error('Error getting last notification ID:', error));
}

// Toggle notification panel
function toggleNotificationPanel() {
    const panel = document.getElementById('notificationPanel');
    if (panel) {
        panel.classList.toggle('show');
        isNotificationPanelOpen = panel.classList.contains('show');
        
        // If opening panel, load unread notifications
        if (isNotificationPanelOpen) {
            loadUnreadNotifications();
        }
    }
}

// Load unread notifications
function loadUnreadNotifications() {
    const list = document.getElementById('notificationList');
    const markAllBtn = document.getElementById('markAllReadBtn');
    
    // Show loading state
    list.innerHTML = `
        <div class="loading-notifications">
            <div class="spinner"></div>
            <div>Loading notifications...</div>
        </div>
    `;
    
    fetch('<?php echo $base_path; ?>ajax/get_unread_notifications.php')
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            let html = '';
            
            if (!data.notifications || data.notifications.length === 0) {
                html = `
                    <div class="no-notifications">
                        <i class="icon-bell" style="font-size: 32px; color: #cbd5e0; margin-bottom: 10px; display: block;"></i>
                        <div>No new notifications</div>
                    </div>
                `;
                markAllBtn.style.display = 'none';
            } else {
                markAllBtn.style.display = 'block';
                
                data.notifications.forEach(notif => {
                    let iconClass = '';
                    let icon = '🔔';
                    
                    if (notif.type.includes('approved')) {
                        icon = '✅';
                        iconClass = 'leave-approved';
                    } else if (notif.type.includes('rejected')) {
                        icon = '❌';
                        iconClass = 'leave-rejected';
                    } else if (notif.type.includes('deleted') || notif.type.includes('cancelled')) {
                        icon = '🗑️';
                        iconClass = 'leave-deleted';
                    } else if (notif.type.includes('submitted')) {
                        icon = '📝';
                        iconClass = 'pending';
                    } else if (notif.type.includes('late')) {
                        icon = '⏰';
                        iconClass = 'lop-rejected';
                    } else if (notif.type.includes('lop')) {
                        icon = '💰';
                        iconClass = 'lop-approved';
                    } else {
                        icon = 'ℹ️';
                    }
                    
                    html += `
                        <div class="notification-item unread" data-id="${notif.id}" onclick="markAsRead(${notif.id}, this)">
                            <div style="display: flex; align-items: start;">
                                <span class="notification-icon ${iconClass}">${icon}</span>
                                <div class="notification-content">
                                    <div class="notification-title">${escapeHtml(notif.title)}</div>
                                    <div class="notification-message">${escapeHtml(notif.message)}</div>
                                    <div class="notification-time">${notif.time_ago || formatDate(notif.created_at)}</div>
                                </div>
                            </div>
                        </div>
                    `;
                });
            }
            
            list.innerHTML = html;
        }
    })
    .catch(error => {
        console.error('Error loading notifications:', error);
        list.innerHTML = `
            <div class="no-notifications">
                <i class="icon-error" style="font-size: 32px; color: #c53030; margin-bottom: 10px; display: block;"></i>
                <div>Error loading notifications</div>
            </div>
        `;
    });
}

// Helper function to escape HTML
function escapeHtml(text) {
    if (!text) return '';
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

// Helper function to format date
function formatDate(dateString) {
    const date = new Date(dateString);
    return date.toLocaleString('en-US', { 
        month: 'short', 
        day: 'numeric', 
        year: 'numeric',
        hour: 'numeric',
        minute: '2-digit',
        hour12: true 
    });
}

// Mark notification as read
function markAsRead(notificationId, element) {
    fetch('<?php echo $base_path; ?>ajax/mark_notification_read.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
        },
        body: JSON.stringify({ notification_id: notificationId })
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            if (element) {
                element.remove();
            }
            
            // Update badge
            updateNotificationBadge();
            
            // Check if there are any unread notifications left
            const unreadItems = document.querySelectorAll('.notification-item');
            const markAllBtn = document.getElementById('markAllReadBtn');
            
            if (unreadItems.length === 0) {
                const list = document.getElementById('notificationList');
                if (list) {
                    list.innerHTML = `
                        <div class="no-notifications">
                            <i class="icon-bell" style="font-size: 32px; color: #cbd5e0; margin-bottom: 10px; display: block;"></i>
                            <div>No new notifications</div>
                        </div>
                    `;
                }
                if (markAllBtn) {
                    markAllBtn.style.display = 'none';
                }
            }
        }
    })
    .catch(error => console.error('Error:', error));
}

// Mark all as read
function markAllRead(event) {
    if (event) event.stopPropagation();
    
    fetch('<?php echo $base_path; ?>ajax/mark_all_notifications_read.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
        }
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            const list = document.getElementById('notificationList');
            if (list) {
                list.innerHTML = `
                    <div class="no-notifications">
                        <i class="icon-bell" style="font-size: 32px; color: #cbd5e0; margin-bottom: 10px; display: block;"></i>
                        <div>No new notifications</div>
                    </div>
                `;
            }
            
            document.getElementById('markAllReadBtn').style.display = 'none';
            updateNotificationBadge();
        }
    })
    .catch(error => console.error('Error:', error));
}

// Update notification badge count
function updateNotificationBadge() {
    fetch('<?php echo $base_path; ?>ajax/get_unread_count.php?' + new Date().getTime())
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
    .catch(error => console.error('Error updating badge:', error));
}

// Start polling for new notifications
function startNotificationPolling() {
    if (notificationCheckInterval) {
        clearInterval(notificationCheckInterval);
    }
    
    // Check every 10 seconds
    notificationCheckInterval = setInterval(checkForNewNotifications, 10000);
}

// Check for new notifications
function checkForNewNotifications() {
    fetch('<?php echo $base_path; ?>ajax/get_notifications.php?last_id=' + lastNotificationId + '&_=' + new Date().getTime())
    .then(response => response.json())
    .then(data => {
        if (data.success && data.notifications && data.notifications.length > 0) {
            console.log('New notifications:', data.notifications.length);
            
            // Update last seen ID
            if (data.last_id > lastNotificationId) {
                lastNotificationId = data.last_id;
            }
            
            // Update badge
            if (data.unread_count !== undefined) {
                const badge = document.getElementById('notificationBadge');
                if (badge) {
                    if (data.unread_count > 0) {
                        badge.textContent = data.unread_count;
                        badge.style.display = 'block';
                    } else {
                        badge.style.display = 'none';
                    }
                }
            }
            
            // Show toast for each new notification
            data.notifications.forEach(notification => {
                showNotificationToast(notification);
            });
            
            // Play sound if there are new notifications
            if (data.notifications.length > 0 && notificationSound) {
                notificationSound.play().catch(e => console.log('Sound play failed:', e));
            }
            
            // If panel is open, reload unread notifications
            if (isNotificationPanelOpen) {
                loadUnreadNotifications();
            }
        }
    })
    .catch(error => console.error('Error checking notifications:', error));
}

// Show notification toast
function showNotificationToast(notification) {
    const container = document.getElementById('notificationToastContainer');
    if (!container) return;
    
    // Check if toast for this notification already exists
    if (document.querySelector(`.notification-toast[data-id="${notification.id}"]`)) {
        return;
    }
    
    const toast = document.createElement('div');
    toast.className = 'notification-toast';
    toast.setAttribute('data-id', notification.id);
    
    // Determine icon based on type
    let icon = '🔔';
    let iconClass = 'info';
    if (notification.type.includes('approved')) {
        icon = '✅';
        iconClass = 'success';
    } else if (notification.type.includes('rejected')) {
        icon = '❌';
        iconClass = 'error';
    } else if (notification.type.includes('deleted') || notification.type.includes('cancelled')) {
        icon = '🗑️';
        iconClass = 'warning';
    } else if (notification.type.includes('submitted')) {
        icon = '📝';
        iconClass = 'info';
    } else if (notification.type.includes('late')) {
        icon = '⏰';
        iconClass = 'error';
    } else if (notification.type.includes('lop')) {
        icon = '💰';
        iconClass = 'warning';
    }
    
    toast.innerHTML = `
        <div class="notification-toast-icon ${iconClass}">${icon}</div>
        <div class="notification-toast-content">
            <div class="notification-toast-title">${escapeHtml(notification.title)}</div>
            <div class="notification-toast-message">${escapeHtml(notification.message)}</div>
            <div class="notification-toast-time">${notification.time_ago || formatDate(notification.created_at)}</div>
        </div>
        <button class="notification-toast-close" onclick="this.parentElement.remove()">×</button>
    `;
    
    container.appendChild(toast);
    
    // Auto-remove after 10 seconds
    setTimeout(() => {
        if (toast.parentNode) {
            toast.style.animation = 'slideOut 0.3s ease forwards';
            setTimeout(() => {
                if (toast.parentNode) toast.remove();
            }, 300);
        }
    }, 10000);
    
    // Click to open panel
    toast.addEventListener('click', function(e) {
        if (!e.target.classList.contains('notification-toast-close')) {
            // Open notification panel
            const panel = document.getElementById('notificationPanel');
            if (panel) {
                panel.classList.add('show');
                isNotificationPanelOpen = true;
                loadUnreadNotifications();
            }
            
            // Mark as read when clicked
            markAsRead(notification.id, null);
            
            // Remove toast
            toast.remove();
        }
    });
}

// Add spinner styles
const style = document.createElement('style');
style.textContent = `
    .loading-notifications {
        padding: 40px 20px;
        text-align: center;
        color: #718096;
    }
    .spinner {
        width: 40px;
        height: 40px;
        margin: 0 auto 15px;
        border: 3px solid #f3f3f3;
        border-top: 3px solid #006400;
        border-radius: 50%;
        animation: spin 1s linear infinite;
    }
    @keyframes spin {
        0% { transform: rotate(0deg); }
        100% { transform: rotate(360deg); }
    }
    
    .pending {
        background: #e9d8fd;
        color: #553c9a;
    }
`;
document.head.appendChild(style);

// Request notification permission
if (Notification && Notification.permission === 'default') {
    Notification.requestPermission();
}

// Refresh when page becomes visible
document.addEventListener('visibilitychange', function() {
    if (!document.hidden) {
        console.log('Page became visible, refreshing notification count');
        updateNotificationBadge();
        getLastNotificationId();
    }
});
</script>

<style>
/* Notification Bell */
.notification-bell {
    position: relative;
    margin-right: 10px;
    cursor: pointer;
    display: inline-block;
}

.notification-bell i {
    font-size: 22px;
    color: white;
    transition: transform 0.2s;
}

.notification-bell:hover i {
    transform: scale(1.1);
}

.notification-badge {
    position: absolute;
    top: -8px;
    right: -8px;
    background: #c53030;
    color: white;
    border-radius: 50%;
    padding: 2px 6px;
    font-size: 11px;
    min-width: 18px;
    height: 18px;
    text-align: center;
    font-weight: bold;
    box-shadow: 0 2px 4px rgba(0,0,0,0.2);
}

/* User Info */
.user-info {
    display: flex;
    align-items: center;
    gap: 15px;
}

.user-label {
    background: rgba(255,255,255,0.2);
    padding: 8px 15px;
    border-radius: 20px;
    display: flex;
    align-items: center;
    gap: 8px;
}

.user-role-badge {
    padding: 4px 10px;
    border-radius: 12px;
    font-size: 11px;
    font-weight: 600;
}

.role-hr { background: #c6f6d5; color: #276749; }
.role-pm { background: #bee3f8; color: #2c5282; }
.role-admin { background: #e9d8fd; color: #553c9a; }
.role-manager { background: #fed7e2; color: #b83280; }
.role-employee { background: #feebc8; color: #c05621; }

.logout-btn {
    background: rgba(255,255,255,0.2);
    border: none;
    color: white;
    padding: 8px 15px;
    border-radius: 20px;
    cursor: pointer;
    transition: background 0.3s;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    gap: 8px;
}

.logout-btn:hover {
    background: rgba(255,255,255,0.3);
}

/* Notification Panel */
.notification-panel {
    position: absolute;
    top: 70px;
    right: 20px;
    width: 380px;
    background: white;
    border-radius: 12px;
    box-shadow: 0 10px 40px rgba(0,0,0,0.15);
    z-index: 10000;
    display: none;
    max-height: 500px;
    overflow: hidden;
    border: 1px solid #e2e8f0;
    animation: slideDown 0.3s ease;
}

.notification-panel.show {
    display: block;
}

@keyframes slideDown {
    from {
        opacity: 0;
        transform: translateY(-20px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

.notification-header {
    padding: 15px 20px;
    border-bottom: 1px solid #e2e8f0;
    display: flex;
    justify-content: space-between;
    align-items: center;
    background: linear-gradient(135deg, #006400 0%, #2c9218 100%);
    color: white;
}

.notification-header h4 {
    color: white;
    font-size: 16px;
    margin: 0;
    display: flex;
    align-items: center;
    gap: 8px;
}

.mark-all-read {
    background: rgba(255,255,255,0.2);
    color: white;
    border: none;
    padding: 5px 12px;
    border-radius: 20px;
    font-size: 11px;
    font-weight: 600;
    cursor: pointer;
    transition: background 0.2s;
}

.mark-all-read:hover {
    background: rgba(255,255,255,0.3);
}

.notification-list {
    max-height: 350px;
    overflow-y: auto;
    padding: 0;
}

.notification-item {
    padding: 15px 20px;
    border-bottom: 1px solid #e2e8f0;
    cursor: pointer;
    transition: background 0.2s;
    position: relative;
    display: flex;
    align-items: flex-start;
}

.notification-item:hover {
    background: #f7fafc;
}

.notification-item.unread {
    background: #ebf8ff;
    border-left: 3px solid #006400;
}

.notification-icon {
    display: inline-flex;
    width: 36px;
    height: 36px;
    border-radius: 50%;
    text-align: center;
    align-items: center;
    justify-content: center;
    margin-right: 12px;
    font-size: 16px;
    flex-shrink: 0;
}

.notification-icon.leave-approved,
.notification-icon.permission-approved { background: #c6f6d5; color: #276749; }
.notification-icon.leave-rejected,
.notification-icon.permission-rejected { background: #fed7d7; color: #c53030; }
.notification-icon.leave-deleted,
.notification-icon.permission-deleted { background: #fed7e2; color: #b83280; }
.notification-icon.lop-approved,
.notification-icon.lop-rejected { background: #fed7d7; color: #c53030; }
.notification-icon.pending { background: #e9d8fd; color: #553c9a; }
.notification-icon.late-timesheet { background: #fed7d7; color: #c53030; }

.notification-content {
    flex: 1;
}

.notification-title {
    font-weight: 600;
    color: #2d3748;
    font-size: 14px;
    margin-bottom: 3px;
}

.notification-message {
    color: #718096;
    font-size: 12px;
    margin-bottom: 5px;
    word-break: break-word;
}

.notification-time {
    color: #a0aec0;
    font-size: 10px;
}

.notification-footer {
    padding: 12px 20px;
    background: #f7fafc;
    border-top: 1px solid #e2e8f0;
    text-align: center;
}

.notification-footer a {
    color: #006400;
    text-decoration: none;
    font-size: 13px;
    font-weight: 600;
}

.notification-footer a:hover {
    text-decoration: underline;
}

.no-notifications {
    padding: 40px 20px;
    text-align: center;
    color: #718096;
}

/* Notification Toast Container */
.notification-toast-container {
    position: fixed;
    top: 80px;
    right: 20px;
    z-index: 9999;
    display: flex;
    flex-direction: column;
    gap: 10px;
    pointer-events: none;
}

.notification-toast {
    width: 350px;
    background: white;
    border-radius: 10px;
    box-shadow: 0 10px 30px rgba(0,0,0,0.15);
    padding: 15px;
    display: flex;
    gap: 12px;
    animation: slideInRight 0.5s ease forwards;
    cursor: pointer;
    pointer-events: auto;
    border-left: 4px solid #006400;
    transition: transform 0.2s, box-shadow 0.2s;
    position: relative;
}

.notification-toast:hover {
    transform: translateX(-5px);
    box-shadow: 0 15px 40px rgba(0,0,0,0.2);
}

@keyframes slideInRight {
    from {
        opacity: 0;
        transform: translateX(100%);
    }
    to {
        opacity: 1;
        transform: translateX(0);
    }
}

@keyframes slideOut {
    from {
        opacity: 1;
        transform: translateX(0);
    }
    to {
        opacity: 0;
        transform: translateX(100%);
    }
}

.notification-toast-icon {
    width: 40px;
    height: 40px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 20px;
    flex-shrink: 0;
}

.notification-toast-icon.success {
    background: #c6f6d5;
    color: #276749;
}

.notification-toast-icon.error {
    background: #fed7d7;
    color: #c53030;
}

.notification-toast-icon.warning {
    background: #feebc8;
    color: #c05621;
}

.notification-toast-icon.info {
    background: #bee3f8;
    color: #2c5282;
}

.notification-toast-content {
    flex: 1;
}

.notification-toast-title {
    font-weight: 600;
    color: #2d3748;
    font-size: 14px;
    margin-bottom: 3px;
    padding-right: 20px;
}

.notification-toast-message {
    color: #718096;
    font-size: 12px;
    margin-bottom: 5px;
}

.notification-toast-time {
    color: #a0aec0;
    font-size: 10px;
}

.notification-toast-close {
    position: absolute;
    top: 10px;
    right: 10px;
    background: none;
    border: none;
    font-size: 20px;
    color: #a0aec0;
    cursor: pointer;
    padding: 0;
    width: 24px;
    height: 24px;
    display: flex;
    align-items: center;
    justify-content: center;
    border-radius: 50%;
}

.notification-toast-close:hover {
    background: #f7fafc;
    color: #4a5568;
}
</style>
<?php endif; ?>