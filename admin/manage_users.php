<?php
session_start();
include('../config/db.php');

// Ensure only admin
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header('Location: ../login.php');
    exit;
}

// Add user
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_user'])) {
    $first_name = trim($_POST['first_name']);
    $middle_name = !empty($_POST['middle_name']) ? trim($_POST['middle_name']) : NULL;
    $last_name = trim($_POST['last_name']);
    $email = trim($_POST['email']);
    $password = password_hash($_POST['password'], PASSWORD_DEFAULT);
    $role = $_POST['role'];
    $office_type = $_POST['office_type'];
    $college_office_name = trim($_POST['college_office_name']);
    $status = 'active';

    // find or create college/office
    $stmt = $conn->prepare("SELECT id FROM college_offices WHERE name = ? AND type = ?");
    $stmt->bind_param("ss", $college_office_name, $office_type);
    $stmt->execute();
    $res = $stmt->get_result();

    if ($res->num_rows === 0) {
        $insertOffice = $conn->prepare("INSERT INTO college_offices (name, type, created_at) VALUES (?, ?, NOW())");
        $insertOffice->bind_param("ss", $college_office_name, $office_type);
        $insertOffice->execute();
        $college_office_id = $insertOffice->insert_id;
    } else {
        $college_office_id = $res->fetch_assoc()['id'];
    }

    // validation
    if ($role === 'supply_officer') {
        $check = $conn->query("SELECT id FROM users WHERE role='supply_officer' AND status='active'");
        if ($check->num_rows > 0) $error = "Only one Supply Officer is allowed.";
    } elseif ($role === 'dean' && $office_type === 'College') {
        $check = $conn->prepare("SELECT id FROM users WHERE role='dean' AND college_office_id=? AND status='active'");
        $check->bind_param("i", $college_office_id);
        $check->execute();
        if ($check->get_result()->num_rows > 0) $error = "This college already has a Dean.";
    } elseif ($role === 'head' && $office_type === 'Office') {
        $check = $conn->prepare("SELECT id FROM users WHERE role='head' AND college_office_id=? AND status='active'");
        $check->bind_param("i", $college_office_id);
        $check->execute();
        if ($check->get_result()->num_rows > 0) $error = "This office already has a Head.";
    }

    if (!isset($error)) {
        $insert = $conn->prepare("INSERT INTO users (first_name, middle_name, last_name, email, password, role, college_office_id, status, created_at)
                                  VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())");
        $insert->bind_param("ssssssis", $first_name, $middle_name, $last_name, $email, $password, $role, $college_office_id, $status);
        $insert->execute();
        $success = "User successfully added.";
    }
}

// Actions
if (isset($_GET['action'], $_GET['id'])) {
    $id = intval($_GET['id']);
    $action = $_GET['action'];

    if ($action === 'disable') $conn->query("UPDATE users SET status='inactive' WHERE id=$id");
    elseif ($action === 'activate') $conn->query("UPDATE users SET status='active' WHERE id=$id");
    elseif ($action === 'delete') $conn->query("DELETE FROM users WHERE id=$id");

    header("Location: manage_users.php");
    exit;
}

// Fetch users
$users = $conn->query("
    SELECT u.*, c.name AS college_office_name, c.type AS office_type
    FROM users u
    LEFT JOIN college_offices c ON u.college_office_id = c.id
    ORDER BY u.created_at DESC
");
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Manage Users - WMSU OSRS</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
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

    /* Filter Card */
    .filter-card {
        background: white;
        border-radius: 12px;
        border: 1px solid var(--gray-200);
        padding: 1.5rem;
        margin-bottom: 1.5rem;
    }

    .filter-label {
        font-size: 0.875rem;
        color: var(--gray-700);
        font-weight: 500;
        margin-bottom: 0.5rem;
    }

    .form-control-minimal,
    .form-select-minimal {
        border: 1px solid var(--gray-300);
        border-radius: 8px;
        padding: 0.625rem 0.875rem;
        font-size: 0.9375rem;
        transition: all 0.2s ease;
    }

    .form-control-minimal:focus,
    .form-select-minimal:focus {
        border-color: var(--red-primary);
        box-shadow: 0 0 0 0.2rem rgba(220, 53, 69, 0.1);
        outline: none;
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
        cursor: pointer;
    }

    .btn-primary-minimal {
        background-color: var(--red-primary);
        color: white;
    }

    .btn-primary-minimal:hover {
        background-color: var(--red-dark);
        transform: translateY(-1px);
    }

    .btn-secondary-minimal {
        background-color: white;
        color: var(--gray-700);
        border: 1px solid var(--gray-300);
    }

    .btn-secondary-minimal:hover {
        background-color: var(--gray-50);
        border-color: var(--gray-700);
    }

    .btn-sm-minimal {
        padding: 0.4rem 0.875rem;
        font-size: 0.875rem;
    }

    .btn-action-view {
        background-color: #d1ecf1;
        color: #0c5460;
        border: 1px solid #bee5eb;
    }

    .btn-action-view:hover {
        background-color: #bee5eb;
        border-color: #17a2b8;
        color: #0c5460;
    }

    .btn-action-warning {
        background-color: #fff3cd;
        color: #856404;
        border: 1px solid #ffeaa7;
    }

    .btn-action-warning:hover {
        background-color: #ffe69c;
        border-color: #ffc107;
        color: #856404;
    }

    .btn-action-success {
        background-color: #d4edda;
        color: #155724;
        border: 1px solid #c3e6cb;
    }

    .btn-action-success:hover {
        background-color: #c3e6cb;
        border-color: #28a745;
        color: #155724;
    }

    .btn-action-danger {
        background-color: var(--red-light);
        color: #721c24;
        border: 1px solid #f5c6cb;
    }

    .btn-action-danger:hover {
        background-color: #f1b0b7;
        border-color: var(--red-primary);
        color: #721c24;
    }

    /* Table Section */
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

    .badge-success {
        background-color: #d4edda;
        color: #155724;
        border-color: #c3e6cb;
    }

    .badge-secondary {
        background-color: var(--gray-200);
        color: var(--gray-700);
        border-color: var(--gray-300);
    }

    /* Action Buttons Group */
    .action-buttons {
        display: flex;
        gap: 0.5rem;
        flex-wrap: wrap;
    }

    /* Modal Styles */
    .modal-content {
        border-radius: 12px;
        border: 1px solid var(--gray-200);
    }

    .modal-header {
        border-bottom: 1px solid var(--gray-200);
        padding: 1.25rem 1.5rem;
    }

    .modal-header.modal-header-info {
        background-color: #d1ecf1;
    }

    .modal-header.modal-header-success {
        background-color: #d4edda;
    }

    .modal-title {
        font-size: 1.125rem;
        font-weight: 600;
        color: var(--gray-900);
    }

    .modal-body {
        padding: 1.5rem;
    }

    .modal-footer {
        border-top: 1px solid var(--gray-200);
        padding: 1rem 1.5rem;
    }

    .form-label-minimal {
        font-size: 0.875rem;
        color: var(--gray-700);
        font-weight: 500;
        margin-bottom: 0.5rem;
    }

    .detail-row {
        padding: 0.75rem 0;
        border-bottom: 1px solid var(--gray-100);
    }

    .detail-row:last-child {
        border-bottom: none;
    }

    .detail-label {
        font-weight: 600;
        color: var(--gray-700);
        font-size: 0.875rem;
        margin-bottom: 0.25rem;
    }

    .detail-value {
        color: var(--gray-900);
        font-size: 0.9375rem;
    }

    /* Empty State */
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

    /* Responsive */
    @media (max-width: 768px) {
        .container-main {
            padding: 1.5rem 1rem;
        }

        .page-header {
            flex-direction: column;
            align-items: flex-start;
            gap: 1rem;
        }

        .page-title {
            font-size: 1.5rem;
        }

        .filter-card {
            padding: 1.25rem;
        }

        .table-minimal thead th,
        .table-minimal tbody td {
            padding: 0.875rem 0.75rem;
            font-size: 0.875rem;
        }

        .action-buttons {
            flex-direction: column;
        }

        .action-buttons button {
            width: 100%;
            justify-content: center;
        }
    }
</style>
<script>
function confirmAction(action, id, name) {
    let msg = {
        disable: `Disable ${name}?`,
        activate: `Reactivate ${name}?`,
        delete: `Permanently delete ${name}?`
    }[action];
    Swal.fire({
        title: 'Are you sure?',
        text: msg,
        icon: 'warning',
        showCancelButton: true,
        confirmButtonText: 'Yes',
        cancelButtonText: 'Cancel',
        confirmButtonColor: '#dc3545',
        cancelButtonColor: '#6c757d'
    }).then(result => { if (result.isConfirmed) window.location.href = `manage_users.php?action=${action}&id=${id}`; });
}

function toggleCollegeOffice() {
    const type = document.getElementById('office_type').value;
    document.getElementById('collegeOfficeField').style.display = type ? 'block' : 'none';
}

function filterTable() {
    const search = document.getElementById("searchInput").value.toLowerCase();
    const role = document.getElementById("roleFilter").value;
    const status = document.getElementById("statusFilter").value;
    const type = document.getElementById("typeFilter").value;
    const rows = document.querySelectorAll("#userTable tbody tr");
    rows.forEach(row => {
        const name = row.querySelector(".name").textContent.toLowerCase();
        const email = row.querySelector(".email").textContent.toLowerCase();
        const roleVal = row.querySelector(".role").textContent.trim();
        const statusVal = row.querySelector(".status").textContent.trim();
        const typeVal = row.querySelector(".type").textContent.trim();
        row.style.display = ( (!role || roleVal === role) && (!status || statusVal === status) && (!type || typeVal === type) &&
                              (name.includes(search) || email.includes(search)) ) ? "" : "none";
    });
}

function showUserDetails(data) {
    document.getElementById('detailName').textContent = data.name;
    document.getElementById('detailEmail').textContent = data.email;
    document.getElementById('detailRole').textContent = data.role;
    document.getElementById('detailOffice').textContent = data.office;
    document.getElementById('detailType').textContent = data.type;
    document.getElementById('detailStatus').textContent = data.status;
    document.getElementById('detailCreated').textContent = data.created;
    new bootstrap.Modal(document.getElementById('viewUserModal')).show();
}
</script>
</head>
<body>
<?php include('../includes/admin_sidebar.php'); ?>

<div class="container-main">
    <div class="page-header">
        <h1 class="page-title">User Management</h1>
        <button class="btn-minimal btn-primary-minimal" data-bs-toggle="modal" data-bs-target="#addUserModal">
            <i class="bi bi-person-plus"></i> Add New User
        </button>
    </div>

    <!-- Filter Card -->
    <div class="filter-card">
        <div class="row g-3">
            <div class="col-md-3">
                <label class="filter-label">Search</label>
                <input type="text" id="searchInput" class="form-control form-control-minimal" placeholder="Search by name or email" onkeyup="filterTable()">
            </div>
            <div class="col-md-3">
                <label class="filter-label">Role</label>
                <select id="roleFilter" class="form-select form-select-minimal" onchange="filterTable()">
                    <option value="">All Roles</option>
                    <option value="dean">Dean</option>
                    <option value="head">Head</option>
                    <option value="requester">Requester</option>
                    <option value="supply_officer">Supply Officer</option>
                    <option value="admin">Admin</option>
                </select>
            </div>
            <div class="col-md-3">
                <label class="filter-label">Status</label>
                <select id="statusFilter" class="form-select form-select-minimal" onchange="filterTable()">
                    <option value="">All Status</option>
                    <option value="active">Active</option>
                    <option value="inactive">Inactive</option>
                </select>
            </div>
            <div class="col-md-3">
                <label class="filter-label">Type</label>
                <select id="typeFilter" class="form-select form-select-minimal" onchange="filterTable()">
                    <option value="">All Types</option>
                    <option value="College">College</option>
                    <option value="Office">Office</option>
                </select>
            </div>
        </div>
    </div>

    <!-- Users Table -->
    <div class="section-card">
        <div class="table-responsive">
            <table class="table table-minimal" id="userTable">
                <thead>
                    <tr>
                        <th>Name</th>
                        <th>Email</th>
                        <th>Role</th>
                        <th>College/Office</th>
                        <th>Type</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php if ($users->num_rows > 0): ?>
                    <?php while ($row = $users->fetch_assoc()): 
                        $fullName = htmlspecialchars($row['first_name'] . ' ' . ($row['middle_name'] ? $row['middle_name'].' ' : '') . $row['last_name']); ?>
                        <tr>
                            <td class="name"><?= $fullName ?></td>
                            <td class="email"><?= htmlspecialchars($row['email']) ?></td>
                            <td class="role"><?= htmlspecialchars(ucwords(str_replace('_', ' ', $row['role']))) ?></td>
                            <td><?= htmlspecialchars($row['college_office_name'] ?? '-') ?></td>
                            <td class="type"><?= htmlspecialchars($row['office_type'] ?? '-') ?></td>
                            <td class="status">
                                <span class="badge-minimal <?= $row['status'] === 'active' ? 'badge-success' : 'badge-secondary' ?>">
                                    <i class="bi bi-<?= $row['status'] === 'active' ? 'check-circle' : 'x-circle' ?>"></i> 
                                    <?= htmlspecialchars(ucfirst($row['status'])) ?>
                                </span>
                            </td>
                            <td>
                                <div class="action-buttons">
                                    <button class="btn-minimal btn-sm-minimal btn-action-view" onclick='showUserDetails({
                                        name: "<?= $fullName ?>",
                                        email: "<?= htmlspecialchars($row['email']) ?>",
                                        role: "<?= htmlspecialchars(ucwords(str_replace('_', ' ', $row['role']))) ?>",
                                        office: "<?= htmlspecialchars($row['college_office_name'] ?? '-') ?>",
                                        type: "<?= htmlspecialchars($row['office_type'] ?? '-') ?>",
                                        status: "<?= htmlspecialchars(ucfirst($row['status'])) ?>",
                                        created: "<?= htmlspecialchars(date("M d, Y", strtotime($row['created_at']))) ?>"
                                    })'>
                                        <i class="bi bi-eye"></i> View
                                    </button>

                                    <?php if ($row['status'] === 'active'): ?>
                                        <button class="btn-minimal btn-sm-minimal btn-action-warning" onclick="confirmAction('disable', <?= $row['id'] ?>, '<?= $fullName ?>')">
                                            <i class="bi bi-pause-circle"></i> Disable
                                        </button>
                                    <?php else: ?>
                                        <button class="btn-minimal btn-sm-minimal btn-action-success" onclick="confirmAction('activate', <?= $row['id'] ?>, '<?= $fullName ?>')">
                                            <i class="bi bi-play-circle"></i> Activate
                                        </button>
                                    <?php endif; ?>
                                    <button class="btn-minimal btn-sm-minimal btn-action-danger" onclick="confirmAction('delete', <?= $row['id'] ?>, '<?= $fullName ?>')">
                                        <i class="bi bi-trash"></i> Delete
                                    </button>
                                </div>
                            </td>
                        </tr>
                    <?php endwhile; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="7">
                            <div class="empty-state">
                                <i class="bi bi-people"></i>
                                <p>No users found.</p>
                            </div>
                        </td>
                    </tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- View User Modal -->
<div class="modal fade" id="viewUserModal" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header modal-header-info">
        <h5 class="modal-title"><i class="bi bi-person-circle"></i> User Details</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <div class="detail-row">
          <div class="detail-label">Name</div>
          <div class="detail-value" id="detailName"></div>
        </div>
        <div class="detail-row">
          <div class="detail-label">Email</div>
          <div class="detail-value" id="detailEmail"></div>
        </div>
        <div class="detail-row">
          <div class="detail-label">Role</div>
          <div class="detail-value" id="detailRole"></div>
        </div>
        <div class="detail-row">
          <div class="detail-label">College/Office</div>
          <div class="detail-value" id="detailOffice"></div>
        </div>
        <div class="detail-row">
          <div class="detail-label">Type</div>
          <div class="detail-value" id="detailType"></div>
        </div>
        <div class="detail-row">
          <div class="detail-label">Status</div>
          <div class="detail-value" id="detailStatus"></div>
        </div>
        <div class="detail-row">
          <div class="detail-label">Created</div>
          <div class="detail-value" id="detailCreated"></div>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn-minimal btn-secondary-minimal" data-bs-dismiss="modal">Close</button>
      </div>
    </div>
  </div>
</div>

<!-- Add User Modal -->
<div class="modal fade" id="addUserModal" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <form method="POST">
        <div class="modal-header modal-header-success">
          <h5 class="modal-title"><i class="bi bi-person-plus"></i> Add New User</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <div class="row g-3">
              <div class="col-md-4">
                <label class="form-label-minimal">First Name</label>
                <input type="text" name="first_name" class="form-control form-control-minimal" required>
              </div>
              <div class="col-md-4">
                <label class="form-label-minimal">Middle Name (Optional)</label>
                <input type="text" name="middle_name" class="form-control form-control-minimal">
              </div>
              <div class="col-md-4">
                <label class="form-label-minimal">Last Name</label>
                <input type="text" name="last_name" class="form-control form-control-minimal" required>
              </div>
          </div>
          <div class="mt-3">
            <label class="form-label-minimal">Email</label>
            <input type="email" name="email" class="form-control form-control-minimal" required>
          </div>
          <div class="mt-3">
            <label class="form-label-minimal">Password</label>
            <input type="password" name="password" class="form-control form-control-minimal" required>
          </div>
          <div class="mt-3">
            <label class="form-label-minimal">Role</label>
              <select name="role" class="form-select form-select-minimal" required>
                  <option value="">Select role</option>
                  <option value="supply_officer">Supply Officer</option>
                  <option value="dean">Dean</option>
                  <option value="head">Head</option>
                  <option value="requester">Requester</option>
              </select>
          </div>
          <div class="mt-3">
            <label class="form-label-minimal">College or Office</label>
              <select name="office_type" id="office_type" class="form-select form-select-minimal" onchange="toggleCollegeOffice()" required>
                  <option value="">Select Type</option>
                  <option value="College">College</option>
                  <option value="Office">Office</option>
              </select>
          </div>
          <div id="collegeOfficeField" class="mt-3" style="display:none;">
              <label class="form-label-minimal">College/Office Name</label>
              <input type="text" name="college_office_name" class="form-control form-control-minimal" placeholder="e.g., College of Computing Studies" required>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn-minimal btn-secondary-minimal" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" name="add_user" class="btn-minimal btn-primary-minimal">
            <i class="bi bi-check-lg"></i> Add User
          </button>
        </div>
      </form>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>