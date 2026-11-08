<?php
// Category activity was folded into Monthly Reports. Old address kept so bookmarks still work.
header('Location: reports.php?by=category' . (!empty($_GET['category_id']) ? '&id=' . (int)$_GET['category_id'] : ''));
exit();
