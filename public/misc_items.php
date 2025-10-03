<?php
// public/misc_items.php — process-first; duplicate-safe for items; safe redirects
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';
require_login();

$pdo = Database::pdo();

// ---------- helpers ----------
function P($k, $d=null){ return $_POST[$k] ?? $d; }
function Pn($k){ $v = P($k, 0); return is_numeric($v) ? (float)$v : 0.0; }
function Pid($k){ $v = P($k, 0); return is_numeric($v) ? (int)$v : 0; }

// ---------- HANDLE POST (before any HTML) ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

  // Add item (duplicate-safe by name)
  if (isset($_POST['add_item'])) {
    $name = trim((string)P('name',''));
    $unit = trim((string)P('unit_label','unit'));
    if ($name === '') {
      safe_redirect('misc_items.php?err='.urlencode('Item name is required'));
    }

    $st = $pdo->prepare("SELECT id, unit_label FROM misc_items WHERE name=?");
    $st->execute([$name]);
    $row = $st->fetch(PDO::FETCH_ASSOC);

    if ($row) {
      if ($unit !== '' && strcasecmp((string)$row['unit_label'], $unit) !== 0) {
        $pdo->prepare("UPDATE misc_items SET unit_label=? WHERE id=?")->execute([$unit, (int)$row['id']]);
        safe_redirect('misc_items.php?msg='.urlencode('Existing item updated').'#item'.$row['id']);
      }
      safe_redirect('misc_items.php?msg='.urlencode('Item already exists').'#item'.$row['id']);
    } else {
      $stmt = $pdo->prepare("INSERT INTO misc_items(name,unit_label) VALUES(?,?)");
      $stmt->execute([$name, $unit ?: 'unit']);
      $newId = (int)$pdo->lastInsertId();
      safe_redirect('misc_items.php?msg='.urlencode('Item added').'#item'.$newId);
    }
  }

  // Add stock (receipts can be multiple per day)
  if (isset($_POST['add_stock'])) {
    $misc_item_id  = Pid('misc_item_id');
    $recvd_dt      = (string)P('recvd_dt', date('Y-m-d'));
    $qty_in        = Pn('qty_in');
    $damage_units  = Pn('damage_units');
    $cost_per_unit = Pn('cost_per_unit');
    $tpct          = Pn('transport_pct');
    $tpu           = Pn('transport_per_unit');
    $ttot          = Pn('transport_total');
    $notes         = trim((string)P('notes',''));

    if ($misc_item_id <= 0) {
      safe_redirect('misc_items.php?err='.urlencode('Choose an item'));
    }

    $stmt = $pdo->prepare("
      INSERT INTO misc_inventory_items
        (misc_item_id, recvd_dt, qty_in, damage_units, cost_per_unit,
         transport_pct, transport_per_unit, transport_total, notes)
      VALUES(?,?,?,?,?,?,?,?,?)
    ");
    $stmt->execute([$misc_item_id, $recvd_dt, $qty_in, $damage_units, $cost_per_unit,
                    $tpct, $tpu, $ttot, $notes]);

    safe_redirect('misc_items.php?msg='.urlencode('Stock added'));
  }
}

// ---------- FETCH for rendering ----------
$items = $pdo->query("SELECT * FROM misc_items ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);

// Receipts: normalize tiles + misc into one list (SQLite-safe)
$receipts = $pdo->query("
  SELECT
    ii.id                                      AS rid,
    COALESCE(NULLIF(ii.purchase_dt,''), DATE('now')) AS recvd_dt,
    t.name || ' (' || ts.label || ')'          AS item_name,
    'boxes'                                    AS unit_label,
    ii.boxes_in                                AS qty_in,
    ii.damage_boxes                            AS damage_units,
    COALESCE(ii.per_box_value, 0)              AS cost_per_unit,
    COALESCE(ii.transport_pct,0)               AS transport_pct,
    COALESCE(ii.transport_per_box,0)           AS transport_per_unit,
    COALESCE(ii.transport_total,0)             AS transport_total
  FROM inventory_items ii
  JOIN tiles t       ON t.id  = ii.tile_id
  JOIN tile_sizes ts ON ts.id = t.size_id

  UNION ALL

  SELECT
    r.id                    AS rid,
    r.recvd_dt              AS recvd_dt,
    i.name                  AS item_name,
    i.unit_label            AS unit_label,
    r.qty_in                AS qty_in,
    r.damage_units          AS damage_units,
    r.cost_per_unit         AS cost_per_unit,
    COALESCE(r.transport_pct,0)        AS transport_pct,
    COALESCE(r.transport_per_unit,0)   AS transport_per_unit,
    COALESCE(r.transport_total,0)      AS transport_total
  FROM misc_inventory_items r
  JOIN misc_items i ON i.id = r.misc_item_id

  ORDER BY recvd_dt DESC, rid DESC
")->fetchAll(PDO::FETCH_ASSOC);

// ---------- RENDER ----------
$page_title = "Other Items";
require_once __DIR__ . '/../includes/header.php';
?>

<style>
  .table-xl th, .table-xl td { padding: .9rem 1rem; font-size: 1rem; }
  .table-sticky thead th { position: sticky; top: 0; background: #fff; z-index: 2; }
  .add-stock .form-control { min-width: 110px; }
</style>

<?php if (!empty($_GET['msg'])): ?>
  <div class="alert alert-success py-2"><?= h($_GET['msg']) ?></div>
<?php endif; ?>
<?php if (!empty($_GET['err'])): ?>
  <div class="alert alert-danger  py-2"><?= h($_GET['err']) ?></div>
<?php endif; ?>

<div class="row g-3">
  <div class="col-lg-3 col-md-4">
    <div class="card p-3">
      <h5>Add Item</h5>
      <form method="post">
        <input class="form-control mb-2" name="name" placeholder="e.g. Cement" required>
        <input class="form-control mb-2" name="unit_label" placeholder="bag / piece / unit" value="unit">
        <button class="btn btn-primary" name="add_item" value="1">Save Item</button>
      </form>

      <hr>
      <?php if ($items): ?>
      <div class="table-responsive">
        <table class="table table-sm table-striped align-middle">
          <thead><tr><th>#</th><th>Name</th><th>Unit</th></tr></thead>
          <tbody>
            <?php foreach($items as $i): ?>
              <tr id="item<?= (int)$i['id'] ?>">
                <td>#<?= (int)$i['id'] ?></td>
                <td><?= h($i['name']) ?></td>
                <td><?= h($i['unit_label']) ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php endif; ?>
    </div>
  </div>

  <div class="col-lg-9 col-md-8">
    <div class="card p-3 add-stock">
      <h5>Add Stock</h5>
      <form method="post" class="row g-2">
        <div class="col-md-4">
          <select class="form-select" name="misc_item_id" required>
            <option value="">Choose item…</option>
            <?php foreach($items as $i): ?>
              <option value="<?= (int)$i['id'] ?>">
                <?= h($i['name']) ?> (<?= h($i['unit_label']) ?>)
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-3">
          <input class="form-control" type="date" name="recvd_dt" value="<?= h(date('Y-m-d')) ?>">
        </div>
        <div class="col-md-2">
          <input class="form-control" type="number" step="0.01" name="qty_in" placeholder="Qty" required>
        </div>
        <div class="col-md-3">
          <input class="form-control" type="number" step="0.01" name="damage_units" value="0" placeholder="Damage">
        </div>
        <div class="col-md-3">
          <input class="form-control" type="number" step="0.01" name="cost_per_unit" placeholder="Cost/Unit">
        </div>
        <div class="col-md-2">
          <input class="form-control" type="number" step="0.01" name="transport_pct" value="0" placeholder="Trans %">
        </div>
        <div class="col-md-2">
          <input class="form-control" type="number" step="0.01" name="transport_per_unit" value="0" placeholder="Trans/Unit">
        </div>
        <div class="col-md-2">
          <input class="form-control" type="number" step="0.01" name="transport_total" value="0" placeholder="Trans Total">
        </div>
        <div class="col-md-12">
          <input class="form-control" name="notes" placeholder="Notes">
        </div>
        <div class="col-md-12">
          <button class="btn btn-success" name="add_stock" value="1">Add Stock</button>
        </div>
      </form>
    </div>
  </div>

  <div class="col-12">
    <div class="card p-3 mt-3">
      <h5>Receipts</h5>
      <div class="table-responsive">
        <table class="table table-striped align-middle table-xl table-sticky">
          <thead>
            <tr>
              <th>#</th><th>Date</th><th>Item</th><th>Qty</th><th>Damage</th>
              <th>Cost/Unit</th><th>Trans%</th><th>Trans/Unit</th><th>Trans Total</th>
            </tr>
          </thead>
          <tbody>
            <?php $i=1; foreach($receipts as $r): ?>
              <tr>
                <td><?= $i++ ?></td>
                <td><?= h($r['recvd_dt']) ?></td>
                <td><?= h($r['item_name']) ?> (<?= h($r['unit_label']) ?>)</td>
                <td><?= n2($r['qty_in']) ?></td>
                <td><?= n2($r['damage_units']) ?></td>
                <td><?= n2($r['cost_per_unit']) ?></td>
                <td><?= n2($r['transport_pct']) ?></td>
                <td><?= n2($r['transport_per_unit']) ?></td>
                <td><?= n2($r['transport_total']) ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
