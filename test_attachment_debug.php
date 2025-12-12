<?php
include('config/db.php');

echo "<h2>Attachment Debug Test</h2>";

//php
// Test 1: Check database for attachments
echo "<h3>1. Database Check</h3>";
$result = $conn->query("SELECT id, attachment FROM requests WHERE attachment IS NOT NULL AND attachment != '' LIMIT 3");

if ($result && $result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        echo "<strong>Request ID:</strong> " . $row['id'] . "<br>";
        echo "<strong>Attachment in DB:</strong> " . htmlspecialchars($row['attachment']) . "<br>";
        
        $attachment_path = $row['attachment'];
        
        // Test different path combinations
        echo "<strong>Path Tests:</strong><br>";
        echo "- Original path exists: " . (file_exists($attachment_path) ? "YES" : "NO") . "<br>";
        echo "- With ../ prefix: " . (file_exists('../' . $attachment_path) ? "YES" : "NO") . "<br>";
        echo "- Without ../ prefix: " . (file_exists(ltrim($attachment_path, '../')) ? "YES" : "NO") . "<br>";
        
        // Test our current logic
        if (strpos($attachment_path, '../') === 0) {
            $web_path = substr($attachment_path, 3);
        } else {
            $web_path = $attachment_path;
        }
        
        echo "<strong>Web path:</strong> " . htmlspecialchars($web_path) . "<br>";
        echo "<strong>File exists check:</strong> " . (file_exists('../' . $web_path) ? "YES" : "NO") . "<br>";
        
        echo "<hr>";
    }
} else {
    echo "No attachments found in database.<br>";
}

// Test 2: Check uploads directory
echo "<h3>2. Uploads Directory Check</h3>";
if (is_dir('uploads')) {
    echo "Uploads directory exists.<br>";
    $files = scandir('uploads');
    echo "Files in uploads:<br>";
    foreach ($files as $file) {
        if ($file !== '.' && $file !== '..') {
            $full_path = 'uploads/' . $file;
            echo "- " . htmlspecialchars($file) . " (exists: " . (file_exists($full_path) ? "YES" : "NO") . ")<br>";
        }
    }
} else {
    echo "Uploads directory does not exist.<br>";
}

// Test 3: Check parent directory uploads
echo "<h3>3. Parent Directory Check</h3>";
if (is_dir('../uploads')) {
    echo "Parent uploads directory exists.<br>";
    $files = scandir('../uploads');
    echo "Files in ../uploads:<br>";
    foreach ($files as $file) {
        if ($file !== '.' && $file !== '..') {
            $full_path = '../uploads/' . $file;
            echo "- " . htmlspecialchars($file) . " (exists: " . (file_exists($full_path) ? "YES" : "NO") . ")<br>";
        }
    }
} else {
    echo "Parent uploads directory does not exist.<br>";
}

// Test 4: Current working directory
echo "<h3>4. Current Working Directory</h3>";
echo "CWD: " . getcwd() . "<br>";
echo "Document Root: " . $_SERVER['DOCUMENT_ROOT'] . "<br>";
echo "Script Name: " . $_SERVER['SCRIPT_NAME'] . "<br>";
?>
