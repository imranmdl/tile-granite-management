<?php
// public/report_daily_pl.php - Daily Profit & Loss Report
require_once __DIR__ . '/../includes/simple_auth.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/admin_functions.php';

auth_require_login();

$pdo = Database::pdo();
$user_id = $_SESSION['user_id'] ?? 1;

// Check permissions
$user_stmt = $pdo->prepare("SELECT * FROM users_simple WHERE id = ?");
$user_stmt->execute([$user_id]);
$user = $user_stmt->fetch(PDO::FETCH_ASSOC);

$can_view_pl = ($user['can_view_pl'] ?? 0) == 1;
if (!$can_view_pl && ($user['role'] ?? '') !== 'admin') {
    $_SESSION['error'] = 'You do not have permission to view P&L reports';
    header('Location: /reports_dashboard_new.php');
    exit;
}

// Date range handling
$preset = $_GET['preset'] ?? '';
$date_from = $_GET['date_from'] ?? '';
$date_to = $_GET['date_to'] ?? '';

// Handle presets
switch ($preset) {
    case 'today':
        $date_from = $date_to = date('Y-m-d');
        break;
    case 'yesterday':
        $date_from = $date_to = date('Y-m-d', strtotime('-1 day'));
        break;
    case 'this_week':
        $date_from = date('Y-m-d', strtotime('monday this week'));
        $date_to = date('Y-m-d');
        break;
    case 'last_week':
        $date_from = date('Y-m-d', strtotime('monday last week'));
        $date_to = date('Y-m-d', strtotime('sunday last week'));
        break;
    case 'this_month':
        $date_from = date('Y-m-01');
        $date_to = date('Y-m-d');
        break;
    default:
        if (!$date_from) $date_from = date('Y-m-d');
        if (!$date_to) $date_to = date('Y-m-d');
        break;
}

// Calculate P&L for the selected period
$pl_data = [];

// Revenue calculation
$revenue_sql = "
    SELECT 
        DATE(i.invoice_dt) as sale_date,
        COUNT(i.id) as order_count,
        SUM(i.final_total) as total_revenue,
        SUM(i.discount_amount) as total_discounts,
        SUM(i.subtotal) as gross_revenue
    FROM invoices i 
    WHERE DATE(i.invoice_dt) BETWEEN ? AND ? 
    AND i.status != 'CANCELLED'
    GROUP BY DATE(i.invoice_dt)
    ORDER BY DATE(i.invoice_dt)
";

$revenue_stmt = $pdo->prepare($revenue_sql);
$revenue_stmt->execute([$date_from, $date_to]);
$daily_revenue = $revenue_stmt->fetchAll(PDO::FETCH_ASSOC);

// Cost calculation - tiles
$tile_cost_sql = "
    SELECT 
        DATE(i.invoice_dt) as sale_date,
        SUM(ii.boxes_decimal * t.as_of_cost_per_box) as tile_cost
    FROM invoices i
    JOIN invoice_items ii ON i.id = ii.invoice_id
    JOIN tiles t ON ii.tile_id = t.id
    WHERE DATE(i.invoice_dt) BETWEEN ? AND ?
    AND i.status != 'CANCELLED'
    GROUP BY DATE(i.invoice_dt)
";

$tile_cost_stmt = $pdo->prepare($tile_cost_sql);
$tile_cost_stmt->execute([$date_from, $date_to]);
$daily_tile_costs = [];
while ($row = $tile_cost_stmt->fetch(PDO::FETCH_ASSOC)) {
    $daily_tile_costs[$row['sale_date']] = $row['tile_cost'];
}

// Cost calculation - misc items
$misc_cost_sql = "
    SELECT 
        DATE(i.invoice_dt) as sale_date,
        SUM(imi.quantity * m.current_cost) as misc_cost
    FROM invoices i
    JOIN invoice_misc_items imi ON i.id = imi.invoice_id
    JOIN misc_items m ON imi.misc_item_id = m.id
    WHERE DATE(i.invoice_dt) BETWEEN ? AND ?
    AND i.status != 'CANCELLED'
    GROUP BY DATE(i.invoice_dt)
";

$misc_cost_stmt = $pdo->prepare($misc_cost_sql);
$misc_cost_stmt->execute([$date_from, $date_to]);
$daily_misc_costs = [];
while ($row = $misc_cost_stmt->fetch(PDO::FETCH_ASSOC)) {
    $daily_misc_costs[$row['sale_date']] = $row['misc_cost'];
}

// Commission calculation
$commission_sql = "
    SELECT 
        DATE(i.invoice_dt) as sale_date,
        SUM(i.commission_amount) as total_commission
    FROM invoices i
    WHERE DATE(i.invoice_dt) BETWEEN ? AND ?
    AND i.status != 'CANCELLED'
    GROUP BY DATE(i.invoice_dt)
";

$commission_stmt = $pdo->prepare($commission_sql);
$commission_stmt->execute([$date_from, $date_to]);
$daily_commissions = [];
while ($row = $commission_stmt->fetch(PDO::FETCH_ASSOC)) {
    $daily_commissions[$row['sale_date']] = $row['total_commission'];
}

// Returns/refunds calculation
$returns_sql = "
    SELECT 
        DATE(ir.return_date) as return_date,
        SUM(ir.refund_amount) as total_refunds
    FROM individual_returns ir
    WHERE DATE(ir.return_date) BETWEEN ? AND ?
    GROUP BY DATE(ir.return_date)
";

$returns_stmt = $pdo->prepare($returns_sql);
$returns_stmt->execute([$date_from, $date_to]);
$daily_returns = [];
while ($row = $returns_stmt->fetch(PDO::FETCH_ASSOC)) {
    $daily_returns[$row['return_date']] = $row['total_refunds'];
}

// Combine all data
$combined_data = [];
$totals = [
    'revenue' => 0,
    'cost' => 0,
    'profit' => 0,
    'commission' => 0,
    'returns' => 0,
    'net_profit' => 0,
    'orders' => 0
];

foreach ($daily_revenue as $day) {
    $date = $day['sale_date'];
    $tile_cost = $daily_tile_costs[$date] ?? 0;
    $misc_cost = $daily_misc_costs[$date] ?? 0;
    $total_cost = $tile_cost + $misc_cost;
    $commission = $daily_commissions[$date] ?? 0;
    $returns = $daily_returns[$date] ?? 0;
    
    $gross_profit = $day['total_revenue'] - $total_cost;
    $net_profit = $gross_profit - $commission - $returns;
    
    $combined_data[] = [
        'date' => $date,
        'orders' => $day['order_count'],
        'revenue' => $day['total_revenue'],
        'discounts' => $day['total_discounts'],
        'tile_cost' => $tile_cost,
        'misc_cost' => $misc_cost,
        'total_cost' => $total_cost,
        'gross_profit' => $gross_profit,
        'commission' => $commission,
        'returns' => $returns,
        'net_profit' => $net_profit,
        'profit_margin' => $day['total_revenue'] > 0 ? ($net_profit / $day['total_revenue'] * 100) : 0
    ];
    
    // Add to totals
    $totals['revenue'] += $day['total_revenue'];
    $totals['cost'] += $total_cost;
    $totals['commission'] += $commission;
    $totals['returns'] += $returns;
    $totals['orders'] += $day['order_count'];
}

$totals['profit'] = $totals['revenue'] - $totals['cost'];
$totals['net_profit'] = $totals['profit'] - $totals['commission'] - $totals['returns'];
$totals['margin'] = $totals['revenue'] > 0 ? ($totals['net_profit'] / $totals['revenue'] * 100) : 0;

$page_title = "Daily Profit & Loss Report";
require_once __DIR__ . '/../includes/header.php';
?>

<style>
.profit-positive { color: #28a745; }
.profit-negative { color: #dc3545; }
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
.chart-container {
    background: white;
    border-radius: 10px;
    padding: 20px;
    box-shadow: 0 2px 10px rgba(0,0,0,0.1);
}
</style>

<div class="container-fluid mt-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h2><i class="bi bi-graph-up text-primary"></i> Daily Profit & Loss Report</h2>
            <p class="text-muted mb-0">
                Period: <?= date('M j, Y', strtotime($date_from)) ?> 
                <?= $date_from !== $date_to ? ' to ' . date('M j, Y', strtotime($date_to)) : '' ?>
            </p>
        </div>
        <a href="reports_dashboard_new.php" class="btn btn-outline-secondary">
            <i class="bi bi-arrow-left"></i> Back to Reports
        </a>
    </div>

    <!-- Date Range Filter -->
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
                    <label class="form-label">Quick Presets</label>
                    <select class="form-select" name="preset" onchange="this.form.submit()">
                        <option value="">Custom Range</option>
                        <option value="today" <?= $preset === 'today' ? 'selected' : '' ?>>Today</option>
                        <option value="yesterday" <?= $preset === 'yesterday' ? 'selected' : '' ?>>Yesterday</option>
                        <option value="this_week" <?= $preset === 'this_week' ? 'selected' : '' ?>>This Week</option>
                        <option value="last_week" <?= $preset === 'last_week' ? 'selected' : '' ?>>Last Week</option>
                        <option value="this_month" <?= $preset === 'this_month' ? 'selected' : '' ?>>This Month</option>
                    </select>
                </div>
                <div class="col-md-3 d-flex align-items-end">
                    <button type="submit" class="btn btn-primary me-2">
                        <i class="bi bi-search"></i> Generate Report
                    </button>
                    <a href="?export=excel&date_from=<?= $date_from ?>&date_to=<?= $date_to ?>" class="btn btn-success">
                        <i class="bi bi-file-excel"></i> Export
                    </a>
                </div>
            </form>
        </div>
    </div>

    <!-- Summary Cards -->
    <div class="row mb-4">
        <div class="col-md-2">
            <div class="metric-card">
                <h6>Total Revenue</h6>
                <h4>₹<?= number_format($totals['revenue'], 2) ?></h4>
                <small><?= $totals['orders'] ?> orders</small>
            </div>
        </div>
        <div class="col-md-2">
            <div class="metric-card">
                <h6>Total Costs</h6>
                <h4>₹<?= number_format($totals['cost'], 2) ?></h4>
                <small>COGS</small>
            </div>
        </div>
        <div class="col-md-2">
            <div class="metric-card">
                <h6>Gross Profit</h6>
                <h4 class="<?= $totals['profit'] >= 0 ? 'profit-positive' : 'profit-negative' ?>">
                    ₹<?= number_format($totals['profit'], 2) ?>
                </h4>
                <small>Before expenses</small>
            </div>
        </div>
        <div class="col-md-2">
            <div class="metric-card">
                <h6>Commissions</h6>
                <h4>₹<?= number_format($totals['commission'], 2) ?></h4>
                <small>Sales commissions</small>
            </div>
        </div>
        <div class="col-md-2">
            <div class="metric-card">
                <h6>Returns</h6>
                <h4 class="text-danger">₹<?= number_format($totals['returns'], 2) ?></h4>
                <small>Refunds given</small>
            </div>
        </div>
        <div class="col-md-2">
            <div class="metric-card">
                <h6>Net Profit</h6>
                <h4 class="<?= $totals['net_profit'] >= 0 ? 'profit-positive' : 'profit-negative' ?>">
                    ₹<?= number_format($totals['net_profit'], 2) ?>
                </h4>
                <small><?= number_format($totals['margin'], 1) ?>% margin</small>
            </div>
        </div>
    </div>

    <!-- Daily Breakdown Table -->
    <div class="card">
        <div class="card-header">
            <h5><i class="bi bi-calendar-week"></i> Daily Breakdown</h5>
        </div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-hover">
                    <thead class="table-light">
                        <tr>
                            <th>Date</th>
                            <th>Orders</th>
                            <th>Revenue</th>
                            <th>Tile Costs</th>
                            <th>Misc Costs</th>
                            <th>Total Costs</th>
                            <th>Gross Profit</th>
                            <th>Commission</th>
                            <th>Returns</th>
                            <th>Net Profit</th>
                            <th>Margin %</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($combined_data)): ?>
                            <tr>
                                <td colspan="11" class="text-center text-muted py-4">
                                    <i class="bi bi-info-circle"></i> No sales data found for the selected period
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($combined_data as $day): ?>
                                <tr>
                                    <td><?= date('M j, Y', strtotime($day['date'])) ?></td>
                                    <td><?= $day['orders'] ?></td>
                                    <td>₹<?= number_format($day['revenue'], 2) ?></td>
                                    <td>₹<?= number_format($day['tile_cost'], 2) ?></td>
                                    <td>₹<?= number_format($day['misc_cost'], 2) ?></td>
                                    <td>₹<?= number_format($day['total_cost'], 2) ?></td>
                                    <td class="<?= $day['gross_profit'] >= 0 ? 'profit-positive' : 'profit-negative' ?>">
                                        ₹<?= number_format($day['gross_profit'], 2) ?>
                                    </td>
                                    <td>₹<?= number_format($day['commission'], 2) ?></td>
                                    <td class="text-danger">₹<?= number_format($day['returns'], 2) ?></td>
                                    <td class="<?= $day['net_profit'] >= 0 ? 'profit-positive' : 'profit-negative' ?>">
                                        <strong>₹<?= number_format($day['net_profit'], 2) ?></strong>
                                    </td>
                                    <td class="<?= $day['profit_margin'] >= 0 ? 'profit-positive' : 'profit-negative' ?>">
                                        <?= number_format($day['profit_margin'], 1) ?>%
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                    <?php if (!empty($combined_data)): ?>
                        <tfoot class="table-secondary">
                            <tr>
                                <th>TOTALS</th>
                                <th><?= $totals['orders'] ?></th>
                                <th>₹<?= number_format($totals['revenue'], 2) ?></th>
                                <th>₹<?= number_format(array_sum(array_column($combined_data, 'tile_cost')), 2) ?></th>
                                <th>₹<?= number_format(array_sum(array_column($combined_data, 'misc_cost')), 2) ?></th>
                                <th>₹<?= number_format($totals['cost'], 2) ?></th>
                                <th class="<?= $totals['profit'] >= 0 ? 'profit-positive' : 'profit-negative' ?>">
                                    ₹<?= number_format($totals['profit'], 2) ?>
                                </th>
                                <th>₹<?= number_format($totals['commission'], 2) ?></th>
                                <th class="text-danger">₹<?= number_format($totals['returns'], 2) ?></th>
                                <th class="<?= $totals['net_profit'] >= 0 ? 'profit-positive' : 'profit-negative' ?>">
                                    <strong>₹<?= number_format($totals['net_profit'], 2) ?></strong>
                                </th>
                                <th class="<?= $totals['margin'] >= 0 ? 'profit-positive' : 'profit-negative' ?>">
                                    <strong><?= number_format($totals['margin'], 1) ?>%</strong>
                                </th>
                            </tr>
                        </tfoot>
                    <?php endif; ?>
                </table>
            </div>
        </div>
    </div>

    <!-- Chart Section -->
    <?php if (!empty($combined_data) && count($combined_data) > 1): ?>
        <div class="row mt-4">
            <div class="col-12">
                <div class="chart-container">
                    <h6>Profit Trend Chart</h6>
                    <canvas id="profitChart" style="max-height: 400px;"></canvas>
                </div>
            </div>
        </div>
    <?php endif; ?>
</div>

<?php if (!empty($combined_data) && count($combined_data) > 1): ?>
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
const ctx = document.getElementById('profitChart').getContext('2d');
const chartData = {
    labels: <?= json_encode(array_map(function($d) { return date('M j', strtotime($d['date'])); }, $combined_data)) ?>,
    datasets: [
        {
            label: 'Revenue',
            data: <?= json_encode(array_column($combined_data, 'revenue')) ?>,
            borderColor: '#007bff',
            backgroundColor: 'rgba(0, 123, 255, 0.1)',
            tension: 0.4
        },
        {
            label: 'Total Costs',
            data: <?= json_encode(array_column($combined_data, 'total_cost')) ?>,
            borderColor: '#dc3545',
            backgroundColor: 'rgba(220, 53, 69, 0.1)',
            tension: 0.4
        },
        {
            label: 'Net Profit',
            data: <?= json_encode(array_column($combined_data, 'net_profit')) ?>,
            borderColor: '#28a745',
            backgroundColor: 'rgba(40, 167, 69, 0.1)',
            tension: 0.4
        }
    ]
};

new Chart(ctx, {
    type: 'line',
    data: chartData,
    options: {
        responsive: true,
        scales: {
            y: {
                beginAtZero: true,
                ticks: {
                    callback: function(value) {
                        return '₹' + value.toLocaleString();
                    }
                }
            }
        },
        plugins: {
            tooltip: {
                callbacks: {
                    label: function(context) {
                        return context.dataset.label + ': ₹' + context.parsed.y.toLocaleString();
                    }
                }
            }
        }
    }
});
</script>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>