<?php
// public/quotation_enhanced.php - Enhanced Quotation with improved functionality
require_once __DIR__ . '/../includes/simple_auth.php';
require_once __DIR__ . '/../includes/helpers.php';

auth_require_login();

$pdo = Database::pdo();
$message = '';
$error = '';
$id = (int)($_GET['id'] ?? 0);

// Get user preferences
$user_id = $_SESSION['user_id'] ?? 1;
$show_images_stmt = $pdo->prepare("SELECT preference_value FROM user_preferences WHERE user_id = ? AND preference_key = 'show_item_images'");
$show_images_stmt->execute([$user_id]);
$show_images = ($show_images_stmt->fetchColumn() === 'true');

// Handle user preference update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_preferences'])) {
    $show_images = isset($_POST['show_item_images']);
    $stmt = $pdo->prepare("INSERT OR REPLACE INTO user_preferences (user_id, preference_key, preference_value, updated_at) VALUES (?, ?, ?, datetime('now'))");
    $stmt->execute([$user_id, 'show_item_images', $show_images ? 'true' : 'false']);
    $message = 'Preferences updated successfully';
}

// Handle quotation creation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_quote'])) {
    $quote_no = 'Q' . date('ymdHis');
    $quote_dt = $_POST['quote_dt'] ?? date('Y-m-d');
    $customer_name = trim($_POST['customer_name'] ?? '');
    $firm_name = trim($_POST['firm_name'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $customer_gst = trim($_POST['customer_gst'] ?? '');
    $notes = trim($_POST['notes'] ?? '');
    
    // Validation
    if (!$customer_name) {
        $error = 'Customer name is required';
    } elseif (!$phone) {
        $error = 'Mobile number is required';
    } elseif (!preg_match('/^[0-9]{10}$/', $phone)) {
        $error = 'Mobile number must be 10 digits';
    } else {
        try {
            $stmt = $pdo->prepare("
                INSERT INTO quotations (quote_no, quote_dt, customer_name, firm_name, phone, customer_gst, notes, created_by, updated_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, datetime('now'))
            ");
            if ($stmt->execute([$quote_no, $quote_dt, $customer_name, $firm_name, $phone, $customer_gst, $notes, $user_id])) {
                $new_id = (int)$pdo->lastInsertId();
                safe_redirect('quotation_enhanced.php?id=' . $new_id);
            } else {
                $error = 'Failed to create quotation';
            }
        } catch (Exception $e) {
            $error = 'Database error: ' . $e->getMessage();
        }
    }
}

// Handle tile item addition
if ($id > 0 && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_tile_item'])) {
    $tile_id = (int)$_POST['tile_id'];
    $purpose = trim($_POST['purpose'] ?? '');
    $calculation_mode = $_POST['calculation_mode'] ?? 'sqft_mode';
    $show_image = isset($_POST['show_image']) ? 1 : 0;
    
    // Get tile info and current stock
    $tile_stmt = $pdo->prepare("
        SELECT t.name, ts.sqft_per_box, cts.total_stock_boxes
        FROM tiles t 
        JOIN tile_sizes ts ON t.size_id = ts.id
        LEFT JOIN current_tiles_stock cts ON t.id = cts.id
        WHERE t.id = ?
    ");
    $tile_stmt->execute([$tile_id]);
    $tile_info = $tile_stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$tile_info) {
        $error = 'Invalid tile selected';
    } else {
        $sqft_per_box = (float)$tile_info['sqft_per_box'];
        $current_stock = (float)($tile_info['total_stock_boxes'] ?? 0);
        
        if ($calculation_mode === 'sqft_mode') {
            $length_ft = (float)$_POST['length_ft'];
            $width_ft = (float)$_POST['width_ft'];
            $extra_sqft = (float)$_POST['extra_sqft'];
            $total_sqft = max(0.0, $length_ft * $width_ft + $extra_sqft);
            $boxes_decimal = $sqft_per_box > 0 ? ($total_sqft / $sqft_per_box) : 0.0;
            $direct_boxes = null;
        } else {
            $boxes_decimal = (float)$_POST['direct_boxes'];
            $direct_boxes = $boxes_decimal;
            $total_sqft = $boxes_decimal * $sqft_per_box;
            $length_ft = 0;
            $width_ft = 0;
            $extra_sqft = 0;
        }
        
        $rate_per_box = (float)$_POST['rate_per_box'];
        $rate_per_sqft = $sqft_per_box > 0 ? ($rate_per_box / $sqft_per_box) : 0.0;
        $line_total = $rate_per_box * $boxes_decimal;
        
        // Check stock availability
        if ($boxes_decimal > $current_stock && $current_stock > 0) {
            $error = "Warning: Requested {$boxes_decimal} boxes but only {$current_stock} boxes available in stock";
        }
        
        if (!$error) {
            try {
                $stmt = $pdo->prepare("
                    INSERT INTO quotation_items 
                    (quotation_id, purpose, tile_id, calculation_mode, direct_boxes, length_ft, width_ft, extra_sqft, total_sqft, rate_per_sqft, rate_per_box, boxes_decimal, line_total, show_image)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                if ($stmt->execute([$id, $purpose, $tile_id, $calculation_mode, $direct_boxes, $length_ft, $width_ft, $extra_sqft, $total_sqft, $rate_per_sqft, $rate_per_box, $boxes_decimal, $line_total, $show_image])) {
                    $message = 'Tile item added successfully';
                    safe_redirect('quotation_enhanced.php?id=' . $id);
                } else {
                    $error = 'Failed to add tile item';
                }
            } catch (Exception $e) {
                $error = 'Database error: ' . $e->getMessage();
            }
        }
    }
}

// Handle item deletion
if ($id > 0 && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_item'])) {
    $item_id = (int)$_POST['item_id'];
    $item_type = $_POST['item_type'];
    
    try {
        if ($item_type === 'tile') {
            $stmt = $pdo->prepare("DELETE FROM quotation_items WHERE id = ? AND quotation_id = ?");
        } else {
            $stmt = $pdo->prepare("DELETE FROM quotation_misc_items WHERE id = ? AND quotation_id = ?");
        }
        
        if ($stmt->execute([$item_id, $id])) {
            // Update quotation total
            updateQuotationTotal($pdo, $id);
            $message = 'Item deleted successfully';
            safe_redirect('quotation_enhanced.php?id=' . $id);
        } else {
            $error = 'Failed to delete item';
        }
    } catch (Exception $e) {
        $error = 'Database error: ' . $e->getMessage();
    }
}

// Handle item update
if ($id > 0 && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_item'])) {
    $item_id = (int)$_POST['item_id'];
    $item_type = $_POST['item_type'];
    $new_quantity = (float)$_POST['new_quantity'];
    $new_rate = (float)$_POST['new_rate'];
    $new_line_total = $new_quantity * $new_rate;
    
    try {
        if ($item_type === 'tile') {
            $stmt = $pdo->prepare("
                UPDATE quotation_items 
                SET boxes_decimal = ?, rate_per_box = ?, line_total = ?
                WHERE id = ? AND quotation_id = ?
            ");
            $stmt->execute([$new_quantity, $new_rate, $new_line_total, $item_id, $id]);
        } else {
            $stmt = $pdo->prepare("
                UPDATE quotation_misc_items 
                SET qty_units = ?, rate_per_unit = ?, line_total = ?
                WHERE id = ? AND quotation_id = ?
            ");
            $stmt->execute([$new_quantity, $new_rate, $new_line_total, $item_id, $id]);
        }
        
        // Update quotation total
        updateQuotationTotal($pdo, $id);
        $message = 'Item updated successfully';
        safe_redirect('quotation_enhanced.php?id=' . $id);
    } catch (Exception $e) {
        $error = 'Database error: ' . $e->getMessage();
    }
}

// Function to update quotation total
function updateQuotationTotal($pdo, $quotation_id) {
    $total_stmt = $pdo->prepare("
        SELECT 
            COALESCE(SUM(qi.line_total), 0) + COALESCE(SUM(qmi.line_total), 0) as total
        FROM quotations q
        LEFT JOIN quotation_items qi ON q.id = qi.quotation_id
        LEFT JOIN quotation_misc_items qmi ON q.id = qmi.quotation_id
        WHERE q.id = ?
    ");
    $total_stmt->execute([$quotation_id]);
    $total = (float)$total_stmt->fetchColumn();
    
    $update_stmt = $pdo->prepare("UPDATE quotations SET total = ?, updated_at = datetime('now') WHERE id = ?");
    $update_stmt->execute([$total, $quotation_id]);
}

// Handle misc item addition
if ($id > 0 && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_misc_item'])) {
    $misc_item_id = (int)$_POST['misc_item_id'];
    $purpose = trim($_POST['purpose'] ?? '');
    $qty_units = (float)$_POST['qty_units'];
    $rate_per_unit = (float)$_POST['rate_per_unit'];
    $show_image = isset($_POST['show_image']) ? 1 : 0;
    $line_total = $qty_units * $rate_per_unit;
    
    // Check stock availability for misc items
    $misc_stmt = $pdo->prepare("
        SELECT m.name, cms.total_stock_quantity
        FROM misc_items m
        LEFT JOIN current_misc_stock cms ON m.id = cms.id
        WHERE m.id = ?
    ");
    $misc_stmt->execute([$misc_item_id]);
    $misc_info = $misc_stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$misc_info) {
        $error = 'Invalid item selected';
    } else {
        $current_stock = (float)($misc_info['total_stock_quantity'] ?? 0);
        
        // Check stock availability
        if ($qty_units > $current_stock && $current_stock > 0) {
            $error = "Warning: Requested {$qty_units} units but only {$current_stock} units available in stock";
        }
        
        if (!$error) {
            try {
                $stmt = $pdo->prepare("
                    INSERT INTO quotation_misc_items 
                    (quotation_id, purpose, misc_item_id, qty_units, rate_per_unit, line_total, show_image)
                    VALUES (?, ?, ?, ?, ?, ?, ?)
                ");
                if ($stmt->execute([$id, $purpose, $misc_item_id, $qty_units, $rate_per_unit, $line_total, $show_image])) {
                    $message = 'Misc item added successfully';
                    safe_redirect('quotation_enhanced.php?id=' . $id);
                } else {
                    $error = 'Failed to add misc item';
                }
            } catch (Exception $e) {
                $error = 'Database error: ' . $e->getMessage();
            }
        }
    }
}

// Get quotation data if ID provided
$quotation = null;
$quotation_items = [];
$quotation_misc_items = [];

if ($id > 0) {
    // Get quotation header
    $stmt = $pdo->prepare("SELECT * FROM quotations WHERE id = ?");
    $stmt->execute([$id]);
    $quotation = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($quotation) {
        // Get tile items with stock info
        $items_stmt = $pdo->prepare("
            SELECT qi.*, t.name as tile_name, ts.label as size_label, ts.sqft_per_box, t.photo_path,
                   cts.total_stock_boxes as current_stock
            FROM quotation_items qi
            JOIN tiles t ON qi.tile_id = t.id
            JOIN tile_sizes ts ON t.size_id = ts.id
            LEFT JOIN current_tiles_stock cts ON t.id = cts.id
            WHERE qi.quotation_id = ?
            ORDER BY qi.id
        ");
        $items_stmt->execute([$id]);
        $quotation_items = $items_stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Get misc items with stock info
        $misc_stmt = $pdo->prepare("
            SELECT qmi.*, m.name as item_name, m.unit_label, m.photo_path,
                   cms.total_stock_quantity as current_stock
            FROM quotation_misc_items qmi
            JOIN misc_items m ON qmi.misc_item_id = m.id
            LEFT JOIN current_misc_stock cms ON m.id = cms.id
            WHERE qmi.quotation_id = ?
            ORDER BY qmi.id
        ");
        $misc_stmt->execute([$id]);
        $quotation_misc_items = $misc_stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}

// Get tiles list with stock info for dropdown
$tiles_stmt = $pdo->query("
    SELECT t.id, t.name, ts.label as size_label, ts.sqft_per_box, t.photo_path,
           cts.total_stock_boxes as current_stock
    FROM tiles t
    JOIN tile_sizes ts ON t.size_id = ts.id
    LEFT JOIN current_tiles_stock cts ON t.id = cts.id
    ORDER BY t.name, ts.label
");
$tiles = $tiles_stmt->fetchAll(PDO::FETCH_ASSOC);

// Get misc items list with stock info for dropdown
$misc_items_stmt = $pdo->query("
    SELECT m.id, m.name, m.unit_label, m.photo_path,
           cms.total_stock_quantity as current_stock
    FROM misc_items m
    LEFT JOIN current_misc_stock cms ON m.id = cms.id
    ORDER BY m.name
");
$misc_items = $misc_items_stmt->fetchAll(PDO::FETCH_ASSOC);

$page_title = $quotation ? "Edit Quotation: " . $quotation['quote_no'] : "Create New Quotation";
require_once __DIR__ . '/../includes/header.php';
?>

<style>
.quotation-header {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
    border-radius: 12px;
    padding: 20px;
    margin-bottom: 20px;
}

.add-item-section {
    background: #f8f9fa;
    border-radius: 8px;
    padding: 20px;
    margin-bottom: 20px;
    border: 1px solid #dee2e6;
}

.calculation-toggle {
    background: #e3f2fd;
    border-radius: 6px;
    padding: 10px;
    margin-bottom: 15px;
}

.stock-warning {
    background: #fff3e0;
    border: 1px solid #ffb74d;
    border-radius: 4px;
    padding: 8px 12px;
    color: #f57c00;
    font-size: 0.9em;
}

.stock-available {
    background: #e8f5e8;
    border: 1px solid #81c784;
    border-radius: 4px;
    padding: 8px 12px;
    color: #2e7d32;
    font-size: 0.9em;
}

.item-image {
    width: 40px;
    height: 40px;
    object-fit: cover;
    border-radius: 4px;
    margin-right: 8px;
}

.calculation-mode-card {
    border: 2px solid #e9ecef;
    border-radius: 8px;
    transition: all 0.3s ease;
}

.calculation-mode-card.active {
    border-color: #007bff;
    background: #f8f9ff;
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

<!-- User Preferences Panel -->
<div class="card mb-3">
    <div class="card-body">
        <form method="post" class="d-flex align-items-center gap-3">
            <h6 class="mb-0">Display Preferences:</h6>
            <div class="form-check">
                <input class="form-check-input" type="checkbox" name="show_item_images" id="showImages" <?= $show_images ? 'checked' : '' ?>>
                <label class="form-check-label" for="showImages">Show item images</label>
            </div>
            <button type="submit" name="update_preferences" class="btn btn-sm btn-outline-primary">Update</button>
        </form>
    </div>
</div>

<?php if (!$quotation): ?>
    <!-- Create New Quotation -->
    <div class="quotation-header">
        <h4 class="mb-0"><i class="bi bi-file-earmark-plus"></i> Create New Quotation</h4>
    </div>

    <div class="card">
        <div class="card-body">
            <form method="post" id="createQuotationForm">
                <div class="row g-3">
                    <div class="col-md-3">
                        <label class="form-label">Quotation Date *</label>
                        <input type="date" class="form-control" name="quote_dt" value="<?= date('Y-m-d') ?>" required>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Customer Name *</label>
                        <input type="text" class="form-control" name="customer_name" required>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Firm Name</label>
                        <input type="text" class="form-control" name="firm_name" placeholder="Optional">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Mobile Number *</label>
                        <input type="tel" class="form-control" name="phone" pattern="[0-9]{10}" 
                               placeholder="10-digit mobile number" required>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Customer GST Number</label>
                        <input type="text" class="form-control" name="customer_gst" 
                               placeholder="Optional GST number">
                    </div>
                    <div class="col-md-8">
                        <label class="form-label">Notes</label>
                        <textarea class="form-control" name="notes" rows="2" placeholder="Additional notes"></textarea>
                    </div>
                </div>
                <div class="mt-4">
                    <button type="submit" name="create_quote" class="btn btn-primary btn-lg">
                        <i class="bi bi-check-circle"></i> Create Quotation
                    </button>
                    <a href="quotation_list_enhanced.php" class="btn btn-outline-secondary btn-lg ms-2">
                        <i class="bi bi-arrow-left"></i> Back to List
                    </a>
                </div>
            </form>
        </div>
    </div>

<?php else: ?>
    <!-- Edit Existing Quotation -->
    <div class="quotation-header">
        <div class="d-flex justify-content-between align-items-center">
            <div>
                <h4 class="mb-1"><?= h($quotation['quote_no']) ?></h4>
                <div>
                    <span class="badge bg-light text-dark me-2">
                        <i class="bi bi-calendar"></i> <?= date('M j, Y', strtotime($quotation['quote_dt'])) ?>
                    </span>
                    <span class="badge bg-light text-dark me-2">
                        <i class="bi bi-person"></i> <?= h($quotation['customer_name']) ?>
                    </span>
                    <?php if ($quotation['firm_name']): ?>
                    <span class="badge bg-light text-dark me-2">
                        <i class="bi bi-building"></i> <?= h($quotation['firm_name']) ?>
                    </span>
                    <?php endif; ?>
                    <span class="badge bg-light text-dark">
                        <i class="bi bi-telephone"></i> <?= h($quotation['phone']) ?>
                    </span>
                </div>
            </div>
            <div>
                <span class="badge bg-success fs-6">₹<?= number_format($quotation['total'], 2) ?></span>
            </div>
        </div>
    </div>

    <!-- Add Tile Item Section -->
    <div class="add-item-section">
        <h5><i class="bi bi-plus-circle"></i> Add Tile Item</h5>
        <form method="post" id="addTileForm">
            <input type="hidden" name="add_tile_item" value="1">
            
            <!-- Calculation Mode Toggle -->
            <div class="calculation-toggle">
                <label class="form-label fw-bold">Calculation Mode:</label>
                <div class="row g-2">
                    <div class="col-md-6">
                        <div class="calculation-mode-card p-3 active" onclick="selectCalculationMode('sqft_mode')">
                            <input type="radio" name="calculation_mode" value="sqft_mode" id="sqftMode" checked>
                            <label for="sqftMode" class="form-label fw-bold">Calculate by Area (Sq.Ft → Boxes)</label>
                            <p class="text-muted small mb-0">Enter length, width to calculate square feet and boxes</p>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="calculation-mode-card p-3" onclick="selectCalculationMode('direct_mode')">
                            <input type="radio" name="calculation_mode" value="direct_mode" id="directMode">
                            <label for="directMode" class="form-label fw-bold">Direct Box Entry</label>
                            <p class="text-muted small mb-0">Enter boxes directly (bypass area calculation)</p>
                        </div>
                    </div>
                </div>
            </div>

            <div class="row g-3">
                <div class="col-md-4">
                    <label class="form-label">Select Tile *</label>
                    <select class="form-select" name="tile_id" id="tileSelect" required onchange="updateTileStock()">
                        <option value="">Choose tile...</option>
                        <?php foreach ($tiles as $tile): ?>
                            <option value="<?= $tile['id'] ?>" 
                                    data-sqft-per-box="<?= $tile['sqft_per_box'] ?>"
                                    data-stock="<?= $tile['current_stock'] ?? 0 ?>"
                                    data-image="<?= h($tile['photo_path']) ?>">
                                <?php if ($show_images && $tile['photo_path']): ?>
                                    <img src="<?= h($tile['photo_path']) ?>" class="item-image"> 
                                <?php endif; ?>
                                <?= h($tile['name']) ?> (<?= h($tile['size_label']) ?>) - Stock: <?= number_format($tile['current_stock'] ?? 0, 1) ?> boxes
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <div id="tileStockInfo" class="mt-2"></div>
                </div>
                
                <div class="col-md-3">
                    <label class="form-label">Purpose</label>
                    <input type="text" class="form-control" name="purpose" placeholder="e.g., Living Room Floor">
                </div>
                
                <div class="col-md-2">
                    <label class="form-label">Rate per Box (₹) *</label>
                    <input type="number" class="form-control" name="rate_per_box" step="0.01" required oninput="calculateTotal()">
                </div>
                
                <?php if ($show_images): ?>
                <div class="col-md-3">
                    <div class="form-check mt-4">
                        <input class="form-check-input" type="checkbox" name="show_image" id="showTileImage">
                        <label class="form-check-label" for="showTileImage">
                            Show image in quotation
                        </label>
                    </div>
                </div>
                <?php endif; ?>
            </div>

            <!-- Sqft Calculation Fields -->
            <div id="sqftFields" class="row g-3 mt-2">
                <div class="col-md-2">
                    <label class="form-label">Length (ft)</label>
                    <input type="number" class="form-control" name="length_ft" step="0.1" oninput="calculateFromSqft()">
                </div>
                <div class="col-md-2">
                    <label class="form-label">Width (ft)</label>
                    <input type="number" class="form-control" name="width_ft" step="0.1" oninput="calculateFromSqft()">
                </div>
                <div class="col-md-2">
                    <label class="form-label">Extra Sq.Ft</label>
                    <input type="number" class="form-control" name="extra_sqft" step="0.1" oninput="calculateFromSqft()">
                </div>
                <div class="col-md-2">
                    <label class="form-label">Total Sq.Ft</label>
                    <input type="number" class="form-control" id="totalSqft" readonly>
                </div>
                <div class="col-md-2">
                    <label class="form-label">Boxes Needed</label>
                    <input type="number" class="form-control" id="boxesNeeded" readonly>
                </div>
                <div class="col-md-2">
                    <label class="form-label">Line Total (₹)</label>
                    <input type="number" class="form-control" id="lineTotal" readonly>
                </div>
            </div>

            <!-- Direct Box Entry Fields -->
            <div id="directFields" class="row g-3 mt-2" style="display: none;">
                <div class="col-md-3">
                    <label class="form-label">Number of Boxes *</label>
                    <input type="number" class="form-control" name="direct_boxes" step="0.1" oninput="calculateFromBoxes()">
                </div>
                <div class="col-md-3">
                    <label class="form-label">Equivalent Sq.Ft</label>
                    <input type="number" class="form-control" id="equivalentSqft" readonly>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Line Total (₹)</label>
                    <input type="number" class="form-control" id="directLineTotal" readonly>
                </div>
            </div>

            <div class="mt-3">
                <button type="submit" class="btn btn-success">
                    <i class="bi bi-plus"></i> Add Tile Item
                </button>
            </div>
        </form>
    </div>

    <!-- Add Misc Item Section -->
    <div class="add-item-section">
        <h5><i class="bi bi-plus-circle"></i> Add Other Item</h5>
        <form method="post" id="addMiscForm">
            <input type="hidden" name="add_misc_item" value="1">
            <div class="row g-3">
                <div class="col-md-4">
                    <label class="form-label">Select Item *</label>
                    <select class="form-select" name="misc_item_id" id="miscSelect" required onchange="updateMiscStock()">
                        <option value="">Choose item...</option>
                        <?php foreach ($misc_items as $item): ?>
                            <option value="<?= $item['id'] ?>"
                                    data-stock="<?= $item['current_stock'] ?? 0 ?>"
                                    data-unit="<?= h($item['unit_label']) ?>"
                                    data-image="<?= h($item['photo_path']) ?>">
                                <?php if ($show_images && $item['photo_path']): ?>
                                    <img src="<?= h($item['photo_path']) ?>" class="item-image">
                                <?php endif; ?>
                                <?= h($item['name']) ?> - Stock: <?= number_format($item['current_stock'] ?? 0, 1) ?> <?= h($item['unit_label']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <div id="miscStockInfo" class="mt-2"></div>
                </div>
                <div class="col-md-2">
                    <label class="form-label">Purpose</label>
                    <input type="text" class="form-control" name="purpose" placeholder="e.g., Installation">
                </div>
                <div class="col-md-2">
                    <label class="form-label">Quantity *</label>
                    <input type="number" class="form-control" name="qty_units" step="0.1" required oninput="calculateMiscTotal()">
                </div>
                <div class="col-md-2">
                    <label class="form-label">Rate per Unit (₹) *</label>
                    <input type="number" class="form-control" name="rate_per_unit" step="0.01" required oninput="calculateMiscTotal()">
                </div>
                <div class="col-md-2">
                    <label class="form-label">Line Total (₹)</label>
                    <input type="number" class="form-control" id="miscLineTotal" readonly>
                </div>
                
                <?php if ($show_images): ?>
                <div class="col-12">
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" name="show_image" id="showMiscImage">
                        <label class="form-check-label" for="showMiscImage">
                            Show image in quotation
                        </label>
                    </div>
                </div>
                <?php endif; ?>
            </div>
            
            <div class="mt-3">
                <button type="submit" class="btn btn-success">
                    <i class="bi bi-plus"></i> Add Other Item
                </button>
            </div>
        </form>
    </div>

    <!-- Quotation Items List -->
    <?php if (!empty($quotation_items) || !empty($quotation_misc_items)): ?>
    <div class="card">
        <div class="card-header">
            <h5 class="mb-0"><i class="bi bi-list-ul"></i> Quotation Items</h5>
        </div>
        <div class="card-body">
            <?php if (!empty($quotation_items)): ?>
                <h6 class="text-primary mb-3">Tile Items</h6>
                <div class="table-responsive">
                    <table class="table table-hover">
                        <thead>
                            <tr>
                                <th>Item</th>
                                <th>Purpose</th>
                                <th>Calculation</th>
                                <th>Stock Status</th>
                                <th>Rate</th>
                                <th>Amount</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($quotation_items as $item): ?>
                                <tr>
                                    <td>
                                        <?php if ($item['show_image'] && $item['photo_path']): ?>
                                            <img src="<?= h($item['photo_path']) ?>" class="item-image">
                                        <?php endif; ?>
                                        <strong><?= h($item['tile_name']) ?></strong><br>
                                        <small class="text-muted"><?= h($item['size_label']) ?></small>
                                    </td>
                                    <td><?= h($item['purpose']) ?></td>
                                    <td>
                                        <?php if ($item['calculation_mode'] === 'sqft_mode'): ?>
                                            <span class="badge bg-info">Area Mode</span><br>
                                            <small><?= number_format($item['total_sqft'], 1) ?> sq.ft → <?= number_format($item['boxes_decimal'], 1) ?> boxes</small>
                                        <?php else: ?>
                                            <span class="badge bg-success">Direct Mode</span><br>
                                            <small><?= number_format($item['direct_boxes'], 1) ?> boxes</small>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php 
                                        $stock_needed = $item['boxes_decimal'];
                                        $stock_available = $item['current_stock'] ?? 0;
                                        if ($stock_needed > $stock_available): ?>
                                            <div class="stock-warning">
                                                <i class="bi bi-exclamation-triangle"></i> 
                                                Need: <?= number_format($stock_needed, 1) ?><br>
                                                Available: <?= number_format($stock_available, 1) ?>
                                            </div>
                                        <?php else: ?>
                                            <div class="stock-available">
                                                <i class="bi bi-check-circle"></i> 
                                                Available: <?= number_format($stock_available, 1) ?>
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                    <td>₹<?= number_format($item['rate_per_box'], 2) ?>/box</td>
                                    <td class="fw-bold">₹<?= number_format($item['line_total'], 2) ?></td>
                                    <td>
                                        <div class="btn-group btn-group-sm">
                                            <button type="button" class="btn btn-outline-warning" onclick="editItem(<?= $item['id'] ?>, 'tile')">
                                                <i class="bi bi-pencil"></i>
                                            </button>
                                            <button type="button" class="btn btn-outline-danger" onclick="deleteItem(<?= $item['id'] ?>, 'tile')">
                                                <i class="bi bi-trash"></i>
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>

            <?php if (!empty($quotation_misc_items)): ?>
                <h6 class="text-success mb-3 <?= !empty($quotation_items) ? 'mt-4' : '' ?>">Other Items</h6>
                <div class="table-responsive">
                    <table class="table table-hover">
                        <thead>
                            <tr>
                                <th>Item</th>
                                <th>Purpose</th>
                                <th>Quantity</th>
                                <th>Stock Status</th>
                                <th>Rate</th>
                                <th>Amount</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($quotation_misc_items as $item): ?>
                                <tr>
                                    <td>
                                        <?php if ($item['show_image'] && $item['photo_path']): ?>
                                            <img src="<?= h($item['photo_path']) ?>" class="item-image">
                                        <?php endif; ?>
                                        <strong><?= h($item['item_name']) ?></strong>
                                    </td>
                                    <td><?= h($item['purpose']) ?></td>
                                    <td><?= number_format($item['qty_units'], 1) ?> <?= h($item['unit_label']) ?></td>
                                    <td>
                                        <?php 
                                        $qty_needed = $item['qty_units'];
                                        $stock_available = $item['current_stock'] ?? 0;
                                        if ($qty_needed > $stock_available): ?>
                                            <div class="stock-warning">
                                                <i class="bi bi-exclamation-triangle"></i> 
                                                Need: <?= number_format($qty_needed, 1) ?><br>
                                                Available: <?= number_format($stock_available, 1) ?>
                                            </div>
                                        <?php else: ?>
                                            <div class="stock-available">
                                                <i class="bi bi-check-circle"></i> 
                                                Available: <?= number_format($stock_available, 1) ?>
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                    <td>₹<?= number_format($item['rate_per_unit'], 2) ?>/<?= h($item['unit_label']) ?></td>
                                    <td class="fw-bold">₹<?= number_format($item['line_total'], 2) ?></td>
                                    <td>
                                        <div class="btn-group btn-group-sm">
                                            <button type="button" class="btn btn-outline-warning" onclick="editItem(<?= $item['id'] ?>, 'misc')">
                                                <i class="bi bi-pencil"></i>
                                            </button>
                                            <button type="button" class="btn btn-outline-danger" onclick="deleteItem(<?= $item['id'] ?>, 'misc')">
                                                <i class="bi bi-trash"></i>
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- Actions -->
    <div class="mt-4">
        <a href="quotation_list_enhanced.php" class="btn btn-outline-secondary">
            <i class="bi bi-arrow-left"></i> Back to List
        </a>
        <a href="quotation_view.php?id=<?= $id ?>" class="btn btn-primary">
            <i class="bi bi-eye"></i> View/Print
        </a>
        <button type="button" class="btn btn-success" onclick="convertToInvoice(<?= $id ?>)">
            <i class="bi bi-receipt"></i> Convert to Invoice
        </button>
    </div>

<?php endif; ?>

<script>
function selectCalculationMode(mode) {
    // Update UI
    document.querySelectorAll('.calculation-mode-card').forEach(card => {
        card.classList.remove('active');
    });
    
    if (mode === 'sqft_mode') {
        document.getElementById('sqftMode').checked = true;
        document.getElementById('sqftFields').style.display = '';
        document.getElementById('directFields').style.display = 'none';
        document.querySelector('[onclick="selectCalculationMode(\'sqft_mode\')"]').classList.add('active');
    } else {
        document.getElementById('directMode').checked = true;
        document.getElementById('sqftFields').style.display = 'none';
        document.getElementById('directFields').style.display = '';
        document.querySelector('[onclick="selectCalculationMode(\'direct_mode\')"]').classList.add('active');
    }
}

function updateTileStock() {
    const select = document.getElementById('tileSelect');
    const option = select.options[select.selectedIndex];
    const stockInfo = document.getElementById('tileStockInfo');
    
    if (option.value) {
        const stock = parseFloat(option.dataset.stock) || 0;
        const sqftPerBox = parseFloat(option.dataset.sqftPerBox) || 0;
        
        if (stock > 0) {
            stockInfo.innerHTML = `
                <div class="stock-available">
                    <i class="bi bi-check-circle"></i> 
                    Available: ${stock.toFixed(1)} boxes (${(stock * sqftPerBox).toFixed(1)} sq.ft)
                </div>
            `;
        } else {
            stockInfo.innerHTML = `
                <div class="stock-warning">
                    <i class="bi bi-exclamation-triangle"></i> 
                    Out of stock
                </div>
            `;
        }
    } else {
        stockInfo.innerHTML = '';
    }
}

function updateMiscStock() {
    const select = document.getElementById('miscSelect');
    const option = select.options[select.selectedIndex];
    const stockInfo = document.getElementById('miscStockInfo');
    
    if (option.value) {
        const stock = parseFloat(option.dataset.stock) || 0;
        const unit = option.dataset.unit || 'units';
        
        if (stock > 0) {
            stockInfo.innerHTML = `
                <div class="stock-available">
                    <i class="bi bi-check-circle"></i> 
                    Available: ${stock.toFixed(1)} ${unit}
                </div>
            `;
        } else {
            stockInfo.innerHTML = `
                <div class="stock-warning">
                    <i class="bi bi-exclamation-triangle"></i> 
                    Out of stock
                </div>
            `;
        }
    } else {
        stockInfo.innerHTML = '';
    }
}

function calculateFromSqft() {
    const length = parseFloat(document.querySelector('[name="length_ft"]').value) || 0;
    const width = parseFloat(document.querySelector('[name="width_ft"]').value) || 0;
    const extra = parseFloat(document.querySelector('[name="extra_sqft"]').value) || 0;
    const rate = parseFloat(document.querySelector('[name="rate_per_box"]').value) || 0;
    
    const select = document.getElementById('tileSelect');
    const option = select.options[select.selectedIndex];
    const sqftPerBox = parseFloat(option.dataset.sqftPerBox) || 1;
    
    const totalSqft = length * width + extra;
    const boxes = totalSqft / sqftPerBox;
    const lineTotal = boxes * rate;
    
    document.getElementById('totalSqft').value = totalSqft.toFixed(2);
    document.getElementById('boxesNeeded').value = boxes.toFixed(2);
    document.getElementById('lineTotal').value = lineTotal.toFixed(2);
}

function calculateFromBoxes() {
    const boxes = parseFloat(document.querySelector('[name="direct_boxes"]').value) || 0;
    const rate = parseFloat(document.querySelector('[name="rate_per_box"]').value) || 0;
    
    const select = document.getElementById('tileSelect');
    const option = select.options[select.selectedIndex];
    const sqftPerBox = parseFloat(option.dataset.sqftPerBox) || 1;
    
    const equivalentSqft = boxes * sqftPerBox;
    const lineTotal = boxes * rate;
    
    document.getElementById('equivalentSqft').value = equivalentSqft.toFixed(2);
    document.getElementById('directLineTotal').value = lineTotal.toFixed(2);
}

function calculateTotal() {
    if (document.getElementById('sqftMode').checked) {
        calculateFromSqft();
    } else {
        calculateFromBoxes();
    }
}

function calculateMiscTotal() {
    const qty = parseFloat(document.querySelector('[name="qty_units"]').value) || 0;
    const rate = parseFloat(document.querySelector('[name="rate_per_unit"]').value) || 0;
    const lineTotal = qty * rate;
    
    document.getElementById('miscLineTotal').value = lineTotal.toFixed(2);
}

function editItem(itemId, type) {
    // Implement edit functionality
    alert(`Edit ${type} item ${itemId} - Feature coming soon!`);
}

function deleteItem(itemId, type) {
    if (confirm(`Are you sure you want to delete this ${type} item?`)) {
        // Implement delete functionality
        alert(`Delete ${type} item ${itemId} - Feature coming soon!`);
    }
}

function convertToInvoice(quotationId) {
    if (confirm('Convert this quotation to an invoice?')) {
        window.location.href = `invoice_enhanced.php?from_quote=${quotationId}`;
    }
}
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>