-- 0009_commission_final_value.sql
-- Update commission strategy to use final invoice value instead of cost

-- Update existing commission config to use FINAL_VALUE strategy
UPDATE commission_config SET strategy = 'FINAL_VALUE' WHERE id = 1;

-- Add column to track calculation method in commission ledger
ALTER TABLE commission_ledger ADD COLUMN calculation_method TEXT DEFAULT 'FINAL_VALUE';

-- Update existing records to reflect new calculation method
UPDATE commission_ledger SET calculation_method = 'FINAL_VALUE';

-- Add index for better performance on invoice lookups
CREATE INDEX IF NOT EXISTS idx_commission_ledger_invoice_id ON commission_ledger(invoice_id);
CREATE INDEX IF NOT EXISTS idx_commission_ledger_salesperson ON commission_ledger(salesperson_user_id);
CREATE INDEX IF NOT EXISTS idx_commission_ledger_status ON commission_ledger(status);