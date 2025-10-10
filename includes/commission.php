<?php
// ------------------------------------------------------------
// includes/commission.php — Cost-based commissions with overrides.
// ------------------------------------------------------------
if (!class_exists('Commission')) {
class Commission {
  public static function driver(PDO $pdo): string { return $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) ?: 'unknown'; }
  public static function table_exists(PDO $pdo, string $table): bool {
    try {
      $drv = self::driver($pdo);
      if ($drv === 'sqlite') {
        $st = $pdo->prepare("SELECT name FROM sqlite_master WHERE type='table' AND name=?"); $st->execute([$table]); return (bool)$st->fetchColumn();
      } else { $st = $pdo->prepare("SHOW TABLES LIKE ?"); $st->execute([$table]); return (bool)$st->fetchColumn(); }
    } catch (Throwable $e) { return false; }
  }
  public static function column_exists(PDO $pdo, string $table, string $col): bool {
    if (!self::table_exists($pdo, $table)) return false;
    try {
      $drv = self::driver($pdo);
      if ($drv === 'sqlite') { $st = $pdo->query("PRAGMA table_info(".$table.")"); foreach ($st ?: [] as $r) if (strcasecmp($r['name'] ?? '', $col) === 0) return true; return false; }
      else { $st = $pdo->prepare("SHOW COLUMNS FROM `$table` LIKE ?"); $st->execute([$col]); return (bool)$st->fetch(); }
    } catch (Throwable $e) { return false; }
  }
  public static function first_col(PDO $pdo, string $table, array $candidates, $default=null) {
    foreach ($candidates as $c) if (self::column_exists($pdo, $table, $c)) return $c; return $default;
  }
  public static function setting(PDO $pdo, string $key, $default=null) {
    if (!self::table_exists($pdo, 'app_settings')) return $default;
    $st = $pdo->prepare("SELECT value FROM app_settings WHERE key = ?"); $st->execute([$key]);
    $v = $st->fetchColumn(); return ($v === false || $v === null) ? $default : $v;
  }
  public static function users_table(PDO $pdo): array {
    foreach (['users','app_users','user'] as $t) if (self::table_exists($pdo,$t)) {
      $id=self::first_col($pdo,$t,['id','user_id']); $key=self::first_col($pdo,$t,['username','mobile','name','email']); return [$t,$id,$key];
    } return [null,null,null];
  }
  public static function resolve_sales_user_id(PDO $pdo, array $invoice): ?int {
    list($usersTable,$userIdCol,$userKeyCol) = self::users_table($pdo); if (!$usersTable || !$userIdCol) return null;
    foreach (['sales_user','salesperson','created_by','user_name','created_user','created_by_user'] as $c) if (!empty($invoice[$c])) {
      $v=$invoice[$c];
      if ($userKeyCol) { $st=$pdo->prepare("SELECT $userIdCol FROM $usersTable WHERE $userKeyCol=?"); $st->execute([$v]); if ($id=$st->fetchColumn()) return (int)$id; }
      foreach (['username','mobile','email','name'] as $col) {
        if (!self::column_exists($pdo,$usersTable,$col)) continue;
        $st=$pdo->prepare("SELECT $userIdCol FROM $usersTable WHERE $col=?"); $st->execute([$v]); if ($id=$st->fetchColumn()) return (int)$id;
      }
    }
    return null;
  }
  public static function get_commission_pct(PDO $pdo, ?int $invoice_id, ?int $quotation_id, ?int $user_id): float {
    if ($invoice_id && self::table_exists($pdo,'commission_rates')) { $st=$pdo->prepare("SELECT pct FROM commission_rates WHERE scope='INVOICE' AND scope_id=? AND active=1 ORDER BY id DESC LIMIT 1"); $st->execute([$invoice_id]); if ($x=$st->fetchColumn()) return (float)$x; }
    if ($quotation_id && self::table_exists($pdo,'commission_rates')) { $st=$pdo->prepare("SELECT pct FROM commission_rates WHERE scope='QUOTATION' AND scope_id=? AND active=1 ORDER BY id DESC LIMIT 1"); $st->execute([$quotation_id]); if ($x=$st->fetchColumn()) return (float)$x; }
    if ($user_id && self::table_exists($pdo,'commission_rates')) { $st=$pdo->prepare("SELECT pct FROM commission_rates WHERE scope='USER' AND user_id=? AND active=1 ORDER BY id DESC LIMIT 1"); $st->execute([$user_id]); if ($x=$st->fetchColumn()) return (float)$x; }
    if (self::table_exists($pdo,'commission_rates')) { $st=$pdo->query("SELECT pct FROM commission_rates WHERE scope='GLOBAL' AND active=1 ORDER BY id DESC LIMIT 1"); if ($x=$st->fetchColumn()) return (float)$x; }
    return (float) self::setting($pdo,'commission_default_pct', 0);
  }
  public static function compute_invoice_cost_base(PDO $pdo, int $invoice_id): float {
    $base=0.0; $INV_ITEMS=self::table_exists($pdo,'invoice_items')?'invoice_items':null; $INV_MISC=self::table_exists($pdo,'invoice_misc_items')?'invoice_misc_items':null;
    if ($INV_ITEMS) { $qty=self::first_col($pdo,$INV_ITEMS,['boxes_decimal','qty_boxes','boxes','qty']); $cost=self::first_col($pdo,$INV_ITEMS,['cost_per_box_at_sale','cost_box_at_sale']);
      if ($qty) { $st=$pdo->prepare("SELECT $qty AS q, ".($cost?$cost:"NULL")." AS c FROM $INV_ITEMS WHERE invoice_id=?"); $st->execute([$invoice_id]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) { $q=(float)($r['q']??0); $c=isset($r['c'])&&$r['c']!==null?(float)$r['c']:0.0; $base += $q*$c; } } }
    if ($INV_MISC) { $qty=self::first_col($pdo,$INV_MISC,['qty_units','qty']); $cost=self::first_col($pdo,$INV_MISC,['cost_per_unit_at_sale','cost_at_sale']);
      if ($qty) { $st=$pdo->prepare("SELECT $qty AS q, ".($cost?$cost:"NULL")." AS c FROM $INV_MISC WHERE invoice_id=?"); $st->execute([$invoice_id]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) { $q=(float)($r['q']??0); $c=isset($r['c'])&&$r['c']!==null?(float)$r['c']:0.0; $base += $q*$c; } } }
    return max(0.0, $base);
  }
  
  public static function compute_invoice_final_value(PDO $pdo, int $invoice_id): float {
    // Get the final invoice total (after discount, including GST if applicable)
    $st = $pdo->prepare("SELECT total FROM invoices WHERE id = ?");
    $st->execute([$invoice_id]);
    $total = $st->fetchColumn();
    return $total ? (float)$total : 0.0;
  }
  public static function sync_for_invoice(PDO $pdo, int $invoice_id): array {
    $st=$pdo->prepare("SELECT * FROM invoices WHERE id=?"); $st->execute([$invoice_id]); $inv=$st->fetch(PDO::FETCH_ASSOC); if(!$inv) return ['ok'=>false,'msg'=>'Invoice not found'];
    $sales_user_id=self::resolve_sales_user_id($pdo,$inv); if(!$sales_user_id) return ['ok'=>false,'msg'=>'Sales user not mapped to a valid login user'];
    
    // Use final invoice value (after discount) instead of cost base
    $base=self::compute_invoice_final_value($pdo,$invoice_id); 
    $pct=self::get_commission_pct($pdo,$invoice_id,null,$sales_user_id); 
    $amount=round($base*($pct/100.0),2);
    
    $exists=$pdo->prepare("SELECT id,status FROM commission_ledger WHERE invoice_id=?"); $exists->execute([$invoice_id]);
    if ($row=$exists->fetch(PDO::FETCH_ASSOC)) {
      if (($row['status'] ?? '')==='PAID') { $pdo->prepare("UPDATE commission_ledger SET base_amount=?, pct=?, amount=?, updated_at=CURRENT_TIMESTAMP WHERE id=?")->execute([$base,$pct,$amount,$row['id']]); }
      else { $pdo->prepare("UPDATE commission_ledger SET salesperson_user_id=?, base_amount=?, pct=?, amount=?, status='PENDING', updated_at=CURRENT_TIMESTAMP WHERE id=?")->execute([$sales_user_id,$base,$pct,$amount,$row['id']]); }
    } else {
      $pdo->prepare("INSERT INTO commission_ledger(invoice_id, salesperson_user_id, base_amount, pct, amount, status, created_at, updated_at) VALUES(?,?,?,?,?,'PENDING',CURRENT_TIMESTAMP,CURRENT_TIMESTAMP)")->execute([$invoice_id,$sales_user_id,$base,$pct,$amount]);
    }
    return ['ok'=>true,'msg'=>'Commission synced (based on final invoice value)','base'=>$base,'pct'=>$pct,'amount'=>$amount];
  }
  public static function set_status(PDO $pdo, int $id, string $status, string $reference='', string $notes=''): bool {
    if ($status==='PAID') { $st=$pdo->prepare("UPDATE commission_ledger SET status='PAID', paid_on=CURRENT_TIMESTAMP, reference=?, notes=?, updated_at=CURRENT_TIMESTAMP WHERE id=?"); return $st->execute([$reference,$notes,$id]); }
    if (in_array($status,['PENDING','APPROVED'],true)) { $st=$pdo->prepare("UPDATE commission_ledger SET status=?, updated_at=CURRENT_TIMESTAMP WHERE id=?"); return $st->execute([$status,$id]); }
    return false;
  }
  public static function recompute_range(PDO $pdo, string $fromDate, string $toDate): array {
    $dateCol = self::column_exists($pdo,'invoices','invoice_dt') ? 'invoice_dt' : (self::column_exists($pdo,'invoices','created_at') ? 'created_at' : null);
    $sql = $dateCol ? "SELECT id FROM invoices WHERE DATE($dateCol) BETWEEN ? AND ?" : "SELECT id FROM invoices";
    $st=$pdo->prepare($sql); $dateCol ? $st->execute([$fromDate,$toDate]) : $st->execute();
    $n=0; $tot=0; foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) { $res=self::sync_for_invoice($pdo,(int)$r['id']); $n += $res['ok']?1:0; $tot++; }
    return ['ok'=>true,'synced'=>$n,'total'=>$tot];
  }
}}
?>
