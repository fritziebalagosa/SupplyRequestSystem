<?php
session_start();
include('../config/db.php');
include('../includes/csrf.php');

// Handle Approve Request
if (isset($_POST['approve_request'])) {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $_SESSION['error'] = 'Security check failed. Please try again.';
        header('Location: manage_requests.php');
        exit();
    }
    $request_id = $_POST['request_id'];
    $release_date = $_POST['release_date'] ?? '';

    if (empty($release_date)) {
        $_SESSION['error'] = 'Please select a release date before approving.';
        header('Location: manage_requests.php');
        exit();
    }

    // Update request status and release date
    $stmt = $conn->prepare("UPDATE requests SET status = 'approved', release_date = ? WHERE id = ?");
    $stmt->bind_param("si", $release_date, $request_id);
    $stmt->execute();

    // Deduct stock quantities (use approved_quantity when column exists)
    $colCheck = $conn->query("SHOW COLUMNS FROM request_items LIKE 'approved_quantity'");
    $hasApproved = ($colCheck && $colCheck->num_rows > 0);
    if ($hasApproved) {
        $update_stock = $conn->prepare("UPDATE items JOIN request_items ON items.id = request_items.item_id SET items.stock_qty = items.stock_qty - COALESCE(request_items.approved_quantity, request_items.quantity) WHERE request_items.request_id = ?");
    } else {
        $update_stock = $conn->prepare("UPDATE items JOIN request_items ON items.id = request_items.item_id SET items.stock_qty = items.stock_qty - request_items.quantity WHERE request_items.request_id = ?");
    }
    $update_stock->bind_param("i", $request_id);
    $update_stock->execute();

    $_SESSION['success'] = "Request #$request_id approved and stock updated.";
    header("Location: manage_requests.php");
    exit();
}

// Handle Reject Request
if (isset($_POST['reject_request'])) {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $_SESSION['error'] = 'Security check failed. Please try again.';
        header('Location: manage_requests.php');
        exit();
    }
    $request_id = $_POST['request_id'];
    $remarks = $_POST['remarks'];

    // Update status only (no remarks column) and log reason in request_actions
    $stmt = $conn->prepare("UPDATE requests SET status = 'rejected' WHERE id = ?");
    $stmt->bind_param("i", $request_id);
    $stmt->execute();

    // Log the action with admin role
    if (session_status() === PHP_SESSION_NONE) session_start();
    $admin_id = $_SESSION['user_id'] ?? null;
    $role = 'supply_head';
    $ia = $conn->prepare("INSERT INTO request_actions (request_id, action_by, role, action_type, comment, created_at) VALUES (?, ?, ?, 'rejected', ?, NOW())");
    $ia->bind_param("iiss", $request_id, $admin_id, $role, $remarks);
    $ia->execute();

    $_SESSION['error'] = "Request #$request_id has been rejected.";
    header("Location: manage_requests.php");
    exit();
}

// Fetch only requests that are pending final approval
$query = "
    SELECT r.*, u.first_name, u.middle_name, u.last_name, c.name AS college_office
    FROM requests r
    LEFT JOIN users u ON r.requester_id = u.id
    LEFT JOIN college_offices c ON u.college_office_id = c.id
    WHERE r.status = 'for_final_approval'
    ORDER BY r.created_at DESC
";
$result = $conn->query($query) or die('Query failed: ' . $conn->error);

if (isset($_GET['debug'])) {
    echo "<pre>DEBUG manage_requests\\nSQL: " . htmlspecialchars($query) . "\\nRows: " . $result->num_rows . "</pre>";
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Requests - WMSU OSRS</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css" rel="stylesheet">
    <link rel="stylesheet" href="../styles/admin_nav.css">
    <style>
        /* Page Header */
        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 2rem;
        }

        .page-title {
            font-size: 1.75rem;
            font-weight: 600;
            color: var(--gray-900);
            letter-spacing: -0.5px;
            margin: 0;
        }

        /* Button Styles */
        .btn-minimal {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.5rem 1rem;
            border-radius: 6px;
            font-size: 0.875rem;
            font-weight: 500;
            text-decoration: none;
            border: 1px solid var(--gray-300);
            background: white;
            color: var(--gray-700);
            transition: all 0.2s ease;
        }

        .btn-minimal:hover {
            background: var(--gray-50);
            border-color: var(--gray-400);
            color: var(--gray-900);
            text-decoration: none;
        }

        .btn-sm-minimal {
            padding: 0.375rem 0.75rem;
            font-size: 0.8125rem;
        }

        .btn-action-view {
            background: var(--red-primary);
            color: white;
            border-color: var(--red-primary);
        }

        .btn-action-view:hover {
            background: var(--red-dark);
            border-color: var(--red-dark);
            color: white;
        }

        /* Request ID styling */
        .request-id {
            font-family: 'Courier New', monospace;
            font-weight: 600;
            color: var(--red-primary);
            font-size: 0.875rem;
        }

        /* Status badge consistency */
        .badge-minimal {
            display: inline-flex;
            align-items: center;
            gap: 0.375rem;
            padding: 0.35rem 0.75rem;
            border-radius: 6px;
            font-size: 0.8125rem;
            font-weight: 500;
            border: 1px solid;
        }
    </style>
</head>
<body>
<?php include('../includes/admin_sidebar.php'); ?>

<div class="container-main">
    <div class="page-header">
        <h1 class="page-title">Request Management</h1>
    </div>

    <!-- Alert Messages -->
    <?php if (isset($_SESSION['success'])): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            <?php echo $_SESSION['success']; unset($_SESSION['success']); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php elseif (isset($_SESSION['error'])): ?>
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <?php echo $_SESSION['error']; unset($_SESSION['error']); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <!-- Requests Table -->
    <div class="section-card">
        <div class="section-header">
            <h2>Pending Requests</h2>
        </div>
        <div class="section-body">
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
                                    <?php
                                    $status_style = '';
                                    $status_icon = '';
                                    if ($row['status'] === 'approved') {
                                        $status_style = 'background:#d4edda;color:#155724;border-color:#c3e6cb';
                                        $status_icon = '<i class="bi bi-check-circle"></i>';
                                    } elseif ($row['status'] === 'rejected') {
                                        $status_style = 'background:#f8d7da;color:#721c24;border-color:#f5c6cb';
                                        $status_icon = '<i class="bi bi-x-circle"></i>';
                                    } elseif (in_array($row['status'], ['pending', 'for_approval', 'for_final_approval', 'forwarded'])) {
                                        $status_style = 'background:#fff3cd;color:#856404;border-color:#ffeaa7';
                                        $status_icon = '<i class="bi bi-clock"></i>';
                                    }
                                    ?>
                                    <span class="badge-minimal" style="<?php echo $status_style; ?>">
                                        <?php echo $status_icon . ' ' . htmlspecialchars(ucwords(str_replace('_', ' ', $row['status']))); ?>
                                    </span>
                                </td>
                                <td>
                                    <?php 
                                    // Get release date and time from release_schedule
                                    $schedule_query = $conn->prepare("SELECT release_date FROM release_schedule WHERE request_id = ? LIMIT 1");
                                    $schedule_query->bind_param("i", $row['id']);
                                    $schedule_query->execute();
                                    $schedule_result = $schedule_query->get_result()->fetch_assoc();
                                    $schedule_query->close();
                                    
                                    if ($schedule_result && $schedule_result['release_date']) {
                                        echo date("M d, Y", strtotime($schedule_result['release_date']));
                                        // Temporarily show default time until column is added
                                        echo " 9:00 AM";
                                    } else {
                                        echo '—';
                                    }
                                    ?>
                                </td>
                                <td>
                                    <a class="btn-minimal btn-sm-minimal btn-action-view" href="view_request.php?id=<?php echo $row['id']; ?>">
                                        <i class="bi bi-eye"></i> View
                                    </a>
                                    <?php if ($row['status'] === 'approved' || $row['status'] === 'completed'): ?>
                                        <br><br>
                                        <a class="btn-minimal btn-sm-minimal" href="adjust_schedule.php?id=<?php echo $row['id']; ?>" title="Adjust Release Schedule">
                                            <i class="bi bi-calendar-plus"></i> Adjust Schedule
                                        </a>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="7" class="text-center">
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