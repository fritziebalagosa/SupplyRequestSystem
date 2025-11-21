<?php
require_once 'config/db.php';

// Check if table exists
$result = $conn->query("SHOW TABLES LIKE 'release_proofs'");
if ($result->num_rows === 0) {
    die("Error: The 'release_proofs' table does not exist. Please run the schema update script at update_schema.php");
}

// Check table structure
$result = $conn->query("SHOW COLUMNS FROM release_proofs");
echo "<h2>release_proofs Table Structure:</h2>";
echo "<table border='1' cellpadding='5'>";
echo "<tr><th>Field</th><th>Type</th><th>Null</th><th>Key</th><th>Default</th><th>Extra</th></tr>";
while ($row = $result->fetch_assoc()) {
    echo "<tr>";
    foreach (['Field', 'Type', 'Null', 'Key', 'Default', 'Extra'] as $field) {
        echo "<td>" . htmlspecialchars($row[$field] ?? 'NULL') . "</td>";
    }
    echo "</tr>";
}
echo "</table>";

// Try to run the actual query to see the error
$test_id = 1; // Using a test ID
$test_stmt = $conn->prepare("SELECT * FROM release_proofs WHERE request_id = ? ORDER BY created_at DESC LIMIT 1");
if ($test_stmt === false) {
    echo "<p style='color:red;'>Error preparing statement: " . htmlspecialchars($conn->error) . "</p>";
} else {
    $test_stmt->bind_param("i", $test_id);
    if ($test_stmt->execute()) {
        echo "<p style='color:green;'>Query executed successfully!</p>";
        $result = $test_stmt->get_result();
        if ($result->num_rows > 0) {
            echo "<p>Found " . $result->num_rows . " receipt records for request ID: " . $test_id . "</p>";
        } else {
            echo "<p>No receipt records found for request ID: " . $test_id . " (This is expected if no receipts exist for this ID)</p>";
        }
    } else {
        echo "<p style='color:red;'>Error executing query: " . htmlspecialchars($test_stmt->error) . "</p>";
    }
    $test_stmt->close();
}

// Check if we can get the request ID from the URL
if (isset($_GET['id'])) {
    $id = intval($_GET['id']);
    echo "<h3>Testing with actual request ID: $id</h3>";
    $test_stmt = $conn->prepare("SELECT * FROM release_proofs WHERE request_id = ? ORDER BY created_at DESC LIMIT 1");
    if ($test_stmt) {
        $test_stmt->bind_param("i", $id);
        if ($test_stmt->execute()) {
            $result = $test_stmt->get_result();
            echo "<p>Found " . $result->num_rows . " receipt records for actual request ID: $id</p>";
        } else {
            echo "<p style='color:red;'>Error executing query with ID $id: " . htmlspecialchars($test_stmt->error) . "</p>";
        }
        $test_stmt->close();
    }
}

$conn->close();
?>
