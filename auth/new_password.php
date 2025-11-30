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
            // Success: Redirect to their proper dashboard
            if ($_SESSION['role'] == 'admin') {
                header("Location: ../admin/dashboard.php");
            } else {
                header("Location: ../requesters/dashboard.php");
            }
            exit();
        } else {
            // Added a check for potential database errors for better debugging
            $error = "Error: Could not update password. MySQL Error: " . $conn->error;
        }
    }
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>Required Password Update</title>
    <style>
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            margin: 0;
            padding: 20px;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .password-container {
            background: white;
            padding: 40px;
            border-radius: 10px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.2);
            max-width: 400px;
            width: 100%;
        }
        h2 {
            color: #333;
            margin-bottom: 10px;
            text-align: center;
        }
        p {
            color: #666;
            margin-bottom: 30px;
            text-align: center;
        }
        .form-group {
            margin-bottom: 20px;
        }
        label {
            display: block;
            margin-bottom: 5px;
            color: #333;
            font-weight: 500;
        }
        input[type="password"] {
            width: 100%;
            padding: 12px;
            border: 1px solid #ddd;
            border-radius: 5px;
            font-size: 14px;
            box-sizing: border-box;
        }
        input[type="password"]:focus {
            outline: none;
            border-color: #667eea;
            box-shadow: 0 0 0 2px rgba(102, 126, 234, 0.2);
        }
        .btn {
            width: 100%;
            padding: 12px;
            background: #667eea;
            color: white;
            border: none;
            border-radius: 5px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: background 0.3s ease;
        }
        .btn:hover {
            background: #5a6fd8;
        }
        .error {
            background: #fee;
            color: #c33;
            padding: 10px;
            border-radius: 5px;
            margin-bottom: 20px;
            border: 1px solid #fcc;
        }
    </style>
</head>
<body>
    <div class="password-container">
        <h2>Security Update Required</h2>
        <p>Please enter a new password to secure your account and proceed.</p>
        
        <?php if (isset($error)): ?>
            <div class="error"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>
        
        <form action="" method="POST">
            <div class="form-group">
                <label for="new_password">New Password:</label>
                <input type="password" id="new_password" name="new_password" required minlength="8" placeholder="Enter at least 8 characters">
            </div>
            
            <div class="form-group">
                <label for="confirm_password">Confirm Password:</label>
                <input type="password" id="confirm_password" name="confirm_password" required minlength="8" placeholder="Re-enter your password">
            </div>
            
            <button type="submit" name="update_pass_btn" class="btn">Set New Password</button>
        </form>
    </div>
</body>
</html>