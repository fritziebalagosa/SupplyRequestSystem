<?php
include('config/db.php');

echo "<h2>Items Table Setup</h2>";

// Check if items table exists
$result = $conn->query("SHOW TABLES LIKE 'items'");
if ($result->num_rows == 0) {
    echo "<p>Items table does not exist. Creating table...</p>";
    
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
        echo "<p style='color: green;'>Items table created successfully!</p>";
    } else {
        echo "<p style='color: red;'>Error creating table: " . $conn->error . "</p>";
        exit;
    }
} else {
    echo "<p>Items table already exists.</p>";
    
    // Check table structure
    $result = $conn->query("DESCRIBE items");
    echo "<h3>Table Structure:</h3>";
    echo "<table border='1'><tr><th>Field</th><th>Type</th><th>Null</th><th>Key</th><th>Default</th></tr>";
    while ($row = $result->fetch_assoc()) {
        echo "<tr><td>{$row['Field']}</td><td>{$row['Type']}</td><td>{$row['Null']}</td><td>{$row['Key']}</td><td>{$row['Default']}</td></tr>";
    }
    echo "</table>";
}

// Check if table has data
$result = $conn->query("SELECT COUNT(*) as count FROM items");
$count = $result->fetch_assoc()['count'];
echo "<h3>Data Count: $count records</h3>";

if ($count == 0) {
    echo "<p>No data found. Adding sample items...</p>";
    
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
            echo "<p style='color: green;'>Added: {$item[0]}</p>";
        } else {
            echo "<p style='color: red;'>Error adding {$item[0]}: " . $stmt->error . "</p>";
        }
        $stmt->close();
    }
    
    echo "<p style='color: green; font-weight: bold;'>Sample items added successfully!</p>";
} else {
    echo "<p>Showing existing data:</p>";
    $result = $conn->query("SELECT * FROM items ORDER BY item_name LIMIT 10");
    echo "<table border='1'><tr><th>Stock No.</th><th>Item Name</th><th>Unit</th><th>Quantity</th><th>Reorder Level</th></tr>";
    
    while ($row = $result->fetch_assoc()) {
        $status = $row['stock_qty'] <= $row['reorder_level'] ? 'Low Stock' : 'In Stock';
        $color = $status == 'Low Stock' ? 'red' : 'green';
        echo "<tr>";
        echo "<td>{$row['stock_number']}</td>";
        echo "<td>{$row['item_name']}</td>";
        echo "<td>{$row['unit']}</td>";
        echo "<td>{$row['stock_qty']}</td>";
        echo "<td>{$row['reorder_level']}</td>";
        echo "<td style='color: $color;'>$status</td>";
        echo "</tr>";
    }
    echo "</table>";
}

$conn->close();
?>
