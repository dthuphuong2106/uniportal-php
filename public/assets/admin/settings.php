<?php
/**
 * UniPortal – Settings redirect to config.php
 * The admin nav links 'settings' to config.php which has the full settings UI.
 */
$root = dirname(__DIR__, 3);
require_once $root . '/src/src/helpers/auth.php';

require_login();
if ($_SESSION['role'] === 'Student')  { header('Location: /UniPortal/public/assets/student/dashboard.php'); exit; }
if ($_SESSION['role'] === 'Lecturer') { header('Location: /UniPortal/public/assets/lecturer/dashboard.php'); exit; }
if (isset($_GET['logout'])) { auth_logout(); header('Location: /UniPortal/public/index.php'); exit; }

// Redirect to config.php which has the full settings implementation
header('Location: config.php');
exit;
