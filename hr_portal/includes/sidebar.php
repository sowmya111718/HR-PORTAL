<?php
// File: includes/sidebar.php
// Determine the base path
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

// Get current page for active class
$current_page = basename($_SERVER['SCRIPT_NAME']);
$role = $_SESSION['role'] ?? 'employee';
?>
<!-- Sidebar -->
<div class="sidebar">
    <ul class="sidebar-nav">
        <li><a href="<?php echo $base_path; ?>dashboard.php" class="<?php echo $current_page == 'dashboard.php' ? 'active' : ''; ?>">
            <i class="icon-dashboard"></i> Dashboard
        </a></li>
        <li><a href="<?php echo $base_path; ?>leaves/leaves.php" class="<?php echo $current_page == 'leaves.php' ? 'active' : ''; ?>">
            <i class="icon-leave"></i> Leave Management
        </a></li>
        <li><a href="<?php echo $base_path; ?>permissions/permissions.php" class="<?php echo $current_page == 'permissions.php' ? 'active' : ''; ?>">
            <i class="icon-clock"></i> Permission Management
        </a></li>
        <li><a href="<?php echo $base_path; ?>timesheet/timesheet.php" class="<?php echo $current_page == 'timesheet.php' ? 'active' : ''; ?>">
            <i class="icon-timesheet"></i> Timesheet
        </a></li>
        <!-- REMOVED: Attendance link -->
        
        <?php if (in_array($role, ['hr', 'admin', 'pm'])): ?>
        <li><a href="<?php echo $base_path; ?>hr/panel.php" class="<?php echo $current_page == 'panel.php' ? 'active' : ''; ?>">
            <i class="icon-hr"></i> HR Panel
        </a></li>
        <li><a href="<?php echo $base_path; ?>hr/export_leaves.php" class="<?php echo $current_page == 'export_leaves.php' ? 'active' : ''; ?>">
            <i class="icon-excel"></i> Export Leaves
        </a></li>
        <li><a href="<?php echo $base_path; ?>users/users.php" class="<?php echo $current_page == 'users.php' ? 'active' : ''; ?>">
            <i class="icon-users"></i> User Management
        </a></li>
        <li><a href="<?php echo $base_path; ?>admin/settings.php" class="<?php echo $current_page == 'settings.php' ? 'active' : ''; ?>">
            <i class="icon-settings"></i> System Administration
        </a></li>
        <?php endif; ?>
        
        <?php if (in_array($role, ['hr', 'admin'])): ?>
        <li><a href="<?php echo $base_path; ?>admin/reset_leave_year.php" class="<?php echo $current_page == 'reset_leave_year.php' ? 'active' : ''; ?>">
            <i class="icon-calendar"></i> Reset Leave Year
        </a></li>
        <?php endif; ?>
        
        <li><a href="<?php echo $base_path; ?>auth/change_password.php" class="<?php echo $current_page == 'change_password.php' ? 'active' : ''; ?>">
            <i class="icon-edit"></i> Change Password
        </a></li>
    </ul>
</div>