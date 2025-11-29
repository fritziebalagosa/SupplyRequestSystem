<?php
session_start();
include('../config/db.php');

// Allow requesters to view records for their office as requested (this shows office-wide records)
if (!isset($_SESSION['user_id'])) {
    header('Location: ../auth/log_in.php');
    exit;
}

$user_id = $_SESSION['user_id'];

// determine college_office_id
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

if (!$college_office_id) die('Office not configured.');

// fetch only approved/completed requests for this office
$stmt = $conn->prepare("SELECT r.id, r.request_id, r.status, r.created_at, u.first_name, u.last_name,
                        GROUP_CONCAT(DISTINCT it.item_name SEPARATOR ', ') AS items
                        FROM requests r
                        JOIN users u ON r.requester_id = u.id
                        LEFT JOIN request_items ri ON ri.request_id = r.id
                        LEFT JOIN items it ON ri.item_id = it.id
                        WHERE r.college_office_id = ?
                        AND r.status IN ('approved', 'completed')
                        GROUP BY r.id
                        ORDER BY r.created_at DESC");
$stmt->bind_param('i', $college_office_id);
$stmt->execute();
$requests = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Office Records - WMSU OSRS</title>
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
            max-width: 1400px;
            margin: 0 auto;
            padding: 2rem 1.5rem;
        }

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

        .page-subtitle {
            color: var(--gray-700);
            font-size: 0.9375rem;
            margin-top: 0.5rem;
            margin-bottom: 0;
        }

        .section-card {
            background: white;
            border-radius: 12px;
            border: 1px solid var(--gray-200);
            overflow: hidden;
        }

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

        .request-id {
            font-family: 'Courier New', monospace;
            font-weight: 600;
            color: var(--red-primary);
            font-size: 0.875rem;
        }

        .items-list {
            color: var(--gray-700);
            max-width: 300px;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

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

        .badge-pending_dean,
        .badge-pending_head {
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
        }

        .btn-action-view {
            background-color: #d1ecf1;
            color: #0c5460;
            border-color: #bee5eb;
        }

        .btn-action-view:hover {
            background-color: #bee5eb;
            border-color: #17a2b8;
            color: #0c5460;
        }

        .empty-state {
            text-align: center;
            padding: 3rem 1.5rem;
            color: var(--gray-700);
        }

        .empty-state i {
            font-size: 3rem;
            color: var(--gray-300);
            margin-bottom: 1rem;
        }

        .empty-state p {
            margin: 0.5rem 0 0 0;
            font-size: 0.9375rem;
        }

        .requester-name {
            font-weight: 500;
            color: var(--gray-900);
        }

        @media (max-width: 768px) {
            .container-main {
                padding: 1.5rem 1rem;
            }

            .page-title {
                font-size: 1.5rem;
            }

            .table-minimal thead th,
            .table-minimal tbody td {
                padding: 0.875rem 0.75rem;
                font-size: 0.875rem;
            }

            .items-list {
                max-width: 150px;
            }

            .table-minimal thead {
                display: none;
            }

            .table-minimal tbody tr {
                display: block;
                margin-bottom: 1rem;
                border: 1px solid var(--gray-200);
                border-radius: 8px;
                overflow: hidden;
            }

            .table-minimal tbody td {
                display: flex;
                justify-content: space-between;
                align-items: center;
                padding: 0.75rem 1rem;
                border-bottom: 1px solid var(--gray-100);
            }

            .table-minimal tbody td:last-child {
                border-bottom: none;
            }

            .table-minimal tbody td::before {
                content: attr(data-label);
                font-weight: 600;
                color: var(--gray-700);
                font-size: 0.8125rem;
                text-transform: uppercase;
            }

            .items-list {
                max-width: none;
                text-align: right;
            }
        }
    </style>
</head>
<body>
    <?php include('../includes/requester_navbar.php'); ?>
    
    <div class="container-main">
        <div class="page-header">
            <div>
                <h1 class="page-title">Office Records</h1>
                <p class="page-subtitle">View all supply requests from your office</p>
            </div>
        </div>

        <div class="section-card">
            <div class="table-responsive">
                <table class="table table-minimal">
                    <thead>
                        <tr>
                            <th>Request ID</th>
                            <th>Items</th>
                            <th>Requester</th>
                            <th>Status</th>
                            <th>Date Submitted</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($requests)): ?>
                            <tr>
                                <td colspan="6">
                                    <div class="empty-state">
                                        <i class="bi bi-inbox"></i>
                                        <p>No requests have been submitted from your office yet.</p>
                                    </div>
                                </td>
                            </tr>
                        <?php else: foreach ($requests as $r): 
                            $status = strtolower($r['status']);
                            $badge_class = 'badge-pending';
                            
                            if (strpos($status, 'approved') !== false) {
                                $badge_class = 'badge-approved';
                            } elseif (strpos($status, 'rejected') !== false) {
                                $badge_class = 'badge-rejected';
                            } elseif (strpos($status, 'returned') !== false) {
                                $badge_class = 'badge-returned';
                            } elseif (strpos($status, 'forwarded') !== false) {
                                $badge_class = 'badge-forwarded';
                            } elseif (strpos($status, 'pending') !== false) {
                                $badge_class = 'badge-pending';
                            }
                            
                            $status_text = ucwords(str_replace('_', ' ', $r['status']));
                        ?>
                            <tr>
                                <td data-label="Request ID">
                                    <span class="request-id">#<?= htmlspecialchars($r['request_id'] ?: $r['id']) ?></span>
                                </td>
                                <td data-label="Items">
                                    <span class="items-list" title="<?= htmlspecialchars($r['items'] ?? '—') ?>">
                                        <?= htmlspecialchars($r['items'] ?? '—') ?>
                                    </span>
                                </td>
                                <td data-label="Requester">
                                    <span class="requester-name"><?= htmlspecialchars($r['first_name'] . ' ' . $r['last_name']) ?></span>
                                </td>
                                <td data-label="Status">
                                    <span class="badge-minimal <?= $badge_class ?>">
                                        <?php if (strpos($status, 'approved') !== false): ?>
                                            <i class="bi bi-check-circle"></i>
                                        <?php elseif (strpos($status, 'rejected') !== false): ?>
                                            <i class="bi bi-x-circle"></i>
                                        <?php elseif (strpos($status, 'returned') !== false): ?>
                                            <i class="bi bi-arrow-return-left"></i>
                                        <?php else: ?>
                                            <i class="bi bi-clock-history"></i>
                                        <?php endif; ?>
                                        <?= htmlspecialchars($status_text) ?>
                                    </span>
                                </td>
                                <td data-label="Date"><?= htmlspecialchars(date('M d, Y g:i A', strtotime($r['created_at']))) ?></td>
                                <td data-label="Actions">
                                    <a class="btn-minimal btn-action-view" href="view_request.php?id=<?= $r['id'] ?>">
                                        <i class="bi bi-eye"></i> View Details
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
