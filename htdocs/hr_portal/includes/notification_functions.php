<?php
// File: includes/notification_functions.php

/**
 * Create a notification for a user
 */
function createNotification($conn, $user_id, $type, $title, $message, $related_id = null) {
    // Ensure notifications table exists
    $conn->query("
        CREATE TABLE IF NOT EXISTS notifications (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            type VARCHAR(50) NOT NULL,
            title VARCHAR(255) NOT NULL,
            message TEXT NOT NULL,
            related_id INT NULL,
            is_read TINYINT DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX (user_id),
            INDEX (is_read),
            INDEX (created_at)
        )
    ");
    
    // Check if duplicate notification exists (within last hour)
    $check_dup = $conn->prepare("
        SELECT id FROM notifications 
        WHERE user_id = ? AND type = ? AND message = ? 
        AND created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)
    ");
    $check_dup->bind_param("iss", $user_id, $type, $message);
    $check_dup->execute();
    $dup_result = $check_dup->get_result();
    
    if ($dup_result->num_rows > 0) {
        $check_dup->close();
        return true; // Duplicate found, don't create another
    }
    $check_dup->close();
    
    $stmt = $conn->prepare("
        INSERT INTO notifications (user_id, type, title, message, related_id) 
        VALUES (?, ?, ?, ?, ?)
    ");
    
    if (!$stmt) {
        error_log("Failed to prepare notification statement: " . $conn->error);
        return false;
    }
    
    $stmt->bind_param("isssi", $user_id, $type, $title, $message, $related_id);
    $success = $stmt->execute();
    
    if (!$success) {
        error_log("Failed to insert notification: " . $stmt->error);
    }
    
    $stmt->close();
    return $success;
}

/**
 * Create notification for multiple users at once
 */
function createNotificationsForUsers($conn, $user_ids, $type, $title, $message, $related_id = null) {
    if (empty($user_ids)) return false;
    
    $success_count = 0;
    foreach ($user_ids as $user_id) {
        if (createNotification($conn, $user_id, $type, $title, $message, $related_id)) {
            $success_count++;
        }
    }
    
    return $success_count;
}

/**
 * Get all HR/Admin/dm users
 */
function getManagementUserIds($conn, $exclude_user_id = null) {
    $user_ids = [];
    
    $query = "SELECT id FROM users WHERE role IN ('hr', 'admin', 'DM', 'coo', 'ed')";
    if ($exclude_user_id) {
        $query .= " AND id != " . intval($exclude_user_id);
    }
    
    $result = $conn->query($query);
    while ($row = $result->fetch_assoc()) {
        $user_ids[] = $row['id'];
    }
    
    return $user_ids;
}

/**
 * Get user's reporting manager ID
 */
function getReportingManagerId($conn, $user_id) {
    $stmt = $conn->prepare("
        SELECT u2.id 
        FROM users u1
        JOIN users u2 ON u1.reporting_to = u2.username
        WHERE u1.id = ?
    ");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    $stmt->close();
    
    return $row ? $row['id'] : null;
}

/**
 * Get user's reporting manager name
 */
function getReportingManagerName($conn, $user_id) {
    $stmt = $conn->prepare("
        SELECT u2.full_name 
        FROM users u1
        JOIN users u2 ON u1.reporting_to = u2.username
        WHERE u1.id = ?
    ");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    $stmt->close();
    
    return $row ? $row['full_name'] : null;
}

/**
 * Get user details by ID
 */
function getUserDetails($conn, $user_id) {
    $stmt = $conn->prepare("SELECT id, username, full_name, role, email FROM users WHERE id = ?");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $user = $result->fetch_assoc();
    $stmt->close();
    
    return $user;
}

/**
 * Get user details by username
 */
function getUserDetailsByUsername($conn, $username) {
    $stmt = $conn->prepare("SELECT id, username, full_name, role, email FROM users WHERE username = ?");
    $stmt->bind_param("s", $username);
    $stmt->execute();
    $result = $stmt->get_result();
    $user = $result->fetch_assoc();
    $stmt->close();
    
    return $user;
}

/**
 * Create notification for timesheet - COMPREHENSIVE VERSION
 */
function createTimesheetNotification($conn, $timesheet_id, $user_id, $action, $approver_id = null, $additional_data = []) {
    // Get user details
    $user = getUserDetails($conn, $user_id);
    if (!$user) return false;
    
    // Get timesheet details
    $ts_query = $conn->prepare("SELECT * FROM timesheets WHERE id = ?");
    $ts_query->bind_param("i", $timesheet_id);
    $ts_query->execute();
    $timesheet = $ts_query->get_result()->fetch_assoc();
    $ts_query->close();
    
    if (!$timesheet) return false;
    
    $entry_date = date('d M Y', strtotime($timesheet['entry_date']));
    $raw_hours = floatval($timesheet['hours']);
    $h_whole = floor($raw_hours);
    $h_mins  = round(($raw_hours - $h_whole) * 60);
    $hours = $h_whole . 'h ' . $h_mins . 'm';
    
    // Get approver name if provided
    $approver_name = '';
    if ($approver_id) {
        $approver = getUserDetails($conn, $approver_id);
        $approver_name = $approver ? $approver['full_name'] : '';
    }
    
    // Get reporting manager ID
    $manager_id = getReportingManagerId($conn, $user_id);
    
    // Get all management IDs (HR/Admin/dm)
    $management_ids = getManagementUserIds($conn, $user_id);
    
    switch ($action) {
        case 'submitted':
            // Check if this is a late submission
            $entry_date_ts = strtotime($timesheet['entry_date']);
            $submitted_date_ts = strtotime($timesheet['submitted_date']);
            $end_of_day = strtotime($timesheet['entry_date'] . ' 23:59:59');
            $is_late = ($submitted_date_ts > $end_of_day);
            
            $late_text = $is_late ? "LATE " : "";
            $title = $is_late ? "⏰ Late Timesheet Submitted" : "📝 Timesheet Submitted";
            
            // 1. Notify the user themselves (confirmation)
            $user_title = "Timesheet " . ($is_late ? "Submitted Late" : "Submitted");
            $user_message = "Your timesheet for {$entry_date} ({$hours} hours) has been submitted" . ($is_late ? " LATE" : "") . ".";
            createNotification($conn, $user_id, $is_late ? 'late_timesheet_submitted' : 'timesheet_submitted', $user_title, $user_message, $timesheet_id);
            
            // 2. Notify reporting manager
            if ($manager_id) {
                $manager_title = "{$late_text}Timesheet Submission - {$user['full_name']}";
                $manager_message = "{$user['full_name']} has submitted a {$late_text}timesheet for {$entry_date} ({$hours} hours).";
                createNotification($conn, $manager_id, $is_late ? 'late_timesheet' : 'timesheet_submitted', $manager_title, $manager_message, $timesheet_id);
            }
            
            // 3. Notify all HR/Admin/dm
            $mgmt_title = "{$late_text}Timesheet Submitted - {$user['full_name']}";
            $mgmt_message = "{$user['full_name']} has submitted a {$late_text}timesheet for {$entry_date} ({$hours} hours).";
            createNotificationsForUsers($conn, $management_ids, $is_late ? 'late_timesheet' : 'timesheet_submitted', $mgmt_title, $mgmt_message, $timesheet_id);
            
            error_log("Timesheet submitted notification created for user $user_id, late: " . ($is_late ? 'yes' : 'no'));
            break;
            
        case 'approved':
            $title = "✅ Timesheet Approved";
            $message = "Your timesheet for {$entry_date} ({$hours} hours) has been approved";
            
            // Check if it's Sunday work (gets bonus leave)
            $is_sunday = (date('N', strtotime($timesheet['entry_date'])) == 7);
            if ($is_sunday) {
                $message .= " and you have received +1 casual leave for Sunday work!";
            }
            
            if ($approver_name) {
                $message .= " by {$approver_name}";
            }
            
            // Notify the user
            createNotification($conn, $user_id, 'timesheet_approved', $title, $message, $timesheet_id);
            
            // Notify manager who approved (if different from user)
            if ($approver_id && $approver_id != $user_id) {
                $approver_title = "Timesheet Approved";
                $approver_message = "You approved {$user['full_name']}'s timesheet for {$entry_date} ({$hours} hours).";
                createNotification($conn, $approver_id, 'timesheet_approved_by_you', $approver_title, $approver_message, $timesheet_id);
            }
            
            error_log("Timesheet approved notification created for user $user_id");
            break;
            
        case 'rejected':
            $title = "❌ Timesheet Rejected";
            $message = "Your timesheet for {$entry_date} ({$hours} hours) has been rejected";
            
            if ($approver_name) {
                $message .= " by {$approver_name}";
            }
            
            // Add reason if provided
            if (!empty($additional_data['reason'])) {
                $message .= ". Reason: " . $additional_data['reason'];
            }
            
            // Notify the user
            createNotification($conn, $user_id, 'timesheet_rejected', $title, $message, $timesheet_id);
            
            // Notify manager who rejected (if different from user)
            if ($approver_id && $approver_id != $user_id) {
                $approver_title = "Timesheet Rejected";
                $approver_message = "You rejected {$user['full_name']}'s timesheet for {$entry_date} ({$hours} hours).";
                createNotification($conn, $approver_id, 'timesheet_rejected_by_you', $approver_title, $approver_message, $timesheet_id);
            }
            
            error_log("Timesheet rejected notification created for user $user_id");
            break;
            
        case 'deleted':
            $title = "🗑️ Timesheet Deleted";
            $message = "Your timesheet for {$entry_date} has been deleted";
            
            if (!empty($additional_data['deleted_by'])) {
                $message .= " by " . $additional_data['deleted_by'];
            }
            
            if (!empty($additional_data['reason'])) {
                $message .= ". Reason: " . $additional_data['reason'];
            }
            
            createNotification($conn, $user_id, 'timesheet_deleted', $title, $message, $timesheet_id);
            
            // Notify managers about deletion
            $mgmt_title = "Timesheet Deleted - {$user['full_name']}";
            $mgmt_message = "{$user['full_name']}'s timesheet for {$entry_date} has been deleted.";
            if (!empty($additional_data['deleted_by'])) {
                $mgmt_message .= " Deleted by: " . $additional_data['deleted_by'];
            }
            createNotificationsForUsers($conn, $management_ids, 'timesheet_deleted', $mgmt_title, $mgmt_message, $timesheet_id);
            
            error_log("Timesheet deleted notification created for user $user_id");
            break;
            
        case 'cancelled':
            $title = "↩️ Timesheet Cancelled";
            $message = "Your timesheet for {$entry_date} ({$hours} hours) has been cancelled";
            
            if (!empty($additional_data['cancelled_by'])) {
                $message .= " by " . $additional_data['cancelled_by'];
            }
            
            createNotification($conn, $user_id, 'timesheet_cancelled', $title, $message, $timesheet_id);
            
            error_log("Timesheet cancelled notification created for user $user_id");
            break;
    }
    
    return true;
}

/**
 * Create notification for leave application - COMPREHENSIVE VERSION
 */
function createLeaveNotification($conn, $leave_id, $user_id, $action, $approver_id = null, $additional_data = []) {
    // Get user details
    $user = getUserDetails($conn, $user_id);
    if (!$user) return false;
    
    // Get leave details
    $leave_query = $conn->prepare("SELECT * FROM leaves WHERE id = ?");
    $leave_query->bind_param("i", $leave_id);
    $leave_query->execute();
    $leave = $leave_query->get_result()->fetch_assoc();
    $leave_query->close();
    
    if (!$leave) return false;
    
    $leave_type = $leave['leave_type'];
    $from_date = date('d M Y', strtotime($leave['from_date']));
    $to_date = date('d M Y', strtotime($leave['to_date']));
    $days = $leave['days'];
    
    // Get approver name if provided
    $approver_name = '';
    if ($approver_id) {
        $approver = getUserDetails($conn, $approver_id);
        $approver_name = $approver ? $approver['full_name'] : '';
    }
    
    // Get reporting manager ID
    $manager_id = getReportingManagerId($conn, $user_id);
    
    // Get all management IDs (HR/Admin/dm)
    $management_ids = getManagementUserIds($conn, $user_id);
    
    // Determine icon based on action and type
    $is_lop = ($leave_type == 'LOP');
    $type_prefix = $is_lop ? 'lop' : 'leave';
    
    switch ($action) {
        case 'submitted':
            // 1. Notify the user themselves (confirmation)
            $user_title = "📋 Leave Application Submitted";
            $user_message = "Your {$leave_type} leave request for {$days} day(s) from {$from_date} to {$to_date} has been submitted and is pending approval.";
            createNotification($conn, $user_id, $type_prefix . '_submitted', $user_title, $user_message, $leave_id);
            
            // 2. Notify reporting manager
            if ($manager_id) {
                $manager_title = "New Leave Request - {$user['full_name']}";
                $manager_message = "{$user['full_name']} has submitted a {$leave_type} leave request for {$days} day(s) from {$from_date} to {$to_date}.";
                createNotification($conn, $manager_id, $type_prefix . '_submitted', $manager_title, $manager_message, $leave_id);
            }
            
            // 3. Notify all HR/Admin/dm
            $mgmt_title = "New Leave Request - {$user['full_name']}";
            $mgmt_message = "{$user['full_name']} has submitted a {$leave_type} leave request for {$days} day(s) from {$from_date} to {$to_date}.";
            createNotificationsForUsers($conn, $management_ids, $type_prefix . '_submitted', $mgmt_title, $mgmt_message, $leave_id);
            
            error_log("Leave submitted notification created for user $user_id");
            break;
            
        case 'approved':
            $title = $is_lop ? "💰 LOP Approved" : "✅ Leave Approved";
            $message = "Your {$leave_type} leave request for {$days} day(s) from {$from_date} to {$to_date} has been approved";
            
            if ($approver_name) {
                $message .= " by {$approver_name}";
            }
            
            // Notify the user
            createNotification($conn, $user_id, $type_prefix . '_approved', $title, $message, $leave_id);
            
            // Notify approver
            if ($approver_id && $approver_id != $user_id) {
                $approver_title = $is_lop ? "LOP Approved" : "Leave Approved";
                $approver_message = "You approved {$user['full_name']}'s {$leave_type} leave request for {$days} day(s).";
                createNotification($conn, $approver_id, $type_prefix . '_approved_by_you', $approver_title, $approver_message, $leave_id);
            }
            
            // Notify managers about approval
            $mgmt_title = "Leave Approved - {$user['full_name']}";
            $mgmt_message = "{$user['full_name']}'s {$leave_type} leave request for {$days} day(s) has been approved by {$approver_name}.";
            createNotificationsForUsers($conn, $management_ids, $type_prefix . '_approved', $mgmt_title, $mgmt_message, $leave_id);
            
            error_log("Leave approved notification created for user $user_id");
            break;
            
        case 'rejected':
            $title = $is_lop ? "💰 LOP Rejected" : "❌ Leave Rejected";
            $message = "Your {$leave_type} leave request for {$days} day(s) from {$from_date} to {$to_date} has been rejected";
            
            if ($approver_name) {
                $message .= " by {$approver_name}";
            }
            
            if (!empty($additional_data['reason'])) {
                $message .= ". Reason: " . $additional_data['reason'];
            }
            
            // Notify the user
            createNotification($conn, $user_id, $type_prefix . '_rejected', $title, $message, $leave_id);
            
            // Notify approver
            if ($approver_id && $approver_id != $user_id) {
                $approver_title = $is_lop ? "LOP Rejected" : "Leave Rejected";
                $approver_message = "You rejected {$user['full_name']}'s {$leave_type} leave request.";
                if (!empty($additional_data['reason'])) {
                    $approver_message .= " Reason: " . $additional_data['reason'];
                }
                createNotification($conn, $approver_id, $type_prefix . '_rejected_by_you', $approver_title, $approver_message, $leave_id);
            }
            
            error_log("Leave rejected notification created for user $user_id");
            break;
            
        case 'deleted':
        case 'cancelled':
            $action_text = ($action == 'deleted') ? 'deleted' : 'cancelled';
            $title = $is_lop ? "💰 LOP {$action_text}" : "🗑️ Leave {$action_text}";
            $message = "Your {$leave_type} leave request for {$days} day(s) from {$from_date} to {$to_date} has been {$action_text}";
            
            if (!empty($additional_data['deleted_by'])) {
                $message .= " by " . $additional_data['deleted_by'];
            }
            
            if (!empty($additional_data['reason'])) {
                $message .= ". Reason: " . $additional_data['reason'];
            }
            
            createNotification($conn, $user_id, $type_prefix . '_' . $action, $title, $message, $leave_id);
            
            // Notify managers about deletion/cancellation
            $mgmt_title = "Leave {$action_text} - {$user['full_name']}";
            $mgmt_message = "{$user['full_name']}'s {$leave_type} leave request has been {$action_text}.";
            if (!empty($additional_data['deleted_by'])) {
                $mgmt_message .= " By: " . $additional_data['deleted_by'];
            }
            createNotificationsForUsers($conn, $management_ids, $type_prefix . '_' . $action, $mgmt_title, $mgmt_message, $leave_id);
            
            error_log("Leave $action notification created for user $user_id");
            break;
    }
    
    return true;
}

/**
 * Create notification for permission request - COMPREHENSIVE VERSION
 */
function createPermissionNotification($conn, $permission_id, $user_id, $action, $approver_id = null, $additional_data = []) {
    // Get user details
    $user = getUserDetails($conn, $user_id);
    if (!$user) return false;
    
    // Get permission details
    $perm_query = $conn->prepare("SELECT * FROM permissions WHERE id = ?");
    $perm_query->bind_param("i", $permission_id);
    $perm_query->execute();
    $permission = $perm_query->get_result()->fetch_assoc();
    $perm_query->close();
    
    if (!$permission) return false;
    
    $permission_date = date('d M Y', strtotime($permission['permission_date']));
    $duration = floatval($permission['duration']);
    
    // Format duration text
    if ($duration == 1) {
        $duration_text = "1 hour";
    } elseif ($duration < 1) {
        $duration_text = ($duration * 60) . " minutes";
    } elseif ($duration == 8) {
        $duration_text = "full day";
    } else {
        $duration_text = $duration . " hours";
    }
    
    // Check if this is LOP permission
    $reason_lower = strtolower($permission['reason'] ?? '');
    $is_lop = (strpos($reason_lower, 'lop') !== false) || 
              (strpos($reason_lower, 'loss of pay') !== false) ||
              (strpos($reason_lower, 'excess') !== false) ||
              ($permission['status'] == 'LOP') ||
              (isset($additional_data['is_lop']) && $additional_data['is_lop']);
    
    // Get approver name if provided
    $approver_name = '';
    if ($approver_id) {
        $approver = getUserDetails($conn, $approver_id);
        $approver_name = $approver ? $approver['full_name'] : '';
    }
    
    // Get reporting manager ID
    $manager_id = getReportingManagerId($conn, $user_id);
    
    // Get all management IDs (HR/Admin/dm)
    $management_ids = getManagementUserIds($conn, $user_id);
    
    // Determine type prefix
    $type_prefix = $is_lop ? 'lop' : 'permission';
    
    switch ($action) {
        case 'submitted':
            // 1. Notify the user themselves (confirmation)
            $user_title = $is_lop ? "💰 LOP Permission Submitted" : "⏰ Permission Request Submitted";
            $user_message = "Your permission request for {$duration_text} on {$permission_date} has been submitted and is pending approval.";
            createNotification($conn, $user_id, $type_prefix . '_submitted', $user_title, $user_message, $permission_id);
            
            // 2. Notify reporting manager
            if ($manager_id) {
                $manager_title = "New Permission Request - {$user['full_name']}";
                $manager_message = "{$user['full_name']} has submitted a " . ($is_lop ? "LOP " : "") . "permission request for {$duration_text} on {$permission_date}.";
                createNotification($conn, $manager_id, $type_prefix . '_submitted', $manager_title, $manager_message, $permission_id);
            }
            
            // 3. Notify all HR/Admin/dm
            $mgmt_title = "New Permission Request - {$user['full_name']}";
            $mgmt_message = "{$user['full_name']} has submitted a " . ($is_lop ? "LOP " : "") . "permission request for {$duration_text} on {$permission_date}.";
            createNotificationsForUsers($conn, $management_ids, $type_prefix . '_submitted', $mgmt_title, $mgmt_message, $permission_id);
            
            error_log("Permission submitted notification created for user $user_id, is_lop: " . ($is_lop ? 'yes' : 'no'));
            break;
            
        case 'approved':
            $title = $is_lop ? "💰 LOP Permission Approved" : "✅ Permission Approved";
            $message = "Your permission request for {$duration_text} on {$permission_date} has been approved";
            
            if ($approver_name) {
                $message .= " by {$approver_name}";
            }
            
            // Notify the user
            createNotification($conn, $user_id, $type_prefix . '_approved', $title, $message, $permission_id);
            
            // Notify approver
            if ($approver_id && $approver_id != $user_id) {
                $approver_title = $is_lop ? "LOP Permission Approved" : "Permission Approved";
                $approver_message = "You approved {$user['full_name']}'s permission request for {$duration_text} on {$permission_date}.";
                createNotification($conn, $approver_id, $type_prefix . '_approved_by_you', $approver_title, $approver_message, $permission_id);
            }
            
            // Notify managers about approval
            $mgmt_title = "Permission Approved - {$user['full_name']}";
            $mgmt_message = "{$user['full_name']}'s " . ($is_lop ? "LOP " : "") . "permission request for {$duration_text} has been approved by {$approver_name}.";
            createNotificationsForUsers($conn, $management_ids, $type_prefix . '_approved', $mgmt_title, $mgmt_message, $permission_id);
            
            error_log("Permission approved notification created for user $user_id");
            break;
            
        case 'rejected':
            $title = $is_lop ? "💰 LOP Permission Rejected" : "❌ Permission Rejected";
            $message = "Your permission request for {$duration_text} on {$permission_date} has been rejected";
            
            if ($approver_name) {
                $message .= " by {$approver_name}";
            }
            
            if (!empty($additional_data['reason'])) {
                $message .= ". Reason: " . $additional_data['reason'];
            }
            
            // Notify the user
            createNotification($conn, $user_id, $type_prefix . '_rejected', $title, $message, $permission_id);
            
            // Notify approver
            if ($approver_id && $approver_id != $user_id) {
                $approver_title = $is_lop ? "LOP Permission Rejected" : "Permission Rejected";
                $approver_message = "You rejected {$user['full_name']}'s permission request.";
                if (!empty($additional_data['reason'])) {
                    $approver_message .= " Reason: " . $additional_data['reason'];
                }
                createNotification($conn, $approver_id, $type_prefix . '_rejected_by_you', $approver_title, $approver_message, $permission_id);
            }
            
            error_log("Permission rejected notification created for user $user_id");
            break;
            
        case 'deleted':
        case 'cancelled':
            $action_text = ($action == 'deleted') ? 'deleted' : 'cancelled';
            $title = $is_lop ? "💰 LOP Permission {$action_text}" : "🗑️ Permission {$action_text}";
            $message = "Your permission request for {$duration_text} on {$permission_date} has been {$action_text}";
            
            if (!empty($additional_data['deleted_by'])) {
                $message .= " by " . $additional_data['deleted_by'];
            }
            
            if (!empty($additional_data['reason'])) {
                $message .= ". Reason: " . $additional_data['reason'];
            }
            
            createNotification($conn, $user_id, $type_prefix . '_' . $action, $title, $message, $permission_id);
            
            // Notify managers about deletion/cancellation
            $mgmt_title = "Permission {$action_text} - {$user['full_name']}";
            $mgmt_message = "{$user['full_name']}'s permission request has been {$action_text}.";
            if (!empty($additional_data['deleted_by'])) {
                $mgmt_message .= " By: " . $additional_data['deleted_by'];
            }
            createNotificationsForUsers($conn, $management_ids, $type_prefix . '_' . $action, $mgmt_title, $mgmt_message, $permission_id);
            
            error_log("Permission $action notification created for user $user_id");
            break;
    }
    
    return true;
}

/**
 * Create notification for batch operations (multiple leaves/permissions)
 */
function createBatchNotification($conn, $user_ids, $type, $title, $message, $related_ids = []) {
    $related_id = !empty($related_ids) ? $related_ids[0] : null;
    return createNotificationsForUsers($conn, $user_ids, $type, $title, $message, $related_id);
}

/**
 * Create daily summary notification for managers
 */
function createDailySummaryNotification($conn, $manager_id) {
    $today = date('Y-m-d');
    $tomorrow = date('Y-m-d', strtotime('+1 day'));
    
    // Count pending leaves
    $leaves_query = $conn->prepare("
        SELECT COUNT(*) as count FROM leaves 
        WHERE status = 'Pending' AND DATE(applied_date) = ?
    ");
    $leaves_query->bind_param("s", $today);
    $leaves_query->execute();
    $leaves_result = $leaves_query->get_result();
    $pending_leaves = $leaves_result->fetch_assoc()['count'];
    $leaves_query->close();
    
    // Count pending permissions
    $perms_query = $conn->prepare("
        SELECT COUNT(*) as count FROM permissions 
        WHERE status = 'Pending' AND DATE(applied_date) = ?
    ");
    $perms_query->bind_param("s", $today);
    $perms_query->execute();
    $perms_result = $perms_query->get_result();
    $pending_permissions = $perms_result->fetch_assoc()['count'];
    $perms_query->close();
    
    // Count late timesheets
    $late_query = $conn->prepare("
        SELECT COUNT(*) as count FROM timesheets 
        WHERE submitted_date > CONCAT(entry_date, ' 23:59:59')
        AND DATE(submitted_date) = ?
    ");
    $late_query->bind_param("s", $today);
    $late_query->execute();
    $late_result = $late_query->get_result();
    $late_timesheets = $late_result->fetch_assoc()['count'];
    $late_query->close();
    
    $total = $pending_leaves + $pending_permissions + $late_timesheets;
    
    if ($total > 0) {
        $title = "📊 Daily Summary - " . date('d M Y');
        $message = "Today's pending items: {$pending_leaves} leaves, {$pending_permissions} permissions, {$late_timesheets} late timesheets.";
        createNotification($conn, $manager_id, 'daily_summary', $title, $message);
    }
}

/**
 * Get unread notification count
 */
function getUnreadNotificationCount($conn, $user_id) {
    $stmt = $conn->prepare("SELECT COUNT(*) as count FROM notifications WHERE user_id = ? AND is_read = 0");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    $stmt->close();
    return (int)$row['count'];
}

/**
 * Get recent notifications
 */
function getRecentNotifications($conn, $user_id, $limit = 10) {
    $stmt = $conn->prepare("
        SELECT * FROM notifications 
        WHERE user_id = ? 
        ORDER BY created_at DESC 
        LIMIT ?
    ");
    $stmt->bind_param("ii", $user_id, $limit);
    $stmt->execute();
    $result = $stmt->get_result();
    $notifications = $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $notifications;
}

/**
 * Mark notification as read
 */
function markNotificationRead($conn, $notification_id, $user_id) {
    $stmt = $conn->prepare("
        UPDATE notifications 
        SET is_read = 1 
        WHERE id = ? AND user_id = ?
    ");
    $stmt->bind_param("ii", $notification_id, $user_id);
    $success = $stmt->execute();
    $stmt->close();
    return $success;
}

/**
 * Mark all notifications as read for a user
 */
function markAllNotificationsRead($conn, $user_id) {
    $stmt = $conn->prepare("
        UPDATE notifications 
        SET is_read = 1 
        WHERE user_id = ? AND is_read = 0
    ");
    $stmt->bind_param("i", $user_id);
    $success = $stmt->execute();
    $affected = $stmt->affected_rows;
    $stmt->close();
    
    error_log("Marked $affected notifications as read for user $user_id");
    return ['success' => $success, 'affected' => $affected];
}

/**
 * Cleanup old notifications (older than 30 days)
 */
function cleanupOldNotifications($conn) {
    $stmt = $conn->prepare("
        DELETE FROM notifications 
        WHERE created_at < DATE_SUB(NOW(), INTERVAL 30 DAY)
    ");
    $stmt->execute();
    $deleted = $stmt->affected_rows;
    $stmt->close();
    return $deleted;
}

/**
 * Delete notification
 */
function deleteNotification($conn, $notification_id, $user_id) {
    $stmt = $conn->prepare("DELETE FROM notifications WHERE id = ? AND user_id = ?");
    $stmt->bind_param("ii", $notification_id, $user_id);
    $success = $stmt->execute();
    $stmt->close();
    return $success;
}

/**
 * Get notification by ID
 */
function getNotificationById($conn, $notification_id, $user_id) {
    $stmt = $conn->prepare("SELECT * FROM notifications WHERE id = ? AND user_id = ?");
    $stmt->bind_param("ii", $notification_id, $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $notification = $result->fetch_assoc();
    $stmt->close();
    return $notification;
}
?>