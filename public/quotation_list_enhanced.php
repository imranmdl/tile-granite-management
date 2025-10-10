<?php
// public/quotation_list_enhanced.php - Enhanced Quotation List with search and filtering
require_once __DIR__ . '/../includes/simple_auth.php';
require_once __DIR__ . '/../includes/helpers.php';

auth_require_login();

$pdo = Database::pdo();
$message = '';
$error = '';

// Handle quotation deletion
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_quotation'])) {
    $quotation_id = (int)$_POST['quotation_id'];
    
    try {
        $pdo->beginTransaction();
        
        // Delete quotation items first (foreign key constraints)
        $stmt = $pdo->prepare("DELETE FROM quotation_items WHERE quotation_id = ?");
        $stmt->execute([$quotation_id]);
        
        $stmt = $pdo->prepare("DELETE FROM quotation_misc_items WHERE quotation_id = ?");
        $stmt->execute([$quotation_id]);
        
        // Delete the quotation
        $stmt = $pdo->prepare("DELETE FROM quotations WHERE id = ?");
        if ($stmt->execute([$quotation_id])) {
            $pdo->commit();
            $message = 'Quotation deleted successfully';
        } else {
            $pdo->rollback();
            $error = 'Failed to delete quotation';
        }
    } catch (Exception $e) {
        $pdo->rollback();
        $error = 'Database error: ' . $e->getMessage();
    }
}

// Get search parameters
$search_customer = trim($_GET['search_customer'] ?? '');
$search_firm = trim($_GET['search_firm'] ?? '');
$search_gst = trim($_GET['search_gst'] ?? '');
$date_from = $_GET['date_from'] ?? '';
$date_to = $_GET['date_to'] ?? '';
$single_date = $_GET['single_date'] ?? date('Y-m-d'); // Default to today

// Determine which date filter to use
$use_single_date = !$date_from && !$date_to;

// Build query with search and filters
$where_conditions = [];
$params = [];

if ($search_customer) {
    $where_conditions[] = "customer_name LIKE ?";
    $params[] = "%$search_customer%";
}

if ($search_firm) {
    $where_conditions[] = "firm_name LIKE ?";
    $params[] = "%$search_firm%";
}

if ($search_gst) {
    $where_conditions[] = "customer_gst LIKE ?";
    $params[] = "%$search_gst%";
}

if ($use_single_date && $single_date) {
    $where_conditions[] = "quote_dt = ?";
    $params[] = $single_date;
} elseif ($date_from && $date_to) {
    $where_conditions[] = "quote_dt BETWEEN ? AND ?";
    $params[] = $date_from;
    $params[] = $date_to;
} elseif ($date_from) {
    $where_conditions[] = "quote_dt >= ?";
    $params[] = $date_from;
} elseif ($date_to) {
    $where_conditions[] = "quote_dt <= ?";
    $params[] = $date_to;
}

$where_clause = $where_conditions ? 'WHERE ' . implode(' AND ', $where_conditions) : '';

// Get quotations with enhanced details
$quotations_query = "
    SELECT * FROM enhanced_quotations_list
    $where_clause
    ORDER BY quote_dt DESC, id DESC
";

$stmt = $pdo->prepare($quotations_query);
$stmt->execute($params);
$quotations = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Calculate totals
$total_quotations = count($quotations);
$total_value = array_sum(array_column($quotations, 'total'));

$page_title = "Enhanced Quotation List";
require_once __DIR__ . '/../includes/header.php';
?>

<style>
.search-section {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
    border-radius: 12px;
    padding: 20px;
    margin-bottom: 20px;
}

.stats-card {
    background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
    border-radius: 8px;
    border: 1px solid #dee2e6;
}

.quotation-table {
    font-size: 0.9em;
}

.quotation-table th {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
    border: none;
    font-weight: 600;
    text-align: center;
    vertical-align: middle;
}

.date-toggle-section {
    background: #e3f2fd;
    border-radius: 6px;
    padding: 15px;
    margin-bottom: 15px;
}

.customer-info {
    line-height: 1.2;
}

.amount-badge {
    font-size: 1.1em;
    font-weight: 600;
}
</style>

<!-- Page Header -->
<div class="d-flex justify-content-between align-items-center mb-4">
    <h2 class="mb-0"><i class="bi bi-file-earmark-text"></i> Quotation Management</h2>
    <div>
        <a href="quotation_enhanced.php" class="btn btn-primary">
            <i class="bi bi-plus-circle"></i> New Quotation
        </a>
    </div>
</div>

<!-- Search and Filter Section -->
<div class="search-section">
    <h5 class="mb-3"><i class="bi bi-search"></i> Search & Filter Quotations</h5>
    
    <form method="GET" class="mb-0">
        <!-- Date Filter Toggle -->
        <div class="date-toggle-section">
            <div class="form-check form-check-inline">
                <input class="form-check-input" type="radio" name="date_mode" id="singleDateMode" 
                       <?= $use_single_date ? 'checked' : '' ?> onchange="toggleDateMode('single')">
                <label class="form-check-label" for="singleDateMode">
                    <strong>Single Date</strong> (Today's quotations)
                </label>
            </div>
            <div class="form-check form-check-inline ms-4">
                <input class="form-check-input" type="radio" name="date_mode" id="rangeDateMode" 
                       <?= !$use_single_date ? 'checked' : '' ?> onchange="toggleDateMode('range')">
                <label class="form-check-label" for="rangeDateMode">
                    <strong>Date Range</strong> (Custom period)
                </label>
            </div>
        </div>

        <div class="row g-3">
            <!-- Single Date Field -->
            <div class="col-md-3" id="singleDateField" style="<?= !$use_single_date ? 'display: none;' : '' ?>">
                <label class="form-label">Select Date</label>
                <input type="date" class="form-control" name="single_date" value="<?= h($single_date) ?>">
            </div>

            <!-- Date Range Fields -->
            <div class="col-md-2" id="fromDateField" style="<?= $use_single_date ? 'display: none;' : '' ?>">
                <label class="form-label">From Date</label>
                <input type="date" class="form-control" name="date_from" value="<?= h($date_from) ?>">
            </div>
            <div class="col-md-2" id="toDateField" style="<?= $use_single_date ? 'display: none;' : '' ?>">
                <label class="form-label">To Date</label>
                <input type="date" class="form-control" name="date_to" value="<?= h($date_to) ?>">
            </div>

            <!-- Search Fields -->
            <div class="col-md-3">
                <label class="form-label">Customer Name</label>
                <input type="text" class="form-control" name="search_customer" value="<?= h($search_customer) ?>" 
                       placeholder="Search customer...">
            </div>
            <div class="col-md-2">
                <label class="form-label">Firm Name</label>
                <input type="text" class="form-control" name="search_firm" value="<?= h($search_firm) ?>" 
                       placeholder="Search firm...">
            </div>
            <div class="col-md-2">
                <label class="form-label">GST Number</label>
                <input type="text" class="form-control" name="search_gst" value="<?= h($search_gst) ?>" 
                       placeholder="GST number...">
            </div>
        </div>

        <div class="mt-3">
            <button type="submit" class="btn btn-light">
                <i class="bi bi-search"></i> Search Quotations
            </button>
            <a href="quotation_list_enhanced.php" class="btn btn-outline-light ms-2">
                <i class="bi bi-arrow-clockwise"></i> Reset Filters
            </a>
            <a href="quotation_list_enhanced.php?single_date=<?= date('Y-m-d') ?>" class="btn btn-outline-light ms-2">
                <i class="bi bi-calendar-day"></i> Today's Quotations
            </a>
        </div>
    </form>
</div>

<!-- Statistics Cards -->
<div class="row g-3 mb-4">
    <div class="col-md-3">
        <div class="card stats-card text-center">
            <div class="card-body">
                <h6 class="card-title text-primary">Total Quotations</h6>
                <h3 class="text-primary"><?= $total_quotations ?></h3>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card stats-card text-center">
            <div class="card-body">
                <h6 class="card-title text-success">Total Value</h6>
                <h3 class="text-success">₹<?= number_format($total_value, 2) ?></h3>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card stats-card text-center">
            <div class="card-body">
                <h6 class="card-title text-info">Average Value</h6>
                <h3 class="text-info">₹<?= $total_quotations > 0 ? number_format($total_value / $total_quotations, 2) : '0.00' ?></h3>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card stats-card text-center">
            <div class="card-body">
                <h6 class="card-title text-warning">Date Range</h6>
                <p class="mb-0 small">
                    <?php if ($use_single_date): ?>
                        <strong><?= date('M j, Y', strtotime($single_date)) ?></strong>
                    <?php elseif ($date_from && $date_to): ?>
                        <strong><?= date('M j', strtotime($date_from)) ?> - <?= date('M j, Y', strtotime($date_to)) ?></strong>
                    <?php else: ?>
                        <strong>All Dates</strong>
                    <?php endif; ?>
                </p>
            </div>
        </div>
    </div>
</div>

<!-- Quotations List -->
<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center">
        <h5 class="mb-0">Quotations List</h5>
        <div class="btn-group btn-group-sm">
            <button type="button" class="btn btn-outline-primary" onclick="exportData()">
                <i class="bi bi-download"></i> Export CSV
            </button>
            <button type="button" class="btn btn-outline-success" onclick="bulkPrint()">
                <i class="bi bi-printer"></i> Bulk Print
            </button>
        </div>
    </div>
    <div class="card-body">
        <?php if (empty($quotations)): ?>
            <div class="text-center py-5">
                <i class="bi bi-file-earmark-text display-4 text-muted"></i>
                <h5 class="text-muted mt-3">No quotations found</h5>
                <p class="text-muted">Try adjusting your search criteria or create a new quotation.</p>
                <a href="quotation_enhanced.php" class="btn btn-primary">
                    <i class="bi bi-plus-circle"></i> Create New Quotation
                </a>
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover quotation-table" id="quotationsTable">
                    <thead>
                        <tr>
                            <th>Quotation No</th>
                            <th>Date</th>
                            <th>Customer Details</th>
                            <th>Items</th>
                            <th>Amount</th>
                            <th>Created By</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($quotations as $quotation): ?>
                            <tr>
                                <td>
                                    <strong class="text-primary"><?= h($quotation['quote_no']) ?></strong>
                                </td>
                                <td>
                                    <span class="badge bg-light text-dark">
                                        <?= date('M j, Y', strtotime($quotation['quote_dt'])) ?>
                                    </span>
                                </td>
                                <td>
                                    <div class="customer-info">
                                        <strong><?= h($quotation['customer_name']) ?></strong>
                                        <?php if ($quotation['firm_name']): ?>
                                            <br><small class="text-muted">
                                                <i class="bi bi-building"></i> <?= h($quotation['firm_name']) ?>
                                            </small>
                                        <?php endif; ?>
                                        <br><small class="text-muted">
                                            <i class="bi bi-telephone"></i> <?= h($quotation['phone']) ?>
                                        </small>
                                        <?php if ($quotation['customer_gst']): ?>
                                            <br><small class="text-muted">
                                                <i class="bi bi-receipt"></i> GST: <?= h($quotation['customer_gst']) ?>
                                            </small>
                                        <?php endif; ?>
                                    </div>
                                </td>
                                <td>
                                    <div class="text-center">
                                        <span class="badge bg-primary"><?= $quotation['total_items'] ?> Total</span>
                                        <?php if ($quotation['tile_items'] > 0): ?>
                                            <br><span class="badge bg-info mt-1"><?= $quotation['tile_items'] ?> Tiles</span>
                                        <?php endif; ?>
                                        <?php if ($quotation['misc_items'] > 0): ?>
                                            <br><span class="badge bg-success mt-1"><?= $quotation['misc_items'] ?> Other</span>
                                        <?php endif; ?>
                                    </div>
                                </td>
                                <td>
                                    <span class="badge bg-success amount-badge">
                                        ₹<?= number_format($quotation['total'], 2) ?>
                                    </span>
                                </td>
                                <td>
                                    <small class="text-muted">
                                        <?= h($quotation['created_by_user'] ?? 'System') ?>
                                    </small>
                                </td>
                                <td>
                                    <div class="btn-group btn-group-sm">
                                        <a href="quotation_enhanced.php?id=<?= $quotation['id'] ?>" class="btn btn-outline-primary" title="Edit">
                                            <i class="bi bi-pencil"></i>
                                        </a>
                                        <a href="quotation_view.php?id=<?= $quotation['id'] ?>" class="btn btn-outline-info" title="View/Print" target="_blank">
                                            <i class="bi bi-eye"></i>
                                        </a>
                                        <a href="invoice_enhanced.php?from_quote=<?= $quotation['id'] ?>" class="btn btn-outline-success" title="Convert to Invoice">
                                            <i class="bi bi-receipt"></i>
                                        </a>
                                        <button type="button" class="btn btn-outline-danger" onclick="deleteQuotation(<?= $quotation['id'] ?>)" title="Delete">
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

<script>
function toggleDateMode(mode) {
    const singleDateField = document.getElementById('singleDateField');
    const fromDateField = document.getElementById('fromDateField');
    const toDateField = document.getElementById('toDateField');
    
    if (mode === 'single') {
        singleDateField.style.display = '';
        fromDateField.style.display = 'none';
        toDateField.style.display = 'none';
        
        // Clear range date fields
        document.querySelector('[name="date_from"]').value = '';
        document.querySelector('[name="date_to"]').value = '';
    } else {
        singleDateField.style.display = 'none';
        fromDateField.style.display = '';
        toDateField.style.display = '';
        
        // Clear single date field
        document.querySelector('[name="single_date"]').value = '';
    }
}

function exportData() {
    // Get table data and export as CSV
    const table = document.getElementById('quotationsTable');
    const rows = table.querySelectorAll('tr');
    
    let csvContent = "data:text/csv;charset=utf-8,";
    csvContent += "Quotation No,Date,Customer Name,Firm Name,Phone,GST Number,Total Items,Amount\n";
    
    // Skip header row (index 0)
    for (let i = 1; i < rows.length; i++) {
        const row = rows[i];
        const cells = row.querySelectorAll('td');
        
        if (cells.length >= 5) {
            const quotationNo = cells[0].textContent.trim();
            const date = cells[1].textContent.trim();
            const customerInfo = cells[2].textContent.trim().replace(/\s+/g, ' ');
            const totalItems = cells[3].textContent.trim();
            const amount = cells[4].textContent.trim();
            
            csvContent += `"${quotationNo}","${date}","${customerInfo}","","","","${totalItems}","${amount}"\n`;
        }
    }
    
    const encodedUri = encodeURI(csvContent);
    const link = document.createElement("a");
    link.setAttribute("href", encodedUri);
    link.setAttribute("download", `quotations_${new Date().toISOString().split('T')[0]}.csv`);
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
}

function bulkPrint() {
    const selectedRows = [];
    // For now, just alert - can be enhanced to select specific quotations
    alert('Bulk print functionality - Feature can be enhanced to select specific quotations for printing');
}

function deleteQuotation(quotationId) {
    if (confirm('Are you sure you want to delete this quotation? This action cannot be undone.')) {
        // Create a form to submit the delete request
        const form = document.createElement('form');
        form.method = 'POST';
        form.action = window.location.href;
        form.innerHTML = `
            <input type="hidden" name="delete_quotation" value="1">
            <input type="hidden" name="quotation_id" value="${quotationId}">
        `;
        document.body.appendChild(form);
        form.submit();
    }
}
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>