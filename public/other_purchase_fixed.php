<?php
// public/other_purchase.php - Miscellaneous Items Purchase Entry
require_once __DIR__ . '/../includes/simple_auth.php';
require_once __DIR__ . '/../includes/helpers.php';

auth_require_login();

$pdo = Database::pdo();
$message = '';
$error = '';

// Get item ID if specified
$item_id = (int)($_GET['item_id'] ?? 0);
$selected_item = null;

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $user_id = $_SESSION['user_id'] ?? 1;
    
    if (isset($_POST['add_purchase'])) {
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
                $error = "Damage units cannot exceed total quantity";
            } else {
                try {
                    // Insert into misc_inventory_items table
                    $stmt = $pdo->prepare("
                        INSERT INTO misc_inventory_items 
                        (misc_item_id, purchase_date, qty_in, damage_units, cost_per_unit, transport_cost, vendor, invoice_no, notes, created_by)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ");
                    
                    if ($stmt->execute([
                        $misc_item_id, $purchase_date, $qty_in, $damage_units, 
                        $cost_per_unit, $transport_cost, $vendor, $invoice_no, $notes, $user_id
                    ])) {
                        $usable_qty = $qty_in - $damage_units;
                        $total_cost = $qty_in * $cost_per_unit + $transport_cost;
                        $message = "Purchase entry added successfully! Usable quantity: {$usable_qty}, Total cost: ₹" . number_format($total_cost, 2);
                        
                        // Clear form data
                        $_POST = [];
                    } else {
                        $error = "Failed to add purchase entry";
                    }
                } catch (Exception $e) {
                    $error = "Database error: " . $e->getMessage();
                }
            }
        } else {
            $error = "Item, quantity, and cost per unit are required";
        }
    }
    
    // Handle item status toggle
    if (isset($_POST['toggle_status'])) {
        $toggle_item_id = (int)($_POST['toggle_item_id'] ?? 0);
        $current_status = (int)($_POST['current_status'] ?? 1);
        $new_status = $current_status ? 0 : 1;
        
        try {
            $stmt = $pdo->prepare("UPDATE misc_items SET active = ? WHERE id = ?");
            if ($stmt->execute([$new_status, $toggle_item_id])) {
                $status_text = $new_status ? 'activated' : 'deactivated';
                $message = "Item {$status_text} successfully";
            } else {
                $error = "Failed to update item status";
            }
        } catch (Exception $e) {
            $error = "Database error: " . $e->getMessage();
        }
    }
}

// Get all misc items with active status
$misc_items = [];
try {
    $misc_items = $pdo->query("
        SELECT id, name, unit_label, COALESCE(description, '') as description, 
               COALESCE(active, 1) as active,
               CASE WHEN active = 0 THEN ' (HIDDEN)' ELSE '' END as status_suffix
        FROM misc_items 
        ORDER BY active DESC, name
    ")->fetchAll(PDO::FETCH_ASSOC);
    
    // Get selected item details if specified
    if ($item_id > 0) {
        $stmt = $pdo->prepare("SELECT * FROM misc_items WHERE id = ?");
        $stmt->execute([$item_id]);
        $selected_item = $stmt->fetch(PDO::FETCH_ASSOC);
    }
    
} catch (Exception $e) {
    $error = "Error loading items: " . $e->getMessage();
}

// Get recent purchases for display
$recent_purchases = [];
try {
    $recent_purchases = $pdo->query("
        SELECT 
            mii.purchase_date,
            m.name as item_name,
            m.unit_label,
            m.active,
            mii.qty_in,
            mii.damage_units,
            (mii.qty_in - COALESCE(mii.damage_units, 0)) as usable_quantity,
            mii.cost_per_unit,
            mii.transport_cost,
            (mii.qty_in * mii.cost_per_unit + COALESCE(mii.transport_cost, 0)) as total_cost,
            mii.vendor,
            mii.invoice_no
        FROM misc_inventory_items mii
        JOIN misc_items m ON mii.misc_item_id = m.id
        ORDER BY mii.purchase_date DESC, mii.id DESC
        LIMIT 10
    ")->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    // Ignore if tables don't exist yet
}

$page_title = "Other Items Purchase Entry";
require_once __DIR__ . '/../includes/header.php';
?>

<style>
.purchase-header {
    background: linear-gradient(135deg, #28a745 0%, #20c997 100%);
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
.calculation-box {
    background: #f8f9fa;
    border: 2px solid #e9ecef;
    border-radius: 10px;
    padding: 1rem;
    margin-top: 1rem;
}
.total-display {
    font-size: 1.25rem;
    font-weight: 700;
    color: #28a745;
}
.recent-purchases {
    max-height: 400px;
    overflow-y: auto;
}
.item-inactive {
    background-color: #f8f9fa;
    opacity: 0.7;
}
.status-toggle {
    border: none;
    background: none;
    padding: 2px 8px;
    border-radius: 4px;
    font-size: 0.8em;
}
</style>

<?php if ($message): ?>
    <div class="alert alert-success alert-dismissible fade show">
        <i class="bi bi-check-circle me-2"></i><?= h($message) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<?php if ($error): ?>
    <div class="alert alert-danger alert-dismissible fade show">
        <i class="bi bi-exclamation-triangle me-2"></i><?= h($error) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<!-- Header -->
<div class="purchase-header">
    <div class="row align-items-center">
        <div class="col-md-8">
            <h2><i class="bi bi-plus-circle me-3"></i>Other Items Purchase Entry</h2>
            <p class="mb-0 opacity-75">Add purchase entries for miscellaneous inventory items with active/inactive management</p>
        </div>
        <div class="col-md-4 text-end">
            <div class="bg-white bg-opacity-20 rounded p-3">
                <div class="h6 mb-1">Available Items</div>
                <div class="h4 mb-0"><?= count(array_filter($misc_items, fn($item) => $item['active'] == 1)) ?></div>
                <small class="opacity-75">Active items (<?= count($misc_items) ?> total)</small>
            </div>
        </div>
    </div>
</div>

<!-- Items Management Section -->
<div class="card mb-4">
    <div class="card-header">
        <h5 class="mb-0"><i class="bi bi-gear me-2"></i>Items Management</h5>
    </div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-sm">
                <thead>
                    <tr>
                        <th>Item Name</th>
                        <th>Unit</th>
                        <th>Description</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($misc_items as $item): ?>
                        <tr class="<?= $item['active'] ? '' : 'item-inactive' ?>">
                            <td>
                                <strong><?= h($item['name']) ?><?= h($item['status_suffix']) ?></strong>
                            </td>
                            <td><?= h($item['unit_label']) ?></td>
                            <td><?= h($item['description']) ?></td>
                            <td>
                                <span class="badge bg-<?= $item['active'] ? 'success' : 'secondary' ?>">
                                    <?= $item['active'] ? 'Active' : 'Hidden' ?>
                                </span>
                            </td>
                            <td>
                                <form method="post" class="d-inline">
                                    <input type="hidden" name="toggle_status" value="1">
                                    <input type="hidden" name="toggle_item_id" value="<?= $item['id'] ?>">
                                    <input type="hidden" name="current_status" value="<?= $item['active'] ?>">
                                    <button type="submit" class="status-toggle <?= $item['active'] ? 'text-warning' : 'text-success' ?>" 
                                            onclick="return confirm('<?= $item['active'] ? 'Hide' : 'Show' ?> this item?')">
                                        <i class="bi bi-<?= $item['active'] ? 'eye-slash' : 'eye' ?>"></i>
                                        <?= $item['active'] ? 'Hide' : 'Show' ?>
                                    </button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Purchase Entry Form -->
<?php if (!empty(array_filter($misc_items, fn($item) => $item['active'] == 1))): ?>
<div class="form-section">
    <h5 class="mb-4"><i class="bi bi-box-arrow-in-down me-2"></i>New Purchase Entry</h5>
    
    <form method="post" id="purchaseForm">
        <div class="row g-4">
            <!-- Item Selection -->
            <div class="col-md-4">
                <label class="form-label fw-bold">Select Active Item *</label>
                <select class="form-select form-select-lg" name="misc_item_id" required onchange="updateItemDetails()">
                    <option value="">Choose miscellaneous item...</option>
                    <?php foreach (array_filter($misc_items, fn($item) => $item['active'] == 1) as $item): ?>
                        <option value="<?= $item['id'] ?>" 
                                data-unit="<?= h($item['unit_label']) ?>"
                                data-description="<?= h($item['description']) ?>"
                                <?= ($item_id == $item['id']) ? 'selected' : '' ?>>
                            <?= h($item['name']) ?> (<?= h($item['unit_label']) ?>)
                            <?php if ($item['description']): ?>
                                - <?= h($item['description']) ?>
                            <?php endif; ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <div id="itemDetails" class="mt-2 text-muted small"></div>
            </div>
            
            <!-- Purchase Date -->
            <div class="col-md-2">
                <label class="form-label fw-bold">Purchase Date *</label>
                <input type="date" class="form-control form-control-lg" name="purchase_date" 
                       value="<?= h($_POST['purchase_date'] ?? date('Y-m-d')) ?>" required>
            </div>
            
            <!-- Total Quantity -->
            <div class="col-md-2">
                <label class="form-label fw-bold">Total Quantity *</label>
                <input type="number" step="0.01" class="form-control form-control-lg" name="qty_in" 
                       value="<?= h($_POST['qty_in'] ?? '') ?>" required min="0" placeholder="0.00"
                       onchange="calculateTotals()">
                <small id="quantityUnit" class="text-muted">units</small>
            </div>
            
            <!-- Damage Quantity -->
            <div class="col-md-2">
                <label class="form-label fw-bold">Damage Quantity</label>
                <input type="number" step="0.01" class="form-control form-control-lg" name="damage_units" 
                       value="<?= h($_POST['damage_units'] ?? '0') ?>" min="0" placeholder="0.0"
                       onchange="calculateTotals()">
                <small class="text-muted">Damaged units</small>
            </div>
            
            <!-- Cost per Unit -->
            <div class="col-md-2">
                <label class="form-label fw-bold">Cost per Unit *</label>
                <div class="input-group input-group-lg">
                    <span class="input-group-text">₹</span>
                    <input type="number" step="0.01" class="form-control" name="cost_per_unit" 
                           value="<?= h($_POST['cost_per_unit'] ?? '') ?>" required min="0" placeholder="0.00"
                           onchange="calculateTotals()">
                </div>
            </div>
        </div>
        
        <div class="row g-4 mt-2">
            <!-- Transport Cost -->
            <div class="col-md-3">
                <label class="form-label fw-bold">Transport Cost</label>
                <div class="input-group">
                    <span class="input-group-text">₹</span>
                    <input type="number" step="0.01" class="form-control" name="transport_cost" 
                           value="<?= h($_POST['transport_cost'] ?? '0') ?>" min="0" placeholder="0.00"
                           onchange="calculateTotals()">
                </div>
            </div>
            
            <!-- Vendor -->
            <div class="col-md-3">
                <label class="form-label fw-bold">Vendor Name</label>
                <input type="text" class="form-control" name="vendor" 
                       value="<?= h($_POST['vendor'] ?? '') ?>" placeholder="Vendor name">
            </div>
            
            <!-- Invoice Number -->
            <div class="col-md-3">
                <label class="form-label fw-bold">Invoice Number</label>
                <input type="text" class="form-control" name="invoice_no" 
                       value="<?= h($_POST['invoice_no'] ?? '') ?>" placeholder="Invoice #">
            </div>
            
            <!-- Notes -->
            <div class="col-md-3">
                <label class="form-label fw-bold">Notes</label>
                <input type="text" class="form-control" name="notes" 
                       value="<?= h($_POST['notes'] ?? '') ?>" placeholder="Additional notes">
            </div>
        </div>
        
        <!-- Enhanced Calculations Box -->
        <div class="calculation-box" id="calculationBox" style="display: none;">
            <div class="row g-3">
                <div class="col-md-2">
                    <div class="text-center">
                        <div class="h6 text-muted">Total Quantity</div>
                        <div class="h5" id="totalQuantity">0</div>
                    </div>
                </div>
                <div class="col-md-2">
                    <div class="text-center">
                        <div class="h6 text-muted">Damage Qty</div>
                        <div class="h5 text-warning" id="damageQty">0</div>
                    </div>
                </div>
                <div class="col-md-2">
                    <div class="text-center">
                        <div class="h6 text-muted">Usable Quantity</div>
                        <div class="h5 text-success" id="usableQuantity">0</div>
                    </div>
                </div>
                <div class="col-md-2">
                    <div class="text-center">
                        <div class="h6 text-muted">Material Cost</div>
                        <div class="h5" id="materialCost">₹0</div>
                    </div>
                </div>
                <div class="col-md-2">
                    <div class="text-center">
                        <div class="h6 text-muted">Transport Cost</div>
                        <div class="h5" id="transportCostDisplay">₹0</div>
                    </div>
                </div>
                <div class="col-md-2">
                    <div class="text-center">
                        <div class="h6 text-muted">Total Cost</div>
                        <div class="total-display" id="totalCost">₹0</div>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="text-center mt-4">
            <button type="submit" name="add_purchase" class="btn btn-success btn-lg px-5">
                <i class="bi bi-plus-circle me-2"></i>Add Purchase Entry
            </button>
        </div>
    </form>
</div>
<?php else: ?>
<div class="form-section text-center">
    <i class="bi bi-exclamation-triangle display-1 text-warning mb-3"></i>
    <h4>No Active Miscellaneous Items Found</h4>
    <p class="text-muted">You need to add and activate miscellaneous items before you can make purchase entries.</p>
    <a href="misc_items.php" class="btn btn-warning btn-lg">
        <i class="bi bi-plus-circle me-2"></i>Add Misc Items
    </a>
</div>
<?php endif; ?>

<!-- Recent Purchases -->
<?php if (!empty($recent_purchases)): ?>
<div class="card">
    <div class="card-header">
        <h5 class="mb-0"><i class="bi bi-clock-history me-2"></i>Recent Purchase Entries</h5>
    </div>
    <div class="card-body recent-purchases">
        <div class="table-responsive">
            <table class="table table-hover table-sm">
                <thead class="table-light">
                    <tr>
                        <th>Date</th>
                        <th>Item</th>
                        <th>Total Qty</th>
                        <th>Damage Qty</th>
                        <th>Usable Qty</th>
                        <th>Cost/Unit</th>
                        <th>Total Cost</th>
                        <th>Vendor</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($recent_purchases as $purchase): ?>
                        <tr class="<?= $purchase['active'] ? '' : 'item-inactive' ?>">
                            <td><?= h(date('M j', strtotime($purchase['purchase_date']))) ?></td>
                            <td>
                                <strong><?= h($purchase['item_name']) ?></strong>
                                <small class="text-muted d-block"><?= h($purchase['unit_label']) ?></small>
                                <?php if (!$purchase['active']): ?>
                                    <small class="text-danger">(Hidden)</small>
                                <?php endif; ?>
                            </td>
                            <td><?= number_format($purchase['qty_in'], 2) ?></td>
                            <td class="text-warning"><?= number_format($purchase['damage_units'], 2) ?></td>
                            <td class="text-success fw-bold"><?= number_format($purchase['usable_quantity'], 2) ?></td>
                            <td>₹<?= number_format($purchase['cost_per_unit'], 2) ?></td>
                            <td class="fw-bold">₹<?= number_format($purchase['total_cost'], 2) ?></td>
                            <td><?= h($purchase['vendor']) ?: '-' ?></td>
                            <td>
                                <span class="badge bg-<?= $purchase['active'] ? 'success' : 'secondary' ?>">
                                    <?= $purchase['active'] ? 'Active' : 'Hidden' ?>
                                </span>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php endif; ?>

<script>
function updateItemDetails() {
    const select = document.querySelector('select[name="misc_item_id"]');
    const option = select.selectedOptions[0];
    const detailsDiv = document.getElementById('itemDetails');
    const quantityUnit = document.getElementById('quantityUnit');
    
    if (option && option.value) {
        const unit = option.getAttribute('data-unit');
        const description = option.getAttribute('data-description');
        
        detailsDiv.innerHTML = `
            <strong>Unit:</strong> ${unit}
            ${description ? `<br><strong>Description:</strong> ${description}` : ''}
        `;
        quantityUnit.textContent = unit;
        
        calculateTotals();
    } else {
        detailsDiv.innerHTML = '';
        quantityUnit.textContent = 'units';
        document.getElementById('calculationBox').style.display = 'none';
    }
}

function calculateTotals() {
    const totalQty = parseFloat(document.querySelector('input[name="qty_in"]').value) || 0;
    const damageQty = parseFloat(document.querySelector('input[name="damage_units"]').value) || 0;
    const costPerUnit = parseFloat(document.querySelector('input[name="cost_per_unit"]').value) || 0;
    const transport = parseFloat(document.querySelector('input[name="transport_cost"]').value) || 0;
    
    const usableQty = totalQty - damageQty;
    const materialCost = totalQty * costPerUnit;
    const totalCost = materialCost + transport;
    
    document.getElementById('totalQuantity').textContent = totalQty.toFixed(2);
    document.getElementById('damageQty').textContent = damageQty.toFixed(2);
    document.getElementById('usableQuantity').textContent = usableQty.toFixed(2);
    document.getElementById('materialCost').textContent = '₹' + materialCost.toFixed(2);
    document.getElementById('transportCostDisplay').textContent = '₹' + transport.toFixed(2);
    document.getElementById('totalCost').textContent = '₹' + totalCost.toFixed(2);
    
    // Show calculation box if we have values
    if (totalQty > 0 && costPerUnit > 0) {
        document.getElementById('calculationBox').style.display = 'block';
    } else {
        document.getElementById('calculationBox').style.display = 'none';
    }
}

// Initialize on page load
document.addEventListener('DOMContentLoaded', function() {
    updateItemDetails();
    calculateTotals();
});

// Form validation
document.getElementById('purchaseForm').addEventListener('submit', function(e) {
    const totalQty = parseFloat(document.querySelector('input[name="qty_in"]').value) || 0;
    const damageQty = parseFloat(document.querySelector('input[name="damage_units"]').value) || 0;
    
    if (damageQty > totalQty) {
        e.preventDefault();
        alert('Damage quantity cannot exceed total quantity!');
        return false;
    }
    
    if (totalQty <= 0) {
        e.preventDefault();
        alert('Total quantity must be greater than 0!');
        return false;
    }
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>