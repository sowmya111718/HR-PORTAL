<?php
// File: includes/header.php
if (!isset($no_header)):

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
?>
<!-- Header -->
<div class="app-header">
    <div style="display: flex; align-items: center;">
        <img src="<?php echo $base_path; ?>assets/images/maksim_infotech_logo.png" alt="MAKSIM Infotech" height="40" style="margin-right: 10px;">
        <h1 style="margin: 0; font-size: 24px;">MAKSIM PORTAL</h1>
    </div>
    <div class="user-info">
        <div class="user-label <?php echo $_SESSION['role'] === 'hr' ? 'hr' : ($_SESSION['role'] === 'pm' ? 'pm' : ''); ?>">
            <i class="icon-user"></i> <?php echo $_SESSION['full_name']; ?>
            <span class="user-role-badge role-<?php echo $_SESSION['role']; ?>">
                <?php echo strtoupper($_SESSION['role']); ?>
            </span>
        </div>
        <a href="<?php echo $base_path; ?>auth/logout.php" class="logout-btn">
            <i class="icon-logout"></i> Logout
        </a>
    </div>
</div>
<?php endif; ?>