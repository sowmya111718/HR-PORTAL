<!-- Sidebar -->
<div class="sidebar">
    <ul class="sidebar-nav">
        <li><a href="../dashboard.php" class="<?php echo basename($_SERVER['PHP_SELF']) == 'dashboard.php' ? 'active' : ''; ?>">
            <i class="fas fa-home"></i> Dashboard
        </a></li>
        <li><a href="../leaves/leaves.php" class="<?php echo basename($_SERVER['PHP_SELF']) == 'leaves.php' ? 'active' : ''; ?>">
            <i class="fas fa-umbrella-beach"></i> Leave Management
        </a></li>
        <li><a href="../permissions/permissions.php" class="<?php echo basename($_SERVER['PHP_SELF']) == 'permissions.php' ? 'active' : ''; ?>">
            <i class="fas fa-clock"></i> Permission Management
        </a></li>
        <li><a href="../timesheet/timesheet.php" class="<?php echo basename($_SERVER['PHP_SELF']) == 'timesheet.php' ? 'active' : ''; ?>">
            <i class="fas fa-calendar-alt"></i> Timesheet
        </a></li>
        <li><a href="../attendance/attendance.php" class="<?php echo basename($_SERVER['PHP_SELF']) == 'attendance.php' ? 'active' : ''; ?>">
            <i class="fas fa-calendar-check"></i> Attendance
        </a></li>
        
        <?php if (in_array($_SESSION['role'], ['hr', 'admin', 'pm'])): ?>
        <li><a href="../hr/panel.php" class="<?php echo basename($_SERVER['PHP_SELF']) == 'panel.php' ? 'active' : ''; ?>">
            <i class="fas fa-user-tie"></i> HR Panel
        </a></li>
        <!-- ADDED: Export Leaves Link -->
        <li><a href="../hr/export_leaves.php" class="<?php echo basename($_SERVER['PHP_SELF']) == 'export_leaves.php' ? 'active' : ''; ?>">
            <i class="fas fa-file-excel"></i> Export Leaves
        </a></li>
        <li><a href="../users/users.php" class="<?php echo basename($_SERVER['PHP_SELF']) == 'users.php' ? 'active' : ''; ?>">
            <i class="fas fa-user-cog"></i> User Management
        </a></li>
        <li><a href="../admin/settings.php" class="<?php echo basename($_SERVER['PHP_SELF']) == 'settings.php' ? 'active' : ''; ?>">
            <i class="fas fa-cog"></i> System Administration
        </a></li>
        <?php endif; ?>
        
        <?php if (in_array($_SESSION['role'], ['hr', 'admin'])): ?>
        <li><a href="../admin/reset_leave_year.php" class="<?php echo basename($_SERVER['PHP_SELF']) == 'reset_leave_year.php' ? 'active' : ''; ?>">
            <i class="fas fa-calendar-alt"></i> Reset Leave Year
        </a></li>
        <?php endif; ?>
        
        <li><a href="../auth/change_password.php" class="<?php echo basename($_SERVER['PHP_SELF']) == 'change_password.php' ? 'active' : ''; ?>">
            <i class="fas fa-key"></i> Change Password
        </a></li>
    </ul>
</div>