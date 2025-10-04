<?php
// public/item_profit.php - Comprehensive Item-wise Profit/Loss Report
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

if (!$can_view_reports) {
    $_SESSION['error'] = 'You do not have permission to access reports';
    safe_redirect('index.php');
}

// Handle filters
$date_from = $_GET['date_from'] ?? date('Y-m-01');
$date_to = $_GET['date_to'] ?? date('Y-m-d');
$item_type = $_GET['item_type'] ?? 'all';
$sort_by = $_GET['sort_by'] ?? 'profit_margin';
$export = isset($_GET['export']) && $_GET['export'] === 'csv';

// Tiles P/L Analysis
$tiles_sql = "
    SELECT 
        t.id,
        t.name as item_name,
        ts.label as size_info,
        'tile' as item_type,
        
        -- Purchase/Cost Data
        COALESCE(t.current_cost, 0) as current_cost,
        COALESCE(AVG(pet.as_of_cost_per_box), 0) as avg_purchase_cost,
        
        -- Sales Data from Invoices
        COUNT(DISTINCT ii.invoice_id) as invoice_count,
        COALESCE(SUM(ii.boxes_decimal), 0) as total_boxes_sold,
        COALESCE(AVG(ii.rate_per_box), 0) as avg_selling_price,
        COALESCE(SUM(ii.line_total), 0) as total_revenue,
        
        -- Cost Calculations
        COALESCE(SUM(ii.boxes_decimal * t.current_cost), 0) as total_cost,
        COALESCE(SUM(ii.line_total) - SUM(ii.boxes_decimal * t.current_cost), 0) as gross_profit,
        
        -- Quotation Data
        COUNT(DISTINCT qi.quotation_id) as quotation_count,
        COALESCE(SUM(qi.boxes_decimal), 0) as quoted_boxes,
        COALESCE(SUM(qi.line_total), 0) as quoted_value,
        
        -- Stock Info
        COALESCE(cts.total_stock_boxes, 0) as current_stock,
        COALESCE(cts.total_stock_boxes * t.current_cost, 0) as stock_value
        
    FROM tiles t
    JOIN tile_sizes ts ON t.size_id = ts.id
    LEFT JOIN invoice_items ii ON t.id = ii.tile_id
    LEFT JOIN invoices i ON ii.invoice_id = i.id AND DATE(i.invoice_dt) BETWEEN ? AND ?
    LEFT JOIN quotation_items qi ON t.id = qi.tile_id
    LEFT JOIN quotations q ON qi.quotation_id = q.id AND DATE(q.quote_dt) BETWEEN ? AND ?
    LEFT JOIN current_tiles_stock cts ON t.id = cts.id
    LEFT JOIN purchase_entries_tiles pet ON t.id = pet.tile_id
    GROUP BY t.id, t.name, ts.label
    HAVING total_revenue > 0 OR quoted_value > 0
";

// Misc Items P/L Analysis
$misc_sql = "
    SELECT 
        m.id,
        m.name as item_name,
        m.unit_label as size_info,
        'misc' as item_type,
        
        -- Purchase/Cost Data
        COALESCE(m.current_cost, 0) as current_cost,
        COALESCE(AVG(pem.as_of_cost_per_unit), 0) as avg_purchase_cost,
        
        -- Sales Data from Invoices
        COUNT(DISTINCT imi.invoice_id) as invoice_count,
        COALESCE(SUM(imi.qty_units), 0) as total_units_sold,
        COALESCE(AVG(imi.rate_per_unit), 0) as avg_selling_price,
        COALESCE(SUM(imi.line_total), 0) as total_revenue,
        
        -- Cost Calculations
        COALESCE(SUM(imi.qty_units * m.current_cost), 0) as total_cost,
        COALESCE(SUM(imi.line_total) - SUM(imi.qty_units * m.current_cost), 0) as gross_profit,
        
        -- Quotation Data
        COUNT(DISTINCT qmi.quotation_id) as quotation_count,
        COALESCE(SUM(qmi.qty_units), 0) as quoted_units,
        COALESCE(SUM(qmi.line_total), 0) as quoted_value,
        
        -- Stock Info
        COALESCE(cms.total_stock_units, 0) as current_stock,
        COALESCE(cms.total_stock_units * m.current_cost, 0) as stock_value
        
    FROM misc_items m
    LEFT JOIN invoice_misc_items imi ON m.id = imi.misc_item_id
    LEFT JOIN invoices i ON imi.invoice_id = i.id AND DATE(i.invoice_dt) BETWEEN ? AND ?
    LEFT JOIN quotation_misc_items qmi ON m.id = qmi.misc_item_id
    LEFT JOIN quotations q ON qmi.quotation_id = q.id AND DATE(q.quote_dt) BETWEEN ? AND ?
    LEFT JOIN current_misc_stock cms ON m.id = cms.id
    LEFT JOIN purchase_entries_misc pem ON m.id = pem.misc_item_id
    GROUP BY m.id, m.name, m.unit_label
    HAVING total_revenue > 0 OR quoted_value > 0
";

// Execute queries
$profit_data = [];
$params = [$date_from, $date_to, $date_from, $date_to];

if ($item_type === 'all' || $item_type === 'tiles') {
    $tiles_stmt = $pdo->prepare($tiles_sql);
    $tiles_stmt->execute($params);
    $profit_data = array_merge($profit_data, $tiles_stmt->fetchAll(PDO::FETCH_ASSOC));
}

if ($item_type === 'all' || $item_type === 'misc') {
    $misc_stmt = $pdo->prepare($misc_sql);
    $misc_stmt->execute($params);
    $profit_data = array_merge($profit_data, $misc_stmt->fetchAll(PDO::FETCH_ASSOC));
}

// Calculate profit margins
foreach ($profit_data as &$item) {
    $item['profit_margin'] = $item['total_revenue'] > 0 ? 
        (($item['gross_profit'] / $item['total_revenue']) * 100) : 0;
    
    $item['quote_to_sale_ratio'] = $item['quoted_value'] > 0 ? 
        (($item['total_revenue'] / $item['quoted_value']) * 100) : 0;
        
    $item['stock_turns'] = $item['stock_value'] > 0 ? 
        ($item['total_cost'] / $item['stock_value']) : 0;
}

// Sort data
switch ($sort_by) {
    case 'total_revenue':
        usort($profit_data, function($a, $b) { return $b['total_revenue'] <=> $a['total_revenue']; });
        break;
    case 'gross_profit':
        usort($profit_data, function($a, $b) { return $b['gross_profit'] <=> $a['gross_profit']; });
        break;
    case 'quantity_sold':
        usort($profit_data, function($a, $b) { 
            $a_qty = $a['item_type'] === 'tile' ? $a['total_boxes_sold'] : $a['total_units_sold'];
            $b_qty = $b['item_type'] === 'tile' ? $b['total_boxes_sold'] : $b['total_units_sold'];
            return $b_qty <=> $a_qty;
        });
        break;
    default: // profit_margin
        usort($profit_data, function($a, $b) { return $b['profit_margin'] <=> $a['profit_margin']; });
}

// Calculate summary
$summary = [
    'total_items' => count($profit_data),
    'total_revenue' => array_sum(array_column($profit_data, 'total_revenue')),
    'total_cost' => array_sum(array_column($profit_data, 'total_cost')),
    'total_profit' => array_sum(array_column($profit_data, 'gross_profit')),
    'total_quoted' => array_sum(array_column($profit_data, 'quoted_value')),
    'total_stock_value' => array_sum(array_column($profit_data, 'stock_value'))
];

$summary['overall_margin'] = $summary['total_revenue'] > 0 ? 
    (($summary['total_profit'] / $summary['total_revenue']) * 100) : 0;

// Handle CSV export
if ($export) {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="item_profit_report_' . $date_from . '_to_' . $date_to . '.csv"');
    
    $output = fopen('php://output', 'w');
    
    fputcsv($output, [
        'Item Name', 'Type', 'Size/Unit', 'Qty Sold', 'Avg Selling Price', 'Current Cost', 
        'Total Revenue', 'Total Cost', 'Gross Profit', 'Profit Margin %', 'Quoted Value', 
        'Quote-to-Sale %', 'Current Stock', 'Stock Value'
    ]);
    
    foreach ($profit_data as $item) {
        $qty_sold = $item['item_type'] === 'tile' ? 
            number_format($item['total_boxes_sold'], 1) . ' boxes' : 
            number_format($item['total_units_sold'], 1) . ' ' . $item['size_info'];
            
        $current_stock = $item['item_type'] === 'tile' ? 
            number_format($item['current_stock'], 1) . ' boxes' : 
            number_format($item['current_stock'], 1) . ' ' . $item['size_info'];
        
        fputcsv($output, [
            $item['item_name'],
            ucfirst($item['item_type']),
            $item['size_info'],
            $qty_sold,
            number_format($item['avg_selling_price'], 2),
            number_format($item['current_cost'], 2),
            number_format($item['total_revenue'], 2),
            number_format($item['total_cost'], 2),
            number_format($item['gross_profit'], 2),
            number_format($item['profit_margin'], 1),
            number_format($item['quoted_value'], 2),
            number_format($item['quote_to_sale_ratio'], 1),
            $current_stock,
            number_format($item['stock_value'], 2)
        ]);
    }
    
    fclose($output);
    exit;
}

$page_title = "Item-wise Profit Analysis";
require_once __DIR__ . '/../includes/header.php';
?>

<style>
.profit-positive { color: #28a745; font-weight: bold; }
.profit-negative { color: #dc3545; font-weight: bold; }
.margin-excellent { background-color: #d4edda; }
.margin-good { background-color: #d1ecf1; }
.margin-average { background-color: #fff3cd; }
.margin-poor { background-color: #f8d7da; }
.metric-card { border-radius: 10px; padding: 1rem; text-align: center; margin-bottom: 1rem; }
</style>

<div class="container-fluid mt-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h2><i class="bi bi-pie-chart-fill"></i> Item-wise Profit Analysis</h2>
        <div>
            <a href="reports_dashboard.php" class="btn btn-outline-secondary">
                <i class="bi bi-arrow-left"></i> Back to Dashboard
            </a>
            <a href="?<?= http_build_query(array_merge($_GET, ['export' => 'csv'])) ?>" class="btn btn-success">
                <i class="bi bi-download"></i> Export CSV
            </a>
            <button onclick="window.print()" class="btn btn-primary">
                <i class="bi bi-printer"></i> Print Report
            </button>
        </div>
    </div>

    <!-- Summary Cards -->
    <div class="row mb-4">
        <div class="col-md-2">
            <div class="card bg-primary text-white metric-card">
                <h4><?= $summary['total_items'] ?></h4>
                <small>Items Analyzed</small>
            </div>
        </div>
        <div class="col-md-2">
            <div class="card bg-info text-white metric-card">
                <h4>₹<?= number_format($summary['total_revenue'], 0) ?></h4>
                <small>Total Revenue</small>
            </div>
        </div>
        <div class="col-md-2">
            <div class="card bg-warning text-white metric-card">
                <h4>₹<?= number_format($summary['total_cost'], 0) ?></h4>
                <small>Total Cost</small>
            </div>
        </div>
        <div class="col-md-2">
            <div class="card bg-<?= $summary['total_profit'] >= 0 ? 'success' : 'danger' ?> text-white metric-card">
                <h4>₹<?= number_format($summary['total_profit'], 0) ?></h4>
                <small>Total Profit</small>
            </div>
        </div>
        <div class="col-md-2">
            <div class="card bg-secondary text-white metric-card">
                <h4>₹<?= number_format($summary['total_quoted'], 0) ?></h4>
                <small>Quoted Value</small>
            </div>
        </div>
        <div class="col-md-2">
            <div class="card bg-dark text-white metric-card">
                <h4><?= number_format($summary['overall_margin'], 1) ?>%</h4>
                <small>Overall Margin</small>
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
                    <label class="form-label">Item Type</label>
                    <select class="form-select" name="item_type">
                        <option value="all" <?= $item_type === 'all' ? 'selected' : '' ?>>All Items</option>
                        <option value="tiles" <?= $item_type === 'tiles' ? 'selected' : '' ?>>Tiles Only</option>
                        <option value="misc" <?= $item_type === 'misc' ? 'selected' : '' ?>>Misc Items Only</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label">Sort By</label>
                    <select class="form-select" name="sort_by">
                        <option value="profit_margin" <?= $sort_by === 'profit_margin' ? 'selected' : '' ?>>Profit Margin</option>
                        <option value="total_revenue" <?= $sort_by === 'total_revenue' ? 'selected' : '' ?>>Total Revenue</option>
                        <option value="gross_profit" <?= $sort_by === 'gross_profit' ? 'selected' : '' ?>>Gross Profit</option>
                        <option value="quantity_sold" <?= $sort_by === 'quantity_sold' ? 'selected' : '' ?>>Quantity Sold</option>
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

    <!-- Item Profit Data Table -->
    <div class="card">
        <div class="card-header">
            <h5><i class="bi bi-table"></i> Item-wise Profit Analysis (<?= $date_from ?> to <?= $date_to ?>)</h5>
        </div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-hover table-sm">
                    <thead class="table-dark">
                        <tr>
                            <th>Item Name</th>
                            <th>Type</th>
                            <th>Size/Unit</th>
                            <th>Qty Sold</th>
                            <th>Avg Price</th>
                            <th>Current Cost</th>
                            <th>Revenue</th>
                            <th>Cost</th>
                            <th>Gross Profit</th>
                            <th>Margin %</th>
                            <th>Quoted Value</th>
                            <th>Quote→Sale %</th>
                            <th>Stock</th>
                            <?php if ($can_view_pl): ?>
                                <th>Stock Value</th>
                                <th>Stock Turns</th>
                            <?php endif; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($profit_data)): ?>
                            <tr>
                                <td colspan="<?= $can_view_pl ? '15' : '13' ?>" class="text-center text-muted">
                                    No profit data found for the selected criteria
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($profit_data as $item): ?>
                                <?php 
                                $margin = $item['profit_margin'];
                                $row_class = '';
                                if ($margin >= 30) $row_class = 'margin-excellent';
                                elseif ($margin >= 20) $row_class = 'margin-good';
                                elseif ($margin >= 10) $row_class = 'margin-average';
                                elseif ($margin < 0) $row_class = 'margin-poor';
                                
                                $qty_sold = $item['item_type'] === 'tile' ? 
                                    number_format($item['total_boxes_sold'], 1) . ' boxes' : 
                                    number_format($item['total_units_sold'], 1) . ' ' . $item['size_info'];
                                    
                                $current_stock = $item['item_type'] === 'tile' ? 
                                    number_format($item['current_stock'], 1) . ' boxes' : 
                                    number_format($item['current_stock'], 1) . ' ' . $item['size_info'];
                                ?>
                                <tr class="<?= $row_class ?>">
                                    <td><strong><?= h($item['item_name']) ?></strong></td>
                                    <td>
                                        <span class="badge bg-<?= $item['item_type'] === 'tile' ? 'primary' : 'info' ?>">
                                            <?= ucfirst($item['item_type']) ?>
                                        </span>
                                    </td>
                                    <td><?= h($item['size_info']) ?></td>
                                    <td><?= $qty_sold ?></td>
                                    <td>₹<?= number_format($item['avg_selling_price'], 2) ?></td>
                                    <td>₹<?= number_format($item['current_cost'], 2) ?></td>
                                    <td>₹<?= number_format($item['total_revenue'], 2) ?></td>
                                    <td>₹<?= number_format($item['total_cost'], 2) ?></td>
                                    <td class="<?= $item['gross_profit'] >= 0 ? 'profit-positive' : 'profit-negative' ?>">
                                        ₹<?= number_format($item['gross_profit'], 2) ?>
                                    </td>
                                    <td class="<?= $margin >= 20 ? 'profit-positive' : ($margin >= 0 ? 'text-warning' : 'profit-negative') ?>">
                                        <strong><?= number_format($margin, 1) ?>%</strong>
                                    </td>
                                    <td>₹<?= number_format($item['quoted_value'], 2) ?></td>
                                    <td class="text-info"><?= number_format($item['quote_to_sale_ratio'], 1) ?>%</td>
                                    <td><?= $current_stock ?></td>
                                    <?php if ($can_view_pl): ?>
                                        <td>₹<?= number_format($item['stock_value'], 2) ?></td>
                                        <td class="text-secondary"><?= number_format($item['stock_turns'], 2) ?>x</td>
                                    <?php endif; ?>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Performance Insights -->
    <div class="card mt-4">
        <div class="card-body">
            <h6><i class="bi bi-lightbulb"></i> Performance Insights:</h6>
            <div class="row">
                <div class="col-md-4">
                    <strong>Top Performers:</strong>
                    <?php 
                    $top_performers = array_filter($profit_data, function($item) { return $item['profit_margin'] >= 30; });
                    ?>
                    <p class="text-success"><?= count($top_performers) ?> items with 30%+ margin</p>
                </div>
                <div class="col-md-4">
                    <strong>Needs Attention:</strong>
                    <?php 
                    $poor_performers = array_filter($profit_data, function($item) { return $item['profit_margin'] < 10; });
                    ?>
                    <p class="text-warning"><?= count($poor_performers) ?> items with <10% margin</p>
                </div>
                <div class="col-md-4">
                    <strong>Loss Making:</strong>
                    <?php 
                    $loss_making = array_filter($profit_data, function($item) { return $item['profit_margin'] < 0; });
                    ?>
                    <p class="text-danger"><?= count($loss_making) ?> items making losses</p>
                </div>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>