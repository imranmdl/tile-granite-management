<?php
require_once 'includes/Database.php';

try {
    $pdo = Database::pdo();
    
    // Check if new tables exist
    $tables = ['purchase_entries_tiles', 'purchase_entries_misc'];
    
    foreach ($tables as $table) {
        $stmt = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='$table'");
        if ($stmt->fetchColumn()) {
            echo "✅ Table $table exists<br>";
        } else {
            echo "❌ Table $table missing<br>";
        }
    }
    
    // Check views
    $views = ['current_tiles_stock', 'current_misc_stock'];
    foreach ($views as $view) {
        $stmt = $pdo->query("SELECT name FROM sqlite_master WHERE type='view' AND name='$view'");
        if ($stmt->fetchColumn()) {
            echo "✅ View $view exists<br>";
        } else {
            echo "❌ View $view missing<br>";
        }
    }
    
    echo "<br>Database structure ready for Enhanced Inventory!";
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage();
}
?>