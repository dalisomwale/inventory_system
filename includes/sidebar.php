<div class="sidebar">
    <div class="sidebar-logo">
        <div class="logo-text">Proflight</div>
        <div class="logo-sub">ZAMBIA</div>
    </div>
    <nav class="sidebar-nav">
        <div class="nav-section-label">Service Center</div>
        <a href="dashboard.php" class="nav-link <?php echo $cur==='dashboard.php'?'active':'';?>">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <rect x="3" y="3" width="7" height="7" rx="1"/>
                <rect x="14" y="3" width="7" height="7" rx="1"/>
                <rect x="3" y="14" width="7" height="7" rx="1"/>
                <rect x="14" y="14" width="7" height="7" rx="1"/>
            </svg>
            <span>Dashboard</span>
        </a>
        <?php if (isManager()): ?>
        <a href="manage_items.php" class="nav-link <?php echo $cur==='manage_items.php'?'active':'';?>">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/>
                <polyline points="3.27 6.96 12 12.01 20.73 6.96"/>
                <line x1="12" y1="22.08" x2="12" y2="12"/>
            </svg>
            <span>Manage Inventory</span>
        </a>
        <a href="procurement.php" class="nav-link <?php echo $cur==='procurement.php'?'active':'';?>">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <path d="M6 2L3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4z"/>
                <line x1="3" y1="6" x2="21" y2="6"/>
                <path d="M16 10a4 4 0 0 1-8 0"/>
            </svg>
            <span>Manage Procurements</span>
        </a>
        <a href="reports.php" class="nav-link <?php echo $cur==='reports.php'?'active':'';?>">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <line x1="18" y1="20" x2="18" y2="10"/>
                <line x1="12" y1="20" x2="12" y2="4"/>
                <line x1="6" y1="20" x2="6" y2="14"/>
            </svg>
            <span>View Reports</span>
        </a>
        <a href="suppliers.php" class="nav-link <?php echo $cur==='suppliers.php'?'active':'';?>">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/>
                <circle cx="12" cy="7" r="4"/>
            </svg>
            <span>Suppliers</span>
        </a>
        <?php else: ?>
        <a href="inventory.php" class="nav-link <?php echo $cur==='inventory.php'?'active':'';?>">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/>
                <polyline points="3.27 6.96 12 12.01 20.73 6.96"/>
                <line x1="12" y1="22.08" x2="12" y2="12"/>
            </svg>
            <span>View Inventory</span>
        </a>
        <a href="receive_stock.php" class="nav-link <?php echo $cur==='receive_stock.php'?'active':'';?>">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/>
                <polyline points="3.27 6.96 12 12.01 20.73 6.96"/>
                <line x1="12" y1="22.08" x2="12" y2="12"/>
            </svg>
            <span>Receive Stock</span>
        </a>
        <a href="issue_stock.php" class="nav-link <?php echo $cur==='issue_stock.php'?'active':'';?>">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/>
                <polyline points="3 6 12 13 21 6"/>
                <line x1="12" y1="22" x2="12" y2="13"/>
            </svg>
            <span>Issue Out Items</span>
        </a>

        <a href="procurement.php" class="nav-link <?php echo $cur==='procurement.php'?'active':'';?>">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <path d="M6 2L3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4z"/>
                <line x1="3" y1="6" x2="21" y2="6"/>
                <path d="M16 10a4 4 0 0 1-8 0"/>
            </svg>
            <span>Request Procurements</span>
        </a>
        <a href="reports.php" class="nav-link <?php echo $cur==='reports.php'?'active':'';?>">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <line x1="18" y1="20" x2="18" y2="10"/>
                <line x1="12" y1="20" x2="12" y2="4"/>
                <line x1="6" y1="20" x2="6" y2="14"/>
            </svg>
            <span>Submit Reports</span>
        </a>
        <?php endif; ?>
    </nav>
    <div class="sidebar-footer">
        <div class="user-avatar"><?php echo strtoupper(substr($_SESSION['full_name']??'U',0,1));?></div>
        <div class="user-details">
            <div class="user-name"><?php echo htmlspecialchars($_SESSION['full_name']??'User');?></div>
            <div class="user-role"><?php echo htmlspecialchars(ucfirst(str_replace('_',' ',$_SESSION['role']??'')));?></div>
        </div>
        <a href="logout.php" class="logout-btn" title="Logout">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/>
                <polyline points="16 17 21 12 16 7"/>
                <line x1="21" y1="12" x2="9" y2="12"/>
            </svg>
        </a>
    </div>
</div>
