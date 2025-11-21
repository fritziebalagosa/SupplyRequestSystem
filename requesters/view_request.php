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
        COALESCE(ri.priority, 'Normal') as priority
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
        // Check if request is approved and release date has passed
        $release_check = $conn->prepare("SELECT comment, created_at FROM request_actions 
                                        WHERE request_id = ? AND action_type = 'approved' 
                                        ORDER BY created_at DESC LIMIT 1");
        $release_check->bind_param("i", $id);
        $release_check->execute();
        $release_data = $release_check->get_result()->fetch_assoc();
        $release_check->close();

        if (!$release_data) {
            throw new Exception('This request has not been approved yet.');
        }

        // Set timezone to Asia/Manila for accurate time calculations
        date_default_timezone_set('Asia/Manila');
        
        // Create DateTime objects for time calculations
        $release_date = new DateTime($release_data['created_at'], new DateTimeZone('UTC'));
        $release_date->setTimezone(new DateTimeZone('Asia/Manila'));
        $now = new DateTime('now', new DateTimeZone('Asia/Manila'));
        
        // Format the release date for display
        $formatted_release_date = $release_date->format('F j, Y, g:i a');
        
        // This check is now handled client-side to show a modal instead of throwing an error

        // Start transaction
        $conn->begin_transaction();
        
        try {
            $target_dir = "../uploads/release_proofs/";
            if (!is_dir($target_dir)) mkdir($target_dir, 0777, true);
            
            // Generate unique filename
            $file_extension = pathinfo($_FILES['photo']['name'], PATHINFO_EXTENSION);
            $fname = uniqid() . '.' . $file_extension;
            $target_path = $target_dir . $fname;
            
            // Move uploaded file
            if (!move_uploaded_file($_FILES['photo']['tmp_name'], $target_path)) {
                throw new Exception('Failed to save uploaded file.');
            }
            
            // Insert release proof
            $rp = $conn->prepare("INSERT INTO release_proofs (request_id, user_id, image_path, notes, created_at) 
                                 VALUES (?, ?, ?, ?, NOW())");
            $notes = trim($_POST['notes'] ?? '');
            $rp->bind_param("iiss", $id, $_SESSION['user_id'], $target_path, $notes);
            if (!$rp->execute()) {
                throw new Exception('Failed to save receipt information: ' . $conn->error);
            }
            $rp->close();

            // Update request status to completed
            $u = $conn->prepare("UPDATE requests SET status = 'completed' WHERE id = ?");
            $u->bind_param("i", $id);
            if (!$u->execute()) {
                throw new Exception('Failed to update request status.');
            }
            $u->close();

            // Log the action
            $ia = $conn->prepare("INSERT INTO request_actions (request_id, action_by, role, action_type, comment, created_at) 
                                 VALUES (?, ?, ?, 'received', ?, NOW())");
            $role = $_SESSION['role'] ?? 'requester';
            $comment = "Items received on " . date('Y-m-d H:i:s');
            $ia->bind_param("iiss", $id, $user_id, $role, $comment);
            if (!$ia->execute()) {
                throw new Exception('Failed to log receipt action.');
            }
            $ia->close();
            
            // Commit transaction
            $conn->commit();
            
            // Send notifications to all relevant parties
            require_once('../includes/functions.php');
            send_approval_notifications($conn, $id, null, true); // true indicates this is a receipt notification
            
            // Set success message and redirect
            $_SESSION['success_message'] = 'Receipt submitted successfully. The request is now marked as completed.';
            header('Location: view_request.php?id=' . $id);
            exit();
            
        } catch (Exception $e) {
            // Rollback transaction on error
            $conn->rollback();
            $error_message = $e->getMessage();
            
            // Don't handle "Item Not Yet Available" errors - they should be caught by client-side validation
            if (strpos($error_message, 'Item Not Yet Available') === false) {
                $message = '
                <div class="alert alert-danger alert-dismissible fade show" role="alert">
                    <div class="d-flex align-items-center">
                        <i class="bi bi-exclamation-triangle-fill me-2" style="font-size: 1.25rem;"></i>
                        <div class="flex-grow-1">
                            <h5 class="mb-1">Submission Error</h5>
                            <p class="mb-0">' . htmlspecialchars($error_message) . '</p>
                        </div>
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                    </div>
                </div>';
                $message_type = 'danger';
                
                // Log the error for admin review
                error_log("Receipt submission error: " . $e->getMessage() . "\n" . $e->getTraceAsString());
            }
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
            <div class="alert alert-<?= $message_type ?? 'info' ?> alert-dismissible fade show" role="alert">
                <div class="d-flex align-items-center">
                    <i class="bi bi-<?= $message_type === 'success' ? 'check-circle-fill' : ($message_type === 'warning' ? 'exclamation-triangle-fill' : 'exclamation-circle-fill') ?> me-2" style="font-size: 1.25rem;"></i>
                    <div class="flex-grow-1">
                        <?= $message ?>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
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
                        <th>Status</th>
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
                            $approvedQty = $it['approved_quantity'] ?? null;
                            if (isset($approvedQty) && $approvedQty !== ''): ?>
                                <strong><?= (int)$approvedQty ?></strong>
                                <?php if ($approvedQty != $it['quantity']): ?>
                                    <span class="badge-minimal badge-warning" style="margin-left: 0.5rem;">
                                        <i class="bi bi-pencil-square"></i> Adjusted
                                    </span>
                                <?php endif; ?>
                            <?php else: ?>
                                <span class="text-muted">Pending</span>
                            <?php endif; ?>
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
        <?php if ($request['status'] === 'approved' || $request['status'] === 'completed'): ?>
        <div class="info-card">
            <h5><i class="bi bi-check-square"></i> Confirm Receipt</h5>
            <?php 
            // Get release date from request_actions
            $release_stmt = $conn->prepare("SELECT comment, created_at FROM request_actions WHERE request_id = ? AND action_type = 'approved' ORDER BY created_at DESC LIMIT 1");
            $release_stmt->bind_param("i", $id);
            $release_stmt->execute();
            $release_info = $release_stmt->get_result()->fetch_assoc();
            $release_date = '';
            if ($release_info && strpos($release_info['comment'], 'Release date: ') === 0) {
                $release_date = substr($release_info['comment'], 14);
            }
            $release_stmt->close();
            ?>
            
            <?php if ($release_date): ?>
            <div class="alert alert-info mb-3" style="background-color: #e7f5ff; border-left: 4px solid #4dabf7;">
                <i class="bi bi-calendar-check me-2"></i>
                <strong>Scheduled Release Date:</strong> <?php echo htmlspecialchars($release_date); ?>
            </div>
            <?php endif; ?>
            
            <p style="color: var(--gray-700); font-size: 0.9375rem; margin-bottom: 1rem;">
                Your request has been approved! Please confirm receipt by uploading a photo of the released items as proof of delivery.
            </p>
            
            <!-- Early Submission Modal -->
            <div class="modal fade" id="earlySubmissionModal" tabindex="-1" aria-labelledby="earlySubmissionModalLabel" aria-hidden="true">
                <div class="modal-dialog modal-dialog-centered">
                    <div class="modal-content">
                        <div class="modal-header" style="background-color: #f8d7da; border-bottom: 1px solid #f5c6cb;">
                            <h5 class="modal-title" id="earlySubmissionModalLabel" style="color: #721c24; font-weight: 600;">
                                <i class="bi bi-clock-fill me-2"></i>Receipt Submission Too Early
                            </h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                        </div>
                        <div class="modal-body" style="padding: 1.5rem;">
                            <div class="d-flex align-items-start">
                                <div class="flex-shrink-0 me-3">
                                    <i class="bi bi-exclamation-triangle-fill" style="font-size: 1.5rem; color: #dc3545;"></i>
                                </div>
                                <div class="flex-grow-1">
                                    <p style="margin-bottom: 1rem; color: #495057; line-height: 1.6;">You're trying to submit the receipt before the scheduled delivery time.</p>
                                    <p class="mb-0" id="releaseTimeMessage" style="color: #495057; line-height: 1.6;"></p>
                                </div>
                            </div>
                        </div>
                        <div class="modal-footer" style="background-color: #f8f9fa; border-top: 1px solid #dee2e6; padding: 1rem 1.5rem;">
                        </div>
                    </div>
                </div>
            </div>
            
            <form method="POST" enctype="multipart/form-data" class="mb-3" id="receiptForm" onsubmit="return validateForm(event)">
                <input type="hidden" name="action" value="received">
                <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
                <div class="mb-3">
                    <label for="photo" class="form-label">Upload Photo of Released Items <span class="text-danger">*</span></label>
                    <input type="file" class="form-control" id="photo" name="photo" accept="image/*" capture="camera" required>
                    <div class="form-text">Please take a clear photo of the received items as proof of delivery.</div>
                    <div id="photoPreview" class="mt-2" style="display: none;">
                        <img id="preview" src="#" alt="Preview" style="max-width: 200px; max-height: 200px; border: 1px solid #ddd; border-radius: 4px; padding: 5px;">
                    </div>
                </div>
                <div class="mb-3">
                    <label for="notes" class="form-label">Additional Notes (Optional)</label>
                    <textarea class="form-control" id="notes" name="notes" rows="2" placeholder="Any additional information about the received items"></textarea>
                </div>
                <div class="form-actions">
                    <button type="submit" class="btn-minimal btn-success-minimal"><i class="bi bi-check-circle"></i> Confirm Receipt</button>
                </div>
            </form>
        </div>
        <?php endif; ?>

        <!-- Action History -->
        <div class="info-card">
            <h5><i class="bi bi-clock-history"></i> Action History</h5>
            <div class="timeline" style="margin-top: 1.5rem;">
                <?php if (!empty($history)): ?>
                    <?php foreach ($history as $action): ?>
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
                                            default:
                                                $actionText = ucfirst($action['action_type']);
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
    </div>

    <style>
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
        #photoPreview img {
            max-width: 100%;
            height: auto;
            border-radius: 4px;
            margin-top: 10px;
        }
        .form-text {
            font-size: 0.8rem;
            color: #6c757d;
            margin-top: 0.25rem;
        }
    </style>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Store the release time from PHP to JavaScript
        const releaseTime = new Date('<?php 
            if (isset($release_date) && !empty($release_date)) {
                // Parse the release date from the comment (format: "Release date: November 22, 2025, 5:58 am")
                $datetime = DateTime::createFromFormat('F j, Y, g:i a', $release_date);
                if ($datetime) {
                    echo $datetime->format('Y-m-d H:i:s');
                } else {
                    echo date('Y-m-d H:i:s', strtotime('+1 day'));
                }
            } else {
                // Default to current time + 1 day if no release date is set
                echo date('Y-m-d H:i:s', strtotime('+1 day'));
            }
        ?>');
        
        function formatTimeRemaining(ms) {
            const seconds = Math.floor(ms / 1000);
            const minutes = Math.floor(seconds / 60);
            const hours = Math.floor(minutes / 60);
            const days = Math.floor(hours / 24);

            const timeParts = [];
            if (days > 0) timeParts.push(`${days} day${days > 1 ? 's' : ''}`);
            if (hours % 24 > 0) timeParts.push(`${hours % 24} hour${hours % 24 > 1 ? 's' : ''}`);
            if (days === 0 && hours === 0) {
                timeParts.push(`${minutes % 60} minute${minutes % 60 !== 1 ? 's' : ''}`);
            }

            return timeParts.join(' and ');
        }

        function showEarlySubmissionModal(releaseTime) {
            const now = new Date();
            const timeRemaining = formatTimeRemaining(releaseTime - now);
            const formattedReleaseTime = releaseTime.toLocaleString('en-US', {
                month: 'long',
                day: 'numeric',
                year: 'numeric',
                hour: 'numeric',
                minute: '2-digit',
                hour12: true
            });

            document.getElementById('releaseTimeMessage').innerHTML = 
                `The items will be available for <strong>delivery</strong> on <strong>${formattedReleaseTime}</strong>.<br><br>` +
                `Please check back in about <strong>${timeRemaining}</strong>.`;
            
            const modal = new bootstrap.Modal(document.getElementById('earlySubmissionModal'));
            modal.show();
            return false;
        }

        function validateForm(event) {
            event.preventDefault();
            
            const fileInput = document.getElementById('photo');
            
            // Check if file is selected
            if (!fileInput || !fileInput.files || !fileInput.files[0]) {
                alert('Please upload a photo of the received items.');
                return false;
            }
            
            // Check file type
            const fileType = fileInput.files[0].type;
            if (!fileType.startsWith('image/')) {
                alert('Please upload an image file (JPEG, PNG, etc.)');
                return false;
            }
            
            // Check file size (max 5MB)
            const fileSize = fileInput.files[0].size / 1024 / 1024; // in MB
            if (fileSize > 5) {
                alert('Image size should be less than 5MB');
                return false;
            }
            
            // Check if current time is before release time
            const now = new Date();
            if (releaseTime && now < releaseTime) {
                return showEarlySubmissionModal(releaseTime);
            }
            
            // If all validations pass, submit the form
            event.target.submit();
            return true;
        }

        // Initialize form submission handler
        document.addEventListener('DOMContentLoaded', function() {
            const form = document.getElementById('receiptForm');
            if (form) {
                form.onsubmit = validateForm;
            }
            
            // Preview image before upload
            document.getElementById('photo').addEventListener('change', function(e) {
                const file = e.target.files[0];
                if (file) {
                    const reader = new FileReader();
                    reader.onload = function(event) {
                        const preview = document.getElementById('preview');
                        if (preview) {
                            preview.src = event.target.result;
                            const previewContainer = document.getElementById('photoPreview');
                            if (previewContainer) {
                                previewContainer.style.display = 'block';
                            }
                        }
                    }
                    reader.readAsDataURL(file);
                }
            });
        });
    </script>
</body>
</html>