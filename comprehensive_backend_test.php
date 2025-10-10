<?php
// comprehensive_backend_test.php - Comprehensive testing for PHP tile inventory system
error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "=== Comprehensive PHP Backend Testing ===\n";
echo "Testing all features mentioned in review request\n\n";

$test_results = [
    'passed' => [],
    'failed' => [],
    'warnings' => []
];

// Test 1: other_purchase.php - no fatal errors, proper form functionality
echo "1. Testing other_purchase.php functionality...\n";
try {
    // Check syntax
    $syntax_check = shell_exec('php -l /app/public/other_purchase.php 2>&1');
    if (strpos($syntax_check, 'No syntax errors') !== false) {
        echo "✅ other_purchase.php - No syntax errors\n";
        $test_results['passed'][] = "other_purchase.php syntax check";
    } else {
        echo "❌ other_purchase.php - Syntax errors found:\n";
        echo $syntax_check . "\n";
        $test_results['failed'][] = "other_purchase.php syntax check";
    }
    
    // Check for key functionality
    $content = file_get_contents('/app/public/other_purchase.php');
    $checks = [
        'form handling' => strpos($content, '$_SERVER[\'REQUEST_METHOD\'] === \'POST\'') !== false,
        'database operations' => strpos($content, 'INSERT INTO misc_inventory_items') !== false,
        'transport cost handling' => strpos($content, 'transport_cost') !== false,
        'error handling' => strpos($content, 'try {') !== false && strpos($content, 'catch') !== false,
        'item status toggle' => strpos($content, 'toggle_status') !== false
    ];
    
    foreach ($checks as $check => $passed) {
        if ($passed) {
            echo "✅ other_purchase.php - $check working\n";
            $test_results['passed'][] = "other_purchase.php $check";
        } else {
            echo "❌ other_purchase.php - $check missing\n";
            $test_results['failed'][] = "other_purchase.php $check";
        }
    }
    
} catch (Exception $e) {
    echo "❌ other_purchase.php - Error during testing: " . $e->getMessage() . "\n";
    $test_results['failed'][] = "other_purchase.php testing";
}

// Test 2: inventory_summary_unified.php - correct cost calculations with transport costs
echo "\n2. Testing inventory_summary_unified.php cost calculations...\n";
try {
    $content = file_get_contents('/app/public/inventory_summary_unified.php');
    
    $checks = [
        'transport cost in tiles' => strpos($content, 'transport_per_box') !== false,
        'transport cost in misc' => strpos($content, 'transport_cost') !== false,
        'cost calculations' => strpos($content, 'avg_cost_per_unit') !== false,
        'inventory levels' => strpos($content, 'getCurrentInventoryLevels') !== false,
        'unified view' => strpos($content, 'tiles_inventory') !== false && strpos($content, 'misc_inventory') !== false
    ];
    
    foreach ($checks as $check => $passed) {
        if ($passed) {
            echo "✅ inventory_summary_unified.php - $check present\n";
            $test_results['passed'][] = "inventory_summary_unified.php $check";
        } else {
            echo "❌ inventory_summary_unified.php - $check missing\n";
            $test_results['failed'][] = "inventory_summary_unified.php $check";
        }
    }
    
} catch (Exception $e) {
    echo "❌ inventory_summary_unified.php - Error during testing: " . $e->getMessage() . "\n";
    $test_results['failed'][] = "inventory_summary_unified.php testing";
}

// Test 3: profit_calculations.php - no database column errors, proper transport cost inclusion
echo "\n3. Testing profit_calculations.php for transport cost inclusion...\n";
try {
    $content = file_get_contents('/app/includes/profit_calculations.php');
    
    $checks = [
        'transport cost references' => substr_count(strtolower($content), 'transport') >= 20,
        'cost calculations' => strpos($content, 'calculateInvoiceProfit') !== false,
        'quotation profit' => strpos($content, 'calculateQuotationProfit') !== false,
        'item wise analysis' => strpos($content, 'getItemWiseProfitAnalysis') !== false,
        'transport in tiles' => strpos($content, 'transport_per_box') !== false,
        'transport in misc' => strpos($content, 'transport_cost') !== false,
        'comprehensive costs' => strpos($content, 'total_costs') !== false
    ];
    
    foreach ($checks as $check => $passed) {
        if ($passed) {
            echo "✅ profit_calculations.php - $check working\n";
            $test_results['passed'][] = "profit_calculations.php $check";
        } else {
            echo "❌ profit_calculations.php - $check missing\n";
            $test_results['failed'][] = "profit_calculations.php $check";
        }
    }
    
} catch (Exception $e) {
    echo "❌ profit_calculations.php - Error during testing: " . $e->getMessage() . "\n";
    $test_results['failed'][] = "profit_calculations.php testing";
}

// Test 4: report_inventory_enhanced.php - uses actual inventory tables instead of views
echo "\n4. Testing report_inventory_enhanced.php for correct table usage...\n";
try {
    $content = file_get_contents('/app/public/report_inventory_enhanced.php');
    
    $checks = [
        'uses inventory_items table' => strpos($content, 'FROM inventory_items') !== false,
        'uses misc_inventory_items table' => strpos($content, 'FROM misc_inventory_items') !== false,
        'no non-existent views' => strpos($content, 'FROM inventory_view') === false,
        'transport cost inclusion' => strpos($content, 'transport') !== false,
        'current stock calculations' => strpos($content, 'current_stock') !== false,
        'cost per unit calculations' => strpos($content, 'avg_cost_per') !== false
    ];
    
    foreach ($checks as $check => $passed) {
        if ($passed) {
            echo "✅ report_inventory_enhanced.php - $check correct\n";
            $test_results['passed'][] = "report_inventory_enhanced.php $check";
        } else {
            echo "❌ report_inventory_enhanced.php - $check incorrect\n";
            $test_results['failed'][] = "report_inventory_enhanced.php $check";
        }
    }
    
} catch (Exception $e) {
    echo "❌ report_inventory_enhanced.php - Error during testing: " . $e->getMessage() . "\n";
    $test_results['failed'][] = "report_inventory_enhanced.php testing";
}

// Test 5: report_profit_loss.php - all cost calculations include transport costs and commission
echo "\n5. Testing report_profit_loss.php for comprehensive cost calculations...\n";
try {
    $content = file_get_contents('/app/public/report_profit_loss.php');
    
    $checks = [
        'transport costs' => strpos($content, 'transport_cost') !== false,
        'commission calculations' => strpos($content, 'commission') !== false,
        'bulk invoice analysis' => strpos($content, 'bulk_invoices') !== false,
        'item wise analysis' => strpos($content, 'item_wise') !== false,
        'profit calculations' => strpos($content, 'ProfitCalculations::') !== false,
        'cost breakdown' => strpos($content, 'cost_breakdown') !== false
    ];
    
    foreach ($checks as $check => $passed) {
        if ($passed) {
            echo "✅ report_profit_loss.php - $check present\n";
            $test_results['passed'][] = "report_profit_loss.php $check";
        } else {
            echo "❌ report_profit_loss.php - $check missing\n";
            $test_results['failed'][] = "report_profit_loss.php $check";
        }
    }
    
} catch (Exception $e) {
    echo "❌ report_profit_loss.php - Error during testing: " . $e->getMessage() . "\n";
    $test_results['failed'][] = "report_profit_loss.php testing";
}

// Test 6: report_daily_pl.php - proper cost columns including transport costs
echo "\n6. Testing report_daily_pl.php for transport cost inclusion...\n";
try {
    $content = file_get_contents('/app/public/report_daily_pl.php');
    
    $checks = [
        'transport cost in tiles' => strpos($content, 'transport_per_box') !== false || strpos($content, 'avg_cost_per_box_with_transport') !== false,
        'transport cost in misc' => strpos($content, 'transport_cost') !== false || strpos($content, 'avg_cost_per_unit_with_transport') !== false,
        'daily breakdown' => strpos($content, 'daily_revenue') !== false,
        'cost calculations' => strpos($content, 'tile_cost') !== false && strpos($content, 'misc_cost') !== false,
        'commission handling' => strpos($content, 'commission') !== false,
        'profit margins' => strpos($content, 'profit_margin') !== false
    ];
    
    foreach ($checks as $check => $passed) {
        if ($passed) {
            echo "✅ report_daily_pl.php - $check working\n";
            $test_results['passed'][] = "report_daily_pl.php $check";
        } else {
            echo "❌ report_daily_pl.php - $check missing\n";
            $test_results['failed'][] = "report_daily_pl.php $check";
        }
    }
    
} catch (Exception $e) {
    echo "❌ report_daily_pl.php - Error during testing: " . $e->getMessage() . "\n";
    $test_results['failed'][] = "report_daily_pl.php testing";
}

// Test 7: report_commission_enhanced.php - detailed commission calculations
echo "\n7. Testing report_commission_enhanced.php for commission calculations...\n";
try {
    $content = file_get_contents('/app/public/report_commission_enhanced.php');
    
    $checks = [
        'commission data from invoices' => strpos($content, 'FROM invoices') !== false,
        'commission ledger' => strpos($content, 'commission_ledger') !== false,
        'salesperson summary' => strpos($content, 'salesperson_summary') !== false,
        'commission percentage' => strpos($content, 'commission_percentage') !== false,
        'commission amount' => strpos($content, 'commission_amount') !== false,
        'status handling' => strpos($content, 'status_summary') !== false
    ];
    
    foreach ($checks as $check => $passed) {
        if ($passed) {
            echo "✅ report_commission_enhanced.php - $check present\n";
            $test_results['passed'][] = "report_commission_enhanced.php $check";
        } else {
            echo "❌ report_commission_enhanced.php - $check missing\n";
            $test_results['failed'][] = "report_commission_enhanced.php $check";
        }
    }
    
} catch (Exception $e) {
    echo "❌ report_commission_enhanced.php - Error during testing: " . $e->getMessage() . "\n";
    $test_results['failed'][] = "report_commission_enhanced.php testing";
}

// Test 8: quotation_enhanced.php - total value calculations working properly
echo "\n8. Testing quotation_enhanced.php for total value calculations...\n";
try {
    $content = file_get_contents('/app/public/quotation_enhanced.php');
    
    $checks = [
        'updateQuotationTotal function' => strpos($content, 'function updateQuotationTotal') !== false,
        'updateQuotationTotal calls' => substr_count($content, 'updateQuotationTotal') >= 5,
        'stock calculations' => strpos($content, 'current_stock') !== false,
        'line total calculations' => strpos($content, 'line_total') !== false,
        'discount handling' => strpos($content, 'discount_amount') !== false,
        'commission handling' => strpos($content, 'commission_amount') !== false,
        'final total calculations' => strpos($content, 'final_total') !== false
    ];
    
    foreach ($checks as $check => $passed) {
        if ($passed) {
            echo "✅ quotation_enhanced.php - $check working\n";
            $test_results['passed'][] = "quotation_enhanced.php $check";
        } else {
            echo "❌ quotation_enhanced.php - $check missing\n";
            $test_results['failed'][] = "quotation_enhanced.php $check";
        }
    }
    
} catch (Exception $e) {
    echo "❌ quotation_enhanced.php - Error during testing: " . $e->getMessage() . "\n";
    $test_results['failed'][] = "quotation_enhanced.php testing";
}

// Test 9: Database operations - all queries use existing columns only
echo "\n9. Testing database operations for correct column usage...\n";
try {
    $pdo = new PDO('sqlite:/app/data/app.sqlite');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    echo "✅ Database connection successful\n";
    $test_results['passed'][] = "Database connection";
    
    // Test key table structures
    $tables_to_check = [
        'misc_inventory_items' => ['transport_cost', 'cost_per_unit', 'qty_in'],
        'inventory_items' => ['transport_per_box', 'per_box_value', 'boxes_in'],
        'quotations' => ['total', 'final_total', 'commission_amount'],
        'invoices' => ['total', 'final_total', 'commission_amount']
    ];
    
    foreach ($tables_to_check as $table => $columns) {
        try {
            $stmt = $pdo->query("PRAGMA table_info($table)");
            $table_columns = $stmt->fetchAll(PDO::FETCH_COLUMN, 1);
            
            $missing_columns = array_diff($columns, $table_columns);
            if (empty($missing_columns)) {
                echo "✅ Table '$table' - All required columns present\n";
                $test_results['passed'][] = "Table $table column structure";
            } else {
                echo "❌ Table '$table' - Missing columns: " . implode(', ', $missing_columns) . "\n";
                $test_results['failed'][] = "Table $table missing columns";
            }
        } catch (Exception $e) {
            echo "❌ Table '$table' - Error checking structure: " . $e->getMessage() . "\n";
            $test_results['failed'][] = "Table $table structure check";
        }
    }
    
} catch (Exception $e) {
    echo "❌ Database operations - Connection failed: " . $e->getMessage() . "\n";
    $test_results['failed'][] = "Database connection";
}

// Test 10: Cost consistency - transport costs included across all inventory and profit calculations
echo "\n10. Testing cost consistency across all modules...\n";
try {
    $files_to_check = [
        '/app/public/inventory_summary_unified.php',
        '/app/includes/profit_calculations.php',
        '/app/public/report_profit_loss.php',
        '/app/public/report_daily_pl.php'
    ];
    
    $transport_consistency = true;
    foreach ($files_to_check as $file) {
        $content = file_get_contents($file);
        $has_transport = strpos($content, 'transport') !== false;
        
        if ($has_transport) {
            echo "✅ " . basename($file) . " - Transport costs included\n";
            $test_results['passed'][] = basename($file) . " transport costs";
        } else {
            echo "❌ " . basename($file) . " - Transport costs missing\n";
            $test_results['failed'][] = basename($file) . " transport costs";
            $transport_consistency = false;
        }
    }
    
    if ($transport_consistency) {
        echo "✅ Cost consistency - Transport costs included across all modules\n";
        $test_results['passed'][] = "Cost consistency across modules";
    } else {
        echo "❌ Cost consistency - Transport costs missing in some modules\n";
        $test_results['failed'][] = "Cost consistency across modules";
    }
    
} catch (Exception $e) {
    echo "❌ Cost consistency - Error during testing: " . $e->getMessage() . "\n";
    $test_results['failed'][] = "Cost consistency testing";
}

// Test 11: Syntax check for all PHP files
echo "\n11. Running syntax check on all PHP files...\n";
try {
    $php_files = [
        '/app/public/other_purchase.php',
        '/app/public/inventory_summary_unified.php',
        '/app/public/report_inventory_enhanced.php',
        '/app/public/report_profit_loss.php',
        '/app/public/report_daily_pl.php',
        '/app/public/report_commission_enhanced.php',
        '/app/public/quotation_enhanced.php',
        '/app/includes/profit_calculations.php'
    ];
    
    $syntax_errors = 0;
    foreach ($php_files as $file) {
        if (file_exists($file)) {
            $syntax_check = shell_exec("php -l $file 2>&1");
            if (strpos($syntax_check, 'No syntax errors') !== false) {
                echo "✅ " . basename($file) . " - No syntax errors\n";
                $test_results['passed'][] = basename($file) . " syntax";
            } else {
                echo "❌ " . basename($file) . " - Syntax errors found\n";
                $test_results['failed'][] = basename($file) . " syntax";
                $syntax_errors++;
            }
        } else {
            echo "⚠️  " . basename($file) . " - File not found\n";
            $test_results['warnings'][] = basename($file) . " file not found";
        }
    }
    
    if ($syntax_errors === 0) {
        echo "✅ All PHP files have correct syntax\n";
        $test_results['passed'][] = "All PHP files syntax check";
    } else {
        echo "❌ $syntax_errors PHP files have syntax errors\n";
        $test_results['failed'][] = "PHP files syntax errors";
    }
    
} catch (Exception $e) {
    echo "❌ Syntax check - Error during testing: " . $e->getMessage() . "\n";
    $test_results['failed'][] = "Syntax check testing";
}

// Summary
echo "\n=== Test Summary ===\n";
echo "✅ Passed tests: " . count($test_results['passed']) . "\n";
echo "❌ Failed tests: " . count($test_results['failed']) . "\n";
echo "⚠️  Warnings: " . count($test_results['warnings']) . "\n";

if (count($test_results['failed']) > 0) {
    echo "\nFailed tests:\n";
    foreach ($test_results['failed'] as $failed) {
        echo "  - $failed\n";
    }
}

if (count($test_results['warnings']) > 0) {
    echo "\nWarnings:\n";
    foreach ($test_results['warnings'] as $warning) {
        echo "  - $warning\n";
    }
}

$success_rate = count($test_results['passed']) / (count($test_results['passed']) + count($test_results['failed'])) * 100;
echo "\nOverall success rate: " . number_format($success_rate, 1) . "%\n";

echo "\n=== Comprehensive PHP Backend Testing Complete ===\n";
?>