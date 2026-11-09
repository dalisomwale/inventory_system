<?php
// Old address kept so bookmarks still work.
header('Location: requisitions' . (!empty($_SERVER['QUERY_STRING']) ? '?' . $_SERVER['QUERY_STRING'] : ''));
exit();
