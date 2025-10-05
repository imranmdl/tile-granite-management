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

-- Note: Cost updates will be handled programmatically after determining correct column names