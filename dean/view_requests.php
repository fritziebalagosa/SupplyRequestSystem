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
            margin-bottom: 2rem;
        }

        .page-title {
            font-size: 1.75rem;
            font-weight: 600;
            color: var(--gray-900);
            letter-spacing: -0.5px;
            margin-bottom: 0.5rem;
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        .page-subtitle {
            color: var(--gray-700);
            font-size: 0.9375rem;
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

        .alert-info {
            background-color: #e7f5ff;
            color: #084298;
            border-left: 4px solid #4dabf7;
        }

        .alert-minimal i {
            font-size: 1.25rem;
        }

        /* Section Cards */
        .section-card {
            background: white;
            border-radius: 12px;
            border: 1px solid var(--gray-200);
            overflow: hidden;
            margin-bottom: 2rem;
        }

        .section-header {
            padding: 1.25rem 1.5rem;
            border-bottom: 1px solid var(--gray-200);
            background: white;
        }

        .section-header h5 {
            font-size: 1.125rem;
            font-weight: 600;
            color: var(--gray-900);
            margin: 0;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .section-body {
            padding: 1.5rem;
        }

        /* Info Cards (for backward compatibility) */
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
        }

        .form-control-minimal {
            border: 1px solid var(--gray-300);
            border-radius: 8px;
            padding: 0.625rem 0.875rem;
            font-size: 0.9375rem;
            transition: all 0.2s ease;
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

        .btn-warning-minimal {
            background-color: #fff3cd;
            color: #856404;
            border: 1px solid #ffeaa7;
        }

        .btn-warning-minimal:hover {
            background-color: #ffeaa7;
            border-color: #ffc107;
        }

        .btn-danger-minimal {
            background-color: var(--red-light);
            color: #721c24;
            border: 1px solid #f5c6cb;
        }

        .btn-danger-minimal:hover {
            background-color: #f5c6cb;
            border-color: #dc3545;
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
            padding-left: 2rem;
        }

        .timeline::before {
            content: '';
            position: absolute;
            left: 0.75rem;
            top: 0;
            height: 100%;
            width: 2px;
            background: var(--gray-300);
        }

        .timeline-item {
            position: relative;
            margin-bottom: 1.5rem;
        }

        .timeline-marker {
            position: absolute;
            left: -2rem;
            top: 0.25rem;
            width: 1rem;
            height: 1rem;
            border-radius: 50%;
            background: var(--gray-700);
            border: 2px solid white;
            box-shadow: 0 0 0 2px var(--gray-300);
        }

        .timeline-content {
            background: white;
            border: 1px solid var(--gray-200);
            border-radius: 8px;
            padding: 1rem;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
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
    <?php include('../includes/head_dean_navbar.php'); ?>

    <div class="container-main">
        <a href="dean_requests.php" class="back-button">
            <i class="bi bi-arrow-left"></i> Back to Requests
        </a>

        <div class="page-header">
            <h1 class="page-title">Request <span class="request-id">#<?= htmlspecialchars($request['request_id'] ?: $request['id']) ?></span></h1>
            <span class="badge-minimal badge-<?= $request['status'] === 'approved' ? 'approved' : ($request['status'] === 'rejected' ? 'rejected' : 'pending') ?>">
                <?php if ($request['status'] === 'approved'): ?>
                    <i class="bi bi-check-circle"></i>
                <?php elseif ($request['status'] === 'rejected'): ?>
                    <i class="bi bi-x-circle"></i>
                <?php else: ?>
                    <i class="bi bi-clock-history"></i>
                <?php endif; ?>
                <?= htmlspecialchars(ucfirst(str_replace('_', ' ', $request['status']))) ?>
            </span>
        </div>

        <!-- Request Details -->
        <div class="section-card">
            <div class="section-header">
                <h5><i class="bi bi-info-circle"></i> Request Details</h5>
            </div>
            <div class="section-body">
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
                    
                    // Since we're in dean/ directory, we need to go up one level to reach uploads/
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
            
            if ($receipt): ?>
                <div class="info-row">
                    <div class="info-label">Delivery Date</div>
                    <div class="info-value">
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
                    </div>
                </div>
                <div class="info-row">
                    <div class="info-label">Receipt Status</div>
                    <div class="info-value">
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
                    </div>
                </div>
            <?php else: ?>
                <div class="info-row">
                    <div class="info-label">Delivery Date</div>
                    <div class="info-value">
                        <?php if ($schedule && $schedule['release_date']): ?>
                            <span style="color: var(--gray-700); font-size: 0.9375rem;">
                                <i class="bi bi-calendar-check"></i> Scheduled for <?= htmlspecialchars(date('M d, Y', strtotime($schedule['release_date']))) ?>
                            </span>
                        <?php else: ?>
                            <span style="color: var(--gray-400); font-style: italic;">Not scheduled</span>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="info-row">
                    <div class="info-label">Receipt Status</div>
                    <div class="info-value">
                        <span class="badge-minimal badge-pending">
                            <i class="bi bi-clock-history"></i> Pending
                        </span>
                    </div>
                </div>
            <?php endif; ?>
            </div>
        </div>

        <!-- Items -->
        <div class="section-card">
            <div class="section-header">
                <h5><i class="bi bi-box-seam"></i> Items</h5>
            </div>
            <div class="section-body">
            <?php if (empty($items)): ?>
                <p>No items attached.</p>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="items-table">
                        <thead>
                            <tr>
                                <th data-label="Item">Item</th>
                                <th data-label="Quantity">Quantity</th>
                                <th data-label="Unit">Unit</th>
                                <th data-label="Priority">Priority</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($items as $it):
                                $reqQty = (int)$it['quantity'];
                                $approved = isset($it['approved_quantity']) && $it['approved_quantity'] !== null ? (int)$it['approved_quantity'] : null;
                                $effective = $approved !== null ? $approved : $reqQty;
                            ?>
                                <tr>
                                    <td data-label="Item"><?= htmlspecialchars($it['item_name']) ?></td>
                                    <td data-label="Quantity">
                                        <?= $effective ?>
                                        <?php if ($approved !== null && $approved !== $reqQty): ?>
                                            <div class="small text-muted">Requested: <?= $reqQty ?> — Adjusted: <?= $approved ?></div>
                                        <?php endif; ?>
                                    </td>
                                    <td data-label="Unit"><?= htmlspecialchars($it['unit']) ?></td>
                                    <td data-label="Priority"><?= htmlspecialchars($it['priority']) ?></td>
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
            <!-- Take Action -->
            <div class="section-card">
                <div class="section-header">
                    <h5><i class="bi bi-gear"></i> Take Action</h5>
                </div>
                <div class="section-body">
                <form method="POST" action="dean_requests.php" id="actionForm">
                    <input type="hidden" name="request_id" value="<?= $request['id'] ?>">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                    <div class="mb-3">
                        <label class="form-label-minimal">Comment (optional, required when returning)</label>
                        <textarea name="comment" class="form-control-minimal" rows="3" placeholder="Add your comments here..."></textarea>
                    </div>
                    <div class="form-actions">
                        <button type="submit" name="action" value="approve" class="btn-minimal btn-success-minimal">
                            <i class="bi bi-check-circle"></i> Approve & Forward to Supply Officer
                        </button>
                        <button type="submit" name="action" value="return" class="btn-minimal btn-warning-minimal" id="btnReturn">
                            <i class="bi bi-arrow-clockwise"></i> Return with Comment
                        </button>
                        <button type="submit" name="action" value="reject" class="btn-minimal btn-danger-minimal">
                            <i class="bi bi-x-circle"></i> Reject
                        </button>
                    </div>
                </form>
            </div>
        </div>
        <?php else: ?>
            <div class="section-card">
                <div class="section-body">
                    <div class="alert-minimal alert-info">
                        <i class="bi bi-info-circle-fill"></i>
                        <span>No actions available for this request (current status: <?= htmlspecialchars($request['status']) ?>).</span>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <!-- Action History -->
        <div class="section-card">
            <div class="section-header">
                <h5><i class="bi bi-clock-history"></i> Action History</h5>
            </div>
            <div class="section-body">
            <?php if (empty($history)): ?>
                <p>No actions recorded yet.</p>
            <?php else: ?>
                <div class="timeline">
                    <?php foreach ($history as $h): ?>
                        <div class="timeline-item">
                            <div class="timeline-marker"></div>
                            <div class="timeline-content">
                                <strong><?= htmlspecialchars(ucfirst($h['action_type'])) ?></strong> by <?= htmlspecialchars($h['first_name'] . ' ' . $h['last_name']) ?> (<?= htmlspecialchars($h['role']) ?>)
                                <div class="text-muted small"><?= htmlspecialchars(date('M d, Y g:i A', strtotime($h['created_at']))) ?></div>
                                <?php if (!empty($h['comment'])): ?>
                                    <div class="mt-2"><?= nl2br(htmlspecialchars($h['comment'])) ?></div>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
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
