<?php
// public/report_inventory.php - Inventory Report (FR-RP-02)
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

// Handle filters
$show_photos = isset($_GET['show_photos']) ? 1 : 0;
$size_filter = $_GET['size_filter'] ?? '';
$vendor_filter = $_GET['vendor_filter'] ?? '';
$low_stock_only = isset($_GET['low_stock_only']) ? 1 : 0;
$export = isset($_GET['export']) && $_GET['export'] === 'csv';

// Get inventory data (tiles)
$tiles_sql = "
    SELECT t.id, t.name, ts.label as size_label, ts.sqft_per_box,
           t.photo_path, t.current_cost, t.last_cost, t.average_cost,
           COALESCE(SUM(pet.purchase_qty_boxes), 0) as total_stock_boxes, 
           COALESCE(SUM(pet.purchase_qty_boxes * ts.sqft_per_box), 0) as total_stock_sqft,
           COALESCE(SUM(pet.purchase_qty_boxes) * t.current_cost, 0) as stock_value,
           '' as vendor_name
    FROM tiles t
    JOIN tile_sizes ts ON t.size_id = ts.id
    LEFT JOIN purchase_entries_tiles pet ON t.id = pet.tile_id
    WHERE 1=1
";

$params = [];

if ($size_filter) {
    $tiles_sql .= " AND ts.id = ?";
    $params[] = $size_filter;
}

if ($low_stock_only) {
    $tiles_sql .= " AND COALESCE(cts.total_stock_boxes, 0) < 10";
}

$tiles_sql .= " GROUP BY t.id, t.name, ts.label ORDER BY t.name, ts.label";

$tiles_stmt = $pdo->prepare($tiles_sql);
$tiles_stmt->execute($params);
$tiles_data = $tiles_stmt->fetchAll(PDO::FETCH_ASSOC);

// Get misc items data
$misc_sql = "
    SELECT m.id, m.name, m.unit_label, m.photo_path, 
           m.current_cost, m.last_cost, m.average_cost,
           COALESCE(cms.total_stock_units, 0) as total_stock_units, 
           COALESCE(cms.total_stock_units * m.current_cost, 0) as stock_value,
           '' as vendor_name
    FROM misc_items m
    LEFT JOIN current_misc_stock cms ON m.id = cms.id
    WHERE 1=1
";

if ($low_stock_only) {
    $misc_sql .= " AND COALESCE(cms.total_stock_units, 0) < 10";
}

$misc_sql .= " ORDER BY m.name";

$misc_stmt = $pdo->query($misc_sql);
$misc_data = $misc_stmt->fetchAll(PDO::FETCH_ASSOC);

// Get size options for filter
$sizes_stmt = $pdo->query("SELECT id, label FROM tile_sizes ORDER BY label");
$sizes = $sizes_stmt->fetchAll(PDO::FETCH_ASSOC);

// Calculate summary
$total_tile_value = array_sum(array_column($tiles_data, 'stock_value'));
$total_misc_value = array_sum(array_column($misc_data, 'stock_value'));
$total_inventory_value = $total_tile_value + $total_misc_value;

$low_stock_tiles = count(array_filter($tiles_data, function($tile) {
    return ($tile['total_stock_boxes'] ?? 0) < 10;
}));

$low_stock_misc = count(array_filter($misc_data, function($item) {
    return ($item['total_stock_units'] ?? 0) < 10;
}));

// Handle CSV export
if ($export) {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="inventory_report_' . date('Y-m-d') . '.csv"');
    
    $output = fopen('php://output', 'w');
    
    // CSV headers
    fputcsv($output, ['Type', 'Item Name', 'Size/Unit', 'Stock Quantity', 'Current Cost', 'Stock Value']);
    
    // Tiles data
    foreach ($tiles_data as $tile) {
        fputcsv($output, [
            'Tile',
            $tile['name'],
            $tile['size_label'],
            number_format($tile['total_stock_boxes'] ?? 0, 1) . ' boxes',
            number_format($tile['current_cost'] ?? 0, 2),
            number_format($tile['stock_value'] ?? 0, 2)
        ]);
    }
    
    // Misc items data
    foreach ($misc_data as $item) {
        fputcsv($output, [
            'Misc Item',
            $item['name'],
            $item['unit_label'],
            number_format($item['total_stock_units'] ?? 0, 1) . ' ' . $item['unit_label'],
            number_format($item['current_cost'] ?? 0, 2),
            number_format($item['stock_value'] ?? 0, 2)
        ]);
    }
    
    fclose($output);
    exit;
}

$page_title = "Inventory Report";
require_once __DIR__ . '/../includes/header.php';
?>

<style>
.inventory-photo {
    width: 50px;
    height: 50px;
    object-fit: cover;
    border-radius: 5px;
}
.stock-badge {
    font-size: 0.8rem;
}
</style>

<div class="container-fluid mt-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h2><i class="bi bi-boxes"></i> Inventory Report</h2>
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

    <!-- Summary Cards -->
    <div class="row mb-4">
        <div class="col-md-3">
            <div class="card bg-primary text-white">
                <div class="card-body text-center">
                    <h4><?= count($tiles_data) ?></h4>
                    <small>Tile Types</small>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card bg-info text-white">
                <div class="card-body text-center">
                    <h4><?= count($misc_data) ?></h4>
                    <small>Misc Items</small>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card bg-success text-white">
                <div class="card-body text-center">
                    <h4>₹<?= number_format($total_inventory_value, 0) ?></h4>
                    <small>Total Value</small>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card bg-warning text-white">
                <div class="card-body text-center">
                    <h4><?= $low_stock_tiles + $low_stock_misc ?></h4>
                    <small>Low Stock Items</small>
                </div>
            </div>
        </div>
    </div>

    <!-- Filters -->
    <div class="card mb-4">
        <div class="card-body">
            <form method="GET" class="row g-3">
                <div class="col-md-2">
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" name="show_photos" id="showPhotos" 
                               <?= $show_photos ? 'checked' : '' ?>>
                        <label class="form-check-label" for="showPhotos">Show Photos</label>
                    </div>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Filter by Size</label>
                    <select class="form-select" name="size_filter">
                        <option value="">All Sizes</option>
                        <?php foreach ($sizes as $size): ?>
                            <option value="<?= $size['id'] ?>" <?= $size_filter == $size['id'] ? 'selected' : '' ?>>
                                <?= h($size['label']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <div class="form-check mt-4">
                        <input class="form-check-input" type="checkbox" name="low_stock_only" id="lowStock" 
                               <?= $low_stock_only ? 'checked' : '' ?>>
                        <label class="form-check-label" for="lowStock">Low Stock Only</label>
                    </div>
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

    <!-- Tiles Inventory -->
    <div class="card mb-4">
        <div class="card-header">
            <h5><i class="bi bi-grid-3x3"></i> Tiles Inventory</h5>
        </div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-hover">
                    <thead class="table-dark">
                        <tr>
                            <?php if ($show_photos): ?><th>Photo</th><?php endif; ?>
                            <th>Tile Name</th>
                            <th>Size</th>
                            <th>Stock (Boxes)</th>
                            <th>Stock (Sq.Ft)</th>
                            <th>Current Cost</th>
                            <th>Stock Value</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($tiles_data)): ?>
                            <tr>
                                <td colspan="<?= $show_photos ? '8' : '7' ?>" class="text-center text-muted">
                                    No tiles found with current filters
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($tiles_data as $tile): ?>
                                <?php 
                                $stock_boxes = $tile['total_stock_boxes'] ?? 0;
                                $is_low_stock = $stock_boxes < 10;
                                ?>
                                <tr class="<?= $is_low_stock ? 'table-warning' : '' ?>">
                                    <?php if ($show_photos): ?>
                                        <td>
                                            <?php if ($tile['photo_path']): ?>
                                                <img src="<?= h($tile['photo_path']) ?>" class="inventory-photo" alt="Photo">
                                            <?php else: ?>
                                                <div class="inventory-photo bg-light d-flex align-items-center justify-content-center">
                                                    <i class="bi bi-image text-muted"></i>
                                                </div>
                                            <?php endif; ?>
                                        </td>
                                    <?php endif; ?>
                                    <td><strong><?= h($tile['name']) ?></strong></td>
                                    <td><?= h($tile['size_label']) ?></td>
                                    <td><?= number_format($stock_boxes, 1) ?></td>
                                    <td><?= number_format($tile['total_stock_sqft'] ?? 0, 1) ?></td>
                                    <td>₹<?= number_format($tile['current_cost'] ?? 0, 2) ?></td>
                                    <td>₹<?= number_format($tile['stock_value'] ?? 0, 2) ?></td>
                                    <td>
                                        <?php if ($is_low_stock): ?>
                                            <span class="badge bg-warning stock-badge">Low Stock</span>
                                        <?php elseif ($stock_boxes > 50): ?>
                                            <span class="badge bg-success stock-badge">Good Stock</span>
                                        <?php else: ?>
                                            <span class="badge bg-info stock-badge">Medium Stock</span>
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

    <!-- Misc Items Inventory -->
    <div class="card">
        <div class="card-header">
            <h5><i class="bi bi-box"></i> Other Items Inventory</h5>
        </div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-hover">
                    <thead class="table-dark">
                        <tr>
                            <?php if ($show_photos): ?><th>Photo</th><?php endif; ?>
                            <th>Item Name</th>
                            <th>Unit</th>
                            <th>Stock Quantity</th>
                            <th>Current Cost</th>
                            <th>Stock Value</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($misc_data)): ?>
                            <tr>
                                <td colspan="<?= $show_photos ? '7' : '6' ?>" class="text-center text-muted">
                                    No misc items found with current filters
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($misc_data as $item): ?>
                                <?php 
                                $stock_units = $item['total_stock_units'] ?? 0;
                                $is_low_stock = $stock_units < 10;
                                ?>
                                <tr class="<?= $is_low_stock ? 'table-warning' : '' ?>">
                                    <?php if ($show_photos): ?>
                                        <td>
                                            <?php if ($item['photo_path']): ?>
                                                <img src="<?= h($item['photo_path']) ?>" class="inventory-photo" alt="Photo">
                                            <?php else: ?>
                                                <div class="inventory-photo bg-light d-flex align-items-center justify-content-center">
                                                    <i class="bi bi-image text-muted"></i>
                                                </div>
                                            <?php endif; ?>
                                        </td>
                                    <?php endif; ?>
                                    <td><strong><?= h($item['name']) ?></strong></td>
                                    <td><?= h($item['unit_label']) ?></td>
                                    <td><?= number_format($stock_units, 1) ?> <?= h($item['unit_label']) ?></td>
                                    <td>₹<?= number_format($item['current_cost'] ?? 0, 2) ?></td>
                                    <td>₹<?= number_format($item['stock_value'] ?? 0, 2) ?></td>
                                    <td>
                                        <?php if ($is_low_stock): ?>
                                            <span class="badge bg-warning stock-badge">Low Stock</span>
                                        <?php elseif ($stock_units > 50): ?>
                                            <span class="badge bg-success stock-badge">Good Stock</span>
                                        <?php else: ?>
                                            <span class="badge bg-info stock-badge">Medium Stock</span>
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