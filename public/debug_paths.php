<?php
echo "<h3>Debug Paths</h3>";
echo "<pre>";
echo "SCRIPT_FILENAME: ".($_SERVER['SCRIPT_FILENAME'] ?? '')."\n";
echo "SCRIPT_NAME: ".($_SERVER['SCRIPT_NAME'] ?? '')."\n";
echo "PHP_SELF: ".($_SERVER['PHP_SELF'] ?? '')."\n";
echo "CWD: ".getcwd()."\n";
echo "</pre>";

$checks = [
  "../includes/header.php",
  "../includes/auth.php",
  "../includes/helpers.php",
  "returns.php",
  "inventory_manage.php",
  "reports.php",
  "index.php"
];
echo "<h4>File existence</h4><ul>";
foreach($checks as $f){
  $ok = file_exists(__DIR__ . '/' . $f) ? "✅" : "❌";
  echo "<li>$ok $f</li>";
}
echo "</ul>";
?>
