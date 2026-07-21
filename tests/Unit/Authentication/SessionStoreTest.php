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
        $this->path = $this->dir . '/session.json';
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->dir);
    }

    private function store(int $idleTimeout = 1800): SessionStore
    {
        return new SessionStore($this->path, $idleTimeout);
    }

    public function testAnAbsentSessionReadsAsEmpty(): void
    {
        $this->assertSame([], $this->store()->read());
        $this->assertNull($this->store()->user());
    }

    public function testWriteThenReadRoundTrips(): void
    {
        $store = $this->store();
        $store->write(['user' => ['username' => 'ann'], 'lastActivity' => time()]);

        $this->assertSame('ann', $store->user()['username']);
    }

    public function testClearRemovesIt(): void
    {
        $store = $this->store();
        $store->write(['user' => ['username' => 'ann'], 'lastActivity' => time()]);
        $store->clear();

        $this->assertSame([], $store->read());
    }

    public function testCorruptFileReadsAsEmptyRatherThanThrowing(): void
    {
        file_put_contents($this->path, '{ not json');

        $this->assertSame([], $this->store()->read());
    }

    public function testExpiredSessionIsDiscarded(): void
    {
        $store = $this->store();
        $store->write(['user' => ['username' => 'ann'], 'expiresAt' => time() - 10]);

        $this->assertSame([], $store->read());
        $this->assertFileDoesNotExist($this->path);
    }

    public function testIdleSessionIsDiscarded(): void
    {
        $store = $this->store(60);
        $store->write(['user' => ['username' => 'ann'], 'lastActivity' => time() - 3600]);

        $this->assertSame([], $store->read());
    }

    /**
     * Being signed out after a lunch break is exactly what "remember me" exists
     * to prevent, so it is exempt from the idle timeout.
     */
    public function testRememberedSessionSurvivesIdleness(): void
    {
        $store = $this->store(60);
        $store->write([
            'user' => ['username' => 'ann'],
            'lastActivity' => time() - 3600,
            'remember' => true,
        ]);

        $this->assertSame('ann', $store->user()['username']);
    }

    public function testTouchExtendsAnActiveSession(): void
    {
        $store = $this->store(60);
        $store->write(['user' => ['username' => 'ann'], 'lastActivity' => time() - 30]);

        $store->touch();

        $this->assertGreaterThan(time() - 5, $store->read()['lastActivity']);
    }

    public function testTouchDoesNotResurrectAnExpiredSession(): void
    {
        $store = $this->store(60);
        $store->write(['user' => ['username' => 'ann'], 'lastActivity' => time() - 3600]);

        $store->touch();

        $this->assertSame([], $store->read());
    }

    public function testMergeLeavesUnrelatedValuesAlone(): void
    {
        $store = $this->store();
        $store->write([
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
