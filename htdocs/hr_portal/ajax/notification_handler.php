<?php
// File: ajax/notification_handler.php
require_once '../config/db.php';
require_once '../includes/notification_functions.php';

// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

header('Content-Type: application/json');

if (!isLoggedIn()) {
    echo json_encode(['success' => false, 'message' => 'Not authenticated']);
    exit();
}

$action = isset($_POST['action']) ? $_POST['action'] : '';
$type = isset($_POST['type']) ? $_POST['type'] : '';
$id = isset($_POST['id']) ? intval($_POST['id']) : 0;
$user_id = $_SESSION['user_id'];

$response = ['success' => false, 'message' => 'Invalid action'];

switch ($action) {
    case 'mark_read':
        if ($id > 0) {
            $success = markNotificationRead($conn, $id, $user_id);
            $response = [
                'success' => $success,
                'message' => $success ? 'Notification marked as read' : 'Failed to mark as read'
            ];
        }
        break;
        
    case 'mark_all_read':
        $result = markAllNotificationsRead($conn, $user_id);
        $response = [
            'success' => $result['success'],
            'message' => 'All notifications marked as read',
            'count' => 0,
            'affected' => $result['affected'] ?? 0
        ];
        break;
        
    case 'get_unread_count':
        $count = getUnreadNotificationCount($conn, $user_id);
        $response = [
            'success' => true,
            'count' => $count
        ];
        break;
        
    case 'get_recent':
        $limit = isset($_POST['limit']) ? intval($_POST['limit']) : 10;
        $notifications = getRecentNotifications($conn, $user_id, $limit);
        $unread_count = getUnreadNotificationCount($conn, $user_id);
        
        // Add time ago for each notification
        foreach ($notifications as &$notif) {
            $notif['time_ago'] = timeAgo($notif['created_at']);
        }
        
        $response = [
            'success' => true,
            'notifications' => $notifications,
            'unread_count' => $unread_count,
            'count' => count($notifications)
        ];
        break;
        
    case 'get_unread':
        $unread_notifications = [];
        $stmt = $conn->prepare("
            SELECT * FROM notifications 
            WHERE user_id = ? AND is_read = 0
            ORDER BY created_at DESC
            LIMIT 20
        ");
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $row['time_ago'] = timeAgo($row['created_at']);
            $unread_notifications[] = $row;
        }
        $stmt->close();
        
        $response = [
            'success' => true,
            'notifications' => $unread_notifications,
            'count' => count($unread_notifications)
        ];
        break;
        
    case 'cleanup_old':
        // Only allow admin to cleanup
        if ($_SESSION['role'] === 'admin') {
            $deleted = cleanupOldNotifications($conn);
            $response = [
                'success' => true,
                'message' => "Deleted $deleted old notifications",
                'deleted' => $deleted
            ];
        } else {
            $response = ['success' => false, 'message' => 'Unauthorized'];
        }
        break;
        
    default:
        $response = ['success' => false, 'message' => 'Unknown action'];
}

echo json_encode($response);

function timeAgo($datetime) {
    $time = strtotime($datetime);
    $now = time();
    $diff = $now - $time;
    
    if ($diff < 60) {
        return $diff . ' seconds ago';
    } elseif ($diff < 3600) {
        $mins = floor($diff / 60);
        return $mins . ' minute' . ($mins > 1 ? 's' : '') . ' ago';
    } elseif ($diff < 86400) {
        $hours = floor($diff / 3600);
        return $hours . ' hour' . ($hours > 1 ? 's' : '') . ' ago';
    } elseif ($diff < 2592000) {
        $days = floor($diff / 86400);
        return $days . ' day' . ($days > 1 ? 's' : '') . ' ago';
    } else {
        return date('M j, Y g:i A', $time);
    }
}
?>