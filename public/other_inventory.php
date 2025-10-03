<?php
// public/other_inventory.php - Enhanced Other Items Inventory
require_once __DIR__ . '/../includes/simple_auth.php';
require_once __DIR__ . '/../includes/helpers.php';

auth_require_login();

$pdo = Database::pdo();
$message = '';
$error = '';

// Handle photo upload for misc items
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['upload_photo'])) {
    $item_id = (int)$_POST['item_id'];
    
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
                $upload_dir = __DIR__ . '/../uploads/misc_items';
                if (!is_dir($upload_dir)) {
                    mkdir($upload_dir, 0777, true);
                }
                
                // Generate unique filename
                $extension = pathinfo($file['name'], PATHINFO_EXTENSION);
                $filename = 'misc_' . $item_id . '_' . time() . '.' . $extension;
                $filepath = $upload_dir . '/' . $filename;
                
                if (move_uploaded_file($file['tmp_name'], $filepath)) {
                    // Update database
                    $stmt = $pdo->prepare("UPDATE misc_items SET photo_path = ?, photo_size = ? WHERE id = ?");
                    $stmt->execute(['/uploads/misc_items/' . $filename, $file['size'], $item_id]);
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

// Handle QR code generation for misc items
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['generate_qr'])) {
    $item_id = (int)$_POST['item_id'];
    
    // Get item information for QR code
    $stmt = $pdo->prepare("
        SELECT m.id, m.name, m.unit_label, 
               cms.total_stock_quantity, cms.avg_cost_per_unit, m.photo_path
        FROM misc_items m
        LEFT JOIN current_misc_stock cms ON m.id = cms.id
        WHERE m.id = ?
    ");
    $stmt->execute([$item_id]);
    $item_data = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($item_data) {
        // Create QR code data (JSON format for scanning)
        $qr_data = json_encode([
            'type' => 'misc_inventory',
            'id' => $item_data['id'],
            'name' => $item_data['name'],
            'unit' => $item_data['unit_label'],
            'stock_quantity' => $item_data['total_stock_quantity'],
            'price_per_unit' => $item_data['avg_cost_per_unit'],
            'photo' => $item_data['photo_path'] ? ('http://localhost' . $item_data['photo_path']) : null,
            'scan_time' => date('Y-m-d H:i:s')
        ]);
        
        // Generate QR code filename
        $qr_filename = 'qr_misc_' . $item_id . '.png';
        $qr_path = '/uploads/qr/' . $qr_filename;
        $qr_full_path = __DIR__ . '/../uploads/qr/' . $qr_filename;
        
        // Create QR directory
        $qr_dir = dirname($qr_full_path);
        if (!is_dir($qr_dir)) {
            mkdir($qr_dir, 0777, true);
        }
        
        // Simple QR code generation (placeholder)
        $image = imagecreate(200, 200);
        $bg_color = imagecolorallocate($image, 255, 255, 255);
        $text_color = imagecolorallocate($image, 0, 0, 0);
        
        imagestring($image, 3, 10, 10, "QR: Item #" . $item_id, $text_color);
        imagestring($image, 2, 10, 30, substr($item_data['name'], 0, 25), $text_color);
        imagestring($image, 2, 10, 50, "Stock: " . $item_data['total_stock_quantity'] . " " . $item_data['unit_label'], $text_color);
        imagestring($image, 2, 10, 70, "Price: $" . number_format($item_data['avg_cost_per_unit'], 2), $text_color);
        
        if (imagepng($image, $qr_full_path)) {
            // Update database
            $stmt = $pdo->prepare("UPDATE misc_items SET qr_code_path = ? WHERE id = ?");
            $stmt->execute([$qr_path, $item_id]);
            $message = 'QR Code generated successfully';
        } else {
            $error = 'Failed to generate QR code';
        }
        
        imagedestroy($image);
    } else {
        $error = 'Item not found';
    }
}

// Get search parameters
$search = trim($_GET['search'] ?? '');

// Build query with search
$where_clause = '';
$params = [];

if ($search) {
    $where_clause = "WHERE m.name LIKE ? OR m.unit_label LIKE ?";
    $params[] = "%$search%";
    $params[] = "%$search%";
}

// Get misc items data with stock information
$items_query = "
    SELECT m.id, m.name, m.unit_label, m.photo_path, m.qr_code_path,
           cms.total_stock_quantity, cms.avg_cost_per_unit,
           cms.min_cost_per_unit, cms.max_cost_per_unit, cms.purchase_count
    FROM misc_items m
    LEFT JOIN current_misc_stock cms ON m.id = cms.id
    $where_clause
    ORDER BY m.name
";

$stmt = $pdo->prepare($items_query);
$stmt->execute($params);
$items = $stmt->fetchAll(PDO::FETCH_ASSOC);

$page_title = "Other Items Inventory";
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
    min-width: 1000px;
}

.inventory-table th {
    position: sticky;
    top: 0;
    background: linear-gradient(135deg, #28a745 0%, #20c997 100%);
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

<!-- Search Section -->
<div class="search-section">
    <form method="GET" class="row g-3">
        <div class="col-md-6">
            <label class="form-label">Search Items</label>
            <input type="text" class="form-control" name="search" value="<?= h($search) ?>" 
                   placeholder="Search by item name or unit...">
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
    <h4 class="mb-0">Other Items Inventory (<?= count($items) ?> items)</h4>
    <div class="btn-group">
        <a href="misc_items.php" class="btn btn-success">
            <i class="bi bi-plus-circle"></i> Add New Item
        </a>
        <a href="other_purchase.php" class="btn btn-primary">
            <i class="bi bi-cart-plus"></i> Purchase Entry
        </a>
        <button type="button" class="btn btn-outline-info" onclick="exportData()">
            <i class="bi bi-download"></i> Export
        </button>
    </div>
</div>

<!-- Enhanced Inventory Table -->
<div class="inventory-table-container">
    <table class="table table-hover inventory-table" id="inventoryTable">
        <thead>
            <tr>
                <th style="min-width: 200px;">Item Name</th>
                <th style="min-width: 100px;">Unit</th>
                <th style="min-width: 80px;">Photo</th>
                <th style="min-width: 120px;">Stock Quantity</th>
                <th style="min-width: 120px;">Avg Cost/Unit</th>
                <th style="min-width: 100px;">Cost Range</th>
                <th style="min-width: 100px;">Purchases</th>
                <th style="min-width: 80px;">QR Code</th>
                <th style="min-width: 150px;">Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($items)): ?>
                <tr>
                    <td colspan="9" class="text-center text-muted py-4">
                        <i class="bi bi-inbox display-4"></i><br>
                        No items found. <a href="misc_items.php">Add some items</a> or adjust your search.
                    </td>
                </tr>
            <?php else: ?>
                <?php foreach ($items as $item): 
                    $stock_quantity = (float)($item['total_stock_quantity'] ?? 0);
                    $stock_class = $stock_quantity > 5 ? 'stock-good' : ($stock_quantity > 0 ? 'stock-low' : 'stock-out');
                ?>
                    <tr>
                        <td>
                            <strong><?= h($item['name']) ?></strong><br>
                            <small class="text-muted">ID: <?= $item['id'] ?></small>
                        </td>
                        <td><?= h($item['unit_label']) ?></td>
                        <td>
                            <?php if ($item['photo_path']): ?>
                                <img src="<?= h($item['photo_path']) ?>" class="photo-thumb" 
                                     onclick="viewPhoto('<?= h($item['photo_path']) ?>', '<?= h($item['name']) ?>')">
                            <?php else: ?>
                                <button type="button" class="btn btn-sm btn-outline-secondary" 
                                        onclick="uploadPhoto(<?= $item['id'] ?>)">
                                    <i class="bi bi-camera"></i>
                                </button>
                            <?php endif; ?>
                        </td>
                        <td>
                            <span class="stock-indicator <?= $stock_class ?>">
                                <?= number_format($stock_quantity, 1) ?>
                            </span>
                        </td>
                        <td>$<?= number_format($item['avg_cost_per_unit'] ?? 0, 2) ?></td>
                        <td>
                            <?php if ($item['min_cost_per_unit'] && $item['max_cost_per_unit']): ?>
                                $<?= number_format($item['min_cost_per_unit'], 2) ?> - 
                                $<?= number_format($item['max_cost_per_unit'], 2) ?>
                            <?php else: ?>
                                <span class="text-muted">N/A</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($item['purchase_count']): ?>
                                <span class="badge bg-info"><?= $item['purchase_count'] ?> entries</span>
                            <?php else: ?>
                                <span class="text-muted">No purchases</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($item['qr_code_path']): ?>
                                <img src="<?= h($item['qr_code_path']) ?>" class="qr-thumb" 
                                     onclick="viewQR('<?= h($item['qr_code_path']) ?>', '<?= h($item['name']) ?>')">
                            <?php else: ?>
                                <button type="button" class="btn btn-sm btn-outline-primary" 
                                        onclick="generateQR(<?= $item['id'] ?>)">
                                    <i class="bi bi-qr-code"></i>
                                </button>
                            <?php endif; ?>
                        </td>
                        <td>
                            <div class="btn-group btn-group-sm">
                                <a href="other_purchase.php?item_id=<?= $item['id'] ?>" class="btn btn-success" title="Add Purchase">
                                    <i class="bi bi-plus-circle"></i>
                                </a>
                                <button type="button" class="btn btn-info" onclick="viewHistory(<?= $item['id'] ?>)" title="View History">
                                    <i class="bi bi-clock-history"></i>
                                </button>
                                <a href="misc_items.php#item<?= $item['id'] ?>" class="btn btn-warning" title="Edit Item">
                                    <i class="bi bi-pencil"></i>
                                </a>
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
                    <input type="hidden" name="item_id" id="photoItemId">
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
                <h5 class="modal-title" id="photoViewTitle">Item Photo</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body text-center">
                <img id="photoViewImage" src="" class="img-fluid">
            </div>
        </div>
    </div>
</div>

<!-- QR Generation Form (Hidden) -->
<form id="qrGenerationForm" method="post" style="display: none;">
    <input type="hidden" name="item_id" id="qrItemId">
    <input type="hidden" name="generate_qr" value="1">
</form>

<script>
function uploadPhoto(itemId) {
    document.getElementById('photoItemId').value = itemId;
    new bootstrap.Modal(document.getElementById('photoUploadModal')).show();
}

function viewPhoto(photoPath, itemName) {
    document.getElementById('photoViewTitle').textContent = itemName + ' - Photo';
    document.getElementById('photoViewImage').src = photoPath;
    new bootstrap.Modal(document.getElementById('photoViewModal')).show();
}

function generateQR(itemId) {
    if (confirm('Generate QR code for this item?')) {
        document.getElementById('qrItemId').value = itemId;
        document.getElementById('qrGenerationForm').submit();
    }
}

function viewQR(qrPath, itemName) {
    document.getElementById('photoViewTitle').textContent = itemName + ' - QR Code';
    document.getElementById('photoViewImage').src = qrPath;
    new bootstrap.Modal(document.getElementById('photoViewModal')).show();
}

function viewHistory(itemId) {
    window.open(`other_purchase.php?item_id=${itemId}&view=history`, '_blank');
}

function exportData() {
    alert('Data export feature coming soon!');
}
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>