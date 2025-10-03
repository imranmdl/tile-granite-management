<?php
// includes/simple_auth.php - Clean, Simple Authentication System
require_once __DIR__ . '/Database.php';

// Start session only if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/**
 * Simple Authentication Functions
 */

function auth_user() {
    return $_SESSION['user'] ?? null;
}

function auth_user_id() {
    $user = auth_user();
    return $user ? (int)$user['id'] : null;
}

function auth_username() {
    $user = auth_user();
    return $user ? $user['username'] : '';
}

function auth_role() {
    $user = auth_user();
    return $user ? $user['role'] : 'guest';
}

function auth_is_admin() {
    return in_array(auth_role(), ['admin', 'manager']);
}

function auth_is_logged_in() {
    return auth_user() !== null;
}

function auth_require_login() {
    if (!auth_is_logged_in()) {
        header('Location: /public/login.php?redirect=' . urlencode($_SERVER['REQUEST_URI'] ?? ''));
        exit;
    }
}

function auth_login($username, $password) {
    $pdo = Database::pdo();
    
    // Create users table if not exists
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS users_simple (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            username TEXT UNIQUE NOT NULL,
            password_hash TEXT NOT NULL,
            role TEXT NOT NULL DEFAULT 'sales',
            name TEXT,
            email TEXT,
            active INTEGER DEFAULT 1,
            created_by INTEGER,
            created_at TEXT DEFAULT CURRENT_TIMESTAMP
        )
    ");
    
    // Create default admin user if no users exist
    $user_count = $pdo->query("SELECT COUNT(*) FROM users_simple")->fetchColumn();
    if ($user_count == 0) {
        create_default_users($pdo);
    }
    
    // Authenticate user
    $stmt = $pdo->prepare("SELECT * FROM users_simple WHERE username = ? AND active = 1");
    $stmt->execute([$username]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($user && password_verify($password, $user['password_hash'])) {
        $_SESSION['user'] = [
            'id' => (int)$user['id'],
            'username' => $user['username'],
            'role' => $user['role'],
            'name' => $user['name'],
            'email' => $user['email']
        ];
        return true;
    }
    
    return false;
}

function auth_logout() {
    $_SESSION = array();
    
    // Delete session cookie
    if (ini_get("session.use_cookies")) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $params["path"], $params["domain"],
            $params["secure"], $params["httponly"]
        );
    }
    
    session_destroy();
}

function auth_has_permission($permission) {
    if (!auth_is_logged_in()) {
        return false;
    }
    
    $role = auth_role();
    
    // Admin has all permissions
    if ($role === 'admin') {
        return true;
    }
    
    // Define role-based permissions
    $permissions = [
        'admin' => [
            'users.view', 'users.create', 'users.edit', 'users.delete',
            'inventory.view', 'inventory.create', 'inventory.edit', 'inventory.delete', 'inventory.view_costs',
            'quotes.view', 'quotes.create', 'quotes.edit', 'quotes.delete',
            'invoices.view', 'invoices.create', 'invoices.edit', 'invoices.delete',
            'reports.view', 'reports.profit_loss',
            'commission.view', 'commission.manage',
            'settings.view', 'settings.edit'
        ],
        'manager' => [
            'users.view',
            'inventory.view', 'inventory.create', 'inventory.edit', 'inventory.view_costs',
            'quotes.view', 'quotes.create', 'quotes.edit',
            'invoices.view', 'invoices.create', 'invoices.edit',
            'reports.view', 'reports.profit_loss',
            'commission.view'
        ],
        'sales' => [
            'inventory.view',
            'quotes.view', 'quotes.create', 'quotes.edit',
            'invoices.view', 'invoices.create', 'invoices.edit',
            'reports.view',
            'commission.view'
        ]
    ];
    
    $role_permissions = $permissions[$role] ?? [];
    return in_array($permission, $role_permissions);
}

function auth_get_user() {
    return auth_user();
}

function create_default_users($pdo) {
    $users = [
        ['admin', 'admin123', 'admin', 'System Administrator', 'admin@tilesuite.com'],
        ['manager1', 'manager123', 'manager', 'John Manager', 'manager@tilesuite.com'],
        ['sales1', 'sales123', 'sales', 'Jane Sales', 'sales@tilesuite.com']
    ];
    
    $stmt = $pdo->prepare("
        INSERT OR IGNORE INTO users_simple (username, password_hash, role, name, email) 
        VALUES (?, ?, ?, ?, ?)
    ");
    
    foreach ($users as [$username, $password, $role, $name, $email]) {
        $password_hash = password_hash($password, PASSWORD_DEFAULT);
        $stmt->execute([$username, $password_hash, $role, $name, $email]);
    }
}

// Backward compatibility functions
function require_login($roles = null) {
    auth_require_login();
    
    if ($roles) {
        $user_role = auth_role();
        if (!in_array($user_role, (array)$roles, true)) {
            http_response_code(403);
            echo "<div style='margin:2rem;font-family:sans-serif'>
                    <h3>Access Denied</h3>
                    <p>You don't have permission to access this page.</p>
                    <p><a href='/public/index.php'>Back to Dashboard</a></p>
                  </div>";
            exit;
        }
    }
}

function auth_login_password($username, $password) {
    return auth_login($username, $password);
}
?>