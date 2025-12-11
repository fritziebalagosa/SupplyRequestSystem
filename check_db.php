<?php
include('config/db.php');
echo "Checking notifications table structure...\n";
$result = $conn->query('DESCRIBE notifications');
if ($result) {
    while ($row = $result->fetch_assoc()) {
        echo $row['Field'] . ' - ' . $row['Type'] . "\n";
    }
} else {
    echo "Error: " . $conn->error . "\n";
}
$conn->close();
?>
