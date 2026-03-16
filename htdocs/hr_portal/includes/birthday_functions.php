<?php
/**
 * Birthday Functions for MAKSIM HR
 * Handles birthday-related functionality
 */

function isUserBirthdayToday($conn, $user_id) {
    $stmt = $conn->prepare("
        SELECT birthday FROM users 
        WHERE id = ? AND birthday IS NOT NULL
    ");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($row = $result->fetch_assoc()) {
        $birthday = $row['birthday'];
        $today = date('m-d');
        $birthday_date = date('m-d', strtotime($birthday));
        $stmt->close();
        return ($birthday_date == $today);
    }
    $stmt->close();
    return false;
}

function getTodaysBirthdays($conn) {
    $today = date('m-d');
    $users = [];
    
    $stmt = $conn->prepare("
        SELECT id, username, full_name, email, role, birthday,
               department, position
        FROM users 
        WHERE birthday IS NOT NULL 
        AND DATE_FORMAT(birthday, '%m-%d') = ?
        ORDER BY full_name
    ");
    $stmt->bind_param("s", $today);
    $stmt->execute();
    $result = $stmt->get_result();
    
    while ($row = $result->fetch_assoc()) {
        $users[] = $row;
    }
    $stmt->close();
    
    return $users;
}

function getUpcomingBirthdays($conn, $days = 30) {
    $users = [];
    
    $stmt = $conn->prepare("
        SELECT id, username, full_name, email, role, birthday,
               department, position,
               DATE_FORMAT(birthday, '%m-%d') as birthday_md,
               DATEDIFF(
                   CONCAT(YEAR(CURDATE()), DATE_FORMAT(birthday, '-%m-%d')),
                   CURDATE()
               ) as days_until
        FROM users 
        WHERE birthday IS NOT NULL
        HAVING (days_until BETWEEN 1 AND ?) 
           OR (days_until < 0 AND days_until + 365 BETWEEN 1 AND ?)
        ORDER BY 
            CASE 
                WHEN days_until > 0 THEN days_until
                ELSE days_until + 365
            END
        LIMIT 20
    ");
    $stmt->bind_param("ii", $days, $days);
    $stmt->execute();
    $result = $stmt->get_result();
    
    while ($row = $result->fetch_assoc()) {
        $users[] = $row;
    }
    $stmt->close();
    
    return $users;
}

function getAgeFromBirthday($birthday) {
    if (empty($birthday)) return null;
    
    $bday = new DateTime($birthday);
    $today = new DateTime();
    $age = $today->diff($bday)->y;
    
    return $age;
}

function formatBirthday($birthday, $show_age = true) {
    if (empty($birthday)) return '-';
    
    $date = new DateTime($birthday);
    $formatted = $date->format('d M');
    
    if ($show_age) {
        $age = getAgeFromBirthday($birthday);
        if ($age !== null) {
            $formatted .= " ($age years)";
        }
    }
    
    return $formatted;
}

function getBirthdayCelebrationData($conn) {
    $today_birthdays = getTodaysBirthdays($conn);
    
    if (empty($today_birthdays)) {
        return [
            'has_birthday' => false,
            'message' => '',
            'users' => []
        ];
    }
    
    $names = array_column($today_birthdays, 'full_name');
    
    if (count($today_birthdays) == 1) {
        $message = "🎉 Happy Birthday to {$names[0]}! 🎉";
    } else {
        $last_name = array_pop($names);
        $name_list = implode(', ', $names) . " and {$last_name}";
        $message = "🎉 Happy Birthday to {$name_list}! 🎉";
    }
    
    return [
        'has_birthday' => true,
        'message' => $message,
        'users' => $today_birthdays
    ];
}

function ensureBirthdayColumnExists($conn) {
    $check = $conn->query("SHOW COLUMNS FROM users LIKE 'birthday'");
    if ($check->num_rows == 0) {
        $alter = $conn->query("ALTER TABLE users ADD COLUMN birthday DATE NULL AFTER join_date");
        return $alter ? true : false;
    }
    return true;
}
?>