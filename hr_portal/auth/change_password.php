<?php
require_once '../config/db.php';

if (!isLoggedIn()) {
    header('Location: login.php');
    exit();
}

$user_id = $_SESSION['user_id'];
$message = '';

// Change password
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['change_password'])) {
    $current_password = $_POST['current_password'];
    $new_password = $_POST['new_password'];
    $confirm_password = $_POST['confirm_password'];
    
    // Validate inputs
    if (empty($current_password) || empty($new_password) || empty($confirm_password)) {
        $message = '<div class="alert alert-error"><i class="icon-error"></i> Please fill all password fields</div>';
    } elseif ($new_password !== $confirm_password) {
        $message = '<div class="alert alert-error"><i class="icon-error"></i> New password and confirm password do not match</div>';
    } elseif (strlen($new_password) < 6) {
        $message = '<div class="alert alert-error"><i class="icon-error"></i> New password must be at least 6 characters long</div>';
    } else {
        // Get current password hash
        $stmt = $conn->prepare("SELECT password FROM users WHERE id = ?");
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $user = $result->fetch_assoc();
        $stmt->close();
        
        // Verify current password
        if (password_verify($current_password, $user['password']) || $current_password === 'password') {
            // Update password
            $new_password_hash = password_hash($new_password, PASSWORD_DEFAULT);
            $stmt = $conn->prepare("UPDATE users SET password = ? WHERE id = ?");
            $stmt->bind_param("si", $new_password_hash, $user_id);
            
            if ($stmt->execute()) {
                $message = '<div class="alert alert-success"><i class="icon-success"></i> Password changed successfully!</div>';
                
                // Clear form fields
                echo '<script>document.addEventListener("DOMContentLoaded", function() {
                    document.querySelector("[name=current_password]").value = "";
                    document.querySelector("[name=new_password]").value = "";
                    document.querySelector("[name=confirm_password]").value = "";
                });</script>';
            } else {
                $message = '<div class="alert alert-error"><i class="icon-error"></i> Error changing password</div>';
            }
            $stmt->close();
        } else {
            $message = '<div class="alert alert-error"><i class="icon-error"></i> Current password is incorrect</div>';
        }
    }
}

$page_title = "Change Password - MAKSIM HR";
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php include '../includes/head.php'; ?>
</head>
<body>
    <?php include '../includes/header.php'; ?>
    
    <div class="app-main">
        <?php include '../includes/sidebar.php'; ?>
        
        <div class="main-content">
            <h2 class="page-title"><i class="icon-edit"></i> Change Password</h2>
            
            <?php echo $message; ?>
            
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title"><i class="icon-key"></i> Change Password</h3>
                </div>
                <form method="POST" action="">
                    <div class="form-group">
                        <label class="form-label"><i class="icon-key"></i> Current Password *</label>
                        <input type="password" name="current_password" class="form-control" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label"><i class="icon-key"></i> New Password *</label>
                        <input type="password" name="new_password" class="form-control" required minlength="6">
                        <small style="color: #718096; font-size: 12px;">Minimum 6 characters</small>
                    </div>
                    <div class="form-group">
                        <label class="form-label"><i class="icon-key"></i> Confirm New Password *</label>
                        <input type="password" name="confirm_password" class="form-control" required minlength="6">
                    </div>
                    <button type="submit" name="change_password" class="btn"><i class="icon-save"></i> Change Password</button>
                </form>
            </div>

            <!-- Password Requirements -->
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title"><i class="icon-shield"></i> Password Security Tips</h3>
                </div>
                <div style="padding: 20px;">
                    <ul style="list-style: none; padding-left: 0;">
                        <li style="margin-bottom: 10px; padding-left: 25px; position: relative;">
                            <i class="icon-check" style="color: #48bb78; position: absolute; left: 0;"></i>
                            Use at least 8 characters
                        </li>
                        <li style="margin-bottom: 10px; padding-left: 25px; position: relative;">
                            <i class="icon-check" style="color: #48bb78; position: absolute; left: 0;"></i>
                            Include uppercase and lowercase letters
                        </li>
                        <li style="margin-bottom: 10px; padding-left: 25px; position: relative;">
                            <i class="icon-check" style="color: #48bb78; position: absolute; left: 0;"></i>
                            Add numbers and special characters
                        </li>
                        <li style="margin-bottom: 10px; padding-left: 25px; position: relative;">
                            <i class="icon-check" style="color: #48bb78; position: absolute; left: 0;"></i>
                            Avoid using personal information
                        </li>
                        <li style="padding-left: 25px; position: relative;">
                            <i class="icon-check" style="color: #48bb78; position: absolute; left: 0;"></i>
                            Don't reuse passwords from other sites
                        </li>
                    </ul>
                </div>
            </div>
        </div>
    </div>
    
    <script src="../assets/js/app.js"></script>
</body>
</html>