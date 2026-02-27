<?php
// Enable error reporting
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once 'config/db.php';
require_once 'includes/leave_functions.php';

if (!isLoggedIn()) {
    header('Location: auth/login.php');
    exit();
}

$user_id = $_SESSION['user_id'];
$role = $_SESSION['role'];

// Only allow admins to run fixes
$is_admin = in_array($role, ['admin', 'hr']);

echo "<!DOCTYPE html>
<html>
<head>
    <title>LOP Diagnostic & Fix Tool</title>
    <style>
        body { font-family: Arial; padding: 20px; background: #f5f5f5; }
        .container { max-width: 1200px; margin: 0 auto; background: white; padding: 20px; border-radius: 10px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
        h1 { color: #333; }
        h2 { color: #006400; margin-top: 30px; }
        table { border-collapse: collapse; width: 100%; margin: 10px 0; }
        th { background: #006400; color: white; padding: 10px; text-align: left; }
        td { padding: 10px; border-bottom: 1px solid #ddd; }
        .success { background: #d4edda; color: #155724; padding: 15px; border-radius: 5px; margin: 10px 0; }
        .warning { background: #fff3cd; color: #856404; padding: 15px; border-radius: 5px; margin: 10px 0; }
        .error { background: #f8d7da; color: #721c24; padding: 15px; border-radius: 5px; margin: 10px 0; }
        .btn { background: #006400; color: white; padding: 10px 20px; text-decoration: none; border-radius: 5px; display: inline-block; margin: 10px 5px; }
        .btn-danger { background: #dc3545; }
        .btn-warning { background: #ffc107; color: black; }
        pre { background: #f4f4f4; padding: 10px; border-radius: 5px; overflow-x: auto; }
    </style>
</head>
<body>
<div class='container'>";

echo "<h1>🔍 LOP Diagnostic & Fix Tool</h1>";
echo "<p>User ID: <strong>$user_id</strong> | Role: <strong>" . strtoupper($role) . "</strong></p>";

// ============================================
// PART 1: DIAGNOSTIC - Show all leave entries
// ============================================
echo "<h2>📋 All Leave Entries for User ID: $user_id</h2>";

$sql = "SELECT id, leave_type, from_date, to_date, days, status, reason, leave_year 
        FROM leaves 
        WHERE user_id = $user_id 
        ORDER BY id DESC";

$result = $conn->query($sql);

if ($result && $result->num_rows > 0) {
    echo "<table>";
    echo "<tr><th>ID</th><th>Type</th><th>From</th><th>To</th><th>Days</th><th>Status</th><th>Reason</th><th>Leave Year</th></tr>";
    
    $has_blank = false;
    $has_lop = false;
    
    while ($row = $result->fetch_assoc()) {
        $type = $row['leave_type'];
        $is_blank = ($type === '' || $type === null);
        $is_lop = ($type === 'LOP');
        
        if ($is_blank) $has_blank = true;
        if ($is_lop) $has_lop = true;
        
        $bg_color = $is_blank ? 'style="background: #fff3cd"' : ($is_lop ? 'style="background: #d4edda"' : '');
        
        echo "<tr $bg_color>";
        echo "<td>" . $row['id'] . "</td>";
        echo "<td><strong>" . ($type ?: '🔴 BLANK') . "</strong></td>";
        echo "<td>" . $row['from_date'] . "</td>";
        echo "<td>" . $row['to_date'] . "</td>";
        echo "<td>" . $row['days'] . "</td>";
        echo "<td>" . $row['status'] . "</td>";
        echo "<td>" . htmlspecialchars($row['reason']) . "</td>";
        echo "<td>" . $row['leave_year'] . "</td>";
        echo "</tr>";
    }
    echo "</table>";
    
    if ($has_blank) {
        echo "<div class='warning'>⚠️ Found entries with BLANK leave_type that need to be fixed!</div>";
    }
    if ($has_lop) {
        echo "<div class='success'>✅ Found entries with LOP type that are correct.</div>";
    }
} else {
    echo "<p>No leave entries found.</p>";
}

// ============================================
// PART 2: CHECK CURRENT LOP COUNT
// ============================================
echo "<h2>📊 Current LOP Calculations</h2>";

// Direct SQL query
$sql_direct = "SELECT COALESCE(SUM(days), 0) as total 
               FROM leaves 
               WHERE user_id = $user_id 
               AND leave_type = 'LOP' 
               AND status = 'Approved'";
$result_direct = $conn->query($sql_direct);
$row_direct = $result_direct->fetch_assoc();
$direct_total = $row_direct['total'];

// Using functions
$func_total = getLOPCount($conn, $user_id);
$func_month = getCurrentMonthLOPUsage($conn, $user_id);

echo "<table>";
echo "<tr><th>Method</th><th>Value</th><th>Status</th></tr>";
echo "<tr><td>Direct SQL Query</td><td><strong>$direct_total days</strong></td><td>" . ($direct_total > 0 ? '✅' : '❌') . "</td></tr>";
echo "<tr><td>getLOPCount() function</td><td><strong>$func_total days</strong></td><td>" . ($func_total > 0 ? '✅' : '❌') . "</td></tr>";
echo "<tr><td>getCurrentMonthLOPUsage()</td><td><strong>$func_month days</strong></td><td>" . ($func_month > 0 ? '✅' : '❌') . "</td></tr>";
echo "</table>";

// ============================================
// PART 3: FIX BLANK ENTRIES (if admin)
// ============================================
if ($is_admin) {
    echo "<h2>🛠️ Fix Blank Leave Type Entries</h2>";
    
    // Check for entries with blank type that should be LOP
    $check_blank = "SELECT COUNT(*) as count FROM leaves 
                    WHERE user_id = $user_id 
                    AND (leave_type = '' OR leave_type IS NULL)
                    AND reason LIKE '%Loss of Pay%'";
    $result_blank = $conn->query($check_blank);
    $row_blank = $result_blank->fetch_assoc();
    $blank_count = $row_blank['count'];
    
    if ($blank_count > 0) {
        echo "<div class='warning'>⚠️ Found <strong>$blank_count</strong> entries with blank leave_type that should be LOP.</div>";
        
        // Show them
        $show_blank = "SELECT id, from_date, days, reason FROM leaves 
                       WHERE user_id = $user_id 
                       AND (leave_type = '' OR leave_type IS NULL)
                       AND reason LIKE '%Loss of Pay%'";
        $result_show = $conn->query($show_blank);
        
        echo "<h3>Entries to fix:</h3>";
        echo "<table>";
        echo "<tr><th>ID</th><th>Date</th><th>Days</th><th>Reason</th></tr>";
        while ($row = $result_show->fetch_assoc()) {
            echo "<tr>";
            echo "<td>" . $row['id'] . "</td>";
            echo "<td>" . $row['from_date'] . "</td>";
            echo "<td>" . $row['days'] . "</td>";
            echo "<td>" . htmlspecialchars($row['reason']) . "</td>";
            echo "</tr>";
        }
        echo "</table>";
        
        // Fix button
        if (isset($_POST['fix_blank'])) {
            $fix_sql = "UPDATE leaves 
                        SET leave_type = 'LOP' 
                        WHERE user_id = $user_id 
                        AND (leave_type = '' OR leave_type IS NULL)
                        AND reason LIKE '%Loss of Pay%'";
            
            if ($conn->query($fix_sql)) {
                $affected = $conn->affected_rows;
                echo "<div class='success'>✅ Fixed <strong>$affected</strong> entries! <a href='?'>Refresh page</a></div>";
            } else {
                echo "<div class='error'>❌ Error fixing entries: " . $conn->error . "</div>";
            }
        } else {
            echo "<form method='POST'>";
            echo "<button type='submit' name='fix_blank' class='btn btn-warning' onclick='return confirm(\"Fix these blank entries?\")'>🔧 Fix Blank Entries Now</button>";
            echo "</form>";
        }
    } else {
        echo "<div class='success'>✅ No blank entries found that need fixing!</div>";
    }
    
    // ============================================
    // PART 4: INSERT TEST LOP (if needed)
    // ============================================
    echo "<h2>🧪 Insert Test LOP Record</h2>";
    
    if (isset($_POST['insert_test'])) {
        $leave_year = getCurrentLeaveYear();
        $today = date('Y-m-d');
        
        $insert_sql = "INSERT INTO leaves (user_id, leave_type, from_date, to_date, days, day_type, reason, status, leave_year) 
                       VALUES ($user_id, 'LOP', '$today', '$today', 2.5, 'full', 'Test LOP record from fix tool', 'Approved', '{$leave_year['year_label']}')";
        
        if ($conn->query($insert_sql)) {
            $new_id = $conn->insert_id;
            echo "<div class='success'>✅ Test LOP record inserted! ID: $new_id, Days: 2.5 <a href='?'>Refresh page</a></div>";
        } else {
            echo "<div class='error'>❌ Error inserting test record: " . $conn->error . "</div>";
        }
    } else {
        echo "<form method='POST'>";
        echo "<button type='submit' name='insert_test' class='btn' onclick='return confirm(\"Insert test LOP record?\")'>➕ Insert Test LOP (2.5 days)</button>";
        echo "</form>";
    }
} else {
    echo "<div class='warning'>⚠️ You need admin/HR privileges to fix entries.</div>";
}

// ============================================
// PART 5: FINAL VERIFICATION
// ============================================
echo "<h2>✅ Final LOP Count</h2>";

$final_sql = "SELECT COALESCE(SUM(days), 0) as total 
              FROM leaves 
              WHERE user_id = $user_id 
              AND leave_type = 'LOP' 
              AND status = 'Approved'";
$final_result = $conn->query($final_sql);
$final_row = $final_result->fetch_assoc();
$final_total = $final_row['total'];

echo "<div style='font-size: 24px; text-align: center; padding: 20px; background: #e8f5e9; border-radius: 10px;'>";
echo "Total LOP Days: <strong style='font-size: 48px; color: #c53030;'>$final_total</strong>";
echo "</div>";

echo "<p style='margin-top: 20px;'>";
echo "<a href='dashboard.php' class='btn'>📊 Go to Dashboard</a> ";
echo "<a href='leaves/leaves.php' class='btn'>📝 Go to Leave Management</a> ";
echo "<a href='?' class='btn'>🔄 Refresh This Page</a>";
echo "</p>";

echo "</div></body></html>";
?>