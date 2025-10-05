-- 0016_commission_system.sql (SQLite)
-- Commission system for quotations and invoices

-- Add commission fields to quotations table
ALTER TABLE quotations ADD COLUMN commission_percentage REAL DEFAULT 0;
ALTER TABLE quotations ADD COLUMN commission_amount REAL DEFAULT 0;
ALTER TABLE quotations ADD COLUMN commission_user_id INTEGER NULL;

-- Add commission fields to invoices table  
ALTER TABLE invoices ADD COLUMN commission_percentage REAL DEFAULT 0;
ALTER TABLE invoices ADD COLUMN commission_amount REAL DEFAULT 0;
ALTER TABLE invoices ADD COLUMN commission_user_id INTEGER NULL;

-- Add foreign key constraints for commission users
-- Foreign keys for quotations
-- FOREIGN KEY (commission_user_id) REFERENCES users_simple(id)

-- Foreign keys for invoices  
-- FOREIGN KEY (commission_user_id) REFERENCES users_simple(id)

-- Commission tracking table for detailed records
CREATE TABLE IF NOT EXISTS commission_records (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    document_type TEXT NOT NULL CHECK (document_type IN ('quotation', 'invoice')),
    document_id INTEGER NOT NULL,
    user_id INTEGER NOT NULL,
    base_amount REAL NOT NULL,
    commission_percentage REAL NOT NULL,
    commission_amount REAL NOT NULL,
    status TEXT DEFAULT 'pending' CHECK (status IN ('pending', 'approved', 'paid')),
    created_at TEXT DEFAULT (datetime('now')),
    approved_at TEXT NULL,
    paid_at TEXT NULL,
    notes TEXT NULL,
    FOREIGN KEY (user_id) REFERENCES users_simple(id)
);

CREATE INDEX IF NOT EXISTS idx_commission_records_user_id ON commission_records(user_id);
CREATE INDEX IF NOT EXISTS idx_commission_records_document ON commission_records(document_type, document_id);
CREATE INDEX IF NOT EXISTS idx_commission_records_status ON commission_records(status);