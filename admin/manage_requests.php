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
    $stmt = $conn->prepare("UPDATE requests SET status = 'approved' WHERE id = ?");
    $stmt->bind_param("i", $request_id);
    $stmt->execute();

    // Store release schedule
    $schedule_stmt = $conn->prepare("INSERT INTO release_schedule (request_id, release_date) VALUES (?, ?)");
    $schedule_stmt->bind_param("is", $request_id, $release_date);
    $schedule_stmt->execute();
    $schedule_stmt->close();

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

    // Send notifications to all relevant parties (excluding the admin who approved)
    require_once('../includes/notifications.php');
    $admin_id = $_SESSION['user_id'] ?? null;
    send_request_status_notification($conn, $request_id, 'approved', null, $admin_id);

    // Log the approval action
    $role = 'supply_head';
    $remarks = "Approved with release scheduled for " . date('M d, Y', strtotime($release_date));
    $ia = $conn->prepare("INSERT INTO request_actions (request_id, action_by, role, action_type, comment, created_at) VALUES (?, ?, ?, 'approved', ?, NOW())");
    $ia->bind_param("iiss", $request_id, $admin_id, $role, $remarks);
    $ia->execute();
    $ia->close();

    $_SESSION['success'] = "Request #$request_id approved and scheduled for release.";
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

// Handle search functionality
$search = $_GET['search'] ?? '';
$search_where = '';
$search_params = [];
if (!empty($search)) {
    $search_where = " AND (r.id LIKE ? OR r.request_id LIKE ? OR u.first_name LIKE ? OR u.last_name LIKE ? OR c.name LIKE ? OR r.status LIKE ?)";
    $search_term = '%' . $search . '%';
    $search_params = array_fill(0, 6, $search_term);
}

// Fetch only requests that are pending final approval
$query = "
    SELECT r.*, u.first_name, u.middle_name, u.last_name, c.name AS college_office
    FROM requests r
    LEFT JOIN users u ON r.requester_id = u.id
    LEFT JOIN college_offices c ON u.college_office_id = c.id
    WHERE r.status = 'for_final_approval'$search_where
    ORDER BY r.created_at DESC
";
$stmt = $conn->prepare($query);
if (!empty($search)) {
    $param_types = str_repeat('s', 6);
    $stmt->bind_param($param_types, ...$search_params);
}
$result = $stmt->execute() ? $stmt->get_result() : false;

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

        /* Filter Card */
        .filter-card {
            background: white;
            border: 1px solid var(--gray-200);
            border-radius: 12px;
            padding: 1.5rem;
            margin-bottom: 1.5rem;
        }

        .filter-label {
            font-size: 0.875rem;
            font-weight: 500;
            color: var(--gray-700);
            margin-bottom: 0.5rem;
            display: block;
        }

        .form-control-minimal {
            border: 1px solid var(--gray-300);
            border-radius: 8px;
            padding: 0.625rem 0.875rem;
            font-size: 0.9375rem;
            transition: all 0.2s ease;
            background: white;
        }

        .form-control-minimal:focus {
            outline: none;
            border-color: var(--red-primary);
            box-shadow: 0 0 0 0.2rem rgba(231, 76, 60, 0.1);
        }

        .form-select-minimal {
            border: 1px solid var(--gray-300);
            border-radius: 8px;
            padding: 0.625rem 0.875rem;
            font-size: 0.9375rem;
            transition: all 0.2s ease;
            background: white;
        }

        .form-select-minimal:focus {
            outline: none;
            border-color: var(--red-primary);
            box-shadow: 0 0 0 0.2rem rgba(231, 76, 60, 0.1);
        }

        /* Search row (matches design image) */
        .search-row {
            display: flex;
            gap: 1rem;
            align-items: center;
            width: 100%;
        }

        .search-input {
            flex: 1 1 auto;
            border: 1px solid var(--gray-300);
            border-radius: 12px;
            padding: 0 1rem;
            font-size: 0.95rem;
            background: white;
            transition: all 0.15s ease;
            height: 52px;
            display: flex;
            align-items: center;
        }

        .search-input:focus {
            outline: none;
            border-color: var(--red-primary);
            box-shadow: 0 0 0 0.12rem rgba(231, 76, 60, 0.08);
        }

        .search-btn {
            background-color: var(--red-primary);
            color: white;
            border: none;
            padding: 0 1.25rem;
            min-width: 150px;
            height: 52px;
            border-radius: 12px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
            font-weight: 600;
            box-shadow: none;
            cursor: pointer;
            line-height: 1;
        }

        .search-btn:hover {
            background-color: var(--red-dark);
            transform: translateY(-1px);
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

        /* Responsive */
        @media (max-width: 768px) {
            .container-main {
                padding: 1.5rem 1rem;
            }

            .page-title {
                font-size: 1.5rem;
            }

            .filter-card {
                padding: 1rem;
            }

            .table-minimal thead th,
            .table-minimal tbody td {
                padding: 0.75rem 1rem;
            }
        }
    </style>
</head>
<body>
<?php include('../includes/admin_sidebar.php'); ?>
<div class="container-main">
    <div class="page-header">
        <h1 class="page-title">Request Management</h1>
    </div>

    <!-- Filter Card -->
    <div class="filter-card">
        <form method="GET">
            <label class="filter-label">Search Records</label>
            <div class="search-row">
                <input type="text" name="search" class="search-input" placeholder="Search by Request ID, Requester, College/Office, or Status..." value="<?= htmlspecialchars($search) ?>">
                <button type="submit" class="search-btn">
                    <i class="bi bi-funnel"></i> Search
                </button>
            </div>
        </form>
        <?php if (!empty($search)): ?>
            <div class="mt-2">
                <small class="text-muted">Showing results for "<?= htmlspecialchars($search) ?>"</small>
                <a href="?" class="ms-2 text-muted"><i class="bi bi-x"></i> Clear</a>
            </div>
        <?php endif; ?>
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