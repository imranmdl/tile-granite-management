<?php
// public/report_pl_drilldown.php - P/L Drilldown Report (FR-RP-05)
require_once __DIR__ . '/../includes/simple_auth.php';
require_once __DIR__ . '/../includes/helpers.php';

auth_require_login();

$pdo = Database::pdo();
$user_id = $_SESSION['user_id'] ?? 1;

// Check permissions
$user_stmt = $pdo->prepare("SELECT * FROM users_simple WHERE id = ?");
$user_stmt->execute([$user_id]);
$user = $user_stmt->fetch(PDO::FETCH_ASSOC);

$can_view_reports = ($user['can_view_reports'] ?? 0) == 1 || ($user['role'] ?? '') === 'admin';
$can_view_pl = ($user['can_view_pl'] ?? 0) == 1 || ($user['role'] ?? '') === 'admin';

if (!$can_view_reports || !$can_view_pl) {
    $_SESSION['error'] = 'You do not have permission to view profit/loss data';
    safe_redirect('reports_dashboard.php');
}

// Handle filters
$date_from = $_GET['date_from'] ?? date('Y-m-01');
$date_to = $_GET['date_to'] ?? date('Y-m-d');
$invoice_id = $_GET['invoice_id'] ?? '';
$customer_filter = $_GET['customer_filter'] ?? '';
$view_type = $_GET['view_type'] ?? 'invoice'; // invoice, customer, timeline

// Get invoice-wise P/L data
$invoice_sql = "
    SELECT 
        i.id,
        i.invoice_no,
        i.invoice_dt,
        i.customer_name,
        i.firm_name,
        i.total as gross_total,
        i.final_total,
        COALESCE(i.discount_amount, 0) as discount_amount,
        
        -- Tiles P/L
        COALESCE(SUM(ii.line_total), 0) as tiles_revenue,
        COALESCE(SUM(ii.boxes_decimal * t.current_cost), 0) as tiles_cost,
        COALESCE(SUM(ii.line_total), 0) - COALESCE(SUM(ii.boxes_decimal * t.current_cost), 0) as tiles_profit,
        
        -- Misc items P/L
        COALESCE(SUM(imi.line_total), 0) as misc_revenue,
        COALESCE(SUM(imi.qty_units * m.current_cost), 0) as misc_cost,
        COALESCE(SUM(imi.line_total), 0) - COALESCE(SUM(imi.qty_units * m.current_cost), 0) as misc_profit,
        
        -- Total P/L
        (COALESCE(SUM(ii.line_total), 0) + COALESCE(SUM(imi.line_total), 0)) as total_revenue,
        (COALESCE(SUM(ii.boxes_decimal * t.current_cost), 0) + COALESCE(SUM(imi.qty_units * m.current_cost), 0)) as total_cost,
        
        -- Returns impact
        COALESCE(returns_data.total_returns, 0) as returns_amount,
        
        -- Commission
        COALESCE(i.commission_amount, 0) as commission_amount,
        u.username as commission_user
        
    FROM invoices i
    LEFT JOIN invoice_items ii ON i.id = ii.invoice_id
    LEFT JOIN tiles t ON ii.tile_id = t.id
    LEFT JOIN invoice_misc_items imi ON i.id = imi.invoice_id
    LEFT JOIN misc_items m ON imi.misc_item_id = m.id
    LEFT JOIN users_simple u ON i.commission_user_id = u.id
    LEFT JOIN (
        SELECT invoice_id, SUM(refund_amount) as total_returns
        FROM individual_returns
        GROUP BY invoice_id
    ) returns_data ON i.id = returns_data.invoice_id
    
    WHERE DATE(i.invoice_dt) BETWEEN ? AND ?
";

$params = [$date_from, $date_to];

if ($invoice_id) {
    $invoice_sql .= " AND i.id = ?";
    $params[] = $invoice_id;
}

if ($customer_filter) {
    $invoice_sql .= " AND (i.customer_name LIKE ? OR i.firm_name LIKE ?)";
    $params[] = "%$customer_filter%";
    $params[] = "%$customer_filter%";
}

$invoice_sql .= " GROUP BY i.id ORDER BY i.invoice_dt DESC, i.id DESC";

$stmt = $pdo->prepare($invoice_sql);
$stmt->execute($params);
$invoice_data = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Calculate final profit for each invoice
foreach ($invoice_data as &$invoice) {
    $gross_profit = ($invoice['tiles_profit'] ?? 0) + ($invoice['misc_profit'] ?? 0);
    $invoice['gross_profit'] = $gross_profit;
    $invoice['net_profit'] = $gross_profit - ($invoice['returns_amount'] ?? 0) - ($invoice['commission_amount'] ?? 0);
    $invoice['profit_margin'] = $invoice['total_revenue'] > 0 ? (($invoice['net_profit'] / $invoice['total_revenue']) * 100) : 0;
}

// Calculate summary
$summary = [
    'total_invoices' => count($invoice_data),
    'total_revenue' => array_sum(array_column($invoice_data, 'total_revenue')),
    'total_cost' => array_sum(array_column($invoice_data, 'total_cost')),
    'total_gross_profit' => array_sum(array_column($invoice_data, 'gross_profit')),
    'total_returns' => array_sum(array_column($invoice_data, 'returns_amount')),
    'total_commission' => array_sum(array_column($invoice_data, 'commission_amount')),
    'total_net_profit' => array_sum(array_column($invoice_data, 'net_profit'))
];

$summary['overall_margin'] = $summary['total_revenue'] > 0 ? (($summary['total_net_profit'] / $summary['total_revenue']) * 100) : 0;

// Get customer-wise summary if needed
$customer_summary = [];
if ($view_type === 'customer') {
    $customer_sql = "
        SELECT 
            customer_name,
            firm_name,
            COUNT(DISTINCT i.id) as invoice_count,
            SUM(i.final_total) as total_invoiced,
            AVG(i.final_total) as avg_invoice_value,
            SUM(COALESCE(ii.line_total, 0) + COALESCE(imi.line_total, 0)) as total_revenue,
            SUM(COALESCE(ii.boxes_decimal * t.current_cost, 0) + COALESCE(imi.qty_units * m.current_cost, 0)) as total_cost
        FROM invoices i
        LEFT JOIN invoice_items ii ON i.id = ii.invoice_id
        LEFT JOIN tiles t ON ii.tile_id = t.id
        LEFT JOIN invoice_misc_items imi ON i.id = imi.invoice_id
        LEFT JOIN misc_items m ON imi.misc_item_id = m.id
        WHERE DATE(i.invoice_dt) BETWEEN ? AND ?
        GROUP BY customer_name, firm_name
        ORDER BY total_revenue DESC
    ";
    
    $customer_stmt = $pdo->prepare($customer_sql);
    $customer_stmt->execute([$date_from, $date_to]);
    $customer_summary = $customer_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($customer_summary as &$customer) {
        $customer['profit'] = $customer['total_revenue'] - $customer['total_cost'];
        $customer['margin'] = $customer['total_revenue'] > 0 ? (($customer['profit'] / $customer['total_revenue']) * 100) : 0;
    }
}

$page_title = "P/L Drilldown Report";
require_once __DIR__ . '/../includes/header.php';
?>

<style>
.profit-positive { color: #28a745; font-weight: bold; }
.profit-negative { color: #dc3545; font-weight: bold; }
.margin-excellent { background-color: #d4edda; }
.margin-good { background-color: #d1ecf1; }
.margin-poor { background-color: #f8d7da; }
</style>

<div class="container-fluid mt-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h2><i class="bi bi-zoom-in"></i> P/L Drilldown Report</h2>
        <div>
            <a href="reports_dashboard.php" class="btn btn-outline-secondary">
                <i class="bi bi-arrow-left"></i> Back to Dashboard
            </a>
            <button onclick="window.print()" class="btn btn-primary">
                <i class="bi bi-printer"></i> Print Report
            </button>
        </div>
    </div>

    <!-- Summary Cards -->
    <div class="row mb-4">
        <div class="col-md-2">
            <div class="card bg-primary text-white">
                <div class="card-body text-center">
                    <h5><?= $summary['total_invoices'] ?></h5>
                    <small>Total Invoices</small>
                </div>
            </div>
        </div>
        <div class="col-md-2">
            <div class="card bg-info text-white">
                <div class="card-body text-center">
                    <h5>₹<?= number_format($summary['total_revenue'], 0) ?></h5>
                    <small>Total Revenue</small>
                </div>
            </div>
        </div>
        <div class="col-md-2">
            <div class="card bg-warning text-white">
                <div class="card-body text-center">
                    <h5>₹<?= number_format($summary['total_cost'], 0) ?></h5>
                    <small>Total Cost</small>
                </div>
            </div>
        </div>
        <div class="col-md-2">
            <div class="card bg-success text-white">
                <div class="card-body text-center">
                    <h5>₹<?= number_format($summary['total_gross_profit'], 0) ?></h5>
                    <small>Gross Profit</small>
                </div>
            </div>
        </div>
        <div class="col-md-2">
            <div class="card bg-danger text-white">
                <div class="card-body text-center">
                    <h5>₹<?= number_format($summary['total_returns'] + $summary['total_commission'], 0) ?></h5>
                    <small>Deductions</small>
                </div>
            </div>
        </div>
        <div class="col-md-2">
            <div class="card bg-<?= $summary['total_net_profit'] >= 0 ? 'dark' : 'danger' ?> text-white">
                <div class="card-body text-center">
                    <h5>₹<?= number_format($summary['total_net_profit'], 0) ?></h5>
                    <small>Net Profit</small>
                </div>
            </div>
        </div>
    </div>

    <!-- Filters -->
    <div class="card mb-4">
        <div class="card-body">
            <form method="GET" class="row g-3">
                <div class="col-md-2">
                    <label class="form-label">From Date</label>
                    <input type="date" class="form-control" name="date_from" value="<?= h($date_from) ?>">
                </div>
                <div class="col-md-2">
                    <label class="form-label">To Date</label>
                    <input type="date" class="form-control" name="date_to" value="<?= h($date_to) ?>">
                </div>
                <div class="col-md-2">
                    <label class="form-label">Invoice ID</label>
                    <input type="number" class="form-control" name="invoice_id" value="<?= h($invoice_id) ?>" placeholder="Specific invoice">
                </div>
                <div class="col-md-2">
                    <label class="form-label">Customer</label>
                    <input type="text" class="form-control" name="customer_filter" value="<?= h($customer_filter) ?>" placeholder="Customer name">
                </div>
                <div class="col-md-2">
                    <label class="form-label">View Type</label>
                    <select class="form-select" name="view_type">
                        <option value="invoice" <?= $view_type === 'invoice' ? 'selected' : '' ?>>Invoice-wise</option>
                        <option value="customer" <?= $view_type === 'customer' ? 'selected' : '' ?>>Customer-wise</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label">&nbsp;</label>
                    <button type="submit" class="btn btn-primary d-block">
                        <i class="bi bi-funnel"></i> Apply Filters
                    </button>
                </div>
            </form>
        </div>
    </div>

    <?php if ($view_type === 'customer'): ?>
        <!-- Customer-wise P/L -->
        <div class="card">
            <div class="card-header">
                <h5><i class="bi bi-people"></i> Customer-wise P/L Analysis</h5>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-hover">
                        <thead class="table-dark">
                            <tr>
                                <th>Customer</th>
                                <th>Firm</th>
                                <th>Invoices</th>
                                <th>Total Invoiced</th>
                                <th>Avg Invoice</th>
                                <th>Revenue</th>
                                <th>Cost</th>
                                <th>Profit</th>
                                <th>Margin %</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($customer_summary as $customer): ?>
                                <tr class="<?= $customer['margin'] >= 20 ? 'margin-good' : ($customer['margin'] < 0 ? 'margin-poor' : '') ?>">
                                    <td><strong><?= h($customer['customer_name']) ?></strong></td>
                                    <td><?= h($customer['firm_name'] ?? '-') ?></td>
                                    <td><?= $customer['invoice_count'] ?></td>
                                    <td>₹<?= number_format($customer['total_invoiced'], 2) ?></td>
                                    <td>₹<?= number_format($customer['avg_invoice_value'], 2) ?></td>
                                    <td>₹<?= number_format($customer['total_revenue'], 2) ?></td>
                                    <td>₹<?= number_format($customer['total_cost'], 2) ?></td>
                                    <td class="<?= $customer['profit'] >= 0 ? 'profit-positive' : 'profit-negative' ?>">
                                        ₹<?= number_format($customer['profit'], 2) ?>
                                    </td>
                                    <td class="<?= $customer['margin'] >= 0 ? 'profit-positive' : 'profit-negative' ?>">
                                        <?= number_format($customer['margin'], 1) ?>%
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    <?php else: ?>
        <!-- Invoice-wise P/L -->
        <div class="card">
            <div class="card-header">
                <h5><i class="bi bi-receipt"></i> Invoice-wise P/L Breakdown</h5>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-hover table-sm">
                        <thead class="table-dark">
                            <tr>
                                <th>Invoice #</th>
                                <th>Date</th>
                                <th>Customer</th>
                                <th>Revenue</th>
                                <th>Cost</th>
                                <th>Gross Profit</th>
                                <th>Returns</th>
                                <th>Commission</th>
                                <th>Net Profit</th>
                                <th>Margin %</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($invoice_data)): ?>
                                <tr>
                                    <td colspan="11" class="text-center text-muted">No invoices found</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($invoice_data as $invoice): ?>
                                    <tr class="<?= $invoice['profit_margin'] >= 20 ? 'margin-good' : ($invoice['profit_margin'] < 0 ? 'margin-poor' : '') ?>">
                                        <td>
                                            <strong><?= h($invoice['invoice_no']) ?></strong>
                                            <br><small class="text-muted">ID: <?= $invoice['id'] ?></small>
                                        </td>
                                        <td><?= date('M j, Y', strtotime($invoice['invoice_dt'])) ?></td>
                                        <td>
                                            <strong><?= h($invoice['customer_name']) ?></strong>
                                            <?php if ($invoice['firm_name']): ?>
                                                <br><small class="text-muted"><?= h($invoice['firm_name']) ?></small>
                                            <?php endif; ?>
                                        </td>
                                        <td>₹<?= number_format($invoice['total_revenue'], 2) ?></td>
                                        <td>₹<?= number_format($invoice['total_cost'], 2) ?></td>
                                        <td class="<?= $invoice['gross_profit'] >= 0 ? 'profit-positive' : 'profit-negative' ?>">
                                            ₹<?= number_format($invoice['gross_profit'], 2) ?>
                                        </td>
                                        <td class="text-danger">₹<?= number_format($invoice['returns_amount'], 2) ?></td>
                                        <td class="text-warning">
                                            ₹<?= number_format($invoice['commission_amount'], 2) ?>
                                            <?php if ($invoice['commission_user']): ?>
                                                <br><small><?= h($invoice['commission_user']) ?></small>
                                            <?php endif; ?>
                                        </td>
                                        <td class="<?= $invoice['net_profit'] >= 0 ? 'profit-positive' : 'profit-negative' ?>">
                                            ₹<?= number_format($invoice['net_profit'], 2) ?>
                                        </td>
                                        <td class="<?= $invoice['profit_margin'] >= 0 ? 'profit-positive' : 'profit-negative' ?>">
                                            <?= number_format($invoice['profit_margin'], 1) ?>%
                                        </td>
                                        <td>
                                            <a href="invoice_enhanced.php?id=<?= $invoice['id'] ?>" class="btn btn-outline-primary btn-sm">
                                                <i class="bi bi-eye"></i> View
                                            </a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <!-- P/L Breakdown Chart -->
    <div class="card mt-4">
        <div class="card-header">
            <h5><i class="bi bi-pie-chart"></i> P/L Breakdown</h5>
        </div>
        <div class="card-body">
            <div class="row">
                <div class="col-md-6">
                    <canvas id="plChart" style="height: 300px;"></canvas>
                </div>
                <div class="col-md-6">
                    <h6>Overall Metrics</h6>
                    <div class="row">
                        <div class="col-6">
                            <strong>Overall Margin:</strong><br>
                            <span class="fs-4 <?= $summary['overall_margin'] >= 0 ? 'text-success' : 'text-danger' ?>">
                                <?= number_format($summary['overall_margin'], 1) ?>%
                            </span>
                        </div>
                        <div class="col-6">
                            <strong>Avg per Invoice:</strong><br>
                            <span class="fs-5">₹<?= $summary['total_invoices'] > 0 ? number_format($summary['total_net_profit'] / $summary['total_invoices'], 0) : 0 ?></span>
                        </div>
                    </div>
                    <hr>
                    <h6>Cost Breakdown</h6>
                    <div class="progress mb-2">
                        <div class="progress-bar bg-success" style="width: <?= $summary['total_revenue'] > 0 ? ($summary['total_net_profit'] / $summary['total_revenue']) * 100 : 0 ?>%">
                            Profit
                        </div>
                        <div class="progress-bar bg-warning" style="width: <?= $summary['total_revenue'] > 0 ? ($summary['total_cost'] / $summary['total_revenue']) * 100 : 0 ?>%">
                            Cost
                        </div>
                        <div class="progress-bar bg-danger" style="width: <?= $summary['total_revenue'] > 0 ? (($summary['total_returns'] + $summary['total_commission']) / $summary['total_revenue']) * 100 : 0 ?>%">
                            Deductions
                        </div>
                    </div>
                    <small class="text-muted">
                        Cost: <?= $summary['total_revenue'] > 0 ? number_format(($summary['total_cost'] / $summary['total_revenue']) * 100, 1) : 0 ?>% |
                        Deductions: <?= $summary['total_revenue'] > 0 ? number_format((($summary['total_returns'] + $summary['total_commission']) / $summary['total_revenue']) * 100, 1) : 0 ?>%
                    </small>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Chart.js -->
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
const ctx = document.getElementById('plChart').getContext('2d');

new Chart(ctx, {
    type: 'doughnut',
    data: {
        labels: ['Net Profit', 'Cost', 'Returns', 'Commission'],
        datasets: [{
            data: [
                <?= $summary['total_net_profit'] ?>,
                <?= $summary['total_cost'] ?>,
                <?= $summary['total_returns'] ?>,
                <?= $summary['total_commission'] ?>
            ],
            backgroundColor: [
                '#28a745',
                '#ffc107', 
                '#dc3545',
                '#fd7e14'
            ]
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
            tooltip: {
                callbacks: {
                    label: function(context) {
                        const total = context.dataset.data.reduce((a, b) => a + b, 0);
                        const percentage = ((context.parsed / total) * 100).toFixed(1);
                        return context.label + ': ₹' + context.parsed.toLocaleString() + ' (' + percentage + '%)';
                    }
                }
            }
        }
    }
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>