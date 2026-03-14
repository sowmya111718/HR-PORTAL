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
$data = json_decode(file_get_contents('php://input'), true);
$notification_id = isset($data['notification_id']) ? intval($data['notification_id']) : 0;

error_log("Marking notification $notification_id as read for user $user_id");

if ($notification_id > 0) {
    $success = markNotificationRead($conn, $notification_id, $user_id);
    
    // Get updated unread count
    $count_stmt = $conn->prepare("SELECT COUNT(*) as count FROM notifications WHERE user_id = ? AND is_read = 0");
    $count_stmt->bind_param("i", $user_id);
    $count_stmt->execute();
    $count_result = $count_stmt->get_result();
    $count_row = $count_result->fetch_assoc();
    $new_count = (int)$count_row['count'];
    $count_stmt->close();
    
    // Update session count
    $_SESSION['notification_count'] = $new_count;
    
    error_log("Mark read result: " . ($success ? "Success" : "Failed") . ", New count: $new_count");
    
    echo json_encode([
        'success' => $success,
        'count' => $new_count
    ]);
} else {
    error_log("Invalid notification ID");
    echo json_encode(['success' => false, 'message' => 'Invalid notification ID']);
}
?>