<?php
require_once '../config/db.php';
require_once '../includes/leave_functions.php'; // ADD THIS LINE for leave year auto-reset

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = sanitize($_POST['username']);
    $password = $_POST['password'];
    
    if (empty($username) || empty($password)) {
        $error = 'Please enter username and password';
    } else {
        $stmt = $conn->prepare("SELECT id, username, password, role, full_name FROM users WHERE username = ?");
        $stmt->bind_param("s", $username);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows === 1) {
            $user = $result->fetch_assoc();
            
            // Verify password (for demo, using simple comparison. In production, use password_verify())
            if (password_verify($password, $user['password']) || $password === 'password') {
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['username'] = $user['username'];
                $_SESSION['role'] = $user['role'];
                $_SESSION['full_name'] = $user['full_name'];
                
                // === ADD THIS - Auto check and reset leave year ===
                // Check and auto-reset leave year if needed
                $leave_year = getCurrentLeaveYear();
                if ($leave_year['is_new_year']) {
                    $check_reset = $conn->query("SELECT COUNT(*) as count FROM system_logs WHERE event_type = 'leave_year_reset' AND DATE(created_at) = CURDATE()");
                    if ($check_reset) {
                        $reset_done = $check_reset->fetch_assoc();
                        if ($reset_done['count'] == 0) {
                            $reset_result = resetLeaveBalancesForNewYear($conn);
                            if ($reset_result['success']) {
                                $_SESSION['reset_message'] = $reset_result['message'];
                            }
                        }
                    }
                }
                // === END OF ADDED CODE ===
                
                header('Location: ../dashboard.php');
                exit();
            } else {
                $error = 'Invalid username or password';
            }
        } else {
            $error = 'Invalid username or password';
        }
        $stmt->close();
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>MAKSIM HR - Login</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }

        body {
            background: linear-gradient(135deg,#006400 0%, #188f18 100%);
            min-height: 100vh;
            display: flex;
            justify-content: center;
            align-items: center;
            padding: 20px;
        }

        .login-container {
            background: white;
            border-radius: 20px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
            overflow: hidden;
            width: 100%;
            max-width: 400px;
        }

        .login-box {
            padding: 40px;
        }

        .logo {
            text-align: center;
            margin-bottom: 30px;
        }

        .logo h1 {
            color: #0a961d;
            font-size: 28px;
            margin-bottom: 5px;
        }

        .logo p {
            color: #718096;
            font-size: 14px;
        }

        .form-group {
            margin-bottom: 20px;
        }

        .form-label {
            display: block;
            margin-bottom: 8px;
            color: #4a6852;
            font-weight: 500;
        }

        .form-control {
            width: 100%;
            padding: 12px 16px;
            border: 2px solid #e2e8f0;
            border-radius: 10px;
            font-size: 16px;
            transition: border-color 0.3s;
        }

        .form-control:focus {
            outline: none;
            border-color: #0db840;
        }

        .btn {
            background: linear-gradient(135deg, #169e1d 0%, #1f8016 100%);
            color: white;
            border: none;
            padding: 14px 20px;
            border-radius: 10px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: transform 0.2s, box-shadow 0.2s;
            width: 100%;
        }

        .btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 20px rgba(102, 126, 234, 0.4);
        }

        .error-message {
            background: #fed7d7;
            color: #c53030;
            padding: 10px 15px;
            border-radius: 8px;
            margin-bottom: 20px;
            display: <?php echo $error ? 'block' : 'none'; ?>;
        }

        .demo-accounts {
            margin-top: 20px;
            padding: 15px;
            background: #f7fafc;
            border-radius: 10px;
            font-size: 14px;
            color: #4a5568;
        }

        .demo-accounts h4 {
            margin-bottom: 10px;
            color: #2d3748;
        }

        .demo-accounts ul {
            list-style: none;
            padding-left: 0;
        }

        .demo-accounts li {
            margin-bottom: 5px;
            padding: 5px;
            background: white;
            border-radius: 5px;
            border-left: 3px solid #667eea;
        }
    </style>
</head>
<body>
    <div class="login-container">
        <div class="login-box">
            <div class="logo">
                <h1><img src="../assets/images/maksim_infotech_logo.png" alt="MAKSIM Infotech" height="40" style="margin-right: 10px;"></i> MAKSIM HR</h1>
                <p>Human Resource Management System</p>
            </div>
            
            <?php if ($error): ?>
                <div class="error-message">
                    <i class="fas fa-exclamation-circle"></i> <?php echo $error; ?>
                </div>
            <?php endif; ?>
            
            <form method="POST" action="">
                <div class="form-group">
                    <label class="form-label">Username</label>
                    <input type="text" name="username" class="form-control" placeholder="Enter username" required>
                </div>
                <div class="form-group">
                    <label class="form-label">Password</label>
                    <input type="password" name="password" class="form-control" placeholder="Enter password" required>
                </div>
                <button type="submit" class="btn">Login</button>
            </form>
            
            <div class="demo-accounts">
                <h4>Demo Accounts:</h4>
                <ul>
                    <li><strong>HR Manager:</strong> hr / password</li>
                    <li><strong>Admin:</strong> admin / password</li>
                    <li><strong>Employee:</strong> employee / password</li>
                    <li><strong>Project Manager:</strong> projectmanager / password</li>
                    <li><strong>Manager:</strong> manager / password</li>
                </ul>
            </div>
        </div>
    </div>
</body>
</html>