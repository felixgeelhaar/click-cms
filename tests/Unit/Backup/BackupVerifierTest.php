<?php

declare(strict_types=1);

namespace Click\Cms\Tests\Unit\Backup;

use Click\Cms\Application\Backup\BackupException;
use Click\Cms\Application\Backup\BackupExporter;
use Click\Cms\Application\Backup\BackupVerifier;
use Click\Cms\Application\Backup\MediaPool;
use Click\Cms\Domain\Content\Content;
use Click\Cms\Domain\ValueObjects\ContentKey;
use ZipArchive;

/**
 * Verification: everything a restore refuses, and it refuses all of it before
 * writing a byte.
 *
 * An archive is a file. It may have been downloaded over a flaky link, sat on a
 * disk for a year, been emailed by somebody, or been edited by somebody. A
 * restore that discovered any of that halfway through would leave an
 * installation that is neither the site it was nor the site the backup held.
 */
final class BackupVerifierTest extends BackupTestCase
{
    private function archive(?MediaPool $pool = null, string $name = 'backup.zip'): string
    {
        $storage = $this->jsonStorage();
        $storage->save(Content::create(ContentKey::page('home'), ['title' => 'Home']));
        $storage->save(Content::create(ContentKey::page('about'), ['title' => 'About']));
        $this->writeMedia('photo.png', 'the picture bytes');

        $path = $this->base . '/' . $name;
        (new BackupExporter($storage, $this->mediaDir(), 'json', $pool))->export($path);

        return $path;
    }

    public function testAGoodArchiveVerifies(): void
    {
        $manifest = (new BackupVerifier())->verify($this->archive());

        $this->assertSame(2, $manifest->documentCount());
        $this->assertSame(1, $manifest->mediaCount());
    }

    public function testAFileThatIsNotAnArchiveIsRefused(): void
    {
        file_put_contents($this->base . '/not-a-zip.zip', 'hello');

        $this->expectException(BackupException::class);
        (new BackupVerifier())->verify($this->base . '/not-a-zip.zip');
    }

    public function testAnArchiveThatIsNotThereIsRefused(): void
    {
        $this->expectException(BackupException::class);
        (new BackupVerifier())->verify($this->base . '/nothing-here.zip');
    }

    /**
     * An archive from before this format had no manifest at all — and contained
     * no documents on any database backend. Restoring one would silently produce
     * an empty site, which is the original bug run backwards.
     */
    public function testAnArchiveWithNoManifestIsRefused(): void
    {
        $path = $this->base . '/old.zip';
        $zip = new ZipArchive();
        $zip->open($path, ZipArchive::CREATE);
        $zip->addFromString('content/page/home.json', '{}');
        $zip->close();

        $this->expectExceptionMessage('no manifest');
        (new BackupVerifier())->verify($path);
    }

    public function testAnArchiveWhoseManifestIsNotJsonIsRefused(): void
    {
        $path = $this->archive();
        $this->replaceEntry($path, 'manifest.json', 'not json at all');

        $this->expectExceptionMessage('not readable JSON');
        (new BackupVerifier())->verify($path);
    }

    /* -------------------------------------------------------- truncation -- */

    public function testAnArchiveMissingAnEntryItsManifestNamesIsRefused(): void
    {
        $path = $this->archive();

        $zip = $this->openArchive($path);
        $this->assertTrue($zip->deleteName('content/page/en/home.json'));
        $zip->close();

        $this->expectExceptionMessage('missing');
        (new BackupVerifier())->verify($path);
    }

    public function testAnEntryOfTheWrongLengthIsRefused(): void
    {
        $path = $this->archive();
        $this->replaceEntry($path, 'content/page/en/home.json', '{"key":"page:en:home"}');

        $this->expectExceptionMessage('its manifest says');
        (new BackupVerifier())->verify($path);
    }

    /**
     * The nastiest case: the same number of bytes, different content. Only the
     * checksum catches it, and it is the case that matters — an archive somebody
     * altered to write chosen content into a site.
     */
    public function testAnEntryOfTheRightLengthButTheWrongBytesIsRefused(): void
    {
        $path = $this->archive();

        $zip = $this->openArchive($path);
        $original = (string) $zip->getFromName('content/page/en/home.json');
        $zip->close();

        // Same length, one character different.
        $tampered = substr_replace($original, strtoupper(substr($original, 5, 1)), 5, 1);
        if ($tampered === $original) {
            $tampered = substr_replace($original, 'X', -2, 1);
        }
        $this->replaceEntry($path, 'content/page/en/home.json', $tampered);

        $this->expectExceptionMessage('does not match the checksum');
        (new BackupVerifier())->verify($path);
    }

    public function testAnAlteredMediaFileIsRefused(): void
    {
        $path = $this->archive();
        $this->replaceEntry($path, 'content/media/photo.png', 'the picture bytez');

        $this->expectException(BackupException::class);
        (new BackupVerifier())->verify($path);
    }

    /* ------------------------------------------------------------ the pool -- */

    public function testAPooledArchiveVerifiesAgainstItsPool(): void
    {
        $pool = new MediaPool($this->base . '/pool');
        $manifest = (new BackupVerifier($pool))->verify($this->archive($pool));

        $this->assertTrue($manifest->isPooled());
    }

    /**
     * The failure the retention rules exist to prevent, caught a second time
     * here: a pool entry deleted out from under a surviving archive.
     */
    public function testAPooledArchiveWhoseMediaHasLeftThePoolIsRefused(): void
    {
        $pool = new MediaPool($this->base . '/pool');
        $path = $this->archive($pool);

        foreach ($pool->entries() as $reference) {
            $pool->remove($reference);
        }

        $this->expectExceptionMessage('missing from the backup pool');
        (new BackupVerifier($pool))->verify($path);
    }

    public function testAPooledFileWithTheWrongBytesIsRefused(): void
    {
        $pool = new MediaPool($this->base . '/pool');
        $path = $this->archive($pool);

        $reference = $pool->entries()[0];
        file_put_contents((string) $pool->pathFor($reference), 'different bytes entirely');

        $this->expectExceptionMessage('does not match the checksum');
        (new BackupVerifier($pool))->verify($path);
    }

    /**
     * A pooled archive taken off one installation and handed to another cannot
     * be restored there, and saying so plainly is better than a missing-file
     * error from somewhere deep inside the restore.
     */
    public function testAPooledArchiveWithNoPoolAtAllSaysWhy(): void
    {
        $pool = new MediaPool($this->base . '/pool');
        $path = $this->archive($pool);

        $this->expectExceptionMessage('shared pool');
        (new BackupVerifier(null))->verify($path);
    }

    /* --------------------------------------------------- doctored manifests -- */

    public function testAManifestWhoseEntryNameCouldEscapeIsRefused(): void
    {
        $path = $this->archive();
        $this->rewriteManifest($path, static function (array $manifest): array {
            $manifest['documents'][0]['entry'] = '../../public/shell.php';
            return $manifest;
        });

        $this->expectExceptionMessage('unsafe archive entry');
        (new BackupVerifier())->verify($path);
    }

    public function testAManifestClaimingMoreEntriesThanItListsIsRefused(): void
    {
        $path = $this->archive();
        $this->rewriteManifest($path, static function (array $manifest): array {
            $manifest['counts']['documents'] = 99;
            return $manifest;
        });

        $this->expectException(BackupException::class);
        (new BackupVerifier())->verify($path);
    }

    /**
     * Changing a digest to match doctored bytes has to fail too — the manifest
     * is not a second source of truth, it is the index, and the entry it points
     * at is what is hashed.
     */
    public function testAManifestWithARewrittenDigestStillFailsAgainstTheBytes(): void
    {
        $path = $this->archive();
        $this->rewriteManifest($path, static function (array $manifest): array {
            $manifest['documents'][0]['sha256'] = hash('sha256', 'something else');
            return $manifest;
        });

        $this->expectExceptionMessage('does not match the checksum');
        (new BackupVerifier())->verify($path);
    }

    /**
     * An entry nobody put in the manifest is never looked at: the manifest is
     * what is iterated, not the ZIP's directory. So an appended payload is inert
     * rather than something the restore has to be careful about.
     */
    public function testAnEntryTheManifestDoesNotNameIsIgnoredRatherThanRefused(): void
    {
        $path = $this->archive();
        $this->replaceEntry($path, 'stowaway.php', '<?php echo "hi";');

        $manifest = (new BackupVerifier())->verify($path);

        $this->assertSame(2, $manifest->documentCount());
        $this->assertNotContains(
            'stowaway.php',
            array_merge(
                array_column($manifest->documents, 'entry'),
                array_column($manifest->media, 'entry')
            )
        );
    }
}
