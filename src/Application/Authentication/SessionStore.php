<?php

declare(strict_types=1);

namespace Click\Cms\Application\Authentication;

/**
 * Reads and writes the signed-in session.
 *
 * A session is a file named after a random identifier, and that identifier is
 * held by the browser in a cookie. Both halves matter: without the cookie there
 * is nothing tying a stored session to the client that created it.
 *
 * This previously wrote one file with no client binding at all, which meant
 * that while anybody was signed in, every visitor to the site was treated as
 * that person — an anonymous request reached management endpoints simply
 * because an administrator happened to be logged in elsewhere.
 */
final class SessionStore
{
    public const COOKIE = 'click_session';

    private ?string $id = null;

    public function __construct(
        private readonly string $directory,
        private readonly int $idleTimeoutSeconds = 1800,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function read(): array
    {
        $id = $this->currentId();
        if ($id === null) {
            return [];
        }

        $path = $this->pathFor($id);
        if (!is_file($path)) {
            return [];
        }

        $decoded = json_decode((string) @file_get_contents($path), true);
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
     * Start a session and hand its identifier to the browser.
     *
     * @param array<string, mixed> $session
     */
    public function start(array $session, bool $remember = false): void
    {
        $this->id = bin2hex(random_bytes(32));
        $this->writeFile($this->id, $session);
        $this->sendCookie($this->id, $remember ? time() + 2_592_000 : 0);
    }

    /**
     * Update the session already in progress.
     *
     * @param array<string, mixed> $session
     */
    public function write(array $session): void
    {
        $id = $this->currentId();
        if ($id === null) {
            return;
        }

        $this->writeFile($id, $session);
    }

    public function clear(): void
    {
        $id = $this->currentId();

        if ($id !== null) {
            @unlink($this->pathFor($id));
        }

        $this->id = null;
        // Expire the cookie so the browser stops presenting a dead identifier.
        $this->sendCookie('', time() - 3600);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function user(): ?array
    {
        $user = $this->read()['user'] ?? null;

        return is_array($user) ? $user : null;
    }

    /**
     * Record that the session is still in use, so the idle timeout measures
     * inactivity rather than session age.
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
     * The identifier the browser presented, if it is one this store could have
     * issued. Anything else is ignored rather than used to build a path.
     */
    private function currentId(): ?string
    {
        if ($this->id !== null) {
            return $this->id;
        }

        $candidate = $_COOKIE[self::COOKIE] ?? null;

        if (!is_string($candidate) || preg_match('/^[a-f0-9]{64}$/', $candidate) !== 1) {
            return null;
        }

        return $this->id = $candidate;
    }

    private function pathFor(string $id): string
    {
        return $this->directory . '/' . $id . '.json';
    }

    /**
     * @param array<string, mixed> $session
     */
    /**
     * Replace the session file atomically.
     *
     * Written to a neighbouring temporary file and renamed into place, because
     * `file_put_contents` — even with `LOCK_EX` — truncates the target when it
     * opens it and only then takes the lock, while {@see read()} takes no lock
     * at all. A reader landing in that window read an empty file, decoded
     * nothing, and was told it was not authenticated.
     *
     * That was not theoretical. Every request touches the session to record
     * activity, so a single admin page load firing half a dozen requests raced
     * itself: roughly one request in thirteen came back 401 on a session that
     * was valid the whole time, which surfaced as a picker reporting an empty
     * media library and a comments panel refusing permission.
     *
     * `rename` within one directory is atomic on POSIX, so a concurrent reader
     * now sees either the whole previous session or the whole new one.
     */
    private function writeFile(string $id, array $session): void
    {
        if (!is_dir($this->directory) && !@mkdir($this->directory, 0o700, true) && !is_dir($this->directory)) {
            return;
        }

        $path = $this->pathFor($id);
        // Same directory, so the rename stays within one filesystem; unique per
        // writer, so two concurrent writes cannot corrupt each other's staging.
        $temporary = $path . '.' . bin2hex(random_bytes(8)) . '.tmp';

        if (@file_put_contents($temporary, json_encode($session, JSON_PRETTY_PRINT)) === false) {
            @unlink($temporary);

            return;
        }

        // Set before the rename: the file must never be readable by anyone else,
        // not even for the moment between appearing and being locked down.
        @chmod($temporary, 0o600);

        if (!@rename($temporary, $path)) {
            @unlink($temporary);

            return;
        }

        $this->collectGarbage();
    }

    private function sendCookie(string $value, int $expires): void
    {
        if (headers_sent()) {
            return;
        }

        setcookie(self::COOKIE, $value, [
            'expires' => $expires,
            'path' => '/',
            // Not readable from JavaScript, so cross-site scripting cannot
            // simply lift the identifier.
            'httponly' => true,
            // Not sent on cross-site requests, which is defence in depth behind
            // the CSRF token rather than a replacement for it.
            'samesite' => 'Lax',
            'secure' => (($_SERVER['HTTPS'] ?? '') !== '') || (($_SERVER['SERVER_PORT'] ?? '') === '443'),
        ]);
    }

    /**
     * Remove sessions nobody can present any more.
     *
     * Without this the directory grows by one file per login for ever.
     */
    private function collectGarbage(): void
    {
        // Cheap, and often enough that the directory cannot run away.
        if (random_int(1, 50) !== 1) {
            return;
        }

        $cutoff = time() - max(86_400, $this->idleTimeoutSeconds * 4);

        // Staging files are swept too. One only survives a crash between writing
        // and renaming, which is rare — and rare is exactly what accumulates
        // unnoticed when nothing collects it.
        $stale = array_merge(
            glob($this->directory . '/*.json') ?: [],
            glob($this->directory . '/*.tmp') ?: [],
        );

        foreach ($stale as $file) {
            if (@filemtime($file) < $cutoff) {
                @unlink($file);
            }
        }
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
        // being signed out after a lunch break is what it exists to avoid.
        if (($session['remember'] ?? false) === true) {
            return false;
        }

        $lastActivity = $session['lastActivity'] ?? null;

        return is_int($lastActivity)
            && $this->idleTimeoutSeconds > 0
            && (time() - $lastActivity) > $this->idleTimeoutSeconds;
    }
}
