<?php
$page_title="Quotation List";
require_once __DIR__ . '/../includes/header.php';
$pdo = Database::pdo();
$rows = $pdo->query("SELECT * FROM quotations ORDER BY id DESC")->fetchAll();
?>
<div class="card p-3">
  <h5>Quotations</h5>
  <div class="table-responsive"><table class="table table-striped align-middle">
    <thead><tr><th>No</th><th>Date</th><th>Customer</th><th>Total (₹)</th><th>Actions</th></tr></thead>
    <tbody><?php foreach ($rows as $r): ?>
      <tr>
        <td><?= h($r['quote_no']) ?></td>
        <td><?= h($r['quote_dt']) ?></td>
        <td><?= h($r['customer_name']) ?></td>
        <td>₹ <?= n2($r['total']) ?></td>
        <td>
          <a class="btn btn-sm btn-outline-primary" href="quotation.php?id=<?= (int)$r['id'] ?>">Open</a>
          <a class="btn btn-sm btn-primary" href="quote_profit.php?id=<?= (int)$r['id'] ?>">P/L</a>
        </td>
      </tr>
    <?php endforeach; ?></tbody>
  </table></div>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
