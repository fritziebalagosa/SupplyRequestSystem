<?php
session_start();
include('../config/db.php');

// only dean/head can view their requesters
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'] ?? '', ['dean','head'])) {
    header('Location: ../auth/log_in.php');
    exit;
}

$user_id = $_SESSION['user_id'];
$requester_id = intval($_GET['id'] ?? 0);
if (!$requester_id) die('Invalid requester id');

// ensure the requester belongs to this dean/head
$q = $conn->prepare("SELECT id, first_name, middle_name, last_name, email, status, created_at FROM users WHERE id = ? AND role = 'requester' AND created_by = ? LIMIT 1");
$q->bind_param('ii', $requester_id, $user_id);
$q->execute();
$data = $q->get_result()->fetch_assoc();
$q->close();

if (!$data) die('Requester not found or not associated with your account.');

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Requester Details</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <style>
        body{font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Inter,system-ui;background:#fafafa;color:#212121}
        .container-main{max-width:900px;margin:0 auto;padding:2rem}
        .section-card{background:#fff;border:1px solid #eee;border-radius:10px;padding:1rem}
        .id-badge{font-family:'Courier New',monospace;color:#dc3545;font-weight:600}
    </style>
</head>
<body>
    <?php include('../includes/head_dean_navbar.php'); ?>

    <div class="container-main">
        <a href="dashboard.php" class="btn btn-sm btn-secondary mb-3">← Back to dashboard</a>
        <div class="section-card">
            <h3>Requester Details</h3>
            <p><strong>Name:</strong> <?= htmlspecialchars(trim($data['first_name'] . ' ' . ($data['middle_name'] ? $data['middle_name'] . ' ' : '') . $data['last_name'])) ?></p>
            <p><strong>Email:</strong> <?= htmlspecialchars($data['email']) ?></p>
            <p><strong>Status:</strong> <?= htmlspecialchars(ucfirst($data['status'])) ?></p>
            <p><small class="text-muted">Created: <?= htmlspecialchars(date('M d, Y g:i A', strtotime($data['created_at']))) ?></small></p>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
