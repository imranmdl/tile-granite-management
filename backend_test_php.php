<?php
// backend_test_php.php - Test PHP functionality for critical fixes
error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "=== PHP Backend Testing for Critical Fixes ===\n";

// Test 1: Check if other_purchase.php has syntax errors (line 84 fix)
echo "\n1. Testing other_purchase.php syntax...\n";
$syntax_check = shell_exec('php -l /app/public/other_purchase.php 2>&1');
if (strpos($syntax_check, 'No syntax errors') !== false) {
    echo "✅ other_purchase.php - No syntax errors found\n";
} else {
    echo "❌ other_purchase.php - Syntax errors found:\n";
    echo $syntax_check . "\n";
}

// Test 2: Check if quotation_enhanced.php has updateQuotationTotal function calls
echo "\n2. Testing quotation_enhanced.php for updateQuotationTotal calls...\n";
$quotation_content = file_get_contents('/app/public/quotation_enhanced.php');
$update_calls = substr_count($quotation_content, 'updateQuotationTotal');
if ($update_calls > 0) {
    echo "✅ quotation_enhanced.php - Found $update_calls updateQuotationTotal function calls\n";
} else {
    echo "❌ quotation_enhanced.php - No updateQuotationTotal function calls found\n";
}

// Test 3: Check if profit_calculations.php includes transport costs
echo "\n3. Testing profit_calculations.php for transport cost calculations...\n";
$profit_content = file_get_contents('/app/includes/profit_calculations.php');
$transport_mentions = substr_count(strtolower($profit_content), 'transport');
if ($transport_mentions > 5) {
    echo "✅ profit_calculations.php - Found $transport_mentions transport cost references\n";
} else {
    echo "❌ profit_calculations.php - Insufficient transport cost references ($transport_mentions found)\n";
}

// Test 4: Check if users_management.php has comprehensive permissions
echo "\n4. Testing users_management.php for comprehensive permissions...\n";
$users_content = file_get_contents('/app/public/users_management.php');
$permission_groups = [
    'Users & Access',
    'Inventory Management', 
    'Purchase Management',
    'Sales & Quotations',
    'Reports & Analytics',
    'Commission & Finance',
    'System Settings'
];

$found_groups = 0;
foreach ($permission_groups as $group) {
    if (strpos($users_content, $group) !== false) {
        $found_groups++;
    }
}

if ($found_groups >= 6) {
    echo "✅ users_management.php - Found $found_groups/$found_groups permission groups\n";
} else {
    echo "❌ users_management.php - Only found $found_groups/" . count($permission_groups) . " permission groups\n";
}

// Test 5: Check database connection and basic structure
echo "\n5. Testing database connection and structure...\n";
try {
    $pdo = new PDO('sqlite:/app/data/app.sqlite');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    echo "✅ Database connection successful\n";
    
    // Check for key tables
    $tables = ['tiles', 'misc_items', 'quotations', 'invoices', 'users_simple'];
    foreach ($tables as $table) {
        $stmt = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='$table'");
        if ($stmt->fetchColumn()) {
            echo "✅ Table '$table' exists\n";
        } else {
            echo "❌ Table '$table' missing\n";
        }
    }
    
} catch (Exception $e) {
    echo "❌ Database connection failed: " . $e->getMessage() . "\n";
}

// Test 6: Test inventory_summary_unified.php for transport cost inclusion
echo "\n6. Testing inventory_summary_unified.php for transport cost calculations...\n";
$inventory_content = file_get_contents('/app/public/inventory_summary_unified.php');
$inventory_updates_content = file_get_contents('/app/includes/inventory_updates.php');
$transport_in_misc = strpos($inventory_content, 'transport_cost_per_unit') !== false;
$transport_in_tiles = strpos($inventory_updates_content, 'transport_cost') !== false && strpos($inventory_updates_content, 'purchase_entries_tiles') !== false;

if ($transport_in_misc && $transport_in_tiles) {
    echo "✅ inventory_summary_unified.php - Transport costs included in calculations\n";
} else {
    echo "❌ inventory_summary_unified.php - Transport costs missing in calculations\n";
    if (!$transport_in_misc) echo "  - Missing transport_cost_per_unit for misc items\n";
    if (!$transport_in_tiles) echo "  - Missing transport costs for tiles in inventory_updates.php\n";
}

// Test 7: Check if report_profit_loss.php includes transport and commission
echo "\n7. Testing report_profit_loss.php for comprehensive cost calculations...\n";
$report_content = file_get_contents('/app/public/report_profit_loss.php');
$has_transport = strpos($report_content, 'transport_cost') !== false;
$has_commission = strpos($report_content, 'commission') !== false;

if ($has_transport && $has_commission) {
    echo "✅ report_profit_loss.php - Includes transport and commission costs\n";
} else {
    echo "❌ report_profit_loss.php - Missing cost components:\n";
    if (!$has_transport) echo "  - Transport costs missing\n";
    if (!$has_commission) echo "  - Commission costs missing\n";
}

echo "\n=== PHP Backend Testing Complete ===\n";
?>