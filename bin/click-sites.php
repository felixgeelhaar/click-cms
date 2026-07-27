#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Look at, and set up, the sites this installation serves.
 *
 *   php bin/click-sites.php --list
 *   php bin/click-sites.php --add=acme --host=acme.example.com --title="Acme Ltd"
 *   php bin/click-sites.php --check
 *
 * ## What a site is here
 *
 * One installation, many sites: the code, the plugins and the themes are shared;
 * the content, the media, the accounts and the settings are not. That split is
 * the point — an agency running eight client sites wants one thing to update and
 * eight things that cannot see each other.
 *
 * ## Nothing changes until you declare one
 *
 * An installation with no `config/sites.json` has exactly one site, and its
 * content stays at `content/` and `data/` where it has always been. Adding the
 * first *other* site does not move it either: the primary site keeps the
 * original layout, and only the new ones live under `sites/<id>/`.
 *
 * That is deliberate. A multi-site feature that begins by relocating an existing
 * site's content is a feature nobody dares turn on.
 *
 * Exit codes: 0 fine, 1 something is wrong.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';

use Click\Cms\Domain\Site\Site;
use Click\Cms\Domain\Site\SiteRegistry;

$argv = $_SERVER['argv'];
array_shift($argv);

$option = static function (string $name) use ($argv): ?string {
    foreach ($argv as $arg) {
        if (str_starts_with($arg, "--{$name}=")) {
            return substr($arg, strlen($name) + 3);
        }
    }

    return null;
};

$has = static fn (string $name): bool => in_array("--{$name}", $argv, true);

$path = $root . '/config/sites.json';

$read = static function () use ($path): array {
    if (!is_file($path)) {
        return ['sites' => []];
    }

    $decoded = json_decode((string) @file_get_contents($path), true);

    if (!is_array($decoded)) {
        fwrite(STDERR, "click-sites: {$path} exists but is not valid JSON. Fix or remove it.\n");
        exit(1);
    }

    return $decoded + ['sites' => []];
};

/* ------------------------------------------------------------------ list -- */

if ($has('list') || $argv === []) {
    $document = $read();

    if (!is_file($path)) {
        echo "This installation serves one site.\n";
        echo "Content is at content/ and data/, as it always has been.\n\n";
        echo "To serve more than one, add the first with:\n";
        echo "  php bin/click-sites.php --add=acme --host=acme.example.com\n";
        exit(0);
    }

    $registry = SiteRegistry::fromArray($document);

    printf("%-20s %-10s %s\n", 'ID', 'ROOT', 'HOSTS');
    foreach ($registry->all() as $site) {
        printf(
            "%-20s %-10s %s%s\n",
            $site->id,
            $site->rootSuffix() === '' ? '(root)' : ltrim($site->rootSuffix(), '/'),
            implode(', ', $site->hosts) ?: '—',
            $site->id === $registry->default()->id ? '   [default]' : '',
        );
    }

    exit(0);
}

/* ----------------------------------------------------------------- check -- */

if ($has('check')) {
    $document = $read();
    $registry = SiteRegistry::fromArray($document);

    // Two lists, and the difference decides the exit code. `--check` is meant to
    // be usable from CI and from cron, so it must exit non-zero only for things
    // that are actually wrong — a site whose directory does not exist yet is the
    // ordinary state of a site nobody has written to, and failing a build over
    // it would teach people to stop running this.
    $problems = [];
    $notes = [];

    // A host claimed by two sites is the one configuration mistake with a
    // genuinely bad outcome: which client's content a visitor gets would depend
    // on declaration order, which nobody would think to look at.
    $seen = [];
    foreach ($registry->all() as $site) {
        foreach ($site->hosts as $host) {
            if (isset($seen[$host])) {
                $problems[] = "{$host} is claimed by both {$seen[$host]} and {$site->id}.";
            }
            $seen[$host] = $site->id;
        }
    }

    foreach ($registry->all() as $site) {
        // The default site answers for anything unclaimed, so it needs no hosts
        // of its own. Any other site without one is unreachable, which is
        // certainly a mistake.
        if ($site->hosts === [] && $site->id !== $registry->default()->id) {
            $problems[] = "{$site->id} declares no hosts, so nothing will ever reach it.";
        }

        $directory = $root . $site->rootSuffix();
        if ($site->rootSuffix() !== '' && !is_dir($directory)) {
            $notes[] = "{$site->id} has no directory yet at {$directory}; it is created on first write.";
        }
    }

    foreach ($notes as $note) {
        echo "  note: {$note}\n";
    }

    if ($problems === []) {
        echo 'No problems found in ' . count($registry->all()) . " site(s).\n";
        exit(0);
    }

    foreach ($problems as $problem) {
        fwrite(STDERR, "  - {$problem}\n");
    }

    exit(1);
}

/* ------------------------------------------------------------------- add -- */

$id = $option('add');

if ($id === null) {
    fwrite(STDERR, "click-sites: nothing to do. Try --list, --check, or --add=<id> --host=<hostname>.\n");
    exit(1);
}

$host = $option('host');

if ($host === null || trim($host) === '') {
    fwrite(STDERR, "click-sites: a new site needs --host=<hostname>, or nothing will ever reach it.\n");
    exit(1);
}

try {
    $site = Site::fromArray(['id' => $id, 'hosts' => [$host], 'title' => $option('title') ?? $id]);
} catch (InvalidArgumentException $e) {
    fwrite(STDERR, "click-sites: {$e->getMessage()}\n");
    exit(1);
}

$document = $read();

foreach ($document['sites'] as $existing) {
    if (($existing['id'] ?? null) === $site->id) {
        fwrite(STDERR, "click-sites: a site with the id \"{$site->id}\" already exists.\n");
        exit(1);
    }

    foreach ($existing['hosts'] ?? [] as $existingHost) {
        if (strcasecmp((string) $existingHost, $host) === 0) {
            fwrite(STDERR, "click-sites: {$host} is already claimed by \"{$existing['id']}\".\n");
            exit(1);
        }
    }
}

// The first declaration has to name the existing site too, or the installation
// that already had content would stop being reachable — its content is at the
// root, and only a site marked primary lives there.
if ($document['sites'] === []) {
    $document['sites'][] = [
        'id' => Site::PRIMARY,
        'hosts' => [],
        'title' => 'This installation',
    ];

    echo "Declared the existing installation as the \"" . Site::PRIMARY . "\" site.\n";
    echo "Its content stays at content/ and data/ — nothing was moved.\n";
    echo "Give it a hostname by editing config/sites.json, or it will only answer\n";
    echo "for hosts no other site claims.\n\n";
}

$document['sites'][] = [
    'id' => $site->id,
    'hosts' => $site->hosts,
    'title' => $site->title,
];

$json = json_encode($document, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

$tmp = $path . '.' . bin2hex(random_bytes(6)) . '.tmp';

if (@file_put_contents($tmp, $json . "\n", LOCK_EX) === false || !@rename($tmp, $path)) {
    @unlink($tmp);
    fwrite(STDERR, "click-sites: could not write {$path}.\n");
    exit(1);
}

$directory = $root . $site->rootSuffix();

echo "Added \"{$site->id}\", answering for {$host}.\n";
echo "Its content will live in {$directory}.\n\n";
echo "Next:\n";
echo "  - point {$host} at this installation\n";
echo "  - php bin/click-sites.php --check\n";
echo "  - run any bin/ tool for it with --site={$site->id}\n";

exit(0);
