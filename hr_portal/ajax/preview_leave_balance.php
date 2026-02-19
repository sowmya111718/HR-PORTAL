<?php
require_once '../config/db.php';
require_once '../includes/leave_functions.php';

header('Content-Type: application/json');

// Check if user is logged in
if (!isLoggedIn()) {
    echo json_encode(['success' => false, 'message' => 'Not authenticated']);
    exit();
}

// Check if join date is provided
if (!isset($_GET['join_date']) || empty($_GET['join_date'])) {
    echo json_encode(['success' => false, 'message' => 'Join date is required']);
    exit();
}

$join_date = sanitize($_GET['join_date']);

// Validate date format
$date_timestamp = strtotime($join_date);
if ($date_timestamp === false) {
    echo json_encode(['success' => false, 'message' => 'Invalid date format']);
    exit();
}

try {
    // Calculate prorated leave balance
    $leave_year = getLeaveYearForDate($join_date);
    $default_entitlement = ['Sick' => 6, 'Casual' => 12, 'Other' => 40];
    
    // Pro-rate based on join date
    $join = new DateTime($join_date);
    $year_start = new DateTime($leave_year['start_date']);
    
    // If join date is before leave year start, use leave year start
    if ($join < $year_start) {
        $join = $year_start;
    }
    
    $year_end = new DateTime($leave_year['end_date']);
    $days_in_year = $year_start->diff($year_end)->days + 1;
    $days_remaining = $join->diff($year_end)->days + 1;
    
    // Ensure we don't divide by zero
    if ($days_in_year <= 0) {
        $days_in_year = 365;
    }
    
    $proration_factor = $days_remaining / $days_in_year;
    
    // Calculate prorated days (rounded to 1 decimal place)
    $sick_prorated = round($default_entitlement['Sick'] * $proration_factor, 1);
    $casual_prorated = round($default_entitlement['Casual'] * $proration_factor, 1);
    $other_prorated = round($default_entitlement['Other'] * $proration_factor, 1);
    
    // Ensure minimum of 0 days
    $sick_prorated = max(0, $sick_prorated);
    $casual_prorated = max(0, $casual_prorated);
    $other_prorated = max(0, $other_prorated);
    
    echo json_encode([
        'success' => true,
        'join_date' => $join_date,
        'leave_year' => $leave_year['year_label'],
        'year_start' => $leave_year['start_date'],
        'year_end' => $leave_year['end_date'],
        'days_in_year' => $days_in_year,
        'days_remaining' => $days_remaining,
        'proration_factor' => round($proration_factor, 2),
        'balance' => [
            'sick' => $sick_prorated,
            'casual' => $casual_prorated,
            'other' => $other_prorated
        ],
        'entitlement' => $default_entitlement,
        'message' => "Leave balance prorated based on join date. {$days_remaining} of {$days_in_year} days remaining in leave year."
    ]);
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false, 
        'message' => 'Error calculating leave balance: ' . $e->getMessage()
    ]);
}
?>