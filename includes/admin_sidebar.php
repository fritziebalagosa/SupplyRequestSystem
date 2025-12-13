<?php
// admin_sidebar.php - Sidebar for admin pages (replaces top navbar)
$current_page = basename($_SERVER['PHP_SELF']);
if (!isset($conn)) { @include(__DIR__ . '/../config/db.php'); }

// Get notification count
$notification_count = 0;
if (isset($conn) && $conn && isset($_SESSION['user_id'])) {
    $user_id = $_SESSION['user_id'];
    $stmt = $conn->prepare("SELECT COUNT(*) as count FROM notifications WHERE user_id = ? AND `read` = 0");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $notif_result = $stmt->get_result();
    $notification_count = $notif_result->fetch_assoc()['count'];
    $stmt->close();
}
?>
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css" rel="stylesheet">
<link rel="stylesheet" href="/SupplyRequestSystem/styles/admin_sidebar.css">

<aside class="admin-sidebar">
  <div class="brand">
    <img src="/SupplyRequestSystem/wmsulogo.jpg" alt="WMSU OSRS Logo" style="height: 24px;"> 
    <span>WMSU OSRS</span>
    <button class="toggle-btn" onclick="toggleSidebar()">
      <i class="bi bi-chevron-left"></i>
    </button>
  </div>
  <nav>
    <ul class="px-0">
      <li class="nav-item"><a class="nav-link <?php echo ($current_page == 'dashboard.php') ? 'active' : ''; ?>" href="dashboard.php"><i class="bi bi-speedometer2"></i> <span>Dashboard</span></a></li>
      <li class="nav-item"><a class="nav-link <?php echo ($current_page == 'manage_inventory.php') ? 'active' : ''; ?>" href="manage_inventory.php"><i class="bi bi-box-seam"></i> <span>Inventory</span></a></li>
      <li class="nav-item"><a class="nav-link <?php echo ($current_page == 'manage_requests.php') ? 'active' : ''; ?>" href="manage_requests.php"><i class="bi bi-envelope-check"></i> <span>Requests</span></a></li>
      <li class="nav-item"><a class="nav-link <?php echo ($current_page == 'records.php') ? 'active' : ''; ?>" href="records.php"><i class="bi bi-journal-text"></i> <span>Records</span></a></li>
      <li class="nav-item"><a class="nav-link <?php echo ($current_page == 'manage_users.php') ? 'active' : ''; ?>" href="manage_users.php"><i class="bi bi-people"></i> <span>Users</span></a></li>
    </ul>
  </nav>
  <div class="divider"></div>
  <div class="sidebar-footer">
    <ul class="px-0">
      <li class="nav-item">
        <a class="nav-link" href="profile.php">
          <i class="bi bi-person-circle"></i> <span>Profile</span>
        </a>
      </li>
      <li class="nav-item">
        <a class="nav-link" href="../auth/logout.php">
          <i class="bi bi-box-arrow-right"></i> <span>Logout</span>
        </a>
      </li>
      <li class="nav-item">
        <a class="nav-link" href="notifications.php">
          <i class="bi bi-bell"></i> <span>Notifications</span>
          <?php if($notification_count>0): ?>
            <span class="sidebar-notification-badge"><?php echo $notification_count; ?></span>
          <?php endif; ?>
        </a>
      </li>
    </ul>
  </div>
</aside>

<!-- Mobile menu overlay -->
<div class="mobile-menu-overlay" onclick="closeMobileMenu()"></div>

<!-- Mobile menu toggle button -->
<button class="mobile-menu-toggle" onclick="toggleMobileMenu()" style="display: none;">
  <span class="navbar-toggler-icon"></span>
</button>

<script>
function toggleSidebar() {
  const sidebar = document.querySelector('.admin-sidebar');
  const toggleBtn = document.querySelector('.toggle-btn i');
  const body = document.body;
  
  sidebar.classList.toggle('collapsed');
  body.classList.toggle('sidebar-collapsed');
  
  // Change toggle button icon
  if (sidebar.classList.contains('collapsed')) {
    toggleBtn.classList.remove('bi-chevron-left');
    toggleBtn.classList.add('bi-chevron-right');
  } else {
    toggleBtn.classList.remove('bi-chevron-right');
    toggleBtn.classList.add('bi-chevron-left');
  }
  
  // Save state to localStorage
  localStorage.setItem('sidebarCollapsed', sidebar.classList.contains('collapsed'));
}

// Mobile menu functions
function toggleMobileMenu() {
  const sidebar = document.querySelector('.admin-sidebar');
  const overlay = document.querySelector('.mobile-menu-overlay');
  const toggleBtn = document.querySelector('.mobile-menu-toggle');
  const toggleIcon = toggleBtn.querySelector('.navbar-toggler-icon');
  
  sidebar.classList.toggle('mobile-open');
  overlay.classList.toggle('show');
  
  // Change icon to X when open
  if (sidebar.classList.contains('mobile-open')) {
    toggleIcon.style.backgroundImage = "url('data:image/svg+xml,%3csvg xmlns=\'http://www.w3.org/2000/svg\' viewBox=\'0 0 30 30\'%3e%3cpath stroke=\'%23dc3545\' stroke-linecap=\'round\' stroke-miterlimit=\'10\' stroke-width=\'2\' d=\'M4 4h22M4 4l22 22M4 26l22-22M26 26H4\'/%3e%3c/svg%3e')";
  } else {
    toggleIcon.style.backgroundImage = "url('data:image/svg+xml,%3csvg xmlns=\'http://www.w3.org/2000/svg\' viewBox=\'0 0 30 30\'%3e%3cpath stroke=\'%23dc3545\' stroke-linecap=\'round\' stroke-miterlimit=\'10\' stroke-width=\'2\' d=\'M4 7h22M4 15h22M4 23h22\'/%3e%3c/svg%3e')";
  }
}

function closeMobileMenu() {
  const sidebar = document.querySelector('.admin-sidebar');
  const overlay = document.querySelector('.mobile-menu-overlay');
  const toggleBtn = document.querySelector('.mobile-menu-toggle');
  const toggleIcon = toggleBtn.querySelector('.navbar-toggler-icon');
  
  sidebar.classList.remove('mobile-open');
  overlay.classList.remove('show');
  
  // Reset icon to hamburger
  toggleIcon.style.backgroundImage = "url('data:image/svg+xml,%3csvg xmlns=\'http://www.w3.org/2000/svg\' viewBox=\'0 0 30 30\'%3e%3cpath stroke=\'%23dc3545\' stroke-linecap=\'round\' stroke-miterlimit=\'10\' stroke-width=\'2\' d=\'M4 7h22M4 15h22M4 23h22\'/%3e%3c/svg%3e')";
}

// Handle window resize
function handleResize() {
  const width = window.innerWidth;
  const mobileToggle = document.querySelector('.mobile-menu-toggle');
  const sidebar = document.querySelector('.admin-sidebar');
  
  if (width <= 991.98) {
    mobileToggle.style.display = 'block';
    // Close mobile menu if open
    closeMobileMenu();
  } else {
    mobileToggle.style.display = 'none';
    sidebar.classList.remove('mobile-open');
    document.querySelector('.mobile-menu-overlay').classList.remove('show');
  }
}

// Restore sidebar state on page load
document.addEventListener('DOMContentLoaded', function() {
  const isCollapsed = localStorage.getItem('sidebarCollapsed') === 'true';
  if (isCollapsed && window.innerWidth > 991.98) {
    const sidebar = document.querySelector('.admin-sidebar');
    const toggleBtn = document.querySelector('.toggle-btn i');
    const body = document.body;
    
    sidebar.classList.add('collapsed');
    body.classList.add('sidebar-collapsed');
    toggleBtn.classList.remove('bi-chevron-left');
    toggleBtn.classList.add('bi-chevron-right');
  }
  
  // Set up resize listener
  window.addEventListener('resize', handleResize);
  handleResize(); // Initial check
});
</script>

<?php // end of admin_sidebar.php ?>
