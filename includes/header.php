<?php $cur = basename($_SERVER['PHP_SELF']); ?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<?php echo csrf_meta(); ?>
<title>Inventory System · Altus Financial Services</title>
<link rel="icon" type="image/png" href="../images/favicon.png">
<link rel="stylesheet" href="../css/style.css">
</head>
<body>
<?php if(isset($_SESSION['user_id'])): ?>
<aside class="sidebar">
<div class="sidebar-logo">
    <div>
        <img src="../images/altus-logo.png" alt="Altus Financial Services Limited">
        <div class="app-name">Inventory System</div>
    </div>
</div>
<nav class="sidebar-nav">
<div class="nav-section-label">Overview</div>
<a href="dashboard.php" class="nav-link <?php echo ($cur==='dashboard.php')?'active':'';?>">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/></svg>
    Dashboard
</a>
<?php if (can('stock.view')): ?>
<a href="stock.php" class="nav-link <?php echo ($cur==='stock.php')?'active':'';?>">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="5" rx="1"/><rect x="3" y="11" width="18" height="5" rx="1"/><line x1="7" y1="6.5" x2="7.01" y2="6.5"/><line x1="7" y1="13.5" x2="7.01" y2="13.5"/><path d="M3 20h18"/></svg>
    Manage Stock
</a>
<?php endif; ?>
<?php if (can_any('stock.receive','stock.issue','requisitions.view','categories.manage')): ?><div class="nav-section-label">Stock</div><?php endif; ?>
<?php if (can('stock.receive')): ?>
<a href="receive_stock.php" class="nav-link <?php echo ($cur==='receive_stock.php')?'active':'';?>">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="8 17 12 21 16 17"/><line x1="12" y1="12" x2="12" y2="21"/><path d="M20.88 18.09A5 5 0 0 0 18 9h-1.26A8 8 0 1 0 3 16.29"/></svg>
    Receive Stock
</a>
<?php endif; ?>
<?php if (can('stock.issue')): ?>
<a href="issue_stock.php" class="nav-link <?php echo ($cur==='issue_stock.php')?'active':'';?>">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="16 17 12 13 8 17"/><line x1="12" y1="13" x2="12" y2="21"/><path d="M20.88 18.09A5 5 0 0 0 18 9h-1.26A8 8 0 1 0 3 16.29"/></svg>
    Issue Stock
</a>
<?php endif; ?>
<?php if (can('requisitions.view')): ?>
<a href="requisitions.php" class="nav-link <?php echo ($cur==='requisitions.php')?'active':'';?>">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="9" cy="21" r="1"/><circle cx="20" cy="21" r="1"/><path d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6"/></svg>
    Requisitions
</a>
<?php endif; ?>
<?php if (can('categories.manage')): ?>
<a href="categories.php" class="nav-link <?php echo ($cur==='categories.php')?'active':'';?>">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/><polyline points="3.27 6.96 12 12.01 20.73 6.96"/><line x1="12" y1="22.08" x2="12" y2="12"/></svg>
    Categories
</a>
<?php endif; ?>
<?php if (can_any('transactions.view','reports.view','reports.submit')): ?><div class="nav-section-label">Records</div><?php endif; ?>
<?php if (can('transactions.view')): ?>
<a href="view_transactions.php" class="nav-link <?php echo ($cur==='view_transactions.php')?'active':'';?>">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg>
    Transactions
</a>
<?php endif; ?>
<?php if (can_any('reports.view','reports.submit')): ?>
<a href="reports.php" class="nav-link <?php echo ($cur==='reports.php' || $cur==='submit_category_report.php' || $cur==='category_reports.php' || $cur==='view_category_reports.php')?'active':'';?>">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/></svg>
    Monthly Reports
</a>
<?php endif; ?>
<?php if (can_any('admin.org','admin.users','admin.roles')): ?>
<div class="nav-section-label">Admin</div>
<?php if (can('admin.org')): ?>
<a href="companies.php" class="nav-link <?php echo ($cur==='companies.php')?'active':'';?>">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="4" y="2" width="16" height="20" rx="1"/><path d="M9 22v-4h6v4"/><path d="M8 6h.01M12 6h.01M16 6h.01M8 10h.01M12 10h.01M16 10h.01M8 14h.01M12 14h.01M16 14h.01"/></svg>
    Companies
</a>
<?php endif; ?>
<?php if (can('admin.org')): ?>
<a href="branches.php" class="nav-link <?php echo ($cur==='branches.php')?'active':'';?>">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/></svg>
    Branches
</a>
<?php endif; ?>
<?php if (can('admin.org')): ?>
<a href="departments.php" class="nav-link <?php echo ($cur==='departments.php')?'active':'';?>">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="9" y="2" width="6" height="5" rx="1"/><rect x="2" y="17" width="6" height="5" rx="1"/><rect x="16" y="17" width="6" height="5" rx="1"/><path d="M12 7v5M5 17v-2a1 1 0 0 1 1-1h12a1 1 0 0 1 1 1v2"/></svg>
    Departments
</a>
<?php endif; ?>
<?php if (can('admin.users')): ?>
<a href="users.php" class="nav-link <?php echo ($cur==='users.php')?'active':'';?>">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
    Users
</a>
<?php endif; ?>
<?php if (can('admin.roles')): ?>
<a href="roles.php" class="nav-link <?php echo ($cur==='roles.php')?'active':'';?>">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/><polyline points="9 12 11 14 15 10"/></svg>
    Roles
</a>
<?php endif; ?>
<?php endif; ?>
</nav>
<div class="sidebar-footer">
    <div class="user-avatar"><?php echo strtoupper(substr($_SESSION['full_name']??'U',0,1));?></div>
    <div class="user-details">
        <div class="user-name"><?php echo htmlspecialchars($_SESSION['full_name']??'User');?></div>
        <div class="user-role"><?php echo !empty($_SESSION['role_name']) ? htmlspecialchars($_SESSION['role_name']) . ' · ' : ''; ?><?php echo htmlspecialchars($_SESSION['email'] ?? '');?></div>
    </div>
    <a href="logout.php" class="logout-btn" title="Log out" aria-label="Log out"
       data-confirm-title="Log out"
       data-confirm="Log out of the inventory system? Anything you haven't saved will be lost."
       data-confirm-ok="Log out">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
    </a>
</div>
</aside>
<?php endif; ?>
<main class="main-content <?php echo isset($_SESSION['user_id'])?'with-sidebar':'';?> page-<?php echo htmlspecialchars(basename($cur, '.php')); ?>">
<?php
// Breadcrumb: pages can set $breadcrumbs = [['Label', 'url.php'], ['Current page', null]] before including this file.
if (isset($_SESSION['user_id'])) {
    $crumb_map = [
        'stock.php'                  => [['Manage Stock', null]],
        'receive_stock.php'          => [['Receive Stock', null]],
        'issue_stock.php'            => [['Issue Stock', null]],
        'requisitions.php'           => [['Requisitions', null]],
        'categories.php'             => [['Categories', null]],
        'view_transactions.php'      => [['Transactions', null]],
        'reports.php'                => [['Monthly Reports', null]],
        'submit_category_report.php' => [['Monthly Reports', 'reports.php'], ['Submit report', null]],
        'category_reports.php'       => [['Monthly Reports', 'reports.php'], ['Category activity', null]],
        'view_category_reports.php'  => [['Monthly Reports', 'reports.php'], ['Category report', null]],
        'settings.php'               => [['Settings', null]],
        'users.php'                  => [['Users', null]],
        'suppliers.php'              => [['Suppliers', null]],
    ];
    $crumbs = $breadcrumbs ?? ($crumb_map[$cur] ?? []);
    echo '<nav class="breadcrumb" aria-label="You are here">';
    echo $crumbs ? '<a href="dashboard.php">Dashboard</a>' : '<span aria-current="page">Dashboard</span>';
    foreach ($crumbs as $i => [$label, $url]) {
        echo '<span class="crumb-sep" aria-hidden="true">&rsaquo;</span>';
        echo ($url && $i < count($crumbs) - 1)
            ? '<a href="' . htmlspecialchars($url) . '">' . htmlspecialchars($label) . '</a>'
            : '<span aria-current="page">' . htmlspecialchars($label) . '</span>';
    }
    echo '</nav>';
}
?>