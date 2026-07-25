#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Build the static documentation site.
 *
 *   php scripts/docs/build-site.php <output-dir> [options]
 *
 *     --repo=URL     Repository the site links back to.
 *                    Default: https://github.com/felixgeelhaar/click-cms
 *     --branch=NAME  Branch those links point at. Default: main.
 *     --base=PATH    Absolute path the site will be served under, used only by
 *                    404.html — every other link on the site is relative and
 *                    needs no configuration. Default: /
 *     --root=DIR     Repository to read Markdown from. Default: this checkout.
 *
 * It reads `README.md` and every `docs/*.md`, and writes HTML, one stylesheet,
 * `404.html` and `.nojekyll` into the output directory. It writes nothing else,
 * anywhere: hand it a directory and it stays inside it.
 *
 * There is no timestamp, no build id and no random value in the output, so
 * rebuilding unchanged input produces byte-identical files and a diff of the
 * published site shows only what actually changed.
 */

require_once __DIR__ . '/bootstrap.php';

use ClickCms\Tools\Docs\SiteBuilder;

$argv = $_SERVER['argv'] ?? [];
$arguments = array_slice($argv, 1);

$outputDirectory = null;
$options = [
    'repo' => SiteBuilder::DEFAULT_REPOSITORY,
    'branch' => 'main',
    'base' => '/',
    'root' => dirname(__DIR__, 2),
];

foreach ($arguments as $argument) {
    if (str_starts_with($argument, '--')) {
        [$name, $value] = array_pad(explode('=', substr($argument, 2), 2), 2, '');
        if (!array_key_exists($name, $options)) {
            fwrite(STDERR, "Unknown option: --{$name}\n");
            exit(1);
        }
        if ($value === '') {
            fwrite(STDERR, "Option --{$name} needs a value, as --{$name}=…\n");
            exit(1);
        }
        $options[$name] = $value;
        continue;
    }
    if ($outputDirectory !== null) {
        fwrite(STDERR, "Only one output directory can be given.\n");
        exit(1);
    }
    $outputDirectory = $argument;
}

if ($outputDirectory === null) {
    fwrite(STDERR, "Usage: php scripts/docs/build-site.php <output-dir> [--repo=URL] [--branch=NAME] [--base=PATH] [--root=DIR]\n");
    exit(1);
}

if (!is_dir($options['root'])) {
    fwrite(STDERR, "Not a directory: {$options['root']}\n");
    exit(1);
}

try {
    $builder = new SiteBuilder(
        $options['root'],
        rtrim($options['repo'], '/'),
        $options['branch'],
        $options['base'],
    );
    $written = $builder->build($outputDirectory);
} catch (Throwable $error) {
    fwrite(STDERR, 'Build failed: ' . $error->getMessage() . "\n");
    exit(1);
}

$bytes = 0;
foreach ($written as $path) {
    $bytes += (int) filesize(rtrim($outputDirectory, '/') . '/' . $path);
    fwrite(STDOUT, "  {$path}\n");
}

fwrite(STDOUT, sprintf(
    "%d files, %.1f KB, written to %s\n",
    count($written),
    $bytes / 1024,
    $outputDirectory,
));

exit(0);
