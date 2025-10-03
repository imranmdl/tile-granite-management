<?php
// includes/auth.php — DB-backed auth with roles (admin, manager, sales)
require_once __DIR__ . '/Database.php';
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Try to load enhanced auth system if available
if (file_exists(__DIR__ . '/auth_enhanced.php')) {
  require_once __DIR__ . '/auth_enhanced.php';
  // Initialize enhanced auth system
  if (class_exists('AuthSystem')) {
    AuthSystem::init();
  }
}

function auth_user(): ?array { return $_SESSION['user'] ?? null; }
function auth_user_id(): ?int { return $_SESSION['user']['id'] ?? null; }

if (!function_exists('auth_username')) {
    function auth_username(): string { return $_SESSION['user']['username'] ?? ''; }
}

if (!function_exists('auth_role')) {
    function auth_role(): string { return $_SESSION['user']['role'] ?? 'sales'; }
}

if (!function_exists('auth_is_admin')) {
    function auth_is_admin(): bool { return in_array(auth_role(), ['admin','manager'], true); }
} // managers treated as elevated
function require_login($roles = null){
  if (!auth_user()) { header('Location: /public/login.php'); exit; }
  if ($roles) {
    $u = auth_user();
    if (!in_array($u['role'], (array)$roles, true)) {
      http_response_code(403);
      echo "<div style='margin:2rem;font-family:sans-serif'><h3>Forbidden</h3><p>You do not have access.</p><p><a href='/public/index.php'>Back</a></p></div>";
      exit;
    }
  }
}

function auth_login_password(string $username, string $password): bool {
  $pdo = Database::pdo();
  // Ensure users table exists (migration runner executes on first PDO init)
  $st = $pdo->prepare("SELECT id, username, password_hash, role, active FROM users WHERE username=?");
  $st->execute([$username]);
  $u = $st->fetch();
  if (!$u || !(int)$u['active']) return false;
  if (!password_verify($password, $u['password_hash'])) return false;
  $_SESSION['user'] = ['id'=>(int)$u['id'], 'username'=>$u['username'], 'role'=>$u['role'], 'active'=>(int)$u['active']];
  return true;
}

function auth_logout(){
  $_SESSION = [];
  if (session_id()) session_destroy();
}

// Enhanced permission functions for compatibility
if (!function_exists('auth_has_permission')) {
function auth_has_permission(string $permission, int $user_id = null): bool {
  // If enhanced auth system is available, use it
  if (class_exists('AuthSystem')) {
    return AuthSystem::hasPermission($permission, $user_id);
  }
  
  // Fallback to basic role-based permissions
  $user = auth_user();
  if (!$user) return false;
  
  $role = $user['role'];
  
  // Admin has all permissions
  if ($role === 'admin') return true;
  
  // Basic role-based permissions for compatibility
  $basic_permissions = [
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
  
  $role_permissions = $basic_permissions[$role] ?? [];
  return in_array($permission, $role_permissions);
}
}

if (!function_exists('auth_get_user')) {
    function auth_get_user(): ?array {
      return auth_user();
    }
}

if (!function_exists('auth_require_login')) {
    function auth_require_login() {
      require_login();
    }
}
