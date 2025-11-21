<?php
session_start();
include('../config/db.php');
include('../includes/csrf.php');

if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'] ?? '', ['admin','supply_head'])) {
    header('Location: ../auth/log_in.php');
    exit;
}

$user_id = (int)$_SESSION['user_id'];
$request_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($request_id <= 0) { die('Invalid request id'); }

// Fetch request and requester
$req_stmt = $conn->prepare("SELECT r.*, u.first_name, u.last_name FROM requests r JOIN users u ON r.requester_id=u.id WHERE r.id=? LIMIT 1");
$req_stmt->bind_param('i', $request_id);
$req_stmt->execute();
$request = $req_stmt->get_result()->fetch_assoc();
$req_stmt->close();
if (!$request) { die('Request not found'); }

// Handle actions
// Ensure support tables
$conn->query("CREATE TABLE IF NOT EXISTS release_schedule (request_id INT PRIMARY KEY, release_date DATE NOT NULL, created_at DATETIME DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
$conn->query("CREATE TABLE IF NOT EXISTS request_receipts (id INT AUTO_INCREMENT PRIMARY KEY, request_id INT NOT NULL, receiver_id INT NOT NULL, photo_path VARCHAR(255) NOT NULL, status VARCHAR(20) DEFAULT 'submitted', created_at DATETIME DEFAULT CURRENT_TIMESTAMP, confirmed_at DATETIME NULL, confirmed_by INT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf = $_POST['csrf_token'] ?? '';
    if (!verify_csrf_token($csrf)) {
        $_SESSION['error'] = 'Security check failed. Please try again.';
        header('Location: view_request.php?id=' . $request_id);
        exit;
    }

    if (isset($_POST['approve_request'])) {
        $release_date = trim($_POST['release_date'] ?? '');
        if ($release_date === '') {
            $_SESSION['error'] = 'Please select a release date before approving.';
            header('Location: view_request.php?id=' . $request_id);
            exit;
        }
        // Prevent double approve
        $curr = $conn->prepare("SELECT status FROM requests WHERE id=?");
        $curr->bind_param('i', $request_id);
        $curr->execute();
        $st = $curr->get_result()->fetch_assoc();
        $curr->close();
        if (!$st || in_array($st['status'], ['approved','rejected'])) {
            $_SESSION['error'] = 'Request is not pending final approval.';
            header('Location: view_request.php?id=' . $request_id);
            exit;
        }

        // Approve (no release_date column in requests) -> store date in action log comment
        $up = $conn->prepare("UPDATE requests SET status='approved' WHERE id=?");
        $up->bind_param('i', $request_id);
        $up->execute();
        $up->close();

    // Deduct stock: use approved_quantity if the column exists, otherwise use requested quantity
    $colCheck = $conn->query("SHOW COLUMNS FROM request_items LIKE 'approved_quantity'");
    $hasApprovedCol = ($colCheck && $colCheck->num_rows > 0);
    if ($hasApprovedCol) {
      $ded = $conn->prepare("UPDATE items JOIN request_items ON items.id=request_items.item_id SET items.stock_qty = items.stock_qty - COALESCE(request_items.approved_quantity, request_items.quantity) WHERE request_items.request_id = ?");
    } else {
      $ded = $conn->prepare("UPDATE items JOIN request_items ON items.id=request_items.item_id SET items.stock_qty = items.stock_qty - request_items.quantity WHERE request_items.request_id = ?");
    }
    $ded->bind_param('i', $request_id);
    $ded->execute();
    $ded->close();

        // Upsert release schedule
        $conn->query("CREATE TABLE IF NOT EXISTS release_schedule (request_id INT PRIMARY KEY, release_date DATE NOT NULL, created_at DATETIME DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $ins = $conn->prepare("INSERT INTO release_schedule (request_id, release_date) VALUES (?,?) ON DUPLICATE KEY UPDATE release_date=VALUES(release_date)");
        $ins->bind_param('is', $request_id, $release_date);
        $ins->execute();
        $ins->close();

        // Log action
        $role = $_SESSION['role'];
        $comment = 'Release date: ' . $release_date;
        $ia = $conn->prepare("INSERT INTO request_actions (request_id, action_by, role, action_type, comment, created_at) VALUES (?, ?, ?, 'approved', ?, NOW())");
        $ia->bind_param('iiss', $request_id, $user_id, $role, $comment);
        $ia->execute();
        $ia->close();

        // Send notifications to requester, dean, and head
        require_once('../includes/functions.php');
        send_approval_notifications($conn, $request_id, $release_date);

        $_SESSION['success'] = 'Request approved, stock updated, and notifications sent.';
        header('Location: view_request.php?id=' . $request_id);
        exit;
    }

    if (isset($_POST['reject_request'])) {
        $remarks = trim($_POST['remarks'] ?? '');
        if ($remarks === '') {
            $_SESSION['error'] = 'Please provide a reason for rejection.';
            header('Location: view_request.php?id=' . $request_id);
            exit;
        }
        $up = $conn->prepare("UPDATE requests SET status='rejected' WHERE id=?");
        $up->bind_param('i', $request_id);
        $up->execute();
        $up->close();

        $role = $_SESSION['role'];
        $ia = $conn->prepare("INSERT INTO request_actions (request_id, action_by, role, action_type, comment, created_at) VALUES (?, ?, ?, 'rejected', ?, NOW())");
        $ia->bind_param('iiss', $request_id, $user_id, $role, $remarks);
        $ia->execute();
        $ia->close();

        $_SESSION['error'] = 'Request has been rejected.';
        header('Location: view_request.php?id=' . $request_id);
        exit;
    }

    if (isset($_POST['confirm_receipt'])) {
        $rid = $request_id;
        // mark latest submitted receipt as confirmed
        $sel = $conn->prepare("SELECT id FROM request_receipts WHERE request_id=? AND status='submitted' ORDER BY created_at DESC LIMIT 1");
        $sel->bind_param('i', $rid);
        $sel->execute();
        $rec = $sel->get_result()->fetch_assoc();
        $sel->close();
        if ($rec) {
            $upd = $conn->prepare("UPDATE request_receipts SET status='confirmed', confirmed_at=NOW(), confirmed_by=? WHERE id=?");
            $upd->bind_param('ii', $user_id, $rec['id']);
            $upd->execute();
            $upd->close();
            // Optionally mark request completed
            $ur = $conn->prepare("UPDATE requests SET status='completed' WHERE id=?");
            $ur->bind_param('i', $rid);
            $ur->execute();
            $ur->close();
            // Log action
            $role = $_SESSION['role'];
            $ia = $conn->prepare("INSERT INTO request_actions (request_id, action_by, role, action_type, comment, created_at) VALUES (?, ?, ?, 'receipt_confirmed', '', NOW())");
            $ia->bind_param('iis', $rid, $user_id, $role);
            $ia->execute();
            $ia->close();
            $_SESSION['success'] = 'Receipt confirmed and request completed.';
        } else {
            $_SESSION['error'] = 'No pending receipt to confirm.';
        }
        header('Location: view_request.php?id=' . $request_id);
        exit;
    }
}

$it = null;
$colCheckItems = $conn->query("SHOW COLUMNS FROM request_items LIKE 'approved_quantity'");
$hasApprovedItems = ($colCheckItems && $colCheckItems->num_rows > 0);
if ($hasApprovedItems) {
  $it = $conn->prepare("SELECT i.item_name, i.unit, ri.quantity, ri.approved_quantity, COALESCE(ri.approved_quantity, ri.quantity) AS effective_quantity, COALESCE(ri.priority, 'Normal') AS priority FROM request_items ri JOIN items i ON ri.item_id=i.id WHERE ri.request_id=?");
} else {
  $it = $conn->prepare("SELECT i.item_name, i.unit, ri.quantity, NULL AS approved_quantity, ri.quantity AS effective_quantity, COALESCE(ri.priority, 'Normal') AS priority FROM request_items ri JOIN items i ON ri.item_id=i.id WHERE ri.request_id=?");
}
$it->bind_param('i', $request_id);
$it->execute();
$items = $it->get_result();

// Fetch history
$hist = $conn->prepare("SELECT ra.*, u.first_name, u.last_name FROM request_actions ra LEFT JOIN users u ON u.id=ra.action_by WHERE ra.request_id=? ORDER BY ra.created_at DESC");
$hist->bind_param('i', $request_id);
$hist->execute();
$history = $hist->get_result();

$csrf_token = generate_csrf_token();
$status_text = ucwords(str_replace('_',' ', $request['status']));
$can_act = in_array($request['status'], ['for_final_approval']);

// Fetch scheduled release date if any
$sched_date = null;
$rs = $conn->prepare("SELECT release_date FROM release_schedule WHERE request_id=? LIMIT 1");
$rs->bind_param('i', $request_id);
$rs->execute();
$rsres = $rs->get_result()->fetch_assoc();
if ($rsres) { $sched_date = $rsres['release_date']; }
$rs->close();

// Fetch receipt if submitted
$rec_stmt = $conn->prepare("SELECT * FROM request_receipts WHERE request_id=? ORDER BY created_at DESC LIMIT 1");
$rec_stmt->bind_param('i', $request_id);
$rec_stmt->execute();
$receipt = $rec_stmt->get_result()->fetch_assoc();
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
    .section-card{background:#fff;border:1px solid #eee;border-radius:12px;overflow:hidden;margin-bottom:1rem}
    .section-header{padding:1rem 1.25rem;border-bottom:1px solid #eee;background:#fff}
    .section-header h2{font-size:1.05rem;font-weight:600;margin:0}
    .items-table th{background:#f6f6f6;text-transform:uppercase;font-size:.8rem}
    .badge-status{background:#fff3cd;color:#856404;border:1px solid #ffeaa7}
    .list-group .list-group-item{border:0;border-top:1px solid #eee;padding-top:.5rem;padding-bottom:.5rem}
    .list-group .list-group-item:first-child{border-top:0}
  </style>
</head>
<body>
  <?php include('../includes/admin_sidebar.php'); ?>
<div class="container-main">
  <a href="manage_requests.php" class="btn btn-light border mb-3"><i class="bi bi-arrow-left"></i> Back to Requests</a>

  <?php if(isset($_SESSION['success'])): ?><div class="alert alert-success"><?=$_SESSION['success']; unset($_SESSION['success']);?></div><?php endif; ?>
  <?php if(isset($_SESSION['error'])): ?><div class="alert alert-danger"><?=$_SESSION['error']; unset($_SESSION['error']);?></div><?php endif; ?>

  <h3 class="page-title">Request <span class="request-id">#<?= htmlspecialchars($request['request_id'] ?: $request['id']) ?></span>
    <span class="badge badge-status ms-2 px-2 py-1"><?= htmlspecialchars($status_text) ?></span>
  </h3>

  <div class="section-card">
    <div class="section-header"><h2>Request Details</h2></div>
    <div class="p-3">
      <div class="row g-3">
        <div class="col-md-6"><div class="text-muted">Requester</div><div><?= htmlspecialchars($request['first_name'].' '.$request['last_name']) ?></div></div>
        <div class="col-md-6"><div class="text-muted">Date Submitted</div><div><?= htmlspecialchars(date('M d, Y g:i A', strtotime($request['created_at']))) ?></div></div>
        <div class="col-12"><div class="text-muted">Description</div><div><?= nl2br(htmlspecialchars($request['description'] ?? '')) ?></div></div>
        <?php if ($sched_date): ?>
        <div class="col-md-6"><div class="text-muted">Scheduled Release</div><div><?= htmlspecialchars(date('M d, Y', strtotime($sched_date))) ?></div></div>
        <?php endif; ?>
        <?php if(!empty($request['attachment'])): ?>
          <div class="col-12"><div class="text-muted">Attachment</div><a class="link-danger" href="<?= htmlspecialchars($request['attachment']) ?>" target="_blank"><i class="bi bi-paperclip"></i> View Attached File</a></div>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <div class="section-card">
    <div class="section-header"><h2>Requested Items</h2></div>
    <div class="p-3">
      <div class="table-responsive">
        <table class="table items-table">
          <thead><tr><th>Item Name</th><th class="text-center">Quantity</th><th class="text-center">Unit</th><th class="text-center">Priority</th></tr></thead>
          <tbody>
            <?php while($row=$items->fetch_assoc()):
                $requested = (int)$row['quantity'];
                $approved = isset($row['approved_quantity']) && $row['approved_quantity'] !== null ? (int)$row['approved_quantity'] : null;
                $effective = (int)$row['effective_quantity'];
            ?>
              <tr>
                <td><?= htmlspecialchars($row['item_name']) ?></td>
                <td class="text-center">
                  <strong><?= $effective ?></strong>
                  <?php if ($approved !== null && $approved !== $requested): ?>
                    <div class="text-muted small">Requested: <?= $requested ?> — Adjusted to <?= $approved ?></div>
                  <?php endif; ?>
                </td>
                <td class="text-center"><?= htmlspecialchars($row['unit']) ?></td>
                <td class="text-center"><?= htmlspecialchars(ucfirst($row['priority'])) ?></td>
              </tr>
            <?php endwhile; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>

  <?php if ($receipt): ?>
  <div class="section-card">
    <div class="section-header"><h2>Receipt Confirmation</h2></div>
    <div class="p-3">
      <div class="mb-2">
        <div class="text-muted">Submitted Receipt</div>
        <a class="link-danger" href="<?= htmlspecialchars($receipt['photo_path']) ?>" target="_blank"><i class="bi bi-image"></i> View Receipt Photo</a>
        <div class="text-muted mt-1">Status: <?= htmlspecialchars($receipt['status']) ?></div>
      </div>
      <?php if ($receipt['status'] === 'submitted'): ?>
      <form method="POST" class="mt-2">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>" />
        <button type="submit" name="confirm_receipt" class="btn btn-primary"><i class="bi bi-check2-circle"></i> Confirm Receipt</button>
      </form>
      <?php endif; ?>
    </div>
  </div>
  <?php endif; ?>

  <?php if ($can_act): ?>
  <div class="section-card">
    <div class="section-header"><h2>Take Action</h2></div>
    <div class="p-3">
      <form method="POST" class="row g-3">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>" />
        <div class="col-md-4">
          <label class="form-label">Release Date</label>
          <input type="date" name="release_date" class="form-control" required>
        </div>
        <div class="col-12 d-flex gap-2">
          <button type="submit" name="approve_request" class="btn btn-success"><i class="bi bi-check-circle"></i> Approve Request</button>
          <button type="button" class="btn btn-outline-danger" data-bs-toggle="collapse" data-bs-target="#rejBox"><i class="bi bi-x-circle"></i> Reject</button>
        </div>
        <div id="rejBox" class="collapse">
          <div class="mt-3">
            <label class="form-label">Reason for rejection</label>
            <textarea name="remarks" class="form-control" rows="3" placeholder="Enter reason for rejection..."></textarea>
            <div class="mt-2">
              <button type="submit" name="reject_request" class="btn btn-danger"><i class="bi bi-exclamation-triangle"></i> Confirm Reject</button>
            </div>
          </div>
        </div>
      </form>
    </div>
  </div>
  <?php endif; ?>

  <div class="section-card mb-4">
    <div class="section-header"><h2>Action History</h2></div>
    <div class="p-3">
      <?php if ($history->num_rows === 0): ?>
        <div class="text-muted">No actions recorded yet.</div>
      <?php else: ?>
        <div class="list-group">
          <?php while($h=$history->fetch_assoc()): ?>
            <div class="list-group-item">
              <div class="d-flex justify-content-between">
                <div><strong><?= htmlspecialchars(str_replace('_',' ', $h['action_type'])) ?></strong> by <?= htmlspecialchars(($h['first_name']??'').' '.($h['last_name']??'')) ?> (<?= htmlspecialchars($h['role']) ?>)</div>
                <div class="text-muted"><?= htmlspecialchars(date('M d, Y g:i A', strtotime($h['created_at']))) ?></div>
              </div>
              <?php if(!empty($h['comment'])): ?><div class="mt-2 bg-light p-2 rounded"><?= nl2br(htmlspecialchars($h['comment'])) ?></div><?php endif; ?>
            </div>
          <?php endwhile; ?>
        </div>
      <?php endif; ?>
    </div>
  </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
