<?php

declare(strict_types=1);

namespace Click\Cms\Tests\Unit\Backup;

use Click\Cms\Application\Backup\BackupService;
use Click\Cms\Application\Backup\BackupStore;
use Click\Cms\Domain\Content\Content;
use Click\Cms\Domain\ValueObjects\ContentKey;
use ZipArchive;

/**
 * The backup directory, and retention applied to real files.
 *
 * {@see \Click\Cms\Tests\Unit\Backup\RetentionPlanTest} pins the decision; this
 * pins that the decision is carried out against archives and pool files that
 * actually exist — including the case the plan exists for, where two archives
 * share one picture and only one of them is pruned.
 */
final class BackupStoreTest extends BackupTestCase
{
    private function store(): BackupStore
    {
        return new BackupStore($this->base . '/data/backups');
    }

    private function service(BackupStore $store): BackupService
    {
        $storage = $this->jsonStorage();
        $storage->save(Content::create(ContentKey::page('home'), ['title' => 'Home']));

        return new BackupService($store, $storage, $this->mediaDir(), 'json');
    }

    /* --------------------------------------------------------------- naming -- */

    public function testAnArchiveIsNamedForTheMomentItWasTaken(): void
    {
        $this->assertSame('2026-07-25T030000Z.zip', $this->store()->nameFor(1784948400));
    }

    /**
     * Names sort chronologically as strings, which is what lets retention pick
     * the newest without trusting a modification time that a copy or a restore
     * can change.
     */
    public function testNamesSortIntoChronologicalOrder(): void
    {
        $store = $this->store();
        $names = [$store->nameFor(1784948400), $store->nameFor(1785034800), $store->nameFor(1784862000)];

        $sorted = $names;
        sort($sorted, SORT_STRING);

        $this->assertSame([$names[2], $names[0], $names[1]], $sorted);
    }

    public function testTwoBackupsInOneSecondDoNotCollide(): void
    {
        $store = $this->store();
        $store->ensureDir();

        $first = $store->nameFor(1784948400);
        touch($store->directory() . '/' . $first);
        $second = $store->nameFor(1784948400);

        $this->assertNotSame($first, $second);
        $this->assertNotNull($store->pathFor($first));
    }

    /**
     * An archive name reaches this from an HTTP query string and from a command
     * line, so it must not be able to name anything but an archive.
     */
    public function testANameThatIsNotAnArchiveNameResolvesToNothing(): void
    {
        $store = $this->store();
        $store->ensureDir();
        file_put_contents($this->base . '/data/backups/notes.txt', 'x');

        $this->assertNull($store->pathFor('../../config/core.json'));
        $this->assertNull($store->pathFor('/etc/passwd'));
        $this->assertNull($store->pathFor('notes.txt'));
        $this->assertNull($store->pathFor('2026-07-25T030000Z.zip/../../x'));
    }

    /** A half-written archive is not an archive, so retention never counts one. */
    public function testAPartiallyWrittenArchiveIsNotListed(): void
    {
        $store = $this->store();
        $store->ensureDir();
        touch($store->directory() . '/2026-07-25T030000Z.zip.abc123.tmp');

        $this->assertSame([], $store->archives());
    }

    /* ------------------------------------------------------------ retention -- */

    public function testRetentionKeepsTheNewestAndDeletesTheRest(): void
    {
        $store = $this->store();
        $service = $this->service($store);

        for ($day = 1; $day <= 5; $day++) {
            $service->takeBackup(99, 1784948400 + $day * 86400);
        }
        $this->assertCount(5, $store->archives());

        $store->prune(2);

        $this->assertCount(2, $store->archives());
        $this->assertSame(
            ['2026-07-29T030000Z.zip', '2026-07-30T030000Z.zip'],
            $store->archives()
        );
    }

    /**
     * The case that matters. Two nightly backups of an unchanged library share
     * one pool entry; pruning the older one must not take the picture with it,
     * because the survivor still needs it and nothing would say so.
     */
    public function testPruningOneOfTwoArchivesLeavesTheirSharedPictureAlone(): void
    {
        $this->writeMedia('photo.png', 'the same picture both nights');

        $store = $this->store();
        $service = $this->service($store);

        $service->takeBackup(99, 1784948400);
        $service->takeBackup(99, 1785034800);

        $this->assertCount(1, $store->pool()->entries(), 'The two nights should share one pool entry.');

        $result = $store->prune(1);

        $this->assertCount(1, $result['archives']);
        $this->assertSame([], $result['poolEntries']);
        $this->assertCount(1, $store->pool()->entries());

        // And the survivor is still restorable, which is the property that was
        // actually at stake.
        $surviving = $store->archives()[0];
        $manifest = $store->manifestOf($surviving);
        $this->assertNotNull($manifest);
        $this->assertTrue($store->pool()->has($manifest->poolReferences()[0]));
    }

    public function testAPictureNoSurvivingArchiveNeedsIsFreed(): void
    {
        $store = $this->store();
        $service = $this->service($store);

        $this->writeMedia('photo.png', 'the first picture');
        $service->takeBackup(99, 1784948400);

        $this->writeMedia('photo.png', 'a replacement picture');
        $service->takeBackup(99, 1785034800);

        $this->assertCount(2, $store->pool()->entries());

        $result = $store->prune(1);

        $this->assertCount(1, $result['poolEntries']);
        $this->assertCount(1, $store->pool()->entries());
    }

    /**
     * A surviving archive that will not say what it needs makes the pool
     * un-prunable for that run. A slightly larger pool beats a silently broken
     * backup.
     */
    public function testAnUnreadableSurvivingArchiveStopsThePoolBeingPruned(): void
    {
        $this->writeMedia('photo.png', 'a picture');

        $store = $this->store();
        $service = $this->service($store);
        $service->takeBackup(99, 1784948400);

        // A second archive whose manifest is nonsense — a corrupt file, or one
        // written by something else.
        $broken = $store->directory() . '/2026-07-30T030000Z.zip';
        $zip = new ZipArchive();
        $zip->open($broken, ZipArchive::CREATE);
        $zip->addFromString('manifest.json', 'not json');
        $zip->close();

        $result = $store->prune(2);

        $this->assertTrue($result['refused']);
        $this->assertSame([], $result['poolEntries']);
        $this->assertCount(1, $store->pool()->entries());
    }

    /** A file in the pool that this code did not write is left for a human. */
    public function testAnUnrecognisedFileInThePoolIsNeitherListedNorDeleted(): void
    {
        $store = $this->store();
        $store->ensureDir();
        mkdir($store->directory() . '/pool', 0o775, true);
        file_put_contents($store->directory() . '/pool/README.txt', 'left by an operator');

        $store->prune(1);

        $this->assertSame([], $store->pool()->entries());
        $this->assertFileExists($store->directory() . '/pool/README.txt');
    }

    /* -------------------------------------------------------------- listing -- */

    public function testTheListingDescribesEachArchiveNewestFirst(): void
    {
        $this->writeMedia('photo.png', 'a picture');
        $store = $this->store();
        $service = $this->service($store);

        $service->takeBackup(99, 1784948400);
        $service->takeBackup(99, 1785034800);

        $listing = $store->listing();

        $this->assertCount(2, $listing);
        $this->assertSame('2026-07-26T030000Z.zip', $listing[0]['name']);
        $this->assertTrue($listing[0]['readable']);
        $this->assertSame(1, $listing[0]['documents']);
        $this->assertSame(1, $listing[0]['media']);
        $this->assertSame('json', $listing[0]['sourceBackend']);
        $this->assertSame('pool', $listing[0]['mediaStorage']);
        $this->assertGreaterThan(0, $listing[0]['bytes']);
    }

    /**
     * An archive that is there and cannot be restored is a different problem
     * from having no backup, and an administrator has to be able to see the
     * difference.
     */
    public function testAnUnreadableArchiveIsListedAsUnreadableRatherThanHidden(): void
    {
        $store = $this->store();
        $store->ensureDir();
        file_put_contents($store->directory() . '/2026-07-25T030000Z.zip', 'not a zip at all');

        $listing = $store->listing();

        $this->assertCount(1, $listing);
        $this->assertFalse($listing[0]['readable']);
        $this->assertNull($listing[0]['documents']);
    }

    public function testAnEmptyDirectoryListsNothing(): void
    {
        $this->assertSame([], $this->store()->listing());
        $this->assertSame([], $this->store()->archives());
    }
}
