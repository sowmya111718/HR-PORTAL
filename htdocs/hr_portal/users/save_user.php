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
$birthday = !empty($data['birthday']) ? sanitize($data['birthday']) : null;
$password = isset($data['password']) ? password_hash($data['password'], PASSWORD_DEFAULT) : '';

// Check if updating or creating
if (isset($data['id']) && $data['id'] > 0) {
    // Update user
    $user_id = intval($data['id']);
    
    if ($user_id == $_SESSION['user_id'] && $role !== $_SESSION['role']) {
        jsonResponse(false, 'You cannot change your own role');
    }
    
    // Check if username already exists for another user
    $check_stmt = $conn->prepare("SELECT id FROM users WHERE username = ? AND id != ?");
    $check_stmt->bind_param("si", $username, $user_id);
    $check_stmt->execute();
    $check_result = $check_stmt->get_result();
    
    if ($check_result->num_rows > 0) {
        jsonResponse(false, 'Username already exists. Please choose a different username.');
    }
    $check_stmt->close();
    
    if ($password) {
        $stmt = $conn->prepare("
            UPDATE users SET 
            username = ?, full_name = ?, role = ?, reporting_to = ?, email = ?, 
            department = ?, position = ?, join_date = ?, birthday = ?, password = ?
            WHERE id = ?
        ");
        $stmt->bind_param("ssssssssssi", $username, $full_name, $role, $reporting_to, $email, 
                         $department, $position, $join_date, $birthday, $password, $user_id);
    } else {
        $stmt = $conn->prepare("
            UPDATE users SET 
            username = ?, full_name = ?, role = ?, reporting_to = ?, email = ?, 
            department = ?, position = ?, join_date = ?, birthday = ?
            WHERE id = ?
        ");
        $stmt->bind_param("sssssssssi", $username, $full_name, $role, $reporting_to, $email, 
                         $department, $position, $join_date, $birthday, $user_id);
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
        INSERT INTO users (username, password, role, full_name, email, department, position, reporting_to, join_date, birthday)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $stmt->bind_param("ssssssssss", $username, $password, $role, $full_name, 
                     $email, $department, $position, $reporting_to, $join_date, $birthday);
    
    if ($stmt->execute()) {
        jsonResponse(true, 'User created successfully', ['user_id' => $stmt->insert_id]);
    } else {
        jsonResponse(false, 'Error creating user');
    }
    $stmt->close();
}
?>