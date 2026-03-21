<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once 'config/db.php';
require_once 'includes/leave_functions.php';
require_once 'includes/birthday_functions.php';
require_once 'includes/notification_functions.php';

if (!isLoggedIn()) {
    header('Location: auth/login.php');
    exit();
}

$user_id   = $_SESSION['user_id'];
$role      = $_SESSION['role'];
$full_name = $_SESSION['full_name'];

// Get unread notifications count
$unread_count = 0;
$unread_query = $conn->prepare("SELECT COUNT(*) as count FROM notifications WHERE user_id = ? AND is_read = 0");
$unread_query->bind_param("i", $user_id);
$unread_query->execute();
$unread_result = $unread_query->get_result();
if ($unread_row = $unread_result->fetch_assoc()) {
    $unread_count = $unread_row['count'];
}
$unread_query->close();

// Get recent notifications (including read ones for history)
$recent_notifications = [];
$recent_query = $conn->prepare("
    SELECT * FROM notifications 
    WHERE user_id = ? 
    ORDER BY created_at DESC 
    LIMIT 10
");
$recent_query->bind_param("i", $user_id);
$recent_query->execute();
$recent_result = $recent_query->get_result();
while ($row = $recent_result->fetch_assoc()) {
    $recent_notifications[] = $row;
}
$recent_query->close();

// Get unread notifications for the panel
$unread_notifications = [];
$unread_list_query = $conn->prepare("
    SELECT * FROM notifications 
    WHERE user_id = ? AND is_read = 0
    ORDER BY created_at DESC 
    LIMIT 5
");
$unread_list_query->bind_param("i", $user_id);
$unread_list_query->execute();
$unread_list_result = $unread_list_query->get_result();
while ($row = $unread_list_result->fetch_assoc()) {
    $unread_notifications[] = $row;
}
$unread_list_query->close();

// Get the latest notification for the popup
$latest_notification = null;
if ($unread_count > 0) {
    $latest_query = $conn->prepare("
        SELECT * FROM notifications 
        WHERE user_id = ? AND is_read = 0 
        ORDER BY created_at DESC 
        LIMIT 1
    ");
    $latest_query->bind_param("i", $user_id);
    $latest_query->execute();
    $latest_result = $latest_query->get_result();
    if ($latest_row = $latest_result->fetch_assoc()) {
        $latest_notification = $latest_row;
    }
    $latest_query->close();
}

// Check if this is first login after session start (show welcome message)
$show_welcome = false;
if (!isset($_SESSION['welcome_shown'])) {
    $show_welcome = true;
    $_SESSION['welcome_shown'] = true;
}

// Check if notification popup has been shown
$show_notification_popup = false;
if (!isset($_SESSION['notification_popup_shown']) && $latest_notification) {
    $show_notification_popup = true;
    $_SESSION['notification_popup_shown'] = true;
}

// For HR/Admin/dm - Get counts of new submissions
$new_pending_leaves_today = 0;
$new_pending_permissions_today = 0;
$new_late_timesheets_today = 0;

if (in_array($role, ['hr', 'admin', 'dm', 'coo', 'ed'])) {
    $today_date = date('Y-m-d');
    
    // Check if we've already created notifications today
    $today_start = date('Y-m-d 00:00:00');
    $today_end = date('Y-m-d 23:59:59');
    
    $check_notifications_query = $conn->prepare("
        SELECT COUNT(*) as count 
        FROM notifications 
        WHERE user_id = ? 
        AND created_at BETWEEN ? AND ?
        AND type IN ('pending_leaves', 'pending_permissions', 'late_timesheets')
    ");
    $check_notifications_query->bind_param("iss", $user_id, $today_start, $today_end);
    $check_notifications_query->execute();
    $check_result = $check_notifications_query->get_result();
    $existing_notifications_today = $check_result->fetch_assoc()['count'];
    $check_notifications_query->close();
    
    // Count new pending leaves submitted today
    $leaves_today_query = $conn->prepare("
        SELECT COUNT(*) as count 
        FROM leaves 
        WHERE status = 'Pending' 
        AND DATE(applied_date) = ?
    ");
    $leaves_today_query->bind_param("s", $today_date);
    $leaves_today_query->execute();
    $leaves_today_result = $leaves_today_query->get_result();
    if ($leaves_today_row = $leaves_today_result->fetch_assoc()) {
        $new_pending_leaves_today = $leaves_today_row['count'];
    }
    $leaves_today_query->close();
    
    // Count new pending permissions submitted today
    $permissions_today_query = $conn->prepare("
        SELECT COUNT(*) as count 
        FROM permissions 
        WHERE status = 'Pending' 
        AND DATE(applied_date) = ?
    ");
    $permissions_today_query->bind_param("s", $today_date);
    $permissions_today_query->execute();
    $permissions_today_result = $permissions_today_query->get_result();
    if ($permissions_today_row = $permissions_today_result->fetch_assoc()) {
        $new_pending_permissions_today = $permissions_today_row['count'];
    }
    $permissions_today_query->close();
    
    // Count late timesheets submitted today
    $late_timesheets_query = $conn->prepare("
        SELECT COUNT(*) as count 
        FROM timesheets 
        WHERE submitted_date > CONCAT(entry_date, ' 23:59:59')
        AND DATE(submitted_date) = ?
    ");
    $late_timesheets_query->bind_param("s", $today_date);
    $late_timesheets_query->execute();
    $late_timesheets_result = $late_timesheets_query->get_result();
    if ($late_timesheets_row = $late_timesheets_result->fetch_assoc()) {
        $new_late_timesheets_today = $late_timesheets_row['count'];
    }
    $late_timesheets_query->close();
    
    // Only create notifications if we haven't created any today AND there are new items
    if ($existing_notifications_today == 0) {
        // Create notifications for new pending leaves
        if ($new_pending_leaves_today > 0) {
            $title = "New Pending Leave Applications";
            $message = "You have {$new_pending_leaves_today} new pending leave application(s) submitted today.";
            createNotification($conn, $user_id, 'pending_leaves', $title, $message);
        }
        
        // Create notifications for new pending permissions
        if ($new_pending_permissions_today > 0) {
            $title = "New Pending Permission Requests";
            $message = "You have {$new_pending_permissions_today} new pending permission request(s) submitted today.";
            createNotification($conn, $user_id, 'pending_permissions', $title, $message);
        }
        
        // Create notifications for late timesheets
        if ($new_late_timesheets_today > 0) {
            $title = "Late Timesheet Submissions";
            $message = "You have {$new_late_timesheets_today} late timesheet submission(s) today.";
            createNotification($conn, $user_id, 'late_timesheets', $title, $message);
        }
    }
}

// Check if today is user's birthday
$is_my_birthday = isUserBirthdayToday($conn, $user_id);

// Check if tomorrow is user's own birthday (for personal advance wish)
$is_my_birthday_tomorrow = false;
$my_bday_stmt = $conn->prepare("SELECT birthday FROM users WHERE id = ? AND birthday IS NOT NULL");
$my_bday_stmt->bind_param("i", $user_id);
$my_bday_stmt->execute();
$my_bday_row = $my_bday_stmt->get_result()->fetch_assoc();
$my_bday_stmt->close();
if ($my_bday_row && !empty($my_bday_row['birthday'])) {
    $my_bday_md = date('m-d', strtotime($my_bday_row['birthday']));
    $tomorrow_md_check = date('m-d', strtotime('+1 day'));
    $is_my_birthday_tomorrow = ($my_bday_md === $tomorrow_md_check);
}

// Get today's birthday people for celebration
$today_birthdays = getTodaysBirthdays($conn);

// Advance birthday wishes — people whose birthday is exactly tomorrow
$tomorrow_md = date('m-d', strtotime('+1 day'));
$advance_birthdays = [];
$abres = $conn->query("
    SELECT full_name, birthday
    FROM users
    WHERE birthday IS NOT NULL
      AND status != 'inactive'
      AND DATE_FORMAT(birthday, '%m-%d') = '$tomorrow_md'
    ORDER BY full_name ASC
");
if ($abres) {
    while ($abrow = $abres->fetch_assoc()) {
        $advance_birthdays[] = $abrow;
    }
}

// ── Holiday Advance Celebration Detection ──────────────────────────────────
// Check if tomorrow is a holiday — show "Advance Happy X" banner today
$tomorrow = date('Y-m-d', strtotime('+1 day'));
$today_date = date('Y-m-d');

// Holiday themes: name => [emoji, bg_gradient, text_color, decorations]
$holiday_themes = [
    // ── National Holidays ──────────────────────────────────────────────────
    'Republic Day'          => ['emoji' => '🇮🇳', 'bg' => 'linear-gradient(135deg, #ff9933 0%, #ffffff 50%, #138808 100%)', 'color' => '#000080', 'decor' => ['🇮🇳','🕊️','✨','🎆','⭐','🌟','💫','🎊']],
    'Independence Day'      => ['emoji' => '🇮🇳', 'bg' => 'linear-gradient(135deg, #ff9933 0%, #ffffff 50%, #138808 100%)', 'color' => '#000080', 'decor' => ['🇮🇳','🕊️','✨','🎆','🎇','⭐','🌟','💫']],
    'Gandhi Jayanthi'       => ['emoji' => '🕊️', 'bg' => 'linear-gradient(135deg, #667eea 0%, #764ba2 100%)', 'color' => '#ffffff', 'decor' => ['🕊️','🌿','✌️','🙏','⭐','🌟','💫','🌸']],
    'Gandhi Jayanti'        => ['emoji' => '🕊️', 'bg' => 'linear-gradient(135deg, #667eea 0%, #764ba2 100%)', 'color' => '#ffffff', 'decor' => ['🕊️','🌿','✌️','🙏','⭐','🌟','💫','🌸']],
    // ── Hindu Festivals ────────────────────────────────────────────────────
    'Diwali'                => ['emoji' => '🪔', 'bg' => 'linear-gradient(135deg, #1a0533 0%, #4a0080 40%, #f7971e 100%)', 'color' => '#ffd700', 'decor' => ['🪔','✨','🎆','🌟','💛','🎊','🌸','💫']],
    'Deepavali'             => ['emoji' => '🪔', 'bg' => 'linear-gradient(135deg, #1a0533 0%, #4a0080 40%, #f7971e 100%)', 'color' => '#ffd700', 'decor' => ['🪔','✨','🎆','🌟','💛','🎊','🌸','💫']],
    'Holi'                  => ['emoji' => '🎨', 'bg' => 'linear-gradient(135deg, #f953c6 0%, #f7971e 35%, #84fab0 65%, #667eea 100%)', 'color' => '#ffffff', 'decor' => ['🎨','🌈','✨','🎊','🌸','💜','💛','❤️']],
    'Ugadi'                 => ['emoji' => '🌸', 'bg' => 'linear-gradient(135deg, #f6d365 0%, #fda085 50%, #84fab0 100%)', 'color' => '#4a1942', 'decor' => ['🌺','🍀','🌼','🎋','🥭','🌸','✨','🎊']],
    'Gudi Padwa'            => ['emoji' => '🌸', 'bg' => 'linear-gradient(135deg, #f6d365 0%, #fda085 50%, #84fab0 100%)', 'color' => '#4a1942', 'decor' => ['🌺','🍀','🌼','🎋','🌸','✨','🎊','💛']],
    'Navratri'              => ['emoji' => '🏮', 'bg' => 'linear-gradient(135deg, #e91e63 0%, #ff5722 50%, #ffc107 100%)', 'color' => '#ffffff', 'decor' => ['🏮','🌺','💃','✨','🌸','🎊','💛','🌼']],
    'Durga Puja'            => ['emoji' => '🌺', 'bg' => 'linear-gradient(135deg, #e91e63 0%, #ff5722 50%, #ffc107 100%)', 'color' => '#ffffff', 'decor' => ['🌺','🏮','💃','✨','🌸','🎊','💛','🌼']],
    'Vijaya Dashami'        => ['emoji' => '⚔️', 'bg' => 'linear-gradient(135deg, #f7971e 0%, #ffd200 100%)', 'color' => '#4a1942', 'decor' => ['⚔️','🌟','🎊','✨','🏹','🌺','💛','🎆']],
    'Dussehra'              => ['emoji' => '⚔️', 'bg' => 'linear-gradient(135deg, #f7971e 0%, #ffd200 100%)', 'color' => '#4a1942', 'decor' => ['⚔️','🌟','🎊','✨','🏹','🌺','💛','🎆']],
    'Vinayaka Chavithi'     => ['emoji' => '🐘', 'bg' => 'linear-gradient(135deg, #f7971e 0%, #ffd200 50%, #ff6b35 100%)', 'color' => '#4a1942', 'decor' => ['🐘','🌺','🍬','🌸','✨','🎊','🪔','🌼']],
    'Ganesh Chaturthi'      => ['emoji' => '🐘', 'bg' => 'linear-gradient(135deg, #f7971e 0%, #ffd200 50%, #ff6b35 100%)', 'color' => '#4a1942', 'decor' => ['🐘','🌺','🍬','🌸','✨','🎊','🪔','🌼']],
    'Janmashtami'           => ['emoji' => '🦚', 'bg' => 'linear-gradient(135deg, #1a237e 0%, #283593 50%, #f9a825 100%)', 'color' => '#ffffff', 'decor' => ['🦚','🪈','🌸','✨','💛','🌺','⭐','🎊']],
    'Krishna Janmashtami'   => ['emoji' => '🦚', 'bg' => 'linear-gradient(135deg, #1a237e 0%, #283593 50%, #f9a825 100%)', 'color' => '#ffffff', 'decor' => ['🦚','🪈','🌸','✨','💛','🌺','⭐','🎊']],
    'Raksha Bandhan'        => ['emoji' => '🧡', 'bg' => 'linear-gradient(135deg, #f953c6 0%, #b91d73 100%)', 'color' => '#ffffff', 'decor' => ['🧡','💕','✨','🌸','💛','🎊','💜','🌺']],
    'Makar Sankranti'       => ['emoji' => '🪁', 'bg' => 'linear-gradient(135deg, #f6d365 0%, #fda085 100%)', 'color' => '#4a1942', 'decor' => ['🪁','🌤️','✨','🌸','💛','🌼','🎊','⭐']],
    'Pongal'                => ['emoji' => '🍚', 'bg' => 'linear-gradient(135deg, #f6d365 0%, #84fab0 100%)', 'color' => '#4a1942', 'decor' => ['🍚','🌾','🌺','🐄','✨','🌸','💛','🌼']],
    'Onam'                  => ['emoji' => '🌺', 'bg' => 'linear-gradient(135deg, #11998e 0%, #38ef7d 100%)', 'color' => '#ffffff', 'decor' => ['🌺','🌸','🌼','🌿','✨','🎊','💚','🌻']],
    'Bihu'                  => ['emoji' => '🌾', 'bg' => 'linear-gradient(135deg, #84fab0 0%, #8fd3f4 100%)', 'color' => '#1a472a', 'decor' => ['🌾','💃','🌸','✨','🌿','🎊','💚','🌼']],
    'Vishu'                 => ['emoji' => '🌼', 'bg' => 'linear-gradient(135deg, #f6d365 0%, #84fab0 100%)', 'color' => '#4a1942', 'decor' => ['🌼','🌺','✨','🎊','💛','🌸','⭐','🌿']],
    'Baisakhi'              => ['emoji' => '🌾', 'bg' => 'linear-gradient(135deg, #f7971e 0%, #ffd200 100%)', 'color' => '#4a1942', 'decor' => ['🌾','💃','🥁','✨','💛','🌸','🎊','🌼']],
    'Lohri'                 => ['emoji' => '🔥', 'bg' => 'linear-gradient(135deg, #f7971e 0%, #f53803 100%)', 'color' => '#ffffff', 'decor' => ['🔥','🌾','💃','🥁','✨','💛','⭐','🎊']],
    'Chhath Puja'           => ['emoji' => '🌅', 'bg' => 'linear-gradient(135deg, #f7971e 0%, #ffd200 50%, #ff6b35 100%)', 'color' => '#4a1942', 'decor' => ['🌅','🌺','🍎','✨','💛','🌸','⭐','🎊']],
    'Karva Chauth'          => ['emoji' => '🌙', 'bg' => 'linear-gradient(135deg, #e91e63 0%, #9c27b0 100%)', 'color' => '#ffffff', 'decor' => ['🌙','⭐','💕','✨','🌸','💜','💛','🌺']],
    'Maha Shivratri'        => ['emoji' => '🔱', 'bg' => 'linear-gradient(135deg, #141e30 0%, #243b55 100%)', 'color' => '#ffffff', 'decor' => ['🔱','🌙','⭐','✨','💫','🌟','🌸','🕉️']],
    'Ram Navami'            => ['emoji' => '🏹', 'bg' => 'linear-gradient(135deg, #f7971e 0%, #ffd200 100%)', 'color' => '#4a1942', 'decor' => ['🏹','🌺','✨','💛','🌸','🎊','⭐','🌟']],
    'Hanuman Jayanti'       => ['emoji' => '🐒', 'bg' => 'linear-gradient(135deg, #f7971e 0%, #ff6b35 100%)', 'color' => '#ffffff', 'decor' => ['🐒','🌺','✨','💛','🌸','🎊','⭐','🏹']],
    'Guru Purnima'          => ['emoji' => '🙏', 'bg' => 'linear-gradient(135deg, #667eea 0%, #764ba2 100%)', 'color' => '#ffffff', 'decor' => ['🙏','🌕','✨','💫','🌟','⭐','🌸','💜']],
    'Basant Panchami'       => ['emoji' => '🌻', 'bg' => 'linear-gradient(135deg, #f6d365 0%, #fda085 100%)', 'color' => '#4a1942', 'decor' => ['🌻','🌸','✨','💛','🌼','🎊','⭐','🌺']],
    'Varalakshmi Vratam'    => ['emoji' => '🌺', 'bg' => 'linear-gradient(135deg, #e91e63 0%, #f7971e 100%)', 'color' => '#ffffff', 'decor' => ['🌺','🪔','✨','💛','🌸','🎊','⭐','🌼']],
    'Navaratri'             => ['emoji' => '🏮', 'bg' => 'linear-gradient(135deg, #e91e63 0%, #ff5722 50%, #ffc107 100%)', 'color' => '#ffffff', 'decor' => ['🏮','🌺','💃','✨','🌸','🎊','💛','🌼']],
    // ── Muslim Festivals ───────────────────────────────────────────────────
    'Eid'                   => ['emoji' => '🌙', 'bg' => 'linear-gradient(135deg, #0f2027 0%, #203a43 50%, #2c5364 100%)', 'color' => '#ffd700', 'decor' => ['🌙','⭐','🕌','✨','💫','🌟','🎊','🙏']],
    'Eid ul Fitr'           => ['emoji' => '🌙', 'bg' => 'linear-gradient(135deg, #0f2027 0%, #203a43 50%, #2c5364 100%)', 'color' => '#ffd700', 'decor' => ['🌙','⭐','🕌','✨','💫','🌟','🎊','🙏']],
    'Eid al Fitr'           => ['emoji' => '🌙', 'bg' => 'linear-gradient(135deg, #0f2027 0%, #203a43 50%, #2c5364 100%)', 'color' => '#ffd700', 'decor' => ['🌙','⭐','🕌','✨','💫','🌟','🎊','🙏']],
    'Eid ul Adha'           => ['emoji' => '🌙', 'bg' => 'linear-gradient(135deg, #1a3a2a 0%, #2d6a4f 50%, #ffd700 100%)', 'color' => '#ffffff', 'decor' => ['🌙','⭐','🕌','✨','💫','🌟','🎊','🙏']],
    'Eid al Adha'           => ['emoji' => '🌙', 'bg' => 'linear-gradient(135deg, #1a3a2a 0%, #2d6a4f 50%, #ffd700 100%)', 'color' => '#ffffff', 'decor' => ['🌙','⭐','🕌','✨','💫','🌟','🎊','🙏']],
    'Muharram'              => ['emoji' => '🌙', 'bg' => 'linear-gradient(135deg, #141e30 0%, #243b55 100%)', 'color' => '#ffd700', 'decor' => ['🌙','⭐','🕌','✨','💫','🌟','🙏','💜']],
    'Milad un Nabi'         => ['emoji' => '🌙', 'bg' => 'linear-gradient(135deg, #0f2027 0%, #2d6a4f 100%)', 'color' => '#ffd700', 'decor' => ['🌙','⭐','🕌','✨','💫','🌟','🎊','🌿']],
    // ── Christian Festivals ────────────────────────────────────────────────
    'Christmas'             => ['emoji' => '🎄', 'bg' => 'linear-gradient(135deg, #1a472a 0%, #2d6a4f 50%, #c0392b 100%)', 'color' => '#ffffff', 'decor' => ['🎄','⭐','🎅','🎁','❄️','🔔','✨','🦌']],
    'Good Friday'           => ['emoji' => '✝️', 'bg' => 'linear-gradient(135deg, #2c3e50 0%, #4a4a8a 100%)', 'color' => '#ffffff', 'decor' => ['✝️','🌹','🕊️','✨','💫','🙏','🌸','⭐']],
    'Easter'                => ['emoji' => '🐣', 'bg' => 'linear-gradient(135deg, #84fab0 0%, #8fd3f4 100%)', 'color' => '#1a472a', 'decor' => ['🐣','🌸','🐰','✨','🌼','💛','🎊','🌺']],
    // ── Sikh Festivals ─────────────────────────────────────────────────────
    'Guru Nanak Jayanti'    => ['emoji' => '🪯', 'bg' => 'linear-gradient(135deg, #f7971e 0%, #ffd200 100%)', 'color' => '#4a1942', 'decor' => ['🪯','⭐','✨','🌟','💛','🌸','🙏','💫']],
    'Guru Nanak'            => ['emoji' => '🪯', 'bg' => 'linear-gradient(135deg, #f7971e 0%, #ffd200 100%)', 'color' => '#4a1942', 'decor' => ['🪯','⭐','✨','🌟','💛','🌸','🙏','💫']],
    'Baisakhi'              => ['emoji' => '🌾', 'bg' => 'linear-gradient(135deg, #f7971e 0%, #ffd200 100%)', 'color' => '#4a1942', 'decor' => ['🌾','💃','🥁','✨','💛','🌸','🎊','🌼']],
    // ── Buddhist Festivals ─────────────────────────────────────────────────
    'Buddha Purnima'        => ['emoji' => '☸️', 'bg' => 'linear-gradient(135deg, #f6d365 0%, #84fab0 100%)', 'color' => '#4a1942', 'decor' => ['☸️','🌕','✨','🌸','💛','🌿','🙏','💫']],
    'Buddha Jayanti'        => ['emoji' => '☸️', 'bg' => 'linear-gradient(135deg, #f6d365 0%, #84fab0 100%)', 'color' => '#4a1942', 'decor' => ['☸️','🌕','✨','🌸','💛','🌿','🙏','💫']],
    // ── New Year Celebrations ──────────────────────────────────────────────
    'New Year'              => ['emoji' => '🎆', 'bg' => 'linear-gradient(135deg, #141e30 0%, #243b55 100%)', 'color' => '#ffd700', 'decor' => ['🎆','🎇','✨','🥂','🌟','💫','🎊','🎉']],
    'New Years'             => ['emoji' => '🎆', 'bg' => 'linear-gradient(135deg, #141e30 0%, #243b55 100%)', 'color' => '#ffd700', 'decor' => ['🎆','🎇','✨','🥂','🌟','💫','🎊','🎉']],
    'Tamil New Year'        => ['emoji' => '🌸', 'bg' => 'linear-gradient(135deg, #f6d365 0%, #84fab0 100%)', 'color' => '#4a1942', 'decor' => ['🌸','🌺','✨','💛','🌼','🎊','⭐','🌿']],
    'Telugu New Year'       => ['emoji' => '🌸', 'bg' => 'linear-gradient(135deg, #f6d365 0%, #fda085 50%, #84fab0 100%)', 'color' => '#4a1942', 'decor' => ['🌸','🌺','🥭','✨','💛','🌼','🎊','⭐']],
    'Kannada Rajyotsava'    => ['emoji' => '🌻', 'bg' => 'linear-gradient(135deg, #e53935 0%, #ffb300 100%)', 'color' => '#ffffff', 'decor' => ['🌻','⭐','✨','💛','🌟','🎊','🌸','💫']],
    // ── Other Celebrations ─────────────────────────────────────────────────
    'Ambedkar Jayanti'              => ['emoji' => '🔵', 'bg' => 'linear-gradient(135deg, #1565c0 0%, #0288d1 100%)', 'color' => '#ffffff', 'decor' => ['🔵','📚','✊','✨','⭐','🌟','💫','🙏']],
    'Labour Day'                    => ['emoji' => '✊', 'bg' => 'linear-gradient(135deg, #c62828 0%, #e53935 100%)', 'color' => '#ffffff', 'decor' => ['✊','⭐','✨','🌟','💪','🎊','💫','🔴']],
    'May Day'                       => ['emoji' => '✊', 'bg' => 'linear-gradient(135deg, #c62828 0%, #e53935 100%)', 'color' => '#ffffff', 'decor' => ['✊','⭐','✨','🌟','💪','🎊','💫','🔴']],
    'Maharshi Valmiki'              => ['emoji' => '📜', 'bg' => 'linear-gradient(135deg, #f7971e 0%, #ffd200 100%)', 'color' => '#4a1942', 'decor' => ['📜','🌺','✨','💛','🌸','🎊','⭐','🌟']],
    'Maharshi Valmiki Jayanti'      => ['emoji' => '📜', 'bg' => 'linear-gradient(135deg, #f7971e 0%, #ffd200 100%)', 'color' => '#4a1942', 'decor' => ['📜','🌺','✨','💛','🌸','🎊','⭐','🌟']],
    'Mahavir Jayanti'               => ['emoji' => '🕉️', 'bg' => 'linear-gradient(135deg, #f7971e 0%, #ffd200 100%)', 'color' => '#4a1942', 'decor' => ['🕉️','🌺','✨','💛','🌸','🙏','⭐','💫']],
    'Akshaya Tritiya'               => ['emoji' => '🌟', 'bg' => 'linear-gradient(135deg, #f7971e 0%, #ffd200 100%)', 'color' => '#4a1942', 'decor' => ['🌟','🪙','✨','💛','🌺','🎊','⭐','💫']],
    'Rath Yatra'                    => ['emoji' => '🛕', 'bg' => 'linear-gradient(135deg, #f7971e 0%, #ffd200 50%, #ff6b35 100%)', 'color' => '#4a1942', 'decor' => ['🛕','🌺','✨','💛','🌸','🎊','⭐','🙏']],
    'Holika Dahan'                  => ['emoji' => '🔥', 'bg' => 'linear-gradient(135deg, #f953c6 0%, #f7971e 35%, #84fab0 65%, #667eea 100%)', 'color' => '#ffffff', 'decor' => ['🔥','🎨','🌈','✨','🎊','🌸','💜','💛']],
    'Govardhan Puja'                => ['emoji' => '🐄', 'bg' => 'linear-gradient(135deg, #f7971e 0%, #ffd200 100%)', 'color' => '#4a1942', 'decor' => ['🐄','🌺','✨','💛','🌸','🎊','⭐','🪔']],
    'Bhai Dooj'                     => ['emoji' => '💕', 'bg' => 'linear-gradient(135deg, #f953c6 0%, #b91d73 100%)', 'color' => '#ffffff', 'decor' => ['💕','🧡','✨','🌸','💛','🎊','💜','🌺']],
    'Sheetala Ashtami'              => ['emoji' => '🌊', 'bg' => 'linear-gradient(135deg, #4facfe 0%, #00f2fe 100%)', 'color' => '#ffffff', 'decor' => ['🌊','🌸','✨','💛','🌼','🎊','⭐','💫']],
    'Thiruvalluvar Day'             => ['emoji' => '📖', 'bg' => 'linear-gradient(135deg, #f6d365 0%, #84fab0 100%)', 'color' => '#4a1942', 'decor' => ['📖','🌺','✨','💛','🌸','🎊','⭐','🌟']],
    'Chhatrapati Shivaji'           => ['emoji' => '⚔️', 'bg' => 'linear-gradient(135deg, #f7971e 0%, #ff6b35 100%)', 'color' => '#ffffff', 'decor' => ['⚔️','🌟','🎊','✨','🏹','🌺','💛','🎆']],
    'Kannada Rajyotsava'            => ['emoji' => '🌻', 'bg' => 'linear-gradient(135deg, #e53935 0%, #ffb300 100%)', 'color' => '#ffffff', 'decor' => ['🌻','⭐','✨','💛','🌟','🎊','🌸','💫']],
    'New Year Eve'                  => ['emoji' => '🎆', 'bg' => 'linear-gradient(135deg, #141e30 0%, #243b55 100%)', 'color' => '#ffd700', 'decor' => ['🎆','🎇','✨','🥂','🌟','💫','🎊','🎉']],
];

$advance_holiday  = null;
$advance_theme    = null;
$advance_is_today = false;

// Helper: match holiday name to theme
function matchHolidayTheme($name, $themes) {
    foreach ($themes as $key => $theme) {
        if (stripos($name, $key) !== false || stripos($key, $name) !== false) {
            return $theme;
        }
    }
    return ['emoji' => '🎉', 'bg' => 'linear-gradient(135deg, #f6d365 0%, #fda085 100%)', 'color' => '#4a1942', 'decor' => ['🎉','🎊','✨','🌟','💫','🎆','🎇','🎈']];
}

// Check TODAY first - check both company holidays AND wish_holidays
// wish_holidays = internet holidays for banner wishing only (no LOP impact)
$hol_today_row = null;
$hol_today_stmt = $conn->prepare("SELECT holiday_name FROM holidays WHERE holiday_date = ?");
$hol_today_stmt->bind_param("s", $today_date);
$hol_today_stmt->execute();
$hol_today_row = $hol_today_stmt->get_result()->fetch_assoc();
$hol_today_stmt->close();

if (!$hol_today_row) {
    // Also check wish_holidays table (internet holidays for banner only)
    $wish_check = $conn->query("SHOW TABLES LIKE 'wish_holidays'");
    if ($wish_check && $wish_check->num_rows > 0) {
        $wh_stmt = $conn->prepare("SELECT holiday_name FROM wish_holidays WHERE holiday_date = ?");
        $wh_stmt->bind_param("s", $today_date);
        $wh_stmt->execute();
        $hol_today_row = $wh_stmt->get_result()->fetch_assoc();
        $wh_stmt->close();
    }
}

// Today's holiday
if ($hol_today_row) {
    $advance_holiday  = $hol_today_row['holiday_name'];
    $advance_theme    = matchHolidayTheme($advance_holiday, $holiday_themes);
    $advance_is_today = true;
}

// Always also check TOMORROW — show advance banner regardless of whether today has a holiday
$hol_row_tomorrow = null;
$hol_check_tmrw = $conn->prepare("SELECT holiday_name FROM holidays WHERE holiday_date = ?");
$hol_check_tmrw->bind_param("s", $tomorrow);
$hol_check_tmrw->execute();
$hol_row_tomorrow = $hol_check_tmrw->get_result()->fetch_assoc();
$hol_check_tmrw->close();

if (!$hol_row_tomorrow) {
    $wish_check2 = $conn->query("SHOW TABLES LIKE 'wish_holidays'");
    if ($wish_check2 && $wish_check2->num_rows > 0) {
        $wh_stmt2 = $conn->prepare("SELECT holiday_name FROM wish_holidays WHERE holiday_date = ?");
        $wh_stmt2->bind_param("s", $tomorrow);
        $wh_stmt2->execute();
        $hol_row_tomorrow = $wh_stmt2->get_result()->fetch_assoc();
        $wh_stmt2->close();
    }
}

// If no today holiday but tomorrow has one — set advance
if (!$hol_today_row && $hol_row_tomorrow) {
    $advance_holiday  = $hol_row_tomorrow['holiday_name'];
    $advance_theme    = matchHolidayTheme($advance_holiday, $holiday_themes);
    $advance_is_today = false;
}

// Tomorrow holiday advance (separate variable for when today also has holiday)
$tomorrow_holiday      = $hol_row_tomorrow ? $hol_row_tomorrow['holiday_name'] : null;
$tomorrow_holiday_theme = $hol_row_tomorrow ? matchHolidayTheme($tomorrow_holiday, $holiday_themes) : null;
// ────────────────────────────────────────────────────────────────────────────

// Determine base path for assets
$base_path = '';
$current_file = $_SERVER['SCRIPT_NAME'];
if (strpos($current_file, '/admin/') !== false || 
    strpos($current_file, '/auth/') !== false || 
    strpos($current_file, '/hr/') !== false || 
    strpos($current_file, '/leaves/') !== false || 
    strpos($current_file, '/permissions/') !== false || 
    strpos($current_file, '/timesheet/') !== false || 
    strpos($current_file, '/users/') !== false) {
    $base_path = '../';
}

// Get current user details
$user_query = $conn->prepare("SELECT id, username, full_name, role, department, position, email, join_date, reporting_to FROM users WHERE id = ?");
$user_query->bind_param("i", $user_id);
$user_query->execute();
$current_user_details = $user_query->get_result()->fetch_assoc();
$user_query->close();

// Get reporting manager name
$reporting_manager = 'Not assigned';
if (!empty($current_user_details['reporting_to'])) {
    $manager_query = $conn->prepare("SELECT full_name FROM users WHERE username = ?");
    $manager_query->bind_param("s", $current_user_details['reporting_to']);
    $manager_query->execute();
    $manager_result = $manager_query->get_result();
    if ($manager_row = $manager_result->fetch_assoc()) {
        $reporting_manager = $manager_row['full_name'];
    }
    $manager_query->close();
}

// ── Casual leave: Mar 16 – Mar 15 cycle ───────────────────
$casual_year    = getCurrentCasualLeaveYear();
$current_window = getCurrentCasualWindow();

// ── Get LOP window (16th-15th) ────────────────────────────
$lop_window = getWindowForDate(date('Y-m-d'));

// ── Get Permission LOP window (16th-15th) ─────────────────
$permission_window = getWindowForDate(date('Y-m-d'));

// ── Check if it's reset period (March 16) ─────────────────
$is_reset_period = (date('m-d') == '03-16');
$is_after_reset = (date('m-d') >= '03-16');

// ── Full balance (includes accrual, carry-forward, countdowns) ──
$balance = getUserLeaveBalance($conn, $user_id);

// ── Casual balance shorthand ───────────────────────────────
$casual_balance          = $balance['casual_balance'];
$casual_available        = $casual_balance['remaining'];
$casual_total_entitled   = $casual_balance['total_entitled'];
$casual_accrued          = $casual_balance['accrued_to_date'];
$casual_used_cycle       = $casual_balance['used_cycle'];
$casual_used_this_window = $casual_balance['used_this_window'];

// ── Countdown values ──────────────────────────────────────
$days_until_monthly_reset = $balance['days_until_monthly_reset'];
$days_until_yearly_reset  = $balance['days_until_yearly_reset'];

// ── Sick year countdown (Mar 16 reset) ────────────────────
$today           = new DateTime();
$sick_reset_date = new DateTime($casual_year['end_date']);
$sick_reset_date->modify('+1 day');
$days_until_sick_reset = $today->diff($sick_reset_date)->days;

// ── Next Mar 16 reset date label ──────────────────────────
$year_now  = (int)$today->format('Y');
$month_now = (int)$today->format('n');
$day_now   = (int)$today->format('j');
if ($month_now < 3 || ($month_now == 3 && $day_now <= 16)) {
    $next_mar16 = new DateTime("{$year_now}-03-16");
} else {
    $next_mar16 = new DateTime(($year_now + 1) . "-03-16");
}
$next_mar16_label = date('d M Y', $next_mar16->getTimestamp());

// ── LOP (Loss of Pay from leaves) ─────────────────────────
$lop_total          = getLOPCount($conn, $user_id);
$lop_this_window    = getLOPDaysUsedInWindow($conn, $user_id, $lop_window['window_start'], $lop_window['window_end']);
$lop_previous_window = 0;

// ── Get LOP leaves for display ────────────────────────────
function getLOPLeaves($conn, $user_id, $limit = 10) {
    $leaves = [];
    $stmt = $conn->prepare("
        SELECT * FROM leaves 
        WHERE user_id = ? AND leave_type = 'LOP' AND status = 'Approved'
        ORDER BY from_date DESC 
        LIMIT ?
    ");
    if ($stmt) {
        $stmt->bind_param("ii", $user_id, $limit);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $leaves[] = $row;
        }
        $stmt->close();
    }
    return $leaves;
}

// Get LOP leaves data
$lop_leaves = getLOPLeaves($conn, $user_id, 10);

// ── Permission LOP in hours ───────────────────────────────
function calculatePermissionLOPHours($conn, $user_id, $period = 'total') {
    $hours = 0;
    $column_check = $conn->query("SHOW COLUMNS FROM permissions LIKE 'lop_hours'");
    $has_lop_column = $column_check && $column_check->num_rows > 0;
    
    $query = "SELECT * FROM permissions 
              WHERE user_id = ? AND status = 'Approved'";
    
    switch($period) {
        case 'window':
            $window = getWindowForDate(date('Y-m-d'));
            $query .= " AND permission_date BETWEEN '{$window['window_start']}' AND '{$window['window_end']}'";
            break;
        case 'prev_window':
            $window = getWindowForDate(date('Y-m-d'));
            $prev_start = date('Y-m-d', strtotime($window['window_start'] . ' -1 month'));
            $prev_end = date('Y-m-d', strtotime($window['window_end'] . ' -1 month'));
            $query .= " AND permission_date BETWEEN '{$prev_start}' AND '{$prev_end}'";
            break;
        case 'year':
            $casual_year = getCurrentCasualLeaveYear();
            $query .= " AND permission_date BETWEEN '{$casual_year['start_date']}' AND '{$casual_year['end_date']}'";
            break;
    }
    
    $stmt = $conn->prepare($query);
    if ($stmt) {
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $result = $stmt->get_result();
        
        while ($row = $result->fetch_assoc()) {
            $reason = $row['reason'] ?? '';
            $duration = floatval($row['duration'] ?? 0);
            $lop_hours_col = floatval($row['lop_hours'] ?? 0);
            
            $reason_lower = strtolower($reason);
            $has_lop_indicator = (strpos($reason_lower, 'lop') !== false) || 
                                 (strpos($reason_lower, 'loss of pay') !== false) ||
                                 (strpos($reason_lower, 'excess') !== false);
            
            if ($has_lop_indicator) {
                if ($has_lop_column && $lop_hours_col > 0) {
                    $hours += $lop_hours_col;
                } else {
                    $hours += $duration;
                }
            }
        }
        $stmt->close();
    }
    
    return $hours;
}

function getDetailedLOPPermissions($conn, $user_id, $limit = 10) {
    $permissions = [];
    $column_check = $conn->query("SHOW COLUMNS FROM permissions LIKE 'lop_hours'");
    $has_lop_column = $column_check && $column_check->num_rows > 0;
    
    $query = "SELECT * FROM permissions 
              WHERE user_id = ? AND status = 'Approved'
              ORDER BY permission_date DESC 
              LIMIT ?";
    
    $stmt = $conn->prepare($query);
    if ($stmt) {
        $stmt->bind_param("ii", $user_id, $limit);
        $stmt->execute();
        $result = $stmt->get_result();
        
        while ($row = $result->fetch_assoc()) {
            $permission = $row;
            $reason = $row['reason'] ?? '';
            $duration = floatval($row['duration'] ?? 0);
            $lop_hours_col = floatval($row['lop_hours'] ?? 0);
            $reason_lower = strtolower($reason);
            
            $has_lop_indicator = (strpos($reason_lower, 'lop') !== false) || 
                                 (strpos($reason_lower, 'loss of pay') !== false) ||
                                 (strpos($reason_lower, 'excess') !== false);
            
            if ($has_lop_indicator) {
                if ($has_lop_column && $lop_hours_col > 0) {
                    $permission['lop_hours'] = $lop_hours_col;
                } else {
                    $permission['lop_hours'] = $duration;
                }
                $permission['is_lop'] = true;
                $permissions[] = $permission;
            }
        }
        $stmt->close();
    }
    
    return $permissions;
}

$permission_lop_total_hours = calculatePermissionLOPHours($conn, $user_id, 'year');
$permission_lop_window_hours = calculatePermissionLOPHours($conn, $user_id, 'window');
$permission_lop_prev_window_hours = calculatePermissionLOPHours($conn, $user_id, 'prev_window');

// ── Regular permission counts ─────────────────────────────
$permission_total = 0;
$permission_window = 0;
$permission_prev_window = 0;

$perm_query = "SELECT COUNT(*) as total FROM permissions WHERE user_id = ? AND status = 'Approved'";
$stmt = $conn->prepare($perm_query);
if ($stmt) {
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $permission_total = $result->fetch_assoc()['total'] ?? 0;
    $stmt->close();
}

$window = getWindowForDate(date('Y-m-d'));
$perm_window_query = "SELECT COUNT(*) as total FROM permissions WHERE user_id = ? AND status = 'Approved' AND permission_date BETWEEN ? AND ?";
$stmt = $conn->prepare($perm_window_query);
if ($stmt) {
    $stmt->bind_param("iss", $user_id, $window['window_start'], $window['window_end']);
    $stmt->execute();
    $result = $stmt->get_result();
    $permission_window = $result->fetch_assoc()['total'] ?? 0;
    $stmt->close();
}

$prev_window_start = date('Y-m-d', strtotime($window['window_start'] . ' -1 month'));
$prev_window_end = date('Y-m-d', strtotime($window['window_end'] . ' -1 month'));
$perm_prev_query = "SELECT COUNT(*) as total FROM permissions WHERE user_id = ? AND status = 'Approved' AND permission_date BETWEEN ? AND ?";
$stmt = $conn->prepare($perm_prev_query);
if ($stmt) {
    $stmt->bind_param("iss", $user_id, $prev_window_start, $prev_window_end);
    $stmt->execute();
    $result = $stmt->get_result();
    $permission_prev_window = $result->fetch_assoc()['total'] ?? 0;
    $stmt->close();
}

// ── Pending count ─────────────────────────────────────────
$pending = 0;
$stmt = $conn->prepare("
    SELECT
        (SELECT COUNT(*) FROM leaves WHERE user_id = ? AND status = 'Pending') AS pending_leaves,
        (SELECT COUNT(*) FROM permissions WHERE user_id = ? AND status = 'Pending') AS pending_permissions
");
if ($stmt) {
    $stmt->bind_param("ii", $user_id, $user_id);
    $stmt->execute();
    $row     = $stmt->get_result()->fetch_assoc();
    $pending = ($row['pending_leaves'] ?? 0) + ($row['pending_permissions'] ?? 0);
    $stmt->close();
}

// ── Recent leaves ─────────────────────────────────────────
$recent_leaves = [];
$recent_stmt   = $conn->prepare("SELECT * FROM leaves WHERE user_id = ? ORDER BY applied_date DESC LIMIT 10");
if ($recent_stmt) {
    $recent_stmt->bind_param("i", $user_id);
    $recent_stmt->execute();
    $recent_leaves = $recent_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $recent_stmt->close();
}

// ── Recent approved LOP permissions ───────────────────────
$lop_permissions = getDetailedLOPPermissions($conn, $user_id, 10);

// ── Progress bar helpers ───────────────────────────────────
$casual_progress = $casual_total_entitled > 0 ? round(($casual_used_cycle / $casual_total_entitled) * 100) : 0;
$sick_progress   = ($balance['sick_entitlement'] ?? 6) > 0
    ? round((($balance['used']['Sick'] ?? 0) / ($balance['sick_entitlement'] ?? 6)) * 100) : 0;

// Format window display strings
$current_window_display = date('M j', strtotime($current_window['window_start'])) . ' - ' . date('M j', strtotime($current_window['window_end']));

// On March 16, show 0 for yearly totals (new cycle starts)
if ($is_reset_period) {
    $lop_total = 0;
    $permission_lop_total_hours = 0;
}

// Ensure permission_window and lop_window are properly defined arrays
if (!is_array($permission_window)) {
    $permission_window = [
        'window_start' => date('Y-m-16'),
        'window_end' => date('Y-m-15', strtotime('+1 month'))
    ];
}

if (!is_array($lop_window)) {
    $lop_window = [
        'window_start' => date('Y-m-16'),
        'window_end' => date('Y-m-15', strtotime('+1 month'))
    ];
}

$page_title = "Dashboard - MAKSIM HR";
?>
<!DOCTYPE html>
<html lang="en"> 
<head>
    <?php include 'includes/head.php'; ?>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; font-family: 'Segoe UI', sans-serif; }
        body { background: linear-gradient(135deg,  #006400 0%, #2c9218 100%); min-height: 100vh; padding: 20px; position: relative; }

        .app-header {
            background: linear-gradient(135deg,  #006400 0%,#2c9218 100%);
            color: white; padding: 20px 30px;
            display: flex; justify-content: space-between; align-items: center;
            border-radius: 15px 15px 0 0;
        }
        .user-info   { display: flex; align-items: center; gap: 15px; }
        .user-label  { background: rgba(255,255,255,0.2); padding: 8px 15px; border-radius: 20px; display: flex; align-items: center; gap: 8px; }
        .logout-btn  { background: rgba(255,255,255,0.2); color: white; padding: 8px 15px; border-radius: 20px; text-decoration: none; }
        
        .role-badge {
            background: #e8f5e9;
            color: #006400;
            padding: 12px 30px;
            border-radius: 50px;
            font-size: 18px;
            font-weight: 600;
            cursor: pointer;
            transition: transform 0.2s, box-shadow 0.2s;
        }
        .role-badge:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0,100,0,0.2);
        }
        .role-hr { background: #4299e1; color: white; }
        .role-dm { background: #48bb78; color: white; }
        .role-admin { background: #ed8936; color: white; }
        .role-employee { background: #a0aec0; color: white; }
        .role-manager { background: #9f7aea; color: white; }
        .role-coo { background: #9b59b6; color: white; }
        .role-ed { background: #e74c3c; color: white; }
        
        .role-badge-small {
            padding: 4px 10px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 600;
            display: inline-block;
        }
        
        .user-modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0,0,0,0.5);
            z-index: 10000;
            justify-content: center;
            align-items: center;
        }
        
        .user-modal-content {
            background-color: white;
            border-radius: 15px;
            width: 90%;
            max-width: 600px;
            max-height: 80vh;
            overflow-y: auto;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
            animation: flyThrough 0.6s cubic-bezier(0.34, 1.56, 0.64, 1) forwards;
            transform-origin: center;
        }
        
        @keyframes flyThrough {
            0% {
                opacity: 0;
                transform: scale(0.3) translateY(-200px) rotate(-15deg);
            }
            40% {
                opacity: 1;
                transform: scale(1.1) translateY(20px) rotate(3deg);
            }
            70% {
                transform: scale(0.95) translateY(-5px) rotate(-1deg);
            }
            100% {
                opacity: 1;
                transform: scale(1) translateY(0) rotate(0);
            }
        }
        
        .user-modal-header {
            background: linear-gradient(135deg, #006400 0%, #2c9218 100%);
            color: white;
            padding: 20px 30px;
            border-radius: 15px 15px 0 0;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .user-modal-header h3 {
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 20px;
        }
        
        .close-modal {
            background: rgba(255,255,255,0.2);
            border: none;
            color: white;
            font-size: 24px;
            cursor: pointer;
            width: 40px;
            height: 40px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: background 0.2s;
        }
        
        .close-modal:hover {
            background: rgba(255,255,255,0.3);
        }
        
        .user-modal-body {
            padding: 30px;
        }
        
        .user-detail-card {
            background: #f8fafc;
            border-radius: 12px;
            padding: 25px;
            margin-bottom: 20px;
            border: 1px solid #e2e8f0;
        }
        
        .user-avatar {
            width: 80px;
            height: 80px;
            background: linear-gradient(135deg, #006400 0%, #2c9218 100%);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 20px;
            color: white;
            font-size: 36px;
            font-weight: bold;
        }
        
        .user-name-large {
            text-align: center;
            font-size: 24px;
            font-weight: 700;
            color: #2d3748;
            margin-bottom: 5px;
        }
        
        .user-role-large {
            text-align: center;
            margin-bottom: 25px;
        }
        
        .info-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
        }
        
        .info-item {
            background: white;
            padding: 15px;
            border-radius: 10px;
            box-shadow: 0 2px 5px rgba(0,0,0,0.05);
        }
        
        .info-label {
            font-size: 11px;
            color: #718096;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 5px;
        }
        
        .info-value {
            font-size: 16px;
            font-weight: 600;
            color: #2d3748;
        }
        
        .full-width {
            grid-column: span 2;
        }

        .welcome-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            display: flex;
            z-index: 9999;
            animation: welcomeFlyThrough 3.5s ease-in-out forwards;
            pointer-events: none;
            perspective: 1200px;
        }

        .welcome-left {
            flex: 1;
            background: linear-gradient(135deg, #1a472a 0%, #2c9218 100%);
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
            color: white;
            padding: 40px;
            animation: leftFlyThrough 1.2s cubic-bezier(0.34, 1.56, 0.64, 1) forwards;
            transform-origin: left center;
            box-shadow: 20px 0 30px rgba(0,0,0,0.2);
            position: relative;
            overflow: hidden;
        }

        .welcome-left::before {
            content: '';
            position: absolute;
            top: -50%;
            left: -50%;
            width: 200%;
            height: 200%;
            background: repeating-linear-gradient(
                45deg,
                transparent,
                transparent 20px,
                rgba(255,255,255,0.05) 20px,
                rgba(255,255,255,0.05) 40px
            );
            animation: backgroundShift 10s linear infinite;
        }

        .welcome-left .logo-container {
            width: 180px;
            height: 180px;
            background: rgba(255,255,255,0.15);
            border-radius: 50%;
            display: flex;
            justify-content: center;
            align-items: center;
            margin-bottom: 30px;
            animation: float 3s ease-in-out infinite, glowPulse 2s ease-in-out infinite;
            border: 3px solid rgba(255,255,255,0.3);
            box-shadow: 0 10px 30px rgba(0,0,0,0.3);
        }

        .welcome-left .logo-container img {
            width: 120px;
            height: 120px;
            filter: brightness(0) invert(1);
            animation: rotateLogo 20s linear infinite;
        }

        .welcome-left h1 {
            font-size: 56px;
            font-weight: 800;
            margin-bottom: 15px;
            animation: slideUp 0.8s ease 0.3s both;
            text-shadow: 2px 2px 4px rgba(0,0,0,0.3);
            letter-spacing: 4px;
        }

        .welcome-left .tagline {
            font-size: 22px;
            opacity: 0.95;
            font-style: italic;
            animation: slideUp 0.8s ease 0.5s both;
            text-shadow: 1px 1px 2px rgba(0,0,0,0.3);
            border-top: 1px solid rgba(255,255,255,0.3);
            padding-top: 20px;
            margin-top: 20px;
        }

        .welcome-right {
            flex: 1;
            background: white;
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
            padding: 40px;
            animation: rightFlyThrough 1.2s cubic-bezier(0.34, 1.56, 0.64, 1) 0.2s both;
            transform-origin: right center;
            position: relative;
            overflow: hidden;
            box-shadow: -20px 0 30px rgba(0,0,0,0.1);
        }

        .welcome-right::before {
            content: '';
            position: absolute;
            width: 150%;
            height: 150%;
            background: radial-gradient(circle at 30% 50%, rgba(0,100,0,0.08) 0%, transparent 50%);
            animation: rotateBackground 30s linear infinite;
        }

        .welcome-right::after {
            content: '';
            position: absolute;
            width: 100%;
            height: 100%;
            background: radial-gradient(circle at 70% 30%, rgba(44,146,24,0.05) 0%, transparent 50%);
            animation: pulseBackground 4s ease-in-out infinite;
        }

        .welcome-right .greeting {
            font-size: 28px;
            color: #4a5568;
            margin-bottom: 20px;
            z-index: 1;
            animation: flyIn 0.8s ease 0.7s both;
            font-weight: 300;
            letter-spacing: 2px;
        }

        .welcome-right .user-name {
            font-size: 64px;
            font-weight: 800;
            color: #006400;
            margin-bottom: 20px;
            text-align: center;
            z-index: 1;
            animation: flyPop 1s cubic-bezier(0.68, -0.55, 0.265, 1.55) 0.9s both;
            text-shadow: 3px 3px 0 rgba(0,100,0,0.1);
            line-height: 1.2;
        }

        .welcome-right .role-badge {
            background: linear-gradient(135deg, #006400 0%, #2c9218 100%);
            color: white;
            padding: 15px 40px;
            border-radius: 50px;
            font-size: 20px;
            font-weight: 700;
            z-index: 1;
            animation: flyScale 0.8s ease 1.1s both;
            cursor: pointer;
            transition: all 0.3s ease;
            border: 2px solid rgba(255,255,255,0.3);
            box-shadow: 0 5px 15px rgba(0,100,0,0.3);
            text-transform: uppercase;
            letter-spacing: 2px;
        }
        
        .welcome-right .role-badge:hover {
            transform: translateY(-3px) scale(1.05) !important;
            box-shadow: 0 8px 25px rgba(0,100,0,0.4);
            background: linear-gradient(135deg, #2c9218 0%, #006400 100%);
        }

        .welcome-right .quote {
            margin-top: 50px;
            font-size: 18px;
            color: #718096;
            font-style: italic;
            max-width: 450px;
            text-align: center;
            z-index: 1;
            animation: flyFade 1.2s ease 1.4s both;
            border-left: 3px solid #006400;
            padding-left: 20px;
        }

        @keyframes welcomeFlyThrough {
            0% { opacity: 1; visibility: visible; }
            85% { opacity: 1; visibility: visible; }
            100% { opacity: 0; visibility: hidden; }
        }

        @keyframes leftFlyThrough {
            0% {
                opacity: 0;
                transform: scale(0.3) translateX(-200px) rotate(-15deg);
            }
            40% {
                opacity: 1;
                transform: scale(1.1) translateX(20px) rotate(3deg);
            }
            70% {
                transform: scale(0.95) translateX(-5px) rotate(-1deg);
            }
            100% {
                opacity: 1;
                transform: scale(1) translateX(0) rotate(0);
            }
        }

        @keyframes rightFlyThrough {
            0% {
                opacity: 0;
                transform: scale(0.3) translateX(200px) rotate(15deg);
            }
            40% {
                opacity: 1;
                transform: scale(1.1) translateX(-20px) rotate(-3deg);
            }
            70% {
                transform: scale(0.95) translateX(5px) rotate(1deg);
            }
            100% {
                opacity: 1;
                transform: scale(1) translateX(0) rotate(0);
            }
        }

        @keyframes flyIn {
            0% {
                opacity: 0;
                transform: scale(0.5) translateY(-100px) rotate(-10deg);
            }
            50% {
                opacity: 0.8;
                transform: scale(1.1) translateY(10px) rotate(5deg);
            }
            100% {
                opacity: 1;
                transform: scale(1) translateY(0) rotate(0);
            }
        }

        @keyframes flyPop {
            0% {
                opacity: 0;
                transform: scale(0.2) translateY(100px) rotate(20deg);
            }
            50% {
                opacity: 0.9;
                transform: scale(1.2) translateY(-10px) rotate(-5deg);
            }
            100% {
                opacity: 1;
                transform: scale(1) translateY(0) rotate(0);
            }
        }

        @keyframes flyScale {
            0% {
                opacity: 0;
                transform: scale(0) rotate(-180deg);
            }
            60% {
                opacity: 1;
                transform: scale(1.2) rotate(10deg);
            }
            80% {
                transform: scale(0.95) rotate(-5deg);
            }
            100% {
                opacity: 1;
                transform: scale(1) rotate(0);
            }
        }

        @keyframes flyFade {
            0% {
                opacity: 0;
                transform: translateY(50px);
            }
            50% {
                opacity: 0.5;
                transform: translateY(-10px);
            }
            100% {
                opacity: 1;
                transform: translateY(0);
            }
        }

        @keyframes slideUp {
            from {
                transform: translateY(50px);
                opacity: 0;
            }
            to {
                transform: translateY(0);
                opacity: 1;
            }
        }

        @keyframes float {
            0% { transform: translateY(0px); }
            50% { transform: translateY(-15px); }
            100% { transform: translateY(0px); }
        }

        @keyframes glowPulse {
            0% { box-shadow: 0 0 20px rgba(255,255,255,0.3); }
            50% { box-shadow: 0 0 40px rgba(255,255,255,0.6); }
            100% { box-shadow: 0 0 20px rgba(255,255,255,0.3); }
        }

        @keyframes rotateLogo {
            from { transform: rotate(0deg); }
            to { transform: rotate(360deg); }
        }

        @keyframes rotateBackground {
            from { transform: rotate(0deg); }
            to { transform: rotate(360deg); }
        }

        @keyframes pulseBackground {
            0% { opacity: 0.3; }
            50% { opacity: 0.7; }
            100% { opacity: 0.3; }
        }

        @keyframes backgroundShift {
            from { transform: translate(0, 0); }
            to { transform: translate(50%, 50%); }
        }

        .floating-container {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            pointer-events: none;
            z-index: 1;
            overflow: hidden;
        }

        .floating-item {
            position: absolute;
            user-select: none;
            pointer-events: none;
            animation: floatUp var(--duration) linear forwards;
            transform-origin: center;
            --duration: 12s;
            --delay: 0s;
            --start-left: 0%;
            --rotation: 0deg;
            left: var(--start-left);
            animation-delay: var(--delay);
            opacity: 0.25;
            filter: drop-shadow(0 0 5px currentColor);
            z-index: 1;
            text-shadow: 0 0 8px currentColor;
        }

        .svg-balloon {
            display: inline-block;
            width: 40px;
            height: 50px;
            filter: drop-shadow(0 0 3px currentColor);
        }
        
        .svg-balloon svg {
            width: 100%;
            height: 100%;
            fill: currentColor;
        }

        .floating-item.red { color: rgba(255, 70, 70, 0.35); }
        .floating-item.blue { color: rgba(70, 130, 255, 0.35); }
        .floating-item.green { color: rgba(70, 200, 70, 0.35); }
        .floating-item.yellow { color: rgba(255, 215, 70, 0.4); }
        .floating-item.purple { color: rgba(160, 70, 255, 0.35); }
        .floating-item.pink { color: rgba(255, 105, 180, 0.35); }
        .floating-item.orange { color: rgba(255, 140, 70, 0.4); }
        .floating-item.cyan { color: rgba(70, 210, 210, 0.35); }
        .floating-item.gold { color: rgba(255, 215, 0, 0.4); }
        .floating-item.silver { color: rgba(192, 192, 192, 0.3); }

        @keyframes floatUp {
            0% {
                transform: translateY(100vh) rotate(0deg) scale(0.6);
                opacity: 0;
            }
            10% {
                opacity: 0.3;
                transform: translateY(80vh) rotate(var(--rotation)) scale(0.9);
            }
            50% {
                opacity: 0.35;
                transform: translateY(50vh) rotate(calc(var(--rotation) * 0.5)) scale(1);
            }
            90% {
                opacity: 0.25;
                transform: translateY(10vh) rotate(calc(var(--rotation) * -0.5)) scale(1.1);
            }
            100% {
                transform: translateY(-100px) rotate(calc(var(--rotation) * -1)) scale(1.2);
                opacity: 0;
            }
        }

        .birthday-banner {
            background: linear-gradient(135deg, #0077ff, #26beec, #76ddf7);
            color: white;
            padding: 20px 30px;
            border-radius: 15px;
            margin-bottom: 25px;
            box-shadow: 0 10px 30px rgba(255, 107, 107, 0.4);
            border: 2px solid rgba(255,255,255,0.3);
            display: flex;
            align-items: center;
            gap: 20px;
            position: relative;
            overflow: hidden;
            z-index: 3;
        }

        .birthday-banner::before {
            content: '🎂 🎈 🎉 🎊 🎂 🎈 🎉 🎊';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            font-size: 40px;
            opacity: 0.1;
            display: flex;
            align-items: center;
            justify-content: center;
            white-space: nowrap;
            animation: floatPattern 20s linear infinite;
            pointer-events: none;
        }

        .cake-icon {
            font-size: 60px;
            animation: bounce 1s infinite;
            filter: drop-shadow(0 10px 15px rgba(0, 0, 0, 0.2));
            z-index: 2;
        }

        .birthday-message {
            flex: 1;
            z-index: 2;
        }

        .birthday-message h3 {
            font-size: 28px;
            margin-bottom: 10px;
            font-weight: 700;
            text-shadow: 2px 2px 4px rgba(0,0,0,0.2);
        }
        .rainbow-text span { display: inline-block; }
        .rainbow-text span:nth-child(7n+1) { color: #ff4757; }
        .rainbow-text span:nth-child(7n+2) { color: #ff9f43; }
        .rainbow-text span:nth-child(7n+3) { color: #ffd32a; }
        .rainbow-text span:nth-child(7n+4) { color: #0be881; }
        .rainbow-text span:nth-child(7n+5) { color: #18dcff; }
        .rainbow-text span:nth-child(7n+6) { color: #7d5fff; }
        .rainbow-text span:nth-child(7n+7) { color: #ff3f8b; }
        .rainbow-text span { text-shadow: 1px 1px 3px rgba(0,0,0,0.3); font-weight: 800; animation: rainbowPulse 2s ease-in-out infinite alternate; }
        @keyframes rainbowPulse { from { filter: brightness(1); } to { filter: brightness(1.3) drop-shadow(0 0 6px currentColor); } }
        .advance-bday-banner {
            background: linear-gradient(135deg, #1a1a2e 0%, #16213e 40%, #0f3460 100%);
            border: 2px solid transparent;
            background-clip: padding-box;
            box-shadow: 0 0 0 2px #ff6b6b, 0 0 0 4px #ff9f43, 0 0 0 6px #ffd32a, 0 0 20px rgba(255,107,107,0.4);
            color: #fff;
            padding: 20px 30px;
            border-radius: 15px;
            margin-bottom: 25px;
            display: flex;
            align-items: center;
            gap: 20px;
            position: relative;
            overflow: hidden;
        }
        .advance-bday-banner .decor-strip {
            position: absolute; top: 0; left: 0; width: 200%; height: 100%;
            font-size: 28px; opacity: 0.08; white-space: nowrap;
            animation: floatPattern 18s linear infinite; pointer-events: none;
        }
        .advance-bday-person {
            background: rgba(255,255,255,0.08);
            border: 1px solid rgba(255,255,255,0.2);
            border-radius: 12px;
            padding: 10px 18px;
            margin-bottom: 8px;
        }
        .advance-bday-person h4 {
            font-size: 20px;
            font-weight: 800;
            margin: 0 0 4px 0;
        }
        .advance-bday-person p {
            margin: 0;
            font-size: 14px;
            opacity: 0.85;
        }

        .birthday-names {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            margin-top: 10px;
        }

        .birthday-name-tag {
            background: rgba(255,255,255,0.2);
            padding: 8px 18px;
            border-radius: 30px;
            font-size: 16px;
            font-weight: 600;
            backdrop-filter: blur(5px);
            border: 1px solid rgba(255,255,255,0.3);
            box-shadow: 0 4px 10px rgba(0,0,0,0.1);
        }

        @keyframes bounce {
            0%, 100% { transform: translateY(0); }
            50% { transform: translateY(-15px); }
        }

        @keyframes floatPattern {
            0% { transform: translateX(-100%) rotate(-5deg); }
            100% { transform: translateX(100%) rotate(5deg); }
        }

        /* ── Holiday Advance Banner ───────────────────────── */
        .holiday-advance-banner {
            padding: 20px 30px;
            border-radius: 15px;
            margin-bottom: 25px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.25);
            border: 2px solid rgba(255,255,255,0.4);
            display: flex;
            align-items: center;
            gap: 20px;
            position: relative;
            overflow: hidden;
            z-index: 3;
        }
        .holiday-advance-banner::before {
            content: '';
            position: absolute;
            top: -50%; left: -50%;
            width: 200%; height: 200%;
            background: radial-gradient(circle, rgba(255,255,255,0.08) 0%, transparent 60%);
            animation: holidayPulse 4s ease-in-out infinite;
            pointer-events: none;
        }
        .holiday-decor-strip {
            position: absolute;
            top: 0; left: 0;
            width: 100%; height: 100%;
            font-size: 32px;
            opacity: 0.12;
            display: flex;
            align-items: center;
            justify-content: center;
            white-space: nowrap;
            letter-spacing: 10px;
            animation: floatPattern 25s linear infinite;
            pointer-events: none;
        }
        .holiday-emoji-big {
            font-size: 64px;
            animation: bounce 1.5s ease-in-out infinite;
            z-index: 2;
            filter: drop-shadow(0 8px 12px rgba(0,0,0,0.2));
        }
        .holiday-advance-msg {
            flex: 1;
            z-index: 2;
        }
        .holiday-advance-msg h3 {
            font-size: 26px;
            font-weight: 800;
            margin-bottom: 6px;
            text-shadow: 1px 2px 6px rgba(0,0,0,0.18);
            letter-spacing: 0.5px;
        }
        .holiday-advance-msg p {
            font-size: 15px;
            opacity: 0.9;
            margin: 0;
        }
        .holiday-tag {
            background: rgba(255,255,255,0.25);
            padding: 4px 14px;
            border-radius: 20px;
            font-size: 13px;
            font-weight: 700;
            backdrop-filter: blur(6px);
            border: 1px solid rgba(255,255,255,0.35);
            display: inline-block;
            margin-top: 8px;
        }
        @keyframes holidayPulse {
            0%, 100% { transform: scale(1); opacity: 0.08; }
            50% { transform: scale(1.05); opacity: 0.15; }
        }
        /* ────────────────────────────────────────────────── */

                /* ── Holiday Advance Banner ───────────────────────── */
        .holiday-advance-banner {
            padding: 20px 30px;
            border-radius: 15px;
            margin-bottom: 25px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.25);
            border: 2px solid rgba(255,255,255,0.4);
            display: flex;
            align-items: center;
            gap: 20px;
            position: relative;
            overflow: hidden;
            z-index: 3;
        }
        .holiday-decor-strip {
            position: absolute;
            top: 0; left: 0;
            width: 100%; height: 100%;
            font-size: 32px;
            opacity: 0.12;
            display: flex;
            align-items: center;
            justify-content: center;
            white-space: nowrap;
            letter-spacing: 10px;
            animation: floatPattern 25s linear infinite;
            pointer-events: none;
        }
        .holiday-emoji-big {
            font-size: 64px;
            animation: bounce 1.5s ease-in-out infinite;
            z-index: 2;
            filter: drop-shadow(0 8px 12px rgba(0,0,0,0.2));
        }
        .holiday-advance-msg { flex: 1; z-index: 2; }
        .holiday-advance-msg h3 {
            font-size: 26px; font-weight: 800; margin-bottom: 6px;
            text-shadow: 1px 2px 6px rgba(0,0,0,0.18);
        }
        .holiday-advance-msg p { font-size: 15px; opacity: 0.9; margin: 0; }
        .holiday-tag {
            background: rgba(255,255,255,0.25); padding: 4px 14px;
            border-radius: 20px; font-size: 13px; font-weight: 700;
            display: inline-block; margin-top: 8px;
            border: 1px solid rgba(255,255,255,0.35);
        }
        @keyframes holidayPulse {
            0%, 100% { opacity: 0.08; } 50% { opacity: 0.16; }
        }
        /* ────────────────────────────────────────────────── */

        .cycle-banner {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white; padding: 18px 24px; border-radius: 12px; margin-bottom: 16px;
            display: flex; flex-wrap: wrap; gap: 20px; align-items: center; justify-content: space-between;
            position: relative;
            z-index: 3;
        }
        .cycle-banner .section { display: flex; flex-direction: column; gap: 4px; }
        .cycle-banner .label   { font-size: 11px; opacity: .75; text-transform: uppercase; letter-spacing: .5px; }
        .cycle-banner .value   { font-size: 16px; font-weight: 700; }
        .cycle-banner .divider { width: 1px; height: 40px; background: rgba(255,255,255,.3); }

        .countdown-row { display: flex; gap: 12px; flex-wrap: wrap; margin-bottom: 20px; position: relative; z-index: 3; }
        .countdown-pill {
            display: flex; align-items: center; gap: 10px;
            background: white; border-radius: 10px; padding: 12px 18px;
            box-shadow: 0 2px 8px rgba(0,0,0,.08); flex: 1; min-width: 200px;
        }
        .countdown-pill .pill-icon { width: 40px; height: 40px; border-radius: 10px; display: flex; align-items: center; justify-content: center; font-size: 18px; }
        .countdown-pill.monthly .pill-icon  { background: #ebf8ff; color: #3182ce; }
        .countdown-pill.yearly  .pill-icon  { background: #faf5ff; color: #805ad5; }
        .countdown-pill .pill-days  { font-size: 26px; font-weight: 700; color: #2d3748; }
        .countdown-pill .pill-label { font-size: 12px; color: #718096; }
        .countdown-pill .pill-sub   { font-size: 11px; color: #a0aec0; }

        .stats-grid { display: grid; grid-template-columns: repeat(4,1fr); gap: 20px; margin-bottom: 25px; position: relative; z-index: 3; }
        @media(max-width:1100px) { .stats-grid { grid-template-columns: repeat(2,1fr); } }
        .stat-card {
            background: white; border-radius: 15px; padding: 20px;
            box-shadow: 0 2px 10px rgba(0,0,0,.05); transition: transform .2s;
        }
        .stat-card:hover { transform: translateY(-4px); box-shadow: 0 6px 20px rgba(0,0,0,.1); }
        .stat-card.lop-card { background: linear-gradient(135deg, #f56565 0%, #c53030 100%); color: white; }
        .stat-card.permission-lop-card { background: linear-gradient(135deg, #ed8936 0%, #c05621 100%); color: white; }
        .stat-icon {
            width: 50px; height: 50px; border-radius: 12px;
            display: flex; align-items: center; justify-content: center;
            margin-bottom: 15px; font-size: 22px;
        }
        .stat-card:not(.lop-card):not(.permission-lop-card) .stat-icon.sick    { background: #fed7d7; color: #c53030; }
        .stat-card:not(.lop-card):not(.permission-lop-card) .stat-icon.casual  { background: #c6f6d5; color: #276749; }
        .stat-card:not(.lop-card):not(.permission-lop-card) .stat-icon.pending { background: #e9d8fd; color: #553c9a; }
        .stat-card.lop-card .stat-icon,
        .stat-card.permission-lop-card .stat-icon { background: rgba(255,255,255,.2); color: white; }
        .stat-value      { font-size: 36px; font-weight: 700; margin-bottom: 5px; }
        .stat-card:not(.lop-card):not(.permission-lop-card) .stat-value { color: #2d3748; }
        .stat-card.lop-card .stat-value,
        .stat-card.permission-lop-card .stat-value { color: white; }
        .stat-label      { font-size: 14px; margin-bottom: 8px; }
        .stat-card:not(.lop-card):not(.permission-lop-card) .stat-label { color: #718096; }
        .stat-card.lop-card .stat-label,
        .stat-card.permission-lop-card .stat-label { color: rgba(255,255,255,.9); }
        .stat-sub { font-size: 12px; padding-top: 10px; border-top: 1px solid #e2e8f0; margin-top: 8px; color: #718096; }
        .stat-card.lop-card .stat-sub,
        .stat-card.permission-lop-card .stat-sub { border-top-color: rgba(255,255,255,.2); color: rgba(255,255,255,.8); }

        .progress-bar  { width: 100%; height: 6px; background: #e2e8f0; border-radius: 4px; overflow: hidden; margin-top: 8px; }
        .progress-fill { height: 100%; border-radius: 4px; transition: width .4s ease; }
        .fill-sick     { background: linear-gradient(90deg,#fc8181,#c53030); }
        .fill-casual   { background: linear-gradient(90deg,#68d391,#276749); }

        .card { background: white; border-radius: 15px; padding: 25px; box-shadow: 0 2px 10px rgba(0,0,0,.05); margin-bottom: 25px; position: relative; z-index: 3; }
        .card-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; }
        .card-title  { font-size: 16px; font-weight: 600; color: #2d3748; }

        .casual-detail {
            background: #f0fff4; border: 1px solid #9ae6b4; border-radius: 10px;
            padding: 14px 20px; margin-bottom: 20px;
            display: flex; flex-wrap: wrap; gap: 20px; align-items: center;
            position: relative;
            z-index: 3;
        }
        .casual-detail .item { display: flex; flex-direction: column; }
        .casual-detail .item .lbl { font-size: 11px; color: #718096; text-transform: uppercase; letter-spacing: .5px; }
        .casual-detail .item .val { font-size: 18px; font-weight: 700; color: #276749; }
        .casual-detail .window-tag {
            background: #276749; color: white; border-radius: 8px;
            padding: 6px 14px; font-size: 13px; font-weight: 600; margin-left: auto;
        }

        .table-container { overflow-x: auto; border: 1px solid #e2e8f0; border-radius: 10px; margin-bottom: 20px; }
        table { width: 100%; border-collapse: collapse; }
        th { background: #f7fafc; padding: 12px 14px; text-align: left; font-weight: 600; color: #4a5568; border-bottom: 2px solid #e2e8f0; font-size: 13px; }
        td { padding: 12px 14px; border-bottom: 1px solid #e2e8f0; color: #4a5568; font-size: 13px; }
        tr:last-child td { border-bottom: none; }
        .lop-row { background: #fff5f5; }
        .permission-row { background: #f0f9ff; }
        .permission-lop-row { background: #fff5f0; }
        .status-badge   { padding: 3px 10px; border-radius: 20px; font-size: 11px; font-weight: 600; display: inline-block; }
        .status-approved { background: #c6f6d5; color: #276749; }
        .status-pending  { background: #fefcbf; color: #744210; }
        .status-rejected { background: #fed7d7; color: #c53030; }
        .status-lop { background: #fed7d7; color: #c53030; }
        .lop-badge { background: #c53030; color: white; padding: 2px 8px; border-radius: 12px; font-size: 11px; margin-left: 5px; }
        .lop-hours-badge { background: #ed8936; color: white; padding: 2px 8px; border-radius: 12px; font-size: 11px; margin-left: 5px; }
        .type-lop    { color: #c53030; font-weight: 600; }
        .type-casual { color: #276749; font-weight: 600; }
        .type-sick   { color: #3182ce; font-weight: 600; }
        .duration-badge { background: #4299e1; color: white; padding: 2px 6px; border-radius: 10px; font-size: 10px; margin-left: 5px; }
        .approved-badge { background: #48bb78; color: white; padding: 2px 8px; border-radius: 12px; font-size: 10px; margin-left: 5px; }
        
        .notification-bell {
            position: relative;
            margin-right: 15px;
            cursor: pointer;
            display: inline-block;
        }
        .notification-bell i {
            font-size: 20px;
            color: #006400;
        }
        .notification-badge {
            position: absolute;
            top: -8px;
            right: -8px;
            background: #c53030;
            color: white;
            border-radius: 50%;
            padding: 2px 6px;
            font-size: 10px;
            min-width: 18px;
            text-align: center;
            font-weight: bold;
            display: <?php echo $unread_count > 0 ? 'block' : 'none'; ?>;
        }
        .notification-panel {
            position: absolute;
            top: 100%;
            right: 0;
            width: 350px;
            background: white;
            border-radius: 10px;
            box-shadow: 0 10px 40px rgba(0,0,0,0.2);
            z-index: 1000;
            display: none;
            max-height: 500px;
            overflow-y: auto;
            margin-top: 10px;
        }
        .notification-header {
            padding: 15px;
            border-bottom: 1px solid #e2e8f0;
            display: flex;
            justify-content: space-between;
            align-items: center;
            background: #f7fafc;
            border-radius: 10px 10px 0 0;
        }
        .notification-header h4 {
            color: #2d3748;
            font-size: 16px;
            margin: 0;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .mark-all-read {
            background: #4299e1;
            color: white;
            border: none;
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 11px;
            cursor: pointer;
            transition: background 0.2s;
            <?php if (empty($unread_notifications)): ?>display: none;<?php endif; ?>
        }
        .mark-all-read:hover {
            background: #3182ce;
        }
        .notification-list {
            padding: 0;
        }
        .notification-item {
            padding: 15px;
            border-bottom: 1px solid #e2e8f0;
            cursor: pointer;
            transition: background 0.2s;
            position: relative;
        }
        .notification-item:hover {
            background: #f7fafc;
        }
        .notification-item.unread {
            background: #ebf8ff;
            border-left: 3px solid #4299e1;
        }
        .notification-item.unread:hover {
            background: #d4edf5;
        }
        .notification-icon {
            display: inline-block;
            width: 30px;
            height: 30px;
            border-radius: 50%;
            text-align: center;
            line-height: 30px;
            margin-right: 10px;
            font-size: 14px;
        }
        .notification-icon.leave-approved { background: #c6f6d5; color: #276749; }
        .notification-icon.leave-rejected { background: #fed7d7; color: #c53030; }
        .notification-icon.leave-deleted { background: #fed7e2; color: #b83280; }
        .notification-icon.permission-approved { background: #c6f6d5; color: #276749; }
        .notification-icon.permission-rejected { background: #fed7d7; color: #c53030; }
        .notification-icon.lop-approved { background: #fed7d7; color: #c53030; }
        .notification-icon.lop-rejected { background: #fed7d7; color: #c53030; }
        .notification-icon.pending-leaves { background: #e9d8fd; color: #553c9a; }
        .notification-icon.pending-permissions { background: #e9d8fd; color: #553c9a; }
        .notification-icon.late-timesheets { background: #fed7d7; color: #c53030; }
        .notification-content {
            flex: 1;
        }
        .notification-title {
            font-weight: 600;
            color: #2d3748;
            font-size: 14px;
            margin-bottom: 3px;
        }
        .notification-message {
            color: #718096;
            font-size: 12px;
            margin-bottom: 3px;
        }
        .notification-time {
            color: #a0aec0;
            font-size: 10px;
        }
        .notification-footer {
            padding: 10px 15px;
            background: #f7fafc;
            border-radius: 0 0 10px 10px;
            text-align: center;
        }
        .notification-footer a {
            color: #4299e1;
            text-decoration: none;
            font-size: 12px;
        }
        .notification-footer a:hover {
            text-decoration: underline;
        }
        .no-notifications {
            padding: 30px;
            text-align: center;
            color: #718096;
        }

        .notification-popup {
            position: fixed;
            top: 80px;
            right: 30px;
            max-width: 350px;
            background: white;
            border-radius: 12px;
            box-shadow: 0 10px 40px rgba(0,0,0,0.2);
            z-index: 10001;
            animation: slideInFromRight 0.5s ease forwards, fadeOut 0.5s ease 9.5s forwards;
            border-left: 5px solid #4299e1;
            overflow: hidden;
            pointer-events: auto;
        }

        @keyframes slideInFromRight {
            0% {
                transform: translateX(400px);
                opacity: 0;
            }
            100% {
                transform: translateX(0);
                opacity: 1;
            }
        }

        @keyframes fadeOut {
            0% {
                opacity: 1;
                transform: translateX(0);
            }
            100% {
                opacity: 0;
                transform: translateX(400px);
                display: none;
            }
        }

        .notification-popup-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 15px 20px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .notification-popup-header i {
            font-size: 24px;
        }

        .notification-popup-header h4 {
            flex: 1;
            font-size: 16px;
            font-weight: 600;
            margin: 0;
        }

        .notification-popup-close {
            background: rgba(255,255,255,0.2);
            border: none;
            color: white;
            width: 30px;
            height: 30px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            font-size: 16px;
            transition: background 0.2s;
        }

        .notification-popup-close:hover {
            background: rgba(255,255,255,0.3);
        }

        .notification-popup-body {
            padding: 20px;
            display: flex;
            gap: 15px;
        }

        .notification-popup-icon {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 20px;
            flex-shrink: 0;
        }

        .notification-popup-icon.leave-approved { background: #c6f6d5; color: #276749; }
        .notification-popup-icon.leave-rejected { background: #fed7d7; color: #c53030; }
        .notification-popup-icon.leave-deleted { background: #fed7e2; color: #b83280; }
        .notification-popup-icon.permission-approved { background: #c6f6d5; color: #276749; }
        .notification-popup-icon.permission-rejected { background: #fed7d7; color: #c53030; }
        .notification-popup-icon.lop-approved { background: #fed7d7; color: #c53030; }
        .notification-popup-icon.lop-rejected { background: #fed7d7; color: #c53030; }
        .notification-popup-icon.pending-leaves { background: #e9d8fd; color: #553c9a; }
        .notification-popup-icon.pending-permissions { background: #e9d8fd; color: #553c9a; }
        .notification-popup-icon.late-timesheets { background: #fed7d7; color: #c53030; }

        .notification-popup-content {
            flex: 1;
        }

        .notification-popup-title {
            font-weight: 700;
            color: #2d3748;
            font-size: 15px;
            margin-bottom: 5px;
        }

        .notification-popup-message {
            color: #718096;
            font-size: 13px;
            margin-bottom: 5px;
        }

        .notification-popup-time {
            color: #a0aec0;
            font-size: 11px;
        }

        .notification-popup-footer {
            padding: 10px 20px 15px;
            text-align: right;
        }

        .notification-popup-footer a {
            color: #4299e1;
            text-decoration: none;
            font-size: 12px;
            font-weight: 600;
        }

        .notification-popup-footer a:hover {
            text-decoration: underline;
        }

        .window-label {
            font-size: 11px;
            color: #a0aec0;
            margin-top: 2px;
        }
    </style>
</head>
<body>

<?php if ($show_welcome): ?>
<div class="welcome-overlay" id="welcomeOverlay">
    <div class="welcome-left">
        <div class=
            <img src="<?php echo $base_path; ?>assets/images/maksim_infotech_logo.png" alt="MAKSIM Logo">
        </div>
        <img src="<?php echo $base_path; ?>assets/images/maksim_infotech_logo.png" alt="MAKSIM Logo">
        <h1>MAKSIM</h1>
        <div class="tagline">"Work Together - Grow Together"</div>
    </div>
    <div class="welcome-right">
        <div class="greeting">Welcome back,</div>
        <div class="user-name"><?php echo htmlspecialchars($full_name); ?>!</div>
        <div class="role-badge"><?php echo strtoupper($role); ?></div>
        <div class="quote">"Success is not just about what you accomplish, but what you inspire others to do."</div>
    </div>
</div>
<?php endif; ?>

<!-- Notification Popup - Shows once from right side -->
<?php if ($show_notification_popup && $latest_notification): ?>
<div class="notification-popup" id="notificationPopup">
    <div class="notification-popup-header">
        <i class="icon-bell"></i>
        <h4>New Notification</h4>
        <button class="notification-popup-close" onclick="closeNotificationPopup()">&times;</button>
    </div>
    <div class="notification-popup-body">
        <?php
        $icon_class = '';
        if (strpos($latest_notification['type'], 'leave_approved') !== false) $icon_class = 'leave-approved';
        elseif (strpos($latest_notification['type'], 'leave_rejected') !== false) $icon_class = 'leave-rejected';
        elseif (strpos($latest_notification['type'], 'leave_deleted') !== false) $icon_class = 'leave-deleted';
        elseif (strpos($latest_notification['type'], 'permission_approved') !== false) $icon_class = 'permission-approved';
        elseif (strpos($latest_notification['type'], 'permission_rejected') !== false) $icon_class = 'permission-rejected';
        elseif (strpos($latest_notification['type'], 'lop_approved') !== false) $icon_class = 'lop-approved';
        elseif (strpos($latest_notification['type'], 'lop_rejected') !== false) $icon_class = 'lop-rejected';
        elseif (strpos($latest_notification['type'], 'pending_leaves') !== false) $icon_class = 'pending-leaves';
        elseif (strpos($latest_notification['type'], 'pending_permissions') !== false) $icon_class = 'pending-permissions';
        elseif (strpos($latest_notification['type'], 'late_timesheets') !== false) $icon_class = 'late-timesheets';
        else $icon_class = 'leave-approved';
        ?>
        <div class="notification-popup-icon <?php echo $icon_class; ?>">
            <?php
            if (strpos($latest_notification['type'], 'approved') !== false) echo '✓';
            elseif (strpos($latest_notification['type'], 'rejected') !== false) echo '✗';
            elseif (strpos($latest_notification['type'], 'deleted') !== false) echo '🗑️';
            elseif (strpos($latest_notification['type'], 'pending') !== false) echo '⏳';
            elseif (strpos($latest_notification['type'], 'late') !== false) echo '⚠️';
            else echo 'ℹ️';
            ?>
        </div>
        <div class="notification-popup-content">
            <div class="notification-popup-title"><?php echo htmlspecialchars($latest_notification['title']); ?></div>
            <div class="notification-popup-message"><?php echo htmlspecialchars(mb_strimwidth($latest_notification['message'], 0, 80, '…')); ?></div>
            <div class="notification-popup-time"><?php echo date('M j, Y g:i A', strtotime($latest_notification['created_at'])); ?></div>
        </div>
    </div>
    <div class="notification-popup-footer">
        <a href="#" onclick="document.getElementById('notificationPopup').style.display='none'; toggleNotifications(); return false;">View All Notifications →</a>
    </div>
</div>
<?php endif; ?>

<?php include 'includes/header.php'; ?>

<div class="app-main">
    <?php include 'includes/sidebar.php'; ?>

    <div class="main-content">
        <h2 class="page-title">Dashboard</h2>

        <!-- Birthday Banner with Cake and Floating Elements in Background -->
<?php if (!empty($today_birthdays)): ?>
<div class="floating-container" id="floatingContainer"></div>

<div class="birthday-banner">
    <div class="cake-icon">🎂</div>
    <div class="birthday-message">
        <h3 class="rainbow-text">
            <?php
            $bday_text = '';
            if ($is_my_birthday) {
                $bday_text = '🎉 Happy Birthday to You, ' . htmlspecialchars($full_name) . '! 🎉';
            } elseif (count($today_birthdays) == 1) {
                $bday_text = '🎉 Happy Birthday ' . htmlspecialchars($today_birthdays[0]['full_name']) . '! 🎉';
            } else {
                $bday_text = '🎉 Happy Birthday to our Birthday Stars! 🎉';
            }
            // Wrap each character in a span for rainbow effect
            $chars = mb_str_split($bday_text);
            foreach ($chars as $ch) {
                if ($ch === ' ') echo ' ';
                else echo '<span>' . htmlspecialchars($ch) . '</span>';
            }
            ?>
        </h3>
        <div class="birthday-names">
            <?php foreach ($today_birthdays as $bday_user): ?>
                <span class="birthday-name-tag">
                    🎈 <?php echo htmlspecialchars($bday_user['full_name']); ?>
                </span>
            <?php endforeach; ?>
        </div>
    </div>
    <div class="cake-icon">🎂</div>
</div>

<?php endif; ?>

<?php if (!empty($advance_birthdays)): ?>
<!-- ── Advance Birthday Wishes Banners (one per person) ─────────────── -->
<?php
$advance_colors = [
    ['bg' => 'linear-gradient(135deg, #1a1a2e, #e94560)', 'shadow' => '#e94560'],
    ['bg' => 'linear-gradient(135deg, #0f2027, #f7971e)', 'shadow' => '#f7971e'],
    ['bg' => 'linear-gradient(135deg, #200122, #6f0000)', 'shadow' => '#cc0000'],
    ['bg' => 'linear-gradient(135deg, #0d0d0d, #7928ca)', 'shadow' => '#7928ca'],
    ['bg' => 'linear-gradient(135deg, #003973, #0ba360)', 'shadow' => '#0ba360'],
];
$ci = 0;
foreach ($advance_birthdays as $ab_person):
    $ac = $advance_colors[$ci % count($advance_colors)];
    $ci++;
    $is_me = ($is_my_birthday_tomorrow && $ab_person['full_name'] === $full_name);
    $ab_title = $is_me
        ? '🎂 Advance Happy Birthday to You, ' . htmlspecialchars($ab_person['full_name']) . '! 🎂'
        : '🎂 Advance Happy Birthday ' . htmlspecialchars($ab_person['full_name']) . '! 🎂';
?>
<div class="advance-bday-banner" style="background: <?php echo $ac['bg']; ?>; box-shadow: 0 0 0 2px <?php echo $ac['shadow']; ?>, 0 0 20px <?php echo $ac['shadow']; ?>55;">
    <div class="decor-strip">🎂 🎈 🎉 🎁 🎊 🎆 🎇 ✨ 🎂 🎈 🎉 🎁 🎊 🎆 🎇 ✨ 🎂 🎈 🎉 🎁 🎊</div>
    <div style="font-size:55px; animation: bounce 1s infinite; z-index:2;">🎂</div>
    <div style="flex:1; z-index:2;">
        <h3 class="rainbow-text" style="font-size:22px; margin:0 0 8px;">
            <?php
            $chars = mb_str_split($ab_title);
            foreach ($chars as $ch) {
                if ($ch === ' ') echo ' ';
                else echo '<span>' . htmlspecialchars($ch) . '</span>';
            }
            ?>
        </h3>
        <p style="margin:0; opacity:0.9; font-size:14px;">
            <?php echo $is_me ? '🎉 Wishing you a wonderful celebration tomorrow! 🎊' : "🎉 Wishing them a wonderful celebration tomorrow! 🎊"; ?>
        </p>
        <span style="display:inline-block; margin-top:8px; background:rgba(255,255,255,0.15); padding:4px 14px; border-radius:20px; font-size:13px;">🗓️ <?php echo date('d M Y', strtotime('+1 day')); ?></span>
    </div>
    <div style="font-size:55px; animation: bounce 1s infinite 0.5s; z-index:2;">🎈</div>
</div>
<?php endforeach; ?>
<!-- ──────────────────────────────────────────────────────────────────── -->
<?php endif; ?>

<?php if ($advance_holiday && $advance_theme): ?>
<!-- ── Holiday Advance Celebration Banner ─────────────────────────────── -->
<div class="holiday-advance-banner" style="background: <?php echo $advance_theme['bg']; ?>; color: <?php echo $advance_theme['color']; ?>;">
    <!-- Decorative floating emojis in background -->
    <div class="holiday-decor-strip">
        <?php echo implode(' ', array_fill(0, 6, implode(' ', $advance_theme['decor']))); ?>
    </div>
    <div class="holiday-emoji-big"><?php echo $advance_theme['emoji']; ?></div>
    <div class="holiday-advance-msg">
        <h3>
            <?php echo $advance_theme['emoji']; ?>
            <?php echo $advance_is_today ? 'Happy ' : 'Advance Happy '; ?><?php echo htmlspecialchars($advance_holiday); ?>!
            <?php echo $advance_theme['emoji']; ?>
        </h3>
        <p><?php echo $advance_is_today
            ? 'Wishing everyone at MAKSIM a joyful ' . htmlspecialchars($advance_holiday) . ' today! 🎉'
            : 'Tomorrow is ' . htmlspecialchars($advance_holiday) . '! Wishing everyone a wonderful celebration! 🎊'; ?>
        </p>
        <span class="holiday-tag"><?php echo $advance_theme['emoji']; ?> <?php echo $advance_is_today ? date('d M Y', strtotime($today_date)) : date('d M Y', strtotime($tomorrow)); ?></span>
    </div>
    <div class="holiday-emoji-big"><?php echo $advance_theme['emoji']; ?></div>
</div>
<!-- ──────────────────────────────────────────────────────────────────── -->
<?php endif; ?>

<?php if ($hol_today_row && $tomorrow_holiday && $tomorrow_holiday_theme): ?>
<!-- ── Tomorrow's Holiday Advance Banner (shown even when today has a holiday) ── -->
<div class="holiday-advance-banner" style="background: <?php echo $tomorrow_holiday_theme['bg']; ?>; color: <?php echo $tomorrow_holiday_theme['color']; ?>;">
    <div class="holiday-decor-strip">
        <?php echo implode(' ', array_fill(0, 6, implode(' ', $tomorrow_holiday_theme['decor']))); ?>
    </div>
    <div class="holiday-emoji-big"><?php echo $tomorrow_holiday_theme['emoji']; ?></div>
    <div class="holiday-advance-msg">
        <h3>
            <?php echo $tomorrow_holiday_theme['emoji']; ?>
            Advance Happy <?php echo htmlspecialchars($tomorrow_holiday); ?>!
            <?php echo $tomorrow_holiday_theme['emoji']; ?>
        </h3>
        <p>Tomorrow is <?php echo htmlspecialchars($tomorrow_holiday); ?>! Wishing everyone a wonderful celebration! 🎊</p>
        <span class="holiday-tag"><?php echo $tomorrow_holiday_theme['emoji']; ?> <?php echo date('d M Y', strtotime($tomorrow)); ?></span>
    </div>
    <div class="holiday-emoji-big"><?php echo $tomorrow_holiday_theme['emoji']; ?></div>
</div>
<!-- ──────────────────────────────────────────────────────────────────── -->
<?php endif; ?>

<?php if (!empty($today_birthdays)): ?>
        <script>
        // SVG Balloon templates - different shapes
        function getBalloonSVG(type, color) {
            switch(type) {
                case 'round':
                    return `<svg viewBox="0 0 40 60" width="40" height="60">
                        <circle cx="20" cy="25" r="16" fill="currentColor" opacity="0.9"/>
                        <path d="M20 41 L15 52 L25 52 L20 41" fill="currentColor" opacity="0.9"/>
                        <line x1="20" y1="52" x2="20" y2="58" stroke="currentColor" stroke-width="1.5" opacity="0.7"/>
                    </svg>`;
                case 'teardrop':
                    return `<svg viewBox="0 0 40 65" width="40" height="65">
                        <path d="M20 5 Q32 5 34 18 Q36 28 28 38 Q20 48 12 38 Q4 28 6 18 Q8 5 20 5" fill="currentColor" opacity="0.9"/>
                        <path d="M20 48 L16 57 L24 57 L20 48" fill="currentColor" opacity="0.9"/>
                        <line x1="20" y1="57" x2="20" y2="62" stroke="currentColor" stroke-width="1.5" opacity="0.7"/>
                    </svg>`;
                case 'heart':
                    return `<svg viewBox="0 0 40 60" width="40" height="60">
                        <path d="M20 35 C10 25 5 15 10 8 C15 1 25 1 30 8 C35 15 30 25 20 35" fill="currentColor" opacity="0.9"/>
                        <path d="M20 35 L16 44 L24 44 L20 35" fill="currentColor" opacity="0.9"/>
                        <line x1="20" y1="44" x2="20" y2="52" stroke="currentColor" stroke-width="1.5" opacity="0.7"/>
                    </svg>`;
                case 'star':
                    return `<svg viewBox="0 0 40 60" width="40" height="60">
                        <path d="M20 8 L24 18 L35 18 L26 25 L30 36 L20 30 L10 36 L14 25 L5 18 L16 18 L20 8" fill="currentColor" opacity="0.9"/>
                        <path d="M20 30 L16 40 L24 40 L20 30" fill="currentColor" opacity="0.9"/>
                        <line x1="20" y1="40" x2="20" y2="48" stroke="currentColor" stroke-width="1.5" opacity="0.7"/>
                    </svg>`;
                case 'long':
                    return `<svg viewBox="0 0 40 70" width="40" height="70">
                        <ellipse cx="20" cy="25" rx="14" ry="20" fill="currentColor" opacity="0.9"/>
                        <path d="M20 45 L15 58 L25 58 L20 45" fill="currentColor" opacity="0.9"/>
                        <line x1="20" y1="58" x2="20" y2="68" stroke="currentColor" stroke-width="1.5" opacity="0.7"/>
                    </svg>`;
                default:
                    return `<svg viewBox="0 0 40 60" width="40" height="60">
                        <ellipse cx="20" cy="25" rx="15" ry="20" fill="currentColor" opacity="0.9"/>
                        <path d="M20 45 L15 58 L25 58 L20 45" fill="currentColor" opacity="0.9"/>
                        <line x1="20" y1="58" x2="20" y2="65" stroke="currentColor" stroke-width="1.5" opacity="0.7"/>
                    </svg>`;
            }
        }

        const floatingElements = [
            { type: 'emoji', emoji: '🎈', category: 'balloon' },
            { type: 'emoji', emoji: '🎈', category: 'balloon' },
            { type: 'emoji', emoji: '⭐', category: 'star' },
            { type: 'emoji', emoji: '🌟', category: 'star' },
            { type: 'emoji', emoji: '✨', category: 'sparkle' },
            { type: 'emoji', emoji: '❤️', category: 'heart' },
            { type: 'emoji', emoji: '💖', category: 'heart' },
            { type: 'emoji', emoji: '💝', category: 'heart' },
            { type: 'emoji', emoji: '💕', category: 'heart' },
            { type: 'emoji', emoji: '🎉', category: 'party' },
            { type: 'emoji', emoji: '🎊', category: 'party' },
            { type: 'emoji', emoji: '🎂', category: 'cake' },
            { type: 'emoji', emoji: '🍰', category: 'cake' },
            { type: 'emoji', emoji: '🎁', category: 'gift' },
            { type: 'emoji', emoji: '🎀', category: 'ribbon' },
            { type: 'emoji', emoji: '🦄', category: 'mythical' },
            { type: 'emoji', emoji: '🌈', category: 'rainbow' },
            { type: 'emoji', emoji: '☁️', category: 'cloud' },
            { type: 'emoji', emoji: '🕊️', category: 'dove' },
            { type: 'emoji', emoji: '🦋', category: 'butterfly' },
            { type: 'emoji', emoji: '🌸', category: 'flower' },
            { type: 'emoji', emoji: '🌺', category: 'flower' },
            { type: 'emoji', emoji: '🌼', category: 'flower' },
            { type: 'emoji', emoji: '🌻', category: 'flower' },
            
            { type: 'svg', shape: 'round', category: 'balloon_svg' },
            { type: 'svg', shape: 'round', category: 'balloon_svg' },
            { type: 'svg', shape: 'teardrop', category: 'balloon_svg' },
            { type: 'svg', shape: 'teardrop', category: 'balloon_svg' },
            { type: 'svg', shape: 'heart', category: 'balloon_svg' },
            { type: 'svg', shape: 'heart', category: 'balloon_svg' },
            { type: 'svg', shape: 'star', category: 'balloon_svg' },
            { type: 'svg', shape: 'long', category: 'balloon_svg' },
            { type: 'svg', shape: 'long', category: 'balloon_svg' },
            { type: 'svg', shape: 'default', category: 'balloon_svg' }
        ];

        function createFloatingItem() {
            const container = document.getElementById('floatingContainer');
            if (!container) return;
            
            const item = document.createElement('div');
            item.className = 'floating-item';
            
            const randomIndex = Math.floor(Math.random() * floatingElements.length);
            const element = floatingElements[randomIndex];
            
            if (element.type === 'emoji') {
                item.textContent = element.emoji;
                item.style.fontSize = (Math.floor(Math.random() * 25) + 25) + 'px';
            } else {
                const size = Math.floor(Math.random() * 15) + 25;
                const svgHtml = getBalloonSVG(element.shape);
                item.innerHTML = `<div class="svg-balloon" style="width: ${size}px; height: ${size * 1.5}px;">${svgHtml}</div>`;
                item.style.fontSize = '0';
            }
            
            const colors = ['red', 'blue', 'green', 'yellow', 'purple', 'pink', 'orange', 'cyan', 'gold', 'silver'];
            const randomColor = colors[Math.floor(Math.random() * colors.length)];
            item.classList.add(randomColor);
            
            const startLeft = Math.random() * 100;
            const duration = Math.random() * 12 + 15;
            const delay = Math.random() * 8;
            const rotation = (Math.random() * 40 - 20);
            
            item.style.setProperty('--start-left', startLeft + '%');
            item.style.setProperty('--duration', duration + 's');
            item.style.setProperty('--delay', delay + 's');
            item.style.setProperty('--rotation', rotation + 'deg');
            item.style.left = startLeft + '%';
            
            container.appendChild(item);
            
            setTimeout(() => {
                if (item.parentNode) {
                    item.remove();
                }
            }, (duration + delay) * 1000);
        }

        if (document.querySelector('.birthday-banner')) {
            for (let i = 0; i < 30; i++) {
                setTimeout(() => {
                    createFloatingItem();
                }, i * 80);
            }
            
            setInterval(() => {
                if (document.querySelector('.birthday-banner')) {
                    const count = Math.floor(Math.random() * 3) + 3;
                    for (let i = 0; i < count; i++) {
                        setTimeout(() => {
                            createFloatingItem();
                        }, i * 120);
                    }
                }
            }, 500);
        }
        </script>
        <?php endif; ?>

        <div class="cycle-banner">
            <div class="section">
                <span class="label">Sick Leave Year</span>
                <span class="value"><i class="icon-calendar"></i> <?php echo $casual_year['year_label']; ?> (Mar 16–Mar 15)</span>
            </div>
            <div class="divider"></div>
            <div class="section">
                <span class="label">Casual Leave Cycle</span>
                <span class="value"><i class="icon-sync"></i> <?php echo $casual_year['year_label']; ?> (Mar 16–Mar 15)</span>
            </div>
            <div class="divider"></div>
            <div class="section">
                <span class="label">Current Window</span>
                <span class="value">
                    <i class="icon-calendar"></i>
                    <?php echo date('d M', strtotime($current_window['window_start'])); ?>
                    &rarr;
                    <?php echo date('d M', strtotime($current_window['window_end'])); ?>
                </span>
            </div>
            <div class="divider"></div>
            <div class="section">
                <span class="label">Join Date</span>
                <span class="value">
                    <i class="icon-user"></i>
                    <?php echo $balance['join_date'] ? date('d M Y', strtotime($balance['join_date'])) : 'N/A'; ?>
                </span>
            </div>
        </div>

        <div class="countdown-row">
            <div class="countdown-pill monthly">
                <div class="pill-icon"><i class="icon-calendar"></i></div>
                <div>
                    <div class="pill-days"><?php echo $days_until_monthly_reset; ?></div>
                    <div class="pill-label">Days until next monthly window</div>
                    <div class="pill-sub">Next accrual on <?php echo date('d M Y', strtotime(date('Y-m') . '-16' . ($today->format('j') >= 16 ? ' +1 month' : ''))); ?></div>
                </div>
            </div>
            <div class="countdown-pill yearly">
                <div class="pill-icon"><i class="icon-hourglass"></i></div>
                <div>
                    <div class="pill-days"><?php echo $days_until_yearly_reset; ?></div>
                    <div class="pill-label">Days until casual cycle resets</div>
                    <div class="pill-sub">Resets <?php echo $next_mar16_label; ?> (Mar 16) — unused days forfeited</div>
                </div>
            </div>
            <div class="countdown-pill" style="border-left: 3px solid #f56565;">
                <div class="pill-icon" style="background:#fff5f5; color:#c53030;"><i class="icon-sick"></i></div>
                <div>
                    <div class="pill-days"><?php echo $days_until_yearly_reset; ?></div>
                    <div class="pill-label">Days until sick leave resets</div>
                    <div class="pill-sub">Resets <?php echo $next_mar16_label; ?> (Mar 16)</div>
                </div>
            </div>
        </div>

        <div class="casual-detail">
            <div class="item">
                <span class="lbl">Entitled (cycle)</span>
                <span class="val"><?php echo $casual_total_entitled; ?> days</span>
            </div>
            <div class="item">
                <span class="lbl">Accrued to date</span>
                <span class="val"><?php echo $casual_accrued; ?> days</span>
            </div>
            <div class="item">
                <span class="lbl">Used (cycle)</span>
                <span class="val"><?php echo $casual_used_cycle; ?> days</span>
            </div>
            <div class="item">
                <span class="lbl">Used (this window)</span>
                <span class="val"><?php echo $casual_used_this_window; ?> days</span>
            </div>
            <div class="item">
                <span class="lbl">Available (carry-fwd)</span>
                <span class="val" style="color:#276749;"><?php echo $casual_available; ?> days</span>
            </div>
            <div class="window-tag">
                <i class="icon-calendar"></i>
                <?php echo date('d M', strtotime($current_window['window_start'])); ?> &rarr; <?php echo date('d M', strtotime($current_window['window_end'])); ?>
            </div>
        </div>

        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-icon sick"><i class="icon-sick"></i></div>
                <div class="stat-value"><?php echo $balance['remaining']['Sick'] ?? 0; ?></div>
                <div class="stat-label">Sick Leave Remaining</div>
                <div class="stat-sub">
                    Entitled: <?php echo $balance['sick_entitlement'] ?? 6; ?> | Used: <?php echo $balance['used']['Sick'] ?? 0; ?>
                    <div class="progress-bar"><div class="progress-fill fill-sick" style="width:<?php echo $sick_progress; ?>%"></div></div>
                </div>
            </div>

            <div class="stat-card">
                <div class="stat-icon casual"><i class="icon-casual"></i></div>
                <div class="stat-value"><?php echo $casual_available; ?></div>
                <div class="stat-label">Casual Leave Available</div>
                <div class="stat-sub">
                    Accrued: <?php echo $casual_accrued; ?> / <?php echo $casual_total_entitled; ?> | Used: <?php echo $casual_used_cycle; ?>
                    <div class="progress-bar"><div class="progress-fill fill-casual" style="width:<?php echo $casual_progress; ?>%"></div></div>
                    <div class="window-label">Window: <?php echo date('M j', strtotime($current_window['window_start'])); ?> – <?php echo date('M j', strtotime($current_window['window_end'])); ?></div>
                </div>
            </div>

            <div class="stat-card lop-card">
                <div class="stat-icon"><i class="icon-lop"></i></div>
                <div class="stat-value"><?php echo $lop_this_window; ?></div>
                <div class="stat-label">LOP (Leaves) — Current Window</div>
                <div class="stat-sub">
                    <div>Yearly total: <?php echo $lop_total; ?> days</div>
                    <div class="window-label">Window: <?php echo date('M j', strtotime($lop_window['window_start'])); ?> – <?php echo date('M j', strtotime($lop_window['window_end'])); ?></div>
                </div>
            </div>

            <div class="stat-card permission-lop-card">
                <div class="stat-icon"><i class="icon-clock"></i></div>
                <div class="stat-value"><?php echo $permission_lop_window_hours; ?> hr</div>
                <div class="stat-label">Permission LOP — Current Window</div>
                <div class="stat-sub">
                    <div>Yearly total: <?php echo $permission_lop_total_hours; ?> hrs</div>
                    <div>Previous window: <?php echo $permission_lop_prev_window_hours; ?> hrs</div>
                    <div class="window-label">Window: <?php echo date('M j', strtotime($permission_window['window_start'])); ?> – <?php echo date('M j', strtotime($permission_window['window_end'])); ?></div>
                </div>
            </div>
        </div>

        <div class="card">
            <div class="card-header">
                <h3 class="card-title">Leave Balance Summary</h3>
            </div>
            <div class="table-container">
                <table>
                    <thead>
                        <tr>
                            <th>Type</th>
                            <th>Cycle / Year</th>
                            <th>Entitled</th>
                            <th>Accrued</th>
                            <th>Used</th>
                            <th>Available</th>
                            <th>Window Used</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td class="type-sick">Sick</td>
                            <td><?php echo $casual_year['year_label']; ?> (Mar 16–Mar 15)</td>
                            <td><?php echo $balance['sick_entitlement'] ?? 6; ?></td>
                            <td>—</td>
                            <td><?php echo $balance['used']['Sick'] ?? 0; ?></td>
                            <td><strong><?php echo $balance['remaining']['Sick'] ?? 0; ?></strong></td>
                            <td><?php echo getSickLeaveUsedThisMonth($conn, $user_id); ?> / month</td>
                        </tr>
                        <tr>
                            <td class="type-casual">Casual</td>
                            <td>
                                <?php echo $casual_year['year_label']; ?> (Mar 16–Mar 15)<br>
                                <small style="color:#a0aec0;">Window: <?php echo date('d M', strtotime($current_window['window_start'])); ?> – <?php echo date('d M', strtotime($current_window['window_end'])); ?></small>
                            </td>
                            <td><?php echo $casual_total_entitled; ?></td>
                            <td><?php echo $casual_accrued; ?></td>
                            <td><?php echo $casual_used_cycle; ?></td>
                            <td><strong style="color:#276749;"><?php echo $casual_available; ?></strong></td>
                            <td><?php echo $casual_used_this_window; ?></td>
                        </tr>
                        <tr class="lop-row">
                            <td class="type-lop">LOP (Leaves) <span class="lop-badge">Unpaid</span></td>
                            <td><?php echo $casual_year['year_label']; ?></td>
                            <td>N/A</td>
                            <td>—</td>
                            <td style="color:#c53030;"><?php echo $lop_total; ?> days</td>
                            <td>N/A</td>
                            <td><?php echo $lop_this_window; ?> days <small style="color:#a0aec0;">(window <?php echo date('M j', strtotime($lop_window['window_start'])); ?>–<?php echo date('M j', strtotime($lop_window['window_end'])); ?>)</small></td>
                        </tr>
                        <tr class="permission-lop-row">
                            <td style="color:#ed8936; font-weight:600;">Permission LOP <span class="lop-hours-badge">Hours</span></td>
                            <td><?php echo $casual_year['year_label']; ?></td>
                            <td>N/A</td>
                            <td>—</td>
                            <td style="color:#ed8936;"><?php echo $permission_lop_total_hours; ?> hrs</td>
                            <td>N/A</td>
                            <td><?php echo $permission_lop_window_hours; ?> hrs <small style="color:#a0aec0;">(window <?php echo date('M j', strtotime($permission_window['window_start'])); ?>–<?php echo date('M j', strtotime($permission_window['window_end'])); ?>)</small></td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="card">
            <div class="card-header">
                <h3 class="card-title">Recent Leaves</h3>
                <a href="leaves/leaves.php" style="background:#4299e1; color:white; padding:6px 14px; border-radius:6px; text-decoration:none; font-size:13px;">View All</a>
            </div>
            <div class="table-container">
                <table>
                    <thead>
                        <tr><th>Type</th><th>From</th><th>To</th><th>Days</th><th>Reason</th><th>Status</th></tr>
                    </thead>
                    <tbody>
                        <?php if (!empty($recent_leaves)): ?>
                            <?php foreach ($recent_leaves as $leave):
                                $is_lop = ($leave['leave_type'] === 'LOP');
                                $type_class = $is_lop ? 'type-lop' : ($leave['leave_type'] === 'Casual' ? 'type-casual' : 'type-sick');
                            ?>
                            <tr class="<?php echo $is_lop ? 'lop-row' : ''; ?>">
                                <td class="<?php echo $type_class; ?>">
                                    <?php echo htmlspecialchars($leave['leave_type']); ?>
                                    <?php if ($is_lop): ?><span class="lop-badge">Unpaid</span><?php endif; ?>
                                </td>
                                <td><?php echo htmlspecialchars($leave['from_date']); ?></td>
                                <td><?php echo htmlspecialchars($leave['to_date']); ?></td>
                                <td><?php echo htmlspecialchars($leave['days']); ?></td>
                                <td style="max-width:160px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;" title="<?php echo htmlspecialchars($leave['reason'] ?? ''); ?>">
                                    <?php echo htmlspecialchars(mb_strimwidth($leave['reason'] ?? '—', 0, 30, '…')); ?>
                                </td>
                                <td>
                                    <span class="status-badge status-<?php echo strtolower(htmlspecialchars($leave['status'])); ?>">
                                        <?php echo htmlspecialchars($leave['status']); ?>
                                    </span>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr><td colspan="6" style="text-align:center; padding:24px; color:#a0aec0;">No recent leaves found</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Recent LOP Leaves -->
        <div class="card">
            <div class="card-header">
                <h3 class="card-title">Recent LOP Leaves (Approved)</h3>
                <a href="leaves/leaves.php" style="background:#4299e1; color:white; padding:6px 14px; border-radius:6px; text-decoration:none; font-size:13px;">View All</a>
            </div>
            <div class="table-container">
                <table>
                    <thead>
                        <tr><th>From</th><th>To</th><th>Days</th><th>Reason</th><th>Status</th></tr>
                    </thead>
                    <tbody>
                        <?php if (!empty($lop_leaves)): ?>
                            <?php foreach ($lop_leaves as $leave): ?>
                            <tr class="lop-row">
                                <td><?php echo htmlspecialchars($leave['from_date']); ?></td>
                                <td><?php echo htmlspecialchars($leave['to_date']); ?></td>
                                <td><strong><?php echo htmlspecialchars($leave['days']); ?></strong></td>
                                <td style="max-width:200px;" title="<?php echo htmlspecialchars($leave['reason'] ?? ''); ?>">
                                    <?php echo htmlspecialchars(mb_strimwidth($leave['reason'] ?? '—', 0, 40, '…')); ?>
                                </td>
                                <td>
                                    <span class="status-badge status-approved">
                                        Approved
                                    </span>
                                    <span class="lop-badge">LOP</span>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="5" style="text-align:center; padding:24px; color:#a0aec0;">No approved LOP leaves found</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Recent Approved LOP Permissions -->
        <div class="card">
            <div class="card-header">
                <h3 class="card-title">Recent LOP Permissions (Approved)</h3>
                <a href="permissions/permissions.php" style="background:#4299e1; color:white; padding:6px 14px; border-radius:6px; text-decoration:none; font-size:13px;">View All</a>
            </div>
            <div class="table-container">
                <table>
                    <thead>
                        <tr><th>Date</th><th>Duration</th><th>Reason</th><th>Status</th><th>LOP Hours</th></tr>
                    </thead>
                    <tbody>
                        <?php if (!empty($lop_permissions)): ?>
                            <?php foreach ($lop_permissions as $permission): ?>
                            <tr class="permission-lop-row" style="background: #fff0e6;">
                                <td><?php echo date('Y-m-d', strtotime($permission['permission_date'])); ?></td>
                                <td>
                                    <?php 
                                    $dur = floatval($permission['duration']);
                                    if ($dur == 1) echo "1 hour";
                                    elseif ($dur < 1) echo ($dur * 60) . " min";
                                    elseif ($dur == 8) echo "Full Day";
                                    else echo $dur . " hours";
                                    ?>
                                </td>
                                <td style="max-width:200px;" title="<?php echo htmlspecialchars($permission['reason']); ?>">
                                    <?php echo htmlspecialchars(mb_strimwidth($permission['reason'] ?? '—', 0, 40, '…')); ?>
                                </td>
                                <td>
                                    <span class="status-badge status-approved">
                                        Approved
                                    </span>
                                    <span class="approved-badge">LOP</span>
                                </td>
                                <td>
                                    <span class="lop-hours-badge"><?php echo $permission['lop_hours']; ?> hr LOP</span>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="5" style="text-align:center; padding:24px; color:#a0aec0;">No approved LOP permissions found</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

    </div>
</div>

<!-- User Modal -->
<div id="userModal" class="user-modal">
    <div class="user-modal-content">
        <div class="user-modal-header">
            <h3>My Profile</h3>
            <button class="close-modal" onclick="closeUserModal()">&times;</button>
        </div>
        <div class="user-modal-body">
            <div class="user-detail-card">
                <div class="user-avatar">
                    <?php echo strtoupper(substr($current_user_details['full_name'], 0, 1)); ?>
                </div>
                <div class="user-name-large"><?php echo htmlspecialchars($current_user_details['full_name']); ?></div>
                <div class="user-role-large">
                    <span class="role-badge-small role-<?php echo $current_user_details['role']; ?>" style="padding: 5px 15px; font-size: 14px;">
                        <?php echo strtoupper($current_user_details['role']); ?>
                    </span>
                </div>
                
                <div class="info-grid">
                    <div class="info-item">
                        <div class="info-label">Username</div>
                        <div class="info-value"><?php echo htmlspecialchars($current_user_details['username']); ?></div>
                    </div>
                    <div class="info-item">
                        <div class="info-label">Email</div>
                        <div class="info-value"><?php echo htmlspecialchars($current_user_details['email'] ?? 'Not provided'); ?></div>
                    </div>
                    <div class="info-item">
                        <div class="info-label">Department</div>
                        <div class="info-value"><?php echo htmlspecialchars($current_user_details['department'] ?? 'Not assigned'); ?></div>
                    </div>
                    <div class="info-item">
                        <div class="info-label">Position</div>
                        <div class="info-value"><?php echo htmlspecialchars($current_user_details['position'] ?? 'Not assigned'); ?></div>
                    </div>
                    <div class="info-item">
                        <div class="info-label">Join Date</div>
                        <div class="info-value"><?php echo $current_user_details['join_date'] ? date('d M Y', strtotime($current_user_details['join_date'])) : 'Not set'; ?></div>
                    </div>
                    <div class="info-item">
                        <div class="info-label">Reporting To</div>
                        <div class="info-value"><?php echo htmlspecialchars($reporting_manager); ?></div>
                    </div>
                    <div class="info-item full-width">
                        <div class="info-label">User ID</div>
                        <div class="info-value"><?php echo $current_user_details['id']; ?></div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Notification Panel -->
<div class="notification-panel" id="notificationPanel">
    <div class="notification-header">
        <h4><i class="icon-bell"></i> Notifications</h4>
        <?php if (!empty($unread_notifications)): ?>
        <button class="mark-all-read" onclick="markAllRead(event)">Mark all as read</button>
        <?php endif; ?>
    </div>
    <div class="notification-list" id="notificationList">
        <?php if (!empty($unread_notifications)): ?>
            <?php foreach ($unread_notifications as $notification): ?>
            <div class="notification-item unread" onclick="markAsRead(<?php echo $notification['id']; ?>, this)">
                <div style="display: flex; align-items: center;">
                    <?php
                    $icon_class = '';
                    if (strpos($notification['type'], 'leave_approved') !== false) $icon_class = 'leave-approved';
                    elseif (strpos($notification['type'], 'leave_rejected') !== false) $icon_class = 'leave-rejected';
                    elseif (strpos($notification['type'], 'leave_deleted') !== false) $icon_class = 'leave-deleted';
                    elseif (strpos($notification['type'], 'permission_approved') !== false) $icon_class = 'permission-approved';
                    elseif (strpos($notification['type'], 'permission_rejected') !== false) $icon_class = 'permission-rejected';
                    elseif (strpos($notification['type'], 'lop_approved') !== false) $icon_class = 'lop-approved';
                    elseif (strpos($notification['type'], 'lop_rejected') !== false) $icon_class = 'lop-rejected';
                    elseif (strpos($notification['type'], 'pending_leaves') !== false) $icon_class = 'pending-leaves';
                    elseif (strpos($notification['type'], 'pending_permissions') !== false) $icon_class = 'pending-permissions';
                    elseif (strpos($notification['type'], 'late_timesheets') !== false) $icon_class = 'late-timesheets';
                    else $icon_class = 'leave-approved';
                    ?>
                    <span class="notification-icon <?php echo $icon_class; ?>">
                        <?php
                        if (strpos($notification['type'], 'approved') !== false) echo '✓';
                        elseif (strpos($notification['type'], 'rejected') !== false) echo '✗';
                        elseif (strpos($notification['type'], 'deleted') !== false) echo '🗑️';
                        elseif (strpos($notification['type'], 'pending') !== false) echo '⏳';
                        elseif (strpos($notification['type'], 'late') !== false) echo '⚠️';
                        else echo 'ℹ️';
                        ?>
                    </span>
                    <div class="notification-content">
                        <div class="notification-title"><?php echo htmlspecialchars($notification['title']); ?></div>
                        <div class="notification-message"><?php echo htmlspecialchars(mb_strimwidth($notification['message'], 0, 60, '…')); ?></div>
                        <div class="notification-time"><?php echo date('M j, Y g:i A', strtotime($notification['created_at'])); ?></div>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        <?php else: ?>
            <div class="no-notifications">
                <i class="icon-bell" style="font-size: 48px; color: #cbd5e0; margin-bottom: 10px; display: block;"></i>
                No new notifications
            </div>
        <?php endif; ?>
    </div>
    <div class="notification-footer">
        <a href="notifications.php">View all notifications</a>
    </div>
</div>

<?php if ($show_welcome): ?>
<script>
    setTimeout(function() {
        var overlay = document.getElementById('welcomeOverlay');
        if (overlay) {
            overlay.remove();
        }
    }, 5000);
</script>
<?php endif; ?>

<script>
function showUserModal() {
    document.getElementById('userModal').style.display = 'flex';
}

function closeUserModal() {
    document.getElementById('userModal').style.display = 'none';
}

window.onclick = function(event) {
    var modal = document.getElementById('userModal');
    if (event.target == modal) {
        closeUserModal();
    }
}

document.addEventListener('DOMContentLoaded', function() {
    var headerRoleBadge = document.querySelector('.user-role-badge');
    if (headerRoleBadge) {
        headerRoleBadge.style.cursor = 'pointer';
        headerRoleBadge.onclick = showUserModal;
    }
    
    // Initialize notification panel toggle
    var notificationBell = document.querySelector('.notification-bell');
    if (notificationBell) {
        notificationBell.addEventListener('click', function(e) {
            e.stopPropagation();
            toggleNotifications();
        });
    }
});

function closeNotificationPopup() {
    var popup = document.getElementById('notificationPopup');
    if (popup) {
        popup.style.display = 'none';
    }
}

setTimeout(function() {
    closeNotificationPopup();
}, 10000);

function toggleNotifications() {
    const panel = document.getElementById('notificationPanel');
    if (panel) {
        if (panel.style.display === 'none' || panel.style.display === '') {
            panel.style.display = 'block';
        } else {
            panel.style.display = 'none';
        }
    }
}

function markAsRead(notificationId, element) {
    fetch('ajax/mark_notification_read.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
        },
        body: JSON.stringify({ notification_id: notificationId })
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            element.classList.remove('unread');
            element.style.opacity = '0.7';
            
            // Remove the element
            element.remove();
            
            // Check if there are any unread notifications left
            const remainingUnread = document.querySelectorAll('.notification-item.unread').length;
            const markAllBtn = document.querySelector('.mark-all-read');
            
            if (remainingUnread === 0) {
                const list = document.getElementById('notificationList');
                if (list) {
                    list.innerHTML = '<div class="no-notifications"><i class="icon-bell" style="font-size: 48px; color: #cbd5e0; margin-bottom: 10px; display: block;"></i>No new notifications</div>';
                }
                if (markAllBtn) {
                    markAllBtn.style.display = 'none';
                }
            }
            
            // Update badge
            updateBadge();
        }
    })
    .catch(error => console.error('Error:', error));
}

function markAllRead(event) {
    event.preventDefault();
    event.stopPropagation();
    
    fetch('ajax/mark_all_notifications_read.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
        }
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            // Clear all unread items
            const list = document.getElementById('notificationList');
            if (list) {
                list.innerHTML = '<div class="no-notifications"><i class="icon-bell" style="font-size: 48px; color: #cbd5e0; margin-bottom: 10px; display: block;"></i>No new notifications</div>';
            }
            
            // Hide the mark all button
            const markAllBtn = document.querySelector('.mark-all-read');
            if (markAllBtn) {
                markAllBtn.style.display = 'none';
            }
            
            // Update badge
            updateBadge();
        }
    })
    .catch(error => console.error('Error:', error));
}

function updateBadge() {
    fetch('ajax/get_unread_count.php')
    .then(response => response.json())
    .then(data => {
        const badge = document.getElementById('notificationBadge');
        if (badge) {
            if (data.count > 0) {
                badge.textContent = data.count;
                badge.style.display = 'block';
            } else {
                badge.style.display = 'none';
            }
        }
    })
    .catch(error => console.error('Error:', error));
}

// Poll for new notifications every 30 seconds
setInterval(function() {
    updateBadge();
}, 30000);

// Request notification permission
if (Notification.permission !== 'denied' && Notification.permission !== 'granted') {
    Notification.requestPermission();
}

// Close notification panel when clicking outside
document.addEventListener('click', function(event) {
    const panel = document.getElementById('notificationPanel');
    const bell = document.querySelector('.notification-bell');
    
    if (panel && bell && !bell.contains(event.target) && !panel.contains(event.target)) {
        panel.style.display = 'none';
    }
});
</script>

<script src="assets/js/app.js"></script>
</body>
</html>