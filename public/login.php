<?php
require_once __DIR__ . '/../includes/auth.php';
$msg = null;
if ($_SERVER['REQUEST_METHOD']==='POST') {
  $u = trim($_POST['username'] ?? '');
  $p = $_POST['password'] ?? '';
  if (auth_login_password($u, $p)) { header('Location: /public/index.php'); exit; }
  $msg = "Invalid credentials or inactive account.";
}
?><!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Login</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet"></head>
<body class="bg-light d-flex align-items-center" style="min-height:100vh">
<div class="container"><div class="row justify-content-center"><div class="col-md-4">
<div class="card shadow-sm mt-5"><div class="card-body">
<h3 class="mb-3 text-center">🧱 Tile Suite</h3>
<?php if ($msg): ?><div class="alert alert-danger"><?= htmlspecialchars($msg) ?></div><?php endif; ?>
<form method="post">
  <div class="mb-3"><label class="form-label">Username</label><input class="form-control" name="username" required></div>
  <div class="mb-3"><label class="form-label">Password</label><input class="form-control" name="password" type="password" required></div>
  <button class="btn btn-primary w-100">Login</button>
  <p class="text-muted small mt-3">Default admin: <code>admin / admin123</code></p>
</form>
</div></div></div></div></div></body></html>
