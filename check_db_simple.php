<?php
include('config/db.php');

echo "Checking database for attachments...\n";

// Simple check for any attachments
$result = $conn->query("SELECT COUNT(*) as count FROM requests WHERE attachment IS NOT NULL AND attachment != ''");
$row = $result->fetch_assoc();
echo "Total requests with attachments: " . $row['count'] . "\n\n";

// Get actual attachment data
$result = $conn->query("SELECT id, attachment FROM requests WHERE attachment IS NOT NULL AND attachment != '' LIMIT 3");

if ($result && $result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        echo "Request ID: " . $row['id'] . "\n";
        echo "Attachment: " . $row['attachment'] . "\n";
        echo "---\n";
    }
} else {
    echo "No attachments found.\n";
}
?>
