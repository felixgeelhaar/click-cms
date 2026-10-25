<?php

declare(strict_types=1);

namespace Click\Cms\Tests\Unit\Http;

use Click\Cms\Application\Authentication\SessionStore;
use Click\Cms\Http\ApiGuard;
use PHPUnit\Framework\TestCase;

/**
 * Pin the anonymous boundary against the concrete management paths that sit
 * beside delivery routes. A new endpoint must not become public just because
 * its name shares a prefix with a delivery endpoint.
 */
final class AuthorizationBoundaryRegressionTest extends TestCase
{
    private string $sessionDir;
    /** @var array<string, array<string, mixed>> */
    private array $accounts = [];
    private ApiGuard $guard;

    protected function setUp(): void
    {
        $this->sessionDir = sys_get_temp_dir() . '/click-cms-authorization-boundary-' . bin2hex(random_bytes(6));
        $_COOKIE = [];
        $this->guard = $this->newGuard();
    }

    protected function tearDown(): void
    {
        $_COOKIE = [];
        $this->removeDirectory($this->sessionDir);
    }

    /**
     * Build a real session whose fingerprint is checked against its account,
     * as it is in the application and MarketplaceGuardTest.
     */
    private function signInAs(string $role): void
    {
        $username = $role . '-user';
        $this->accounts[$username] = [
            'password' => 'test-hash',
            'role' => $role,
            'status' => 'active',
        ];

        $sessions = new SessionStore(
            $this->sessionDir,
            1800,
            fn (string $name): ?array => $this->accounts[$name] ?? null,
        );
        $sessions->start([
            'username' => $username,
            'expiresAt' => time() + 3600,
            'lastActivity' => time(),
            'user' => ['username' => $username, 'role' => $role],
        ]);
        $this->guard = new ApiGuard($sessions);
    }

    private function newGuard(): ApiGuard
    {
        return new ApiGuard(new SessionStore(
            $this->sessionDir,
            1800,
            fn (string $name): ?array => $this->accounts[$name] ?? null,
        ));
    }

    private function removeDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }

        foreach (scandir($directory) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $directory . '/' . $entry;
            is_dir($path) ? $this->removeDirectory($path) : @unlink($path);
        }

        @rmdir($directory);
    }

    public function testDraftAdjacentManagementReadsStayBehindAuthentication(): void
    {
        $privateReads = [
            'pages/home/versions',
            'pages/home/versions/version-id',
            'pages/home/versions/version-id/restore',
            'pages/home/schedule',
            'schedule',
            'collections/blog/entries',
            'collections/blog/entries/draft',
            'collections/blog/entries/draft/versions',
            'collections/blog/entries/draft/versions/version-id',
            'collections/blog/entries/draft/backreferences',
            'collections/blog',
        ];

        foreach ($privateReads as $path) {
            self::assertFalse($this->guard->isPublic($path, 'GET'), $path . ' must not be public');
            self::assertSame(401, $this->guard->enforceAuth($path, 'GET')['status'], $path);
        }
    }

    public function testAccountAndPluginManagementPathsStayBehindAuthentication(): void
    {
        foreach ([
            ['users', 'GET'],
            ['users/alice', 'GET'],
            ['plugins', 'GET'],
            ['plugins/example', 'GET'],
            ['plugins/example/activate', 'POST'],
            ['marketplace', 'GET'],
        ] as [$path, $method]) {
            self::assertFalse($this->guard->isPublic($path, $method), $method . ' ' . $path);
            self::assertSame(401, $this->guard->enforceAuth($path, $method)['status'], $method . ' ' . $path);
        }
    }

    public function testViewerAndAuthorCannotManageAccountsOrPlugins(): void
    {
        foreach (['viewer', 'author'] as $role) {
            $this->signInAs($role);

            foreach ([
                ['users', 'GET'],
                ['users/alice', 'GET'],
                ['plugins/example/activate', 'POST'],
            ] as [$path, $method]) {
                self::assertSame(403, $this->guard->enforceAuth($path, $method)['status'], $role . ' ' . $method . ' ' . $path);
            }

            // MarketplaceGuardTest drives the complete request pipeline and
            // verifies the controller's ManagePlugins capability gate. ApiGuard
            // only authenticates this route; role authorization belongs to the
            // controller, so a signed-in viewer passes this coarse gate.
            self::assertNull($this->guard->enforceAuth('marketplace', 'GET'));
        }
    }

    public function testAdminMayManageAccountsAndPlugins(): void
    {
        $this->signInAs('admin');

        foreach ([
            ['users', 'GET'],
            ['users/alice', 'GET'],
            ['plugins/example/activate', 'POST'],
            ['marketplace', 'GET'],
        ] as [$path, $method]) {
            self::assertNull($this->guard->enforceAuth($path, $method), 'admin ' . $method . ' ' . $path);
        }
    }

    public function testPublicPagePrefixDoesNotMakeHistoryOrSchedulesPublicByMethodVariation(): void
    {
        foreach (['GET', 'HEAD', 'OPTIONS', 'POST', 'PUT', 'DELETE'] as $method) {
            self::assertFalse($this->guard->isPublic('pages/home/versions', $method), $method . ' history');
            self::assertFalse($this->guard->isPublic('pages/home/schedule', $method), $method . ' schedule');
            self::assertFalse($this->guard->isPublic('schedule', $method), $method . ' schedules');
        }
    }

    public function testOnlyDeliveryReadsArePublicAmongAdjacentContentRoutes(): void
    {
        foreach ([
            ['pages', 'GET'],
            ['pages/home', 'GET'],
            ['collections/blog/published', 'GET'],
            ['collections/blog/published/hello', 'GET'],
        ] as [$path, $method]) {
            self::assertTrue($this->guard->isPublic($path, $method), $method . ' ' . $path);
        }

        foreach ([
            ['pages', 'POST'],
            ['pages/home', 'PUT'],
            ['collections/blog/published', 'POST'],
            ['collections/blog/entries/draft', 'GET'],
        ] as [$path, $method]) {
            self::assertFalse($this->guard->isPublic($path, $method), $method . ' ' . $path);
        }
    }
}
