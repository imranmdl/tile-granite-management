<?php
require_once __DIR__ . '/../includes/auth.php';

// Check if user is already logged in
if (auth_user()) {
    header('Location: /index.php');
    exit;
}

$msg = null;
$success_msg = null;

// Handle logout message
if (isset($_GET['message'])) {
    $success_msg = $_GET['message'];
}

if ($_SERVER['REQUEST_METHOD']==='POST') {
    $u = trim($_POST['username'] ?? '');
    $p = $_POST['password'] ?? '';
    
    // Try enhanced authentication first, fallback to basic
    $success = false;
    
    if (class_exists('AuthSystem')) {
        $result = AuthSystem::authenticate($u, $p);
        $success = $result['success'];
        if (!$success) {
            $msg = $result['message'];
        }
    } else {
        $success = auth_login_password($u, $p);
        if (!$success) {
            $msg = "Invalid credentials or inactive account.";
        }
    }
    
    if ($success) {
        header('Location: /public/index.php');
        exit;
    }
}
?><!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Login</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet"></head>
<body class="bg-light d-flex align-items-center" style="min-height:100vh">
<div class="container"><div class="row justify-content-center"><div class="col-md-4">
<div class="card shadow-sm mt-5"><div class="card-body">
<h3 class="mb-3 text-center">🧱 Tile Suite</h3>
<?php if ($msg): ?><div class="alert alert-danger"><?= htmlspecialchars($msg) ?></div><?php endif; ?>
<?php if ($success_msg): ?><div class="alert alert-success"><?= htmlspecialchars($success_msg) ?></div><?php endif; ?>
<form method="post">
  <div class="mb-3"><label class="form-label">Username</label><input class="form-control" name="username" required></div>
  <div class="mb-3"><label class="form-label">Password</label><input class="form-control" name="password" type="password" required></div>
  <button class="btn btn-primary w-100">Login</button>
  <div class="mt-3">
    <p class="text-muted small">Test Accounts:</p>
    <small class="text-muted">
      Admin: <code>admin / admin123</code><br>
      Manager: <code>manager1 / manager123</code><br>
      Sales: <code>sales1 / sales123</code>
    </small>
    <?php if (class_exists('AuthSystem')): ?>
        <p class="text-success small mt-2">✅ Enhanced Auth System Active</p>
    <?php else: ?>
        <p class="text-warning small mt-2">⚠️ Basic Auth System</p>
    <?php endif; ?>
  </div>
</form>
</div></div></div></div></div></body></html>
