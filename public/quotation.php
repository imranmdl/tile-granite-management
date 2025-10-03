<?php
// public/quotation.php — FIXED: POST-first (no header warnings) + safe_redirect
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';
require_login();

$pdo = Database::pdo();
require_once __DIR__ . '/_calc_tile_cost.php';

/* ---------- helpers ---------- */
function P($k,$d=null){ return $_POST[$k]??$d; }
function Pn($k){ $v=P($k,0); return is_numeric($v)?(float)$v:0.0; }
function Pid($k){ $v=P($k,0); return is_numeric($v)?(int)$v:0; }

$id = (int)($_GET['id'] ?? 0);

/* ===========================================================
   HANDLE ALL POST ACTIONS BEFORE ANY OUTPUT
   =========================================================== */

/* Create quotation header */
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['create_quote'])) {
  $no   = 'Q' . date('ymdHis');
  $dt   = (string)P('quote_dt', date('Y-m-d'));
  $name = trim((string)P('customer_name',''));
  $phone= trim((string)P('phone',''));
  $notes= trim((string)P('notes',''));

  $stmt = $pdo->prepare("INSERT INTO quotations(quote_no,quote_dt,customer_name,phone,notes) VALUES(?,?,?,?,?)");
  $stmt->execute([$no,$dt,$name,$phone,$notes]);

  $newId = (int)$pdo->lastInsertId();
  safe_redirect('quotation.php?id='.$newId);
}

/* Add tile row */
if ($id>0 && $_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['add_tile_row'])) {
  $tile_id       = Pid('tile_id');
  $purpose       = trim((string)P('purpose',''));
  $len           = Pn('length_ft');
  $wid           = Pn('width_ft');
  $adj           = Pn('extra_sqft');
  $spb           = (float)$pdo->query("SELECT ts.sqft_per_box FROM tiles t JOIN tile_sizes ts ON ts.id=t.size_id WHERE t.id=".$tile_id)->fetchColumn();
  $sqft          = max(0.0, $len*$wid + $adj);
  $boxes         = ($spb>0)? ($sqft/$spb) : 0.0;
  $rate_per_box  = Pn('rate_per_box');
  $rate_per_sqft = ($spb>0)? ($rate_per_box/$spb) : 0.0;
  $line_total    = $rate_per_box * $boxes;

  $stmt = $pdo->prepare("INSERT INTO quotation_items(quotation_id,purpose,tile_id,length_ft,width_ft,extra_sqft,total_sqft,rate_per_sqft,rate_per_box,boxes_decimal,line_total) VALUES(?,?,?,?,?,?,?,?,?,?,?)");
  $stmt->execute([$id,$purpose,$tile_id,$len,$wid,$adj,$sqft,$rate_per_sqft,$rate_per_box,$boxes,$line_total]);

  safe_redirect('quotation.php?id='.$id);
}

/* Add misc row */
if ($id>0 && $_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['add_misc_row'])) {
  $misc_item_id = Pid('misc_item_id');
  $purpose      = trim((string)P('purpose',''));
  $qty          = Pn('qty_units');
  $rate         = Pn('rate_per_unit');
  $line_total   = $qty * $rate;

  $stmt = $pdo->prepare("INSERT INTO quotation_misc_items(quotation_id,purpose,misc_item_id,qty_units,rate_per_unit,line_total) VALUES(?,?,?,?,?,?)");
  $stmt->execute([$id,$purpose,$misc_item_id,$qty,$rate,$line_total]);

  safe_redirect('quotation.php?id='.$id);
}

/* Row-wise edit/save/delete: tiles */
if ($id>0 && $_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['save_tile'])) {
  $rid          = Pid('row_id');
  $purpose      = trim((string)P('purpose',''));
  $len          = Pn('length_ft');
  $wid          = Pn('width_ft');
  $adj          = Pn('extra_sqft');
  $rate_per_box = Pn('rate_per_box');

  $row = $pdo->query("
    SELECT qi.id, qi.tile_id, ts.sqft_per_box spb
    FROM quotation_items qi
    JOIN tiles t ON t.id=qi.tile_id
    JOIN tile_sizes ts ON ts.id=t.size_id
    WHERE qi.id=".$rid
  )->fetch(PDO::FETCH_ASSOC);

  if ($row) {
    $spb           = (float)$row['spb'];
    $sqft          = max(0.0, $len*$wid + $adj);
    $boxes         = ($spb>0)? ($sqft/$spb) : 0.0;
    $rate_per_sqft = ($spb>0)? ($rate_per_box/$spb) : 0.0;
    $line_total    = $rate_per_box * $boxes;

    $st = $pdo->prepare("UPDATE quotation_items SET purpose=?,length_ft=?,width_ft=?,extra_sqft=?,total_sqft=?,rate_per_sqft=?,rate_per_box=?,boxes_decimal=?,line_total=? WHERE id=?");
    $st->execute([$purpose,$len,$wid,$adj,$sqft,$rate_per_sqft,$rate_per_box,$boxes,$line_total,$rid]);
  }
  safe_redirect('quotation.php?id='.$id);
}

if ($id>0 && $_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['del_tile'])) {
  $rid = Pid('row_id');
  $pdo->prepare("DELETE FROM quotation_items WHERE id=?")->execute([$rid]);
  safe_redirect('quotation.php?id='.$id);
}

/* Row-wise edit/save/delete: misc items */
if ($id>0 && $_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['save_misc'])) {
  $rid        = Pid('row_id');
  $purpose    = trim((string)P('purpose',''));
  $qty        = Pn('qty_units');
  $rate       = Pn('rate_per_unit');
  $line_total = $qty * $rate;

  $pdo->prepare("UPDATE quotation_misc_items SET purpose=?, qty_units=?, rate_per_unit=?, line_total=? WHERE id=?")
      ->execute([$purpose,$qty,$rate,$line_total,$rid]);

  safe_redirect('quotation.php?id='.$id);
}

if ($id>0 && $_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['del_misc'])) {
  $rid = Pid('row_id');
  $pdo->prepare("DELETE FROM quotation_misc_items WHERE id=?")->execute([$rid]);
  safe_redirect('quotation.php?id='.$id);
}

/* Update totals (discount + GST) */
if ($id>0 && $_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['update_totals'])) {
  $disc_type = (string)P('discount_type','AMOUNT');
  $disc_val  = Pn('discount_value');
  $gst_mode  = (string)P('gst_mode','EXCLUDE');
  $gst_pct   = Pn('gst_percent');

  $sub = (float)$pdo->query("SELECT COALESCE(SUM(line_total),0) FROM quotation_items WHERE quotation_id=".$id)->fetchColumn()
       + (float)$pdo->query("SELECT COALESCE(SUM(line_total),0) FROM quotation_misc_items WHERE quotation_id=".$id)->fetchColumn();

  $disc    = ($disc_type==='PERCENT') ? ($sub*$disc_val/100.0) : $disc_val;
  $base    = max(0.0, $sub - $disc);
  $gst_amt = ($gst_mode==='EXCLUDE') ? ($base*$gst_pct/100.0) : 0.0;
  $total   = ($gst_mode==='EXCLUDE') ? ($base + $gst_amt) : $base;

  $pdo->prepare("UPDATE quotations SET discount_type=?, discount_value=?, subtotal=?, total=?, gst_mode=?, gst_percent=?, gst_amount=? WHERE id=?")
      ->execute([$disc_type,$disc_val,$sub,$total,$gst_mode,$gst_pct,$gst_amt,$id]);

  safe_redirect('quotation.php?id='.$id);
}

/* ============================
   FETCH DATA FOR RENDERING
   ============================ */
$hdr = null;
if ($id>0) {
  $st = $pdo->prepare("SELECT * FROM quotations WHERE id=?");
  $st->execute([$id]);
  $hdr = $st->fetch(PDO::FETCH_ASSOC);
}

$tiles = $pdo->query("
  SELECT t.id,t.name, ts.label size_label
  FROM tiles t
  JOIN tile_sizes ts ON ts.id=t.size_id
  ORDER BY t.name
")->fetchAll(PDO::FETCH_ASSOC);

$misc_items = $pdo->query("SELECT * FROM misc_items ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);

/* ============================
   RENDER (safe to output now)
   ============================ */
$page_title="Quotations (Row-wise Edit)";
require_once __DIR__ . '/../includes/header.php';
?>
<div class="card p-3 mb-3">
  <h5>New Quotation</h5>
  <form method="post" class="row g-2">
    <div class="col-md-2"><label class="form-label">Date</label><input class="form-control" type="date" name="quote_dt" value="<?= h(date('Y-m-d')) ?>"></div>
    <div class="col-md-3"><label class="form-label">Customer</label><input class="form-control" name="customer_name"></div>
    <div class="col-md-2"><label class="form-label">Phone</label><input class="form-control" name="phone"></div>
    <div class="col-md-5"><label class="form-label">Notes</label><input class="form-control" name="notes"></div>
    <div class="col-md-12"><button class="btn btn-primary" name="create_quote" value="1">Create</button></div>
  </form>
</div>

<?php if ($hdr): ?>
<div class="card p-3 mb-3"><div class="row g-2">
  <div class="col-md-3"><strong>No:</strong> <?= h($hdr['quote_no']) ?></div>
  <div class="col-md-3"><strong>Date:</strong> <?= h($hdr['quote_dt']) ?></div>
  <div class="col-md-6"><strong>Customer:</strong> <?= h($hdr['customer_name']) ?></div>
</div></div>

<div class="card p-3 mb-3">
  <h5>Add Tile Row</h5>
  <form method="post" class="row g-2">
    <div class="col-md-3">
      <label class="form-label">Tile</label>
      <select class="form-select" name="tile_id">
        <?php foreach ($tiles as $t): ?>
          <option value="<?= (int)$t['id'] ?>"><?= h($t['name']) ?> (<?= h($t['size_label']) ?>)</option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-md-2"><label class="form-label">Length (ft)</label><input class="form-control" type="number" step="0.01" name="length_ft"></div>
    <div class="col-md-2"><label class="form-label">Width (ft)</label><input class="form-control" type="number" step="0.01" name="width_ft"></div>
    <div class="col-md-2"><label class="form-label">Adjust Sqft</label><input class="form-control" type="number" step="0.01" name="extra_sqft" value="0"></div>
    <div class="col-md-2"><label class="form-label">Rate / Box (₹)</label><input class="form-control" type="number" step="0.01" name="rate_per_box"></div>
    <div class="col-md-12"><label class="form-label">Purpose</label><input class="form-control" name="purpose" placeholder="e.g. Hall 20x10"></div>
    <div class="col-md-12"><button class="btn btn-success" name="add_tile_row" value="1">Add Tile</button></div>
  </form>
</div>

<div class="card p-3 mb-3">
  <h5>Add Other Item</h5>
  <form method="post" class="row g-2">
    <div class="col-md-4"><label class="form-label">Item</label>
      <select class="form-select" name="misc_item_id">
        <?php foreach ($misc_items as $m): ?>
          <option value="<?= (int)$m['id'] ?>"><?= h($m['name']) ?> (<?= h($m['unit_label']) ?>)</option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-md-2"><label class="form-label">Qty</label><input class="form-control" type="number" step="0.01" name="qty_units"></div>
    <div class="col-md-2"><label class="form-label">Rate / Unit (₹)</label><input class="form-control" type="number" step="0.01" name="rate_per_unit"></div>
    <div class="col-md-4"><label class="form-label">Purpose</label><input class="form-control" name="purpose"></div>
    <div class="col-md-12"><button class="btn btn-success" name="add_misc_row" value="1">Add Item</button></div>
  </form>
</div>

<?php
$tile_rows = $pdo->query("
  SELECT qi.*, t.name tile_name, ts.sqft_per_box spb, ts.label size_label
  FROM quotation_items qi
  JOIN tiles t ON t.id=qi.tile_id
  JOIN tile_sizes ts ON ts.id=t.size_id
  WHERE quotation_id=".$id."
  ORDER BY qi.id
")->fetchAll(PDO::FETCH_ASSOC);

$misc_rows = $pdo->query("
  SELECT qmi.*, mi.name item_name, mi.unit_label
  FROM quotation_misc_items qmi
  JOIN misc_items mi ON mi.id=qmi.misc_item_id
  WHERE quotation_id=".$id."
  ORDER BY qmi.id
")->fetchAll(PDO::FETCH_ASSOC);
?>
<div class="card p-3 mb-3">
  <h5>Items (Editable)</h5>
  <div class="table-responsive"><table class="table table-striped align-middle">
    <thead><tr>
      <th>#</th><th>Purpose</th><th>Tile</th><th>Size</th><th>L(ft)</th><th>W(ft)</th><th>Adjust Sqft</th><th><strong>Total Sqft</strong></th><th>Boxes(dec)</th><th>Rate/Box</th><th>Line Total</th><th>Avail</th><th>Save</th><th>Del</th>
    </tr></thead>
    <tbody>
      <?php $i=1; $sub=0.0; foreach ($tile_rows as $r):
        list($avail,$cpb,$spb) = tile_availability_and_cost($pdo, (int)$r['tile_id']);
        $need = (float)$r['boxes_decimal'];
        $ok   = $avail >= $need;
        $sub += (float)$r['line_total'];
        $total_sqft = (float)$r['total_sqft'];
      ?>
      <tr>
        <form method="post">
          <td><?= $i++ ?></td>
          <td><input class="form-control form-control-sm" name="purpose" value="<?= h($r['purpose']) ?>"></td>
          <td><?= h($r['tile_name']) ?></td>
          <td><?= h($r['size_label']) ?></td>
          <td style="max-width:90px"><input class="form-control form-control-sm" type="number" step="0.01" name="length_ft" value="<?= n2($r['length_ft']) ?>"></td>
          <td style="max-width:90px"><input class="form-control form-control-sm" type="number" step="0.01" name="width_ft" value="<?= n2($r['width_ft']) ?>"></td>
          <td style="max-width:110px"><input class="form-control form-control-sm" type="number" step="0.01" name="extra_sqft" value="<?= n2($r['extra_sqft']) ?>"></td>
          <td><strong><?= n2($total_sqft) ?></strong></td>
          <td><?= n3($r['boxes_decimal']) ?></td>
          <td style="max-width:120px"><input class="form-control form-control-sm" type="number" step="0.01" name="rate_per_box" value="<?= n2($r['rate_per_box']) ?>"></td>
          <td>₹ <?= n2($r['line_total']) ?></td>
          <td class="<?= $ok?'text-success':'text-danger' ?>"><?= $ok?'OK':'Need '.n3(max(0,$need-$avail)).' box' ?></td>
          <td>
            <input type="hidden" name="row_id" value="<?= (int)$r['id'] ?>">
            <button class="btn btn-sm btn-primary" name="save_tile" value="1">Save</button>
          </td>
          <td><button class="btn btn-sm btn-outline-danger" name="del_tile" value="1" onclick="return confirm('Delete row?')">X</button></td>
        </form>
      </tr>
      <?php endforeach; ?>
      <?php foreach ($misc_rows as $r): $sub += (float)$r['line_total']; ?>
      <tr>
        <form method="post">
          <td><?= $i++ ?></td>
          <td><input class="form-control form-control-sm" name="purpose" value="<?= h($r['purpose']) ?>"></td>
          <td><?= h($r['item_name']) ?> (<?= h($r['unit_label']) ?>)</td>
          <td>—</td><td>—</td><td>—</td><td>—</td><td>—</td>
          <td style="max-width:100px"><input class="form-control form-control-sm" type="number" step="0.01" name="qty_units" value="<?= n3($r['qty_units']) ?>"></td>
          <td style="max-width:120px"><input class="form-control form-control-sm" type="number" step="0.01" name="rate_per_unit" value="<?= n2($r['rate_per_unit']) ?>"></td>
          <td>₹ <?= n2($r['line_total']) ?></td>
          <td>—</td>
          <td>
            <input type="hidden" name="row_id" value="<?= (int)$r['id'] ?>">
            <button class="btn btn-sm btn-primary" name="save_misc" value="1">Save</button>
          </td>
          <td><button class="btn btn-sm btn-outline-danger" name="del_misc" value="1" onclick="return confirm('Delete row?')">X</button></td>
        </form>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table></div>
</div>

<?php
$disc_type = $hdr['discount_type'] ?? 'AMOUNT';
$disc_val  = (float)($hdr['discount_value'] ?? 0);
$gst_mode  = $hdr['gst_mode'] ?? 'EXCLUDE';
$gst_pct   = (float)($hdr['gst_percent'] ?? 0);
$disc      = ($disc_type==='PERCENT')? ($sub*$disc_val/100.0) : $disc_val;
$base      = max(0.0, $sub - $disc);
$gst_amt   = ($gst_mode==='EXCLUDE')? ($base*$gst_pct/100.0) : 0.0;
$total     = ($gst_mode==='EXCLUDE')? ($base+$gst_amt) : $base;
?>
<div class="card p-3">
  <h5>Totals</h5>
  <form method="post" class="row g-2">
    <div class="col-md-3">
      <label class="form-label">Discount Type</label>
      <select name="discount_type" class="form-select">
        <option <?= $disc_type==='AMOUNT'?'selected':'' ?>>AMOUNT</option>
        <option <?= $disc_type==='PERCENT'?'selected':'' ?>>PERCENT</option>
      </select>
    </div>
    <div class="col-md-2">
      <label class="form-label">Discount Value</label>
      <input class="form-control" type="number" step="0.01" name="discount_value" value="<?= n2($disc_val) ?>">
    </div>
    <div class="col-md-2">
      <label class="form-label">GST Mode</label>
      <select name="gst_mode" class="form-select">
        <option <?= $gst_mode==='EXCLUDE'?'selected':'' ?>>EXCLUDE</option>
        <option <?= $gst_mode==='INCLUDE'?'selected':'' ?>>INCLUDE</option>
      </select>
    </div>
    <div class="col-md-2">
      <label class="form-label">GST %</label>
      <input class="form-control" type="number" step="0.01" name="gst_percent" value="<?= n2($gst_pct) ?>">
    </div>
    <div class="col-md-3 d-flex align-items-end">
      <button class="btn btn-primary" name="update_totals" value="1">Update Totals</button>
    </div>
  </form>
  <div class="mt-2">
    <strong>Subtotal:</strong> ₹ <?= n2($sub ?? 0) ?> &nbsp;
    <strong>Total:</strong> ₹ <?= n2($total ?? 0) ?>
  </div>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
