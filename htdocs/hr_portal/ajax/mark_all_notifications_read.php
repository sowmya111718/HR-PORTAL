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

error_log("Marking all notifications as read for user $user_id");

$result = markAllNotificationsRead($conn, $user_id);

// Update session count
$_SESSION['notification_count'] = 0;

error_log("Marked " . ($result['affected'] ?? 0) . " notifications as read");

echo json_encode([
    'success' => $result['success'],
    'count' => 0,
    'affected' => $result['affected'] ?? 0
]);
?>