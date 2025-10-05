<?php
// public/inventory_summary.php - Comprehensive Inventory Summary with Sales Integration
require_once __DIR__ . '/../includes/simple_auth.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/inventory_updates.php';

auth_require_login();

$pdo = Database::pdo();

// Initialize inventory system
InventoryUpdates::createInventoryViews($pdo);

// Handle reconciliation request
if (isset($_POST['reconcile_inventory'])) {
    if (InventoryUpdates::reconcileInventory($pdo)) {
        $message = "Inventory reconciliation completed successfully";
    } else {
        $error = "Failed to reconcile inventory";
    }
}

// Get comprehensive inventory data
$inventory_data = InventoryUpdates::getCurrentInventoryLevels($pdo);

// Calculate totals
$totals = [
    'total_tiles' => count($inventory_data),
    'total_boxes_available' => 0,
    'total_inventory_value' => 0,
    'low_stock_count' => 0,
    'out_of_stock_count' => 0
];

foreach ($inventory_data as $item) {
    $totals['total_boxes_available'] += (float)$item['available_boxes'];
    $totals['total_inventory_value'] += (float)$item['total_cost_value'];
    
    if ($item['available_boxes'] <= 0) {
        $totals['out_of_stock_count']++;
    } elseif ($item['available_boxes'] < 5) {
        $totals['low_stock_count']++;
    }
}

$page_title = "Inventory Summary & Stock Levels";
require_once __DIR__ . '/../includes/header.php';
?>

<style>
.inventory-summary {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
    border-radius: 15px;
    padding: 2rem;
    margin-bottom: 2rem;
}

.summary-card {
    background: rgba(255, 255, 255, 0.1);
    border-radius: 10px;
    padding: 1rem;
    text-align: center;
    backdrop-filter: blur(10px);
}

.inventory-table {
    border-radius: 10px;
    overflow: hidden;
    box-shadow: 0 10px 30px rgba(0,0,0,0.1);
}

.stock-status {
    display: inline-block;
    padding: 4px 12px;
    border-radius: 20px;
    font-size: 0.85rem;
    font-weight: 600;
}

.stock-good { background: #d4edda; color: #155724; }
.stock-low { background: #fff3cd; color: #856404; }
.stock-critical { background: #f8d7da; color: #721c24; }
.stock-out { background: #f8d7da; color: #721c24; }

.value-display {
    font-weight: 700;
    font-size: 1.1rem;
}

.cost-breakdown {
    background: #f8f9fa;
    border-radius: 8px;
    padding: 0.5rem;
    font-size: 0.9rem;
}

.action-buttons .btn {
    margin: 0 2px;
    border-radius: 8px;
}

.filter-section {
    background: white;
    border-radius: 10px;
    padding: 1.5rem;
    box-shadow: 0 4px 15px rgba(0,0,0,0.05);
    margin-bottom: 1rem;
}
</style>

<?php if (isset($message)): ?>
    <div class="alert alert-success alert-dismissible fade show">
        <?= h($message) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<?php if (isset($error)): ?>
    <div class="alert alert-danger alert-dismissible fade show">
        <?= h($error) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<!-- Summary Header -->
<div class="inventory-summary">
    <div class="text-center mb-4">
        <h2><i class="bi bi-boxes me-3"></i>Inventory Summary & Stock Levels</h2>
        <p class="mb-0 opacity-75">Real-time inventory tracking with sales integration</p>
    </div>
    
    <div class="row g-4">
        <div class="col-md-3">
            <div class="summary-card">
                <div class="h3 mb-1"><?= $totals['total_tiles'] ?></div>
                <small>Total Tile Types</small>
            </div>
        </div>
        <div class="col-md-3">
            <div class="summary-card">
                <div class="h3 mb-1"><?= number_format($totals['total_boxes_available'], 1) ?></div>
                <small>Available Boxes</small>
            </div>
        </div>
        <div class="col-md-3">
            <div class="summary-card">
                <div class="h3 mb-1">₹<?= number_format($totals['total_inventory_value'], 0) ?></div>
                <small>Total Inventory Value</small>
            </div>
        </div>
        <div class="col-md-3">
            <div class="summary-card">
                <div class="h3 mb-1 text-warning"><?= $totals['low_stock_count'] + $totals['out_of_stock_count'] ?></div>
                <small>Stock Alerts</small>
            </div>
        </div>
    </div>
</div>

<!-- Action Bar -->
<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h5 class="mb-0">Inventory Details</h5>
        <small class="text-muted">Last updated: <?= date('M j, Y g:i A') ?></small>
    </div>
    <div class="btn-group">
        <form method="post" style="display: inline;">
            <button type="submit" name="reconcile_inventory" class="btn btn-warning" 
                    onclick="return confirm('This will reconcile inventory from all transactions. Continue?')">
                <i class="bi bi-arrow-clockwise"></i> Reconcile Inventory
            </button>
        </form>
        <a href="tiles_inventory.php" class="btn btn-primary">
            <i class="bi bi-gear"></i> Manage Inventory
        </a>
        <button class="btn btn-success" onclick="exportInventory()">
            <i class="bi bi-download"></i> Export
        </button>
    </div>
</div>

<!-- Filter Section -->
<div class="filter-section">
    <div class="row g-3">
        <div class="col-md-3">
            <label class="form-label">Filter by Stock Status</label>
            <select class="form-select" id="stockFilter" onchange="filterTable()">
                <option value="">All Items</option>
                <option value="good">Good Stock (5+ boxes)</option>
                <option value="low">Low Stock (1-4 boxes)</option>
                <option value="out">Out of Stock</option>
            </select>
        </div>
        <div class="col-md-3">
            <label class="form-label">Search Tiles</label>
            <input type="text" class="form-control" id="searchTiles" placeholder="Search by name or size" onkeyup="filterTable()">
        </div>
        <div class="col-md-3">
            <label class="form-label">Minimum Value</label>
            <input type="number" class="form-control" id="minValue" placeholder="Min inventory value" onchange="filterTable()">
        </div>
        <div class="col-md-3">
            <label class="form-label">Sort By</label>
            <select class="form-select" id="sortBy" onchange="sortTable()">
                <option value="name">Tile Name</option>
                <option value="stock">Available Stock</option>
                <option value="value">Inventory Value</option>
                <option value="cost">Cost per Box</option>
            </select>
        </div>
    </div>
</div>

<!-- Inventory Table -->
<div class="card inventory-table">
    <div class="table-responsive">
        <table class="table table-hover mb-0" id="inventoryTable">
            <thead class="table-dark">
                <tr>
                    <th style="width: 200px;">Tile Details</th>
                    <th style="width: 120px;">Total Received</th>
                    <th style="width: 100px;">Total Sold</th>
                    <th style="width: 100px;">Returns</th>
                    <th style="width: 120px;">Available Boxes</th>
                    <th style="width: 120px;">Remaining Stock</th>
                    <th style="width: 120px;">Cost per Box</th>
                    <th style="width: 150px;">Total Cost Value</th>
                    <th style="width: 100px;">Stock Status</th>
                    <th style="width: 140px;">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($inventory_data)): ?>
                    <tr>
                        <td colspan="10" class="text-center py-4">
                            <i class="bi bi-inbox display-4 text-muted d-block mb-2"></i>
                            <h5 class="text-muted">No inventory data found</h5>
                            <p class="text-muted">Add some tiles to your inventory to see data here.</p>
                            <a href="tiles_inventory.php" class="btn btn-primary">Add Inventory</a>
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($inventory_data as $item): 
                        $available_boxes = (float)$item['available_boxes'];
                        $remaining_boxes = (float)$item['current_stock']; // Same as available for now
                        $total_cost_value = (float)$item['total_cost_value'];
                        $avg_cost = (float)$item['avg_cost_per_box'];
                        
                        // Determine stock status
                        if ($available_boxes <= 0) {
                            $stock_class = 'stock-out';
                            $stock_text = 'Out of Stock';
                        } elseif ($available_boxes < 5) {
                            $stock_class = 'stock-critical';
                            $stock_text = 'Critical';
                        } elseif ($available_boxes < 10) {
                            $stock_class = 'stock-low';
                            $stock_text = 'Low Stock';
                        } else {
                            $stock_class = 'stock-good';
                            $stock_text = 'Good';
                        }
                    ?>
                        <tr data-tile-name="<?= h(strtolower($item['tile_name'] . ' ' . $item['size_label'])) ?>" 
                            data-stock-status="<?= $available_boxes <= 0 ? 'out' : ($available_boxes < 5 ? 'low' : 'good') ?>"
                            data-inventory-value="<?= $total_cost_value ?>">
                            
                            <!-- Tile Details -->
                            <td>
                                <div class="fw-bold text-primary"><?= h($item['tile_name']) ?></div>
                                <small class="text-muted"><?= h($item['size_label']) ?></small>
                                <div class="small text-info">ID: <?= $item['tile_id'] ?></div>
                            </td>
                            
                            <!-- Total Received -->
                            <td>
                                <div class="value-display text-success"><?= number_format($item['total_received'], 1) ?></div>
                                <small class="text-muted">boxes</small>
                            </td>
                            
                            <!-- Total Sold -->  
                            <td>
                                <div class="value-display text-danger"><?= number_format($item['total_sold'], 1) ?></div>
                                <small class="text-muted">sold</small>
                            </td>
                            
                            <!-- Returns -->
                            <td>
                                <div class="value-display text-info"><?= number_format($item['total_returned'], 1) ?></div>
                                <small class="text-muted">returned</small>
                            </td>
                            
                            <!-- Available Boxes -->
                            <td>
                                <div class="value-display text-primary"><?= number_format($available_boxes, 1) ?></div>
                                <small class="text-muted">available</small>
                                <?php if ($item['sqft_per_box'] > 0): ?>
                                    <div class="small text-muted"><?= number_format($available_boxes * $item['sqft_per_box'], 1) ?> sq.ft</div>
                                <?php endif; ?>
                            </td>
                            
                            <!-- Remaining Stock (same as available for now) -->
                            <td>
                                <div class="value-display"><?= number_format($remaining_boxes, 1) ?></div>
                                <small class="text-muted">remaining</small>
                            </td>
                            
                            <!-- Cost per Box -->
                            <td>
                                <div class="value-display">₹<?= number_format($avg_cost, 2) ?></div>
                                <?php if ($item['sqft_per_box'] > 0): ?>
                                    <div class="small text-muted">₹<?= number_format($avg_cost / $item['sqft_per_box'], 2) ?>/sq.ft</div>
                                <?php endif; ?>
                            </td>
                            
                            <!-- Total Cost Value -->
                            <td>
                                <div class="cost-breakdown">
                                    <div class="value-display text-success">₹<?= number_format($total_cost_value, 0) ?></div>
                                    <small class="text-muted d-block">Available Stock Value</small>
                                    <?php if ($available_boxes > 0): ?>
                                        <small class="text-info">₹<?= number_format($total_cost_value / $available_boxes, 2) ?>/box avg</small>
                                    <?php endif; ?>
                                </div>
                            </td>
                            
                            <!-- Stock Status -->
                            <td>
                                <span class="stock-status <?= $stock_class ?>"><?= $stock_text ?></span>
                                <?php if ($available_boxes > 0 && $available_boxes < 10): ?>
                                    <div class="small text-warning mt-1">
                                        <i class="bi bi-exclamation-triangle"></i> Reorder Soon
                                    </div>
                                <?php endif; ?>
                            </td>
                            
                            <!-- Actions -->
                            <td>
                                <div class="action-buttons">
                                    <a href="tiles_purchase.php?tile_id=<?= $item['tile_id'] ?>" 
                                       class="btn btn-sm btn-success" title="Add Stock">
                                        <i class="bi bi-plus-circle"></i>
                                    </a>
                                    <button type="button" class="btn btn-sm btn-info" 
                                            onclick="viewTileHistory(<?= $item['tile_id'] ?>)" title="View History">
                                        <i class="bi bi-clock-history"></i>
                                    </button>
                                    <a href="invoice_enhanced.php?tile_id=<?= $item['tile_id'] ?>" 
                                       class="btn btn-sm btn-primary" title="Create Sale">
                                        <i class="bi bi-receipt"></i>
                                    </a>
                                    <?php if ($available_boxes > 0): ?>
                                        <button type="button" class="btn btn-sm btn-warning" 
                                                onclick="adjustStock(<?= $item['tile_id'] ?>, <?= $available_boxes ?>)" title="Adjust Stock">
                                            <i class="bi bi-pencil"></i>
                                        </button>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
            
            <?php if (!empty($inventory_data)): ?>
            <tfoot class="table-light">
                <tr>
                    <th>Totals:</th>
                    <th><?= number_format(array_sum(array_column($inventory_data, 'total_received')), 1) ?></th>
                    <th><?= number_format(array_sum(array_column($inventory_data, 'total_sold')), 1) ?></th>
                    <th><?= number_format(array_sum(array_column($inventory_data, 'total_returned')), 1) ?></th>
                    <th class="text-primary fw-bold"><?= number_format($totals['total_boxes_available'], 1) ?></th>
                    <th class="text-primary fw-bold"><?= number_format($totals['total_boxes_available'], 1) ?></th>
                    <th>-</th>
                    <th class="text-success fw-bold">₹<?= number_format($totals['total_inventory_value'], 0) ?></th>
                    <th>-</th>
                    <th>-</th>
                </tr>
            </tfoot>
            <?php endif; ?>
        </table>
    </div>
</div>

<!-- Stock Adjustment Modal -->
<div class="modal fade" id="stockAdjustmentModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form id="adjustmentForm" onsubmit="submitAdjustment(event)">
                <div class="modal-header">
                    <h5 class="modal-title">Adjust Stock Level</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" id="adjustTileId">
                    
                    <div class="mb-3">
                        <label class="form-label">Current Stock</label>
                        <input type="number" class="form-control" id="currentStock" readonly>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">New Stock Level</label>
                        <input type="number" step="0.1" class="form-control" id="newStock" required>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Adjustment Reason</label>
                        <select class="form-select" id="adjustmentReason" required>
                            <option value="">Select reason...</option>
                            <option value="physical_count">Physical Count Correction</option>
                            <option value="damage">Damage/Breakage</option>
                            <option value="loss">Loss/Theft</option>
                            <option value="return">Customer Return</option>
                            <option value="transfer">Transfer</option>
                            <option value="other">Other</option>
                        </select>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Notes</label>
                        <textarea class="form-control" id="adjustmentNotes" rows="2" placeholder="Additional details..."></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-warning">
                        <i class="bi bi-check-circle"></i> Adjust Stock
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
// Filter and search functionality
function filterTable() {
    const stockFilter = document.getElementById('stockFilter').value.toLowerCase();
    const searchText = document.getElementById('searchTiles').value.toLowerCase();
    const minValue = parseFloat(document.getElementById('minValue').value) || 0;
    
    const rows = document.querySelectorAll('#inventoryTable tbody tr[data-tile-name]');
    
    rows.forEach(row => {
        const tileName = row.getAttribute('data-tile-name');
        const stockStatus = row.getAttribute('data-stock-status');
        const inventoryValue = parseFloat(row.getAttribute('data-inventory-value')) || 0;
        
        let show = true;
        
        // Filter by stock status
        if (stockFilter && stockStatus !== stockFilter) {
            show = false;
        }
        
        // Filter by search text
        if (searchText && !tileName.includes(searchText)) {
            show = false;
        }
        
        // Filter by minimum value
        if (minValue > 0 && inventoryValue < minValue) {
            show = false;
        }
        
        row.style.display = show ? '' : 'none';
    });
}

// Sort table functionality
function sortTable() {
    const sortBy = document.getElementById('sortBy').value;
    const tbody = document.querySelector('#inventoryTable tbody');
    const rows = Array.from(tbody.querySelectorAll('tr[data-tile-name]'));
    
    rows.sort((a, b) => {
        let aValue, bValue;
        
        switch (sortBy) {
            case 'name':
                aValue = a.getAttribute('data-tile-name');
                bValue = b.getAttribute('data-tile-name');
                return aValue.localeCompare(bValue);
                
            case 'stock':
                aValue = parseFloat(a.children[4].querySelector('.value-display').textContent.replace(/,/g, ''));
                bValue = parseFloat(b.children[4].querySelector('.value-display').textContent.replace(/,/g, ''));
                return bValue - aValue; // Descending
                
            case 'value':
                aValue = parseFloat(a.getAttribute('data-inventory-value'));
                bValue = parseFloat(b.getAttribute('data-inventory-value'));
                return bValue - aValue; // Descending
                
            case 'cost':
                aValue = parseFloat(a.children[6].querySelector('.value-display').textContent.replace(/₹|,/g, ''));
                bValue = parseFloat(b.children[6].querySelector('.value-display').textContent.replace(/₹|,/g, ''));
                return bValue - aValue; // Descending
                
            default:
                return 0;
        }
    });
    
    // Re-append sorted rows
    rows.forEach(row => tbody.appendChild(row));
}

// Export functionality
function exportInventory() {
    const table = document.getElementById('inventoryTable');
    const rows = table.querySelectorAll('tr:not([style*="display: none"])');
    
    let csvContent = "data:text/csv;charset=utf-8,";
    
    rows.forEach((row, index) => {
        const cols = row.querySelectorAll(index === 0 ? 'th' : 'td');
        const rowData = Array.from(cols).slice(0, -1).map(col => { // Exclude actions column
            let text = col.textContent.trim().replace(/"/g, '""');
            return `"${text}"`;
        });
        csvContent += rowData.join(",") + "\r\n";
    });
    
    const encodedUri = encodeURI(csvContent);
    const link = document.createElement("a");
    link.setAttribute("href", encodedUri);
    link.setAttribute("download", `inventory_summary_${new Date().toISOString().split('T')[0]}.csv`);
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
}

// View tile history
function viewTileHistory(tileId) {
    window.open(`tiles_inventory.php?tile_id=${tileId}&view=history`, '_blank');
}

// Stock adjustment
function adjustStock(tileId, currentStock) {
    document.getElementById('adjustTileId').value = tileId;
    document.getElementById('currentStock').value = currentStock.toFixed(1);
    document.getElementById('newStock').value = currentStock.toFixed(1);
    new bootstrap.Modal(document.getElementById('stockAdjustmentModal')).show();
}

function submitAdjustment(event) {
    event.preventDefault();
    
    const tileId = document.getElementById('adjustTileId').value;
    const newStock = document.getElementById('newStock').value;
    const reason = document.getElementById('adjustmentReason').value;
    const notes = document.getElementById('adjustmentNotes').value;
    
    // Create form and submit
    const form = document.createElement('form');
    form.method = 'POST';
    form.action = 'tiles_inventory.php';
    form.innerHTML = `
        <input type="hidden" name="adjust_stock" value="1">
        <input type="hidden" name="tile_id" value="${tileId}">
        <input type="hidden" name="new_stock" value="${newStock}">
        <input type="hidden" name="adjustment_reason" value="${reason}">
        <input type="hidden" name="adjustment_notes" value="${notes}">
    `;
    
    document.body.appendChild(form);
    form.submit();
}

// Auto-refresh every 5 minutes
setInterval(() => {
    if (!document.hidden) {
        location.reload();
    }
}, 300000);

// Initialize table on page load
document.addEventListener('DOMContentLoaded', function() {
    // Set default sort
    sortTable();
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>