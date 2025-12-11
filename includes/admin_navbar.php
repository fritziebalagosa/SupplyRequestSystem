<!-- admin_navbar.php -->
<?php
// Get current page filename
$current_page = basename($_SERVER['PHP_SELF']);
if (!isset($conn)) { @include('../config/db.php'); }

// Get notifications for the current user (from notifications table)
$notif_count = 0;
$notifications = [];
if (!empty($_SESSION['user_id'])) {
  $dbPath = rtrim($_SERVER['DOCUMENT_ROOT'], DIRECTORY_SEPARATOR) . '/SupplyRequestSystem/config/db.php';
  $notifPath = rtrim($_SERVER['DOCUMENT_ROOT'], DIRECTORY_SEPARATOR) . '/SupplyRequestSystem/includes/notifications.php';
  $funcPath = rtrim($_SERVER['DOCUMENT_ROOT'], DIRECTORY_SEPARATOR) . '/SupplyRequestSystem/includes/functions.php';
  if (file_exists($dbPath)) include_once $dbPath;
  if (file_exists($funcPath)) include_once $funcPath;
  if (file_exists($notifPath)) include_once $notifPath;
  if (isset($conn) && function_exists('get_user_notifications') && function_exists('get_unread_notification_count')) {
    $notifications = get_user_notifications($conn, $_SESSION['user_id'], 6, false);
    $notif_count = get_unread_notification_count($conn, $_SESSION['user_id']);
  }
}

// Also keep low stock alerts for admin
$alert_count = 0;
$alerts = [];
if (!empty($_SESSION['user_id']) && $_SESSION['role'] === 'admin' && isset($conn)) {
    $stmt = $conn->prepare("SELECT i.item_name, i.stock_qty, i.reorder_level FROM items i WHERE i.stock_qty <= i.reorder_level ORDER BY i.item_name");
    $stmt->execute();
    $alerts = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $alert_count = count($alerts);
    $stmt->close();
}
?>

<style>
  :root {
    --red-primary: #dc3545;
    --red-dark: #c82333;
    --red-darker: #bd2130;
  }

  /* Reset navbar styles */
  .navbar-custom {
    background-color: white !important;
    border-bottom: 1px solid #e0e0e0;
    padding: 0.75rem 0;
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.05);
  }

  /* Brand */
  .navbar-custom .navbar-brand {
    color: var(--red-primary) !important;
    font-weight: 600;
    font-size: 1.25rem;
    letter-spacing: -0.5px;
    transition: color 0.2s ease;
  }

  .navbar-custom .navbar-brand:hover {
    color: var(--red-dark) !important;
  }

  .navbar-custom .navbar-brand i {
    font-size: 1.1rem;
    margin-right: 0.35rem;
  }

  /* Toggler for mobile */
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

  /* Nav links */
  .navbar-custom .nav-link {
    color: #616161 !important;
    font-weight: 500;
    font-size: 0.9375rem;
    padding: 0.5rem 1rem !important;
    transition: all 0.2s ease;
    border-radius: 6px;
    margin: 0 0.125rem;
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

  /* Notification bell */
  .navbar-custom .notification-link {
    position: relative;
    color: #616161 !important;
    font-size: 1.25rem;
    padding: 0.5rem 0.75rem !important;
    transition: color 0.2s ease;
  }

  .navbar-custom .notification-link:hover {
    color: var(--red-primary) !important;
  }

  .navbar-custom .notification-badge {
    position: absolute;
    top: 0.25rem;
    right: 0.25rem;
    background-color: var(--red-primary);
    color: white;
    font-size: 0.625rem;
    font-weight: 600;
    padding: 0.15rem 0.35rem;
    border-radius: 10px;
    line-height: 1;
    min-width: 18px;
    text-align: center;
  }

  .mark-all-read {
    color: var(--red-primary) !important;
    text-decoration: none;
    font-weight: 500;
    transition: opacity 0.2s ease;
  }

  .mark-all-read:hover {
    opacity: 0.8;
    text-decoration: underline;
  }

  /* Profile dropdown */
  .navbar-custom .profile-dropdown .nav-link {
    color: #212121 !important;
    font-weight: 500;
    padding: 0.5rem 1rem !important;
  }

  .navbar-custom .profile-dropdown .nav-link:hover {
    color: var(--red-primary) !important;
  }

  .navbar-custom .profile-dropdown .nav-link i {
    font-size: 1.25rem;
    margin-right: 0.5rem;
  }

  /* Dropdown menus */
  .navbar-custom .dropdown-menu {
    border: 1px solid #e0e0e0;
    border-radius: 8px;
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
    padding: 0.5rem;
    margin-top: 0.5rem;
  }

  .navbar-custom .dropdown-header {
    color: #616161;
    font-weight: 600;
    font-size: 0.75rem;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    padding: 0.5rem 1rem;
  }

  .navbar-custom .dropdown-item {
    color: #212121;
    font-size: 0.9375rem;
    padding: 0.6rem 1rem;
    border-radius: 6px;
    transition: all 0.2s ease;
  }

  .navbar-custom .dropdown-item:hover {
    background-color: #fafafa;
    color: var(--red-primary);
  }

  .navbar-custom .dropdown-item i {
    width: 1.25rem;
    margin-right: 0.5rem;
    font-size: 1rem;
  }

  .navbar-custom .dropdown-item.text-danger {
    color: var(--red-primary) !important;
  }

  .navbar-custom .dropdown-item.text-danger:hover {
    background-color: #fff5f5;
    color: var(--red-dark) !important;
  }

  .navbar-custom .dropdown-item.text-primary {
    color: var(--red-primary) !important;
    font-weight: 500;
  }

  .navbar-custom .dropdown-item.text-primary:hover {
    background-color: #fff5f5;
  }

  .navbar-custom .dropdown-item.small {
    font-size: 0.875rem;
    padding: 0.5rem 1rem;
  }

  .navbar-custom .dropdown-divider {
    border-top: 1px solid #eeeeee;
    margin: 0.5rem 0;
  }

  /* Notification dropdown specific styles */
  .notification-dropdown {
    min-width: 320px;
    max-width: 320px;
  }

  .notification-dropdown .dropdown-item {
    white-space: normal;
    line-height: 1.4;
  }

  /* Mobile responsive */
  @media (max-width: 991.98px) {
    .navbar-custom .navbar-collapse {
      margin-top: 1rem;
      padding-top: 1rem;
      border-top: 1px solid #eeeeee;
    }

    .navbar-custom .nav-link {
      padding: 0.75rem 1rem !important;
    }

    .navbar-custom .notification-link {
      display: inline-flex;
      align-items: center;
    }

    .navbar-custom .notification-badge {
      position: static;
      margin-left: 0.5rem;
    }
  }
</style>

<nav class="navbar navbar-expand-lg navbar-custom">
  <div class="container-fluid">
    <!-- System Name -->
    <a class="navbar-brand" href="dashboard.php">
      <i class="bi bi-building"></i> WMSU OSRS
    </a>

    <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNavDropdown">
      <span class="navbar-toggler-icon"></span>
    </button>

    <div class="collapse navbar-collapse" id="navbarNavDropdown">
      <ul class="navbar-nav me-auto ms-lg-3">
        <li class="nav-item">
          <a class="nav-link <?php echo ($current_page == 'dashboard.php') ? 'active' : ''; ?>" href="dashboard.php">
            <i class="bi bi-speedometer2"></i> Dashboard
          </a>
        </li>
        <li class="nav-item">
          <a class="nav-link <?php echo ($current_page == 'manage_inventory.php') ? 'active' : ''; ?>" href="manage_inventory.php">
            <i class="bi bi-box-seam"></i> Inventory
          </a>
        </li>
        <li class="nav-item">
          <a class="nav-link <?php echo ($current_page == 'manage_requests.php') ? 'active' : ''; ?>" href="manage_requests.php">
            <i class="bi bi-envelope-check"></i> Requests
          </a>
        </li>
        <li class="nav-item">
          <a class="nav-link <?php echo ($current_page == 'records.php') ? 'active' : ''; ?>" href="records.php">
            <i class="bi bi-envelope-check"></i> Records
          </a>
        </li>
        <li class="nav-item">
          <a class="nav-link <?php echo ($current_page == 'manage_users.php') ? 'active' : ''; ?>" href="manage_users.php">
            <i class="bi bi-people"></i> Users
          </a>
        </li>
      </ul>

      <!-- Right side -->
      <ul class="navbar-nav ms-auto">
        <!-- Notifications Dropdown -->
        <li class="nav-item dropdown">
          <a class="nav-link notification-link" href="#" id="notifDropdown" role="button" data-bs-toggle="dropdown" aria-expanded="false">
            <i class="bi bi-bell"></i>
            <?php if ($notif_count > 0): ?><span class="notification-badge"><?= $notif_count ?></span><?php endif; ?>
          </a>
          <ul class="dropdown-menu dropdown-menu-end notification-dropdown" aria-labelledby="notifDropdown">
            <li class="dropdown-header d-flex justify-content-between align-items-center px-3 py-2">
              <strong>Notifications</strong>
              <?php if ($notif_count > 0): ?>
                <a href="#" id="markAllRead" class="mark-all-read" style="font-size: 0.8rem;">Mark all as read</a>
              <?php endif; ?>
            </li>
            <?php if (!empty($notifications)): ?>
              <?php foreach ($notifications as $notification): ?>
                <li>
                  <a class="dropdown-item notification-item" href="<?= htmlspecialchars($notification['link'] ?? '#') ?>" data-notif-id="<?= intval($notification['id']) ?>">
                    <div class="d-flex w-100">
                      <div class="notification-icon me-2">
                        <i class="bi bi-bell-fill text-primary"></i>
                      </div>
                      <div class="notification-content">
                        <div class="notification-message"><?= htmlspecialchars($notification['message']) ?></div>
                        <div class="notification-time text-muted" style="font-size: 0.75rem;">
                          <?= time_elapsed_string($notification['created_at']) ?>
                        </div>
                      </div>
                    </div>
                  </a>
                </li>
              <?php endforeach; ?>
              <li><hr class="dropdown-divider"></li>
              <li><a class="dropdown-item text-center" href="notifications.php">View all notifications</a></li>
            <?php else: ?>
              <li class="px-3 py-2 text-muted">No notifications</li>
            <?php endif; ?>
          </ul>
        </li>

        <!-- Low Stock Alerts Dropdown -->
        <li class="nav-item dropdown">
          <a class="nav-link notification-link" href="#" id="alertDropdown" role="button" data-bs-toggle="dropdown" aria-expanded="false">
            <i class="bi bi-exclamation-triangle"></i>
            <?php if ($alert_count > 0): ?><span class="notification-badge"><?= $alert_count ?></span><?php endif; ?>
          </a>
          <ul class="dropdown-menu dropdown-menu-end notification-dropdown" aria-labelledby="alertDropdown">
            <li><h6 class="dropdown-header">Low Stock Alerts</h6></li>
            <?php if ($alert_count === 0): ?>
              <li><span class="dropdown-item small text-muted">No low stock alerts</span></li>
            <?php else: foreach ($alerts as $al): ?>
              <li>
                <a class="dropdown-item small" href="manage_inventory.php">
                  <i class="bi bi-exclamation-triangle"></i>
                  <?= htmlspecialchars($al['item_name']) ?> low (<?= (int)$al['stock_qty'] ?>/<?= (int)$al['reorder_level'] ?>)
                </a>
              </li>
            <?php endforeach; endif; ?>
            <li><hr class="dropdown-divider"></li>
            <li><a class="dropdown-item text-center small text-primary" href="manage_inventory.php">Open Inventory</a></li>
          </ul>
        </li>

        <!-- Profile Dropdown -->
        <li class="nav-item dropdown profile-dropdown">
          <a class="nav-link dropdown-toggle" href="#" id="adminDropdown" role="button" data-bs-toggle="dropdown" aria-expanded="false">
            <i class="bi bi-person-circle"></i> Admin
          </a>
          <ul class="dropdown-menu dropdown-menu-end" aria-labelledby="adminDropdown">
            <li><a class="dropdown-item" href="profile.php">
              <i class="bi bi-gear"></i> Profile Settings
            </a></li>
            <li><hr class="dropdown-divider"></li>
            <li><a class="dropdown-item text-danger" href="../auth/logout.php">
              <i class="bi bi-box-arrow-right"></i> Logout
            </a></li>
          </ul>
        </li>
      </ul>
    </div>
  </div>
</nav>

<script>
// Notification mark-as-read handlers for admin navbar
(function(){
  const apiUrl = '/SupplyRequestSystem/api/notifications.php';

  function postMarkRead(ids){
    const fd = new FormData();
    fd.append('action', 'mark_read');
    fd.append('ids', JSON.stringify(ids));

    return fetch(apiUrl, { method: 'POST', body: fd, credentials: 'same-origin' })
      .then(r => r.json());
  }

  document.addEventListener('click', function(e){
    // Mark all as read
    if (e.target && e.target.id === 'markAllRead'){
      e.preventDefault();
      const items = document.querySelectorAll('.notification-item[data-notif-id]');
      const ids = Array.from(items).map(i => parseInt(i.getAttribute('data-notif-id'))).filter(Boolean);
      if (!ids.length) return;

      postMarkRead(ids).then(res => {
        if (res.success) {
          // remove badge
          const badge = document.querySelector('.notification-badge');
          if (badge) badge.remove();
          // visually mark items as read
          items.forEach(it => it.classList.add('text-muted'));
        }
      }).catch(()=>{});
    }

    // Single notification click: fire mark-read in background
    const notif = e.target.closest && e.target.closest('.notification-item');
    if (notif && notif.dataset && notif.dataset.notifId){
      const id = parseInt(notif.dataset.notifId);
      if (!id) return;
      // decrement badge count optimistically
      const badge = document.querySelector('.notification-badge');
      if (badge){
        const n = parseInt(badge.textContent) || 0;
        if (n > 1) badge.textContent = n - 1;
        else badge.remove();
      }
      postMarkRead([id]).catch(()=>{});
    }
  });
})();
</script>