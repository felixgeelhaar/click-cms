<?php

declare(strict_types=1);

/**
 * Ask this host whether it can run click-cms — before, or instead of, finding
 * out the hard way.
 *
 * Everything it reports can only be answered on the server, and answered *by the
 * web server*: shared hosting routinely runs one PHP on the command line and
 * another for the web, so a shell session can tell you the wrong thing. Open
 * this in a browser and it answers as the site itself will.
 *
 * ── Using it ───────────────────────────────────────────────────────────────
 *
 *   1. Set a token. Either edit TOKEN below, or — better, because an update
 *      replaces this file — set CLICK_PREFLIGHT_TOKEN in the server config:
 *
 *          SetEnv CLICK_PREFLIGHT_TOKEN a-long-hard-to-guess-string
 *
 *   2. Open  https://example.com/preflight.php?token=THAT-STRING
 *   3. Delete the file, or unset the token, when you are done.
 *
 * Until a token is set this answers 404, exactly like a file that is not there.
 * That is deliberate: the archive ships with the placeholder, so an installation
 * that never touches this page never exposes a description of its server — and
 * because an update restores the placeholder, forgetting step 3 fails safe on
 * the next release rather than staying open forever.
 *
 * Nothing here is secret on its own. Taken together — PHP version, extensions,
 * paths — it is a tidy summary of what to try against this host, which is not
 * something to publish for no reason.
 */

/** Replaced by an operator, or left alone and set through the environment. */
const TOKEN = 'change-me-before-using';

/**
 * The same lookup {@see \Click\Cms\Http\ServerEnvironment} performs, written
 * out because nothing is autoloaded yet — this file is what finds the code.
 *
 * All three names matter: Apache prefixes SetEnv variables with REDIRECT_ once
 * a request has been through an internal redirect, and on a cgi-fcgi SAPI every
 * PHP request has been.
 */
function click_cms_preflight_value(string $name): ?string
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


$configured = click_cms_preflight_value('CLICK_PREFLIGHT_TOKEN') ?? TOKEN;
$presented = (string) ($_GET['token'] ?? '');

// Refused while the token is still the shipped placeholder, or too short to be
// worth guessing at. hash_equals rather than === because comparing a secret with
// a short-circuiting operator leaks its length through timing.
if (
    strlen($configured) < 16
    || str_contains($configured, 'change-me')
    || !hash_equals($configured, $presented)
) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    exit("Not Found\n");
}

// The same root resolution index.php performs, and for the same reason: on a
// split-root install the autoloader is not above this file, it is wherever
// CLICK_CMS_ROOT points. Assuming otherwise made this page fatal on exactly the
// installation it was written to diagnose — and a preflight that dies for the
// same reason as the site turns one wrong answer into two.
$root = click_cms_preflight_value('CLICK_CMS_ROOT');
$root = $root !== null && is_dir($root) ? rtrim($root, '/') : __DIR__ . '/..';

if (!is_file($root . '/vendor/autoload.php')) {
    // Reported rather than fataled. "I looked here and found nothing" is the
    // most useful sentence this page can print, because a missing autoloader is
    // what a wrong CLICK_CMS_ROOT looks like from here — and a blank 500 is
    // what it looked like before.
    header('Content-Type: text/plain; charset=utf-8');
    http_response_code(503);
    echo "click-cms preflight\n\n";
    echo "The installation's own files are not where this page was told to look.\n\n";
    echo '  CLICK_CMS_ROOT:  ' . (click_cms_preflight_value('CLICK_CMS_ROOT') ?? '(not set)') . "\n";
    echo "  looked in:       {$root}\n";
    echo "  expected:        {$root}/vendor/autoload.php\n\n";
    echo "If that path looks right but does not exist, check whether it is the path\n";
    echo "the *server* sees. SFTP is often chrooted to an account's home, so the\n";
    echo "directory you uploaded to is not the absolute path PHP resolves.\n";
    exit;
}

require_once $root . '/vendor/autoload.php';

use Click\Cms\Application\Preflight\CheckStatus;
use Click\Cms\Application\Preflight\HostReport;

header('Content-Type: text/plain; charset=utf-8');
header('X-Robots-Tag: noindex, nofollow');
header('Cache-Control: no-store, private');

/** `2M`, `512K`, `1G` and plain byte counts, as PHP writes them. */
$toBytes = static function (string $value): int {
    $value = trim($value);
    if ($value === '') {
        return 0;
    }
    // -1 means unlimited, which is not a small number.
    if ((int) $value === -1) {
        return PHP_INT_MAX;
    }

    $number = (int) $value;

    return match (strtolower(substr($value, -1))) {
        'g' => $number * 1024 ** 3,
        'm' => $number * 1024 ** 2,
        'k' => $number * 1024,
        default => $number,
    };
};

$publicDir = __DIR__;
$documentRoot = realpath((string) ($_SERVER['DOCUMENT_ROOT'] ?? '')) ?: '';
// Where an app root could live: the directory above whatever the server serves.
// Above the document root rather than above this file, because this file may sit
// several directories down inside it.
$outsideRoot = $documentRoot !== '' ? dirname($documentRoot) : dirname($publicDir, 2);

$checks = HostReport::for([
    'phpVersionId' => PHP_VERSION_ID,
    'phpVersion' => PHP_VERSION,
    'sapi' => PHP_SAPI,
    'extensions' => get_loaded_extensions(),
    'uploadMaxBytes' => $toBytes((string) ini_get('upload_max_filesize')),
    'postMaxBytes' => $toBytes((string) ini_get('post_max_size')),
    'memoryLimit' => (string) ini_get('memory_limit'),
    'maxExecutionTime' => (string) ini_get('max_execution_time'),
    'allowUrlFopen' => filter_var(ini_get('allow_url_fopen'), FILTER_VALIDATE_BOOL),
    'documentRoot' => $documentRoot !== '' ? $documentRoot : 'unknown',
    'publicDir' => $publicDir,
    'publicDirWritable' => is_writable($publicDir),
    'outsideRoot' => $outsideRoot,
    'outsideRootWritable' => $outsideRoot !== $documentRoot && is_dir($outsideRoot) && is_writable($outsideRoot),
]);

$width = 0;
foreach ($checks as $check) {
    $width = max($width, strlen($check->name));
}

$marks = [
    CheckStatus::Ok->value => '  ok ',
    CheckStatus::Warning->value => ' warn',
    CheckStatus::Failed->value => ' FAIL',
    CheckStatus::Info->value => '  ·  ',
];

echo "click-cms preflight\n";
echo str_repeat('=', 72) . "\n\n";

foreach ($checks as $check) {
    printf("%s  %-{$width}s  %s\n", $marks[$check->status->value], $check->name, $check->detail);
}

$failures = HostReport::failures($checks);
$warnings = HostReport::warnings($checks);

echo "\n" . str_repeat('-', 72) . "\n";
if ($failures > 0) {
    echo "{$failures} thing(s) will stop click-cms running here.\n";
} elseif ($warnings > 0) {
    echo "Nothing blocking; {$warnings} thing(s) will work less well than they could.\n";
} else {
    echo "This host can run click-cms.\n";
}
echo "Delete this file, or unset its token, when you are done with it.\n";
