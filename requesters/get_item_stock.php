<?php
include('../config/db.php');

$item_name = $_GET['item_name'] ?? '';
$response = ['success' => false];

if ($item_name !== '') {
    $stmt = $conn->prepare("SELECT stock_qty, reorder_level FROM items WHERE item_name = ? LIMIT 1");
    $stmt->bind_param('s', $item_name);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($row = $result->fetch_assoc()) {
        $response = [
            'success' => true,
            'stock_qty' => (int)$row['stock_qty'],
            'reorder_level' => (int)$row['reorder_level']
        ];
    }
    $stmt->close();
}

header('Content-Type: application/json');
echo json_encode($response);
