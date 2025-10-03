<?php
$page_title="My Commission";
require_once __DIR__ . '/../includes/header.php';
require_login();
require_once __DIR__ . '/../includes/commission.php';
$pdo = Database::pdo();

list($usersTable,$userIdCol,$userKeyCol) = Commission::users_table($pdo);
$me = auth_user_id();
if (!$me && $userKeyCol) {
  $key = $_SESSION['user']['username'] ?? $_SESSION['user']['mobile'] ?? $_SESSION['user']['name'] ?? null;
  if ($key) { $st=$pdo->prepare("SELECT $userIdCol FROM $usersTable WHERE $userKeyCol=?"); $st->execute([$key]); if ($x=$st->fetchColumn()) $me=(int)$x; }
}

$from = $_GET['from'] ?? date('Y-m-01');
$to   = $_GET['to']   ?? date('Y-m-t');
$status = $_GET['status'] ?? 'ALL';

$dateCol = Commission::column_exists($pdo,'invoices','invoice_dt') ? 'invoice_dt' : (Commission::column_exists($pdo,'invoices','created_at') ? 'created_at' : 'id');

$w = "c.salesperson_user_id=?"; $args = [$me];
if ($status !== 'ALL') { $w .= " AND c.status=?"; $args[]=$status; }
$w .= " AND DATE(i.$dateCol) BETWEEN ? AND ?"; $args[]=$from; $args[]=$to;

$sql = "SELECT c.*, i.$dateCol AS invoice_dt, i.invoice_no
        FROM commission_ledger c
        LEFT JOIN invoices i ON i.id=c.invoice_id
        WHERE $w
        ORDER BY i.$dateCol DESC, c.id DESC";
$st=$pdo->prepare($sql); $st->execute($args); $rows=$st->fetchAll(PDO::FETCH_ASSOC);

$tot=0; foreach($rows as $r) $tot += (float)$r['amount'];
?>
<div class="card p-3 mb-3">
  <form class="row g-2 align-items-end">
    <div class="col-auto"><label class="form-label">From</label><input type="date" name="from" class="form-control" value="<?=htmlspecialchars($from)?>"></div>
    <div class="col-auto"><label class="form-label">To</label><input type="date" name="to" class="form-control" value="<?=htmlspecialchars($to)?>"></div>
    <div class="col-auto"><label class="form-label">Status</label>
      <select name="status" class="form-select">
        <?php foreach(['ALL','PENDING','APPROVED','PAID'] as $s): ?>
          <option value="<?=$s?>" <?=$status===$s?'selected':''?>><?=$s?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-auto"><button class="btn btn-primary">Apply</button></div>
  </form>
</div>

<div class="card p-3 mb-3">
  <div class="row g-3">
    <div class="col-md-4"><div class="p-3 bg-light rounded"><div class="small text-muted">My Commission</div><div class="h5 mb-0">₹<?=number_format($tot,2)?></div></div></div>
    <div class="col-md-4"><div class="p-3 bg-light rounded"><div class="small text-muted">Entries</div><div class="h5 mb-0"><?=count($rows)?></div></div></div>
  </div>
</div>

<div class="card p-3">
  <h5 class="mb-3">Entries</h5>
  <div class="table-responsive">
    <table class="table table-striped align-middle">
      <thead><tr><th>Date</th><th>Invoice</th><th>Base</th><th>%</th><th>Amount</th><th>Status</th></tr></thead>
      <tbody>
      <?php foreach($rows as $r): ?>
        <tr>
          <td><?=htmlspecialchars(substr((string)$r['invoice_dt'],0,10))?></td>
          <td>#<?=htmlspecialchars((string)($r['invoice_id'] ?? ''))?></td>
          <td>₹<?=number_format((float)$r['base_amount'],2)?></td>
          <td><?=number_format((float)$r['pct'],2)?>%</td>
          <td>₹<?=number_format((float)$r['amount'],2)?></td>
          <td><span class="badge bg-<?=($r['status']==='PAID'?'success':($r['status']==='APPROVED'?'info':'warning'))?>"><?=htmlspecialchars((string)$r['status'])?></span></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
