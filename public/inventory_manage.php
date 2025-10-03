<?php
// ========== Inventory Edit / Adjust (live calc + avail & net value + stock validation) ==========
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/header.php';

$pdo = Database::pdo();

$page_title = "Inventory Edit / Adjust";

/* ---------------- schema helpers ---------------- */
function col_exists(PDO $pdo, string $table, string $col): bool {
  foreach ($pdo->query("PRAGMA table_info($table)") as $r) {
    if (strcasecmp($r['name'], $col) === 0) return true;
  }
  return false;
}
function ensure_col(PDO $pdo, string $table, string $col, string $ddl): void {
  if (!col_exists($pdo, $table, $col)) $pdo->exec("ALTER TABLE $table ADD COLUMN $col $ddl");
}
ensure_col($pdo, 'inventory_items',      'vendor',       'TEXT');
ensure_col($pdo, 'inventory_items',      'purchase_dt',  'TEXT');
ensure_col($pdo, 'misc_inventory_items', 'vendor',       'TEXT');
ensure_col($pdo, 'misc_inventory_items', 'purchase_dt',  'TEXT');

/* helpers */
function P($k, $d=null){ return $_POST[$k] ?? $d; }
function Pn($k){ $v = P($k, 0); return is_numeric($v) ? (float)$v : 0.0; }
function Pid($k){ $v = P($k, 0); return is_numeric($v) ? (int)$v : 0; }

/* ================= Pricing helpers ================= */
function base_per_box(array $r, float $spb): float {
  $base = (float)($r['per_box_value'] ?? 0);
  if ($base <= 0 && (float)($r['per_sqft_value'] ?? 0) > 0) {
    $base = (float)$r['per_sqft_value'] * $spb;
  }
  return $base;
}
function net_boxes_row(array $r): float {
  $in  = (float)($r['boxes_in'] ?? 0);
  $dam = (float)($r['damage_boxes'] ?? 0);
  return max(0.0, $in - $dam);
}
function total_trans_per_box(array $r, float $spb): float {
  $base = base_per_box($r, $spb);
  $pct  = (float)($r['transport_pct'] ?? 0.0);
  $from_pct   = $base * ($pct/100.0);
  $per_box    = (float)($r['transport_per_box'] ?? 0.0); // constant per-box (if any)
  $nb         = net_boxes_row($r);
  $tr_total   = (float)($r['transport_total'] ?? 0.0);
  $from_total = ($tr_total > 0 && $nb > 0) ? ($tr_total / $nb) : 0.0;
  return $from_pct + $per_box + $from_total;
}
function cost_box_incl(array $r, float $spb): float {
  return base_per_box($r,$spb) + total_trans_per_box($r,$spb);
}
function cost_sqft_incl(array $r, float $spb): float {
  return ($spb > 0) ? (cost_box_incl($r,$spb) / $spb) : 0.0;
}
// Other Items (kept here in case you extend this page later)
function net_units(array $r): float {
  $in  = (float)($r['qty_in'] ?? 0);
  $dam = (float)($r['damage_units'] ?? 0);
  return max(0.0, $in - $dam);
}
function total_trans_per_unit(array $r): float {
  $base = (float)($r['cost_per_unit'] ?? 0);
  $pct  = (float)($r['transport_pct'] ?? 0.0);
  $from_pct   = $base * ($pct/100.0);
  $per_unit   = (float)($r['transport_per_unit'] ?? 0.0);
  $nu         = net_units($r);
  $tr_total   = (float)($r['transport_total'] ?? 0.0);
  $from_total = ($tr_total > 0 && $nu > 0) ? ($tr_total / $nu) : 0.0;
  return $from_pct + $per_unit + $from_total;
}
function cost_unit_incl(array $r): float {
  return (float)($r['cost_per_unit'] ?? 0) + total_trans_per_unit($r);
}

/* ================= Availability helper ================= */
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

/* ================= POST handlers ================= */
// Save TILE row with improved validation and error handling
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['save_inv_row'])){
  $id = Pid('row_id');
  if ($id<=0) { 
    header("Location: inventory_manage.php?err=".urlencode("Invalid item ID")); 
    exit; 
  }

  // Validate inputs
  $boxes_in = Pn('boxes_in');
  $damage = Pn('damage_boxes');
  $per_box_value = Pn('per_box_value');
  $per_sqft_value = Pn('per_sqft_value');
  $transport_pct = Pn('transport_pct');
  $transport_total = Pn('transport_total');
  
  // Enhanced validation
  if ($boxes_in < 0) {
    header("Location: inventory_manage.php?err=".urlencode("Boxes in cannot be negative")."#row$id");
    exit;
  }
  
  if ($damage < 0 || $damage > $boxes_in) {
    header("Location: inventory_manage.php?err=".urlencode("Damage boxes must be between 0 and total boxes")."#row$id");
    exit;
  }
  
  if ($per_box_value < 0 || $per_sqft_value < 0) {
    header("Location: inventory_manage.php?err=".urlencode("Values cannot be negative")."#row$id");
    exit;
  }
  
  if ($per_box_value == 0 && $per_sqft_value == 0) {
    header("Location: inventory_manage.php?err=".urlencode("Either per box value or per sqft value must be provided")."#row$id");
    exit;
  }

  // find tile id to compute availability
  $tileIdStmt = $pdo->prepare("SELECT tile_id FROM inventory_items WHERE id=?");
  $tileIdStmt->execute([$id]);
  $tile_id = (int)$tileIdStmt->fetchColumn();
  
  if (!$tile_id) {
    header("Location: inventory_manage.php?err=".urlencode("Inventory item not found")."#row$id");
    exit;
  }
  
  $avail = available_boxes($pdo, $tile_id);
  $net = max(0,$boxes_in - $damage);

  // Improved availability check - only warn if trying to add more than available
  $current_item_stmt = $pdo->prepare("SELECT boxes_in - COALESCE(damage_boxes, 0) as current_net FROM inventory_items WHERE id=?");
  $current_item_stmt->execute([$id]);
  $current_net = (float)$current_item_stmt->fetchColumn();
  
  $net_change = $net - $current_net;
  if ($net_change > $avail) {
    header("Location: inventory_manage.php?err=".urlencode("Cannot add $net_change boxes. Only $avail boxes available")."#row$id");
    exit;
  }

  $purchase_dt = (string)P('purchase_dt','') ?: date('Y-m-d');

  $sets = []; $params = [':id'=>$id];
  if (col_exists($pdo,'inventory_items','vendor'))            { $sets[]='vendor=:vendor';                       $params[':vendor']            = (string)P('vendor',''); }
  if (col_exists($pdo,'inventory_items','purchase_dt'))       { $sets[]='purchase_dt=:purchase_dt';             $params[':purchase_dt']       = $purchase_dt; }
  if (col_exists($pdo,'inventory_items','per_box_value'))     { $sets[]='per_box_value=:per_box_value';         $params[':per_box_value']     = $per_box_value; }
  if (col_exists($pdo,'inventory_items','per_sqft_value'))    { $sets[]='per_sqft_value=:per_sqft_value';       $params[':per_sqft_value']    = $per_sqft_value; }
  if (col_exists($pdo,'inventory_items','boxes_in'))          { $sets[]='boxes_in=:boxes_in';                   $params[':boxes_in']          = $boxes_in; }
  if (col_exists($pdo,'inventory_items','damage_boxes'))      { $sets[]='damage_boxes=:damage_boxes';           $params[':damage_boxes']      = $damage; }
  if (col_exists($pdo,'inventory_items','transport_pct'))     { $sets[]='transport_pct=:transport_pct';         $params[':transport_pct']     = $transport_pct; }
  if (col_exists($pdo,'inventory_items','transport_per_box')) { $sets[]='transport_per_box=:transport_per_box'; $params[':transport_per_box'] = Pn('transport_per_box'); }
  if (col_exists($pdo,'inventory_items','transport_total'))   { $sets[]='transport_total=:transport_total';     $params[':transport_total']   = $transport_total; }
  if (col_exists($pdo,'inventory_items','notes'))             { $sets[]='notes=:notes';                         $params[':notes']             = (string)P('notes',''); }

  if ($sets) {
    try {
      $sql = "UPDATE inventory_items SET ".implode(', ', $sets)." WHERE id=:id";
      $stmt = $pdo->prepare($sql);
      $stmt->execute($params);
      header("Location: inventory_manage.php?success=".urlencode("Item updated successfully")."#row$id"); 
    } catch (Exception $e) {
      header("Location: inventory_manage.php?err=".urlencode("Update failed: " . $e->getMessage())."#row$id");
    }
  }
  exit;
}

// Delete TILE row with usage validation
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['del_inv_row'])){
  $id = Pid('row_id'); 
  if ($id > 0) {
    // Check if this inventory item's tile has been used in any invoices
    $usage_check = $pdo->prepare("
      SELECT COUNT(*) FROM invoice_items ii 
      JOIN inventory_items inv ON inv.tile_id = ii.tile_id 
      WHERE inv.id = ?
    ");
    $usage_check->execute([$id]);
    $usage_count = (int)$usage_check->fetchColumn();
    
    if ($usage_count > 0) {
      header("Location: inventory_manage.php?err=".urlencode("Cannot delete: This item's tile is referenced in $usage_count invoice(s)"));
      exit;
    }
    
    try {
      $pdo->prepare("DELETE FROM inventory_items WHERE id=?")->execute([$id]);
      header("Location: inventory_manage.php?success=".urlencode("Item deleted successfully"));
    } catch (Exception $e) {
      header("Location: inventory_manage.php?err=".urlencode("Delete failed: " . $e->getMessage()));
    }
  } else {
    header("Location: inventory_manage.php?err=".urlencode("Invalid item ID"));
  }
  exit;
}

/* ================= Fetch data ================= */
$inv = $pdo->query("
  SELECT ii.*, t.name AS tile_name, ts.label AS size_label, ts.sqft_per_box AS spb
  FROM inventory_items ii
  JOIN tiles t       ON t.id = ii.tile_id
  JOIN tile_sizes ts ON ts.id = t.size_id
  ORDER BY ii.id DESC
")->fetchAll(PDO::FETCH_ASSOC);

/* ================= Render ================= */
require_once __DIR__ . '/../includes/header.php';
?>
<style>
  .table.compact td, .table.compact th { padding:.6rem .8rem; }
  .text-end { text-align:right; }
  .w-xxs { min-width:78px; max-width:100px; }
  .w-xs  { min-width:108px; max-width:140px; }
  .w-sm  { min-width:140px; max-width:200px; }
  .w-md  { min-width:180px; max-width:260px; }
  .total-trans { color:#000 !important; font-weight:600; }
  .muted { color:#6c757d; }
</style>

<div class="card p-3 mb-3">
  <h5 class="mb-1">Tile Inventory Rows</h5>
  <hr class="mt-2 mb-3">

  <?php if (!empty($_GET['err'])): ?>
    <div class="alert alert-danger alert-dismissible fade show">
      <?= h($_GET['err']) ?>
      <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
  <?php endif; ?>
  
  <?php if (!empty($_GET['success'])): ?>
    <div class="alert alert-success alert-dismissible fade show">
      <?= h($_GET['success']) ?>
      <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
  <?php endif; ?>

  <div class="table-responsive">
    <table class="table table-striped table-hover table-bordered table-sm align-middle compact">
      <thead>
        <tr>
          <th>#</th><th>Tile</th><th>Size</th>
          <th>Vendor</th><th>Purchase</th>
          <th class="text-end">Per Box</th><th class="text-end">Per Sqft</th>
          <th class="text-end">Boxes In</th><th class="text-end">Damage</th>
          <th class="text-end">Trans %</th><th class="text-end text-dark"><strong>Total Trans/Box</strong></th><th class="text-end">Trans Total</th>
          <th class="text-end muted">Cost/Box (incl.)</th><th class="text-end muted">Cost/Sqft (incl.)</th>
          <th class="text-end">Avail (boxes)</th><th class="text-end muted">Net Value</th>
          <th>Notes</th><th>Save</th><th>Del</th>
        </tr>
      </thead>
      <tbody>
      <?php
        $grand_value = 0.0;
        foreach($inv as $r):
          $spb       = (float)($r['spb'] ?? 0);
          $ttb       = total_trans_per_box($r,$spb);
          $cb        = cost_box_incl($r,$spb);
          $cs        = cost_sqft_incl($r,$spb);
          $avail     = available_boxes($pdo,(int)$r['tile_id']);
          $net_value = $avail * $cb;
          $grand_value += $net_value;
      ?>
        <tr id="row<?= (int)$r['id'] ?>"
            data-spb="<?= n3($spb) ?>"
            data-rowid="<?= (int)$r['id'] ?>"
            data-tpb="<?= n2($r['transport_per_box'] ?? 0) ?>"
            data-avail="<?= n3($avail) ?>">
          <td>#<?= (int)$r['id'] ?></td>
          <td><?= h($r['tile_name']) ?></td>
          <td><?= h($r['size_label']) ?></td>
          <form method="post" class="tile-form">
            <td><input class="form-control form-control-sm w-sm"  name="vendor" value="<?= h($r['vendor'] ?? '') ?>"></td>
            <td><input class="form-control form-control-sm w-xs"  type="date"   name="purchase_dt" value="<?= h($r['purchase_dt'] ?? '') ?>"></td>

            <td class="text-end w-xs"><input class="form-control form-control-sm w-xs text-end"  type="number" step="0.01"  name="per_box_value"  value="<?= n2($r['per_box_value'] ?? 0) ?>"></td>
            <td class="text-end w-xs"><input class="form-control form-control-sm w-xs text-end"  type="number" step="0.01"  name="per_sqft_value" value="<?= n2($r['per_sqft_value'] ?? 0) ?>"></td>

            <td class="text-end w-xxs"><input class="form-control form-control-sm w-xxs text-end" type="number" step="0.001" name="boxes_in"     value="<?= n3($r['boxes_in'] ?? 0) ?>"></td>
            <td class="text-end w-xxs"><input class="form-control form-control-sm w-xxs text-end" type="number" step="0.001" name="damage_boxes" value="<?= n3($r['damage_boxes'] ?? 0) ?>"></td>

            <td class="text-end w-xxs"><input class="form-control form-control-sm w-xxs text-end" type="number" step="0.01"  name="transport_pct" value="<?= n2($r['transport_pct'] ?? 0) ?>"></td>
            <td class="text-end total-trans js-ttb w-xs"><?= n2($ttb) ?></td>
            <td class="text-end w-xs"><input class="form-control form-control-sm w-xs text-end"  type="number" step="0.01"  name="transport_total" value="<?= n2($r['transport_total'] ?? 0) ?>"></td>

            <td class="text-end muted js-cb w-xs"><?= n2($cb) ?></td>
            <td class="text-end muted js-cs w-xs"><?= n2($cs) ?></td>
            <td class="text-end w-xxs">
              <strong class="<?= $avail>0 ? 'text-success' : 'text-danger' ?>">
                <?= $avail>0 ? n3($avail) : 'Not available' ?>
              </strong>
            </td>
            <td class="text-end muted js-net w-md"><?= n2($net_value) ?></td>

            <td><input class="form-control form-control-sm w-md"  name="notes" value="<?= h($r['notes'] ?? '') ?>"></td>
            <td>
              <input type="hidden" name="row_id" value="<?= (int)$r['id'] ?>">
              <button class="btn btn-sm btn-primary" name="save_inv_row" value="1">Save</button>
            </td>
            <td>
              <button class="btn btn-sm btn-outline-danger" onclick="confirmDelete(<?= (int)$r['id'] ?>)">Delete</button>
            </td>
          </form>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <div class="alert alert-info mt-3">
    <strong>Grand Stock Value:</strong> ₹ <?= n2($grand_value) ?>
  </div>
</div>

<script>
// -------- helper: formatters ----------
const n2 = v => (isFinite(v) ? Number(v).toFixed(2) : '0.00');
const n3 = v => (isFinite(v) ? Number(v).toFixed(3) : '0.000');
const num = v => {
  if (v === null || v === undefined) return 0;
  const s = String(v).trim();
  if (!s) return 0;
  const x = parseFloat(s.replace(/,/g,''));
  return isFinite(x) ? x : 0;
};

// -------- improved live calc for TILE rows ----------
document.querySelectorAll('tr[id^="row"]').forEach(tr => {
  const spb = num(tr.dataset.spb || '0');
  const tpbConst = num(tr.dataset.tpb || '0');
  const availServer = num(tr.dataset.avail || '0');
  const form = tr.querySelector('form.tile-form');
  if (!form) return;

  const fld = name => form.querySelector(`[name="${name}"]`);
  const elTtb = tr.querySelector('.js-ttb');
  const elCb  = tr.querySelector('.js-cb');
  const elCs  = tr.querySelector('.js-cs');
  const elNet = tr.querySelector('.js-net');

  const recalc = () => {
    const perBox = num(fld('per_box_value')?.value);
    const perSqf = num(fld('per_sqft_value')?.value);
    const boxes  = num(fld('boxes_in')?.value);
    const dmg    = num(fld('damage_boxes')?.value);
    const pct    = num(fld('transport_pct')?.value);
    const tpb    = num(fld('transport_per_box')?.value);
    const ttot   = num(fld('transport_total')?.value);

    // Validation highlighting
    const boxesInput = fld('boxes_in');
    const damageInput = fld('damage_boxes');
    const perBoxInput = fld('per_box_value');
    const perSqftInput = fld('per_sqft_value');
    
    // Reset validation styles
    [boxesInput, damageInput, perBoxInput, perSqftInput].forEach(input => {
      if (input) input.classList.remove('is-invalid', 'is-valid');
    });
    
    // Validate and highlight issues
    if (boxes < 0) {
      boxesInput?.classList.add('is-invalid');
      return;
    } else {
      boxesInput?.classList.add('is-valid');
    }
    
    if (dmg < 0 || dmg > boxes) {
      damageInput?.classList.add('is-invalid');
      return;
    } else {
      damageInput?.classList.add('is-valid');
    }
    
    if (perBox === 0 && perSqf === 0) {
      perBoxInput?.classList.add('is-invalid');
      perSqftInput?.classList.add('is-invalid');
      return;
    } else {
      perBoxInput?.classList.add('is-valid');
      perSqftInput?.classList.add('is-valid');
    }

    const base   = perBox > 0 ? perBox : (perSqf > 0 ? perSqf * spb : 0);
    const netB   = Math.max(0, boxes - dmg);

    const fromPct   = base * (pct/100);
    const fromTotal = (ttot > 0 && netB > 0) ? (ttot / netB) : 0;

    const ttb = fromPct + tpb + fromTotal;
    const cb  = base + ttb;
    const cs  = spb > 0 ? (cb / spb) : 0;
    const netValue = netB * cb;

    if (elTtb) elTtb.textContent = n2(ttb);
    if (elCb)  elCb.textContent  = n2(cb);
    if (elCs)  elCs.textContent  = n2(cs);
    if (elNet) elNet.textContent = n2(netValue);
    
    // Update row background based on validation
    if (boxes < 0 || dmg < 0 || dmg > boxes || (perBox === 0 && perSqf === 0)) {
      tr.style.backgroundColor = '#f8d7da';
    } else {
      tr.style.backgroundColor = '';
    }
  };

  form.querySelectorAll('input').forEach(inp => {
    inp.addEventListener('input', recalc);
    inp.addEventListener('blur', recalc);
  });
  
  // Initial calculation
  recalc();
});

// Confirmation for delete
function confirmDelete(itemId) {
  if (confirm('Are you sure you want to delete this inventory item? This action cannot be undone.')) {
    const form = document.createElement('form');
    form.method = 'POST';
    form.innerHTML = `
      <input type="hidden" name="row_id" value="${itemId}">
      <input type="hidden" name="del_inv_row" value="1">
    `;
    document.body.appendChild(form);
    form.submit();
  }
}

// Auto-save functionality (optional)
let autoSaveTimeout = null;
document.querySelectorAll('tr[id^="row"] input').forEach(input => {
  input.addEventListener('input', function() {
    // Clear previous timeout
    if (autoSaveTimeout) {
      clearTimeout(autoSaveTimeout);
    }
    
    // Show unsaved changes indicator
    const saveBtn = this.closest('tr').querySelector('button[name="save_inv_row"]');
    if (saveBtn) {
      saveBtn.classList.add('btn-warning');
      saveBtn.textContent = 'Save*';
    }
    
    // Auto-save after 3 seconds of no changes
    autoSaveTimeout = setTimeout(() => {
      if (confirm('Auto-save changes?')) {
        this.closest('form').submit();
      }
    }, 3000);
  });
});

// Reset save button on focus
document.querySelectorAll('button[name="save_inv_row"]').forEach(btn => {
  btn.addEventListener('click', function() {
    this.classList.remove('btn-warning');
    this.classList.add('btn-primary');
    this.textContent = 'Save';
  });
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
