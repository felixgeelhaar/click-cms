<?php

declare(strict_types=1);

namespace Click\Cms\Application\Backup;

use Click\Cms\Domain\Backup\BackupManifest;
use InvalidArgumentException;
use ZipArchive;

/**
 * Checks an archive against its own manifest, before anything is written.
 *
 * This runs to completion first and the restore runs second, and the order is
 * the feature. A restore that verified as it went would write half a site and
 * then discover the archive was truncated — leaving an installation that is
 * neither what it was nor what the backup held, and no obvious way back. Every
 * entry is therefore read and hashed here, with nothing on disk touched, so a
 * bad archive is refused while refusing still costs nothing.
 *
 * What is checked, and why each one:
 *
 *  - **The manifest parses and is the format this version reads.** An archive
 *    from a future format is refused rather than partly understood.
 *  - **Every entry the manifest names exists.** A truncated download loses
 *    entries, and the manifest is what notices.
 *  - **Every entry's size and SHA-256 match.** Catches truncation inside an
 *    entry, bit rot, and tampering — a doctored page in an archive an
 *    administrator was emailed is a way to write arbitrary content into a site.
 *  - **Pooled media is present in the pool and hashes correctly.** A pool entry
 *    deleted by a retention bug is the exact failure the retention rules are
 *    built to prevent, and this is the second line of defence against it.
 *
 * Entry names are never taken from the ZIP's own directory: the manifest names
 * them and {@see \Click\Cms\Domain\Backup\ArchivePath} has already refused any
 * that could escape. An extra entry somebody appended to the archive is
 * therefore not verified, not extracted, and not reachable.
 */
final class BackupVerifier
{
    /**
     * Reading an entry to hash it must not be a way to exhaust memory, so the
     * bytes are streamed in chunks and the manifest's stated size bounds how far
     * the stream is allowed to run.
     */
    private const CHUNK_BYTES = 262144;

    public function __construct(private readonly ?MediaPool $pool = null)
    {
    }

    /**
     * @throws BackupException when the archive cannot be trusted, for any reason.
     */
    public function verify(string $archivePath): BackupManifest
    {
        if (!is_file($archivePath)) {
            throw new BackupException('There is no archive at ' . $archivePath . '.');
        }

        $zip = new ZipArchive();
        if ($zip->open($archivePath) !== true) {
            throw new BackupException('That file is not a readable backup archive.');
        }

        try {
            $manifest = $this->readManifest($zip);

            foreach ($manifest->documents as $document) {
                $this->assertEntryMatches($zip, $document['entry'], $document['sha256'], $document['bytes']);
            }

            foreach ($manifest->media as $item) {
                if ($item['entry'] !== null) {
                    $this->assertEntryMatches($zip, $item['entry'], $item['sha256'], $item['bytes']);
                    continue;
                }

                $this->assertPoolEntryMatches((string) $item['pool'], $item['sha256'], $item['path']);
            }

            return $manifest;
        } finally {
            $zip->close();
        }
    }

    /**
     * The manifest, or a refusal. Read into the parser rather than trusted:
     * `fromArray()` is where an entry name that could escape the destination is
     * rejected, so nothing downstream has to wonder.
     *
     * @throws BackupException
     */
    public function readManifest(ZipArchive $zip): BackupManifest
    {
        $raw = $zip->getFromName('manifest.json');
        if (!is_string($raw) || $raw === '') {
            throw new BackupException(
                'This archive has no manifest, so there is no way to tell what it should contain. '
                . 'Archives made before backup format ' . BackupManifest::FORMAT_VERSION
                . ' cannot be restored.'
            );
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            throw new BackupException('This archive\'s manifest is not readable JSON.');
        }

        try {
            return BackupManifest::fromArray($decoded);
        } catch (InvalidArgumentException $e) {
            throw new BackupException('This archive was refused: ' . $e->getMessage());
        }
    }

    /**
     * @throws BackupException
     */
    private function assertEntryMatches(ZipArchive $zip, string $entry, string $expected, int $bytes): void
    {
        $stat = $zip->statName($entry);
        if ($stat === false) {
            throw new BackupException(sprintf(
                'The archive is missing "%s", which its manifest says it contains.',
                $entry
            ));
        }

        if ((int) ($stat['size'] ?? -1) !== $bytes) {
            throw new BackupException(sprintf(
                'The archive entry "%s" is %d bytes; its manifest says %d.',
                $entry,
                (int) ($stat['size'] ?? -1),
                $bytes
            ));
        }

        $stream = $zip->getStream($entry);
        if ($stream === false) {
            throw new BackupException(sprintf('The archive entry "%s" could not be read.', $entry));
        }

        $context = hash_init('sha256');
        $read = 0;
        while (!feof($stream)) {
            $chunk = fread($stream, self::CHUNK_BYTES);
            if ($chunk === false) {
                fclose($stream);
                throw new BackupException(sprintf('The archive entry "%s" could not be read.', $entry));
            }
            $read += strlen($chunk);
            // A ZIP header can promise one size and the compressed stream deliver
            // another; expanding past what the manifest allows is a zip bomb, and
            // stopping at the stated size means the digest will not match anyway.
            if ($read > $bytes) {
                fclose($stream);
                throw new BackupException(sprintf(
                    'The archive entry "%s" expands to more than its manifest allows.',
                    $entry
                ));
            }
            hash_update($context, $chunk);
        }
        fclose($stream);

        if (!hash_equals($expected, hash_final($context))) {
            throw new BackupException(sprintf(
                'The archive entry "%s" does not match the checksum in its manifest, '
                . 'so this archive has been truncated or altered and was not restored.',
                $entry
            ));
        }
    }

    /**
     * @throws BackupException
     */
    private function assertPoolEntryMatches(string $reference, string $expected, string $path): void
    {
        if ($this->pool === null) {
            throw new BackupException(
                'This archive keeps its media in a shared pool, but no pool was given to read it from. '
                . 'It can only be restored on the installation that made it.'
            );
        }

        $poolPath = $this->pool->pathFor($reference);
        if ($poolPath === null || !is_file($poolPath)) {
            throw new BackupException(sprintf(
                'The media file "%s" is missing from the backup pool (%s), so this archive is incomplete.',
                $path,
                $reference
            ));
        }

        if (!hash_equals($expected, (string) hash_file('sha256', $poolPath))) {
            throw new BackupException(sprintf(
                'The pooled media file "%s" does not match the checksum in the manifest.',
                $path
            ));
        }
    }
}
