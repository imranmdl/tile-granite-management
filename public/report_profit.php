<?php
// public/report_profit.php - Item Profit Report (FR-RP-03)
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
    safe_redirect('reports_dashboard.php');
}

if (!$can_view_pl) {
    $_SESSION['error'] = 'You do not have permission to view profit/loss data';
    safe_redirect('reports_dashboard.php');
}

// Handle filters
$date_from = $_GET['date_from'] ?? date('Y-m-01');
$date_to = $_GET['date_to'] ?? date('Y-m-d');
$item_type = $_GET['item_type'] ?? 'all'; // all, tiles, misc
$min_margin = $_GET['min_margin'] ?? '';
$sort_by = $_GET['sort_by'] ?? 'profit_margin'; // profit_margin, total_profit, quantity_sold

// Get tiles profit data
$tiles_sql = "
    SELECT 
        t.id,
        t.name as item_name,
        ts.label as size_info,
        t.current_cost,
        COUNT(DISTINCT ii.invoice_id) as invoices_count,
        SUM(ii.boxes_decimal) as total_quantity_sold,
        AVG(ii.rate_per_box) as avg_selling_price,
        SUM(ii.line_total) as total_revenue,
        SUM(ii.boxes_decimal * t.current_cost) as total_cost,
        SUM(ii.line_total) - SUM(ii.boxes_decimal * t.current_cost) as total_profit,
        CASE 
            WHEN SUM(ii.line_total) > 0 
            THEN ((SUM(ii.line_total) - SUM(ii.boxes_decimal * t.current_cost)) / SUM(ii.line_total)) * 100 
            ELSE 0 
        END as profit_margin_percentage,
        'tile' as item_type
    FROM tiles t
    JOIN tile_sizes ts ON t.size_id = ts.id
    JOIN invoice_items ii ON t.id = ii.tile_id
    JOIN invoices i ON ii.invoice_id = i.id
    WHERE DATE(i.invoice_dt) BETWEEN ? AND ?
    GROUP BY t.id, t.name, ts.label, t.current_cost
    HAVING 1=1
";

$params = [$date_from, $date_to];

if ($min_margin !== '') {
    $tiles_sql .= " AND profit_margin_percentage >= ?";
    $params[] = (float)$min_margin;
}

// Get misc items profit data
$misc_sql = "
    SELECT 
        m.id,
        m.name as item_name,
        m.unit_label as size_info,
        m.current_cost,
        COUNT(DISTINCT imi.invoice_id) as invoices_count,
        SUM(imi.qty_units) as total_quantity_sold,
        AVG(imi.rate_per_unit) as avg_selling_price,
        SUM(imi.line_total) as total_revenue,
        SUM(imi.qty_units * m.current_cost) as total_cost,
        SUM(imi.line_total) - SUM(imi.qty_units * m.current_cost) as total_profit,
        CASE 
            WHEN SUM(imi.line_total) > 0 
            THEN ((SUM(imi.line_total) - SUM(imi.qty_units * m.current_cost)) / SUM(imi.line_total)) * 100 
            ELSE 0 
        END as profit_margin_percentage,
        'misc' as item_type
    FROM misc_items m
    JOIN invoice_misc_items imi ON m.id = imi.misc_item_id
    JOIN invoices i ON imi.invoice_id = i.id
    WHERE DATE(i.invoice_dt) BETWEEN ? AND ?
    GROUP BY m.id, m.name, m.unit_label, m.current_cost
    HAVING 1=1
";

$misc_params = [$date_from, $date_to];

if ($min_margin !== '') {
    $misc_sql .= " AND profit_margin_percentage >= ?";
    $misc_params[] = (float)$min_margin;
}

// Combine results based on item_type filter
$profit_data = [];

if ($item_type === 'all' || $item_type === 'tiles') {
    $tiles_stmt = $pdo->prepare($tiles_sql);
    $tiles_stmt->execute($params);
    $profit_data = array_merge($profit_data, $tiles_stmt->fetchAll(PDO::FETCH_ASSOC));
}

if ($item_type === 'all' || $item_type === 'misc') {
    $misc_stmt = $pdo->prepare($misc_sql);
    $misc_stmt->execute($misc_params);
    $profit_data = array_merge($profit_data, $misc_stmt->fetchAll(PDO::FETCH_ASSOC));
}

// Sort data
switch ($sort_by) {
    case 'total_profit':
        usort($profit_data, function($a, $b) {
            return $b['total_profit'] <=> $a['total_profit'];
        });
        break;
    case 'quantity_sold':
        usort($profit_data, function($a, $b) {
            return $b['total_quantity_sold'] <=> $a['total_quantity_sold'];
        });
        break;
    default: // profit_margin
        usort($profit_data, function($a, $b) {
            return $b['profit_margin_percentage'] <=> $a['profit_margin_percentage'];
        });
}

// Calculate summary
$total_revenue = array_sum(array_column($profit_data, 'total_revenue'));
$total_cost = array_sum(array_column($profit_data, 'total_cost'));
$total_profit = $total_revenue - $total_cost;
$overall_margin = $total_revenue > 0 ? (($total_profit / $total_revenue) * 100) : 0;

$page_title = "Item Profit Report";
require_once __DIR__ . '/../includes/header.php';
?>

<style>
.profit-positive { color: #28a745; }
.profit-negative { color: #dc3545; }
.profit-neutral { color: #6c757d; }
.margin-excellent { background-color: #d4edda; }
.margin-good { background-color: #d1ecf1; }
.margin-average { background-color: #fff3cd; }
.margin-poor { background-color: #f8d7da; }
</style>

<div class="container-fluid mt-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h2><i class="bi bi-pie-chart"></i> Item Profit Report</h2>
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
        <div class="col-md-3">
            <div class="card bg-primary text-white">
                <div class="card-body text-center">
                    <h4>₹<?= number_format($total_revenue, 0) ?></h4>
                    <small>Total Revenue</small>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card bg-info text-white">
                <div class="card-body text-center">
                    <h4>₹<?= number_format($total_cost, 0) ?></h4>
                    <small>Total Cost</small>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card bg-<?= $total_profit >= 0 ? 'success' : 'danger' ?> text-white">
                <div class="card-body text-center">
                    <h4>₹<?= number_format($total_profit, 0) ?></h4>
                    <small>Total Profit</small>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card bg-<?= $overall_margin >= 20 ? 'success' : ($overall_margin >= 10 ? 'warning' : 'danger') ?> text-white">
                <div class="card-body text-center">
                    <h4><?= number_format($overall_margin, 1) ?>%</h4>
                    <small>Overall Margin</small>
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
                    <label class="form-label">Item Type</label>
                    <select class="form-select" name="item_type">
                        <option value="all" <?= $item_type === 'all' ? 'selected' : '' ?>>All Items</option>
                        <option value="tiles" <?= $item_type === 'tiles' ? 'selected' : '' ?>>Tiles Only</option>
                        <option value="misc" <?= $item_type === 'misc' ? 'selected' : '' ?>>Misc Items Only</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label">Min Margin %</label>
                    <input type="number" class="form-control" name="min_margin" value="<?= h($min_margin) ?>" 
                           placeholder="e.g., 10" step="0.1">
                </div>
                <div class="col-md-2">
                    <label class="form-label">Sort By</label>
                    <select class="form-select" name="sort_by">
                        <option value="profit_margin" <?= $sort_by === 'profit_margin' ? 'selected' : '' ?>>Profit Margin</option>
                        <option value="total_profit" <?= $sort_by === 'total_profit' ? 'selected' : '' ?>>Total Profit</option>
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

    <!-- Profit Data Table -->
    <div class="card">
        <div class="card-header">
            <h5><i class="bi bi-table"></i> Item-wise Profit Analysis</h5>
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
                            <th>Avg Selling Price</th>
                            <th>Current Cost</th>
                            <th>Total Revenue</th>
                            <th>Total Cost</th>
                            <th>Total Profit</th>
                            <th>Profit Margin %</th>
                            <th>Invoices</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($profit_data)): ?>
                            <tr>
                                <td colspan="11" class="text-center text-muted">
                                    No profit data found for the selected criteria
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($profit_data as $item): ?>
                                <?php 
                                $margin = $item['profit_margin_percentage'];
                                $profit = $item['total_profit'];
                                
                                // Determine row class based on margin
                                $row_class = '';
                                if ($margin >= 30) $row_class = 'margin-excellent';
                                elseif ($margin >= 20) $row_class = 'margin-good';
                                elseif ($margin >= 10) $row_class = 'margin-average';
                                elseif ($margin < 0) $row_class = 'margin-poor';
                                ?>
                                <tr class="<?= $row_class ?>">
                                    <td><strong><?= h($item['item_name']) ?></strong></td>
                                    <td>
                                        <span class="badge bg-<?= $item['item_type'] === 'tile' ? 'primary' : 'info' ?>">
                                            <?= ucfirst($item['item_type']) ?>
                                        </span>
                                    </td>
                                    <td><?= h($item['size_info']) ?></td>
                                    <td><?= number_format($item['total_quantity_sold'], 1) ?></td>
                                    <td>₹<?= number_format($item['avg_selling_price'], 2) ?></td>
                                    <td>₹<?= number_format($item['current_cost'], 2) ?></td>
                                    <td>₹<?= number_format($item['total_revenue'], 2) ?></td>
                                    <td>₹<?= number_format($item['total_cost'], 2) ?></td>
                                    <td class="<?= $profit >= 0 ? 'profit-positive' : 'profit-negative' ?>">
                                        <strong>₹<?= number_format($profit, 2) ?></strong>
                                    </td>
                                    <td class="<?= $margin >= 20 ? 'profit-positive' : ($margin >= 0 ? 'profit-neutral' : 'profit-negative') ?>">
                                        <strong><?= number_format($margin, 1) ?>%</strong>
                                    </td>
                                    <td><?= number_format($item['invoices_count']) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Margin Legend -->
    <div class="card mt-4">
        <div class="card-body">
            <h6>Profit Margin Color Coding:</h6>
            <div class="row">
                <div class="col-md-3">
                    <div class="p-2 margin-excellent rounded">
                        <strong>Excellent:</strong> 30%+ margin
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="p-2 margin-good rounded">
                        <strong>Good:</strong> 20-30% margin
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="p-2 margin-average rounded">
                        <strong>Average:</strong> 10-20% margin
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="p-2 margin-poor rounded">
                        <strong>Poor:</strong> Below 10% or negative
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>