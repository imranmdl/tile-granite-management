<?php
// public/report_profit_loss.php - Comprehensive Profit/Loss Analysis
require_once __DIR__ . '/../includes/simple_auth.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/profit_calculations.php';

auth_require_login();

$pdo = Database::pdo();
$user_id = $_SESSION['user_id'] ?? 1;

// Check permissions
$user_stmt = $pdo->prepare("SELECT * FROM users_simple WHERE id = ?");
$user_stmt->execute([$user_id]);
$user = $user_stmt->fetch(PDO::FETCH_ASSOC);

$can_view_pl = ($user['can_view_pl'] ?? 0) == 1 || ($user['role'] ?? '') === 'admin';
if (!$can_view_pl) {
    $_SESSION['error'] = 'You do not have permission to access P&L reports';
    header('Location: /reports_dashboard_new.php');
    exit;
}

// Parameters
$report_type = $_GET['type'] ?? 'invoice';
$date_from = $_GET['date_from'] ?? date('Y-m-01');
$date_to = $_GET['date_to'] ?? date('Y-m-d');
$invoice_id = (int)($_GET['invoice_id'] ?? 0);
$quotation_id = (int)($_GET['quotation_id'] ?? 0);

$results = [];
$summary = [];

if ($report_type === 'invoice' && $invoice_id > 0) {
    // Single invoice P&L
    $results = ProfitCalculations::calculateInvoiceProfit($pdo, $invoice_id);
} elseif ($report_type === 'quotation' && $quotation_id > 0) {
    // Single quotation P&L
    $results = ProfitCalculations::calculateQuotationProfit($pdo, $quotation_id);
} elseif ($report_type === 'item_wise') {
    // Item-wise profit analysis
    $results = ProfitCalculations::getItemWiseProfitAnalysis($pdo, $date_from, $date_to);
} elseif ($report_type === 'bulk_invoices') {
    // Multiple invoices P&L
    $invoices_sql = "
        SELECT id, invoice_no, customer_name, final_total, invoice_dt
        FROM invoices 
        WHERE DATE(invoice_dt) BETWEEN ? AND ?
        AND status != 'CANCELLED'
        ORDER BY invoice_dt DESC
        LIMIT 50
    ";
    $stmt = $pdo->prepare($invoices_sql);
    $stmt->execute([$date_from, $date_to]);
    $invoices = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $bulk_results = [];
    $total_revenue = 0;
    $total_profit = 0;
    
    foreach ($invoices as $invoice) {
        $invoice_profit = ProfitCalculations::calculateInvoiceProfit($pdo, $invoice['id']);
        if (!isset($invoice_profit['error'])) {
            $bulk_results[] = $invoice_profit;
            $total_revenue += $invoice_profit['revenue'];
            $total_profit += $invoice_profit['profit_amount'];
        }
    }
    
    $results = [
        'bulk_data' => $bulk_results,
        'summary' => [
            'total_invoices' => count($bulk_results),
            'total_revenue' => $total_revenue,
            'total_profit' => $total_profit,
            'overall_profit_percentage' => $total_revenue > 0 ? ($total_profit / $total_revenue * 100) : 0
        ]
    ];
}

$page_title = "Profit & Loss Analysis";
require_once __DIR__ . '/../includes/header.php';
?>

<style>
.profit-positive { background-color: rgba(40, 167, 69, 0.1); border-left: 4px solid #28a745; }
.profit-negative { background-color: rgba(220, 53, 69, 0.1); border-left: 4px solid #dc3545; }
.profit-summary {
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
}
.cost-breakdown {
    background: #f8f9fa;
    border-radius: 8px;
    padding: 1rem;
    margin: 1rem 0;
}
</style>

<div class="container-fluid mt-4">
    <!-- Header -->
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h2><i class="bi bi-calculator text-success"></i> Profit & Loss Analysis</h2>
        <a href="reports_dashboard_new.php" class="btn btn-outline-secondary">
            <i class="bi bi-arrow-left"></i> Back to Reports
        </a>
    </div>

    <!-- Report Type Selection -->
    <div class="card mb-4">
        <div class="card-header">
            <h5><i class="bi bi-gear"></i> Analysis Options</h5>
        </div>
        <div class="card-body">
            <div class="row g-3">
                <div class="col-md-3">
                    <label class="form-label">Report Type</label>
                    <select class="form-select" onchange="updateReportType(this.value)" id="reportType">
                        <option value="bulk_invoices" <?= $report_type === 'bulk_invoices' ? 'selected' : '' ?>>Bulk Invoice Analysis</option>
                        <option value="invoice" <?= $report_type === 'invoice' ? 'selected' : '' ?>>Single Invoice P&L</option>
                        <option value="quotation" <?= $report_type === 'quotation' ? 'selected' : '' ?>>Single Quotation P&L</option>
                        <option value="item_wise" <?= $report_type === 'item_wise' ? 'selected' : '' ?>>Item-wise Profit Analysis</option>
                    </select>
                </div>
                
                <div class="col-md-2" id="dateFields">
                    <label class="form-label">From Date</label>
                    <input type="date" class="form-control" id="dateFrom" value="<?= h($date_from) ?>">
                </div>
                
                <div class="col-md-2" id="dateFields2">
                    <label class="form-label">To Date</label>
                    <input type="date" class="form-control" id="dateTo" value="<?= h($date_to) ?>">
                </div>
                
                <div class="col-md-3" id="invoiceField" style="<?= $report_type === 'invoice' ? '' : 'display:none' ?>">
                    <label class="form-label">Invoice ID</label>
                    <input type="number" class="form-control" id="invoiceId" value="<?= $invoice_id ?>" placeholder="Enter invoice ID">
                </div>
                
                <div class="col-md-3" id="quotationField" style="<?= $report_type === 'quotation' ? '' : 'display:none' ?>">
                    <label class="form-label">Quotation ID</label>
                    <input type="number" class="form-control" id="quotationId" value="<?= $quotation_id ?>" placeholder="Enter quotation ID">
                </div>
                
                <div class="col-md-2">
                    <label class="form-label">&nbsp;</label>
                    <button type="button" class="btn btn-primary w-100" onclick="generateReport()">
                        <i class="bi bi-play-fill"></i> Generate Report
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Results Display -->
    <?php if (!empty($results)): ?>
        
        <?php if ($report_type === 'bulk_invoices'): ?>
            <!-- Bulk Invoice Analysis -->
            <div class="profit-summary mb-4">
                <div class="row">
                    <div class="col-md-3">
                        <div class="metric-card">
                            <h6>Total Invoices</h6>
                            <h3><?= $results['summary']['total_invoices'] ?></h3>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="metric-card">
                            <h6>Total Revenue</h6>
                            <h3>₹<?= number_format($results['summary']['total_revenue'], 2) ?></h3>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="metric-card">
                            <h6>Total Profit</h6>
                            <h3 class="<?= $results['summary']['total_profit'] >= 0 ? 'text-success' : 'text-danger' ?>">
                                ₹<?= number_format($results['summary']['total_profit'], 2) ?>
                            </h3>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="metric-card">
                            <h6>Profit Margin</h6>
                            <h3 class="<?= $results['summary']['overall_profit_percentage'] >= 0 ? 'text-success' : 'text-danger' ?>">
                                <?= number_format($results['summary']['overall_profit_percentage'], 2) ?>%
                            </h3>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="card">
                <div class="card-header">
                    <h5>Invoice-wise Profit Analysis</h5>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-hover">
                            <thead class="table-light">
                                <tr>
                                    <th>Invoice</th>
                                    <th>Customer</th>
                                    <th>Revenue</th>
                                    <th>Total Costs</th>
                                    <th>Profit Amount</th>
                                    <th>Profit %</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($results['bulk_data'] as $invoice_data): ?>
                                    <tr class="<?= $invoice_data['is_profitable'] ? 'profit-positive' : 'profit-negative' ?>">
                                        <td>
                                            <strong><?= h($invoice_data['invoice_no']) ?></strong>
                                            <br><small class="text-muted"><?= date('M j, Y', strtotime($invoice_data['invoice_date'])) ?></small>
                                        </td>
                                        <td><?= h($invoice_data['customer_name']) ?></td>
                                        <td>₹<?= number_format($invoice_data['revenue'], 2) ?></td>
                                        <td>₹<?= number_format($invoice_data['costs']['total_costs'], 2) ?></td>
                                        <td class="<?= $invoice_data['profit_amount'] >= 0 ? 'text-success' : 'text-danger' ?>">
                                            <strong>₹<?= number_format($invoice_data['profit_amount'], 2) ?></strong>
                                        </td>
                                        <td class="<?= $invoice_data['profit_percentage'] >= 0 ? 'text-success' : 'text-danger' ?>">
                                            <strong><?= number_format($invoice_data['profit_percentage'], 2) ?>%</strong>
                                        </td>
                                        <td>
                                            <span class="badge bg-<?= $invoice_data['is_profitable'] ? 'success' : 'danger' ?>">
                                                <?= $invoice_data['is_profitable'] ? 'Profitable' : 'Loss' ?>
                                            </span>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

        <?php elseif ($report_type === 'item_wise'): ?>
            <!-- Item-wise Analysis -->
            <div class="profit-summary mb-4">
                <div class="row">
                    <div class="col-md-3">
                        <div class="metric-card">
                            <h6>Total Items</h6>
                            <h3><?= $results['summary']['total_items'] ?></h3>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="metric-card">
                            <h6>Profitable Items</h6>
                            <h3 class="text-success"><?= $results['summary']['profitable_items'] ?></h3>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="metric-card">
                            <h6>Loss Making Items</h6>
                            <h3 class="text-danger"><?= $results['summary']['loss_making_items'] ?></h3>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="metric-card">
                            <h6>Overall Margin</h6>
                            <h3 class="<?= $results['summary']['overall_profit_percentage'] >= 0 ? 'text-success' : 'text-danger' ?>">
                                <?= number_format($results['summary']['overall_profit_percentage'], 2) ?>%
                            </h3>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="card">
                <div class="card-header">
                    <h5>Item-wise Profit Analysis</h5>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-hover">
                            <thead class="table-light">
                                <tr>
                                    <th>Item Name</th>
                                    <th>Type</th>
                                    <th>Sales Count</th>
                                    <th>Quantity Sold</th>
                                    <th>Revenue</th>
                                    <th>Cost</th>
                                    <th>Profit</th>
                                    <th>Margin %</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($results['item_analysis'] as $item): ?>
                                    <tr class="<?= $item['is_profitable'] ? 'profit-positive' : 'profit-negative' ?>">
                                        <td>
                                            <strong><?= h($item['item_name']) ?></strong>
                                            <?php if ($item['size_label']): ?>
                                                <br><small class="text-muted"><?= h($item['size_label']) ?></small>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <span class="badge bg-<?= $item['item_type'] === 'tile' ? 'primary' : 'secondary' ?>">
                                                <?= ucfirst($item['item_type']) ?>
                                            </span>
                                        </td>
                                        <td><?= $item['sale_count'] ?></td>
                                        <td><?= number_format($item['total_quantity_sold'], 2) ?></td>
                                        <td>₹<?= number_format($item['total_revenue'], 2) ?></td>
                                        <td>₹<?= number_format($item['total_cost'], 2) ?></td>
                                        <td class="<?= $item['profit_amount'] >= 0 ? 'text-success' : 'text-danger' ?>">
                                            <strong>₹<?= number_format($item['profit_amount'], 2) ?></strong>
                                        </td>
                                        <td class="<?= $item['profit_percentage'] >= 0 ? 'text-success' : 'text-danger' ?>">
                                            <strong><?= number_format($item['profit_percentage'], 2) ?>%</strong>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

        <?php elseif (($report_type === 'invoice' || $report_type === 'quotation') && !isset($results['error'])): ?>
            <!-- Single Invoice/Quotation Analysis -->
            <div class="profit-summary mb-4">
                <div class="row">
                    <div class="col-md-3">
                        <div class="metric-card">
                            <h6>Total Revenue</h6>
                            <h3>₹<?= number_format($results['revenue'], 2) ?></h3>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="metric-card">
                            <h6>Total Costs</h6>
                            <h3>₹<?= number_format($results['costs']['total_costs'], 2) ?></h3>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="metric-card">
                            <h6>Profit Amount</h6>
                            <h3 class="<?= $results['profit_amount'] >= 0 ? 'text-success' : 'text-danger' ?>">
                                ₹<?= number_format($results['profit_amount'], 2) ?>
                            </h3>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="metric-card">
                            <h6>Profit Margin</h6>
                            <h3 class="<?= $results['profit_percentage'] >= 0 ? 'text-success' : 'text-danger' ?>">
                                <?= number_format($results['profit_percentage'], 2) ?>%
                            </h3>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Cost Breakdown -->
            <div class="row mb-4">
                <div class="col-md-6">
                    <div class="card">
                        <div class="card-header">
                            <h6>Cost Breakdown</h6>
                        </div>
                        <div class="card-body">
                            <div class="cost-breakdown">
                                <div class="d-flex justify-content-between mb-2">
                                    <span>Item Costs:</span>
                                    <strong>₹<?= number_format($results['costs']['item_cost'], 2) ?></strong>
                                </div>
                                <div class="d-flex justify-content-between mb-2">
                                    <span>Transport Costs:</span>
                                    <strong>₹<?= number_format($results['costs']['transport_cost'], 2) ?></strong>
                                </div>
                                <div class="d-flex justify-content-between mb-2">
                                    <span>Commission:</span>
                                    <strong>₹<?= number_format($results['costs']['commission_amount'], 2) ?></strong>
                                </div>
                                <div class="d-flex justify-content-between mb-2">
                                    <span>Other Expenses:</span>
                                    <strong>₹<?= number_format($results['costs']['other_expenses'], 2) ?></strong>
                                </div>
                                <hr>
                                <div class="d-flex justify-content-between">
                                    <span><strong>Total Costs:</strong></span>
                                    <strong>₹<?= number_format($results['costs']['total_costs'], 2) ?></strong>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="col-md-6">
                    <div class="card">
                        <div class="card-header">
                            <h6>Profit Analysis</h6>
                        </div>
                        <div class="card-body">
                            <div class="cost-breakdown">
                                <div class="d-flex justify-content-between mb-2">
                                    <span>Revenue:</span>
                                    <strong class="text-success">₹<?= number_format($results['revenue'], 2) ?></strong>
                                </div>
                                <div class="d-flex justify-content-between mb-2">
                                    <span>Total Costs:</span>
                                    <strong class="text-danger">₹<?= number_format($results['costs']['total_costs'], 2) ?></strong>
                                </div>
                                <hr>
                                <div class="d-flex justify-content-between">
                                    <span><strong>Net Profit:</strong></span>
                                    <strong class="<?= $results['profit_amount'] >= 0 ? 'text-success' : 'text-danger' ?>">
                                        ₹<?= number_format($results['profit_amount'], 2) ?>
                                    </strong>
                                </div>
                                <div class="d-flex justify-content-between mt-2">
                                    <span><strong>Profit Margin:</strong></span>
                                    <strong class="<?= $results['profit_percentage'] >= 0 ? 'text-success' : 'text-danger' ?>">
                                        <?= number_format($results['profit_percentage'], 2) ?>%
                                    </strong>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Item Details -->
            <div class="card">
                <div class="card-header">
                    <h6>Item-wise Breakdown</h6>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-sm">
                            <thead class="table-light">
                                <tr>
                                    <th>Item</th>
                                    <th>Quantity</th>
                                    <th>Cost/Unit</th>
                                    <th>Selling Price</th>
                                    <th>Total Cost</th>
                                    <th>Total Revenue</th>
                                    <th>Profit</th>
                                    <th>Margin %</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($results['item_details'] as $item): ?>
                                    <tr>
                                        <td><?= h($item['tile_name']) ?></td>
                                        <td><?= number_format($item['quantity'], 2) ?></td>
                                        <td>₹<?= number_format($item['cost_per_box'], 2) ?></td>
                                        <td>₹<?= number_format($item['selling_price'], 2) ?></td>
                                        <td>₹<?= number_format($item['total_cost'], 2) ?></td>
                                        <td>₹<?= number_format($item['total_revenue'], 2) ?></td>
                                        <td class="<?= $item['profit'] >= 0 ? 'text-success' : 'text-danger' ?>">
                                            ₹<?= number_format($item['profit'], 2) ?>
                                        </td>
                                        <td class="<?= $item['profit_percentage'] >= 0 ? 'text-success' : 'text-danger' ?>">
                                            <?= number_format($item['profit_percentage'], 2) ?>%
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
            
        <?php elseif (isset($results['error'])): ?>
            <div class="alert alert-warning">
                <i class="bi bi-exclamation-triangle"></i> <?= h($results['error']) ?>
            </div>
        <?php endif; ?>
        
    <?php else: ?>
        <div class="card">
            <div class="card-body text-center py-5">
                <i class="bi bi-calculator display-1 text-muted mb-3"></i>
                <h4>Select Analysis Type</h4>
                <p class="text-muted">Choose a report type and parameters above to generate profit/loss analysis.</p>
            </div>
        </div>
    <?php endif; ?>
</div>

<script>
function updateReportType(type) {
    document.getElementById('invoiceField').style.display = type === 'invoice' ? 'block' : 'none';
    document.getElementById('quotationField').style.display = type === 'quotation' ? 'block' : 'none';
}

function generateReport() {
    const reportType = document.getElementById('reportType').value;
    const dateFrom = document.getElementById('dateFrom').value;
    const dateTo = document.getElementById('dateTo').value;
    const invoiceId = document.getElementById('invoiceId').value;
    const quotationId = document.getElementById('quotationId').value;
    
    let url = `?type=${reportType}&date_from=${dateFrom}&date_to=${dateTo}`;
    
    if (reportType === 'invoice' && invoiceId) {
        url += `&invoice_id=${invoiceId}`;
    } else if (reportType === 'quotation' && quotationId) {
        url += `&quotation_id=${quotationId}`;
    }
    
    window.location.href = url;
}
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>