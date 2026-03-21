<?php
// File: includes/sidebar.php
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
$current_page = basename($_SERVER['SCRIPT_NAME']);
$role = strtolower($_SESSION['role'] ?? 'employee');

$today_str  = date('Y-m-d');
$next30_str = date('Y-m-d', strtotime('+30 days'));
$upcoming_holidays  = [];
$upcoming_birthdays = [];
if (isset($conn) && $conn) {
    $wish_exists = $conn->query("SHOW TABLES LIKE 'wish_holidays'")->num_rows > 0;
    $wu = $wish_exists ? " UNION ALL SELECT holiday_date, holiday_name, 'festival' as src FROM wish_holidays WHERE holiday_date > '$today_str' AND holiday_date <= '$next30_str'" : "";
    $hres = $conn->query("SELECT holiday_date, holiday_name, src FROM (SELECT holiday_date, holiday_name, 'company' as src FROM holidays WHERE holiday_date > '$today_str' AND holiday_date <= '$next30_str' $wu) c ORDER BY holiday_date ASC");
    if ($hres) {
        $seen = [];
        while ($hr = $hres->fetch_assoc()) {
            $key = $hr['holiday_date'].'|'.strtolower($hr['holiday_name']);
            if (!isset($seen[$key])) { $seen[$key]=true; $upcoming_holidays[]=$hr; }
        }
    }
    $bres = $conn->query("SELECT full_name, DATE_FORMAT(birthday,'%m-%d') as bday_md FROM users WHERE birthday IS NOT NULL AND status != 'inactive'");
    if ($bres) {
        while ($br = $bres->fetch_assoc()) {
            $bday = date('Y').'-'.$br['bday_md'];
            if ($bday <= $today_str) $bday = (date('Y')+1).'-'.$br['bday_md'];
            $diff = (int)((strtotime($bday)-strtotime($today_str))/86400);
            if ($diff > 0 && $diff <= 30)
                $upcoming_birthdays[] = ['full_name'=>$br['full_name'],'bday_date'=>$bday,'days_left'=>$diff];
        }
        usort($upcoming_birthdays, fn($a,$b)=>$a['days_left']-$b['days_left']);
    }
}

// Role-based panel label
$panel_labels = ['dm'=>'DM Panel','hr'=>'HR Panel','admin'=>'Admin Panel','coo'=>'COO Panel','ed'=>'ED Panel'];
$panel_label  = $panel_labels[$role] ?? 'HR Panel';
?>
<div class="sidebar">

    <!-- Brand -->
    <div style="padding:14px 18px 12px;border-bottom:1px solid #c7d2fe;margin-bottom:6px;">
        <div style="font-weight:800;font-size:16px;color:#4338ca;letter-spacing:0.3px;">&#127970; MAKSIM HR</div>
        <div style="font-size:11px;color:#6366f1;margin-top:2px;"><?php
            $rl = ['hr'=>'HR','admin'=>'Admin','dm'=>'Manager','coo'=>'COO','ed'=>'Director','MG'=>'Management'];
            echo ($rl[$role] ?? ucfirst($role)).' Portal';
        ?></div>
    </div>

    <ul class="sidebar-nav">
        <li style="padding:6px 14px 2px;font-size:10px;font-weight:700;color:#818cf8;text-transform:uppercase;letter-spacing:1.2px;">Main</li>
        <li><a href="<?php echo $base_path;?>dashboard.php" class="<?php echo $current_page=='dashboard.php'?'active':'';?>"><i class="icon-dashboard"></i> Dashboard</a></li>
        <li><a href="<?php echo $base_path;?>leaves/leaves.php" class="<?php echo $current_page=='leaves.php'?'active':'';?>"><i class="icon-leave"></i> Leave Management</a></li>
        <li><a href="<?php echo $base_path;?>permissions/permissions.php" class="<?php echo $current_page=='permissions.php'?'active':'';?>"><i class="icon-clock"></i> Permission Management</a></li>
        <li><a href="<?php echo $base_path;?>timesheet/timesheet.php" class="<?php echo $current_page=='timesheet.php'?'active':'';?>"><i class="icon-timesheet"></i> Timesheet</a></li>

        <?php if (in_array($role, ['hr','admin','dm','coo','ed'])): ?>
        <li style="padding:10px 14px 2px;font-size:10px;font-weight:700;color:#818cf8;text-transform:uppercase;letter-spacing:1.2px;margin-top:4px;">HR</li>
        <li><a href="<?php echo $base_path;?>hr/panel.php" class="<?php echo $current_page=='panel.php'?'active':'';?>"><i class="icon-hr"></i> <?php echo $panel_label; ?></a></li>
        <li><a href="<?php echo $base_path;?>hr/holiday_work.php" class="<?php echo $current_page=='holiday_work.php'?'active':'';?>">&#127881; Holiday Work +1 CL</a></li>
        <li><a href="<?php echo $base_path;?>hr/auto_lop.php" class="<?php echo $current_page=='auto_lop.php'?'active':'';?>">&#9888; Auto-Generated LOPs</a></li>
        <li><a href="<?php echo $base_path;?>users/users.php" class="<?php echo $current_page=='users.php'?'active':'';?>"><i class="icon-users"></i> User Management</a></li>
        <li style="padding:10px 14px 2px;font-size:10px;font-weight:700;color:#818cf8;text-transform:uppercase;letter-spacing:1.2px;margin-top:4px;">Admin</li>
        <li><a href="<?php echo $base_path;?>admin/settings.php" class="<?php echo $current_page=='settings.php'?'active':'';?>"><i class="icon-settings"></i> System Administration</a></li>
        <li><a href="<?php echo $base_path;?>admin/manage_holidays.php" class="<?php echo $current_page=='manage_holidays.php'?'active':'';?>"><i class="icon-calendar"></i> Manage Holidays</a></li>
        <?php endif; ?>
        <?php if (in_array($role, ['hr','admin'])): ?>
        <li><a href="<?php echo $base_path;?>admin/reset_leave_year.php" class="<?php echo $current_page=='reset_leave_year.php'?'active':'';?>"><i class="icon-calendar"></i> Reset Leave Year</a></li>
        <?php endif; ?>

        <li style="padding:10px 14px 2px;font-size:10px;font-weight:700;color:#818cf8;text-transform:uppercase;letter-spacing:1.2px;margin-top:4px;">Account</li>
        <li><a href="<?php echo $base_path;?>auth/change_password.php" class="<?php echo $current_page=='change_password.php'?'active':'';?>"><i class="icon-edit"></i> Change Password</a></li>
    </ul>

    <?php if (!empty($upcoming_birthdays) || !empty($upcoming_holidays)): ?>
    <div style="margin:10px 10px 14px;">

        <!-- NEXT 30 DAYS pill header — matches image style -->
        <div id="sidebar-panel-header" onclick="toggleSidebarPanel()"
             style="display:flex;align-items:center;justify-content:space-between;background:#ffffff;border:1px solid #c7d2fe;border-radius:8px;padding:8px 12px;cursor:pointer;user-select:none;box-shadow:0 1px 3px rgba(99,102,241,0.08);">
            <div style="display:flex;align-items:center;gap:7px;">
                <span style="font-size:14px;">&#128197;</span>
                <span style="font-weight:700;font-size:11px;color:#4338ca;text-transform:uppercase;letter-spacing:0.8px;">Next 30 Days</span>
            </div>
            <span id="sidebar-panel-chevron"
                  style="color:#6366f1;font-size:11px;background:#e0e7ff;border-radius:4px;padding:2px 7px;line-height:1.6;font-weight:700;">&#9660;</span>
        </div>

        <!-- Collapsible content -->
        <div id="sidebar-panel-body" style="margin-top:6px;background:#ffffff;border:1px solid #c7d2fe;border-radius:10px;padding:10px 13px;box-shadow:0 1px 4px rgba(99,102,241,0.08);">

            <?php if (!empty($upcoming_birthdays)): ?>
            <div style="margin-bottom:8px;">
                <div style="font-size:10px;font-weight:700;color:#818cf8;text-transform:uppercase;letter-spacing:0.5px;margin-bottom:5px;">&#127874; Birthdays</div>
                <?php foreach ($upcoming_birthdays as $b): ?>
                <div style="display:flex;justify-content:space-between;align-items:center;padding:5px 8px;background:#f5f3ff;border-radius:6px;margin-bottom:3px;border-left:3px solid #a78bfa;">
                    <div style="flex:1;min-width:0;">
                        <div style="font-weight:600;color:#3730a3;font-size:11.5px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;"><?php echo htmlspecialchars($b['full_name']);?></div>
                        <div style="color:#818cf8;font-size:10px;"><?php echo date('d M',strtotime($b['bday_date']));?></div>
                    </div>
                    <span style="background:#e0e7ff;color:#4338ca;padding:2px 7px;border-radius:8px;font-size:10px;font-weight:700;white-space:nowrap;margin-left:6px;"><?php echo $b['days_left']==1?'Tomorrow':"in {$b['days_left']}d";?></span>
                </div>
                <?php endforeach;?>
            </div>
            <?php endif;?>

            <?php if (!empty($upcoming_holidays)): ?>
            <div>
                <div style="font-size:10px;font-weight:700;color:#818cf8;text-transform:uppercase;letter-spacing:0.5px;margin-bottom:5px;">&#127958; Holidays &amp; Festivals</div>
                <?php foreach ($upcoming_holidays as $h):
                    $dl    = (int)((strtotime($h['holiday_date'])-strtotime($today_str))/86400);
                    $is_co = ($h['src']==='company');
                    $lbl   = $is_co?'Holiday':'Festival';
                    $bdr   = $is_co?'#6366f1':'#818cf8';
                ?>
                <div style="display:flex;justify-content:space-between;align-items:center;padding:5px 8px;background:#f5f3ff;border-radius:6px;margin-bottom:3px;border-left:3px solid <?php echo $bdr;?>;">
                    <div style="flex:1;min-width:0;">
                        <div style="font-weight:600;color:#3730a3;font-size:11.5px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;"><?php echo htmlspecialchars($h['holiday_name']);?></div>
                        <div style="color:#818cf8;font-size:10px;"><?php echo date('d M',strtotime($h['holiday_date']));?> &middot; <?php echo date('D',strtotime($h['holiday_date']));?> &middot; <span style="color:<?php echo $bdr;?>;"><?php echo $lbl;?></span></div>
                    </div>
                    <span style="background:#e0e7ff;color:#4338ca;padding:2px 7px;border-radius:8px;font-size:10px;font-weight:700;white-space:nowrap;margin-left:6px;"><?php echo $dl==1?'Tomorrow':"in {$dl}d";?></span>
                </div>
                <?php endforeach;?>
            </div>
            <?php endif;?>

        </div><!-- end panel-body -->
    </div><!-- end panel wrap -->

    <script>
    (function(){
        var KEY    = 'sidebar_panel_collapsed';
        var body   = document.getElementById('sidebar-panel-body');
        var chev   = document.getElementById('sidebar-panel-chevron');
        function setCollapsed(c){
            body.style.display = c ? 'none' : '';
            chev.innerHTML     = c ? '&#9650;' : '&#9660;';
            try{ localStorage.setItem(KEY, c ? '1' : '0'); }catch(e){}
        }
        try{ if(localStorage.getItem(KEY)==='1') setCollapsed(true); }catch(e){}
        window.toggleSidebarPanel = function(){ setCollapsed(body.style.display !== 'none'); };
    })();
    </script>
    <?php endif;?>

</div>