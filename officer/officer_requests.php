<?php
session_start();
include('../config/db.php');
include('../includes/csrf.php');

if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'] ?? '', ['officer','supply_officer'])) {
    header('Location: ../auth/log_in.php'); exit;
}

$user_id = (int)$_SESSION['user_id'];
$college_office_id = $_SESSION['college_office_id'] ?? null;
// Officer may operate centrally; proceed even if college_office_id is null.

if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['action'], $_POST['request_id'])) {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) { $_SESSION['flash_message']='Invalid security token.'; header('Location: officer_requests.php'); exit; }
    $rid=(int)$_POST['request_id'];
    // Use appropriate remarks field per action to avoid overwrite
    $action = $_POST['action'];
    $remarks_forward = trim($_POST['remarks_forward'] ?? '');
    $remarks_reject = trim($_POST['remarks_reject'] ?? '');
    $v=$conn->prepare("SELECT status FROM requests WHERE id=? LIMIT 1");
    $v->bind_param('i',$rid); $v->execute(); $req=$v->get_result()->fetch_assoc(); $v->close();
    if(!$req || $req['status']!=='pending_officer'){ $_SESSION['flash_message']='Request not available for action.'; header('Location: officer_requests.php'); exit; }

    if($action==='forward'){
        $s=$conn->prepare("SELECT ri.id ri_id, ri.quantity, i.stock_qty, i.item_name FROM request_items ri JOIN items i ON ri.item_id=i.id WHERE ri.request_id=?");
        $s->bind_param('i',$rid); $s->execute(); $rs=$s->get_result(); $s->close();
        $adjusted=0; $none=[];
        while($row=$rs->fetch_assoc()){
            $reqq=(int)$row['quantity']; $stk=(int)$row['stock_qty']; $new=$reqq;
            if($stk<=0){ $new=0; $none[]=$row['item_name']; }
            elseif($reqq>$stk){ $new=$stk; }
            if($new!==$reqq){ $u=$conn->prepare("UPDATE request_items SET quantity=? WHERE id=?"); $u->bind_param('ii',$new,$row['ri_id']); $u->execute(); $u->close(); $adjusted++; }
        }
        $auto = ($adjusted>0?'System: adjusted quantities to available stock. ':'') . (!empty($none)?('No stock for: '.implode(', ',$none).'. '):'');
        $final = trim($auto . ($remarks_forward?('Officer remark: '.$remarks_forward):''));
        $u=$conn->prepare("UPDATE requests SET status='for_final_approval' WHERE id=?");
        $u->bind_param('i',$rid); $ok=$u->execute(); $u->close();
        if($ok){ $ia=$conn->prepare("INSERT INTO request_actions (request_id,action_by,role,action_type,comment,created_at) VALUES (?,?,?,?,?,NOW())"); $role=$_SESSION['role']; $type='forwarded_to_admin'; $ia->bind_param('iisss',$rid,$user_id,$role,$type,$final); $ia->execute(); $ia->close(); $_SESSION['flash_message']='Request forwarded to Supply Head for final approval.'; }
        else { $_SESSION['flash_message']='Failed to forward request.'; }
        header('Location: officer_requests.php'); exit;
    }
    if($action==='reject'){
        if($remarks_reject===''){ $_SESSION['flash_message']='Please provide a remark when rejecting.'; header('Location: officer_requests.php'); exit; }
        $u=$conn->prepare("UPDATE requests SET status='rejected' WHERE id=?");
        $u->bind_param('i',$rid); $ok=$u->execute(); $u->close();
        if($ok){ $ia=$conn->prepare("INSERT INTO request_actions (request_id,action_by,role,action_type,comment,created_at) VALUES (?,?,?,?,?,NOW())"); $role=$_SESSION['role']; $type='rejected'; $ia->bind_param('iisss',$rid,$user_id,$role,$type,$remarks_reject); $ia->execute(); $ia->close(); $_SESSION['flash_message']='Request rejected and returned to dean/head.'; }
        else { $_SESSION['flash_message']='Failed to reject request.'; }
        header('Location: officer_requests.php'); exit;
    }
}

$stmt=$conn->prepare("SELECT r.id, r.request_id, r.status, r.created_at, u.first_name, u.last_name, GROUP_CONCAT(DISTINCT it.item_name SEPARATOR ', ') items FROM requests r JOIN users u ON r.requester_id=u.id LEFT JOIN request_items ri ON ri.request_id=r.id LEFT JOIN items it ON ri.item_id=it.id WHERE r.status='pending_officer' GROUP BY r.id ORDER BY r.created_at DESC");
$stmt->execute(); $pending_res=$stmt->get_result(); $pending_rows = $pending_res? $pending_res->fetch_all(MYSQLI_ASSOC) : [];
$stmt2=$conn->prepare("SELECT r.id, r.request_id, r.status, r.created_at, u.first_name, u.last_name, GROUP_CONCAT(DISTINCT it.item_name SEPARATOR ', ') items FROM requests r JOIN users u ON r.requester_id=u.id LEFT JOIN request_items ri ON ri.request_id=r.id LEFT JOIN items it ON ri.item_id=it.id WHERE r.status='approved' GROUP BY r.id ORDER BY r.created_at DESC LIMIT 20");
$stmt2->execute(); $forwarded=$stmt2->get_result();
$csrf=generate_csrf_token(); $flash=$_SESSION['flash_message'] ?? ''; unset($_SESSION['flash_message']);
?>
<!DOCTYPE html><html lang="en"><head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Requests Awaiting Your Review</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css" rel="stylesheet">
<style>
  :root { --red-primary:#dc3545; --gray-50:#fafafa; --gray-100:#f5f5f5; --gray-200:#eeeeee; --gray-700:#616161; --gray-900:#212121; }
  body{background:var(--gray-50);color:var(--gray-900)}
  .container-main{max-width:1200px;margin:0 auto;padding:24px}
  .page-title{font-weight:600;margin-bottom:16px}
  .section-card{background:#fff;border:1px solid var(--gray-200);border-radius:12px;overflow:hidden;margin-bottom:20px}
  .section-header{padding:1rem 1.25rem;border-bottom:1px solid var(--gray-200);background:#fff;display:flex;align-items:center;gap:.5rem}
  .section-header h2{font-size:1.05rem;font-weight:600;margin:0}
  .section-header i{color:#6c757d}
  .table-minimal{margin:0;width:100%}
  .table-minimal thead th{background:#f6f6f6;font-size:.8rem;text-transform:uppercase;letter-spacing:.5px}
  .table-minimal tbody td{vertical-align:middle}
  .request-id{font-family:Courier New,monospace;color:var(--red-primary);font-weight:600}
  .badge-pending{background:#fff3cd;color:#856404;border:1px solid #ffeaa7;padding:.25rem .5rem;border-radius:6px;font-size:.8rem}
  .btn-minimal{padding:.4rem .875rem;border-radius:6px;font-weight:500;font-size:.875rem;border:1px solid #bee5eb;transition:.2s;text-decoration:none;display:inline-flex;align-items:center;gap:.375rem;background:#d1ecf1;color:#0c5460}
  .btn-minimal:hover{background:#bee5eb;border-color:#17a2b8;color:#0c5460}
  .empty-state{padding:2rem;text-align:center;color:#6c757d}
  .empty-state i{font-size:1.5rem;display:block;margin-bottom:.5rem;color:#adb5bd}
  .stock-low{color:#b45309;font-weight:600}.stock-none{color:#b91c1c;font-weight:700}
</style>
</head><body>
<?php include('../includes/officer_navbar.php'); ?>
<div class="container-main">
  <h1 class="page-title">Requests Awaiting Your Review</h1>
  <?php if($flash): ?><div class="alert alert-info"><?= htmlspecialchars($flash) ?></div><?php endif; ?>

  <div class="section-card">
    <div class="section-header"><i class="bi bi-clock-history"></i><h2>Pending Review</h2></div>
    <div class="table-responsive">
      <table class="table table-minimal">
        <thead><tr><th>Request ID</th><th>Items</th><th>Requester</th><th>Status</th><th>Date</th><th>Actions</th></tr></thead>
        <tbody>
        <?php if(empty($pending_rows)): ?>
          <tr><td colspan="6">
            <div class="empty-state"><i class="bi bi-inbox" style="font-size:2rem"></i>No requests pending your review at the moment.</div>
          </td></tr>
        <?php else: foreach($pending_rows as $r): ?>
          <tr>
            <td><span class="request-id">#<?= htmlspecialchars($r['request_id'] ?: $r['id']) ?></span></td>
            <td><span title="<?= htmlspecialchars($r['items'] ?? '—') ?>"><?= htmlspecialchars($r['items'] ?? '—') ?></span></td>
            <td><?= htmlspecialchars(($r['first_name']??'').' '.($r['last_name']??'')) ?></td>
            <td><span class="badge-pending"><i class="bi bi-clock-history"></i> <?= htmlspecialchars(ucwords(str_replace('_',' ',$r['status']))) ?></span></td>
            <td><?= htmlspecialchars(date('M d, Y g:i A', strtotime($r['created_at']))) ?></td>
            <td class="d-flex gap-2">
              <a class="btn-minimal" href="view_request.php?id=<?= (int)$r['id'] ?>"><i class="bi bi-eye"></i> View</a>
              <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#m<?= (int)$r['id'] ?>">
                <i class="bi bi-clipboard-check"></i> Review
              </button>
            </td>
          </tr>
        <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>

  <?php if (!empty($pending_rows)): ?>
    <!-- Render modals outside the table for proper Bootstrap behavior -->
    <?php foreach ($pending_rows as $r): ?>
      <div class="modal fade" id="m<?= (int)$r['id'] ?>" tabindex="-1" aria-labelledby="mLabel<?= (int)$r['id'] ?>" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-scrollable">
          <div class="modal-content">
            <div class="modal-header bg-light">
              <h5 class="modal-title" id="mLabel<?= (int)$r['id'] ?>">Verify Request #<?= htmlspecialchars($r['request_id'] ?: $r['id']) ?></h5>
              <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
              <div class="mb-3">
                <div class="table-responsive">
                  <table class="table table-bordered align-middle">
                    <thead class="table-light">
                      <tr>
                        <th>Item</th>
                        <th class="text-center">Requested</th>
                        <th class="text-center">In Stock</th>
                        <th class="text-center">Suggested</th>
                      </tr>
                    </thead>
                    <tbody>
                      <?php $it=$conn->prepare("SELECT ri.quantity, i.stock_qty, i.item_name FROM request_items ri JOIN items i ON ri.item_id=i.id WHERE ri.request_id=?"); $it->bind_param('i',$r['id']); $it->execute(); $itr=$it->get_result(); while($row=$itr->fetch_assoc()): $reqQ=(int)$row['quantity']; $stk=(int)$row['stock_qty']; $sug=max(0,min($reqQ,$stk)); ?>
                        <tr>
                          <td><?= htmlspecialchars($row['item_name']) ?></td>
                          <td class="text-center"><strong><?= $reqQ ?></strong></td>
                          <td class="text-center"><?php if($stk<=0): ?><span class="stock-none">No stock</span><?php elseif($stk<$reqQ): ?><span class="stock-low"><?= $stk ?> (low)</span><?php else: ?><?= $stk ?><?php endif; ?></td>
                          <td class="text-center"><?= $sug ?></td>
                        </tr>
                      <?php endwhile; $it->close(); ?>
                    </tbody>
                  </table>
                </div>
              </div>

              <form method="POST">
                <input type="hidden" name="request_id" value="<?= (int)$r['id'] ?>">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                <div class="mb-3">
                  <label class="form-label">Officer Remarks (optional)</label>
                  <textarea name="remarks_forward" class="form-control" rows="3" placeholder="e.g., Low stock on X; adjusted accordingly."></textarea>
                </div>
                <div class="d-flex gap-2 flex-wrap">
                  <button type="submit" name="action" value="forward" class="btn btn-success">
                    <i class="bi bi-send"></i> Forward to Supply Head
                  </button>
                  <button type="button" class="btn btn-outline-danger" data-bs-toggle="collapse" data-bs-target="#rej<?= (int)$r['id'] ?>">
                    <i class="bi bi-x-circle"></i> Reject & Return
                  </button>
                </div>
                <div id="rej<?= (int)$r['id'] ?>" class="collapse mt-3">
                  <div class="card card-body border-danger">
                    <label class="form-label">Reason for rejection (required)</label>
                    <textarea name="remarks_reject" class="form-control" rows="3" placeholder="State reason (e.g., Printer not eligible)"></textarea>
                    <div class="mt-2">
                      <button type="submit" name="action" value="reject" class="btn btn-danger">
                        <i class="bi bi-exclamation-triangle"></i> Confirm Reject
                      </button>
                    </div>
                  </div>
                </div>
              </form>
            </div>
          </div>
        </div>
      </div>
    <?php endforeach; ?>
  <?php endif; ?>

  <div class="section-card">
    <div class="section-header"><i class="bi bi-check-circle"></i><h2>Approved & Ready for Release</h2></div>
    <div class="table-responsive"><table class="table table-minimal">
      <thead><tr><th>Request ID</th><th>Items</th><th>Requester</th><th>Date Approved</th><th>Actions</th></tr></thead>
      <tbody>
      <?php if($forwarded->num_rows===0): ?><tr><td colspan="5">
        <div class="empty-state"><i class="bi bi-inbox" style="font-size:2rem"></i>No approved requests yet.</div>
      </td></tr>
      <?php else: while($fr=$forwarded->fetch_assoc()): ?>
        <tr>
          <td><span class="request-id">#<?= htmlspecialchars($fr['request_id'] ?: $fr['id']) ?></span></td>
          <td><?= htmlspecialchars($fr['items'] ?? '—') ?></td>
          <td><?= htmlspecialchars(($fr['first_name']??'').' '.($fr['last_name']??'')) ?></td>
          <td><?= htmlspecialchars(date('M d, Y g:i A', strtotime($fr['created_at']))) ?></td>
          <td><a class="btn-minimal" href="view_request.php?id=<?= (int)$fr['id'] ?>"><i class="bi bi-eye"></i> View Details</a></td>
        </tr>
      <?php endwhile; endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php foreach ($pending_rows as $r): ?>
  <div class="modal fade" id="m<?= (int)$r['id'] ?>" tabindex="-1" aria-labelledby="mLabel<?= (int)$r['id'] ?>" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
      <div class="modal-content">
        <div class="modal-header bg-light">
          <h5 class="modal-title" id="mLabel<?= (int)$r['id'] ?>">Verify Request #<?= htmlspecialchars($r['request_id'] ?: $r['id']) ?></h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body">
          <div class="mb-3">
            <div class="table-responsive">
              <table class="table table-minimal">
                <thead class="table-light">
                  <tr>
                    <th>Item</th>
                    <th class="text-center">Requested</th>
                    <th class="text-center">In Stock</th>
                    <th class="text-center">Suggested</th>
                  </tr>
                </thead>
                <tbody>
                  <?php $it=$conn->prepare("SELECT ri.quantity, i.stock_qty, i.item_name FROM request_items ri JOIN items i ON ri.item_id=i.id WHERE ri.request_id=?"); $it->bind_param('i',$r['id']); $it->execute(); $itr=$it->get_result(); while($row=$itr->fetch_assoc()): $reqQ=(int)$row['quantity']; $stk=(int)$row['stock_qty']; $sug=max(0,min($reqQ,$stk)); ?>
                  <tr>
                    <td><?= htmlspecialchars($row['item_name']) ?></td>
                    <td class="text-center"><strong><?= $reqQ ?></strong></td>
                    <td class="text-center"><?php if($stk<=0): ?><span class="stock-none">No stock</span><?php elseif($stk<$reqQ): ?><span class="stock-low"><?= $stk ?> (low)</span><?php else: ?><?= $stk ?><?php endif; ?></td>
                    <td class="text-center"><?= $sug ?></td>
                  </tr>
                <?php endwhile; $it->close(); ?>
                </tbody>
              </table>
            </div>
          </div>

          <form method="POST">
            <input type="hidden" name="request_id" value="<?= (int)$r['id'] ?>">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
            <div class="mb-3">
              <label class="form-label">Officer Remarks (optional)</label>
              <textarea name="remarks_forward" class="form-control" rows="3" placeholder="e.g., Low stock on X; adjusted accordingly."></textarea>
            </div>
            <div class="d-flex gap-2 flex-wrap">
              <button type="submit" name="action" value="forward" class="btn btn-success">
                <i class="bi bi-send"></i> Forward to Supply Head
              </button>
              <button type="button" class="btn btn-outline-danger" data-bs-toggle="collapse" data-bs-target="#rej<?= (int)$r['id'] ?>">
                <i class="bi bi-x-circle"></i> Reject & Return
              </button>
            </div>
            <div id="rej<?= (int)$r['id'] ?>" class="collapse mt-3">
              <div class="card card-body border-danger">
                <label class="form-label">Reason for rejection (required)</label>
                <textarea name="remarks_reject" class="form-control" rows="3" placeholder="State reason (e.g., Printer not eligible)"></textarea>
                <div class="mt-2">
                  <button type="submit" name="action" value="reject" class="btn btn-danger">
                    <i class="bi bi-exclamation-triangle"></i> Confirm Reject
                  </button>
                </div>
              </div>
            </div>
          </form>
        </div>
      </div>
    </div>
  </div>
<?php endforeach; ?>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body></html>
