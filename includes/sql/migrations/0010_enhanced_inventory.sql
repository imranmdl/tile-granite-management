-- 0010_enhanced_inventory.sql (SQLite)
-- Enhanced inventory features for tiles and other items

-- Add photo support to tiles table (if not exists)
-- ALTER TABLE tiles ADD COLUMN photo_path TEXT NULL; -- Already exists
-- ALTER TABLE tiles ADD COLUMN photo_size INTEGER DEFAULT 0;

-- Add photo support to misc_items table (check if exists)  
-- ALTER TABLE misc_items ADD COLUMN photo_path TEXT NULL;
-- ALTER TABLE misc_items ADD COLUMN photo_size INTEGER DEFAULT 0;

-- Add QR code support (check if exists)
-- ALTER TABLE tiles ADD COLUMN qr_code_path TEXT NULL;
-- ALTER TABLE misc_items ADD COLUMN qr_code_path TEXT NULL;

-- Enhanced purchase entries tracking
CREATE TABLE IF NOT EXISTS purchase_entries_tiles (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    tile_id INTEGER NOT NULL,
    purchase_date TEXT NOT NULL,
    supplier_name TEXT,
    invoice_number TEXT,
    total_boxes REAL NOT NULL DEFAULT 0,
    damage_percentage REAL NOT NULL DEFAULT 0,
    usable_boxes REAL GENERATED ALWAYS AS (total_boxes * (1 - damage_percentage/100)) STORED,
    cost_per_box REAL NOT NULL DEFAULT 0,
    total_cost REAL GENERATED ALWAYS AS (total_boxes * cost_per_box) STORED,
    transport_cost REAL NOT NULL DEFAULT 0,
    final_cost REAL GENERATED ALWAYS AS (total_cost + transport_cost) STORED,
    notes TEXT,
    created_at TEXT DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY(tile_id) REFERENCES tiles(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS purchase_entries_misc (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    misc_item_id INTEGER NOT NULL,
    purchase_date TEXT NOT NULL,
    supplier_name TEXT,
    invoice_number TEXT,
    total_quantity REAL NOT NULL DEFAULT 0,
    damage_percentage REAL NOT NULL DEFAULT 0,
    usable_quantity REAL GENERATED ALWAYS AS (total_quantity * (1 - damage_percentage/100)) STORED,
    cost_per_unit REAL NOT NULL DEFAULT 0,
    total_cost REAL GENERATED ALWAYS AS (total_quantity * cost_per_unit) STORED,
    transport_cost REAL NOT NULL DEFAULT 0,
    final_cost REAL GENERATED ALWAYS AS (total_cost + transport_cost) STORED,
    notes TEXT,
    created_at TEXT DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY(misc_item_id) REFERENCES misc_items(id) ON DELETE CASCADE
);

-- Current stock summary views (calculated from purchase entries)
CREATE VIEW IF NOT EXISTS current_tiles_stock AS
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
    COALESCE(SUM(pe.usable_boxes), 0) as total_stock_boxes,
    COALESCE(SUM(pe.usable_boxes * ts.sqft_per_box), 0) as total_stock_sqft,
    COALESCE(AVG(pe.cost_per_box), 0) as avg_cost_per_box,
    COALESCE(MIN(pe.cost_per_box), 0) as min_cost_per_box,
    COALESCE(MAX(pe.cost_per_box), 0) as max_cost_per_box,
    COUNT(pe.id) as purchase_count
FROM tiles t
LEFT JOIN tile_sizes ts ON t.size_id = ts.id
LEFT JOIN vendors v ON t.vendor_id = v.id
LEFT JOIN purchase_entries_tiles pe ON t.id = pe.tile_id
GROUP BY t.id, t.name, t.size_id, ts.label, ts.sqft_per_box, t.vendor_id, v.name, t.photo_path, t.qr_code_path;

CREATE VIEW IF NOT EXISTS current_misc_stock AS
SELECT 
    m.id,
    m.name,
    m.unit_label,
    m.photo_path,
    m.qr_code_path,
    COALESCE(SUM(pe.usable_quantity), 0) as total_stock_quantity,
    COALESCE(AVG(pe.cost_per_unit), 0) as avg_cost_per_unit,
    COALESCE(MIN(pe.cost_per_unit), 0) as min_cost_per_unit,
    COALESCE(MAX(pe.cost_per_unit), 0) as max_cost_per_unit,
    COUNT(pe.id) as purchase_count
FROM misc_items m
LEFT JOIN purchase_entries_misc pe ON m.id = pe.misc_item_id
GROUP BY m.id, m.name, m.unit_label, m.photo_path, m.qr_code_path;

-- Indexes for better performance
CREATE INDEX IF NOT EXISTS idx_purchase_entries_tiles_tile_id ON purchase_entries_tiles(tile_id);
CREATE INDEX IF NOT EXISTS idx_purchase_entries_tiles_date ON purchase_entries_tiles(purchase_date);
CREATE INDEX IF NOT EXISTS idx_purchase_entries_misc_item_id ON purchase_entries_misc(misc_item_id);
CREATE INDEX IF NOT EXISTS idx_purchase_entries_misc_date ON purchase_entries_misc(purchase_date);