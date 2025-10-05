<?php
// public/report_inventory_enhanced_final.php - Enhanced Inventory Report (Fixed Schema)
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
    $_SESSION['error'] = 'You do not have permission to access inventory reports';
    header('Location: /reports_dashboard_new.php');
    exit;
}

// Parameters
$category = $_GET['category'] ?? 'all';
$low_stock_only = isset($_GET['low_stock']) && $_GET['low_stock'] == '1';
$search = trim($_GET['search'] ?? '');
$sort_by = $_GET['sort_by'] ?? 'name';
$sort_order = $_GET['sort_order'] ?? 'ASC';

// Get tiles inventory with current stock (Fixed schema)
$tiles_sql = "
    SELECT 
        t.id,
        t.name,
        ts.label as size_label,
        t.current_cost,
        t.last_cost,
        t.average_cost,
        t.photo_path,
        COALESCE(cts.total_stock_boxes, 0) as current_stock,
        COALESCE(cts.total_stock_boxes * t.current_cost, 0) as stock_value,
        v.name as vendor_name,
        (
            SELECT COUNT(*) 
            FROM purchase_entries_tiles pet 
            WHERE pet.tile_id = t.id 
            AND DATE(pet.purchase_date) >= DATE('now', '-30 days')
        ) as recent_purchases,
        (
            SELECT SUM(ii.boxes_decimal) 
            FROM invoice_items ii 
            JOIN invoices i ON ii.invoice_id = i.id 
            WHERE ii.tile_id = t.id 
            AND DATE(i.invoice_dt) >= DATE('now', '-30 days')
            AND i.status != 'CANCELLED'
        ) as recent_sales
    FROM tiles t
    JOIN tile_sizes ts ON t.size_id = ts.id
    LEFT JOIN vendors v ON t.vendor_id = v.id
    LEFT JOIN current_tiles_stock cts ON t.id = cts.id
    WHERE 1=1
";

$params = [];

if ($search) {
    $tiles_sql .= " AND (t.name LIKE ? OR ts.label LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
}

if ($low_stock_only) {
    $tiles_sql .= " AND COALESCE(cts.total_stock_boxes, 0) < 10";
}

// Get misc items inventory
$misc_sql = "
    SELECT 
        m.id,
        m.name,
        m.unit_label,
        m.current_cost,
        m.photo_path,
        COALESCE(cms.total_stock_quantity, 0) as current_stock,
        COALESCE(cms.total_stock_quantity * m.current_cost, 0) as stock_value,
        (
            SELECT COUNT(*) 
            FROM purchase_entries_misc pem 
            WHERE pem.misc_item_id = m.id 
            AND DATE(pem.purchase_date) >= DATE('now', '-30 days')
        ) as recent_purchases,
        (
            SELECT SUM(imi.qty_units) 
            FROM invoice_misc_items imi 
            JOIN invoices i ON imi.invoice_id = i.id 
            WHERE imi.misc_item_id = m.id 
            AND DATE(i.invoice_dt) >= DATE('now', '-30 days')
            AND i.status != 'CANCELLED'
        ) as recent_sales
    FROM misc_items m
    LEFT JOIN current_misc_stock cms ON m.id = cms.id
    WHERE 1=1
";

if ($search) {
    $misc_sql .= " AND m.name LIKE ?";
}

if ($low_stock_only) {
    $misc_sql .= " AND COALESCE(cms.total_stock_quantity, 0) < 50";
}

// Execute queries
$inventory_data = [];

if ($category === 'all' || $category === 'tiles') {
    try {
        $tiles_stmt = $pdo->prepare($tiles_sql);
        $tiles_stmt->execute($params);
        $tiles = $tiles_stmt->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($tiles as $tile) {
            $tile['type'] = 'tile';
            $tile['unit'] = 'boxes';
            $inventory_data[] = $tile;
        }
    } catch (Exception $e) {
        error_log("Tiles query error: " . $e->getMessage());
    }
}

if ($category === 'all' || $category === 'misc') {
    $misc_params = [];
    if ($search) {
        $misc_params[] = "%$search%";
    }
    
    try {
        $misc_stmt = $pdo->prepare($misc_sql);
        $misc_stmt->execute($misc_params);
        $misc = $misc_stmt->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($misc as $item) {
            $item['type'] = 'misc';
            $item['unit'] = $item['unit_label'];
            $item['size_label'] = $item['unit_label'];
            $item['vendor_name'] = null;
            $inventory_data[] = $item;
        }
    } catch (Exception $e) {
        error_log("Misc items query error: " . $e->getMessage());
    }
}

// Sort data
switch ($sort_by) {
    case 'stock':
        usort($inventory_data, function($a, $b) use ($sort_order) {
            $result = $a['current_stock'] <=> $b['current_stock'];
            return $sort_order === 'DESC' ? -$result : $result;
        });
        break;
    case 'value':
        usort($inventory_data, function($a, $b) use ($sort_order) {
            $result = $a['stock_value'] <=> $b['stock_value'];
            return $sort_order === 'DESC' ? -$result : $result;
        });
        break;
    case 'cost':
        usort($inventory_data, function($a, $b) use ($sort_order) {
            $result = $a['current_cost'] <=> $b['current_cost'];
            return $sort_order === 'DESC' ? -$result : $result;
        });
        break;
    default:
        usort($inventory_data, function($a, $b) use ($sort_order) {
            $result = strcasecmp($a['name'], $b['name']);
            return $sort_order === 'DESC' ? -$result : $result;
        });
        break;
}

// Calculate summary
$summary = [
    'total_items' => count($inventory_data),
    'total_value' => array_sum(array_column($inventory_data, 'stock_value')),
    'low_stock_count' => 0,
    'out_of_stock_count' => 0,
    'tiles_count' => 0,
    'misc_count' => 0
];

foreach ($inventory_data as $item) {
    if ($item['type'] === 'tile') {
        $summary['tiles_count']++;
        if ($item['current_stock'] < 10) $summary['low_stock_count']++;
    } else {
        $summary['misc_count']++;
        if ($item['current_stock'] < 50) $summary['low_stock_count']++;
    }
    if ($item['current_stock'] == 0) $summary['out_of_stock_count']++;
}

$page_title = "Enhanced Inventory Report";
require_once __DIR__ . '/../includes/header.php';
?>

<style>
.inventory-summary {
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
.stock-critical { background-color: rgba(220, 53, 69, 0.1); }
.stock-low { background-color: rgba(255, 193, 7, 0.1); }
.stock-good { background-color: rgba(40, 167, 69, 0.1); }
.item-image {
    width: 40px;
    height: 40px;
    object-fit: cover;
    border-radius: 5px;
}
</style>

<div class="container-fluid mt-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h2><i class="bi bi-boxes text-primary"></i> Enhanced Inventory Report</h2>
            <p class="text-muted mb-0">
                <?= $category === 'all' ? 'All Items' : ucfirst($category) ?>
                <?= $search ? " | Search: $search" : "" ?>
                <?= $low_stock_only ? " | Low Stock Only" : "" ?>
            </p>
        </div>
        <a href="reports_dashboard_new.php" class="btn btn-outline-secondary">
            <i class="bi bi-arrow-left"></i> Back to Reports
        </a>
    </div>

    <!-- Summary Statistics -->
    <div class="row mb-4">
        <div class="col-md-2">
            <div class="metric-card">
                <h6>Total Items</h6>
                <h3><?= $summary['total_items'] ?></h3>
                <small><?= $summary['tiles_count'] ?> tiles, <?= $summary['misc_count'] ?> misc</small>
            </div>
        </div>
        <div class="col-md-2">
            <div class="metric-card">
                <h6>Total Value</h6>
                <h3>₹<?= number_format($summary['total_value'], 2) ?></h3>
                <small>Inventory value</small>
            </div>
        </div>
        <div class="col-md-2">
            <div class="metric-card">
                <h6>Low Stock</h6>
                <h3 class="text-warning"><?= $summary['low_stock_count'] ?></h3>
                <small>Need reorder</small>
            </div>
        </div>
        <div class="col-md-2">
            <div class="metric-card">
                <h6>Out of Stock</h6>
                <h3 class="text-danger"><?= $summary['out_of_stock_count'] ?></h3>
                <small>Zero inventory</small>
            </div>
        </div>
        <div class="col-md-2">
            <div class="metric-card">
                <h6>Avg Item Value</h6>
                <h3>₹<?= $summary['total_items'] > 0 ? number_format($summary['total_value'] / $summary['total_items'], 2) : '0.00' ?></h3>
                <small>Per item</small>
            </div>
        </div>
        <div class="col-md-2">
            <div class="metric-card">
                <h6>Stock Health</h6>
                <h3 class="<?= $summary['low_stock_count'] == 0 ? 'text-success' : 'text-warning' ?>">
                    <?= $summary['total_items'] > 0 ? number_format((($summary['total_items'] - $summary['low_stock_count']) / $summary['total_items']) * 100, 0) : 0 ?>%
                </h3>
                <small>Healthy stock</small>
            </div>
        </div>
    </div>

    <!-- Inventory Table -->
    <div class="card">
        <div class="card-header">
            <h5><i class="bi bi-list-ul"></i> Inventory Details (<?= count($inventory_data) ?> items)</h5>
        </div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-hover table-sm">
                    <thead class="table-light">
                        <tr>
                            <th>Image</th>
                            <th>Item Name</th>
                            <th>Size/Unit</th>
                            <th>Type</th>
                            <th>Current Stock</th>
                            <th>Current Cost</th>
                            <th>Stock Value</th>
                            <th>Recent Activity</th>
                            <th>Vendor</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($inventory_data)): ?>
                            <tr>
                                <td colspan="10" class="text-center text-muted py-4">
                                    <i class="bi bi-info-circle"></i> No inventory items found for the selected filters
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($inventory_data as $item): ?>
                                <?php 
                                if ($item['type'] === 'tile') {
                                    $stock_class = $item['current_stock'] == 0 ? 'stock-critical' : 
                                                  ($item['current_stock'] < 10 ? 'stock-low' : 'stock-good');
                                } else {
                                    $stock_class = $item['current_stock'] == 0 ? 'stock-critical' : 
                                                  ($item['current_stock'] < 50 ? 'stock-low' : 'stock-good');
                                }
                                ?>
                                <tr class="<?= $stock_class ?>">
                                    <td>
                                        <?php if ($item['photo_path']): ?>
                                            <img src="<?= h($item['photo_path']) ?>" class="item-image" alt="Item">
                                        <?php else: ?>
                                            <div class="item-image bg-light d-flex align-items-center justify-content-center">
                                                <i class="bi bi-image text-muted"></i>
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <strong><?= h($item['name']) ?></strong>
                                        <?php if ($item['type'] === 'tile'): ?>
                                            <br><small class="text-muted">Tile ID: <?= $item['id'] ?></small>
                                        <?php else: ?>
                                            <br><small class="text-muted">Misc ID: <?= $item['id'] ?></small>
                                        <?php endif; ?>
                                    </td>
                                    <td><?= h($item['size_label']) ?></td>
                                    <td>
                                        <span class="badge bg-<?= $item['type'] === 'tile' ? 'primary' : 'info' ?>">
                                            <?= ucfirst($item['type']) ?>
                                        </span>
                                    </td>
                                    <td>
                                        <strong><?= number_format($item['current_stock'], 1) ?></strong> 
                                        <small class="text-muted"><?= $item['unit'] ?></small>
                                    </td>
                                    <td>₹<?= number_format($item['current_cost'], 2) ?></td>
                                    <td>
                                        <strong>₹<?= number_format($item['stock_value'], 2) ?></strong>
                                    </td>
                                    <td>
                                        <small>
                                            <?= $item['recent_purchases'] ?? 0 ?> purchases<br>
                                            <?= number_format($item['recent_sales'] ?? 0, 1) ?> sold (30d)
                                        </small>
                                    </td>
                                    <td><?= h($item['vendor_name'] ?? 'N/A') ?></td>
                                    <td>
                                        <?php if ($item['current_stock'] == 0): ?>
                                            <span class="badge bg-danger">Out of Stock</span>
                                        <?php elseif (($item['type'] === 'tile' && $item['current_stock'] < 10) || 
                                                      ($item['type'] === 'misc' && $item['current_stock'] < 50)): ?>
                                            <span class="badge bg-warning">Low Stock</span>
                                        <?php else: ?>
                                            <span class="badge bg-success">In Stock</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>