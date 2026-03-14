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

// Get the latest notification ID
$stmt = $conn->prepare("
    SELECT MAX(id) as last_id FROM notifications 
    WHERE user_id = ?
");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();
$row = $result->fetch_assoc();
$last_id = $row['last_id'] ?? 0;
$stmt->close();

error_log("Last notification ID for user $user_id: $last_id");

echo json_encode([
    'success' => true,
    'last_id' => $last_id
]);
?>