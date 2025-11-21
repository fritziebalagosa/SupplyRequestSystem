<?php
session_start();
include('../config/db.php');
include('../includes/csrf.php');

// require dean/head role
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'] ?? '', ['dean','head'])) {
    header('Location: ../auth/log_in.php');
    exit;
}

$flash = $_SESSION['flash_message'] ?? '';
unset($_SESSION['flash_message']);

// Handle post actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'], $_POST['request_id'])) {
    $action = $_POST['action'];
    $request_id = $_POST['request_id'];
    $comment = $_POST['comment'] ?? '';
    $user_id = $_SESSION['user_id'];
    $college_office_id = $_SESSION['college_office_id'];
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
    if ($request && $request['status'] === 'pending_dean') {
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

$flash = $_SESSION['flash_message'] ?? '';
unset($_SESSION['flash_message']);

$college_office_id = $_SESSION['college_office_id'];
$user_id = $_SESSION['user_id'];

// fetch pending requests for this dean/head, include item names instead of request title
$stmt = $conn->prepare("SELECT r.id, r.request_id, r.status, r.created_at, u.first_name, u.last_name,
                        GROUP_CONCAT(DISTINCT it.item_name SEPARATOR ', ') AS items
                        FROM requests r
                        JOIN users u ON r.requester_id = u.id
                        LEFT JOIN request_items ri ON ri.request_id = r.id
                        LEFT JOIN items it ON ri.item_id = it.id
                        WHERE r.college_office_id = ? AND r.status = 'pending_dean'
                        GROUP BY r.id
                        ORDER BY r.created_at DESC");
if (!$stmt) {
    die('Failed to prepare statement: ' . $conn->error);
}
$stmt->bind_param("i", $college_office_id);
$stmt->execute();
$results = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();
// Also fetch approved requests to notify dean/head when scheduled for release
$stmt2 = $conn->prepare("SELECT r.id, r.request_id, r.status, r.created_at, u.first_name, u.last_name,
                        GROUP_CONCAT(DISTINCT it.item_name SEPARATOR ', ') AS items
                        FROM requests r
                        JOIN users u ON r.requester_id = u.id
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
	</style>
</head>

<body>
	<?php include('../includes/head_dean_navbar.php'); ?>

	<div class="container-main">
		<h1 class="page-title">Requests Awaiting Your Review</h1>
		<p class="page-subtitle">Review and manage requests for your college/office.</p>
		<?php if ($flash): ?>
			<div class="alert alert-info"><?= htmlspecialchars($flash) ?></div>
		<?php endif; ?>

		<div class="section-card">
			<div class="section-header">
				<h2>Pending Requests</h2>
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
									<th>Action</th>
								</tr>
							</thead>
							<tbody>
							<?php foreach ($results as $r): ?>
								<tr>
									<td><strong>#<?= htmlspecialchars($r['request_id'] ?: $r['id']) ?></strong></td>
									<td><?= htmlspecialchars($r['items'] ?? '—') ?></td>
									<td><?= htmlspecialchars($r['first_name'] . ' ' . $r['last_name']) ?></td>
									<td><?= htmlspecialchars(ucfirst($r['status'])) ?></td>
									<td><?= htmlspecialchars(date('M d, Y g:i A', strtotime($r['created_at']))) ?></td>
									<td>
										<a class="btn btn-sm btn-primary" href="view_requests.php?id=<?= $r['id'] ?>">View</a>
									</td>
								</tr>
							<?php endforeach; ?>
							</tbody>
						</table>
					</div>
				<?php endif; ?>
			</div>
		</div>
		
		<div class="section-card">
			<div class="section-header">
				<h2>Approved &amp; Ready for Release</h2>
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
									<th>Action</th>
								</tr>
							</thead>
							<tbody>
							<?php foreach ($approved as $r): ?>
								<tr>
									<td><strong>#<?= htmlspecialchars($r['request_id'] ?: $r['id']) ?></strong></td>
									<td><?= htmlspecialchars($r['items'] ?? '—') ?></td>
									<td><?= htmlspecialchars($r['first_name'] . ' ' . $r['last_name']) ?></td>
									<td><?= htmlspecialchars(date('M d, Y g:i A', strtotime($r['created_at']))) ?></td>
									<td><a class="btn btn-sm btn-primary" href="view_requests.php?id=<?= $r['id'] ?>">View</a></td>
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