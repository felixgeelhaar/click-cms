<?php

declare(strict_types=1);

namespace Click\Cms\Application\Update;

/**
 * The last thing an installation learned about updates, so the admin can say it
 * without asking the feed again.
 *
 * The admin screen asks for update status whenever somebody signs in, and the
 * status endpoint fetched the release feed on every call. That put a network
 * round trip in the sign-in path — slow when the feed is slow, an error when it
 * is unreachable, and a poll rate decided by how often people log in, which is
 * not a rate anyone chose. {@see UpdateScheduler} already existed to stop the
 * cron path polling too often; this is the other half, for the interactive one.
 *
 * Deliberately small: a single JSON file holding the decision and when it was
 * made. It is a cache of something re-derivable, so every failure here is
 * answered with "nothing remembered" rather than an exception — an unreadable
 * cache must never be able to take the admin screen down.
 */
final class UpdateNotice
{
    private const FILE = 'last-check.json';

    public function __construct(private readonly string $directory) {}

    /**
     * What was last learned, with the time it was learned, or null when there
     * is nothing usable.
     *
     * @return array<string, mixed>|null
     */
    public function remembered(): ?array
    {
        $raw = @file_get_contents($this->path());
        if ($raw === false) {
            return null;
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) && isset($decoded['checkedAt']) ? $decoded : null;
    }

    /**
     * Record what a check found. Replaces whatever was there: only the latest
     * answer is of any use.
     *
     * @param array<string, mixed> $state
     */
    public function remember(array $state, int $now): void
    {
        if (!is_dir($this->directory) && !@mkdir($this->directory, 0o775, true) && !is_dir($this->directory)) {
            return;
        }

        $payload = json_encode($state + ['checkedAt' => $now], JSON_PRETTY_PRINT);
        if ($payload === false) {
            return;
        }

        // Written through a temporary file so a reader never sees half of one.
        $tmp = $this->path() . '.' . bin2hex(random_bytes(4));
        if (@file_put_contents($tmp, $payload, LOCK_EX) === false) {
            return;
        }

        if (!@rename($tmp, $this->path())) {
            @unlink($tmp);
        }
    }

    /**
     * Drop what was remembered.
     *
     * Called when an update is installed: the remembered answer describes the
     * version that was running a moment ago, and keeping it means the admin goes
     * on offering a release the site already has — until the check interval
     * elapses, which is a day. Forgetting makes the next sign-in ask again.
     */
    public function forget(): void
    {
        @unlink($this->path());
    }

    private function path(): string
    {
        return rtrim($this->directory, '/') . '/' . self::FILE;
    }
}
