<?php
include('config/db.php');

// Create release_proofs table
$sql = "CREATE TABLE IF NOT EXISTS release_proofs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    request_id INT NOT NULL,
    user_id INT NOT NULL,
    image_path VARCHAR(255) NOT NULL,
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (request_id) REFERENCES requests(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

if ($conn->query($sql)) {
    echo "release_proofs table created successfully\n";
} else {
    echo "Error creating release_proofs table: " . $conn->error . "\n";
}

// Check if table exists
$result = $conn->query("DESCRIBE release_proofs");
if ($result) {
    echo "\nrelease_proofs table structure:\n";
    while ($row = $result->fetch_assoc()) {
        echo "- " . $row['Field'] . " (" . $row['Type'] . ")\n";
    }
} else {
    echo "\nTable does not exist or error: " . $conn->error . "\n";
}

$conn->close();
?>
