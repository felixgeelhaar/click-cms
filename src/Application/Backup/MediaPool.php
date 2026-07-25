<?php

declare(strict_types=1);

namespace Click\Cms\Application\Backup;

use Click\Cms\Domain\Backup\PoolReference;

/**
 * The content-addressed store every retained archive shares its media with.
 *
 * `data/backups/pool/<sha256>.<ext>`, one copy per distinct set of bytes. A
 * nightly backup of a site whose pictures have not changed therefore writes no
 * picture at all on the second night and every night after: the digest is the
 * same, so the name is the same, so the file is already there.
 *
 * Storing under the digest is what makes that safe rather than merely
 * convenient. There is no "has this changed?" heuristic on modification time or
 * size to be wrong about — two files land on the same name exactly when they are
 * the same file, and a changed picture is a different name and therefore a new
 * entry, leaving the old one for the older archives that still refer to it.
 *
 * Deleting is deliberately not this class's decision. It will remove an entry it
 * is told to, and {@see \Click\Cms\Domain\Backup\RetentionPlan} is what works out
 * which — because "is anything still using this?" is a question about every
 * archive on disk, not about the pool.
 */
final class MediaPool
{
    public function __construct(private readonly string $dir)
    {
    }

    public function directory(): string
    {
        return $this->dir;
    }

    /**
     * Put a file in the pool if it is not already there, and return its
     * reference.
     *
     * The digest is supplied by the caller, which has just computed it to write
     * the manifest; recomputing here would hash every file twice on every run
     * for no additional guarantee, since the caller's digest is the one the
     * manifest records and therefore the one a restore checks against.
     *
     * @throws BackupException when the pool cannot be written, which is a failed
     *         backup rather than a backup missing one picture.
     */
    public function store(string $sourcePath, string $sha256, string $extension): string
    {
        $reference = PoolReference::for($sha256, $extension);
        $target = $this->pathFor($reference);

        if ($target === null) {
            throw new BackupException('A media file produced an unusable pool reference.');
        }

        // Already pooled by an earlier run — this is the ordinary case, and the
        // entire saving. The name is the digest, so existence is proof of
        // identity; there is nothing to compare.
        if (is_file($target)) {
            return $reference;
        }

        $this->ensureDir();

        // Copied to a temporary name and renamed, so a run interrupted mid-copy
        // never leaves a short file sitting under a digest that promises the
        // whole one. A later run would have believed it.
        $tmp = $target . '.' . bin2hex(random_bytes(6)) . '.tmp';
        if (!@copy($sourcePath, $tmp)) {
            @unlink($tmp);
            throw new BackupException('A media file could not be copied into the backup pool.');
        }
        if (!@rename($tmp, $target)) {
            @unlink($tmp);
            throw new BackupException('A media file could not be committed to the backup pool.');
        }
        @chmod($target, 0o644);

        return $reference;
    }

    /**
     * Where a reference lives, or null when it is not a reference at all.
     *
     * The validation is the security boundary: references arrive from manifests,
     * which are files, and `pool/../../../../etc/passwd` must never become a
     * path. {@see PoolReference::isValid()} admits only a digest and an
     * extension, so there is no traversal to normalise away.
     */
    public function pathFor(string $reference): ?string
    {
        if (!PoolReference::isValid($reference)) {
            return null;
        }

        return $this->dir . '/' . substr($reference, strlen(PoolReference::DIRECTORY) + 1);
    }

    public function has(string $reference): bool
    {
        $path = $this->pathFor($reference);

        return $path !== null && is_file($path);
    }

    /**
     * Everything in the pool, as references.
     *
     * A file whose name is not a valid reference was not written by this class
     * and is left alone: retention deletes what it can account for, and an
     * unaccountable file in a data directory is a thing to leave for a human.
     *
     * @return list<string>
     */
    public function entries(): array
    {
        if (!is_dir($this->dir)) {
            return [];
        }

        $out = [];
        foreach (scandir($this->dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $reference = PoolReference::DIRECTORY . '/' . $entry;
            if (PoolReference::isValid($reference) && is_file($this->dir . '/' . $entry)) {
                $out[] = $reference;
            }
        }

        sort($out, SORT_STRING);

        return $out;
    }

    public function remove(string $reference): bool
    {
        $path = $this->pathFor($reference);

        return $path !== null && is_file($path) && @unlink($path);
    }

    public function bytesUsed(): int
    {
        $total = 0;
        foreach ($this->entries() as $reference) {
            $path = $this->pathFor($reference);
            $total += $path === null ? 0 : (int) @filesize($path);
        }

        return $total;
    }

    private function ensureDir(): void
    {
        if (!is_dir($this->dir) && !@mkdir($this->dir, 0o770, true) && !is_dir($this->dir)) {
            throw new BackupException("The backup pool directory could not be created: {$this->dir}");
        }
    }
}
