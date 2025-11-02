<?php
session_start();
include('../config/db.php');
include('../includes/csrf.php');

// require dean/head role
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'] ?? '', ['dean','head'])) {
    header('Location: ../auth/log_in.php');
    exit;
}

$flash = $_SESSION['flash_message'] ?? '';
unset($_SESSION['flash_message']);

// Handle post actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'], $_POST['request_id'])) {
    $action = $_POST['action'];
    $request_id = $_POST['request_id'];
    $comment = $_POST['comment'] ?? '';
    $user_id = $_SESSION['user_id'];
    $college_office_id = $_SESSION['college_office_id'];
    $message = '';

    // verify CSRF token
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $_SESSION['flash_message'] = 'Invalid security token. Please try again.';
        header('Location: dean_requests.php');
        exit;
    }

    // verify request exists and belongs to this office
    $v = $conn->prepare("SELECT status FROM requests WHERE id = ? AND college_office_id = ?");
    $v->bind_param("ii", $request_id, $college_office_id);
    $v->execute();
    $result = $v->get_result();
    $request = $result->fetch_assoc();
    $request_db_id = $request_id;

    $may_act = false;
    if ($request && $request['status'] === 'pending_dean') {
        $may_act = true;
    }
    if (!$may_act) {
        $message = 'This request cannot be acted on in its current state.';
        $v->close();
        $_SESSION['flash_message'] = $message;
        header('Location: dean_requests.php');
        exit;
    }
    
    // determine update values
    if ($action === 'approve') {
        $new_status = 'pending_officer';
        $action_type = 'approved';
        if (isset($_SESSION['role']) && $_SESSION['role'] === 'dean') {
            $hstmt = $conn->prepare("SELECT id FROM users WHERE role='head' AND college_office_id = ? AND status='active' LIMIT 1");
            $hstmt->bind_param('i', $college_office_id);
            $hstmt->execute();
            $hres = $hstmt->get_result();
            $has_head = ($hres && $hres->num_rows > 0);
            $hstmt->close();
            if ($has_head) {
                $new_status = 'pending_head';
                $action_type = 'forwarded_to_head';
            }
        }
    } elseif ($action === 'reject') {
        $new_status = 'rejected';
        $action_type = 'rejected';
    } else {
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

        $message = 'Action performed successfully.';
    }
    $u->close();
    $v->close();
    
    $_SESSION['flash_message'] = $message;
    header('Location: dean_requests.php');
    exit;
}

$college_office_id = $_SESSION['college_office_id'];
$user_id = $_SESSION['user_id'];

// fetch pending requests for this dean/head
$stmt = $conn->prepare("SELECT r.id, r.request_id, r.status, r.created_at, u.first_name, u.last_name,
                        GROUP_CONCAT(DISTINCT it.item_name SEPARATOR ', ') AS items
                        FROM requests r
                        JOIN users u ON r.requester_id = u.id
                        LEFT JOIN request_items ri ON ri.request_id = r.id
                        LEFT JOIN items it ON ri.item_id = it.id
                        WHERE r.college_office_id = ? AND r.status = 'pending_dean'
                        GROUP BY r.id
                        ORDER BY r.created_at DESC");
if (!$stmt) {
    die('Failed to prepare statement: ' . $conn->error);
}
$stmt->bind_param("i", $college_office_id);
$stmt->execute();
$results = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// fetch approved requests
$stmt2 = $conn->prepare("SELECT r.id, r.request_id, r.status, r.created_at, u.first_name, u.last_name,
                        GROUP_CONCAT(DISTINCT it.item_name SEPARATOR ', ') AS items
                        FROM requests r
                        JOIN users u ON r.requester_id = u.id
                        LEFT JOIN request_items ri ON ri.request_id = r.id
                        LEFT JOIN items it ON ri.item_id = it.id
                        WHERE r.college_office_id = ? AND r.status = 'approved'
                        GROUP BY r.id
                        ORDER BY r.created_at DESC");
$stmt2->bind_param("i", $college_office_id);
$stmt2->execute();
$approved = $stmt2->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt2->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Requests - WMSU OSRS</title>
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
            max-width: 1400px;
            margin: 0 auto;
            padding: 2rem 1.5rem;
        }

        .page-title {
            font-size: 1.75rem;
            font-weight: 600;
            color: var(--gray-900);
            letter-spacing: -0.5px;
            margin-bottom: 2rem;
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

        .alert-info {
            background-color: #d1ecf1;
            color: #0c5460;
            border-color: #bee5eb;
        }

        .alert-minimal i {
            font-size: 1.25rem;
        }

        /* Section Cards */
        .section-card {
            background: white;
            border-radius: 12px;
            border: 1px solid var(--gray-200);
            overflow: hidden;
            margin-bottom: 2rem;
        }

        .section-header {
            padding: 1.25rem 1.5rem;
            border-bottom: 1px solid var(--gray-200);
            background: white;
        }

        .section-header h2 {
            font-size: 1.125rem;
            font-weight: 600;
            color: var(--gray-900);
            margin: 0;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .section-body {
            padding: 0;
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

        /* Request ID */
        .request-id {
            font-family: 'Courier New', monospace;
            font-weight: 600;
            color: var(--red-primary);
            font-size: 0.875rem;
        }

        /* Items list */
        .items-list {
            color: var(--gray-700);
            max-width: 300px;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
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

        /* Button */
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
        }

        .btn-action-view {
            background-color: #d1ecf1;
            color: #0c5460;
            border-color: #bee5eb;
        }

        .btn-action-view:hover {
            background-color: #bee5eb;
            border-color: #17a2b8;
            color: #0c5460;
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

        .empty-state p {
            margin: 0;
            font-size: 0.9375rem;
        }

        /* Responsive */
        @media (max-width: 768px) {
            .container-main {
                padding: 1.5rem 1rem;
            }

            .page-title {
                font-size: 1.5rem;
                margin-bottom: 1.5rem;
            }

            .table-minimal thead th,
            .table-minimal tbody td {
                padding: 0.875rem 0.75rem;
                font-size: 0.875rem;
            }

            .items-list {
                max-width: 150px;
            }

            .section-header {
                padding: 1rem 1.25rem;
            }

            .section-header h2 {
                font-size: 1rem;
            }
        }
    </style>
</head>
<body>
    <?php include('../includes/head_dean_navbar.php'); ?>

    <div class="container-main">
        <h1 class="page-title">Requests Awaiting Your Review</h1>

        <?php if ($flash): ?>
            <div class="alert-minimal alert-info">
                <i class="bi bi-info-circle-fill"></i>
                <span><?= htmlspecialchars($flash) ?></span>
            </div>
        <?php endif; ?>

        <!-- Pending Requests -->
        <div class="section-card">
            <div class="section-header">
                <h2><i class="bi bi-clock-history"></i> Pending Review</h2>
            </div>
            <div class="section-body">
                <?php if (empty($results)): ?>
                    <div class="empty-state">
                        <i class="bi bi-inbox"></i>
                        <p>No requests pending your review at the moment.</p>
                    </div>
                <?php else: ?>
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
                                <?php foreach ($results as $r): ?>
                                    <tr>
                                        <td><span class="request-id">#<?= htmlspecialchars($r['request_id'] ?: $r['id']) ?></span></td>
                                        <td>
                                            <span class="items-list" title="<?= htmlspecialchars($r['items'] ?? '—') ?>">
                                                <?= htmlspecialchars($r['items'] ?? '—') ?>
                                            </span>
                                        </td>
                                        <td><?= htmlspecialchars($r['first_name'] . ' ' . $r['last_name']) ?></td>
                                        <td>
                                            <span class="badge-minimal badge-pending">
                                                <i class="bi bi-clock-history"></i>
                                                <?= htmlspecialchars(ucwords(str_replace('_', ' ', $r['status']))) ?>
                                            </span>
                                        </td>
                                        <td><?= htmlspecialchars(date('M d, Y g:i A', strtotime($r['created_at']))) ?></td>
                                        <td>
                                            <a class="btn-minimal btn-action-view" href="view_requests.php?id=<?= $r['id'] ?>">
                                                <i class="bi bi-eye"></i> View Details
                                            </a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Approved Requests -->
        <div class="section-card">
            <div class="section-header">
                <h2><i class="bi bi-check-circle"></i> Approved & Ready for Release</h2>
            </div>
            <div class="section-body">
                <?php if (empty($approved)): ?>
                    <div class="empty-state">
                        <i class="bi bi-inbox"></i>
                        <p>No approved requests ready for release.</p>
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-minimal">
                            <thead>
                                <tr>
                                    <th>Request ID</th>
                                    <th>Items</th>
                                    <th>Requester</th>
                                    <th>Date Approved</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($approved as $r): ?>
                                    <tr>
                                        <td><span class="request-id">#<?= htmlspecialchars($r['request_id'] ?: $r['id']) ?></span></td>
                                        <td>
                                            <span class="items-list" title="<?= htmlspecialchars($r['items'] ?? '—') ?>">
                                                <?= htmlspecialchars($r['items'] ?? '—') ?>
                                            </span>
                                        </td>
                                        <td><?= htmlspecialchars($r['first_name'] . ' ' . $r['last_name']) ?></td>
                                        <td><?= htmlspecialchars(date('M d, Y g:i A', strtotime($r['created_at']))) ?></td>
                                        <td>
                                            <a class="btn-minimal btn-action-view" href="view_requests.php?id=<?= $r['id'] ?>">
                                                <i class="bi bi-eye"></i> View Details
                                            </a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>