<?php

declare(strict_types=1);

namespace Click\Cms\Tests\Unit\Backup;

use Click\Cms\Application\Backup\BackupExporter;
use Click\Cms\Application\Backup\MediaPool;
use Click\Cms\Domain\Backup\BackupManifest;
use Click\Cms\Domain\Content\Content;
use Click\Cms\Domain\Storage\StorageInterface;
use Click\Cms\Domain\ValueObjects\ContentKey;
use Click\Cms\Domain\ValueObjects\Locale;

/**
 * Export, and specifically the bug it was written to kill.
 *
 * The predecessor walked `content/`, which holds documents on exactly one of the
 * four supported backends. A site on SQLite therefore produced an archive with
 * its pictures in it, no pages, no accounts, no menus — and a manifest reporting
 * success. The first test below is that bug, stated as an assertion.
 */
final class BackupExporterTest extends BackupTestCase
{
    private function exporter(
        StorageInterface $storage,
        string $backend = 'json',
        ?MediaPool $pool = null,
        bool $includeMedia = true,
        int $maxMediaBytes = 0,
    ): BackupExporter {
        return new BackupExporter(
            $storage,
            $this->mediaDir(),
            $backend,
            $pool,
            $includeMedia,
            $maxMediaBytes,
        );
    }

    private function archivePath(string $name = 'backup.zip'): string
    {
        return $this->base . '/' . $name;
    }

    /* ---------------------------------------------- storage-independence -- */

    /**
     * The bug. On a database backend the content directory holds no documents at
     * all, so an export that reads the filesystem produces an archive that would
     * restore an empty site — and says nothing.
     */
    public function testASqliteSitesDocumentsAreInTheArchive(): void
    {
        $storage = $this->sqliteStorage();
        $storage->save(Content::create(ContentKey::page('home'), ['title' => 'Welcome home']));
        $storage->save(Content::create(ContentKey::page('about'), ['title' => 'About']));
        $storage->save(Content::create(ContentKey::user('admin'), ['role' => 'admin']));

        $path = $this->archivePath();
        $manifest = $this->exporter($storage, 'sqlite')->export($path);

        $this->assertSame(3, $manifest->documentCount());
        $this->assertContains('content/page/en/home.json', $this->entryNames($path));

        $zip = $this->openArchive($path);
        $this->assertStringContainsString('Welcome home', (string) $zip->getFromName('content/page/en/home.json'));
        $zip->close();
    }

    /** The same site on flat files must produce the same archive contents. */
    public function testTheSameContentProducesTheSameEntriesOnEitherBackend(): void
    {
        $documents = [
            [ContentKey::page('home'), ['title' => 'Home']],
            [ContentKey::for('menu', 'main'), ['items' => []]],
            [ContentKey::for('post', 'hallo', Locale::fromString('de')), ['title' => 'Hallo']],
        ];

        $json = $this->jsonStorage('-a');
        $sqlite = $this->sqliteStorage('-a');
        foreach ($documents as [$key, $data]) {
            $json->save(Content::create($key, $data));
            $sqlite->save(Content::create($key, $data));
        }

        $fromJson = $this->archivePath('json.zip');
        $fromSqlite = $this->archivePath('sqlite.zip');
        $this->exporter($json, 'json')->export($fromJson);
        $this->exporter($sqlite, 'sqlite')->export($fromSqlite);

        $this->assertSame($this->entryNames($fromJson), $this->entryNames($fromSqlite));
    }

    public function testEveryTypeAndEveryLanguageIsIncluded(): void
    {
        $storage = $this->jsonStorage();
        $storage->save(Content::create(ContentKey::page('home')));
        $storage->save(Content::create(ContentKey::page('home', Locale::fromString('de'))));
        $storage->save(Content::create(ContentKey::user('admin')));
        $storage->save(Content::create(ContentKey::for('menu', 'main')));

        $path = $this->archivePath();
        $manifest = $this->exporter($storage)->export($path);

        $this->assertSame(4, $manifest->documentCount());
        $this->assertContains('content/page/de/home.json', $this->entryNames($path));
        $this->assertContains('content/user/en/admin.json', $this->entryNames($path));
    }

    public function testAnEmptySiteProducesAValidEmptyArchive(): void
    {
        $path = $this->archivePath();
        $manifest = $this->exporter($this->jsonStorage())->export($path);

        $this->assertSame(0, $manifest->documentCount());
        $this->assertContains('manifest.json', $this->entryNames($path));
    }

    /* --------------------------------------------------------- the manifest -- */

    public function testTheManifestSaysWhatTheArchiveIsAndWhereItCameFrom(): void
    {
        $storage = $this->jsonStorage();
        $storage->save(Content::create(ContentKey::page('home')));

        $path = $this->archivePath();
        $this->exporter($storage, 'postgres')->export($path, 1_800_000_000);
        $manifest = $this->manifestOf($path);

        $this->assertSame('click-cms', $manifest['generator']);
        $this->assertSame(BackupManifest::FORMAT_VERSION, $manifest['formatVersion']);
        $this->assertSame('postgres', $manifest['sourceBackend']);
        $this->assertSame(1, $manifest['counts']['documents']);
        $this->assertSame(date('c', 1_800_000_000), $manifest['createdAt']);
    }

    public function testEveryEntryIsRecordedWithItsChecksum(): void
    {
        $storage = $this->jsonStorage();
        $storage->save(Content::create(ContentKey::page('home'), ['title' => 'Home']));

        $path = $this->archivePath();
        $this->exporter($storage)->export($path);

        $zip = $this->openArchive($path);
        $bytes = (string) $zip->getFromName('content/page/en/home.json');
        $zip->close();

        $manifest = $this->manifestOf($path);
        $this->assertSame(hash('sha256', $bytes), $manifest['documents'][0]['sha256']);
        $this->assertSame(strlen($bytes), $manifest['documents'][0]['bytes']);
    }

    /* ---------------------------------------------------------------- media -- */

    public function testMediaGoesInsideTheArchiveWhenThereIsNoPool(): void
    {
        $this->writeMedia('photo.png', "\x89PNG\r\nfake");

        $path = $this->archivePath();
        $manifest = $this->exporter($this->jsonStorage())->export($path);

        $this->assertSame(1, $manifest->mediaCount());
        $this->assertFalse($manifest->isPooled());
        $this->assertContains('content/media/photo.png', $this->entryNames($path));

        $zip = $this->openArchive($path);
        $this->assertSame("\x89PNG\r\nfake", (string) $zip->getFromName('content/media/photo.png'));
        $zip->close();
    }

    public function testMediaGoesIntoThePoolWhenThereIsOne(): void
    {
        $this->writeMedia('photo.png', 'the picture');
        $pool = new MediaPool($this->base . '/data/backups/pool');

        $path = $this->archivePath();
        $manifest = $this->exporter($this->jsonStorage(), 'json', $pool)->export($path);

        $this->assertTrue($manifest->isPooled());
        $this->assertNotContains('content/media/photo.png', $this->entryNames($path));

        $reference = $manifest->poolReferences()[0];
        $this->assertSame('pool/' . hash('sha256', 'the picture') . '.png', $reference);
        $this->assertSame('the picture', file_get_contents((string) $pool->pathFor($reference)));
    }

    /**
     * The saving, stated directly: seven nights of an unchanged library cost one
     * copy of it, because the same bytes produce the same name.
     */
    public function testSevenBackupsOfUnchangedMediaStoreItOnce(): void
    {
        $this->writeMedia('photo.png', str_repeat('x', 4096));
        $pool = new MediaPool($this->base . '/data/backups/pool');
        $exporter = $this->exporter($this->jsonStorage(), 'json', $pool);

        for ($night = 1; $night <= 7; $night++) {
            $exporter->export($this->archivePath("night-{$night}.zip"));
        }

        $this->assertCount(1, $pool->entries());
        $this->assertSame(4096, $pool->bytesUsed());
    }

    /** A changed picture is new bytes and therefore a new entry; the old one stays. */
    public function testAChangedPictureBecomesASecondPoolEntry(): void
    {
        $pool = new MediaPool($this->base . '/data/backups/pool');
        $exporter = $this->exporter($this->jsonStorage(), 'json', $pool);

        $this->writeMedia('photo.png', 'first');
        $exporter->export($this->archivePath('one.zip'));

        $this->writeMedia('photo.png', 'second');
        $exporter->export($this->archivePath('two.zip'));

        $this->assertCount(2, $pool->entries());
    }

    /** Two identical pictures under different names are one set of bytes. */
    public function testTwoIdenticalPicturesShareOnePoolEntry(): void
    {
        $this->writeMedia('one.png', 'identical bytes');
        $this->writeMedia('two.png', 'identical bytes');
        $pool = new MediaPool($this->base . '/data/backups/pool');

        $manifest = $this->exporter($this->jsonStorage(), 'json', $pool)
            ->export($this->archivePath());

        $this->assertSame(2, $manifest->mediaCount());
        $this->assertCount(1, $pool->entries());
    }

    public function testMediaIsLeftOutEntirelyWhenTheSiteSaysSo(): void
    {
        $this->writeMedia('photo.png', 'bytes');

        $path = $this->archivePath();
        $manifest = $this->exporter($this->jsonStorage(), 'json', null, false)->export($path);

        $this->assertSame(0, $manifest->mediaCount());
        $this->assertSame([], $manifest->skippedMedia);
    }

    /**
     * The rendition cache is built on demand from the stored originals, so
     * backing it up would multiply the archive for files the site regenerates
     * for free.
     */
    public function testTheRenditionCacheIsNotBackedUp(): void
    {
        $this->writeMedia('photo.png', 'original');
        $this->writeMedia('photo-800.png', 'rendition', $this->mediaDir() . '/derived');

        $manifest = $this->exporter($this->jsonStorage())->export($this->archivePath());

        $this->assertSame(1, $manifest->mediaCount());
        $this->assertSame('photo.png', $manifest->media[0]['path']);
    }

    /**
     * A symlink planted in the media directory must not become a way to copy
     * `config/core.json` — or a private key — into an archive an administrator
     * will download.
     */
    public function testASymlinkOutOfTheMediaDirectoryIsNotFollowed(): void
    {
        file_put_contents($this->base . '/outside-secret.txt', 'symlinked-secret');
        if (!@symlink($this->base . '/outside-secret.txt', $this->mediaDir() . '/link.png')) {
            $this->markTestSkipped('The filesystem does not support symlinks.');
        }

        $path = $this->archivePath();
        $this->exporter($this->jsonStorage())->export($path);

        $zip = $this->openArchive($path);
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $this->assertStringNotContainsString('symlinked-secret', (string) $zip->getFromIndex($i));
        }
        $zip->close();
    }

    /* ----------------------------------------------------- the size ceiling -- */

    /**
     * The whole reason this feature was rebuilt: a backup that silently omitted
     * something reported success. A ceiling is the obvious place to reintroduce
     * that, so a file over it is *recorded*, with its size and the reason.
     */
    public function testAFileOverTheCeilingIsReportedAsSkippedRatherThanDropped(): void
    {
        $this->writeMedia('small.png', 'tiny');
        $this->writeMedia('huge.mp4', str_repeat('v', 5000));

        $manifest = $this->exporter($this->jsonStorage(), 'json', null, true, 1000)
            ->export($this->archivePath());

        $this->assertSame(1, $manifest->mediaCount());
        $this->assertCount(1, $manifest->skippedMedia);
        $this->assertSame('huge.mp4', $manifest->skippedMedia[0]['path']);
        $this->assertSame(5000, $manifest->skippedMedia[0]['bytes']);
        $this->assertStringContainsString('maxMediaBytes', $manifest->skippedMedia[0]['reason']);
    }

    public function testSkippedMediaIsInTheManifestOnDiskNotJustInMemory(): void
    {
        $this->writeMedia('huge.mp4', str_repeat('v', 5000));

        $path = $this->archivePath();
        $this->exporter($this->jsonStorage(), 'json', null, true, 1000)->export($path);

        $manifest = $this->manifestOf($path);
        $this->assertSame(1, $manifest['counts']['skippedMedia']);
        $this->assertSame('huge.mp4', $manifest['skippedMedia'][0]['path']);
    }

    public function testACeilingOfZeroMeansNoCeiling(): void
    {
        $this->writeMedia('huge.mp4', str_repeat('v', 5000));

        $manifest = $this->exporter($this->jsonStorage(), 'json', null, true, 0)
            ->export($this->archivePath());

        $this->assertSame(1, $manifest->mediaCount());
        $this->assertSame([], $manifest->skippedMedia);
    }

    /* --------------------------------------------------------------- atomicity -- */

    /**
     * Retention reads every archive in the directory. A half-written one counted
     * as a survivor would have unknown requirements and would suppress pool
     * pruning forever, so nothing may appear under the final name until it is
     * complete.
     */
    public function testNoTemporaryFileIsLeftBesideTheFinishedArchive(): void
    {
        $this->writeMedia('photo.png', 'bytes');
        $dir = $this->base . '/archives';
        mkdir($dir);

        $this->exporter($this->jsonStorage())->export($dir . '/backup.zip');

        $this->assertSame(['backup.zip'], array_values(array_diff(scandir($dir) ?: [], ['.', '..'])));
    }

    public function testEntriesAreInAStableOrderSoTwoBackupsAreComparable(): void
    {
        $storage = $this->jsonStorage();
        foreach (['charlie', 'alpha', 'bravo'] as $slug) {
            $storage->save(Content::create(ContentKey::page($slug)));
        }

        $manifest = $this->exporter($storage)->export($this->archivePath());

        $this->assertSame(
            ['content/page/en/alpha.json', 'content/page/en/bravo.json', 'content/page/en/charlie.json'],
            array_column($manifest->documents, 'entry')
        );
    }
}
