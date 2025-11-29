<?php
session_start();
include('../config/db.php');
include('../includes/csrf.php');
include('../includes/notifications.php');

// Check if user is admin or supply head
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'] ?? '', ['admin', 'supply_head'])) {
    header('Location: ../auth/log_in.php');
    exit;
}

$request_id = $_GET['id'] ?? 0;
$message = '';
$message_type = '';

// Get request details
$request_stmt = $conn->prepare("SELECT r.*, u.first_name, u.last_name, rs.release_date 
                               FROM requests r 
                               JOIN users u ON r.requester_id = u.id 
                               LEFT JOIN release_schedule rs ON rs.request_id = r.id 
                               WHERE r.id = ?");
$request_stmt->bind_param("i", $request_id);
$request_stmt->execute();
$request = $request_stmt->get_result()->fetch_assoc();
$request_stmt->close();

if (!$request) {
    $_SESSION['flash_message'] = 'Request not found';
    header('Location: manage_requests.php');
    exit;
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $message = 'Invalid security token. Please try again.';
        $message_type = 'danger';
    } else {
        $release_date = $_POST['release_date'] ?? '';
        $release_time = $_POST['release_time'] ?? '';
        $comment = $_POST['comment'] ?? '';
        
        if (empty($release_date) || empty($release_time)) {
            $message = 'Please provide both release date and time';
            $message_type = 'danger';
        } elseif (empty($comment)) {
            $message = 'Please provide a reason for the adjustment';
            $message_type = 'danger';
        } else {
            // Get current schedule for comparison
            $current_stmt = $conn->prepare("SELECT release_date FROM release_schedule WHERE request_id = ?");
            $current_stmt->bind_param("i", $request_id);
            $current_stmt->execute();
            $current_schedule = $current_stmt->get_result()->fetch_assoc();
            $current_stmt->close();
            
            $old_datetime = '';
            $new_datetime = $release_date . ' ' . $release_time;
            
            if ($current_schedule) {
                $old_datetime = $current_schedule['release_date'] . ' ' . ($current_schedule['release_time'] ?? '09:00:00');
            }
            
            // Update or insert release schedule (temporary fix - only update date)
            $upsert = $conn->prepare("INSERT INTO release_schedule (request_id, release_date) 
                                     VALUES (?, ?) 
                                     ON DUPLICATE KEY UPDATE release_date = VALUES(release_date)");
            $upsert->bind_param("is", $request_id, $release_date);
            $upsert->execute();
            $upsert->close();
            
            // Log the adjustment
            $user_id = $_SESSION['user_id'];
            $role = $_SESSION['role'];
            $log_comment = "Release schedule adjusted from " . ($old_datetime ?: 'Not set') . " to " . $new_datetime . ". Reason: " . $comment;
            
            $log_stmt = $conn->prepare("INSERT INTO request_actions (request_id, action_by, role, action_type, comment, created_at) 
                                       VALUES (?, ?, ?, 'schedule_adjusted', ?, NOW())");
            $log_stmt->bind_param("iiss", $request_id, $user_id, $role, $log_comment);
            $log_stmt->execute();
            $log_stmt->close();
            
            // Send notifications to relevant users
            send_schedule_adjustment_notification($conn, $request_id, $old_datetime, $new_datetime, $comment);
            
            $message = 'Release schedule adjusted successfully!';
            $message_type = 'success';
            
            // Refresh request data
            $request_stmt = $conn->prepare("SELECT r.*, u.first_name, u.last_name, rs.release_date 
                                           FROM requests r 
                                           JOIN users u ON r.requester_id = u.id 
                                           LEFT JOIN release_schedule rs ON rs.request_id = r.id 
                                           WHERE r.id = ?");
            $request_stmt->bind_param("i", $request_id);
            $request_stmt->execute();
            $request = $request_stmt->get_result()->fetch_assoc();
            $request_stmt->close();
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Adjust Release Schedule - WMSU OSRS</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css" rel="stylesheet">
    <link href="../styles/admin_nav.css" rel="stylesheet">
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
            max-width: 1000px;
            margin: 0 auto;
            padding: 2rem 1.5rem;
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
            text-decoration: none;
        }

        .page-header {
            margin-bottom: 2rem;
        }

        .page-title {
            font-size: 1.75rem;
            font-weight: 600;
            color: var(--gray-900);
            margin-bottom: 0.5rem;
        }

        .page-subtitle {
            color: var(--gray-700);
            font-size: 0.9375rem;
            margin-bottom: 2rem;
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

        /* Info Cards */
        .info-card {
            background: white;
            border-radius: 12px;
            border: 1px solid var(--gray-200);
            padding: 1.5rem;
            margin-bottom: 1.5rem;
        }

        .info-card h5 {
            font-size: 1.125rem;
            font-weight: 600;
            color: var(--gray-900);
            margin-bottom: 1rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .info-row {
            display: flex;
            justify-content: space-between;
            padding: 0.75rem 0;
            border-bottom: 1px solid var(--gray-100);
        }

        .info-row:last-child {
            border-bottom: none;
        }

        .info-label {
            font-weight: 500;
            color: var(--gray-700);
        }

        .info-value {
            color: var(--gray-900);
        }

        .current-schedule {
            background-color: #fff3cd;
            border: 1px solid #ffeaa7;
            border-radius: 8px;
            padding: 1rem;
            margin-bottom: 1.5rem;
        }

        .schedule-highlight {
            font-weight: 600;
            color: #856404;
        }

        /* Form Elements */
        .form-label {
            font-weight: 500;
            color: var(--gray-700);
            margin-bottom: 0.5rem;
        }

        .form-control, .form-select {
            border: 1px solid var(--gray-300);
            border-radius: 8px;
            padding: 0.75rem;
            font-size: 0.9375rem;
            transition: border-color 0.15s ease-in-out, box-shadow 0.15s ease-in-out;
        }

        .form-control:focus, .form-select:focus {
            border-color: var(--red-primary);
            box-shadow: 0 0 0 0.2rem rgba(220, 53, 69, 0.25);
        }

        .form-text {
            font-size: 0.8rem;
            color: #6c757d;
            margin-top: 0.25rem;
        }

        /* Buttons */
        .btn-primary {
            background-color: var(--red-primary);
            border-color: var(--red-primary);
            color: white;
            padding: 0.75rem 1.5rem;
            border-radius: 8px;
            font-weight: 500;
            transition: all 0.2s ease;
        }

        .btn-primary:hover {
            background-color: var(--red-dark);
            border-color: var(--red-dark);
            transform: translateY(-1px);
        }

        .btn-secondary {
            background-color: var(--gray-200);
            border-color: var(--gray-300);
            color: var(--gray-700);
            padding: 0.75rem 1.5rem;
            border-radius: 8px;
            font-weight: 500;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            transition: all 0.2s ease;
        }

        .btn-secondary:hover {
            background-color: var(--gray-300);
            color: var(--gray-900);
            text-decoration: none;
        }

        .d-flex {
            display: flex;
        }

        .gap-2 {
            gap: 0.5rem;
        }

        .mb-3 {
            margin-bottom: 1rem;
        }

        .mb-4 {
            margin-bottom: 1.5rem;
        }

        .text-danger {
            color: var(--red-primary) !important;
        }

        .text-muted {
            color: var(--gray-700) !important;
        }

        .row {
            display: flex;
            flex-wrap: wrap;
            margin-right: -0.75rem;
            margin-left: -0.75rem;
        }

        .col-md-6 {
            flex: 0 0 50%;
            max-width: 50%;
            padding-right: 0.75rem;
            padding-left: 0.75rem;
        }
    </style>
</head>
<body>
    <?php include('../includes/admin_sidebar.php'); ?>
    
    <div class="container-main">
        <div class="page-header">
            <a href="records.php" class="back-button">
                <i class="bi bi-arrow-left"></i> Back to Records
            </a>
            <h1 class="page-title">Adjust Release Schedule</h1>
            <p class="page-subtitle">Modify the release date and time for this request</p>
        </div>

        <?php if ($message): ?>
            <div class="alert-minimal alert-<?= $message_type === 'success' ? 'success' : ($message_type === 'danger' ? 'danger' : 'info') ?>">
                <i class="bi bi-<?= $message_type === 'success' ? 'check-circle-fill' : ($message_type === 'danger' ? 'exclamation-triangle-fill' : 'info-circle-fill') ?>"></i>
                <div><?= $message ?></div>
            </div>
        <?php endif; ?>

        <!-- Request Information -->
        <div class="info-card">
            <h5><i class="bi bi-info-circle"></i> Request Information</h5>
            <div class="info-row">
                <span class="info-label">Request ID:</span>
                <span class="info-value">#<?= htmlspecialchars($request['request_id'] ?: $request['id']) ?></span>
            </div>
            <div class="info-row">
                <span class="info-label">Requester:</span>
                <span class="info-value"><?= htmlspecialchars($request['first_name'] . ' ' . $request['last_name']) ?></span>
            </div>
            <div class="info-row">
                <span class="info-label">Status:</span>
                <span class="info-value"><?= htmlspecialchars(ucwords(str_replace('_', ' ', $request['status']))) ?></span>
            </div>
            <div class="info-row">
                <span class="info-label">Date Submitted:</span>
                <span class="info-value"><?= date('M d, Y g:i A', strtotime($request['created_at'])) ?></span>
            </div>
        </div>

        <!-- Current Schedule -->
        <?php if ($request['release_date']): ?>
        <div class="current-schedule">
            <h6 class="mb-2"><i class="bi bi-calendar-check"></i> Current Release Schedule</h6>
            <div class="schedule-highlight">
                <?= date('M d, Y', strtotime($request['release_date'])) ?>
                at 9:00 AM
            </div>
        </div>
        <?php endif; ?>

        <!-- Adjustment Form -->
        <div class="info-card">
            <h5><i class="bi bi-calendar-plus"></i> New Release Schedule</h5>
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
                
                <div class="row mb-3">
                    <div class="col-md-6">
                        <label for="release_date" class="form-label">Release Date <span class="text-danger">*</span></label>
                        <input type="date" class="form-control" id="release_date" name="release_date" 
                               value="<?= $request['release_date'] ?? date('Y-m-d') ?>" required>
                    </div>
                    <div class="col-md-6">
                        <label for="release_time" class="form-label">Release Time <span class="text-danger">*</span></label>
                        <input type="time" class="form-control" id="release_time" name="release_time" 
                               value="<?= $request['release_time'] ?? '09:00' ?>" required>
                    </div>
                </div>
                
                <div class="mb-4">
                    <label for="comment" class="form-label">Reason for Adjustment <span class="text-danger">*</span></label>
                    <textarea class="form-control" id="comment" name="comment" rows="4" 
                              placeholder="Please explain why this adjustment is necessary..." required></textarea>
                    <div class="form-text">This reason will be sent to the requester, dean, and head of office.</div>
                </div>
                
                <div class="d-flex gap-2">
                    <button type="submit" name="action" value="adjust" class="btn-primary">
                        <i class="bi bi-calendar-plus"></i> Adjust Schedule
                    </button>
                    <a href="records.php" class="btn-secondary">
                        <i class="bi bi-x-circle"></i> Cancel
                    </a>
                </div>
            </form>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
