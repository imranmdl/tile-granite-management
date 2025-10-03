<?php
// includes/inventory_calculations.php - Comprehensive inventory calculation helpers

/**
 * Calculate accurate transport and total costs for inventory items
 */
class InventoryCalculations {
    
    /**
     * Calculate comprehensive cost breakdown for an inventory item
     */
    public static function calculateItemCosts(array $item, float $sqft_per_box = 1.0): array {
        // Input values
        $boxes_in = max(0, (float)($item['boxes_in'] ?? 0));
        $damage_boxes = max(0, (float)($item['damage_boxes'] ?? 0));
        $per_box_value = max(0, (float)($item['per_box_value'] ?? 0));
        $per_sqft_value = max(0, (float)($item['per_sqft_value'] ?? 0));
        $transport_pct = max(0, (float)($item['transport_pct'] ?? 0));
        $transport_per_box = max(0, (float)($item['transport_per_box'] ?? 0));
        $transport_total = max(0, (float)($item['transport_total'] ?? 0));
        
        // Validate damage boxes
        $damage_boxes = min($damage_boxes, $boxes_in);
        $net_boxes = max(0, $boxes_in - $damage_boxes);
        
        // Calculate base cost per box
        $base_cost_per_box = 0;
        if ($per_box_value > 0) {
            $base_cost_per_box = $per_box_value;
        } elseif ($per_sqft_value > 0 && $sqft_per_box > 0) {
            $base_cost_per_box = $per_sqft_value * $sqft_per_box;
        }
        
        // Calculate transport costs
        $transport_from_percent = $base_cost_per_box * ($transport_pct / 100.0);
        $transport_allocated_per_box = 0;
        if ($transport_total > 0 && $net_boxes > 0) {
            $transport_allocated_per_box = $transport_total / $net_boxes;
        }
        
        $total_transport_per_box = $transport_from_percent + $transport_per_box + $transport_allocated_per_box;
        $final_cost_per_box = $base_cost_per_box + $total_transport_per_box;
        $final_cost_per_sqft = ($sqft_per_box > 0) ? ($final_cost_per_box / $sqft_per_box) : 0;
        
        // Calculate totals
        $total_base_value = $net_boxes * $base_cost_per_box;
        $total_transport_value = $net_boxes * $total_transport_per_box;
        $total_final_value = $net_boxes * $final_cost_per_box;
        
        // Calculate damage costs
        $damage_cost = $damage_boxes * $final_cost_per_box;
        
        return [
            // Input values (validated)
            'boxes_in' => round($boxes_in, 3),
            'damage_boxes' => round($damage_boxes, 3),
            'net_boxes' => round($net_boxes, 3),
            'per_box_value' => round($per_box_value, 2),
            'per_sqft_value' => round($per_sqft_value, 2),
            'transport_pct' => round($transport_pct, 2),
            'transport_per_box' => round($transport_per_box, 2),
            'transport_total' => round($transport_total, 2),
            
            // Calculated per-unit costs
            'base_cost_per_box' => round($base_cost_per_box, 2),
            'transport_from_percent' => round($transport_from_percent, 2),
            'transport_allocated_per_box' => round($transport_allocated_per_box, 2),
            'total_transport_per_box' => round($total_transport_per_box, 2),
            'final_cost_per_box' => round($final_cost_per_box, 2),
            'final_cost_per_sqft' => round($final_cost_per_sqft, 4),
            
            // Total values
            'total_base_value' => round($total_base_value, 2),
            'total_transport_value' => round($total_transport_value, 2),
            'total_final_value' => round($total_final_value, 2),
            'damage_cost' => round($damage_cost, 2),
            
            // Percentages and ratios
            'damage_percentage' => $boxes_in > 0 ? round(($damage_boxes / $boxes_in) * 100, 2) : 0,
            'transport_percentage_of_base' => $base_cost_per_box > 0 ? round(($total_transport_per_box / $base_cost_per_box) * 100, 2) : 0,
            'profit_margin_needed' => round($final_cost_per_box * 0.2, 2), // Suggested 20% margin
        ];
    }
    
    /**
     * Get inventory summary for a tile
     */
    public static function getTileInventorySummary(PDO $pdo, int $tile_id): array {
        // Get tile info
        $tile_stmt = $pdo->prepare("
            SELECT t.name, ts.label AS size_label, ts.sqft_per_box
            FROM tiles t
            JOIN tile_sizes ts ON ts.id = t.size_id
            WHERE t.id = ?
        ");
        $tile_stmt->execute([$tile_id]);
        $tile_info = $tile_stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$tile_info) {
            return ['error' => 'Tile not found'];
        }
        
        $sqft_per_box = (float)$tile_info['sqft_per_box'];
        
        // Get all inventory items for this tile
        $inventory_stmt = $pdo->prepare("
            SELECT * FROM inventory_items 
            WHERE tile_id = ? 
            ORDER BY purchase_dt DESC, id DESC
        ");
        $inventory_stmt->execute([$tile_id]);
        $inventory_items = $inventory_stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Calculate totals
        $summary = [
            'tile_id' => $tile_id,
            'tile_name' => $tile_info['name'],
            'size_label' => $tile_info['size_label'],
            'sqft_per_box' => $sqft_per_box,
            'total_receipts' => 0,
            'total_boxes_received' => 0,
            'total_damage_boxes' => 0,
            'total_net_boxes' => 0,
            'total_investment' => 0,
            'weighted_avg_cost_per_box' => 0,
            'total_sqft_received' => 0,
            'items' => []
        ];
        
        $total_cost_weighted = 0;
        $total_net_boxes = 0;
        
        foreach ($inventory_items as $item) {
            $calc = self::calculateItemCosts($item, $sqft_per_box);
            
            $summary['total_receipts']++;
            $summary['total_boxes_received'] += $calc['boxes_in'];
            $summary['total_damage_boxes'] += $calc['damage_boxes'];
            $summary['total_net_boxes'] += $calc['net_boxes'];
            $summary['total_investment'] += $calc['total_final_value'];
            $summary['total_sqft_received'] += $calc['boxes_in'] * $sqft_per_box;
            
            // For weighted average
            $total_cost_weighted += $calc['final_cost_per_box'] * $calc['net_boxes'];
            $total_net_boxes += $calc['net_boxes'];
            
            $calc['item_id'] = $item['id'];
            $calc['purchase_dt'] = $item['purchase_dt'] ?? '';
            $calc['vendor'] = $item['vendor'] ?? '';
            $calc['notes'] = $item['notes'] ?? '';
            
            $summary['items'][] = $calc;
        }
        
        // Calculate weighted average cost
        if ($total_net_boxes > 0) {
            $summary['weighted_avg_cost_per_box'] = round($total_cost_weighted / $total_net_boxes, 2);
        }
        
        // Get current availability (considering sales and returns)
        $availability = self::getCurrentAvailability($pdo, $tile_id);
        $summary = array_merge($summary, $availability);
        
        return $summary;
    }
    
    /**
     * Get current availability for a tile
     */
    public static function getCurrentAvailability(PDO $pdo, int $tile_id): array {
        // Total good boxes received
        $received_stmt = $pdo->prepare("
            SELECT COALESCE(SUM(boxes_in - COALESCE(damage_boxes, 0)), 0) 
            FROM inventory_items 
            WHERE tile_id = ?
        ");
        $received_stmt->execute([$tile_id]);
        $total_received = (float)$received_stmt->fetchColumn();
        
        // Total boxes sold
        $sold_stmt = $pdo->prepare("
            SELECT COALESCE(SUM(boxes_decimal), 0) 
            FROM invoice_items 
            WHERE tile_id = ?
        ");
        $sold_stmt->execute([$tile_id]);
        $total_sold = (float)$sold_stmt->fetchColumn();
        
        // Total boxes returned
        $returned_stmt = $pdo->prepare("
            SELECT COALESCE(SUM(boxes_decimal), 0) 
            FROM invoice_return_items 
            WHERE tile_id = ?
        ");
        $returned_stmt->execute([$tile_id]);
        $total_returned = (float)$returned_stmt->fetchColumn();
        
        $current_availability = max(0, $total_received - $total_sold + $total_returned);
        
        return [
            'total_received_net' => round($total_received, 3),
            'total_sold' => round($total_sold, 3),
            'total_returned' => round($total_returned, 3),
            'current_availability' => round($current_availability, 3),
            'turnover_rate' => $total_received > 0 ? round(($total_sold / $total_received) * 100, 2) : 0,
        ];
    }
    
    /**
     * Validate inventory item data
     */
    public static function validateInventoryData(array $data): array {
        $errors = [];
        
        // Required fields
        if (!isset($data['tile_id']) || (int)$data['tile_id'] <= 0) {
            $errors[] = "Valid tile ID is required";
        }
        
        // Boxes validation
        $boxes_in = (float)($data['boxes_in'] ?? 0);
        $damage_boxes = (float)($data['damage_boxes'] ?? 0);
        
        if ($boxes_in < 0) {
            $errors[] = "Boxes in cannot be negative";
        }
        
        if ($damage_boxes < 0) {
            $errors[] = "Damage boxes cannot be negative";
        }
        
        if ($damage_boxes > $boxes_in) {
            $errors[] = "Damage boxes cannot exceed total boxes";
        }
        
        // Cost validation
        $per_box_value = (float)($data['per_box_value'] ?? 0);
        $per_sqft_value = (float)($data['per_sqft_value'] ?? 0);
        
        if ($per_box_value < 0 || $per_sqft_value < 0) {
            $errors[] = "Cost values cannot be negative";
        }
        
        if ($per_box_value == 0 && $per_sqft_value == 0) {
            $errors[] = "Either per box value or per sqft value must be provided";
        }
        
        // Transport validation
        $transport_pct = (float)($data['transport_pct'] ?? 0);
        $transport_per_box = (float)($data['transport_per_box'] ?? 0);
        $transport_total = (float)($data['transport_total'] ?? 0);
        
        if ($transport_pct < 0 || $transport_per_box < 0 || $transport_total < 0) {
            $errors[] = "Transport costs cannot be negative";
        }
        
        if ($transport_pct > 100) {
            $errors[] = "Transport percentage cannot exceed 100%";
        }
        
        return [
            'valid' => empty($errors),
            'errors' => $errors,
            'warnings' => self::getValidationWarnings($data)
        ];
    }
    
    /**
     * Get validation warnings (non-critical issues)
     */
    private static function getValidationWarnings(array $data): array {
        $warnings = [];
        
        $damage_boxes = (float)($data['damage_boxes'] ?? 0);
        $boxes_in = (float)($data['boxes_in'] ?? 0);
        $transport_pct = (float)($data['transport_pct'] ?? 0);
        
        // High damage percentage
        if ($boxes_in > 0 && ($damage_boxes / $boxes_in) > 0.1) {
            $warnings[] = "Damage percentage is high (" . round(($damage_boxes / $boxes_in) * 100, 1) . "%)";
        }
        
        // High transport percentage
        if ($transport_pct > 20) {
            $warnings[] = "Transport percentage is high (" . $transport_pct . "%)";
        }
        
        // Missing vendor
        if (empty($data['vendor'])) {
            $warnings[] = "Vendor information is missing";
        }
        
        // Future purchase date
        if (!empty($data['purchase_dt']) && $data['purchase_dt'] > date('Y-m-d')) {
            $warnings[] = "Purchase date is in the future";
        }
        
        return $warnings;
    }
    
    /**
     * Generate inventory report data
     */
    public static function generateInventoryReport(PDO $pdo, array $filters = []): array {
        $where_conditions = [];
        $params = [];
        
        // Apply filters
        if (!empty($filters['tile_id'])) {
            $where_conditions[] = "ii.tile_id = ?";
            $params[] = $filters['tile_id'];
        }
        
        if (!empty($filters['vendor'])) {
            $where_conditions[] = "ii.vendor LIKE ?";
            $params[] = "%" . $filters['vendor'] . "%";
        }
        
        if (!empty($filters['date_from'])) {
            $where_conditions[] = "ii.purchase_dt >= ?";
            $params[] = $filters['date_from'];
        }
        
        if (!empty($filters['date_to'])) {
            $where_conditions[] = "ii.purchase_dt <= ?";
            $params[] = $filters['date_to'];
        }
        
        $where_clause = empty($where_conditions) ? '' : 'WHERE ' . implode(' AND ', $where_conditions);
        
        // Get inventory data
        $sql = "
            SELECT ii.*, t.name AS tile_name, ts.label AS size_label, ts.sqft_per_box
            FROM inventory_items ii
            JOIN tiles t ON t.id = ii.tile_id
            JOIN tile_sizes ts ON ts.id = t.size_id
            $where_clause
            ORDER BY ii.purchase_dt DESC, ii.id DESC
        ";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $report = [
            'total_items' => count($items),
            'total_investment' => 0,
            'total_boxes' => 0,
            'total_damage' => 0,
            'total_sqft' => 0,
            'by_tile' => [],
            'by_vendor' => [],
            'items' => []
        ];
        
        foreach ($items as $item) {
            $sqft_per_box = (float)$item['sqft_per_box'];
            $calc = self::calculateItemCosts($item, $sqft_per_box);
            
            // Add to totals
            $report['total_investment'] += $calc['total_final_value'];
            $report['total_boxes'] += $calc['boxes_in'];
            $report['total_damage'] += $calc['damage_boxes'];
            $report['total_sqft'] += $calc['boxes_in'] * $sqft_per_box;
            
            // Group by tile
            $tile_key = $item['tile_id'];
            if (!isset($report['by_tile'][$tile_key])) {
                $report['by_tile'][$tile_key] = [
                    'tile_name' => $item['tile_name'],
                    'size_label' => $item['size_label'],
                    'total_value' => 0,
                    'total_boxes' => 0,
                    'items_count' => 0
                ];
            }
            $report['by_tile'][$tile_key]['total_value'] += $calc['total_final_value'];
            $report['by_tile'][$tile_key]['total_boxes'] += $calc['net_boxes'];
            $report['by_tile'][$tile_key]['items_count']++;
            
            // Group by vendor
            $vendor = $item['vendor'] ?? 'Unknown';
            if (!isset($report['by_vendor'][$vendor])) {
                $report['by_vendor'][$vendor] = [
                    'total_value' => 0,
                    'total_boxes' => 0,
                    'items_count' => 0
                ];
            }
            $report['by_vendor'][$vendor]['total_value'] += $calc['total_final_value'];
            $report['by_vendor'][$vendor]['total_boxes'] += $calc['net_boxes'];
            $report['by_vendor'][$vendor]['items_count']++;
            
            $calc['tile_name'] = $item['tile_name'];
            $calc['size_label'] = $item['size_label'];
            $calc['vendor'] = $item['vendor'] ?? '';
            $calc['purchase_dt'] = $item['purchase_dt'] ?? '';
            $calc['item_id'] = $item['id'];
            
            $report['items'][] = $calc;
        }
        
        return $report;
    }
}
?>