<?php
session_start();
include('../config/db.php');

// Generate form token
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $_SESSION['form_token'] = bin2hex(random_bytes(32));
}

// ✅ Ensure requester is logged in
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'requester') {
    header('Location: ../auth/log_in.php');
    exit;
}

$requester_id = $_SESSION['user_id'];

// ✅ Fetch requester info and verify college_office_id exists
$userQuery = $conn->prepare("
    SELECT u.college_office_id 
    FROM users u 
    JOIN college_offices co ON u.college_office_id = co.id 
    WHERE u.id = ?
");
$userQuery->bind_param("i", $requester_id);
$userQuery->execute();
$userResult = $userQuery->get_result()->fetch_assoc();
$college_office_id = $userResult['college_office_id'] ?? null;
$userQuery->close();

if (!$college_office_id) {
    die("<div style='padding:20px;background:#ffe0e0;color:#a33;border-radius:6px;'>⚠️ Your account is not properly linked to an active college or office. Please contact the administrator.</div>");
}

// ✅ Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Check if this form was already submitted
    if (!isset($_SESSION['form_token']) || !isset($_POST['form_token']) || 
        $_SESSION['form_token'] !== $_POST['form_token']) {
        header('Location: ' . $_SERVER['PHP_SELF']);
        exit;
    }
    
    // Clear the token to prevent resubmission
    unset($_SESSION['form_token']);
    
    $item_raw = $_POST['item_name'] ?? [];
    $unit_raw = $_POST['unit'] ?? [];
    $qty_raw = $_POST['quantity'] ?? [];
    $prio_raw = $_POST['priority'] ?? [];

    $item_names = is_array($item_raw) ? array_map('trim', $item_raw) : [trim((string)$item_raw)];
    $units = is_array($unit_raw) ? $unit_raw : [(string)$unit_raw];
    $quantities = is_array($qty_raw) ? $qty_raw : [(string)$qty_raw];
    $priorities = is_array($prio_raw) ? $prio_raw : [(string)$prio_raw];

    $description = trim($_POST['description'] ?? '');
    $created_at = date('Y-m-d H:i:s');

    // Handle attachment upload
    $attachment = null;
    if (!empty($_FILES['attachment']['name'])) {
        $target_dir = "../uploads/";
        if (!is_dir($target_dir)) mkdir($target_dir, 0777, true);
        $attachment = $target_dir . basename($_FILES['attachment']['name']);
        move_uploaded_file($_FILES['attachment']['tmp_name'], $attachment);
    }

    // Get both requester's role and creator's role
    $roles_query = $conn->prepare("SELECT 
        u1.role as requester_role,
        u2.role as creator_role,
        u2.id as creator_id 
    FROM users u1 
    LEFT JOIN users u2 ON u1.created_by = u2.id 
    WHERE u1.id = ?");
    $roles_query->bind_param("i", $requester_id);
    $roles_query->execute();
    $roles = $roles_query->get_result()->fetch_assoc();
    $requester_role = $roles['requester_role'] ?? '';
    $creator_role = $roles['creator_role'] ?? '';
    $creator_id = $roles['creator_id'] ?? 0;
    $roles_query->close();

    // Determine initial status
    if ($requester_role === 'requester') {
        if ($creator_role === 'dean') {
            $status = 'pending_dean';
        } else if ($creator_role === 'head') {
            $status = 'pending_head';
        } else {
            $status = 'pending_head';
        }
    } else {
        $status = 'pending_head';
    }

    // Basic validation
    $rows = [];
    for ($i = 0; $i < count($item_names); $i++) {
        $nm = trim($item_names[$i] ?? '');
        $qt = (int)($quantities[$i] ?? 0);
        $un = trim($units[$i] ?? '');
        $pr = trim($priorities[$i] ?? 'normal');
        if ($nm !== '' && $qt > 0) {
            $rows[] = ['name'=>$nm,'qty'=>$qt,'unit'=>$un,'priority'=>$pr];
        }
    }
    if (empty($rows)) {
        $error = "Please add at least one item with a quantity greater than zero.";
    } else {
        // ✅ Generate a formatted request ID
        $formatted_request_id = date('ymd') . str_pad(mt_rand(1, 9999), 4, '0', STR_PAD_LEFT);

        $conn->begin_transaction();
        try {
            $stmt = $conn->prepare("INSERT INTO requests (request_id, requester_id, college_office_id, description, attachment, status, created_at)
                                    VALUES (?, ?, ?, ?, ?, ?, ?)");
            $stmt->bind_param("siissss", $formatted_request_id, $requester_id, $college_office_id, $description, $attachment, $status, $created_at);
            if (!$stmt->execute()) { throw new Exception($stmt->error); }
            $request_id = $stmt->insert_id;
            $stmt->close();

            $find_item = $conn->prepare("SELECT id FROM items WHERE item_name = ? LIMIT 1");
            $ins_item = $conn->prepare("INSERT INTO request_items (request_id, item_id, quantity, unit, priority) VALUES (?, ?, ?, ?, ?)");
            foreach ($rows as $r) {
                $find_item->bind_param('s', $r['name']);
                $find_item->execute();
                $res = $find_item->get_result()->fetch_assoc();
                $item_id = $res['id'] ?? null;
                if (!$item_id) { throw new Exception('Item not found: '.htmlspecialchars($r['name'])); }
                $ins_item->bind_param('iiiss', $request_id, $item_id, $r['qty'], $r['unit'], $r['priority']);
                if (!$ins_item->execute()) { throw new Exception($ins_item->error); }
            }
            $find_item->close();
            $ins_item->close();

            $conn->commit();
            $success = "Request submitted successfully! Your Request ID is: $formatted_request_id";
        } catch (Exception $e) {
            $conn->rollback();
            $error = "Database error: ".htmlspecialchars($e->getMessage());
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Requester Dashboard - WMSU OSRS</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css" rel="stylesheet">
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>

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

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Inter', sans-serif;
            background-color: var(--gray-50);
            color: var(--gray-900);
            line-height: 1.6;
        }

        .container-main {
            max-width: 900px;
            margin: 0 auto;
            padding: 2rem 1.5rem;
        }

        .page-title {
            font-size: 1.75rem;
            font-weight: 600;
            color: var(--gray-900);
            letter-spacing: -0.5px;
            margin-bottom: 2rem;
            text-align: center;
        }

        .form-card {
            background: white;
            border-radius: 12px;
            border: 1px solid var(--gray-200);
            padding: 2rem;
        }

        .form-header {
            margin-bottom: 1.5rem;
        }

        .form-header h4 {
            font-size: 1.25rem;
            font-weight: 600;
            color: var(--gray-900);
            margin-bottom: 0.5rem;
        }

        .guide-box {
            background-color: #e7f3ff;
            border-left: 4px solid var(--red-primary);
            padding: 1rem 1.25rem;
            border-radius: 8px;
            margin-bottom: 1.5rem;
        }

        .guide-box strong {
            color: var(--red-primary);
            font-size: 0.9375rem;
        }

        .guide-box p {
            margin: 0.5rem 0 0 0;
            font-size: 0.9375rem;
            color: var(--gray-700);
            line-height: 1.6;
        }

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

        .alert-minimal i {
            font-size: 1.25rem;
        }

        .form-label-minimal {
            font-size: 0.875rem;
            color: var(--gray-700);
            font-weight: 500;
            margin-bottom: 0.5rem;
        }

        .form-control-minimal,
        .form-select-minimal {
            border: 1px solid var(--gray-300);
            border-radius: 8px;
            padding: 0.625rem 0.875rem;
            font-size: 0.9375rem;
            transition: all 0.2s ease;
        }

        .form-control-minimal:focus,
        .form-select-minimal:focus {
            border-color: var(--red-primary);
            box-shadow: 0 0 0 0.2rem rgba(220, 53, 69, 0.1);
            outline: none;
        }

        .form-control-minimal:read-only {
            background-color: var(--gray-100);
            cursor: not-allowed;
        }

        textarea.form-control-minimal {
            resize: vertical;
            min-height: 100px;
        }

        .suggestion-box {
            position: absolute;
            width: 100%;
            z-index: 1000;
            margin-top: 0.25rem;
            background: white;
            border: 1px solid var(--gray-200);
            border-radius: 8px;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
            max-height: 250px;
            overflow-y: auto;
        }

        .list-group-item {
            cursor: pointer;
            border: none;
            border-bottom: 1px solid var(--gray-100);
            padding: 0.75rem 1rem;
            transition: background-color 0.2s ease;
        }

        .list-group-item:last-child {
            border-bottom: none;
        }

        .list-group-item:hover {
            background-color: var(--gray-50);
        }

        .btn-minimal {
            padding: 0.625rem 1.25rem;
            border-radius: 8px;
            font-weight: 500;
            font-size: 0.9375rem;
            border: none;
            transition: all 0.2s ease;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            cursor: pointer;
        }

        .btn-primary-minimal {
            background-color: var(--red-primary);
            color: white;
        }

        .btn-primary-minimal:hover {
            background-color: var(--red-dark);
            transform: translateY(-1px);
        }

        .btn-sm-minimal {
            padding: 0.4rem 0.75rem;
            font-size: 0.875rem;
        }

        .items-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 1rem;
        }

        .items-table thead th {
            background: var(--gray-50);
            color: var(--gray-700);
            font-weight: 600;
            font-size: 0.75rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            padding: 0.75rem 1rem;
            border: 1px solid var(--gray-200);
            text-align: left;
        }

        .items-table tbody td {
            padding: 0.75rem 1rem;
            border: 1px solid var(--gray-200);
            font-size: 0.9375rem;
        }

        .items-table tbody tr:hover {
            background-color: var(--gray-50);
        }

        .step2 {
            animation: fadeIn 0.3s ease-in;
        }

        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(-10px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .form-actions {
            margin-top: 2rem;
            padding-top: 1.5rem;
            border-top: 1px solid var(--gray-200);
            text-align: right;
        }

        @media (max-width: 768px) {
            .container-main {
                padding: 1.5rem 1rem;
            }

            .page-title {
                font-size: 1.5rem;
                margin-bottom: 1.5rem;
            }

            .form-card {
                padding: 1.5rem;
            }

            .guide-box {
                padding: 0.875rem 1rem;
            }
        }
    </style>

                <!-- Modal for restock warning, beautified to match system UI -->
                <div class="modal fade" id="restockModal" tabindex="-1" aria-labelledby="restockModalLabel" aria-hidden="true">
                    <div class="modal-dialog modal-dialog-centered">
                        <div class="modal-content" style="border-radius: 16px; box-shadow: 0 6px 32px rgba(220,53,69,0.10), 0 1.5px 6px rgba(33,33,33,0.06); font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Inter', sans-serif;">
                            <div class="modal-header" style="background: var(--red-light); border-top-left-radius: 16px; border-top-right-radius: 16px; border-bottom: none;">
                                <h5 class="modal-title d-flex align-items-center gap-2" id="restockModalLabel" style="color: var(--red-primary); font-weight: 600; font-size: 1.15rem;">
                                    <i class="bi bi-exclamation-triangle-fill" style="font-size: 1.5rem; color: var(--red-primary);"></i>
                                    Cannot Add Item
                                </h5>
                                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close" style="filter: invert(40%) sepia(100%) saturate(500%) hue-rotate(-10deg);"></button>
                            </div>
                            <div class="modal-body" id="restockModalBody" style="padding: 1.5rem 2rem 1rem 2rem; color: var(--gray-900); font-size: 1.05rem;">
                                <!-- Message will be injected here -->
                            </div>
                            <div class="modal-footer" style="border-top: none; padding-bottom: 1.5rem;">
                                <button type="button" class="btn btn-primary-minimal w-100" data-bs-dismiss="modal" style="background: var(--red-primary); color: #fff; border-radius: 8px; font-weight: 500; font-size: 1rem;">Close</button>
                            </div>
                        </div>
                    </div>
                </div>
        <script src="dashboard.js"></script>
</head>

<body>
    <?php include('../includes/requester_navbar.php'); ?>
    
    <div class="container-main">
        <h1 class="page-title">Create Supply Request</h1>

        <div class="form-card">
            <div class="form-header">
                <h4><i class="bi bi-clipboard-check"></i> New Request Form</h4>
            </div>

            <div class="guide-box">
                <strong><i class="bi bi-lightbulb"></i> Quick Guide</strong>
                <p>
                    <strong>Step 1:</strong> Search for an item by typing its name.<br>
                    <strong>Step 2:</strong> Select from suggestions and fill in quantity and priority.<br>
                    <strong>Step 3:</strong> Click "Add Item" to add it to your request (you can add multiple items).<br>
                    <strong>Step 4:</strong> Fill in the purpose and submit your request.
                </p>
            </div>

            <?php if (isset($success)): ?>
                <div class="alert-minimal alert-success">
                    <i class="bi bi-check-circle-fill"></i>
                    <span><?= htmlspecialchars($success) ?></span>
                </div>
            <?php endif; ?>

            <?php if (isset($error)): ?>
                <div class="alert-minimal alert-danger">
                    <i class="bi bi-exclamation-circle-fill"></i>
                    <span><?= htmlspecialchars($error) ?></span>
                </div>
            <?php endif; ?>

            <form method="POST" enctype="multipart/form-data" autocomplete="off" id="requestForm">
                <div class="row g-3">
                    <div class="col-md-6 position-relative">
                        <label class="form-label-minimal">Item Name <span style="color: var(--red-primary);">*</span></label>
                        <input type="text" id="item_name" class="form-control form-control-minimal" placeholder="Type item name (e.g., bond paper)">
                        <div id="suggestions" class="list-group suggestion-box"></div>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label-minimal">Stock Number</label>
                        <input type="text" id="stock_number" class="form-control form-control-minimal" readonly>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label-minimal">Unit</label>
                        <input type="text" id="unit" class="form-control form-control-minimal" readonly>
                    </div>
                </div>

                <div class="row g-3 mt-2 step2" style="display:none;">
                    <div class="col-md-4">
                        <label class="form-label-minimal">Quantity <span style="color: var(--red-primary);">*</span></label>
                        <input type="number" id="quantity" class="form-control form-control-minimal" min="1" placeholder="0">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label-minimal">Priority Level <span style="color: var(--red-primary);">*</span></label>
                        <select id="priority" class="form-select form-select-minimal">
                            <option value="normal">Normal</option>
                            <option value="high">High</option>
                            <option value="urgent">Urgent</option>
                        </select>
                    </div>
                    <div class="col-md-4 d-flex align-items-end">
                        <button type="button" class="btn-minimal btn-primary-minimal w-100" id="addItemBtn">
                            <i class="bi bi-plus-circle"></i> Add Item
                        </button>
                    </div>
                </div>

                <div class="row g-3 mt-3" id="itemsListRow" style="display:none;">
                    <div class="col-12">
                        <h5 class="form-label-minimal" style="font-size: 1rem; margin-bottom: 0.5rem;">Items in Request:</h5>
                        <table class="items-table" id="itemsTable">
                            <thead>
                                <tr>
                                    <th>Item Name</th>
                                    <th>Stock #</th>
                                    <th>Unit</th>
                                    <th>Quantity</th>
                                    <th>Priority</th>
                                    <th>Remove</th>
                                </tr>
                            </thead>
                            <tbody></tbody>
                        </table>
                    </div>
                </div>

                <div class="row g-3 mt-3" id="finalStep" style="display:none;">
                    <div class="col-md-6">
                        <label class="form-label-minimal">Purpose / Description <span style="color: var(--red-primary);">*</span></label>
                        <textarea name="description" class="form-control form-control-minimal" rows="4" placeholder="State the purpose of this supply request" required></textarea>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label-minimal">Attach Request Slip</label>
                        <input type="file" name="attachment" class="form-control form-control-minimal" accept=".pdf,.jpg,.jpeg,.png">
                    </div>
                </div>

                <div class="form-actions" id="submitRow" style="display:none;">
                    <button type="submit" class="btn-minimal btn-primary-minimal">
                        <i class="bi bi-send"></i> Submit Request
                    </button>
                </div>

                <div id="hiddenItems"></div>
                <input type="hidden" name="form_token" value="<?php echo htmlspecialchars($_SESSION['form_token'] ?? ''); ?>">
            </form>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>