<?php
// public/reports_dashboard.php - Main reports dashboard
require_once __DIR__ . '/../includes/simple_auth.php';
require_once __DIR__ . '/../includes/helpers.php';

auth_require_login();

$pdo = Database::pdo();
$user_id = $_SESSION['user_id'] ?? 1;

// Get user permissions
$user_stmt = $pdo->prepare("SELECT * FROM users_simple WHERE id = ?");
$user_stmt->execute([$user_id]);
$user = $user_stmt->fetch(PDO::FETCH_ASSOC);

$can_view_pl = ($user['can_view_pl'] ?? 0) == 1;
$can_view_reports = ($user['can_view_reports'] ?? 0) == 1 || ($user['role'] ?? '') === 'admin';

if (!$can_view_reports) {
    $_SESSION['error'] = 'You do not have permission to access reports';
    safe_redirect('dashboard.php');
}

$page_title = "Reports Dashboard";
require_once __DIR__ . '/../includes/header.php';
?>

<style>
.report-card {
    transition: transform 0.2s;
    cursor: pointer;
}
.report-card:hover {
    transform: translateY(-5px);
    box-shadow: 0 4px 15px rgba(0,0,0,0.1);
}
.permission-badge {
    position: absolute;
    top: 10px;
    right: 10px;
    font-size: 0.7rem;
}
</style>

<div class="container mt-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h2><i class="bi bi-graph-up"></i> Reports Dashboard</h2>
        <div>
            <span class="badge bg-primary">Reports Access</span>
            <?php if ($can_view_pl): ?>
                <span class="badge bg-success">P/L Access</span>
            <?php endif; ?>
        </div>
    </div>

    <!-- Quick Stats Cards -->
    <div class="row mb-4">
        <div class="col-md-3">
            <div class="card bg-primary text-white">
                <div class="card-body">
                    <div class="d-flex justify-content-between">
                        <div>
                            <h5>Today's Sales</h5>
                            <?php
                            $today_sales = $pdo->query("
                                SELECT COALESCE(SUM(final_total), 0) as total
                                FROM invoices 
                                WHERE DATE(invoice_dt) = DATE('now')
                            ")->fetchColumn();
                            ?>
                            <h3>₹<?= number_format($today_sales, 0) ?></h3>
                        </div>
                        <i class="bi bi-currency-rupee fs-1 opacity-50"></i>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="col-md-3">
            <div class="card bg-success text-white">
                <div class="card-body">
                    <div class="d-flex justify-content-between">
                        <div>
                            <h5>This Month</h5>
                            <?php
                            $month_sales = $pdo->query("
                                SELECT COALESCE(SUM(final_total), 0) as total
                                FROM invoices 
                                WHERE DATE(invoice_dt) >= DATE('now', 'start of month')
                            ")->fetchColumn();
                            ?>
                            <h3>₹<?= number_format($month_sales, 0) ?></h3>
                        </div>
                        <i class="bi bi-calendar-month fs-1 opacity-50"></i>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="col-md-3">
            <div class="card bg-info text-white">
                <div class="card-body">
                    <div class="d-flex justify-content-between">
                        <div>
                            <h5>Pending Quotes</h5>
                            <?php
                            $pending_quotes = $pdo->query("
                                SELECT COUNT(*) as count
                                FROM quotations 
                                WHERE COALESCE(status, 'pending') = 'pending'
                            ")->fetchColumn();
                            ?>
                            <h3><?= number_format($pending_quotes) ?></h3>
                        </div>
                        <i class="bi bi-file-earmark-text fs-1 opacity-50"></i>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="col-md-3">
            <div class="card bg-warning text-white">
                <div class="card-body">
                    <div class="d-flex justify-content-between">
                        <div>
                            <h5>Low Stock Items</h5>
                            <?php
                            $low_stock = $pdo->query("
                                SELECT COUNT(*) as count
                                FROM current_tiles_stock 
                                WHERE total_stock_boxes < 10
                            ")->fetchColumn();
                            ?>
                            <h3><?= number_format($low_stock) ?></h3>
                        </div>
                        <i class="bi bi-exclamation-triangle fs-1 opacity-50"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Reports Grid -->
    <div class="row">
        <!-- Sales Reports -->
        <div class="col-md-4 mb-4">
            <div class="card report-card h-100" onclick="location.href='report_sales.php'">
                <div class="card-body position-relative">
                    <div class="d-flex align-items-center mb-3">
                        <i class="bi bi-graph-up text-primary fs-1 me-3"></i>
                        <div>
                            <h5 class="card-title">Sales Report</h5>
                            <p class="card-text text-muted mb-0">Daily/Monthly/Range sales analysis</p>
                        </div>
                    </div>
                    <ul class="list-unstyled">
                        <li><i class="bi bi-check text-success"></i> Gross & Net Sales</li>
                        <li><i class="bi bi-check text-success"></i> Returns Analysis</li>
                        <li><i class="bi bi-check text-success"></i> Payment Tracking</li>
                        <li><i class="bi bi-check text-success"></i> CSV Export</li>
                    </ul>
                </div>
            </div>
        </div>

        <!-- Inventory Reports -->
        <div class="col-md-4 mb-4">
            <div class="card report-card h-100" onclick="location.href='report_inventory.php'">
                <div class="card-body">
                    <div class="d-flex align-items-center mb-3">
                        <i class="bi bi-boxes text-info fs-1 me-3"></i>
                        <div>
                            <h5 class="card-title">Inventory Report</h5>
                            <p class="card-text text-muted mb-0">Stock levels and valuation</p>
                        </div>
                    </div>
                    <ul class="list-unstyled">
                        <li><i class="bi bi-check text-success"></i> Stock Levels</li>
                        <li><i class="bi bi-check text-success"></i> Photo Integration</li>
                        <li><i class="bi bi-check text-success"></i> Vendor Analysis</li>
                        <li><i class="bi bi-check text-success"></i> Custom Filters</li>
                    </ul>
                </div>
            </div>
        </div>

        <!-- Profit Reports (P/L Permission Required) -->
        <div class="col-md-4 mb-4">
            <div class="card report-card h-100 <?= !$can_view_pl ? 'opacity-50' : '' ?>" 
                 <?= $can_view_pl ? "onclick=\"location.href='report_profit.php'\"" : '' ?>>
                <div class="card-body position-relative">
                    <?php if (!$can_view_pl): ?>
                        <span class="permission-badge badge bg-danger">P/L Access Required</span>
                    <?php endif; ?>
                    <div class="d-flex align-items-center mb-3">
                        <i class="bi bi-pie-chart text-success fs-1 me-3"></i>
                        <div>
                            <h5 class="card-title">Item Profit Report</h5>
                            <p class="card-text text-muted mb-0">Per-item margin analysis</p>
                        </div>
                    </div>
                    <ul class="list-unstyled">
                        <li><i class="bi bi-check text-success"></i> Cost vs Sell Price</li>
                        <li><i class="bi bi-check text-success"></i> Margin Calculations</li>
                        <li><i class="bi bi-check text-success"></i> Color Coding</li>
                        <li><i class="bi bi-check text-success"></i> Profitability Trends</li>
                    </ul>
                </div>
            </div>
        </div>

        <!-- Daily Business Summary -->
        <div class="col-md-4 mb-4">
            <div class="card report-card h-100" onclick="location.href='report_daily_summary.php'">
                <div class="card-body">
                    <div class="d-flex align-items-center mb-3">
                        <i class="bi bi-calendar-day text-warning fs-1 me-3"></i>
                        <div>
                            <h5 class="card-title">Daily Summary</h5>
                            <p class="card-text text-muted mb-0">Complete business overview</p>
                        </div>
                    </div>
                    <ul class="list-unstyled">
                        <li><i class="bi bi-check text-success"></i> Invoice Count & Value</li>
                        <li><i class="bi bi-check text-success"></i> Top Selling Items</li>
                        <li><i class="bi bi-check text-success"></i> Returns Summary</li>
                        <li><i class="bi bi-check text-success"></i> Payment Overview</li>
                    </ul>
                </div>
            </div>
        </div>

        <!-- P/L Drilldown -->
        <div class="col-md-4 mb-4">
            <div class="card report-card h-100 <?= !$can_view_pl ? 'opacity-50' : '' ?>" 
                 <?= $can_view_pl ? "onclick=\"location.href='report_pl_drilldown.php'\"" : '' ?>>
                <div class="card-body position-relative">
                    <?php if (!$can_view_pl): ?>
                        <span class="permission-badge badge bg-danger">P/L Access Required</span>
                    <?php endif; ?>
                    <div class="d-flex align-items-center mb-3">
                        <i class="bi bi-zoom-in text-danger fs-1 me-3"></i>
                        <div>
                            <h5 class="card-title">P/L Drilldown</h5>
                            <p class="card-text text-muted mb-0">Detailed profit analysis</p>
                        </div>
                    </div>
                    <ul class="list-unstyled">
                        <li><i class="bi bi-check text-success"></i> Invoice-wise P/L</li>
                        <li><i class="bi bi-check text-success"></i> Customer Analysis</li>
                        <li><i class="bi bi-check text-success"></i> Time-based Trends</li>
                        <li><i class="bi bi-check text-success"></i> Detailed Breakdowns</li>
                    </ul>
                </div>
            </div>
        </div>

        <!-- Commission Reports -->
        <div class="col-md-4 mb-4">
            <div class="card report-card h-100" onclick="location.href='report_commission.php'">
                <div class="card-body">
                    <div class="d-flex align-items-center mb-3">
                        <i class="bi bi-person-check text-purple fs-1 me-3"></i>
                        <div>
                            <h5 class="card-title">Commission Report</h5>
                            <p class="card-text text-muted mb-0">Sales team performance</p>
                        </div>
                    </div>
                    <ul class="list-unstyled">
                        <li><i class="bi bi-check text-success"></i> User-wise Commission</li>
                        <li><i class="bi bi-check text-success"></i> Pending/Paid Status</li>
                        <li><i class="bi bi-check text-success"></i> Period Analysis</li>
                        <li><i class="bi bi-check text-success"></i> Commission Tracking</li>
                    </ul>
                </div>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>