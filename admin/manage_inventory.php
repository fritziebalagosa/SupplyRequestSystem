<?php
include('../config/db.php');
session_start();

// Authentication check
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'] ?? '', ['admin', 'supply_head'])) {
    header('Location: ../auth/log_in.php');
    exit();
}

// 🔹 Auto-generate stock number
function generateStockNumber($conn) {
    $prefix = "STK-" . date("Ymd") . "-";
    $result = $conn->query("SELECT COUNT(*) AS count FROM items");
    $count = $result->fetch_assoc()['count'] + 1;
    return $prefix . str_pad($count, 4, "0", STR_PAD_LEFT);
}

// 🔹 Handle Add Item
if (isset($_POST['add_item'])) {
    $stock_number = generateStockNumber($conn);
    $item_name = $_POST['item_name'];
    $unit = $_POST['unit'];
    $stock_qty = $_POST['stock_qty'];
    $reorder_level = $_POST['reorder_level'];

    $stmt = $conn->prepare("INSERT INTO items (stock_number, item_name, unit, stock_qty, reorder_level) VALUES (?, ?, ?, ?, ?)");
    $stmt->bind_param("sssii", $stock_number, $item_name, $unit, $stock_qty, $reorder_level);
    $stmt->execute();
    $stmt->close();

    header("Location: manage_inventory.php");
    exit();
}

// 🔹 Handle Restock
if (isset($_POST['restock_item'])) {
    $item_id = $_POST['item_id'];
    $add_qty = $_POST['add_qty'];

    $stmt = $conn->prepare("UPDATE items SET stock_qty = stock_qty + ? WHERE id = ?");
    $stmt->bind_param("ii", $add_qty, $item_id);
    $stmt->execute();
    $stmt->close();
    header("Location: manage_inventory.php");
    exit();
}

// 🔹 Handle Delete
if (isset($_GET['delete'])) {
    $id = $_GET['delete'];
    $stmt = $conn->prepare("DELETE FROM items WHERE id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $stmt->close();
    header("Location: manage_inventory.php");
    exit();
}

// 🔹 Filtering
$search = isset($_GET['search']) ? "%" . $_GET['search'] . "%" : '%';
$unit_filter = isset($_GET['unit']) && $_GET['unit'] != 'All' ? $_GET['unit'] : '%';
$status_filter = isset($_GET['status']) && $_GET['status'] != 'All' ? $_GET['status'] : '%';

$query = "
    SELECT *, 
    CASE 
        WHEN stock_qty <= reorder_level THEN 'Low Stock'
        ELSE 'In Stock'
    END AS stock_status
    FROM items 
    WHERE item_name LIKE ? 
      AND unit LIKE ?
";

$params = [$search, $unit_filter];
$types = "ss";

if ($status_filter != '%') {
    if ($status_filter == 'Low Stock') {
        $query .= " AND stock_qty <= reorder_level";
    } elseif ($status_filter == 'In Stock') {
        $query .= " AND stock_qty > reorder_level";
    }
}

$query .= " ORDER BY item_name ASC";

$stmt = $conn->prepare($query);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$result = $stmt->get_result();

if (isset($_GET['debug'])) {
    echo "<pre>DEBUG manage_inventory\nSQL: " . htmlspecialchars($query) . "\nRows: " . $result->num_rows . "</pre>";
}

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Inventory - WMSU OSRS</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css" rel="stylesheet">
    <link rel="stylesheet" href="../styles/admin_inventory.css">
</head>
<body>
<?php include('../includes/admin_sidebar.php'); ?>

<div class="container-main">
    <div class="page-header" data-print-date="<?php echo date('F j, Y, g:i A'); ?>">
        <h1 class="page-title">Inventory Management</h1>
        <div class="page-actions">
            <button class="btn-minimal btn-secondary-minimal" onclick="window.print()">
                <i class="bi bi-printer"></i> Print Document
            </button>
            <button class="btn-minimal btn-primary-minimal" data-bs-toggle="modal" data-bs-target="#addItemModal">
                <i class="bi bi-plus-lg"></i> Add New Item
            </button>
        </div>
    </div>

    <!-- Filter Card -->
    <div class="filter-card">
        <form method="GET" class="row g-3">
            <div class="col-md-4">
                <label class="filter-label">Search Item</label>
                <input type="text" name="search" class="form-control form-control-minimal" placeholder="Search by item name..." value="<?= htmlspecialchars($_GET['search'] ?? '') ?>">
            </div>
            <div class="col-md-3">
                <label class="filter-label">Unit Type</label>
                <select name="unit" class="form-select form-select-minimal">
                    <option>All</option>
                    <option <?= (($_GET['unit'] ?? '') == 'piece') ? 'selected' : '' ?>>piece</option>
                    <option <?= (($_GET['unit'] ?? '') == 'box') ? 'selected' : '' ?>>box</option>
                    <option <?= (($_GET['unit'] ?? '') == 'ream') ? 'selected' : '' ?>>ream</option>
                    <option <?= (($_GET['unit'] ?? '') == 'pack') ? 'selected' : '' ?>>pack</option>
                </select>
            </div>
            <div class="col-md-3">
                <label class="filter-label">Stock Status</label>
                <select name="status" class="form-select form-select-minimal">
                    <option>All</option>
                    <option <?= (($_GET['status'] ?? '') == 'In Stock') ? 'selected' : '' ?>>In Stock</option>
                    <option <?= (($_GET['status'] ?? '') == 'Low Stock') ? 'selected' : '' ?>>Low Stock</option>
                </select>
            </div>
            <div class="col-md-2 d-flex align-items-end">
                <button type="submit" class="btn-minimal btn-primary-minimal w-100">
                    <i class="bi bi-funnel"></i> Filter
                </button>
            </div>
        </form>
    </div>

    <!-- Inventory Table -->
    <div class="section-card">
        <div class="table-responsive">
            <table class="table table-minimal">
                <thead>
                    <tr>
                        <th>Stock No.</th>
                        <th>Item Name</th>
                        <th>Unit</th>
                        <th>Quantity</th>
                        <th>Reorder Level</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($result->num_rows > 0): ?>
                        <?php while ($row = $result->fetch_assoc()): ?>
                            <?php $is_low = $row['stock_qty'] <= $row['reorder_level']; ?>
                            <tr class="<?= $is_low ? 'low-stock' : ''; ?>">
                                <td><span class="stock-number"><?= htmlspecialchars($row['stock_number']); ?></span></td>
                                <td><?= htmlspecialchars($row['item_name']); ?></td>
                                <td><?= htmlspecialchars($row['unit']); ?></td>
                                <td><strong><?= $row['stock_qty']; ?></strong></td>
                                <td><?= $row['reorder_level']; ?></td>
                                <td>
                                    <?= $is_low 
                                        ? '<span class="badge-minimal badge-danger"><i class="bi bi-exclamation-circle"></i> Low Stock</span>' 
                                        : '<span class="badge-minimal badge-success"><i class="bi bi-check-circle"></i> In Stock</span>'; ?>
                                </td>
                                <td>
                                    <div class="action-buttons">
                                        <button class="btn-action btn-action-update" data-bs-toggle="modal" data-bs-target="#restockModal<?= $row['id']; ?>">
                                            <i class="bi bi-arrow-up-circle"></i> <?= $is_low ? 'Restock' : 'Update' ?>
                                        </button>
                                        <a href="?delete=<?= $row['id']; ?>" class="btn-action btn-action-delete" onclick="return confirm('Are you sure you want to delete this item?');">
                                            <i class="bi bi-trash"></i> Delete
                                        </a>
                                    </div>
                                </td>
                            </tr>

                            <!-- Restock Modal -->
                            <div class="modal fade" id="restockModal<?= $row['id']; ?>" tabindex="-1">
                                <div class="modal-dialog">
                                    <div class="modal-content">
                                        <form method="POST">
                                            <div class="modal-header modal-header-warning">
                                                <h5 class="modal-title">Update Stock - <?= htmlspecialchars($row['item_name']); ?></h5>
                                                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                            </div>
                                            <div class="modal-body">
                                                <input type="hidden" name="item_id" value="<?= $row['id']; ?>">
                                                <div class="mb-3">
                                                    <label class="form-label-minimal">Current Stock</label>
                                                    <input type="text" class="form-control form-control-minimal" value="<?= $row['stock_qty']; ?> <?= htmlspecialchars($row['unit']); ?>" disabled>
                                                </div>
                                                <div class="mb-3">
                                                    <label class="form-label-minimal">Add Quantity</label>
                                                    <input type="number" class="form-control form-control-minimal" name="add_qty" min="1" required placeholder="Enter quantity to add">
                                                </div>
                                            </div>
                                            <div class="modal-footer">
                                                <button type="button" class="btn-minimal btn-secondary-minimal" data-bs-dismiss="modal">Cancel</button>
                                                <button type="submit" name="restock_item" class="btn-minimal btn-primary-minimal">
                                                    <i class="bi bi-check-lg"></i> Update Stock
                                                </button>
                                            </div>
                                        </form>
                                    </div>
                                </div>
                            </div>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="7">
                                <div class="empty-state">
                                    <i class="bi bi-inbox"></i>
                                    <p>No items found. Add your first inventory item to get started.</p>
                                </div>
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Add Item Modal -->
<div class="modal fade" id="addItemModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST">
                <div class="modal-header modal-header-success">
                    <h5 class="modal-title">Add New Item</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label-minimal">Item Name</label>
                        <input type="text" class="form-control form-control-minimal" name="item_name" required placeholder="e.g. Bond Paper">
                    </div>
                    <div class="mb-3">
                        <label class="form-label-minimal">Unit</label>
                        <select name="unit" class="form-select form-select-minimal" required>
                            <option value="">Select unit...</option>
                            <option value="piece">Piece</option>
                            <option value="box">Box</option>
                            <option value="ream">Ream</option>
                            <option value="pack">Pack</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label-minimal">Initial Stock Quantity</label>
                        <input type="number" class="form-control form-control-minimal" name="stock_qty" min="0" required placeholder="0">
                    </div>
                    <div class="mb-3">
                        <label class="form-label-minimal">Reorder Level</label>
                        <input type="number" class="form-control form-control-minimal" name="reorder_level" min="0" required placeholder="Set minimum stock alert level">
                        <small class="text-muted">You'll be notified when stock falls below this level</small>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn-minimal btn-secondary-minimal" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="add_item" class="btn-minimal btn-primary-minimal">
                        <i class="bi bi-plus-lg"></i> Add Item
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<style>
@media print {
    /* Hide all navigation and UI elements */
    .admin-sidebar,
    .page-actions,
    .filter-card,
    .action-buttons,
    .modal,
    .btn,
    nav,
    footer,
    .toggle-btn,
    .brand,
    .sidebar-footer {
        display: none !important;
    }
    
    /* Document styling */
    body {
        background: white !important;
        color: black !important;
        font-family: 'Times New Roman', Times, serif !important;
        font-size: 12pt;
        line-height: 1.4;
        margin: 0;
        padding: 0;
    }
    
    .container-main {
        margin: 0 !important;
        padding: 0.1in !important;
        max-width: 8.5in !important;
        margin-left: 0 !important;
        padding-top: 0.1in !important;
    }
    
    /* Document header */
    .page-header {
        text-align: center !important;
        margin-bottom: 30px !important;
        display: block !important;
        border-bottom: 2px solid #000 !important;
        padding-bottom: 20px !important;
    }
    
    .page-title {
        font-size: 24pt !important;
        color: black !important;
        margin-bottom: 0 !important;
        font-weight: bold !important;
        text-transform: uppercase !important;
        letter-spacing: 2px !important;
    }
    
    /* Remove table styling and create document format */
    .section-card {
        border: none !important;
        box-shadow: none !important;
        margin: 0 !important;
        page-break-inside: avoid;
    }
    
    .table {
        display: none !important;
    }
    
    /* Create document-style layout */
    .section-card::after {
        content: '';
        display: block;
        margin-top: 20px;
    }
    
    /* Generate document content */
    .section-card[data-section-title="Inventory Report"]::before {
        content: "INVENTORY REPORT";
        display: block;
        font-size: 16pt;
        font-weight: bold;
        margin-bottom: 20px;
        text-align: center;
        text-transform: uppercase;
        border-bottom: 1px solid #000;
        padding-bottom: 10px;
    }
    
    /* Document footer info */
    .page-header::after {
        content: "Printed on: " attr(data-print-date) " | Page: 1";
        display: block;
        font-size: 10pt;
        margin-top: 10px;
        color: #666;
        text-align: right;
    }
    
    /* Create custom document table */
    @page {
        margin: 0.5in;
    }
    
    /* Hide responsive wrapper */
    .table-responsive {
        overflow: visible !important;
    }
}
</style>

<!-- Document Content for Print -->
<div class="print-document" style="display: none;">
    <div class="document-header">
        <h1>INVENTORY REPORT</h1>
        <p>Western Mindanao State University</p>
        <p>Office Supply Request System</p>
        <p>Generated on: <?php echo date('F j, Y'); ?></p>
    </div>
    
    <div class="document-content">
        <h2>Current Inventory Status</h2>
        <table class="document-table">
            <thead>
                <tr>
                    <th>Stock Number</th>
                    <th>Item Description</th>
                    <th>Unit</th>
                    <th>Quantity</th>
                    <th>Reorder Level</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
                <?php 
                // Reset and re-run the query for print
                $stmt = $conn->prepare($query);
                $stmt->bind_param($types, ...$params);
                $stmt->execute();
                $print_result = $stmt->get_result();
                
                // Get low stock count
                $low_stock_query = "SELECT COUNT(*) as low_count FROM items WHERE stock_qty <= reorder_level";
                $low_stock_result = $conn->query($low_stock_query);
                $low_stock_count = $low_stock_result->fetch_assoc()['low_count'];
                
                if ($print_result->num_rows > 0): 
                    while ($row = $print_result->fetch_assoc()): 
                        $is_low = $row['stock_qty'] <= $row['reorder_level'];
                ?>
                <tr>
                    <td><?= htmlspecialchars($row['stock_number']); ?></td>
                    <td><?= htmlspecialchars($row['item_name']); ?></td>
                    <td><?= htmlspecialchars($row['unit']); ?></td>
                    <td><?= $row['stock_qty']; ?></td>
                    <td><?= $row['reorder_level']; ?></td>
                    <td><?= $is_low ? 'LOW STOCK' : 'IN STOCK'; ?></td>
                </tr>
                <?php 
                    endwhile; 
                else: 
                ?>
                <tr>
                    <td colspan="6" style="text-align: center; font-style: italic;">No inventory items found.</td>
                </tr>
                <?php endif; ?>
            </tbody>
        </table>
        
        <div class="document-summary">
            <h3>Summary</h3>
            <p>Total Items: <?php echo $print_result->num_rows; ?></p>
            <p>Low Stock Items: <?php echo $low_stock_count; ?></p>
            <p>Generated by: System Administrator</p>
        </div>
    </div>
</div>

<style>
.print-document {
    font-family: 'Times New Roman', Times, serif;
    line-height: 1.4;
}

.document-header {
    text-align: center;
    margin-bottom: 30px;
    border-bottom: 2px solid #000;
    padding-bottom: 20px;
    margin-top: 0;
}

.document-header h1 {
    font-size: 24pt;
    font-weight: bold;
    text-transform: uppercase;
    letter-spacing: 2px;
    margin: 0 0 10px 0;
}

.document-header p {
    margin: 5px 0;
    font-size: 12pt;
}

.document-content h2 {
    font-size: 16pt;
    font-weight: bold;
    margin: 20px 0 15px 0;
    text-transform: uppercase;
}

.document-table {
    width: 100%;
    border-collapse: collapse;
    margin: 20px 0;
    font-size: 10pt;
}

.document-table th,
.document-table td {
    border: 1px solid #000;
    padding: 8px;
    text-align: left;
}

.document-table th {
    background-color: #f5f5f5;
    font-weight: bold;
    text-align: center;
}

.document-summary {
    margin-top: 30px;
    padding-top: 15px;
    border-top: 1px solid #000;
}

.document-summary h3 {
    font-size: 14pt;
    font-weight: bold;
    margin: 0 0 10px 0;
}

.document-summary p {
    margin: 5px 0;
    font-size: 11pt;
}

@media print {
    .print-document {
        display: block !important;
    }
    
    .container-main > *:not(.print-document) {
        display: none !important;
    }
}
</style>
</body>
</html>