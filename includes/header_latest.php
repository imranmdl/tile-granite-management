<?php
require_once __DIR__.'/auth.php';
require_once __DIR__.'/helpers.php';
require_login();
$path = $_SERVER['PHP_SELF'] ?? '';
?><!doctype html><html lang="en"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= h($page_title ?? 'Tile Suite') ?></title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<style>
  .brandbar { background: linear-gradient(90deg, #c1121f, #003049); color:#fff; }
  .brandbar .nav-link { color:#fff; }
  .brandbar .nav-link.active { text-decoration: underline; font-weight:700; }
  .footerbar { background:#003049; color:#fff; }
</style>
</head><body>
<nav class="navbar navbar-expand-lg brandbar">
  <div class="container-fluid">
    <a class="navbar-brand fw-bold">🧱 Tile Suite</a>
    <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#nav"><span class="navbar-toggler-icon"></span></button>
    <div class="collapse navbar-collapse" id="nav">
      <ul class="navbar-nav me-auto">
        <li class="nav-item"><a class="nav-link <?= active('index.php',$path) ?>" href="/public/index.php">Dashboard</a></li>
        <li class="nav-item"><a class="nav-link <?= active('tiles.php',$path) ?>" href="/public/tiles.php">Tiles & Sizes</a></li>
        <li class="nav-item"><a class="nav-link <?= active('misc_items.php',$path) ?>" href="/public/misc_items.php">Other Items</a></li>
        <li class="nav-item"><a class="nav-link <?= active('inventory.php',$path) ?>" href="/public/inventory.php">Inventory</a></li>
        <li class="nav-item"><a class="nav-link <?= active('quotation.php',$path) ?>" href="/public/quotation.php">Quotations</a></li>
        <li class="nav-item"><a class="nav-link <?= active('quotation_view.php',$path) ?>" href="/public/quotation_view.php">Quotation List</a></li>
        <li class="nav-item"><a class="nav-link <?= active('invoice.php',$path) ?>" href="/public/invoice.php">Invoices</a></li>
        <li class="nav-item"><a class="nav-link <?= active('invoice_list.php',$path) ?>" href="/public/invoice_list.php">Invoice List</a></li>
        <li class="nav-item"><a class="nav-link <?= active('expenses.php',$path) ?>" href="/public/expenses.php">Expenses</a></li>
        <li class="nav-item"><a class="nav-link <?= active('my_commission.php',$path) ?>" href="/public/my_commission.php">My Commission</a></li>
        <?php if (auth_is_admin()): ?>
          <li class="nav-item"><a class="nav-link <?= active('sales_commission.php',$path) ?>" href="/public/sales_commission.php">Commission Admin</a></li>
          <li class="nav-item"><a class="nav-link <?= active('invoice_profit.php',$path) ?>" href="/public/invoice_profit.php">Invoice P/L</a></li>
          <li class="nav-item"><a class="nav-link <?= active('quotation_profit.php',$path) ?>" href="/public/quotation_profit.php">Quote P/L</a></li>
        <?php endif; ?>
      </ul>
      <div class="d-flex align-items-center gap-3">
        <span>Hi, <strong><?= h(auth_username()) ?></strong></span>
        <a class="btn btn-sm btn-outline-light" href="/public/logout.php">Logout</a>
      </div>
    </div>
  </div>
</nav>
<div class="container my-3">
