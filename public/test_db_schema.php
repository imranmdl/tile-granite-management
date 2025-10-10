<?php
// test_db_schema.php - Quick database schema check
require_once __DIR__ . '/../includes/Database.php';

?>
<!DOCTYPE html>
<html>
<head>
    <title>Database Schema Test</title>
    <style>
        body { font-family: Arial, sans-serif; padding: 20px; }
        table { border-collapse: collapse; width: 100%; margin: 10px 0; }
        th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }
        th { background-color: #f2f2f2; }
        .error { color: red; }
        .success { color: green; }
    </style>
</head>
<body>
    <h1>Database Schema Check</h1>
    
    <?php
    try {
        $pdo = Database::pdo();
        
        // Check invoices table structure
        echo "<h2>INVOICES Table Structure</h2>";
        $columns = $pdo->query("PRAGMA table_info(invoices)")->fetchAll(PDO::FETCH_ASSOC);
        echo "<table><tr><th>Column</th><th>Type</th><th>Default</th></tr>";
        foreach ($columns as $col) {
            echo "<tr><td>{$col['name']}</td><td>{$col['type']}</td><td>{$col['dflt_value']}</td></tr>";
        }
        echo "</table>";
        
        // Check quotations table structure
        echo "<h2>QUOTATIONS Table Structure</h2>";
        $q_columns = $pdo->query("PRAGMA table_info(quotations)")->fetchAll(PDO::FETCH_ASSOC);
        echo "<table><tr><th>Column</th><th>Type</th><th>Default</th></tr>";
        foreach ($q_columns as $col) {
            echo "<tr><td>{$col['name']}</td><td>{$col['type']}</td><td>{$col['dflt_value']}</td></tr>";
        }
        echo "</table>";
        
        // Test a simple invoice query
        echo "<h2>Sample Invoice Data</h2>";
        $stmt = $pdo->query("SELECT * FROM invoices LIMIT 1");
        $sample = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($sample) {
            echo "<table><tr><th>Field</th><th>Value</th></tr>";
            foreach ($sample as $field => $value) {
                echo "<tr><td>{$field}</td><td>" . htmlspecialchars($value ?? 'NULL') . "</td></tr>";
            }
            echo "</table>";
        } else {
            echo "<p class='error'>No invoice data found</p>";
        }
        
        // Test a simple quotation query
        echo "<h2>Sample Quotation Data</h2>";
        $stmt = $pdo->query("SELECT * FROM quotations LIMIT 1");
        $sample = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($sample) {
            echo "<table><tr><th>Field</th><th>Value</th></tr>";
            foreach ($sample as $field => $value) {
                echo "<tr><td>{$field}</td><td>" . htmlspecialchars($value ?? 'NULL') . "</td></tr>";
            }
            echo "</table>";
        } else {
            echo "<p class='error'>No quotation data found</p>";
        }
        
    } catch (Exception $e) {
        echo "<p class='error'>Error: " . htmlspecialchars($e->getMessage()) . "</p>";
    }
    ?>
    
    <div style="margin-top: 20px;">
        <a href="fix_schema_web.php">🔧 Fix Schema</a> |
        <a href="reports_dashboard_new.php">📊 Reports Dashboard</a>
    </div>
    
</body>
</html>