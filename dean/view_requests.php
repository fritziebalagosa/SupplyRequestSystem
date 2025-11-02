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

// Determine badge class
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

$can_act = false;
$role = $_SESSION['role'] ?? '';
if ($role === 'dean' && $request['status'] === 'pending_dean') $can_act = true;
if ($role === 'head' && $request['status'] === 'pending_head') $can_act = true;
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

        .badge-pending { background-color: #fff3cd; color: #856404; border-color: #ffeaa7; }
        .badge-approved { background-color: #d4edda; color: #155724; border-color: #c3e6cb; }
        .badge-rejected { background-color: var(--red-light); color: #721c24; border-color: #f5c6cb; }
        .badge-completed { background-color: #d1ecf1; color: #0c5460; border-color: #bee5eb; }
        .badge-returned { background-color: #d1ecf1; color: #0c5460; border-color: #bee5eb; }
        .badge-forwarded { background-color: #d1ecf1; color: #0c5460; border-color: #bee5eb; }

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
            background-color: #ffe69c;
            border-color: #ffc107;
        }

        .btn-danger-minimal {
            background-color: var(--red-light);
            color: #721c24;
            border: 1px solid #f5c6cb;
        }

        .btn-danger-minimal:hover {
            background-color: #f1b0b7;
            border-color: var(--red-primary);
        }

        .action-buttons {
            display: flex;
            gap: 0.75rem;
            flex-wrap: wrap;
        }

        /* Alert */
        .alert-minimal {
            border-radius: 8px;
            padding: 1rem 1.25rem;
            margin-bottom: 1.5rem;
            border: 1px solid;
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .alert-secondary {
            background-color: var(--gray-200);
            color: var(--gray-700);
            border-color: var(--gray-300);
        }

        /* History Timeline */
        .history-list {
            list-style: none;
            padding: 0;
            margin: 0;
        }

        .history-item {
            padding: 1rem;
            border: 1px solid var(--gray-200);
            border-radius: 8px;
            margin-bottom: 0.75rem;
            background: white;
        }

        .history-item:last-child {
            margin-bottom: 0;
        }

        .history-header {
            display: flex;
            justify-content: space-between;
            align-items: start;
            margin-bottom: 0.5rem;
        }

        .history-action {
            font-weight: 600;
            color: var(--gray-900);
            text-transform: capitalize;
        }

        .history-user {
            color: var(--gray-700);
            font-size: 0.875rem;
        }

        .history-time {
            color: var(--gray-700);
            font-size: 0.8125rem;
        }

        .history-comment {
            margin-top: 0.5rem;
            padding: 0.75rem;
            background: var(--gray-50);
            border-radius: 6px;
            font-size: 0.9375rem;
            color: var(--gray-900);
        }

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

            .action-buttons {
                flex-direction: column;
            }

            .action-buttons button {
                width: 100%;
                justify-content: center;
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
                <div class="info-label">Requester</div>
                <div class="info-value"><?= htmlspecialchars($request['first_name'] . ' ' . $request['last_name']) ?></div>
            </div>
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
                <div class="info-label">Date Submitted</div>
                <div class="info-value"><?= htmlspecialchars(date('M d, Y g:i A', strtotime($request['created_at']))) ?></div>
            </div>
        </div>

        <!-- Requested Items -->
        <div class="info-card">
            <h5><i class="bi bi-box-seam"></i> Requested Items</h5>
            <?php if (empty($items)): ?>
                <p style="color: var(--gray-700); margin: 0;">No items attached to this request.</p>
            <?php else: ?>
                <table class="items-table">
                    <thead>
                        <tr>
                            <th>Item Name</th>
                            <th>Quantity</th>
                            <th>Unit</th>
                            <th>Priority</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($items as $it): ?>
                            <tr>
                                <td data-label="Item"><?= htmlspecialchars($it['item_name']) ?></td>
                                <td data-label="Quantity"><strong><?= htmlspecialchars($it['quantity']) ?></strong></td>
                                <td data-label="Unit"><?= htmlspecialchars($it['unit']) ?></td>
                                <td data-label="Priority"><?= htmlspecialchars(ucfirst($it['priority'])) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>

        <!-- Take Action -->
        <?php if ($can_act): ?>
        <div class="info-card">
            <h5><i class="bi bi-lightning"></i> Take Action</h5>
            <form method="POST" action="dean_requests.php" id="actionForm">
                <input type="hidden" name="request_id" value="<?= $request['id'] ?>">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                <div class="mb-3">
                    <label class="form-label-minimal">Comment (Optional, required when returning)</label>
                    <textarea name="comment" class="form-control form-control-minimal" rows="3" placeholder="Add your comment here..."></textarea>
                </div>
                <div class="action-buttons">
                    <button type="submit" name="action" value="approve" class="btn-minimal btn-success-minimal">
                        <i class="bi bi-check-circle"></i> Approve & Forward
                    </button>
                    <button type="submit" name="action" value="return" class="btn-minimal btn-warning-minimal" id="btnReturn">
                        <i class="bi bi-arrow-return-left"></i> Return with Comment
                    </button>
                    <button type="submit" name="action" value="reject" class="btn-minimal btn-danger-minimal">
                        <i class="bi bi-x-circle"></i> Reject Request
                    </button>
                </div>
            </form>
        </div>
        <?php else: ?>
        <div class="alert-minimal alert-secondary">
            <i class="bi bi-info-circle"></i>
            <span>No actions available for this request (current status: <?= htmlspecialchars($status_text) ?>)</span>
        </div>
        <?php endif; ?>

        <!-- Action History -->
        <div class="info-card">
            <h5><i class="bi bi-clock-history"></i> Action History</h5>
            <?php if (empty($history)): ?>
                <p style="color: var(--gray-700); margin: 0;">No actions recorded yet.</p>
            <?php else: ?>
                <ul class="history-list">
                    <?php foreach ($history as $h): ?>
                        <li class="history-item">
                            <div class="history-header">
                                <div>
                                    <div class="history-action"><?= htmlspecialchars(str_replace('_', ' ', $h['action_type'])) ?></div>
                                    <div class="history-user">by <?= htmlspecialchars($h['first_name'] . ' ' . $h['last_name']) ?> (<?= htmlspecialchars(ucfirst($h['role'])) ?>)</div>
                                </div>
                                <div class="history-time"><?= htmlspecialchars(date('M d, Y g:i A', strtotime($h['created_at']))) ?></div>
                            </div>
                            <?php if (!empty($h['comment'])): ?>
                                <div class="history-comment"><?= nl2br(htmlspecialchars($h['comment'])) ?></div>
                            <?php endif; ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function(){
            var btn = document.getElementById('btnReturn');
            if (btn) btn.addEventListener('click', function(e){
                var comment = document.querySelector('textarea[name="comment"]').value.trim();
                if (!comment) { 
                    e.preventDefault(); 
                    alert('Please provide a comment when returning a request.'); 
                }
            });
        });
    </script>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>