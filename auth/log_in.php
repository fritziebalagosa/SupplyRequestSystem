<?php

// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

session_start();
require_once '../config/db.php';

// Check if form was submitted (has email and password)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['email']) && !empty($_POST['password'])) {
    $email = $_POST['email'];
    $password = $_POST['password'];
    
    // Debug: Log submission attempt
    error_log("Login attempt for email: $email");
    
    // Select required fields from the USERS table (include name/email for navbars)
    $stmt = $conn->prepare("SELECT id, password, role, must_change_password, first_name, last_name, email FROM users WHERE email = ?");
    $stmt->bind_param('s', $email);
    $stmt->execute();
    $result = $stmt->get_result();
    $user = $result->fetch_assoc();
    
    // Debug: Log user lookup result
    error_log("User found: " . ($user ? "YES" : "NO"));
    if ($user) {
        error_log("User role: " . $user['role']);
        error_log("Must change password: " . ($user['must_change_password'] ? "YES" : "NO"));
    }
    
    if ($user) {
        
        if (password_verify($password, $user['password'])) {
            // Debug: Log successful password verification
            error_log("Password verification: SUCCESS");
            
            // Password is correct. Regenerate session id and store session variables to prevent fixation.
            session_regenerate_id(true);
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['role'] = $user['role'];
            // Store user display name pieces so navbars can show the user's name
            $_SESSION['first_name'] = $user['first_name'] ?? '';
            $_SESSION['last_name'] = $user['last_name'] ?? '';
            // Ensure email is available in session as a fallback
            $_SESSION['email'] = $user['email'] ?? $email;
            
            // Debug: Log session variables
            error_log("Session set - user_id: " . $_SESSION['user_id'] . ", role: " . $_SESSION['role']);
            
            // === INTERCEPTION LOGIC ===
            if ($user['must_change_password'] == 1) {
                error_log("Redirecting to new_password.php");
                header("Location: new_password.php"); 
                exit();
            }

            // Normal Login Flow (Redirect based on role)
            // Use the actual role from the database and redirect accordingly.
            switch ($user['role']) {
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
                case 'supply_officer':
                    $redirect = '../officer/dashboard.php';
                    break;
                default:
                    $redirect = '../requesters/dashboard.php';
                    break;
            }
            error_log("Redirecting to: $redirect");
            header("Location: $redirect");
            exit();
        } else {
            error_log("Password verification: FAILED");
            $error = "Invalid email or password.";
        }
    } else {
        error_log("User lookup: FAILED");
        $error = "Invalid email or password.";
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - Office Supply Request System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

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

        .login-container {
            width: 100%;
            max-width: 470px;
            padding: 20px;
        }

        .login-card {
            background: white;
            border-radius: 8px;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.1);
            padding: 40px;
        }

        .login-header {
            text-align: center;
            margin-bottom: 30px;
        }

        .login-header h1 {
            font-size: 28px;
            font-weight: 700;
            color: #000;
            margin-bottom: 8px;
            letter-spacing: -0.5px;
        }

        .login-header p {
            font-size: 14px;
            color: #666;
        }

        .form-group {
            margin-bottom: 20px;
        }

        .form-group label {
            font-size: 14px;
            font-weight: 500;
            color: #333;
            margin-bottom: 8px;
            display: block;
        }

        .form-group input {
            width: 100%;
            padding: 12px 14px;
            border: 1px solid #ddd;
            border-radius: 6px;
            font-size: 14px;
            transition: all 0.3s ease;
        }

        .form-group input:focus {
            outline: none;
            border-color: #dc3545;
            box-shadow: 0 0 0 3px rgba(220, 53, 69, 0.1);
        }

        .login-btn {
            /* <CHANGE> Style button with red background and white text */
            width: 100%;
            padding: 12px;
            background-color: #dc3545;
            color: white;
            border: none;
            border-radius: 6px;
            font-size: 15px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            margin-top: 10px;
        }

        .login-btn:hover {
            background-color: #c82333;
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(220, 53, 69, 0.3);
        }

        .login-btn:active {
            transform: translateY(0);
        }
        .logo {
            width: 90px;
            height: auto;
            display: block;
            margin: 0 auto 18px auto;
            border-radius: 8px;
            opacity: 0.95;
        }


        .error-alert {
            background-color: #fff5f5;
            border: 1px solid #feb2b2;
            color: #c53030;
            padding: 12px 14px;
            border-radius: 6px;
            font-size: 14px;
            margin-bottom: 20px;
            text-align: center;
        }

        @media (max-width: 480px) {
            .login-card {
                padding: 30px 20px;
            }

            .login-header h1 {
                font-size: 24px;
            }
        }
    </style>
</head>
<body>

<div class="login-container">
    <div class="login-card">
        <div class="login-header">
            <img src="../wmsulogo.jpg" alt="WMSU Logo" class="logo">
            <h1>Office Supply Request System</h1>
            <p>Western Mindanao State University</p>
        </div>


        <?php if (isset($error)): ?>
            <div class="error-alert"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <form method="POST">
            <div class="form-group">
                <label for="email">Email</label>
                <input type="email" id="email" name="email" required>
            </div>

            <div class="form-group">
                <label for="password">Password</label>
                <input type="password" id="password" name="password" required>
            </div>

            <button type="submit" name="login_btn" class="login-btn">Sign In</button>
        </form>
        <p style="text-align:center;margin-top:12px"><a href="forgot_password.php">Forgot Password?</a></p>
    </div>
</div>

</body>
</html>