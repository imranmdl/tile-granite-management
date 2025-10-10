<?php
/* Defensive header: make sure totals exist before using them */
if (!isset($quote_id)) {
  $quote_id = (int)($_GET['id'] ?? $_GET['quote_id'] ?? 0);
}
/* Try to pick already-computed totals if they exist.
   This prevents "Undefined variable $total_sell/$total_cost" warnings if the include
   is accidentally placed before the totals are set. */
$__total_sell = null;
foreach (['total_sell','grand_total','sell_total','gross_total','total'] as $__n) {
  if (isset($$__n)) { $__total_sell = (float) $$__n; break; }
}
$__total_cost = null;
foreach (['total_cost','cost_total','grand_cost','cost'] as $__n) {
  if (isset($$__n)) { $__total_cost = (float) $$__n; break; }
}
if (!isset($total_sell)) $total_sell = (float) ($__total_sell ?? 0.0);
if (!isset($total_cost)) $total_cost = (float) ($__total_cost ?? 0.0);

/* ---- MAIN: Discount + Commission block ---- */
$disc_mode = 'NONE'; $disc_value = 0.0; $commission_on = 'PROFIT'; $commission_pct = 0.0; $commission_to = '';
$discount_amount = 0.0; $net_sell = $total_sell; $profit_before_discount = $total_sell - $total_cost;
$profit_after_discount = $profit_before_discount; $commission_amount = 0.0; $profit_after_commission = $profit_after_discount;

// Try to preload previously-saved values (ignore if columns don't exist yet)
try {
  $pref = $pdo->prepare("SELECT discount_mode,discount_value,discount_amount,total_before_discount,total_after_discount,profit_before_discount,profit_after_discount,commission_base,commission_pct,commission_amount,commission_to FROM quotations WHERE id=?");
  $pref->execute([$quote_id]);
  if ($row = $pref->fetch(PDO::FETCH_ASSOC)) {
    if (!empty($row['discount_mode'])) $disc_mode = $row['discount_mode'];
    if (isset($row['discount_value'])) $disc_value = (float)$row['discount_value'];
    if (!empty($row['commission_base'])) $commission_on = $row['commission_base'];
    if (isset($row['commission_pct'])) $commission_pct = (float)$row['commission_pct'];
    if (!empty($row['commission_to'])) $commission_to = $row['commission_to'];
  }
} catch (Throwable $e) { /* columns may not exist yet; ignore */ }

if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['apply_discount'])) {
  $disc_mode = $_POST['disc_mode'] ?? 'NONE';
  $disc_value = round((float)($_POST['disc_value'] ?? 0), 2);
  $commission_on = $_POST['commission_on'] ?? 'PROFIT';  // PROFIT | SALE
  $commission_pct = round((float)($_POST['commission_pct'] ?? 0), 2);
  $commission_to = trim($_POST['commission_to'] ?? '');

  // Compute discount
  if ($disc_mode === 'PCT') {
    $discount_amount = max(0, min($total_sell, $total_sell * $disc_value / 100.0));
  } elseif ($disc_mode === 'AMT') {
    $discount_amount = max(0, min($total_sell, $disc_value));
  } else {
    $discount_amount = 0.0;
  }
  $net_sell = $total_sell - $discount_amount;
  $profit_before_discount = $total_sell - $total_cost;
  $profit_after_discount = $net_sell - $total_cost;

  // Commission
  $commission_base_amt = ($commission_on === 'SALE') ? $net_sell : max(0.0, $profit_after_discount); // no commission on negative profit
  $commission_amount = round($commission_base_amt * ($commission_pct/100.0), 2);
  $profit_after_commission = $profit_after_discount - $commission_amount;

  // Persist to DB if requested
  if (isset($_POST['save'])) {
    $st = $pdo->prepare("UPDATE quotations
      SET discount_mode=?, discount_value=?, discount_amount=?, total_before_discount=?, total_after_discount=?,
          profit_before_discount=?, profit_after_discount=?, commission_base=?, commission_pct=?, commission_amount=?, commission_to=?
      WHERE id=?");
    $st->execute([
      $disc_mode, $disc_value, $discount_amount, $total_sell, $net_sell,
      $profit_before_discount, $profit_after_discount, $commission_on, $commission_pct, $commission_amount, $commission_to,
      $quote_id
    ]);
    if (function_exists('safe_redirect')) { safe_redirect('quotation_profit.php?id='.$quote_id.'&saved=1'); }
  }
}
?>

<div class="card p-3 mt-3">
  <h6 class="mb-3">Negotiation / Discount & Commission</h6>
  <form method="post" class="row g-3">
    <input type="hidden" name="apply_discount" value="1">
    <div class="col-md-3">
      <label class="form-label">Discount Mode</label>
      <select name="disc_mode" class="form-select">
        <option value="NONE" <?php echo $disc_mode==='NONE'?'selected':'';?>>None</option>
        <option value="PCT"  <?php echo $disc_mode==='PCT'?'selected':'';?>>% Percentage</option>
        <option value="AMT"  <?php echo $disc_mode==='AMT'?'selected':'';?>>₹ Amount</option>
      </select>
    </div>
    <div class="col-md-3">
      <label class="form-label">Discount Value</label>
      <input type="number" step="0.01" min="0" name="disc_value" class="form-control" value="<?php echo htmlspecialchars((string)$disc_value);?>">
    </div>

    <div class="col-md-3">
      <label class="form-label">Commission Base</label>
      <select name="commission_on" class="form-select">
        <option value="PROFIT" <?php echo $commission_on==='PROFIT'?'selected':'';?>>Profit after discount</option>
        <option value="SALE"   <?php echo $commission_on==='SALE'  ?'selected':'';?>>Net sale (after discount)</option>
      </select>
    </div>
    <div class="col-md-3">
      <label class="form-label">Commission %</label>
      <input type="number" step="0.01" min="0" name="commission_pct" class="form-control" value="<?php echo htmlspecialchars((string)$commission_pct);?>">
    </div>

    <div class="col-md-4">
      <label class="form-label">Commission To (person)</label>
      <input type="text" name="commission_to" class="form-control" placeholder="e.g., Imran / Sales A" value="<?php echo htmlspecialchars($commission_to);?>">
    </div>

    <div class="col-12 d-flex gap-2">
      <button class="btn btn-secondary" type="submit" name="apply">Preview</button>
      <button class="btn btn-primary" type="submit" name="save" value="1">Save & Store in Quotation</button>
    </div>
  </form>

  <div class="table-responsive mt-3">
    <table class="table table-sm mb-0">
      <tbody>
        <tr><th style="width:40%">Gross sale (before discount)</th><td>₹ <?php echo number_format($total_sell, 2);?></td></tr>
        <tr><th>Discount</th><td>₹ <?php echo number_format($discount_amount, 2);?> <?php if($disc_mode==='PCT') echo '(' . number_format($disc_value,2) . '%)';?></td></tr>
        <tr class="table-light"><th>Net sale (after discount)</th><td><strong>₹ <?php echo number_format($net_sell, 2);?></strong></td></tr>
        <tr><th>Total cost</th><td>₹ <?php echo number_format($total_cost, 2);?></td></tr>
        <tr><th>Profit after discount</th><td>₹ <?php echo number_format($profit_after_discount, 2);?></td></tr>
        <tr><th>Commission (<?php echo htmlspecialchars($commission_on);?> @ <?php echo number_format($commission_pct,2);?>%)</th><td>₹ <?php echo number_format($commission_amount, 2);?></td></tr>
        <tr class="table-success"><th>Profit after commission</th><td><strong>₹ <?php echo number_format($profit_after_commission, 2);?></strong></td></tr>
      </tbody>
    </table>
  </div>
</div>
