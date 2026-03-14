<?php
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

$user_id = $_SESSION['user_id'];

// Get recent notifications (unread first, then recent)
$stmt = $conn->prepare("
    SELECT * FROM notifications 
    WHERE user_id = ? 
    ORDER BY is_read ASC, created_at DESC 
    LIMIT 10
");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();

$notifications = [];
while ($row = $result->fetch_assoc()) {
    // Format time for display
    $row['time_ago'] = timeAgo($row['created_at']);
    $notifications[] = $row;
}
$stmt->close();

// Get unread count
$unread_count = getUnreadNotificationCount($conn, $user_id);

error_log("Found " . count($notifications) . " notifications for user $user_id, $unread_count unread");

echo json_encode([
    'success' => true,
    'notifications' => $notifications,
    'unread_count' => $unread_count,
    'count' => count($notifications)
]);

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