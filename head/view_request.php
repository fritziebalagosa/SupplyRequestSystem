<?php
session_start();
include('../config/db.php');
include('../includes/csrf.php');
// include shared navbar for head
include_once('../includes/head_dean_navbar.php');

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'head') {
    header('Location: ../auth/log_in.php');
    exit;
}

$user_id = $_SESSION['user_id'];

// get college_office_id
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

$id = intval($_GET['id'] ?? 0);
if (!$id) {
    die('Invalid request id');
}

$stmt = $conn->prepare("SELECT r.*, u.first_name, u.last_name FROM requests r JOIN users u ON r.requester_id = u.id WHERE r.id = ? AND r.college_office_id = ? AND u.created_by = ? LIMIT 1");
$stmt->bind_param("iii", $id, $college_office_id, $user_id);
$stmt->execute();
$request = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$request) {
    die('Request not found or you do not have permission to view it.');
}

// fetch items with approved quantities if they exist
$items_stmt = $conn->prepare("
    SELECT ri.*, it.item_name, it.unit, COALESCE(ri.priority, 'Normal') as priority 
    FROM request_items ri 
    JOIN items it ON ri.item_id = it.id 
    WHERE ri.request_id = ?");
$items_stmt->bind_param("i", $id);
$items_stmt->execute();
$items = $items_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$items_stmt->close();

// fetch action history
$hist_stmt = $conn->prepare("SELECT ra.*, u.first_name, u.last_name FROM request_actions ra LEFT JOIN users u ON ra.action_by = u.id WHERE ra.request_id = ? ORDER BY ra.created_at DESC");
$hist_stmt->bind_param("i", $id);
$hist_stmt->execute();
$history = $hist_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$hist_stmt->close();

$csrf_token = generate_csrf_token();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>View Request</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>.small-label{font-size:0.9rem;color:#666}</style>
</head>
<body class="container py-4">
    <a href="head_requests.php" class="btn btn-sm btn-secondary mb-3">← Back to list</a>
    <h3>Request <?= htmlspecialchars($request['request_id'] ?: $request['id']) ?></h3>

    <div class="card mb-3 p-3">
        <!-- Title removed: items are shown below -->
        <p><strong>Requester:</strong> <?= htmlspecialchars($request['first_name'] . ' ' . $request['last_name']) ?></p>
        <p><strong>Description:</strong><br><?= nl2br(htmlspecialchars($request['description'] ?? '')) ?></p>
        <?php if (!empty($request['attachment'])): ?>
            <p><strong>Attachment:</strong> <a href="<?= htmlspecialchars($request['attachment']) ?>" target="_blank">Download</a></p>
        <?php endif; ?>
        <p class="small-label">Status: <?= htmlspecialchars($request['status']) ?> • Created at: <?= htmlspecialchars($request['created_at']) ?></p>
    </div>

    <div class="card mb-3 p-3">
        <h5>Items</h5>
        <?php if (empty($items)): ?>
            <p>No items attached.</p>
        <?php else: ?>
            <?php
            // Check for quantity adjustments in history
            $hasAdjustments = false;
            $adjustmentNote = '';
            foreach ($history as $h) {
                if (strpos(($h['comment'] ?? ''), 'Adjustments:') !== false) {
                    $hasAdjustments = true;
                    $adjustmentNote = $h['comment'];
                    break;
                }
            }
            if ($hasAdjustments): ?>
            <div class="alert alert-warning">
                <i class="bi bi-info-circle"></i> 
                The Supply Officer has adjusted some quantities for this request.
            </div>
            <?php endif; ?>
            <table class="table table-sm">
                <thead>
                    <tr>
                        <th>Item</th>
                        <th>Requested Qty</th>
                        <th>Approved Qty</th>
                        <th>Unit</th>
                        <th>Priority</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($items as $it): ?>
                    <tr>
                        <td><?= htmlspecialchars($it['item_name']) ?></td>
                        <td><?= (int)$it['quantity'] ?></td>
                        <td>
                            <?php 
                            $approved = isset($it['approved_quantity']) ? (int)$it['approved_quantity'] : (int)$it['quantity'];
                            echo $approved;
                            if (isset($it['approved_quantity']) && $it['approved_quantity'] != $it['quantity']): ?>
                                <span class="badge bg-warning text-dark">
                                    <i class="bi bi-pencil-square"></i> Adjusted
                                </span>
                            <?php endif; ?>
                        </td>
                        <td><?= htmlspecialchars($it['unit']) ?></td>
                        <td><?= htmlspecialchars($it['priority']) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php if ($hasAdjustments && $adjustmentNote): ?>
            <div class="mt-3 p-3 bg-light rounded">
                <strong>Adjustment Details:</strong><br>
                <?= nl2br(htmlspecialchars($adjustmentNote)) ?>
            </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>

    <?php
    // Only show action form to head when request is pending_head
    $can_act = false;
    $role = $_SESSION['role'] ?? '';
    if ($role === 'head' && $request['status'] === 'pending_head') {
        $can_act = true;
    }
    ?>
    <?php if ($can_act): ?>
    <div class="card mb-3 p-3">
        <h5>Take Action</h5>
        <form method="POST" action="head_requests.php">
            <input type="hidden" name="request_db_id" value="<?= $request['id'] ?>">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
            <div class="mb-2">
                <label class="form-label">Comment (optional, required when returning)</label>
                <textarea name="comment" class="form-control" rows="3"></textarea>
            </div>
            <div class="d-flex gap-2">
                <button type="submit" name="action" value="approve" class="btn btn-success">Approve & Forward to Supply Officer</button>
                <button type="submit" name="action" value="return" class="btn btn-warning" id="btnReturn">Return with Comment</button>
                <button type="submit" name="action" value="reject" class="btn btn-danger">Reject</button>
            </div>
        </form>
    </div>
    <?php else: ?>
    <div class="alert alert-secondary">No actions available for this request (current status: <?= htmlspecialchars($request['status']) ?>).</div>
    <?php endif; ?>

    <script>
        // Client-side: require comment when clicking Return
        document.getElementById('btnReturn').addEventListener('click', function(e){
            var comment = document.querySelector('textarea[name="comment"]').value.trim();
            if (!comment) {
                e.preventDefault();
                alert('Please provide a comment when returning a request.');
            }
        });
    </script>

    <div class="card p-3">
        <h5>Action History</h5>
        <?php if (empty($history)): ?>
            <p>No actions recorded yet.</p>
        <?php else: ?>
            <ul class="list-group">
                <?php foreach ($history as $h): ?>
                    <li class="list-group-item">
                        <strong><?= htmlspecialchars($h['action_type']) ?></strong> by <?= htmlspecialchars($h['first_name'] . ' ' . $h['last_name']) ?> (<?= htmlspecialchars($h['role']) ?>)
                        <div class="small-label"><?= htmlspecialchars($h['created_at']) ?></div>
                        <?php if (!empty($h['comment'])): ?>
                            <div class="mt-2"><?= nl2br(htmlspecialchars($h['comment'])) ?></div>
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </div>
</body>
</html>
