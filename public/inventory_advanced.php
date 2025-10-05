<?php
// public/inventory_advanced.php - Enhanced Miscellaneous Inventory Management
require_once __DIR__ . '/../includes/simple_auth.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/inventory_updates.php';

auth_require_login();

$pdo = Database::pdo();
$message = '';
$error = '';

// Ensure misc inventory tables exist
$pdo->exec("
    CREATE TABLE IF NOT EXISTS misc_items (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT NOT NULL,
        unit TEXT NOT NULL DEFAULT 'units',
        description TEXT,
        created_at TEXT DEFAULT CURRENT_TIMESTAMP
    )
");

$pdo->exec("
    CREATE TABLE IF NOT EXISTS misc_inventory_items (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        misc_item_id INTEGER NOT NULL,
        purchase_date TEXT NOT NULL,
        qty_in REAL NOT NULL,
        damage_units REAL DEFAULT 0,
        cost_per_unit REAL NOT NULL,
        transport_cost REAL DEFAULT 0,
        vendor TEXT,
        invoice_no TEXT,
        notes TEXT,
        created_by INTEGER,
        created_at TEXT DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (misc_item_id) REFERENCES misc_items(id)
    )
");

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $user_id = $_SESSION['user_id'] ?? 1;
    
    // Add new misc item
    if (isset($_POST['add_misc_item'])) {
        $name = trim($_POST['name'] ?? '');
        $unit = trim($_POST['unit'] ?? 'units');
        $description = trim($_POST['description'] ?? '');
        
        if ($name && $unit) {
            try {
                $stmt = $pdo->prepare("INSERT INTO misc_items (name, unit, description) VALUES (?, ?, ?)");
                if ($stmt->execute([$name, $unit, $description])) {
                    $message = "Misc item '$name' added successfully";
                } else {
                    $error = "Failed to add misc item";
                }
            } catch (Exception $e) {
                $error = "Database error: " . $e->getMessage();
            }
        } else {
            $error = "Item name and unit are required";
        }
    }
    
    // Add inventory entry
    if (isset($_POST['add_inventory'])) {
        $misc_item_id = (int)($_POST['misc_item_id'] ?? 0);
        $purchase_date = $_POST['purchase_date'] ?? date('Y-m-d');
        $quantity = (float)($_POST['quantity'] ?? 0);
        $damage_quantity = (float)($_POST['damage_quantity'] ?? 0);
        $cost_per_unit = (float)($_POST['cost_per_unit'] ?? 0);
        $transport_cost = (float)($_POST['transport_cost'] ?? 0);
        $vendor = trim($_POST['vendor'] ?? '');
        $invoice_no = trim($_POST['invoice_no'] ?? '');
        $notes = trim($_POST['notes'] ?? '');
        
        if ($misc_item_id && $quantity > 0 && $cost_per_unit > 0) {
            if ($damage_quantity > $quantity) {
                $error = "Damage quantity cannot exceed total quantity";
            } else {
                try {
                    $stmt = $pdo->prepare("
                        INSERT INTO misc_inventory_items 
                        (misc_item_id, purchase_date, qty_in, damage_units, cost_per_unit, 
                         transport_cost, vendor, invoice_no, notes, created_by)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ");
                    
                    if ($stmt->execute([
                        $misc_item_id, $purchase_date, $quantity, $damage_quantity, 
                        $cost_per_unit, $transport_cost, $vendor, $invoice_no, $notes, $user_id
                    ])) {
                        $message = "Inventory entry added successfully";
                    } else {
                        $error = "Failed to add inventory entry";
                    }
                } catch (Exception $e) {
                    $error = "Database error: " . $e->getMessage();
                }
            }
        } else {
            $error = "Item, quantity, and cost per unit are required";
        }
    }
    
    // Stock adjustment
    if (isset($_POST['adjust_stock'])) {
        $misc_item_id = (int)($_POST['misc_item_id'] ?? 0);
        $new_quantity = (float)($_POST['new_quantity'] ?? 0);
        $adjustment_reason = trim($_POST['adjustment_reason'] ?? '');
        $notes = trim($_POST['notes'] ?? '');
        
        if ($misc_item_id && $new_quantity >= 0 && $adjustment_reason) {
            try {
                // Get current stock
                $current_stmt = $pdo->prepare("
                    SELECT COALESCE(SUM(qty_in - COALESCE(damage_units, 0)), 0) 
                    FROM misc_inventory_items 
                    WHERE misc_item_id = ?
                ");
                $current_stmt->execute([$misc_item_id]);
                $current_stock = (float)$current_stmt->fetchColumn();
                
                $adjustment = $new_quantity - $current_stock;
                
                if ($adjustment != 0) {
                    $stmt = $pdo->prepare("
                        INSERT INTO misc_inventory_items 
                        (misc_item_id, purchase_date, qty_in, damage_units, cost_per_unit, 
                         vendor, notes, created_by)
                        VALUES (?, ?, ?, 0, 0, ?, ?, ?)
                    ");
                    
                    $adjustment_notes = "Stock adjustment: $adjustment_reason. ";
                    $adjustment_notes .= "Previous: $current_stock, New: $new_quantity. ";
                    if ($notes) $adjustment_notes .= "Notes: $notes";
                    
                    if ($stmt->execute([
                        $misc_item_id, date('Y-m-d'), $adjustment, 
                        'STOCK_ADJUSTMENT', $adjustment_notes, $user_id
                    ])) {
                        $message = "Stock adjusted from " . number_format($current_stock, 2) . " to " . number_format($new_quantity, 2);
                    } else {
                        $error = "Failed to adjust stock";
                    }
                } else {
                    $error = "New quantity is same as current stock";
                }
            } catch (Exception $e) {
                $error = "Database error: " . $e->getMessage();
            }
        } else {
            $error = "Item, quantity, and reason are required";
        }
    }
}

// Get all misc items
$misc_items = $pdo->query("SELECT * FROM misc_items ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);

// Get inventory data with current stock calculations
$inventory_sql = "
    SELECT 
        m.id, m.name, m.unit, m.description,
        COALESCE(inv.total_received, 0) as total_received,
        COALESCE(inv.net_received, 0) as net_received,
        COALESCE(inv.total_cost, 0) as total_cost,
        COALESCE(inv.avg_cost, 0) as avg_cost,
        COALESCE(sold.total_sold, 0) as total_sold,
        COALESCE(returned.total_returned, 0) as total_returned,
        (COALESCE(inv.net_received, 0) - COALESCE(sold.total_sold, 0) + COALESCE(returned.total_returned, 0)) as current_stock,
        (COALESCE(inv.net_received, 0) - COALESCE(sold.total_sold, 0) + COALESCE(returned.total_returned, 0)) * COALESCE(inv.avg_cost, 0) as stock_value
    FROM misc_items m
    LEFT JOIN (
        SELECT 
            misc_item_id,
            SUM(qty_in) as total_received,
            SUM(qty_in - COALESCE(damage_units, 0)) as net_received,
            SUM((qty_in - COALESCE(damage_units, 0)) * cost_per_unit) as total_cost,
            CASE 
                WHEN SUM(qty_in - COALESCE(damage_units, 0)) > 0 
                THEN SUM((qty_in - COALESCE(damage_units, 0)) * cost_per_unit) / SUM(qty_in - COALESCE(damage_units, 0))
                ELSE 0 
            END as avg_cost
        FROM misc_inventory_items 
        GROUP BY misc_item_id
    ) inv ON m.id = inv.misc_item_id
    LEFT JOIN (
        SELECT misc_item_id, SUM(quantity) as total_sold
        FROM invoice_misc_items 
        GROUP BY misc_item_id
    ) sold ON m.id = sold.misc_item_id
    LEFT JOIN (
        SELECT misc_item_id, SUM(quantity) as total_returned
        FROM invoice_return_misc_items 
        GROUP BY misc_item_id
    ) returned ON m.id = returned.misc_item_id
    ORDER BY m.name
";

$inventory_data = $pdo->query($inventory_sql)->fetchAll(PDO::FETCH_ASSOC);

$page_title = "Enhanced Miscellaneous Inventory Management";
require_once __DIR__ . '/../includes/header.php';
?>

<style>
.inventory-header {
    background: linear-gradient(135deg, #fd7e14 0%, #dc6545 100%);
    color: white;
    border-radius: 15px;
    padding: 2rem;
    margin-bottom: 2rem;
}

.form-section {
    background: white;
    border-radius: 15px;
    padding: 2rem;
    margin-bottom: 2rem;
    box-shadow: 0 10px 30px rgba(0,0,0,0.1);
}

.inventory-table {
    border-radius: 10px;
    overflow: hidden;
    box-shadow: 0 10px 30px rgba(0,0,0,0.1);
}

.stock-indicator {
    display: inline-block;
    padding: 4px 12px;
    border-radius: 20px;
    font-size: 0.85rem;
    font-weight: 600;
}

.stock-good { background: #d4edda; color: #155724; }
.stock-low { background: #fff3cd; color: #856404; }
.stock-out { background: #f8d7da; color: #721c24; }

.value-display {
    font-weight: 700;
    font-size: 1.1rem;
}

.quick-stats {
    background: rgba(255, 255, 255, 0.1);
    border-radius: 10px;
    padding: 1rem;
    text-align: center;
    backdrop-filter: blur(10px);
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
<div class="inventory-header">
    <div class="row align-items-center">
        <div class="col-md-8">
            <h2><i class="bi bi-gear-wide me-3"></i>Miscellaneous Inventory Management</h2>
            <p class="mb-0 opacity-75">Manage non-tile inventory items with complete tracking</p>
        </div>
        <div class="col-md-4">
            <div class="quick-stats">
                <div class="h4 mb-1"><?= count($misc_items) ?></div>
                <small>Total Item Types</small>
            </div>
        </div>
    </div>
</div>

<!-- Add New Item Form -->
<div class="form-section">
    <h5 class="mb-4"><i class="bi bi-plus-circle me-2"></i>Add New Miscellaneous Item</h5>
    
    <form method="post" class="row g-3">
        <div class="col-md-4">
            <label class="form-label">Item Name *</label>
            <input type="text" class="form-control" name="name" required placeholder="e.g., Cement, Sand, Tools">
        </div>
        <div class="col-md-2">
            <label class="form-label">Unit *</label>
            <select class="form-select" name="unit" required>
                <option value="units">Units</option>
                <option value="kg">Kilograms</option>
                <option value="bags">Bags</option>
                <option value="meters">Meters</option>
                <option value="liters">Liters</option>
                <option value="pieces">Pieces</option>
                <option value="sets">Sets</option>
            </select>
        </div>
        <div class="col-md-4">
            <label class="form-label">Description</label>
            <input type="text" class="form-control" name="description" placeholder="Brief description">
        </div>
        <div class="col-md-2">
            <label class="form-label">&nbsp;</label>
            <button type="submit" name="add_misc_item" class="btn btn-warning w-100">
                <i class="bi bi-plus-circle"></i> Add Item
            </button>
        </div>
    </form>
</div>

<!-- Add Inventory Entry Form -->
<?php if (!empty($misc_items)): ?>
<div class="form-section">
    <h5 class="mb-4"><i class="bi bi-box-arrow-in-down me-2"></i>Add Inventory Entry</h5>
    
    <form method="post" class="row g-3">
        <div class="col-md-3">
            <label class="form-label">Select Item *</label>
            <select class="form-select" name="misc_item_id" required>
                <option value="">Choose item...</option>
                <?php foreach ($misc_items as $item): ?>
                    <option value="<?= $item['id'] ?>">
                        <?= h($item['name']) ?> (<?= h($item['unit']) ?>)
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-2">
            <label class="form-label">Purchase Date *</label>
            <input type="date" class="form-control" name="purchase_date" value="<?= date('Y-m-d') ?>" required>
        </div>
        <div class="col-md-2">
            <label class="form-label">Quantity *</label>
            <input type="number" step="0.01" class="form-control" name="quantity" required min="0">
        </div>
        <div class="col-md-2">
            <label class="form-label">Damage Qty</label>
            <input type="number" step="0.01" class="form-control" name="damage_quantity" min="0" value="0">
        </div>
        <div class="col-md-3">
            <label class="form-label">Cost per Unit *</label>
            <input type="number" step="0.01" class="form-control" name="cost_per_unit" required min="0" placeholder="₹">
        </div>
        
        <div class="col-md-2">
            <label class="form-label">Transport Cost</label>
            <input type="number" step="0.01" class="form-control" name="transport_cost" min="0" value="0" placeholder="₹">
        </div>
        <div class="col-md-3">
            <label class="form-label">Vendor</label>
            <input type="text" class="form-control" name="vendor" placeholder="Supplier name">
        </div>
        <div class="col-md-2">
            <label class="form-label">Invoice No</label>
            <input type="text" class="form-control" name="invoice_no" placeholder="Invoice #">
        </div>
        <div class="col-md-3">
            <label class="form-label">Notes</label>
            <input type="text" class="form-control" name="notes" placeholder="Additional notes">
        </div>
        <div class="col-md-2">
            <label class="form-label">&nbsp;</label>
            <button type="submit" name="add_inventory" class="btn btn-success w-100">
                <i class="bi bi-plus-circle"></i> Add Entry
            </button>
        </div>
    </form>
</div>
<?php endif; ?>

<!-- Inventory Table -->
<div class="card inventory-table">
    <div class="card-header">
        <h5 class="mb-0">
            <i class="bi bi-list-ul me-2"></i>Miscellaneous Inventory Stock Levels
        </h5>
    </div>
    <div class="table-responsive">
        <table class="table table-hover mb-0">
            <thead class="table-dark">
                <tr>
                    <th>Item Details</th>
                    <th>Total Received</th>
                    <th>Total Sold</th>
                    <th>Returns</th>
                    <th>Available Stock</th>
                    <th>Remaining Stock</th>
                    <th>Avg Cost/Unit</th>
                    <th>Stock Value</th>
                    <th>Status</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($inventory_data)): ?>
                    <tr>
                        <td colspan="10" class="text-center py-4">
                            <i class="bi bi-gear-wide display-4 text-muted d-block mb-2"></i>
                            <h5 class="text-muted">No miscellaneous inventory found</h5>
                            <p class="text-muted">Add some misc items above to get started.</p>
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($inventory_data as $item): 
                        $current_stock = (float)$item['current_stock'];
                        $stock_value = (float)$item['stock_value'];
                        
                        if ($current_stock <= 0) {
                            $stock_class = 'stock-out';
                            $stock_text = 'Out of Stock';
                        } elseif ($current_stock < 10) {
                            $stock_class = 'stock-low';
                            $stock_text = 'Low Stock';
                        } else {
                            $stock_class = 'stock-good';
                            $stock_text = 'Good';
                        }
                    ?>
                        <tr>
                            <td>
                                <div class="fw-bold text-warning"><?= h($item['name']) ?></div>
                                <small class="text-muted"><?= h($item['unit']) ?></small>
                                <?php if ($item['description']): ?>
                                    <div class="small text-info"><?= h($item['description']) ?></div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div class="value-display text-success"><?= number_format($item['total_received'], 2) ?></div>
                                <small class="text-muted"><?= h($item['unit']) ?></small>
                            </td>
                            <td>
                                <div class="value-display text-danger"><?= number_format($item['total_sold'], 2) ?></div>
                                <small class="text-muted">sold</small>
                            </td>
                            <td>
                                <div class="value-display text-info"><?= number_format($item['total_returned'], 2) ?></div>
                                <small class="text-muted">returned</small>
                            </td>
                            <td>
                                <div class="value-display text-primary"><?= number_format($current_stock, 2) ?></div>
                                <small class="text-muted">available</small>
                            </td>
                            <td>
                                <div class="value-display"><?= number_format($current_stock, 2) ?></div>
                                <small class="text-muted">remaining</small>
                            </td>
                            <td>
                                <div class="value-display">₹<?= number_format($item['avg_cost'], 2) ?></div>
                            </td>
                            <td>
                                <div class="value-display text-success">₹<?= number_format($stock_value, 0) ?></div>
                            </td>
                            <td>
                                <span class="stock-indicator <?= $stock_class ?>"><?= $stock_text ?></span>
                            </td>
                            <td>
                                <div class="btn-group btn-group-sm">
                                    <button type="button" class="btn btn-warning" 
                                            onclick="adjustStock(<?= $item['id'] ?>, '<?= h($item['name']) ?>', <?= $current_stock ?>)" 
                                            title="Adjust Stock">
                                        <i class="bi bi-pencil"></i>
                                    </button>
                                    <button type="button" class="btn btn-info" 
                                            onclick="viewHistory(<?= $item['id'] ?>, '<?= h($item['name']) ?>')" 
                                            title="View History">
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

<!-- Stock Adjustment Modal -->
<div class="modal fade" id="stockAdjustmentModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="post">
                <div class="modal-header">
                    <h5 class="modal-title">Adjust Stock Level</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="misc_item_id" id="adjustItemId">
                    
                    <div class="mb-3">
                        <label class="form-label">Item</label>
                        <input type="text" class="form-control" id="adjustItemName" readonly>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Current Stock</label>
                        <input type="number" class="form-control" id="currentStock" readonly>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">New Stock Level *</label>
                        <input type="number" step="0.01" class="form-control" name="new_quantity" id="newQuantity" required>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Adjustment Reason *</label>
                        <select class="form-select" name="adjustment_reason" required>
                            <option value="">Select reason...</option>
                            <option value="physical_count">Physical Count Correction</option>
                            <option value="damage">Damage/Wastage</option>
                            <option value="loss">Loss/Theft</option>
                            <option value="usage">Internal Usage</option>
                            <option value="return">Customer Return</option>
                            <option value="other">Other</option>
                        </select>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Notes</label>
                        <textarea class="form-control" name="notes" rows="2" placeholder="Additional details..."></textarea>
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
function adjustStock(itemId, itemName, currentStock) {
    document.getElementById('adjustItemId').value = itemId;
    document.getElementById('adjustItemName').value = itemName;
    document.getElementById('currentStock').value = currentStock.toFixed(2);
    document.getElementById('newQuantity').value = currentStock.toFixed(2);
    
    new bootstrap.Modal(document.getElementById('stockAdjustmentModal')).show();
}

function viewHistory(itemId, itemName) {
    alert('History view for "' + itemName + '" (ID: ' + itemId + ') - Feature coming soon!');
}

// Auto-refresh every 5 minutes
setInterval(() => {
    if (!document.hidden) {
        location.reload();
    }
}, 300000);
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>