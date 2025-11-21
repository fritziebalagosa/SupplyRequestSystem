<?php
// Head/Dean navbar include (shared)
if (session_status() === PHP_SESSION_NONE) session_start();

$current = basename($_SERVER['PHP_SELF']);
$role = $_SESSION['role'] ?? '';
$base = ($role === 'head') ? '/SupplyRequestSystem/head' : '/SupplyRequestSystem/dean';
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

<style>
:root {
    --red-primary: #dc3545;
    --red-dark: #c82333;
    --red-darker: #bd2130;
}

/* Navbar styles */
.navbar-custom {
    background-color: white !important;
    border-bottom: 1px solid #e0e0e0;
    padding: 0.75rem 0;
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.05);
    min-height: 64px;
}

.navbar-custom .navbar-brand {
    color: var(--red-primary) !important;
    font-weight: 600;
    font-size: 1.25rem;
    letter-spacing: -0.5px;
    transition: color 0.2s ease;
    display: flex;
    align-items: center;
    gap: 0.5rem;
}

.navbar-custom .navbar-brand:hover {
    color: var(--red-dark) !important;
}

.navbar-custom .navbar-brand i {
    font-size: 1.25rem;
}

.navbar-custom .navbar-toggler {
    border: 1px solid #e0e0e0;
    padding: 0.4rem 0.6rem;
}

.navbar-custom .navbar-toggler:focus {
    box-shadow: 0 0 0 0.2rem rgba(220, 53, 69, 0.15);
}

.navbar-custom .navbar-toggler-icon {
    background-image: url("data:image/svg+xml,%3csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 30 30'%3e%3cpath stroke='%23dc3545' stroke-linecap='round' stroke-miterlimit='10' stroke-width='2' d='M4 7h22M4 15h22M4 23h22'/%3e%3c/svg%3e");
}

.navbar-custom .nav-link {
    color: #616161 !important;
    font-weight: 500;
    font-size: 0.9375rem;
    padding: 0.5rem 1rem !important;
    transition: all 0.2s ease;
    border-radius: 6px;
    margin: 0 0.125rem;
    display: flex;
    align-items: center;
}

.navbar-custom .nav-link:hover {
    color: var(--red-primary) !important;
    background-color: #fafafa;
}

.navbar-custom .nav-link.active {
    color: var(--red-primary) !important;
    background-color: #fff5f5;
}

.navbar-custom .nav-link i {
    font-size: 1rem;
    margin-right: 0.4rem;
}

.notification-badge {
    position: absolute;
    top: 0;
    right: 0;
    transform: translate(25%, -25%);
    font-size: 0.65rem !important;
    padding: 0.25em 0.45em;
}

.user-menu {
    display: flex;
    align-items: center;
    gap: 0.25rem;
    color: #333;
    text-decoration: none;
}

.user-menu:hover {
    color: var(--red-primary);
}

.user-avatar {
    width: 32px;
    height: 32px;
    border-radius: 50%;
    background-color: #f0f0f0;
    display: flex;
    align-items: center;
    justify-content: center;
    color: #666;
    font-size: 1.1rem;
}
</style>

<nav class="navbar navbar-expand-lg navbar-custom">
    <div class="container-fluid px-4">
        <a class="navbar-brand" href="/SupplyRequestSystem/index.php">
            <i class="bi bi-building"></i> WMSU OSRS
        </a>
        
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarContent" aria-controls="navbarContent" aria-expanded="false" aria-label="Toggle navigation">
            <span class="navbar-toggler-icon"></span>
        </button>

        <div class="collapse navbar-collapse" id="navbarContent">
            <ul class="navbar-nav me-auto mb-2 mb-lg-0">
                <li class="nav-item">
                    <a class="nav-link <?= ($current === 'dashboard.php') ? 'active' : '' ?>" href="<?= $base ?>/dashboard.php">
                        <i class="bi bi-speedometer2"></i> Dashboard
                    </a>
                </li>
                <li class="nav-item">
                    <?php if ($role === 'head'): ?>
                        <a class="nav-link <?= ($current === 'head_requests.php' || $current === 'view_request.php') ? 'active' : '' ?>" href="<?= $base ?>/head_requests.php">
                            <i class="bi bi-envelope"></i> Requests
                        </a>
                    <?php else: ?>
                        <a class="nav-link <?= ($current === 'dean_requests.php' || $current === 'view_requests.php') ? 'active' : '' ?>" href="<?= $base ?>/dean_requests.php">
                            <i class="bi bi-envelope"></i> Requests
                        </a>
                    <?php endif; ?>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?= ($current === 'records.php') ? 'active' : '' ?>" href="<?= $base ?>/records.php">
                        <i class="bi bi-journal-text"></i> Records
                    </a>
                </li>
            </ul>

            <div class="d-flex align-items-center gap-3">
                <div class="dropdown">
                    <a href="#" class="position-relative text-decoration-none text-dark" role="button" id="notificationDropdown" data-bs-toggle="dropdown" aria-expanded="false">
                        <i class="bi bi-bell" style="font-size: 1.25rem;"></i>
                        <?php if ($notif_count > 0): ?>
                            <span class="badge bg-danger notification-badge"><?= $notif_count ?></span>
                        <?php endif; ?>
                    </a>
                    <ul class="dropdown-menu dropdown-menu-end" aria-labelledby="notificationDropdown" style="min-width: 300px;">
                        <li><h6 class="dropdown-header">Notifications</h6></li>
                        <?php if (empty($notifications)): ?>
                            <li class="px-3 py-2 text-muted">No new notifications</li>
                        <?php else: ?>
                            <?php foreach ($notifications as $n): ?>
                                <li>
                                    <a class="dropdown-item" href="<?= htmlspecialchars($n['link']) ?>">
                                        <div class="d-flex w-100">
                                            <div class="me-2">
                                                <i class="bi bi-bell-fill text-primary"></i>
                                            </div>
                                            <div>
                                                <div><?= htmlspecialchars($n['message']) ?></div>
                                                <small class="text-muted"><?= htmlspecialchars($n['created_at']) ?></small>
                                            </div>
                                        </div>
                                    </a>
                                </li>
                            <?php endforeach; ?>
                        <?php endif; ?>
                        <li><hr class="dropdown-divider"></li>
                        <li><a class="dropdown-item text-center small" href="#">View all notifications</a></li>
                    </ul>
                </div>

                <div class="dropdown">
                    <a class="user-menu dropdown-toggle" href="#" role="button" id="userDropdown" data-bs-toggle="dropdown" aria-expanded="false">
                        <div class="user-avatar">
                            <i class="bi bi-person-fill"></i>
                        </div>
                        <span><?= htmlspecialchars(trim($name ?: ($_SESSION['email'] ?? 'User'))) ?></span>
                    </a>
                    <ul class="dropdown-menu dropdown-menu-end" aria-labelledby="userDropdown">
                        <li><a class="dropdown-item" href="<?= $base ?>/profile.php"><i class="bi bi-person me-2"></i>My Profile</a></li>
                        <li><a class="dropdown-item" href="<?= $base ?>/profile.php"><i class="bi bi-gear me-2"></i>Settings</a></li>
                        <li><hr class="dropdown-divider"></li>
                        <li><a class="dropdown-item text-danger" href="/SupplyRequestSystem/auth/logout.php"><i class="bi bi-box-arrow-right me-2"></i>Logout</a></li>
                    </ul>
                </div>
            </div>
        </div>
    </div>
</nav>

