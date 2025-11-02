<?php
session_start();

// Destroy all session data
$_SESSION = [];
session_unset();
session_destroy();

// Redirect to login page
header("Location: log_in.php");
exit();
?>
