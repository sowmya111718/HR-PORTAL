<?php
require_once '../config/db.php';
require_once '../includes/leave_functions.php'; // For LOP functions

if (!isLoggedIn()) {
    header('Location: ../auth/login.php');
    exit();
}

$user_id = $_SESSION['user_id'];
$message = '';

// Get current month and year for permission limit check
$current_month = date('Y-m');
$current_month_start = $current_month . '-01';
$current_month_end = date('Y-m-t', strtotime($current_month_start));

// Function to get total permission hours used in current month
function getMonthlyPermissionHours($conn, $user_id) {
    $current_month = date('Y-m');
    $month_start = $current_month . '-01';
    $month_end = date('Y-m-t', strtotime($month_start));
    
    $stmt = $conn->prepare("
        SELECT COALESCE(SUM(duration), 0) as total_hours 
        FROM permissions 
        WHERE user_id = ? 
        AND status IN ('Approved', 'Pending')
        AND permission_date BETWEEN ? AND ?
    ");
    $stmt->bind_param("iss", $user_id, $month_start, $month_end);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    $stmt->close();
    
    return floatval($row['total_hours']);
}

// Apply permission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['apply_permission'])) {
    $permission_date = sanitize($_POST['permission_date']);
    $duration = floatval($_POST['duration']);
    $reason = sanitize($_POST['reason']);
    
    // Check if the date is valid
    $date_timestamp = strtotime($permission_date);
    if ($date_timestamp === false) {
        $message = '<div class="alert alert-error">Invalid date format. Please use YYYY-MM-DD format</div>';
    } else {
        $formatted_date = date('Y-m-d', $date_timestamp);
        
        // Check for existing permission on same date
        $stmt = $conn->prepare("
            SELECT COUNT(*) as count 
            FROM permissions 
            WHERE user_id = ? 
            AND permission_date = ? 
            AND status IN ('Pending', 'Approved')
        ");
        $stmt->bind_param("is", $user_id, $formatted_date);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        $stmt->close();
        
        if ($row['count'] > 0) {
            $message = '<div class="alert alert-error">You already have a permission request for this date</div>';
        } else {
            // Check monthly permission limit (4 hours max)
            $used_hours = getMonthlyPermissionHours($conn, $user_id);
            $remaining_hours = 4 - $used_hours;
            
            if ($duration > $remaining_hours) {
                // Not enough permission hours left - excess becomes LOP
                $lop_hours = $duration - $remaining_hours;
                
                // Start transaction
                $conn->begin_transaction();
                
                try {
                    // Insert permission for remaining hours - with PENDING status
                    if ($remaining_hours > 0) {
                        $stmt1 = $conn->prepare("
                            INSERT INTO permissions (user_id, permission_date, duration, reason, status)
                            VALUES (?, ?, ?, ?, 'Pending')
                        ");
                        $permission_reason = $reason . " (Partial - within 4hr limit)";
                        $stmt1->bind_param("isds", $user_id, $formatted_date, $remaining_hours, $permission_reason);
                        $stmt1->execute();
                        $stmt1->close();
                    }
                    
                    // Insert LOP for excess hours - stored in permissions table, auto-approved
                    if ($lop_hours > 0) {
                        $lop_reason = $reason . " (Excess " . $lop_hours . "hr - LOP, 4hr monthly limit exceeded)";

                        // Ensure lop_hours column exists (add it if not)
                        $conn->query("ALTER TABLE permissions ADD COLUMN IF NOT EXISTS lop_hours DECIMAL(4,1) DEFAULT NULL");

                        // Ensure LOP is a valid status (handle ENUM or VARCHAR)
                        $col_info = $conn->query("SHOW COLUMNS FROM permissions LIKE 'status'");
                        if ($col_info && $col_row = $col_info->fetch_assoc()) {
                            if (strpos($col_row['Type'], 'enum') !== false && strpos($col_row['Type'], "'LOP'") === false) {
                                // Add LOP to the enum
                                $new_type = str_replace(")", ",'LOP')", $col_row['Type']);
                                $conn->query("ALTER TABLE permissions MODIFY COLUMN status $new_type NOT NULL DEFAULT 'Pending'");
                            }
                        }

                        $stmt2 = $conn->prepare("
                            INSERT INTO permissions (user_id, permission_date, duration, reason, status, lop_hours)
                            VALUES (?, ?, ?, ?, 'LOP', ?)
                        ");
                        $stmt2->bind_param("isdsd", $user_id, $formatted_date, $lop_hours, $lop_reason, $lop_hours);
                        $stmt2->execute();
                        $stmt2->close();
                    }
                    
                    $conn->commit();
                    
                    if ($remaining_hours > 0 && $lop_hours > 0) {
                        $message = '<div class="alert alert-warning" style="background: #fff5f5; border-left-color: #c53030;">
                            <i class="fas fa-exclamation-triangle" style="color: #c53030;"></i> 
                            <strong>Partial Submission with LOP!</strong><br>
                            You have used ' . $used_hours . ' of 4 monthly permission hours.<br>
                            ' . $remaining_hours . ' hours submitted as permission (pending approval).<br>
                            ' . $lop_hours . ' hours converted to LOP (Loss of Pay) as unpaid leave (auto-approved).
                        </div>';
                    } else if ($remaining_hours > 0) {
                        $message = '<div class="alert alert-success">Permission request submitted successfully! ' . $remaining_hours . ' hours (pending approval).</div>';
                    } else {
                        $message = '<div class="alert alert-warning" style="background: #fff5f5; border-left-color: #c53030;">
                            <i class="fas fa-exclamation-triangle" style="color: #c53030;"></i> 
                            <strong>Fully Converted to LOP!</strong><br>
                            You have used all 4 monthly permission hours.<br>
                            All ' . $duration . ' hours converted to LOP (Loss of Pay) as unpaid leave (auto-approved).
                        </div>';
                    }
                    
                } catch (Exception $e) {
                    $conn->rollback();
                    $message = '<div class="alert alert-error">Error processing request: ' . $e->getMessage() . '</div>';
                }
                
            } else {
                // Enough permission hours left - insert normally with PENDING status
                $stmt = $conn->prepare("
                    INSERT INTO permissions (user_id, permission_date, duration, reason, status)
                    VALUES (?, ?, ?, ?, 'Pending')
                ");
                $stmt->bind_param("isds", $user_id, $formatted_date, $duration, $reason);
                
                if ($stmt->execute()) {
                    $message = '<div class="alert alert-success">Permission request submitted successfully! (' . $duration . ' hours) - Pending approval.</div>';
                } else {
                    $message = '<div class="alert alert-error">Error submitting permission request</div>';
                }
                $stmt->close();
            }
        }
    }
}

// Cancel permission
if (isset($_GET['cancel'])) {
    $permission_id = intval($_GET['cancel']);
    
    $stmt = $conn->prepare("
        UPDATE permissions 
        SET status = 'Cancelled' 
        WHERE id = ? AND user_id = ? AND status = 'Pending'
    ");
    $stmt->bind_param("ii", $permission_id, $user_id);
    
    if ($stmt->execute()) {
        $message = '<div class="alert alert-success">Permission request cancelled successfully</div>';
    } else {
        $message = '<div class="alert alert-error">Error cancelling permission request</div>';
    }
    $stmt->close();
}

// Get user's permissions
$stmt = $conn->prepare("
    SELECT * FROM permissions 
    WHERE user_id = ? 
    ORDER BY applied_date DESC
");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$permissions = $stmt->get_result();
$stmt->close();

// Get monthly usage statistics
$used_hours = getMonthlyPermissionHours($conn, $user_id);
$remaining_hours = max(0, 4 - $used_hours);
$current_month_name = date('F Y');
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Permission Management - MAKSIM HR</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="../assets/css/style.css">
    <!-- Add Flatpickr CSS with multiple CDN options -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
    <link rel="stylesheet" href="https://unpkg.com/flatpickr/dist/flatpickr.min.css">
    <style>
        .flatpickr-input {
            background-color: white;
            cursor: pointer;
        }
        .date-input-container {
            position: relative;
        }
        .date-input-container i {
            position: absolute;
            right: 10px;
            top: 35px;
            color: #666;
            pointer-events: none;
        }
        .native-date-fallback {
            display: none;
        }
        .flatpickr-fallback {
            display: block;
        }
        @media (max-width: 768px) {
            .flatpickr-fallback {
                display: none;
            }
            .native-date-fallback {
                display: block;
            }
        }
        /* Permission limit card styles */
        .permission-limit-card {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 20px;
            border-radius: 10px;
            margin-bottom: 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 15px;
        }
        .limit-stats {
            display: flex;
            gap: 30px;
            flex-wrap: wrap;
        }
        .limit-stat {
            text-align: center;
        }
        .limit-stat-value {
            font-size: 32px;
            font-weight: bold;
        }
        .limit-stat-label {
            font-size: 12px;
            opacity: 0.8;
        }
        .limit-progress {
            width: 200px;
            height: 8px;
            background: rgba(255,255,255,0.2);
            border-radius: 4px;
            overflow: hidden;
        }
        .limit-progress-fill {
            height: 100%;
            background: #48bb78;
            transition: width 0.3s ease;
        }
        .warning-message {
            background: #fff5f5;
            border-left: 4px solid #c53030;
            padding: 15px;
            border-radius: 8px;
            margin: 15px 0;
            color: #742a2a;
        }
        .info-message {
            background: #ebf8ff;
            border-left: 4px solid #4299e1;
            padding: 15px;
            border-radius: 8px;
            margin: 15px 0;
        }
    </style>
</head>
<body>
    <?php include '../includes/header.php'; ?>
    
    <div class="app-main">
        <?php include '../includes/sidebar.php'; ?>
        
        <div class="main-content">
            <h2 class="page-title">Permission Management</h2>
            
            <?php echo $message; ?>
            
            <!-- Permission Limit Card -->
            <div class="permission-limit-card">
                <div>
                    <i class="fas fa-clock"></i> 
                    <strong>Monthly Permission Limit:</strong> 4 hours per month
                </div>
                <div class="limit-stats">
                    <div class="limit-stat">
                        <div class="limit-stat-value"><?php echo $used_hours; ?></div>
                        <div class="limit-stat-label">Hours Used</div>
                    </div>
                    <div class="limit-stat">
                        <div class="limit-stat-value"><?php echo $remaining_hours; ?></div>
                        <div class="limit-stat-label">Hours Remaining</div>
                    </div>
                    <div class="limit-stat">
                        <div class="limit-progress">
                            <div class="limit-progress-fill" style="width: <?php echo min(100, ($used_hours / 4) * 100); ?>%"></div>
                        </div>
                        <div class="limit-stat-label"><?php echo $current_month_name; ?></div>
                    </div>
                </div>
            </div>
            
            <!-- Warning if limit reached -->
            <?php if ($used_hours >= 4): ?>
            <div class="warning-message">
                <i class="fas fa-exclamation-triangle" style="color: #c53030;"></i> 
                <strong>⚠️ Monthly Permission Limit Reached!</strong><br>
                You have used all 4 hours of your permission quota for <?php echo $current_month_name; ?>.
                Any additional permission requests will automatically be converted to 
                <span style="color: #c53030; font-weight: 600;">Loss of Pay (LOP) - Unpaid Leave</span>.
            </div>
            <?php elseif ($remaining_hours > 0 && $remaining_hours < 2): ?>
            <div class="info-message">
                <i class="fas fa-info-circle" style="color: #4299e1;"></i> 
                <strong>Low Permission Balance:</strong> You have only <?php echo $remaining_hours; ?> hour(s) remaining for <?php echo $current_month_name; ?>.
                Any excess hours will be converted to LOP.
            </div>
            <?php endif; ?>
            
            <!-- Apply Permission Form -->
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title"><i class="fas fa-plus-circle"></i> Apply for Permission</h3>
                </div>
                <form method="POST" action="">
                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label">Date *</label>
                            <div class="date-input-container">
                                <!-- Flatpickr version (for desktop/browsers with JS) -->
                                <input type="text" 
                                       name="permission_date" 
                                       id="permission_date" 
                                       class="form-control flatpickr-input flatpickr-fallback" 
                                       required 
                                       readonly
                                       placeholder="Click to select date"
                                       value="<?php echo date('Y-m-d'); ?>">
                                <i class="fas fa-calendar-alt"></i>
                                
                                <!-- Native date fallback (for mobile/browsers without JS) -->
                                <input type="date" 
                                       name="permission_date_native" 
                                       id="permission_date_native" 
                                       class="form-control native-date-fallback" 
                                       style="display: none;"
                                       value="<?php echo date('Y-m-d'); ?>">
                            </div>
                            <small class="form-text">Select any date</small>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Duration (hours) *</label>
                            <select name="duration" id="duration_select" class="form-control" required onchange="updateDurationInfo()">
                                <option value="0.5">30 minutes</option>
                                <option value="1" selected>1 hour</option>
                                <option value="1.5">1.5 hours</option>
                                <option value="2">2 hours</option>
                                <option value="3">3 hours</option>
                                <option value="4">4 hours</option>
                                <option value="5">5 hours (will exceed limit)</option>
                                <option value="6">6 hours (will exceed limit)</option>
                                <option value="7">7 hours (will exceed limit)</option>
                                <option value="8">Full Day (8 hours)</option>
                            </select>
                        </div>
                    </div>
                    
                    <div id="duration_info" style="margin-bottom: 15px;"></div>
                    
                    <div class="form-group">
                        <label class="form-label">Reason *</label>
                        <textarea name="reason" class="form-control" rows="3" required placeholder="Enter detailed reason for permission"></textarea>
                    </div>
                    
                    <button type="submit" name="apply_permission" class="btn">Apply Permission</button>
                </form>
            </div>

            <!-- My Permission Requests -->
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title"><i class="fas fa-list"></i> My Permission Requests</h3>
                </div>
                <div class="table-container">
                    <table>
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Duration</th>
                                <th>Status</th>
                                <th>Reason</th>
                                <th>Applied Date</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($permissions->num_rows > 0): ?>
                                <?php while ($permission = $permissions->fetch_assoc()): 
                                    $is_lop = ($permission['status'] === 'LOP');
                                ?>
                                <tr style="<?php echo $is_lop ? 'background:#fff5f5;' : ''; ?>">
                                    <td><?php echo date('M j, Y', strtotime($permission['permission_date'])); ?></td>
                                    <td>
                                        <?php 
                                        $dur = floatval($permission['duration']);
                                        if ($dur == 1) echo "1 hour";
                                        elseif ($dur < 1) echo ($dur * 60) . " min";
                                        elseif ($dur == 8) echo "Full Day";
                                        else echo $dur . " hours";
                                        ?>
                                        <?php if ($is_lop): ?>
                                            <span style="display:inline-block; background:#c53030; color:white; font-size:10px; font-weight:700; padding:1px 7px; border-radius:10px; margin-left:5px;">LOP</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($is_lop): ?>
                                            <span class="status-badge" style="background:#fed7d7; color:#c53030;">LOP</span>
                                        <?php else: ?>
                                            <span class="status-badge status-<?php echo strtolower($permission['status']); ?>">
                                                <?php echo $permission['status']; ?>
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                    <td title="<?php echo htmlspecialchars($permission['reason']); ?>">
                                        <?php echo strlen($permission['reason']) > 50 ? substr($permission['reason'], 0, 50) . '...' : $permission['reason']; ?>
                                    </td>
                                    <td><?php echo date('M j, Y', strtotime($permission['applied_date'])); ?></td>
                                    <td>
                                        <div class="action-buttons">
                                            <?php if ($permission['status'] === 'Pending'): ?>
                                                <a href="?cancel=<?php echo $permission['id']; ?>" 
                                                   class="btn-small btn-cancel"
                                                   onclick="return confirm('Are you sure you want to cancel this permission request?')">
                                                    <i class="fas fa-times"></i> Cancel
                                                </a>
                                            <?php endif; ?>
                                            <button class="btn-small btn-view" onclick="viewPermissionDetails(<?php echo $permission['id']; ?>)">
                                                <i class="fas fa-eye"></i> View
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="6" style="text-align: center; padding: 40px; color: #718096;">
                                        No permission requests found
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- Add Flatpickr JS with multiple CDN options and local fallback -->
    <script>
    // Function to load Flatpickr from multiple sources
    function loadFlatpickr() {
        const sources = [
            'https://cdn.jsdelivr.net/npm/flatpickr',
            'https://unpkg.com/flatpickr',
            '../assets/js/flatpickr.min.js' // Local fallback
        ];
        
        let currentSource = 0;
        
        function tryLoad(source) {
            return new Promise((resolve, reject) => {
                const script = document.createElement('script');
                script.src = source;
                script.onload = resolve;
                script.onerror = reject;
                document.head.appendChild(script);
            });
        }
        
        function attemptLoad() {
            if (currentSource >= sources.length) {
                // All sources failed, use native date input
                console.log('Flatpickr failed to load, using native date input');
                useNativeDateInput();
                return;
            }
            
            tryLoad(sources[currentSource])
                .then(() => {
                    console.log('Flatpickr loaded from:', sources[currentSource]);
                    initializeFlatpickr();
                })
                .catch(() => {
                    console.log('Failed to load from:', sources[currentSource]);
                    currentSource++;
                    attemptLoad();
                });
        }
        
        attemptLoad();
    }
    
    // Initialize Flatpickr if loaded
    function initializeFlatpickr() {
        if (typeof flatpickr === 'undefined') {
            useNativeDateInput();
            return;
        }
        
        try {
            flatpickr("#permission_date", {
                dateFormat: "Y-m-d",
                allowInput: false,
                clickOpens: true,
                onReady: function(selectedDates, dateStr, instance) {
                    // Hide native input
                    document.getElementById('permission_date_native').style.display = 'none';
                }
            });
            
            // Sync values between flatpickr and native input
            document.getElementById('permission_date').addEventListener('change', function() {
                document.getElementById('permission_date_native').value = this.value;
            });
            
            document.getElementById('permission_date_native').addEventListener('change', function() {
                document.getElementById('permission_date').value = this.value;
            });
            
        } catch (error) {
            console.error('Error initializing Flatpickr:', error);
            useNativeDateInput();
        }
    }
    
    // Fallback to native date input
    function useNativeDateInput() {
        const flatpickrInput = document.getElementById('permission_date');
        const nativeInput = document.getElementById('permission_date_native');
        
        // Hide flatpickr input, show native
        flatpickrInput.style.display = 'none';
        nativeInput.style.display = 'block';
        nativeInput.name = 'permission_date'; // Use the correct name for form submission
        
        // Remove readonly attribute for better compatibility
        flatpickrInput.removeAttribute('readonly');
    }
    
    // Check if browser supports native date input
    function supportsDateInput() {
        const input = document.createElement('input');
        input.setAttribute('type', 'date');
        return input.type === 'date';
    }
    
    // Update duration info based on selection
    function updateDurationInfo() {
        const duration = parseFloat(document.getElementById('duration_select').value);
        const usedHours = <?php echo $used_hours; ?>;
        const remainingHours = <?php echo $remaining_hours; ?>;
        const infoDiv = document.getElementById('duration_info');
        
        if (duration > remainingHours) {
            const excessHours = duration - remainingHours;
            const lopDays = (excessHours / 8).toFixed(2);
            infoDiv.innerHTML = `
                <div class="alert alert-warning" style="background: #fff5f5; border-left-color: #c53030;">
                    <i class="fas fa-exclamation-triangle" style="color: #c53030;"></i> 
                    <strong>⚠️ Monthly Limit Exceeded!</strong><br>
                    You have only ${remainingHours} hour(s) remaining this month.<br>
                    <strong>${remainingHours} hour(s)</strong> will be submitted as permission (pending approval).<br>
                    <strong>${excessHours} hour(s) (${lopDays} days)</strong> will be converted to 
                    <span style="color: #c53030; font-weight: 600;">Loss of Pay (LOP) - Unpaid Leave (auto-approved)</span>.
                </div>
            `;
        } else {
            infoDiv.innerHTML = `
                <div class="alert alert-info" style="background: #f0fff4; border-left-color: #48bb78;">
                    <i class="fas fa-check-circle" style="color: #48bb78;"></i> 
                    <strong>Within Monthly Limit</strong><br>
                    You have ${remainingHours} hour(s) remaining this month.<br>
                    This ${duration} hour(s) request will be submitted as permission (pending approval).
                </div>
            `;
        }
    }
    
    // Initialize on page load
    document.addEventListener('DOMContentLoaded', function() {
        // Check browser support first
        if (!supportsDateInput()) {
            // Browser doesn't support native date input, try Flatpickr
            loadFlatpickr();
        } else {
            // Browser supports native date input, check screen size
            if (window.innerWidth <= 768) {
                // On mobile, prefer native date input
                useNativeDateInput();
            } else {
                // On desktop, try Flatpickr first
                loadFlatpickr();
            }
        }
        
        // Initialize duration info
        updateDurationInfo();
    });
    
    function viewPermissionDetails(id) {
        window.location.href = 'permission_details.php?id=' + id;
    }
    </script>
    
    <!-- Local fallback for Flatpickr (optional - you can download from https://flatpickr.js.org/getting-started/) -->
    <!-- <script src="../assets/js/flatpickr.min.js"></script> -->
    
    <script src="../assets/js/app.js"></script>
</body>
</html>