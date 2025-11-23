<?php
header('Content-Type: text/plain');
error_reporting(E_ALL);
ini_set('display_errors', 1);

include('config/db.php');

echo "Migrating release dates from request_actions.comment to requests.release_date...\n\n";

// Find all approved requests with release dates in comments
$sql = "SELECT r.id, ra.comment 
        FROM requests r 
        JOIN request_actions ra ON ra.request_id = r.id 
        WHERE r.status = 'approved' 
        AND ra.action_type = 'approved' 
        AND ra.comment LIKE 'Release date: %'";

$result = $conn->query($sql);
$migrated = 0;

if ($result) {
    while ($row = $result->fetch_assoc()) {
        $request_id = $row['id'];
        $comment = $row['comment'];
        
        // Extract the date from comment
        if (strpos($comment, 'Release date: ') === 0) {
            $release_date = substr($comment, 14); // Remove "Release date: " prefix
            
            // Update the requests table
            $update = $conn->prepare("UPDATE requests SET release_date = ? WHERE id = ?");
            $update->bind_param("si", $release_date, $request_id);
            
            if ($update->execute()) {
                echo "✅ Updated request ID $request_id: Release date set to $release_date\n";
                $migrated++;
            } else {
                echo "❌ Failed to update request ID $request_id: " . $conn->error . "\n";
            }
            $update->close();
        }
    }
} else {
    echo "❌ Error finding requests: " . $conn->error . "\n";
}

echo "\nMigration complete. Total requests migrated: $migrated\n\n";

// Show some sample data
echo "Sample data from requests table:\n";
$sample = $conn->query("SELECT id, request_id, status, release_date FROM requests WHERE status = 'approved' LIMIT 5");
if ($sample) {
    while ($row = $sample->fetch_assoc()) {
        echo "ID: {$row['id']}, Request ID: {$row['request_id']}, Status: {$row['status']}, Release Date: " . ($row['release_date'] ?: 'NULL') . "\n";
    }
}

$conn->close();
?>
