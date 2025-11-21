<?php
require_once 'config/db.php';

// Check if release_proofs table exists
$result = $conn->query("SHOW TABLES LIKE 'release_proofs'");
if ($result->num_rows === 0) {
    die("Error: The 'release_proofs' table does not exist. Please run the schema update script.");
}

// Get table structure
$result = $conn->query("DESCRIBE release_proofs");
echo "<h2>release_proofs Table Structure:</h2>";
echo "<table border='1'>";
echo "<tr><th>Field</th><th>Type</th><th>Null</th><th>Key</th><th>Default</th><th>Extra</th></tr>";
while ($row = $result->fetch_assoc()) {
    echo "<tr>";
    echo "<td>" . htmlspecialchars($row['Field']) . "</td>";
    echo "<td>" . htmlspecialchars($row['Type']) . "</td>";
    echo "<td>" . htmlspecialchars($row['Null']) . "</td>";
    echo "<td>" . htmlspecialchars($row['Key']) . "</td>";
    echo "<td>" . htmlspecialchars($row['Default']) . "</td>";
    echo "<td>" . htmlspecialchars($row['Extra']) . "</td>";
    echo "</tr>";
}
echo "</table>";

// Check if created_at column exists
$result = $conn->query("SHOW COLUMNS FROM release_proofs LIKE 'created_at'");
if ($result->num_rows === 0) {
    echo "<p style='color:red;'>Error: The 'created_at' column is missing from the 'release_proofs' table.</p>";
    echo "<p>Please run the following SQL to add the column:</p>";
    echo "<pre>ALTER TABLE release_proofs ADD COLUMN created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP;</pre>";
} else {
    echo "<p style='color:green;'>The 'created_at' column exists in the 'release_proofs' table.</p>";
}

$conn->close();
?>
