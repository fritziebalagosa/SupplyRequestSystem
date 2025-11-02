<?php
session_start();
include('../config/db.php');
include('../includes/csrf.php');

if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'] ?? '', ['officer','supply_officer'])) {
  header('Location: ../auth/log_in.php'); exit;
}

$user_id = (int)$_SESSION['user_id'];
$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id <= 0) { die('Invalid request id'); }

// Fetch request + requester
$req = $conn->prepare("SELECT r.*, u.first_name, u.last_name FROM requests r JOIN users u ON r.requester_id=u.id WHERE r.id=? LIMIT 1");
$req->bind_param('i', $id);
$req->execute();
$request = $req->get_result()->fetch_assoc();
$req->close();
if (!$request) { die('Request not found'); }

// Actions
if ($_SERVER['REQUEST_METHOD']==='POST') {
  $csrf = $_POST['csrf_token'] ?? '';
  if (!verify_csrf_token($csrf)) {
    $_SESSION['error'] = 'Security check failed.';
    header('Location: view_request.php?id='.$id); exit;
  }
  // Only when pending_officer
  $st = $conn->prepare('SELECT status FROM requests WHERE id=?');
  $st->bind_param('i', $id); $st->execute(); $row = $st->get_result()->fetch_assoc(); $st->close();
  if (!$row || $row['status']!=='pending_officer') {
    $_SESSION['error'] = 'Request is not pending officer.';
    header('Location: view_request.php?id='.$id); exit;
  }

  if (isset($_POST['forward'])) {
    $remarks = trim($_POST['remarks_forward'] ?? '');
    $up = $conn->prepare("UPDATE requests SET status='for_final_approval' WHERE id=?");
    $up->bind_param('i', $id); $ok = $up->execute(); $up->close();
    if ($ok) {
      $role=$_SESSION['role'];
      $comment = $remarks ? ('Officer remark: '.$remarks) : '';
      $ia=$conn->prepare("INSERT INTO request_actions (request_id,action_by,role,action_type,comment,created_at) VALUES (?,?,?,?,?,NOW())");
      $type='forwarded_to_admin';
      $ia->bind_param('iisss',$id,$user_id,$role,$type,$comment);
      $ia->execute(); $ia->close();
      $_SESSION['success']='Request forwarded for final approval.';
    } else { $_SESSION['error']='Failed to forward request.'; }
    header('Location: view_request.php?id='.$id); exit;
  }

  if (isset($_POST['reject'])) {
    $remarks = trim($_POST['remarks_reject'] ?? '');
    if ($remarks==='') { $_SESSION['error']='Please provide a reason for rejection.'; header('Location: view_request.php?id='.$id); exit; }
    $up = $conn->prepare("UPDATE requests SET status='rejected' WHERE id=?");
    $up->bind_param('i', $id); $ok = $up->execute(); $up->close();
    if ($ok) {
      $role=$_SESSION['role'];
      $ia=$conn->prepare("INSERT INTO request_actions (request_id,action_by,role,action_type,comment,created_at) VALUES (?,?,?,?,?,NOW())");
      $type='rejected';
      $ia->bind_param('iisss',$id,$user_id,$role,$type,$remarks);
      $ia->execute(); $ia->close();
      $_SESSION['error']='Request rejected and returned.';
    } else { $_SESSION['error']='Failed to reject request.'; }
    header('Location: view_request.php?id='.$id); exit;
  }
}

// Items
$it = $conn->prepare("SELECT i.item_name, i.unit, ri.quantity, COALESCE(ri.priority,'Normal') priority FROM request_items ri JOIN items i ON ri.item_id=i.id WHERE ri.request_id=?");
$it->bind_param('i', $id); $it->execute(); $items = $it->get_result();

// History
$hist = $conn->prepare("SELECT ra.*, u.first_name, u.last_name FROM request_actions ra LEFT JOIN users u ON u.id=ra.action_by WHERE ra.request_id=? ORDER BY ra.created_at DESC");
$hist->bind_param('i', $id); $hist->execute(); $history = $hist->get_result();

$csrf_token = generate_csrf_token();
$status_text = ucwords(str_replace('_',' ', $request['status']));
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>View Request - Officer</title>
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
  </style>
</head>
<body>
<?php include('../includes/officer_navbar.php'); ?>
<div class="container-main">
  <a href="officer_requests.php" class="btn btn-light border mb-3"><i class="bi bi-arrow-left"></i> Back to Requests</a>

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
        <?php if (!empty($request['attachment'])): ?>
          <div class="col-12"><div class="text-muted">Attachment</div><a class="link-danger" target="_blank" href="<?= htmlspecialchars($request['attachment']) ?>"><i class="bi bi-paperclip"></i> View Attachment</a></div>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <div class="section-card">
    <div class="section-header"><h2>Requested Items</h2></div>
    <div class="p-3">
      <?php if ($items->num_rows === 0): ?>
        <div class="text-muted">No items attached.</div>
      <?php else: ?>
      <div class="table-responsive">
        <table class="table items-table">
          <thead><tr><th>Item Name</th><th class="text-center">Quantity</th><th class="text-center">Unit</th><th class="text-center">Priority</th></tr></thead>
          <tbody>
            <?php while($row=$items->fetch_assoc()): ?>
              <tr>
                <td><?= htmlspecialchars($row['item_name']) ?></td>
                <td class="text-center"><strong><?= (int)$row['quantity'] ?></strong></td>
                <td class="text-center"><?= htmlspecialchars($row['unit']) ?></td>
                <td class="text-center"><?= htmlspecialchars(ucfirst($row['priority'])) ?></td>
              </tr>
            <?php endwhile; ?>
          </tbody>
        </table>
      </div>
      <?php endif; ?>
    </div>
  </div>

  <?php $can_act = ($request['status']==='pending_officer'); ?>
  <?php if ($can_act): ?>
  <div class="section-card">
    <div class="section-header"><h2>Officer Actions</h2></div>
    <div class="p-3">
      <form method="POST" class="row g-3">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
        <div class="col-12">
          <label class="form-label">Officer Remarks (optional)</label>
          <textarea name="remarks_forward" class="form-control" rows="3" placeholder="e.g., Adjusted due to stock availability."></textarea>
        </div>
        <div class="col-12 d-flex gap-2 flex-wrap">
          <button type="submit" name="forward" class="btn btn-success"><i class="bi bi-send"></i> Forward for Final Approval</button>
          <button type="button" class="btn btn-outline-danger" data-bs-toggle="collapse" data-bs-target="#rejBox"><i class="bi bi-x-circle"></i> Reject</button>
        </div>
        <div id="rejBox" class="collapse col-12">
          <div class="card card-body border-danger mt-2">
            <label class="form-label">Reason for rejection (required)</label>
            <textarea name="remarks_reject" class="form-control" rows="3" placeholder="State reason"></textarea>
            <div class="mt-2"><button type="submit" name="reject" class="btn btn-danger"><i class="bi bi-exclamation-triangle"></i> Confirm Reject</button></div>
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

