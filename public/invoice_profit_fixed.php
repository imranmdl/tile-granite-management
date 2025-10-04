<?php
// public/invoice_profit_fixed.php - Invoice Profit Report (Fixed for Latest DB Schema)
require_once __DIR__ . '/../includes/simple_auth.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/admin_functions.php';

auth_require_login();

$pdo = Database::pdo();

// Date range handling
$date_from = $_GET['date_from'] ?? date('Y-m-01');
$date_to = $_GET['date_to'] ?? date('Y-m-d');
$customer_filter = trim($_GET['customer'] ?? '');

// Build invoice profit analysis query
$invoice_sql = "
    SELECT 
        i.id,
        i.invoice_no,
        DATE(i.invoice_dt) as invoice_date,
        i.customer_name,
        i.firm_name,
        i.phone,
        i.subtotal,
        i.discount_amount,
        i.final_total,
        i.commission_amount,
        i.status,
        (
            SELECT SUM(ii.boxes_decimal * ii.rate_per_box)
            FROM invoice_items ii
            WHERE ii.invoice_id = i.id
        ) as tiles_revenue,
        (
            SELECT SUM(imi.qty_units * imi.rate_per_unit)
            FROM invoice_misc_items imi
            WHERE imi.invoice_id = i.id
        ) as misc_revenue,
        (
            SELECT SUM(ii.boxes_decimal * t.current_cost)
            FROM invoice_items ii
            JOIN tiles t ON ii.tile_id = t.id
            WHERE ii.invoice_id = i.id
        ) as tiles_cost,
        (
            SELECT SUM(imi.qty_units * m.current_cost)
            FROM invoice_misc_items imi
            JOIN misc_items m ON imi.misc_item_id = m.id
            WHERE imi.invoice_id = i.id
        ) as misc_cost,
        (
            SELECT SUM(ir.refund_amount)
            FROM individual_returns ir
            WHERE ir.invoice_id = i.id
        ) as total_returns
    FROM invoices i
    WHERE DATE(i.invoice_dt) BETWEEN ? AND ?
    AND i.status != 'CANCELLED'
";

$params = [$date_from, $date_to];

if ($customer_filter) {
    $invoice_sql .= " AND (i.customer_name LIKE ? OR i.firm_name LIKE ?)";
    $params[] = "%$customer_filter%";
    $params[] = "%$customer_filter%";
}

$invoice_sql .= " ORDER BY i.invoice_dt DESC";

$invoice_stmt = $pdo->prepare($invoice_sql);
$invoice_stmt->execute($params);
$invoice_data = $invoice_stmt->fetchAll(PDO::FETCH_ASSOC);

// Process and calculate profit metrics
$processed_data = [];
$summary = [
    'total_invoices' => count($invoice_data),
    'total_revenue' => 0,
    'total_cost' => 0,
    'total_gross_profit' => 0,
    'total_commission' => 0,
    'total_returns' => 0,
    'total_net_profit' => 0,
    'avg_profit_margin' => 0,
    'positive_profit_count' => 0,
    'negative_profit_count' => 0
];

foreach ($invoice_data as $invoice) {
    $tiles_revenue = $invoice['tiles_revenue'] ?? 0;
    $misc_revenue = $invoice['misc_revenue'] ?? 0;
    $tiles_cost = $invoice['tiles_cost'] ?? 0;
    $misc_cost = $invoice['misc_cost'] ?? 0;
    
    $total_revenue = $tiles_revenue + $misc_revenue;
    $total_cost = $tiles_cost + $misc_cost;
    $gross_profit = $total_revenue - $total_cost;
    $commission = $invoice['commission_amount'] ?? 0;
    $returns = $invoice['total_returns'] ?? 0;
    $net_profit = $gross_profit - $commission - $returns;
    $profit_margin = $invoice['final_total'] > 0 ? ($net_profit / $invoice['final_total'] * 100) : 0;
    
    $processed_invoice = array_merge($invoice, [
        'total_revenue' => $total_revenue,
        'total_cost' => $total_cost,
        'gross_profit' => $gross_profit,
        'net_profit' => $net_profit,
        'profit_margin' => $profit_margin,
        'returns' => $returns
    ]);
    
    $processed_data[] = $processed_invoice;
    
    // Update summary
    $summary['total_revenue'] += $invoice['final_total'];
    $summary['total_cost'] += $total_cost;
    $summary['total_gross_profit'] += $gross_profit;
    $summary['total_commission'] += $commission;
    $summary['total_returns'] += $returns;
    $summary['total_net_profit'] += $net_profit;
    
    if ($net_profit > 0) {
        $summary['positive_profit_count']++;
    } elseif ($net_profit < 0) {
        $summary['negative_profit_count']++;
    }
}

$summary['avg_profit_margin'] = $summary['total_revenue'] > 0 ? 
    ($summary['total_net_profit'] / $summary['total_revenue'] * 100) : 0;

// Get all customers for filter
$customers_sql = "
    SELECT DISTINCT customer_name
    FROM invoices 
    WHERE customer_name IS NOT NULL AND customer_name != ''
    AND status != 'CANCELLED'
    ORDER BY customer_name
";
$all_customers = $pdo->query($customers_sql)->fetchAll(PDO::FETCH_COLUMN);

$page_title = "Invoice Profit Analysis";
require_once __DIR__ . '/../includes/header.php';
?>

<style>
.profit-positive { color: #28a745; }
.profit-negative { color: #dc3545; }
.invoice-profitable { background-color: rgba(40, 167, 69, 0.1); }
.invoice-loss { background-color: rgba(220, 53, 69, 0.1); }
.summary-card {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
    border-radius: 15px;
    padding: 20px;
    margin-bottom: 20px;
}
.metric-card {
    background: rgba(255,255,255,0.1);
    border-radius: 10px;
    padding: 15px;
    text-align: center;
    margin-bottom: 15px;
}
</style>

<div class="container-fluid mt-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h2><i class="bi bi-receipt text-success"></i> Invoice Profit Analysis</h2>
            <p class="text-muted mb-0">
                Period: <?= date('M j, Y', strtotime($date_from)) ?> to <?= date('M j, Y', strtotime($date_to)) ?>
                <?= $customer_filter ? " | Customer: $customer_filter" : "" ?>
            </p>
        </div>
        <a href="reports_dashboard_new.php" class="btn btn-outline-secondary">
            <i class="bi bi-arrow-left"></i> Back to Reports
        </a>
    </div>

    <!-- Filters -->
    <div class="card mb-4">
        <div class="card-body">
            <form method="GET" class="row g-3">
                <div class="col-md-3">
                    <label class="form-label">From Date</label>
                    <input type="date" class="form-control" name="date_from" value="<?= h($date_from) ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label">To Date</label>
                    <input type="date" class="form-control" name="date_to" value="<?= h($date_to) ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label">Customer</label>
                    <select class="form-select" name="customer">
                        <option value="">All Customers</option>
                        <?php foreach ($all_customers as $customer): ?>
                            <option value="<?= h($customer) ?>" <?= $customer_filter === $customer ? 'selected' : '' ?>>
                                <?= h($customer) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3 d-flex align-items-end">
                    <button type="submit" class="btn btn-primary me-2">
                        <i class="bi bi-search"></i> Generate Report
                    </button>
                    <a href="?export=excel&<?= http_build_query($_GET) ?>" class="btn btn-success">
                        <i class="bi bi-file-excel"></i> Export
                    </a>
                </div>
            </form>
        </div>
    </div>

    <!-- Summary Statistics -->
    <div class="row mb-4">
        <div class="col-md-2">
            <div class="metric-card">
                <h6>Total Invoices</h6>
                <h3><?= $summary['total_invoices'] ?></h3>
                <small>In period</small>
            </div>
        </div>
        <div class="col-md-2">
            <div class="metric-card">
                <h6>Total Revenue</h6>
                <h3>₹<?= number_format($summary['total_revenue'], 0) ?></h3>
                <small>Final invoice value</small>
            </div>
        </div>
        <div class="col-md-2">
            <div class="metric-card">
                <h6>Total Cost</h6>
                <h3>₹<?= number_format($summary['total_cost'], 0) ?></h3>
                <small>COGS</small>
            </div>
        </div>
        <div class="col-md-2">
            <div class="metric-card">
                <h6>Gross Profit</h6>
                <h3 class="<?= $summary['total_gross_profit'] >= 0 ? 'profit-positive' : 'profit-negative' ?>">
                    ₹<?= number_format($summary['total_gross_profit'], 0) ?>
                </h3>
                <small>Before expenses</small>
            </div>
        </div>
        <div class="col-md-2">
            <div class="metric-card">
                <h6>Expenses</h6>
                <h3>₹<?= number_format($summary['total_commission'] + $summary['total_returns'], 0) ?></h3>
                <small>Commission + Returns</small>
            </div>
        </div>
        <div class="col-md-2">
            <div class="metric-card">
                <h6>Net Profit</h6>
                <h3 class="<?= $summary['total_net_profit'] >= 0 ? 'profit-positive' : 'profit-negative' ?>">
                    ₹<?= number_format($summary['total_net_profit'], 0) ?>
                </h3>
                <small><?= number_format($summary['avg_profit_margin'], 1) ?>% margin</small>
            </div>
        </div>
    </div>

    <!-- Profit Distribution -->
    <div class="row mb-4">
        <div class="col-md-4">
            <div class="card text-center border-success">
                <div class="card-body">
                    <h5 class="card-title text-success">Profitable Invoices</h5>
                    <h3><?= $summary['positive_profit_count'] ?></h3>
                    <small class="text-muted">
                        <?= $summary['total_invoices'] > 0 ? number_format(($summary['positive_profit_count'] / $summary['total_invoices']) * 100, 1) : 0 ?>% of total
                    </small>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card text-center border-danger">
                <div class="card-body">
                    <h5 class="card-title text-danger">Loss-Making Invoices</h5>
                    <h3><?= $summary['negative_profit_count'] ?></h3>
                    <small class="text-muted">
                        <?= $summary['total_invoices'] > 0 ? number_format(($summary['negative_profit_count'] / $summary['total_invoices']) * 100, 1) : 0 ?>% of total
                    </small>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card text-center border-secondary">
                <div class="card-body">
                    <h5 class="card-title text-secondary">Break-even Invoices</h5>
                    <h3><?= $summary['total_invoices'] - $summary['positive_profit_count'] - $summary['negative_profit_count'] ?></h3>
                    <small class="text-muted">Zero profit/loss</small>
                </div>
            </div>
        </div>
    </div>

    <!-- Invoice Details -->
    <div class="card">
        <div class="card-header">
            <h5><i class="bi bi-list-ul"></i> Invoice Profit Details</h5>
        </div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-hover table-sm">
                    <thead class="table-light">
                        <tr>
                            <th>Date</th>
                            <th>Invoice #</th>
                            <th>Customer</th>
                            <th>Revenue</th>
                            <th>Cost</th>
                            <th>Gross Profit</th>
                            <th>Commission</th>
                            <th>Returns</th>
                            <th>Net Profit</th>
                            <th>Margin %</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($processed_data)): ?>
                            <tr>
                                <td colspan="11" class="text-center text-muted py-4">
                                    <i class="bi bi-info-circle"></i> No invoice data found for the selected period
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($processed_data as $invoice): ?>
                                <?php 
                                $profit_class = $invoice['net_profit'] > 0 ? 'invoice-profitable' : 
                                               ($invoice['net_profit'] < 0 ? 'invoice-loss' : '');
                                ?>
                                <tr class="<?= $profit_class ?>">
                                    <td><?= date('M j, Y', strtotime($invoice['invoice_date'])) ?></td>
                                    <td>
                                        <a href="invoice_view.php?id=<?= $invoice['id'] ?>" class="text-decoration-none">
                                            <?= h($invoice['invoice_no']) ?>
                                        </a>
                                    </td>
                                    <td>
                                        <strong><?= h($invoice['customer_name']) ?></strong>
                                        <?= $invoice['firm_name'] ? '<br><small class="text-muted">' . h($invoice['firm_name']) . '</small>' : '' ?>
                                    </td>
                                    <td>₹<?= number_format($invoice['final_total'], 2) ?></td>
                                    <td>₹<?= number_format($invoice['total_cost'], 2) ?></td>
                                    <td class="<?= $invoice['gross_profit'] >= 0 ? 'profit-positive' : 'profit-negative' ?>">
                                        ₹<?= number_format($invoice['gross_profit'], 2) ?>
                                    </td>
                                    <td>₹<?= number_format($invoice['commission_amount'] ?? 0, 2) ?></td>
                                    <td class="text-danger">₹<?= number_format($invoice['returns'], 2) ?></td>
                                    <td class="<?= $invoice['net_profit'] >= 0 ? 'profit-positive' : 'profit-negative' ?>">
                                        <strong>₹<?= number_format($invoice['net_profit'], 2) ?></strong>
                                    </td>
                                    <td class="<?= $invoice['profit_margin'] >= 0 ? 'profit-positive' : 'profit-negative' ?>">
                                        <?= number_format($invoice['profit_margin'], 1) ?>%
                                    </td>
                                    <td>
                                        <span class="badge bg-<?= $invoice['status'] === 'FINALIZED' ? 'success' : 'secondary' ?>">
                                            <?= h($invoice['status']) ?>
                                        </span>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                    <?php if (!empty($processed_data)): ?>
                        <tfoot class="table-secondary">
                            <tr>
                                <th colspan="3">TOTALS</th>
                                <th>₹<?= number_format($summary['total_revenue'], 2) ?></th>
                                <th>₹<?= number_format($summary['total_cost'], 2) ?></th>
                                <th class="<?= $summary['total_gross_profit'] >= 0 ? 'profit-positive' : 'profit-negative' ?>">
                                    ₹<?= number_format($summary['total_gross_profit'], 2) ?>
                                </th>
                                <th>₹<?= number_format($summary['total_commission'], 2) ?></th>
                                <th class="text-danger">₹<?= number_format($summary['total_returns'], 2) ?></th>
                                <th class="<?= $summary['total_net_profit'] >= 0 ? 'profit-positive' : 'profit-negative' ?>">
                                    <strong>₹<?= number_format($summary['total_net_profit'], 2) ?></strong>
                                </th>
                                <th class="<?= $summary['avg_profit_margin'] >= 0 ? 'profit-positive' : 'profit-negative' ?>">
                                    <strong><?= number_format($summary['avg_profit_margin'], 1) ?>%</strong>
                                </th>
                                <th>-</th>
                            </tr>
                        </tfoot>
                    <?php endif; ?>
                </table>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>