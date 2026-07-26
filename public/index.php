<?php

declare(strict_types=1);

// Where the installation lives, worked out before anything is loaded — because
// the autoloader is itself inside it.
//
// Normally that is the directory above this one. It is not, when the app root
// has been moved out of the document root: on shared hosting the whole account
// is served from one directory tree, and keeping content/, data/ and config/
// out of it is the only way they are not reachable over HTTP. CLICK_CMS_ROOT is
// how the server says so.
//
// The variable's name is spelt out rather than taken from Application::ROOT_ENV
// (which is the same string) because nothing is autoloaded yet. A path that is
// not a directory is ignored, so a typo in a server config leaves an ordinary
// install running instead of a blank page.
$configuredRoot = getenv('CLICK_CMS_ROOT');
$root = is_string($configuredRoot) && $configuredRoot !== '' && is_dir($configuredRoot)
    ? rtrim($configuredRoot, '/')
    : __DIR__ . '/..';

require_once $root . '/vendor/autoload.php';

use Click\Cms\Application\Config\ConfigurationException;
use Click\Cms\Core\Application;

$app = new Application($root);

try {
    $app->run();
} catch (ConfigurationException $e) {
    // A configuration the CMS cannot honour is reported rather than worked
    // around, and reported where the person who caused it will see it. Left
    // uncaught this is a blank 500: the message reaches the error log, but the
    // administrator staring at an empty page has no idea the file they just
    // edited is the reason.
    //
    // 503 rather than 500 — the installation is misconfigured, not broken, and
    // it serves again the moment the setting is corrected.
    //
    // Only this exception type is echoed. Any other failure keeps its blank
    // 500, because arbitrary exception messages carry internal paths and must
    // not be shown to whoever happens to be visiting.
    http_response_code(503);
    header('Content-Type: text/plain; charset=utf-8');
    header('Retry-After: 60');
    echo "Click CMS cannot start.\n\n" . $e->getMessage() . "\n";
}
