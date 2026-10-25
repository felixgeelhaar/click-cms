#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Inspect a WordPress WXR export or import its page/post text as working copies.
 *
 *   php bin/click-wordpress-import.php export.xml            # dry run (default)
 *   php bin/click-wordpress-import.php --dry-run export.xml  # explicit dry run
 *   php bin/click-wordpress-import.php --apply export.xml    # create drafts
 *
 * Media is never downloaded. Attachments and legacy-slug redirect candidates
 * are reported for an operator to handle separately.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require __DIR__ . '/../vendor/autoload.php';

use Click\Cms\Application\Collection\CollectionService;
use Click\Cms\Application\Config\CoreConfig;
use Click\Cms\Application\Config\LayeredCoreConfig;
use Click\Cms\Application\Content\PageService;
use Click\Cms\Application\Migration\WordPressWxrImporter;
use Click\Cms\Application\Migration\WordPressWxrInspector;
use Click\Cms\Application\Migration\WordPressWxrLocalePreflight;
use Click\Cms\Core\Application;
use Click\Cms\Domain\Schema\SectionValidator;
use Click\Cms\Infrastructure\Collection\JsonCollectionTypeRepository;
use Click\Cms\Infrastructure\Schema\JsonSectionTypeRepository;

$mode = 'dry-run';
$siteOption = null;
$localeOption = null;
$path = null;
foreach (array_slice($_SERVER['argv'], 1) as $argument) {
    if ($argument === '--dry-run') { $mode = 'dry-run'; continue; }
    if ($argument === '--apply') { $mode = 'apply'; continue; }
    if (str_starts_with($argument, '--site=')) { $siteOption = substr($argument, 7); continue; }
    if (str_starts_with($argument, '--locale=')) { $localeOption = substr($argument, 9); continue; }
    if (str_starts_with($argument, '-')) {
        fwrite(STDERR, "Unknown option: {$argument}\n");
        exit(2);
    }
    if ($path !== null) {
        fwrite(STDERR, "Usage: php bin/click-wordpress-import.php [--dry-run|--apply] [--site=ID] [--locale=TAG] <export.xml>\n");
        exit(2);
    }
    $path = $argument;
}
if ($path === null) {
    fwrite(STDERR, "Usage: php bin/click-wordpress-import.php [--dry-run|--apply] [--site=ID] [--locale=TAG] <export.xml>\n");
    exit(2);
}
if (!is_file($path) || !is_readable($path)) {
    fwrite(STDERR, "Cannot read WordPress export: {$path}\n");
    exit(2);
}
if (filesize($path) > 64 * 1024 * 1024) {
    fwrite(STDERR, "Export exceeds the 64 MiB limit. Split the export and inspect each file.\n");
    exit(2);
}

$root = dirname(__DIR__);
try {
    $report = (new WordPressWxrInspector())->inspect((string) file_get_contents($path));
    $report['mode'] = $mode;
    $report['locale'] = null;
    $report['scope'] = [
        'supported' => ['pages as a single rich-text section', 'posts as the configured post collection'],
        'allImportedAs' => 'unpublished working copies',
        'remoteMediaFetch' => false,
        'redirectsCreated' => false,
    ];
    $report['targetPreflightPerformed'] = false;
    $report['preflightNote'] = $mode === 'dry-run'
        ? 'Source-only inspection: target schemas, permissions, and existing slugs are not queried.'
        : 'Apply validates each item against the selected site as it writes. The import may be partial; inspect skipped records and back up first.';
    if ($mode === 'apply') {
        $app = new Application($root, $siteOption);
        $app->boot();
        $siteRoot = $app->siteRoot();
        $installConfig = CoreConfig::load($root . '/config/core.json');
        $effectiveConfig = $installConfig;
        $siteConfigPath = $siteRoot . '/config/core.json';
        if ($siteRoot !== $root && is_file($siteConfigPath)) {
            $siteConfig = json_decode((string) file_get_contents($siteConfigPath), true);
            if (!is_array($siteConfig)) throw new RuntimeException('The selected site core configuration is invalid JSON.');
            $effectiveConfig = CoreConfig::fromArray((new LayeredCoreConfig())->effective(
                json_decode((string) file_get_contents($root . '/config/core.json'), true) ?: [],
                $siteConfig,
            ));
        }
        $schemaPath = static fn (string $kind): string => is_dir($siteRoot . '/config/' . $kind)
            ? $siteRoot . '/config/' . $kind
            : $root . '/config/' . $kind;
        $locale = WordPressWxrLocalePreflight::validate(
            $effectiveConfig,
            $localeOption ?? ($effectiveConfig->locales()[0]->code ?? 'en'),
        );
        $content = $app->getContentService();
        $pages = new PageService(
            $content,
            new JsonSectionTypeRepository($schemaPath('sections')),
            new SectionValidator(),
            $effectiveConfig->locales(),
        );
        $collections = new CollectionService(
            $content,
            new JsonCollectionTypeRepository($schemaPath('collections')),
            new SectionValidator(),
        );
        $result = $app->runAs('wordpress-import', fn (): array => (new WordPressWxrImporter($pages, $collections))
            ->import($report['items'], ['username' => 'wordpress-import', 'role' => 'admin'], $locale));
        $report['locale'] = $locale;
        $report['import'] = $result;
        $report['writesPerformed'] = $result['created'] !== [];
    }
    fwrite(STDOUT, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n");
} catch (Throwable $error) {
    fwrite(STDERR, "WordPress import failed: {$error->getMessage()}\n");
    exit(1);
}
