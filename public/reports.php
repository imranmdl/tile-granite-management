<?php
// public/reports.php — Universal launcher to open any report with a selected date range
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/report_range.php';
require_login();

$page_title = 'All Reports';
$pdo = Database::pdo();
$rng = compute_range();

// Handle launch submit (POST) BEFORE any output
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $target = $_POST['target'] ?? '';

  // Map report keys to files
  $map = [
    'inventory'   => 'report_inventory.php',
    'damage'      => 'damage_report.php',
    'invoice_pl'  => 'invoice_profit.php',
    'quote_pl'    => 'quotation_profit.php',
    'item_pl'     => 'item_profit.php',
    'expenses'    => 'expenses.php',
    'returns'     => 'returns.php',
  ];

  if (isset($map[$target])) {
    // Preserve range in query string
    $qs = ['range' => $rng['key']];
    if ($rng['key'] === 'custom') {
      $qs['from'] = (new DateTime($rng['from']))->format('Y-m-d');
      $qs['to']   = (new DateTime($rng['to']))->modify('-1 day')->format('Y-m-d');
    }
    $url = $map[$target] . '?' . http_build_query($qs);
    header("Location: " . $url);
    exit;
  }
}

require_once __DIR__ . '/../includes/header.php';
?>

<?php render_range_controls(); ?>

<div class="card p-3">
  <form method="post" class="row g-3">
    <div class="col-md-6">
      <label class="form-label">Choose Report</label>
      <select name="target" class="form-select" required>
        <option value="" disabled selected>Select a report…</option>
        <option value="inventory">Inventory Report</option>
        <option value="damage">Damage Report</option>
        <option value="invoice_pl">Invoice P/L Report</option>
        <option value="quote_pl">Quote P/L Report</option>
        <option value="item_pl">Item P/L Report</option>
        <option value="expenses">Expenses Report</option>
        <option value="returns">Returns Report</option>
      </select>
    </div>
    <div class="col-md-6 d-flex align-items-end">
      <button class="btn btn-primary">
        <i class="bi bi-arrow-right-circle me-1"></i>
        Open Report for <strong class="ms-1"><?= h($rng['label']) ?></strong>
      </button>
    </div>
  </form>

  <hr class="my-4">

  <div class="row g-2">
    <?php
      // helper to build quick-link buttons that keep the current range
      function report_link($file, $label, $rng) {
        $params = ['range' => $rng['key']];
        if ($rng['key'] === 'custom') {
          $params['from'] = (new DateTime($rng['from']))->format('Y-m-d');
          $params['to']   = (new DateTime($rng['to']))->modify('-1 day')->format('Y-m-d');
        }
        $href = $file . '?' . http_build_query($params);
        echo '<div class="col-auto"><a class="btn btn-outline-secondary btn-sm" href="' . h($href) . '">' . h($label) . '</a></div>';
      }

      report_link('report_inventory.php',  'Inventory',    $rng);
      report_link('damage_report.php',     'Damage',       $rng);
      report_link('invoice_profit.php',    'Invoice P/L',  $rng);
      report_link('quotation_profit.php',  'Quote P/L',    $rng);
      report_link('item_profit.php',       'Item P/L',     $rng);
      report_link('expenses.php',          'Expenses',     $rng);
      report_link('returns.php',           'Returns',      $rng);
    ?>
  </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
