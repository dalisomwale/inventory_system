<?php
session_start();
require_once 'config/database.php';

if (isset($_SESSION['user_id'])) {
    if ($_SESSION['role'] === 'manager' || $_SESSION['role'] === 'admin') {
        header('Location: pages/manager_dashboard.php');
    } else {
        header('Location: pages/dashboard.php');
    }
} else {
    header('Location: pages/login.php');
}
exit();
?>
