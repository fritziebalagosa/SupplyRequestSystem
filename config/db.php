<?php
$host = 'localhost';
$user = 'root';
$pass = '';
$dbname = 'supply_request_system';

$conn = new mysqli($host, $user, $pass, $dbname);

if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

$conn->set_charset("utf8mb4");

// Set timezone to Asia/Manila for consistent datetime handling
date_default_timezone_set('Asia/Manila');

// Set MySQL timezone to match PHP timezone
$conn->query("SET time_zone = '+08:00'");
?>
