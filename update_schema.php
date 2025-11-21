<?php
// Database configuration
require_once 'config/db.php';

// Check if user is logged in and is an admin
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    die('Unauthorized access. Only administrators can run this script.');
}

echo "<h1>Database Schema Update</h1>";

try {
    // Read the SQL file
    $sql = file_get_contents('database/schema_updates.sql');
    
    if ($sql === false) {
        throw new Exception("Could not read SQL file");
    }
    
    // Split the SQL into individual queries
    $queries = explode(';', $sql);
    $successCount = 0;
    $errorCount = 0;
    
    echo "<pre>";
    echo "Starting database update...\n";
    echo "=================================\n";
    
    // Execute each query
    foreach ($queries as $query) {
        $query = trim($query);
        
        // Skip empty queries
        if (empty($query)) {
            continue;
        }
        
        echo "Executing: " . substr($query, 0, 100) . (strlen($query) > 100 ? '...' : '') . "\n";
        
        try {
            $result = $conn->query($query);
            if ($result === false) {
                throw new Exception($conn->error);
            }
            $successCount++;
            echo "✓ Success\n";
        } catch (Exception $e) {
            $errorCount++;
            echo "✗ Error: " . $e->getMessage() . "\n";
        }
        
        echo "---------------------------------\n";
    }
    
    echo "\nUpdate complete!\n";
    echo "Successful queries: $successCount\n";
    echo "Failed queries: $errorCount\n";
    echo "</pre>";
    
    if ($errorCount === 0) {
        echo "<p style='color: green;'>Database schema updated successfully!</p>";
    } else {
        echo "<p style='color: orange;'>Some queries failed to execute. Check the output above for details.</p>";
    }
    
} catch (Exception $e) {
    echo "<div style='color: red; padding: 10px; border: 1px solid #f5c6cb; background-color: #f8d7da; border-radius: 4px;'>
        <h3>Error:</h3>
        <p>" . htmlspecialchars($e->getMessage()) . "</p>
    </div>";
}

$conn->close();
?>

<div style="margin-top: 20px;">
    <a href="admin/dashboard.php" class="btn btn-primary">Return to Dashboard</a>
</div>

<style>
    body {
        font-family: Arial, sans-serif;
        line-height: 1.6;
        margin: 20px;
        max-width: 1000px;
        margin: 0 auto;
        padding: 20px;
    }
    pre {
        background-color: #f8f9fa;
        padding: 15px;
        border-radius: 4px;
        border: 1px solid #dee2e6;
        overflow-x: auto;
    }
    .btn {
        display: inline-block;
        padding: 8px 16px;
        background-color: #007bff;
        color: white;
        text-decoration: none;
        border-radius: 4px;
        border: none;
        cursor: pointer;
    }
    .btn:hover {
        background-color: #0056b3;
    }
</style>
