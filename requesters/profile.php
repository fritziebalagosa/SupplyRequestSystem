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

    // Basic validation
    if (!$first_name || !$last_name || !$email) {
        $msg = 'Please fill required fields.';
    } else {
        // check email uniqueness
        $q = $conn->prepare("SELECT id FROM users WHERE email = ? AND id != ?");
        $q->bind_param('si', $email, $user_id);
        $q->execute();
        $q->store_result();
        if ($q->num_rows > 0) {
            $msg = 'Email already in use by another account.';
            $q->close();
        } else {
            $q->close();
            // update
            $up = $conn->prepare("UPDATE users SET first_name = ?, middle_name = ?, last_name = ?, email = ? WHERE id = ?");
            $up->bind_param('ssssi', $first_name, $middle_name, $last_name, $email, $user_id);
            $up->execute();
            $up->close();

            // handle password change if provided
            if (!empty($_POST['password'])) {
                $pw = password_hash($_POST['password'], PASSWORD_DEFAULT);
                $pup = $conn->prepare("UPDATE users SET password = ? WHERE id = ?");
                $pup->bind_param('si', $pw, $user_id);
                $pup->execute();
                $pup->close();
            }

            // Update session so navbar reflects changes
            $_SESSION['first_name'] = $first_name;
            $_SESSION['last_name'] = $last_name;
            $_SESSION['email'] = $email;
            $msg = 'Profile updated successfully.';
            // refresh user
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
    <title>My Profile - Requester</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css">
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

        /* Form Elements */
        .form-label-minimal {
            font-size: 0.875rem;
            color: var(--gray-700);
            font-weight: 500;
            margin-bottom: 0.5rem;
        }

        .form-control-minimal {
            border: 1px solid var(--gray-300);
            border-radius: 8px;
            padding: 0.625rem 0.875rem;
            font-size: 0.9375rem;
            transition: all 0.2s ease;
        }

        .form-control-minimal:focus {
            border-color: var(--red-primary);
            box-shadow: 0 0 0 0.2rem rgba(220, 53, 69, 0.1);
            outline: none;
        }

        /* Alert Messages */
        .alert-minimal {
            border-radius: 8px;
            padding: 1rem 1.25rem;
            margin-bottom: 1.5rem;
            border: 1px solid;
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .alert-success {
            background-color: #d4edda;
            color: #155724;
            border-color: #c3e6cb;
        }

        .alert-danger {
            background-color: var(--red-light);
            color: #721c24;
            border-color: #f5c6cb;
        }

        .alert-info {
            background-color: #d1ecf1;
            color: #0c5460;
            border-color: #bee5eb;
        }

        /* Buttons */
        .btn-primary-minimal {
            background-color: var(--red-primary);
            color: white;
            border: none;
            padding: 0.625rem 1.25rem;
            font-size: 0.9375rem;
            border-radius: 6px;
            font-weight: 500;
            transition: all 0.2s ease;
        }

        .btn-primary-minimal:hover {
            background-color: var(--red-dark);
            transform: translateY(-1px);
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
    <?php include('../includes/requester_navbar.php'); ?>
    <div class="container-main">
        <h1 class="page-title">My Profile</h1>
        <p class="page-subtitle">Manage your account information and login details.</p>
        
        <div class="section-card">
            <div class="section-header">
                <h2>Profile Information</h2>
            </div>
            <div class="section-body">
                <?php if ($msg): ?>
                    <div class="alert-minimal alert-info">
                        <i class="bi bi-info-circle-fill"></i>
                        <span><?= htmlspecialchars($msg) ?></span>
                    </div>
                <?php endif; ?>
                <form method="POST">
                    <div class="mb-3">
                        <label class="form-label-minimal">First Name <span style="color: var(--red-primary);">*</span></label>
                        <input class="form-control form-control-minimal" name="first_name" value="<?= htmlspecialchars($user['first_name']) ?>" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label-minimal">Middle Name</label>
                        <input class="form-control form-control-minimal" name="middle_name" value="<?= htmlspecialchars($user['middle_name']) ?>">
                    </div>
                    <div class="mb-3">
                        <label class="form-label-minimal">Last Name <span style="color: var(--red-primary);">*</span></label>
                        <input class="form-control form-control-minimal" name="last_name" value="<?= htmlspecialchars($user['last_name']) ?>" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label-minimal">Email <span style="color: var(--red-primary);">*</span></label>
                        <input type="email" class="form-control form-control-minimal" name="email" value="<?= htmlspecialchars($user['email']) ?>" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label-minimal">New Password (leave blank to keep current)</label>
                        <input type="password" class="form-control form-control-minimal" name="password">
                        <small style="color: var(--gray-700); font-size: 0.8125rem;">Leave blank to keep current password</small>
                    </div>
                    <button type="submit" class="btn-primary-minimal">
                        <i class="bi bi-check-circle"></i> Save Changes
                    </button>
                </form>
            </div>
        </div>
    </div>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
