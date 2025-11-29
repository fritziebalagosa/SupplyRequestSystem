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
<style>
  :root{ 
    --sidebar-width: 240px; 
    --sidebar-collapsed-width: 64px;
    --red-primary: #dc3545; 
    --gray-100: #f5f5f5; 
  }
  .admin-sidebar{ 
    position:fixed; 
    left:0; 
    top:0; 
    height:100vh; 
    width:var(--sidebar-width); 
    background:#fff; 
    border-right:1px solid #eee; 
    padding:1.25rem 0.75rem; 
    overflow:auto; 
    z-index:1030;
    transition: width 0.3s ease;
  }
  .admin-sidebar.collapsed {
    width: var(--sidebar-collapsed-width);
  }
  .admin-sidebar .brand{ 
    display:flex; 
    align-items:center; 
    gap:0.5rem; 
    padding:0 0.5rem; 
    margin-bottom:1.25rem; 
    font-weight:700; 
    color:var(--red-primary); 
    font-size:1.05rem;
    position: relative;
  }
  .admin-sidebar .brand i{ font-size:1.2rem; }
  .admin-sidebar.collapsed .brand span { display: none; }
  .admin-sidebar .toggle-btn {
    position: absolute;
    right: 0;
    top: 50%;
    transform: translateY(-50%);
    background: none;
    border: none;
    color: var(--red-primary);
    cursor: pointer;
    padding: 0.25rem;
    display: flex;
    align-items: center;
    justify-content: center;
  }
  .admin-sidebar.collapsed .toggle-btn {
    right: -0.5rem;
  }
  .admin-sidebar nav{ display:block; }
  .admin-sidebar .nav-item{ list-style:none; }
  .admin-sidebar .nav-link{ 
    display:flex; 
    align-items:center; 
    gap:0.6rem; 
    color:#444; 
    padding:0.6rem 0.5rem; 
    border-radius:6px; 
    font-weight:500; 
    text-decoration:none;
    white-space: nowrap;
    overflow: hidden;
  }
  .admin-sidebar .nav-link i{ font-size:1.05rem; min-width: 1.05rem; }
  .admin-sidebar.collapsed .nav-link span { opacity: 0; }
  .admin-sidebar .nav-link:hover{ background:#fafafa; color:var(--red-primary); }
  .admin-sidebar .nav-link.active{ background:#fff5f5; color:var(--red-primary); }
  .admin-sidebar .divider{ height:1px; background:#f1f1f1; margin:0.75rem 0; }
  .admin-sidebar .sidebar-footer{ position:sticky; bottom:1rem; padding:0 0.5rem; }
  .admin-sidebar.collapsed .sidebar-footer span { display: none; }

  /* push content to the right of the sidebar */
  .container-main{ 
    margin-left: calc(var(--sidebar-width) + 20px);
    transition: margin-left 0.3s ease;
  }
  body.sidebar-collapsed .container-main {
    margin-left: calc(var(--sidebar-collapsed-width) + 20px);
  }

  @media (max-width: 991.98px){
    .admin-sidebar{ position:relative; width:100% !important; height:auto; border-right:none; }
    .container-main{ margin-left:0 !important; padding-top:1rem; }
    .admin-sidebar.collapsed .nav-link span,
    .admin-sidebar.collapsed .brand span,
    .admin-sidebar.collapsed .sidebar-footer span { display: inline; }
  }
</style>

<aside class="admin-sidebar">
  <div class="brand">
    <i class="bi bi-building"></i> 
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
    <div style="margin-bottom:0.5rem;"><a class="nav-link" href="profile.php"><i class="bi bi-person-circle"></i> <span>Profile</span></a></div>
    <div style="margin-bottom:0.5rem;">
      <a class="nav-link" href="../auth/logout.php"><i class="bi bi-box-arrow-right"></i> <span>Logout</span></a>
    </div>
    <div style="margin-top:0.5rem;">
      <a class="nav-link" href="notifications.php"><i class="bi bi-bell"></i> <span>Notifications <?php if($notification_count>0){ echo '<span style="color:var(--red-primary);font-weight:700;">('.$notification_count.')</span>'; } ?></span></a>
    </div>
  </div>
</aside>

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

// Restore sidebar state on page load
document.addEventListener('DOMContentLoaded', function() {
  const isCollapsed = localStorage.getItem('sidebarCollapsed') === 'true';
  if (isCollapsed) {
    const sidebar = document.querySelector('.admin-sidebar');
    const toggleBtn = document.querySelector('.toggle-btn i');
    const body = document.body;
    
    sidebar.classList.add('collapsed');
    body.classList.add('sidebar-collapsed');
    toggleBtn.classList.remove('bi-chevron-left');
    toggleBtn.classList.add('bi-chevron-right');
  }
});
</script>

<?php // end of admin_sidebar.php ?>
