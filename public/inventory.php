<?php
// public/inventory.php — Manage inventory + “Recently Added” (Today / 2d / 5d / 7d)

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';
require_login();

$pdo = Database::pdo();

/* ---------- helpers ---------- */
if (!function_exists('table_exists')) {
  function table_exists(PDO $pdo, string $table): bool {
    try {
      $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
      if ($driver === 'mysql') {
        $st = $pdo->prepare("SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?");
        $st->execute([$table]);
        return (bool)$st->fetchColumn();
      } else { // sqlite & others
        $st = $pdo->prepare("SELECT name FROM sqlite_master WHERE type='table' AND name=?");
        $st->execute([$table]);
        return (bool)$st->fetchColumn();
      }
    } catch (Throwable $e) {
      return false;
    }
  }
}
function P($k,$d=null){ return $_POST[$k]??$d; }
function Pn($k){ $v=P($k,0); return is_numeric($v)?(float)$v:0.0; }
function Pid($k){ $v=P($k,0); return is_numeric($v)?(int)$v:0; }

/* Which window?  today (default) / 2d / 5d / 7d */
function get_recent_mode(): string {
  $m = strtolower(trim($_GET['recent'] ?? 'today'));
  return in_array($m, ['today','2d','5d','7d'], true) ? $m : 'today';
}
$mode = get_recent_mode();

switch ($mode) {
  case '2d':  $daysBack = 1; break;  // today + yesterday
  case '5d':  $daysBack = 4; break;
  case '7d':  $daysBack = 6; break;
  case 'today':
  default:    $daysBack = 0; break;  // only today
}

/* ===========================================================
   HANDLE POST (before any HTML)
   =========================================================== */
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['add_stock'])) {
  $tile_id           = Pid('tile_id');
  $recvd_dt          = (string)P('recvd_dt', date('Y-m-d')); // default today
  $boxes_in          = Pn('boxes_in');
  $damage_boxes      = Pn('damage_boxes');
  $per_box_value     = Pn('per_box_value');
  $per_sqft_value    = Pn('per_sqft_value');
  $transport_pct     = Pn('transport_pct');
  $transport_per_box = Pn('transport_per_box');
  $transport_total   = Pn('transport_total');
  $notes             = trim((string)P('notes',''));

  if ($tile_id > 0 && $boxes_in >= 0 && $damage_boxes >= 0) {
    $stmt = $pdo->prepare("
      INSERT INTO inventory_items
        (tile_id, recvd_dt, boxes_in, damage_boxes,
         per_box_value, per_sqft_value, transport_pct, transport_per_box, transport_total, notes)
      VALUES (?,?,?,?,?,?,?,?,?,?)
    ");
    $stmt->execute([
      $tile_id, $recvd_dt, $boxes_in, $damage_boxes,
      $per_box_value, $per_sqft_value, $transport_pct, $transport_per_box, $transport_total, $notes
    ]);
  }

  safe_redirect('inventory.php');
  exit;
}

/* ============================
   FETCH data for rendering
   ============================ */
$tiles = $pdo->query("
  SELECT t.id, t.name, ts.label AS size_label, ts.sqft_per_box AS spb
  FROM tiles t
  JOIN tile_sizes ts ON ts.id = t.size_id
  ORDER BY t.id DESC
")->fetchAll(PDO::FETCH_ASSOC);

/* availability per tile (boxes) = receipts - sold + returns */
$avail = []; $spb = [];
foreach ($tiles as $t){ $avail[(int)$t['id']] = 0.0; $spb[(int)$t['id']] = (float)$t['spb']; }

$st = $pdo->query("SELECT tile_id, COALESCE(SUM(boxes_in - damage_boxes),0) AS good FROM inventory_items GROUP BY tile_id");
foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r){ $avail[(int)$r['tile_id']] += (float)$r['good']; }

$st = $pdo->query("SELECT tile_id, COALESCE(SUM(boxes_decimal),0) AS sold FROM invoice_items GROUP BY tile_id");
foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r){ $avail[(int)$r['tile_id']] -= (float)$r['sold']; }

if (table_exists($pdo, 'invoice_return_items')) {
  $st = $pdo->query("SELECT tile_id, COALESCE(SUM(boxes_decimal),0) AS ret FROM invoice_return_items GROUP BY tile_id");
  foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r){ $avail[(int)$r['tile_id']] += (float)$r['ret']; }
}

/* --- “Recently Added” window (portable WHERE) --- */
$since = (new DateTime('today'))->modify("-{$daysBack} days")->format('Y-m-d');

$params = [':since' => $since];
$recentSql = "
  SELECT ii.id, ii.recvd_dt, ii.boxes_in, ii.damage_boxes, ii.per_box_value, ii.per_sqft_value,
         ii.transport_pct, ii.transport_per_box, ii.transport_total, ii.notes,
         t.name AS tile_name, ts.label AS size_label, ts.sqft_per_box AS spb
  FROM inventory_items ii
  JOIN tiles t       ON t.id = ii.tile_id
  JOIN tile_sizes ts ON ts.id = t.size_id
  WHERE ii.recvd_dt >= :since
  ORDER BY ii.id DESC 
";
$st = $pdo->prepare($recentSql);
$st->execute($params);
$recentRows = $st->fetchAll(PDO::FETCH_ASSOC);

/* ============================
   RENDER
   ============================ */
$page_title = "Inventory";
require_once __DIR__ . '/../includes/header.php';
?>
<div class="card p-3 mb-3">
  <div class="d-flex flex-wrap align-items-center justify-content-between gap-2">
    <h5 class="mb-0">Add Stock (Tiles)</h5>

    <!-- Switcher: Today / 2d / 5d / 7d -->
    <div class="btn-group btn-group-sm" role="group" aria-label="recent filters">
      <?php
        $q = $_GET; unset($q['recent']);
        $base = 'inventory.php' . (empty($q) ? '' : ('?'.http_build_query($q)));
        $active = $mode;

        $mkHref = function(string $val) use ($base) {
          $sep = (strpos($base, '?') !== false) ? '&' : '?';
          return $base . $sep . 'recent=' . $val;
        };
        $btn = function(string $label, string $val) use ($active, $mkHref) {
          $cls = ($val === $active) ? 'btn-primary' : 'btn-outline-primary';
          echo '<a class="btn '.$cls.'" href="'.h($mkHref($val)).'">'.h($label).'</a>';
        };
        $btn('Recently Added (Today)', 'today');
        $btn('2 days', '2d');
        $btn('5 days', '5d');
        $btn('7 days', '7d');
      ?>
    </div>
  </div>

  <form method="post" class="row g-2 mt-2">
    <div class="col-md-3">
      <label class="form-label">Tile</label>
      <select class="form-select" name="tile_id" required>
        <?php foreach($tiles as $t): $tid=(int)$t['id']; ?>
          <option value="<?= $tid ?>">
            <?= h($t['name']) ?> (<?= h($t['size_label']) ?>) — Avl: <?= n2($avail[$tid] ?? 0) ?> box
          </option>
        <?php endforeach; ?>
      </select>
    </div>
 
    <div class="col-md-2">
      <label class="form-label">Boxes In</label>
      <input type="number" step="0.01" name="boxes_in" class="form-control" required>
    </div>
    <div class="col-md-2">
      <label class="form-label">Damage Boxes</label>
      <input type="number" step="0.01" name="damage_boxes" class="form-control" value="0">
    </div>
    <div class="col-md-3">
      <label class="form-label">Per Box Value (₹)</label>
      <input type="number" step="0.01" name="per_box_value" class="form-control" value="0">
    </div>
  <!--  <div class="col-md-3">
      <label class="form-label">Per Sqft Value (₹)</label>
      <input type="number" step="0.01" name="per_sqft_value" class="form-control" value="0">
    </div> -->
    <div class="col-md-2">
      <label class="form-label">Trans %</label>
      <input type="number" step="0.01" name="transport_pct" class="form-control" value="0">
    </div>
   <!-- <div class="col-md-2">
      <label class="form-label">Trans / Box (₹)</label>
      <input type="number" step="0.01" name="transport_per_box" class="form-control" value="0">
    </div> -->
    <div class="col-md-2">
      <label class="form-label">Trans Total (₹)</label>
      <input type="number" step="0.01" name="transport_total" class="form-control" value="0">
    </div>
	 <div class="col-md-2">
      <label class="form-label">Date</label>
      <input type="date" name="recvd_dt" class="form-control" value="<?= h(date('Y-m-d')) ?>">
    </div>
    <div class="col-md-12">
      <label class="form-label">Notes</label>
      <input name="notes" class="form-control" placeholder="Optional notes…">
    </div>
    <div class="col-md-12">
      <button class="btn btn-success" name="add_stock" value="1">Add Stock</button>
    </div>
  </form>
</div>

<?php if (!empty($recentRows)): ?>
<div class="card p-3">
  <div class="d-flex justify-content-between align-items-center mb-2">
    <h5 class="mb-0">
      Recently Added —
      <?php
        echo ($mode==='today') ? 'Today'
             : (($mode==='2d') ? 'Last 2 days'
             : (($mode==='5d') ? 'Last 5 days' : 'Last 7 days'));
      ?>
    </h5>
  </div>

  <div class="table-responsive">
    <table class="table table-striped align-middle">
      <thead>
        <tr>
          <th>#</th>
          <th>Date</th>
          <th>Tile</th>
          <th>Size</th>
          <th class="text-end">Boxes</th>
          <th class="text-end">Damage</th>
          <th class="text-end">Per Box</th>
          <th class="text-end">Per Sqft</th>
          <th class="text-end">Trans%</th>
          <th class="text-end">T/Box</th>
          <th class="text-end">T Total</th>
        </tr>
      </thead>
      <tbody>
        <?php
          $i=1;
          $sumBoxes = 0.0; $sumDamage = 0.0; $sumTTotal = 0.0;
          foreach($recentRows as $r):
            $spb   = (float)($r['spb'] ?? 0);
            $pb    = (float)($r['per_box_value'] ?? 0);
            $ps    = (float)($r['per_sqft_value'] ?? 0);
            $pct   = (float)($r['transport_pct'] ?? 0);
            $tpb   = (float)($r['transport_per_box'] ?? 0);
            $ttot  = (float)($r['transport_total'] ?? 0);
            $boxes = (float)($r['boxes_in'] ?? 0);
            $dam   = (float)($r['damage_boxes'] ?? 0);
            $netB  = max(0.0, $boxes - $dam);

            $base  = $pb > 0 ? $pb : ($ps > 0 ? $ps * max($spb, 0) : 0);
            $fromPct   = $base * ($pct / 100.0);
            $fromTotal = ($ttot > 0 && $netB > 0) ? ($ttot / $netB) : 0.0;

            $t_per_box = $fromPct + $tpb + $fromTotal;
            $t_total   = $t_per_box + $pb;
			$t_total = $boxes * $t_total;

            $sumBoxes  += $boxes;
            $sumDamage += $dam;
            $sumTTotal += $t_total;
        ?>
          <tr>
            <td><?= $i++ ?></td>
            <td><?= h($r['recvd_dt']) ?></td>
            <td><?= h($r['tile_name']) ?></td>
            <td><?= h($r['size_label']) ?></td>
            <td class="text-end"><?= n2($boxes) ?></td>
            <td class="text-end"><?= n2($dam) ?></td>
            <td class="text-end"><?= n2($pb) ?></td>
            <td class="text-end"><?= n2($ps) ?></td>
            <td class="text-end"><?= n2($pct) ?></td>
            <td class="text-end"><?= n2($t_per_box) ?></td>
            <td class="text-end"><?= n2($t_total) ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
      <tfoot>
        <tr>
          <th colspan="4" class="text-end">Totals</th>
          <th class="text-end"><?= n2($sumBoxes) ?></th>
          <th class="text-end text-danger"><?= n2($sumDamage) ?></th>
          <th colspan="4"></th>
          <th class="text-end fw-bold"><?= n2($sumTTotal) ?></th>
        </tr>
      </tfoot>
    </table>
  </div>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
