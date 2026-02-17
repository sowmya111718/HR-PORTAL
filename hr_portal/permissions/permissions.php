<?php
require_once '../config/db.php';

if (!isLoggedIn()) {
    header('Location: ../auth/login.php');
    exit();
}

$user_id = $_SESSION['user_id'];
$message = '';

// Apply permission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['apply_permission'])) {
    $permission_date = sanitize($_POST['permission_date']);
    $duration = floatval($_POST['duration']);
    $reason = sanitize($_POST['reason']);
    
    // Check for existing permission on same date
    $stmt = $conn->prepare("
        SELECT COUNT(*) as count 
        FROM permissions 
        WHERE user_id = ? 
        AND permission_date = ? 
        AND status IN ('Pending', 'Approved')
    ");
    $stmt->bind_param("is", $user_id, $permission_date);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    $stmt->close();
    
    if ($row['count'] > 0) {
        $message = '<div class="alert alert-error">You already have a permission request for this date</div>';
    } else {
        $stmt = $conn->prepare("
            INSERT INTO permissions (user_id, permission_date, duration, reason)
            VALUES (?, ?, ?, ?)
        ");
        $stmt->bind_param("isds", $user_id, $permission_date, $duration, $reason);
        
        if ($stmt->execute()) {
            $message = '<div class="alert alert-success">Permission request submitted successfully!</div>';
        } else {
            $message = '<div class="alert alert-error">Error submitting permission request</div>';
        }
        $stmt->close();
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
    </style>
</head>
<body>
    <?php include '../includes/header.php'; ?>
    
    <div class="app-main">
        <?php include '../includes/sidebar.php'; ?>
        
        <div class="main-content">
            <h2 class="page-title">Permission Management</h2>
            
            <?php echo $message; ?>
            
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
                            <select name="duration" class="form-control" required>
                                <option value="0.5">30 minutes</option>
                                <option value="1" selected>1 hour</option>
                                <option value="1.5">1.5 hours</option>
                                <option value="2">2 hours</option>
                                <option value="3">3 hours</option>
                                <option value="4">4 hours</option>
                                <option value="8">Full Day (8 hours)</option>
                            </select>
                        </div>
                    </div>
                    
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
                                <?php while ($permission = $permissions->fetch_assoc()): ?>
                                <tr>
                                    <td><?php echo date('M j, Y', strtotime($permission['permission_date'])); ?></td>
                                    <td>
                                        <?php 
                                        if ($permission['duration'] == 1) {
                                            echo "1 hour";
                                        } else if ($permission['duration'] < 1) {
                                            echo ($permission['duration'] * 60) . " min";
                                        } else if ($permission['duration'] == 8) {
                                            echo "Full Day";
                                        } else {
                                            echo $permission['duration'] . " hours";
                                        }
                                        ?>
                                    </td>
                                    <td>
                                        <span class="status-badge status-<?php echo strtolower($permission['status']); ?>">
                                            <?php echo $permission['status']; ?>
                                        </span>
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