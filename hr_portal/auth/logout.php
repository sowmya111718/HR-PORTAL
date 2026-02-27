<?php
session_start();
session_destroy();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Logging Out...</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #74ebd5 0%, #ACB6E5 100%);
            min-height: 100vh;
            display: flex;
            justify-content: center;
            align-items: center;
        }
        
        .container {
            text-align: center;
            background: rgba(255, 255, 255, 0.95);
            padding: 50px;
            border-radius: 20px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.2);
            animation: slideIn 0.6s ease-out;
        }
        
        @keyframes slideIn {
            from {
                opacity: 0;
                transform: translateY(-30px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        h1 {
            color: #2c3e50;
            font-size: 2.5em;
            margin-bottom: 20px;
            animation: fadeIn 1s;
        }
        
        p {
            color: #34495e;
            font-size: 1.2em;
            margin: 15px 0;
        }
        
        .heart {
            color: #e74c3c;
            font-size: 3em;
            animation: heartbeat 1.5s ease-in-out infinite;
            margin: 20px 0;
        }
        
        @keyframes heartbeat {
            0% { transform: scale(1); }
            50% { transform: scale(1.2); }
            100% { transform: scale(1); }
        }
        
        .spinner {
            width: 50px;
            height: 50px;
            border: 5px solid #f3f3f3;
            border-top: 5px solid #3498db;
            border-radius: 50%;
            margin: 20px auto;
            animation: spin 1s linear infinite;
        }
        
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
        
        .redirect-message {
            color: #7f8c8d;
            font-size: 0.9em;
            margin-top: 20px;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="heart">❤️</div>
        <h1>Thank You!</h1>
        <p>You have been successfully logged out.</p>
        <p>We hope to see you again soon!</p>
        <div class="spinner"></div>
        <p class="redirect-message">Redirecting to login page in <span id="timer">3</span> seconds...</p>
    </div>

    <script>
        // Auto redirect with countdown
        let countdown = 3;
        const timerElement = document.getElementById('timer');
        
        function updateTimer() {
            timerElement.textContent = countdown;
            if (countdown === 0) {
                window.location.href = 'login.php';
            } else {
                countdown--;
                setTimeout(updateTimer, 1000);
            }
        }
        
        // Start the countdown
        setTimeout(updateTimer, 1000);
        
        // Optional: Add a manual redirect link
        setTimeout(function() {
            if (document.querySelector('.container')) {
                const manualLink = document.createElement('p');
                manualLink.innerHTML = '<a href="login.php" style="color: #3498db; text-decoration: none;">Click here if you\'re not redirected automatically</a>';
                document.querySelector('.container').appendChild(manualLink);
            }
        }, 2000);
    </script>
</body>
</html>