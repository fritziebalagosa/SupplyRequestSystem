<?php
include('config/db.php');

echo "Fixing notifications table structure...\n";

// Check if is_read column exists
$result = $conn->query("SHOW COLUMNS FROM notifications LIKE 'is_read'");
if ($result->num_rows == 0) {
    echo "Adding is_read column...\n";
    $conn->query("ALTER TABLE notifications ADD COLUMN is_read TINYINT(1) DEFAULT 0");
} else {
    echo "is_read column already exists\n";
}

// Check if read_at column exists
$result = $conn->query("SHOW COLUMNS FROM notifications LIKE 'read_at'");
if ($result->num_rows == 0) {
    echo "Adding read_at column...\n";
    $conn->query("ALTER TABLE notifications ADD COLUMN read_at TIMESTAMP NULL DEFAULT NULL");
} else {
    echo "read_at column already exists\n";
}

// Check if 'read' column exists (old name) and copy data
$result = $conn->query("SHOW COLUMNS FROM notifications LIKE 'read'");
if ($result->num_rows > 0) {
    echo "Found 'read' column, copying data to is_read...\n";
    $conn->query("UPDATE notifications SET is_read = `read` WHERE `read` IS NOT NULL");
    echo "Data copied. You may want to drop the 'read' column later.\n";
}

// Show current structure
echo "\nCurrent notifications table structure:\n";
$result = $conn->query('DESCRIBE notifications');
while ($row = $result->fetch_assoc()) {
    echo $row['Field'] . ' - ' . $row['Type'] . "\n";
}

echo "\nFix complete!\n";
$conn->close();
?>
