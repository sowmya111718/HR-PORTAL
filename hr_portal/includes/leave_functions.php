<?php
// File: includes/leave_functions.php
// ============================================
// LEAVE YEAR CALCULATION FUNCTIONS
//
// Sick Leave   : Calendar Year (Jan 1 – Dec 31)
// Casual Leave : Custom Cycle (Mar 16 – Mar 15 next year)
//   Monthly windows : 16th → 15th of next month
//   Accrual         : 1 day credited on the 16th of each month
//   New joiner      : gets window's day only if joined on or before
//                     the 15th of that calendar month
//   Carry forward   : unused days carry forward within the cycle (no cap)
//   Yearly reset    : Mar 16 — all unused carry-forward is LOST (fresh start)
// ============================================

// ============================================
// HOLIDAY FUNCTIONS
// ============================================

/**
 * List of fixed holidays in MM-DD format
 * Format: 'MM-DD' => 'Holiday Name'
 */
function getHolidayList() {
    return [
        '03-19' => 'UGADI',
        '08-15' => 'INDEPENDENCE DAY',
        '09-14' => 'VINAYAKA CHAVITHI',
        '10-02' => 'GANDHI JAYANTHI',
        '10-20' => 'VIJAYA DASHAMI'
    ];
}

/**
 * Check if a date is a holiday
 * @param string $date Date in YYYY-MM-DD format
 * @return bool|string Returns false if not holiday, holiday name if it is
 */
function isHoliday($date) {
    $holidays = getHolidayList();
    $month_day = date('m-d', strtotime($date));
    
    return isset($holidays[$month_day]) ? $holidays[$month_day] : false;
}

/**
 * Get holiday name for display
 * @param string $date Date in YYYY-MM-DD format
 * @return string Holiday name or empty string
 */
function getHolidayName($date) {
    $holiday = isHoliday($date);
    return $holiday !== false ? $holiday : '';
}

/**
 * Get all holiday dates for a given year
 * @param int $year Year to get holidays for
 * @return array Associative array of holiday dates => holiday names
 */
function getHolidaysForYear($year) {
    $holidays = getHolidayList();
    $year_holidays = [];
    
    foreach ($holidays as $md => $name) {
        $date = $year . '-' . $md;
        $year_holidays[$date] = $name;
    }
    
    return $year_holidays;
}

// Function to check if a date is a weekend (Sunday)
function isSunday($date) {
    $dayOfWeek = date('w', strtotime($date));
    return ($dayOfWeek == 0); // 0 = Sunday
}

// Function to check if a date is a Saturday
function isSaturday($date) {
    $dayOfWeek = date('w', strtotime($date));
    return ($dayOfWeek == 6); // 6 = Saturday
}

// Function to check if a date is a Monday
function isMonday($date) {
    $dayOfWeek = date('w', strtotime($date));
    return ($dayOfWeek == 1); // 1 = Monday
}

// Function to check if a date range includes Saturday and Monday with Sunday in between
function isSaturdayMondayWithSunday($from_date, $to_date) {
    $from = new DateTime($from_date);
    $to = new DateTime($to_date);
    $interval = $from->diff($to);
    
    // Check if it's exactly 2 days apart (Saturday to Monday)
    if ($interval->days == 2) {
        $day1 = $from->format('w'); // 6 = Saturday
        $day2 = $to->format('w');   // 1 = Monday
        
        // Check if from is Saturday and to is Monday
        if ($day1 == 6 && $day2 == 1) {
            return true;
        }
    }
    
    return false;
}

// Function to get date range details including Saturdays, Sundays, and Holidays
function getDateRangeDetails($from_date, $to_date) {
    $from = new DateTime($from_date);
    $to = new DateTime($to_date);
    $interval = $from->diff($to);
    
    $saturday_dates = [];
    $sunday_dates = [];
    $holiday_dates = [];
    $weekday_dates = [];
    $all_dates = [];
    
    for ($i = 0; $i <= $interval->days; $i++) {
        $current_date = date('Y-m-d', strtotime($from_date . " + $i days"));
        $all_dates[] = $current_date;
        
        $holiday_name = isHoliday($current_date);
        if ($holiday_name !== false) {
            $holiday_dates[$current_date] = $holiday_name;
        } elseif (isSunday($current_date)) {
            $sunday_dates[] = $current_date;
        } elseif (isSaturday($current_date)) {
            $saturday_dates[] = $current_date;
        } else {
            $weekday_dates[] = $current_date;
        }
    }
    
    return [
        'total_calendar_days' => $interval->days + 1,
        'saturday_days' => count($saturday_dates),
        'sunday_days' => count($sunday_dates),
        'holiday_days' => count($holiday_dates),
        'holiday_dates' => $holiday_dates,
        'weekday_days' => count($weekday_dates),
        'saturday_dates' => $saturday_dates,
        'sunday_dates' => $sunday_dates,
        'weekday_dates' => $weekday_dates,
        'all_dates' => $all_dates
    ];
}

// FIXED: Function to add LOP for missing timesheet (not approved leave)
function addLOPForMissingTimesheet($conn, $user_id, $date) {
    // Skip Sundays - no LOP for Sunday (employees can work on Sunday and get extra casual leave)
    if (date('N', strtotime($date)) == 7) { // 7 = Sunday
        return false;
    }
    
    // Skip Holidays - no LOP for holidays
    $holiday_name = isHoliday($date);
    if ($holiday_name !== false) {
        return false;
    }
    
    // Skip if user has approved leave on this date
    if (hasApprovedLeaveOnDate($conn, $user_id, $date)) {
        return false;
    }
    
    $leave_year = date('Y', strtotime($date)) . '-' . (date('Y', strtotime($date)) + 1);
    
    // Check if LOP already exists
    $check = $conn->prepare("SELECT id FROM leaves WHERE user_id = ? AND from_date = ? AND leave_type = 'LOP' AND reason LIKE 'Auto-generated LOP%'");
    $check->bind_param("is", $user_id, $date);
    $check->execute();
    $result = $check->get_result();
    
    if ($result->num_rows == 0) {
        // Set status to 'Pending' so it shows as LOP pending
        $insert = $conn->prepare("
            INSERT INTO leaves (user_id, leave_type, from_date, to_date, days, day_type, reason, status, applied_date, leave_year) 
            VALUES (?, 'LOP', ?, ?, 1, 'Full Day', 'Auto-generated LOP - Timesheet not submitted', 'Pending', NOW(), ?)
        ");
        $insert->bind_param("isss", $user_id, $date, $date, $leave_year);
        $insert->execute();
        $insert->close();
        return true;
    }
    $check->close();
    return false;
}

// Function to check if user has approved leave on a date
function hasApprovedLeaveOnDate($conn, $user_id, $date) {
    $check = $conn->prepare("
        SELECT id, leave_type, days 
        FROM leaves 
        WHERE user_id = ? 
        AND from_date <= ? 
        AND to_date >= ? 
        AND status = 'Approved'
        AND leave_type != 'LOP'
    ");
    $check->bind_param("iss", $user_id, $date, $date);
    $check->execute();
    $result = $check->get_result();
    $leave = $result->fetch_assoc();
    $check->close();
    return $leave;
}

// Function to remove LOP when timesheet is approved
function removeLOPForTimesheet($conn, $user_id, $date) {
    $delete = $conn->prepare("DELETE FROM leaves WHERE user_id = ? AND from_date = ? AND leave_type = 'LOP' AND reason LIKE 'Auto-generated LOP%'");
    $delete->bind_param("is", $user_id, $date);
    $delete->execute();
    $affected = $delete->affected_rows;
    $delete->close();
    return $affected > 0;
}

// FIXED: Function to add 1 casual leave when Sunday work is approved - NOW INCREASES ENTITLEMENT
function addCasualLeaveForSundayWork($conn, $user_id, $sunday_date, $approved_by) {
    // Get current leave year for the date using casual cycle
    $casual_year = getCasualLeaveYearForDate($sunday_date);
    $year_label = $casual_year['year_label']; // This will be like "2025-2026"
    
    // Log for debugging
    error_log("=== SUNDAY WORK LEAVE ADDITION ===");
    error_log("User ID: $user_id");
    error_log("Sunday Date: $sunday_date");
    error_log("Casual Year: $year_label");
    error_log("Approved By: $approved_by");
    
    // Check if a Sunday work bonus already exists for this date
    $check = $conn->prepare("
        SELECT id FROM leaves 
        WHERE user_id = ? AND from_date = ? 
        AND leave_type = 'Casual' 
        AND status = 'Approved'
        AND reason LIKE '%SUNDAY WORK BONUS%'
    ");
    $check->bind_param("is", $user_id, $sunday_date);
    $check->execute();
    $result = $check->get_result();
    $existing = $result->fetch_assoc();
    $check->close();
    
    if (!$existing) {
        // Add a special leave entry that will be treated as an entitlement increase
        $reason = "SUNDAY WORK BONUS - Extra casual leave granted for working on Sunday";
        
        // Get current date for applied_date and approved_date
        $current_date = date('Y-m-d H:i:s');
        
        // Insert the leave record with a special flag
        $insert = $conn->prepare("
            INSERT INTO leaves (
                user_id, leave_type, from_date, to_date, days, day_type, 
                reason, status, applied_date, approved_date, approved_by, leave_year
            ) VALUES (
                ?, 'Casual', ?, ?, 1, 'full', ?, 'Approved', ?, ?, ?, ?
            )
        ");
        
        $insert->bind_param("isssssis", 
            $user_id, 
            $sunday_date, 
            $sunday_date, 
            $reason, 
            $current_date,
            $current_date,
            $approved_by, 
            $year_label
        );
        
        if ($insert->execute()) {
            $insert_id = $conn->insert_id;
            $insert->close();
            
            error_log("SUCCESS: Sunday work bonus added. Leave ID: $insert_id");
            
            // Force refresh of casual balance by deleting all carry-forward for this user
            $delete_all_carry = $conn->prepare("DELETE FROM casual_leave_carryforward WHERE user_id = ?");
            $delete_all_carry->bind_param("i", $user_id);
            $delete_all_carry->execute();
            $delete_all_carry->close();
            
            error_log("Carry-forward records deleted for user $user_id");
            error_log("=== END SUNDAY WORK BONUS ADDITION (SUCCESS) ===");
            
            return true;
        } else {
            error_log("ERROR: Failed to insert Sunday work bonus: " . $insert->error);
            $insert->close();
            error_log("=== END SUNDAY WORK BONUS ADDITION (FAILED) ===");
            return false;
        }
    } else {
        error_log("Sunday work bonus already exists for user $user_id on date $sunday_date. Existing ID: " . $existing['id']);
        error_log("=== END SUNDAY WORK BONUS ADDITION (ALREADY EXISTS) ===");
        return false;
    }
}

/**
 * Get Sunday work bonus leaves (these INCREASE entitlement, not count as usage)
 */
function getSundayWorkBonusLeaves($conn, $user_id, $casual_year) {
    $stmt = $conn->prepare("
        SELECT COALESCE(SUM(days), 0) AS bonus_days FROM leaves
        WHERE user_id = ? AND leave_type = 'Casual' AND status = 'Approved'
          AND from_date BETWEEN ? AND ?
          AND reason LIKE '%SUNDAY WORK BONUS%'
    ");
    $stmt->bind_param("iss", $user_id, $casual_year['start_date'], $casual_year['end_date']);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return floatval($row['bonus_days']);
}

// ============================================================
//  CASUAL LEAVE CYCLE  (Mar 16 – Mar 15)
// ============================================================

/**
 * Return the casual-leave-cycle boundaries for any given date.
 *
 *   Jan 1 – Mar 15  →  cycle that STARTED Mar 16 of the PREVIOUS year
 *   Mar 16 – Dec 31 →  cycle that STARTS Mar 16 of THIS year
 *
 * Examples:
 *   2026-01-10  → start: 2025-03-16, end: 2026-03-15, label: "2025-2026"
 *   2026-03-15  → start: 2025-03-16, end: 2026-03-15, label: "2025-2026"
 *   2026-03-16  → start: 2026-03-16, end: 2027-03-15, label: "2026-2027"
 */
function getCasualLeaveYearForDate($date) {
    $d     = new DateTime($date);
    $year  = (int)$d->format('Y');
    $month = (int)$d->format('n');
    $day   = (int)$d->format('j');

    if ($month < 3 || ($month == 3 && $day < 16)) {
        $start_year = $year - 1;
    } else {
        $start_year = $year;
    }
    $end_year = $start_year + 1;

    return [
        'start_date'  => "{$start_year}-03-16",
        'end_date'    => "{$end_year}-03-15",
        'year_label'  => "{$start_year}-{$end_year}",
        'start_year'  => $start_year,
        'end_year'    => $end_year,
        'is_new_year' => ($month == 3 && $day == 16),
    ];
}

/** Current casual cycle. */
function getCurrentCasualLeaveYear() {
    return getCasualLeaveYearForDate(date('Y-m-d'));
}

/** Previous casual cycle. */
function getPreviousCasualLeaveYear() {
    $c = getCurrentCasualLeaveYear();
    $ps = $c['start_year'] - 1;
    $pe = $ps + 1;
    return [
        'start_date' => "{$ps}-03-16",
        'end_date'   => "{$pe}-03-15",
        'year_label' => "{$ps}-{$pe}",
        'start_year' => $ps,
        'end_year'   => $pe,
    ];
}

/**
 * Get next casual reset date (next March 16)
 */
function getNextCasualResetDate() {
    $today = new DateTime();
    $year_now = (int)$today->format('Y');
    $month_now = (int)$today->format('n');
    $day_now = (int)$today->format('j');
    
    if ($month_now < 3 || ($month_now == 3 && $day_now < 16)) {
        // Reset is coming this year
        return ($year_now) . '-03-16';
    } elseif ($month_now == 3 && $day_now == 16) {
        // Today is reset day
        return $year_now . '-03-16';
    } else {
        // Reset is next year
        return ($year_now + 1) . '-03-16';
    }
}

/**
 * Get days until next casual reset
 */
function getDaysUntilCasualReset() {
    $today = new DateTime();
    $reset_date = new DateTime(getNextCasualResetDate());
    
    // If today is reset day, return 0
    if ($today->format('Y-m-d') === $reset_date->format('Y-m-d')) {
        return 0;
    }
    
    $interval = $today->diff($reset_date);
    return $interval->days;
}

// ============================================================
//  MONTHLY WINDOWS  (16th → 15th)
// ============================================================

/**
 * Return every monthly window in a casual cycle as an ordered array.
 *
 * Each element:
 *   window_start : YYYY-MM-16  (accrual date; first window uses cycle start = Mar 16)
 *   window_end   : YYYY-MM-15  (of the next month)
 *   accrual_date : same as window_start
 *   month_key    : YYYY-MM of the window_start month (used as key in DB)
 *
 * Cycle Mar 16 YYYY → Mar 15 (YYYY+1) has 12 windows:
 *   window 1  : Mar 16 YYYY   – Apr 15 YYYY
 *   window 2  : Apr 16 YYYY   – May 15 YYYY
 *   …
 *   window 10 : Dec 16 YYYY   – Jan 15 (YYYY+1)
 *   window 11 : Jan 16 (YYYY+1) – Feb 15 (YYYY+1)
 *   window 12 : Feb 16 (YYYY+1) – Mar 15 (YYYY+1)
 */
function getCasualCycleWindows($casual_year) {
    $windows    = [];
    $start_year = $casual_year['start_year'];
    $end_year   = $casual_year['end_year'];

    // Months Mar(start_year) … Dec(start_year)
    for ($m = 3; $m <= 12; $m++) {
        $ws_year = $start_year;
        $ws_mon  = $m;

        // Window end = 15th of next calendar month
        $we_dt  = new DateTime(sprintf('%04d-%02d-16', $ws_year, $ws_mon));
        $we_dt->modify('+1 month')->modify('-1 day'); // 15th of next month

        $windows[] = [
            'window_start' => sprintf('%04d-%02d-16', $ws_year, $ws_mon),
            'window_end'   => $we_dt->format('Y-m-d'),
            'accrual_date' => sprintf('%04d-%02d-16', $ws_year, $ws_mon),
            'month_key'    => sprintf('%04d-%02d', $ws_year, $ws_mon),
        ];
    }

    // Months Jan(end_year), Feb(end_year)
    for ($m = 1; $m <= 2; $m++) {
        $ws_year = $end_year;
        $ws_mon  = $m;

        $we_dt = new DateTime(sprintf('%04d-%02d-16', $ws_year, $ws_mon));
        $we_dt->modify('+1 month')->modify('-1 day');

        $windows[] = [
            'window_start' => sprintf('%04d-%02d-16', $ws_year, $ws_mon),
            'window_end'   => $we_dt->format('Y-m-d'),
            'accrual_date' => sprintf('%04d-%02d-16', $ws_year, $ws_mon),
            'month_key'    => sprintf('%04d-%02d', $ws_year, $ws_mon),
        ];
    }

    return $windows; // 12 windows
}

/**
 * Get the monthly window that contains a given date.
 * A date D belongs to window starting on the 16th of the PREVIOUS month
 * if D <= 15, or the 16th of THIS month if D >= 16.
 */
function getWindowForDate($date) {
    $d     = new DateTime($date);
    $year  = (int)$d->format('Y');
    $month = (int)$d->format('n');
    $day   = (int)$d->format('j');

    if ($day >= 16) {
        $ws_year = $year;
        $ws_mon  = $month;
    } else {
        // belongs to window that started on 16th of previous month
        $prev = clone $d;
        $prev->modify('first day of last month');
        $ws_year = (int)$prev->format('Y');
        $ws_mon  = (int)$prev->format('n');
    }

    $we_dt = new DateTime(sprintf('%04d-%02d-16', $ws_year, $ws_mon));
    $we_dt->modify('+1 month')->modify('-1 day');

    return [
        'window_start' => sprintf('%04d-%02d-16', $ws_year, $ws_mon),
        'window_end'   => $we_dt->format('Y-m-d'),
        'accrual_date' => sprintf('%04d-%02d-16', $ws_year, $ws_mon),
        'month_key'    => sprintf('%04d-%02d', $ws_year, $ws_mon),
    ];
}

/** Current month key as YYYY-MM (unchanged helper). */
function getCurrentMonthKey() { return date('Y-m'); }

/** Previous month key as YYYY-MM. */
function getPreviousMonthKey() {
    $d = new DateTime();
    $d->modify('-1 month');
    return $d->format('Y-m');
}

/** Month key from any date. */
function getMonthKeyFromDate($date) {
    return (new DateTime($date))->format('Y-m');
}

/**
 * Get the current casual monthly window (based on today).
 * Returns same structure as getWindowForDate().
 */
function getCurrentCasualWindow() {
    return getWindowForDate(date('Y-m-d'));
}

/**
 * Days until next 16th (monthly window reset).
 */
function daysUntilNextMonthlyWindow() {
    $today = new DateTime();
    $day   = (int)$today->format('j');

    if ($day < 16) {
        // Next 16th is this month
        $next16 = new DateTime($today->format('Y-m') . '-16');
    } else {
        // Next 16th is next month
        $next16 = new DateTime($today->format('Y-m') . '-16');
        $next16->modify('+1 month');
    }

    $diff = $today->diff($next16);
    return $diff->days;
}

/**
 * Days until next Mar 16 (casual yearly cycle reset).
 *
 * - If today IS Mar 16 → 0 (reset day itself).
 * - If today is before Mar 16 this year → count to this year's Mar 16.
 * - If today is after Mar 16 this year → count to next year's Mar 16.
 */
function daysUntilCasualYearReset() {
    $today = new DateTime();
    $today->setTime(0, 0, 0); // strip time for clean day diff

    $year  = (int)$today->format('Y');
    $month = (int)$today->format('n');
    $day   = (int)$today->format('j');

    // Determine the upcoming Mar 16
    if ($month < 3 || ($month == 3 && $day < 16)) {
        // Mar 16 is still ahead this year
        $next_reset = new DateTime("{$year}-03-16");
    } elseif ($month == 3 && $day == 16) {
        // Today IS the reset day
        return 0;
    } else {
        // Mar 16 has passed this year; next one is next year
        $next_reset = new DateTime(($year + 1) . "-03-16");
    }

    return (int)$today->diff($next_reset)->days;
}


// ============================================================
//  CASUAL LEAVE ENTITLEMENT  (join-date based)
// ============================================================

/**
 * Calculate the total casual days an employee is entitled to
 * in a given casual cycle, based on their join date.
 * NOW INCLUDES Sunday work bonuses
 */
function calculateCasualEntitledDays($join_date, $casual_year, $sunday_bonus = 0) {
    $join      = new DateTime($join_date);
    $join_day  = (int)$join->format('j');
    $join_ym   = $join->format('Y-m');

    $cycle_start = new DateTime($casual_year['start_date']); // Mar 16
    $cycle_end   = new DateTime($casual_year['end_date']);   // Mar 15

    // Joined on/before cycle start → full 12
    if ($join <= $cycle_start) return 12 + $sunday_bonus;

    // Joined after cycle end → 0
    if ($join > $cycle_end) return 0 + $sunday_bonus;

    // Determine first qualifying window month_key
    if ($join_day <= 15) {
        $first_ym = $join_ym; // join month window
    } else {
        $next = clone $join;
        $next->modify('first day of next month');
        $first_ym = $next->format('Y-m');
    }

    $entitled = 0;
    foreach (getCasualCycleWindows($casual_year) as $w) {
        if ($w['month_key'] >= $first_ym) {
            $entitled++;
        }
    }

    return max(0, $entitled) + $sunday_bonus;
}

/**
 * Calculate how many casual days have ACCRUED so far in the cycle.
 *
 * Accrual rule: 1 day credited on the 16th of each qualifying window month.
 * We count windows whose accrual_date has passed (today >= accrual_date)
 * AND that the employee qualifies for (based on join date).
 */
function calculateCasualAccruedDays($join_date, $casual_year = null) {
    if ($casual_year === null) $casual_year = getCurrentCasualLeaveYear();

    $entitled = calculateCasualEntitledDays($join_date, $casual_year);
    if ($entitled == 0) return 0;

    $join     = new DateTime($join_date);
    $join_day = (int)$join->format('j');
    $join_ym  = $join->format('Y-m');
    $today    = new DateTime();

    $cycle_start = new DateTime($casual_year['start_date']);

    if ($join <= $cycle_start) {
        $first_ym = $casual_year['start_year'] . '-03';
    } elseif ($join_day <= 15) {
        $first_ym = $join_ym;
    } else {
        $next = clone $join;
        $next->modify('first day of next month');
        $first_ym = $next->format('Y-m');
    }

    $accrued = 0;
    foreach (getCasualCycleWindows($casual_year) as $w) {
        if ($w['month_key'] < $first_ym) continue;
        $accrual_dt = new DateTime($w['accrual_date']);
        if ($today >= $accrual_dt) {
            $accrued++;
        }
    }

    return min($entitled, $accrued);
}


// ============================================================
//  CASUAL LEAVE BALANCE  (with carry-forward within cycle)
// ============================================================

/**
 * Ensure carry-forward table exists.
 */
function ensureCasualCarryForwardTable($conn) {
    $conn->query("
        CREATE TABLE IF NOT EXISTS casual_leave_carryforward (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            month_key VARCHAR(7) NOT NULL,
            carry_forward_days DECIMAL(5,1) DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY unique_user_month (user_id, month_key),
            INDEX idx_user_month (user_id, month_key)
        )
    ");
}

/**
 * Get stored carry-forward for user/month. Returns -1 if no record.
 */
function getCasualLeaveCarryForward($conn, $user_id, $month_key) {
    ensureCasualCarryForwardTable($conn);
    $stmt = $conn->prepare("SELECT carry_forward_days FROM casual_leave_carryforward WHERE user_id = ? AND month_key = ?");
    $stmt->bind_param("is", $user_id, $month_key);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($row = $result->fetch_assoc()) {
        $val = floatval($row['carry_forward_days']);
        $stmt->close();
        return $val;
    }
    $stmt->close();
    return -1;
}

/**
 * Save carry-forward snapshot for user/month.
 */
function saveCasualLeaveCarryForward($conn, $user_id, $month_key, $carry_days) {
    ensureCasualCarryForwardTable($conn);
    $carry_days = max(0, (float)$carry_days);
    $stmt = $conn->prepare("
        INSERT INTO casual_leave_carryforward (user_id, month_key, carry_forward_days)
        VALUES (?, ?, ?)
        ON DUPLICATE KEY UPDATE carry_forward_days = VALUES(carry_forward_days), updated_at = CURRENT_TIMESTAMP
    ");
    $stmt->bind_param("isd", $user_id, $month_key, $carry_days);
    $stmt->execute();
    $stmt->close();
}

/**
 * Total casual days USED in the current cycle (approved + pending).
 * EXCLUDES Sunday work bonus leaves
 */
function getCasualLeaveUsedInCycle($conn, $user_id, $casual_year) {
    $stmt = $conn->prepare("
        SELECT COALESCE(SUM(days), 0) AS used_days FROM leaves
        WHERE user_id = ? AND leave_type = 'Casual' AND status IN ('Approved','Pending')
          AND from_date BETWEEN ? AND ?
          AND (reason NOT LIKE '%SUNDAY WORK BONUS%' OR reason IS NULL)
    ");
    $stmt->bind_param("iss", $user_id, $casual_year['start_date'], $casual_year['end_date']);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return floatval($row['used_days']);
}

/**
 * Casual days used within the current monthly window (16th–15th).
 * EXCLUDES Sunday work bonus leaves
 */
function getCasualLeaveUsedThisWindow($conn, $user_id, $month_key = null) {
    // Use current window boundaries (16th-15th), not calendar month
    $window = getCurrentCasualWindow();
    $stmt = $conn->prepare("
        SELECT COALESCE(SUM(days), 0) AS used_days FROM leaves
        WHERE user_id = ? AND leave_type = 'Casual' AND status IN ('Approved','Pending')
          AND from_date BETWEEN ? AND ?
          AND (reason NOT LIKE '%SUNDAY WORK BONUS%' OR reason IS NULL)
    ");
    $stmt->bind_param("iss", $user_id, $window['window_start'], $window['window_end']);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return floatval($row['used_days']);
}

/**
 * Compute and refresh the casual leave balance for the current window.
 *
 * Formula (with carry-forward within cycle):
 *   entitled_with_bonus = base_entitled + sunday_bonus
 *   accrued_to_date = windows unlocked so far (based on join date)
 *   used_in_cycle   = total casual days used in this cycle (excluding Sunday bonus)
 *   remaining       = max(0, (accrued_to_date + sunday_bonus) - used_in_cycle)
 *
 * @return float remaining available days
 */
function initializeMonthlyCarryForward($conn, $user_id) {
    $current_month_key = getCurrentMonthKey();

    $join_date = null;
    $js = $conn->prepare("SELECT join_date FROM users WHERE id = ?");
    $js->bind_param("i", $user_id);
    $js->execute();
    if ($row = $js->get_result()->fetch_assoc()) $join_date = $row['join_date'];
    $js->close();

    $casual_year = getCurrentCasualLeaveYear();
    $sunday_bonus = getSundayWorkBonusLeaves($conn, $user_id, $casual_year);

    $accrued   = $join_date
        ? calculateCasualAccruedDays($join_date, $casual_year)
        : calculateCasualAccruedDays($casual_year['start_date'], $casual_year);

    $used      = getCasualLeaveUsedInCycle($conn, $user_id, $casual_year);
    $remaining = max(0, ($accrued + $sunday_bonus) - $used);

    saveCasualLeaveCarryForward($conn, $user_id, $current_month_key, $remaining);
    return $remaining;
}

/**
 * Full casual leave balance for display.
 */
function calculateCasualLeaveBalance($conn, $user_id, $month_key = null) {
    if (!$month_key) $month_key = getCurrentMonthKey();

    $join_date = null;
    $js = $conn->prepare("SELECT join_date FROM users WHERE id = ?");
    $js->bind_param("i", $user_id);
    $js->execute();
    if ($row = $js->get_result()->fetch_assoc()) $join_date = $row['join_date'];
    $js->close();

    $casual_year    = getCurrentCasualLeaveYear();
    $sunday_bonus   = getSundayWorkBonusLeaves($conn, $user_id, $casual_year);
    
    $base_entitled = $join_date ? calculateCasualEntitledDays($join_date, $casual_year, 0) : 12;
    $total_entitled = $base_entitled + $sunday_bonus;
    
    $accrued        = $join_date
        ? calculateCasualAccruedDays($join_date, $casual_year)
        : calculateCasualAccruedDays($casual_year['start_date'], $casual_year);

    $used_cycle      = getCasualLeaveUsedInCycle($conn, $user_id, $casual_year);
    $used_this_window = getCasualLeaveUsedThisWindow($conn, $user_id, $month_key);
    $remaining       = max(0, ($accrued + $sunday_bonus) - $used_cycle);

    // Save snapshot
    saveCasualLeaveCarryForward($conn, $user_id, $month_key, $remaining);

    $current_window = getCurrentCasualWindow();

    return [
        'month_key'             => $month_key,
        'casual_year'           => $casual_year['year_label'],
        'casual_year_start'     => $casual_year['start_date'],
        'casual_year_end'       => $casual_year['end_date'],
        'current_window_start'  => $current_window['window_start'],
        'current_window_end'    => $current_window['window_end'],
        'base_entitled'         => $base_entitled,
        'sunday_bonus'          => $sunday_bonus,
        'total_entitled'        => $total_entitled,
        'accrued_to_date'       => $accrued,
        'used_cycle'            => $used_cycle,
        'used_this_window'      => $used_this_window,
        'remaining'             => $remaining,
        'can_take_this_month'   => $remaining > 0,
        // Dashboard countdown helpers
        'days_until_monthly_reset' => daysUntilNextMonthlyWindow(),
        'days_until_yearly_reset'  => daysUntilCasualYearReset(),
    ];
}


// ============================================================
//  AUTO-RESET CASUAL LEAVE ON MARCH 16  (unused days LOST)
// ============================================================

/**
 * Archive the ending cycle and wipe all carry-forward rows.
 * Unused carry-forward is LOST — cycle starts fresh.
 * Guards itself via system_logs; runs only once per cycle.
 */
function checkAndAutoResetCasualLeaveYear($conn) {
    $today       = new DateTime();
    $casual_year = getCurrentCasualLeaveYear();

    $result = [
        'checked'         => true,
        'reset_performed' => false,
        'message'         => '',
        'leave_year'      => $casual_year['year_label'],
    ];

    // Only relevant on/after Mar 16
    if ($today->format('m-d') < '03-16') return $result;

    // Already done for this cycle?
    $tbl = $conn->query("SHOW TABLES LIKE 'system_logs'");
    if ($tbl && $tbl->num_rows > 0) {
        $chk  = $conn->prepare("SELECT COUNT(*) AS cnt FROM system_logs WHERE event_type = 'casual_leave_year_reset' AND description LIKE ?");
        $like = '%' . $casual_year['year_label'] . '%';
        $chk->bind_param("s", $like);
        $chk->execute();
        $row = $chk->get_result()->fetch_assoc();
        $chk->close();
        if ($row['cnt'] > 0) {
            $result['message'] = "Casual leave reset already done for {$casual_year['year_label']}";
            return $result;
        }
    }

    $conn->begin_transaction();
    try {
        $conn->query("
            CREATE TABLE IF NOT EXISTS leave_balances_archive (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_id INT NOT NULL,
                leave_year VARCHAR(12) NOT NULL,
                leave_year_type VARCHAR(20) DEFAULT 'casual',
                sick_leave_total DECIMAL(5,1) DEFAULT 0, sick_leave_used DECIMAL(5,1) DEFAULT 0, sick_leave_carried DECIMAL(5,1) DEFAULT 0,
                casual_leave_total DECIMAL(5,1) DEFAULT 0, casual_leave_used DECIMAL(5,1) DEFAULT 0, casual_leave_carried DECIMAL(5,1) DEFAULT 0,
                lop_leave_total DECIMAL(5,1) DEFAULT 0, lop_leave_used DECIMAL(5,1) DEFAULT 0, lop_leave_carried DECIMAL(5,1) DEFAULT 0,
                archived_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_user_year (user_id, leave_year)
            )
        ");

        $prev_cycle    = getPreviousCasualLeaveYear();
        $users_updated = 0;

        $users = $conn->query("SELECT id, join_date FROM users WHERE status = 'active' OR status IS NULL");
        if (!$users) throw new Exception("Cannot fetch users: " . $conn->error);

        while ($user = $users->fetch_assoc()) {
            $uid       = $user['id'];
            $join_date = $user['join_date'];

            $prev_entitled = $join_date ? calculateCasualEntitledDays($join_date, $prev_cycle) : 12;

            $pu = $conn->prepare("SELECT COALESCE(SUM(days),0) AS tu FROM leaves WHERE user_id=? AND leave_type='Casual' AND status='Approved' AND from_date BETWEEN ? AND ?");
            $pu->bind_param("iss", $uid, $prev_cycle['start_date'], $prev_cycle['end_date']);
            $pu->execute();
            $casual_used = floatval($pu->get_result()->fetch_assoc()['tu']);
            $pu->close();

            // Archive — carry_forward = 0 (unused LOST on yearly reset)
            $arch = $conn->prepare("
                INSERT INTO leave_balances_archive (user_id, leave_year, leave_year_type, casual_leave_total, casual_leave_used, casual_leave_carried)
                VALUES (?, ?, 'casual', ?, ?, 0)
                ON DUPLICATE KEY UPDATE casual_leave_total=VALUES(casual_leave_total), casual_leave_used=VALUES(casual_leave_used), casual_leave_carried=0, archived_date=CURRENT_TIMESTAMP
            ");
            $arch->bind_param("isdd", $uid, $prev_cycle['year_label'], $prev_entitled, $casual_used);
            $arch->execute();
            $arch->close();

            // Wipe ALL carry-forward rows for clean start
            $del = $conn->prepare("DELETE FROM casual_leave_carryforward WHERE user_id = ?");
            $del->bind_param("i", $uid);
            $del->execute();
            $del->close();

            $users_updated++;
        }

        $tbl2 = $conn->query("SHOW TABLES LIKE 'system_logs'");
        if ($tbl2 && $tbl2->num_rows > 0) {
            $log = $conn->prepare("INSERT INTO system_logs (event_type, description, user_id, created_at) VALUES ('casual_leave_year_reset', ?, ?, NOW())");
            $desc     = "Casual leave cycle reset for {$casual_year['year_label']} (Mar 16-Mar 15). Unused days forfeited. Users: {$users_updated}.";
            $admin_id = $_SESSION['user_id'] ?? 0;
            $log->bind_param("si", $desc, $admin_id);
            $log->execute();
            $log->close();
        }

        $conn->commit();
        $result['reset_performed'] = true;
        $result['users_updated']   = $users_updated;
        $result['message']         = "Casual leave cycle reset for {$casual_year['year_label']}. {$users_updated} users updated. Unused days forfeited.";

    } catch (Exception $e) {
        $conn->rollback();
        $result['message'] = "Error during casual leave reset: " . $e->getMessage();
    }

    return $result;
}


// ============================================================
//  SICK LEAVE YEAR  (Jan 1 – Dec 31)
// ============================================================

function getCurrentLeaveYear() {
    $today = new DateTime();
    $y     = (int)$today->format('Y');
    return [
        'start_date'  => "{$y}-01-01",
        'end_date'    => "{$y}-12-31",
        'year_label'  => "{$y}",
        'start_year'  => $y,
        'end_year'    => $y,
        'is_new_year' => ($today->format('m-d') == '01-01'),
    ];
}

function getPreviousLeaveYear() {
    $c = getCurrentLeaveYear();
    $p = $c['start_year'] - 1;
    return [
        'start_date' => "{$p}-01-01", 
        'end_date'   => "{$p}-12-31", 
        'year_label' => "{$p}", 
        'start_year' => $p, 
        'end_year'   => $p
    ];
}

function getLeaveYearForDate($date) {
    $y = (int)(new DateTime($date))->format('Y');
    return [
        'start_date' => "{$y}-01-01", 
        'end_date'   => "{$y}-12-31", 
        'year_label' => "{$y}", 
        'start_year' => $y, 
        'end_year'   => $y
    ];
}

function isInCurrentLeaveYear($date) {
    $ly = getCurrentLeaveYear();
    return ($date >= $ly['start_date'] && $date <= $ly['end_date']);
}

function hasSickLeaveInPreviousMonth($conn, $user_id) {
    $pmk = getPreviousMonthKey();
    $pms = $pmk . '-01';
    $pme = date('Y-m-t', strtotime($pms));
    $stmt = $conn->prepare("SELECT COUNT(*) AS count FROM leaves WHERE user_id=? AND leave_type='Sick' AND status='Approved' AND from_date BETWEEN ? AND ?");
    $stmt->bind_param("iss", $user_id, $pms, $pme);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row['count'] > 0;
}

function getSickLeaveUsedYearly($conn, $user_id, $leave_year) {
    $stmt = $conn->prepare("SELECT COALESCE(SUM(days),0) AS used_days FROM leaves WHERE user_id=? AND leave_type='Sick' AND status IN ('Approved','Pending') AND from_date BETWEEN ? AND ?");
    $stmt->bind_param("iss", $user_id, $leave_year['start_date'], $leave_year['end_date']);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return floatval($row['used_days']);
}

function getSickLeaveUsedThisMonth($conn, $user_id) {
    $mk = getCurrentMonthKey();
    $ms = $mk . '-01';
    $me = date('Y-m-t', strtotime($ms));
    $stmt = $conn->prepare("SELECT COALESCE(SUM(days),0) AS used_days FROM leaves WHERE user_id=? AND leave_type='Sick' AND status IN ('Approved','Pending') AND from_date BETWEEN ? AND ?");
    $stmt->bind_param("iss", $user_id, $ms, $me);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return floatval($row['used_days']);
}

function getCurrentMonthCasualUsage($conn, $user_id) {
    // Uses current 16th-15th window
    return getCasualLeaveUsedThisWindow($conn, $user_id);
}

function getLOPCount($conn, $user_id) {
    $ly = getCurrentLeaveYear();
    $stmt = $conn->prepare("SELECT COALESCE(SUM(days),0) AS total_lop FROM leaves WHERE user_id=? AND leave_type='LOP' AND status IN ('Approved','Pending') AND from_date BETWEEN ? AND ?");
    $stmt->bind_param("iss", $user_id, $ly['start_date'], $ly['end_date']);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return floatval($row['total_lop']);
}

function getCurrentMonthLOPUsage($conn, $user_id) {
    $mk = getCurrentMonthKey();
    $ms = $mk . '-01';
    $me = date('Y-m-t', strtotime($ms));
    $stmt = $conn->prepare("SELECT COALESCE(SUM(days),0) AS lop_days FROM leaves WHERE user_id=? AND leave_type='LOP' AND status IN ('Approved','Pending') AND from_date BETWEEN ? AND ?");
    $stmt->bind_param("iss", $user_id, $ms, $me);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return floatval($row['lop_days']);
}

function canTakeSickLeave($conn, $user_id) {
    $used_this_month = getSickLeaveUsedThisMonth($conn, $user_id);
    if ($used_this_month >= 1) {
        return ['can_take' => false, 'reason' => 'You have already taken sick leave this month.', 'message' => 'Maximum 1 sick leave day per month'];
    }

    $leave_year           = getCurrentLeaveYear();
    $used_yearly          = getSickLeaveUsedYearly($conn, $user_id, $leave_year);
    $prorated_entitlement = 6;

    $join_date = null;
    $js = $conn->prepare("SELECT join_date FROM users WHERE id = ?");
    $js->bind_param("i", $user_id);
    $js->execute();
    if ($row = $js->get_result()->fetch_assoc()) $join_date = $row['join_date'];
    $js->close();

    if ($join_date) {
        $join       = new DateTime($join_date);
        $year_start = new DateTime($leave_year['start_date']);
        if ($join > $year_start) {
            $year_end             = new DateTime($leave_year['end_date']);
            $proration_factor     = ($join->diff($year_end)->days + 1) / ($year_start->diff($year_end)->days + 1);
            $prorated_entitlement = round(6 * $proration_factor, 1);
        }
    }

    $yearly_remaining = max(0, $prorated_entitlement - $used_yearly);
    if ($yearly_remaining <= 0) {
        return ['can_take' => false, 'reason' => 'You have used all your sick leave for the year.', 'message' => 'No sick leave remaining for this year'];
    }
    return ['can_take' => true, 'max_days' => min(1 - $used_this_month, $yearly_remaining), 'message' => 'Sick leave available'];
}


// ============================================================
//  MAIN BALANCE FUNCTION — FIXED: Sunday work adds to available balance
// ============================================================

function getUserLeaveBalance($conn, $user_id, $leave_type = null) {
    $leave_year        = getCurrentLeaveYear();        // Calendar year for Sick leave
    $casual_year       = getCurrentCasualLeaveYear();  // Cycle year for Casual leave (Mar 16 - Mar 15)
    $current_month_key = getCurrentMonthKey();

    $default_entitlement = ['Sick' => 6, 'Casual' => 12, 'LOP' => 0];

    $join_date = null;
    $js = $conn->prepare("SELECT join_date FROM users WHERE id = ?");
    $js->bind_param("i", $user_id);
    $js->execute();
    if ($row = $js->get_result()->fetch_assoc()) $join_date = $row['join_date'];
    $js->close();

    $prorated_entitlement = ['Sick' => 6, 'Casual' => 12, 'LOP' => 0];
    $proration_factor     = 1;

    // Get Sunday work bonus leaves
    $sunday_bonus = getSundayWorkBonusLeaves($conn, $user_id, $casual_year);

    if ($join_date) {
        $join       = new DateTime($join_date);
        $year_start = new DateTime($leave_year['start_date']);

        // Sick (calendar year)
        if ($join > $year_start) {
            $year_end             = new DateTime($leave_year['end_date']);
            $proration_factor     = ($join->diff($year_end)->days + 1) / ($year_start->diff($year_end)->days + 1);
            $quarter              = ceil((int)$join->format('n') / 3);
            $quarters_remaining   = 4 - $quarter + 1;
            $prorated_entitlement['Sick'] = round(1.5 * $quarters_remaining, 1);
        }

        // Casual (Mar 16–Mar 15 cycle) - base entitlement without bonus
        $prorated_entitlement['Casual'] = calculateCasualEntitledDays($join_date, $casual_year, 0);
    }

    // Used leaves (sick/LOP on calendar year)
    $stmt = $conn->prepare("SELECT leave_type, COALESCE(SUM(days),0) AS used_days FROM leaves WHERE user_id=? AND status IN ('Approved','Pending') AND from_date BETWEEN ? AND ? GROUP BY leave_type");
    $stmt->bind_param("iss", $user_id, $leave_year['start_date'], $leave_year['end_date']);
    $stmt->execute();
    $res  = $stmt->get_result();
    $used = ['Sick' => 0, 'Casual' => 0, 'LOP' => 0];
    while ($row = $res->fetch_assoc()) {
        if (isset($used[$row['leave_type']])) $used[$row['leave_type']] = floatval($row['used_days']);
    }
    $stmt->close();

    // Casual used: get from cycle-based figure (EXCLUDING Sunday bonus leaves)
    $casual_used_in_cycle = getCasualLeaveUsedInCycle($conn, $user_id, $casual_year);
    
    // Override the casual used from calendar year with the cycle-based figure
    $used['Casual'] = $casual_used_in_cycle;

    $casual_balance    = calculateCasualLeaveBalance($conn, $user_id, $current_month_key);
    $sick_availability = canTakeSickLeave($conn, $user_id);

    $total = [
        'Sick'   => $prorated_entitlement['Sick'],
        'Casual' => $prorated_entitlement['Casual'] + $sunday_bonus, // Add bonus to total entitlement
        'LOP'    => 0,
    ];
    
    $remaining = [
        'Sick'      => max(0, $total['Sick'] - $used['Sick']),
        'Casual'    => $casual_balance['remaining'], // This already includes bonus in calculation
        'LOP'       => $used['LOP'],
    ];

    return [
        'user_id'                      => $user_id,
        // Sick / calendar year
        'leave_year'                   => $leave_year['year_label'],
        'start_date'                   => $leave_year['start_date'],
        'end_date'                     => $leave_year['end_date'],
        // Casual cycle
        'casual_leave_year'            => $casual_year['year_label'],
        'casual_year_start'            => $casual_year['start_date'],
        'casual_year_end'              => $casual_year['end_date'],
        // Current window (16th–15th)
        'current_window_start'         => $casual_balance['current_window_start'],
        'current_window_end'           => $casual_balance['current_window_end'],
        // Balance data
        'entitlement'                  => $default_entitlement,
        'prorated_entitlement'         => $prorated_entitlement,
        'sunday_bonus'                 => $sunday_bonus,
        'total'                        => $total,
        'used'                         => $used,
        'remaining'                    => $remaining,
        'join_date'                    => $join_date,
        'proration_factor'             => round($proration_factor, 2),
        'leave_type_balance'           => $leave_type ? ($remaining[$leave_type] ?? 0) : $remaining,
        'casual_balance'               => $casual_balance,
        'casual_this_month'            => $casual_balance['used_this_window'],
        'casual_remaining_this_month'  => $casual_balance['remaining'],
        'casual_total_entitled'        => $casual_balance['total_entitled'],
        'casual_base_entitled'         => $casual_balance['base_entitled'] ?? $prorated_entitlement['Casual'],
        'casual_accrued_to_date'       => $casual_balance['accrued_to_date'],
        'sick_availability'            => $sick_availability,
        'sick_entitlement'             => $prorated_entitlement['Sick'],
        'lop_taken'                    => $used['LOP'],
        // Dashboard countdown fields
        'days_until_monthly_reset'     => $casual_balance['days_until_monthly_reset'],
        'days_until_yearly_reset'      => $casual_balance['days_until_yearly_reset'],
    ];
}


// ============================================================
//  BALANCE CHECK  — RESTORED ORIGINAL LOGIC WITH SATURDAY-MONDAY SPECIAL CASE
// ============================================================

function checkLeaveBalance($conn, $user_id, $leave_type, $days, $from_date = null, $to_date = null) {
    $balance = getUserLeaveBalance($conn, $user_id, $leave_type);
    
    // Check for special Saturday-Monday case
    $is_special_case = false;
    if ($from_date && $to_date) {
        $is_special_case = isSaturdayMondayWithSunday($from_date, $to_date);
    }

    if ($leave_type === 'LOP') {
        return ['has_balance' => true, 'available' => 999,
                'message' => "Loss of Pay (Unpaid Leave) - $days days requested",
                'warning' => 'This is unpaid leave and will affect your salary'];
    }

    if ($leave_type === 'Casual') {
        $cb        = $balance['casual_balance'];
        $available = $cb['remaining'];
        $entitled  = $cb['total_entitled'];
        
        // Special case: Saturday to Monday - all days LOP
        if ($is_special_case) {
            return ['has_balance' => true, 'available' => $available,
                    'casual_days' => 0, 'lop_days' => $days,
                    'message' => "Saturday to Monday leave application: All {$days} day(s) will be processed as Loss of Pay (LOP).",
                    'suggest_lop' => true, 'auto_convert' => true,
                    'is_special_case' => true];
        }

        // Normal balance check
        if ($days <= $available) {
            return ['has_balance' => true, 'available' => $available,
                    'message' => "Sufficient balance. You have {$available} casual leave day(s) available (total entitled: {$entitled})",
                    'monthly_limit' => true, 'month_key' => $cb['month_key']];
        } else {
            $lop_days = $days - $available;
            return ['has_balance' => false, 'available' => $available,
                    'casual_days' => $available, 'lop_days' => $lop_days,
                    'message' => "You have {$available} casual leave day(s) available. {$lop_days} day(s) will be processed as Loss of Pay (LOP).",
                    'suggest_lop' => true, 'auto_convert' => true];
        }
    }

    if ($leave_type === 'Sick') {
        $available = $balance['remaining']['Sick'];
        
        // Special case: Saturday to Monday - all days LOP
        if ($is_special_case) {
            return [
                'has_balance' => true,
                'available'   => $available,
                'sick_days'   => 0,
                'lop_days'    => $days,
                'auto_convert'=> true,
                'message'     => "Saturday to Monday leave application: All {$days} day(s) will be recorded as Loss of Pay (LOP).",
                'warning'     => 'Special case: Saturday-Monday leave converted to LOP',
                'is_special_case' => true
            ];
        }

        // No sick balance left at all — entire request becomes LOP
        if ($available <= 0) {
            return [
                'has_balance' => true,
                'available'   => 0,
                'sick_days'   => 0,
                'lop_days'    => $days,
                'auto_convert'=> true,
                'message'     => "You have no sick leave remaining. All {$days} day(s) will be recorded as Loss of Pay (LOP).",
                'warning'     => 'No sick leave balance — converted to LOP automatically',
            ];
        }

        // Partial balance — sick up to limit, rest as LOP
        if ($days > $available) {
            $sick_days = $available;
            $lop_days  = $days - $available;
            return [
                'has_balance' => true,
                'available'   => $available,
                'sick_days'   => $sick_days,
                'lop_days'    => $lop_days,
                'auto_convert'=> true,
                'message'     => "You have {$available} sick leave day(s) remaining. {$sick_days} day(s) will be Sick leave and {$lop_days} day(s) will be Loss of Pay (LOP).",
                'warning'     => 'Partial sick balance — excess days converted to LOP automatically',
            ];
        }

        // Full balance available
        return [
            'has_balance' => true,
            'available'   => $available,
            'sick_days'   => $days,
            'lop_days'    => 0,
            'message'     => "Sufficient balance. You have {$available} sick leave day(s) available.",
        ];
    }

    $available = $balance['leave_type_balance'];
    return $days <= $available
        ? ['has_balance' => true,  'available' => $available, 'message' => "Sufficient balance. Available: {$available} days"]
        : ['has_balance' => false, 'available' => $available, 'message' => "Insufficient balance. Requested: {$days} days, Available: {$available} days"];
}


// ============================================================
//  APPLY LEAVE  — RESTORED ORIGINAL LOGIC WITH SATURDAY-MONDAY SPECIAL CASE
// ============================================================

function applyLeaveWithAutoLOP($conn, $user_id, $leave_type, $from_date, $to_date, $days, $day_type, $reason) {
    $leave_year        = getLeaveYearForDate($from_date);
    $year_label        = $leave_year['year_label'];
    $current_month_key = getMonthKeyFromDate($from_date);
    
    // Check for special Saturday-Monday case
    $is_special_case = isSaturdayMondayWithSunday($from_date, $to_date);
    
    // Special case: Saturday to Monday - all days as LOP
    if ($is_special_case) {
        $conn->begin_transaction();
        try {
            $date_details = getDateRangeDetails($from_date, $to_date);
            $saturday_count = $date_details['saturday_days'];
            $sunday_count = $date_details['sunday_days'];
            
            $lop_reason = $reason . " (Saturday to Monday - All days LOP)";
            
            $ls = $conn->prepare("INSERT INTO leaves (user_id, leave_type, from_date, to_date, days, day_type, reason, leave_year) VALUES (?, 'LOP', ?, ?, ?, ?, ?, ?)");
            $ls->bind_param("issdsss", $user_id, $from_date, $to_date, $days, $day_type, $lop_reason, $year_label);
            $ls->execute(); 
            $ls->close();

            $conn->commit();
            
            $result = ['success' => true, 'regular_days' => 0, 'lop_days' => $days, 
                       'saturday_days' => $saturday_count, 'sunday_days' => $sunday_count, 
                       'message' => "Applied: {$days} LOP days (Saturday to Monday special case)"];
            return $result;

        } catch (Exception $e) {
            $conn->rollback();
            return ['success' => false, 'message' => 'Error: ' . $e->getMessage()];
        }
    }

    // Normal leave application logic
    if ($leave_type === 'Casual') {
        $cb               = calculateCasualLeaveBalance($conn, $user_id, $current_month_key);
        $available_casual = $cb['remaining'];

        $conn->begin_transaction();
        try {
            $result      = ['success' => true, 'casual_days' => 0, 'lop_days' => 0, 'message' => ''];
            $casual_days = min($available_casual, $days);
            $lop_days    = $days - $casual_days;

            if ($casual_days > 0) {
                $cs = $conn->prepare("INSERT INTO leaves (user_id, leave_type, from_date, to_date, days, day_type, reason, leave_year) VALUES (?, 'Casual', ?, ?, ?, ?, ?, ?)");
                $cs->bind_param("issdsss", $user_id, $from_date, $to_date, $casual_days, $day_type, $reason, $year_label);
                $cs->execute(); $cs->close();
                $result['casual_days'] = $casual_days;
            }
            if ($lop_days > 0) {
                $lr = $reason . " (Excess leave - Loss of Pay)";
                $ls = $conn->prepare("INSERT INTO leaves (user_id, leave_type, from_date, to_date, days, day_type, reason, leave_year) VALUES (?, 'LOP', ?, ?, ?, ?, ?, ?)");
                $ls->bind_param("issdsss", $user_id, $from_date, $to_date, $lop_days, $day_type, $lr, $year_label);
                $ls->execute(); $ls->close();
                $result['lop_days'] = $lop_days;
            }

            $new_remaining = max(0, $available_casual - $casual_days);
            saveCasualLeaveCarryForward($conn, $user_id, $current_month_key, $new_remaining);

            $conn->commit();
            $result['message'] = $result['lop_days'] > 0
                ? "Applied: {$result['casual_days']} casual + {$result['lop_days']} LOP"
                : "Leave applied successfully";
            return $result;

        } catch (Exception $e) {
            $conn->rollback();
            return ['success' => false, 'message' => 'Error: ' . $e->getMessage()];
        }

    } elseif ($leave_type === 'Sick') {
        // Get sick leave balance for the year
        $leave_year_data  = getCurrentLeaveYear();
        $sick_used        = getSickLeaveUsedYearly($conn, $user_id, $leave_year_data);
        $sick_entitlement = 6;

        // Adjust entitlement for prorated joining
        $join_date = null;
        $js = $conn->prepare("SELECT join_date FROM users WHERE id = ?");
        $js->bind_param("i", $user_id);
        $js->execute();
        if ($row = $js->get_result()->fetch_assoc()) $join_date = $row['join_date'];
        $js->close();

        if ($join_date) {
            $join       = new DateTime($join_date);
            $year_start = new DateTime($leave_year_data['start_date']);
            if ($join > $year_start) {
                $year_end         = new DateTime($leave_year_data['end_date']);
                $proration_factor = ($join->diff($year_end)->days + 1) / ($year_start->diff($year_end)->days + 1);
                $quarter          = ceil((int)$join->format('n') / 3);
                $quarters_remaining = 4 - $quarter + 1;
                $sick_entitlement = round(1.5 * $quarters_remaining, 1);
            }
        }

        $sick_remaining = max(0, $sick_entitlement - $sick_used);
        $sick_days      = min($sick_remaining, $days);
        $lop_days       = $days - $sick_days;

        $conn->begin_transaction();
        try {
            $result = ['success' => true, 'sick_days' => 0, 'lop_days' => 0, 'message' => ''];

            if ($sick_days > 0) {
                $ss = $conn->prepare("INSERT INTO leaves (user_id, leave_type, from_date, to_date, days, day_type, reason, leave_year) VALUES (?, 'Sick', ?, ?, ?, ?, ?, ?)");
                $ss->bind_param("issdsss", $user_id, $from_date, $to_date, $sick_days, $day_type, $reason, $year_label);
                $ss->execute(); $ss->close();
                $result['sick_days'] = $sick_days;
            }
            if ($lop_days > 0) {
                $lr = $reason . " (Sick leave exhausted - Loss of Pay)";
                $ls = $conn->prepare("INSERT INTO leaves (user_id, leave_type, from_date, to_date, days, day_type, reason, leave_year) VALUES (?, 'LOP', ?, ?, ?, ?, ?, ?)");
                $ls->bind_param("issdsss", $user_id, $from_date, $to_date, $lop_days, $day_type, $lr, $year_label);
                $ls->execute(); $ls->close();
                $result['lop_days'] = $lop_days;
            }

            $conn->commit();
            $result['message'] = $result['lop_days'] > 0
                ? "Applied: {$result['sick_days']} Sick + {$result['lop_days']} LOP (sick leave exhausted)"
                : "Leave applied successfully";
            return $result;

        } catch (Exception $e) {
            $conn->rollback();
            return ['success' => false, 'message' => 'Error: ' . $e->getMessage()];
        }

    } else {
        $stmt = $conn->prepare("INSERT INTO leaves (user_id, leave_type, from_date, to_date, days, day_type, reason, leave_year) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->bind_param("issdssss", $user_id, $leave_type, $from_date, $to_date, $days, $day_type, $reason, $year_label);
        if ($stmt->execute()) { $stmt->close(); return ['success' => true, 'message' => 'Leave applied successfully']; }
        $error = $stmt->error; $stmt->close();
        return ['success' => false, 'message' => 'Error: ' . $error];
    }
}


// ============================================================
//  WORKING DAYS / MISC  — UNCHANGED
// ============================================================

function calculateWorkingDays($from_date, $to_date, $day_type) {
    $from = new DateTime($from_date);
    $to   = new DateTime($to_date);
    if ($from == $to) return ($day_type === 'half') ? 0.5 : 1;
    $working_days = 0;
    $cur = clone $from;
    while ($cur <= $to) {
        if ((int)$cur->format('N') <= 6) $working_days++;
        $cur->modify('+1 day');
    }
    return ($day_type === 'half') ? $working_days * 0.5 : $working_days;
}

function getCurrentLeaveMonth() {
    $today = new DateTime();
    $sd    = $today->format('Y-m') . '-01';
    return ['start_date' => $sd, 'end_date' => date('Y-m-t', strtotime($sd)), 'month_label' => date('F Y', strtotime($sd))];
}

/**
 * Leave year statistics — now includes casual cycle info and both countdowns.
 */
function getLeaveYearStatistics($conn) {
    $current  = getCurrentLeaveYear();
    $previous = getPreviousLeaveYear();

    $cs = $conn->prepare("SELECT COUNT(*) AS ta, COALESCE(SUM(days),0) AS td, COUNT(DISTINCT user_id) AS te FROM leaves WHERE from_date BETWEEN ? AND ?");
    $cs->bind_param("ss", $current['start_date'], $current['end_date']);
    $cs->execute();
    $cur = $cs->get_result()->fetch_assoc(); $cs->close();

    $ps = $conn->prepare("SELECT COUNT(*) AS ta, COALESCE(SUM(days),0) AS td, COUNT(DISTINCT user_id) AS te FROM leaves WHERE from_date BETWEEN ? AND ?");
    $ps->bind_param("ss", $previous['start_date'], $previous['end_date']);
    $ps->execute();
    $prev = $ps->get_result()->fetch_assoc(); $ps->close();

    $next_sick_reset = new DateTime($current['end_date']);
    $next_sick_reset->modify('+1 day');
    $today           = new DateTime();

    $casual_year    = getCurrentCasualLeaveYear();
    $current_window = getCurrentCasualWindow();

    return [
        // Sick year
        'current_year'             => $current['year_label'],
        'current_start'            => $current['start_date'],
        'current_end'              => $current['end_date'],
        'current_applications'     => $cur['ta'] ?? 0,
        'current_days'             => floatval($cur['td'] ?? 0),
        'current_employees'        => $cur['te'] ?? 0,
        'previous_year'            => $previous['year_label'],
        'previous_applications'    => $prev['ta'] ?? 0,
        'previous_days'            => floatval($prev['td'] ?? 0),
        'previous_employees'       => $prev['te'] ?? 0,
        'days_until_reset'         => $today->diff($next_sick_reset)->days, // sick year reset (Dec 31)
        'reset_date'               => $next_sick_reset->format('Y-m-d'),
        'is_reset_period'          => ($today->format('m-d') == '01-01'),
        // Casual cycle
        'casual_leave_year'        => $casual_year['year_label'],
        'casual_year_start'        => $casual_year['start_date'],
        'casual_year_end'          => $casual_year['end_date'],
        'casual_reset_date'        => $casual_year['end_year'] . '-03-16', // next cycle start
        // Dashboard countdowns
        'days_until_monthly_reset' => daysUntilNextMonthlyWindow(),  // days until next 16th
        'days_until_yearly_reset'  => daysUntilCasualYearReset(),    // days until next Mar 16
        'current_window_start'     => $current_window['window_start'],
        'current_window_end'       => $current_window['window_end'],
    ];
}

function initializeEmployeeLeaveBalance($conn, $user_id, $join_date) {
    $casual_year   = getCasualLeaveYearForDate($join_date);
    $calendar_year = getLeaveYearForDate($join_date);
    $join_month    = (int)(new DateTime($join_date))->format('n');

    $casual_entitled       = calculateCasualEntitledDays($join_date, $casual_year, 0);
    $months_remaining_sick = 12 - $join_month + 1;
    $sick_prorated         = round((6 / 12) * $months_remaining_sick, 1);

    return [
        'sick'             => $sick_prorated,
        'casual'           => $casual_entitled,
        'lop'              => 0,
        'leave_year'       => $calendar_year['year_label'],
        'casual_year'      => $casual_year['year_label'],
        'proration_factor' => round($casual_entitled / 12, 2),
    ];
}

/**
 * Reset sick/LOP balances for new calendar year (Jan 1).
 * Casual reset is handled separately on Mar 16.
 */
function resetLeaveBalancesForNewYear($conn) {
    $leave_year = getCurrentLeaveYear();
    $prev_year  = getPreviousLeaveYear();
    $results    = ['success' => false, 'message' => '', 'users_updated' => 0, 'carry_forward' => 0];

    $conn->begin_transaction();
    try {
        $conn->query("
            CREATE TABLE IF NOT EXISTS leave_balances_archive (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_id INT NOT NULL, leave_year VARCHAR(12) NOT NULL,
                leave_year_type VARCHAR(20) DEFAULT 'calendar',
                sick_leave_total DECIMAL(5,1) DEFAULT 0, sick_leave_used DECIMAL(5,1) DEFAULT 0, sick_leave_carried DECIMAL(5,1) DEFAULT 0,
                casual_leave_total DECIMAL(5,1) DEFAULT 0, casual_leave_used DECIMAL(5,1) DEFAULT 0, casual_leave_carried DECIMAL(5,1) DEFAULT 0,
                lop_leave_total DECIMAL(5,1) DEFAULT 0, lop_leave_used DECIMAL(5,1) DEFAULT 0, lop_leave_carried DECIMAL(5,1) DEFAULT 0,
                archived_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_user_year (user_id, leave_year)
            )
        ");

        $users = $conn->query("SELECT id FROM users WHERE status = 'active' OR status IS NULL");
        if (!$users) throw new Exception("Failed to fetch users: " . $conn->error);

        $carry_forward_limit = 6;

        while ($user = $users->fetch_assoc()) {
            $uid = $user['id'];
            $pu  = $conn->prepare("SELECT leave_type, COALESCE(SUM(days),0) AS tu FROM leaves WHERE user_id=? AND status='Approved' AND from_date BETWEEN ? AND ? GROUP BY leave_type");
            $pu->bind_param("iss", $uid, $prev_year['start_date'], $prev_year['end_date']);
            $pu->execute();
            $sick_used = $lop_used = 0;
            while ($row = $pu->get_result()->fetch_assoc()) {
                if ($row['leave_type'] == 'Sick') $sick_used = $row['tu'];
                if ($row['leave_type'] == 'LOP')  $lop_used  = $row['tu'];
            }
            $pu->close();

            $sick_carry = min(max(0, 6 - $sick_used), $carry_forward_limit);

            $arch = $conn->prepare("INSERT INTO leave_balances_archive (user_id, leave_year, leave_year_type, sick_leave_total, sick_leave_used, sick_leave_carried, lop_leave_total, lop_leave_used, lop_leave_carried) VALUES (?, ?, 'calendar', 6, ?, ?, 0, ?, 0) ON DUPLICATE KEY UPDATE sick_leave_used=VALUES(sick_leave_used), sick_leave_carried=VALUES(sick_leave_carried), archived_date=CURRENT_TIMESTAMP");
            $arch->bind_param("isddd", $uid, $prev_year['year_label'], $sick_used, $sick_carry, $lop_used);
            $arch->execute(); $arch->close();

            $results['users_updated']++;
            $results['carry_forward'] += $sick_carry;
        }

        $tbl = $conn->query("SHOW TABLES LIKE 'system_logs'");
        if ($tbl && $tbl->num_rows > 0) {
            $log = $conn->prepare("INSERT INTO system_logs (event_type, description, user_id, created_at) VALUES ('leave_year_reset', ?, ?, NOW())");
            $description = "Sick leave year reset for {$leave_year['year_label']}. Carry forward limit: {$carry_forward_limit}.";
            $admin_id    = $_SESSION['user_id'] ?? 0;
            $log->bind_param("si", $description, $admin_id);
            $log->execute(); $log->close();
        }

        $conn->commit();
        $results['success'] = true;
        $results['message'] = "Leave balances reset for {$results['users_updated']} users.";
    } catch (Exception $e) {
        $conn->rollback();
        $results['message'] = "Error: " . $e->getMessage();
    }
    return $results;
}

/**
 * Call on every login.
 * Handles BOTH: sick year reset (Jan 1) + casual cycle reset (Mar 16).
 */
function checkAndAutoResetLeaveYear($conn) {
    $leave_year = getCurrentLeaveYear();
    $result     = ['checked' => true, 'reset_performed' => false, 'message' => '', 'leave_year' => $leave_year['year_label']];

    $today      = new DateTime();
    $reset_date = new DateTime($leave_year['end_date']);
    $reset_date->modify('+1 day');

    if ($today >= $reset_date) {
        $tbl = $conn->query("SHOW TABLES LIKE 'system_logs'");
        if ($tbl && $tbl->num_rows > 0) {
            $chk = $conn->prepare("SELECT COUNT(*) AS count FROM system_logs WHERE event_type = 'leave_year_reset' AND DATE(created_at) = CURDATE()");
            $chk->execute();
            $chk_row = $chk->get_result()->fetch_assoc();
            $chk->close();

            if ($chk_row['count'] == 0) {
                $rr = resetLeaveBalancesForNewYear($conn);
                $result['reset_performed'] = $rr['success'];
                $result['message']        = $rr['message'];
                $result['users_updated']  = $rr['users_updated'] ?? 0;
                $result['carry_forward']  = $rr['carry_forward'] ?? 0;
            } else {
                $result['message'] = "Sick leave year reset already done today for {$leave_year['year_label']}";
            }
        }
    }

    // Always check casual cycle reset (fires on Mar 16 of each year)
    checkAndAutoResetCasualLeaveYear($conn);

    return $result;
}

/**
 * Get next sick leave reset date (next Jan 1)
 */
function getNextSickResetDate() {
    $today = new DateTime();
    $year_now = (int)$today->format('Y');
    $month_now = (int)$today->format('n');
    $day_now = (int)$today->format('j');
    
    // Sick leave resets on Jan 1
    if ($month_now == 1 && $day_now == 1) {
        // Today is reset day
        return $year_now . '-01-01';
    } else {
        // Next reset is Jan 1 of next year
        return ($year_now + 1) . '-01-01';
    }
}

/**
 * Get days until next sick reset
 */
function getDaysUntilSickReset() {
    $today = new DateTime();
    $reset_date = new DateTime(getNextSickResetDate());
    
    // If today is reset day, return 0
    if ($today->format('Y-m-d') === $reset_date->format('Y-m-d')) {
        return 0;
    }
    
    $interval = $today->diff($reset_date);
    return $interval->days;
}
?>