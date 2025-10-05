<?php
// backend_test.php - Comprehensive Backend Testing for Inventory System
require_once __DIR__ . '/includes/Database.php';
require_once __DIR__ . '/includes/inventory_updates.php';
require_once __DIR__ . '/includes/inventory_calculations.php';

class InventorySystemTester {
    private $pdo;
    private $tests_run = 0;
    private $tests_passed = 0;
    private $test_results = [];
    
    public function __construct() {
        try {
            $this->pdo = Database::pdo();
            echo "✅ Database connection established\n";
        } catch (Exception $e) {
            echo "❌ Database connection failed: " . $e->getMessage() . "\n";
            exit(1);
        }
    }
    
    public function runAllTests() {
        echo "\n🔍 Starting Inventory System Backend Tests...\n";
        echo "=" . str_repeat("=", 50) . "\n\n";
        
        // Test database structure
        $this->testDatabaseStructure();
        
        // Test inventory calculations
        $this->testInventoryCalculations();
        
        // Test inventory updates (sales/returns)
        $this->testInventoryUpdates();
        
        // Test inventory views and summaries
        $this->testInventoryViews();
        
        // Test data integrity
        $this->testDataIntegrity();
        
        $this->printSummary();
        return $this->tests_passed === $this->tests_run;
    }
    
    private function runTest($name, $callback) {
        $this->tests_run++;
        echo "🔍 Testing: $name... ";
        
        try {
            $result = $callback();
            if ($result) {
                $this->tests_passed++;
                echo "✅ PASSED\n";
                $this->test_results[] = ['name' => $name, 'status' => 'PASSED', 'message' => ''];
            } else {
                echo "❌ FAILED\n";
                $this->test_results[] = ['name' => $name, 'status' => 'FAILED', 'message' => 'Test returned false'];
            }
        } catch (Exception $e) {
            echo "❌ ERROR: " . $e->getMessage() . "\n";
            $this->test_results[] = ['name' => $name, 'status' => 'ERROR', 'message' => $e->getMessage()];
        }
    }
    
    private function testDatabaseStructure() {
        echo "\n📋 Testing Database Structure...\n";
        
        // Test required tables exist
        $this->runTest("Required tables exist", function() {
            $required_tables = [
                'tiles', 'tile_sizes', 'inventory_items', 'invoices', 'invoice_items', 
                'individual_returns', 'purchase_entries_tiles'
            ];
            
            foreach ($required_tables as $table) {
                $stmt = $this->pdo->prepare("SELECT name FROM sqlite_master WHERE type='table' AND name = ?");
                $stmt->execute([$table]);
                if (!$stmt->fetchColumn()) {
                    throw new Exception("Required table '$table' not found");
                }
            }
            return true;
        });
        
        // Test inventory views can be created
        $this->runTest("Inventory views creation", function() {
            InventoryUpdates::createInventoryViews($this->pdo);
            
            // Check if view exists
            $stmt = $this->pdo->prepare("SELECT name FROM sqlite_master WHERE type='view' AND name = 'current_inventory_summary'");
            $stmt->execute();
            return (bool)$stmt->fetchColumn();
        });
    }
    
    private function testInventoryCalculations() {
        echo "\n🧮 Testing Inventory Calculations...\n";
        
        // Test basic cost calculations
        $this->runTest("Basic cost calculations", function() {
            $test_data = [
                'boxes_in' => 100,
                'damage_boxes' => 5,
                'per_box_value' => 50,
                'transport_pct' => 10,
                'transport_per_box' => 2
            ];
            
            $result = InventoryCalculations::calculateItemCosts($test_data, 1.5);
            
            // Verify calculations
            if ($result['net_boxes'] !== 95.0) {
                throw new Exception("Net boxes calculation incorrect: expected 95, got " . $result['net_boxes']);
            }
            
            if ($result['damage_percentage'] !== 5.0) {
                throw new Exception("Damage percentage calculation incorrect: expected 5%, got " . $result['damage_percentage'] . "%");
            }
            
            // Expected: base 50 + transport (50*0.1 + 2) = 50 + 5 + 2 = 57
            if ($result['final_cost_per_box'] !== 57.0) {
                throw new Exception("Final cost per box incorrect: expected 57, got " . $result['final_cost_per_box']);
            }
            
            return true;
        });
        
        // Test validation
        $this->runTest("Data validation", function() {
            $invalid_data = [
                'tile_id' => 0,
                'boxes_in' => -10,
                'damage_boxes' => 150,
                'per_box_value' => -5
            ];
            
            $validation = InventoryCalculations::validateInventoryData($invalid_data);
            
            if ($validation['valid']) {
                throw new Exception("Validation should have failed for invalid data");
            }
            
            if (count($validation['errors']) < 3) {
                throw new Exception("Expected multiple validation errors, got " . count($validation['errors']));
            }
            
            return true;
        });
    }
    
    private function testInventoryUpdates() {
        echo "\n🔄 Testing Inventory Updates (Sales/Returns)...\n";
        
        // Test sales processing
        $this->runTest("Sales inventory deduction", function() {
            // Create test data
            $test_tile_id = $this->createTestTile();
            $this->addTestInventory($test_tile_id, 100); // Add 100 boxes
            
            // Get initial inventory
            $initial_levels = InventoryUpdates::getCurrentInventoryLevels($this->pdo);
            $initial_stock = 0;
            foreach ($initial_levels as $item) {
                if ($item['tile_id'] == $test_tile_id) {
                    $initial_stock = (float)$item['available_boxes'];
                    break;
                }
            }
            
            // Process a sale
            $sale_items = [
                ['tile_id' => $test_tile_id, 'boxes_decimal' => 25]
            ];
            InventoryUpdates::processSale($this->pdo, 999, $sale_items);
            
            // Check inventory after sale
            $updated_levels = InventoryUpdates::getCurrentInventoryLevels($this->pdo);
            $updated_stock = 0;
            foreach ($updated_levels as $item) {
                if ($item['tile_id'] == $test_tile_id) {
                    $updated_stock = (float)$item['available_boxes'];
                    break;
                }
            }
            
            // Should be reduced by 25
            $expected_stock = $initial_stock - 25;
            if (abs($updated_stock - $expected_stock) > 0.01) {
                throw new Exception("Stock not properly deducted. Expected: $expected_stock, Got: $updated_stock");
            }
            
            return true;
        });
        
        // Test returns processing
        $this->runTest("Returns inventory restoration", function() {
            $test_tile_id = $this->createTestTile();
            $this->addTestInventory($test_tile_id, 50);
            
            // Get initial stock
            $initial_levels = InventoryUpdates::getCurrentInventoryLevels($this->pdo);
            $initial_stock = 0;
            foreach ($initial_levels as $item) {
                if ($item['tile_id'] == $test_tile_id) {
                    $initial_stock = (float)$item['available_boxes'];
                    break;
                }
            }
            
            // Process a return
            $return_data = [
                'tile_id' => $test_tile_id,
                'quantity' => 10
            ];
            InventoryUpdates::processReturn($this->pdo, 888, $return_data);
            
            // Check inventory after return
            $updated_levels = InventoryUpdates::getCurrentInventoryLevels($this->pdo);
            $updated_stock = 0;
            foreach ($updated_levels as $item) {
                if ($item['tile_id'] == $test_tile_id) {
                    $updated_stock = (float)$item['available_boxes'];
                    break;
                }
            }
            
            // Should be increased by 10
            $expected_stock = $initial_stock + 10;
            if (abs($updated_stock - $expected_stock) > 0.01) {
                throw new Exception("Stock not properly restored. Expected: $expected_stock, Got: $updated_stock");
            }
            
            return true;
        });
    }
    
    private function testInventoryViews() {
        echo "\n📊 Testing Inventory Views and Summaries...\n";
        
        // Test inventory summary data
        $this->runTest("Inventory summary data structure", function() {
            $inventory_data = InventoryUpdates::getCurrentInventoryLevels($this->pdo);
            
            if (!is_array($inventory_data)) {
                throw new Exception("Inventory data should be an array");
            }
            
            // Check required fields in each item
            if (!empty($inventory_data)) {
                $required_fields = [
                    'tile_name', 'size_label', 'total_received', 'total_sold', 
                    'total_returned', 'available_boxes', 'current_stock', 
                    'total_cost_value', 'avg_cost_per_box'
                ];
                
                $first_item = $inventory_data[0];
                foreach ($required_fields as $field) {
                    if (!array_key_exists($field, $first_item)) {
                        throw new Exception("Required field '$field' missing from inventory data");
                    }
                }
            }
            
            return true;
        });
        
        // Test reconciliation
        $this->runTest("Inventory reconciliation", function() {
            $result = InventoryUpdates::reconcileInventory($this->pdo);
            return $result === true;
        });
    }
    
    private function testDataIntegrity() {
        echo "\n🔒 Testing Data Integrity...\n";
        
        // Test negative stock prevention
        $this->runTest("Negative stock prevention", function() {
            $test_tile_id = $this->createTestTile();
            $this->addTestInventory($test_tile_id, 10); // Only 10 boxes
            
            // Try to sell more than available
            $sale_items = [
                ['tile_id' => $test_tile_id, 'boxes_decimal' => 50] // Try to sell 50
            ];
            
            // This should still process but result in negative available stock
            InventoryUpdates::processSale($this->pdo, 777, $sale_items);
            
            $levels = InventoryUpdates::getCurrentInventoryLevels($this->pdo);
            $stock = 0;
            foreach ($levels as $item) {
                if ($item['tile_id'] == $test_tile_id) {
                    $stock = (float)$item['available_boxes'];
                    break;
                }
            }
            
            // Should be negative (10 - 50 = -40)
            if ($stock >= 0) {
                throw new Exception("System should allow negative stock for tracking purposes, got: $stock");
            }
            
            return true;
        });
        
        // Test cost calculations consistency
        $this->runTest("Cost calculations consistency", function() {
            $inventory_data = InventoryUpdates::getCurrentInventoryLevels($this->pdo);
            
            foreach ($inventory_data as $item) {
                $available_boxes = (float)$item['available_boxes'];
                $avg_cost = (float)$item['avg_cost_per_box'];
                $total_cost = (float)$item['total_cost_value'];
                
                if ($available_boxes > 0) {
                    $calculated_total = $available_boxes * $avg_cost;
                    if (abs($calculated_total - $total_cost) > 0.01) {
                        throw new Exception("Cost calculation inconsistency for tile {$item['tile_name']}: expected $calculated_total, got $total_cost");
                    }
                }
            }
            
            return true;
        });
    }
    
    private function createTestTile() {
        // Create a test tile size if not exists
        $this->pdo->exec("INSERT OR IGNORE INTO tile_sizes (id, label, sqft_per_box) VALUES (999, 'Test Size', 1.5)");
        
        // Create a test tile with unique name
        $unique_name = 'Test Tile ' . time() . '_' . rand(1000, 9999);
        $stmt = $this->pdo->prepare("INSERT INTO tiles (name, size_id) VALUES (?, ?)");
        $stmt->execute([$unique_name, 999]);
        
        return (int)$this->pdo->lastInsertId();
    }
    
    private function addTestInventory($tile_id, $boxes) {
        $stmt = $this->pdo->prepare("
            INSERT INTO inventory_items (tile_id, purchase_dt, boxes_in, per_box_value, vendor)
            VALUES (?, ?, ?, ?, ?)
        ");
        $stmt->execute([$tile_id, date('Y-m-d'), $boxes, 50, 'Test Vendor']);
    }
    
    private function printSummary() {
        echo "\n" . str_repeat("=", 60) . "\n";
        echo "📊 TEST SUMMARY\n";
        echo str_repeat("=", 60) . "\n";
        echo "Total Tests: {$this->tests_run}\n";
        echo "Passed: {$this->tests_passed}\n";
        echo "Failed: " . ($this->tests_run - $this->tests_passed) . "\n";
        echo "Success Rate: " . round(($this->tests_passed / $this->tests_run) * 100, 1) . "%\n";
        
        if ($this->tests_passed === $this->tests_run) {
            echo "\n🎉 ALL TESTS PASSED! Inventory system is working correctly.\n";
        } else {
            echo "\n⚠️  SOME TESTS FAILED. Check the details above.\n";
            
            echo "\nFailed Tests:\n";
            foreach ($this->test_results as $result) {
                if ($result['status'] !== 'PASSED') {
                    echo "- {$result['name']}: {$result['status']}";
                    if ($result['message']) {
                        echo " ({$result['message']})";
                    }
                    echo "\n";
                }
            }
        }
        echo str_repeat("=", 60) . "\n";
    }
}

// Run the tests
$tester = new InventorySystemTester();
$success = $tester->runAllTests();

exit($success ? 0 : 1);
?>