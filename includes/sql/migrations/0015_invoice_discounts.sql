-- 0015_invoice_discounts.sql (SQLite)
-- Add missing discount fields to invoices table

-- Add discount fields to invoices table to match quotations
ALTER TABLE invoices ADD COLUMN discount_amount REAL DEFAULT 0;
ALTER TABLE invoices ADD COLUMN final_total REAL DEFAULT 0;

-- Update existing invoices to set final_total = total where final_total is 0
UPDATE invoices SET final_total = total WHERE final_total = 0;