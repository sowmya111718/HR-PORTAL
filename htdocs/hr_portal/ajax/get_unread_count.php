<?php
require_once '../config/db.php';
require_once '../includes/notification_functions.php';

// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

header('Content-Type: application/json');

if (!isLoggedIn()) {
    echo json_encode(['count' => 0]);
    exit();
}

$user_id = $_SESSION['user_id'];

error_log("Getting unread count for user: $user_id");

// Force refresh - get fresh count from database
$stmt = $conn->prepare("SELECT COUNT(*) as count FROM notifications WHERE user_id = ? AND is_read = 0");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();
$row = $result->fetch_assoc();
$count = (int)$row['count'];
$stmt->close();

// Store in session with timestamp
$_SESSION['notification_count'] = $count;
$_SESSION['notification_count_time'] = time();

error_log("Unread count for user $user_id: $count");

echo json_encode(['count' => $count]);
?>