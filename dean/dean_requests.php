<?php
session_start();
include('../config/db.php');
include('../includes/csrf.php');

// require dean role only
if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'dean') {
    header('Location: ../auth/log_in.php');
    exit;
}

$flash = $_SESSION['flash_message'] ?? '';
unset($_SESSION['flash_message']);

// Dean users: display pending requests and completed (received) items
$college_office_id = $_SESSION['college_office_id'];
$user_id = $_SESSION['user_id'];
// statuses to display in the list (include completed so received items remain visible)
$display_statuses = ['pending_dean', 'pending_head', 'pending_officer', 'completed'];
// statuses that the dean may act on
$actionable_statuses = ['pending_dean', 'pending_head', 'pending_officer'];

// Handle post actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'], $_POST['request_id'])) {
    $action = $_POST['action'];
    $request_id = $_POST['request_id'];
    $comment = $_POST['comment'] ?? '';
    $message = '';

    // verify CSRF token
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $_SESSION['flash_message'] = 'Invalid security token. Please try again.';
        header('Location: dean_requests.php');
        exit;
    }

    // verify request exists and belongs to this office
    $v = $conn->prepare("SELECT status FROM requests WHERE id = ? AND college_office_id = ?");
    $v->bind_param("ii", $request_id, $college_office_id);
    $v->execute();
    $result = $v->get_result();
    $request = $result->fetch_assoc();
    $request_db_id = $request_id;

	$may_act = false;
	if ($request && in_array($request['status'], $actionable_statuses)) {
        $may_act = true;
    }
    if (!$may_act) {
        $message = 'This request cannot be acted on in its current state.';
        // close and skip update
        $v->close();
        $_SESSION['flash_message'] = $message;
        header('Location: dean_requests.php');
        exit;
    }
    // determine update values
    if ($action === 'approve') {
            // If current user is a dean and there is a head for this office, forward to head first
            $new_status = 'pending_officer';
            $action_type = 'approved';
            if (isset($_SESSION['role']) && $_SESSION['role'] === 'dean') {
                $hstmt = $conn->prepare("SELECT id FROM users WHERE role='head' AND college_office_id = ? AND status='active' LIMIT 1");
                $hstmt->bind_param('i', $college_office_id);
                $hstmt->execute();
                $hres = $hstmt->get_result();
                $has_head = ($hres && $hres->num_rows > 0);
                $hstmt->close();
                if ($has_head) {
                    $new_status = 'pending_head';
                    $action_type = 'forwarded_to_head';
                }
            }
        } elseif ($action === 'reject') {
            $new_status = 'rejected';
            $action_type = 'rejected';
        } else { // return
            $new_status = 'returned';
            $action_type = 'returned';
        }

        // update requests table
        $u = $conn->prepare("UPDATE requests SET status = ? WHERE id = ?");
        $u->bind_param("si", $new_status, $request_db_id);
        if (!$u->execute()) {
            $message = 'Failed to update request status: ' . htmlspecialchars($u->error);
        } else {
            // insert into request_actions
            $ia = $conn->prepare("INSERT INTO request_actions (request_id, action_by, role, action_type, comment, created_at) VALUES (?, ?, ?, ?, ?, NOW())");
            $role = $_SESSION['role'];
            $ia->bind_param("iisss", $request_db_id, $user_id, $role, $action_type, $comment);
            $ia->execute();
            $ia->close();

            $message = 'Action performed successfully.';
        }
        $u->close();
    
    $v->close();
    
    // redirect back to avoid resubmission
    $_SESSION['flash_message'] = $message;
    header('Location: dean_requests.php');
    exit;
}

// fetch pending requests for this dean (actionable statuses only)
$status_to_fetch = $actionable_statuses;
$status_placeholders = str_repeat('?,', count($status_to_fetch) - 1) . '?';
$sql = "SELECT r.id, r.request_id, r.status, r.created_at, u.first_name, u.last_name,
                        rs.release_date, rp.created_at as receipt_date,
                        COALESCE(GROUP_CONCAT(DISTINCT it.item_name SEPARATOR ', '), 'No items specified') AS items
                        FROM requests r
                        LEFT JOIN users u ON r.requester_id = u.id
                        LEFT JOIN release_schedule rs ON rs.request_id = r.id
                        LEFT JOIN release_proofs rp ON rp.request_id = r.id
                        LEFT JOIN request_items ri ON ri.request_id = r.id
                        LEFT JOIN items it ON ri.item_id = it.id
						WHERE r.college_office_id = ? AND r.status IN ($status_placeholders)
                        GROUP BY r.id
                        ORDER BY r.created_at DESC";
$stmt = $conn->prepare($sql);
$param_types = 'i' . str_repeat('s', count($status_to_fetch)); // college_office_id is int, statuses are strings
$stmt->bind_param($param_types, $college_office_id, ...$status_to_fetch);
$stmt->execute();
$results = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Also fetch approved requests to notify dean when scheduled for release
$stmt2 = $conn->prepare("SELECT r.id, r.request_id, r.status, r.created_at, u.first_name, u.last_name,
                        rs.release_date, rp.created_at as receipt_date,
                        COALESCE(GROUP_CONCAT(DISTINCT it.item_name SEPARATOR ', '), 'No items specified') AS items
                        FROM requests r
                        LEFT JOIN users u ON r.requester_id = u.id
                        LEFT JOIN release_schedule rs ON rs.request_id = r.id
                        LEFT JOIN release_proofs rp ON rp.request_id = r.id
                        LEFT JOIN request_items ri ON ri.request_id = r.id
                        LEFT JOIN items it ON ri.item_id = it.id
                        WHERE r.college_office_id = ? AND r.status = 'approved'
                        GROUP BY r.id
                        ORDER BY r.created_at DESC");
$stmt2->bind_param("i", $college_office_id);
$stmt2->execute();
$approved = $stmt2->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt2->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
	<meta charset="UTF-8">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<title>Requests - WMSU OSRS</title>
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

		.alert-info {
			background-color: #d1ecf1;
			color: #0c5460;
			border-color: #bee5eb;
		}

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
			padding: 0;
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

		/* Status Badges */
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

		.request-id {
			font-family: 'Courier New', monospace;
			font-weight: 600;
			color: var(--red-primary);
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

		.btn-primary-minimal {
			background-color: var(--red-primary);
			color: white;
			border: none;
			padding: 0.625rem 1.25rem;
			font-size: 0.9375rem;
		}

		.btn-primary-minimal:hover {
			background-color: var(--red-dark);
			transform: translateY(-1px);
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

		/* Responsive */
		@media (max-width: 768px) {
			.container-main {
				padding: 1.5rem 1rem;
			}

			.page-title {
				font-size: 1.5rem;
			}

			.table-minimal thead {
				display: none;
			}

			.table-minimal tbody tr {
				display: block;
				margin-bottom: 1rem;
				border: 1px solid var(--gray-200);
				border-radius: 8px;
			}

			.table-minimal tbody td {
				display: flex;
				justify-content: space-between;
				padding: 0.75rem 1rem;
				border: none;
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
		}
	</style>
</head>
<body>
	<?php include('../includes/head_dean_navbar.php'); ?>

	<div class="container-main">

		<h1 class="page-title">Requests Awaiting Your Review</h1>
		<p class="page-subtitle">Review and manage requests for your college/office.</p>
		<?php if ($flash): ?>
			<div class="alert alert-info alert-dismissible fade show" role="alert">
				<div class="d-flex align-items-center">
					<i class="bi bi-info-circle-fill me-2" style="font-size: 1.25rem;"></i>
					<div class="flex-grow-1">
						<?= htmlspecialchars($flash) ?>
					</div>
					<button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
				</div>
			</div>
		<?php endif; ?>

		<!-- Pending Requests -->
		<div class="section-card">
			<div class="section-header">
				<h5><i class="bi bi-clock-history"></i> Pending Requests</h5>
			</div>
			<div class="section-body">
			<?php if (empty($results)): ?>
				<div class="empty-state">
					<i class="bi bi-inbox"></i>
					<p>No requests pending your review.</p>
				</div>
			<?php else: ?>
				<div class="table-responsive">
					<table class="table table-minimal">
						<thead>
							<tr>
								<th>Request ID</th>
								<th>Items</th>
								<th>Requester</th>
								<th>Status</th>
								<th>Date</th>
								<th>Delivery Date</th>
								<th>Receipt Status</th>
								<th>Actions</th>
							</tr>
						</thead>
						<tbody>
						<?php foreach ($results as $r): ?>
							<tr>
								<td><span class="request-id"><?= htmlspecialchars($r['request_id'] ?: $r['id']) ?></span></td>
								<td><?= htmlspecialchars($r['items'] ?? '—') ?></td>
								<td><?= htmlspecialchars($r['first_name'] . ' ' . $r['last_name']) ?></td>
								<td><span class="badge-minimal badge-pending"><?= htmlspecialchars(ucfirst(str_replace('_', ' ', $r['status']))) ?></span></td>
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
								<td><?= $r['receipt_date'] ? htmlspecialchars(date('M d, Y g:i A', strtotime($r['receipt_date']))) : '<span class="badge-minimal badge-pending">Pending</span>' ?></td>
								<td>
									<a class="btn-minimal btn-action-view" href="view_requests.php?id=<?= $r['id'] ?>">
										<i class="bi bi-eye"></i> View
									</a>
								</td>
							</tr>
						<?php endforeach; ?>
						</tbody>
					</table>
				</div>
			<?php endif; ?>
			</div>
		</div>

		<!-- Approved Requests -->
		<div class="section-card">
			<div class="section-header">
				<h5><i class="bi bi-check-circle"></i> Approved &amp; Ready for Release</h5>
			</div>
			<div class="section-body">
			<?php if (empty($approved)): ?>
				<div class="empty-state">
					<i class="bi bi-inbox"></i>
					<p>No approved requests ready for release.</p>
				</div>
			<?php else: ?>
				<div class="table-responsive">
					<table class="table table-minimal">
						<thead>
							<tr>
								<th>Request ID</th>
								<th>Items</th>
								<th>Requester</th>
								<th>Date</th>
								<th>Delivery Date</th>
								<th>Receipt Status</th>
								<th>Actions</th>
							</tr>
						</thead>
						<tbody>
						<?php foreach ($approved as $r): ?>
							<tr>
								<td><span class="request-id"><?= htmlspecialchars($r['request_id'] ?: $r['id']) ?></span></td>
								<td><?= htmlspecialchars($r['items'] ?? '—') ?></td>
								<td><?= htmlspecialchars($r['first_name'] . ' ' . $r['last_name']) ?></td>
								<td><?= htmlspecialchars(date('M d, Y g:i A', strtotime($r['created_at']))) ?></td>
								<td><?= $r['release_date'] ? htmlspecialchars(date('M d, Y g:i A', strtotime($r['release_date']))) : '<span class="badge-minimal badge-pending">No Schedule</span>' ?></td>
								<td><?= $r['receipt_date'] ? htmlspecialchars(date('M d, Y g:i A', strtotime($r['receipt_date']))) : '<span class="badge-minimal badge-pending">Pending</span>' ?></td>
								<td>
									<a class="btn-minimal btn-action-view" href="view_requests.php?id=<?= $r['id'] ?>">
										<i class="bi bi-eye"></i> View
									</a>
								</td>
							</tr>
						<?php endforeach; ?>
						</tbody>
					</table>
				</div>
			<?php endif; ?>
			</div>
		</div>
	</div>
	
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>