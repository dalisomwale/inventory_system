<?php
/**
 * Requisition workflow.
 *
 *   Department raises it
 *     -> IT-related: IT attaches quotations      (quotes_it)
 *        otherwise:  the department attaches them (quotes_dept)
 *   -> Director approves (picks a quotation) or rejects back to the department (director / returned)
 *   -> Accounts -> Accounts Manager -> CFO        (accounts / accounts_manager / cfo; a rejection goes back one step)
 *   -> Accounts attaches the proof of payment     (pay -> paid)
 *   -> Stores receive it into stock               (received)
 *
 * Each step is done by whoever has that access right on Admin › Roles.
 */

const REQ_STAGES = [
    'quotes_it'        => ['Waiting for IT quotations',      'IT'],
    'quotes_dept'      => ['Waiting for quotations',         'Department'],
    'returned'         => ['Returned to department',         'Department'],
    'director'         => ['Waiting for Director approval',  'Director'],
    'accounts'         => ['With Accounts',                  'Accounts'],
    'accounts_manager' => ['With Accounts Manager',          'Accounts Manager'],
    'cfo'              => ['With CFO for final approval',    'CFO'],
    'pay'              => ['Approved, waiting for payment',  'Accounts'],
    'paid'             => ['Paid, waiting for delivery',     'Stores'],
    'received'         => ['Received',                       ''],
    'cancelled'        => ['Cancelled',                      ''],
];

/** Steps shown on the tracker, in order. */
const REQ_STEPS = [
    'raised'           => 'Raised',
    'quotes'           => 'Quotations',
    'director'         => 'Director',
    'accounts'         => 'Accounts',
    'accounts_manager' => 'Accounts Manager',
    'cfo'              => 'CFO',
    'pay'              => 'Payment',
    'received'         => 'Received',
];

/** Which tracker step a status sits on. */
function req_step(string $status): string {
    return match ($status) {
        'quotes_it', 'quotes_dept', 'returned' => 'quotes',
        'paid'                                 => 'received',
        'cancelled'                            => 'raised',
        default                                => $status,
    };
}

function req_status_label(string $status): string { return REQ_STAGES[$status][0] ?? ucfirst($status); }

/** "REQ-0012" style reference. */
function req_ref(int $id): string { return 'REQ-' . str_pad((string)$id, 4, '0', STR_PAD_LEFT); }

/** CSS class for a status pill. */
function req_status_class(string $status): string {
    return match ($status) {
        'received'                              => 'status-receiving',
        'cancelled'                             => 'status-muted',
        'returned'                              => 'status-issuing',
        'paid'                                  => 'status-ordered',
        default                                 => 'status-pending',
    };
}

/** The signed-in user's department (0 if none). */
function req_my_department(PDO $pdo): int {
    static $d = null;
    if ($d === null) {
        $st = $pdo->prepare("SELECT department_id FROM users WHERE id = ?");
        $st->execute([$_SESSION['user_id'] ?? 0]);
        $d = (int)$st->fetchColumn();
    }
    return $d;
}

/** The signed-in user's company (0 if none). */
function req_my_company(PDO $pdo): int {
    static $c = null;
    if ($c === null) {
        $st = $pdo->prepare("SELECT company_id FROM users WHERE id = ?");
        $st->execute([$_SESSION['user_id'] ?? 0]);
        $c = (int)$st->fetchColumn();
    }
    return $c;
}

/**
 * IT, Director, Accounts, Accounts Manager, CFO and Stores work for their own company only.
 * The System Admin (and anyone not yet given a company) works across the group.
 */
function req_company_sql(PDO $pdo): string {
    $c = req_my_company($pdo);
    return (isAdmin() || !$c) ? '1=1' : "p.company_id = $c";
}

/** Is the requisition in the user's company (for the steps above)? */
function req_same_company(PDO $pdo, array $p): bool {
    $c = req_my_company($pdo);
    return isAdmin() || !$c || (int)$p['company_id'] === $c;
}

/** Does the user hold this workflow right for this requisition's company? */
function req_actor(PDO $pdo, string $perm, array $p): bool {
    return can($perm) && req_same_company($pdo, $p);
}

/** SQL condition (on alias p) for the requisitions this user may see. */
function req_visible_sql(PDO $pdo): string {
    $co   = req_company_sql($pdo);
    if (can('requisitions.view_all')) return "($co)";
    $me   = (int)($_SESSION['user_id'] ?? 0);
    $or   = ["p.requested_by = $me"];
    if (can('requisitions.raise') && ($dept = req_my_department($pdo))) $or[] = "p.department_id = $dept";
    $step = [];
    if (can('requisitions.it_quotes'))  $step[] = "p.is_it = 1";
    if (can('requisitions.approve'))    $step[] = "p.status = 'director' OR EXISTS (SELECT 1 FROM requisition_log l WHERE l.requisition_id = p.id AND l.to_status = 'director')";
    if (can_any('requisitions.accounts', 'requisitions.accounts_review', 'requisitions.cfo'))
        $step[] = "p.status IN ('accounts','accounts_manager','cfo','pay','paid','received')";
    if (can('stock.receive'))           $step[] = "p.status IN ('paid','received')";
    if ($step) $or[] = "(($co) AND (" . implode(' OR ', $step) . "))";
    return '(' . implode(' OR ', $or) . ')';
}

/** Is this the raiser's side (the person who raised it, or a manager of that department)? */
function req_is_department(PDO $pdo, array $p): bool {
    if ((int)$p['requested_by'] === (int)($_SESSION['user_id'] ?? 0)) return true;
    $dept = req_my_department($pdo);
    return can('requisitions.raise') && $dept && (int)$p['department_id'] === $dept;
}

/** True if it is this user's turn to act on the requisition. */
function req_my_turn(PDO $pdo, array $p): bool {
    return match ($p['status']) {
        'quotes_it'        => req_actor($pdo, 'requisitions.it_quotes', $p),
        'quotes_dept', 'returned' => req_is_department($pdo, $p),
        'director'         => req_actor($pdo, 'requisitions.approve', $p),
        'accounts', 'pay'  => req_actor($pdo, 'requisitions.accounts', $p),
        'accounts_manager' => req_actor($pdo, 'requisitions.accounts_review', $p),
        'cfo'              => req_actor($pdo, 'requisitions.cfo', $p),
        'paid'             => req_actor($pdo, 'stock.receive', $p),
        default            => false,
    };
}

/** SQL condition for "waiting for me" (mirrors req_my_turn). */
function req_my_turn_sql(PDO $pdo): string {
    $or = []; $step = [];
    if (can('requisitions.it_quotes'))       $step[] = "p.status = 'quotes_it'";
    $me = (int)($_SESSION['user_id'] ?? 0);
    $dept_or = ["p.requested_by = $me"];
    if (can('requisitions.raise') && ($dept = req_my_department($pdo))) $dept_or[] = "p.department_id = $dept";
    $or[] = "(p.status IN ('quotes_dept','returned') AND (" . implode(' OR ', $dept_or) . "))";
    if (can('requisitions.approve'))         $step[] = "p.status = 'director'";
    if (can('requisitions.accounts'))        $step[] = "p.status IN ('accounts','pay')";
    if (can('requisitions.accounts_review')) $step[] = "p.status = 'accounts_manager'";
    if (can('requisitions.cfo'))             $step[] = "p.status = 'cfo'";
    if (can('stock.receive'))                $step[] = "p.status = 'paid'";
    if ($step) $or[] = "((" . req_company_sql($pdo) . ") AND (" . implode(' OR ', $step) . "))";
    return '(' . implode(' OR ', $or) . ')';
}

/** Move a requisition to a new stage (only if it is still at $from) and record who did it. */
function req_move(PDO $pdo, array $p, string $to, string $action, ?string $note = null, array $set = []): bool {
    $cols = ['status = ?', 'stage_at = NOW()', 'last_note = ?'];
    $vals = [$to, $note];
    foreach ($set as $col => $val) {
        if ($val === 'NOW()') { $cols[] = "$col = NOW()"; continue; }
        $cols[] = "$col = ?"; $vals[] = $val;
    }
    $vals[] = $p['id']; $vals[] = $p['status'];
    $st = $pdo->prepare("UPDATE procurement SET " . implode(', ', $cols) . " WHERE id = ? AND status = ?");
    $st->execute($vals);
    if ($st->rowCount() !== 1) return false;
    req_log($pdo, (int)$p['id'], $action, $p['status'], $to, $note);
    return true;
}

function req_log(PDO $pdo, int $id, string $action, ?string $from, ?string $to, ?string $note = null): void {
    $pdo->prepare("INSERT INTO requisition_log (requisition_id, user_id, action, from_status, to_status, note) VALUES (?,?,?,?,?,?)")
        ->execute([$id, $_SESSION['user_id'], $action, $from, $to, $note]);
}

/** Words for the history list. */
const REQ_ACTIONS = [
    'raised'          => 'Raised the requisition',
    'docs_added'      => 'Attached supporting documents',
    'quote_added'     => 'Attached a quotation',
    'quote_removed'   => 'Removed a quotation',
    'to_director'     => 'Sent to the Director',
    'resubmitted'     => 'Resubmitted to the Director',
    'back_to_it'      => 'Asked IT for new quotations',
    'approved'        => 'Approved',
    'rejected'        => 'Rejected and returned to the department',
    'to_am'           => 'Sent to the Accounts Manager',
    'am_approved'     => 'Approved and sent to the CFO',
    'am_rejected'     => 'Sent back to Accounts',
    'cfo_approved'    => 'Gave final approval',
    'cfo_rejected'    => 'Sent back to the Accounts Manager',
    'pop_sent'        => 'Attached the proof of payment',
    'received'        => 'Received into stock',
    'cancelled'       => 'Cancelled',
];

// ── Files (quotations and proof of payment) ────────────────

const REQ_FILE_TYPES = [
    'pdf'  => 'application/pdf',
    'jpg'  => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png',
    'doc'  => 'application/msword',
    'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    'xls'  => 'application/vnd.ms-excel',
    'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
];
const REQ_FILE_MAX = 10 * 1024 * 1024;   // 10 MB
const REQ_ATTACH_MAX = 5;                 // supporting documents on a new requisition

function req_storage_dir(): string {
    $dir = dirname(__DIR__) . '/storage/requisitions';
    if (!is_dir($dir)) mkdir($dir, 0770, true);
    return $dir;
}

/**
 * Check and store an uploaded file. Returns [stored_name, original_name, mime, size] or an error string.
 */
function req_store_upload(?array $f) {
    if (!$f || ($f['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) return 'Choose a file to attach.';
    if ($f['error'] === UPLOAD_ERR_INI_SIZE || $f['error'] === UPLOAD_ERR_FORM_SIZE || $f['size'] > REQ_FILE_MAX) return 'The file is too big. The limit is 10 MB.';
    if ($f['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($f['tmp_name'])) return 'The file didn\'t upload. Please try again.';
    $original = mb_substr(basename(str_replace('\\', '/', (string)$f['name'])), 0, 200);
    $ext = strtolower(pathinfo($original, PATHINFO_EXTENSION));
    if (!isset(REQ_FILE_TYPES[$ext])) return 'Attach a PDF, image (JPG or PNG), Word or Excel file.';
    // Check the content matches the type, not just the name
    $real = function_exists('finfo_open') ? (new finfo(FILEINFO_MIME_TYPE))->file($f['tmp_name']) : REQ_FILE_TYPES[$ext];
    $ok = match ($ext) {
        'pdf'         => $real === 'application/pdf',
        'jpg', 'jpeg' => $real === 'image/jpeg',
        'png'         => $real === 'image/png',
        'doc', 'xls'  => in_array($real, ['application/msword', 'application/vnd.ms-excel', 'application/CDFV2', 'application/x-ole-storage', 'application/octet-stream'], true),
        default       => in_array($real, ['application/zip', 'application/octet-stream', REQ_FILE_TYPES[$ext]], true),
    };
    if (!$ok) return "That file doesn't look like a real .$ext file.";
    $stored = bin2hex(random_bytes(16)) . '.' . $ext;
    if (!move_uploaded_file($f['tmp_name'], req_storage_dir() . '/' . $stored)) return 'The file couldn\'t be saved. Please try again.';
    return [$stored, $original, REQ_FILE_TYPES[$ext], (int)$f['size']];
}

function req_file_size(int $bytes): string {
    return $bytes >= 1048576 ? round($bytes / 1048576, 1) . ' MB' : max(1, round($bytes / 1024)) . ' KB';
}
