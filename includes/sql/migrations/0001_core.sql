-- 0001_core.sql (SQLite)
CREATE TABLE IF NOT EXISTS vendors(
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  name TEXT NOT NULL UNIQUE
);
CREATE TABLE IF NOT EXISTS tile_sizes(
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  label TEXT NOT NULL UNIQUE,
  sqft_per_box REAL NOT NULL DEFAULT 0
);
CREATE TABLE IF NOT EXISTS tiles(
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  name TEXT NOT NULL,
  size_id INTEGER NOT NULL,
  vendor_id INTEGER NULL,
  image_path TEXT NULL,
  UNIQUE(name, size_id),
  FOREIGN KEY(size_id) REFERENCES tile_sizes(id) ON DELETE RESTRICT,
  FOREIGN KEY(vendor_id) REFERENCES vendors(id) ON DELETE SET NULL
);
CREATE TABLE IF NOT EXISTS inventory_items(
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  tile_id INTEGER NOT NULL,
  recvd_dt TEXT,
  boxes_in REAL NOT NULL DEFAULT 0,
  damage_boxes REAL NOT NULL DEFAULT 0,
  per_box_value REAL NOT NULL DEFAULT 0,
  per_sqft_value REAL NOT NULL DEFAULT 0,
  transport_pct REAL NOT NULL DEFAULT 0,
  transport_per_box REAL NOT NULL DEFAULT 0,
  transport_total REAL NOT NULL DEFAULT 0,
  notes TEXT,
  FOREIGN KEY(tile_id) REFERENCES tiles(id) ON DELETE CASCADE
);
CREATE TABLE IF NOT EXISTS misc_items(
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  name TEXT NOT NULL UNIQUE,
  unit_label TEXT NOT NULL DEFAULT 'unit'
);
CREATE TABLE IF NOT EXISTS misc_inventory_items(
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  misc_item_id INTEGER NOT NULL,
  recvd_dt TEXT,
  qty_in REAL NOT NULL DEFAULT 0,
  damage_units REAL NOT NULL DEFAULT 0,
  cost_per_unit REAL NOT NULL DEFAULT 0,
  transport_pct REAL NOT NULL DEFAULT 0,
  transport_per_unit REAL NOT NULL DEFAULT 0,
  transport_total REAL NOT NULL DEFAULT 0,
  notes TEXT,
  FOREIGN KEY(misc_item_id) REFERENCES misc_items(id) ON DELETE CASCADE
);
CREATE TABLE IF NOT EXISTS quotations(
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  quote_no TEXT NOT NULL UNIQUE,
  quote_dt TEXT NOT NULL,
  customer_name TEXT,
  phone TEXT,
  notes TEXT,
  discount_type TEXT NOT NULL DEFAULT 'AMOUNT',
  discount_value REAL NOT NULL DEFAULT 0,
  subtotal REAL NOT NULL DEFAULT 0,
  total REAL NOT NULL DEFAULT 0,
  gst_mode TEXT NOT NULL DEFAULT 'EXCLUDE',
  gst_percent REAL NOT NULL DEFAULT 18.0,
  gst_amount REAL NOT NULL DEFAULT 0
);
CREATE TABLE IF NOT EXISTS quotation_items(
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  quotation_id INTEGER NOT NULL,
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
  FOREIGN KEY(quotation_id) REFERENCES quotations(id) ON DELETE CASCADE,
  FOREIGN KEY(tile_id) REFERENCES tiles(id) ON DELETE RESTRICT
);
CREATE TABLE IF NOT EXISTS quotation_misc_items(
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  quotation_id INTEGER NOT NULL,
  purpose TEXT,
  misc_item_id INTEGER NOT NULL,
  qty_units REAL NOT NULL DEFAULT 0,
  rate_per_unit REAL NOT NULL DEFAULT 0,
  line_total REAL NOT NULL DEFAULT 0,
  FOREIGN KEY(quotation_id) REFERENCES quotations(id) ON DELETE CASCADE,
  FOREIGN KEY(misc_item_id) REFERENCES misc_items(id) ON DELETE RESTRICT
);
