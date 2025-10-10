<?php
// public/commission_ledger.php - Commission management for admin
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/commission.php';
require_login();

$pdo = Database::pdo();

// Only admin can access commission management
if (!auth_is_admin()) {
    safe_redirect('index.php');
    exit;
}

/* ===========================================================
   HANDLE ALL POST ACTIONS BEFORE ANY OUTPUT
   =========================================================== */

// Mark commission as paid
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['mark_paid'])) {
    $commission_id = (int)$_POST['commission_id'];
    $reference = trim($_POST['reference'] ?? '');
    $notes = trim($_POST['notes'] ?? '');
    
    Commission::set_status($pdo, $commission_id, 'PAID', $reference, $notes);
    safe_redirect('commission_ledger.php');
}

// Mark commission as pending
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['mark_pending'])) {
    $commission_id = (int)$_POST['commission_id'];
    Commission::set_status($pdo, $commission_id, 'PENDING');
    safe_redirect('commission_ledger.php');
}

// Bulk recompute commissions
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['recompute_range'])) {
    $from_date = $_POST['from_date'] ?? date('Y-m-d', strtotime('-30 days'));
    $to_date = $_POST['to_date'] ?? date('Y-m-d');
    
    $result = Commission::recompute_range($pdo, $from_date, $to_date);
    $message = "Recomputed {$result['synced']} out of {$result['total']} invoices";
}

$page_title = "Commission Ledger";
require_once __DIR__ . '/../includes/header.php';

// Get filter parameters
$status_filter = $_GET['status'] ?? 'all';
$user_filter = (int)($_GET['user_id'] ?? 0);

// Build WHERE clause for filters
$where_conditions = [];
$params = [];

if ($status_filter !== 'all') {
    $where_conditions[] = "cl.status = ?";
    $params[] = strtoupper($status_filter);
}

if ($user_filter > 0) {
    $where_conditions[] = "cl.salesperson_user_id = ?";
    $params[] = $user_filter;
}

$where_clause = empty($where_conditions) ? '' : 'WHERE ' . implode(' AND ', $where_conditions);

// Fetch commission ledger with invoice and user details
$sql = "
    SELECT 
        cl.*,
        i.invoice_no,
        i.invoice_dt,
        i.customer_name,
        i.total as invoice_total,
        u.username as salesperson_name
    FROM commission_ledger cl
    LEFT JOIN invoices i ON i.id = cl.invoice_id
    LEFT JOIN users u ON u.id = cl.salesperson_user_id
    $where_clause
    ORDER BY cl.created_at DESC
";

$st = $pdo->prepare($sql);
$st->execute($params);
$commissions = $st->fetchAll(PDO::FETCH_ASSOC);

// Get users for filter dropdown
$users = $pdo->query("SELECT id, username FROM users WHERE active=1 ORDER BY username")->fetchAll(PDO::FETCH_ASSOC);

// Calculate totals
$total_pending = 0;
$total_paid = 0;
foreach ($commissions as $c) {
    if ($c['status'] === 'PENDING') {
        $total_pending += (float)$c['amount'];
    } elseif ($c['status'] === 'PAID') {
        $total_paid += (float)$c['amount'];
    }
}
?>

<?php if (isset($message)): ?>
<div class="alert alert-success alert-dismissible fade show" role="alert">
    <?= h($message) ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<!-- Summary Cards -->
<div class="row g-3 mb-4">
    <div class="col-md-4">
        <div class="card text-bg-warning">
            <div class="card-body">
                <h5 class="card-title">Pending Commissions</h5>
                <h3>₹ <?= n2($total_pending) ?></h3>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card text-bg-success">
            <div class="card-body">
                <h5 class="card-title">Paid Commissions</h5>
                <h3>₹ <?= n2($total_paid) ?></h3>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card text-bg-info">
            <div class="card-body">
                <h5 class="card-title">Total Commissions</h5>
                <h3>₹ <?= n2($total_pending + $total_paid) ?></h3>
            </div>
        </div>
    </div>
</div>

<!-- Bulk Actions -->
<div class="card p-3 mb-3">
    <h5>Bulk Actions</h5>
    <form method="post" class="row g-2">
        <div class="col-md-3">
            <label class="form-label">From Date</label>
            <input class="form-control" type="date" name="from_date" value="<?= h(date('Y-m-d', strtotime('-30 days'))) ?>">
        </div>
        <div class="col-md-3">
            <label class="form-label">To Date</label>
            <input class="form-control" type="date" name="to_date" value="<?= h(date('Y-m-d')) ?>">
        </div>
        <div class="col-md-6 d-flex align-items-end">
            <button class="btn btn-outline-primary" name="recompute_range" onclick="return confirm('Recompute commissions for this date range?')">
                Recompute Commissions
            </button>
        </div>
    </form>
</div>

<!-- Filters -->
<div class="card p-3 mb-3">
    <h5>Filters</h5>
    <form method="get" class="row g-2">
        <div class="col-md-3">
            <label class="form-label">Status</label>
            <select class="form-select" name="status">
                <option value="all" <?= $status_filter === 'all' ? 'selected' : '' ?>>All Status</option>
                <option value="pending" <?= $status_filter === 'pending' ? 'selected' : '' ?>>Pending</option>
                <option value="paid" <?= $status_filter === 'paid' ? 'selected' : '' ?>>Paid</option>
                <option value="approved" <?= $status_filter === 'approved' ? 'selected' : '' ?>>Approved</option>
            </select>
        </div>
        <div class="col-md-3">
            <label class="form-label">Salesperson</label>
            <select class="form-select" name="user_id">
                <option value="0">All Salespeople</option>
                <?php foreach ($users as $u): ?>
                    <option value="<?= (int)$u['id'] ?>" <?= $user_filter === (int)$u['id'] ? 'selected' : '' ?>>
                        <?= h($u['username']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-6 d-flex align-items-end">
            <button type="submit" class="btn btn-outline-primary">Apply Filters</button>
            <a href="commission_ledger.php" class="btn btn-outline-secondary ms-2">Clear</a>
        </div>
    </form>
</div>

<!-- Commission Ledger -->
<div class="card p-3">
    <h5>Commission Ledger</h5>
    <div class="table-responsive">
        <table class="table table-striped align-middle">
            <thead>
                <tr>
                    <th>Date</th>
                    <th>Invoice</th>
                    <th>Customer</th>
                    <th>Salesperson</th>
                    <th class="text-end">Invoice Total</th>
                    <th class="text-end">Commission %</th>
                    <th class="text-end">Commission Amount</th>
                    <th>Status</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($commissions as $c): ?>
                <tr>
                    <td><?= h($c['created_at']) ?></td>
                    <td>
                        <a href="invoice.php?id=<?= (int)$c['invoice_id'] ?>" target="_blank">
                            <?= h($c['invoice_no']) ?>
                        </a>
                    </td>
                    <td><?= h($c['customer_name']) ?></td>
                    <td><?= h($c['salesperson_name']) ?></td>
                    <td class="text-end">₹ <?= n2($c['invoice_total']) ?></td>
                    <td class="text-end"><?= n2($c['pct']) ?>%</td>
                    <td class="text-end">₹ <?= n2($c['amount']) ?></td>
                    <td>
                        <span class="badge <?= 
                            $c['status'] === 'PAID' ? 'text-bg-success' : 
                            ($c['status'] === 'APPROVED' ? 'text-bg-info' : 'text-bg-warning') 
                        ?>">
                            <?= h($c['status']) ?>
                        </span>
                    </td>
                    <td>
                        <?php if ($c['status'] !== 'PAID'): ?>
                            <button type="button" class="btn btn-sm btn-success" 
                                    onclick="markAsPaid(<?= (int)$c['id'] ?>, '<?= h($c['invoice_no']) ?>')">
                                Mark Paid
                            </button>
                        <?php else: ?>
                            <small class="text-muted">
                                Paid: <?= h($c['paid_on']) ?><br>
                                Ref: <?= h($c['reference']) ?>
                            </small>
                            <form method="post" class="d-inline">
                                <input type="hidden" name="commission_id" value="<?= (int)$c['id'] ?>">
                                <button type="submit" name="mark_pending" class="btn btn-sm btn-outline-warning" 
                                        onclick="return confirm('Mark as pending again?')">
                                    Mark Pending
                                </button>
                            </form>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>

                <?php if (empty($commissions)): ?>
                <tr>
                    <td colspan="9" class="text-center text-muted">No commission records found.</td>
                </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Modal for marking payment -->
<div class="modal fade" id="markPaidModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="post">
                <div class="modal-header">
                    <h5 class="modal-title">Mark Commission as Paid</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="commission_id" id="modalCommissionId">
                    <div class="mb-3">
                        <label class="form-label">Payment Reference</label>
                        <input type="text" class="form-control" name="reference" placeholder="Transaction ID, Check number, etc.">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Notes</label>
                        <textarea class="form-control" name="notes" rows="3" placeholder="Additional notes about the payment"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="mark_paid" class="btn btn-success">Mark as Paid</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function markAsPaid(commissionId, invoiceNo) {
    document.getElementById('modalCommissionId').value = commissionId;
    document.querySelector('#markPaidModal .modal-title').textContent = 'Mark Commission as Paid - Invoice: ' + invoiceNo;
    
    const modal = new bootstrap.Modal(document.getElementById('markPaidModal'));
    modal.show();
}
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>