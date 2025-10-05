-- 0006_quotation_discounts_commission.sql
ALTER TABLE quotations ADD COLUMN discount_mode TEXT DEFAULT 'NONE';
ALTER TABLE quotations ADD COLUMN discount_value REAL DEFAULT 0;
ALTER TABLE quotations ADD COLUMN discount_amount REAL DEFAULT 0;
ALTER TABLE quotations ADD COLUMN total_before_discount REAL DEFAULT 0;
ALTER TABLE quotations ADD COLUMN total_after_discount REAL DEFAULT 0;
ALTER TABLE quotations ADD COLUMN profit_before_discount REAL DEFAULT 0;
ALTER TABLE quotations ADD COLUMN profit_after_discount REAL DEFAULT 0;
ALTER TABLE quotations ADD COLUMN commission_base TEXT DEFAULT 'PROFIT';
ALTER TABLE quotations ADD COLUMN commission_pct REAL DEFAULT 0;
ALTER TABLE quotations ADD COLUMN commission_amount REAL DEFAULT 0;
ALTER TABLE quotations ADD COLUMN commission_to TEXT;
