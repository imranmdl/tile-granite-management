<?php
// public/login_enhanced.php - Enhanced Login System
require_once __DIR__ . '/../includes/Database.php';
require_once __DIR__ . '/../includes/auth_enhanced.php';

// Initialize auth system
AuthSystem::init();

// If already logged in, redirect to dashboard
if (AuthSystem::isLoggedIn()) {
    header('Location: index.php');
    exit;
}

$error_message = '';
$success_message = '';

// Handle login
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['login'])) {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $otp = trim($_POST['otp'] ?? '');
    
    if ($username && $password) {
        $result = AuthSystem::authenticate($username, $password, $otp);
        
        if ($result['success']) {
            $redirect = $_GET['redirect'] ?? 'index.php';
            header('Location: ' . $redirect);
            exit;
        } else {
            $error_message = $result['message'];
        }
    } else {
        $error_message = 'Please enter username and password';
    }
}

// Handle password reset request
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['reset_password'])) {
    $username = trim($_POST['reset_username'] ?? '');
    // Placeholder for password reset logic
    $success_message = 'Password reset instructions sent (Feature coming soon)';
}

$company_name = AuthSystem::getSetting('company_name', 'Tile Suite Business');
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
            max-width: 400px;
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
            padding: 2rem;
            text-align: center;
        }
        
        .login-body {
            padding: 2rem;
        }
        
        .form-control {
            border-radius: 10px;
            border: 2px solid #f1f3f4;
            padding: 0.75rem 1rem;
        }
        
        .form-control:focus {
            border-color: var(--brand-primary);
            box-shadow: 0 0 0 0.2rem rgba(138, 36, 61, 0.25);
        }
        
        .btn-login {
            background: linear-gradient(135deg, var(--brand-primary), var(--brand-secondary));
            border: none;
            border-radius: 10px;
            padding: 0.75rem 2rem;
            color: white;
            font-weight: 600;
            width: 100%;
        }
        
        .btn-login:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 20px rgba(0, 0, 0, 0.2);
            color: white;
        }
        
        .login-footer {
            text-align: center;
            color: #6c757d;
            font-size: 0.875rem;
        }
        
        .input-group-text {
            background: transparent;
            border: 2px solid #f1f3f4;
            border-right: none;
        }
        
        .input-group .form-control {
            border-left: none;
        }
        
        .alert {
            border-radius: 10px;
            border: none;
        }
        
        .brand-icon {
            font-size: 3rem;
            margin-bottom: 1rem;
        }
        
        .password-toggle {
            cursor: pointer;
            padding: 0.5rem;
        }
        
        .session-info {
            background: rgba(255, 255, 255, 0.1);
            border-radius: 10px;
            padding: 1rem;
            margin-top: 1rem;
            font-size: 0.875rem;
        }
        
        .reset-link {
            color: var(--brand-primary);
            text-decoration: none;
            font-size: 0.875rem;
        }
        
        .reset-link:hover {
            color: var(--brand-secondary);
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="login-container">
            <div class="login-card">
                <div class="login-header">
                    <i class="bi bi-bricks brand-icon"></i>
                    <h2 class="mb-0"><?= htmlspecialchars($company_name) ?></h2>
                    <p class="mb-0">Business Management System</p>
                    <div class="session-info">
                        <small>
                            <i class="bi bi-clock"></i> Session Timeout: 
                            <?= AuthSystem::getSetting('session_timeout_minutes', 300) ?> minutes
                        </small>
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
                            <label for="username" class="form-label">Username</label>
                            <div class="input-group">
                                <span class="input-group-text">
                                    <i class="bi bi-person"></i>
                                </span>
                                <input type="text" class="form-control" id="username" name="username" 
                                       value="<?= htmlspecialchars($_POST['username'] ?? '') ?>" 
                                       required autocomplete="username">
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <label for="password" class="form-label">Password</label>
                            <div class="input-group">
                                <span class="input-group-text">
                                    <i class="bi bi-lock"></i>
                                </span>
                                <input type="password" class="form-control" id="password" name="password" 
                                       required autocomplete="current-password">
                                <span class="input-group-text password-toggle" onclick="togglePassword()">
                                    <i class="bi bi-eye" id="passwordToggleIcon"></i>
                                </span>
                            </div>
                        </div>
                        
                        <div class="mb-3" id="otpField" style="display: none;">
                            <label for="otp" class="form-label">OTP Code</label>
                            <div class="input-group">
                                <span class="input-group-text">
                                    <i class="bi bi-shield-lock"></i>
                                </span>
                                <input type="text" class="form-control" id="otp" name="otp" 
                                       placeholder="Enter 6-digit OTP" maxlength="6">
                            </div>
                            <div class="form-text">Enter the 6-digit code from your authenticator app</div>
                        </div>
                        
                        <div class="mb-3 form-check">
                            <input type="checkbox" class="form-check-input" id="rememberMe">
                            <label class="form-check-label" for="rememberMe">
                                Keep me logged in
                            </label>
                        </div>
                        
                        <button type="submit" name="login" class="btn btn-login">
                            <i class="bi bi-box-arrow-in-right me-2"></i>
                            Sign In
                        </button>
                    </form>
                    
                    <div class="text-center mt-3">
                        <a href="#" class="reset-link" onclick="showPasswordReset()">
                            Forgot your password?
                        </a>
                    </div>
                </div>
                
                <!-- Password Reset Modal -->
                <div class="modal fade" id="passwordResetModal" tabindex="-1">
                    <div class="modal-dialog">
                        <div class="modal-content">
                            <div class="modal-header">
                                <h5 class="modal-title">Reset Password</h5>
                                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                            </div>
                            <form method="POST">
                                <div class="modal-body">
                                    <div class="mb-3">
                                        <label for="reset_username" class="form-label">Username or Email</label>
                                        <input type="text" class="form-control" id="reset_username" 
                                               name="reset_username" required>
                                    </div>
                                    <div class="alert alert-info">
                                        <i class="bi bi-info-circle me-2"></i>
                                        Password reset instructions will be sent to your registered email.
                                    </div>
                                </div>
                                <div class="modal-footer">
                                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                    <button type="submit" name="reset_password" class="btn btn-primary">
                                        Send Reset Instructions
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
                
                <div class="login-footer p-3">
                    <p class="mb-1">
                        <i class="bi bi-shield-check text-success"></i>
                        Secure Login System
                    </p>
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
        
        function showPasswordReset() {
            new bootstrap.Modal(document.getElementById('passwordResetModal')).show();
        }
        
        // Check if OTP is enabled for the user (placeholder)
        document.getElementById('username').addEventListener('blur', function() {
            const username = this.value.trim();
            if (username) {
                // Placeholder: Check if user has OTP enabled
                // For now, show OTP field for demo
                // document.getElementById('otpField').style.display = 'block';
            }
        });
        
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
        
        // Session timeout warning (placeholder)
        let sessionTimeout = <?= AuthSystem::getSetting('session_timeout_minutes', 300) * 60 * 1000 ?>;
        let warningTime = sessionTimeout - (5 * 60 * 1000); // 5 minutes before timeout
        
        // Add form validation
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
            submitBtn.innerHTML = '<i class="bi bi-hourglass-split me-2"></i>Signing In...';
            submitBtn.disabled = true;
            
            // Re-enable button after 10 seconds (in case of network issues)
            setTimeout(() => {
                submitBtn.innerHTML = originalText;
                submitBtn.disabled = false;
            }, 10000);
        });
    </script>
</body>
</html>