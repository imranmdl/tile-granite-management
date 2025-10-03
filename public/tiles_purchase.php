<?php
// public/tiles_purchase.php - Purchase Entry for Tiles with Damage Calculations
require_once __DIR__ . '/../includes/simple_auth.php';
require_once __DIR__ . '/../includes/helpers.php';

auth_require_login();

$pdo = Database::pdo();
$message = '';
$error = '';

// Get tile_id if provided
$tile_id = (int)($_GET['tile_id'] ?? 0);
$view_mode = $_GET['view'] ?? 'entry';

// Handle purchase entry submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_purchase'])) {
    $tile_id = (int)$_POST['tile_id'];
    $purchase_date = $_POST['purchase_date'];
    $supplier_name = trim($_POST['supplier_name'] ?? '');
    $invoice_number = trim($_POST['invoice_number'] ?? '');
    $total_boxes = (float)$_POST['total_boxes'];
    $damage_percentage = (float)$_POST['damage_percentage'];
    $cost_per_box = (float)$_POST['cost_per_box'];
    $transport_cost = (float)($_POST['transport_cost'] ?? 0);
    $transport_percentage = (float)($_POST['transport_percentage'] ?? 0);
    $notes = trim($_POST['notes'] ?? '');
    
    // Validation
    if (!$tile_id || !$purchase_date || $total_boxes <= 0 || $cost_per_box <= 0) {
        $error = 'Please fill in all required fields with valid values';
    } elseif ($damage_percentage < 0 || $damage_percentage > 100) {
        $error = 'Damage percentage must be between 0 and 100';
    } elseif ($transport_percentage < 0 || $transport_percentage > 200) {
        $error = 'Transport percentage must be between 0 and 200';
    } else {
        try {
            $stmt = $pdo->prepare("
                INSERT INTO purchase_entries_tiles 
                (tile_id, purchase_date, supplier_name, invoice_number, total_boxes, 
                 damage_percentage, cost_per_box, transport_cost, transport_percentage, notes)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            
            if ($stmt->execute([$tile_id, $purchase_date, $supplier_name, $invoice_number, 
                               $total_boxes, $damage_percentage, $cost_per_box, $transport_cost, $transport_percentage, $notes])) {
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

// Get tiles for selection
$tiles_stmt = $pdo->query("
    SELECT t.id, t.name, ts.label as size_label, v.name as vendor_name
    FROM tiles t
    JOIN tile_sizes ts ON t.size_id = ts.id
    LEFT JOIN vendors v ON t.vendor_id = v.id
    ORDER BY t.name, ts.label
");
$tiles = $tiles_stmt->fetchAll(PDO::FETCH_ASSOC);

// Get selected tile info if tile_id provided
$selected_tile = null;
if ($tile_id) {
    $stmt = $pdo->prepare("
        SELECT t.id, t.name, ts.label as size_label, ts.sqft_per_box, v.name as vendor_name,
               cts.total_stock_boxes, cts.total_stock_sqft
        FROM tiles t
        JOIN tile_sizes ts ON t.size_id = ts.id
        LEFT JOIN vendors v ON t.vendor_id = v.id
        LEFT JOIN current_tiles_stock cts ON t.id = cts.id
        WHERE t.id = ?
    ");
    $stmt->execute([$tile_id]);
    $selected_tile = $stmt->fetch(PDO::FETCH_ASSOC);
}

// Get purchase history for selected tile
$purchase_history = [];
if ($tile_id) {
    $stmt = $pdo->prepare("
        SELECT pe.*, (pe.total_boxes * (1 - pe.damage_percentage/100)) as calculated_usable_boxes,
               (pe.total_boxes * pe.cost_per_box + pe.transport_cost) as calculated_total_cost
        FROM purchase_entries_tiles pe
        WHERE pe.tile_id = ?
        ORDER BY pe.purchase_date DESC, pe.created_at DESC
    ");
    $stmt->execute([$tile_id]);
    $purchase_history = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

$page_title = $view_mode === 'history' ? "Purchase History" : "Tile Purchase Entry";
require_once __DIR__ . '/../includes/header.php';
?>

<style>
.purchase-card {
    background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
    border-radius: 12px;
    border: 1px solid #dee2e6;
}

.calculation-card {
    background: linear-gradient(135deg, #e3f2fd 0%, #f3e5f5 100%);
    border-radius: 8px;
    border: 1px solid #90caf9;
}

.tile-info-card {
    background: linear-gradient(135deg, #f1f8e9 0%, #f9fbe7 100%);
    border-radius: 8px;
    border: 1px solid #c8e6c9;
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
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
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
           href="tiles_purchase.php<?= $tile_id ? '?tile_id=' . $tile_id : '' ?>">
            <i class="bi bi-plus-circle"></i> New Purchase Entry
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link <?= $view_mode === 'history' ? 'active' : '' ?>" 
           href="tiles_purchase.php?view=history<?= $tile_id ? '&tile_id=' . $tile_id : '' ?>">
            <i class="bi bi-clock-history"></i> Purchase History
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link" href="tiles_inventory.php">
            <i class="bi bi-arrow-left"></i> Back to Inventory
        </a>
    </li>
</ul>

<?php if ($view_mode === 'entry'): ?>
    <!-- Purchase Entry Form -->
    <div class="row">
        <div class="col-lg-8">
            <div class="card purchase-card">
                <div class="card-header">
                    <h5 class="mb-0"><i class="bi bi-cart-plus"></i> Add Purchase Entry</h5>
                </div>
                <div class="card-body">
                    <form method="post" id="purchaseForm">
                        <div class="row g-3">
                            <!-- Tile Selection -->
                            <div class="col-12">
                                <label class="form-label">Select Tile *</label>
                                <select class="form-select" name="tile_id" id="tileSelect" required onchange="updateTileInfo()">
                                    <option value="">Choose a tile...</option>
                                    <?php foreach ($tiles as $tile): ?>
                                        <option value="<?= $tile['id'] ?>" <?= $tile['id'] == $tile_id ? 'selected' : '' ?>>
                                            <?= h($tile['name']) ?> (<?= h($tile['size_label']) ?>)
                                            <?= $tile['vendor_name'] ? ' - ' . h($tile['vendor_name']) : '' ?>
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
                                <label class="form-label">Total Boxes *</label>
                                <input type="number" class="form-control" name="total_boxes" 
                                       value="<?= $_POST['total_boxes'] ?? '' ?>" 
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
                                <label class="form-label">Cost per Box *</label>
                                <div class="input-group">
                                    <span class="input-group-text">₹</span>
                                    <input type="number" class="form-control" name="cost_per_box" 
                                           value="<?= $_POST['cost_per_box'] ?? '' ?>" 
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
                                <div class="form-text">Cost/Box × (1 + Transport%)</div>
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
                            <a href="tiles_inventory.php" class="btn btn-outline-secondary btn-lg ms-2">
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

            <!-- Selected Tile Info -->
            <?php if ($selected_tile): ?>
            <div class="card tile-info-card">
                <div class="card-header">
                    <h6 class="mb-0"><i class="bi bi-info-circle"></i> Tile Information</h6>
                </div>
                <div class="card-body">
                    <h6><?= h($selected_tile['name']) ?></h6>
                    <p class="mb-2">
                        <strong>Size:</strong> <?= h($selected_tile['size_label']) ?><br>
                        <strong>Sq.Ft/Box:</strong> <?= $selected_tile['sqft_per_box'] ?><br>
                        <?php if ($selected_tile['vendor_name']): ?>
                            <strong>Vendor:</strong> <?= h($selected_tile['vendor_name']) ?><br>
                        <?php endif; ?>
                    </p>
                    <hr>
                    <h6>Current Stock</h6>
                    <p class="mb-0">
                        <strong>Boxes:</strong> <?= number_format($selected_tile['total_stock_boxes'] ?? 0, 1) ?><br>
                        <strong>Sq.Ft:</strong> <?= number_format($selected_tile['total_stock_sqft'] ?? 0, 1) ?>
                    </p>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>

<?php else: ?>
    <!-- Purchase History View -->
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h4>Purchase History</h4>
        <?php if ($tile_id): ?>
            <div>
                <span class="badge bg-info">Tile ID: <?= $tile_id ?></span>
                <?php if ($selected_tile): ?>
                    <span class="badge bg-success"><?= h($selected_tile['name']) ?></span>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>

    <!-- Tile Filter (if no specific tile selected) -->
    <?php if (!$tile_id): ?>
        <div class="mb-4">
            <label class="form-label">Filter by Tile:</label>
            <select class="form-select" onchange="filterByTile(this.value)" style="max-width: 400px;">
                <option value="">All Tiles</option>
                <?php foreach ($tiles as $tile): ?>
                    <option value="<?= $tile['id'] ?>">
                        <?= h($tile['name']) ?> (<?= h($tile['size_label']) ?>)
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
    <?php endif; ?>

    <!-- Purchase History Table -->
    <?php if (empty($purchase_history) && $tile_id): ?>
        <div class="alert alert-info">
            <i class="bi bi-info-circle"></i> No purchase entries found for this tile.
            <a href="tiles_purchase.php?tile_id=<?= $tile_id ?>" class="btn btn-sm btn-primary ms-2">
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
                        <th>Total Boxes</th>
                        <th>Damage %</th>
                        <th>Usable Boxes</th>
                        <th>Cost/Box</th>
                        <th>Transport</th>
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
                            <td><?= number_format($entry['total_boxes'], 1) ?></td>
                            <td><?= number_format($entry['damage_percentage'], 1) ?>%</td>
                            <td class="fw-bold text-success">
                                <?= number_format($entry['calculated_usable_boxes'], 1) ?>
                            </td>
                            <td>$<?= number_format($entry['cost_per_box'], 2) ?></td>
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
                        <h6 class="card-title">Total Boxes</h6>
                        <h4 class="text-info"><?= number_format(array_sum(array_column($purchase_history, 'total_boxes')), 1) ?></h4>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card text-center">
                    <div class="card-body">
                        <h6 class="card-title">Usable Boxes</h6>
                        <h4 class="text-success"><?= number_format(array_sum(array_column($purchase_history, 'calculated_usable_boxes')), 1) ?></h4>
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
    const totalBoxes = parseFloat(document.querySelector('[name="total_boxes"]').value) || 0;
    const damagePercent = parseFloat(document.querySelector('[name="damage_percentage"]').value) || 0;
    
    const usableBoxes = totalBoxes * (1 - damagePercent/100);
    
    updateCalculationDisplay();
}

function calculateCosts() {
    updateCalculationDisplay();
}

function updateCalculationDisplay() {
    const totalBoxes = parseFloat(document.querySelector('[name="total_boxes"]').value) || 0;
    const damagePercent = parseFloat(document.querySelector('[name="damage_percentage"]').value) || 0;
    const costPerBox = parseFloat(document.querySelector('[name="cost_per_box"]').value) || 0;
    const transportCost = parseFloat(document.querySelector('[name="transport_cost"]').value) || 0;
    
    const usableBoxes = totalBoxes * (1 - damagePercent/100);
    const totalMaterialCost = totalBoxes * costPerBox;
    const grandTotal = totalMaterialCost + transportCost;
    
    let html = '';
    
    if (totalBoxes > 0) {
        html += `<div class="calculation-result result-usable">
            <i class="bi bi-check-circle"></i> Usable Boxes: ${usableBoxes.toFixed(1)}
        </div>`;
        
        if (damagePercent > 0) {
            html += `<div class="text-danger small">
                <i class="bi bi-exclamation-triangle"></i> Damage: ${(totalBoxes - usableBoxes).toFixed(1)} boxes (${damagePercent}%)
            </div>`;
        }
    }
    
    if (costPerBox > 0) {
        html += `<div class="calculation-result result-cost">
            <i class="bi bi-currency-dollar"></i> Material Cost: $${totalMaterialCost.toFixed(2)}
        </div>`;
        
        if (transportCost > 0) {
            html += `<div class="calculation-result result-cost">
                <i class="bi bi-truck"></i> + Transport: $${transportCost.toFixed(2)}
            </div>`;
        }
        
        html += `<div class="calculation-result result-final">
            <i class="bi bi-calculator"></i> Grand Total: $${grandTotal.toFixed(2)}
        </div>`;
        
        if (usableBoxes > 0) {
            const effectiveCostPerUsableBox = grandTotal / usableBoxes;
            html += `<div class="text-info small mt-2">
                <i class="bi bi-info-circle"></i> Effective cost per usable box: $${effectiveCostPerUsableBox.toFixed(2)}
            </div>`;
        }
    }
    
    if (!html) {
        html = '<p class="text-muted"><i class="bi bi-info-circle"></i> Enter values to see calculations</p>';
    }
    
    document.getElementById('calculationResults').innerHTML = html;
}

function updateTileInfo() {
    const tileId = document.getElementById('tileSelect').value;
    if (tileId) {
        window.location.href = `tiles_purchase.php?tile_id=${tileId}`;
    }
}

function filterByTile(tileId) {
    if (tileId) {
        window.location.href = `tiles_purchase.php?view=history&tile_id=${tileId}`;
    } else {
        window.location.href = `tiles_purchase.php?view=history`;
    }
}

// Initialize calculations on page load
document.addEventListener('DOMContentLoaded', function() {
    updateCalculationDisplay();
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>