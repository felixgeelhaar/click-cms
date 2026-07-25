<?php

declare(strict_types=1);

namespace Click\Cms\Application\Update;

/**
 * Decides *when* an unattended update check may run, and makes sure only one
 * runs at a time.
 *
 * Two separate hazards, both of which have bitten self-updating software before:
 *
 *  - **Checking too often.** A cron line every five minutes must not mean a
 *    release feed fetched every five minutes. The interval is enforced here, on
 *    a timestamp the scheduler persists, so the cron entry can be as frequent as
 *    an operator likes without that becoming the poll rate.
 *
 *  - **Two updates at once.** Two cron runs overlapping — or a slow update still
 *    finishing when the next fires — would have two processes swapping the same
 *    directories. That is how an installation ends up half of one release and
 *    half of another. An exclusive, non-blocking lock means the second run
 *    simply declines.
 *
 * The clock is passed in rather than read, so the interval logic is testable
 * without sleeping.
 */
final class UpdateScheduler
{
    /** A day: often enough to matter for a security fix, rare enough to be polite. */
    public const DEFAULT_INTERVAL_SECONDS = 86400;

    public function __construct(
        private readonly string $stateDir,
        private readonly int $intervalSeconds = self::DEFAULT_INTERVAL_SECONDS,
    ) {
    }

    /**
     * Whether enough time has passed since the last check.
     *
     * A site that has never checked is due immediately: the first run after
     * installing should find out where it stands rather than wait a day.
     */
    public function isDue(int $now): bool
    {
        $last = $this->lastCheckedAt();

        return $last === null || ($now - $last) >= $this->intervalSeconds;
    }

    public function lastCheckedAt(): ?int
    {
        $decoded = json_decode((string) @file_get_contents($this->statePath()), true);
        $at = is_array($decoded) ? ($decoded['lastCheckedAt'] ?? null) : null;

        return is_int($at) ? $at : null;
    }

    /** Record that a check happened, whatever its outcome. */
    public function markChecked(int $now): void
    {
        $this->ensureDir();
        $path = $this->statePath();
        $tmp = $path . '.' . bin2hex(random_bytes(4)) . '.tmp';

        // Written then renamed, so a reader never sees a half-written file — the
        // same idiom the rest of the codebase uses for small state documents.
        if (@file_put_contents($tmp, json_encode(['lastCheckedAt' => $now], JSON_PRETTY_PRINT), LOCK_EX) === false) {
            return;
        }
        @rename($tmp, $path);
    }

    /**
     * Run $work while holding the update lock, or return null if another process
     * already holds it.
     *
     * Non-blocking on purpose: a cron run that finds an update in progress should
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
            // every future update until someone noticed, which is worse than the
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
        return $this->stateDir . '/update.lock';
    }

    private function ensureDir(): void
    {
        if (!is_dir($this->stateDir)) {
            @mkdir($this->stateDir, 0o775, true);
        }
    }
}
