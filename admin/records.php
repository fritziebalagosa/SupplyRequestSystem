<?php
session_start();
include('../config/db.php');

if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'] ?? '', ['admin','supply_head'])) {
  header('Location: ../auth/log_in.php');
  exit;
}

$admin_id = (int)$_SESSION['user_id'];

// Ensure helper tables exist
$conn->query("CREATE TABLE IF NOT EXISTS release_schedule (request_id INT PRIMARY KEY, release_date DATE NOT NULL, release_time TIME DEFAULT NULL, created_at DATETIME DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
$conn->query("CREATE TABLE IF NOT EXISTS request_receipts (id INT AUTO_INCREMENT PRIMARY KEY, request_id INT NOT NULL, receiver_id INT NOT NULL, photo_path VARCHAR(255) NOT NULL, status VARCHAR(20) DEFAULT 'submitted', created_at DATETIME DEFAULT CURRENT_TIMESTAMP, confirmed_at DATETIME NULL, confirmed_by INT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// Fetch For Release (approved by me, scheduled, not yet completed)
$for_sql = "
  SELECT r.id, r.request_id, r.created_at, rs.release_date,
         u.first_name, u.last_name,
         rs.release_date AS rel_date,
         (SELECT ra2.comment FROM request_actions ra2 WHERE ra2.request_id = r.id AND ra2.action_type = 'approved' ORDER BY ra2.created_at DESC LIMIT 1) AS appr_comment
  FROM requests r
  JOIN request_actions ra ON ra.request_id = r.id AND ra.action_type = 'approved' AND ra.action_by = ?
  LEFT JOIN release_schedule rs ON rs.request_id = r.id
  JOIN users u ON u.id = r.requester_id
  WHERE r.status = 'approved'
  ORDER BY COALESCE(rs.release_date, r.created_at) DESC, r.id DESC";
$for_stmt = $conn->prepare($for_sql);
$for_stmt->bind_param('i', $admin_id);
$for_stmt->execute();
$for_res = $for_stmt->get_result();

// Fetch Completed (approved by me, and completed/receipt confirmed)
$comp_sql = "
  SELECT r.id, r.request_id, r.created_at, rs.release_date,
         u.first_name, u.last_name,
         MAX(CASE WHEN rr.status='confirmed' THEN 1 ELSE 0 END) AS has_confirmed,
         MAX(rr.confirmed_at) AS confirmed_at
  FROM requests r
  JOIN request_actions ra ON ra.request_id = r.id AND ra.action_type = 'approved' AND ra.action_by = ?
  LEFT JOIN release_schedule rs ON rs.request_id = r.id
  LEFT JOIN request_receipts rr ON rr.request_id = r.id
  JOIN users u ON u.id = r.requester_id
  GROUP BY r.id
  HAVING (MAX(CASE WHEN rr.status='confirmed' THEN 1 ELSE 0 END) = 1) OR (MIN(r.status) = 'completed')
  ORDER BY COALESCE(MAX(rr.confirmed_at), MAX(rs.release_date), MAX(r.created_at)) DESC, r.id DESC";
$comp_stmt = $conn->prepare($comp_sql);
$comp_stmt->bind_param('i', $admin_id);
$comp_stmt->execute();
$comp_res = $comp_stmt->get_result();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Records - WMSU OSRS</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css" rel="stylesheet">
  <style>
    body{background:#fafafa}
    .container-main{max-width:1400px;margin:0 auto;padding:2rem 1.5rem}
    .page-title{font-weight:600}
    /* Reuse manage_requests table styles */
    .section-card{background:#fff;border-radius:12px;border:1px solid #eee;overflow:hidden}
    .table-minimal{margin:0;width:100%}
    .table-minimal thead th{background:#fafafa;color:#616161;font-weight:600;font-size:.75rem;text-transform:uppercase;letter-spacing:.5px;padding:1rem 1.5rem;border:none;border-bottom:1px solid #eee;text-align:center}
    .table-minimal tbody td{padding:1rem 1.5rem;color:#212121;font-size:.9375rem;border:none;border-bottom:1px solid #f5f5f5;vertical-align:middle;text-align:center}
    .badge-minimal{display:inline-flex;align-items:center;padding:.35rem .75rem;border-radius:6px;font-size:.8125rem;font-weight:500;border:1px solid}
    .btn-minimal{padding:.4rem .875rem;border-radius:6px;font-weight:500;font-size:.875rem;border:1px solid #bee5eb;transition:.2s;text-decoration:none;display:inline-flex;align-items:center;gap:.375rem;background:#d1ecf1;color:#0c5460}
    .btn-sm-minimal{padding:.3rem .6rem;font-size:.8125rem}
    .btn-action-view{background:#d1ecf1;color:#0c5460;border-color:#bee5eb}
    .btn-action-edit{background:#fff3cd;color:#856404;border-color:#ffeaa7}
    .btn-minimal:hover{background:#bee5eb;border-color:#17a2b8;color:#0c5460}
    .request-id{font-family:'Courier New',monospace;font-weight:600;color:#dc3545;font-size:.875rem}
    .empty-state{text-align:center;padding:3rem 1.5rem;color:#6b7280}
    .empty-state i{font-size:3rem;color:#9ca3af;margin-bottom:1rem;display:block}
    .empty-state p{margin:0;font-size:.9375rem}
  </style>
</head>
<body>
<?php include('../includes/admin_sidebar.php'); ?>
<div class="container-main">
  <h3 class="page-title mb-3"><i class="bi bi-clipboard-check"></i> Records</h3>

  <div class="section-card mb-4">
    <div class="table-responsive">
      <table class="table table-minimal">
          <thead>
            <tr>
              <th>Request ID</th>
              <th>Requester</th>
              <th>Scheduled Release</th>
              <th>Date Approved</th>
              <th>Status</th>
              <th>Action</th>
            </tr>
          </thead>
          <tbody>
          <?php if ($for_res && $for_res->num_rows > 0): while($row = $for_res->fetch_assoc()): 
              $dispRel = $row['rel_date'];
              if (!$dispRel && !empty($row['appr_comment'])) {
                  if (preg_match('/Release date:\s*(\d{4}-\d{2}-\d{2})/i', $row['appr_comment'], $m)) {
                      $dispRel = $m[1];
                  }
              }
              if (!$dispRel) { continue; }
          ?>
            <tr>
              <td><span class="request-id">#<?= htmlspecialchars($row['request_id'] ?: $row['id']) ?></span></td>
              <td><?= htmlspecialchars($row['first_name'].' '.$row['last_name']) ?></td>
              <td><?= htmlspecialchars(date('M d, Y', strtotime($dispRel))) ?> 9:00 AM</td>
              <td><?= htmlspecialchars(date('M d, Y', strtotime($row['created_at']))) ?></td>
              <td>
                <span class="badge-minimal" style="background:#fff3cd;color:#856404;border-color:#ffeaa7;">
                  <i class="bi bi-truck"></i> For Release
                </span>
              </td>
              <td>
                <a class="btn-minimal btn-sm-minimal btn-action-view" href="view_request.php?id=<?= (int)$row['id'] ?>">
                  <i class="bi bi-eye"></i> View Details
                </a>
                <br><br>
                <a class="btn-minimal btn-sm-minimal btn-action-edit" href="adjust_schedule.php?id=<?= (int)$row['id'] ?>" title="Adjust Release Schedule">
                  <i class="bi bi-calendar-plus"></i> Adjust Schedule
                </a>
              </td>
            </tr>
          <?php endwhile; else: ?>
            <tr>
              <td colspan="6">
                <div class="empty-state">
                  <i class="bi bi-inbox"></i>
                  <p>No records in For Release.</p>
                </div>
              </td>
            </tr>
          <?php endif; ?>
          </tbody>
      </table>
    </div>
  </div>

  <div class="section-card">
    <div class="table-responsive">
      <table class="table table-minimal">
          <thead>
            <tr>
              <th>Request ID</th>
              <th>Requester</th>
              <th>Release Date & Time</th>
              <th>Completed On</th>
              <th>Status</th>
              <th>Action</th>
            </tr>
          </thead>
          <tbody>
          <?php if ($comp_res && $comp_res->num_rows > 0): while($row = $comp_res->fetch_assoc()): ?>
            <tr>
              <td><span class="request-id">#<?= htmlspecialchars($row['request_id'] ?: $row['id']) ?></span></td>
              <td><?= htmlspecialchars($row['first_name'].' '.$row['last_name']) ?></td>
              <td><?= $row['release_date'] ? htmlspecialchars(date('M d, Y', strtotime($row['release_date']))) : '—' ?> 9:00 AM</td>
              <td><?= htmlspecialchars($row['confirmed_at'] ? date('M d, Y', strtotime($row['confirmed_at'])) : '-') ?></td>
              <td>
                <span class="badge-minimal" style="background:#d4edda;color:#155724;border-color:#c3e6cb;">
                  <i class="bi bi-check-circle"></i> Completed
                </span>
              </td>
              <td>
                <a class="btn-minimal btn-sm-minimal btn-action-view" href="view_request.php?id=<?= (int)$row['id'] ?>">
                  <i class="bi bi-eye"></i> View Details
                </a>
              </td>
            </tr>
          <?php endwhile; else: ?>
            <tr>
              <td colspan="6">
                <div class="empty-state">
                  <i class="bi bi-inbox"></i>
                  <p>No completed records.</p>
                </div>
              </td>
            </tr>
          <?php endif; ?>
          </tbody>
      </table>
    </div>
  </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
