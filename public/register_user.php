<?php
require_once __DIR__ . '/../includes/header.php';
require_login(['admin','manager']);
$pdo = Database::pdo();
$msg=null; $err=null;
if ($_SERVER['REQUEST_METHOD']==='POST') {
  $u = trim($_POST['username'] ?? '');
  $p = $_POST['password'] ?? 'pass123';
  $role = $_POST['role'] ?? 'sales';
  $active = isset($_POST['active']) ? 1 : 0;
  if ($u==='') $err="Username required";
  else {
    try{
      $hash = password_hash($p, PASSWORD_BCRYPT);
      $st=$pdo->prepare("INSERT INTO users(username,password_hash,role,active) VALUES(?,?,?,?)");
      $st->execute([$u,$hash,$role,$active]);
      $msg="User created.";
    }catch(Throwable $e){ $err="Error: ".$e->getMessage(); }
  }
}
?>
<div class="card p-3">
  <h5 class="mb-3">Register User</h5>
  <?php if($msg): ?><div class="alert alert-success"><?= htmlspecialchars($msg) ?></div><?php endif; ?>
  <?php if($err): ?><div class="alert alert-danger"><?= htmlspecialchars($err) ?></div><?php endif; ?>
  <form method="post" class="row g-3">
    <div class="col-md-4"><label class="form-label">Username</label><input name="username" class="form-control" required></div>
    <div class="col-md-4"><label class="form-label">Password</label><input name="password" class="form-control" value="pass123"></div>
    <div class="col-md-3"><label class="form-label">Role</label>
      <select name="role" class="form-select">
        <option value="sales">sales</option>
        <option value="manager">manager</option>
        <option value="admin">admin</option>
      </select>
    </div>
    <div class="col-md-1 d-flex align-items-end">
      <div class="form-check"><input class="form-check-input" type="checkbox" name="active" checked> <label class="form-check-label">Active</label></div>
    </div>
    <div class="col-12"><button class="btn btn-primary">Create</button></div>
  </form>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
