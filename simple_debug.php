<?php
include('config/db.php');

echo "=== Attachment Debug ===\n";

// Get a sample attachment
$result = $conn->query("SELECT id, attachment FROM requests WHERE attachment IS NOT NULL AND attachment != '' LIMIT 1");

if ($result && $row = $result->fetch_assoc()) {
    echo "Found request with attachment:\n";
    echo "ID: " . $row['id'] . "\n";
    echo "Attachment: " . $row['attachment'] . "\n";
    
    $attachment_path = $row['attachment'];
    
    echo "\nTesting file existence:\n";
    echo "file_exists('$attachment_path'): " . (file_exists($attachment_path) ? 'true' : 'false') . "\n";
    echo "file_exists('../$attachment_path'): " . (file_exists('../' . $attachment_path) ? 'true' : 'false') . "\n";
    
    // Test our logic
    if (strpos($attachment_path, '../') === 0) {
        $web_path = substr($attachment_path, 3);
    } else {
        $web_path = $attachment_path;
    }
    
    echo "\nOur processed path:\n";
    echo "Web path: '$web_path'\n";
    echo "file_exists('../$web_path'): " . (file_exists('../' . $web_path) ? 'true' : 'false') . "\n";
    
    // Check uploads directory
    echo "\nUploads directory check:\n";
    echo "is_dir('uploads'): " . (is_dir('uploads') ? 'true' : 'false') . "\n";
    echo "is_dir('../uploads'): " . (is_dir('../uploads') ? 'true' : 'false') . "\n";
    
    if (is_dir('../uploads')) {
        $files = scandir('../uploads');
        echo "Files in ../uploads:\n";
        foreach ($files as $file) {
            if ($file !== '.' && $file !== '..') {
                echo "  - $file\n";
            }
        }
    }
    
} else {
    echo "No attachments found in database\n";
}
?>
