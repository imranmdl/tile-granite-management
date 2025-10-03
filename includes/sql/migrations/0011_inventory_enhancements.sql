-- 0011_inventory_enhancements.sql (SQLite)
-- Enhanced inventory with transport percentage and sales tracking

-- Add transport percentage column to purchase entries
ALTER TABLE purchase_entries_tiles ADD COLUMN transport_percentage REAL DEFAULT 0;
ALTER TABLE purchase_entries_misc ADD COLUMN transport_percentage REAL DEFAULT 0;

-- Update views to include enhanced calculations and sales data
DROP VIEW IF EXISTS current_tiles_stock;
CREATE VIEW current_tiles_stock AS
SELECT 
    t.id,
    t.name,
    t.size_id,
    ts.label as size_label,
    ts.sqft_per_box,
    t.vendor_id,
    v.name as vendor_name,
    t.photo_path,
    t.qr_code_path,
    -- Stock calculations
    COALESCE(SUM(pe.usable_boxes), 0) as total_stock_boxes,
    COALESCE(SUM(pe.usable_boxes * ts.sqft_per_box), 0) as total_stock_sqft,
    -- Cost calculations with transport
    COALESCE(AVG(pe.cost_per_box), 0) as avg_cost_per_box,
    COALESCE(AVG(CASE 
        WHEN pe.transport_percentage > 0 THEN pe.cost_per_box * (1 + pe.transport_percentage/100)
        ELSE pe.cost_per_box + (pe.transport_cost / pe.total_boxes)
    END), 0) as avg_cost_per_box_with_transport,
    COALESCE(SUM(pe.total_boxes * CASE 
        WHEN pe.transport_percentage > 0 THEN pe.cost_per_box * (1 + pe.transport_percentage/100)
        ELSE pe.cost_per_box + (pe.transport_cost / pe.total_boxes)
    END), 0) as total_boxes_cost,
    -- Sales data from quotations and invoices
    COALESCE((
        SELECT SUM(qi.boxes_decimal) 
        FROM quotation_items qi 
        JOIN quotations q ON qi.quotation_id = q.id 
        WHERE qi.tile_id = t.id
    ), 0) as total_sold_boxes_quotes,
    COALESCE((
        SELECT SUM(qi.line_total) 
        FROM quotation_items qi 
        JOIN quotations q ON qi.quotation_id = q.id 
        WHERE qi.tile_id = t.id
    ), 0) as total_sold_cost_quotes,
    -- Purchase entry count
    COUNT(pe.id) as purchase_count
FROM tiles t
LEFT JOIN tile_sizes ts ON t.size_id = ts.id
LEFT JOIN vendors v ON t.vendor_id = v.id
LEFT JOIN purchase_entries_tiles pe ON t.id = pe.tile_id
GROUP BY t.id, t.name, t.size_id, ts.label, ts.sqft_per_box, t.vendor_id, v.name, t.photo_path, t.qr_code_path;

DROP VIEW IF EXISTS current_misc_stock;
CREATE VIEW current_misc_stock AS
SELECT 
    m.id,
    m.name,
    m.unit_label,
    m.photo_path,
    m.qr_code_path,
    -- Stock calculations
    COALESCE(SUM(pe.usable_quantity), 0) as total_stock_quantity,
    -- Cost calculations with transport
    COALESCE(AVG(pe.cost_per_unit), 0) as avg_cost_per_unit,
    COALESCE(AVG(CASE 
        WHEN pe.transport_percentage > 0 THEN pe.cost_per_unit * (1 + pe.transport_percentage/100)
        ELSE pe.cost_per_unit + (pe.transport_cost / pe.total_quantity)
    END), 0) as avg_cost_per_unit_with_transport,
    COALESCE(SUM(pe.total_quantity * CASE 
        WHEN pe.transport_percentage > 0 THEN pe.cost_per_unit * (1 + pe.transport_percentage/100)
        ELSE pe.cost_per_unit + (pe.transport_cost / pe.total_quantity)
    END), 0) as total_quantity_cost,
    -- Sales data from quotations
    COALESCE((
        SELECT SUM(qmi.qty_units) 
        FROM quotation_misc_items qmi 
        JOIN quotations q ON qmi.quotation_id = q.id 
        WHERE qmi.misc_item_id = m.id
    ), 0) as total_sold_quantity_quotes,
    COALESCE((
        SELECT SUM(qmi.line_total) 
        FROM quotation_misc_items qmi 
        JOIN quotations q ON qmi.quotation_id = q.id 
        WHERE qmi.misc_item_id = m.id
    ), 0) as total_sold_cost_quotes,
    -- Purchase entry count
    COUNT(pe.id) as purchase_count
FROM misc_items m
LEFT JOIN purchase_entries_misc pe ON m.id = pe.misc_item_id
GROUP BY m.id, m.name, m.unit_label, m.photo_path, m.qr_code_path;