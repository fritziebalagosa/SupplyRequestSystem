<?php
include('../config/db.php');
session_start();

// Handle Approve Request
if (isset($_POST['approve_request'])) {
    $request_id = $_POST['request_id'];
    $release_date = $_POST['release_date'];

    // Update request status and release date
    $stmt = $conn->prepare("UPDATE requests SET status = 'approved', release_date = ? WHERE id = ?");
    $stmt->bind_param("si", $release_date, $request_id);
    $stmt->execute();

    // Deduct stock quantities
    $update_stock = $conn->prepare("
        UPDATE items 
        JOIN request_items ON items.id = request_items.item_id
        SET items.stock_qty = items.stock_qty - request_items.quantity
        WHERE request_items.request_id = ?
    ");
    $update_stock->bind_param("i", $request_id);
    $update_stock->execute();

    $_SESSION['success'] = "Request #$request_id approved and stock updated.";
    header("Location: manage_requests.php");
    exit();
}

// Handle Reject Request
if (isset($_POST['reject_request'])) {
    $request_id = $_POST['request_id'];
    $remarks = $_POST['remarks'];

    $stmt = $conn->prepare("UPDATE requests SET status = 'rejected', remarks = ? WHERE id = ?");
    $stmt->bind_param("si", $remarks, $request_id);
    $stmt->execute();

    $_SESSION['error'] = "Request #$request_id has been rejected.";
    header("Location: manage_requests.php");
    exit();
}

// Fetch all requests forwarded by the supply officer
$query = "
    SELECT r.*, u.first_name, u.middle_name, u.last_name, c.name AS college_office
    FROM requests r
    LEFT JOIN users u ON r.requester_id = u.id
    LEFT JOIN college_offices c ON u.college_office_id = c.id
    WHERE r.status IN ('pending', 'for_approval', 'forwarded')
    ORDER BY r.created_at DESC
";
$result = $conn->query($query) or die('Query failed: ' . $conn->error);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Requests - WMSU OSRS</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
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
            max-width: 1400px;
            margin: 0 auto;
            padding: 2rem 1.5rem;
        }

        .page-header {
            margin-bottom: 2rem;
        }

        .page-title {
            font-size: 1.75rem;
            font-weight: 600;
            color: var(--gray-900);
            letter-spacing: -0.5px;
            margin: 0;
        }

        /* Alert Messages */
        .alert-minimal {
            border-radius: 8px;
            padding: 1rem 1.25rem;
            margin-bottom: 1.5rem;
            border: 1px solid;
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .alert-success {
            background-color: #d4edda;
            color: #155724;
            border-color: #c3e6cb;
        }

        .alert-danger {
            background-color: var(--red-light);
            color: #721c24;
            border-color: #f5c6cb;
        }

        .alert-minimal i {
            font-size: 1.25rem;
        }

        /* Table Section */
        .section-card {
            background: white;
            border-radius: 12px;
            border: 1px solid var(--gray-200);
            overflow: hidden;
        }

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
            text-align: center;
        }

        .table-minimal tbody td {
            padding: 1rem 1.5rem;
            color: var(--gray-900);
            font-size: 0.9375rem;
            border: none;
            border-bottom: 1px solid var(--gray-100);
            vertical-align: middle;
            text-align: center;
        }

        .table-minimal tbody tr:last-child td {
            border-bottom: none;
        }

        .table-minimal tbody tr:hover {
            background-color: var(--gray-50);
        }

        /* Request ID */
        .request-id {
            font-family: 'Courier New', monospace;
            font-weight: 600;
            color: var(--red-primary);
            font-size: 0.875rem;
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
        }

        .badge-warning {
            background-color: #fff3cd;
            color: #856404;
            border-color: #ffeaa7;
        }

        .badge-info {
            background-color: #d1ecf1;
            color: #0c5460;
            border-color: #bee5eb;
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
        }

        .btn-primary-minimal {
            background-color: var(--red-primary);
            color: white;
        }

        .btn-primary-minimal:hover {
            background-color: var(--red-dark);
            transform: translateY(-1px);
        }

        .btn-secondary-minimal {
            background-color: white;
            color: var(--gray-700);
            border: 1px solid var(--gray-300);
        }

        .btn-secondary-minimal:hover {
            background-color: var(--gray-50);
            border-color: var(--gray-700);
        }

        .btn-success-minimal {
            background-color: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }

        .btn-success-minimal:hover {
            background-color: #c3e6cb;
            border-color: #28a745;
        }

        .btn-danger-minimal {
            background-color: var(--red-light);
            color: #721c24;
            border: 1px solid #f5c6cb;
        }

        .btn-danger-minimal:hover {
            background-color: #f1b0b7;
            border-color: var(--red-primary);
        }

        .btn-sm-minimal {
            padding: 0.4rem 0.875rem;
            font-size: 0.875rem;
        }

        .btn-action-view {
            background-color: #d1ecf1;
            color: #0c5460;
            border: 1px solid #bee5eb;
        }

        .btn-action-view:hover {
            background-color: #bee5eb;
            border-color: #17a2b8;
            color: #0c5460;
        }

        /* Modal Styles */
        .modal-content {
            border-radius: 12px;
            border: 1px solid var(--gray-200);
        }

        .modal-header {
            border-bottom: 1px solid var(--gray-200);
            padding: 1.25rem 1.5rem;
            background-color: #d1ecf1;
            border-top-left-radius: 12px;
            border-top-right-radius: 12px;
        }

        .modal-title {
            font-size: 1.125rem;
            font-weight: 600;
            color: var(--gray-900);
        }

        .modal-body {
            padding: 1.5rem;
        }

        .modal-footer {
            border-top: 1px solid var(--gray-200);
            padding: 1rem 1.5rem;
        }

        .section-label {
            font-size: 0.875rem;
            font-weight: 600;
            color: var(--gray-700);
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 1rem;
        }

        .form-label-minimal {
            font-size: 0.875rem;
            color: var(--gray-700);
            font-weight: 500;
            margin-bottom: 0.5rem;
        }

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

        textarea.form-control-minimal {
            resize: vertical;
            min-height: 80px;
        }

        /* Items Table in Modal */
        .items-table {
            margin-bottom: 1.5rem;
        }

        .items-table table {
            width: 100%;
            border-collapse: collapse;
        }

        .items-table thead th {
            background: var(--gray-50);
            color: var(--gray-700);
            font-weight: 600;
            font-size: 0.75rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            padding: 0.75rem 1rem;
            border: 1px solid var(--gray-200);
        }

        .items-table tbody td {
            padding: 0.75rem 1rem;
            border: 1px solid var(--gray-200);
            font-size: 0.9375rem;
        }

        .items-table tbody tr:hover {
            background-color: var(--gray-50);
        }

        /* Action Buttons Group */
        .action-buttons-modal {
            display: flex;
            gap: 0.75rem;
            margin-top: 1.5rem;
        }

        .action-buttons-modal button {
            flex: 1;
        }

        /* Collapse Section */
        .reject-section {
            margin-top: 1.5rem;
            padding: 1.25rem;
            background-color: #fff5f5;
            border: 1px solid var(--red-light);
            border-radius: 8px;
        }

        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 3rem 1.5rem;
            color: var(--gray-700);
        }

        .empty-state i {
            font-size: 3rem;
            color: var(--gray-300);
            margin-bottom: 1rem;
        }

        /* Responsive */
        @media (max-width: 768px) {
            .container-main {
                padding: 1.5rem 1rem;
            }

            .page-title {
                font-size: 1.5rem;
            }

            .table-minimal thead th,
            .table-minimal tbody td {
                padding: 0.875rem 0.75rem;
                font-size: 0.875rem;
            }

            .action-buttons-modal {
                flex-direction: column;
            }
        }
    </style>
</head>
<body>
<?php include('../includes/admin_navbar.php'); ?>

<div class="container-main">
    <div class="page-header">
        <h1 class="page-title">Request Management</h1>
    </div>

    <!-- Alert Messages -->
    <?php if (isset($_SESSION['success'])): ?>
        <div class="alert-minimal alert-success">
            <i class="bi bi-check-circle-fill"></i>
            <span><?php echo $_SESSION['success']; unset($_SESSION['success']); ?></span>
        </div>
    <?php elseif (isset($_SESSION['error'])): ?>
        <div class="alert-minimal alert-danger">
            <i class="bi bi-exclamation-circle-fill"></i>
            <span><?php echo $_SESSION['error']; unset($_SESSION['error']); ?></span>
        </div>
    <?php endif; ?>

    <!-- Requests Table -->
    <div class="section-card">
        <div class="table-responsive">
            <table class="table table-minimal">
                <thead>
                    <tr>
                        <th>Request ID</th>
                        <th>Requester</th>
                        <th>College/Office</th>
                        <th>Date Requested</th>
                        <th>Status</th>
                        <th>Release Date</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($result->num_rows > 0): ?>
                        <?php while ($row = $result->fetch_assoc()): ?>
                            <tr>
                                <td><span class="request-id">#<?php echo $row['id']; ?></span></td>
                                <td><?php echo htmlspecialchars($row['first_name'] . ' ' . ($row['middle_name'] ? $row['middle_name'][0] . '. ' : '') . $row['last_name']); ?></td>
                                <td><?php echo htmlspecialchars($row['college_office']); ?></td>
                                <td><?php echo date("M d, Y", strtotime($row['created_at'])); ?></td>
                                <td>
                                    <span class="badge-minimal badge-warning">
                                        <i class="bi bi-clock-history"></i> <?php echo htmlspecialchars(ucwords(str_replace('_', ' ', $row['status']))); ?>
                                    </span>
                                </td>
                                <td>
                                    <?php echo $row['release_date'] ? date("M d, Y", strtotime($row['release_date'])) : '—'; ?>
                                </td>
                                <td>
                                    <button class="btn-minimal btn-sm-minimal btn-action-view" data-bs-toggle="modal" data-bs-target="#viewRequest<?php echo $row['id']; ?>">
                                        <i class="bi bi-eye"></i> View Details
                                    </button>
                                </td>
                            </tr>

                            <!-- View Request Modal -->
                            <div class="modal fade" id="viewRequest<?php echo $row['id']; ?>" tabindex="-1">
                                <div class="modal-dialog modal-lg">
                                    <div class="modal-content">
                                        <div class="modal-header">
                                            <h5 class="modal-title">Request Details - #<?php echo $row['id']; ?></h5>
                                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                        </div>
                                        <div class="modal-body">
                                            <!-- Requester Info -->
                                            <div class="mb-4">
                                                <div class="row g-3">
                                                    <div class="col-md-6">
                                                        <label class="form-label-minimal">Requester</label>
                                                        <input type="text" class="form-control-minimal" value="<?php echo htmlspecialchars($row['first_name'] . ' ' . ($row['middle_name'] ? $row['middle_name'][0] . '. ' : '') . $row['last_name']); ?>" disabled>
                                                    </div>
                                                    <div class="col-md-6">
                                                        <label class="form-label-minimal">College/Office</label>
                                                        <input type="text" class="form-control-minimal" value="<?php echo htmlspecialchars($row['college_office']); ?>" disabled>
                                                    </div>
                                                </div>
                                            </div>

                                            <!-- Requested Items -->
                                            <h6 class="section-label">Requested Items</h6>
                                            <div class="items-table">
                                                <table>
                                                    <thead>
                                                        <tr>
                                                            <th>Item Name</th>
                                                            <th style="text-align: center;">Quantity</th>
                                                            <th style="text-align: center;">Unit</th>
                                                        </tr>
                                                    </thead>
                                                    <tbody>
                                                        <?php
                                                        $items_q = $conn->prepare("
                                                            SELECT i.item_name, i.unit, ri.quantity
                                                            FROM request_items ri
                                                            JOIN items i ON ri.item_id = i.id
                                                            WHERE ri.request_id = ?
                                                        ");
                                                        $items_q->bind_param("i", $row['id']);
                                                        $items_q->execute();
                                                        $items_result = $items_q->get_result();
                                                        while ($item = $items_result->fetch_assoc()):
                                                        ?>
                                                            <tr>
                                                                <td><?php echo htmlspecialchars($item['item_name']); ?></td>
                                                                <td style="text-align: center;"><strong><?php echo $item['quantity']; ?></strong></td>
                                                                <td style="text-align: center;"><?php echo htmlspecialchars($item['unit']); ?></td>
                                                            </tr>
                                                        <?php endwhile; ?>
                                                    </tbody>
                                                </table>
                                            </div>

                                            <!-- Action Form -->
                                            <form method="POST">
                                                <input type="hidden" name="request_id" value="<?php echo $row['id']; ?>">

                                                <div class="mb-3">
                                                    <label class="form-label-minimal">Assign Release Date</label>
                                                    <input type="date" class="form-control form-control-minimal" name="release_date" required>
                                                </div>

                                                <div class="action-buttons-modal">
                                                    <button type="submit" name="approve_request" class="btn-minimal btn-success-minimal">
                                                        <i class="bi bi-check-circle"></i> Approve Request
                                                    </button>
                                                    <button type="button" class="btn-minimal btn-danger-minimal" data-bs-toggle="collapse" data-bs-target="#reject<?php echo $row['id']; ?>">
                                                        <i class="bi bi-x-circle"></i> Reject Request
                                                    </button>
                                                </div>

                                                <!-- Reject Form -->
                                                <div id="reject<?php echo $row['id']; ?>" class="collapse">
                                                    <div class="reject-section">
                                                        <div class="mb-3">
                                                            <label class="form-label-minimal">Reason for Rejection</label>
                                                            <textarea name="remarks" class="form-control form-control-minimal" rows="3" placeholder="Enter the reason for rejecting this request..." required></textarea>
                                                        </div>
                                                        <button type="submit" name="reject_request" class="btn-minimal btn-danger-minimal">
                                                            <i class="bi bi-exclamation-triangle"></i> Confirm Rejection
                                                        </button>
                                                    </div>
                                                </div>
                                            </form>
                                        </div>
                                        <div class="modal-footer">
                                            <button type="button" class="btn-minimal btn-secondary-minimal" data-bs-dismiss="modal">Close</button>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="7">
                                <div class="empty-state">
                                    <i class="bi bi-inbox"></i>
                                    <p>No pending requests at the moment.</p>
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