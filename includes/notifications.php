<?php
/**
 * Comprehensive Notification System
 * Handles all types of notifications across the system
 */

/**
 * Send notification to specific users
 */
function send_notification($conn, $user_ids, $message, $type = 'general', $request_id = null) {
    if (!is_array($user_ids)) {
        $user_ids = [$user_ids];
    }
    
    // Remove duplicates and filter valid users
    $user_ids = array_unique(array_filter($user_ids));
    
    foreach ($user_ids as $user_id) {
        $stmt = $conn->prepare("INSERT INTO notifications (user_id, request_id, message, type, created_at) 
                               VALUES (?, ?, ?, ?, NOW())");
        $stmt->bind_param("iiss", $user_id, $request_id, $message, $type);
        $stmt->execute();
        $stmt->close();
    }
}

/**
 * Send request status notifications (approved/rejected/returned)
 */
function send_request_status_notification($conn, $request_id, $status, $comment = null) {
    // Get request details
    $req_stmt = $conn->prepare("SELECT r.request_id, r.requester_id, r.college_office_id, u.first_name, u.last_name 
                                FROM requests r 
                                JOIN users u ON r.requester_id = u.id 
                                WHERE r.id = ?");
    $req_stmt->bind_param("i", $request_id);
    $req_stmt->execute();
    $request = $req_stmt->get_result()->fetch_assoc();
    $req_stmt->close();
    
    if (!$request) return;
    
    $request_ref = $request['request_id'] ?: '#' . $request_id;
    $requester_name = $request['first_name'] . ' ' . $request['last_name'];
    $college_office_id = $request['college_office_id'];
    
    // Prepare message based on status
    switch ($status) {
        case 'approved':
            $message = "Request $request_ref by $requester_name has been approved.";
            $type = 'request_approved';
            break;
        case 'rejected':
            $message = "Request $request_ref by $requester_name has been rejected.";
            $type = 'request_rejected';
            if ($comment) {
                $message .= " Reason: " . $comment;
            }
            break;
        case 'returned':
            $message = "Request $request_ref by $requester_name has been returned for revision.";
            $type = 'request_returned';
            if ($comment) {
                $message .= " Comment: " . $comment;
            }
            break;
        default:
            return;
    }
    
    // Get users to notify
    $users_to_notify = [$request['requester_id']];
    
    // Notify dean and head for this office
    $office_stmt = $conn->prepare("SELECT id, role FROM users WHERE (role = 'dean' OR role = 'head') AND college_office_id = ? AND status = 'active'");
    $office_stmt->bind_param("i", $college_office_id);
    $office_stmt->execute();
    $office_result = $office_stmt->get_result();
    while ($user = $office_result->fetch_assoc()) {
        $users_to_notify[] = $user['id'];
    }
    $office_stmt->close();
    
    // Notify admins and supply officers
    $admin_stmt = $conn->prepare("SELECT id FROM users WHERE role IN ('admin', 'supply_officer') AND status = 'active'");
    $admin_stmt->execute();
    $admin_result = $admin_stmt->get_result();
    while ($admin = $admin_result->fetch_assoc()) {
        $users_to_notify[] = $admin['id'];
    }
    $admin_stmt->close();
    
    send_notification($conn, $users_to_notify, $message, $type, $request_id);
}

/**
 * Send quantity adjustment notifications
 */
function send_quantity_adjustment_notification($conn, $request_id, $adjustments) {
    // Get request details
    $req_stmt = $conn->prepare("SELECT r.request_id, r.requester_id, r.college_office_id, u.first_name, u.last_name 
                                FROM requests r 
                                JOIN users u ON r.requester_id = u.id 
                                WHERE r.id = ?");
    $req_stmt->bind_param("i", $request_id);
    $req_stmt->execute();
    $request = $req_stmt->get_result()->fetch_assoc();
    $req_stmt->close();
    
    if (!$request) return;
    
    $request_ref = $request['request_id'] ?: '#' . $request_id;
    $requester_name = $request['first_name'] . ' ' . $request['last_name'];
    
    $message = "Quantities have been adjusted for request $request_ref by $requester_name.\n";
    $message .= "Adjustments: " . $adjustments;
    
    // Notify requester, dean, head, and admins
    $users_to_notify = [$request['requester_id']];
    
    // Add office users
    $office_stmt = $conn->prepare("SELECT id FROM users WHERE (role = 'dean' OR role = 'head') AND college_office_id = ? AND status = 'active'");
    $office_stmt->bind_param("i", $request['college_office_id']);
    $office_stmt->execute();
    $office_result = $office_stmt->get_result();
    while ($user = $office_result->fetch_assoc()) {
        $users_to_notify[] = $user['id'];
    }
    $office_stmt->close();
    
    // Add admins
    $admin_stmt = $conn->prepare("SELECT id FROM users WHERE role IN ('admin', 'supply_officer') AND status = 'active'");
    $admin_stmt->execute();
    $admin_result = $admin_stmt->get_result();
    while ($admin = $admin_result->fetch_assoc()) {
        $users_to_notify[] = $admin['id'];
    }
    $admin_stmt->close();
    
    send_notification($conn, $users_to_notify, $message, 'quantity_adjusted', $request_id);
}

/**
 * Send release schedule adjustment notifications
 */
function send_schedule_adjustment_notification($conn, $request_id, $old_datetime, $new_datetime, $reason) {
    // Get request details
    $req_stmt = $conn->prepare("SELECT r.request_id, r.requester_id, r.college_office_id, u.first_name, u.last_name 
                                FROM requests r 
                                JOIN users u ON r.requester_id = u.id 
                                WHERE r.id = ?");
    $req_stmt->bind_param("i", $request_id);
    $req_stmt->execute();
    $request = $req_stmt->get_result()->fetch_assoc();
    $req_stmt->close();
    
    if (!$request) return;
    
    $request_ref = $request['request_id'] ?: '#' . $request_id;
    $requester_name = $request['first_name'] . ' ' . $request['last_name'];
    
    $message = "Release schedule for request $request_ref by $requester_name has been adjusted.\n";
    $message .= "Previous: " . ($old_datetime ?: 'Not set') . "\n";
    $message .= "New: $new_datetime\n";
    $message .= "Reason: $reason";
    
    // Get users to notify
    $users_to_notify = [$request['requester_id']];
    
    // Add dean and head for this office
    $office_stmt = $conn->prepare("SELECT id FROM users WHERE (role = 'dean' OR role = 'head') AND college_office_id = ? AND status = 'active'");
    $office_stmt->bind_param("i", $request['college_office_id']);
    $office_stmt->execute();
    $office_result = $office_stmt->get_result();
    while ($user = $office_result->fetch_assoc()) {
        $users_to_notify[] = $user['id'];
    }
    $office_stmt->close();
    
    // Add admins
    $admin_stmt = $conn->prepare("SELECT id FROM users WHERE role IN ('admin', 'supply_officer') AND status = 'active'");
    $admin_stmt->execute();
    $admin_result = $admin_stmt->get_result();
    while ($admin = $admin_result->fetch_assoc()) {
        $users_to_notify[] = $admin['id'];
    }
    $admin_stmt->close();
    
    send_notification($conn, $users_to_notify, $message, 'schedule_adjusted', $request_id);
}

/**
 * Send low stock notifications (not called "alerts" for admin)
 */
function send_low_stock_notification($conn, $item_id, $item_name, $current_stock, $reorder_level) {
    $message = "Low stock warning: Item '$item_name' (ID: $item_id) has only $current_stock units remaining. Reorder level: $reorder_level units.";
    
    // Notify admins and supply officers
    $users_to_notify = [];
    
    $admin_stmt = $conn->prepare("SELECT id FROM users WHERE role IN ('admin', 'supply_officer') AND status = 'active'");
    $admin_stmt->execute();
    $admin_result = $admin_stmt->get_result();
    while ($admin = $admin_result->fetch_assoc()) {
        $users_to_notify[] = $admin['id'];
    }
    $admin_stmt->close();
    
    send_notification($conn, $users_to_notify, $message, 'low_stock', null);
}

/**
 * Get notifications for a user
 */
function get_user_notifications($conn, $user_id, $limit = 10, $unread_only = false) {
    $sql = "SELECT n.*, r.request_id as req_reference 
            FROM notifications n 
            LEFT JOIN requests r ON n.request_id = r.id 
            WHERE n.user_id = ?";
    
    if ($unread_only) {
        $sql .= " AND n.`read` = 0";
    }
    
    $sql .= " ORDER BY n.created_at DESC LIMIT ?";
    
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ii", $user_id, $limit);
    $stmt->execute();
    $result = $stmt->get_result();
    $notifications = $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    
    return $notifications;
}

/**
 * Mark notification(s) as read
 */
function mark_notifications_read($conn, $user_id, $notification_ids = null) {
    if ($notification_ids && is_array($notification_ids)) {
        // Mark specific notifications as read
        $placeholders = str_repeat('?,', count($notification_ids) - 1) . '?';
        $types = str_repeat('i', count($notification_ids));
        $params = array_merge([$user_id], $notification_ids);
        
        $stmt = $conn->prepare("UPDATE notifications SET `read` = 1 WHERE user_id = ? AND id IN ($placeholders)");
        $stmt->bind_param("i" . $types, ...$params);
        $stmt->execute();
        $stmt->close();
    } else {
        // Mark all notifications as read for user
        $stmt = $conn->prepare("UPDATE notifications SET `read` = 1 WHERE user_id = ?");
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $stmt->close();
    }
}

/**
 * Get unread notification count for user
 */
function get_unread_notification_count($conn, $user_id) {
    $stmt = $conn->prepare("SELECT COUNT(*) as count FROM notifications WHERE user_id = ? AND `read` = 0");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $count = $result->fetch_assoc()['count'];
    $stmt->close();
    
    return $count;
}

/**
 * Delete old notifications (cleanup)
 */
function cleanup_old_notifications($conn, $days = 30) {
    $stmt = $conn->prepare("DELETE FROM notifications WHERE created_at < DATE_SUB(NOW(), INTERVAL ? DAY) AND `read` = 1");
    $stmt->bind_param("i", $days);
    $stmt->execute();
    $stmt->close();
}
?>
