<?php

declare(strict_types=1);

namespace Click\Cms\Application\Backup;

/**
 * Decides *when* an unattended backup may run, and makes sure only one runs at a
 * time.
 *
 * Deliberately the same shape as {@see \Click\Cms\Application\Update\UpdateScheduler},
 * because the two hazards are the same ones and were already solved once:
 *
 *  - **Backing up too often.** A cron line every five minutes must not mean an
 *    archive every five minutes; a site would fill its disk in a day. The
 *    interval is enforced here, on a timestamp this class persists, so the cron
 *    entry can be as frequent as an operator likes without that becoming the
 *    backup rate.
 *
 *  - **Two backups at once.** Two overlapping runs would both write into the
 *    pool and both prune, and the second could delete a pool entry the first had
 *    written but not yet referenced from a finished manifest. An exclusive,
 *    non-blocking lock means the second run simply declines. Restore takes the
 *    same lock, so retention cannot delete a pool entry out from under a restore
 *    that is reading it.
 *
 * The clock is passed in rather than read, so the interval logic is testable
 * without sleeping.
 */
final class BackupScheduler
{
    /** A day. Frequent enough that a lost afternoon is the worst case. */
    public const DEFAULT_INTERVAL_SECONDS = 86400;

    public function __construct(
        private readonly string $stateDir,
        private readonly int $intervalSeconds = self::DEFAULT_INTERVAL_SECONDS,
    ) {
    }

    /**
     * Whether enough time has passed since the last backup.
     *
     * A site that has never backed up is due immediately: the first run after
     * switching this on should produce an archive rather than wait a day for one.
     */
    public function isDue(int $now): bool
    {
        $last = $this->lastRunAt();

        return $last === null || ($now - $last) >= $this->intervalSeconds;
    }

    public function lastRunAt(): ?int
    {
        $decoded = json_decode((string) @file_get_contents($this->statePath()), true);
        $at = is_array($decoded) ? ($decoded['lastRunAt'] ?? null) : null;

        return is_int($at) ? $at : null;
    }

    /**
     * Record that a backup ran.
     *
     * Called only after a real run. A dry run must never reach this: previewing
     * what would happen would otherwise consume the interval and leave the next
     * genuine run silently skipping, so looking before leaping would cost the
     * site a night's backup. That exact bug was found and fixed in the updater;
     * the CLI here is written the same way for the same reason.
     */
    public function markRun(int $now): void
    {
        $this->ensureDir();
        $path = $this->statePath();
        $tmp = $path . '.' . bin2hex(random_bytes(4)) . '.tmp';

        // Written then renamed, so a reader never sees a half-written file — the
        // same idiom the rest of the codebase uses for small state documents.
        if (@file_put_contents($tmp, json_encode(['lastRunAt' => $now], JSON_PRETTY_PRINT), LOCK_EX) === false) {
            return;
        }
        @rename($tmp, $path);
    }

    /**
     * Run $work while holding the backup lock, or return null if another process
     * already holds it.
     *
     * Non-blocking on purpose: a cron run that finds a backup in progress should
     * end, not queue up behind it and then start a second one the moment the
     * first finishes.
     *
     * @template T
     * @param callable(): T $work
     * @return T|null
     */
    public function withLock(callable $work): mixed
    {
        $this->ensureDir();
        $handle = @fopen($this->lockPath(), 'c');
        if ($handle === false) {
            return null;
        }

        if (!flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);
            return null;
        }

        try {
            return $work();
        } finally {
            // Released even if $work throws: a lock file left held would stop
            // every future backup until someone noticed, which is worse than the
            // failure that caused it.
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    private function statePath(): string
    {
        return $this->stateDir . '/schedule.json';
    }

    private function lockPath(): string
    {
        return $this->stateDir . '/backup.lock';
    }

    private function ensureDir(): void
    {
        if (!is_dir($this->stateDir)) {
            @mkdir($this->stateDir, 0o770, true);
        }
    }
}
