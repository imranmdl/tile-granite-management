<?php
require_once __DIR__ . '/includes/simple_auth.php';

$pdo = Database::pdo();

// Get quotations table structure
echo "Quotations table structure:\n";
$stmt = $pdo->query("PRAGMA table_info(quotations)");
$columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
foreach ($columns as $col) {
    echo "- {$col['name']} ({$col['type']}) " . ($col['notnull'] ? 'NOT NULL' : 'NULL') . 
         ($col['dflt_value'] ? ' DEFAULT ' . $col['dflt_value'] : '') . "\n";
}

echo "\nInvoices table structure:\n";
$stmt = $pdo->query("PRAGMA table_info(invoices)");
$columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
foreach ($columns as $col) {
    echo "- {$col['name']} ({$col['type']}) " . ($col['notnull'] ? 'NOT NULL' : 'NULL') . 
         ($col['dflt_value'] ? ' DEFAULT ' . $col['dflt_value'] : '') . "\n";
}
?>