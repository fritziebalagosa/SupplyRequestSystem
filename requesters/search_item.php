<?php
include('../config/db.php');

$q = $_GET['q'] ?? '';

if (strlen($q) >= 2) {
    $stmt = $conn->prepare("SELECT stock_number, item_name, unit FROM items WHERE item_name LIKE CONCAT('%', ?, '%') LIMIT 10");
    $stmt->bind_param("s", $q);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows > 0) {
        while ($row = $result->fetch_assoc()) {
            echo "<button type='button' class='list-group-item list-group-item-action suggest-item'
                    data-name='" . htmlspecialchars($row['item_name']) . "'
                    data-stock='" . htmlspecialchars($row['stock_number']) . "'
                    data-unit='" . htmlspecialchars($row['unit']) . "'>
                    " . htmlspecialchars($row['item_name']) . " (" . htmlspecialchars($row['unit']) . ")
                  </button>";
        }
    } else {
        echo "<div class='list-group-item disabled'>No items found</div>";
    }
    $stmt->close();
}
?>
