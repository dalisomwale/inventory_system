<?php
// Reports are no longer submitted; they are worked out live on Monthly Reports. Old address kept so bookmarks still work.
header('Location: reports' . (!empty($_GET['month']) && is_string($_GET['month']) ? '?month=' . urlencode($_GET['month']) : ''));
exit();
