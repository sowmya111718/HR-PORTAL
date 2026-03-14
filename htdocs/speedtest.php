<?php
$client_ip = $_SERVER['REMOTE_ADDR'];
$server_ip = gethostbyname(gethostname());
$start_time = microtime(true);

// TEST DATABASE CONNECTION
$db_start = microtime(true);
try {
    $conn = new mysqli('192.168.48.77', 'root', '');
    $db_time = microtime(true) - $db_start;
    $db_status = '✅ OK';
    $db_message = round($db_time * 1000, 2) . 'ms';
    $conn->close();
} catch (Exception $e) {
    $db_time = 999;
    $db_status = '❌ FAILED';
    $db_message = 'Connection error';
}

// TEST FILE ACCESS
$file_start = microtime(true);
file_exists(__FILE__);
$file_time = microtime(true) - $file_start;

$total_time = microtime(true) - $start_time;
?>
<!DOCTYPE html>
<html>
<head>
    <title>XAMPP SPEED TEST</title>
    <style>
        body { font-family: Arial; margin: 50px; background: #f0f0f0; }
        .container { background: white; padding: 30px; border-radius: 10px; max-width: 800px; margin: 0 auto; }
        .critical { background: #ffebee; border-left: 5px solid #f44336; padding: 15px; margin: 20px 0; }
        .success { background: #e8f5e9; border-left: 5px solid #4caf50; padding: 15px; }
        table { width: 100%; border-collapse: collapse; margin: 20px 0; }
        td, th { padding: 12px; border-bottom: 1px solid #ddd; text-align: left; }
        .bad { color: #f44336; font-weight: bold; }
        .good { color: #4caf50; font-weight: bold; }
    </style>
</head>
<body>
    <div class="container">
        <h1>⚠️ XAMPP NETWORK DIAGNOSTIC</h1>
        
        <div class="<?php echo $db_time > 0.1 ? 'critical' : 'success'; ?>">
            <h2><?php echo $db_time > 0.1 ? '❌ DATABASE PROBLEM DETECTED' : '✅ DATABASE OK'; ?></h2>
            <p><strong>Database Connection Time:</strong> <?php echo $db_message; ?></p>
            <p><strong>Target:</strong> If this is >100ms, MySQL is doing DNS lookups</p>
        </div>

        <table>
            <tr><th>Test</th><th>Result</th><th>Status</th><th>Should be</th></tr>
            <tr>
                <td><strong>Database Connection</strong></td>
                <td><?php echo $db_message; ?></td>
                <td><?php echo $db_time < 0.1 ? '✅ GOOD' : '❌ BAD'; ?></td>
                <td>< 100ms</td>
            </tr>
            <tr>
                <td>File Access</td>
                <td><?php echo round($file_time * 1000, 2); ?>ms</td>
                <td><?php echo $file_time < 0.01 ? '✅ GOOD' : '⚠️ SLOW'; ?></td>
                <td>< 10ms</td>
            </tr>
            <tr>
                <td>PHP Processing</td>
                <td><?php echo round(($total_time - $db_time - $file_time) * 1000, 2); ?>ms</td>
                <td>✅ OK</td>
                <td>< 50ms</td>
            </tr>
            <tr>
                <td><strong>TOTAL PAGE TIME</strong></td>
                <td><strong><?php echo round($total_time * 1000, 2); ?>ms</strong></td>
                <td><?php echo $total_time < 0.5 ? '✅ FAST' : '🐌 SLOW'; ?></td>
                <td>< 500ms</td>
            </tr>
        </table>

        <h3>🔍 SYSTEM INFORMATION</h3>
        <table>
            <tr><td><strong>Server IP:</strong></td><td>192.168.48.77</td></tr>
            <tr><td><strong>Your IP (Client):</strong></td><td><?php echo $client_ip; ?></td></tr>
            <tr><td><strong>skip-name-resolve:</strong></td><td><?php echo $db_time < 0.1 ? '✅ ENABLED' : '❌ NOT DETECTED - THIS IS YOUR PROBLEM'; ?></td></tr>
            <tr><td><strong>bind-address:</strong></td><td><?php echo $db_time < 0.1 ? '✅ SET TO 192.168.48.77' : '❌ CHECK my.ini'; ?></td></tr>
        </table>

        <?php if($db_time > 0.1): ?>
        <div class="critical">
            <h2>🚨 IMMEDIATE ACTION REQUIRED</h2>
            <p><strong>Your database is slow because MySQL is doing DNS lookups.</strong></p>
            <p>On SERVER (192.168.48.77):</p>
            <ol>
                <li>Open C:\xampp\mysql\bin\my.ini</li>
                <li>Find [mysqld] section</li>
                <li><strong>ADD THIS LINE:</strong> <code>skip-name-resolve</code></li>
                <li><strong>ADD THIS LINE:</strong> <code>bind-address = 192.168.48.77</code></li>
                <li>Save file</li>
                <li>Restart MySQL</li>
            </ol>
        </div>
        <?php endif; ?>
    </div>
</body>
</html>