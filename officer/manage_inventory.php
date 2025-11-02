<?php
include('../config/db.php');
session_start();

// only officers
// allow either legacy 'officer' or 'supply_officer' role name
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'] ?? '', ['officer','supply_officer'])) {
    header('Location: ../auth/log_in.php');
    exit;
}

$user_id = $_SESSION['user_id'];
$college_office_id = $_SESSION['college_office_id'] ?? null;

// Ensure alerts table exists for both GET and POST flows
$conn->query("CREATE TABLE IF NOT EXISTS low_stock_alerts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    item_id INT NOT NULL,
    sent_by INT NOT NULL,
    college_office_id INT DEFAULT NULL,
    status VARCHAR(32) DEFAULT 'open',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// Handle Send Low Stock Alert
if (isset($_POST['send_alert'])) {
    $item_id = (int)($_POST['item_id'] ?? 0);
    if ($item_id > 0) {
        // ensure alerts table exists
        $create = "CREATE TABLE IF NOT EXISTS low_stock_alerts (
            id INT AUTO_INCREMENT PRIMARY KEY,
            item_id INT NOT NULL,
            sent_by INT NOT NULL,
            college_office_id INT DEFAULT NULL,
            status VARCHAR(32) DEFAULT 'open',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
        $conn->query($create);

        // avoid duplicate open alert for same item and office
        $chk = $conn->prepare("SELECT id FROM low_stock_alerts WHERE item_id = ? AND college_office_id = ? AND status = 'open' LIMIT 1");
        $chk->bind_param('ii', $item_id, $college_office_id);
        $chk->execute();
        $cres = $chk->get_result();
        if ($cres && $cres->num_rows > 0) {
            $_SESSION['flash_message'] = 'An open low-stock alert for this item already exists.';
        } else {
            $ins = $conn->prepare("INSERT INTO low_stock_alerts (item_id, sent_by, college_office_id, status) VALUES (?, ?, ?, 'open')");
            $ins->bind_param('iii', $item_id, $user_id, $college_office_id);
            if ($ins->execute()) {
                $_SESSION['flash_message'] = 'Low-stock alert sent to supply head (admin).';
            } else {
                $_SESSION['flash_message'] = 'Failed to send alert: ' . htmlspecialchars($ins->error);
            }
            $ins->close();
        }
        $chk->close();
    }
    header('Location: manage_inventory.php');
    exit;
}

// Filtering
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
      AND (CASE WHEN stock_qty <= reorder_level THEN 'Low Stock' ELSE 'In Stock' END) LIKE ?
    ORDER BY item_name ASC
";

$stmt = $conn->prepare($query);
$stmt->bind_param('sss', $search, $unit_filter, $status_filter);
$stmt->execute();
$result = $stmt->get_result();

$flash = $_SESSION['flash_message'] ?? '';
unset($_SESSION['flash_message']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Officer - Inventory Monitor</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css" rel="stylesheet">
    <link rel="stylesheet" href="../styles/admin_inventory.css">
</head>
<body>
<?php include('../includes/officer_navbar.php'); ?>

<div class="container-main">
    <div class="page-header">
        <h1 class="page-title">Inventory Monitor</h1>
        <p class="text-muted">View stock levels and send low-stock alerts to the supply head.</p>
    </div>

    <?php if ($flash): ?>
        <div class="alert alert-info"><?= htmlspecialchars($flash) ?></div>
    <?php endif; ?>

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
                                        <?php if ($is_low): ?>
                                            <?php
                                            // check if open alert exists
                                            $q = $conn->prepare("SELECT id FROM low_stock_alerts WHERE item_id = ? AND college_office_id = ? AND status = 'open' LIMIT 1");
                                            $q->bind_param('ii', $row['id'], $college_office_id);
                                            $ok = false;
                                            if ($q->execute()) {
                                                $res = $q->get_result();
                                                $ok = ($res && $res->num_rows > 0);
                                            }
                                            $q->close();
                                            ?>
                                            <?php if ($ok): ?>
                                                <button class="btn-action btn-action-warning" disabled>
                                                    <i class="bi bi-bell"></i> Alert Sent
                                                </button>
                                            <?php else: ?>
                                                <form method="POST" style="display:inline">
                                                    <input type="hidden" name="item_id" value="<?= $row['id'] ?>">
                                                    <button type="submit" name="send_alert" class="btn-action btn-action-warning">
                                                        <i class="bi bi-bell"></i> Send Low Stock Alert
                                                    </button>
                                                </form>
                                            <?php endif; ?>
                                        <?php else: ?>
                                            <span class="text-muted">Monitoring</span>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="7">
                                <div class="empty-state">
                                    <i class="bi bi-inbox"></i>
                                    <p>No items found in inventory.</p>
                                </div>
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
