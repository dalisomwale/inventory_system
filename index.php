<?php
// Home: the dashboard when signed in, otherwise the sign-in page
if (session_status() === PHP_SESSION_NONE) session_start();
header('Location: /' . (isset($_SESSION['user_id']) ? 'dashboard' : 'login'));
exit();
