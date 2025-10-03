<?php
// public/inventory_advanced.php — Enhanced Inventory Management with improved UI and calculations
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';
require_login();

$pdo = Database::pdo();

/* ---------- Calculation Helpers ---------- */
function calculate_transport_cost(array $item, float $spb = 1.0): array {
    $per_box_value = (float)($item['per_box_value'] ?? 0);
    $per_sqft_value = (float)($item['per_sqft_value'] ?? 0);
    $transport_pct = (float)($item['transport_pct'] ?? 0);
    $transport_per_box = (float)($item['transport_per_box'] ?? 0);
    $transport_total = (float)($item['transport_total'] ?? 0);
    $boxes_in = (float)($item['boxes_in'] ?? 0);
    $damage_boxes = (float)($item['damage_boxes'] ?? 0);
    
    // Calculate base cost per box
    $base_cost_per_box = $per_box_value > 0 ? $per_box_value : ($per_sqft_value * $spb);
    
    // Calculate net boxes (after damage)
    $net_boxes = max(0, $boxes_in - $damage_boxes);
    
    // Calculate transport costs
    $transport_from_percent = $base_cost_per_box * ($transport_pct / 100.0);
    $transport_allocated = ($transport_total > 0 && $net_boxes > 0) ? ($transport_total / $net_boxes) : 0;
    
    $total_transport_per_box = $transport_from_percent + $transport_per_box + $transport_allocated;
    $final_cost_per_box = $base_cost_per_box + $total_transport_per_box;
    $final_cost_per_sqft = $spb > 0 ? ($final_cost_per_box / $spb) : 0;
    
    return [
        'base_cost_per_box' => round($base_cost_per_box, 2),
        'transport_from_percent' => round($transport_from_percent, 2),
        'transport_per_box' => round($transport_per_box, 2),
        'transport_allocated' => round($transport_allocated, 2),
        'total_transport_per_box' => round($total_transport_per_box, 2),
        'final_cost_per_box' => round($final_cost_per_box, 2),
        'final_cost_per_sqft' => round($final_cost_per_sqft, 2),
        'net_boxes' => round($net_boxes, 3),
        'total_value' => round($net_boxes * $final_cost_per_box, 2)
    ];
}

function get_tile_availability(PDO $pdo, int $tile_id): float {
    // Good boxes received
    $st = $pdo->prepare("SELECT COALESCE(SUM(boxes_in - COALESCE(damage_boxes, 0)), 0) FROM inventory_items WHERE tile_id = ?");
    $st->execute([$tile_id]);
    $received = (float)$st->fetchColumn();
    
    // Sold boxes
    $st = $pdo->prepare("SELECT COALESCE(SUM(boxes_decimal), 0) FROM invoice_items WHERE tile_id = ?");
    $st->execute([$tile_id]);
    $sold = (float)$st->fetchColumn();
    
    // Returned boxes
    $st = $pdo->prepare("SELECT COALESCE(SUM(boxes_decimal), 0) FROM invoice_return_items WHERE tile_id = ?");
    $st->execute([$tile_id]);
    $returned = (float)$st->fetchColumn();
    
    return max(0, $received - $sold + $returned);
}

/* ---------- Helper Functions ---------- */
function P($k, $d = null) { return $_POST[$k] ?? $d; }
function Pn($k) { $v = P($k, 0); return is_numeric($v) ? (float)$v : 0.0; }
function Pid($k) { $v = P($k, 0); return is_numeric($v) ? (int)$v : 0; }

/* ===========================================================
   HANDLE POST ACTIONS
   =========================================================== */

// Update inventory item
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_inventory'])) {
    $id = Pid('item_id');
    if ($id > 0) {
        // Validate inputs
        $boxes_in = Pn('boxes_in');
        $damage_boxes = Pn('damage_boxes');
        $per_box_value = Pn('per_box_value');
        $per_sqft_value = Pn('per_sqft_value');
        $transport_pct = Pn('transport_pct');
        $transport_per_box = Pn('transport_per_box');
        $transport_total = Pn('transport_total');
        $vendor = trim(P('vendor', ''));
        $purchase_dt = P('purchase_dt', date('Y-m-d'));
        $notes = trim(P('notes', ''));
        
        // Validation
        if ($boxes_in < 0) {
            $error = "Boxes in cannot be negative";
        } elseif ($damage_boxes < 0 || $damage_boxes > $boxes_in) {
            $error = "Damage boxes must be between 0 and total boxes";
        } elseif ($per_box_value < 0 || $per_sqft_value < 0) {
            $error = "Values cannot be negative";
        } elseif ($per_box_value == 0 && $per_sqft_value == 0) {
            $error = "Either per box value or per sqft value must be provided";
        } else {
            // Update the record
            $stmt = $pdo->prepare("
                UPDATE inventory_items SET
                    boxes_in = ?, damage_boxes = ?, per_box_value = ?, per_sqft_value = ?,
                    transport_pct = ?, transport_per_box = ?, transport_total = ?,
                    vendor = ?, purchase_dt = ?, notes = ?
                WHERE id = ?
            ");
            
            if ($stmt->execute([
                $boxes_in, $damage_boxes, $per_box_value, $per_sqft_value,
                $transport_pct, $transport_per_box, $transport_total,
                $vendor, $purchase_dt, $notes, $id
            ])) {
                $success = "Inventory item updated successfully";
            } else {
                $error = "Failed to update inventory item";
            }
        }
    }
    
    header("Location: inventory_advanced.php" . (isset($error) ? "?error=" . urlencode($error) : (isset($success) ? "?success=" . urlencode($success) : "")));
    exit;
}

// Delete inventory item
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_inventory'])) {
    $id = Pid('item_id');
    if ($id > 0) {
        // Check if this inventory item has been used in any invoices
        $stmt = $pdo->prepare("
            SELECT COUNT(*) FROM invoice_items ii 
            JOIN inventory_items inv ON inv.tile_id = ii.tile_id 
            WHERE inv.id = ?
        ");
        $stmt->execute([$id]);
        $usage_count = (int)$stmt->fetchColumn();
        
        if ($usage_count > 0) {
            $error = "Cannot delete: This inventory item is referenced in invoices";
        } else {
            $stmt = $pdo->prepare("DELETE FROM inventory_items WHERE id = ?");
            if ($stmt->execute([$id])) {
                $success = "Inventory item deleted successfully";
            } else {
                $error = "Failed to delete inventory item";
            }
        }
    }
    
    header("Location: inventory_advanced.php" . (isset($error) ? "?error=" . urlencode($error) : (isset($success) ? "?success=" . urlencode($success) : "")));
    exit;
}

// Bulk operations
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['bulk_action'])) {
    $action = P('bulk_action');
    $selected_ids = $_POST['selected_items'] ?? [];
    
    if (empty($selected_ids)) {
        $error = "Please select items for bulk operation";
    } else {
        $ids_str = implode(',', array_map('intval', $selected_ids));
        
        switch ($action) {
            case 'delete':
                // Check usage first
                $stmt = $pdo->query("
                    SELECT COUNT(*) FROM invoice_items ii 
                    JOIN inventory_items inv ON inv.tile_id = ii.tile_id 
                    WHERE inv.id IN ($ids_str)
                ");
                $usage_count = (int)$stmt->fetchColumn();
                
                if ($usage_count > 0) {
                    $error = "Cannot delete: Some selected items are referenced in invoices";
                } else {
                    $pdo->query("DELETE FROM inventory_items WHERE id IN ($ids_str)");
                    $success = "Selected items deleted successfully";
                }
                break;
                
            case 'update_vendor':
                $new_vendor = trim(P('new_vendor', ''));
                if ($new_vendor) {
                    $stmt = $pdo->prepare("UPDATE inventory_items SET vendor = ? WHERE id IN ($ids_str)");
                    $stmt->execute([$new_vendor]);
                    $success = "Vendor updated for selected items";
                } else {
                    $error = "Please provide a vendor name";
                }
                break;
        }
    }
    
    header("Location: inventory_advanced.php" . (isset($error) ? "?error=" . urlencode($error) : (isset($success) ? "?success=" . urlencode($success) : "")));
    exit;
}

/* ===========================================================
   FETCH DATA FOR DISPLAY
   =========================================================== */

// Get filters
$tile_filter = (int)($_GET['tile_id'] ?? 0);
$vendor_filter = trim($_GET['vendor'] ?? '');
$date_from = $_GET['date_from'] ?? '';
$date_to = $_GET['date_to'] ?? '';

// Build WHERE clause
$where_conditions = [];
$params = [];

if ($tile_filter > 0) {
    $where_conditions[] = "ii.tile_id = ?";
    $params[] = $tile_filter;
}

if ($vendor_filter) {
    $where_conditions[] = "ii.vendor LIKE ?";
    $params[] = "%$vendor_filter%";
}

if ($date_from) {
    $where_conditions[] = "ii.purchase_dt >= ?";
    $params[] = $date_from;
}

if ($date_to) {
    $where_conditions[] = "ii.purchase_dt <= ?";
    $params[] = $date_to;
}

$where_clause = empty($where_conditions) ? '' : 'WHERE ' . implode(' AND ', $where_conditions);

// Fetch inventory items with calculations
$sql = "
    SELECT ii.*, t.name AS tile_name, ts.label AS size_label, ts.sqft_per_box
    FROM inventory_items ii
    JOIN tiles t ON t.id = ii.tile_id
    JOIN tile_sizes ts ON ts.id = t.size_id
    $where_clause
    ORDER BY ii.id DESC
";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$inventory_items = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get tiles for filter dropdown
$tiles = $pdo->query("
    SELECT t.id, t.name, ts.label AS size_label
    FROM tiles t
    JOIN tile_sizes ts ON ts.id = t.size_id
    ORDER BY t.name
")->fetchAll(PDO::FETCH_ASSOC);

// Get vendors for filter
$vendors = $pdo->query("SELECT DISTINCT vendor FROM inventory_items WHERE vendor IS NOT NULL AND vendor != '' ORDER BY vendor")->fetchAll(PDO::FETCH_COLUMN);

$page_title = "Advanced Inventory Management";
require_once __DIR__ . '/../includes/header.php';
?>

<style>
.inventory-table th, .inventory-table td {
    padding: 8px 12px;
    vertical-align: middle;
}
.form-control-sm {
    padding: 0.25rem 0.5rem;
    font-size: 0.875rem;
}
.calculated-field {
    background-color: #f8f9fa;
    font-weight: 600;
}
.error-row {
    background-color: #f8d7da;
}
.success-row {
    background-color: #d1e7dd;
}
.sticky-header {
    position: sticky;
    top: 74px;
    z-index: 10;
    background: white;
    box-shadow: 0 2px 4px rgba(0,0,0,0.1);
}
</style>

<?php if (isset($_GET['error'])): ?>
    <div class="alert alert-danger alert-dismissible fade show">
        <?= h($_GET['error']) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<?php if (isset($_GET['success'])): ?>
    <div class="alert alert-success alert-dismissible fade show">
        <?= h($_GET['success']) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<!-- Filters -->
<div class="card p-3 mb-3">
    <h5>Filters & Search</h5>
    <form method="get" class="row g-3">
        <div class="col-md-3">
            <label class="form-label">Tile</label>
            <select class="form-select" name="tile_id">
                <option value="">All Tiles</option>
                <?php foreach ($tiles as $tile): ?>
                    <option value="<?= (int)$tile['id'] ?>" <?= $tile_filter === (int)$tile['id'] ? 'selected' : '' ?>>
                        <?= h($tile['name']) ?> (<?= h($tile['size_label']) ?>)
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-2">
            <label class="form-label">Vendor</label>
            <input type="text" class="form-control" name="vendor" value="<?= h($vendor_filter) ?>" placeholder="Search vendor...">
        </div>
        <div class="col-md-2">
            <label class="form-label">From Date</label>
            <input type="date" class="form-control" name="date_from" value="<?= h($date_from) ?>">
        </div>
        <div class="col-md-2">
            <label class="form-label">To Date</label>
            <input type="date" class="form-control" name="date_to" value="<?= h($date_to) ?>">
        </div>
        <div class="col-md-3 d-flex align-items-end">
            <button type="submit" class="btn btn-primary me-2">Apply Filters</button>
            <a href="inventory_advanced.php" class="btn btn-outline-secondary">Clear</a>
        </div>
    </form>
</div>

<!-- Bulk Actions -->
<div class="card p-3 mb-3">
    <h5>Bulk Actions</h5>
    <form method="post" id="bulkForm" onsubmit="return confirmBulkAction()">
        <div class="row g-3 align-items-end">
            <div class="col-md-3">
                <label class="form-label">Action</label>
                <select class="form-select" name="bulk_action" id="bulkAction">
                    <option value="">Select Action</option>
                    <option value="delete">Delete Selected</option>
                    <option value="update_vendor">Update Vendor</option>
                </select>
            </div>
            <div class="col-md-3" id="vendorInput" style="display: none;">
                <label class="form-label">New Vendor</label>
                <input type="text" class="form-control" name="new_vendor" placeholder="Enter vendor name">
            </div>
            <div class="col-md-3">
                <button type="submit" class="btn btn-warning">Execute Bulk Action</button>
            </div>
        </div>
    </form>
</div>

<!-- Summary Stats -->
<div class="row g-3 mb-3">
    <?php
    $total_items = count($inventory_items);
    $total_value = 0;
    $total_boxes = 0;
    $total_damage = 0;
    
    foreach ($inventory_items as $item) {
        $spb = (float)$item['sqft_per_box'];
        $calc = calculate_transport_cost($item, $spb);
        $total_value += $calc['total_value'];
        $total_boxes += (float)$item['boxes_in'];
        $total_damage += (float)$item['damage_boxes'];
    }
    ?>
    <div class="col-md-3">
        <div class="card text-bg-primary">
            <div class="card-body">
                <h6 class="card-title">Total Items</h6>
                <h4><?= $total_items ?></h4>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card text-bg-success">
            <div class="card-body">
                <h6 class="card-title">Total Value</h6>
                <h4>₹ <?= n2($total_value) ?></h4>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card text-bg-info">
            <div class="card-body">
                <h6 class="card-title">Total Boxes</h6>
                <h4><?= n2($total_boxes) ?></h4>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card text-bg-warning">
            <div class="card-body">
                <h6 class="card-title">Damaged Boxes</h6>
                <h4><?= n2($total_damage) ?></h4>
            </div>
        </div>
    </div>
</div>

<!-- Inventory Table -->
<div class="card p-3">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h5 class="mb-0">Inventory Items (<?= $total_items ?>)</h5>
        <div>
            <button type="button" class="btn btn-sm btn-outline-primary" onclick="selectAllItems()">Select All</button>
            <button type="button" class="btn btn-sm btn-outline-secondary" onclick="clearSelection()">Clear Selection</button>
        </div>
    </div>
    
    <div class="table-responsive">
        <table class="table table-striped table-hover inventory-table">
            <thead class="sticky-header">
                <tr>
                    <th><input type="checkbox" id="selectAll" onchange="toggleSelectAll()"></th>
                    <th>ID</th>
                    <th>Tile</th>
                    <th>Size</th>
                    <th>Vendor</th>
                    <th>Purchase Date</th>
                    <th class="text-end">Boxes In</th>
                    <th class="text-end">Damage</th>
                    <th class="text-end">Net Boxes</th>
                    <th class="text-end">Per Box Value</th>
                    <th class="text-end">Transport %</th>
                    <th class="text-end">Transport Total</th>
                    <th class="text-end">Final Cost/Box</th>
                    <th class="text-end">Total Value</th>
                    <th class="text-end">Available</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($inventory_items as $item): 
                    $spb = (float)$item['sqft_per_box'];
                    $calc = calculate_transport_cost($item, $spb);
                    $available = get_tile_availability($pdo, (int)$item['tile_id']);
                ?>
                <tr data-item-id="<?= (int)$item['id'] ?>">
                    <td>
                        <input type="checkbox" class="item-checkbox" value="<?= (int)$item['id'] ?>" name="selected_items[]" form="bulkForm">
                    </td>
                    <td><?= (int)$item['id'] ?></td>
                    <td><?= h($item['tile_name']) ?></td>
                    <td><?= h($item['size_label']) ?></td>
                    <td><?= h($item['vendor'] ?? '') ?></td>
                    <td><?= h($item['purchase_dt'] ?? '') ?></td>
                    <td class="text-end"><?= n2($item['boxes_in']) ?></td>
                    <td class="text-end <?= (float)$item['damage_boxes'] > 0 ? 'text-danger' : '' ?>">
                        <?= n2($item['damage_boxes']) ?>
                    </td>
                    <td class="text-end calculated-field"><?= n2($calc['net_boxes']) ?></td>
                    <td class="text-end">₹ <?= n2($item['per_box_value']) ?></td>
                    <td class="text-end"><?= n2($item['transport_pct']) ?>%</td>
                    <td class="text-end">₹ <?= n2($item['transport_total']) ?></td>
                    <td class="text-end calculated-field">₹ <?= n2($calc['final_cost_per_box']) ?></td>
                    <td class="text-end calculated-field">₹ <?= n2($calc['total_value']) ?></td>
                    <td class="text-end">
                        <span class="badge <?= $available > 0 ? 'text-bg-success' : 'text-bg-danger' ?>">
                            <?= n2($available) ?>
                        </span>
                    </td>
                    <td>
                        <button type="button" class="btn btn-sm btn-primary" onclick="editItem(<?= (int)$item['id'] ?>)">
                            Edit
                        </button>
                        <button type="button" class="btn btn-sm btn-outline-danger" onclick="deleteItem(<?= (int)$item['id'] ?>)">
                            Delete
                        </button>
                    </td>
                </tr>
                <?php endforeach; ?>
                
                <?php if (empty($inventory_items)): ?>
                <tr>
                    <td colspan="16" class="text-center text-muted py-4">
                        No inventory items found matching your criteria.
                    </td>
                </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Edit Modal -->
<div class="modal fade" id="editModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="post" id="editForm">
                <div class="modal-header">
                    <h5 class="modal-title">Edit Inventory Item</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="item_id" id="editItemId">
                    
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">Vendor</label>
                            <input type="text" class="form-control" name="vendor" id="editVendor">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Purchase Date</label>
                            <input type="date" class="form-control" name="purchase_dt" id="editPurchaseDate">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Boxes In</label>
                            <input type="number" step="0.001" class="form-control" name="boxes_in" id="editBoxesIn" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Damage Boxes</label>
                            <input type="number" step="0.001" class="form-control" name="damage_boxes" id="editDamageBoxes">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Per Box Value (₹)</label>
                            <input type="number" step="0.01" class="form-control" name="per_box_value" id="editPerBoxValue">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Per Sqft Value (₹)</label>
                            <input type="number" step="0.01" class="form-control" name="per_sqft_value" id="editPerSqftValue">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Transport % of Base Cost</label>
                            <input type="number" step="0.01" class="form-control" name="transport_pct" id="editTransportPct">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Transport Per Box (₹)</label>
                            <input type="number" step="0.01" class="form-control" name="transport_per_box" id="editTransportPerBox">
                        </div>
                        <div class="col-md-12">
                            <label class="form-label">Transport Total (₹)</label>
                            <input type="number" step="0.01" class="form-control" name="transport_total" id="editTransportTotal">
                        </div>
                        <div class="col-md-12">
                            <label class="form-label">Notes</label>
                            <textarea class="form-control" name="notes" id="editNotes" rows="3"></textarea>
                        </div>
                    </div>
                    
                    <!-- Live calculation preview -->
                    <div class="mt-3 p-3 bg-light rounded">
                        <h6>Calculation Preview:</h6>
                        <div class="row">
                            <div class="col-md-6">
                                <small><strong>Net Boxes:</strong> <span id="previewNetBoxes">0</span></small><br>
                                <small><strong>Base Cost/Box:</strong> ₹<span id="previewBaseCost">0</span></small><br>
                                <small><strong>Transport Cost/Box:</strong> ₹<span id="previewTransportCost">0</span></small>
                            </div>
                            <div class="col-md-6">
                                <small><strong>Final Cost/Box:</strong> ₹<span id="previewFinalCost">0</span></small><br>
                                <small><strong>Total Value:</strong> ₹<span id="previewTotalValue">0</span></small>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="update_inventory" class="btn btn-primary">Update Item</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
// Global data for calculations
const inventoryData = <?= json_encode($inventory_items) ?>;
let currentEditingItem = null;

// Bulk action handling
document.getElementById('bulkAction').addEventListener('change', function() {
    const vendorInput = document.getElementById('vendorInput');
    if (this.value === 'update_vendor') {
        vendorInput.style.display = 'block';
    } else {
        vendorInput.style.display = 'none';
    }
});

// Selection functions
function toggleSelectAll() {
    const selectAll = document.getElementById('selectAll');
    const checkboxes = document.querySelectorAll('.item-checkbox');
    checkboxes.forEach(cb => cb.checked = selectAll.checked);
}

function selectAllItems() {
    document.getElementById('selectAll').checked = true;
    toggleSelectAll();
}

function clearSelection() {
    document.getElementById('selectAll').checked = false;
    toggleSelectAll();
}

function confirmBulkAction() {
    const action = document.getElementById('bulkAction').value;
    const selected = document.querySelectorAll('.item-checkbox:checked').length;
    
    if (!action) {
        alert('Please select an action');
        return false;
    }
    
    if (selected === 0) {
        alert('Please select items to perform the action on');
        return false;
    }
    
    let message = '';
    switch (action) {
        case 'delete':
            message = `Are you sure you want to delete ${selected} selected items?`;
            break;
        case 'update_vendor':
            const vendor = document.querySelector('[name="new_vendor"]').value;
            if (!vendor) {
                alert('Please enter a vendor name');
                return false;
            }
            message = `Update vendor to "${vendor}" for ${selected} selected items?`;
            break;
    }
    
    return confirm(message);
}

// Edit functions
function editItem(itemId) {
    const item = inventoryData.find(i => parseInt(i.id) === itemId);
    if (!item) return;
    
    currentEditingItem = item;
    
    // Populate form
    document.getElementById('editItemId').value = item.id;
    document.getElementById('editVendor').value = item.vendor || '';
    document.getElementById('editPurchaseDate').value = item.purchase_dt || '';
    document.getElementById('editBoxesIn').value = item.boxes_in || 0;
    document.getElementById('editDamageBoxes').value = item.damage_boxes || 0;
    document.getElementById('editPerBoxValue').value = item.per_box_value || 0;
    document.getElementById('editPerSqftValue').value = item.per_sqft_value || 0;
    document.getElementById('editTransportPct').value = item.transport_pct || 0;
    document.getElementById('editTransportPerBox').value = item.transport_per_box || 0;
    document.getElementById('editTransportTotal').value = item.transport_total || 0;
    document.getElementById('editNotes').value = item.notes || '';
    
    // Setup live calculation
    setupLiveCalculation();
    updateCalculationPreview();
    
    // Show modal
    new bootstrap.Modal(document.getElementById('editModal')).show();
}

function deleteItem(itemId) {
    if (confirm('Are you sure you want to delete this inventory item?')) {
        const form = document.createElement('form');
        form.method = 'POST';
        form.innerHTML = `
            <input type="hidden" name="item_id" value="${itemId}">
            <input type="hidden" name="delete_inventory" value="1">
        `;
        document.body.appendChild(form);
        form.submit();
    }
}

// Live calculation for edit modal
function setupLiveCalculation() {
    const inputs = ['editBoxesIn', 'editDamageBoxes', 'editPerBoxValue', 'editPerSqftValue', 
                   'editTransportPct', 'editTransportPerBox', 'editTransportTotal'];
    
    inputs.forEach(inputId => {
        const input = document.getElementById(inputId);
        if (input) {
            input.addEventListener('input', updateCalculationPreview);
        }
    });
}

function updateCalculationPreview() {
    if (!currentEditingItem) return;
    
    const spb = parseFloat(currentEditingItem.sqft_per_box) || 1;
    const boxesIn = parseFloat(document.getElementById('editBoxesIn').value) || 0;
    const damageBoxes = parseFloat(document.getElementById('editDamageBoxes').value) || 0;
    const perBoxValue = parseFloat(document.getElementById('editPerBoxValue').value) || 0;
    const perSqftValue = parseFloat(document.getElementById('editPerSqftValue').value) || 0;
    const transportPct = parseFloat(document.getElementById('editTransportPct').value) || 0;
    const transportPerBox = parseFloat(document.getElementById('editTransportPerBox').value) || 0;
    const transportTotal = parseFloat(document.getElementById('editTransportTotal').value) || 0;
    
    // Calculate values
    const netBoxes = Math.max(0, boxesIn - damageBoxes);
    const baseCostPerBox = perBoxValue > 0 ? perBoxValue : (perSqftValue * spb);
    const transportFromPct = baseCostPerBox * (transportPct / 100);
    const transportAllocated = (transportTotal > 0 && netBoxes > 0) ? (transportTotal / netBoxes) : 0;
    const totalTransportPerBox = transportFromPct + transportPerBox + transportAllocated;
    const finalCostPerBox = baseCostPerBox + totalTransportPerBox;
    const totalValue = netBoxes * finalCostPerBox;
    
    // Update preview
    document.getElementById('previewNetBoxes').textContent = netBoxes.toFixed(3);
    document.getElementById('previewBaseCost').textContent = baseCostPerBox.toFixed(2);
    document.getElementById('previewTransportCost').textContent = totalTransportPerBox.toFixed(2);
    document.getElementById('previewFinalCost').textContent = finalCostPerBox.toFixed(2);
    document.getElementById('previewTotalValue').textContent = totalValue.toFixed(2);
}
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>