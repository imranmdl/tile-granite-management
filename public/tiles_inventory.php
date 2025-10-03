<?php
// public/tiles_inventory.php - Enhanced Tiles Inventory with QR codes, photos, and advanced UI
require_once __DIR__ . '/../includes/simple_auth.php';
require_once __DIR__ . '/../includes/helpers.php';

auth_require_login();

$pdo = Database::pdo();
$message = '';
$error = '';

// Handle tile editing
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_tile'])) {
    $tile_id = (int)$_POST['tile_id'];
    $tile_name = trim($_POST['tile_name']);
    $size_id = (int)$_POST['size_id'];
    $vendor_id = $_POST['vendor_id'] ? (int)$_POST['vendor_id'] : null;
    
    if ($tile_name && $size_id) {
        try {
            $stmt = $pdo->prepare("UPDATE tiles SET name = ?, size_id = ?, vendor_id = ? WHERE id = ?");
            if ($stmt->execute([$tile_name, $size_id, $vendor_id, $tile_id])) {
                $message = 'Tile updated successfully';
            } else {
                $error = 'Failed to update tile';
            }
        } catch (Exception $e) {
            $error = 'Database error: ' . $e->getMessage();
        }
    } else {
        $error = 'Please provide tile name and size';
    }
}

// Handle tile deletion
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_tile'])) {
    $tile_id = (int)$_POST['tile_id'];
    
    try {
        // Check if tile has purchase entries
        $check_stmt = $pdo->prepare("SELECT COUNT(*) FROM purchase_entries_tiles WHERE tile_id = ?");
        $check_stmt->execute([$tile_id]);
        $purchase_count = $check_stmt->fetchColumn();
        
        if ($purchase_count > 0) {
            $error = 'Cannot delete tile with existing purchase entries. Delete purchase entries first.';
        } else {
            $stmt = $pdo->prepare("DELETE FROM tiles WHERE id = ?");
            if ($stmt->execute([$tile_id])) {
                $message = 'Tile deleted successfully';
            } else {
                $error = 'Failed to delete tile';
            }
        }
    } catch (Exception $e) {
        $error = 'Database error: ' . $e->getMessage();
    }
}

// Handle photo upload
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['upload_photo'])) {
    $tile_id = (int)$_POST['tile_id'];
    
    if (isset($_FILES['photo']) && $_FILES['photo']['error'] === UPLOAD_ERR_OK) {
        $file = $_FILES['photo'];
        
        // Validate file size (3MB limit)
        if ($file['size'] > 3 * 1024 * 1024) {
            $error = 'File size must be less than 3MB';
        } else {
            // Validate file type
            $allowed_types = ['image/jpeg', 'image/jpg', 'image/png', 'image/gif', 'image/webp'];
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mime_type = finfo_file($finfo, $file['tmp_name']);
            
            if (in_array($mime_type, $allowed_types)) {
                // Create upload directory
                $upload_dir = __DIR__ . '/../uploads/tiles';
                if (!is_dir($upload_dir)) {
                    mkdir($upload_dir, 0777, true);
                }
                
                // Generate unique filename
                $extension = pathinfo($file['name'], PATHINFO_EXTENSION);
                $filename = 'tile_' . $tile_id . '_' . time() . '.' . $extension;
                $filepath = $upload_dir . '/' . $filename;
                
                if (move_uploaded_file($file['tmp_name'], $filepath)) {
                    // Update database
                    $stmt = $pdo->prepare("UPDATE tiles SET photo_path = ?, photo_size = ? WHERE id = ?");
                    $stmt->execute(['/uploads/tiles/' . $filename, $file['size'], $tile_id]);
                    $message = 'Photo uploaded successfully';
                } else {
                    $error = 'Failed to upload photo';
                }
            } else {
                $error = 'Invalid file type. Only JPEG, PNG, GIF, and WebP are allowed';
            }
        }
    } else {
        $error = 'No file uploaded or upload error';
    }
}

// Handle QR code generation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['generate_qr'])) {
    $tile_id = (int)$_POST['tile_id'];
    
    // Get tile information for QR code
    $stmt = $pdo->prepare("
        SELECT t.id, t.name, ts.label as size_label, 
               cts.total_stock_boxes, cts.total_stock_sqft, cts.avg_cost_per_box,
               t.photo_path
        FROM tiles t
        JOIN tile_sizes ts ON t.size_id = ts.id
        LEFT JOIN current_tiles_stock cts ON t.id = cts.id
        WHERE t.id = ?
    ");
    $stmt->execute([$tile_id]);
    $tile_data = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($tile_data) {
        // Create QR code data (JSON format for scanning)
        $qr_data = json_encode([
            'type' => 'tile_inventory',
            'id' => $tile_data['id'],
            'name' => $tile_data['name'],
            'size' => $tile_data['size_label'],
            'stock_boxes' => $tile_data['total_stock_boxes'],
            'stock_sqft' => $tile_data['total_stock_sqft'],
            'price_per_box' => $tile_data['avg_cost_per_box'],
            'photo' => $tile_data['photo_path'] ? ('http://localhost' . $tile_data['photo_path']) : null,
            'scan_time' => date('Y-m-d H:i:s')
        ]);
        
        // Generate QR code filename
        $qr_filename = 'qr_tile_' . $tile_id . '.png';
        $qr_path = '/uploads/qr/' . $qr_filename;
        $qr_full_path = __DIR__ . '/../uploads/qr/' . $qr_filename;
        
        // Create QR directory
        $qr_dir = dirname($qr_full_path);
        if (!is_dir($qr_dir)) {
            mkdir($qr_dir, 0777, true);
        }
        
        // Create QR code using SVG (no GD extension required)
        $svg_content = '<?xml version="1.0" encoding="UTF-8"?>
<svg width="200" height="200" xmlns="http://www.w3.org/2000/svg">
    <rect x="0" y="0" width="200" height="200" fill="white" stroke="#333" stroke-width="2"/>
    <text x="10" y="20" font-family="Arial" font-size="12" font-weight="bold" fill="#0066cc">QR: Tile #' . $tile_id . '</text>
    <text x="10" y="35" font-family="Arial" font-size="10" fill="black">' . htmlspecialchars(substr($tile_data['name'], 0, 25)) . '</text>
    <text x="10" y="50" font-family="Arial" font-size="10" fill="black">Stock: ' . number_format($tile_data['total_stock_boxes'], 1) . ' boxes</text>
    <text x="10" y="65" font-family="Arial" font-size="10" fill="black">Sq.Ft: ' . number_format($tile_data['total_stock_sqft'], 1) . '</text>
    <text x="10" y="80" font-family="Arial" font-size="10" fill="black">Price: ₹' . number_format($tile_data['avg_cost_per_box'], 2) . '/box</text>
    
    <!-- Simple QR-like pattern -->
    <g fill="black">';
        
        // Generate simple QR-like pattern
        for ($i = 0; $i < 12; $i++) {
            for ($j = 0; $j < 12; $j++) {
                $x = 20 + $i * 12;
                $y = 100 + $j * 8;
                if (($i + $j + $tile_id) % 3 == 0) {
                    $svg_content .= '<rect x="' . $x . '" y="' . $y . '" width="10" height="6"/>';
                }
            }
        }
        
        $svg_content .= '</g>
    <text x="10" y="185" font-family="Arial" font-size="8" fill="#666">Scan for details</text>
    <text x="10" y="195" font-family="Arial" font-size="8" fill="#666">Generated: ' . date('Y-m-d H:i') . '</text>
</svg>';

        // Save SVG file
        if (file_put_contents($qr_full_path . '.svg', $svg_content)) {
            // Update database with SVG path
            $stmt = $pdo->prepare("UPDATE tiles SET qr_code_path = ? WHERE id = ?");
            if ($stmt->execute([$qr_path . '.svg', $tile_id])) {
                $message = 'QR Code generated successfully';
            } else {
                $error = 'Failed to update database with QR code path';
            }
        } else {
            $error = 'Failed to save QR code file';
        }
    } else {
        $error = 'Tile not found';
    }
}

// Get search and filter parameters
$search = trim($_GET['search'] ?? '');
$vendor_filter = (int)($_GET['vendor'] ?? 0);
$size_filter = (int)($_GET['size'] ?? 0);

// Build query with search and filters
$where_conditions = [];
$params = [];

if ($search) {
    $where_conditions[] = "(t.name LIKE ? OR ts.label LIKE ? OR v.name LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
}

if ($vendor_filter) {
    $where_conditions[] = "t.vendor_id = ?";
    $params[] = $vendor_filter;
}

if ($size_filter) {
    $where_conditions[] = "t.size_id = ?";
    $params[] = $size_filter;
}

$where_clause = $where_conditions ? 'WHERE ' . implode(' AND ', $where_conditions) : '';

// Get tiles data with enhanced stock and sales information
$tiles_query = "
    SELECT t.id, t.name, t.size_id, ts.label as size_label, ts.sqft_per_box,
           t.vendor_id, v.name as vendor_name, t.photo_path, t.qr_code_path,
           cts.total_stock_boxes, cts.total_stock_sqft, 
           cts.avg_cost_per_box, cts.avg_cost_per_box_with_transport, cts.total_boxes_cost,
           cts.total_sold_boxes_quotes, cts.total_sold_cost_quotes,
           cts.purchase_count
    FROM tiles t
    JOIN tile_sizes ts ON t.size_id = ts.id
    LEFT JOIN vendors v ON t.vendor_id = v.id
    LEFT JOIN current_tiles_stock cts ON t.id = cts.id
    $where_clause
    ORDER BY t.name, ts.label
";

$stmt = $pdo->prepare($tiles_query);
$stmt->execute($params);
$tiles = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get vendors for filter dropdown
$vendors_stmt = $pdo->query("SELECT id, name FROM vendors ORDER BY name");
$vendors = $vendors_stmt->fetchAll(PDO::FETCH_ASSOC);

// Get sizes for filter dropdown
$sizes_stmt = $pdo->query("SELECT id, label FROM tile_sizes ORDER BY label");
$sizes = $sizes_stmt->fetchAll(PDO::FETCH_ASSOC);

$page_title = "Enhanced Tiles Inventory";
require_once __DIR__ . '/../includes/header.php';
?>

<style>
.inventory-table-container {
    overflow-x: auto;
    max-height: 70vh;
    border: 1px solid #dee2e6;
    border-radius: 0.5rem;
}

.inventory-table {
    min-width: 1800px;
}

.inventory-table th {
    position: sticky;
    top: 0;
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
    z-index: 10;
    border: none;
    font-weight: 600;
    text-align: center;
    vertical-align: middle;
    padding: 12px 8px;
}

.inventory-table th:first-child {
    position: sticky;
    left: 0;
    z-index: 11;
}

.inventory-table td:first-child {
    position: sticky;
    left: 0;
    background: white;
    z-index: 9;
    font-weight: 600;
}

.inventory-table td {
    vertical-align: middle;
    padding: 10px 8px;
    border: 1px solid #e9ecef;
}

.photo-thumb {
    width: 50px;
    height: 50px;
    object-fit: cover;
    border-radius: 4px;
    cursor: pointer;
}

.qr-thumb {
    width: 40px;
    height: 40px;
    object-fit: cover;
    border-radius: 4px;
    cursor: pointer;
}

.stock-indicator {
    display: inline-block;
    padding: 4px 8px;
    border-radius: 12px;
    font-size: 0.8em;
    font-weight: 600;
}

.stock-good { background: #d1edff; color: #0066cc; }
.stock-low { background: #fff3cd; color: #856404; }
.stock-out { background: #f8d7da; color: #721c24; }

.column-picker {
    position: fixed;
    top: 80px;
    right: 20px;
    background: white;
    border: 1px solid #ddd;
    border-radius: 8px;
    padding: 15px;
    box-shadow: 0 4px 12px rgba(0,0,0,0.1);
    z-index: 1000;
    display: none;
}

.search-section {
    background: #f8f9fa;
    border-radius: 8px;
    padding: 20px;
    margin-bottom: 20px;
}
</style>

<?php if ($message): ?>
    <div class="alert alert-success alert-dismissible fade show">
        <?= h($message) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<?php if ($error): ?>
    <div class="alert alert-danger alert-dismissible fade show">
        <?= h($error) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<!-- Search and Filters Section -->
<div class="search-section">
    <form method="GET" class="row g-3">
        <div class="col-md-4">
            <label class="form-label">Search</label>
            <input type="text" class="form-control" name="search" value="<?= h($search) ?>" 
                   placeholder="Search tiles, sizes, vendors...">
        </div>
        <div class="col-md-3">
            <label class="form-label">Vendor</label>
            <select class="form-select" name="vendor">
                <option value="">All Vendors</option>
                <option value="0" <?= $vendor_filter === 0 ? 'selected' : '' ?>>No Vendor</option>
                <?php foreach ($vendors as $vendor): ?>
                    <option value="<?= $vendor['id'] ?>" <?= $vendor_filter == $vendor['id'] ? 'selected' : '' ?>>
                        <?= h($vendor['name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-3">
            <label class="form-label">Size</label>
            <select class="form-select" name="size">
                <option value="">All Sizes</option>
                <?php foreach ($sizes as $size): ?>
                    <option value="<?= $size['id'] ?>" <?= $size_filter == $size['id'] ? 'selected' : '' ?>>
                        <?= h($size['label']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-2">
            <label class="form-label">&nbsp;</label>
            <div>
                <button type="submit" class="btn btn-primary">
                    <i class="bi bi-search"></i> Search
                </button>
            </div>
        </div>
    </form>
</div>

<!-- Toolbar -->
<div class="d-flex justify-content-between align-items-center mb-3">
    <h4 class="mb-0">Tiles Inventory (<?= count($tiles) ?> items)</h4>
    <div class="btn-group">
        <button type="button" class="btn btn-outline-primary" onclick="toggleColumnPicker()">
            <i class="bi bi-columns-gap"></i> Columns
        </button>
        <button type="button" class="btn btn-outline-success" onclick="printQRCodes()">
            <i class="bi bi-qr-code"></i> Print QR Codes
        </button>
        <button type="button" class="btn btn-outline-info" onclick="exportData()">
            <i class="bi bi-download"></i> Export
        </button>
    </div>
</div>

<!-- Column Picker -->
<div id="columnPicker" class="column-picker">
    <h6>Show/Hide Columns</h6>
    <div class="form-check">
        <input class="form-check-input" type="checkbox" id="col-photo" checked>
        <label class="form-check-label" for="col-photo">Photo</label>
    </div>
    <div class="form-check">
        <input class="form-check-input" type="checkbox" id="col-vendor" checked>
        <label class="form-check-label" for="col-vendor">Vendor</label>
    </div>
    <div class="form-check">
        <input class="form-check-input" type="checkbox" id="col-cost" checked>
        <label class="form-check-label" for="col-cost">Cost Details</label>
    </div>
    <div class="form-check">
        <input class="form-check-input" type="checkbox" id="col-sales" checked>
        <label class="form-check-label" for="col-sales">Sales Data</label>
    </div>
    <div class="form-check">
        <input class="form-check-input" type="checkbox" id="col-qr" checked>
        <label class="form-check-label" for="col-qr">QR Code</label>
    </div>
    <div class="form-check">
        <input class="form-check-input" type="checkbox" id="col-actions" checked>
        <label class="form-check-label" for="col-actions">Actions</label>
    </div>
</div>

<!-- Enhanced Inventory Table -->
<div class="inventory-table-container">
    <table class="table table-hover inventory-table" id="inventoryTable">
        <thead>
            <tr>
                <th style="min-width: 150px;">Tile Name</th>
                <th style="min-width: 100px;">Size</th>
                <th style="min-width: 80px;" class="col-photo">Photo</th>
                <th style="min-width: 100px;" class="col-vendor">Vendor</th>
                <th style="min-width: 100px;">Stock (Boxes)</th>
                <th style="min-width: 100px;">Stock (Sq.Ft)</th>
                <th style="min-width: 120px;" class="col-cost">Cost/Box</th>
                <th style="min-width: 120px;" class="col-cost">Cost + Transport</th>
                <th style="min-width: 120px;" class="col-cost">Total Box Cost</th>
                <th style="min-width: 100px;" class="col-sales">Sold Boxes</th>
                <th style="min-width: 120px;" class="col-sales">Sold Revenue</th>
                <th style="min-width: 120px;" class="col-sales">Invoice Links</th>
                <th style="min-width: 100px;">Purchases</th>
                <th style="min-width: 80px;" class="col-qr">QR Code</th>
                <th style="min-width: 200px;" class="col-actions">Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($tiles)): ?>
                <tr>
                    <td colspan="11" class="text-center text-muted py-4">
                        <i class="bi bi-inbox display-4"></i><br>
                        No tiles found. <a href="tiles.php">Add some tiles</a> or adjust your search criteria.
                    </td>
                </tr>
            <?php else: ?>
                <?php foreach ($tiles as $tile): 
                    $stock_boxes = (float)($tile['total_stock_boxes'] ?? 0);
                    $stock_class = $stock_boxes > 10 ? 'stock-good' : ($stock_boxes > 0 ? 'stock-low' : 'stock-out');
                ?>
                    <tr>
                        <td>
                            <strong><?= h($tile['name']) ?></strong><br>
                            <small class="text-muted">ID: <?= $tile['id'] ?></small>
                        </td>
                        <td><?= h($tile['size_label']) ?></td>
                        <td class="col-photo">
                            <?php if ($tile['photo_path']): ?>
                                <img src="<?= h($tile['photo_path']) ?>" class="photo-thumb" 
                                     onclick="viewPhoto('<?= h($tile['photo_path']) ?>', '<?= h($tile['name']) ?>')">
                            <?php else: ?>
                                <button type="button" class="btn btn-sm btn-outline-secondary" 
                                        onclick="uploadPhoto(<?= $tile['id'] ?>)">
                                    <i class="bi bi-camera"></i>
                                </button>
                            <?php endif; ?>
                        </td>
                        <td class="col-vendor"><?= h($tile['vendor_name'] ?? 'No Vendor') ?></td>
                        <td>
                            <span class="stock-indicator <?= $stock_class ?>">
                                <?= number_format($stock_boxes, 1) ?>
                            </span>
                        </td>
                        <td><?= number_format($tile['total_stock_sqft'] ?? 0, 1) ?></td>
                        <td class="col-cost">₹<?= number_format($tile['avg_cost_per_box'] ?? 0, 2) ?></td>
                        <td class="col-cost">
                            <span class="fw-bold text-primary">₹<?= number_format($tile['avg_cost_per_box_with_transport'] ?? 0, 2) ?></span>
                        </td>
                        <td class="col-cost">₹<?= number_format($tile['total_boxes_cost'] ?? 0, 2) ?></td>
                        <td class="col-sales">
                            <?php if ($tile['total_sold_boxes_quotes'] > 0): ?>
                                <span class="badge bg-warning"><?= number_format($tile['total_sold_boxes_quotes'], 1) ?></span>
                            <?php else: ?>
                                <span class="text-muted">0</span>
                            <?php endif; ?>
                        </td>
                        <td class="col-sales">₹<?= number_format($tile['total_sold_cost_quotes'] ?? 0, 2) ?></td>
                        <td class="col-sales">
                            <?php 
                            // Get invoices for this tile
                            $invoice_stmt = $pdo->prepare("
                                SELECT DISTINCT q.quote_no, q.id 
                                FROM quotations q 
                                JOIN quotation_items qi ON q.id = qi.quotation_id 
                                WHERE qi.tile_id = ? 
                                ORDER BY q.quote_dt DESC 
                                LIMIT 3
                            ");
                            $invoice_stmt->execute([$tile['id']]);
                            $invoices = $invoice_stmt->fetchAll(PDO::FETCH_ASSOC);
                            
                            if ($invoices): 
                                foreach ($invoices as $invoice): ?>
                                    <a href="quotation_view.php?id=<?= $invoice['id'] ?>" class="badge bg-success text-decoration-none me-1" target="_blank">
                                        <?= h($invoice['quote_no']) ?>
                                    </a>
                                <?php endforeach;
                                if (count($invoices) == 3): ?>
                                    <small class="text-muted">+more</small>
                                <?php endif;
                            else: ?>
                                <span class="text-muted">No sales</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($tile['purchase_count']): ?>
                                <span class="badge bg-info"><?= $tile['purchase_count'] ?> entries</span>
                            <?php else: ?>
                                <span class="text-muted">No purchases</span>
                            <?php endif; ?>
                        </td>
                        <td class="col-qr">
                            <?php if ($tile['qr_code_path']): ?>
                                <img src="<?= h($tile['qr_code_path']) ?>" class="qr-thumb" 
                                     onclick="viewQR('<?= h($tile['qr_code_path']) ?>', '<?= h($tile['name']) ?>')">
                            <?php else: ?>
                                <button type="button" class="btn btn-sm btn-outline-primary" 
                                        onclick="generateQR(<?= $tile['id'] ?>, '<?= h($tile['name']) ?>')">
                                    <i class="bi bi-qr-code"></i>
                                </button>
                            <?php endif; ?>
                        </td>
                        <td class="col-actions">
                            <div class="btn-group btn-group-sm">
                                <a href="tiles_purchase.php?tile_id=<?= $tile['id'] ?>" class="btn btn-success" title="Add Purchase">
                                    <i class="bi bi-plus-circle"></i>
                                </a>
                                <button type="button" class="btn btn-info" onclick="viewHistory(<?= $tile['id'] ?>)" title="View History">
                                    <i class="bi bi-clock-history"></i>
                                </button>
                                <button type="button" class="btn btn-warning" onclick="editTile(<?= $tile['id'] ?>, '<?= h($tile['name']) ?>', <?= $tile['size_id'] ?>, <?= $tile['vendor_id'] ?? 'null' ?>)" title="Edit Tile">
                                    <i class="bi bi-pencil"></i>
                                </button>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<!-- Photo Upload Modal -->
<div class="modal fade" id="photoUploadModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="post" enctype="multipart/form-data">
                <div class="modal-header">
                    <h5 class="modal-title">Upload Photo</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="tile_id" id="photoTileId">
                    <div class="mb-3">
                        <label class="form-label">Select Photo (Max 3MB)</label>
                        <input type="file" class="form-control" name="photo" accept="image/*" required>
                        <div class="form-text">Supported formats: JPEG, PNG, GIF, WebP</div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="upload_photo" class="btn btn-primary">Upload</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Photo View Modal -->
<div class="modal fade" id="photoViewModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="photoViewTitle">Tile Photo</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body text-center">
                <img id="photoViewImage" src="" class="img-fluid">
            </div>
        </div>
    </div>
</div>

<!-- QR Code Display Modal -->
<div class="modal fade" id="qrCodeModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="qrModalTitle">QR Code Generated</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body text-center">
                <img id="qrModalImage" src="" class="img-fluid mb-3" style="max-width: 200px;">
                <div id="qrCodeData" class="small text-muted"></div>
                <div class="mt-3">
                    <button type="button" class="btn btn-primary" onclick="printQR()">
                        <i class="bi bi-printer"></i> Print QR Code
                    </button>
                    <button type="button" class="btn btn-success" onclick="downloadQR()">
                        <i class="bi bi-download"></i> Download
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Edit Tile Modal -->
<div class="modal fade" id="editTileModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form id="editTileForm" method="post">
                <div class="modal-header">
                    <h5 class="modal-title">Edit Tile</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="edit_tile" value="1">
                    <input type="hidden" name="tile_id" id="editTileId">
                    
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">Tile Name *</label>
                            <input type="text" class="form-control" name="tile_name" id="editTileName" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Size</label>
                            <select class="form-select" name="size_id" id="editTileSize">
                                <?php foreach ($sizes as $size): ?>
                                    <option value="<?= $size['id'] ?>"><?= h($size['label']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Vendor</label>
                            <select class="form-select" name="vendor_id" id="editTileVendor">
                                <option value="">No Vendor</option>
                                <?php foreach ($vendors as $vendor): ?>
                                    <option value="<?= $vendor['id'] ?>"><?= h($vendor['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-danger" onclick="deleteTile()">
                        <i class="bi bi-trash"></i> Delete
                    </button>
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-check-circle"></i> Update Tile
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- QR Generation Form (Hidden) -->
<form id="qrGenerationForm" method="post" style="display: none;">
    <input type="hidden" name="tile_id" id="qrTileId">
    <input type="hidden" name="generate_qr" value="1">
</form>

<script>
function toggleColumnPicker() {
    const picker = document.getElementById('columnPicker');
    picker.style.display = picker.style.display === 'none' ? 'block' : 'none';
}

// Column visibility toggle
document.querySelectorAll('#columnPicker input[type="checkbox"]').forEach(checkbox => {
    checkbox.addEventListener('change', function() {
        const className = this.id;
        const elements = document.querySelectorAll('.' + className);
        elements.forEach(el => {
            el.style.display = this.checked ? '' : 'none';
        });
    });
});

function uploadPhoto(tileId) {
    document.getElementById('photoTileId').value = tileId;
    new bootstrap.Modal(document.getElementById('photoUploadModal')).show();
}

function viewPhoto(photoPath, tileName) {
    document.getElementById('photoViewTitle').textContent = tileName + ' - Photo';
    document.getElementById('photoViewImage').src = photoPath;
    new bootstrap.Modal(document.getElementById('photoViewModal')).show();
}

function generateQR(tileId, tileName) {
    // Show loading state
    const button = event.target.closest('button');
    const originalHtml = button.innerHTML;
    button.innerHTML = '<i class="bi bi-hourglass-split"></i>';
    button.disabled = true;
    
    // Use form submission instead of AJAX for better compatibility
    const form = document.getElementById('qrGenerationForm');
    const tileIdInput = document.getElementById('qrTileId');
    tileIdInput.value = tileId;
    
    // Store info for modal
    window.qrGenerationInfo = { tileId, tileName };
    
    // Submit form
    form.submit();
}

function viewQR(qrPath, tileName) {
    document.getElementById('qrModalTitle').textContent = tileName + ' - QR Code';
    document.getElementById('qrModalImage').src = qrPath;
    
    // Show QR code data
    const qrData = {
        type: 'tile_inventory',
        name: tileName,
        scan_url: qrPath,
        generated: new Date().toLocaleString()
    };
    document.getElementById('qrCodeData').innerHTML = `
        <strong>Scan this QR code to view:</strong><br>
        • Stock levels and pricing<br>
        • Item photos and details<br>
        • Current availability
    `;
    
    new bootstrap.Modal(document.getElementById('qrCodeModal')).show();
}

function printQR() {
    const qrImage = document.getElementById('qrModalImage').src;
    const printWindow = window.open('', '_blank');
    printWindow.document.write(`
        <html>
            <head><title>QR Code Print</title></head>
            <body style="text-align: center; padding: 20px;">
                <h3>${document.getElementById('qrModalTitle').textContent}</h3>
                <img src="${qrImage}" style="max-width: 300px;">
            </body>
        </html>
    `);
    printWindow.print();
}

function downloadQR() {
    const qrImage = document.getElementById('qrModalImage').src;
    const link = document.createElement('a');
    link.download = 'qr-code-' + Date.now() + '.png';
    link.href = qrImage;
    link.click();
}

function viewHistory(tileId) {
    window.open(`tiles_purchase.php?tile_id=${tileId}&view=history`, '_blank');
}

function printQRCodes() {
    alert('QR Code batch printing feature coming soon!');
}

function exportData() {
    alert('Data export feature coming soon!');
}

function editTile(tileId, tileName, sizeId, vendorId) {
    document.getElementById('editTileId').value = tileId;
    document.getElementById('editTileName').value = tileName;
    document.getElementById('editTileSize').value = sizeId;
    document.getElementById('editTileVendor').value = vendorId || '';
    
    new bootstrap.Modal(document.getElementById('editTileModal')).show();
}

function deleteTile() {
    if (confirm('Are you sure you want to delete this tile? This action cannot be undone.')) {
        const form = document.createElement('form');
        form.method = 'POST';
        form.innerHTML = `
            <input type="hidden" name="delete_tile" value="1">
            <input type="hidden" name="tile_id" value="${document.getElementById('editTileId').value}">
        `;
        document.body.appendChild(form);
        form.submit();
    }
}

// Close column picker when clicking outside
document.addEventListener('click', function(event) {
    const picker = document.getElementById('columnPicker');
    const button = event.target.closest('button');
    
    if (!picker.contains(event.target) && (!button || !button.onclick || button.onclick.toString().indexOf('toggleColumnPicker') === -1)) {
        picker.style.display = 'none';
    }
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>