<?php
// public/report_sales.php - Sales Report (FR-RP-01)
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

// Handle form submission
$date_from = $_GET['date_from'] ?? date('Y-m-01'); // First day of current month
$date_to = $_GET['date_to'] ?? date('Y-m-d'); // Today
$preset = $_GET['preset'] ?? '';
$export = isset($_GET['export']) && $_GET['export'] === 'csv';

// Handle presets
if ($preset) {
    switch ($preset) {
        case 'today':
            $date_from = $date_to = date('Y-m-d');
            break;
        case 'yesterday':
            $date_from = $date_to = date('Y-m-d', strtotime('-1 day'));
            break;
        case 'last7':
            $date_from = date('Y-m-d', strtotime('-7 days'));
            $date_to = date('Y-m-d');
            break;
        case 'this_month':
            $date_from = date('Y-m-01');
            $date_to = date('Y-m-d');
            break;
        case 'last_month':
            $date_from = date('Y-m-01', strtotime('first day of last month'));
            $date_to = date('Y-m-t', strtotime('last day of last month'));
            break;
        case 'ytd':
            $date_from = date('Y-01-01');
            $date_to = date('Y-m-d');
            break;
    }
}

// Get sales data
$sales_stmt = $pdo->prepare("
    SELECT 
        DATE(invoice_dt) as sale_date,
        COUNT(*) as invoice_count,
        SUM(total) as gross_sales,
        SUM(COALESCE(discount_amount, 0)) as total_discounts,
        SUM(COALESCE(final_total, total)) as net_sales,
        AVG(COALESCE(final_total, total)) as avg_invoice_value
    FROM invoices 
    WHERE DATE(invoice_dt) BETWEEN ? AND ?
    GROUP BY DATE(invoice_dt)
    ORDER BY sale_date DESC
");
$sales_stmt->execute([$date_from, $date_to]);
$sales_data = $sales_stmt->fetchAll(PDO::FETCH_ASSOC);

// Get returns data
$returns_stmt = $pdo->prepare("
    SELECT 
        DATE(return_date) as return_date,
        COUNT(*) as return_count,
        SUM(refund_amount) as total_returns
    FROM individual_returns 
    WHERE DATE(return_date) BETWEEN ? AND ?
    GROUP BY DATE(return_date)
    ORDER BY return_date DESC
");
$returns_stmt->execute([$date_from, $date_to]);
$returns_data = $returns_stmt->fetchAll(PDO::FETCH_ASSOC);

// Create returns lookup array
$returns_lookup = [];
foreach ($returns_data as $return) {
    $returns_lookup[$return['return_date']] = $return;
}

// Get summary totals
$summary_stmt = $pdo->prepare("
    SELECT 
        COUNT(*) as total_invoices,
        SUM(total) as total_gross_sales,
        SUM(COALESCE(discount_amount, 0)) as total_discounts,
        SUM(COALESCE(final_total, total)) as total_net_sales,
        AVG(COALESCE(final_total, total)) as avg_invoice_value
    FROM invoices 
    WHERE DATE(invoice_dt) BETWEEN ? AND ?
");
$summary_stmt->execute([$date_from, $date_to]);
$summary = $summary_stmt->fetch(PDO::FETCH_ASSOC);

// Get total returns
$total_returns_stmt = $pdo->prepare("
    SELECT COALESCE(SUM(refund_amount), 0) as total_returns
    FROM individual_returns 
    WHERE DATE(return_date) BETWEEN ? AND ?
");
$total_returns_stmt->execute([$date_from, $date_to]);
$total_returns = $total_returns_stmt->fetchColumn();

// Calculate final net sales (after returns)
$final_net_sales = ($summary['total_net_sales'] ?? 0) - $total_returns;

// Handle CSV export
if ($export) {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="sales_report_' . $date_from . '_to_' . $date_to . '.csv"');
    
    $output = fopen('php://output', 'w');
    
    // CSV headers
    fputcsv($output, ['Date', 'Invoice Count', 'Gross Sales', 'Discounts', 'Net Sales', 'Returns', 'Final Net Sales']);
    
    // CSV data
    foreach ($sales_data as $row) {
        $return_amount = $returns_lookup[$row['sale_date']]['total_returns'] ?? 0;
        $final_net = $row['net_sales'] - $return_amount;
        
        fputcsv($output, [
            $row['sale_date'],
            $row['invoice_count'],
            number_format($row['gross_sales'], 2),
            number_format($row['gross_sales'] - $row['net_sales'], 2),
            number_format($row['net_sales'], 2),
            number_format($return_amount, 2),
            number_format($final_net, 2)
        ]);
    }
    
    fclose($output);
    exit;
}

$page_title = "Sales Report";
require_once __DIR__ . '/../includes/header.php';
?>

<style>
.chart-container {
    position: relative;
    height: 400px;
    margin-bottom: 2rem;
}
.stats-card {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
    border-radius: 10px;
    padding: 1.5rem;
    margin-bottom: 1rem;
}
.preset-btn {
    margin-right: 0.5rem;
    margin-bottom: 0.5rem;
}
</style>

<div class="container-fluid mt-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h2><i class="bi bi-graph-up"></i> Sales Report</h2>
        <div>
            <a href="reports_dashboard.php" class="btn btn-outline-secondary">
                <i class="bi bi-arrow-left"></i> Back to Dashboard
            </a>
            <a href="?<?= http_build_query(array_merge($_GET, ['export' => 'csv'])) ?>" class="btn btn-success">
                <i class="bi bi-download"></i> Export CSV
            </a>
            <button onclick="window.print()" class="btn btn-primary">
                <i class="bi bi-printer"></i> Print
            </button>
        </div>
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
                <div class="col-md-6">
                    <label class="form-label">Quick Presets</label><br>
                    <a href="?preset=today" class="btn btn-outline-primary preset-btn btn-sm">Today</a>
                    <a href="?preset=yesterday" class="btn btn-outline-primary preset-btn btn-sm">Yesterday</a>
                    <a href="?preset=last7" class="btn btn-outline-primary preset-btn btn-sm">Last 7 Days</a>
                    <a href="?preset=this_month" class="btn btn-outline-primary preset-btn btn-sm">This Month</a>
                    <a href="?preset=last_month" class="btn btn-outline-primary preset-btn btn-sm">Last Month</a>
                    <a href="?preset=ytd" class="btn btn-outline-primary preset-btn btn-sm">Year to Date</a>
                </div>
                <div class="col-12">
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-funnel"></i> Apply Filters
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Summary Stats -->
    <div class="row mb-4">
        <div class="col-md-2">
            <div class="card text-center bg-primary text-white">
                <div class="card-body">
                    <h5><?= number_format($summary['total_invoices'] ?? 0) ?></h5>
                    <small>Total Invoices</small>
                </div>
            </div>
        </div>
        <div class="col-md-2">
            <div class="card text-center bg-info text-white">
                <div class="card-body">
                    <h5>₹<?= number_format($summary['total_gross_sales'] ?? 0, 0) ?></h5>
                    <small>Gross Sales</small>
                </div>
            </div>
        </div>
        <div class="col-md-2">
            <div class="card text-center bg-warning text-white">
                <div class="card-body">
                    <h5>₹<?= number_format($summary['total_discounts'] ?? 0, 0) ?></h5>
                    <small>Total Discounts</small>
                </div>
            </div>
        </div>
        <div class="col-md-2">
            <div class="card text-center bg-success text-white">
                <div class="card-body">
                    <h5>₹<?= number_format($summary['total_net_sales'] ?? 0, 0) ?></h5>
                    <small>Net Sales</small>
                </div>
            </div>
        </div>
        <div class="col-md-2">
            <div class="card text-center bg-danger text-white">
                <div class="card-body">
                    <h5>₹<?= number_format($total_returns, 0) ?></h5>
                    <small>Total Returns</small>
                </div>
            </div>
        </div>
        <div class="col-md-2">
            <div class="card text-center bg-dark text-white">
                <div class="card-body">
                    <h5>₹<?= number_format($final_net_sales, 0) ?></h5>
                    <small>Final Net Sales</small>
                </div>
            </div>
        </div>
    </div>

    <!-- Chart -->
    <div class="card mb-4">
        <div class="card-header">
            <h5><i class="bi bi-bar-chart"></i> Sales Trend</h5>
        </div>
        <div class="card-body">
            <div class="chart-container">
                <canvas id="salesChart"></canvas>
            </div>
        </div>
    </div>

    <!-- Detailed Data Table -->
    <div class="card">
        <div class="card-header">
            <h5><i class="bi bi-table"></i> Detailed Sales Data</h5>
        </div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-striped table-hover">
                    <thead class="table-dark">
                        <tr>
                            <th>Date</th>
                            <th>Invoices</th>
                            <th>Gross Sales</th>
                            <th>Discounts</th>
                            <th>Net Sales</th>
                            <th>Returns</th>
                            <th>Final Net</th>
                            <th>Avg Invoice</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($sales_data)): ?>
                            <tr>
                                <td colspan="8" class="text-center text-muted">No sales data found for the selected period</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($sales_data as $row): ?>
                                <?php 
                                $return_amount = $returns_lookup[$row['sale_date']]['total_returns'] ?? 0;
                                $final_net = $row['net_sales'] - $return_amount;
                                ?>
                                <tr>
                                    <td><?= date('M j, Y', strtotime($row['sale_date'])) ?></td>
                                    <td><?= number_format($row['invoice_count']) ?></td>
                                    <td>₹<?= number_format($row['gross_sales'], 2) ?></td>
                                    <td class="text-warning">₹<?= number_format($row['gross_sales'] - $row['net_sales'], 2) ?></td>
                                    <td class="text-success">₹<?= number_format($row['net_sales'], 2) ?></td>
                                    <td class="text-danger">₹<?= number_format($return_amount, 2) ?></td>
                                    <td class="fw-bold">₹<?= number_format($final_net, 2) ?></td>
                                    <td>₹<?= number_format($row['avg_invoice_value'], 2) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- Chart.js -->
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
// Sales Chart
const ctx = document.getElementById('salesChart').getContext('2d');

const salesData = <?= json_encode($sales_data) ?>;
const returnsLookup = <?= json_encode($returns_lookup) ?>;

const labels = salesData.map(item => {
    const date = new Date(item.sale_date);
    return date.toLocaleDateString('en-US', { month: 'short', day: 'numeric' });
});

const grossSales = salesData.map(item => parseFloat(item.gross_sales));
const netSales = salesData.map(item => parseFloat(item.net_sales));
const returns = salesData.map(item => {
    const returnData = returnsLookup[item.sale_date];
    return returnData ? parseFloat(returnData.total_returns) : 0;
});

new Chart(ctx, {
    type: 'line',
    data: {
        labels: labels,
        datasets: [
            {
                label: 'Gross Sales',
                data: grossSales,
                borderColor: 'rgb(54, 162, 235)',
                backgroundColor: 'rgba(54, 162, 235, 0.1)',
                tension: 0.4
            },
            {
                label: 'Net Sales',
                data: netSales,
                borderColor: 'rgb(75, 192, 192)',
                backgroundColor: 'rgba(75, 192, 192, 0.1)',
                tension: 0.4
            },
            {
                label: 'Returns',
                data: returns,
                borderColor: 'rgb(255, 99, 132)',
                backgroundColor: 'rgba(255, 99, 132, 0.1)',
                tension: 0.4
            }
        ]
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
                        return context.dataset.label + ': ₹' + context.parsed.y.toLocaleString();
                    }
                }
            }
        }
    }
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>