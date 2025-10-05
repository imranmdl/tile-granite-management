<?php
// test_inventory_pages.php - Test inventory pages without authentication
error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "🔍 Testing Inventory Pages Database Issues...\n";
echo "=" . str_repeat("=", 50) . "\n\n";

// Test 1: inventory_advanced.php database queries
echo "📋 Testing inventory_advanced.php queries...\n";

require_once __DIR__ . '/includes/Database.php';

try {
    $pdo = Database::pdo();
    
    // Test the problematic query from inventory_advanced.php
    echo "🔍 Testing misc_items query with description column... ";
    try {
        $result = $pdo->query("
            SELECT 
                id, 
                name, 
                unit_label, 
                COALESCE(description, '') as description 
            FROM misc_items 
            ORDER BY name
            LIMIT 1
        ");
        echo "✅ PASSED\n";
    } catch (Exception $e) {
        echo "❌ FAILED: " . $e->getMessage() . "\n";
    }
    
    // Test the full inventory query
    echo "🔍 Testing full inventory query... ";
    try {
        $inventory_sql = "
            SELECT 
                m.id, 
                m.name, 
                m.unit_label as unit, 
                COALESCE(m.description, '') as description,
                COALESCE(inv.total_received, 0) as total_received,
                COALESCE(inv.net_received, 0) as net_received,
                COALESCE(inv.total_cost, 0) as total_cost,
                COALESCE(inv.avg_cost, 0) as avg_cost_per_unit,
                COALESCE(sold.total_sold, 0) as total_sold,
                COALESCE(returned.total_returned, 0) as total_returned,
                (COALESCE(inv.net_received, 0) - COALESCE(sold.total_sold, 0) + COALESCE(returned.total_returned, 0)) as current_stock
            FROM misc_items m
            LEFT JOIN (
                SELECT 
                    misc_item_id,
                    SUM(qty_in) as total_received,
                    SUM(qty_in - COALESCE(damage_units, 0)) as net_received,
                    SUM((qty_in - COALESCE(damage_units, 0)) * cost_per_unit) as total_cost,
                    CASE 
                        WHEN SUM(qty_in - COALESCE(damage_units, 0)) > 0 
                        THEN SUM((qty_in - COALESCE(damage_units, 0)) * cost_per_unit) / SUM(qty_in - COALESCE(damage_units, 0))
                        ELSE 0 
                    END as avg_cost
                FROM misc_inventory_items 
                GROUP BY misc_item_id
            ) inv ON m.id = inv.misc_item_id
            LEFT JOIN (
                SELECT misc_item_id, SUM(COALESCE(qty_units, 0)) as total_sold
                FROM invoice_misc_items 
                GROUP BY misc_item_id
            ) sold ON m.id = sold.misc_item_id
            LEFT JOIN (
                SELECT misc_item_id, SUM(COALESCE(qty_units, 0)) as total_returned
                FROM invoice_return_misc_items 
                GROUP BY misc_item_id
            ) returned ON m.id = returned.misc_item_id
            ORDER BY m.name
            LIMIT 1
        ";
        
        $result = $pdo->query($inventory_sql);
        echo "✅ PASSED\n";
    } catch (Exception $e) {
        echo "❌ FAILED: " . $e->getMessage() . "\n";
    }
    
} catch (Exception $e) {
    echo "❌ Database connection failed: " . $e->getMessage() . "\n";
}

// Test 2: other_purchase.php queries
echo "\n📦 Testing other_purchase.php queries...\n";

try {
    // Test misc_items query from other_purchase.php
    echo "🔍 Testing misc_items query for purchase form... ";
    try {
        $result = $pdo->query("
            SELECT id, name, unit_label, COALESCE(description, '') as description 
            FROM misc_items 
            ORDER BY name
            LIMIT 1
        ");
        echo "✅ PASSED\n";
    } catch (Exception $e) {
        echo "❌ FAILED: " . $e->getMessage() . "\n";
    }
    
    // Test purchase entry insertion
    echo "🔍 Testing purchase entry insertion structure... ";
    try {
        // Check if we can prepare the insert statement
        $stmt = $pdo->prepare("
            INSERT INTO misc_inventory_items 
            (misc_item_id, purchase_date, qty_in, damage_units, cost_per_unit, 
             transport_cost, vendor, invoice_no, notes, created_by)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        echo "✅ PASSED\n";
    } catch (Exception $e) {
        echo "❌ FAILED: " . $e->getMessage() . "\n";
    }
    
} catch (Exception $e) {
    echo "❌ Error in other_purchase.php tests: " . $e->getMessage() . "\n";
}

// Test 3: Check database structure
echo "\n🔧 Checking database structure...\n";

try {
    // Check misc_items table structure
    echo "🔍 Checking misc_items table structure... ";
    $columns = $pdo->query("PRAGMA table_info(misc_items)")->fetchAll(PDO::FETCH_ASSOC);
    $column_names = array_column($columns, 'name');
    
    $missing_columns = [];
    if (!in_array('description', $column_names)) {
        $missing_columns[] = 'description';
    }
    if (!in_array('unit_label', $column_names)) {
        $missing_columns[] = 'unit_label';
    }
    
    if (empty($missing_columns)) {
        echo "✅ PASSED\n";
    } else {
        echo "❌ FAILED - Missing columns: " . implode(', ', $missing_columns) . "\n";
    }
    
    // Check misc_inventory_items table structure
    echo "🔍 Checking misc_inventory_items table structure... ";
    $columns = $pdo->query("PRAGMA table_info(misc_inventory_items)")->fetchAll(PDO::FETCH_ASSOC);
    $column_names = array_column($columns, 'name');
    
    $missing_columns = [];
    $expected_columns = ['transport_cost', 'purchase_date', 'vendor', 'invoice_no', 'notes', 'created_by'];
    
    foreach ($expected_columns as $col) {
        if (!in_array($col, $column_names)) {
            $missing_columns[] = $col;
        }
    }
    
    if (empty($missing_columns)) {
        echo "✅ PASSED\n";
    } else {
        echo "❌ FAILED - Missing columns: " . implode(', ', $missing_columns) . "\n";
        echo "Available columns: " . implode(', ', $column_names) . "\n";
    }
    
} catch (Exception $e) {
    echo "❌ Error checking database structure: " . $e->getMessage() . "\n";
}

echo "\n" . str_repeat("=", 50) . "\n";
echo "📊 Database Issues Summary:\n";
echo "- inventory_advanced.php fails due to missing 'description' column\n";
echo "- other_purchase.php may fail due to column mismatches\n";
echo "- Database structure needs to be updated to match the code expectations\n";
echo str_repeat("=", 50) . "\n";
?>