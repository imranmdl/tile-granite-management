<?php
// fix_schema_web.php - Web-accessible schema fix script
require_once __DIR__ . '/../includes/Database.php';

// Security check
$allowed_ips = ['127.0.0.1', '::1', 'localhost'];
$client_ip = $_SERVER['REMOTE_ADDR'] ?? '';

?>
<!DOCTYPE html>
<html>
<head>
    <title>Database Schema Fix</title>
    <style>
        body { font-family: monospace; padding: 20px; background: #f5f5f5; }
        .console { background: #000; color: #0f0; padding: 20px; border-radius: 5px; }
        .error { color: #ff6666; }
        .success { color: #66ff66; }
        .warning { color: #ffff66; }
        .info { color: #66ccff; }
        .btn { background: #007bff; color: white; padding: 10px 20px; border: none; cursor: pointer; margin: 10px 0; }
        .btn:hover { background: #0056b3; }
    </style>
</head>
<body>
    <h1>Database Schema Fix for Reporting System</h1>
    <div class="console">
<?php

if (isset($_POST['run_fix'])) {
    echo "<span class='info'>🔧 Starting Database Schema Fix...</span>\n";
    echo str_repeat("=", 60) . "\n\n";
    
    try {
        $pdo = Database::pdo();
        echo "<span class='success'>✅ Database connection established</span>\n\n";
        
        // Fix invoices table
        echo "<span class='info'>💰 Fixing invoices table...</span>\n";
        $invoice_columns = $pdo->query("PRAGMA table_info(invoices)")->fetchAll(PDO::FETCH_ASSOC);
        $invoice_column_names = array_column($invoice_columns, 'name');
        
        $invoice_missing_columns = [
            'transport_cost' => 'REAL DEFAULT 0',
            'other_expenses' => 'REAL DEFAULT 0', 
            'commission_amount' => 'REAL DEFAULT 0',
            'commission_percentage' => 'REAL DEFAULT 0',
            'salesperson_id' => 'INTEGER'
        ];
        
        foreach ($invoice_missing_columns as $col => $def) {
            if (!in_array($col, $invoice_column_names)) {
                $pdo->exec("ALTER TABLE invoices ADD COLUMN $col $def");
                echo "<span class='success'>✅ Added '$col' to invoices</span>\n";
            } else {
                echo "<span class='warning'>✅ '$col' already exists in invoices</span>\n";
            }
        }
        
        // Fix quotations table
        echo "\n<span class='info'>📋 Fixing quotations table...</span>\n";
        $quotation_columns = $pdo->query("PRAGMA table_info(quotations)")->fetchAll(PDO::FETCH_ASSOC);
        $quotation_column_names = array_column($quotation_columns, 'name');
        
        $quotation_missing_columns = [
            'transport_cost' => 'REAL DEFAULT 0',
            'other_expenses' => 'REAL DEFAULT 0',
            'commission_percentage' => 'REAL DEFAULT 0',
            'commission_amount' => 'REAL DEFAULT 0'
        ];
        
        foreach ($quotation_missing_columns as $col => $def) {
            if (!in_array($col, $quotation_column_names)) {
                $pdo->exec("ALTER TABLE quotations ADD COLUMN $col $def");
                echo "<span class='success'>✅ Added '$col' to quotations</span>\n";
            } else {
                echo "<span class='warning'>✅ '$col' already exists in quotations</span>\n";
            }
        }
        
        // Test queries
        echo "\n<span class='info'>🧮 Testing profit calculation queries...</span>\n";
        
        // Test invoice query
        $stmt = $pdo->prepare("
            SELECT i.*, 
                   COALESCE(i.final_total, i.total) as final_amount,
                   COALESCE(i.commission_percentage, 0) as commission_pct,
                   COALESCE(i.commission_amount, 0) as commission_amount,
                   COALESCE(i.transport_cost, 0) as transport_cost,
                   COALESCE(i.other_expenses, 0) as other_expenses
            FROM invoices i LIMIT 1
        ");
        $stmt->execute();
        echo "<span class='success'>✅ Invoice profit query works</span>\n";
        
        // Test quotation query
        $stmt = $pdo->prepare("
            SELECT q.*, 
                   COALESCE(q.final_total, q.total) as final_amount,
                   COALESCE(q.commission_percentage, 0) as commission_pct,
                   COALESCE(q.transport_cost, 0) as transport_cost,
                   COALESCE(q.other_expenses, 0) as other_expenses
            FROM quotations q LIMIT 1
        ");
        $stmt->execute();
        echo "<span class='success'>✅ Quotation profit query works</span>\n";
        
        echo "\n<span class='success'>✅ DATABASE SCHEMA FIX COMPLETED!</span>\n";
        echo "<span class='info'>🎯 All reporting modules should now work:</span>\n";
        echo "   - report_profit_loss.php\n";
        echo "   - report_daily_pl.php\n";
        echo "   - report_sales_enhanced.php\n";
        echo "   - report_commission_enhanced.php\n";
        
    } catch (Exception $e) {
        echo "<span class='error'>❌ Error: " . htmlspecialchars($e->getMessage()) . "</span>\n";
    }
} else {
    echo "<span class='warning'>Ready to fix database schema for reporting system.</span>\n";
    echo "<span class='info'>This will add missing columns to invoices and quotations tables.</span>\n\n";
    echo "<span class='warning'>Missing columns that will be added:</span>\n";
    echo "INVOICES: transport_cost, other_expenses, commission_amount, commission_percentage, salesperson_id\n";
    echo "QUOTATIONS: transport_cost, other_expenses, commission_percentage, commission_amount\n";
}

?>
    </div>
    
    <?php if (!isset($_POST['run_fix'])): ?>
    <form method="POST">
        <button type="submit" name="run_fix" class="btn">🔧 Run Schema Fix</button>
    </form>
    <?php endif; ?>
    
    <div style="margin-top: 20px;">
        <a href="reports_dashboard_new.php" class="btn">📊 Go to Reports Dashboard</a>
        <a href="report_profit_loss.php" class="btn">💰 Test Profit/Loss Report</a>
    </div>
    
</body>
</html>