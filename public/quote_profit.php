<?php
$page_title="Quotation P/L Detail";
require_once __DIR__ . '/../includes/header.php';
require_admin();
$pdo = Database::pdo();
$id = (int)($_GET['id'] ?? 0);
$hdr = null; if ($id>0){ $st=$pdo->prepare("SELECT * FROM quotations WHERE id=?"); $st->execute([$id]); $hdr=$st->fetch(); }
if (!$hdr){ echo "<div class='alert alert-danger'>Quotation not found.</div>"; require_once __DIR__ . '/../includes/footer.php'; exit; }

function tile_cost_box(PDO $pdo, int $tile_id){
  $spb=(float)$pdo->query("SELECT ts.sqft_per_box FROM tiles t JOIN tile_sizes ts ON ts.id=t.size_id WHERE t.id=".$tile_id)->fetchColumn();
  $rows=$pdo->query("SELECT per_box_value, per_sqft_value, transport_pct, transport_per_box, transport_total, (boxes_in - damage_boxes) qty FROM inventory_items WHERE tile_id=".$tile_id)->fetchAll();
  $csum=0.0;$qty=0.0; foreach($rows as $r){ $base=(float)$r['per_box_value']; if($base<=0 && (float)$r['per_sqft_value']>0) $base=(float)$r['per_sqft_value']*$spb; $trans=0.0; if((float)$r['transport_pct']>0)$trans+=$base*((float)$r['transport_pct']/100.0); if((float)$r['transport_per_box']>0)$trans+=(float)$r['transport_per_box']; if((float)$r['transport_total']>0 && (float)$r['qty']>0)$trans+=((float)$r['transport_total']/(float)$r['qty']); $eff=$base+$trans; $q=max(0,(float)$r['qty']); $csum+=$eff*$q; $qty+=$q; } return ($qty>0)?($csum/$qty):0.0; }

$tiles = $pdo->query("SELECT qi.*, t.name tile_name, ts.label size_label FROM quotation_items qi JOIN tiles t ON t.id=qi.tile_id JOIN tile_sizes ts ON ts.id=t.size_id WHERE quotation_id=".$id)->fetchAll();
$misc  = $pdo->query("SELECT qmi.*, mi.name item_name, mi.id mid, mi.unit_label FROM quotation_misc_items qmi JOIN misc_items mi ON mi.id=qmi.misc_item_id WHERE quotation_id=".$id)->fetchAll();

$total_rev=(float)$hdr['total']; $total_cost=0.0;
?>
<div class="card p-3 mb-3"><div class="row g-2">
  <div class="col-md-3"><strong>No:</strong> <?= h($hdr['quote_no']) ?></div>
  <div class="col-md-3"><strong>Date:</strong> <?= h($hdr['quote_dt']) ?></div>
  <div class="col-md-6"><strong>Customer:</strong> <?= h($hdr['customer_name']) ?></div>
</div></div>

<div class="card p-3 mb-3">
  <h5>Tiles</h5>
  <div class="table-responsive"><table class="table table-sm table-striped align-middle">
    <thead><tr><th>#</th><th>Tile</th><th>Size</th><th>Boxes</th><th>Sell/Box</th><th>Cost/Box</th><th>Line Rev</th><th>Line Cost</th><th>Profit</th><th>Margin %</th></tr></thead>
    <tbody><?php $i=1; foreach($tiles as $r): $boxes=(float)$r['boxes_decimal']; $sell=(float)$r['rate_per_box']; $cost=tile_cost_box($pdo,(int)$r['tile_id']); $lineRev=$boxes*$sell; $lineCost=$boxes*$cost; $lineProf=$lineRev-$lineCost; $marg=($lineRev>0)?($lineProf*100/$lineRev):0.0; $total_cost+=$lineCost; ?>
      <tr><td><?= $i++ ?></td><td><?= h($r['tile_name']) ?></td><td><?= h($r['size_label']) ?></td><td><?= n3($boxes) ?></td><td>₹ <?= n2($sell) ?></td><td>₹ <?= n2($cost) ?></td><td>₹ <?= n2($lineRev) ?></td><td>₹ <?= n2($lineCost) ?></td><td class="<?= $lineProf<0?'text-danger':'text-success' ?>">₹ <?= n2($lineProf) ?></td><td class="<?= $marg<0?'text-danger':($marg>=10?'text-success':($marg<=5?'text-danger':'')) ?>"><?= n2($marg) ?>%</td></tr>
    <?php endforeach; ?></tbody>
  </table></div>
</div>

<div class="card p-3 mb-3">
  <h5>Other Items</h5>
  <div class="table-responsive"><table class="table table-sm table-striped align-middle">
    <thead><tr><th>#</th><th>Item</th><th>Qty</th><th>Sell/Unit</th><th>Cost/Unit</th><th>Line Rev</th><th>Line Cost</th><th>Profit</th><th>Margin %</th></tr></thead>
    <tbody><?php $j=1; foreach($misc as $r): 
      $rows=$pdo->query("SELECT cost_per_unit, transport_pct, transport_per_unit, transport_total, (qty_in - damage_units) qty FROM misc_inventory_items WHERE misc_item_id=".(int)$r['mid'])->fetchAll();
      $csum=0.0; $qsum=0.0; foreach($rows as $m){ $base=(float)$m['cost_per_unit']; $trans=0.0; if((float)$m['transport_pct']>0)$trans+=$base*((float)$m['transport_pct']/100.0); if((float)$m['transport_per_unit']>0)$trans+=(float)$m['transport_per_unit']; if((float)$m['transport_total']>0 && (float)$m['qty']>0)$trans+=((float)$m['transport_total']/(float)$m['qty']); $eff=$base+$trans; $csum+=$eff*(float)$m['qty']; $qsum+=(float)$m['qty']; }
      $cpu=($qsum>0)?($csum/$qsum):0.0; $qty=(float)$r['qty_units']; $sell=(float)$r['rate_per_unit']; $lineRev=$qty*$sell; $lineCost=$qty*$cpu; $lineProf=$lineRev-$lineCost; $marg=($lineRev>0)?($lineProf*100/$lineRev):0.0; $total_cost+=$lineCost;
    ?><tr><td><?= $j++ ?></td><td><?= h($r['item_name']) ?> (<?= h($r['unit_label']) ?>)</td><td><?= n3($qty) ?></td><td>₹ <?= n2($sell) ?></td><td>₹ <?= n2($cpu) ?></td><td>₹ <?= n2($lineRev) ?></td><td>₹ <?= n2($lineCost) ?></td><td class="<?= $lineProf<0?'text-danger':'text-success' ?>">₹ <?= n2($lineProf) ?></td><td class="<?= $marg<0?'text-danger':($marg>=10?'text-success':($marg<=5?'text-danger':'')) ?>"><?= n2($marg) ?>%</td></tr><?php endforeach; ?></tbody>
  </table></div>
</div>

<?php $revenue=(float)$hdr['total']; $gross=$revenue-$total_cost; $margin=($revenue>0)?($gross*100/$revenue):0.0; ?>
<div class="card p-3"><div class="row g-2">
  <div class="col-md-3"><strong>Total Revenue:</strong> ₹ <?= n2($revenue) ?></div>
  <div class="col-md-3"><strong>Total Cost:</strong> ₹ <?= n2($total_cost) ?></div>
  <div class="col-md-3"><strong>Gross Profit:</strong> ₹ <?= n2($gross) ?></div>
  <div class="col-md-3"><strong>Margin:</strong> <?= n2($margin) ?>%</div>
</div></div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
