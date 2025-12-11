<?php
session_start();
include('../config/db.php');
include('../includes/csrf.php');
include('../includes/notifications.php');
include('../includes/functions.php');

// Check if user is officer
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'] ?? '', ['officer', 'supply_officer', 'supply_head'])) {
    header('Location: ../auth/log_in.php');
    exit;
}

$user_id = $_SESSION['user_id'];
$message = '';
$message_type = '';

// Handle marking notifications as read
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $message = 'Invalid security token.';
        $message_type = 'danger';
    } else {
        if ($_POST['action'] === 'mark_read') {
            $notification_ids = $_POST['notification_ids'] ?? [];
            if (empty($notification_ids)) {
                // Mark all as read
                mark_notifications_read($conn, $user_id);
                $message = 'All notifications marked as read.';
                $message_type = 'success';
            } else {
                // Mark specific notifications as read
                mark_notifications_read($conn, $user_id, $notification_ids);
                $message = 'Selected notifications marked as read.';
                $message_type = 'success';
            }
        }
    }
}

// Get notifications
$notifications = get_user_notifications($conn, $user_id, 50);
$unread_count = get_unread_notification_count($conn, $user_id);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Notifications - WMSU OSRS</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        :root {
            --red-primary: #dc3545;
            --red-dark: #c82333;
            --gray-50: #fafafa;
            --gray-100: #f5f5f5;
            --gray-200: #eeeeee;
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

        .page-header {
            margin-bottom: 2rem;
        }

        .page-title {
            font-size: 1.75rem;
            font-weight: 600;
            color: var(--gray-900);
            margin-bottom: 0.5rem;
        }

        .notification-card {
            background: white;
            border-radius: 12px;
            border: 1px solid var(--gray-200);
            padding: 1.5rem;
            margin-bottom: 1rem;
            transition: all 0.2s ease;
        }

        .notification-card:hover {
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
        }

        .notification-card.unread {
            border-left: 4px solid var(--red-primary);
            background-color: #fff5f5;
        }

        .notification-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 0.75rem;
        }

        .notification-type {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            font-size: 0.875rem;
            font-weight: 500;
        }

        .type-approved {
            background-color: #d4edda;
            color: #155724;
        }

        .type-rejected {
            background-color: #f8d7da;
            color: #721c24;
        }

        .type-returned {
            background-color: #fff3cd;
            color: #856404;
        }

        .type-adjusted {
            background-color: #d1ecf1;
            color: #0c5460;
        }

        .type-schedule_adjusted {
            background-color: #d1ecf1;
            color: #0c5460;
        }

        .type-low_stock {
            background-color: #f8d7da;
            color: #721c24;
        }

        .type-general {
            background-color: #e2e3e5;
            color: #383d41;
        }

        .notification-time {
            color: var(--gray-700);
            font-size: 0.875rem;
        }

        .notification-message {
            color: var(--gray-900);
            line-height: 1.5;
            margin-bottom: 1rem;
        }

        .notification-actions {
            display: flex;
            gap: 0.5rem;
            align-items: center;
        }

        .btn-minimal {
            padding: 0.5rem 1rem;
            border-radius: 8px;
            border: 1px solid var(--gray-300);
            background: white;
            color: var(--gray-700);
            text-decoration: none;
            font-size: 0.875rem;
            font-weight: 500;
            transition: all 0.2s ease;
        }

        .btn-minimal:hover {
            background-color: var(--gray-100);
            color: var(--gray-900);
            text-decoration: none;
        }

        .btn-primary {
            background-color: var(--red-primary);
            border-color: var(--red-primary);
            color: white;
            padding: 0.5rem 1rem;
            border-radius: 8px;
            font-weight: 500;
            transition: all 0.2s ease;
        }

        .btn-primary:hover {
            background-color: var(--red-dark);
            border-color: var(--red-dark);
        }

        .empty-state {
            text-align: center;
            padding: 3rem 1rem;
            color: var(--gray-700);
        }

        .empty-state i {
            font-size: 3rem;
            margin-bottom: 1rem;
            color: var(--gray-200);
        }

        .form-check {
            margin-bottom: 0;
        }

        .bulk-actions {
            background: white;
            border-radius: 12px;
            border: 1px solid var(--gray-200);
            padding: 1rem;
            margin-bottom: 1.5rem;
        }
    </style>
</head>
<body>
    <?php include('../includes/officer_navbar.php'); ?>
    
    <div class="container-main">
        <div class="page-header">
            <h1 class="page-title">Notifications</h1>
            <p class="text-muted">You have <?= $unread_count ?> unread notification<?= $unread_count !== 1 ? 's' : '' ?></p>
        </div>

        <?php if ($message): ?>
            <div class="alert alert-<?= $message_type ?> alert-dismissible fade show" role="alert">
                <?= $message ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <?php if (!empty($notifications)): ?>
            <!-- Bulk Actions -->
            <div class="bulk-actions">
                <form method="POST" style="display: flex; justify-content: space-between; align-items: center;">
                    <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
                    <input type="hidden" name="action" value="mark_read">
                    
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" id="selectAll">
                        <label class="form-check-label" for="selectAll">
                            Select all notifications
                        </label>
                    </div>
                    
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-check2"></i> Mark Selected as Read
                    </button>
                </form>
            </div>

            <!-- Notifications List -->
            <form method="POST" id="notificationsForm">
                <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
                <input type="hidden" name="action" value="mark_read">
                
                <?php foreach ($notifications as $notification): ?>
                    <div class="notification-card <?= $notification['read'] ? '' : 'unread' ?>">
                        <div class="notification-header">
                            <div>
                                <span class="notification-type type-<?= $notification['type'] ?>">
                                    <i class="bi bi-<?= get_notification_icon($notification['type']) ?>"></i>
                                    <?= get_notification_label($notification['type']) ?>
                                </span>
                                <?php if ($notification['request_id']): ?>
                                    <a href="view_request.php?id=<?= $notification['request_id'] ?>" class="btn-minimal" style="margin-left: 0.5rem;">
                                        <i class="bi bi-eye"></i> View Request
                                    </a>
                                <?php endif; ?>
                            </div>
                            <div class="notification-time">
                                <?= format_notification_time($notification['created_at']) ?>
                            </div>
                        </div>
                        
                        <div class="notification-message">
                            <?= nl2br(htmlspecialchars($notification['message'])) ?>
                        </div>
                        
                        <div class="notification-actions">
                            <?php if (!$notification['read']): ?>
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" name="notification_ids[]" value="<?= $notification['id'] ?>">
                                    <label class="form-check-label">Mark as read</label>
                                </div>
                            <?php else: ?>
                                <span class="text-muted"><i class="bi bi-check-circle"></i> Read</span>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </form>
        <?php else: ?>
            <div class="empty-state">
                <i class="bi bi-bell"></i>
                <h3>No Notifications</h3>
                <p>You're all caught up! No new notifications to show.</p>
            </div>
        <?php endif; ?>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Select all functionality
        document.getElementById('selectAll').addEventListener('change', function() {
            const checkboxes = document.querySelectorAll('input[name="notification_ids[]"]');
            checkboxes.forEach(checkbox => {
                checkbox.checked = this.checked;
            });
        });

        // Update select all when individual checkboxes change
        document.querySelectorAll('input[name="notification_ids[]"]').forEach(checkbox => {
            checkbox.addEventListener('change', function() {
                const allCheckboxes = document.querySelectorAll('input[name="notification_ids[]"]');
                const checkedBoxes = document.querySelectorAll('input[name="notification_ids[]"]:checked');
                const selectAll = document.getElementById('selectAll');
                
                selectAll.checked = allCheckboxes.length === checkedBoxes.length;
                selectAll.indeterminate = checkedBoxes.length > 0 && checkedBoxes.length < allCheckboxes.length;
            });
        });

        // Auto-submit form when checkboxes are selected
        document.querySelectorAll('input[name="notification_ids[]"]').forEach(checkbox => {
            checkbox.addEventListener('change', function() {
                if (this.checked) {
                    // Submit after a short delay to allow multiple selections
                    setTimeout(() => {
                        if (this.checked) {
                            document.getElementById('notificationsForm').submit();
                        }
                    }, 500);
                }
            });
        });
    </script>
</body>
</html>

<?php
function get_notification_icon($type) {
    $icons = [
        'request_approved' => 'check-circle',
        'request_rejected' => 'x-circle',
        'request_returned' => 'arrow-return-left',
        'quantity_adjusted' => 'pencil-square',
        'schedule_adjusted' => 'calendar-plus',
        'low_stock' => 'exclamation-triangle',
        'general' => 'info-circle'
    ];
    return $icons[$type] ?? 'info-circle';
}

function get_notification_label($type) {
    $labels = [
        'request_approved' => 'Approved',
        'request_rejected' => 'Rejected',
        'request_returned' => 'Returned',
        'quantity_adjusted' => 'Quantity Adjusted',
        'schedule_adjusted' => 'Schedule Adjusted',
        'low_stock' => 'Low Stock',
        'general' => 'Notification'
    ];
    return $labels[$type] ?? 'Notification';
}

function format_notification_time($created_at) {
    $timestamp = strtotime($created_at);
    $now = time();
    $diff = $now - $timestamp;

    if ($diff < 60) {
        return 'Just now';
    } elseif ($diff < 3600) {
        return floor($diff / 60) . ' minute' . (floor($diff / 60) > 1 ? 's' : '') . ' ago';
    } elseif ($diff < 86400) {
        return floor($diff / 3600) . ' hour' . (floor($diff / 3600) > 1 ? 's' : '') . ' ago';
    } elseif ($diff < 604800) {
        return floor($diff / 86400) . ' day' . (floor($diff / 86400) > 1 ? 's' : '') . ' ago';
    } else {
        return date('M d, Y g:i A', $timestamp);
    }
}
?>
