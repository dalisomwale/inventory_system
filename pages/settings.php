<?php
require_once __DIR__ . '/../config/database.php';
redirectIfNotLoggedIn();

$message = '';
$error = '';

// Update password
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_password'])) {
    $current = $_POST['current_password'] ?? '';
    $new = $_POST['new_password'] ?? '';
    $confirm = $_POST['confirm_password'] ?? '';
    
    $stmt = $pdo->prepare("SELECT password FROM users WHERE id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $user = $stmt->fetch();
    
    if (password_verify($current, $user['password'])) {
        if ($new === $confirm && strlen($new) >= 6) {
            $hash = password_hash($new, PASSWORD_DEFAULT);
            $pdo->prepare("UPDATE users SET password = ? WHERE id = ?")->execute([$hash, $_SESSION['user_id']]);
            $message = "Password updated successfully!";
        } else {
            $error = "New password must match and be at least 6 characters.";
        }
    } else {
        $error = "Current password is incorrect.";
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
                <input type="password" name="current_password" required>
            </div>
            <div class="form-group">
                <label>New Password</label>
                <input type="password" name="new_password" required>
            </div>
            <div class="form-group">
                <label>Confirm New Password</label>
                <input type="password" name="confirm_password" required>
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
        <p><strong>Username:</strong> <?php echo htmlspecialchars($_SESSION['username']); ?></p>
        <p><strong>Role:</strong> <?php echo ucfirst($_SESSION['role']); ?></p>
    </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
