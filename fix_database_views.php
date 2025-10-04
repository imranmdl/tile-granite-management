<?php
require_once __DIR__ . '/includes/Database.php';

$pdo = Database::pdo();

// Create current_tiles_stock view if not exists
$pdo->exec("
    CREATE VIEW IF NOT EXISTS current_tiles_stock AS
    SELECT 
        t.id,
        t.name,
        COALESCE(SUM(COALESCE(pet.purchase_qty_boxes, 0)), 0) as total_stock_boxes,
        COALESCE(SUM(COALESCE(pet.purchase_qty_boxes, 0) * COALESCE(ts.sqft_per_box, 0)), 0) as total_stock_sqft
    FROM tiles t
    LEFT JOIN purchase_entries_tiles pet ON t.id = pet.tile_id
    LEFT JOIN tile_sizes ts ON t.size_id = ts.id
    GROUP BY t.id, t.name
");

// Create current_misc_stock view if not exists
$pdo->exec("
    CREATE VIEW IF NOT EXISTS current_misc_stock AS
    SELECT 
        m.id,
        m.name,
        COALESCE(SUM(COALESCE(pem.purchase_qty_units, 0)), 0) as total_stock_units
    FROM misc_items m
    LEFT JOIN purchase_entries_misc pem ON m.id = pem.misc_item_id
    GROUP BY m.id, m.name
");

echo "Database views created successfully!";
?>