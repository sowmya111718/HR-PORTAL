<?php
// File: includes/head.php
// Determine the base path
$base_path = '';
$current_file = $_SERVER['SCRIPT_NAME'];
if (strpos($current_file, '/admin/') !== false || 
    strpos($current_file, '/auth/') !== false || 
    strpos($current_file, '/hr/') !== false || 
    strpos($current_file, '/leaves/') !== false || 
    strpos($current_file, '/permissions/') !== false || 
    strpos($current_file, '/timesheet/') !== false || 
    strpos($current_file, '/attendance/') !== false || 
    strpos($current_file, '/users/') !== false) {
    $base_path = '../';
}

// Include icon functions
require_once(dirname(__FILE__) . '/icon_functions.php');
?>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?php echo isset($page_title) ? $page_title : 'MAKSIM HR'; ?></title>
<link rel="stylesheet" href="<?php echo $base_path; ?>assets/css/style.css">
<?php 
// Output the icon CSS
echo getIconCSS(); 
?>