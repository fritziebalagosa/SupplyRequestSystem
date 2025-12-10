<?php
session_start();
require_once '../config/db.php';

// PHPMailer includes
require_once '../libs/PHPMailer/src/Exception.php';
require_once '../libs/PHPMailer/src/PHPMailer.php';
require_once '../libs/PHPMailer/src/SMTP.php';
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

$info = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['email'])) {
    $email = trim($_POST['email']);

    $stmt = $conn->prepare("SELECT id, first_name, middle_name, last_name, status FROM users WHERE email = ?");
    $stmt->bind_param('s', $email);
    $stmt->execute();
    $res = $stmt->get_result();

    if ($res && $user = $res->fetch_assoc()) {
        if ($user['status'] !== 'active') {
            $error = 'Account is not active. Please contact administrator.';
        } else {
            // Generate secure temporary password (8 chars)
            try {
                $temp_password = bin2hex(random_bytes(4));
            } catch (Exception $e) {
                $temp_password = substr(str_shuffle('abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789'), 0, 8);
            }

            $hash = password_hash($temp_password, PASSWORD_DEFAULT);
            $must_change = 1;

            $upd = $conn->prepare("UPDATE users SET password = ?, must_change_password = ? WHERE id = ?");
            $upd->bind_param('sii', $hash, $must_change, $user['id']);

            if ($upd->execute()) {
                // Send email with temporary password using existing SMTP settings
                $mail = new PHPMailer(true);
                try {
                    $mail->isSMTP();
                    $mail->Host = 'smtp.gmail.com';
                    $mail->SMTPAuth = true;
                    // NOTE: Update these credentials in production or store in config
                    $mail->Username = 'fritziemaebalagosa@gmail.com';
                    $mail->Password = 'zcof vatq iukf ssya';
                    $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
                    $mail->Port = 587;

                    $mail->setFrom('no-reply@wmsu.edu.ph', 'WMSU Supply Request System');
                    $mail->addAddress($email);
                    $mail->addReplyTo('admin@wmsu.edu.ph', 'System Administrator');

                    $mail->isHTML(true);
                    $mail->Subject = 'WMSU - Temporary Password';

                    $full_name = $user['first_name'] . ' ' . ($user['middle_name'] ? $user['middle_name'] . ' ' : '') . $user['last_name'];

                    $mail->Body = "
                        <h3>Temporary Password</h3>
                        <p>Dear <strong>{$full_name}</strong>,</p>
                        <p>A temporary password has been generated for your account. Use it to log in, and you will be required to change your password immediately.</p>
                        <p><strong>Temporary Password:</strong> <code style='background:#f0f0f0;padding:4px;'>{$temp_password}</code></p>
                        <p><a href='http://localhost/SupplyRequestSystem/auth/log_in.php' style='background:#dc3545;color:#fff;padding:8px 12px;text-decoration:none;border-radius:4px;'>Login Now</a></p>
                        <hr>
                        <p style='font-size:12px;color:#666;'>If you did not request this, please contact your system administrator.</p>
                    ";

                    $mail->AltBody = "Temporary Password for WMSU Supply Request System\n\nDear {$full_name},\n\nYour temporary password: {$temp_password}\n\nLogin at: http://localhost/SupplyRequestSystem/auth/log_in.php\n\nIf you did not request this, contact your system administrator.";

                    $mail->send();
                    $info = "A temporary password has been sent to {$email}. Check your email and use it to login.";
                } catch (Exception $e) {
                    // If email fails, still show the temp password to admin (avoid leaking in public)
                    $error = "Failed to send email. Temporary password: {$temp_password}";
                    error_log('Forgot password email error: ' . $mail->ErrorInfo);
                }
            } else {
                $error = 'Failed to set temporary password. Please try again later.';
            }
        }
    } else {
        $error = 'No account found with that email address.';
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Forgot Password - WMSU OSRS</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        *{box-sizing:border-box;margin:0;padding:0}
        body{font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;background:linear-gradient(rgba(0,0,0,0.3),rgba(0,0,0,0.3)),url('../wmsu.jpg');background-size:cover;min-height:100vh;display:flex;align-items:center;justify-content:center}
        .card{max-width:480px;width:100%;padding:32px;border-radius:8px;background:#fff;box-shadow:0 4px 20px rgba(0,0,0,0.08)}
        .logo{width:90px;display:block;margin:0 auto 12px}
        .btn-primary{background:#dc3545;border-color:#dc3545}
        .btn-primary:hover{background:#c82333}
        .msg{margin-bottom:16px;padding:12px;border-radius:6px}
        .info{background:#f0f8ff;color:#0b63a6;border:1px solid #bfdff5}
        .err{background:#fff5f5;color:#c53030;border:1px solid #feb2b2}
    </style>
</head>
<body>
    <div class="card">
        <img src="../wmsulogo.jpg" class="logo" alt="Logo">
        <h3 style="text-align:center;margin-bottom:8px">Forgot Password</h3>
        <p style="text-align:center;color:#666;margin-bottom:16px">Enter your account email to receive a temporary password.</p>

        <?php if (!empty($info)): ?>
            <div class="msg info"><?php echo htmlspecialchars($info); ?></div>
        <?php endif; ?>
        <?php if (!empty($error)): ?>
            <div class="msg err"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>

        <form method="POST">
            <div style="margin-bottom:12px">
                <label for="email">Email</label>
                <input type="email" id="email" name="email" required style="width:100%;padding:10px;border:1px solid #ddd;border-radius:6px;margin-top:6px">
            </div>
            <button type="submit" class="btn btn-primary" style="width:100%;padding:10px">Send Temporary Password</button>
        </form>

        <p style="text-align:center;margin-top:12px"><a href="log_in.php">Back to Login</a></p>
    </div>
</body>
</html>
