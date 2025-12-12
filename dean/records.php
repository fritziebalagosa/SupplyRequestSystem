<?php
session_start();
include('../config/db.php');

// allow dean and head to view records (they use shared navbar)
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'] ?? '', ['dean','head'])) {
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

// Handle search functionality
$search = $_GET['search'] ?? '';
$search_where = '';
$search_params = [];
if (!empty($search)) {
    $search_where = " AND (r.id LIKE ? OR r.request_id LIKE ? OR u.first_name LIKE ? OR u.last_name LIKE ? OR it.item_name LIKE ? OR r.status LIKE ?)";
    $search_term = '%' . $search . '%';
    $search_params = array_fill(0, 6, $search_term);
}

// fetch only approved requests for this office
$stmt = $conn->prepare("SELECT r.id, r.request_id, r.status, r.created_at, u.first_name, u.last_name,
                        GROUP_CONCAT(DISTINCT it.item_name SEPARATOR ', ') AS items,
                        cu.id as creator_id, cu.first_name as creator_fn, cu.last_name as creator_ln, cu.role as creator_role,
                        rp.created_at as receipt_date,
                        rs.release_date
                        FROM requests r
                        JOIN users u ON r.requester_id = u.id
                        LEFT JOIN request_items ri ON ri.request_id = r.id
                        LEFT JOIN items it ON ri.item_id = it.id
                        LEFT JOIN users cu ON u.created_by = cu.id
                        LEFT JOIN release_proofs rp ON rp.request_id = r.id
                        LEFT JOIN release_schedule rs ON rs.request_id = r.id
                        WHERE r.college_office_id = ? AND (r.status = 'approved' OR r.status = 'completed')$search_where
                        GROUP BY r.id
                        ORDER BY r.created_at DESC");
$stmt->bind_param('i', $college_office_id);
if (!empty($search)) {
    $stmt->bind_param('issssss', $college_office_id, ...$search_params);
} else {
    $stmt->bind_param('i', $college_office_id);
}
$stmt->execute();
$requests = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Records - WMSU OSRS</title>
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
            max-width: 1400px;
            margin: 0 auto;
            padding: 2rem 1.5rem;
        }

        .page-title {
            font-size: 1.75rem;
            font-weight: 600;
            color: var(--gray-900);
            letter-spacing: -0.5px;
            margin-bottom: 0.25rem;
        }

        .page-subtitle {
            color: var(--gray-700);
            font-size: 0.9375rem;
            margin-bottom: 2rem;
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

        .section-header h2 {
            font-size: 1.125rem;
            font-weight: 600;
            color: var(--gray-900);
            margin: 0;
        }

        .section-body {
            padding: 0;
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

        /* Request ID styling */
        .request-id {
            font-family: 'Courier New', monospace;
            font-weight: 600;
            color: var(--red-primary);
            font-size: 0.875rem;
        }

        .items-list {
            color: var(--gray-700);
            max-width: 250px;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .creator-info {
            font-size: 0.875rem;
        }

        .creator-role {
            color: var(--gray-700);
            font-style: italic;
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

        .badge-pending {
            background-color: #fff3cd;
            color: #856404;
            border-color: #ffeaa7;
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

        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 2rem 1.5rem;
            color: var(--gray-700);
        }

        .empty-state i {
            font-size: 2.5rem;
            color: var(--gray-300);
            margin-bottom: 0.75rem;
        }

        .empty-state p {
            margin: 0;
            font-size: 0.9375rem;
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

            .items-list {
                max-width: 150px;
            }
        }
    </style>
</head>
<body>
    <?php include('../includes/head_dean_navbar.php'); ?>
    <div class="container-main">
        <h1 class="page-title">All Records</h1>
        <p class="page-subtitle">Complete history of all requests for your office, regardless of status</p>

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

        <div class="section-card">
            <div class="section-body">
            <div class="table-responsive">
                <?php if (empty($requests)): ?>
                    <div class="empty-state">
                        <i class="bi bi-inbox"></i>
                        <p>No requests found for your office.</p>
                    </div>
                <?php else: ?>
                    <table class="table table-minimal">
                        <thead>
                            <tr>
                                <th>Request ID</th>
                                <th>Items</th>
                                <th>Requester</th>
                                <th>Created By</th>
                                <th>Status</th>
                                <th>Date Submitted</th>
                                <th>Delivery Date</th>
                                <th>Receipt Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($requests as $r):
                                $status = strtolower($r['status']);
                                $badge_class = 'badge-pending';
                                if (strpos($status,'approved')!==false) $badge_class='badge-approved';
                                elseif (strpos($status,'rejected')!==false) $badge_class='badge-rejected';
                                elseif (strpos($status,'completed')!==false) $badge_class='badge-completed';
                                elseif (strpos($status,'returned')!==false) $badge_class='badge-returned';
                                elseif (strpos($status,'forwarded')!==false) $badge_class='badge-forwarded';
                                $status_text = ucwords(str_replace('_',' ',$r['status']));
                            ?>
                            <tr>
                                <td><span class="request-id">#<?= htmlspecialchars($r['request_id'] ?: $r['id']) ?></span></td>
                                <td><span class="items-list" title="<?= htmlspecialchars($r['items'] ?? '—') ?>"><?= htmlspecialchars($r['items'] ?? '—') ?></span></td>
                                <td><?= htmlspecialchars($r['first_name'].' '.$r['last_name']) ?></td>
                                <td>
                                    <?php if ($r['creator_fn']): ?>
                                        <div class="creator-info">
                                            <div><?= htmlspecialchars($r['creator_fn'].' '.$r['creator_ln']) ?></div>
                                            <div class="creator-role"><?= htmlspecialchars(ucfirst($r['creator_role'])) ?></div>
                                        </div>
                                    <?php else: ?>
                                        <span style="color:#616161;">—</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="badge-minimal <?= $badge_class ?>">
                                        <?php if(strpos($status,'approved')!==false): ?>
                                            <i class="bi bi-check-circle"></i>
                                        <?php elseif(strpos($status,'rejected')!==false): ?>
                                            <i class="bi bi-x-circle"></i>
                                        <?php elseif(strpos($status,'completed')!==false): ?>
                                            <i class="bi bi-check-circle-fill"></i>
                                        <?php elseif(strpos($status,'returned')!==false): ?>
                                            <i class="bi bi-arrow-return-left"></i>
                                        <?php else: ?>
                                            <i class="bi bi-clock-history"></i>
                                        <?php endif; ?>
                                        <?= htmlspecialchars($status_text) ?>
                                    </span>
                                </td>
                                <td><?= htmlspecialchars(date('M d, Y g:i A', strtotime($r['created_at']))) ?></td>
                                <td>
                                    <?php if ($r['release_date']): ?>
                                        <span style="color: var(--gray-700); font-size: 0.875rem;">
                                            <i class="bi bi-calendar-check"></i> <?= htmlspecialchars(date('M d, Y', strtotime($r['release_date']))) ?>
                                        </span>
                                    <?php else: ?>
                                        <span style="color: var(--gray-400); font-style: italic;">Not scheduled</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($r['receipt_date']): ?>
                                        <span class="badge-minimal badge-completed">
                                            <i class="bi bi-check-circle-fill"></i> Received
                                        </span>
                                    <?php else: ?>
                                        <span class="badge-minimal badge-pending">
                                            <i class="bi bi-clock-history"></i> Pending
                                        </span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <a class="btn-minimal btn-action-view" href="view_requests.php?id=<?= $r['id'] ?>">
                                        <i class="bi bi-eye"></i> View
                                    </a>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
