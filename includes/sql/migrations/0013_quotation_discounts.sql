-- 0013_quotation_discounts.sql (SQLite)
-- Add discount functionality to quotations table

-- Add discount fields to quotations table
ALTER TABLE quotations ADD COLUMN discount_type TEXT DEFAULT 'percentage';
ALTER TABLE quotations ADD COLUMN discount_value REAL DEFAULT 0;
ALTER TABLE quotations ADD COLUMN discount_amount REAL DEFAULT 0;
ALTER TABLE quotations ADD COLUMN final_total REAL DEFAULT 0;

-- Update existing quotations to set final_total = total where final_total is 0
UPDATE quotations SET final_total = total WHERE final_total = 0;