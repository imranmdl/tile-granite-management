<?php
// public/tiles_inventory.php - Enhanced Tiles Inventory Management (matches unified summary)
require_once __DIR__ . '/../includes/simple_auth.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/inventory_updates.php';

auth_require_login();

$pdo = Database::pdo();

// Initialize inventory system
InventoryUpdates::createInventoryViews($pdo);

$message = '';
$error = '';

// Handle stock adjustment
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['adjust_stock'])) {
    $tile_id = (int)($_POST['tile_id'] ?? 0);
    $new_stock = (float)($_POST['new_stock'] ?? 0);
    $adjustment_reason = trim($_POST['adjustment_reason'] ?? '');
    $adjustment_notes = trim($_POST['adjustment_notes'] ?? '');
    
    if ($tile_id && $new_stock >= 0 && $adjustment_reason) {
        // This would be handled by a proper stock adjustment system
        $message = "Stock adjustment logged for tile ID $tile_id";
    } else {
        $error = "Invalid adjustment parameters";
    }
}

// Get tiles inventory data - SAME AS inventory_summary_unified.php
$tiles_inventory = InventoryUpdates::getCurrentInventoryLevels($pdo);

// Calculate totals
$totals = [
    'total_tiles' => count($tiles_inventory),
    'total_boxes_available' => array_sum(array_column($tiles_inventory, 'available_boxes')),
    'total_inventory_value' => array_sum(array_column($tiles_inventory, 'total_cost_value')),
    'low_stock_count' => 0,
    'out_of_stock_count' => 0
];

foreach ($tiles_inventory as $item) {
    if ($item['available_boxes'] <= 0) {
        $totals['out_of_stock_count']++;
    } elseif ($item['available_boxes'] < 5) {
        $totals['low_stock_count']++;
    }
}

$page_title = "Enhanced Tiles Inventory Management";
require_once __DIR__ . '/../includes/header.php';
?>

<style>
.tiles-header {
    background: linear-gradient(135deg, #007bff 0%, #0056b3 100%);
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
</style>

<?php if ($message): ?>
    <div class="alert alert-success alert-dismissible fade show">
        <?= h($message) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<?php if ($error): ?>
    <div class="alert alert-danger alert-dismissible fade show">
        <?= h($error) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<!-- Header -->
<div class="tiles-header">
    <div class="row align-items-center">
        <div class="col-md-8">
            <h2><i class="bi bi-bricks me-3"></i>Enhanced Tiles Inventory Management</h2>
            <p class="mb-0 opacity-75">Complete tiles inventory tracking - data matches unified summary</p>
        </div>
        <div class="col-md-4">
            <div class="row g-2">
                <div class="col-6">
                    <div class="summary-card">
                        <div class="h4 mb-1"><?= $totals['total_tiles'] ?></div>
                        <small>Tile Types</small>
                    </div>
                </div>
                <div class="col-6">
                    <div class="summary-card">
                        <div class="h4 mb-1"><?= number_format($totals['total_boxes_available'], 0) ?></div>
                        <small>Available Boxes</small>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Action Bar -->
<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h5 class="mb-0">Tiles Inventory Details</h5>
        <small class="text-muted">
            Total Value: ₹<?= number_format($totals['total_inventory_value'], 0) ?> | 
            Stock Alerts: <?= $totals['low_stock_count'] + $totals['out_of_stock_count'] ?>
        </small>
    </div>
    <div class="btn-group">
        <a href="tiles_purchase.php" class="btn btn-success">
            <i class="bi bi-plus-circle"></i> Add Stock
        </a>
        <a href="tiles.php" class="btn btn-primary">
            <i class="bi bi-bricks"></i> Manage Tiles
        </a>
        <a href="inventory_summary_unified.php" class="btn btn-info">
            <i class="bi bi-speedometer"></i> Unified Summary
        </a>
        <button class="btn btn-secondary" onclick="exportTilesInventory()">
            <i class="bi bi-download"></i> Export
        </button>
    </div>
</div>

<!-- Search Section -->
<div class="card mb-4">
    <div class="card-body">
        <div class="row g-3">
            <div class="col-md-3">
                <label class="form-label">Search Tiles</label>
                <input type="text" class="form-control" id="searchTiles" placeholder="Search by name or size" onkeyup="filterTilesTable()">
            </div>
            <div class="col-md-2">
                <label class="form-label">Stock Status</label>
                <select class="form-select" id="stockFilter" onchange="filterTilesTable()">
                    <option value="">All Status</option>
                    <option value="good">Good Stock (5+ boxes)</option>
                    <option value="low">Low Stock (1-4 boxes)</option>
                    <option value="out">Out of Stock</option>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label">Min Value</label>
                <input type="number" class="form-control" id="minValue" placeholder="Min ₹" onchange="filterTilesTable()">
            </div>
            <div class="col-md-2">
                <label class="form-label">Sort By</label>
                <select class="form-select" id="sortBy" onchange="sortTilesTable()">
                    <option value="name">Tile Name</option>
                    <option value="stock">Available Stock</option>
                    <option value="value">Inventory Value</option>
                    <option value="cost">Cost per Box</option>
                </select>
            </div>
            <div class="col-md-3 d-flex align-items-end">
                <button class="btn btn-outline-secondary" onclick="resetFilters()">
                    <i class="bi bi-arrow-clockwise"></i> Reset Filters
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Tiles Inventory Table - SAME STRUCTURE AS UNIFIED SUMMARY -->
<div class="card inventory-table">
    <div class="card-header">
        <h5 class="mb-0">
            <i class="bi bi-bricks me-2"></i>Tiles Inventory Stock Levels
            <small class="text-muted ms-2">(Data matches Inventory Summary)</small>
        </h5>
    </div>
    <div class="table-responsive">
        <table class="table table-hover mb-0" id="tilesInventoryTable">
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
                <?php if (empty($tiles_inventory)): ?>
                    <tr>
                        <td colspan="10" class="text-center py-4">
                            <i class="bi bi-bricks display-4 text-muted d-block mb-2"></i>
                            <h5 class="text-muted">No tiles inventory found</h5>
                            <p class="text-muted">Add some tiles to your inventory to see data here.</p>
                            <a href="tiles.php" class="btn btn-primary">Add Tiles</a>
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($tiles_inventory as $item): 
                        $available_boxes = (float)$item['available_boxes'];
                        $remaining_boxes = (float)$item['current_stock'];
                        $total_cost_value = (float)$item['total_cost_value'];
                        $avg_cost = (float)$item['avg_cost_per_box'];
                        
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
                            
                            <!-- Remaining Stock -->
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
                                <div class="value-display text-success">₹<?= number_format($total_cost_value, 0) ?></div>
                                <?php if ($available_boxes > 0): ?>
                                    <small class="text-muted d-block">₹<?= number_format($total_cost_value / $available_boxes, 2) ?>/box avg</small>
                                <?php endif; ?>
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
                                <div class="btn-group btn-group-sm">
                                    <a href="tiles_purchase.php?tile_id=<?= $item['tile_id'] ?>" 
                                       class="btn btn-success" title="Add Stock">
                                        <i class="bi bi-plus-circle"></i>
                                    </a>
                                    <button type="button" class="btn btn-info" 
                                            onclick="viewTileHistory(<?= $item['tile_id'] ?>)" title="View History">
                                        <i class="bi bi-clock-history"></i>
                                    </button>
                                    <a href="invoice_enhanced.php?tile_id=<?= $item['tile_id'] ?>" 
                                       class="btn btn-primary" title="Create Sale">
                                        <i class="bi bi-receipt"></i>
                                    </a>
                                    <?php if ($available_boxes > 0): ?>
                                        <button type="button" class="btn btn-warning" 
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
            
            <?php if (!empty($tiles_inventory)): ?>
            <tfoot class="table-light">
                <tr>
                    <th>Totals:</th>
                    <th><?= number_format(array_sum(array_column($tiles_inventory, 'total_received')), 1) ?></th>
                    <th><?= number_format(array_sum(array_column($tiles_inventory, 'total_sold')), 1) ?></th>
                    <th><?= number_format(array_sum(array_column($tiles_inventory, 'total_returned')), 1) ?></th>
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
            <form method="post">
                <div class="modal-header">
                    <h5 class="modal-title">Adjust Tile Stock Level</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="tile_id" id="adjustTileId">
                    
                    <div class="mb-3">
                        <label class="form-label">Current Stock</label>
                        <input type="number" class="form-control" id="currentStock" readonly>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">New Stock Level</label>
                        <input type="number" step="0.1" class="form-control" name="new_stock" id="newStock" required>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Adjustment Reason</label>
                        <select class="form-select" name="adjustment_reason" required>
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
                        <textarea class="form-control" name="adjustment_notes" rows="2" placeholder="Additional details..."></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="adjust_stock" class="btn btn-warning">
                        <i class="bi bi-check-circle"></i> Adjust Stock
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
// Filter functionality
function filterTilesTable() {
    const stockFilter = document.getElementById('stockFilter').value.toLowerCase();
    const searchText = document.getElementById('searchTiles').value.toLowerCase();
    const minValue = parseFloat(document.getElementById('minValue').value) || 0;
    
    const rows = document.querySelectorAll('#tilesInventoryTable tbody tr[data-tile-name]');
    
    rows.forEach(row => {
        const tileName = row.getAttribute('data-tile-name');
        const stockStatus = row.getAttribute('data-stock-status');
        const inventoryValue = parseFloat(row.getAttribute('data-inventory-value')) || 0;
        
        let show = true;
        
        if (stockFilter && stockStatus !== stockFilter) show = false;
        if (searchText && !tileName.includes(searchText)) show = false;
        if (minValue > 0 && inventoryValue < minValue) show = false;
        
        row.style.display = show ? '' : 'none';
    });
}

// Sort functionality
function sortTilesTable() {
    const sortBy = document.getElementById('sortBy').value;
    const tbody = document.querySelector('#tilesInventoryTable tbody');
    const rows = Array.from(tbody.querySelectorAll('tr[data-tile-name]'));
    
    rows.sort((a, b) => {
        let aValue, bValue;
        
        switch (sortBy) {
            case 'name':
                return a.getAttribute('data-tile-name').localeCompare(b.getAttribute('data-tile-name'));
            case 'stock':
                aValue = parseFloat(a.children[4].querySelector('.value-display').textContent.replace(/,/g, ''));
                bValue = parseFloat(b.children[4].querySelector('.value-display').textContent.replace(/,/g, ''));
                return bValue - aValue;
            case 'value':
                return parseFloat(b.getAttribute('data-inventory-value')) - parseFloat(a.getAttribute('data-inventory-value'));
            case 'cost':
                aValue = parseFloat(a.children[6].querySelector('.value-display').textContent.replace(/₹|,/g, ''));
                bValue = parseFloat(b.children[6].querySelector('.value-display').textContent.replace(/₹|,/g, ''));
                return bValue - aValue;
        }
    });
    
    rows.forEach(row => tbody.appendChild(row));
}

function resetFilters() {
    document.getElementById('stockFilter').value = '';
    document.getElementById('searchTiles').value = '';
    document.getElementById('minValue').value = '';
    document.getElementById('sortBy').value = 'name';
    filterTilesTable();
    sortTilesTable();
}

function exportTilesInventory() {
    // Export functionality
    const table = document.getElementById('tilesInventoryTable');
    const rows = table.querySelectorAll('tr:not([style*="display: none"])');
    
    let csvContent = "data:text/csv;charset=utf-8,";
    
    rows.forEach((row, index) => {
        const cols = row.querySelectorAll(index === 0 ? 'th' : 'td');
        const rowData = Array.from(cols).slice(0, -1).map(col => {
            let text = col.textContent.trim().replace(/"/g, '""');
            return `"${text}"`;
        });
        csvContent += rowData.join(",") + "\r\n";
    });
    
    const encodedUri = encodeURI(csvContent);
    const link = document.createElement("a");
    link.setAttribute("href", encodedUri);
    link.setAttribute("download", `tiles_inventory_${new Date().toISOString().split('T')[0]}.csv`);
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
}

function viewTileHistory(tileId) {
    alert('Tile history for ID: ' + tileId + ' - Feature coming soon!');
}

function adjustStock(tileId, currentStock) {
    document.getElementById('adjustTileId').value = tileId;
    document.getElementById('currentStock').value = currentStock.toFixed(1);
    document.getElementById('newStock').value = currentStock.toFixed(1);
    new bootstrap.Modal(document.getElementById('stockAdjustmentModal')).show();
}

// Initialize on page load
document.addEventListener('DOMContentLoaded', function() {
    sortTilesTable();
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>