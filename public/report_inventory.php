<?php
// public/report_inventory.php — Read-only Inventory Report (Tiles + Misc) with CSV export
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/report_range.php'; // range helpers
require_login();

$pdo = Database::pdo();
$rng = compute_range();
$page_title = 'Inventory Report — ' . $rng['label'];

/* ---------- helpers (local) ---------- */
function available_boxes(PDO $pdo, int $tile_id): float {
  $st = $pdo->prepare("SELECT COALESCE(SUM(boxes_in - damage_boxes),0) FROM inventory_items WHERE tile_id=?");
  $st->execute([$tile_id]); $good = (float)$st->fetchColumn();

  $st = $pdo->prepare("SELECT COALESCE(SUM(boxes_decimal),0) FROM invoice_items WHERE tile_id=?");
  $st->execute([$tile_id]); $sold = (float)$st->fetchColumn();

  $st = $pdo->prepare("SELECT COALESCE(SUM(boxes_decimal),0) FROM invoice_return_items WHERE tile_id=?");
  $st->execute([$tile_id]); $ret = (float)$st->fetchColumn();

  return max(0.0, $good - $sold + $ret);
}
function base_per_box(array $r, float $spb): float {
  $base = (float)($r['per_box_value'] ?? 0);
  if ($base <= 0 && (float)($r['per_sqft_value'] ?? 0) > 0) {
    $base = (float)$r['per_sqft_value'] * $spb;
  }
  return $base;
}
function total_trans_per_box(array $r, float $spb): float {
  $base = base_per_box($r, $spb);
  $pct  = (float)($r['transport_pct'] ?? 0.0);
  $from_pct = $base * ($pct/100.0);
  $per_box  = (float)($r['transport_per_box'] ?? 0.0);

  $in  = (float)($r['boxes_in'] ?? 0);
  $dam = (float)($r['damage_boxes'] ?? 0);
  $nb  = max(0.0, $in - $dam);
  $tr_total   = (float)($r['transport_total'] ?? 0.0);
  $from_total = ($tr_total > 0 && $nb > 0) ? ($tr_total / $nb) : 0.0;

  return $from_pct + $per_box + $from_total;
}
function cost_box_incl(array $r, float $spb): float {
  return base_per_box($r,$spb) + total_trans_per_box($r,$spb);
}
function net_units_row(array $r): float {
  $in  = (float)($r['qty_in'] ?? 0);
  $dam = (float)($r['damage_units'] ?? 0);
  return max(0.0, $in - $dam);
}
function cost_unit_incl(array $r): float {
  $base = (float)($r['cost_per_unit'] ?? 0);
  $pct  = (float)($r['transport_pct'] ?? 0.0);
  $from_pct   = $base * ($pct/100.0);
  $per_unit   = (float)($r['transport_per_unit'] ?? 0.0);
  $nu         = net_units_row($r);
  $tr_total   = (float)($r['transport_total'] ?? 0.0);
  $from_total = ($tr_total > 0 && $nu > 0) ? ($tr_total / $nu) : 0.0;
  return $base + $from_pct + $per_unit + $from_total;
}

/* ---------- fetch rows ----------
   NOTE: This report is a point-in-time snapshot.
   If you want to filter by received date later, wire range_where(...) once
   you confirm column names (e.g., ii.received_date, mi.received_date).
*/
$tiles = $pdo->query("
  SELECT ii.*, t.name AS tile_name, ts.label AS size_label, ts.sqft_per_box AS spb, t.id AS tile_id
  FROM inventory_items ii
  JOIN tiles t       ON t.id = ii.tile_id
  JOIN tile_sizes ts ON ts.id = t.size_id
  ORDER BY t.name, ii.id DESC
")->fetchAll(PDO::FETCH_ASSOC);

$misc = $pdo->query("
  SELECT mi.*, m.name AS item_name, m.unit_label
  FROM misc_inventory_items mi
  JOIN misc_items m ON m.id = mi.misc_item_id
  ORDER BY m.name, mi.id DESC
")->fetchAll(PDO::FETCH_ASSOC);

/* ---------- compute (Tiles) ---------- */
$tile_rows = [];
$tile_total_value = 0.0;

foreach ($tiles as $r) {
  $spb   = (float)($r['spb'] ?? 0);
  $cpb   = cost_box_incl($r, $spb);
  $avail = available_boxes($pdo, (int)$r['tile_id']);
  $value = $avail * $cpb;
  $tile_total_value += $value;

  $tile_rows[] = [
    'tile'   => $r['tile_name'],
    'size'   => $r['size_label'],
    'avail'  => $avail,
    'cpb'    => $cpb,
    'value'  => $value,
  ];
}

/* ---------- compute (Other Items) ---------- */
$misc_map = [];
foreach ($misc as $r) {
  $key = $r['item_name'].'|'.$r['unit_label'];
  if (!isset($misc_map[$key])) {
    $misc_map[$key] = ['item'=>$r['item_name'], 'unit'=>$r['unit_label'], 'net'=>0.0, 'cpu'=>0.0, 'value'=>0.0];
  }
  $nu  = net_units_row($r);
  $cpu = cost_unit_incl($r);
  $misc_map[$key]['net']   += $nu;
  $misc_map[$key]['value'] += ($nu * $cpu);
  $misc_map[$key]['cpu']    = $cpu; // latest
}
$misc_rows = array_values($misc_map);
$misc_total_value = array_reduce($misc_rows, function($s,$x){ return $s + (float)$x['value']; }, 0.0);

$grand_total = $tile_total_value + $misc_total_value;

/* ---------- CSV export ---------- */
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
  header('Content-Type: text/csv');
  header('Content-Disposition: attachment; filename="inventory_report.csv"');
  $out = fopen('php://output', 'w');
  fputcsv($out, ['Section','Name','Size/Unit','Available','Cost (incl.)','Total Value']);
  foreach ($tile_rows as $r) {
    fputcsv($out, ['Tiles', $r['tile'], $r['size'], n3($r['avail']), n2($r['cpb']), n2($r['value'])]);
  }
  foreach ($misc_rows as $r) {
    fputcsv($out, ['Misc', $r['item'], $r['unit'], n3($r['net']), n2($r['cpu']), n2($r['value'])]);
  }
  fputcsv($out, ['TOTAL','','','', '', n2($grand_total)]);
  fclose($out);
  exit;
}

/* ---------- Render ---------- */
require_once __DIR__ . '/../includes/header.php';
?>
<style>
  .table-sm td, .table-sm th { padding:.55rem .75rem; }
  .text-end { text-align:right; }
</style>

<?php render_range_controls(); ?>

<div class="card p-3 mb-3">
  <div class="d-flex justify-content-between align-items-center">
    <h5 class="mb-0"><?= h($page_title) ?></h5>
    <a class="btn btn-sm btn-outline-secondary" href="?export=csv">Export CSV</a>
  </div>
</div>

<div class="card p-3 mb-3">
  <h6 class="mb-2">Tiles</h6>
  <div class="table-responsive">
    <table class="table table-striped table-bordered table-sm align-middle">
      <thead>
        <tr>
          <th>Tile</th>
          <th>Size</th>
          <th class="text-end">Available (boxes)</th>
          <th class="text-end">Cost/Box (incl.)</th>
          <th class="text-end">Stock Value</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($tile_rows as $r): ?>
          <tr>
            <td><?= h($r['tile']) ?></td>
            <td><?= h($r['size']) ?></td>
            <td class="text-end">
              <?php if ($r['avail'] > 0): ?>
                <?= n3($r['avail']) ?>
              <?php else: ?>
                <span class="text-danger">Not available</span>
              <?php endif; ?>
            </td>
            <td class="text-end">₹ <?= n2($r['cpb']) ?></td>
            <td class="text-end">₹ <?= n2($r['value']) ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
      <tfoot>
        <tr>
          <th colspan="4" class="text-end">Tiles Total</th>
          <th class="text-end">₹ <?= n2($tile_total_value) ?></th>
        </tr>
      </tfoot>
    </table>
  </div>
</div>

<div class="card p-3 mb-3">
  <h6 class="mb-2">Other Items</h6>
  <div class="table-responsive">
    <table class="table table-striped table-bordered table-sm align-middle">
      <thead>
        <tr>
          <th>Item</th>
          <th>Unit</th>
          <th class="text-end">Available</th>
          <th class="text-end">Cost/Unit (incl.)</th>
          <th class="text-end">Stock Value</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($misc_rows as $r): ?>
          <tr>
            <td><?= h($r['item']) ?></td>
            <td><?= h($r['unit']) ?></td>
            <td class="text-end">
              <?php if ($r['net'] > 0): ?>
                <?= n3($r['net']) ?>
              <?php else: ?>
                <span class="text-danger">Not available</span>
              <?php endif; ?>
            </td>
            <td class="text-end">₹ <?= n2($r['cpu']) ?></td>
            <td class="text-end">₹ <?= n2($r['value']) ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
      <tfoot>
        <tr>
          <th colspan="4" class="text-end">Other Items Total</th>
          <th class="text-end">₹ <?= n2($misc_total_value) ?></th>
        </tr>
      </tfoot>
    </table>
  </div>
</div>

<div class="card p-3">
  <div class="row">
    <div class="col-md-6">
      <div class="p-2 bg-light rounded">
        <div class="small text-muted">Grand Inventory Value</div>
        <div class="fs-5">₹ <?= n2($grand_total) ?></div>
      </div>
    </div>
  </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
