-- 0012_enhanced_quotations.sql (SQLite)
-- Enhanced quotations and invoices with improved functionality

-- Add new fields to quotations table
ALTER TABLE quotations ADD COLUMN firm_name TEXT NULL;
ALTER TABLE quotations ADD COLUMN customer_gst TEXT NULL;
ALTER TABLE quotations ADD COLUMN mobile_required INTEGER DEFAULT 1;
ALTER TABLE quotations ADD COLUMN created_by INTEGER NULL;
ALTER TABLE quotations ADD COLUMN updated_at TEXT DEFAULT CURRENT_TIMESTAMP;

-- Add new fields to invoices table  
ALTER TABLE invoices ADD COLUMN firm_name TEXT NULL;
ALTER TABLE invoices ADD COLUMN customer_gst TEXT NULL;
ALTER TABLE invoices ADD COLUMN mobile_required INTEGER DEFAULT 1;
ALTER TABLE invoices ADD COLUMN created_by INTEGER NULL;
ALTER TABLE invoices ADD COLUMN updated_at TEXT DEFAULT CURRENT_TIMESTAMP;

-- Add calculation mode field to quotation_items (sqft_mode or direct_box_mode)
ALTER TABLE quotation_items ADD COLUMN calculation_mode TEXT DEFAULT 'sqft_mode';
ALTER TABLE quotation_items ADD COLUMN direct_boxes REAL NULL;
ALTER TABLE quotation_items ADD COLUMN show_image INTEGER DEFAULT 0;

-- Add calculation mode field to invoice_items
ALTER TABLE invoice_items ADD COLUMN calculation_mode TEXT DEFAULT 'sqft_mode'; 
ALTER TABLE invoice_items ADD COLUMN direct_boxes REAL NULL;
ALTER TABLE invoice_items ADD COLUMN show_image INTEGER DEFAULT 0;

-- Add calculation mode field to quotation_misc_items
ALTER TABLE quotation_misc_items ADD COLUMN show_image INTEGER DEFAULT 0;

-- Add calculation mode field to invoice_misc_items
ALTER TABLE invoice_misc_items ADD COLUMN show_image INTEGER DEFAULT 0;

-- Create user preferences table for image display settings
CREATE TABLE IF NOT EXISTS user_preferences (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    preference_key TEXT NOT NULL,
    preference_value TEXT NOT NULL,
    created_at TEXT DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(user_id, preference_key)
);

-- Insert default preferences
INSERT OR IGNORE INTO user_preferences (user_id, preference_key, preference_value) 
SELECT 1, 'show_item_images', 'false';

-- Create indexes for better performance
CREATE INDEX IF NOT EXISTS idx_quotations_date ON quotations(quote_dt);
CREATE INDEX IF NOT EXISTS idx_quotations_customer ON quotations(customer_name);
CREATE INDEX IF NOT EXISTS idx_quotations_firm ON quotations(firm_name);
CREATE INDEX IF NOT EXISTS idx_quotations_gst ON quotations(customer_gst);

CREATE INDEX IF NOT EXISTS idx_invoices_date ON invoices(invoice_dt);
CREATE INDEX IF NOT EXISTS idx_invoices_customer ON invoices(customer_name);
CREATE INDEX IF NOT EXISTS idx_invoices_firm ON invoices(firm_name);
CREATE INDEX IF NOT EXISTS idx_invoices_gst ON invoices(customer_gst);

-- Create view for enhanced quotation list with customer details
CREATE VIEW IF NOT EXISTS enhanced_quotations_list AS
SELECT 
    q.id,
    q.quote_no,
    q.quote_dt,
    q.customer_name,
    q.firm_name,
    q.phone,
    q.customer_gst,
    q.total,
    q.notes,
    q.created_by,
    u.username as created_by_user,
    COUNT(qi.id) + COUNT(qmi.id) as total_items,
    COUNT(qi.id) as tile_items,
    COUNT(qmi.id) as misc_items
FROM quotations q
LEFT JOIN quotation_items qi ON q.id = qi.quotation_id
LEFT JOIN quotation_misc_items qmi ON q.id = qmi.quotation_id
LEFT JOIN users_simple u ON q.created_by = u.id
GROUP BY q.id, q.quote_no, q.quote_dt, q.customer_name, q.firm_name, q.phone, q.customer_gst, q.total, q.notes, q.created_by, u.username;

-- Create view for enhanced invoice list with customer details
CREATE VIEW IF NOT EXISTS enhanced_invoices_list AS
SELECT 
    i.id,
    i.invoice_no,
    i.invoice_dt,
    i.customer_name,
    i.firm_name,
    i.phone,
    i.customer_gst,
    i.total,
    i.status,
    i.notes,
    i.created_by,
    u.username as created_by_user,
    COUNT(ii.id) + COUNT(imi.id) as total_items,
    COUNT(ii.id) as tile_items,
    COUNT(imi.id) as misc_items
FROM invoices i
LEFT JOIN invoice_items ii ON i.id = ii.invoice_id
LEFT JOIN invoice_misc_items imi ON i.id = imi.invoice_id
LEFT JOIN users_simple u ON i.created_by = u.id
GROUP BY i.id, i.invoice_no, i.invoice_dt, i.customer_name, i.firm_name, i.phone, i.customer_gst, i.total, i.status, i.notes, i.created_by, u.username;