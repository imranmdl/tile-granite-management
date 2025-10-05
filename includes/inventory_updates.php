<?php
// includes/inventory_updates.php - Inventory Update Functions for Sales & Returns

class InventoryUpdates {
    
    /**
     * Update inventory after sale
     */
    public static function processSale(PDO $pdo, int $invoice_id, array $items) {
        foreach ($items as $item) {
            if (isset($item['tile_id']) && $item['tile_id'] > 0) {
                // Deduct from inventory
                self::updateTileInventory($pdo, (int)$item['tile_id'], -(float)($item['boxes_decimal'] ?? 0), 'sale', $invoice_id);
            }
        }
    }
    
    /**
     * Update inventory after return
     */
    public static function processReturn(PDO $pdo, int $return_id, array $return_data) {
        if (isset($return_data['tile_id']) && $return_data['tile_id'] > 0) {
            // Add back to inventory
            self::updateTileInventory($pdo, (int)$return_data['tile_id'], (float)($return_data['quantity'] ?? 0), 'return', $return_id);
        }
    }
    
    /**
     * Core inventory update function
     */
    private static function updateTileInventory(PDO $pdo, int $tile_id, float $quantity_change, string $transaction_type, int $reference_id) {
        try {
            // Create inventory transaction record
            if (!self::tableExists($pdo, 'inventory_transactions')) {
                self::createInventoryTransactionsTable($pdo);
            }
            
            $stmt = $pdo->prepare("
                INSERT INTO inventory_transactions 
                (tile_id, quantity_change, transaction_type, reference_id, transaction_date, created_by)
                VALUES (?, ?, ?, ?, datetime('now'), ?)
            ");
            
            $user_id = $_SESSION['user_id'] ?? 1;
            $stmt->execute([$tile_id, $quantity_change, $transaction_type, $reference_id, $user_id]);
            
            return true;
        } catch (Exception $e) {
            error_log("Inventory update error: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Get current inventory levels for all tiles
     */
    public static function getCurrentInventoryLevels(PDO $pdo) {
        // Ensure views exist
        self::createInventoryViews($pdo);
        
        $sql = "
            SELECT 
                t.id,
                t.name,
                ts.label as size_label,
                ts.sqft_per_box,
                COALESCE(cis.total_received, 0) as total_received,
                COALESCE(cis.total_sold, 0) as total_sold,
                COALESCE(cis.total_returned, 0) as total_returned,
                COALESCE(cis.current_stock, 0) as current_stock,
                COALESCE(cis.available_boxes, 0) as available_boxes,
                COALESCE(cis.total_cost_value, 0) as total_cost_value,
                COALESCE(cis.avg_cost_per_box, 0) as avg_cost_per_box
            FROM tiles t
            JOIN tile_sizes ts ON t.size_id = ts.id
            LEFT JOIN current_inventory_summary cis ON t.id = cis.tile_id
            ORDER BY t.name, ts.label
        ";
        
        return $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * Get detailed inventory for specific tile
     */
    public static function getTileInventoryDetail(PDO $pdo, int $tile_id) {
        // Purchases (additions to inventory)
        $purchases = $pdo->prepare("
            SELECT 
                'purchase' as type,
                purchase_dt as date,
                boxes_in as quantity,
                (boxes_in - COALESCE(damage_boxes, 0)) as net_quantity,
                per_box_value as cost_per_box,
                vendor,
                notes
            FROM inventory_items 
            WHERE tile_id = ?
            
            UNION ALL
            
            SELECT 
                'purchase' as type,
                purchase_date as date, 
                total_boxes as quantity,
                (total_boxes * (100 - COALESCE(damage_percentage, 0)) / 100) as net_quantity,
                cost_per_box as cost_per_box,
                supplier_name as vendor,
                notes
            FROM purchase_entries_tiles pet
            WHERE tile_id = ? AND pet.id NOT IN (
                SELECT DISTINCT reference_id FROM inventory_items WHERE tile_id = ? AND reference_type = 'purchase_entry'
            )
            
            ORDER BY date DESC
        ");
        $purchases->execute([$tile_id, $tile_id, $tile_id]);
        
        // Sales (deductions from inventory)
        $sales = $pdo->prepare("
            SELECT 
                'sale' as type,
                i.invoice_dt as date,
                -(ii.boxes_decimal) as quantity,
                ii.rate_per_box as cost_per_box,
                i.customer_name as reference,
                i.invoice_no
            FROM invoice_items ii
            JOIN invoices i ON ii.invoice_id = i.id
            WHERE ii.tile_id = ?
            
            UNION ALL
            
            SELECT 
                'return' as type,
                ir.return_date as date,
                ir.quantity as quantity,
                ir.refund_rate as cost_per_box,
                i.customer_name as reference,
                i.invoice_no
            FROM individual_returns ir
            JOIN invoices i ON ir.invoice_id = i.id
            WHERE ir.tile_id = ?
            
            ORDER BY date DESC
        ");
        $sales->execute([$tile_id, $tile_id]);
        
        return [
            'purchases' => $purchases->fetchAll(PDO::FETCH_ASSOC),
            'sales' => $sales->fetchAll(PDO::FETCH_ASSOC)
        ];
    }
    
    /**
     * Create inventory transactions table
     */
    private static function createInventoryTransactionsTable(PDO $pdo) {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS inventory_transactions (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                tile_id INTEGER NOT NULL,
                quantity_change REAL NOT NULL,
                transaction_type TEXT NOT NULL, -- 'purchase', 'sale', 'return', 'adjustment'
                reference_id INTEGER,
                transaction_date TEXT NOT NULL,
                notes TEXT,
                created_by INTEGER,
                created_at TEXT DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (tile_id) REFERENCES tiles(id)
            )
        ");
        
        // Create index for performance
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_inventory_transactions_tile ON inventory_transactions(tile_id)");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_inventory_transactions_type ON inventory_transactions(transaction_type)");
    }
    
    /**
     * Create inventory summary views
     */
    public static function createInventoryViews(PDO $pdo) {
        // Create comprehensive current inventory view
        $pdo->exec("
            CREATE VIEW IF NOT EXISTS current_inventory_summary AS
            SELECT 
                t.id as tile_id,
                t.name as tile_name,
                ts.label as size_label,
                ts.sqft_per_box,
                
                -- Received quantities
                COALESCE(purchases.total_boxes_received, 0) as total_received,
                COALESCE(purchases.total_net_boxes, 0) as total_net_received,
                COALESCE(purchases.total_damage_boxes, 0) as total_damage,
                
                -- Sold quantities  
                COALESCE(sales.total_boxes_sold, 0) as total_sold,
                
                -- Returned quantities
                COALESCE(returns.total_boxes_returned, 0) as total_returned,
                
                -- Current stock calculation
                (COALESCE(purchases.total_net_boxes, 0) - COALESCE(sales.total_boxes_sold, 0) + COALESCE(returns.total_boxes_returned, 0)) as current_stock,
                
                -- Available boxes (same as current stock for now)
                (COALESCE(purchases.total_net_boxes, 0) - COALESCE(sales.total_boxes_sold, 0) + COALESCE(returns.total_boxes_returned, 0)) as available_boxes,
                
                -- Cost calculations
                COALESCE(purchases.weighted_avg_cost, 0) as avg_cost_per_box,
                (COALESCE(purchases.total_net_boxes, 0) - COALESCE(sales.total_boxes_sold, 0) + COALESCE(returns.total_boxes_returned, 0)) * COALESCE(purchases.weighted_avg_cost, 0) as total_cost_value
                
            FROM tiles t
            JOIN tile_sizes ts ON t.size_id = ts.id
            
            -- Aggregate purchase data
            LEFT JOIN (
                SELECT 
                    tile_id,
                    SUM(boxes_in) as total_boxes_received,
                    SUM(boxes_in - COALESCE(damage_boxes, 0)) as total_net_boxes,
                    SUM(COALESCE(damage_boxes, 0)) as total_damage_boxes,
                    CASE 
                        WHEN SUM(boxes_in - COALESCE(damage_boxes, 0)) > 0 
                        THEN SUM((boxes_in - COALESCE(damage_boxes, 0)) * COALESCE(purchase_box_value, 0)) / SUM(boxes_in - COALESCE(damage_boxes, 0))
                        ELSE 0 
                    END as weighted_avg_cost
                FROM inventory_items 
                GROUP BY tile_id
                
                UNION ALL
                
                SELECT 
                    tile_id,
                    SUM(total_boxes) as total_boxes_received,
                    SUM(total_boxes * (100 - COALESCE(damage_percentage, 0)) / 100) as total_net_boxes,
                    SUM(total_boxes * COALESCE(damage_percentage, 0) / 100) as total_damage_boxes,
                    CASE 
                        WHEN SUM(total_boxes * (100 - COALESCE(damage_percentage, 0)) / 100) > 0
                        THEN SUM(total_boxes * (100 - COALESCE(damage_percentage, 0)) / 100 * COALESCE(cost_per_box, 0)) / SUM(total_boxes * (100 - COALESCE(damage_percentage, 0)) / 100)
                        ELSE 0
                    END as weighted_avg_cost
                FROM purchase_entries_tiles 
                GROUP BY tile_id
            ) purchases ON t.id = purchases.tile_id
            
            -- Aggregate sales data  
            LEFT JOIN (
                SELECT 
                    tile_id,
                    SUM(boxes_decimal) as total_boxes_sold
                FROM invoice_items
                GROUP BY tile_id
            ) sales ON t.id = sales.tile_id
            
            -- Aggregate returns data
            LEFT JOIN (
                SELECT 
                    tile_id,
                    SUM(quantity) as total_boxes_returned
                FROM individual_returns
                GROUP BY tile_id  
            ) returns ON t.id = returns.tile_id
        ");
        
        // Ensure the view is refreshed
        try {
            $pdo->exec("DROP VIEW IF EXISTS current_inventory_summary");
            $pdo->exec("
                CREATE VIEW current_inventory_summary AS
                SELECT 
                    t.id as tile_id,
                    t.name as tile_name,
                    ts.label as size_label,
                    ts.sqft_per_box,
                    
                    -- Purchase totals
                    COALESCE(p.total_boxes_received, 0) + COALESCE(pet.total_boxes_received, 0) as total_received,
                    COALESCE(p.total_net_boxes, 0) + COALESCE(pet.total_net_boxes, 0) as total_net_received,
                    
                    -- Sales totals
                    COALESCE(s.total_boxes_sold, 0) as total_sold,
                    
                    -- Returns totals
                    COALESCE(r.total_boxes_returned, 0) as total_returned,
                    
                    -- Current calculations
                    (COALESCE(p.total_net_boxes, 0) + COALESCE(pet.total_net_boxes, 0) - COALESCE(s.total_boxes_sold, 0) + COALESCE(r.total_boxes_returned, 0)) as current_stock,
                    (COALESCE(p.total_net_boxes, 0) + COALESCE(pet.total_net_boxes, 0) - COALESCE(s.total_boxes_sold, 0) + COALESCE(r.total_boxes_returned, 0)) as available_boxes,
                    
                    -- Cost calculations
                    CASE 
                        WHEN (COALESCE(p.total_net_boxes, 0) + COALESCE(pet.total_net_boxes, 0)) > 0
                        THEN (COALESCE(p.total_cost, 0) + COALESCE(pet.total_cost, 0)) / (COALESCE(p.total_net_boxes, 0) + COALESCE(pet.total_net_boxes, 0))
                        ELSE 0
                    END as avg_cost_per_box,
                    
                    (COALESCE(p.total_net_boxes, 0) + COALESCE(pet.total_net_boxes, 0) - COALESCE(s.total_boxes_sold, 0) + COALESCE(r.total_boxes_returned, 0)) * 
                    CASE 
                        WHEN (COALESCE(p.total_net_boxes, 0) + COALESCE(pet.total_net_boxes, 0)) > 0
                        THEN (COALESCE(p.total_cost, 0) + COALESCE(pet.total_cost, 0)) / (COALESCE(p.total_net_boxes, 0) + COALESCE(pet.total_net_boxes, 0))
                        ELSE 0
                    END as total_cost_value
                    
                FROM tiles t
                JOIN tile_sizes ts ON t.size_id = ts.id
                
                -- Inventory items purchases
                LEFT JOIN (
                    SELECT 
                        tile_id,
                        SUM(boxes_in) as total_boxes_received,
                        SUM(boxes_in - COALESCE(damage_boxes, 0)) as total_net_boxes,
                        SUM((boxes_in - COALESCE(damage_boxes, 0)) * COALESCE(purchase_box_value, 0)) as total_cost
                    FROM inventory_items 
                    GROUP BY tile_id
                ) p ON t.id = p.tile_id
                
                -- Purchase entries tiles  
                LEFT JOIN (
                    SELECT 
                        tile_id,
                        SUM(total_boxes) as total_boxes_received,
                        SUM(total_boxes * (100 - COALESCE(damage_percentage, 0)) / 100) as total_net_boxes,
                        SUM(total_boxes * (100 - COALESCE(damage_percentage, 0)) / 100 * COALESCE(cost_per_box, 0)) as total_cost
                    FROM purchase_entries_tiles
                    GROUP BY tile_id
                ) pet ON t.id = pet.tile_id
                
                -- Sales
                LEFT JOIN (
                    SELECT 
                        tile_id,
                        SUM(boxes_decimal) as total_boxes_sold
                    FROM invoice_items
                    GROUP BY tile_id
                ) s ON t.id = s.tile_id
                
                -- Returns  
                LEFT JOIN (
                    SELECT 
                        tile_id,
                        SUM(quantity) as total_boxes_returned
                    FROM individual_returns
                    GROUP BY tile_id
                ) r ON t.id = r.tile_id
            ");
        } catch (Exception $e) {
            // View creation failed - continue without it
            error_log("Failed to create inventory view: " . $e->getMessage());
        }
    }
    
    /**
     * Check if table exists
     */
    private static function tableExists(PDO $pdo, string $table_name): bool {
        $stmt = $pdo->prepare("SELECT name FROM sqlite_master WHERE type='table' AND name = ?");
        $stmt->execute([$table_name]);
        return (bool)$stmt->fetchColumn();
    }
    
    /**
     * Rebuild inventory from scratch (reconciliation)
     */
    public static function reconcileInventory(PDO $pdo) {
        self::createInventoryViews($pdo);
        
        // Clear and rebuild transactions if needed
        if (self::tableExists($pdo, 'inventory_transactions')) {
            $pdo->exec("DELETE FROM inventory_transactions");
            
            // Rebuild from purchases
            $pdo->exec("
                INSERT INTO inventory_transactions (tile_id, quantity_change, transaction_type, reference_id, transaction_date, created_by)
                SELECT tile_id, (boxes_in - COALESCE(damage_boxes, 0)), 'purchase', id, purchase_dt, 1
                FROM inventory_items
            ");
            
            // Rebuild from sales
            $pdo->exec("
                INSERT INTO inventory_transactions (tile_id, quantity_change, transaction_type, reference_id, transaction_date, created_by)
                SELECT ii.tile_id, -ii.boxes_decimal, 'sale', i.id, i.invoice_dt, 1
                FROM invoice_items ii
                JOIN invoices i ON ii.invoice_id = i.id
            ");
            
            // Rebuild from returns
            $pdo->exec("
                INSERT INTO inventory_transactions (tile_id, quantity_change, transaction_type, reference_id, transaction_date, created_by)
                SELECT tile_id, quantity, 'return', id, return_date, 1
                FROM individual_returns
            ");
        }
        
        return true;
    }
}
?>