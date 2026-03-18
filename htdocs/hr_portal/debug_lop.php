<?php
require_once 'config/db.php';
require_once 'includes/leave_functions.php';

if (!isLoggedIn()) {
    die('Please login first');
}

$user_id = $_SESSION['user_id'];

echo "<h1>LOP Debug - User ID: $user_id</h1>";

// Check all LOP entries
$sql = "SELECT * FROM leaves WHERE user_id = $user_id AND leave_type = 'LOP'";
$result = $conn->query($sql);

if ($result->num_rows > 0) {
    echo "<h2>LOP Entries Found:</h2>";
    echo "<table border='1' cellpadding='5'>";
    echo "<tr><th>ID</th><th>From</th><th>To</th><th>Days</th><th>Status</th><th>Leave Year</th></tr>";
    while ($row = $result->fetch_assoc()) {
        echo "<tr>";
        echo "<td>" . $row['id'] . "</td>";
        echo "<td>" . $row['from_date'] . "</td>";
        echo "<td>" . $row['to_date'] . "</td>";
        echo "<td>" . $row['days'] . "</td>";
        echo "<td>" . $row['status'] . "</td>";
        echo "<td>" . $row['leave_year'] . "</td>";
        echo "</tr>";
    }
    echo "</table>";
} else {
    echo "<p>No LOP entries found in database!</p>";
}

// Calculate totals
$lop_total = getLOPCount($conn, $user_id);
$lop_month = getCurrentMonthLOPUsage($conn, $user_id);

echo "<h2>Function Results:</h2>";
echo "<p>Yearly LOP: $lop_total</p>";
echo "<p>Monthly LOP: $lop_month</p>";
?>