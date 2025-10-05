<?php
// public/inventory_advanced.php - Fixed Miscellaneous Inventory Management
require_once __DIR__ . '/../includes/simple_auth.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/inventory_updates.php';

auth_require_login();

$pdo = Database::pdo();
$message = '';
$error = '';

// Check actual table structure and create/fix tables
try {
    // Get existing columns for misc_items table
    $columns = $pdo->query("PRAGMA table_info(misc_items)")->fetchAll(PDO::FETCH_ASSOC);
    $has_unit_label = false;
    $has_unit = false;
    
    foreach ($columns as $col) {
        if ($col['name'] === 'unit_label') $has_unit_label = true;
        if ($col['name'] === 'unit') $has_unit = true;
    }
    
    // Create or fix misc_items table
    if (empty($columns)) {
        $pdo->exec("
            CREATE TABLE misc_items (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT NOT NULL,
                unit_label TEXT NOT NULL DEFAULT 'units',
                description TEXT,
                created_at TEXT DEFAULT CURRENT_TIMESTAMP
            )
        ");
    } elseif (!$has_unit_label && $has_unit) {
        // Rename unit to unit_label if needed
        $pdo->exec("ALTER TABLE misc_items RENAME COLUMN unit TO unit_label");
    } elseif (!$has_unit_label && !$has_unit) {
        // Add unit_label column
        $pdo->exec("ALTER TABLE misc_items ADD COLUMN unit_label TEXT DEFAULT 'units'");
    }

    // Check misc_inventory_items table
    $inv_columns = $pdo->query("PRAGMA table_info(misc_inventory_items)")->fetchAll(PDO::FETCH_ASSOC);
    
    if (empty($inv_columns)) {
        $pdo->exec("
            CREATE TABLE misc_inventory_items (
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
    }
    
} catch (Exception $e) {
    $error = "Database setup error: " . $e->getMessage();
}

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$error) {
    $user_id = $_SESSION['user_id'] ?? 1;
    
    // Add new misc item
    if (isset($_POST['add_misc_item'])) {
        $name = trim($_POST['name'] ?? '');
        $unit_label = trim($_POST['unit_label'] ?? 'units');
        $description = trim($_POST['description'] ?? '');
        
        if ($name && $unit_label) {
            try {
                $stmt = $pdo->prepare("INSERT INTO misc_items (name, unit_label, description) VALUES (?, ?, ?)");
                if ($stmt->execute([$name, $unit_label, $description])) {
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
        $qty_in = (float)($_POST['qty_in'] ?? 0);
        $damage_units = (float)($_POST['damage_units'] ?? 0);
        $cost_per_unit = (float)($_POST['cost_per_unit'] ?? 0);
        $transport_cost = (float)($_POST['transport_cost'] ?? 0);
        $vendor = trim($_POST['vendor'] ?? '');
        $invoice_no = trim($_POST['invoice_no'] ?? '');
        $notes = trim($_POST['notes'] ?? '');
        
        if ($misc_item_id && $qty_in > 0 && $cost_per_unit > 0) {
            if ($damage_units > $qty_in) {
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
                        $misc_item_id, $purchase_date, $qty_in, $damage_units, 
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
}

// Get all misc items
try {
    $misc_items = $pdo->query("SELECT id, name, unit_label, description FROM misc_items ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $misc_items = [];
    if (!$error) $error = "Error loading misc items: " . $e->getMessage();
}

// Get inventory data - SAME STRUCTURE AS inventory_summary_unified.php
try {
    $inventory_sql = "
        SELECT 
            m.id, 
            m.name, 
            m.unit_label as unit, 
            m.description,
            COALESCE(inv.total_received, 0) as total_received,
            COALESCE(inv.net_received, 0) as net_received,
            COALESCE(inv.total_cost, 0) as total_cost,
            COALESCE(inv.avg_cost, 0) as avg_cost_per_unit,
            COALESCE(sold.total_sold, 0) as total_sold,
            COALESCE(returned.total_returned, 0) as total_returned,
            (COALESCE(inv.net_received, 0) - COALESCE(sold.total_sold, 0) + COALESCE(returned.total_returned, 0)) as current_stock,
            (COALESCE(inv.net_received, 0) - COALESCE(sold.total_sold, 0) + COALESCE(returned.total_returned, 0)) as available_quantity,
            (COALESCE(inv.net_received, 0) - COALESCE(sold.total_sold, 0) + COALESCE(returned.total_returned, 0)) * COALESCE(inv.avg_cost, 0) as total_cost_value
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
            SELECT misc_item_id, SUM(qty_units) as total_sold
            FROM invoice_misc_items 
            GROUP BY misc_item_id
        ) sold ON m.id = sold.misc_item_id
        LEFT JOIN (
            SELECT misc_item_id, SUM(qty_units) as total_returned
            FROM invoice_return_misc_items 
            GROUP BY misc_item_id
        ) returned ON m.id = returned.misc_item_id
        ORDER BY m.name
    ";
    
    $inventory_data = $pdo->query($inventory_sql)->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $inventory_data = [];
    if (!$error) $error = "Error loading inventory data: " . $e->getMessage();
}

$page_title = "Miscellaneous Inventory Management";
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
.value-display { font-weight: 700; font-size: 1.1rem; }
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
            <p class="mb-0 opacity-75">Manage non-tile inventory items - matches unified summary data</p>
        </div>
        <div class="col-md-4">
            <div class="text-center bg-white bg-opacity-20 rounded p-3">
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
            <select class="form-select" name="unit_label" required>
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
                        <?= h($item['name']) ?> (<?= h($item['unit_label']) ?>)
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
            <input type="number" step="0.01" class="form-control" name="qty_in" required min="0">
        </div>
        <div class="col-md-2">
            <label class="form-label">Damage Qty</label>
            <input type="number" step="0.01" class="form-control" name="damage_units" min="0" value="0">
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

<!-- Inventory Table - SAME STRUCTURE AS UNIFIED SUMMARY -->
<div class="card">
    <div class="card-header">
        <h5 class="mb-0">
            <i class="bi bi-list-ul me-2"></i>Miscellaneous Inventory Stock Levels
            <small class="text-muted ms-2">(Data matches Inventory Summary)</small>
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
                    <th>Available Quantity</th>
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
                        $available_qty = (float)$item['available_quantity'];
                        $stock_value = (float)$item['total_cost_value'];
                        
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
                                <div class="value-display text-primary"><?= number_format($available_qty, 2) ?></div>
                                <small class="text-muted">available</small>
                            </td>
                            <td>
                                <div class="value-display"><?= number_format($current_stock, 2) ?></div>
                                <small class="text-muted">remaining</small>
                            </td>
                            <td>
                                <div class="value-display">₹<?= number_format($item['avg_cost_per_unit'], 2) ?></div>
                            </td>
                            <td>
                                <div class="value-display text-success">₹<?= number_format($stock_value, 0) ?></div>
                            </td>
                            <td>
                                <span class="stock-indicator <?= $stock_class ?>"><?= $stock_text ?></span>
                            </td>
                            <td>
                                <div class="btn-group btn-group-sm">
                                    <a href="inventory_summary_unified.php" class="btn btn-info" title="View in Summary">
                                        <i class="bi bi-eye"></i>
                                    </a>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="text-center mt-4">
    <a href="inventory_summary_unified.php" class="btn btn-primary">
        <i class="bi bi-speedometer me-2"></i>View Unified Inventory Summary
    </a>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>