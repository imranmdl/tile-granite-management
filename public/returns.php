<?php
// public/returns.php — Safe redirects BEFORE output + prepared SQL everywhere

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';
require_login();

$pdo = Database::pdo();

/* ------------ helpers ------------ */
if (!function_exists('safe_redirect')) {
  function safe_redirect(string $url): void {
    if (!headers_sent()) {
      header('Location: ' . $url);
      exit;
    }
    // Fallback if headers already sent
    echo '<script>window.location.href=' . json_encode($url) . ';</script>';
    echo '<noscript><meta http-equiv="refresh" content="0;url=' . htmlspecialchars($url, ENT_QUOTES) . '"></noscript>';
    exit;
  }
}

function already_returned_boxes(PDO $pdo, int $invoice_item_id): float {
  $st = $pdo->prepare("SELECT COALESCE(SUM(boxes_decimal),0) FROM invoice_return_items WHERE invoice_item_id = ?");
  $st->execute([$invoice_item_id]);
  return (float)$st->fetchColumn();
}
function already_returned_units(PDO $pdo, int $invoice_misc_item_id): float {
  $st = $pdo->prepare("SELECT COALESCE(SUM(qty_units),0) FROM invoice_return_misc_items WHERE invoice_misc_item_id = ?");
  $st->execute([$invoice_misc_item_id]);
  return (float)$st->fetchColumn();
}

/* ------------ state ------------ */
$error = '';
$done  = isset($_GET['done']) ? (int)$_GET['done'] : 0;

/* ======================================================================
   HANDLE POST FIRST (NO OUTPUT BEFORE THIS)
   ====================================================================== */

// Find invoice by number → redirect to returns.php?invoice_id=...
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['find_invoice'])) {
  $no = trim($_POST['invoice_no'] ?? '');
  $st = $pdo->prepare("SELECT id FROM invoices WHERE invoice_no = ?");
  $st->execute([$no]);
  $id = $st->fetchColumn();
  if ($id) {
    safe_redirect('returns.php?invoice_id=' . (int)$id);
  } else {
    // We'll show this error after we render the page
    $error = 'Invoice not found';
  }
}

// Create return for an invoice
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_return'], $_POST['invoice_id'])) {
  $invoice_id = (int)$_POST['invoice_id'];

  $hdr = $pdo->prepare("SELECT * FROM invoices WHERE id = ?");
  $hdr->execute([$invoice_id]);
  $h = $hdr->fetch(PDO::FETCH_ASSOC);

  if ($h) {
    $ret_no      = 'RET' . date('ymdHis');
    $ret_dt      = $_POST['return_dt'] ?? date('Y-m-d');
    $notes       = trim($_POST['notes'] ?? '');
    $gst_mode    = $h['gst_mode'] ?? 'EXCLUDE';
    $gst_percent = (float)($h['gst_percent'] ?? 18.0);

    $pdo->beginTransaction();
    try {
      // insert header
      $insHdr = $pdo->prepare("
        INSERT INTO invoice_returns (return_no, return_dt, invoice_id, customer_name, notes, gst_mode, gst_percent)
        VALUES (?,?,?,?,?,?,?)
      ");
      $insHdr->execute([$ret_no, $ret_dt, $invoice_id, $h['customer_name'], $notes, $gst_mode, $gst_percent]);
      $ret_id = (int)$pdo->lastInsertId();

      $subtotal = 0.0;

      // tiles
      if (!empty($_POST['ret_tile']) && is_array($_POST['ret_tile'])) {
        $selTile = $pdo->prepare("
          SELECT ii.*, t.id AS tid, ts.sqft_per_box AS spb
          FROM invoice_items ii
          JOIN tiles t       ON t.id = ii.tile_id
          JOIN tile_sizes ts ON ts.id = t.size_id
          WHERE ii.id = ?
        ");
        $insTile = $pdo->prepare("
          INSERT INTO invoice_return_items
            (return_id, invoice_item_id, tile_id, purpose, boxes_decimal, rate_per_box, line_total)
          VALUES (?,?,?,?,?,?,?)
        ");

        foreach ($_POST['ret_tile'] as $iid => $qty) {
          $iid = (int)$iid; $qty = (float)$qty;
          if ($iid <= 0 || $qty <= 0) continue;

          $selTile->execute([$iid]);
          $row = $selTile->fetch(PDO::FETCH_ASSOC);
          if (!$row) continue;

          $sold     = (float)$row['boxes_decimal'];
          $returned = already_returned_boxes($pdo, $iid);
          $left     = max(0.0, $sold - $returned);
          $qty      = min($qty, $left);
          if ($qty <= 0) continue;

          $rate   = (float)$row['rate_per_box'];
          $line   = $rate * $qty;

          $insTile->execute([$ret_id, $iid, (int)$row['tile_id'], $row['purpose'], $qty, $rate, $line]);
          $subtotal += $line;
        }
      }

      // misc
      if (!empty($_POST['ret_misc']) && is_array($_POST['ret_misc'])) {
        $selMisc = $pdo->prepare("
          SELECT im.*, mi.id AS misc_id
          FROM invoice_misc_items im
          JOIN misc_items mi ON mi.id = im.misc_item_id
          WHERE im.id = ?
        ");
        $insMisc = $pdo->prepare("
          INSERT INTO invoice_return_misc_items
            (return_id, invoice_misc_item_id, misc_item_id, qty_units, rate_per_unit, line_total)
          VALUES (?,?,?,?,?,?)
        ");

        foreach ($_POST['ret_misc'] as $mid => $qty) {
          $mid = (int)$mid; $qty = (float)$qty;
          if ($mid <= 0 || $qty <= 0) continue;

          $selMisc->execute([$mid]);
          $row = $selMisc->fetch(PDO::FETCH_ASSOC);
          if (!$row) continue;

          $sold     = (float)$row['qty_units'];
          $returned = already_returned_units($pdo, $mid);
          $left     = max(0.0, $sold - $returned);
          $qty      = min($qty, $left);
          if ($qty <= 0) continue;

          $rate = (float)$row['rate_per_unit'];
          $line = $rate * $qty;

          $insMisc->execute([$ret_id, $mid, (int)$row['misc_id'], $qty, $rate, $line]);
          $subtotal += $line;
        }
      }

      $gst_amount = ($gst_mode === 'EXCLUDE') ? ($subtotal * $gst_percent / 100.0) : 0.0;
      $total      = ($gst_mode === 'EXCLUDE') ? ($subtotal + $gst_amount) : $subtotal;

      $upd = $pdo->prepare("UPDATE invoice_returns SET subtotal=?, gst_amount=?, total=? WHERE id=?");
      $upd->execute([$subtotal, $gst_amount, $total, $ret_id]);

      $pdo->commit();

      safe_redirect('returns.php?invoice_id=' . $invoice_id . '&done=1&ret=' . $ret_id);
    } catch (Throwable $e) {
      $pdo->rollBack();
      $error = 'Failed to create return: ' . $e->getMessage();
    }
  } else {
    $error = 'Invoice not found.';
  }
}

/* ======================================================================
   FETCH DATA FOR DISPLAY (safe to compute now; still no output)
   ====================================================================== */
$invoice_id = (int)($_GET['invoice_id'] ?? 0);
$h = null; $tile_rows = []; $misc_rows = []; $ret_hdrs = [];

if ($invoice_id > 0) {
  $st = $pdo->prepare("SELECT * FROM invoices WHERE id = ?");
  $st->execute([$invoice_id]);
  $h = $st->fetch(PDO::FETCH_ASSOC);

  if ($h) {
    $st = $pdo->prepare("
      SELECT ii.*, t.name AS tile_name, ts.label AS size_label
      FROM invoice_items ii
      JOIN tiles t       ON t.id = ii.tile_id
      JOIN tile_sizes ts ON ts.id = t.size_id
      WHERE ii.invoice_id = ?
      ORDER BY ii.id ASC
    ");
    $st->execute([$invoice_id]);
    $tile_rows = $st->fetchAll(PDO::FETCH_ASSOC);

    $st = $pdo->prepare("
      SELECT im.*, mi.name AS item_name, mi.unit_label
      FROM invoice_misc_items im
      JOIN misc_items mi ON mi.id = im.misc_item_id
      WHERE im.invoice_id = ?
      ORDER BY im.id ASC
    ");
    $st->execute([$invoice_id]);
    $misc_rows = $st->fetchAll(PDO::FETCH_ASSOC);

    $st = $pdo->prepare("
      SELECT *
      FROM invoice_returns
      WHERE invoice_id = ?
      ORDER BY id DESC
    ");
    $st->execute([$invoice_id]);
    $ret_hdrs = $st->fetchAll(PDO::FETCH_ASSOC);
  }
}

/* ======================================================================
   OUTPUT STARTS HERE — include header AFTER all header()/redirect logic
   ====================================================================== */
$page_title = "Returns";
require_once __DIR__ . '/../includes/header.php';
?>
<div class="card p-3 mb-3">
  <h5>Find Invoice for Return</h5>
  <?php if (!empty($error)): ?>
    <div class="alert alert-danger"><?= h($error) ?></div>
  <?php elseif ($done): ?>
    <div class="alert alert-success mb-2">Return saved successfully.</div>
  <?php endif; ?>

  <form method="post" class="row g-2">
    <div class="col-md-4">
      <input class="form-control" name="invoice_no" placeholder="Invoice No (e.g., INV240901123456)">
    </div>
    <div class="col-md-2">
      <button class="btn btn-primary" name="find_invoice">Load</button>
    </div>
  </form>
</div>

<?php if ($h): ?>
<div class="card p-3 mb-3">
  <div class="row g-2">
    <div class="col-md-3"><strong>Invoice:</strong> <?= h($h['invoice_no']) ?></div>
    <div class="col-md-3"><strong>Date:</strong> <?= h($h['invoice_dt']) ?></div>
    <div class="col-md-6"><strong>Customer:</strong> <?= h($h['customer_name']) ?></div>
  </div>
</div>

<div class="card p-3 mb-3">
  <h5>Return Items</h5>
  <form method="post">
    <input type="hidden" name="invoice_id" value="<?= (int)$invoice_id ?>">
    <div class="row g-2 mb-2">
      <div class="col-md-3">
        <label class="form-label">Return Date</label>
        <input class="form-control" type="date" name="return_dt" value="<?= h(date('Y-m-d')) ?>">
      </div>
      <div class="col-md-9">
        <label class="form-label">Notes</label>
        <input class="form-control" name="notes">
      </div>
    </div>

    <div class="table-responsive mb-2">
      <table class="table table-striped table-sm align-middle">
        <thead>
          <tr>
            <th>#</th><th>Purpose</th><th>Tile / Item</th>
            <th>Sold</th><th>Already Returned</th><th>Return Now</th><th>Rate</th>
          </tr>
        </thead>
        <tbody>
          <?php $i=1; foreach ($tile_rows as $r):
            $returned = already_returned_boxes($pdo, (int)$r['id']);
            $left = max(0.0, (float)$r['boxes_decimal'] - $returned); ?>
            <tr>
              <td><?= $i++ ?></td>
              <td><?= h($r['purpose']) ?></td>
              <td><?= h($r['tile_name']) ?> (<?= h($r['size_label']) ?>)</td>
              <td><?= n3($r['boxes_decimal']) ?> boxes</td>
              <td><?= n3($returned) ?> boxes</td>
              <td style="max-width:120px">
                <?php if ($left <= 0): ?>
                  <span class="text-muted">Fully returned</span>
                <?php else: ?>
                  <input class="form-control form-control-sm"
                         type="number" step="0.001"
                         name="ret_tile[<?= (int)$r['id'] ?>]"
                         value="0" max="<?= h(n3($left)) ?>">
                  <div class="small text-muted">Left: <?= n3($left) ?></div>
                <?php endif; ?>
              </td>
              <td>₹ <?= n2($r['rate_per_box']) ?>/box</td>
            </tr>
          <?php endforeach; ?>

          <?php foreach ($misc_rows as $r):
            $returned = already_returned_units($pdo, (int)$r['id']);
            $left = max(0.0, (float)$r['qty_units'] - $returned); ?>
            <tr>
              <td><?= $i++ ?></td>
              <td><?= h($r['purpose']) ?></td>
              <td><?= h($r['item_name']) ?> (<?= h($r['unit_label']) ?>)</td>
              <td><?= n3($r['qty_units']) ?> units</td>
              <td><?= n3($returned) ?> units</td>
              <td style="max-width:120px">
                <?php if ($left <= 0): ?>
                  <span class="text-muted">Fully returned</span>
                <?php else: ?>
                  <input class="form-control form-control-sm"
                         type="number" step="0.001"
                         name="ret_misc[<?= (int)$r['id'] ?>]"
                         value="0" max="<?= h(n3($left)) ?>">
                  <div class="small text-muted">Left: <?= n3($left) ?></div>
                <?php endif; ?>
              </td>
              <td>₹ <?= n2($r['rate_per_unit']) ?>/unit</td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <button class="btn btn-success" name="create_return">Create Return</button>
  </form>
</div>

<?php if ($ret_hdrs): ?>
<div class="card p-3">
  <h5>Past Returns for this Invoice</h5>
  <div class="table-responsive">
    <table class="table table-striped table-sm align-middle">
      <thead>
        <tr><th>Date</th><th>No</th><th>Subtotal</th><th>GST</th><th>Total Refund</th></tr>
      </thead>
      <tbody>
        <?php foreach ($ret_hdrs as $r): ?>
          <tr>
            <td><?= h($r['return_dt']) ?></td>
            <td><?= h($r['return_no']) ?></td>
            <td>₹ <?= n2($r['subtotal']) ?></td>
            <td>₹ <?= n2($r['gst_amount']) ?></td>
            <td class="fw-bold">₹ <?= n2($r['total']) ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
