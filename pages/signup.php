<?php
require_once __DIR__ . '/../config/database.php';

if (isset($_SESSION['user_id'])) {
    header('Location: dashboard.php');
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $full_name = trim($_POST['full_name'] ?? '');
    $username  = trim($_POST['username'] ?? '');
    $password  = $_POST['password'] ?? '';
    $confirm   = $_POST['confirm_password'] ?? '';
    $role      = $_POST['role'] ?? 'front_desk';

    if (strlen($password) < 8) {
        $error = 'Password must be at least 8 characters.';
    } elseif ($password !== $confirm) {
        $error = 'Passwords do not match.';
    } elseif (!in_array($role, ['manager', 'front_desk'])) {
        $error = 'Invalid role selected.';
    } else {
        try {
            $stmt = $pdo->prepare("SELECT id FROM users WHERE username = ?");
            $stmt->execute([$username]);
            if ($stmt->fetch()) {
                $error = 'Username already taken. Please choose another.';
            } else {
                $hash = password_hash($password, PASSWORD_DEFAULT);
                $pdo->prepare("INSERT INTO users (full_name, username, password, role) VALUES (?, ?, ?, ?)")
                    ->execute([$full_name, $username, $hash, $role]);
                $success = 'Account created. You can now <a href="login.php">sign in</a>.';
            }
        } catch (PDOException $e) {
            $error = 'Registration failed. Please try again.';
            error_log($e->getMessage());
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>Create account · Altus Inventory</title>
<link rel="icon" type="image/png" href="../images/favicon.png">
<link rel="stylesheet" href="../css/style.css">
</head>
<body>
<div class="login-wrapper">
    <div class="login-card" style="max-width: 400px;">
        <div class="login-brand">
            <img src="../images/altus-logo.png" alt="Altus Financial Services Limited">
            <h2>Create an account</h2>
            <div class="sub">Inventory System</div>
        </div>

        <?php if (isset($error)): ?>
            <div class="alert alert-danger"><?php echo $error; ?></div>
        <?php endif; ?>
        <?php if (isset($success)): ?>
            <div class="alert alert-success"><?php echo $success; ?></div>
        <?php endif; ?>

        <form method="POST" novalidate>
            <div class="form-group">
                <label for="full_name">Full Name</label>
                <input type="text" id="full_name" name="full_name" required placeholder="Your full name"
                       value="<?php echo htmlspecialchars($_POST['full_name'] ?? ''); ?>">
            </div>
            <div class="form-group">
                <label for="username">Username</label>
                <input type="text" id="username" name="username" required placeholder="Choose a username"
                       value="<?php echo htmlspecialchars($_POST['username'] ?? ''); ?>" autocomplete="username">
            </div>
            <div class="form-group">
                <label for="role">Role</label>
                <select id="role" name="role">
                    <option value="front_desk" <?php echo (($_POST['role'] ?? '') === 'front_desk') ? 'selected' : ''; ?>>Front Desk</option>
                    <option value="manager" <?php echo (($_POST['role'] ?? '') === 'manager') ? 'selected' : ''; ?>>Manager</option>
                </select>
            </div>
            <div class="form-group">
                <label for="password">Password</label>
                <input type="password" id="password" name="password" required placeholder="Min. 8 characters" autocomplete="new-password">
            </div>
            <div class="form-group">
                <label for="confirm_password">Confirm Password</label>
                <input type="password" id="confirm_password" name="confirm_password" required placeholder="Repeat password" autocomplete="new-password">
            </div>
            <button type="submit" class="btn btn-primary login-submit">Create Account</button>
        </form>

        <div class="login-alt">
            Already have an account? <a href="login.php">Sign in</a>
        </div>
    </div>
    <div class="login-footer">
        &copy; <?php echo date('Y'); ?> Altus Financial Services Limited<br>
        Trouble signing in? Contact the IT department.
    </div>
</div>
<script src="../js/script.js"></script>
</body>
</html>