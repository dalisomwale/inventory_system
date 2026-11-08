<?php
/**
 * Create a System Admin account, or make an existing account a System Admin.
 * Run from the terminal only:
 *
 *   php tools/create_admin.php itadmin@altus.co.zm "IT Administrator"
 *
 * You'll be asked to type the password twice; it is never shown or saved anywhere except
 * as a secure hash in the database.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit(); }

require_once __DIR__ . '/../config/database.php';

$email = strtolower(trim($argv[1] ?? ''));
$name  = trim($argv[2] ?? 'System Admin');

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    fwrite(STDERR, "Usage: php tools/create_admin.php email@altus.co.zm \"Full Name\"\n");
    exit(1);
}

/** Read a line without echoing it (falls back to visible input if stty isn't available). */
function ask_hidden(string $prompt): string {
    echo $prompt;
    $hidden = DIRECTORY_SEPARATOR === '/' && @shell_exec('stty -echo 2>/dev/null; echo ok') !== null;
    $line = rtrim((string)fgets(STDIN), "\r\n");
    if ($hidden) { @shell_exec('stty echo 2>/dev/null'); echo "\n"; }
    return $line;
}

$pw  = ask_hidden("New password for $email: ");
$pw2 = ask_hidden('Type it again: ');

if ($pw !== $pw2)                                              { fwrite(STDERR, "The passwords do not match. Nothing was changed.\n"); exit(1); }
if (strlen($pw) < 8 || !preg_match('/[A-Za-z]/', $pw) || !preg_match('/\d/', $pw)) {
    fwrite(STDERR, "Password must be at least 8 characters with a letter and a number. Nothing was changed.\n"); exit(1);
}

$hash = password_hash($pw, PASSWORD_DEFAULT);
$admin_role = (int)$pdo->query("SELECT id FROM roles WHERE is_system = 1 ORDER BY id LIMIT 1")->fetchColumn();
if (!$admin_role) { fwrite(STDERR, "The roles table isn't set up. Run: mysql -u root -p < sql/migrate_schema.sql\n"); exit(1); }
$st = $pdo->prepare("SELECT id FROM users WHERE email = ?");
$st->execute([$email]);
$id = $st->fetchColumn();

if ($id) {
    $pdo->prepare("UPDATE users SET password = ?, role = 'admin', role_id = ?, active = 1 WHERE id = ?")->execute([$hash, $admin_role, $id]);
    echo "Updated: $email is now a System Admin with the new password.\n";
} else {
    $pdo->prepare("INSERT INTO users (full_name, email, password, role, role_id, active) VALUES (?, ?, ?, 'admin', ?, 1)")->execute([$name, $email, $hash, $admin_role]);
    $id = (int)$pdo->lastInsertId();
    echo "Created System Admin account for $name ($email).\n";
}
try {
    $pdo->prepare("INSERT INTO audit_trail (user_id, action, details) VALUES (?, 'ADMIN_SET_FROM_TERMINAL', ?)")->execute([$id, "System Admin set for $email"]);
} catch (Exception $e) {}
