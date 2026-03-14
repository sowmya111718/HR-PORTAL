<?php
require_once '../config/db.php';
require_once '../includes/leave_functions.php';
require_once '../includes/icon_functions.php';

date_default_timezone_set('Asia/Kolkata');

if (!isLoggedIn()) {
    header('Location: ../auth/login.php');
    exit();
}

$role = $_SESSION['role'];
$user_id = $_SESSION['user_id'];

// Only HR, Admin, PM allowed
if (!in_array($role, ['hr', 'admin', 'pm'])) {
    header('Location: ../dashboard.php');
    exit();
}

$message = '';

// Ensure holidays table exists
$conn->query("CREATE TABLE IF NOT EXISTS holidays (
    id INT AUTO_INCREMENT PRIMARY KEY,
    holiday_date DATE NOT NULL UNIQUE,
    holiday_name VARCHAR(100) NOT NULL,
    created_by INT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)");

// Seed default holidays if table is empty
$count_check = $conn->query("SELECT COUNT(*) as cnt FROM holidays")->fetch_assoc();
if ($count_check['cnt'] == 0) {
    $defaults = getHolidayList();
    $year = date('Y');
    foreach ([$year, $year + 1] as $y) {
        foreach ($defaults as $md => $name) {
            $full_date = $y . '-' . $md;
            $conn->query("INSERT IGNORE INTO holidays (holiday_date, holiday_name, created_by) VALUES ('$full_date', '$name', $user_id)");
        }
    }
}

// Add holiday
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_holiday'])) {
    $holiday_date = sanitize($_POST['holiday_date']);
    $holiday_name = strtoupper(trim(sanitize($_POST['holiday_name'])));

    if (empty($holiday_date) || empty($holiday_name)) {
        $message = '<div class="alert alert-error"><i class="icon-error"></i> Both date and name are required.</div>';
    } else {
        $stmt = $conn->prepare("INSERT INTO holidays (holiday_date, holiday_name, created_by) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE holiday_name = VALUES(holiday_name), created_by = VALUES(created_by)");
        $stmt->bind_param("ssi", $holiday_date, $holiday_name, $user_id);
        if ($stmt->execute()) {
            $lop_removed = 0;
            
            // If the holiday date is today or in the past, remove any auto-generated LOPs for that date
            if ($holiday_date <= date('Y-m-d')) {
                $del_lop = $conn->prepare("DELETE FROM leaves WHERE from_date = ? AND leave_type = 'LOP' AND reason LIKE 'Auto-generated LOP%'");
                $del_lop->bind_param("s", $holiday_date);
                $del_lop->execute();
                $lop_removed = $del_lop->affected_rows;
                $del_lop->close();
            }
            
            $lop_msg = $lop_removed > 0 ? " <strong>{$lop_removed} auto-generated LOP(s) removed</strong> for this date." : "";
            $message = '<div class="alert alert-success"><i class="icon-success"></i> Holiday <strong>' . htmlspecialchars($holiday_name) . '</strong> on ' . $holiday_date . ' added successfully.' . $lop_msg . ' No LOP will be generated for this date.</div>';
        } else {
            $message = '<div class="alert alert-error"><i class="icon-error"></i> Error adding holiday: ' . $conn->error . '</div>';
        }
        $stmt->close();
    }
}

// Delete holiday
if (isset($_GET['delete']) && in_array($role, ['hr', 'admin', 'pm'])) {
    $holiday_id = intval($_GET['delete']);
    $stmt = $conn->prepare("DELETE FROM holidays WHERE id = ?");
    $stmt->bind_param("i", $holiday_id);
    if ($stmt->execute()) {
        $message = '<div class="alert alert-success"><i class="icon-success"></i> Holiday removed successfully.</div>';
    } else {
        $message = '<div class="alert alert-error"><i class="icon-error"></i> Error removing holiday.</div>';
    }
    $stmt->close();
}

// Get all holidays
$holidays_result = $conn->query("SELECT h.*, u.full_name as added_by_name FROM holidays h LEFT JOIN users u ON h.created_by = u.id ORDER BY h.holiday_date ASC");

$page_title = "Manage Holidays - MAKSIM HR";
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $page_title; ?></title>
    <?php include '../includes/head.php'; ?>
    <style>
        .holiday-card {
            background: white;
            border-radius: 12px;
            padding: 25px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.06);
            margin-bottom: 25px;
        }
        .holiday-card h3 {
            color: #2d3748;
            margin-bottom: 20px;
            font-size: 18px;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .form-row {
            display: flex;
            gap: 15px;
            align-items: flex-end;
            flex-wrap: wrap;
        }
        .form-group {
            flex: 1;
            min-width: 200px;
        }
        .form-label {
            display: block;
            font-weight: 600;
            color: #4a5568;
            margin-bottom: 6px;
            font-size: 14px;
        }
        .form-control {
            width: 100%;
            padding: 10px 14px;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            font-size: 14px;
            color: #2d3748;
            box-sizing: border-box;
        }
        .form-control:focus {
            outline: none;
            border-color: #006400;
            box-shadow: 0 0 0 3px rgba(0,100,0,0.1);
        }
        .btn-add {
            background: #006400;
            color: white;
            border: none;
            padding: 10px 24px;
            border-radius: 8px;
            font-weight: 600;
            cursor: pointer;
            font-size: 14px;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            white-space: nowrap;
        }
        .btn-add:hover { background: #004d00; }
        .btn-del {
            background: #f56565;
            color: white;
            border: none;
            padding: 5px 12px;
            border-radius: 6px;
            font-size: 12px;
            cursor: pointer;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 4px;
        }
        .btn-del:hover { background: #e53e3e; }
        .holiday-table { width: 100%; border-collapse: collapse; }
        .holiday-table th {
            background: #006400;
            color: white;
            padding: 12px 15px;
            text-align: left;
            font-size: 13px;
        }
        .holiday-table td {
            padding: 12px 15px;
            border-bottom: 1px solid #e2e8f0;
            font-size: 14px;
            color: #2d3748;
        }
        .holiday-table tr:hover td { background: #f7fafc; }
        .holiday-table tr:last-child td { border-bottom: none; }
        .date-badge {
            background: #ebf8ff;
            color: #2c5282;
            padding: 3px 10px;
            border-radius: 12px;
            font-weight: 600;
            font-size: 13px;
        }
        .name-badge {
            background: #f3e8ff;
            color: #553c9a;
            padding: 3px 10px;
            border-radius: 12px;
            font-weight: 600;
            font-size: 13px;
        }
        .past-row td { color: #a0aec0; }
        .today-row td { background: #f0fff4 !important; font-weight: 600; }
        .future-row td { color: #2d3748; }
        .note-box {
            background: #fffbeb;
            border: 1px solid #f6e05e;
            border-radius: 8px;
            padding: 12px 16px;
            font-size: 13px;
            color: #744210;
            margin-bottom: 20px;
        }
    </style>
</head>
<body>
    <?php include '../includes/header.php'; ?>
    <div class="app-main">
        <?php include '../includes/sidebar.php'; ?>
        <div class="main-content">
            <h2 class="page-title">🎉 Manage Holidays</h2>

            <?php echo $message; ?>

            <div class="note-box">
                <strong>⚠️ Important:</strong> Adding a holiday here will prevent Auto-LOP from being generated for that date and exempt employees from timesheet submission. This applies to all employees.
            </div>

            <!-- Add Holiday Form -->
            <div class="holiday-card">
                <h3>➕ Add New Holiday</h3>
                <form method="POST" action="">
                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label">Holiday Date *</label>
                            <input type="date" name="holiday_date" class="form-control" required>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Holiday Name *</label>
                            <input type="text" name="holiday_name" class="form-control" placeholder="e.g. DIWALI" required>
                        </div>
                        <div>
                            <button type="submit" name="add_holiday" class="btn-add">
                                ➕ Add Holiday
                            </button>
                        </div>
                    </div>
                </form>
            </div>

            <!-- Holidays List -->
            <div class="holiday-card">
                <h3>📅 All Holidays</h3>
                <?php if ($holidays_result && $holidays_result->num_rows > 0): ?>
                <table class="holiday-table">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Date</th>
                            <th>Day</th>
                            <th>Holiday Name</th>
                            <th>Added By</th>
                            <th>Added On</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        $today = date('Y-m-d');
                        $i = 1;
                        while ($h = $holidays_result->fetch_assoc()):
                            $row_class = '';
                            if ($h['holiday_date'] < $today) $row_class = 'past-row';
                            elseif ($h['holiday_date'] == $today) $row_class = 'today-row';
                            else $row_class = 'future-row';
                        ?>
                        <tr class="<?php echo $row_class; ?>">
                            <td><?php echo $i++; ?></td>
                            <td><span class="date-badge"><?php echo date('d M Y', strtotime($h['holiday_date'])); ?></span></td>
                            <td><?php echo date('l', strtotime($h['holiday_date'])); ?></td>
                            <td><span class="name-badge"><?php echo htmlspecialchars($h['holiday_name']); ?></span></td>
                            <td><?php echo htmlspecialchars($h['added_by_name'] ?? 'System'); ?></td>
                            <td><?php echo date('d M Y', strtotime($h['created_at'])); ?></td>
                            <td>
                                <a href="?delete=<?php echo $h['id']; ?>" class="btn-del"
                                   onclick="return confirm('Remove <?php echo htmlspecialchars($h['holiday_name']); ?> on <?php echo $h['holiday_date']; ?>? Employees may get LOP if they missed timesheet for this date.')">
                                    🗑️ Remove
                                </a>
                            </td>
                        </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
                <?php else: ?>
                <p style="color: #718096; text-align: center; padding: 30px;">No holidays found. Add one above.</p>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <script src="../assets/js/app.js"></script>
</body>
</html>