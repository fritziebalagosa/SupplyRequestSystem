<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once 'config/db.php';

echo "<h2>Login Debug Information</h2>";

echo "<h3>Database Connection</h3>";
if ($conn) {
    echo "✅ Database connection: SUCCESS<br>";
} else {
    echo "❌ Database connection: FAILED<br>";
}

echo "<h3>Users Table</h3>";
$result = $conn->query("SELECT id, email, role, first_name, last_name FROM users");
echo "<table border='1'><tr><th>ID</th><th>Email</th><th>Role</th><th>Name</th></tr>";
while ($row = $result->fetch_assoc()) {
    echo "<tr><td>{$row['id']}</td><td>{$row['email']}</td><td>{$row['role']}</td><td>{$row['first_name']} {$row['last_name']}</td></tr>";
}
echo "</table>";

echo "<h3>Session Test</h3>";
session_start();
echo "Session ID: " . session_id() . "<br>";
echo "Session data: ";
print_r($_SESSION);

echo "<h3>Test Officer Login</h3>";
// Try to find an officer account
$officer = $conn->query("SELECT * FROM users WHERE role = 'officer' OR role = 'supply_officer' LIMIT 1")->fetch_assoc();
if ($officer) {
    echo "Found officer account: {$officer['email']} with role: {$officer['role']}<br>";
    
    // Test password verification
    if (password_verify('password', $officer['password'])) {
        echo "✅ Password 'password' works for this account<br>";
    } else {
        echo "❌ Password 'password' does not work<br>";
    }
} else {
    echo "❌ No officer account found<br>";
}

echo "<h3>Test Login Flow</h3>";
// Simulate login for officer
if ($officer) {
    $_SESSION['user_id'] = $officer['id'];
    $_SESSION['role'] = $officer['role'];
    echo "Session set: user_id={$_SESSION['user_id']}, role={$_SESSION['role']}<br>";
    
    // Test redirect logic
    switch ($_SESSION['role']) {
        case 'admin':
            $redirect = '../admin/dashboard.php';
            break;
        case 'dean':
            $redirect = '../dean/dashboard.php';
            break;
        case 'head':
            $redirect = '../head/dashboard.php';
            break;
        case 'officer':
            $redirect = '../officer/dashboard.php';
            break;
        default:
            $redirect = '../requesters/dashboard.php';
            break;
    }
    echo "Would redirect to: $redirect<br>";
}
?>
