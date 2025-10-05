-- 0004_commission.sql (SQLite)
-- Add salesperson tracking and commission settings
ALTER TABLE invoices ADD COLUMN sales_user TEXT;
ALTER TABLE invoices ADD COLUMN commission_percent REAL NULL;

CREATE TABLE IF NOT EXISTS commission_config (
  id INTEGER PRIMARY KEY CHECK (id=1),
  default_percent REAL NOT NULL DEFAULT 0,
  strategy TEXT NOT NULL DEFAULT 'COST'  -- COST (commission on cost), or PROFIT (not used now)
);
INSERT OR IGNORE INTO commission_config(id, default_percent, strategy) VALUES (1, 2.0, 'COST');

CREATE TABLE IF NOT EXISTS commission_tiers (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  min_margin_pct REAL NOT NULL,
  percent REAL NOT NULL
);
