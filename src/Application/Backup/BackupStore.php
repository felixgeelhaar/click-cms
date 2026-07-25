<?php

declare(strict_types=1);

namespace Click\Cms\Application\Backup;

use Click\Cms\Domain\Backup\BackupManifest;
use Click\Cms\Domain\Backup\RetentionPlan;
use ZipArchive;

/**
 * The `data/backups` directory: what is in it, what a new archive is called, and
 * what retention may remove.
 *
 * ## Why it lives under `data/`
 *
 * A backup is the entire site, and the entire site includes documents nobody has
 * chosen to publish and `user` records carrying password hashes. `data/` is the
 * directory that is not web-served; putting archives anywhere reachable would
 * turn the backup feature into a way to download every draft and every hash
 * without signing in. The one route that hands an archive out is administrator-
 * only for exactly this reason.
 *
 * ## Names are timestamps, and that is load-bearing
 *
 * `2026-07-25T030000Z.zip`. Sorting the names as strings sorts them
 * chronologically, which is what lets retention pick "the newest N" without
 * consulting a modification time — a value that a copy, a restore from a
 * filesystem backup, or a `touch` can change. The name is written once and means
 * one thing forever.
 */
final class BackupStore
{
    /**
     * A timestamp, optionally disambiguated when two backups land in the same
     * second. The suffix sorts after the bare name, which keeps the ordering
     * within a second stable rather than merely arbitrary.
     */
    private const NAME_PATTERN = '/^\d{4}-\d{2}-\d{2}T\d{6}Z(?:-[a-f0-9]{6})?\.zip$/';

    public function __construct(private readonly string $dir)
    {
    }

    public function directory(): string
    {
        return $this->dir;
    }

    public function pool(): MediaPool
    {
        return new MediaPool($this->dir . '/pool');
    }

    /**
     * Every archive, oldest first.
     *
     * Only files whose names this class could itself have produced. A half-
     * written archive carries a `.tmp` suffix and is therefore not one, which
     * matters: retention counts survivors, and a partial file counted as a
     * survivor would suppress pool pruning forever.
     *
     * @return list<string>
     */
    public function archives(): array
    {
        if (!is_dir($this->dir)) {
            return [];
        }

        $out = [];
        foreach (scandir($this->dir) ?: [] as $entry) {
            if (preg_match(self::NAME_PATTERN, $entry) === 1 && is_file($this->dir . '/' . $entry)) {
                $out[] = $entry;
            }
        }

        sort($out, SORT_STRING);

        return $out;
    }

    /**
     * The full path of a named archive, or null when the name is not one this
     * store issues.
     *
     * The pattern is the whole check. An archive name reaches this from an HTTP
     * query string and a command line, so `../../config/core.json` must not
     * become a path — and a name made only of digits, dashes, `T`, `Z` and hex
     * cannot contain a separator or a dot-dot to begin with.
     */
    public function pathFor(string $name): ?string
    {
        if (preg_match(self::NAME_PATTERN, $name) !== 1) {
            return null;
        }

        $path = $this->dir . '/' . $name;

        return is_file($path) ? $path : null;
    }

    /** A name no existing archive has, for a backup being taken now. */
    public function nameFor(int $now): string
    {
        // UTC, and said so with the trailing Z. A site whose server moves
        // timezone — or observes daylight saving — would otherwise produce names
        // that sort into the wrong order twice a year, and retention would
        // delete the wrong hour's backup.
        $base = gmdate('Y-m-d\THis\Z', $now);

        if (!is_file($this->dir . '/' . $base . '.zip')) {
            return $base . '.zip';
        }

        return $base . '-' . bin2hex(random_bytes(3)) . '.zip';
    }

    public function ensureDir(): void
    {
        if (!is_dir($this->dir) && !@mkdir($this->dir, 0o770, true) && !is_dir($this->dir)) {
            throw new BackupException("The backup directory could not be created: {$this->dir}");
        }
    }

    /**
     * What retention would do, without doing it.
     *
     * Separated from {@see prune()} so `--dry-run` reports the real decision
     * rather than a re-derivation of it, and so the decision itself — the part
     * that can quietly destroy older backups — is a pure function of what is on
     * disk.
     */
    public function retentionPlan(int $keep): RetentionPlan
    {
        $archives = $this->archives();

        $references = [];
        foreach ($archives as $name) {
            $manifest = $this->manifestOf($name);
            // null, not [] — "its manifest would not open" is emphatically not
            // "it needs nothing from the pool", and treating the two the same is
            // how retention empties a pool that six good archives depend on.
            $references[$name] = $manifest?->poolReferences();
        }

        return RetentionPlan::compute($archives, $keep, $references, $this->pool()->entries());
    }

    /**
     * Apply retention: drop the archives beyond `keep`, then drop pool entries
     * nothing that survived still refers to.
     *
     * The order matters and it is this way round on purpose. Archives go first
     * and the plan was computed before either, from the survivors' own
     * manifests, so there is no window in which a pool entry is deleted while an
     * archive that needs it is still on disk claiming to be restorable.
     *
     * @return array{archives: list<string>, poolEntries: list<string>, refused: bool}
     */
    public function prune(int $keep): array
    {
        $plan = $this->retentionPlan($keep);

        $removedArchives = [];
        foreach ($plan->archivesToDelete() as $name) {
            $path = $this->pathFor($name);
            if ($path !== null && @unlink($path)) {
                $removedArchives[] = $name;
            }
        }

        $pool = $this->pool();
        $removedEntries = [];
        foreach ($plan->poolEntriesToDelete() as $reference) {
            if ($pool->remove($reference)) {
                $removedEntries[] = $reference;
            }
        }

        return [
            'archives' => $removedArchives,
            'poolEntries' => $removedEntries,
            'refused' => $plan->poolPruningRefused(),
        ];
    }

    /**
     * The manifest of a stored archive, or null when it cannot be read.
     *
     * Deliberately does not verify the entries: this answers "what does this
     * archive need?", which retention asks about every archive on every run, and
     * hashing every byte of every archive nightly to answer it would make
     * retention cost more than the backup. Verification is the restore's job,
     * where being wrong actually costs something.
     */
    public function manifestOf(string $name): ?BackupManifest
    {
        $path = $this->pathFor($name);
        if ($path === null) {
            return null;
        }

        $zip = new ZipArchive();
        if ($zip->open($path) !== true) {
            return null;
        }

        try {
            return (new BackupVerifier())->readManifest($zip);
        } catch (BackupException) {
            return null;
        } finally {
            $zip->close();
        }
    }

    /**
     * One archive, described for a listing.
     *
     * @return array<string, mixed>
     */
    public function describe(string $name): array
    {
        $path = $this->pathFor($name);
        $manifest = $this->manifestOf($name);

        return [
            'name' => $name,
            'bytes' => $path === null ? 0 : (int) @filesize($path),
            // A manifest that will not parse is reported as unreadable rather
            // than omitted from the listing: an administrator needs to see that
            // the file is there and that it cannot be restored, which is a
            // different problem from having no backup at all.
            'readable' => $manifest !== null,
            'createdAt' => $manifest?->createdAt,
            'sourceBackend' => $manifest?->sourceBackend,
            'mediaStorage' => $manifest?->mediaStorage,
            'documents' => $manifest?->documentCount(),
            'media' => $manifest?->mediaCount(),
            'skippedMedia' => $manifest === null ? null : count($manifest->skippedMedia),
        ];
    }

    /**
     * Every archive, newest first — the order a listing wants, because the
     * newest is the one somebody is looking for.
     *
     * @return list<array<string, mixed>>
     */
    public function listing(): array
    {
        $out = [];
        foreach (array_reverse($this->archives()) as $name) {
            $out[] = $this->describe($name);
        }

        return $out;
    }
}
