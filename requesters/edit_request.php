<?php
session_start();
include('../config/db.php');
include('../includes/csrf.php');

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'requester') {
    header('Location: ../auth/log_in.php');
    exit;
}

$user_id = $_SESSION['user_id'];
$id = intval($_GET['id'] ?? $_POST['request_id'] ?? 0);
if (!$id) die('Invalid request id');

// fetch request and ensure it's owned by this user and is returned
$stmt = $conn->prepare("SELECT * FROM requests WHERE id = ? AND requester_id = ? LIMIT 1");
$stmt->bind_param('ii', $id, $user_id);
$stmt->execute();
$request = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$request) die('Request not found or access denied');
if (strpos($request['status'], 'returned') === false) die('Request is not in returned state.');

// fetch request_items with their row id
$items_stmt = $conn->prepare("SELECT ri.id as ri_id, ri.quantity, ri.unit, ri.priority, it.item_name FROM request_items ri JOIN items it ON ri.item_id = it.id WHERE ri.request_id = ?");
$items_stmt->bind_param('i', $id);
$items_stmt->execute();
$items = $items_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$items_stmt->close();

$message = '';
$message_type = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf = $_POST['csrf_token'] ?? '';
    if (!verify_csrf_token($csrf)) {
        $message = 'Invalid request (CSRF).';
        $message_type = 'danger';
    } else {
        $quantities = $_POST['quantity'] ?? [];
        $ri_ids = $_POST['ri_id'] ?? [];
        $comment = trim($_POST['comment'] ?? '');

        // Basic validation
        $valid = true;
        for ($i = 0; $i < count($ri_ids); $i++) {
            $q = intval($quantities[$i] ?? 0);
            if ($q < 1) { $valid = false; break; }
        }
        if (!$valid) {
            $message = 'Please ensure all quantities are at least 1.';
            $message_type = 'danger';
        } else {
            // update each request_items row
            $u = $conn->prepare("UPDATE request_items SET quantity = ? WHERE id = ?");
            for ($i = 0; $i < count($ri_ids); $i++) {
                $q = intval($quantities[$i]);
                $rid = intval($ri_ids[$i]);
                $u->bind_param('ii', $q, $rid);
                $u->execute();
            }
            $u->close();

            // set status back to pending_head so head/dean can review again
            $s = $conn->prepare("UPDATE requests SET status = 'pending_head' WHERE id = ?");
            $s->bind_param('i', $id);
            $s->execute();
            $s->close();

            // insert action
            $ia = $conn->prepare("INSERT INTO request_actions (request_id, action_by, role, action_type, comment, created_at) VALUES (?, ?, ?, 'resubmitted', ?, NOW())");
            $role = 'requester';
            $ia->bind_param('iiss', $id, $user_id, $role, $comment);
            $ia->execute();
            $ia->close();

            $_SESSION['flash_message'] = 'Request updated and resubmitted successfully.';
            header('Location: view_request.php?id=' . $id);
            exit;
        }
    }
}

$csrf_token = generate_csrf_token();
?>
<!doctype html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Edit Returned Request</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css" rel="stylesheet">
</head>
<body class="container py-4">
    <?php include('../includes/requester_navbar.php'); ?>
    <a href="view_request.php?id=<?= $id ?>" class="btn btn-sm btn-secondary mb-3">← Back to request</a>
    <h3>Edit Returned Request <?= htmlspecialchars($request['request_id'] ?: $request['id']) ?></h3>
    <?php if ($message): ?>
        <div class="alert alert-<?= $message_type ?>"><?= htmlspecialchars($message) ?></div>
    <?php endif; ?>

    <form method="POST">
        <input type="hidden" name="request_id" value="<?= $id ?>">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
        <div class="card mb-3">
            <div class="card-body">
                <h5>Items</h5>
                <div class="table-responsive">
                    <table class="table">
                        <thead><tr><th>Item</th><th>Quantity</th></tr></thead>
                        <tbody>
                        <?php foreach ($items as $idx => $it): ?>
                            <tr>
                                <td><?= htmlspecialchars($it['item_name']) ?></td>
                                <td style="max-width:160px;">
                                    <input type="hidden" name="ri_id[]" value="<?= (int)$it['ri_id'] ?>">
                                    <input type="number" name="quantity[]" class="form-control" value="<?= (int)$it['quantity'] ?>" min="1" required>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="mb-3">
            <label class="form-label">Comment (optional)</label>
            <textarea name="comment" class="form-control" rows="3"></textarea>
        </div>
        <div class="d-flex gap-2">
            <button type="submit" class="btn btn-primary">Save & Resubmit</button>
            <a href="view_request.php?id=<?= $id ?>" class="btn btn-secondary">Cancel</a>
        </div>
    </form>

</body>
</html>
