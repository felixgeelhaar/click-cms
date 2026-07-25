<?php

declare(strict_types=1);

namespace Click\Cms\Tests\Unit\Plugins;

use Click\Cms\Application\Plugin\AuthGate;
use Click\Cms\Application\Plugin\PublishGate;
use Click\Cms\Core\Application;
use Click\Cms\Http\AuthController;
use PHPUnit\Framework\TestCase;

/**
 * The authentication events as a real plugin actually receives them.
 *
 * The gate's contract is pinned in `Tests\Unit\Plugin\AuthGateTest`. What is left
 * — and what decides whether any of this is worth having — is that a plugin
 * dropped into `plugins/`, declaring these hooks in its `plugin.json`, is really
 * reached by a real sign-in through a kernel booted from disk, with payloads it
 * can act on and no secret in them. The content events could lean on a storage
 * decorator no write path can miss; authentication has no such seam, so the call
 * sites are explicit and this is the test that they are all there.
 *
 * Two plugins are installed, both active, both declaring all five hooks. One
 * observes and logs; one throws on every hook and sorts first, so it is
 * dispatched ahead of the observer at every step. Every assertion about what the
 * observer saw is therefore simultaneously an assertion that a broken extension
 * changed nothing — which for authentication is not a nicety: a fail-closed gate
 * plus one throwing plugin would lock everybody out of the only UI that can
 * disable it.
 */
final class AuthEventsWiringTest extends TestCase
{
    /**
     * Built once for the whole class. A plugin bootstrap is `require_once`d into
     * the one PHP process, so a fresh directory per test would try to declare
     * the same class from a new path.
     */
    private static string $base = '';

    private Application $app;

    /** Every hook this work adds. */
    private const HOOKS = [
        'auth.before_login',
        'auth.logged_in',
        'auth.login_failed',
        'auth.logged_out',
        'auth.locked_out',
    ];

    public static function setUpBeforeClass(): void
    {
        self::$base = sys_get_temp_dir() . '/click-cms-auth-events-' . bin2hex(random_bytes(6));

        foreach (['content', 'data', 'config', 'plugins'] as $dir) {
            mkdir(self::$base . '/' . $dir, 0o775, true);
        }

        file_put_contents(
            self::$base . '/config/core.json',
            json_encode(['core' => [
                'languages' => ['default' => 'en', 'available' => ['en']],
                'auth' => [
                    // Three strikes, so a lockout can be reached in a test
                    // without pretending time has passed, and a short lock so
                    // the announced retryAfter is unmistakably this setting.
                    'lockoutMaxAttempts' => 3,
                    'lockoutDurationSeconds' => 120,
                ],
            ]])
        );

        self::observerPlugin();
        self::brokenPlugin();
    }

    public static function tearDownAfterClass(): void
    {
        self::removeTree(self::$base);
    }

    private static function observerPlugin(): void
    {
        $dir = self::$base . '/plugins/auth-observer';
        mkdir($dir, 0o775, true);

        file_put_contents($dir . '/plugin.json', json_encode([
            'name' => 'Auth Observer',
            'description' => 'Records every authentication event it is given.',
            'version' => '1.0.0',
            'author' => 'test',
            'dependencies' => [],
            'hooks' => self::HOOKS,
        ]));

        // Refusal is driven by a file so one fixture can demonstrate both the
        // permitting and the refusing side of the veto.
        $methods = '';
        foreach (self::HOOKS as $hook) {
            $method = 'hook_' . str_replace('.', '_', $hook);
            $methods .= <<<PHP

                public function {$method}(array \$params) {
                    return \$this->observe('{$hook}', \$params);
                }
            PHP;
        }

        file_put_contents($dir . '/bootstrap.php', <<<PHP
        <?php
        class Plugin_auth_observer {
            public function __construct(\$manager) {}
            public function activate(): bool { return true; }

            private function observe(string \$hook, array \$params) {
                \$line = json_encode(['hook' => \$hook] + \$params);
                file_put_contents(__DIR__ . '/../../data/auth-events.log', \$line . "\\n", FILE_APPEND);

                \$refuse = __DIR__ . '/../../data/refuse-' . \$hook;
                if (file_exists(\$refuse)) {
                    return ['allowed' => false, 'reason' => trim((string) file_get_contents(\$refuse))];
                }

                return null;
            }
        {$methods}
        }
        PHP);
    }

    /**
     * Named so it sorts before the observer, and therefore runs first. A fixture
     * where the survivor went first would pass whatever the implementation did.
     */
    private static function brokenPlugin(): void
    {
        $dir = self::$base . '/plugins/auth-breaker';
        mkdir($dir, 0o775, true);

        file_put_contents($dir . '/plugin.json', json_encode([
            'name' => 'Auth Breaker',
            'description' => 'Throws on every authentication hook it declares.',
            'version' => '1.0.0',
            'author' => 'test',
            'dependencies' => [],
            'hooks' => self::HOOKS,
        ]));

        $methods = '';
        foreach (self::HOOKS as $hook) {
            $method = 'hook_' . str_replace('.', '_', $hook);
            $methods .= <<<PHP

                public function {$method}(array \$params) {
                    throw new \\RuntimeException('{$hook} is broken');
                }
            PHP;
        }

        file_put_contents($dir . '/bootstrap.php', <<<PHP
        <?php
        class Plugin_auth_breaker {
            public function __construct(\$manager) {}
            public function activate(): bool { return true; }
        {$methods}
        }
        PHP);
    }

    protected function setUp(): void
    {
        @unlink(self::$base . '/data/auth-events.log');
        @unlink(self::$base . '/data/lockouts.json');
        @unlink(self::$base . '/data/login-spray.json');
        foreach (glob(self::$base . '/data/refuse-*') ?: [] as $flag) {
            @unlink($flag);
        }

        $_COOKIE = [];
        $_POST = [];
        $_SERVER['REQUEST_URI'] = '/';
        $_SERVER['REQUEST_METHOD'] = 'GET';

        $this->app = new Application(self::$base);
        $this->app->boot();
    }

    protected function tearDown(): void
    {
        $_COOKIE = [];
        $_POST = [];
        // The kernel installs the process-wide dispatcher; forget it so a later
        // test cannot dispatch into plugins loaded from a directory that is gone.
        PublishGate::useAmbient(null);
    }

    /**
     * The kernel's own auth controller — the one the HTTP layer routes to, built
     * during boot with no gate handed to it, so what this test drives is the
     * production wiring rather than a controller assembled for the occasion.
     */
    private function auth(): AuthController
    {
        return (new \ReflectionProperty($this->app, 'authController'))->getValue($this->app);
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    private function post(string $action, array $body = []): array
    {
        // php://input cannot be set in-process, so the controller's fallback to
        // $_POST carries the body — the same array it would decode.
        $_POST = $body;
        $result = $this->auth()->handle('auth/' . $action, 'POST');
        $_POST = [];

        return $result;
    }

    private function refuse(string $hook, string $reason): void
    {
        file_put_contents(self::$base . '/data/refuse-' . $hook, $reason);
    }

    /** @return list<array<string, mixed>> */
    private function events(): array
    {
        $log = self::$base . '/data/auth-events.log';
        if (!file_exists($log)) {
            return [];
        }

        $out = [];
        foreach (explode("\n", trim((string) file_get_contents($log))) as $line) {
            if ($line !== '') {
                $out[] = json_decode($line, true);
            }
        }

        return $out;
    }

    /** @return list<string> */
    private function observed(): array
    {
        return array_map(static fn (array $e): string => (string) $e['hook'], $this->events());
    }

    /** @return array<string, mixed> */
    private function payloadFor(string $hook): array
    {
        foreach ($this->events() as $event) {
            if ($event['hook'] === $hook) {
                unset($event['hook']);

                return $event;
            }
        }

        $this->fail("no {$hook} reached the plugin");
    }

    /* ------------------------------------------------------------ the proof -- */

    /**
     * A good sign-in and a sign-out, and the exact payloads a plugin saw.
     *
     * This is the test the feature exists for: the seeded admin signing in
     * through the kernel's own controller, with a plugin throwing on every hook
     * dispatched ahead of the observer at every step.
     */
    public function testASignInAndSignOutAreObservedWithTheirExactPayloads(): void
    {
        $login = $this->post('login', ['username' => 'admin', 'password' => 'admin', 'remember' => true]);
        $this->assertTrue($login['data']['success'], 'the sign-in happened');

        $logout = $this->post('logout');
        $this->assertTrue($logout['data']['success']);

        $this->assertSame([
            'auth.before_login',
            'auth.logged_in',
            'auth.logged_out',
        ], $this->observed());

        $this->assertSame([
            'username' => 'admin',
            'remember' => true,
            'role' => 'admin',
            // The seeded account must change its password, which is a fact worth
            // having in an audit trail and is not a secret.
            'mustChangePassword' => true,
        ], $this->payloadFor('auth.before_login'));

        $this->assertSame([
            'username' => 'admin',
            'remember' => true,
            'role' => 'admin',
            'mustChangePassword' => true,
        ], $this->payloadFor('auth.logged_in'));

        $this->assertSame([
            'username' => 'admin',
            'role' => 'admin',
            'mustChangePassword' => true,
        ], $this->payloadFor('auth.logged_out'));
    }

    /**
     * Whatever else these payloads carry, they do not carry the credential. The
     * seeded admin's document holds a real bcrypt hash and the session holds a
     * token, and a plugin sees neither.
     */
    public function testNoPasswordHashSessionTokenOrEmailReachesThePlugin(): void
    {
        $this->post('login', ['username' => 'admin', 'password' => 'admin']);
        $session = $this->app->getContentService()->user('admin');
        $hash = (string) $session->data['password'];
        $this->post('logout');

        $raw = (string) file_get_contents(self::$base . '/data/auth-events.log');

        $this->assertStringContainsString('auth.logged_in', $raw, 'the events did fire');
        $this->assertStringNotContainsString($hash, $raw);
        $this->assertStringNotContainsString('$2y$', $raw);
        $this->assertStringNotContainsString('password', $raw, 'not even the field name');
        $this->assertStringNotContainsString('admin@example.com', $raw);
        $this->assertStringNotContainsString('csrf', $raw);
        $this->assertStringNotContainsString('sessionId', $raw);
    }

    /**
     * The enumeration question, through the real controller. A wrong password
     * against a real account and an attempt against an account that does not
     * exist are answered identically to the caller — and are reported
     * identically to the plugin, which is the point.
     */
    public function testAWrongPasswordAndAnUnknownAccountAreIndistinguishableToAPlugin(): void
    {
        $wrong = $this->post('login', ['username' => 'admin', 'password' => 'not-the-password']);
        $ghost = $this->post('login', ['username' => 'nobody-here', 'password' => 'not-the-password']);

        $this->assertSame(401, $wrong['status']);
        $this->assertSame(401, $ghost['status']);
        $this->assertSame($wrong['error'], $ghost['error']);

        $this->assertSame([
            ['hook' => 'auth.login_failed', 'username' => 'admin', 'reason' => 'invalid_credentials'],
            ['hook' => 'auth.login_failed', 'username' => 'nobody-here', 'reason' => 'invalid_credentials'],
        ], $this->events());
    }

    /** No session was created, so nothing announced one. */
    public function testAFailedSignInAnnouncesNoSignIn(): void
    {
        $this->post('login', ['username' => 'admin', 'password' => 'not-the-password']);

        $this->assertNotContains('auth.logged_in', $this->observed());
        $this->assertFalse($this->auth()->handle('auth/check', 'GET')['data']['authenticated']);
    }

    /**
     * The event an operator actually wants: the moment an account goes over its
     * threshold, once, with how long the door stays shut.
     */
    public function testTheLockoutIsAnnouncedOnceAtTheMomentItIsEstablished(): void
    {
        for ($attempt = 1; $attempt <= 3; $attempt++) {
            $this->post('login', ['username' => 'admin', 'password' => 'wrong-' . $attempt]);
        }

        $this->assertSame([
            'auth.login_failed',
            'auth.login_failed',
            'auth.login_failed',
            'auth.locked_out',
        ], $this->observed(), 'the lockout is announced after the failure that caused it');

        $lockout = $this->payloadFor('auth.locked_out');
        $this->assertSame('admin', $lockout['username']);
        $this->assertGreaterThan(0, $lockout['retryAfter']);
        $this->assertLessThanOrEqual(120, $lockout['retryAfter']);

        // Further attempts are refused by the lockout before anything reaches a
        // plugin: no second lockout event, and no failed-sign-in event either,
        // because an attacker must not be able to drive plugin work by knocking.
        $refused = $this->post('login', ['username' => 'admin', 'password' => 'wrong-again']);
        $this->assertSame(429, $refused['status']);
        $this->assertSame([
            'auth.login_failed',
            'auth.login_failed',
            'auth.login_failed',
            'auth.locked_out',
        ], $this->observed(), 'a locked-out attempt is silent');
    }

    /**
     * A lockout must not be reachable with the right password either: the veto
     * sits behind the lockout, so a locked account never reaches plugin code.
     */
    public function testALockedOutAccountNeverReachesTheVeto(): void
    {
        for ($attempt = 1; $attempt <= 3; $attempt++) {
            $this->post('login', ['username' => 'admin', 'password' => 'wrong-' . $attempt]);
        }

        $correct = $this->post('login', ['username' => 'admin', 'password' => 'admin']);

        $this->assertSame(429, $correct['status']);
        $this->assertNotContains('auth.before_login', $this->observed());
    }

    /* ----------------------------------------------------------- the veto -- */

    /**
     * What a second-factor plugin needs: credentials accepted, sign-in refused,
     * nobody signed in, and a reason the legitimate user can act on.
     */
    public function testAPluginCanRefuseASignInThatHadTheRightPassword(): void
    {
        $this->refuse('auth.before_login', 'Enter the code from your authenticator app.');

        $result = $this->post('login', ['username' => 'admin', 'password' => 'admin']);

        $this->assertSame(403, $result['status']);
        $this->assertSame('Enter the code from your authenticator app.', $result['error']);
        $this->assertArrayNotHasKey('data', $result);

        $this->assertFalse(
            $this->auth()->handle('auth/check', 'GET')['data']['authenticated'],
            'a refusal that left somebody signed in would not be a refusal'
        );

        $this->assertSame([
            'auth.before_login',
            'auth.login_failed',
        ], $this->observed());
        $this->assertSame(
            ['username' => 'admin', 'reason' => 'refused_by_plugin'],
            $this->payloadFor('auth.login_failed')
        );
        $this->assertNotContains('auth.logged_in', $this->observed());
    }

    /**
     * A refused sign-in is counted against the account, so the veto is not an
     * unmetered surface to retry against — and *not* against the site-wide
     * ceiling, because whoever reached it proved the password and a site using a
     * second factor must not spray-block itself during ordinary step-ups.
     */
    public function testARefusedSignInIsCountedAgainstTheAccountButNotTheSite(): void
    {
        $this->refuse('auth.before_login', 'Second factor required.');

        $this->post('login', ['username' => 'admin', 'password' => 'admin']);

        $this->assertFileExists(self::$base . '/data/lockouts.json');
        $lockouts = json_decode((string) file_get_contents(self::$base . '/data/lockouts.json'), true);
        $this->assertSame([1], array_values(array_map(
            static fn (array $entry): int => (int) $entry['attempts'],
            $lockouts
        )));

        $spray = self::$base . '/data/login-spray.json';
        $counted = file_exists($spray)
            ? (json_decode((string) file_get_contents($spray), true)['failures'] ?? [])
            : [];
        $this->assertSame([], $counted, 'a step-up must not push the site towards refusing everyone');
    }

    /**
     * Enough refusals and the account locks anyway — the plugin's veto cannot
     * hold the throttle open, and the lockout is announced from there too.
     */
    public function testRepeatedRefusalsStillReachTheAccountLockout(): void
    {
        $this->refuse('auth.before_login', 'Second factor required.');

        for ($attempt = 1; $attempt <= 3; $attempt++) {
            $this->post('login', ['username' => 'admin', 'password' => 'admin']);
        }

        $this->assertContains('auth.locked_out', $this->observed());
        $this->assertSame(429, $this->post('login', ['username' => 'admin', 'password' => 'admin'])['status']);
    }

    /* --------------------------------------------------- the broken plugin -- */

    /**
     * The test that matters most. A plugin that throws on every hook — including
     * the veto — and everyone can still sign in, sign out, and be locked out on
     * the site's own terms.
     *
     * Fail-closed here would be unrecoverable: the only way to disable a plugin
     * is the admin UI, and the admin UI needs a sign-in.
     */
    public function testAPluginThatThrowsOnEveryHookPreventsNobodyFromSigningIn(): void
    {
        // The observer refuses nothing in this test, so the subject is Breaker,
        // which throws on all five hooks and is dispatched first.
        $login = $this->post('login', ['username' => 'admin', 'password' => 'admin']);
        $this->assertTrue($login['data']['success'], 'the throwing plugin did not stop the sign-in');
        $this->assertTrue($this->auth()->handle('auth/check', 'GET')['data']['authenticated']);

        $this->assertTrue($this->post('logout')['data']['success']);
        $this->assertFalse($this->auth()->handle('auth/check', 'GET')['data']['authenticated']);

        // And the working plugin was still told about every one of them, which is
        // the property isolated dispatch exists to protect.
        $this->assertSame([
            'auth.before_login',
            'auth.logged_in',
            'auth.logged_out',
        ], $this->observed());
    }

    /**
     * A hook nobody declares reaches nothing and costs one memoised lookup — the
     * plugin manager answers from metadata rather than walking the active set.
     */
    public function testAHookNoPluginDeclaresIsNotDispatched(): void
    {
        $manager = $this->app->getPluginManager();

        $this->assertTrue($manager->hasHookListeners('auth.before_login'));
        $this->assertFalse($manager->hasHookListeners('auth.something_nobody_declared'));
        $this->assertSame([], $manager->executeHookIsolated('auth.something_nobody_declared', []));
    }

    private static function removeTree(string $path): void
    {
        if (!is_dir($path)) {
            @unlink($path);

            return;
        }

        foreach (scandir($path) ?: [] as $e) {
            if ($e === '.' || $e === '..') {
                continue;
            }
            self::removeTree($path . '/' . $e);
        }

        @rmdir($path);
    }
}
