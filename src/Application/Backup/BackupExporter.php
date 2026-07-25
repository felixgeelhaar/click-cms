<?php

declare(strict_types=1);

namespace Click\Cms\Application\Backup;

use Click\Cms\Domain\Backup\ArchivePath;
use Click\Cms\Domain\Backup\BackupManifest;
use Click\Cms\Domain\Content\Content;
use Click\Cms\Domain\Storage\StorageInterface;
use ZipArchive;

/**
 * Builds an archive of the whole site from the storage port, whatever backend is
 * behind it.
 *
 * ## Why this reads storage and not the content directory
 *
 * The predecessor walked `content/`. That directory holds documents on exactly
 * one of the four supported backends. On SQLite, MySQL, MariaDB and Postgres the
 * documents are in the database and `content/` holds only the media, so the
 * archive contained the pictures, no pages, no accounts, no menus — and a
 * manifest reporting success. A site backed up nightly for a year had a year of
 * archives that would have restored an empty site.
 *
 * Every document is now reached through {@see StorageInterface::types()} and
 * {@see StorageInterface::findByType()}, which is the pair that makes
 * "everything in this site" expressible without knowing what a site contains or
 * where the bytes live. Each document is serialised to the same JSON the
 * flat-file backend writes, so the archive's shape is a property of the format
 * and not of the backend it came off — which is what makes restoring a SQLite
 * site onto flat files (or the reverse) an ordinary restore rather than a
 * migration.
 *
 * ## Two shapes of archive
 *
 * With a pool, media is written once into `data/backups/pool` and the manifest
 * names the entries; without one, the bytes go inside the ZIP. Scheduled backups
 * take the first — seven nightly runs of an unchanged library cost one copy —
 * and a download takes the second, because an archive that referred to a pool
 * the person holding it does not have could not be restored anywhere else.
 *
 * ## The size ceiling reports itself
 *
 * A media file over `maxMediaBytes` is recorded in `skippedMedia` with its size
 * and the reason, not dropped. The whole point of rebuilding this feature was
 * that a backup which silently omitted things reported success; a ceiling that
 * silently omitted the 2 GB video would have reintroduced it in a smaller way.
 */
final class BackupExporter
{
    /** Renditions cached on demand from the stored original; not the site's data. */
    private const DERIVED_DIRECTORY = 'derived';

    /**
     * A hard ceiling on how many files the media walk will consider, so a
     * directory that has gone wrong cannot turn a backup into an unbounded run.
     */
    private const MAX_MEDIA_FILES = 100000;

    public function __construct(
        private readonly StorageInterface $storage,
        private readonly string $mediaDir,
        /** Named in the manifest so a restore can say where the archive came from. */
        private readonly string $sourceBackend,
        /** Null puts the media inside the archive; a pool shares it between archives. */
        private readonly ?MediaPool $pool = null,
        private readonly bool $includeMedia = true,
        /** Files larger than this are recorded as skipped. Zero or less means no ceiling. */
        private readonly int $maxMediaBytes = 0,
    ) {
    }

    /**
     * Write the archive at $archivePath and return the manifest describing it.
     *
     * The ZIP is built under a temporary name and renamed into place, so nothing
     * ever sees a half-written archive — which matters more here than usual,
     * because retention reads every archive in the directory and a partial one
     * would either be pruned as unreadable or, worse, counted as a survivor whose
     * requirements are unknown.
     *
     * @throws BackupException
     */
    public function export(string $archivePath, ?int $now = null): BackupManifest
    {
        $tmpPath = $archivePath . '.' . bin2hex(random_bytes(6)) . '.tmp';

        $zip = new ZipArchive();
        if ($zip->open($tmpPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new BackupException('The backup archive could not be created at ' . $archivePath . '.');
        }

        try {
            $documents = $this->addDocuments($zip);
            [$media, $skipped] = $this->includeMedia ? $this->addMedia($zip) : [[], []];

            $manifest = BackupManifest::create(
                date('c', $now ?? time()),
                $this->sourceBackend,
                $this->pool === null ? BackupManifest::MEDIA_EMBEDDED : BackupManifest::MEDIA_POOLED,
                $documents,
                $media,
                $skipped,
            );

            $zip->addFromString('manifest.json', (string) json_encode(
                $manifest->toArray(),
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
            ));

            if (!$zip->close()) {
                throw new BackupException('The backup archive could not be written.');
            }
        } catch (\Throwable $e) {
            // close() on a failed build still writes whatever was added, so the
            // temporary file is removed either way and the caller is left with
            // no archive rather than a plausible-looking short one.
            @$zip->close();
            @unlink($tmpPath);
            throw $e;
        }

        if (!@rename($tmpPath, $archivePath)) {
            @unlink($tmpPath);
            throw new BackupException('The backup archive could not be moved into place.');
        }
        @chmod($archivePath, 0o640);

        return $manifest;
    }

    /**
     * Every document of every type, serialised as JSON.
     *
     * @return list<array{entry: string, key: string, type: string, slug: string, locale: string, sha256: string, bytes: int}>
     */
    private function addDocuments(ZipArchive $zip): array
    {
        $out = [];

        foreach ($this->storage->types() as $type) {
            // Every language, not the site's default: a translation left out of
            // the backup is a translation lost, and `findByType()` with no locale
            // is precisely "all of them".
            foreach ($this->storage->findByType($type) as $document) {
                $entry = $this->entryNameFor($document);
                if ($entry === null) {
                    // A key that cannot become a path segment cannot have been
                    // written by any backend this codebase ships, so this is
                    // unreachable in practice — and skipping it silently would be
                    // the exact class of bug being fixed. It is loud instead.
                    throw new BackupException(sprintf(
                        'The document "%s" has a key that cannot be stored in an archive.',
                        $document->key->toString()
                    ));
                }

                $bytes = (string) json_encode(
                    $document->toArray(),
                    JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
                );

                $zip->addFromString($entry, $bytes);

                $out[] = [
                    'entry' => $entry,
                    'key' => $document->key->toString(),
                    'type' => $document->type(),
                    'slug' => $document->slug(),
                    'locale' => $document->locale()->code,
                    'sha256' => hash('sha256', $bytes),
                    'bytes' => strlen($bytes),
                ];
            }
        }

        usort($out, static fn (array $a, array $b): int => strcmp($a['entry'], $b['entry']));

        return array_values($out);
    }

    /**
     * `content/<type>/<locale>/<slug>.json` — the flat-file backend's own layout,
     * used for every backend so that what comes out of SQLite is byte-identical
     * to what comes out of files. Null when the key could not make a safe path.
     */
    private function entryNameFor(Content $document): ?string
    {
        $entry = sprintf(
            'content/%s/%s/%s.json',
            $document->type(),
            $document->locale()->code,
            $document->slug()
        );

        return ArchivePath::isSafe($entry) ? $entry : null;
    }

    /**
     * @return array{
     *     0: list<array{path: string, sha256: string, bytes: int, entry: ?string, pool: ?string}>,
     *     1: list<array{path: string, bytes: int, reason: string}>
     * }
     */
    private function addMedia(ZipArchive $zip): array
    {
        $stored = [];
        $skipped = [];

        foreach ($this->mediaFiles() as $relative => $absolute) {
            $bytes = (int) filesize($absolute);

            if ($this->maxMediaBytes > 0 && $bytes > $this->maxMediaBytes) {
                $skipped[] = [
                    'path' => $relative,
                    'bytes' => $bytes,
                    'reason' => sprintf(
                        'larger than core.backup.maxMediaBytes (%d bytes)',
                        $this->maxMediaBytes
                    ),
                ];
                continue;
            }

            $digest = hash_file('sha256', $absolute);
            if (!is_string($digest)) {
                $skipped[] = ['path' => $relative, 'bytes' => $bytes, 'reason' => 'unreadable'];
                continue;
            }

            if ($this->pool !== null) {
                $stored[] = [
                    'path' => $relative,
                    'sha256' => $digest,
                    'bytes' => $bytes,
                    'entry' => null,
                    'pool' => $this->pool->store(
                        $absolute,
                        $digest,
                        (string) pathinfo($relative, PATHINFO_EXTENSION)
                    ),
                ];
                continue;
            }

            // Under `content/media/` so an embedded archive mirrors the
            // installation layout: a human who unzips one finds the site's
            // directories where they expect them.
            $entry = 'content/media/' . $relative;
            $zip->addFile($absolute, $entry);

            $stored[] = [
                'path' => $relative,
                'sha256' => $digest,
                'bytes' => $bytes,
                'entry' => $entry,
                'pool' => null,
            ];
        }

        return [$stored, $skipped];
    }

    /**
     * Every media file, keyed by its path relative to the media directory.
     *
     * The walk is rooted and each file's real path is checked to sit inside the
     * root, so a symlink planted in the media directory resolves to somewhere
     * outside it and is dropped rather than copied into a backup that an
     * administrator will later download. `derived/` is skipped because it is a
     * rendition cache built on demand from the stored originals — backing it up
     * would multiply the archive for files the site regenerates for free.
     *
     * @return array<string, string> relative path => absolute path
     */
    private function mediaFiles(): array
    {
        $root = realpath($this->mediaDir);
        if ($root === false || !is_dir($root)) {
            return [];
        }

        $out = [];
        $seen = 0;

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveCallbackFilterIterator(
                new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
                static function (\SplFileInfo $file): bool {
                    return !($file->isDir() && $file->getFilename() === self::DERIVED_DIRECTORY);
                }
            ),
            \RecursiveIteratorIterator::LEAVES_ONLY
        );

        foreach ($iterator as $file) {
            if (++$seen > self::MAX_MEDIA_FILES) {
                throw new BackupException(
                    'The media directory holds more files than a backup will walk; '
                    . 'this is almost certainly not a media library.'
                );
            }
            if (!$file->isFile()) {
                continue;
            }

            $absolute = $file->getRealPath();
            if ($absolute === false || !str_starts_with($absolute, $root . DIRECTORY_SEPARATOR)) {
                continue;
            }

            $relative = str_replace(DIRECTORY_SEPARATOR, '/', substr($absolute, strlen($root) + 1));
            if (!ArchivePath::isSafe($relative)) {
                continue;
            }

            $out[$relative] = $absolute;
        }

        // A stable order makes two backups of the same site comparable, and makes
        // the manifest diffable.
        ksort($out, SORT_STRING);

        return $out;
    }
}
