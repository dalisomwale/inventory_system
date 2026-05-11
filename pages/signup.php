<?php
// signup.php
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

    $errors = [];

    if (strlen($full_name) < 2) {
        $errors[] = 'Full name must be at least 2 characters.';
    }

    if (strlen($username) < 3) {
        $errors[] = 'Username must be at least 3 characters.';
    }

    if (strlen($password) < 6) {
        $errors[] = 'Password must be at least 6 characters.';
    }

    if ($password !== $confirm) {
        $errors[] = 'Passwords do not match.';
    }

    if (empty($errors)) {

        $stmt = $pdo->prepare("SELECT id FROM users WHERE username=?");
        $stmt->execute([$username]);

        if ($stmt->fetch()) {
            $errors[] = 'Username already exists.';
        } else {
            $hash = password_hash($password, PASSWORD_DEFAULT);

            $pdo->prepare("
                INSERT INTO users(full_name, username, password, role)
                VALUES(?, ?, ?, ?)
            ")->execute([$full_name, $username, $hash, $role]);

            $success = "Account created successfully! You can now sign in.";
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Proflight Zambia | Sign Up</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
<style>
* {
    margin: 0;
    padding: 0;
    box-sizing: border-box;
    font-family: 'Inter', sans-serif;
}

:root {
    --proflight-blue: #122FA4;
    --proflight-orange: #F15A24;
    --proflight-blue-dark: #0E257A;
    --proflight-blue-light: #E8EDF9;
    --proflight-orange-light: #FEE8E0;
}

body {
    min-height: 100vh;
    background: linear-gradient(135deg, #f5f7fc 0%, #eef2f8 100%);
}

.container {
    display: flex;
    min-height: 100vh;
}

/* LEFT SIDE - BRAND PANEL WITH IMAGE */
.left {
    flex: 1;
    position: relative;
    overflow: hidden;
}

.left-bg-image {
    position: absolute;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    object-fit: cover;
    z-index: 0;
}

.left-overlay {
    position: absolute;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background: linear-gradient(135deg, rgba(18, 47, 164, 0.75) 0%, rgba(10, 30, 100, 0.85) 100%);
    z-index: 1;
}

.wave-bg {
    position: absolute;
    bottom: 0;
    left: 0;
    right: 0;
    height: 180px;
    background: url('data:image/svg+xml;utf8,<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 1440 320"><path fill="rgba(255,255,255,0.1)" d="M0,192L48,197.3C96,203,192,213,288,208C384,203,480,181,576,186.7C672,192,768,224,864,229.3C960,235,1056,213,1152,192C1248,171,1344,149,1392,138.7L1440,128L1440,320L1392,320C1344,320,1248,320,1152,320C1056,320,960,320,864,320C768,320,672,320,576,320C480,320,384,320,288,320C192,320,96,320,48,320L0,320Z"></path></svg>') no-repeat bottom;
    background-size: cover;
    z-index: 2;
    pointer-events: none;
}

.left-content {
    position: absolute;
    bottom: 70px;
    left: 10%;
    right: 10%;
    color: white;
    z-index: 3;
}

.logo {
    display: flex;
    align-items: center;
    gap: 12px;
    margin-bottom: 30px;
}

.logo-icon {
    width: 50px;
    height: 50px;
    background: rgba(255,255,255,0.15);
    border-radius: 14px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-weight: 800;
    font-size: 18px;
    backdrop-filter: blur(8px);
    border: 1px solid rgba(255,255,255,0.2);
}

.logo-text {
    font-weight: 700;
    font-size: 22px;
    letter-spacing: -0.5px;
}

.logo-text span {
    color: var(--proflight-orange);
}

.left-content h1 {
    font-size: 42px;
    font-weight: 800;
    line-height: 1.2;
    margin-bottom: 16px;
}

.left-content h1 .brand-name {
    color: var(--proflight-orange);
}

.left-content .tagline {
    font-size: 16px;
    opacity: 0.85;
    line-height: 1.5;
    max-width: 80%;
}

/* RIGHT SIDE - FORM PANEL */
.right {
    flex: 1;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 40px;
    background: white;
}

.form-box {
    width: 100%;
    max-width: 480px;
}

.card {
    background: white;
    border-radius: 32px;
    padding: 48px 40px;
}

.header {
    text-align: center;
    margin-bottom: 36px;
}

.header h2 {
    font-size: 32px;
    font-weight: 700;
    color: var(--proflight-blue);
    margin-bottom: 8px;
}

.header p {
    color: #6c757d;
    font-size: 14px;
}

/* FORM STYLES */
.form-group {
    margin-bottom: 20px;
}

label {
    display: block;
    margin-bottom: 8px;
    font-size: 13px;
    font-weight: 600;
    color: #1a2a3a;
}

.input-group input,
.input-group select {
    width: 100%;
    padding: 14px 16px;
    border: 2px solid #e2e8f0;
    border-radius: 14px;
    font-size: 14px;
    transition: all 0.2s ease;
    background: #fafbfc;
}

.input-group select {
    cursor: pointer;
    appearance: none;
    background-image: url('data:image/svg+xml;utf8,<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="%236c757d" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 12 15 18 9"></polyline></svg>');
    background-repeat: no-repeat;
    background-position: right 16px center;
}

.input-group input:focus,
.input-group select:focus {
    outline: none;
    border-color: var(--proflight-blue);
    background: white;
    box-shadow: 0 0 0 4px rgba(18, 47, 164, 0.08);
}

.btn-signup {
    width: 100%;
    padding: 15px;
    border: none;
    border-radius: 14px;
    background: linear-gradient(135deg, var(--proflight-blue) 0%, #1a3bb5 100%);
    color: white;
    font-weight: 700;
    font-size: 15px;
    cursor: pointer;
    transition: all 0.2s ease;
    margin-top: 12px;
}

.btn-signup:hover {
    transform: translateY(-2px);
    box-shadow: 0 8px 20px rgba(18, 47, 164, 0.25);
}

.alert {
    padding: 14px 16px;
    border-radius: 14px;
    margin-bottom: 22px;
    font-size: 13px;
    font-weight: 500;
    display: flex;
    align-items: center;
    gap: 10px;
}

.alert-danger {
    background: #fff5f5;
    border-left: 4px solid #e53e3e;
    color: #c53030;
}

.alert-danger::before {
    content: "⚠";
}

.alert-success {
    background: #f0fff4;
    border-left: 4px solid #38a169;
    color: #276749;
}

.alert-success::before {
    content: "✓";
}

.footer {
    text-align: center;
    margin-top: 28px;
    padding-top: 20px;
    border-top: 1px solid #edf2f7;
    color: #6c757d;
    font-size: 14px;
}

.footer a {
    color: var(--proflight-blue);
    text-decoration: none;
    font-weight: 700;
}

.footer a:hover {
    color: var(--proflight-orange);
}

.role-hint {
    font-size: 11px;
    color: #94a3b8;
    margin-top: 6px;
    display: block;
}

/* Responsive */
@media (max-width: 900px) {
    .container {
        flex-direction: column;
    }
    
    .left {
        min-height: 280px;
    }
    
    .left-content {
        bottom: 30px;
        left: 5%;
        right: 5%;
    }
    
    .left-content h1 {
        font-size: 28px;
    }
    
    .left-content .tagline {
        max-width: 100%;
        font-size: 14px;
    }
    
    .card {
        padding: 32px 24px;
    }
    
    .header h2 {
        font-size: 28px;
    }
}

@media (max-width: 480px) {
    .right {
        padding: 20px;
    }
    
    .card {
        padding: 28px 20px;
    }
}
</style>
</head>
<body>
<div class="container">
    <div class="left">
        <img class="left-bg-image" src="login-image.jpeg" alt="Proflight Zambia Background">
        <div class="left-overlay"></div>
        <div class="wave-bg"></div>
        <div class="left-content">
            <h1>Join Our <span class="brand-name">Inventory Team</span></h1>
            <p class="tagline">Create an account to start managing Proflight Zambia's inventory.</p>
        </div>
    </div>
    <div class="right">
        <div class="form-box">
            <div class="card">
                <div class="header">
                    <h2>Create Account</h2>
                    <p>Start managing inventory today</p>
                </div>
                
                <?php if(!empty($errors)): ?>
                    <?php foreach($errors as $err): ?>
                    <div class="alert alert-danger">
                        <?php echo htmlspecialchars($err); ?>
                    </div>
                    <?php endforeach; ?>
                <?php endif; ?>
                
                <?php if(isset($success)): ?>
                <div class="alert alert-success">
                    <?php echo $success; ?>
                </div>
                <?php endif; ?>
                
                <form method="POST">
                    <div class="form-group">
                        <label>Full Name</label>
                        <div class="input-group">
                            <input type="text" name="full_name" placeholder="Enter your full name" required>
                        </div>
                    </div>
                    <div class="form-group">
                        <label>Username</label>
                        <div class="input-group">
                            <input type="text" name="username" placeholder="Choose a username" required>
                        </div>
                    </div>
                    <div class="form-group">
                        <label>Role</label>
                        <div class="input-group">
                            <select name="role">
                                <option value="front_desk">Front Desk Staff</option>
                                <option value="manager">Manager</option>
                            </select>
                        </div>
                    </div>
                    <div class="form-group">
                        <label>Password</label>
                        <div class="input-group">
                            <input type="password" name="password" placeholder="Create a password (min. 6 characters)" required>
                        </div>
                    </div>
                    <div class="form-group">
                        <label>Confirm Password</label>
                        <div class="input-group">
                            <input type="password" name="confirm_password" placeholder="Confirm your password" required>
                        </div>
                    </div>
                    <button type="submit" class="btn-signup">Create Account</button>
                </form>
                <div class="footer">
                    Already have an account? <a href="login.php">Sign In</a>
                </div>
            </div>
        </div>
    </div>
</div>
</body>
</html>