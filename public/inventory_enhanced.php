<?php
// public/inventory_enhanced.php - Enhanced Inventory Management with all required fields
require_once __DIR__ . '/../includes/Database.php';
require_once __DIR__ . '/../includes/auth_enhanced.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/inventory_calculations.php';

AuthSystem::init();
auth_require_login();

if (!auth_has_permission('inventory.view')) {
    header('Location: index.php?error=' . urlencode('Access denied'));
    exit;
}

$pdo = Database::pdo();

// Ensure enhanced inventory table exists with all required fields
$pdo->exec("
    CREATE TABLE IF NOT EXISTS inventory_items_enhanced (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        tile_id INTEGER NOT NULL,
        purchase_dt TEXT NOT NULL,
        purchase_box_value REAL DEFAULT 0,
        transport_pct REAL DEFAULT 0,
        transport_per_box REAL DEFAULT 0,
        boxes_in REAL NOT NULL,
        vendor TEXT,
        vehicle_no TEXT,
        tax_invoice_no TEXT,
        damage_boxes REAL DEFAULT 0,
        damage_sqft REAL DEFAULT 0,
        notes TEXT,
        created_at TEXT DEFAULT CURRENT_TIMESTAMP,
        created_by INTEGER,
        updated_at TEXT DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (tile_id) REFERENCES tiles(id),
        FOREIGN KEY (created_by) REFERENCES users_enhanced(id)
    )
");

// Create barcode table for tiles
$pdo->exec("
    CREATE TABLE IF NOT EXISTS tile_barcodes (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        tile_id INTEGER NOT NULL UNIQUE,
        barcode TEXT UNIQUE NOT NULL,
        qr_token TEXT UNIQUE,
        public_visible INTEGER DEFAULT 1,
        created_at TEXT DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (tile_id) REFERENCES tiles(id)
    )
");

// Create photo gallery table
$pdo->exec("
    CREATE TABLE IF NOT EXISTS tile_photos (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        tile_id INTEGER NOT NULL,
        photo_path TEXT NOT NULL,
        photo_name TEXT,
        is_primary INTEGER DEFAULT 0,
        uploaded_by INTEGER,
        uploaded_at TEXT DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (tile_id) REFERENCES tiles(id),
        FOREIGN KEY (uploaded_by) REFERENCES users_enhanced(id)
    )
");

$message = '';
$error = '';

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $current_user = auth_get_user();
    
    // Add new inventory entry
    if (isset($_POST['add_inventory']) && auth_has_permission('inventory.create')) {
        $tile_id = (int)($_POST['tile_id'] ?? 0);
        $purchase_dt = $_POST['purchase_dt'] ?? date('Y-m-d');
        $purchase_box_value = (float)($_POST['purchase_box_value'] ?? 0);
        $transport_pct = (float)($_POST['transport_pct'] ?? 0);
        $transport_per_box = (float)($_POST['transport_per_box'] ?? 0);
        $boxes_in = (float)($_POST['boxes_in'] ?? 0);
        $vendor = trim($_POST['vendor'] ?? '');
        $vehicle_no = trim($_POST['vehicle_no'] ?? '');
        $tax_invoice_no = trim($_POST['tax_invoice_no'] ?? '');
        $damage_boxes = (float)($_POST['damage_boxes'] ?? 0);
        $damage_sqft = (float)($_POST['damage_sqft'] ?? 0);
        $notes = trim($_POST['notes'] ?? '');
        
        // Validation
        $validation_data = [
            'tile_id' => $tile_id,
            'boxes_in' => $boxes_in,
            'damage_boxes' => $damage_boxes,
            'per_box_value' => $purchase_box_value,
            'transport_pct' => $transport_pct,
            'transport_per_box' => $transport_per_box
        ];
        
        $validation = InventoryCalculations::validateInventoryData($validation_data);
        
        if (!$validation['valid']) {
            $error = implode(', ', $validation['errors']);
        } else {
            try {
                $stmt = $pdo->prepare("
                    INSERT INTO inventory_items_enhanced (
                        tile_id, purchase_dt, purchase_box_value, transport_pct, transport_per_box,
                        boxes_in, vendor, vehicle_no, tax_invoice_no, damage_boxes, damage_sqft,
                        notes, created_by
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                
                if ($stmt->execute([
                    $tile_id, $purchase_dt, $purchase_box_value, $transport_pct, $transport_per_box,
                    $boxes_in, $vendor, $vehicle_no, $tax_invoice_no, $damage_boxes, $damage_sqft,
                    $notes, $current_user['id']
                ])) {
                    $inventory_id = $pdo->lastInsertId();
                    
                    // Auto-generate barcode if not exists
                    generateTileBarcode($pdo, $tile_id);
                    
                    $message = 'Inventory entry added successfully';
                    
                    if (!empty($validation['warnings'])) {
                        $message .= '. Warnings: ' . implode(', ', $validation['warnings']);
                    }
                } else {
                    $error = 'Failed to add inventory entry';
                }
            } catch (Exception $e) {
                $error = 'Database error: ' . $e->getMessage();
            }
        }
    }
    
    // Upload photos
    if (isset($_POST['upload_photos']) && isset($_FILES['photos']) && auth_has_permission('inventory.edit')) {
        $tile_id = (int)($_POST['tile_id'] ?? 0);
        $upload_dir = __DIR__ . '/../uploads/tiles/';
        
        if (!is_dir($upload_dir)) {
            mkdir($upload_dir, 0755, true);
        }
        
        $uploaded_count = 0;
        foreach ($_FILES['photos']['tmp_name'] as $key => $tmp_name) {
            if ($_FILES['photos']['error'][$key] === UPLOAD_ERR_OK) {
                $file_name = $_FILES['photos']['name'][$key];
                $file_ext = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));
                
                if (in_array($file_ext, ['jpg', 'jpeg', 'png', 'webp'])) {
                    $new_file_name = 'tile_' . $tile_id . '_' . time() . '_' . $key . '.' . $file_ext;
                    $file_path = $upload_dir . $new_file_name;
                    
                    if (move_uploaded_file($tmp_name, $file_path)) {
                        $stmt = $pdo->prepare("
                            INSERT INTO tile_photos (tile_id, photo_path, photo_name, uploaded_by)
                            VALUES (?, ?, ?, ?)
                        ");
                        $stmt->execute([$tile_id, $new_file_name, $file_name, $current_user['id']]);
                        $uploaded_count++;
                    }
                }
            }
        }
        
        $message = $uploaded_count > 0 ? "$uploaded_count photos uploaded successfully" : "No valid photos to upload";
    }
    
    // Generate/Regenerate barcode
    if (isset($_POST['generate_barcode']) && auth_has_permission('inventory.edit')) {
        $tile_id = (int)($_POST['tile_id'] ?? 0);
        if (generateTileBarcode($pdo, $tile_id, true)) {
            $message = 'Barcode generated successfully';
        } else {
            $error = 'Failed to generate barcode';
        }
    }
}

// Helper function to generate barcode
function generateTileBarcode(PDO $pdo, int $tile_id, bool $regenerate = false): bool {
    if (!$regenerate) {
        // Check if barcode already exists
        $stmt = $pdo->prepare("SELECT id FROM tile_barcodes WHERE tile_id = ?");
        $stmt->execute([$tile_id]);
        if ($stmt->fetchColumn()) {
            return true; // Already exists
        }
    }
    
    // Generate unique barcode and QR token
    $barcode = 'TILE' . str_pad($tile_id, 6, '0', STR_PAD_LEFT) . rand(1000, 9999);
    $qr_token = bin2hex(random_bytes(16));
    
    try {
        if ($regenerate) {
            $stmt = $pdo->prepare("
                INSERT OR REPLACE INTO tile_barcodes (tile_id, barcode, qr_token)
                VALUES (?, ?, ?)
            ");
        } else {
            $stmt = $pdo->prepare("
                INSERT INTO tile_barcodes (tile_id, barcode, qr_token)
                VALUES (?, ?, ?)
            ");
        }
        
        return $stmt->execute([$tile_id, $barcode, $qr_token]);
    } catch (Exception $e) {
        return false;
    }
}

// Get all tiles for dropdown
$tiles = $pdo->query("
    SELECT t.id, t.name, ts.label AS size_label, ts.sqft_per_box
    FROM tiles t
    JOIN tile_sizes ts ON ts.id = t.size_id
    ORDER BY t.name, ts.label
")->fetchAll(PDO::FETCH_ASSOC);

// Get inventory items with enhanced fields
$inventory_sql = "
    SELECT 
        ie.*,
        t.name AS tile_name,
        ts.label AS size_label,
        ts.sqft_per_box,
        u.username AS created_by_username,
        tb.barcode,
        tb.qr_token,
        COUNT(tp.id) AS photo_count
    FROM inventory_items_enhanced ie
    JOIN tiles t ON t.id = ie.tile_id
    JOIN tile_sizes ts ON ts.id = t.size_id
    LEFT JOIN users_enhanced u ON u.id = ie.created_by
    LEFT JOIN tile_barcodes tb ON tb.tile_id = ie.tile_id
    LEFT JOIN tile_photos tp ON tp.tile_id = ie.tile_id
    GROUP BY ie.id
    ORDER BY ie.created_at DESC
    LIMIT 50
";

$inventory_items = $pdo->query($inventory_sql)->fetchAll(PDO::FETCH_ASSOC);

$page_title = "Enhanced Inventory Management";
require_once __DIR__ . '/../includes/header.php';
?>

<style>
.enhanced-form {
    background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
    border-radius: 15px;
    padding: 2rem;
    box-shadow: 0 10px 30px rgba(0,0,0,0.1);
}

.inventory-card {
    transition: all 0.3s ease;
    border-radius: 15px;
    overflow: hidden;
}

.inventory-card:hover {
    transform: translateY(-5px);
    box-shadow: 0 15px 30px rgba(0,0,0,0.15);
}

.barcode-section {
    background: #f8f9fa;
    padding: 1rem;
    border-radius: 10px;
    font-family: monospace;
}

.photo-thumbnail {
    width: 60px;
    height: 60px;
    object-fit: cover;
    border-radius: 8px;
}

.calculation-preview {
    background: linear-gradient(135deg, #e3f2fd 0%, #f3e5f5 100%);
    border-radius: 10px;
    padding: 1rem;
}

.field-group {
    background: white;
    border-radius: 10px;
    padding: 1.5rem;
    margin-bottom: 1rem;
    box-shadow: 0 2px 10px rgba(0,0,0,0.05);
}

.required-field::after {
    content: " *";
    color: #dc3545;
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

<!-- Add New Inventory Entry -->
<?php if (auth_has_permission('inventory.create')): ?>
<div class="enhanced-form mb-4">
    <h5 class="mb-4">
        <i class="bi bi-plus-circle me-2"></i>Add New Inventory Entry
    </h5>
    
    <form method="post" id="inventoryForm">
        <!-- Basic Information -->
        <div class="field-group">
            <h6 class="text-primary mb-3">
                <i class="bi bi-info-circle me-2"></i>Basic Information
            </h6>
            <div class="row g-3">
                <div class="col-md-6">
                    <label class="form-label required-field">Tile</label>
                    <select class="form-select" name="tile_id" required onchange="updateCalculations()">
                        <option value="">Select Tile</option>
                        <?php foreach ($tiles as $tile): ?>
                            <option value="<?= $tile['id'] ?>" data-spb="<?= $tile['sqft_per_box'] ?>">
                                <?= h($tile['name']) ?> (<?= h($tile['size_label']) ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-6">
                    <label class="form-label required-field">Purchase Date</label>
                    <input type="date" class="form-control" name="purchase_dt" value="<?= date('Y-m-d') ?>" required>
                </div>
            </div>
        </div>

        <!-- Purchase Details -->
        <div class="field-group">
            <h6 class="text-primary mb-3">
                <i class="bi bi-cash-coin me-2"></i>Purchase Details
            </h6>
            <div class="row g-3">
                <div class="col-md-4">
                    <label class="form-label required-field">Purchase Box Value (₹)</label>
                    <input type="number" step="0.01" class="form-control" name="purchase_box_value" 
                           required min="0" onchange="updateCalculations()">
                </div>
                <div class="col-md-4">
                    <label class="form-label">Transport % of Base Cost</label>
                    <input type="number" step="0.01" class="form-control" name="transport_pct" 
                           min="0" max="100" onchange="updateCalculations()">
                </div>
                <div class="col-md-4">
                    <label class="form-label">Transport Per Box (₹)</label>
                    <input type="number" step="0.01" class="form-control" name="transport_per_box" 
                           min="0" onchange="updateCalculations()">
                </div>
                <div class="col-md-6">
                    <label class="form-label">Vendor</label>
                    <input type="text" class="form-control" name="vendor" placeholder="Supplier name">
                </div>
                <div class="col-md-6">
                    <label class="form-label">Tax Invoice No</label>
                    <input type="text" class="form-control" name="tax_invoice_no" placeholder="Invoice number">
                </div>
            </div>
        </div>

        <!-- Quantity & Logistics -->
        <div class="field-group">
            <h6 class="text-primary mb-3">
                <i class="bi bi-boxes me-2"></i>Quantity & Logistics
            </h6>
            <div class="row g-3">
                <div class="col-md-4">
                    <label class="form-label required-field">Boxes In</label>
                    <input type="number" step="0.001" class="form-control" name="boxes_in" 
                           required min="0" onchange="updateCalculations()">
                </div>
                <div class="col-md-4">
                    <label class="form-label">Damage Boxes</label>
                    <input type="number" step="0.001" class="form-control" name="damage_boxes" 
                           min="0" onchange="updateCalculations()">
                </div>
                <div class="col-md-4">
                    <label class="form-label">Damage Sqft</label>
                    <input type="number" step="0.01" class="form-control" name="damage_sqft" min="0">
                </div>
                <div class="col-md-6">
                    <label class="form-label">Vehicle Number</label>
                    <input type="text" class="form-control" name="vehicle_no" placeholder="e.g., GJ01AB1234">
                </div>
                <div class="col-md-6">
                    <label class="form-label">Notes</label>
                    <input type="text" class="form-control" name="notes" placeholder="Additional notes">
                </div>
            </div>
        </div>

        <!-- Live Calculations -->
        <div class="calculation-preview">
            <h6 class="text-success mb-3">
                <i class="bi bi-calculator me-2"></i>Live Calculations
            </h6>
            <div class="row g-3">
                <div class="col-md-3">
                    <div class="text-center">
                        <div class="h4 text-primary" id="netBoxes">0</div>
                        <small class="text-muted">Net Boxes</small>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="text-center">
                        <div class="h4 text-success" id="finalCostPerBox">₹0</div>
                        <small class="text-muted">Final Cost/Box</small>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="text-center">
                        <div class="h4 text-info" id="totalValue">₹0</div>
                        <small class="text-muted">Total Value</small>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="text-center">
                        <div class="h4 text-warning" id="damagePercentage">0%</div>
                        <small class="text-muted">Damage %</small>
                    </div>
                </div>
            </div>
        </div>

        <div class="text-end mt-4">
            <button type="submit" name="add_inventory" class="btn btn-primary btn-lg">
                <i class="bi bi-plus-circle me-2"></i>Add Inventory Entry
            </button>
        </div>
    </form>
</div>
<?php endif; ?>

<!-- Inventory List -->
<div class="card">
    <div class="card-header">
        <h5 class="mb-0">
            <i class="bi bi-list-ul me-2"></i>Recent Inventory Entries
        </h5>
    </div>
    <div class="card-body">
        <?php if (empty($inventory_items)): ?>
            <div class="text-center py-4">
                <i class="bi bi-inbox display-1 text-muted"></i>
                <h5 class="text-muted mt-3">No inventory entries found</h5>
                <p class="text-muted">Add your first inventory entry using the form above.</p>
            </div>
        <?php else: ?>
            <div class="row g-3">
                <?php foreach ($inventory_items as $item): 
                    $calc = InventoryCalculations::calculateItemCosts($item, (float)$item['sqft_per_box']);
                ?>
                <div class="col-lg-6">
                    <div class="inventory-card card h-100">
                        <div class="card-body">
                            <div class="d-flex justify-content-between align-items-start mb-2">
                                <h6 class="card-title text-primary">
                                    <?= h($item['tile_name']) ?>
                                    <small class="text-muted">(<?= h($item['size_label']) ?>)</small>
                                </h6>
                                <span class="badge text-bg-secondary">#<?= $item['id'] ?></span>
                            </div>

                            <div class="row g-2 mb-3">
                                <div class="col-6">
                                    <small class="text-muted">Purchase Date:</small><br>
                                    <strong><?= date('M j, Y', strtotime($item['purchase_dt'])) ?></strong>
                                </div>
                                <div class="col-6">
                                    <small class="text-muted">Vendor:</small><br>
                                    <strong><?= h($item['vendor'] ?: 'N/A') ?></strong>
                                </div>
                                <div class="col-6">
                                    <small class="text-muted">Boxes In:</small><br>
                                    <strong class="text-success"><?= n2($item['boxes_in']) ?></strong>
                                </div>
                                <div class="col-6">
                                    <small class="text-muted">Net Boxes:</small><br>
                                    <strong class="text-primary"><?= n2($calc['net_boxes']) ?></strong>
                                </div>
                            </div>

                            <?php if ($item['damage_boxes'] > 0): ?>
                                <div class="alert alert-warning py-2 px-3 mb-2">
                                    <small>
                                        <i class="bi bi-exclamation-triangle me-1"></i>
                                        Damage: <?= n2($item['damage_boxes']) ?> boxes 
                                        (<?= n2($calc['damage_percentage']) ?>%)
                                    </small>
                                </div>
                            <?php endif; ?>

                            <div class="calculation-preview small">
                                <div class="row g-2">
                                    <div class="col-6">
                                        <strong>Cost/Box:</strong> ₹<?= n2($calc['final_cost_per_box']) ?>
                                    </div>
                                    <div class="col-6">
                                        <strong>Total Value:</strong> ₹<?= n2($calc['total_final_value']) ?>
                                    </div>
                                </div>
                            </div>

                            <?php if (auth_has_permission('inventory.view_costs')): ?>
                            <div class="mt-2">
                                <small class="text-muted">
                                    Base: ₹<?= n2($calc['base_cost_per_box']) ?> | 
                                    Transport: ₹<?= n2($calc['total_transport_per_box']) ?>
                                </small>
                            </div>
                            <?php endif; ?>

                            <!-- Barcode Section -->
                            <?php if ($item['barcode']): ?>
                            <div class="barcode-section mt-3">
                                <small class="text-muted d-block">Barcode:</small>
                                <strong class="h6"><?= h($item['barcode']) ?></strong>
                                <?php if ($item['qr_token']): ?>
                                    <button type="button" class="btn btn-sm btn-outline-info ms-2" 
                                            onclick="viewQRCode('<?= h($item['qr_token']) ?>')">
                                        <i class="bi bi-qr-code"></i>
                                    </button>
                                <?php endif; ?>
                            </div>
                            <?php endif; ?>

                            <!-- Additional Details -->
                            <div class="mt-3">
                                <?php if ($item['vehicle_no']): ?>
                                    <small class="text-muted">
                                        <i class="bi bi-truck"></i> <?= h($item['vehicle_no']) ?>
                                    </small><br>
                                <?php endif; ?>
                                <?php if ($item['tax_invoice_no']): ?>
                                    <small class="text-muted">
                                        <i class="bi bi-receipt"></i> <?= h($item['tax_invoice_no']) ?>
                                    </small><br>
                                <?php endif; ?>
                                <?php if ($item['photo_count'] > 0): ?>
                                    <small class="text-success">
                                        <i class="bi bi-images"></i> <?= $item['photo_count'] ?> photos
                                    </small><br>
                                <?php endif; ?>
                                <small class="text-muted">
                                    Created by <?= h($item['created_by_username'] ?? 'System') ?> on 
                                    <?= date('M j, Y g:i A', strtotime($item['created_at'])) ?>
                                </small>
                            </div>
                        </div>
                        
                        <?php if (auth_has_permission('inventory.edit')): ?>
                        <div class="card-footer">
                            <div class="btn-group w-100" role="group">
                                <button type="button" class="btn btn-sm btn-outline-primary" 
                                        onclick="editInventoryItem(<?= $item['id'] ?>)">
                                    <i class="bi bi-pencil"></i>
                                </button>
                                
                                <button type="button" class="btn btn-sm btn-outline-success" 
                                        onclick="uploadPhotos(<?= $item['tile_id'] ?>)">
                                    <i class="bi bi-camera"></i>
                                </button>
                                
                                <button type="button" class="btn btn-sm btn-outline-info" 
                                        onclick="generateBarcode(<?= $item['tile_id'] ?>)">
                                    <i class="bi bi-upc-scan"></i>
                                </button>
                                
                                <?php if (auth_has_permission('inventory.delete')): ?>
                                <button type="button" class="btn btn-sm btn-outline-danger" 
                                        onclick="deleteInventoryItem(<?= $item['id'] ?>)">
                                    <i class="bi bi-trash"></i>
                                </button>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- Photo Upload Modal -->
<div class="modal fade" id="photoUploadModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="post" enctype="multipart/form-data">
                <div class="modal-header">
                    <h5 class="modal-title">Upload Photos</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="tile_id" id="photoTileId">
                    <div class="mb-3">
                        <label class="form-label">Select Photos</label>
                        <input type="file" class="form-control" name="photos[]" multiple 
                               accept="image/jpeg,image/jpg,image/png,image/webp" required>
                        <div class="form-text">
                            Supported formats: JPG, PNG, WebP. Max 5 files at once.
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="upload_photos" class="btn btn-success">
                        <i class="bi bi-upload"></i> Upload Photos
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
let currentTileSpb = 0;

function updateCalculations() {
    const tileSelect = document.querySelector('[name="tile_id"]');
    const boxValue = parseFloat(document.querySelector('[name="purchase_box_value"]').value) || 0;
    const transportPct = parseFloat(document.querySelector('[name="transport_pct"]').value) || 0;
    const transportPerBox = parseFloat(document.querySelector('[name="transport_per_box"]').value) || 0;
    const boxesIn = parseFloat(document.querySelector('[name="boxes_in"]').value) || 0;
    const damageBoxes = parseFloat(document.querySelector('[name="damage_boxes"]').value) || 0;
    
    // Get sqft per box from selected tile
    if (tileSelect.selectedIndex > 0) {
        currentTileSpb = parseFloat(tileSelect.options[tileSelect.selectedIndex].dataset.spb) || 1;
    }
    
    // Calculate values
    const netBoxes = Math.max(0, boxesIn - damageBoxes);
    const transportFromPct = boxValue * (transportPct / 100);
    const totalTransportPerBox = transportFromPct + transportPerBox;
    const finalCostPerBox = boxValue + totalTransportPerBox;
    const totalValue = netBoxes * finalCostPerBox;
    const damagePercentage = boxesIn > 0 ? (damageBoxes / boxesIn) * 100 : 0;
    
    // Update display
    document.getElementById('netBoxes').textContent = netBoxes.toFixed(3);
    document.getElementById('finalCostPerBox').textContent = '₹' + finalCostPerBox.toFixed(2);
    document.getElementById('totalValue').textContent = '₹' + totalValue.toFixed(2);
    document.getElementById('damagePercentage').textContent = damagePercentage.toFixed(1) + '%';
    
    // Visual feedback for high damage
    const damageElement = document.getElementById('damagePercentage');
    if (damagePercentage > 10) {
        damageElement.className = 'h4 text-danger';
    } else if (damagePercentage > 5) {
        damageElement.className = 'h4 text-warning';
    } else {
        damageElement.className = 'h4 text-success';
    }
}

function uploadPhotos(tileId) {
    document.getElementById('photoTileId').value = tileId;
    new bootstrap.Modal(document.getElementById('photoUploadModal')).show();
}

function generateBarcode(tileId) {
    if (confirm('Generate/Regenerate barcode for this tile?')) {
        const form = document.createElement('form');
        form.method = 'POST';
        form.innerHTML = `
            <input type="hidden" name="tile_id" value="${tileId}">
            <input type="hidden" name="generate_barcode" value="1">
        `;
        document.body.appendChild(form);
        form.submit();
    }
}

function viewQRCode(token) {
    // Placeholder for QR code viewer
    alert('QR Code: ' + token + '\nFull functionality coming soon');
}

function editInventoryItem(itemId) {
    // Placeholder for edit functionality
    alert('Edit inventory item #' + itemId + '\nFull edit functionality coming soon');
}

function deleteInventoryItem(itemId) {
    if (confirm('Are you sure you want to delete this inventory item? This action cannot be undone.')) {
        // Placeholder for delete functionality
        alert('Delete functionality coming soon');
    }
}

// Initialize calculations on page load
document.addEventListener('DOMContentLoaded', function() {
    // Set up event listeners
    document.querySelectorAll('input[type="number"]').forEach(input => {
        input.addEventListener('input', updateCalculations);
    });
    
    document.querySelector('[name="tile_id"]').addEventListener('change', updateCalculations);
    
    // Initial calculation
    updateCalculations();
});

// Form validation
document.getElementById('inventoryForm').addEventListener('submit', function(e) {
    const boxesIn = parseFloat(document.querySelector('[name="boxes_in"]').value) || 0;
    const damageBoxes = parseFloat(document.querySelector('[name="damage_boxes"]').value) || 0;
    const boxValue = parseFloat(document.querySelector('[name="purchase_box_value"]').value) || 0;
    
    if (damageBoxes > boxesIn) {
        e.preventDefault();
        alert('Damage boxes cannot exceed total boxes received');
        return false;
    }
    
    if (boxValue <= 0) {
        e.preventDefault();
        alert('Purchase box value must be greater than 0');
        return false;
    }
    
    const damagePercentage = boxesIn > 0 ? (damageBoxes / boxesIn) * 100 : 0;
    if (damagePercentage > 20) {
        if (!confirm('Damage percentage is very high (' + damagePercentage.toFixed(1) + '%). Continue?')) {
            e.preventDefault();
            return false;
        }
    }
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>