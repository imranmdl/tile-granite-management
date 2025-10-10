<?php
$page_title="Sales Commission";
require_once __DIR__ . '/../includes/header.php';

require_login(['admin','manager']);
require_once __DIR__ . '/../includes/commission.php';
$pdo = Database::pdo();
$role = $_SESSION['user']['role'] ?? ($_SESSION['user']['is_admin'] ?? 0 ? 'Admin' : 'User');

$from   = $_GET['from']   ?? date('Y-m-01');
$to     = $_GET['to']     ?? date('Y-m-t');
$status = $_GET['status'] ?? 'ALL';
$user_id= isset($_GET['user_id']) && $_GET['user_id']!=='' ? (int)$_GET['user_id'] : null;

$dateCol = Commission::column_exists($pdo,'invoices','invoice_dt') ? 'invoice_dt' : (Commission::column_exists($pdo,'invoices','created_at') ? 'created_at' : 'id');

if ($_SERVER['REQUEST_METHOD']==='POST' && $role!=='User') {
  if (isset($_POST['approve'])) Commission::set_status($pdo,(int)$_POST['id'],'APPROVED');
  if (isset($_POST['mark_paid'])) Commission::set_status($pdo,(int)$_POST['id'],'PAID', trim($_POST['reference']??''), trim($_POST['notes']??''));
  header("Location: sales_commission.php?from=$from&to=$to&status=$status&user_id=".($user_id??'')); exit;
}

$w = "1=1"; $args=[];
if ($status!=='ALL') { $w.=" AND c.status=?"; $args[]=$status; }
if ($user_id) { $w.=" AND c.salesperson_user_id=?"; $args[]=$user_id; }
$w.=" AND DATE(i.$dateCol) BETWEEN ? AND ?"; $args[]=$from; $args[]=$to;

$sql = "SELECT c.*, i.$dateCol AS invoice_dt, i.invoice_no, u.username AS salesperson
        FROM commission_ledger c
        LEFT JOIN invoices i ON i.id=c.invoice_id
        LEFT JOIN users u ON u.id=c.salesperson_user_id
        WHERE $w
        ORDER BY i.$dateCol DESC, c.id DESC";
$st=$pdo->prepare($sql); $st->execute($args); $rows=$st->fetchAll(PDO::FETCH_ASSOC);

$tot_base=0; $tot_amt=0;
foreach ($rows as $r){ $tot_base+=(float)$r['base_amount']; $tot_amt+=(float)$r['amount']; }

// includes/display_name_resolver.php
// Returns a SQL expression to display a user's name, choosing from existing columns.
if (!function_exists('resolve_user_display_expr')) {
  function resolve_user_display_expr(PDO $pdo, string $table = 'users'): string {
    try {
      $cols = $pdo->query("PRAGMA table_info('".$table."')")->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
      // Fallback if PRAGMA not supported
      return "CAST(id AS TEXT)";
    }
    $existing = [];
    foreach ($cols as $c) {
      $n = strtolower($c['name'] ?? ($c['Name'] ?? ''));
      if ($n) $existing[$n] = true;
    }
    $candidates = ['full_name','username','display_name','name','mobile','email'];
    $available = [];
    foreach ($candidates as $c) {
      if (isset($existing[strtolower($c)])) $available[] = $c;
    }
    if (count($available) === 0) return "CAST(id AS TEXT)";
    if (count($available) === 1) return $available[0];
    return "COALESCE(".implode(',', $available).")";
  }
}
// Monthly totals (overall)
if (Commission::driver($pdo)==='sqlite') {
  $msql = "SELECT strftime('%Y-%m', i.$dateCol) AS ym, SUM(c.amount) AS amt
           FROM commission_ledger c LEFT JOIN invoices i ON i.id=c.invoice_id
           WHERE DATE(i.$dateCol) BETWEEN ? AND ? GROUP BY ym ORDER BY ym DESC";
} else {
  $msql = "SELECT DATE_FORMAT(i.$dateCol, '%Y-%m') AS ym, SUM(c.amount) AS amt
           FROM commission_ledger c LEFT JOIN invoices i ON i.id=c.invoice_id
           WHERE DATE(i.$dateCol) BETWEEN ? AND ? GROUP BY ym ORDER BY ym DESC";
}
$ms=$pdo->prepare($msql); $ms->execute([$from,$to]); $monthly_total=$ms->fetchAll(PDO::FETCH_ASSOC);

// Monthly per person
if (Commission::driver($pdo)==='sqlite') {
  $psql = "SELECT strftime('%Y-%m', i.$dateCol) AS ym, u.username AS salesperson, SUM(c.amount) AS amt
           FROM commission_ledger c
           LEFT JOIN invoices i ON i.id=c.invoice_id
           LEFT JOIN users u ON u.id=c.salesperson_user_id
           WHERE DATE(i.$dateCol) BETWEEN ? AND ?
           GROUP BY ym, salesperson ORDER BY ym DESC, salesperson";
} else {
  $psql = "SELECT DATE_FORMAT(i.$dateCol, '%Y-%m') AS ym, u.username AS salesperson, SUM(c.amount) AS amt
           FROM commission_ledger c
           LEFT JOIN invoices i ON i.id=c.invoice_id
           LEFT JOIN users u ON u.id=c.salesperson_user_id
           WHERE DATE(i.$dateCol) BETWEEN ? AND ?
           GROUP BY ym, salesperson ORDER BY ym DESC, salesperson";
}
$ps=$pdo->prepare($psql); $ps->execute([$from,$to]); $monthly_person=$ps->fetchAll(PDO::FETCH_ASSOC);
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
    <div class="col-auto"><label class="form-label">Salesperson</label>
      <select name="user_id" class="form-select">
        <option value="">All</option>
        <?php foreach($users as $u): ?>
          <option value="<?=$u['id']?>" <?=($user_id===(int)$u['id'])?'selected':''?>><?=htmlspecialchars($u['label'])?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-auto"><button class="btn btn-primary">Apply</button>
      <a class="btn btn-success" href="?from=<?=date('Y-m-01')?>&to=<?=date('Y-m-t')?>">This Month</a>
    </div>
  </form>
</div>

<div class="card p-3 mb-3">
  <div class="row g-3">
    <div class="col-md-3"><div class="p-3 bg-light rounded"><div class="small text-muted">Records</div><div class="h5 mb-0"><?=count($rows)?></div></div></div>
    <div class="col-md-4"><div class="p-3 bg-light rounded"><div class="small text-muted">Commission Base (Cost)</div><div class="h5 mb-0">₹<?=number_format($tot_base,2)?></div></div></div>
    <div class="col-md-4"><div class="p-3 bg-light rounded"><div class="small text-muted">Commission Amount</div><div class="h5 mb-0">₹<?=number_format($tot_amt,2)?></div></div></div>
  </div>
</div>

<div class="card p-3 mb-4">
  <h5 class="mb-3">Commission Entries</h5>
  <div class="table-responsive">
    <table class="table table-striped align-middle">
      <thead><tr>
        <th>Date</th><th>Invoice</th><th>Salesperson</th><th>Base (Cost)</th><th>%</th><th>Amount</th><th>Status</th><th>Actions</th>
      </tr></thead>
      <tbody>
      <?php foreach($rows as $r): ?>
        <tr>
          <td><?=htmlspecialchars(substr((string)$r['invoice_dt'],0,10))?></td>
          <td>#<?=htmlspecialchars((string)($r['invoice_id'] ?? ''))?></td>
          <td><?=htmlspecialchars((string)$r['salesperson'])?></td>
          <td>₹<?=number_format((float)$r['base_amount'],2)?></td>
          <td><?=number_format((float)$r['pct'],2)?>%</td>
          <td>₹<?=number_format((float)$r['amount'],2)?></td>
          <td><span class="badge bg-<?=($r['status']==='PAID'?'success':($r['status']==='APPROVED'?'info':'warning'))?>"><?=htmlspecialchars((string)$r['status'])?></span></td>
          <td>
            <?php if($role!=='User'): ?>
            <form method="post" class="d-flex flex-wrap gap-2">
              <input type="hidden" name="id" value="<?=$r['id']?>">
              <button class="btn btn-sm btn-outline-info" name="approve" value="1" <?=$r['status']!=='PENDING'?'disabled':''?>>Approve</button>
              <input class="form-control form-control-sm" name="reference" placeholder="Ref #" style="max-width:120px">
              <input class="form-control form-control-sm" name="notes" placeholder="Notes" style="max-width:220px">
              <button class="btn btn-sm btn-success" name="mark_paid" value="1" <?=$r['status']==='PAID'?'disabled':''?>>Mark Paid</button>
            </form>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<?php
if (Commission::driver($pdo)==='sqlite') {
  $msql = "SELECT strftime('%Y-%m', i.$dateCol) AS ym, SUM(c.amount) AS amt
           FROM commission_ledger c LEFT JOIN invoices i ON i.id=c.invoice_id
           WHERE DATE(i.$dateCol) BETWEEN ? AND ? GROUP BY ym ORDER BY ym DESC";
} else {
  $msql = "SELECT DATE_FORMAT(i.$dateCol, '%Y-%m') AS ym, SUM(c.amount) AS amt
           FROM commission_ledger c LEFT JOIN invoices i ON i.id=c.invoice_id
           WHERE DATE(i.$dateCol) BETWEEN ? AND ? GROUP BY ym ORDER BY ym DESC";
}
$ms=$pdo->prepare($msql); $ms->execute([$from,$to]); $monthly_total=$ms->fetchAll(PDO::FETCH_ASSOC);
?>
<div class="card p-3 mb-4">
  <h5 class="mb-2">Monthly Totals (All)</h5>
  <div class="table-responsive mb-3">
    <table class="table table-bordered">
      <thead><tr><th>Month</th><th>Total Commission</th></tr></thead>
      <tbody>
        <?php foreach($monthly_total as $m): ?>
          <tr><td><?=$m['ym']?></td><td>₹<?=number_format((float)$m['amt'],2)?></td></tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <canvas id="cmMonthly" height="110" class="mt-2"></canvas>
</div>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js" crossorigin="anonymous"></script>
<script>
  const mLabels = <?=json_encode(array_column($monthly_total,'ym'))?>;
  const mData   = <?=json_encode(array_map(fn($x)=>round((float)$x['amt'],2), $monthly_total))?>;
  const ctx = document.getElementById('cmMonthly').getContext('2d');
  new Chart(ctx, { type: 'bar', data: { labels: mLabels, datasets: [{ label: 'Total Commission (₹)', data: mData }] },
    options: { responsive: true, scales: { y: { beginAtZero: true, title: { display: true, text: '₹' } } } } });
</script>
