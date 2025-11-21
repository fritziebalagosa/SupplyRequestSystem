<?php
require_once 'config/db.php';

echo "<h2>Fixing release_proofs table</h2>";

// Add created_at column if it doesn't exist
$sql = "ALTER TABLE release_proofs 
        ADD COLUMN IF NOT EXISTS created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP";

if ($conn->query($sql) === TRUE) {
    echo "<p style='color:green;'>Successfully added 'created_at' column to release_proofs table.</p>";
} else {
    echo "<p style='color:red;'>Error adding column: " . $conn->error . "</p>";
}

// Verify the column was added
$result = $conn->query("SHOW COLUMNS FROM release_proofs LIKE 'created_at'");
if ($result->num_rows > 0) {
    echo "<p style='color:green;'>Verification: 'created_at' column exists in release_proofs table.</p>";
    
    // Test the original query
    $test_id = 1;
    $test_query = "SELECT * FROM release_proofs WHERE request_id = ? ORDER BY created_at DESC LIMIT 1";
    $test_stmt = $conn->prepare($test_query);
    
    if ($test_stmt) {
        $test_stmt->bind_param("i", $test_id);
        if ($test_stmt->execute()) {
            echo "<p style='color:green;'>✓ Test query executed successfully!</p>";
            $result = $test_stmt->get_result();
            echo "<p>Found " . $result->num_rows . " receipt records for request ID: " . $test_id . "</p>";
        } else {
            echo "<p style='color:red;'>Error executing test query: " . $test_stmt->error . "</p>";
        }
        $test_stmt->close();
    } else {
        echo "<p style='color:red;'>Error preparing test query: " . $conn->error . "</p>";
    }
} else {
    echo "<p style='color:red;'>Failed to verify 'created_at' column. Please check database permissions.</p>";
}

$conn->close();
?>

<p>After running this script, please try accessing the original page again.</p>
<p>If you still encounter issues, please check the following:</p>
<ol>
    <li>Make sure the database user has ALTER TABLE permissions</li>
    <li>Check if there are any triggers or constraints that might be preventing the column addition</li>
    <li>Verify the table name is exactly 'release_proofs' (case-sensitive in some databases)</li>
</ol>
