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
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        body{background:#fafafa;color:#212121;font-family:Inter,Segoe UI,Roboto,system-ui}
        .container-main{max-width:900px;margin:2rem auto;padding:1rem}
        .section-card{background:#fff;border:1px solid #eee;border-radius:12px;padding:1.25rem}
        .form-label{font-weight:600}
    </style>
</head>
<body>
    <?php include('../includes/requester_navbar.php'); ?>
    <div class="container-main">
        <div class="section-card">
            <h3>My Profile</h3>
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
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
<?php
session_start();
include('../config/db.php');

if (!isset($_SESSION['user_id'])) {
    header('Location: ../auth/log_in.php');
    exit;
}

$user_id = $_SESSION['user_id'];
$msg = '';

// Handle update
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $first = trim($_POST['first_name'] ?? '');
    $last = trim($_POST['last_name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($first === '' || $last === '' || $email === '') {
        $msg = 'Please fill out all required fields.';
    } else {
        if ($password !== '') {
            $hash = password_hash($password, PASSWORD_DEFAULT);
            $u = $conn->prepare("UPDATE users SET first_name = ?, last_name = ?, email = ?, password = ? WHERE id = ?");
            $u->bind_param('ssssi', $first, $last, $email, $hash, $user_id);
        } else {
            $u = $conn->prepare("UPDATE users SET first_name = ?, last_name = ?, email = ? WHERE id = ?");
            $u->bind_param('sssi', $first, $last, $email, $user_id);
        }
        if ($u->execute()) {
            $msg = 'Profile updated.';
            $_SESSION['first_name'] = $first;
            $_SESSION['last_name'] = $last;
        } else {
            $msg = 'Update failed: ' . htmlspecialchars($u->error);
        }
        $u->close();
    }
}

// Fetch latest data
$q = $conn->prepare("SELECT first_name, last_name, email FROM users WHERE id = ? LIMIT 1");
$q->bind_param('i', $user_id);
$q->execute();
$data = $q->get_result()->fetch_assoc();
$q->close();

$first = $data['first_name'] ?? '';
$last = $data['last_name'] ?? '';
$email = $data['email'] ?? '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Profile</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</head>
<body>
    <?php include('../includes/requester_navbar.php'); ?>
    <div class="container py-4">
        <h3>Profile Settings</h3>
        <?php if ($msg): ?><div class="alert alert-info"><?= htmlspecialchars($msg) ?></div><?php endif; ?>

        <form method="POST">
            <div class="mb-3">
                <label class="form-label">First name</label>
                <input type="text" name="first_name" class="form-control" value="<?= htmlspecialchars($first) ?>" required>
            </div>
            <div class="mb-3">
                <label class="form-label">Last name</label>
                <input type="text" name="last_name" class="form-control" value="<?= htmlspecialchars($last) ?>" required>
            </div>
            <div class="mb-3">
                <label class="form-label">Email</label>
                <input type="email" name="email" class="form-control" value="<?= htmlspecialchars($email) ?>" required>
            </div>
            <div class="mb-3">
                <label class="form-label">New password (leave blank to keep current)</label>
                <input type="password" name="password" class="form-control">
            </div>
            <div class="text-end"><button class="btn btn-primary">Save changes</button></div>
        </form>
    </div>
</body>
</html>
