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

	if ($role === 'requester') {
		$sql = "SELECT id, request_id, status, created_at FROM requests WHERE requester_id = ? AND status IN ('returned','approved','rejected','completed') ORDER BY created_at DESC LIMIT ?";
		$stmt = $conn->prepare($sql);
		$stmt->bind_param('ii', $user_id, $limit);
	} elseif ($role === 'dean') {
		// Dean sees requests pending for dean
		$sql = "SELECT r.id, r.request_id, r.status, r.created_at FROM requests r JOIN users u ON r.requester_id = u.id WHERE r.college_office_id = ? AND u.created_by = ? AND r.status IN ('pending_dean','approved') ORDER BY r.created_at DESC LIMIT ?";
		$stmt = $conn->prepare($sql);
		$stmt->bind_param('iii', $college_office_id, $user_id, $limit);
	} elseif ($role === 'head') {
		// Head sees requests forwarded to head
		$sql = "SELECT r.id, r.request_id, r.status, r.created_at FROM requests r JOIN users u ON r.requester_id = u.id WHERE r.college_office_id = ? AND u.created_by = ? AND r.status IN ('pending_head','approved') ORDER BY r.created_at DESC LIMIT ?";
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
			$out[] = [
				'id' => $row['id'],
				'request_id' => $row['request_id'],
				'message' => $msg,
				'link' => '/SupplyRequestSystem/' . ($role === 'requester' ? 'requesters/view_request.php?id=' : (($role==='dean' || $role==='head') ? 'dean/view_requests.php?id=' : 'officer/view_request.php?id=')) . $row['id'],
				'created_at' => $row['created_at']
			];
		}
	}
	if ($stmt) $stmt->close();

	// Also include recent request_actions relevant to the user/office (resubmitted, received, forwarded)
	$seen_requests = array_column($out, 'id');
	$action_stmt = null;
	if ($role === 'requester') {
		$action_sql = "SELECT ra.id as action_id, ra.request_id, ra.action_type, ra.comment, ra.created_at, r.request_id as rid FROM request_actions ra JOIN requests r ON ra.request_id = r.id WHERE r.requester_id = ? ORDER BY ra.created_at DESC LIMIT ?";
		$action_stmt = $conn->prepare($action_sql);
		$action_stmt->bind_param('ii', $user_id, $limit);
	} elseif (in_array($role, ['dean','head','supply_officer','supply_head','officer'])) {
		$action_sql = "SELECT ra.id as action_id, ra.request_id, ra.action_type, ra.comment, ra.created_at, r.request_id as rid FROM request_actions ra JOIN requests r ON ra.request_id = r.id WHERE r.college_office_id = ? ORDER BY ra.created_at DESC LIMIT ?";
		$action_stmt = $conn->prepare($action_sql);
		$action_stmt->bind_param('ii', $college_office_id, $limit);
	}

	if ($action_stmt && $action_stmt->execute()) {
		$res2 = $action_stmt->get_result();
		while ($a = $res2->fetch_assoc()) {
			// avoid duplicating same request id already in $out
			if (in_array($a['request_id'], $seen_requests)) continue;
			$msg = '';
			switch ($a['action_type']) {
				case 'resubmitted': $msg = "Request {$a['rid']} was resubmitted by requester"; break;
				case 'received': $msg = "Receipt submitted for request {$a['rid']}"; break;
				case 'forwarded_to_head': $msg = "Request {$a['rid']} was forwarded to head"; break;
				default: $msg = "Request {$a['rid']} action: {$a['action_type']}";
			}
			$out[] = [
				'id' => $a['request_id'],
				'request_id' => $a['rid'],
				'message' => $msg,
				'link' => '/SupplyRequestSystem/' . ($role === 'requester' ? 'requesters/view_request.php?id=' : (($role==='dean' || $role==='head') ? 'dean/view_requests.php?id=' : 'officer/view_request.php?id=')) . $a['request_id'],
				'created_at' => $a['created_at']
			];
		}
	}
	if ($action_stmt) $action_stmt->close();

	return $out;
}

