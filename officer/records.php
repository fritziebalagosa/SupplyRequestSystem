<?php
session_start();
include('../config/db.php');
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'] ?? '', ['officer','supply_officer'])) {
    header('Location: ../auth/log_in.php');
    exit;
}

include('../includes/officer_navbar.php');

$user_id = $_SESSION['user_id'];

// determine college_office_id (kept for potential future filters)
if (isset($_SESSION['college_office_id'])) {
    $college_office_id = $_SESSION['college_office_id'];
} else {
    $q = $conn->prepare("SELECT college_office_id FROM users WHERE id = ?");
    $q->bind_param("i", $user_id);
    $q->execute();
    $res = $q->get_result()->fetch_assoc();
    $college_office_id = $res['college_office_id'] ?? null;
    $q->close();
}

// Officers are central; show all records regardless of office.
$sql = "SELECT r.id, r.request_id, r.status, r.created_at, u.first_name, u.last_name,
                GROUP_CONCAT(DISTINCT it.item_name SEPARATOR ', ') AS items
        FROM requests r
        JOIN users u ON r.requester_id = u.id
        LEFT JOIN request_items ri ON ri.request_id = r.id
        LEFT JOIN items it ON ri.item_id = it.id
        GROUP BY r.id
        ORDER BY r.created_at DESC";
$stmt = $conn->prepare($sql);

$stmt->execute();
$requests = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Records</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="container py-4">
    <h3>All Records</h3>
    <p class="text-muted">Showing all requests for your office regardless of status.</p>

    <table class="table table-striped">
        <thead>
            <tr>
                <th>Request ID</th>
                <th>Items</th>
                <th>Requester</th>
                <th>Status</th>
                <th>Date</th>
                <th>Action</th>
            </tr>
        </thead>
        <tbody>
        <?php if (empty($requests)): ?>
            <tr><td colspan="6">No requests found.</td></tr>
        <?php else: foreach ($requests as $r): ?>
            <tr>
                <td><?= htmlspecialchars($r['request_id'] ?: $r['id']) ?></td>
                <td><?= htmlspecialchars($r['items'] ?? '—') ?></td>
                <td><?= htmlspecialchars($r['first_name'] . ' ' . $r['last_name']) ?></td>
                <td><?= htmlspecialchars(ucfirst($r['status'])) ?></td>
                <td><?= htmlspecialchars($r['created_at']) ?></td>
                <td>
                    <a class="btn btn-sm btn-primary" href="view_request.php?id=<?= $r['id'] ?>">View</a>
                </td>
            </tr>
        <?php endforeach; endif; ?>
        </tbody>
    </table>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
