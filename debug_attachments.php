<?php
include('config/db.php');

echo "<h2>Debug Attachment Paths</h2>";

// Check requests with attachments
$result = $conn->query("SELECT id, request_id, attachment FROM requests WHERE attachment IS NOT NULL AND attachment != ''");

if ($result && $result->num_rows > 0) {
    echo "<table border='1'>";
    echo "<tr><th>Request ID</th><th>Attachment Path</th><th>Original Exists</th><th>With ../ Exists</th><th>Uploads/ Exists</th><th>Full Path Check</th></tr>";
    
    while ($row = $result->fetch_assoc()) {
        $attachment = $row['attachment'];
        echo "<tr>";
        echo "<td>" . $row['id'] . "</td>";
        echo "<td>" . htmlspecialchars($attachment) . "</td>";
        
        // Check various path combinations
        $original_exists = file_exists($attachment);
        $with_dotdot_exists = file_exists('../' . $attachment);
        $uploads_exists = file_exists('uploads/' . basename($attachment));
        
        echo "<td>" . ($original_exists ? 'YES' : 'NO') . "</td>";
        echo "<td>" . ($with_dotdot_exists ? 'YES' : 'NO') . "</td>";
        echo "<td>" . ($uploads_exists ? 'YES' : 'NO') . "</td>";
        
        // Show the actual file path we're checking
        $check_path = '../' . $attachment;
        if (strpos($attachment, '../') === 0) {
            $check_path = '../' . str_replace('../', '', $attachment);
        }
        if (strpos($attachment, 'uploads/') !== 0) {
            $check_path = '../uploads/' . basename($attachment);
        }
        
        echo "<td>" . htmlspecialchars($check_path) . " - " . (file_exists($check_path) ? 'EXISTS' : 'NOT FOUND') . "</td>";
        echo "</tr>";
    }
    echo "</table>";
} else {
    echo "<p>No requests with attachments found in database.</p>";
}

// Check what's actually in uploads directory
echo "<h3>Files in uploads directory:</h3>";
if (is_dir('uploads')) {
    $files = scandir('uploads');
    foreach ($files as $file) {
        if ($file !== '.' && $file !== '..') {
            echo "- " . htmlspecialchars($file) . "<br>";
        }
    }
} else {
    echo "<p>Uploads directory does not exist.</p>";
}

// Check uploads/release_proofs
echo "<h3>Files in uploads/release_proofs directory:</h3>";
if (is_dir('uploads/release_proofs')) {
    $files = scandir('uploads/release_proofs');
    foreach ($files as $file) {
        if ($file !== '.' && $file !== '..') {
            echo "- " . htmlspecialchars($file) . "<br>";
        }
    }
} else {
    echo "<p>Uploads/release_proofs directory does not exist.</p>";
}
?>
