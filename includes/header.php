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
<div class="nav-section-label">Menu</div>
<?php if(isManager()): ?>
<a href="manager_dashboard.php" class="nav-link <?php echo $cur==='manager_dashboard.php'?'active':'';?>">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/></svg>
    Dashboard
</a>
<a href="stock.php" class="nav-link <?php echo $cur==='stock.php'?'active':'';?>">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="5" rx="1"/><rect x="3" y="11" width="18" height="5" rx="1"/><line x1="7" y1="6.5" x2="7.01" y2="6.5"/><line x1="7" y1="13.5" x2="7.01" y2="13.5"/><path d="M3 20h18"/></svg>
    Manage Stock
</a>
<a href="receive_stock.php" class="nav-link <?php echo $cur==='receive_stock.php'?'active':'';?>">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="8 17 12 21 16 17"/><line x1="12" y1="12" x2="12" y2="21"/><path d="M20.88 18.09A5 5 0 0 0 18 9h-1.26A8 8 0 1 0 3 16.29"/></svg>
    Receive Stock
</a>
<a href="issue_stock.php" class="nav-link <?php echo $cur==='issue_stock.php'?'active':'';?>">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="16 17 12 13 8 17"/><line x1="12" y1="13" x2="12" y2="21"/><path d="M20.88 18.09A5 5 0 0 0 18 9h-1.26A8 8 0 1 0 3 16.29"/></svg>
    Issue Stock
</a>
<a href="manage_items.php" class="nav-link <?php echo $cur==='manage_items.php'?'active':'';?>">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/><polyline points="3.27 6.96 12 12.01 20.73 6.96"/><line x1="12" y1="22.08" x2="12" y2="12"/></svg>
    Manage Items
</a>
<a href="view_transactions.php" class="nav-link <?php echo $cur==='view_transactions.php'?'active':'';?>">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg>
    Transactions
</a>
<a href="reports.php" class="nav-link <?php echo $cur==='reports.php'?'active':'';?>">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/></svg>
    Reports
</a>
<a href="adjust_stock.php" class="nav-link <?php echo $cur==='adjust_stock.php'?'active':'';?>">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="3"/><path d="M19.07 4.93a10 10 0 0 1 0 14.14M4.93 4.93a10 10 0 0 0 0 14.14"/></svg>
    Adjust Stock
</a>
<a href="procurement.php" class="nav-link <?php echo $cur==='procurement.php'?'active':'';?>">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="9" cy="21" r="1"/><circle cx="20" cy="21" r="1"/><path d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6"/></svg>
    Procurements
</a>
<?php else: ?>
<a href="dashboard.php" class="nav-link <?php echo $cur==='dashboard.php'?'active':'';?>">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/></svg>
    Dashboard
</a>
<a href="stock.php" class="nav-link <?php echo $cur==='stock.php'?'active':'';?>">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="5" rx="1"/><rect x="3" y="11" width="18" height="5" rx="1"/><line x1="7" y1="6.5" x2="7.01" y2="6.5"/><line x1="7" y1="13.5" x2="7.01" y2="13.5"/><path d="M3 20h18"/></svg>
    Manage Stock
</a>
<a href="receive_stock.php" class="nav-link <?php echo $cur==='receive_stock.php'?'active':'';?>">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="8 17 12 21 16 17"/><line x1="12" y1="12" x2="12" y2="21"/><path d="M20.88 18.09A5 5 0 0 0 18 9h-1.26A8 8 0 1 0 3 16.29"/></svg>
    Receive Stock
</a>
<a href="issue_stock.php" class="nav-link <?php echo $cur==='issue_stock.php'?'active':'';?>">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="16 17 12 13 8 17"/><line x1="12" y1="13" x2="12" y2="21"/><path d="M20.88 18.09A5 5 0 0 0 18 9h-1.26A8 8 0 1 0 3 16.29"/></svg>
    Issue Stock
</a>
<a href="procurement.php" class="nav-link <?php echo $cur==='procurement.php'?'active':'';?>">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="9" cy="21" r="1"/><circle cx="20" cy="21" r="1"/><path d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6"/></svg>
    Request Procurement
</a>
<a href="category_reports.php" class="nav-link <?php echo $cur==='category_reports.php'?'active':'';?>">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/></svg>
    Reports
</a>
<?php endif; ?>
</nav>
<div class="sidebar-footer">
    <div class="user-avatar"><?php echo strtoupper(substr($_SESSION['full_name']??'U',0,1));?></div>
    <div class="user-details">
        <div class="user-name"><?php echo htmlspecialchars($_SESSION['full_name']??'User');?></div>
        <div class="user-role"><?php echo htmlspecialchars(ucfirst(str_replace('_',' ',$_SESSION['role']??'')));?></div>
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