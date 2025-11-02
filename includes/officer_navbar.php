<?php
// Officer navbar include
if (session_status() === PHP_SESSION_NONE) session_start();

$current = basename($_SERVER['PHP_SELF']);
$base = '/SupplyRequestSystem/officer';
$name = trim(($_SESSION['first_name'] ?? '') . ' ' . ($_SESSION['last_name'] ?? ''));

// include DB and helper functions
$dbPath = rtrim($_SERVER['DOCUMENT_ROOT'], DIRECTORY_SEPARATOR) . '/SupplyRequestSystem/config/db.php';
$funcPath = rtrim($_SERVER['DOCUMENT_ROOT'], DIRECTORY_SEPARATOR) . '/SupplyRequestSystem/includes/functions.php';
if (file_exists($dbPath)) include_once $dbPath;
if (file_exists($funcPath)) include_once $funcPath;

$notif_count = 0;
$notifications = [];
if (!empty($_SESSION['user_id']) && isset($conn)) {
		$role = $_SESSION['role'] ?? '';
		$college_office_id = $_SESSION['college_office_id'] ?? null;
		if (function_exists('get_notifications')) {
				$notifications = get_notifications($conn, $_SESSION['user_id'], $role, $college_office_id, 6);
				$notif_count = count($notifications);
		}
}
?>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css">
<nav class="navbar navbar-expand-lg navbar-light bg-white border-bottom" style="min-height:64px;">
	<div class="container-fluid px-4">
		<div class="d-flex align-items-center">
			<a class="navbar-brand d-flex align-items-center gap-2" href="/SupplyRequestSystem/index.php">
				<i class="bi bi-building" style="font-size:1.25rem;color:#d33"></i>
				<span style="font-weight:700;color:#d33;">WMSU OSRS</span>
			</a>
		</div>

		<div class="collapse navbar-collapse" id="navbarLeft">
			<ul class="navbar-nav mb-2 mb-lg-0">
				<li class="nav-item px-3">
					<a class="nav-link d-flex align-items-center <?= ($current==='dashboard.php')? 'active':'' ?>" href="<?= $base ?>/dashboard.php"><i class="bi bi-speedometer2 me-2"></i>Dashboard</a>
				</li>
				<li class="nav-item px-3">
					<a class="nav-link d-flex align-items-center <?= ($current==='officer_requests.php' || $current==='view_request.php')? 'active':'' ?>" href="<?= $base ?>/officer_requests.php"><i class="bi bi-envelope me-2"></i>Requests</a>
				</li>
				<li class="nav-item px-3">
					<a class="nav-link d-flex align-items-center <?= ($current==='records.php')? 'active':'' ?>" href="<?= $base ?>/manage_inventory.php"><i class="bi bi-journal-text me-2"></i>Inventory</a>
				</li>
			</ul>
		</div>

		<div class="d-flex align-items-center gap-3">
			<div class="dropdown">
				<a class="position-relative text-decoration-none text-dark dropdown-toggle" href="#" role="button" id="notifMenu" data-bs-toggle="dropdown" aria-expanded="false">
					<i class="bi bi-bell" style="font-size:1.25rem;"></i>
					<?php if ($notif_count > 0): ?>
						<span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger" style="font-size:0.65rem;"><?= $notif_count ?></span>
					<?php endif; ?>
				</a>
				<ul class="dropdown-menu dropdown-menu-end" aria-labelledby="notifMenu" style="min-width:300px;">
					<?php if (empty($notifications)): ?>
						<li class="dropdown-item text-muted">No notifications</li>
					<?php else: ?>
						<?php foreach ($notifications as $n): ?>
							<li><a class="dropdown-item" href="<?= htmlspecialchars($n['link']) ?>"><?= htmlspecialchars($n['message']) ?><br><small class="text-muted"><?= htmlspecialchars($n['created_at']) ?></small></a></li>
						<?php endforeach; ?>
					<?php endif; ?>
				</ul>
			</div>

			<div class="dropdown">
				<a class="d-flex align-items-center text-dark text-decoration-none dropdown-toggle" href="#" role="button" id="userMenu" data-bs-toggle="dropdown" aria-expanded="false">
					<i class="bi bi-person-circle" style="font-size:1.4rem;margin-right:6px"></i>
					<strong><?= htmlspecialchars(trim($name ?: ($_SESSION['email'] ?? 'User'))) ?></strong>
				</a>
				<ul class="dropdown-menu dropdown-menu-end" aria-labelledby="userMenu">
					<li><a class="dropdown-item" href="/SupplyRequestSystem/officer/dashboard.php"><i class="bi bi-gear me-2"></i>Profile Settings</a></li>
					<li><hr class="dropdown-divider"></li>
					<li><a class="dropdown-item text-danger" href="/SupplyRequestSystem/auth/logout.php"><i class="bi bi-box-arrow-right me-2"></i>Logout</a></li>
				</ul>
			</div>
		</div>
	</div>
</nav>

