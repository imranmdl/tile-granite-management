-- 0019_active_status.sql - Add active status to tiles and misc_items
-- Add active status to tiles table
ALTER TABLE tiles ADD COLUMN active INTEGER DEFAULT 1;

-- Add active status to misc_items table  
ALTER TABLE misc_items ADD COLUMN active INTEGER DEFAULT 1;

-- Update existing records to be active by default
UPDATE tiles SET active = 1 WHERE active IS NULL;
UPDATE misc_items SET active = 1 WHERE active IS NULL;

-- Create indexes for better performance on active status
CREATE INDEX IF NOT EXISTS idx_tiles_active ON tiles(active);
CREATE INDEX IF NOT EXISTS idx_misc_items_active ON misc_items(active);