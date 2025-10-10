<?php
// public/other_purchase.php - Enhanced Miscellaneous Items Purchase Entry with Active/Hide functionality
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
        $total_quantity = (float)($_POST['total_quantity'] ?? 0);
        $damage_percentage = (float)($_POST['damage_percentage'] ?? 0);
        $cost_per_unit = (float)($_POST['cost_per_unit'] ?? 0);
        $transport_cost = (float)($_POST['transport_cost'] ?? 0);
        $supplier_name = trim($_POST['supplier_name'] ?? '');
        $invoice_number = trim($_POST['invoice_number'] ?? '');
        $notes = trim($_POST['notes'] ?? '');
        
        if ($misc_item_id && $total_quantity > 0 && $cost_per_unit > 0) {
            if ($damage_percentage > 100) {
                $error = "Damage percentage cannot exceed 100%";
            } else {
                try {
                    // Use the enhanced purchase_entries_misc table
                    $stmt = $pdo->prepare("
                        INSERT INTO purchase_entries_misc 
                        (misc_item_id, purchase_date, total_quantity, damage_percentage, 
                         cost_per_unit, transport_cost, supplier_name, invoice_number, notes)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ");
                    
                    if ($stmt->execute([
                        $misc_item_id, $purchase_date, $total_quantity, $damage_percentage, 
                        $cost_per_unit, $transport_cost, $supplier_name, $invoice_number, $notes
                    ])) {
                        $usable_qty = $total_quantity * (1 - $damage_percentage/100);
                        $total_cost = $total_quantity * $cost_per_unit + $transport_cost;
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
    // Show all items (both active and inactive) for management
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

// Get recent purchases for display - using enhanced table
$recent_purchases = [];
try {
    $recent_sql = "
        SELECT 
            pem.purchase_date,
            m.name as item_name,
            m.unit_label,
            m.active,
            pem.total_quantity,
            pem.damage_percentage,
            pem.usable_quantity,
            pem.cost_per_unit,
            pem.transport_cost,
            pem.final_cost,
            pem.supplier_name,
            pem.invoice_number
        FROM purchase_entries_misc pem
        JOIN misc_items m ON pem.misc_item_id = m.id
        ORDER BY pem.purchase_date DESC, pem.id DESC
        LIMIT 10
    ";
    
    $recent_purchases = $pdo->query($recent_sql)->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    // Ignore if tables don't exist yet
}

$page_title = "Enhanced Other Items Purchase Entry";
require_once __DIR__ . '/../includes/header.php';
?>\n\n<style>\n.purchase-header {\n    background: linear-gradient(135deg, #28a745 0%, #20c997 100%);\n    color: white;\n    border-radius: 15px;\n    padding: 2rem;\n    margin-bottom: 2rem;\n}\n.form-section {\n    background: white;\n    border-radius: 15px;\n    padding: 2rem;\n    margin-bottom: 2rem;\n    box-shadow: 0 10px 30px rgba(0,0,0,0.1);\n}\n.calculation-box {\n    background: #f8f9fa;\n    border: 2px solid #e9ecef;\n    border-radius: 10px;\n    padding: 1rem;\n    margin-top: 1rem;\n}\n.total-display {\n    font-size: 1.25rem;\n    font-weight: 700;\n    color: #28a745;\n}\n.recent-purchases {\n    max-height: 400px;\n    overflow-y: auto;\n}\n.item-inactive {\n    background-color: #f8f9fa;\n    opacity: 0.7;\n}\n.status-toggle {\n    border: none;\n    background: none;\n    padding: 2px 8px;\n    border-radius: 4px;\n    font-size: 0.8em;\n}\n</style>\n\n<?php if ($message): ?>\n    <div class="alert alert-success alert-dismissible fade show">\n        <i class="bi bi-check-circle me-2"></i><?= h($message) ?>\n        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>\n    </div>\n<?php endif; ?>\n\n<?php if ($error): ?>\n    <div class="alert alert-danger alert-dismissible fade show">\n        <i class="bi bi-exclamation-triangle me-2"></i><?= h($error) ?>\n        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>\n    </div>\n<?php endif; ?>\n\n<!-- Header -->\n<div class="purchase-header">\n    <div class="row align-items-center">\n        <div class="col-md-8">\n            <h2><i class="bi bi-plus-circle me-3"></i>Enhanced Other Items Purchase Entry</h2>\n            <p class="mb-0 opacity-75">Add purchase entries for miscellaneous inventory items with active/inactive management</p>\n        </div>\n        <div class="col-md-4 text-end">\n            <div class="bg-white bg-opacity-20 rounded p-3">\n                <div class="h6 mb-1">Available Items</div>\n                <div class="h4 mb-0"><?= count(array_filter($misc_items, fn($item) => $item['active'] == 1)) ?></div>\n                <small class="opacity-75">Active items (<?= count($misc_items) ?> total)</small>\n            </div>\n        </div>\n    </div>\n</div>\n\n<!-- Action Bar -->\n<div class="d-flex justify-content-between align-items-center mb-4">\n    <div>\n        <h5 class="mb-0">Purchase Entry Form</h5>\n        <small class="text-muted">Add new stock for miscellaneous items</small>\n    </div>\n    <div class="btn-group">\n        <a href="inventory_enhanced.php" class="btn btn-outline-primary">\n            <i class="bi bi-arrow-left"></i> Back to Inventory\n        </a>\n        <a href="inventory_summary_unified.php" class="btn btn-info">\n            <i class="bi bi-speedometer"></i> View Summary\n        </a>\n        <?php if (empty(array_filter($misc_items, fn($item) => $item['active'] == 1))): ?>\n            <a href="misc_items.php" class="btn btn-warning">\n                <i class="bi bi-plus-circle"></i> Add Items First\n            </a>\n        <?php endif; ?>\n    </div>\n</div>\n\n<!-- Items Management Section -->\n<div class="card mb-4">\n    <div class="card-header">\n        <h5 class="mb-0"><i class="bi bi-gear me-2"></i>Items Management</h5>\n    </div>\n    <div class="card-body">\n        <div class="table-responsive">\n            <table class="table table-sm">\n                <thead>\n                    <tr>\n                        <th>Item Name</th>\n                        <th>Unit</th>\n                        <th>Description</th>\n                        <th>Status</th>\n                        <th>Actions</th>\n                    </tr>\n                </thead>\n                <tbody>\n                    <?php foreach ($misc_items as $item): ?>\n                        <tr class="<?= $item['active'] ? '' : 'item-inactive' ?>">\n                            <td>\n                                <strong><?= h($item['name']) ?><?= h($item['status_suffix']) ?></strong>\n                            </td>\n                            <td><?= h($item['unit_label']) ?></td>\n                            <td><?= h($item['description']) ?></td>\n                            <td>\n                                <span class="badge bg-<?= $item['active'] ? 'success' : 'secondary' ?>">\n                                    <?= $item['active'] ? 'Active' : 'Hidden' ?>\n                                </span>\n                            </td>\n                            <td>\n                                <form method="post" class="d-inline">\n                                    <input type="hidden" name="toggle_status" value="1">\n                                    <input type="hidden" name="toggle_item_id" value="<?= $item['id'] ?>">\n                                    <input type="hidden" name="current_status" value="<?= $item['active'] ?>">\n                                    <button type="submit" class="status-toggle <?= $item['active'] ? 'text-warning' : 'text-success' ?>" \n                                            onclick="return confirm('<?= $item['active'] ? 'Hide' : 'Show' ?> this item?')">\n                                        <i class="bi bi-<?= $item['active'] ? 'eye-slash' : 'eye' ?>"></i>\n                                        <?= $item['active'] ? 'Hide' : 'Show' ?>\n                                    </button>\n                                </form>\n                            </td>\n                        </tr>\n                    <?php endforeach; ?>\n                </tbody>\n            </table>\n        </div>\n    </div>\n</div>\n\n<!-- Purchase Entry Form -->\n<?php if (!empty(array_filter($misc_items, fn($item) => $item['active'] == 1))): ?>\n<div class="form-section">\n    <h5 class="mb-4"><i class="bi bi-box-arrow-in-down me-2"></i>New Purchase Entry</h5>\n    \n    <form method="post" id="purchaseForm">\n        <div class="row g-4">\n            <!-- Item Selection -->\n            <div class="col-md-4">\n                <label class="form-label fw-bold">Select Active Item *</label>\n                <select class="form-select form-select-lg" name="misc_item_id" required onchange="updateItemDetails()">\n                    <option value="">Choose miscellaneous item...</option>\n                    <?php foreach (array_filter($misc_items, fn($item) => $item['active'] == 1) as $item): ?>\n                        <option value="<?= $item['id'] ?>" \n                                data-unit="<?= h($item['unit_label']) ?>"\n                                data-description="<?= h($item['description']) ?>"\n                                <?= ($item_id == $item['id']) ? 'selected' : '' ?>>\n                            <?= h($item['name']) ?> (<?= h($item['unit_label']) ?>)\n                            <?php if ($item['description']): ?>\n                                - <?= h($item['description']) ?>\n                            <?php endif; ?>\n                        </option>\n                    <?php endforeach; ?>\n                </select>\n                <div id="itemDetails" class="mt-2 text-muted small"></div>\n            </div>\n            \n            <!-- Purchase Date -->\n            <div class="col-md-2">\n                <label class="form-label fw-bold">Purchase Date *</label>\n                <input type="date" class="form-control form-control-lg" name="purchase_date" \n                       value="<?= h($_POST['purchase_date'] ?? date('Y-m-d')) ?>" required>\n            </div>\n            \n            <!-- Total Quantity -->\n            <div class="col-md-2">\n                <label class="form-label fw-bold">Total Quantity *</label>\n                <input type="number" step="0.01" class="form-control form-control-lg" name="total_quantity" \n                       value="<?= h($_POST['total_quantity'] ?? '') ?>" required min="0" placeholder="0.00"\n                       onchange="calculateTotals()">\n                <small id="quantityUnit" class="text-muted">units</small>\n            </div>\n            \n            <!-- Damage Percentage -->\n            <div class="col-md-2">\n                <label class="form-label fw-bold">Damage %</label>\n                <input type="number" step="0.1" class="form-control form-control-lg" name="damage_percentage" \n                       value="<?= h($_POST['damage_percentage'] ?? '0') ?>" min="0" max="100" placeholder="0.0"\n                       onchange="calculateTotals()">\n                <small class="text-muted">Percentage damaged</small>\n            </div>\n            \n            <!-- Cost per Unit -->\n            <div class="col-md-2">\n                <label class="form-label fw-bold">Cost per Unit *</label>\n                <div class="input-group input-group-lg">\n                    <span class="input-group-text">₹</span>\n                    <input type="number" step="0.01" class="form-control" name="cost_per_unit" \n                           value="<?= h($_POST['cost_per_unit'] ?? '') ?>" required min="0" placeholder="0.00"\n                           onchange="calculateTotals()">\n                </div>\n            </div>\n        </div>\n        \n        <div class="row g-4 mt-2">\n            <!-- Transport Cost -->\n            <div class="col-md-3">\n                <label class="form-label fw-bold">Transport Cost</label>\n                <div class="input-group">\n                    <span class="input-group-text">₹</span>\n                    <input type="number" step="0.01" class="form-control" name="transport_cost" \n                           value="<?= h($_POST['transport_cost'] ?? '0') ?>" min="0" placeholder="0.00"\n                           onchange="calculateTotals()">\n                </div>\n            </div>\n            \n            <!-- Supplier -->\n            <div class="col-md-3">\n                <label class="form-label fw-bold">Supplier Name</label>\n                <input type="text" class="form-control" name="supplier_name" \n                       value="<?= h($_POST['supplier_name'] ?? '') ?>" placeholder="Supplier name">\n            </div>\n            \n            <!-- Invoice Number -->\n            <div class="col-md-3">\n                <label class="form-label fw-bold">Invoice Number</label>\n                <input type="text" class="form-control" name="invoice_number" \n                       value="<?= h($_POST['invoice_number'] ?? '') ?>" placeholder="Invoice #">\n            </div>\n            \n            <!-- Notes -->\n            <div class="col-md-3">\n                <label class="form-label fw-bold">Notes</label>\n                <input type="text" class="form-control" name="notes" \n                       value="<?= h($_POST['notes'] ?? '') ?>" placeholder="Additional notes">\n            </div>\n        </div>\n        \n        <!-- Enhanced Calculations Box -->\n        <div class="calculation-box" id="calculationBox" style="display: none;">\n            <div class="row g-3">\n                <div class="col-md-2">\n                    <div class="text-center">\n                        <div class="h6 text-muted">Total Quantity</div>\n                        <div class="h5" id="totalQuantity">0</div>\n                    </div>\n                </div>\n                <div class="col-md-2">\n                    <div class="text-center">\n                        <div class="h6 text-muted">Damage %</div>\n                        <div class="h5 text-warning" id="damagePercent">0%</div>\n                    </div>\n                </div>\n                <div class="col-md-2">\n                    <div class="text-center">\n                        <div class="h6 text-muted">Usable Quantity</div>\n                        <div class="h5 text-success" id="usableQuantity">0</div>\n                    </div>\n                </div>\n                <div class="col-md-2">\n                    <div class="text-center">\n                        <div class="h6 text-muted">Material Cost</div>\n                        <div class="h5" id="materialCost">₹0</div>\n                    </div>\n                </div>\n                <div class="col-md-2">\n                    <div class="text-center">\n                        <div class="h6 text-muted">Transport Cost</div>\n                        <div class="h5" id="transportCostDisplay">₹0</div>\n                    </div>\n                </div>\n                <div class="col-md-2">\n                    <div class="text-center">\n                        <div class="h6 text-muted">Total Cost</div>\n                        <div class="total-display" id="totalCost">₹0</div>\n                    </div>\n                </div>\n            </div>\n        </div>\n        \n        <div class="text-center mt-4">\n            <button type="submit" name="add_purchase" class="btn btn-success btn-lg px-5">\n                <i class="bi bi-plus-circle me-2"></i>Add Purchase Entry\n            </button>\n        </div>\n    </form>\n</div>\n<?php else: ?>\n<div class="form-section text-center">\n    <i class="bi bi-exclamation-triangle display-1 text-warning mb-3"></i>\n    <h4>No Active Miscellaneous Items Found</h4>\n    <p class="text-muted">You need to add and activate miscellaneous items before you can make purchase entries.</p>\n    <a href="misc_items.php" class="btn btn-warning btn-lg">\n        <i class="bi bi-plus-circle me-2"></i>Add Misc Items\n    </a>\n</div>\n<?php endif; ?>\n\n<!-- Recent Purchases -->\n<?php if (!empty($recent_purchases)): ?>\n<div class="card">\n    <div class="card-header">\n        <h5 class="mb-0"><i class="bi bi-clock-history me-2"></i>Recent Purchase Entries</h5>\n    </div>\n    <div class="card-body recent-purchases">\n        <div class="table-responsive">\n            <table class="table table-hover table-sm">\n                <thead class="table-light">\n                    <tr>\n                        <th>Date</th>\n                        <th>Item</th>\n                        <th>Total Qty</th>\n                        <th>Damage %</th>\n                        <th>Usable Qty</th>\n                        <th>Cost/Unit</th>\n                        <th>Total Cost</th>\n                        <th>Supplier</th>\n                        <th>Status</th>\n                    </tr>\n                </thead>\n                <tbody>\n                    <?php foreach ($recent_purchases as $purchase): ?>\n                        <tr class="<?= $purchase['active'] ? '' : 'item-inactive' ?>">\n                            <td><?= h(date('M j', strtotime($purchase['purchase_date']))) ?></td>\n                            <td>\n                                <strong><?= h($purchase['item_name']) ?></strong>\n                                <small class="text-muted d-block"><?= h($purchase['unit_label']) ?></small>\n                                <?php if (!$purchase['active']): ?>\n                                    <small class="text-danger">(Hidden)</small>\n                                <?php endif; ?>\n                            </td>\n                            <td><?= number_format($purchase['total_quantity'], 2) ?></td>\n                            <td class="text-warning"><?= number_format($purchase['damage_percentage'], 1) ?>%</td>\n                            <td class="text-success fw-bold"><?= number_format($purchase['usable_quantity'], 2) ?></td>\n                            <td>₹<?= number_format($purchase['cost_per_unit'], 2) ?></td>\n                            <td class="fw-bold">₹<?= number_format($purchase['final_cost'], 2) ?></td>\n                            <td><?= h($purchase['supplier_name']) ?: '-' ?></td>\n                            <td>\n                                <span class="badge bg-<?= $purchase['active'] ? 'success' : 'secondary' ?>">\n                                    <?= $purchase['active'] ? 'Active' : 'Hidden' ?>\n                                </span>\n                            </td>\n                        </tr>\n                    <?php endforeach; ?>\n                </tbody>\n            </table>\n        </div>\n    </div>\n</div>\n<?php endif; ?>\n\n<script>\nfunction updateItemDetails() {\n    const select = document.querySelector('select[name="misc_item_id"]');\n    const option = select.selectedOptions[0];\n    const detailsDiv = document.getElementById('itemDetails');\n    const quantityUnit = document.getElementById('quantityUnit');\n    \n    if (option && option.value) {\n        const unit = option.getAttribute('data-unit');\n        const description = option.getAttribute('data-description');\n        \n        detailsDiv.innerHTML = `\n            <strong>Unit:</strong> ${unit}\n            ${description ? `<br><strong>Description:</strong> ${description}` : ''}\n        `;\n        quantityUnit.textContent = unit;\n        \n        calculateTotals();\n    } else {\n        detailsDiv.innerHTML = '';\n        quantityUnit.textContent = 'units';\n        document.getElementById('calculationBox').style.display = 'none';\n    }\n}\n\nfunction calculateTotals() {\n    const totalQty = parseFloat(document.querySelector('input[name="total_quantity"]').value) || 0;\n    const damagePercent = parseFloat(document.querySelector('input[name="damage_percentage"]').value) || 0;\n    const costPerUnit = parseFloat(document.querySelector('input[name="cost_per_unit"]').value) || 0;\n    const transport = parseFloat(document.querySelector('input[name="transport_cost"]').value) || 0;\n    \n    const usableQty = totalQty * (1 - damagePercent/100);\n    const materialCost = totalQty * costPerUnit;\n    const totalCost = materialCost + transport;\n    \n    document.getElementById('totalQuantity').textContent = totalQty.toFixed(2);\n    document.getElementById('damagePercent').textContent = damagePercent.toFixed(1) + '%';\n    document.getElementById('usableQuantity').textContent = usableQty.toFixed(2);\n    document.getElementById('materialCost').textContent = '₹' + materialCost.toFixed(2);\n    document.getElementById('transportCostDisplay').textContent = '₹' + transport.toFixed(2);\n    document.getElementById('totalCost').textContent = '₹' + totalCost.toFixed(2);\n    \n    // Show calculation box if we have values\n    if (totalQty > 0 && costPerUnit > 0) {\n        document.getElementById('calculationBox').style.display = 'block';\n    } else {\n        document.getElementById('calculationBox').style.display = 'none';\n    }\n}\n\n// Initialize on page load\ndocument.addEventListener('DOMContentLoaded', function() {\n    updateItemDetails();\n    calculateTotals();\n});\n\n// Form validation\ndocument.getElementById('purchaseForm').addEventListener('submit', function(e) {\n    const totalQty = parseFloat(document.querySelector('input[name="total_quantity"]').value) || 0;\n    const damagePercent = parseFloat(document.querySelector('input[name="damage_percentage"]').value) || 0;\n    \n    if (damagePercent > 100) {\n        e.preventDefault();\n        alert('Damage percentage cannot exceed 100%!');\n        return false;\n    }\n    \n    if (totalQty <= 0) {\n        e.preventDefault();\n        alert('Total quantity must be greater than 0!');\n        return false;\n    }\n});\n</script>\n\n<?php require_once __DIR__ . '/../includes/footer.php'; ?>