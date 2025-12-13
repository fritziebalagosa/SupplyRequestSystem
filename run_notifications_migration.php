<?php
include('config/db.php');

echo "Running notifications table migration...\n";

try {
    // First, drop the foreign key constraint if it exists
    $conn->query("ALTER TABLE notifications DROP FOREIGN KEY notifications_ibfk_2");
    echo "Dropped foreign key constraint\n";
} catch (Exception $e) {
    echo "Foreign key constraint may not exist or already dropped: " . $e->getMessage() . "\n";
}

try {
    // Then modify the column to allow NULL
    $conn->query("ALTER TABLE notifications MODIFY COLUMN request_id INT DEFAULT NULL");
    echo "Modified request_id column to allow NULL\n";
} catch (Exception $e) {
    echo "Error modifying request_id column: " . $e->getMessage() . "\n";
}

try {
    // Re-add the foreign key constraint with ON DELETE SET NULL
    $conn->query("ALTER TABLE notifications ADD CONSTRAINT fk_notifications_request_id 
    FOREIGN KEY (request_id) REFERENCES requests(id) ON DELETE SET NULL");
    echo "Re-added foreign key constraint\n";
} catch (Exception $e) {
    echo "Error adding foreign key constraint: " . $e->getMessage() . "\n";
}

try {
    // Add the type column if it doesn't exist
    $conn->query("ALTER TABLE notifications ADD COLUMN IF NOT EXISTS type VARCHAR(50) DEFAULT 'general'");
    echo "Added type column if needed\n";
} catch (Exception $e) {
    echo "Error adding type column: " . $e->getMessage() . "\n";
}

try {
    // Change is_read to read if it exists and is the old name
    $conn->query("ALTER TABLE notifications CHANGE COLUMN is_read `read` TINYINT(1) DEFAULT 0");
    echo "Changed is_read to read column\n";
} catch (Exception $e) {
    echo "Error changing column name: " . $e->getMessage() . "\n";
}

echo "Migration completed!\n";
?>
