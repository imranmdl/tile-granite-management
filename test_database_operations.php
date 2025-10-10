<?php
// test_database_operations.php - Test actual database operations
error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "=== Testing Database Operations ===\n";

try {
    $pdo = new PDO('sqlite:/app/data/app.sqlite');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    echo "✅ Database connection successful\n";
    
    // Test 1: Check if we can read from key tables
    echo "\n1. Testing table access...\n";
    
    $tables = ['tiles', 'misc_items', 'quotations', 'invoices', 'users_simple'];
    foreach ($tables as $table) {
        try {
            $stmt = $pdo->query("SELECT COUNT(*) FROM $table");
            $count = $stmt->fetchColumn();
            echo "✅ Table '$table' - $count records\n";
        } catch (Exception $e) {
            echo "❌ Table '$table' - Error: " . $e->getMessage() . "\n";
        }
    }
    
    // Test 2: Test transport cost calculations in misc_inventory_items
    echo "\n2. Testing misc inventory transport cost calculations...\n";
    try {
        $stmt = $pdo->query("
            SELECT 
                m.name,
                mii.qty_in,
                mii.cost_per_unit,
                mii.transport_cost,
                (mii.cost_per_unit + COALESCE(mii.transport_cost, 0) / NULLIF(mii.qty_in, 0)) as total_cost_per_unit
            FROM misc_inventory_items mii
            JOIN misc_items m ON mii.misc_item_id = m.id
            LIMIT 5
        ");
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        if (count($results) > 0) {
            echo "✅ Misc inventory transport cost calculation working\n";
            foreach ($results as $row) {
                echo "  - {$row['name']}: Cost ₹{$row['cost_per_unit']}, Transport ₹{$row['transport_cost']}, Total ₹" . number_format($row['total_cost_per_unit'], 2) . "\n";
            }
        } else {
            echo "⚠️  No misc inventory data found\n";
        }
    } catch (Exception $e) {
        echo "❌ Misc inventory transport cost calculation - Error: " . $e->getMessage() . "\n";
    }
    
    // Test 3: Test transport cost calculations in inventory_items (tiles)
    echo "\n3. Testing tiles inventory transport cost calculations...\n";
    try {
        $stmt = $pdo->query("
            SELECT 
                t.name,
                ii.boxes_in,
                ii.per_box_value,
                ii.transport_per_box,
                (ii.per_box_value + COALESCE(ii.transport_per_box, 0)) as total_cost_per_box
            FROM inventory_items ii
            JOIN tiles t ON ii.tile_id = t.id
            LIMIT 5
        ");
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        if (count($results) > 0) {
            echo "✅ Tiles inventory transport cost calculation working\n";
            foreach ($results as $row) {
                echo "  - {$row['name']}: Cost ₹{$row['per_box_value']}, Transport ₹{$row['transport_per_box']}, Total ₹" . number_format($row['total_cost_per_box'], 2) . "\n";
            }
        } else {
            echo "⚠️  No tiles inventory data found\n";
        }
    } catch (Exception $e) {
        echo "❌ Tiles inventory transport cost calculation - Error: " . $e->getMessage() . "\n";
    }
    
    // Test 4: Test quotation total calculations
    echo "\n4. Testing quotation calculations...\n";
    try {
        $stmt = $pdo->query("
            SELECT 
                q.quote_no,
                q.total,
                q.discount_amount,
                q.final_total,
                q.commission_amount
            FROM quotations q
            WHERE q.total > 0
            LIMIT 5
        ");
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        if (count($results) > 0) {
            echo "✅ Quotation calculations working\n";
            foreach ($results as $row) {
                echo "  - {$row['quote_no']}: Total ₹{$row['total']}, Discount ₹{$row['discount_amount']}, Final ₹{$row['final_total']}\n";
            }
        } else {
            echo "⚠️  No quotation data found\n";
        }
    } catch (Exception $e) {
        echo "❌ Quotation calculations - Error: " . $e->getMessage() . "\n";
    }
    
    // Test 5: Test profit calculation queries
    echo "\n5. Testing profit calculation queries...\n";
    try {
        // Test a simple profit calculation query similar to what's in profit_calculations.php
        $stmt = $pdo->query("
            SELECT 
                i.invoice_no,
                i.final_total as revenue,
                COALESCE(i.commission_amount, 0) as commission,
                (i.final_total - COALESCE(i.commission_amount, 0)) as gross_profit
            FROM invoices i
            WHERE i.final_total > 0
            LIMIT 3
        ");
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        if (count($results) > 0) {
            echo "✅ Profit calculation queries working\n";
            foreach ($results as $row) {
                echo "  - {$row['invoice_no']}: Revenue ₹{$row['revenue']}, Commission ₹{$row['commission']}, Gross Profit ₹{$row['gross_profit']}\n";
            }
        } else {
            echo "⚠️  No invoice data found\n";
        }
    } catch (Exception $e) {
        echo "❌ Profit calculation queries - Error: " . $e->getMessage() . "\n";
    }
    
    // Test 6: Test database column existence for transport costs
    echo "\n6. Testing database column existence for transport costs...\n";
    try {
        // Check misc_inventory_items columns
        $stmt = $pdo->query("PRAGMA table_info(misc_inventory_items)");
        $columns = $stmt->fetchAll(PDO::FETCH_COLUMN, 1);
        
        if (in_array('transport_cost', $columns)) {
            echo "✅ misc_inventory_items.transport_cost column exists\n";
        } else {
            echo "❌ misc_inventory_items.transport_cost column missing\n";
        }
        
        // Check inventory_items columns
        $stmt = $pdo->query("PRAGMA table_info(inventory_items)");
        $columns = $stmt->fetchAll(PDO::FETCH_COLUMN, 1);
        
        if (in_array('transport_per_box', $columns)) {
            echo "✅ inventory_items.transport_per_box column exists\n";
        } else {
            echo "❌ inventory_items.transport_per_box column missing\n";
        }
        
    } catch (Exception $e) {
        echo "❌ Column existence check - Error: " . $e->getMessage() . "\n";
    }
    
    // Test 7: Test if views exist and work
    echo "\n7. Testing database views...\n";
    try {
        $views = ['current_inventory_summary', 'current_tiles_stock', 'current_misc_stock'];
        foreach ($views as $view) {
            try {
                $stmt = $pdo->query("SELECT COUNT(*) FROM $view");
                $count = $stmt->fetchColumn();
                echo "✅ View '$view' - $count records\n";
            } catch (Exception $e) {
                echo "❌ View '$view' - Error: " . $e->getMessage() . "\n";
            }
        }
    } catch (Exception $e) {
        echo "❌ Views test - Error: " . $e->getMessage() . "\n";
    }
    
    echo "\n✅ Database operations testing completed successfully\n";
    
} catch (Exception $e) {
    echo "❌ Database operations testing failed: " . $e->getMessage() . "\n";
}

echo "\n=== Database Operations Testing Complete ===\n";
?>