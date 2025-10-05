<?php
// public/dashboard_test.php - Simple test dashboard
require_once __DIR__ . '/../includes/simple_auth.php';

auth_require_login();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard - Tile Suite</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
</head>
<body>
    <nav class="navbar navbar-expand-lg navbar-dark bg-primary">
        <div class="container">
            <a class="navbar-brand" href="#">
                <i class="bi bi-bricks me-2"></i>Tile Suite
            </a>
            <div class="navbar-nav ms-auto">
                <div class="nav-item dropdown">
                    <a class="nav-link dropdown-toggle" href="#" data-bs-toggle="dropdown">
                        <i class="bi bi-person-circle me-1"></i>
                        <?= htmlspecialchars(auth_username()) ?>
                        <span class="badge text-bg-light text-primary ms-1">
                            <?= htmlspecialchars(ucfirst(auth_role())) ?>
                        </span>
                    </a>
                    <ul class="dropdown-menu">
                        <li><a class="dropdown-item" href="logout_clean.php">
                            <i class="bi bi-box-arrow-right me-2"></i>Logout
                        </a></li>
                    </ul>
                </div>
            </div>
        </div>
    </nav>

    <div class="container mt-4">
        <div class="row">
            <div class="col-12">
                <div class="alert alert-success">
                    <h4><i class="bi bi-check-circle me-2"></i>Login Successful!</h4>
                    <p class="mb-0">You are now logged in to the Tile Suite system.</p>
                </div>
            </div>
        </div>

        <div class="row g-4">
            <!-- User Info Card -->
            <div class="col-md-6">
                <div class="card">
                    <div class="card-header">
                        <h5><i class="bi bi-person me-2"></i>User Information</h5>
                    </div>
                    <div class="card-body">
                        <table class="table table-borderless">
                            <tr>
                                <td><strong>Username:</strong></td>
                                <td><?= htmlspecialchars(auth_username()) ?></td>
                            </tr>
                            <tr>
                                <td><strong>Role:</strong></td>
                                <td>
                                    <span class="badge text-bg-<?= 
                                        auth_role() === 'admin' ? 'danger' : 
                                        (auth_role() === 'manager' ? 'warning' : 'info') 
                                    ?>">
                                        <?= htmlspecialchars(ucfirst(auth_role())) ?>
                                    </span>
                                </td>
                            </tr>
                            <tr>
                                <td><strong>User ID:</strong></td>
                                <td><?= auth_user_id() ?></td>
                            </tr>
                            <tr>
                                <td><strong>Admin Access:</strong></td>
                                <td><?= auth_is_admin() ? 
                                    '<span class="text-success">✓ Yes</span>' : 
                                    '<span class="text-muted">✗ No</span>' ?></td>
                            </tr>
                        </table>
                    </div>
                </div>
            </div>

            <!-- Permissions Test Card -->
            <div class="col-md-6">
                <div class="card">
                    <div class="card-header">
                        <h5><i class="bi bi-shield-check me-2"></i>Permissions Test</h5>
                    </div>
                    <div class="card-body">
                        <div class="list-group list-group-flush">
                            <?php
                            $permissions_to_test = [
                                'users.create' => 'Create Users',
                                'inventory.view' => 'View Inventory',
                                'inventory.create' => 'Create Inventory',
                                'inventory.view_costs' => 'View Costs',
                                'reports.profit_loss' => 'P&L Reports',
                                'settings.edit' => 'Edit Settings'
                            ];
                            
                            foreach ($permissions_to_test as $permission => $label):
                                $has_permission = auth_has_permission($permission);
                            ?>
                            <div class="list-group-item d-flex justify-content-between align-items-center">
                                <?= htmlspecialchars($label) ?>
                                <?php if ($has_permission): ?>
                                    <span class="badge text-bg-success">✓ Allowed</span>
                                <?php else: ?>
                                    <span class="badge text-bg-danger">✗ Denied</span>
                                <?php endif; ?>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="row mt-4">
            <div class="col-12">
                <div class="card">
                    <div class="card-header">
                        <h5><i class="bi bi-gear me-2"></i>System Status</h5>
                    </div>
                    <div class="card-body">
                        <div class="row g-3">
                            <div class="col-md-3">
                                <div class="text-center">
                                    <div class="text-success display-6">
                                        <i class="bi bi-check-circle"></i>
                                    </div>
                                    <h6 class="mt-2">Authentication</h6>
                                    <small class="text-muted">Working</small>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="text-center">
                                    <div class="text-success display-6">
                                        <i class="bi bi-shield-check"></i>
                                    </div>
                                    <h6 class="mt-2">Permissions</h6>
                                    <small class="text-muted">Active</small>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="text-center">
                                    <div class="text-success display-6">
                                        <i class="bi bi-database-check"></i>
                                    </div>
                                    <h6 class="mt-2">Database</h6>
                                    <small class="text-muted">Connected</small>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="text-center">
                                    <div class="text-success display-6">
                                        <i class="bi bi-clock-history"></i>
                                    </div>
                                    <h6 class="mt-2">Session</h6>
                                    <small class="text-muted">Active</small>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="row mt-4">
            <div class="col-12">
                <div class="card border-primary">
                    <div class="card-header bg-primary text-white">
                        <h5 class="mb-0"><i class="bi bi-info-circle me-2"></i>Next Steps</h5>
                    </div>
                    <div class="card-body">
                        <h6>🎉 Authentication System is Working!</h6>
                        <p>You can now:</p>
                        <ul>
                            <li><strong>Test different accounts:</strong> Try logging out and back in with different test accounts</li>
                            <li><strong>Role-based access:</strong> Notice how permissions change based on your role</li>
                            <li><strong>Integrate with existing pages:</strong> Replace <code>require_once 'auth.php'</code> with <code>require_once 'simple_auth.php'</code></li>
                        </ul>
                        
                        <div class="mt-3">
                            <a href="logout_clean.php" class="btn btn-outline-primary">
                                <i class="bi bi-box-arrow-right me-2"></i>Test Logout
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>