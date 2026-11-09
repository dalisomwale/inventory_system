<?php
require_once __DIR__ . '/../config/database.php';

if (isset($_SESSION['user_id'])) {
    try {
        $pdo->prepare("INSERT INTO audit_trail (user_id, action, details) VALUES (?, 'LOGOUT', 'User logged out')")
            ->execute([$_SESSION['user_id']]);
    } catch (Exception $e) {}
}

session_destroy();
header('Location: login');
exit();
