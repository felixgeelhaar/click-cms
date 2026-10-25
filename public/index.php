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
/**
 * The same lookup {@see \Click\Cms\Http\ServerEnvironment} performs, written
 * out because nothing is autoloaded yet — this file is what finds the code.
 *
 * All three names matter: Apache prefixes SetEnv variables with REDIRECT_ once
 * a request has been through an internal redirect, and on a cgi-fcgi SAPI every
 * PHP request has been.
 */
function click_cms_server_value(string $name): ?string
{
    $candidates = [getenv($name), $_SERVER[$name] ?? null];
    $prefix = '';
    for ($i = 0; $i < 3; $i++) {
        $prefix .= 'REDIRECT_';
        $candidates[] = $_SERVER[$prefix . $name] ?? null;
    }

    foreach ($candidates as $value) {
        if (is_string($value) && trim($value) !== '') {
            return trim($value);
        }
    }

    return null;
}

$configuredRoot = click_cms_server_value('CLICK_CMS_ROOT');
$root = $configuredRoot !== null && is_dir($configuredRoot)
    ? rtrim($configuredRoot, '/')
    : __DIR__ . '/..';

// Acquire the installation barrier BEFORE Composer or application classes load.
// A busy updater answers maintenance instead of mixing two software versions.
$gateDirectory = $root . '/data/updates';
if (!is_dir($gateDirectory)) {
    @mkdir($gateDirectory, 0755, true);
}
$clickGate = @fopen($gateDirectory . '/requests.lock', 'c');
if (!is_resource($clickGate)) {
    $clickGate = @fopen($gateDirectory . '/requests.lock', 'r');
}
if (!is_resource($clickGate) || !flock($clickGate, LOCK_SH | LOCK_NB)) {
    http_response_code(503);
    header('Retry-After: 5');
    header('Cache-Control: no-store');
    header('Content-Type: text/plain; charset=utf-8');
    echo "Click is updating. Please retry shortly.\n";
    exit;
}
// Recover a killed worker before Composer can load a missing/mixed vendor or
// src tree. Recovery code is preserved outside the replaceable directories.
if (file_exists($gateDirectory . '/swap-journal.json') || is_link($gateDirectory . '/swap-journal.json')) {
    if (!is_resource($clickGate) || !flock($clickGate, LOCK_EX | LOCK_NB)) {
        http_response_code(503);
        header('Retry-After: 5');
        header('Cache-Control: no-store');
        exit("Click update recovery is pending.\n");
    }
    $recoveryFile = $gateDirectory . '/bootstrap-recovery.php';
    $recovery = ['error' => 'Recovery bootstrap is unavailable.'];
    if (is_file($recoveryFile) && !is_link($recoveryFile)) {
        require_once $recoveryFile;
        $rawConfig = json_decode((string) @file_get_contents($root . '/config/core.json'), true);
        $expectedPublic = $rawConfig['core']['updates']['publicRoot'] ?? null;
        if ($expectedPublic === null || (is_string($expectedPublic) && trim($expectedPublic) !== '')) {
            $recovery = \Click\Cms\Application\Update\UpdateRecovery::recover($root,
                is_string($expectedPublic) ? trim($expectedPublic) : null);
        }
    }
    if (($recovery['error'] ?? null) !== null) {
        error_log('Click update recovery failed: ' . $recovery['error']);
        http_response_code(503);
        header('Retry-After: 60');
        header('Cache-Control: no-store');
        exit("Click update recovery needs administrator attention.\n");
    }
    if (function_exists('opcache_reset')) { @opcache_reset(); }
    http_response_code(503);
    header('Retry-After: 5');
    header('Cache-Control: no-store');
    exit("Click update recovery finished. Please retry shortly.\n");
}

require_once $root . '/vendor/autoload.php';

if (is_resource($clickGate)) {
    \Click\Cms\Application\Update\RequestGate::adopt($root, $clickGate);
    if (\Click\Cms\Application\Update\WebUpdateRunner::run($root)) {
        // Even rollback can replace files. This worker loaded the old classes
        // and must never construct Application after an installation attempt.
        http_response_code(503);
        header('Retry-After: 5');
        header('Cache-Control: no-store');
        header('Content-Type: text/plain; charset=utf-8');
        echo "Click update finished. Please retry shortly.\n";
        exit;
    }
}


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
