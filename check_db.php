<?php
include('config/db.php');
echo "Database connection: " . ($conn->connect_error ? "FAILED" : "OK") . "\n";

// Check for officer users
echo "\nChecking for officer users...\n";
$result = $conn->query("SELECT id, email, role FROM users WHERE role LIKE '%officer%'");
if ($result && $result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        echo "ID: {$row['id']}, Email: {$row['email']}, Role: {$row['role']}\n";
    }
} else {
    echo "No officer users found\n";
}

// Check all roles
echo "\nAll user roles:\n";
$result = $conn->query("SELECT DISTINCT role FROM users");
if ($result) {
    while ($row = $result->fetch_assoc()) {
        echo "- {$row['role']}\n";
    }
}

$conn->close();
?>
