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
  :root{ 
    --card-r:18px; 
    --shadow:0 10px 30px rgba(0,0,0,.10);
    --brand-primary: #8a243d;
    --brand-secondary: #0d3b66;
    --brand-accent: #ffd166;
    --gradient: linear-gradient(135deg, var(--brand-primary) 0%, var(--brand-secondary) 100%);
  }
  body { background-color: #f8f9fa; }
  .kpi{ 
    border:0;
    border-radius:var(--card-r);
    box-shadow:var(--shadow);
    overflow:hidden;
    background: white;
    transition: all 0.3s ease;
    cursor: pointer;
    position: relative;
  }
  .kpi:hover {
    transform: translateY(-5px);
    box-shadow: 0 20px 40px rgba(0,0,0,.15);
  }
  .kpi .big{ font-size:2.5rem;line-height:1.1;font-weight:800;color:var(--brand-primary) }
  .kpi .emoji{ font-size:2.5rem;margin-bottom:10px }
  .kpi::before {
    content: '';
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    height: 4px;
    background: var(--gradient);
  }
  .card-soft{ 
    border:0;
    border-radius:var(--card-r);
    box-shadow:var(--shadow);
    background: white;
    transition: all 0.3s ease;
  }
  .card-soft:hover {
    transform: translateY(-2px);
    box-shadow: 0 15px 35px rgba(0,0,0,.12);
  }
  .chip{ 
    border-radius:999px;
    padding:.35rem .8rem;
    border:1px solid rgba(0,0,0,.08);
    transition: all 0.2s ease;
    text-decoration: none;
    color: #6c757d;
  }
  .chip:hover {
    background: var(--brand-primary);
    color: white;
    border-color: var(--brand-primary);
    text-decoration: none;
    transform: scale(1.05);
  }
  .chip.btn-outline-primary {
    background: var(--brand-primary);
    color: white;
    border-color: var(--brand-primary);
  }
  .spark-wrap{ position:relative;height:44px }
  .spark-bg{ fill:rgba(138, 36, 61,.08) }
  .spark-line{ fill:none;stroke:var(--brand-primary);stroke-width:3 }
  .dashboard-header {
    background: var(--gradient);
    color: white;
    border-radius: var(--card-r);
    padding: 2rem;
    margin-bottom: 2rem;
    box-shadow: var(--shadow);
  }
  .quick-action-btn {
    border-radius: 12px;
    padding: 0.75rem 1rem;
    font-weight: 600;
    transition: all 0.3s ease;
    border: 2px solid transparent;
  }
  .quick-action-btn:hover {
    transform: translateY(-3px);
    box-shadow: 0 8px 20px rgba(0,0,0,0.15);
  }
</style>

<div class="container-xxl my-4">
  <!-- Dashboard Header -->
  <div class="dashboard-header text-center">
    <div class="row align-items-center">
      <div class="col-md-8">
        <h1 class="mb-2"><i class="bi bi-speedometer2 me-3"></i>Business Dashboard</h1>
        <p class="mb-0 opacity-75">Welcome back, <?= h(auth_username()) ?>! Here's your business overview.</p>
      </div>
      <div class="col-md-4 text-end">
        <div class="bg-white bg-opacity-10 rounded-3 p-3">
          <h6 class="mb-1">Today's Date</h6>
          <h4 class="mb-0"><?= date('M j, Y') ?></h4>
          <small class="opacity-75"><?= date('l') ?></small>
        </div>
      </div>
    </div>
  </div>
  
  <!-- range -->
  <div class="d-flex align-items-center justify-content-between mb-4">
    <div class="d-flex align-items-center gap-2">
      <span class="text-muted fw-semibold">Time Period:</span>
      <a class="chip <?= $range==='today'?'btn btn-sm btn-outline-primary':'' ?>" href="?range=today">
        <i class="bi bi-calendar-day me-1"></i>Today
      </a>
      <a class="chip <?= $range==='7'?'btn btn-sm btn-outline-primary':'' ?>" href="?range=7">
        <i class="bi bi-calendar-week me-1"></i>Last 7 days
      </a>
      <a class="chip <?= $range==='30'?'btn btn-sm btn-outline-primary':'' ?>" href="?range=30">
        <i class="bi bi-calendar-month me-1"></i>Last 30 days
      </a>
    </div>
    <div class="d-flex gap-2">
      <a href="/public/reports_dashboard_new.php" class="btn btn-outline-primary btn-sm">
        <i class="bi bi-graph-up me-1"></i>View Reports
      </a>
      <button class="btn btn-primary btn-sm" onclick="refreshDashboard()">
        <i class="bi bi-arrow-clockwise me-1"></i>Refresh
      </button>
    </div>
  </div>

  <!-- KPIs -->
  <div class="row g-4 mb-4">
    <div class="col-lg-4">
      <div class="card kpi p-4" onclick="location.href='/public/tiles.php'">
        <div class="d-flex align-items-center gap-3 mb-3">
          <span class="emoji">🧱</span>
          <div>
            <h6 class="m-0 fw-bold">Tiles Catalog</h6>
            <small class="text-muted">Manage inventory</small>
          </div>
        </div>
        <div class="big"><?= number_format($tiles) ?></div>
        <div class="text-muted small mt-1">
          <i class="bi bi-box-seam me-1"></i>Total tile products
        </div>
      </div>
    </div>
    
    <div class="col-lg-4">
      <div class="card kpi p-4" onclick="location.href='/public/quotation_list_enhanced.php'">
        <div class="d-flex align-items-center gap-3 mb-3">
          <span class="emoji">🧾</span>
          <div>
            <h6 class="m-0 fw-bold">Quotations</h6>
            <small class="text-muted">Customer quotes</small>
          </div>
        </div>
        <div class="big"><?= number_format($quotes) ?></div>
        <div class="text-muted small mt-1">
          <i class="bi bi-file-text me-1"></i>All time quotes
        </div>
      </div>
    </div>
    
    <div class="col-lg-4">
      <div class="card kpi p-4" onclick="location.href='/public/invoice_enhanced.php'">
        <div class="d-flex align-items-center gap-3 mb-3">
          <span class="emoji">💳</span>
          <div>
            <h6 class="m-0 fw-bold">Invoices</h6>
            <small class="text-muted">Sales transactions</small>
          </div>
        </div>
        <div class="big"><?= number_format($invoices) ?></div>
        <div class="text-muted small mt-1">
          <i class="bi bi-receipt me-1"></i>Total invoices
        </div>
      </div>
    </div>
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

      <div class="card card-soft p-4">
        <h6 class="mb-3 fw-bold"><i class="bi bi-lightning-charge me-2"></i>Quick Actions</h6>
        <div class="d-grid gap-3">
          <a class="btn btn-primary quick-action-btn" href="/public/inventory_summary_unified.php">
            <i class="bi bi-speedometer me-2"></i>Inventory Summary
          </a>
          <a class="btn btn-success quick-action-btn" href="/public/quotation_enhanced.php">
            <i class="bi bi-file-plus me-2"></i>New Quotation
          </a>
          <a class="btn btn-warning quick-action-btn" href="/public/invoice_enhanced.php">
            <i class="bi bi-receipt-cutoff me-2"></i>New Invoice
          </a>
          <a class="btn btn-outline-primary quick-action-btn" href="/public/tiles_inventory.php">
            <i class="bi bi-bricks me-2"></i>Tiles Inventory
          </a>
          <a class="btn btn-outline-warning quick-action-btn" href="/public/inventory_advanced.php">
            <i class="bi bi-gear-wide me-2"></i>Misc Inventory
          </a>
          <a class="btn btn-info quick-action-btn" href="/public/reports_dashboard_new.php">
            <i class="bi bi-graph-up-arrow me-2"></i>Reports
          </a>
        </div>
      </div>
    </div>
  </div>
</div>

<script>
function refreshDashboard() {
  const refreshBtn = document.querySelector('[onclick="refreshDashboard()"]');
  const originalText = refreshBtn.innerHTML;
  refreshBtn.innerHTML = '<i class="bi bi-hourglass-split me-1"></i>Refreshing...';
  refreshBtn.disabled = true;
  
  // Simulate refresh delay
  setTimeout(() => {
    location.reload();
  }, 1000);
}

// Add click animation to KPI cards
document.querySelectorAll('.kpi').forEach(card => {
  card.addEventListener('mouseenter', function() {
    this.style.transform = 'translateY(-8px) scale(1.02)';
  });
  
  card.addEventListener('mouseleave', function() {
    this.style.transform = 'translateY(0) scale(1)';
  });
});

// Auto-refresh dashboard every 5 minutes
setInterval(() => {
  // Show subtle notification
  const notification = document.createElement('div');
  notification.className = 'position-fixed top-0 end-0 p-3';
  notification.style.zIndex = '9999';
  notification.innerHTML = `
    <div class="toast show" role="alert">
      <div class="toast-header">
        <i class="bi bi-arrow-clockwise text-primary me-2"></i>
        <strong class="me-auto">Dashboard Updated</strong>
        <button type="button" class="btn-close" data-bs-dismiss="toast"></button>
      </div>
      <div class="toast-body">
        Data refreshed automatically
      </div>
    </div>
  `;
  document.body.appendChild(notification);
  
  // Remove notification after 3 seconds
  setTimeout(() => {
    notification.remove();
  }, 3000);
}, 300000); // 5 minutes

// Real-time clock
function updateClock() {
  const now = new Date();
  const timeString = now.toLocaleTimeString();
  const clockElement = document.querySelector('.dashboard-header h4');
  if (clockElement) {
    clockElement.textContent = now.toLocaleDateString('en-US', {
      month: 'short',
      day: 'numeric',
      year: 'numeric'
    });
  }
}

setInterval(updateClock, 60000); // Update every minute
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
