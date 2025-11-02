<?php
session_start();
include('../config/db.php');

if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'] ?? '', ['officer','supply_officer'])) {
    header('Location: ../auth/log_in.php');
    exit;
}

$user_id = $_SESSION['user_id'];

// determine college_office_id
if (isset($_SESSION['college_office_id'])) {
    $college_office_id = $_SESSION['college_office_id'];
} else {
    $q = $conn->prepare("SELECT college_office_id FROM users WHERE id = ?");
    $q->bind_param("i", $user_id);
    $q->execute();
    $res = $q->get_result()->fetch_assoc();
    $college_office_id = $res['college_office_id'] ?? null;
    $q->close();
}

// Match dashboard "Recent Requests" exactly (no office filter)
$stmt = $conn->prepare("SELECT r.id, r.request_id, r.title, r.status, r.created_at, u.first_name, u.last_name
                        FROM requests r
                        JOIN users u ON u.id = r.requester_id
                        WHERE r.status IN ('pending_officer','for_final_approval','approved')
                        ORDER BY r.created_at DESC LIMIT 50");
$stmt->execute();
$requests = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Officer - Records</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css" rel="stylesheet">
  <style>
    :root { --red-primary:#dc3545; --gray-50:#fafafa; --gray-100:#f5f5f5; --gray-200:#eeeeee; --gray-700:#616161; }
    body{background:var(--gray-50)}
    .container-main{max-width:1400px;margin:0 auto;padding:2rem 1.5rem}
    .page-title{font-weight:600}
    .section-card{background:#fff;border:1px solid var(--gray-200);border-radius:12px;overflow:hidden}
    .table-minimal{margin:0;width:100%}
    .table-minimal thead th{background:var(--gray-50);color:#616161;font-weight:600;font-size:.75rem;text-transform:uppercase;letter-spacing:.5px;padding:1rem 1.5rem;border:none;border-bottom:1px solid var(--gray-200);text-align:left}
    .table-minimal tbody td{padding:1rem 1.5rem;color:#212121;font-size:.9375rem;border:none;border-bottom:1px solid #f5f5f5;vertical-align:middle}
    .request-id{font-family:Courier New,monospace;color:var(--red-primary);font-weight:600}
    .badge-minimal{display:inline-flex;align-items:center;padding:.35rem .75rem;border-radius:6px;font-size:.8125rem;font-weight:500;border:1px solid}
    .badge-pending{background:#fff3cd;color:#856404;border-color:#ffeaa7}
    .badge-approved{background:#d4edda;color:#155724;border-color:#c3e6cb}
    .badge-rejected{background:#f8d7da;color:#721c24;border-color:#f5c6cb}
    .btn-minimal{padding:.4rem .875rem;border-radius:6px;font-weight:500;font-size:.875rem;border:1px solid #bee5eb;transition:.2s;text-decoration:none;display:inline-flex;align-items:center;gap:.375rem;background:#d1ecf1;color:#0c5460}
    .btn-minimal:hover{background:#bee5eb;border-color:#17a2b8;color:#0c5460}
  </style>
</head>
<body>
  <?php include('../includes/officer_navbar.php'); ?>
  <div class="container-main">
    <h3 class="page-title mb-3"><i class="bi bi-clipboard-check"></i> Records</h3>

    <div class="section-card">
      <div class="table-responsive">
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
            <?php if (empty($requests)): ?>
              <tr><td colspan="6" class="text-center text-muted py-4">No records found.</td></tr>
            <?php else: foreach ($requests as $r): 
              $badge = 'badge-pending';
              if ($r['status'] === 'approved') $badge='badge-approved';
              elseif ($r['status'] === 'for_final_approval') $badge='badge-info';
            ?>
              <tr>
                <td><span class="request-id">#<?= htmlspecialchars($r['request_id'] ?: $r['id']) ?></span></td>
                <td><?= htmlspecialchars($r['title']) ?></td>
                <td><?= htmlspecialchars($r['first_name'] . ' ' . $r['last_name']) ?></td>
                <td><span class="badge-minimal <?= $badge ?>"><?= htmlspecialchars(ucwords(str_replace('_',' ',$r['status']))) ?></span></td>
                <td><?= htmlspecialchars(date('M d, Y g:i A', strtotime($r['created_at']))) ?></td>
                <td><a class="btn-minimal" href="view_request.php?id=<?= (int)$r['id'] ?>"><i class="bi bi-eye"></i> View</a></td>
              </tr>
            <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>
    </div>

  </div>
  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
