<?php
// Action History Component
// Expects $history variable to be available (array or mysqli_result)
?>
<div class="card mt-4">
    <div class="card-header bg-light">
        <h5><i class="bi bi-clock-history"></i> Action History</h5>
    </div>
    <div class="card-body">
        <div class="timeline" style="margin-top: 1.5rem;">
            <?php 
            // Handle both array and mysqli_result types
            $historyArray = [];
            if (isset($history)) {
                if (is_array($history)) {
                    $historyArray = $history;
                } elseif ($history instanceof mysqli_result) {
                    $history->data_seek(0);
                    $historyArray = $history->fetch_all(MYSQLI_ASSOC);
                }
            }
            
            if (!empty($historyArray)): ?>
                <?php foreach ($historyArray as $action): ?>
                    <div class="timeline-item">
                        <div class="timeline-marker"></div>
                        <div class="timeline-content">
                            <div class="d-flex justify-content-between align-items-center">
                                <h6 class="mb-1">
                                    <?php 
                                    $actionText = '';
                                    $icon = '';
                                    
                                    switch(strtolower($action['action_type'] ?? '')) {
                                        case 'approved':
                                            $actionText = 'Approved';
                                            $icon = 'bi-check-circle-fill text-success';
                                            break;
                                        case 'rejected':
                                            $actionText = 'Rejected';
                                            $icon = 'bi-x-circle-fill text-danger';
                                            break;
                                        case 'forwarded_to_admin':
                                            $actionText = 'Forwarded for Final Approval';
                                            $icon = 'bi-arrow-right-circle text-primary';
                                            break;
                                        case 'completed':
                                            $actionText = 'Completed';
                                            $icon = 'bi-check2-circle text-success';
                                            break;
                                        case 'submitted':
                                            $actionText = 'Submitted';
                                            $icon = 'bi-send text-info';
                                            break;
                                        case 'quantity_adjusted':
                                            $actionText = 'Quantities Adjusted';
                                            $icon = 'bi-pencil-square text-warning';
                                            break;
                                        default:
                                            $actionText = ucfirst($action['action_type'] ?? 'Unknown');
                                            $icon = 'bi-circle text-muted';
                                    }
                                    ?>
                                    <i class="bi <?= $icon ?>"></i>
                                    <?= htmlspecialchars($actionText) ?>
                                </h6>
                                <small class="text-muted">
                                    <?= date('M j, Y g:i A', strtotime($action['created_at'])) ?>
                                </small>
                            </div>
                            <?php if (!empty($action['first_name']) || !empty($action['last_name'])): ?>
                                <p class="mb-1 text-muted">
                                    by <?= htmlspecialchars(trim($action['first_name'] . ' ' . $action['last_name'])) ?>
                                </p>
                            <?php endif; ?>
                            <?php if (!empty($action['comment'])): ?>
                                <p class="mb-0"><?= nl2br(htmlspecialchars($action['comment'])) ?></p>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php else: ?>
                <p class="text-muted">No action history available.</p>
            <?php endif; ?>
        </div>
    </div>
</div>

<style>
.timeline {
    position: relative;
    padding-left: 30px;
}

.timeline::before {
    content: '';
    position: absolute;
    left: 10px;
    top: 0;
    bottom: 0;
    width: 2px;
    background: #dee2e6;
}

.timeline-item {
    position: relative;
    margin-bottom: 20px;
}

.timeline-marker {
    position: absolute;
    left: -25px;
    top: 5px;
    width: 20px;
    height: 20px;
    border-radius: 50%;
    background: #fff;
    border: 2px solid #dee2e6;
    z-index: 1;
}

.timeline-content {
    background: #f8f9fa;
    padding: 15px;
    border-radius: 8px;
    border-left: 3px solid #007bff;
}
</style>
