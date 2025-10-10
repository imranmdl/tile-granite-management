<?php
$page_title = "My Daily Business Report";
require_once __DIR__ . '/../includes/header.php';
$pdo = Database::pdo();

// ---------- Config toggles ----------
$TRANSPORT_MODE = 'PERCENT'; // 'PERCENT' or 'PER_BOX'
$DEFAULT_DAYS   = 1;
$INVOICE_TOTAL_IS_NET = true; // if using invoice header totals as fallback, treat them as NET after discount+commission
// ------------------------------------

function db_driver(PDO $pdo): string {
  return $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) ?: 'unknown';
}

function table_exists(PDO $pdo, string $table): bool {
  $drv = db_driver($pdo);
  try {
    if ($drv === 'sqlite') {
      $st = $pdo->prepare("SELECT name FROM sqlite_master WHERE type='table' AND name=?");
      $st->execute([$table]);
      return (bool)$st->fetchColumn();
    } else {
      $st = $pdo->prepare("SHOW TABLES LIKE ?");
      $st->execute([$table]);
      return (bool)$st->fetchColumn();
    }
  } catch (Throwable $e) { return false; }
}

function column_exists(PDO $pdo, string $table, string $col): bool {
  if (!table_exists($pdo, $table)) return false;
  $drv = db_driver($pdo);
  try {
    if ($drv === 'sqlite') {
      $st = $pdo->query("PRAGMA table_info(".$table.")");
      foreach ($st ?: [] as $r) {
        if (strcasecmp($r['name'] ?? '', $col) === 0) return true;
      }
      return false;
    } else {
      $st = $pdo->prepare("SHOW COLUMNS FROM `$table` LIKE ?");
      $st->execute([$col]);
      return (bool)$st->fetch();
    }
  } catch (Throwable $e) { return false; }
}

function first_existing_column(PDO $pdo, string $table, array $candidates, $default=null) {
  foreach ($candidates as $c) if (column_exists($pdo, $table, $c)) return $c;
  return $default;
}

// Tables
$INVOICE_TABLE = 'invoices';
$INV_ITEMS_TABLE       = table_exists($pdo,'invoice_items')        ? 'invoice_items'        : null;
$INV_MISC_TABLE        = table_exists($pdo,'invoice_misc_items')   ? 'invoice_misc_items'   : null;
$INV_RET_ITEMS_TABLE   = table_exists($pdo,'invoice_return_items') ? 'invoice_return_items' : null;
$INV_TABLE             = table_exists($pdo,'inventory_items')      ? 'inventory_items'      : null;

// Invoice header columns
$INVOICE_DATE_COL       = first_existing_column($pdo, $INVOICE_TABLE, ['invoice_dt','inv_dt','date','created_at','created_on']);
$INVOICE_TOTAL_COL      = first_existing_column($pdo, $INVOICE_TABLE, ['grand_total','total','total_amount','net_total']);
$INVOICE_DISCOUNT_VAL   = first_existing_column($pdo, $INVOICE_TABLE, ['discount_value','discount','discount_amount']);
$INVOICE_DISCOUNT_ISPCT = first_existing_column($pdo, $INVOICE_TABLE, ['discount_is_percent']);
$INVOICE_COMM_VAL       = first_existing_column($pdo, $INVOICE_TABLE, ['commission_value','commission','commission_amount']);
$INVOICE_COMM_ISPCT     = first_existing_column($pdo, $INVOICE_TABLE, ['commission_is_percent']);
$INVOICE_SALES_USER     = first_existing_column($pdo, $INVOICE_TABLE, ['sales_user','salesperson','created_by','user_name']);

// Invoice item columns (tiles)
$INV_ITEM_QTY_BOX       = $INV_ITEMS_TABLE ? first_existing_column($pdo, $INV_ITEMS_TABLE, ['boxes_decimal','qty_boxes','boxes','qty']) : null;
$INV_ITEM_PRICE_BOX     = $INV_ITEMS_TABLE ? first_existing_column($pdo, $INV_ITEMS_TABLE, ['price_per_box','sell_price_box','selling_rate_box','rate_box']) : null;
$INV_ITEM_COST_AT_SALE  = $INV_ITEMS_TABLE ? first_existing_column($pdo, $INV_ITEMS_TABLE, ['cost_per_box_at_sale','cost_box_at_sale']) : null;
$INV_ITEM_INVITEM_FK    = $INV_ITEMS_TABLE ? first_existing_column($pdo, $INV_ITEMS_TABLE, ['inventory_item_id','item_id','inv_item_id']) : null;

// Returns columns
$INV_RET_INV_ITEM_FK    = $INV_RET_ITEMS_TABLE ? first_existing_column($pdo, $INV_RET_ITEMS_TABLE, ['invoice_item_id']) : null;
$INV_RET_BOXES_DEC      = $INV_RET_ITEMS_TABLE ? first_existing_column($pdo, $INV_RET_ITEMS_TABLE, ['boxes_decimal','qty_boxes','boxes']) : null;

// Misc item columns (units)
$INV_MISC_QTY_UNITS     = $INV_MISC_TABLE ? first_existing_column($pdo, $INV_MISC_TABLE, ['qty_units','qty']) : null;
$INV_MISC_RATE_UNIT     = $INV_MISC_TABLE ? first_existing_column($pdo, $INV_MISC_TABLE, ['rate_per_unit','rate','price']) : null;
$INV_MISC_COST_AT_SALE  = $INV_MISC_TABLE ? first_existing_column($pdo, $INV_MISC_TABLE, ['cost_per_unit_at_sale','cost_at_sale']) : null;

// Inventory cost fallback
$INV_PURCHASE_BOX       = $INV_TABLE ? first_existing_column($pdo, $INV_TABLE, ['purchase_box_value','purchase_value_box','purchase_per_box']) : null;
$INV_TRANSPORT_PCT      = $INV_TABLE ? first_existing_column($pdo, $INV_TABLE, ['transport_pct','transport_percent']) : null;
$INV_TRANSPORT_PER_BOX  = $INV_TABLE ? first_existing_column($pdo, $INV_TABLE, ['transport_per_box','transport_box']) : null;

// Basic guardrails
if (!$INVOICE_DATE_COL) {
  die("<div class='alert alert-danger m-3'>Could not detect invoice date column. Map it in this file.</div>");
}

// Utilities
function parse_date_or_default($s, $defaultDays=1): array {
  $tz = new DateTimeZone(date_default_timezone_get());
  if (!$s) {
    $from = new DateTime('today', $tz);
    $to   = new DateTime('today', $tz);
  } else {
    $from = new DateTime($_GET['from'] ?? 'today', $tz);
    $to   = new DateTime($_GET['to']   ?? 'today', $tz);
  }
  return [$from->format('Y-m-d'), $to->format('Y-m-d')];
}

list($fromDate, $toDate) = parse_date_or_default($_GET['from'] ?? null, $DEFAULT_DAYS);
$onlyMine = isset($_GET['mine']) && $_GET['mine'] === '1';
$currentUser = $_SESSION['user']['username'] ?? $_SESSION['user']['name'] ?? $_SESSION['user']['mobile'] ?? null;

function invoice_ids_in_range(PDO $pdo, string $dateCol, string $from, string $to, $salesUserCol=null, $onlyMine=false, $currentUser=null): array {
  $w = "DATE($dateCol) BETWEEN :f AND :t";
  $args = [':f'=>$from, ':t'=>$to];
  if ($onlyMine && $salesUserCol && $currentUser) {
    $w .= " AND $salesUserCol = :u";
    $args[':u'] = $currentUser;
  }
  $sql = "SELECT id FROM invoices WHERE $w ORDER BY $dateCol ASC, id ASC";
  $st = $pdo->prepare($sql);
  $st->execute($args);
  return array_map(fn($r)=>(int)$r['id'], $st->fetchAll(PDO::FETCH_ASSOC));
}

$invoiceIds = invoice_ids_in_range($pdo, $INVOICE_DATE_COL, $fromDate, $toDate, $INVOICE_SALES_USER, $onlyMine, $currentUser);

// Helper to fetch single header col
function ivh(PDO $pdo, int $id, string $col, string $table='invoices'): ?float {
  $st = $pdo->prepare("SELECT $col FROM $table WHERE id=?");
  $st->execute([$id]);
  $v = $st->fetchColumn();
  return ($v === false || $v === null) ? null : (float)$v;
}
function ivhb(PDO $pdo, int $id, ?string $boolCol, string $table='invoices'): bool {
  if (!$boolCol) return false;
  $st = $pdo->prepare("SELECT $boolCol FROM $table WHERE id=?");
  $st->execute([$id]);
  $v = $st->fetchColumn();
  return (bool)$v;
}

function compute_invoice_financials(PDO $pdo, int $invoiceId, array $cfg): array {
  [
    $INVOICE_TABLE,$INVOICE_TOTAL_COL,$INVOICE_DISCOUNT_VAL,$INVOICE_DISCOUNT_ISPCT,
    $INVOICE_COMM_VAL,$INVOICE_COMM_ISPCT,$INV_ITEMS_TABLE,$INV_ITEM_QTY_BOX,$INV_ITEM_PRICE_BOX,
    $INV_ITEM_COST_AT_SALE,$INV_ITEM_INVITEM_FK,$INV_TABLE,$INV_PURCHASE_BOX,$INV_TRANSPORT_PCT,$INV_TRANSPORT_PER_BOX,
    $INV_RET_ITEMS_TABLE,$INV_RET_INV_ITEM_FK,$INV_RET_BOXES_DEC,$INV_MISC_TABLE,$INV_MISC_QTY_UNITS,$INV_MISC_RATE_UNIT,$INV_MISC_COST_AT_SALE,$TRANSPORT_MODE,$INVOICE_TOTAL_IS_NET
  ] = $cfg;

  $gross_from_items = 0.0; $cost_from_items = 0.0;

  // ---- TILES (invoice_items)
  if ($INV_ITEMS_TABLE && $INV_ITEM_QTY_BOX && $INV_ITEM_PRICE_BOX) {
    $st=$pdo->prepare("SELECT * FROM $INV_ITEMS_TABLE WHERE invoice_id = ?");
    $st->execute([$invoiceId]);
    $rows=$st->fetchAll(PDO::FETCH_ASSOC);

    foreach ($rows as $it) {
      $qty = (float)($it[$INV_ITEM_QTY_BOX] ?? 0);
      $rate= (float)($it[$INV_ITEM_PRICE_BOX] ?? 0);
      $gross_from_items += $qty * $rate;

      if ($INV_ITEM_COST_AT_SALE && isset($it[$INV_ITEM_COST_AT_SALE]) && $it[$INV_ITEM_COST_AT_SALE] !== null) {
        $cost_from_items += $qty * (float)$it[$INV_ITEM_COST_AT_SALE];
      } elseif ($INV_TABLE && $INV_PURCHASE_BOX && (($TRANSPORT_MODE==='PERCENT' && $INV_TRANSPORT_PCT) || ($TRANSPORT_MODE==='PER_BOX' && $INV_TRANSPORT_PER_BOX))) {
        if ($INV_ITEM_INVITEM_FK && isset($it[$INV_ITEM_INVITEM_FK])) {
          $invId = (int)$it[$INV_ITEM_INVITEM_FK];
          $si = $pdo->prepare("SELECT $INV_PURCHASE_BOX AS pbox, ".($TRANSPORT_MODE==='PERCENT' ? $INV_TRANSPORT_PCT : $INV_TRANSPORT_PER_BOX)." AS tval FROM $INV_TABLE WHERE id=?");
          $si->execute([$invId]);
          if ($row=$si->fetch(PDO::FETCH_ASSOC)) {
            $pbox = (float)$row['pbox']; $tval=(float)$row['tval'];
            $costBox = ($TRANSPORT_MODE==='PERCENT') ? ($pbox * (1 + $tval/100.0)) : ($pbox + $tval);
            $cost_from_items += $qty * $costBox;
          }
        }
      }
    }

    // ---- RETURNS (reduce gross & cost when we can map)
    if ($INV_RET_ITEMS_TABLE && $INV_RET_INV_ITEM_FK && $INV_RET_BOXES_DEC && $rows) {
      $ids = [];
      foreach ($rows as $r) if (isset($r['id'])) $ids[] = (int)$r['id'];
      if ($ids) {
        $in = implode(',', array_fill(0,count($ids),'?'));
        $sr=$pdo->prepare("SELECT $INV_RET_INV_ITEM_FK AS inv_item_id, $INV_RET_BOXES_DEC AS boxes FROM $INV_RET_ITEMS_TABLE WHERE $INV_RET_INV_ITEM_FK IN ($in)");
        $sr->execute($ids);
        $rets=$sr->fetchAll(PDO::FETCH_ASSOC);

        $priceByItem=[]; $costByItem=[];
        foreach ($rows as $it) {
          $iid=(int)($it['id']??0); if(!$iid) continue;
          $priceByItem[$iid]=(float)$it[$INV_ITEM_PRICE_BOX];
          if ($INV_ITEM_COST_AT_SALE && isset($it[$INV_ITEM_COST_AT_SALE]) && $it[$INV_ITEM_COST_AT_SALE] !== null) {
            $costByItem[$iid]=(float)$it[$INV_ITEM_COST_AT_SALE];
          }
        }
        foreach ($rets as $r) {
          $iid=(int)$r['inv_item_id']; $rb=(float)$r['boxes'];
          $gross_from_items -= $rb * ($priceByItem[$iid] ?? 0);
          if (isset($costByItem[$iid])) $cost_from_items -= $rb * $costByItem[$iid];
        }
      }
    }
  }

  // ---- MISC (units)
  if ($INV_MISC_TABLE && $INV_MISC_QTY_UNITS && $INV_MISC_RATE_UNIT) {
    $st=$pdo->prepare("SELECT * FROM $INV_MISC_TABLE WHERE invoice_id = ?");
    $st->execute([$invoiceId]);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $mi) {
      $qty=(float)($mi[$INV_MISC_QTY_UNITS]??0);
      $rate=(float)($mi[$INV_MISC_RATE_UNIT]??0);
      $gross_from_items += $qty * $rate;
      if ($INV_MISC_COST_AT_SALE && isset($mi[$INV_MISC_COST_AT_SALE]) && $mi[$INV_MISC_COST_AT_SALE] !== null) {
        $cost_from_items += $qty * (float)$mi[$INV_MISC_COST_AT_SALE];
      }
    }
  }

  // ---- Header values (discount, commission, total)
  $discVal = $INVOICE_DISCOUNT_VAL ? (ivh($pdo,$invoiceId,$INVOICE_DISCOUNT_VAL,$INVOICE_TABLE) ?? 0.0) : 0.0;
  $discIsPct = $INVOICE_DISCOUNT_ISPCT ? ivhb($pdo,$invoiceId,$INVOICE_DISCOUNT_ISPCT,$INVOICE_TABLE) : false;
  $commVal = $INVOICE_COMM_VAL ? (ivh($pdo,$invoiceId,$INVOICE_COMM_VAL,$INVOICE_TABLE) ?? 0.0) : 0.0;
  $commIsPct = $INVOICE_COMM_ISPCT ? ivhb($pdo,$invoiceId,$INVOICE_COMM_ISPCT,$INVOICE_TABLE) : false;

  // If we got items, compute from items; else fallback to header
  if ($gross_from_items > 0) {
    $gross = $gross_from_items;
    $discount   = $discIsPct ? ($gross * $discVal/100.0) : $discVal;
    $commission = $commIsPct ? ($gross * $commVal/100.0) : $commVal;
    $netSales = max(0.0, $gross - $discount - $commission);
    $cost     = $cost_from_items;
  } else {
    // Fallback: use invoice total
    $total = $INVOICE_TOTAL_COL ? (ivh($pdo,$invoiceId,$INVOICE_TOTAL_COL,$INVOICE_TABLE) ?? 0.0) : 0.0;
    $discount   = $discIsPct ? ($total * $discVal/100.0) : $discVal;
    $commission = $commIsPct ? ($total * $commVal/100.0) : $commVal;
    if ($INVOICE_TOTAL_IS_NET) {
      $netSales = $total;
      $gross    = $total + $discount + $commission; // reconstruct gross
    } else {
      $gross    = $total;
      $netSales = max(0.0, $gross - $discount - $commission);
    }
    $cost = 0.0; // No safe way without items; stays 0
  }

  $profit = $netSales - $cost;
  $margin = ($netSales > 0) ? ($profit / $netSales * 100.0) : 0.0;

  return compact('gross','discount','commission','netSales','cost','profit','margin');
}

function aggregate_by_day(PDO $pdo, array $invoiceIds, array $cfg, string $dateCol): array {
  if (!$invoiceIds) return [];
  $in = implode(',', array_fill(0,count($invoiceIds),'?'));
  $st = $pdo->prepare("SELECT id, DATE($dateCol) AS d FROM invoices WHERE id IN ($in) ORDER BY $dateCol ASC");
  $st->execute($invoiceIds);
  $byDate = [];
  while ($r=$st->fetch(PDO::FETCH_ASSOC)) {
    $fin = compute_invoice_financials($pdo, (int)$r['id'], $cfg);
    $d = $r['d'];
    if (!isset($byDate[$d])) $byDate[$d] = ['invoices'=>0,'gross'=>0,'discount'=>0,'commission'=>0,'netSales'=>0,'cost'=>0,'profit'=>0];
    $byDate[$d]['invoices']  += 1;
    $byDate[$d]['gross']     += $fin['gross'];
    $byDate[$d]['discount']  += $fin['discount'];
    $byDate[$d]['commission']+= $fin['commission'];
    $byDate[$d]['netSales']  += $fin['netSales'];
    $byDate[$d]['cost']      += $fin['cost'];
    $byDate[$d]['profit']    += $fin['profit'];
  }
  foreach ($byDate as $d=>&$v) {
    $v['margin'] = $v['netSales']>0 ? ($v['profit']/$v['netSales']*100.0) : 0.0;
  }
  ksort($byDate);
  return $byDate;
}

$cfg = [
  $INVOICE_TABLE,$INVOICE_TOTAL_COL,$INVOICE_DISCOUNT_VAL,$INVOICE_DISCOUNT_ISPCT,
  $INVOICE_COMM_VAL,$INVOICE_COMM_ISPCT,$INV_ITEMS_TABLE,$INV_ITEM_QTY_BOX,$INV_ITEM_PRICE_BOX,
  $INV_ITEM_COST_AT_SALE,$INV_ITEM_INVITEM_FK,$INV_TABLE,$INV_PURCHASE_BOX,$INV_TRANSPORT_PCT,$INV_TRANSPORT_PER_BOX,
  $INV_RET_ITEMS_TABLE,$INV_RET_INV_ITEM_FK,$INV_RET_BOXES_DEC,$INV_MISC_TABLE,$INV_MISC_QTY_UNITS,$INV_MISC_RATE_UNIT,$INV_MISC_COST_AT_SALE,$TRANSPORT_MODE,$INVOICE_TOTAL_IS_NET
];

$byDay = aggregate_by_day($pdo, $invoiceIds, $cfg, $INVOICE_DATE_COL);

// Totals
$T = ['invoices'=>0,'gross'=>0,'discount'=>0,'commission'=>0,'netSales'=>0,'cost'=>0,'profit'=>0,'margin'=>0];
foreach ($byDay as $d=>$v) foreach (['invoices','gross','discount','commission','netSales','cost','profit'] as $k) $T[$k]+=$v[$k];
$T['margin'] = $T['netSales']>0 ? ($T['profit']/$T['netSales']*100.0) : 0.0;

// CSV export
if (isset($_GET['export']) && $_GET['export']==='csv') {
  header('Content-Type: text/csv; charset=utf-8');
  header('Content-Disposition: attachment; filename=daily_business_'.$fromDate.'_to_'.$toDate.($onlyMine?'_mine':'').'.csv');
  $out = fopen('php://output','w');
  fputcsv($out, ['Date','Invoices','Gross','Discount','Commission','Net Sales','Cost','Profit','Margin %']);
  foreach ($byDay as $d=>$v) fputcsv($out, [$d,$v['invoices'],round($v['gross'],2),round($v['discount'],2),round($v['commission'],2),round($v['netSales'],2),round($v['cost'],2),round($v['profit'],2),round($v['margin'],2)]);
  fputcsv($out, ['TOTAL',$T['invoices'],round($T['gross'],2),round($T['discount'],2),round($T['commission'],2),round($T['netSales'],2),round($T['cost'],2),round($T['profit'],2),round($T['margin'],2)]);
  fclose($out); exit;
}

// Debug mapping
if (isset($_GET['debug']) && $_GET['debug']==='1') {
  echo "<div class='alert alert-info m-3'><pre>";
  $map = [
    'INVOICE_DATE_COL'=>$INVOICE_DATE_COL,'INVOICE_TOTAL_COL'=>$INVOICE_TOTAL_COL,
    'INVOICE_DISCOUNT_VAL'=>$INVOICE_DISCOUNT_VAL,'INVOICE_DISCOUNT_ISPCT'=>$INVOICE_DISCOUNT_ISPCT,
    'INVOICE_COMM_VAL'=>$INVOICE_COMM_VAL,'INVOICE_COMM_ISPCT'=>$INVOICE_COMM_ISPCT,
    'INV_ITEMS_TABLE'=>$INV_ITEMS_TABLE,'INV_ITEM_QTY_BOX'=>$INV_ITEM_QTY_BOX,'INV_ITEM_PRICE_BOX'=>$INV_ITEM_PRICE_BOX,'INV_ITEM_COST_AT_SALE'=>$INV_ITEM_COST_AT_SALE,'INV_ITEM_INVITEM_FK'=>$INV_ITEM_INVITEM_FK,
    'INV_MISC_TABLE'=>$INV_MISC_TABLE,'INV_MISC_QTY_UNITS'=>$INV_MISC_QTY_UNITS,'INV_MISC_RATE_UNIT'=>$INV_MISC_RATE_UNIT,'INV_MISC_COST_AT_SALE'=>$INV_MISC_COST_AT_SALE,
    'INV_RET_ITEMS_TABLE'=>$INV_RET_ITEMS_TABLE,'INV_RET_INV_ITEM_FK'=>$INV_RET_INV_ITEM_FK,'INV_RET_BOXES_DEC'=>$INV_RET_BOXES_DEC,
    'INV_TABLE'=>$INV_TABLE,'INV_PURCHASE_BOX'=>$INV_PURCHASE_BOX,'INV_TRANSPORT_PCT'=>$INV_TRANSPORT_PCT,'INV_TRANSPORT_PER_BOX'=>$INV_TRANSPORT_PER_BOX,
    'INVOICE_TOTAL_IS_NET'=>$INVOICE_TOTAL_IS_NET,'TRANSPORT_MODE'=>$TRANSPORT_MODE
  ];
  foreach ($map as $k=>$v) echo $k.": ".(is_null($v)?'NULL':$v)."\n";
  echo "</pre></div>";
}

function margin_class($m) { if ($m>20) return 'text-success'; if ($m>=15) return 'text-warning'; if ($m>=10) return 'text-orange'; if ($m>=5) return 'text-secondary'; return 'text-danger'; }
?>

<div class="card p-3 mb-3">
  <form class="row g-2 align-items-end">
    <div class="col-auto">
      <label class="form-label">From</label>
      <input type="date" name="from" class="form-control" value="<?=htmlspecialchars($fromDate)?>">
    </div>
    <div class="col-auto">
      <label class="form-label">To</label>
      <input type="date" name="to" class="form-control" value="<?=htmlspecialchars($toDate)?>">
    </div>
    <?php if ($INVOICE_SALES_USER && $currentUser): ?>
    <div class="col-auto form-check mt-4 ms-2">
      <input class="form-check-input" type="checkbox" value="1" id="mine" name="mine" <?= $onlyMine?'checked':''?>>
      <label class="form-check-label" for="mine">Only my invoices (<?=htmlspecialchars($currentUser)?>)</label>
    </div>
    <?php endif; ?>
    <div class="col-auto">
      <button class="btn btn-primary">Apply</button>
      <a class="btn btn-outline-secondary" href="?from=<?=date('Y-m-d')?>&to=<?=date('Y-m-d')?><?= $onlyMine?'&mine=1':'' ?>">Today</a>
      <a class="btn btn-outline-secondary" href="?from=<?=date('Y-m-d', strtotime('-6 days'))?>&to=<?=date('Y-m-d')?><?= $onlyMine?'&mine=1':'' ?>">Last 7 days</a>
      <a class="btn btn-outline-secondary" href="?from=<?=date('Y-m-01')?>&to=<?=date('Y-m-t')?><?= $onlyMine?'&mine=1':'' ?>">This Month</a>
      <a class="btn btn-success" href="?from=<?=htmlspecialchars($fromDate)?>&to=<?=htmlspecialchars($toDate)?><?= $onlyMine?'&mine=1':'' ?>&export=csv">Export CSV</a>
      <a class="btn btn-outline-info" href="?from=<?=htmlspecialchars($fromDate)?>&to=<?=htmlspecialchars($toDate)?><?= $onlyMine?'&mine=1':'' ?>&debug=1">Debug</a>
    </div>
  </form>
</div>

<div class="card p-3 mb-3">
  <h5 class="mb-3">Summary (<?=htmlspecialchars($fromDate)?> to <?=htmlspecialchars($toDate)?><?= $onlyMine?' • My invoices':'' ?>)</h5>
  <div class="row g-3">
    <div class="col-md-2"><div class="p-3 bg-light rounded"><div class="small text-muted">Invoices</div><div class="h5 mb-0"><?=$T['invoices']?></div></div></div>
    <div class="col-md-2"><div class="p-3 bg-light rounded"><div class="small text-muted">Gross</div><div class="h5 mb-0">₹<?=number_format($T['gross'],2)?></div></div></div>
    <div class="col-md-2"><div class="p-3 bg-light rounded"><div class="small text-muted">Discount</div><div class="h5 mb-0">₹<?=number_format($T['discount'],2)?></div></div></div>
    <div class="col-md-2"><div class="p-3 bg-light rounded"><div class="small text-muted">Commission</div><div class="h5 mb-0">₹<?=number_format($T['commission'],2)?></div></div></div>
    <div class="col-md-2"><div class="p-3 bg-light rounded"><div class="small text muted">Net Sales</div><div class="h5 mb-0">₹<?=number_format($T['netSales'],2)?></div></div></div>
    <div class="col-md-2"><div class="p-3 bg-light rounded"><div class="small text-muted">Profit</div><div class="h5 mb-0">₹<?=number_format($T['profit'],2)?></div></div></div>
  </div>
  <div class="mt-2"><span class="<?=margin_class($T['margin'])?> fw-semibold">Margin: <?=number_format($T['margin'],2)?>%</span></div>
</div>

<div class="card p-3 mb-3">
  <h5 class="mb-3">Daily Breakdown</h5>
  <div class="table-responsive">
    <table class="table table-striped align-middle">
      <thead><tr>
        <th>Date</th><th>Invoices</th>
        <th>Gross</th><th>Discount</th><th>Commission</th>
        <th>Net Sales</th><th>Cost</th><th>Profit</th><th>Margin %</th>
      </tr></thead>
      <tbody>
      <?php foreach ($byDay as $d=>$v): ?>
        <tr>
          <td><?=htmlspecialchars($d)?></td>
          <td><?=$v['invoices']?></td>
          <td>₹<?=number_format($v['gross'],2)?></td>
          <td>₹<?=number_format($v['discount'],2)?></td>
          <td>₹<?=number_format($v['commission'],2)?></td>
          <td>₹<?=number_format($v['netSales'],2)?></td>
          <td>₹<?=number_format($v['cost'],2)?></td>
          <td>₹<?=number_format($v['profit'],2)?></td>
          <td><span class="<?=margin_class($v['margin'])?>"><?=number_format($v['margin'],2)?>%</span></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
      <tfoot>
        <tr class="table-secondary fw-semibold">
          <td>TOTAL</td><td><?=$T['invoices']?></td>
          <td>₹<?=number_format($T['gross'],2)?></td>
          <td>₹<?=number_format($T['discount'],2)?></td>
          <td>₹<?=number_format($T['commission'],2)?></td>
          <td>₹<?=number_format($T['netSales'],2)?></td>
          <td>₹<?=number_format($T['cost'],2)?></td>
          <td>₹<?=number_format($T['profit'],2)?></td>
          <td><?=number_format($T['margin'],2)?>%</td>
        </tr>
      </tfoot>
    </table>
  </div>
</div>

<div class="card p-3 mb-4">
  <h5 class="mb-3">Sales vs Profit (Line Chart)</h5>
  <canvas id="salesProfitChart" height="110"></canvas>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js" crossorigin="anonymous"></script>
<script>
  const labels = <?=json_encode(array_keys($byDay))?>;
  const sales  = <?=json_encode(array_values(array_map(fn($v)=>round($v['netSales'],2), $byDay)))?>;
  const profit = <?=json_encode(array_values(array_map(fn($v)=>round($v['profit'],2), $byDay)))?>;

  const ctx = document.getElementById('salesProfitChart').getContext('2d');
  new Chart(ctx, {
    type: 'line',
    data: { labels, datasets: [
      { label: 'Net Sales (₹)', data: sales, tension: 0.25 },
      { label: 'Profit (₹)',    data: profit, tension: 0.25 },
    ]},
    options: {
      responsive: true,
      interaction: { mode: 'index', intersect: false },
      plugins: { legend: { position: 'top' } },
      scales: { x: { title: { display: true, text: 'Date' }}, y: { beginAtZero: true, title: { display: true, text: 'Amount (₹)'}}}
    }
  });
</script>
<style>.text-orange{color:#fd7e14;}</style>
