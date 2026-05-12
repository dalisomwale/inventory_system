<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Proflight Zambia - Service Center Inventory</title>
    <link rel="stylesheet" href="../css/style.css">
</head>
<body>
<?php if(isset($_SESSION['user_id'])): ?>
<?php include __DIR__ . '/sidebar.php'; ?>
<?php endif; ?>
<main class="main-content <?php echo isset($_SESSION['user_id'])?'with-sidebar':'';?>">
