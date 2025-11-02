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

// Ensure receipts table exists and handle receipt upload
$conn->query("CREATE TABLE IF NOT EXISTS request_receipts (id INT AUTO_INCREMENT PRIMARY KEY, request_id INT NOT NULL, receiver_id INT NOT NULL, photo_path VARCHAR(255) NOT NULL, status VARCHAR(20) DEFAULT 'submitted', created_at DATETIME DEFAULT CURRENT_TIMESTAMP, confirmed_at DATETIME NULL, confirmed_by INT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_receipt'])) {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) { die('Security check failed'); }
    $rid = intval($_POST['request_id'] ?? 0);
    if ($rid !== intval($_GET['id'] ?? 0)) { die('Mismatched request id'); }
    if (!isset($_FILES['receipt_photo']) || $_FILES['receipt_photo']['error'] !== UPLOAD_ERR_OK) {
        $_SESSION['flash_error'] = 'Please attach a receipt photo.';
        header('Location: view_request.php?id=' . $rid);
        exit;
    }
    $f = $_FILES['receipt_photo'];
    $ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
    $allowed = ['jpg','jpeg','png','gif','webp'];
    if (!in_array($ext, $allowed)) {
        $_SESSION['flash_error'] = 'Invalid file type. Allowed: jpg, jpeg, png, gif, webp';
        header('Location: view_request.php?id=' . $rid);
        exit;
    }
    $destDir = realpath(__DIR__ . '/../') . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'receipts';
    if (!is_dir($destDir)) { @mkdir($destDir, 0777, true); }
    $fname = 'receipt_' . $rid . '_' . time() . '.' . $ext;
    $dest = $destDir . DIRECTORY_SEPARATOR . $fname;
    if (!move_uploaded_file($f['tmp_name'], $dest)) {
        $_SESSION['flash_error'] = 'Failed to save uploaded file.';
        header('Location: view_request.php?id=' . $rid);
        exit;
    }
    $relPath = '../uploads/receipts/' . $fname;
    $ins = $conn->prepare("INSERT INTO request_receipts (request_id, receiver_id, photo_path, status) VALUES (?, ?, ?, 'submitted')");
    $ins->bind_param('iis', $rid, $user_id, $relPath);
    $ins->execute();
    $ins->close();
    $role = $_SESSION['role'];
    $ia = $conn->prepare("INSERT INTO request_actions (request_id, action_by, role, action_type, comment, created_at) VALUES (?, ?, ?, 'receipt_submitted', 'Receipt photo uploaded', NOW())");
    $ia->bind_param('iis', $rid, $user_id, $role);
    $ia->execute();
    $ia->close();
    $_SESSION['flash_success'] = 'Receipt submitted to admin for confirmation.';
    header('Location: view_request.php?id=' . $rid);
    exit;
}

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

// Release schedule
$conn->query("CREATE TABLE IF NOT EXISTS release_schedule (request_id INT PRIMARY KEY, release_date DATE NOT NULL, created_at DATETIME DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
$rel_date = null;
$rs = $conn->prepare("SELECT release_date FROM release_schedule WHERE request_id=? LIMIT 1");
$rs->bind_param('i', $id);
$rs->execute();
$rres = $rs->get_result()->fetch_assoc();
if ($rres) { $rel_date = $rres['release_date']; }
$rs->close();

// Fallback: parse latest approved action comment
if (!$rel_date) {
    $as = $conn->prepare("SELECT comment FROM request_actions WHERE request_id=? AND action_type='approved' ORDER BY created_at DESC LIMIT 1");
    $as->bind_param('i', $id);
    $as->execute();
    $ar = $as->get_result()->fetch_assoc();
    $as->close();
    if ($ar && !empty($ar['comment'])) {
        if (preg_match('/Release date:\s*(\\d{4}-\\d{2}-\\d{2})/i', $ar['comment'], $m)) {
            $rel_date = $m[1];
        }
    }
}

// Latest receipt
$rec_stmt = $conn->prepare("SELECT * FROM request_receipts WHERE request_id=? ORDER BY created_at DESC LIMIT 1");
$rec_stmt->bind_param('i', $id);
$rec_stmt->execute();
$latest_receipt = $rec_stmt->get_result()->fetch_assoc();
$rec_stmt->close();
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
        body{background:#fafafa}
        .container-main{max-width:1100px;margin:0 auto;padding:24px}
        .page-title{font-weight:600;margin-bottom:12px}
        .request-id{color:#dc3545;font-family:Courier New,monospace;font-weight:600}
        .card-min{background:#fff;border:1px solid #eee;border-radius:12px}
        .card-min .card-header{background:#f8f9fa;font-weight:600;border-bottom:1px solid #eee}
        .items-table th{background:#f6f6f6;text-transform:uppercase;font-size:.8rem}
        .back-btn{display:inline-flex;align-items:center;gap:.5rem}
        .badge-status{background:#fff3cd;color:#856404;border:1px solid #ffeaa7}
    </style>
    </head>
<body>
    <div class="container-main">
        <a href="head_requests.php" class="btn btn-light border mb-3 back-btn"><i class="bi bi-arrow-left"></i> Back to Requests</a>
        <?php $status_text = ucwords(str_replace('_',' ',$request['status'])); ?>
        <h3 class="page-title">Request <span class="request-id">#<?= htmlspecialchars($request['request_id'] ?: $request['id']) ?></span>
            <span class="badge badge-status ms-2 px-2 py-1"><?= htmlspecialchars($status_text) ?></span>
        </h3>

        <div class="card-min mb-3">
            <div class="card-header">Request Details</div>
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-md-6"><div class="text-muted">Requester</div><div><?= htmlspecialchars($request['first_name'].' '.$request['last_name']) ?></div></div>
                    <div class="col-md-6"><div class="text-muted">Date Submitted</div><div><?= htmlspecialchars(date('M d, Y g:i A', strtotime($request['created_at']))) ?></div></div>
                    <div class="col-12"><div class="text-muted">Description</div><div><?= nl2br(htmlspecialchars($request['description'] ?? '')) ?></div></div>
                    <div class="col-md-6"><div class="text-muted">Scheduled Release</div><div><?= $rel_date ? htmlspecialchars(date('M d, Y', strtotime($rel_date))) : '—' ?></div></div>
                    <?php if (!empty($request['attachment'])): ?>
                        <div class="col-12"><div class="text-muted">Attachment</div><a class="link-danger" href="<?= htmlspecialchars($request['attachment']) ?>" target="_blank"><i class="bi bi-paperclip"></i> View Attached File</a></div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="card-min mb-3">
            <div class="card-header">Requested Items</div>
            <div class="card-body">
                <?php if (empty($items)): ?>
                    <div class="text-muted">No items attached.</div>
                <?php else: ?>
                <div class="table-responsive">
                    <table class="table items-table">
                        <thead><tr><th>Item Name</th><th class="text-center">Quantity</th><th class="text-center">Unit</th><th class="text-center">Priority</th></tr></thead>
                        <tbody>
                        <?php foreach ($items as $it): ?>
                            <tr>
                                <td><?= htmlspecialchars($it['item_name']) ?></td>
                                <td class="text-center"><strong><?= (int)$it['quantity'] ?></strong></td>
                                <td class="text-center"><?= htmlspecialchars($it['unit']) ?></td>
                                <td class="text-center"><?= htmlspecialchars(ucfirst($it['priority'])) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>
            </div>
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
    <div class="card-min mb-3">
        <div class="card-header">Take Action</div>
        <div class="card-body">
        <form method="POST" action="head_requests.php" class="row g-3">
            <input type="hidden" name="request_db_id" value="<?= $request['id'] ?>">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
            <div class="col-12">
                <label class="form-label">Comment (optional, required when returning)</label>
                <textarea name="comment" class="form-control" rows="3"></textarea>
            </div>
            <div class="col-12 d-flex gap-2">
                <button type="submit" name="action" value="approve" class="btn btn-success"><i class="bi bi-check-circle"></i> Approve & Forward to Supply Officer</button>
                <button type="submit" name="action" value="return" class="btn btn-warning" id="btnReturn"><i class="bi bi-arrow-return-left"></i> Return with Comment</button>
                <button type="submit" name="action" value="reject" class="btn btn-danger"><i class="bi bi-x-circle"></i> Reject</button>
            </div>
        </form>
        </div>
    </div>
    <?php else: ?>
    <div class="alert alert-secondary"><i class="bi bi-info-circle"></i> No actions available for this request (current status: <?= htmlspecialchars($request['status']) ?>).</div>
    <?php endif; ?>

    <?php if ($request['status'] === 'approved'): ?>
    <div class="card-min mb-3">
        <div class="card-header">Release & Receipt</div>
        <div class="card-body">
        <p class="mb-2"><strong>Scheduled Release:</strong> <?= $rel_date ? htmlspecialchars(date('M d, Y', strtotime($rel_date))) : 'Not scheduled' ?></p>
        <?php if (!empty($_SESSION['flash_error'])): ?><div class="alert alert-danger"><?=$_SESSION['flash_error']; unset($_SESSION['flash_error']);?></div><?php endif; ?>
        <?php if (!empty($_SESSION['flash_success'])): ?><div class="alert alert-success"><?=$_SESSION['flash_success']; unset($_SESSION['flash_success']);?></div><?php endif; ?>
        <?php if ($latest_receipt): ?>
            <div>
                <p class="mb-1"><strong>Submitted Receipt:</strong> <a class="link-danger" href="<?= htmlspecialchars($latest_receipt['photo_path']) ?>" target="_blank"><i class="bi bi-image"></i> View Photo</a></p>
                <p class="text-muted">Status: <?= htmlspecialchars($latest_receipt['status']) ?></p>
            </div>
        <?php else: ?>
            <form method="POST" enctype="multipart/form-data">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                <input type="hidden" name="request_id" value="<?= (int)$request['id'] ?>">
                <label class="form-label">Attach receipt photo (jpg, png, gif, webp)</label>
                <input type="file" name="receipt_photo" class="form-control" accept="image/*" required>
                <button type="submit" name="submit_receipt" class="btn btn-success mt-2"><i class="bi bi-check2-circle"></i> Mark as Received</button>
            </form>
        <?php endif; ?>
        </div>
    </div>
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

    <div class="card-min mb-4">
        <div class="card-header">Action History</div>
        <div class="card-body">
        <?php if (empty($history)): ?>
            <div class="text-muted">No actions recorded yet.</div>
        <?php else: ?>
            <div class="list-group">
                <?php foreach ($history as $h): ?>
                    <div class="list-group-item">
                        <div class="d-flex justify-content-between">
                            <div><strong><?= htmlspecialchars(str_replace('_',' ',$h['action_type'])) ?></strong> by <?= htmlspecialchars(($h['first_name']??'').' '.($h['last_name']??'')) ?> (<?= htmlspecialchars($h['role']) ?>)</div>
                            <div class="text-muted"><?= htmlspecialchars(date('M d, Y g:i A', strtotime($h['created_at']))) ?></div>
                        </div>
                        <?php if (!empty($h['comment'])): ?><div class="mt-2 bg-light p-2 rounded"><?= nl2br(htmlspecialchars($h['comment'])) ?></div><?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
        </div>
    </div>
</body>
</html>
