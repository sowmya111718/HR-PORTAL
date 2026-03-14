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
$last_id = isset($_GET['last_id']) ? intval($_GET['last_id']) : 0;
$last_check = isset($_GET['last_check']) ? intval($_GET['last_check']) : 0;

error_log("Checking notifications for user $user_id since ID: $last_id");

// Get notifications newer than last_id
$stmt = $conn->prepare("
    SELECT * FROM notifications 
    WHERE user_id = ? AND id > ? 
    ORDER BY created_at DESC
");
$stmt->bind_param("ii", $user_id, $last_id);
$stmt->execute();
$result = $stmt->get_result();

$notifications = [];
$max_id = $last_id;

while ($row = $result->fetch_assoc()) {
    $row['time_ago'] = timeAgo($row['created_at']);
    $notifications[] = $row;
    if ($row['id'] > $max_id) {
        $max_id = $row['id'];
    }
}
$stmt->close();

$unread_count = getUnreadNotificationCount($conn, $user_id);

// Update session count
$_SESSION['notification_count'] = $unread_count;
$_SESSION['notification_count_time'] = time();

error_log("Found " . count($notifications) . " new notifications. Max ID: $max_id, Unread count: $unread_count");

echo json_encode([
    'success' => true,
    'notifications' => $notifications,
    'unread_count' => $unread_count,
    'last_id' => $max_id
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