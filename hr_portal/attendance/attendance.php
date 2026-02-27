<?php
require_once '../config/db.php';
require_once '../includes/icon_functions.php'; // ADDED

if (!isLoggedIn()) {
    header('Location: ../auth/login.php');
    exit();
}

$user_id = $_SESSION['user_id'];
$role = $_SESSION['role'];
$message = '';

// Mark attendance
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['mark_attendance'])) {
        $attendance_date = sanitize($_POST['attendance_date']);
        $status = sanitize($_POST['status']);
        $check_in = sanitize($_POST['check_in'] ?? '');
        $check_out = sanitize($_POST['check_out'] ?? '');
        $remarks = sanitize($_POST['remarks'] ?? '');
        
        // Check if already marked
        $stmt = $conn->prepare("SELECT id FROM attendance WHERE user_id = ? AND attendance_date = ?");
        $stmt->bind_param("is", $user_id, $attendance_date);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows > 0) {
            $message = '<div class="alert alert-error"><i class="icon-error"></i> Attendance already marked for this date</div>';
        } else {
            $stmt = $conn->prepare("
                INSERT INTO attendance (user_id, attendance_date, status, check_in, check_out, remarks)
                VALUES (?, ?, ?, ?, ?, ?)
            ");
            $stmt->bind_param("isssss", $user_id, $attendance_date, $status, $check_in, $check_out, $remarks);
            
            if ($stmt->execute()) {
                $message = '<div class="alert alert-success"><i class="icon-success"></i> Attendance marked successfully!</div>';
            } else {
                $message = '<div class="alert alert-error"><i class="icon-error"></i> Error marking attendance</div>';
            }
        }
        $stmt->close();
    }
    
    if (isset($_POST['update_attendance']) && isset($_POST['attendance_id'])) {
        $attendance_id = intval($_POST['attendance_id']);
        $status = sanitize($_POST['status']);
        $check_in = sanitize($_POST['check_in'] ?? '');
        $check_out = sanitize($_POST['check_out'] ?? '');
        $remarks = sanitize($_POST['remarks'] ?? '');
        
        // Check permissions
        if ($role === 'hr' || $role === 'admin' || $role === 'pm') {
            $stmt = $conn->prepare("
                UPDATE attendance 
                SET status = ?, check_in = ?, check_out = ?, remarks = ? 
                WHERE id = ?
            ");
            $stmt->bind_param("ssssi", $status, $check_in, $check_out, $remarks, $attendance_id);
        } else {
            // Employees can only update their own attendance
            $stmt = $conn->prepare("
                UPDATE attendance 
                SET check_in = ?, check_out = ?, remarks = ? 
                WHERE id = ? AND user_id = ?
            ");
            $stmt->bind_param("sssii", $check_in, $check_out, $remarks, $attendance_id, $user_id);
        }
        
        if ($stmt->execute()) {
            $message = '<div class="alert alert-success"><i class="icon-success"></i> Attendance updated successfully!</div>';
        } else {
            $message = '<div class="alert alert-error"><i class="icon-error"></i> Error updating attendance</div>';
        }
        $stmt->close();
    }
}

// Delete attendance
if (isset($_GET['delete']) && in_array($role, ['hr', 'admin', 'pm'])) {
    $attendance_id = intval($_GET['delete']);
    
    $stmt = $conn->prepare("DELETE FROM attendance WHERE id = ?");
    $stmt->bind_param("i", $attendance_id);
    
    if ($stmt->execute()) {
        $message = '<div class="alert alert-success"><i class="icon-success"></i> Attendance record deleted successfully</div>';
    } else {
        $message = '<div class="alert alert-error"><i class="icon-error"></i> Error deleting attendance record</div>';
    }
    $stmt->close();
}

// Get attendance records
$selected_month = isset($_GET['month']) ? sanitize($_GET['month']) : date('Y-m');
$start_date = $selected_month . '-01';
$end_date = date('Y-m-t', strtotime($start_date));

// For HR/Admin/PM viewing other users
$viewing_user_id = $user_id;
if (isset($_GET['user_id']) && in_array($role, ['hr', 'admin', 'pm'])) {
    $viewing_user_id = intval($_GET['user_id']);
}

$stmt = $conn->prepare("
    SELECT a.*, u.full_name 
    FROM attendance a 
    JOIN users u ON a.user_id = u.id 
    WHERE a.user_id = ? 
    AND a.attendance_date BETWEEN ? AND ? 
    ORDER BY a.attendance_date DESC
");
$stmt->bind_param("iss", $viewing_user_id, $start_date, $end_date);
$stmt->execute();
$attendance_records = $stmt->get_result();

// Get user info
$stmt_user = $conn->prepare("SELECT full_name FROM users WHERE id = ?");
$stmt_user->bind_param("i", $viewing_user_id);
$stmt_user->execute();
$user_result = $stmt_user->get_result();
$user_info = $user_result->fetch_assoc();
$stmt_user->close();

// Get all users for HR/Admin/PM dropdown
$users = [];
if (in_array($role, ['hr', 'admin', 'pm'])) {
    $users_result = $conn->query("SELECT id, username, full_name FROM users ORDER BY full_name");
    $users = $users_result->fetch_all(MYSQLI_ASSOC);
}

// Calculate attendance summary
$stmt_summary = $conn->prepare("
    SELECT 
        COUNT(*) as total_days,
        SUM(CASE WHEN status = 'Present' THEN 1 ELSE 0 END) as present_days,
        SUM(CASE WHEN status = 'Absent' THEN 1 ELSE 0 END) as absent_days,
        SUM(CASE WHEN status = 'Leave' THEN 1 ELSE 0 END) as leave_days,
        SUM(CASE WHEN status = 'Half Day' THEN 1 ELSE 0 END) as half_days
    FROM attendance 
    WHERE user_id = ? 
    AND attendance_date BETWEEN ? AND ?
");
$stmt_summary->bind_param("iss", $viewing_user_id, $start_date, $end_date);
$stmt_summary->execute();
$summary_result = $stmt_summary->get_result();
$summary = $summary_result->fetch_assoc();
$stmt_summary->close();

$page_title = "Attendance - MAKSIM HR";
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Attendance - MAKSIM HR</title>
    <?php include '../includes/head.php'; ?>
    <style>
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.5);
            justify-content: center;
            align-items: center;
            z-index: 1000;
        }
        
        .modal-content {
            background: white;
            border-radius: 15px;
            padding: 30px;
            width: 90%;
            max-width: 500px;
            max-height: 80vh;
            overflow-y: auto;
        }
        
        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }
        
        .modal-title {
            font-size: 20px;
            color: #2d3748;
        }
        
        .close-modal {
            background: none;
            border: none;
            font-size: 24px;
            cursor: pointer;
            color: #718096;
        }
        i[class^="icon-"] {
            font-style: normal;
        }
    </style>
</head>
<body>
    <?php include '../includes/header.php'; ?>
    
    <div class="app-main">
        <?php include '../includes/sidebar.php'; ?>
        
        <div class="main-content">
            <h2 class="page-title"><i class="icon-attendance"></i> Attendance</h2>
            
            <?php echo $message; ?>
            
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title"><i class="icon-calendar-check"></i> Attendance - <?php echo date('F Y', strtotime($selected_month)); ?></h3>
                    <div style="display: flex; gap: 10px; align-items: center;">
                        <?php if (in_array($role, ['hr', 'admin', 'pm'])): ?>
                        <select id="userSelect" class="form-control" style="width: 200px;" onchange="changeUser(this.value)">
                            <option value="">Select User</option>
                            <?php foreach ($users as $u): ?>
                            <option value="<?php echo $u['id']; ?>" <?php echo $viewing_user_id == $u['id'] ? 'selected' : ''; ?>>
                                <?php echo $u['full_name']; ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                        <?php endif; ?>
                        <input type="month" id="monthSelect" class="form-control" value="<?php echo $selected_month; ?>" onchange="changeMonth(this.value)">
                    </div>
                </div>
                
                <!-- Attendance Summary -->
                <div class="stats-grid" style="margin-bottom: 30px;">
                    <div class="stat-card">
                        <div class="stat-icon"><i class="icon-calendar"></i></div>
                        <div class="stat-value"><?php echo $summary['total_days'] ?? 0; ?></div>
                        <div class="stat-label">Total Days</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon" style="background: #c6f6d5; color: #276749;"><i class="icon-check"></i></div>
                        <div class="stat-value"><?php echo $summary['present_days'] ?? 0; ?></div>
                        <div class="stat-label">Present Days</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon" style="background: #fed7d7; color: #c53030;"><i class="icon-cancel"></i></div>
                        <div class="stat-value"><?php echo $summary['absent_days'] ?? 0; ?></div>
                        <div class="stat-label">Absent Days</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon" style="background: #feebc8; color: #c05621;"><i class="icon-leave"></i></div>
                        <div class="stat-value"><?php echo $summary['leave_days'] ?? 0; ?></div>
                        <div class="stat-label">Leave Days</div>
                    </div>
                </div>

                <!-- Mark Attendance Form -->
                <div class="card" style="margin-bottom: 30px;">
                    <div class="card-header">
                        <h3 class="card-title"><i class="icon-plus"></i> Mark Attendance</h3>
                    </div>
                    <form method="POST" action="">
                        <div class="form-row">
                            <div class="form-group">
                                <label class="form-label">Date *</label>
                                <input type="date" name="attendance_date" class="form-control" required>
                            </div>
                            <div class="form-group">
                                <label class="form-label">Status *</label>
                                <select name="status" class="form-control" required>
                                    <option value="Present">Present</option>
                                    <option value="Absent">Absent</option>
                                    <option value="Leave">Leave</option>
                                    <option value="Half Day">Half Day</option>
                                </select>
                            </div>
                            <div class="form-group">
                                <label class="form-label">Check In</label>
                                <input type="time" name="check_in" class="form-control">
                            </div>
                            <div class="form-group">
                                <label class="form-label">Check Out</label>
                                <input type="time" name="check_out" class="form-control">
                            </div>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Remarks</label>
                            <input type="text" name="remarks" class="form-control" placeholder="Enter remarks">
                        </div>
                        <button type="submit" name="mark_attendance" class="btn"><i class="icon-plus"></i> Mark Attendance</button>
                    </form>
                </div>

                <!-- Attendance Records -->
                <div class="table-container">
                    <table>
                        <thead>
                            <tr>
                                <?php if (in_array($role, ['hr', 'admin', 'pm'])): ?>
                                <th>Employee</th>
                                <?php endif; ?>
                                <th>Date</th>
                                <th>Status</th>
                                <th>Check In</th>
                                <th>Check Out</th>
                                <th>Remarks</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($attendance_records->num_rows > 0): ?>
                                <?php while ($record = $attendance_records->fetch_assoc()): ?>
                                <tr>
                                    <?php if (in_array($role, ['hr', 'admin', 'pm'])): ?>
                                    <td><?php echo $record['full_name']; ?></td>
                                    <?php endif; ?>
                                    <td><?php echo $record['attendance_date']; ?></td>
                                    <td>
                                        <?php
                                        $status_colors = [
                                            'Present' => 'success',
                                            'Absent' => 'error',
                                            'Leave' => 'warning',
                                            'Half Day' => 'info'
                                        ];
                                        ?>
                                        <span class="status-badge status-<?php echo strtolower($record['status']); ?>">
                                            <?php echo $record['status']; ?>
                                        </span>
                                    </td>
                                    <td><?php echo $record['check_in'] ?: '-'; ?></td>
                                    <td><?php echo $record['check_out'] ?: '-'; ?></td>
                                    <td><?php echo $record['remarks'] ?: '-'; ?></td>
                                    <td>
                                        <div class="action-buttons">
                                            <?php if (in_array($role, ['hr', 'admin', 'pm'])): ?>
                                                <button class="btn-small btn-edit" onclick="editAttendance(<?php echo $record['id']; ?>)">
                                                    <i class="icon-edit"></i> Edit
                                                </button>
                                                <a href="?delete=<?php echo $record['id']; ?>&month=<?php echo $selected_month; ?><?php echo $viewing_user_id != $user_id ? '&user_id=' . $viewing_user_id : ''; ?>" 
                                                   class="btn-small btn-delete"
                                                   onclick="return confirm('Are you sure you want to delete this attendance record?')">
                                                    <i class="icon-delete"></i> Delete
                                                </a>
                                            <?php elseif ($record['user_id'] == $user_id): ?>
                                                <button class="btn-small btn-edit" onclick="editAttendance(<?php echo $record['id']; ?>)">
                                                    <i class="icon-edit"></i> Edit
                                                </button>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="<?php echo in_array($role, ['hr', 'admin', 'pm']) ? '7' : '6'; ?>" style="text-align: center; padding: 40px; color: #718096;">
                                        <i class="icon-folder-open" style="font-size: 48px; margin-bottom: 15px; display: block;"></i>
                                        No attendance records found for <?php echo date('F Y', strtotime($selected_month)); ?>
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- Edit Attendance Modal -->
    <div id="editModal" class="modal" style="display: none;">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title"><i class="icon-edit"></i> Edit Attendance</h3>
                <button class="close-modal" onclick="closeModal()">&times;</button>
            </div>
            <form method="POST" action="">
                <input type="hidden" name="attendance_id" id="editAttendanceId">
                <div class="form-group">
                    <label class="form-label">Status</label>
                    <select name="status" id="editStatus" class="form-control">
                        <option value="Present">Present</option>
                        <option value="Absent">Absent</option>
                        <option value="Leave">Leave</option>
                        <option value="Half Day">Half Day</option>
                    </select>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">Check In</label>
                        <input type="time" name="check_in" id="editCheckIn" class="form-control">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Check Out</label>
                        <input type="time" name="check_out" id="editCheckOut" class="form-control">
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label">Remarks</label>
                    <input type="text" name="remarks" id="editRemarks" class="form-control">
                </div>
                <button type="submit" name="update_attendance" class="btn"><i class="icon-save"></i> Update Attendance</button>
            </form>
        </div>
    </div>

    <script>
    function changeUser(userId) {
        const month = document.getElementById('monthSelect').value;
        window.location.href = `attendance.php?user_id=${userId}&month=${month}`;
    }
    
    function changeMonth(month) {
        const url = new URL(window.location.href);
        url.searchParams.set('month', month);
        window.location.href = url.toString();
    }
    
    function editAttendance(id) {
        // In a real application, you would fetch the attendance details via AJAX
        // For now, we'll just show the modal and let the form handle the update
        document.getElementById('editAttendanceId').value = id;
        document.getElementById('editModal').style.display = 'flex';
    }
    
    function closeModal() {
        document.getElementById('editModal').style.display = 'none';
    }
    
    // Close modal when clicking outside
    window.onclick = function(event) {
        const modal = document.getElementById('editModal');
        if (event.target == modal) {
            closeModal();
        }
    }
    </script>
    
    <style>
    .modal {
        display: none;
        position: fixed;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
        background: rgba(0,0,0,0.5);
        justify-content: center;
        align-items: center;
        z-index: 1000;
    }
    
    .modal-content {
        background: white;
        border-radius: 15px;
        padding: 30px;
        width: 90%;
        max-width: 500px;
        max-height: 80vh;
        overflow-y: auto;
    }
    
    .modal-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 20px;
    }
    
    .modal-title {
        font-size: 20px;
        color: #2d3748;
    }
    
    .close-modal {
        background: none;
        border: none;
        font-size: 24px;
        cursor: pointer;
        color: #718096;
    }
    </style>
    
    <script src="../assets/js/app.js"></script>
</body>
</html>