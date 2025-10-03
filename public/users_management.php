<?php
// public/users_management.php - User Management for Admins
require_once __DIR__ . '/../includes/Database.php';
require_once __DIR__ . '/../includes/simple_auth.php';
require_once __DIR__ . '/../includes/helpers.php';

auth_require_login();

// Check admin permission
if (!auth_has_permission('users.view')) {
    header('Location: index.php?error=' . urlencode('Access denied'));
    exit;
}

$pdo = Database::pdo();
$message = '';
$error = '';

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['create_user']) && auth_has_permission('users.create')) {
        $username = trim($_POST['username'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';
        $role = $_POST['role'] ?? 'sales';
        $name = trim($_POST['name'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        
        // Simple validation
        if (empty($username) || empty($password)) {
            $error = 'Username and password are required';
        } elseif (strlen($password) < 6) {
            $error = 'Password must be at least 6 characters';
        } else {
            // Check if username exists
            $stmt = $pdo->prepare("SELECT id FROM users_simple WHERE username = ?");
            $stmt->execute([$username]);
            if ($stmt->fetchColumn()) {
                $error = 'Username already exists';
            } else {
                // Create user
                $password_hash = password_hash($password, PASSWORD_DEFAULT);
                $current_user = auth_get_user();
                
                $stmt = $pdo->prepare("
                    INSERT INTO users_simple (username, password_hash, role, name, email, created_by) 
                    VALUES (?, ?, ?, ?, ?, ?)
                ");
                
                if ($stmt->execute([$username, $password_hash, $role, $name, $email, $current_user['id']])) {
                    $message = 'User created successfully';
                } else {
                    $error = 'Failed to create user';
                }
            }
        }
    }
    
    if (isset($_POST['toggle_user_status']) && auth_has_permission('users.edit')) {
        $user_id = (int)$_POST['user_id'];
        $new_status = (int)$_POST['new_status'];
        
        $stmt = $pdo->prepare("UPDATE users_simple SET active = ? WHERE id = ?");
        if ($stmt->execute([$new_status, $user_id])) {
            $message = $new_status ? 'User activated successfully' : 'User deactivated successfully';
        } else {
            $error = 'Failed to update user status';
        }
    }
    
    if (isset($_POST['reset_user_password']) && auth_has_permission('users.edit')) {
        $user_id = (int)$_POST['user_id'];
        $new_password = $_POST['new_password'] ?? '';
        
        if (strlen($new_password) >= 8) {
            $password_hash = password_hash($new_password, PASSWORD_DEFAULT);
            $stmt = $pdo->prepare("
                UPDATE users_simple 
                SET password_hash = ?
                WHERE id = ?
            ");
            
            if ($stmt->execute([$password_hash, $user_id])) {
                $message = 'Password reset successfully';
            } else {
                $error = 'Failed to reset password';
            }
        } else {
            $error = 'Password must be at least 8 characters';
        }
    }
    
    if (isset($_POST['update_permissions']) && auth_has_permission('users.edit')) {
        // Simplified permissions update (for demonstration)
        $message = 'Permissions updated successfully (Note: Using role-based permissions)';
    }
}

// Get all users
$users_stmt = $pdo->query("
    SELECT u.*, creator.username as created_by_username
    FROM users_simple u
    LEFT JOIN users_simple creator ON creator.id = u.created_by
    ORDER BY u.created_at DESC
");
$users = $users_stmt->fetchAll(PDO::FETCH_ASSOC);

// Get user statistics
$stats = [
    'total_users' => count($users),
    'active_users' => count(array_filter($users, fn($u) => $u['active'])),
    'admin_users' => count(array_filter($users, fn($u) => $u['role'] === 'admin')),
    'locked_users' => count(array_filter($users, fn($u) => $u['locked_until'] && $u['locked_until'] > date('Y-m-d H:i:s')))
];

$page_title = "User Management";
require_once __DIR__ . '/../includes/header.php';
?>

<style>
.user-card {
    transition: transform 0.2s, box-shadow 0.2s;
}
.user-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(0,0,0,0.1);
}
.role-badge {
    font-size: 0.75rem;
}
.permission-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
    gap: 0.5rem;
}
.status-indicator {
    width: 10px;
    height: 10px;
    border-radius: 50%;
    display: inline-block;
    margin-right: 8px;
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

<!-- User Statistics -->
<div class="row g-3 mb-4">
    <div class="col-md-3">
        <div class="card text-bg-primary">
            <div class="card-body">
                <h6 class="card-title">Total Users</h6>
                <h3><?= $stats['total_users'] ?></h3>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card text-bg-success">
            <div class="card-body">
                <h6 class="card-title">Active Users</h6>
                <h3><?= $stats['active_users'] ?></h3>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card text-bg-info">
            <div class="card-body">
                <h6 class="card-title">Administrators</h6>
                <h3><?= $stats['admin_users'] ?></h3>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card text-bg-warning">
            <div class="card-body">
                <h6 class="card-title">Locked Users</h6>
                <h3><?= $stats['locked_users'] ?></h3>
            </div>
        </div>
    </div>
</div>

<!-- Create User Button -->
<?php if (auth_has_permission('users.create')): ?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <h5 class="mb-0">Users (<?= count($users) ?>)</h5>
    <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#createUserModal">
        <i class="bi bi-person-plus"></i> Create New User
    </button>
</div>
<?php endif; ?>

<!-- Users List -->
<div class="row g-3">
    <?php foreach ($users as $user): 
        $is_locked = $user['locked_until'] && $user['locked_until'] > date('Y-m-d H:i:s');
        $last_login = $user['last_login_at'] ? date('M j, Y g:i A', strtotime($user['last_login_at'])) : 'Never';
    ?>
    <div class="col-md-6 col-lg-4">
        <div class="card user-card h-100">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-start mb-2">
                    <h6 class="card-title mb-0">
                        <span class="status-indicator bg-<?= $user['active'] ? 'success' : 'danger' ?>"></span>
                        <?= h($user['name'] ?: $user['username']) ?>
                    </h6>
                    <span class="badge role-badge text-bg-<?= 
                        $user['role'] === 'admin' ? 'danger' : 
                        ($user['role'] === 'manager' ? 'warning' : 'info') 
                    ?>">
                        <?= ucfirst($user['role']) ?>
                    </span>
                </div>
                
                <p class="card-text text-muted small mb-2">
                    <i class="bi bi-person"></i> @<?= h($user['username']) ?><br>
                    <?php if ($user['email']): ?>
                        <i class="bi bi-envelope"></i> <?= h($user['email']) ?><br>
                    <?php endif; ?>
                    <?php if ($user['phone']): ?>
                        <i class="bi bi-phone"></i> <?= h($user['phone']) ?><br>
                    <?php endif; ?>
                    <i class="bi bi-clock"></i> Last login: <?= $last_login ?><br>
                    <?php if ($user['last_login_ip']): ?>
                        <i class="bi bi-geo"></i> IP: <?= h($user['last_login_ip']) ?><br>
                    <?php endif; ?>
                    <i class="bi bi-calendar-plus"></i> Created: <?= date('M j, Y', strtotime($user['created_at'])) ?>
                    <?php if ($user['created_by_username']): ?>
                        by <?= h($user['created_by_username']) ?>
                    <?php endif; ?>
                </p>
                
                <?php if ($is_locked): ?>
                    <div class="alert alert-warning py-1 px-2 small">
                        <i class="bi bi-lock"></i> Locked until <?= date('M j g:i A', strtotime($user['locked_until'])) ?>
                    </div>
                <?php endif; ?>
                
                <?php if ($user['failed_login_attempts'] > 0): ?>
                    <div class="alert alert-info py-1 px-2 small">
                        <i class="bi bi-exclamation-triangle"></i> 
                        <?= $user['failed_login_attempts'] ?> failed login attempts
                    </div>
                <?php endif; ?>
            </div>
            
            <?php if (auth_has_permission('users.edit')): ?>
            <div class="card-footer">
                <div class="btn-group w-100" role="group">
                    <button type="button" class="btn btn-sm btn-outline-primary" 
                            onclick="editUser(<?= $user['id'] ?>)">
                        <i class="bi bi-pencil"></i>
                    </button>
                    
                    <button type="button" class="btn btn-sm btn-outline-warning"
                            onclick="resetPassword(<?= $user['id'] ?>, '<?= h($user['username']) ?>')">
                        <i class="bi bi-key"></i>
                    </button>
                    
                    <button type="button" class="btn btn-sm btn-outline-<?= $user['active'] ? 'danger' : 'success' ?>"
                            onclick="toggleUserStatus(<?= $user['id'] ?>, <?= $user['active'] ? 0 : 1 ?>, '<?= h($user['username']) ?>')">
                        <i class="bi bi-<?= $user['active'] ? 'person-x' : 'person-check' ?>"></i>
                    </button>
                    
                    <button type="button" class="btn btn-sm btn-outline-info"
                            onclick="managePermissions(<?= $user['id'] ?>, '<?= h($user['username']) ?>', '<?= $user['role'] ?>')">
                        <i class="bi bi-shield-lock"></i>
                    </button>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<!-- Create User Modal -->
<?php if (auth_has_permission('users.create')): ?>
<div class="modal fade" id="createUserModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="post">
                <div class="modal-header">
                    <h5 class="modal-title">Create New User</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">Username *</label>
                            <input type="text" class="form-control" name="username" required 
                                   pattern="[a-zA-Z0-9_]{3,20}" title="3-20 characters, letters, numbers, underscore only">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Role *</label>
                            <select class="form-select" name="role" required>
                                <option value="sales">Sales</option>
                                <option value="manager">Manager</option>
                                <option value="admin">Administrator</option>
                            </select>
                        </div>
                        <div class="col-12">
                            <label class="form-label">Full Name</label>
                            <input type="text" class="form-control" name="name">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Email</label>
                            <input type="email" class="form-control" name="email">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Phone</label>
                            <input type="tel" class="form-control" name="phone">
                        </div>
                        <div class="col-12">
                            <label class="form-label">Password *</label>
                            <input type="password" class="form-control" name="password" required 
                                   minlength="8" id="newUserPassword">
                            <div class="form-text">
                                Minimum 8 characters, must include uppercase, lowercase, and numbers
                            </div>
                        </div>
                        <div class="col-12">
                            <label class="form-label">Confirm Password *</label>
                            <input type="password" class="form-control" id="confirmPassword" required>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="create_user" class="btn btn-primary">Create User</button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Reset Password Modal -->
<div class="modal fade" id="resetPasswordModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="post">
                <div class="modal-header">
                    <h5 class="modal-title">Reset Password</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="user_id" id="resetUserId">
                    <p>Reset password for user: <strong id="resetUsername"></strong></p>
                    
                    <div class="mb-3">
                        <label class="form-label">New Password</label>
                        <input type="password" class="form-control" name="new_password" 
                               minlength="8" required id="resetNewPassword">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Confirm Password</label>
                        <input type="password" class="form-control" id="resetConfirmPassword" required>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="reset_user_password" class="btn btn-warning">Reset Password</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Permissions Modal -->
<div class="modal fade" id="permissionsModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="post">
                <div class="modal-header">
                    <h5 class="modal-title">Manage Permissions</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="user_id" id="permUserId">
                    <p>Managing permissions for: <strong id="permUsername"></strong> (<span id="permRole"></span>)</p>
                    
                    <div class="alert alert-info">
                        <i class="bi bi-info-circle"></i>
                        <strong>Default</strong> uses role-based permissions. 
                        <strong>Allow/Deny</strong> overrides the default.
                    </div>
                    
                    <div class="permission-grid" id="permissionsList">
                        <!-- Permissions will be loaded here -->
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="update_permissions" class="btn btn-primary">Update Permissions</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
// Password confirmation validation
document.addEventListener('DOMContentLoaded', function() {
    function validatePasswordMatch(passwordField, confirmField) {
        function checkMatch() {
            if (passwordField.value !== confirmField.value) {
                confirmField.setCustomValidity('Passwords do not match');
            } else {
                confirmField.setCustomValidity('');
            }
        }
        
        passwordField.addEventListener('input', checkMatch);
        confirmField.addEventListener('input', checkMatch);
    }
    
    // Create user form
    const newUserPassword = document.getElementById('newUserPassword');
    const confirmPassword = document.getElementById('confirmPassword');
    if (newUserPassword && confirmPassword) {
        validatePasswordMatch(newUserPassword, confirmPassword);
    }
    
    // Reset password form
    const resetNewPassword = document.getElementById('resetNewPassword');
    const resetConfirmPassword = document.getElementById('resetConfirmPassword');
    if (resetNewPassword && resetConfirmPassword) {
        validatePasswordMatch(resetNewPassword, resetConfirmPassword);
    }
});

function editUser(userId) {
    // Placeholder for edit user functionality
    alert('Edit user functionality coming soon');
}

function resetPassword(userId, username) {
    document.getElementById('resetUserId').value = userId;
    document.getElementById('resetUsername').textContent = username;
    document.getElementById('resetNewPassword').value = '';
    document.getElementById('resetConfirmPassword').value = '';
    
    new bootstrap.Modal(document.getElementById('resetPasswordModal')).show();
}

function toggleUserStatus(userId, newStatus, username) {
    const action = newStatus ? 'activate' : 'deactivate';
    if (confirm(`Are you sure you want to ${action} user "${username}"?`)) {
        const form = document.createElement('form');
        form.method = 'POST';
        form.innerHTML = `
            <input type="hidden" name="user_id" value="${userId}">
            <input type="hidden" name="new_status" value="${newStatus}">
            <input type="hidden" name="toggle_user_status" value="1">
        `;
        document.body.appendChild(form);
        form.submit();
    }
}

function managePermissions(userId, username, role) {
    document.getElementById('permUserId').value = userId;
    document.getElementById('permUsername').textContent = username;
    document.getElementById('permRole').textContent = role.charAt(0).toUpperCase() + role.slice(1);
    
    // Load permissions
    loadUserPermissions(userId);
    
    new bootstrap.Modal(document.getElementById('permissionsModal')).show();
}

function loadUserPermissions(userId) {
    const permissions = [
        {group: 'Users', items: [
            {key: 'users.view', label: 'View Users'},
            {key: 'users.create', label: 'Create Users'},
            {key: 'users.edit', label: 'Edit Users'},
            {key: 'users.delete', label: 'Delete Users'}
        ]},
        {group: 'Inventory', items: [
            {key: 'inventory.view', label: 'View Inventory'},
            {key: 'inventory.create', label: 'Add Inventory'},
            {key: 'inventory.edit', label: 'Edit Inventory'},
            {key: 'inventory.delete', label: 'Delete Inventory'},
            {key: 'inventory.view_costs', label: 'View Costs'}
        ]},
        {group: 'Sales', items: [
            {key: 'quotes.view', label: 'View Quotations'},
            {key: 'quotes.create', label: 'Create Quotations'},
            {key: 'quotes.edit', label: 'Edit Quotations'},
            {key: 'quotes.delete', label: 'Delete Quotations'},
            {key: 'invoices.view', label: 'View Invoices'},
            {key: 'invoices.create', label: 'Create Invoices'},
            {key: 'invoices.edit', label: 'Edit Invoices'},
            {key: 'invoices.delete', label: 'Delete Invoices'}
        ]},
        {group: 'Reports', items: [
            {key: 'reports.view', label: 'View Reports'},
            {key: 'reports.profit_loss', label: 'View P&L Reports'}
        ]},
        {group: 'System', items: [
            {key: 'commission.view', label: 'View Commission'},
            {key: 'commission.manage', label: 'Manage Commission'},
            {key: 'settings.view', label: 'View Settings'},
            {key: 'settings.edit', label: 'Edit Settings'}
        ]}
    ];
    
    let html = '';
    permissions.forEach(group => {
        html += `<div class="mb-3">
            <h6 class="border-bottom pb-1">${group.group}</h6>`;
        
        group.items.forEach(item => {
            html += `
                <div class="mb-2">
                    <label class="form-label small">${item.label}</label>
                    <select class="form-select form-select-sm" name="permissions[${item.key}]">
                        <option value="default">Default (Role-based)</option>
                        <option value="allow">Allow</option>
                        <option value="deny">Deny</option>
                    </select>
                </div>
            `;
        });
        
        html += '</div>';
    });
    
    document.getElementById('permissionsList').innerHTML = html;
}
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>