<?php
require_once 'includes/Database.php';

try {
    $pdo = Database::pdo();
    $sql = file_get_contents('includes/sql/migrations/0010_enhanced_inventory.sql');
    $pdo->exec($sql);
    echo "Enhanced inventory migration completed successfully";
} catch (Exception $e) {
    echo "Migration failed: " . $e->getMessage();
}
?>