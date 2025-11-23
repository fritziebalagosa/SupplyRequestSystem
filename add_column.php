<?php
include('config/db.php');

// Directly add the column
$sql = "ALTER TABLE requests ADD COLUMN release_date DATE NULL";
if ($conn->query($sql)) {
    echo "Column added successfully";
} else {
    echo "Error: " . $conn->error;
}
$conn->close();
?>
