<?php
require_once '../config/db.php';
require_once '../includes/leave_functions.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

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
            
            if (password_verify($password, $user['password'])) {
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['username'] = $user['username'];
                $_SESSION['role'] = $user['role'];
                $_SESSION['full_name'] = $user['full_name'];
                
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

$page_title = "MAKSIM HR - Login";
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>MAKSIM HR - Login</title>
    <?php include '../includes/head.php'; ?>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }

        body {
            background: linear-gradient(135deg, #006400 0%, #188f18 100%);
            min-height: 100vh;
            display: flex;
            justify-content: center;
            align-items: center;
            padding: 20px;
        }


        /* ── Split-screen wrapper ── */
        .login-wrapper {
            display: flex;
            width: 100%;
            max-width: 900px;
            min-height: 520px;
            border-radius: 24px;
            overflow: hidden;
            box-shadow: 0 30px 80px rgba(0,0,0,0.45);
        }

        /* ── Left panel — logo / branding ── */
        .login-brand {
            flex: 1;
            background: linear-gradient(160deg, #003d00 0%, #006400 45%, #188f18 100%);
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
            padding: 50px 40px;
            position: relative;
            overflow: hidden;
        }

        /* subtle pattern overlay */
        .login-brand::before {
            content: '';
            position: absolute;
            inset: 0;
            background:
                radial-gradient(circle at 20% 20%, rgba(255,255,255,0.06) 0%, transparent 50%),
                radial-gradient(circle at 80% 80%, rgba(255,255,255,0.04) 0%, transparent 50%);
            pointer-events: none;
        }

        /* decorative circles */
        .brand-circle {
            position: absolute;
            border-radius: 50%;
            background: rgba(255,255,255,0.05);
        }
        .brand-circle.c1 { width: 220px; height: 220px; top: -60px; left: -60px; }
        .brand-circle.c2 { width: 160px; height: 160px; bottom: -40px; right: -40px; }
        .brand-circle.c3 { width: 90px;  height: 90px;  top: 50%; right: 20px; transform: translateY(-50%); }

        .brand-logo {
            width: 170px;
            height: 170px;
            object-fit: contain;
            border-radius: 24px;
            background: rgba(255,255,255,0.12);
            padding: 18px;
            box-shadow: 0 8px 32px rgba(0,0,0,0.3);
            animation: logoPop 0.6s cubic-bezier(.26,1.4,.6,1) both;
            z-index: 1;
        }

        @keyframes logoPop {
            from { transform: scale(0.7); opacity: 0; }
            to   { transform: scale(1);   opacity: 1; }
        }

        .brand-title {
            color: #ffffff;
            font-size: 30px;
            font-weight: 800;
            margin-top: 24px;
            letter-spacing: 2px;
            text-shadow: 0 2px 8px rgba(0,0,0,0.3);
            z-index: 1;
        }

        .brand-subtitle {
            color: rgba(255,255,255,0.75);
            font-size: 13px;
            margin-top: 8px;
            letter-spacing: 1px;
            text-transform: uppercase;
            z-index: 1;
        }

        .brand-divider {
            width: 50px;
            height: 3px;
            background: rgba(255,255,255,0.4);
            border-radius: 2px;
            margin: 18px auto;
            z-index: 1;
        }

        .brand-tagline {
            color: rgba(255,255,255,0.65);
            font-size: 12px;
            text-align: center;
            max-width: 220px;
            line-height: 1.6;
            z-index: 1;
        }

        /* ── Right panel — login form ── */
        .login-container {
            background: white;
            width: 380px;
            flex-shrink: 0;
            display: flex;
            flex-direction: column;
            justify-content: center;
        }

        .login-box {
            padding: 44px 40px;
        }

        .logo {
            text-align: center;
            margin-bottom: 28px;
        }

        .logo h1 {
            color: #006400;
            font-size: 22px;
            font-weight: 700;
            margin-bottom: 4px;
        }

        .logo p {
            color: #718096;
            font-size: 13px;
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
            box-shadow: 0 10px 20px rgba(22,158,29,0.4);
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

        /* responsive: stack vertically on small screens */
        @media (max-width: 650px) {
            .login-wrapper { flex-direction: column; max-width: 400px; }
            .login-brand   { padding: 36px 24px; min-height: 220px; }
            .brand-logo    { width: 100px; height: 100px; }
            .brand-title   { font-size: 22px; }
            .login-container { width: 100%; }
        }
    </style>
</head>
<body>
    <div class="login-wrapper">

        <!-- Left brand panel -->
        <div class="login-brand">
            <div class="brand-circle c1"></div>
            <div class="brand-circle c2"></div>
            <div class="brand-circle c3"></div>
            <img src="../assets/images/maksim_infotech_logo.png" alt="MAKSIM Infotech" class="brand-logo">
            <div class="brand-title">MAKSIM</div>
            <div class="brand-divider"></div>
            <div class="brand-subtitle">HR Portal</div>
            <div class="brand-tagline">Streamlining people, projects &amp; performance — all in one place.</div>
        </div>

        <!-- Right login form -->
        <div class="login-container">
        <div class="login-box">
            <div class="logo">
                <h1>Welcome Back 👋</h1>
                <p>Sign in to your MAKSIM account</p>
            </div>
            
            <?php if ($error): ?>
                <div class="error-message">
                    <i class="icon-error"></i> <?php echo $error; ?>
                </div>
            <?php endif; ?>
            
            <form method="POST" action="">
                <div class="form-group">
                    <label class="form-label"><i class="icon-user"></i> Username</label>
                    <input type="text" name="username" class="form-control" placeholder="Enter username" required>
                </div>
                <div class="form-group">
                    <label class="form-label"><i class="icon-key"></i> Password</label>
                    <input type="password" name="password" class="form-control" placeholder="Enter password" required>
                </div>
                <button type="submit" class="btn"><i class="icon-login"></i> Login</button>
            </form>
        </div>
        </div><!-- /login-container -->
    </div><!-- /login-wrapper -->

</body>
</html>