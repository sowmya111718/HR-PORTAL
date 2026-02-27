<?php
require_once '../config/db.php';
require_once '../includes/icon_functions.php'; // ADDED

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(false, 'Invalid request method');
}

checkRole(['hr', 'admin', 'pm']);

$data = json_decode(file_get_contents('php://input'), true);

if (!isset($data['username'], $data['full_name'], $data['role'], $data['reporting_to'])) {
    jsonResponse(false, 'Missing required fields');
}

$username = sanitize($data['username']);
$full_name = sanitize($data['full_name']);
$role = sanitize($data['role']);
$reporting_to = sanitize($data['reporting_to']);
$email = sanitize($data['email'] ?? '');
$department = sanitize($data['department'] ?? '');
$position = sanitize($data['position'] ?? '');
$join_date = sanitize($data['join_date'] ?? date('Y-m-d'));
$password = isset($data['password']) ? password_hash($data['password'], PASSWORD_DEFAULT) : '';

// Check if updating or creating
if (isset($data['id']) && $data['id'] > 0) {
    // Update user
    $user_id = intval($data['id']);
    
    if ($user_id == $_SESSION['user_id'] && $role !== $_SESSION['role']) {
        jsonResponse(false, 'You cannot change your own role');
    }
    
    if ($password) {
        $stmt = $conn->prepare("
            UPDATE users SET 
            full_name = ?, role = ?, reporting_to = ?, email = ?, 
            department = ?, position = ?, join_date = ?, password = ?
            WHERE id = ?
        ");
        $stmt->bind_param("ssssssssi", $full_name, $role, $reporting_to, $email, 
                         $department, $position, $join_date, $password, $user_id);
    } else {
        $stmt = $conn->prepare("
            UPDATE users SET 
            full_name = ?, role = ?, reporting_to = ?, email = ?, 
            department = ?, position = ?, join_date = ?
            WHERE id = ?
        ");
        $stmt->bind_param("sssssssi", $full_name, $role, $reporting_to, $email, 
                         $department, $position, $join_date, $user_id);
    }
    
    if ($stmt->execute()) {
        jsonResponse(true, 'User updated successfully');
    } else {
        jsonResponse(false, 'Error updating user');
    }
    $stmt->close();
} else {
    // Create new user
    if (!$password) {
        jsonResponse(false, 'Password is required for new users');
    }
    
    // Check if username exists
    $stmt = $conn->prepare("SELECT id FROM users WHERE username = ?");
    $stmt->bind_param("s", $username);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows > 0) {
        jsonResponse(false, 'Username already exists');
    }
    $stmt->close();
    
    $stmt = $conn->prepare("
        INSERT INTO users (username, password, role, full_name, email, department, position, reporting_to, join_date)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $stmt->bind_param("sssssssss", $username, $password, $role, $full_name, 
                     $email, $department, $position, $reporting_to, $join_date);
    
    if ($stmt->execute()) {
        jsonResponse(true, 'User created successfully', ['user_id' => $stmt->insert_id]);
    } else {
        jsonResponse(false, 'Error creating user');
    }
    $stmt->close();
}
?>