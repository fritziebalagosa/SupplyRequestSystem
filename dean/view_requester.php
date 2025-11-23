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
            --red-light: #f8d7da;
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
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Inter', sans-serif;
            background-color: var(--gray-50);
            color: var(--gray-900);
            line-height: 1.6;
        }

        .container-main {
            max-width: 1400px;
            margin: 0 auto;
            padding: 2rem 1.5rem;
        }

        .page-title {
            font-size: 1.75rem;
            font-weight: 600;
            color: var(--gray-900);
            letter-spacing: -0.5px;
            margin-bottom: 0.25rem;
        }

        .page-subtitle {
            color: var(--gray-700);
            font-size: 0.9375rem;
            margin-bottom: 2rem;
        }

        /* Section Cards */
        .section-card {
            background: white;
            border-radius: 12px;
            border: 1px solid var(--gray-200);
            overflow: hidden;
            margin-bottom: 2rem;
        }

        .section-header {
            padding: 1.25rem 1.5rem;
            border-bottom: 1px solid var(--gray-200);
            background: white;
        }

        .section-header h2 {
            font-size: 1.125rem;
            font-weight: 600;
            color: var(--gray-900);
            margin: 0;
        }

        .section-body {
            padding: 1.5rem;
        }

        /* Back Button */
        .back-button {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.5rem 1rem;
            background-color: white;
            color: var(--gray-700);
            border: 1px solid var(--gray-300);
            border-radius: 8px;
            text-decoration: none;
            font-size: 0.9375rem;
            font-weight: 500;
            transition: all 0.2s ease;
            margin-bottom: 1.5rem;
        }

        .back-button:hover {
            background-color: var(--gray-50);
            border-color: var(--gray-700);
            color: var(--gray-900);
        }

        /* ID Badge */
        .id-badge {
            font-family: 'Courier New', monospace;
            color: var(--red-primary);
            font-weight: 600;
        }

        /* Badges */
        .badge-minimal {
            display: inline-flex;
            align-items: center;
            padding: 0.35rem 0.75rem;
            border-radius: 6px;
            font-size: 0.8125rem;
            font-weight: 500;
            border: 1px solid;
        }

        .badge-success {
            background-color: #d4edda;
            color: #155724;
            border-color: #c3e6cb;
        }

        .badge-secondary {
            background-color: var(--gray-200);
            color: var(--gray-700);
            border-color: var(--gray-300);
        }

        /* Responsive */
        @media (max-width: 768px) {
            .container-main {
                padding: 1.5rem 1rem;
            }

            .page-title {
                font-size: 1.5rem;
            }

            .section-body {
                padding: 1.25rem;
            }
        }
    </style>
</head>
<body>
    <?php include('../includes/head_dean_navbar.php'); ?>

    <div class="container-main">
        <a href="dashboard.php" class="back-button">
            <i class="bi bi-arrow-left"></i> Back to dashboard
        </a>
        <h1 class="page-title">Requester Details</h1>
        <p class="page-subtitle">View information about a requester account created under your office.</p>
        
        <div class="section-card">
            <div class="section-header">
                <h2>Requester Information</h2>
            </div>
            <div class="section-body">
                <div class="row g-3">
                    <div class="col-md-6">
                        <strong>Name:</strong><br>
                        <span style="color: var(--gray-900); font-size: 1rem;">
                            <?= htmlspecialchars(trim($data['first_name'] . ' ' . ($data['middle_name'] ? $data['middle_name'] . ' ' : '') . $data['last_name'])) ?>
                        </span>
                    </div>
                    <div class="col-md-6">
                        <strong>Email:</strong><br>
                        <span style="color: var(--gray-900); font-size: 1rem;">
                            <?= htmlspecialchars($data['email']) ?>
                        </span>
                    </div>
                    <div class="col-md-6">
                        <strong>Status:</strong><br>
                        <span class="badge-minimal <?= $data['status'] === 'active' ? 'badge-success' : 'badge-secondary' ?>">
                            <i class="bi bi-<?= $data['status'] === 'active' ? 'check-circle' : 'x-circle' ?>"></i>
                            <?= htmlspecialchars(ucfirst($data['status'])) ?>
                        </span>
                    </div>
                    <div class="col-md-6">
                        <strong>Created:</strong><br>
                        <span style="color: var(--gray-700); font-size: 0.9375rem;">
                            <?= htmlspecialchars(date('M d, Y g:i A', strtotime($data['created_at']))) ?>
                        </span>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
