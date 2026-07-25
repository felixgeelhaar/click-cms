#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Fill a fresh install with an example site.
 *
 *   php bin/click-seed.php [--dry-run]
 *
 * A new install has an admin account and nothing else, so every screen in the
 * admin is an empty list and the public site is a 404. This puts a small but
 * complete site behind those screens: pages using every shipped section type,
 * entries in both shipped collections, a main menu, and the pictures they
 * reference.
 *
 * Nothing is ever overwritten. Anything already present is left alone and
 * reported as skipped, so running this twice is a no-op and an interrupted run
 * is finished by running it again. There is no flag that deletes content — to
 * remove the example site, delete its pages from the admin.
 *
 * The content itself is {@see \Click\Cms\Application\Seed\ExampleSite}; this
 * file only wires the services it needs and prints what happened.
 */

use Click\Cms\Application\Collection\CollectionService;
use Click\Cms\Application\Config\CoreConfig;
use Click\Cms\Application\Content\PageService;
use Click\Cms\Application\Media\MediaService;
use Click\Cms\Application\Seed\ExampleSite;
use Click\Cms\Application\Seed\SiteSeeder;
use Click\Cms\Core\Application;
use Click\Cms\Domain\Schema\SectionValidator;
use Click\Cms\Infrastructure\Collection\JsonCollectionTypeRepository;
use Click\Cms\Infrastructure\Schema\JsonSectionTypeRepository;

// A command-line tool. Served over the web this would be an unauthenticated
// endpoint that writes content, so it refuses to run under a web SAPI at all.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require __DIR__ . '/../vendor/autoload.php';

$basePath = dirname(__DIR__);
$argv = $_SERVER['argv'];
array_shift($argv);

$dryRun = in_array('--dry-run', $argv, true);
$unknown = array_values(array_diff($argv, ['--dry-run']));

if ($unknown !== []) {
    fwrite(STDERR, "Unknown option: {$unknown[0]}\n");
    fwrite(STDERR, "Usage: php bin/click-seed.php [--dry-run]\n");
    exit(1);
}

if ($dryRun) {
    // Listing rather than writing. Deliberately not a real run against a
    // throwaway store: the point of --dry-run is to see what *this* site would
    // gain, and that answer depends on what this site already has.
    echo "Would seed (existing items are skipped, never overwritten):\n\n";
    foreach (array_keys(ExampleSite::pages()) as $slug) {
        echo "  page/{$slug}\n";
    }
    foreach (array_keys(ExampleSite::teamMembers()) as $slug) {
        echo "  team-member/{$slug}\n";
    }
    foreach (array_keys(ExampleSite::posts()) as $slug) {
        echo "  post/{$slug}\n";
    }
    foreach (ExampleSite::media() as $picture) {
        echo "  media {$picture['name']}\n";
    }
    echo "  menu/main\n\n";
    echo "Run without --dry-run to create them.\n";
    exit(0);
}

// The real application, booted: the seeder writes through the same configured
// storage, the same locales and the same collection registration the running
// site uses. Building a parallel stack here would let the seeder succeed
// against a store the site does not read.
$app = new Application($basePath);
$app->boot();

$content = $app->getContentService();
$config = CoreConfig::load($basePath . '/config/core.json');

$pages = new PageService(
    $content,
    new JsonSectionTypeRepository($basePath . '/config/sections'),
    new SectionValidator(),
    $config->locales(),
);

$collections = new CollectionService(
    $content,
    new JsonCollectionTypeRepository($basePath . '/config/collections'),
    new SectionValidator(),
);

// The same media directory and the same declared crops the HTTP upload endpoint
// uses — a seeded picture that landed elsewhere, or without the site's crops,
// would not be the picture an editor gets when they upload one.
$media = new MediaService($basePath . '/content/media', crops: $config->mediaCrops());

$seeder = new SiteSeeder($content, $pages, $collections, $media);

// Attributed to the installer's admin account, so ownership on the seeded pages
// points at an account that exists and can edit them.
$report = $seeder->seed(['username' => 'admin', 'role' => 'admin']);

foreach ($report->createdItems() as $item) {
    echo "created  {$item}\n";
}
foreach ($report->skippedItems() as $item) {
    echo "exists   {$item}\n";
}
foreach ($report->failureMessages() as $message) {
    fwrite(STDERR, "FAILED   {$message}\n");
}

echo "\n";

if ($report->wasNoOp()) {
    echo "Nothing to do — the example site is already here.\n";
} else {
    printf("Seeded %d items.\n", count($report->createdItems()));
}

// A partial seed exits non-zero so a provisioning script does not treat a site
// missing half its example content as a success.
if ($report->hasFailures()) {
    fwrite(STDERR, sprintf("\n%d item(s) could not be created.\n", count($report->failureMessages())));
    exit(1);
}
