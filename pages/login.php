<?php
require_once __DIR__ . '/../config/database.php';

if (isset($_SESSION['user_id'])) {
    header($_SESSION['role'] === 'manager' ? 'Location: manager_dashboard.php' : 'Location: dashboard.php');
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($username === '' || $password === '') {
        $error = 'Please enter username and password.';
    } else {
        try {
            $stmt = $pdo->prepare("SELECT * FROM users WHERE username = ?");
            $stmt->execute([$username]);
            $user = $stmt->fetch();

            $valid = false;
            if ($user) {
                if (strlen($user['password']) === 32) {
                    $valid = ($user['password'] === md5($password));
                    if ($valid) {
                        $hash = password_hash($password, PASSWORD_DEFAULT);
                        $pdo->prepare("UPDATE users SET password = ? WHERE id = ?")->execute([$hash, $user['id']]);
                    }
                } else {
                    $valid = password_verify($password, $user['password']);
                }
            }

            if ($valid) {
                session_regenerate_id(true);
                $_SESSION['user_id']   = $user['id'];
                $_SESSION['username']  = $user['username'];
                $_SESSION['full_name'] = $user['full_name'];
                $_SESSION['role']      = $user['role'];

                try {
                    $pdo->prepare("INSERT INTO audit_trail (user_id, action, details) VALUES (?, 'LOGIN', 'User logged in')")->execute([$user['id']]);
                } catch (Exception $e) {}

                header($user['role'] === 'manager' ? 'Location: manager_dashboard.php' : 'Location: dashboard.php');
                exit();
            } else {
                $error = 'Invalid username or password.';
            }
        } catch (PDOException $e) {
            $error = 'Database error. Please try again.';
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
<title>Sign In — Inventory System</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Sans:wght@300;400;500;600&family=IBM+Plex+Mono:wght@400;500&display=swap" rel="stylesheet">
<link rel="stylesheet" href="../css/style.css">
</head>
<body>
<div class="login-wrapper">
    <div class="login-card">
        <div class="login-brand">
            <div class="logo-icon">INV</div>
            <div>
                <h2>InvMS</h2>
                <div class="sub">Inventory Management System</div>
            </div>
        </div>

        <?php if (isset($error)): ?>
            <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>

        <form method="POST" novalidate>
            <div class="form-group">
                <label for="username">Username</label>
                <input type="text" id="username" name="username" required placeholder="Enter username"
                       value="<?php echo htmlspecialchars($_POST['username'] ?? ''); ?>" autocomplete="username">
            </div>
            <div class="form-group">
                <label for="password">Password</label>
                <input type="password" id="password" name="password" required placeholder="Enter password" autocomplete="current-password">
            </div>
            <button type="submit" class="btn btn-primary login-submit">Sign In</button>
        </form>

        <div class="login-alt" style="margin-top: 14px;">
            Don't have an account? <a href="signup.php">Create one</a>
        </div>

        <hr class="login-divider">

        <div class="demo-box">
            <strong>Demo credentials</strong><br>
            Manager: <code>manager</code> / <code>password123</code><br>
            Front Desk: <code>frontdesk</code> / <code>password123</code>
        </div>
    </div>
</div>
<script src="../js/script.js"></script>
</body>
</html>