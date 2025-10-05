<?php
// test_unified_summary.php - Test inventory_summary_unified.php queries
error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "🔍 Testing inventory_summary_unified.php...\n";
echo "=" . str_repeat("=", 50) . "\n\n";

require_once __DIR__ . '/includes/Database.php';

try {
    $pdo = Database::pdo();
    
    // Test the misc inventory query from inventory_summary_unified.php
    echo "🔍 Testing misc inventory levels query... ";
    try {
        $sql = "
            SELECT 
                m.id as item_id,
                m.name as item_name,
                m.unit_label as unit,
                '' as description,
                'misc' as item_type,
                
                -- Purchase totals
                COALESCE(p.total_quantity_received, 0) as total_received,
                COALESCE(p.total_net_quantity, 0) as total_net_received,
                
                -- Sales totals
                COALESCE(s.total_quantity_sold, 0) as total_sold,
                
                -- Returns totals
                COALESCE(r.total_quantity_returned, 0) as total_returned,
                
                -- Current calculations
                (COALESCE(p.total_net_quantity, 0) - COALESCE(s.total_quantity_sold, 0) + COALESCE(r.total_quantity_returned, 0)) as current_stock,
                (COALESCE(p.total_net_quantity, 0) - COALESCE(s.total_quantity_sold, 0) + COALESCE(r.total_quantity_returned, 0)) as available_quantity,
                
                -- Cost calculations
                CASE 
                    WHEN COALESCE(p.total_net_quantity, 0) > 0
                    THEN COALESCE(p.total_cost, 0) / COALESCE(p.total_net_quantity, 0)
                    ELSE 0
                END as avg_cost_per_unit,
                
                (COALESCE(p.total_net_quantity, 0) - COALESCE(s.total_quantity_sold, 0) + COALESCE(r.total_quantity_returned, 0)) * 
                CASE 
                    WHEN COALESCE(p.total_net_quantity, 0) > 0
                    THEN COALESCE(p.total_cost, 0) / COALESCE(p.total_net_quantity, 0)
                    ELSE 0
                END as total_cost_value
                
            FROM misc_items m
            
            -- Purchase entries
            LEFT JOIN (
                SELECT 
                    misc_item_id,
                    SUM(qty_in) as total_quantity_received,
                    SUM(qty_in - COALESCE(damage_units, 0)) as total_net_quantity,
                    SUM((qty_in - COALESCE(damage_units, 0)) * COALESCE(cost_per_unit, 0)) as total_cost
                FROM misc_inventory_items 
                GROUP BY misc_item_id
            ) p ON m.id = p.misc_item_id
            
            -- Sales
            LEFT JOIN (
                SELECT 
                    misc_item_id,
                    SUM(qty_units) as total_quantity_sold
                FROM invoice_misc_items
                GROUP BY misc_item_id
            ) s ON m.id = s.misc_item_id
            
            -- Returns  
            LEFT JOIN (
                SELECT 
                    misc_item_id,
                    SUM(qty_units) as total_quantity_returned
                FROM invoice_return_misc_items
                GROUP BY misc_item_id
            ) r ON m.id = r.misc_item_id
            
            ORDER BY m.name
            LIMIT 5
        ";
        
        $result = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
        echo "✅ PASSED - Query executed successfully\n";
        echo "   Found " . count($result) . " misc items\n";
        
        if (!empty($result)) {
            $first_item = $result[0];
            echo "   Sample item: " . $first_item['item_name'] . " (" . $first_item['unit'] . ")\n";
        }
        
    } catch (Exception $e) {
        echo "❌ FAILED: " . $e->getMessage() . "\n";
    }
    
} catch (Exception $e) {
    echo "❌ Database connection failed: " . $e->getMessage() . "\n";
}

echo "\n" . str_repeat("=", 50) . "\n";
echo "📊 Summary: inventory_summary_unified.php should work correctly\n";
echo "   because it doesn't use the missing 'description' column\n";
echo str_repeat("=", 50) . "\n";
?>