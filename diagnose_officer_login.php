<?php
// Enable error reporting
error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "<h2>Officer Login Diagnosis</h2>";

// Test database connection
require_once 'config/db.php';
echo "<p><strong>Database Connection:</strong> " . ($conn->connect_error ? "FAILED - " . $conn->connect_error : "OK") . "</p>";

// Check users table exists
$result = $conn->query("SHOW TABLES LIKE 'users'");
echo "<p><strong>Users Table:</strong> " . ($result && $result->num_rows > 0 ? "EXISTS" : "NOT FOUND") . "</p>";

// Check for officer users
echo "<h3>Officer Users:</h3>";
$result = $conn->query("SELECT id, email, role, first_name, last_name FROM users WHERE role LIKE '%officer%' OR role LIKE '%supply%'");
if ($result && $result->num_rows > 0) {
    echo "<table border='1'><tr><th>ID</th><th>Email</th><th>Role</th><th>Name</th></tr>";
    while ($row = $result->fetch_assoc()) {
        echo "<tr><td>{$row['id']}</td><td>{$row['email']}</td><td>{$row['role']}</td><td>{$row['first_name']} {$row['last_name']}</td></tr>";
    }
    echo "</table>";
} else {
    echo "<p>No officer users found</p>";
}

// Check all user roles
echo "<h3>All User Roles:</h3>";
$result = $conn->query("SELECT DISTINCT role, COUNT(*) as count FROM users GROUP BY role");
if ($result) {
    echo "<table border='1'><tr><th>Role</th><th>Count</th></tr>";
    while ($row = $result->fetch_assoc()) {
        echo "<tr><td>{$row['role']}</td><td>{$row['count']}</td></tr>";
    }
    echo "</table>";
}

// Test login process with sample data
echo "<h3>Login Process Test:</h3>";
$test_email = "officer@test.com"; // Change this to a real officer email
$result = $conn->query("SELECT id, password, role, must_change_password FROM users WHERE email = '$test_email'");
if ($result && $user = $result->fetch_assoc()) {
    echo "<p>User found: {$user['email']} (Role: {$user['role']})</p>";
    echo "<p>Password hash: " . substr($user['password'], 0, 20) . "...</p>";
    echo "<p>Must change password: " . ($user['must_change_password'] ? "YES" : "NO") . "</p>";
} else {
    echo "<p>User not found for email: $test_email</p>";
}

$conn->close();
?>
