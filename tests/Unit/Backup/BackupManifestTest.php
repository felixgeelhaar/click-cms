<?php

declare(strict_types=1);

namespace Click\Cms\Tests\Unit\Backup;

use Click\Cms\Domain\Backup\BackupManifest;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * The manifest is the index a restore iterates, so parsing it is the step that
 * lets everything after it assume its inputs. These pin what it refuses.
 *
 * The archive being parsed may have come from anywhere — a file an administrator
 * was sent — so "it does not look like ours" has to be a refusal rather than a
 * best effort. Every case below is a manifest a restore must not act on.
 */
final class BackupManifestTest extends TestCase
{
    /** @return array<string, mixed> */
    private function validArray(): array
    {
        return BackupManifest::create(
            '2026-07-25T03:00:00+00:00',
            'sqlite',
            BackupManifest::MEDIA_POOLED,
            [[
                'entry' => 'content/page/en/home.json',
                'key' => 'page:en:home',
                'type' => 'page',
                'slug' => 'home',
                'locale' => 'en',
                'sha256' => str_repeat('a', 64),
                'bytes' => 412,
            ]],
            [[
                'path' => 'photo-a1b2.png',
                'sha256' => str_repeat('b', 64),
                'bytes' => 900,
                'entry' => null,
                'pool' => 'pool/' . str_repeat('b', 64) . '.png',
            ]],
            [['path' => 'huge.mp4', 'bytes' => 2147483648, 'reason' => 'too large']],
        )->toArray();
    }

    public function testAManifestRoundTripsThroughItsArrayForm(): void
    {
        $manifest = BackupManifest::fromArray($this->validArray());

        $this->assertSame(BackupManifest::FORMAT_VERSION, $manifest->formatVersion);
        $this->assertSame('sqlite', $manifest->sourceBackend);
        $this->assertTrue($manifest->isPooled());
        $this->assertSame(1, $manifest->documentCount());
        $this->assertSame(1, $manifest->mediaCount());
        $this->assertSame('page:en:home', $manifest->documents[0]['key']);
    }

    public function testTheGeneratorAndCountsAreWrittenOut(): void
    {
        $row = $this->validArray();

        $this->assertSame('click-cms', $row['generator']);
        $this->assertSame(1, $row['counts']['documents']);
        $this->assertSame(1, $row['counts']['media']);
        $this->assertSame(1, $row['counts']['skippedMedia']);
    }

    /**
     * The media a backup deliberately left out is recorded, not dropped. A
     * backup that quietly omitted the 2 GB video and reported success is the
     * failure this whole feature was rebuilt to prevent.
     */
    public function testMediaLeftOutIsCarriedWithItsSizeAndReason(): void
    {
        $manifest = BackupManifest::fromArray($this->validArray());

        $this->assertSame('huge.mp4', $manifest->skippedMedia[0]['path']);
        $this->assertSame(2147483648, $manifest->skippedMedia[0]['bytes']);
        $this->assertNotSame('', $manifest->skippedMedia[0]['reason']);
    }

    public function testPoolReferencesAreTheEntriesTheArchiveCannotDoWithout(): void
    {
        $manifest = BackupManifest::fromArray($this->validArray());

        $this->assertSame(['pool/' . str_repeat('b', 64) . '.png'], $manifest->poolReferences());
    }

    public function testAnEmbeddedArchiveNeedsNothingFromThePool(): void
    {
        $manifest = BackupManifest::create(
            '2026-07-25T03:00:00+00:00',
            'json',
            BackupManifest::MEDIA_EMBEDDED,
            [],
            [[
                'path' => 'photo.png',
                'sha256' => str_repeat('c', 64),
                'bytes' => 10,
                'entry' => 'content/media/photo.png',
                'pool' => null,
            ]],
        );

        $this->assertSame([], $manifest->poolReferences());
        $this->assertFalse($manifest->isPooled());
    }

    /* ------------------------------------------------------- what is refused -- */

    /**
     * Version 1 was the directory walk this replaced. Its archives contained no
     * documents at all on any database backend, so restoring one would silently
     * produce an empty site — the exact bug, re-run as a recovery.
     */
    public function testAnArchiveInAnOlderFormatIsRefusedRatherThanHalfUnderstood(): void
    {
        $row = $this->validArray();
        $row['formatVersion'] = 1;

        $this->expectException(InvalidArgumentException::class);
        BackupManifest::fromArray($row);
    }

    public function testAManifestWithNoFormatVersionIsRefused(): void
    {
        $row = $this->validArray();
        unset($row['formatVersion']);

        $this->expectException(InvalidArgumentException::class);
        BackupManifest::fromArray($row);
    }

    public function testAManifestThatDoesNotSayWhereItsMediaIsIsRefused(): void
    {
        $row = $this->validArray();
        $row['mediaStorage'] = 'somewhere';

        $this->expectException(InvalidArgumentException::class);
        BackupManifest::fromArray($row);
    }

    /**
     * The Zip Slip case at the manifest layer: an entry name is the thing a path
     * gets built from, so a doctored name must be refused before any joining.
     */
    public function testADocumentEntryThatCouldEscapeTheDestinationIsRefused(): void
    {
        $row = $this->validArray();
        $row['documents'][0]['entry'] = '../../public/shell.php';

        $this->expectException(InvalidArgumentException::class);
        BackupManifest::fromArray($row);
    }

    public function testAMediaPathThatCouldEscapeTheMediaDirectoryIsRefused(): void
    {
        $row = $this->validArray();
        $row['media'][0]['path'] = '../../../config/core.json';

        $this->expectException(InvalidArgumentException::class);
        BackupManifest::fromArray($row);
    }

    public function testAPoolReferenceThatIsNotADigestIsRefused(): void
    {
        $row = $this->validArray();
        $row['media'][0]['pool'] = 'pool/../../../etc/passwd';

        $this->expectException(InvalidArgumentException::class);
        BackupManifest::fromArray($row);
    }

    public function testAnEntryWithNoUsableChecksumIsRefused(): void
    {
        $row = $this->validArray();
        $row['documents'][0]['sha256'] = 'not-a-digest';

        $this->expectException(InvalidArgumentException::class);
        BackupManifest::fromArray($row);
    }

    public function testAnEntryWithNoUsableSizeIsRefused(): void
    {
        $row = $this->validArray();
        $row['documents'][0]['bytes'] = -1;

        $this->expectException(InvalidArgumentException::class);
        BackupManifest::fromArray($row);
    }

    /**
     * The counts are redundant with the lists, which is exactly why they are
     * checked: a truncated manifest that lost half its entries would otherwise
     * describe a smaller backup perfectly consistently.
     */
    public function testAManifestWhoseCountsDisagreeWithItsListsIsRefused(): void
    {
        $row = $this->validArray();
        array_pop($row['documents']);

        $this->expectException(InvalidArgumentException::class);
        BackupManifest::fromArray($row);
    }

    public function testAPooledMediaEntryWithNoPoolReferenceIsRefused(): void
    {
        $row = $this->validArray();
        unset($row['media'][0]['pool']);

        $this->expectException(InvalidArgumentException::class);
        BackupManifest::fromArray($row);
    }

    public function testAnEmbeddedMediaEntryWithNoArchiveEntryIsRefused(): void
    {
        $row = BackupManifest::create(
            '2026-07-25T03:00:00+00:00',
            'json',
            BackupManifest::MEDIA_EMBEDDED,
            [],
            [[
                'path' => 'photo.png',
                'sha256' => str_repeat('c', 64),
                'bytes' => 10,
                'entry' => 'content/media/photo.png',
                'pool' => null,
            ]],
        )->toArray();
        $row['media'][0]['entry'] = null;

        $this->expectException(InvalidArgumentException::class);
        BackupManifest::fromArray($row);
    }

    public function testCreatingAManifestWithAnUnknownMediaStorageThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        BackupManifest::create('now', 'json', 'elsewhere', [], []);
    }
}
