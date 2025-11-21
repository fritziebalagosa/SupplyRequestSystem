<?php
session_start();
include('../config/db.php');
include('../includes/csrf.php');

if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'] ?? '', ['dean','head'])) {
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

// fetch items
$items_stmt = $conn->prepare("SELECT ri.*, it.item_name FROM request_items ri JOIN items it ON ri.item_id = it.id WHERE ri.request_id = ?");
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
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>View Request - WMSU OSRS</title>
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
            max-width: 1100px;
            margin: 0 auto;
            padding: 2rem 1.5rem;
        }

        .page-title {
            font-size: 1.75rem;
            font-weight: 600;
            color: var(--gray-900);
            letter-spacing: -0.5px;
            margin-bottom: 0.25rem;
        }

        .page-subtitle {
            color: var(--gray-700);
            font-size: 0.9375rem;
            margin-bottom: 1.5rem;
        }

        .section-card {
            background: white;
            border-radius: 12px;
            border: 1px solid var(--gray-200);
            overflow: hidden;
            margin-bottom: 1.5rem;
        }

        .section-header {
            padding: 1.1rem 1.4rem;
            border-bottom: 1px solid var(--gray-200);
            background: white;
        }

        .section-header h2,
        .section-header h5 {
            font-size: 1.1rem;
            font-weight: 600;
            margin: 0;
        }

        .section-body {
            padding: 1.2rem 1.4rem;
        }
    </style>
</head>
<body>
    <?php include('../includes/head_dean_navbar.php'); ?>

    <div class="container-main">
        <a href="dean_requests.php" class="btn btn-sm btn-secondary mb-3">
            ← Back to list
        </a>

        <div class="section-card">
            <div class="section-header">
                <h2>Request Details</h2>
            </div>
            <div class="section-body">
                <h3 class="h5 mb-2">Request <?= htmlspecialchars($request['request_id'] ?: $request['id']) ?></h3>
                <p><strong>Requester:</strong> <?= htmlspecialchars($request['first_name'] . ' ' . $request['last_name']) ?></p>
                <p><strong>Description:</strong><br><?= nl2br(htmlspecialchars($request['description'] ?? '')) ?></p>
                <?php if (!empty($request['attachment'])): ?>
                    <p><strong>Attachment:</strong> <a href="<?= htmlspecialchars($request['attachment']) ?>" target="_blank">Download</a></p>
                <?php endif; ?>
                <p class="mb-0"><small class="text-muted">Status: <?= htmlspecialchars($request['status']) ?> • Created at: <?= htmlspecialchars($request['created_at']) ?></small></p>
                
                <?php
                // Check if receipt exists for this request
                $receipt_stmt = $conn->prepare("SELECT * FROM release_proofs WHERE request_id = ? ORDER BY created_at DESC LIMIT 1");
                $receipt_stmt->bind_param("i", $id);
                $receipt_stmt->execute();
                $receipt = $receipt_stmt->get_result()->fetch_assoc();
                $receipt_stmt->close();
                
                if ($receipt): ?>
                    <div class="mt-3 p-2 bg-light rounded">
                        <p class="mb-1"><strong>Receipt Status:</strong> <span class="text-success">Received</span></p>
                        <p class="mb-1"><small class="text-muted">Received at: <?= date('M j, Y h:i A', strtotime($receipt['created_at'])) ?></small></p>
                        <?php if (!empty($receipt['image_path'])): ?>
                            <a href="<?= htmlspecialchars($receipt['image_path']) ?>" target="_blank" class="btn btn-sm btn-outline-primary mt-1">
                                <i class="bi bi-image"></i> View Receipt
                            </a>
                        <?php endif; ?>
                        <?php if (!empty($receipt['notes'])): ?>
                            <div class="mt-2 p-2 bg-white rounded">
                                <p class="mb-0 small"><?= nl2br(htmlspecialchars($receipt['notes'])) ?></p>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <div class="section-card">
            <div class="section-header"><h2>Items</h2></div>
            <div class="section-body">
                <?php if (empty($items)): ?>
                    <p>No items attached.</p>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-striped">
                            <thead><tr><th>Item</th><th>Qty</th><th>Unit</th><th>Priority</th></tr></thead>
                            <tbody>
                                <?php foreach ($items as $it):
                                    $reqQty = (int)$it['quantity'];
                                    $approved = isset($it['approved_quantity']) && $it['approved_quantity'] !== null ? (int)$it['approved_quantity'] : null;
                                    $effective = $approved !== null ? $approved : $reqQty;
                                ?>
                                    <tr>
                                        <td><?= htmlspecialchars($it['item_name']) ?></td>
                                        <td><?= $effective ?><?php if ($approved !== null && $approved !== $reqQty): ?> <div class="small text-muted">Requested: <?= $reqQty ?> — Adjusted: <?= $approved ?></div><?php endif; ?></td>
                                        <td><?= htmlspecialchars($it['unit']) ?></td>
                                        <td><?= htmlspecialchars($it['priority']) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <?php
        $can_act = false;
        $role = $_SESSION['role'] ?? '';
        if ($role === 'dean' && $request['status'] === 'pending_dean') $can_act = true;
        if ($role === 'head' && $request['status'] === 'pending_head') $can_act = true;
        ?>
        <?php if ($can_act): ?>
            <div class="section-card">
                <div class="section-header"><h2>Take Action</h2></div>
                <div class="section-body">
                    <form method="POST" action="dean_requests.php" id="actionForm">
                        <input type="hidden" name="request_id" value="<?= $request['id'] ?>">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                        <div class="mb-3">
                            <label class="form-label">Comment (optional, required when returning)</label>
                            <textarea name="comment" class="form-control" rows="3"></textarea>
                        </div>
                        <div class="d-flex flex-wrap gap-2">
                            <button type="submit" name="action" value="approve" class="btn btn-success">Approve & Forward to Supply Officer</button>
                            <button type="submit" name="action" value="return" class="btn btn-warning" id="btnReturn">Return with Comment</button>
                            <button type="submit" name="action" value="reject" class="btn btn-danger">Reject</button>
                        </div>
                    </form>
                </div>
            </div>
        <?php else: ?>
            <div class="section-card">
                <div class="section-body">
                    <div class="alert alert-secondary mb-0">No actions available for this request (current status: <?= htmlspecialchars($request['status']) ?>).</div>
                </div>
            </div>
        <?php endif; ?>

        <div class="section-card">
            <div class="section-header"><h2>Action History</h2></div>
            <div class="section-body">
                <?php if (empty($history)): ?>
                    <p>No actions recorded yet.</p>
                <?php else: ?>
                    <ul class="list-group">
                        <?php foreach ($history as $h): ?>
                            <li class="list-group-item">
                                <strong><?= htmlspecialchars($h['action_type']) ?></strong> by <?= htmlspecialchars($h['first_name'] . ' ' . $h['last_name']) ?> (<?= htmlspecialchars($h['role']) ?>)
                                <div class="text-muted small"><?= htmlspecialchars($h['created_at']) ?></div>
                                <?php if (!empty($h['comment'])): ?><div class="mt-2"><?= nl2br(htmlspecialchars($h['comment'])) ?></div><?php endif; ?>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function(){
            var btn = document.getElementById('btnReturn');
            if (btn) btn.addEventListener('click', function(e){
                var comment = document.querySelector('textarea[name="comment"]').value.trim();
                if (!comment) { e.preventDefault(); alert('Please provide a comment when returning a request.'); }
            });
        });
    </script>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
