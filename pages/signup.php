<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/validation.php';

if (isset($_SESSION['user_id'])) {
    header('Location: dashboard.php');
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $v         = new Validator($_POST);
    $full_name = $v->text('full_name', 'Full name', 150, true, 3);
    $email     = $v->email('email', 'Work email');
    $role      = $v->choice('role', 'role', ['manager', 'front_desk']);
    $password  = (string)($_POST['password'] ?? '');
    $confirm   = (string)($_POST['confirm_password'] ?? '');

    if ($full_name !== '' && !preg_match("/^[\p{L} .'-]+$/u", $full_name)) {
        $v->fail('Full name can only contain letters, spaces, hyphens and apostrophes.');
    }
    if ($v->ok() && !email_domain_allowed($email)) {
        $v->fail('Use your Altus work email (' . implode(', ', array_map(fn($d) => '@' . $d, ALLOWED_EMAIL_DOMAINS)) . ').');
    }
    if (strlen($password) < 8) {
        $v->fail('Password must be at least 8 characters.');
    } elseif (!preg_match('/[A-Za-z]/', $password) || !preg_match('/\d/', $password)) {
        $v->fail('Password must contain at least one letter and one number.');
    } elseif ($password !== $confirm) {
        $v->fail('Passwords do not match.');
    }

    if (!$v->ok()) {
        $error = $v->message();
    } else {
        try {
            $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ?");
            $stmt->execute([$email]);
            if ($stmt->fetch()) {
                $error = 'An account with this email already exists. <a href="login.php">Sign in</a> instead.';
            } else {
                $hash = password_hash($password, PASSWORD_DEFAULT);
                $pdo->prepare("INSERT INTO users (full_name, email, password, role) VALUES (?, ?, ?, ?)")
                    ->execute([$full_name, $email, $hash, $role]);
                try {
                    $new_id = (int)$pdo->lastInsertId();
                    $pdo->prepare("INSERT INTO audit_trail (user_id, action, details) VALUES (?, 'CREATE_ACCOUNT', ?)")
                        ->execute([$new_id, "Account created for $email ($role)"]);
                } catch (Exception $e) {}
                $_SESSION['signup_done']  = $full_name;
                $_SESSION['signup_email'] = $email;
                header('Location: login.php');
                exit();
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
<?php echo csrf_meta(); ?>
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

        <form method="POST" novalidate
              data-confirm-title="Create account"
              data-confirm="Create a {role} account for {full_name} ({email})?"
              data-confirm-ok="Create account">
            <div class="form-group">
                <label for="full_name">Full Name</label>
                <input type="text" id="full_name" name="full_name" required minlength="3" maxlength="150" placeholder="Your full name"
                       value="<?php echo htmlspecialchars($_POST['full_name'] ?? ''); ?>">
            </div>
            <div class="form-group">
                <label for="email">Work email</label>
                <input type="email" id="email" name="email" required maxlength="190" placeholder="name@altus.co.zm"
                       value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>" autocomplete="email">
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
                <input type="password" id="password" name="password" required minlength="8" maxlength="128" placeholder="Min. 8 characters, with a letter and a number" autocomplete="new-password"
                       pattern="(?=.*[A-Za-z])(?=.*\d).{8,}" title="At least 8 characters, with a letter and a number.">
            </div>
            <div class="form-group">
                <label for="confirm_password">Confirm Password</label>
                <input type="password" id="confirm_password" name="confirm_password" required data-match="password" placeholder="Repeat password" autocomplete="new-password">
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