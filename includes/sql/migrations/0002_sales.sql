-- 0002_sales.sql (SQLite)
CREATE TABLE IF NOT EXISTS invoices(
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  invoice_no TEXT NOT NULL UNIQUE,
  invoice_dt TEXT NOT NULL,
  customer_name TEXT,
  phone TEXT,
  notes TEXT,
  discount_type TEXT NOT NULL DEFAULT 'AMOUNT',
  discount_value REAL NOT NULL DEFAULT 0,
  subtotal REAL NOT NULL DEFAULT 0,
  total REAL NOT NULL DEFAULT 0,
  gst_mode TEXT NOT NULL DEFAULT 'EXCLUDE',
  gst_percent REAL NOT NULL DEFAULT 18.0,
  gst_amount REAL NOT NULL DEFAULT 0,
  status TEXT NOT NULL DEFAULT 'FINALIZED',
  quote_id INTEGER NULL
);
CREATE TABLE IF NOT EXISTS invoice_items(
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  invoice_id INTEGER NOT NULL,
  purpose TEXT,
  tile_id INTEGER NOT NULL,
  length_ft REAL DEFAULT 0,
  width_ft REAL DEFAULT 0,
  extra_sqft REAL DEFAULT 0,
  total_sqft REAL DEFAULT 0,
  rate_per_sqft REAL DEFAULT 0,
  rate_per_box REAL DEFAULT 0,
  boxes_decimal REAL NOT NULL DEFAULT 0,
  line_total REAL NOT NULL DEFAULT 0,
  FOREIGN KEY(invoice_id) REFERENCES invoices(id) ON DELETE CASCADE,
  FOREIGN KEY(tile_id) REFERENCES tiles(id) ON DELETE RESTRICT
);
CREATE TABLE IF NOT EXISTS invoice_misc_items(
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  invoice_id INTEGER NOT NULL,
  purpose TEXT,
  misc_item_id INTEGER NOT NULL,
  qty_units REAL NOT NULL DEFAULT 0,
  rate_per_unit REAL NOT NULL DEFAULT 0,
  line_total REAL NOT NULL DEFAULT 0,
  FOREIGN KEY(invoice_id) REFERENCES invoices(id) ON DELETE CASCADE,
  FOREIGN KEY(misc_item_id) REFERENCES misc_items(id) ON DELETE RESTRICT
);
CREATE TABLE IF NOT EXISTS invoice_payments(
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  invoice_id INTEGER NOT NULL,
  pay_dt TEXT NOT NULL,
  method TEXT NOT NULL,
  amount REAL NOT NULL,
  reference TEXT,
  notes TEXT,
  FOREIGN KEY(invoice_id) REFERENCES invoices(id) ON DELETE CASCADE
);
