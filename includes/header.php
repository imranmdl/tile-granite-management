<?php
// includes/header.php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/helpers.php';
require_login();

$path = $_SERVER['PHP_SELF'] ?? '';
$base = rtrim(dirname($path), '/\\');

if (!function_exists('nav_active')) {
  function nav_active($needle, $path){
    return (substr($path, -strlen('/'.$needle)) === '/'.$needle) ? 'active' : '';
  }
}
if (!function_exists('nav_active_any')) {
  function nav_active_any($files, $path){
    foreach ($files as $f) {
      if (substr($path, -strlen('/'.$f)) === '/'.$f) return 'active';
    }
    return '';
  }
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= h($page_title ?? 'Tile Suite') ?></title>

  <!-- CSS -->
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">

  <style>
    :root{
      --brand-start:#8a243d;
      --brand-mid:#641f5e;
      --brand-end:#0d3b66;
      --brand-accent:#ffd166;
    }
    body{ padding-top:74px; }
    .brandbar{
      background: linear-gradient(90deg,var(--brand-start),var(--brand-mid),var(--brand-end));
      box-shadow: 0 8px 24px rgba(0,0,0,.15);
    }
    .navbar .navbar-brand{ color:#fff; }
    .navbar .nav-link{ color: rgba(255,255,255,.9) !important; letter-spacing:.2px; }
    .navbar .nav-link:hover,.navbar .nav-link:focus{ color:#fff !important; }
    .navbar .nav-link.active{ color:#fff !important; position: relative; font-weight: 700; }
    .navbar .nav-link.active::after{
      content:""; position:absolute; left:.5rem; right:.5rem; bottom:.35rem;
      height:3px; background: var(--brand-accent); border-radius:3px;
      box-shadow:0 0 14px rgba(255,209,102,.7);
    }
    .navbar .dropdown-menu{ border:0; border-radius:12px; box-shadow:0 14px 34px rgba(0,0,0,.18); overflow:hidden; }
    .navbar .dropdown-item{ padding:.55rem .85rem; }
    .footerbar{ background:#022a3f; color:#fff; }
    .badge-role{ background:rgba(255,255,255,.15); }
  </style>
</head>
<body>
<nav class="navbar navbar-expand-lg navbar-dark brandbar fixed-top">
  <div class="container-fluid">
    <a class="navbar-brand fw-bold d-flex align-items-center" href="<?= $base ?>/index.php">
      <i class="bi bi-bricks me-2"></i> Tile Suite
    </a>

    <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#nav">
      <span class="navbar-toggler-icon"></span>
    </button>

    <div class="collapse navbar-collapse" id="nav">
      <ul class="navbar-nav me-auto">
        

        <?php if (auth_is_admin()): ?>
        <li class="nav-item dropdown">
          <a class="nav-link dropdown-toggle <?= nav_active_any(['users.php','register_user.php'], $path) ?>" href="#" data-bs-toggle="dropdown">Users</a>
          <ul class="dropdown-menu">
            <li><a class="dropdown-item <?= nav_active('users.php',$path) ?>" href="<?= $base ?>/users.php">Manage Users</a></li>
            <li><a class="dropdown-item <?= nav_active('register_user.php',$path) ?>" href="<?= $base ?>/register_user.php">Register User</a></li>
          </ul>
        </li>
        <?php endif; ?>
        <?php if (auth_has_permission('settings.view') || auth_has_permission('users.view')): ?>
        <li class="nav-item dropdown">
          <a class="nav-link dropdown-toggle" href="#" data-bs-toggle="dropdown">Admin</a>
          <ul class="dropdown-menu">
            <?php if (auth_has_permission('settings.view')): ?>
            <li><a class="dropdown-item" href="<?= $base ?>/admin_control_panel.php">Control Panel</a></li>
            <?php endif; ?>
            <?php if (auth_has_permission('users.view')): ?>
            <li><a class="dropdown-item" href="<?= $base ?>/users_management.php">User Management</a></li>
            <?php endif; ?>
            <?php if (auth_has_permission('settings.view')): ?>
            <li><hr class="dropdown-divider"></li>
            <li><a class="dropdown-item" href="<?= $base ?>/system_settings.php">System Settings</a></li>
            <li><a class="dropdown-item" href="<?= $base ?>/backup_restore.php">Backup & Restore</a></li>
            <?php endif; ?>
          </ul>
        </li>
        <?php endif; ?>
    
<li class="nav-item">
          <a class="nav-link <?= nav_active('index.php',$path) ?>" href="<?= $base ?>/index.php">Dashboard</a>
        </li>

        <!-- Tiles Inventory -->
        <li class="nav-item dropdown">
          <a class="nav-link dropdown-toggle <?= nav_active_any(['tiles.php','tiles_inventory.php','tiles_purchase.php','tiles_stock.php'],$path) ?>"
             href="#" data-bs-toggle="dropdown">Tiles Inventory</a>
          <ul class="dropdown-menu">
            <li><a class="dropdown-item <?= nav_active('tiles.php',$path) ?>" href="<?= $base ?>/tiles.php">Tiles &amp; Sizes</a></li>
            <li><a class="dropdown-item <?= nav_active('tiles_inventory.php',$path) ?>" href="<?= $base ?>/tiles_inventory.php">Tiles Stock</a></li>
            <li><a class="dropdown-item <?= nav_active('tiles_purchase.php',$path) ?>" href="<?= $base ?>/tiles_purchase.php">Purchase Entry</a></li>
            <li><hr class="dropdown-divider"></li>
            <li><a class="dropdown-item <?= nav_active('inventory.php',$path) ?>" href="<?= $base ?>/inventory.php">Legacy Add Inventory</a></li>
          </ul>
        </li>

        <!-- Other Inventory -->
        <li class="nav-item dropdown">
          <a class="nav-link dropdown-toggle <?= nav_active_any(['misc_items.php','other_inventory.php','other_purchase.php'],$path) ?>"
             href="#" data-bs-toggle="dropdown">Other Inventory</a>
          <ul class="dropdown-menu">
            <li><a class="dropdown-item <?= nav_active('misc_items.php',$path) ?>" href="<?= $base ?>/misc_items.php">Other Items</a></li>
            <li><a class="dropdown-item <?= nav_active('other_inventory.php',$path) ?>" href="<?= $base ?>/other_inventory.php">Other Stock</a></li>
            <li><a class="dropdown-item <?= nav_active('other_purchase.php',$path) ?>" href="<?= $base ?>/other_purchase.php">Purchase Entry</a></li>
            <li><hr class="dropdown-divider"></li>
            <li><a class="dropdown-item <?= nav_active('inventory_manage.php',$path) ?>" href="<?= $base ?>/inventory_manage.php">Legacy Manage</a></li>
          </ul>
        </li>

        <!-- Quotation -->
        <li class="nav-item dropdown">
          <a class="nav-link dropdown-toggle <?= nav_active_any(['quotation.php','quotation_enhanced.php','quotation_view.php','quotation_list_enhanced.php'],$path) ?>"
             href="#" data-bs-toggle="dropdown">Quotation</a>
          <ul class="dropdown-menu">
            <li><a class="dropdown-item <?= nav_active('quotation_enhanced.php',$path) ?>" href="<?= $base ?>/quotation_enhanced.php">Create Enhanced Quotation</a></li>
            <li><a class="dropdown-item <?= nav_active('quotation_list_enhanced.php',$path) ?>" href="<?= $base ?>/quotation_list_enhanced.php">Enhanced Quotation List</a></li>
            <li><hr class="dropdown-divider"></li>
            <li><a class="dropdown-item <?= nav_active('quotation.php',$path) ?>" href="<?= $base ?>/quotation.php">Legacy Quotation</a></li>
            <li><a class="dropdown-item <?= nav_active('quotation_view.php',$path) ?>" href="<?= $base ?>/quotation_view.php">Legacy List</a></li>
          </ul>
        </li>

        <!-- Sales -->
        <li class="nav-item dropdown">
          <a class="nav-link dropdown-toggle <?= nav_active_any(['invoice.php','invoice_list.php','returns.php'],$path) ?>"
             href="#" data-bs-toggle="dropdown">Sales</a>
          <ul class="dropdown-menu">
            <li><a class="dropdown-item <?= nav_active('invoice.php',$path) ?>" href="<?= $base ?>/invoice.php">New Invoice</a></li>
            <li><a class="dropdown-item <?= nav_active('invoice_list.php',$path) ?>" href="<?= $base ?>/invoice_list.php">Invoice List</a></li>
            <li><a class="dropdown-item <?= nav_active('returns.php',$path) ?>" href="<?= $base ?>/returns.php">Returns</a></li>
          </ul>
        </li>
 <!-- Commission & Earnings -->
        <li class="nav-item dropdown">
          <?php
            $commissionFiles = [
              'commission_ledger.php',
              'commission_settings.php',
              'commission_override.php',
              'sales_commission.php',
              'my_commission.php',
              'commission_recompute.php'
            ];
          ?>
          <a class="nav-link dropdown-toggle <?= nav_active_any($commissionFiles, $path) ?>"
             href="#" data-bs-toggle="dropdown">Commission &amp; Earnings</a>
          <ul class="dropdown-menu">
            <?php if (auth_is_admin()): ?>
            <li><a class="dropdown-item <?= nav_active('commission_ledger.php', $path) ?>" href="<?= $base ?>/commission_ledger.php">Commission Ledger</a></li>
            <li><hr class="dropdown-divider"></li>
            <?php endif; ?>
            <li><a class="dropdown-item <?= nav_active('commission_settings.php', $path) ?>" href="<?= $base ?>/commission_settings.php">Commission Settings</a></li>
            <li><a class="dropdown-item <?= nav_active('commission_override.php', $path) ?>" href="<?= $base ?>/commission_override.php">Commission Override</a></li>
            <li><a class="dropdown-item <?= nav_active('sales_commission.php', $path) ?>" href="<?= $base ?>/sales_commission.php">Sales Commission</a></li>
            <li><a class="dropdown-item <?= nav_active('my_commission.php', $path) ?>" href="<?= $base ?>/my_commission.php">My Commission</a></li>
            <li><a class="dropdown-item <?= nav_active('commission_recompute.php', $path) ?>" href="<?= $base ?>/commission_recompute.php">Recompute Commission</a></li>
          </ul>
        </li>
        <!-- Reports -->
        <li class="nav-item dropdown">
          <a class="nav-link dropdown-toggle <?= nav_active_any(['reports_dashboard.php','report_sales.php','report_daily_summary.php','report_commission.php','item_profit.php','invoice_profit.php','quotation_profit.php','damage_report.php','expenses.php','reports.php','report_inventory.php'],$path) ?>"
             href="#" data-bs-toggle="dropdown">Reports</a>
          <ul class="dropdown-menu">
            <li><a class="dropdown-item <?= nav_active('reports_dashboard_new.php',$path) ?>" href="<?= $base ?>/reports_dashboard_new.php"><i class="bi bi-speedometer2"></i> Enhanced Reports Dashboard</a></li>
            <li><a class="dropdown-item <?= nav_active('reports_dashboard.php',$path) ?>" href="<?= $base ?>/reports_dashboard.php"><i class="bi bi-speedometer"></i> Legacy Reports Dashboard</a></li>
            <li><hr class="dropdown-divider"></li>
            <li><a class="dropdown-item <?= nav_active('report_daily_pl.php',$path) ?>" href="<?= $base ?>/report_daily_pl.php"><i class="bi bi-calendar-day"></i> Daily P&L Report</a></li>
            <li><a class="dropdown-item <?= nav_active('report_sales_enhanced.php',$path) ?>" href="<?= $base ?>/report_sales_enhanced.php"><i class="bi bi-graph-up-arrow"></i> Enhanced Sales Report</a></li>
            <li><a class="dropdown-item <?= nav_active('report_damage_enhanced.php',$path) ?>" href="<?= $base ?>/report_damage_enhanced.php"><i class="bi bi-exclamation-triangle"></i> Enhanced Damage Report</a></li>
            <li><hr class="dropdown-divider"></li>
            <li><a class="dropdown-item <?= nav_active('report_sales.php',$path) ?>" href="<?= $base ?>/report_sales.php"><i class="bi bi-graph-up"></i> Legacy Sales Report</a></li>
            <li><a class="dropdown-item <?= nav_active('report_daily_summary.php',$path) ?>" href="<?= $base ?>/report_daily_summary.php"><i class="bi bi-calendar-day"></i> Legacy Daily Summary</a></li>
            <li><a class="dropdown-item <?= nav_active('report_commission.php',$path) ?>" href="<?= $base ?>/report_commission.php"><i class="bi bi-person-check"></i> Legacy Commission Report</a></li>
            <li><hr class="dropdown-divider"></li>
            <li><a class="dropdown-item <?= nav_active('item_profit.php',$path) ?>" href="<?= $base ?>/item_profit.php">Item P/L</a></li>
            <li><a class="dropdown-item <?= nav_active('invoice_profit.php',$path) ?>" href="<?= $base ?>/invoice_profit.php">Invoice P/L</a></li>
            <li><a class="dropdown-item <?= nav_active('quotation_profit.php',$path) ?>" href="<?= $base ?>/quotation_profit.php">Quote P/L</a></li>
            <li><a class="dropdown-item <?= nav_active('damage_report.php',$path) ?>" href="<?= $base ?>/damage_report.php">Legacy Damage Report</a></li>
            <li><a class="dropdown-item <?= nav_active('expenses.php',$path) ?>" href="<?= $base ?>/expenses.php">Expenses</a></li>
            <li><a class="dropdown-item <?= nav_active('report_inventory.php',$path) ?>" href="<?= $base ?>/report_inventory.php">Inventory Report</a></li>
            <li><hr class="dropdown-divider"></li>
            <li><a class="dropdown-item <?= nav_active('reports.php',$path) ?>" href="<?= $base ?>/reports.php">All Reports</a></li>
          </ul>
        </li>
      </ul>

      <div class="d-flex align-items-center gap-3">
        <span class="badge rounded-pill text-bg-light badge-role">
          Hi, <strong><?= h(auth_username()) ?> (<?= h(auth_role()) ?>)</strong>
        </span>
        <a class="btn btn-sm btn-outline-light" href="<?= $base ?>/logout.php">
          <i class="bi bi-box-arrow-right me-1"></i> Logout
        </a>
      </div>
    </div>
  </div>
</nav>

<div class="container my-3">
