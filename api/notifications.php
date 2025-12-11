<?php
session_start();
header('Content-Type: application/json');

// Basic API for marking notifications as read
if (session_status() === PHP_SESSION_NONE) session_start();
if (empty($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Not authenticated']);
    exit;
}
$user_id = $_SESSION['user_id'];

// include DB and helper
$dbPath = rtrim($_SERVER['DOCUMENT_ROOT'], DIRECTORY_SEPARATOR) . '/SupplyRequestSystem/config/db.php';
$notifPath = rtrim($_SERVER['DOCUMENT_ROOT'], DIRECTORY_SEPARATOR) . '/SupplyRequestSystem/includes/notifications.php';
if (!file_exists($dbPath)) {
    echo json_encode(['success' => false, 'message' => 'DB not found']);
    exit;
}
include_once $dbPath;
if (file_exists($notifPath)) include_once $notifPath;

$action = $_POST['action'] ?? $_GET['action'] ?? '';
if ($action === 'mark_read') {
    $ids = $_POST['ids'] ?? null;
    if ($ids) {
        // ids may be JSON string or array
        if (is_string($ids)) {
            $decoded = json_decode($ids, true);
            if (json_last_error() === JSON_ERROR_NONE) $ids = $decoded;
        }
        if (!is_array($ids)) $ids = [$ids];
        // cast to ints
        $ids = array_map('intval', $ids);
        if (function_exists('mark_notifications_read')) {
            mark_notifications_read($conn, $user_id, $ids);
            echo json_encode(['success' => true]);
            exit;
        }
    } else {
        // mark all as read
        if (function_exists('mark_notifications_read')) {
            mark_notifications_read($conn, $user_id, null);
            echo json_encode(['success' => true]);
            exit;
        }
    }
    echo json_encode(['success' => false, 'message' => 'Failed to mark read']);
    exit;
}

// Fallback
echo json_encode(['success' => false, 'message' => 'Invalid action']);
exit;
