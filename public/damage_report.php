<?php
/**
 * public/damage_report.php
 * Damage Report (schema-aware)
 * - Uses shared range UI/logic (Today / 15d / Month / Year / Custom)
 * - Dynamically inspects table columns and only references real ones
 * - Supports tiles + misc items, damage in boxes + sqft
 * - Robust transport math; date filter only when a date column exists
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/calc_cost.php';   // h(), n2(), n3(), table_exists(), column_exists()
require_once __DIR__ . '/../includes/report_range.php'; // compute_range(), render_range_controls(), range_where(), bind_range()
require_admin();

$pdo = Database::pdo();
$rng = compute_range();
$page_title = "Damage Report — " . $rng['label'];

/* ------------------------- helpers ------------------------- */
function has_col(PDO $pdo, string $table, string $col): bool {
  try { return column_exists($pdo, $table, $col); } catch (Throwable $e) { return false; }
}

/**
 * Build a COALESCE(expr1, expr2, ..., default) **only** from columns that exist.
 */
function coalesce_existing(PDO $pdo, string $table, string $alias, array $candidates, string $default = '0'): string {
  $parts = [];
  foreach ($candidates as $c) {
    if (has_col($pdo, $table, $c)) $parts[] = "$alias.$c";
  }
  if (!$parts) return $default;
  $list = implode(', ', $parts);
  return "COALESCE($list, $default)";
}

/** Find the first available column from a list. Return NULL if none present. */
function first_existing(PDO $pdo, string $table, array $candidates): ?string {
  foreach ($candidates as $c) if (has_col($pdo, $table, $c)) return $c;
  return null;
}

/* ------------------------- date columns ------------------------- */
$tile_date_col = first_existing($pdo, 'inventory_items',      ['purchase_dt','received_date','created_at','created_on','dt']);
$misc_date_col = table_exists($pdo,'misc_inventory_items')
  ? first_existing($pdo, 'misc_inventory_items', ['purchase_dt','received_date','created_at','created_on','dt'])
  : null;

$noteBits = [];
if (!$tile_date_col) $noteBits[] = 'Tiles inventory has no purchase/received/created date column; date filter ignored.';
if (table_exists($pdo,'misc_inventory_items') && !$misc_date_col) $noteBits[] = 'Other items inventory has no date column; date filter ignored.';
$note = $noteBits ? ('Note: '.implode(' ', $noteBits)) : '';

/* =====================================================================
 * 1) TILES
 * ===================================================================== */
$tile_select = [];
$tile_select[] = 'ii.id';
$tile_select[] = 't.name AS tile_name';
$tile_select[] = 'ts.label AS size_label';
$tile_select[] = 'ts.sqft_per_box AS spb';

$tile_boxes_in_expr  = coalesce_existing($pdo,'inventory_items','ii', ['boxes_in','number_of_boxes','total_boxes'], '0');
$tile_dmg_boxes_expr = coalesce_existing($pdo,'inventory_items','ii', ['damage_boxes','damage_in_boxes'], '0');
$tile_dmg_sqft_expr  = coalesce_existing($pdo,'inventory_items','ii', ['damage_sqft'], '0');

$tile_per_box_expr   = coalesce_existing($pdo,'inventory_items','ii', ['per_box_value','purchase_box_value'], '0');
$tile_per_sqft_expr  = coalesce_existing($pdo,'inventory_items','ii', ['per_sqft_value'], '0');

$tile_tr_pct_expr    = coalesce_existing($pdo,'inventory_items','ii', ['transport_pct','transport_percent'], '0');
$tile_tr_pbox_expr   = coalesce_existing($pdo,'inventory_items','ii', ['transport_per_box'], '0');
$tile_tr_total_expr  = coalesce_existing($pdo,'inventory_items','ii', ['transport_total'], '0');

$tile_select[] = "$tile_boxes_in_expr  AS boxes_in";
$tile_select[] = "$tile_dmg_boxes_expr AS damage_boxes";
$tile_select[] = "$tile_dmg_sqft_expr  AS damage_sqft";
$tile_select[] = "$tile_per_box_expr   AS per_box_value";
$tile_select[] = "$tile_per_sqft_expr  AS per_sqft_value";
$tile_select[] = "$tile_tr_pct_expr    AS transport_pct";
$tile_select[] = "$tile_tr_pbox_expr   AS transport_per_box";
$tile_select[] = "$tile_tr_total_expr  AS transport_total";

$tile_sql  = "SELECT ".implode(",\n       ", $tile_select)."
              FROM inventory_items ii
              JOIN tiles t       ON t.id  = ii.tile_id
              JOIN tile_sizes ts ON ts.id = t.size_id";

if ($tile_date_col) {
  $tile_sql .= " WHERE ".range_where("ii.$tile_date_col")." ORDER BY ii.id DESC";
} else {
  $tile_sql .= " ORDER BY ii.id DESC";
}

$tile_rows = [];
try {
  if ($tile_date_col) {
    $st = $pdo->prepare($tile_sql);
    bind_range($st, $rng);
    $st->execute();
  } else {
    $st = $pdo->query($tile_sql);
  }
  $tile_rows = $st ? $st->fetchAll(PDO::FETCH_ASSOC) : [];
} catch (Throwable $e) {
  $note .= ($note ? ' ' : 'Note: ') . 'Tiles query failed: ' . h($e->getMessage());
  $tile_rows = [];
}

/* =====================================================================
 * 2) MISC ITEMS
 * ===================================================================== */
$misc_rows = [];
if (table_exists($pdo,'misc_inventory_items')) {
  $misc_select = [];
  $misc_select[] = 'mi.id';
  $misc_select[] = 'm.name AS item_name';
  $misc_unit_col = has_col($pdo,'misc_items','unit_label') ? 'm.unit_label' : "'unit'";
  $misc_select[] = "$misc_unit_col AS unit_label";

  $misc_qty_in_expr   = coalesce_existing($pdo,'misc_inventory_items','mi', ['qty_in','quantity_in','units_in'], '0');
  $misc_dmg_units_expr= coalesce_existing($pdo,'misc_inventory_items','mi', ['damage_units','damage_qty'], '0');
  $misc_cpu_expr      = coalesce_existing($pdo,'misc_inventory_items','mi', ['cost_per_unit','per_unit_value'], '0');
  $misc_tr_pct_expr   = coalesce_existing($pdo,'misc_inventory_items','mi', ['transport_pct','transport_percent'], '0');
  $misc_tr_pu_expr    = coalesce_existing($pdo,'misc_inventory_items','mi', ['transport_per_unit'], '0');
  $misc_tr_total_expr = coalesce_existing($pdo,'misc_inventory_items','mi', ['transport_total'], '0');

  $misc_select[] = "$misc_qty_in_expr    AS qty_in";
  $misc_select[] = "$misc_dmg_units_expr AS damage_units";
  $misc_select[] = "$misc_cpu_expr       AS cost_per_unit";
  $misc_select[] = "$misc_tr_pct_expr    AS transport_pct";
  $misc_select[] = "$misc_tr_pu_expr     AS transport_per_unit";
  $misc_select[] = "$misc_tr_total_expr  AS transport_total";

  $misc_sql  = "SELECT ".implode(",\n       ", $misc_select)."
                FROM misc_inventory_items mi
                JOIN misc_items m ON m.id = mi.misc_item_id";

  if ($misc_date_col) {
    $misc_sql .= " WHERE ".range_where("mi.$misc_date_col")." ORDER BY mi.id DESC";
  } else {
    $misc_sql .= " ORDER BY mi.id DESC";
  }

  try {
    if ($misc_date_col) {
      $stm = $pdo->prepare($misc_sql);
      bind_range($stm, $rng);
      $stm->execute();
    } else {
      $stm = $pdo->query($misc_sql);
    }
    $misc_rows = $stm ? $stm->fetchAll(PDO::FETCH_ASSOC) : [];
  } catch (Throwable $e) {
    $note .= ($note ? ' ' : 'Note: ') . 'Misc query failed: ' . h($e->getMessage());
    $misc_rows = [];
  }
}

/* ------------------------- Render ------------------------- */
require_once __DIR__ . '/../includes/header.php';
render_range_controls();

$sum = 0.0;
?>
<div class="card p-3 mb-3">
  <?php if ($note): ?>
    <div class="alert alert-warning mb-0"><?= h($note) ?></div>
  <?php else: ?>
    <div class="text-muted small">Showing results for: <strong><?= h($rng['from_display']) ?> → <?= h($rng['to_display']) ?></strong></div>
  <?php endif; ?>
</div>

<div class="card p-3 mb-3">
  <h5>Tiles Damage</h5>
  <div class="table-responsive">
    <table class="table table-striped table-sm align-middle">
      <thead>
        <tr>
          <th>#</th><th>Tile</th><th>Size</th>
          <th class="text-end">Boxes In</th>
          <th class="text-end">Damage (Boxes)</th>
          <th class="text-end">Damage (Sqft)</th>
          <th class="text-end">Cost/Box (incl. transport)</th>
          <th class="text-end">Damage Cost</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($tile_rows as $r):
          $boxes_in     = (float)$r['boxes_in'];
          $dmg_boxes    = (float)$r['damage_boxes'];
          $dmg_sqft     = (float)$r['damage_sqft'];
          $spb          = max(0.000001, (float)$r['spb']);

          // base cost per box
          $base_box = (float)$r['per_box_value'];
          if ($base_box <= 0 && (float)$r['per_sqft_value'] > 0) {
            $base_box = (float)$r['per_sqft_value'] * $spb;
          }

          $transport = 0.0;
          if ((float)$r['transport_pct'] > 0) {
            $transport += $base_box * ((float)$r['transport_pct'] / 100.0);
          }
          if ((float)$r['transport_per_box'] > 0) {
            $transport += (float)$r['transport_per_box'];
          }

          $net_boxes = max(0.0, $boxes_in - $dmg_boxes);
          if ((float)$r['transport_total'] > 0 && $net_boxes > 0) {
            $transport += ((float)$r['transport_total'] / $net_boxes);
          }

          $cpb = $base_box + $transport;
          $dmg_boxes_equiv = $dmg_boxes + ($dmg_sqft / $spb);
          $damage_cost = $cpb * $dmg_boxes_equiv;
          $sum += $damage_cost;
        ?>
          <tr>
            <td>#<?= (int)$r['id'] ?></td>
            <td><?= h($r['tile_name']) ?></td>
            <td><?= h($r['size_label']) ?></td>
            <td class="text-end"><?= n3($boxes_in) ?></td>
            <td class="text-end text-danger"><?= n3($dmg_boxes) ?></td>
            <td class="text-end text-danger"><?= n3($dmg_sqft) ?></td>
            <td class="text-end">₹ <?= n2($cpb) ?></td>
            <td class="text-end text-danger">₹ <?= n2($damage_cost) ?></td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$tile_rows): ?>
          <tr><td colspan="8" class="text-muted">No tile records.</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php if ($misc_rows): ?>
<div class="card p-3 mb-3">
  <h5>Other Items Damage</h5>
  <div class="table-responsive">
    <table class="table table-striped table-sm align-middle">
      <thead>
        <tr>
          <th>#</th><th>Item</th><th>Unit</th>
          <th class="text-end">Qty In</th>
          <th class="text-end">Damage Units</th>
          <th class="text-end">Cost/Unit (incl. transport)</th>
          <th class="text-end">Damage Cost</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($misc_rows as $r):
          $qty_in        = (float)$r['qty_in'];
          $dmg_units     = (float)$r['damage_units'];
          $base_unit     = (float)$r['cost_per_unit'];

          $transport = 0.0;
          if ((float)$r['transport_pct'] > 0) {
            $transport += $base_unit * ((float)$r['transport_pct'] / 100.0);
          }
          if ((float)$r['transport_per_unit'] > 0) {
            $transport += (float)$r['transport_per_unit'];
          }

          $net_units = max(0.0, $qty_in - $dmg_units);
          if ((float)$r['transport_total'] > 0 && $net_units > 0) {
            $transport += ((float)$r['transport_total'] / $net_units);
          }

          $cpu = $base_unit + $transport;
          $dc  = $cpu * $dmg_units;
          $sum += $dc;
        ?>
          <tr>
            <td>#<?= (int)$r['id'] ?></td>
            <td><?= h($r['item_name']) ?></td>
            <td><?= h($r['unit_label']) ?></td>
            <td class="text-end"><?= n3($qty_in) ?></td>
            <td class="text-end text-danger"><?= n3($dmg_units) ?></td>
            <td class="text-end">₹ <?= n2($cpu) ?></td>
            <td class="text-end text-danger">₹ <?= n2($dc) ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<div class="card p-3">
  <strong>Total Damage (info only):</strong> ₹ <?= n2($sum) ?>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
