<?php
$page_title = "Expenses";
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';
require_login();

$pdo = Database::pdo();

// ---------- HANDLE POST BEFORE ANY HTML ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create'])) {
    $pdo->prepare("
        INSERT INTO expenses
          (exp_dt, category, payee, method, amount, notes, invoice_id, quotation_id)
        VALUES (?,?,?,?,?,?,?,?)
    ")->execute([
        $_POST['exp_dt'] ?? date('Y-m-d'),
        trim($_POST['category'] ?? ''),
        trim($_POST['payee'] ?? ''),
        $_POST['method'] ?? 'CASH',
        (float)($_POST['amount'] ?? 0),
        trim($_POST['notes'] ?? ''),
        ($_POST['invoice_id'] ?? '') !== '' ? (int)$_POST['invoice_id'] : null,
        ($_POST['quotation_id'] ?? '') !== '' ? (int)$_POST['quotation_id'] : null,
    ]);

    // redirect safely
    safe_redirect('expenses.php');
}

// ---------- FETCH DATA ----------
$rows = $pdo->query("
  SELECT e.*, i.invoice_no, q.quote_no
  FROM expenses e
  LEFT JOIN invoices i   ON i.id = e.invoice_id
  LEFT JOIN quotations q ON q.id = e.quotation_id
  ORDER BY e.exp_dt DESC, e.id DESC
")->fetchAll();

$invoices = $pdo->query("SELECT id, invoice_no FROM invoices ORDER BY id DESC")->fetchAll();
$quotes   = $pdo->query("SELECT id, quote_no FROM quotations ORDER BY id DESC")->fetchAll();

$sum = 0.0;
foreach ($rows as $r) $sum += (float)$r['amount'];

// ---------- RENDER ----------
require_once __DIR__ . '/../includes/header.php';
?>
<div class="row g-3">
  <div class="col-lg-5">
    <div class="card p-3">
      <h5>Add Expense</h5>
      <form method="post" class="row g-2">
        <div class="col-md-4">
          <label class="form-label">Date</label>
          <input class="form-control" type="date" name="exp_dt" value="<?= h(date('Y-m-d')) ?>">
        </div>
        <div class="col-md-8">
          <label class="form-label">Category</label>
          <input class="form-control" name="category" placeholder="Fuel / Wages / Rent / Misc">
        </div>
        <div class="col-md-6">
          <label class="form-label">Payee</label>
          <input class="form-control" name="payee">
        </div>
        <div class="col-md-6">
          <label class="form-label">Method</label>
          <select class="form-select" name="method">
            <option>CASH</option><option>CARD</option><option>UPI</option>
            <option>BANK</option><option>OTHER</option>
          </select>
        </div>
        <div class="col-md-6">
          <label class="form-label">Amount (₹)</label>
          <input class="form-control" type="number" step="0.01" name="amount">
        </div>
        <div class="col-md-6">
          <label class="form-label">Notes</label>
          <input class="form-control" name="notes">
        </div>
        <div class="col-md-6">
          <label class="form-label">Invoice</label>
          <select class="form-select" name="invoice_id">
            <option value="">—</option>
            <?php foreach($invoices as $iv): ?>
              <option value="<?= (int)$iv['id'] ?>"><?= h($iv['invoice_no']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-6">
          <label class="form-label">Quotation</label>
          <select class="form-select" name="quotation_id">
            <option value="">—</option>
            <?php foreach($quotes as $q): ?>
              <option value="<?= (int)$q['id'] ?>"><?= h($q['quote_no']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-12">
          <button class="btn btn-primary" name="create">Save</button>
        </div>
      </form>
    </div>
  </div>

  <div class="col-lg-7">
    <div class="card p-3">
      <h5 class="d-flex justify-content-between">
        <span>Expenses</span>
        <small class="text-muted">Total in view: ₹ <?= n2($sum) ?></small>
      </h5>
      <div class="table-responsive">
        <table class="table table-sm table-striped align-middle">
          <thead>
            <tr><th>Date</th><th>Category</th><th>Payee</th><th>Method</th><th>Amount</th><th>Linked</th><th>Notes</th></tr>
          </thead>
          <tbody>
            <?php foreach($rows as $r): ?>
              <tr>
                <td><?= h($r['exp_dt']) ?></td>
                <td><?= h($r['category']) ?></td>
                <td><?= h($r['payee']) ?></td>
                <td><?= h($r['method']) ?></td>
                <td>₹ <?= n2($r['amount']) ?></td>
                <td>
                  <?php if($r['invoice_no']): ?>INV <?= h($r['invoice_no']) ?><?php endif; ?>
                  <?php if($r['invoice_no'] && $r['quote_no']): ?> | <?php endif; ?>
                  <?php if($r['quote_no']): ?>Q <?= h($r['quote_no']) ?><?php endif; ?>
                </td>
                <td><?= h($r['notes']) ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
