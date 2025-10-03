<?php
require_once __DIR__ . '/../includes/header.php';
require_login(['admin','manager']);
$pdo = Database::pdo();

if ($_SERVER['REQUEST_METHOD']==='POST') {
  if (isset($_POST['toggle_active'])) {
    $id=(int)$_POST['id'];
    $pdo->prepare("UPDATE users SET active = CASE active WHEN 1 THEN 0 ELSE 1 END WHERE id=?")->execute([$id]);
  }
  if (isset($_POST['change_role'])) {
    $id=(int)$_POST['id']; $role=$_POST['role'] ?? 'sales';
    $pdo->prepare("UPDATE users SET role=? WHERE id=?")->execute([$role,$id]);
  }
  if (isset($_POST['reset_pass'])) {
    $id=(int)$_POST['id']; $hash=password_hash('pass123', PASSWORD_BCRYPT);
    $pdo->prepare("UPDATE users SET password_hash=? WHERE id=?")->execute([$hash,$id]);
  }
  header('Location: /public/users.php'); exit;
}

$rows=$pdo->query("SELECT id, username, role, active, created_at FROM users ORDER BY id DESC")->fetchAll();
?>
<div class="card p-3">
  <h5 class="mb-3">Users</h5>
  <div class="table-responsive">
    <table class="table table-striped align-middle">
      <thead><tr><th>ID</th><th>Username</th><th>Role</th><th>Active</th><th>Created</th><th>Actions</th></tr></thead>
      <tbody>
      <?php foreach($rows as $r): ?>
        <tr>
          <td><?=$r['id']?></td>
          <td><?=htmlspecialchars($r['username'])?></td>
          <td>
            <form method="post" class="d-flex gap-2 align-items-center">
              <input type="hidden" name="id" value="<?=$r['id']?>">
              <select name="role" class="form-select form-select-sm" style="max-width:140px">
                <?php foreach(['sales','manager','admin'] as $role): ?>
                  <option value="<?=$role?>" <?=$r['role']===$role?'selected':''?>><?=$role?></option>
                <?php endforeach; ?>
              </select>
              <button class="btn btn-sm btn-outline-primary" name="change_role" value="1">Update</button>
            </form>
          </td>
          <td><span class="badge bg-<?=$r['active']?'success':'secondary'?>"><?=$r['active']?'Active':'Inactive'?></span></td>
          <td><?=substr((string)$r['created_at'],0,19)?></td>
          <td class="d-flex gap-2">
            <form method="post"><input type="hidden" name="id" value="<?=$r['id']?>"><button class="btn btn-sm btn-outline-warning" name="toggle_active" value="1"><?=$r['active']?'Deactivate':'Activate'?></button></form>
            <form method="post"><input type="hidden" name="id" value="<?=$r['id']?>"><button class="btn btn-sm btn-outline-danger" name="reset_pass" value="1">Reset Pass</button></form>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
