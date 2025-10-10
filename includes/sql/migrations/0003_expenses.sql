-- 0003_expenses.sql (SQLite)
CREATE TABLE IF NOT EXISTS expenses (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  exp_dt TEXT NOT NULL,
  category TEXT NOT NULL,
  payee TEXT,
  method TEXT NOT NULL DEFAULT 'CASH',
  amount REAL NOT NULL,
  notes TEXT,
  invoice_id INTEGER NULL,
  quotation_id INTEGER NULL,
  FOREIGN KEY(invoice_id) REFERENCES invoices(id) ON DELETE SET NULL,
  FOREIGN KEY(quotation_id) REFERENCES quotations(id) ON DELETE SET NULL
);
