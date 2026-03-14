<?php
require_once '../config/db.php';
require_once '../includes/notification_functions.php';

if (!isLoggedIn()) {
    jsonResponse(false, 'Not authenticated');
}

$user_id = $_SESSION['user_id'];
$last_id = isset($_GET['last_id']) ? intval($_GET['last_id']) : 0;

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
    $notifications[] = $row;
    if ($row['id'] > $max_id) {
        $max_id = $row['id'];
    }
}
$stmt->close();

$unread_count = getUnreadNotificationCount($conn, $user_id);

jsonResponse(true, 'Notifications retrieved', [
    'notifications' => $notifications,
    'unread_count' => $unread_count,
    'last_id' => $max_id
]);