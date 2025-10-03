<?php
// public/other_purchase.php - Purchase Entry for Other Items with Damage Calculations
require_once __DIR__ . '/../includes/simple_auth.php';
require_once __DIR__ . '/../includes/helpers.php';

auth_require_login();

$pdo = Database::pdo();
$message = '';
$error = '';

// Get item_id if provided
$item_id = (int)($_GET['item_id'] ?? 0);
$view_mode = $_GET['view'] ?? 'entry';

// Handle purchase entry submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_purchase'])) {
    $item_id = (int)$_POST['item_id'];
    $purchase_date = $_POST['purchase_date'];
    $supplier_name = trim($_POST['supplier_name'] ?? '');
    $invoice_number = trim($_POST['invoice_number'] ?? '');
    $total_quantity = (float)$_POST['total_quantity'];
    $damage_percentage = (float)$_POST['damage_percentage'];
    $cost_per_unit = (float)$_POST['cost_per_unit'];
    $transport_cost = (float)($_POST['transport_cost'] ?? 0);
    $transport_percentage = (float)($_POST['transport_percentage'] ?? 0);
    $notes = trim($_POST['notes'] ?? '');
    
    // Validation
    if (!$item_id || !$purchase_date || $total_quantity <= 0 || $cost_per_unit <= 0) {
        $error = 'Please fill in all required fields with valid values';
    } elseif ($damage_percentage < 0 || $damage_percentage > 100) {
        $error = 'Damage percentage must be between 0 and 100';
    } elseif ($transport_percentage < 0 || $transport_percentage > 200) {
        $error = 'Transport percentage must be between 0 and 200';
    } else {
        try {
            $stmt = $pdo->prepare("
                INSERT INTO purchase_entries_misc 
                (misc_item_id, purchase_date, supplier_name, invoice_number, total_quantity, 
                 damage_percentage, cost_per_unit, transport_cost, transport_percentage, notes)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            
            if ($stmt->execute([$item_id, $purchase_date, $supplier_name, $invoice_number, 
                               $total_quantity, $damage_percentage, $cost_per_unit, $transport_cost, $transport_percentage, $notes])) {
                $message = 'Purchase entry added successfully';
                // Reset form
                $_POST = [];
            } else {
                $error = 'Failed to add purchase entry';
            }
        } catch (Exception $e) {
            $error = 'Database error: ' . $e->getMessage();
        }
    }
}

// Get misc items for selection
$items_stmt = $pdo->query("
    SELECT m.id, m.name, m.unit_label
    FROM misc_items m
    ORDER BY m.name
");
$items = $items_stmt->fetchAll(PDO::FETCH_ASSOC);

// Get selected item info if item_id provided
$selected_item = null;
if ($item_id) {
    $stmt = $pdo->prepare("
        SELECT m.id, m.name, m.unit_label,
               cms.total_stock_quantity
        FROM misc_items m
        LEFT JOIN current_misc_stock cms ON m.id = cms.id
        WHERE m.id = ?
    ");
    $stmt->execute([$item_id]);
    $selected_item = $stmt->fetch(PDO::FETCH_ASSOC);
}

// Get purchase history for selected item
$purchase_history = [];
if ($item_id) {
    $stmt = $pdo->prepare("
        SELECT pe.*, 
               (pe.total_quantity * (1 - pe.damage_percentage/100)) as calculated_usable_quantity,
               CASE 
                   WHEN pe.transport_percentage > 0 THEN pe.cost_per_unit * (1 + pe.transport_percentage/100)
                   ELSE pe.cost_per_unit + (COALESCE(pe.transport_cost, 0) / pe.total_quantity)
               END as cost_per_unit_with_transport,
               (pe.total_quantity * pe.cost_per_unit + COALESCE(pe.transport_cost, 0)) as calculated_total_cost
        FROM purchase_entries_misc pe
        WHERE pe.misc_item_id = ?
        ORDER BY pe.purchase_date DESC, pe.created_at DESC
    ");
    $stmt->execute([$item_id]);
    $purchase_history = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

$page_title = $view_mode === 'history' ? "Other Items Purchase History" : "Other Items Purchase Entry";
require_once __DIR__ . '/../includes/header.php';
?>

<style>
.purchase-card {
    background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
    border-radius: 12px;
    border: 1px solid #dee2e6;
}

.calculation-card {
    background: linear-gradient(135deg, #e8f5e8 0%, #f1f8e9 100%);
    border-radius: 8px;
    border: 1px solid #c8e6c9;
}

.item-info-card {
    background: linear-gradient(135deg, #e3f2fd 0%, #f3e5f5 100%);
    border-radius: 8px;
    border: 1px solid #90caf9;
}

.damage-input {
    position: relative;
}

.damage-input .form-control {
    padding-right: 40px;
}

.damage-input .percentage-sign {
    position: absolute;
    right: 12px;
    top: 50%;
    transform: translateY(-50%);
    color: #6c757d;
    font-weight: bold;
}

.calculation-result {
    font-size: 1.1em;
    font-weight: 600;
    padding: 8px 12px;
    border-radius: 6px;
    margin: 5px 0;
}

.result-usable { background: #d1edff; color: #0066cc; }
.result-cost { background: #fff3e0; color: #f57c00; }
.result-final { background: #e8f5e8; color: #2e7d32; }

.history-table {
    font-size: 0.9em;
}

.history-table th {
    background: linear-gradient(135deg, #28a745 0%, #20c997 100%);
    color: white;
    border: none;
    font-weight: 600;
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

<!-- Navigation Tabs -->
<ul class="nav nav-tabs mb-4">
    <li class="nav-item">
        <a class="nav-link <?= $view_mode === 'entry' ? 'active' : '' ?>" 
           href="other_purchase.php<?= $item_id ? '?item_id=' . $item_id : '' ?>">
            <i class="bi bi-plus-circle"></i> New Purchase Entry
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link <?= $view_mode === 'history' ? 'active' : '' ?>" 
           href="other_purchase.php?view=history<?= $item_id ? '&item_id=' . $item_id : '' ?>">
            <i class="bi bi-clock-history"></i> Purchase History
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link" href="other_inventory.php">
            <i class="bi bi-arrow-left"></i> Back to Other Inventory
        </a>
    </li>
</ul>

<?php if ($view_mode === 'entry'): ?>
    <!-- Purchase Entry Form -->
    <div class="row">
        <div class="col-lg-8">
            <div class="card purchase-card">
                <div class="card-header">
                    <h5 class="mb-0"><i class="bi bi-cart-plus"></i> Add Purchase Entry - Other Items</h5>
                </div>
                <div class="card-body">
                    <form method="post" id="purchaseForm">
                        <div class="row g-3">
                            <!-- Item Selection -->
                            <div class="col-12">
                                <label class="form-label">Select Item *</label>
                                <select class="form-select" name="item_id" id="itemSelect" required onchange="updateItemInfo()">
                                    <option value="">Choose an item...</option>
                                    <?php foreach ($items as $item): ?>
                                        <option value="<?= $item['id'] ?>" <?= $item['id'] == $item_id ? 'selected' : '' ?>>
                                            <?= h($item['name']) ?> (<?= h($item['unit_label']) ?>)
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <!-- Purchase Details -->
                            <div class="col-md-6">
                                <label class="form-label">Purchase Date *</label>
                                <input type="date" class="form-control" name="purchase_date" 
                                       value="<?= $_POST['purchase_date'] ?? date('Y-m-d') ?>" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Supplier Name</label>
                                <input type="text" class="form-control" name="supplier_name" 
                                       value="<?= h($_POST['supplier_name'] ?? '') ?>" placeholder="Enter supplier name">
                            </div>

                            <div class="col-md-6">
                                <label class="form-label">Invoice Number</label>
                                <input type="text" class="form-control" name="invoice_number" 
                                       value="<?= h($_POST['invoice_number'] ?? '') ?>" placeholder="Enter invoice number">
                            </div>

                            <!-- Quantity and Damage -->
                            <div class="col-md-3">
                                <label class="form-label">Total Quantity *</label>
                                <input type="number" class="form-control" name="total_quantity" 
                                       value="<?= $_POST['total_quantity'] ?? '' ?>" 
                                       step="0.1" min="0.1" required oninput="calculateUsable()">
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Damage %</label>
                                <div class="damage-input">
                                    <input type="number" class="form-control" name="damage_percentage" 
                                           value="<?= $_POST['damage_percentage'] ?? 0 ?>" 
                                           step="0.1" min="0" max="100" oninput="calculateUsable()">
                                    <span class="percentage-sign">%</span>
                                </div>
                            </div>

                            <!-- Cost Details -->
                            <div class="col-md-4">
                                <label class="form-label">Cost per Unit *</label>
                                <div class="input-group">
                                    <span class="input-group-text">₹</span>
                                    <input type="number" class="form-control" name="cost_per_unit" 
                                           value="<?= $_POST['cost_per_unit'] ?? '' ?>" 
                                           step="0.01" min="0.01" required oninput="calculateCosts()">
                                </div>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Transport %</label>
                                <div class="input-group">
                                    <input type="number" class="form-control" name="transport_percentage" 
                                           value="<?= $_POST['transport_percentage'] ?? 0 ?>" 
                                           step="0.1" min="0" max="200" oninput="calculateCosts()" 
                                           placeholder="e.g., 30 for 30%">
                                    <span class="input-group-text">%</span>
                                </div>
                                <div class="form-text">Cost/Unit × (1 + Transport%)</div>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Fixed Transport Cost</label>
                                <div class="input-group">
                                    <span class="input-group-text">₹</span>
                                    <input type="number" class="form-control" name="transport_cost" 
                                           value="<?= $_POST['transport_cost'] ?? 0 ?>" 
                                           step="0.01" min="0" oninput="calculateCosts()" 
                                           placeholder="Optional">
                                </div>
                                <div class="form-text">Used if Transport % is 0</div>
                            </div>

                            <!-- Notes -->
                            <div class="col-12">
                                <label class="form-label">Notes</label>
                                <textarea class="form-control" name="notes" rows="3" 
                                          placeholder="Any additional notes about this purchase"><?= h($_POST['notes'] ?? '') ?></textarea>
                            </div>
                        </div>

                        <div class="mt-4">
                            <button type="submit" name="add_purchase" class="btn btn-primary btn-lg">
                                <i class="bi bi-check-circle"></i> Add Purchase Entry
                            </button>
                            <a href="other_inventory.php" class="btn btn-outline-secondary btn-lg ms-2">
                                <i class="bi bi-x-circle"></i> Cancel
                            </a>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <div class="col-lg-4">
            <!-- Live Calculations -->
            <div class="card calculation-card mb-3">
                <div class="card-header">
                    <h6 class="mb-0"><i class="bi bi-calculator"></i> Live Calculations</h6>
                </div>
                <div class="card-body">
                    <div id="calculationResults">
                        <p class="text-muted"><i class="bi bi-info-circle"></i> Enter values to see calculations</p>
                    </div>
                </div>
            </div>

            <!-- Selected Item Info -->
            <?php if ($selected_item): ?>
            <div class="card item-info-card">
                <div class="card-header">
                    <h6 class="mb-0"><i class="bi bi-info-circle"></i> Item Information</h6>
                </div>
                <div class="card-body">
                    <h6><?= h($selected_item['name']) ?></h6>
                    <p class="mb-2">
                        <strong>Unit:</strong> <?= h($selected_item['unit_label']) ?><br>
                    </p>
                    <hr>
                    <h6>Current Stock</h6>
                    <p class="mb-0">
                        <strong>Quantity:</strong> <?= number_format($selected_item['total_stock_quantity'] ?? 0, 1) ?> <?= h($selected_item['unit_label']) ?>
                    </p>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>

<?php else: ?>
    <!-- Purchase History View -->
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h4>Purchase History - Other Items</h4>
        <?php if ($item_id): ?>
            <div>
                <span class="badge bg-info">Item ID: <?= $item_id ?></span>
                <?php if ($selected_item): ?>
                    <span class="badge bg-success"><?= h($selected_item['name']) ?></span>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>

    <!-- Item Filter (if no specific item selected) -->
    <?php if (!$item_id): ?>
        <div class="mb-4">
            <label class="form-label">Filter by Item:</label>
            <select class="form-select" onchange="filterByItem(this.value)" style="max-width: 400px;">
                <option value="">All Items</option>
                <?php foreach ($items as $item): ?>
                    <option value="<?= $item['id'] ?>">
                        <?= h($item['name']) ?> (<?= h($item['unit_label']) ?>)
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
    <?php endif; ?>

    <!-- Purchase History Table -->
    <?php if (empty($purchase_history) && $item_id): ?>
        <div class="alert alert-info">
            <i class="bi bi-info-circle"></i> No purchase entries found for this item.
            <a href="other_purchase.php?item_id=<?= $item_id ?>" class="btn btn-sm btn-primary ms-2">
                Add First Purchase
            </a>
        </div>
    <?php elseif (!empty($purchase_history)): ?>
        <div class="table-responsive">
            <table class="table table-hover history-table">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Supplier</th>
                        <th>Invoice#</th>
                        <th>Total Quantity</th>
                        <th>Damage %</th>
                        <th>Usable Quantity</th>
                        <th>Cost/Unit</th>
                        <th>Cost + Transport</th>
                        <th>Transport %</th>
                        <th>Total Cost</th>
                        <th>Notes</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($purchase_history as $entry): ?>
                        <tr>
                            <td><?= date('M j, Y', strtotime($entry['purchase_date'])) ?></td>
                            <td><?= h($entry['supplier_name']) ?></td>
                            <td><?= h($entry['invoice_number']) ?></td>
                            <td><?= number_format($entry['total_quantity'], 1) ?></td>
                            <td><?= number_format($entry['damage_percentage'], 1) ?>%</td>
                            <td class="fw-bold text-success">
                                <?= number_format($entry['calculated_usable_quantity'], 1) ?>
                            </td>
                            <td>$<?= number_format($entry['cost_per_unit'], 2) ?></td>
                            <td>$<?= number_format($entry['transport_cost'], 2) ?></td>
                            <td class="fw-bold">$<?= number_format($entry['calculated_total_cost'], 2) ?></td>
                            <td>
                                <?php if ($entry['notes']): ?>
                                    <span class="text-truncate d-inline-block" style="max-width: 150px;" 
                                          title="<?= h($entry['notes']) ?>">
                                        <?= h($entry['notes']) ?>
                                    </span>
                                <?php else: ?>
                                    <span class="text-muted">—</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <!-- Summary Stats -->
        <div class="row mt-4">
            <div class="col-md-3">
                <div class="card text-center">
                    <div class="card-body">
                        <h6 class="card-title">Total Purchases</h6>
                        <h4 class="text-primary"><?= count($purchase_history) ?></h4>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card text-center">
                    <div class="card-body">
                        <h6 class="card-title">Total Quantity</h6>
                        <h4 class="text-info"><?= number_format(array_sum(array_column($purchase_history, 'total_quantity')), 1) ?></h4>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card text-center">
                    <div class="card-body">
                        <h6 class="card-title">Usable Quantity</h6>
                        <h4 class="text-success"><?= number_format(array_sum(array_column($purchase_history, 'calculated_usable_quantity')), 1) ?></h4>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card text-center">
                    <div class="card-body">
                        <h6 class="card-title">Total Investment</h6>
                        <h4 class="text-warning">$<?= number_format(array_sum(array_column($purchase_history, 'calculated_total_cost')), 2) ?></h4>
                    </div>
                </div>
            </div>
        </div>
    <?php endif; ?>
<?php endif; ?>

<script>
function calculateUsable() {
    const totalQuantity = parseFloat(document.querySelector('[name="total_quantity"]').value) || 0;
    const damagePercent = parseFloat(document.querySelector('[name="damage_percentage"]').value) || 0;
    
    const usableQuantity = totalQuantity * (1 - damagePercent/100);
    
    updateCalculationDisplay();
}

function calculateCosts() {
    updateCalculationDisplay();
}

function updateCalculationDisplay() {
    const totalQuantity = parseFloat(document.querySelector('[name="total_quantity"]').value) || 0;
    const damagePercent = parseFloat(document.querySelector('[name="damage_percentage"]').value) || 0;
    const costPerUnit = parseFloat(document.querySelector('[name="cost_per_unit"]').value) || 0;
    const transportPercent = parseFloat(document.querySelector('[name="transport_percentage"]').value) || 0;
    const transportCost = parseFloat(document.querySelector('[name="transport_cost"]').value) || 0;
    
    const usableQuantity = totalQuantity * (1 - damagePercent/100);
    
    // Calculate cost per unit with transport
    let costPerUnitWithTransport = costPerUnit;
    if (transportPercent > 0) {
        costPerUnitWithTransport = costPerUnit * (1 + transportPercent/100);
    }
    
    const totalMaterialCost = totalQuantity * costPerUnit;
    const totalCostWithTransport = totalQuantity * costPerUnitWithTransport + transportCost;
    
    let html = '';
    
    if (totalQuantity > 0) {
        html += `<div class="calculation-result result-usable">
            <i class="bi bi-check-circle"></i> Usable Quantity: ${usableQuantity.toFixed(1)}
        </div>`;
        
        if (damagePercent > 0) {
            html += `<div class="text-danger small">
                <i class="bi bi-exclamation-triangle"></i> Damage: ${(totalQuantity - usableQuantity).toFixed(1)} units (${damagePercent}%)
            </div>`;
        }
    }
    
    if (costPerUnit > 0) {
        html += `<div class="calculation-result result-cost">
            <i class="bi bi-currency-dollar"></i> Base Cost: ₹${totalMaterialCost.toFixed(2)}
        </div>`;
        
        if (transportPercent > 0) {
            html += `<div class="calculation-result result-cost">
                <i class="bi bi-truck"></i> Cost + Transport (${transportPercent}%): ₹${costPerUnitWithTransport.toFixed(2)}/unit
            </div>`;
        }
        
        if (transportCost > 0) {
            html += `<div class="calculation-result result-cost">
                <i class="bi bi-plus-circle"></i> + Fixed Transport: ₹${transportCost.toFixed(2)}
            </div>`;
        }
        
        html += `<div class="calculation-result result-final">
            <i class="bi bi-calculator"></i> Total Cost: ₹${totalCostWithTransport.toFixed(2)}
        </div>`;
        
        if (usableQuantity > 0) {
            const effectiveCostPerUsableUnit = totalCostWithTransport / usableQuantity;
            html += `<div class="text-info small mt-2">
                <i class="bi bi-info-circle"></i> Effective cost per usable unit: ₹${effectiveCostPerUsableUnit.toFixed(2)}
            </div>`;
        }
    }
    
    if (!html) {
        html = '<p class="text-muted"><i class="bi bi-info-circle"></i> Enter values to see calculations</p>';
    }
    
    document.getElementById('calculationResults').innerHTML = html;
}

function updateItemInfo() {
    const itemId = document.getElementById('itemSelect').value;
    if (itemId) {
        window.location.href = `other_purchase.php?item_id=${itemId}`;
    }
}

function filterByItem(itemId) {
    if (itemId) {
        window.location.href = `other_purchase.php?view=history&item_id=${itemId}`;
    } else {
        window.location.href = `other_purchase.php?view=history`;
    }
}

// Initialize calculations on page load
document.addEventListener('DOMContentLoaded', function() {
    updateCalculationDisplay();
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>