#!/usr/bin/env php
<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Click\Cms\Application\Backup\BackupExporter;
use Click\Cms\Application\Backup\BackupRestorer;
use Click\Cms\Application\Backup\BackupSource;
use Click\Cms\Application\Config\CoreConfig;
use Click\Cms\Application\Content\ContentService;
use Click\Cms\Domain\Content\Content;
use Click\Cms\Domain\ValueObjects\ContentKey;
use Click\Cms\Domain\ValueObjects\Locale;

// Deliberately accepts no target path or production credentials.
$base = sys_get_temp_dir() . '/click-restore-drill-' . bin2hex(random_bytes(12));
$remove = static function (string $path) use (&$remove): void {
    if (!is_dir($path) || is_link($path)) {
        @unlink($path);
        return;
    }
    foreach (scandir($path) ?: [] as $item) {
        if ($item !== '.' && $item !== '..') {
            $remove($path . '/' . $item);
        }
    }
    @rmdir($path);
};
$check = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};
$exitCode = 0;
try {
    mkdir($base, 0700);
    foreach (['source/data', 'source/content/media', 'target/data', 'target/content/media'] as $dir) {
        mkdir($base . '/' . $dir, 0700, true);
    }
    $source = BackupSource::create(CoreConfig::fromArray(['core' => ['storage' => [
        'backend' => 'sqlite', 'sqlite' => ['path' => 'data/content.sqlite'],
    ]]]), $base . '/source');
    $home = ContentKey::page('home');
    $secret = ContentKey::page('private');
    $german = ContentKey::page('home', Locale::fromString('de'));
    $source->save(Content::create($home, ['title' => 'Public title']));
    $source->publish($home);
    $source->save(Content::create($home, ['title' => 'Unpublished revision']));
    $source->save(Content::create($secret, ['title' => 'Private draft']));
    $source->save(Content::create($german, ['title' => 'Willkommen']));
    $source->publish($german);
    $media = random_bytes(1024);
    file_put_contents($base . '/source/content/media/fixture.png', $media);
    (new BackupExporter($source, $base . '/source/content/media', 'sqlite'))->export($base . '/portable.zip');
    $target = BackupSource::create(CoreConfig::fromArray([]), $base . '/target');
    $restorer = new BackupRestorer(new ContentService($target), $base . '/target/content/media');
    $report = $restorer->restore($base . '/portable.zip');
    $check(!$report->hasFailures(), implode('; ', $report->failureMessages()));
    $check($target->find($home)?->title() === 'Public title', 'Live content changed.');
    $check($target->draft($home)?->title() === 'Unpublished revision', 'Private revision lost.');
    $check($target->find($secret) === null && $target->draft($secret)?->title() === 'Private draft', 'Draft publication boundary failed.');
    $check($target->find($german)?->title() === 'Willkommen', 'Translation lost.');
    $check(hash_file('sha256', $base . '/target/content/media/fixture.png') === hash('sha256', $media), 'Media bytes changed.');
    $check($restorer->restore($base . '/portable.zip')->wasNoOp(), 'Repeat restore was not a no-op.');
    echo "PASS: isolated SQLite-to-JSON recovery; live content, private revision, draft, locale, media and repeat restore verified.\n";
} catch (Throwable $error) {
    fwrite(STDERR, 'FAIL: ' . $error->getMessage() . "\n");
    $exitCode = 1;
} finally {
    if (is_dir($base)) {
        $remove($base);
    }
}

exit($exitCode);
