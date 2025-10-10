<?php
$page_title="Recompute Commissions";
require_once __DIR__ . '/../includes/header.php';

require_login(['admin','manager']);
require_once __DIR__ . '/../includes/commission.php';
$pdo = Database::pdo();

$from = $_GET['from'] ?? date('Y-m-01');
$to   = $_GET['to']   ?? date('Y-m-t');
$msg = null;

if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['run'])) {
  $res = Commission::recompute_range($pdo, $from, $to);
  $msg = "Synced {$res['synced']} / {$res['total']} invoices.";
}
?>
<div class="card p-3 mb-3">
  <form class="row g-2 align-items-end" method="post">
    <div class="col-auto"><label class="form-label">From</label><input type="date" name="from" class="form-control" value="<?=htmlspecialchars($from)?>"></div>
    <div class="col-auto"><label class="form-label">To</label><input type="date" name="to" class="form-control" value="<?=htmlspecialchars($to)?>"></div>
    <div class="col-auto"><button class="btn btn-primary" name="run" value="1">Recompute</button></div>
  </form>
</div>
<?php if ($msg): ?><div class="alert alert-info m-3"><?=htmlspecialchars($msg)?></div><?php endif; ?>

<div class="card p-3">
  <p>This recalculates <strong>cost-based</strong> commission for all invoices in range using: <em>Invoice override → Quotation override → User rate → Global rate</em>.</p>
  <ol class="mb-0">
    <li>Salesperson must be a valid login user mapped from invoice (e.g., <code>invoices.sales_user</code>).</li>
    <li>Base = sum of <code>cost_at_sale</code> for items (tiles + misc).</li>
    <li>Run this after changing rates or fixing item costs.</li>
  </ol>
</div>
