<?php 
// Inside the part of your code where a request is marked as approved
$update_stock = $conn->prepare("
    UPDATE items 
    JOIN request_items ON items.id = request_items.item_id
    SET items.stock_qty = items.stock_qty - request_items.quantity
    WHERE request_items.request_id = ?
");
$update_stock->bind_param("i", $request_id);
$update_stock->execute();

?>