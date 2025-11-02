<?php
session_start();
include('../config/db.php');

// Include navbar
include('../includes/head_dean_navbar.php');

// ✅ Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: ../auth/log_in.php');
    exit;
}

// Get user's role for personalized welcome message
$role = $_SESSION['role'] ?? '';

$dean_id = $_SESSION['user_id'];

// ✅ Ensure we have college_office_id in session
if (!isset($_SESSION['college_office_id'])) {
    $stmt = $conn->prepare("SELECT college_office_id FROM users WHERE id = ?");
    $stmt->bind_param("i", $dean_id);
    $stmt->execute();
    $stmt->bind_result($college_office_id);
    $stmt->fetch();
    $stmt->close();

    $_SESSION['college_office_id'] = $college_office_id;
} else {
    $college_office_id = $_SESSION['college_office_id'];
}

// ✅ Check if requester already exists for this dean/head
$stmt = $conn->prepare("SELECT COUNT(*) FROM users WHERE created_by = ? AND role = 'requester'");
$stmt->bind_param("i", $dean_id);
$stmt->execute();
$stmt->bind_result($existing_requester);
$stmt->fetch();
$stmt->close();

$has_requester = $existing_requester > 0;

// ✅ Handle creation of requester (with optional middle name)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$has_requester) {
    $first_name = trim($_POST['first_name']);
    $middle_name = isset($_POST['middle_name']) && $_POST['middle_name'] !== '' ? trim($_POST['middle_name']) : null;
    $last_name = trim($_POST['last_name']);
    $email = trim($_POST['email']);
    $password = password_hash($_POST['password'], PASSWORD_DEFAULT);
    $role = 'requester';
    $status = 'active';

    // Check for duplicate email
    $check = $conn->prepare("SELECT id FROM users WHERE email = ?");
    $check->bind_param("s", $email);
    $check->execute();
    $check->store_result();

    if ($check->num_rows > 0) {
        $error = "Email already exists.";
    } else {
        $stmt = $conn->prepare("
            INSERT INTO users 
            (first_name, middle_name, last_name, email, password, role, status, college_office_id, created_by, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
        ");
        $stmt->bind_param(
            "ssssssiii",
            $first_name,
            $middle_name,
            $last_name,
            $email,
            $password,
            $role,
            $status,
            $college_office_id,
            $dean_id
        );
        $stmt->execute();
        $success = "Requester account created successfully.";
        $has_requester = true;
    }
    $check->close();
}

// ✅ Dashboard counts
function getCount($conn, $college_office_id, $status = null)
{
    if ($status) {
        $stmt = $conn->prepare("SELECT COUNT(*) FROM requests WHERE college_office_id = ? AND (status = ? OR (? = 'pending_head' AND status = 'pending_officer'))");
        $stmt->bind_param("iss", $college_office_id, $status, $status);
    } else {
        $stmt = $conn->prepare("SELECT COUNT(*) FROM requests WHERE college_office_id = ?");
        $stmt->bind_param("i", $college_office_id);
    }
$count = 0; // Initialize variable

$stmt->execute();
$stmt->bind_result($count);
$stmt->fetch();
$stmt->close();

return $count;

}

$total_requests = getCount($conn, $college_office_id);
$approved_requests = getCount($conn, $college_office_id, 'approved');
$rejected_requests = getCount($conn, $college_office_id, 'rejected');
$pending_requests = getCount($conn, $college_office_id, 'pending_head');

// ✅ Recent activity
$recent_stmt = $conn->prepare("
    SELECT r.id, r.title, r.status, r.created_at
    FROM requests r
    WHERE r.college_office_id = ? 
    AND (status != 'pending_dean' OR (? = 'dean' AND status = 'pending_dean'))
    AND (status != 'pending_head' OR (? = 'head' AND status = 'pending_head'))
    ORDER BY r.created_at DESC
    LIMIT 5
");
$recent_stmt->bind_param("iss", $college_office_id, $role, $role);
$recent_stmt->execute();
$recent_requests = $recent_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$recent_stmt->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= ucfirst($role) ?> Dashboard - WMSU OSRS</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        :root { --red-primary:#dc3545; --red-dark:#c82333; --red-light:#f8d7da; --gray-50:#fafafa; --gray-100:#f5f5f5; --gray-200:#eeeeee; --gray-700:#616161; --gray-900:#212121; }
        *{margin:0;padding:0;box-sizing:border-box}
        body{font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,'Inter',sans-serif;background:var(--gray-50);color:var(--gray-900);line-height:1.6}
        .container-main{max-width:1400px;margin:0 auto;padding:2rem 1.5rem}
        .page-title{font-size:1.75rem;font-weight:600;color:var(--gray-900);letter-spacing:-.5px;margin-bottom:.25rem}
        .page-subtitle{color:var(--gray-700);font-size:.9375rem;margin-bottom:2rem}
        .summary-card{background:#fff;border-radius:12px;padding:1.5rem;border:1px solid var(--gray-200);transition:.2s;height:100%}
        .summary-card:hover{border-color:var(--red-primary);box-shadow:0 4px 12px rgba(220,53,69,.08)}
        .summary-card .label{font-size:.875rem;color:var(--gray-700);font-weight:500;margin-bottom:.5rem;text-transform:uppercase;letter-spacing:.5px}
        .summary-card .number{font-size:2.25rem;font-weight:700;color:var(--gray-900);line-height:1}
        .summary-card.card-total .number{color:var(--red-primary)}
        .summary-card.card-approved .number{color:#28a745}
        .summary-card.card-rejected .number{color:var(--red-primary)}
        .summary-card.card-pending .number{color:#ffc107}
        .section-card{background:#fff;border-radius:12px;border:1px solid var(--gray-200);overflow:hidden;margin-bottom:2rem}
        .section-header{padding:1.25rem 1.5rem;border-bottom:1px solid var(--gray-200);background:#fff}
        .section-header h2{font-size:1.125rem;font-weight:600;color:var(--gray-900);margin:0}
        .section-body{padding:0}
        .table-minimal{margin:0;width:100%}
        .table-minimal thead th{background:var(--gray-50);color:var(--gray-700);font-weight:600;font-size:.75rem;text-transform:uppercase;letter-spacing:.5px;padding:1rem 1.5rem;border:none;border-bottom:1px solid var(--gray-200);text-align:left}
        .table-minimal tbody td{padding:1rem 1.5rem;color:var(--gray-900);font-size:.9375rem;border:none;border-bottom:1px solid var(--gray-100);vertical-align:middle}
        .table-minimal tbody tr:last-child td{border-bottom:none}
        .table-minimal tbody tr:hover{background:var(--gray-50)}
        .badge-minimal{display:inline-flex;align-items:center;padding:.35rem .75rem;border-radius:6px;font-size:.8125rem;font-weight:500;border:1px solid}
        .badge-success{background:#d4edda;color:#155724;border-color:#c3e6cb}
        .badge-secondary{background:var(--gray-200);color:var(--gray-700);border-color:var(--gray-300)}
        .btn-minimal{padding:.4rem .875rem;border-radius:6px;font-weight:500;font-size:.875rem;border:1px solid;transition:.2s;text-decoration:none;display:inline-flex;align-items:center;gap:.375rem}
        .btn-action-view{background:#d1ecf1;color:#0c5460;border-color:#bee5eb}
        .btn-action-view:hover{background:#bee5eb;border-color:#17a2b8;color:#0c5460}
        .empty-state{text-align:center;padding:2rem 1.5rem;color:var(--gray-700)}
        .empty-state i{font-size:2.5rem;color:#e0e0e0;margin-bottom:.75rem}
    </style>
</head>
<body>
    <div class="container-main">
        <h1 class="page-title">Welcome, <?= ucfirst($role) ?></h1>
        <p class="page-subtitle">Dashboard overview for your college/office</p>

        <div class="row g-3 g-md-4 mb-4">
            <div class="col-6 col-lg-3"><div class="summary-card card-total"><div class="label">Total Requests</div><div class="number"><?= $total_requests ?></div></div></div>
            <div class="col-6 col-lg-3"><div class="summary-card card-approved"><div class="label">Approved</div><div class="number"><?= $approved_requests ?></div></div></div>
            <div class="col-6 col-lg-3"><div class="summary-card card-rejected"><div class="label">Rejected</div><div class="number"><?= $rejected_requests ?></div></div></div>
            <div class="col-6 col-lg-3"><div class="summary-card card-pending"><div class="label">Pending</div><div class="number"><?= $pending_requests ?></div></div></div>
        </div>

        <div class="section-card">
            <div class="section-header"><h2>Requester Accounts</h2></div>
            <div class="section-body">
                <?php
                $rq = $conn->prepare("SELECT id, first_name, middle_name, last_name, email, status, created_at FROM users WHERE created_by = ? AND role = 'requester'");
                $rq->bind_param('i', $dean_id);
                $rq->execute();
                $requesters = $rq->get_result()->fetch_all(MYSQLI_ASSOC);
                $rq->close();
                ?>
                <?php if (empty($requesters)): ?>
                    <div class="empty-state"><i class="bi bi-people"></i><p>No requester accounts created yet</p></div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-minimal"><thead><tr><th>ID</th><th>Name</th><th>Email</th><th>Status</th><th>Created</th><th>Actions</th></tr></thead><tbody>
                            <?php foreach ($requesters as $r): ?>
                            <tr>
                                <td><strong>#<?= htmlspecialchars($r['id']) ?></strong></td>
                                <td><?= htmlspecialchars($r['first_name'].' '.($r['middle_name'] ? $r['middle_name'].' ' : '').$r['last_name']) ?></td>
                                <td><?= htmlspecialchars($r['email']) ?></td>
                                <td><span class="badge-minimal <?= $r['status']==='active' ? 'badge-success' : 'badge-secondary' ?>"><i class="bi bi-<?= $r['status']==='active' ? 'check-circle' : 'x-circle'?>"></i> <?= htmlspecialchars(ucfirst($r['status'])) ?></span></td>
                                <td><?= htmlspecialchars(date('M d, Y', strtotime($r['created_at']))) ?></td>
                                <td><a class="btn-minimal btn-action-view" href="../dean/view_requester.php?id=<?= $r['id'] ?>"><i class="bi bi-eye"></i> View</a></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody></table>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <div class="section-card">
            <div class="section-header"><h2>Recent Requests</h2></div>
            <div class="section-body">
                <?php if (empty($recent_requests)): ?>
                    <div class="empty-state"><i class="bi bi-inbox"></i><p>No recent requests found</p></div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-minimal"><thead><tr><th>ID</th><th>Title</th><th>Status</th><th>Date</th><th>Actions</th></tr></thead><tbody>
                            <?php foreach ($recent_requests as $req): ?>
                            <tr>
                                <td><strong>#<?= $req['id'] ?></strong></td>
                                <td><?= htmlspecialchars($req['title']) ?></td>
                                <td><?= htmlspecialchars(ucwords(str_replace('_',' ',$req['status']))) ?></td>
                                <td><?= htmlspecialchars(date('M d, Y g:i A', strtotime($req['created_at']))) ?></td>
                                <td><a class="btn-minimal btn-action-view" href="view_request.php?id=<?= $req['id'] ?>"><i class="bi bi-eye"></i> View</a></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody></table>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <div class="section-card">
            <div class="section-header"><h2>Create Requester Account</h2></div>
            <div class="section-body" style="padding:1.5rem;">
                <?php if ($has_requester): ?>
                    <div class="empty-state"><i class="bi bi-info-circle"></i><p>Requester already exists for your office.</p></div>
                <?php else: ?>
                    <?php if (isset($success)): ?><div class="alert alert-success py-2 mb-3"><?= $success ?></div><?php endif; ?>
                    <?php if (isset($error)): ?><div class="alert alert-danger py-2 mb-3"><?= $error ?></div><?php endif; ?>
                    <form method="POST" class="row g-3">
                        <div class="col-md-4"><label class="form-label">First Name</label><input type="text" name="first_name" class="form-control" required></div>
                        <div class="col-md-4"><label class="form-label">Middle Name (Optional)</label><input type="text" name="middle_name" class="form-control"></div>
                        <div class="col-md-4"><label class="form-label">Last Name</label><input type="text" name="last_name" class="form-control" required></div>
                        <div class="col-md-6"><label class="form-label">Email</label><input type="email" name="email" class="form-control" required></div>
                        <div class="col-md-6"><label class="form-label">Password</label><input type="password" name="password" class="form-control" required></div>
                        <div class="col-12"><button type="submit" class="btn btn-danger"><i class="bi bi-person-plus"></i> Create Requester</button></div>
                    </form>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
