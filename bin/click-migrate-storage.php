#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Move content from one storage backend to another.
 *
 *   php bin/click-migrate-storage.php <from> <to> [type ...]
 *
 * where <from> and <to> are backend names ("json" or "sqlite"). For example, a
 * site outgrowing flat files moves to SQLite with:
 *
 *   php bin/click-migrate-storage.php json sqlite
 *
 * The backends are built here, directly, from `config/core.json` — the same
 * settings the running site uses, so the SQLite path and the site's default
 * language are whatever the configuration already says. It reads
 * {@see \Click\Cms\Infrastructure\Storage\StorageFactory} for how, but does not
 * call it: the factory builds one backend, the configured one, and a migration
 * needs two backends at once, one of which is by definition not the configured
 * one.
 *
 * ## Which types
 *
 * {@see \Click\Cms\Domain\Storage\StorageInterface} cannot list its own types
 * (a backend is addressed by a key that already names a type), so the set to
 * move is worked out here and handed to the mover. Given explicitly on the
 * command line it is used verbatim. Given nothing, it is discovered from the
 * content directory's top-level folders — the flat-file layout names a
 * directory per type — unioned with the types core always has, so a common one
 * is never missed. When the *source* is SQLite there is no content directory to
 * scan, so naming the types explicitly is the reliable route and is what the
 * final report tells the operator to do.
 *
 * Version history is not moved: the storage port exposes no way to enumerate a
 * document's versions. That is a separate store and a separate tool.
 *
 * The migration is safe to re-run: a document already present identically in
 * the target is skipped, so an interrupted run is resumed simply by running it
 * again.
 */

use Click\Cms\Application\Config\ConfigurationException;
use Click\Cms\Application\Config\CoreConfig;
use Click\Cms\Domain\Storage\StorageInterface;
use Click\Cms\Infrastructure\Storage\JsonStorage;
use Click\Cms\Infrastructure\Storage\SqliteStorage;
use Click\Cms\Infrastructure\Storage\StorageMigrator;

// This is a command-line tool. Served over the web it would expose a migration
// trigger to the internet, so it refuses to run under a web SAPI at all.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require __DIR__ . '/../vendor/autoload.php';

$basePath = dirname(__DIR__);

/** Backends core itself can build; anything else is a plugin's job, not this tool's. */
const KNOWN_BACKENDS = ['json', 'sqlite'];

/**
 * Types core always has. Unioned with whatever the content directory reveals so
 * a fresh or SQLite-backed install still moves the essentials when the operator
 * names nothing. Not exhaustive by design — plugin types a site has added are
 * named on the command line.
 */
const CORE_TYPES = ['page', 'user', 'media', 'menu', 'redirect'];

/**
 * Write a line to stderr and stop.
 *
 * Diagnostics go to stderr, not stdout, so a caller capturing the summary does
 * not have an error folded into the data it is reading.
 */
function fail(string $message): never
{
    fwrite(STDERR, $message . "\n");
    exit(1);
}

$argv = $_SERVER['argv'];
array_shift($argv); // drop the script name

$fromName = strtolower((string) ($argv[0] ?? ''));
$toName = strtolower((string) ($argv[1] ?? ''));
$explicitTypes = array_slice($argv, 2);

if ($fromName === '' || $toName === '') {
    fail(
        "Usage: php bin/click-migrate-storage.php <from> <to> [type ...]\n"
        . '       backends: ' . implode(', ', KNOWN_BACKENDS) . "\n"
        . "       example:  php bin/click-migrate-storage.php json sqlite\n"
    );
}

foreach ([$fromName, $toName] as $name) {
    if (!in_array($name, KNOWN_BACKENDS, true)) {
        fail(sprintf(
            'Unknown backend "%s". Core provides: %s.',
            $name,
            implode(', ', KNOWN_BACKENDS),
        ));
    }
}

if ($fromName === $toName) {
    fail('The source and target backends are the same; there is nothing to move.');
}

$config = CoreConfig::load($basePath . '/config/core.json');

/**
 * Build a backend directly, the way StorageFactory does, but for a named
 * backend rather than the configured one — a migration needs the backend that
 * is *not* configured too.
 */
$build = static function (string $name) use ($config, $basePath): StorageInterface {
    if ($name === 'json') {
        return new JsonStorage($basePath . '/content', $config->defaultLocale());
    }

    // sqlite. The same two checks StorageFactory makes, for the same reason:
    // PDO's own "could not find driver" surfaces deep in a call and helps
    // nobody decide what to do about it.
    if (!extension_loaded('pdo_sqlite')) {
        fail(
            'Backend "sqlite" needs the pdo_sqlite extension, which this PHP '
            . 'build does not have. Enable it in php.ini and try again.'
        );
    }

    $configured = $config->storageSqlitePath();
    if ($configured === '') {
        fail(
            'Backend "sqlite" has no path: core.storage.sqlite.path in '
            . 'config/core.json is empty, so there is nowhere to read or write '
            . 'the database. Set it to a path such as "data/content.sqlite".'
        );
    }

    // A relative path resolves against the install root, not the working
    // directory — the CLI and the web server run from different places and
    // would otherwise disagree about where the database is.
    $isAbsolute = str_starts_with($configured, '/')
        || preg_match('/^[A-Za-z]:[\\\\\/]/', $configured) === 1;
    $path = $isAbsolute ? $configured : $basePath . '/' . ltrim($configured, '/');

    return new SqliteStorage($path, $config->defaultLocale());
};

/**
 * Types to move: what the operator named, or what the content directory reveals
 * unioned with the core set.
 *
 * @return list<string>
 */
$resolveTypes = static function (array $explicit, string $basePath): array {
    if ($explicit !== []) {
        return array_values(array_unique($explicit));
    }

    $discovered = [];
    $contentDir = $basePath . '/content';
    if (is_dir($contentDir)) {
        foreach (glob($contentDir . '/*', GLOB_ONLYDIR) ?: [] as $dir) {
            $discovered[] = basename($dir);
        }
    }

    return array_values(array_unique([...$discovered, ...CORE_TYPES]));
};

try {
    $from = $build($fromName);
    $to = $build($toName);
} catch (ConfigurationException $e) {
    fail($e->getMessage());
}

$types = $resolveTypes($explicitTypes, $basePath);

fwrite(STDOUT, sprintf("Migrating content: %s -> %s\n", $fromName, $toName));
fwrite(STDOUT, 'Types: ' . implode(', ', $types) . "\n\n");

$migrator = new StorageMigrator($types);
$summary = $migrator->migrate($from, $to);

foreach ($summary['types'] as $type => $tally) {
    // Skip the noise of types that held nothing, so the output is the work done
    // rather than a wall of zeroes.
    if ($tally['copied'] === 0 && $tally['skipped'] === 0) {
        continue;
    }

    fwrite(STDOUT, sprintf(
        "  %-16s copied %d, skipped %d\n",
        $type,
        $tally['copied'],
        $tally['skipped'],
    ));
}

fwrite(STDOUT, sprintf(
    "\nDone. %d copied, %d skipped (already present).\n",
    $summary['copied'],
    $summary['skipped'],
));

if ($explicitTypes === [] && $fromName === 'sqlite') {
    // The one case discovery cannot cover: a SQLite source has no content
    // directory to scan, so any plugin type not in the core set was not looked
    // for. Say so rather than let the operator assume everything came across.
    fwrite(STDOUT,
        "\nNote: the source is SQLite, so types were taken from the core set only.\n"
        . "If this site has plugin content types, re-run naming them explicitly:\n"
        . "  php bin/click-migrate-storage.php sqlite json page user my_type\n"
    );
}

exit(0);
