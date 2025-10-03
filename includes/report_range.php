<?php
// includes/report_range.php
// Reusable date-range helpers + compact UI for all reports.

// ----------------------------------------------------------------------------
// Timezone (set here in case your app hasn't already)
date_default_timezone_set('Asia/Kolkata');

// ----------------------------------------------------------------------------
// Core: compute_range()
// Returns: ['from' => 'YYYY-MM-DD', 'to' => 'YYYY-MM-DD', 'label' => '...',
//           'key' => 'today|15d|1m|1y|custom', 'from_display' => 'd M Y', 'to_display' => 'd M Y']
// NOTE: 'to' is EXCLUSIVE end => use in SQL with >= :from AND < :to
if (!function_exists('compute_range')) {
  function compute_range() {
    $range = isset($_GET['range']) ? strtolower(trim($_GET['range'])) : '';
    $fromQ = isset($_GET['from']) ? trim($_GET['from']) : '';
    $toQ   = isset($_GET['to'])   ? trim($_GET['to'])   : '';

    $today = new DateTime('today');                 // 00:00 today
    $tomorrow = (clone $today)->modify('+1 day');   // exclusive end

    $from = clone $today;
    $to   = clone $tomorrow;                        // default: Today
    $label = 'Today';
    $key = 'today';

    switch ($range) {
      case 'today':
        $label = 'Today'; $key = 'today';
        break;

      case '15d':
        // Last 15 calendar days including today => start = today - 14 days, end = tomorrow
        $from = (clone $today)->modify('-14 days');
        $to   = clone $tomorrow;
        $label = 'Last 15 Days'; $key = '15d';
        break;

      case '1m':
      case 'month':
        // This calendar month (1st of month 00:00 to 1st of next month 00:00)
        $first = new DateTime('first day of this month 00:00');
        $next  = (clone $first)->modify('first day of next month 00:00');
        $from = $first; $to = $next;
        $label = 'This Month'; $key = '1m';
        break;

      case '1y':
      case 'year':
        // This calendar year
        $first = new DateTime(date('Y-01-01').' 00:00');
        $next  = new DateTime((date('Y') + 1) . '-01-01 00:00');
        $from = $first; $to = $next;
        $label = 'This Year'; $key = '1y';
        break;

      case 'custom':
      default:
        // If valid custom dates are given, honor them; else fall back to Today
        $fromOk = preg_match('/^\d{4}-\d{2}-\d{2}$/', $fromQ);
        $toOk   = preg_match('/^\d{4}-\d{2}-\d{2}$/', $toQ);
        if ($fromOk && $toOk) {
          $from = new DateTime($fromQ . ' 00:00');
          // Make "to" EXCLUSIVE by adding 1 day to the provided date
          $to   = (new DateTime($toQ . ' 00:00'))->modify('+1 day');
          $label = $from->format('d M Y') . ' – ' . (new DateTime($toQ))->format('d M Y');
          $key = 'custom';
        } else {
          $label = 'Today'; $key = 'today';
        }
        break;
    }

    return [
      'from' => $from->format('Y-m-d'),
      'to'   => $to->format('Y-m-d'),
      'label'=> $label,
      'key'  => $key,
      // pretty display bounds (inclusive display for UX)
      'from_display' => $from->format('d M Y'),
      'to_display'   => (clone $to)->modify('-1 day')->format('d M Y'),
    ];
  }
}

// ----------------------------------------------------------------------------
// SQL helpers
// Use as: WHERE {col} >= :from AND {col} < :to
if (!function_exists('range_where')) {
  function range_where($col) {
    return "$col >= :from AND $col < :to";
  }
}
if (!function_exists('bind_range')) {
  function bind_range(PDOStatement $stmt, $rangeArr = null) {
    if ($rangeArr === null) $rangeArr = compute_range();
    $stmt->bindValue(':from', $rangeArr['from']);
    $stmt->bindValue(':to',   $rangeArr['to']);
  }
}

// ----------------------------------------------------------------------------
// Title helper (optional): gives a friendly name from filename
if (!function_exists('pretty_report_name')) {
  function pretty_report_name($fallback = 'Report') {
    $map = [
      'report_inventory.php'  => 'Inventory Report',
      'invoice_profit.php'    => 'Invoice P/L Report',
      'quotation_profit.php'  => 'Quote P/L Report',
      'item_profit.php'       => 'Item P/L Report',
      'damage_report.php'     => 'Damage Report',
      'expenses.php'          => 'Expenses',
      'returns.php'           => 'Returns',
      'reports.php'           => 'All Reports',
    ];
    $file = basename(isset($_SERVER['PHP_SELF']) ? $_SERVER['PHP_SELF'] : '');
    return isset($map[$file]) ? $map[$file] : $fallback;
  }
}

// ----------------------------------------------------------------------------
// Compact Range Controls UI
// Shows quick buttons + custom date fields.
// Safe to call near the top of your report body.
if (!function_exists('render_range_controls')) {
  function render_range_controls() {
    // Allow pages to pass a custom title via global $page_title, else derive prettily.
    $page_title = isset($GLOBALS['page_title']) && $GLOBALS['page_title']
                  ? $GLOBALS['page_title']
                  : pretty_report_name('Report');

    $r   = compute_range();
    $cur = $r['key'];

    // Helper for active button style
    $btnClass = function ($val, $cur) {
      return $val === $cur ? 'btn-primary' : 'btn-outline-primary';
    };

    // Preserve non-range query params (like mode/id) when switching
    $preserve = $_GET;
    unset($preserve['range'], $preserve['from'], $preserve['to']);
    $baseQS = http_build_query($preserve);
    $qsep   = $baseQS ? '&' : '';
    $baseHref = $baseQS ? ('?'.$baseQS) : '?';
    ?>
    <div class="card p-3 mb-3">
      <div class="d-flex flex-wrap align-items-center gap-2 justify-content-between">
        <h5 class="mb-0"><?= htmlspecialchars($page_title) ?> — <span class="text-muted"><?= htmlspecialchars($r['label']) ?></span></h5>

        <div class="d-flex flex-wrap align-items-center gap-2">
          <a class="btn btn-sm <?= $btnClass('today', $cur) ?>" href="<?= $baseHref . $qsep ?>range=today">Today</a>
          <a class="btn btn-sm <?= $btnClass('15d', $cur)   ?>" href="<?= $baseHref . $qsep ?>range=15d">Last 15 days</a>
          <a class="btn btn-sm <?= $btnClass('1m', $cur)    ?>" href="<?= $baseHref . $qsep ?>range=1m">This Month</a>
          <a class="btn btn-sm <?= $btnClass('1y', $cur)    ?>" href="<?= $baseHref . $qsep ?>range=1y">This Year</a>

          <form class="d-flex align-items-center gap-2" method="get">
            <?php
              // keep preserved params in the form as hidden inputs too
              foreach ($preserve as $k => $v) {
                echo '<input type="hidden" name="'.htmlspecialchars($k).'" value="'.htmlspecialchars($v).'">';
              }
            ?>
            <input type="hidden" name="range" value="custom">
            <input type="date" name="from" class="form-control form-control-sm" value="<?= htmlspecialchars((new DateTime($r['from']))->format('Y-m-d')) ?>">
            <span class="text-muted small">to</span>
            <input type="date" name="to" class="form-control form-control-sm" value="<?= htmlspecialchars((new DateTime($r['to']))->modify('-1 day')->format('Y-m-d')) ?>">
            <button class="btn btn-sm btn-secondary">Go</button>
          </form>
        </div>
      </div>
      <div class="small text-muted mt-2">
        Showing: <strong><?= htmlspecialchars($r['from_display']) ?> → <?= htmlspecialchars($r['to_display']) ?></strong>
      </div>
    </div>
    <?php
  }
}
