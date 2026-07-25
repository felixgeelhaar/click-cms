<?php

declare(strict_types=1);

namespace Click\Cms\Tests\Unit\Application\Authentication;

use Click\Cms\Application\Authentication\SessionStore;
use PHPUnit\Framework\TestCase;

/**
 * A session must survive being read while it is being written.
 *
 * Every authenticated request records activity, so an admin page load firing
 * half a dozen requests writes the same session file several times over while
 * also reading it. The store previously wrote with `file_put_contents(...,
 * LOCK_EX)`, which truncates the target when it opens it and only then takes
 * the lock — and `read()` takes no lock at all. A reader landing in that window
 * decoded an empty file and reported nobody was signed in.
 *
 * Measured against a running container, about one request in thirteen came back
 * 401 on a session that was valid throughout. It surfaced as a media picker
 * announcing an empty library and a comments panel refusing permission — both
 * of which look like product faults rather than a torn read.
 *
 * These spawn real concurrent processes rather than simulating the race, since
 * a single-threaded simulation could only assert the fix's shape and not that
 * it holds.
 */
final class SessionStoreConcurrencyTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/click-cms-session-race-' . bin2hex(random_bytes(6));
        mkdir($this->dir, 0o700, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->dir);
    }

    private const SESSION_ID = 'a1b2c3d4e5f60718293a4b5c6d7e8f90a1b2c3d4e5f60718293a4b5c6d7e8f90';

    /** @return array<string, mixed> a session big enough that a torn read is likely */
    private function session(): array
    {
        return [
            'username' => 'admin',
            'loginTime' => time(),
            'expiresAt' => time() + 3600,
            'remember' => false,
            'lastActivity' => time(),
            'sessionId' => bin2hex(random_bytes(16)),
            'csrfToken' => bin2hex(random_bytes(32)),
            'user' => [
                'username' => 'admin',
                'displayName' => 'Administrator',
                'email' => 'admin@example.com',
                'role' => 'admin',
                // A real payload carries every capability name, which is what
                // makes the file big enough for a partial write to be visible.
                'capabilities' => array_map(
                    static fn (int $i): string => "capability.number.{$i}",
                    range(1, 60)
                ),
                'mustChangePassword' => false,
            ],
        ];
    }

    private function seed(): void
    {
        $store = new SessionStore($this->dir);
        $_COOKIE[SessionStore::COOKIE] = self::SESSION_ID;
        $store->write($this->session());

        self::assertFileExists($this->dir . '/' . self::SESSION_ID . '.json', 'The session was not seeded.');
    }

    /**
     * Run readers and writers against the same session at once, and report how
     * many readers saw nobody signed in.
     */
    private function hammer(int $writers, int $readers, int $iterations): int
    {
        $root = dirname(__DIR__, 4);
        $script = <<<'PHP'
            <?php
            require $argv[1] . '/vendor/autoload.php';
            $dir = $argv[2];
            $id = $argv[3];
            $role = $argv[4];
            $iterations = (int) $argv[5];
            $_COOKIE[Click\Cms\Application\Authentication\SessionStore::COOKIE] = $id;

            $missing = 0;
            for ($i = 0; $i < $iterations; $i++) {
                $store = new Click\Cms\Application\Authentication\SessionStore($dir);
                if ($role === 'writer') {
                    $store->touch();
                    continue;
                }
                if (($store->read()['username'] ?? null) !== 'admin') {
                    $missing++;
                }
            }
            echo $missing;
            PHP;

        $scriptPath = $this->dir . '/worker.php';
        file_put_contents($scriptPath, $script);

        $processes = [];
        $pipes = [];

        foreach (array_merge(
            array_fill(0, $writers, 'writer'),
            array_fill(0, $readers, 'reader'),
        ) as $index => $role) {
            $command = sprintf(
                '%s %s %s %s %s %d',
                escapeshellarg(PHP_BINARY),
                escapeshellarg($scriptPath),
                escapeshellarg($root),
                escapeshellarg($this->dir),
                escapeshellarg(self::SESSION_ID) . ' ' . escapeshellarg($role),
                $iterations
            );

            $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $handles);
            if (!is_resource($process)) {
                self::markTestSkipped('Could not spawn a worker process.');
            }
            $processes[$index] = $process;
            $pipes[$index] = $handles;
        }

        $missing = 0;
        foreach ($processes as $index => $process) {
            $out = stream_get_contents($pipes[$index][1]);
            fclose($pipes[$index][1]);
            fclose($pipes[$index][2]);
            proc_close($process);
            $missing += (int) $out;
        }

        @unlink($scriptPath);

        return $missing;
    }

    public function testAReaderNeverSeesAHalfWrittenSession(): void
    {
        $this->seed();

        // Six writers is an ordinary admin page load: every request touches the
        // session to record activity.
        $missing = $this->hammer(writers: 6, readers: 6, iterations: 120);

        self::assertSame(
            0,
            $missing,
            "{$missing} concurrent reads saw no signed-in user while the session was valid throughout."
        );
    }

    public function testTheSessionFileIsAlwaysCompleteJson(): void
    {
        $this->seed();

        $this->hammer(writers: 8, readers: 0, iterations: 80);

        $contents = (string) file_get_contents($this->dir . '/' . self::SESSION_ID . '.json');

        self::assertNotSame('', $contents, 'The session file was left empty.');
        self::assertIsArray(json_decode($contents, true), 'The session file was left as invalid JSON.');
    }

    /** Staging files must not survive a completed write. */
    public function testNoTemporaryFilesAreLeftBehind(): void
    {
        $this->seed();

        $this->hammer(writers: 4, readers: 0, iterations: 40);

        self::assertSame([], glob($this->dir . '/*.tmp') ?: []);
    }
}
