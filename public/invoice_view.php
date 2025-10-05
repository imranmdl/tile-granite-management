<?php
// public/invoice_view.php - Invoice View and Print Page
require_once __DIR__ . '/../includes/simple_auth.php';
require_once __DIR__ . '/../includes/helpers.php';

auth_require_login();

$pdo = Database::pdo();
$id = (int)($_GET['id'] ?? 0);

if (!$id) {
    safe_redirect('invoice_enhanced.php');
}

// Get invoice data
$stmt = $pdo->prepare("SELECT * FROM invoices WHERE id = ?");
$stmt->execute([$id]);
$invoice = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$invoice) {
    safe_redirect('invoice_enhanced.php');
}

// Get tile items
$items_stmt = $pdo->prepare("
    SELECT ii.*, t.name as tile_name, ts.label as size_label, ts.sqft_per_box, t.photo_path
    FROM invoice_items ii
    JOIN tiles t ON ii.tile_id = t.id
    JOIN tile_sizes ts ON t.size_id = ts.id
    WHERE ii.invoice_id = ?
    ORDER BY ii.id
");
$items_stmt->execute([$id]);
$invoice_items = $items_stmt->fetchAll(PDO::FETCH_ASSOC);

// Get misc items
$misc_stmt = $pdo->prepare("
    SELECT imi.*, m.name as item_name, m.unit_label, m.photo_path
    FROM invoice_misc_items imi
    JOIN misc_items m ON imi.misc_item_id = m.id
    WHERE imi.invoice_id = ?
    ORDER BY imi.id
");
$misc_stmt->execute([$id]);
$invoice_misc_items = $misc_stmt->fetchAll(PDO::FETCH_ASSOC);

$page_title = "Invoice: " . $invoice['invoice_no'];
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= h($page_title) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.7.2/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        @media print {
            .no-print { display: none !important; }
            .print-only { display: block !important; }
        }
        .print-only { display: none; }
        
        .invoice-header {
            border-bottom: 3px solid #007bff;
            padding-bottom: 20px;
            margin-bottom: 30px;
        }
        
        .company-info {
            text-align: right;
        }
        
        .invoice-details {
            background: #f8f9fa;
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
        }
        
        .items-table th {
            background: #007bff;
            color: white;
        }
        
        .total-section {
            background: #e9ecef;
            padding: 15px;
            border-radius: 8px;
            margin-top: 20px;
        }
    </style>
</head>
<body>
    <div class="container mt-4">
        <!-- Print Controls -->
        <div class="no-print mb-3">
            <button onclick="window.print()" class="btn btn-primary">
                <i class="bi bi-printer"></i> Print Invoice
            </button>
            <a href="invoice_enhanced.php?id=<?= $id ?>" class="btn btn-secondary">
                <i class="bi bi-arrow-left"></i> Back to Edit
            </a>
        </div>

        <!-- Invoice Content -->
        <div class="invoice-content">
            <!-- Header -->
            <div class="invoice-header row">
                <div class="col-md-6">
                    <h1 class="text-primary">INVOICE</h1>
                    <h3><?= h($invoice['invoice_no']) ?></h3>
                </div>
                <div class="col-md-6 company-info">
                    <h4>Tile Suite</h4>
                    <p>Business Management System<br>
                    Your Address Here<br>
                    Phone: Your Phone Here<br>
                    Email: your@email.com</p>
                </div>
            </div>

            <!-- Invoice Details -->
            <div class="row">
                <div class="col-md-6">
                    <div class="invoice-details">
                        <h5>Bill To:</h5>
                        <p><strong><?= h($invoice['customer_name']) ?></strong><br>
                        <?php if ($invoice['firm_name']): ?>
                            <?= h($invoice['firm_name']) ?><br>
                        <?php endif; ?>
                        Phone: <?= h($invoice['phone']) ?><br>
                        <?php if ($invoice['customer_gst']): ?>
                            GST: <?= h($invoice['customer_gst']) ?><br>
                        <?php endif; ?>
                        </p>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="invoice-details">
                        <h5>Invoice Details:</h5>
                        <p><strong>Invoice Date:</strong> <?= date('M j, Y', strtotime($invoice['invoice_dt'])) ?><br>
                        <strong>Status:</strong> 
                        <span class="badge bg-<?= $invoice['status'] === 'paid' ? 'success' : ($invoice['status'] === 'partial' ? 'warning' : 'secondary') ?>">
                            <?= ucfirst($invoice['status']) ?>
                        </span><br>
                        <?php if ($invoice['notes']): ?>
                            <strong>Notes:</strong> <?= h($invoice['notes']) ?>
                        <?php endif; ?>
                        </p>
                    </div>
                </div>
            </div>

            <!-- Items Table -->
            <div class="row">
                <div class="col-12">
                    <table class="table table-bordered items-table">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>Item Description</th>
                                <th>Quantity</th>
                                <th>Rate</th>
                                <th>Amount</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php $line_num = 1; ?>
                            <?php foreach ($invoice_items as $item): ?>
                                <tr>
                                    <td><?= $line_num++ ?></td>
                                    <td>
                                        <strong><?= h($item['tile_name']) ?></strong> (<?= h($item['size_label']) ?>)
                                        <?php if ($item['purpose']): ?>
                                            <br><small class="text-muted">Purpose: <?= h($item['purpose']) ?></small>
                                        <?php endif; ?>
                                        <?php if ($item['calculation_mode'] === 'sqft_mode'): ?>
                                            <br><small class="text-muted">Area: <?= number_format($item['total_sqft'], 1) ?> sq.ft</small>
                                        <?php endif; ?>
                                    </td>
                                    <td><?= number_format($item['boxes_decimal'], 1) ?> boxes</td>
                                    <td>₹<?= number_format($item['rate_per_box'], 2) ?></td>
                                    <td>₹<?= number_format($item['line_total'], 2) ?></td>
                                </tr>
                            <?php endforeach; ?>
                            
                            <?php foreach ($invoice_misc_items as $item): ?>
                                <tr>
                                    <td><?= $line_num++ ?></td>
                                    <td>
                                        <strong><?= h($item['item_name']) ?></strong>
                                        <?php if ($item['purpose']): ?>
                                            <br><small class="text-muted">Purpose: <?= h($item['purpose']) ?></small>
                                        <?php endif; ?>
                                    </td>
                                    <td><?= number_format($item['qty_units'], 1) ?> <?= h($item['unit_label']) ?></td>
                                    <td>₹<?= number_format($item['rate_per_unit'], 2) ?></td>
                                    <td>₹<?= number_format($item['line_total'], 2) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Totals -->
            <div class="row">
                <div class="col-md-8"></div>
                <div class="col-md-4">
                    <div class="total-section">
                        <div class="d-flex justify-content-between">
                            <strong>Subtotal:</strong>
                            <span>₹<?= number_format($invoice['total'], 2) ?></span>
                        </div>
                        
                        <?php if (($invoice['discount_amount'] ?? 0) > 0): ?>
                        <div class="d-flex justify-content-between">
                            <strong>Discount:</strong>
                            <span class="text-danger">-₹<?= number_format($invoice['discount_amount'] ?? 0, 2) ?></span>
                        </div>
                        <?php endif; ?>
                        
                        <hr>
                        <div class="d-flex justify-content-between">
                            <h5><strong>Total Amount:</strong></h5>
                            <h5><strong>₹<?= number_format($invoice['final_total'] ?? $invoice['total'] ?? 0, 2) ?></strong></h5>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Footer -->
            <div class="row mt-5">
                <div class="col-12">
                    <hr>
                    <p class="text-center text-muted">
                        Thank you for your business!<br>
                        <small>This is a computer-generated invoice.</small>
                    </p>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>