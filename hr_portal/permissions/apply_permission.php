<?php
require_once '../config/db.php';
require_once '../includes/icon_functions.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(false, 'Invalid request method');
}

if (!isLoggedIn()) {
    jsonResponse(false, 'Not authenticated');
}

$user_id = $_SESSION['user_id'];
$data = json_decode(file_get_contents('php://input'), true);

// If not JSON, check POST data
if (empty($data)) {
    $data = $_POST;
}

if (!isset($data['permission_date'], $data['duration'], $data['reason'])) {
    jsonResponse(false, 'Missing required fields');
}

$permission_date = sanitize($data['permission_date']);
$duration = floatval($data['duration']);
$reason = sanitize($data['reason']);

// VALIDATE AND FORMAT DATE PROPERLY
$date_timestamp = strtotime($permission_date);
if ($date_timestamp === false) {
    jsonResponse(false, 'Invalid date format. Please use YYYY-MM-DD format');
}

// Convert to YYYY-MM-DD format regardless of input
$formatted_date = date('Y-m-d', $date_timestamp);

// Validate the date is valid
if (!$formatted_date || $formatted_date === '1970-01-01') {
    jsonResponse(false, 'Invalid date');
}

// Check for existing permission on same date
$stmt = $conn->prepare("
    SELECT COUNT(*) as count 
    FROM permissions 
    WHERE user_id = ? 
    AND permission_date = ? 
    AND status IN ('Pending', 'Approved')
");
$stmt->bind_param("is", $user_id, $formatted_date);
$stmt->execute();
$result = $stmt->get_result();
$row = $result->fetch_assoc();
$stmt->close();

if ($row['count'] > 0) {
    jsonResponse(false, 'You already have a permission request for this date');
}

// Insert permission request with current timestamp and PENDING status
$current_time = date('Y-m-d H:i:s');
$status = 'Pending'; // This should be PENDING, not Approved

$stmt = $conn->prepare("
    INSERT INTO permissions (user_id, permission_date, duration, reason, status, applied_date)
    VALUES (?, ?, ?, ?, ?, ?)
");
$stmt->bind_param("isdsss", $user_id, $formatted_date, $duration, $reason, $status, $current_time);

if ($stmt->execute()) {
    $permission_id = $stmt->insert_id;
    jsonResponse(true, 'Permission request submitted successfully and is pending approval', [
        'permission_id' => $permission_id,
        'permission_date' => $formatted_date,
        'duration' => $duration,
        'status' => 'Pending'
    ]);
} else {
    jsonResponse(false, 'Error submitting permission request. Database error: ' . $stmt->error);
}

$stmt->close();
?>