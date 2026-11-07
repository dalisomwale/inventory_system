<?php
require_once __DIR__ . '/../includes/auth.php';
redirectIfNotLoggedIn();

$message = '';
$error = '';

// Update password
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_password'])) {
    $current = (string)($_POST['current_password'] ?? '');
    $new     = (string)($_POST['new_password'] ?? '');
    $confirm = (string)($_POST['confirm_password'] ?? '');

    $stmt = $pdo->prepare("SELECT password FROM users WHERE id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $user = $stmt->fetch();

    if (!$user || !password_verify($current, $user['password'])) {
        $error = "Current password is incorrect.";
    } elseif (strlen($new) < 8) {
        $error = "New password must be at least 8 characters.";
    } elseif (!preg_match('/[A-Za-z]/', $new) || !preg_match('/\d/', $new)) {
        $error = "New password must contain at least one letter and one number.";
    } elseif ($new !== $confirm) {
        $error = "The new passwords do not match.";
    } elseif ($new === $current) {
        $error = "New password must be different from your current password.";
    } else {
        $hash = password_hash($new, PASSWORD_DEFAULT);
        $pdo->prepare("UPDATE users SET password = ? WHERE id = ?")->execute([$hash, $_SESSION['user_id']]);
        logAudit($pdo, $_SESSION['user_id'], 'CHANGE_PASSWORD', null, null, 'Password changed');
        $message = "Password updated.";
    }
}

include __DIR__ . '/../includes/header.php';
?>

<div class="page-header">
    <h1>Settings</h1>
</div>

<?php if ($message): ?>
    <div class="alert alert-success"><?php echo $message; ?></div>
<?php endif; ?>
<?php if ($error): ?>
    <div class="alert alert-danger"><?php echo $error; ?></div>
<?php endif; ?>

<div class="card">
    <div class="card-header">
        <h3>Change Password</h3>
    </div>
    <div class="card-body">
        <form method="POST">
            <div class="form-group">
                <label>Current Password</label>
                <input type="password" name="current_password" required autocomplete="current-password">
            </div>
            <div class="form-group">
                <label>New Password</label>
                <input type="password" name="new_password" required minlength="8" maxlength="128" autocomplete="new-password"
                       pattern="(?=.*[A-Za-z])(?=.*\d).{8,}" title="At least 8 characters, with a letter and a number.">
                <small>At least 8 characters, with a letter and a number.</small>
            </div>
            <div class="form-group">
                <label>Confirm New Password</label>
                <input type="password" name="confirm_password" required data-match="new_password" autocomplete="new-password">
            </div>
            <button type="submit" name="update_password" class="btn btn-primary">Update Password</button>
        </form>
    </div>
</div>

<div class="card">
    <div class="card-header">
        <h3>Account Information</h3>
    </div>
    <div class="card-body">
        <p><strong>Name:</strong> <?php echo htmlspecialchars($_SESSION['full_name']); ?></p>
        <p><strong>Email:</strong> <?php echo htmlspecialchars($_SESSION['email'] ?? ''); ?></p>
    </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
