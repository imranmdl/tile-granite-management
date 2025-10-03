-- 0018_cost_tracking.sql (SQLite)
-- Add cost tracking for profit calculations

-- Add cost fields to tiles table if not exists
ALTER TABLE tiles ADD COLUMN current_cost REAL DEFAULT 0;
ALTER TABLE tiles ADD COLUMN last_cost REAL DEFAULT 0;
ALTER TABLE tiles ADD COLUMN average_cost REAL DEFAULT 0;

-- Add cost fields to misc_items table if not exists  
ALTER TABLE misc_items ADD COLUMN current_cost REAL DEFAULT 0;
ALTER TABLE misc_items ADD COLUMN last_cost REAL DEFAULT 0;
ALTER TABLE misc_items ADD COLUMN average_cost REAL DEFAULT 0;

-- Cost history table for tracking cost changes
CREATE TABLE IF NOT EXISTS cost_history (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    item_type TEXT NOT NULL CHECK (item_type IN ('tile', 'misc')),
    item_id INTEGER NOT NULL,
    old_cost REAL DEFAULT 0,
    new_cost REAL NOT NULL,
    purchase_entry_id INTEGER NULL,
    reason TEXT DEFAULT 'purchase_update',
    created_at TEXT DEFAULT (datetime('now')),
    created_by INTEGER NULL,
    FOREIGN KEY (created_by) REFERENCES users_simple(id)
);

CREATE INDEX IF NOT EXISTS idx_cost_history_item ON cost_history(item_type, item_id);
CREATE INDEX IF NOT EXISTS idx_cost_history_date ON cost_history(created_at);

-- Update current costs from existing purchase entries
-- For tiles - get latest cost from purchase_entries_tiles
UPDATE tiles SET current_cost = (
    SELECT COALESCE(
        (as_of_cost_per_box - (as_of_cost_per_box * damage_pct / 100) - 
         (as_of_cost_per_box * transport_pct / 100)), 0
    )
    FROM purchase_entries_tiles pet 
    WHERE pet.tile_id = tiles.id 
    ORDER BY pet.created_at DESC 
    LIMIT 1
) WHERE id IN (SELECT DISTINCT tile_id FROM purchase_entries_tiles);

-- For misc items - get latest cost from purchase_entries_misc
UPDATE misc_items SET current_cost = (
    SELECT COALESCE(
        (as_of_cost_per_unit - (as_of_cost_per_unit * damage_pct / 100) - 
         (as_of_cost_per_unit * transport_pct / 100)), 0
    )
    FROM purchase_entries_misc pem 
    WHERE pem.misc_item_id = misc_items.id 
    ORDER BY pem.created_at DESC 
    LIMIT 1
) WHERE id IN (SELECT DISTINCT misc_item_id FROM purchase_entries_misc);