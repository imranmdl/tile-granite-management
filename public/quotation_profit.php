<?php
// public/quotation_profit.php - Quotation-wise Profit/Loss Analysis
require_once __DIR__ . '/../includes/simple_auth.php';
require_once __DIR__ . '/../includes/helpers.php';

auth_require_login();

$pdo = Database::pdo();
$user_id = $_SESSION['user_id'] ?? 1;

// Check permissions
$user_stmt = $pdo->prepare("SELECT * FROM users_simple WHERE id = ?");
$user_stmt->execute([$user_id]);
$user = $user_stmt->fetch(PDO::FETCH_ASSOC);

$can_view_reports = ($user['can_view_reports'] ?? 0) == 1 || ($user['role'] ?? '') === 'admin';
$can_view_pl = ($user['can_view_pl'] ?? 0) == 1 || ($user['role'] ?? '') === 'admin';

if (!$can_view_reports) {
    $_SESSION['error'] = 'You do not have permission to access reports';
    safe_redirect('index.php');
}

$page_title = "Quotation-wise Profit Analysis";
require_once __DIR__ . '/../includes/header.php';

$mode = (isset($_GET['mode']) && strtolower($_GET['mode'])==='detailed') ? 'detailed' : 'simple';
$id   = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($id <= 0) {
  $st = $pdo->prepare("
    SELECT id,
           quote_no  AS code,
           quote_dt  AS dt,
           customer_name,
           total
    FROM quotations
    WHERE " . range_where('quote_dt') . "
    ORDER BY quote_dt DESC, id DESC
  ");
  bind_range($st);
  $st->execute();
  $rows = $st->fetchAll(PDO::FETCH_ASSOC);

  // preserve current range when changing cost mode
  $keep = [
    'range' => $_GET['range'] ?? '',
    'from'  => $_GET['from']  ?? '',
    'to'    => $_GET['to']    ?? '',
    'mode'  => $mode,
  ];
  $qs_keep = http_build_query(array_filter($keep, fn($v)=>$v!=='' && $v!==null));
  ?>
  <div class="card p-3 mb-3">
    <form class="row g-2" method="get">
      <?php if (!empty($keep['range'])): ?><input type="hidden" name="range" value="<?= h($keep['range']) ?>"><?php endif; ?>
      <?php if (!empty($keep['from'])):  ?><input type="hidden" name="from"  value="<?= h($keep['from'])  ?>"><?php endif; ?>
      <?php if (!empty($keep['to'])):    ?><input type="hidden" name="to"    value="<?= h($keep['to'])    ?>"><?php endif; ?>
      <div class="col-md-3">
        <label class="form-label">Cost Mode</label>
        <select class="form-select" name="mode" onchange="this.form.submit()">
          <option value="simple"   <?= $mode==='simple'   ? 'selected':'' ?>>Simple (Base + %)</option>
          <option value="detailed" <?= $mode==='detailed' ? 'selected':'' ?>>Detailed (Base + % + adders + allocation)</option>
        </select>
      </div>
    </form>
  </div>

  <div class="card p-3">
    <div class="table-responsive">
      <table class="table table-striped table-sm align-middle">
        <thead>
          <tr><th>Date</th><th>Quotation</th><th>Customer</th><th class="text-end">Total</th><th></th></tr>
        </thead>
        <tbody>
          <?php foreach($rows as $r): ?>
            <tr>
              <td><?= h($r['dt']) ?></td>
              <td><?= h($r['code']) ?></td>
              <td><?= h($r['customer_name']) ?></td>
              <td class="text-end">₹ <?= n2($r['total']) ?></td>
              <td>
                <a class="btn btn-sm btn-outline-primary" href="quotation_profit.php?<?= $qs_keep ?>&id=<?= (int)$r['id'] ?>">View P/L</a>
              </td>
            </tr>
          <?php endforeach; ?>
          <?php if (empty($rows)): ?>
            <tr><td colspan="5" class="text-center text-muted">No quotations in this range.</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
  
/* Align totals with quote_profit.php names */
if (!isset($total_sell) && isset($total_rev)) { $total_sell = (float)$total_rev; }
<?php require_once __DIR__ . '/../includes/footer.php'; exit;
}

$h = $pdo->query("SELECT * FROM quotations WHERE id=".$id)->fetch(PDO::FETCH_ASSOC);
if (!$h) { echo '<div class="alert alert-danger">Quotation not found.</div>'; require_once __DIR__ . '/../includes/footer.php'; exit; }
$as_of = $h['quote_dt'] ?? null;

$tile_lines = $pdo->query("
  SELECT x.*, t.name tile_name, ts.label size_label
  FROM quotation_items x
  JOIN tiles t       ON t.id = x.tile_id
  JOIN tile_sizes ts ON ts.id = t.size_id
  WHERE quotation_id=".$id
)->fetchAll(PDO::FETCH_ASSOC);

$misc_lines = table_exists($pdo,'quotation_misc_items')
  ? $pdo->query("
      SELECT m.*, mi.name item_name, mi.unit_label
      FROM quotation_misc_items m
      JOIN misc_items mi ON mi.id = m.misc_item_id
      WHERE quotation_id=".$id
    )->fetchAll(PDO::FETCH_ASSOC)
  : [];

$total_rev = 0.0; $total_cost = 0.0;

/* === BEGIN ADDED: Discount & Commission === */

/* ===== Negotiation / Discount & Commission (Integrated) =====
   Expects $total_sell (gross before discount) and $total_cost already computed.
   Defensive: will try common fallback names and default to 0 if not set yet.
*/
if (!isset($pdo)) { $pdo = Database::pdo(); }
if (!isset($quote_id)) { $quote_id = (int)($_GET['id'] ?? $_GET['quote_id'] ?? 0); }

$__total_sell = null;
foreach (["total_sell","grand_total","sell_total","gross_total","total"] as $__n) {
  if (isset($$__n)) { $__total_sell = (float) $$__n; break; }
}
$__total_cost = null;
foreach (["total_cost","cost_total","grand_cost","cost"] as $__n) {
  if (isset($$__n)) { $__total_cost = (float) $$__n; break; }
}
if (!isset($total_sell)) $total_sell = (float) ($__total_sell ?? 0.0);
if (!isset($total_cost)) $total_cost = (float) ($__total_cost ?? 0.0);

$disc_mode = 'NONE'; $disc_value = 0.0; $commission_on = 'PROFIT'; $commission_pct = 0.0; $commission_to = '';
$discount_amount = 0.0; $net_sell = $total_sell; $profit_before_discount = $total_sell - $total_cost;
$profit_after_discount = $profit_before_discount; $commission_amount = 0.0; $profit_after_commission = $profit_after_discount;

// Preload previously-saved values (ignore if columns don't exist yet)
try {
  $pref = $pdo->prepare("SELECT discount_mode,discount_value,discount_amount,total_before_discount,total_after_discount,profit_before_discount,profit_after_discount,commission_base,commission_pct,commission_amount,commission_to FROM quotations WHERE id=?");
  $pref->execute([$quote_id]);
  if ($row = $pref->fetch(PDO::FETCH_ASSOC)) {
    if (!empty($row['discount_mode'])) $disc_mode = $row['discount_mode'];
    if (isset($row['discount_value'])) $disc_value = (float)$row['discount_value'];
    if (!empty($row['commission_base'])) $commission_on = $row['commission_base'];
    if (isset($row['commission_pct'])) $commission_pct = (float)$row['commission_pct'];
    if (!empty($row['commission_to'])) $commission_to = $row['commission_to'];
  }
} catch (Throwable $e) { /* columns may not exist yet; ignore */ }

if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['apply_discount'])) {
  $disc_mode = $_POST['disc_mode'] ?? 'NONE';
  $disc_value = round((float)($_POST['disc_value'] ?? 0), 2);
  $commission_on = $_POST['commission_on'] ?? 'PROFIT';  // PROFIT | SALE
  $commission_pct = round((float)($_POST['commission_pct'] ?? 0), 2);
  $commission_to = trim($_POST['commission_to'] ?? '');

  // Discount
  if ($disc_mode === 'PCT') {
    $discount_amount = max(0, min($total_sell, $total_sell * $disc_value / 100.0));
  } elseif ($disc_mode === 'AMT') {
    $discount_amount = max(0, min($total_sell, $disc_value));
  } else {
    $discount_amount = 0.0;
  }
  $net_sell = $total_sell - $discount_amount;
  $profit_before_discount = $total_sell - $total_cost;
  $profit_after_discount = $net_sell - $total_cost;

  // Commission
  $commission_base_amt = ($commission_on === 'SALE') ? $net_sell : max(0.0, $profit_after_discount); // no commission on negative profit
  $commission_amount = round($commission_base_amt * ($commission_pct/100.0), 2);
  $profit_after_commission = $profit_after_discount - $commission_amount;

  // Persist to DB if requested
  if (isset($_POST['save'])) {
    try {
      $st = $pdo->prepare("UPDATE quotations
        SET discount_mode=?, discount_value=?, discount_amount=?, total_before_discount=?, total_after_discount=?,
            profit_before_discount=?, profit_after_discount=?, commission_base=?, commission_pct=?, commission_amount=?, commission_to=?
        WHERE id=?");
      $st->execute([
        $disc_mode, $disc_value, $discount_amount, $total_sell, $net_sell,
        $profit_before_discount, $profit_after_discount, $commission_on, $commission_pct, $commission_amount, $commission_to,
        $quote_id
      ]);
      if (function_exists('safe_redirect')) { safe_redirect('quotation_profit.php?id='.$quote_id.'&saved=1'); }
    } catch (Throwable $e) { /* ignore write error if migration not applied yet */ }
  }
}
?>

<div class="card p-3 mt-3">
  <h6 class="mb-3">Negotiation / Discount & Commission</h6>
  <form method="post" class="row g-3">
    <input type="hidden" name="apply_discount" value="1">
    <div class="col-md-3">
      <label class="form-label">Discount Mode</label>
      <select name="disc_mode" class="form-select">
        <option value="NONE" <?php echo $disc_mode==='NONE'?'selected':'';?>>None</option>
        <option value="PCT"  <?php echo $disc_mode==='PCT'?'selected':'';?>>% Percentage</option>
        <option value="AMT"  <?php echo $disc_mode==='AMT'?'selected':'';?>>₹ Amount</option>
      </select>
    </div>
    <div class="col-md-3">
      <label class="form-label">Discount Value</label>
      <input type="number" step="0.01" min="0" name="disc_value" class="form-control" value="<?php echo htmlspecialchars((string)$disc_value);?>">
    </div>

    <div class="col-md-3">
      <label class="form-label">Commission Base</label>
      <select name="commission_on" class="form-select">
        <option value="PROFIT" <?php echo $commission_on==='PROFIT'?'selected':'';?>>Profit after discount</option>
        <option value="SALE"   <?php echo $commission_on==='SALE'  ?'selected':'';?>>Net sale (after discount)</option>
      </select>
    </div>
    <div class="col-md-3">
      <label class="form-label">Commission %</label>
      <input type="number" step="0.01" min="0" name="commission_pct" class="form-control" value="<?php echo htmlspecialchars((string)$commission_pct);?>">
    </div>

    <div class="col-md-4">
      <label class="form-label">Commission To (person)</label>
      <input type="text" name="commission_to" class="form-control" placeholder="e.g., Imran / Sales A" value="<?php echo htmlspecialchars($commission_to);?>">
    </div>

    <div class="col-12 d-flex gap-2">
      <button class="btn btn-secondary" type="submit" name="apply">Preview</button>
      <button class="btn btn-primary" type="submit" name="save" value="1">Save & Store in Quotation</button>
    </div>
  </form>

  <div class="table-responsive mt-3">
    <table class="table table-sm mb-0">
      <tbody>
        <tr><th style="width:40%">Gross sale (before discount)</th><td>₹ <?php echo number_format($total_sell, 2);?></td></tr>
        <tr><th>Discount</th><td>₹ <?php echo number_format($discount_amount, 2);?> <?php if($disc_mode==='PCT') echo '(' . number_format($disc_value,2) . '%)';?></td></tr>
        <tr class="table-light"><th>Net sale (after discount)</th><td><strong>₹ <?php echo number_format($net_sell, 2);?></strong></td></tr>
        <tr><th>Total cost</th><td>₹ <?php echo number_format($total_cost, 2);?></td></tr>
        <tr><th>Profit after discount</th><td>₹ <?php echo number_format($profit_after_discount, 2);?></td></tr>
        <tr><th>Commission (<?php echo htmlspecialchars($commission_on);?> @ <?php echo number_format($commission_pct,2);?>%)</th><td>₹ <?php echo number_format($commission_amount, 2);?></td></tr>
        <tr class="table-success"><th>Profit after commission</th><td><strong>₹ <?php echo number_format($profit_after_commission, 2);?></strong></td></tr>
      </tbody>
    </table>
  </div>
</div>

<?php
/* === END ADDED: Discount & Commission === */

 $rows=[]; $dbg=[];

foreach($tile_lines as $r){
  $bd = cost_tile_per_box_asof($pdo, (int)$r['tile_id'], $as_of, $mode);
  $qty = (float)$r['boxes_decimal'];
  $rev = (float)$r['rate_per_box'] * $qty;
  $cost = $bd['cp'] * $qty;
  $profit = $rev - $cost;
  $margin = $rev>0 ? ($profit*100.0/$rev) : 0.0;

  $rows[] = [
    'name'=>$r['tile_name'].' ('.$r['size_label'].')',
    'qty'=>$qty.' boxes',
    'rate'=>$r['rate_per_box'],
    'rev'=>$rev,
    'cp'=>$bd['cp'],
    'cost'=>$cost,
    'profit'=>$profit,
    'margin'=>$margin
  ];
  $dbg[] = [
    'name'=>$r['tile_name'].' ('.$r['size_label'].')',
    'base'=>$bd['base'],
    'pct'=>$bd['pct'],
    'pct_amt'=>$bd['pct_amt'],
    'adder'=>$bd['adder'],
    'alloc'=>$bd['alloc'],
    'cp'=>$bd['cp']
  ];
  $total_rev += $rev; $total_cost += $cost;
}

foreach($misc_lines as $r){
  $bd = cost_misc_per_unit_asof($pdo, (int)$r['misc_item_id'], $as_of, $mode);
  $qty = (float)$r['qty_units'];
  $rev = (float)$r['rate_per_unit'] * $qty;
  $cost = $bd['cp'] * $qty;
  $profit = $rev - $cost;
  $margin = $rev>0 ? ($profit*100.0/$rev) : 0.0;

  $rows[] = [
    'name'=>$r['item_name'].' ('.$r['unit_label'].')',
    'qty'=>$qty.' units',
    'rate'=>$r['rate_per_unit'],
    'rev'=>$rev,
    'cp'=>$bd['cp'],
    'cost'=>$cost,
    'profit'=>$profit,
    'margin'=>$margin
  ];
  $dbg[] = [
    'name'=>$r['item_name'].' ('.$r['unit_label'].')',
    'base'=>$bd['base'],
    'pct'=>$bd['pct'],
    'pct_amt'=>$bd['pct_amt'],
    'adder'=>$bd['adder'],
    'alloc'=>$bd['alloc'],
    'cp'=>$bd['cp']
  ];
  $total_rev += $rev; $total_cost += $cost;
}

$gross = $total_rev - $total_cost;
$margin = $total_rev>0 ? ($gross*100.0/$total_rev) : 0.0;


?>

<div class="card p-3 mb-3">
  <div class="row g-2">
    <div class="col-md-3"><strong>No:</strong> <?= h($h['quote_no']) ?></div>
    <div class="col-md-3"><strong>Date:</strong> <?= h($h['quote_dt']) ?></div>
    <div class="col-md-6"><strong>Customer:</strong> <?= h($h['customer_name']) ?></div>
  </div>
  <div class="mt-2">
    <form method="get" class="row g-2">
      <input type="hidden" name="id" value="<?= (int)$id ?>">
      <?php if (!empty($_GET['range'])): ?><input type="hidden" name="range" value="<?= h($_GET['range']) ?>"><?php endif; ?>
      <?php if (!empty($_GET['from'])):  ?><input type="hidden" name="from"  value="<?= h($_GET['from'])  ?>"><?php endif; ?>
      <?php if (!empty($_GET['to'])):    ?><input type="hidden" name="to"    value="<?= h($_GET['to'])    ?>"><?php endif; ?>
      <div class="col-md-3">
        <label class="form-label">Cost Mode</label>
        <select class="form-select" name="mode">
          <option value="simple"   <?= $mode==='simple'   ? 'selected':'' ?>>Simple (Base + %)</option>
          <option value="detailed" <?= $mode==='detailed' ? 'selected':'' ?>>Detailed (Base + % + adders + allocation)</option>
        </select>
      </div>
      <div class="col-md-2 d-flex align-items-end">
        <button class="btn btn-secondary">Recalculate</button>
      </div>
    </form>
  </div>
</div>

<div class="card p-3 mb-3">
  <h5>P/L Lines</h5>
  <div class="table-responsive">
    <table class="table table-striped table-sm align-middle">
      <thead>
        <tr>
          <th>Item</th><th>Qty</th><th>Rate</th><th>Revenue</th>
          <th>Cost/Box or Unit (incl. transport)</th><th>Cost</th><th>Profit</th><th>Margin %</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach($rows as $r): ?>
          <tr>
            <td><?= h($r['name']) ?></td>
            <td><?= h($r['qty']) ?></td>
            <td>₹ <?= n2($r['rate']) ?></td>
            <td>₹ <?= n2($r['rev']) ?></td>
            <td>₹ <?= n2($r['cp']) ?></td>
            <td>₹ <?= n2($r['cost']) ?></td>
            <td class="<?= $r['profit']>=0?'text-success':'text-danger' ?>">₹ <?= n2($r['profit']) ?></td>
            <td><?= n2($r['margin']) ?>%</td>
          </tr>
        <?php endforeach; ?>
        <?php if (empty($rows)): ?>
          <tr><td colspan="8" class="text-center text-muted">No lines.</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<div class="card p-3 mb-3">
  <details>
    <summary class="mb-2"><strong>Show cost breakdown (debug)</strong></summary>
    <div class="table-responsive">
      <table class="table table-sm">
        <thead>
          <tr>
            <th>Item</th><th>Base</th><th>Transport %</th><th>Transport % Amt</th>
            <th>Per-box / Per-unit Adder</th><th>Allocated from Total</th><th>Cost (cp)</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach($dbg as $d): ?>
            <tr>
              <td><?= h($d['name']) ?></td>
              <td>₹ <?= n2($d['base']) ?></td>
              <td><?= n2($d['pct']) ?>%</td>
              <td>₹ <?= n2($d['pct_amt']) ?></td>
              <td>₹ <?= n2($d['adder']) ?></td>
              <td>₹ <?= n2($d['alloc']) ?></td>
              <td>₹ <?= n2($d['cp']) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </details>
</div>

<div class="card p-3">
  <div class="row text-center">
    <div class="col"><div class="p-2 bg-light rounded"><div class="small text-muted">Total Revenue</div><div class="fs-5">₹ <?= n2($total_rev) ?></div></div></div>
    <div class="col"><div class="p-2 bg-light rounded"><div class="small text-muted">Total Cost</div><div class="fs-5">₹ <?= n2($total_cost) ?></div></div></div>
    <div class="col"><div class="p-2 bg-light rounded"><div class="small text-muted">Gross Profit</div><div class="fs-5 <?= $gross>=0?'text-success':'text-danger' ?>">₹ <?= n2($gross) ?></div></div></div>
    <div class="col"><div class="p-2 bg-light rounded"><div class="small text-muted">Margin</div><div class="fs-5"><?= n2($margin) ?>%</div></div></div>
  </div>
</div>


<?php require_once __DIR__ . '/../includes/footer.php'; ?>
