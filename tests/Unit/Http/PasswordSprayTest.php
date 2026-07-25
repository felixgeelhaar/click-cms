<?php

declare(strict_types=1);

namespace Click\Cms\Tests\Unit\Http;

use Click\Cms\Application\Authentication\LoginThrottle;
use Click\Cms\Application\Authentication\SessionStore;
use Click\Cms\Application\Config\CoreConfig;
use Click\Cms\Application\Content\ContentService;
use Click\Cms\Domain\Content\Content;
use Click\Cms\Http\AuthController;
use Click\Cms\Infrastructure\Storage\JsonStorage;
use PHPUnit\Framework\TestCase;

/**
 * The login path under a password spray.
 *
 * The per-account lockout answers "is this account being ground at", and it is
 * blind by construction to the attack that runs the other way round: one common
 * password tried once each against every account on the site. Nobody reaches
 * their own threshold, no lockout fires, and the whole user list gets tested in
 * a few minutes. These tests pin the site-wide ceiling that closes that gap —
 * and, just as importantly, pin that it does not fire for the everyday case of
 * one person mistyping their own password, because a defence that goes off
 * during ordinary work is one an operator turns off.
 */
final class PasswordSprayTest extends TestCase
{
    private string $base;
    private ContentService $content;

    protected function setUp(): void
    {
        $this->base = sys_get_temp_dir() . '/click-cms-spray-http-' . bin2hex(random_bytes(6));
        mkdir($this->base . '/content', 0o775, true);
        mkdir($this->base . '/sessions', 0o700, true);

        $_COOKIE = [];
        $_POST = [];
        $_SERVER['REQUEST_METHOD'] = 'POST';

        $this->content = new ContentService(new JsonStorage($this->base . '/content'));
    }

    protected function tearDown(): void
    {
        $_COOKIE = [];
        $_POST = [];
        $this->removeTree($this->base);
    }

    private function removeTree(string $path): void
    {
        if (!is_dir($path)) {
            @unlink($path);
            return;
        }
        foreach (scandir($path) ?: [] as $e) {
            if ($e !== '.' && $e !== '..') {
                $this->removeTree($path . '/' . $e);
            }
        }
        @rmdir($path);
    }

    /**
     * A controller whose per-account threshold is three and whose site-wide
     * ceiling the test chooses, so the two limits can be told apart by which
     * one fires.
     */
    private function controller(int $sprayMax): AuthController
    {
        $auth = new AuthController(
            new SessionStore($this->base . '/sessions', 1800),
            $this->throttle(),
            $this->content,
            CoreConfig::fromArray(['core' => ['auth' => [
                'sprayMaxFailures' => $sprayMax,
                'sprayWindowSeconds' => 900,
            ]]]),
            'admin',
        );

        $auth->ensureDefaultAdminUser();

        return $auth;
    }

    private function throttle(): LoginThrottle
    {
        return new LoginThrottle($this->base . '/lockouts.json', 3, 900, 900);
    }

    /** Real accounts, so a spray is refused for the password and not the name. */
    private function seedAccounts(int $count): array
    {
        $usernames = [];
        for ($i = 1; $i <= $count; $i++) {
            $username = 'editor-' . $i;
            $usernames[] = $username;
            $this->content->save(Content::create($this->content->userKey($username), [
                'username' => $username,
                'displayName' => 'Editor ' . $i,
                'role' => 'editor',
                'status' => 'active',
                'password' => password_hash('the-real-password-' . $i, PASSWORD_DEFAULT),
            ]));
        }

        return $usernames;
    }

    private function post(AuthController $auth, string $action, array $body): array
    {
        // php://input cannot be set in-process, so the controller's fallback to
        // $_POST carries the body — the same array it would decode.
        $_POST = $body;
        $result = $auth->handle('auth/' . $action, 'POST');
        $_POST = [];
        return $result;
    }

    /* --------------------------------------------------------------- spray -- */

    /**
     * The whole point. Six accounts, one guess each, nobody within reach of
     * their own three-strike lockout — and the site still stops answering.
     */
    public function testASprayAcrossManyAccountsIsStoppedThoughNoAccountIsLockedOut(): void
    {
        $auth = $this->controller(6);
        $usernames = $this->seedAccounts(6);

        foreach ($usernames as $username) {
            $result = $this->post($auth, 'login', ['username' => $username, 'password' => 'Summer2026!']);
            $this->assertSame(401, $result['status'], "{$username} is refused on the merits, not throttled");
        }

        // Not one of them is anywhere near its own lockout: a defence built
        // only per account would have seen nothing at all here.
        $throttle = $this->throttle();
        foreach ($usernames as $username) {
            $this->assertFalse($throttle->isLocked($username), "{$username} must not be locked out");
        }

        $next = $this->post($auth, 'login', ['username' => 'editor-7', 'password' => 'Summer2026!']);

        $this->assertSame(429, $next['status'], 'the site-wide ceiling must refuse the next attempt');
        $this->assertArrayHasKey('retryAfter', $next);
    }

    /**
     * The cost of the ceiling, stated as a test so it cannot be changed by
     * accident: while it holds, a legitimate password is refused too.
     *
     * Letting correct credentials through would be no limit at all — the
     * refusal would answer "wrong" and the success would answer "right", and an
     * attacker could keep testing guesses straight through the block. Fail
     * closed, and keep the block no longer than the failures that caused it.
     */
    public function testTheRightPasswordIsRefusedWhileTheCeilingHolds(): void
    {
        $auth = $this->controller(3);
        $usernames = $this->seedAccounts(3);

        foreach ($usernames as $username) {
            $this->post($auth, 'login', ['username' => $username, 'password' => 'Summer2026!']);
        }

        $result = $this->post($auth, 'login', ['username' => 'admin', 'password' => 'admin']);

        $this->assertSame(429, $result['status']);
        $this->assertArrayNotHasKey('data', $result, 'no session may be handed out during the cool-off');
    }

    /**
     * A refusal must read the same whoever asked and whatever they named, or it
     * becomes a way to enumerate accounts during exactly the moment somebody is
     * trying to.
     */
    public function testTheRefusalSaysNothingAboutTheAccountNamed(): void
    {
        $auth = $this->controller(3);

        // Trip the ceiling on names that do not exist, then ask about two
        // accounts — one real, one invented.
        for ($i = 0; $i < 3; $i++) {
            $this->post($auth, 'login', ['username' => 'ghost-' . $i, 'password' => 'Summer2026!']);
        }

        $real = $this->post($auth, 'login', ['username' => 'admin', 'password' => 'nope']);
        $invented = $this->post($auth, 'login', ['username' => 'nobody-at-all', 'password' => 'nope']);

        $this->assertSame($real['status'], $invented['status']);
        $this->assertSame($real['error'], $invented['error']);
    }

    public function testTheCeilingLiftsOnceTheWindowHasPassed(): void
    {
        $auth = $this->controller(3);
        $usernames = $this->seedAccounts(3);

        foreach ($usernames as $username) {
            $this->post($auth, 'login', ['username' => $username, 'password' => 'Summer2026!']);
        }
        $this->assertSame(429, $this->post($auth, 'login', ['username' => 'admin', 'password' => 'admin'])['status']);

        // Age the recorded failures out of the window rather than waiting a
        // quarter of an hour for them to do it themselves.
        $this->backdateSprayFailures(1_000);

        $result = $this->post($this->controller(3), 'login', ['username' => 'admin', 'password' => 'admin']);

        $this->assertTrue($result['data']['success'], 'the site must let people in again on its own');
    }

    private function backdateSprayFailures(int $seconds): void
    {
        $path = $this->base . '/login-spray.json';
        $state = json_decode((string) file_get_contents($path), true);
        $state['failures'] = array_map(
            static fn (int $stamp): int => $stamp - $seconds,
            $state['failures']
        );
        file_put_contents($path, json_encode($state));
    }

    /* ------------------------------------------------------ ordinary use -- */

    /**
     * One person having a bad morning is not an attack. Their own account's
     * lockout is the right scope for it, and it must not spill over into
     * everybody else's ability to sign in.
     */
    public function testOneAccountsRepeatedFumblesDoNotCloseTheSiteToOthers(): void
    {
        $auth = $this->controller(50);
        $this->seedAccounts(1);

        for ($i = 0; $i < 3; $i++) {
            $this->post($auth, 'login', ['username' => 'admin', 'password' => 'wrong']);
        }

        $other = $this->post($auth, 'login', ['username' => 'editor-1', 'password' => 'also-wrong']);
        $this->assertSame(401, $other['status'], 'a bystander is answered on the merits');

        $good = $this->post($auth, 'login', ['username' => 'editor-1', 'password' => 'the-real-password-1']);
        $this->assertTrue($good['data']['success'], 'and can still sign in');
    }

    /**
     * The regression guard: adding a site-wide ceiling must leave the
     * per-account lockout doing exactly what it did before.
     */
    public function testThePerAccountLockoutStillFiresAtItsOwnThreshold(): void
    {
        $auth = $this->controller(50);

        for ($i = 0; $i < 3; $i++) {
            $this->assertSame(
                401,
                $this->post($auth, 'login', ['username' => 'admin', 'password' => 'wrong'])['status'],
                "attempt {$i} is refused on the merits"
            );
        }

        $locked = $this->post($auth, 'login', ['username' => 'admin', 'password' => 'wrong']);
        $this->assertSame(429, $locked['status']);
        $this->assertGreaterThan(0, $locked['retryAfter']);
        $this->assertLessThanOrEqual(900, $locked['retryAfter']);

        // Even the right password waits out that account's lockout, as before.
        $this->assertSame(429, $this->post($auth, 'login', ['username' => 'admin', 'password' => 'admin'])['status']);
        $this->assertTrue($this->throttle()->isLocked('admin'));
    }

    /**
     * A signed-in editor mistyping their current password on the change form is
     * not a credential attack — nobody is guessing their way in, they are
     * already in. Counting those would let ordinary clumsiness walk the site
     * towards refusing logins for everyone.
     */
    public function testAFumbledPasswordChangeDoesNotCountTowardsTheCeiling(): void
    {
        $auth = $this->controller(2);
        $this->seedAccounts(1);
        $this->post($auth, 'login', ['username' => 'admin', 'password' => 'admin']);

        for ($i = 0; $i < 3; $i++) {
            $result = $this->post($auth, 'password', [
                'currentPassword' => 'not-my-password',
                'newPassword' => 'a-proper-password',
            ]);
            $this->assertSame(403, $result['status']);
        }

        $other = $this->post($auth, 'login', ['username' => 'editor-1', 'password' => 'wrong']);

        $this->assertSame(401, $other['status'], 'the site is not in cool-off');
    }
}
