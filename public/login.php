<?php
// public/login.php - Modern Login System
require_once __DIR__ . '/../includes/simple_auth.php';

$error_message = '';
$success_message = '';

// Handle logout message
if (isset($_GET['message'])) {
    $success_message = $_GET['message'];
}

// If already logged in, redirect to dashboard
if (auth_is_logged_in()) {
    $redirect = $_GET['redirect'] ?? 'index.php';
    header('Location: ' . $redirect);
    exit;
}

// Handle login
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['login'])) {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    
    if ($username && $password) {
        if (auth_login($username, $password)) {
            $redirect = $_GET['redirect'] ?? 'index.php';
            header('Location: ' . $redirect);
            exit;
        } else {
            $error_message = 'Invalid username or password';
        }
    } else {
        $error_message = 'Please enter username and password';
    }
}

$company_name = 'Tile Suite Business';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - <?= htmlspecialchars($company_name) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        :root {
            --brand-primary: #8a243d;
            --brand-secondary: #0d3b66;
            --brand-accent: #ffd166;
        }
        
        body {
            background: linear-gradient(135deg, var(--brand-primary) 0%, var(--brand-secondary) 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
        }
        
        .login-container {
            max-width: 420px;
            margin: 0 auto;
        }
        
        .login-card {
            background: white;
            border-radius: 20px;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.2);
            overflow: hidden;
        }
        
        .login-header {
            background: linear-gradient(135deg, var(--brand-primary), var(--brand-secondary));
            color: white;
            padding: 2.5rem 2rem 2rem;
            text-align: center;
        }
        
        .login-body {
            padding: 2rem;
        }
        
        .form-control {
            border-radius: 12px;
            border: 2px solid #f1f3f4;
            padding: 0.875rem 1rem;
            font-size: 1rem;
        }
        
        .form-control:focus {
            border-color: var(--brand-primary);
            box-shadow: 0 0 0 0.2rem rgba(138, 36, 61, 0.25);
        }
        
        .btn-login {
            background: linear-gradient(135deg, var(--brand-primary), var(--brand-secondary));
            border: none;
            border-radius: 12px;
            padding: 0.875rem 2rem;
            color: white;
            font-weight: 600;
            width: 100%;
            font-size: 1.1rem;
            transition: all 0.3s ease;
        }
        
        .btn-login:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.2);
            color: white;
        }
        
        .input-group-text {
            background: transparent;
            border: 2px solid #f1f3f4;
            border-right: none;
            border-radius: 12px 0 0 12px;
        }
        
        .input-group .form-control {
            border-left: none;
            border-radius: 0 12px 12px 0;
        }
        
        .alert {
            border-radius: 12px;
            border: none;
            font-size: 0.95rem;
        }
        
        .brand-icon {
            font-size: 4rem;
            margin-bottom: 1rem;
            text-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        
        .password-toggle {
            cursor: pointer;
            padding: 0.5rem;
        }
        
        .test-accounts {
            background: rgba(255, 255, 255, 0.1);
            border-radius: 12px;
            padding: 1rem;
            margin-top: 1rem;
            font-size: 0.85rem;
        }
        
        .login-footer {
            text-align: center;
            color: #6c757d;
            font-size: 0.875rem;
            padding: 1.5rem;
            background: #f8f9fa;
        }
        
        .form-check-input:checked {
            background-color: var(--brand-primary);
            border-color: var(--brand-primary);
        }
        
        .features-list {
            list-style: none;
            padding: 0;
            margin: 0;
        }
        
        .features-list li {
            padding: 0.25rem 0;
            font-size: 0.9rem;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="login-container">
            <div class="login-card">
                <div class="login-header">
                    <i class="bi bi-bricks brand-icon"></i>
                    <h2 class="mb-1 fw-bold"><?= htmlspecialchars($company_name) ?></h2>
                    <p class="mb-0 opacity-75">Complete Business Management</p>
                    
                    <div class="test-accounts">
                        <h6 class="mb-2"><i class="bi bi-key"></i> Demo Credentials</h6>
                        <div class="row text-start">
                            <div class="col-6">
                                <strong>Admin:</strong><br>
                                <code>admin</code><br>
                                <code>admin123</code>
                            </div>
                            <div class="col-6">
                                <strong>Manager:</strong><br>
                                <code>manager1</code><br>
                                <code>manager123</code>
                            </div>
                        </div>
                        <div class="mt-2">
                            <strong>Sales:</strong> <code>sales1 / sales123</code>
                        </div>
                    </div>
                </div>
                
                <div class="login-body">
                    <?php if ($error_message): ?>
                        <div class="alert alert-danger alert-dismissible fade show" role="alert">
                            <i class="bi bi-exclamation-triangle me-2"></i>
                            <?= htmlspecialchars($error_message) ?>
                            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                        </div>
                    <?php endif; ?>
                    
                    <?php if ($success_message): ?>
                        <div class="alert alert-success alert-dismissible fade show" role="alert">
                            <i class="bi bi-check-circle me-2"></i>
                            <?= htmlspecialchars($success_message) ?>
                            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                        </div>
                    <?php endif; ?>
                    
                    <!-- Login Form -->
                    <form method="POST" id="loginForm">
                        <div class="mb-3">
                            <label for="username" class="form-label fw-semibold">Username</label>
                            <div class="input-group">
                                <span class="input-group-text">
                                    <i class="bi bi-person text-muted"></i>
                                </span>
                                <input type="text" class="form-control" id="username" name="username" 
                                       value="<?= htmlspecialchars($_POST['username'] ?? '') ?>" 
                                       placeholder="Enter username" required autocomplete="username">
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <label for="password" class="form-label fw-semibold">Password</label>
                            <div class="input-group">
                                <span class="input-group-text">
                                    <i class="bi bi-lock text-muted"></i>
                                </span>
                                <input type="password" class="form-control" id="password" name="password" 
                                       placeholder="Enter password" required autocomplete="current-password">
                                <span class="input-group-text password-toggle" onclick="togglePassword()">
                                    <i class="bi bi-eye text-muted" id="passwordToggleIcon"></i>
                                </span>
                            </div>
                        </div>
                        
                        <div class="mb-4 form-check">
                            <input type="checkbox" class="form-check-input" id="rememberMe">
                            <label class="form-check-label" for="rememberMe">
                                Keep me signed in
                            </label>
                        </div>
                        
                        <button type="submit" name="login" class="btn btn-login mb-3">
                            <i class="bi bi-box-arrow-in-right me-2"></i>
                            Sign In to Dashboard
                        </button>
                    </form>
                    
                    <div class="text-center">
                        <small class="text-muted">
                            <i class="bi bi-shield-check text-success"></i>
                            Secure authentication system
                        </small>
                    </div>
                </div>
                
                <div class="login-footer">
                    <div class="row g-0 text-center">
                        <div class="col-4">
                            <i class="bi bi-graph-up text-primary"></i><br>
                            <small>Sales</small>
                        </div>
                        <div class="col-4">
                            <i class="bi bi-box-seam text-success"></i><br>
                            <small>Inventory</small>
                        </div>
                        <div class="col-4">
                            <i class="bi bi-clipboard-data text-info"></i><br>
                            <small>Reports</small>
                        </div>
                    </div>
                    <hr class="my-3">
                    <small class="text-muted">
                        © <?= date('Y') ?> <?= htmlspecialchars($company_name) ?>. All rights reserved.
                    </small>
                </div>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function togglePassword() {
            const passwordField = document.getElementById('password');
            const toggleIcon = document.getElementById('passwordToggleIcon');
            
            if (passwordField.type === 'password') {
                passwordField.type = 'text';
                toggleIcon.classList.remove('bi-eye');
                toggleIcon.classList.add('bi-eye-slash');
            } else {
                passwordField.type = 'password';
                toggleIcon.classList.remove('bi-eye-slash');
                toggleIcon.classList.add('bi-eye');
            }
        }
        
        // Auto-focus on first empty field
        document.addEventListener('DOMContentLoaded', function() {
            const usernameField = document.getElementById('username');
            const passwordField = document.getElementById('password');
            
            if (!usernameField.value) {
                usernameField.focus();
            } else {
                passwordField.focus();
            }
        });
        
        // Add form validation and loading state
        document.getElementById('loginForm').addEventListener('submit', function(e) {
            const username = document.getElementById('username').value.trim();
            const password = document.getElementById('password').value;
            
            if (!username || !password) {
                e.preventDefault();
                alert('Please enter both username and password');
                return false;
            }
            
            // Show loading state
            const submitBtn = this.querySelector('button[type="submit"]');
            const originalText = submitBtn.innerHTML;
            submitBtn.innerHTML = '<i class="bi bi-hourglass-split me-2"></i>Signing in...';
            submitBtn.disabled = true;
            
            // Re-enable button after 10 seconds (in case of network issues)
            setTimeout(() => {
                submitBtn.innerHTML = originalText;
                submitBtn.disabled = false;
            }, 10000);
        });
        
        // Quick login functionality for demo accounts
        function quickLogin(username, password) {
            document.getElementById('username').value = username;
            document.getElementById('password').value = password;
            document.getElementById('loginForm').submit();
        }
    </script>
</body>
</html>
