<?php
session_start();
if (isset($_SESSION['user_id'])) {
    header($_SESSION['role'] === 'manager'
        ? 'Location: pages/manager_dashboard.php'
        : 'Location: pages/dashboard.php');
} else {
    header('Location: pages/login.php');
}
exit();
