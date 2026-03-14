<?php
session_start();

// Database configuration - USE 192.168.48.77 INSTEAD OF localhost
define('DB_HOST', '192.168.48.77');  // Changed from localhost to IP
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_NAME', 'hr_portal');

// Create connection with timeout and error reporting
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
try {
    $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME, 3306);
    $conn->set_charset("utf8mb4");
} catch (mysqli_sql_exception $e) {
    die("Connection failed: " . $e->getMessage() . " (Host: " . DB_HOST . ")");
}

// Error reporting
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Helper function to check if user is logged in
function isLoggedIn() {
    return isset($_SESSION['user_id']);
}

// Helper function to check user role
function checkRole($allowedRoles) {
    if (!isLoggedIn() || !in_array($_SESSION['role'], $allowedRoles)) {
        header('Location: ../auth/login.php');
        exit();
    }
}

// Helper function to sanitize input
function sanitize($input) {
    global $conn;
    return $conn->real_escape_string(trim($input));
}

// Helper function to return JSON response
function jsonResponse($success, $message = '', $data = []) {
    header('Content-Type: application/json');
    echo json_encode([
        'success' => $success,
        'message' => $message,
        'data' => $data
    ]);
    exit();
}
?>