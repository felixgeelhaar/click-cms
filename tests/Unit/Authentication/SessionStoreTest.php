<?php

declare(strict_types=1);

namespace Click\Cms\Tests\Unit\Authentication;

use Click\Cms\Application\Authentication\LoginThrottle;
use Click\Cms\Application\Authentication\SessionStore;
use PHPUnit\Framework\TestCase;

final class SessionStoreTest extends TestCase
{
    private string $dir;
    private string $path;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/click-cms-session-' . bin2hex(random_bytes(6));
        mkdir($this->dir, 0o775, true);
        $this->path = $this->dir . '/sessions';
        $_COOKIE = [];
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*/*') ?: [] as $f) {
            @unlink($f);
        }
        foreach (glob($this->dir . '/*') ?: [] as $d) {
            is_dir($d) ? @rmdir($d) : @unlink($d);
        }
        @rmdir($this->dir);
        $_COOKIE = [];
    }

    private function store(int $idleTimeout = 1800): SessionStore
    {
        return new SessionStore($this->path, $idleTimeout);
    }

    /**
     * Simulate a browser presenting the identifier it was given.
     *
     * start() issues a cookie, but setcookie() does not populate $_COOKIE
     * within the same request, so a test has to carry it across explicitly —
     * which is exactly what a browser does between requests.
     */
    private function asClientOf(SessionStore $store): void
    {
        $files = glob($this->path . '/*.json') ?: [];
        if ($files !== []) {
            $_COOKIE[SessionStore::COOKIE] = basename(end($files), '.json');
        }
    }

    public function testAnAbsentSessionReadsAsEmpty(): void
    {
        $this->assertSame([], $this->store()->read());
        $this->assertNull($this->store()->user());
    }

    public function testStartThenReadRoundTrips(): void
    {
        $store = $this->store();
        $store->start(['user' => ['username' => 'ann'], 'lastActivity' => time()]);

        $this->assertSame('ann', $store->user()['username']);
    }

    /**
     * The defect this class exists to prevent: a stored session must be
     * unreachable by a client that does not present its identifier. Previously
     * one file was read by everyone, so any visitor was treated as whoever had
     * signed in.
     */
    public function testAnotherClientCannotSeeTheSession(): void
    {
        $signedIn = $this->store();
        $signedIn->start(['user' => ['username' => 'ann'], 'lastActivity' => time()]);

        // A different visitor: same server, same files, no cookie.
        $_COOKIE = [];
        $stranger = $this->store();

        $this->assertNull($stranger->user());
        $this->assertSame([], $stranger->read());
    }

    public function testAForgedIdentifierIsIgnored(): void
    {
        $store = $this->store();
        $store->start(['user' => ['username' => 'ann'], 'lastActivity' => time()]);

        foreach (['../../etc/passwd', 'not-hex', str_repeat('a', 63), ''] as $forged) {
            $_COOKIE[SessionStore::COOKIE] = $forged;
            $this->assertNull($this->store()->user(), $forged);
        }
    }

    public function testTwoAccountsGetSeparateSessions(): void
    {
        $this->store()->start(['user' => ['username' => 'ann'], 'lastActivity' => time()]);
        $this->asClientOf($this->store());
        $annCookie = $_COOKIE[SessionStore::COOKIE];

        $_COOKIE = [];
        $this->store()->start(['user' => ['username' => 'bob'], 'lastActivity' => time()]);

        // Two files, not one: Bob signing in does not overwrite Ann's session,
        // which is what the single shared file used to do.
        $this->assertCount(2, glob($this->path . '/*.json') ?: []);

        $_COOKIE[SessionStore::COOKIE] = $annCookie;
        $this->assertSame('ann', $this->store()->user()['username']);
    }

    public function testClearRemovesIt(): void
    {
        $store = $this->store();
        $store->start(['user' => ['username' => 'ann'], 'lastActivity' => time()]);
        $store->clear();

        $this->assertSame([], $store->read());
    }

    public function testCorruptFileReadsAsEmptyRatherThanThrowing(): void
    {
        $store = $this->store();
        $store->start(['user' => ['username' => 'ann'], 'lastActivity' => time()]);

        $files = glob($this->path . '/*.json') ?: [];
        file_put_contents($files[0], '{ not json');

        $this->assertSame([], $store->read());
    }

    public function testExpiredSessionIsDiscarded(): void
    {
        $store = $this->store();
        $store->start(['user' => ['username' => 'ann'], 'expiresAt' => time() - 10]);

        $this->assertSame([], $store->read());
    }

    public function testIdleSessionIsDiscarded(): void
    {
        $store = $this->store(60);
        $store->start(['user' => ['username' => 'ann'], 'lastActivity' => time() - 3600]);

        $this->assertSame([], $store->read());
    }

    /**
     * Being signed out after a lunch break is exactly what "remember me" exists
     * to prevent, so it is exempt from the idle timeout.
     */
    public function testRememberedSessionSurvivesIdleness(): void
    {
        $store = $this->store(60);
        $store->start([
            'user' => ['username' => 'ann'],
            'lastActivity' => time() - 3600,
            'remember' => true,
        ], true);

        $this->assertSame('ann', $store->user()['username']);
    }

    public function testTouchExtendsAnActiveSession(): void
    {
        $store = $this->store(60);
        $store->start(['user' => ['username' => 'ann'], 'lastActivity' => time() - 30]);

        $store->touch();

        $this->assertGreaterThan(time() - 5, $store->read()['lastActivity']);
    }

    public function testTouchDoesNotResurrectAnExpiredSession(): void
    {
        $store = $this->store(60);
        $store->start(['user' => ['username' => 'ann'], 'lastActivity' => time() - 3600]);

        $store->touch();

        $this->assertSame([], $store->read());
    }

    public function testMergeLeavesUnrelatedValuesAlone(): void
    {
        $store = $this->store();
        $store->start([
            'user' => ['username' => 'ann', 'role' => 'admin'],
            'csrfToken' => 'keep-me',
            'lastActivity' => time(),
        ]);

        $store->merge(['user' => ['mustChangePassword' => false]]);

        $session = $store->read();
        $this->assertSame('keep-me', $session['csrfToken']);
        $this->assertSame('admin', $session['user']['role']);
        $this->assertFalse($session['user']['mustChangePassword']);
    }
}

final class LoginThrottleTest extends TestCase
{
    private string $dir;
    private string $path;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/click-cms-throttle-' . bin2hex(random_bytes(6));
        mkdir($this->dir, 0o775, true);
        $this->path = $this->dir . '/lockouts.json';
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->dir);
    }

    private function throttle(int $max = 3): LoginThrottle
    {
        return new LoginThrottle($this->path, $max, 900, 900);
    }

    public function testAnUnknownAccountIsNotLocked(): void
    {
        $this->assertFalse($this->throttle()->isLocked('ann'));
        $this->assertNull($this->throttle()->secondsRemaining('ann'));
    }

    public function testLocksOnceTheThresholdIsReached(): void
    {
        $t = $this->throttle(3);

        $t->recordFailure('ann');
        $t->recordFailure('ann');
        $this->assertFalse($t->isLocked('ann'));

        $t->recordFailure('ann');
        $this->assertTrue($t->isLocked('ann'));
        $this->assertGreaterThan(0, $t->secondsRemaining('ann'));
    }

    public function testALockAppliesOnlyToThatAccount(): void
    {
        $t = $this->throttle(2);
        $t->recordFailure('ann');
        $t->recordFailure('ann');

        $this->assertTrue($t->isLocked('ann'));
        $this->assertFalse($t->isLocked('bob'));
    }

    public function testUsernamesAreMatchedCaseInsensitively(): void
    {
        $t = $this->throttle(2);
        $t->recordFailure('Ann');
        $t->recordFailure('ANN');

        $this->assertTrue($t->isLocked('ann'));
    }

    public function testASuccessfulLoginClearsTheCount(): void
    {
        $t = $this->throttle(3);
        $t->recordFailure('ann');
        $t->recordFailure('ann');
        $t->clear('ann');
        $t->recordFailure('ann');

        $this->assertFalse($t->isLocked('ann'));
    }

    /**
     * The file should not become a readable list of accounts someone has been
     * probing.
     */
    public function testUsernamesAreNotStoredInTheClear(): void
    {
        $this->throttle()->recordFailure('ann@example.com');

        $this->assertStringNotContainsString('ann@example.com', (string) file_get_contents($this->path));
    }
}
