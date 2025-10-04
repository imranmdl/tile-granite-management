<?php
// public/report_sales_enhanced.php - Enhanced Sales Report with Latest DB Schema
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
    $_SESSION['error'] = 'You do not have permission to access sales reports';
    header('Location: /reports_dashboard_new.php');
    exit;
}

// Parameters and filters
$preset = $_GET['preset'] ?? '';
$date_from = $_GET['date_from'] ?? '';
$date_to = $_GET['date_to'] ?? '';
$customer_filter = trim($_GET['customer'] ?? '');
$salesperson_filter = trim($_GET['salesperson'] ?? '');
$product_type = $_GET['product_type'] ?? 'all';
$min_amount = (float)($_GET['min_amount'] ?? 0);
$export = isset($_GET['export']) && $_GET['export'] === 'excel';

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
    case 'last_month':
        $date_from = date('Y-m-01', strtotime('first day of last month'));
        $date_to = date('Y-m-t', strtotime('last day of last month'));
        break;
    default:
        if (!$date_from) $date_from = date('Y-m-01'); // First day of current month
        if (!$date_to) $date_to = date('Y-m-d'); // Today
        break;
}

// Build comprehensive sales query
$sales_sql = "
    SELECT 
        i.id,
        i.invoice_no,
        DATE(i.invoice_dt) as sale_date,
        i.customer_name,
        i.firm_name,
        i.phone,
        i.subtotal,
        i.discount_type,
        i.discount_value,
        i.discount_amount,
        i.final_total,
        i.status,
        i.commission_percentage,
        i.commission_amount,
        u.username as salesperson,
        (
            SELECT COUNT(*) 
            FROM invoice_items ii 
            WHERE ii.invoice_id = i.id
        ) as tile_items_count,
        (
            SELECT COUNT(*) 
            FROM invoice_misc_items imi 
            WHERE imi.invoice_id = i.id
        ) as misc_items_count,
        (
            SELECT SUM(ii.quantity * ii.rate_per_box) 
            FROM invoice_items ii 
            WHERE ii.invoice_id = i.id
        ) as tiles_revenue,
        (
            SELECT SUM(imi.quantity * imi.rate_per_unit) 
            FROM invoice_misc_items imi 
            WHERE imi.invoice_id = i.id
        ) as misc_revenue
    FROM invoices i
    LEFT JOIN users_simple u ON i.created_by = u.id
    WHERE DATE(i.invoice_dt) BETWEEN ? AND ?
    AND i.status != 'CANCELLED'
";

$params = [$date_from, $date_to];

// Add filters
if ($customer_filter) {
    $sales_sql .= " AND (i.customer_name LIKE ? OR i.firm_name LIKE ?)";
    $params[] = "%$customer_filter%";
    $params[] = "%$customer_filter%";
}

if ($salesperson_filter) {
    $sales_sql .= " AND u.username LIKE ?";
    $params[] = "%$salesperson_filter%";
}

if ($min_amount > 0) {
    $sales_sql .= " AND i.final_total >= ?";
    $params[] = $min_amount;
}

// Filter by product type
if ($product_type === 'tiles_only') {
    $sales_sql .= " AND EXISTS (SELECT 1 FROM invoice_items ii WHERE ii.invoice_id = i.id)";
} elseif ($product_type === 'misc_only') {
    $sales_sql .= " AND EXISTS (SELECT 1 FROM invoice_misc_items imi WHERE imi.invoice_id = i.id)";
}

$sales_sql .= " ORDER BY i.invoice_dt DESC";

$sales_stmt = $pdo->prepare($sales_sql);
$sales_stmt->execute($params);
$sales_data = $sales_stmt->fetchAll(PDO::FETCH_ASSOC);

// Calculate summary statistics
$summary = [
    'total_sales' => 0,
    'total_discount' => 0,
    'total_commission' => 0,
    'avg_order_value' => 0,
    'total_orders' => count($sales_data),
    'tiles_revenue' => 0,
    'misc_revenue' => 0,
    'customers_count' => 0,
    'top_salesperson' => '',
    'top_customer' => ''
];

$customer_totals = [];
$salesperson_totals = [];

foreach ($sales_data as $sale) {
    $summary['total_sales'] += $sale['final_total'];
    $summary['total_discount'] += $sale['discount_amount'] ?? 0;
    $summary['total_commission'] += $sale['commission_amount'] ?? 0;
    $summary['tiles_revenue'] += $sale['tiles_revenue'] ?? 0;
    $summary['misc_revenue'] += $sale['misc_revenue'] ?? 0;
    
    // Customer analysis
    $customer_key = $sale['customer_name'] . '|' . $sale['firm_name'];
    if (!isset($customer_totals[$customer_key])) {
        $customer_totals[$customer_key] = [
            'name' => $sale['customer_name'],
            'firm' => $sale['firm_name'],
            'total' => 0,
            'orders' => 0
        ];
    }
    $customer_totals[$customer_key]['total'] += $sale['final_total'];
    $customer_totals[$customer_key]['orders']++;
    
    // Salesperson analysis
    if ($sale['salesperson']) {
        if (!isset($salesperson_totals[$sale['salesperson']])) {
            $salesperson_totals[$sale['salesperson']] = [
                'total' => 0,
                'orders' => 0,
                'commission' => 0
            ];
        }
        $salesperson_totals[$sale['salesperson']]['total'] += $sale['final_total'];
        $salesperson_totals[$sale['salesperson']]['orders']++;
        $salesperson_totals[$sale['salesperson']]['commission'] += $sale['commission_amount'] ?? 0;
    }
}

$summary['avg_order_value'] = $summary['total_orders'] > 0 ? ($summary['total_sales'] / $summary['total_orders']) : 0;
$summary['customers_count'] = count($customer_totals);

// Find top performers
if (!empty($customer_totals)) {
    uasort($customer_totals, function($a, $b) { return $b['total'] <=> $a['total']; });
    $top_customer = reset($customer_totals);
    $summary['top_customer'] = $top_customer['name'] . ($top_customer['firm'] ? ' (' . $top_customer['firm'] . ')' : '');
}

if (!empty($salesperson_totals)) {
    uasort($salesperson_totals, function($a, $b) { return $b['total'] <=> $a['total']; });
    $summary['top_salesperson'] = array_key_first($salesperson_totals);
}

// Get all customers and salespersons for filter dropdowns
$customers_sql = "
    SELECT DISTINCT 
        customer_name,
        firm_name,
        CONCAT(customer_name, CASE WHEN firm_name != '' THEN CONCAT(' (', firm_name, ')') ELSE '' END) as display_name
    FROM invoices 
    WHERE customer_name IS NOT NULL AND customer_name != ''
    ORDER BY customer_name
";
$all_customers = $pdo->query($customers_sql)->fetchAll(PDO::FETCH_ASSOC);

$salespersons_sql = "
    SELECT DISTINCT u.username 
    FROM invoices i 
    JOIN users_simple u ON i.created_by = u.id 
    WHERE u.username IS NOT NULL 
    ORDER BY u.username
";
$all_salespersons = $pdo->query($salespersons_sql)->fetchAll(PDO::FETCH_COLUMN);

$page_title = "Enhanced Sales Report";
require_once __DIR__ . '/../includes/header.php';
?>

<style>
.sales-summary {
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
.performance-card {
    border-left: 4px solid #28a745;
    transition: all 0.3s ease;
}
.performance-card:hover {
    box-shadow: 0 4px 15px rgba(0,0,0,0.1);
}
.high-value { background-color: rgba(40, 167, 69, 0.1); }
.medium-value { background-color: rgba(255, 193, 7, 0.1); }
.low-value { background-color: rgba(108, 117, 125, 0.1); }
</style>

<div class="container-fluid mt-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h2><i class="bi bi-graph-up text-success"></i> Enhanced Sales Report</h2>
            <p class="text-muted mb-0">
                Period: <?= date('M j, Y', strtotime($date_from)) ?> to <?= date('M j, Y', strtotime($date_to)) ?>
                <?= $customer_filter ? " | Customer: $customer_filter" : "" ?>
                <?= $salesperson_filter ? " | Salesperson: $salesperson_filter" : "" ?>
            </p>
        </div>
        <a href="reports_dashboard_new.php" class="btn btn-outline-secondary">
            <i class="bi bi-arrow-left"></i> Back to Reports
        </a>
    </div>

    <!-- Advanced Filters -->
    <div class="card mb-4">
        <div class="card-header">
            <h5><i class="bi bi-funnel"></i> Advanced Filters</h5>
        </div>
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
                    <label class="form-label">Quick Presets</label>
                    <select class="form-select" name="preset" onchange="if(this.value) this.form.submit()">
                        <option value="">Custom Range</option>
                        <option value="today" <?= $preset === 'today' ? 'selected' : '' ?>>Today</option>
                        <option value="yesterday" <?= $preset === 'yesterday' ? 'selected' : '' ?>>Yesterday</option>
                        <option value="this_week" <?= $preset === 'this_week' ? 'selected' : '' ?>>This Week</option>
                        <option value="last_week" <?= $preset === 'last_week' ? 'selected' : '' ?>>Last Week</option>
                        <option value="this_month" <?= $preset === 'this_month' ? 'selected' : '' ?>>This Month</option>
                        <option value="last_month" <?= $preset === 'last_month' ? 'selected' : '' ?>>Last Month</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label">Customer</label>
                    <select class="form-select" name="customer">
                        <option value="">All Customers</option>
                        <?php foreach ($all_customers as $customer): ?>
                            <option value="<?= h($customer['customer_name']) ?>" 
                                    <?= $customer_filter === $customer['customer_name'] ? 'selected' : '' ?>>
                                <?= h($customer['display_name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label">Salesperson</label>
                    <select class="form-select" name="salesperson">
                        <option value="">All Salespersons</option>
                        <?php foreach ($all_salespersons as $salesperson): ?>
                            <option value="<?= h($salesperson) ?>" 
                                    <?= $salesperson_filter === $salesperson ? 'selected' : '' ?>>
                                <?= h($salesperson) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label">Product Type</label>
                    <select class="form-select" name="product_type">
                        <option value="all" <?= $product_type === 'all' ? 'selected' : '' ?>>All Products</option>
                        <option value="tiles_only" <?= $product_type === 'tiles_only' ? 'selected' : '' ?>>Tiles Only</option>
                        <option value="misc_only" <?= $product_type === 'misc_only' ? 'selected' : '' ?>>Misc Items Only</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label">Min Amount</label>
                    <input type="number" class="form-control" name="min_amount" 
                           value="<?= $min_amount ?>" min="0" step="100" placeholder="e.g., 1000">
                </div>
                <div class="col-md-10"></div>
                <div class="col-md-2">
                    <button type="submit" class="btn btn-primary w-100">
                        <i class="bi bi-search"></i> Apply Filters
                    </button>
                </div>
                <div class="col-md-2">
                    <a href="?export=excel&<?= http_build_query($_GET) ?>" class="btn btn-success w-100">
                        <i class="bi bi-file-excel"></i> Export Excel
                    </a>
                </div>
            </form>
        </div>
    </div>

    <!-- Sales Summary -->
    <div class="row mb-4">
        <div class="col-md-2">
            <div class="metric-card">
                <h6>Total Sales</h6>
                <h3>₹<?= number_format($summary['total_sales'], 2) ?></h3>
                <small><?= $summary['total_orders'] ?> orders</small>
            </div>
        </div>
        <div class="col-md-2">
            <div class="metric-card">
                <h6>Avg Order Value</h6>
                <h3>₹<?= number_format($summary['avg_order_value'], 2) ?></h3>
                <small>Per order</small>
            </div>
        </div>
        <div class="col-md-2">
            <div class="metric-card">
                <h6>Total Discounts</h6>
                <h3 class="text-warning">₹<?= number_format($summary['total_discount'], 2) ?></h3>
                <small>Given to customers</small>
            </div>
        </div>
        <div class="col-md-2">
            <div class="metric-card">
                <h6>Commission</h6>
                <h3 class="text-info">₹<?= number_format($summary['total_commission'], 2) ?></h3>
                <small>Paid to sales team</small>
            </div>
        </div>
        <div class="col-md-2">
            <div class="metric-card">
                <h6>Unique Customers</h6>
                <h3><?= $summary['customers_count'] ?></h3>
                <small>Served</small>
            </div>
        </div>
        <div class="col-md-2">
            <div class="metric-card">
                <h6>Product Mix</h6>
                <h3><?= number_format($summary['tiles_revenue'] + $summary['misc_revenue'] > 0 ? 
                    ($summary['tiles_revenue'] / ($summary['tiles_revenue'] + $summary['misc_revenue']) * 100) : 0, 0) ?>%</h3>
                <small>Tiles vs Misc</small>
            </div>
        </div>
    </div>

    <!-- Top Performers -->
    <div class="row mb-4">
        <div class="col-md-6">
            <div class="card performance-card">
                <div class="card-header">
                    <h6><i class="bi bi-trophy"></i> Top Customer Performance</h6>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-sm">
                            <thead>
                                <tr>
                                    <th>Customer</th>
                                    <th>Orders</th>
                                    <th>Total Value</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php 
                                $count = 0;
                                foreach ($customer_totals as $customer): 
                                    if (++$count > 5) break;
                                ?>
                                    <tr>
                                        <td>
                                            <strong><?= h($customer['name']) ?></strong>
                                            <?= $customer['firm'] ? '<br><small class="text-muted">' . h($customer['firm']) . '</small>' : '' ?>
                                        </td>
                                        <td><?= $customer['orders'] ?></td>
                                        <td>₹<?= number_format($customer['total'], 2) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-6">
            <div class="card performance-card">
                <div class="card-header">
                    <h6><i class="bi bi-person-badge"></i> Salesperson Performance</h6>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-sm">
                            <thead>
                                <tr>
                                    <th>Salesperson</th>
                                    <th>Orders</th>
                                    <th>Total Sales</th>
                                    <th>Commission</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php 
                                $count = 0;
                                foreach ($salesperson_totals as $name => $data): 
                                    if (++$count > 5) break;
                                ?>
                                    <tr>
                                        <td><strong><?= h($name) ?></strong></td>
                                        <td><?= $data['orders'] ?></td>
                                        <td>₹<?= number_format($data['total'], 2) ?></td>
                                        <td>₹<?= number_format($data['commission'], 2) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Detailed Sales Data -->
    <div class="card">
        <div class="card-header">
            <h5><i class="bi bi-list-ul"></i> Detailed Sales Data (<?= count($sales_data) ?> records)</h5>
        </div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-hover table-sm">
                    <thead class="table-light">
                        <tr>
                            <th>Date</th>
                            <th>Invoice #</th>
                            <th>Customer</th>
                            <th>Salesperson</th>
                            <th>Items</th>
                            <th>Subtotal</th>
                            <th>Discount</th>
                            <th>Final Total</th>
                            <th>Commission</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($sales_data)): ?>
                            <tr>
                                <td colspan="10" class="text-center text-muted py-4">
                                    <i class="bi bi-info-circle"></i> No sales data found for the selected period and filters
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($sales_data as $sale): ?>
                                <?php 
                                $value_class = $sale['final_total'] >= 10000 ? 'high-value' : 
                                              ($sale['final_total'] >= 5000 ? 'medium-value' : 'low-value');
                                ?>
                                <tr class="<?= $value_class ?>">
                                    <td><?= date('M j, Y', strtotime($sale['sale_date'])) ?></td>
                                    <td>
                                        <a href="invoice_view.php?id=<?= $sale['id'] ?>" class="text-decoration-none">
                                            <?= h($sale['invoice_no']) ?>
                                        </a>
                                    </td>
                                    <td>
                                        <strong><?= h($sale['customer_name']) ?></strong>
                                        <?= $sale['firm_name'] ? '<br><small class="text-muted">' . h($sale['firm_name']) . '</small>' : '' ?>
                                        <br><small class="text-muted"><?= h($sale['phone']) ?></small>
                                    </td>
                                    <td><?= h($sale['salesperson'] ?? 'N/A') ?></td>
                                    <td>
                                        <?= $sale['tile_items_count'] ? $sale['tile_items_count'] . ' tiles' : '' ?>
                                        <?= $sale['tile_items_count'] && $sale['misc_items_count'] ? ', ' : '' ?>
                                        <?= $sale['misc_items_count'] ? $sale['misc_items_count'] . ' misc' : '' ?>
                                    </td>
                                    <td>₹<?= number_format($sale['subtotal'], 2) ?></td>
                                    <td class="text-warning">
                                        <?= $sale['discount_amount'] > 0 ? '₹' . number_format($sale['discount_amount'], 2) : '-' ?>
                                    </td>
                                    <td>
                                        <strong>₹<?= number_format($sale['final_total'], 2) ?></strong>
                                    </td>
                                    <td class="text-info">
                                        <?= $sale['commission_amount'] > 0 ? '₹' . number_format($sale['commission_amount'], 2) : '-' ?>
                                    </td>
                                    <td>
                                        <span class="badge bg-<?= $sale['status'] === 'FINALIZED' ? 'success' : 'secondary' ?>">
                                            <?= h($sale['status']) ?>
                                        </span>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                    <?php if (!empty($sales_data)): ?>
                        <tfoot class="table-secondary">
                            <tr>
                                <th colspan="5">TOTALS</th>
                                <th>₹<?= number_format(array_sum(array_column($sales_data, 'subtotal')), 2) ?></th>
                                <th class="text-warning">₹<?= number_format($summary['total_discount'], 2) ?></th>
                                <th>₹<?= number_format($summary['total_sales'], 2) ?></th>
                                <th class="text-info">₹<?= number_format($summary['total_commission'], 2) ?></th>
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