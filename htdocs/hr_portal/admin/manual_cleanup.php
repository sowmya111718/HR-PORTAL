<?php
require_once '../config/db.php';
require_once '../includes/icon_functions.php';
require_once '../includes/notification_functions.php';

// Set default timezone to IST for the entire script
date_default_timezone_set('Asia/Kolkata');

// ONLY ADMIN CAN ACCESS
checkRole(['admin']); 

$message = '';

// Get selected dates from POST or default to 90 days ago
$from_date = isset($_POST['from_date']) ? $_POST['from_date'] : date('Y-m-d', strtotime('-90 days'));
$to_date = isset($_POST['to_date']) ? $_POST['to_date'] : date('Y-m-d');

// Validate dates
if ($from_date > $to_date) {
    $temp = $from_date;
    $from_date = $to_date;
    $to_date = $temp;
}

// ============================================
// HOLIDAY FUNCTIONS
// ============================================

/**
 * Check if a date is a holiday
 * @param string $date Date in Y-m-d format
 * @return string|false Returns holiday name if it's a holiday, false otherwise
 */
function isHoliday($date) {
    // Define holidays with fixed dates (month-day format)
    $holidays = [
        '03-19' => 'Ugadi',
        '08-15' => 'Independence Day',
        '09-14' => 'Vinayaka Chavithi',
        '10-02' => 'Gandhi Jayanthi',
        '10-20' => 'Vijaya Dashami'
    ];
    
    // Get month-day from date
    $month_day = date('m-d', strtotime($date));
    
    // Check if it's a holiday
    if (isset($holidays[$month_day])) {
        return $holidays[$month_day];
    }
    
    return false;
}

// ============================================
// HANDLE DOWNLOAD & DELETE REQUEST
// ============================================
if (isset($_POST['cleanup_and_download'])) {
    $from_date = $_POST['from_date'];
    $to_date = $_POST['to_date'];
    $tables = isset($_POST['tables']) ? $_POST['tables'] : [];
    
    if (empty($tables)) {
        $message = '<div class="alert alert-error">❌ Please select at least one table to cleanup</div>';
    } else {
        try {
            // Start transaction
            $conn->begin_transaction();
            
            // Create a single ZIP file with all selected data
            $zip_filename = 'admin_cleanup_' . date('Y-m-d_His') . '.zip';
            $zip_path = sys_get_temp_dir() . '/' . $zip_filename;
            
            $zip = new ZipArchive();
            if ($zip->open($zip_path, ZipArchive::CREATE) !== TRUE) {
                throw new Exception("Cannot create ZIP file");
            }
            
            $total_records = 0;
            $exported_data = [];
            
            // ============================================
            // 1. EXPORT EACH SELECTED TABLE
            // ============================================
            foreach ($tables as $table) {
                switch ($table) {
                    case 'leaves':
                        $data = exportLeaves($conn, $from_date, $to_date, $zip);
                        break;
                    case 'permissions':
                        $data = exportPermissions($conn, $from_date, $to_date, $zip);
                        break;
                    case 'timesheets':
                        $data = exportTimesheets($conn, $from_date, $to_date, $zip);
                        break;
                    case 'notifications':
                        $data = exportNotifications($conn, $from_date, $to_date, $zip);
                        break;
                    case 'system_logs':
                        $data = exportSystemLogs($conn, $from_date, $to_date, $zip);
                        break;
                }
                
                if ($data['count'] > 0) {
                    $exported_data[] = $data;
                    $total_records += $data['count'];
                }
            }
            
            // ============================================
            // 2. CREATE SUMMARY FILE
            // ============================================
            $summary = "========================================\n";
            $summary .= "ADMIN MANUAL CLEANUP REPORT\n";
            $summary .= "========================================\n";
            $summary .= "Generated: " . date('Y-m-d H:i:s') . "\n";
            $summary .= "Admin: {$_SESSION['full_name']} (ID: {$_SESSION['user_id']})\n";
            $summary .= "Date Range: {$from_date} to {$to_date}\n\n";
            $summary .= "EXPORTED DATA:\n";
            
            foreach ($exported_data as $data) {
                $summary .= sprintf("  • %-12s: %5d records → %s\n", 
                    $data['table'], $data['count'], $data['filename']);
            }
            
            $summary .= "\nTOTAL RECORDS: {$total_records}\n";
            $summary .= "========================================\n";
            
            $zip->addFromString('SUMMARY.txt', $summary);
            $zip->close();
            
            // ============================================
            // 3. DELETE DATA FROM DATABASE
            // ============================================
            $deleted_counts = [];
            foreach ($tables as $table) {
                switch ($table) {
                    case 'leaves':
                        $deleted_counts['leaves'] = deleteOldLeaves($conn, $from_date, $to_date);
                        break;
                    case 'permissions':
                        $deleted_counts['permissions'] = deleteOldPermissions($conn, $from_date, $to_date);
                        break;
                    case 'timesheets':
                        $deleted_counts['timesheets'] = deleteOldTimesheets($conn, $from_date, $to_date);
                        break;
                    case 'notifications':
                        $deleted_counts['notifications'] = deleteOldNotifications($conn, $from_date, $to_date);
                        break;
                    case 'system_logs':
                        $deleted_counts['system_logs'] = deleteOldSystemLogs($conn, $from_date, $to_date);
                        break;
                }
            }
            
            // ============================================
            // 4. LOG THE ACTION
            // ============================================
            $log_message = "Admin Manual Cleanup - " . date('Y-m-d H:i:s') . "\n";
            $log_message .= "Admin: {$_SESSION['full_name']} (ID: {$_SESSION['user_id']})\n";
            $log_message .= "Date Range: {$from_date} to {$to_date}\n";
            $log_message .= "Tables cleaned: " . implode(', ', $tables) . "\n";
            $log_message .= "Records exported: {$total_records}\n";
            $log_message .= "File downloaded: {$zip_filename}\n";
            
            // Log to system_logs if table exists
            $check = $conn->query("SHOW TABLES LIKE 'system_logs'");
            if ($check && $check->num_rows > 0) {
                $log_stmt = $conn->prepare("INSERT INTO system_logs (event_type, description, user_id, created_at) VALUES (?, ?, ?, NOW())");
                $event_type = 'manual_cleanup';
                $log_stmt->bind_param("ssi", $event_type, $log_message, $_SESSION['user_id']);
                $log_stmt->execute();
                $log_stmt->close();
            }
            
            // Commit transaction
            $conn->commit();
            
            // ============================================
            // 5. FORCE DOWNLOAD TO ADMIN'S COMPUTER
            // ============================================
            header('Content-Type: application/zip');
            header('Content-Disposition: attachment; filename="' . $zip_filename . '"');
            header('Content-Length: ' . filesize($zip_path));
            header('Cache-Control: no-cache, must-revalidate');
            header('Pragma: no-cache');
            header('Expires: 0');
            
            readfile($zip_path);
            
            // Delete temp file after download
            unlink($zip_path);
            exit();
            
        } catch (Exception $e) {
            $conn->rollback();
            $message = '<div class="alert alert-error">❌ Error: ' . $e->getMessage() . '</div>';
        }
    }
}

// ============================================
// EXPORT FUNCTIONS - NOW WITH DATE RANGE
// ============================================

function exportLeaves($conn, $from_date, $to_date, $zip) {
    $filename = 'leaves_' . date('Y-m-d') . '.csv';
    
    $query = "SELECT l.*, u.username, u.full_name, u.department, u.position,
                     a.full_name as approved_by_name, r.full_name as rejected_by_name
              FROM leaves l 
              JOIN users u ON l.user_id = u.id 
              LEFT JOIN users a ON l.approved_by = a.id
              LEFT JOIN users r ON l.rejected_by = r.id
              WHERE l.from_date BETWEEN ? AND ? 
              ORDER BY l.from_date DESC";
    
    $stmt = $conn->prepare($query);
    $stmt->bind_param("ss", $from_date, $to_date);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows == 0) {
        return ['table' => 'leaves', 'count' => 0, 'filename' => ''];
    }
    
    // Get column names
    $columns = [];
    while ($col = $result->fetch_field()) {
        $columns[] = $col->name;
    }
    
    // Create CSV in memory
    $output = fopen('php://temp', 'w');
    
    // Add UTF-8 BOM for Excel compatibility
    fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));
    
    fputcsv($output, $columns);
    
    $count = 0;
    mysqli_data_seek($result, 0);
    while ($row = $result->fetch_assoc()) {
        $ordered_row = [];
        foreach ($columns as $col) {
            $ordered_row[] = $row[$col] ?? '';
        }
        fputcsv($output, $ordered_row);
        $count++;
    }
    
    rewind($output);
    $csv_content = stream_get_contents($output);
    fclose($output);
    $stmt->close();
    
    $zip->addFromString($filename, $csv_content);
    
    return ['table' => 'leaves', 'count' => $count, 'filename' => $filename];
}

function exportPermissions($conn, $from_date, $to_date, $zip) {
    $filename = 'permissions_' . date('Y-m-d') . '.csv';
    
    $query = "SELECT p.*, u.username, u.full_name, u.department, u.position,
                     a.full_name as approved_by_name, r.full_name as rejected_by_name
              FROM permissions p 
              JOIN users u ON p.user_id = u.id 
              LEFT JOIN users a ON p.approved_by = a.id
              LEFT JOIN users r ON p.rejected_by = r.id
              WHERE p.permission_date BETWEEN ? AND ? 
              ORDER BY p.permission_date DESC";
    
    $stmt = $conn->prepare($query);
    $stmt->bind_param("ss", $from_date, $to_date);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows == 0) {
        return ['table' => 'permissions', 'count' => 0, 'filename' => ''];
    }
    
    $columns = [];
    while ($col = $result->fetch_field()) {
        $columns[] = $col->name;
    }
    
    $output = fopen('php://temp', 'w');
    fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF)); // UTF-8 BOM
    fputcsv($output, $columns);
    
    $count = 0;
    mysqli_data_seek($result, 0);
    while ($row = $result->fetch_assoc()) {
        $ordered_row = [];
        foreach ($columns as $col) {
            $ordered_row[] = $row[$col] ?? '';
        }
        fputcsv($output, $ordered_row);
        $count++;
    }
    
    rewind($output);
    $csv_content = stream_get_contents($output);
    fclose($output);
    $stmt->close();
    
    $zip->addFromString($filename, $csv_content);
    
    return ['table' => 'permissions', 'count' => $count, 'filename' => $filename];
}

function exportTimesheets($conn, $from_date, $to_date, $zip) {
    $filename = 'timesheets_' . date('Y-m-d') . '.xls';
    
    // Get all users with their department and position
    $users_query = "SELECT id, full_name, department, position FROM users ORDER BY full_name";
    $users_result = $conn->query($users_query);
    $all_users = $users_result->fetch_all(MYSQLI_ASSOC);
    
    // Get working days count in the date range (excluding Sundays and holidays)
    $start = new DateTime($from_date);
    $end = new DateTime($to_date);
    $end->modify('+1 day');
    $interval = new DateInterval('P1D');
    $date_range = new DatePeriod($start, $interval, $end);
    
    $working_days = 0;
    $all_dates = [];
    $holiday_dates = [];
    $sunday_dates = [];
    
    foreach ($date_range as $date) {
        $date_str = $date->format('Y-m-d');
        $all_dates[] = $date_str;
        
        $holiday_name = isHoliday($date_str);
        if ($holiday_name !== false) {
            $holiday_dates[] = $date_str;
        } elseif ($date->format('N') == 7) {
            $sunday_dates[] = $date_str;
        } else {
            $working_days++;
        }
    }
    
    // Get all timesheets for the date range
    $query = "SELECT 
                t.user_id,
                DATE_FORMAT(t.entry_date, '%Y-%m-%d') as entry_date,
                u.full_name as employee_name,
                u.department,
                u.position,
                t.software,
                p.project_name as project_name,
                t.task_name,
                t.hours,
                t.status,
                t.remarks,
                DATE_FORMAT(t.submitted_date, '%Y-%m-%d %H:%i:%s') as submitted_date,
                CASE WHEN t.submitted_date > CONCAT(t.entry_date, ' 23:59:59') THEN 'Yes' ELSE 'No' END as is_late,
                CASE WHEN DAYOFWEEK(t.entry_date) = 1 THEN 'Yes' ELSE 'No' END as is_sunday
              FROM timesheets t 
              JOIN users u ON t.user_id = u.id 
              LEFT JOIN projects p ON t.project_id = p.id
              WHERE t.entry_date BETWEEN ? AND ? 
              ORDER BY u.full_name, t.entry_date DESC";
    
    $stmt = $conn->prepare($query);
    $stmt->bind_param("ss", $from_date, $to_date);
    $stmt->execute();
    $result = $stmt->get_result();
    
    // Get LOP data for each user
    $lop_query = "SELECT user_id, COUNT(*) as lop_count 
                  FROM leaves 
                  WHERE from_date BETWEEN ? AND ? 
                  AND leave_type = 'LOP' 
                  AND reason LIKE 'Auto-generated LOP%'
                  GROUP BY user_id";
    $lop_stmt = $conn->prepare($lop_query);
    $lop_stmt->bind_param("ss", $from_date, $to_date);
    $lop_stmt->execute();
    $lop_result = $lop_stmt->get_result();
    $lop_counts = [];
    while ($lop = $lop_result->fetch_assoc()) {
        $lop_counts[$lop['user_id']] = $lop['lop_count'];
    }
    $lop_stmt->close();
    
    // Get leave days count for each user (excluding LOP)
    $leave_query = "SELECT user_id, COUNT(*) as leave_count 
                    FROM leaves 
                    WHERE from_date BETWEEN ? AND ? 
                    AND leave_type IN ('Sick', 'Casual', 'Other')
                    AND status = 'Approved'
                    GROUP BY user_id";
    $leave_stmt = $conn->prepare($leave_query);
    $leave_stmt->bind_param("ss", $from_date, $to_date);
    $leave_stmt->execute();
    $leave_result = $leave_stmt->get_result();
    $leave_counts = [];
    while ($leave = $leave_result->fetch_assoc()) {
        $leave_counts[$leave['user_id']] = $leave['leave_count'];
    }
    $leave_stmt->close();
    
    // Count submissions and calculate total hours per employee
    $submissions_by_employee = [];
    $total_hours_by_employee = [];
    $sunday_work_by_employee = [];
    $all_timesheets = [];
    $timesheets_by_date_user = [];
    
    while ($row = $result->fetch_assoc()) {
        $all_timesheets[] = $row;
        $employee_id = $row['user_id'];
        $employee = $row['employee_name'];
        $date = $row['entry_date'];
        
        if (!isset($submissions_by_employee[$employee_id])) {
            $submissions_by_employee[$employee_id] = 0;
            $total_hours_by_employee[$employee_id] = 0;
            $sunday_work_by_employee[$employee_id] = 0;
        }
        
        $submissions_by_employee[$employee_id]++;
        $total_hours_by_employee[$employee_id] += floatval($row['hours']);
        
        if ($row['is_sunday'] == 'Yes') {
            $sunday_work_by_employee[$employee_id]++;
        }
        
        // For daily view
        if (!isset($timesheets_by_date_user[$date])) {
            $timesheets_by_date_user[$date] = [];
        }
        $timesheets_by_date_user[$date][$employee_id] = $row;
    }
    
    // Get leaves by date for daily view
    $leaves_by_date_user = [];
    $leave_detail_stmt = $conn->prepare("SELECT user_id, from_date, to_date, leave_type FROM leaves WHERE from_date BETWEEN ? AND ? AND status = 'Approved'");
    $leave_detail_stmt->bind_param("ss", $from_date, $to_date);
    $leave_detail_stmt->execute();
    $leave_detail_result = $leave_detail_stmt->get_result();
    while ($leave = $leave_detail_result->fetch_assoc()) {
        $from = new DateTime($leave['from_date']);
        $to = new DateTime($leave['to_date']);
        $to->modify('+1 day');
        $leave_range = new DatePeriod($from, $interval, $to);
        foreach ($leave_range as $leave_date) {
            $date_str = $leave_date->format('Y-m-d');
            if (!isset($leaves_by_date_user[$date_str])) {
                $leaves_by_date_user[$date_str] = [];
            }
            $leaves_by_date_user[$date_str][$leave['user_id']] = $leave;
        }
    }
    $leave_detail_stmt->close();
    
    // Create XLS file content (HTML table format for Excel compatibility)
    $xls_content = '<html>';
    $xls_content .= '<head>';
    $xls_content .= '<meta charset="UTF-8">';
    $xls_content .= '<title>Timesheet Report</title>';
    $xls_content .= '<style>';
    $xls_content .= 'body { font-family: Arial, sans-serif; margin: 20px; }';
    $xls_content .= 'h2 { color: #006400; margin-bottom: 5px; }';
    $xls_content .= 'h3 { color: #006400; margin-top: 30px; margin-bottom: 15px; border-bottom: 2px solid #006400; padding-bottom: 5px; }';
    $xls_content .= 'table { border-collapse: collapse; width: 100%; margin-bottom: 30px; font-size: 12px; }';
    $xls_content .= 'th { background: #4472c4; color: white; font-weight: bold; text-align: left; padding: 8px; border: 1px solid #2d4b8c; }';
    $xls_content .= 'td { padding: 6px; border: 1px solid #cccccc; vertical-align: top; }';
    $xls_content .= '.employee-summary { background: #e6f0ff; }';
    $xls_content .= '.total-row { background: #f0f0f0; font-weight: bold; }';
    $xls_content .= '.late { color: #ed8936; font-weight: bold; }';
    $xls_content .= '.sunday { background: #fff3cd; }';
    $xls_content .= '.holiday-cell { background: #f3e8ff; color: #6b21a8; text-align: center; }';
    $xls_content .= '.sunday-cell { background: #e2e8f0; text-align: center; }';
    $xls_content .= '.submitted-cell { background: #d4edda; text-align: center; }';
    $xls_content .= '.sunday-work-cell { background: #fff3cd; text-align: center; }';
    $xls_content .= '.leave-cell { background: #cce5ff; text-align: center; color: #004085; }';
    $xls_content .= '.lop-cell { background: #fff3cd; text-align: center; color: #c53030; }';
    $xls_content .= '.empty-cell { background: #f0f0f0; text-align: center; }';
    $xls_content .= '.summary-box { background: #f8f9fa; border: 2px solid #006400; padding: 15px; margin-bottom: 20px; border-radius: 5px; }';
    $xls_content .= '.summary-row { display: flex; margin-bottom: 5px; }';
    $xls_content .= '.summary-label { font-weight: bold; width: 200px; }';
    $xls_content .= '.summary-value { flex: 1; }';
    $xls_content .= '.text-format { mso-number-format:"\@"; }'; /* Force text format in Excel */
    $xls_content .= '</style>';
    $xls_content .= '</head>';
    $xls_content .= '<body>';
    
    // Title and Report Info
    $xls_content .= '<h2>🏢 MAKSIM HR - Timesheet Report</h2>';
    $xls_content .= '<div class="summary-box">';
    $xls_content .= '<div class="summary-row"><span class="summary-label">Period:</span><span class="summary-value">' . date('d-m-Y', strtotime($from_date)) . ' to ' . date('d-m-Y', strtotime($to_date)) . ' (Excluding Holidays)</span></div>';
    $xls_content .= '<div class="summary-row"><span class="summary-label">Generated on:</span><span class="summary-value">' . date('d-m-Y H:i:s') . ' IST</span></div>';
    $xls_content .= '<div class="summary-row"><span class="summary-label">Report Type:</span><span class="summary-value">All Employees</span></div>';
    $xls_content .= '</div>';
    
    // ===== EMPLOYEE SUMMARY TABLE =====
    $xls_content .= '<h3>📊 Employee Timesheet Submissions</h3>';
    $xls_content .= '<table>';
    $xls_content .= '<thead><tr>';
    $xls_content .= '<th>Employee</th>';
    $xls_content .= '<th>Department</th>';
    $xls_content .= '<th>Position</th>';
    $xls_content .= '<th>Working Days Submitted</th>';
    $xls_content .= '<th>Sunday Work</th>';
    $xls_content .= '<th>Total Hours</th>';
    $xls_content .= '<th>Leave Days</th>';
    $xls_content .= '<th>Auto LOP Days</th>';
    $xls_content .= '<th>Submission %</th>';
    $xls_content .= '</tr></thead>';
    $xls_content .= '<tbody>';
    
    $grand_total_submissions = 0;
    $grand_total_hours = 0;
    $grand_total_lop = 0;
    $grand_total_leaves = 0;
    $grand_total_sunday = 0;
    
    foreach ($all_users as $user) {
        $user_id = $user['id'];
        $submission_count = isset($submissions_by_employee[$user_id]) ? $submissions_by_employee[$user_id] : 0;
        $sunday_count = isset($sunday_work_by_employee[$user_id]) ? $sunday_work_by_employee[$user_id] : 0;
        $total_hours = isset($total_hours_by_employee[$user_id]) ? $total_hours_by_employee[$user_id] : 0;
        $lop_count = isset($lop_counts[$user_id]) ? $lop_counts[$user_id] : 0;
        $leave_count = isset($leave_counts[$user_id]) ? $leave_counts[$user_id] : 0;
        
        $working_days_required = $working_days - $leave_count;
        $working_days_submitted = intval($submission_count - $sunday_count);
        $submission_percent = ($working_days_required > 0) ? round($working_days_submitted / $working_days_required * 100, 1) : 0;
        
        $grand_total_submissions += $submission_count;
        $grand_total_hours += $total_hours;
        $grand_total_lop += $lop_count;
        $grand_total_leaves += $leave_count;
        $grand_total_sunday += $sunday_count;
        
        $xls_content .= '<tr class="employee-summary">';
        $xls_content .= '<td><strong>' . htmlspecialchars($user['full_name']) . '</strong></td>';
        $xls_content .= '<td>' . htmlspecialchars($user['department'] ?? '') . '</td>';
        $xls_content .= '<td>' . htmlspecialchars($user['position'] ?? '') . '</td>';
        // Force text format by adding a zero-width space or using CSS class
        $xls_content .= '<td class="text-format">' . $working_days_submitted . '/' . $working_days . '</td>';
        $xls_content .= '<td>' . $sunday_count . '</td>';
        $xls_content .= '<td>' . number_format($total_hours, 2) . ' hrs</td>';
        $xls_content .= '<td>' . $leave_count . '</td>';
        $xls_content .= '<td>' . $lop_count . '</td>';
        $xls_content .= '<td>' . $submission_percent . '%</td>';
        $xls_content .= '</tr>';
    }
    
    // Grand total row
    $total_possible_working_days = $working_days * count($all_users);
    $total_working_days_submitted = intval($grand_total_submissions - $grand_total_sunday);
    $overall_submission_percent = ($total_possible_working_days - $grand_total_leaves) > 0 ? round($total_working_days_submitted / ($total_possible_working_days - $grand_total_leaves) * 100, 1) : 0;
    
    $xls_content .= '<tr class="total-row">';
    $xls_content .= '<td colspan="3" style="text-align: right;"><strong>TOTAL</strong></td>';
    $xls_content .= '<td class="text-format"><strong>' . $total_working_days_submitted . '/' . $total_possible_working_days . '</strong></td>';
    $xls_content .= '<td><strong>' . $grand_total_sunday . '</strong></td>';
    $xls_content .= '<td><strong>' . number_format($grand_total_hours, 2) . ' hrs</strong></td>';
    $xls_content .= '<td><strong>' . $grand_total_leaves . '</strong></td>';
    $xls_content .= '<td><strong>' . $grand_total_lop . '</strong></td>';
    $xls_content .= '<td><strong>' . $overall_submission_percent . '%</strong></td>';
    $xls_content .= '</tr>';
    
    $xls_content .= '</tbody></table>';
    
    // ===== DAILY TIMESHEET STATUS =====
    $xls_content .= '<h3>Daily Timesheet Status (Holidays Excluded)</h3>';
    $xls_content .= '<p><strong>■ Submitted (Working Day) ■ Sunday Work (+1 Casual Leave) ■ Approved Leave ■ Holiday (No Timesheet) ■ Not Submitted (Auto LOP) ■ Sunday (No Work)</strong></p>';
    
    // Daily status table header
    $xls_content .= '<table>';
    $xls_content .= '<thead><tr>';
    $xls_content .= '<th>Employee</th>';
    
    foreach ($all_dates as $date) {
        $display = date('d-m D', strtotime($date));
        $holiday_name = isHoliday($date);
        $is_sunday = (date('N', strtotime($date)) == 7);
        
        if ($holiday_name !== false) {
            $xls_content .= '<th style="background: #9b59b6; color: white;" title="' . $holiday_name . '">' . $display . '<br>' . substr($holiday_name, 0, 3) . '</th>';
        } elseif ($is_sunday) {
            $xls_content .= '<th style="background: #ed8936; color: white;">' . $display . '</th>';
        } else {
            $xls_content .= '<th>' . $display . '</th>';
        }
    }
    $xls_content .= '<th>Total</th>';
    $xls_content .= '</tr></thead><tbody>';
    
    $daily_totals = array_fill(0, count($all_dates), 0);
    
    foreach ($all_users as $user) {
        $user_id = $user['id'];
        $daily_count = 0;
        
        $xls_content .= '<tr>';
        $xls_content .= '<td><strong>' . htmlspecialchars($user['full_name']) . '</strong></td>';
        
        foreach ($all_dates as $index => $date) {
            $holiday_name = isHoliday($date);
            $is_sunday = (date('N', strtotime($date)) == 7);
            
            if ($holiday_name !== false) {
                $xls_content .= '<td class="holiday-cell" title="' . $holiday_name . '">H</td>';
            } elseif ($is_sunday) {
                if (isset($timesheets_by_date_user[$date][$user_id])) {
                    $entry = $timesheets_by_date_user[$date][$user_id];
                    $xls_content .= '<td class="sunday-work-cell">' . $entry['hours'] . 'h</td>';
                    $daily_count++;
                    $daily_totals[$index]++;
                } else {
                    $xls_content .= '<td class="sunday-cell">-</td>';
                }
            } else {
                if (isset($timesheets_by_date_user[$date][$user_id])) {
                    $entry = $timesheets_by_date_user[$date][$user_id];
                    $xls_content .= '<td class="submitted-cell">' . $entry['hours'] . 'h</td>';
                    $daily_count++;
                    $daily_totals[$index]++;
                } elseif (isset($leaves_by_date_user[$date][$user_id])) {
                    $xls_content .= '<td class="leave-cell">Leave</td>';
                } elseif (isset($lop_counts[$user_id])) {
                    $xls_content .= '<td class="lop-cell">LOP</td>';
                } else {
                    $xls_content .= '<td class="empty-cell">-</td>';
                }
            }
        }
        
        $daily_count_total = intval($daily_count);
        $total_days = count($all_dates);
        $xls_content .= '<td style="font-weight: bold; text-align: center;" class="text-format">' . $daily_count_total . '/' . $total_days . '</td>';
        $xls_content .= '</tr>';
    }
    
    // Daily totals row
    $xls_content .= '<tr class="total-row">';
    $xls_content .= '<td><strong>Daily Totals</strong></td>';
    
    $grand_daily_total = 0;
    foreach ($all_dates as $index => $date) {
        $holiday_name = isHoliday($date);
        $is_sunday = (date('N', strtotime($date)) == 7);
        
        if ($holiday_name !== false) {
            $xls_content .= '<td style="text-align: center;"><strong>Holiday</strong></td>';
        } elseif ($is_sunday) {
            $daily_total = intval($daily_totals[$index]);
            $xls_content .= '<td style="text-align: center;" class="text-format"><strong>' . $daily_total . '/' . count($all_users) . '</strong></td>';
            $grand_daily_total += $daily_total;
        } else {
            $daily_total = intval($daily_totals[$index]);
            $xls_content .= '<td style="text-align: center;" class="text-format"><strong>' . $daily_total . '/' . count($all_users) . '</strong></td>';
            $grand_daily_total += $daily_total;
        }
    }
    
    $grand_total_display = intval($grand_total_submissions);
    $xls_content .= '<td style="text-align: center;" class="text-format"><strong>' . $grand_total_display . '</strong></td>';
    $xls_content .= '</tr>';
    
    $xls_content .= '</tbody></table>';
    
    // ===== ALL TIMESHEET ENTRIES =====
    if (!empty($all_timesheets)) {
        $xls_content .= '<h3>📋 Detailed Timesheet Entries</h3>';
        $xls_content .= '<table>';
        $xls_content .= '<thead><tr>';
        $xls_content .= '<th>Date</th>';
        $xls_content .= '<th>Employee</th>';
        $xls_content .= '<th>Department</th>';
        $xls_content .= '<th>Software</th>';
        $xls_content .= '<th>Project</th>';
        $xls_content .= '<th>Task</th>';
        $xls_content .= '<th>Hours</th>';
        $xls_content .= '<th>Status</th>';
        $xls_content .= '<th>Remarks</th>';
        $xls_content .= '<th>Submitted (IST)</th>';
        $xls_content .= '<th>Late</th>';
        $xls_content .= '<th>Sunday Work</th>';
        $xls_content .= '</tr></thead><tbody>';
        
        foreach ($all_timesheets as $entry) {
            $sunday_class = ($entry['is_sunday'] == 'Yes') ? ' class="sunday"' : '';
            $xls_content .= '<tr' . $sunday_class . '>';
            $xls_content .= '<td>' . htmlspecialchars($entry['entry_date']) . '</td>';
            $xls_content .= '<td><strong>' . htmlspecialchars($entry['employee_name']) . '</strong></td>';
            $xls_content .= '<td>' . htmlspecialchars($entry['department'] ?? '') . '</td>';
            $xls_content .= '<td>' . htmlspecialchars($entry['software'] ?? '') . '</td>';
            $xls_content .= '<td>' . htmlspecialchars($entry['project_name'] ?? '') . '</td>';
            $xls_content .= '<td>' . htmlspecialchars($entry['task_name'] ?? '') . '</td>';
            $xls_content .= '<td><strong>' . number_format($entry['hours'], 1) . ' hrs</strong></td>';
            $xls_content .= '<td>' . ucfirst(htmlspecialchars($entry['status'] ?? '')) . '</td>';
            $xls_content .= '<td>' . htmlspecialchars($entry['remarks'] ?? '') . '</td>';
            $xls_content .= '<td>' . htmlspecialchars($entry['submitted_date'] ?? '') . '</td>';
            $xls_content .= '<td' . (($entry['is_late'] == 'Yes') ? ' class="late"' : '') . '>' . htmlspecialchars($entry['is_late']) . '</td>';
            $xls_content .= '<td>' . htmlspecialchars($entry['is_sunday']) . '</td>';
            $xls_content .= '</tr>';
        }
        
        $xls_content .= '</tbody></table>';
        $xls_content .= '<p><strong>Total Timesheet Entries: ' . intval($grand_total_submissions) . '</strong></p>';
    }
    
    $xls_content .= '<p><em>Generated by MAKSIM PORTAL on ' . date('d-m-Y H:i:s') . ' IST</em></p>';
    $xls_content .= '</body></html>';
    
    $stmt->close();
    
    $zip->addFromString($filename, $xls_content);
    
    return ['table' => 'timesheets', 'count' => $grand_total_submissions, 'filename' => $filename];
}

function exportNotifications($conn, $from_date, $to_date, $zip) {
    $filename = 'notifications_' . date('Y-m-d') . '.csv';
    
    $query = "SELECT n.*, u.username, u.full_name, u.department
              FROM notifications n 
              JOIN users u ON n.user_id = u.id 
              WHERE DATE(n.created_at) BETWEEN ? AND ? 
              ORDER BY n.created_at DESC";
    
    $stmt = $conn->prepare($query);
    $stmt->bind_param("ss", $from_date, $to_date);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows == 0) {
        return ['table' => 'notifications', 'count' => 0, 'filename' => ''];
    }
    
    $columns = [];
    while ($col = $result->fetch_field()) {
        $columns[] = $col->name;
    }
    
    $output = fopen('php://temp', 'w');
    fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF)); // UTF-8 BOM
    fputcsv($output, $columns);
    
    $count = 0;
    mysqli_data_seek($result, 0);
    while ($row = $result->fetch_assoc()) {
        $ordered_row = [];
        foreach ($columns as $col) {
            $ordered_row[] = $row[$col] ?? '';
        }
        fputcsv($output, $ordered_row);
        $count++;
    }
    
    rewind($output);
    $csv_content = stream_get_contents($output);
    fclose($output);
    $stmt->close();
    
    $zip->addFromString($filename, $csv_content);
    
    return ['table' => 'notifications', 'count' => $count, 'filename' => $filename];
}

function exportSystemLogs($conn, $from_date, $to_date, $zip) {
    $filename = 'system_logs_' . date('Y-m-d') . '.csv';
    
    $query = "SELECT l.*, u.username, u.full_name 
              FROM system_logs l 
              LEFT JOIN users u ON l.user_id = u.id 
              WHERE DATE(l.created_at) BETWEEN ? AND ? 
              ORDER BY l.created_at DESC";
    
    $stmt = $conn->prepare($query);
    $stmt->bind_param("ss", $from_date, $to_date);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows == 0) {
        return ['table' => 'system_logs', 'count' => 0, 'filename' => ''];
    }
    
    $columns = [];
    while ($col = $result->fetch_field()) {
        $columns[] = $col->name;
    }
    
    $output = fopen('php://temp', 'w');
    fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF)); // UTF-8 BOM
    fputcsv($output, $columns);
    
    $count = 0;
    mysqli_data_seek($result, 0);
    while ($row = $result->fetch_assoc()) {
        $ordered_row = [];
        foreach ($columns as $col) {
            $ordered_row[] = $row[$col] ?? '';
        }
        fputcsv($output, $ordered_row);
        $count++;
    }
    
    rewind($output);
    $csv_content = stream_get_contents($output);
    fclose($output);
    $stmt->close();
    
    $zip->addFromString($filename, $csv_content);
    
    return ['table' => 'system_logs', 'count' => $count, 'filename' => $filename];
}

// ============================================
// DELETE FUNCTIONS - NOW WITH DATE RANGE
// ============================================

function deleteOldLeaves($conn, $from_date, $to_date) {
    $stmt = $conn->prepare("DELETE FROM leaves WHERE from_date BETWEEN ? AND ?");
    $stmt->bind_param("ss", $from_date, $to_date);
    $stmt->execute();
    $count = $stmt->affected_rows;
    $stmt->close();
    return $count;
}

function deleteOldPermissions($conn, $from_date, $to_date) {
    $stmt = $conn->prepare("DELETE FROM permissions WHERE permission_date BETWEEN ? AND ?");
    $stmt->bind_param("ss", $from_date, $to_date);
    $stmt->execute();
    $count = $stmt->affected_rows;
    $stmt->close();
    return $count;
}

function deleteOldTimesheets($conn, $from_date, $to_date) {
    $stmt = $conn->prepare("DELETE FROM timesheets WHERE entry_date BETWEEN ? AND ?");
    $stmt->bind_param("ss", $from_date, $to_date);
    $stmt->execute();
    $count = $stmt->affected_rows;
    $stmt->close();
    return $count;
}

function deleteOldNotifications($conn, $from_date, $to_date) {
    $stmt = $conn->prepare("DELETE FROM notifications WHERE DATE(created_at) BETWEEN ? AND ?");
    $stmt->bind_param("ss", $from_date, $to_date);
    $stmt->execute();
    $count = $stmt->affected_rows;
    $stmt->close();
    return $count;
}

function deleteOldSystemLogs($conn, $from_date, $to_date) {
    $stmt = $conn->prepare("DELETE FROM system_logs WHERE DATE(created_at) BETWEEN ? AND ?");
    $stmt->bind_param("ss", $from_date, $to_date);
    $stmt->execute();
    $count = $stmt->affected_rows;
    $stmt->close();
    return $count;
}

// ============================================
// DYNAMIC STATISTICS BASED ON DATE RANGE
// ============================================
$stats = [];
$stats['leaves'] = $conn->query("SELECT COUNT(*) as count FROM leaves WHERE from_date BETWEEN '$from_date' AND '$to_date'")->fetch_assoc()['count'];
$stats['permissions'] = $conn->query("SELECT COUNT(*) as count FROM permissions WHERE permission_date BETWEEN '$from_date' AND '$to_date'")->fetch_assoc()['count'];
$stats['timesheets'] = $conn->query("SELECT COUNT(*) as count FROM timesheets WHERE entry_date BETWEEN '$from_date' AND '$to_date'")->fetch_assoc()['count'];
$stats['notifications'] = $conn->query("SELECT COUNT(*) as count FROM notifications WHERE DATE(created_at) BETWEEN '$from_date' AND '$to_date'")->fetch_assoc()['count'];
$stats['system_logs'] = $conn->query("SELECT COUNT(*) as count FROM system_logs WHERE DATE(created_at) BETWEEN '$from_date' AND '$to_date'")->fetch_assoc()['count'];

$page_title = "Admin Manual Cleanup - MAKSIM HR";
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $page_title; ?></title>
    <?php include '../includes/head.php'; ?>
    <style>
        .cleanup-container {
            max-width: 900px;
            margin: 0 auto;
        }
        .admin-badge {
            background: #ed8936;
            color: white;
            padding: 5px 15px;
            border-radius: 20px;
            font-size: 14px;
            display: inline-block;
            margin-left: 15px;
        }
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 15px;
            margin-bottom: 30px;
        }
        .stat-card {
            background: white;
            padding: 20px;
            border-radius: 10px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.05);
            text-align: center;
            border-left: 4px solid #ed8936;
        }
        .stat-value {
            font-size: 28px;
            font-weight: bold;
            color: #dc3545;
        }
        .stat-label {
            color: #718096;
            font-size: 12px;
        }
        .stat-note {
            font-size: 10px;
            color: #ed8936;
            margin-top: 5px;
        }
        .table-checkbox {
            display: flex;
            align-items: center;
            padding: 15px;
            border: 2px solid #e2e8f0;
            border-radius: 10px;
            margin-bottom: 10px;
            transition: all 0.2s;
        }
        .table-checkbox:hover {
            border-color: #ed8936;
            background: #fff5e6;
        }
        .table-checkbox input {
            width: 20px;
            height: 20px;
            margin-right: 15px;
            cursor: pointer;
        }
        .table-checkbox input:checked {
            accent-color: #ed8936;
        }
        .table-checkbox label {
            flex: 1;
            cursor: pointer;
            font-weight: 600;
        }
        .table-checkbox .count {
            background: #dc3545;
            color: white;
            padding: 3px 10px;
            border-radius: 20px;
            font-size: 12px;
        }
        .btn-admin {
            background: #ed8936;
            color: white;
            padding: 15px 30px;
            font-size: 16px;
            border: none;
            border-radius: 10px;
            cursor: pointer;
            transition: all 0.2s;
            width: 100%;
            font-weight: 600;
        }
        .btn-admin:hover {
            background: #dd6b20;
            transform: translateY(-2px);
            box-shadow: 0 10px 20px rgba(237, 137, 54, 0.3);
        }
        .warning-box {
            background: #fff5f5;
            border-left: 4px solid #dc3545;
            padding: 15px;
            border-radius: 8px;
            margin: 20px 0;
            color: #721c24;
        }
        .admin-note {
            background: #f0f7ff;
            border-left: 4px solid #ed8936;
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
        }
        .date-range-picker {
            display: flex;
            gap: 15px;
            align-items: center;
            margin-bottom: 20px;
            flex-wrap: wrap;
        }
        .date-input {
            flex: 1;
            min-width: 200px;
        }
        .date-input label {
            display: block;
            margin-bottom: 5px;
            font-weight: 600;
            color: #4a5568;
        }
        .date-input input {
            width: 100%;
            padding: 10px;
            border: 2px solid #e2e8f0;
            border-radius: 8px;
        }
        .range-indicator {
            background: #ed8936;
            color: white;
            padding: 5px 15px;
            border-radius: 20px;
            font-size: 14px;
            display: inline-block;
        }
        .update-btn {
            background: #4299e1;
            color: white;
            border: none;
            padding: 10px 20px;
            border-radius: 8px;
            cursor: pointer;
            font-weight: 600;
        }
        .update-btn:hover {
            background: #3182ce;
        }
    </style>
</head>
<body>
    <?php include '../includes/header.php'; ?>
    
    <div class="app-main">
        <?php include '../includes/sidebar.php'; ?>
        
        <div class="main-content cleanup-container">
            <h2 class="page-title">
                <i class="icon-database"></i> Manual Data Cleanup
                <span class="admin-badge">👑 ADMIN ONLY</span>
            </h2>
            
            <div class="admin-note">
                <i class="icon-shield"></i> 
                <strong>Admin Access Only:</strong> This tool is restricted to administrators. 
                All actions are logged and cannot be undone.
            </div>
            
            <?php echo $message; ?>
            
            <!-- Date Range Selection -->
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title"><i class="icon-calendar"></i> Select Date Range</h3>
                </div>
                <form method="POST" id="dateRangeForm">
                    <div class="date-range-picker">
                        <div class="date-input">
                            <label>From Date:</label>
                            <input type="date" name="from_date" value="<?php echo $from_date; ?>" required>
                        </div>
                        <div class="date-input">
                            <label>To Date:</label>
                            <input type="date" name="to_date" value="<?php echo $to_date; ?>" required>
                        </div>
                        <div>
                            <button type="submit" name="update_range" class="update-btn">
                                <i class="icon-sync"></i> Update Stats
                            </button>
                        </div>
                    </div>
                </form>
            </div>
            
            <!-- Statistics - Based on selected date range -->
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-value"><?php echo $stats['leaves']; ?></div>
                    <div class="stat-label">Leaves in Range</div>
                    <div class="stat-note"><?php echo $from_date; ?> to <?php echo $to_date; ?></div>
                </div>
                <div class="stat-card">
                    <div class="stat-value"><?php echo $stats['permissions']; ?></div>
                    <div class="stat-label">Permissions in Range</div>
                    <div class="stat-note"><?php echo $from_date; ?> to <?php echo $to_date; ?></div>
                </div>
                <div class="stat-card">
                    <div class="stat-value"><?php echo $stats['timesheets']; ?></div>
                    <div class="stat-label">Timesheets in Range</div>
                    <div class="stat-note"><?php echo $from_date; ?> to <?php echo $to_date; ?></div>
                </div>
                <div class="stat-card">
                    <div class="stat-value"><?php echo $stats['notifications']; ?></div>
                    <div class="stat-label">Notifications in Range</div>
                    <div class="stat-note"><?php echo $from_date; ?> to <?php echo $to_date; ?></div>
                </div>
                <div class="stat-card">
                    <div class="stat-value"><?php echo $stats['system_logs']; ?></div>
                    <div class="stat-label">System Logs in Range</div>
                    <div class="stat-note"><?php echo $from_date; ?> to <?php echo $to_date; ?></div>
                </div>
            </div>
            
            <!-- Cleanup Form -->
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">
                        <i class="icon-trash"></i> Download & Delete Data 
                        <span class="range-indicator"><?php echo $from_date; ?> to <?php echo $to_date; ?></span>
                    </h3>
                </div>
                
                <form method="POST" id="cleanupForm" onsubmit="return confirmCleanup()">
                    <input type="hidden" name="from_date" value="<?php echo $from_date; ?>">
                    <input type="hidden" name="to_date" value="<?php echo $to_date; ?>">
                    
                    <div class="warning-box">
                        <strong>⚠️ ADMIN WARNING:</strong> This will download data and then 
                        <strong style="color: #dc3545; font-size: 16px;">PERMANENTLY DELETE</strong> it from the database. 
                        This action cannot be undone and will be logged!
                    </div>
                    
                    <label class="form-label"><strong>Select tables to cleanup:</strong></label>
                    
                    <div class="table-checkbox">
                        <input type="checkbox" name="tables[]" value="leaves" id="leaves" checked>
                        <label for="leaves">📋 Leave Applications</label>
                        <span class="count"><?php echo $stats['leaves']; ?> records</span>
                    </div>
                    
                    <div class="table-checkbox">
                        <input type="checkbox" name="tables[]" value="permissions" id="permissions" checked>
                        <label for="permissions">⏰ Permission Requests</label>
                        <span class="count"><?php echo $stats['permissions']; ?> records</span>
                    </div>
                    
                    <div class="table-checkbox">
                        <input type="checkbox" name="tables[]" value="timesheets" id="timesheets" checked>
                        <label for="timesheets">📝 Timesheet Entries</label>
                        <span class="count"><?php echo $stats['timesheets']; ?> records</span>
                    </div>
                    
                    <div class="table-checkbox">
                        <input type="checkbox" name="tables[]" value="notifications" id="notifications" checked>
                        <label for="notifications">🔔 Notifications</label>
                        <span class="count"><?php echo $stats['notifications']; ?> records</span>
                    </div>
                    
                    <div class="table-checkbox">
                        <input type="checkbox" name="tables[]" value="system_logs" id="system_logs" checked>
                        <label for="system_logs">📋 System Logs</label>
                        <span class="count"><?php echo $stats['system_logs']; ?> records</span>
                    </div>
                    
                    <div style="margin: 30px 0;">
                        <button type="submit" name="cleanup_and_download" class="btn-admin">
                            <i class="icon-database"></i> Download Selected & PERMANENTLY DELETE
                        </button>
                    </div>
                    
                    <div style="background: #f0f7ff; padding: 15px; border-radius: 8px;">
                        <p><strong>What will happen:</strong></p>
                        <ol style="margin-left: 20px;">
                            <li>✅ Selected data from <strong><?php echo $from_date; ?> to <?php echo $to_date; ?></strong> will be compiled into a ZIP file</li>
                            <li>⬇️ ZIP file will download directly to your computer</li>
                            <li>🗑️ After download, data will be <span style="color: #dc3545; font-weight: bold;">PERMANENTLY DELETED</span> from database</li>
                            <li>📊 Summary report included in ZIP file</li>
                            <li>📝 All actions are logged in system_logs</li>
                        </ol>
                    </div>
                </form>
            </div>
            
            <!-- Recent Cleanup Logs -->
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title"><i class="icon-history"></i> Recent Cleanup Logs</h3>
                </div>
                <div class="table-container">
                    <table>
                        <thead>
                            <tr>
                                <th>Date/Time</th>
                                <th>Admin</th>
                                <th>Action</th>
                                <th>Records</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            $logs = $conn->query("
                                SELECT l.*, u.full_name 
                                FROM system_logs l
                                LEFT JOIN users u ON l.user_id = u.id
                                WHERE l.event_type = 'manual_cleanup'
                                ORDER BY l.created_at DESC
                                LIMIT 10
                            ");
                            
                            if ($logs && $logs->num_rows > 0):
                                while ($log = $logs->fetch_assoc()):
                            ?>
                            <tr>
                                <td><?php echo date('Y-m-d H:i', strtotime($log['created_at'])); ?></td>
                                <td><?php echo $log['full_name'] ?? 'Unknown'; ?></td>
                                <td><?php echo substr($log['description'], 0, 50) . '...'; ?></td>
                                <td>
                                    <?php 
                                    preg_match('/Records exported: (\d+)/', $log['description'], $matches);
                                    echo $matches[1] ?? '-';
                                    ?>
                                </td>
                            </tr>
                            <?php 
                                endwhile;
                            else:
                            ?>
                            <tr>
                                <td colspan="4" style="text-align: center;">No cleanup logs found</td>
                            </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
    
    <script>
    function confirmCleanup() {
        // Check if any tables selected
        const checkboxes = document.querySelectorAll('input[name="tables[]"]:checked');
        if (checkboxes.length === 0) {
            alert('Please select at least one table to cleanup');
            return false;
        }
        
        // Count total records
        let totalRecords = 0;
        checkboxes.forEach(cb => {
            const countSpan = cb.closest('.table-checkbox').querySelector('.count');
            const count = parseInt(countSpan.textContent);
            if (!isNaN(count)) totalRecords += count;
        });
        
        const fromDate = document.querySelector('input[name="from_date"]').value;
        const toDate = document.querySelector('input[name="to_date"]').value;
        const selectedTables = Array.from(checkboxes).map(cb => {
            return cb.closest('.table-checkbox').querySelector('label').textContent.trim();
        });
        
        const message = `👑 ADMIN PERMISSION REQUIRED 👑\n\n` +
            `You are about to:\n` +
            `1. DOWNLOAD ${totalRecords} records from ${fromDate} to ${toDate}\n` +
            `2. PERMANENTLY DELETE them from the database\n\n` +
            `Tables affected:\n• ${selectedTables.join('\n• ')}\n\n` +
            `⚠️ THIS ACTION IS IRREVERSIBLE AND WILL BE LOGGED ⚠️\n\n` +
            `Type "ADMIN DELETE" to confirm:`;
        
        const confirmation = prompt(message);
        return confirmation === 'ADMIN DELETE';
    }
    
    // Select/Deselect all
    document.addEventListener('DOMContentLoaded', function() {
        const checkboxes = document.querySelectorAll('input[name="tables[]"]');
        const selectAllBtn = document.createElement('button');
        selectAllBtn.type = 'button';
        selectAllBtn.className = 'btn-small';
        selectAllBtn.style.marginBottom = '15px';
        selectAllBtn.style.background = '#ed8936';
        selectAllBtn.style.color = 'white';
        selectAllBtn.innerHTML = '✓ Select All';
        selectAllBtn.onclick = function() {
            const allChecked = Array.from(checkboxes).every(cb => cb.checked);
            checkboxes.forEach(cb => cb.checked = !allChecked);
            this.innerHTML = allChecked ? '✓ Select All' : '❌ Deselect All';
        };
        
        document.querySelector('.form-label').after(selectAllBtn);
    });
    
    // Validate dates
    document.querySelector('input[name="to_date"]').addEventListener('change', function() {
        const fromDate = document.querySelector('input[name="from_date"]').value;
        const toDate = this.value;
        
        if (fromDate && toDate && toDate < fromDate) {
            alert('To date cannot be before from date');
            this.value = fromDate;
        }
    });
    
    document.querySelector('input[name="from_date"]').addEventListener('change', function() {
        const fromDate = this.value;
        const toDate = document.querySelector('input[name="to_date"]').value;
        
        if (fromDate && toDate && toDate < fromDate) {
            document.querySelector('input[name="to_date"]').value = fromDate;
        }
    });
    </script>
    
    <script src="../assets/js/app.js"></script>
</body>
</html>