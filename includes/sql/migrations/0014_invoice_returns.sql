-- 0014_invoice_returns.sql (SQLite)
-- Create invoice returns table for return/refund functionality

-- Create invoice returns table
CREATE TABLE IF NOT EXISTS invoice_returns (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    invoice_id INTEGER NOT NULL,
    invoice_item_id INTEGER NULL,
    invoice_misc_item_id INTEGER NULL,
    item_type TEXT NOT NULL CHECK (item_type IN ('tile', 'misc')),
    item_id INTEGER NOT NULL,
    quantity_returned REAL NOT NULL,
    original_rate REAL NOT NULL,
    refund_rate REAL NOT NULL,
    refund_amount REAL NOT NULL,
    return_reason TEXT NOT NULL,
    return_date TEXT NOT NULL DEFAULT (date('now')),
    notes TEXT NULL,
    processed_by INTEGER NULL,
    created_at TEXT DEFAULT (datetime('now')),
    FOREIGN KEY (invoice_id) REFERENCES invoices(id) ON DELETE CASCADE,
    FOREIGN KEY (invoice_item_id) REFERENCES invoice_items(id) ON DELETE SET NULL,
    FOREIGN KEY (invoice_misc_item_id) REFERENCES invoice_misc_items(id) ON DELETE SET NULL,
    FOREIGN KEY (processed_by) REFERENCES users_simple(id)
);

-- Create indexes for better performance
CREATE INDEX IF NOT EXISTS idx_invoice_returns_invoice_id ON invoice_returns(invoice_id);
CREATE INDEX IF NOT EXISTS idx_invoice_returns_item_type ON invoice_returns(item_type);
CREATE INDEX IF NOT EXISTS idx_invoice_returns_return_date ON invoice_returns(return_date);