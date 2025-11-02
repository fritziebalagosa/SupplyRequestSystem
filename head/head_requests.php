<?php
session_start();
include('../config/db.php');
include('../includes/csrf.php');
// include shared navbar for head
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

// fetch pending requests for this head, include item names instead of request title
$stmt = $conn->prepare("SELECT r.id, r.request_id, r.status, r.created_at, u.first_name, u.last_name,
                        GROUP_CONCAT(DISTINCT it.item_name SEPARATOR ', ') AS items
                        FROM requests r
                        JOIN users u ON r.requester_id = u.id
                        LEFT JOIN request_items ri ON ri.request_id = r.id
                        LEFT JOIN items it ON ri.item_id = it.id
                        WHERE r.college_office_id = ? AND r.status = 'pending_head' AND u.created_by = ?
                        GROUP BY r.id
                        ORDER BY r.created_at DESC");
$stmt->bind_param("ii", $college_office_id, $user_id);
$stmt->execute();
$results = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();
// Also fetch approved requests to notify head when scheduled for release
$stmt2 = $conn->prepare("SELECT r.id, r.request_id, r.status, r.created_at, u.first_name, u.last_name,
                        GROUP_CONCAT(DISTINCT it.item_name SEPARATOR ', ') AS items
                        FROM requests r
                        JOIN users u ON r.requester_id = u.id
                        LEFT JOIN request_items ri ON ri.request_id = r.id
                        LEFT JOIN items it ON ri.item_id = it.id
                        WHERE r.college_office_id = ? AND r.status = 'approved' AND u.created_by = ?
                        GROUP BY r.id
                        ORDER BY r.created_at DESC");
$stmt2->bind_param("ii", $college_office_id, $user_id);
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
</head>
<body class="container py-4">
    <h2>Requests Awaiting Your Review</h2>
    <?php if ($flash): ?>
        <div class="alert alert-info"><?= htmlspecialchars($flash) ?></div>
    <?php endif; ?>

    <table class="table table-striped">
        <thead>
            <tr>
                <th>Request ID</th>
                <th>Items</th>
                <th>Requester</th>
                <th>Status</th>
                <th>Date</th>
                <th>Action</th>
            </tr>
        </thead>
        <tbody>
        <?php if (empty($results)): ?>
            <tr><td colspan="6">No requests pending your review.</td></tr>
        <?php else: foreach ($results as $r): ?>
            <tr>
                <td><?= htmlspecialchars($r['request_id'] ?: $r['id']) ?></td>
                <td><?= htmlspecialchars($r['items'] ?? '—') ?></td>
                <td><?= htmlspecialchars($r['first_name'] . ' ' . $r['last_name']) ?></td>
                <td><?= htmlspecialchars(ucfirst($r['status'])) ?></td>
                <td><?= htmlspecialchars($r['created_at']) ?></td>
                <td>
                    <a class="btn btn-sm btn-primary" href="view_request.php?id=<?= $r['id'] ?>">View</a>
                </td>
            </tr>
        <?php endforeach; endif; ?>
        </tbody>
    </table>
    
    <h3 class="mt-4">Approved & Ready for Release</h3>
    <table class="table table-striped">
        <thead><tr><th>Request ID</th><th>Items</th><th>Requester</th><th>Date</th><th>Action</th></tr></thead>
        <tbody>
        <?php if (empty($approved)): ?>
            <tr><td colspan="5">No approved requests ready for release.</td></tr>
        <?php else: foreach ($approved as $r): ?>
            <tr>
                <td><?= htmlspecialchars($r['request_id'] ?: $r['id']) ?></td>
                <td><?= htmlspecialchars($r['items'] ?? '—') ?></td>
                <td><?= htmlspecialchars($r['first_name'] . ' ' . $r['last_name']) ?></td>
                <td><?= htmlspecialchars($r['created_at']) ?></td>
                <td><a class="btn btn-sm btn-primary" href="view_request.php?id=<?= $r['id'] ?>">View</a></td>
            </tr>
        <?php endforeach; endif; ?>
        </tbody>
    </table>
</body>
</html>
