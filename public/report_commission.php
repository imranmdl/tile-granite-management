<?php
// public/report_commission.php - Commission Report
require_once __DIR__ . '/../includes/simple_auth.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/commission_handler.php';

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

// Initialize commission handler
$commission_handler = new CommissionHandler($pdo);

// Handle form filters
$filter_user = $_GET['filter_user'] ?? '';
$filter_status = $_GET['filter_status'] ?? '';
$date_from = $_GET['date_from'] ?? date('Y-m-01');
$date_to = $_GET['date_to'] ?? date('Y-m-d');

// Handle status update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_status'])) {
    $commission_id = (int)$_POST['commission_id'];
    $new_status = $_POST['new_status'];
    $notes = trim($_POST['notes'] ?? '');
    
    if ($commission_handler->updateCommissionStatus($commission_id, $new_status, $notes)) {
        $message = 'Commission status updated successfully';
    } else {
        $error = 'Failed to update commission status';
    }
}

// Get commission records
$commission_records = $commission_handler->getCommissionRecords($filter_user, $filter_status, $date_from, $date_to);

// Get summary by user
$summary_stmt = $pdo->prepare("
    SELECT 
        u.username,
        u.role,
        COUNT(cr.id) as total_records,
        SUM(cr.commission_amount) as total_commission,
        SUM(CASE WHEN cr.status = 'pending' THEN cr.commission_amount ELSE 0 END) as pending_amount,
        SUM(CASE WHEN cr.status = 'approved' THEN cr.commission_amount ELSE 0 END) as approved_amount,
        SUM(CASE WHEN cr.status = 'paid' THEN cr.commission_amount ELSE 0 END) as paid_amount
    FROM commission_records cr
    JOIN users_simple u ON cr.user_id = u.id
    WHERE DATE(cr.created_at) BETWEEN ? AND ?
    " . ($filter_user ? "AND cr.user_id = ?" : "") . "
    GROUP BY u.id, u.username, u.role
    ORDER BY total_commission DESC
");

$params = [$date_from, $date_to];
if ($filter_user) {
    $params[] = $filter_user;
}
$summary_stmt->execute($params);
$user_summaries = $summary_stmt->fetchAll(PDO::FETCH_ASSOC);

// Get all users for filter dropdown
$users_stmt = $pdo->query("SELECT id, username, role FROM users_simple WHERE active = 1 ORDER BY username");
$users = $users_stmt->fetchAll(PDO::FETCH_ASSOC);

$page_title = "Commission Report";
require_once __DIR__ . '/../includes/header.php';
?>

<div class="container-fluid mt-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h2><i class="bi bi-person-check"></i> Commission Report</h2>
        <div>
            <a href="reports_dashboard.php" class="btn btn-outline-secondary">
                <i class="bi bi-arrow-left"></i> Back to Dashboard
            </a>
            <button onclick="window.print()" class="btn btn-primary">
                <i class="bi bi-printer"></i> Print Report
            </button>
        </div>
    </div>

    <?php if (isset($message)): ?>
        <div class="alert alert-success"><?= h($message) ?></div>
    <?php endif; ?>
    <?php if (isset($error)): ?>
        <div class="alert alert-danger"><?= h($error) ?></div>
    <?php endif; ?>

    <!-- Filters -->
    <div class="card mb-4">
        <div class="card-body">
            <form method="GET" class="row g-3">
                <div class="col-md-2">
                    <label class="form-label">Sales Person</label>
                    <select class="form-select" name="filter_user">
                        <option value="">All Users</option>
                        <?php foreach ($users as $u): ?>
                            <option value="<?= $u['id'] ?>" <?= $filter_user == $u['id'] ? 'selected' : '' ?>>
                                <?= h($u['username']) ?> (<?= h($u['role']) ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label">Status</label>
                    <select class="form-select" name="filter_status">
                        <option value="">All Status</option>
                        <option value="pending" <?= $filter_status === 'pending' ? 'selected' : '' ?>>Pending</option>
                        <option value="approved" <?= $filter_status === 'approved' ? 'selected' : '' ?>>Approved</option>
                        <option value="paid" <?= $filter_status === 'paid' ? 'selected' : '' ?>>Paid</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label">From Date</label>
                    <input type="date" class="form-control" name="date_from" value="<?= h($date_from) ?>">
                </div>
                <div class="col-md-2">
                    <label class="form-label">To Date</label>
                    <input type="date" class="form-control" name="date_to" value="<?= h($date_to) ?>">
                </div>
                <div class="col-md-2">
                    <label class="form-label">&nbsp;</label>
                    <button type="submit" class="btn btn-primary d-block">
                        <i class="bi bi-funnel"></i> Filter
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- User Summary -->
    <div class="card mb-4">
        <div class="card-header">
            <h5><i class="bi bi-people"></i> Commission Summary by User</h5>
        </div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-hover">
                    <thead class="table-dark">
                        <tr>
                            <th>Sales Person</th>
                            <th>Role</th>
                            <th>Total Records</th>
                            <th>Total Commission</th>
                            <th>Pending</th>
                            <th>Approved</th>
                            <th>Paid</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($user_summaries)): ?>
                            <tr>
                                <td colspan="7" class="text-center text-muted">No commission data found</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($user_summaries as $summary): ?>
                                <tr>
                                    <td><strong><?= h($summary['username']) ?></strong></td>
                                    <td><span class="badge bg-secondary"><?= h($summary['role']) ?></span></td>
                                    <td><?= number_format($summary['total_records']) ?></td>
                                    <td><strong>₹<?= number_format($summary['total_commission'], 2) ?></strong></td>
                                    <td class="text-warning">₹<?= number_format($summary['pending_amount'], 2) ?></td>
                                    <td class="text-info">₹<?= number_format($summary['approved_amount'], 2) ?></td>
                                    <td class="text-success">₹<?= number_format($summary['paid_amount'], 2) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Detailed Records -->
    <div class="card">
        <div class="card-header">
            <h5><i class="bi bi-list-ul"></i> Detailed Commission Records</h5>
        </div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-hover table-sm">
                    <thead class="table-dark">
                        <tr>
                            <th>Date</th>
                            <th>Document</th>
                            <th>Customer</th>
                            <th>Sales Person</th>
                            <th>Base Amount</th>
                            <th>Commission %</th>
                            <th>Commission Amount</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($commission_records)): ?>
                            <tr>
                                <td colspan="9" class="text-center text-muted">No commission records found</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($commission_records as $record): ?>
                                <tr>
                                    <td><?= date('M j, Y', strtotime($record['created_at'])) ?></td>
                                    <td>
                                        <span class="badge bg-<?= $record['document_type'] === 'invoice' ? 'success' : 'primary' ?>">
                                            <?= ucfirst($record['document_type']) ?>
                                        </span>
                                        <br><small><?= h($record['document_no']) ?></small>
                                    </td>
                                    <td><?= h($record['customer_name']) ?></td>
                                    <td><?= h($record['username']) ?></td>
                                    <td>₹<?= number_format($record['base_amount'], 2) ?></td>
                                    <td><?= number_format($record['commission_percentage'], 2) ?>%</td>
                                    <td><strong>₹<?= number_format($record['commission_amount'], 2) ?></strong></td>
                                    <td>
                                        <span class="badge bg-<?= 
                                            $record['status'] === 'paid' ? 'success' : 
                                            ($record['status'] === 'approved' ? 'info' : 'warning') ?>">
                                            <?= ucfirst($record['status']) ?>
                                        </span>
                                    </td>
                                    <td>
                                        <button type="button" class="btn btn-outline-primary btn-sm" 
                                                data-bs-toggle="modal" data-bs-target="#statusModal<?= $record['id'] ?>">
                                            <i class="bi bi-pencil"></i> Update
                                        </button>
                                    </td>
                                </tr>

                                <!-- Status Update Modal -->
                                <div class="modal fade" id="statusModal<?= $record['id'] ?>" tabindex="-1">
                                    <div class="modal-dialog">
                                        <div class="modal-content">
                                            <form method="POST">
                                                <div class="modal-header">
                                                    <h5 class="modal-title">Update Commission Status</h5>
                                                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                                </div>
                                                <div class="modal-body">
                                                    <input type="hidden" name="update_status" value="1">
                                                    <input type="hidden" name="commission_id" value="<?= $record['id'] ?>">
                                                    
                                                    <div class="mb-3">
                                                        <label class="form-label">Status</label>
                                                        <select class="form-select" name="new_status" required>
                                                            <option value="pending" <?= $record['status'] === 'pending' ? 'selected' : '' ?>>Pending</option>
                                                            <option value="approved" <?= $record['status'] === 'approved' ? 'selected' : '' ?>>Approved</option>
                                                            <option value="paid" <?= $record['status'] === 'paid' ? 'selected' : '' ?>>Paid</option>
                                                        </select>
                                                    </div>
                                                    
                                                    <div class="mb-3">
                                                        <label class="form-label">Notes</label>
                                                        <textarea class="form-control" name="notes" rows="3"><?= h($record['notes'] ?? '') ?></textarea>
                                                    </div>
                                                </div>
                                                <div class="modal-footer">
                                                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                                    <button type="submit" class="btn btn-primary">Update Status</button>
                                                </div>
                                            </form>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>