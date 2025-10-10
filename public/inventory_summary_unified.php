<?php
// public/inventory_summary_unified.php - UNIFIED Inventory Summary (Tiles + Misc Items)
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

// Get TILES inventory data
$tiles_inventory = InventoryUpdates::getCurrentInventoryLevels($pdo);

// Get MISC ITEMS inventory data
function getMiscInventoryLevels(PDO $pdo) {
    $sql = "
        SELECT 
            m.id as item_id,
            m.name as item_name,
            m.unit_label as unit,
            '' as description,
            'misc' as item_type,
            
            -- Purchase totals
            COALESCE(p.total_quantity_received, 0) as total_received,
            COALESCE(p.total_net_quantity, 0) as total_net_received,
            
            -- Sales totals
            COALESCE(s.total_quantity_sold, 0) as total_sold,
            
            -- Returns totals
            COALESCE(r.total_quantity_returned, 0) as total_returned,
            
            -- Current calculations
            (COALESCE(p.total_net_quantity, 0) - COALESCE(s.total_quantity_sold, 0) + COALESCE(r.total_quantity_returned, 0)) as current_stock,
            (COALESCE(p.total_net_quantity, 0) - COALESCE(s.total_quantity_sold, 0) + COALESCE(r.total_quantity_returned, 0)) as available_quantity,
            
            -- Cost calculations (UPDATED to include transport costs)
            CASE 
                WHEN COALESCE(p.total_net_quantity, 0) > 0
                THEN COALESCE(p.total_cost, 0) / COALESCE(p.total_net_quantity, 0)
                ELSE 0
            END as avg_cost_per_unit,
            
            (COALESCE(p.total_net_quantity, 0) - COALESCE(s.total_quantity_sold, 0) + COALESCE(r.total_quantity_returned, 0)) * 
            CASE 
                WHEN COALESCE(p.total_net_quantity, 0) > 0
                THEN COALESCE(p.total_cost, 0) / COALESCE(p.total_net_quantity, 0)
                ELSE 0
            END as total_cost_value
            
        FROM misc_items m
        
        -- Purchase entries (UPDATED to include transport costs)
        LEFT JOIN (
            SELECT 
                misc_item_id,
                SUM(qty_in) as total_quantity_received,
                SUM(qty_in - COALESCE(damage_units, 0)) as total_net_quantity,
                SUM((qty_in - COALESCE(damage_units, 0)) * (COALESCE(cost_per_unit, 0) + COALESCE(transport_cost, 0) / NULLIF(qty_in, 0))) as total_cost
            FROM misc_inventory_items 
            GROUP BY misc_item_id
        ) p ON m.id = p.misc_item_id
        
        -- Sales
        LEFT JOIN (
            SELECT 
                misc_item_id,
                SUM(qty_units) as total_quantity_sold
            FROM invoice_misc_items
            GROUP BY misc_item_id
        ) s ON m.id = s.misc_item_id
        
        -- Returns  
        LEFT JOIN (
            SELECT 
                misc_item_id,
                SUM(qty_units) as total_quantity_returned
            FROM invoice_return_misc_items
            GROUP BY misc_item_id
        ) r ON m.id = r.misc_item_id
        
        ORDER BY m.name
    ";
    
    return $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
}

$misc_inventory = getMiscInventoryLevels($pdo);

// Calculate combined totals
$totals = [
    'total_tile_types' => count($tiles_inventory),
    'total_misc_types' => count($misc_inventory),
    'total_items' => count($tiles_inventory) + count($misc_inventory),
    'tiles_available_boxes' => 0,
    'tiles_inventory_value' => 0,
    'misc_available_quantity' => 0,
    'misc_inventory_value' => 0,
    'total_inventory_value' => 0,
    'low_stock_count' => 0,
    'out_of_stock_count' => 0
];

// Calculate tiles totals
foreach ($tiles_inventory as $item) {
    $totals['tiles_available_boxes'] += (float)$item['available_boxes'];
    $totals['tiles_inventory_value'] += (float)$item['total_cost_value'];
    
    if ($item['available_boxes'] <= 0) {
        $totals['out_of_stock_count']++;
    } elseif ($item['available_boxes'] < 5) {
        $totals['low_stock_count']++;
    }
}

// Calculate misc totals  
foreach ($misc_inventory as $item) {
    $totals['misc_available_quantity'] += (float)$item['available_quantity'];
    $totals['misc_inventory_value'] += (float)$item['total_cost_value'];
    
    if ($item['available_quantity'] <= 0) {
        $totals['out_of_stock_count']++;
    } elseif ($item['available_quantity'] < 10) {
        $totals['low_stock_count']++;
    }
}

$totals['total_inventory_value'] = $totals['tiles_inventory_value'] + $totals['misc_inventory_value'];

$page_title = "Unified Inventory Summary - Tiles & Misc Items";
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

.inventory-section {
    background: white;
    border-radius: 15px;
    margin-bottom: 2rem;
    box-shadow: 0 10px 30px rgba(0,0,0,0.1);
    overflow: hidden;
}

.section-header {
    background: linear-gradient(135deg, #28a745, #20c997);
    color: white;
    padding: 1rem 1.5rem;
    border-radius: 15px 15px 0 0;
}

.tiles-header {
    background: linear-gradient(135deg, #007bff, #0056b3);
}

.misc-header {
    background: linear-gradient(135deg, #fd7e14, #dc6545);
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

.tabs-container {
    border-bottom: 2px solid #e9ecef;
    margin-bottom: 1rem;
}

.tab-button {
    background: none;
    border: none;
    padding: 1rem 2rem;
    font-weight: 600;
    color: #6c757d;
    border-bottom: 3px solid transparent;
    transition: all 0.3s ease;
}

.tab-button.active {
    color: #007bff;
    border-bottom-color: #007bff;
}

.tab-content {
    display: none;
}

.tab-content.active {
    display: block;
}

.quick-stats {
    background: #f8f9fa;
    border-radius: 10px;
    padding: 1rem;
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
        <h2><i class="bi bi-boxes me-3"></i>Unified Inventory Management</h2>
        <p class="mb-0 opacity-75">Complete inventory tracking - Tiles & Miscellaneous Items</p>
    </div>
    
    <div class="row g-4">
        <div class="col-md-2">
            <div class="summary-card">
                <div class="h4 mb-1"><?= $totals['total_tile_types'] ?></div>
                <small>Tile Types</small>
            </div>
        </div>
        <div class="col-md-2">
            <div class="summary-card">
                <div class="h4 mb-1"><?= $totals['total_misc_types'] ?></div>
                <small>Misc Items</small>
            </div>
        </div>
        <div class="col-md-2">
            <div class="summary-card">
                <div class="h4 mb-1"><?= number_format($totals['tiles_available_boxes'], 0) ?></div>
                <small>Tiles (Boxes)</small>
            </div>
        </div>
        <div class="col-md-2">
            <div class="summary-card">
                <div class="h4 mb-1"><?= number_format($totals['misc_available_quantity'], 0) ?></div>
                <small>Misc (Units)</small>
            </div>
        </div>
        <div class="col-md-2">
            <div class="summary-card">
                <div class="h4 mb-1">₹<?= number_format($totals['total_inventory_value'], 0) ?></div>
                <small>Total Value</small>
            </div>
        </div>
        <div class="col-md-2">
            <div class="summary-card">
                <div class="h4 mb-1 text-warning"><?= $totals['low_stock_count'] + $totals['out_of_stock_count'] ?></div>
                <small>Stock Alerts</small>
            </div>
        </div>
    </div>
    
    <div class="row g-3 mt-3">
        <div class="col-md-6">
            <div class="summary-card">
                <h6>Tiles Inventory</h6>
                <div class="h5 text-info">₹<?= number_format($totals['tiles_inventory_value'], 0) ?></div>
                <small><?= number_format($totals['tiles_available_boxes'], 1) ?> boxes available</small>
            </div>
        </div>
        <div class="col-md-6">
            <div class="summary-card">
                <h6>Misc Inventory</h6>
                <div class="h5 text-warning">₹<?= number_format($totals['misc_inventory_value'], 0) ?></div>
                <small><?= number_format($totals['misc_available_quantity'], 1) ?> units available</small>
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
                <i class="bi bi-arrow-clockwise"></i> Reconcile All
            </button>
        </form>
        <a href="tiles_inventory.php" class="btn btn-primary">
            <i class="bi bi-bricks"></i> Tiles Inventory
        </a>
        <a href="inventory_advanced.php" class="btn btn-info">
            <i class="bi bi-gear-wide"></i> Misc Inventory
        </a>
        <button class="btn btn-success" onclick="exportInventory()">
            <i class="bi bi-download"></i> Export All
        </button>
    </div>
</div>

<!-- Tabbed Interface -->
<div class="tabs-container">
    <button class="tab-button active" onclick="showTab('tiles')">
        <i class="bi bi-bricks me-2"></i>Tiles Inventory (<?= count($tiles_inventory) ?>)
    </button>
    <button class="tab-button" onclick="showTab('misc')">
        <i class="bi bi-gear-wide me-2"></i>Misc Items (<?= count($misc_inventory) ?>)
    </button>
    <button class="tab-button" onclick="showTab('combined')">
        <i class="bi bi-list-ul me-2"></i>Combined View
    </button>
</div>

<!-- TILES INVENTORY TAB -->
<div id="tilesTab" class="tab-content active">
    <div class="inventory-section">
        <div class="section-header tiles-header">
            <div class="d-flex justify-content-between align-items-center">
                <h5 class="mb-0"><i class="bi bi-bricks me-2"></i>Tiles Inventory</h5>
                <div class="text-end">
                    <div>₹<?= number_format($totals['tiles_inventory_value'], 0) ?> total value</div>
                    <small><?= number_format($totals['tiles_available_boxes'], 1) ?> boxes available</small>
                </div>
            </div>
        </div>
        
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Tile Details</th>
                        <th>Available Boxes</th>
                        <th>Remaining Stock</th>
                        <th>Cost per Box</th>
                        <th>Total Value</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($tiles_inventory)): ?>
                        <tr>
                            <td colspan="7" class="text-center py-4">
                                <i class="bi bi-inbox display-4 text-muted d-block mb-2"></i>
                                <h5 class="text-muted">No tiles inventory data</h5>
                                <a href="tiles_inventory.php" class="btn btn-primary">Add Tiles</a>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($tiles_inventory as $item): 
                            $available_boxes = (float)$item['available_boxes'];
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
                                $stock_text = 'Low';
                            } else {
                                $stock_class = 'stock-good';
                                $stock_text = 'Good';
                            }
                        ?>
                            <tr>
                                <td>
                                    <div class="fw-bold text-primary"><?= h($item['tile_name']) ?></div>
                                    <small class="text-muted"><?= h($item['size_label']) ?></small>
                                </td>
                                <td>
                                    <div class="value-display text-success"><?= number_format($available_boxes, 1) ?></div>
                                    <small class="text-muted">boxes</small>
                                </td>
                                <td>
                                    <div class="value-display"><?= number_format($available_boxes, 1) ?></div>
                                    <small class="text-muted">remaining</small>
                                </td>
                                <td>
                                    <div class="value-display">₹<?= number_format($avg_cost, 2) ?></div>
                                </td>
                                <td>
                                    <div class="value-display text-success">₹<?= number_format($total_cost_value, 0) ?></div>
                                </td>
                                <td>
                                    <span class="stock-status <?= $stock_class ?>"><?= $stock_text ?></span>
                                </td>
                                <td>
                                    <div class="btn-group btn-group-sm">
                                        <a href="tiles_purchase.php?tile_id=<?= $item['tile_id'] ?>" class="btn btn-success" title="Add Stock">
                                            <i class="bi bi-plus-circle"></i>
                                        </a>
                                        <button type="button" class="btn btn-info" onclick="viewHistory('tile', <?= $item['tile_id'] ?>)" title="History">
                                            <i class="bi bi-clock-history"></i>
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- MISC INVENTORY TAB -->
<div id="miscTab" class="tab-content">
    <div class="inventory-section">
        <div class="section-header misc-header">
            <div class="d-flex justify-content-between align-items-center">
                <h5 class="mb-0"><i class="bi bi-gear-wide me-2"></i>Miscellaneous Items</h5>
                <div class="text-end">
                    <div>₹<?= number_format($totals['misc_inventory_value'], 0) ?> total value</div>
                    <small><?= number_format($totals['misc_available_quantity'], 1) ?> units available</small>
                </div>
            </div>
        </div>
        
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Item Details</th>
                        <th>Available Quantity</th>
                        <th>Remaining Stock</th>
                        <th>Cost per Unit</th>
                        <th>Total Value</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($misc_inventory)): ?>
                        <tr>
                            <td colspan="7" class="text-center py-4">
                                <i class="bi bi-gear-wide display-4 text-muted d-block mb-2"></i>
                                <h5 class="text-muted">No miscellaneous inventory data</h5>
                                <a href="inventory_advanced.php" class="btn btn-warning">Add Misc Items</a>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($misc_inventory as $item): 
                            $available_qty = (float)$item['available_quantity'];
                            $total_cost_value = (float)$item['total_cost_value'];
                            $avg_cost = (float)$item['avg_cost_per_unit'];
                            
                            if ($available_qty <= 0) {
                                $stock_class = 'stock-out';
                                $stock_text = 'Out of Stock';
                            } elseif ($available_qty < 10) {
                                $stock_class = 'stock-critical';
                                $stock_text = 'Critical';
                            } elseif ($available_qty < 25) {
                                $stock_class = 'stock-low';
                                $stock_text = 'Low';
                            } else {
                                $stock_class = 'stock-good';
                                $stock_text = 'Good';
                            }
                        ?>
                            <tr>
                                <td>
                                    <div class="fw-bold text-warning"><?= h($item['item_name']) ?></div>
                                    <small class="text-muted"><?= h($item['unit']) ?> | <?= h($item['description']) ?></small>
                                </td>
                                <td>
                                    <div class="value-display text-warning"><?= number_format($available_qty, 1) ?></div>
                                    <small class="text-muted"><?= h($item['unit']) ?></small>
                                </td>
                                <td>
                                    <div class="value-display"><?= number_format($available_qty, 1) ?></div>
                                    <small class="text-muted">remaining</small>
                                </td>
                                <td>
                                    <div class="value-display">₹<?= number_format($avg_cost, 2) ?></div>
                                </td>
                                <td>
                                    <div class="value-display text-warning">₹<?= number_format($total_cost_value, 0) ?></div>
                                </td>
                                <td>
                                    <span class="stock-status <?= $stock_class ?>"><?= $stock_text ?></span>
                                </td>
                                <td>
                                    <div class="btn-group btn-group-sm">
                                        <a href="inventory_advanced.php?item_id=<?= $item['item_id'] ?>" class="btn btn-warning" title="Add Stock">
                                            <i class="bi bi-plus-circle"></i>
                                        </a>
                                        <button type="button" class="btn btn-info" onclick="viewHistory('misc', <?= $item['item_id'] ?>)" title="History">
                                            <i class="bi bi-clock-history"></i>
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- COMBINED VIEW TAB -->
<div id="combinedTab" class="tab-content">
    <div class="quick-stats">
        <div class="row text-center">
            <div class="col-md-3">
                <div class="h5 text-primary"><?= $totals['total_tile_types'] ?></div>
                <small>Tile Types</small>
            </div>
            <div class="col-md-3">
                <div class="h5 text-warning"><?= $totals['total_misc_types'] ?></div>
                <small>Misc Types</small>
            </div>
            <div class="col-md-3">
                <div class="h5 text-success">₹<?= number_format($totals['total_inventory_value'], 0) ?></div>
                <small>Total Value</small>
            </div>
            <div class="col-md-3">
                <div class="h5 text-danger"><?= $totals['low_stock_count'] + $totals['out_of_stock_count'] ?></div>
                <small>Alerts</small>
            </div>
        </div>
    </div>
    
    <div class="row">
        <div class="col-md-6">
            <div class="card">
                <div class="card-header bg-primary text-white">
                    <h6 class="mb-0"><i class="bi bi-bricks me-2"></i>Low Stock Tiles</h6>
                </div>
                <div class="card-body">
                    <?php 
                    $low_tiles = array_filter($tiles_inventory, function($item) {
                        return $item['available_boxes'] > 0 && $item['available_boxes'] < 10;
                    });
                    ?>
                    <?php if (empty($low_tiles)): ?>
                        <p class="text-muted">All tiles have adequate stock levels</p>
                    <?php else: ?>
                        <?php foreach (array_slice($low_tiles, 0, 5) as $item): ?>
                            <div class="d-flex justify-content-between align-items-center mb-2">
                                <div>
                                    <strong><?= h($item['tile_name']) ?></strong>
                                    <small class="text-muted d-block"><?= h($item['size_label']) ?></small>
                                </div>
                                <span class="badge bg-warning"><?= number_format($item['available_boxes'], 1) ?> boxes</span>
                            </div>
                        <?php endforeach; ?>
                        <?php if (count($low_tiles) > 5): ?>
                            <small class="text-muted">+<?= count($low_tiles) - 5 ?> more items</small>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        
        <div class="col-md-6">
            <div class="card">
                <div class="card-header bg-warning text-dark">
                    <h6 class="mb-0"><i class="bi bi-gear-wide me-2"></i>Low Stock Misc Items</h6>
                </div>
                <div class="card-body">
                    <?php 
                    $low_misc = array_filter($misc_inventory, function($item) {
                        return $item['available_quantity'] > 0 && $item['available_quantity'] < 25;
                    });
                    ?>
                    <?php if (empty($low_misc)): ?>
                        <p class="text-muted">All misc items have adequate stock levels</p>
                    <?php else: ?>
                        <?php foreach (array_slice($low_misc, 0, 5) as $item): ?>
                            <div class="d-flex justify-content-between align-items-center mb-2">
                                <div>
                                    <strong><?= h($item['item_name']) ?></strong>
                                    <small class="text-muted d-block"><?= h($item['unit']) ?></small>
                                </div>
                                <span class="badge bg-warning"><?= number_format($item['available_quantity'], 1) ?> <?= h($item['unit']) ?></span>
                            </div>
                        <?php endforeach; ?>
                        <?php if (count($low_misc) > 5): ?>
                            <small class="text-muted">+<?= count($low_misc) - 5 ?> more items</small>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
// Tab switching functionality
function showTab(tabName) {
    // Hide all tab contents
    document.querySelectorAll('.tab-content').forEach(content => {
        content.classList.remove('active');
    });
    
    // Remove active class from all tab buttons
    document.querySelectorAll('.tab-button').forEach(button => {
        button.classList.remove('active');
    });
    
    // Show selected tab content
    document.getElementById(tabName + 'Tab').classList.add('active');
    
    // Add active class to clicked tab button
    event.target.classList.add('active');
}

// Export functionality
function exportInventory() {
    const currentTab = document.querySelector('.tab-content.active');
    const table = currentTab.querySelector('table');
    
    if (!table) {
        alert('No data to export');
        return;
    }
    
    const rows = table.querySelectorAll('tr');
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
    link.setAttribute("download", `inventory_${new Date().toISOString().split('T')[0]}.csv`);
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
}

// View history
function viewHistory(type, itemId) {
    if (type === 'tile') {
        window.open(`tiles_inventory.php?tile_id=${itemId}&view=history`, '_blank');
    } else {
        window.open(`inventory_advanced.php?item_id=${itemId}&view=history`, '_blank');
    }
}

// Auto-refresh every 5 minutes
setInterval(() => {
    if (!document.hidden) {
        location.reload();
    }
}, 300000);
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>