<?php
// public/quotation_profit_fixed.php - Quotation Profit Report (Fixed for Latest DB Schema)
require_once __DIR__ . '/../includes/simple_auth.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/admin_functions.php';

auth_require_login();

$pdo = Database::pdo();

// Date range handling
$date_from = $_GET['date_from'] ?? date('Y-m-01');
$date_to = $_GET['date_to'] ?? date('Y-m-d');
$customer_filter = trim($_GET['customer'] ?? '');

// Build quotation profit analysis query
$quotation_sql = "
    SELECT 
        q.id,
        q.quote_no,
        DATE(q.quote_dt) as quote_date,
        q.customer_name,
        q.firm_name,
        q.phone,
        q.total as quote_total,
        q.discount_amount,
        q.final_total,
        (
            SELECT SUM(qi.boxes_decimal * qi.rate_per_box)
            FROM quotation_items qi
            WHERE qi.quotation_id = q.id
        ) as tiles_revenue,
        (
            SELECT SUM(qmi.qty_units * qmi.rate_per_unit)
            FROM quotation_misc_items qmi
            WHERE qmi.quotation_id = q.id
        ) as misc_revenue,
        (
            SELECT SUM(qi.boxes_decimal * t.current_cost)
            FROM quotation_items qi
            JOIN tiles t ON qi.tile_id = t.id
            WHERE qi.quotation_id = q.id
        ) as tiles_cost,
        (
            SELECT SUM(qmi.quantity * m.current_cost)
            FROM quotation_misc_items qmi
            JOIN misc_items m ON qmi.misc_item_id = m.id
            WHERE qmi.quotation_id = q.id
        ) as misc_cost,
        CASE 
            WHEN EXISTS (SELECT 1 FROM invoices i WHERE i.quote_id = q.id) 
            THEN 'Converted' 
            ELSE 'Pending' 
        END as conversion_status,
        (
            SELECT i.final_total 
            FROM invoices i 
            WHERE i.quote_id = q.id 
            LIMIT 1
        ) as converted_invoice_total
    FROM quotations q
    WHERE DATE(q.quote_dt) BETWEEN ? AND ?
";

$params = [$date_from, $date_to];

if ($customer_filter) {
    $quotation_sql .= " AND (q.customer_name LIKE ? OR q.firm_name LIKE ?)";
    $params[] = "%$customer_filter%";
    $params[] = "%$customer_filter%";
}

$quotation_sql .= " ORDER BY q.quote_dt DESC";

$quotation_stmt = $pdo->prepare($quotation_sql);
$quotation_stmt->execute($params);
$quotation_data = $quotation_stmt->fetchAll(PDO::FETCH_ASSOC);

// Process and calculate profit metrics
$processed_data = [];
$summary = [
    'total_quotes' => count($quotation_data),
    'total_quote_value' => 0,
    'total_estimated_cost' => 0,
    'total_estimated_profit' => 0,
    'converted_quotes' => 0,
    'pending_quotes' => 0,
    'conversion_rate' => 0,
    'avg_profit_margin' => 0
];

foreach ($quotation_data as $quote) {
    $tiles_revenue = $quote['tiles_revenue'] ?? 0;
    $misc_revenue = $quote['misc_revenue'] ?? 0;
    $tiles_cost = $quote['tiles_cost'] ?? 0;
    $misc_cost = $quote['misc_cost'] ?? 0;
    
    $total_revenue = $tiles_revenue + $misc_revenue;
    $total_cost = $tiles_cost + $misc_cost;
    $estimated_profit = $total_revenue - $total_cost;
    $profit_margin = $total_revenue > 0 ? ($estimated_profit / $total_revenue * 100) : 0;
    
    $processed_quote = array_merge($quote, [
        'total_revenue' => $total_revenue,
        'total_cost' => $total_cost,
        'estimated_profit' => $estimated_profit,
        'profit_margin' => $profit_margin
    ]);
    
    $processed_data[] = $processed_quote;
    
    // Update summary
    $summary['total_quote_value'] += $quote['final_total'] ?? $total_revenue;
    $summary['total_estimated_cost'] += $total_cost;
    $summary['total_estimated_profit'] += $estimated_profit;
    
    if ($quote['conversion_status'] === 'Converted') {
        $summary['converted_quotes']++;
    } else {
        $summary['pending_quotes']++;
    }
}

$summary['conversion_rate'] = $summary['total_quotes'] > 0 ? 
    ($summary['converted_quotes'] / $summary['total_quotes'] * 100) : 0;

$summary['avg_profit_margin'] = $summary['total_quote_value'] > 0 ? 
    ($summary['total_estimated_profit'] / $summary['total_quote_value'] * 100) : 0;

// Get all customers for filter
$customers_sql = "
    SELECT DISTINCT customer_name
    FROM quotations 
    WHERE customer_name IS NOT NULL AND customer_name != ''
    ORDER BY customer_name
";
$all_customers = $pdo->query($customers_sql)->fetchAll(PDO::FETCH_COLUMN);

$page_title = "Quotation Profit Analysis";
require_once __DIR__ . '/../includes/header.php';
?>

<style>
.profit-positive { color: #28a745; }
.profit-negative { color: #dc3545; }
.conversion-converted { background-color: rgba(40, 167, 69, 0.1); }
.conversion-pending { background-color: rgba(255, 193, 7, 0.1); }
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
            <h2><i class="bi bi-file-earmark-text text-primary"></i> Quotation Profit Analysis</h2>
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
                <h6>Total Quotes</h6>
                <h3><?= $summary['total_quotes'] ?></h3>
                <small>In period</small>
            </div>
        </div>
        <div class="col-md-2">
            <div class="metric-card">
                <h6>Quote Value</h6>
                <h3>₹<?= number_format($summary['total_quote_value'], 0) ?></h3>
                <small>Total quoted</small>
            </div>
        </div>
        <div class="col-md-2">
            <div class="metric-card">
                <h6>Est. Cost</h6>
                <h3>₹<?= number_format($summary['total_estimated_cost'], 0) ?></h3>
                <small>Estimated COGS</small>
            </div>
        </div>
        <div class="col-md-2">
            <div class="metric-card">
                <h6>Est. Profit</h6>
                <h3 class="<?= $summary['total_estimated_profit'] >= 0 ? 'profit-positive' : 'profit-negative' ?>">
                    ₹<?= number_format($summary['total_estimated_profit'], 0) ?>
                </h3>
                <small><?= number_format($summary['avg_profit_margin'], 1) ?>% margin</small>
            </div>
        </div>
        <div class="col-md-2">
            <div class="metric-card">
                <h6>Converted</h6>
                <h3 class="text-success"><?= $summary['converted_quotes'] ?></h3>
                <small><?= number_format($summary['conversion_rate'], 1) ?>% rate</small>
            </div>
        </div>
        <div class="col-md-2">
            <div class="metric-card">
                <h6>Pending</h6>
                <h3 class="text-warning"><?= $summary['pending_quotes'] ?></h3>
                <small>Not converted</small>
            </div>
        </div>
    </div>

    <!-- Quotation Details -->
    <div class="card">
        <div class="card-header">
            <h5><i class="bi bi-list-ul"></i> Quotation Profit Details</h5>
        </div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-hover table-sm">
                    <thead class="table-light">
                        <tr>
                            <th>Date</th>
                            <th>Quote #</th>
                            <th>Customer</th>
                            <th>Quote Value</th>
                            <th>Est. Cost</th>
                            <th>Est. Profit</th>
                            <th>Margin %</th>
                            <th>Status</th>
                            <th>Converted Value</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($processed_data)): ?>
                            <tr>
                                <td colspan="9" class="text-center text-muted py-4">
                                    <i class="bi bi-info-circle"></i> No quotation data found for the selected period
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($processed_data as $quote): ?>
                                <?php 
                                $conversion_class = $quote['conversion_status'] === 'Converted' ? 'conversion-converted' : 'conversion-pending';
                                ?>
                                <tr class="<?= $conversion_class ?>">
                                    <td><?= date('M j, Y', strtotime($quote['quote_date'])) ?></td>
                                    <td>
                                        <a href="quotation_enhanced.php?id=<?= $quote['id'] ?>" class="text-decoration-none">
                                            <?= h($quote['quote_no']) ?>
                                        </a>
                                    </td>
                                    <td>
                                        <strong><?= h($quote['customer_name']) ?></strong>
                                        <?= $quote['firm_name'] ? '<br><small class="text-muted">' . h($quote['firm_name']) . '</small>' : '' ?>
                                    </td>
                                    <td>₹<?= number_format($quote['final_total'] ?? $quote['total_revenue'], 2) ?></td>
                                    <td>₹<?= number_format($quote['total_cost'], 2) ?></td>
                                    <td class="<?= $quote['estimated_profit'] >= 0 ? 'profit-positive' : 'profit-negative' ?>">
                                        ₹<?= number_format($quote['estimated_profit'], 2) ?>
                                    </td>
                                    <td class="<?= $quote['profit_margin'] >= 0 ? 'profit-positive' : 'profit-negative' ?>">
                                        <?= number_format($quote['profit_margin'], 1) ?>%
                                    </td>
                                    <td>
                                        <span class="badge bg-<?= $quote['conversion_status'] === 'Converted' ? 'success' : 'warning' ?>">
                                            <?= $quote['conversion_status'] ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?= $quote['converted_invoice_total'] ? '₹' . number_format($quote['converted_invoice_total'], 2) : '-' ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                    <?php if (!empty($processed_data)): ?>
                        <tfoot class="table-secondary">
                            <tr>
                                <th colspan="3">TOTALS</th>
                                <th>₹<?= number_format($summary['total_quote_value'], 2) ?></th>
                                <th>₹<?= number_format($summary['total_estimated_cost'], 2) ?></th>
                                <th class="<?= $summary['total_estimated_profit'] >= 0 ? 'profit-positive' : 'profit-negative' ?>">
                                    ₹<?= number_format($summary['total_estimated_profit'], 2) ?>
                                </th>
                                <th class="<?= $summary['avg_profit_margin'] >= 0 ? 'profit-positive' : 'profit-negative' ?>">
                                    <?= number_format($summary['avg_profit_margin'], 1) ?>%
                                </th>
                                <th><?= $summary['converted_quotes'] ?>/<?= $summary['total_quotes'] ?></th>
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