<?php if (!isset($no_header)): ?>
<!-- Header -->
<div class="app-header">
    <div style="display: flex; align-items: center;">
        <!-- Logo image only - no icon -->
        <img src="../assets/images/maksim_infotech_logo.png" alt="MAKSIM Infotech" height="40" style="margin-right: 10px;">
        
        <!-- Title without icon -->
        <h1 style="margin: 0; font-size: 24px;">MAKSIM HR System</h1>
    </div>
    <div class="user-info">
        <div class="user-label <?php echo $_SESSION['role'] === 'hr' ? 'hr' : ($_SESSION['role'] === 'pm' ? 'pm' : ''); ?>">
            <i class="fas fa-user"></i> <?php echo $_SESSION['full_name']; ?>
            <span class="user-role-badge role-<?php echo $_SESSION['role']; ?>">
                <?php echo strtoupper($_SESSION['role']); ?>
            </span>
        </div>
        <a href="../auth/logout.php" class="logout-btn">
            <i class="fas fa-sign-out-alt"></i> Logout
        </a>
    </div>
</div>
<?php endif; ?>