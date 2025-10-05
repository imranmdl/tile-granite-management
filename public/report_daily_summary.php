<?php
// public/report_daily_summary.php - Daily Business Summary (FR-RP-04)
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
if (!$can_view_reports) {
    $_SESSION['error'] = 'You do not have permission to access reports';
    safe_redirect('reports_dashboard.php');
}

$selected_date = $_GET['date'] ?? date('Y-m-d');

// Get daily invoices summary
$invoices_stmt = $pdo->prepare("
    SELECT 
        COUNT(*) as invoice_count,
        SUM(total) as gross_sales,
        SUM(COALESCE(final_total, total)) as net_sales,
        AVG(COALESCE(final_total, total)) as avg_invoice_value,
        MIN(COALESCE(final_total, total)) as min_invoice,
        MAX(COALESCE(final_total, total)) as max_invoice
    FROM invoices 
    WHERE DATE(invoice_dt) = ?
");
$invoices_stmt->execute([$selected_date]);
$invoice_summary = $invoices_stmt->fetch(PDO::FETCH_ASSOC);

// Get payments received (assuming paid invoices)
$payments_stmt = $pdo->prepare("
    SELECT 
        COUNT(*) as paid_count,
        SUM(COALESCE(final_total, total)) as payments_received
    FROM invoices 
    WHERE DATE(invoice_dt) = ? AND status = 'paid'
");
$payments_stmt->execute([$selected_date]);
$payment_summary = $payments_stmt->fetch(PDO::FETCH_ASSOC);

// Get top selling items (tiles)
$top_tiles_stmt = $pdo->prepare("
    SELECT 
        t.name as item_name,
        ts.label as size_label,
        SUM(ii.boxes_decimal) as total_quantity,
        SUM(ii.line_total) as total_value,
        COUNT(DISTINCT ii.invoice_id) as invoice_count
    FROM invoice_items ii
    JOIN invoices i ON ii.invoice_id = i.id
    JOIN tiles t ON ii.tile_id = t.id
    JOIN tile_sizes ts ON t.size_id = ts.id
    WHERE DATE(i.invoice_dt) = ?
    GROUP BY t.id, t.name, ts.label
    ORDER BY total_value DESC
    LIMIT 10
");
$top_tiles_stmt->execute([$selected_date]);
$top_tiles = $top_tiles_stmt->fetchAll(PDO::FETCH_ASSOC);

// Get top selling misc items
$top_misc_stmt = $pdo->prepare("
    SELECT 
        m.name as item_name,
        m.unit_label,
        SUM(imi.qty_units) as total_quantity,
        SUM(imi.line_total) as total_value,
        COUNT(DISTINCT imi.invoice_id) as invoice_count
    FROM invoice_misc_items imi
    JOIN invoices i ON imi.invoice_id = i.id
    JOIN misc_items m ON imi.misc_item_id = m.id
    WHERE DATE(i.invoice_dt) = ?
    GROUP BY m.id, m.name, m.unit_label
    ORDER BY total_value DESC
    LIMIT 10
");
$top_misc_stmt->execute([$selected_date]);
$top_misc = $top_misc_stmt->fetchAll(PDO::FETCH_ASSOC);

// Get returns summary
$returns_stmt = $pdo->prepare("
    SELECT 
        COUNT(*) as return_count,
        SUM(refund_amount) as total_refunds,
        COUNT(DISTINCT invoice_id) as affected_invoices
    FROM individual_returns 
    WHERE DATE(return_date) = ?
");
$returns_stmt->execute([$selected_date]);
$returns_summary = $returns_stmt->fetch(PDO::FETCH_ASSOC);

// Get hourly distribution
$hourly_stmt = $pdo->prepare("
    SELECT 
        strftime('%H', invoice_dt) as hour,
        COUNT(*) as invoice_count,
        SUM(COALESCE(final_total, total)) as hourly_sales
    FROM invoices 
    WHERE DATE(invoice_dt) = ?
    GROUP BY strftime('%H', invoice_dt)
    ORDER BY hour
");
$hourly_stmt->execute([$selected_date]);
$hourly_data = $hourly_stmt->fetchAll(PDO::FETCH_ASSOC);

// Get customer distribution
$customers_stmt = $pdo->prepare("
    SELECT 
        customer_name,
        firm_name,
        COUNT(*) as invoice_count,
        SUM(COALESCE(final_total, total)) as customer_total
    FROM invoices 
    WHERE DATE(invoice_dt) = ?
    GROUP BY customer_name, firm_name
    ORDER BY customer_total DESC
    LIMIT 10
");
$customers_stmt->execute([$selected_date]);
$top_customers = $customers_stmt->fetchAll(PDO::FETCH_ASSOC);

$page_title = "Daily Business Summary";
require_once __DIR__ . '/../includes/header.php';
?>

<style>
.summary-card {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
    border-radius: 15px;
    padding: 1.5rem;
    margin-bottom: 1rem;
    box-shadow: 0 4px 15px rgba(0,0,0,0.1);
}
.metric-card {
    border-radius: 10px;
    padding: 1rem;
    text-align: center;
    margin-bottom: 1rem;
}
.chart-container {
    position: relative;
    height: 300px;
    margin-bottom: 2rem;
}
</style>

<div class="container-fluid mt-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h2><i class="bi bi-calendar-day"></i> Daily Business Summary</h2>
        <div>
            <a href="reports_dashboard.php" class="btn btn-outline-secondary">
                <i class="bi bi-arrow-left"></i> Back to Dashboard
            </a>
            <button onclick="window.print()" class="btn btn-primary">
                <i class="bi bi-printer"></i> Print Report
            </button>
        </div>
    </div>

    <!-- Date Selector -->
    <div class="card mb-4">
        <div class="card-body">
            <form method="GET" class="row g-3 align-items-end">
                <div class="col-md-3">
                    <label class="form-label">Select Date</label>
                    <input type="date" class="form-control" name="date" value="<?= h($selected_date) ?>">
                </div>
                <div class="col-md-6">
                    <div class="btn-group" role="group">
                        <a href="?date=<?= date('Y-m-d') ?>" class="btn btn-outline-primary btn-sm">Today</a>
                        <a href="?date=<?= date('Y-m-d', strtotime('-1 day')) ?>" class="btn btn-outline-primary btn-sm">Yesterday</a>
                        <a href="?date=<?= date('Y-m-d', strtotime('-7 days')) ?>" class="btn btn-outline-primary btn-sm">Week Ago</a>
                    </div>
                </div>
                <div class="col-md-3">
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-calendar-check"></i> View Date
                    </button>
                </div>
            </form>
        </div>
    </div>

    <h3 class="mb-4">Summary for <?= date('l, F j, Y', strtotime($selected_date)) ?></h3>

    <!-- Key Metrics -->
    <div class="row mb-4">
        <div class="col-md-2">
            <div class="card bg-primary text-white metric-card">
                <h4><?= number_format($invoice_summary['invoice_count'] ?? 0) ?></h4>
                <small>Total Invoices</small>
            </div>
        </div>
        <div class="col-md-2">
            <div class="card bg-success text-white metric-card">
                <h4>₹<?= number_format($invoice_summary['net_sales'] ?? 0, 0) ?></h4>
                <small>Total Sales</small>
            </div>
        </div>
        <div class="col-md-2">
            <div class="card bg-info text-white metric-card">
                <h4>₹<?= number_format($payment_summary['payments_received'] ?? 0, 0) ?></h4>
                <small>Payments Received</small>
            </div>
        </div>
        <div class="col-md-2">
            <div class="card bg-warning text-white metric-card">
                <h4>₹<?= number_format($invoice_summary['avg_invoice_value'] ?? 0, 0) ?></h4>
                <small>Avg Invoice Value</small>
            </div>
        </div>
        <div class="col-md-2">
            <div class="card bg-danger text-white metric-card">
                <h4><?= number_format($returns_summary['return_count'] ?? 0) ?></h4>
                <small>Returns</small>
            </div>
        </div>
        <div class="col-md-2">
            <div class="card bg-dark text-white metric-card">
                <h4>₹<?= number_format($returns_summary['total_refunds'] ?? 0, 0) ?></h4>
                <small>Total Refunds</small>
            </div>
        </div>
    </div>

    <div class="row">
        <!-- Hourly Sales Distribution -->
        <div class="col-md-6 mb-4">
            <div class="card">
                <div class="card-header">
                    <h5><i class="bi bi-clock"></i> Hourly Sales Distribution</h5>
                </div>
                <div class="card-body">
                    <div class="chart-container">
                        <canvas id="hourlyChart"></canvas>
                    </div>
                </div>
            </div>
        </div>

        <!-- Top Customers -->
        <div class="col-md-6 mb-4">
            <div class="card">
                <div class="card-header">
                    <h5><i class="bi bi-people"></i> Top Customers</h5>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-sm">
                            <thead>
                                <tr>
                                    <th>Customer</th>
                                    <th>Invoices</th>
                                    <th>Amount</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($top_customers)): ?>
                                    <tr><td colspan="3" class="text-center text-muted">No customers found</td></tr>
                                <?php else: ?>
                                    <?php foreach ($top_customers as $customer): ?>
                                        <tr>
                                            <td>
                                                <strong><?= h($customer['customer_name']) ?></strong>
                                                <?php if ($customer['firm_name']): ?>
                                                    <br><small class="text-muted"><?= h($customer['firm_name']) ?></small>
                                                <?php endif; ?>
                                            </td>
                                            <td><?= $customer['invoice_count'] ?></td>
                                            <td>₹<?= number_format($customer['customer_total'], 0) ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="row">
        <!-- Top Selling Tiles -->
        <div class="col-md-6 mb-4">
            <div class="card">
                <div class="card-header">
                    <h5><i class="bi bi-grid-3x3"></i> Top Selling Tiles</h5>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-sm">
                            <thead>
                                <tr>
                                    <th>Tile</th>
                                    <th>Quantity</th>
                                    <th>Value</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($top_tiles)): ?>
                                    <tr><td colspan="3" class="text-center text-muted">No tiles sold</td></tr>
                                <?php else: ?>
                                    <?php foreach ($top_tiles as $tile): ?>
                                        <tr>
                                            <td>
                                                <strong><?= h($tile['item_name']) ?></strong>
                                                <br><small class="text-muted"><?= h($tile['size_label']) ?></small>
                                            </td>
                                            <td><?= number_format($tile['total_quantity'], 1) ?> boxes</td>
                                            <td>₹<?= number_format($tile['total_value'], 0) ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <!-- Top Selling Misc Items -->
        <div class="col-md-6 mb-4">
            <div class="card">
                <div class="card-header">
                    <h5><i class="bi bi-box"></i> Top Selling Other Items</h5>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-sm">
                            <thead>
                                <tr>
                                    <th>Item</th>
                                    <th>Quantity</th>
                                    <th>Value</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($top_misc)): ?>
                                    <tr><td colspan="3" class="text-center text-muted">No other items sold</td></tr>
                                <?php else: ?>
                                    <?php foreach ($top_misc as $item): ?>
                                        <tr>
                                            <td><strong><?= h($item['item_name']) ?></strong></td>
                                            <td><?= number_format($item['total_quantity'], 1) ?> <?= h($item['unit_label']) ?></td>
                                            <td>₹<?= number_format($item['total_value'], 0) ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Additional Stats -->
    <div class="row">
        <div class="col-md-12">
            <div class="card">
                <div class="card-header">
                    <h5><i class="bi bi-info-circle"></i> Additional Statistics</h5>
                </div>
                <div class="card-body">
                    <div class="row text-center">
                        <div class="col-md-3">
                            <h6>Invoice Range</h6>
                            <p class="mb-0">Min: ₹<?= number_format($invoice_summary['min_invoice'] ?? 0, 0) ?></p>
                            <p>Max: ₹<?= number_format($invoice_summary['max_invoice'] ?? 0, 0) ?></p>
                        </div>
                        <div class="col-md-3">
                            <h6>Payment Status</h6>
                            <p class="mb-0">Paid: <?= number_format($payment_summary['paid_count'] ?? 0) ?> invoices</p>
                            <p>Collection Rate: <?= ($invoice_summary['invoice_count'] ?? 0) > 0 ? number_format((($payment_summary['paid_count'] ?? 0) / ($invoice_summary['invoice_count'] ?? 1)) * 100, 1) : 0 ?>%</p>
                        </div>
                        <div class="col-md-3">
                            <h6>Returns Impact</h6>
                            <p class="mb-0">Affected Invoices: <?= number_format($returns_summary['affected_invoices'] ?? 0) ?></p>
                            <p>Return Rate: <?= ($invoice_summary['invoice_count'] ?? 0) > 0 ? number_format((($returns_summary['affected_invoices'] ?? 0) / ($invoice_summary['invoice_count'] ?? 1)) * 100, 1) : 0 ?>%</p>
                        </div>
                        <div class="col-md-3">
                            <h6>Business Performance</h6>
                            <p class="mb-0">Net After Returns: ₹<?= number_format(($invoice_summary['net_sales'] ?? 0) - ($returns_summary['total_refunds'] ?? 0), 0) ?></p>
                            <p>Gross Margin: <?= ($invoice_summary['gross_sales'] ?? 0) > 0 ? number_format((($invoice_summary['net_sales'] ?? 0) / ($invoice_summary['gross_sales'] ?? 1)) * 100, 1) : 0 ?>%</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Chart.js -->
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
// Hourly Distribution Chart
const hourlyCtx = document.getElementById('hourlyChart').getContext('2d');
const hourlyData = <?= json_encode($hourly_data) ?>;

// Create 24-hour array with data
const hourlyLabels = [];
const hourlySales = [];
for (let i = 0; i < 24; i++) {
    hourlyLabels.push(i.toString().padStart(2, '0') + ':00');
    const hourData = hourlyData.find(h => parseInt(h.hour) === i);
    hourlySales.push(hourData ? parseFloat(hourData.hourly_sales) : 0);
}

new Chart(hourlyCtx, {
    type: 'bar',
    data: {
        labels: hourlyLabels,
        datasets: [{
            label: 'Sales (₹)',
            data: hourlySales,
            backgroundColor: 'rgba(54, 162, 235, 0.6)',
            borderColor: 'rgba(54, 162, 235, 1)',
            borderWidth: 1
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
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
                        return 'Sales: ₹' + context.parsed.y.toLocaleString();
                    }
                }
            }
        }
    }
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>