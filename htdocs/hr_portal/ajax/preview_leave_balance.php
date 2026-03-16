<?php
require_once '../config/db.php';
require_once '../includes/leave_functions.php';

header('Content-Type: application/json');

if (!isset($_GET['join_date']) || empty($_GET['join_date'])) {
    echo json_encode(['success' => false, 'message' => 'Join date is required']);
    exit();
}

$join_date = $_GET['join_date'];

// Get leave year for the join date (Mar 16 - Mar 15 cycle)
$leave_year = getLeaveYearForDate($join_date);
$casual_year = getCasualLeaveYearForDate($join_date);

// Parse join date
$join = new DateTime($join_date);
$join_day = (int)$join->format('j');
$join_month = (int)$join->format('n');
$join_year = (int)$join->format('Y');

// Get all windows in the cycle
$windows = getCasualCycleWindows($casual_year);

// Count windows that the employee is eligible for
$eligible_windows = 0;
$first_window = null;

foreach ($windows as $window) {
    $window_start = new DateTime($window['window_start']);
    $window_end = new DateTime($window['window_end']);
    
    // Check if the employee is eligible for this window
    // They are eligible if they joined on or before the window's end date
    // AND if they joined before the window's start date? No - they need to be employed during the window
    
    // For a window to count, the employee must have joined BEFORE the window ends
    // and the window must start AFTER they join? Let's think:
    
    // Window: Mar 16 - Apr 15
    // If join on Mar 18: They miss this window because it started before they joined
    // If join on Apr 10: They get this window because they joined during it
    
    if ($join <= $window_end) {
        // Employee joined before or during this window
        if ($join <= $window_start) {
            // Joined before window started - full window counts
            $eligible_windows++;
            if (!$first_window) {
                $first_window = $window;
            }
        } else {
            // Joined during the window - this window counts
            $eligible_windows++;
            if (!$first_window) {
                $first_window = $window;
            }
        }
    }
}

// CASUAL LEAVE - 1 day per eligible window
$casual_prorated = $eligible_windows;

// SICK LEAVE - 0.5 days per eligible window (6 days / 12 windows = 0.5 per window)
$sick_prorated = round($eligible_windows * 0.5);
if ($sick_prorated < 1 && $eligible_windows > 0) {
    $sick_prorated = 1;
}

echo json_encode([
    'success' => true,
    'balance' => [
        'sick' => $sick_prorated,
        'casual' => $casual_prorated
    ],
    'leave_year' => $leave_year['year_label'],
    'casual_year' => $casual_year['year_label'],
    'eligible_windows' => $eligible_windows,
    'cycle_info' => [
        'start_date' => $leave_year['start_date'],
        'end_date' => $leave_year['end_date'],
        'first_window' => $first_window ? [
            'start' => $first_window['window_start'],
            'end' => $first_window['window_end'],
            'display' => date('M d', strtotime($first_window['window_start'])) . ' - ' . date('M d', strtotime($first_window['window_end']))
        ] : null
    ]
]);
?>