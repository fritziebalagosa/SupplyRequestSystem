<?php
include('config/db.php');

echo "Checking notifications table structure...\n";

// Check current structure
$result = $conn->query("DESCRIBE notifications");
if ($result) {
    while ($row = $result->fetch_assoc()) {
        echo "Field: {$row['Field']}, Type: {$row['Type']}, Null: {$row['Null']}, Default: {$row['Default']}\n";
    }
}

echo "\nRunning migration...\n";

try {
    // Drop foreign key if exists
    $conn->query("ALTER TABLE notifications DROP FOREIGN KEY IF EXISTS notifications_ibfk_2");
    echo "Dropped foreign key (if existed)\n";
} catch (Exception $e) {
    echo "Could not drop foreign key: " . $e->getMessage() . "\n";
}

try {
    // Allow NULL in request_id
    $conn->query("ALTER TABLE notifications MODIFY COLUMN request_id INT DEFAULT NULL");
    echo "Modified request_id to allow NULL\n";
} catch (Exception $e) {
    echo "Error modifying request_id: " . $e->getMessage() . "\n";
}

try {
    // Re-add foreign key
    $conn->query("ALTER TABLE notifications ADD CONSTRAINT fk_notifications_request_id 
    FOREIGN KEY (request_id) REFERENCES requests(id) ON DELETE SET NULL");
    echo "Re-added foreign key\n";
} catch (Exception $e) {
    echo "Error adding foreign key: " . $e->getMessage() . "\n";
}

echo "\nFinal structure check:\n";
$result = $conn->query("DESCRIBE notifications");
if ($result) {
    while ($row = $result->fetch_assoc()) {
        echo "Field: {$row['Field']}, Type: {$row['Type']}, Null: {$row['Null']}, Default: {$row['Default']}\n";
    }
}

echo "\nDone!\n";
?>
