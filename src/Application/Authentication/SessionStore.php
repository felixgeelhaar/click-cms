<?php

declare(strict_types=1);

namespace Click\Cms\Application\Authentication;

/**
 * Reads and writes the signed-in session.
 *
 * Backed by a single file, which is a known limitation rather than a design:
 * two people signed in at once share one record and will overwrite each other.
 * It is isolated here so that replacing it — with a file per session, or a
 * store behind an interface — touches nothing else.
 */
final class SessionStore
{
    public function __construct(
        private readonly string $path,
        private readonly int $idleTimeoutSeconds = 1800,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function read(): array
    {
        if (!is_file($this->path)) {
            return [];
        }

        $decoded = json_decode((string) @file_get_contents($this->path), true);
        if (!is_array($decoded)) {
            return [];
        }

        if ($this->hasExpired($decoded)) {
            $this->clear();

            return [];
        }

        return $decoded;
    }

    /**
     * @param array<string, mixed> $session
     */
    public function write(array $session): void
    {
        $dir = dirname($this->path);
        if (!is_dir($dir)) {
            @mkdir($dir, 0o775, true);
        }

        file_put_contents($this->path, json_encode($session, JSON_PRETTY_PRINT), LOCK_EX);
    }

    public function clear(): void
    {
        if (is_file($this->path)) {
            @unlink($this->path);
        }
    }

    /**
     * The signed-in account, or null.
     *
     * @return array<string, mixed>|null
     */
    public function user(): ?array
    {
        $user = $this->read()['user'] ?? null;

        return is_array($user) ? $user : null;
    }

    /**
     * Record that the session is still in use.
     *
     * Without this an idle timeout would expire someone mid-task; with it, the
     * timeout measures inactivity rather than session age.
     */
    public function touch(): void
    {
        $session = $this->read();
        if ($session === []) {
            return;
        }

        $session['lastActivity'] = time();
        $this->write($session);
    }

    /**
     * Merge values into the stored session, leaving the rest alone.
     *
     * @param array<string, mixed> $changes
     */
    public function merge(array $changes): void
    {
        $session = $this->read();
        if ($session === []) {
            return;
        }

        $this->write(array_replace_recursive($session, $changes));
    }

    /**
     * @param array<string, mixed> $session
     */
    private function hasExpired(array $session): bool
    {
        $expiresAt = $session['expiresAt'] ?? null;
        if (is_int($expiresAt) && $expiresAt < time()) {
            return true;
        }

        // A "remember me" session is deliberately exempt from the idle timeout;
        // being signed out after a lunch break is the thing it exists to avoid.
        if (($session['remember'] ?? false) === true) {
            return false;
        }

        $lastActivity = $session['lastActivity'] ?? null;

        return is_int($lastActivity)
            && $this->idleTimeoutSeconds > 0
            && (time() - $lastActivity) > $this->idleTimeoutSeconds;
    }
}
