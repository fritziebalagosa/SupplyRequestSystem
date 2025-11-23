<?php
session_start();
include('../config/db.php');

if (!isset($_SESSION['user_id'])) {
    header('Location: ../auth/log_in.php');
    exit;
}

$user_id = $_SESSION['user_id'];

// fetch user
$stmt = $conn->prepare("SELECT id, first_name, middle_name, last_name, email FROM users WHERE id = ?");
$stmt->bind_param('i', $user_id);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();
$stmt->close();

$msg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $first_name = trim($_POST['first_name']);
    $middle_name = trim($_POST['middle_name']) ?: null;
    $last_name = trim($_POST['last_name']);
    $email = trim($_POST['email']);

    if (!$first_name || !$last_name || !$email) {
        $msg = 'Please fill required fields.';
    } else {
        $q = $conn->prepare("SELECT id FROM users WHERE email = ? AND id != ?");
        $q->bind_param('si', $email, $user_id);
        $q->execute();
        $q->store_result();
        if ($q->num_rows > 0) {
            $msg = 'Email already in use by another account.';
        } else {
            $q->close();
            $up = $conn->prepare("UPDATE users SET first_name = ?, middle_name = ?, last_name = ?, email = ? WHERE id = ?");
            $up->bind_param('ssssi', $first_name, $middle_name, $last_name, $email, $user_id);
            $up->execute();
            $up->close();

            if (!empty($_POST['password'])) {
                $pw = password_hash($_POST['password'], PASSWORD_DEFAULT);
                $pup = $conn->prepare("UPDATE users SET password = ? WHERE id = ?");
                $pup->bind_param('si', $pw, $user_id);
                $pup->execute();
                $pup->close();
            }

            $msg = 'Profile updated successfully.';
            $stmt = $conn->prepare("SELECT id, first_name, middle_name, last_name, email FROM users WHERE id = ?");
            $stmt->bind_param('i', $user_id);
            $stmt->execute();
            $user = $stmt->get_result()->fetch_assoc();
            $stmt->close();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>My Profile - Head</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css" rel="stylesheet">
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
        
        .section-header h2, .section-header h3 {
            font-size: 1.125rem;
            font-weight: 600;
            color: var(--gray-900);
            margin: 0;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .section-body {
            padding: 1.5rem;
        }
        
        .form-label {
            font-weight: 600;
            color: var(--gray-900);
        }
        
        .form-control {
            border: 1px solid var(--gray-300);
            border-radius: 8px;
            padding: 0.625rem 0.875rem;
            font-size: 0.9375rem;
            transition: border-color 0.2s ease;
        }
        
        .form-control:focus {
            border-color: var(--red-primary);
            box-shadow: 0 0 0 0.2rem rgba(220, 53, 69, 0.25);
        }
        
        .btn-primary {
            background-color: var(--red-primary);
            border-color: var(--red-primary);
            padding: 0.625rem 1.25rem;
            font-weight: 500;
            border-radius: 8px;
            transition: all 0.2s ease;
        }
        
        .btn-primary:hover {
            background-color: var(--red-dark);
            border-color: var(--red-dark);
            transform: translateY(-1px);
        }
        
        .alert {
            border-radius: 8px;
            border: none;
            box-shadow: 0 0.125rem 0.25rem rgba(0, 0, 0, 0.075);
        }
        
        .alert-info {
            background-color: #e7f5ff;
            color: #084298;
            border-left: 4px solid #4dabf7;
        }
        
        @media (max-width: 768px) {
            .container-main {
                padding: 1.5rem 1rem;
            }
        }
    </style>
</head>
<body>
    <?php include('../includes/head_dean_navbar.php'); ?>
    <div class="container-main">
        <h1 class="page-title">My Profile</h1>
        <p class="page-subtitle">Manage your personal information and account settings</p>
        
        <div class="section-card">
            <div class="section-header">
                <h2><i class="bi bi-person me-2"></i>Profile Information</h2>
            </div>
            <div class="section-body">
            <?php if ($msg): ?>
                <div class="alert alert-info"><?= htmlspecialchars($msg) ?></div>
            <?php endif; ?>
            <form method="POST">
                <div class="mb-3">
                    <label class="form-label">First Name</label>
                    <input class="form-control" name="first_name" value="<?= htmlspecialchars($user['first_name']) ?>" required>
                </div>
                <div class="mb-3">
                    <label class="form-label">Middle Name</label>
                    <input class="form-control" name="middle_name" value="<?= htmlspecialchars($user['middle_name']) ?>">
                </div>
                <div class="mb-3">
                    <label class="form-label">Last Name</label>
                    <input class="form-control" name="last_name" value="<?= htmlspecialchars($user['last_name']) ?>" required>
                </div>
                <div class="mb-3">
                    <label class="form-label">Email</label>
                    <input type="email" class="form-control" name="email" value="<?= htmlspecialchars($user['email']) ?>" required>
                </div>
                <div class="mb-3">
                    <label class="form-label">New Password (leave blank to keep current)</label>
                    <input type="password" class="form-control" name="password">
                </div>
                <button class="btn btn-primary">Save Changes</button>
            </form>
            </div>
        </div>
    </div>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
