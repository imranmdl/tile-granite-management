<?php
// includes/auth_enhanced.php - Enhanced Authentication System
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

class AuthSystem {
    private static $pdo = null;
    
    public static function init() {
        if (!self::$pdo) {
            self::$pdo = Database::pdo();
            self::createTables();
        }
    }
    
    private static function createTables() {
        // Enhanced users table
        self::$pdo->exec("
            CREATE TABLE IF NOT EXISTS users_enhanced (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                username TEXT UNIQUE NOT NULL,
                email TEXT,
                password_hash TEXT NOT NULL,
                role TEXT NOT NULL CHECK (role IN ('admin', 'manager', 'sales')) DEFAULT 'sales',
                active INTEGER DEFAULT 1,
                created_at TEXT DEFAULT CURRENT_TIMESTAMP,
                created_by INTEGER,
                name TEXT,
                phone TEXT,
                last_login_at TEXT,
                last_login_ip TEXT,
                failed_login_attempts INTEGER DEFAULT 0,
                locked_until TEXT,
                password_reset_token TEXT,
                password_reset_expires TEXT,
                otp_secret TEXT,
                otp_enabled INTEGER DEFAULT 0
            )
        ");
        
        // User permissions table
        self::$pdo->exec("
            CREATE TABLE IF NOT EXISTS user_permissions (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER NOT NULL,
                permission_key TEXT NOT NULL,
                permission_value TEXT DEFAULT 'deny',
                created_at TEXT DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (user_id) REFERENCES users_enhanced(id) ON DELETE CASCADE,
                UNIQUE(user_id, permission_key)
            )
        ");
        
        // Sessions table
        self::$pdo->exec("
            CREATE TABLE IF NOT EXISTS user_sessions (
                id TEXT PRIMARY KEY,
                user_id INTEGER NOT NULL,
                ip_address TEXT,
                user_agent TEXT,
                created_at TEXT DEFAULT CURRENT_TIMESTAMP,
                expires_at TEXT NOT NULL,
                active INTEGER DEFAULT 1,
                FOREIGN KEY (user_id) REFERENCES users_enhanced(id) ON DELETE CASCADE
            )
        ");
        
        // System settings for auth configuration
        self::$pdo->exec("
            CREATE TABLE IF NOT EXISTS system_settings (
                key TEXT PRIMARY KEY,
                value TEXT,
                description TEXT,
                updated_at TEXT DEFAULT CURRENT_TIMESTAMP
            )
        ");
        
        // Insert default settings
        $defaultSettings = [
            'session_timeout_minutes' => ['300', 'Session timeout in minutes (default 5 hours)'],
            'max_failed_login_attempts' => ['5', 'Maximum failed login attempts before lockout'],
            'lockout_duration_minutes' => ['30', 'Account lockout duration in minutes'],
            'password_min_length' => ['8', 'Minimum password length'],
            'require_password_complexity' => ['1', 'Require complex passwords (uppercase, lowercase, numbers)'],
            'enable_otp' => ['0', 'Enable OTP authentication'],
            'company_name' => ['Tile Suite Business', 'Company name for branding'],
            'timezone' => ['Asia/Kolkata', 'System timezone'],
            'currency_symbol' => ['₹', 'Currency symbol'],
            'date_format' => ['d-m-Y', 'Date display format']
        ];
        
        foreach ($defaultSettings as $key => [$value, $description]) {
            self::$pdo->prepare("
                INSERT OR IGNORE INTO system_settings (key, value, description) 
                VALUES (?, ?, ?)
            ")->execute([$key, $value, $description]);
        }
        
        // Create default admin user if no users exist
        $userCount = self::$pdo->query("SELECT COUNT(*) FROM users_enhanced")->fetchColumn();
        if ($userCount == 0) {
            self::createUser('admin', 'admin@tilesuite.com', 'admin123', 'admin', 'System Administrator');
        }
    }
    
    public static function createUser($username, $email, $password, $role = 'sales', $name = '', $phone = '', $created_by = null) {
        self::init();
        
        // Validate inputs
        if (!self::validateUsername($username)) {
            return ['success' => false, 'message' => 'Invalid username format'];
        }
        
        if (!self::validatePassword($password)) {
            return ['success' => false, 'message' => 'Password does not meet requirements'];
        }
        
        if (!in_array($role, ['admin', 'manager', 'sales'])) {
            return ['success' => false, 'message' => 'Invalid role'];
        }
        
        // Check if username exists
        $stmt = self::$pdo->prepare("SELECT id FROM users_enhanced WHERE username = ?");
        $stmt->execute([$username]);
        if ($stmt->fetchColumn()) {
            return ['success' => false, 'message' => 'Username already exists'];
        }
        
        // Check if email exists (if provided)
        if ($email) {
            $stmt = self::$pdo->prepare("SELECT id FROM users_enhanced WHERE email = ?");
            $stmt->execute([$email]);
            if ($stmt->fetchColumn()) {
                return ['success' => false, 'message' => 'Email already exists'];
            }
        }
        
        // Create user
        $password_hash = password_hash($password, PASSWORD_DEFAULT);
        $stmt = self::$pdo->prepare("
            INSERT INTO users_enhanced (username, email, password_hash, role, name, phone, created_by)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ");
        
        if ($stmt->execute([$username, $email, $password_hash, $role, $name, $phone, $created_by])) {
            $user_id = self::$pdo->lastInsertId();
            
            // Set default permissions based on role
            self::setDefaultPermissions($user_id, $role);
            
            return ['success' => true, 'message' => 'User created successfully', 'user_id' => $user_id];
        }
        
        return ['success' => false, 'message' => 'Failed to create user'];
    }
    
    public static function authenticate($username, $password, $otp = null) {
        self::init();
        
        // Check if user exists and is active
        $stmt = self::$pdo->prepare("
            SELECT id, username, email, password_hash, role, name, active, 
                   failed_login_attempts, locked_until, otp_enabled, otp_secret
            FROM users_enhanced 
            WHERE username = ?
        ");
        $stmt->execute([$username]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$user) {
            return ['success' => false, 'message' => 'Invalid credentials'];
        }
        
        if (!$user['active']) {
            return ['success' => false, 'message' => 'Account is deactivated'];
        }
        
        // Check if account is locked
        if ($user['locked_until'] && $user['locked_until'] > date('Y-m-d H:i:s')) {
            return ['success' => false, 'message' => 'Account is locked. Try again later.'];
        }
        
        // Verify password
        if (!password_verify($password, $user['password_hash'])) {
            self::recordFailedLogin($user['id']);
            return ['success' => false, 'message' => 'Invalid credentials'];
        }
        
        // Check OTP if enabled
        if ($user['otp_enabled'] && !self::verifyOTP($user['otp_secret'], $otp)) {
            return ['success' => false, 'message' => 'Invalid OTP code'];
        }
        
        // Reset failed attempts and create session
        self::resetFailedAttempts($user['id']);
        $session_id = self::createSession($user['id']);
        
        if ($session_id) {
            self::recordSuccessfulLogin($user['id']);
            
            // Set session variables
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['username'] = $user['username'];
            $_SESSION['role'] = $user['role'];
            $_SESSION['session_id'] = $session_id;
            $_SESSION['last_activity'] = time();
            
            return ['success' => true, 'message' => 'Login successful', 'user' => $user];
        }
        
        return ['success' => false, 'message' => 'Failed to create session'];
    }
    
    public static function logout($session_id = null) {
        if (!$session_id) {
            $session_id = $_SESSION['session_id'] ?? null;
        }
        
        if ($session_id) {
            self::init();
            self::$pdo->prepare("UPDATE user_sessions SET active = 0 WHERE id = ?")->execute([$session_id]);
        }
        
        session_unset();
        session_destroy();
        return true;
    }
    
    public static function isLoggedIn() {
        if (!isset($_SESSION['user_id']) || !isset($_SESSION['session_id'])) {
            return false;
        }
        
        // Check session timeout
        $timeout = self::getSetting('session_timeout_minutes', 300) * 60;
        if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity'] > $timeout)) {
            self::logout();
            return false;
        }
        
        // Update last activity
        $_SESSION['last_activity'] = time();
        
        // Verify session in database
        self::init();
        $stmt = self::$pdo->prepare("
            SELECT active FROM user_sessions 
            WHERE id = ? AND user_id = ? AND expires_at > CURRENT_TIMESTAMP AND active = 1
        ");
        $stmt->execute([$_SESSION['session_id'], $_SESSION['user_id']]);
        
        return (bool)$stmt->fetchColumn();
    }
    
    public static function getCurrentUser() {
        if (!self::isLoggedIn()) {
            return null;
        }
        
        self::init();
        $stmt = self::$pdo->prepare("
            SELECT id, username, email, role, name, phone, created_at, last_login_at
            FROM users_enhanced 
            WHERE id = ?
        ");
        $stmt->execute([$_SESSION['user_id']]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }
    
    public static function hasPermission($permission_key, $user_id = null) {
        if (!$user_id) {
            if (!self::isLoggedIn()) return false;
            $user_id = $_SESSION['user_id'];
        }
        
        self::init();
        
        // Get user role
        $stmt = self::$pdo->prepare("SELECT role FROM users_enhanced WHERE id = ?");
        $stmt->execute([$user_id]);
        $role = $stmt->fetchColumn();
        
        // Admin has all permissions
        if ($role === 'admin') return true;
        
        // Check specific user permission
        $stmt = self::$pdo->prepare("
            SELECT permission_value FROM user_permissions 
            WHERE user_id = ? AND permission_key = ?
        ");
        $stmt->execute([$user_id, $permission_key]);
        $permission = $stmt->fetchColumn();
        
        if ($permission !== false) {
            return $permission === 'allow';
        }
        
        // Check default role permissions
        return self::getDefaultRolePermission($role, $permission_key);
    }
    
    private static function setDefaultPermissions($user_id, $role) {
        $permissions = self::getDefaultPermissions($role);
        
        foreach ($permissions as $key => $value) {
            self::$pdo->prepare("
                INSERT OR REPLACE INTO user_permissions (user_id, permission_key, permission_value)
                VALUES (?, ?, ?)
            ")->execute([$user_id, $key, $value]);
        }
    }
    
    private static function getDefaultPermissions($role) {
        $permissions = [
            'admin' => [
                'users.view' => 'allow',
                'users.create' => 'allow',
                'users.edit' => 'allow',
                'users.delete' => 'allow',
                'inventory.view' => 'allow',
                'inventory.create' => 'allow',
                'inventory.edit' => 'allow',
                'inventory.delete' => 'allow',
                'inventory.view_costs' => 'allow',
                'quotes.view' => 'allow',
                'quotes.create' => 'allow',
                'quotes.edit' => 'allow',
                'quotes.delete' => 'allow',
                'invoices.view' => 'allow',
                'invoices.create' => 'allow',
                'invoices.edit' => 'allow',
                'invoices.delete' => 'allow',
                'reports.view' => 'allow',
                'reports.profit_loss' => 'allow',
                'commission.view' => 'allow',
                'commission.manage' => 'allow',
                'settings.view' => 'allow',
                'settings.edit' => 'allow'
            ],
            'manager' => [
                'users.view' => 'allow',
                'users.create' => 'deny',
                'users.edit' => 'deny',
                'users.delete' => 'deny',
                'inventory.view' => 'allow',
                'inventory.create' => 'allow',
                'inventory.edit' => 'allow',
                'inventory.delete' => 'deny',
                'inventory.view_costs' => 'allow',
                'quotes.view' => 'allow',
                'quotes.create' => 'allow',
                'quotes.edit' => 'allow',
                'quotes.delete' => 'deny',
                'invoices.view' => 'allow',
                'invoices.create' => 'allow',
                'invoices.edit' => 'allow',
                'invoices.delete' => 'deny',
                'reports.view' => 'allow',
                'reports.profit_loss' => 'allow',
                'commission.view' => 'allow',
                'commission.manage' => 'deny',
                'settings.view' => 'deny',
                'settings.edit' => 'deny'
            ],
            'sales' => [
                'users.view' => 'deny',
                'users.create' => 'deny',
                'users.edit' => 'deny',
                'users.delete' => 'deny',
                'inventory.view' => 'allow',
                'inventory.create' => 'deny',
                'inventory.edit' => 'deny',
                'inventory.delete' => 'deny',
                'inventory.view_costs' => 'deny',
                'quotes.view' => 'allow',
                'quotes.create' => 'allow',
                'quotes.edit' => 'allow',
                'quotes.delete' => 'deny',
                'invoices.view' => 'allow',
                'invoices.create' => 'allow',
                'invoices.edit' => 'allow',
                'invoices.delete' => 'deny',
                'reports.view' => 'allow',
                'reports.profit_loss' => 'deny',
                'commission.view' => 'allow',
                'commission.manage' => 'deny',
                'settings.view' => 'deny',
                'settings.edit' => 'deny'
            ]
        ];
        
        return $permissions[$role] ?? [];
    }
    
    private static function getDefaultRolePermission($role, $permission_key) {
        $defaults = self::getDefaultPermissions($role);
        return ($defaults[$permission_key] ?? 'deny') === 'allow';
    }
    
    private static function recordFailedLogin($user_id) {
        $max_attempts = self::getSetting('max_failed_login_attempts', 5);
        $lockout_duration = self::getSetting('lockout_duration_minutes', 30);
        
        self::$pdo->prepare("
            UPDATE users_enhanced 
            SET failed_login_attempts = failed_login_attempts + 1 
            WHERE id = ?
        ")->execute([$user_id]);
        
        // Check if we should lock the account
        $stmt = self::$pdo->prepare("SELECT failed_login_attempts FROM users_enhanced WHERE id = ?");
        $stmt->execute([$user_id]);
        $attempts = $stmt->fetchColumn();
        
        if ($attempts >= $max_attempts) {
            $lock_until = date('Y-m-d H:i:s', strtotime("+{$lockout_duration} minutes"));
            self::$pdo->prepare("
                UPDATE users_enhanced 
                SET locked_until = ? 
                WHERE id = ?
            ")->execute([$lock_until, $user_id]);
        }
    }
    
    private static function resetFailedAttempts($user_id) {
        self::$pdo->prepare("
            UPDATE users_enhanced 
            SET failed_login_attempts = 0, locked_until = NULL 
            WHERE id = ?
        ")->execute([$user_id]);
    }
    
    private static function recordSuccessfulLogin($user_id) {
        $ip_address = $_SERVER['REMOTE_ADDR'] ?? $_SERVER['HTTP_X_FORWARDED_FOR'] ?? 'unknown';
        
        self::$pdo->prepare("
            UPDATE users_enhanced 
            SET last_login_at = CURRENT_TIMESTAMP, last_login_ip = ? 
            WHERE id = ?
        ")->execute([$ip_address, $user_id]);
    }
    
    private static function createSession($user_id) {
        $session_id = bin2hex(random_bytes(32));
        $timeout_minutes = self::getSetting('session_timeout_minutes', 300);
        $expires_at = date('Y-m-d H:i:s', strtotime("+{$timeout_minutes} minutes"));
        $ip_address = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? 'unknown';
        
        $stmt = self::$pdo->prepare("
            INSERT INTO user_sessions (id, user_id, ip_address, user_agent, expires_at)
            VALUES (?, ?, ?, ?, ?)
        ");
        
        if ($stmt->execute([$session_id, $user_id, $ip_address, $user_agent, $expires_at])) {
            return $session_id;
        }
        
        return null;
    }
    
    private static function validateUsername($username) {
        return preg_match('/^[a-zA-Z0-9_]{3,20}$/', $username);
    }
    
    private static function validatePassword($password) {
        $min_length = self::getSetting('password_min_length', 8);
        $require_complexity = self::getSetting('require_password_complexity', 1);
        
        if (strlen($password) < $min_length) {
            return false;
        }
        
        if ($require_complexity) {
            // Must contain at least one uppercase, one lowercase, and one number
            if (!preg_match('/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d).+$/', $password)) {
                return false;
            }
        }
        
        return true;
    }
    
    private static function verifyOTP($secret, $otp) {
        // Placeholder for OTP verification - implement with Google Authenticator library
        return true; // For now, always return true
    }
    
    public static function getSetting($key, $default = null) {
        self::init();
        $stmt = self::$pdo->prepare("SELECT value FROM system_settings WHERE key = ?");
        $stmt->execute([$key]);
        $value = $stmt->fetchColumn();
        return $value !== false ? $value : $default;
    }
    
    public static function setSetting($key, $value, $description = null) {
        self::init();
        $stmt = self::$pdo->prepare("
            INSERT OR REPLACE INTO system_settings (key, value, description, updated_at)
            VALUES (?, ?, ?, CURRENT_TIMESTAMP)
        ");
        return $stmt->execute([$key, $value, $description]);
    }
    
    // Clean up expired sessions
    public static function cleanupSessions() {
        self::init();
        self::$pdo->exec("DELETE FROM user_sessions WHERE expires_at < CURRENT_TIMESTAMP");
    }
}

// Helper functions for backward compatibility - only define if not already defined
if (!function_exists('auth_require_login')) {
    function auth_require_login() {
        if (!AuthSystem::isLoggedIn()) {
            header('Location: /public/login_enhanced.php');
            exit;
        }
    }
}

if (!function_exists('auth_get_user')) {
    function auth_get_user() {
        return AuthSystem::getCurrentUser();
    }
}

if (!function_exists('auth_has_permission')) {
    function auth_has_permission($permission) {
        return AuthSystem::hasPermission($permission);
    }
}

if (!function_exists('auth_is_admin')) {
    function auth_is_admin() {
        $user = AuthSystem::getCurrentUser();
        return $user && $user['role'] === 'admin';
    }
}

if (!function_exists('auth_username')) {
    function auth_username() {
        $user = AuthSystem::getCurrentUser();
        return $user ? $user['username'] : 'guest';
    }
}

if (!function_exists('auth_role')) {
    function auth_role() {
        $user = AuthSystem::getCurrentUser();
        return $user ? ucfirst($user['role']) : 'Guest';
    }
}
?>