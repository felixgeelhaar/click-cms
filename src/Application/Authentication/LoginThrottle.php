<?php

declare(strict_types=1);

namespace Click\Cms\Application\Authentication;

/**
 * Slows down password guessing.
 *
 * Counts failures per username and refuses further attempts once a threshold is
 * crossed. Deliberately not per-IP: an attacker rotates addresses far more
 * easily than they rotate the account they are trying to break into, and
 * locking by address would let one visitor lock out everyone behind the same
 * office NAT.
 *
 * The trade-off is that an attacker can lock a known account out on purpose.
 * That is why the lock expires on its own rather than needing an administrator.
 *
 * Counting per username leaves one gap on purpose: a password sprayed across
 * many accounts never reaches any single account's threshold. That case belongs
 * to {@see LoginSprayGuard}, which this class can hand out over its own state
 * directory — see {@see self::sprayGuard()}.
 */
final class LoginThrottle
{
    public function __construct(
        private readonly string $path,
        private readonly int $maxAttempts = 5,
        private readonly int $windowSeconds = 900,
        private readonly int $lockoutSeconds = 900,
    ) {}

    /**
     * How many seconds remain of a lockout, or null when not locked.
     */
    public function secondsRemaining(string $username): ?int
    {
        $entry = $this->all()[$this->key($username)] ?? null;
        if (!is_array($entry)) {
            return null;
        }

        $until = $entry['lockedUntil'] ?? null;
        if (!is_int($until) || $until <= time()) {
            return null;
        }

        return $until - time();
    }

    public function isLocked(string $username): bool
    {
        return $this->secondsRemaining($username) !== null;
    }

    public function recordFailure(string $username): void
    {
        $all = $this->all();
        $key = $this->key($username);
        $entry = $all[$key] ?? ['attempts' => 0, 'firstAttempt' => time()];

        // Failures older than the window do not count towards a lockout, so a
        // typo last week plus a typo today is not treated as an attack.
        if (time() - (int) ($entry['firstAttempt'] ?? 0) > $this->windowSeconds) {
            $entry = ['attempts' => 0, 'firstAttempt' => time()];
        }

        $entry['attempts'] = (int) $entry['attempts'] + 1;

        if ($entry['attempts'] >= $this->maxAttempts) {
            $entry['lockedUntil'] = time() + $this->lockoutSeconds;
        }

        $all[$key] = $entry;
        $this->save($all);
    }

    /**
     * The site-wide companion to this throttle, over the same state directory.
     *
     * Built here rather than wired separately because this class is the one
     * thing that already knows where login-failure state is kept on a given
     * installation; a caller that had to name the file again could name a
     * different one, and the two counters would drift apart. The limits come
     * from the caller because they are configuration, and configuration is not
     * this class's business.
     */
    public function sprayGuard(int $maxFailures, int $windowSeconds): LoginSprayGuard
    {
        return new LoginSprayGuard(
            dirname($this->path) . '/login-spray.json',
            $maxFailures,
            $windowSeconds
        );
    }

    public function clear(string $username): void
    {
        $all = $this->all();
        unset($all[$this->key($username)]);
        $this->save($all);
    }

    /**
     * Usernames are supplied by whoever is trying to log in, so they are hashed
     * rather than used as keys directly — the file should not become a list of
     * accounts someone has been probing.
     */
    private function key(string $username): string
    {
        return hash('sha256', strtolower(trim($username)));
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function all(): array
    {
        if (!is_file($this->path)) {
            return [];
        }

        $decoded = json_decode((string) @file_get_contents($this->path), true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param array<string, array<string, mixed>> $entries
     */
    private function save(array $entries): void
    {
        $dir = dirname($this->path);
        if (!is_dir($dir)) {
            @mkdir($dir, 0o775, true);
        }

        // Expired entries are dropped on every write, so the file cannot grow
        // without bound from probing attempts.
        $now = time();
        $entries = array_filter($entries, static function (array $entry) use ($now): bool {
            $until = $entry['lockedUntil'] ?? null;
            if (is_int($until) && $until > $now) {
                return true;
            }

            return ($now - (int) ($entry['firstAttempt'] ?? 0)) < 86400;
        });

        file_put_contents($this->path, json_encode($entries, JSON_PRETTY_PRINT), LOCK_EX);
    }
}
