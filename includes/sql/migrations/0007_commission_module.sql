-- 0007_commission_module.sql
CREATE TABLE IF NOT EXISTS commission_ledger (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  invoice_id INTEGER,
  quotation_id INTEGER,
  salesperson_user_id INTEGER NOT NULL,
  base_amount REAL NOT NULL DEFAULT 0,
  pct REAL NOT NULL DEFAULT 0,
  amount REAL NOT NULL DEFAULT 0,
  status TEXT NOT NULL DEFAULT 'PENDING',
  reference TEXT,
  notes TEXT,
  created_at TEXT DEFAULT CURRENT_TIMESTAMP,
  updated_at TEXT DEFAULT CURRENT_TIMESTAMP,
  paid_on TEXT
);
CREATE TABLE IF NOT EXISTS commission_rates (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  scope TEXT NOT NULL,
  scope_id INTEGER,
  user_id INTEGER,
  pct REAL NOT NULL DEFAULT 0,
  active INTEGER NOT NULL DEFAULT 1,
  created_at TEXT DEFAULT CURRENT_TIMESTAMP
);
CREATE TABLE IF NOT EXISTS app_settings ( key TEXT PRIMARY KEY, value TEXT );
INSERT INTO commission_rates(scope, scope_id, user_id, pct, active)
SELECT 'GLOBAL', NULL, NULL, 2.0, 1
WHERE NOT EXISTS (SELECT 1 FROM commission_rates WHERE scope='GLOBAL');
