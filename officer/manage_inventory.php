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
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        :root {
            --red-primary: #dc3545;
            --red-dark: #c82333;
            --red-light: #f8d7da;
            --gray-50: #fafafa;
            --gray-100: #f5f5f5;
            --gray-200: #eeeeee;
            --gray-300: #e0e0e0;
            --gray-700: #616161;
            --gray-900: #212121;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Inter', sans-serif;
            background-color: var(--gray-50);
            color: var(--gray-900);
            line-height: 1.6;
        }

        .container-main {
            max-width: 1200px;
            margin: 0 auto;
            padding: 2rem 1.5rem;
        }

        /* Page Header */
        .page-header {
            margin-bottom: 2rem;
        }

        .page-title {
            font-size: 1.75rem;
            font-weight: 600;
            color: var(--gray-900);
            letter-spacing: -0.5px;
            margin-bottom: 0.5rem;
        }

        .page-subtitle {
            color: var(--gray-700);
            font-size: 0.9375rem;
            margin-bottom: 0;
        }

        /* Alert Messages */
        .alert {
            border-radius: 8px;
            border: none;
            box-shadow: 0 0.125rem 0.25rem rgba(0, 0, 0, 0.075);
            margin-bottom: 1.5rem;
        }

        .alert-info {
            background-color: #d1ecf1;
            color: #0c5460;
            border-color: #bee5eb;
        }

        /* Filter Card */
        .filter-card {
            background: white;
            border-radius: 12px;
            border: 1px solid var(--gray-200);
            padding: 1.5rem;
            margin-bottom: 2rem;
            box-shadow: 0 0.125rem 0.25rem rgba(0, 0, 0, 0.075);
        }

        .filter-label {
            font-size: 0.875rem;
            color: var(--gray-700);
            font-weight: 500;
            margin-bottom: 0.5rem;
        }

        /* Cards */
        .section-card {
            background: white;
            border-radius: 12px;
            border: 1px solid var(--gray-200);
            overflow: hidden;
            margin-bottom: 2rem;
            box-shadow: 0 0.125rem 0.25rem rgba(0, 0, 0, 0.075);
        }

        /* Form Elements */
        .form-control-minimal,
        .form-select-minimal {
            border: 1px solid var(--gray-300);
            border-radius: 8px;
            padding: 0.625rem 0.875rem;
            font-size: 0.9375rem;
            transition: all 0.2s ease;
        }

        .form-control-minimal:focus,
        .form-select-minimal:focus {
            border-color: var(--red-primary);
            box-shadow: 0 0 0 0.2rem rgba(220, 53, 69, 0.1);
            outline: none;
        }

        /* Buttons */
        .btn-minimal {
            padding: 0.625rem 1.25rem;
            border-radius: 8px;
            font-weight: 500;
            font-size: 0.9375rem;
            border: none;
            transition: all 0.2s ease;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            cursor: pointer;
        }

        .btn-primary-minimal {
            background-color: var(--red-primary);
            color: white;
        }

        .btn-primary-minimal:hover {
            background-color: var(--red-dark);
            transform: translateY(-1px);
        }

        /* Tables */
        .table-minimal {
            margin: 0;
            width: 100%;
        }

        .table-minimal thead th {
            background: var(--gray-50);
            color: var(--gray-700);
            font-weight: 600;
            font-size: 0.75rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            padding: 1rem 1.5rem;
            border: none;
            border-bottom: 1px solid var(--gray-200);
            text-align: left;
        }

        .table-minimal tbody td {
            padding: 1rem 1.5rem;
            color: var(--gray-900);
            font-size: 0.9375rem;
            border: none;
            border-bottom: 1px solid var(--gray-100);
            vertical-align: middle;
        }

        .table-minimal tbody tr:last-child td {
            border-bottom: none;
        }

        .table-minimal tbody tr:hover {
            background-color: var(--gray-50);
        }

        .table-minimal tbody tr.low-stock {
            background-color: #fff8e1;
        }

        .table-minimal tbody tr.low-stock:hover {
            background-color: #ffecb3;
        }

        /* Stock Number */
        .stock-number {
            font-family: 'Courier New', monospace;
            font-weight: 600;
            color: var(--gray-700);
        }

        /* Badges */
        .badge-minimal {
            display: inline-flex;
            align-items: center;
            padding: 0.35rem 0.75rem;
            border-radius: 6px;
            font-size: 0.8125rem;
            font-weight: 500;
            border: 1px solid;
            gap: 0.375rem;
        }

        .badge-success {
            background-color: #d4edda;
            color: #155724;
            border-color: #c3e6cb;
        }

        .badge-danger {
            background-color: var(--red-light);
            color: #721c24;
            border-color: #f5c6cb;
        }

        /* Action Buttons */
        .action-buttons {
            display: flex;
            gap: 0.5rem;
            align-items: center;
        }

        .btn-action {
            padding: 0.4rem 0.875rem;
            border-radius: 6px;
            font-weight: 500;
            font-size: 0.875rem;
            border: 1px solid;
            transition: all 0.2s ease;
            display: inline-flex;
            align-items: center;
            gap: 0.375rem;
            cursor: pointer;
            text-decoration: none;
        }

        .btn-action:disabled {
            opacity: 0.6;
            cursor: not-allowed;
        }

        .btn-action-warning {
            background: #fff3cd;
            color: #856404;
            border-color: #ffeaa7;
        }

        .btn-action-warning:hover:not(:disabled) {
            background: #ffeaa7;
            border-color: #ffc107;
            transform: translateY(-1px);
        }

        /* Empty State */
        .empty-state {
            padding: 3rem;
            text-align: center;
            color: var(--gray-700);
        }

        .empty-state i {
            font-size: 2rem;
            display: block;
            margin-bottom: 1rem;
            color: #adb5bd;
        }

        /* Stock Status Indicators */
        .stock-low {
            color: #b45309;
            font-weight: 600;
        }

        .stock-none {
            color: #b91c1c;
            font-weight: 700;
        }

        /* Responsive */
        @media (max-width: 768px) {
            .container-main {
                padding: 1.5rem 1rem;
            }

            .page-title {
                font-size: 1.5rem;
            }

            .filter-card {
                padding: 1.25rem;
            }

            .table-minimal thead th,
            .table-minimal tbody td {
                padding: 0.75rem 1rem;
            }

            .action-buttons {
                flex-direction: column;
                align-items: stretch;
            }
        }
    </style>
</head>
<body>
<?php include('../includes/officer_navbar.php'); ?>

<div class="container-main">
    <div class="page-header">
        <h1 class="page-title">Inventory Monitor</h1>
        <p class="page-subtitle">View stock levels and send low-stock alerts to the supply head.</p>
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
