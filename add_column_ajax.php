<?php
header('Content-Type: text/plain');
error_reporting(E_ALL);
ini_set('display_errors', 1);

include('config/db.php');

echo "Starting to add release_date column...\n";

// Check if column already exists
$check = $conn->prepare("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME = 'requests' AND COLUMN_NAME = 'release_date' AND TABLE_SCHEMA = DATABASE()");
$check->execute();
$result = $check->get_result();

if ($result->num_rows > 0) {
    echo "release_date column already exists.\n";
} else {
    echo "Adding release_date column...\n";
    $sql = "ALTER TABLE requests ADD COLUMN release_date DATE NULL COMMENT 'Release date set by admin when approving request'";
    
    if ($conn->query($sql)) {
        echo "✅ release_date column added successfully!\n";
    } else {
        echo "❌ Error adding column: " . $conn->error . "\n";
    }
}

// Verify the column was added
$verify = $conn->prepare("DESCRIBE requests");
$verify->execute();
$columns = $verify->get_result()->fetch_all(MYSQLI_ASSOC);

echo "\nCurrent columns in requests table:\n";
foreach ($columns as $col) {
    echo "- " . $col['Field'] . " (" . $col['Type'] . ")\n";
}

$conn->close();
?>
