<?php

declare(strict_types=1);

namespace Click\Cms\Tests\Unit\Backup;

use Click\Cms\Application\Backup\BackupException;
use Click\Cms\Application\Backup\BackupExporter;
use Click\Cms\Application\Backup\BackupRestorer;
use Click\Cms\Application\Backup\MediaPool;
use Click\Cms\Domain\Content\Content;
use Click\Cms\Domain\Publishing\Publishable;
use Click\Cms\Domain\ValueObjects\ContentKey;
use Click\Cms\Domain\ValueObjects\Locale;
use Click\Cms\Infrastructure\Storage\JsonStorage;
use Click\Cms\Infrastructure\Storage\SqliteStorage;
use Click\Cms\Infrastructure\Storage\VersioningStorage;

/**
 * Restore: the cross-backend round trip, what it refuses, and what it will not
 * overwrite.
 *
 * The round trip is the feature. An archive taken off SQLite has to restore onto
 * flat files, because the reason to have backups at all is the day the machine
 * that held the database is gone.
 */
final class BackupRestorerTest extends BackupTestCase
{
    protected function tearDown(): void
    {
        // Collection types are registered process-wide at boot; a test that
        // registers one must not leak it into the next.
        Publishable::reset();
        parent::tearDown();
    }

    /** The site being backed up: SQLite, with pages, a menu, an account, a picture. */
    private function sourceSite(): SqliteStorage
    {
        $storage = $this->sqliteStorage();
        $storage->save(Content::create(ContentKey::page('home'), ['title' => 'Welcome home']));
        $storage->save(Content::create(ContentKey::page('about'), ['title' => 'About us']));
        $storage->save(Content::create(ContentKey::page('willkommen', Locale::fromString('de')), ['title' => 'Willkommen']));
        $storage->save(Content::create(ContentKey::for('menu', 'main'), ['items' => [['label' => 'Home']]]));
        $storage->save(Content::create(ContentKey::user('admin'), ['role' => 'admin', 'password' => 'hash']));
        $this->writeMedia('photo.png', 'the picture bytes');

        return $storage;
    }

    /** The site being restored into: flat files, empty. */
    private function targetSite(): VersioningStorage
    {
        return $this->versioning($this->jsonStorage('-target'), '-target');
    }

    private function targetMediaDir(): string
    {
        $dir = $this->base . '/content-target/media';
        if (!is_dir($dir)) {
            mkdir($dir, 0o775, true);
        }

        return $dir;
    }

    private function export(?MediaPool $pool = null, string $name = 'backup.zip'): string
    {
        $path = $this->base . '/' . $name;
        (new BackupExporter($this->sourceSite(), $this->mediaDir(), 'sqlite', $pool))->export($path);

        return $path;
    }

    /* ------------------------------------------------- the cross-backend trip -- */

    public function testASqliteBackupRestoresOntoAFlatFileSite(): void
    {
        $archive = $this->export();
        $target = $this->targetSite();

        $report = (new BackupRestorer($this->contentService($target), $this->targetMediaDir()))
            ->restore($archive);

        $this->assertFalse($report->hasFailures(), implode('; ', $report->failureMessages()));

        $this->assertSame('Welcome home', $target->find(ContentKey::page('home'))?->title());
        $this->assertSame('About us', $target->find(ContentKey::page('about'))?->title());
        $this->assertSame(
            'Willkommen',
            $target->find(ContentKey::page('willkommen', Locale::fromString('de')))?->title()
        );
        $this->assertNotNull($target->find(ContentKey::for('menu', 'main')));
        $this->assertNotNull($target->find(ContentKey::user('admin')));
    }

    /**
     * Every document is reachable from `types()` on the *new* backend afterwards
     * — which is the same property the export relied on, asserted from the other
     * end. A restore that left documents unreachable would back up to nothing on
     * the next run.
     */
    public function testTheRestoredSiteReportsTheSameTypesAsTheOriginal(): void
    {
        $source = $this->sourceSite();
        $archive = $this->base . '/backup.zip';
        (new BackupExporter($source, $this->mediaDir(), 'sqlite'))->export($archive);

        $target = $this->targetSite();
        (new BackupRestorer($this->contentService($target), $this->targetMediaDir()))->restore($archive);

        $this->assertSame($source->types(), $target->types());
    }

    /**
     * A saved page is a working copy and nothing more, so a restore that stopped
     * at save would return a site whose every page was an unpublished draft and
     * whose every address 404ed.
     */
    public function testRestoredPagesAreLiveAndNotLeftAsDrafts(): void
    {
        $archive = $this->export();
        $target = $this->targetSite();

        (new BackupRestorer($this->contentService($target), $this->targetMediaDir()))->restore($archive);

        // find() is the live document; a draft would not be there.
        $this->assertNotNull($target->find(ContentKey::page('home')));
        $this->assertCount(2, $target->findByType('page', Locale::default()));
    }

    /** Writing through the service means the version chain saw it. */
    public function testARestoreLeavesAHistoryTrail(): void
    {
        $archive = $this->export();
        $target = $this->targetSite();

        (new BackupRestorer($this->contentService($target), $this->targetMediaDir()))->restore($archive);

        $this->assertNotNull($target->draft(ContentKey::page('home')));
        $this->assertTrue($target->publicationOf(ContentKey::page('home'))->published);
    }

    public function testMediaFilesArriveWithTheirBytesIntact(): void
    {
        $archive = $this->export();
        $target = $this->targetSite();

        (new BackupRestorer($this->contentService($target), $this->targetMediaDir()))->restore($archive);

        $this->assertSame('the picture bytes', file_get_contents($this->targetMediaDir() . '/photo.png'));
    }

    public function testAPooledArchiveRestoresFromThePool(): void
    {
        $pool = new MediaPool($this->base . '/pool');
        $archive = $this->export($pool);
        $target = $this->targetSite();

        $report = (new BackupRestorer($this->contentService($target), $this->targetMediaDir(), $pool))
            ->restore($archive);

        $this->assertFalse($report->hasFailures(), implode('; ', $report->failureMessages()));
        $this->assertSame('the picture bytes', file_get_contents($this->targetMediaDir() . '/photo.png'));
    }

    /* ------------------------------------------------------- never overwrite -- */

    public function testAnExistingDocumentIsLeftAloneAndReported(): void
    {
        $archive = $this->export();
        $target = $this->targetSite();
        $target->save(Content::create(ContentKey::page('home'), ['title' => 'My newer work']));
        $target->publish(ContentKey::page('home'));

        $report = (new BackupRestorer($this->contentService($target), $this->targetMediaDir()))
            ->restore($archive);

        $this->assertSame('My newer work', $target->find(ContentKey::page('home'))?->title());
        $this->assertContains('page/en/home', $report->skippedItems());
        $this->assertContains('page/en/about', $report->restoredItems());
    }

    /**
     * An unpublished draft occupies the address just as firmly, and overwriting
     * one would destroy work that was never backed up in the first place.
     */
    public function testAnUnpublishedDraftIsAlsoLeftAlone(): void
    {
        $archive = $this->export();
        $target = $this->targetSite();
        // Saved but never published: nothing is live at this key.
        $target->save(Content::create(ContentKey::page('home'), ['title' => 'A draft in progress']));

        $report = (new BackupRestorer($this->contentService($target), $this->targetMediaDir()))
            ->restore($archive);

        $this->assertContains('page/en/home', $report->skippedItems());
        $this->assertSame('A draft in progress', $target->draft(ContentKey::page('home'))?->title());
    }

    public function testAnExistingMediaFileIsLeftAlone(): void
    {
        $archive = $this->export();
        $target = $this->targetSite();
        file_put_contents($this->targetMediaDir() . '/photo.png', 'a newer picture');

        $report = (new BackupRestorer($this->contentService($target), $this->targetMediaDir()))
            ->restore($archive);

        $this->assertSame('a newer picture', file_get_contents($this->targetMediaDir() . '/photo.png'));
        $this->assertContains('media photo.png', $report->skippedItems());
    }

    public function testOverwriteReplacesWhatIsThere(): void
    {
        $archive = $this->export();
        $target = $this->targetSite();
        $target->save(Content::create(ContentKey::page('home'), ['title' => 'My newer work']));
        $target->publish(ContentKey::page('home'));
        file_put_contents($this->targetMediaDir() . '/photo.png', 'a newer picture');

        $report = (new BackupRestorer($this->contentService($target), $this->targetMediaDir()))
            ->restore($archive, true);

        $this->assertSame('Welcome home', $target->find(ContentKey::page('home'))?->title());
        $this->assertSame('the picture bytes', file_get_contents($this->targetMediaDir() . '/photo.png'));
        $this->assertSame([], $report->skippedItems());
    }

    public function testRestoringTwiceIsANoOp(): void
    {
        $archive = $this->export();
        $target = $this->targetSite();
        $restorer = new BackupRestorer($this->contentService($target), $this->targetMediaDir());

        $restorer->restore($archive);
        $second = $restorer->restore($archive);

        $this->assertTrue($second->wasNoOp());
        $this->assertSame([], $second->restoredItems());
    }

    /* -------------------------------------------- nothing is written on refusal -- */

    /**
     * The ordering property the whole design rests on: verification runs to
     * completion first, so a bad archive costs nothing.
     */
    public function testATamperedArchiveIsRefusedWithoutWritingAnything(): void
    {
        $archive = $this->export();
        $this->replaceEntry($archive, 'content/page/en/about.json', '{"key":"page:en:about","data":{}}');

        $target = $this->targetSite();
        $restorer = new BackupRestorer($this->contentService($target), $this->targetMediaDir());

        try {
            $restorer->restore($archive);
            $this->fail('A tampered archive must be refused.');
        } catch (BackupException) {
            // expected
        }

        $this->assertSame([], $target->types(), 'Nothing may be written when an archive is refused.');
        $this->assertSame([], glob($this->targetMediaDir() . '/*') ?: []);
    }

    public function testATruncatedArchiveIsRefusedWithoutWritingAnything(): void
    {
        $archive = $this->export();
        $zip = $this->openArchive($archive);
        $zip->deleteName('content/page/en/home.json');
        $zip->close();

        $target = $this->targetSite();

        try {
            (new BackupRestorer($this->contentService($target), $this->targetMediaDir()))->restore($archive);
            $this->fail('A truncated archive must be refused.');
        } catch (BackupException) {
            // expected
        }

        $this->assertSame([], $target->types());
    }

    /**
     * Zip Slip, end to end. A manifest naming `../../public/shell.php` must be
     * refused before any path exists, and nothing may be written outside the
     * media directory.
     */
    public function testAnArchiveWhoseManifestPointsOutOfTheSiteIsRefused(): void
    {
        $archive = $this->export();
        $this->rewriteManifest($archive, static function (array $manifest): array {
            $manifest['media'][0]['path'] = '../../public/shell.php';
            return $manifest;
        });

        $target = $this->targetSite();

        try {
            (new BackupRestorer($this->contentService($target), $this->targetMediaDir()))->restore($archive);
            $this->fail('An archive that names a path outside the site must be refused.');
        } catch (BackupException) {
            // expected
        }

        $this->assertFileDoesNotExist($this->base . '/public/shell.php');
        $this->assertFileDoesNotExist($this->base . '/content-target/shell.php');
    }

    /**
     * The document's own key decides where it lands, so a manifest that says
     * otherwise is a manifest somebody edited — and believing the index over the
     * document is how a restore writes a page somewhere nobody expected.
     */
    public function testADocumentWhoseKeyDisagreesWithTheManifestIsRefusedIndividually(): void
    {
        $archive = $this->export();

        // Rewrite the entry and its manifest record together, so the archive
        // verifies but the document inside claims a different key.
        $zip = $this->openArchive($archive);
        $original = json_decode((string) $zip->getFromName('content/page/en/home.json'), true);
        $zip->close();
        $original['key'] = 'page:en:somewhere-else';
        $bytes = (string) json_encode($original);

        $this->replaceEntry($archive, 'content/page/en/home.json', $bytes);
        $this->rewriteManifest($archive, static function (array $manifest) use ($bytes): array {
            foreach ($manifest['documents'] as $i => $document) {
                if ($document['entry'] === 'content/page/en/home.json') {
                    $manifest['documents'][$i]['sha256'] = hash('sha256', $bytes);
                    $manifest['documents'][$i]['bytes'] = strlen($bytes);
                }
            }
            return $manifest;
        });

        $target = $this->targetSite();
        $report = (new BackupRestorer($this->contentService($target), $this->targetMediaDir()))
            ->restore($archive);

        $this->assertTrue($report->hasFailures());
        $this->assertNull($target->find(ContentKey::page('somewhere-else')));
        // And the rest of the archive still went in: one doctored document is
        // not a reason to abandon a recovery.
        $this->assertNotNull($target->find(ContentKey::page('about')));
    }

    /* ------------------------------------------------------ what was left out -- */

    /**
     * A file the backup never held cannot be restored, and an operator hunting a
     * missing picture must be told that here rather than by reading a manifest.
     */
    public function testMediaTheBackupSkippedIsReportedAsAFailureOnRestore(): void
    {
        $this->writeMedia('huge.mp4', str_repeat('v', 5000));
        $archive = $this->base . '/backup.zip';
        (new BackupExporter($this->sourceSite(), $this->mediaDir(), 'sqlite', null, true, 1000))
            ->export($archive);

        $target = $this->targetSite();
        $report = (new BackupRestorer($this->contentService($target), $this->targetMediaDir()))
            ->restore($archive);

        $this->assertTrue($report->hasFailures());
        $this->assertStringContainsString('huge.mp4', implode("\n", $report->failureMessages()));
        $this->assertStringContainsString('was not in the backup', implode("\n", $report->failureMessages()));
    }

    /* --------------------------------------------------------- collections -- */

    /**
     * A collection entry is publishable only because the site registered its
     * type at boot, so restoring one has to publish it too — otherwise a
     * restored blog is a set of drafts.
     */
    public function testARegisteredCollectionTypeIsPublishedOnRestore(): void
    {
        Publishable::register(['post']);

        $source = $this->sqliteStorage();
        $source->save(Content::create(ContentKey::for('post', 'hello'), ['title' => 'Hello']));

        $archive = $this->base . '/backup.zip';
        (new BackupExporter($source, $this->mediaDir(), 'sqlite'))->export($archive);

        $target = $this->targetSite();
        (new BackupRestorer($this->contentService($target), $this->targetMediaDir()))->restore($archive);

        $this->assertNotNull($target->find(ContentKey::for('post', 'hello')));
    }

    /* ------------------------------------------------------------- reporting -- */

    public function testTheReportSeparatesRestoredSkippedAndFailed(): void
    {
        $archive = $this->export();
        $target = $this->targetSite();
        $target->save(Content::create(ContentKey::page('home'), ['title' => 'Mine']));
        $target->publish(ContentKey::page('home'));

        $report = (new BackupRestorer($this->contentService($target), $this->targetMediaDir()))
            ->restore($archive);

        $summary = $report->toArray();
        $this->assertSame(1, $summary['counts']['skipped']);
        $this->assertGreaterThan(0, $summary['counts']['restored']);
        $this->assertSame(0, $summary['counts']['failed']);
    }

    public function testARestoreIntoAnUnpreparedMediaDirectoryStillWorks(): void
    {
        $archive = $this->export();
        $target = $this->targetSite();
        $dir = $this->base . '/content-fresh/media';

        $report = (new BackupRestorer($this->contentService($target), $dir))->restore($archive);

        $this->assertFalse($report->hasFailures(), implode('; ', $report->failureMessages()));
        $this->assertFileExists($dir . '/photo.png');
    }

    /** A plain backend with no version chain restores just as well. */
    public function testRestoringOntoUndecoratedStorageWorks(): void
    {
        $archive = $this->export();
        $target = new JsonStorage($this->base . '/content-plain');

        (new BackupRestorer($this->contentService($target), $this->targetMediaDir()))->restore($archive);

        $this->assertSame('Welcome home', $target->find(ContentKey::page('home'))?->title());
    }
}
