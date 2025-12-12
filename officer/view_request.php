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
    // require officer remarks before forwarding
    if ($remarks === '') {
      $_SESSION['error'] = 'Please provide officer remarks before forwarding.';
      header('Location: view_request.php?id='.$id); exit;
    }

    // process approved quantities if provided
    $adjustments = [];
    $ri_ids = $_POST['ri_id'] ?? [];
    $approved_qtys = $_POST['approved_qty'] ?? [];

    // validate each approved qty: ownership, numeric, and <= min(requested_qty, stock_qty)
    $errors = [];
    if (!empty($ri_ids) && is_array($ri_ids)) {
      if (!is_array($approved_qtys) || count($approved_qtys) !== count($ri_ids)) {
        $_SESSION['error'] = 'Mismatch in submitted items. Please try again.';
        header('Location: view_request.php?id='.$id); exit;
      }
      $selRi = $conn->prepare("SELECT item_id, quantity FROM request_items WHERE id = ? AND request_id = ? LIMIT 1");
      $selItemStock = $conn->prepare("SELECT item_name, stock_qty FROM items WHERE id = ? LIMIT 1");
      for ($i=0;$i<count($ri_ids);$i++) {
        $rid = intval($ri_ids[$i]);
        $aq = intval($approved_qtys[$i] ?? 0);
        // fetch request_items row
        $selRi->bind_param('ii', $rid, $id);
        $selRi->execute();
        $res = $selRi->get_result();
        if (!$res || $res->num_rows === 0) {
          $errors[] = "Invalid item entry (ri_id={$rid}).";
          continue;
        }
        $riRow = $res->fetch_assoc();
        $reqQty = (int)$riRow['quantity'];
        $itemId = (int)$riRow['item_id'];
        // fetch item stock and name
        $selItemStock->bind_param('i', $itemId);
        $selItemStock->execute();
        $itRes = $selItemStock->get_result();
        if (!$itRes || $itRes->num_rows === 0) {
          $errors[] = "Item not found for ri_id={$rid}.";
          continue;
        }
        $itRow = $itRes->fetch_assoc();
        $stock = (int)$itRow['stock_qty'];
        $iname = $itRow['item_name'];
        $maxAllowed = min($reqQty, $stock);
        if ($aq < 0) {
          $errors[] = "Approved quantity for {$iname} cannot be negative.";
        } elseif ($aq > $maxAllowed) {
          $errors[] = "Approved quantity for {$iname} cannot exceed requested quantity or available stock (max {$maxAllowed}).";
        }
        // collect adjustment for recording
        $adjustments[] = [
          'ri_id' => $rid,
          'item_name' => $iname,
          'quantity' => $reqQty,
          'approved' => $aq
        ];
      }
      $selRi->close();
      $selItemStock->close();
    }

    if (!empty($errors)) {
      $_SESSION['error'] = implode(' ', $errors);
      header('Location: view_request.php?id='.$id); exit;
    }

    // all validations passed -> persist adjustments
    if (!empty($adjustments)) {
      // if approved_quantity column exists, update request_items; otherwise, collect adjustments for comment
      $colCheck2 = $conn->query("SHOW COLUMNS FROM request_items LIKE 'approved_quantity'");
      $hasApproved = ($colCheck2 && $colCheck2->num_rows > 0);
      if ($hasApproved) {
        $upd = $conn->prepare("UPDATE request_items SET approved_quantity = ? WHERE id = ?");
        foreach ($adjustments as $adj) {
          $rid = (int)$adj['ri_id']; $aq = (int)$adj['approved'];
          $upd->bind_param('ii', $aq, $rid);
          $upd->execute();
        }
        $upd->close();
      } else {
        // turn adjustments into textual form
        $tmp = [];
        foreach ($adjustments as $adj) {
          $tmp[] = "{$adj['item_name']} (ri_id={$adj['ri_id']}):{$adj['approved']}";
        }
        $adjustments = $tmp;
      }
    }

    $up = $conn->prepare("UPDATE requests SET status='for_final_approval' WHERE id=?");
    $up->bind_param('i', $id); $ok = $up->execute(); $up->close();
    if ($ok) {
      $role=$_SESSION['role'];
      $comment = "Officer remark:\n" . $remarks;
      if (!empty($adjustments) && is_array($adjustments)) {
          $comment .= "\n\n--- Quantity Adjustments ---\n";
          foreach ($adjustments as $adj) {
              if (is_array($adj)) {
                  $comment .= "• {$adj['item_name']}: " . 
                            "Requested: {$adj['quantity']} → Approved: {$adj['approved']}\n";
              } else {
                  $comment .= "• " . $adj . "\n";
              }
          }
      }
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
$colCheck = $conn->query("SHOW COLUMNS FROM request_items LIKE 'approved_quantity'");
$hasApprovedCol = ($colCheck && $colCheck->num_rows > 0);
$select = "SELECT i.item_name, i.unit, ri.quantity, COALESCE(ri.priority,'Normal') priority, ri.id as ri_id, ri.item_id";
if ($hasApprovedCol) $select .= ", ri.approved_quantity";
$select .= " FROM request_items ri JOIN items i ON ri.item_id=i.id WHERE ri.request_id=? ORDER BY ri.id ASC";
$it = $conn->prepare($select);
$it->bind_param('i', $id); $it->execute(); $items = $it->get_result();

// History
$hist = $conn->prepare("SELECT ra.*, u.first_name, u.last_name FROM request_actions ra LEFT JOIN users u ON u.id=ra.action_by WHERE ra.request_id=? ORDER BY ra.created_at DESC");
$hist->bind_param('i', $id); $hist->execute(); $history = $hist->get_result();

$csrf_token = generate_csrf_token();

// Determine status badge
$status = strtolower($request['status']);
$badge_class = 'badge-pending';

if (strpos($status, 'approved') !== false) {
    $badge_class = 'badge-approved';
} elseif (strpos($status, 'rejected') !== false) {
    $badge_class = 'badge-rejected';
} elseif (strpos($status, 'completed') !== false) {
    $badge_class = 'badge-completed';
} elseif (strpos($status, 'returned') !== false) {
    $badge_class = 'badge-returned';
} elseif (strpos($status, 'forwarded') !== false) {
    $badge_class = 'badge-forwarded';
}

$status_text = ucwords(str_replace('_', ' ', $request['status']));
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
            max-width: 1200px;
            margin: 0 auto;
            padding: 2rem 1.5rem;
        }

        /* Back Button */
        .back-button {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.5rem 1rem;
            background-color: white;
            color: var(--gray-700);
            border: 1px solid var(--gray-300);
            border-radius: 8px;
            text-decoration: none;
            font-size: 0.9375rem;
            font-weight: 500;
            transition: all 0.2s ease;
            margin-bottom: 1.5rem;
        }

        .back-button:hover {
            background-color: var(--gray-50);
            border-color: var(--gray-400);
            color: var(--gray-900);
            transform: translateY(-1px);
        }

        /* Page Header */
        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 2rem;
            flex-wrap: wrap;
            gap: 1rem;
        }

        .page-title {
            font-size: 1.75rem;
            font-weight: 600;
            color: var(--gray-900);
            letter-spacing: -0.5px;
            margin: 0;
        }

        .request-id {
            font-family: 'Courier New', monospace;
            font-weight: 600;
            color: var(--red-primary);
        }

        /* Alert Messages */
        .alert {
            border-radius: 8px;
            border: none;
            box-shadow: 0 0.125rem 0.25rem rgba(0, 0, 0, 0.075);
            margin-bottom: 1.5rem;
        }

        .alert-success {
            background-color: #d4edda;
            color: #155724;
            border-color: #c3e6cb;
        }

        .alert-warning {
            background-color: #fff3cd;
            color: #664d03;
            border-left: 4px solid #ffc107;
            border-color: #c3e6cb;
        }

        .alert-danger {
            background-color: var(--red-light);
            color: #721c24;
            border-color: #f5c6cb;
        }

        .alert-info {
            background-color: #d1ecf1;
            color: #0c5460;
            border-color: #bee5eb;
        }

        .alert-minimal i {
            font-size: 1.25rem;
        }

        /* Info Cards */
        .info-card {
            background: white;
            border-radius: 12px;
            border: 1px solid var(--gray-200);
            padding: 1.5rem;
            margin-bottom: 1.5rem;
        }

        .info-card h5 {
            font-size: 1.125rem;
            font-weight: 600;
            color: var(--gray-900);
            margin-bottom: 1rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .info-row {
            padding: 0.75rem 0;
            border-bottom: 1px solid var(--gray-100);
        }

        .info-row:last-child {
            border-bottom: none;
        }

        .info-label {
            font-weight: 600;
            color: var(--gray-700);
            font-size: 0.875rem;
            margin-bottom: 0.25rem;
        }

        .info-value {
            color: var(--gray-900);
            font-size: 0.9375rem;
        }

        /* Link styling */
        .file-link {
            color: var(--red-primary);
            text-decoration: none;
            font-weight: 500;
            display: inline-flex;
            align-items: center;
            gap: 0.375rem;
        }

        .file-link:hover {
            color: var(--red-dark);
            text-decoration: underline;
        }

        /* Timeline */
        .timeline {
            position: relative;
            padding-left: 1.5rem;
        }
        .timeline::before {
            content: '';
            position: absolute;
            left: 0.75rem;
            top: 0;
            bottom: 0;
            width: 2px;
            background: var(--gray-200);
        }
        .timeline-item {
            position: relative;
            margin-bottom: 1.5rem;
        }
        .timeline-marker {
            position: absolute;
            left: -1.5rem;
            width: 1rem;
            height: 1rem;
            border-radius: 50%;
            background: var(--gray-300);
            top: 0.25rem;
        }
        .timeline-content {
            padding-left: 1.5rem;
        }

        /* Badges */
        .badge-minimal {
            display: inline-flex;
            align-items: center;
            padding: 0.5rem 1rem;
            border-radius: 8px;
            font-size: 0.875rem;
            font-weight: 500;
            border: 1px solid;
            gap: 0.5rem;
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

        .badge-rejected {
            background-color: var(--red-light);
            color: #721c24;
            border-color: #f5c6cb;
        }

        .badge-completed {
            background-color: #d1ecf1;
            color: #0c5460;
            border-color: #bee5eb;
        }

        .badge-returned {
            background-color: #d1ecf1;
            color: #0c5460;
            border-color: #bee5eb;
        }

        .badge-forwarded {
            background-color: #d1ecf1;
            color: #0c5460;
            border-color: #bee5eb;
        }

        /* Tables */
        .items-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 1rem;
        }

        .items-table thead th {
            background: var(--gray-50);
            color: var(--gray-700);
            font-weight: 600;
            font-size: 0.75rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            padding: 0.75rem 1rem;
            border: 1px solid var(--gray-200);
            text-align: left;
        }

        .items-table tbody td {
            padding: 0.75rem 1rem;
            border: 1px solid var(--gray-200);
            font-size: 0.9375rem;
        }

        .items-table tbody tr:hover {
            background-color: var(--gray-50);
        }

        /* Form Elements */
        .form-label-minimal {
            font-size: 0.875rem;
            color: var(--gray-700);
            font-weight: 600;
            margin-bottom: 0.5rem;
            display: block;
        }

        .form-control-minimal {
            border: 1px solid var(--gray-300);
            border-radius: 8px;
            padding: 0.75rem 1rem;
            font-size: 0.9375rem;
            transition: all 0.2s ease;
            width: 100%;
        }

        .form-control-minimal:focus {
            border-color: var(--red-primary);
            box-shadow: 0 0 0 0.2rem rgba(220, 53, 69, 0.1);
            outline: none;
        }

        textarea.form-control-minimal {
            resize: vertical;
            min-height: 100px;
        }

        /* Buttons */
        .btn-minimal {
            padding: 0.75rem 1.5rem;
            border-radius: 8px;
            font-weight: 600;
            font-size: 0.9375rem;
            border: none;
            transition: all 0.2s ease;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            cursor: pointer;
            text-decoration: none;
        }

        .btn-primary-minimal {
            background-color: var(--red-primary);
            color: white;
        }

        .btn-primary-minimal:hover {
            background-color: var(--red-dark);
            transform: translateY(-1px);
        }

        .btn-success-minimal {
            background-color: #28a745;
            color: white;
        }

        .btn-success-minimal:hover {
            background-color: #218838;
            transform: translateY(-1px);
        }

        .btn-danger-minimal {
            background-color: var(--red-primary);
            color: white;
        }

        .btn-danger-minimal:hover {
            background-color: var(--red-dark);
            transform: translateY(-1px);
        }

        .form-actions {
            display: flex;
            gap: 1rem;
            justify-content: flex-end;
            margin-top: 1.5rem;
            flex-wrap: wrap;
        }

        /* Action Card */
        .action-card {
            background: linear-gradient(135deg, #fff5f5 0%, #ffffff 100%);
            border: 1px solid #ffeaa7;
            border-radius: 12px;
            padding: 1.5rem;
            margin-top: 1rem;
        }

        .action-card h5 {
            color: var(--red-primary);
            margin-bottom: 1rem;
        }

        /* Quantity Input */
        .quantity-input {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            max-width: 140px;
            margin: 0 auto;
        }

        .quantity-input input {
            text-align: center;
            font-weight: 600;
        }

        .quantity-input .input-group-text {
            background: var(--gray-100);
            border: 1px solid var(--gray-300);
            color: var(--gray-600);
            font-size: 0.875rem;
        }

        /* Timeline */
        .timeline {
            position: relative;
            padding-left: 2rem;
        }

        .timeline::before {
            content: '';
            position: absolute;
            left: 0.75rem;
            top: 0;
            bottom: 0;
            width: 2px;
            background: var(--gray-200);
        }

        .timeline-item {
            position: relative;
            margin-bottom: 1.5rem;
        }

        .timeline-marker {
            position: absolute;
            left: -1.5rem;
            top: 0.25rem;
            width: 12px;
            height: 12px;
            border-radius: 50%;
            background: var(--red-primary);
            border: 2px solid white;
        }

        .timeline-content {
            background: var(--gray-50);
            border-radius: 8px;
            padding: 1rem;
            border: 1px solid var(--gray-200);
        }

        .timeline-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 0.5rem;
        }

        .timeline-title {
            font-weight: 600;
            color: var(--gray-900);
        }

        .timeline-time {
            font-size: 0.875rem;
            color: var(--gray-700);
        }

        .timeline-comment {
            margin-top: 0.5rem;
            padding: 0.75rem;
            background: white;
            border-radius: 6px;
            border-left: 3px solid var(--red-primary);
        }

        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 3rem 1rem;
            color: var(--gray-700);
        }

        .empty-state i {
            font-size: 2.5rem;
            color: var(--gray-300);
            margin-bottom: 1rem;
            display: block;
        }

        /* Responsive */
        @media (max-width: 768px) {
            .container-main {
                padding: 1.5rem 1rem;
            }

            .page-title {
                font-size: 1.5rem;
            }

            .info-card {
                padding: 1.25rem;
            }

            .items-table thead {
                display: none;
            }

            .items-table tbody tr {
                display: block;
                margin-bottom: 1rem;
                border: 1px solid var(--gray-200);
                border-radius: 8px;
            }

            .items-table tbody td {
                display: flex;
                justify-content: space-between;
                padding: 0.75rem 1rem;
                border: none;
                border-bottom: 1px solid var(--gray-100);
            }

            .items-table tbody td:last-child {
                border-bottom: none;
            }

            .items-table tbody td::before {
                content: attr(data-label);
                font-weight: 600;
                color: var(--gray-700);
                font-size: 0.8125rem;
                text-transform: uppercase;
            }
        }
    </style>
</head>
<body>
<?php include('../includes/officer_navbar.php'); ?>
<div class="container-main">
  <a href="officer_requests.php" class="back-button">
    <i class="bi bi-arrow-left"></i> Back to Requests
  </a>

  <?php if(isset($_SESSION['success'])): ?><div class="alert alert-success"><?=$_SESSION['success']; unset($_SESSION['success']);?></div><?php endif; ?>
  <?php if(isset($_SESSION['error'])): ?><div class="alert alert-danger"><?=$_SESSION['error']; unset($_SESSION['error']);?></div><?php endif; ?>

  <div class="page-header">
    <h1 class="page-title">Request <span class="request-id">#<?= htmlspecialchars($request['request_id'] ?: $request['id']) ?></span></h1>
    <span class="badge-minimal <?= $badge_class ?>">
      <?php if (strpos($status, 'approved') !== false): ?>
          <i class="bi bi-check-circle"></i>
      <?php elseif (strpos($status, 'rejected') !== false): ?>
          <i class="bi bi-x-circle"></i>
      <?php elseif (strpos($status, 'completed') !== false): ?>
          <i class="bi bi-check-circle-fill"></i>
      <?php else: ?>
          <i class="bi bi-clock-history"></i>
      <?php endif; ?>
      <?= htmlspecialchars($status_text) ?>
    </span>
  </div>

        <!-- Request Details -->
        <div class="info-card">
            <h5><i class="bi bi-info-circle"></i> Request Details</h5>
            <div class="info-row">
                <div class="info-label">Description</div>
                <div class="info-value"><?= nl2br(htmlspecialchars($request['description'] ?? 'No description provided')) ?></div>
            </div>
            <?php if (!empty($request['attachment'])): ?>
            <div class="info-row">
                <div class="info-label">Attachment</div>
                <div class="info-value">
                    <?php 
                    $attachment_path = $request['attachment'];
                    
                    // Remove '../' prefix to get web-accessible path
                    if (strpos($attachment_path, '../') === 0) {
                        $web_path = substr($attachment_path, 3);
                    } else {
                        $web_path = $attachment_path;
                    }
                    
                    // Since we're in officer/ directory, we need to go up one level to reach uploads/
                    if (strpos($web_path, 'uploads/') === 0) {
                        $web_path = '../' . $web_path;
                    }
                    ?>
                    <a href="<?= htmlspecialchars($web_path) ?>" target="_blank" class="file-link">
                        <i class="bi bi-paperclip"></i> View Attached File
                    </a>
                </div>
            </div>
            <?php endif; ?>
            <div class="info-row">
                <div class="info-label">Requested By</div>
                <div class="info-value"><?= htmlspecialchars($request['first_name'] . ' ' . $request['last_name']) ?></div>
            </div>
            <div class="info-row">
                <div class="info-label">Date Submitted</div>
                <div class="info-value"><?= htmlspecialchars(date('M d, Y g:i A', strtotime($request['created_at']))) ?></div>
            </div>
            
            <?php
            // Check if receipt exists for this request
            $receipt_stmt = $conn->prepare("SELECT * FROM release_proofs WHERE request_id = ? ORDER BY created_at DESC LIMIT 1");
            $receipt_stmt->bind_param("i", $id);
            $receipt_stmt->execute();
            $receipt = $receipt_stmt->get_result()->fetch_assoc();
            $receipt_stmt->close();
            
            // fetch release schedule
            $schedule_stmt = $conn->prepare("SELECT release_date FROM release_schedule WHERE request_id = ? LIMIT 1");
            $schedule_stmt->bind_param("i", $id);
            $schedule_stmt->execute();
            $schedule = $schedule_stmt->get_result()->fetch_assoc();
            $schedule_stmt->close();
            
            // Add default time for display
            if ($schedule && $schedule['release_date']) {
                $schedule['release_time'] = '09:00:00';
            }
            ?>
            
            <div class="info-row">
                <div class="info-label">Delivery Date</div>
                <div class="info-value">
                    <?php if ($receipt): ?>
                        <?php if ($schedule && $schedule['release_date']): ?>
                            <span style="color: var(--gray-700); font-size: 0.9375rem;">
                                <i class="bi bi-calendar-check"></i> Scheduled for <?= htmlspecialchars(date('M d, Y', strtotime($schedule['release_date']))) ?>
                                <?php if ($schedule['release_time']): ?>
                                    at <?= htmlspecialchars(date('g:i A', strtotime($schedule['release_time']))) ?>
                                <?php endif; ?>
                            </span>
                        <?php elseif ($receipt): ?>
                            <span style="color: var(--gray-700); font-size: 0.9375rem;">
                                <i class="bi bi-truck"></i> Delivered on <?= htmlspecialchars(date('M d, Y g:i A', strtotime($receipt['created_at']))) ?>
                            </span>
                        <?php else: ?>
                            <span style="color: var(--gray-400); font-style: italic;">Not scheduled</span>
                        <?php endif; ?>
                    <?php else: ?>
                        <?php if ($schedule && $schedule['release_date']): ?>
                            <span style="color: var(--gray-700); font-size: 0.9375rem;">
                                <i class="bi bi-calendar-check"></i> Scheduled for <?= htmlspecialchars(date('M d, Y', strtotime($schedule['release_date']))) ?>
                                <?php if ($schedule['release_time']): ?>
                                    at <?= htmlspecialchars(date('g:i A', strtotime($schedule['release_time']))) ?>
                                <?php endif; ?>
                            </span>
                        <?php else: ?>
                            <span style="color: var(--gray-400); font-style: italic;">Not scheduled</span>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            </div>
            
            <div class="info-row">
                <div class="info-label">Receipt Status</div>
                <div class="info-value">
                    <?php if ($receipt): ?>
                        <span class="badge-minimal badge-completed">
                            <i class="bi bi-check-circle-fill"></i> Received
                        </span>
                        <div class="mt-2">
                            <small class="text-muted">Received at: <?= date('M j, Y h:i A', strtotime($receipt['created_at'])) ?></small>
                        </div>
                        <?php if (!empty($receipt['image_path'])): ?>
                            <div class="mt-2">
                                <a href="<?= htmlspecialchars($receipt['image_path']) ?>" target="_blank" class="file-link">
                                    <i class="bi bi-image"></i> View Receipt
                                </a>
                            </div>
                        <?php endif; ?>
                        <?php if (!empty($receipt['notes'])): ?>
                            <div class="mt-2 p-2 bg-light rounded">
                                <small><?= nl2br(htmlspecialchars($receipt['notes'])) ?></small>
                            </div>
                        <?php endif; ?>
                    <?php else: ?>
                        <span class="badge-minimal badge-pending">
                            <i class="bi bi-clock-history"></i> Pending
                        </span>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Requested Items -->
        <div class="info-card">
            <h5><i class="bi bi-box-seam"></i> Requested Items</h5>
            <?php if ($items->num_rows === 0): ?>
                <p style="color: var(--gray-700);">No items attached.</p>
            <?php else: ?>
                <?php
                // Check for quantity adjustments in history
                $hasAdjustments = false;
                $adjustmentNote = '';
                $historyArray = $history->fetch_all(MYSQLI_ASSOC);
                foreach ($historyArray as $h) {
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
                <table class="items-table">
                    <thead>
                        <tr>
                            <th>Item Name</th>
                            <th>Requested</th>
                            <th>Approved</th>
                            <th>Unit</th>
                            <th>Priority</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php 
                    // Reset the items result pointer
                    $items->data_seek(0);
                    while($row=$items->fetch_assoc()): ?>
                        <tr>
                            <td data-label="Item"><?= htmlspecialchars($row['item_name']) ?></td>
                            <td data-label="Requested"><strong><?= (int)$row['quantity'] ?></strong></td>
                            <td data-label="Approved">
                                <?php 
                                $approved = isset($row['approved_quantity']) ? (int)$row['approved_quantity'] : (int)$row['quantity'];
                                echo $approved;
                                if (isset($row['approved_quantity']) && $row['approved_quantity'] != $row['quantity']): ?>
                                    <span class="badge-minimal badge-warning" style="margin-left: 0.5rem;">
                                        <i class="bi bi-pencil-square"></i> Adjusted
                                    </span>
                                <?php endif; ?>
                            </td>
                            <td data-label="Unit"><?= htmlspecialchars($row['unit']) ?></td>
                            <td data-label="Priority"><?= htmlspecialchars(ucfirst($row['priority'])) ?></td>
                        </tr>
                    <?php endwhile; ?>
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

        <!-- Officer Actions -->
        <div class="info-card">
            <h5><i class="bi bi-clipboard-check"></i> Officer Actions</h5>
  <?php $can_act = ($request['status']==='pending_officer'); ?>
  <?php if ($can_act): ?>
      <form method="POST" class="row g-3">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
        <!-- Allow officer to adjust allowable quantities per item -->
        <div class="col-12">
          <div class="alert alert-info">
            <i class="bi bi-info-circle"></i>
            You can adjust the approved quantities for each item below. These adjustments will be visible to the requester, dean/head, and admin.
            <ul class="mb-0 mt-2">
              <li>Original quantities will be preserved for reference</li>
              <li>Set to 0 to disallow an item</li>
              <li>Cannot exceed requested quantity or available stock</li>
            </ul>
          </div>
          <div class="table-responsive">
            <table class="table">
              <thead>
                <tr>
                  <th>Item</th>
                  <th class="text-center">Requested</th>
                  <th class="text-center">In Stock</th>
                  <th class="text-center">Allow Quantity</th>
                </tr>
              </thead>
              <tbody>
              <?php
              // Check if approved_quantity column exists
              $colCheck = $conn->query("SHOW COLUMNS FROM request_items LIKE 'approved_quantity'");
              $hasApprovedCol = ($colCheck && $colCheck->num_rows > 0);
              
              // Build query based on column existence
              $query = "SELECT 
                      ri.id as request_item_id,
                      ri.quantity,";
              if ($hasApprovedCol) {
                  $query .= " ri.approved_quantity,";
              }
              $query .= " i.item_name,
                      i.stock_qty,
                      i.unit 
                  FROM request_items ri 
                  JOIN items i ON ri.item_id=i.id 
                  WHERE ri.request_id=? ORDER BY ri.id ASC";
              
              // Re-query items with stock info for the editable list
              $it2 = $conn->prepare($query);
              $it2->bind_param('i', $id);
              $it2->execute();
              $items2 = $it2->get_result();
              while($r=$items2->fetch_assoc()):
                $reqQty = (int)$r['quantity'];
                $stockQty = (int)$r['stock_qty'];
                $defaultAllowed = isset($r['approved_quantity']) && $r['approved_quantity'] !== null ? (int)$r['approved_quantity'] : $reqQty;
                $maxAllowed = min($reqQty, $stockQty);
              ?>
                <tr>
                  <td>
                    <?php echo htmlspecialchars($r['item_name']); ?>
                    <div class="text-muted small"><?php echo htmlspecialchars($r['unit']); ?></div>
                  </td>
                  <td class="text-center"><?php echo $reqQty; ?></td>
                  <td class="text-center"><?php echo $stockQty; ?></td>
                  <td class="text-center">
                    <?php
                    // Sanitize the values to avoid any potential issues
                    $ri_id = (int)$r['request_item_id'];
                    $allowedQty = (int)$defaultAllowed;
                    $maxQty = (int)$maxAllowed;
                    ?>
                    <input type="hidden" name="ri_id[]" value="<?php echo $ri_id; ?>">
                    <div class="quantity-input">
                        <input type="number" 
                               name="approved_qty[]" 
                               class="form-control form-control-minimal text-center"
                               value="<?php echo $allowedQty; ?>"
                               min="0"
                               max="<?php echo $maxQty; ?>"
                               required>
                        <span class="input-group-text">/<?php echo $maxQty; ?></span>
                    </div>
                    <?php if ($maxAllowed < $reqQty): ?>
                    <div class="mt-2"><small class="text-warning">Limited by stock</small></div>
                    <?php endif; ?>
                  </td>
                </tr>
              <?php endwhile; $it2->close(); ?>
              </tbody>
            </table>
          </div>
        </div>
        <div class="col-12">
            <div class="action-card">
                <h5><i class="bi bi-clipboard-check"></i> Officer Actions</h5>
                <label class="form-label-minimal">Officer Remarks (required)</label>
                <textarea name="remarks_forward" class="form-control form-control-minimal" rows="3" placeholder="e.g., Adjusted due to stock availability." required></textarea>
                
                <div class="form-actions">
                    <button type="submit" name="forward" class="btn-minimal btn-success-minimal">
                        <i class="bi bi-send"></i> Forward for Final Approval
                    </button>
                    <button type="button" class="btn-minimal btn-danger-minimal" data-bs-toggle="collapse" data-bs-target="#rejBox">
                        <i class="bi bi-x-circle"></i> Reject
                    </button>
                </div>
                
                <div id="rejBox" class="collapse">
                    <div class="mt-3">
                        <label class="form-label-minimal">Reason for rejection (required)</label>
                        <textarea name="remarks_reject" class="form-control form-control-minimal" rows="3" placeholder="State reason"></textarea>
                        <div class="form-actions">
                            <button type="submit" name="reject" class="btn-minimal btn-danger-minimal">
                                <i class="bi bi-exclamation-triangle"></i> Confirm Reject
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
      </form>
        </div>
    <?php endif; ?>

        <!-- Action History -->
        <div class="info-card">
            <h5><i class="bi bi-clock-history"></i> Action History</h5>
            <div class="timeline" style="margin-top: 1.5rem;">
                <?php 
                // Reset history pointer and fetch as array
                $history->data_seek(0);
                $historyArray = $history->fetch_all(MYSQLI_ASSOC);
                if (!empty($historyArray)): ?>
                    <?php foreach ($historyArray as $action): ?>
                        <div class="timeline-item">
                            <div class="timeline-marker"></div>
                            <div class="timeline-content">
                                <div class="d-flex justify-content-between align-items-center">
                                    <h6 class="mb-1">
                                        <?php 
                                        $actionText = '';
                                        $icon = '';
                                        $color = 'secondary';
                                        
                                        switch ($action['action_type']) {
                                            case 'submitted':
                                                $actionText = 'Request Submitted';
                                                $icon = 'bi-send';
                                                $color = 'primary';
                                                break;
                                            case 'approved':
                                                $actionText = 'Request Approved';
                                                $icon = 'bi-check-circle';
                                                $color = 'success';
                                                break;
                                            case 'rejected':
                                                $actionText = 'Request Rejected';
                                                $icon = 'bi-x-circle';
                                                $color = 'danger';
                                                break;
                                            case 'returned':
                                                $actionText = 'Request Returned for Revision';
                                                $icon = 'bi-arrow-return-left';
                                                $color = 'warning';
                                                break;
                                            case 'received':
                                                $actionText = 'Items Received';
                                                $icon = 'bi-check-circle-fill';
                                                $color = 'info';
                                                break;
                                            case 'forwarded_to_admin':
                                                $actionText = 'Forwarded for Final Approval';
                                                $icon = 'bi-send';
                                                $color = 'primary';
                                                break;
                                            default:
                                                $actionText = ucfirst(str_replace('_', ' ', $action['action_type']));
                                                $icon = 'bi-info-circle';
                                        }
                                        ?>
                                        <i class="bi <?= $icon ?> me-1 text-<?= $color ?>"></i>
                                        <?= $actionText ?>
                                    </h6>
                                    <small class="text-muted"><?= date('M d, Y h:i A', strtotime($action['created_at'])) ?></small>
                                </div>
                                <div class="ms-4 mt-1">
                                    <?php if (!empty($action['first_name'])): ?>
                                        <small class="text-muted">By: <?= htmlspecialchars($action['first_name'] . ' ' . $action['last_name']) ?></small><br>
                                    <?php endif; ?>
                                    <?php if (!empty($action['comment'])): ?>
                                        <div class="mt-1 p-2 bg-light rounded">
                                            <small><?= nl2br(htmlspecialchars($action['comment'])) ?></small>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="text-center text-muted py-3">
                        <i class="bi bi-info-circle"></i> No action history found for this request.
                    </div>
                <?php endif; ?>
        </div>

</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>

