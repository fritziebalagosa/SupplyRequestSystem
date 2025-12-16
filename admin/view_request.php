
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
$conn->query("CREATE TABLE IF NOT EXISTS release_schedule (request_id INT PRIMARY KEY, release_datetime DATETIME NOT NULL, created_at DATETIME DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
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
        $release_time = trim($_POST['release_time'] ?? '');
        
        if ($release_date === '' || $release_time === '') {
            $_SESSION['error'] = 'Please select both release date and time before approving.';
            header('Location: view_request.php?id=' . $request_id);
            exit;
        }
        
        // Store date and time separately
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
        
        // Send notifications to all relevant parties
        require_once('../includes/notifications.php');
        send_request_status_notification($conn, $request_id, 'approved', 'Request approved by admin', $user_id);

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
    
    // Check for low stock alerts after deduction
    check_and_send_low_stock_alerts($conn, $request_id);

        // Upsert release schedule with date and time (temporary fix)
        $conn->query("CREATE TABLE IF NOT EXISTS release_schedule (request_id INT PRIMARY KEY, release_date DATE NOT NULL, created_at DATETIME DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $ins = $conn->prepare("INSERT INTO release_schedule (request_id, release_date) VALUES (?,?) ON DUPLICATE KEY UPDATE release_date=VALUES(release_date)");
        $ins->bind_param('is', $request_id, $release_date);
        $ins->execute();
        $ins->close();

        // Log action
        $role = $_SESSION['role'];
        $comment = 'Release date: ' . $release_date . ' at ' . $release_time;
        $ia = $conn->prepare("INSERT INTO request_actions (request_id, action_by, role, action_type, comment, created_at) VALUES (?, ?, ?, 'approved', ?, NOW())");
        $ia->bind_param('iiss', $request_id, $user_id, $role, $comment);
        $ia->execute();
        $ia->close();

        // Send notifications to requester, dean, and head
        require_once('../includes/functions.php');
        send_approval_notifications($conn, $request_id, $release_date . ' ' . $release_time);

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
        
        // Send notifications to all relevant parties
        require_once('../includes/notifications.php');
        send_request_status_notification($conn, $request_id, 'rejected', $remarks, $user_id);

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
$can_act = in_array($request['status'], ['for_final_approval']);

// Fetch scheduled release date and time if any
$sched_date = null;
$sched_time = null;
$rs = $conn->prepare("SELECT release_date FROM release_schedule WHERE request_id=? LIMIT 1");
$rs->bind_param('i', $request_id);
$rs->execute();
$rsres = $rs->get_result()->fetch_assoc();
if ($rsres) { 
    $sched_date = $rsres['release_date'];
    // Temporarily set default time until column is added
    $sched_time = '09:00:00';
}
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
            max-width: 1000px;
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
            border-color: var(--gray-700);
            color: var(--gray-900);
        }

        /* Page Header */
        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 2rem;
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
            color: var(--red-primary);
        }

        /* Alert Messages */
        .alert {
            border-radius: 8px;
            border: none;
            box-shadow: 0 0.125rem 0.25rem rgba(0, 0, 0, 0.075);
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

        .alert-minimal i {
            font-size: 1.25rem;
        }

        /* Cards */
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

        /* Badges */
        .badge-minimal {
            display: inline-flex;
            align-items: center;
            padding: 0.35rem 0.75rem;
            border-radius: 6px;
            font-size: 0.8125rem;
            font-weight: 500;
            border: 1px solid;
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

        /* Items Table */
        .items-table {
            width: 100%;
            border-collapse: collapse;
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
            font-weight: 500;
            margin-bottom: 0.5rem;
            display: block;
        }

        .form-control-minimal {
            border: 1px solid var(--gray-300);
            border-radius: 8px;
            padding: 0.625rem 0.875rem;
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
            min-height: 80px;
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

        .btn-success-minimal {
            background-color: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }

        .btn-success-minimal:hover {
            background-color: #c3e6cb;
            border-color: #28a745;
        }

        .btn-primary-minimal {
            background-color: var(--red-primary);
            color: white;
            border: none;
        }

        .btn-primary-minimal:hover {
            background-color: var(--red-dark);
            transform: translateY(-1px);
        }

        .btn-secondary-minimal {
            background-color: #e2e3e5;
            color: #41464b;
            border: 1px solid #ced4da;
        }

        .btn-secondary-minimal:hover {
            background-color: #ced4da;
            border-color: #adb5bd;
            transform: translateY(-1px);
        }

        .btn-danger-minimal {
            background-color: var(--red-light);
            color: #721c24;
            border: 1px solid #f5c6cb;
        }

        .btn-danger-minimal:hover {
            background-color: #f5c6cb;
            border-color: #dc3545;
            transform: translateY(-1px);
        }

        .form-actions {
            display: flex;
            gap: 1rem;
            justify-content: flex-end;
            margin-top: 1.5rem;
            flex-wrap: wrap;
        }

        .form-actions .btn {
            min-width: 140px;
        }

        @media (max-width: 480px) {
            .form-actions .btn {
                flex: 1 1 100%;
                min-width: 0;
            }
        }

        /* Action Card */
        .action-card {
            background: linear-gradient(135deg, #fff5f5 0%, #ffffff 100%);
            border: 1px solid #ffeaa7;
            border-radius: 12px;
            padding: 1.25rem;
            margin-top: 1rem;
        }

        .action-card h5 {
            color: var(--red-primary);
            margin-bottom: 1rem;
        }

        .form-actions {
            text-align: right;
            margin-top: 1rem;
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
  <?php include('../includes/admin_sidebar.php'); ?>
<div class="container-main">
  <a href="manage_requests.php" class="back-button">
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
                    
                    // Since we're in admin/ directory, we need to go up one level to reach uploads/
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
            $receipt_stmt->bind_param("i", $request_id);
            $receipt_stmt->execute();
            $receipt = $receipt_stmt->get_result()->fetch_assoc();
            $receipt_stmt->close();
            
            // fetch release schedule
            $schedule_stmt = $conn->prepare("SELECT release_date FROM release_schedule WHERE request_id = ? LIMIT 1");
            $schedule_stmt->bind_param("i", $request_id);
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

        <?php
        // Only show action form to admin when request is for_final_approval
        $can_act = false;
        $role = $_SESSION['role'] ?? '';
        if ($request['status'] === 'for_final_approval') {
            $can_act = true;
        }
        ?>
        <?php if ($can_act): ?>
        <div class="info-card action-card">
            <h5><i class="bi bi-gear"></i> Available Actions</h5>
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                <div class="mb-3">
                    <label class="form-label-minimal">Release Date</label>
                    <input type="date" name="release_date" class="form-control-minimal" required>
                </div>
                <div class="mb-3">
                    <label class="form-label-minimal">Release Time</label>
                    <input type="time" name="release_time" class="form-control-minimal" required>
                </div>
                <div class="mb-3">
                    <label class="form-label-minimal">Comment (optional, required when rejecting)</label>
                    <textarea name="remarks" class="form-control-minimal" rows="3" placeholder="Enter your comments here..."></textarea>
                </div>
                <div class="form-actions">
                    <button type="submit" name="approve_request" class="btn btn-success"><i class="bi bi-check-circle"></i> Approve Request</button>
                    <button type="submit" name="reject_request" class="btn btn-danger"><i class="bi bi-x-circle"></i> Reject</button>
                </div>
            </form>
        </div>
        <?php else: ?>
        <div class="info-card">
            <h5><i class="bi bi-info-circle"></i> Request Status</h5>
            <div class="alert alert-info">No actions available for this request (current status: <?= htmlspecialchars($request['status']) ?>).</div>
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
                                            case 'receipt_confirmed':
                                                $actionText = 'Receipt Confirmed';
                                                $icon = 'bi-check-circle-fill';
                                                $color = 'success';
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
