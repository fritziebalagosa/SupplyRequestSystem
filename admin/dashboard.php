<?php
include('../config/db.php');
session_start();

// Check if user is logged in
if (!isset($_SESSION['user_id']) || !isset($_SESSION['role'])) {
    header('Location: ../auth/log_in.php');
    exit();
}

// Check if user has admin role
if ($_SESSION['role'] !== 'admin') {
    header('Location: ../auth/log_in.php');
    exit();
}

// Summary counts
$total_requests = $conn->query("SELECT COUNT(*) AS total FROM requests")->fetch_assoc()['total'];
$approved_requests = $conn->query("SELECT COUNT(*) AS total FROM requests WHERE status = 'approved'")->fetch_assoc()['total'];
$rejected_requests = $conn->query("SELECT COUNT(*) AS total FROM requests WHERE status = 'rejected'")->fetch_assoc()['total'];
$pending_requests = $conn->query("SELECT COUNT(*) AS total FROM requests WHERE status IN ('pending', 'for_approval', 'forwarded')")->fetch_assoc()['total'];

// Calculate KPIs
// 1. Stock-Out Frequency (current count of items with zero or critical stock)
$stockout_query = $conn->query("
    SELECT COALESCE(COUNT(*), 0) as stockout_count
    FROM items i
    WHERE i.stock_qty <= i.reorder_level
")->fetch_assoc();
$stockout_frequency = $stockout_query ? $stockout_query['stockout_count'] : 0;

// 2. Approval Rate
$approval_rate = $total_requests > 0 ? round(($approved_requests / $total_requests) * 100, 1) : 0;

// 3. Current Active Low Stock Alerts
$active_alerts_query = $conn->query("
    SELECT COALESCE(COUNT(*), 0) as alert_count
    FROM low_stock_alerts
    WHERE status = 'open'
")->fetch_assoc();
$active_alerts = $active_alerts_query ? $active_alerts_query['alert_count'] : 0;

// 4. Top Offices/Colleges by Request Volume (last 30 days)
$top_offices_query = $conn->query("
    SELECT 
        co.name as office_name,
        COUNT(r.id) as request_count
    FROM requests r
    JOIN users u ON r.requester_id = u.id
    JOIN college_offices co ON r.college_office_id = co.id
    WHERE r.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
    GROUP BY co.id
    ORDER BY request_count DESC
    LIMIT 5
");
$top_offices = [];
$office_labels = [];
$office_data = [];
if ($top_offices_query) {
    while ($row = $top_offices_query->fetch_assoc()) {
        $top_offices[] = $row;
        $office_labels[] = $row['office_name'];
        $office_data[] = $row['request_count'];
    }
}

// 5. Top Most Requested Items (last 30 days)
$top_items_query = $conn->query("
    SELECT 
        i.item_name,
        SUM(ri.quantity) as total_quantity
    FROM request_items ri
    JOIN items i ON ri.item_id = i.id
    JOIN requests r ON ri.request_id = r.id
    WHERE r.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
    GROUP BY i.id
    ORDER BY total_quantity DESC
    LIMIT 5
");
$top_items = [];
$item_labels = [];
$item_data = [];
if ($top_items_query) {
    while ($row = $top_items_query->fetch_assoc()) {
        $top_items[] = $row;
        $item_labels[] = $row['item_name'];
        $item_data[] = $row['total_quantity'];
    }
}

// 5. Average Request Processing Time (days from submission to final action)
$processing_time_query = $conn->query("
    SELECT COALESCE(AVG(TIMESTAMPDIFF(HOUR, r.created_at, ra.created_at)), 0) as avg_hours
    FROM requests r
    JOIN request_actions ra ON r.id = ra.request_id
    WHERE ra.action_type IN ('approved', 'rejected')
    AND r.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
")->fetch_assoc();
$avg_processing_time = $processing_time_query ? round($processing_time_query['avg_hours'] / 24, 1) : 0;

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
  <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
  <style>
    /* KPI Cards */
    .kpi-card {
      background: white;
      border-radius: 12px;
      padding: 1.25rem;
      display: flex;
      align-items: flex-start;
      gap: 1rem;
      height: 100%;
      border: 1px solid var(--gray-200);
      transition: all 0.2s ease;
    }
    
    .kpi-card:hover {
      border-color: var(--red-primary);
      box-shadow: 0 4px 12px rgba(0, 0, 0, 0.05);
    }

    .kpi-icon {
      background: var(--gray-50);
      width: 48px;
      height: 48px;
      border-radius: 12px;
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 1.5rem;
      flex-shrink: 0;
    }

    .kpi-content {
      flex-grow: 1;
    }

    .kpi-label {
      font-size: 0.875rem;
      font-weight: 600;
      color: var(--gray-700);
      margin-bottom: 0.25rem;
    }

    .kpi-value {
      font-size: 1.75rem;
      font-weight: 700;
      color: var(--gray-900);
      line-height: 1.2;
    }

    .kpi-subtitle {
      font-size: 0.75rem;
      color: var(--gray-700);
      margin-top: 0.25rem;
    }

    .kpi-header {
      display: flex;
      align-items: center;
      gap: 1rem;
      margin-bottom: 1rem;
    }

    .kpi-title {
      flex-grow: 1;
    }

    .kpi-title h5 {
      font-size: 0.875rem;
      font-weight: 600;
      color: var(--gray-900);
      margin: 0;
    }

    .chart-container {
      padding-top: 0.5rem;
    }

    canvas {
      margin: 0;
    }
  </style>
</head>
<body>
<?php include('../includes/admin_sidebar.php'); ?>

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

    <!-- KPI Metrics -->
    <div class="section-card mb-4">
      <div class="section-header">
        <h2>Supply Performance Metrics <small class="text-muted">(Last 30 Days)</small></h2>
      </div>
      <div class="section-body p-3">
        <div class="row g-4">
          <div class="col-md-4">
            <div class="kpi-card">
              <div class="kpi-icon text-danger">
                <i class="bi bi-exclamation-diamond"></i>
              </div>
              <div class="kpi-content">
                <div class="kpi-label">Critical Stock Items</div>
                <div class="kpi-value"><?php echo $stockout_frequency; ?></div>
                <div class="kpi-subtitle">Items at or below reorder level</div>
              </div>
            </div>
          </div>
          <div class="col-md-4">
            <div class="kpi-card">
              <div class="kpi-icon text-success">
                <i class="bi bi-check-circle"></i>
              </div>
              <div class="kpi-content">
                <div class="kpi-label">Approval Rate</div>
                <div class="kpi-value"><?php echo $approval_rate; ?>%</div>
                <div class="kpi-subtitle">Of total requests approved</div>
              </div>
            </div>
          </div>
          <div class="col-md-6">
            <div class="kpi-card">
              <div class="kpi-header">
                <div class="kpi-icon text-info">
                  <i class="bi bi-building"></i>
                </div>
                <div class="kpi-title">
                  <h5>Top Requesting Offices</h5>
                  <span class="kpi-subtitle">Last 30 Days</span>
                </div>
              </div>
              <div class="chart-container">
                <canvas id="officesChart" height="150"></canvas>
              </div>
            </div>
          </div>
          <div class="col-md-6">
            <div class="kpi-card">
              <div class="kpi-header">
                <div class="kpi-icon text-warning">
                  <i class="bi bi-box-seam"></i>
                </div>
                <div class="kpi-title">
                  <h5>Most Requested Items</h5>
                  <span class="kpi-subtitle">Last 30 Days</span>
                </div>
              </div>
              <div class="chart-container">
                <canvas id="itemsChart" height="150"></canvas>
              </div>
            </div>
          </div>
          <div class="col-md-4">
            <div class="kpi-card">
              <div class="kpi-icon text-primary">
                <i class="bi bi-graph-up"></i>
              </div>
              <div class="kpi-content">
                <div class="kpi-label">Avg. Processing Time</div>
                <div class="kpi-value"><?php echo $avg_processing_time; ?> days</div>
                <div class="kpi-subtitle">Request to completion</div>
              </div>
            </div>
          </div>
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
  <script>
    // Chart configuration and data
    const chartOptions = {
      responsive: true,
      maintainAspectRatio: false,
      plugins: {
        legend: { display: false },
        tooltip: {
          backgroundColor: 'rgba(255, 255, 255, 0.9)',
          titleColor: '#212121',
          bodyColor: '#616161',
          borderColor: '#e0e0e0',
          borderWidth: 1,
          padding: 8,
          boxPadding: 6,
          usePointStyle: true,
          callbacks: {
            label: function(context) {
              return ` ${context.parsed.y || context.parsed.x} requests`;
            }
          }
        }
      },
      scales: {
        x: {
          grid: { display: false },
          ticks: { font: { size: 10 } }
        },
        y: {
          grid: { display: false },
          ticks: { font: { size: 10 } }
        }
      }
    };

    const officesChartCtx = document.getElementById('officesChart').getContext('2d');
    new Chart(officesChartCtx, {
      type: 'bar',
      data: {
        labels: <?php echo json_encode($office_labels); ?>,
        datasets: [{
          data: <?php echo json_encode($office_data); ?>,
          backgroundColor: 'rgba(220, 53, 69, 0.2)',
          borderColor: '#dc3545',
          borderWidth: 1,
          barThickness: 12
        }]
      },
      options: {
        ...chartOptions,
        scales: {
          ...chartOptions.scales,
          y: {
            ...chartOptions.scales.y,
            beginAtZero: true,
            ticks: { precision: 0, font: { size: 10 } }
          }
        }
      }
    });

    const itemsChartCtx = document.getElementById('itemsChart').getContext('2d');
    new Chart(itemsChartCtx, {
      type: 'bar',
      data: {
        labels: <?php echo json_encode($item_labels); ?>,
        datasets: [{
          data: <?php echo json_encode($item_data); ?>,
          backgroundColor: 'rgba(255, 193, 7, 0.2)',
          borderColor: '#ffc107',
          borderWidth: 1,
          barThickness: 12
        }]
      },
      options: {
        ...chartOptions,
        indexAxis: 'y',
        scales: {
          ...chartOptions.scales,
          x: {
            ...chartOptions.scales.x,
            beginAtZero: true,
            ticks: { precision: 0, font: { size: 10 } }
          }
        }
      }
    });
  </script>
</body>
</html>