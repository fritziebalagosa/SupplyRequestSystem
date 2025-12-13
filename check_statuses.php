<?php
include('config/db.php');

echo "Checking request statuses...\n";

// Check distinct statuses
$result = $conn->query("SELECT DISTINCT status FROM requests ORDER BY status");
if ($result) {
    echo "Available statuses:\n";
    while ($row = $result->fetch_assoc()) {
        echo "- " . $row['status'] . "\n";
    }
} else {
    echo "Error: " . $conn->error . "\n";
}

// Check recent requests with approved-like statuses
echo "\nRecent requests:\n";
$result = $conn->query("SELECT id, request_id, status, created_at FROM requests ORDER BY id DESC LIMIT 10");
if ($result) {
    while ($row = $result->fetch_assoc()) {
        echo "ID: " . $row['id'] . ", Request ID: " . $row['request_id'] . ", Status: " . $row['status'] . ", Created: " . $row['created_at'] . "\n";
    }
} else {
    echo "Error: " . $conn->error . "\n";
}
?>
