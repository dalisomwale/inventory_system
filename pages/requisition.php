<?php
// One requisition: where it is, its quotations and proof of payment, the next action, and its history.
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/requisition_flow.php';
require_once __DIR__ . '/../includes/insights.php';
redirectIfNotLoggedIn();
if (!req_can_open()) requirePermission('requisitions.raise');

$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
function load_req(PDO $pdo, int $id): ?array {
    $st = $pdo->prepare("SELECT p.*, u.full_name AS raised_by_name, c.name AS category_name, d.name AS dept_name, b.name AS branch_name, co.name AS company_name
                         FROM procurement p
                         LEFT JOIN companies co ON co.id = p.company_id
                         LEFT JOIN users u ON u.id = p.requested_by
                         LEFT JOIN categories c ON c.id = p.category_id
                         LEFT JOIN departments d ON d.id = p.department_id
                         LEFT JOIN branches b ON b.id = p.branch_id
                         WHERE p.id = ? AND " . req_visible_sql($pdo));
    $st->execute([$id]);
    return $st->fetch() ?: null;
}
$p = load_req($pdo, $id);
if (!$p) {
    $_SESSION['flash_error'] = "That requisition doesn't exist, or you don't have access to it.";
    header('Location: requisitions'); exit();
}
$back = 'requisition?id=' . $p['id'];
$ref  = req_ref((int)$p['id']);
$dept_side = req_is_department($pdo, $p);

function req_quotes(PDO $pdo, int $id, string $kind = 'quote'): array {
    $st = $pdo->prepare("SELECT f.*, u.full_name AS by_name FROM requisition_files f JOIN users u ON u.id = f.uploaded_by
                         WHERE f.requisition_id = ? AND f.kind = ? ORDER BY f.created_at");
    $st->execute([$id, $kind]);
    return $st->fetchAll();
}

// Who may add or remove quotations right now
$can_quote = ($p['status'] === 'quotes_it' && req_actor($pdo, 'requisitions.it_quotes', $p))
          || (in_array($p['status'], ['quotes_dept', 'returned'], true) && $dept_side);
$can_cancel = in_array($p['status'], ['quotes_it', 'quotes_dept', 'returned', 'director'], true)
          && ($dept_side || isAdmin());

// ── Actions ─────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $act  = (string)($_POST['act'] ?? '');
    $note = trim(mb_substr((string)($_POST['note'] ?? ''), 0, 1000));
    $err  = null; $msg = null;
    $quotes = req_quotes($pdo, (int)$p['id']);
    $need_reason = fn() => mb_strlen($note) >= 3 ? null : 'Please give a reason (at least 3 characters).';

    switch ($act) {
        case 'add_quote':
            if (!$can_quote) { $err = "You can't add quotations at this stage."; break; }
            $v = new Validator($_POST);
            $supplier = $v->text('supplier', 'Supplier', 200, true, 2);
            $amount   = $v->money('amount', 'Amount', 0.01, 1000000000);
            if (!$v->ok()) { $err = $v->message(); break; }
            if (count($quotes) >= 10) { $err = 'A requisition can have up to 10 quotations.'; break; }
            $file = req_store_upload($_FILES['file'] ?? null);
            if (is_string($file)) { $err = $file; break; }
            [$stored, $orig, $mime, $size] = $file;
            $pdo->prepare("INSERT INTO requisition_files (requisition_id, kind, supplier, amount, original_name, stored_name, mime, size, uploaded_by) VALUES (?,?,?,?,?,?,?,?,?)")
                ->execute([$p['id'], 'quote', $supplier, $amount, $orig, $stored, $mime, $size, $_SESSION['user_id']]);
            req_log($pdo, (int)$p['id'], 'quote_added', null, null, "$supplier, " . kwacha((float)$amount));
            $msg = "Quotation from $supplier attached.";
            break;

        case 'remove_quote':
            if (!$can_quote) { $err = "You can't remove quotations at this stage."; break; }
            $fid = (int)($_POST['file_id'] ?? 0);
            $f = array_values(array_filter($quotes, fn($q) => (int)$q['id'] === $fid))[0] ?? null;
            if (!$f) { $err = 'That quotation is no longer attached.'; break; }
            $pdo->prepare("DELETE FROM requisition_files WHERE id = ?")->execute([$fid]);
            @unlink(req_storage_dir() . '/' . basename($f['stored_name']));
            req_log($pdo, (int)$p['id'], 'quote_removed', null, null, $f['supplier']);
            $msg = "Quotation from {$f['supplier']} removed.";
            break;

        case 'to_director':
            if (!$can_quote) { $err = "It isn't your turn on this requisition."; break; }
            if (!$quotes) { $err = 'Attach at least one quotation before sending it to the Director.'; break; }
            $msg = req_move($pdo, $p, 'director', $p['status'] === 'returned' ? 'resubmitted' : 'to_director', $note ?: null)
                 ? "$ref sent to the Director for approval." : null;
            break;

        case 'back_to_it':
            if ($p['status'] !== 'returned' || !$dept_side || !$p['is_it']) { $err = "That isn't possible at this stage."; break; }
            $msg = req_move($pdo, $p, 'quotes_it', 'back_to_it', $note ?: null) ? "$ref sent back to IT for new quotations." : null;
            break;

        case 'approve':
            if ($p['status'] !== 'director' || !req_actor($pdo, 'requisitions.approve', $p)) { $err = "Only the Director can approve this, while it is waiting for approval."; break; }
            $qid = (int)($_POST['quote_id'] ?? 0);
            $q = array_values(array_filter($quotes, fn($x) => (int)$x['id'] === $qid))[0] ?? null;
            if (!$q) { $err = 'Choose the quotation you are approving.'; break; }
            $msg = req_move($pdo, $p, 'accounts', 'approved', trim("Quotation: {$q['supplier']}, " . kwacha((float)$q['amount']) . ($note ? ". $note" : '')),
                            ['quote_id' => $q['id'], 'supplier' => $q['supplier'], 'director_at' => 'NOW()', 'approved_by' => $_SESSION['user_id']])
                 ? "$ref approved and sent to Accounts." : null;
            break;

        case 'reject':
            if ($p['status'] !== 'director' || !req_actor($pdo, 'requisitions.approve', $p)) { $err = "Only the Director can reject this, while it is waiting for approval."; break; }
            if ($e = $need_reason()) { $err = $e; break; }
            $msg = req_move($pdo, $p, 'returned', 'rejected', $note) ? "$ref rejected and returned to the department." : null;
            break;

        case 'to_am':
            if ($p['status'] !== 'accounts' || !req_actor($pdo, 'requisitions.accounts', $p)) { $err = "It isn't your turn on this requisition."; break; }
            $msg = req_move($pdo, $p, 'accounts_manager', 'to_am', $note ?: null) ? "$ref sent to the Accounts Manager." : null;
            break;

        case 'am_approve':
        case 'am_reject':
            if ($p['status'] !== 'accounts_manager' || !req_actor($pdo, 'requisitions.accounts_review', $p)) { $err = "It isn't your turn on this requisition."; break; }
            if ($act === 'am_reject' && ($e = $need_reason())) { $err = $e; break; }
            $msg = $act === 'am_approve'
                ? (req_move($pdo, $p, 'cfo', 'am_approved', $note ?: null) ? "$ref sent to the CFO for final approval." : null)
                : (req_move($pdo, $p, 'accounts', 'am_rejected', $note) ? "$ref sent back to Accounts." : null);
            break;

        case 'cfo_approve':
        case 'cfo_reject':
            if ($p['status'] !== 'cfo' || !req_actor($pdo, 'requisitions.cfo', $p)) { $err = "It isn't your turn on this requisition."; break; }
            if ($act === 'cfo_reject' && ($e = $need_reason())) { $err = $e; break; }
            $msg = $act === 'cfo_approve'
                ? (req_move($pdo, $p, 'pay', 'cfo_approved', $note ?: null) ? "$ref approved for payment. Accounts can now pay and attach the proof of payment." : null)
                : (req_move($pdo, $p, 'accounts_manager', 'cfo_rejected', $note) ? "$ref sent back to the Accounts Manager." : null);
            break;

        case 'pop':
            if ($p['status'] !== 'pay' || !req_actor($pdo, 'requisitions.accounts', $p)) { $err = "The proof of payment is attached by Accounts after the CFO approves."; break; }
            $file = req_store_upload($_FILES['file'] ?? null);
            if (is_string($file)) { $err = $file; break; }
            [$stored, $orig, $mime, $size] = $file;
            $pdo->prepare("INSERT INTO requisition_files (requisition_id, kind, original_name, stored_name, mime, size, uploaded_by) VALUES (?,?,?,?,?,?,?)")
                ->execute([$p['id'], 'pop', $orig, $stored, $mime, $size, $_SESSION['user_id']]);
            if (!req_move($pdo, $p, 'paid', 'pop_sent', $note ?: null, ['paid_at' => 'NOW()', 'ordered_at' => 'NOW()'])) {
                $pdo->prepare("DELETE FROM requisition_files WHERE stored_name = ?")->execute([$stored]);
                @unlink(req_storage_dir() . '/' . $stored);
                break;
            }
            $msg = "Proof of payment sent to {$p['raised_by_name']}. Stores can receive the items when they arrive.";
            break;

        case 'cancel':
            if (!$can_cancel) { $err = "It can only be cancelled by the department, before the Director approves it."; break; }
            if ($e = $need_reason()) { $err = $e; break; }
            $msg = req_move($pdo, $p, 'cancelled', 'cancelled', $note) ? "$ref cancelled." : null;
            break;

        default:
            $err = 'Unknown action.';
    }
    if ($err)       $_SESSION['flash_error'] = $err;
    elseif ($msg)   { $_SESSION['flash'] = $msg; logAudit($pdo, $_SESSION['user_id'], 'REQUISITION_' . strtoupper($act), $p['item_id'] ? (int)$p['item_id'] : null, $p['quantity'], "$ref: $msg"); }
    elseif (!$err && $act !== 'add_quote' && $act !== 'remove_quote') $_SESSION['flash_error'] = "Someone else has just moved $ref on. Here is where it is now.";
    header('Location: ' . $back); exit();
}

if (!empty($_SESSION['flash']))       { $success = htmlspecialchars($_SESSION['flash']);       unset($_SESSION['flash']); }
if (!empty($_SESSION['flash_error'])) { $error   = htmlspecialchars($_SESSION['flash_error']); unset($_SESSION['flash_error']); }

$quotes = req_quotes($pdo, (int)$p['id']);
$pops   = req_quotes($pdo, (int)$p['id'], 'pop');
$docs   = req_quotes($pdo, (int)$p['id'], 'doc');
$log    = $pdo->prepare("SELECT l.*, u.full_name, r.name AS role_name FROM requisition_log l JOIN users u ON u.id = l.user_id LEFT JOIN roles r ON r.id = u.role_id
                         WHERE l.requisition_id = ? ORDER BY l.created_at, l.id");
$log->execute([$p['id']]);
$log = $log->fetchAll();
$approved_quote = null;
foreach ($quotes as $q) if ((int)$q['id'] === (int)$p['quote_id']) $approved_quote = $q;

$cur_step = req_step($p['status']);
$step_keys = array_keys(REQ_STEPS);
$cur_idx = array_search($cur_step, $step_keys, true);
if ($p['status'] === 'received') $cur_idx = count($step_keys);   // all done

$breadcrumbs = [['Requisitions', 'requisitions'], [$ref, null]];
include __DIR__ . '/../includes/header.php';

function action_form(string $act, string $label, array $o = []): string {
    global $p;
    $h = '<form method="POST" class="req-action' . (!empty($o['inline']) ? ' inline' : '') . '"' . (!empty($o['upload']) ? ' enctype="multipart/form-data"' : '')
       . ' data-confirm-title="' . htmlspecialchars($o['title'] ?? $label) . '" data-confirm="' . htmlspecialchars($o['confirm'] ?? $label . '?') . '"'
       . ' data-confirm-ok="' . htmlspecialchars($label) . '"' . (!empty($o['danger']) ? ' data-confirm-danger="1"' : '') . '>'
       . '<input type="hidden" name="id" value="' . (int)$p['id'] . '"><input type="hidden" name="act" value="' . $act . '">'
       . ($o['body'] ?? '');
    if (isset($o['note'])) {
        $req = !empty($o['note_required']);
        $h .= '<div class="form-group"><label for="note_' . $act . '">' . htmlspecialchars($o['note']) . ($req ? ' *' : '') . '</label>'
            . '<textarea id="note_' . $act . '" name="note" rows="2" maxlength="1000"' . ($req ? ' required minlength="3"' : '') . ' placeholder="' . htmlspecialchars($o['placeholder'] ?? '') . '"></textarea></div>';
    }
    $h .= '<button type="submit" class="btn ' . ($o['class'] ?? 'btn-primary') . '">' . htmlspecialchars($label) . '</button></form>';
    return $h;
}
?>

<div class="page-header">
    <div>
        <h1><?php echo $ref; ?> &middot; <?php echo htmlspecialchars($p['item_name']); ?></h1>
        <div class="req-sub"><span class="<?php echo req_status_class($p['status']); ?>"><?php echo req_status_label($p['status']); ?></span>
            <?php if (!in_array($p['status'], ['received', 'cancelled'], true)): ?><span class="muted-text">since <?php echo date('d M Y, H:i', strtotime($p['stage_at'] ?? $p['created_at'])); ?></span><?php endif; ?></div>
    </div>
    <a href="requisitions" class="btn">All requisitions</a>
</div>

<?php if (isset($success)): ?><div class="alert alert-success"><?php echo $success; ?></div><?php endif; ?>
<?php if (isset($error)):   ?><div class="alert alert-danger"><?php echo $error; ?></div><?php endif; ?>

<!-- Where it is -->
<?php if ($p['status'] !== 'cancelled'): ?>
<ol class="req-steps">
    <?php foreach ($step_keys as $i => $k): ?>
    <li class="<?php echo $i < $cur_idx ? 'done' : ($i === $cur_idx ? 'current' : ''); ?>"><span class="dot"><?php echo $i < $cur_idx ? '&#10003;' : $i + 1; ?></span><span class="lbl"><?php echo REQ_STEPS[$k]; ?></span></li>
    <?php endforeach; ?>
</ol>
<?php endif; ?>

<?php if ($p['last_note'] && in_array($p['status'], ['returned', 'accounts', 'accounts_manager', 'cancelled'], true)
          && in_array(end($log)['action'] ?? '', ['rejected', 'am_rejected', 'cfo_rejected', 'cancelled'], true)): ?>
<div class="alert alert-warning"><strong><?php echo htmlspecialchars(REQ_ACTIONS[end($log)['action']]); ?></strong> by <?php echo htmlspecialchars(end($log)['full_name']); ?>: <?php echo htmlspecialchars($p['last_note']); ?></div>
<?php endif; ?>

<div class="req-layout">
<div class="req-main">

    <!-- Your action -->
    <?php
    $actions = '';
    switch ($p['status']) {
        case 'quotes_it':
        case 'quotes_dept':
        case 'returned':
            if ($can_quote) {
                $is_resubmit = $p['status'] === 'returned';
                $actions .= '<p class="muted-text">' . ($quotes ? 'When the quotations are all attached, send it to the Director.' : 'Attach at least one quotation below, then send it to the Director.') . '</p>';
                if ($quotes) $actions .= action_form('to_director', $is_resubmit ? 'Resubmit to Director' : 'Send to Director',
                    ['confirm' => "Send $ref with " . count($quotes) . ' quotation' . (count($quotes) === 1 ? '' : 's') . ' to the Director?', 'note' => 'Note for the Director', 'placeholder' => $is_resubmit ? 'What you changed' : 'Optional']);
                if ($is_resubmit && $p['is_it']) $actions .= action_form('back_to_it', 'Ask IT for new quotations', ['class' => '', 'inline' => 1, 'confirm' => "Send $ref back to IT for new quotations?"]);
            } elseif ($p['status'] === 'quotes_it') {
                $actions = '';
            }
            break;
        case 'director':
            if (req_actor($pdo, 'requisitions.approve', $p)) {
                $radios = '<div class="form-group"><label>Approve which quotation? *</label>';
                foreach ($quotes as $i => $q) $radios .= '<label class="check-row"><input type="radio" name="quote_id" value="' . $q['id'] . '" required' . (count($quotes) === 1 ? ' checked' : '') . '><span><strong>' . htmlspecialchars($q['supplier']) . '</strong> &middot; ' . kwacha((float)$q['amount']) . '</span></label>';
                $radios .= '</div>';
                $actions .= action_form('approve', 'Approve', ['confirm' => "Approve $ref and send it to Accounts for payment?", 'body' => $radios, 'note' => 'Note', 'placeholder' => 'Optional']);
                $actions .= '<hr class="req-sep">' . action_form('reject', 'Reject', ['class' => 'btn-danger', 'danger' => 1, 'confirm' => "Reject $ref and return it to the department?", 'note' => 'Reason for rejecting', 'note_required' => 1, 'placeholder' => 'The department will see this']);
            }
            break;
        case 'accounts':
            if (req_actor($pdo, 'requisitions.accounts', $p)) $actions .= action_form('to_am', 'Send to Accounts Manager', ['confirm' => "Send $ref to the Accounts Manager?", 'note' => 'Payment details', 'placeholder' => 'e.g. payment method, account, invoice number']);
            break;
        case 'accounts_manager':
            if (req_actor($pdo, 'requisitions.accounts_review', $p)) {
                $actions .= action_form('am_approve', 'Approve and send to CFO', ['confirm' => "Approve $ref and send it to the CFO?", 'note' => 'Note', 'placeholder' => 'Optional']);
                $actions .= '<hr class="req-sep">' . action_form('am_reject', 'Send back to Accounts', ['class' => 'btn-danger', 'danger' => 1, 'confirm' => "Send $ref back to Accounts?", 'note' => 'Reason', 'note_required' => 1, 'placeholder' => 'What needs fixing']);
            }
            break;
        case 'cfo':
            if (req_actor($pdo, 'requisitions.cfo', $p)) {
                $actions .= action_form('cfo_approve', 'Give final approval', ['confirm' => "Give final approval to pay for $ref?", 'note' => 'Note', 'placeholder' => 'Optional']);
                $actions .= '<hr class="req-sep">' . action_form('cfo_reject', 'Send back to Accounts Manager', ['class' => 'btn-danger', 'danger' => 1, 'confirm' => "Send $ref back to the Accounts Manager?", 'note' => 'Reason', 'note_required' => 1, 'placeholder' => 'What needs fixing']);
            }
            break;
        case 'pay':
            if (req_actor($pdo, 'requisitions.accounts', $p)) {
                $actions .= action_form('pop', 'Send proof of payment', ['upload' => 1, 'confirm' => "Attach this proof of payment and send it to " . $p['raised_by_name'] . '?',
                    'body' => '<div class="form-group"><label for="pop_file">Proof of payment *</label><input type="file" id="pop_file" name="file" required accept=".pdf,.jpg,.jpeg,.png,.doc,.docx,.xls,.xlsx"><small>PDF, image, Word or Excel, up to 10 MB.</small></div>',
                    'note' => 'Note', 'placeholder' => 'Optional, e.g. payment reference']);
            }
            break;
        case 'paid':
            if (req_actor($pdo, 'stock.receive', $p)) $actions .= '<p class="muted-text">When the items arrive, receive them into stock.</p><a href="receive_stock?purchase_id=' . $p['id'] . '" class="btn btn-success">Receive into stock</a>';
            break;
    }
    ?>
    <?php if ($actions): ?>
    <div class="card req-your-action">
        <div class="card-head"><h3>Your action</h3></div>
        <?php echo $actions; ?>
    </div>
    <?php elseif (!in_array($p['status'], ['received', 'cancelled'], true)): ?>
    <div class="card req-waiting"><p>Waiting for <strong><?php echo htmlspecialchars(REQ_STAGES[$p['status']][1] ?? ''); ?></strong>. There is nothing for you to do on it right now.</p></div>
    <?php endif; ?>

    <!-- Quotations -->
    <div class="card">
        <div class="card-head"><h3>Quotations</h3><span class="card-hint"><?php echo $p['is_it'] ? 'IT-related: IT gets the quotations' : 'The department attaches the quotations'; ?></span></div>
        <?php if ($quotes): ?>
        <table class="compact">
            <thead><tr><th>Supplier</th><th class="num">Amount</th><th>File</th><th>Added by</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($quotes as $q): ?>
            <tr class="<?php echo $approved_quote && $approved_quote['id'] === $q['id'] ? 'row-approved' : ''; ?>">
                <td style="font-weight:600"><?php echo htmlspecialchars($q['supplier']); ?><?php if ($approved_quote && $approved_quote['id'] === $q['id']): ?> <span class="status-receiving">Approved</span><?php endif; ?></td>
                <td class="num"><?php echo kwacha((float)$q['amount']); ?></td>
                <td><a href="requisition_file?id=<?php echo $q['id']; ?>" target="_blank" rel="noopener"><?php echo htmlspecialchars($q['original_name']); ?></a> <span class="muted-text">(<?php echo req_file_size((int)$q['size']); ?>)</span></td>
                <td class="muted"><?php echo htmlspecialchars($q['by_name']); ?><div class="item-desc"><?php echo date('d M Y', strtotime($q['created_at'])); ?></div></td>
                <td><?php if ($can_quote): ?>
                    <form method="POST" class="inline-form" data-confirm-title="Remove quotation" data-confirm="Remove the quotation from <?php echo htmlspecialchars($q['supplier']); ?>?" data-confirm-ok="Remove" data-confirm-danger="1">
                        <input type="hidden" name="id" value="<?php echo $p['id']; ?>"><input type="hidden" name="act" value="remove_quote"><input type="hidden" name="file_id" value="<?php echo $q['id']; ?>">
                        <button type="submit" class="btn btn-icon btn-icon-danger" aria-label="Remove quotation from <?php echo htmlspecialchars($q['supplier']); ?>" data-tip="Remove"><?php echo row_icon('delete'); ?></button>
                    </form>
                <?php endif; ?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php else: ?>
        <div class="empty-state"><p>No quotations yet.</p></div>
        <?php endif; ?>

        <?php if ($can_quote): ?>
        <form method="POST" enctype="multipart/form-data" class="req-upload">
            <input type="hidden" name="id" value="<?php echo $p['id']; ?>"><input type="hidden" name="act" value="add_quote">
            <div class="form-row three">
                <div class="form-group"><label for="q_supplier">Supplier *</label><input type="text" id="q_supplier" name="supplier" required minlength="2" maxlength="200"></div>
                <div class="form-group"><label for="q_amount">Amount (K) *</label><input type="number" id="q_amount" name="amount" required min="0.01" step="0.01" placeholder="0.00"></div>
                <div class="form-group"><label for="q_file">Quotation file *</label><input type="file" id="q_file" name="file" required accept=".pdf,.jpg,.jpeg,.png,.doc,.docx,.xls,.xlsx"></div>
            </div>
            <button type="submit" class="btn btn-primary">Attach quotation</button>
        </form>
        <?php endif; ?>
    </div>

    <?php if ($docs): ?>
    <div class="card">
        <div class="card-head"><h3>Attachments</h3><span class="card-hint">Added when it was raised</span></div>
        <?php foreach ($docs as $f): ?>
        <p><a href="requisition_file?id=<?php echo $f['id']; ?>" target="_blank" rel="noopener"><?php echo htmlspecialchars($f['original_name']); ?></a>
           <span class="muted-text">(<?php echo req_file_size((int)$f['size']); ?>) &middot; <?php echo htmlspecialchars($f['by_name']); ?>, <?php echo date('d M Y', strtotime($f['created_at'])); ?></span></p>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <?php if ($pops): ?>
    <div class="card">
        <div class="card-head"><h3>Proof of payment</h3></div>
        <?php foreach ($pops as $f): ?>
        <p><a href="requisition_file?id=<?php echo $f['id']; ?>" target="_blank" rel="noopener"><?php echo htmlspecialchars($f['original_name']); ?></a>
           <span class="muted-text">(<?php echo req_file_size((int)$f['size']); ?>) &middot; sent by <?php echo htmlspecialchars($f['by_name']); ?>, <?php echo date('d M Y', strtotime($f['created_at'])); ?></span></p>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <?php if ($can_cancel): ?>
    <details class="req-cancel">
        <summary>Cancel this requisition</summary>
        <?php echo action_form('cancel', 'Cancel requisition', ['class' => 'btn-danger', 'danger' => 1, 'confirm' => "Cancel $ref? This can't be undone.", 'note' => 'Reason', 'note_required' => 1]); ?>
    </details>
    <?php endif; ?>
</div>

<aside class="req-side">
    <div class="card">
        <div class="card-head"><h3>Details</h3></div>
        <dl class="req-details">
            <dt>Item</dt><dd><?php echo htmlspecialchars($p['item_name']); ?></dd>
            <dt>Quantity</dt><dd><?php echo number_format($p['quantity']); ?></dd>
            <dt>Category</dt><dd><?php echo htmlspecialchars($p['category_name'] ?? $p['category'] ?? '—'); ?></dd>
            <dt>Company</dt><dd><?php echo htmlspecialchars($p['company_name'] ?? '—'); ?></dd>
            <dt>Department</dt><dd><?php echo htmlspecialchars($p['dept_name'] ?? '—'); ?><?php echo $p['branch_name'] ? ' <span class="muted-text">· ' . htmlspecialchars($p['branch_name']) . '</span>' : ''; ?></dd>
            <dt>IT-related</dt><dd><?php echo $p['is_it'] ? 'Yes' : 'No'; ?></dd>
            <dt>Raised by</dt><dd><?php echo htmlspecialchars($p['raised_by_name'] ?? '—'); ?></dd>
            <dt>Raised on</dt><dd><?php echo date('d M Y', strtotime($p['created_at'])); ?></dd>
            <dt>Reason</dt><dd><?php echo nl2br(htmlspecialchars($p['reason'] ?? '—')); ?></dd>
            <?php if ($approved_quote): ?><dt>Approved quote</dt><dd><?php echo htmlspecialchars($approved_quote['supplier']); ?> &middot; <?php echo kwacha((float)$approved_quote['amount']); ?></dd><?php endif; ?>
        </dl>
    </div>
    <div class="card">
        <div class="card-head"><h3>History</h3></div>
        <ol class="req-history">
            <?php foreach (array_reverse($log) as $l): ?>
            <li>
                <div><strong><?php echo htmlspecialchars(REQ_ACTIONS[$l['action']] ?? $l['action']); ?></strong></div>
                <div class="muted-text"><?php echo htmlspecialchars($l['full_name']); ?><?php echo $l['role_name'] ? ' · ' . htmlspecialchars($l['role_name']) : ''; ?> · <?php echo date('d M Y, H:i', strtotime($l['created_at'])); ?></div>
                <?php if ($l['note']): ?><div class="req-note"><?php echo nl2br(htmlspecialchars($l['note'])); ?></div><?php endif; ?>
            </li>
            <?php endforeach; ?>
        </ol>
    </div>
</aside>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
