<?php
// fix_database_schema.php - Fix Database Schema Mismatches
require_once __DIR__ . '/includes/Database.php';

echo "🔧 Fixing Database Schema for Miscellaneous Inventory...\n";
echo "=" . str_repeat("=", 60) . "\n";

try {
    $pdo = Database::pdo();
    echo "✅ Database connection established\n\n";
    
    // Fix misc_items table
    echo "📋 Fixing misc_items table structure...\n";
    
    // Check current structure
    $columns = $pdo->query("PRAGMA table_info(misc_items)")->fetchAll(PDO::FETCH_ASSOC);
    $column_names = array_column($columns, 'name');
    
    echo "Current columns: " . implode(', ', $column_names) . "\n";
    
    // Add missing description column
    if (!in_array('description', $column_names)) {
        $pdo->exec("ALTER TABLE misc_items ADD COLUMN description TEXT DEFAULT ''");
        echo "✅ Added 'description' column to misc_items\n";
    } else {
        echo "✅ 'description' column already exists\n";
    }
    
    // Fix misc_inventory_items table
    echo "\n📦 Fixing misc_inventory_items table structure...\n";
    
    $inv_columns = $pdo->query("PRAGMA table_info(misc_inventory_items)")->fetchAll(PDO::FETCH_ASSOC);
    $inv_column_names = array_column($inv_columns, 'name');
    
    echo "Current columns: " . implode(', ', $inv_column_names) . "\n";
    
    // Add missing columns
    $missing_columns = [
        'transport_cost' => 'REAL DEFAULT 0',
        'purchase_date' => 'TEXT',
        'invoice_no' => 'TEXT DEFAULT ""',
        'created_by' => 'INTEGER'
    ];
    
    foreach ($missing_columns as $col => $def) {
        if (!in_array($col, $inv_column_names)) {
            $pdo->exec("ALTER TABLE misc_inventory_items ADD COLUMN $col $def");
            echo "✅ Added '$col' column to misc_inventory_items\n";
        } else {
            echo "✅ '$col' column already exists\n";
        }
    }
    
    // Map existing purchase_dt to purchase_date if needed
    if (in_array('purchase_dt', $inv_column_names) && !in_array('purchase_date', $inv_column_names)) {
        // Copy data from purchase_dt to new purchase_date column
        $pdo->exec("UPDATE misc_inventory_items SET purchase_date = purchase_dt WHERE purchase_date IS NULL");
        echo "✅ Mapped purchase_dt data to purchase_date column\n";
    }
    
    // Verify fixes
    echo "\n🔍 Verifying fixes...\n";
    
    // Test misc_items query
    try {
        $stmt = $pdo->query("
            SELECT 
                id, name, unit_label, 
                COALESCE(description, '') as description 
            FROM misc_items 
            LIMIT 1
        ");
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        echo "✅ misc_items query with description works\n";
    } catch (Exception $e) {
        echo "❌ misc_items query failed: " . $e->getMessage() . "\n";
    }
    
    // Test misc_inventory_items insert
    try {
        $stmt = $pdo->prepare("
            INSERT INTO misc_inventory_items 
            (misc_item_id, purchase_date, qty_in, damage_units, cost_per_unit, 
             transport_cost, vendor, invoice_no, notes, created_by)
            VALUES (1, ?, 0, 0, 0, 0, 'TEST', 'TEST', 'TEST', 1)
        ");
        $stmt->execute([date('Y-m-d')]);
        
        // Clean up test record
        $pdo->exec("DELETE FROM misc_inventory_items WHERE vendor = 'TEST' AND invoice_no = 'TEST'");
        echo "✅ misc_inventory_items insert/delete works\n";
    } catch (Exception $e) {
        echo "❌ misc_inventory_items insert failed: " . $e->getMessage() . "\n";
    }
    
    echo "\n✅ Database schema fixes completed successfully!\n";
    echo "🎯 inventory_advanced.php and other_purchase.php should now work properly.\n";
    
} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
    exit(1);
}
?>