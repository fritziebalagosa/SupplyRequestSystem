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
  
  // Handle officer forward with adjustments
  if (isset($_POST['forward'])) {
    $remarks = trim($_POST['remarks_forward'] ?? '');
    if ($remarks === '') { $_SESSION['error'] = 'Please provide officer remarks.'; header('Location: view_request.php?id='.$id); exit; }
    
    // Capture quantity adjustments from form
    $adjustments = [];
    if (isset($_POST['approved_qty']) && is_array($_POST['approved_qty'])) {
        foreach ($_POST['approved_qty'] as $ri_id => $approved_qty) {
            $ri_id = (int)$ri_id;
            $approved_qty = (int)$approved_qty;
            
            // Get original item details for logging
            $item_stmt = $conn->prepare("SELECT ri.quantity, i.item_name FROM request_items ri JOIN items i ON ri.item_id = i.id WHERE ri.id = ?");
            $item_stmt->bind_param('i', $ri_id);
            $item_stmt->execute();
            $item_info = $item_stmt->get_result()->fetch_assoc();
            $item_stmt->close();
            
            if ($item_info && $approved_qty != $item_info['quantity']) {
                $adjustments[] = [
                    'ri_id' => $ri_id,
                    'item_name' => $item_info['item_name'],
                    'quantity' => $item_info['quantity'],
                    'approved' => $approved_qty
                ];
            }
        }
    }
    
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
        // Fallback: collect adjustments for comment only
        $tmp = [];
        foreach ($adjustments as $adj) {
          $tmp[] = "{$adj['item_name']} (ri_id={$adj['ri_id']}): {$adj['approved']}";
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
      $ia->execute();$ia->close();
      
      // Send notifications to all relevant parties
      require_once('../includes/notifications.php');
      
      // If adjustments were made, send specific adjustment notification
      if (!empty($adjustments)) {
          $adjustment_text = '';
          foreach ($adjustments as $adj) {
              if (is_array($adj)) {
                  $adjustment_text .= "{$adj['item_name']}: {$adj['quantity']} → {$adj['approved']}; ";
              } else {
                  $adjustment_text .= $adj . "; ";
              }
          }
          send_quantity_adjustment_notification($conn, $id, trim($adjustment_text));
      }
      
      // Send general status notification
      send_request_status_notification($conn, $id, 'for_final_approval', $comment, $user_id);
      
      $_SESSION['success']='Request forwarded to Supply Head for final approval.';
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
      
      // Send notifications to all relevant parties
      require_once('../includes/notifications.php');
      send_request_status_notification($conn, $id, 'rejected', $remarks, $user_id);
      
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

// Reset history pointer and fetch as array for later use
if ($history instanceof mysqli_result) {
    $history->data_seek(0);
    $history = $history->fetch_all(MYSQLI_ASSOC);
}

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

        /* Action History */
        .action-history {
            background: white;
            border-radius: 12px;
            border: 1px solid #e5e7eb;
            padding: 1.5rem;
            margin-bottom: 1.5rem;
        }

        .action-history h5 {
            font-size: 1.125rem;
            font-weight: 600;
            color: #111827;
            margin-bottom: 1.5rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .timeline {
            position: relative;
        }

        .timeline-item {
            position: relative;
            padding-left: 2.5rem;
            margin-bottom: 1.5rem;
        }

        .timeline-item:last-child {
            margin-bottom: 0;
        }

        .timeline-item::before {
            content: '';
            position: absolute;
            left: 0.875rem;
            top: 1.25rem;
            bottom: -1.5rem;
            width: 1px;
            background: #e5e7eb;
        }

        .timeline-item:last-child::before {
            display: none;
        }

        .timeline-icon {
            position: absolute;
            left: 0;
            top: 0.75rem;
            width: 1.75rem;
            height: 1.75rem;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            background-color: white;
            border: 2px solid #dc3545;
            font-size: 0.75rem;
            color: #dc3545;
            z-index: 2;
        }

        .timeline-card {
            background: white;
            border: 1px solid #e5e7eb;
            border-radius: 0.75rem;
            padding: 1rem;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
        }

        .timeline-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 0.5rem;
        }

        .timeline-title {
            font-size: 0.9375rem;
            font-weight: 600;
            color: #111827;
            margin: 0;
            line-height: 1.3;
        }

        .timeline-meta {
            font-size: 0.8125rem;
            color: #6b7280;
            font-weight: 400;
            margin-bottom: 0.25rem;
        }

        .timeline-details {
            background-color: #f9fafb;
            border: 1px solid #f3f4f6;
            border-radius: 0.5rem;
            padding: 0.75rem;
            margin-top: 0.75rem;
        }

        .timeline-detail-label {
            font-size: 0.6875rem;
            font-weight: 600;
            color: #374151;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            margin-bottom: 0.375rem;
        }

        .timeline-detail-content {
            font-size: 0.8125rem;
            color: #111827;
            line-height: 1.4;
        }

        .timeline-timestamp {
            font-size: 0.75rem;
            color: #9ca3af;
            text-align: right;
            white-space: nowrap;
            font-weight: 400;
        }

        .quantity-item {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            margin-bottom: 0.25rem;
            font-size: 0.8125rem;
        }

        .quantity-item::before {
            content: '•';
            color: #dc3545;
            font-weight: bold;
            font-size: 0.875rem;
            line-height: 1;
        }

        .ri-id {
            background-color: #e0e0e0 !important;
            color: #333;
            padding: 2px 6px !important;
            border-radius: 4px !important;
            font-size: 0.85em !important;
            font-weight: normal;
        }

        /* Timeline */

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
                $historyArray = is_array($history) ? $history : [];
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
                    <div class="quantity-input">
                        <input type="number" 
                               name="approved_qty[<?php echo $ri_id; ?>]" 
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
    <?php endif; ?>

        <!-- Action History -->
        <div class="action-history">
            <h5><i class="bi bi-clock-history"></i> Action History</h5>
            <div class="timeline">
                <?php 
                // Reset history pointer and fetch as array
                $historyArray = is_array($history) ? $history : [];
                if (!empty($historyArray)): ?>
                    <?php foreach ($historyArray as $action): ?>
                        <div class="timeline-item">
                            <?php 
                            $iconSymbol = '';
                            $actionTitle = '';
                            
                            switch ($action['action_type']) {
                                case 'submitted':
                                    $iconSymbol = 'bi-send';
                                    $actionTitle = 'Request Submitted';
                                    break;
                                case 'approved':
                                    $iconSymbol = 'bi-check';
                                    $actionTitle = 'Request Approved';
                                    break;
                                case 'rejected':
                                    $iconSymbol = 'bi-x';
                                    $actionTitle = 'Request Rejected';
                                    break;
                                case 'returned':
                                    $iconSymbol = 'bi-arrow-return-left';
                                    $actionTitle = 'Request Returned';
                                    break;
                                case 'officer_remark':
                                    $iconSymbol = 'bi-info';
                                    $actionTitle = 'Officer Remark';
                                    break;
                                case 'forwarded_to_admin':
                                    $iconSymbol = 'bi-send';
                                    $actionTitle = 'Forwarded for Final Approval';
                                    break;
                                case 'receipt_confirmed':
                                    $iconSymbol = 'bi-check';
                                    $actionTitle = 'Receipt Confirmed';
                                    break;
                                case 'received':
                                    $iconSymbol = 'bi-check-circle';
                                    $actionTitle = 'Items Received';
                                    break;
                                default:
                                    $iconSymbol = 'bi-info';
                                    $actionTitle = ucfirst(str_replace('_', ' ', $action['action_type']));
                            }
                            ?>
                            <div class="timeline-icon">
                                <i class="bi <?= $iconSymbol ?>"></i>
                            </div>
                            <div class="timeline-card">
                                <div class="timeline-header">
                                    <div>
                                        <h6 class="timeline-title"><?= $actionTitle ?></h6>
                                        <div class="timeline-meta">By: <?= htmlspecialchars($action['first_name'] . ' ' . $action['last_name']) ?></div>
                                    </div>
                                    <div class="timeline-timestamp"><?= date('M d, Y h:i A', strtotime($action['created_at'])) ?></div>
                                </div>
                                
                                <?php if (!empty($action['comment'])): ?>
                                    <div class="timeline-details">
                                        <?php 
                                        $comment = $action['comment'];
                                        
                                        // Check if comment contains release date information
                                        if (strpos($comment, 'Release date:') !== false) {
                                            echo '<div class="timeline-detail-label">Release date</div>';
                                            // Convert 24-hour time to 12-hour format in release date comment
                                            $formatted_comment = $comment;
                                            if (preg_match('/(\d{4}-\d{2}-\d{2}\s+at\s+)(\d{2}:\d{2})/', $formatted_comment, $matches)) {
                                                $date_part = $matches[1];
                                                $time_part = $matches[2];
                                                $formatted_time = date('g:i A', strtotime($time_part));
                                                $formatted_comment = str_replace($matches[0], $date_part . $formatted_time, $formatted_comment);
                                            }
                                            echo '<div class="timeline-detail-content">' . nl2br(htmlspecialchars($formatted_comment)) . '</div>';
                                        }
                                        // Check if comment contains officer remarks
                                        elseif (strpos($comment, 'OFFICER REMARK') !== false || $action['action_type'] === 'officer_remark') {
                                            if (strpos($comment, 'OFFICER REMARK') !== false) {
                                                $parts = explode('OFFICER REMARK', $comment);
                                                $remarkPart = $parts[1] ?? '';
                                                echo '<div class="timeline-detail-label">COMMENT</div>';
                                                echo '<div class="timeline-detail-content">Officer remark:</div>';
                                                echo '<div class="timeline-detail-content">' . nl2br(htmlspecialchars(trim($remarkPart))) . '</div>';
                                                
                                                // Check for quantity adjustments
                                                if (strpos($comment, 'QUANTITY ADJUSTMENTS') !== false) {
                                                    $adjustmentParts = explode('QUANTITY ADJUSTMENTS', $comment);
                                                    $adjustments = $adjustmentParts[1] ?? '';
                                                    echo '<div class="timeline-detail-label" style="margin-top: 1rem;">--- Quantity Adjustments ---</div>';
                                                    echo '<div class="timeline-detail-content">';
                                                    
                                                    // Parse adjustments line by line and style ri_id part
                                                    $lines = explode("\n", trim($adjustments));
                                                    foreach ($lines as $line) {
                                                        $line = trim($line);
                                                        if (!empty($line) && strpos($line, ':') !== false) {
                                                            // Extract item name, ri_id, and quantity
                                                            preg_match('/^(.*?)\s*\(ri_id=\d+\)\s*:\s*(\d+)$/', $line, $matches);
                                                            if (count($matches) === 3) {
                                                                $itemName = htmlspecialchars($matches[1]);
                                                                $quantity = htmlspecialchars($matches[2]);
                                                                echo '<div class="quantity-item">';
                                                                echo '<span class="item-name">' . $itemName . '</span> ';
                                                                echo '<span class="quantity">: ' . $quantity . '</span>';
                                                                echo '</div>';
                                                            } else {
                                                                // Fallback: try to remove ri_id if present
                                                                $cleanLine = preg_replace('/\s*\(ri_id=\d+\)/', '', $line);
                                                                echo '<div class="quantity-item">' . htmlspecialchars($cleanLine) . '</div>';
                                                            }
                                                        }
                                                    }
                                                    echo '</div>';
                                                }
                                            } else {
                                                // This handles officer_remark actions where comment doesn't contain "OFFICER REMARK"
                                                echo '<div class="timeline-detail-label">COMMENT</div>';
                                                echo '<div class="timeline-detail-content">Officer remark:</div>';
                                                echo '<div class="timeline-detail-content">' . nl2br(htmlspecialchars($comment)) . '</div>';
                                            }
                                        }
                                        // Check for notes
                                        elseif (strpos($comment, 'NOTE') !== false) {
                                            echo '<div class="timeline-detail-label">NOTE</div>';
                                            echo '<div class="timeline-detail-content">' . nl2br(htmlspecialchars($comment)) . '</div>';
                                        }
                                        // General comment
                                        else {
                                            echo '<div class="timeline-detail-label">COMMENT</div>';
                                            echo '<div class="timeline-detail-content">' . nl2br(htmlspecialchars($comment)) . '</div>';
                                        }
                                        ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="text-center text-muted py-4">
                        <i class="bi bi-clock-history" style="font-size: 2rem;"></i>
                        <p class="mt-2">No action history available</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>

</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>

