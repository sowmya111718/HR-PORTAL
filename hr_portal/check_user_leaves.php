<?php
require_once 'config/db.php';
require_once 'includes/leave_functions.php';

if (!isLoggedIn()) {
    die('Please login first');
}

$user_id = $_SESSION['user_id'];

echo "<h1>All Leave Applications for User ID: $user_id</h1>";

// Check all leaves for this user
$sql = "SELECT * FROM leaves WHERE user_id = $user_id ORDER BY applied_date DESC";
$result = $conn->query($sql);

if ($result->num_rows > 0) {
    echo "<table border='1' cellpadding='5'>";
    echo "<tr><th>ID</th><th>Type</th><th>From</th><th>To</th><th>Days</th><th>Status</th><th>Reason</th><th>Leave Year</th></tr>";
    while ($row = $result->fetch_assoc()) {
        $color = $row['leave_type'] == 'LOP' ? 'style="background: #ffebee"' : '';
        echo "<tr $color>";
        echo "<td>" . $row['id'] . "</td>";
        echo "<td>" . $row['leave_type'] . "</td>";
        echo "<td>" . $row['from_date'] . "</td>";
        echo "<td>" . $row['to_date'] . "</td>";
        echo "<td>" . $row['days'] . "</td>";
        echo "<td>" . $row['status'] . "</td>";
        echo "<td>" . $row['reason'] . "</td>";
        echo "<td>" . $row['leave_year'] . "</td>";
        echo "</tr>";
    }
    echo "</table>";
} else {
    echo "<p>No leave applications found!</p>";
}

echo "<br><a href='leaves/leaves.php'>Go to Leave Management</a>";
?>