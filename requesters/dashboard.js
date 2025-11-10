$(document).ready(function() {
    let items = [];
    let currentStockInfo = null;

    // Initialize
    $('#addItemBtn').prop('disabled', true);

    // Stock level validation functions
    function showRestockWarning(msg) {
        // Extract restock level and max allowed quantity from the message
        let restockLevelMatch = msg.match(/restock level \((\d+)\)/);
        let maxQtyMatch = msg.match(/quantity is (\d+)/);
        let restockLevel = restockLevelMatch ? restockLevelMatch[1] : '';
        let maxQty = maxQtyMatch ? maxQtyMatch[1] : '';
            let html = `
                <div style="display: flex; align-items: center; gap: 0.75rem; margin-bottom: 0.5rem;">
                    <i class="bi bi-exclamation-triangle-fill" style="font-size: 1.7rem; color: var(--red-primary);"></i>
                    <span style="font-weight: 600; color: var(--red-primary); font-size: 1.08rem;">Cannot add item</span>
                </div>
                <div style="margin-bottom: 0.5rem; color: var(--gray-900); font-size: 1.01rem;">
                    The requested quantity would bring the stock <b>to or below the restock level</b>
                    <span style="color: var(--red-primary); font-weight: 600;">(${restockLevel})</span>.
                </div>
                <div style="color: var(--gray-900); font-size: 1.01rem;">
                    Maximum allowed quantity is
                    <span style="font-weight: 600; color: var(--red-primary);">${maxQty}</span>.
                </div>
            `;
        $('#restockModalBody').html(html);
        let modal = new bootstrap.Modal(document.getElementById('restockModal'));
        modal.show();
    }

    function hideRestockWarning() {
        $('#restock-warning').remove();
    }

    function resetItemInputs() {
        $('#item_name').val('');
        $('#stock_number').val('');
        $('#unit').val('');
        $('#quantity').val('');
        $('#priority').val('normal');
        $('.step2').hide();
        $('#suggestions').empty();
        hideRestockWarning();
        currentStockInfo = null;
        $('#addItemBtn').prop('disabled', true);
    }

    function updateItemsTable() {
        const tbody = $('#itemsTable tbody');
        tbody.empty();
        items.forEach((item, idx) => {
            tbody.append(`
                <tr>
                    <td>${$('<div>').text(item.name).html()}</td>
                    <td>${$('<div>').text(item.stock).html()}</td>
                    <td>${$('<div>').text(item.unit).html()}</td>
                    <td><strong>${item.qty}</strong></td>
                    <td>${$('<div>').text(item.priority).html()}</td>
                    <td>
                        <button type="button" class="btn btn-sm btn-danger remove-item" data-idx="${idx}">
                            <i class="bi bi-trash"></i>
                        </button>
                    </td>
                </tr>
            `);
        });
        if (items.length > 0) {
            $('#itemsListRow').show();
            $('#finalStep').show();
            $('#submitRow').show();
        } else {
            $('#itemsListRow').hide();
            $('#finalStep').hide();
            $('#submitRow').hide();
        }
    }

    function updateHiddenFields() {
        const hidden = $('#hiddenItems');
        hidden.empty();
        items.forEach((item) => {
            hidden.append(`<input type="hidden" name="item_name[]" value="${$('<div>').text(item.name).html()}">`);
            hidden.append(`<input type="hidden" name="unit[]" value="${$('<div>').text(item.unit).html()}">`);
            hidden.append(`<input type="hidden" name="quantity[]" value="${item.qty}">`);
            hidden.append(`<input type="hidden" name="priority[]" value="${item.priority}">`);
        });
    }

    async function checkStockLevel(itemName, quantity) {
        if (!itemName || !quantity || quantity < 1) {
            hideRestockWarning();
            $('#addItemBtn').prop('disabled', true);
            return false;
        }

        try {
            const response = await $.get('get_item_stock.php', { item_name: itemName });
            if (response.success) {
                currentStockInfo = response;
                const stockAfter = response.stock_qty - quantity;
                const maxAllowed = response.stock_qty - response.reorder_level - 1;
                // Only allow if stockAfter > reorder_level
                if (stockAfter <= response.reorder_level) {
                    showRestockWarning('Cannot add item: The requested quantity would bring the stock to or below the restock level (' + response.reorder_level + '). Maximum allowed quantity is ' + (maxAllowed > 0 ? maxAllowed : 0) + '.');
                    $('#addItemBtn').prop('disabled', true);
                    return false;
                } else {
                    hideRestockWarning();
                    $('#addItemBtn').prop('disabled', false);
                    return true;
                }
            }
            $('#addItemBtn').prop('disabled', true);
            return false;
        } catch (error) {
            console.error('Error checking stock:', error);
            $('#addItemBtn').prop('disabled', true);
            return false;
        }
    }

    // Event Handlers
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
        $('#quantity').val('').focus();
        hideRestockWarning();
        currentStockInfo = null;
        $('#addItemBtn').prop('disabled', true);
    });

    $('#quantity').on('input', async function() {
        const itemName = $('#item_name').val().trim();
        const qty = parseInt($(this).val(), 10);
        await checkStockLevel(itemName, qty);
    });

    $(document).on('click', '#addItemBtn', async function(e) {
        const name = $('#item_name').val().trim();
        const stock = $('#stock_number').val().trim();
        const unit = $('#unit').val().trim();
        const qty = parseInt($('#quantity').val(), 10);
        const priority = $('#priority').val();
        
        if (!name || !unit || !qty || qty < 1) {
            alert('Please fill in all item fields and ensure quantity is greater than 0.');
            return;
        }

        // Final stock check before adding
        const isValid = await checkStockLevel(name, qty);
        if (!isValid) {
            return;
        }

        items.push({ name, stock, unit, qty, priority });
        updateItemsTable();
        updateHiddenFields();
        resetItemInputs();
    });

    $(document).on('click', '.remove-item', function() {
        const idx = $(this).data('idx');
        items.splice(idx, 1);
        updateItemsTable();
        updateHiddenFields();
    });

    // Track form submission state
    let isSubmitting = false;

    $('#requestForm').on('submit', function(e) {
        if (items.length === 0) {
            alert('Please add at least one item to your request.');
            e.preventDefault();
            return;
        }

        // Prevent duplicate submissions
        if (isSubmitting) {
            console.log('Preventing duplicate submission');
            e.preventDefault();
            return;
        }

        isSubmitting = true;
        
        // Disable submit button
        const submitButton = $(this).find('button[type="submit"]');
        submitButton.prop('disabled', true);
        
        // Re-enable after a short delay in case submission fails
        setTimeout(() => {
            isSubmitting = false;
            submitButton.prop('disabled', false);
        }, 5000);
    });
});