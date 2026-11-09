<?php
// Download a quotation or proof of payment, if the user can see its requisition.
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/requisition_flow.php';
redirectIfNotLoggedIn();

$st = $pdo->prepare("SELECT f.* FROM requisition_files f JOIN procurement p ON p.id = f.requisition_id
                     WHERE f.id = ? AND " . req_visible_sql($pdo));
$st->execute([(int)($_GET['id'] ?? 0)]);
$f = $st->fetch();
$path = $f ? req_storage_dir() . '/' . basename($f['stored_name']) : '';
if (!$f || !req_can_open() || !is_file($path)) {
    http_response_code(404);
    $_SESSION['flash_error'] = "That file doesn't exist, or you don't have access to it.";
    header('Location: requisitions'); exit();
}
// PDFs and images open in the browser; everything else downloads
$inline = in_array($f['mime'], ['application/pdf', 'image/jpeg', 'image/png'], true);
header('Content-Type: ' . $f['mime']);
header('Content-Length: ' . filesize($path));
header('X-Content-Type-Options: nosniff');
header("Content-Security-Policy: default-src 'none'; img-src 'self'; style-src 'unsafe-inline'; sandbox");
header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . '; filename="' . str_replace(['"', "\r", "\n"], '', $f['original_name']) . '"');
readfile($path);
