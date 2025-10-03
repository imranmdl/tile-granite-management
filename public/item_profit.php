<?php
// public/item_profit.php
$page_title = "Item Profit (with Inventory Totals)";
require_once __DIR__ . '/../includes/header.php';   // brings in Bootstrap, helpers (h, n2, n3), auth, etc.
require_once __DIR__ . '/../includes/Database.php';

$pdo = Database::pdo();
date_default_timezone_set('Asia/Kolkata');

/**
 * ---- Date range handling ----
 * Profit metrics (revenue / boxes sold / estimated cost / margin) respect this window.
 * Lifetime inventory totals (total boxes in, amount paid, available boxes) are NOT date-limited.
 */
function yyyy_mm_dd($ts){ return date('Y-m-d', $ts); }

$today = yyyy_mm_dd(time());
$range = $_GET['range'] ?? '30d';  // quick pills: today, 15d, 30d, 1m, 1y, custom
$from = $_GET['from'] ?? '';
$to   = $_GET['to']   ?? '';

if ($range !== 'custom') {
  switch ($range) {
    case 'today':
      $from = $today;
      $to   = $today;
      break;
    case '15d':
      $from = yyyy_mm_dd(strtotime('-15 days'));
      $to   = $today;
      break;
    case '30d':
      $from = yyyy_mm_dd(strtotime('-30 days'));
      $to   = $today;
      break;
    case '1m':
      $from = yyyy_mm_dd(strtotime('-1 month'));
      $to   = $today;
      break;
    case '1y':
      $from = yyyy_mm_dd(strtotime('-1 year'));
      $to   = $today;
      break;
    default:
      $from = yyyy_mm_dd(strtotime('-30 days'));
      $to   = $today;
  }
} else {
  // sanitize custom inputs; fallback if empty/bad
  if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from ?? '')) $from = yyyy_mm_dd(strtotime('-30 days'));
  if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $to   ?? '')) $to   = $today;
}
// we’ll use [from, toNext) half-open interval for safer date comparisons
$toNext = yyyy_mm_dd(strtotime($to . ' +1 day'));

/**
 * ---- Main query ----
 * - period: sums revenue and boxes sold within the selected date range
 * - inv: lifetime inventory aggregation (total boxes added, total amount paid, good boxes)
 * - sold_life / ret_life: lifetime sold and returned boxes for available stock calc
 *
 * Estimated cost within the period = period.boxes_sold * (inv.total_amount_paid / inv.good_boxes)
 * (i.e., lifetime weighted average cost/box)
 */
$sql = "
SELECT
  t.id,
  t.name AS tile_name,
  s.label AS size_label,

  COALESCE(p.revenue_period, 0)            AS revenue,
  COALESCE(p.boxes_sold_period, 0)         AS boxes_sold,

  -- average cost per good box across lifetime inventory
  CASE WHEN COALESCE(inv.good_boxes,0) > 0
       THEN inv.total_amount_paid / inv.good_boxes
       ELSE 0
  END                                       AS avg_cost_per_box,

  -- estimated cost for the selected period
  COALESCE(p.boxes_sold_period,0) * 
  CASE WHEN COALESCE(inv.good_boxes,0) > 0
       THEN inv.total_amount_paid / inv.good_boxes
       ELSE 0
  END                                       AS cost_total,

  -- profit & margin for the selected period
  COALESCE(p.revenue_period,0)
  - (COALESCE(p.boxes_sold_period,0) * 
     CASE WHEN COALESCE(inv.good_boxes,0) > 0
          THEN inv.total_amount_paid / inv.good_boxes
          ELSE 0
     END)                                   AS profit,

  CASE WHEN COALESCE(p.revenue_period,0) > 0
       THEN (
         COALESCE(p.revenue_period,0)
         - (COALESCE(p.boxes_sold_period,0) *
            CASE WHEN COALESCE(inv.good_boxes,0) > 0
                 THEN inv.total_amount_paid / inv.good_boxes
                 ELSE 0
            END)
       ) * 100.0 / COALESCE(p.revenue_period,0)
       ELSE 0
  END                                       AS margin_pct,

  -- NEW COLUMNS (lifetime / snapshot)
  COALESCE(inv.total_boxes_added,0)         AS total_boxes_added,
  COALESCE(inv.total_amount_paid,0)         AS total_amount_paid,
  ROUND(COALESCE(inv.good_boxes,0)
        - COALESCE(sl.boxes_sold_life,0)
        + COALESCE(rl.boxes_returned_life,0), 3) AS available_boxes

FROM tiles t
JOIN tile_sizes s ON s.id = t.size_id

-- Period aggregation: revenue & boxes sold in range
LEFT JOIN (
  SELECT ii.tile_id,
         SUM(ii.boxes_decimal) AS boxes_sold_period,
         SUM(ii.line_total)    AS revenue_period
  FROM invoice_items ii
  JOIN invoices i ON i.id = ii.invoice_id
  WHERE i.invoice_dt >= :from AND i.invoice_dt < :toNext
  GROUP BY ii.tile_id
) p ON p.tile_id = t.id

-- Lifetime inventory aggregation
LEFT JOIN (
  SELECT
    inv.tile_id,

    -- 1) total boxes added (lifetime)
    SUM(inv.boxes_in) AS total_boxes_added,

    -- 2) total amount paid (base + %transport + per-box + lump-sum)
    SUM(
      -- base per box
      (CASE
         WHEN inv.per_box_value  > 0 THEN inv.per_box_value
         WHEN inv.per_sqft_value > 0 THEN inv.per_sqft_value * s2.sqft_per_box
         ELSE 0
       END) * inv.boxes_in
      -- percent transport on base
      + (CASE
           WHEN inv.per_box_value  > 0 THEN inv.per_box_value
           WHEN inv.per_sqft_value > 0 THEN inv.per_sqft_value * s2.sqft_per_box
           ELSE 0
         END) * (COALESCE(inv.transport_pct,0)/100.0) * inv.boxes_in
      -- per-box transport
      + COALESCE(inv.transport_per_box,0) * inv.boxes_in
      -- lump-sum transport (count once per receipt row)
      + COALESCE(inv.transport_total,0)
    ) AS total_amount_paid,

    -- 3) lifetime good boxes (net of damage boxes & damage sqft)
    SUM(
      inv.boxes_in
      - COALESCE(inv.damage_boxes,0)
      - (COALESCE(inv.damage_sqft,0) / s2.sqft_per_box)
    ) AS good_boxes

  FROM inventory_items inv
  JOIN tiles t2      ON t2.id = inv.tile_id
  JOIN tile_sizes s2 ON s2.id = t2.size_id
  GROUP BY inv.tile_id
) inv ON inv.tile_id = t.id

-- Lifetime sold boxes (for available calc)
LEFT JOIN (
  SELECT ii.tile_id, SUM(ii.boxes_decimal) AS boxes_sold_life
  FROM invoice_items ii
  GROUP BY ii.tile_id
) sl ON sl.tile_id = t.id

-- Lifetime returned boxes (for available calc)
LEFT JOIN (
  SELECT iri.tile_id, SUM(iri.boxes_decimal) AS boxes_returned_life
  FROM invoice_return_items iri
  GROUP BY iri.tile_id
) rl ON rl.tile_id = t.id

ORDER BY t.name ASC, s.label ASC
";

$st = $pdo->prepare($sql);
$st->execute([':from' => $from, ':toNext' => $toNext]);
$rows = $st->fetchAll(PDO::FETCH_ASSOC);

?>
<div class="card p-3 mb-3">
  <form class="row gy-2 gx-2 align-items-end" method="get">
    <div class="col-auto">
      <label class="form-label mb-1">Quick Range</label>
      <select name="range" class="form-select">
        <?php
          $opts = ['today'=>'Today','15d'=>'Last 15 Days','30d'=>'Last 30 Days','1m'=>'Last 1 Month','1y'=>'Last 1 Year','custom'=>'Custom'];
          foreach ($opts as $k=>$v) {
            $sel = ($range===$k) ? 'selected' : '';
            echo "<option value=\"".h($k)."\" $sel>".h($v)."</option>";
          }
        ?>
      </select>
    </div>
    <div class="col-auto">
      <label class="form-label mb-1">From</label>
      <input type="date" name="from" value="<?=h($from)?>" class="form-control">
    </div>
    <div class="col-auto">
      <label class="form-label mb-1">To</label>
      <input type="date" name="to" value="<?=h($to)?>" class="form-control">
    </div>
    <div class="col-auto">
      <button class="btn btn-primary">Apply</button>
    </div>
    <div class="col-auto ms-auto text-muted">
      <small>Showing profits from <strong><?=h($from)?></strong> to <strong><?=h($to)?></strong> (inclusive)</small>
    </div>
  </form>
</div>

<div class="card p-3">
  <h5 class="mb-3">Item Profit</h5>
  <div class="table-responsive">
    <table class="table table-striped align-middle">
      <thead>
        <tr>
          <th>#</th>
          <th>Tile</th>
          <th>Size</th>
          <th class="text-end">Revenue (₹)</th>
          <th class="text-end">Boxes Sold</th>
          <th class="text-end">Avg Cost/Box (₹)</th>
          <th class="text-end">Est. Cost (₹)</th>
          <th class="text-end">Profit (₹)</th>
          <th class="text-end">Margin %</th>
          <!-- NEW -->
          <th class="text-end">Total Boxes (Inventory)</th>
          <th class="text-end">Total Amount Paid (₹)</th>
          <th class="text-end">Available Boxes</th>
        </tr>
      </thead>
      <tbody>
        <?php if (!$rows): ?>
          <tr><td colspan="12" class="text-center text-muted">No data.</td></tr>
        <?php else:
          $i=0;
          $sum_rev=$sum_boxes=$sum_cost=$sum_profit=0;
          $sum_total_boxes_added=0; $sum_total_amount_paid=0; $sum_available=0;
          foreach ($rows as $r):
            $i++;
            $rev   = (float)$r['revenue'];
            $boxes = (float)$r['boxes_sold'];
            $avgc  = (float)$r['avg_cost_per_box'];
            $cost  = (float)$r['cost_total'];
            $prof  = (float)$r['profit'];
            $marg  = (float)$r['margin_pct'];
            $tba   = (float)$r['total_boxes_added'];
            $tap   = (float)$r['total_amount_paid'];
            $avail = (float)$r['available_boxes'];

            $sum_rev += $rev; $sum_boxes += $boxes; $sum_cost += $cost; $sum_profit += $prof;
            $sum_total_boxes_added += $tba; $sum_total_amount_paid += $tap; $sum_available += $avail;
        ?>
          <tr>
            <td><?= $i ?></td>
            <td><?= h($r['tile_name']) ?></td>
            <td><?= h($r['size_label']) ?></td>
            <td class="text-end"><?= n2($rev) ?></td>
            <td class="text-end"><?= n3($boxes) ?></td>
            <td class="text-end"><?= n2($avgc) ?></td>
            <td class="text-end"><?= n2($cost) ?></td>
            <td class="text-end"><?= n2($prof) ?></td>
            <td class="text-end"><?= n2($marg) ?></td>
            <!-- NEW -->
            <td class="text-end"><?= n3($tba) ?></td>
            <td class="text-end"><?= n2($tap) ?></td>
            <td class="text-end"><?= n3($avail) ?></td>
          </tr>
        <?php endforeach; ?>
          <tr class="table-light fw-semibold">
            <td colspan="3" class="text-end">Totals</td>
            <td class="text-end"><?= n2($sum_rev) ?></td>
            <td class="text-end"><?= n3($sum_boxes) ?></td>
            <td class="text-end">—</td>
            <td class="text-end"><?= n2($sum_cost) ?></td>
            <td class="text-end"><?= n2($sum_profit) ?></td>
            <td class="text-end"><?= n2($sum_rev > 0 ? ($sum_profit * 100.0 / $sum_rev) : 0) ?></td>
            <!-- NEW -->
            <td class="text-end"><?= n3($sum_total_boxes_added) ?></td>
            <td class="text-end"><?= n2($sum_total_amount_paid) ?></td>
            <td class="text-end"><?= n3($sum_available) ?></td>
          </tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
  <div class="text-muted small">
    <ul class="mb-0">
      <li><strong>Total Boxes (Inventory)</strong> = lifetime sum of boxes received.</li>
      <li><strong>Total Amount Paid</strong> = Σ (base×boxes + base×transport%×boxes + transport_per_box×boxes + transport_total).</li>
      <li><strong>Available Boxes</strong> = lifetime good boxes − lifetime boxes sold + lifetime boxes returned.</li>
      <li><em>Estimated cost</em> in this report window uses lifetime weighted avg cost/box.</li>
    </ul>
  </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
