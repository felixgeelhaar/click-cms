<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use Click\Cms\Application\Config\ConfigurationException;
use Click\Cms\Core\Application;

$app = new Application(__DIR__ . '/..');

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
