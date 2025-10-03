<?php
/* ============================================================
   Invoices (Row-wise Edit + Salesperson + Live Availability)
   Process-first (no output before redirects)
   ============================================================ */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';
require_login();

$pdo = Database::pdo();

/* ---------- tiny schema guards ---------- */
function _ensure_col(PDO $pdo, string $table, string $col, string $ddl){
  $has=false;
  foreach ($pdo->query("PRAGMA table_info($table)") as $r){
    if (strcasecmp($r['name'],$col)===0){ $has=true; break; }
  }
  if(!$has){ $pdo->exec("ALTER TABLE $table ADD COLUMN $col $ddl"); }
}
_ensure_col($pdo,'invoice_items','unit_cost','REAL DEFAULT 0');
_ensure_col($pdo,'invoice_items','line_cost','REAL DEFAULT 0');
_ensure_col($pdo,'invoice_misc_items','unit_cost','REAL DEFAULT 0');
_ensure_col($pdo,'invoice_misc_items','line_cost','REAL DEFAULT 0');
_ensure_col($pdo,'invoices','sales_user','TEXT');
_ensure_col($pdo,'invoices','commission_percent','REAL');
_ensure_col($pdo,'invoices','salesperson_user_id','INTEGER');

/* ---------- helpers ---------- */
function _num($row,$k,$def=0){ return isset($row[$k])?(float)$row[$k]:$def; }

/** live availability = receipts(good) - sales + returns */
function available_boxes(PDO $pdo, int $tile_id): float {
  $st = $pdo->prepare("SELECT COALESCE(SUM(boxes_in - damage_boxes),0) FROM inventory_items WHERE tile_id=?");
  $st->execute([$tile_id]);
  $good = (float)$st->fetchColumn();

  $st = $pdo->prepare("SELECT COALESCE(SUM(boxes_decimal),0) FROM invoice_items WHERE tile_id=?");
  $st->execute([$tile_id]);
  $sold = (float)$st->fetchColumn();

  $st = $pdo->prepare("SELECT COALESCE(SUM(boxes_decimal),0) FROM invoice_return_items WHERE tile_id=?");
  $st->execute([$tile_id]);
  $ret = (float)$st->fetchColumn();

  return max(0.0, $good - $sold + $ret);
}

/** return [cost_per_box, cost_per_sqft] for a TILE */
function tile_cost_now(PDO $pdo, int $tile_id): array {
  $spb = (float)$pdo->query("SELECT ts.sqft_per_box FROM tiles t JOIN tile_sizes ts ON ts.id=t.size_id WHERE t.id=".$tile_id)->fetchColumn();
  if ($spb<=0) $spb=1.0;

  $st = $pdo->prepare("SELECT * FROM inventory_items WHERE tile_id=? ORDER BY id DESC LIMIT 1");
  $st->execute([$tile_id]);
  $r = $st->fetch(PDO::FETCH_ASSOC) ?: [];

  $base = _num($r,'per_box_value',0);
  if ($base<=0) $base = _num($r,'per_sqft_value',0)*$spb;

  $transport  = 0.0;
  $transport += $base * (_num($r,'transport_pct',_num($r,'transport_percent',0))/100.0);
  $transport += _num($r,'transport_per_box',0);

  $net_boxes = max(0.0, _num($r,'boxes_in',_num($r,'number_of_boxes',0)) - _num($r,'damage_boxes',0));
  if ((_num($r,'transport_total',0)>0) && $net_boxes>0){
    $transport += (_num($r,'transport_total',0) / $net_boxes);
  }

  $cpb = $base + $transport;
  $cps = $cpb / $spb;
  return [$cpb,$cps];
}

/** return cost_per_unit for a MISC item */
function misc_cost_now(PDO $pdo, int $misc_item_id): float {
  $st = $pdo->prepare("SELECT * FROM misc_inventory_items WHERE misc_item_id=? ORDER BY id DESC LIMIT 1");
  $st->execute([$misc_item_id]);
  $r = $st->fetch(PDO::FETCH_ASSOC) ?: [];

  $base = _num($r,'cost_per_unit',_num($r,'per_unit_value',0));
  $transport  = 0.0;
  $transport += $base * (_num($r,'transport_pct',_num($r,'transport_percent',0))/100.0);
  $transport += _num($r,'transport_per_unit',0);

  $net_units = max(0.0, _num($r,'qty_in',_num($r,'quantity_in',_num($r,'units_in',0))) - _num($r,'damage_units',_num($r,'damage_qty',0)));
  if ((_num($r,'transport_total',0)>0) && $net_units>0){
    $transport += (_num($r,'transport_total',0) / $net_units);
  }
  return $base + $transport;
}

/* ============================================================
   POST handlers
   ============================================================ */

# create invoice header
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['create_invoice'])) {
  $no='INV'.date('ymdHis'); $dt=$_POST['invoice_dt']??date('Y-m-d');
  $name=trim($_POST['customer_name']??''); $phone=trim($_POST['phone']??''); $notes=trim($_POST['notes']??'');
  $sales_user = auth_username();
  
  // Get current user ID for salesperson assignment
  $current_user_st = $pdo->prepare("SELECT id FROM users WHERE username = ? AND active = 1");
  $current_user_st->execute([$sales_user]);
  $current_user_id = $current_user_st->fetchColumn();
  
  $stmt=$pdo->prepare("INSERT INTO invoices(invoice_no,invoice_dt,customer_name,phone,notes,sales_user, salesperson_user_id) VALUES(?,?,?,?,?,?, ?)");
  $stmt->execute([$no,$dt,$name,$phone,$notes,$sales_user, $current_user_id]);
  
  $new_invoice_id = $pdo->lastInsertId();
  
  // Initialize commission record (will be calculated when totals are set)
  require_once __DIR__ . '/../includes/commission.php';
  Commission::sync_for_invoice($pdo, $new_invoice_id);
  
  header('Location: invoice.php?id='.$new_invoice_id); exit;
}

$id=(int)($_GET['id']??0);

# Admin: update salesperson or commission override
if ($id>0 && auth_is_admin() && $_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['save_sales_meta'])){
  $sales_user = trim($_POST['sales_user'] ?? '');
  $commission_percent = ($_POST['commission_percent'] === '' ? null : (float)$_POST['commission_percent']);
  $salesperson_user_id = (int)($_POST['salesperson_user_id'] ?? 0);
  
  $pdo->prepare("UPDATE invoices SET sales_user=?, commission_percent=?, salesperson_user_id=? WHERE id=?")->execute([$sales_user, $commission_percent, $salesperson_user_id, $id]);
  
  // Auto-sync commission when admin updates commission settings
  require_once __DIR__ . '/../includes/commission.php';
  Commission::sync_for_invoice($pdo, $id);
  
  header('Location: invoice.php?id='.$id); exit;
}

# Add tile row  (store cost snapshot)
if ($id>0 && $_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['add_tile_row'])){
  $tile_id=(int)$_POST['tile_id']; $purpose=trim($_POST['purpose']??'');
  $len=(float)$_POST['length_ft']; $wid=(float)$_POST['width_ft']; $adj=(float)$_POST['extra_sqft'];
  $rate_per_box=(float)$_POST['rate_per_box'];

  $spb=(float)$pdo->query("SELECT ts.sqft_per_box FROM tiles t JOIN tile_sizes ts ON ts.id=t.size_id WHERE t.id=".$tile_id)->fetchColumn();
  $sqft=max(0.0,$len*$wid+$adj); $boxes=($spb>0)?($sqft/$spb):0.0; $rate_per_sqft=($spb>0)?($rate_per_box/$spb):0.0; $line_total=$rate_per_box*$boxes;

  list($cpb,$cps) = tile_cost_now($pdo,$tile_id);
  $unit_cost = $cpb;                  // billing by box
  $line_cost = $unit_cost * $boxes;

  $pdo->prepare("INSERT INTO invoice_items
      (invoice_id,purpose,tile_id,length_ft,width_ft,extra_sqft,total_sqft,rate_per_sqft,rate_per_box,boxes_decimal,line_total,unit_cost,line_cost)
      VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?)")
      ->execute([$id,$purpose,$tile_id,$len,$wid,$adj,$sqft,$rate_per_sqft,$rate_per_box,$boxes,$line_total,$unit_cost,$line_cost]);

  header('Location: invoice.php?id='.$id); exit;
}

# Add misc row  (store cost snapshot)
if ($id>0 && $_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['add_misc_row'])) {
  $misc_item_id=(int)$_POST['misc_item_id']; $purpose=trim($_POST['purpose']??'');
  $qty=(float)$_POST['qty_units']; $rate=(float)$_POST['rate_per_unit']; $line_total=$qty*$rate;

  $unit_cost = misc_cost_now($pdo,$misc_item_id);
  $line_cost = $unit_cost * $qty;

  $pdo->prepare("INSERT INTO invoice_misc_items
      (invoice_id,purpose,misc_item_id,qty_units,rate_per_unit,line_total,unit_cost,line_cost)
      VALUES(?,?,?,?,?,?,?,?)")
      ->execute([$id,$purpose,$misc_item_id,$qty,$rate,$line_total,$unit_cost,$line_cost]);

  header('Location: invoice.php?id='.$id); exit;
}

# Row-wise edit/save/delete: tiles  (keep stored unit_cost; backfill only if 0)
if ($id>0 && $_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['save_tile'])) {
  $rid=(int)$_POST['row_id'];
  $purpose=trim($_POST['purpose']??'');
  $len=(float)$_POST['length_ft']; $wid=(float)$_POST['width_ft']; $adj=(float)$_POST['extra_sqft'];
  $rate_per_box=(float)$_POST['rate_per_box'];

  $row=$pdo->query("SELECT ii.id, ii.tile_id, ii.unit_cost, ts.sqft_per_box spb
                    FROM invoice_items ii
                    JOIN tiles t ON t.id=ii.tile_id
                    JOIN tile_sizes ts ON ts.id=t.size_id
                    WHERE ii.id=".$rid)->fetch();
  if ($row) {
    $spb=(float)$row['spb'];
    $sqft=max(0.0,$len*$wid+$adj);
    $boxes=($spb>0)?($sqft/$spb):0.0;
    $rate_per_sqft=($spb>0)?($rate_per_box/$spb):0.0;
    $line_total=$rate_per_box*$boxes;

    $unit_cost = ($row['unit_cost'] ?? 0) > 0 ? (float)$row['unit_cost'] : (tile_cost_now($pdo,(int)$row['tile_id'])[0]);
    $line_cost = $unit_cost * $boxes;

    $st=$pdo->prepare("UPDATE invoice_items
        SET purpose=?,length_ft=?,width_ft=?,extra_sqft=?,total_sqft=?,rate_per_sqft=?,rate_per_box=?,boxes_decimal=?,line_total=?, unit_cost=?, line_cost=?
        WHERE id=?");
    $st->execute([$purpose,$len,$wid,$adj,$sqft,$rate_per_sqft,$rate_per_box,$boxes,$line_total,$unit_cost,$line_cost,$rid]);
  }
  header('Location: invoice.php?id='.$id); exit;
}
if ($id>0 && $_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['del_tile'])) {
  $rid=(int)$_POST['row_id']; $pdo->prepare("DELETE FROM invoice_items WHERE id=?")->execute([$rid]); header('Location: invoice.php?id='.$id); exit;
}

# Row-wise edit/save/delete: misc items  (stable unit_cost)
if ($id>0 && $_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['save_misc'])) {
  $rid=(int)$_POST['row_id']; $purpose=trim($_POST['purpose']??''); $qty=(float)$_POST['qty_units']; $rate=(float)$_POST['rate_per_unit']; $line_total=$qty*$rate;

  $row = $pdo->query("SELECT unit_cost, misc_item_id FROM invoice_misc_items WHERE id=".$rid)->fetch(PDO::FETCH_ASSOC);
  $unit_cost = ($row['unit_cost'] ?? 0) > 0 ? (float)$row['unit_cost'] : misc_cost_now($pdo,(int)$row['misc_item_id']);
  $line_cost = $unit_cost * $qty;

  $pdo->prepare("UPDATE invoice_misc_items
                    SET purpose=?, qty_units=?, rate_per_unit=?, line_total=?, unit_cost=?, line_cost=?
                  WHERE id=?")
      ->execute([$purpose,$qty,$rate,$line_total,$unit_cost,$line_cost,$rid]);

  header('Location: invoice.php?id='.$id); exit;
}
if ($id>0 && $_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['del_misc'])) {
  $rid=(int)$_POST['row_id']; $pdo->prepare("DELETE FROM invoice_misc_items WHERE id=?")->execute([$rid]); header('Location: invoice.php?id='.$id); exit;
}

# Update totals
if ($id>0 && $_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['update_totals'])){
  $disc_type=$_POST['discount_type']??'AMOUNT'; $disc_val=(float)$_POST['discount_value'];
  $gst_mode=$_POST['gst_mode']??'EXCLUDE'; $gst_pct=(float)$_POST['gst_percent'];
  $sub=(float)$pdo->query("SELECT COALESCE(SUM(line_total),0) FROM invoice_items WHERE invoice_id=".$id)->fetchColumn()
     +(float)$pdo->query("SELECT COALESCE(SUM(line_total),0) FROM invoice_misc_items WHERE invoice_id=".$id)->fetchColumn();
  $disc=($disc_type==='PERCENT')?($sub*$disc_val/100.0):$disc_val;
  $base=max(0.0,$sub-$disc);
  $gst_amt=($gst_mode==='EXCLUDE')?($base*$gst_pct/100.0):0.0;
  $total=($gst_mode==='EXCLUDE')?($base+$gst_amt):$base;
  $pdo->prepare("UPDATE invoices SET discount_type=?,discount_value=?,subtotal=?,total=?,gst_mode=?,gst_percent=?,gst_amount=? WHERE id=?")
      ->execute([$disc_type,$disc_val,$sub,$total,$gst_mode,$gst_pct,$gst_amt,$id]);
      
  // Auto-sync commission whenever totals are updated
  require_once __DIR__ . '/../includes/commission.php';
  Commission::sync_for_invoice($pdo, $id);
      
  header('Location: invoice.php?id='.$id); exit;
}

# Payments
if ($id>0 && $_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['add_payment'])){
  $pay_dt=$_POST['pay_dt']??date('Y-m-d'); $method=$_POST['method']??'CASH'; $amount=(float)$_POST['amount']; $ref=trim($_POST['reference']??''); $notes=trim($_POST['notes']??'');
  $pdo->prepare("INSERT INTO invoice_payments(invoice_id,pay_dt,method,amount,reference,notes) VALUES(?,?,?,?,?,?)")->execute([$id,$pay_dt,$method,$amount,$ref,$notes]);
  header('Location: invoice.php?id='.$id); exit;
}
if ($id>0 && $_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['del_payment'])){
  $pid=(int)$_POST['payment_id']; $pdo->prepare("DELETE FROM invoice_payments WHERE id=?")->execute([$pid]); header('Location: invoice.php?id='.$id); exit;
}

/* ============================================================
   RENDER
   ============================================================ */
$page_title="Invoices (Row-wise Edit + Salesperson)";
require_once __DIR__ . '/../includes/header.php';

/* header data AFTER posts */
$hdr=null; if($id>0){ $st=$pdo->prepare("SELECT * FROM invoices WHERE id=?"); $st->execute([$id]); $hdr=$st->fetch(); }

/* tiles + availability for selector */
$tiles = $pdo->query("SELECT t.id,t.name, ts.label size_label FROM tiles t JOIN tile_sizes ts ON ts.id=t.size_id ORDER BY t.name")->fetchAll();
$avail_map = [];
foreach ($tiles as $t) {
  $avail_map[(int)$t['id']] = available_boxes($pdo, (int)$t['id']);
}
$misc_items = $pdo->query("SELECT * FROM misc_items ORDER BY name")->fetchAll();
?>

<!-- ------------- PAGE CONTENT ------------- -->
<div class="card p-3 mb-3">
  <h5>New Invoice</h5>
  <form method="post" class="row g-2">
    <div class="col-md-2"><label class="form-label">Date</label><input class="form-control" type="date" name="invoice_dt" value="<?= h(date('Y-m-d')) ?>"></div>
    <div class="col-md-3"><label class="form-label">Customer</label><input class="form-control" name="customer_name"></div>
    <div class="col-md-2"><label class="form-label">Phone</label><input class="form-control" name="phone"></div>
    <div class="col-md-5"><label class="form-label">Notes</label><input class="form-control" name="notes"></div>
    <div class="col-md-12"><button class="btn btn-primary" name="create_invoice">Create</button></div>
  </form>
</div>

<?php if ($hdr): ?>
<div class="card p-3 mb-3">
  <div class="row g-2"><div class="col-md-3"><strong>No:</strong> <?= h($hdr['invoice_no']) ?></div><div class="col-md-3"><strong>Date:</strong> <?= h($hdr['invoice_dt']) ?></div><div class="col-md-6"><strong>Customer:</strong> <?= h($hdr['customer_name']) ?></div></div>
  <div class="row g-2 mt-2">
    <div class="col-md-3"><strong>Salesperson:</strong> <?= h($hdr['sales_user']) ?></div>
    <?php if (auth_is_admin()): ?>
    <div class="col-md-9">
      <form method="post" class="row g-2">
        <div class="col-md-3">
          <label class="form-label">Salesperson Username</label>
          <input class="form-control" name="sales_user" value="<?= h($hdr['sales_user']) ?>" placeholder="Assign salesperson (username)">
        </div>
        <div class="col-md-3">
          <label class="form-label">Commission %</label>
          <input class="form-control" type="number" step="0.01" name="commission_percent" value="<?= ($hdr['commission_percent']===null?'':n2($hdr['commission_percent'])) ?>" placeholder="Override % (optional)">
        </div>
        <div class="col-md-3">
          <?php $users = $pdo->query("SELECT id, username FROM users WHERE active=1 ORDER BY username")->fetchAll(); ?>
          <label class="form-label">Assign by User ID</label>
          <select class="form-select" name="salesperson_user_id">
            <option value="">Select User</option>
            <?php foreach($users as $u): ?>
              <option value="<?=$u["id"]?>" <?= (isset($hdr["salesperson_user_id"]) && (int)$hdr["salesperson_user_id"]===(int)$u["id"])?"selected":"" ?>>
                <?=htmlspecialchars($u["username"])?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-3 d-flex align-items-end">
          <button class="btn btn-outline-light border" name="save_sales_meta">Save Sales/Commission</button>
        </div>
      </form>
    </div>
    <?php endif; ?>
  </div>
</div>

<div class="card p-3 mb-3">
  <h5>Add Tile Row</h5>
  <form method="post" class="row g-2">
    <div class="col-md-4">
      <label class="form-label">Tile</label>
      <select class="form-select" name="tile_id">
        <?php foreach ($tiles as $t): $av = $avail_map[(int)$t['id']] ?? 0; ?>
          <option value="<?= (int)$t['id'] ?>">
            <?= h($t['name']) ?> (<?= h($t['size_label']) ?>) — Avl: <?= n2($av) ?> box
          </option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-md-2"><label class="form-label">Length (ft)</label><input class="form-control" type="number" step="0.01" name="length_ft"></div>
    <div class="col-md-2"><label class="form-label">Width (ft)</label><input class="form-control" type="number" step="0.01" name="width_ft"></div>
    <div class="col-md-2"><label class="form-label">Adjust Sqft</label><input class="form-control" type="number" step="0.01" name="extra_sqft" value="0"></div>
    <div class="col-md-2"><label class="form-label">Rate / Box (₹)</label><input class="form-control" type="number" step="0.01" name="rate_per_box"></div>
    <div class="col-md-12"><label class="form-label">Purpose</label><input class="form-control" name="purpose" placeholder="e.g. Hall 20x10"></div>
    <div class="col-md-12"><button class="btn btn-success" name="add_tile_row">Add Tile</button></div>
  </form>
</div>

<div class="card p-3 mb-3">
  <h5>Add Other Item</h5>
  <form method="post" class="row g-2">
    <div class="col-md-4"><label class="form-label">Item</label><select class="form-select" name="misc_item_id"><?php foreach ($misc_items as $m): ?><option value="<?= (int)$m['id'] ?>"><?= h($m['name']) ?> (<?= h($m['unit_label']) ?>)</option><?php endforeach; ?></select></div>
    <div class="col-md-2"><label class="form-label">Qty</label><input class="form-control" type="number" step="0.01" name="qty_units"></div>
    <div class="col-md-2"><label class="form-label">Rate / Unit (₹)</label><input class="form-control" type="number" step="0.01" name="rate_per_unit"></div>
    <div class="col-md-4"><label class="form-label">Purpose</label><input class="form-control" name="purpose"></div>
    <div class="col-md-12"><button class="btn btn-success" name="add_misc_row">Add Item</button></div>
  </form>
</div>

<?php
$tile_rows=$pdo->query("SELECT ii.*, t.name tile_name, ts.label size_label, ts.sqft_per_box spb FROM invoice_items ii JOIN tiles t ON t.id=ii.tile_id JOIN tile_sizes ts ON ts.id=t.size_id WHERE invoice_id=".$id)->fetchAll();
$misc_rows=$pdo->query("SELECT im.*, mi.name item_name, mi.unit_label FROM invoice_misc_items im JOIN misc_items mi ON mi.id=im.misc_item_id WHERE invoice_id=".$id)->fetchAll();
?>
<div class="card p-3 mb-3">
  <h5>Items (Editable)</h5>
  <div class="table-responsive"><table class="table table-striped align-middle">
    <thead><tr>
      <th>#</th><th>Purpose</th><th>Item</th><th>Size</th><th>L(ft)</th><th>W(ft)</th><th>Adjust Sqft</th><th><strong>Total Sqft</strong></th><th>Boxes(dec)</th><th>Rate/Box</th><th>Line Total</th><th>Save</th><th>Del</th>
    </tr></thead>
    <tbody>
      <?php $i=1; $sub=0.0; foreach($tile_rows as $r): $sub+=(float)$r['line_total']; ?>
      <tr>
        <form method="post">
        <td><?= $i++ ?></td>
        <td><input class="form-control form-control-sm" name="purpose" value="<?= h($r['purpose']) ?>"></td>
        <td><?= h($r['tile_name']) ?></td>
        <td><?= h($r['size_label']) ?></td>
        <td style="max-width:90px"><input class="form-control form-control-sm" type="number" step="0.01" name="length_ft" value="<?= n2($r['length_ft']) ?>"></td>
        <td style="max-width:90px"><input class="form-control form-control-sm" type="number" step="0.01" name="width_ft" value="<?= n2($r['width_ft']) ?>"></td>
        <td style="max-width:110px"><input class="form-control form-control-sm" type="number" step="0.01" name="extra_sqft" value="<?= n2($r['extra_sqft']) ?>"></td>
        <td><strong><?= n2($r['total_sqft']) ?></strong></td>
        <td><?= n3($r['boxes_decimal']) ?></td>
        <td style="max-width:120px"><input class="form-control form-control-sm" type="number" step="0.01" name="rate_per_box" value="<?= n2($r['rate_per_box']) ?>"></td>
        <td>₹ <?= n2($r['line_total']) ?></td>
        <td>
          <input type="hidden" name="row_id" value="<?= (int)$r['id'] ?>">
          <button class="btn btn-sm btn-primary" name="save_tile">Save</button>
        </td>
        <td><button class="btn btn-sm btn-outline-danger" name="del_tile" onclick="return confirm('Delete row?')">X</button></td>
        </form>
      </tr>
      <?php endforeach; foreach($misc_rows as $r): $sub+=(float)$r['line_total']; ?>
      <tr>
        <form method="post">
        <td><?= $i++ ?></td>
        <td><input class="form-control form-control-sm" name="purpose" value="<?= h($r['purpose']) ?>"></td>
        <td><?= h($r['item_name']) ?> (<?= h($r['unit_label']) ?>)</td>
        <td>—</td><td>—</td><td>—</td><td>—</td>
        <td>—</td>
        <td style="max-width:100px"><input class="form-control form-control-sm" type="number" step="0.01" name="qty_units" value="<?= n3($r['qty_units']) ?>"></td>
        <td style="max-width:120px"><input class="form-control form-control-sm" type="number" step="0.01" name="rate_per_unit" value="<?= n2($r['rate_per_unit']) ?>"></td>
        <td>₹ <?= n2($r['line_total']) ?></td>
        <td>
          <input type="hidden" name="row_id" value="<?= (int)$r['id'] ?>">
          <button class="btn btn-sm btn-primary" name="save_misc">Save</button>
        </td>
        <td><button class="btn btn-sm btn-outline-danger" name="del_misc" onclick="return confirm('Delete row?')">X</button></td>
        </form>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table></div>
</div>

<?php
$disc_type=$hdr['discount_type']; $disc_val=(float)$hdr['discount_value']; $gst_mode=$hdr['gst_mode']; $gst_pct=(float)$hdr['gst_percent'];
$disc=($disc_type==='PERCENT')?($sub*$disc_val/100.0):$disc_val; $base=max(0.0,$sub-$disc); $gst_amt=($gst_mode==='EXCLUDE')?($base*$gst_pct/100.0):0.0; $total=($gst_mode==='EXCLUDE')?($base+$gst_amt):$base;
$paid=(float)$pdo->query("SELECT COALESCE(SUM(amount),0) FROM invoice_payments WHERE invoice_id=".$id)->fetchColumn(); $bal=max(0.0,$total-$paid);
?>
<div class="card p-3 mb-3">
  <h5>Totals & Payments</h5>
  <form method="post" class="row g-2">
    <div class="col-md-3"><label class="form-label">Discount Type</label><select class="form-select" name="discount_type"><option <?= $disc_type==='AMOUNT'?'selected':'' ?>>AMOUNT</option><option <?= $disc_type==='PERCENT'?'selected':'' ?>>PERCENT</option></select></div>
    <div class="col-md-2"><label class="form-label">Discount Value</label><input class="form-control" type="number" step="0.01" name="discount_value" value="<?= n2($disc_val) ?>"></div>
    <div class="col-md-2"><label class="form-label">GST Mode</label><select name="gst_mode" class="form-select"><option <?= $gst_mode==='EXCLUDE'?'selected':'' ?>>EXCLUDE</option><option <?= $gst_mode==='INCLUDE'?'selected':'' ?>>INCLUDE</option></select></div>
    <div class="col-md-2"><label class="form-label">GST %</label><input class="form-control" type="number" step="0.01" name="gst_percent" value="<?= n2($gst_pct) ?>"></div>
    <div class="col-md-3 d-flex align-items-end"><button class="btn btn-primary" name="update_totals">Update Totals</button></div>
  </form>
  <div class="mt-2"><strong>Subtotal:</strong> ₹ <?= n2($sub) ?> &nbsp; <strong>Total:</strong> ₹ <?= n2($total) ?> &nbsp; <strong>Paid:</strong> ₹ <?= n2($paid) ?> &nbsp; <strong>Balance:</strong> ₹ <?= n2($bal) ?></div>
  
  <?php
  // Show commission information
  require_once __DIR__ . '/../includes/commission.php';
  $commission_st = $pdo->prepare("SELECT * FROM commission_ledger WHERE invoice_id = ?");
  $commission_st->execute([$id]);
  $commission = $commission_st->fetch(PDO::FETCH_ASSOC);
  
  if ($commission):
  ?>
  <div class="mt-2 p-2 bg-light rounded">
    <small class="text-muted"><strong>Commission Info:</strong></small><br>
    <small>
      <strong>Base Amount:</strong> ₹ <?= n2($commission['base_amount']) ?> &nbsp;
      <strong>Commission %:</strong> <?= n2($commission['pct']) ?>% &nbsp;
      <strong>Commission Amount:</strong> ₹ <?= n2($commission['amount']) ?> &nbsp;
      <strong>Status:</strong> 
      <span class="badge <?= 
          $commission['status'] === 'PAID' ? 'text-bg-success' : 
          ($commission['status'] === 'APPROVED' ? 'text-bg-info' : 'text-bg-warning') 
      ?>">
          <?= h($commission['status']) ?>
      </span>
    </small>
  </div>
  <?php endif; ?>
</div>

<div class="card p-3">
  <h5>Payments</h5>
  <form method="post" class="row g-2 mb-2">
    <div class="col-md-3"><input class="form-control" type="date" name="pay_dt" value="<?= h(date('Y-m-d')) ?>"></div>
    <div class="col-md-3"><select class="form-select" name="method"><option>CASH</option><option>CARD</option><option>UPI</option><option>BANK</option><option>CREDIT</option><option>OTHER</option></select></div>
    <div class="col-md-3"><input class="form-control" type="number" step="0.01" name="amount" placeholder="Amount"></div>
    <div class="col-md-3"><input class="form-control" name="reference" placeholder="Ref # (optional)"></div>
    <div class="col-md-12"><input class="form-control" name="notes" placeholder="Notes"></div>
    <div class="col-md-12"><button class="btn btn-success" name="add_payment">Add Payment</button></div>
  </form>
  <?php $pays=$pdo->query("SELECT * FROM invoice_payments WHERE invoice_id=".$id." ORDER BY id DESC")->fetchAll(); if($pays): ?>
  <div class="table-responsive"><table class="table table-sm table-striped align-middle">
    <thead><tr><th>Date</th><th>Method</th><th>Amount</th><th>Ref</th><th>Notes</th><th></th></tr></thead>
    <tbody><?php foreach($pays as $p): ?><tr>
      <td><?= h($p['pay_dt']) ?></td><td><?= h($p['method']) ?></td><td>₹ <?= n2($p['amount']) ?></td><td><?= h($p['reference']) ?></td><td><?= h($p['notes']) ?></td>
      <td><form method="post" onsubmit="return confirm('Delete payment?')"><input type="hidden" name="payment_id" value="<?= (int)$p['id'] ?>"><button class="btn btn-sm btn-outline-danger" name="del_payment">X</button></form></td>
    </tr><?php endforeach; ?></tbody>
  </table></div>
  <?php endif; ?>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
