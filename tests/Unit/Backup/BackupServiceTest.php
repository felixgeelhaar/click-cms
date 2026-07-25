<?php

declare(strict_types=1);

namespace Click\Cms\Tests\Unit\Backup;

use Click\Cms\Application\Backup\BackupException;
use Click\Cms\Application\Backup\BackupRestorer;
use Click\Cms\Application\Backup\BackupService;
use Click\Cms\Application\Backup\BackupStore;
use Click\Cms\Application\Backup\BackupVerifier;
use Click\Cms\Domain\Backup\BackupManifest;
use Click\Cms\Domain\Content\Content;
use Click\Cms\Domain\ValueObjects\ContentKey;

/**
 * Taking a backup end to end, including the one operation that lets a retained
 * archive leave the machine it protects.
 *
 * Nightly archives keep their media in a shared pool, which makes them cheap and
 * makes them inseparable from this installation. An off-site copy has to be
 * self-contained, or the backup is only useful for the failures that leave the
 * server intact — which are not the ones anybody worries about.
 */
final class BackupServiceTest extends BackupTestCase
{
    private function service(?BackupStore $store = null): BackupService
    {
        $storage = $this->jsonStorage();
        $storage->save(Content::create(ContentKey::page('home'), ['title' => 'Home']));
        $storage->save(Content::create(ContentKey::user('admin'), ['role' => 'admin']));
        $this->writeMedia('photo.png', 'the picture bytes');

        return new BackupService(
            $store ?? new BackupStore($this->base . '/data/backups'),
            $storage,
            $this->mediaDir(),
            'json',
        );
    }

    public function testTakingABackupWritesAPooledArchiveAndPrunes(): void
    {
        $store = new BackupStore($this->base . '/data/backups');
        $result = $this->service($store)->takeBackup(7, 1784948400);

        $this->assertSame('2026-07-25T030000Z.zip', $result['name']);
        $this->assertSame(2, $result['manifest']->documentCount());
        $this->assertTrue($result['manifest']->isPooled());
        $this->assertSame([], $result['pruned']['archives']);
        $this->assertFalse($result['pruned']['refused']);
    }

    /**
     * The new archive is on disk with a finished manifest before retention looks
     * at anything, so its pool entries are live from the first moment they could
     * be considered for deletion.
     */
    public function testTheArchiveJustTakenIsNeverPrunedByItsOwnRun(): void
    {
        $store = new BackupStore($this->base . '/data/backups');
        $service = $this->service($store);

        $result = $service->takeBackup(1, 1784948400);

        $this->assertSame([$result['name']], $store->archives());
        $this->assertCount(1, $store->pool()->entries());
        $this->assertSame([], $result['pruned']['poolEntries']);
    }

    public function testAPortableArchiveCarriesItsMediaInside(): void
    {
        $path = $this->base . '/download.zip';
        $manifest = $this->service()->exportPortable($path);

        $this->assertFalse($manifest->isPooled());
        $this->assertContains('content/media/photo.png', $this->entryNames($path));

        // And it verifies with no pool at all, which is what "portable" means.
        $this->assertSame(2, (new BackupVerifier(null))->verify($path)->documentCount());
    }

    /* -------------------------------------------------- getting one off-site -- */

    public function testAPooledArchiveCanBeTurnedIntoASelfContainedOne(): void
    {
        $store = new BackupStore($this->base . '/data/backups');
        $result = $this->service($store)->takeBackup(7, 1784948400);

        $copy = $this->base . '/portable.zip';
        $manifest = $this->service($store)->exportPortableCopy($result['name'], $copy);

        $this->assertSame(BackupManifest::MEDIA_EMBEDDED, $manifest->mediaStorage);
        $this->assertSame([], $manifest->poolReferences());
        $this->assertContains('content/media/photo.png', $this->entryNames($copy));

        // Verifiable with no pool — the property the copy exists for.
        (new BackupVerifier(null))->verify($copy);
    }

    /**
     * The copy is the same backup in a different wrapper. Re-dating it would make
     * an off-site copy claim to be more recent than the site it holds.
     */
    public function testAPortableCopyKeepsTheOriginalDateAndSourceBackend(): void
    {
        $store = new BackupStore($this->base . '/data/backups');
        $result = $this->service($store)->takeBackup(7, 1784948400);

        $copy = $this->base . '/portable.zip';
        $manifest = $this->service($store)->exportPortableCopy($result['name'], $copy);

        $this->assertSame($result['manifest']->createdAt, $manifest->createdAt);
        $this->assertSame('json', $manifest->sourceBackend);
    }

    /** And it restores, on an installation that has never seen this pool. */
    public function testAPortableCopyRestoresWithNoPoolPresent(): void
    {
        $store = new BackupStore($this->base . '/data/backups');
        $result = $this->service($store)->takeBackup(7, 1784948400);

        $copy = $this->base . '/portable.zip';
        $this->service($store)->exportPortableCopy($result['name'], $copy);

        $target = $this->versioning($this->jsonStorage('-elsewhere'), '-elsewhere');
        $mediaDir = $this->base . '/content-elsewhere/media';

        $report = (new BackupRestorer($this->contentService($target), $mediaDir, null))->restore($copy);

        $this->assertFalse($report->hasFailures(), implode('; ', $report->failureMessages()));
        $this->assertSame('Home', $target->find(ContentKey::page('home'))?->title());
        $this->assertSame('the picture bytes', file_get_contents($mediaDir . '/photo.png'));
    }

    /**
     * A corrupt backup faithfully converted into a portable corrupt backup is
     * worse than an error, because it will be carried off-site and trusted.
     */
    public function testACorruptArchiveIsNotCopied(): void
    {
        $store = new BackupStore($this->base . '/data/backups');
        $result = $this->service($store)->takeBackup(7, 1784948400);

        $this->replaceEntry(
            (string) $store->pathFor($result['name']),
            'content/page/en/home.json',
            'not the document it was'
        );

        $copy = $this->base . '/portable.zip';

        try {
            $this->service($store)->exportPortableCopy($result['name'], $copy);
            $this->fail('A corrupt archive must not be copied.');
        } catch (BackupException) {
            // expected
        }

        $this->assertFileDoesNotExist($copy);
    }

    public function testCopyingAnArchiveThatIsNotThereIsRefused(): void
    {
        $this->expectExceptionMessage('There is no backup called');
        $this->service()->exportPortableCopy('2026-01-01T000000Z.zip', $this->base . '/x.zip');
    }

    /** An archive name from a caller must not be able to name anything else. */
    public function testCopyingRefusesANameThatIsNotAnArchiveName(): void
    {
        $this->expectException(BackupException::class);
        $this->service()->exportPortableCopy('../../config/core.json', $this->base . '/x.zip');
    }
}
