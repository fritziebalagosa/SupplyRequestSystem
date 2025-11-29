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
    .page-header{display:flex;justify-content:space-between;align-items:center;margin-bottom:1.5rem}
    .page-actions{display:flex;gap:0.5rem}
  </style>
</head>
<body>
<?php include('../includes/admin_sidebar.php'); ?>
<div class="container-main">
  <div class="page-header" data-print-date="<?php echo date('F j, Y, g:i A'); ?>">
    <h3 class="page-title mb-3"><i class="bi bi-clipboard-check"></i> Records</h3>
    <div class="page-actions">
      <button class="btn-minimal btn-secondary-minimal" onclick="window.print()">
        <i class="bi bi-printer"></i> Print Document
      </button>
    </div>
  </div>

  <div class="section-card mb-4" data-section-title="For Release">
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

  <div class="section-card" data-section-title="Completed Records">
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
<style>
@media print {
    /* Hide all navigation and UI elements */
    .admin-sidebar,
    .page-actions,
    .btn,
    nav,
    footer,
    .toggle-btn,
    .brand,
    .sidebar-footer {
        display: none !important;
    }
    
    /* Document styling */
    body {
        background: white !important;
        color: black !important;
        font-family: 'Times New Roman', Times, serif !important;
        font-size: 12pt;
        line-height: 1.4;
        margin: 0;
        padding: 0;
    }
    
    .container-main {
        margin: 0 !important;
        padding: 0.1in !important;
        max-width: 8.5in !important;
        margin-left: 0 !important;
        padding-top: 0.1in !important;
    }
    
    /* Document header */
    .page-header {
        text-align: center !important;
        margin-bottom: 30px !important;
        display: block !important;
        border-bottom: 2px solid #000 !important;
        padding-bottom: 20px !important;
    }
    
    .page-title {
        font-size: 24pt !important;
        color: black !important;
        margin-bottom: 0 !important;
        font-weight: bold !important;
        text-transform: uppercase !important;
        letter-spacing: 2px !important;
    }
    
    /* Hide web tables */
    .section-card {
        border: none !important;
        box-shadow: none !important;
        margin: 0 !important;
        page-break-inside: avoid;
        display: none !important;
    }
    
    .table {
        display: none !important;
    }
    
    /* Document footer info */
    .page-header::after {
        content: "Printed on: " attr(data-print-date) " | Page: 1";
        display: block;
        font-size: 10pt;
        margin-top: 10px;
        color: #666;
        text-align: right;
    }
    
    /* Create custom document table */
    @page {
        margin: 0.5in;
    }
}
</style>

<!-- Document Content for Print -->
<div class="print-document" style="display: none;">
    <div class="document-header">
        <h1>REQUEST RECORDS</h1>
        <p>Western Mindanao State University</p>
        <p>Office Supply Request System</p>
        <p>Generated on: <?php echo date('F j, Y'); ?></p>
    </div>
    
    <div class="document-content">
        <h2>For Release</h2>
        <table class="document-table">
            <thead>
                <tr>
                    <th>Request ID</th>
                    <th>Requester</th>
                    <th>Scheduled Release</th>
                    <th>Date Approved</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
                <?php 
                // Reset and re-run the query for print
                $for_stmt = $conn->prepare($for_sql);
                if ($admin_id) {
                    $for_stmt->bind_param('i', $admin_id);
                }
                $for_stmt->execute();
                $for_print_res = $for_stmt->get_result();
                
                if ($for_print_res && $for_print_res->num_rows > 0): 
                    while($row = $for_print_res->fetch_assoc()): 
                        $dispRel = $row['rel_date'];
                        if (!$dispRel && !empty($row['appr_comment'])) {
                            if (preg_match('/Release date:\s*(\d{4}-\d{2}-\d{2})/i', $row['appr_comment'], $m)) {
                                $dispRel = $m[1];
                            }
                        }
                        if (!$dispRel) { continue; }
                ?>
                <tr>
                    <td><?= htmlspecialchars($row['request_id'] ?: $row['id']) ?></td>
                    <td><?= htmlspecialchars($row['first_name'].' '.$row['last_name']) ?></td>
                    <td><?= htmlspecialchars(date('M d, Y', strtotime($dispRel))) ?> 9:00 AM</td>
                    <td><?= htmlspecialchars(date('M d, Y', strtotime($row['created_at']))) ?></td>
                    <td>FOR RELEASE</td>
                </tr>
                <?php 
                    endwhile; 
                else: 
                ?>
                <tr>
                    <td colspan="5" style="text-align: center; font-style: italic;">No requests pending release.</td>
                </tr>
                <?php endif; ?>
            </tbody>
        </table>
        
        <h2 style="margin-top: 30px;">Completed Records</h2>
        <table class="document-table">
            <thead>
                <tr>
                    <th>Request ID</th>
                    <th>Requester</th>
                    <th>Release Date & Time</th>
                    <th>Completed On</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
                <?php 
                // Reset and re-run the query for print
                $comp_stmt = $conn->prepare($comp_sql);
                if ($admin_id) {
                    $comp_stmt->bind_param('i', $admin_id);
                }
                $comp_stmt->execute();
                $comp_print_res = $comp_stmt->get_result();
                
                if ($comp_print_res && $comp_print_res->num_rows > 0): 
                    while($row = $comp_print_res->fetch_assoc()): 
                ?>
                <tr>
                    <td><?= htmlspecialchars($row['request_id'] ?: $row['id']) ?></td>
                    <td><?= htmlspecialchars($row['first_name'].' '.$row['last_name']) ?></td>
                    <td><?= $row['release_date'] ? htmlspecialchars(date('M d, Y', strtotime($row['release_date']))) : '—' ?> 9:00 AM</td>
                    <td><?= htmlspecialchars($row['confirmed_at'] ? date('M d, Y', strtotime($row['confirmed_at'])) : '-') ?></td>
                    <td>COMPLETED</td>
                </tr>
                <?php 
                    endwhile; 
                else: 
                ?>
                <tr>
                    <td colspan="5" style="text-align: center; font-style: italic;">No completed records.</td>
                </tr>
                <?php endif; ?>
            </tbody>
        </table>
        
        <div class="document-summary">
            <h3>Summary</h3>
            <p>Pending Release: <?php echo $for_print_res->num_rows; ?> requests</p>
            <p>Completed: <?php echo $comp_print_res->num_rows; ?> requests</p>
            <p>Total Processed: <?php echo $for_print_res->num_rows + $comp_print_res->num_rows; ?> requests</p>
            <p>Generated by: System Administrator</p>
        </div>
    </div>
</div>

<style>
.print-document {
    font-family: 'Times New Roman', Times, serif;
    line-height: 1.4;
}

.document-header {
    text-align: center;
    margin-bottom: 30px;
    border-bottom: 2px solid #000;
    padding-bottom: 20px;
    margin-top: 0;
}

.document-header h1 {
    font-size: 24pt;
    font-weight: bold;
    text-transform: uppercase;
    letter-spacing: 2px;
    margin: 0 0 10px 0;
}

.document-header p {
    margin: 5px 0;
    font-size: 12pt;
}

.document-content h2 {
    font-size: 16pt;
    font-weight: bold;
    margin: 20px 0 15px 0;
    text-transform: uppercase;
    border-bottom: 1px solid #000;
    padding-bottom: 5px;
}

.document-table {
    width: 100%;
    border-collapse: collapse;
    margin: 20px 0;
    font-size: 10pt;
}

.document-table th,
.document-table td {
    border: 1px solid #000;
    padding: 8px;
    text-align: left;
}

.document-table th {
    background-color: #f5f5f5;
    font-weight: bold;
    text-align: center;
}

.document-table td {
    text-align: center;
}

.document-table td:first-child {
    text-align: left;
}

.document-table td:nth-child(2) {
    text-align: left;
}

.document-summary {
    margin-top: 30px;
    padding-top: 15px;
    border-top: 1px solid #000;
}

.document-summary h3 {
    font-size: 14pt;
    font-weight: bold;
    margin: 0 0 10px 0;
}

.document-summary p {
    margin: 5px 0;
    font-size: 11pt;
}

@media print {
    .print-document {
        display: block !important;
    }
    
    .container-main > *:not(.print-document) {
        display: none !important;
    }
}
</style>
</body>
</html>
