<?php
session_start();
include('../config/db.php');

// Require officer role (support both 'officer' and legacy 'supply_officer')
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'] ?? '', ['officer','supply_officer'])) {
    header('Location: ../auth/log_in.php');
    exit;
}

$officer_id = (int)$_SESSION['user_id'];

// Summary counts for officer workload
function getOfficerCount($conn, $status = null) {
    if ($status) {
        $stmt = $conn->prepare("SELECT COUNT(*) FROM requests WHERE status = ?");
        $stmt->bind_param('s', $status);
    } else {
        $stmt = $conn->prepare("SELECT COUNT(*) FROM requests");
    }
    $stmt->execute();
    $stmt->bind_result($count);
    $stmt->fetch();
    $stmt->close();
    return (int)$count;
}

$total_requests = getOfficerCount($conn);
$pending_officer = getOfficerCount($conn, 'pending_officer');
$for_final_approval = getOfficerCount($conn, 'for_final_approval');
$approved = getOfficerCount($conn, 'approved');

// Recent items relevant to officer
$recent_stmt = $conn->prepare("SELECT r.id, r.request_id, r.title, r.status, r.created_at, u.first_name, u.last_name
                               FROM requests r
                               JOIN users u ON u.id = r.requester_id
                               WHERE r.status IN ('pending_officer','for_final_approval','approved')
                               ORDER BY r.created_at DESC LIMIT 10");
$recent_stmt->execute();
$recent = $recent_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$recent_stmt->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Officer Dashboard - WMSU OSRS</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css" rel="stylesheet">
  <style>
    :root { --red-primary:#dc3545; --red-dark:#c82333; --gray-50:#fafafa; --gray-100:#f5f5f5; --gray-200:#eeeeee; --gray-700:#616161; --gray-900:#212121; }
    *{margin:0;padding:0;box-sizing:border-box}
    body{font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,'Inter',sans-serif;background:var(--gray-50);color:var(--gray-900);line-height:1.6}
    .container-main{max-width:1400px;margin:0 auto;padding:2rem 1.5rem}
    .page-title{font-size:1.75rem;font-weight:600;color:var(--gray-900);letter-spacing:-.5px;margin-bottom:.25rem}
    .page-subtitle{color:var(--gray-700);font-size:.9375rem;margin-bottom:2rem}
    .summary-card{background:#fff;border-radius:12px;padding:1.5rem;border:1px solid var(--gray-200);height:100%}
    .summary-card .label{font-size:.875rem;color:var(--gray-700);font-weight:500;margin-bottom:.5rem;text-transform:uppercase;letter-spacing:.5px}
    .summary-card .number{font-size:2.25rem;font-weight:700;line-height:1}
    .summary-card.card-total .number{color:#dc3545}
    .summary-card.card-pending .number{color:#ffc107}
    .summary-card.card-final .number{color:#0c5460}
    .summary-card.card-approved .number{color:#28a745}
    .section-card{background:#fff;border-radius:12px;border:1px solid var(--gray-200);overflow:hidden;margin-bottom:2rem}
    .section-header{padding:1.25rem 1.5rem;border-bottom:1px solid var(--gray-200);background:#fff}
    .section-header h2{font-size:1.125rem;font-weight:600;margin:0}
    .table-minimal{margin:0;width:100%}
    .table-minimal thead th{background:var(--gray-50);color:var(--gray-700);font-weight:600;font-size:.75rem;text-transform:uppercase;letter-spacing:.5px;padding:1rem 1.5rem;border:none;border-bottom:1px solid var(--gray-200);text-align:left}
    .table-minimal tbody td{padding:1rem 1.5rem;color:var(--gray-900);font-size:.9375rem;border:none;border-bottom:1px solid var(--gray-100);vertical-align:middle}
    .table-minimal tbody tr:last-child td{border-bottom:none}
    .badge-minimal{display:inline-flex;align-items:center;padding:.35rem .75rem;border-radius:6px;font-size:.8125rem;font-weight:500;border:1px solid}
    .badge-pending{background:#fff3cd;color:#856404;border-color:#ffeaa7}
    .badge-approved{background:#d4edda;color:#155724;border-color:#c3e6cb}
    .badge-info{background:#d1ecf1;color:#0c5460;border-color:#bee5eb}
    .request-id{font-family:'Courier New',monospace;font-weight:600;color:#dc3545}
    .btn-minimal{padding:.4rem .875rem;border-radius:6px;font-weight:500;font-size:.875rem;border:1px solid;transition:.2s;text-decoration:none;display:inline-flex;align-items:center;gap:.375rem}
    .btn-action-view{background:#d1ecf1;color:#0c5460;border-color:#bee5eb}
    .btn-action-view:hover{background:#bee5eb;border-color:#17a2b8;color:#0c5460}
  </style>
</head>
<body>
  <?php include('../includes/officer_navbar.php'); ?>
  <div class="container-main">
    <h1 class="page-title">Welcome, Officer</h1>
    <p class="page-subtitle">Overview of requests you manage</p>

    <div class="row g-3 g-md-4 mb-4">
      <div class="col-6 col-lg-3"><div class="summary-card card-total"><div class="label">Total</div><div class="number"><?= $total_requests ?></div></div></div>
      <div class="col-6 col-lg-3"><div class="summary-card card-pending"><div class="label">Pending Officer</div><div class="number"><?= $pending_officer ?></div></div></div>
      <div class="col-6 col-lg-3"><div class="summary-card card-final"><div class="label">For Final Approval</div><div class="number"><?= $for_final_approval ?></div></div></div>
      <div class="col-6 col-lg-3"><div class="summary-card card-approved"><div class="label">Approved</div><div class="number"><?= $approved ?></div></div></div>
    </div>

    <div class="section-card">
      <div class="section-header"><h2>Recent Requests</h2></div>
      <div class="table-responsive">
        <?php if (empty($recent)): ?>
          <div class="p-4 text-center text-muted">No recent requests.</div>
        <?php else: ?>
        <table class="table table-minimal">
          <thead>
            <tr>
              <th>Request ID</th>
              <th>Title</th>
              <th>Requester</th>
              <th>Status</th>
              <th>Submitted</th>
              <th>Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($recent as $r): 
              $badge = 'badge-pending';
              if ($r['status'] === 'approved') $badge='badge-approved';
              elseif ($r['status'] === 'for_final_approval') $badge='badge-info';
            ?>
            <tr>
              <td><span class="request-id">#<?= htmlspecialchars($r['request_id'] ?: $r['id']) ?></span></td>
              <td><?= htmlspecialchars($r['title']) ?></td>
              <td><?= htmlspecialchars($r['first_name'].' '.$r['last_name']) ?></td>
              <td><span class="badge-minimal <?= $badge ?>"><?= htmlspecialchars(ucwords(str_replace('_',' ',$r['status']))) ?></span></td>
              <td><?= htmlspecialchars(date('M d, Y g:i A', strtotime($r['created_at']))) ?></td>
              <td><a class="btn-minimal btn-action-view" href="view_request.php?id=<?= (int)$r['id'] ?>"><i class="bi bi-eye"></i> View</a></td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
        <?php endif; ?>
      </div>
    </div>
  </div>
  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
