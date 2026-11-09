<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/validation.php';

if (isset($_SESSION['user_id'])) {
    header('Location: dashboard');
    exit();
}

if (!empty($_SESSION['login_notice'])) { $error = $_SESSION['login_notice']; unset($_SESSION['login_notice']); }

// Just came from creating an account
$prefill_email = '';
if (!empty($_SESSION['signup_done'])) {
    $welcome       = 'Account created, please log in.';
    $prefill_email = $_SESSION['signup_email'] ?? '';
    unset($_SESSION['signup_done'], $_SESSION['signup_email']);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email    = strtolower(trim((string)($_POST['email'] ?? '')));
    $password = (string)($_POST['password'] ?? '');

    if ($email === '' || $password === '') {
        $error = 'Please enter your email and password.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Sign in with your work email address, like name@altus.co.zm.';
    } else {
        try {
            $stmt = $pdo->prepare("SELECT * FROM users WHERE email = ?");
            $stmt->execute([$email]);
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

            if ($valid && isset($user['active']) && !(int)$user['active']) {
                $valid = false;
                $disabled = true;
            }

            if ($valid) {
                session_regenerate_id(true);
                $_SESSION['user_id']   = $user['id'];
                $_SESSION['email']     = $user['email'];
                $_SESSION['full_name'] = $user['full_name'];
                $_SESSION['role']      = $user['role'];

                try {
                    $pdo->prepare("UPDATE users SET last_login_at = NOW() WHERE id = ?")->execute([$user['id']]);
                    $pdo->prepare("INSERT INTO audit_trail (user_id, action, details) VALUES (?, 'LOGIN', 'User logged in')")->execute([$user['id']]);
                } catch (Exception $e) {}

                header('Location: dashboard');
                exit();
            } else {
                $error = !empty($disabled) ? 'This account has been disabled. Contact the IT department.' : 'Incorrect email or password.';
                usleep(400000); // slow down password guessing
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
<?php echo csrf_meta(); ?>
<title>Sign in · Altus Inventory</title>
<link rel="icon" type="image/png" href="../images/favicon.png">
<link rel="stylesheet" href="../css/style.css">
</head>
<body>
<div class="login-wrapper">
    <div class="login-card">
        <div class="login-brand">
            <img src="../images/altus-logo.png" alt="Altus Financial Services Limited">
            <h2>Inventory System</h2>
            <div class="sub">Sign in with your staff account</div>
        </div>

        <?php if (isset($welcome)): ?>
            <div class="alert alert-success" data-keep="1"><?php echo $welcome; ?></div>
        <?php endif; ?>
        <?php if (isset($error)): ?>
            <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>

        <form method="POST" novalidate>
            <div class="form-group">
                <label for="email">Work email</label>
                <input type="email" id="email" name="email" required maxlength="190" placeholder="name@altus.co.zm"
                       value="<?php echo htmlspecialchars($_POST['email'] ?? $prefill_email); ?>" autocomplete="username" <?php echo $prefill_email ? '' : 'autofocus'; ?>>
            </div>
            <div class="form-group">
                <label for="password">Password</label>
                <input type="password" id="password" name="password" required <?php echo $prefill_email ? 'autofocus' : ''; ?> placeholder="Enter password" autocomplete="current-password">
            </div>
            <button type="submit" class="btn btn-primary login-submit">Sign In</button>
        </form>

        <div class="login-alt">
            Don't have an account? <a href="signup">Create one</a>
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