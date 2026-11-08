<?php
// Old address: Companies, Branches and Departments now have their own pages.
$to = in_array($_GET['tab'] ?? '', ['companies', 'branches', 'departments'], true) ? $_GET['tab'] : 'companies';
header('Location: ' . $to . '.php');
exit();
