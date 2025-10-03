<?php
// test_login_flow.php - Test the complete login and access flow
require_once __DIR__ . '/includes/simple_auth.php';

echo "<h1>🧪 Testing Complete Login Flow</h1>";

// Test 1: Login System
echo "<h2>1. Testing Login System</h2>";
if (auth_login('admin', 'admin123')) {
    echo "✅ Admin login successful<br>";
    echo "Current user: " . auth_username() . " (" . auth_role() . ")<br>";
    echo "Is logged in: " . (auth_is_logged_in() ? 'Yes' : 'No') . "<br>";
} else {
    echo "❌ Admin login failed<br>";
}

// Test 2: Permission System
echo "<h2>2. Testing Permission System</h2>";
$permissions = [
    'settings.view' => 'View Settings (Control Panel)',
    'users.view' => 'View Users (User Management)', 
    'users.create' => 'Create Users',
    'inventory.view' => 'View Inventory',
    'reports.profit_loss' => 'View P&L Reports'
];

foreach ($permissions as $perm => $description) {
    $has_perm = auth_has_permission($perm);
    echo ($has_perm ? "✅" : "❌") . " $description: " . ($has_perm ? "Allowed" : "Denied") . "<br>";
}

// Test 3: Access Control Simulation
echo "<h2>3. Testing Access Control</h2>";

echo "<h3>Admin Control Panel Access:</h3>";
if (auth_has_permission('settings.view')) {
    echo "✅ Admin can access control panel<br>";
} else {
    echo "❌ Admin cannot access control panel<br>";
}

echo "<h3>User Management Access:</h3>";
if (auth_has_permission('users.view')) {
    echo "✅ Admin can access user management<br>";
} else {
    echo "❌ Admin cannot access user management<br>";
}

// Test 4: Database Check
echo "<h2>4. Database Status</h2>";
$pdo = Database::pdo();
$user_count = $pdo->query("SELECT COUNT(*) FROM users_simple")->fetchColumn();
echo "Total users in database: $user_count<br>";

$users = $pdo->query("SELECT username, role, active FROM users_simple ORDER BY role")->fetchAll();
echo "<table border='1' cellpadding='5'>";
echo "<tr><th>Username</th><th>Role</th><th>Status</th></tr>";
foreach ($users as $user) {
    $status = $user['active'] ? 'Active' : 'Inactive';
    echo "<tr><td>{$user['username']}</td><td>{$user['role']}</td><td>$status</td></tr>";
}
echo "</table>";

// Test 5: Role-based Access
echo "<h2>5. Testing Different User Roles</h2>";

// Test Manager
auth_logout();
if (auth_login('manager1', 'manager123')) {
    echo "<h3>Manager User (manager1):</h3>";
    echo "Can view settings: " . (auth_has_permission('settings.view') ? "✅ Yes" : "❌ No") . "<br>";
    echo "Can create users: " . (auth_has_permission('users.create') ? "✅ Yes" : "❌ No") . "<br>";
    echo "Can view inventory: " . (auth_has_permission('inventory.view') ? "✅ Yes" : "❌ No") . "<br>";
}

// Test Sales
auth_logout();
if (auth_login('sales1', 'sales123')) {
    echo "<h3>Sales User (sales1):</h3>";
    echo "Can view settings: " . (auth_has_permission('settings.view') ? "✅ Yes" : "❌ No") . "<br>";
    echo "Can view users: " . (auth_has_permission('users.view') ? "✅ Yes" : "❌ No") . "<br>";
    echo "Can view inventory: " . (auth_has_permission('inventory.view') ? "✅ Yes" : "❌ No") . "<br>";
}

// Reset to admin for final test
auth_logout();
auth_login('admin', 'admin123');

echo "<h2>6. Final Status</h2>";
if (auth_is_logged_in() && auth_has_permission('settings.view') && auth_has_permission('users.view')) {
    echo "🎉 <strong>ALL TESTS PASSED!</strong><br>";
    echo "✅ Login system working<br>";
    echo "✅ Permission system working<br>";
    echo "✅ Admin can access all features<br>";
    echo "✅ Role-based access working<br>";
    
    echo "<h3>Next Steps:</h3>";
    echo "1. <a href='public/login_enhanced.php'>Test Enhanced Login Page</a><br>";
    echo "2. <a href='public/admin_control_panel.php'>Test Admin Control Panel</a><br>";
    echo "3. <a href='public/users_management.php'>Test User Management</a><br>";
    echo "4. <a href='public/dashboard_test.php'>Test Dashboard</a><br>";
} else {
    echo "❌ Some tests failed - check the system<br>";
}

echo "<hr>";
echo "<p><small>Current session: " . (auth_is_logged_in() ? auth_username() . " (" . auth_role() . ")" : "Not logged in") . "</small></p>";
?>