<?php
require_once __DIR__ . '/../includes/auth.php';
redirectIfNotLoggedIn();
if (!isManager()) { header('Location: dashboard.php'); exit(); }

$current_month   = date('Y-m');
$pending_count   = $pdo->query("SELECT COUNT(*) FROM procurement WHERE status='pending'")->fetchColumn();
$low_stock_count = $pdo->query("SELECT COUNT(*) FROM items WHERE current_quantity <= reorder_level")->fetchColumn();
$total_items     = $pdo->query("SELECT COUNT(*) FROM items")->fetchColumn();

$report_status = $pdo->prepare("
    SELECT c.id, c.name,
           CASE WHEN r.id IS NOT NULL THEN 1 ELSE 0 END as submitted,
           r.submitted_at, r.total_value
    FROM categories c
    LEFT JOIN category_monthly_reports r ON c.id = r.category_id AND DATE_FORMAT(r.report_month, '%Y-%m') = ?
    ORDER BY c.name
");
$report_status->execute([$current_month]);
$reports_data    = $report_status->fetchAll();
$total_cats      = count($reports_data);
$submitted_count = count(array_filter($reports_data, fn($r) => $r['submitted']));

if (!empty($_SESSION['flash']))       { $success = htmlspecialchars($_SESSION['flash']);       unset($_SESSION['flash']); }
if (!empty($_SESSION['flash_error'])) { $error   = htmlspecialchars($_SESSION['flash_error']); unset($_SESSION['flash_error']); }

include __DIR__ . '/../includes/header.php';
?>

<div class="page-header">
    <h1>Manager Dashboard</h1>
    <div style="font-size:.78rem; color:var(--muted)"><?php echo date('l, d F Y'); ?></div>
</div>

<?php if (isset($success)): ?><div class="alert alert-success"><?php echo $success; ?></div><?php endif; ?>
<?php if (isset($error)):   ?><div class="alert alert-danger"><?php echo $error; ?></div><?php endif; ?>

<!-- Stats -->
<div class="dashboard">
    <div class="card">
        <div class="stat"><?php echo $total_items; ?></div>
        <div class="stat-label">Total Items</div>
    </div>
    <div class="card">
        <div class="stat" style="color:<?php echo $low_stock_count > 0 ? 'var(--danger)' : 'inherit'; ?>"><?php echo $low_stock_count; ?></div>
        <div class="stat-label">Low Stock Items</div>
    </div>
    <div class="card">
        <div class="stat" style="color:<?php echo $pending_count > 0 ? 'var(--warning)' : 'inherit'; ?>"><?php echo $pending_count; ?></div>
        <div class="stat-label">Pending Requests</div>
    </div>
    <div class="card">
        <div class="stat"><?php echo $submitted_count; ?>/<?php echo $total_cats; ?></div>
        <div class="stat-label">Reports Submitted</div>
    </div>
</div>

<!-- Navigation -->
<div style="display:grid; grid-template-columns:repeat(auto-fit,minmax(160px,1fr)); gap:12px; margin-bottom:22px;">
    <?php
    $navItems = [
        ['manage_items.php',        'Manage Items',      'Add, edit or delete items'],
        ['view_transactions.php',   'Transactions',      'All stock movements'],
        ['reports.php',             'Monthly Reports',   'View category reports'],
        ['procurement.php',         'Procurement',       'Manage requests'],
        ['adjust_stock.php',        'Adjust Stock',      'Manual stock correction'],
    ];
    foreach ($navItems as [$href, $title, $desc]):
    ?>
    <a href="<?php echo $href; ?>" style="background:var(--surface); border:1px solid var(--border); border-radius:var(--radius); padding:16px; text-decoration:none; color:var(--text); transition:border-color .15s, box-shadow .15s;" onmouseover="this.style.borderColor='var(--accent)'; this.style.boxShadow='var(--shadow)';" onmouseout="this.style.borderColor='var(--border)'; this.style.boxShadow='none';">
        <div style="font-size:.88rem; font-weight:600; margin-bottom:4px;"><?php echo $title; ?></div>
        <div style="font-size:.72rem; color:var(--muted)"><?php echo $desc; ?></div>
    </a>
    <?php endforeach; ?>
</div>

<!-- Report Submission Progress -->
<div class="card" style="margin-bottom:16px;">
    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:12px; flex-wrap:wrap; gap:8px;">
        <h3 style="margin:0">Monthly Reports &mdash; <?php echo date('F Y'); ?></h3>
        <a href="reports.php" class="btn btn-sm">View All</a>
    </div>
    <div class="progress-track">
        <div class="progress-fill" style="width:<?php echo $total_cats > 0 ? round(($submitted_count / $total_cats) * 100) : 0; ?>%"></div>
    </div>
    <div style="font-size:.74rem; color:var(--muted); margin-bottom:12px"><?php echo $submitted_count; ?> of <?php echo $total_cats; ?> categories submitted</div>
    <div style="display:flex; flex-wrap:wrap; gap:6px;">
        <?php foreach ($reports_data as $r): ?>
        <span style="padding:3px 10px; border-radius:999px; font-size:.72rem; font-weight:500; background:<?php echo $r['submitted'] ? '#f0fdf4' : '#fafafa'; ?>; color:<?php echo $r['submitted'] ? '#166534' : 'var(--muted)'; ?>; border:1px solid <?php echo $r['submitted'] ? '#bbf7d0' : 'var(--border)'; ?>">
            <?php echo htmlspecialchars($r['name']); ?>
        </span>
        <?php endforeach; ?>
    </div>
</div>

<!-- Pending Requests -->
<?php if ($pending_count > 0): ?>
<?php
$pending_requests = $pdo->query("
    SELECT p.*, u.full_name as requester
    FROM procurement p
    JOIN users u ON p.requested_by = u.id
    WHERE p.status = 'pending'
    ORDER BY p.created_at DESC
    LIMIT 5
")->fetchAll();
?>
<div class="card">
    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:14px; flex-wrap:wrap; gap:8px;">
        <h3 style="margin:0">Pending Procurement &mdash; <span style="color:var(--warning)"><?php echo $pending_count; ?> request<?php echo $pending_count != 1 ? 's' : ''; ?></span></h3>
        <a href="procurement.php" class="btn btn-sm">View All</a>
    </div>
    <div class="table-container">
        <table>
            <thead>
                <tr><th>Item</th><th>Qty</th><th>Requested By</th><th>Date</th><th></th></tr>
            </thead>
            <tbody>
                <?php foreach ($pending_requests as $req): ?>
                <tr>
                    <td style="font-weight:500"><?php echo htmlspecialchars($req['item_name']); ?></td>
                    <td><?php echo $req['quantity']; ?></td>
                    <td style="color:var(--muted)"><?php echo htmlspecialchars($req['requester']); ?></td>
                    <td style="color:var(--muted); font-size:.76rem; white-space:nowrap"><?php echo date('d/m/Y', strtotime($req['created_at'])); ?></td>
                    <td style="white-space:nowrap">
                        <form method="POST" action="procurement.php" class="inline-form">
                            <input type="hidden" name="id" value="<?php echo $req['id']; ?>">
                            <input type="hidden" name="return" value="manager_dashboard.php">
                            <button type="submit" name="decision" value="approve" class="btn btn-sm btn-success"
                                    data-confirm-title="Approve request"
                                    data-confirm="Approve <?php echo htmlspecialchars($req['quantity'] . ' × ' . $req['item_name']); ?> for <?php echo htmlspecialchars($req['requester']); ?>?"
                                    data-confirm-ok="Approve">Approve</button>
                            <button type="submit" name="decision" value="reject" class="btn btn-sm"
                                    data-confirm-title="Reject request"
                                    data-confirm="Reject the request for <?php echo htmlspecialchars($req['quantity'] . ' × ' . $req['item_name']); ?> from <?php echo htmlspecialchars($req['requester']); ?>?"
                                    data-confirm-ok="Reject" data-confirm-danger="1">Reject</button>
                        </form>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<?php include __DIR__ . '/../includes/footer.php'; ?>