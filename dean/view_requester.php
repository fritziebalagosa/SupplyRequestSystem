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
    <title>Requester Details - WMSU OSRS</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <style>
        :root {
            --red-primary: #dc3545;
            --red-dark: #c82333;
            --gray-50: #fafafa;
            --gray-100: #f5f5f5;
            --gray-200: #eeeeee;
            --gray-300: #e0e0e0;
            --gray-700: #616161;
            --gray-900: #212121;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Inter,system-ui;
            background: var(--gray-50);
            color: var(--gray-900);
            line-height: 1.6;
        }

        .container-main {
            max-width:900px;
            margin:0 auto;
            padding:2rem 1.5rem;
        }

        .page-title {
            font-size:1.75rem;
            font-weight:600;
            color:var(--gray-900);
            letter-spacing:-0.5px;
            margin-bottom:0.25rem;
        }

        .page-subtitle {
            color:var(--gray-700);
            font-size:0.9375rem;
            margin-bottom:1.5rem;
        }

        .section-card {
            background:#fff;
            border-radius:12px;
            border:1px solid var(--gray-200);
            padding:1.5rem 1.75rem;
        }

        .id-badge{
            font-family:'Courier New',monospace;
            color:var(--red-primary);
            font-weight:600
        }
    </style>
</head>
<body>
    <?php include('../includes/head_dean_navbar.php'); ?>

    <div class="container-main">
        <a href="dashboard.php" class="btn btn-sm btn-secondary mb-3">
            ← Back to dashboard
        </a>
        <h1 class="page-title">Requester Details</h1>
        <p class="page-subtitle">View information about a requester account created under your office.</p>
        <div class="section-card">
            <p><strong>Name:</strong> <?= htmlspecialchars(trim($data['first_name'] . ' ' . ($data['middle_name'] ? $data['middle_name'] . ' ' : '') . $data['last_name'])) ?></p>
            <p><strong>Email:</strong> <?= htmlspecialchars($data['email']) ?></p>
            <p><strong>Status:</strong> <?= htmlspecialchars(ucfirst($data['status'])) ?></p>
            <p><small class="text-muted">Created: <?= htmlspecialchars(date('M d, Y g:i A', strtotime($data['created_at']))) ?></small></p>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
