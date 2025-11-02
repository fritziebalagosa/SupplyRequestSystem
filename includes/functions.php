<?php
// Common helper functions
if (session_status() === PHP_SESSION_NONE) session_start();

/**
 * Get notifications for a user based on role.
 * Returns array of ['id','request_id','message','link','created_at']
 */
function get_notifications($conn, $user_id, $role, $college_office_id = null, $limit = 8) {
	$out = [];
	if (!$conn || !$user_id) return $out;

	// Ensure tables used by notifications exist when referenced
	$conn->query("CREATE TABLE IF NOT EXISTS release_schedule (
		request_id INT PRIMARY KEY,
		release_date DATE NOT NULL,
		created_at DATETIME DEFAULT CURRENT_TIMESTAMP
	) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

	if ($role === 'requester') {
		$sql = "SELECT id, request_id, status, created_at FROM requests WHERE requester_id = ? AND status IN ('returned','approved','rejected','completed') ORDER BY created_at DESC LIMIT ?";
		$stmt = $conn->prepare($sql);
		$stmt->bind_param('ii', $user_id, $limit);
	} elseif ($role === 'dean') {
		// Dean sees requests pending for dean and approved (with schedule)
		$sql = "SELECT r.id, r.request_id, r.status, r.created_at, rs.release_date
				FROM requests r
				JOIN users u ON r.requester_id = u.id
				LEFT JOIN release_schedule rs ON rs.request_id = r.id
				WHERE r.college_office_id = ? AND u.created_by = ? AND r.status IN ('pending_dean','approved')
				ORDER BY r.created_at DESC LIMIT ?";
		$stmt = $conn->prepare($sql);
		$stmt->bind_param('iii', $college_office_id, $user_id, $limit);
	} elseif ($role === 'head') {
		// Head sees requests forwarded to head and approved (with schedule)
		$sql = "SELECT r.id, r.request_id, r.status, r.created_at, rs.release_date
				FROM requests r
				JOIN users u ON r.requester_id = u.id
				LEFT JOIN release_schedule rs ON rs.request_id = r.id
				WHERE r.college_office_id = ? AND u.created_by = ? AND r.status IN ('pending_head','approved')
				ORDER BY r.created_at DESC LIMIT ?";
		$stmt = $conn->prepare($sql);
		$stmt->bind_param('iii', $college_office_id, $user_id, $limit);
	} elseif ($role === 'supply_officer' || $role === 'supply_head' || $role === 'officer') {
		// show requests assigned to officer for action
		$sql = "SELECT id, request_id, status, created_at FROM requests WHERE college_office_id = ? AND status IN ('pending_officer','for_final_approval') ORDER BY created_at DESC LIMIT ?";
		$stmt = $conn->prepare($sql);
		$stmt->bind_param('ii', $college_office_id, $limit);
	} else {
		return $out;
	}

	if ($stmt && $stmt->execute()) {
		$res = $stmt->get_result();
		while ($row = $res->fetch_assoc()) {
			$msg = '';
			switch ($row['status']) {
				case 'returned': $msg = "Request {$row['request_id']} was returned to you"; break;
				case 'approved': $msg = "Request {$row['request_id']} was approved"; break;
				case 'rejected': $msg = "Request {$row['request_id']} was rejected"; break;
				case 'completed': $msg = "Request {$row['request_id']} is completed"; break;
				case 'pending_dean': $msg = "New request {$row['request_id']} awaiting your review"; break;
				case 'pending_head': $msg = "New request {$row['request_id']} forwarded to you for review"; break;
				case 'pending_officer': $msg = "Request {$row['request_id']} needs officer action"; break;
				case 'for_final_approval': $msg = "Request {$row['request_id']} is for final approval"; break;
				default: $msg = "Request {$row['request_id']} status: {$row['status']}";
			}
			// For dean/head, if approved and has schedule, customize
			if (($role === 'dean' || $role === 'head') && $row['status'] === 'approved') {
				$today = date('Y-m-d');
				if (!empty($row['release_date'])) {
					if ($row['release_date'] === $today) {
						$msg = "Release today for request {$row['request_id']}";
					} else {
						$msg = "Request {$row['request_id']} approved, scheduled for " . date('M d, Y', strtotime($row['release_date']));
					}
				}
			}
			$out[] = [
				'id' => $row['id'],
				'request_id' => $row['request_id'],
				'message' => $msg,
				'link' => '/SupplyRequestSystem/' . (
                    $role === 'requester' ? 'requesters/view_request.php?id=' : (
                        $role==='dean' ? 'dean/view_requests.php?id=' : (
                            ($role==='head') ? 'head/view_request.php?id=' : 'officer/view_request.php?id='
                        )
                    )
                ) . $row['id'],
				'created_at' => $row['created_at']
			];
		}
	}
	if ($stmt) $stmt->close();
	return $out;
}
