<?php
// public/quotation_view.php - View and Print Quotations
require_once __DIR__ . '/../includes/simple_auth.php';
require_once __DIR__ . '/../includes/helpers.php';

auth_require_login();

$pdo = Database::pdo();
$id = (int)($_GET['id'] ?? 0);

if ($id <= 0) {
    $_SESSION['error'] = 'Invalid quotation ID';
    header('Location: quotation_list_enhanced.php');
    exit;
}

// Get quotation data
$quotation_stmt = $pdo->prepare("SELECT * FROM quotations WHERE id = ?");
$quotation_stmt->execute([$id]);
$quotation = $quotation_stmt->fetch(PDO::FETCH_ASSOC);

if (!$quotation) {
    $_SESSION['error'] = 'Quotation not found';
    header('Location: quotation_list_enhanced.php');
    exit;
}

// Get tile items with stock info - using CORRECT stock tables
$items_stmt = $pdo->prepare("
    SELECT qi.*, t.name as tile_name, ts.label as size_label, ts.sqft_per_box, t.photo_path,
           COALESCE(cts.total_stock_boxes, 0) as current_stock
    FROM quotation_items qi
    JOIN tiles t ON qi.tile_id = t.id
    JOIN tile_sizes ts ON t.size_id = ts.id
    LEFT JOIN current_tiles_stock cts ON t.id = cts.id
    WHERE qi.quotation_id = ?
    ORDER BY qi.id
");
$items_stmt->execute([$id]);
$quotation_items = $items_stmt->fetchAll(PDO::FETCH_ASSOC);

// Get misc items with stock info - using CORRECT stock tables  
$misc_stmt = $pdo->prepare("
    SELECT qmi.*, m.name as item_name, m.unit_label, m.photo_path,
           cms.total_stock_quantity as current_stock
    FROM quotation_misc_items qmi
    JOIN misc_items m ON qmi.misc_item_id = m.id
    LEFT JOIN current_misc_stock cms ON m.id = cms.id
    WHERE qmi.quotation_id = ?
    ORDER BY qmi.id
");
$misc_stmt->execute([$id]);
$quotation_misc_items = $misc_stmt->fetchAll(PDO::FETCH_ASSOC);

// Calculate totals
$subtotal = 0;
foreach ($quotation_items as $item) {
    $subtotal += $item['line_total'];
}
foreach ($quotation_misc_items as $item) {
    $subtotal += $item['line_total'];
}

$discount_amount = $quotation['discount_amount'] ?? 0;
$final_total = $subtotal - $discount_amount;

$page_title = "View Quotation: " . $quotation['quote_no'];
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title><?= h($page_title) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.8.1/font/bootstrap-icons.css">
    <style>
        @media print {
            .no-print { display: none !important; }
            body { -webkit-print-color-adjust: exact; }
        }
        .quotation-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 20px;
            border-radius: 10px;
            margin-bottom: 20px;
        }
        .company-logo {
            font-size: 2rem;
            font-weight: bold;
        }
    </style>
</head>
<body>

<div class="container mt-4">
    <!-- Print Controls -->
    <div class="no-print mb-3">
        <div class="d-flex justify-content-between">
            <a href="quotation_enhanced.php?id=<?= $id ?>" class="btn btn-outline-secondary">
                <i class="bi bi-arrow-left"></i> Back to Edit
            </a>
            <div>
                <button onclick="window.print()" class="btn btn-primary">
                    <i class="bi bi-printer"></i> Print Quotation
                </button>
                <a href="quotation_list_enhanced.php" class="btn btn-outline-secondary ms-2">
                    <i class="bi bi-list"></i> All Quotations
                </a>
            </div>
        </div>
    </div>

    <!-- Quotation Header -->
    <div class="quotation-header">
        <div class="row">
            <div class="col-md-6">
                <div class="company-logo">
                    <i class="bi bi-building"></i> Your Company Name
                </div>
                <p class="mb-0">Complete Tile Solutions</p>
                <small>Address • Phone • Email</small>
            </div>
            <div class="col-md-6 text-end">
                <h3>QUOTATION</h3>
                <p class="mb-1"><strong><?= h($quotation['quote_no']) ?></strong></p>
                <p class="mb-0">Date: <?= date('F j, Y', strtotime($quotation['quote_dt'])) ?></p>
            </div>
        </div>
    </div>

    <!-- Customer Information -->
    <div class="card mb-4">
        <div class="card-header bg-light">
            <h5 class="mb-0">Customer Details</h5>
        </div>
        <div class="card-body">
            <div class="row">
                <div class="col-md-6">
                    <p><strong>Customer Name:</strong> <?= h($quotation['customer_name']) ?></p>
                    <?php if ($quotation['firm_name']): ?>
                    <p><strong>Firm Name:</strong> <?= h($quotation['firm_name']) ?></p>
                    <?php endif; ?>
                </div>
                <div class="col-md-6">
                    <p><strong>Mobile:</strong> <?= h($quotation['phone']) ?></p>
                    <?php if ($quotation['customer_gst']): ?>
                    <p><strong>GST Number:</strong> <?= h($quotation['customer_gst']) ?></p>
                    <?php endif; ?>
                </div>
            </div>
            <?php if ($quotation['notes']): ?>
            <div class="row mt-3">
                <div class="col-12">
                    <p><strong>Notes:</strong> <?= h($quotation['notes']) ?></p>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Tile Items -->
    <?php if (!empty($quotation_items)): ?>
    <div class="card mb-4">
        <div class="card-header bg-primary text-white">
            <h5 class="mb-0">Tile Items</h5>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-striped mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Item</th>
                            <th>Purpose</th>
                            <th>Calculation</th>
                            <th>Rate/Box</th>
                            <th>Amount</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($quotation_items as $item): ?>
                            <tr>
                                <td>
                                    <?php if ($item['show_image'] && $item['photo_path']): ?>
                                        <img src="<?= h($item['photo_path']) ?>" style="width: 40px; height: 40px; object-fit: cover; border-radius: 4px; margin-right: 8px;">
                                    <?php endif; ?>
                                    <strong><?= h($item['tile_name']) ?></strong><br>
                                    <small class="text-muted"><?= h($item['size_label']) ?></small>
                                </td>
                                <td><?= h($item['purpose']) ?></td>
                                <td>
                                    <?php if ($item['calculation_mode'] === 'sqft_mode'): ?>
                                        <small>
                                            Area: <?= number_format($item['total_sqft'], 1) ?> sq.ft<br>
                                            Boxes: <?= number_format($item['boxes_decimal'], 1) ?>
                                        </small>
                                    <?php else: ?>
                                        <small>
                                            Direct: <?= number_format($item['direct_boxes'], 1) ?> boxes
                                        </small>
                                    <?php endif; ?>
                                </td>
                                <td>₹<?= number_format($item['rate_per_box'], 2) ?></td>
                                <td class="text-end"><strong>₹<?= number_format($item['line_total'], 2) ?></strong></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Misc Items -->
    <?php if (!empty($quotation_misc_items)): ?>
    <div class="card mb-4">
        <div class="card-header bg-success text-white">
            <h5 class="mb-0">Other Items</h5>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-striped mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Item</th>
                            <th>Purpose</th>
                            <th>Quantity</th>
                            <th>Rate/Unit</th>
                            <th>Amount</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($quotation_misc_items as $item): ?>
                            <tr>
                                <td>
                                    <?php if ($item['show_image'] && $item['photo_path']): ?>
                                        <img src="<?= h($item['photo_path']) ?>" style="width: 40px; height: 40px; object-fit: cover; border-radius: 4px; margin-right: 8px;">
                                    <?php endif; ?>
                                    <strong><?= h($item['item_name']) ?></strong>
                                </td>
                                <td><?= h($item['purpose']) ?></td>
                                <td><?= number_format($item['qty_units'], 1) ?> <?= h($item['unit_label']) ?></td>
                                <td>₹<?= number_format($item['rate_per_unit'], 2) ?></td>
                                <td class="text-end"><strong>₹<?= number_format($item['line_total'], 2) ?></strong></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Total Summary -->
    <div class="row justify-content-end">
        <div class="col-md-4">
            <div class="card">
                <div class="card-body">
                    <div class="d-flex justify-content-between mb-2">
                        <span>Subtotal:</span>
                        <strong>₹<?= number_format($subtotal, 2) ?></strong>
                    </div>
                    <?php if ($discount_amount > 0): ?>
                    <div class="d-flex justify-content-between mb-2 text-success">
                        <span>Discount:</span>
                        <strong>-₹<?= number_format($discount_amount, 2) ?></strong>
                    </div>
                    <?php endif; ?>
                    <hr>
                    <div class="d-flex justify-content-between">
                        <span class="h5">Total:</span>
                        <strong class="h5 text-primary">₹<?= number_format($final_total, 2) ?></strong>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Footer -->
    <div class="mt-5 pt-3 border-top">
        <div class="row">
            <div class="col-md-6">
                <small class="text-muted">
                    Generated on <?= date('F j, Y g:i A') ?><br>
                    Valid for 30 days from quotation date
                </small>
            </div>
            <div class="col-md-6 text-end">
                <small class="text-muted">
                    Thank you for choosing us!<br>
                    For any queries, please contact us.
                </small>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>