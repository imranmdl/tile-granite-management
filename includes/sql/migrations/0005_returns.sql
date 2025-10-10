-- 0005_returns.sql — Returns module (SQLite)
CREATE TABLE IF NOT EXISTS invoice_returns (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  return_no TEXT NOT NULL UNIQUE,
  return_dt TEXT NOT NULL,
  invoice_id INTEGER NOT NULL,
  customer_name TEXT,
  notes TEXT,
  discount_type TEXT NOT NULL DEFAULT 'AMOUNT',
  discount_value REAL NOT NULL DEFAULT 0,
  subtotal REAL NOT NULL DEFAULT 0,
  gst_mode TEXT NOT NULL DEFAULT 'EXCLUDE',
  gst_percent REAL NOT NULL DEFAULT 18.0,
  gst_amount REAL NOT NULL DEFAULT 0,
  total REAL NOT NULL DEFAULT 0,
  FOREIGN KEY(invoice_id) REFERENCES invoices(id) ON DELETE CASCADE
);
CREATE TABLE IF NOT EXISTS invoice_return_items (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  return_id INTEGER NOT NULL,
  invoice_item_id INTEGER NULL,
  tile_id INTEGER NULL,
  purpose TEXT,
  boxes_decimal REAL NOT NULL DEFAULT 0,
  rate_per_box REAL NOT NULL DEFAULT 0,
  line_total REAL NOT NULL DEFAULT 0,
  FOREIGN KEY(return_id) REFERENCES invoice_returns(id) ON DELETE CASCADE
);
CREATE TABLE IF NOT EXISTS invoice_return_misc_items (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  return_id INTEGER NOT NULL,
  invoice_misc_item_id INTEGER NULL,
  misc_item_id INTEGER NOT NULL,
  qty_units REAL NOT NULL DEFAULT 0,
  rate_per_unit REAL NOT NULL DEFAULT 0,
  line_total REAL NOT NULL DEFAULT 0,
  FOREIGN KEY(return_id) REFERENCES invoice_returns(id) ON DELETE CASCADE
);
CREATE INDEX IF NOT EXISTS idx_ret_hdr_invoice ON invoice_returns(invoice_id);
CREATE INDEX IF NOT EXISTS idx_ret_items_return ON invoice_return_items(return_id);
CREATE INDEX IF NOT EXISTS idx_ret_misc_return ON invoice_return_misc_items(return_id);
