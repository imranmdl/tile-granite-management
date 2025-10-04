<?php
// Fix database schema issues
require_once __DIR__ . '/includes/Database.php';

$pdo = Database::pdo();

// Check quotations table structure
echo "Quotations table columns:\n";
$stmt = $pdo->query("PRAGMA table_info(quotations)");
$quotation_columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
$quotation_column_names = array_column($quotation_columns, 'name');
foreach ($quotation_columns as $col) {
    echo "- {$col['name']} ({$col['type']})\n";
}

// Add missing status column to quotations if not exists
if (!in_array('status', $quotation_column_names)) {
    echo "\nAdding status column to quotations...\n";
    $pdo->exec("ALTER TABLE quotations ADD COLUMN status TEXT DEFAULT 'pending'");
    echo "Status column added.\n";
}

// Check if current_tiles_stock view exists
echo "\nChecking current_tiles_stock view...\n";
$view_check = $pdo->query("SELECT name FROM sqlite_master WHERE type='view' AND name='current_tiles_stock'")->fetch();
if (!$view_check) {
    echo "Creating current_tiles_stock view...\n";
    $pdo->exec("
        CREATE VIEW current_tiles_stock AS
        SELECT 
            t.id,
            t.name,
            COALESCE(SUM(pet.purchase_qty_boxes), 0) as total_stock_boxes,
            COALESCE(SUM(pet.purchase_qty_boxes * ts.sqft_per_box), 0) as total_stock_sqft,
            COALESCE(SUM(pet.purchase_qty_boxes * pet.rate_per_box), 0) as total_value
        FROM tiles t
        LEFT JOIN purchase_entries_tiles pet ON t.id = pet.tile_id
        LEFT JOIN tile_sizes ts ON t.size_id = ts.id
        GROUP BY t.id, t.name
    ");
    echo "Current tiles stock view created.\n";
}

// Check if current_misc_stock view exists
echo "Checking current_misc_stock view...\n";
$misc_view_check = $pdo->query("SELECT name FROM sqlite_master WHERE type='view' AND name='current_misc_stock'")->fetch();
if (!$misc_view_check) {
    echo "Creating current_misc_stock view...\n";
    $pdo->exec("
        CREATE VIEW current_misc_stock AS
        SELECT 
            m.id,
            m.name,
            COALESCE(SUM(pem.purchase_qty_units), 0) as total_stock_units,
            COALESCE(SUM(pem.purchase_qty_units * pem.rate_per_unit), 0) as total_value
        FROM misc_items m
        LEFT JOIN purchase_entries_misc pem ON m.id = pem.misc_item_id
        GROUP BY m.id, m.name
    ");
    echo "Current misc stock view created.\n";
}

echo "\nDatabase schema fixes completed!\n";
?>