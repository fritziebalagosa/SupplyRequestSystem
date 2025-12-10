<?php 

session_start();
require_once '../config/db.php'; 

// Check if the user is logged in (session check)
if (!isset($_SESSION['user_id'])) { 
    header("Location: log_in.php"); 
    exit(); 
}
 
if (isset($_POST['update_pass_btn'])) {
    $new_pass = $_POST['new_password'];
    $confirm_pass = $_POST['confirm_password'];
    $user_id = $_SESSION['user_id'];

    // Validate passwords match
    if ($new_pass !== $confirm_pass) {
        $error = "Passwords do not match. Please try again.";
    } elseif (strlen($new_pass) < 8) {
        $error = "Password must be at least 8 characters long.";
    } else {
        // 1. Hash the new password
        $new_hash = password_hash($new_pass, PASSWORD_DEFAULT);
        $mustChange = 0; // Clear the flag, granting normal access

        // 2. Update DB: new password AND clear the flag
        $sql = "UPDATE users SET password = ?, must_change_password = ? WHERE id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param('sii', $new_hash, $mustChange, $user_id); // string, integer, integer
        
        if ($stmt->execute()) {
            // Regenerate session id after password change to be safe
            session_regenerate_id(true);

            // Determine role: prefer session, otherwise fetch from DB as a fallback
            $role = isset($_SESSION['role']) ? $_SESSION['role'] : '';
            if (empty($role)) {
                $sqlRole = "SELECT role FROM users WHERE id = ?";
                $stmtRole = $conn->prepare($sqlRole);
                $stmtRole->bind_param('i', $user_id);
                $stmtRole->execute();
                $resRole = $stmtRole->get_result();
                if ($resRole && $rowRole = $resRole->fetch_assoc()) {
                    $role = $rowRole['role'];
                    $_SESSION['role'] = $role;
                }
            }

            switch ($role) {
                case 'admin':
                    $redirect = '../admin/dashboard.php';
                    break;
                case 'dean':
                    $redirect = '../dean/dashboard.php';
                    break;
                case 'head':
                    $redirect = '../head/dashboard.php';
                    break;
                case 'officer':
                    $redirect = '../officer/dashboard.php';
                    break;
                default:
                    $redirect = '../requesters/dashboard.php';
                    break;
            }

            header("Location: $redirect");
            exit();
        } else {
            // Added a check for potential database errors for better debugging
            $error = "Error: Could not update password. MySQL Error: " . $conn->error;
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Required Password Update</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }

        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, Cantarell, sans-serif;
            background: linear-gradient(rgba(0, 0, 0, 0.3), rgba(0, 0, 0, 0.3)), url('../wmsu.jpg');
            background-size: cover;
            background-position: center;
            background-attachment: fixed;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .login-container { width: 100%; max-width: 470px; padding: 20px; }

        .login-card {
            background: white;
            border-radius: 8px;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.1);
            padding: 40px;
        }

        .login-header { text-align: center; margin-bottom: 20px; }

        .login-header h1 { font-size: 20px; font-weight: 700; color: #000; margin-bottom: 6px; }

        .login-header p { font-size: 13px; color: #666; margin-bottom: 0; }

        .logo { width: 90px; height: auto; display: block; margin: 0 auto 12px auto; border-radius: 8px; opacity: 0.95; }

        .form-group { margin-bottom: 18px; }

        .form-group label { font-size: 14px; font-weight: 500; color: #333; margin-bottom: 8px; display: block; }

        .form-group input { width: 100%; padding: 12px 14px; border: 1px solid #ddd; border-radius: 6px; font-size: 14px; transition: all 0.3s ease; }

        .form-group input:focus { outline: none; border-color: #dc3545; box-shadow: 0 0 0 3px rgba(220, 53, 69, 0.1); }

        .login-btn { width: 100%; padding: 12px; background-color: #dc3545; color: white; border: none; border-radius: 6px; font-size: 15px; font-weight: 600; cursor: pointer; transition: all 0.3s ease; }

        .login-btn:hover { background-color: #c82333; transform: translateY(-2px); box-shadow: 0 4px 12px rgba(220, 53, 69, 0.3); }

        .error-alert { background-color: #fff5f5; border: 1px solid #feb2b2; color: #c53030; padding: 12px 14px; border-radius: 6px; font-size: 14px; margin-bottom: 18px; text-align: center; }

        @media (max-width: 480px) { .login-card { padding: 30px 20px; } .login-header h1 { font-size: 18px; } }
    </style>
</head>
<body>

<div class="login-container">
    <div class="login-card">
        <div class="login-header">
            <img src="../wmsulogo.jpg" alt="WMSU Logo" class="logo">
            <h1>Office Supply Request System</h1>
            <p>Security Update Required — please set a new password</p>
        </div>


        <?php if (isset($error)): ?>
            <div class="error-alert"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <form method="POST">
            <div class="form-group">
                <label for="new_password">New Password</label>
                <input type="password" id="new_password" name="new_password" required minlength="8" placeholder="Enter at least 8 characters">
            </div>

            <div class="form-group">
                <label for="confirm_password">Confirm Password</label>
                <input type="password" id="confirm_password" name="confirm_password" required minlength="8" placeholder="Re-enter your password">
            </div>

            <button type="submit" name="update_pass_btn" class="login-btn">Set New Password</button>
        </form>
    </div>
</div>

</body>
</html>