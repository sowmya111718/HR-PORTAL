<?php
// File: includes/leave_functions.php
// ============================================
// LEAVE YEAR CALCULATION FUNCTIONS
// FROM: March 16, 2026 TO: March 15, 2027
// RESETS EVERY YEAR ON MARCH 16
// ============================================

/**
 * Get current leave year based on March 16 - March 15 cycle
 */
function getCurrentLeaveYear() {
    $today = new DateTime();
    $current_year = (int)$today->format('Y');
    $current_month = (int)$today->format('m');
    $current_day = (int)$today->format('d');
    
    // Check if current date is on or after March 16
    if ($current_month > 3 || ($current_month == 3 && $current_day >= 16)) {
        // Leave year: March 16, YYYY to March 15, YYYY+1
        $start_year = $current_year;
        $end_year = $current_year + 1;
        $is_new_year = ($current_month == 3 && $current_day == 16);
    } else {
        // Leave year: March 16, YYYY-1 to March 15, YYYY
        $start_year = $current_year - 1;
        $end_year = $current_year;
        $is_new_year = false;
    }
    
    $start_date = "{$start_year}-03-16";
    $end_date = "{$end_year}-03-15";
    $year_label = "{$start_year}-{$end_year}";
    
    return [
        'start_date' => $start_date,
        'end_date' => $end_date,
        'year_label' => $year_label,
        'start_year' => $start_year,
        'end_year' => $end_year,
        'is_new_year' => $is_new_year
    ];
}

/**
 * Get previous leave year
 */
function getPreviousLeaveYear() {
    $current = getCurrentLeaveYear();
    $start_year = $current['start_year'] - 1;
    $end_year = $current['end_year'] - 1;
    
    return [
        'start_date' => "{$start_year}-03-16",
        'end_date' => "{$end_year}-03-15",
        'year_label' => "{$start_year}-{$end_year}",
        'start_year' => $start_year,
        'end_year' => $end_year
    ];
}

/**
 * Get current month key (YYYY-MM)
 */
function getCurrentMonthKey() {
    return date('Y-m');
}

/**
 * Get previous month key
 */
function getPreviousMonthKey() {
    $date = new DateTime();
    $date->modify('-1 month');
    return $date->format('Y-m');
}

/**
 * Get month key from date
 */
function getMonthKeyFromDate($date) {
    return date('Y-m', strtotime($date));
}

/**
 * Check if date is within current leave year
 */
function isInCurrentLeaveYear($date) {
    $leave_year = getCurrentLeaveYear();
    return ($date >= $leave_year['start_date'] && $date <= $leave_year['end_date']);
}

/**
 * Get leave year for a specific date
 */
function getLeaveYearForDate($date) {
    $date_obj = new DateTime($date);
    $year = (int)$date_obj->format('Y');
    $month = (int)$date_obj->format('m');
    $day = (int)$date_obj->format('d');
    
    if ($month > 3 || ($month == 3 && $day >= 16)) {
        $start_year = $year;
        $end_year = $year + 1;
    } else {
        $start_year = $year - 1;
        $end_year = $year;
    }
    
    return [
        'start_date' => "{$start_year}-03-16",
        'end_date' => "{$end_year}-03-15",
        'year_label' => "{$start_year}-{$end_year}",
        'start_year' => $start_year,
        'end_year' => $end_year
    ];
}

/**
 * Check if user took sick leave in previous month
 */
function hasSickLeaveInPreviousMonth($conn, $user_id) {
    $prev_month_key = getPreviousMonthKey();
    $prev_month_start = $prev_month_key . '-01';
    $prev_month_end = date('Y-m-t', strtotime($prev_month_start));
    
    $stmt = $conn->prepare("
        SELECT COUNT(*) as count
        FROM leaves 
        WHERE user_id = ? 
        AND leave_type = 'Sick'
        AND status = 'Approved'
        AND from_date BETWEEN ? AND ?
    ");
    $stmt->bind_param("iss", $user_id, $prev_month_start, $prev_month_end);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    $stmt->close();
    
    return $row['count'] > 0;
}

/**
 * Get user's casual leave used in current month
 */
function getCasualLeaveUsedThisMonth($conn, $user_id, $month_key = null) {
    if (!$month_key) {
        $month_key = getCurrentMonthKey();
    }
    
    $month_start = $month_key . '-01';
    $month_end = date('Y-m-t', strtotime($month_start));
    
    $stmt = $conn->prepare("
        SELECT COALESCE(SUM(days), 0) as used_days
        FROM leaves 
        WHERE user_id = ? 
        AND leave_type = 'Casual'
        AND status IN ('Approved', 'Pending')
        AND from_date BETWEEN ? AND ?
    ");
    $stmt->bind_param("iss", $user_id, $month_start, $month_end);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    $stmt->close();
    
    return floatval($row['used_days']);
}

/**
 * Get user's sick leave used in current year
 */
function getSickLeaveUsedYearly($conn, $user_id, $leave_year) {
    $stmt = $conn->prepare("
        SELECT COALESCE(SUM(days), 0) as used_days
        FROM leaves 
        WHERE user_id = ? 
        AND leave_type = 'Sick'
        AND status IN ('Approved', 'Pending')
        AND from_date BETWEEN ? AND ?
    ");
    $stmt->bind_param("iss", $user_id, $leave_year['start_date'], $leave_year['end_date']);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    $stmt->close();
    
    return floatval($row['used_days']);
}

/**
 * Get user's sick leave used in current month
 */
function getSickLeaveUsedThisMonth($conn, $user_id) {
    $current_month_key = getCurrentMonthKey();
    $month_start = $current_month_key . '-01';
    $month_end = date('Y-m-t', strtotime($month_start));
    
    $stmt = $conn->prepare("
        SELECT COALESCE(SUM(days), 0) as used_days
        FROM leaves 
        WHERE user_id = ? 
        AND leave_type = 'Sick'
        AND status IN ('Approved', 'Pending')
        AND from_date BETWEEN ? AND ?
    ");
    $stmt->bind_param("iss", $user_id, $month_start, $month_end);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    $stmt->close();
    
    return floatval($row['used_days']);
}

/**
 * Get current month casual usage
 */
function getCurrentMonthCasualUsage($conn, $user_id) {
    $current_month_key = getCurrentMonthKey();
    $month_start = $current_month_key . '-01';
    $month_end = date('Y-m-t', strtotime($month_start));
    
    $stmt = $conn->prepare("
        SELECT COALESCE(SUM(days), 0) as used_days
        FROM leaves 
        WHERE user_id = ? 
        AND leave_type = 'Casual'
        AND status IN ('Approved', 'Pending')
        AND from_date BETWEEN ? AND ?
    ");
    $stmt->bind_param("iss", $user_id, $month_start, $month_end);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    $stmt->close();
    
    return floatval($row['used_days']);
}

/**
 * Get total LOP count for user
 */
function getLOPCount($conn, $user_id) {
    $leave_year = getCurrentLeaveYear();
    
    $stmt = $conn->prepare("
        SELECT COALESCE(SUM(days), 0) as total_lop
        FROM leaves 
        WHERE user_id = ? 
        AND leave_type = 'LOP'
        AND status IN ('Approved', 'Pending')
        AND from_date BETWEEN ? AND ?
    ");
    $stmt->bind_param("iss", $user_id, $leave_year['start_date'], $leave_year['end_date']);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    $stmt->close();
    
    return floatval($row['total_lop']);
}

/**
 * Get current month LOP usage
 */
function getCurrentMonthLOPUsage($conn, $user_id) {
    $current_month_key = getCurrentMonthKey();
    $month_start = $current_month_key . '-01';
    $month_end = date('Y-m-t', strtotime($month_start));
    
    $stmt = $conn->prepare("
        SELECT COALESCE(SUM(days), 0) as lop_days
        FROM leaves 
        WHERE user_id = ? 
        AND leave_type = 'LOP'
        AND status IN ('Approved', 'Pending')
        AND from_date BETWEEN ? AND ?
    ");
    $stmt->bind_param("iss", $user_id, $month_start, $month_end);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    $stmt->close();
    
    return floatval($row['lop_days']);
}

/**
 * Calculate casual leave balance for a specific month
 * Only 1 casual leave per month maximum
 */
function calculateCasualLeaveBalance($conn, $user_id, $month_key = null) {
    if (!$month_key) {
        $month_key = getCurrentMonthKey();
    }
    
    // Strict limit: ONLY 1 casual leave per month maximum
    $MAX_CASUAL_PER_MONTH = 1;
    
    // Get used days this month
    $used_this_month = getCasualLeaveUsedThisMonth($conn, $user_id, $month_key);
    
    // Calculate remaining casual days for this month
    $remaining = max(0, $MAX_CASUAL_PER_MONTH - $used_this_month);
    
    return [
        'month_key' => $month_key,
        'max_per_month' => $MAX_CASUAL_PER_MONTH,
        'used_this_month' => $used_this_month,
        'remaining' => $remaining,
        'can_take_this_month' => $remaining > 0,
        'exceeded' => $used_this_month >= $MAX_CASUAL_PER_MONTH
    ];
}

/**
 * Check if user can take sick leave in current month
 */
function canTakeSickLeave($conn, $user_id) {
    // Check if user took sick leave in previous month
    $has_sick_prev = hasSickLeaveInPreviousMonth($conn, $user_id);
    
    if ($has_sick_prev) {
        return [
            'can_take' => false,
            'reason' => 'You took sick leave in the previous month. Sick leave cannot be taken in consecutive months.',
            'message' => 'Sick leave not available this month'
        ];
    }
    
    // Get used sick days this month
    $used_this_month = getSickLeaveUsedThisMonth($conn, $user_id);
    
    // Maximum 1 sick leave per month
    $max_per_month = 1;
    
    if ($used_this_month >= $max_per_month) {
        return [
            'can_take' => false,
            'reason' => 'You have already taken sick leave this month.',
            'message' => 'Maximum 1 sick leave day per month'
        ];
    }
    
    // Get leave year info
    $leave_year = getCurrentLeaveYear();
    
    // Get used sick days in current year
    $used_yearly = getSickLeaveUsedYearly($conn, $user_id, $leave_year);
    
    // Yearly entitlement
    $yearly_entitlement = 6;
    
    // Get user join date for proration
    $join_date = null;
    $join_stmt = $conn->prepare("SELECT join_date FROM users WHERE id = ?");
    $join_stmt->bind_param("i", $user_id);
    $join_stmt->execute();
    $join_result = $join_stmt->get_result();
    if ($join_row = $join_result->fetch_assoc()) {
        $join_date = $join_row['join_date'];
    }
    $join_stmt->close();
    
    // Calculate prorated entitlement
    $prorated_entitlement = $yearly_entitlement;
    
    if ($join_date) {
        $join = new DateTime($join_date);
        $year_start = new DateTime($leave_year['start_date']);
        
        if ($join > $year_start) {
            $year_end = new DateTime($leave_year['end_date']);
            $days_in_year = $year_start->diff($year_end)->days + 1;
            $days_remaining = $join->diff($year_end)->days + 1;
            
            $proration_factor = $days_remaining / $days_in_year;
            $prorated_entitlement = round($yearly_entitlement * $proration_factor, 1);
        }
    }
    
    // Yearly remaining
    $yearly_remaining = max(0, $prorated_entitlement - $used_yearly);
    
    if ($yearly_remaining <= 0) {
        return [
            'can_take' => false,
            'reason' => 'You have used all your sick leave for the year.',
            'message' => 'No sick leave remaining for this year'
        ];
    }
    
    return [
        'can_take' => true,
        'max_days' => min($max_per_month - $used_this_month, $yearly_remaining),
        'message' => 'Sick leave available'
    ];
}

/**
 * Get user's leave balance for current leave year
 */
function getUserLeaveBalance($conn, $user_id, $leave_type = null) {
    $leave_year = getCurrentLeaveYear();
    $current_month_key = getCurrentMonthKey();
    
    $default_entitlement = [
        'Sick' => 6,
        'Casual' => 12,
        'LOP' => 0
    ];
    
    // Get user's join date for proration
    $join_date = null;
    $join_stmt = $conn->prepare("SELECT join_date FROM users WHERE id = ?");
    $join_stmt->bind_param("i", $user_id);
    $join_stmt->execute();
    $join_result = $join_stmt->get_result();
    if ($join_row = $join_result->fetch_assoc()) {
        $join_date = $join_row['join_date'];
    }
    $join_stmt->close();
    
    // Calculate prorated entitlement based on join date
    $prorated_entitlement = [
        'Sick' => $default_entitlement['Sick'],
        'Casual' => $default_entitlement['Casual'],
        'LOP' => $default_entitlement['LOP']
    ];
    
    if ($join_date) {
        $join = new DateTime($join_date);
        $year_start = new DateTime($leave_year['start_date']);
        
        if ($join > $year_start) {
            $year_end = new DateTime($leave_year['end_date']);
            $days_in_year = $year_start->diff($year_end)->days + 1;
            $days_remaining = $join->diff($year_end)->days + 1;
            
            $proration_factor = $days_remaining / $days_in_year;
            
            $prorated_entitlement['Sick'] = round($default_entitlement['Sick'] * $proration_factor, 1);
            $prorated_entitlement['Casual'] = round($default_entitlement['Casual'] * $proration_factor, 1);
        }
    }
    
    // Get used leaves in current leave year
    $stmt = $conn->prepare("
        SELECT 
            leave_type,
            COALESCE(SUM(days), 0) as used_days
        FROM leaves 
        WHERE user_id = ? 
        AND status IN ('Approved', 'Pending')
        AND from_date BETWEEN ? AND ?
        GROUP BY leave_type
    ");
    $stmt->bind_param("iss", $user_id, $leave_year['start_date'], $leave_year['end_date']);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $used = [
        'Sick' => 0,
        'Casual' => 0,
        'LOP' => 0
    ];
    
    while ($row = $result->fetch_assoc()) {
        if (isset($used[$row['leave_type']])) {
            $used[$row['leave_type']] = floatval($row['used_days']);
        }
    }
    $stmt->close();
    
    // Calculate Casual leave balance with strict monthly limit
    $casual_balance = calculateCasualLeaveBalance($conn, $user_id, $current_month_key);
    
    // Check if user can take sick leave this month
    $sick_availability = canTakeSickLeave($conn, $user_id);
    
    // LOP days taken
    $lop_used = $used['LOP'];
    $casual_used_this_month = getCurrentMonthCasualUsage($conn, $user_id);
    $casual_remaining_this_month = max(0, 1 - $casual_used_this_month);
    
    $total = [
        'Sick' => $prorated_entitlement['Sick'],
        'Casual' => $prorated_entitlement['Casual'],
        'LOP' => 0
    ];
    
    $remaining = [
        'Sick' => max(0, $total['Sick'] - $used['Sick']),
        'Casual' => max(0, $total['Casual'] - $used['Casual']),
        'LOP' => $lop_used
    ];
    
    return [
        'user_id' => $user_id,
        'leave_year' => $leave_year['year_label'],
        'start_date' => $leave_year['start_date'],
        'end_date' => $leave_year['end_date'],
        'entitlement' => $default_entitlement,
        'prorated_entitlement' => $prorated_entitlement,
        'total' => $total,
        'used' => $used,
        'remaining' => $remaining,
        'join_date' => $join_date,
        'proration_factor' => isset($proration_factor) ? round($proration_factor, 2) : 1,
        'leave_type_balance' => $leave_type ? ($remaining[$leave_type] ?? 0) : $remaining,
        'casual_monthly' => $casual_balance,
        'casual_this_month' => $casual_used_this_month,
        'casual_remaining_this_month' => $casual_remaining_this_month,
        'sick_availability' => $sick_availability,
        'sick_entitlement' => $prorated_entitlement['Sick'],
        'lop_taken' => $lop_used
    ];
}

/**
 * Apply leave with auto LOP conversion
 */
function applyLeaveWithAutoLOP($conn, $user_id, $leave_type, $from_date, $to_date, $days, $day_type, $reason) {
    $leave_year = getLeaveYearForDate($from_date);
    $year_label = $leave_year['year_label'];
    
    if ($leave_type === 'Casual') {
        $used_this_month = getCurrentMonthCasualUsage($conn, $user_id);
        $remaining_this_month = max(0, 1 - $used_this_month);
        
        $conn->begin_transaction();
        
        try {
            $result = [
                'success' => true,
                'casual_days' => 0,
                'lop_days' => 0,
                'message' => ''
            ];
            
            if ($remaining_this_month > 0) {
                $casual_days = min($remaining_this_month, $days);
                $casual_reason = $reason;
                
                $casual_stmt = $conn->prepare("
                    INSERT INTO leaves (user_id, leave_type, from_date, to_date, days, day_type, reason, leave_year)
                    VALUES (?, 'Casual', ?, ?, ?, ?, ?, ?)
                ");
                $casual_stmt->bind_param("issdsss", $user_id, $from_date, $to_date, $casual_days, $day_type, $casual_reason, $year_label);
                $casual_stmt->execute();
                $casual_stmt->close();
                
                $result['casual_days'] = $casual_days;
            }
            
            $lop_days = $days - ($remaining_this_month > 0 ? min($remaining_this_month, $days) : 0);
            if ($lop_days > 0) {
                $lop_reason = $reason . " (Excess leave - Loss of Pay)";
                $lop_stmt = $conn->prepare("
                    INSERT INTO leaves (user_id, leave_type, from_date, to_date, days, day_type, reason, leave_year)
                    VALUES (?, 'LOP', ?, ?, ?, ?, ?, ?)
                ");
                $lop_stmt->bind_param("issdsss", $user_id, $from_date, $to_date, $lop_days, $day_type, $lop_reason, $year_label);
                $lop_stmt->execute();
                $lop_stmt->close();
                $result['lop_days'] = $lop_days;
            }
            
            $conn->commit();
            
            if ($result['lop_days'] > 0) {
                $result['message'] = "Applied: {$result['casual_days']} casual + {$result['lop_days']} LOP";
            } else {
                $result['message'] = "Leave applied successfully";
            }
            
            return $result;
            
        } catch (Exception $e) {
            $conn->rollback();
            return [
                'success' => false,
                'message' => 'Error: ' . $e->getMessage()
            ];
        }
    } else {
        // For Sick leave
        $stmt = $conn->prepare("
            INSERT INTO leaves (user_id, leave_type, from_date, to_date, days, day_type, reason, leave_year)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->bind_param("issdsss", $user_id, $leave_type, $from_date, $to_date, $days, $day_type, $reason, $year_label);
        
        if ($stmt->execute()) {
            $stmt->close();
            return [
                'success' => true,
                'message' => 'Leave applied successfully'
            ];
        } else {
            $error = $stmt->error;
            $stmt->close();
            return [
                'success' => false,
                'message' => 'Error: ' . $error
            ];
        }
    }
}

/**
 * Get current leave month
 */
function getCurrentLeaveMonth() {
    $today = new DateTime();
    $year = (int)$today->format('Y');
    $month = (int)$today->format('m');
    $day = (int)$today->format('d');
    
    if ($day >= 16) {
        $start_year = $year;
        $start_month = $month;
        $end_year = $month == 12 ? $year + 1 : $year;
        $end_month = $month == 12 ? 1 : $month + 1;
    } else {
        $start_year = $month == 1 ? $year - 1 : $year;
        $start_month = $month == 1 ? 12 : $month - 1;
        $end_year = $year;
        $end_month = $month;
    }
    
    $start_date = sprintf("%04d-%02d-16", $start_year, $start_month);
    $end_date = sprintf("%04d-%02d-15", $end_year, $end_month);
    $month_label = sprintf("%02d/%04d - %02d/%04d", $start_month, $start_year, $end_month, $end_year);
    
    return [
        'start_date' => $start_date,
        'end_date' => $end_date,
        'month_label' => $month_label
    ];
}

/**
 * Get leave year statistics
 */
function getLeaveYearStatistics($conn) {
    $current = getCurrentLeaveYear();
    $previous = getPreviousLeaveYear();
    
    // Get total leaves in current year
    $current_stmt = $conn->prepare("
        SELECT 
            COUNT(*) as total_applications,
            COALESCE(SUM(days), 0) as total_days,
            COUNT(DISTINCT user_id) as total_employees
        FROM leaves 
        WHERE from_date BETWEEN ? AND ?
    ");
    $current_stmt->bind_param("ss", $current['start_date'], $current['end_date']);
    $current_stmt->execute();
    $current_result = $current_stmt->get_result();
    $current_stats = $current_result->fetch_assoc();
    $current_stmt->close();
    
    // Get total leaves in previous year
    $previous_stmt = $conn->prepare("
        SELECT 
            COUNT(*) as total_applications,
            COALESCE(SUM(days), 0) as total_days,
            COUNT(DISTINCT user_id) as total_employees
        FROM leaves 
        WHERE from_date BETWEEN ? AND ?
    ");
    $previous_stmt->bind_param("ss", $previous['start_date'], $previous['end_date']);
    $previous_stmt->execute();
    $previous_result = $previous_stmt->get_result();
    $previous_stats = $previous_result->fetch_assoc();
    $previous_stmt->close();
    
    // Calculate days until next reset
    $next_reset = new DateTime($current['end_date']);
    $next_reset->modify('+1 day');
    $today = new DateTime();
    $days_until_reset = $today->diff($next_reset)->days;
    
    return [
        'current_year' => $current['year_label'],
        'current_start' => $current['start_date'],
        'current_end' => $current['end_date'],
        'current_applications' => $current_stats['total_applications'] ?? 0,
        'current_days' => floatval($current_stats['total_days'] ?? 0),
        'current_employees' => $current_stats['total_employees'] ?? 0,
        'previous_year' => $previous['year_label'],
        'previous_applications' => $previous_stats['total_applications'] ?? 0,
        'previous_days' => floatval($previous_stats['total_days'] ?? 0),
        'previous_employees' => $previous_stats['total_employees'] ?? 0,
        'days_until_reset' => $days_until_reset,
        'reset_date' => $next_reset->format('Y-m-d'),
        'is_reset_period' => ($today->format('m-d') >= '03-01' && $today->format('m-d') <= '03-15')
    ];
}

/**
 * Initialize leave balances for new employee
 */
function initializeEmployeeLeaveBalance($conn, $user_id, $join_date) {
    $leave_year = getLeaveYearForDate($join_date);
    $default_entitlement = [
        'Sick' => 6,
        'Casual' => 12,
        'LOP' => 0
    ];
    
    // Pro-rate based on join date
    $join = new DateTime($join_date);
    $year_start = new DateTime($leave_year['start_date']);
    
    if ($join < $year_start) {
        $join = $year_start;
    }
    
    $year_end = new DateTime($leave_year['end_date']);
    $days_in_year = $year_start->diff($year_end)->days + 1;
    $days_remaining = $join->diff($year_end)->days + 1;
    
    $proration_factor = $days_remaining / $days_in_year;
    
    $sick_prorated = round($default_entitlement['Sick'] * $proration_factor, 1);
    $casual_prorated = round($default_entitlement['Casual'] * $proration_factor, 1);
    
    return [
        'sick' => $sick_prorated,
        'casual' => $casual_prorated,
        'lop' => 0,
        'leave_year' => $leave_year['year_label'],
        'proration_factor' => round($proration_factor, 2),
        'days_remaining' => $days_remaining,
        'days_in_year' => $days_in_year
    ];
}

/**
 * Reset leave balances for new year (March 16)
 */
function resetLeaveBalancesForNewYear($conn) {
    $leave_year = getCurrentLeaveYear();
    $prev_year = getPreviousLeaveYear();
    
    $results = [
        'success' => false,
        'message' => '',
        'users_updated' => 0,
        'carry_forward' => 0
    ];
    
    $conn->begin_transaction();
    
    try {
        // Create leave_balances_archive table if not exists
        $conn->query("
            CREATE TABLE IF NOT EXISTS leave_balances_archive (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_id INT NOT NULL,
                leave_year VARCHAR(9) NOT NULL,
                sick_leave_total DECIMAL(5,1) DEFAULT 0,
                sick_leave_used DECIMAL(5,1) DEFAULT 0,
                sick_leave_carried DECIMAL(5,1) DEFAULT 0,
                casual_leave_total DECIMAL(5,1) DEFAULT 0,
                casual_leave_used DECIMAL(5,1) DEFAULT 0,
                casual_leave_carried DECIMAL(5,1) DEFAULT 0,
                lop_leave_total DECIMAL(5,1) DEFAULT 0,
                lop_leave_used DECIMAL(5,1) DEFAULT 0,
                lop_leave_carried DECIMAL(5,1) DEFAULT 0,
                archived_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_user_year (user_id, leave_year)
            )
        ");
        
        // Get all active users
        $users = $conn->query("SELECT id, username, full_name, join_date FROM users WHERE status = 'active' OR status IS NULL");
        
        if (!$users) {
            throw new Exception("Failed to fetch users: " . $conn->error);
        }
        
        $carry_forward_limit = 6;
        $default_entitlement = [
            'Sick' => 6,
            'Casual' => 12,
            'LOP' => 0
        ];
        
        while ($user = $users->fetch_assoc()) {
            $user_id = $user['id'];
            
            // Get previous year's leave usage
            $prev_usage = $conn->prepare("
                SELECT 
                    leave_type,
                    COALESCE(SUM(days), 0) as total_used
                FROM leaves 
                WHERE user_id = ? 
                AND status = 'Approved'
                AND from_date BETWEEN ? AND ?
                GROUP BY leave_type
            ");
            $prev_usage->bind_param("iss", $user_id, $prev_year['start_date'], $prev_year['end_date']);
            $prev_usage->execute();
            $prev_result = $prev_usage->get_result();
            
            $sick_used = 0;
            $casual_used = 0;
            $lop_used = 0;
            
            while ($row = $prev_result->fetch_assoc()) {
                if ($row['leave_type'] == 'Sick') $sick_used = $row['total_used'];
                if ($row['leave_type'] == 'Casual') $casual_used = $row['total_used'];
                if ($row['leave_type'] == 'LOP') $lop_used = $row['total_used'];
            }
            $prev_usage->close();
            
            // Calculate carry forward (unused days, capped at limit)
            $sick_unused = max(0, $default_entitlement['Sick'] - $sick_used);
            $casual_unused = max(0, $default_entitlement['Casual'] - $casual_used);
            
            $sick_carry = min($sick_unused, $carry_forward_limit);
            $casual_carry = min($casual_unused, $carry_forward_limit);
            $lop_carry = 0;
            
            // Archive previous year's balance
            $archive = $conn->prepare("
                INSERT INTO leave_balances_archive 
                (user_id, leave_year, sick_leave_total, sick_leave_used, sick_leave_carried,
                 casual_leave_total, casual_leave_used, casual_leave_carried,
                 lop_leave_total, lop_leave_used, lop_leave_carried)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $archive->bind_param("isddddddddd", 
                $user_id, 
                $prev_year['year_label'],
                $default_entitlement['Sick'], $sick_used, $sick_carry,
                $default_entitlement['Casual'], $casual_used, $casual_carry,
                $default_entitlement['LOP'], $lop_used, $lop_carry
            );
            $archive->execute();
            $archive->close();
            
            $results['users_updated']++;
            $results['carry_forward'] += ($sick_carry + $casual_carry);
        }
        
        // Log the reset event
        $log = $conn->prepare("
            INSERT INTO system_logs (event_type, description, user_id, created_at)
            VALUES ('leave_year_reset', ?, ?, NOW())
        ");
        $description = "Leave year reset for {$leave_year['year_label']}. Carry forward limit: {$carry_forward_limit} days.";
        $admin_id = $_SESSION['user_id'] ?? 0;
        $log->bind_param("si", $description, $admin_id);
        $log->execute();
        $log->close();
        
        $conn->commit();
        $results['success'] = true;
        $results['message'] = "Leave balances reset successfully for {$results['users_updated']} users. Total carry forward: {$results['carry_forward']} days.";
        
    } catch (Exception $e) {
        $conn->rollback();
        $results['success'] = false;
        $results['message'] = "Error resetting leave balances: " . $e->getMessage();
    }
    
    return $results;
}

/**
 * Check and auto-reset leave year on login
 */
function checkAndAutoResetLeaveYear($conn) {
    $leave_year = getCurrentLeaveYear();
    $result = [
        'checked' => true,
        'reset_performed' => false,
        'message' => '',
        'leave_year' => $leave_year['year_label']
    ];
    
    $today = new DateTime();
    $reset_date = new DateTime($leave_year['end_date']);
    $reset_date->modify('+1 day');
    
    if ($today >= $reset_date) {
        $check_stmt = $conn->prepare("
            SELECT COUNT(*) as count 
            FROM system_logs 
            WHERE event_type = 'leave_year_reset' 
            AND DATE(created_at) = CURDATE()
        ");
        $check_stmt->execute();
        $check_result = $check_stmt->get_result();
        $check_row = $check_result->fetch_assoc();
        $check_stmt->close();
        
        if ($check_row['count'] == 0) {
            $reset_result = resetLeaveBalancesForNewYear($conn);
            $result['reset_performed'] = $reset_result['success'];
            $result['message'] = $reset_result['message'];
            $result['users_updated'] = $reset_result['users_updated'] ?? 0;
            $result['carry_forward'] = $reset_result['carry_forward'] ?? 0;
        } else {
            $result['message'] = "Leave year reset already performed today for {$leave_year['year_label']}";
        }
    }
    
    return $result;
}
?>