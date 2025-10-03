<?php
require_once __DIR__.'/auth.php';
require_once __DIR__.'/helpers.php';
require_login();
$path = $_SERVER['PHP_SELF'] ?? '';
$PUB = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? '/public'), '/\\'); // works if app is in subfolder
function nav_active($needle, $path){ return (str_ends_with($path, '/'.$needle) ? 'active' : ''); }
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
    <a class="navbar-brand fw-bold">🧱 Royal Tiles and Granites </a>
    <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#nav"><span class="navbar-toggler-icon"></span></button>
    <div class="collapse navbar-collapse" id="nav">
      <ul class="navbar-nav me-auto">
        <li class="nav-item"><a class="nav-link <?= nav_active('index.php',$path) ?>" href="<?= h($PUB) ?>/index.php">Dashboard</a></li>
        <li class="nav-item"><a class="nav-link <?= nav_active('tiles.php',$path) ?>" href="<?= h($PUB) ?>/tiles.php">Tiles & Sizes</a></li>
        <li class="nav-item"><a class="nav-link <?= nav_active('misc_items.php',$path) ?>" href="<?= h($PUB) ?>/misc_items.php">Other Items</a></li>
        <li class="nav-item"><a class="nav-link <?= nav_active('inventory.php',$path) ?>" href="<?= h($PUB) ?>/inventory.php">Inventory</a></li>
        <li class="nav-item"><a class="nav-link <?= nav_active('quotation.php',$path) ?>" href="<?= h($PUB) ?>/quotation.php">Quotations</a></li>
        <li class="nav-item"><a class="nav-link <?= nav_active('quotation_view.php',$path) ?>" href="<?= h($PUB) ?>/quotation_view.php">Quotation List</a></li>
        <li class="nav-item"><a class="nav-link <?= nav_active('invoice.php',$path) ?>" href="<?= h($PUB) ?>/invoice.php">Invoices</a></li>
        <li class="nav-item"><a class="nav-link <?= nav_active('invoice_list.php',$path) ?>" href="<?= h($PUB) ?>/invoice_list.php">Invoice List</a></li>
        <li class="nav-item"><a class="nav-link <?= nav_active('expenses.php',$path) ?>" href="<?= h($PUB) ?>/expenses.php">Expenses</a></li>
        <li class="nav-item"><a class="nav-link <?= nav_active('my_commission.php',$path) ?>" href="<?= h($PUB) ?>/my_commission.php">My Commission</a></li>
        <?php if (auth_is_admin()): ?>
          <li class="nav-item"><a class="nav-link <?= nav_active('sales_commission.php',$path) ?>" href="<?= h($PUB) ?>/sales_commission.php">Commission Admin</a></li>
          <li class="nav-item"><a class="nav-link <?= nav_active('reports.php',$path) ?>" href="<?= h($PUB) ?>/reports.php">Reports</a></li>
          <li class="nav-item"><a class="nav-link <?= nav_active('invoice_profit.php',$path) ?>" href="<?= h($PUB) ?>/invoice_profit.php">Invoice P/L</a></li>
          <li class="nav-item"><a class="nav-link <?= nav_active('quotation_profit.php',$path) ?>" href="<?= h($PUB) ?>/quotation_profit.php">Quote P/L</a></li>
        <?php endif; ?>
      </ul>
      <div class="d-flex align-items-center gap-3">
        <span>Hi, <strong><?= h(auth_username()) ?></strong></span>
        <a class="btn btn-sm btn-outline-light" href="<?= h($PUB) ?>/logout.php">Logout</a>
      </div>
    </div>
  </div>
</nav>
<div class="container my-3">
