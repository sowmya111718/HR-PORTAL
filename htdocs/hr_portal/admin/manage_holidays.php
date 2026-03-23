<?php
mysqli_report(MYSQLI_REPORT_OFF);
require_once '../config/db.php';
require_once '../includes/leave_functions.php';
require_once '../includes/icon_functions.php';

date_default_timezone_set('Asia/Kolkata');

if (!isLoggedIn()) {
    header('Location: ../auth/login.php');
    exit();
}

$user_id = $_SESSION['user_id'];

// Always refresh role from DB
global $conn;
$_r = $conn->prepare("SELECT role FROM users WHERE id = ?");
$_r->bind_param("i", $user_id);
$_r->execute();
$_rrow = $_r->get_result()->fetch_assoc();
$_r->close();
if ($_rrow && !empty($_rrow['role'])) {
    $_SESSION['role'] = $_rrow['role'];
}
$role = $_SESSION['role'];

// Only HR, Admin, dm, ed, coo allowed
if (!in_array(strtolower($role), ['hr', 'admin', 'dm', 'ed', 'coo'])) {
    header('Location: ../dashboard.php');
    exit();
}

$message = '';

// ── FETCH HOLIDAY NAMES FROM INTERNET (suggestions only — admin decides which to add) ──
function fetchHolidayNamesFromAPI($year) {
    $country = 'IN';
    $url = "https://date.nager.at/api/v3/PublicHolidays/{$year}/{$country}";
    $response = false;

    // Try cURL first (works even when allow_url_fopen is disabled)
    if (function_exists('curl_init')) {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_USERAGENT      => 'MAKSIM-HR/1.0',
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_FOLLOWLOCATION => true,
        ]);
        $response = curl_exec($ch);
        curl_close($ch);
    }

    // Fallback to file_get_contents if cURL not available
    if (!$response && ini_get('allow_url_fopen')) {
        $ctx = stream_context_create(['http' => ['timeout' => 8, 'user_agent' => 'MAKSIM-HR/1.0']]);
        $response = @file_get_contents($url, false, $ctx);
    }

    if (!$response) return [];
    $data = json_decode($response, true);
    if (!is_array($data)) return [];
    $holidays = [];
    foreach ($data as $h) {
        $holidays[] = [
            'date' => $h['date'],
            'name' => strtoupper($h['localName'] ?? $h['name'])
        ];
    }
    return $holidays;
}
// ── END FETCH FUNCTION ──────────────────────────────────────────────────────

// ── DEBUG TEST (visit ?debug_fetch=1 to see results) ────────────────────────
if (isset($_GET['debug_fetch'])) {
    $test_url = "https://date.nager.at/api/v3/PublicHolidays/2026/IN";
    $debug = [];
    $debug[] = "PHP version: " . PHP_VERSION;
    $debug[] = "allow_url_fopen: " . ini_get('allow_url_fopen');
    $debug[] = "cURL enabled: " . (function_exists('curl_init') ? 'YES' : 'NO');
    if (function_exists('curl_init')) {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $test_url, CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10, CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_USERAGENT => 'MAKSIM-HR/1.0',
            CURLOPT_SSL_VERIFYPEER => false, CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_FOLLOWLOCATION => true,
        ]);
        $res = curl_exec($ch);
        $err = curl_error($ch);
        $inf = curl_getinfo($ch);
        curl_close($ch);
        $debug[] = "cURL HTTP code: " . $inf['http_code'];
        $debug[] = "cURL error: " . ($err ?: 'none');
        $debug[] = "Response length: " . strlen($res ?: '');
        $debug[] = "Response preview: " . substr($res ?: '', 0, 300);
    }
    if (ini_get('allow_url_fopen')) {
        $r2 = @file_get_contents($test_url);
        $debug[] = "file_get_contents length: " . strlen($r2 ?: '');
    }
    echo '<pre style="background:#111;color:#0f0;padding:20px;margin:20px;border-radius:8px;">';
    echo htmlspecialchars(implode("\n", $debug));
    echo '</pre>';
    exit();
}
// ── END DEBUG ────────────────────────────────────────────────────────────────

// Ensure wish_holidays table exists (for dashboard banner only — no LOP impact)
$conn->query("CREATE TABLE IF NOT EXISTS wish_holidays (
    id INT AUTO_INCREMENT PRIMARY KEY,
    holiday_date DATE NOT NULL UNIQUE,
    holiday_name VARCHAR(100) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)");

// Auto-sync internet holidays into wish_holidays (for wishing only, no LOP impact)
$wish_year = (int)date('Y');
$wish_check = $conn->query("SELECT COUNT(*) as cnt FROM wish_holidays WHERE YEAR(holiday_date) IN ($wish_year, " . ($wish_year+1) . ")");
$wish_count = $wish_check->fetch_assoc()['cnt'];
if ($wish_count < 5 && !isset($_SESSION['wish_synced_' . $wish_year])) {
    foreach ([$wish_year, $wish_year + 1] as $wy) {
        $wish_holidays = fetchHolidayNamesFromAPI($wy);
        foreach ($wish_holidays as $wh) {
            $conn->query("INSERT IGNORE INTO wish_holidays (holiday_date, holiday_name) VALUES ('{$wh['date']}', '" . $conn->real_escape_string($wh['name']) . "')");
        }
    }
    $_SESSION['wish_synced_' . $wish_year] = true;
}

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
if (isset($_GET['delete']) && in_array(strtolower($role), ['hr', 'admin', 'dm', 'ed', 'coo'])) {
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

// Update wish_holiday name and/or date
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_wish_holiday'])) {
    $wh_id   = intval($_POST['wh_id']);
    $wh_date = sanitize($_POST['wh_date']);
    $wh_name = strtoupper(trim(sanitize($_POST['wh_name'])));
    if ($wh_id && $wh_date && $wh_name) {
        // Check if another wish holiday already exists on this date (excluding current record)
        $chk = $conn->prepare("SELECT id, holiday_name FROM wish_holidays WHERE holiday_date = ? AND id != ?");
        $chk->bind_param("si", $wh_date, $wh_id);
        $chk->execute();
        $chk_row = $chk->get_result()->fetch_assoc();
        $chk->close();
        if ($chk_row) {
            $message = '<div class="alert alert-error"><i class="icon-error"></i> ' . date('d M Y', strtotime($wh_date)) . ' already has a wish holiday: <strong>' . htmlspecialchars($chk_row['holiday_name']) . '</strong>. Please choose a different date or delete the existing one first.</div>';
        } else {
            $stmt = $conn->prepare("UPDATE wish_holidays SET holiday_date = ?, holiday_name = ? WHERE id = ?");
            $stmt->bind_param("ssi", $wh_date, $wh_name, $wh_id);
            if ($stmt->execute()) {
                $message = '<div class="alert alert-success"><i class="icon-success"></i> Wish holiday updated successfully.</div>';
            } else {
                $message = '<div class="alert alert-error"><i class="icon-error"></i> Error: ' . $conn->error . '</div>';
            }
            $stmt->close();
        }
    }
}

// Add new wish_holiday
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_wish_holiday'])) {
    $wh_date = sanitize($_POST['new_wh_date']);
    $wh_name = strtoupper(trim(sanitize($_POST['new_wh_name'])));
    if (empty($wh_date) || empty($wh_name)) {
        $message = '<div class="alert alert-error"><i class="icon-error"></i> Both name and date are required.</div>';
    } else {
        $stmt = $conn->prepare("INSERT INTO wish_holidays (holiday_date, holiday_name) VALUES (?, ?) ON DUPLICATE KEY UPDATE holiday_name = VALUES(holiday_name)");
        $stmt->bind_param("ss", $wh_date, $wh_name);
        if ($stmt->execute()) {
            $message = '<div class="alert alert-success"><i class="icon-success"></i> <strong>' . htmlspecialchars($wh_name) . '</strong> added to wish holidays on ' . date('d M Y', strtotime($wh_date)) . '.</div>';
        } else {
            $message = '<div class="alert alert-error"><i class="icon-error"></i> Error: ' . $conn->error . '</div>';
        }
        $stmt->close();
    }
}

// Delete wish_holiday
if (isset($_GET['delete_wish']) && in_array(strtolower($role), ['hr', 'admin', 'dm', 'ed', 'coo'])) {
    $wh_id = intval($_GET['delete_wish']);
    $stmt = $conn->prepare("DELETE FROM wish_holidays WHERE id = ?");
    $stmt->bind_param("i", $wh_id);
    if ($stmt->execute()) {
        $message = '<div class="alert alert-success"><i class="icon-success"></i> Wish holiday removed.</div>';
    } else {
        $message = '<div class="alert alert-error"><i class="icon-error"></i> Error removing wish holiday.</div>';
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

            <!-- Wish Holidays Panel -->
            <div class="holiday-card" style="background: #ebf8ff; border: 1px solid #90cdf4;">
                <h3 style="color: #2b6cb0;">🎉 Dashboard Wish Holidays (Indian Festivals)</h3>
                <p style="color: #4a5568; font-size: 14px; margin-bottom: 5px;">
                    These festivals are used <strong>only for dashboard wishes</strong>.
                    They do <strong>NOT</strong> affect timesheets, LOP, or working days at MAKSIM.
                </p>
                <p style="color: #c53030; font-size: 13px; margin-bottom: 5px;">
                    ⚠️ To make a date an actual company holiday (no timesheet required, no LOP), use the <strong>Add New Holiday</strong> form below.
                </p>
                <p style="color: #805ad5; font-size: 13px; margin-bottom: 15px;">
                    ✏️ Click <strong>Edit</strong> to change name/date &nbsp;|&nbsp; 🗑️ to remove &nbsp;|&nbsp; ➕ Add to add new festivals for any year.
                </p>
                <?php
                // ── Hardcoded full Indian festival list ──────────────────────
                function getAllIndianFestivals() {
                    return [
                        ['2025-01-01','New Year'],['2025-01-13','Lohri'],
                        ['2025-01-14','Makar Sankranti'],['2025-01-14','Pongal'],
                        ['2025-01-26','Republic Day'],['2025-02-02','Basant Panchami'],
                        ['2025-02-26','Maha Shivratri'],['2025-03-14','Holi'],
                        ['2025-03-30','Ram Navami'],['2025-03-31','Ugadi'],
                        ['2025-03-31','Telugu New Year'],['2025-03-31','Gudi Padwa'],
                        ['2025-04-06','Baisakhi'],['2025-04-06','Vishu'],
                        ['2025-04-10','Mahavir Jayanti'],['2025-04-13','Tamil New Year'],
                        ['2025-04-14','Ambedkar Jayanti'],['2025-04-18','Good Friday'],
                        ['2025-04-20','Easter'],['2025-04-30','Akshaya Tritiya'],
                        ['2025-05-01','Labour Day'],['2025-05-12','Buddha Purnima'],
                        ['2025-06-06','Eid ul Adha'],['2025-07-06','Muharram'],
                        ['2025-07-13','Guru Purnima'],['2025-08-09','Milad un Nabi'],
                        ['2025-08-15','Independence Day'],['2025-08-16','Janmashtami'],
                        ['2025-08-27','Raksha Bandhan'],['2025-08-29','Onam'],
                        ['2025-09-05','Ganesh Chaturthi'],['2025-09-05','Vinayaka Chavithi'],
                        ['2025-10-02','Gandhi Jayanti'],['2025-10-02','Navratri'],
                        ['2025-10-10','Durga Puja'],['2025-10-13','Karva Chauth'],
                        ['2025-10-20','Dussehra'],['2025-10-20','Vijaya Dashami'],
                        ['2025-10-26','Maharshi Valmiki Jayanti'],
                        ['2025-11-01','Diwali'],['2025-11-01','Kannada Rajyotsava'],
                        ['2025-11-02','Govardhan Puja'],['2025-11-03','Bhai Dooj'],
                        ['2025-11-05','Guru Nanak Jayanti'],['2025-11-07','Chhath Puja'],
                        ['2025-12-25','Christmas'],
                        ['2026-01-01','New Year'],['2026-01-13','Lohri'],
                        ['2026-01-14','Makar Sankranti'],['2026-01-14','Pongal'],
                        ['2026-01-26','Republic Day'],['2026-02-14','Basant Panchami'],
                        ['2026-02-17','Maha Shivratri'],['2026-03-03','Holi'],
                        ['2026-03-19','Ugadi'],['2026-03-19','Telugu New Year'],
                        ['2026-03-20','Gudi Padwa'],['2026-03-28','Ram Navami'],
                        ['2026-03-31','Eid ul Fitr'],['2026-04-03','Good Friday'],
                        ['2026-04-05','Easter'],['2026-04-06','Baisakhi'],
                        ['2026-04-08','Mahavir Jayanti'],['2026-04-13','Vishu'],
                        ['2026-04-14','Ambedkar Jayanti'],['2026-04-14','Tamil New Year'],
                        ['2026-04-19','Akshaya Tritiya'],['2026-05-01','Labour Day'],
                        ['2026-05-26','Eid ul Adha'],['2026-05-31','Buddha Purnima'],
                        ['2026-06-26','Muharram'],['2026-07-02','Guru Purnima'],
                        ['2026-08-05','Janmashtami'],['2026-08-15','Independence Day'],
                        ['2026-08-16','Raksha Bandhan'],['2026-08-25','Ganesh Chaturthi'],
                        ['2026-08-25','Vinayaka Chavithi'],['2026-09-23','Onam'],
                        ['2026-10-02','Gandhi Jayanti'],['2026-10-02','Navratri'],
                        ['2026-10-08','Karva Chauth'],['2026-10-15','Maharshi Valmiki Jayanti'],
                        ['2026-10-18','Milad un Nabi'],['2026-10-23','Dussehra'],
                        ['2026-10-23','Vijaya Dashami'],['2026-10-30','Durga Puja'],
                        ['2026-11-01','Kannada Rajyotsava'],['2026-11-08','Diwali'],
                        ['2026-11-09','Govardhan Puja'],['2026-11-10','Bhai Dooj'],
                        ['2026-11-24','Guru Nanak Jayanti'],['2026-11-27','Chhath Puja'],
                        ['2026-12-25','Christmas'],
                        ['2027-01-01','New Year'],['2027-01-13','Lohri'],
                        ['2027-01-14','Makar Sankranti'],['2027-01-14','Pongal'],
                        ['2027-01-26','Republic Day'],['2027-02-03','Basant Panchami'],
                        ['2027-03-08','Maha Shivratri'],['2027-03-20','Eid ul Fitr'],
                        ['2027-03-22','Holi'],['2027-04-06','Baisakhi'],
                        ['2027-04-07','Ugadi'],['2027-04-07','Telugu New Year'],
                        ['2027-04-08','Gudi Padwa'],['2027-04-09','Good Friday'],
                        ['2027-04-09','Mahavir Jayanti'],['2027-04-11','Easter'],
                        ['2027-04-14','Ambedkar Jayanti'],['2027-04-14','Tamil New Year'],
                        ['2027-04-15','Vishu'],['2027-04-28','Ram Navami'],
                        ['2027-05-01','Labour Day'],['2027-05-16','Eid ul Adha'],
                        ['2027-05-20','Buddha Purnima'],['2027-06-15','Muharram'],
                        ['2027-07-22','Guru Purnima'],['2027-08-15','Independence Day'],
                        ['2027-08-24','Janmashtami'],['2027-08-29','Raksha Bandhan'],
                        ['2027-09-13','Onam'],['2027-09-14','Ganesh Chaturthi'],
                        ['2027-09-14','Vinayaka Chavithi'],['2027-10-02','Gandhi Jayanti'],
                        ['2027-10-04','Maharshi Valmiki Jayanti'],['2027-10-07','Milad un Nabi'],
                        ['2027-10-13','Navratri'],['2027-10-24','Karva Chauth'],
                        ['2027-10-27','Dussehra'],['2027-10-27','Vijaya Dashami'],
                        ['2027-10-29','Diwali'],['2027-10-30','Govardhan Puja'],
                        ['2027-10-31','Bhai Dooj'],['2027-11-01','Kannada Rajyotsava'],
                        ['2027-11-04','Chhath Puja'],['2027-11-14','Guru Nanak Jayanti'],
                        ['2027-11-20','Durga Puja'],['2027-12-25','Christmas'],
                    ];
                }
                // Seed wish_holidays from local list — runs only when sparse
                $wish_year = (int)date('Y');
                $wc = $conn->query("SELECT COUNT(*) as cnt FROM wish_holidays WHERE YEAR(holiday_date) IN ($wish_year, " . ($wish_year+1) . ")")->fetch_assoc()['cnt'];
                if ($wc < 20 && !isset($_SESSION['wish_synced_' . $wish_year])) {
                    $ins = $conn->prepare("INSERT IGNORE INTO wish_holidays (holiday_date, holiday_name) VALUES (?, ?)");
                    foreach (getAllIndianFestivals() as $f) { $ins->bind_param("ss", $f[0], $f[1]); $ins->execute(); }
                    $ins->close();
                    $_SESSION['wish_synced_' . $wish_year] = true;
                }
                // Load from DB for display
                $suggest_year = isset($_GET['suggest_year']) ? intval($_GET['suggest_year']) : (int)date('Y');
                $wh_list = [];
                $wh_res = $conn->query("SELECT id, holiday_date, holiday_name FROM wish_holidays WHERE YEAR(holiday_date) = $suggest_year ORDER BY holiday_date");
                if ($wh_res) while ($wr = $wh_res->fetch_assoc()) $wh_list[] = $wr;
                $company_dates = [];
                $ex = $conn->query("SELECT holiday_date FROM holidays WHERE YEAR(holiday_date) = $suggest_year");
                while ($row = $ex->fetch_assoc()) $company_dates[] = $row['holiday_date'];
                // Build year tabs dynamically from DB
                $yr_res = $conn->query("SELECT DISTINCT YEAR(holiday_date) as yr FROM wish_holidays ORDER BY yr");
                $available_years = [];
                if ($yr_res) while ($yr_row = $yr_res->fetch_assoc()) $available_years[] = (int)$yr_row['yr'];
                if (!in_array((int)date('Y'), $available_years)) $available_years[] = (int)date('Y');
                if (!in_array((int)date('Y')+1, $available_years)) $available_years[] = (int)date('Y')+1;
                sort($available_years);
                ?>

                <!-- Year tabs -->
                <div style="display:flex; gap:10px; margin-bottom:15px; flex-wrap:wrap; align-items:center;">
                    <span style="font-size:13px; color:#4a5568;">Year:</span>
                    <?php foreach ($available_years as $yr): ?>
                    <a href="?suggest_year=<?php echo $yr; ?>" class="btn-add"
                       style="background:<?php echo $suggest_year==$yr?'#2b6cb0':'#718096'; ?>; padding:6px 14px; font-size:12px;">
                        <?php echo $yr; ?>
                    </a>
                    <?php endforeach; ?>
                </div>

                <!-- Add new wish holiday form -->
                <form method="POST" action="?suggest_year=<?php echo $suggest_year; ?>"
                      style="display:flex; flex-wrap:wrap; gap:10px; align-items:flex-end; background:#f0f4ff; padding:12px 15px; border-radius:8px; margin-bottom:18px; border:1px dashed #90cdf4;">
                    <div style="display:flex; flex-direction:column; gap:4px;">
                        <label style="font-size:11px; color:#4a5568; font-weight:600;">Festival Name *</label>
                        <input type="text" name="new_wh_name" placeholder="e.g. Diwali" required
                               style="font-size:12px; padding:5px 9px; border:1px solid #cbd5e0; border-radius:5px; width:160px;">
                    </div>
                    <div style="display:flex; flex-direction:column; gap:4px;">
                        <label style="font-size:11px; color:#4a5568; font-weight:600;">Date * (any year)</label>
                        <input type="date" name="new_wh_date" required
                               style="font-size:12px; padding:5px 9px; border:1px solid #cbd5e0; border-radius:5px;">
                    </div>
                    <button type="submit" name="add_wish_holiday"
                            style="padding:6px 16px; background:#2b6cb0; color:#fff; border:none; border-radius:5px; font-size:12px; cursor:pointer; font-weight:600;">
                        ➕ Add Wish Holiday
                    </button>
                </form>

                <!-- Festival cards -->
                <?php if (!empty($wh_list)): ?>
                <div style="display:flex; flex-wrap:wrap; gap:10px;">
                    <?php foreach ($wh_list as $s):
                        $is_company = in_array($s['holiday_date'], $company_dates);
                    ?>
                    <div style="background:<?php echo $is_company?'#f0fff4':'#fffff0'; ?>; border:1px solid <?php echo $is_company?'#9ae6b4':'#e2e8f0'; ?>; border-radius:8px; padding:10px 14px; min-width:210px; display:flex; flex-direction:column; gap:4px;">
                        <span style="font-weight:700; font-size:13px; color:#2d3748;"><?php echo htmlspecialchars($s['holiday_name']); ?></span>
                        <span style="font-size:12px; color:#718096;"><?php echo date('d M Y', strtotime($s['holiday_date'])); ?> &nbsp;(<?php echo date('l', strtotime($s['holiday_date'])); ?>)</span>
                        <?php if ($is_company): ?>
                            <span style="color:#276749; font-size:11px; font-weight:600;">🏢 Company Holiday</span>
                        <?php else: ?>
                            <span style="color:#2b6cb0; font-size:11px;">🎉 Wish only (working day)</span>
                        <?php endif; ?>
                        <!-- Edit -->
                        <details style="margin-top:2px;">
                            <summary style="font-size:11px; color:#805ad5; cursor:pointer;">✏️ Edit</summary>
                            <form method="POST" action="?suggest_year=<?php echo $suggest_year; ?>" style="margin-top:6px; display:flex; flex-direction:column; gap:5px;">
                                <input type="hidden" name="wh_id" value="<?php echo $s['id']; ?>">
                                <input type="text" name="wh_name" value="<?php echo htmlspecialchars($s['holiday_name']); ?>"
                                    placeholder="Festival name"
                                    style="font-size:11px; padding:3px 6px; border:1px solid #cbd5e0; border-radius:4px;">
                                <div style="display:flex; gap:5px; align-items:center;">
                                    <input type="date" name="wh_date" value="<?php echo $s['holiday_date']; ?>"
                                        style="font-size:11px; padding:3px 6px; border:1px solid #cbd5e0; border-radius:4px; flex:1;">
                                    <button type="submit" name="update_wish_holiday"
                                        style="font-size:11px; padding:4px 10px; background:#805ad5; color:#fff; border:none; border-radius:4px; cursor:pointer; white-space:nowrap;">
                                        Save
                                    </button>
                                </div>
                            </form>
                        </details>
                        <!-- Delete -->
                        <a href="?delete_wish=<?php echo $s['id']; ?>&suggest_year=<?php echo $suggest_year; ?>"
                           onclick="return confirm('Remove <?php echo htmlspecialchars($s['holiday_name']); ?> from wish holidays?')"
                           style="font-size:11px; color:#e53e3e; text-decoration:none; margin-top:2px;">🗑️ Remove</a>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php else: ?>
                <p style="color:#718096;">No wish holidays found for <?php echo $suggest_year; ?>. Use ➕ Add above to add festivals.</p>
                <?php endif; ?>
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