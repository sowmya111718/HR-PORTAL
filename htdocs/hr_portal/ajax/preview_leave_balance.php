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
$leave_year  = getLeaveYearForDate($join_date);
$casual_year = getCasualLeaveYearForDate($join_date);

// Use the fixed calculateCasualEntitledDays function (uses getWindowForDate internally).
// Rule: the window the employee joins IN always counts.
// Mar 16 – Apr 15 join → 12 casual days, Apr 16 – May 15 → 11, etc.
$casual_prorated = calculateCasualEntitledDays($join_date, $casual_year, 0);
$eligible_windows = $casual_prorated; // 1 day per window

// SICK LEAVE - prorated: 6 days / 12 windows = 0.5 per window, minimum 1
$sick_prorated = round($eligible_windows * 0.5);
if ($sick_prorated < 1 && $eligible_windows > 0) {
    $sick_prorated = 1;
}

// Get first qualifying window for display
$join_window = getWindowForDate($join_date);
$first_window = null;
foreach (getCasualCycleWindows($casual_year) as $w) {
    if ($w['month_key'] >= $join_window['month_key']) {
        $first_window = $w;
        break;
    }
}

echo json_encode([
    'success' => true,
    'balance' => [
        'sick'   => $sick_prorated,
        'casual' => $casual_prorated
    ],
    'leave_year'      => $leave_year['year_label'],
    'casual_year'     => $casual_year['year_label'],
    'eligible_windows' => $eligible_windows,
    'cycle_info' => [
        'start_date'   => $leave_year['start_date'],
        'end_date'     => $leave_year['end_date'],
        'first_window' => $first_window ? [
            'start'   => $first_window['window_start'],
            'end'     => $first_window['window_end'],
            'display' => date('M d', strtotime($first_window['window_start'])) . ' - ' . date('M d', strtotime($first_window['window_end']))
        ] : null
    ]
]);
?>