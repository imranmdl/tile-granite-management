<?php
$page_title="Commission Settings";
require_once __DIR__ . '/../includes/header.php';

require_login(['admin','manager']);
require_once __DIR__ . '/../includes/commission.php';
$pdo = Database::pdo();

list($usersTable,$userIdCol,$userKeyCol) = Commission::users_table($pdo);
if (!$usersTable) { echo "<div class='alert alert-danger m-3'>Users table not found.</div>"; return; }

if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['save_global'])) {
  $pct = (float)($_POST['global_pct'] ?? 0);
  $pdo->prepare("INSERT INTO commission_rates(scope, pct, active) VALUES('GLOBAL', ?, 1)")->execute([$pct]);
  header('Location: commission_settings.php'); exit;
}
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['save_user'])) {
  $uid = (int)($_POST['user_id'] ?? 0);
  $pct = (float)($_POST['user_pct'] ?? 0);
  if ($uid>0) {
    $pdo->prepare("INSERT INTO commission_rates(scope, scope_id, user_id, pct, active) VALUES('USER', ?, ?, ?, 1)")
        ->execute([$uid,$uid,$pct]);
  }
  header('Location: commission_settings.php'); exit;
}

$global = $pdo->query("SELECT id,pct,created_at FROM commission_rates WHERE scope='GLOBAL' AND active=1 ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$userRates = $pdo->query("SELECT cr.id, u.$userKeyCol AS user_label, cr.pct, cr.created_at
                          FROM commission_rates cr
                          LEFT JOIN $usersTable u ON u.$userIdCol = cr.user_id
                          WHERE cr.scope='USER' AND cr.active=1
                          ORDER BY cr.id DESC")->fetchAll(PDO::FETCH_ASSOC);

// --- Drop-in fix for commission_settings.php (replace your user SELECT on/around line 34) ---
// Requires: $pdo = Database::pdo();

// 1) Detect available columns on the users table
$cols = $pdo->query("PRAGMA table_info('users')")->fetchAll(PDO::FETCH_ASSOC);
$colNames = array_map(function($c){
  // handle different drivers returning 'name' or 'Name'
  return strtolower($c['name'] ?? ($c['Name'] ?? ''));
}, $cols);

// 2) Pick the first present column as display name
$preferred = ['full_name','username','display_name','name','mobile','email'];
$displayCol = null;
foreach ($preferred as $c) {
  if (in_array(strtolower($c), $colNames, true)) { $displayCol = $c; break; }
}
if ($displayCol === null) {
  // Fallback: cast id to text so we have something to show
  $displayCol = "CAST(id AS TEXT)";
} else {
  // keep column as-is
  $displayCol = $displayCol;
}

// 3) Build a safe SELECT for your sales-facing roles (tweak roles as needed)
$roles = ["SALES","MANAGER","SUPERVISOR"]; // add/remove roles to suit your app
$in  = implode(",", array_fill(0, count($roles), "?"));
$sql = "SELECT id, $displayCol AS name, role FROM users WHERE role IN ($in) ORDER BY name COLLATE NOCASE ASC";

$st  = $pdo->prepare($sql);
$st->execute($roles);
$salesUsers = $st->fetchAll(PDO::FETCH_ASSOC);

// $salesUsers now has: [ ['id'=>..., 'name'=>..., 'role'=>...], ... ]
// Use it to render your Commission Settings UI.
?>
<div class="card p-3 mb-3">
  <h5>Global Commission %</h5>
  <form method="post" class="row g-2 align-items-end">
    <div class="col-auto">
      <label class="form-label">Current</label>
      <input class="form-control" value="<?= isset($global['pct'])?number_format((float)$global['pct'],2):'0.00' ?>" disabled>
    </div>
    <div class="col-auto">
      <label class="form-label">Set New %</label>
      <input type="number" step="0.01" min="0" name="global_pct" class="form-control" placeholder="e.g., 2.5">
    </div>
    <div class="col-auto">
      <button class="btn btn-primary" name="save_global" value="1">Save</button>
    </div>
  </form>
</div>

<div class="card p-3 mb-4">
  <h5>User-specific Commission %</h5>
  <form method="post" class="row g-2 align-items-end mb-3">
    <div class="col-auto">
      <label class="form-label">User</label>
      <select name="user_id" class="form-select">
        <?php foreach($users as $u): ?>
          <option value="<?=$u['id']?>"><?=htmlspecialchars((string)$u['label'])?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-auto">
      <label class="form-label">% Value</label>
      <input type="number" step="0.01" min="0" name="user_pct" class="form-control" placeholder="e.g., 3.0">
    </div>
    <div class="col-auto">
      <button class="btn btn-primary" name="save_user" value="1">Add / Update</button>
    </div>
  </form>

  <div class="table-responsive">
    <table class="table table-striped align-middle">
      <thead><tr><th>User</th><th>%</th><th>Created</th></tr></thead>
      <tbody>
        <?php foreach($userRates as $r): ?>
          <tr><td><?=htmlspecialchars((string)$r['user_label'])?></td><td><?=number_format((float)$r['pct'],2)?>%</td><td><?=htmlspecialchars((string)$r['created_at'])?></td></tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
