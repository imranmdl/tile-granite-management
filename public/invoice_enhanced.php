<?php
// public/invoice_enhanced.php - Enhanced Invoice system with conversion from quotation
require_once __DIR__ . '/../includes/simple_auth.php';
require_once __DIR__ . '/../includes/helpers.php';

auth_require_login();

$pdo = Database::pdo();
$message = '';
$error = '';
$id = (int)($_GET['id'] ?? 0);
$from_quote = (int)($_GET['from_quote'] ?? 0);

// Convert quotation to invoice
if ($from_quote > 0) {
    try {
        // Get quotation data
        $quote_stmt = $pdo->prepare("SELECT * FROM quotations WHERE id = ?");
        $quote_stmt->execute([$from_quote]);
        $quotation = $quote_stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$quotation) {
            $error = 'Quotation not found';
        } else {
            // Create invoice from quotation
            $invoice_no = 'INV' . date('ymdHis');
            $invoice_dt = date('Y-m-d');
            $user_id = $_SESSION['user_id'] ?? 1;
            
            $pdo->beginTransaction();
            
            // Create invoice header
            $stmt = $pdo->prepare("
                INSERT INTO invoices (invoice_no, invoice_dt, customer_name, firm_name, phone, customer_gst, 
                                    total, discount_amount, final_total, notes, status, created_by, updated_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', ?, datetime('now'))
            ");
            
            $stmt->execute([
                $invoice_no, $invoice_dt, $quotation['customer_name'], $quotation['firm_name'], 
                $quotation['phone'], $quotation['customer_gst'], $quotation['total'], 0, 
                $quotation['total'], $quotation['notes'], $user_id
            ]);
            
            $invoice_id = (int)$pdo->lastInsertId();
            
            // Copy tile items
            $tile_items_stmt = $pdo->prepare("
                SELECT * FROM quotation_items WHERE quotation_id = ?
            ");
            $tile_items_stmt->execute([$from_quote]);
            $tile_items = $tile_items_stmt->fetchAll(PDO::FETCH_ASSOC);
            
            foreach ($tile_items as $item) {
                $stmt = $pdo->prepare("
                    INSERT INTO invoice_items 
                    (invoice_id, purpose, tile_id, calculation_mode, direct_boxes, length_ft, width_ft, 
                     extra_sqft, total_sqft, rate_per_sqft, rate_per_box, boxes_decimal, line_total, show_image)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                
                $stmt->execute([
                    $invoice_id, $item['purpose'], $item['tile_id'], $item['calculation_mode'],
                    $item['direct_boxes'], $item['length_ft'], $item['width_ft'], $item['extra_sqft'],
                    $item['total_sqft'], $item['rate_per_sqft'], $item['rate_per_box'], 
                    $item['boxes_decimal'], $item['line_total'], $item['show_image']
                ]);
            }
            
            // Copy misc items
            $misc_items_stmt = $pdo->prepare("
                SELECT * FROM quotation_misc_items WHERE quotation_id = ?
            ");
            $misc_items_stmt->execute([$from_quote]);
            $misc_items = $misc_items_stmt->fetchAll(PDO::FETCH_ASSOC);
            
            foreach ($misc_items as $item) {
                $stmt = $pdo->prepare("
                    INSERT INTO invoice_misc_items 
                    (invoice_id, purpose, misc_item_id, qty_units, rate_per_unit, line_total, show_image)
                    VALUES (?, ?, ?, ?, ?, ?, ?)
                ");
                
                $stmt->execute([
                    $invoice_id, $item['purpose'], $item['misc_item_id'], $item['qty_units'],
                    $item['rate_per_unit'], $item['line_total'], $item['show_image']
                ]);
            }
            
            $pdo->commit();
            
            $message = "Invoice {$invoice_no} created successfully from quotation {$quotation['quote_no']}";
            safe_redirect('invoice_enhanced.php?id=' . $invoice_id);
        }
        
    } catch (Exception $e) {
        $pdo->rollback();
        $error = 'Failed to convert quotation to invoice: ' . $e->getMessage();
    }
}

// Get user preferences
$user_id = $_SESSION['user_id'] ?? 1;
$show_images_stmt = $pdo->prepare("SELECT preference_value FROM user_preferences WHERE user_id = ? AND preference_key = 'show_item_images'");
$show_images_stmt->execute([$user_id]);
$show_images = ($show_images_stmt->fetchColumn() === 'true');

// Handle invoice creation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_invoice'])) {
    $invoice_no = 'INV' . date('ymdHis');
    $invoice_dt = $_POST['invoice_dt'] ?? date('Y-m-d');
    $customer_name = trim($_POST['customer_name'] ?? '');
    $firm_name = trim($_POST['firm_name'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $customer_gst = trim($_POST['customer_gst'] ?? '');
    $notes = trim($_POST['notes'] ?? '');
    
    // Validation
    if (!$customer_name) {
        $error = 'Customer name is required';
    } elseif (!$phone) {
        $error = 'Mobile number is required';
    } elseif (!preg_match('/^[0-9]{10}$/', $phone)) {
        $error = 'Mobile number must be 10 digits';
    } else {
        try {
            $stmt = $pdo->prepare("
                INSERT INTO invoices (invoice_no, invoice_dt, customer_name, firm_name, phone, customer_gst, 
                                    notes, status, created_by, updated_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, 'pending', ?, datetime('now'))
            ");
            if ($stmt->execute([$invoice_no, $invoice_dt, $customer_name, $firm_name, $phone, $customer_gst, $notes, $user_id])) {
                $new_id = (int)$pdo->lastInsertId();
                safe_redirect('invoice_enhanced.php?id=' . $new_id);
            } else {
                $error = 'Failed to create invoice';
            }
        } catch (Exception $e) {
            $error = 'Database error: ' . $e->getMessage();
        }
    }
}

// Handle return processing
if ($id > 0 && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['process_return'])) {
    $item_id = (int)$_POST['item_id'];
    $item_type = $_POST['item_type'];
    $return_quantity = (float)$_POST['return_quantity'];
    $refund_rate = (float)$_POST['refund_rate'];
    $return_reason = trim($_POST['return_reason'] ?? '');
    $return_notes = trim($_POST['return_notes'] ?? '');
    $user_id = $_SESSION['user_id'] ?? 1;
    
    // Validate return is within 15 days
    $stmt = $pdo->prepare("SELECT invoice_dt FROM invoices WHERE id = ?");
    $stmt->execute([$id]);
    $invoice_dt = $stmt->fetchColumn();
    
    if (strtotime($invoice_dt) < strtotime('-15 days')) {
        $error = 'Return period has expired. Items can only be returned within 15 days of purchase.';
    } elseif (!$return_reason) {
        $error = 'Return reason is required';
    } elseif ($return_quantity <= 0) {
        $error = 'Return quantity must be greater than 0';
    } else {
        try {
            // Get original item details
            if ($item_type === 'tile') {
                $stmt = $pdo->prepare("SELECT ii.*, t.id as tile_id FROM invoice_items ii JOIN tiles t ON ii.tile_id = t.id WHERE ii.id = ? AND ii.invoice_id = ?");
            } else {
                $stmt = $pdo->prepare("SELECT imi.*, m.id as misc_id FROM invoice_misc_items imi JOIN misc_items m ON imi.misc_item_id = m.id WHERE imi.id = ? AND imi.invoice_id = ?");
            }
            $stmt->execute([$item_id, $id]);
            $original_item = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$original_item) {
                $error = 'Original item not found';
            } else {
                $original_rate = $item_type === 'tile' ? $original_item['rate_per_box'] : $original_item['rate_per_unit'];
                $refund_amount = $return_quantity * $refund_rate;
                $actual_item_id = $item_type === 'tile' ? $original_item['tile_id'] : $original_item['misc_id'];
                
                // Insert return entry
                $stmt = $pdo->prepare("
                    INSERT INTO individual_returns 
                    (invoice_id, invoice_item_id, invoice_misc_item_id, item_type, item_id, quantity_returned, 
                     original_rate, refund_rate, refund_amount, return_reason, return_date, notes, processed_by)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, date('now'), ?, ?)
                ");
                
                $invoice_item_id = $item_type === 'tile' ? $item_id : null;
                $invoice_misc_item_id = $item_type === 'misc' ? $item_id : null;
                
                if ($stmt->execute([$id, $invoice_item_id, $invoice_misc_item_id, $item_type, $actual_item_id, 
                                   $return_quantity, $original_rate, $refund_rate, $refund_amount, 
                                   $return_reason, $return_notes, $user_id])) {
                    $message = 'Return processed successfully. Refund amount: ₹' . number_format($refund_amount, 2);
                } else {
                    $error = 'Failed to process return';
                }
            }
        } catch (Exception $e) {
            $error = 'Database error: ' . $e->getMessage();
        }
    }
}

// Handle discount application
if ($id > 0 && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['apply_discount'])) {
    $discount_type = $_POST['discount_type'] ?? 'percentage';
    $discount_value = (float)$_POST['discount_value'];
    
    // Get current invoice total
    $total_stmt = $pdo->prepare("
        SELECT 
            COALESCE(SUM(ii.line_total), 0) + COALESCE(SUM(imi.line_total), 0) as subtotal
        FROM invoices i
        LEFT JOIN invoice_items ii ON i.id = ii.invoice_id
        LEFT JOIN invoice_misc_items imi ON i.id = imi.invoice_id
        WHERE i.id = ?
    ");
    $total_stmt->execute([$id]);
    $subtotal = (float)$total_stmt->fetchColumn();
    
    if ($discount_type === 'percentage') {
        $discount_amount = $subtotal * ($discount_value / 100);
    } else {
        $discount_amount = $discount_value;
    }
    
    $final_total = $subtotal - $discount_amount;
    
    // Update invoice with discount
    $stmt = $pdo->prepare("
        UPDATE invoices 
        SET total = ?, discount_type = ?, discount_value = ?, discount_amount = ?, final_total = ?, updated_at = datetime('now')
        WHERE id = ?
    ");
    
    if ($stmt->execute([$subtotal, $discount_type, $discount_value, $discount_amount, $final_total, $id])) {
        $message = 'Discount applied successfully';
    } else {
        $error = 'Failed to apply discount';
    }
}

// Get invoice data if ID provided
$invoice = null;
$invoice_items = [];
$invoice_misc_items = [];
$invoice_returns = [];

if ($id > 0) {
    // Get invoice header
    $stmt = $pdo->prepare("SELECT * FROM invoices WHERE id = ?");
    $stmt->execute([$id]);
    $invoice = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($invoice) {
        // Get tile items with stock info
        $items_stmt = $pdo->prepare("
            SELECT ii.*, t.name as tile_name, ts.label as size_label, ts.sqft_per_box, t.photo_path,
                   cts.total_stock_boxes as current_stock
            FROM invoice_items ii
            JOIN tiles t ON ii.tile_id = t.id
            JOIN tile_sizes ts ON t.size_id = ts.id
            LEFT JOIN current_tiles_stock cts ON t.id = cts.id
            WHERE ii.invoice_id = ?
            ORDER BY ii.id
        ");
        $items_stmt->execute([$id]);
        $invoice_items = $items_stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Get misc items with stock info
        $misc_stmt = $pdo->prepare("
            SELECT imi.*, m.name as item_name, m.unit_label, m.photo_path,
                   cms.total_stock_quantity as current_stock
            FROM invoice_misc_items imi
            JOIN misc_items m ON imi.misc_item_id = m.id
            LEFT JOIN current_misc_stock cms ON m.id = cms.id
            WHERE imi.invoice_id = ?
            ORDER BY imi.id
        ");
        $misc_stmt->execute([$id]);
        $invoice_misc_items = $misc_stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Get return entries
        $returns_stmt = $pdo->prepare("
            SELECT ir.*, 
                   CASE 
                       WHEN ir.item_type = 'tile' THEN t.name 
                       WHEN ir.item_type = 'misc' THEN m.name 
                   END as item_name,
                   CASE 
                       WHEN ir.item_type = 'tile' THEN ts.label 
                       WHEN ir.item_type = 'misc' THEN m.unit_label 
                   END as item_unit
            FROM individual_returns ir
            LEFT JOIN tiles t ON ir.item_type = 'tile' AND ir.item_id = t.id
            LEFT JOIN tile_sizes ts ON t.size_id = ts.id
            LEFT JOIN misc_items m ON ir.item_type = 'misc' AND ir.item_id = m.id
            WHERE ir.invoice_id = ?
            ORDER BY ir.return_date DESC
        ");
        $returns_stmt->execute([$id]);
        $invoice_returns = $returns_stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Recalculate totals
        $subtotal = array_sum(array_column($invoice_items, 'line_total')) + array_sum(array_column($invoice_misc_items, 'line_total'));
        $total_returns = array_sum(array_column($invoice_returns, 'refund_amount'));
        
        if ($subtotal != $invoice['total']) {
            // Update invoice totals
            $discount_amount = (float)($invoice['discount_amount'] ?? 0);
            $final_total = $subtotal - $discount_amount - $total_returns;
            
            $update_stmt = $pdo->prepare("
                UPDATE invoices 
                SET total = ?, final_total = ?, updated_at = datetime('now')
                WHERE id = ?
            ");
            $update_stmt->execute([$subtotal, $final_total, $id]);
            $invoice['total'] = $subtotal;
            $invoice['final_total'] = $final_total;
        }
    }
}

$page_title = $invoice ? "Edit Invoice: " . $invoice['invoice_no'] : "Create New Invoice";
require_once __DIR__ . '/../includes/header.php';
?>

<style>
.invoice-header {
    background: linear-gradient(135deg, #28a745 0%, #20c997 100%);
    color: white;
    border-radius: 12px;
    padding: 20px;
    margin-bottom: 20px;
}

.discount-section {
    background: #fff3e0;
    border-radius: 8px;
    padding: 15px;
    margin-bottom: 20px;
    border: 1px solid #ffb74d;
}

.return-section {
    background: #ffebee;
    border-radius: 8px;
    padding: 15px;
    margin-bottom: 20px;
    border: 1px solid #ef5350;
}

.return-policy {
    background: #e3f2fd;
    border-radius: 6px;
    padding: 10px;
    margin-bottom: 15px;
    border-left: 4px solid #2196f3;
}

.total-breakdown {
    background: #f8f9fa;
    border-radius: 8px;
    padding: 15px;
    border: 1px solid #dee2e6;
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

<?php if (!$invoice): ?>
    <!-- Create New Invoice -->
    <div class="invoice-header">
        <h4 class="mb-0"><i class="bi bi-receipt"></i> Create New Invoice</h4>
    </div>

    <div class="card">
        <div class="card-body">
            <form method="post" id="createInvoiceForm">
                <div class="row g-3">
                    <div class="col-md-3">
                        <label class="form-label">Invoice Date *</label>
                        <input type="date" class="form-control" name="invoice_dt" value="<?= date('Y-m-d') ?>" required>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Customer Name *</label>
                        <input type="text" class="form-control" name="customer_name" required>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Firm Name</label>
                        <input type="text" class="form-control" name="firm_name" placeholder="Optional">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Mobile Number *</label>
                        <input type="tel" class="form-control" name="phone" pattern="[0-9]{10}" 
                               placeholder="10-digit mobile number" required>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Customer GST Number</label>
                        <input type="text" class="form-control" name="customer_gst" 
                               placeholder="Optional GST number">
                    </div>
                    <div class="col-md-8">
                        <label class="form-label">Notes</label>
                        <textarea class="form-control" name="notes" rows="2" placeholder="Additional notes"></textarea>
                    </div>
                </div>
                <div class="mt-4">
                    <button type="submit" name="create_invoice" class="btn btn-success btn-lg">
                        <i class="bi bi-check-circle"></i> Create Invoice
                    </button>
                    <a href="invoice_list_enhanced.php" class="btn btn-outline-secondary btn-lg ms-2">
                        <i class="bi bi-arrow-left"></i> Back to List
                    </a>
                </div>
            </form>
        </div>
    </div>

<?php else: ?>
    <!-- Edit Existing Invoice -->
    <div class="invoice-header">
        <div class="d-flex justify-content-between align-items-center">
            <div>
                <h4 class="mb-1"><?= h($invoice['invoice_no']) ?></h4>
                <div>
                    <span class="badge bg-light text-dark me-2">
                        <i class="bi bi-calendar"></i> <?= date('M j, Y', strtotime($invoice['invoice_dt'])) ?>
                    </span>
                    <span class="badge bg-light text-dark me-2">
                        <i class="bi bi-person"></i> <?= h($invoice['customer_name']) ?>
                    </span>
                    <?php if ($invoice['firm_name']): ?>
                    <span class="badge bg-light text-dark me-2">
                        <i class="bi bi-building"></i> <?= h($invoice['firm_name']) ?>
                    </span>
                    <?php endif; ?>
                    <span class="badge bg-<?= $invoice['status'] === 'paid' ? 'success' : ($invoice['status'] === 'partial' ? 'warning' : 'secondary') ?>">
                        <?= ucfirst($invoice['status']) ?>
                    </span>
                </div>
            </div>
            <div>
                <span class="badge bg-success fs-6">₹<?= number_format($invoice['final_total'] ?? $invoice['total'], 2) ?></span>
            </div>
        </div>
    </div>

    <!-- Discount Section -->
    <div class="discount-section">
        <h6><i class="bi bi-percent"></i> Apply Discount</h6>
        <form method="post" class="row g-3">
            <input type="hidden" name="apply_discount" value="1">
            <div class="col-md-3">
                <label class="form-label">Discount Type</label>
                <select class="form-select" name="discount_type" onchange="updateDiscountLabel()">
                    <option value="percentage">Percentage (%)</option>
                    <option value="fixed">Fixed Amount (₹)</option>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label" id="discountLabel">Discount Percentage</label>
                <input type="number" class="form-control" name="discount_value" step="0.01" min="0" oninput="calculateDiscount()">
            </div>
            <div class="col-md-3">
                <label class="form-label">Discount Amount</label>
                <input type="text" class="form-control" id="discountAmount" readonly>
            </div>
            <div class="col-md-3">
                <label class="form-label">&nbsp;</label>
                <div>
                    <button type="submit" class="btn btn-warning">
                        <i class="bi bi-check"></i> Apply Discount
                    </button>
                </div>
            </div>
        </form>
    </div>

    <!-- Total Breakdown -->
    <div class="total-breakdown mb-4">
        <h6><i class="bi bi-calculator"></i> Invoice Summary</h6>
        <div class="row">
            <div class="col-md-3">
                <strong>Subtotal:</strong><br>
                <span class="fs-5">₹<?= number_format($invoice['total'], 2) ?></span>
            </div>
            <?php if (($invoice['discount_amount'] ?? 0) > 0): ?>
            <div class="col-md-3">
                <strong>Discount:</strong><br>
                <span class="fs-5 text-warning">-₹<?= number_format($invoice['discount_amount'] ?? 0, 2) ?></span>
            </div>
            <?php endif; ?>
            <?php if (!empty($invoice_returns)): ?>
            <div class="col-md-3">
                <strong>Returns:</strong><br>
                <span class="fs-5 text-danger">-₹<?= number_format(array_sum(array_column($invoice_returns, 'refund_amount')), 2) ?></span>
            </div>
            <?php endif; ?>
            <div class="col-md-3">
                <strong>Final Total:</strong><br>
                <span class="fs-4 text-success">₹<?= number_format($invoice['final_total'] ?? $invoice['total'], 2) ?></span>
            </div>
        </div>
    </div>

    <!-- Return Policy Notice -->
    <div class="return-policy">
        <i class="bi bi-info-circle"></i> <strong>Return Policy:</strong> 
        Items can be returned within 15 days of purchase date (<?= date('M j, Y', strtotime($invoice['invoice_dt'] . ' +15 days')) ?>). 
        Refund amounts may vary based on item condition and market rates.
    </div>

    <!-- Invoice Items Display -->
    <?php if (!empty($invoice_items) || !empty($invoice_misc_items)): ?>
    <div class="card mb-4">
        <div class="card-header d-flex justify-content-between align-items-center">
            <h5 class="mb-0">Invoice Items</h5>
            <div class="btn-group btn-group-sm">
                <button type="button" class="btn btn-outline-primary" onclick="addNewItem()">
                    <i class="bi bi-plus"></i> Add Item
                </button>
                <button type="button" class="btn btn-outline-warning" onclick="processReturn()">
                    <i class="bi bi-arrow-return-left"></i> Process Return
                </button>
            </div>
        </div>
        <div class="card-body">
            <!-- Display items similar to quotation but with return options -->
            <?php if (!empty($invoice_items)): ?>
                <h6 class="text-primary mb-3">Tile Items</h6>
                <div class="table-responsive">
                    <table class="table table-hover">
                        <thead>
                            <tr>
                                <th>Item</th>
                                <th>Purpose</th>
                                <th>Quantity</th>
                                <th>Rate</th>
                                <th>Amount</th>
                                <th>Return Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($invoice_items as $item): ?>
                                <tr>
                                    <td>
                                        <?php if ($item['show_image'] && $item['photo_path']): ?>
                                            <img src="<?= h($item['photo_path']) ?>" class="item-image" style="width: 40px; height: 40px; object-fit: cover; border-radius: 4px; margin-right: 8px;">
                                        <?php endif; ?>
                                        <strong><?= h($item['tile_name']) ?></strong><br>
                                        <small class="text-muted"><?= h($item['size_label']) ?></small>
                                    </td>
                                    <td><?= h($item['purpose']) ?></td>
                                    <td><?= number_format($item['boxes_decimal'], 1) ?> boxes</td>
                                    <td>₹<?= number_format($item['rate_per_box'], 2) ?>/box</td>
                                    <td class="fw-bold">₹<?= number_format($item['line_total'], 2) ?></td>
                                    <td>
                                        <?php
                                        // Check if item has returns
                                        $item_returns = array_filter($invoice_returns, function($r) use ($item) {
                                            return $r['item_type'] === 'tile' && $r['invoice_item_id'] == $item['id'];
                                        });
                                        if (!empty($item_returns)):
                                            $total_returned = array_sum(array_column($item_returns, 'quantity_returned'));
                                        ?>
                                            <span class="badge bg-warning">Partial Return</span><br>
                                            <small>Returned: <?= number_format($total_returned, 1) ?> boxes</small>
                                        <?php else: ?>
                                            <span class="badge bg-success">Available</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div class="btn-group btn-group-sm">
                                            <button type="button" class="btn btn-outline-warning" onclick="editInvoiceItem(<?= $item['id'] ?>, 'tile')">
                                                <i class="bi bi-pencil"></i>
                                            </button>
                                            <button type="button" class="btn btn-outline-danger" onclick="returnItem(<?= $item['id'] ?>, 'tile', <?= $item['boxes_decimal'] ?>, '<?= h($item['tile_name']) ?>')">
                                                <i class="bi bi-arrow-return-left"></i>
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>

            <!-- Display misc items similarly -->
            <?php if (!empty($invoice_misc_items)): ?>
                <h6 class="text-success mb-3 <?= !empty($invoice_items) ? 'mt-4' : '' ?>">Other Items</h6>
                <!-- Similar table structure for misc items -->
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- Returns History -->
    <?php if (!empty($invoice_returns)): ?>
    <div class="return-section">
        <h6><i class="bi bi-arrow-return-left"></i> Returns & Refunds History</h6>
        <div class="table-responsive">
            <table class="table table-sm">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Item</th>
                        <th>Quantity Returned</th>
                        <th>Refund Amount</th>
                        <th>Reason</th>
                        <th>Notes</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($invoice_returns as $return): ?>
                        <tr>
                            <td><?= date('M j, Y', strtotime($return['return_date'])) ?></td>
                            <td><?= h($return['item_name']) ?></td>
                            <td><?= number_format($return['quantity_returned'], 1) ?> <?= h($return['item_unit']) ?></td>
                            <td class="text-danger fw-bold">₹<?= number_format($return['refund_amount'], 2) ?></td>
                            <td><?= h($return['return_reason']) ?></td>
                            <td><?= h($return['notes']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>

    <!-- Actions -->
    <div class="mt-4">
        <a href="invoice_list_enhanced.php" class="btn btn-outline-secondary">
            <i class="bi bi-arrow-left"></i> Back to List
        </a>
        <a href="invoice_view.php?id=<?= $id ?>" class="btn btn-primary">
            <i class="bi bi-eye"></i> View/Print
        </a>
        <button type="button" class="btn btn-success" onclick="markAsPaid(<?= $id ?>)">
            <i class="bi bi-check-circle"></i> Mark as Paid
        </button>
    </div>

<?php endif; ?>

<!-- Return Modal -->
<div class="modal fade" id="returnModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form id="returnForm" method="post">
                <div class="modal-header">
                    <h5 class="modal-title">Process Item Return</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="process_return" value="1">
                    <input type="hidden" name="item_id" id="returnItemId">
                    <input type="hidden" name="item_type" id="returnItemType">
                    
                    <div class="alert alert-info">
                        <strong>Return Policy:</strong> Items can only be returned within 15 days of purchase.
                        Current date: <?= date('M j, Y') ?> | Purchase date: <?= isset($invoice) ? date('M j, Y', strtotime($invoice['invoice_dt'])) : '' ?>
                        <?php if (isset($invoice) && strtotime($invoice['invoice_dt']) < strtotime('-15 days')): ?>
                            <br><span class="text-danger">⚠️ Return period expired - cannot process returns</span>
                        <?php endif; ?>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Item: <span id="returnItemName" class="fw-bold"></span></label>
                    </div>
                    
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">Maximum Returnable Quantity</label>
                            <input type="number" class="form-control" id="maxReturnQty" readonly>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Quantity to Return *</label>
                            <input type="number" class="form-control" name="return_quantity" step="0.1" min="0.1" required oninput="calculateRefund()">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Original Rate</label>
                            <input type="number" class="form-control" id="originalRate" readonly>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Refund Rate per Unit *</label>
                            <input type="number" class="form-control" name="refund_rate" step="0.01" required oninput="calculateRefund()">
                            <small class="text-muted">May differ from original rate</small>
                        </div>
                        <div class="col-12">
                            <label class="form-label">Total Refund Amount</label>
                            <input type="number" class="form-control" id="totalRefund" readonly>
                        </div>
                        <div class="col-12">
                            <label class="form-label">Return Reason *</label>
                            <select class="form-select" name="return_reason" required>
                                <option value="">Select reason...</option>
                                <option value="defective">Defective/Damaged</option>
                                <option value="wrong_item">Wrong Item Delivered</option>
                                <option value="customer_change">Customer Changed Mind</option>
                                <option value="excess_quantity">Excess Quantity</option>
                                <option value="quality_issue">Quality Issue</option>
                                <option value="other">Other</option>
                            </select>
                        </div>
                        <div class="col-12">
                            <label class="form-label">Notes</label>
                            <textarea class="form-control" name="return_notes" rows="2" placeholder="Additional notes about the return"></textarea>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-warning">
                        <i class="bi bi-arrow-return-left"></i> Process Return
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function updateDiscountLabel() {
    const discountType = document.querySelector('[name="discount_type"]').value;
    const label = document.getElementById('discountLabel');
    const input = document.querySelector('[name="discount_value"]');
    
    if (discountType === 'percentage') {
        label.textContent = 'Discount Percentage';
        input.placeholder = 'Enter percentage (e.g., 10 for 10%)';
        input.max = '100';
    } else {
        label.textContent = 'Discount Amount';
        input.placeholder = 'Enter fixed amount in ₹';
        input.removeAttribute('max');
    }
}

function calculateDiscount() {
    const subtotal = <?= isset($invoice) ? $invoice['total'] : 0 ?>;
    const discountType = document.querySelector('[name="discount_type"]').value;
    const discountValue = parseFloat(document.querySelector('[name="discount_value"]').value) || 0;
    
    let discountAmount = 0;
    if (discountType === 'percentage') {
        discountAmount = subtotal * (discountValue / 100);
    } else {
        discountAmount = discountValue;
    }
    
    document.getElementById('discountAmount').value = '₹' + discountAmount.toFixed(2);
}

function returnItem(itemId, itemType, maxQuantity, itemName) {
    <?php if (isset($invoice) && strtotime($invoice['invoice_dt']) < strtotime('-15 days')): ?>
        alert('Return period has expired. Items can only be returned within 15 days of purchase.');
        return;
    <?php endif; ?>
    
    document.getElementById('returnItemId').value = itemId;
    document.getElementById('returnItemType').value = itemType;
    document.getElementById('returnItemName').textContent = itemName;
    document.getElementById('maxReturnQty').value = maxQuantity.toFixed(1);
    
    // Reset form
    document.querySelector('[name="return_quantity"]').value = '';
    document.querySelector('[name="refund_rate"]').value = '';
    document.getElementById('totalRefund').value = '';
    
    new bootstrap.Modal(document.getElementById('returnModal')).show();
}

function calculateRefund() {
    const quantity = parseFloat(document.querySelector('[name="return_quantity"]').value) || 0;
    const rate = parseFloat(document.querySelector('[name="refund_rate"]').value) || 0;
    const totalRefund = quantity * rate;
    
    document.getElementById('totalRefund').value = '₹' + totalRefund.toFixed(2);
}

function editInvoiceItem(itemId, itemType) {
    alert('Edit invoice item functionality - Feature coming soon!');
}

function addNewItem() {
    alert('Add new item to invoice functionality - Feature coming soon!');
}

function processReturn() {
    alert('Please use the return button next to each item for individual returns.');
}

function markAsPaid(invoiceId) {
    if (confirm('Mark this invoice as paid?')) {
        // Implement mark as paid functionality
        alert('Mark as paid functionality - Feature coming soon!');
    }
}
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>