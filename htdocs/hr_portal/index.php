<?php
// Redirect to login page if not logged in
session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: auth/login.php');
    exit();
}

// Redirect to dashboard if logged in
header('Location: dashboard.php');
exit();
?>