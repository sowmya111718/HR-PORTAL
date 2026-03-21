<?php
require_once '../config/db.php';
require_once '../includes/notification_functions.php';

if (!isLoggedIn()) {
    header('Location: login.php');
    exit();
}

$user_id = $_SESSION['user_id'];
$role = $_SESSION['role'];
$message = '';

// Check if we're changing another user's password (HR/Admin/dm only)
$target_user_id = isset($_GET['user_id']) ? intval($_GET['user_id']) : $user_id;

// Only allow HR/Admin/dm to change others' passwords
if ($target_user_id != $user_id && !in_array($role, ['hr', 'admin', 'dm'])) {
    header('Location: ../dashboard.php');
    exit();
}

// Get target user info
$stmt = $conn->prepare("SELECT id, username, full_name, role FROM users WHERE id = ?");
$stmt->bind_param("i", $target_user_id);
$stmt->execute();
$target_user = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$target_user) {
    header('Location: users.php');
    exit();
}

// Get all users for dropdown (HR/Admin/dm only)
$users = [];
if (in_array($role, ['hr', 'admin', 'dm'])) {
    $users_result = $conn->query("SELECT id, username, full_name FROM users ORDER BY full_name");
    $users = $users_result->fetch_all(MYSQLI_ASSOC);
}

// Change password
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['change_password'])) {
    $target_id = intval($_POST['user_id']);
    $current_password = $_POST['current_password'] ?? '';
    $new_password = $_POST['new_password'];
    $confirm_password = $_POST['confirm_password'];
    
    // Validate inputs
    if (empty($new_password) || empty($confirm_password)) {
        $message = '<div class="alert alert-error"><i class="icon-error"></i> Please fill all password fields</div>';
    } elseif ($new_password !== $confirm_password) {
        $message = '<div class="alert alert-error"><i class="icon-error"></i> New password and confirm password do not match</div>';
    } elseif (strlen($new_password) < 6) {
        $message = '<div class="alert alert-error"><i class="icon-error"></i> New password must be at least 6 characters long</div>';
    } else {
        
        // If changing own password, verify current password
        if ($target_id == $user_id) {
            // Get current password hash
            $stmt = $conn->prepare("SELECT password FROM users WHERE id = ?");
            $stmt->bind_param("i", $user_id);
            $stmt->execute();
            $result = $stmt->get_result();
            $user = $result->fetch_assoc();
            $stmt->close();
            
            // FIXED: Removed the 'password' fallback - only actual password works
            if (!password_verify($current_password, $user['password'])) {
                $message = '<div class="alert alert-error"><i class="icon-error"></i> Current password is incorrect</div>';
            } else {
                // Update password
                $result = updateUserPassword($conn, $target_id, $new_password, $user_id, $role, $target_user);
                $message = $result['message'];
            }
        } else {
            // HR/Admin/dm changing someone else's password - no current password check needed
            $result = updateUserPassword($conn, $target_id, $new_password, $user_id, $role, $target_user);
            $message = $result['message'];
        }
    }
}

// Function to update password and send notifications
function updateUserPassword($conn, $target_id, $new_password, $changer_id, $changer_role, $target_user) {
    
    $new_password_hash = password_hash($new_password, PASSWORD_DEFAULT);
    $stmt = $conn->prepare("UPDATE users SET password = ? WHERE id = ?");
    $stmt->bind_param("si", $new_password_hash, $target_id);
    
    if ($stmt->execute()) {
        // Password changed successfully
        
        // Send notification to the user whose password changed
        $title = "🔐 Password Changed";
        
        if ($target_id == $changer_id) {
            $notification_msg = "Your password was successfully changed.";
            createNotification($conn, $target_id, 'password_changed_self', $title, $notification_msg, 0);
            
            $message = '<div class="alert alert-success"><i class="icon-success"></i> Your password has been changed successfully!</div>';
        } else {
            // Get changer's name
            $changer_stmt = $conn->prepare("SELECT full_name FROM users WHERE id = ?");
            $changer_stmt->bind_param("i", $changer_id);
            $changer_stmt->execute();
            $changer = $changer_stmt->get_result()->fetch_assoc();
            $changer_stmt->close();
            
            $notification_msg = "Your password was changed by " . ($changer['full_name'] ?? 'Administrator') . ".";
            createNotification($conn, $target_id, 'password_changed_by_admin', $title, $notification_msg, $changer_id);
            
            $message = '<div class="alert alert-success"><i class="icon-success"></i> Password for ' . htmlspecialchars($target_user['full_name']) . ' has been changed successfully!</div>';
            
            // Log to system_logs
            $check = $conn->query("SHOW TABLES LIKE 'system_logs'");
            if ($check && $check->num_rows > 0) {
                $log = $conn->prepare("INSERT INTO system_logs (event_type, description, user_id, created_at) VALUES (?, ?, ?, NOW())");
                $event_type = 'password_change';
                $desc = "Password changed for user: {$target_user['username']} ({$target_user['full_name']}) by " . ($changer['full_name'] ?? 'Admin');
                $log->bind_param("ssi", $event_type, $desc, $changer_id);
                $log->execute();
                $log->close();
            }
        }
        
        // Clear form fields
        echo '<script>
            document.addEventListener("DOMContentLoaded", function() {
                if (document.querySelector("[name=current_password]")) {
                    document.querySelector("[name=current_password]").value = "";
                }
                document.querySelector("[name=new_password]").value = "";
                document.querySelector("[name=confirm_password]").value = "";
            });
        </script>';
        
    } else {
        $message = '<div class="alert alert-error"><i class="icon-error"></i> Error changing password: ' . $stmt->error . '</div>';
    }
    $stmt->close();
    
    return ['success' => true, 'message' => $message];
}

$page_title = "Change Password - MAKSIM HR";
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php include '../includes/head.php'; ?>
    <style>
        .user-selector {
            background: #f0f7ff;
            padding: 20px;
            border-radius: 10px;
            margin-bottom: 20px;
            border-left: 4px solid #ed8936;
        }
        .admin-badge {
            background: #ed8936;
            color: white;
            padding: 3px 10px;
            border-radius: 20px;
            font-size: 12px;
            margin-left: 10px;
        }
        .hr-badge {
            background: #4299e1;
            color: white;
            padding: 3px 10px;
            border-radius: 20px;
            font-size: 12px;
            margin-left: 10px;
        }
        .dm-badge {
            background: #48bb78;
            color: white;
            padding: 3px 10px;
            border-radius: 20px;
            font-size: 12px;
            margin-left: 10px;
        }
        .password-requirements {
            background: #f7fafc;
            padding: 15px;
            border-radius: 8px;
            margin-top: 20px;
        }
        .info-box {
            background: #fff5e6;
            border-left: 4px solid #ed8936;
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
        }
        .role-indicator {
            display: inline-block;
            padding: 2px 8px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 600;
            margin-left: 5px;
        }
        .role-admin { background: #e9d8fd; color: #553c9a; }
        .role-hr { background: #c6f6d5; color: #276749; }
        .role-dm { background: #bee3f8; color: #2c5282; }
    </style>
</head>
<body>
    <?php include '../includes/header.php'; ?>
    
    <div class="app-main">
        <?php include '../includes/sidebar.php'; ?>
        
        <div class="main-content">
            <h2 class="page-title">
                <i class="icon-edit"></i> Change Password
                <?php if ($target_user_id != $user_id): ?>
                    <span class="admin-badge">👑 Admin Action</span>
                <?php elseif ($role == 'hr'): ?>
                    <span class="hr-badge">👥 HR</span>
                <?php elseif ($role == 'dm'): ?>
                    <span class="dm-badge">📋 dm</span>
                <?php endif; ?>
            </h2>
            
            <?php echo $message; ?>
            
            <!-- User Selector (for HR/Admin/dm only) -->
            <?php if (in_array($role, ['hr', 'admin', 'dm']) && count($users) > 0): ?>
            <div class="user-selector">
                <form method="GET" action="" style="display: flex; align-items: center; gap: 15px; flex-wrap: wrap;">
                    <div style="flex: 1;">
                        <label class="form-label"><i class="icon-user"></i> Select User:</label>
                        <select name="user_id" class="form-control" onchange="this.form.submit()">
                            <option value="<?php echo $user_id; ?>">-- Change My Own Password --</option>
                            <?php foreach ($users as $u): ?>
                                <option value="<?php echo $u['id']; ?>" <?php echo $target_user_id == $u['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($u['full_name']); ?> (<?php echo $u['username']; ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div style="padding-top: 20px;">
                        <?php if ($target_user_id == $user_id): ?>
                            <span class="admin-badge">Changing YOUR password</span>
                        <?php else: ?>
                            <span class="admin-badge">Changing <?php echo htmlspecialchars($target_user['full_name']); ?>'s password</span>
                        <?php endif; ?>
                    </div>
                </form>
            </div>
            <?php endif; ?>
            
            <!-- Info Box for Admin Actions -->
            <?php if ($target_user_id != $user_id): ?>
            <div class="info-box">
                <i class="icon-shield" style="color: #ed8936;"></i>
                <strong>Admin Action:</strong> You are changing password for 
                <strong><?php echo htmlspecialchars($target_user['full_name']); ?></strong> 
                <span class="role-indicator role-<?php echo $target_user['role']; ?>">
                    <?php echo strtoupper($target_user['role']); ?>
                </span>
                <br>
                <small>No current password verification needed. User will receive a notification.</small>
            </div>
            <?php endif; ?>
            
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">
                        <i class="icon-key"></i> 
                        <?php if ($target_user_id == $user_id): ?>
                            Change Your Password
                        <?php else: ?>
                            Change Password for: <strong><?php echo htmlspecialchars($target_user['full_name']); ?></strong>
                        <?php endif; ?>
                    </h3>
                </div>
                
                <form method="POST" action="">
                    <input type="hidden" name="user_id" value="<?php echo $target_user_id; ?>">
                    
                    <!-- Current Password - Only show when changing own password -->
                    <?php if ($target_user_id == $user_id): ?>
                    <div class="form-group">
                        <label class="form-label"><i class="icon-key"></i> Current Password *</label>
                        <input type="password" name="current_password" class="form-control" required>
                        <small style="color: #718096;">Enter your current password</small>
                    </div>
                    <?php endif; ?>
                    
                    <div class="form-group">
                        <label class="form-label"><i class="icon-key"></i> New Password *</label>
                        <input type="password" name="new_password" class="form-control" required minlength="6">
                        <small style="color: #718096;">Minimum 6 characters</small>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label"><i class="icon-key"></i> Confirm New Password *</label>
                        <input type="password" name="confirm_password" class="form-control" required minlength="6">
                    </div>
                    
                    <div style="display: flex; gap: 10px; align-items: center;">
                        <button type="submit" name="change_password" class="btn" style="background: <?php echo $target_user_id == $user_id ? '#006400' : '#ed8936'; ?>;">
                            <i class="icon-save"></i> 
                            <?php if ($target_user_id == $user_id): ?>
                                Change My Password
                            <?php else: ?>
                                Change Password for <?php echo htmlspecialchars($target_user['full_name']); ?>
                            <?php endif; ?>
                        </button>
                        
                        <?php if ($target_user_id != $user_id): ?>
                        <a href="change_password.php" class="btn" style="background: #718096;">
                            <i class="icon-cancel"></i> Cancel
                        </a>
                        <?php endif; ?>
                    </div>
                </form>
            </div>

            <!-- Password Requirements -->
            <div class="password-requirements">
                <h4 style="margin-bottom: 10px; color: #4a5568;"><i class="icon-shield"></i> Password Security Tips</h4>
                <ul style="list-style: none; padding-left: 0;">
                    <li style="margin-bottom: 8px; padding-left: 25px; position: relative;">
                        <i class="icon-check" style="color: #48bb78; position: absolute; left: 0;"></i>
                        Use at least 8 characters
                    </li>
                    <li style="margin-bottom: 8px; padding-left: 25px; position: relative;">
                        <i class="icon-check" style="color: #48bb78; position: absolute; left: 0;"></i>
                        Include uppercase and lowercase letters
                    </li>
                    <li style="margin-bottom: 8px; padding-left: 25px; position: relative;">
                        <i class="icon-check" style="color: #48bb78; position: absolute; left: 0;"></i>
                        Add numbers and special characters
                    </li>
                    <li style="margin-bottom: 8px; padding-left: 25px; position: relative;">
                        <i class="icon-check" style="color: #48bb78; position: absolute; left: 0;"></i>
                        Don't reuse passwords from other sites
                    </li>
                </ul>
                
                <?php if ($target_user_id != $user_id): ?>
                <div style="margin-top: 15px; padding: 10px; background: #f0f7ff; border-radius: 5px;">
                    <i class="icon-info"></i>
                    <strong>Note:</strong> 
                    <?php echo htmlspecialchars($target_user['full_name']); ?> will receive a notification that their password was changed.
                    This action is logged in system_logs.
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    
    <script src="../assets/js/app.js"></script>
</body>
</html>