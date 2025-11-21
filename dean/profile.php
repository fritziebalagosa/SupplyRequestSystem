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
    <title>My Profile - WMSU OSRS</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css">
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
            background: var(--gray-50);
            color: var(--gray-900);
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Inter', system-ui;
            line-height: 1.6;
        }

        .container-main {
            max-width: 900px;
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
            margin-bottom: 1.5rem;
        }

        .section-card {
            background: #fff;
            border-radius: 12px;
            border: 1px solid var(--gray-200);
            padding: 1.5rem 1.75rem;
        }

        .form-label {
            font-weight: 600;
        }
    </style>
</head>
<body>
    <?php include('../includes/head_dean_navbar.php'); ?>
    <div class="container-main">
        <h1 class="page-title">My Profile</h1>
        <p class="page-subtitle">Manage your account information and login details.</p>
        <div class="section-card">
            <?php if ($msg): ?>
                <div class="alert alert-info mb-3"><?= htmlspecialchars($msg) ?></div>
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
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
