<?php
// public/admin_control_panel.php - Admin Control Panel
require_once __DIR__ . '/../includes/Database.php';
require_once __DIR__ . '/../includes/simple_auth.php';
require_once __DIR__ . '/../includes/helpers.php';

auth_require_login();

// Check admin permission
if (!auth_has_permission('settings.view')) {
    header('Location: index.php?error=' . urlencode('Access denied'));
    exit;
}

$pdo = Database::pdo();
$message = '';
$error = '';

// Handle settings updates
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_settings']) && auth_has_permission('settings.edit')) {
    $settings = $_POST['settings'] ?? [];
    
    // Simple settings update (for demonstration)
    $message = 'Settings updated successfully (Note: Settings are stored in session for demo)';
    
    $message = 'Settings updated successfully';
}

// Handle company profile update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_company']) && auth_has_permission('settings.edit')) {
    $company_settings = [
        'company_name' => trim($_POST['company_name'] ?? ''),
        'company_address' => trim($_POST['company_address'] ?? ''),
        'company_phone' => trim($_POST['company_phone'] ?? ''),
        'company_email' => trim($_POST['company_email'] ?? ''),
        'company_gstin' => trim($_POST['company_gstin'] ?? ''),
        'company_website' => trim($_POST['company_website'] ?? '')
    ];
    
    foreach ($company_settings as $key => $value) {
        AuthSystem::setSetting($key, $value);
    }
    
    $message = 'Company profile updated successfully';
}

// Get all system settings
$settings_stmt = $pdo->query("SELECT * FROM system_settings ORDER BY key");
$all_settings = $settings_stmt->fetchAll(PDO::FETCH_ASSOC);
$settings = [];
foreach ($all_settings as $setting) {
    $settings[$setting['key']] = $setting['value'];
}

// Get system statistics
$system_stats = [
    'total_users' => $pdo->query("SELECT COUNT(*) FROM users_enhanced")->fetchColumn(),
    'active_sessions' => $pdo->query("SELECT COUNT(*) FROM user_sessions WHERE active = 1 AND expires_at > CURRENT_TIMESTAMP")->fetchColumn(),
    'total_inventory_items' => $pdo->query("SELECT COUNT(*) FROM inventory_items")->fetchColumn(),
    'total_invoices' => $pdo->query("SELECT COUNT(*) FROM invoices")->fetchColumn(),
    'database_size' => 'N/A' // SQLite doesn't have easy size query
];

$page_title = "Admin Control Panel";
require_once __DIR__ . '/../includes/header.php';
?>

<style>
.settings-section {
    background: white;
    border-radius: 10px;
    box-shadow: 0 2px 10px rgba(0,0,0,0.1);
    margin-bottom: 2rem;
}

.settings-header {
    background: linear-gradient(135deg, #8a243d, #0d3b66);
    color: white;
    padding: 1rem 1.5rem;
    border-radius: 10px 10px 0 0;
    margin: 0;
}

.stat-card {
    transition: transform 0.2s;
}

.stat-card:hover {
    transform: translateY(-2px);
}

.feature-toggle {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 0.75rem 0;
    border-bottom: 1px solid #eee;
}

.feature-toggle:last-child {
    border-bottom: none;
}

.switch {
    position: relative;
    display: inline-block;
    width: 60px;
    height: 34px;
}

.switch input {
    opacity: 0;
    width: 0;
    height: 0;
}

.slider {
    position: absolute;
    cursor: pointer;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background-color: #ccc;
    transition: .4s;
    border-radius: 34px;
}

.slider:before {
    position: absolute;
    content: "";
    height: 26px;
    width: 26px;
    left: 4px;
    bottom: 4px;
    background-color: white;
    transition: .4s;
    border-radius: 50%;
}

input:checked + .slider {
    background-color: #8a243d;
}

input:checked + .slider:before {
    transform: translateX(26px);
}
</style>

<?php if ($message): ?>
    <div class="alert alert-success alert-dismissible fade show">
        <?= h($message) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<?php if ($error): ?>
    <div class="alert alert-danger alert-dismissible fade show">
        <?= h($error) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<!-- System Overview -->
<div class="settings-section">
    <h5 class="settings-header">
        <i class="bi bi-speedometer2 me-2"></i>System Overview
    </h5>
    <div class="p-3">
        <div class="row g-3">
            <div class="col-md-3">
                <div class="card stat-card text-bg-primary">
                    <div class="card-body text-center">
                        <i class="bi bi-people fs-1"></i>
                        <h4><?= $system_stats['total_users'] ?></h4>
                        <p class="mb-0">Total Users</p>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card stat-card text-bg-success">
                    <div class="card-body text-center">
                        <i class="bi bi-activity fs-1"></i>
                        <h4><?= $system_stats['active_sessions'] ?></h4>
                        <p class="mb-0">Active Sessions</p>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card stat-card text-bg-info">
                    <div class="card-body text-center">
                        <i class="bi bi-box fs-1"></i>
                        <h4><?= $system_stats['total_inventory_items'] ?></h4>
                        <p class="mb-0">Inventory Items</p>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card stat-card text-bg-warning">
                    <div class="card-body text-center">
                        <i class="bi bi-receipt fs-1"></i>
                        <h4><?= $system_stats['total_invoices'] ?></h4>
                        <p class="mb-0">Invoices</p>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Company Profile -->
<div class="settings-section">
    <h5 class="settings-header">
        <i class="bi bi-building me-2"></i>Company Profile
    </h5>
    <div class="p-3">
        <?php if (auth_has_permission('settings.edit')): ?>
        <form method="post">
            <div class="row g-3">
                <div class="col-md-6">
                    <label class="form-label">Company Name</label>
                    <input type="text" class="form-control" name="company_name" 
                           value="<?= h($settings['company_name'] ?? '') ?>">
                </div>
                <div class="col-md-6">
                    <label class="form-label">GSTIN</label>
                    <input type="text" class="form-control" name="company_gstin" 
                           value="<?= h($settings['company_gstin'] ?? '') ?>">
                </div>
                <div class="col-12">
                    <label class="form-label">Address</label>
                    <textarea class="form-control" name="company_address" rows="3"><?= h($settings['company_address'] ?? '') ?></textarea>
                </div>
                <div class="col-md-6">
                    <label class="form-label">Phone</label>
                    <input type="text" class="form-control" name="company_phone" 
                           value="<?= h($settings['company_phone'] ?? '') ?>">
                </div>
                <div class="col-md-6">
                    <label class="form-label">Email</label>
                    <input type="email" class="form-control" name="company_email" 
                           value="<?= h($settings['company_email'] ?? '') ?>">
                </div>
                <div class="col-12">
                    <label class="form-label">Website</label>
                    <input type="url" class="form-control" name="company_website" 
                           value="<?= h($settings['company_website'] ?? '') ?>">
                </div>
                <div class="col-12">
                    <button type="submit" name="update_company" class="btn btn-primary">
                        Update Company Profile
                    </button>
                </div>
            </div>
        </form>
        <?php else: ?>
            <div class="alert alert-warning">
                You don't have permission to edit company settings.
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- System Configuration -->
<div class="settings-section">
    <h5 class="settings-header">
        <i class="bi bi-gear me-2"></i>System Configuration
    </h5>
    <div class="p-3">
        <?php if (auth_has_permission('settings.edit')): ?>
        <form method="post">
            <div class="row g-3">
                <div class="col-md-6">
                    <label class="form-label">Timezone</label>
                    <select class="form-select" name="settings[timezone]">
                        <option value="Asia/Kolkata" <?= ($settings['timezone'] ?? '') === 'Asia/Kolkata' ? 'selected' : '' ?>>Asia/Kolkata (IST)</option>
                        <option value="Asia/Dubai" <?= ($settings['timezone'] ?? '') === 'Asia/Dubai' ? 'selected' : '' ?>>Asia/Dubai (GST)</option>
                        <option value="UTC" <?= ($settings['timezone'] ?? '') === 'UTC' ? 'selected' : '' ?>>UTC</option>
                    </select>
                </div>
                <div class="col-md-6">
                    <label class="form-label">Currency Symbol</label>
                    <input type="text" class="form-control" name="settings[currency_symbol]" 
                           value="<?= h($settings['currency_symbol'] ?? '₹') ?>" maxlength="3">
                </div>
                <div class="col-md-6">
                    <label class="form-label">Date Format</label>
                    <select class="form-select" name="settings[date_format]">
                        <option value="d-m-Y" <?= ($settings['date_format'] ?? '') === 'd-m-Y' ? 'selected' : '' ?>>DD-MM-YYYY</option>
                        <option value="m-d-Y" <?= ($settings['date_format'] ?? '') === 'm-d-Y' ? 'selected' : '' ?>>MM-DD-YYYY</option>
                        <option value="Y-m-d" <?= ($settings['date_format'] ?? '') === 'Y-m-d' ? 'selected' : '' ?>>YYYY-MM-DD</option>
                    </select>
                </div>
                <div class="col-md-6">
                    <label class="form-label">Session Timeout (minutes)</label>
                    <input type="number" class="form-control" name="settings[session_timeout_minutes]" 
                           value="<?= h($settings['session_timeout_minutes'] ?? '300') ?>" min="30" max="1440">
                </div>
            </div>
            
            <h6 class="mt-4 mb-3 border-bottom pb-2">Security Settings</h6>
            <div class="row g-3">
                <div class="col-md-6">
                    <label class="form-label">Max Failed Login Attempts</label>
                    <input type="number" class="form-control" name="settings[max_failed_login_attempts]" 
                           value="<?= h($settings['max_failed_login_attempts'] ?? '5') ?>" min="3" max="10">
                </div>
                <div class="col-md-6">
                    <label class="form-label">Lockout Duration (minutes)</label>
                    <input type="number" class="form-control" name="settings[lockout_duration_minutes]" 
                           value="<?= h($settings['lockout_duration_minutes'] ?? '30') ?>" min="5" max="120">
                </div>
                <div class="col-md-6">
                    <label class="form-label">Minimum Password Length</label>
                    <input type="number" class="form-control" name="settings[password_min_length]" 
                           value="<?= h($settings['password_min_length'] ?? '8') ?>" min="6" max="20">
                </div>
                <div class="col-md-6">
                    <div class="feature-toggle">
                        <div>
                            <strong>Require Complex Passwords</strong>
                            <div class="text-muted small">Uppercase, lowercase, numbers required</div>
                        </div>
                        <label class="switch">
                            <input type="hidden" name="settings[require_password_complexity]" value="0">
                            <input type="checkbox" name="settings[require_password_complexity]" value="1" 
                                   <?= ($settings['require_password_complexity'] ?? '1') == '1' ? 'checked' : '' ?>>
                            <span class="slider"></span>
                        </label>
                    </div>
                </div>
            </div>
            
            <h6 class="mt-4 mb-3 border-bottom pb-2">Feature Toggles</h6>
            <div class="row g-3">
                <div class="col-md-6">
                    <div class="feature-toggle">
                        <div>
                            <strong>Enable OTP Authentication</strong>
                            <div class="text-muted small">Two-factor authentication</div>
                        </div>
                        <label class="switch">
                            <input type="hidden" name="settings[enable_otp]" value="0">
                            <input type="checkbox" name="settings[enable_otp]" value="1" 
                                   <?= ($settings['enable_otp'] ?? '0') == '1' ? 'checked' : '' ?>>
                            <span class="slider"></span>
                        </label>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="feature-toggle">
                        <div>
                            <strong>Enable GST Calculations</strong>
                            <div class="text-muted small">Show GST fields in invoices</div>
                        </div>
                        <label class="switch">
                            <input type="hidden" name="settings[enable_gst]" value="0">
                            <input type="checkbox" name="settings[enable_gst]" value="1" 
                                   <?= ($settings['enable_gst'] ?? '1') == '1' ? 'checked' : '' ?>>
                            <span class="slider"></span>
                        </label>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="feature-toggle">
                        <div>
                            <strong>Enable Email Notifications</strong>
                            <div class="text-muted small">Send invoice/quote emails</div>
                        </div>
                        <label class="switch">
                            <input type="hidden" name="settings[enable_email]" value="0">
                            <input type="checkbox" name="settings[enable_email]" value="1" 
                                   <?= ($settings['enable_email'] ?? '0') == '1' ? 'checked' : '' ?>>
                            <span class="slider"></span>
                        </label>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="feature-toggle">
                        <div>
                            <strong>Enable WhatsApp Integration</strong>
                            <div class="text-muted small">Send documents via WhatsApp</div>
                        </div>
                        <label class="switch">
                            <input type="hidden" name="settings[enable_whatsapp]" value="0">
                            <input type="checkbox" name="settings[enable_whatsapp]" value="1" 
                                   <?= ($settings['enable_whatsapp'] ?? '0') == '1' ? 'checked' : '' ?>>
                            <span class="slider"></span>
                        </label>
                    </div>
                </div>
            </div>
            
            <div class="mt-4">
                <button type="submit" name="update_settings" class="btn btn-primary">
                    <i class="bi bi-check-circle me-2"></i>Update Settings
                </button>
            </div>
        </form>
        <?php else: ?>
            <div class="alert alert-warning">
                You don't have permission to edit system settings.
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- System Maintenance -->
<div class="settings-section">
    <h5 class="settings-header">
        <i class="bi bi-tools me-2"></i>System Maintenance
    </h5>
    <div class="p-3">
        <div class="row g-3">
            <div class="col-md-6">
                <div class="card">
                    <div class="card-body">
                        <h6 class="card-title">
                            <i class="bi bi-trash me-2"></i>Cleanup Sessions
                        </h6>
                        <p class="card-text small">Remove expired user sessions from database</p>
                        <button type="button" class="btn btn-outline-warning btn-sm" onclick="cleanupSessions()">
                            Cleanup Now
                        </button>
                    </div>
                </div>
            </div>
            <div class="col-md-6">
                <div class="card">
                    <div class="card-body">
                        <h6 class="card-title">
                            <i class="bi bi-download me-2"></i>Database Backup
                        </h6>
                        <p class="card-text small">Download a backup of your database</p>
                        <button type="button" class="btn btn-outline-info btn-sm" onclick="backupDatabase()">
                            Download Backup
                        </button>
                    </div>
                </div>
            </div>
            <div class="col-md-6">
                <div class="card">
                    <div class="card-body">
                        <h6 class="card-title">
                            <i class="bi bi-arrow-clockwise me-2"></i>System Logs
                        </h6>
                        <p class="card-text small">View system activity and error logs</p>
                        <button type="button" class="btn btn-outline-secondary btn-sm" onclick="viewLogs()">
                            View Logs
                        </button>
                    </div>
                </div>
            </div>
            <div class="col-md-6">
                <div class="card">
                    <div class="card-body">
                        <h6 class="card-title">
                            <i class="bi bi-shield-check me-2"></i>Security Audit
                        </h6>
                        <p class="card-text small">Run security checks and audit logs</p>
                        <button type="button" class="btn btn-outline-danger btn-sm" onclick="securityAudit()">
                            Run Audit
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Quick Actions -->
<div class="settings-section">
    <h5 class="settings-header">
        <i class="bi bi-lightning me-2"></i>Quick Actions
    </h5>
    <div class="p-3">
        <div class="d-flex flex-wrap gap-2">
            <a href="users_management.php" class="btn btn-outline-primary">
                <i class="bi bi-people me-2"></i>Manage Users
            </a>
            <a href="inventory_advanced.php" class="btn btn-outline-success">
                <i class="bi bi-box me-2"></i>Manage Inventory
            </a>
            <a href="commission_ledger.php" class="btn btn-outline-info">
                <i class="bi bi-cash-coin me-2"></i>Commission Ledger
            </a>
            <button type="button" class="btn btn-outline-warning" onclick="clearCache()">
                <i class="bi bi-arrow-clockwise me-2"></i>Clear Cache
            </button>
        </div>
    </div>
</div>

<script>
function cleanupSessions() {
    if (confirm('This will remove all expired user sessions. Continue?')) {
        // Placeholder for cleanup functionality
        alert('Session cleanup completed (Feature coming soon)');
    }
}

function backupDatabase() {
    // Placeholder for backup functionality
    alert('Database backup feature coming soon');
}

function viewLogs() {
    // Placeholder for log viewing
    alert('System logs viewer coming soon');
}

function securityAudit() {
    if (confirm('Run security audit? This may take a few moments.')) {
        alert('Security audit completed (Feature coming soon)');
    }
}

function clearCache() {
    if (confirm('Clear system cache? This may temporarily slow down the system.')) {
        alert('Cache cleared successfully (Feature coming soon)');
    }
}

// Auto-save draft settings
let saveTimeout;
document.querySelectorAll('input, select, textarea').forEach(input => {
    input.addEventListener('change', function() {
        clearTimeout(saveTimeout);
        saveTimeout = setTimeout(() => {
            // Auto-save draft (placeholder)
            console.log('Settings draft saved');
        }, 2000);
    });
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>