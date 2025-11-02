<?php
session_start();
include('../config/db.php');

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
    // 🧠 FIX: Handle array or single inputs safely
    $item_name = isset($_POST['item_name']) 
        ? (is_array($_POST['item_name']) ? trim($_POST['item_name'][0]) : trim($_POST['item_name'])) 
        : '';
    $stock_number = isset($_POST['stock_number']) 
        ? (is_array($_POST['stock_number']) ? trim($_POST['stock_number'][0]) : trim($_POST['stock_number'])) 
        : '';
    $unit = isset($_POST['unit']) 
        ? (is_array($_POST['unit']) ? trim($_POST['unit'][0]) : trim($_POST['unit'])) 
        : '';
    $quantity = intval($_POST['quantity'] ?? 0);
    $priority = $_POST['priority'] ?? 'normal';
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

    // Determine initial status based on both roles
    if ($requester_role === 'requester') {
        if ($creator_role === 'dean') {
            $status = 'pending_dean';
        } else if ($creator_role === 'head') {
            $status = 'pending_head';
        } else {
            // Default for office requesters or unknown creator
            $status = 'pending_head';
        }
    } else {
        // For non-requester roles (shouldn't happen, but just in case)
        $status = 'pending_head';
    }

    // Debug logging
    error_log("Request creation - Requester ID: $requester_id, Requester Role: $requester_role, Creator ID: $creator_id, Creator Role: $creator_role");
    error_log("Setting initial status to: $status");

    // ✅ Generate a formatted request ID (YYMMDD + 4 digit number)
    $formatted_request_id = date('ymd') . str_pad(mt_rand(1, 9999), 4, '0', STR_PAD_LEFT);

    // ✅ Insert request with the formatted request_id
    $stmt = $conn->prepare("INSERT INTO requests (request_id, requester_id, college_office_id, description, attachment, status, created_at)
                            VALUES (?, ?, ?, ?, ?, ?, ?)");
    
    $stmt->bind_param("siissss", $formatted_request_id, $requester_id, $college_office_id, $description, $attachment, $status, $created_at);

    if (!$stmt->execute()) {
        die("<div style='padding:20px;background:#ffe0e0;color:#a33;border-radius:6px;'>Database error: " . htmlspecialchars($stmt->error) . "</div>");
    }

    $request_id = $stmt->insert_id; // This is the auto-increment ID we'll use for request_items
    $stmt->close();

    // ✅ Get item ID from items table (create link)
    $item_stmt = $conn->prepare("SELECT id FROM items WHERE item_name = ? LIMIT 1");
    $item_stmt->bind_param("s", $item_name);
    $item_stmt->execute();
    $item_result = $item_stmt->get_result();
    $item = $item_result->fetch_assoc();
    $item_id = $item['id'] ?? null;
    $item_stmt->close();

    if (!$item_id) {
        die("<div style='padding:20px;background:#ffe0e0;color:#a33;border-radius:6px;'>❌ Item not found in database. Please select an existing item.</div>");
    }

    // ✅ Insert item details into request_items
    $stmt = $conn->prepare("INSERT INTO request_items (request_id, item_id, quantity, unit, priority)
                            VALUES (?, ?, ?, ?, ?)");
    $stmt->bind_param("iiiss", $request_id, $item_id, $quantity, $unit, $priority);
    $stmt->execute();
    $stmt->close();

    $success = "Request submitted successfully! Your Request ID is: $formatted_request_id";
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

        /* Form Card */
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

        /* Guide Box */
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

        /* Alert */
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

        .alert-minimal i {
            font-size: 1.25rem;
        }

        /* Form Elements */
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

        /* Suggestions */
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

        /* Buttons */
        .btn-minimal {
            padding: 0.625rem 1.5rem;
            border-radius: 8px;
            font-weight: 500;
            font-size: 0.9375rem;
            border: none;
            transition: all 0.2s ease;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
        }

        .btn-primary-minimal {
            background-color: var(--red-primary);
            color: white;
        }

        .btn-primary-minimal:hover {
            background-color: var(--red-dark);
            transform: translateY(-1px);
        }

        /* Step visibility */
        .step2 {
            animation: fadeIn 0.3s ease-in;
        }

        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(-10px); }
            to { opacity: 1; transform: translateY(0); }
        }

        /* Form Actions */
        .form-actions {
            margin-top: 2rem;
            padding-top: 1.5rem;
            border-top: 1px solid var(--gray-200);
            text-align: right;
        }

        /* Responsive */
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

    <script>
        $(document).ready(function() {
            $('#item_name').on('input', function() {
                const query = $(this).val();
                if (query.length < 2) {
                    $('#suggestions').empty();
                    return;
                }
                $.ajax({
                    url: 'search_item.php',
                    method: 'GET',
                    data: { q: query },
                    success: function(data) {
                        $('#suggestions').html(data);
                    }
                });
            });

            $(document).on('click', '.suggest-item', function() {
                $('#item_name').val($(this).data('name'));
                $('#stock_number').val($(this).data('stock'));
                $('#unit').val($(this).data('unit'));
                $('#suggestions').empty();
                $('.step2').fadeIn();
            });
        });
    </script>
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
                    <strong>Step 1:</strong> Start typing the item name to search available supplies.<br>
                    <strong>Step 2:</strong> Select from suggestions - stock number and unit will auto-fill.<br>
                    <strong>Step 3:</strong> Enter quantity, priority, and attach your request slip before submitting.
                </p>
            </div>

            <?php if (isset($success)): ?>
                <div class="alert-minimal alert-success">
                    <i class="bi bi-check-circle-fill"></i>
                    <span><?= htmlspecialchars($success) ?></span>
                </div>
            <?php endif; ?>

            <form method="POST" enctype="multipart/form-data" autocomplete="off">
                <div class="row g-3">
                    <div class="col-md-6 position-relative">
                        <label class="form-label-minimal">Item Name <span style="color: var(--red-primary);">*</span></label>
                        <input type="text" id="item_name" name="item_name" class="form-control form-control-minimal" placeholder="Type item name (e.g., bond paper)" required>
                        <div id="suggestions" class="list-group suggestion-box"></div>
                    </div>

                    <div class="col-md-3">
                        <label class="form-label-minimal">Stock Number</label>
                        <input type="text" id="stock_number" name="stock_number" class="form-control form-control-minimal" readonly>
                    </div>

                    <div class="col-md-3">
                        <label class="form-label-minimal">Unit</label>
                        <input type="text" id="unit" name="unit" class="form-control form-control-minimal" readonly>
                    </div>

                    <div class="col-md-4 step2" style="display:none;">
                        <label class="form-label-minimal">Quantity <span style="color: var(--red-primary);">*</span></label>
                        <input type="number" name="quantity" class="form-control form-control-minimal" min="1" placeholder="0" required>
                    </div>

                    <div class="col-md-4 step2" style="display:none;">
                        <label class="form-label-minimal">Priority Level <span style="color: var(--red-primary);">*</span></label>
                        <select name="priority" class="form-select form-select-minimal" required>
                            <option value="normal">Normal</option>
                            <option value="high">High</option>
                            <option value="urgent">Urgent</option>
                        </select>
                    </div>

                    <div class="col-md-4 step2" style="display:none;">
                        <label class="form-label-minimal">Attach Request Slip</label>
                        <input type="file" name="attachment" class="form-control form-control-minimal" accept=".pdf,.jpg,.jpeg,.png">
                    </div>

                    <div class="col-12 step2" style="display:none;">
                        <label class="form-label-minimal">Purpose / Description <span style="color: var(--red-primary);">*</span></label>
                        <textarea name="description" class="form-control form-control-minimal" rows="4" placeholder="State the purpose of this supply request" required></textarea>
                    </div>
                </div>

                <div class="form-actions step2" style="display:none;">
                    <button type="submit" class="btn-minimal btn-primary-minimal">
                        <i class="bi bi-send"></i> Submit Request
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>