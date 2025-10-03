<?php
// public/invoice_list.php
$page_title = "Invoice List";

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/report_range.php'; // range helpers
$pdo = Database::pdo();

// ---- UI: quick range controls (Today / 15d / 1m / 1y / Custom) ----
render_range_controls();

// ---- Data: fetch invoices in selected range, with total paid (single query) ----
$sql = "
  SELECT
    i.id,
    i.invoice_no,
    i.invoice_dt,
    i.customer_name,
    i.total,
    COALESCE(p.paid, 0) AS paid
  FROM invoices i
  LEFT JOIN (
    SELECT invoice_id, SUM(amount) AS paid
    FROM invoice_payments
    GROUP BY invoice_id
  ) p ON p.invoice_id = i.id
  WHERE " . range_where('i.invoice_dt') . "
  ORDER BY i.invoice_dt DESC, i.id DESC
";
$st = $pdo->prepare($sql);
bind_range($st);           // binds :from and :to based on ?range or ?from/&?to
$st->execute();
$rows = $st->fetchAll(PDO::FETCH_ASSOC);
?>

<div class="card p-3">
  <h5 class="mb-3">Invoices</h5>

  <div class="table-responsive">
    <table class="table table-striped align-middle">
      <thead>
        <tr>
          <th>No</th>
          <th>Date</th>
          <th>Customer</th>
          <th class="text-end">Total</th>
          <th class="text-end">Paid</th>
          <th class="text-end">Balance</th>
          <th>Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($rows as $r): 
          $paid = (float)$r['paid'];
          $bal  = max(0.0, (float)$r['total'] - $paid);
        ?>
          <tr>
            <td><?= h($r['invoice_no']) ?></td>
            <td><?= h($r['invoice_dt']) ?></td>
            <td><?= h($r['customer_name']) ?></td>
            <td class="text-end">₹ <?= n2($r['total']) ?></td>
            <td class="text-end">₹ <?= n2($paid) ?></td>
            <td class="text-end">₹ <?= n2($bal) ?></td>
            <td>
              <a class="btn btn-sm btn-outline-primary" href="invoice.php?id=<?= (int)$r['id'] ?>">Open</a>
              <a class="btn btn-sm btn-outline-secondary" href="invoice_profit.php?id=<?= (int)$r['id'] ?>">P/L</a>
            </td>
          </tr>
        <?php endforeach; ?>

        <?php if (empty($rows)): ?>
          <tr><td colspan="7" class="text-center text-muted">No invoices for the selected range.</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
