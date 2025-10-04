<?php
// public/report_commission_enhanced.php - Enhanced Commission Report with Latest Schema
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
    $_SESSION['error'] = 'You do not have permission to access commission reports';
    header('Location: /reports_dashboard_new.php');
    exit;
}

// Parameters
$preset = $_GET['preset'] ?? '';
$date_from = $_GET['date_from'] ?? '';
$date_to = $_GET['date_to'] ?? '';
$salesperson_filter = trim($_GET['salesperson'] ?? '');
$status_filter = $_GET['status'] ?? 'all';

// Handle presets
switch ($preset) {
    case 'today':
        $date_from = $date_to = date('Y-m-d');
        break;
    case 'this_week':
        $date_from = date('Y-m-d', strtotime('monday this week'));
        $date_to = date('Y-m-d');
        break;
    case 'this_month':
        $date_from = date('Y-m-01');
        $date_to = date('Y-m-d');
        break;
    case 'last_month':
        $date_from = date('Y-m-01', strtotime('first day of last month'));
        $date_to = date('Y-m-t', strtotime('last day of last month'));
        break;
    default:
        if (!$date_from) $date_from = date('Y-m-01');
        if (!$date_to) $date_to = date('Y-m-d');
        break;
}

// Get commission data from invoices
$commission_sql = "
    SELECT 
        i.id as invoice_id,
        i.invoice_no,
        DATE(i.invoice_dt) as sale_date,
        i.customer_name,
        i.firm_name,
        i.final_total as invoice_total,
        i.commission_percentage,
        i.commission_amount,
        i.status as invoice_status,
        u.username as salesperson,
        u.id as salesperson_id,
        'invoice' as commission_source
    FROM invoices i
    LEFT JOIN users_simple u ON i.created_by = u.id
    WHERE DATE(i.invoice_dt) BETWEEN ? AND ?
    AND i.status != 'CANCELLED'
    AND (i.commission_amount IS NOT NULL AND i.commission_amount > 0)
";

$params = [$date_from, $date_to];

if ($salesperson_filter) {
    $commission_sql .= " AND u.username LIKE ?";
    $params[] = "%$salesperson_filter%";
}

$commission_sql .= " ORDER BY i.invoice_dt DESC";

$commission_stmt = $pdo->prepare($commission_sql);
$commission_stmt->execute($params);
$commission_data = $commission_stmt->fetchAll(PDO::FETCH_ASSOC);

// Get commission ledger data
$ledger_sql = "
    SELECT 
        cl.id,
        cl.commission_date,
        cl.invoice_id,
        cl.salesperson_user_id,
        cl.base_amount,
        cl.commission_percentage,
        cl.commission_amount,
        cl.status,
        cl.notes,
        cl.created_at,
        u.username as salesperson,
        i.invoice_no,
        i.customer_name
    FROM commission_ledger cl
    LEFT JOIN users_simple u ON cl.salesperson_user_id = u.id
    LEFT JOIN invoices i ON cl.invoice_id = i.id
    WHERE DATE(cl.commission_date) BETWEEN ? AND ?
";

$ledger_params = [$date_from, $date_to];

if ($salesperson_filter) {
    $ledger_sql .= " AND u.username LIKE ?";
    $ledger_params[] = "%$salesperson_filter%";
}

if ($status_filter !== 'all') {
    $ledger_sql .= " AND cl.status = ?";
    $ledger_params[] = $status_filter;
}

$ledger_sql .= " ORDER BY cl.commission_date DESC";

$ledger_stmt = $pdo->prepare($ledger_sql);
$ledger_stmt->execute($ledger_params);
$ledger_data = $ledger_stmt->fetchAll(PDO::FETCH_ASSOC);

// Combine and analyze data
$combined_data = [];
$salesperson_summary = [];
$status_summary = [
    'PENDING' => 0,
    'PAID' => 0,
    'CANCELLED' => 0
];

// Process commission data
foreach ($commission_data as $comm) {
    $salesperson = $comm['salesperson'] ?? 'Unknown';
    
    if (!isset($salesperson_summary[$salesperson])) {
        $salesperson_summary[$salesperson] = [
            'total_sales' => 0,
            'total_commission' => 0,
            'invoice_count' => 0,
            'avg_commission_rate' => 0
        ];
    }
    
    $salesperson_summary[$salesperson]['total_sales'] += $comm['invoice_total'];
    $salesperson_summary[$salesperson]['total_commission'] += $comm['commission_amount'];
    $salesperson_summary[$salesperson]['invoice_count']++;
    
    $combined_data[] = array_merge($comm, ['source' => 'invoice']);
}

// Process ledger data
foreach ($ledger_data as $ledger) {
    $salesperson = $ledger['salesperson'] ?? 'Unknown';
    
    if (isset($status_summary[$ledger['status']])) {
        $status_summary[$ledger['status']] += $ledger['commission_amount'];
    }
    
    $combined_data[] = array_merge($ledger, ['source' => 'ledger']);
}

// Calculate average commission rates
foreach ($salesperson_summary as $name => &$data) {
    $data['avg_commission_rate'] = $data['total_sales'] > 0 ? 
        ($data['total_commission'] / $data['total_sales'] * 100) : 0;
}

// Sort salesperson summary by total commission
uasort($salesperson_summary, function($a, $b) {
    return $b['total_commission'] <=> $a['total_commission'];
});

// Calculate totals
$totals = [
    'total_commission' => array_sum(array_column($commission_data, 'commission_amount')),
    'total_sales' => array_sum(array_column($commission_data, 'invoice_total')),
    'total_invoices' => count($commission_data),
    'unique_salespersons' => count($salesperson_summary)
];

$totals['avg_commission_rate'] = $totals['total_sales'] > 0 ? 
    ($totals['total_commission'] / $totals['total_sales'] * 100) : 0;

// Get all salespersons for filter
$salespersons_sql = "
    SELECT DISTINCT u.username 
    FROM invoices i 
    JOIN users_simple u ON i.created_by = u.id 
    WHERE u.username IS NOT NULL 
    ORDER BY u.username
";
$all_salespersons = $pdo->query($salespersons_sql)->fetchAll(PDO::FETCH_COLUMN);

$page_title = "Enhanced Commission Report";
require_once __DIR__ . '/../includes/header.php';
?>

<style>
.commission-summary {
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
.performance-card {
    border-left: 4px solid #17a2b8;
    transition: all 0.3s ease;
}
.performance-card:hover {
    box-shadow: 0 4px 15px rgba(0,0,0,0.1);
}
.status-pending { background-color: rgba(255, 193, 7, 0.1); }
.status-paid { background-color: rgba(40, 167, 69, 0.1); }
.status-cancelled { background-color: rgba(220, 53, 69, 0.1); }
</style>

<div class="container-fluid mt-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h2><i class="bi bi-person-check text-info"></i> Enhanced Commission Report</h2>
            <p class="text-muted mb-0">
                Period: <?= date('M j, Y', strtotime($date_from)) ?> to <?= date('M j, Y', strtotime($date_to)) ?>
                <?= $salesperson_filter ? " | Salesperson: $salesperson_filter" : "" ?>
                <?= $status_filter !== 'all' ? " | Status: " . ucfirst($status_filter) : "" ?>
            </p>
        </div>
        <a href="reports_dashboard_new.php" class="btn btn-outline-secondary">
            <i class="bi bi-arrow-left"></i> Back to Reports
        </a>
    </div>

    <!-- Filters -->
    <div class="card mb-4">
        <div class="card-header">
            <h5><i class="bi bi-funnel"></i> Filters</h5>
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
                    <label class="form-label">Quick Presets</label>
                    <select class="form-select" name="preset" onchange="if(this.value) this.form.submit()">
                        <option value="">Custom Range</option>
                        <option value="today" <?= $preset === 'today' ? 'selected' : '' ?>>Today</option>
                        <option value="this_week" <?= $preset === 'this_week' ? 'selected' : '' ?>>This Week</option>
                        <option value="this_month" <?= $preset === 'this_month' ? 'selected' : '' ?>>This Month</option>
                        <option value="last_month" <?= $preset === 'last_month' ? 'selected' : '' ?>>Last Month</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label">Salesperson</label>
                    <select class="form-select" name="salesperson">
                        <option value="">All Salespersons</option>
                        <?php foreach ($all_salespersons as $salesperson): ?>
                            <option value="<?= h($salesperson) ?>" 
                                    <?= $salesperson_filter === $salesperson ? 'selected' : '' ?>>
                                <?= h($salesperson) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label">Status</label>
                    <select class="form-select" name="status">
                        <option value="all" <?= $status_filter === 'all' ? 'selected' : '' ?>>All Status</option>
                        <option value="PENDING" <?= $status_filter === 'PENDING' ? 'selected' : '' ?>>Pending</option>
                        <option value="PAID" <?= $status_filter === 'PAID' ? 'selected' : '' ?>>Paid</option>
                        <option value="CANCELLED" <?= $status_filter === 'CANCELLED' ? 'selected' : '' ?>>Cancelled</option>
                    </select>
                </div>
                <div class="col-md-2 d-flex align-items-end">
                    <button type="submit" class="btn btn-primary w-100">
                        <i class="bi bi-search"></i> Filter
                    </button>
                </div>
                <div class="col-12">
                    <a href="?export=excel&<?= http_build_query($_GET) ?>" class="btn btn-success">
                        <i class="bi bi-file-excel"></i> Export Excel
                    </a>
                </div>
            </form>
        </div>
    </div>

    <!-- Summary Statistics -->
    <div class="row mb-4">
        <div class="col-md-3">
            <div class="metric-card">
                <h6>Total Commission</h6>
                <h3>₹<?= number_format($totals['total_commission'], 2) ?></h3>
                <small><?= $totals['total_invoices'] ?> invoices</small>
            </div>
        </div>
        <div class="col-md-3">
            <div class="metric-card">
                <h6>Total Sales</h6>
                <h3>₹<?= number_format($totals['total_sales'], 2) ?></h3>
                <small>Commission base</small>
            </div>
        </div>
        <div class="col-md-3">
            <div class="metric-card">
                <h6>Avg Commission Rate</h6>
                <h3><?= number_format($totals['avg_commission_rate'], 2) ?>%</h3>
                <small>Average rate</small>
            </div>
        </div>
        <div class="col-md-3">
            <div class="metric-card">
                <h6>Active Salespersons</h6>
                <h3><?= $totals['unique_salespersons'] ?></h3>
                <small>With commissions</small>
            </div>
        </div>
    </div>

    <!-- Commission Status Overview -->
    <div class="row mb-4">
        <div class="col-md-4">
            <div class="card text-center border-warning">
                <div class="card-body">
                    <h5 class="card-title text-warning">Pending</h5>
                    <h3>₹<?= number_format($status_summary['PENDING'], 2) ?></h3>
                    <small class="text-muted">Awaiting payment</small>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card text-center border-success">
                <div class="card-body">
                    <h5 class="card-title text-success">Paid</h5>
                    <h3>₹<?= number_format($status_summary['PAID'], 2) ?></h3>
                    <small class="text-muted">Completed payments</small>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card text-center border-danger">
                <div class="card-body">
                    <h5 class="card-title text-danger">Cancelled</h5>
                    <h3>₹<?= number_format($status_summary['CANCELLED'], 2) ?></h3>
                    <small class="text-muted">Cancelled commissions</small>
                </div>
            </div>
        </div>
    </div>

    <!-- Salesperson Performance -->
    <div class="row mb-4">
        <div class="col-12">
            <div class="card performance-card">
                <div class="card-header">
                    <h5><i class="bi bi-trophy"></i> Salesperson Performance</h5>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-hover">
                            <thead class="table-light">
                                <tr>
                                    <th>Salesperson</th>
                                    <th>Total Sales</th>
                                    <th>Total Commission</th>
                                    <th>Avg Commission Rate</th>
                                    <th>Invoice Count</th>
                                    <th>Avg Commission per Invoice</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($salesperson_summary)): ?>
                                    <tr>
                                        <td colspan="6" class="text-center text-muted py-4">
                                            <i class="bi bi-info-circle"></i> No commission data found for the selected period
                                        </td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($salesperson_summary as $name => $data): ?>
                                        <tr>
                                            <td><strong><?= h($name) ?></strong></td>
                                            <td>₹<?= number_format($data['total_sales'], 2) ?></td>
                                            <td class="text-info">
                                                <strong>₹<?= number_format($data['total_commission'], 2) ?></strong>
                                            </td>
                                            <td><?= number_format($data['avg_commission_rate'], 2) ?>%</td>
                                            <td><?= $data['invoice_count'] ?></td>
                                            <td>₹<?= number_format($data['invoice_count'] > 0 ? $data['total_commission'] / $data['invoice_count'] : 0, 2) ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                            <?php if (!empty($salesperson_summary)): ?>
                                <tfoot class="table-secondary">
                                    <tr>
                                        <th>TOTALS</th>
                                        <th>₹<?= number_format($totals['total_sales'], 2) ?></th>
                                        <th class="text-info">₹<?= number_format($totals['total_commission'], 2) ?></th>
                                        <th><?= number_format($totals['avg_commission_rate'], 2) ?>%</th>
                                        <th><?= $totals['total_invoices'] ?></th>
                                        <th>₹<?= number_format($totals['total_invoices'] > 0 ? $totals['total_commission'] / $totals['total_invoices'] : 0, 2) ?></th>
                                    </tr>
                                </tfoot>
                            <?php endif; ?>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Detailed Commission Data -->
    <div class="card">
        <div class="card-header">
            <h5><i class="bi bi-list-ul"></i> Commission Details (<?= count($commission_data) ?> records)</h5>
        </div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-hover table-sm">
                    <thead class="table-light">
                        <tr>
                            <th>Date</th>
                            <th>Invoice #</th>
                            <th>Customer</th>
                            <th>Salesperson</th>
                            <th>Invoice Total</th>
                            <th>Commission %</th>
                            <th>Commission Amount</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($commission_data)): ?>
                            <tr>
                                <td colspan="8" class="text-center text-muted py-4">
                                    <i class="bi bi-info-circle"></i> No commission data found for the selected period and filters
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($commission_data as $comm): ?>
                                <tr>
                                    <td><?= date('M j, Y', strtotime($comm['sale_date'])) ?></td>
                                    <td>
                                        <a href="invoice_view.php?id=<?= $comm['invoice_id'] ?>" class="text-decoration-none">
                                            <?= h($comm['invoice_no']) ?>
                                        </a>
                                    </td>
                                    <td>
                                        <strong><?= h($comm['customer_name']) ?></strong>
                                        <?= $comm['firm_name'] ? '<br><small class="text-muted">' . h($comm['firm_name']) . '</small>' : '' ?>
                                    </td>
                                    <td><?= h($comm['salesperson'] ?? 'N/A') ?></td>
                                    <td>₹<?= number_format($comm['invoice_total'], 2) ?></td>
                                    <td><?= number_format($comm['commission_percentage'] ?? 0, 2) ?>%</td>
                                    <td class="text-info">
                                        <strong>₹<?= number_format($comm['commission_amount'], 2) ?></strong>
                                    </td>
                                    <td>
                                        <span class="badge bg-success">
                                            <?= h($comm['invoice_status']) ?>
                                        </span>
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