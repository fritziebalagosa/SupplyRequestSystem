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

// Handle search functionality
$search = $_GET['search'] ?? '';
$search_where = '';
$search_params = [];
if (!empty($search)) {
    $search_where = " AND (r.request_id LIKE ? OR u.first_name LIKE ? OR u.last_name LIKE ? OR it.item_name LIKE ? OR r.status LIKE ?)";
    $search_term = '%' . $search . '%';
    $search_params = array_fill(0, 5, $search_term);
}

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

$stmt=$conn->prepare("SELECT r.id, r.request_id, r.status, r.created_at, u.first_name, u.last_name, GROUP_CONCAT(DISTINCT it.item_name SEPARATOR ', ') items FROM requests r JOIN users u ON r.requester_id=u.id LEFT JOIN request_items ri ON ri.request_id=r.id LEFT JOIN items it ON ri.item_id=it.id WHERE r.status='pending_officer'$search_where GROUP BY r.id ORDER BY r.created_at DESC");
if (!empty($search)) {
    $stmt->bind_param('issss', ...$search_params);
}
$stmt->execute(); $pending_res=$stmt->get_result(); $pending_rows = $pending_res? $pending_res->fetch_all(MYSQLI_ASSOC) : [];
$stmt2=$conn->prepare("SELECT r.id, r.request_id, r.status, r.created_at, u.first_name, u.last_name, GROUP_CONCAT(DISTINCT it.item_name SEPARATOR ', ') items FROM requests r JOIN users u ON r.requester_id=u.id LEFT JOIN request_items ri ON ri.request_id=r.id LEFT JOIN items it ON ri.item_id=it.id WHERE r.status='approved'$search_where GROUP BY r.id ORDER BY r.created_at DESC LIMIT 20");
if (!empty($search)) {
    $stmt2->bind_param('issss', ...$search_params);
}
$stmt2->execute(); $forwarded=$stmt2->get_result();
$csrf=generate_csrf_token(); $flash=$_SESSION['flash_message'] ?? ''; unset($_SESSION['flash_message']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Requests Awaiting Your Review</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        :root {
            --red-primary: #e74c3c;
            --red-dark: #c0392b;
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
            max-width: 1200px;
            margin: 0 auto;
            padding: 2rem 1.5rem;
        }

        .page-title {
            font-size: 1.75rem;
            font-weight: 600;
            color: var(--gray-900);
            letter-spacing: -0.5px;
            margin-bottom: 1rem;
        }

        /* Alert Messages */
        .alert {
            border-radius: 8px;
            border: none;
            box-shadow: 0 0.125rem 0.25rem rgba(0, 0, 0, 0.075);
            margin-bottom: 1.5rem;
        }

        .alert-info {
            background-color: #d1ecf1;
            color: #0c5460;
            border-color: #bee5eb;
        }

        /* Cards */
        .section-card {
            background: white;
            border-radius: 12px;
            border: 1px solid var(--gray-200);
            overflow: hidden;
            margin-bottom: 2rem;
            box-shadow: 0 0.125rem 0.25rem rgba(0, 0, 0, 0.075);
        }

        .section-header {
            padding: 1.25rem 1.5rem;
            border-bottom: 1px solid var(--gray-200);
            background: white;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .section-header h2 {
            font-size: 1.125rem;
            font-weight: 600;
            margin: 0;
            color: var(--gray-900);
        }

        .section-header i {
            color: var(--gray-700);
        }

        /* Tables */
        .table-minimal {
            margin: 0;
            width: 100%;
        }

        .table-minimal thead th {
            background: var(--gray-50);
            color: var(--gray-700);
            font-weight: 600;
            font-size: 0.75rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            padding: 1rem 1.5rem;
            border: none;
            border-bottom: 1px solid var(--gray-200);
            text-align: left;
        }

        .table-minimal tbody td {
            padding: 1rem 1.5rem;
            color: var(--gray-900);
            font-size: 0.9375rem;
            border: none;
            border-bottom: 1px solid var(--gray-100);
            vertical-align: middle;
        }

        .table-minimal tbody tr:last-child td {
            border-bottom: none;
        }

        .table-minimal tbody tr:hover {
            background-color: var(--gray-50);
        }

        /* Badges */
        .badge-minimal {
            display: inline-flex;
            align-items: center;
            padding: 0.35rem 0.75rem;
            border-radius: 6px;
            font-size: 0.8125rem;
            font-weight: 500;
            border: 1px solid;
            gap: 0.375rem;
        }

        .badge-pending {
            background-color: #fff3cd;
            color: #856404;
            border-color: #ffeaa7;
        }

        .badge-approved {
            background-color: #d4edda;
            color: #155724;
            border-color: #c3e6cb;
        }

        .badge-info {
            background-color: #d1ecf1;
            color: #0c5460;
            border-color: #bee5eb;
        }

        .request-id {
            font-family: 'Courier New', monospace;
            color: var(--red-primary);
            font-weight: 600;
        }

        /* Buttons */
        .btn-minimal {
            padding: 0.4rem 0.875rem;
            border-radius: 6px;
            font-weight: 500;
            font-size: 0.875rem;
            border: 1px solid;
            transition: all 0.2s ease;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 0.375rem;
            cursor: pointer;
        }

        .btn-action-view {
            background: #d1ecf1;
            color: #0c5460;
            border-color: #bee5eb;
        }

        .btn-action-view:hover {
            background: #bee5eb;
            border-color: #17a2b8;
            color: #0c5460;
            transform: translateY(-1px);
        }

        .btn-primary {
            background-color: var(--red-primary);
            border-color: var(--red-primary);
            color: white;
            font-weight: 500;
            padding: 0.4rem 0.875rem;
            font-size: 0.875rem;
            border-radius: 6px;
            transition: all 0.2s ease;
        }

        .btn-primary:hover {
            background-color: var(--red-dark);
            border-color: var(--red-dark);
            transform: translateY(-1px);
        }

        .btn-outline-danger {
            border-color: var(--red-primary);
            color: var(--red-primary);
            font-weight: 500;
            padding: 0.4rem 0.875rem;
            font-size: 0.875rem;
            border-radius: 6px;
            transition: all 0.2s ease;
        }

        .btn-outline-danger:hover {
            background-color: var(--red-primary);
            border-color: var(--red-primary);
            transform: translateY(-1px);
        }

        .empty-state {
            padding: 3rem;
            text-align: center;
            color: var(--gray-700);
        }

        .empty-state i {
            font-size: 2rem;
            display: block;
            margin-bottom: 1rem;
            color: #adb5bd;
        }

        .stock-low {
            color: #b45309;
            font-weight: 600;
        }

        .stock-none {
            color: #b91c1c;
            font-weight: 700;
        }

        /* Modal improvements */
        .modal-content {
            border-radius: 12px;
            border: none;
            box-shadow: 0 6px 32px rgba(0, 0, 0, 0.15);
        }

        .modal-header {
            border-bottom: 1px solid var(--gray-200);
            padding: 1.25rem 1.5rem;
            background: var(--gray-50);
        }

        .modal-title {
            font-weight: 600;
            color: var(--gray-900);
        }

        .modal-body {
            padding: 1.5rem;
        }

        /* Filter Card */
        .filter-card {
            background: white;
            border: 1px solid var(--gray-200);
            border-radius: 12px;
            padding: 1.5rem;
            margin-bottom: 1.5rem;
        }

        .filter-label {
            font-size: 0.875rem;
            font-weight: 500;
            color: var(--gray-700);
            margin-bottom: 0.5rem;
            display: block;
        }

        .form-control-minimal {
            border: 1px solid var(--gray-300);
            border-radius: 8px;
            padding: 0.625rem 0.875rem;
            font-size: 0.9375rem;
            transition: all 0.2s ease;
            background: white;
        }

        .form-control-minimal:focus {
            outline: none;
            border-color: var(--red-primary);
            box-shadow: 0 0 0 0.2rem rgba(231, 76, 60, 0.1);
        }

        .form-select-minimal {
            border: 1px solid var(--gray-300);
            border-radius: 8px;
            padding: 0.625rem 0.875rem;
            font-size: 0.9375rem;
            transition: all 0.2s ease;
            background: white;
        }

        .form-select-minimal:focus {
            outline: none;
            border-color: var(--red-primary);
            box-shadow: 0 0 0 0.2rem rgba(231, 76, 60, 0.1);
        }

        /* Search row (matches design image) */
        .search-row {
          display: flex;
          gap: 1rem;
          align-items: center;
          width: 100%;
        }

        .search-input {
          flex: 1 1 auto;
          border: 1px solid var(--gray-300);
          border-radius: 12px;
          padding: 0 1rem;
          font-size: 0.95rem;
          background: white;
          transition: all 0.15s ease;
          height: 52px;
          display: flex;
          align-items: center;
        }

        .search-input:focus {
          outline: none;
          border-color: var(--red-primary);
          box-shadow: 0 0 0 0.12rem rgba(231, 76, 60, 0.08);
        }

        .search-btn {
          background-color: var(--red-primary);
          color: white;
          border: none;
          padding: 0 1.25rem;
          min-width: 150px;
          height: 52px;
          border-radius: 12px;
          display: inline-flex;
          align-items: center;
          justify-content: center;
          gap: 0.5rem;
          font-weight: 600;
          box-shadow: none;
          cursor: pointer;
          line-height: 1;
        }

        .search-btn:hover {
          background-color: var(--red-dark);
          transform: translateY(-1px);
        }

        /* Buttons */
        .btn-minimal {
            padding: 0.625rem 1.25rem;
            border-radius: 8px;
            font-weight: 500;
            font-size: 0.9375rem;
            border: none;
            transition: all 0.2s ease;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
        }

        .btn-primary-minimal {
            background-color: var(--red-primary);
            color: white;
        }

        .btn-primary-minimal:hover {
            background-color: var(--red-dark);
            transform: translateY(-1px);
        }

        /* Responsive */
        @media (max-width: 768px) {
            .container-main {
                padding: 1.5rem 1rem;
            }

            .page-title {
                font-size: 1.5rem;
            }

            .filter-card {
                padding: 1rem;
            }

            .table-minimal thead th,
            .table-minimal tbody td {
                padding: 0.75rem 1rem;
            }

            .d-flex.gap-2 {
                flex-direction: column;
                gap: 0.5rem !important;
            }

            .btn-primary,
            .btn-outline-danger {
                width: 100%;
                justify-content: center;
            }
        }
    </style>
</head><body>
<?php include('../includes/officer_navbar.php'); ?>
<div class="container-main">
  <h1 class="page-title">Requests Awaiting Your Review</h1>
  
  <!-- Filter Card -->
        <div class="filter-card">
          <form method="GET">
            <label class="filter-label">Search Records</label>
            <div class="search-row">
              <input type="text" name="search" class="search-input" placeholder="Search by Request ID, Items, Requester Name, or Status..." value="<?= htmlspecialchars($search) ?>">
              <button type="submit" class="search-btn">
                <i class="bi bi-funnel"></i> Search
              </button>
            </div>
          </form>
            <?php if (!empty($search)): ?>
                <div class="mt-2">
                    <small class="text-muted">Showing results for "<?= htmlspecialchars($search) ?>"</small>
                    <a href="?" class="ms-2 text-muted"><i class="bi bi-x"></i> Clear</a>
                </div>
            <?php endif; ?>
        </div>
  
  <?php if($flash): ?><div class="alert alert-info"><?= htmlspecialchars($flash) ?></div><?php endif; ?>

  <div class="section-card">
    <div class="section-header"><i class="bi bi-clock-history"></i><h2>Pending Review</h2></div>
    <div class="table-responsive">
      <table class="table table-minimal">
        <thead><tr><th>Request ID</th><th>Items</th><th>Requester</th><th>Status</th><th>Date</th><th>Actions</th></tr></thead>
        <tbody>
        <?php if(empty($pending_rows)): ?>
          <tr><td colspan="6">
            <div class="empty-state">
              <i class="bi bi-inbox"></i>
              <p>No requests pending your review at the moment.</p>
            </div>
          </td></tr>
        <?php else: foreach($pending_rows as $r): ?>
          <tr>
            <td><span class="request-id">#<?= htmlspecialchars($r['request_id'] ?: $r['id']) ?></span></td>
            <td><span title="<?= htmlspecialchars($r['items'] ?? '—') ?>"><?= htmlspecialchars($r['items'] ?? '—') ?></span></td>
            <td><?= htmlspecialchars(($r['first_name']??'').' '.($r['last_name']??'')) ?></td>
            <td><span class="badge-pending"><i class="bi bi-clock-history"></i> <?= htmlspecialchars(ucwords(str_replace('_',' ',$r['status']))) ?></span></td>
            <td><?= htmlspecialchars(date('M d, Y g:i A', strtotime($r['created_at']))) ?></td>
            <td class="d-flex gap-2">
              <a class="btn-minimal btn-action-view" href="view_request.php?id=<?= (int)$r['id'] ?>"><i class="bi bi-eye"></i> View</a>
              <button class="btn-minimal btn-primary-minimal" data-bs-toggle="modal" data-bs-target="#m<?= (int)$r['id'] ?>">
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
        <div class="empty-state">
          <i class="bi bi-inbox"></i>
          <p>No approved requests yet.</p>
        </div>
      </td></tr>
      <?php else: while($fr=$forwarded->fetch_assoc()): ?>
        <tr>
          <td><span class="request-id">#<?= htmlspecialchars($fr['request_id'] ?: $fr['id']) ?></span></td>
          <td><?= htmlspecialchars($fr['items'] ?? '—') ?></td>
          <td><?= htmlspecialchars(($fr['first_name']??'').' '.($fr['last_name']??'')) ?></td>
          <td><?= htmlspecialchars(date('M d, Y g:i A', strtotime($fr['created_at']))) ?></td>
          <td><a class="btn-minimal btn-action-view" href="view_request.php?id=<?= (int)$fr['id'] ?>"><i class="bi bi-eye"></i> View Details</a></td>
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
