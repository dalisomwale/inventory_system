<?php
/**
 * Clean addresses for PHP's built-in server:  php -S 0.0.0.0:8000 router.php
 *
 *   /dashboard          -> pages/dashboard.php   (the address bar never shows .php or the folder)
 *   /css, /js, /images  -> served as files
 *   anything else       -> "Page not found" (config, includes, sql, storage, tools, .git are never served)
 *   old /pages/x.php    -> redirected to /x so bookmarks keep working
 */
$root = __DIR__;
$path = rawurldecode(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/');
$qs   = $_SERVER['QUERY_STRING'] ?? '';

// Static files, only from the public folders
if (preg_match('#^/(css|js|images)/[\w./-]+$#', $path) && !str_contains($path, '..') && is_file($root . $path)) {
    return false;   // let the built-in server send the file
}
if ($path === '/favicon.ico') { http_response_code(204); return true; }

if ($path === '/' || $path === '/index.php') { require $root . '/index.php'; return true; }

// Old addresses with .php: send GET requests to the clean address
if (preg_match('#^/(?:pages/)?([a-z_]+)\.php$#', $path, $m) && is_file("$root/pages/{$m[1]}.php")) {
    if ($_SERVER['REQUEST_METHOD'] === 'GET' || $_SERVER['REQUEST_METHOD'] === 'HEAD') {
        header('Location: /' . $m[1] . ($qs !== '' ? '?' . $qs : ''), true, 301);
        return true;
    }
    $page = $m[1];
} elseif (preg_match('#^/([a-z_]+)/?$#', $path, $m) && is_file("$root/pages/{$m[1]}.php")) {
    $page = $m[1];
} else {
    http_response_code(404);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><meta charset="utf-8"><title>Page not found</title>'
       . '<div style="font-family:sans-serif;text-align:center;margin-top:80px;color:#1c2333">'
       . '<h2 style="color:#25398d">Page not found</h2><p><a href="/" style="color:#25398d">Go to the dashboard</a></p></div>';
    return true;
}

// Run the page as if it had been opened directly, but under its clean name
$_SERVER['SCRIPT_NAME'] = $_SERVER['PHP_SELF'] = '/' . $page;
$_SERVER['SCRIPT_FILENAME'] = "$root/pages/$page.php";
chdir("$root/pages");
require "$root/pages/$page.php";
return true;
