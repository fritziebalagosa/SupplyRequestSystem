<?php
session_start();
include('../config/db.php');

if (!isset($_SESSION['user_id'])) {
    header('Location: ../auth/log_in.php');
    exit;
}

$user_id = $_SESSION['user_id'];
$id = intval($_GET['id'] ?? 0);
if (!$id) die('Invalid request id');

// fetch request (no status filter — requester can always view their own requests)
$stmt = $conn->prepare("SELECT r.*, u.first_name, u.last_name FROM requests r JOIN users u ON r.requester_id = u.id WHERE r.id = ? AND r.requester_id = ? LIMIT 1");
$stmt->bind_param("ii", $id, $user_id);
$stmt->execute();
$request = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$request) die('Request not found or access denied');

// fetch items with approved quantities if they exist
$items_stmt = $conn->prepare("
    SELECT 
        ri.*,
        it.item_name,
        it.unit,
        COALESCE(ri.priority, 'Normal') as priority,
        ri.approved_quantity
    FROM request_items ri 
    JOIN items it ON ri.item_id = it.id 
    WHERE ri.request_id = ?");
$items_stmt->bind_param("i", $id);
$items_stmt->execute();
$items = $items_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$items_stmt->close();

// fetch action history for this request (used to show return comments)
$hist_stmt = $conn->prepare("SELECT ra.*, u.first_name, u.last_name FROM request_actions ra LEFT JOIN users u ON ra.action_by = u.id WHERE ra.request_id = ? ORDER BY ra.created_at DESC");
$hist_stmt->bind_param("i", $id);
$hist_stmt->execute();
$history = $hist_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$hist_stmt->close();

$message = '';
// Handle receipt submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'received') {
    if (!isset($_FILES['photo']) || $_FILES['photo']['error'] !== UPLOAD_ERR_OK) {
        $message = 'Please attach a photo of the released items.';
        $message_type = 'danger';
    } else {
        $target_dir = "../uploads/release_proofs/";
        if (!is_dir($target_dir)) mkdir($target_dir, 0777, true);
        $fname = time() . '_' . basename($_FILES['photo']['name']);
        $target_path = $target_dir . $fname;
        if (move_uploaded_file($_FILES['photo']['tmp_name'], $target_path)) {
            // insert release_proofs
            $rp = $conn->prepare("INSERT INTO release_proofs (request_id, photo, received_date, uploaded_at) VALUES (?, ?, ?, NOW())");
            $today = date('Y-m-d');
            $rp->bind_param("iss", $id, $target_path, $today);
            $rp->execute();
            $rp->close();

            // update request status to completed
            $u = $conn->prepare("UPDATE requests SET status = 'completed' WHERE id = ?");
            $u->bind_param("i", $id);
            $u->execute();
            $u->close();

            // insert action
            $ia = $conn->prepare("INSERT INTO request_actions (request_id, action_by, role, action_type, comment, created_at) VALUES (?, ?, ?, 'received', ?, NOW())");
            $role = $_SESSION['role'] ?? 'requester';
            $comment = trim($_POST['comment'] ?? '');
            $ia->bind_param("iiss", $id, $user_id, $role, $comment);
            $ia->execute();
            $ia->close();

            $message = 'Receipt submitted successfully. Admin will confirm and record the release.';
            $message_type = 'success';
            
            // Refresh request data
            $stmt = $conn->prepare("SELECT r.*, u.first_name, u.last_name FROM requests r JOIN users u ON r.requester_id = u.id WHERE r.id = ? AND r.requester_id = ? LIMIT 1");
            $stmt->bind_param("ii", $id, $user_id);
            $stmt->execute();
            $request = $stmt->get_result()->fetch_assoc();
            $stmt->close();
        } else {
            $message = 'Failed to save uploaded file.';
            $message_type = 'danger';
        }
    }
}

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
        .alert-minimal {
            border-radius: 8px;
            padding: 1rem 1.25rem;
            margin-bottom: 1.5rem;
            border: 1px solid;
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .alert-success {
            background-color: #d4edda;
            color: #155724;
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
    <?php include('../includes/requester_navbar.php'); ?>
    
    <div class="container-main">
        <a href="my_requests.php" class="back-button">
            <i class="bi bi-arrow-left"></i> Back to My Requests
        </a>

        <?php if ($message): ?>
            <div class="alert-minimal alert-<?= $message_type ?? 'info' ?>">
                <i class="bi bi-<?= $message_type === 'success' ? 'check-circle-fill' : 'exclamation-circle-fill' ?>"></i>
                <span><?= htmlspecialchars($message) ?></span>
            </div>
        <?php endif; ?>

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
                    <a href="<?= htmlspecialchars($request['attachment']) ?>" target="_blank" class="file-link">
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
        </div>

        <!-- Requested Items -->
        <div class="info-card">
            <h5><i class="bi bi-box-seam"></i> Requested Items</h5>
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
                // Check if any quantities were adjusted
                $hasAdjustments = false;
                foreach ($history as $h) {
                    if (strpos(($h['comment'] ?? ''), 'Adjustments:') !== false) {
                        $hasAdjustments = true;
                        break;
                    }
                }
                foreach ($items as $it): ?>
                    <tr>
                        <td data-label="Item"><?= htmlspecialchars($it['item_name']) ?></td>
                        <td data-label="Requested"><strong><?= (int)$it['quantity'] ?></strong></td>
                        <td data-label="Approved">
                            <?php 
                            if ($it['approved_quantity'] !== null) {
                                echo '<strong>' . (int)$it['approved_quantity'] . '</strong>';
                                if ($it['approved_quantity'] != $it['quantity']): ?>
                                    <span class="badge-minimal badge-warning" style="margin-left: 0.5rem;">
                                        <i class="bi bi-pencil-square"></i> Adjusted
                                    </span>
                                <?php endif;
                            } else {
                                echo '<span class="text-muted">Pending</span>';
                            } ?>
                        </td>
                        <td data-label="Unit"><?= htmlspecialchars($it['unit']) ?></td>
                        <td data-label="Priority"><?= htmlspecialchars(ucfirst($it['priority'])) ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if ($hasAdjustments): ?>
                <tr>
                    <td colspan="5">
                        <div class="alert-minimal alert-warning">
                            <i class="bi bi-info-circle"></i>
                            Some quantities have been adjusted by the Supply Officer. See history below for details.
                        </div>
                    </td>
                </tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>

        <?php
        // find latest return comment if any
        $last_return_comment = '';
        foreach ($history as $h) {
            if (($h['action_type'] ?? '') === 'returned') {
                $last_return_comment = $h['comment'] ?? '';
                break; // history already ordered desc
            }
        }
        ?>

        <!-- If returned, show edit/resubmit option -->
        <?php if (strpos($status, 'returned') !== false): ?>
        <div class="info-card">
            <h5><i class="bi bi-arrow-return-left"></i> Returned - Action Required</h5>
            <p style="color: var(--gray-700);">Your request was returned for clarification or changes. Please review the comment and update your request before resubmitting.</p>
            <?php if ($last_return_comment): ?>
                <div class="mb-3">
                    <label class="form-label-minimal">Comment from approver</label>
                    <div class="form-control-minimal" style="background:transparent;border:none;padding:0;"><?= nl2br(htmlspecialchars($last_return_comment)) ?></div>
                </div>
            <?php endif; ?>
            <div class="form-actions">
                <a href="edit_request.php?id=<?= $request['id'] ?>" class="btn-minimal btn-primary-minimal"><i class="bi bi-pencil"></i> Edit & Resubmit</a>
            </div>
        </div>
        <?php endif; ?>

        <!-- Confirm Receipt (only if approved) -->
        <?php if ($request['status'] === 'approved'): ?>
        <div class="info-card">
            <h5><i class="bi bi-check-square"></i> Confirm Receipt</h5>
            <p style="color: var(--gray-700); font-size: 0.9375rem; margin-bottom: 1rem;">
                Your request has been approved! Please confirm receipt by uploading a photo of the released items.
            </p>
            <form method="POST" enctype="multipart/form-data">
                <input type="hidden" name="action" value="received">
                <div class="mb-3">
                    <label class="form-label-minimal">Attach Photo of Released Items <span style="color: var(--red-primary);">*</span></label>
                    <input type="file" name="photo" accept="image/*" class="form-control form-control-minimal" required>
                </div>
                <div class="mb-3">
                    <label class="form-label-minimal">Comment (Optional)</label>
                    <textarea name="comment" class="form-control form-control-minimal" rows="3" placeholder="Add any additional notes or comments..."></textarea>
                </div>
                <div class="form-actions">
                    <button type="submit" class="btn-minimal btn-success-minimal">
                        <i class="bi bi-check-circle"></i> Mark as Received
                    </button>
                </div>
            </form>
        </div>
        <?php endif; ?>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>