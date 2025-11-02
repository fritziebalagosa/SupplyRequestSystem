<?php
include('../config/db.php');
session_start();

// Summary counts
$total_requests = $conn->query("SELECT COUNT(*) AS total FROM requests")->fetch_assoc()['total'];
$approved_requests = $conn->query("SELECT COUNT(*) AS total FROM requests WHERE status = 'approved'")->fetch_assoc()['total'];
$rejected_requests = $conn->query("SELECT COUNT(*) AS total FROM requests WHERE status = 'rejected'")->fetch_assoc()['total'];
$pending_requests = $conn->query("SELECT COUNT(*) AS total FROM requests WHERE status IN ('pending', 'for_approval', 'forwarded')")->fetch_assoc()['total'];

// Fetch supply officer accounts
$officers = $conn->query("
    SELECT id, first_name, middle_name, last_name, email, status, created_at
    FROM users 
    WHERE role = 'supply_officer'
");

// Fetch recent request activities
$activities = $conn->query("
    SELECT ra.*, u.first_name, u.last_name, r.id AS request_id
    FROM request_actions ra
    LEFT JOIN users u ON ra.action_by = u.id
    LEFT JOIN requests r ON ra.request_id = r.id
    ORDER BY ra.created_at DESC
    LIMIT 10
");
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Admin Dashboard</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css" rel="stylesheet">
  <link rel="stylesheet" href="../styles/admin_nav.css">
</head>
<body>
<?php include('../includes/admin_navbar.php'); ?>

  <div class="container-main">
    <h1 class="page-title">Dashboard</h1>

    <!-- Summary Cards -->
    <div class="row g-3 g-md-4 mb-4">
      <div class="col-6 col-lg-3">
        <div class="summary-card card-total">
          <div class="label">Total Requests</div>
          <div class="number"><?php echo $total_requests; ?></div>
        </div>
      </div>
      <div class="col-6 col-lg-3">
        <div class="summary-card card-approved">
          <div class="label">Approved</div>
          <div class="number"><?php echo $approved_requests; ?></div>
        </div>
      </div>
      <div class="col-6 col-lg-3">
        <div class="summary-card card-rejected">
          <div class="label">Rejected</div>
          <div class="number"><?php echo $rejected_requests; ?></div>
        </div>
      </div>
      <div class="col-6 col-lg-3">
        <div class="summary-card card-pending">
          <div class="label">Pending</div>
          <div class="number"><?php echo $pending_requests; ?></div>
        </div>
      </div>
    </div>

    <!-- Supply Officer Accounts -->
    <div class="section-card">
      <div class="section-header">
        <h2>Supply Officer Accounts</h2>
      </div>
      <div class="section-body">
        <?php if ($officers->num_rows > 0): ?>
          <div class="table-responsive">
            <table class="table table-minimal">
              <thead>
                <tr>
                  <th>ID</th>
                  <th>Name</th>
                  <th>Email</th>
                  <th>Status</th>
                  <th>Created</th>
                </tr>
              </thead>
              <tbody>
                <?php while ($row = $officers->fetch_assoc()): ?>
                  <tr>
                    <td><span class="id-badge">#<?php echo $row['id']; ?></span></td>
                    <td><?php echo htmlspecialchars($row['first_name'] . ' ' . ($row['middle_name'] ? $row['middle_name'][0] . '. ' : '') . $row['last_name']); ?></td>
                    <td><?php echo htmlspecialchars($row['email']); ?></td>
                    <td>
                      <span class="badge-minimal <?php echo $row['status'] === 'active' ? 'badge-success' : 'badge-secondary'; ?>">
                        <?php echo ucfirst(htmlspecialchars($row['status'])); ?>
                      </span>
                    </td>
                    <td><?php echo date("M d, Y", strtotime($row['created_at'])); ?></td>
                  </tr>
                <?php endwhile; ?>
              </tbody>
            </table>
          </div>
        <?php else: ?>
          <div class="empty-state">
            <i class="bi bi-people"></i>
            <p>No supply officers found</p>
          </div>
        <?php endif; ?>
      </div>
    </div>

    <!-- Recent Activities -->
    <div class="section-card">
      <div class="section-header">
        <h2>Recent Activities</h2>
      </div>
      <div class="section-body">
        <?php if ($activities->num_rows > 0): ?>
          <div class="table-responsive">
            <table class="table table-minimal">
              <thead>
                <tr>
                  <th>Request</th>
                  <th>Action By</th>
                  <th>Role</th>
                  <th>Action</th>
                  <th>Date</th>
                </tr>
              </thead>
              <tbody>
                <?php while ($a = $activities->fetch_assoc()): ?>
                  <tr>
                    <td><span class="id-badge">#<?php echo $a['request_id']; ?></span></td>
                    <td><?php echo htmlspecialchars($a['first_name'] . ' ' . $a['last_name']); ?></td>
                    <td><?php echo htmlspecialchars(ucwords(str_replace('_', ' ', $a['role']))); ?></td>
                    <td>
                      <span class="badge-minimal <?php 
                        echo match($a['action_type']) {
                          'approved' => 'badge-success',
                          'rejected' => 'badge-danger',
                          'forwarded' => 'badge-info',
                          'returned' => 'badge-warning',
                          default => 'badge-secondary'
                        };
                      ?>">
                        <?php echo ucfirst($a['action_type']); ?>
                      </span>
                    </td>
                    <td><?php echo date("M d, Y g:i A", strtotime($a['created_at'])); ?></td>
                  </tr>
                <?php endwhile; ?>
              </tbody>
            </table>
          </div>
        <?php else: ?>
          <div class="empty-state">
            <i class="bi bi-clock-history"></i>
            <p>No recent activity</p>
          </div>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>