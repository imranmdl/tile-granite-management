<?php
// public/invoice_list_enhanced.php - Enhanced Invoice List
require_once __DIR__ . '/../includes/simple_auth.php';
require_once __DIR__ . '/../includes/helpers.php';

auth_require_login();

$pdo = Database::pdo();
$user_id = $_SESSION['user_id'] ?? 1;

// Filters
$status_filter = $_GET['status'] ?? 'all';
$customer_filter = trim($_GET['customer'] ?? '');
$date_from = $_GET['date_from'] ?? date('Y-m-01');
$date_to = $_GET['date_to'] ?? date('Y-m-d');
$min_amount = (float)($_GET['min_amount'] ?? 0);
$search = trim($_GET['search'] ?? '');
$sort = $_GET['sort'] ?? 'newest';

// Build query
$sql = "
    SELECT 
        i.*,
        u.username as created_by_name,
        COUNT(ii.id) as item_count,
        COUNT(imi.id) as misc_item_count,
        (
            SELECT COUNT(*) 
            FROM individual_returns ir 
            WHERE ir.invoice_id = i.id
        ) as return_count
    FROM invoices i
    LEFT JOIN users_simple u ON i.created_by = u.id
    LEFT JOIN invoice_items ii ON i.id = ii.invoice_id
    LEFT JOIN invoice_misc_items imi ON i.id = imi.invoice_id
    WHERE DATE(i.invoice_dt) BETWEEN ? AND ?
";

$params = [$date_from, $date_to];

// Apply filters
if ($status_filter !== 'all') {
    $sql .= " AND i.status = ?";
    $params[] = $status_filter;
}

if ($customer_filter) {
    $sql .= " AND (i.customer_name LIKE ? OR i.firm_name LIKE ?)";
    $params[] = "%$customer_filter%";
    $params[] = "%$customer_filter%";
}

if ($min_amount > 0) {
    $sql .= " AND i.final_total >= ?";
    $params[] = $min_amount;
}

if ($search) {
    $sql .= " AND (i.invoice_no LIKE ? OR i.customer_name LIKE ? OR i.phone LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
}

$sql .= " GROUP BY i.id";

// Sorting
switch ($sort) {
    case 'newest':
        $sql .= " ORDER BY i.invoice_dt DESC, i.id DESC";
        break;
    case 'oldest':
        $sql .= " ORDER BY i.invoice_dt ASC, i.id ASC";
        break;
    case 'amount_high':
        $sql .= " ORDER BY i.final_total DESC";
        break;
    case 'amount_low':
        $sql .= " ORDER BY i.final_total ASC";
        break;
    case 'customer':
        $sql .= " ORDER BY i.customer_name ASC";
        break;
}

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$invoices = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Calculate summary
$total_amount = array_sum(array_column($invoices, 'final_total'));
$total_count = count($invoices);
$status_counts = array_count_values(array_column($invoices, 'status'));

$page_title = "Enhanced Invoice List";
require_once __DIR__ . '/../includes/header.php';
?>

<style>
.invoice-card {
    transition: all 0.3s ease;
    border-left: 4px solid #dee2e6;
}
.invoice-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 8px 25px rgba(0,0,0,0.1);
}
.status-paid { border-left-color: #28a745; }
.status-pending { border-left-color: #ffc107; }
.status-cancelled { border-left-color: #dc3545; }
.status-partial { border-left-color: #17a2b8; }

.filter-card {
    background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
    border-radius: 15px;
    border: none;
}

.summary-card {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
    border-radius: 15px;
}

.invoice-amount {
    font-size: 1.25rem;
    font-weight: 700;
}

.quick-actions .btn {
    border-radius: 10px;
    font-size: 0.875rem;
    padding: 0.5rem 0.75rem;
}
</style>

<div class="container-fluid mt-4">
    <!-- Header -->
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h2><i class="bi bi-receipt-cutoff text-primary"></i> Enhanced Invoice Management</h2>
            <p class="text-muted mb-0">
                Showing <?= $total_count ?> invoices 
                (<?= date('M j, Y', strtotime($date_from)) ?> - <?= date('M j, Y', strtotime($date_to)) ?>)
            </p>
        </div>
        <div class="d-flex gap-2">
            <a href="invoice_enhanced.php" class="btn btn-success">
                <i class="bi bi-plus-circle"></i> New Invoice
            </a>
            <a href="reports_dashboard_new.php" class="btn btn-outline-secondary">
                <i class="bi bi-graph-up"></i> Reports
            </a>
        </div>
    </div>

    <!-- Summary Cards -->
    <div class="row mb-4">
        <div class="col-md-3">
            <div class="card summary-card p-3 text-center">
                <h6 class="mb-1">Total Amount</h6>
                <h3 class="mb-0">₹<?= number_format($total_amount, 2) ?></h3>
                <small class="opacity-75"><?= $total_count ?> invoices</small>
            </div>
        </div>
        <div class="col-md-2">
            <div class="card bg-success text-white p-3 text-center">
                <h6 class="mb-1">Paid</h6>
                <h4 class="mb-0"><?= $status_counts['paid'] ?? 0 ?></h4>
                <small class="opacity-75">Completed</small>
            </div>
        </div>
        <div class="col-md-2">
            <div class="card bg-warning text-white p-3 text-center">
                <h6 class="mb-1">Pending</h6>
                <h4 class="mb-0"><?= $status_counts['pending'] ?? 0 ?></h4>
                <small class="opacity-75">Awaiting</small>
            </div>
        </div>
        <div class="col-md-2">
            <div class="card bg-info text-white p-3 text-center">
                <h6 class="mb-1">Partial</h6>
                <h4 class="mb-0"><?= $status_counts['partial'] ?? 0 ?></h4>
                <small class="opacity-75">Partial Pay</small>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card bg-light p-3 text-center">
                <h6 class="mb-1 text-muted">Average</h6>
                <h4 class="mb-0 text-dark">₹<?= $total_count > 0 ? number_format($total_amount / $total_count, 2) : '0.00' ?></h4>
                <small class="text-muted">Per invoice</small>
            </div>
        </div>
    </div>

    <!-- Advanced Filters -->
    <div class="card filter-card mb-4">
        <div class="card-header">
            <h6 class="mb-0"><i class="bi bi-funnel"></i> Advanced Filters & Search</h6>
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
                    <label class="form-label">Status</label>
                    <select class="form-select" name="status">
                        <option value="all" <?= $status_filter === 'all' ? 'selected' : '' ?>>All Status</option>
                        <option value="pending" <?= $status_filter === 'pending' ? 'selected' : '' ?>>Pending</option>
                        <option value="paid" <?= $status_filter === 'paid' ? 'selected' : '' ?>>Paid</option>
                        <option value="partial" <?= $status_filter === 'partial' ? 'selected' : '' ?>>Partial</option>
                        <option value="cancelled" <?= $status_filter === 'cancelled' ? 'selected' : '' ?>>Cancelled</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label">Min Amount</label>
                    <input type="number" class="form-control" name="min_amount" value="<?= $min_amount ?>" min="0" step="100">
                </div>
                <div class="col-md-2">
                    <label class="form-label">Sort By</label>
                    <select class="form-select" name="sort">
                        <option value="newest" <?= $sort === 'newest' ? 'selected' : '' ?>>Newest First</option>
                        <option value="oldest" <?= $sort === 'oldest' ? 'selected' : '' ?>>Oldest First</option>
                        <option value="amount_high" <?= $sort === 'amount_high' ? 'selected' : '' ?>>Amount High-Low</option>
                        <option value="amount_low" <?= $sort === 'amount_low' ? 'selected' : '' ?>>Amount Low-High</option>
                        <option value="customer" <?= $sort === 'customer' ? 'selected' : '' ?>>Customer A-Z</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label">Search</label>
                    <input type="text" class="form-control" name="search" value="<?= h($search) ?>" 
                           placeholder="Invoice#, Customer, Phone">
                </div>
                <div class="col-12">
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-search"></i> Apply Filters
                    </button>
                    <a href="invoice_list_enhanced.php" class="btn btn-outline-secondary">
                        <i class="bi bi-arrow-clockwise"></i> Reset
                    </a>
                </div>
            </form>
        </div>
    </div>

    <!-- Invoice List -->
    <div class="row">
        <?php if (empty($invoices)): ?>
            <div class="col-12">
                <div class="text-center py-5">
                    <i class="bi bi-receipt display-1 text-muted"></i>
                    <h4 class="mt-3 text-muted">No invoices found</h4>
                    <p class="text-muted">Try adjusting your filters or create a new invoice</p>
                    <a href="invoice_enhanced.php" class="btn btn-primary">
                        <i class="bi bi-plus-circle"></i> Create New Invoice
                    </a>
                </div>
            </div>
        <?php else: ?>
            <?php foreach ($invoices as $invoice): ?>
                <div class="col-md-6 col-lg-4 mb-4">
                    <div class="card invoice-card status-<?= $invoice['status'] ?> h-100">
                        <div class="card-header d-flex justify-content-between align-items-center">
                            <div>
                                <h6 class="mb-0 fw-bold"><?= h($invoice['invoice_no']) ?></h6>
                                <small class="text-muted">
                                    <?= date('M j, Y', strtotime($invoice['invoice_dt'])) ?>
                                </small>
                            </div>
                            <span class="badge bg-<?= $invoice['status'] === 'paid' ? 'success' : 
                                                    ($invoice['status'] === 'partial' ? 'info' : 
                                                    ($invoice['status'] === 'cancelled' ? 'danger' : 'warning')) ?>">
                                <?= ucfirst($invoice['status']) ?>
                            </span>
                        </div>
                        
                        <div class="card-body">
                            <!-- Customer Info -->
                            <div class="mb-3">
                                <h6 class="mb-1"><?= h($invoice['customer_name']) ?></h6>
                                <?php if ($invoice['firm_name']): ?>
                                    <small class="text-muted d-block"><?= h($invoice['firm_name']) ?></small>
                                <?php endif; ?>
                                <small class="text-muted">
                                    <i class="bi bi-telephone"></i> <?= h($invoice['phone']) ?>
                                </small>
                            </div>
                            
                            <!-- Amount -->
                            <div class="mb-3">
                                <div class="invoice-amount text-success">₹<?= number_format($invoice['final_total'], 2) ?></div>
                                <?php if ($invoice['discount_amount'] > 0): ?>
                                    <small class="text-muted">
                                        <s>₹<?= number_format($invoice['total'], 2) ?></s>
                                        <span class="text-warning">-₹<?= number_format($invoice['discount_amount'], 2) ?></span>
                                    </small>
                                <?php endif; ?>
                            </div>
                            
                            <!-- Items Summary -->
                            <div class="mb-3">
                                <small class="text-muted">
                                    <?= $invoice['item_count'] ?> tiles, <?= $invoice['misc_item_count'] ?> misc items
                                    <?php if ($invoice['return_count'] > 0): ?>
                                        <br><span class="text-danger">⚠️ <?= $invoice['return_count'] ?> returns</span>
                                    <?php endif; ?>
                                </small>
                            </div>
                            
                            <!-- Created By -->
                            <div class="mb-3">
                                <small class="text-muted">
                                    <i class="bi bi-person"></i> <?= h($invoice['created_by_name'] ?? 'System') ?>
                                </small>
                            </div>
                        </div>
                        
                        <div class="card-footer quick-actions">
                            <div class="d-flex gap-1 flex-wrap">
                                <a href="invoice_enhanced.php?id=<?= $invoice['id'] ?>" 
                                   class="btn btn-outline-primary btn-sm">
                                    <i class="bi bi-pencil"></i> Edit
                                </a>
                                <a href="invoice_view.php?id=<?= $invoice['id'] ?>" 
                                   class="btn btn-outline-info btn-sm">
                                    <i class="bi bi-eye"></i> View
                                </a>
                                <?php if ($invoice['status'] !== 'paid'): ?>
                                <button type="button" class="btn btn-outline-success btn-sm" 
                                        onclick="markAsPaid(<?= $invoice['id'] ?>)">
                                    <i class="bi bi-check-circle"></i> Pay
                                </button>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>

<script>
function markAsPaid(invoiceId) {
    if (confirm('Mark this invoice as paid?')) {
        // Create form and submit
        const form = document.createElement('form');
        form.method = 'POST';
        form.action = `invoice_enhanced.php?id=${invoiceId}`;
        form.innerHTML = '<input type="hidden" name="mark_as_paid" value="1">';
        document.body.appendChild(form);
        form.submit();
    }
}

// Auto-refresh every 2 minutes
setInterval(() => {
    const url = new URL(window.location);
    url.searchParams.set('auto_refresh', '1');
    
    if (!document.hidden) {
        // Silent refresh without page reload
        fetch(url.toString())
            .then(() => {
                // Show subtle notification
                const notification = document.createElement('div');
                notification.className = 'position-fixed top-0 end-0 p-3';
                notification.style.zIndex = '9999';
                notification.innerHTML = `
                    <div class="toast show" role="alert">
                        <div class="toast-body bg-info text-white rounded">
                            <i class="bi bi-arrow-clockwise me-2"></i>Data refreshed
                        </div>
                    </div>
                `;
                document.body.appendChild(notification);
                setTimeout(() => notification.remove(), 2000);
            });
    }
}, 120000);
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>