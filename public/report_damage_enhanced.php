<?php
// public/report_damage_enhanced.php - Enhanced Damage Report with Latest DB Schema
require_once __DIR__ . '/../includes/simple_auth.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/admin_functions.php';

auth_require_login();

$pdo = Database::pdo();
$user_id = $_SESSION['user_id'] ?? 1;

// Check permissions - admin access for damage reports
$user_stmt = $pdo->prepare("SELECT * FROM users_simple WHERE id = ?");
$user_stmt->execute([$user_id]);
$user = $user_stmt->fetch(PDO::FETCH_ASSOC);

if (($user['role'] ?? '') !== 'admin') {
    $_SESSION['error'] = 'You do not have permission to access damage reports';
    header('Location: /reports_dashboard_new.php');
    exit;
}

// Date range handling with presets
$preset = $_GET['preset'] ?? '';
$date_from = $_GET['date_from'] ?? '';
$date_to = $_GET['date_to'] ?? '';
$supplier_filter = trim($_GET['supplier'] ?? '');
$damage_threshold = (float)($_GET['damage_threshold'] ?? 0);

// Handle presets
switch ($preset) {
    case 'today':
        $date_from = $date_to = date('Y-m-d');
        break;
    case 'this_week':
        $date_from = date('Y-m-d', strtotime('monday this week'));
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
    case 'last_3_months':
        $date_from = date('Y-m-01', strtotime('-2 months'));
        $date_to = date('Y-m-d');
        break;
    default:
        if (!$date_from) $date_from = date('Y-m-d', strtotime('-30 days'));
        if (!$date_to) $date_to = date('Y-m-d');
        break;
}

// Helper function to check if column exists
function column_exists_enhanced($pdo, $table, $column) {
    try {
        $stmt = $pdo->prepare("PRAGMA table_info($table)");
        $stmt->execute();
        $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($columns as $col) {
            if (strcasecmp($col['name'], $column) === 0) {
                return true;
            }
        }
        return false;
    } catch (Exception $e) {
        return false;
    }
}

// Build damage report for tiles from purchase entries
$tile_damage_sql = "
    SELECT 
        pet.id,
        pet.purchase_date,
        pet.supplier_name,
        pet.invoice_number,
        t.name as tile_name,
        t.size_label,
        pet.total_boxes,
        pet.damage_percentage,
        (pet.total_boxes * pet.damage_percentage / 100) as damage_boxes,
        pet.usable_boxes,
        pet.cost_per_box,
        pet.total_cost,
        pet.transport_cost,
        pet.final_cost,
        (pet.total_boxes * pet.damage_percentage / 100 * pet.cost_per_box) as damage_cost_direct,
        ((pet.total_cost + pet.transport_cost) * pet.damage_percentage / 100) as damage_cost_total,
        pet.notes
    FROM purchase_entries_tiles pet
    JOIN tiles t ON pet.tile_id = t.id
    WHERE DATE(pet.purchase_date) BETWEEN ? AND ?
    " . ($supplier_filter ? "AND pet.supplier_name LIKE ?" : "") . "
    " . ($damage_threshold > 0 ? "AND pet.damage_percentage >= ?" : "") . "
    ORDER BY pet.purchase_date DESC, pet.damage_percentage DESC
";

// Build damage report for misc items from purchase entries
$misc_damage_sql = "
    SELECT 
        pem.id,
        pem.purchase_date,
        pem.supplier_name,
        pem.invoice_number,
        m.name as item_name,
        m.unit_label,
        pem.total_quantity,
        pem.damage_percentage,
        (pem.total_quantity * pem.damage_percentage / 100) as damage_quantity,
        pem.usable_quantity,
        pem.cost_per_unit,
        pem.total_cost,
        pem.transport_cost,
        pem.final_cost,
        (pem.total_quantity * pem.damage_percentage / 100 * pem.cost_per_unit) as damage_cost_direct,
        ((pem.total_cost + pem.transport_cost) * pem.damage_percentage / 100) as damage_cost_total,
        pem.notes
    FROM purchase_entries_misc pem
    JOIN misc_items m ON pem.misc_item_id = m.id
    WHERE DATE(pem.purchase_date) BETWEEN ? AND ?
    " . ($supplier_filter ? "AND pem.supplier_name LIKE ?" : "") . "
    " . ($damage_threshold > 0 ? "AND pem.damage_percentage >= ?" : "") . "
    ORDER BY pem.purchase_date DESC, pem.damage_percentage DESC
";

// Execute queries with proper parameter binding
$params = [$date_from, $date_to];
if ($supplier_filter) {
    $params[] = "%$supplier_filter%";
}
if ($damage_threshold > 0) {
    $params[] = $damage_threshold;
}

$tile_damages = [];
$misc_damages = [];

try {
    $tile_stmt = $pdo->prepare($tile_damage_sql);
    $tile_stmt->execute($params);
    $tile_damages = $tile_stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    error_log("Tile damage query error: " . $e->getMessage());
}

try {
    $misc_stmt = $pdo->prepare($misc_damage_sql);
    $misc_stmt->execute($params);
    $misc_damages = $misc_stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    error_log("Misc damage query error: " . $e->getMessage());
}

// Calculate summary statistics
$summary_stats = [
    'total_damage_cost' => 0,
    'tile_damage_cost' => 0,
    'misc_damage_cost' => 0,
    'total_entries' => count($tile_damages) + count($misc_damages),
    'avg_damage_percentage' => 0,
    'highest_damage_percentage' => 0,
    'suppliers_with_damage' => []
];

$total_damage_percentage = 0;
$entry_count = 0;

foreach ($tile_damages as $damage) {
    $summary_stats['tile_damage_cost'] += $damage['damage_cost_total'];
    $total_damage_percentage += $damage['damage_percentage'];
    $entry_count++;
    if ($damage['damage_percentage'] > $summary_stats['highest_damage_percentage']) {
        $summary_stats['highest_damage_percentage'] = $damage['damage_percentage'];
    }
    if ($damage['supplier_name'] && !in_array($damage['supplier_name'], $summary_stats['suppliers_with_damage'])) {
        $summary_stats['suppliers_with_damage'][] = $damage['supplier_name'];
    }
}

foreach ($misc_damages as $damage) {
    $summary_stats['misc_damage_cost'] += $damage['damage_cost_total'];
    $total_damage_percentage += $damage['damage_percentage'];
    $entry_count++;
    if ($damage['damage_percentage'] > $summary_stats['highest_damage_percentage']) {
        $summary_stats['highest_damage_percentage'] = $damage['damage_percentage'];
    }
    if ($damage['supplier_name'] && !in_array($damage['supplier_name'], $summary_stats['suppliers_with_damage'])) {
        $summary_stats['suppliers_with_damage'][] = $damage['supplier_name'];
    }
}

$summary_stats['total_damage_cost'] = $summary_stats['tile_damage_cost'] + $summary_stats['misc_damage_cost'];
$summary_stats['avg_damage_percentage'] = $entry_count > 0 ? ($total_damage_percentage / $entry_count) : 0;

// Get supplier performance data
$supplier_performance_sql = "
    SELECT 
        supplier_name,
        COUNT(*) as total_shipments,
        AVG(damage_percentage) as avg_damage,
        MAX(damage_percentage) as max_damage,
        SUM(total_cost + transport_cost) as total_value,
        SUM((total_cost + transport_cost) * damage_percentage / 100) as total_damage_cost
    FROM (
        SELECT supplier_name, damage_percentage, total_cost, transport_cost
        FROM purchase_entries_tiles
        WHERE DATE(purchase_date) BETWEEN ? AND ?
        " . ($supplier_filter ? "AND supplier_name LIKE ?" : "") . "
        UNION ALL
        SELECT supplier_name, damage_percentage, total_cost, transport_cost  
        FROM purchase_entries_misc
        WHERE DATE(purchase_date) BETWEEN ? AND ?
        " . ($supplier_filter ? "AND supplier_name LIKE ?" : "") . "
    ) combined
    WHERE supplier_name IS NOT NULL AND supplier_name != ''
    GROUP BY supplier_name
    HAVING COUNT(*) > 0
    ORDER BY avg_damage DESC, total_damage_cost DESC
";

$supplier_params = [$date_from, $date_to, $date_from, $date_to];
if ($supplier_filter) {
    $supplier_params = [$date_from, $date_to, "%$supplier_filter%", $date_from, $date_to, "%$supplier_filter%"];
}

$supplier_performance = [];
try {
    $supplier_stmt = $pdo->prepare($supplier_performance_sql);
    $supplier_stmt->execute($supplier_params);
    $supplier_performance = $supplier_stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    error_log("Supplier performance query error: " . $e->getMessage());
}

// Get all suppliers for filter dropdown
$all_suppliers_sql = "
    SELECT DISTINCT supplier_name 
    FROM (
        SELECT supplier_name FROM purchase_entries_tiles WHERE supplier_name IS NOT NULL AND supplier_name != ''
        UNION 
        SELECT supplier_name FROM purchase_entries_misc WHERE supplier_name IS NOT NULL AND supplier_name != ''
    ) suppliers
    ORDER BY supplier_name
";
$all_suppliers = $pdo->query($all_suppliers_sql)->fetchAll(PDO::FETCH_COLUMN);

$page_title = "Enhanced Damage Report";
require_once __DIR__ . '/../includes/header.php';
?>

<style>
.damage-high { background-color: rgba(220, 53, 69, 0.1); }
.damage-medium { background-color: rgba(255, 193, 7, 0.1); }
.damage-low { background-color: rgba(40, 167, 69, 0.1); }
.supplier-card {
    border-left: 4px solid #007bff;
    transition: all 0.3s ease;
}
.supplier-card:hover {
    box-shadow: 0 4px 15px rgba(0,0,0,0.1);
}
.summary-card {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
    border-radius: 15px;
    padding: 20px;
    margin-bottom: 20px;
}
.metric-box {
    background: rgba(255,255,255,0.1);
    border-radius: 10px;
    padding: 15px;
    text-align: center;
}
</style>

<div class="container-fluid mt-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h2><i class="bi bi-exclamation-triangle text-danger"></i> Enhanced Damage Report</h2>
            <p class="text-muted mb-0">
                Period: <?= date('M j, Y', strtotime($date_from)) ?> to <?= date('M j, Y', strtotime($date_to)) ?>
                <?= $supplier_filter ? " | Supplier: $supplier_filter" : "" ?>
                <?= $damage_threshold > 0 ? " | Min Damage: {$damage_threshold}%" : "" ?>
            </p>
        </div>
        <a href="reports_dashboard_new.php" class="btn btn-outline-secondary">
            <i class="bi bi-arrow-left"></i> Back to Reports
        </a>
    </div>

    <!-- Filters -->
    <div class="card mb-4">
        <div class="card-header">
            <h5><i class="bi bi-funnel"></i> Filters & Settings</h5>
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
                        <option value="this_week" <?= $preset === 'this_week' ? 'selected' : '' ?>>This Week</option>
                        <option value="this_month" <?= $preset === 'this_month' ? 'selected' : '' ?>>This Month</option>
                        <option value="last_month" <?= $preset === 'last_month' ? 'selected' : '' ?>>Last Month</option>
                        <option value="last_3_months" <?= $preset === 'last_3_months' ? 'selected' : '' ?>>Last 3 Months</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label">Supplier</label>
                    <select class="form-select" name="supplier">
                        <option value="">All Suppliers</option>
                        <?php foreach ($all_suppliers as $supplier): ?>
                            <option value="<?= h($supplier) ?>" <?= $supplier_filter === $supplier ? 'selected' : '' ?>>
                                <?= h($supplier) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label">Min Damage %</label>
                    <input type="number" class="form-control" name="damage_threshold" 
                           value="<?= $damage_threshold ?>" min="0" max="100" step="0.1" 
                           placeholder="e.g., 5.0">
                </div>
                <div class="col-md-2 d-flex align-items-end">
                    <button type="submit" class="btn btn-primary me-2">
                        <i class="bi bi-search"></i> Filter
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
        <div class="col-md-3">
            <div class="metric-box">
                <h6>Total Damage Cost</h6>
                <h3 class="text-danger">₹<?= number_format($summary_stats['total_damage_cost'], 2) ?></h3>
                <small><?= $summary_stats['total_entries'] ?> entries</small>
            </div>
        </div>
        <div class="col-md-3">
            <div class="metric-box">
                <h6>Average Damage %</h6>
                <h3><?= number_format($summary_stats['avg_damage_percentage'], 1) ?>%</h3>
                <small>Across all shipments</small>
            </div>
        </div>
        <div class="col-md-3">
            <div class="metric-box">
                <h6>Highest Damage %</h6>
                <h3 class="text-warning"><?= number_format($summary_stats['highest_damage_percentage'], 1) ?>%</h3>
                <small>Single shipment</small>
            </div>
        </div>
        <div class="col-md-3">
            <div class="metric-box">
                <h6>Affected Suppliers</h6>
                <h3><?= count($summary_stats['suppliers_with_damage']) ?></h3>
                <small>With damage reports</small>
            </div>
        </div>
    </div>

    <!-- Supplier Performance -->
    <?php if (!empty($supplier_performance)): ?>
    <div class="card mb-4">
        <div class="card-header">
            <h5><i class="bi bi-truck"></i> Supplier Performance Analysis</h5>
        </div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-hover">
                    <thead class="table-light">
                        <tr>
                            <th>Supplier</th>
                            <th>Total Shipments</th>
                            <th>Avg Damage %</th>
                            <th>Max Damage %</th>
                            <th>Total Value</th>
                            <th>Damage Cost</th>
                            <th>Damage Rate</th>
                            <th>Performance</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($supplier_performance as $supplier): ?>
                            <?php 
                            $damage_rate = $supplier['total_value'] > 0 ? ($supplier['total_damage_cost'] / $supplier['total_value'] * 100) : 0;
                            $performance_class = $supplier['avg_damage'] <= 2 ? 'success' : 
                                               ($supplier['avg_damage'] <= 5 ? 'warning' : 'danger');
                            ?>
                            <tr>
                                <td><strong><?= h($supplier['supplier_name']) ?></strong></td>
                                <td><?= $supplier['total_shipments'] ?></td>
                                <td><?= number_format($supplier['avg_damage'], 2) ?>%</td>
                                <td><?= number_format($supplier['max_damage'], 2) ?>%</td>
                                <td>₹<?= number_format($supplier['total_value'], 2) ?></td>
                                <td class="text-danger">₹<?= number_format($supplier['total_damage_cost'], 2) ?></td>
                                <td><?= number_format($damage_rate, 2) ?>%</td>
                                <td>
                                    <span class="badge bg-<?= $performance_class ?>">
                                        <?= $supplier['avg_damage'] <= 2 ? 'Excellent' : 
                                           ($supplier['avg_damage'] <= 5 ? 'Average' : 'Poor') ?>
                                    </span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Tile Damage Details -->
    <?php if (!empty($tile_damages)): ?>
    <div class="card mb-4">
        <div class="card-header">
            <h5><i class="bi bi-grid-3x3-gap"></i> Tile Damage Details (<?= count($tile_damages) ?> entries)</h5>
        </div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-sm table-hover">
                    <thead class="table-light">
                        <tr>
                            <th>Date</th>
                            <th>Supplier</th>
                            <th>Invoice #</th>
                            <th>Tile</th>
                            <th>Total Boxes</th>
                            <th>Damage %</th>
                            <th>Damage Boxes</th>
                            <th>Usable Boxes</th>
                            <th>Cost/Box</th>
                            <th>Damage Cost</th>
                            <th>Notes</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($tile_damages as $damage): ?>
                            <?php 
                            $damage_class = $damage['damage_percentage'] >= 10 ? 'damage-high' : 
                                          ($damage['damage_percentage'] >= 5 ? 'damage-medium' : 'damage-low');
                            ?>
                            <tr class="<?= $damage_class ?>">
                                <td><?= date('M j, Y', strtotime($damage['purchase_date'])) ?></td>
                                <td><?= h($damage['supplier_name']) ?></td>
                                <td><?= h($damage['invoice_number']) ?></td>
                                <td>
                                    <strong><?= h($damage['tile_name']) ?></strong><br>
                                    <small class="text-muted"><?= h($damage['size_label']) ?></small>
                                </td>
                                <td><?= number_format($damage['total_boxes'], 1) ?></td>
                                <td>
                                    <span class="badge bg-<?= $damage['damage_percentage'] >= 10 ? 'danger' : 
                                                           ($damage['damage_percentage'] >= 5 ? 'warning' : 'secondary') ?>">
                                        <?= number_format($damage['damage_percentage'], 1) ?>%
                                    </span>
                                </td>
                                <td class="text-danger"><?= number_format($damage['damage_boxes'], 1) ?></td>
                                <td class="text-success"><?= number_format($damage['usable_boxes'], 1) ?></td>
                                <td>₹<?= number_format($damage['cost_per_box'], 2) ?></td>
                                <td class="text-danger">
                                    <strong>₹<?= number_format($damage['damage_cost_total'], 2) ?></strong>
                                </td>
                                <td>
                                    <small><?= h($damage['notes']) ?></small>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot class="table-secondary">
                        <tr>
                            <th colspan="6">TILES TOTAL</th>
                            <th><?= number_format(array_sum(array_column($tile_damages, 'damage_boxes')), 1) ?></th>
                            <th><?= number_format(array_sum(array_column($tile_damages, 'usable_boxes')), 1) ?></th>
                            <th>-</th>
                            <th class="text-danger">
                                <strong>₹<?= number_format($summary_stats['tile_damage_cost'], 2) ?></strong>
                            </th>
                            <th>-</th>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Misc Items Damage Details -->
    <?php if (!empty($misc_damages)): ?>
    <div class="card mb-4">
        <div class="card-header">
            <h5><i class="bi bi-box"></i> Misc Items Damage Details (<?= count($misc_damages) ?> entries)</h5>
        </div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-sm table-hover">
                    <thead class="table-light">
                        <tr>
                            <th>Date</th>
                            <th>Supplier</th>
                            <th>Invoice #</th>
                            <th>Item</th>
                            <th>Total Qty</th>
                            <th>Damage %</th>
                            <th>Damage Qty</th>
                            <th>Usable Qty</th>
                            <th>Cost/Unit</th>
                            <th>Damage Cost</th>
                            <th>Notes</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($misc_damages as $damage): ?>
                            <?php 
                            $damage_class = $damage['damage_percentage'] >= 10 ? 'damage-high' : 
                                          ($damage['damage_percentage'] >= 5 ? 'damage-medium' : 'damage-low');
                            ?>
                            <tr class="<?= $damage_class ?>">
                                <td><?= date('M j, Y', strtotime($damage['purchase_date'])) ?></td>
                                <td><?= h($damage['supplier_name']) ?></td>
                                <td><?= h($damage['invoice_number']) ?></td>
                                <td>
                                    <strong><?= h($damage['item_name']) ?></strong><br>
                                    <small class="text-muted"><?= h($damage['unit_label']) ?></small>
                                </td>
                                <td><?= number_format($damage['total_quantity'], 1) ?></td>
                                <td>
                                    <span class="badge bg-<?= $damage['damage_percentage'] >= 10 ? 'danger' : 
                                                           ($damage['damage_percentage'] >= 5 ? 'warning' : 'secondary') ?>">
                                        <?= number_format($damage['damage_percentage'], 1) ?>%
                                    </span>
                                </td>
                                <td class="text-danger"><?= number_format($damage['damage_quantity'], 1) ?></td>
                                <td class="text-success"><?= number_format($damage['usable_quantity'], 1) ?></td>
                                <td>₹<?= number_format($damage['cost_per_unit'], 2) ?></td>
                                <td class="text-danger">
                                    <strong>₹<?= number_format($damage['damage_cost_total'], 2) ?></strong>
                                </td>
                                <td>
                                    <small><?= h($damage['notes']) ?></small>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot class="table-secondary">
                        <tr>
                            <th colspan="6">MISC ITEMS TOTAL</th>
                            <th><?= number_format(array_sum(array_column($misc_damages, 'damage_quantity')), 1) ?></th>
                            <th><?= number_format(array_sum(array_column($misc_damages, 'usable_quantity')), 1) ?></th>
                            <th>-</th>
                            <th class="text-danger">
                                <strong>₹<?= number_format($summary_stats['misc_damage_cost'], 2) ?></strong>
                            </th>
                            <th>-</th>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- No Data Message -->
    <?php if (empty($tile_damages) && empty($misc_damages)): ?>
    <div class="card">
        <div class="card-body text-center py-5">
            <i class="bi bi-info-circle-fill text-info" style="font-size: 3rem;"></i>
            <h4 class="mt-3">No Damage Data Found</h4>
            <p class="text-muted">
                No damage entries found for the selected period and filters.<br>
                Try adjusting the date range or removing filters.
            </p>
            <a href="?preset=last_3_months" class="btn btn-primary">
                <i class="bi bi-calendar-range"></i> View Last 3 Months
            </a>
        </div>
    </div>
    <?php endif; ?>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Add tooltips for damage percentage badges
    document.querySelectorAll('.badge').forEach(badge => {
        const percentage = parseFloat(badge.textContent);
        if (!isNaN(percentage)) {
            let tooltip = '';
            if (percentage >= 10) tooltip = 'High damage - requires attention';
            else if (percentage >= 5) tooltip = 'Moderate damage - monitor closely';
            else tooltip = 'Low damage - within acceptable range';
            
            badge.setAttribute('title', tooltip);
            badge.setAttribute('data-bs-toggle', 'tooltip');
        }
    });
    
    // Initialize tooltips if Bootstrap is available
    if (typeof bootstrap !== 'undefined') {
        var tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
        var tooltipList = tooltipTriggerList.map(function (tooltipTriggerEl) {
            return new bootstrap.Tooltip(tooltipTriggerEl);
        });
    }
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>