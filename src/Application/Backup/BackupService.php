<?php

declare(strict_types=1);

namespace Click\Cms\Application\Backup;

use Click\Cms\Domain\Backup\BackupManifest;
use Click\Cms\Domain\Storage\StorageInterface;
use ZipArchive;

/**
 * Taking a backup: the two shapes of it, and retention afterwards.
 *
 * One place so that the scheduled run and the administrator's download cannot
 * disagree about what "the whole site" means. Both walk the same storage port,
 * both write the same manifest; the only difference is where the media goes, and
 * that difference is a constructor argument rather than a second code path.
 *
 * Restoring is deliberately not here. It needs {@see \Click\Cms\Application\Content\ContentService}
 * — a restore writes through the services so history and audit see it — and
 * folding that in would make this class the thing that both reads storage
 * directly and writes through the application, which is precisely the shape that
 * lets a future edit restore straight into the backend by accident.
 * {@see BackupRestorer} owns it.
 */
final class BackupService
{
    public function __construct(
        private readonly BackupStore $store,
        private readonly StorageInterface $storage,
        private readonly string $mediaDir,
        private readonly string $sourceBackend,
        private readonly bool $includeMedia = true,
        private readonly int $maxMediaBytes = 0,
    ) {
    }

    /**
     * Take the scheduled backup: media into the shared pool, then retention.
     *
     * Written first and pruned second, always. The new archive is on disk with a
     * finished manifest before retention looks at anything, so it counts as a
     * survivor and its pool entries are live from the first moment they could be
     * considered for deletion. Pruning first would leave a window in which the
     * entries this run is about to reference look unreferenced.
     *
     * @return array{name: string, manifest: BackupManifest, pruned: array{archives: list<string>, poolEntries: list<string>, refused: bool}}
     * @throws BackupException
     */
    public function takeBackup(int $keep, ?int $now = null): array
    {
        $this->store->ensureDir();

        $name = $this->store->nameFor($now ?? time());

        $manifest = $this->exporter($this->store->pool())
            ->export($this->store->directory() . '/' . $name, $now);

        return [
            'name' => $name,
            'manifest' => $manifest,
            'pruned' => $this->store->prune($keep),
        ];
    }

    /**
     * A self-contained archive at an arbitrary path — media bytes inside it.
     *
     * What a download is. An archive that referred to a pool would be useless to
     * whoever downloaded it: the whole reason to take a copy off the server is to
     * be able to restore when the server is gone.
     *
     * @throws BackupException
     */
    public function exportPortable(string $path, ?int $now = null): BackupManifest
    {
        return $this->exporter(null)->export($path, $now);
    }

    /**
     * Turn a retained, pooled archive into a self-contained one.
     *
     * Nightly archives are pooled, which makes them cheap and makes them
     * inseparable from this installation. This is how one leaves: the documents
     * are copied across verbatim and the media is pulled out of the pool and
     * written into the ZIP, producing an archive that restores anywhere.
     *
     * The source is verified in full first, so a copy is never made of an
     * archive that could not be restored — a corrupt backup faithfully converted
     * into a portable corrupt backup is worse than an error, because it will be
     * carried off-site and trusted.
     *
     * @throws BackupException
     */
    public function exportPortableCopy(string $name, string $targetPath): BackupManifest
    {
        $sourcePath = $this->store->pathFor($name);
        if ($sourcePath === null) {
            throw new BackupException('There is no backup called "' . $name . '".');
        }

        $pool = $this->store->pool();
        $source = (new BackupVerifier($pool))->verify($sourcePath);

        $from = new ZipArchive();
        if ($from->open($sourcePath) !== true) {
            throw new BackupException('That backup could not be opened.');
        }

        $tmpPath = $targetPath . '.' . bin2hex(random_bytes(6)) . '.tmp';
        $to = new ZipArchive();
        if ($to->open($tmpPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            $from->close();
            throw new BackupException('A portable copy could not be created.');
        }

        try {
            foreach ($source->documents as $document) {
                $bytes = $from->getFromName($document['entry']);
                if (!is_string($bytes)) {
                    throw new BackupException('A document could not be copied out of that backup.');
                }
                $to->addFromString($document['entry'], $bytes);
            }

            $media = [];
            foreach ($source->media as $item) {
                $entry = 'content/media/' . $item['path'];

                if ($item['entry'] !== null) {
                    $bytes = $from->getFromName($item['entry']);
                    if (!is_string($bytes)) {
                        throw new BackupException('A media file could not be copied out of that backup.');
                    }
                    $to->addFromString($entry, $bytes);
                } else {
                    $poolPath = $pool->pathFor((string) $item['pool']);
                    if ($poolPath === null || !$to->addFile($poolPath, $entry)) {
                        throw new BackupException('A pooled media file could not be copied into the portable archive.');
                    }
                }

                $media[] = [
                    'path' => $item['path'],
                    'sha256' => $item['sha256'],
                    'bytes' => $item['bytes'],
                    'entry' => $entry,
                    'pool' => null,
                ];
            }

            // The original creation time and source backend are kept: this is the
            // same backup in a different wrapper, and re-dating it would make an
            // off-site copy claim to be more recent than the site it holds.
            $manifest = BackupManifest::create(
                $source->createdAt,
                $source->sourceBackend,
                BackupManifest::MEDIA_EMBEDDED,
                $source->documents,
                $media,
                $source->skippedMedia,
            );

            $to->addFromString('manifest.json', (string) json_encode(
                $manifest->toArray(),
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
            ));

            if (!$to->close()) {
                throw new BackupException('The portable copy could not be written.');
            }
        } catch (\Throwable $e) {
            @$to->close();
            @unlink($tmpPath);
            $from->close();
            throw $e;
        }

        $from->close();

        if (!@rename($tmpPath, $targetPath)) {
            @unlink($tmpPath);
            throw new BackupException('The portable copy could not be moved into place.');
        }

        return $manifest;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listing(): array
    {
        return $this->store->listing();
    }

    public function store(): BackupStore
    {
        return $this->store;
    }

    private function exporter(?MediaPool $pool): BackupExporter
    {
        return new BackupExporter(
            $this->storage,
            $this->mediaDir,
            $this->sourceBackend,
            $pool,
            $this->includeMedia,
            $this->maxMediaBytes,
        );
    }
}
