<?php
include('../config/db.php');

$password = password_hash('admin123', PASSWORD_DEFAULT);

$sql = "INSERT INTO users (first_name, last_name, email, password, role, status)
        VALUES ('System', 'Admin', 'admin@supplysystem.com', '$password', 'admin', 'active')";

if ($conn->query($sql)) {
    echo "✅ Admin user created successfully!";
} else {
    echo "❌ Error: " . $conn->error;
}
?>
