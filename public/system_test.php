<?php
// public/system_test.php - System Testing & Verification
require_once __DIR__ . '/../includes/simple_auth.php';
require_once __DIR__ . '/../includes/helpers.php';

$page_title = "System Test & Verification";

// Don't require login for testing
$test_results = [];
$all_passed = true;

function test_result($test_name, $passed, $message = '') {
    global $test_results, $all_passed;
    $test_results[] = [
        'name' => $test_name,
        'passed' => $passed,
        'message' => $message
    ];
    if (!$passed) {
        $all_passed = false;
    }
    return $passed;
}

// Test 1: Database Connection
try {
    $pdo = Database::pdo();
    test_result("Database Connection", true, "SQLite database connected successfully");
} catch (Exception $e) {
    test_result("Database Connection", false, "Error: " . $e->getMessage());
}

// Test 2: Authentication System
try {
    $user_count = $pdo->query("SELECT COUNT(*) FROM users_simple")->fetchColumn();
    test_result("Authentication Tables", $user_count > 0, "Found {$user_count} users in system");
} catch (Exception $e) {
    test_result("Authentication Tables", false, "Error: " . $e->getMessage());
}

// Test 3: Core Business Tables
$tables_to_check = ['tiles', 'invoices', 'quotations', 'invoice_items', 'quotation_items'];
foreach ($tables_to_check as $table) {
    try {
        $count = $pdo->query("SELECT COUNT(*) FROM {$table}")->fetchColumn();
        test_result("Table: {$table}", true, "Table exists with {$count} records");
    } catch (Exception $e) {
        test_result("Table: {$table}", false, "Error: " . $e->getMessage());
    }
}

// Test 4: Helper Functions
$helper_functions = ['h', 'safe_redirect', 'table_exists', 'column_exists'];
foreach ($helper_functions as $func) {
    test_result("Function: {$func}", function_exists($func), function_exists($func) ? "Available" : "Missing");
}

// Test 5: Authentication Functions
$auth_functions = ['auth_login', 'auth_user', 'auth_is_logged_in', 'auth_role'];
foreach ($auth_functions as $func) {
    test_result("Auth Function: {$func}", function_exists($func), function_exists($func) ? "Available" : "Missing");
}

// Test 6: File Structure
$critical_files = [
    '../includes/simple_auth.php',
    '../includes/Database.php', 
    '../includes/helpers.php',
    'login.php',
    'index.php',
    'invoice_enhanced.php',
    'invoice_list_enhanced.php',
    'report_sales_enhanced.php',
    'reports_dashboard_new.php'
];

foreach ($critical_files as $file) {
    $exists = file_exists(__DIR__ . '/' . $file) || file_exists($file);
    test_result("File: {$file}", $exists, $exists ? "Found" : "Missing");
}

// Test 7: Sample Data Query (Sales Report)
try {
    $sales_query = "
        SELECT 
            i.id, i.invoice_no, i.customer_name, i.final_total,
            DATE(i.invoice_dt) as invoice_date
        FROM invoices i 
        WHERE DATE(i.invoice_dt) >= ? 
        LIMIT 5
    ";
    $stmt = $pdo->prepare($sales_query);
    $stmt->execute([date('Y-m-01')]);
    $sample_invoices = $stmt->fetchAll(PDO::FETCH_ASSOC);
    test_result("Sales Query Test", true, "Query successful, found " . count($sample_invoices) . " recent invoices");
} catch (Exception $e) {
    test_result("Sales Query Test", false, "Query failed: " . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= h($page_title) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        body { background-color: #f8f9fa; padding: 20px; }
        .test-pass { color: #28a745; }
        .test-fail { color: #dc3545; }
        .system-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border-radius: 15px;
            padding: 2rem;
            margin-bottom: 2rem;
        }
        .test-card {
            background: white;
            border-radius: 10px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.1);
            margin-bottom: 1rem;
        }
        .quick-links {
            background: white;
            border-radius: 10px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.1);
            padding: 1.5rem;
        }
        .status-badge {
            font-size: 1.2rem;
            padding: 0.5rem 1rem;
            border-radius: 50px;
        }
    </style>
</head>
<body>
    <div class="container">
        <!-- Header -->
        <div class="system-header text-center">
            <h1><i class="bi bi-gear-wide-connected me-3"></i>System Test & Verification</h1>
            <p class="mb-0">PHP + SQLite Business Management System</p>
            <div class="mt-3">
                <?php if ($all_passed): ?>
                    <span class="badge bg-success status-badge">
                        <i class="bi bi-check-circle-fill me-2"></i>All Tests Passed
                    </span>
                <?php else: ?>
                    <span class="badge bg-danger status-badge">
                        <i class="bi bi-x-circle-fill me-2"></i>Some Tests Failed
                    </span>
                <?php endif; ?>
            </div>
        </div>

        <div class="row">
            <!-- Test Results -->
            <div class="col-lg-8">
                <h3 class="mb-3">🧪 Test Results</h3>
                
                <?php foreach ($test_results as $test): ?>
                    <div class="test-card p-3 mb-3">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <h6 class="mb-1 <?= $test['passed'] ? 'test-pass' : 'test-fail' ?>">
                                    <i class="bi bi-<?= $test['passed'] ? 'check-circle-fill' : 'x-circle-fill' ?> me-2"></i>
                                    <?= h($test['name']) ?>
                                </h6>
                                <?php if ($test['message']): ?>
                                    <small class="text-muted"><?= h($test['message']) ?></small>
                                <?php endif; ?>
                            </div>
                            <span class="badge bg-<?= $test['passed'] ? 'success' : 'danger' ?>">
                                <?= $test['passed'] ? 'PASS' : 'FAIL' ?>
                            </span>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>

            <!-- Quick Access -->
            <div class="col-lg-4">
                <div class="quick-links">
                    <h5><i class="bi bi-lightning-charge me-2"></i>Quick Access</h5>
                    <div class="d-grid gap-2">
                        <a href="login.php" class="btn btn-primary">
                            <i class="bi bi-box-arrow-in-right me-2"></i>Login Page
                        </a>
                        <a href="index.php" class="btn btn-success">
                            <i class="bi bi-speedometer me-2"></i>Dashboard
                        </a>
                        <a href="invoice_enhanced.php" class="btn btn-warning">
                            <i class="bi bi-receipt me-2"></i>New Invoice
                        </a>
                        <a href="invoice_list_enhanced.php" class="btn btn-info">
                            <i class="bi bi-list-check me-2"></i>Invoice List
                        </a>
                        <a href="reports_dashboard_new.php" class="btn btn-secondary">
                            <i class="bi bi-graph-up me-2"></i>Reports
                        </a>
                        <a href="report_sales_enhanced.php" class="btn btn-outline-primary">
                            <i class="bi bi-bar-chart me-2"></i>Sales Report
                        </a>
                    </div>

                    <hr>
                    <h6>🔧 Admin Tools</h6>
                    <div class="d-grid gap-2">
                        <button class="btn btn-outline-secondary btn-sm" onclick="location.reload()">
                            <i class="bi bi-arrow-clockwise me-2"></i>Re-run Tests
                        </button>
                        <a href="debug_paths.php" class="btn btn-outline-info btn-sm">
                            <i class="bi bi-bug me-2"></i>Debug Info
                        </a>
                    </div>
                </div>

                <!-- System Info -->
                <div class="quick-links mt-3">
                    <h6><i class="bi bi-info-circle me-2"></i>System Info</h6>
                    <table class="table table-sm">
                        <tr>
                            <td>PHP Version</td>
                            <td><strong><?= PHP_VERSION ?></strong></td>
                        </tr>
                        <tr>
                            <td>Database</td>
                            <td><strong>SQLite</strong></td>
                        </tr>
                        <tr>
                            <td>Tests Run</td>
                            <td><strong><?= count($test_results) ?></strong></td>
                        </tr>
                        <tr>
                            <td>Tests Passed</td>
                            <td class="test-pass"><strong><?= count(array_filter($test_results, fn($t) => $t['passed'])) ?></strong></td>
                        </tr>
                        <tr>
                            <td>Tests Failed</td>
                            <td class="test-fail"><strong><?= count(array_filter($test_results, fn($t) => !$t['passed'])) ?></strong></td>
                        </tr>
                    </table>
                </div>
            </div>
        </div>

        <!-- Sample Data Display -->
        <?php if (isset($sample_invoices) && !empty($sample_invoices)): ?>
        <div class="row mt-4">
            <div class="col-12">
                <div class="test-card p-4">
                    <h5><i class="bi bi-database me-2"></i>Sample Data (Recent Invoices)</h5>
                    <div class="table-responsive">
                        <table class="table table-sm">
                            <thead>
                                <tr>
                                    <th>Invoice #</th>
                                    <th>Customer</th>
                                    <th>Date</th>
                                    <th>Amount</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($sample_invoices as $invoice): ?>
                                <tr>
                                    <td><?= h($invoice['invoice_no']) ?></td>
                                    <td><?= h($invoice['customer_name']) ?></td>
                                    <td><?= h($invoice['invoice_date']) ?></td>
                                    <td>₹<?= number_format($invoice['final_total'], 2) ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Footer -->
        <div class="text-center mt-4">
            <small class="text-muted">
                System tested on <?= date('Y-m-d H:i:s') ?> | 
                <strong>Tile Suite Business Management System</strong>
            </small>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>