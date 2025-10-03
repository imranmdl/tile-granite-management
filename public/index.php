<?php
// /public/index.php  — schema-aware dashboard
$page_title = "Dashboard";
require_once __DIR__ . '/../includes/header.php';
$pdo = Database::pdo();

/* ---------- tiny helpers to inspect SQLite schema ---------- */
function table_has_col(PDO $pdo, string $table, string $col): bool {
  $st = $pdo->prepare("PRAGMA table_info($table)");
  $st->execute();
  foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) if (strcasecmp($r['name'],$col)===0) return true;
  return false;
}
function first_col(PDO $pdo, string $table, array $candidates): ?string {
  foreach ($candidates as $c) if (table_has_col($pdo,$table,$c)) return $c;
  return null;
}

/* ---------- top counts ---------- */
$tiles    = (int)$pdo->query("SELECT COUNT(*) FROM tiles")->fetchColumn();
$quotes   = (int)$pdo->query("SELECT COUNT(*) FROM quotations")->fetchColumn();
$invoices = (int)$pdo->query("SELECT COUNT(*) FROM invoices")->fetchColumn();

/* ---------- time range ---------- */
$range = $_GET['range'] ?? '7';
if ($range === 'today') { $from = date('Y-m-d'); $to = date('Y-m-d'); }
elseif ($range === '30') { $from = date('Y-m-d', strtotime('-29 days')); $to = date('Y-m-d'); }
else { $from = date('Y-m-d', strtotime('-6 days')); $to = date('Y-m-d'); } // default 7

/* ---------- revenue/profit series ---------- */
/* If you created view v_invoice_pl earlier, we’ll use it (it already knows revenue/profit) */
$has_invoice_pl = (bool)$pdo->query("SELECT 1 FROM sqlite_master WHERE type IN ('view','table') AND name='v_invoice_pl'")->fetchColumn();

$series = [];
$totalRevenue = 0.0;
$totalProfit  = null;

if ($has_invoice_pl) {
  $st = $pdo->prepare("
    SELECT date(invoice_date) d, SUM(revenue) r, SUM(profit) p
    FROM v_invoice_pl
    WHERE date(invoice_date) BETWEEN :f AND :t
    GROUP BY date(invoice_date) ORDER BY d
  ");
  $st->execute([':f'=>$from, ':t'=>$to]);
  $series = $st->fetchAll(PDO::FETCH_ASSOC);
  $totalRevenue = array_sum(array_map(fn($x)=>(float)$x['r'], $series));
  $totalProfit  = array_sum(array_map(fn($x)=>(float)$x['p'], $series));
} else {
  // Build a safe revenue expression from whatever columns exist
  $inv_date_col = first_col($pdo,'invoices', ['invoice_dt','invoice_date','inv_date','created_at','created_on','date']);
  if (!$inv_date_col) $inv_date_col = 'rowid'; // last resort (not ideal, but prevents failure)

  // Prefer a line total column if you have it
  $line_total = first_col($pdo,'invoice_items', ['line_total','amount','total','net_total']);
  if ($line_total) {
    $rev_expr = "SUM(it.$line_total)";
  } else {
    // Otherwise build qty * price based on available columns
    $qty_sqft  = first_col($pdo,'invoice_items', ['qty_sqft','quantity_sqft','sqft']);
    $qty_boxes = first_col($pdo,'invoice_items', ['qty_boxes','boxes']);
    $qty_gen   = first_col($pdo,'invoice_items', ['qty','quantity']);

    if ($qty_sqft) {
      $price_sqft = first_col($pdo,'invoice_items', ['unit_price_sqft','price_sqft','rate_sqft','unit_price','price','rate']);
      $rev_expr = $price_sqft ? "SUM(it.$qty_sqft * it.$price_sqft)" : "0";
    } elseif ($qty_boxes) {
      $price_box = first_col($pdo,'invoice_items', ['unit_price_box','price_per_box','rate_box','unit_price','price','rate']);
      $rev_expr = $price_box ? "SUM(it.$qty_boxes * it.$price_box)" : "0";
    } elseif ($qty_gen) {
      $price_gen = first_col($pdo,'invoice_items', ['unit_price','price','rate']);
      $rev_expr = $price_gen ? "SUM(it.$qty_gen * it.$price_gen)" : "0";
    } else {
      $rev_expr = "0"; // nothing to compute from
    }
  }

  $sql = "
    SELECT date(inv.$inv_date_col) AS d, $rev_expr AS r
    FROM invoice_items it
    JOIN invoices inv ON inv.id = it.invoice_id
    WHERE date(inv.$inv_date_col) BETWEEN :f AND :t
    GROUP BY date(inv.$inv_date_col)
    ORDER BY d
  ";
  $st = $pdo->prepare($sql);
  $st->execute([':f'=>$from, ':t'=>$to]);
  $series = $st->fetchAll(PDO::FETCH_ASSOC);
  $totalRevenue = array_sum(array_map(fn($x)=>(float)$x['r'], $series));
  $totalProfit = null; // profit only when v_invoice_pl exists
}

/* ---------- sparkline points ---------- */
$w=180; $h=44; $pts=[];
if ($series) {
  $max = max(array_map(fn($x)=>(float)$x['r'], $series)) ?: 1;
  $step = count($series) > 1 ? ($w / (count($series)-1)) : 0;
  foreach ($series as $i=>$row) {
    $x = $i * $step;
    $y = $h - (($row['r'] / $max) * $h);
    $pts[] = $x . ',' . $y;
  }
}

/* ---------- low stock (if v_tile_stock exists) ---------- */
$lowRows = [];
$has_stock = (bool)$pdo->query("SELECT 1 FROM sqlite_master WHERE type IN ('view','table') AND name='v_tile_stock'")->fetchColumn();
if ($has_stock) {
  $q = $pdo->query("
    SELECT t.name tile, ts.label size, round(s.sqft_available,1) sqft
    FROM v_tile_stock s
    JOIN tiles t ON t.id = s.tile_id
    JOIN tile_sizes ts ON ts.id = t.size_id
    ORDER BY s.sqft_available ASC LIMIT 6
  ");
  $lowRows = $q ? $q->fetchAll(PDO::FETCH_ASSOC) : [];
}
?>
<style>
  :root{ --card-r:18px; --shadow:0 10px 30px rgba(0,0,0,.10); }
  .kpi{ border:0;border-radius:var(--card-r);box-shadow:var(--shadow);overflow:hidden }
  .kpi .big{ font-size:2.25rem;line-height:1.1;font-weight:800 }
  .kpi .emoji{ font-size:2rem }
  .card-soft{ border:0;border-radius:var(--card-r);box-shadow:var(--shadow) }
  .chip{ border-radius:999px;padding:.35rem .8rem;border:1px solid rgba(0,0,0,.08) }
  .spark-wrap{ position:relative;height:44px }
  .spark-bg{ fill:rgba(39,125,161,.08) }
  .spark-line{ fill:none;stroke:#277da1;stroke-width:2.5 }
</style>

<div class="container-xxl my-3">
  <!-- range -->
  <div class="d-flex align-items-center gap-2 mb-3">
    <span class="text-muted">Range:</span>
    <a class="chip <?= $range==='today'?'btn btn-sm btn-outline-primary':'' ?>" href="?range=today">Today</a>
    <a class="chip <?= $range==='7'?'btn btn-sm btn-outline-primary':'' ?>" href="?range=7">Last 7 days</a>
    <a class="chip <?= $range==='30'?'btn btn-sm btn-outline-primary':'' ?>" href="?range=30">Last 30 days</a>
  </div>

  <!-- KPIs -->
  <div class="row g-3">
    <div class="col-lg-4"><div class="card kpi p-3"><div class="d-flex align-items-center gap-2 mb-2"><span class="emoji">🧱</span><h6 class="m-0">Tiles</h6></div><div class="big"><?= number_format($tiles) ?></div><div class="text-muted small">Total tile records</div></div></div>
    <div class="col-lg-4"><div class="card kpi p-3"><div class="d-flex align-items-center gap-2 mb-2"><span class="emoji">🧾</span><h6 class="m-0">Quotations</h6></div><div class="big"><?= number_format($quotes) ?></div><div class="text-muted small">All time</div></div></div>
    <div class="col-lg-4"><div class="card kpi p-3"><div class="d-flex align-items-center gap-2 mb-2"><span class="emoji">💳</span><h6 class="m-0">Invoices</h6></div><div class="big"><?= number_format($invoices) ?></div><div class="text-muted small">All time</div></div></div>
  </div>

  <!-- Revenue / Profit -->
  <div class="row g-3 mt-1">
    <div class="col-lg-8">
      <div class="card card-soft p-3">
        <div class="d-flex justify-content-between align-items-center">
          <h6 class="mb-0">Revenue (<?= h($from) ?> → <?= h($to) ?>)</h6>
          <div class="text-end">
            <div class="fw-bold fs-5">₹ <?= number_format($totalRevenue,2) ?></div>
            <?php if ($totalProfit !== null): ?>
              <div class="text-success small">Profit: ₹ <?= number_format($totalProfit,2) ?></div>
            <?php else: ?>
              <div class="text-muted small">Add view <code>v_invoice_pl</code> to show profit</div>
            <?php endif; ?>
          </div>
        </div>
        <div class="spark-wrap mt-2">
          <?php $ptsStr = implode(' ', $pts ?? []); ?>
          <svg viewBox="0 0 <?= $w ?> <?= $h ?>" preserveAspectRatio="none" width="100%" height="<?= $h ?>">
            <rect class="spark-bg" x="0" y="0" width="<?= $w ?>" height="<?= $h ?>"/>
            <?php if ($ptsStr): ?><polyline class="spark-line" points="<?= $ptsStr ?>"/><?php endif; ?>
          </svg>
        </div>
      </div>
    </div>

    <div class="col-lg-4">
      <div class="card card-soft p-3 mb-3">
        <h6 class="mb-2">Low Stock</h6>
        <ul class="list-group list-group-flush">
          <?php if ($lowRows): foreach ($lowRows as $r): ?>
            <li class="list-group-item d-flex justify-content-between align-items-center">
              <div><strong><?= h($r['tile']) ?></strong><div class="text-muted small"><?= h($r['size']) ?></div></div>
              <span class="badge rounded-pill <?= ($r['sqft']<20?'text-bg-danger':($r['sqft']<50?'text-bg-warning':'text-bg-secondary')) ?>">
                <?= (float)$r['sqft'] ?> sqft
              </span>
            </li>
          <?php endforeach; else: ?>
            <li class="list-group-item text-muted">All good 👌</li>
          <?php endif; ?>
        </ul>
      </div>

      <div class="card card-soft p-3">
        <h6 class="mb-2">Quick Actions</h6>
        <div class="d-grid gap-2">
          <a class="btn btn-primary" href="/public/tiles.php">Tiles & Sizes</a>
          <a class="btn btn-success" href="/public/quotation.php">New Quotation</a>
          <a class="btn btn-warning" href="/public/invoice.php">New Invoice</a>
          <a class="btn btn-outline-primary" href="/public/inventory.php">Inventory</a>
          <a class="btn btn-secondary" href="/public/expenses.php">Add Expense</a>
        </div>
      </div>
    </div>
  </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
