<?php
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
// Match Docker/Apache's public theme asset alias.
if (preg_match('#^/themes/([a-z0-9_-]+)/([a-z0-9_.-]+\.css)$#i', $path, $match)) {
    $asset = __DIR__ . '/../themes/' . $match[1] . '/' . $match[2];
    if (is_file($asset)) {
        header('Content-Type: text/css');
        readfile($asset);
        return;
    }
}
if ($path !== '/' && is_file(__DIR__ . '/../public' . $path)) {
    return false;
}
require __DIR__ . '/../public/index.php';
