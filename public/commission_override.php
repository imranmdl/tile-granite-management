<?php
$page_title="Commission Overrides";
require_once __DIR__ . '/../includes/header.php';

require_login(['admin','manager']);
require_once __DIR__ . '/../includes/commission.php';
$pdo = Database::pdo();

$msg = null;
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['save'])) {
  $scope = $_POST['scope'] ?? 'INVOICE'; // INVOICE or QUOTATION
  $scope_id = (int)($_POST['scope_id'] ?? 0);
  $pct = (float)($_POST['pct'] ?? 0);
  if (in_array($scope,['INVOICE','QUOTATION'],true) && $scope_id>0) {
    $pdo->prepare("INSERT INTO commission_rates(scope, scope_id, pct, active) VALUES(?,?,?,1)")
        ->execute([$scope,$scope_id,$pct]);
    $msg = "Override saved for $scope #$scope_id";
  } else {
    $msg = "Provide valid scope and ID.";
  }
}
?>
<div class="card p-3 mb-3">
  <form method="post" class="row g-2 align-items-end">
    <div class="col-auto">
      <label class="form-label">Scope</label>
      <select name="scope" class="form-select">
        <option value="INVOICE">Invoice</option>
        <option value="QUOTATION">Quotation</option>
      </select>
    </div>
    <div class="col-auto">
      <label class="form-label">ID</label>
      <input type="number" name="scope_id" class="form-control" placeholder="Invoice/Quotation ID">
    </div>
    <div class="col-auto">
      <label class="form-label">% Commission</label>
      <input type="number" step="0.01" min="0" name="pct" class="form-control" placeholder="e.g., 2.0">
    </div>
    <div class="col-auto">
      <button class="btn btn-primary" name="save" value="1">Save Override</button>
    </div>
  </form>
</div>
<?php if ($msg): ?><div class="alert alert-info mx-3"><?=$msg?></div><?php endif; ?>
<div class="card p-3">
  <p>Overrides take highest priority. After saving, re-open the invoice or run the <em>Recompute</em> tool to refresh the ledger.</p>
</div>
