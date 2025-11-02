<?php
session_start();
include('../config/db.php');
include('../includes/csrf.php');
// shared navbar (role-aware)
include_once('../includes/head_dean_navbar.php');

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

    // verify request belongs to this college_office_id
    $v = $conn->prepare("SELECT id FROM requests WHERE id = ? AND college_office_id = ? LIMIT 1");
    $v->bind_param("ii", $request_db_id, $college_office_id);
    $v->execute();
    $vr = $v->get_result();
    if ($vr->num_rows === 0) {
        $message = "Request not found or you don't have permission.";
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

            $message = 'Action performed successfully.';
        }
        $u->close();
    }
    $v->close();

    // redirect back to avoid resubmission
    $_SESSION['flash_message'] = $message;
    header('Location: head_requests.php');
    exit;
}

$flash = $_SESSION['flash_message'] ?? '';
unset($_SESSION['flash_message']);

// fetch pending requests for this head
$stmt = $conn->prepare("SELECT r.id, r.request_id, r.status, r.created_at, u.first_name, u.last_name,
                        GROUP_CONCAT(DISTINCT it.item_name SEPARATOR ', ') AS items
                        FROM requests r
                        JOIN users u ON r.requester_id = u.id
                        LEFT JOIN request_items ri ON ri.request_id = r.id
                        LEFT JOIN items it ON ri.item_id = it.id
                        WHERE r.college_office_id = ? AND r.status = 'pending_head'
                        GROUP BY r.id
                        ORDER BY r.created_at DESC");
$stmt->bind_param("i", $college_office_id);
$stmt->execute();
$results = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();
// fetch approved requests for this head (ready for release)
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
    <title>Head - Requests</title>
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
            --gray-700: #616161;
            --gray-900: #212121;
        }
        * { margin:0; padding:0; box-sizing:border-box; }
        body { font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,'Inter',sans-serif; background-color:var(--gray-50); color:var(--gray-900); line-height:1.6; }
        .container-main { max-width: 1400px; margin: 0 auto; padding: 2rem 1.5rem; }
        .page-title { font-size:1.75rem; font-weight:600; color:var(--gray-900); letter-spacing:-0.5px; margin-bottom:2rem; }
        .alert-minimal { border-radius:8px; padding:1rem 1.25rem; margin-bottom:1.5rem; border:1px solid; display:flex; align-items:center; gap:.75rem; }
        .alert-info { background:#d1ecf1; color:#0c5460; border-color:#bee5eb; }
        .section-card { background:#fff; border-radius:12px; border:1px solid var(--gray-200); overflow:hidden; margin-bottom:2rem; }
        .section-header { padding:1.25rem 1.5rem; border-bottom:1px solid var(--gray-200); background:#fff; }
        .section-header h2 { font-size:1.125rem; font-weight:600; color:var(--gray-900); margin:0; display:flex; align-items:center; gap:.5rem; }
        .section-body { padding:0; }
        .table-minimal { margin:0; width:100%; }
        .table-minimal thead th { background:var(--gray-50); color:var(--gray-700); font-weight:600; font-size:.75rem; text-transform:uppercase; letter-spacing:.5px; padding:1rem 1.5rem; border:none; border-bottom:1px solid var(--gray-200); text-align:left; }
        .table-minimal tbody td { padding:1rem 1.5rem; color:var(--gray-900); font-size:.9375rem; border:none; border-bottom:1px solid var(--gray-100); vertical-align:middle; }
        .table-minimal tbody tr:last-child td { border-bottom:none; }
        .table-minimal tbody tr:hover { background:var(--gray-50); }
        .request-id { font-family:'Courier New', monospace; font-weight:600; color:var(--red-primary); font-size:.875rem; }
        .items-list { color:var(--gray-700); max-width:300px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
        .badge-minimal { display:inline-flex; align-items:center; padding:.35rem .75rem; border-radius:6px; font-size:.8125rem; font-weight:500; border:1px solid; }
        .badge-pending { background:#fff3cd; color:#856404; border-color:#ffeaa7; }
        .btn-minimal { padding:.4rem .875rem; border-radius:6px; font-weight:500; font-size:.875rem; border:1px solid; transition:.2s; text-decoration:none; display:inline-flex; align-items:center; gap:.375rem; }
        .btn-action-view { background:#d1ecf1; color:#0c5460; border-color:#bee5eb; }
        .btn-action-view:hover { background:#bee5eb; border-color:#17a2b8; color:#0c5460; }
        .empty-state { text-align:center; padding:3rem 1.5rem; color:var(--gray-700); }
        .empty-state i { font-size:3rem; color:#e0e0e0; margin-bottom:1rem; }
        @media (max-width: 768px) { .container-main{padding:1.5rem 1rem;} .table-minimal thead th, .table-minimal tbody td { padding:.875rem .75rem; font-size:.875rem; } .items-list{max-width:150px;} }
    </style>
</head>
<body>
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
                                            <a class="btn-minimal btn-action-view" href="view_request.php?id=<?= $r['id'] ?>">
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
                                            <a class="btn-minimal btn-action-view" href="view_request.php?id=<?= $r['id'] ?>">
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
