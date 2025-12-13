<?php
session_start();
include('../config/db.php');
include('../includes/csrf.php');

// require head role
if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'head') {
    header('Location: ../auth/log_in.php');
    exit;
}

$user_id = $_SESSION['user_id'];

// determine college_office_id
if (isset($_SESSION['college_office_id'])) {
    $college_office_id = $_SESSION['college_office_id'];
} else {
    $q = $conn->prepare("SELECT college_office_id FROM users WHERE id = ?");
    $q->bind_param("i", $user_id);
    $q->execute();
    $res = $q->get_result()->fetch_assoc();
    $college_office_id = $res['college_office_id'] ?? null;
    $q->close();
}

if (!$college_office_id) {
    die('Your account is not linked to a college/office. Please contact admin.');
}

// Handle actions posted from view page
$message = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && isset($_POST['request_db_id'])) {
    $action = $_POST['action']; // approve | reject | return
    $request_db_id = intval($_POST['request_db_id']);
    $comment = trim($_POST['comment'] ?? '');
    $csrf = $_POST['csrf_token'] ?? '';

    // Verify CSRF
    if (!verify_csrf_token($csrf)) {
        $_SESSION['flash_message'] = 'Invalid request (CSRF token).' ;
        header('Location: head_requests.php');
        exit;
    }

    // If returning a request, require a comment
    if ($action === 'return' && $comment === '') {
        // redirect back to view with message
        $_SESSION['flash_message'] = 'Please provide a comment when returning a request.';
        header('Location: view_request.php?id=' . $request_db_id);
        exit;
    }

    // verify request belongs to this college_office_id and get current status
    $v = $conn->prepare("SELECT status FROM requests WHERE id = ? AND college_office_id = ? LIMIT 1");
    $v->bind_param("ii", $request_db_id, $college_office_id);
    $v->execute();
    $vr = $v->get_result();
    if ($vr->num_rows === 0) {
        $message = "Request not found or you don't have permission.";
    } else {
        $request = $vr->fetch_assoc();
        
        // Check if request can be acted on (must be in pending statuses)
        if (!in_array($request['status'], ['pending_head', 'pending_officer', 'for_final_approval'])) {
            $message = "This request cannot be acted on in its current state.";
        } else {
        // determine update values
        if ($action === 'approve') {
            $new_status = 'pending_officer';
            $action_type = 'approved';
        } elseif ($action === 'reject') {
            $new_status = 'rejected';
            $action_type = 'rejected';
        } else { // return
            $new_status = 'returned';
            $action_type = 'returned';
        }

        // update requests table
        $u = $conn->prepare("UPDATE requests SET status = ? WHERE id = ?");
        $u->bind_param("si", $new_status, $request_db_id);
        if (!$u->execute()) {
            $message = 'Failed to update request status: ' . htmlspecialchars($u->error);
        } else {
            // insert into request_actions
            $ia = $conn->prepare("INSERT INTO request_actions (request_id, action_by, role, action_type, comment, created_at) VALUES (?, ?, ?, ?, ?, NOW())");
            $role = $_SESSION['role'];
            $ia->bind_param("iisss", $request_db_id, $user_id, $role, $action_type, $comment);
            $ia->execute();
            $ia->close();
            
            // Send notifications to all relevant parties
            require_once('../includes/notifications.php');
            send_request_status_notification($conn, $request_db_id, $new_status, $comment, $user_id);
            
            $message = 'Request ' . str_replace('_', ' ', $new_status) . ' successfully.';
        }
        $u->close();
        } // Close the status validation else block
    }
    $v->close();

    // redirect back to avoid resubmission
    $_SESSION['flash_message'] = $message;
    header('Location: head_requests.php');
    exit;
}

$flash = $_SESSION['flash_message'] ?? '';
unset($_SESSION['flash_message']);

// Handle search functionality
$search = $_GET['search'] ?? '';
$search_where = '';
$search_params = [];
if (!empty($search)) {
    $search_where = " AND (r.request_id LIKE ? OR u.first_name LIKE ? OR u.last_name LIKE ? OR it.item_name LIKE ? OR r.status LIKE ?)";
    $search_term = '%' . $search . '%';
    $search_params = array_fill(0, 5, $search_term);
}

$status_to_fetch = ['pending_head', 'pending_officer', 'for_final_approval'];
$status_placeholders = str_repeat('?,', count($status_to_fetch) - 1) . '?';
$sql = "SELECT r.id, r.request_id, r.status, r.created_at, u.first_name, u.last_name,
                        rs.release_date, rp.created_at as receipt_date,
                        COALESCE(GROUP_CONCAT(DISTINCT it.item_name SEPARATOR ', '), 'No items specified') AS items
                        FROM requests r
                        LEFT JOIN users u ON r.requester_id = u.id
                        LEFT JOIN release_schedule rs ON rs.request_id = r.id
                        LEFT JOIN release_proofs rp ON rp.request_id = r.id
                        LEFT JOIN request_items ri ON ri.request_id = r.id
                        LEFT JOIN items it ON ri.item_id = it.id
                        WHERE r.college_office_id = ? AND r.status IN ($status_placeholders)$search_where
                        GROUP BY r.id
                        ORDER BY r.created_at DESC";
$stmt = $conn->prepare($sql);
$param_types = 'i' . str_repeat('s', count($status_to_fetch)); // college_office_id is int, statuses are strings
if (!empty($search)) {
    $param_types .= str_repeat('s', 5); // 5 search parameters
    $stmt->bind_param($param_types, $college_office_id, ...$status_to_fetch, ...$search_params);
} else {
    $stmt->bind_param($param_types, $college_office_id, ...$status_to_fetch);
}
$stmt->execute();
$results = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();
// Also fetch approved requests to notify head when scheduled for release
$stmt2 = $conn->prepare("SELECT r.id, r.request_id, r.status, r.created_at, u.first_name, u.last_name,
                        rs.release_date, rp.created_at as receipt_date,
                        COALESCE(GROUP_CONCAT(DISTINCT it.item_name SEPARATOR ', '), 'No items specified') AS items
                        FROM requests r
                        LEFT JOIN users u ON r.requester_id = u.id
                        LEFT JOIN release_schedule rs ON rs.request_id = r.id
                        LEFT JOIN release_proofs rp ON rp.request_id = r.id
                        LEFT JOIN request_items ri ON ri.request_id = r.id
                        LEFT JOIN items it ON ri.item_id = it.id
                        WHERE r.status = 'approved'$search_where
                        GROUP BY r.id
                        ORDER BY r.created_at DESC");
if (!empty($search)) {
    $stmt2->bind_param('ssss', ...$search_params);
}
$stmt2->execute();
$approved = $stmt2->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt2->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Requests - Head</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        :root {
            --red-primary: #e74c3c;
            --red-dark: #c0392b;
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

        .page-title {
            font-size: 1.75rem;
            font-weight: 600;
            color: var(--gray-900);
            letter-spacing: -0.5px;
            margin-bottom: 1rem;
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

        /* Cards */
        .section-card {
            background: white;
            border-radius: 12px;
            border: 1px solid var(--gray-200);
            overflow: hidden;
            margin-bottom: 2rem;
            box-shadow: 0 0.125rem 0.25rem rgba(0, 0, 0, 0.075);
        }

        .section-header {
            padding: 1.25rem 1.5rem;
            border-bottom: 1px solid var(--gray-200);
            background: white;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .section-header h2 {
            font-size: 1.125rem;
            font-weight: 600;
            margin: 0;
            color: var(--gray-900);
        }

        .section-header i {
            color: var(--gray-700);
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

        .request-id {
            font-family: 'Courier New', monospace;
            font-weight: 600;
            color: var(--red-primary);
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

        .badge-pending {
            background-color: #fff3cd;
            color: #856404;
            border-color: #ffeaa7;
        }

        .badge-approved {
            background-color: #d4edda;
            color: #155724;
            border-color: #c3e6cb;
        }

        /* Buttons */
        .btn-minimal {
            padding: 0.4rem 0.875rem;
            border-radius: 6px;
            font-weight: 500;
            font-size: 0.875rem;
            border: 1px solid;
            transition: all 0.2s ease;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 0.375rem;
            cursor: pointer;
        }

        .btn-action-view {
            background: #d1ecf1;
            color: #0c5460;
            border-color: #bee5eb;
        }

        .btn-action-view:hover {
            background: #bee5eb;
            border-color: #17a2b8;
            color: #0c5460;
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
    <?php include('../includes/head_dean_navbar.php'); ?>
    
    <div class="container-main">
        <div class="page-header">
            <h1 class="page-title">Requests Awaiting Your Review</h1>
            <p class="page-subtitle">Review and approve requests from your department</p>
        </div>

        <?php if ($flash): ?>
            <div class="alert alert-info"><?= htmlspecialchars($flash) ?></div>
        <?php endif; ?>

        <!-- Filter Card -->
        <div class="filter-card">
            <form method="GET">
                <label class="filter-label">Search Records</label>
                <div class="search-row">
                    <input type="text" name="search" class="search-input" placeholder="Search by Request ID, Items, Requester Name, or Status..." value="<?= htmlspecialchars($search) ?>">
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

        <div class="section-card">
            <div class="section-header">
                <i class="bi bi-clock-history"></i>
                <h2>Pending Requests</h2>
            </div>
            <div class="table-responsive">
                <table class="table table-minimal">
                    <thead>
                        <tr>
                            <th>Request ID</th>
                            <th>Items</th>
                            <th>Requester</th>
                            <th>Status</th>
                            <th>Date Submitted</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($results)): ?>
                            <tr><td colspan="6">
                                <div class="empty-state">
                                    <i class="bi bi-inbox"></i>
                                    <p>No requests pending your review.</p>
                                </div>
                            </td></tr>
                        <?php else: foreach ($results as $r): ?>
                            <tr>
                                <td><span class="request-id"><?= htmlspecialchars($r['request_id'] ?: $r['id']) ?></span></td>
                                <td><?= htmlspecialchars($r['items'] ?? '—') ?></td>
                                <td><?= htmlspecialchars($r['first_name'] . ' ' . $r['last_name']) ?></td>
                                <td><span class="badge-minimal badge-pending"><?= htmlspecialchars(ucfirst(str_replace('_', ' ', $r['status']))) ?></span></td>
                                <td><?= htmlspecialchars(date('M d, Y g:i A', strtotime($r['created_at']))) ?></td>
                                <td>
                                    <a class="btn-minimal btn-action-view" href="view_request.php?id=<?= $r['id'] ?>">
                                        <i class="bi bi-eye"></i> View
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="section-card">
            <div class="section-header">
                <i class="bi bi-check-circle"></i>
                <h2>Approved & Ready for Release</h2>
            </div>
            <div class="table-responsive">
                <table class="table table-minimal">
                    <thead>
                        <tr>
                            <th>Request ID</th>
                            <th>Items</th>
                            <th>Requester</th>
                            <th>Date</th>
                            <th>Delivery Date</th>
                            <th>Receipt Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($approved)): ?>
                            <tr><td colspan="7">
                                <div class="empty-state">
                                    <i class="bi bi-inbox"></i>
                                    <p>No approved requests ready for release.</p>
                                </div>
                            </td></tr>
                        <?php else: foreach ($approved as $r): ?>
                            <tr>
                                <td><span class="request-id"><?= htmlspecialchars($r['request_id'] ?: $r['id']) ?></span></td>
                                <td><?= htmlspecialchars($r['items'] ?? '—') ?></td>
                                <td><?= htmlspecialchars($r['first_name'] . ' ' . $r['last_name']) ?></td>
                                <td><?= htmlspecialchars(date('M d, Y g:i A', strtotime($r['created_at']))) ?></td>
                                <td><?= $r['release_date'] ? htmlspecialchars(date('M d, Y g:i A', strtotime($r['release_date']))) : '<span class="badge-minimal badge-pending">No Schedule</span>' ?></td>
                                <td><?= $r['receipt_date'] ? htmlspecialchars(date('M d, Y g:i A', strtotime($r['receipt_date']))) : '<span class="badge-minimal badge-pending">Pending</span>' ?></td>
                                <td>
                                    <a class="btn-minimal btn-action-view" href="view_request.php?id=<?= $r['id'] ?>">
                                        <i class="bi bi-eye"></i> View
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
