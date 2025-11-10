<?php
session_start();
include('../config/db.php');

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
        $stmt = $conn->prepare("SELECT COUNT(*) FROM requests WHERE college_office_id = ? AND (status = ? OR (? = 'pending_dean' AND (status = 'pending_head' OR status = 'pending_officer')))");
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
$pending_requests = getCount($conn, $college_office_id, 'pending_dean');

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
    <title>Dean Dashboard</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="container py-4">
    <?php include('../includes/head_dean_navbar.php'); ?>

    <h1>Welcome, <?= ucfirst($role) ?></h1>
    <p class="text-muted">Dashboard overview</p>

    <div class="row g-4 mb-4">
        <div class="col-md-3">
            <div class="card">
                <div class="card-body">
                    <h3><?= $total_requests ?></h3>
                    <p class="mb-0 text-muted">Total Requests</p>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card">
                <div class="card-body">
                    <h3><?= $approved_requests ?></h3>
                    <p class="mb-0 text-muted">Approved</p>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card">
                <div class="card-body">
                    <h3><?= $rejected_requests ?></h3>
                    <p class="mb-0 text-muted">Rejected</p>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card">
                <div class="card-body">
                    <h3><?= $pending_requests ?></h3>
                    <p class="mb-0 text-muted">Pending</p>
                </div>
            </div>
        </div>
    </div>

    <div class="card mb-4">
        <div class="card-header">
            <h3>Requester Accounts</h3>
        </div>
        <div class="card-body">
            <?php
            $rq = $conn->prepare("SELECT id, first_name, middle_name, last_name, email, status, created_at FROM users WHERE created_by = ? AND role = 'requester'");
            $rq->bind_param('i', $dean_id);
            $rq->execute();
            $requesters = $rq->get_result()->fetch_all(MYSQLI_ASSOC);
            $rq->close();
            ?>
            <div class="table-responsive">
                <table class="table">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Name</th>
                            <th>Email</th>
                            <th>Status</th>
                            <th>Created</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($requesters)): ?>
                            <tr><td colspan="6" class="text-center">No requester accounts created yet.</td></tr>
                        <?php else: foreach ($requesters as $r): ?>
                            <tr>
                                <td><?= htmlspecialchars($r['id']) ?></td>
                                <td><?= htmlspecialchars($r['first_name'].' '.($r['middle_name'] ? $r['middle_name'].' ' : '').$r['last_name']) ?></td>
                                <td><?= htmlspecialchars($r['email']) ?></td>
                                <td><?= htmlspecialchars(ucfirst($r['status'])) ?></td>
                                <td><?= htmlspecialchars($r['created_at']) ?></td>
                                <td><a class="btn btn-sm btn-primary" href="view_requester.php?id=<?= $r['id'] ?>">View</a></td>
                            </tr>
                        <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="card mb-4">
        <div class="card-header">
            <h3>Recent Requests</h3>
        </div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Title</th>
                            <th>Status</th>
                            <th>Date</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($recent_requests)): ?>
                            <tr><td colspan="5" class="text-center">No recent requests found.</td></tr>
                        <?php else: foreach ($recent_requests as $req): ?>
                            <tr>
                                <td><?= $req['id'] ?></td>
                                <td><?= htmlspecialchars($req['title']) ?></td>
                                <td><?= htmlspecialchars(ucwords(str_replace('_',' ',$req['status']))) ?></td>
                                <td><?= htmlspecialchars($req['created_at']) ?></td>
                                <td><a class="btn btn-sm btn-primary" href="view_request.php?id=<?= $req['id'] ?>">View</a></td>
                            </tr>
                        <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="card">
        <div class="card-header">
            <h3>Create Requester Account</h3>
        </div>
        <div class="card-body">
            <?php if ($has_requester): ?>
                <div class="alert alert-success">Requester already exists for your office.</div>
            <?php else: ?>
                <?php if (isset($success)): ?><div class="alert alert-success"><?= $success ?></div><?php endif; ?>
                <?php if (isset($error)): ?><div class="alert alert-danger"><?= $error ?></div><?php endif; ?>
                <form method="POST" class="row g-3">
                    <div class="col-md-4"><input type="text" name="first_name" class="form-control" placeholder="First name" required></div>
                    <div class="col-md-4"><input type="text" name="middle_name" class="form-control" placeholder="Middle name"></div>
                    <div class="col-md-4"><input type="text" name="last_name" class="form-control" placeholder="Last name" required></div>
                    <div class="col-md-6"><input type="email" name="email" class="form-control" placeholder="Email" required></div>
                    <div class="col-md-6"><input type="password" name="password" class="form-control" placeholder="Password" required></div>
                    <div class="col-12"><button class="btn btn-primary">Create Requester</button></div>
                </form>
            <?php endif; ?>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
