<?php
session_start();
include('../config/db.php');
include('../includes/csrf.php');

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'requester') {
    header('Location: ../auth/log_in.php');
    exit;
}

$user_id = $_SESSION['user_id'];
$id = intval($_GET['id'] ?? $_POST['request_id'] ?? 0);
if (!$id) die('Invalid request id');

// fetch request and ensure it's owned by this user and is returned
$stmt = $conn->prepare("SELECT * FROM requests WHERE id = ? AND requester_id = ? LIMIT 1");
$stmt->bind_param('ii', $id, $user_id);
$stmt->execute();
$request = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$request) die('Request not found or access denied');
if (strpos($request['status'], 'returned') === false) die('Request is not in returned state.');

// fetch all available items for adding
$all_items_stmt = $conn->prepare("SELECT id, item_name, unit, stock_number FROM items ORDER BY item_name");
$all_items_stmt->execute();
$all_items = $all_items_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$all_items_stmt->close();

// fetch request_items with their row id
$items_stmt = $conn->prepare("SELECT ri.id as ri_id, ri.quantity, ri.unit, ri.priority, it.item_name FROM request_items ri JOIN items it ON ri.item_id = it.id WHERE ri.request_id = ?");
$items_stmt->bind_param('i', $id);
$items_stmt->execute();
$items = $items_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$items_stmt->close();

$message = '';
$message_type = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf = $_POST['csrf_token'] ?? '';
    if (!verify_csrf_token($csrf)) {
        $message = 'Invalid request (CSRF).';
        $message_type = 'danger';
    } else {
        $quantities = $_POST['quantity'] ?? [];
        $ri_ids = $_POST['ri_id'] ?? [];
        $comment = trim($_POST['comment'] ?? '');
        
        // Handle new items being added
        $new_items = $_POST['new_item_name'] ?? [];
        $new_quantities = $_POST['new_quantity'] ?? [];
        $new_priorities = $_POST['new_priority'] ?? [];

        // Basic validation for existing items
        $valid = true;
        for ($i = 0; $i < count($ri_ids); $i++) {
            $q = intval($quantities[$i] ?? 0);
            if ($q < 1) { $valid = false; break; }
        }
        
        // Basic validation for new items
        for ($i = 0; $i < count($new_items); $i++) {
            $item_name = trim($new_items[$i] ?? '');
            $qty = intval($new_quantities[$i] ?? 0);
            if ($item_name !== '' && $qty < 1) { $valid = false; break; }
        }
        
        if (!$valid) {
            $message = 'Please ensure all quantities are at least 1.';
            $message_type = 'danger';
        } else {
            $conn->begin_transaction();
            try {
                // Update existing request_items rows
                if (!empty($ri_ids)) {
                    $u = $conn->prepare("UPDATE request_items SET quantity = ? WHERE id = ?");
                    for ($i = 0; $i < count($ri_ids); $i++) {
                        $q = intval($quantities[$i]);
                        $rid = intval($ri_ids[$i]);
                        $u->bind_param('ii', $q, $rid);
                        $u->execute();
                    }
                    $u->close();
                }
                
                // Add new items
                if (!empty($new_items)) {
                    $find_item = $conn->prepare("SELECT id, unit FROM items WHERE item_name = ? LIMIT 1");
                    $ins_item = $conn->prepare("INSERT INTO request_items (request_id, item_id, quantity, unit, priority) VALUES (?, ?, ?, ?, ?)");
                    
                    for ($i = 0; $i < count($new_items); $i++) {
                        $item_name = trim($new_items[$i] ?? '');
                        $qty = intval($new_quantities[$i] ?? 0);
                        $priority = trim($new_priorities[$i] ?? 'Normal');
                        
                        if ($item_name !== '' && $qty > 0) {
                            $find_item->bind_param('s', $item_name);
                            $find_item->execute();
                            $res = $find_item->get_result()->fetch_assoc();
                            $item_id = $res['id'] ?? null;
                            $unit = $res['unit'] ?? '';
                            
                            if (!$item_id) {
                                throw new Exception('Item not found: ' . htmlspecialchars($item_name));
                            }
                            
                            $ins_item->bind_param('iiiss', $id, $item_id, $qty, $unit, $priority);
                            if (!$ins_item->execute()) {
                                throw new Exception('Failed to add item: ' . $ins_item->error);
                            }
                        }
                    }
                    $find_item->close();
                    $ins_item->close();
                }
                
                // set status back to pending_head so head/dean can review again
                $s = $conn->prepare("UPDATE requests SET status = 'pending_head' WHERE id = ?");
                $s->bind_param('i', $id);
                $s->execute();
                $s->close();

                // insert action
                $ia = $conn->prepare("INSERT INTO request_actions (request_id, action_by, role, action_type, comment, created_at) VALUES (?, ?, ?, 'resubmitted', ?, NOW())");
                $role = 'requester';
                $ia->bind_param('iiss', $id, $user_id, $role, $comment);
                $ia->execute();
                $ia->close();
                
                $conn->commit();
                
                $_SESSION['flash_message'] = 'Request updated and resubmitted successfully.';
                header('Location: view_request.php?id=' . $id);
                exit;
                
            } catch (Exception $e) {
                $conn->rollback();
                $message = 'Error updating request: ' . $e->getMessage();
                $message_type = 'danger';
            }
        }
    }
}

$csrf_token = generate_csrf_token();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Returned Request - WMSU OSRS</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css" rel="stylesheet">
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
        }

        /* Page Header */
        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 2rem;
        }

        .page-title {
            font-size: 1.75rem;
            font-weight: 600;
            color: var(--gray-900);
            letter-spacing: -0.5px;
            margin: 0;
        }

        .request-id {
            font-family: 'Courier New', monospace;
            color: var(--red-primary);
        }

        /* Alert Messages */
        .alert {
            border-radius: 8px;
            border: none;
            box-shadow: 0 0.125rem 0.25rem rgba(0, 0, 0, 0.075);
        }

        .alert-danger {
            background-color: var(--red-light);
            color: #721c24;
            border-color: #f5c6cb;
        }

        /* Section Cards */
        .section-card {
            background: white;
            border-radius: 12px;
            border: 1px solid var(--gray-200);
            overflow: hidden;
            margin-bottom: 2rem;
        }

        .section-header {
            padding: 1.25rem 1.5rem;
            border-bottom: 1px solid var(--gray-200);
            background: white;
        }

        .section-header h5 {
            font-size: 1.125rem;
            font-weight: 600;
            color: var(--gray-900);
            margin: 0;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .section-body {
            padding: 1.5rem;
        }

        /* Form Elements */
        .form-label-minimal {
            font-size: 0.875rem;
            color: var(--gray-700);
            font-weight: 500;
            margin-bottom: 0.5rem;
        }

        .form-control-minimal {
            border: 1px solid var(--gray-300);
            border-radius: 8px;
            padding: 0.625rem 0.875rem;
            font-size: 0.9375rem;
            transition: all 0.2s ease;
        }

        .form-control-minimal:focus {
            border-color: var(--red-primary);
            box-shadow: 0 0 0 0.2rem rgba(220, 53, 69, 0.1);
            outline: none;
        }

        textarea.form-control-minimal {
            resize: vertical;
            min-height: 80px;
        }

        /* Buttons */
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
        }

        .btn-primary-minimal {
            background-color: var(--red-primary);
            color: white;
            border: 1px solid var(--red-primary);
        }

        .btn-primary-minimal:hover {
            background-color: var(--red-dark);
            border-color: var(--red-dark);
        }

        .btn-secondary-minimal {
            background-color: white;
            color: var(--gray-700);
            border: 1px solid var(--gray-300);
        }

        .btn-secondary-minimal:hover {
            background-color: var(--gray-50);
            border-color: var(--gray-700);
            color: var(--gray-900);
        }

        .form-actions {
            text-align: right;
            margin-top: 1rem;
        }

        /* Items Table */
        .items-table {
            width: 100%;
            border-collapse: collapse;
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

        /* Item Suggestions */
        .item-suggestions {
            position: absolute;
            top: 100%;
            left: 0;
            right: 0;
            background: white;
            border: 1px solid var(--gray-300);
            border-radius: 8px;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
            z-index: 1000;
            max-height: 200px;
            overflow-y: auto;
            display: none;
        }

        .item-suggestion-item {
            padding: 0.75rem 1rem;
            cursor: pointer;
            border-bottom: 1px solid var(--gray-100);
            transition: background-color 0.2s ease;
        }

        .item-suggestion-item:hover {
            background-color: var(--gray-50);
        }

        .item-suggestion-item:last-child {
            border-bottom: none;
        }

        .item-suggestion-item .item-name {
            font-weight: 500;
            color: var(--gray-900);
        }

        .item-suggestion-item .item-details {
            font-size: 0.875rem;
            color: var(--gray-700);
            margin-top: 0.25rem;
        }

        .new-item-row {
            padding: 1rem;
            border: 1px solid var(--gray-200);
            border-radius: 8px;
            background: var(--gray-50);
        }

        .new-item-row .form-control-minimal:focus {
            background: white;
        }
        @media (max-width: 768px) {
            .container-main {
                padding: 1.5rem 1rem;
            }

            .page-title {
                font-size: 1.5rem;
            }

            .section-body {
                padding: 1.25rem;
            }

            .items-table thead {
                display: none;
            }

            .items-table tbody tr {
                display: block;
                margin-bottom: 1rem;
                border: 1px solid var(--gray-200);
                border-radius: 8px;
            }

            .items-table tbody td {
                display: flex;
                justify-content: space-between;
                padding: 0.75rem 1rem;
                border: none;
                border-bottom: 1px solid var(--gray-100);
            }

            .items-table tbody td:last-child {
                border-bottom: none;
            }

            .items-table tbody td::before {
                content: attr(data-label);
                font-weight: 600;
                color: var(--gray-700);
                font-size: 0.8125rem;
                text-transform: uppercase;
            }
        }
    </style>
</head>
<body>
    <?php include('../includes/requester_navbar.php'); ?>
    
    <div class="container-main">
        <a href="view_request.php?id=<?= $id ?>" class="back-button">
            <i class="bi bi-arrow-left"></i> Back to Request
        </a>

        <?php if ($message): ?>
            <div class="alert alert-<?= $message_type ?> alert-dismissible fade show" role="alert">
                <div class="d-flex align-items-center">
                    <i class="bi bi-<?= $message_type === 'success' ? 'check-circle-fill' : 'exclamation-triangle-fill' ?> me-2" style="font-size: 1.25rem;"></i>
                    <div class="flex-grow-1">
                        <?= htmlspecialchars($message) ?>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            </div>
        <?php endif; ?>

        <div class="page-header">
            <h1 class="page-title">Edit Returned Request <span class="request-id">#<?= htmlspecialchars($request['request_id'] ?: $request['id']) ?></span></h1>
        </div>

        <form method="POST">
            <input type="hidden" name="request_id" value="<?= $id ?>">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
            
            <div class="section-card">
                <div class="section-header">
                    <h5><i class="bi bi-box-seam"></i> Current Items</h5>
                </div>
                <div class="section-body">
                    <div class="table-responsive">
                        <table class="items-table">
                            <thead>
                                <tr>
                                    <th>Item Name</th>
                                    <th>Quantity</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($items as $idx => $it): ?>
                                    <tr>
                                        <td data-label="Item"><?= htmlspecialchars($it['item_name']) ?></td>
                                        <td data-label="Quantity" style="max-width:160px;">
                                            <input type="hidden" name="ri_id[]" value="<?= (int)$it['ri_id'] ?>">
                                            <input type="number" name="quantity[]" class="form-control-minimal" value="<?= (int)$it['quantity'] ?>" min="1" required>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <div class="section-card">
                <div class="section-header">
                    <h5><i class="bi bi-plus-circle"></i> Add New Items</h5>
                </div>
                <div class="section-body">
                    <div id="newItemsContainer">
                        <div class="new-item-row mb-3" data-item-index="0">
                            <div class="row g-3">
                                <div class="col-md-6">
                                    <label class="form-label-minimal">Item Name</label>
                                    <div class="position-relative">
                                        <input type="text" name="new_item_name[]" class="form-control-minimal item-search" placeholder="Type to search items..." autocomplete="off">
                                        <div class="item-suggestions"></div>
                                    </div>
                                </div>
                                <div class="col-md-2">
                                    <label class="form-label-minimal">Quantity</label>
                                    <input type="number" name="new_quantity[]" class="form-control-minimal" min="1" placeholder="0">
                                </div>
                                <div class="col-md-2">
                                    <label class="form-label-minimal">Priority</label>
                                    <select name="new_priority[]" class="form-control-minimal">
                                        <option value="Normal">Normal</option>
                                        <option value="High">High</option>
                                        <option value="Urgent">Urgent</option>
                                    </select>
                                </div>
                                <div class="col-md-2">
                                    <label class="form-label-minimal">&nbsp;</label><br>
                                    <button type="button" class="btn-minimal btn-secondary-minimal remove-item-btn" style="display:none;">
                                        <i class="bi bi-trash"></i> Remove
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="mt-3">
                        <button type="button" class="btn-minimal btn-secondary-minimal" id="addNewItemBtn">
                            <i class="bi bi-plus-circle"></i> Add Another Item
                        </button>
                    </div>
                </div>
            </div>

            <div class="section-card">
                <div class="section-header">
                    <h5><i class="bi bi-chat-left-text"></i> Additional Information</h5>
                </div>
                <div class="section-body">
                    <div class="mb-3">
                        <label for="comment" class="form-label-minimal">Comment (optional)</label>
                        <textarea name="comment" id="comment" class="form-control-minimal" rows="3" placeholder="Add any comments about the changes you're making..."></textarea>
                    </div>
                    
                    <div class="form-actions">
                        <button type="submit" class="btn-minimal btn-primary-minimal">
                            <i class="bi bi-check-circle"></i> Save & Resubmit
                        </button>
                        <a href="view_request.php?id=<?= $id ?>" class="btn-minimal btn-secondary-minimal">
                            <i class="bi bi-x-circle"></i> Cancel
                        </a>
                    </div>
                </div>
            </div>
        </form>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Available items data (from PHP)
        const availableItems = <?php echo json_encode($all_items); ?>;
        
        let newItemIndex = 1;
        
        // Add new item row
        document.getElementById('addNewItemBtn').addEventListener('click', function() {
            const container = document.getElementById('newItemsContainer');
            const newRow = createNewItemRow(newItemIndex++);
            container.appendChild(newRow);
            updateRemoveButtons();
        });
        
        // Create new item row
        function createNewItemRow(index) {
            const div = document.createElement('div');
            div.className = 'new-item-row mb-3';
            div.setAttribute('data-item-index', index);
            
            div.innerHTML = `
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label-minimal">Item Name</label>
                        <div class="position-relative">
                            <input type="text" name="new_item_name[]" class="form-control-minimal item-search" placeholder="Type to search items..." autocomplete="off">
                            <div class="item-suggestions"></div>
                        </div>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label-minimal">Quantity</label>
                        <input type="number" name="new_quantity[]" class="form-control-minimal" min="1" placeholder="0">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label-minimal">Priority</label>
                        <select name="new_priority[]" class="form-control-minimal">
                            <option value="Normal">Normal</option>
                            <option value="High">High</option>
                            <option value="Urgent">Urgent</option>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label-minimal">&nbsp;</label><br>
                        <button type="button" class="btn-minimal btn-secondary-minimal remove-item-btn">
                            <i class="bi bi-trash"></i> Remove
                        </button>
                    </div>
                </div>
            `;
            
            // Add event listeners to this new row
            setupItemSearch(div.querySelector('.item-search'));
            div.querySelector('.remove-item-btn').addEventListener('click', function() {
                removeItemRow(div);
            });
            
            return div;
        }
        
        // Remove item row
        function removeItemRow(row) {
            row.remove();
            updateRemoveButtons();
        }
        
        // Update remove buttons visibility
        function updateRemoveButtons() {
            const rows = document.querySelectorAll('.new-item-row');
            rows.forEach(row => {
                const removeBtn = row.querySelector('.remove-item-btn');
                if (removeBtn) {
                    removeBtn.style.display = rows.length > 1 ? 'inline-flex' : 'none';
                }
            });
        }
        
        // Setup item search functionality
        function setupItemSearch(input) {
            let timeout;
            const suggestionsDiv = input.nextElementSibling;
            
            input.addEventListener('input', function() {
                clearTimeout(timeout);
                const query = this.value.trim();
                
                if (query.length < 2) {
                    suggestionsDiv.style.display = 'none';
                    return;
                }
                
                timeout = setTimeout(() => {
                    showItemSuggestions(query, suggestionsDiv, input);
                }, 300);
            });
            
            input.addEventListener('focus', function() {
                if (this.value.trim().length >= 2) {
                    showItemSuggestions(this.value.trim(), suggestionsDiv, input);
                }
            });
            
            // Hide suggestions when clicking outside
            document.addEventListener('click', function(e) {
                if (!input.contains(e.target) && !suggestionsDiv.contains(e.target)) {
                    suggestionsDiv.style.display = 'none';
                }
            });
        }
        
        // Show item suggestions
        function showItemSuggestions(query, suggestionsDiv, input) {
            const filtered = availableItems.filter(item => 
                item.item_name.toLowerCase().includes(query.toLowerCase())
            );
            
            if (filtered.length === 0) {
                suggestionsDiv.innerHTML = '<div class="item-suggestion-item">No items found</div>';
            } else {
                suggestionsDiv.innerHTML = filtered.map(item => `
                    <div class="item-suggestion-item" data-item='${JSON.stringify(item).replace(/'/g, "&apos;")}'>
                        <div class="item-name">${item.item_name}</div>
                        <div class="item-details">Stock #${item.stock_number} • ${item.unit}</div>
                    </div>
                `).join('');
                
                // Add click handlers
                suggestionsDiv.querySelectorAll('.item-suggestion-item').forEach(item => {
                    item.addEventListener('click', function() {
                        const itemData = JSON.parse(this.getAttribute('data-item').replace(/&apos;/g, "'"));
                        input.value = itemData.item_name;
                        suggestionsDiv.style.display = 'none';
                        
                        // Auto-focus quantity field
                        const quantityInput = input.closest('.new-item-row').querySelector('input[name="new_quantity[]"]');
                        if (quantityInput) {
                            quantityInput.focus();
                        }
                    });
                });
            }
            
            suggestionsDiv.style.display = 'block';
        }
        
        // Initialize existing item search
        document.addEventListener('DOMContentLoaded', function() {
            document.querySelectorAll('.item-search').forEach(setupItemSearch);
            updateRemoveButtons();
        });
    </script>
</body>
</html>
