<?php
include('config/db.php');

echo "Starting items table setup...\n";

// Check if items table exists
$result = $conn->query("SHOW TABLES LIKE 'items'");
if ($result->num_rows == 0) {
    echo "Items table does not exist. Creating table...\n";
    
    // Create the items table
    $create_table_sql = "
    CREATE TABLE items (
        id INT AUTO_INCREMENT PRIMARY KEY,
        stock_number VARCHAR(50) UNIQUE NOT NULL,
        item_name VARCHAR(255) NOT NULL,
        unit VARCHAR(50) NOT NULL,
        stock_qty INT NOT NULL DEFAULT 0,
        reorder_level INT NOT NULL DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ";
    
    if ($conn->query($create_table_sql)) {
        echo "Items table created successfully!\n";
    } else {
        echo "Error creating table: " . $conn->error . "\n";
        exit(1);
    }
} else {
    echo "Items table already exists.\n";
}

// Check if table has data
$result = $conn->query("SELECT COUNT(*) as count FROM items");
$count = $result->fetch_assoc()['count'];
echo "Current data count: $count records\n";

if ($count == 0) {
    echo "No data found. Adding sample items...\n";
    
    // Add sample data
    $sample_items = [
        ['Bond Paper', 'ream', 50, 10],
        ['Ballpen', 'piece', 200, 50],
        ['Folder', 'piece', 100, 25],
        ['Stapler', 'piece', 15, 5],
        ['Paper Clips', 'box', 30, 10],
        ['Envelope', 'piece', 150, 30],
        ['Marker', 'piece', 80, 20],
        ['Highlighter', 'piece', 60, 15]
    ];
    
    foreach ($sample_items as $index => $item) {
        $stock_number = "STK-" . date("Ymd") . "-" . str_pad($index + 1, 4, "0", STR_PAD_LEFT);
        $stmt = $conn->prepare("INSERT INTO items (stock_number, item_name, unit, stock_qty, reorder_level) VALUES (?, ?, ?, ?, ?)");
        $stmt->bind_param("sssii", $stock_number, $item[0], $item[1], $item[2], $item[3]);
        
        if ($stmt->execute()) {
            echo "Added: {$item[0]}\n";
        } else {
            echo "Error adding {$item[0]}: " . $stmt->error . "\n";
        }
        $stmt->close();
    }
    
    echo "Sample items added successfully!\n";
} else {
    echo "Table already has data. No sample items added.\n";
}

// Verify the setup
$result = $conn->query("SELECT COUNT(*) as count FROM items");
$count = $result->fetch_assoc()['count'];
echo "Final item count: $count\n";

$conn->close();
echo "Setup complete!\n";
?>
