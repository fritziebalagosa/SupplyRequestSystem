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
        // close and skip update
        $v->close();
        $_SESSION['flash_message'] = $message;
        header('Location: dean_requests.php');
        exit;
    }
    // determine update values
    if ($action === 'approve') {
            // If current user is a dean and there is a head for this office, forward to head first
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
    
    $v->close();
    
    // redirect back to avoid resubmission
    $_SESSION['flash_message'] = $message;
    header('Location: dean_requests.php');
    exit;
}

$flash = $_SESSION['flash_message'] ?? '';
unset($_SESSION['flash_message']);

$college_office_id = $_SESSION['college_office_id'];
$user_id = $_SESSION['user_id'];

// fetch pending requests for this dean/head, include item names instead of request title
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
// Also fetch approved requests to notify dean/head when scheduled for release
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
    <title>Dean - Requests</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="container py-4">
    <?php include('../includes/head_dean_navbar.php'); ?>

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
            <tr><td colspan="6" class="text-center">No requests pending your review.</td></tr>
        <?php else: foreach ($results as $r): ?>
            <tr>
                <td><?= htmlspecialchars($r['request_id'] ?: $r['id']) ?></td>
                <td><?= htmlspecialchars($r['items'] ?? '—') ?></td>
                <td><?= htmlspecialchars($r['first_name'] . ' ' . $r['last_name']) ?></td>
                <td><?= htmlspecialchars(ucfirst($r['status'])) ?></td>
                <td><?= htmlspecialchars($r['created_at']) ?></td>
                <td>
                    <a class="btn btn-sm btn-primary" href="view_requests.php?id=<?= $r['id'] ?>">View</a>
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
            <tr><td colspan="5" class="text-center">No approved requests ready for release.</td></tr>
        <?php else: foreach ($approved as $r): ?>
            <tr>
                <td><?= htmlspecialchars($r['request_id'] ?: $r['id']) ?></td>
                <td><?= htmlspecialchars($r['items'] ?? '—') ?></td>
                <td><?= htmlspecialchars($r['first_name'] . ' ' . $r['last_name']) ?></td>
                <td><?= htmlspecialchars($r['created_at']) ?></td>
                <td><a class="btn btn-sm btn-primary" href="view_requests.php?id=<?= $r['id'] ?>">View</a></td>
            </tr>
        <?php endforeach; endif; ?>
        </tbody>
    </table>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>