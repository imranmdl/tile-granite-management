<?php
// public/reports_dashboard_new.php - Enhanced Reports Dashboard with Daily P/L
require_once __DIR__ . '/../includes/simple_auth.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/admin_functions.php';

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
    header('Location: /index.php');
    exit;
}

// Get quick stats for dashboard
$today = date('Y-m-d');
$this_month = date('Y-m-01');
$last_7_days = date('Y-m-d', strtotime('-7 days'));

// Today's stats
$today_stats = [];
$today_revenue_stmt = $pdo->prepare("
    SELECT COALESCE(SUM(final_total), 0) as revenue 
    FROM invoices 
    WHERE DATE(invoice_dt) = ? AND status != 'CANCELLED'
");
$today_revenue_stmt->execute([$today]);
$today_stats['revenue'] = $today_revenue_stmt->fetchColumn();

$today_orders_stmt = $pdo->prepare("
    SELECT COUNT(*) as orders 
    FROM invoices 
    WHERE DATE(invoice_dt) = ? AND status != 'CANCELLED'
");
$today_orders_stmt->execute([$today]);
$today_stats['orders'] = $today_orders_stmt->fetchColumn();

// Monthly stats
$month_revenue_stmt = $pdo->prepare("
    SELECT COALESCE(SUM(final_total), 0) as revenue 
    FROM invoices 
    WHERE DATE(invoice_dt) >= ? AND status != 'CANCELLED'
");
$month_revenue_stmt->execute([$this_month]);
$month_stats['revenue'] = $month_revenue_stmt->fetchColumn();

// Calculate today's profit (simplified)
$today_profit = 0;
$profit_stmt = $pdo->prepare("
    SELECT 
        SUM(ii.boxes_decimal * ii.rate_per_box) as tile_revenue,
        SUM(imi.quantity * imi.rate_per_unit) as misc_revenue
    FROM invoices i
    LEFT JOIN invoice_items ii ON i.id = ii.invoice_id
    LEFT JOIN invoice_misc_items imi ON i.id = imi.invoice_id
    WHERE DATE(i.invoice_dt) = ? AND i.status != 'CANCELLED'
");
$profit_stmt->execute([$today]);
$profit_data = $profit_stmt->fetch(PDO::FETCH_ASSOC);
$today_revenue_detailed = ($profit_data['tile_revenue'] ?? 0) + ($profit_data['misc_revenue'] ?? 0);

// Recent damage entries
$damage_stmt = $pdo->prepare("
    SELECT COUNT(*) as damage_entries
    FROM individual_returns 
    WHERE DATE(return_date) >= ?
");
$damage_stmt->execute([$last_7_days]);
$recent_damage = $damage_stmt->fetchColumn();

$page_title = "Enhanced Reports Dashboard";
require_once __DIR__ . '/../includes/header.php';
?>

<style>
.report-card {
    transition: all 0.3s ease;
    cursor: pointer;
    border-left: 4px solid #007bff;
}
.report-card:hover {
    transform: translateY(-5px);
    box-shadow: 0 8px 25px rgba(0,0,0,0.15);
    border-left-color: #0056b3;
}
.stat-card {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
    border-radius: 15px;
    padding: 20px;
    margin-bottom: 20px;
}
.quick-stat {
    background: rgba(255,255,255,0.1);
    border-radius: 10px;
    padding: 15px;
    text-align: center;
}
.permission-badge {
    position: absolute;
    top: 10px;
    right: 10px;
    font-size: 0.7rem;
}
.report-icon {
    font-size: 2.5rem;
    color: #007bff;
    margin-bottom: 15px;
}
.category-header {
    background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
    border-radius: 10px;
    padding: 15px;
    margin: 20px 0;
    border-left: 5px solid #007bff;
}
</style>

<div class="container-fluid mt-4">
    <!-- Header -->
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h2><i class="bi bi-graph-up-arrow text-primary"></i> Enhanced Reports Dashboard</h2>
        <div>
            <span class="badge bg-primary">Reports Access</span>
            <?php if ($can_view_pl): ?>
                <span class="badge bg-success">P&L Access</span>
            <?php endif; ?>
        </div>
    </div>

    <!-- Quick Stats Row -->
    <div class="row mb-4">
        <div class="col-md-3">
            <div class="quick-stat">
                <h6>Today's Revenue</h6>
                <h3>₹<?= number_format($today_stats['revenue'], 2) ?></h3>
                <small><?= $today_stats['orders'] ?> orders</small>
            </div>
        </div>
        <div class="col-md-3">
            <div class="quick-stat">
                <h6>Monthly Revenue</h6>
                <h3>₹<?= number_format($month_stats['revenue'], 2) ?></h3>
                <small>Current month</small>
            </div>
        </div>
        <div class="col-md-3">
            <div class="quick-stat">
                <h6>Recent Damage</h6>
                <h3><?= $recent_damage ?></h3>
                <small>Last 7 days</small>
            </div>
        </div>
        <div class="col-md-3">
            <div class="quick-stat">
                <h6>System Status</h6>
                <h3><i class="bi bi-check-circle-fill text-success"></i></h3>
                <small>All systems operational</small>
            </div>
        </div>
    </div>

    <!-- Daily P/L Section -->
    <div class="category-header">
        <h4><i class="bi bi-calendar-day"></i> Daily Profit & Loss Reports</h4>
        <p class="mb-0">Daily insights into profitability and performance metrics</p>
    </div>

    <div class="row mb-4">
        <div class="col-md-4">
            <div class="card report-card h-100" onclick="location.href='report_daily_pl.php'">
                <div class="card-body text-center">
                    <i class="bi bi-graph-up report-icon"></i>
                    <h5>Daily P&L Summary</h5>
                    <p class="text-muted">Comprehensive daily profit & loss analysis with cost breakdowns</p>
                    <?php if ($can_view_pl): ?>
                        <span class="badge bg-success permission-badge">Accessible</span>
                    <?php else: ?>
                        <span class="badge bg-warning permission-badge">Restricted</span>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card report-card h-100" onclick="location.href='report_profit_trends.php'">
                <div class="card-body text-center">
                    <i class="bi bi-graph-up-arrow report-icon"></i>
                    <h5>Profit Trends</h5>
                    <p class="text-muted">Weekly and monthly profit trend analysis with forecasting</p>
                    <?php if ($can_view_pl): ?>
                        <span class="badge bg-success permission-badge">Accessible</span>
                    <?php else: ?>
                        <span class="badge bg-warning permission-badge">Restricted</span>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card report-card h-100" onclick="location.href='report_cost_analysis.php'">
                <div class="card-body text-center">
                    <i class="bi bi-pie-chart report-icon"></i>
                    <h5>Cost Analysis</h5>
                    <p class="text-muted">Detailed cost breakdown and margin analysis by category</p>
                    <?php if ($can_view_pl): ?>
                        <span class="badge bg-success permission-badge">Accessible</span>
                    <?php else: ?>
                        <span class="badge bg-warning permission-badge">Restricted</span>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Sales & Operations Reports -->
    <div class="category-header">
        <h4><i class="bi bi-cart-check"></i> Sales & Operations Reports</h4>
        <p class="mb-0">Comprehensive sales performance and operational efficiency reports</p>
    </div>

    <div class="row mb-4">
        <div class="col-md-4">
            <div class="card report-card h-100" onclick="location.href='report_sales_enhanced.php'">
                <div class="card-body text-center">
                    <i class="bi bi-graph-down report-icon"></i>
                    <h5>Enhanced Sales Report</h5>
                    <p class="text-muted">Detailed sales analysis with customer insights and product performance</p>
                    <span class="badge bg-success permission-badge">Accessible</span>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card report-card h-100" onclick="location.href='report_inventory_enhanced.php'">
                <div class="card-body text-center">
                    <i class="bi bi-boxes report-icon"></i>
                    <h5>Enhanced Inventory Report</h5>
                    <p class="text-muted">Real-time inventory levels, stock movements, and reorder recommendations</p>
                    <span class="badge bg-success permission-badge">Accessible</span>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card report-card h-100" onclick="location.href='report_commission_enhanced.php'">
                <div class="card-body text-center">
                    <i class="bi bi-person-check report-icon"></i>
                    <h5>Enhanced Commission Report</h5>
                    <p class="text-muted">Commission tracking, earnings analysis, and performance metrics</p>
                    <span class="badge bg-success permission-badge">Accessible</span>
                </div>
            </div>
        </div>
    </div>

    <!-- Damage & Returns Reports -->
    <div class="category-header">
        <h4><i class="bi bi-exclamation-triangle"></i> Damage & Returns Analysis</h4>
        <p class="mb-0">Track and analyze damage patterns, returns, and quality metrics</p>
    </div>

    <div class="row mb-4">
        <div class="col-md-4">
            <div class="card report-card h-100" onclick="location.href='report_damage_enhanced.php'">
                <div class="card-body text-center">
                    <i class="bi bi-exclamation-diamond report-icon text-danger"></i>
                    <h5>Enhanced Damage Report</h5>
                    <p class="text-muted">Comprehensive damage analysis with supplier performance and cost impact</p>
                    <span class="badge bg-success permission-badge">Accessible</span>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card report-card h-100" onclick="location.href='report_returns_analysis.php'">
                <div class="card-body text-center">
                    <i class="bi bi-arrow-return-left report-icon text-warning"></i>
                    <h5>Returns Analysis</h5>
                    <p class="text-muted">Customer returns tracking, refund analysis, and return pattern insights</p>
                    <span class="badge bg-success permission-badge">Accessible</span>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card report-card h-100" onclick="location.href='report_quality_metrics.php'">
                <div class="card-body text-center">
                    <i class="bi bi-shield-check report-icon text-info"></i>
                    <h5>Quality Metrics</h5>
                    <p class="text-muted">Quality control metrics, defect rates, and supplier quality scoring</p>
                    <span class="badge bg-success permission-badge">Accessible</span>
                </div>
            </div>
        </div>
    </div>

    <!-- Export & Data Tools -->
    <div class="category-header">
        <h4><i class="bi bi-download"></i> Export & Data Tools</h4>
        <p class="mb-0">Data export, backup, and analytical tools</p>
    </div>

    <div class="row mb-4">
        <div class="col-md-4">
            <div class="card report-card h-100" onclick="location.href='report_data_export.php'">
                <div class="card-body text-center">
                    <i class="bi bi-file-earmark-excel report-icon text-success"></i>
                    <h5>Data Export Center</h5>
                    <p class="text-muted">Export all reports to Excel, CSV, or PDF formats</p>
                    <span class="badge bg-success permission-badge">Accessible</span>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card report-card h-100" onclick="location.href='report_custom_query.php'">
                <div class="card-body text-center">
                    <i class="bi bi-code-slash report-icon text-primary"></i>
                    <h5>Custom Analytics</h5>
                    <p class="text-muted">Build custom reports with advanced filtering and grouping options</p>
                    <span class="badge bg-warning permission-badge">Admin Only</span>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card report-card h-100" onclick="location.href='report_dashboard_config.php'">
                <div class="card-body text-center">
                    <i class="bi bi-gear report-icon text-secondary"></i>
                    <h5>Dashboard Config</h5>
                    <p class="text-muted">Customize dashboard widgets and report preferences</p>
                    <span class="badge bg-info permission-badge">User Settings</span>
                </div>
            </div>
        </div>
    </div>

    <!-- Quick Actions -->
    <div class="row mt-4">
        <div class="col-12">
            <div class="card">
                <div class="card-header">
                    <h5><i class="bi bi-lightning"></i> Quick Actions</h5>
                </div>
                <div class="card-body">
                    <a href="report_daily_pl.php?preset=today" class="btn btn-primary me-2">
                        <i class="bi bi-calendar-day"></i> Today's P&L
                    </a>
                    <a href="report_sales_enhanced.php?preset=this_week" class="btn btn-success me-2">
                        <i class="bi bi-graph-up"></i> This Week Sales
                    </a>
                    <a href="report_inventory_enhanced.php?low_stock=1" class="btn btn-warning me-2">
                        <i class="bi bi-exclamation-triangle"></i> Low Stock Alert
                    </a>
                    <a href="report_damage_enhanced.php?preset=this_month" class="btn btn-danger me-2">
                        <i class="bi bi-shield-exclamation"></i> Monthly Damage
                    </a>
                    <a href="report_commission_enhanced.php?status=pending" class="btn btn-info">
                        <i class="bi bi-clock"></i> Pending Commissions
                    </a>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
// Add hover effects and animations
document.addEventListener('DOMContentLoaded', function() {
    // Add click tracking
    document.querySelectorAll('.report-card').forEach(card => {
        card.addEventListener('click', function() {
            // Optional: Add analytics tracking here
            console.log('Report card clicked:', this.querySelector('h5').textContent);
        });
    });
    
    // Add tooltips for restricted items
    document.querySelectorAll('.badge.bg-warning').forEach(badge => {
        badge.setAttribute('title', 'This report requires P&L access permissions');
        badge.setAttribute('data-bs-toggle', 'tooltip');
    });
    
    // Initialize tooltips if Bootstrap is available
    if (typeof bootstrap !== 'undefined') {
        var tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
        var tooltipList = tooltipTriggerList.map(function (tooltipTriggerEl) {
            return new bootstrap.Tooltip(tooltipTriggerEl);
        });
    }
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>