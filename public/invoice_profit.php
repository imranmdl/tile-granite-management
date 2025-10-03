<?php echo "<pre>calc_cost loaded from: ".__DIR__."/../includes/calc_cost.php</pre>"; ?>

<?php
// public/invoice_profit.php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/calc_cost.php';
require_once __DIR__ . '/../includes/report_range.php';
require_login();

$pdo  = Database::pdo();
$rng  = compute_range();
$page_title = pretty_report_name('Invoice P/L') . ' — ' . $rng['label'];

/* ----------------- UI: date-range controls ----------------- */
require_once __DIR__ . '/../includes/header.php';
render_range_controls();

/* ----------------- inputs ----------------- */
$mode = (isset($_GET['mode']) && strtolower($_GET['mode']) === 'detailed') ? 'detailed' : 'simple';
$id   = isset($_GET['id']) ? (int)$_GET['id'] : 0;

/* ======================================================================
   LIST VIEW (no invoice selected)
   ====================================================================== */
if ($id <= 0) {
  // keep chosen params across navigation
  $keep = [
    'range' => $_GET['range'] ?? '',
    'from'  => $_GET['from']  ?? '',
    'to'    => $_GET['to']    ?? '',
    'mode'  => $mode,
  ];
  $qs_keep = http_build_query(array_filter($keep, fn($v)=>$v!=='' && $v!==null));

  $sql = "
    SELECT i.id,
           i.invoice_no   AS code,
           i.invoice_dt   AS dt,
           i.customer_name,
           i.total
    FROM invoices i
    WHERE " . range_where('i.invoice_dt') . "
    ORDER BY i.invoice_dt DESC, i.id DESC
  ";
  $st = $pdo->prepare($sql);
  bind_range($st);
  $st->execute();
  $rows = $st->fetchAll(PDO::FETCH_ASSOC);
  ?>
  <!-- Cost Mode selector -->
  <div class="card p-3 mb-3">
    <form class="row g-2" method="get">
      <?php if (!empty($keep['range'])): ?><input type="hidden" name="range" value="<?= h($keep['range']) ?>"><?php endif; ?>
      <?php if (!empty($keep['from'])):  ?><input type="hidden" name="from"  value="<?= h($keep['from'])  ?>"><?php endif; ?>
      <?php if (!empty($keep['to'])):    ?><input type="hidden" name="to"    value="<?= h($keep['to'])    ?>"><?php endif; ?>

      <div class="col-md-3">
        <label class="form-label">Cost Mode</label>
        <select class="form-select" name="mode" onchange="this.form.submit()">
          <option value="simple"   <?= $mode==='simple'   ? 'selected' : '' ?>>Simple (Base + %)</option>
          <option value="detailed" <?= $mode==='detailed' ? 'selected' : '' ?>>Detailed (Base + % + adders + allocation)</option>
        </select>
      </div>
    </form>
  </div>

  <div class="card p-3">
    <div class="table-responsive">
      <table class="table table-striped table-sm align-middle">
        <thead>
          <tr>
            <th>Date</th>
            <th>Invoice</th>
            <th>Customer</th>
            <th class="text-end">Total</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($rows as $r): ?>
            <tr>
              <td><?= h($r['dt']) ?></td>
              <td><?= h($r['code']) ?></td>
              <td><?= h($r['customer_name']) ?></td>
              <td class="text-end">₹ <?= n2($r['total']) ?></td>
              <td>
                <a class="btn btn-sm btn-outline-primary"
                   href="invoice_profit.php?<?= $qs_keep ?>&id=<?= (int)$r['id'] ?>">
                  View P/L
                </a>
              </td>
            </tr>
          <?php endforeach; ?>
          <?php if (empty($rows)): ?>
            <tr><td colspan="5" class="text-center text-muted">No invoices in this range.</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
  <?php
  require_once __DIR__ . '/../includes/footer.php';
  exit;
}

/* ======================================================================
   DETAIL VIEW (invoice selected)
   ====================================================================== */
// header
$st = $pdo->prepare("SELECT * FROM invoices WHERE id=? LIMIT 1");
$st->execute([$id]);
$h = $st->fetch(PDO::FETCH_ASSOC);

if (!$h) {
  echo '<div class="alert alert-danger">Invoice not found.</div>';
  require_once __DIR__ . '/../includes/footer.php';
  exit;
}
$as_of = $h['invoice_dt'] ?: date('Y-m-d');

// tile lines
$st = $pdo->prepare("
  SELECT x.*, t.name AS tile_name, ts.label AS size_label
  FROM invoice_items x
  JOIN tiles t       ON t.id = x.tile_id
  JOIN tile_sizes ts ON ts.id = t.size_id
  WHERE x.invoice_id = ?
  ORDER BY x.id ASC
");
$st->execute([$id]);
$tile_lines = $st->fetchAll(PDO::FETCH_ASSOC);

// misc lines (if table exists)
$misc_lines = table_exists($pdo,'invoice_misc_items')
  ? (function(PDO $pdo,$id){
      $st = $pdo->prepare("
        SELECT m.*, mi.name AS item_name, mi.unit_label
        FROM invoice_misc_items m
        JOIN misc_items mi ON mi.id = m.misc_item_id
        WHERE m.invoice_id = ?
        ORDER BY m.id ASC
      ");
      $st->execute([$id]);
      return $st->fetchAll(PDO::FETCH_ASSOC);
    })($pdo,$id)
  : [];

$total_rev = 0.0;
$total_cost = 0.0;
$rows = [];
$dbg  = [];

/* --- tiles --- */
foreach ($tile_lines as $r) {
  $bd    = cost_tile_per_box_asof($pdo, (int)$r['tile_id'], $as_of, $mode);
  $qty   = (float)$r['boxes_decimal'];
  $rev   = (float)$r['rate_per_box'] * $qty;
  $cost  = (float)$bd['cp'] * $qty;
  $profit= $rev - $cost;

  $margin_raw = $rev > 0 ? ($profit * 100.0 / $rev) : 0.0;
  $margin     = max(0.0, $margin_raw - 15.0);

  $rows[] = [
    'name'   => $r['tile_name'].' ('.$r['size_label'].')',
    'qty'    => n2($qty).' boxes',
    'rate'   => (float)$r['rate_per_box'],
    'rev'    => $rev,
    'cp'     => (float)$bd['cp'],
    'cost'   => $cost,
    'profit' => $profit,
    'margin' => $margin,
  ];
  $dbg[] = [
    'name'   => $r['tile_name'].' ('.$r['size_label'].')',
    'base'   => (float)$bd['base'],
    'pct'    => (float)$bd['pct'],
    'pct_amt'=> (float)$bd['pct_amt'],
    'adder'  => (float)$bd['adder'],
    'alloc'  => (float)$bd['alloc'],
    'cp'     => (float)$bd['cp'],
    'why'    => $bd['why'] ?? '',
  ];
  $total_rev  += $rev;
  $total_cost += $cost;
}

/* --- misc items --- */
foreach ($misc_lines as $r) {
  $bd    = cost_misc_per_unit_asof($pdo, (int)$r['misc_item_id'], $as_of, $mode);
  $qty   = (float)$r['qty_units'];
  $rev   = (float)$r['rate_per_unit'] * $qty;
  $cost  = (float)$bd['cp'] * $qty;
  $profit= $rev - $cost;

  $margin_raw = $rev > 0 ? ($profit * 100.0 / $rev) : 0.0;
  $margin     = max(0.0, $margin_raw - 15.0);

  $rows[] = [
    'name'   => $r['item_name'].' ('.$r['unit_label'].')',
    'qty'    => n2($qty).' units',
    'rate'   => (float)$r['rate_per_unit'],
    'rev'    => $rev,
    'cp'     => (float)$bd['cp'],
    'cost'   => $cost,
    'profit' => $profit,
    'margin' => $margin,
  ];
  $dbg[] = [
    'name'   => $r['item_name'].' ('.$r['unit_label'].')',
    'base'   => (float)$bd['base'],
    'pct'    => (float)$bd['pct'],
    'pct_amt'=> (float)$bd['pct_amt'],
    'adder'  => (float)$bd['adder'],
    'alloc'  => (float)$bd['alloc'],
    'cp'     => (float)$bd['cp'],
    'why'    => $bd['why'] ?? '',
  ];
  $total_rev  += $rev;
  $total_cost += $cost;
}

$gross       = $total_rev - $total_cost;
$margin_raw  = $total_rev > 0 ? ($gross * 100.0 / $total_rev) : 0.0;
$margin      = max(0.0, $margin_raw - 15.0);

// Show a heads-up if any cost lines are zero
$zero_cost_lines = array_filter($dbg, fn($d)=> ($d['cp'] ?? 0) <= 0.0);
?>
<div class="card p-3 mb-3">
  <div class="row g-2">
    <div class="col-md-3"><strong>No:</strong> <?= h($h['invoice_no']) ?></div>
    <div class="col-md-3"><strong>Date:</strong> <?= h($h['invoice_dt']) ?></div>
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
          <option value="simple"   <?= $mode==='simple'   ? 'selected' : '' ?>>Simple (Base + %)</option>
          <option value="detailed" <?= $mode==='detailed' ? 'selected' : '' ?>>Detailed (Base + % + adders + allocation)</option>
        </select>
      </div>
      <div class="col-md-2 d-flex align-items-end">
        <button class="btn btn-secondary">Recalculate</button>
      </div>
    </form>
  </div>
  <?php if ($zero_cost_lines): ?>
    <div class="alert alert-warning mt-2">
      Some lines have zero cost.
      <?php
        $reasons = array_unique(array_map(fn($d)=>$d['why'] ?: 'unknown', $zero_cost_lines));
        echo 'Reasons: ' . h(implode(', ', $reasons));
      ?>
    </div>
  <?php endif; ?>
</div>

<div class="card p-3 mb-3">
  <h5>P/L Lines</h5>
  <div class="table-responsive">
    <table class="table table-striped table-sm align-middle">
      <thead>
        <tr>
          <th>Item</th>
          <th>Qty</th>
          <th>Rate</th>
          <th>Revenue</th>
          <th>Cost/Box or Unit (incl. transport)</th>
          <th>Cost</th>
          <th>Profit</th>
          <th>Margin % (−15 adj)</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($rows as $r): ?>
          <tr>
            <td><?= h($r['name']) ?></td>
            <td><?= h($r['qty']) ?></td>
            <td>₹ <?= n2($r['rate']) ?></td>
            <td>₹ <?= n2($r['rev']) ?></td>
            <td>₹ <?= n2($r['cp']) ?></td>
            <td>₹ <?= n2($r['cost']) ?></td>
            <td class="<?= $r['profit'] >= 0 ? 'text-success' : 'text-danger' ?>">₹ <?= n2($r['profit']) ?></td>
            <td><input type="text" class="form-control form-control-sm" value="<?= n2($r['margin']) ?>%" readonly></td>
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
            <th>Item</th>
            <th>Base</th>
            <th>Transport %</th>
            <th>Transport % Amt</th>
            <th>Per-box / Per-unit Adder</th>
            <th>Allocated from Total</th>
            <th>Cost (cp)</th>
            <th>Why</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($dbg as $d): ?>
            <tr>
              <td><?= h($d['name']) ?></td>
              <td>₹ <?= n2($d['base']) ?></td>
              <td><?= n2($d['pct']) ?>%</td>
              <td>₹ <?= n2($d['pct_amt']) ?></td>
              <td>₹ <?= n2($d['adder']) ?></td>
              <td>₹ <?= n2($d['alloc']) ?></td>
              <td>₹ <?= n2($d['cp']) ?></td>
              <td><?= h($d['why']) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </details>
</div>

<div class="card p-3">
  <div class="row text-center">
    <div class="col">
      <div class="p-2 bg-light rounded">
        <div class="small text-muted">Total Revenue</div>
        <div class="fs-5">₹ <?= n2($total_rev) ?></div>
      </div>
    </div>
    <div class="col">
      <div class="p-2 bg-light rounded">
        <div class="small text-muted">Total Cost</div>
        <div class="fs-5">₹ <?= n2($total_cost) ?></div>
      </div>
    </div>
    <div class="col">
      <div class="p-2 bg-light rounded">
        <div class="small text-muted">Gross Profit</div>
        <div class="fs-5 <?= $gross >= 0 ? 'text-success' : 'text-danger' ?>">₹ <?= n2($gross) ?></div>
      </div>
    </div>
    <div class="col">
      <div class="p-2 bg-light rounded">
        <div class="small text-muted">Margin (−15 adj)</div>
        <div class="fs-5"><?= n2($margin) ?>%</div>
      </div>
    </div>
  </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
