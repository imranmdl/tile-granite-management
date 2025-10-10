<?php
// includes/profit_calculations.php - Comprehensive Profit/Loss Calculation System

class ProfitCalculations {
    
    /**
     * Calculate quotation profit/loss
     * Formula: Cost of item + Transport + Commission + Other expense - Final total = Profit
     */
    public static function calculateQuotationProfit(PDO $pdo, int $quotation_id): array {
        // Get quotation data
        $quote_stmt = $pdo->prepare("
            SELECT q.*, 
                   COALESCE(q.final_total, q.total) as final_amount,
                   COALESCE(q.commission_percentage, 0) as commission_pct,
                   COALESCE(q.transport_cost, 0) as transport_cost,
                   COALESCE(q.other_expenses, 0) as other_expenses
            FROM quotations q 
            WHERE q.id = ?
        ");
        $quote_stmt->execute([$quotation_id]);
        $quotation = $quote_stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$quotation) {
            return ['error' => 'Quotation not found'];
        }
        
        $costs = [
            'item_cost' => 0,
            'transport_cost' => (float)$quotation['transport_cost'],
            'commission_amount' => 0,
            'other_expenses' => (float)$quotation['other_expenses'],
            'total_costs' => 0
        ];
        
        $revenue = (float)$quotation['final_amount'];
        
        // Calculate commission amount
        $costs['commission_amount'] = $revenue * ($quotation['commission_pct'] / 100);
        
        // Get item costs from quotation items
        $items_stmt = $pdo->prepare("
            SELECT 
                qi.tile_id,
                qi.boxes_decimal,
                qi.rate_per_box as selling_price,
                qi.boxes_decimal * qi.rate_per_box as item_revenue,
                t.name as tile_name,
                ts.label as size_label,
                -- Get average cost from inventory INCLUDING TRANSPORT COSTS
                COALESCE(
                    (SELECT 
                        SUM((boxes_in - COALESCE(damage_boxes, 0)) * (COALESCE(per_box_value, 0) + COALESCE(transport_cost_per_box, 0))) / 
                        NULLIF(SUM(boxes_in - COALESCE(damage_boxes, 0)), 0)
                     FROM inventory_items 
                     WHERE tile_id = qi.tile_id), 
                    -- Also check purchase_entries_tiles for transport-inclusive costs
                    (SELECT 
                        SUM(total_boxes * (100 - COALESCE(damage_percentage, 0)) / 100 * (COALESCE(cost_per_box, 0) + COALESCE(transport_cost, 0) / NULLIF(total_boxes, 0))) / 
                        NULLIF(SUM(total_boxes * (100 - COALESCE(damage_percentage, 0)) / 100), 0)
                     FROM purchase_entries_tiles 
                     WHERE tile_id = qi.tile_id), 0
                ) as avg_cost_per_box
            FROM quotation_items qi
            LEFT JOIN tiles t ON qi.tile_id = t.id
            LEFT JOIN tile_sizes ts ON t.size_id = ts.id
            WHERE qi.quotation_id = ?
        ");
        $items_stmt->execute([$quotation_id]);
        $items = $items_stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $item_details = [];
        foreach ($items as $item) {
            $item_cost = (float)$item['avg_cost_per_box'] * (float)$item['boxes_decimal'];
            $item_revenue = (float)$item['item_revenue'];
            $item_profit = $item_revenue - $item_cost;
            
            $costs['item_cost'] += $item_cost;
            
            $item_details[] = [
                'tile_name' => $item['tile_name'] . ' ' . $item['size_label'],
                'quantity' => $item['boxes_decimal'],
                'cost_per_box' => $item['avg_cost_per_box'],
                'selling_price' => $item['selling_price'],
                'total_cost' => $item_cost,
                'total_revenue' => $item_revenue,
                'profit' => $item_profit,
                'profit_percentage' => $item_revenue > 0 ? ($item_profit / $item_revenue * 100) : 0
            ];
        }
        
        // Calculate misc items cost
        $misc_items_stmt = $pdo->prepare("
            SELECT 
                qmi.misc_item_id,
                qmi.qty_units,
                qmi.rate_per_unit as selling_price,
                qmi.qty_units * qmi.rate_per_unit as item_revenue,
                m.name as item_name,
                -- Get average cost from misc inventory INCLUDING TRANSPORT COSTS
                COALESCE(
                    (SELECT AVG(COALESCE(cost_per_unit, 0) + COALESCE(transport_cost_per_unit, 0)) 
                     FROM misc_inventory_items 
                     WHERE misc_item_id = qmi.misc_item_id), 
                    -- Also check purchase_entries_misc for transport-inclusive costs
                    (SELECT 
                        SUM(usable_quantity * (COALESCE(cost_per_unit, 0) + COALESCE(transport_cost, 0) / NULLIF(total_quantity, 0))) / 
                        NULLIF(SUM(usable_quantity), 0)
                     FROM purchase_entries_misc 
                     WHERE misc_item_id = qmi.misc_item_id), 0
                ) as avg_cost_per_unit
            FROM quotation_misc_items qmi
            LEFT JOIN misc_items m ON qmi.misc_item_id = m.id
            WHERE qmi.quotation_id = ?
        ");
        $misc_items_stmt->execute([$quotation_id]);
        $misc_items = $misc_items_stmt->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($misc_items as $item) {
            $item_cost = (float)$item['avg_cost_per_unit'] * (float)$item['qty_units'];
            $item_revenue = (float)$item['item_revenue'];
            $item_profit = $item_revenue - $item_cost;
            
            $costs['item_cost'] += $item_cost;
            
            $item_details[] = [
                'tile_name' => $item['item_name'],
                'quantity' => $item['qty_units'],
                'cost_per_box' => $item['avg_cost_per_unit'],
                'selling_price' => $item['selling_price'],
                'total_cost' => $item_cost,
                'total_revenue' => $item_revenue,
                'profit' => $item_profit,
                'profit_percentage' => $item_revenue > 0 ? ($item_profit / $item_revenue * 100) : 0
            ];
        }
        
        // Calculate total costs and profit
        $costs['total_costs'] = $costs['item_cost'] + $costs['transport_cost'] + 
                                $costs['commission_amount'] + $costs['other_expenses'];
        
        $profit_amount = $revenue - $costs['total_costs'];
        $profit_percentage = $revenue > 0 ? ($profit_amount / $revenue * 100) : 0;
        
        return [
            'quotation_id' => $quotation_id,
            'revenue' => $revenue,
            'costs' => $costs,
            'profit_amount' => $profit_amount,
            'profit_percentage' => $profit_percentage,
            'item_details' => $item_details,
            'is_profitable' => $profit_amount > 0
        ];
    }
    
    /**
     * Calculate invoice profit/loss
     */
    public static function calculateInvoiceProfit(PDO $pdo, int $invoice_id): array {
        // Get invoice data
        $invoice_stmt = $pdo->prepare("
            SELECT i.*, 
                   COALESCE(i.final_total, i.total) as final_amount,
                   COALESCE(i.commission_percentage, 0) as commission_pct,
                   COALESCE(i.commission_amount, 0) as commission_amount,
                   COALESCE(i.transport_cost, 0) as transport_cost,
                   COALESCE(i.other_expenses, 0) as other_expenses
            FROM invoices i 
            WHERE i.id = ?
        ");
        $invoice_stmt->execute([$invoice_id]);
        $invoice = $invoice_stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$invoice) {
            return ['error' => 'Invoice not found'];
        }
        
        $costs = [
            'item_cost' => 0,
            'transport_cost' => (float)$invoice['transport_cost'],
            'commission_amount' => (float)$invoice['commission_amount'],
            'other_expenses' => (float)$invoice['other_expenses'],
            'total_costs' => 0
        ];
        
        $revenue = (float)$invoice['final_amount'];
        
        // Get tile item costs from invoice items
        $items_stmt = $pdo->prepare("
            SELECT 
                ii.tile_id,
                ii.boxes_decimal,
                ii.rate_per_box as selling_price,
                ii.boxes_decimal * ii.rate_per_box as item_revenue,
                t.name as tile_name,
                ts.label as size_label,
                -- Get weighted average cost from inventory at time of sale
                COALESCE(
                    (SELECT 
                        SUM((boxes_in - COALESCE(damage_boxes, 0)) * COALESCE(per_box_value, 0)) / 
                        NULLIF(SUM(boxes_in - COALESCE(damage_boxes, 0)), 0)
                     FROM inventory_items 
                     WHERE tile_id = ii.tile_id 
                     AND DATE(COALESCE(purchase_dt, '2020-01-01')) <= DATE(i.invoice_dt)
                    ), 0
                ) as avg_cost_per_box
            FROM invoice_items ii
            LEFT JOIN tiles t ON ii.tile_id = t.id
            LEFT JOIN tile_sizes ts ON t.size_id = ts.id
            LEFT JOIN invoices i ON ii.invoice_id = i.id
            WHERE ii.invoice_id = ?
        ");
        $items_stmt->execute([$invoice_id]);
        $items = $items_stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $item_details = [];
        foreach ($items as $item) {
            $item_cost = (float)$item['avg_cost_per_box'] * (float)$item['boxes_decimal'];
            $item_revenue = (float)$item['item_revenue'];
            $item_profit = $item_revenue - $item_cost;
            
            $costs['item_cost'] += $item_cost;
            
            $item_details[] = [
                'tile_name' => $item['tile_name'] . ' ' . $item['size_label'],
                'quantity' => $item['boxes_decimal'],
                'cost_per_box' => $item['avg_cost_per_box'],
                'selling_price' => $item['selling_price'],
                'total_cost' => $item_cost,
                'total_revenue' => $item_revenue,
                'profit' => $item_profit,
                'profit_percentage' => $item_revenue > 0 ? ($item_profit / $item_revenue * 100) : 0
            ];
        }
        
        // Get misc item costs from invoice misc items
        $misc_items_stmt = $pdo->prepare("
            SELECT 
                imi.misc_item_id,
                imi.qty_units,
                imi.rate_per_unit as selling_price,
                imi.qty_units * imi.rate_per_unit as item_revenue,
                m.name as item_name,
                -- Get weighted average cost from misc inventory
                COALESCE(
                    (SELECT 
                        SUM((qty_in - COALESCE(damage_units, 0)) * cost_per_unit) / 
                        NULLIF(SUM(qty_in - COALESCE(damage_units, 0)), 0)
                     FROM misc_inventory_items 
                     WHERE misc_item_id = imi.misc_item_id
                     AND DATE(purchase_date) <= DATE(i.invoice_dt)
                    ), 0
                ) as avg_cost_per_unit
            FROM invoice_misc_items imi
            LEFT JOIN misc_items m ON imi.misc_item_id = m.id
            LEFT JOIN invoices i ON imi.invoice_id = i.id
            WHERE imi.invoice_id = ?
        ");
        $misc_items_stmt->execute([$invoice_id]);
        $misc_items = $misc_items_stmt->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($misc_items as $item) {
            $item_cost = (float)$item['avg_cost_per_unit'] * (float)$item['qty_units'];
            $item_revenue = (float)$item['item_revenue'];
            $item_profit = $item_revenue - $item_cost;
            
            $costs['item_cost'] += $item_cost;
            
            $item_details[] = [
                'tile_name' => $item['item_name'],
                'quantity' => $item['qty_units'],
                'cost_per_box' => $item['avg_cost_per_unit'],
                'selling_price' => $item['selling_price'],
                'total_cost' => $item_cost,
                'total_revenue' => $item_revenue,
                'profit' => $item_profit,
                'profit_percentage' => $item_revenue > 0 ? ($item_profit / $item_revenue * 100) : 0
            ];
        }
        
        // Calculate total costs and profit
        $costs['total_costs'] = $costs['item_cost'] + $costs['transport_cost'] + 
                                $costs['commission_amount'] + $costs['other_expenses'];
        
        $profit_amount = $revenue - $costs['total_costs'];
        $profit_percentage = $revenue > 0 ? ($profit_amount / $revenue * 100) : 0;
        
        return [
            'invoice_id' => $invoice_id,
            'invoice_no' => $invoice['invoice_no'],
            'customer_name' => $invoice['customer_name'],
            'invoice_date' => $invoice['invoice_dt'],
            'revenue' => $revenue,
            'costs' => $costs,
            'profit_amount' => $profit_amount,
            'profit_percentage' => $profit_percentage,
            'item_details' => $item_details,
            'is_profitable' => $profit_amount > 0
        ];
    }
    
    /**
     * Get item-wise profit analysis
     */
    public static function getItemWiseProfitAnalysis(PDO $pdo, string $date_from, string $date_to, array $filters = []): array {
        $tile_profit_sql = "
            SELECT 
                t.name as item_name,
                ts.label as size_label,
                COUNT(DISTINCT ii.invoice_id) as sale_count,
                SUM(ii.boxes_decimal) as total_quantity_sold,
                SUM(ii.boxes_decimal * ii.rate_per_box) as total_revenue,
                AVG(ii.rate_per_box) as avg_selling_price,
                -- Calculate weighted average cost
                COALESCE(
                    (SELECT 
                        SUM((inv.boxes_in - COALESCE(inv.damage_boxes, 0)) * COALESCE(inv.per_box_value, 0)) / 
                        NULLIF(SUM(inv.boxes_in - COALESCE(inv.damage_boxes, 0)), 0)
                     FROM inventory_items inv 
                     WHERE inv.tile_id = ii.tile_id
                    ), 0
                ) as avg_cost_per_box,
                'tile' as item_type
            FROM invoice_items ii
            JOIN invoices i ON ii.invoice_id = i.id
            JOIN tiles t ON ii.tile_id = t.id
            JOIN tile_sizes ts ON t.size_id = ts.id
            WHERE DATE(i.invoice_dt) BETWEEN ? AND ?
            AND i.status != 'CANCELLED'
            GROUP BY ii.tile_id, t.name, ts.label
            
            UNION ALL
            
            SELECT 
                m.name as item_name,
                m.unit_label as size_label,
                COUNT(DISTINCT imi.invoice_id) as sale_count,
                SUM(imi.qty_units) as total_quantity_sold,
                SUM(imi.qty_units * imi.rate_per_unit) as total_revenue,
                AVG(imi.rate_per_unit) as avg_selling_price,
                -- Calculate weighted average cost
                COALESCE(
                    (SELECT 
                        SUM((inv.qty_in - COALESCE(inv.damage_units, 0)) * inv.cost_per_unit) / 
                        NULLIF(SUM(inv.qty_in - COALESCE(inv.damage_units, 0)), 0)
                     FROM misc_inventory_items inv 
                     WHERE inv.misc_item_id = imi.misc_item_id
                    ), 0
                ) as avg_cost_per_box,
                'misc' as item_type
            FROM invoice_misc_items imi
            JOIN invoices i ON imi.invoice_id = i.id
            JOIN misc_items m ON imi.misc_item_id = m.id
            WHERE DATE(i.invoice_dt) BETWEEN ? AND ?
            AND i.status != 'CANCELLED'
            GROUP BY imi.misc_item_id, m.name, m.unit_label
            
            ORDER BY total_revenue DESC
        ";
        
        $params = [$date_from, $date_to, $date_from, $date_to];
        
        $stmt = $pdo->prepare($tile_profit_sql);
        $stmt->execute($params);
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $profit_analysis = [];
        $total_profit = 0;
        $total_revenue = 0;
        
        foreach ($results as $item) {
            $total_cost = (float)$item['avg_cost_per_box'] * (float)$item['total_quantity_sold'];
            $revenue = (float)$item['total_revenue'];
            $profit = $revenue - $total_cost;
            $profit_percentage = $revenue > 0 ? ($profit / $revenue * 100) : 0;
            
            $total_profit += $profit;
            $total_revenue += $revenue;
            
            $profit_analysis[] = [
                'item_name' => $item['item_name'],
                'size_label' => $item['size_label'],
                'item_type' => $item['item_type'],
                'sale_count' => (int)$item['sale_count'],
                'total_quantity_sold' => (float)$item['total_quantity_sold'],
                'total_revenue' => $revenue,
                'avg_selling_price' => (float)$item['avg_selling_price'],
                'avg_cost_per_unit' => (float)$item['avg_cost_per_box'],
                'total_cost' => $total_cost,
                'profit_amount' => $profit,
                'profit_percentage' => $profit_percentage,
                'is_profitable' => $profit > 0
            ];
        }
        
        // Sort by profit percentage
        usort($profit_analysis, function($a, $b) {
            return $b['profit_percentage'] <=> $a['profit_percentage'];
        });
        
        return [
            'item_analysis' => $profit_analysis,
            'summary' => [
                'total_items' => count($profit_analysis),
                'total_revenue' => $total_revenue,
                'total_profit' => $total_profit,
                'overall_profit_percentage' => $total_revenue > 0 ? ($total_profit / $total_revenue * 100) : 0,
                'profitable_items' => count(array_filter($profit_analysis, fn($item) => $item['is_profitable'])),
                'loss_making_items' => count(array_filter($profit_analysis, fn($item) => !$item['is_profitable']))
            ]
        ];
    }
    
    /**
     * Get daily/weekly/monthly profit trends
     */
    public static function getProfitTrends(PDO $pdo, string $date_from, string $date_to, string $period = 'daily'): array {
        $date_format = match($period) {
            'weekly' => '%Y-%W',
            'monthly' => '%Y-%m',
            default => '%Y-%m-%d'
        };
        
        $trends_sql = "
            SELECT 
                strftime('$date_format', i.invoice_dt) as period,
                DATE(i.invoice_dt) as period_date,
                COUNT(*) as invoice_count,
                SUM(i.final_total) as revenue,
                SUM(COALESCE(i.commission_amount, 0) + COALESCE(i.transport_cost, 0) + COALESCE(i.other_expenses, 0)) as known_costs
            FROM invoices i
            WHERE DATE(i.invoice_dt) BETWEEN ? AND ?
            AND i.status != 'CANCELLED'
            GROUP BY strftime('$date_format', i.invoice_dt)
            ORDER BY period_date
        ";
        
        $stmt = $pdo->prepare($trends_sql);
        $stmt->execute([$date_from, $date_to]);
        $trends = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        return $trends;
    }
}
?>