<?php
require_once '../config/db.php';
require_once '../includes/leave_functions.php';

if (!isLoggedIn()) {
    header('Location: ../auth/login.php');
    exit();
}

$role = $_SESSION['role'];
// Only allow HR, Admin, and Project Manager
if (!in_array($role, ['hr', 'admin', 'pm'])) {
    header('Location: ../dashboard.php');
    exit();
}

$message = '';
$export_data = [];

// Get all users for dropdown
$users_result = $conn->query("SELECT id, username, full_name FROM users ORDER BY full_name");
$users = $users_result->fetch_all(MYSQLI_ASSOC);

// Handle export request
if (isset($_POST['export_excel']) || isset($_POST['generate_excel'])) {
    $from_date = sanitize($_POST['from_date']);
    $to_date = sanitize($_POST['to_date']);
    $user_id = isset($_POST['user_id']) ? intval($_POST['user_id']) : 0;
    
    if (empty($from_date) || empty($to_date)) {
        $message = '<div class="alert alert-error">Please select from and to dates</div>';
    } else {
        // Build query based on filters
        $where_conditions = ["l.from_date BETWEEN ? AND ?"];
        $params = [$from_date, $to_date];
        $types = "ss";
        
        if ($user_id > 0) {
            $where_conditions[] = "l.user_id = ?";
            $params[] = $user_id;
            $types .= "i";
        }
        
        $where_sql = "WHERE " . implode(" AND ", $where_conditions);
        
        // Get leave data with user details
        $sql = "SELECT 
                    u.id as user_id,
                    u.username,
                    u.full_name,
                    u.department,
                    u.position,
                    l.id as leave_id,
                    l.leave_type,
                    l.from_date,
                    l.to_date,
                    l.days,
                    l.day_type,
                    l.reason,
                    l.status,
                    l.applied_date,
                    l.leave_year,
                    DATE_FORMAT(l.from_date, '%M %Y') as month_year,
                    MONTH(l.from_date) as month_num,
                    YEAR(l.from_date) as year_num
                FROM leaves l
                JOIN users u ON l.user_id = u.id
                $where_sql
                ORDER BY u.full_name, l.from_date DESC";
        
        $stmt = $conn->prepare($sql);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $result = $stmt->get_result();
        $export_data = $result->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        
        // If generate Excel button clicked
        if (isset($_POST['generate_excel'])) {
            generateExcel($export_data, $from_date, $to_date, $user_id, $conn);
        }
    }
}

/**
 * Generate Excel file with leave data
 */
function generateExcel($data, $from_date, $to_date, $user_id, $conn) {
    // Get user name if specific user selected
    $user_name = 'All Employees';
    if ($user_id > 0) {
        $user_stmt = $conn->prepare("SELECT full_name FROM users WHERE id = ?");
        $user_stmt->bind_param("i", $user_id);
        $user_stmt->execute();
        $user_result = $user_stmt->get_result();
        $user_row = $user_result->fetch_assoc();
        $user_name = $user_row['full_name'];
        $user_stmt->close();
    }
    
    // Calculate summary statistics
    $total_leaves = 0;
    $total_days = 0;
    $total_lop = 0;
    $total_lop_days = 0;
    $sick_days = 0;
    $casual_days = 0;
    $approved_count = 0;
    $pending_count = 0;
    $rejected_count = 0;
    
    foreach ($data as $row) {
        $total_leaves++;
        $total_days += $row['days'];
        
        if ($row['leave_type'] == 'LOP') {
            $total_lop++;
            $total_lop_days += $row['days'];
        } elseif ($row['leave_type'] == 'Sick') {
            $sick_days += $row['days'];
        } elseif ($row['leave_type'] == 'Casual') {
            $casual_days += $row['days'];
        }
        
        if ($row['status'] == 'Approved') $approved_count++;
        elseif ($row['status'] == 'Pending') $pending_count++;
        elseif ($row['status'] == 'Rejected') $rejected_count++;
    }
    
    // Group by month for monthly summary
    $monthly_summary = [];
    foreach ($data as $row) {
        $key = $row['year_num'] . '-' . str_pad($row['month_num'], 2, '0', STR_PAD_LEFT);
        if (!isset($monthly_summary[$key])) {
            $monthly_summary[$key] = [
                'month' => $row['month_year'],
                'total' => 0,
                'lop' => 0,
                'sick' => 0,
                'casual' => 0,
                'days' => 0
            ];
        }
        $monthly_summary[$key]['total']++;
        $monthly_summary[$key]['days'] += $row['days'];
        if ($row['leave_type'] == 'LOP') {
            $monthly_summary[$key]['lop'] += $row['days'];
        } elseif ($row['leave_type'] == 'Sick') {
            $monthly_summary[$key]['sick'] += $row['days'];
        } elseif ($row['leave_type'] == 'Casual') {
            $monthly_summary[$key]['casual'] += $row['days'];
        }
    }
    ksort($monthly_summary);
    
    // Group by user for user summary
    $user_summary = [];
    foreach ($data as $row) {
        $uid = $row['user_id'];
        if (!isset($user_summary[$uid])) {
            $user_summary[$uid] = [
                'name' => $row['full_name'],
                'department' => $row['department'] ?? 'N/A',
                'total' => 0,
                'days' => 0,
                'lop' => 0,
                'lop_days' => 0,
                'sick' => 0,
                'casual' => 0
            ];
        }
        $user_summary[$uid]['total']++;
        $user_summary[$uid]['days'] += $row['days'];
        if ($row['leave_type'] == 'LOP') {
            $user_summary[$uid]['lop']++;
            $user_summary[$uid]['lop_days'] += $row['days'];
        } elseif ($row['leave_type'] == 'Sick') {
            $user_summary[$uid]['sick'] += $row['days'];
        } elseif ($row['leave_type'] == 'Casual') {
            $user_summary[$uid]['casual'] += $row['days'];
        }
    }
    
    // Set headers for Excel download
    $filename = "leaves_report_" . date('Y-m-d') . ".xls";
    header("Content-Type: application/vnd.ms-excel");
    header("Content-Disposition: attachment; filename=\"$filename\"");
    header("Pragma: no-cache");
    header("Expires: 0");
    
    // Start Excel content
    echo '<html>';
    echo '<head>';
    echo '<meta charset="UTF-8">';
    echo '<style>';
    echo 'td { mso-number-format:\@; }';
    echo '.date { mso-number-format:"yyyy-mm-dd"; }';
    echo '.number { mso-number-format:"0.00"; }';
    echo '.header { background: #006400; color: white; font-weight: bold; text-align: center; }';
    echo '.subheader { background: #e8f5e9; font-weight: bold; }';
    echo '.lop { color: #c53030; font-weight: bold; }';
    echo '.total { background: #f0f0f0; font-weight: bold; }';
    echo '</style>';
    echo '</head>';
    echo '<body>';
    
    // Title
    echo '<h2>MAKSIM HR - Leave Report</h2>';
    echo '<h3>Period: ' . date('d-m-Y', strtotime($from_date)) . ' to ' . date('d-m-Y', strtotime($to_date)) . '</h3>';
    echo '<h3>Employee: ' . $user_name . '</h3>';
    echo '<h3>Generated on: ' . date('d-m-Y H:i:s') . '</h3>';
    echo '<br>';
    
    // Summary Statistics
    echo '<h3>📊 Summary Statistics</h3>';
    echo '<table border="1" cellpadding="5">';
    echo '<tr><td><strong>Total Leaves Applied:</strong></td><td>' . $total_leaves . '</td></tr>';
    echo '<tr><td><strong>Total Leave Days:</strong></td><td>' . number_format($total_days, 1) . '</td></tr>';
    echo '<tr><td><strong>Total LOP Applications:</strong></td><td>' . $total_lop . '</td></tr>';
    echo '<tr><td class="lop"><strong>Total LOP Days:</strong></td><td class="lop">' . number_format($total_lop_days, 1) . '</td></tr>';
    echo '<tr><td><strong>Sick Leave Days:</strong></td><td>' . number_format($sick_days, 1) . '</td></tr>';
    echo '<tr><td><strong>Casual Leave Days:</strong></td><td>' . number_format($casual_days, 1) . '</td></tr>';
    echo '<tr><td><strong>Approved:</strong></td><td>' . $approved_count . '</td></tr>';
    echo '<tr><td><strong>Pending:</strong></td><td>' . $pending_count . '</td></tr>';
    echo '<tr><td><strong>Rejected:</strong></td><td>' . $rejected_count . '</td></tr>';
    echo '</table>';
    echo '<br>';
    
    // Monthly Summary
    if (!empty($monthly_summary)) {
        echo '<h3>📅 Monthly Summary</h3>';
        echo '<table border="1" cellpadding="5">';
        echo '<tr class="header">';
        echo '<th>Month</th>';
        echo '<th>Total Apps</th>';
        echo '<th>Total Days</th>';
        echo '<th>Sick Days</th>';
        echo '<th>Casual Days</th>';
        echo '<th>LOP Days</th>';
        echo '</tr>';
        
        foreach ($monthly_summary as $summary) {
            echo '<tr>';
            echo '<td>' . $summary['month'] . '</td>';
            echo '<td>' . $summary['total'] . '</td>';
            echo '<td>' . number_format($summary['days'], 1) . '</td>';
            echo '<td>' . number_format($summary['sick'], 1) . '</td>';
            echo '<td>' . number_format($summary['casual'], 1) . '</td>';
            echo '<td class="lop">' . number_format($summary['lop'], 1) . '</td>';
            echo '</tr>';
        }
        echo '</table>';
        echo '<br>';
    }
    
    // User Summary
    if (!empty($user_summary)) {
        echo '<h3>👥 Employee Summary</h3>';
        echo '<table border="1" cellpadding="5">';
        echo '<tr class="header">';
        echo '<th>Employee</th>';
        echo '<th>Department</th>';
        echo '<th>Total Apps</th>';
        echo '<th>Total Days</th>';
        echo '<th>LOP Apps</th>';
        echo '<th>LOP Days</th>';
        echo '<th>Sick Days</th>';
        echo '<th>Casual Days</th>';
        echo '</tr>';
        
        foreach ($user_summary as $summary) {
            echo '<tr>';
            echo '<td><strong>' . $summary['name'] . '</strong></td>';
            echo '<td>' . $summary['department'] . '</td>';
            echo '<td>' . $summary['total'] . '</td>';
            echo '<td>' . number_format($summary['days'], 1) . '</td>';
            echo '<td>' . $summary['lop'] . '</td>';
            echo '<td class="lop">' . number_format($summary['lop_days'], 1) . '</td>';
            echo '<td>' . number_format($summary['sick'], 1) . '</td>';
            echo '<td>' . number_format($summary['casual'], 1) . '</td>';
            echo '</tr>';
        }
        echo '</table>';
        echo '<br>';
    }
    
    // Detailed Leave Data
    echo '<h3>📋 Detailed Leave Applications</h3>';
    echo '<table border="1" cellpadding="5">';
    echo '<tr class="header">';
    echo '<th>Employee</th>';
    echo '<th>Department</th>';
    echo '<th>Leave Type</th>';
    echo '<th>From Date</th>';
    echo '<th>To Date</th>';
    echo '<th>Days</th>';
    echo '<th>Day Type</th>';
    echo '<th>Status</th>';
    echo '<th>Reason</th>';
    echo '<th>Leave Year</th>';
    echo '<th>Month</th>';
    echo '</tr>';
    
    foreach ($data as $row) {
        $lop_class = ($row['leave_type'] == 'LOP') ? ' class="lop"' : '';
        echo '<tr>';
        echo '<td>' . htmlspecialchars($row['full_name']) . '</td>';
        echo '<td>' . htmlspecialchars($row['department'] ?? 'N/A') . '</td>';
        echo '<td' . $lop_class . '>' . htmlspecialchars($row['leave_type']) . '</td>';
        echo '<td class="date">' . $row['from_date'] . '</td>';
        echo '<td class="date">' . $row['to_date'] . '</td>';
        echo '<td class="number">' . number_format($row['days'], 1) . '</td>';
        echo '<td>' . ($row['day_type'] == 'half' ? 'Half Day' : 'Full Day') . '</td>';
        echo '<td>' . $row['status'] . '</td>';
        echo '<td>' . htmlspecialchars($row['reason']) . '</td>';
        echo '<td>' . $row['leave_year'] . '</td>';
        echo '<td>' . $row['month_year'] . '</td>';
        echo '</tr>';
    }
    
    // Totals row
    echo '<tr class="total">';
    echo '<td colspan="4"><strong>TOTAL</strong></td>';
    echo '<td><strong>' . number_format($total_days, 1) . ' days</strong></td>';
    echo '<td colspan="6"></td>';
    echo '</tr>';
    
    echo '</table>';
    echo '<br>';
    echo '<hr>';
    echo '<p><em>Generated by MAKSIM HR System</em></p>';
    echo '</body>';
    echo '</html>';
    exit();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Export Leaves - MAKSIM HR</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="../assets/css/style.css">
    <style>
        .export-container {
            max-width: 800px;
            margin: 0 auto;
        }
        .filter-card {
            background: white;
            border-radius: 15px;
            padding: 30px;
            box-shadow: 0 5px 15px rgba(0,0,0,0.05);
            margin-bottom: 30px;
        }
        .filter-title {
            color: #2d3748;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .filter-title i {
            color: #006400;
            font-size: 24px;
        }
        .form-group {
            margin-bottom: 20px;
        }
        .form-label {
            display: block;
            margin-bottom: 8px;
            color: #4a5568;
            font-weight: 500;
        }
        .form-control {
            width: 100%;
            padding: 12px 16px;
            border: 2px solid #e2e8f0;
            border-radius: 10px;
            font-size: 16px;
        }
        .form-control:focus {
            outline: none;
            border-color: #006400;
        }
        .btn-export {
            background: linear-gradient(135deg, #006400 0%, #2c9218 100%);
            color: white;
            border: none;
            padding: 14px 30px;
            border-radius: 10px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            width: 100%;
            transition: transform 0.2s;
        }
        .btn-export:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 20px rgba(0,100,0,0.3);
        }
        .btn-preview {
            background: #4299e1;
            color: white;
            border: none;
            padding: 14px 30px;
            border-radius: 10px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            width: 100%;
            margin-top: 10px;
        }
        .date-hint {
            font-size: 12px;
            color: #718096;
            margin-top: 5px;
        }
        .preview-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
        }
        .preview-table th {
            background: #f7fafc;
            padding: 12px;
            text-align: left;
            border-bottom: 2px solid #e2e8f0;
        }
        .preview-table td {
            padding: 10px;
            border-bottom: 1px solid #e2e8f0;
        }
        .preview-table .lop-row {
            background: #fff5f5;
        }
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 15px;
            margin: 20px 0;
        }
        .stat-box {
            background: #f7fafc;
            padding: 15px;
            border-radius: 10px;
            text-align: center;
        }
        .stat-box.lop {
            background: #fff5f5;
            border-left: 4px solid #c53030;
        }
        .stat-value {
            font-size: 24px;
            font-weight: bold;
            color: #2d3748;
        }
        .stat-label {
            font-size: 12px;
            color: #718096;
        }
    </style>
</head>
<body>
    <?php include '../includes/header.php'; ?>
    
    <div class="app-main">
        <?php include '../includes/sidebar.php'; ?>
        
        <div class="main-content">
            <div class="export-container">
                <h2 class="page-title">
                    <i class="fas fa-file-excel"></i> Export Leaves Report
                </h2>
                
                <?php echo $message; ?>
                
                <div class="filter-card">
                    <div class="filter-title">
                        <i class="fas fa-filter"></i>
                        <h3>Select Date Range</h3>
                    </div>
                    
                    <form method="POST" action="">
                        <div class="form-row">
                            <div class="form-group">
                                <label class="form-label">From Date *</label>
                                <input type="date" name="from_date" id="from_date" class="form-control" 
                                       value="<?php echo date('Y-m-01'); ?>" required>
                                <div class="date-hint">Start of current month by default</div>
                            </div>
                            
                            <div class="form-group">
                                <label class="form-label">To Date *</label>
                                <input type="date" name="to_date" id="to_date" class="form-control" 
                                       value="<?php echo date('Y-m-d'); ?>" required>
                                <div class="date-hint">Today by default</div>
                            </div>
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label">Select Employee</label>
                            <select name="user_id" class="form-control">
                                <option value="0">All Employees</option>
                                <?php foreach ($users as $user): ?>
                                <option value="<?php echo $user['id']; ?>">
                                    <?php echo htmlspecialchars($user['full_name']); ?> (<?php echo $user['username']; ?>)
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <button type="submit" name="export_excel" class="btn-export">
                            <i class="fas fa-search"></i> Preview Data
                        </button>
                        
                        <?php if (!empty($export_data)): ?>
                        <button type="submit" name="generate_excel" class="btn-preview">
                            <i class="fas fa-file-excel"></i> Export to Excel
                        </button>
                        <?php endif; ?>
                    </form>
                </div>
                
                <?php if (!empty($export_data)): ?>
                <!-- Statistics -->
                <div class="stats-grid">
                    <?php
                    $total_days = 0;
                    $total_lop = 0;
                    $total_apps = count($export_data);
                    
                    foreach ($export_data as $row) {
                        $total_days += $row['days'];
                        if ($row['leave_type'] == 'LOP') {
                            $total_lop += $row['days'];
                        }
                    }
                    ?>
                    <div class="stat-box">
                        <div class="stat-value"><?php echo $total_apps; ?></div>
                        <div class="stat-label">Total Applications</div>
                    </div>
                    <div class="stat-box">
                        <div class="stat-value"><?php echo number_format($total_days, 1); ?></div>
                        <div class="stat-label">Total Days</div>
                    </div>
                    <div class="stat-box lop">
                        <div class="stat-value" style="color: #c53030;"><?php echo number_format($total_lop, 1); ?></div>
                        <div class="stat-label">Total LOP Days</div>
                    </div>
                </div>
                
                <!-- Preview Table -->
                <div class="card">
                    <div class="card-header">
                        <h3 class="card-title">
                            <i class="fas fa-eye"></i> Preview (<?php echo count($export_data); ?> records)
                        </h3>
                        <span style="color: #718096;">
                            <?php echo date('d-m-Y', strtotime($_POST['from_date'])); ?> to 
                            <?php echo date('d-m-Y', strtotime($_POST['to_date'])); ?>
                        </span>
                    </div>
                    
                    <div class="table-container">
                        <table class="preview-table">
                            <thead>
                                <tr>
                                    <th>Employee</th>
                                    <th>Type</th>
                                    <th>From</th>
                                    <th>To</th>
                                    <th>Days</th>
                                    <th>Status</th>
                                    <th>Month</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php 
                                $display_count = 0;
                                foreach ($export_data as $row): 
                                    if ($display_count++ >= 20) break;
                                    $is_lop = $row['leave_type'] == 'LOP';
                                ?>
                                <tr class="<?php echo $is_lop ? 'lop-row' : ''; ?>">
                                    <td><strong><?php echo htmlspecialchars($row['full_name']); ?></strong></td>
                                    <td>
                                        <span style="<?php echo $is_lop ? 'color: #c53030; font-weight: bold;' : ''; ?>">
                                            <?php echo $row['leave_type']; ?>
                                        </span>
                                    </td>
                                    <td><?php echo $row['from_date']; ?></td>
                                    <td><?php echo $row['to_date']; ?></td>
                                    <td><?php echo $row['days']; ?></td>
                                    <td>
                                        <span class="status-badge status-<?php echo strtolower($row['status']); ?>">
                                            <?php echo $row['status']; ?>
                                        </span>
                                    </td>
                                    <td><?php echo $row['month_year']; ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                        <?php if (count($export_data) > 20): ?>
                        <div style="text-align: center; padding: 15px; color: #718096;">
                            <i class="fas fa-info-circle"></i> 
                            Showing first 20 of <?php echo count($export_data); ?> records. Export to Excel to see all.
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    
    <script>
    document.addEventListener('DOMContentLoaded', function() {
        var today = new Date();
        var firstDay = new Date(today.getFullYear(), today.getMonth(), 1);
        
        document.getElementById('from_date').value = firstDay.toISOString().split('T')[0];
        document.getElementById('to_date').value = today.toISOString().split('T')[0];
    });
    
    document.getElementById('to_date').addEventListener('change', function() {
        var fromDate = document.getElementById('from_date').value;
        var toDate = this.value;
        
        if (fromDate && toDate && toDate < fromDate) {
            alert('To date cannot be before from date');
            this.value = fromDate;
        }
    });
    
    document.getElementById('from_date').addEventListener('change', function() {
        var fromDate = this.value;
        var toDate = document.getElementById('to_date').value;
        
        if (fromDate && toDate && toDate < fromDate) {
            document.getElementById('to_date').value = fromDate;
        }
    });
    </script>
    
    <script src="../assets/js/app.js"></script>
</body>
</html>