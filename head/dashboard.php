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
    <title>Head Dashboard</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body { font-family: Arial, sans-serif; background: #f8f9fa; }
        .card { background: #fff; padding: 20px; border-radius: 10px; margin-bottom: 15px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
        .stats { display: flex; gap: 15px; }
        .stat { flex: 1; text-align: center; padding: 15px; border-radius: 8px; background: #e9ecef; }
        h3 { margin: 5px 0; }
        .success { color: green; }
        .error { color: red; }
        table { width: 100%; border-collapse: collapse; margin-top: 10px; }
        th, td { border-bottom: 1px solid #ddd; padding: 8px; text-align: left; }
        th { background: #f1f1f1; }
    </style>
</head>
<body>
        <div class="container mt-4">
            <h2>Welcome <?= ucfirst($role) ?> Dashboard</h2>

    <div class="stats">
        <div class="stat"><h3><?= $total_requests ?></h3><p>Total Requests</p></div>
        <div class="stat"><h3><?= $approved_requests ?></h3><p>Approved</p></div>
        <div class="stat"><h3><?= $rejected_requests ?></h3><p>Rejected</p></div>
        <div class="stat"><h3><?= $pending_requests ?></h3><p>Pending</p></div>
    </div>

    <div class="card">
        <h3>Recent Requests</h3>
        <table>
            <tr><th>ID</th><th>Title</th><th>Status</th><th>Date</th></tr>
            <?php foreach ($recent_requests as $req): ?>
                <tr>
                    <td><?= $req['id'] ?></td>
                    <td><?= htmlspecialchars($req['title']) ?></td>
                    <td><?= ucfirst($req['status']) ?></td>
                    <td><?= $req['created_at'] ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if (empty($recent_requests)) echo "<tr><td colspan='4'>No recent requests</td></tr>"; ?>
        </table>
    </div>

    <div class="card">
        <h3>Create Requester Account</h3>
        <?php if ($has_requester): ?>
            <p class="success">Requester already exists for your office.</p>
        <?php else: ?>
            <?php if (isset($success)) echo "<p class='success'>$success</p>"; ?>
            <?php if (isset($error)) echo "<p class='error'>$error</p>"; ?>
            <form method="POST">
                <input type="text" name="first_name" placeholder="First Name" required><br><br>
                <input type="text" name="middle_name" placeholder="Middle Name (optional)"><br><br>
                <input type="text" name="last_name" placeholder="Last Name" required><br><br>
                <input type="email" name="email" placeholder="Email" required><br><br>
                <input type="password" name="password" placeholder="Password" required><br><br>
                <button type="submit">Create Requester</button>
            </form>
        <?php endif; ?>
    </div>
    
    <div class="container mt-3">
        <div class="card">
            <h3>Requester Accounts</h3>
            <?php
            // fetch requesters created by this dean/head
            $rq = $conn->prepare("SELECT id, first_name, middle_name, last_name, email, status, created_at FROM users WHERE created_by = ? AND role = 'requester'");
            $rq->bind_param('i', $dean_id);
            $rq->execute();
            $requesters = $rq->get_result()->fetch_all(MYSQLI_ASSOC);
            $rq->close();
            ?>
            <table class="table table-striped">
                <thead><tr><th>ID</th><th>Name</th><th>Email</th><th>Status</th><th>Created</th><th>Action</th></tr></thead>
                <tbody>
                <?php if (empty($requesters)): ?>
                    <tr><td colspan="6">No requester accounts created yet.</td></tr>
                <?php else: foreach ($requesters as $r): ?>
                    <tr>
                        <td>#<?= htmlspecialchars($r['id']) ?></td>
                        <td><?= htmlspecialchars($r['first_name'] . ' ' . ($r['middle_name'] ? $r['middle_name'] . ' ' : '') . $r['last_name']) ?></td>
                        <td><?= htmlspecialchars($r['email']) ?></td>
                        <td><?= htmlspecialchars(ucfirst($r['status'])) ?></td>
                        <td><?= htmlspecialchars($r['created_at']) ?></td>
                        <td><a class="btn btn-sm btn-primary" href="../dean/view_requester.php?id=<?= $r['id'] ?>">View</a></td>
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
