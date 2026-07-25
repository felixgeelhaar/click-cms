<?php

declare(strict_types=1);

namespace Click\Cms\Application\Backup;

use Click\Cms\Application\Content\ContentService;
use Click\Cms\Domain\Backup\ArchivePath;
use Click\Cms\Domain\Backup\BackupManifest;
use Click\Cms\Domain\Content\Content;
use Click\Cms\Domain\Publishing\Publishable;
use ZipArchive;

/**
 * Puts an archive back into a site.
 *
 * ## Verify first, write second — and not interleaved
 *
 * The verifier runs to completion inside {@see restore()} before a single write,
 * and it is constructed here rather than passed in so there is no call path that
 * skips it. An archive that is truncated, altered, or missing a pooled picture is
 * refused while refusing is still free. Discovering it half way through would
 * leave an installation that is neither the site it was nor the site the backup
 * held.
 *
 * ## It never overwrites unless told to
 *
 * The same stance {@see \Click\Cms\Application\Seed\SiteSeeder} takes, for the
 * same reason. A restore is usually run to recover the handful of things that
 * went missing, not to roll an entire site back to Tuesday — so anything already
 * present is left exactly as it is and reported as skipped. `--overwrite` exists
 * for the rarer case and has to be typed, because the failure it can cause
 * (silently discarding every edit made since the backup) is worse than the one it
 * repairs.
 *
 * ## It writes through the services, not through storage
 *
 * Documents go in through {@see ContentService}, so the version chain records
 * them, the audit trail sees them, and the render cache is invalidated — a
 * restore that wrote straight to the backend would produce content the site
 * cannot account for and a cache still serving the pages that were lost.
 * Publishable types are published after being saved, because saving one records
 * a working copy and nothing more: a restore that stopped there would return a
 * site whose every page was an unpublished draft and whose every URL 404ed.
 *
 * ## Every path is checked before it is a path
 *
 * Entry names and media paths come out of a manifest, which is a file that may
 * have been written by somebody else. They are validated as names by
 * {@see ArchivePath} — no absolute paths, no `..`, no backslashes, no empty
 * segments — and only then joined, which is the check the marketplace
 * installer's Zip Slip RCE did not have. Nothing is extracted because it is in
 * the ZIP; something is extracted because the manifest named it and the name
 * passed.
 */
final class BackupRestorer
{
    private const CHUNK_BYTES = 262144;

    private readonly BackupVerifier $verifier;

    public function __construct(
        private readonly ContentService $content,
        private readonly string $mediaDir,
        private readonly ?MediaPool $pool = null,
        ?BackupVerifier $verifier = null,
    ) {
        $this->verifier = $verifier ?? new BackupVerifier($pool);
    }

    /**
     * @throws BackupException when the archive is not trustworthy. Nothing has
     *         been written when this throws.
     */
    public function restore(string $archivePath, bool $overwrite = false): RestoreReport
    {
        $manifest = $this->verifier->verify($archivePath);

        $zip = new ZipArchive();
        if ($zip->open($archivePath) !== true) {
            throw new BackupException('That file is not a readable backup archive.');
        }

        $report = new RestoreReport();

        try {
            $this->restoreDocuments($zip, $manifest, $overwrite, $report);
            $this->restoreMedia($zip, $manifest, $overwrite, $report);
        } finally {
            $zip->close();
        }

        // Media the backup never held cannot be restored, and an operator
        // hunting a missing picture deserves to be told that here rather than
        // reading the manifest to find out.
        foreach ($manifest->skippedMedia as $skipped) {
            $report->failed(
                'media ' . $skipped['path'],
                'was not in the backup (' . $skipped['reason'] . ')'
            );
        }

        return $report;
    }

    private function restoreDocuments(
        ZipArchive $zip,
        BackupManifest $manifest,
        bool $overwrite,
        RestoreReport $report,
    ): void {
        foreach ($manifest->documents as $entry) {
            $label = $entry['type'] . '/' . $entry['locale'] . '/' . $entry['slug'];

            $raw = $zip->getFromName($entry['entry']);
            if (!is_string($raw)) {
                $report->failed($label, 'the archive entry could not be read');
                continue;
            }

            $row = json_decode($raw, true);
            if (!is_array($row)) {
                $report->failed($label, 'the stored document is not readable JSON');
                continue;
            }

            // The key inside the document and the key in the manifest must agree.
            // They are written together, so a disagreement means one of them was
            // edited — and the document's own key is what decides where this
            // lands, so believing it over an index that says otherwise is how a
            // restore writes a page somewhere nobody expected.
            if (($row['key'] ?? null) !== $entry['key']) {
                $report->failed($label, 'the stored document does not carry the key the manifest gives it');
                continue;
            }

            try {
                $document = Content::fromArray($row);
            } catch (\InvalidArgumentException $e) {
                $report->failed($label, $e->getMessage());
                continue;
            }

            $key = $document->key;

            // The working copy as well as the live document: an unpublished draft
            // occupies the address just as firmly, and overwriting one would
            // destroy work that was never backed up in the first place.
            $present = $this->content->exists($key) || $this->content->draft($key) !== null;

            if ($present && !$overwrite) {
                $report->skipped($label);
                continue;
            }

            try {
                $this->content->save($document);

                // A publishable type is saved as a working copy and nothing more,
                // so without this the restored site would be a set of drafts and
                // a 404 for every address.
                if (Publishable::includes($document->type())) {
                    $this->content->publish($key);
                }
            } catch (\Throwable $e) {
                $report->failed($label, $e->getMessage());
                continue;
            }

            $report->restored($label);
        }
    }

    private function restoreMedia(
        ZipArchive $zip,
        BackupManifest $manifest,
        bool $overwrite,
        RestoreReport $report,
    ): void {
        foreach ($manifest->media as $item) {
            $label = 'media ' . $item['path'];

            $target = ArchivePath::resolve($this->mediaDir, $item['path']);
            if ($target === null) {
                $report->failed($label, 'the manifest gives it an unsafe path');
                continue;
            }

            if (is_file($target) && !$overwrite) {
                $report->skipped($label);
                continue;
            }

            $directory = dirname($target);
            if (!is_dir($directory) && !@mkdir($directory, 0o775, true) && !is_dir($directory)) {
                $report->failed($label, 'its directory could not be created');
                continue;
            }

            $written = $item['entry'] !== null
                ? $this->writeFromArchive($zip, $item['entry'], $target)
                : $this->writeFromPool((string) $item['pool'], $target);

            if ($written === null) {
                $report->restored($label);
                continue;
            }

            $report->failed($label, $written);
        }
    }

    /** @return ?string an error, or null on success */
    private function writeFromArchive(ZipArchive $zip, string $entry, string $target): ?string
    {
        $stream = $zip->getStream($entry);
        if ($stream === false) {
            return 'the archive entry could not be read';
        }

        $tmp = $target . '.' . bin2hex(random_bytes(6)) . '.tmp';
        $out = @fopen($tmp, 'wb');
        if ($out === false) {
            fclose($stream);
            return 'it could not be written';
        }

        // Streamed rather than read whole: a video is a media file too, and
        // holding one in memory to write it out again is how a restore dies on
        // the shared hosting this CMS is built for.
        while (!feof($stream)) {
            $chunk = fread($stream, self::CHUNK_BYTES);
            if ($chunk === false || ($chunk !== '' && fwrite($out, $chunk) === false)) {
                fclose($stream);
                fclose($out);
                @unlink($tmp);
                return 'it could not be written';
            }
        }

        fclose($stream);
        fclose($out);

        return $this->commit($tmp, $target);
    }

    /** @return ?string an error, or null on success */
    private function writeFromPool(string $reference, string $target): ?string
    {
        if ($this->pool === null) {
            return 'this archive keeps its media in a pool that is not available here';
        }

        $source = $this->pool->pathFor($reference);
        if ($source === null || !is_file($source)) {
            return 'the pooled file is missing';
        }

        $tmp = $target . '.' . bin2hex(random_bytes(6)) . '.tmp';
        if (!@copy($source, $tmp)) {
            @unlink($tmp);
            return 'it could not be copied out of the pool';
        }

        return $this->commit($tmp, $target);
    }

    /**
     * Rename into place, so a reader never sees a partially written media file —
     * and a restore interrupted half way leaves the previous file intact rather
     * than a truncated one.
     *
     * @return ?string an error, or null on success
     */
    private function commit(string $tmp, string $target): ?string
    {
        if (!@rename($tmp, $target)) {
            @unlink($tmp);
            return 'it could not be moved into place';
        }

        // Uploaded files are data, never programs — the same posture
        // MediaService gives everything it stores.
        @chmod($target, 0o644);

        return null;
    }
}
